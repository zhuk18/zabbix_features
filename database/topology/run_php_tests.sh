#!/bin/sh
# Runs every PHP test of the topology code, each in its own freshly created MySQL database, in the order the tests document:
# test_ingest_lock.php needs the stand-in schema that test_snapshot_ingest.php leaves, test_reconciliation_scenarios.php
# needs an EMPTY database, and most tests drop and recreate the tables they use. One shared database makes them fail
# for reasons that are not in the code.
#
# Usage: run_php_tests.sh <database user> [password]     (MySQL on localhost; creates and drops topo_t_* databases)
set -u
user=${1:?usage: $0 <database user> [password]}
password=${2:-}
here=$(cd "$(dirname "$0")" && pwd)
export MYSQL_PWD=$password
failed=0

fresh() {
	mysql -u"$user" -e "DROP DATABASE IF EXISTS $1; CREATE DATABASE $1" || exit 2
}

run() {      # run <database> <test> [dsn|name]
	db=$1; test=$2; style=${3:-dsn}
	if [ "$style" = dsn ]; then
		arg="mysql:host=localhost;dbname=$db;charset=utf8mb4"
	else
		arg=$db
	fi
	if php "$here/$test" "$arg" "$user" "$password" >"/tmp/$db.log" 2>&1; then
		echo "pass  $test"
	else
		echo "FAIL  $test   (/tmp/$db.log)"
		failed=1
	fi
}

for t in discovery/test_device_links.php discovery/test_device_link_validation.php discovery/test_identity_normalization.php \
		discovery/test_link_replacement.php discovery/test_manual_contradiction.php discovery/test_reconciliation_scenarios.php \
		discovery/test_topology_model.php test_contradiction_rule.php test_lazy_host_nodes.php \
		discovery/test_shared_identifier.php discovery/test_pseudo_port_index.php discovery/test_far_port_identification.php discovery/test_join_identity.php discovery/test_duplicate_candidates.php discovery/test_ingest_identity.php discovery/test_identity_keys.php discovery/test_normalization.php discovery/test_scenario_catalog.php; do
	db=topo_t_$(basename "$t" .php | cut -c6-40)
	fresh "$db"
	run "$db" "$t"
	[ $failed = 0 ] && mysql -u"$user" -e "DROP DATABASE $db"
done

fresh topo_t_ingest_lock
run topo_t_ingest_lock discovery/test_snapshot_ingest.php
run topo_t_ingest_lock discovery/test_ingest_lock.php

fresh topo_t_busy_port
run topo_t_busy_port discovery/test_manual_link_busy_port.php name

php "$here/test_freshness.php" >/tmp/topo_t_freshness.log 2>&1 && echo "pass  test_freshness.php" || { echo "FAIL  test_freshness.php"; failed=1; }
exit $failed
