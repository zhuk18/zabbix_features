#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * test_shared_identifier.php — topology-1-model.md "Far Device" (§6.7.1), "Shared identifier (FR 4.h)" (§6.7.4) and
 * "Automatic MAC match" (§10.2): look a neighbor or a reporter up by the strongest key it has; a weaker key matches only a
 * Device that has no value for the stronger key; more than one match at any step is a shared identifier (nothing created
 * or changed); a stored stronger key is never replaced by a different value.
 *
 * Same method as test_snapshot_ingest.php. Writes rows straight into topo_lld_snapshot (what the server's LLD worker would store) and runs the REAL
 * ingest.php as a subprocess after every change, so the actual CLI path is exercised end to end — no test-only
 * hook, no reimplementation of the algorithm here.
 *
 * Usage: test_shared_identifier.php <PDO DSN> <database user> [password]
 * Expects a THROWAWAY database: it drops and recreates the topology tables and minimal stand-ins for the Zabbix
 * tables they reference (hosts, proxy, items, interface, host_inventory, topo_lld_snapshot). Never point it at a
 * real Zabbix database.
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
$X = 'b0:00:00:00:00:0a';
$Y = 'b0:00:00:00:00:0b';
$IP = '192.168.1.1';

// ============================================================================================================
echo "\n=== 1: factory IP — Device A (chassis X, IP 192.168.1.1); a row with chassis Y and the same IP (2.47: an address finds no Device) ===\n";
reset_all($pdo);
add_host($pdo, 101, 'SwR', 'SwR', '10.0.0.1');
$A = device($pdo, $X, $IP, 'A');
reporter_rows($pdo, $R, [['if_index' => 1, 'rem_chassis' => $Y, 'rem_chassis_type' => 'macAddress', 'rem_mgmt_ip' => $IP, 'rem_sysname' => 'NewBox'],
	['if_index' => 2, 'rem_chassis' => $X, 'rem_chassis_type' => 'macAddress', 'rem_mgmt_ip' => $IP, 'rem_sysname' => 'A']]);
for ($i = 1; $i <= 5; $i++) {
	$r = run_ingest($dsn, $user, $password);
	check($r['code'] === 0, "run {$i}: ingest ok");
	check(dev_attr($pdo, $A, 'chassis_id') === $X && dev_attr($pdo, $A, 'mgmt_ip') === $IP, "run {$i}: Device A keeps chassis X and its IP");
}
$new = device_id($pdo, $Y);
check($new !== null && $new !== $A, 'the row got a NEW Device (chassis Y)');
check(dev_count($pdo) === 3, 'three Devices: the reporter, A and the new one');
check(dev_attr($pdo, $new, 'mgmt_ip') === $IP, 'the new Device carries the IP as well (both Devices now have it)');
check(observation_of($pdo, 1)['device_id'] == $new, 'the observation points to the new Device');
$cand = static fn (int $id): array => array_values(array_map(static fn (array $c): array => [$c['reason'], $c['device_id'], $c['key'], $c['value']], attrs($pdo, $id)['duplicate_candidates'] ?? []));
check($cand($A) === [] && $cand($new) === [], 'two Devices with MAC chassis and one factory address are two devices: no same_address (2.48)');

echo "  -- a row with chassis X finds A --\n";
reporter_rows($pdo, $R, [['if_index' => 1, 'rem_chassis' => $Y, 'rem_chassis_type' => 'macAddress', 'rem_mgmt_ip' => $IP, 'rem_sysname' => 'NewBox'],
	['if_index' => 2, 'rem_chassis' => $X, 'rem_chassis_type' => 'macAddress', 'rem_mgmt_ip' => $IP, 'rem_sysname' => 'A']], 'lldp', CLOCK + 100);
run_ingest($dsn, $user, $password);
check(observation_of($pdo, 2)['device_id'] == $A && dev_count($pdo) === 3, 'chassis X: Device A, nothing created');

echo "  -- a row with only that IP and a name: a Device of its own (no key is shared, nothing is ambiguous) --\n";
reporter_rows($pdo, $R, [['if_index' => 1, 'rem_chassis' => $Y, 'rem_chassis_type' => 'macAddress', 'rem_mgmt_ip' => $IP, 'rem_sysname' => 'NewBox'],
	['if_index' => 2, 'rem_chassis' => $X, 'rem_chassis_type' => 'macAddress', 'rem_mgmt_ip' => $IP, 'rem_sysname' => 'A'],
	['if_index' => 3, 'rem_mgmt_ip' => $IP, 'rem_sysname' => 'who']], 'lldp', CLOCK + 200);
$before = dev_count($pdo);
$r = run_ingest($dsn, $user, $password);
$o = observation_of($pdo, 3);
check($o !== null && $o['outcome'] !== 'ambiguous' && $o['device_id'] !== null && dev_count($pdo) === $before + 1, 'the claim without a chassis has a Device of its own (scope_port = its port)');
check(dev_attr($pdo, $A, 'chassis_id') === $X && dev_attr($pdo, $new, 'chassis_id') === $Y, 'no chassis_id changed');
check(($r['summary']['identity_keys_shared']['count'] ?? 0) === 0 && shared_list($r['summary']) === [], 'identity_keys_shared has chassis keys only: none');
$who = (int) $o['device_id'];
check(count($cand($who)) === 2 && $cand($A) === [['same_address', $who, 'address', $IP]] && $cand($new) === [['same_address', $who, 'address', $IP]], 'same_address between the Device without a MAC chassis and each of the two others, not between the two with MAC chassis (2.48)');
for ($i = 1; $i <= 4; $i++) {
	run_ingest($dsn, $user, $password);
}
check(dev_attr($pdo, $A, 'chassis_id') === $X && dev_attr($pdo, $new, 'chassis_id') === $Y && dev_count($pdo) === $before + 1,
	'5 runs: no chassis_id changes, no new Device');

// ============================================================================================================
echo "\n=== 2: LLDP and CDP on one port: the claims match, or they are two neighbors (model §6.7.1, 2.47) ===\n";
/** R's LLDP rule (item 1002) and CDP rule (item 1003) with the given rows; the neighbor rows get source and loc_chassis. */
function two_rules(PDO $pdo, string $R, array $lldp, array $cdp, int $clock = CLOCK): void {
	reporter_rows($pdo, $R, [], 'lldp', $clock);
	$fill = static fn (array $rows, string $source): array => array_map(static fn (array $r): array => $r + ['loc_chassis' => $R, 'source' => $source, 'rem_port' => 'Gi0/24', 'rem_port_type' => 'interfaceName'], $rows);
	snapshot($pdo, 1002, 101, 2, $clock + 10, $fill($lldp, 'lldp'));
	snapshot($pdo, 1003, 101, 2, $clock + 10, $fill($cdp, 'cdp'));
}
$C = 'c0:00:00:00:00:0c';
reset_all($pdo);
add_host($pdo, 101, 'SwR', 'SwR', '10.0.0.1');
two_rules($pdo, $R, [['if_index' => 1, 'rem_chassis' => $C, 'rem_chassis_type' => 'macAddress', 'rem_mgmt_ip' => '10.0.0.5', 'rem_sysname' => 'SW-X']],
	[['if_index' => 1, 'rem_mgmt_ip' => '10.0.0.5']]);
run_ingest($dsn, $user, $password);
$r = run_ingest($dsn, $user, $password);
check(dev_count($pdo) === 2 && $r['summary']['devices_created'] === 0 && count(edges($pdo)) === 1, 'LLDP chassis C + address 10.0.0.5, CDP no chassis + the same address, one port -> both claims are the Device of C, one link');
check(array_unique($pdo->query('SELECT device_id FROM topo_observations')->fetchAll(PDO::FETCH_COLUMN)) === [(string) device_id($pdo, $C)] || count(array_unique($pdo->query('SELECT device_id FROM topo_observations')->fetchAll(PDO::FETCH_COLUMN))) === 1, 'both observations point at the Device of C');

reset_all($pdo);
add_host($pdo, 101, 'SwR', 'SwR', '10.0.0.1');
two_rules($pdo, $R, [['if_index' => 1, 'rem_chassis' => $C, 'rem_chassis_type' => 'macAddress', 'rem_mgmt_ip' => '10.0.0.5', 'rem_sysname' => 'SW-X']],
	[['if_index' => 1, 'rem_mgmt_ip' => '10.0.0.5', 'rem_sysname' => 'PHONE-Y']]);
run_ingest($dsn, $user, $password);
$r = run_ingest($dsn, $user, $password);
$phone = (int) scalar($pdo, "SELECT id FROM topo_nodes WHERE type='device' AND JSON_UNQUOTE(JSON_EXTRACT(attrs,'\$.sysname')) = 'PHONE-Y'");
$port1 = port_id($pdo, (int) scalar($pdo, "SELECT id FROM topo_nodes WHERE type='device' AND JSON_UNQUOTE(JSON_EXTRACT(attrs,'\$.chassis_id')) = ?", [$R]), 1);
check(dev_count($pdo) === 3 && $phone > 0 && attrs($pdo, $phone)['scope_port'] === $port1, 'LLDP chassis C name SW-X, CDP no chassis name PHONE-Y, the same address -> no join: a Device with scope_port = the port for the CDP claim');
check(array_unique($pdo->query('SELECT outcome FROM topo_observations')->fetchAll(PDO::FETCH_COLUMN)) === ['ambiguous'] && count(edges($pdo)) === 0, '§8.5: two neighbors on one port, no link -> both claims ambiguous');
check($r['summary']['devices_created'] === 0, 'the second run creates nothing');

// ============================================================================================================
echo "\n=== 3: a CDP claim that names its neighbor as text, and the reporter's own SNMP address (model §6.7.1, 2.47) ===\n";
reset_all($pdo);
add_host($pdo, 101, 'SwR', 'SwR', '10.0.0.1');
two_rules($pdo, $R, [['if_index' => 1, 'rem_chassis' => 'PC-7', 'rem_chassis_type' => 'local']], [['if_index' => 1, 'rem_sysname' => 'PC-7']]);
run_ingest($dsn, $user, $password);
check(dev_count($pdo) === 2 && count(edges($pdo)) === 1, 'CDP device id = the LLDP chassis id as text (HP ProCurve, F-1), no address -> the Device of the LLDP claim');
reset_all($pdo);
add_host($pdo, 101, 'SwR', 'SwR', '10.0.0.1');
add_host($pdo, 102, 'H', 'H', '10.0.0.7');
snapshot($pdo, 2001, 102, 1, CLOCK, [['if_index' => 1, 'name' => 'Gi0/1', 'if_type' => '6', 'admin_status' => '1', 'oper_status' => '1', 'loc_chassis' => 'b0:00:00:00:00:77']]);
two_rules($pdo, $R, [], [['if_index' => 1, 'rem_mgmt_ip' => '10.0.0.7', 'rem_sysname' => 'H']]);
run_ingest($dsn, $user, $password);
$h_dev = device_id($pdo, 'b0:00:00:00:00:77');
check($h_dev !== null && (int) scalar($pdo, 'SELECT device_id FROM topo_observations') === $h_dev && dev_count($pdo) === 2, 'CDP no chassis, address = the SNMP interface address of reporter H, name = H\'s sysname -> the Device of H, nothing created');
reset_all($pdo);
add_host($pdo, 101, 'SwR', 'SwR', '10.0.0.1');
add_host($pdo, 102, 'H', 'H', '10.0.0.7');
snapshot($pdo, 2001, 102, 1, CLOCK, [['if_index' => 1, 'name' => 'Gi0/1', 'if_type' => '6', 'admin_status' => '1', 'oper_status' => '1', 'loc_chassis' => 'b0:00:00:00:00:77']]);
two_rules($pdo, $R, [], [['if_index' => 1, 'rem_mgmt_ip' => '10.0.0.7', 'rem_sysname' => 'R2']]);
run_ingest($dsn, $user, $password);
$h_dev = device_id($pdo, 'b0:00:00:00:00:77');
$o = $pdo->query('SELECT outcome, device_id FROM topo_observations')->fetch(PDO::FETCH_ASSOC);
// 2.49: the reporter has no name of its own to compare (the Zabbix host name is never used), so the address alone decides; a VRRP address shared with a router that sends
// another name is not protected until lldpLocSysName is collected (model §6.7.1)
check((int) $o['device_id'] === $h_dev && dev_count($pdo) === 2, 'name R2 with the SNMP address of H: the Device of H (the reporter has no sysname of its own, 2.49)');

// ============================================================================================================
echo "\n=== 3b: a Device without a chassis keeps its identity while its name or one address stays (model §6.7.1, 2.47) ===\n";
reset_all($pdo);
add_host($pdo, 101, 'SwR', 'SwR', '10.0.0.1');
two_rules($pdo, $R, [], [['if_index' => 1, 'rem_mgmt_ip' => '10.0.0.20', 'rem_sysname' => 'AP-7']]);
run_ingest($dsn, $user, $password);
$ap = (int) scalar($pdo, "SELECT id FROM topo_nodes WHERE type='device' AND JSON_UNQUOTE(JSON_EXTRACT(attrs,'\$.sysname')) = 'AP-7'");
two_rules($pdo, $R, [], [['if_index' => 1, 'rem_mgmt_ip' => '10.0.0.21', 'rem_sysname' => 'AP-7']], CLOCK + 100);
run_ingest($dsn, $user, $password);
check($ap > 0 && dev_count($pdo) === 2 && (int) scalar($pdo, 'SELECT device_id FROM topo_observations') === $ap && dev_attr($pdo, $ap, 'mgmt_ip') === '10.0.0.21', 'D (name AP-7, 10.0.0.20); the next snapshot AP-7, 10.0.0.21 -> D, no new Device');

echo "  -- the same neighbor without a chassis seen by two reporters: two Devices, marks, and the operator's answers --\n";
reset_all($pdo);
add_host($pdo, 101, 'SwR', 'SwR', '10.0.0.1');
add_host($pdo, 102, 'SwS', 'SwS', '10.0.0.2');
snapshot($pdo, 1001, 101, 1, CLOCK, [['if_index' => 1, 'name' => 'Gi0/1', 'if_type' => '6', 'admin_status' => '1', 'oper_status' => '1', 'loc_chassis' => $R]]);
snapshot($pdo, 2001, 102, 1, CLOCK, [['if_index' => 1, 'name' => 'Gi0/1', 'if_type' => '6', 'admin_status' => '1', 'oper_status' => '1', 'loc_chassis' => 'b0:00:00:00:00:02']]);
$cdp_row = ['rem_mgmt_ip' => '10.0.0.30', 'rem_sysname' => 'PRINTER', 'rem_port' => 'e0', 'rem_port_type' => 'interfaceName', 'source' => 'cdp'];
snapshot($pdo, 1003, 101, 2, CLOCK + 10, [['if_index' => 1, 'loc_chassis' => $R] + $cdp_row]);
snapshot($pdo, 2003, 102, 2, CLOCK + 10, [['if_index' => 1, 'loc_chassis' => 'b0:00:00:00:00:02'] + $cdp_row]);
run_ingest($dsn, $user, $password);
$printers = array_map('intval', $pdo->query("SELECT id FROM topo_nodes WHERE type='device' AND JSON_UNQUOTE(JSON_EXTRACT(attrs,'\$.sysname')) = 'PRINTER' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN));
check(count($printers) === 2, 'two Devices for the same printer (one per reporter and port)');
$reasons = static fn (int $id): array => array_values(array_unique(array_column(attrs($pdo, $id)['duplicate_candidates'] ?? [], 'reason')));
check($reasons($printers[0]) === ['same_address', 'same_name'] && $reasons($printers[1]) === ['same_address', 'same_name'], 'same_address and same_name on both');
echo "  -- Not the same device: no candidate for the pair on the later runs --\n";
foreach ([[$printers[0], $printers[1]], [$printers[1], $printers[0]]] as [$one, $other]) {
	$a = attrs($pdo, $one);
	$a['duplicate_dismissed'] = [['device_id' => $other, 'at' => 1, 'by' => 'Admin']];
	$pdo->prepare('UPDATE topo_nodes SET attrs = ? WHERE id = ?')->execute([json_encode($a), $one]);
}
$dismissed_before = [attrs($pdo, $printers[0])['duplicate_dismissed'], attrs($pdo, $printers[1])['duplicate_dismissed']];
run_ingest($dsn, $user, $password);
check(!isset(attrs($pdo, $printers[0])['duplicate_candidates']) && !isset(attrs($pdo, $printers[1])['duplicate_candidates']), 'dismissed on both: no candidate for the pair');
check([attrs($pdo, $printers[0])['duplicate_dismissed'], attrs($pdo, $printers[1])['duplicate_dismissed']] === $dismissed_before, 'ingest keeps duplicate_dismissed byte-identical');

// ============================================================================================================
echo "\n=== 4: two Devices with the same chassis_id (old data) — a row with it is ambiguous, the IP is not tried ===\n";
reset_all($pdo);
add_host($pdo, 101, 'SwR', 'Switch R', '10.0.0.1');
$d1 = device($pdo, $X, '10.3.3.1', 'old1');
$d2 = device($pdo, $X, '10.3.3.2', 'old2');
reporter_rows($pdo, $R, [['if_index' => 1, 'rem_chassis' => $X, 'rem_chassis_type' => 'macAddress', 'rem_mgmt_ip' => '10.3.3.1', 'rem_sysname' => 'x']]);
$r = run_ingest($dsn, $user, $password);
$o = observation_of($pdo, 1);
check($o['outcome'] === 'ambiguous' && $o['device_id'] === null, 'ambiguous, no Device');
check(dev_count($pdo) === 3, 'no Device created (the reporter and the two old ones)');
$list = shared_list($r['summary']);
check(count($list) === 1 && $list[0]['key'] === 'chassis_id' && $list[0]['value'] === $X && $list[0]['node_ids'] === [$d1, $d2],
	'identity_keys_shared lists the chassis, not the IP');
check(dev_attr($pdo, $d1, 'sysname') === 'old1' && dev_attr($pdo, $d2, 'sysname') === 'old2', 'neither Device was changed');

// ============================================================================================================
echo "\n=== 5: a reporter whose loc_chassis is on two Devices gets its own Device (2.43) ===\n";
/** What a run left, without ids: per Device (named by sysname) the chassis, the representing host and the duplicate candidates (reason, key, value, other Device's sysname). */
function key_shared_state(PDO $pdo): array {
	$names = [];
	foreach ($pdo->query("SELECT id, attrs FROM topo_nodes WHERE type='device'")->fetchAll(PDO::FETCH_ASSOC) as $row) {
		$names[(int) $row['id']] = json_decode($row['attrs'], true)['sysname'] ?? '';
	}
	$state = [];
	foreach ($pdo->query("SELECT d.id, d.attrs, h.host_ref FROM topo_nodes d LEFT JOIN topo_nodes h ON h.id = d.represented_by_node_id WHERE d.type='device'")
			->fetchAll(PDO::FETCH_ASSOC) as $row) {
		$attrs = json_decode($row['attrs'], true);
		$candidates = [];
		foreach ($attrs['duplicate_candidates'] ?? [] as $c) {
			$candidates[] = [$c['reason'], $c['key'], $c['value'], $names[(int) $c['device_id']] ?? '?'];
		}
		sort($candidates);
		$state[$names[(int) $row['id']].'#'.($attrs['chassis_id'] ?? '')] = [$row['host_ref'], $candidates];
	}
	ksort($state);
	return $state;
}
function key_shared_setup(PDO $pdo, string $X, bool $second_reporter): array {
	reset_all($pdo);
	$d1 = device($pdo, $X, '10.3.3.1', 'old1');
	$d2 = device($pdo, $X, '10.3.3.2', 'old2');
	add_host($pdo, 102, 'SwDup', 'Switch Dup', '10.0.0.2');
	snapshot($pdo, 2001, 102, 1, CLOCK, [['if_index' => 1, 'name' => 'Gi0/1', 'if_type' => '6', 'admin_status' => '1', 'oper_status' => '1',
		'loc_chassis' => $X]]);
	if ($second_reporter) {
		// a reporter without a chassis whose SNMP IP is old1's address: the address is not looked up (2.47); it gets its own Device without a key, whatever the order
		add_host($pdo, 103, 'SwIp', 'Switch Ip', '10.3.3.1');
		snapshot($pdo, 2002, 103, 1, CLOCK, [['if_index' => 1, 'name' => 'Gi0/1', 'if_type' => '6', 'admin_status' => '1', 'oper_status' => '1']]);
	}
	return [$d1, $d2];
}
[$d1, $d2] = key_shared_setup($pdo, $X, false);
$r = run_ingest($dsn, $user, $password);
$new = (int) scalar($pdo, "SELECT d.id FROM topo_nodes d JOIN topo_nodes h ON h.id = d.represented_by_node_id WHERE d.type='device' AND h.host_ref = 102 AND d.represented_by_matched_by = 'reporter_self'");
check($new > 0 && $new !== $d1 && $new !== $d2 && dev_count($pdo) === 3, 'a third Device is made for the reporter, represented_by set, matched_by reporter_self');
check(($r['summary']['reporters_skipped']['identity_key_shared'] ?? 0) === 0 && empty($r['summary']['reporters_skipped']), 'the reporter is not skipped');
check(dev_attr($pdo, $new, 'chassis_id') === $X, 'the new Device carries the reporter\'s chassis');
check((int) scalar($pdo, "SELECT COUNT(*) FROM topo_identity_keys WHERE `key` = 'chassis:mac:".strtolower($X)."' AND replaced = 0") === 3, 'the shared key is on all three Devices');
foreach ([$new => [$d1, $d2], $d1 => [$new], $d2 => [$new]] as $on => $others) {
	$have = array_values(array_filter(attrs($pdo, $on)['duplicate_candidates'] ?? [], static fn (array $c): bool => $c['reason'] === 'reporter_key_shared'));
	$ids = array_column($have, 'device_id');
	sort($ids);
	$want = $others;
	sort($want);
	check($ids === $want && $have[0]['key'] === 'chassis_id' && $have[0]['value'] === $X, "reporter_key_shared on Device #{$on} names ".implode(', ', $want));
}
check(count(array_filter(shared_list($r['summary']), static fn (array $e): bool => $e['key'] === 'chassis_id' && $e['value'] === $X)) === 1,
	'identity_keys_shared still lists it');
echo "  -- the same snapshots again --\n";
$state = key_shared_state($pdo);
$fp = fingerprint($pdo);
$r = run_ingest($dsn, $user, $password);
check(dev_count($pdo) === 3, 'the second run creates no Device');
check(key_shared_state($pdo) === $state, 'the second run gives the same Devices and candidates');
check(fingerprint($pdo) === $fp, 'the second run changes nothing in the nodes, edges and observations');
check(empty($r['summary']['reporters_skipped']), 'the second run skips nothing');
echo "  -- two reporter orders --\n";
$results = [];
foreach (['hostid order' => null, 'reporters 103, 102' => '103,102', 'reporters 102, 103' => '102,103'] as $label => $order) {
	key_shared_setup($pdo, $X, true);
	$r = run_ingest($dsn, $user, $password, [], $order);
	$results[$label] = key_shared_state($pdo);
	check(empty($r['summary']['reporters_skipped']) && dev_count($pdo) === 4, "{$label}: nothing skipped; old1, old2, the new Device of 102 and the key-less Device of 103");
}
check($results['hostid order'] === $results['reporters 103, 102'] && $results['hostid order'] === $results['reporters 102, 103'], 'the result does not depend on the reporter order');
check(count(array_filter($results['hostid order'], static fn (array $v): bool => (string) $v[0] === '102')) === 1, 'host 102 has its own Device in every order');

// ============================================================================================================
echo "\n=== 6: a MAC in the inventory of two Hosts matches nothing ===\n";
reset_all($pdo);
add_host($pdo, 101, 'SwR', 'Switch R', '10.0.0.1');
add_host($pdo, 201, 'HostOne', 'Host one', null);
add_host($pdo, 202, 'HostTwo', 'Host two', null);
$MAC = 'c0:ff:ee:00:00:01';
foreach ([201, 202] as $hid) {
	$pdo->prepare('INSERT INTO host_inventory (hostid, macaddress_a) VALUES (?, ?)')->execute([$hid, strtoupper($MAC)]);
	$pdo->prepare("INSERT INTO topo_nodes (type, host_ref, attrs, created_at, updated_at) VALUES ('host', ?, '{}', 1, 1)")->execute([$hid]);
}
reporter_rows($pdo, $R, [['if_index' => 1, 'rem_chassis' => $MAC, 'rem_chassis_type' => 'macAddress', 'rem_sysname' => 'fw']]);
$r = run_ingest($dsn, $user, $password);
check(scalar($pdo, 'SELECT COUNT(*) FROM topo_nodes WHERE represented_by_node_id IS NOT NULL AND represented_by_matched_by=\'mac\'') == 0,
	'no represented_by from the shared MAC');
$list = $r['summary']['macs_shared']['list'] ?? [];
check(($r['summary']['macs_shared']['count'] ?? 0) === 1 && count($list) === 1 && $list[0]['kind'] === 'host' && $list[0]['value'] === $MAC && $list[0]['host_ids'] === [201, 202],
	'macs_shared lists the MAC with both hostids');
echo "  -- one Host only: the match works --\n";
$pdo->exec('DELETE FROM host_inventory WHERE hostid = 202');
$r = run_ingest($dsn, $user, $password);
check(scalar($pdo, 'SELECT COUNT(*) FROM topo_nodes WHERE represented_by_node_id IS NOT NULL AND represented_by_matched_by=\'mac\'') == 1,
	'a MAC in one Host is matched');
check(empty($r['summary']['macs_shared']['list']) && ($r['summary']['macs_shared']['count'] ?? 0) === 0, 'macs_shared is empty again');

echo "\nAll shared identifier tests passed.\n";
