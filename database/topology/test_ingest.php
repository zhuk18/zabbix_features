#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * test_ingest.php — regression tests for database/topology/discovery/ingest.php's tag
 * read-modify-write (topology-tags-model-spec.md §14.1/§15).
 *
 * Drives apply_ingest_blob()/resolve_port_label() directly via ingest.php's
 * TOPOLOGY_INGEST_TEST_HOOK (see that file's header) against a real fixture Host, going
 * through the actual Zabbix API (host.get/host.update) exactly like a live ingest run would,
 * but skipping item.get/history.get/the file lock. Always leaves the fixture Host with no
 * topology.* tags when it finishes.
 *
 * Covers:
 *   - A first ingest run writes chassis_id, port.* and neighbor.* from the blob.
 *   - A second run with a smaller blob drops the tags that are no longer present (§15's
 *     "replace the snapshot", §14.1 step 4's "drop every previous port.* / neighbor.* tag not
 *     present in the new blob").
 *   - topology.identity and an unrelated non-topology tag both survive an ingest run untouched
 *     (§14.1's namespace-ownership split).
 *   - A neighbor entry with no remote_chassis_id is not written (§9's minimum required
 *     properties).
 *
 * Usage: test_ingest.php
 */

chdir(__DIR__.'/../../ui');
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
require_once 'include/classes/core/APP.php';
APP::getInstance()->run(APP::EXEC_MODE_API);
API::getWrapper()->auth = ['type' => 0];

$userdata = [
	'userid' => '1', 'username' => 'Admin', 'name' => '', 'surname' => '', 'url' => '',
	'autologin' => 0, 'autologout' => '15m', 'lang' => 'en_US', 'refresh' => '30s',
	'theme' => 'default', 'attempt_failed' => 0, 'attempt_ip' => '', 'attempt_clock' => 0,
	'rows_per_page' => 50, 'roleid' => 3, 'userdirectoryid' => null, 'type' => USER_TYPE_SUPER_ADMIN,
	'userip' => '127.0.0.1', 'sessionid' => 'test', 'gui_access' => GROUP_GUI_ACCESS_SYSTEM,
	'debug_mode' => false, 'mfaid' => 0, 'timezone' => 'default'
];
CWebUser::$data = $userdata;
CApiService::$userData = $userdata;

$fail = false;

function check(bool $condition, string $description): void {
	global $fail;
	echo ($condition ? '  ok: ' : '  FAIL: ').$description."\n";
	if (!$condition) {
		$fail = true;
	}
}

function set_tags(string $hostid, array $tags): void {
	API::Host()->update([['hostid' => $hostid, 'tags' => $tags]]);
}

function get_tags(string $hostid): array {
	$hosts = API::Host()->get(['hostids' => [$hostid], 'output' => [], 'selectTags' => 'extend']);
	$map = [];
	foreach ($hosts[0]['tags'] as $tag) {
		$map[$tag['tag']] = $tag['value'];
	}
	return $map;
}

$hosts = API::Host()->get(['output' => ['hostid', 'name'], 'limit' => 1]);
if (!$hosts) {
	fwrite(STDERR, "SKIP: need at least 1 Zabbix host in this instance to run this test.\n");
	exit(0);
}
$hostid = $hosts[0]['hostid'];
echo "Using Host #{$hostid} ({$hosts[0]['name']})\n";

set_tags($hostid, []);

// apply_ingest_blob() is a top-level function defined by ingest.php (not a closure captured in
// $scope) — called directly by name below. $scope (ingest.php's get_defined_vars() at the point
// the hook fires) isn't needed here; it exists for hooks that do need to reach into ingest.php's
// local closures/variables.
function topology_ingest_test_hook(array $scope): void {
	global $hostid, $fail;

	echo "\n=== First ingest: chassis_id + 2 ports + 1 neighbor ===\n";
	set_tags($hostid, [
		['tag' => 'topology.identity', 'value' => 'MANUAL-ID'],
		['tag' => 'env', 'value' => 'lab']
	]);
	apply_ingest_blob($hostid, [
		'reporter' => ['chassis_id' => 'AA:AA:AA:AA:AA:AA'],
		'ports' => [
			['if_index' => 24, 'name' => 'Gi0/24', 'if_type' => 'physical', 'mac' => '00:11:22:33:44:24', 'oper_status' => 'up'],
			['if_index' => 25, 'name' => 'Gi0/25', 'if_type' => 'physical', 'oper_status' => 'down']
		],
		'neighbors' => [
			['local_if_index' => 24, 'remote_chassis_id' => 'BB:BB:BB:BB:BB:BB', 'remote_port_desc' => 'Gi0/1', 'remote_sysname' => 'Switch2'],
			// No remote_chassis_id: §9 minimum required property missing, must be skipped entirely.
			['local_if_index' => 25, 'remote_sysname' => 'Unresolved']
		]
	]);
	$tags = get_tags($hostid);
	check(($tags['topology.chassis_id'] ?? null) === 'AA:AA:AA:AA:AA:AA', 'chassis_id written');
	check(($tags['topology.port.24.name'] ?? null) === 'Gi0/24', 'port 24 name written');
	check(($tags['topology.port.24.mac'] ?? null) === '00:11:22:33:44:24', 'port 24 mac written');
	check(($tags['topology.port.24.status'] ?? null) === 'up', 'port 24 status written');
	check(($tags['topology.port.25.name'] ?? null) === 'Gi0/25', 'port 25 name written');
	check(($tags['topology.neighbor.24.chassis_id'] ?? null) === 'BB:BB:BB:BB:BB:BB', 'neighbor 24 chassis_id written');
	check(($tags['topology.neighbor.24.port'] ?? null) === 'Gi0/1', 'neighbor 24 port written');
	check(($tags['topology.neighbor.24.name'] ?? null) === 'Switch2', 'neighbor 24 name written');
	check(!isset($tags['topology.neighbor.25.chassis_id']), 'unresolved neighbor (no remote_chassis_id) not written');
	check(($tags['topology.identity'] ?? null) === 'MANUAL-ID', 'topology.identity survives ingest untouched');
	check(($tags['env'] ?? null) === 'lab', 'non-topology tag survives ingest untouched');

	echo "\n=== Second ingest: smaller blob drops stale port/neighbor tags (§15) ===\n";
	apply_ingest_blob($hostid, [
		'reporter' => ['chassis_id' => 'AA:AA:AA:AA:AA:AA'],
		'ports' => [
			['if_index' => 24, 'name' => 'Gi0/24', 'if_type' => 'physical', 'oper_status' => 'up']
		],
		'neighbors' => []
	]);
	$tags = get_tags($hostid);
	check(($tags['topology.chassis_id'] ?? null) === 'AA:AA:AA:AA:AA:AA', 'chassis_id still present after second run');
	check(($tags['topology.port.24.name'] ?? null) === 'Gi0/24', 'port 24 still present after second run');
	check(!isset($tags['topology.port.25.name']), 'port 25 dropped — no longer in the new blob (§15 replace-the-snapshot)');
	check(!isset($tags['topology.port.24.mac']), 'port 24 mac dropped — absent from the second blob');
	check(!isset($tags['topology.neighbor.24.chassis_id']), 'neighbor 24 dropped — no neighbors in the second blob');
	check(($tags['topology.identity'] ?? null) === 'MANUAL-ID', 'topology.identity still survives a second ingest run');
	check(($tags['env'] ?? null) === 'lab', 'non-topology tag still survives a second ingest run');

	set_tags($hostid, []);
	echo $fail ? "\nSome checks FAILED.\n" : "\nAll checks passed — host left with no topology.* tags.\n";
	exit($fail ? 1 : 0);
}

define('TOPOLOGY_INGEST_TEST_HOOK', 'topology_ingest_test_hook');
require __DIR__.'/discovery/ingest.php';
