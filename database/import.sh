#!/usr/bin/env bash
# zabbix_import.sh — Zabbix Cloud configuration import script
# For Cloud ops use only. Not distributed to customers.
#
# Usage:
#   ./zabbix_import.sh --bundle bundle.gz \
#                      --db-host HOST --db-name DB \
#                      --db-user USER [--db-pass PASS] [--db-port 3306] \
#                      --cloud-version 07000014
#
# Dependencies: mysql client, tar, gzip, sha256sum, awk
#
# What this script does:
#   Assumption: target Cloud DB schema exists and configuration tables are pre-cleaned.
#   1. Extracts and verifies bundle integrity (checksums)
#   2. Validates source dbversion against Cloud version
#   3. Validates table list and per-table row counts against manifest
#   4. Loads each table in FK-safe order with deferred FK checking
#   5. Handles NULL markers and base64-encoded BLOB columns
#   6. Rebuilds the ids table from imported data
#   7. Sets dbversion to the Cloud tenant version
#   8. Stubs for Cloud policy hardening and defaults (must be implemented separately)

set -euo pipefail

# ── Constants ──────────────────────────────────────────────────────────────────

readonly SCRIPT_VERSION="1.0"
readonly NULL_MARKER='\\N'     # the two-character sequence \N in MySQL SQL strings
readonly REQUIRED_ZABBIX_MAJOR="7"                               # Zabbix 7.0.x

