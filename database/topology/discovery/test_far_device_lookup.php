#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * test_far_device_lookup.php — topology-1-model.md §6.7.1 "Far Device" (2.47): the lookup table row by row, and the match rule. It replaces test_join_identity.php, which
 * tested the automatic join of a split identity that 2.47 removes (an address and a name are attributes, matched within one reporter port; no automatic join).
 *
 * Same method as test_shared_identifier.php: rows straight into topo_lld_snapshot, the real ingest.php as a subprocess; every case also checks that five runs over the
 * same snapshots change nothing (P1) and, where two reporters take part, that the reporter order does not matter (P2).
 *
 * Usage: test_far_device_lookup.php <PDO DSN> <database user> [password]
 * Expects a THROWAWAY database (drops and recreates the topology tables and the stand-ins of the Zabbix tables).
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
[$dsn, $user] = $args;
$password = $args[2] ?? '';

$pdo = new PDO($dsn, $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
foreach (['topo_identity_keys', 'topo_observations', 'topo_edges', 'topo_nodes', 'topo_lld_snapshot', 'host_inventory', 'interface',
		'items', 'proxy', 'hosts'] as $table) {
	$pdo->exec("DROP TABLE IF EXISTS {$table}");
}
$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
$pdo->exec("CREATE TABLE hosts (hostid BIGINT UNSIGNED PRIMARY KEY, host VARCHAR(128) NOT NULL, name VARCHAR(128) NOT NULL DEFAULT '', status INT NOT NULL DEFAULT 0)");
$pdo->exec('CREATE TABLE proxy (proxyid BIGINT UNSIGNED PRIMARY KEY)');
$pdo->exec('CREATE TABLE items (itemid BIGINT UNSIGNED PRIMARY KEY, hostid BIGINT UNSIGNED NOT NULL, status INT NOT NULL DEFAULT 0, topology_type INT NOT NULL DEFAULT 0, type INT NOT NULL DEFAULT 0)');
$pdo->exec("CREATE TABLE interface (interfaceid BIGINT UNSIGNED PRIMARY KEY, hostid BIGINT UNSIGNED NOT NULL, type INT NOT NULL, useip INT NOT NULL DEFAULT 1, ip VARCHAR(64) NOT NULL DEFAULT '', main INT NOT NULL DEFAULT 1)");
$pdo->exec("CREATE TABLE host_inventory (hostid BIGINT UNSIGNED PRIMARY KEY, macaddress_a VARCHAR(64) NOT NULL DEFAULT '', macaddress_b VARCHAR(64) NOT NULL DEFAULT '')");
$pdo->exec('CREATE TABLE topo_lld_snapshot (itemid BIGINT UNSIGNED PRIMARY KEY, hostid BIGINT UNSIGNED NOT NULL, topology_type INT NOT NULL DEFAULT 0,'.
	' clock INT NOT NULL DEFAULT 0, rows_hash VARCHAR(64) NOT NULL DEFAULT \'\', rows_json LONGTEXT NOT NULL, rows_total INT NOT NULL DEFAULT 0,'.
	' rows_valid INT NOT NULL DEFAULT 0)');
$pdo->exec(file_get_contents(__DIR__.'/../mysql_schema.sql'));

// ---- helpers ----

function add_host(PDO $pdo, int $hostid, string $host, string $name, ?string $ip): void {
	$pdo->prepare('INSERT INTO hosts (hostid, host, name) VALUES (?, ?, ?)')->execute([$hostid, $host, $name]);
	if ($ip !== null) {
		$pdo->prepare('INSERT INTO interface (interfaceid, hostid, type, useip, ip, main) VALUES (?, ?, 2, 1, ?, 1)')
			->execute([$hostid, $hostid, $ip]);
	}
}

function snapshot(PDO $pdo, int $itemid, int $hostid, int $topology_type, int $clock, array $rows, int $current_topology_type = null,
		int $status = 0): void {
	$pdo->prepare('REPLACE INTO items (itemid, hostid, status, topology_type) VALUES (?, ?, ?, ?)')
		->execute([$itemid, $hostid, $status, $current_topology_type ?? $topology_type]);
	$json = json_encode($rows, JSON_THROW_ON_ERROR);
	$pdo->prepare('REPLACE INTO topo_lld_snapshot (itemid, hostid, topology_type, clock, rows_hash, rows_json, rows_total, rows_valid)'.
		' VALUES (?, ?, ?, ?, ?, ?, ?, ?)')->execute([$itemid, $hostid, $topology_type, $clock, hash('sha256', $json), $json,
		count($rows), count($rows)]);
}

function run_ingest(string $dsn, string $user, string $password, array $extra = [], ?string $order = null): array {
	$cmd = [PHP_BINARY, __DIR__.'/ingest.php', '--pdo-dsn', $dsn, '--pdo-user', $user, '--pdo-password', $password];
	foreach ($extra as $host) {
		array_push($cmd, '--zabbix-host', $host);
	}
	if ($order !== null) {
		array_push($cmd, '--reporter-order', $order);
	}
	// A private temp dir keeps the run lock and status file away from any real ingest's.
	$tmp = sys_get_temp_dir().'/topology-snapshot-ingest-test';
	@mkdir($tmp);
	$process = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, ['TMPDIR' => $tmp]);
	$stdout = stream_get_contents($pipes[1]);
	$stderr = stream_get_contents($pipes[2]);
	fclose($pipes[1]);
	fclose($pipes[2]);
	$code = proc_close($process);
	$status = json_decode((string) @file_get_contents($tmp.'/topology-ingest-status.json'), true);
	return ['code' => $code, 'out' => $stdout, 'err' => $stderr, 'summary' => $status['summary'] ?? []];
}

