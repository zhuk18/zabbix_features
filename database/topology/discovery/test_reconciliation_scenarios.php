#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * test_reconciliation_scenarios.php — regression tests for the §3 rule 4 upsert/merge logic in
 * ingest.php, added per the review round that flagged three specific risks:
 *
 *   D. reporter_self must reuse a pre-existing Device (seen earlier as someone else's LLDP
 *      neighbor) via the normal chassis_id/mgmt_ip upsert, never create a duplicate — full
 *      neighbor-to-reporter lifecycle, including the reciprocal-LLDP convergence case.
 *   E. a Device re-matched by mgmt_ip with a *changed* chassis_id updates the existing row's
 *      chassis_id in place, rather than leaving a stale value or creating a second Device.
 *   F. last_seen_src/last_seen_dst must track the physical_link's *canonicalized* side, not the
 *      identity of whichever reporter's push confirmed it — easy to get backwards and have every
 *      other test still pass on a symmetric fixture (§3 rule 4, §8).
 *
 * Does NOT reimplement ingest.php's upsert/merge logic separately — that would test this file's
 * own understanding of the algorithm, not the actual code. Instead it `include`s ingest.php
 * itself with TOPOLOGY_INGEST_TEST_HOOK defined, which hands back every closure ingest.php's CLI
 * path uses ($device, $port, $ensure_physical_link, $promote, $merge_pseudo_port,
 * $find_matching_real_port, $find_or_create_host_node, ...) via get_defined_vars() — see the hook
 * itself, right before ingest.php's `$api = new ZabbixApi(...)` line. No Zabbix API, no SNMP lab,
 * no zabbix_server process needed: this exercises the exact DB-layer code a real ingest run would,
 * just without the network/API plumbing around it.
 *
 * Usage: test_reconciliation_scenarios.php <PDO DSN> <database user> [password]
 * Expects an EMPTY database — creates topo_nodes/topo_edges (mysql_schema.sql) plus minimal
 * `hosts`/`proxy` stub tables (topo_nodes.host_ref/proxy_ref's FK targets) itself. Safe to point at
 * a throwaway DB only; do not run against a real/dev Zabbix database.
 *
 * Side effect: because this exits via the test hook's `return` rather than ingest.php's own
 * normal completion path, ingest.php's shutdown handler writes an 'error' status to the shared
 * INGEST_STATUS_FILE (sys_get_temp_dir()/topology-ingest-status.json) — harmless (the same file a
 * real run would overwrite on its own next invocation), but worth clearing manually
 * (rm the topology-ingest.lock/topology-ingest-status.json files in the system temp dir) before
 * checking `GET /topo/ingest/status` for anything real right after running this.
 */

function fail_test(string $message): void {
	fwrite(STDERR, "FAIL: {$message}\n");
	exit(1);
}

function check(bool $condition, string $description): void {
	if (!$condition) {
		fail_test($description);
	}
	echo "  ok: {$description}\n";
}

$args = array_slice($argv, 1);
if (count($args) < 2 || count($args) > 3) {
	fwrite(STDERR, "Usage: {$argv[0]} <PDO DSN> <database user> [password]\n");
	exit(1);
}

$pdo = new PDO($args[0], $args[1], $args[2] ?? '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

$pdo->exec('CREATE TABLE IF NOT EXISTS hosts (hostid BIGINT UNSIGNED PRIMARY KEY, host VARCHAR(128) NOT NULL DEFAULT \'\')');
$pdo->exec('CREATE TABLE IF NOT EXISTS proxy (proxyid BIGINT UNSIGNED PRIMARY KEY)');
$pdo->exec(file_get_contents(__DIR__.'/../mysql_schema.sql'));

// Fake hostid for the "SwitchB is onboarded as a reporter" step in scenario D — needs a row in the
// stub `hosts` table since topo_nodes.host_ref has a real FK onto it.
$pdo->exec('INSERT INTO hosts (hostid, host) VALUES (90001, \'SwitchB\')');

putenv('ZABBIX_API_URL=http://unused.invalid');
putenv('ZABBIX_API_TOKEN=unused');
putenv('TOPOLOGY_PDO_DSN='.$args[0]);
putenv('TOPOLOGY_PDO_USER='.$args[1]);
putenv('TOPOLOGY_PDO_PASSWORD='.($args[2] ?? ''));

$results = ['pass' => 0];

define('TOPOLOGY_INGEST_TEST_HOOK', function (array $ingest) use ($pdo, &$results) {
	$device = $ingest['device'];
	$port = $ingest['port'];
	$ensure_physical_link = $ingest['ensure_physical_link'];
	$promote = $ingest['promote'];
	$find_or_create_host_node = $ingest['find_or_create_host_node'];
	$merge_pseudo_port = $ingest['merge_pseudo_port'];
	$find_matching_real_port = $ingest['find_matching_real_port'];
	$now = $ingest['now'];

	// $if_index defaults to distinguishing pseudo (999, standing in for a crc32-derived pseudo_if_index
	// — see ingest.php's $pseudo_if_index()) from a real port's own if_index (1) on the SAME Device, so
	// $port()'s (device_id, if_index) upsert key doesn't collide them into one row — mirroring the real
	// gap between a pseudo-Port's fabricated if_index and the eventual real Port's genuine one.
	$port_attrs = static function (string $name, bool $pseudo, int $if_index = 1): array {
		return ['if_index' => $if_index, 'name' => $name, 'if_type' => 'physical', 'mac' => null,
			'speed' => null, 'admin_status' => 'up', 'oper_status' => 'up', 'learned_macs' => [],
			'zabbix_itemids' => [], 'pseudo' => $pseudo];
	};

	// ---- D: full neighbor-to-reporter lifecycle ----
	echo "\n=== D: reporter_self reuses a pre-existing Device ===\n";

	// (1) Reporter A pushes, sees SwitchB as an LLDP neighbor.
	$device_a_id = $device(['mac' => null, 'chassis_id' => 'chassis-A', 'mgmt_ip' => '10.0.0.1',
		'sysname' => 'ReporterA', 'vendor' => 'unknown', 'last_seen' => $now]);
	$port_a = $port($device_a_id, $port_attrs('Gi0/1', false));

	$device_b_id_step1 = $device(['mac' => null, 'chassis_id' => 'chassis-B', 'mgmt_ip' => '10.0.0.2',
		'sysname' => 'SwitchB', 'vendor' => 'unknown', 'last_seen' => $now], $port_a);
	$pseudo_port_b = $find_matching_real_port($device_b_id_step1, 'Gi0/24');
	check($pseudo_port_b === null, 'step 1: no real port exists on Device(B) yet, so none is reused');
	$pseudo_port_b = $port($device_b_id_step1, $port_attrs('Gi0/24', true, 999));
	$ensure_physical_link($port_a, $pseudo_port_b, 'lldp', $port_a);

	$device_count_b = (int) $pdo->query(
		"SELECT COUNT(*) FROM topo_nodes WHERE type = 'device' AND JSON_UNQUOTE(JSON_EXTRACT(attrs, '$.chassis_id')) = 'chassis-B'"
	)->fetchColumn();
	check($device_count_b === 1, 'step 1: exactly one Device(B) created from the neighbor observation');
	check(portIsPseudo($pdo, $pseudo_port_b), 'step 1: Device(B)\'s port is an unconfirmed (pseudo) port');

	// (2) SwitchB is later onboarded and starts pushing as a reporter in its own right.
	$device_b_id_step2 = $device(['mac' => null, 'chassis_id' => 'chassis-B', 'mgmt_ip' => '10.0.0.2',
		'sysname' => 'SwitchB', 'vendor' => 'unknown', 'last_seen' => $now]);
	check($device_b_id_step2 === $device_b_id_step1,
		'step 2: Device(B) is REUSED (same id), not duplicated, once it becomes a reporter');

	$real_port_b = $port($device_b_id_step2, $port_attrs('Gi0/24', false, 24));
	$merge_pseudo_port($device_b_id_step2, $real_port_b, 'Gi0/24');

	$pseudo_still_exists = (bool) $pdo->query('SELECT 1 FROM topo_nodes WHERE id = '.$pseudo_port_b)->fetchColumn();
	check(!$pseudo_still_exists, 'step 2: the unconfirmed port was deleted by the merge');

	$link_row = $pdo->query(
		"SELECT src_id, dst_id FROM topo_edges WHERE type = 'physical_link'".
		" AND (src_id IN ({$port_a},{$real_port_b}) OR dst_id IN ({$port_a},{$real_port_b}))"
	)->fetch(PDO::FETCH_ASSOC);
	check($link_row !== false
			&& in_array((int) $link_row['src_id'], [$port_a, $real_port_b], true)
			&& in_array((int) $link_row['dst_id'], [$port_a, $real_port_b], true),
		'step 2: the physical_link from step 1 now points at real Ports on both ends');
	$link_count = (int) $pdo->query(
		"SELECT COUNT(*) FROM topo_edges WHERE type = 'physical_link'".
		" AND (src_id IN ({$port_a},{$real_port_b}) OR dst_id IN ({$port_a},{$real_port_b}))"
	)->fetchColumn();
	check($link_count === 1, 'step 2: still exactly one physical_link between A and B, no duplicate');
	$link_id_after_step2 = (int) $link_row['src_id'].'-'.(int) $link_row['dst_id'];

	$host_node_b = $find_or_create_host_node('90001');
	$promoted = $promote($device_b_id_step2, $host_node_b, 'reporter_self', null);
	check($promoted, 'step 2: Device(B) gets represented_by its own Host via reporter_self');
	$matched_by = $pdo->query(
		"SELECT JSON_UNQUOTE(JSON_EXTRACT(attrs, '$.matched_by')) FROM topo_edges".
		" WHERE type = 'represented_by' AND src_id = {$device_b_id_step2}"
	)->fetchColumn();
	check($matched_by === 'reporter_self', 'step 2: represented_by edge is stamped matched_by=reporter_self');

	// (3) SwitchB's own push also reports A as its neighbor (reciprocal LLDP).
	$device_a_id_step3 = $device(['mac' => null, 'chassis_id' => 'chassis-A', 'mgmt_ip' => '10.0.0.1',
		'sysname' => 'ReporterA', 'vendor' => 'unknown', 'last_seen' => $now], $real_port_b);
	check($device_a_id_step3 === $device_a_id, 'step 3: Device(A) is reused when seen back from Device(B)\'s side');

	$existing_real_port_a = $find_matching_real_port($device_a_id_step3, 'Gi0/1');
	check($existing_real_port_a === $port_a, 'step 3: Device(A)\'s existing real port is reused, no pseudo-port fabricated');

	$ensure_physical_link($real_port_b, $port_a, 'lldp', $real_port_b);
	$final_link_count = (int) $pdo->query(
		"SELECT COUNT(*) FROM topo_edges WHERE type = 'physical_link'".
		" AND (src_id IN ({$port_a},{$real_port_b}) OR dst_id IN ({$port_a},{$real_port_b}))"
	)->fetchColumn();
	check($final_link_count === 1, 'step 3: reciprocal LLDP still converges to the SAME single physical_link');
	$final_link = $pdo->query(
		"SELECT src_id, dst_id FROM topo_edges WHERE type = 'physical_link'".
		" AND (src_id IN ({$port_a},{$real_port_b}) OR dst_id IN ({$port_a},{$real_port_b}))"
	)->fetch(PDO::FETCH_ASSOC);
	check($link_id_after_step2 === $final_link['src_id'].'-'.$final_link['dst_id'],
		'step 3: same (src_id,dst_id) row as step 2, checked by ID not just by count');

	// ---- E: chassis_id change updates the existing Device in place ----
	echo "\n=== E: mgmt_ip match with a changed chassis_id updates in place ===\n";
	$device_e_id_1 = $device(['mac' => null, 'chassis_id' => 'chassis-E-old', 'mgmt_ip' => '10.0.1.1',
		'sysname' => 'DeviceE', 'vendor' => 'unknown', 'last_seen' => $now]);
	$device_e_id_2 = $device(['mac' => null, 'chassis_id' => 'chassis-E-new', 'mgmt_ip' => '10.0.1.1',
		'sysname' => 'DeviceE', 'vendor' => 'unknown', 'last_seen' => $now]);
	check($device_e_id_1 === $device_e_id_2, 'same Device row matched via mgmt_ip (chassis_id lookup alone would miss)');
	$stored_chassis_id = $pdo->query(
		"SELECT JSON_UNQUOTE(JSON_EXTRACT(attrs, '$.chassis_id')) FROM topo_nodes WHERE id = {$device_e_id_1}"
	)->fetchColumn();
	check($stored_chassis_id === 'chassis-E-new', 'chassis_id updated in place on the existing row');
	$device_count_e = (int) $pdo->query(
		"SELECT COUNT(*) FROM topo_nodes WHERE type = 'device' AND JSON_UNQUOTE(JSON_EXTRACT(attrs, '$.mgmt_ip')) = '10.0.1.1'"
	)->fetchColumn();
	check($device_count_e === 1, 'no second Device row created for mgmt_ip 10.0.1.1');

	// ---- F: last_seen_src/last_seen_dst track the CANONICAL side, not the reporter's identity ----
	echo "\n=== F: last_seen tracks the canonicalized side, not the reporter ===\n";
	$device_f1_id = $device(['mac' => null, 'chassis_id' => 'chassis-F1', 'mgmt_ip' => '10.0.2.1',
		'sysname' => 'DeviceF1', 'vendor' => 'unknown', 'last_seen' => $now]);
	$other_port = $port($device_f1_id, $port_attrs('Gi0/1', false)); // created first -> smaller id
	$device_f2_id = $device(['mac' => null, 'chassis_id' => 'chassis-F2', 'mgmt_ip' => '10.0.2.2',
		'sysname' => 'DeviceF2', 'vendor' => 'unknown', 'last_seen' => $now]);
	$reporter_port = $port($device_f2_id, $port_attrs('Gi0/1', false)); // created second -> larger id
	check($reporter_port > $other_port, 'fixture sanity: the reporter\'s own port has the LARGER id (forces a swap)');

	// Mirrors the real call site exactly: ensure_physical_link($local_port_id, $neighbor_port_id,
	// 'lldp', $local_port_id) — the reporter's own port always passed first, before canonicalization.
	$ensure_physical_link($reporter_port, $other_port, 'lldp', $reporter_port);

	$link_f = $pdo->query(
		"SELECT src_id, dst_id, JSON_EXTRACT(attrs, '$.last_seen_src') AS last_seen_src,".
		" JSON_EXTRACT(attrs, '$.last_seen_dst') AS last_seen_dst FROM topo_edges".
		" WHERE type = 'physical_link' AND src_id = {$other_port} AND dst_id = {$reporter_port}"
	)->fetch(PDO::FETCH_ASSOC);
	check($link_f !== false, 'physical_link canonicalized with the smaller port id as src_id, as expected');
	check($link_f['last_seen_dst'] !== null,
		'last_seen_dst is set — the reporter\'s port ended up on the dst side after canonicalization');
	check($link_f['last_seen_src'] === null,
		'last_seen_src is NOT set — confirms the field follows canonical position, not "the reporter sent this"');

	echo "\nAll checks passed.\n";
	$results['pass'] = 1;
});

function portIsPseudo(PDO $pdo, int $port_id): bool {
	return (bool) $pdo->query(
		"SELECT JSON_EXTRACT(attrs, '$.pseudo') FROM topo_nodes WHERE id = {$port_id}"
	)->fetchColumn();
}

require __DIR__.'/ingest.php';

if (empty($results['pass'])) {
	fail_test('TOPOLOGY_INGEST_TEST_HOOK never ran to completion');
}

exit(0);
