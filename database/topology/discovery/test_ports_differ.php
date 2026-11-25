#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * test_ports_differ.php — task 125 part 3, topology-1-model.md 2.104 §4.3, §8.5 (FR-A 5.12 R-14.3, RC-17): the LLDP and the CDP rows of one local port name different remote ports of the same far Device.
 * (a) no link on the port: one device_link with far_port_reason `lldp_cdp_ports_differ` and both ports in the hint; (b) an active port-level link: it keeps its port and gets attrs.ports_differ; (c) the two
 * agree again: the device_link is refined in place, the mark is removed. `links_ports_differ` counts in the run summary. The real ingest.php runs as a process.
 *
 * Usage: test_ports_differ.php <PDO DSN> <database user> [password]
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
$CR = 'a0:00:00:00:00:01';
$CD = 'b0:00:00:00:00:01';

function reset_all(PDO $pdo): void {
	$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
	foreach (['topo_identity_keys', 'topo_observations', 'topo_edges', 'topo_nodes', 'topo_lld_snapshot', 'host_inventory', 'interface', 'items', 'hosts', 'globalmacro'] as $t) {
		$pdo->exec("DELETE FROM {$t}");
	}
	$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
}

function ports(PDO $pdo, int $hostid, int $clock, string $chassis): void {
	$rows = [];
	foreach ([1, 2, 3] as $index) {
		$rows[] = ['if_index' => $index, 'name' => 'Gi0/'.$index, 'loc_chassis' => $chassis];
	}
	snapshot($pdo, $hostid * 10 + 1, $hostid, 1, $clock, $rows);
}

function sees(string $source, string $remote_port): array {
	return ['if_index' => 1, 'rem_chassis' => 'b0:00:00:00:00:01', 'rem_chassis_type' => 'macAddress', 'rem_port' => $remote_port, 'rem_port_type' => 'interfaceName', 'source' => $source];
}

function setup(PDO $pdo, int $clock): void {
	reset_all($pdo);
	add_host($pdo, 1, 'R', 'R', '10.0.0.1');
	add_host($pdo, 2, 'D', 'D', '10.0.0.2');
	ports($pdo, 1, $clock, 'a0:00:00:00:00:01');
	ports($pdo, 2, $clock, 'b0:00:00:00:00:01');
	$pdo->prepare('REPLACE INTO items (itemid, hostid, status, topology_type) VALUES (12, 1, 0, 2), (13, 1, 0, 2)')->execute();
}

function rules(PDO $pdo, int $clock, string $lldp_port, string $cdp_port): void {
	snapshot($pdo, 12, 1, 2, $clock, [sees('lldp', $lldp_port)]);
	snapshot($pdo, 13, 1, 2, $clock, [sees('cdp', $cdp_port)]);
}

function links(PDO $pdo): array {
	$rows = $pdo->query("SELECT e.id, e.type, e.attrs, a.attrs AS a_attrs, b.attrs AS b_attrs FROM topo_edges e JOIN topo_nodes a ON a.id = e.src_id JOIN topo_nodes b ON b.id = e.dst_id WHERE e.type IN ('physical_link', 'device_link') ORDER BY e.id")->fetchAll(PDO::FETCH_ASSOC);
	return array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'type' => $r['type'], 'attrs' => json_decode($r['attrs'], true), 'a' => json_decode($r['a_attrs'], true)['name'] ?? null,
		'b' => $r['type'] === 'physical_link' ? (json_decode($r['b_attrs'], true)['name'] ?? null) : null], $rows);
}

