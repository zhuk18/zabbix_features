#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * test_device_row.php — task 113, topology-1-model.md 2.96 §6.4 steps 2-4 and 8, §6.7.5 `reporter_cdp_key`, §12.2 (own capabilities): the identity of a reporter from its DEVICE row.
 * The DEVICE row gives the chassis first (the other snapshots only as a fallback); its CDP device ID in MAC form is a hardware ID (the chassis wins when both differ); a CDP device ID
 * that is a name is the key `cdp:<segment>:<name key>` of the reporter's Device (found, shared, replaced on rename); a reporter without a chassis finds its Device by it;
 * `loc_sysname` is `attrs.sysname`, `loc_sys_cap` is `attrs.sys_cap`; two own keys on two Devices give `reporter_cdp_key`.
 *
 * Same method as test_cdp_device_key.php: rows go straight into topo_lld_snapshot, the REAL ingest.php runs as a subprocess.
 *
 * Usage: test_device_row.php <PDO DSN> <database user> [password]
 * Expects a THROWAWAY database.
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

function run_ingest(string $dsn, string $user, string $password, array $extra = [], array $options = []): array {
	$cmd = [PHP_BINARY, __DIR__.'/ingest.php', '--pdo-dsn', $dsn, '--pdo-user', $user, '--pdo-password', $password];
	foreach ($options as $name => $value) {
		array_push($cmd, '--'.$name, $value);
	}
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

function attrs(PDO $pdo, int $node_id): array {
	return json_decode((string) scalar($pdo, 'SELECT attrs FROM topo_nodes WHERE id=?', [$node_id]), true);
}

/** A Device made by hand: old data. */
function device(PDO $pdo, ?string $chassis, ?string $ip, string $sysname = ''): int {
	$pdo->prepare("INSERT INTO topo_nodes (type, attrs, created_at, updated_at) VALUES ('device', ?, 1, 1)")
		->execute([json_encode(['mac' => null, 'chassis_id' => $chassis, 'mgmt_ip' => $ip, 'sysname' => $sysname, 'vendor' => 'unknown', 'last_seen' => 1])]);
	return (int) $pdo->lastInsertId();
}

const CLOCK = 1_800_000_000;

function reset_all(PDO $pdo): void {
	$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
	foreach (['topo_identity_keys', 'topo_observations', 'topo_edges', 'topo_nodes', 'topo_lld_snapshot', 'host_inventory', 'interface', 'items', 'hosts'] as $t) {
		$pdo->exec("DELETE FROM {$t}");
	}
	$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
}


/** A reporter with PORTS and, when $device_row is not null, a DEVICE rule; $lldp rows (NEIGHBORS, source lldp) and $cdp rows (source cdp) are optional. */
function reporter(PDO $pdo, int $hostid, ?string $ip, ?string $ports_chassis, ?array $device_row, array $lldp = [], array $cdp = []): void {
	add_host($pdo, $hostid, 'R'.$hostid, 'R'.$hostid, $ip);
	$ports = [];
	for ($i = 1; $i <= 4; $i++) {
		$ports[] = ['if_index' => $i, 'name' => 'Gi0/'.$i] + ($ports_chassis !== null ? ['loc_chassis' => $ports_chassis] : []);
	}
	snapshot($pdo, $hostid * 10 + 1, $hostid, 1, CLOCK, $ports);
	if ($device_row !== null) {
		snapshot($pdo, $hostid * 10 + 4, $hostid, 5, CLOCK, [$device_row]);
	}
	if ($lldp) {
		snapshot($pdo, $hostid * 10 + 2, $hostid, 2, CLOCK + 10, $lldp);
	}
	if ($cdp) {
		snapshot($pdo, $hostid * 10 + 3, $hostid, 2, CLOCK + 10, $cdp);
	}
}

function cdp_neighbor(int $if_index, string $name, ?string $address = null): array {
	return ['if_index' => $if_index, 'source' => 'cdp', 'rem_sysname' => $name, 'rem_port' => 'Gi0/1'] + ($address !== null ? ['rem_mgmt_ip' => $address] : []);
}

function device_of_host(PDO $pdo, int $hostid): ?int {
	$id = scalar($pdo, "SELECT d.id FROM topo_nodes d JOIN topo_nodes h ON h.id = d.represented_by_node_id WHERE d.type = 'device' AND h.host_ref = ?", [$hostid]);
	return $id === false ? null : (int) $id;
}

function keys_of(PDO $pdo, int $device_id): array {
	$stmt = $pdo->prepare('SELECT `key` FROM topo_identity_keys WHERE device_id = ? ORDER BY `key`');
	$stmt->execute([$device_id]);
	return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

function candidates(PDO $pdo, int $device_id, string $reason): array {
	return array_values(array_filter(attrs($pdo, $device_id)['duplicate_candidates'] ?? [], static fn (array $c): bool => $c['reason'] === $reason));
}

function ingest(string $dsn, string $user, string $password): array {
	$r = run_ingest($dsn, $user, $password);
	if ($r['code'] !== 0) {
		fail_test("ingest.php exited with {$r['code']}: ".substr($r['err'].$r['out'], 0, 600));
	}
	return $r;
}

$MAC_A = 'aa:bb:cc:00:13:01';
$MAC_B = 'aa:bb:cc:00:13:02';

echo "=== 1: the DEVICE row gives the chassis; the other snapshots are only a fallback (§6.4 step 2) ===\n";
reset_all($pdo);
reporter($pdo, 1, '192.0.2.1', $MAC_B, ['loc_chassis' => $MAC_A, 'loc_chassis_type' => 'macAddress', 'loc_sysname' => 'sw1.lab', 'loc_sys_cap' => 'bridge router']);
ingest($dsn, $user, $password);
$d = device_of_host($pdo, 1);
check($d !== null && attrs($pdo, $d)['chassis_id'] === $MAC_A, 'the chassis of the Device is the one of the DEVICE row, not the one of the PORTS rows');
check(keys_of($pdo, $d) === ['chassis:mac:'.$MAC_A], 'its only key is the chassis key of the DEVICE row');
check(attrs($pdo, $d)['sysname'] === 'sw1.lab', '`loc_sysname` is attrs.sysname (own data wins over the Zabbix host name)');
check(attrs($pdo, $d)['sys_cap'] === 'bridge router', '`loc_sys_cap` is attrs.sys_cap');

echo "\n=== 2: no DEVICE row, or a row without a chassis: the other snapshots (as before 2.96) ===\n";
reset_all($pdo);
reporter($pdo, 1, '192.0.2.1', $MAC_B, null);
reporter($pdo, 2, '192.0.2.2', null, ['loc_sysname' => 'only-a-name']);
ingest($dsn, $user, $password);
check(attrs($pdo, device_of_host($pdo, 1))['chassis_id'] === $MAC_B, 'no DEVICE snapshot: the chassis of the PORTS rows');
$d2 = device_of_host($pdo, 2);
check($d2 !== null && keys_of($pdo, $d2) === [] && attrs($pdo, $d2)['sysname'] === 'only-a-name', 'a row with only a name and no chassis: a Device without a key (by its SNMP address), with the name');

echo "\n=== 3: two DEVICE results with two chassis: identity_ambiguous (the rule of step 3) ===\n";
reset_all($pdo);
reporter($pdo, 1, '192.0.2.1', null, ['loc_chassis' => $MAC_A, 'loc_chassis_type' => 'macAddress']);
snapshot($pdo, 15, 1, 5, CLOCK, [['loc_chassis' => $MAC_B, 'loc_chassis_type' => 'macAddress']]);
$r = run_ingest($dsn, $user, $password);      // exit 1: the only reporter is skipped
check(device_of_host($pdo, 1) === null && ($r['summary']['reporters_skipped']['identity_ambiguous'] ?? 0) === 1, 'two DEVICE rules with different chassis ids: the reporter is skipped, identity_ambiguous');

echo "\n=== 4: the CDP device ID in MAC form (§6.4 step 8) ===\n";
reset_all($pdo);
reporter($pdo, 1, '192.0.2.1', null, ['loc_cdp_device_id' => 'SEP'.strtoupper(str_replace(':', '', $MAC_A))]);
reporter($pdo, 2, '192.0.2.2', null, ['loc_chassis' => $MAC_B, 'loc_chassis_type' => 'macAddress', 'loc_cdp_device_id' => $MAC_A]);
ingest($dsn, $user, $password);
$d1 = device_of_host($pdo, 1);
check($d1 !== null && attrs($pdo, $d1)['chassis_id'] === $MAC_A && keys_of($pdo, $d1) === ['chassis:mac:'.$MAC_A], 'without a chassis: the MAC of the CDP device ID (SEP form) is the chassis');
$d2 = device_of_host($pdo, 2);
check(attrs($pdo, $d2)['chassis_id'] === $MAC_B && keys_of($pdo, $d2) === ['chassis:mac:'.$MAC_B], 'with a different chassis: the chassis wins and the CDP value is no key');

echo "\n=== 5: a CDP device ID that is a name: the key of the reporter's Device; a reporter without a chassis finds its Device by it ===\n";
reset_all($pdo);
// a neighbor N claims C by CDP only (a loopback address, not C's SNMP address): before the DEVICE row it would make a second Device
reporter($pdo, 1, '192.0.2.171', $MAC_A, ['loc_chassis' => $MAC_A, 'loc_chassis_type' => 'macAddress'], [], [cdp_neighbor(1, 'C13.lab', '10.255.0.13')]);
reporter($pdo, 2, '192.0.2.170', null, ['loc_cdp_device_id' => 'C13.lab'], [], [cdp_neighbor(1, 'n13.lab', '192.0.2.171')]);
ingest($dsn, $user, $password);
$c = device_of_host($pdo, 2);
check($c !== null && keys_of($pdo, $c) === ['cdp:server:c13'], 'the key of the reporter\'s Device is cdp:<segment>:<name key> (case folded, the domain cut: normalization §4.6)');
check((int) scalar($pdo, "SELECT COUNT(*) FROM topo_nodes WHERE type='device'") === 2, 'N\'s CDP claim (a loopback address, name C13.lab) found the reporter\'s Device: two Devices, no second one for C');
$devices_before = (int) scalar($pdo, "SELECT COUNT(*) FROM topo_nodes WHERE type='device'");
check(candidates($pdo, $c, 'reporter_cdp_key') === [], 'no candidate on C');
ingest($dsn, $user, $password);
check((int) scalar($pdo, "SELECT COUNT(*) FROM topo_nodes WHERE type='device'") === $devices_before && device_of_host($pdo, 2) === $c, 'a second run changes nothing');

echo "\n=== 6: the device was renamed: the new key replaces the old one on the same Device ===\n";
snapshot($pdo, 24, 2, 5, CLOCK + 100, [['loc_cdp_device_id' => 'C13-new.lab']]);
ingest($dsn, $user, $password);
check(device_of_host($pdo, 2) === $c && keys_of($pdo, $c) === ['cdp:server:c13-new'], 'the same Device; its key is the new name, the old key is gone');

echo "\n=== 7: two own keys, two Devices: reporter_cdp_key on both, no merge (§6.4 step 8) ===\n";
reset_all($pdo);
// the Device that CDP-only neighbor reports made earlier: R3 reports the name C13.lab (no chassis, no address of a reporter)
reporter($pdo, 3, '192.0.2.173', $MAC_B, ['loc_chassis' => $MAC_B, 'loc_chassis_type' => 'macAddress'], [], [cdp_neighbor(2, 'C13.lab', '10.255.0.13')]);
ingest($dsn, $user, $password);
$made = (int) scalar($pdo, "SELECT device_id FROM topo_identity_keys WHERE `key` = 'cdp:server:c13'");
check($made > 0 && device_of_host($pdo, 3) !== $made, 'set-up: the neighbor made a Device without a host with the key cdp:server:c13');
// now the reporter R4 with a chassis and the same own CDP device ID
reporter($pdo, 4, '192.0.2.170', $MAC_A, ['loc_chassis' => $MAC_A, 'loc_chassis_type' => 'macAddress', 'loc_cdp_device_id' => 'C13.lab']);
ingest($dsn, $user, $password);
$r4 = device_of_host($pdo, 4);
check($r4 !== null && keys_of($pdo, $r4) === ['chassis:mac:'.$MAC_A], 'the reporter\'s Device (found by its chassis) does not take the key');
check(keys_of($pdo, $made) === ['cdp:server:c13'], 'the other Device keeps the key (no automatic merge)');
$c1 = candidates($pdo, $r4, 'reporter_cdp_key');
$c2 = candidates($pdo, $made, 'reporter_cdp_key');
check(count($c1) === 1 && $c1[0]['device_id'] === $made && count($c2) === 1 && $c2[0]['device_id'] === $r4, 'the candidate reporter_cdp_key is on both Devices');
check(count($pdo->query("SELECT 1 FROM topo_nodes WHERE type='device' AND id IN ({$made}, {$r4})")->fetchAll()) === 2, 'both Devices still exist');
ingest($dsn, $user, $password);
check(count(candidates($pdo, $r4, 'reporter_cdp_key')) === 1, 'the evidence is current: the candidate stays in the next run');
// the operator merges the other Device into the reporter's: the merge moves the key (CTopologyPrototype::mergeDevices), the candidate is gone
$pdo->prepare('INSERT INTO topo_identity_keys (device_id, `key`, kind, first_seen, replaced) VALUES (?, ?, ?, ?, 0)')->execute([$r4, 'cdp:server:c13', 'cdp', CLOCK]);
$pdo->prepare('DELETE FROM topo_identity_keys WHERE device_id = ? AND `key` = ?')->execute([$made, 'cdp:server:c13']);
$pdo->prepare('DELETE FROM topo_nodes WHERE id = ?')->execute([$made]);
ingest($dsn, $user, $password);
check(candidates($pdo, $r4, 'reporter_cdp_key') === [], 'after the key moved to the reporter\'s Device (the merge), no candidate');

echo "\n=== 8: a key that a Device with a host holds is shared; the reporter's own key stays on its Device (§6.7.4) ===\n";
reset_all($pdo);
reporter($pdo, 5, '192.0.2.175', $MAC_A, ['loc_chassis' => $MAC_A, 'loc_chassis_type' => 'macAddress', 'loc_cdp_device_id' => 'box.lab']);
reporter($pdo, 6, '192.0.2.176', $MAC_B, ['loc_chassis' => $MAC_B, 'loc_chassis_type' => 'macAddress', 'loc_cdp_device_id' => 'box.lab']);
$r = ingest($dsn, $user, $password);
$d5 = device_of_host($pdo, 5);
$d6 = device_of_host($pdo, 6);
check(keys_of($pdo, $d5) === ['cdp:server:box', 'chassis:mac:'.$MAC_A] && keys_of($pdo, $d6) === ['cdp:server:box', 'chassis:mac:'.$MAC_B], 'both reporters carry their own key; it is shared');
check(array_filter($r['summary']['identity_keys_shared']['list'] ?? [], static fn (array $e): bool => $e['key'] === 'cdp_key') !== [], 'the shared key is in the run summary');

echo "\nOK\n";
