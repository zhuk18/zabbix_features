#!/usr/bin/env bash
# zabbix_export.sh — Zabbix on-prem → Cloud configuration export
# Produces bundle.gz for import into Zabbix Cloud.
# Scope: Zabbix 7.0.x LTS, configuration tables only.
#
# Usage:
#   ./zabbix_export.sh --db-type mysql --db-host HOST --db-name DB \
#                      --db-user USER [--db-pass PASS] [--db-port 3306] \
#                      [--exclude table1,table2] [--output bundle.gz]
#
#   ./zabbix_export.sh --db-type pgsql --db-host HOST --db-name DB \
#                      --db-user USER [--db-pass PASS] [--db-port 5432] \
#                      [--exclude table1,table2] [--output bundle.gz]
#
# Dependencies:
#   MySQL:      mysql client    (apt: mysql-client / yum: mysql)
#   PostgreSQL: psql client     (apt: postgresql-client / yum: postgresql)
#   Common:     tar, gzip, sha256sum, awk, date (standard on Linux)

set -euo pipefail

# ── Constants ──────────────────────────────────────────────────────────────────

readonly SCRIPT_VERSION="1.0"
readonly BUNDLE_SIZE_HARD_LIMIT=$((5 * 1024 * 1024 * 1024))   # 5 GB — S3 single-PUT limit
readonly BUNDLE_SIZE_WARN_LIMIT=$((4 * 1024 * 1024 * 1024))   # 4 GB — early warning
readonly REQUIRED_ZABBIX_MAJOR="7"                               # Zabbix 7.0.x

