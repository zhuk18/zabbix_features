#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * test_steps.php — the steps of the reconciliation (topology-1-model.md §6.0) called alone: input X -> output Y, with no run, no snapshot and no other step (handoff 062).
 *
 *   Normalize  TopologyNormalize        (a row in, the normal row out)
 *   Identify   IngestIdentify           (a claim in, the Device or `ambiguous` or `new` out: model §6.7.1 rows)
 *   Links      IngestLinks              (two ports in, a link out; the second call changes nothing)
 *
 * Each step gets an IngestRun (the connection, the clock, the run summary and the tables of the run) that the test fills with what the step reads.
 *
 * Usage: test_steps.php <PDO DSN> <database user> [password]
 * Expects a THROWAWAY database. Never point it at a real Zabbix database.
 */

$args = array_slice($argv, 1);
if (count($args) < 2 || count($args) > 3) {
	fwrite(STDERR, "Usage: {$argv[0]} <PDO DSN> <database user> [password]\n");
	exit(1);
}
$pdo = new PDO($args[0], $args[1], $args[2] ?? '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
foreach (['topo_identity_keys', 'topo_observations', 'topo_edges', 'topo_nodes', 'topo_lld_snapshot', 'items', 'proxy', 'hosts'] as $table) {
	$pdo->exec("DROP TABLE IF EXISTS {$table}");
}
$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
$pdo->exec('CREATE TABLE hosts (hostid BIGINT UNSIGNED PRIMARY KEY, host VARCHAR(128) NOT NULL DEFAULT \'\')');
$pdo->exec('CREATE TABLE proxy (proxyid BIGINT UNSIGNED PRIMARY KEY)');
$pdo->exec('CREATE TABLE items (itemid BIGINT UNSIGNED PRIMARY KEY)');
$pdo->exec(file_get_contents(__DIR__.'/../mysql_schema.sql'));

require_once __DIR__.'/../topology_contradiction.inc.php';
require_once __DIR__.'/ingest/TopologyNormalize.php';
require_once __DIR__.'/ingest/IngestRun.php';
require_once __DIR__.'/ingest/IngestKeys.php';
require_once __DIR__.'/ingest/IngestStore.php';
require_once __DIR__.'/ingest/IngestIdentify.php';
require_once __DIR__.'/ingest/IngestFarPort.php';
require_once __DIR__.'/ingest/IngestLinks.php';
require_once __DIR__.'/../../../ui/include/classes/topology/CTopologyAddress.php';

$checks = 0;
$failures = 0;

function check(bool $ok, string $what, $detail = null): void {
	global $checks, $failures;

	$checks++;
	$failures += $ok ? 0 : 1;
	echo ($ok ? '  ok: ' : '  FAIL: ').$what.(!$ok && $detail !== null ? "\n        ".(is_string($detail) ? $detail : json_encode($detail, JSON_UNESCAPED_UNICODE)) : '')."\n";
}

const NOW = 1_800_000_000;

/** A fresh run: empty tables, the modules of ingest.php, a run summary with the counters the steps write. */
function new_run(PDO $pdo): IngestRun {
	$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
	foreach (['topo_identity_keys', 'topo_observations', 'topo_edges', 'topo_nodes'] as $table) {
		$pdo->exec("TRUNCATE {$table}");
	}
	$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
	$run = new IngestRun($pdo, NOW, '`key`');
	$run->insert_node = $pdo->prepare('INSERT INTO topo_nodes (type, attrs, created_at, updated_at) VALUES (?, ?, ?, ?)');
	$run->insert_port_node = $pdo->prepare('INSERT INTO topo_nodes (type, device_id, attrs, created_at, updated_at) VALUES (?, ?, ?, ?, ?)');
	$run->update_node = $pdo->prepare('UPDATE topo_nodes SET attrs = ?, updated_at = ? WHERE id = ?');
	$run->insert_edge = $pdo->prepare('INSERT INTO topo_edges (type, src_id, dst_id, attrs, created_at) VALUES (?, ?, ?, ?, ?)');
	$run->keys = new IngestKeys($run);
	$run->store = new IngestStore($run);
	$run->identify = new IngestIdentify($run);
	$run->farport = new IngestFarPort($run);
	$run->links = new IngestLinks($run);
	$run->summary = ['devices_created' => 0, 'devices_updated' => 0, 'ports_created' => 0, 'links_created' => 0, 'identity_keys_shared' => ['count' => 0, 'list' => []]];

	return $run;
}

function make_device(PDO $pdo, array $attrs, ?string $key = null): int {
	$pdo->prepare("INSERT INTO topo_nodes (type, attrs, created_at, updated_at) VALUES ('device', ?, ?, ?)")->execute([json_encode($attrs), NOW, NOW]);
	$id = (int) $pdo->lastInsertId();
	if ($key !== null) {
		$pdo->prepare('INSERT INTO topo_identity_keys (device_id, `key`, kind, first_seen, replaced) VALUES (?, ?, ?, ?, 0)')->execute([$id, $key, 'chassis', NOW]);
	}

	return $id;
}

echo "=== Normalize: a row in, the normal row out ===\n";
[$row, $problem] = TopologyNormalize::row(['rem_chassis_type' => 'macAddress', 'rem_chassis' => 'AA-BB-CC-00-00-01', 'rem_sysname' => ' "sw-1" ', 'rem_man_addrs' => 'lldp:1.4.10.0.0.9 lldp:1.4.127.0.0.1']);
check($problem === null && $row['rem_chassis'] === 'aa:bb:cc:00:00:01' && $row['rem_sysname'] === 'sw-1' && $row['rem_mgmt_ip'] === '10.0.0.9', 'a MAC in dash form, a quoted name and two addresses: the normal row (loopback skipped)', [$row, $problem]);
[$row, $problem] = TopologyNormalize::row(['rem_chassis_type' => 'macAddress', 'rem_chassis' => 'aa:bb:cc:00:00', 'rem_sysname' => 'x']);
check($row === null && $problem !== null, 'a MAC of 5 octets: the row is invalid, not guessed');

// 066: a CDP device id in the net-snmp MAC form is a hardware ID: the chassis key of kind MAC; a placeholder in that form and mixed separators are a name (no chassis key)
$run = new_run($pdo);
[$row] = TopologyNormalize::row(['source' => 'cdp', 'rem_sysname' => '0:c:29:a:b:c', 'rem_port' => 'Gi0/1']);
check(($row['rem_chassis'] ?? null) === '00:0c:29:0a:0b:0c' && !isset($row['rem_sysname']) && $run->keys->chassisKey($row['rem_chassis'], $row['rem_chassis_type'], 'server') === 'chassis:mac:00:0c:29:0a:0b:0c',
	'a CDP device id 0:c:29:a:b:c: chassis:mac:00:0c:29:0a:0b:0c', $row);
[$row] = TopologyNormalize::row(['source' => 'cdp', 'rem_sysname' => '0:0:0:0:0:0', 'rem_port' => 'Gi0/1']);
check(!isset($row['rem_chassis']) && ($row['rem_sysname'] ?? null) === '0:0:0:0:0:0', 'a placeholder in that form: a name, no chassis, so no key', $row);
[$row] = TopologyNormalize::row(['source' => 'cdp', 'rem_sysname' => '0:c:29-a-b-c', 'rem_port' => 'Gi0/1']);
check(!isset($row['rem_chassis']) && ($row['rem_sysname'] ?? null) === '0:c:29-a-b-c', 'mixed separators: a name', $row);

echo "\n=== Identify: the chassis rows of model §6.7.1 (resolveDevice) ===\n";
$run = new_run($pdo);
check($run->identify->resolveDevice(['chassis' => ['chassis:mac:aa:bb:cc:00:00:01', 'aa:bb:cc:00:00:01']]) === null, 'a chassis that no Device has: none (a new Device is made by the caller)');
$d1 = make_device($pdo, ['chassis_id' => 'aa:bb:cc:00:00:01'], 'chassis:mac:aa:bb:cc:00:00:01');
$r = $run->identify->resolveDevice(['chassis' => ['chassis:mac:aa:bb:cc:00:00:01', 'aa:bb:cc:00:00:01']]);
check($r === [$d1, 'chassis_id'], 'a chassis that one Device has: that Device', $r);
$d2 = make_device($pdo, ['chassis_id' => 'aa:bb:cc:00:00:01'], 'chassis:mac:aa:bb:cc:00:00:01');
$r = $run->identify->resolveDevice(['chassis' => ['chassis:mac:aa:bb:cc:00:00:01', 'aa:bb:cc:00:00:01']]);
check($r !== null && $r[0] === 'shared' && $r[3] === [$d1, $d2], 'a chassis that two Devices have: shared (the claim is ambiguous, §6.7.4)', $r);

echo "\n=== Identify: a claim without a chassis (findChassisless): the rows of the Devices of the port ===\n";
$run = new_run($pdo);
$port = 501;                       // the local port node id (only a number here: the step reads port_scope)
$run->port_scope = [$port => [
	71 => ['addrs' => ['10.0.0.20' => true], 'name' => 'AP-7'],
	72 => ['addrs' => ['10.0.0.30' => true], 'name' => 'AP-8'],
]];
$r = $run->identify->findChassisless(101, 'server', $port, ['addrs' => ['10.0.0.20'], 'name' => 'AP-7']);
check($r === ['found', 71], 'one Device of the port matches (same address and name): that Device', $r);
$r = $run->identify->findChassisless(101, 'server', $port, ['addrs' => ['10.0.0.21'], 'name' => 'AP-7']);
check($r === ['found', 71], 'the address changed, the name is the same: still that Device', $r);
$r = $run->identify->findChassisless(101, 'server', $port, ['addrs' => ['10.0.0.20'], 'name' => 'AP-9']);
check($r === ['new'], 'the same address with another name does not match: a new Device', $r);
$run->port_scope[$port][73] = ['addrs' => ['10.0.0.20' => true], 'name' => ''];
$r = $run->identify->findChassisless(101, 'server', $port, ['addrs' => ['10.0.0.20'], 'name' => '']);
check($r === ['ambiguous'], 'two Devices of the port match: ambiguous, no guess', $r);
$r = $run->identify->findChassisless(101, 'server', 999, ['addrs' => ['10.0.0.20'], 'name' => 'AP-7']);
check($r === ['new'], 'no Device on that port: a new Device', $r);

echo "\n=== Identify: the claims with a chassis on the same port (2.50) ===\n";
$run = new_run($pdo);
$run->port_scope = [];
$run->port_chassis_claims['101|'.$port] = [
	['device_id' => 81, 'addrs' => ['10.9.0.1'], 'name' => 'AA'],
	['device_id' => 82, 'addrs' => ['10.9.0.2'], 'name' => 'BB'],
];
$r = $run->identify->findChassisless(101, 'server', $port, ['addrs' => ['10.9.0.1'], 'name' => 'AA']);
check($r === ['found', 81], 'a claim that matches only one of two claims with a chassis: that Device', $r);
$run->port_chassis_claims['101|'.$port] = [
	['device_id' => 81, 'addrs' => ['10.9.0.9'], 'name' => 'AA'],
	['device_id' => 82, 'addrs' => ['10.9.0.9'], 'name' => 'BB'],
];
$r = $run->identify->findChassisless(101, 'server', $port, ['addrs' => ['10.9.0.9'], 'name' => '']);
check($r === ['new'], 'a claim that matches both, which are two Devices: no guess, a new Device of the port', $r);
$run->port_chassis_claims['101|'.$port] = [
	['device_id' => 81, 'addrs' => ['10.9.0.9'], 'name' => 'AA'],
	['device_id' => 81, 'addrs' => ['10.9.0.9'], 'name' => 'AA'],
];
$r = $run->identify->findChassisless(101, 'server', $port, ['addrs' => ['10.9.0.9'], 'name' => '']);
check($r === ['found', 81], 'the claims that match resolve to one Device (two claims of it): that Device', $r);

echo "\n=== Links: two ports in, a link out; the second call changes nothing (P1) ===\n";
$run = new_run($pdo);
$da = make_device($pdo, ['chassis_id' => 'aa:00:00:00:00:01'], 'chassis:mac:aa:00:00:00:00:01');
$db = make_device($pdo, ['chassis_id' => 'aa:00:00:00:00:02'], 'chassis:mac:aa:00:00:00:00:02');
$pa = $run->store->port($da, 1);
$pb = $run->store->port($db, 7);
$edges = static fn (): array => $pdo->query("SELECT id, src_id, dst_id, attrs FROM topo_edges WHERE type = 'physical_link' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
$id = $run->links->link($pa, $pb, 'lldp', $pa, NOW);
$rows = $edges();
$attrs = json_decode($rows[0]['attrs'], true);
check(count($rows) === 1 && (int) $rows[0]['id'] === $id && $attrs['discovered_via'] === 'lldp' && $attrs['last_seen_src'] === NOW && $run->summary['links_created'] === 1,
	'a new link between the two ports, made by the reporter of port A: one discovered link, links_created 1', [$rows, $run->summary]);
$before = json_encode($edges());
check($run->links->link($pa, $pb, 'lldp', $pa, NOW) === $id && json_encode($edges()) === $before && $run->summary['links_created'] === 1, 'the same call again: the same link, nothing changed (P1)');
$id2 = $run->links->link($pb, $pa, 'lldp', $pb, NOW + 60);
$attrs = json_decode($edges()[0]['attrs'], true);
check($id2 === $id && count($edges()) === 1 && $attrs['last_seen_dst'] === NOW + 60 && $attrs['last_seen_src'] === NOW && $attrs['last_seen'] === NOW + 60,
	'the other end reports it later: the same link, only its side and last_seen move', $attrs);

$run = new_run($pdo);
$da = make_device($pdo, ['chassis_id' => 'aa:00:00:00:00:01'], 'chassis:mac:aa:00:00:00:00:01');
$db = make_device($pdo, ['chassis_id' => 'aa:00:00:00:00:02'], 'chassis:mac:aa:00:00:00:00:02');
$pa = $run->store->port($da, 1);
$pb = $run->store->port($db, 7);
$pdo->prepare("INSERT INTO topo_edges (type, src_id, dst_id, attrs, created_at) VALUES ('physical_link', ?, ?, ?, ?)")->execute([min($pa, $pb), max($pa, $pb), json_encode(['discovered_via' => 'manual']), NOW]);
$manual = (int) $pdo->lastInsertId();
check($run->links->link($pa, $pb, 'lldp', $pa, NOW, true) === $manual && json_decode($edges()[0]['attrs'], true)['discovered_via'] === 'manual', 'a manual link that discovery contradicts (keep_manual): it stays manual (P5)');
check($run->links->link($pa, $pb, 'lldp', $pa, NOW) === $manual && json_decode($edges()[0]['attrs'], true)['discovered_via'] === 'lldp' && count($edges()) === 1, 'confirmed by discovery: the same link (same id) becomes a discovered link (§9.3, P5)');

echo "\n".($failures === 0 ? "OK: {$checks} checks\n" : "FAILED: {$failures} of {$checks} checks\n");
exit($failures === 0 ? 0 : 1);
