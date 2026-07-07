#!/usr/bin/env bash
# zabbix_import_pgsql_native.sh - Minimal native PostgreSQL bundle importer

set -euo pipefail

readonly SCRIPT_VERSION="1.0"

BUNDLE=""
DB_HOST="127.0.0.1"
DB_PORT="5432"
DB_NAME=""
DB_USER=""
DB_PASS=""
DB_PASS_SET=0
CLOUD_VERSION=""
WORK_DIR=""

declare -a MANIFEST_FILES=()
declare -a MANIFEST_TABLES=()
declare -a MANIFEST_ROWS=()

log() { printf '[%s] %s\n' "$(date -u +%H:%M:%S)" "$*" >&2; }
die() { printf 'ERROR: %s\n' "$*" >&2; exit 1; }

cleanup() {
    [[ -n "${WORK_DIR:-}" && -d "${WORK_DIR}" ]] && rm -rf "${WORK_DIR}"
}

check_deps() {
    local -a required=(psql tar gzip sha256sum awk head tr)
    local cmd
    for cmd in "${required[@]}"; do
        command -v "$cmd" >/dev/null 2>&1 || die "Required command not found: ${cmd}"
    done
}

psql_query() {
    PGPASSWORD="${DB_PASS}" psql \
        --no-align --tuples-only --quiet \
        -F $'\t' \
        -h "${DB_HOST}" -p "${DB_PORT}" -U "${DB_USER}" "${DB_NAME}" \
        -c "$1"
}

psql_exec_file() {
    PGPASSWORD="${DB_PASS}" psql \
        --no-align --tuples-only --quiet \
        --set ON_ERROR_STOP=1 \
        -h "${DB_HOST}" -p "${DB_PORT}" -U "${DB_USER}" "${DB_NAME}" \
        -f "$1"
}

extract_bundle() {
    log "Extracting bundle: ${BUNDLE}"
    tar xzf "${BUNDLE}" -C "${WORK_DIR}"

    [[ -f "${WORK_DIR}/manifest.json" ]] || die "Bundle is missing manifest.json"
    [[ -f "${WORK_DIR}/checksums.sha256" ]] || die "Bundle is missing checksums.sha256"
    [[ -d "${WORK_DIR}/tables" ]] || die "Bundle is missing tables/ directory"
}

verify_checksums() {
    log "Verifying checksums..."
    (cd "${WORK_DIR}" && sha256sum -c checksums.sha256 --quiet) || die "Checksum verification failed"
    log "Checksums OK"
}

