#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * test_chassis_subtypes.php — task 078 §2, topology-1-model.md 2.60 §6.7.0 (identity table), normalization 0.18 §4.2, FR-B D-50: a chassis id of the subtype interfaceAlias,
 * portComponent or interfaceName is not a hardware ID. A neighbor claim with it has no chassis key (it goes by the rows of a claim without a chassis, §6.7.1) and keeps the value
 * as an attribute; a reporter with such an own chassis has no loc_chassis (its Device is found by the SNMP interface address).
 *
 * Usage: test_chassis_subtypes.php <PDO DSN> <database user> [password]
 * Expects a THROWAWAY database.
 */

function fail_test(string $message): void {
	fwrite(STDERR, "FAIL: {$message}\n");
	exit(1);
}

function check(bool $condition, string $description, $detail = null): void {
	if (!$condition) {
		fail_test($description.($detail !== null ? " ".json_encode($detail) : ""));
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
		'items', 'proxy', 'hosts', 'globalmacro'] as $table) {
	$pdo->exec("DROP TABLE IF EXISTS {$table}");
}
$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
$pdo->exec("CREATE TABLE hosts (hostid BIGINT UNSIGNED PRIMARY KEY, host VARCHAR(128) NOT NULL, name VARCHAR(128) NOT NULL DEFAULT '', status INT NOT NULL DEFAULT 0)");
$pdo->exec('CREATE TABLE proxy (proxyid BIGINT UNSIGNED PRIMARY KEY)');
$pdo->exec('CREATE TABLE globalmacro (globalmacroid BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT, macro VARCHAR(255) NOT NULL, value VARCHAR(2048) NOT NULL DEFAULT \'\')');
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

function run_ingest(string $dsn, string $user, string $password, array $extra = [], array $options = [], array $env = []): array {
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
	$process = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, ['TMPDIR' => $tmp] + $env);
	$stdout = stream_get_contents($pipes[1]);
	$stderr = stream_get_contents($pipes[2]);
	fclose($pipes[1]);
	fclose($pipes[2]);
	$code = proc_close($process);
	$status = json_decode((string) @file_get_contents($tmp.'/topology-ingest-status.json'), true);
	return ['code' => $code, 'out' => $stdout, 'err' => $stderr, 'summary' => $status['summary'] ?? [], 'status' => $status ?? []];
}

function scalar(PDO $pdo, string $sql, array $params = []) {
	$stmt = $pdo->prepare($sql);
	$stmt->execute($params);
	return $stmt->fetchColumn();
}




const CLOCK = 1_800_000_000;

function reset_all(PDO $pdo): void {
	$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
	foreach (['topo_identity_keys', 'topo_observations', 'topo_edges', 'topo_nodes', 'topo_lld_snapshot', 'host_inventory', 'interface', 'items', 'hosts', 'globalmacro'] as $t) {
		$pdo->exec("DELETE FROM {$t}");
	}
	$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
}

function reporter(PDO $pdo, int $hostid, ?string $chassis, ?string $chassis_type, array $neighbors): void {
	add_host($pdo, $hostid, 'R'.$hostid, 'Reporter '.$hostid, '10.0.0.'.$hostid);
	$ports = array_map(static fn (int $i): array => ['if_index' => $i, 'name' => 'Gi0/'.$i, 'itemids' => [$hostid * 100 + $i]]
		+ ($chassis !== null ? ['loc_chassis' => $chassis, 'loc_chassis_type' => $chassis_type] : []), range(1, 4));
	snapshot($pdo, $hostid * 10 + 1, $hostid, 1, CLOCK, $ports);
	snapshot($pdo, $hostid * 10 + 2, $hostid, 2, CLOCK + 10, array_map(static fn (array $r): array => $r + ['source' => 'lldp']
		+ ($chassis !== null ? ['loc_chassis' => $chassis, 'loc_chassis_type' => $chassis_type] : []), $neighbors));
}