function outcomes(PDO $pdo): array {
	return $pdo->query('SELECT outcome FROM topo_observations ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
}

echo "== (a) a free port: the two rows name Gi0/1 and Gi0/2 of D ==\n";
setup($pdo, $NOW);
rules($pdo, $NOW, 'Gi0/1', 'Gi0/2');
$r = run_ingest($dsn, $user, $password);
check($r['code'] === 0 || $r['code'] === 3, 'ingest runs', $r['err']);
$l = links($pdo);
check(count($l) === 1 && $l[0]['type'] === 'device_link', 'one device_link, no port-level link', $l);
check(($l[0]['attrs']['far_port_reason'] ?? null) === 'lldp_cdp_ports_differ', 'the reason is lldp_cdp_ports_differ', $l[0]['attrs']);
check(($l[0]['attrs']['far_port_hint'] ?? null) === ['lldp' => 'Gi0/1', 'cdp' => 'Gi0/2'], 'the hint has both ports', $l[0]['attrs']);
check(($r['summary']['links_ports_differ'] ?? -1) === 1, 'links_ports_differ = 1 in the run summary', $r['summary']['links_ports_differ'] ?? null);
check(outcomes($pdo) === ['applied', 'applied'], 'both observations are applied (one link)', outcomes($pdo));
$edge = $l[0]['id'];
$r = run_ingest($dsn, $user, $password);
check(links($pdo) === $l, 'a second run changes nothing', links($pdo));

echo "== (c) the two agree again: the device_link is refined in place, no mark ==\n";
rules($pdo, $NOW + 60, 'Gi0/1', 'Gi0/1');
$r = run_ingest($dsn, $user, $password);
$l = links($pdo);
check(count($l) === 1 && $l[0]['type'] === 'physical_link' && $l[0]['id'] === $edge && !isset($l[0]['attrs']['ports_differ']), 'the same edge is a physical_link now (refined), no ports_differ', $l);
check(($r['summary']['links_ports_differ'] ?? -1) === 0, 'links_ports_differ = 0', $r['summary']['links_ports_differ'] ?? null);

echo "== (b) an active port-level link: it keeps its port, the mark is written ==\n";
rules($pdo, $NOW + 120, 'Gi0/1', 'Gi0/2');
$r = run_ingest($dsn, $user, $password);
$l = links($pdo);
check(count($l) === 1 && $l[0]['type'] === 'physical_link' && $l[0]['id'] === $edge && $l[0]['a'] === 'Gi0/1' && $l[0]['b'] === 'Gi0/1', 'the link stays R:Gi0/1 - D:Gi0/1, no new link', $l);
check(($l[0]['attrs']['ports_differ'] ?? null) === ['lldp' => 'Gi0/1', 'cdp' => 'Gi0/2'], 'attrs.ports_differ has both ports', $l[0]['attrs']);
check(outcomes($pdo) === ['applied', 'applied'], 'both observations are applied: the link is confirmed (no conflict)', outcomes($pdo));
check(($r['summary']['links_ports_differ'] ?? -1) === 1, 'links_ports_differ = 1', $r['summary']['links_ports_differ'] ?? null);
check(!scalar($pdo, "SELECT COUNT(*) FROM topo_observations WHERE precision_lower = 1"), 'no observation says "lower": the link is confirmed at port level by the source that names its port');

echo "== the CDP names the port of the link, the LLDP another one (the other way round) ==\n";
rules($pdo, $NOW + 180, 'Gi0/3', 'Gi0/1');
$r = run_ingest($dsn, $user, $password);
$l = links($pdo);
check(count($l) === 1 && $l[0]['id'] === $edge && ($l[0]['attrs']['ports_differ'] ?? null) === ['lldp' => 'Gi0/3', 'cdp' => 'Gi0/1'], 'the link stays and the mark follows the latest rows', $l);

echo "== (c) the two agree again: the mark is removed ==\n";
rules($pdo, $NOW + 240, 'Gi0/1', 'Gi0/1');
$r = run_ingest($dsn, $user, $password);
$l = links($pdo);
check(count($l) === 1 && !isset($l[0]['attrs']['ports_differ']), 'ports_differ is removed', $l);
check(($r['summary']['links_ports_differ'] ?? -1) === 0, 'links_ports_differ = 0', $r['summary']['links_ports_differ'] ?? null);

echo "== the same port of D from LLDP and CDP is not a difference ==\n";
setup($pdo, $NOW);
rules($pdo, $NOW, 'Gi0/2', 'Gi0/2');
$r = run_ingest($dsn, $user, $password);
$l = links($pdo);
check(count($l) === 1 && $l[0]['type'] === 'physical_link' && $l[0]['b'] === 'Gi0/2' && !isset($l[0]['attrs']['ports_differ']) && ($r['summary']['links_ports_differ'] ?? -1) === 0, 'one port-level link, no mark', $l);

echo "OK: all checks passed\n";