parse_manifest() {
    local manifest="${WORK_DIR}/manifest.json"
    local line file table rows

    while IFS= read -r line; do
        file=$(echo "$line" | awk -F'"' '/"file"/{for(i=1;i<=NF;i++) if($i=="file") print $(i+2)}')
        table=$(echo "$line" | awk -F'"' '/"table"/{for(i=1;i<=NF;i++) if($i=="table") print $(i+2)}')
        rows=$(echo "$line" | awk -F'[: ,}]+' '/"rows"/{for(i=1;i<=NF;i++) if($i~/rows/) print $(i+1)}' | tr -d '"')

        [[ -n "$file" && -n "$table" && -n "$rows" ]] || continue
        MANIFEST_FILES+=("$file")
        MANIFEST_TABLES+=("$table")
        MANIFEST_ROWS+=("$rows")
    done < <(grep '"file"' "$manifest")

    [[ ${#MANIFEST_FILES[@]} -gt 0 ]] || die "manifest.json has no table entries"
    log "Manifest parsed: ${#MANIFEST_FILES[@]} tables"
}

load_table() {
    local table="$1"
    local csv_file="$2"
    local expected_rows="$3"

    local exists
    exists=$(psql_query "SELECT COUNT(*) FROM pg_tables WHERE schemaname='public' AND tablename='${table}'")
    [[ "$exists" -eq 1 ]] || { log "Skipping missing table: ${table}"; return 0; }

    local sql_file="${WORK_DIR}/load_${table}.sql"
    local table_esc csv_esc
    table_esc="${table//\"/\"\"}"
    csv_esc="${csv_file//\'/\'\'}"

    {
        printf '\\copy "%s" FROM '\''%s'\'' WITH (FORMAT csv, HEADER true, NULL '\''\\N'\'', ENCODING '\''UTF8'\'')\n' "$table_esc" "$csv_esc"
    } > "$sql_file"

    if ! psql_exec_file "$sql_file" >/dev/null 2>&1; then
        return 1
    fi

    local actual
    actual=$(psql_query "SELECT COUNT(*) FROM \"${table_esc}\"")
    [[ "$actual" == "$expected_rows" ]] || die "Row count mismatch for ${table}: expected ${expected_rows}, got ${actual}"
}

truncate_manifest_tables() {
    log "Truncating target tables..."

    local table_list=""
    local i table table_esc
    for i in "${!MANIFEST_TABLES[@]}"; do
        table="${MANIFEST_TABLES[$i]}"
        table_esc="${table//\"/\"\"}"
        [[ -n "$table_list" ]] && table_list+=", "
        table_list+="\"${table_esc}\""
    done

    [[ -n "$table_list" ]] || die "No tables in manifest to truncate"
    psql_query "TRUNCATE TABLE ${table_list} CASCADE" >/dev/null
}

import_tables() {
    log "Starting table import..."
    truncate_manifest_tables

    local -a pending=()
    local i rel table rows csv_file
    for i in "${!MANIFEST_FILES[@]}"; do
        pending+=("$i")
    done

    local pass=1 progressed=0
    local -a next_pending=()
    while (( ${#pending[@]} > 0 )); do
        progressed=0
        next_pending=()
        log "Import pass ${pass}: pending tables=${#pending[@]}"

        for i in "${pending[@]}"; do
            rel="${MANIFEST_FILES[$i]}"
            table="${MANIFEST_TABLES[$i]}"
            rows="${MANIFEST_ROWS[$i]}"
            csv_file="${WORK_DIR}/${rel}"

            [[ -f "$csv_file" ]] || die "Missing CSV file: ${rel}"
            log "  [$(printf '%03d' $((i + 1)))] loading ${table} (${rows} rows)"

            if load_table "$table" "$csv_file" "$rows"; then
                progressed=$((progressed + 1))
            else
                next_pending+=("$i")
            fi
        done

        if (( progressed == 0 )); then
            local unresolved=""
            for i in "${next_pending[@]}"; do
                [[ -n "$unresolved" ]] && unresolved+=", "
                unresolved+="${MANIFEST_TABLES[$i]}"
            done
            die "Could not resolve table load dependencies. Unresolved tables: ${unresolved}"
        fi

        pending=("${next_pending[@]}")
        pass=$((pass + 1))
    done

    log "Table import complete"
}

rebuild_ids() {
    log "Rebuilding ids table..."

    local sql_file="${WORK_DIR}/rebuild_ids.sql"
    : > "$sql_file"

    local i table pk_col table_esc pk_esc
    for i in "${!MANIFEST_TABLES[@]}"; do
        table="${MANIFEST_TABLES[$i]}"

        pk_col=$(psql_query "SELECT kcu.column_name \
            FROM information_schema.table_constraints tc \
            JOIN information_schema.key_column_usage kcu \
              ON tc.constraint_name = kcu.constraint_name \
             AND tc.table_schema = kcu.table_schema \
             AND tc.table_name = kcu.table_name \
            JOIN information_schema.columns c \
              ON c.table_schema = kcu.table_schema \
             AND c.table_name = kcu.table_name \
             AND c.column_name = kcu.column_name \
            WHERE tc.constraint_type = 'PRIMARY KEY' \
              AND tc.table_schema = 'public' \
              AND tc.table_name = '${table}' \
              AND c.data_type IN ('smallint','integer','bigint') \
            GROUP BY kcu.table_name, kcu.column_name \
            HAVING COUNT(*) = 1" | head -1 | tr -d '[:space:]')

        [[ -n "$pk_col" ]] || continue

        table_esc="${table//\"/\"\"}"
        pk_esc="${pk_col//\"/\"\"}"

        printf 'INSERT INTO ids (table_name, field_name, nextid)\n' >> "$sql_file"
        printf 'SELECT '\''%s'\'', '\''%s'\'', COALESCE(MAX("%s"), 0)\n' "$table_esc" "$pk_esc" "$pk_esc" >> "$sql_file"
        printf 'FROM "%s"\n' "$table_esc" >> "$sql_file"
        printf 'ON CONFLICT (table_name, field_name) DO UPDATE SET nextid = EXCLUDED.nextid;\n\n' >> "$sql_file"
    done

    psql_exec_file "$sql_file" >/dev/null
    log "ids table rebuilt"
}

set_dbversion() {
    log "Setting dbversion to ${CLOUD_VERSION}"
    psql_query "INSERT INTO dbversion (dbversionid, mandatory, optional) \
        VALUES (1, ${CLOUD_VERSION}, ${CLOUD_VERSION}) \
        ON CONFLICT (dbversionid) DO UPDATE SET mandatory = EXCLUDED.mandatory, optional = EXCLUDED.optional" >/dev/null
}

usage() {
    cat >&2 << EOF
Usage: $0 --bundle FILE --db-host HOST --db-name DB --db-user USER --cloud-version VERSION
          [--db-pass PASS] [--db-port PORT]
EOF
    exit 1
}

parse_args() {
    [[ $# -eq 0 ]] && usage

    while [[ $# -gt 0 ]]; do
        case "$1" in
            --bundle) BUNDLE="$2"; shift 2 ;;
            --db-host) DB_HOST="$2"; shift 2 ;;
            --db-port) DB_PORT="$2"; shift 2 ;;
            --db-name) DB_NAME="$2"; shift 2 ;;
            --db-user) DB_USER="$2"; shift 2 ;;
            --db-pass) DB_PASS="$2"; DB_PASS_SET=1; shift 2 ;;
            --cloud-version) CLOUD_VERSION="$2"; shift 2 ;;
            -h|--help) usage ;;
            *) die "Unknown option: $1" ;;
        esac
    done

    [[ -n "$BUNDLE" ]] || die "--bundle is required"
    [[ -f "$BUNDLE" ]] || die "Bundle not found: ${BUNDLE}"
    [[ -n "$DB_NAME" ]] || die "--db-name is required"
    [[ -n "$DB_USER" ]] || die "--db-user is required"
    [[ "$CLOUD_VERSION" =~ ^[0-9]+$ ]] || die "--cloud-version must be a numeric value"

    if [[ $DB_PASS_SET -eq 0 ]]; then
        read -rsp "DB password for ${DB_USER}@${DB_HOST}: " DB_PASS
        printf '\n'
    fi
}

main() {
    parse_args "$@"
    check_deps

    WORK_DIR=$(mktemp -d)
    trap cleanup EXIT

    log "Zabbix PostgreSQL native import script v${SCRIPT_VERSION}"
    log "Target: pgsql://${DB_HOST}:${DB_PORT}/${DB_NAME}"
    log "Bundle: ${BUNDLE}"

    extract_bundle
    verify_checksums
    parse_manifest
    import_tables
    rebuild_ids
    set_dbversion

    log "------------------------------------------"
    log "Import complete"
    log "Tables loaded: ${#MANIFEST_TABLES[@]}"
    log "Cloud version: ${CLOUD_VERSION}"
    log "------------------------------------------"
}

main "$@"
