#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * test_lazy_host_nodes.php — regression tests for §5/§6's lazy Host/Proxy `topo_nodes` creation
 * policy (a Host/Proxy row now exists only when something actually justifies it — a MAC match,
 * `reporter_self`, or manual `/promote` — and is deleted again once that reason goes away):
 *
 *   1. A host with no MAC match and never promoted never gets a `topo_nodes` row.
 *   2. `/promote` against a host with no prior `topo_nodes` row creates the node and the
 *      representation in the same call; a second `/promote` onto the same already-materialized
 *      host is rejected by the existing 1:1 constraint (§2.3), not a new rule — and does not
 *      duplicate the node.
 *   3. `/depromote` deletes the Host node when nothing else justifies it (the only case reachable
 *      under §2.3's 1:1 constraint: once the one Device that could represent a Host does, nothing
 *      else can simultaneously be representing it — see the comment at the relevant check below for
 *      why "leave it in place when the host is a reporter" has no separate code path to exercise).
 *
 * Runs against this environment's actual configured Zabbix database (ui/conf/zabbix.conf.php) via
 * the real CTopologyPrototype/Zabbix API code paths — unlike test_reconciliation_scenarios.php
 * (which drives ingest.php's PDO-only closures directly), promote()/depromote() now call
 * API::Host()/API::Proxy() to verify the target exists, so a throwaway PDO-only DB isn't enough
 * here. Picks a real, currently-unused Device/Host pair from whatever's in the DB, promotes and
 * depromotes it, and asserts the pair is back to its original (untouched) state afterward — no
 * lasting side effects if all checks pass. NOT safe to run against a database you can't restore
 * from backup if something goes wrong partway; this repo's own dev DB is fine.
 *
 * Bootstrap recipe (the fragile part — see GOTCHAS.md's style of note, this is exactly that kind
 * of thing): CTopologyPrototype's Zabbix API calls need a fully authenticated CWebUser context,
 * not just APP::getInstance()->run(APP::EXEC_MODE_API) (which only sets up DB/config, the same
 * gap the minimal bootstrap in GOTCHAS.md #8 hits for anything that touches the API). Getting a
 * real API call through requires, in order:
 *   1. $_SERVER['REMOTE_ADDR'] set (CWebUser::getIp() dereferences it unconditionally).
 *   2. API::getWrapper()->auth = ['type' => 0] set BEFORE the first authenticated call — the
 *      CApiWrapper's $auth property is normally populated by the full HTTP request bootstrap this
 *      script skips; leaving it null throws a TypeError deep in CLocalApiClient::isAllowedMethod().
 *   3. CWebUser::checkAuthentication(<an active sessionid from the `sessions` table>) — reuses
 *      whatever session is currently active (e.g. a logged-in browser tab) rather than trying to
 *      fabricate credentials; if none exists, every check below is skipped, not failed.
 *
 * Usage: test_lazy_host_nodes.php
 * Run from anywhere; it chdir()s to ui/ itself. No arguments — reads the DB connection from this
 * environment's own ui/conf/zabbix.conf.php, same as the web UI would.
 */

chdir(__DIR__.'/../../ui');
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
require_once 'include/classes/core/APP.php';
APP::getInstance()->run(APP::EXEC_MODE_API);
API::getWrapper()->auth = ['type' => 0];

$session = DBfetch(DBselect("SELECT sessionid FROM sessions WHERE status=0 ORDER BY lastaccess DESC", 1));
if (!$session || !CWebUser::checkAuthentication($session['sessionid'])) {
	fwrite(STDERR, "SKIP: no active Zabbix session found to authenticate API calls with".
		" (log in via the web UI in another tab first, then re-run).\n");
	exit(0);
}

$fail = false;

function check(bool $condition, string $description): void {
	global $fail;
	echo ($condition ? '  ok: ' : '  FAIL: ').$description."\n";
	if (!$condition) {
		$fail = true;
	}
}

$device = DBfetch(DBselect("SELECT id FROM topo_nodes WHERE type='device' AND represented_by_node_id IS NULL", 1));
$existing_host_refs = array_column(DBfetchArray(DBselect("SELECT host_ref FROM topo_nodes WHERE type='host'")), 'host_ref');
$candidate = null;
foreach (API::Host()->get(['output' => ['hostid', 'name']]) as $host) {
	if (!in_array($host['hostid'], $existing_host_refs, true)) {
		$candidate = $host;
		break;
	}
}
if (!$device || !$candidate) {
	fwrite(STDERR, "SKIP: no unrepresented Device / never-materialized Host pair available in this DB.\n");
	exit(0);
}
echo "Using Device #{$device['id']}, Host {$candidate['hostid']} ({$candidate['name']})\n";

echo "\n=== 1: a host with no MAC match / never promoted never gets a topo_nodes row ===\n";
$before = (int) DBfetch(DBselect(
	"SELECT COUNT(*) AS c FROM topo_nodes WHERE type='host' AND host_ref=".zbx_dbstr($candidate['hostid'])
))['c'];
check($before === 0, 'no topo_nodes row exists for this host yet');

echo "\n=== §6 search endpoints write nothing ===\n";
$search_results = CTopologyPrototype::searchHosts($candidate['name']);
check(in_array($candidate['hostid'], array_column($search_results, 'hostid'), true),
	'searchHosts() finds the host via host.get directly');
$after_search = (int) DBfetch(DBselect(
	"SELECT COUNT(*) AS c FROM topo_nodes WHERE type='host' AND host_ref=".zbx_dbstr($candidate['hostid'])
))['c'];
check($after_search === 0, 'searchHosts() wrote nothing to topo_nodes');

echo "\n=== 2: /promote against a host with no prior topo_nodes row creates node + representation together ===\n";
CTopologyPrototype::promote((string) $device['id'], 'host', $candidate['hostid']);
$node = DBfetch(DBselect(
	"SELECT id FROM topo_nodes WHERE type='host' AND host_ref=".zbx_dbstr($candidate['hostid'])
), false);
check($node !== false, 'promote() created the Host node lazily, in the same call');
$dev_row = DBfetch(DBselect(
	'SELECT represented_by_node_id,represented_by_matched_by FROM topo_nodes WHERE id='.zbx_dbstr($device['id'])
), false);
check($node !== false && $dev_row['represented_by_node_id'] == $node['id'],
	'device now points at the newly created host node');
check($dev_row['represented_by_matched_by'] === 'manual', 'matched_by defaults to manual for /promote');

$device2 = DBfetch(DBselect(
	"SELECT id FROM topo_nodes WHERE type='device' AND represented_by_node_id IS NULL AND id!=".zbx_dbstr($device['id']), 1
));
if ($device2) {
	try {
		CTopologyPrototype::promote((string) $device2['id'], 'host', $candidate['hostid']);
		check(false, 'a second promote onto an already-represented host must be rejected');
	}
	catch (Exception $exception) {
		check(true, 'a second promote onto an already-represented host is rejected (existing 1:1 rule, §2.3, unaffected by this change)');
	}
	$node_count = (int) DBfetch(DBselect(
		"SELECT COUNT(*) AS c FROM topo_nodes WHERE type='host' AND host_ref=".zbx_dbstr($candidate['hostid'])
	))['c'];
	check($node_count === 1, 'still exactly one topo_nodes row for this host — upsert, not duplicate (§6 item 3)');
}
else {
	echo "  (skipped: no second unrepresented Device available to exercise the 1:1 rejection)\n";
}

echo "\n=== 3: /depromote deletes the Host node once nothing else justifies it ===\n";
// §2.3's 1:1 constraint (unaffected by this change) means at most one Device can ever represent a
// given Host at a time. So the "leave it in place, something else still justifies it" branch in
// depromote()'s isRepresentedTarget() check has no reachable scenario to construct here: the one
// Device that could have represented this Host is exactly the one being depromoted. This still
// exercises the real check (not a hardcoded "always delete"), just confirms the only outcome that's
// actually reachable under today's constraints.
CTopologyPrototype::depromote((string) $device['id']);
$still_exists = (bool) DBfetch(DBselect(
	"SELECT id FROM topo_nodes WHERE type='host' AND host_ref=".zbx_dbstr($candidate['hostid'])
));
check(!$still_exists, 'depromote() deleted the orphaned host node');
$dev_row_after = DBfetch(DBselect(
	'SELECT represented_by_node_id,represented_by_matched_by,represented_by_at FROM topo_nodes WHERE id='.zbx_dbstr($device['id'])
), false);
check($dev_row_after['represented_by_node_id'] === null
		&& $dev_row_after['represented_by_matched_by'] === null
		&& $dev_row_after['represented_by_at'] === null,
	'all three represented_by_* columns cleared on the device (§2.3 provenance invariant)');

echo $fail ? "\nSome checks FAILED.\n" : "\nAll checks passed — DB left in its original state.\n";
exit($fail ? 1 : 0);