function scalar(PDO $pdo, string $sql, array $params = []) {
	$stmt = $pdo->prepare($sql);
	$stmt->execute($params);
	return $stmt->fetchColumn();
}

function device_id(PDO $pdo, string $chassis): ?int {
	$id = scalar($pdo, "SELECT id FROM topo_nodes WHERE type='device' AND JSON_UNQUOTE(JSON_EXTRACT(attrs, '\$.chassis_id')) = ?", [$chassis]);
	return $id === false ? null : (int) $id;
}

function port_id(PDO $pdo, int $device_id, int $if_index): ?int {
	$id = scalar($pdo, "SELECT id FROM topo_nodes WHERE device_id=? AND type='port' AND JSON_EXTRACT(attrs, '\$.if_index') = ?", [$device_id, $if_index]);
	return $id === false ? null : (int) $id;
}

function attrs(PDO $pdo, int $node_id): array {
	return json_decode((string) scalar($pdo, 'SELECT attrs FROM topo_nodes WHERE id=?', [$node_id]), true);
}

function edges(PDO $pdo): array {
	return $pdo->query("SELECT id, src_id, dst_id, attrs FROM topo_edges WHERE type='physical_link' ORDER BY id")
		->fetchAll(PDO::FETCH_ASSOC);
}

/** Every node/edge id and every last_seen value, for "nothing changed between two runs" comparisons. */
function fingerprint(PDO $pdo): string {
	$nodes = $pdo->query('SELECT id, type, device_id, lag_id, represented_by_node_id, attrs, updated_at FROM topo_nodes ORDER BY id')
		->fetchAll(PDO::FETCH_ASSOC);
	$edges = $pdo->query('SELECT id, src_id, dst_id, attrs FROM topo_edges ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
	$obs = $pdo->query('SELECT id, itemid, local_port_id, remote_key, outcome, edge_id, device_id, first_seen, last_seen FROM topo_observations ORDER BY id')
		->fetchAll(PDO::FETCH_ASSOC);
	return md5(json_encode([$nodes, $edges, $obs]));
}

const CLOCK = 1_800_000_000;

function reset_all(PDO $pdo): void {
	$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
	foreach (['topo_identity_keys', 'topo_observations', 'topo_edges', 'topo_nodes', 'topo_lld_snapshot', 'host_inventory', 'interface', 'items', 'hosts'] as $t) {
		$pdo->exec("DELETE FROM {$t}");
	}
	$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
}

/** A Device created by hand: old data, or a Device another reporter found first. */
function device(PDO $pdo, ?string $chassis, ?string $ip, string $sysname = ''): int {
	$pdo->prepare("INSERT INTO topo_nodes (type, attrs, created_at, updated_at) VALUES ('device', ?, 1, 1)")
		->execute([json_encode(['mac' => null, 'chassis_id' => $chassis, 'mgmt_ip' => $ip, 'sysname' => $sysname, 'vendor' => 'unknown',
			'last_seen' => 1])]);
	$id = (int) $pdo->lastInsertId();
	// the identity keys of a Device made earlier in segment `server` (topology-1-model.md section 6.7.0)
	if ($chassis !== null && $chassis !== '') {
		$pdo->prepare("INSERT INTO topo_identity_keys (device_id, `key`, kind, first_seen, replaced) VALUES (?, ?, 'chassis', 1, 0)")
			->execute([$id, preg_match('/^([0-9a-f]{2}:){5}[0-9a-f]{2}$/i', $chassis) ? 'chassis:mac:'.strtolower($chassis) : 'chassis:local:server:'.$chassis]);
	}
	if ($ip !== null && $ip !== '') {
		$pdo->prepare("INSERT INTO topo_identity_keys (device_id, `key`, kind, first_seen, replaced) VALUES (?, ?, 'ip', 1, 0)")->execute([$id, 'ip:server:'.$ip]);
	}
	return $id;
}

function dev_count(PDO $pdo): int {
	return (int) scalar($pdo, "SELECT COUNT(*) FROM topo_nodes WHERE type='device'");
}

function dev_attr(PDO $pdo, int $id, string $key) {
	return attrs($pdo, $id)[$key] ?? null;
}

/** The reporter R (host 101, chassis $R) with ports 1..4 and the given NEIGHBORS rows. */
function reporter_rows(PDO $pdo, string $R, array $rows, string $role_source = 'lldp', int $clock = CLOCK): void {
	snapshot($pdo, 1001, 101, 1, $clock, array_map(static fn (int $i): array => ['if_index' => $i, 'name' => 'Gi0/'.$i, 'if_type' => '6',
		'admin_status' => '1', 'oper_status' => '1', 'loc_chassis' => $R], [1, 2, 3, 4]));
	snapshot($pdo, 1002, 101, 2, $clock + 10, array_map(static fn (array $r): array => $r + ['loc_chassis' => $R, 'source' => $role_source,
		'rem_port' => 'Gi0/24', 'rem_port_type' => 'interfaceName'], $rows));
}

function observation_of(PDO $pdo, int $if_index): ?array {
	$stmt = $pdo->prepare("SELECT o.outcome, o.device_id, o.edge_id FROM topo_observations o JOIN topo_nodes p ON p.id=o.local_port_id".
		" WHERE o.itemid=1002 AND JSON_EXTRACT(p.attrs,'\$.if_index')=?");
	$stmt->execute([$if_index]);
	return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function shared_list(array $summary): array {
	return $summary['identity_keys_shared']['list'] ?? [];
}


$R = 'a0:00:00:00:00:01';
$C = 'c0:00:00:00:00:0c';

/** Reporter R (host 101, ports 1..4) with a LLDP rule (item 1002) and a CDP rule (item 1003); $lldp / $cdp = the neighbor rows (if_index, rem_*). */
function two_rules(PDO $pdo, string $R, array $lldp, array $cdp, int $clock = CLOCK): void {
	reporter_rows($pdo, $R, [], 'lldp', $clock);
	$fill = static fn (array $rows, string $source): array => array_map(static fn (array $r): array => $r + ['loc_chassis' => $R, 'source' => $source, 'rem_port' => 'Gi0/24', 'rem_port_type' => 'interfaceName'], $rows);
	snapshot($pdo, 1002, 101, 2, $clock + 10, $fill($lldp, 'lldp'));
	snapshot($pdo, 1003, 101, 2, $clock + 10, $fill($cdp, 'cdp'));
}

function lldp_c(string $C, array $extra = []): array {
	return ['if_index' => 1, 'rem_chassis' => $C, 'rem_chassis_type' => 'macAddress'] + $extra;
}

/** What a case is judged by, without ids: the Devices (name, chassis, scope_port set), the observations (item, port, outcome, Device name). */
function lookup_state(PDO $pdo): array {
	$names = [];
	$devices = [];
	foreach ($pdo->query("SELECT id, attrs, represented_by_node_id FROM topo_nodes WHERE type = 'device' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC) as $d) {
		$a = json_decode($d['attrs'], true);
		$names[(int) $d['id']] = ($a['sysname'] ?? '').'/'.($a['chassis_id'] ?? '-');
		$devices[] = $names[(int) $d['id']].(isset($a['scope_port']) ? ' scope_port' : '').($d['represented_by_node_id'] !== null ? ' reporter' : '');
	}
	sort($devices);
	$obs = [];
	foreach ($pdo->query('SELECT itemid, remote_key, outcome, device_id FROM topo_observations ORDER BY itemid, remote_key')->fetchAll(PDO::FETCH_ASSOC) as $o) {
		$obs[] = $o['itemid'].' '.$o['remote_key'].' '.$o['outcome'].' -> '.($o['device_id'] === null ? '-' : $names[(int) $o['device_id']]);
	}
	return ['devices' => $devices, 'obs' => $obs];
}

/** Runs ingest five times; every run after the first must change nothing (P1). */
function five_runs(PDO $pdo, string $dsn, string $user, string $password, string $label): array {
	$r = run_ingest($dsn, $user, $password);
	check($r['code'] === 0, "{$label}: run 1 ok");
	$fp = fingerprint($pdo);
	$tables = md5(json_encode([$pdo->query('SELECT device_id, `key`, kind, first_seen, replaced FROM topo_identity_keys ORDER BY device_id, `key`')->fetchAll(PDO::FETCH_ASSOC)]));
	for ($i = 2; $i <= 5; $i++) {
		run_ingest($dsn, $user, $password);
	}
	check(fingerprint($pdo) === $fp, "{$label}: five runs give the same topo_nodes, topo_edges and topo_observations (P1)");
	check(md5(json_encode([$pdo->query('SELECT device_id, `key`, kind, first_seen, replaced FROM topo_identity_keys ORDER BY device_id, `key`')->fetchAll(PDO::FETCH_ASSOC)])) === $tables, "{$label}: and the same topo_identity_keys (P1)");
	return $r['summary'];
}

function fresh(PDO $pdo): void {
	reset_all($pdo);
	add_host($pdo, 101, 'SwR', 'SwR', '10.0.0.1');
}

echo "\n=== a claim with a chassis: one Device / none -> a new Device / several -> ambiguous (§6.7.1 rows 1-3) ===\n";
fresh($pdo);
reporter_rows($pdo, $R, [lldp_c($C, ['rem_sysname' => 'A'])]);
five_runs($pdo, $dsn, $user, $password, 'chassis, none stored');
check(lookup_state($pdo)['devices'] === ['A/'.$C, 'SwR/'.$R.' reporter'], 'a chassis that no Device has -> a new Device with this chassis');
$d_c = device_id($pdo, $C);
$summary = five_runs($pdo, $dsn, $user, $password, 'chassis, one stored');
check(device_id($pdo, $C) === $d_c && dev_count($pdo) === 2, 'a chassis that one Device has -> that Device');
$dup = device($pdo, $C, null, 'dup');          // old data: the chassis is on two Devices
run_ingest($dsn, $user, $password);
check((string) scalar($pdo, 'SELECT outcome FROM topo_observations') === 'ambiguous' && scalar($pdo, 'SELECT device_id FROM topo_observations') === null, 'a chassis on several Devices -> ambiguous, no Device');

echo "\n=== the match: addresses or names, and the names must not differ (§6.7.1 \"Match\") ===\n";
$cases = [
	// [label, LLDP row extras, CDP row, expected number of Devices (R + ...), the CDP claim joins the LLDP Device?]
	['equal addresses, one name only' => null],
];
$match_cases = [
	'the same address, no names' => [['rem_mgmt_ip' => '10.0.0.5'], ['rem_mgmt_ip' => '10.0.0.5'], true],
	'different addresses, equal names (LLDP sends its management address, CDP the address of the sending interface)' => [['rem_mgmt_ip' => '10.0.0.5', 'rem_sysname' => 'SW-X'], ['rem_mgmt_ip' => '10.0.9.9', 'rem_sysname' => 'SW-X'], true],
	'the name with a domain and in capitals (SW-X.lab = sw-x)' => [['rem_sysname' => 'SW-X.lab'], ['rem_sysname' => 'sw-x'], true],
	'the same address, different names (NAT, a factory IP) do not match' => [['rem_mgmt_ip' => '192.168.1.1', 'rem_sysname' => 'SW-X'], ['rem_mgmt_ip' => '192.168.1.1', 'rem_sysname' => 'PHONE-Y'], false],
	'different addresses and different names do not match' => [['rem_mgmt_ip' => '10.0.0.5', 'rem_sysname' => 'SW-X'], ['rem_mgmt_ip' => '10.0.0.6', 'rem_sysname' => 'PHONE-Y'], false],
	'no address and no name on one side: nothing in common, no match' => [['rem_sysname' => 'SW-X'], ['rem_mgmt_ip' => '10.0.0.6'], false],
	'a loopback address never matches: with no name the claim has no identity, no Device (rows_no_identity)' => [['rem_mgmt_ip' => '127.0.17.5', 'rem_sysname' => ''], ['rem_mgmt_ip' => '127.0.17.5'], 'none'],
	'a link-local address never matches (the same)' => [['rem_mgmt_ip' => '169.254.1.1'], ['rem_mgmt_ip' => '169.254.1.1'], 'none'],
	'the unspecified address never matches (the same)' => [['rem_mgmt_ip' => '0.0.0.0'], ['rem_mgmt_ip' => '0.0.0.0'], 'none'],
	'a loopback address and a name: the name finds the Device again' => [['rem_mgmt_ip' => '127.0.17.5', 'rem_sysname' => 'SW-X'], ['rem_mgmt_ip' => '127.0.17.5', 'rem_sysname' => 'SW-X'], true],
	'a loopback address and different names: no match' => [['rem_mgmt_ip' => '127.0.17.5', 'rem_sysname' => 'SW-X'], ['rem_mgmt_ip' => '127.0.17.5', 'rem_sysname' => 'PHONE-Y'], false],
	'a CDP device id that is the chassis MAC (HP ProCurve, F-1) is that chassis, whatever its spelling' => [[], ['rem_sysname' => strtoupper(str_replace(':', '-', $C))], true],
	'a CDP device id in MAC form that is another MAC is a hardware ID of its own (2.48): a Device with that chassis, whatever the address' => [['rem_mgmt_ip' => '10.0.0.5', 'rem_sysname' => 'SW-X'], ['rem_mgmt_ip' => '10.0.0.5', 'rem_sysname' => 'aa:bb:cc:dd:ee:ff'], 'chassis'],
	'a CDP device id of 12 hex digits is not a MAC form (normalization 4.1): a name' => [['rem_sysname' => 'SW-X'], ['rem_sysname' => strtolower(str_replace(':', '', $C))], false],
	'a binary CDP device id with its last 00 byte, 6 octets, is the chassis (SW12, task 059)' => [['rem_sysname' => 'SW12', 'rem_mgmt_ip' => '10.0.0.2'], ['rem_sysname' => $C, 'rem_mgmt_ip' => '10.0.0.2'], 'same_chassis'],
	'the same id with the last byte cut (5 octets) is not a MAC: a name that differs from the sysname' => [['rem_sysname' => 'SW12'], ['rem_sysname' => substr($C, 0, 14)], false],
	'the NX-OS device id name(serial) and the LLDP sysname, no common address (task 058)' => [['rem_sysname' => 'N9K-1'], ['rem_sysname' => 'N9K-1(FDO2101X0AB)'], true],
	'a multicast address never matches (task 058)' => [['rem_man_addrs' => 'lldp:1.4.224.0.0.251'], ['rem_man_addrs' => 'lldp:1.4.224.0.0.251'], 'none'],
];
foreach ($match_cases as $label => [$lldp_extra, $cdp_row, $joins]) {
	fresh($pdo);
	two_rules($pdo, $R, [lldp_c($C, $lldp_extra)], [['if_index' => 1] + $cdp_row]);
	five_runs($pdo, $dsn, $user, $password, $label);
	$state = lookup_state($pdo);
	check(count($state['devices']) === ($joins === true || $joins === 'none' || $joins === 'same_chassis' ? 2 : 3), $label.': '.($joins === true || $joins === 'same_chassis' ? 'one Device for both claims' : ($joins === 'none' ? 'no Device for the CDP claim' : ($joins === 'chassis' ? 'a Device of its own with that chassis' : 'two Devices, the CDP claim has one of its own (scope_port)'))).' '.json_encode($state['devices']));
	if ($joins === 'chassis') {
		check(count(array_filter($state['devices'], static fn (string $d): bool => str_starts_with($d, '/aa:bb:cc:dd:ee:ff'))) === 1, $label.': the Device has the MAC of the device id as its chassis and no name');
	}
	if ($joins === false) {
		check(count(array_filter($state['devices'], static fn (string $d): bool => str_ends_with($d, ' scope_port'))) === 1, $label.': only the Device without a chassis has a scope_port');
	}
}

echo "\n=== the Devices of the port: one matches / several match / none (§6.7.1 rows 6-8) ===\n";
fresh($pdo);
two_rules($pdo, $R, [], [['if_index' => 1, 'rem_mgmt_ip' => '10.0.0.40', 'rem_sysname' => 'AP-1']]);
run_ingest($dsn, $user, $password);
$port = (int) scalar($pdo, "SELECT JSON_EXTRACT(attrs, '\$.scope_port') FROM topo_nodes WHERE type='device' AND JSON_UNQUOTE(JSON_EXTRACT(attrs,'\$.sysname')) = 'AP-1'");
check($port > 0, 'the claim without a chassis made a Device with scope_port = the local port');
// two more Devices of the same port, made by hand (old data): one with the address 10.0.0.41, one with the name AP-2 and the same address
foreach ([['AP-3', '10.0.0.41'], ['AP-2', '10.0.0.41']] as [$name, $ip]) {
	$id = device($pdo, null, null, $name);
	$a = attrs($pdo, $id);
	$a['mgmt_ip'] = $ip;
	$a['scope_port'] = $port;
	$pdo->prepare('UPDATE topo_nodes SET attrs = ? WHERE id = ?')->execute([json_encode($a), $id]);
}
$count = dev_count($pdo);
snapshot($pdo, 1003, 101, 2, CLOCK + 50, [['if_index' => 1, 'rem_mgmt_ip' => '10.0.0.41', 'source' => 'cdp', 'loc_chassis' => $R, 'rem_port' => 'e0', 'rem_port_type' => 'interfaceName']]);
run_ingest($dsn, $user, $password);
check((string) scalar($pdo, 'SELECT outcome FROM topo_observations') === 'ambiguous' && dev_count($pdo) === $count, 'two Devices of the port match the claim (the address) -> ambiguous, nothing is created');
snapshot($pdo, 1003, 101, 2, CLOCK + 60, [['if_index' => 1, 'rem_mgmt_ip' => '10.0.0.41', 'rem_sysname' => 'AP-2', 'source' => 'cdp', 'loc_chassis' => $R, 'rem_port' => 'e0', 'rem_port_type' => 'interfaceName']]);
run_ingest($dsn, $user, $password);
check((string) scalar($pdo, 'SELECT outcome FROM topo_observations') !== 'ambiguous' && (string) scalar($pdo, 'SELECT device_id FROM topo_observations') === (string) scalar($pdo, "SELECT id FROM topo_nodes WHERE JSON_UNQUOTE(JSON_EXTRACT(attrs,'\$.sysname')) = 'AP-2'"),
	'the name tells them apart: only AP-2 matches (the name AP-3 differs) -> that Device');
snapshot($pdo, 1003, 101, 2, CLOCK + 70, [['if_index' => 2, 'rem_mgmt_ip' => '10.0.0.41', 'rem_sysname' => 'AP-2', 'source' => 'cdp', 'loc_chassis' => $R, 'rem_port' => 'e0', 'rem_port_type' => 'interfaceName']]);
run_ingest($dsn, $user, $password);
check(dev_count($pdo) === $count + 1, 'the same name and address on ANOTHER port of the same reporter -> a new Device (the search covers one port)');

echo "\n=== the reporter's SNMP address (§6.7.1 row 4) ===\n";
$h_rows = static fn (string $name): array => [['if_index' => 1, 'rem_mgmt_ip' => '10.0.0.7'] + ($name === '' ? [] : ['rem_sysname' => $name])];
// 2.49: the reporter's name is only a sysname from its own data, never the Zabbix host name; the topology rows do not carry it today, so the address alone decides (the
// VRRP example of §6.7.1 is not protected until lldpLocSysName is collected, §18)
foreach (['no name' => ['', true], 'a name (the host name of H is not its sysname: not compared)' => ['H', true], 'another name (a VRRP address of a router: not protected yet)' => ['R2', true]] as $label => [$name, $is_h]) {
	fresh($pdo);
	add_host($pdo, 102, 'H', 'H', '10.0.0.7');
	snapshot($pdo, 2001, 102, 1, CLOCK, [['if_index' => 1, 'name' => 'Gi0/1', 'if_type' => '6', 'admin_status' => '1', 'oper_status' => '1', 'loc_chassis' => 'b0:00:00:00:00:77']]);
	two_rules($pdo, $R, [], $h_rows($name));
	five_runs($pdo, $dsn, $user, $password, "SNMP address of H, {$label}");
	check((count(lookup_state($pdo)['devices']) === ($is_h ? 2 : 3)), "SNMP address of H, {$label}: ".($is_h ? 'the Device of H' : 'not H: a Device of the port'));
}
fresh($pdo);
add_host($pdo, 102, 'H1', 'H1', '10.0.0.7');
add_host($pdo, 103, 'H2', 'H2', '10.0.0.7');
foreach ([102 => 'b0:00:00:00:00:77', 103 => 'b0:00:00:00:00:78'] as $hid => $chassis) {
	snapshot($pdo, $hid * 10 + 1, $hid, 1, CLOCK, [['if_index' => 1, 'name' => 'Gi0/1', 'if_type' => '6', 'admin_status' => '1', 'oper_status' => '1', 'loc_chassis' => $chassis]]);
}
two_rules($pdo, $R, [], $h_rows(''));
five_runs($pdo, $dsn, $user, $password, 'two reporters with one SNMP address');
check(count(lookup_state($pdo)['devices']) === 4, 'two reporters have the address: the row does not apply, the claim gets a Device of the port (R, H1, H2 and that)');

echo "\n=== a claim with no chassis, address, name or CDP device id makes no Device and no observation ===\n";
fresh($pdo);
two_rules($pdo, $R, [['if_index' => 1, 'rem_port' => 'e0']], []);
$summary = five_runs($pdo, $dsn, $user, $password, 'no identity');
check(dev_count($pdo) === 1 && (int) scalar($pdo, 'SELECT COUNT(*) FROM topo_observations') === 0 && ($summary['rows_no_identity']['count'] ?? 0) === 1, 'counted in rows_no_identity, nothing else');

echo "\n=== pass B order: the claims with a chassis first (rank 0), whatever the order of the rules ===\n";
foreach (['LLDP item has the lower id' => [1002, 1003], 'CDP item has the lower id' => [1003, 1002]] as $label => [$lldp_item, $cdp_item]) {
	fresh($pdo);
	reporter_rows($pdo, $R, []);
	snapshot($pdo, $lldp_item, 101, 2, CLOCK + 10, [lldp_c($C, ['rem_sysname' => 'SW-X', 'source' => 'lldp', 'loc_chassis' => $R, 'rem_port' => 'Gi0/24', 'rem_port_type' => 'interfaceName'])]);
	snapshot($pdo, $cdp_item, 101, 2, CLOCK + 10, [['if_index' => 1, 'rem_sysname' => 'SW-X', 'source' => 'cdp', 'loc_chassis' => $R, 'rem_port' => 'Gi0/24', 'rem_port_type' => 'interfaceName']]);
	five_runs($pdo, $dsn, $user, $password, $label);
	check(dev_count($pdo) === 2, "{$label}: one Device for the two claims");
}

echo "\n=== no automatic join: a Device is never given a chassis by a later row, ingest never deletes a Device (§6.7.1, P3) ===\n";
fresh($pdo);
$old = device($pdo, null, null, 'N');
$a = attrs($pdo, $old);
$a['scope_port'] = 1;
$pdo->prepare('UPDATE topo_nodes SET attrs = ? WHERE id = ?')->execute([json_encode($a), $old]);
two_rules($pdo, $R, [lldp_c($C, ['rem_sysname' => 'N', 'rem_mgmt_ip' => '10.5.5.5'])], [['if_index' => 1, 'rem_sysname' => 'N', 'rem_mgmt_ip' => '10.5.5.5']]);
$summary = five_runs($pdo, $dsn, $user, $password, 'chassis row and a Device with the same name and address');
check(dev_attr($pdo, $old, 'chassis_id') === null && dev_count($pdo) === 3, 'the Device without a chassis stays as it was (no chassis, not deleted); the claim with a chassis has its own Device');
check(!isset($summary['devices_joined']) && count(attrs($pdo, $old)['duplicate_candidates'] ?? []) <= 1, 'no devices_joined counter');

echo "\n=== NX-OS (task 058, model 2.49): LLDP sysname N9K-1, CDP device id N9K-1(FDO2101X0AB), no common address, one port: one Device, one link ===\n";
fresh($pdo);
two_rules($pdo, $R, [lldp_c($C, ['rem_sysname' => 'N9K-1'])], [['if_index' => 1, 'rem_sysname' => 'N9K-1(FDO2101X0AB)']]);
five_runs($pdo, $dsn, $user, $password, 'NX-OS');
check(dev_count($pdo) === 2 && (int) scalar($pdo, "SELECT COUNT(*) FROM topo_edges WHERE type = 'physical_link'") === 1 && array_unique($pdo->query('SELECT outcome FROM topo_observations')->fetchAll(PDO::FETCH_COLUMN)) === ['applied'],
	'one Device, one link, both claims applied (the name key drops the final group in parentheses)');

echo "\n=== the office cases of model 2.48 (task 059): SW12 seen by LLDP and by CDP with a binary device id; the FortiAP factory address ===\n";
$SW12 = 'a8:52:d4:53:11:00';
fresh($pdo);
add_host($pdo, 102, 'SW12', 'SW12', '10.0.0.2');
snapshot($pdo, 2001, 102, 1, CLOCK, [['if_index' => 1, 'name' => '1/1/1', 'if_type' => '6', 'admin_status' => '1', 'oper_status' => '1', 'loc_chassis' => $SW12]]);
two_rules($pdo, $R, [['if_index' => 1, 'rem_chassis' => $SW12, 'rem_chassis_type' => 'macAddress', 'rem_sysname' => 'SW12-10-CORE-6000-48G', 'rem_man_addrs' => 'lldp:1.4.127.0.17.2']],
	[['if_index' => 1, 'rem_sysname' => $SW12, 'rem_man_addrs' => 'cdp:1:7f:00:11:02']]);
five_runs($pdo, $dsn, $user, $password, 'SW12 by LLDP and CDP');
$sw12_device = device_id($pdo, $SW12);
check(dev_count($pdo) === 2 && (int) scalar($pdo, 'SELECT COUNT(DISTINCT device_id) FROM topo_observations') === 1 && (int) scalar($pdo, 'SELECT device_id FROM topo_observations LIMIT 1') === $sw12_device,
	'SW12 (a reporter, chassis a8:52:d4:53:11:00) seen by LLDP and by CDP (device id = the chassis MAC as 6 bytes): every claim is SW12\'s Device, no other Device');
check(!array_filter(attrs($pdo, $sw12_device)['duplicate_candidates'] ?? [], static fn (array $c): bool => $c['reason'] === 'same_name'), 'no same_name');
check(dev_attr($pdo, $sw12_device, 'mgmt_ip') === '10.0.0.2', 'the mgmt_ip of SW12 is its own SNMP address, not the loopback 127.0.17.2 of the neighbor rows (normalization 0.9 section 4.4)');

fresh($pdo);
$forti = [];
foreach ([1 => 'd4:b4:c0:62:ef:10', 2 => 'd4:b4:c0:62:ee:10', 3 => 'd4:b4:c0:62:e9:10', 4 => 'd4:b4:c0:62:c0:70'] as $port => $mac) {
	$forti[] = ['if_index' => $port, 'rem_chassis' => $mac, 'rem_chassis_type' => 'macAddress', 'rem_sysname' => 'FortiAP-231K', 'rem_mgmt_ip' => '192.168.1.2'];
}
reporter_rows($pdo, $R, $forti);
five_runs($pdo, $dsn, $user, $password, 'four FortiAP');
check(dev_count($pdo) === 5 && !(int) scalar($pdo, "SELECT COUNT(*) FROM topo_nodes WHERE type = 'device' AND attrs LIKE '%duplicate_candidates%'"),
	'four Devices with four MAC chassis and one address 192.168.1.2 (FortiAP factory address): four devices, no same_address (2.48)');

echo "\nAll far Device lookup tests passed.\n";
