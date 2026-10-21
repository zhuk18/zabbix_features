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
trap 'rm -f "$results"' EXIT
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
		discovery/test_topology_model.php test_contradiction_rule.php test_lazy_host_nodes.php \
		discovery/test_shared_identifier.php discovery/test_pseudo_port_index.php discovery/test_far_port_identification.php discovery/test_join_identity.php discovery/test_duplicate_candidates.php discovery/test_ingest_identity.php discovery/test_identity_keys.php discovery/test_normalization.php discovery/test_scenario_catalog.php discovery/test_stable_ingest.php discovery/test_linked_port_claims.php discovery/test_addresses.php; do
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
for t in test_freshness.php test_node_label.php test_hop_scope.php; do
	exec_test "$t" "/tmp/topo_t_$(basename "$t" .php).log" php "$here/$t"
done

# ---- 3. Python and JS tests that need nothing of the lab ----
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
else
	skip discovery/step_golden/compare.php "build.sh failed (make the server first): /tmp/topo_t_step_build.log"
	skip discovery/step_golden/edge_cases.php "build.sh failed (make the server first): /tmp/topo_t_step_build.log"
fi

# ---- 4. lab unit tests and the converter of the corpus (PyYAML, mysql-connector: the lab venv) ----
if [ -x "$labpy" ]; then
	exec_test "lab/tests (unittest discover, 13 files)" /tmp/topo_t_lab_unit.log bash -c "cd '$here/lab' && '$labpy' -m unittest discover -s tests"
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
	exec_test "page layout 1280x800, menu collapsed" /tmp/topo_t_page_1280.log python3 "$shot" --host $sw12 --depth 1 --select $sw12 --width 1280 --height 800 --menu collapsed --check layout --out /tmp/topo_t_page_1280.png
	exec_test "page layout 1460x900, menu open" /tmp/topo_t_page_1460.log python3 "$shot" --host $sw12 --depth 1 --select $sw12 --width 1460 --height 900 --menu open --check layout --out /tmp/topo_t_page_1460.png
	exec_test "page escaping (labels and cells)" /tmp/topo_t_page_escape.log python3 "$shot" --host $sw12 --depth 1 --select $sw12 --width 1460 --height 900 --check escape --out /tmp/topo_t_page_escape.png
else
	skip "page layout and escaping (ui_check_040/page_shot.py)" "needs ZBX_UI_USER and ZBX_UI_PASSWORD in the environment, google-chrome, and the office stand host SW12 in the lab"
fi

# ---- the table ----
echo
printf '%-62s %-5s %7s %7s\n' test result checks seconds
awk -F'|' '{ printf "%-62s %-5s %7s %7s\n", $1, $2, ($2 == "SKIP" ? "-" : $3), ($2 == "SKIP" ? "-" : $4) }' "$results"
awk -F'|' '$2 == "PASS" { p++; c += $3 } $2 == "FAIL" { f++ } $2 == "SKIP" { s++ } END { printf "\n%d passed (%d checks), %d failed, %d skipped; duration %d s\n", p, c, f, s, 0 }' "$results" | sed "s/duration 0 s/duration $(( $(date +%s) - start_all )) s/"
exit $failed
