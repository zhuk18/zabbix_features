#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * test_identity_keys.php — the identity key table (topology-1-model.md §6.7.0), handoff 017 section 2.
 *
 * Devices that hold no key (data written before the table) get their keys from their attributes in the next ingest run:
 * the segment of a reporter's Device is the segment of its host, of any other Device the segment of the reporter of its latest
 * observation; a MAC chassis is global; a Device whose scoped keys cannot be built is reported and skipped. The run summary counts them.
 *
 * Usage: test_identity_keys.php <PDO DSN> <database user> [password]
 * Expects a THROWAWAY database (drops and recreates the topology tables and stand-ins of the Zabbix tables).
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
foreach (['topo_identity_keys', 'topo_observations', 'topo_edges', 'topo_nodes', 'topo_lld_snapshot', 'host_inventory', 'interface', 'items', 'proxy', 'hosts'] as $table) {
	$pdo->exec("DROP TABLE IF EXISTS {$table}");
}
$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
$pdo->exec("CREATE TABLE hosts (hostid BIGINT UNSIGNED PRIMARY KEY, host VARCHAR(128) NOT NULL, name VARCHAR(128) NOT NULL DEFAULT '', status INT NOT NULL DEFAULT 0,".
	" monitored_by INT NOT NULL DEFAULT 0, proxyid BIGINT UNSIGNED NULL, proxy_groupid BIGINT UNSIGNED NULL)");
$pdo->exec('CREATE TABLE proxy (proxyid BIGINT UNSIGNED PRIMARY KEY)');
$pdo->exec('CREATE TABLE items (itemid BIGINT UNSIGNED PRIMARY KEY, hostid BIGINT UNSIGNED NOT NULL, status INT NOT NULL DEFAULT 0, topology_role INT NOT NULL DEFAULT 0, type INT NOT NULL DEFAULT 0)');
$pdo->exec("CREATE TABLE interface (interfaceid BIGINT UNSIGNED PRIMARY KEY, hostid BIGINT UNSIGNED NOT NULL, type INT NOT NULL, useip INT NOT NULL DEFAULT 1, ip VARCHAR(64) NOT NULL DEFAULT '', main INT NOT NULL DEFAULT 1)");
$pdo->exec("CREATE TABLE host_inventory (hostid BIGINT UNSIGNED PRIMARY KEY, macaddress_a VARCHAR(64) NOT NULL DEFAULT '', macaddress_b VARCHAR(64) NOT NULL DEFAULT '')");
$pdo->exec('CREATE TABLE topo_lld_snapshot (itemid BIGINT UNSIGNED PRIMARY KEY, hostid BIGINT UNSIGNED NOT NULL, role INT NOT NULL DEFAULT 0,'.
	' clock INT NOT NULL DEFAULT 0, rows_hash VARCHAR(64) NOT NULL DEFAULT \'\', rows_json LONGTEXT NOT NULL, rows_total INT NOT NULL DEFAULT 0,'.
	' rows_valid INT NOT NULL DEFAULT 0)');
$pdo->exec(file_get_contents(__DIR__.'/../mysql_schema.sql'));

// hosts: A behind proxy 5, B in proxy group 3 (polled by proxy 9), S on the server
$pdo->exec("INSERT INTO proxy (proxyid) VALUES (5), (9)");
$pdo->exec("INSERT INTO hosts (hostid, host, name, monitored_by, proxyid, proxy_groupid) VALUES (701, 'rep-a', 'rep-a', 1, 5, NULL),".
	" (702, 'rep-b', 'rep-b', 2, 9, 3), (703, 'rep-s', 'rep-s', 0, NULL, NULL)");
$node = static function (string $type, array $attrs, ?int $host_ref = null) use ($pdo): int {
	$pdo->prepare('INSERT INTO topo_nodes (type, host_ref, attrs, created_at, updated_at) VALUES (?, ?, ?, 1, 1)')
		->execute([$type, $host_ref, json_encode($attrs)]);
	return (int) $pdo->lastInsertId();
};
$represent = static function (int $device, int $host_node) use ($pdo): void {
	$pdo->prepare("UPDATE topo_nodes SET represented_by_node_id = ?, represented_by_matched_by = 'reporter_self', represented_by_at = 1 WHERE id = ?")->execute([$host_node, $device]);
};