function others(PDO $pdo): array {
	return $pdo->query("SELECT id, attrs FROM topo_nodes WHERE type = 'device' AND represented_by_node_id IS NULL ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
}

function keys(PDO $pdo): array {
	return $pdo->query('SELECT `key` FROM topo_identity_keys ORDER BY `key`')->fetchAll(PDO::FETCH_COLUMN);
}

foreach (['interfaceName', 'interfaceAlias', 'portComponent'] as $type) {
	echo "\n=== neighbors on two reporters with the chassis eth0 of the subtype {$type}: two Devices, no chassis key ===\n";
	reset_all($pdo);
	reporter($pdo, 101, 'a0:00:00:00:00:01', 'macAddress', [['if_index' => 1, 'rem_chassis' => 'eth0', 'rem_chassis_type' => $type, 'rem_sysname' => 'Sw-X', 'rem_port' => 'p1', 'rem_port_type' => 'interfaceName']]);
	reporter($pdo, 102, 'a0:00:00:00:00:02', 'macAddress', [['if_index' => 1, 'rem_chassis' => 'eth0', 'rem_chassis_type' => $type, 'rem_sysname' => 'Sw-Y', 'rem_port' => 'p1', 'rem_port_type' => 'interfaceName']]);
	run_ingest($dsn, $user, $password);
	$r = run_ingest($dsn, $user, $password);
	$devices = others($pdo);
	check(count($devices) === 2, 'two Devices (before 2.60 the value was a segment key and both neighbors were one Device)', count($devices));
	check(array_filter(keys($pdo), static fn (string $k): bool => str_contains($k, ':'.$type.':')) === [], 'no key of the subtype '.$type);
	check(count(array_filter($devices, static fn (array $d): bool => (json_decode($d['attrs'], true)['chassis_text'] ?? null) === 'eth0' && (json_decode($d['attrs'], true)['chassis_id'] ?? null) === null)) === 2,
		'the value is kept as an attribute (chassis_text), it is no chassis_id');
	check($r['summary']['devices_created'] === 0, 'the next run makes no Device');
	check((int) $pdo->query("SELECT COUNT(*) FROM topo_observations WHERE outcome = 'applied'")->fetchColumn() === 2, 'both claims are applied');
}

echo "\n=== a MAC chassis and a local chassis keep their keys (the types are not changed) ===\n";
reset_all($pdo);
reporter($pdo, 101, 'a0:00:00:00:00:01', 'macAddress', [
	['if_index' => 1, 'rem_chassis' => 'c0:00:00:00:00:01', 'rem_chassis_type' => 'macAddress', 'rem_sysname' => 'M', 'rem_port' => 'p1', 'rem_port_type' => 'interfaceName'],
	['if_index' => 2, 'rem_chassis' => 'sw-local', 'rem_chassis_type' => 'local', 'rem_sysname' => 'L', 'rem_port' => 'p1', 'rem_port_type' => 'interfaceName']]);
run_ingest($dsn, $user, $password);
check(in_array('chassis:mac:c0:00:00:00:00:01', keys($pdo), true) && in_array('chassis:local:server:sw-local', keys($pdo), true), 'chassis:mac: and chassis:local:<segment>: keys');

echo "\n=== a reporter with an own chassis of the subtype interfaceName: no hardware ID, its Device by the SNMP interface ===\n";
reset_all($pdo);
reporter($pdo, 101, 'eth0', 'interfaceName', []);
$r = run_ingest($dsn, $user, $password);
$reporters = $pdo->query("SELECT id, attrs FROM topo_nodes WHERE type = 'device' AND represented_by_node_id IS NOT NULL")->fetchAll(PDO::FETCH_ASSOC);
check(count($reporters) === 1 && (json_decode($reporters[0]['attrs'], true)['chassis_id'] ?? null) === null && keys($pdo) === [], 'the reporter has a Device with no chassis and no key');
check($r['code'] === 0 || $r['code'] === 3, 'the run is not skipped (identity from the SNMP interface address)', $r['err']);
$fp = md5(json_encode($pdo->query('SELECT id, attrs FROM topo_nodes ORDER BY id')->fetchAll(PDO::FETCH_ASSOC)));
run_ingest($dsn, $user, $password);
check($fp === md5(json_encode($pdo->query('SELECT id, attrs FROM topo_nodes ORDER BY id')->fetchAll(PDO::FETCH_ASSOC))) && (int) $pdo->query("SELECT COUNT(*) FROM topo_nodes WHERE type = 'device'")->fetchColumn() === 1, 'the next run: the same Device');
echo "\n  -- and two reporters with the same own chassis eth0 are two Devices (no shared key)\n";
reset_all($pdo);
reporter($pdo, 101, 'eth0', 'interfaceName', []);
reporter($pdo, 102, 'eth0', 'interfaceName', []);
run_ingest($dsn, $user, $password);
check((int) $pdo->query("SELECT COUNT(*) FROM topo_nodes WHERE type = 'device'")->fetchColumn() === 2 && (int) $pdo->query('SELECT COUNT(*) FROM topo_identity_keys')->fetchColumn() === 0, 'two Devices, no key, no shared identifier');

echo "\n=== task 079: an undefined subtype (0, above 7: the row has no subtype name) or an empty value is no hardware ID ===\n";
reset_all($pdo);
reporter($pdo, 101, 'a0:00:00:00:00:01', 'macAddress', [['if_index' => 1, 'rem_chassis' => '00:00:00:00', 'rem_sysname' => 'Sw-X', 'rem_port' => 'p1', 'rem_port_type' => 'interfaceName']]);
reporter($pdo, 102, 'a0:00:00:00:00:02', 'macAddress', [['if_index' => 1, 'rem_chassis' => '00:00:00:00', 'rem_sysname' => 'Sw-Y', 'rem_port' => 'p1', 'rem_port_type' => 'interfaceName']]);
run_ingest($dsn, $user, $password);
$r = run_ingest($dsn, $user, $password);
$devices = others($pdo);
check(count($devices) === 2 && array_filter(keys($pdo), static fn (string $k): bool => str_contains($k, '00:00:00:00') && !str_contains($k, 'a0:00:00:00:00')) === [],
	'chassis 00 00 00 00 without a subtype name on two reporters of one segment: two Devices, no key (before 2.62 one Device by chassis:local:server:00:00:00:00)', [count($devices), keys($pdo)]);
check(count(array_filter($devices, static fn (array $d): bool => (json_decode($d['attrs'], true)['chassis_text'] ?? null) === '00:00:00:00')) === 2 && $r['summary']['devices_created'] === 0, 'the value is chassis_text; the next run makes no Device');
reset_all($pdo);
reporter($pdo, 101, 'a0:00:00:00:00:01', 'macAddress', [['if_index' => 1, 'rem_chassis' => '', 'rem_chassis_type' => 'local', 'rem_sysname' => 'Sw-E', 'rem_port' => 'p1', 'rem_port_type' => 'interfaceName'],
	['if_index' => 2, 'rem_chassis' => '', 'rem_sysname' => 'Sw-F', 'rem_port' => 'p1', 'rem_port_type' => 'interfaceName']]);
run_ingest($dsn, $user, $password);
check(count(others($pdo)) === 2 && array_filter(keys($pdo), static fn (string $k): bool => !str_contains($k, 'a0:00:00:00:00:01')) === [], 'an empty chassis value: no key, the claims go by their names (two Devices)');
echo "\n  -- a reporter whose own chassis has an undefined subtype: no hardware ID, its Device by the SNMP interface\n";
reset_all($pdo);
reporter($pdo, 101, '00:00:00:00', null, []);
$r = run_ingest($dsn, $user, $password);
$reporters = $pdo->query("SELECT id, attrs FROM topo_nodes WHERE type = 'device' AND represented_by_node_id IS NOT NULL")->fetchAll(PDO::FETCH_ASSOC);
check(count($reporters) === 1 && (json_decode($reporters[0]['attrs'], true)['chassis_id'] ?? null) === null && keys($pdo) === [] && in_array($r['code'], [0, 3], true), 'the reporter has a Device with no chassis and no key, the run is not skipped', $r['err']);
reset_all($pdo);
reporter($pdo, 101, '00:00:00:00', null, []);
reporter($pdo, 102, '00:00:00:00', null, []);
run_ingest($dsn, $user, $password);
check((int) $pdo->query("SELECT COUNT(*) FROM topo_nodes WHERE type = 'device'")->fetchColumn() === 2, 'two reporters with the same undefined own chassis: two Devices (no shared key)');

echo "\nAll chassis subtype checks passed.\n";
