#!/bin/bash
# The common run of every topology test that can run on the lab VM (task 046): the PHP tests (each in its own freshly created MySQL database, in the order the tests document:
# test_ingest_lock.php needs the stand-in schema that test_snapshot_ingest.php leaves, and most tests drop and recreate the tables they use; one shared database makes them fail for
# reasons that are not in the code), then the Python tests, the JS rule tests, the step golden tests, the lab unit tests, the checks on the lab state and the API tests of the frontend.
# The list of what is NOT in this run, and why, is in README-tests.md next to this file.
#
# Usage: run_php_tests.sh <database user> [password]     (MySQL on localhost; creates and drops topo_t_* databases)
#   LABPY=<python with PyYAML and mysql-connector>   default ~/work/labenv/bin/python (lab unit tests, corpus converter test)
#   PHPUNIT=<phpunit 8.5 phar>                       default ~/work/tools/phpunit-8.5.phar (the API tests; skipped when absent)
#   ZBX_UI_USER, ZBX_UI_PASSWORD                     the login of the frontend for the checks of the real page (skipped when not set; never written to a file)
# Exit status 0 when no test failed. A test that needs a state the lab does not have is SKIPPED with its reason (it does not fail the run and is not hidden: see the table).
set -u
user=${1:?usage: $0 <database user> [password]}
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
		discovery/test_topology_model.php discovery/test_manual_device.php discovery/test_junction.php test_contradiction_rule.php test_lazy_host_nodes.php \
		discovery/test_shared_identifier.php discovery/test_pseudo_port_index.php discovery/test_far_port_identification.php discovery/test_far_device_lookup.php discovery/test_cdp_device_key.php discovery/test_run_summary.php discovery/test_old_snapshot.php discovery/test_port_limit.php discovery/test_port_follows_name.php discovery/test_run_input.php discovery/test_two_ports_rules.php discovery/test_remote_attrs_cap.php discovery/test_chassis_subtypes.php discovery/test_claim_key.php discovery/test_duplicate_candidates.php discovery/test_ingest_identity.php discovery/test_identity_keys.php discovery/test_normalization.php discovery/test_scenario_catalog.php discovery/test_stable_ingest.php discovery/test_linked_port_claims.php discovery/test_addresses.php discovery/test_device_last_seen.php discovery/test_steps.php; do
	db=topo_t_$(basename "$t" .php | cut -c6-40)
	fresh "$db"
	php_test "$db" "$t"
	[ $failed = 0 ] && mysql -u"$user" -e "DROP DATABASE $db"
done

fresh topo_t_ingest_lock
php_test topo_t_ingest_lock discovery/test_snapshot_ingest.php
php_test topo_t_ingest_lock discovery/test_ingest_lock.php

fresh topo_t_busy_port
php_test topo_t_busy_port discovery/test_manual_link_busy_port.php name

# ---- 2. PHP tests without a database ----
for t in test_freshness.php test_node_label.php test_hop_scope.php test_leaf_groups.php test_device_class.php; do
	exec_test "$t" "/tmp/topo_t_$(basename "$t" .php).log" php "$here/$t"
done

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
	exec_test "lab/tests (unittest discover, 14 files)" /tmp/topo_t_lab_unit.log bash -c "cd '$here/lab' && '$labpy' -m unittest discover -s tests"
	exec_test corpus/test_corpus_walk.py /tmp/topo_t_corpus_walk.log bash -c "cd '$here/corpus' && '$labpy' -m unittest test_corpus_walk.py"
else
	skip "lab/tests (unittest discover)" "no python with PyYAML at $labpy (LABPY)"
	skip corpus/test_corpus_walk.py "no python with PyYAML at $labpy (LABPY)"
fi

# ---- 5. checks on the state of the lab (the `zabbix` database, read only) ----
lab_host=$(mysql -N -u"$user" zabbix -e "select h.host from hosts h join items i on i.hostid=h.hostid join topo_lld_snapshot s on s.itemid=i.itemid where i.key_='net.if.discovery' order by h.hostid limit 1" 2>/dev/null)
if [ -n "$lab_host" ]; then
	exec_test "discovery/test_port_itemids.py ($lab_host)" /tmp/topo_t_port_itemids.log python3 "$here/discovery/test_port_itemids.py" "$lab_host"
else
	skip discovery/test_port_itemids.py "no host with the rule net.if.discovery and a snapshot in the lab (provision a scenario and run it)"
fi
s=$(date +%s)
php "$here/lab/php/view_scope_check.php" >/tmp/topo_t_view_scope.log 2>&1
rc=$?
if [ $rc = 2 ]; then
	skip lab/php/view_scope_check.php "the office stand hosts are not provisioned in the lab (labsim provision R1)"
else
	record lab/php/view_scope_check.php $([ $rc = 0 ] && echo PASS || echo FAIL) $(( $(date +%s) - s )) /tmp/topo_t_view_scope.log
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

