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
$pdo->exec('CREATE TABLE items (itemid BIGINT UNSIGNED PRIMARY KEY, hostid BIGINT UNSIGNED NOT NULL, status INT NOT NULL DEFAULT 0, topology_role INT NOT NULL DEFAULT 0, type INT NOT NULL DEFAULT 0)');
$pdo->exec("CREATE TABLE interface (interfaceid BIGINT UNSIGNED PRIMARY KEY, hostid BIGINT UNSIGNED NOT NULL, type INT NOT NULL, useip INT NOT NULL DEFAULT 1, ip VARCHAR(64) NOT NULL DEFAULT '', main INT NOT NULL DEFAULT 1)");
$pdo->exec("CREATE TABLE host_inventory (hostid BIGINT UNSIGNED PRIMARY KEY, macaddress_a VARCHAR(64) NOT NULL DEFAULT '', macaddress_b VARCHAR(64) NOT NULL DEFAULT '')");
$pdo->exec('CREATE TABLE topo_lld_snapshot (itemid BIGINT UNSIGNED PRIMARY KEY, hostid BIGINT UNSIGNED NOT NULL, role INT NOT NULL DEFAULT 0,'.
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

function snapshot(PDO $pdo, int $itemid, int $hostid, int $role, int $clock, array $rows, int $current_role = null,
		int $status = 0): void {
	$pdo->prepare('REPLACE INTO items (itemid, hostid, status, topology_role) VALUES (?, ?, ?, ?)')
		->execute([$itemid, $hostid, $status, $current_role ?? $role]);
	$json = json_encode($rows, JSON_THROW_ON_ERROR);
	$pdo->prepare('REPLACE INTO topo_lld_snapshot (itemid, hostid, role, clock, rows_hash, rows_json, rows_total, rows_valid)'.
		' VALUES (?, ?, ?, ?, ?, ?, ?, ?)')->execute([$itemid, $hostid, $role, $clock, hash('sha256', $json), $json,
		count($rows), count($rows)]);
}

function run_ingest(string $dsn, string $user, string $password, array $extra = []): array {
	$cmd = [PHP_BINARY, __DIR__.'/ingest.php', '--pdo-dsn', $dsn, '--pdo-user', $user, '--pdo-password', $password];
	foreach ($extra as $host) {
		array_push($cmd, '--zabbix-host', $host);
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
echo "\n=== 1: factory IP — Device A (chassis X, IP 192.168.1.1); a row with chassis Y and the same IP ===\n";
reset_all($pdo);
add_host($pdo, 101, 'SwR', 'Switch R', '10.0.0.1');
$A = device($pdo, $X, $IP, 'A');
reporter_rows($pdo, $R, [['if_index' => 1, 'rem_chassis' => $Y, 'rem_chassis_type' => 'macAddress', 'rem_mgmt_ip' => $IP, 'rem_sysname' => 'NewBox']]);
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

echo "  -- a row with chassis X finds A --\n";
reporter_rows($pdo, $R, [['if_index' => 2, 'rem_chassis' => $X, 'rem_chassis_type' => 'macAddress', 'rem_sysname' => 'A']], 'lldp', CLOCK + 100);
run_ingest($dsn, $user, $password);
check(observation_of($pdo, 2)['device_id'] == $A && dev_count($pdo) === 3, 'chassis X: Device A, nothing created');

echo "  -- a row with only that IP: two Devices have it --\n";
reporter_rows($pdo, $R, [['if_index' => 3, 'rem_mgmt_ip' => $IP, 'rem_sysname' => 'who']], 'lldp', CLOCK + 200);
$before = dev_count($pdo);
$r = run_ingest($dsn, $user, $password);
$o = observation_of($pdo, 3);
check($o !== null && $o['outcome'] === 'ambiguous' && $o['device_id'] === null && $o['edge_id'] === null, 'the observation is ambiguous, no Device, no link');
check(dev_count($pdo) === $before, 'no Device was created');
check(dev_attr($pdo, $A, 'chassis_id') === $X && dev_attr($pdo, $new, 'chassis_id') === $Y, 'no chassis_id changed');
$list = shared_list($r['summary']);
check(($r['summary']['identity_keys_shared']['count'] ?? 0) === 1 && count($list) === 1 && $list[0]['key'] === 'mgmt_ip'
	&& $list[0]['value'] === $IP && count($list[0]['node_ids']) === 2, 'identity_keys_shared lists the IP with both node ids');
for ($i = 1; $i <= 4; $i++) {
	run_ingest($dsn, $user, $password);
}
check(dev_attr($pdo, $A, 'chassis_id') === $X && dev_attr($pdo, $new, 'chassis_id') === $Y && dev_count($pdo) === $before,
	'5 runs: no chassis_id changes, no new Device');

// ============================================================================================================
echo "\n=== 2: CDP + LLDP — a Device made from a CDP row (IP 1, no chassis) gets the chassis of the LLDP row ===\n";
reset_all($pdo);
add_host($pdo, 101, 'SwR', 'Switch R', '10.0.0.1');
reporter_rows($pdo, $R, [['if_index' => 1, 'rem_mgmt_ip' => '10.1.1.1', 'rem_sysname' => 'cdpbox', 'rem_port' => 'Gi0/24']], 'cdp');
run_ingest($dsn, $user, $password);
$cdp = (int) scalar($pdo, "SELECT id FROM topo_nodes WHERE type='device' AND JSON_UNQUOTE(JSON_EXTRACT(attrs,'\$.mgmt_ip'))='10.1.1.1'");
check($cdp > 0 && dev_attr($pdo, $cdp, 'chassis_id') === null, 'the CDP row made a Device with an IP and no chassis');
reporter_rows($pdo, $R, [['if_index' => 1, 'rem_chassis' => $Y, 'rem_chassis_type' => 'macAddress', 'rem_mgmt_ip' => '10.1.1.1', 'rem_sysname' => 'cdpbox']],
	'lldp', CLOCK + 100);
$before = dev_count($pdo);
run_ingest($dsn, $user, $password);
check(dev_count($pdo) === $before, 'the LLDP row created no Device');
check(dev_attr($pdo, $cdp, 'chassis_id') === $Y, 'the CDP Device got chassis Y');
check(observation_of($pdo, 1)['device_id'] == $cdp, 'the observation points to it');

// ============================================================================================================
echo "\n=== 3: a row with only an IP that one Device has -> that Device, whatever its chassis ===\n";
reset_all($pdo);
add_host($pdo, 101, 'SwR', 'Switch R', '10.0.0.1');
$A = device($pdo, $X, '10.2.2.2', 'A');
reporter_rows($pdo, $R, [['if_index' => 1, 'rem_mgmt_ip' => '10.2.2.2', 'rem_sysname' => 'A']]);
run_ingest($dsn, $user, $password);
check(observation_of($pdo, 1)['device_id'] == $A && dev_count($pdo) === 2, 'the Device with that IP, nothing created');
check(dev_attr($pdo, $A, 'chassis_id') === $X, 'its chassis is untouched');

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
echo "\n=== 5: a reporter whose loc_chassis is on two Devices is skipped ===\n";
add_host($pdo, 102, 'SwDup', 'Switch Dup', '10.0.0.2');
snapshot($pdo, 2001, 102, 1, CLOCK, [['if_index' => 1, 'name' => 'Gi0/1', 'if_type' => '6', 'admin_status' => '1', 'oper_status' => '1',
	'loc_chassis' => $X]]);
$before = dev_count($pdo);
$represented = (int) scalar($pdo, 'SELECT COUNT(*) FROM topo_nodes WHERE represented_by_node_id IS NOT NULL');
$r = run_ingest($dsn, $user, $password);
check(($r['summary']['reporters_skipped']['identity_key_shared'] ?? 0) === 1, 'skipped as identity_key_shared');
check(dev_count($pdo) === $before, 'no Device created');
check((int) scalar($pdo, 'SELECT COUNT(*) FROM topo_nodes WHERE represented_by_node_id IS NOT NULL') === $represented, 'no represented_by was set for it');
check(count(array_filter(shared_list($r['summary']), static fn (array $e): bool => $e['key'] === 'chassis_id' && $e['value'] === $X)) === 1,
	'identity_keys_shared lists it');

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
