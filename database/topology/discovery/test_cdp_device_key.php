#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * test_cdp_device_key.php — task 074, topology-1-model.md 2.55 §6.7.0, §6.7.1 and topology-6-normalization.md 0.15 §4.9, §4.10: a CDP device id that is not a MAC and not a
 * default name is an identity key (`cdp:<segment>:<device name key>`, kind `cdp`) of a Device that is not a reporter.
 * Cases L1-L8 of the task (the lab scenarios S9 run the same on the lab), the run order, the segment, the reporter's own Device, the default name, the re-run.
 *
 * Same method as test_stable_ingest.php: rows go straight into topo_lld_snapshot, the REAL ingest.php runs as a subprocess.
 *
 * Usage: test_cdp_device_key.php <PDO DSN> <database user> [password]
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

/** Reporters: [hostid => [ip, chassis, lldp rows, cdp rows]]; the rules of hostid H: PORTS H*10+1, LLDP H*10+2, CDP H*10+3. */
function reporters(PDO $pdo, array $list): void {
	reset_all($pdo);
	foreach ($list as $hostid => [$ip, $chassis, $lldp, $cdp]) {
		add_host($pdo, $hostid, 'R'.$hostid, 'R'.$hostid, $ip);
		$ports = [];
		for ($i = 1; $i <= 8; $i++) {
			$ports[] = ['if_index' => $i, 'name' => 'Gi0/'.$i, 'loc_chassis' => $chassis];
		}
		snapshot($pdo, $hostid * 10 + 1, $hostid, 1, CLOCK, $ports);
		snapshot($pdo, $hostid * 10 + 2, $hostid, 2, CLOCK + 10, array_map(static fn (array $r): array => $r + ['loc_chassis' => $chassis], $lldp));
		snapshot($pdo, $hostid * 10 + 3, $hostid, 2, CLOCK + 10, array_map(static fn (array $r): array => $r + ['loc_chassis' => $chassis], $cdp));
	}
}

function nrow(int $if, string $source, array $extra): array {
	return ['if_index' => $if, 'rem_port' => 'p'.$if, 'rem_port_type' => 'interfaceName', 'source' => $source] + $extra;
}