# Returns the major version digit from a dbversion.mandatory value.
# Handles both 7-digit (7000000) and 8-digit (07000000) formats, and
# forces base-10 interpretation so a leading zero is never read as octal
# (bash treats 0-prefixed numeric literals as octal by default, and
# 07000000 would otherwise throw "value too great for base" / invalid digit).
zabbix_major_version() {
    local v="${1#0}"          # strip at most one leading zero
    echo $(( 10#${v} / 1000000 ))
}
readonly NULL_MARKER='\N'                                       # unquoted in CSV output

# ── Appendix A — default excluded tables ──────────────────────────────────────
# ids:       excluded from export; importer rebuilds from scratch
# dbversion: excluded from export; importer sets to Cloud tenant version
declare -a EXCLUDED_TABLES=(
    ids dbversion
    # History
    history history_uint history_str history_log history_text history_bin history_json
    # Trends
    trends trends_uint
    # Events and problems
    events event_symptom event_tag event_recovery event_suppress problem problem_tag
    # Alerts and escalations
    alerts acknowledges escalations
    # Service runtime
    service_alarms service_problem service_problem_tag
    # Runtime data
    item_rtdata host_rtdata
    # Internal queues and logs
    housekeeper trigger_queue lld_macro_export changelog sessions auditlog
    # Tasks
    task task_close_problem task_remote_command task_remote_command_result
    task_data task_result task_acknowledge task_check_now
    # Proxy runtime
    proxy_history proxy_dhistory proxy_rtdata proxy_group_rtdata proxy_autoreg_host
    # Discovery runtime
    dhosts dservices autoreg_host
    # HA
    ha_node
)

# ── FK-safe load order for Zabbix 7.0.x ──────────────────────────────────────
# Tables not listed here are appended alphabetically after all ordered ones.
# Self-referencing FKs (items.master_itemid, trigger_depends, hosts.proxy_hostid)
# cannot be resolved by ordering — the importer MUST use deferred FK checking.
# NOTE: verify against your actual 7.0.x schema before production use.
declare -a TABLE_LOAD_ORDER=(
    role usrgrp users users_groups role_rule tag_filter
    hstgrp
    proxy proxy_group
    hosts hosts_groups hosts_templates host_proxy host_inventory
    interface interface_snmp
    globalmacro hostmacro
    config settings config_autoreg_tls
    valuemap valuemap_mapping
    items item_tag item_preproc item_parameter
    triggers trigger_tag functions trigger_depends
    graphs graphs_items graph_theme
    httptest httptest_field httpstep httpstep_field
    media_type media_type_message media_type_param media
    actions conditions
    operations opconditions
    opmessage opmessage_usr opmessage_grp
    opcommand opcommand_hst opcommand_grp
    opgroup optemplate opinventory
    scripts script_param
    drules dchecks
    regexps expressions
    services service_tag service_rule
    sla sla_schedule sla_excluded_downtime
    dashboard dashboard_user dashboard_usrgrp dashboard_page widget widget_field
    sysmaps sysmaps_elements sysmaps_links sysmaps_link_triggers
    sysmaps_element_trigger sysmaps_element_url sysmap_user sysmap_usrgrp
    images
    report report_user report_usrgrp
    token
    userdirectory userdirectory_attribute userdirectory_media
    userdirectory_idpgroup userdirectory_usrgrp
    mfa mfa_totp_secret
    connector connector_tag
    lld_override lld_override_condition lld_override_operation lld_override_opstatus
    lld_override_optemplate lld_override_opgroup lld_override_optag
    lld_override_opperiod lld_override_opinventory
    maintenance maintenances_hosts maintenances_groups timeperiods maintenances_windows
)

# ── CLI defaults ───────────────────────────────────────────────────────────────

DB_TYPE=""; DB_HOST="127.0.0.1"; DB_PORT=""; DB_NAME=""
DB_USER=""; DB_PASS=""; DB_PASS_SET=0; EXTRA_EXCLUDE=""; OUTPUT="bundle.gz"
WORK_DIR=""

# ── Helpers ────────────────────────────────────────────────────────────────────

log()  { printf '[%s] %s\n' "$(date -u +%H:%M:%S)" "$*" >&2; }
warn() { printf '[%s] WARNING: %s\n' "$(date -u +%H:%M:%S)" "$*" >&2; }
die()  { printf 'ERROR: %s\n' "$*" >&2; exit 1; }

check_deps() {
    local -a required=(tar gzip sha256sum awk date)
    [[ "$DB_TYPE" == "mysql" ]] && required+=(mysql)
    [[ "$DB_TYPE" == "pgsql" ]] && required+=(psql)
    for cmd in "${required[@]}"; do
        command -v "$cmd" >/dev/null 2>&1 || die "Required command not found: ${cmd}"
    done
}

cleanup() {
    [[ -n "${WORK_DIR:-}" && -d "${WORK_DIR}" ]] && rm -rf "${WORK_DIR}"
}

# ── Database executors ─────────────────────────────────────────────────────────

# Run a SQL query via the MySQL client.
# Always-quoted RFC 4180 CSV formatting is done at the SQL level (see export_table_mysql).
mysql_query() {
    MYSQL_PWD="${DB_PASS}" mysql \
        --batch --raw --skip-column-names \
        --default-character-set=utf8mb4 \
        -h "${DB_HOST}" -P "${DB_PORT}" -u "${DB_USER}" "${DB_NAME}" \
        -e "$1"
}

# Run a SQL query via the psql client (tab-separated, no header, no align).
psql_query() {
    PGPASSWORD="${DB_PASS}" psql \
        --no-align --tuples-only --quiet \
        -F $'\t' \
        -h "${DB_HOST}" -p "${DB_PORT}" -U "${DB_USER}" "${DB_NAME}" \
        -c "$1"
}

# ── Version check ──────────────────────────────────────────────────────────────

check_zabbix_version() {
    local mandatory optional
    if [[ "$DB_TYPE" == "mysql" ]]; then
        read -r mandatory optional < <(mysql_query "SELECT mandatory, optional FROM dbversion LIMIT 1" || true)
    else
        read -r mandatory optional < <(psql_query "SELECT mandatory, optional FROM dbversion LIMIT 1" || true)
    fi

    [[ -n "${mandatory:-}" ]] || die "Could not read dbversion table. Is this a Zabbix database?"

    local major
    major=$(zabbix_major_version "${mandatory}")
    if [[ "${major}" != "${REQUIRED_ZABBIX_MAJOR}" ]]; then
        warn "dbversion.mandatory=${mandatory} does not match Zabbix 7.0.x (major version ${major}). Import may fail."
    else
        log "DB version: mandatory=${mandatory}, optional=${optional}"
    fi

    echo "${mandatory} ${optional}"
}

# ── Table discovery ────────────────────────────────────────────────────────────

get_all_tables() {
    if [[ "$DB_TYPE" == "mysql" ]]; then
        mysql_query "SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE' ORDER BY table_name"
    else
        psql_query "SELECT tablename FROM pg_tables WHERE schemaname = 'public' ORDER BY tablename"
    fi
}

is_excluded() {
    local table="$1"
    local -n _excluded_set="$2"
    [[ -n "${_excluded_set[${table}]+x}" ]]
}

# Sort tables: FK-ordered tables first, then remaining alphabetically.
sort_tables() {
    local -n _in="$1"    # input array (nameref)
    local -n _out="$2"   # output array (nameref)

    declare -A in_set seen
    for t in "${_in[@]}"; do in_set["$t"]=1; done

    for t in "${TABLE_LOAD_ORDER[@]}"; do
        if [[ -n "${in_set[${t}]+x}" ]]; then
            _out+=("$t")
            seen["$t"]=1
        fi
    done

    local -a remaining=()
    for t in "${_in[@]}"; do
        [[ -z "${seen[${t}]+x}" ]] && remaining+=("$t")
    done

    while IFS= read -r t; do
        _out+=("$t")
    done < <(printf '%s\n' "${remaining[@]:-}" | sort)
}

# ── CSV export — MySQL ─────────────────────────────────────────────────────────
# RFC 4180 formatting is done entirely in SQL:
#   NULL        → unquoted \N       (CASE WHEN col IS NULL THEN '\\N')
#   BLOB        → base64-encoded, always-quoted  (TO_BASE64)
#   All others  → always double-quoted; embedded " doubled to ""  (REPLACE + CONCAT)
#
# MySQL client is invoked with --batch --raw so it outputs the SQL result as-is
# without adding its own escape sequences.
#
export_table_mysql() {
    local table="$1"
    local out_file="$2"

    # Fetch column names and types
    local -a col_names=() col_types=()
    while IFS=$'\t' read -r col_name col_type; do
        [[ -n "$col_name" ]] || continue
        col_names+=("$col_name")
        col_types+=("$col_type")
    done < <(mysql_query "SELECT column_name, data_type \
        FROM information_schema.columns \
        WHERE table_schema = DATABASE() AND table_name = '${table}' \
        ORDER BY ordinal_position")

    [[ ${#col_names[@]} -eq 0 ]] && { warn "No columns for ${table}, skipping"; return 1; }

    # Write always-quoted header row
    local header="" col
    for col in "${col_names[@]}"; do
        [[ -n "$header" ]] && header+=","
        header+="\"${col//\"/\"\"}\""
    done
    printf '%s\n' "$header" > "$out_file"

    # Build per-column SQL CSV expressions, joined by literal comma strings
    # Resulting SQL: CONCAT(col1_expr, ',', col2_expr, ',', ...)
    local concat_inner=""
    local i dtype expr
    for i in "${!col_names[@]}"; do
        col="${col_names[$i]}"
        dtype="${col_types[$i]}"

        [[ $i -gt 0 ]] && concat_inner+=",',',"

        case "$dtype" in
            blob|longblob|mediumblob|tinyblob)
                # Binary: base64-encode, then quote.
                # Importer must base64-decode before inserting into DB.
                expr="CASE WHEN \`${col}\` IS NULL"
                expr+=" THEN '\\\\N'"
                expr+=" ELSE CONCAT('\"', TO_BASE64(\`${col}\`), '\"') END"
                ;;
            *)
                # Cast to utf8mb4 text, escape backslash and double-quote with backslash,
                # then always quote the field.
                # '\\\\N' in bash double-quotes → '\\N' in SQL → \N output value.
                expr="CASE WHEN \`${col}\` IS NULL"
                expr+=" THEN '\\\\N'"
                expr+=" ELSE CONCAT('\"', "
                expr+="REPLACE(REPLACE(CONVERT(\`${col}\` USING utf8mb4), CHAR(92), CONCAT(CHAR(92), CHAR(92))), "
                expr+="CHAR(34), CONCAT(CHAR(92), CHAR(34))), "
                expr+="'\"') END"
                ;;
        esac
        concat_inner+="$expr"
    done

    # Export rows — one CSV line per row, including embedded newlines in quoted fields
    mysql_query "SELECT CONCAT(${concat_inner}) FROM \`${table}\`" >> "$out_file"

    # Return row count on stdout for the caller to capture
    mysql_query "SELECT COUNT(*) FROM \`${table}\`"
}

# ── CSV export — PostgreSQL ────────────────────────────────────────────────────
# Uses native COPY CSV format:
#   NULL  → unquoted \N  (via NULL '\N' option)
#   other → RFC 4180 minimum-quoting (quoted when field contains comma, quote, or newline)
#   BYTEA → PostgreSQL hex-encoded (\x...) by default
#
# Note: quoting style is QUOTE_MINIMAL (unlike MySQL output which is always-quoted).
# Both are valid RFC 4180; the importer must use a proper RFC 4180 parser.
#
export_table_pgsql() {
    local table="$1"
    local out_file="$2"

    # Fetch column names
    local -a col_names=()
    while IFS= read -r col_name; do
        [[ -n "$col_name" ]] || continue
        col_names+=("$col_name")
    done < <(psql_query "SELECT column_name \
        FROM information_schema.columns \
        WHERE table_schema = 'public' AND table_name = '${table}' \
        ORDER BY ordinal_position")

    [[ ${#col_names[@]} -eq 0 ]] && { warn "No columns for ${table}, skipping"; return 1; }

    # Write always-quoted header row (for consistency with MySQL output)
    local header="" col
    for col in "${col_names[@]}"; do
        [[ -n "$header" ]] && header+=","
        header+="\"${col//\"/\"\"}\""
    done
    printf '%s\n' "$header" > "$out_file"

    # Export rows using psql \copy (client-side, writes to stdout)
    # NULL '\N'  → unquoted \N for SQL NULLs (field values equal to \N are auto-quoted by PostgreSQL)
    printf '\\copy "%s" to stdout with (format csv, header false, null '"'"'\\N'"'"', encoding '"'"'UTF8'"'"')\n' \
        "$table" \
        | PGPASSWORD="${DB_PASS}" psql \
            --no-align --tuples-only --quiet \
            -h "${DB_HOST}" -p "${DB_PORT}" -U "${DB_USER}" "${DB_NAME}" \
            >> "$out_file"

    # Return row count
    psql_query "SELECT COUNT(*) FROM \"${table}\""
}

# ── JSON builders ──────────────────────────────────────────────────────────────
# Built with printf — no jq dependency required.

# Build a JSON string array from bash arguments: ["a","b","c"]
json_str_array() {
    local result="[" first=1 item
    for item in "$@"; do
        [[ $first -eq 0 ]] && result+=", "
        result+="\"${item}\""
        first=0
    done
    result+="]"
    printf '%s' "$result"
}

write_manifest() {
    local out_file="$1" mandatory="$2" optional="$3" created_at="$4"
    shift 4
    local -a entries=("$@")   # pre-formatted JSON objects, one per table

    {
        printf '{\n'
        printf '  "version": "%s",\n'              "$SCRIPT_VERSION"
        printf '  "zabbix_version": "%s",\n'       "$optional"
        printf '  "dbversion_mandatory": %s,\n'    "$mandatory"
        printf '  "dbversion_optional": %s,\n'     "$optional"
        printf '  "created_at": "%s",\n'           "$created_at"
        printf '  "tables": [\n'
        local i
        for i in "${!entries[@]}"; do
            [[ $i -gt 0 ]] && printf ',\n'
            printf '    %s' "${entries[$i]}"
        done
        printf '\n  ]\n}\n'
    } > "$out_file"
}

write_report() {
    local out_file="$1" created_at="$2" mandatory="$3" optional="$4"
    local tables_exported="$5" total_rows="$6" uncompressed_bytes="$7"
    shift 7
    local -a default_excluded=("$@")

    {
        printf '{\n'
        printf '  "exported_at": "%s",\n'                   "$created_at"
        printf '  "source_db": "%s",\n'                     "$DB_NAME"
        printf '  "source_host": "%s",\n'                   "$DB_HOST"
        printf '  "zabbix_version": "%s",\n'               "$optional"
        printf '  "tables_exported": %s,\n'                 "$tables_exported"
        printf '  "tables_excluded_default": %s,\n'         "$(json_str_array "${default_excluded[@]}")"
        printf '  "tables_excluded_by_user": %s,\n'         "$(json_str_array ${EXTRA_EXCLUDE//,/ })"
        printf '  "total_rows": %s,\n'                      "$total_rows"
        printf '  "bundle_size_uncompressed_bytes": %s\n'   "$uncompressed_bytes"
        printf '}\n'
    } > "$out_file"
}

# ── Bundle assembly ────────────────────────────────────────────────────────────

build_bundle() {
    local mandatory="$1" optional="$2"

    local created_at
    created_at="$(date -u +%Y-%m-%dT%H:%M:%SZ)"

    mkdir -p "${WORK_DIR}/tables"

    # Build exclusion lookup
    declare -A excluded_set
    for t in "${EXCLUDED_TABLES[@]}"; do excluded_set["$t"]=1; done
    for t in ${EXTRA_EXCLUDE//,/ };    do excluded_set["$t"]=1; done

    # Discover and filter tables
    local -a all_tables=()
    while IFS= read -r t; do
        [[ -n "$t" ]] || continue
        all_tables+=("$t")
    done < <(get_all_tables)

    local -a to_export=()
    local -a actually_excluded_default=()
    for t in "${all_tables[@]}"; do
        if is_excluded "$t" excluded_set; then
            # Collect tables actually excluded by default (present in DB and in Appendix A)
            for excl in "${EXCLUDED_TABLES[@]}"; do
                [[ "$t" == "$excl" ]] && actually_excluded_default+=("$t") && break
            done
        else
            to_export+=("$t")
        fi
    done

    [[ ${#to_export[@]} -eq 0 ]] && die "No tables to export after applying exclusions."

    log "Tables in DB: ${#all_tables[@]} | Excluded: $((${#all_tables[@]} - ${#to_export[@]})) | To export: ${#to_export[@]}"

    # Sort by FK-safe order
    local -a sorted_tables=()
    sort_tables to_export sorted_tables

    # Export each table
    local -a manifest_entries=()
    local total_rows=0 seq=1
    local -a checksum_lines=()
    local table row_count rel_path

    for table in "${sorted_tables[@]}"; do
        rel_path="tables/$(printf '%03d' "$seq")_${table}.csv"
        local out_file="${WORK_DIR}/${rel_path}"

        log "  [$(printf '%03d' "$seq")] ${table}"

        if [[ "$DB_TYPE" == "mysql" ]]; then
            row_count=$(export_table_mysql "$table" "$out_file") || { seq=$((seq+1)); continue; }
        else
            row_count=$(export_table_pgsql "$table" "$out_file") || { seq=$((seq+1)); continue; }
        fi

        row_count="${row_count//[^0-9]/}"   # strip any whitespace
        total_rows=$((total_rows + row_count))

        # SHA-256 of this CSV file
        local digest
        digest=$(sha256sum "$out_file" | awk '{print $1}')
        checksum_lines+=("${digest}  ${rel_path}")

        manifest_entries+=("$(printf '{"seq": %d, "file": "%s", "table": "%s", "rows": %d}' \
            "$seq" "$rel_path" "$table" "$row_count")")

        log "      → ${row_count} rows"
        seq=$((seq+1))
    done

    # ── manifest.json ──────────────────────────────────────────────────────────
    write_manifest \
        "${WORK_DIR}/manifest.json" \
        "$mandatory" "$optional" "$created_at" \
        "${manifest_entries[@]}"

    local manifest_digest
    manifest_digest=$(sha256sum "${WORK_DIR}/manifest.json" | awk '{print $1}')
    checksum_lines+=("${manifest_digest}  manifest.json")

    # ── export_report.json ─────────────────────────────────────────────────────
    local uncompressed_bytes=0
    while IFS= read -r f; do
        local sz
        sz=$(wc -c < "$f")
        uncompressed_bytes=$((uncompressed_bytes + sz))
    done < <(find "${WORK_DIR}" -type f ! -name checksums.sha256)

    write_report \
        "${WORK_DIR}/export_report.json" \
        "$created_at" "$mandatory" "$optional" \
        "${#manifest_entries[@]}" "$total_rows" "$uncompressed_bytes" \
        "${actually_excluded_default[@]:-}"

    local report_digest
    report_digest=$(sha256sum "${WORK_DIR}/export_report.json" | awk '{print $1}')
    checksum_lines+=("${report_digest}  export_report.json")

    # ── checksums.sha256 ───────────────────────────────────────────────────────
    # checksums.sha256 itself is NOT included in its own checksum.
    printf '%s\n' "${checksum_lines[@]}" | sort > "${WORK_DIR}/checksums.sha256"

    # ── Create bundle.gz ───────────────────────────────────────────────────────
    log "Packing bundle..."
    tar czf "${OUTPUT}" -C "${WORK_DIR}" \
        manifest.json export_report.json checksums.sha256 tables/

    local bundle_size
    bundle_size=$(wc -c < "${OUTPUT}")

    log "──────────────────────────────────────────"
    log "Bundle:            ${OUTPUT}"
    log "Compressed size:   $(( bundle_size   / 1024 / 1024 )) MB"
    log "Uncompressed size: $(( uncompressed_bytes / 1024 / 1024 )) MB"
    log "Tables exported:   ${#manifest_entries[@]}"
    log "Total rows:        ${total_rows}"
    log "──────────────────────────────────────────"
    log "Verify with: tar xzf ${OUTPUT} -C /tmp/verify && cd /tmp/verify && sha256sum -c checksums.sha256"

    if (( bundle_size > BUNDLE_SIZE_HARD_LIMIT )); then
        die "Bundle size $(( bundle_size / 1024 / 1024 / 1024 )) GB exceeds the 5 GB S3 single-PUT limit. Use --exclude to reduce scope."
    elif (( bundle_size > BUNDLE_SIZE_WARN_LIMIT )); then
        warn "Bundle size $(( bundle_size / 1024 / 1024 / 1024 )) GB is approaching the 5 GB upload limit."
    fi
}

# ── Argument parsing ───────────────────────────────────────────────────────────

usage() {
    cat >&2 << EOF
Usage: $0 --db-type mysql|pgsql --db-host HOST --db-name DB --db-user USER
          [--db-pass PASS] [--db-port PORT] [--exclude t1,t2] [--output bundle.gz]

Options:
  --db-type    mysql or pgsql (required)
  --db-host    Database host           (default: 127.0.0.1)
  --db-port    Database port           (default: 3306 / 5432)
  --db-name    Database name           (required)
  --db-user    Database user           (required)
  --db-pass    Database password       (prompted if omitted)
  --exclude    Additional tables to exclude, comma-separated
  --output     Output file path        (default: bundle.gz)
EOF
    exit 1
}

parse_args() {
    [[ $# -eq 0 ]] && usage

    while [[ $# -gt 0 ]]; do
        case "$1" in
            --db-type)  DB_TYPE="$2";    shift 2 ;;
            --db-host)  DB_HOST="$2";    shift 2 ;;
            --db-port)  DB_PORT="$2";    shift 2 ;;
            --db-name)  DB_NAME="$2";    shift 2 ;;
            --db-user)  DB_USER="$2";    shift 2 ;;
            --db-pass)  DB_PASS="$2"; DB_PASS_SET=1; shift 2 ;;
            --exclude)  EXTRA_EXCLUDE="$2"; shift 2 ;;
            --output)   OUTPUT="$2";     shift 2 ;;
            -h|--help)  usage ;;
            *)          die "Unknown option: $1" ;;
        esac
    done

    [[ -n "$DB_TYPE" ]] || die "--db-type is required (mysql or pgsql)"
    [[ -n "$DB_NAME" ]] || die "--db-name is required"
    [[ -n "$DB_USER" ]] || die "--db-user is required"
    [[ "$DB_TYPE" == "mysql" || "$DB_TYPE" == "pgsql" ]] || die "--db-type must be 'mysql' or 'pgsql'"

    # Set default port
    if [[ -z "$DB_PORT" ]]; then
        [[ "$DB_TYPE" == "mysql" ]] && DB_PORT="3306" || DB_PORT="5432"
    fi

    # Prompt for password only if --db-pass was omitted entirely
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

    log "Zabbix export script v${SCRIPT_VERSION}"
    log "Source: ${DB_TYPE}://${DB_HOST}:${DB_PORT}/${DB_NAME}"

    # Version check — returns "mandatory optional" on stdout
    local dbversion
    dbversion=$(check_zabbix_version)
    local mandatory optional
    read -r mandatory optional <<< "$dbversion"

    build_bundle "$mandatory" "$optional"

    log "Done."
}

main "$@"