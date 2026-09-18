#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * test_tags_model.php — regression tests for the tags-only topology model
 * (topology-tags-model-spec.md), exercised against CTopologyPrototype's real Zabbix API code path
 * (no DB tables involved at all — see the spec's §0/§24: there is no topology-specific persistence
 * to test against, only Host tags read/written through the normal Zabbix API).
 *
 * Covers:
 *   - Basic discovery: a reporter's neighbor observation resolving to another reporter Host.
 *   - Unmanaged device: a neighbor observation with no matching Host becomes a derived node.
 *   - Promote: associates an unmanaged identity with an existing Host in one call, no DB record.
 *   - Promote validation: an identity already claimed by another Host is rejected (§5 rule 2).
 *   - Depromote: removes the association; the identity reappears as unmanaged if still observed.
 *   - Link/Unlink: linkPort() writes a `topology.neighbor.*` pair on BOTH sides (ports UI's manual
 *     Link button); unlinkPort() removes only the invoking side's tag, leaving the reciprocal side's
 *     observation (and therefore the derived link) intact per §13.
 *   - Disappearance: clearing a Host's topology tags removes it from the graph entirely (§5's
 *     lazy-relevance principle — no separate cleanup step needed, nothing was ever persisted).
 *
 * Bootstrap: see GOTCHAS.md #3 for why this can't just use a DB connection like a PDO-only script
 * would — every one of the operations under test goes through the real Zabbix API (host.get/
 * host.update), which needs a fully authenticated API context. This also means the test mutates
 * real Host tags on whatever two hosts it picks — it always leaves them exactly as it found them
 * (empty of topology.* tags) when it finishes, including on a rejected-promote path, but is NOT
 * safe to run against hosts something else depends on having stable tags at the same time.
 *
 * Usage: test_tags_model.php
 * Reads DB connection from this environment's own ui/conf/zabbix.conf.php, same as the web UI.
 */

chdir(__DIR__.'/../../ui');
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
require_once 'include/classes/core/APP.php';
APP::getInstance()->run(APP::EXEC_MODE_API);
API::getWrapper()->auth = ['type' => 0];

// This environment's DB schema predates a column this branch's code expects in the `users` table
// (see GOTCHAS.md #4), so CUser::checkAuthentication() can't be used — populate the authenticated
// context by hand instead (GOTCHAS.md #3).
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

// GOTCHAS.md #2: host.update() needs a LIST of host objects, even for one.
function set_tags(string $hostid, array $tags): void {
	API::Host()->update([['hostid' => $hostid, 'tags' => $tags]]);
}

$hosts = API::Host()->get(['output' => ['hostid', 'name'], 'limit' => 2]);
if (count($hosts) < 2) {
	fwrite(STDERR, "SKIP: need at least 2 Zabbix hosts in this instance to run this test.\n");
	exit(0);
}
[$hostA, $hostB] = $hosts;
echo "Using Host A #{$hostA['hostid']} ({$hostA['name']}), Host B #{$hostB['hostid']} ({$hostB['name']})\n";

// Clean slate, in case a previous interrupted run left tags behind.
set_tags($hostA['hostid'], []);
set_tags($hostB['hostid'], []);

echo "\n=== Basic discovery: A reports B as neighbor via chassis_id ===\n";
set_tags($hostA['hostid'], [
	['tag' => 'topology.chassis_id', 'value' => 'AA:AA:AA:AA:AA:AA'],
	['tag' => 'topology.port.24.name', 'value' => 'Gi0/24'],
	['tag' => 'topology.neighbor.24.chassis_id', 'value' => 'BB:BB:BB:BB:BB:BB'],
	['tag' => 'topology.neighbor.24.port', 'value' => 'Gi0/1']
]);
set_tags($hostB['hostid'], [
	['tag' => 'topology.chassis_id', 'value' => 'BB:BB:BB:BB:BB:BB'],
	['tag' => 'topology.port.1.name', 'value' => 'Gi0/1']
]);

