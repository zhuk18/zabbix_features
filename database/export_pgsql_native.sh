#!/usr/bin/env bash
# zabbix_export_pgsql_native.sh - Minimal native PostgreSQL bundle exporter

set -euo pipefail

readonly SCRIPT_VERSION="1.0"

# Keep exclusions aligned with migration scope.
declare -a EXCLUDED_TABLES=(
    ids dbversion
    history history_uint history_str history_log history_text history_bin history_json
    trends trends_uint
    events event_symptom event_tag event_recovery event_suppress problem problem_tag
    alerts acknowledges escalations
    service_alarms service_problem service_problem_tag
    item_rtdata host_rtdata
    housekeeper trigger_queue lld_macro_export changelog sessions auditlog
    task task_close_problem task_remote_command task_remote_command_result
    task_data task_result task_acknowledge task_check_now
    proxy_history proxy_dhistory proxy_rtdata proxy_group_rtdata proxy_autoreg_host
    dhosts dservices autoreg_host
    ha_node
)

# Deterministic table order.
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

DB_HOST="127.0.0.1"
DB_PORT="5432"
DB_NAME=""
DB_USER=""
DB_PASS=""
DB_PASS_SET=0
EXTRA_EXCLUDE=""
OUTPUT="bundle.gz"
WORK_DIR=""

log() { printf '[%s] %s\n' "$(date -u +%H:%M:%S)" "$*" >&2; }
die() { printf 'ERROR: %s\n' "$*" >&2; exit 1; }

cleanup() {
    [[ -n "${WORK_DIR:-}" && -d "${WORK_DIR}" ]] && rm -rf "${WORK_DIR}"
}

check_deps() {
    local -a required=(psql tar gzip sha256sum awk date sort find wc)
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

read_dbversion() {
    local mandatory optional
    read -r mandatory optional < <(psql_query "SELECT mandatory, optional FROM dbversion LIMIT 1" || true)
    [[ -n "${mandatory:-}" ]] || die "Could not read dbversion table"
    echo "${mandatory} ${optional}"
}

get_all_tables() {
    psql_query "SELECT tablename FROM pg_tables WHERE schemaname = 'public' ORDER BY tablename"
}

is_excluded() {
    local table="$1"
    local -n excluded_set_ref="$2"
    [[ -n "${excluded_set_ref[${table}]+x}" ]]
}

sort_tables() {
    local -n in_ref="$1"
    local -n out_ref="$2"
    declare -A in_set seen
    local t

    for t in "${in_ref[@]}"; do in_set["$t"]=1; done

    for t in "${TABLE_LOAD_ORDER[@]}"; do
        if [[ -n "${in_set[${t}]+x}" ]]; then
            out_ref+=("$t")
            seen["$t"]=1
        fi
    done

    local -a remaining=()
    for t in "${in_ref[@]}"; do
        [[ -z "${seen[${t}]+x}" ]] && remaining+=("$t")
    done

    while IFS= read -r t; do
        out_ref+=("$t")
    done < <(printf '%s\n' "${remaining[@]:-}" | sort)
}

# Native PostgreSQL CSV export (minimal quoting, native bytea format, CSV header).
export_table_pgsql_native() {
    local table="$1"
    local out_file="$2"
    local table_esc

    table_esc="${table//\"/\"\"}"

    printf '\\copy (SELECT * FROM "%s") TO STDOUT WITH (FORMAT csv, HEADER true, NULL '\''\\N'\'', ENCODING '\''UTF8'\'')\n' "$table_esc" \
        | PGPASSWORD="${DB_PASS}" psql \
            --no-align --tuples-only --quiet \
            -h "${DB_HOST}" -p "${DB_PORT}" -U "${DB_USER}" "${DB_NAME}" \
            > "$out_file"

    psql_query "SELECT COUNT(*) FROM \"${table_esc}\""
}

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
    local -a entries=("$@")
    local i

    {
        printf '{\n'
        printf '  "version": "%s",\n' "$SCRIPT_VERSION"
        printf '  "zabbix_version": "%s",\n' "$optional"
        printf '  "dbversion_mandatory": %s,\n' "$mandatory"
        printf '  "dbversion_optional": %s,\n' "$optional"
        printf '  "created_at": "%s",\n' "$created_at"
        printf '  "tables": [\n'
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
        printf '  "exported_at": "%s",\n' "$created_at"
        printf '  "source_db": "%s",\n' "$DB_NAME"
        printf '  "source_host": "%s",\n' "$DB_HOST"
        printf '  "zabbix_version": "%s",\n' "$optional"
        printf '  "tables_exported": %s,\n' "$tables_exported"
        printf '  "tables_excluded_default": %s,\n' "$(json_str_array "${default_excluded[@]}")"
        printf '  "tables_excluded_by_user": %s,\n' "$(json_str_array ${EXTRA_EXCLUDE//,/ })"
        printf '  "total_rows": %s,\n' "$total_rows"
        printf '  "bundle_size_uncompressed_bytes": %s\n' "$uncompressed_bytes"
        printf '}\n'
    } > "$out_file"
}

