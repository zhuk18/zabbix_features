#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * test_ifname_local_port.php — task 125 part 2, topology-1-model.md 2.104 §5.2, §6.7.6 (FR-B D-9, D-56; FR-A R-23): a NEIGHBORS row with only the name of its local port (`if_name`, no `if_index`, no
 * `loc_port_num`) is resolved by name against the PORTS rows of the reporter (step 2 only: cleaned names, then the name key); no match is `name_not_found`, several ports `name_on_several_ports`, no PORTS
 * snapshot `no_ports`; the claim reference is `name:<cleaned name>`. The real ingest.php runs as a process.
 *
 * Usage: test_ifname_local_port.php <PDO DSN> <database user> [password]
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




$NOW = time();
$C1 = 'a0:00:00:00:00:01';
$X = 'b0:00:00:00:00:0';

function reset_all(PDO $pdo): void {
	$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
	foreach (['topo_identity_keys', 'topo_observations', 'topo_edges', 'topo_nodes', 'topo_lld_snapshot', 'host_inventory', 'interface', 'items', 'hosts', 'globalmacro'] as $t) {
		$pdo->exec("DELETE FROM {$t}");
	}
	$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
}

function ports(PDO $pdo, int $hostid, int $clock, array $names): void {
	$rows = [];
	foreach ($names as $index => $name) {
		$rows[] = ['if_index' => $index, 'name' => $name, 'loc_chassis' => 'a0:00:00:00:00:01'];
	}
	snapshot($pdo, $hostid * 10 + 1, $hostid, 1, $clock, $rows);
}

function neighbor_row(array $local, string $chassis, string $remote_port): array {
	return $local + ['rem_chassis' => $chassis, 'rem_chassis_type' => 'macAddress', 'rem_port' => $remote_port, 'rem_port_type' => 'interfaceName', 'source' => 'lldp'];
}

function observation(PDO $pdo, string $ref): ?array {
	$stmt = $pdo->prepare('SELECT outcome, local_port_ref, local_port_id FROM topo_observations WHERE local_port_ref = ?');
	$stmt->execute([$ref]);
	$row = $stmt->fetch(PDO::FETCH_ASSOC);
	return $row ?: null;
}

function links(PDO $pdo): int {
	return (int) scalar($pdo, "SELECT COUNT(*) FROM topo_edges WHERE type IN ('physical_link', 'device_link')");
}

echo "== a host with a Ports rule: one name that matches, one on two ports, one that matches nothing ==\n";
reset_all($pdo);
add_host($pdo, 1, 'SW1', 'SW1', '10.0.0.1');
$pdo->prepare('REPLACE INTO items (itemid, hostid, status, topology_type) VALUES (12, 1, 0, 2)')->execute();
ports($pdo, 1, $NOW, [1 => 'Gi0/1', 2 => 'Eth1', 3 => 'eth1', 4 => 'Gi0/4']);
snapshot($pdo, 12, 1, 2, $NOW, [
	neighbor_row(['if_name' => 'Gi0/1'], $X.'1', 'p1'),
	neighbor_row(['if_name' => 'ETH1'], $X.'2', 'p1'),
	neighbor_row(['if_name' => 'Gi9/9'], $X.'3', 'p1'),
	neighbor_row(['if_name' => '  gi0/4 '], $X.'4', 'p1'),
	neighbor_row(['if_index' => 3, 'if_name' => 'Gi9/9'], $X.'5', 'p1'),
]);
$r = run_ingest($dsn, $user, $password);
check($r['code'] === 0 || $r['code'] === 3, 'ingest runs', $r['err']);
$u = $r['summary']['rows_local_port_unresolved'] ?? [];
check(($u['by_reason']['name_not_found'] ?? -1) === 1, 'the name that matches nothing is counted as name_not_found', $u);
check(($u['by_reason']['name_on_several_ports'] ?? -1) === 1, 'the name on two ports (the name key of Eth1 and eth1 is one) is counted as name_on_several_ports', $u);
check(($u['count'] ?? -1) === 2 && ($u['by_rule'][12] ?? null) === ['name_on_several_ports' => 1, 'name_not_found' => 1] || ($u['by_rule'][12] ?? null) === ['name_not_found' => 1, 'name_on_several_ports' => 1], 'the two rows are counted for the rule 12', $u);
$o1 = observation($pdo, 'name:Gi0/1');
check($o1 !== null && $o1['local_port_id'] !== null, 'the row with the name Gi0/1 has an observation with a local port; its claim reference is name:Gi0/1', $o1);
$o4 = observation($pdo, 'name:gi0/4');
check($o4 !== null && $o4['local_port_id'] !== null, 'a name with white space and another case is cleaned and found (Gi0/4); the reference is the cleaned text', $o4);
check(observation($pdo, 'name:ETH1') === null && observation($pdo, 'name:Gi9/9') === null, 'the unresolved rows have no observation (§6.7.6: the row is dropped and counted)');
check(links($pdo) === 3, 'three links: the two names, and the row with `if_index` 3 (its if_name is not looked at)', links($pdo));
check(observation($pdo, 'ifindex:3') !== null, 'the row with an if_index keeps its ifindex reference', null);

echo "== a row with a loc_port_num and an if_name is the old row ==\n";
reset_all($pdo);
add_host($pdo, 1, 'SW1', 'SW1', '10.0.0.1');
$pdo->prepare('REPLACE INTO items (itemid, hostid, status, topology_type) VALUES (12, 1, 0, 2)')->execute();
ports($pdo, 1, $NOW, [1 => 'Gi0/1', 2 => 'Gi0/2']);
snapshot($pdo, 12, 1, 2, $NOW, [neighbor_row(['loc_port_num' => 2, 'if_name' => 'Gi9/9'], $X.'1', 'p1')]);
$r = run_ingest($dsn, $user, $password);
check(observation($pdo, 'num:2') !== null && ($r['summary']['rows_local_port_unresolved']['count'] ?? -1) === 0, 'with a loc_port_num the name is not looked at (old rule, step 3: the number is an ifIndex)', $r['summary']['rows_local_port_unresolved'] ?? null);

echo "== the same rule on a host without a Ports rule ==\n";
reset_all($pdo);
add_host($pdo, 1, 'SW1', 'SW1', '10.0.0.1');
$pdo->prepare('REPLACE INTO items (itemid, hostid, status, topology_type) VALUES (12, 1, 0, 2)')->execute();
snapshot($pdo, 12, 1, 2, $NOW, [neighbor_row(['if_name' => 'Gi0/1'], $X.'1', 'p1')]);
$r = run_ingest($dsn, $user, $password);
$u = $r['summary']['rows_local_port_unresolved'] ?? [];
check(($u['by_reason']['no_ports'] ?? -1) === 1 && ($u['count'] ?? -1) === 1, 'no PORTS snapshot: the row is no_ports', $u);
check(links($pdo) === 0, 'no link', links($pdo));

echo "== an old claim reference is not changed: loc_port_num still gives num: ==\n";
reset_all($pdo);
add_host($pdo, 1, 'SW1', 'SW1', '10.0.0.1');
$pdo->prepare('REPLACE INTO items (itemid, hostid, status, topology_type) VALUES (12, 1, 0, 2)')->execute();
ports($pdo, 1, $NOW, [1 => 'Gi0/1']);
snapshot($pdo, 12, 1, 2, $NOW, [neighbor_row(['loc_port_num' => 1], $X.'1', 'p1')]);
$r = run_ingest($dsn, $user, $password);
check(observation($pdo, 'num:1') !== null, 'a loc_port_num row has the reference num:1', null);

echo "OK: all checks passed\n";