$devices = CTopologyPrototype::getDevices();
$ids = array_column($devices, 'id');
check(in_array('host:'.$hostA['hostid'], $ids, true), 'Host A appears as a host node');
check(in_array('host:'.$hostB['hostid'], $ids, true), 'Host B appears as a host node');
check(!in_array('unmanaged:BB:BB:BB:BB:BB:BB', $ids, true), 'B is NOT an unmanaged node (resolved to the real Host)');

$expected_pair_ab = ['host:'.$hostA['hostid'], 'host:'.$hostB['hostid']];
sort($expected_pair_ab);
$relations = CTopologyPrototype::getRelations();
$found = false;
foreach ($relations as $relation) {
	$pair = [$relation['source'], $relation['target']];
	sort($pair);
	if ($pair === $expected_pair_ab) {
		$found = true;
	}
}
check($found, 'A <-> B link exists in the derived graph (§10/§12)');

echo "\n=== Unmanaged device (§11) ===\n";
set_tags($hostA['hostid'], [
	['tag' => 'topology.chassis_id', 'value' => 'AA:AA:AA:AA:AA:AA'],
	['tag' => 'topology.port.24.name', 'value' => 'Gi0/24'],
	['tag' => 'topology.neighbor.24.chassis_id', 'value' => 'XX:XX:XX:XX:XX:XX'],
	['tag' => 'topology.neighbor.24.port', 'value' => 'Gi0/1'],
	['tag' => 'topology.neighbor.24.name', 'value' => 'MysterySwitch']
]);
$devices = CTopologyPrototype::getDevices();
$unmanaged_node = null;
foreach ($devices as $device) {
	if ($device['id'] === 'unmanaged:XX:XX:XX:XX:XX:XX') {
		$unmanaged_node = $device;
	}
}
check($unmanaged_node !== null, 'unmanaged node X appears in the graph');
check($unmanaged_node !== null && $unmanaged_node['name'] === 'MysterySwitch',
	'unmanaged node picks up the reported name');
check($unmanaged_node !== null && $unmanaged_node['type'] === 'device',
	'unmanaged node has type=device (dashed styling, no Host behind it)');

echo "\n=== Promote (§5) ===\n";
try {
	CTopologyPrototype::promote('XX:XX:XX:XX:XX:XX', $hostB['hostid']);
	check(true, 'promote succeeded (identity is currently visible via A\'s neighbor observation)');
}
catch (Exception $exception) {
	check(false, 'promote failed: '.$exception->getMessage());
}
$devices = CTopologyPrototype::getDevices();
$ids = array_column($devices, 'id');
check(!in_array('unmanaged:XX:XX:XX:XX:XX:XX', $ids, true), 'X is no longer an unmanaged node after promote');
check(in_array('host:'.$hostB['hostid'], $ids, true), 'Host B still present');

$relations = CTopologyPrototype::getRelations();
$found = false;
foreach ($relations as $relation) {
	$pair = [$relation['source'], $relation['target']];
	sort($pair);
	if ($pair === $expected_pair_ab) {
		$found = true;
	}
}
check($found, 'A <-> Host B link exists after promoting X to Host B — no topology database object created');

echo "\n=== Promote validation (§5 rule 2) ===\n";
try {
	CTopologyPrototype::promote('XX:XX:XX:XX:XX:XX', $hostA['hostid']);
	check(false, 'promoting an identity already claimed by another host must be rejected');
}
catch (Exception $exception) {
	check(true, 'rejected as expected: '.$exception->getMessage());
}

echo "\n=== Depromote (§6) ===\n";
CTopologyPrototype::depromote($hostB['hostid']);
$devices = CTopologyPrototype::getDevices();
$ids = array_column($devices, 'id');
// A still reports neighbor X (chassis_id XX...) and B no longer claims identity=XX..., so X must
// reappear as unmanaged — no topology entity was ever deleted or recreated (§6/§11).
check(in_array('unmanaged:XX:XX:XX:XX:XX:XX', $ids, true),
	'X reappears as unmanaged after depromote (still observed by A) — nothing to delete/recreate');