/** Devices that are not reporters. */
function others(PDO $pdo): array {
	return $pdo->query("SELECT id FROM topo_nodes WHERE type='device' AND represented_by_node_id IS NULL ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
}

function keys_of(PDO $pdo, int $id, string $kind): array {
	$stmt = $pdo->prepare('SELECT `key` FROM topo_identity_keys WHERE device_id = ? AND kind = ? ORDER BY `key`');
	$stmt->execute([$id, $kind]);
	return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

function links(PDO $pdo): int {
	return (int) scalar($pdo, "SELECT COUNT(*) FROM topo_edges WHERE type = 'physical_link'");
}

function marks(PDO $pdo): array {
	$out = [];
	foreach ($pdo->query("SELECT id, attrs FROM topo_nodes WHERE type='device'")->fetchAll(PDO::FETCH_ASSOC) as $row) {
		foreach (json_decode($row['attrs'], true)['duplicate_candidates'] ?? [] as $c) {
			$out[] = $c['reason'];
		}
	}
	return $out;
}

function run3(string $dsn, string $user, string $password): array {
	return [run_ingest($dsn, $user, $password), run_ingest($dsn, $user, $password), run_ingest($dsn, $user, $password)];
}

$C1 = 'a0:00:00:00:00:01';
$C2 = 'a0:00:00:00:00:02';

echo "\n=== L1: a CDP-only neighbor on 3 ports of one reporter (LAG): one Device, 3 links ===\n";
reporters($pdo, [101 => ['10.0.0.1', $C1, [], [nrow(1, 'cdp', ['rem_sysname' => 'Core-SW1', 'rem_port' => 'Te1/0/1']),
	nrow(2, 'cdp', ['rem_sysname' => 'Core-SW1', 'rem_port' => 'Te1/0/2']), nrow(3, 'cdp', ['rem_sysname' => 'Core-SW1', 'rem_port' => 'Te1/0/3'])]]]);
[$r1, $r2, $r3] = run3($dsn, $user, $password);
$o = others($pdo);
check(count($o) === 1 && keys_of($pdo, (int) $o[0], 'cdp') === ['cdp:server:core-sw1'], 'one Device with the key cdp:server:core-sw1');
check(links($pdo) === 3 && $r1['summary']['devices_created'] === 2, '3 physical links (the reporter and the neighbor are the two Devices made)');
check($r2['summary']['devices_created'] === 0 && $r3['summary']['devices_created'] === 0 && links($pdo) === 3, 'the next runs make nothing (P1)');
check(!isset(attrs($pdo, (int) $o[0])['scope_ports']), 'the Device has no scope_ports');

echo "\n=== L2: the same CDP-only neighbor seen by two reporters: one Device, no same_name mark ===\n";
reporters($pdo, [
	101 => ['10.0.0.1', $C1, [], [nrow(1, 'cdp', ['rem_sysname' => 'Core-SW1(FDO2101X0AB)', 'rem_port' => 'Te1/0/1'])]],
	102 => ['10.0.0.2', $C2, [], [nrow(2, 'cdp', ['rem_sysname' => 'core-sw1.lab', 'rem_port' => 'Te1/0/2'])]]]);
run3($dsn, $user, $password);
$o = others($pdo);
check(count($o) === 1 && keys_of($pdo, (int) $o[0], 'cdp') === ['cdp:server:core-sw1'] && marks($pdo) === [] && links($pdo) === 2, 'one Device (name key core-sw1), no mark, 2 links');

echo "\n=== L3: two different CDP-only neighbors with the device id Switch (a default name) on two ports: two Devices ===\n";
reporters($pdo, [101 => ['10.0.0.1', $C1, [], [nrow(1, 'cdp', ['rem_sysname' => 'Switch', 'rem_port' => 'Gi1/0/1']), nrow(2, 'cdp', ['rem_sysname' => 'Switch', 'rem_port' => 'Gi1/0/1'])]]]);
run3($dsn, $user, $password);
$o = others($pdo);
check(count($o) === 2 && (int) scalar($pdo, "SELECT COUNT(*) FROM topo_identity_keys WHERE kind = 'cdp'") === 0 && links($pdo) === 2, 'two Devices, no device id key, 2 links');
check(count(array_filter($o, static fn ($id): bool => isset(attrs($pdo, (int) $id)['scope_ports']))) === 2, 'each has the scope port (same-port match)');

echo "\n=== L4, L5: SEP + 12 hex digits, 12 bare hex digits: a hardware ID ===\n";
reporters($pdo, [101 => ['10.0.0.1', $C1, [], [nrow(1, 'cdp', ['rem_sysname' => 'SEP001122334455']), nrow(2, 'cdp', ['rem_sysname' => '204c9e6e08fe'])]]]);
run3($dsn, $user, $password);
check(device_id($pdo, '00:11:22:33:44:55') !== null && device_id($pdo, '20:4c:9e:6e:08:fe') !== null && (int) scalar($pdo, "SELECT COUNT(*) FROM topo_identity_keys WHERE kind = 'cdp'") === 0,
	'two Devices with the hardware IDs 00:11:22:33:44:55 and 20:4c:9e:6e:08:fe (chassis:mac: keys, no cdp key)');

echo "\n=== L6: one device sends LLDP and CDP on one port of R1; R2 sees it by CDP only ===\n";
$lldp = nrow(1, 'lldp', ['rem_chassis' => 'c0:00:00:00:00:77', 'rem_chassis_type' => 'macAddress', 'rem_sysname' => 'Core-SW1', 'rem_mgmt_ip' => '10.7.7.7']);
$cdp = nrow(1, 'cdp', ['rem_sysname' => 'Core-SW1', 'rem_mgmt_ip' => '10.7.7.7']);
reporters($pdo, [101 => ['10.0.0.1', $C1, [$lldp], [$cdp]], 102 => ['10.0.0.2', $C2, [], [nrow(2, 'cdp', ['rem_sysname' => 'Core-SW1', 'rem_port' => 'x'])]]]);
run3($dsn, $user, $password);
$o = others($pdo);
check(count($o) === 1 && keys_of($pdo, (int) $o[0], 'cdp') === ['cdp:server:core-sw1'] && device_id($pdo, 'c0:00:00:00:00:77') === (int) $o[0], 'one Device: the chassis Device got the key on the port of R1, R2 found it');
echo "  -- the same with R2 first in the run order\n";
reporters($pdo, [101 => ['10.0.0.1', $C1, [], [nrow(2, 'cdp', ['rem_sysname' => 'Core-SW1', 'rem_port' => 'x'])]], 102 => ['10.0.0.2', $C2, [$lldp], [$cdp]]]);
run3($dsn, $user, $password);
// Known (report 074): the reporters are processed one after the other. When the reporter with the CDP-only claim comes first, it makes the Device that holds the key; the chassis
// Device of the other reporter then gets no key (another Device holds it): two Devices, stable from run to run. The model does not say that the claims of all reporters
// are sorted together.
$again = run_ingest($dsn, $user, $password);
check(count(others($pdo)) === 2 && $again['summary']['devices_created'] === 0, 'the reporter with the CDP-only claim first: two Devices, stable (order dependence of the same-port join, see the report)');

echo "\n=== L7: as L6, but the device is a reporter: no key on it; R2 finds it by its SNMP interface address ===\n";
reporters($pdo, [
	101 => ['10.0.0.1', $C1, [nrow(1, 'lldp', ['rem_chassis' => 'c0:00:00:00:00:77', 'rem_chassis_type' => 'macAddress', 'rem_sysname' => 'Core-SW1', 'rem_mgmt_ip' => '10.7.7.7'])], [nrow(1, 'cdp', ['rem_sysname' => 'Core-SW1', 'rem_mgmt_ip' => '10.7.7.7'])]],
	102 => ['10.0.0.2', $C2, [], [nrow(2, 'cdp', ['rem_sysname' => 'Core-SW1', 'rem_mgmt_ip' => '10.7.7.7', 'rem_port' => 'x'])]],
	107 => ['10.7.7.7', 'c0:00:00:00:00:77', [], []]]);
run3($dsn, $user, $password);
$dev = (int) device_id($pdo, 'c0:00:00:00:00:77');
check($dev > 0 && keys_of($pdo, $dev, 'cdp') === [] && count(others($pdo)) === 0, 'the reporter\'s Device has no device id key and no other Device exists (R2 found it by its address)');

echo "\n=== L8: two different devices with one device id in one segment: one Device (known limitation) ===\n";
reporters($pdo, [101 => ['10.0.0.1', $C1, [], [nrow(1, 'cdp', ['rem_sysname' => 'Core', 'rem_mgmt_ip' => '10.1.1.1']), nrow(2, 'cdp', ['rem_sysname' => 'Core', 'rem_mgmt_ip' => '10.2.2.2'])]]]);
run3($dsn, $user, $password);
check(count(others($pdo)) === 1 && links($pdo) === 2, 'one Device, 2 links: the two devices are one node in the graph');

echo "\n=== segment: the same device id in two segments is two keys ===\n";
reporters($pdo, [101 => ['10.0.0.1', $C1, [], [nrow(1, 'cdp', ['rem_sysname' => 'Core-SW1'])]], 102 => ['10.0.0.2', $C2, [], [nrow(2, 'cdp', ['rem_sysname' => 'Core-SW1'])]]]);
$pdo->exec('INSERT INTO proxy (proxyid) VALUES (5)');
$pdo->exec('ALTER TABLE hosts ADD COLUMN monitored_by INT NOT NULL DEFAULT 0, ADD COLUMN proxyid BIGINT UNSIGNED NULL, ADD COLUMN proxy_groupid BIGINT UNSIGNED NULL');
$pdo->exec('UPDATE hosts SET monitored_by = 1, proxyid = 5 WHERE hostid = 102');
run3($dsn, $user, $password);
$all = $pdo->query("SELECT `key` FROM topo_identity_keys WHERE kind = 'cdp' ORDER BY `key`")->fetchAll(PDO::FETCH_COLUMN);
check($all === ['cdp:proxy:5:core-sw1', 'cdp:server:core-sw1'] && count(others($pdo)) === 2, 'two Devices, keys cdp:server:core-sw1 and cdp:proxy:5:core-sw1');

echo "\n=== a key held by several Devices (old data): the claim is ambiguous ===\n";
reporters($pdo, [101 => ['10.0.0.1', $C1, [], [nrow(1, 'cdp', ['rem_sysname' => 'Core-SW1'])]]]);
foreach ([1, 2] as $n) {
	$id = device($pdo, null, null, 'Core-SW1');
	$pdo->prepare("INSERT INTO topo_identity_keys (device_id, `key`, kind, first_seen, replaced) VALUES (?, 'cdp:server:core-sw1', 'cdp', 1, 0)")->execute([$id]);
}
$r = run_ingest($dsn, $user, $password);
check(scalar($pdo, "SELECT outcome FROM topo_observations LIMIT 1") === 'ambiguous' && links($pdo) === 0 && $r['summary']['devices_created'] === 1, 'ambiguous: no Device is made for the claim, no link (only the reporter\'s Device is new)');

echo "\n=== old data (before 2.55): one Device per port for a CDP-only neighbor; the re-ingest ===\n";
reporters($pdo, [101 => ['10.0.0.1', $C1, [], [nrow(1, 'cdp', ['rem_sysname' => 'Core-SW1', 'rem_port' => 'Te1/0/1']), nrow(2, 'cdp', ['rem_sysname' => 'Core-SW1', 'rem_port' => 'Te1/0/2'])]]]);
$old = [];
for ($n = 1; $n <= 2; $n++) {
	$old[] = device($pdo, null, null, 'Core-SW1');
}
run_ingest($dsn, $user, $password);              // makes the reporter's Device, its ports and the new Device with the key
$ports = $pdo->query("SELECT id FROM topo_nodes WHERE type = 'port' AND JSON_EXTRACT(attrs, '\$.if_index') IN (1, 2) ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
foreach ($old as $i => $id) {                     // the old Devices as the former ingest made them: the scope port, no key
	$pdo->prepare('UPDATE topo_nodes SET attrs = JSON_SET(attrs, \'$.scope_ports\', JSON_ARRAY(?)) WHERE id = ?')->execute([(int) $ports[$i], $id]);
}
$before = count(others($pdo));
$r = run_ingest($dsn, $user, $password);
$keyed = $pdo->query("SELECT device_id FROM topo_identity_keys WHERE kind = 'cdp'")->fetchAll(PDO::FETCH_COLUMN);
check(count($keyed) === 1 && !in_array((int) $keyed[0], $old, true) && $r['summary']['devices_created'] === 0 && count(others($pdo)) === $before && links($pdo) === 2,
	'the claims find the Device with the key, not the old per-port Devices; the old ones are not deleted (they have no observation: "not seen"); no new Device on the next run');
check((int) scalar($pdo, 'SELECT COUNT(*) FROM topo_observations WHERE device_id IN ('.implode(',', $old).')') === 0, 'no observation points at an old Device');

echo "\nAll CDP device id key checks passed.\n";