// D1: reporter A's own Device (chassis + IP); D2: a reporter in a proxy group, with a replaced chassis; D3: no reporter, seen by A's rule;
// D4: no reporter, never observed, IP only; D5: no reporter, never observed, MAC chassis only; D6: text chassis, never observed
$d1 = $node('device', ['chassis_id' => '02:00:00:00:07:01', 'mac' => '02:00:00:00:07:01', 'mgmt_ip' => '10.7.0.1', 'sysname' => 'rep-a', 'last_seen' => 5]);
$represent($d1, $node('host', [], 701));
$d2 = $node('device', ['chassis_id' => '02:00:00:00:07:02', 'replaced_chassis' => ['02:00:00:00:07:99'], 'mgmt_ip' => '10.7.0.2', 'sysname' => 'rep-b', 'last_seen' => 5]);
$represent($d2, $node('host', [], 702));
$d3 = $node('device', ['chassis_id' => null, 'mgmt_ip' => '10.7.0.3', 'sysname' => 'seen', 'last_seen' => 5]);
$d4 = $node('device', ['chassis_id' => null, 'mgmt_ip' => '10.7.0.4', 'sysname' => 'orphan-ip', 'last_seen' => 5]);
$d5 = $node('device', ['chassis_id' => '02:00:00:00:07:05', 'mgmt_ip' => null, 'sysname' => 'orphan-mac', 'last_seen' => 5]);
$d6 = $node('device', ['chassis_id' => 'text-chassis', 'mgmt_ip' => null, 'sysname' => 'orphan-text', 'last_seen' => 5]);
$port = $node('port', ['if_index' => 1, 'name' => 'Gi0/1']);
$pdo->prepare('UPDATE topo_nodes SET device_id = ? WHERE id = ?')->execute([$d1, $port]);
$pdo->exec('INSERT INTO items (itemid, hostid) VALUES (7001, 701), (7002, 703)');
foreach ([[7002, 5], [7001, 9]] as [$item, $seen]) {          // the older observation by the server host, the later one by host A
	$pdo->prepare("INSERT INTO topo_observations (itemid, local_port_id, remote_key, remote_attrs, outcome, device_id, first_seen, last_seen) VALUES (?, ?, ?, '{}', 'applied', ?, 1, ?)")
		->execute([$item, $port, 'k'.$item, $d3, $seen]);
}

$tmp = sys_get_temp_dir().'/topology-identity-keys-test';
@mkdir($tmp);
$env = ['TMPDIR' => $tmp, 'TOPOLOGY_PDO_DSN' => $dsn, 'TOPOLOGY_PDO_USER' => $user, 'TOPOLOGY_PDO_PASSWORD' => $password, 'PATH' => (string) getenv('PATH')];
$run = static function () use ($env, $tmp): array {
	$process = proc_open([PHP_BINARY, __DIR__.'/ingest.php'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
	stream_get_contents($pipes[1]);
	$err = stream_get_contents($pipes[2]);
	proc_close($process);
	$status = json_decode((string) @file_get_contents($tmp.'/topology-ingest-status.json'), true);
	return [$status['summary'] ?? [], $err];
};
$keys = static function (int $device) use ($pdo): array {
	$stmt = $pdo->prepare('SELECT `key`, replaced FROM topo_identity_keys WHERE device_id = ? ORDER BY `key`');
	$stmt->execute([$device]);
	return array_map('intval', $stmt->fetchAll(PDO::FETCH_KEY_PAIR));
};

echo "\n=== keys built from the attributes in one run ===\n";
[$summary, $err] = $run();
check($err === '', 'the run is clean '.$err);
check($keys($d1) === ['chassis:mac:02:00:00:00:07:01' => 0, 'ip:proxy:5:10.7.0.1' => 0], 'a reporter\'s Device: the MAC chassis (global) and the address in the segment of its host (proxy:5)');
check($keys($d2) === ['chassis:mac:02:00:00:00:07:02' => 0, 'chassis:mac:02:00:00:00:07:99' => 1, 'ip:pg:3:10.7.0.2' => 0],
	'a reporter in a proxy group: the segment is the group (pg:3), the replaced chassis is a key with replaced = 1');
check($keys($d3) === ['ip:proxy:5:10.7.0.3' => 0], 'a Device with no reporter: the segment of the reporter of its LATEST observation (proxy:5, not the server)');
check($keys($d4) === [], 'no reporter, no observation: no scoped key');
check($keys($d5) === ['chassis:mac:02:00:00:00:07:05' => 0], 'no reporter, no observation, MAC chassis only: the global key does not need a segment');
check($keys($d6) === [], 'a text chassis needs a segment: skipped');
$built = $summary['identity_keys_built'] ?? [];
check(($built['devices'] ?? null) === 6 && ($built['keys'] ?? null) === 7 && ($built['skipped'] ?? null) === [$d4, $d6],
	'the summary counts the Devices (6), the keys (7) and lists the skipped Devices: '.json_encode($built));

echo "\n=== a second run builds nothing and changes nothing ===\n";
$before = [$keys($d1), $keys($d2), $keys($d3), $keys($d5)];
[$summary] = $run();
check([$keys($d1), $keys($d2), $keys($d3), $keys($d5)] === $before, 'the keys are the same');
check(($summary['identity_keys_built']['keys'] ?? null) === 0, 'no key is built again (the Devices without keys are retried and stay skipped: '.json_encode($summary['identity_keys_built'] ?? null).')');
echo "\nAll identity key checks passed.\n";