echo "\n=== Manual Link/Unlink (ports UI) ===\n";
// Add a second port to A (ifIndex 99) so linkPort() has a real local port to target — leaves A's
// existing port.24/neighbor.24 tags (used above) untouched.
$current_a = API::Host()->get(['hostids' => [$hostA['hostid']], 'output' => [], 'selectTags' => 'extend'])[0]['tags'];
set_tags($hostA['hostid'], array_merge(
	array_map(static fn(array $tag): array => ['tag' => $tag['tag'], 'value' => $tag['value']], $current_a),
	[['tag' => 'topology.port.99.name', 'value' => 'Gi0/99']]
));

CTopologyPrototype::linkPort($hostA['hostid'], 99, $hostB['hostid'], 1);

$a_tags = [];
foreach (API::Host()->get(['hostids' => [$hostA['hostid']], 'output' => [], 'selectTags' => 'extend'])[0]['tags'] as $tag) {
	$a_tags[$tag['tag']] = $tag['value'];
}
$b_tags = [];
foreach (API::Host()->get(['hostids' => [$hostB['hostid']], 'output' => [], 'selectTags' => 'extend'])[0]['tags'] as $tag) {
	$b_tags[$tag['tag']] = $tag['value'];
}
check(($a_tags['topology.neighbor.99.chassis_id'] ?? null) === 'BB:BB:BB:BB:BB:BB', 'linkPort() writes A-side neighbor tag');
check(($a_tags['topology.neighbor.99.port'] ?? null) === 'Gi0/1', 'linkPort() records the target port label on A');
check(($b_tags['topology.neighbor.1.chassis_id'] ?? null) === 'AA:AA:AA:AA:AA:AA', 'linkPort() also writes the reciprocal B-side neighbor tag');
check(($a_tags['topology.port.24.name'] ?? null) === 'Gi0/24', "linkPort() doesn't disturb A's existing port/neighbor tags on a different ifIndex");

$relations = CTopologyPrototype::getRelations();
$found_manual_link = false;
foreach ($relations as $relation) {
	$pair = [$relation['source'], $relation['target']];
	sort($pair);
	if ($pair === $expected_pair_ab) {
		$found_manual_link = true;
	}
}
check($found_manual_link, 'manually linked A/B ports produce an A<->B relation in the derived graph');

CTopologyPrototype::unlinkPort($hostA['hostid'], 99);
$a_tags = [];
foreach (API::Host()->get(['hostids' => [$hostA['hostid']], 'output' => [], 'selectTags' => 'extend'])[0]['tags'] as $tag) {
	$a_tags[$tag['tag']] = $tag['value'];
}
$b_tags = [];
foreach (API::Host()->get(['hostids' => [$hostB['hostid']], 'output' => [], 'selectTags' => 'extend'])[0]['tags'] as $tag) {
	$b_tags[$tag['tag']] = $tag['value'];
}
check(!isset($a_tags['topology.neighbor.99.chassis_id']), 'unlinkPort() removes only the A-side neighbor tag');
check(($b_tags['topology.neighbor.1.chassis_id'] ?? null) === 'AA:AA:AA:AA:AA:AA',
	"unlinkPort() from A's side leaves B's reciprocal observation untouched (§13: no reciprocal required)");

echo "\n=== Disappearance / lazy relevance (§5, §11) ===\n";
set_tags($hostA['hostid'], []);
set_tags($hostB['hostid'], []);
$devices = CTopologyPrototype::getDevices();
$ids = array_column($devices, 'id');
check(!in_array('host:'.$hostA['hostid'], $ids, true), 'Host A no longer topology-relevant once its tags are cleared');
check(!in_array('host:'.$hostB['hostid'], $ids, true), 'Host B no longer topology-relevant once its tags are cleared');
check(!in_array('unmanaged:XX:XX:XX:XX:XX:XX', $ids, true), 'X disappears once nothing observes it anymore');

echo $fail ? "\nSome checks FAILED.\n" : "\nAll checks passed — hosts left with no topology.* tags.\n";
exit($fail ? 1 : 0);