# ---- 7. the real page: layout and escaping (headless Chrome, logged in; the login comes from the environment) ----
if [ -n "${ZBX_UI_USER:-}" ] && [ -n "${ZBX_UI_PASSWORD:-}" ] && command -v google-chrome >/dev/null && [ -n "$(mysql -N -u"$user" zabbix -e "select 1 from hosts where host like 'SW12-%' limit 1" 2>/dev/null)" ]; then
	shot="$here/ui_check_040/page_shot.py"
	sw12=SW12-10-CORE-6000-48G
	page_test "page layout 1280x800, menu collapsed" /tmp/topo_t_page_1280.log python3 "$shot" --host $sw12 --depth 1 --select $sw12 --width 1280 --height 800 --menu collapsed --check layout --out /tmp/topo_t_page_1280.png
	page_test "page layout 1460x900, menu open" /tmp/topo_t_page_1460.log python3 "$shot" --host $sw12 --depth 1 --select $sw12 --width 1460 --height 900 --menu open --check layout --out /tmp/topo_t_page_1460.png
	# task 090 (model §12.1, §12.2): no node box overlaps another, at both window sizes, without a focus host (force placement) and with one (radial placement); the switch
	# "Show devices without links"; the Addresses of SW12 with the count and the folded sources
	page_test "page overlap 1500x900, nothing selected" /tmp/topo_t_overlap_1500.log python3 "$shot" --depth 1 --width 1500 --height 900 --menu open --settle 2 --check overlap --out /tmp/topo_t_overlap_1500.png
	page_test "page overlap 1280x800, nothing selected, menu collapsed" /tmp/topo_t_overlap_1280.log python3 "$shot" --depth 1 --width 1280 --height 800 --menu collapsed --settle 2 --check overlap --out /tmp/topo_t_overlap_1280.png
	page_test "page overlap 1500x900, focus SW12, depth 2 (radial)" /tmp/topo_t_overlap_radial_1500.log python3 "$shot" --host $sw12 --depth 2 --width 1500 --height 900 --menu open --settle 2 --check overlap --out /tmp/topo_t_overlap_radial_1500.png
	page_test "page overlap 1280x800, focus SW12, depth 2 (radial)" /tmp/topo_t_overlap_radial_1280.log python3 "$shot" --host $sw12 --depth 2 --width 1280 --height 800 --menu collapsed --settle 2 --check overlap --out /tmp/topo_t_overlap_radial_1280.png
	# the subject of the switch is a Device with no link at all: since the Junction projection (104) draws the three MikroTik Devices of SW18 port 6, the check makes one of its own for the
	# time of the check (a Device row with a name that no scenario uses; the next reset clears it too)
	mysql -uzabbix zabbix -e "INSERT INTO topo_nodes (type, attrs, created_at, updated_at) VALUES ('device', '{\"sysname\":\"ZZ-NO-LINK-CHECK\",\"chassis_id\":\"ZZ-NO-LINK-CHECK\"}', 0, 0)" 2>/dev/null
	page_test "page switch Show devices without links" /tmp/topo_t_unlinked.log python3 "$shot" --depth 1 --width 1500 --height 900 --menu open --settle 2 --check unlinked --out /tmp/topo_t_unlinked.png
	mysql -uzabbix zabbix -e "DELETE FROM topo_nodes WHERE type='device' AND attrs LIKE '%ZZ-NO-LINK-CHECK%'" 2>/dev/null
	# a Host without a Device and without a link (a throwaway host through the API): not drawn, drawn with the switch, drawn as the focus host (091)
	if [ -n "${ZBX_TOKEN:-}" ]; then
		page_test "page: a Host without a Device and without a link" /tmp/topo_t_nolink_host.log python3 "$here/ui_check_040/nolink_host.py"
	else
		skip "page: a Host without a Device and without a link" "needs ZBX_TOKEN in the environment"
	fi
	# a real mouse double click on the SW18 parent and on an unfolded leaf folds the group, the panel shows it, no zoom (091)
	page_test "page: real double click folds a group" /tmp/topo_t_dblclick.log python3 "$here/ui_check_040/dblclick_check.py"
	# the pointer 5 px beside a line hovers it; the nearest of parallel lines (the pairs of SW12, a bundle and a manual link) (092)
	page_test "page: hit area of a line (real mouse events)" /tmp/topo_t_hit.log python3 "$here/ui_check_040/hit_check.py"
	page_test "page: node panel structure, row and line hover, host menu, Focus, Unlink confirmation (real mouse events)" /tmp/topo_t_panel.log python3 "$here/ui_check_040/panel_check.py"
	# the Fold / Unfold toggle in the group and parent panels, Back in a panel opened from another panel (098)
	page_test "page: Fold / Unfold toggle and Back in the panel" /tmp/topo_t_stack.log python3 "$here/ui_check_040/stack_check.py"
	# the Junction projection (§12.6, 104): one node on the anchor Port, lines to the Devices behind it, its read-only panel, Back, the selection ring
	page_test "page: Junction projection (node, lines, panel, Back)" /tmp/topo_t_junction_page.log python3 "$here/ui_check_040/junction_check.py"
	# a manual Device: the create dialog, the panel, a manual port, a link, edit, delete (099; the database login of the harness, `mysql -uzabbix zabbix`)
	page_test "page: manual Device (create, port, link, edit, delete; real mouse and keyboard events)" /tmp/topo_t_manual_device.log python3 "$here/ui_check_040/manual_device_check.py"
	# the first layout is the same at every load: 10 loads at each size, positions equal within 1 px, the overlap check passes every time (101)
	page_test "page: deterministic first layout (10 + 10 loads)" /tmp/topo_t_determinism.log python3 "$here/ui_check_040/determinism_check.py"
	# the time budget of the node panel: SW12 is complete within 300 ms on the quiet VM (a note on a loaded one) (102)
	page_test "page: the panel of SW12 is complete within 200 ms (median of 5 clicks)" /tmp/topo_t_panel_time.log python3 "$here/ui_check_040/panel_time_check.py"
	# Fit puts the whole graph in the window; the leaves of an unfolded group are on an arc (092)
	page_test "page: Fit 1500x900" /tmp/topo_t_fit_1500.log python3 "$shot" --depth 1 --width 1500 --height 900 --menu open --settle 2 --check fit --out /tmp/topo_t_fit_1500.png
	page_test "page: Fit 1280x800, menu collapsed" /tmp/topo_t_fit_1280.log python3 "$shot" --depth 1 --width 1280 --height 800 --menu collapsed --settle 2 --check fit --out /tmp/topo_t_fit_1280.png
	page_test "page: the leaves of SW18 on an arc" /tmp/topo_t_arc.log python3 "$shot" --depth 1 --width 1500 --height 900 --menu open --settle 2 --check arc --out /tmp/topo_t_arc.png
	page_test "page Addresses of SW12 (count, folded sources)" /tmp/topo_t_addr_sw12.log python3 "$shot" --host $sw12 --depth 1 --eval "(async () => { await view.selectNode(Array.from(view.nodes.values()).find(n => n.type === 'device' && n.label === '$sw12')); document.querySelectorAll('#topology-details details.topology-identity, #topology-details details.topology-addresses').forEach(d => { d.open = true; }); return 1; })()" --width 1500 --height 900 --menu open --settle 2 --check addresses --out /tmp/topo_t_addr_sw12.png
	page_test "page escaping (labels and cells)" /tmp/topo_t_page_escape.log python3 "$shot" --host $sw12 --depth 1 --select $sw12 --width 1460 --height 900 --check escape --out /tmp/topo_t_page_escape.png
	# the menu item, the last run view and its links, started_by of a run started from the page, the new filters of topology.observations.get, a user that is not a Super admin (076)
	exec_test "lab/php/lastrun_http_check.php" /tmp/topo_t_lastrun_http.log php "$here/lab/php/lastrun_http_check.php"
	# the UI only requests a run, the CLI executes it; executor_missing; a write action waits for the run lock; accept discovery (087)
	# the page header and filter bar, parallel links, leaf groups (090)
	exec_test "lab/php/graph_page_check.php" /tmp/topo_t_graph_page_http.log php "$here/lab/php/graph_page_check.php"
	exec_test "lab/php/run_row_http_check.php" /tmp/topo_t_run_row_http.log php "$here/lab/php/run_row_http_check.php"
	page_test "page last run view 1500x1100, menu open" /tmp/topo_t_lastrun_1500.log python3 "$shot" --action topology.lastrun --width 1500 --height 1100 --menu open --settle 1 --check lastrun --out /tmp/topo_t_lastrun_1500.png
	page_test "page last run view 1280x1100, menu collapsed" /tmp/topo_t_lastrun_1280.log python3 "$shot" --action topology.lastrun --width 1280 --height 1100 --menu collapsed --settle 1 --check lastrun --out /tmp/topo_t_lastrun_1280.png
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

# ---- the table ----
echo
printf '%-62s %-5s %7s %7s\n' test result checks seconds
awk -F'|' '{ printf "%-62s %-5s %7s %7s\n", $1, $2, ($2 == "SKIP" ? "-" : $3), ($2 == "SKIP" ? "-" : $4) }' "$results"
awk -F'|' '$2 == "PASS" { p++; c += $3 } $2 == "FAIL" { f++ } $2 == "SKIP" { s++ } $2 == "INFRA" { i++ } END { printf "\n%d passed (%d checks), %d failed, %d skipped, infrastructure: %d; duration %d s\n", p, c, f, s, i, 0 }' "$results" | sed "s/duration 0 s/duration $(( $(date +%s) - start_all )) s/"
exit $failed