# Returns the major version digit from a dbversion.mandatory value.
# Handles both 7-digit (7000000) and 8-digit (07000000) formats, and
# forces base-10 interpretation so a leading zero is never read as octal
# (bash treats 0-prefixed numeric literals as octal by default, and
# 07000000 would otherwise throw "value too great for base" / invalid digit).
zabbix_major_version() {
    local v="$1"
    v="${v//[^0-9]/}"     # strip anything non-digit defensively
    [[ -n "$v" ]] || die "zabbix_major_version: empty or non-numeric version value: '$1'"
    v="${v#0}"            # strip one leading zero (avoids octal interpretation below)
    [[ -n "$v" ]] || v="0"   # value was all zeros (e.g. "0") — keep a valid digit
    echo $(( 10#${v} / 1000000 ))
}

# ── CLI defaults ───────────────────────────────────────────────────────────────

BUNDLE=""
DB_HOST="127.0.0.1"; DB_PORT="3306"; DB_NAME=""
DB_USER=""; DB_PASS=""; DB_PASS_SET=0
CLOUD_VERSION=""
WORK_DIR=""

# ── Helpers ────────────────────────────────────────────────────────────────────

log()  { printf '[%s] %s\n'         "$(date -u +%H:%M:%S)" "$*" >&2; }
warn() { printf '[%s] WARNING: %s\n' "$(date -u +%H:%M:%S)" "$*" >&2; }
die()  { printf 'ERROR: %s\n' "$*" >&2; exit 1; }

check_deps() {
    for cmd in mysql tar gzip sha256sum awk; do
        command -v "$cmd" >/dev/null 2>&1 || die "Required command not found: ${cmd}"
    done
}

cleanup() {
    [[ -n "${WORK_DIR:-}" && -d "${WORK_DIR}" ]] && rm -rf "${WORK_DIR}"
}

# ── Database executor ──────────────────────────────────────────────────────────
#
# --connect-timeout=10: fail fast on network/firewall issues instead of
# hanging indefinitely with no output (the default TCP connect has no
# client-side timeout in some environments).

mysql_query() {
    MYSQL_PWD="${DB_PASS}" mysql \
        --batch --raw --skip-column-names \
        --default-character-set=utf8mb4 \
        --local-infile=1 \
        --connect-timeout=10 \
        -h "${DB_HOST}" -P "${DB_PORT}" -u "${DB_USER}" "${DB_NAME}" \
        -e "$1"
}

mysql_exec_file() {
    {
        printf 'SET FOREIGN_KEY_CHECKS = 0;\n'
        printf 'SET UNIQUE_CHECKS = 0;\n'
        cat "$1"
        printf 'SET UNIQUE_CHECKS = 1;\n'
        printf 'SET FOREIGN_KEY_CHECKS = 1;\n'
    } | MYSQL_PWD="${DB_PASS}" mysql \
        --default-character-set=utf8mb4 \
        --local-infile=1 \
        --connect-timeout=10 \
        -h "${DB_HOST}" -P "${DB_PORT}" -u "${DB_USER}" "${DB_NAME}"
}

# Lightweight preflight check — verifies the DB is reachable and credentials
# work before any real import work begins, so connectivity problems surface
# immediately with a clear message instead of a silent hang deep in the
# import loop.
check_db_connectivity() {
    log "Checking Cloud DB connectivity (${DB_HOST}:${DB_PORT})..."
    if ! mysql_query "SELECT 1" >/dev/null 2>&1; then
        die "Could not connect to Cloud DB at ${DB_HOST}:${DB_PORT} as ${DB_USER}. Check host/port/credentials/network access (security group, firewall)."
    fi
    log "Cloud DB connection OK"

    # LOAD DATA LOCAL INFILE requires local_infile=ON on the server side
    # (in addition to --local-infile=1 on the client, which mysql_query
    # already sets). If the server has it disabled — a common secure
    # default on self-managed MySQL — LOAD DATA can fail or silently load
    # zero rows depending on client/server version combination.
    local local_infile_status
    local_infile_status=$(mysql_query "SHOW VARIABLES LIKE 'local_infile'" | awk '{print $2}')
    if [[ "${local_infile_status}" != "ON" ]]; then
        die "Server variable local_infile is '${local_infile_status:-unknown}', not ON. " \
            "LOAD DATA LOCAL INFILE will fail or silently load 0 rows. " \
            "Enable it with: SET GLOBAL local_infile = 1;  (requires SUPER/SYSTEM_VARIABLES_ADMIN privilege)"
    fi
    log "local_infile is enabled on target server"
}

# Preflight schema compatibility check — compares each CSV's header columns
# against the target table's actual columns *before* any LOAD DATA runs.
# Catches a stale/mismatched schema.sql (missing columns added in a later
# 7.0.x patch, or a table missing entirely) with one clear report instead of
# an opaque MySQL error partway through the import.
validate_target_schema() {
    log "Validating target schema against bundle (${#MANIFEST_FILES[@]} tables)..."

    local mismatch_found=0
    local i
    for i in "${!MANIFEST_FILES[@]}"; do
        local rel="${MANIFEST_FILES[$i]}"
        local table="${MANIFEST_TABLES[$i]}"
        local csv_file="${WORK_DIR}/${rel}"

        # Does the table exist at all in the target?
        local exists
        exists=$(mysql_query "SELECT COUNT(*) FROM information_schema.tables \
            WHERE table_schema = DATABASE() AND table_name = '${table}'")
        if [[ "${exists}" -eq 0 ]]; then
            warn "Table '${table}' does not exist in target DB (schema.sql may be outdated or from a different Zabbix version)"
            mismatch_found=1
            continue
        fi

        # Read CSV header columns
        local -a csv_cols=()
        read_csv_header "$csv_file" csv_cols

        # Read target table columns
        local -a db_cols=()
        while IFS= read -r col; do
            [[ -n "$col" ]] && db_cols+=("$col")
        done < <(mysql_query "SELECT column_name FROM information_schema.columns \
            WHERE table_schema = DATABASE() AND table_name = '${table}' \
            ORDER BY ordinal_position")

        # Report any CSV column missing from the target table
        local col found missing=()
        for col in "${csv_cols[@]}"; do
            found=0
            for dbcol in "${db_cols[@]}"; do
                [[ "$col" == "$dbcol" ]] && { found=1; break; }
            done
            [[ $found -eq 0 ]] && missing+=("$col")
        done

        if [[ ${#missing[@]} -gt 0 ]]; then
            warn "Table '${table}' is missing column(s) in target schema: ${missing[*]}"
            mismatch_found=1
        fi
    done

    if [[ $mismatch_found -eq 1 ]]; then
        die "Target schema does not match the bundle's expected structure (see warnings above). " \
            "Recreate the empty DB using schema.sql from the exact same Zabbix package version as the source instance, then retry."
    fi

    log "Target schema OK — all tables and columns present"
}

# ── Bundle extraction and verification ────────────────────────────────────────

extract_bundle() {
    log "Extracting bundle: ${BUNDLE}"
    tar xzf "${BUNDLE}" -C "${WORK_DIR}"

    local required=(manifest.json export_report.json checksums.sha256)
    for f in "${required[@]}"; do
        [[ -f "${WORK_DIR}/${f}" ]] || die "Bundle is missing required file: ${f}"
    done
    [[ -d "${WORK_DIR}/tables" ]]   || die "Bundle is missing tables/ directory"
}

verify_checksums() {
    log "Verifying checksums..."
    (cd "${WORK_DIR}" && sha256sum -c checksums.sha256 --quiet) \
        || die "Checksum verification failed. Bundle may be corrupted or tampered with."
    log "Checksums OK"
}

# ── Manifest parsing ───────────────────────────────────────────────────────────
# Parses manifest.json using awk — no jq dependency.
# Sets globals: MANIFEST_MANDATORY, MANIFEST_OPTIONAL, MANIFEST_FILES[], MANIFEST_ROWS[]

declare -a MANIFEST_FILES=()    # ordered list of relative CSV file paths
declare -a MANIFEST_TABLES=()   # table name per entry (same index as MANIFEST_FILES)
declare -a MANIFEST_ROWS=()     # expected row count per entry
MANIFEST_MANDATORY=""
MANIFEST_OPTIONAL=""

parse_manifest() {
    local manifest="${WORK_DIR}/manifest.json"

    [[ -f "$manifest" ]] || die "manifest.json not found in extracted bundle"

    # Extract the digit run following each key, regardless of surrounding
    # whitespace/comma layout. `|| true` is required: under `set -e`, a grep
    # that finds no match returns status 1, which would otherwise abort the
    # script right here — silently, before the die() check below ever runs.
    MANIFEST_MANDATORY=$(grep -oE '"dbversion_mandatory"[[:space:]]*:[[:space:]]*[0-9]+' "$manifest" \
        | grep -oE '[0-9]+$' || true)
    MANIFEST_OPTIONAL=$(grep -oE '"dbversion_optional"[[:space:]]*:[[:space:]]*[0-9]+' "$manifest" \
        | grep -oE '[0-9]+$' || true)

    if [[ -z "$MANIFEST_MANDATORY" || -z "$MANIFEST_OPTIONAL" ]]; then
        warn "Could not parse dbversion fields from manifest.json. Raw content:"
        cat "$manifest" >&2
        die "manifest.json: could not parse dbversion_mandatory / dbversion_optional"
    fi

    # Parse the tables array — each entry is one line:
    # {"seq": N, "file": "tables/NNN_x.csv", "table": "x", "rows": N}
    while IFS= read -r line; do
        local file table rows
        file=$(echo  "$line" | awk -F'"' '/"file"/{for(i=1;i<=NF;i++) if($i=="file") print $(i+2)}')
        table=$(echo "$line" | awk -F'"' '/"table"/{for(i=1;i<=NF;i++) if($i=="table") print $(i+2)}')
        rows=$(echo  "$line" | awk -F'[: ,}]+' '/"rows"/{for(i=1;i<=NF;i++) if($i~/rows/) print $(i+1)}' | tr -d '"')

        [[ -n "$file" && -n "$table" && -n "$rows" ]] || continue
        MANIFEST_FILES+=("$file")
        MANIFEST_TABLES+=("$table")
        MANIFEST_ROWS+=("$rows")
    done < <(grep '"file"' "$manifest")

    [[ ${#MANIFEST_FILES[@]} -gt 0 ]] || die "manifest.json: no table entries found"

    log "Manifest: source dbversion mandatory=${MANIFEST_MANDATORY} optional=${MANIFEST_OPTIONAL}, tables=${#MANIFEST_FILES[@]}"
}

# ── Version validation ─────────────────────────────────────────────────────────

validate_version() {
    # Both source and cloud must be Zabbix 7.0.x (compared by major version,
    # not string prefix — dbversion.mandatory may be 7 or 8 digits).
    local src_major cloud_major
    src_major=$(zabbix_major_version "${MANIFEST_MANDATORY}")
    cloud_major=$(zabbix_major_version "${CLOUD_VERSION}")

    [[ "${src_major}"   == "${REQUIRED_ZABBIX_MAJOR}" ]] \
        || die "Source dbversion.mandatory=${MANIFEST_MANDATORY} is not Zabbix 7.0.x. Import aborted."
    [[ "${cloud_major}" == "${REQUIRED_ZABBIX_MAJOR}" ]] \
        || die "--cloud-version=${CLOUD_VERSION} is not Zabbix 7.0.x. Import aborted."

    # Source mandatory must not exceed Cloud mandatory.
    # 10# forces base-10 so a leading zero is never misread as octal.
    # Guard against a value reducing to "" after stripping (e.g. literal "0").
    local src_num cloud_num src_digits cloud_digits
    src_digits="${MANIFEST_MANDATORY#0}";  [[ -n "$src_digits"   ]] || src_digits="0"
    cloud_digits="${CLOUD_VERSION#0}";     [[ -n "$cloud_digits" ]] || cloud_digits="0"
    src_num=$((10#${src_digits}))
    cloud_num=$((10#${cloud_digits}))
    if (( src_num > cloud_num )); then
        die "Source version (${MANIFEST_MANDATORY}) is newer than Cloud version (${CLOUD_VERSION}). Import aborted."
    fi

    log "Version check OK: source=${MANIFEST_MANDATORY} ≤ cloud=${CLOUD_VERSION}"
}

# ── Pre-import validation ──────────────────────────────────────────────────────

validate_bundle_files() {
    log "Validating bundle file list..."
    local i
    for i in "${!MANIFEST_FILES[@]}"; do
        local rel="${MANIFEST_FILES[$i]}"
        [[ -f "${WORK_DIR}/${rel}" ]] \
            || die "Bundle references ${rel} but the file is missing"
    done
    log "All ${#MANIFEST_FILES[@]} CSV files present"
}

# Count logical CSV records in a file.
#
# A naive `wc -l` / `awk 'END{print NR}'` counts physical lines, which
# overcounts when fields contain embedded newlines. This parser tracks
# quote state across lines and treats only non-escaped `"` as quote
# delimiters (backslash escaping).
count_csv_records() {
    local file="$1"
    awk '
        {
            line = $0
            bs = 0

            for (i = 1; i <= length(line); i++) {
                c = substr(line, i, 1)

                if (c == "\\") {
                    bs++
                    continue
                }

                if (c == "\"") {
                    if (bs % 2 == 0) {
                        in_quotes = !in_quotes
                    }
                }

                bs = 0
            }

            if (!in_quotes) {
                records++
            }
        }
        END { print records + 0 }
    ' "$file"
}

# Validate that each CSV has the expected number of data rows (header excluded).
# Aborts if any table's row count does not match the manifest.
validate_row_counts() {
    log "Validating row counts..."
    local i
    for i in "${!MANIFEST_FILES[@]}"; do
        local rel="${MANIFEST_FILES[$i]}"
        local table="${MANIFEST_TABLES[$i]}"
        local expected="${MANIFEST_ROWS[$i]}"
        # Logical CSV records minus 1 (header) = data rows
        local total_records actual
        total_records=$(count_csv_records "${WORK_DIR}/${rel}")
        actual=$(( total_records - 1 ))
        if (( actual != expected )); then
            die "Row count mismatch for ${table}: manifest says ${expected}, file has ${actual}"
        fi
    done
    log "Row counts OK"
}

# ── CSV header reader ──────────────────────────────────────────────────────────
# Reads the header line and returns an array of column names (unquoted).

read_csv_header() {
    local csv_file="$1"
    local -n _cols="$2"   # output nameref array

    local header
    header=$(head -1 "$csv_file")

    # Split on comma, strip surrounding double-quotes from each field name.
    # Column names are simple identifiers; no embedded commas or quotes expected.
    IFS=',' read -ra raw <<< "$header"
    for col in "${raw[@]}"; do
        col="${col#\"}"   # strip leading "
        col="${col%\"}"   # strip trailing "
        _cols+=("$col")
    done
}

# ── BLOB column detection ──────────────────────────────────────────────────────
# Returns a space-separated list of BLOB column names for a given table.

get_blob_columns() {
    local table="$1"
    mysql_query "SELECT column_name FROM information_schema.columns \
        WHERE table_schema = DATABASE() AND table_name = '${table}' \
        AND data_type IN ('blob','longblob','mediumblob','tinyblob') \
        ORDER BY ordinal_position" | tr '\n' ' '
}

# ── LOAD DATA SQL builder ──────────────────────────────────────────────────────
# Generates a LOAD DATA LOCAL INFILE statement that:
#   - Uses @v1..@vN to capture all fields as strings first
#   - Maps each @vN to its column via SET clause
#   - Converts '\N' (string) to SQL NULL via CASE (covers both auto-NULL and string)
#   - Applies FROM_BASE64() for BLOB columns
#   - Handles embedded double-quotes via ESCAPED BY '"' (RFC 4180 doubling)
#
# Using @variables + CASE avoids relying on MySQL's ESCAPED BY interaction
# with \N NULL detection, which is ambiguous when ESCAPED BY is not '\'.

build_load_sql() {
    local table="$1"
    local csv_file="$2"

    local -a cols=()
    read_csv_header "$csv_file" cols
    [[ ${#cols[@]} -gt 0 ]] || die "Could not read header from ${csv_file}"

    # Build BLOB column lookup
    local blob_list
    blob_list=$(get_blob_columns "$table")
    declare -A blob_set
    for bc in $blob_list; do blob_set["$bc"]=1; done

    # Generate @v1..@vN variable list
    local var_list="" set_clause="" i
    for i in "${!cols[@]}"; do
        local vn="@v$((i+1))"
        local col="${cols[$i]}"

        [[ -n "$var_list" ]] && var_list+=", "
        var_list+="$vn"

        [[ -n "$set_clause" ]] && set_clause+=$',\n  '

        if [[ -n "${blob_set[$col]+x}" ]]; then
            # BLOB: '\N' or NULL → SQL NULL; anything else → decode base64
            set_clause+="\`${col}\` = CASE WHEN ${vn} IS NULL OR ${vn} = '\\\\N' THEN NULL ELSE FROM_BASE64(${vn}) END"
        else
            # All other types: '\N' or NULL → SQL NULL; anything else → value as-is
            set_clause+="\`${col}\` = CASE WHEN ${vn} IS NULL OR ${vn} = '\\\\N' THEN NULL ELSE ${vn} END"
        fi
    done

    # Emit the full SQL for this table: delete existing rows first, then load.
    printf "DELETE FROM \`%s\`;\n"       "$table"
    printf "LOAD DATA LOCAL INFILE '%s'\n" "$csv_file"
    printf "INTO TABLE \`%s\`\n"            "$table"
    printf "CHARACTER SET utf8mb4\n"
    printf "FIELDS\n"
    printf "  TERMINATED BY ','\n"
    printf "  OPTIONALLY ENCLOSED BY '\"'\n"
    printf '%s\n' "  ESCAPED BY '\\\\'"
    printf '%s\n' "LINES TERMINATED BY '\\n'"
    printf 'IGNORE 1 LINES\n'
    printf '(%s)\n' "$var_list"
    printf 'SET\n  %s;\n' "$set_clause"

    # mysql running in batch mode (stdin input, as used by mysql_exec_file)
    # suppresses the interactive "Records: N  Deleted: 0  Skipped: N
    # Warnings: N" status line, so a LOAD DATA that silently skips every row
    # (e.g. NOT NULL violation, column-count mismatch, truncation) produces
    # no visible output and exits 0. An explicit SHOW WARNINGS forces an
    # actual result set, which mysql always prints regardless of batch mode.
    printf "SHOW WARNINGS;\n"
}

# ── Table import ───────────────────────────────────────────────────────────────

load_table() {
    local table="$1"
    local csv_file="$2"
    local expected_rows="$3"

    # Verify table exists in the Cloud DB schema
    local exists
    exists=$(mysql_query "SELECT COUNT(*) FROM information_schema.tables \
        WHERE table_schema = DATABASE() AND table_name = '${table}'")
    if [[ "${exists}" -eq 0 ]]; then
        warn "Table '${table}' does not exist in Cloud DB — skipping"
        return 0
    fi

    # Build and execute LOAD DATA
    local sql_file="${WORK_DIR}/load_${table}.sql"
    build_load_sql "$table" "$csv_file" > "$sql_file"

    # Capture combined output explicitly — `if ... ; then` keeps this
    # set -e-safe. Any SHOW WARNINGS result rows (see build_load_sql) will
    # appear here even though mysql's batch mode suppresses status lines.
    local load_output load_rc=0
    if load_output=$(mysql_exec_file "$sql_file" 2>&1); then
        load_rc=0
    else
        load_rc=$?
    fi

    if [[ -n "$load_output" ]]; then
        log "  mysql output for ${table}:"
        printf '%s\n' "$load_output" | sed 's/^/    /' >&2
    fi

    if [[ $load_rc -ne 0 ]]; then
        local debug_copy="/tmp/zabbix_import_debug_${table}.sql"
        cp "$sql_file" "$debug_copy" 2>/dev/null || true
        die "LOAD DATA failed for table '${table}'. Generated SQL saved to: ${debug_copy}"
    fi

    # Post-load row count check
    local actual
    actual=$(mysql_query "SELECT COUNT(*) FROM \`${table}\`")
    if (( actual != expected_rows )); then
        local debug_copy="/tmp/zabbix_import_debug_${table}.sql"
        cp "$sql_file" "$debug_copy" 2>/dev/null || true
        die "Row count mismatch after loading ${table}: expected ${expected_rows}, got ${actual}. Generated SQL saved to: ${debug_copy}"
    fi

    # Preserve the generated per-table SQL for debugging; copy to /tmp
    cp "$sql_file" "/tmp/zabbix_generated_${table}.sql" 2>/dev/null || true
    #rm -f "$sql_file"
}

# ── Main import loop ───────────────────────────────────────────────────────────

import_tables() {
    log "Starting table import (FK/UNIQUE checks disabled per LOAD DATA session)..."

    local i
    for i in "${!MANIFEST_FILES[@]}"; do
        local rel="${MANIFEST_FILES[$i]}"
        local table="${MANIFEST_TABLES[$i]}"
        local rows="${MANIFEST_ROWS[$i]}"
        local csv_file="${WORK_DIR}/${rel}"

        log "  [$(printf '%03d' $((i+1)))] loading ${table} (${rows} rows)"
        load_table "$table" "$csv_file" "$rows"
    done

    log "Table import completed; FK/UNIQUE checks were re-enabled after each load"
}

# ── Post-import: rebuild ids table ────────────────────────────────────────────
# Zabbix manages its own ID sequences via the ids table (not MySQL AUTO_INCREMENT).
# After import we must populate ids with the current MAX for each PK column,
# so Zabbix Server allocates new IDs without colliding with imported data.

rebuild_ids() {
    log "Rebuilding ids table..."

    local sql_file="${WORK_DIR}/rebuild_ids.sql"
    : > "$sql_file"

    # For each imported table, find its primary key column (single-column INT/BIGINT PKs only).
    local i
    for i in "${!MANIFEST_TABLES[@]}"; do
        local table="${MANIFEST_TABLES[$i]}"

        # Get single-column PK of integer type
        local pk_col
        pk_col=$(mysql_query "SELECT kcu.column_name \
            FROM information_schema.key_column_usage kcu \
            JOIN information_schema.columns c \
              ON c.table_schema = kcu.table_schema \
             AND c.table_name   = kcu.table_name \
             AND c.column_name  = kcu.column_name \
            WHERE kcu.table_schema   = DATABASE() \
              AND kcu.table_name     = '${table}' \
              AND kcu.constraint_name = 'PRIMARY' \
              AND c.data_type IN ('int','bigint','smallint','mediumint') \
            GROUP BY kcu.table_name \
            HAVING COUNT(*) = 1" | head -1 | tr -d '[:space:]')

        [[ -n "$pk_col" ]] || continue

        printf "INSERT INTO ids (table_name, field_name, nextid)\n" >> "$sql_file"
        printf "SELECT '%s', '%s', COALESCE(MAX(\`%s\`), 0)\n" \
            "$table" "$pk_col" "$pk_col" >> "$sql_file"
        printf "FROM \`%s\`\n" "$table" >> "$sql_file"
        printf "ON DUPLICATE KEY UPDATE nextid = VALUES(nextid);\n\n" >> "$sql_file"
    done

    mysql_exec_file "$sql_file"
    rm -f "$sql_file"
    log "ids table rebuilt"
}

# ── Post-import: set dbversion ─────────────────────────────────────────────────

set_dbversion() {
    log "Setting dbversion to Cloud version: ${CLOUD_VERSION}"
    mysql_query "INSERT INTO dbversion (dbversionid, mandatory, optional) \
        VALUES (1, ${CLOUD_VERSION}, ${CLOUD_VERSION}) \
        ON DUPLICATE KEY UPDATE mandatory = VALUES(mandatory), optional = VALUES(optional)"
}

# ── Post-import: Cloud policy hardening ───────────────────────────────────────
# IMPORTANT: this function MUST be implemented before production use.
# It should cover at minimum the following categories (see FR addendum):
#
#   settings table:
#     RESET (Cloud default):  authentication.saml.* (all SAML keys and certificates)
#                             authentication.ldap.* (bind DN, password, base DN)
#                             smtp credentials and relay settings
#                             session signing/CSRF secret keys
#                             frontend URL (url) — must point to *.zabbix.cloud
#     KEEP (import as-is):    working hours, severity colors, UI theme,
#                             refresh intervals, default timezone
#
#   userdirectory table:
#     Set all LDAP/SAML/OIDC provider entries to disabled;
#     customer must re-configure credentials for Cloud network.
#
#   media_type table:
#     Disable or sanitize script-type media types (externalscript bodies);
#     Zabbix Cloud disables externalScripts at the server level.
#
#   scripts table:
#     Disable or remove global scripts that rely on on-prem-only paths or agents.
#
#   proxy / proxy_group tables:
#     Mark all proxies as requiring reconfiguration — they point at on-prem hosts;
#     customer must repoint proxy to Cloud node address after migration.
#     Display a post-import warning in the UI (see FR §1.a.ii.3).
#
#   token table:
#     API tokens are imported but customer must regenerate tokens for integrations
#     since on-prem scripts will need updating to point at the Cloud API endpoint.
#
#   mfa / mfa_totp_secret tables:
#     Verify Cloud MFA policy compatibility; reset if policy conflicts.

apply_cloud_defaults() {
    log "Applying Cloud policy hardening..."

    # ── Reset SAML/LDAP/SMTP settings ─────────────────────────────────────────
    # Replace with authoritative Cloud-default values before enabling.
    local saml_keys=(
        "authentication.saml.idp.entityid"
        "authentication.saml.idp.sign.requests"
        "authentication.saml.sp.entityid"
        "authentication.saml.sp.privatekey"
        "authentication.saml.sp.certificate"
        "authentication.saml.username.attribute"
        "authentication.saml.slo.url"
        "authentication.saml.acs.url"
        "authentication.saml.active"
    )
    local ldap_keys=(
        "authentication.ldap.host"
        "authentication.ldap.port"
        "authentication.ldap.base.dn"
        "authentication.ldap.bind.dn"
        "authentication.ldap.bind.password"
        "authentication.ldap.active"
    )
    local smtp_keys=(
        "email.smtp.server"
        "email.smtp.port"
        "email.smtp.username"
        "email.smtp.password"
    )

    local key
    for key in "${saml_keys[@]}" "${ldap_keys[@]}" "${smtp_keys[@]}"; do
        mysql_query "DELETE FROM settings WHERE name = '${key}'" 2>/dev/null || true
    done

    # ── Disable user directories (LDAP/SAML/OIDC providers) ──────────────────
    mysql_query "UPDATE userdirectory SET userdirectoryid = userdirectoryid WHERE 1=0" 2>/dev/null || true
    # TODO: implement actual disabling once userdirectory schema is confirmed

    # ── Disable script-type media types ───────────────────────────────────────
    # type = 0 is Email, type values for script types depend on Zabbix version
    # TODO: implement per confirmed Zabbix 7.0 media_type.type values

    # ── Frontend URL ──────────────────────────────────────────────────────────
    # TODO: set settings.url to the Cloud tenant's *.zabbix.cloud URL

    warn "apply_cloud_defaults: some hardening steps are TODO — review before production use"
    log "Cloud policy hardening applied (partial)"
}

# ── Argument parsing ───────────────────────────────────────────────────────────

usage() {
    cat >&2 << EOF
Usage: $0 --bundle FILE --db-host HOST --db-name DB --db-user USER
          --cloud-version VERSION [--db-pass PASS] [--db-port PORT]

Options:
  --bundle         Path to bundle.gz produced by zabbix_export.sh  (required)
  --db-host        Cloud DB host                                    (required)
  --db-name        Cloud DB name                                    (required)
  --db-user        Cloud DB user                                    (required)
  --db-pass        Cloud DB password (prompted if omitted)
  --db-port        Cloud DB port                                    (default: 3306)
  --cloud-version  Cloud dbversion.mandatory value as a plain integer,
                   e.g. 7000000 — NOT the dotted UI version (7.0.6).
                   Get it with:
                     mysql -h <cloud-host> -u <cloud-user> -p <cloud-db> \
                       -e "SELECT mandatory FROM dbversion"
                   (required)
EOF
    exit 1
}

parse_args() {
    [[ $# -eq 0 ]] && usage
    while [[ $# -gt 0 ]]; do
        case "$1" in
            --bundle)        BUNDLE="$2";        shift 2 ;;
            --db-host)       DB_HOST="$2";       shift 2 ;;
            --db-port)       DB_PORT="$2";       shift 2 ;;
            --db-name)       DB_NAME="$2";       shift 2 ;;
            --db-user)       DB_USER="$2";       shift 2 ;;
            --db-pass)       DB_PASS="$2"; DB_PASS_SET=1; shift 2 ;;
            --cloud-version) CLOUD_VERSION="$2"; shift 2 ;;
            -h|--help)       usage ;;
            *)               die "Unknown option: $1" ;;
        esac
    done

    [[ -n "$BUNDLE"        ]] || die "--bundle is required"
    [[ -n "$DB_NAME"       ]] || die "--db-name is required"
    [[ -n "$DB_USER"       ]] || die "--db-user is required"
    [[ -n "$CLOUD_VERSION" ]] || die "--cloud-version is required"
    [[ -f "$BUNDLE"        ]] || die "Bundle file not found: ${BUNDLE}"

    # --cloud-version must be the raw dbversion.mandatory integer (e.g. 7000000),
    # not the human-readable dotted version shown in the UI/changelog (e.g. 7.0.6).
    # Catch the common mistake early with an actionable message.
    if [[ "$CLOUD_VERSION" == *.* ]]; then
        die "--cloud-version=${CLOUD_VERSION} looks like a dotted version (e.g. 7.0.6), " \
            "but a raw dbversion.mandatory integer is required (e.g. 7000000). " \
            "Get it with: mysql -h <cloud-host> -u <cloud-user> -p <cloud-db> -e \"SELECT mandatory FROM dbversion\""
    fi
    if ! [[ "$CLOUD_VERSION" =~ ^[0-9]+$ ]]; then
        die "--cloud-version=${CLOUD_VERSION} must be a plain integer (dbversion.mandatory value, e.g. 7000000)"
    fi

    if [[ $DB_PASS_SET -eq 0 ]]; then
        read -rsp "DB password for ${DB_USER}@${DB_HOST}: " DB_PASS
        printf '\n'
    fi
}

# ── Main ───────────────────────────────────────────────────────────────────────

main() {
    parse_args "$@"
    check_deps

    WORK_DIR=$(mktemp -d)
    trap cleanup EXIT

    log "Zabbix import script v${SCRIPT_VERSION}"
    log "Target: mysql://${DB_HOST}:${DB_PORT}/${DB_NAME}"
    log "Bundle: ${BUNDLE}"

    # ── Step 1: extract and verify ────────────────────────────────────────────
    extract_bundle
    verify_checksums
    parse_manifest

    # ── Step 2: validate ──────────────────────────────────────────────────────
    validate_version
    validate_bundle_files
    validate_row_counts
    check_db_connectivity
    validate_target_schema

    # ── Step 3: import ────────────────────────────────────────────────────────
    import_tables

    # ── Step 4: post-import ───────────────────────────────────────────────────
    rebuild_ids
    set_dbversion
    apply_cloud_defaults

    log "──────────────────────────────────────────"
    log "Import complete."
    log "Tables loaded:  ${#MANIFEST_TABLES[@]}"
    log "Cloud version:  ${CLOUD_VERSION}"
    log "NEXT: notify Cloud console, trigger post-import smoke test"
    log "──────────────────────────────────────────"
}

main "$@"
