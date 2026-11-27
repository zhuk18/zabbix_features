#!/bin/bash
# The common run of every topology test that can run on the lab VM (task 046): the PHP tests (each in its own freshly created MySQL database, in the order the tests document:
# test_ingest_lock.php needs the stand-in schema that test_snapshot_ingest.php leaves, and most tests drop and recreate the tables they use; one shared database makes them fail for
# reasons that are not in the code), then the Python tests, the JS rule tests, the step golden tests, the lab unit tests, the checks on the lab state and the API tests of the frontend.
# The list of what is NOT in this run, and why, is in README-tests.md next to this file.
#
# Usage: run_php_tests.sh [--only <areas>] [--list] <database user> [password]     (MySQL on localhost; creates and drops topo_t_* databases)
#   --only a,b   run only the tests of these areas (task 119 section 0a; the areas and what is in each are in README-tests.md, `--list` prints the area of every test); no --only: every test
#   LABPY=<python with PyYAML and mysql-connector>   default ~/work/labenv/bin/python (lab unit tests, corpus converter test)
#   PHPUNIT=<phpunit 8.5 phar>                       default ~/work/tools/phpunit-8.5.phar (the API tests; skipped when absent)
#   ZBX_UI_USER, ZBX_UI_PASSWORD                     the login of the frontend for the checks of the real page (skipped when not set; never written to a file)
# Exit status 0 when no test failed. A test that needs a state the lab does not have is SKIPPED with its reason (it does not fail the run and is not hidden: see the table).
set -u
only=""
list_only=0
while [ $# -gt 0 ]; do
	case $1 in
		--only) only=${2:?--only needs a list of areas}; shift 2 ;;
		--only=*) only=${1#--only=}; shift ;;
		--list) list_only=1; shift ;;
		*) break ;;
	esac
done
user=${1:-}
[ -n "$user" ] || [ $list_only = 1 ] || { echo "usage: $0 [--only <areas>] [--list] <database user> [password]" >&2; exit 2; }
password=${2:-}
here=$(cd "$(dirname "$0")" && pwd)
repo=$(cd "$here/../.." && pwd)
export MYSQL_PWD=$password
failed=0
labpy=${LABPY:-$HOME/work/labenv/bin/python}
phpunit=${PHPUNIT:-$HOME/work/tools/phpunit-8.5.phar}
results=$(mktemp)
# the legend state of Admin (the page checks toggle it): saved at the start, put back at the end, also after a failure (task 100 §4)
legend_row="idx = 'web.topology.legend.collapsed' AND userid = (SELECT userid FROM users WHERE username = 'Admin')"
legend_saved=$(mysql -N -u"$user" zabbix -e "SELECT value_int FROM profiles WHERE $legend_row" 2>/dev/null)
restore_legend() {
	if [ -n "$legend_saved" ]; then
		mysql -u"$user" zabbix -e "UPDATE profiles SET value_int = $legend_saved WHERE $legend_row" 2>/dev/null
	else
		mysql -u"$user" zabbix -e "DELETE FROM profiles WHERE $legend_row" 2>/dev/null
	fi
}
trap 'rm -f "$results"; restore_legend' EXIT
start_all=$(date +%s)