build_bundle() {
    local mandatory="$1" optional="$2"
    local created_at
    created_at="$(date -u +%Y-%m-%dT%H:%M:%SZ)"

    mkdir -p "${WORK_DIR}/tables"

    declare -A excluded_set
    local t
    for t in "${EXCLUDED_TABLES[@]}"; do excluded_set["$t"]=1; done
    for t in ${EXTRA_EXCLUDE//,/ }; do excluded_set["$t"]=1; done

    local -a all_tables=()
    while IFS= read -r t; do
        [[ -n "$t" ]] && all_tables+=("$t")
    done < <(get_all_tables)

    local -a to_export=() actually_excluded_default=()
    local excl
    for t in "${all_tables[@]}"; do
        if is_excluded "$t" excluded_set; then
            for excl in "${EXCLUDED_TABLES[@]}"; do
                [[ "$t" == "$excl" ]] && actually_excluded_default+=("$t") && break
            done
        else
            to_export+=("$t")
        fi
    done

    [[ ${#to_export[@]} -eq 0 ]] && die "No tables to export after applying exclusions"

    log "Tables in DB: ${#all_tables[@]} | Excluded: $((${#all_tables[@]} - ${#to_export[@]})) | To export: ${#to_export[@]}"

    local -a sorted_tables=()
    sort_tables to_export sorted_tables

    local -a manifest_entries=() checksum_lines=()
    local total_rows=0 seq=1 table row_count rel_path out_file digest

    for table in "${sorted_tables[@]}"; do
        rel_path="tables/$(printf '%03d' "$seq")_${table}.csv"
        out_file="${WORK_DIR}/${rel_path}"

        log "  [$(printf '%03d' "$seq")] ${table}"

        row_count=$(export_table_pgsql_native "$table" "$out_file") || { seq=$((seq + 1)); continue; }
        row_count="${row_count//[^0-9]/}"
        total_rows=$((total_rows + row_count))

        digest=$(sha256sum "$out_file" | awk '{print $1}')
        checksum_lines+=("${digest}  ${rel_path}")

        manifest_entries+=("$(printf '{"seq": %d, "file": "%s", "table": "%s", "rows": %d}' \
            "$seq" "$rel_path" "$table" "$row_count")")

        log "      -> ${row_count} rows"
        seq=$((seq + 1))
    done

    write_manifest "${WORK_DIR}/manifest.json" "$mandatory" "$optional" "$created_at" "${manifest_entries[@]}"
    digest=$(sha256sum "${WORK_DIR}/manifest.json" | awk '{print $1}')
    checksum_lines+=("${digest}  manifest.json")

    local uncompressed_bytes=0 f sz
    while IFS= read -r f; do
        sz=$(wc -c < "$f")
        uncompressed_bytes=$((uncompressed_bytes + sz))
    done < <(find "${WORK_DIR}" -type f ! -name checksums.sha256)

    write_report "${WORK_DIR}/export_report.json" "$created_at" "$mandatory" "$optional" \
        "${#manifest_entries[@]}" "$total_rows" "$uncompressed_bytes" "${actually_excluded_default[@]:-}"

    digest=$(sha256sum "${WORK_DIR}/export_report.json" | awk '{print $1}')
    checksum_lines+=("${digest}  export_report.json")

    printf '%s\n' "${checksum_lines[@]}" | sort > "${WORK_DIR}/checksums.sha256"

    log "Packing bundle..."
    tar czf "${OUTPUT}" -C "${WORK_DIR}" manifest.json export_report.json checksums.sha256 tables/

    local bundle_size
    bundle_size=$(wc -c < "${OUTPUT}")

    log "------------------------------------------"
    log "Bundle:            ${OUTPUT}"
    log "Compressed size:   $((bundle_size / 1024 / 1024)) MB"
    log "Uncompressed size: $((uncompressed_bytes / 1024 / 1024)) MB"
    log "Tables exported:   ${#manifest_entries[@]}"
    log "Total rows:        ${total_rows}"
    log "------------------------------------------"
}

usage() {
    cat >&2 << EOF
Usage: $0 --db-host HOST --db-name DB --db-user USER
          [--db-pass PASS] [--db-port PORT] [--exclude t1,t2] [--output bundle.gz]

Options:
  --db-host    Database host           (default: 127.0.0.1)
  --db-port    Database port           (default: 5432)
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
            --db-host) DB_HOST="$2"; shift 2 ;;
            --db-port) DB_PORT="$2"; shift 2 ;;
            --db-name) DB_NAME="$2"; shift 2 ;;
            --db-user) DB_USER="$2"; shift 2 ;;
            --db-pass) DB_PASS="$2"; DB_PASS_SET=1; shift 2 ;;
            --exclude) EXTRA_EXCLUDE="$2"; shift 2 ;;
            --output) OUTPUT="$2"; shift 2 ;;
            -h|--help) usage ;;
            *) die "Unknown option: $1" ;;
        esac
    done

    [[ -n "$DB_NAME" ]] || die "--db-name is required"
    [[ -n "$DB_USER" ]] || die "--db-user is required"

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

    log "Zabbix PostgreSQL native export script v${SCRIPT_VERSION}"
    log "Source: pgsql://${DB_HOST}:${DB_PORT}/${DB_NAME}"

    local dbversion mandatory optional
    dbversion=$(read_dbversion)
    read -r mandatory optional <<< "$dbversion"

    build_bundle "$mandatory" "$optional"
    log "Done."
}

main "$@"