# the areas of a test (task 119 section 0a): a test is in the areas that its name matches; a test with no area of its own is in `other`. `slow` marks a test that took 45 s or more in
# the full run 502 (README-tests.md); `page` is every check of the real page (browser).
areas_of() {
	local a=""
	case "$1" in
		*test_bind_interface*|*"Bind to interface"*|*bind_check*) a="bind" ;;
		*test_manual_device*|*manual_device_check*|*"manual Device"*) a="manual" ;;
		*test_junction*|*"Junction projection"*|*junction_check*) a="junction" ;;
		*test_closing_ticks*|*test_topology_type_form*|*test_json_conditions*|*test_scenario_catalog*|*test_snapshot_ingest*|*test_ingest_lock*|*test_stable_ingest*|*test_ingest_identity*|*test_old_snapshot*|*test_run_*|*test_two_ports_rules*|*test_port_*|*test_far_*|*test_claim_key*|*test_linked_port_claims*|*test_steps*|*test_device_row*|*test_allowed_age*|*test_pseudo_port_index*|*test_remote_attrs_cap*|*test_manual_link_busy_port*) a="ingest" ;;
		*test_device_link*|*test_link_replacement*|*test_manual_contradiction*|*test_contradiction_rule*|*test_topology_model*) a="links" ;;
		*test_identity*|*test_shared_identifier*|*test_cdp_device_key*|*test_chassis_subtypes*|*test_normalization*|*test_duplicate_candidates*|*test_addresses*|*test_device_last_seen*|*test_decision*|*test_device_class*) a="identity" ;;
		*step_golden*|*test_generated*|*lld_js*|*test_push*|*device_rows_check*|*test_snapshot_clock*) a="native" ;;
		lab/*|corpus/*) a="lab" ;;
		ui/tests/*) a="api" ;;
		*test_topology_graph.js*|*test_find_device*|*test_speed_mismatch*|*test_node_label*|*test_leaf_groups*|*test_hop_scope*|*test_freshness*|*test_lazy_host_nodes*) a="view" ;;
		page*|*page_shot*|*_check.py*|*nolink_host*) a="page" ;;
		*) a="other" ;;
	esac
	case "$1" in
		*"lab/php/graph_page_check"*|*"lab/php/lastrun_http"*|*"lab/php/run_row_http"*|page*|*_check.py) case " $a " in *" page "*) ;; *) a="$a page" ;; esac ;;
	esac
	case "$1" in
		*proxy_check*|*test_scenario_catalog*|*"deterministic first layout"*|*"one header for the graph"*|*"page escaping"*|*test_device_links.php|*"Bind to interface by name"*|*device_rows_check*|*test_snapshot_clock*|*run_row_http_check*|*test_far_device_lookup*|*test_link_replacement*|*"panel of SW12"*|*"selection after Apply"*) a="$a slow" ;;
	esac
	echo "$a"
}
selected() {   # selected <test name>: 0 when the test is in the run
	local a x o
	[ -z "$only" ] && return 0
	a=$(areas_of "$1")
	for o in ${only//,/ }; do
		for x in $a; do [ "$x" = "$o" ] && return 0; done
	done
	return 1
}
if [ $list_only = 1 ]; then
	{ grep -oE '(discovery/(lld_js/|step_golden/)?|lab/php/|ui_check_040/|corpus/)[A-Za-z_0-9]*\.(php|py|js)' "$0"; grep -oE '"?(test_[a-z_]+\.(php|js))' "$0" | tr -d '"'; echo "lab/tests (unittest discover)"; echo "ui/tests/api_json/testDiscoveryRule*.php"; } | sed 's#^here/##' | sort -u | while read -r t; do printf '%-60s %s\n' "$t" "$(areas_of "$t")"; done
	exit 0
fi

fresh() {
	mysql -u"$user" -e "DROP DATABASE IF EXISTS $1; CREATE DATABASE $1" || exit 2
}

# the number of checks a log shows: the larger of the lines "ok: ..." and the total the test prints ("OK: N checks", "N of N checks passed", "Ran N tests", "OK (N tests, ...)"; a test that prints both would count twice if they were added)
count_of() {
	awk '
		/^[ \t]*(ok|PASS)[: ]/ { lines++ }
		/^OK: [0-9]+ checks/ { total += $2 }
		/^check [a-z]+: ok/ { lines++ }
		/^[0-9]+ of [0-9]+ checks passed/ { total += $1 }
		/^Ran [0-9]+ tests? / { total += $2 }
		/^OK \([0-9]+ tests?,/ { gsub(/\(/, "", $2); total += $2 }
		/^run time: .* \([0-9]+ cases\)/ { match($0, /\([0-9]+ cases\)/); total += substr($0, RSTART + 1, RLENGTH - 8) }
		END { print (total > lines ? total : lines) + 0 }' "$1"
}

record() {   # record <name> <PASS|FAIL|SKIP> <seconds> <log or reason>
	local name=$1 status=$2 secs=$3 info=$4 count=0
	if [ "$status" != SKIP ]; then count=$(count_of "$info"); info=$info; fi
	printf '%s|%s|%s|%s|%s\n' "$name" "$status" "$count" "$secs" "$info" >>"$results"
	case $status in
		PASS) echo "pass  $name" ;;
		SKIP) echo "SKIP  $name   ($info)" ;;
		INFRA) echo "INFRA $name   ($info)"; failed=1 ;;
		*) echo "FAIL  $name   ($info)"; failed=1 ;;
	esac
}

# exec <name> <log> <command...>: runs the command, logs its output, records the verdict
exec_test() {
	local name=$1 log=$2 s rc
	shift 2
	selected "$name" || return 0
	s=$(date +%s)
	"$@" >"$log" 2>&1
	rc=$?
	record "$name" $([ $rc = 0 ] && echo PASS || echo FAIL) $(( $(date +%s) - s )) "$log"
}

# page_test <name> <log> <command...> (task 098 §2): a check of the real page. Each run has a limit of PAGE_TIMEOUT seconds (default 900). An infrastructure error is the limit, "chrome did not start",
# "login failed", or no result line at all (a Chrome that did not answer): it is retried once, and counted apart ("infrastructure: <n>"); a check that FAILS (a result line says so) is never retried.
infra=0
page_infra() {   # page_infra <rc> <log>: 0 when the run was an infrastructure error
	local rc=$1 log=$2
	[ "$rc" = 0 ] && return 1
	[ "$rc" = 124 ] && return 0
	grep -qE 'chrome did not start|login failed' "$log" && return 0
	grep -qE '^(check [a-z]+: (ok|FAILED)|[ \t]*(ok|FAIL):|[0-9]+ check\(s\) FAILED|All .* passed)' "$log" && return 1
	return 0
}
page_test() {
	local name=$1 log=$2 s rc attempt
	shift 2
	selected "$name" || return 0
	s=$(date +%s)
	for attempt in 1 2; do
		timeout "${PAGE_TIMEOUT:-900}" "$@" >"$log" 2>&1
		rc=$?
		page_infra $rc "$log" || break
		infra=$(( infra + 1 ))
		echo "infra $name   (attempt $attempt, exit $rc; see $log)"
		[ $attempt = 1 ] && cp "$log" "$log.attempt1"
	done
	if page_infra $rc "$log"; then
		record "$name" INFRA $(( $(date +%s) - s )) "$log"
	else
		record "$name" $([ $rc = 0 ] && echo PASS || echo FAIL) $(( $(date +%s) - s )) "$log"
	fi
}

php_test() {   # php_test <database> <test> [dsn|name]
	local db=$1 test=$2 style=${3:-dsn} arg
	if [ "$style" = dsn ]; then
		arg="mysql:host=localhost;dbname=$db;charset=utf8mb4"
	else
		arg=$db
	fi
	exec_test "$test" "/tmp/topo_t_$(basename "$test" .php).log" php "$here/$test" "$arg" "$user" "$password"
}

skip() {   # skip <name> <reason>
	record "$1" SKIP 0 "$2"
}

# ---- 1. PHP tests with a database of their own ----
for t in discovery/test_device_links.php discovery/test_device_link_validation.php discovery/test_identity_normalization.php \
		discovery/test_link_replacement.php discovery/test_manual_contradiction.php \
		discovery/test_topology_model.php discovery/test_manual_device.php discovery/test_bind_interface.php discovery/test_junction.php test_contradiction_rule.php test_lazy_host_nodes.php \
		discovery/test_shared_identifier.php discovery/test_pseudo_port_index.php discovery/test_far_port_identification.php discovery/test_far_device_lookup.php discovery/test_cdp_device_key.php discovery/test_device_row.php discovery/test_ifname_local_port.php discovery/test_ports_differ.php discovery/test_json_conditions.php discovery/test_run_summary.php discovery/test_old_snapshot.php discovery/test_allowed_age.php discovery/test_port_limit.php discovery/test_port_follows_name.php discovery/test_port_alias.php discovery/test_run_input.php discovery/test_two_ports_rules.php discovery/test_remote_attrs_cap.php discovery/test_chassis_subtypes.php discovery/test_claim_key.php discovery/test_duplicate_candidates.php discovery/test_ingest_identity.php discovery/test_identity_keys.php discovery/test_normalization.php discovery/test_scenario_catalog.php discovery/test_stable_ingest.php discovery/test_linked_port_claims.php discovery/test_addresses.php discovery/test_device_last_seen.php discovery/test_decision_keys.php discovery/test_decisions.php discovery/test_steps.php; do
	selected "$t" || continue
	db=topo_t_$(basename "$t" .php | cut -c6-40)
	fresh "$db"
	php_test "$db" "$t"
	[ $failed = 0 ] && mysql -u"$user" -e "DROP DATABASE $db"
done

if selected discovery/test_snapshot_ingest.php || selected discovery/test_ingest_lock.php; then
	fresh topo_t_ingest_lock
	php_test topo_t_ingest_lock discovery/test_snapshot_ingest.php
	php_test topo_t_ingest_lock discovery/test_ingest_lock.php
fi

if selected discovery/test_manual_link_busy_port.php; then
	fresh topo_t_busy_port
	php_test topo_t_busy_port discovery/test_manual_link_busy_port.php name
fi

# ---- 2. PHP tests without a database ----
for t in test_find_device.php test_speed_mismatch.php test_freshness.php test_node_label.php test_hop_scope.php test_leaf_groups.php test_device_class.php; do
	exec_test "$t" "/tmp/topo_t_$(basename "$t" .php).log" php "$here/$t"
done

exec_test "discovery/test_closing_ticks.php" /tmp/topo_t_closing_ticks.log php "$here/discovery/test_closing_ticks.php"      # task 131: every loop of the closing stages has a tick (the source, no database, no timing)
exec_test "discovery/test_topology_type_form.php" /tmp/topo_t_type_form.log php "$here/discovery/test_topology_type_form.php"      # task 138: release 1 offers four topology types and four step sources (the form code in PHP, no browser, no database)

# ---- 3. Python and JS tests that need nothing of the lab ----
# the pure parts of the graph (ui/js/topology.graph.js: labels, sizes, collision, radial placement, parallel links, the run line), task 090
if command -v node >/dev/null; then
	exec_test "test_topology_graph.js" /tmp/topo_t_topology_graph.log node "$here/test_topology_graph.js"
else
	skip "test_topology_graph.js" "node is not installed"
fi
exec_test discovery/test_push.py /tmp/topo_t_test_push.log python3 "$here/discovery/test_push.py"
if [ -x "$repo/src/zabbix_js/zabbix_js" ]; then
	exec_test discovery/lld_js/test_lld_js.php /tmp/topo_t_test_lld_js.log php "$here/discovery/lld_js/test_lld_js.php"
else
	skip discovery/lld_js/test_lld_js.php "src/zabbix_js/zabbix_js is not built (make)"
fi

# the native step of the walk: the standalone driver, linked against the built tree
if (cd "$here/discovery/step_golden" && ./build.sh >/tmp/topo_t_step_build.log 2>&1); then
	exec_test discovery/step_golden/compare.php /tmp/topo_t_step_compare.log php "$here/discovery/step_golden/compare.php"
	exec_test discovery/step_golden/edge_cases.php /tmp/topo_t_step_edge.log php "$here/discovery/step_golden/edge_cases.php"
	# the YAML cases of the step (tests/libs/zbxpreproc/zbx_item_preproc.yaml) through the driver: the C framework needs cmocka and libyaml (handoff 071)
	if [ -x "$labpy" ]; then
		exec_test discovery/step_golden/check_yaml_cases.py /tmp/topo_t_step_yaml.log "$labpy" "$here/discovery/step_golden/check_yaml_cases.py"
	else
		skip discovery/step_golden/check_yaml_cases.py "no python with PyYAML at $labpy (LABPY)"
	fi
	# generated values through the native step, push.py and ingest (handoff 060)
	fresh topo_t_generated
	php_test topo_t_generated discovery/test_generated.php
	[ $failed = 0 ] && mysql -u"$user" -e "DROP DATABASE topo_t_generated"
else
	skip discovery/step_golden/compare.php "build.sh failed (make the server first): /tmp/topo_t_step_build.log"
	skip discovery/step_golden/edge_cases.php "build.sh failed (make the server first): /tmp/topo_t_step_build.log"
	skip discovery/test_generated.php "build.sh failed (make the server first): /tmp/topo_t_step_build.log"
fi

# ---- 4. lab unit tests and the converter of the corpus (PyYAML, mysql-connector: the lab venv) ----
if [ -x "$labpy" ]; then
	exec_test "lab/tests (unittest discover, 19 files)" /tmp/topo_t_lab_unit.log bash -c "cd '$here/lab' && '$labpy' -m unittest discover -s tests"
	exec_test corpus/test_corpus_walk.py /tmp/topo_t_corpus_walk.log bash -c "cd '$here/corpus' && '$labpy' -m unittest test_corpus_walk.py"
else
	skip "lab/tests (unittest discover)" "no python with PyYAML at $labpy (LABPY)"
	skip corpus/test_corpus_walk.py "no python with PyYAML at $labpy (LABPY)"
fi

# ---- 4a. the state of R1 at the start (task 110 section 4): a manual Device or a manual link that no check made (somebody made it by hand on the stand) is not a failure of a check. The run is
# reported as `infrastructure` with the ids, the checks that read the stand are not run, and nothing is deleted: the stand is restored from `--reset` by whoever runs it next.
# json-ok: strings compared as strings (the unquoted text on both sides), in a shell command
r1_dirty=$(mysql -N -u"$user" zabbix -e "SELECT CONCAT('manual Device ', id, ' ', IFNULL(JSON_UNQUOTE(JSON_EXTRACT(attrs, '\$.sysname')), '')) FROM topo_nodes WHERE type = 'device' AND JSON_UNQUOTE(JSON_EXTRACT(attrs, '\$.source')) = 'manual' UNION ALL SELECT CONCAT('manual link ', id) FROM topo_edges WHERE JSON_UNQUOTE(JSON_EXTRACT(attrs, '\$.discovered_via')) = 'manual' ORDER BY 1" 2>/dev/null)
if [ -n "$r1_dirty" ]; then
	printf 'R1 has manual data at the start of the run that no check made (not deleted; restore R1 from --reset):\n%s\n' "$r1_dirty" >/tmp/topo_t_r1_dirty.log
	record "R1 at the start: manual Device or link that no check made" INFRA 0 /tmp/topo_t_r1_dirty.log
	infra=$(( infra + 1 ))
fi

# ---- 5. checks on the state of the lab (the `zabbix` database, read only) ----
if [ -z "$r1_dirty" ]; then
s=$(date +%s)
php "$here/lab/php/view_scope_check.php" >/tmp/topo_t_view_scope.log 2>&1
rc=$?
if [ $rc = 2 ]; then
	skip lab/php/view_scope_check.php "the office stand hosts are not provisioned in the lab (labsim provision R1)"
else
	record lab/php/view_scope_check.php $([ $rc = 0 ] && echo PASS || echo FAIL) $(( $(date +%s) - s )) /tmp/topo_t_view_scope.log
fi
else
	skip lab/php/view_scope_check.php "R1 has manual data that no check made (INFRA above)"
fi

# ---- 6. API tests of the frontend against the lab Zabbix (they create and remove their own hosts) ----
if [ -f "$phpunit" ] && [ -f "$repo/ui/tests/bootstrap.php" ]; then
	for t in testDiscoveryRuleTopologyType testDiscoveryRuleTopologyStep testTopologyBySnmpTemplate; do
		exec_test "ui/tests/api_json/$t.php" "/tmp/topo_t_$t.log" bash -c "cd '$repo/ui/tests' && php -d include_path='.:$repo/ui' '$phpunit' --configuration phpunit.xml api_json/$t.php"
	done
else
	for t in testDiscoveryRuleTopologyType testDiscoveryRuleTopologyStep testTopologyBySnmpTemplate; do
		skip "ui/tests/api_json/$t.php" "no phpunit 8.5 phar at $phpunit (PHPUNIT) or no ui/tests/bootstrap.php"
	done
fi

# ---- 6a. the server: a DEVICE rule with more than one valid row gives an empty snapshot and the info text (LLD spec 2.19 section 3.5; makes and deletes one host) ----
if [ -n "${ZBX_TOKEN:-}" ] && [ -x "$repo/bin/zabbix_sender" ]; then
	exec_test discovery/device_rows_check.py /tmp/topo_t_device_rows.log python3 "$here/discovery/device_rows_check.py"
else
	skip discovery/device_rows_check.py "needs ZBX_TOKEN in the environment and bin/zabbix_sender"
fi

# ---- 6b. the server: the snapshot clock (C-136 to C-140, task 128 part 2; makes and deletes one host; a few minutes of waiting for the server) ----
if [ -n "${ZBX_TOKEN:-}" ] && [ -x "$repo/bin/zabbix_sender" ]; then
	exec_test discovery/test_snapshot_clock.py /tmp/topo_t_snapshot_clock.log python3 "$here/discovery/test_snapshot_clock.py"
else
	skip discovery/test_snapshot_clock.py "needs ZBX_TOKEN in the environment and bin/zabbix_sender"
fi

# ---- 7. the real page: layout and escaping (headless Chrome, logged in; the login comes from the environment) ----
if [ -n "$r1_dirty" ]; then
	skip "page checks and lab/php checks of the real page" "R1 has manual data that no check made (INFRA above)"
else
	if [ -n "${ZBX_UI_USER:-}" ] && [ -n "${ZBX_UI_PASSWORD:-}" ] && command -v google-chrome >/dev/null && [ -n "$(mysql -N -u"$user" zabbix -e "select 1 from hosts where host like 'SW12-%' limit 1" 2>/dev/null)" ]; then
		shot="$here/ui_check_040/page_shot.py"
		sw12=SW12-10-CORE-6000-48G
		page_test "page layout 1280x800, menu collapsed" /tmp/topo_t_page_1280.log python3 "$shot" --host $sw12 --depth 1 --select $sw12 --width 1280 --height 800 --menu collapsed --check layout --out /tmp/topo_t_page_1280.png
		page_test "page layout 1460x900, menu open" /tmp/topo_t_page_1460.log python3 "$shot" --host $sw12 --depth 1 --select $sw12 --width 1460 --height 900 --menu open --check layout --out /tmp/topo_t_page_1460.png
		# task 090 (model §12.1, §12.2): no node box overlaps another, at both window sizes, without a focus host (force placement) and with one (radial placement); the switch
		# "Show devices without links"; the Addresses of SW12 with the count and the folded sources
		page_test "page overlap 1500x900, nothing selected" /tmp/topo_t_overlap_1500.log python3 "$shot" --depth 1 --width 1500 --height 900 --menu open --check overlap --out /tmp/topo_t_overlap_1500.png
		page_test "page overlap 1280x800, nothing selected, menu collapsed" /tmp/topo_t_overlap_1280.log python3 "$shot" --depth 1 --width 1280 --height 800 --menu collapsed --check overlap --out /tmp/topo_t_overlap_1280.png
		page_test "page overlap 1500x900, focus SW12, depth 2 (radial)" /tmp/topo_t_overlap_radial_1500.log python3 "$shot" --host $sw12 --depth 2 --width 1500 --height 900 --menu open --check overlap --out /tmp/topo_t_overlap_radial_1500.png
		page_test "page overlap 1280x800, focus SW12, depth 2 (radial)" /tmp/topo_t_overlap_radial_1280.log python3 "$shot" --host $sw12 --depth 2 --width 1280 --height 800 --menu collapsed --check overlap --out /tmp/topo_t_overlap_radial_1280.png
		# the subject of the switch is a Device with no link at all: since the Junction projection (104) draws the three MikroTik Devices of SW18 port 6, the check makes one of its own for the
		# time of the check (a Device row with a name that no scenario uses; the next reset clears it too)
		mysql -uzabbix zabbix -e "INSERT INTO topo_nodes (type, attrs, created_at, updated_at) VALUES ('device', '{\"sysname\":\"ZZ-NO-LINK-CHECK\",\"chassis_id\":\"ZZ-NO-LINK-CHECK\"}', 0, 0)" 2>/dev/null
		page_test "page switch Show devices without links" /tmp/topo_t_unlinked.log python3 "$shot" --depth 1 --width 1500 --height 900 --menu open --check unlinked --out /tmp/topo_t_unlinked.png
		mysql -uzabbix zabbix -e "DELETE FROM topo_nodes WHERE type='device' AND attrs LIKE '%ZZ-NO-LINK-CHECK%'" 2>/dev/null
		# a Host without a Device and without a link (a throwaway host through the API): not drawn, drawn with the switch, drawn as the focus host (091)
		if [ -n "${ZBX_TOKEN:-}" ]; then
			page_test "page: a Host without a Device and without a link" /tmp/topo_t_nolink_host.log python3 "$here/ui_check_040/nolink_host.py"
		else
			skip "page: a Host without a Device and without a link" "needs ZBX_TOKEN in the environment"
		fi
		# a real mouse double click on the SW18 parent and on an unfolded leaf folds the group, the panel shows it, no zoom (091)
		page_test "page: real double click folds a group" /tmp/topo_t_dblclick.log python3 "$here/ui_check_040/dblclick_check.py"
		page_test "page: selection after Apply (focus host selected; without a focus host nothing, no panel; first load nothing)" /tmp/topo_t_selection.log python3 "$here/ui_check_040/selection_check.py"
		page_test "page: one header for the graph and the last run view (2.98: title, switch, Back, Neighbor reports, warning box, kiosk, width)" /tmp/topo_t_header.log python3 "$here/ui_check_040/header_check.py"
		page_test "page: the menu of a node name (host menu and section Topology; Device without host; neighbor name in Links)" /tmp/topo_t_menu.log python3 "$here/ui_check_040/menu_check.py"
		# the pointer 5 px beside a line hovers it; the nearest of parallel lines (the pairs of SW12, a bundle and a manual link) (092)
		page_test "page: hit area of a line (real mouse events)" /tmp/topo_t_hit.log python3 "$here/ui_check_040/hit_check.py"
		page_test "page: node panel structure, row and line hover, host menu, Focus, Unlink confirmation (real mouse events)" /tmp/topo_t_panel.log python3 "$here/ui_check_040/panel_check.py"
		# the Fold / Unfold toggle in the group and parent panels, Back in a panel opened from another panel (098)
		page_test "page: Fold / Unfold toggle and Back in the panel" /tmp/topo_t_stack.log python3 "$here/ui_check_040/stack_check.py"
		# the Junction projection (§12.6, 104): one node on the anchor Port, lines to the Devices behind it, its read-only panel, Back, the selection ring
		page_test "page: Junction projection (node, lines, panel, Back)" /tmp/topo_t_junction_page.log python3 "$here/ui_check_040/junction_check.py"
		# a manual Device: the create dialog, the panel, a manual port, a link, edit, delete (099; the database login of the harness, `mysql -uzabbix zabbix`)
		page_test "page: manual Device (create, port, link, edit, delete; real mouse and keyboard events)" /tmp/topo_t_manual_device.log python3 "$here/ui_check_040/manual_device_check.py"
		# Bind to interface (107): create, link, Promote to a lab host, Bind / Unbind with real mouse events, the load of the interface, the refusal, Depromote, Delete; nothing is left
		page_test "page: Bind to interface (dialog, bound port, load, refusal, Unbind)" /tmp/topo_t_bind_page.log python3 "$here/ui_check_040/bind_check.py"
		# Find a device (task 119 section 2, model 2.100 §12.1): name, MAC, IP, the limit, a hidden Device found and shown, the filter unchanged
		page_test "page: Find a device (name, MAC in all forms, IP, markup, the limit; a hidden Device drawn, selected, panned to; the filter does not change)" /tmp/topo_t_find.log python3 "$here/ui_check_040/find_check.py"
		# the mark "speed mismatch" and the port alias (task 119 sections 3 and 4, model 2.100 §12.3 and §12.5): tooltip, link panel, Links row, Ports section, escaping, the cut
		page_test "page: speed mismatch mark and port alias (tooltip, link panel, Links row, Ports section; escaped; the alias is cut, the port name is not)" /tmp/topo_t_speed_alias.log python3 "$here/ui_check_040/speed_alias_check.py"
		# the warning mark on the line of a speed mismatch (task 120, model 2.101 §12.3): visible with no hover, a click, the bundle, no overlap after the layout, an unfold and a drag, the legend row, the short mark of the Links row
		page_test "page: warning mark of a speed mismatch (line, bundle, click, overlap after layout, unfold and drag, legend, Links row)" /tmp/topo_t_speed_mark.log python3 "$here/ui_check_040/speed_mark_check.py"
		page_test "page: the badge of a contradicted link opens the line menu" /tmp/topo_t_badge.log python3 "$here/ui_check_040/badge_check.py"
		page_test "page: a click on a group node unfolds it in 500 ms with no task over 200 ms, once (states after Reset)" /tmp/topo_t_unfold_time.log python3 "$here/ui_check_040/unfold_time_check.py"
		page_test "page: Last run, part Snapshots (the states fresh, old, not_used, none, the ignored value; the API and the page, own host)" /tmp/topo_t_snapshots.log python3 "$here/ui_check_040/snapshots_check.py"
		# the row contract on the server and on the frontend side, the same table (task 128 part 1, model §5.2): trapper rules, zabbix_sender, snapshots.get and the info text; CTopologyType::checkRows
		page_test "page: row contract (28 cases through trapper rules and CTopologyType::checkRows, own host)" /tmp/topo_t_row_contract.log python3 "$here/ui_check_040/row_contract_check.py"
		page_test "page: Last run, observations local_port_unresolved (the row, its port reference and reason, the filter by the result)" /tmp/topo_t_unresolved.log python3 "$here/ui_check_040/unresolved_check.py"
		page_test "page: Bind to interface by name (a host of the agent template: no index, load, refusal, Unbind)" /tmp/topo_t_bind_name_page.log python3 "$here/ui_check_040/bind_check.py" --by-name
	# the load of the side that has items, "no data" and the reason for the side that has none (110, FR-C V-12)
	page_test "page: a link to an end device: the load of one side, no data on the other (real mouse events)" /tmp/topo_t_nodata.log python3 "$here/ui_check_040/no_data_check.py"
		# the first layout is the same at every load: 10 loads at each size, positions equal within 1 px, the overlap check passes every time (101)
		page_test "page: deterministic first layout (10 + 10 loads)" /tmp/topo_t_determinism.log python3 "$here/ui_check_040/determinism_check.py"
		# the time budget of the node panel: SW12 is complete within 300 ms on the quiet VM, the goal 200 ms is printed (a note on a loaded one) (102, 105)
		page_test "page: the panel of SW12 is complete within 300 ms (median of 5 clicks; goal 200 ms)" /tmp/topo_t_panel_time.log python3 "$here/ui_check_040/panel_time_check.py"
		# Fit puts the whole graph in the window; the leaves of an unfolded group are on an arc (092)
		page_test "page: Fit 1500x900" /tmp/topo_t_fit_1500.log python3 "$shot" --depth 1 --width 1500 --height 900 --menu open --check fit --out /tmp/topo_t_fit_1500.png
		page_test "page: Fit 1280x800, menu collapsed" /tmp/topo_t_fit_1280.log python3 "$shot" --depth 1 --width 1280 --height 800 --menu collapsed --check fit --out /tmp/topo_t_fit_1280.png
		page_test "page: the leaves of SW18 on an arc" /tmp/topo_t_arc.log python3 "$shot" --depth 1 --width 1500 --height 900 --menu open --check arc --out /tmp/topo_t_arc.png
		page_test "page Addresses of SW12 (count, folded sources)" /tmp/topo_t_addr_sw12.log python3 "$shot" --host $sw12 --depth 1 --eval "(async () => { await view.selectNode(Array.from(view.nodes.values()).find(n => n.type === 'device' && n.label === '$sw12')); document.querySelectorAll('#topology-details details.topology-identity, #topology-details details.topology-addresses').forEach(d => { d.open = true; }); return 1; })()" --width 1500 --height 900 --menu open --check addresses --out /tmp/topo_t_addr_sw12.png
		page_test "page escaping (labels and cells)" /tmp/topo_t_page_escape.log python3 "$shot" --host $sw12 --depth 1 --select $sw12 --width 1460 --height 900 --check escape --out /tmp/topo_t_page_escape.png
		# the menu item, the last run view and its links, started_by of a run started from the page, the new filters of topology.observations.get, a user that is not a Super admin (076)
		exec_test "lab/php/lastrun_http_check.php" /tmp/topo_t_lastrun_http.log php "$here/lab/php/lastrun_http_check.php"
		# the UI only requests a run, the CLI executes it; executor_missing; a write action waits for the run lock; accept discovery (087)
		# the page header and filter bar, parallel links, leaf groups (090)
		exec_test "lab/php/graph_page_check.php" /tmp/topo_t_graph_page_http.log php "$here/lab/php/graph_page_check.php"
		exec_test "lab/php/run_row_http_check.php" /tmp/topo_t_run_row_http.log php "$here/lab/php/run_row_http_check.php"
		page_test "page last run view 1500x1100, menu open" /tmp/topo_t_lastrun_1500.log python3 "$shot" --action "topology.view&view=lastrun" --width 1500 --height 1100 --menu open --check lastrun --out /tmp/topo_t_lastrun_1500.png
		page_test "page last run view 1280x1100, menu collapsed" /tmp/topo_t_lastrun_1280.log python3 "$shot" --action "topology.view&view=lastrun" --width 1280 --height 1100 --menu collapsed --check lastrun --out /tmp/topo_t_lastrun_1280.png
		# the section Addresses of a Device without association (051): the office stand's SW15 and its neighbor NA12502000654
		if [ -n "$(mysql -N -u"$user" zabbix -e "select 1 from hosts where host = 'SW15-10-POE-6000-24G' limit 1" 2>/dev/null)" ]; then
			page_test "page Addresses labels 1280x800, menu collapsed" /tmp/topo_t_addr_1280.log python3 "$shot" --host SW15-10-POE-6000-24G --depth 1 --select NA12502000654 --width 1280 --height 800 --menu collapsed --check addresses --out /tmp/topo_t_addr_1280.png
			page_test "page Addresses labels 1460x900, menu open" /tmp/topo_t_addr_1460.log python3 "$shot" --host SW15-10-POE-6000-24G --depth 1 --select NA12502000654 --width 1460 --height 900 --menu open --check addresses --out /tmp/topo_t_addr_1460.png
		else
			skip "page Addresses labels (ui_check_040/page_shot.py --check addresses)" "the office host SW15-10-POE-6000-24G is not in the lab"
		fi
		# the duplicate panel and the filter "possible duplicate" (057): the MikroTik Devices of SW18 share the address 192.168.88.1
		if [ -n "$(mysql -N -u"$user" zabbix -e "select 1 from topo_nodes where type = 'device' and attrs like '%same_address%' limit 1" 2>/dev/null)" ] && [ -n "$(mysql -N -u"$user" zabbix -e "select 1 from hosts where host = 'SW19-09-POE-6000-24G' limit 1" 2>/dev/null)" ]; then
			page_test "page duplicate panel and filter 1460x900, menu open" /tmp/topo_t_dup_1460.log python3 "$shot" --depth "" --select MikroTik --width 1460 --height 900 --menu open --check duplicates --out /tmp/topo_t_dup_1460.png
		else
			skip "page duplicate panel and filter (ui_check_040/page_shot.py --check duplicates)" "no Device with a same_address candidate in the lab (run the scenario R1 first) or the office host SW19 is missing"
		fi
	else
		skip "page layout and escaping (ui_check_040/page_shot.py)" "needs ZBX_UI_USER and ZBX_UI_PASSWORD in the environment, google-chrome, and the office stand host SW12 in the lab"
	fi
fi

# ---- 8. the path through a Zabbix proxy and what the blind-spot badge reads (task 128 parts 3 and 4; model §6.7.0, §4.8). Not in a default run: it runs ingest on R1 after three reporters moved to the proxy, so R1 must be restored after it
#         (a new Device for every CDP-only neighbor of those reporters, in the segment of the proxy). Set LAB_PROXY=1; the proxy is made once with lab/setup/lab-proxy.sh create and started and stopped by the check ----
if [ "${LAB_PROXY:-}" = 1 ]; then
	if [ -n "${ZBX_TOKEN:-}" ] && [ -x "$here/../../src/zabbix_proxy/zabbix_proxy" ] && [ -x "$labpy" ]; then
		exec_test lab/proxy_check.py /tmp/topo_t_proxy_check.log "$labpy" "$here/lab/proxy_check.py"
	else
		skip lab/proxy_check.py "needs ZBX_TOKEN, the built src/zabbix_proxy/zabbix_proxy and the lab python"
	fi
else
	skip lab/proxy_check.py "LAB_PROXY=1 is not set (it changes R1: restore R1 after it)"
fi

# ---- the table ----
echo
printf '%-62s %-5s %7s %7s\n' test result checks seconds
awk -F'|' '{ printf "%-62s %-5s %7s %7s\n", $1, $2, ($2 == "SKIP" ? "-" : $3), ($2 == "SKIP" ? "-" : $4) }' "$results"
awk -F'|' '$2 == "PASS" { p++; c += $3 } $2 == "FAIL" { f++ } $2 == "SKIP" { s++ } $2 == "INFRA" { i++ } END { printf "\n%d passed (%d checks), %d failed, %d skipped, infrastructure: %d; duration %d s\n", p, c, f, s, i, 0 }' "$results" | sed "s/duration 0 s/duration $(( $(date +%s) - start_all )) s/"
exit $failed
