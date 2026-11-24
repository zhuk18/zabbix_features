#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * test_port_alias.php — task 119 section 1, topology-1-model.md 2.100 §3.2 / §6.6: the port alias (ifAlias) of a PORTS row is stored on the Port as the row gives it, an empty or missing
 * value clears it, it is never the port name and never used to find a local or a far port. The real ingest.php runs as a process.
 *
 * Usage: test_port_alias.php <PDO DSN> <database user> [password]
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
$C2 = 'a0:00:00:00:00:02';

function reset_all(PDO $pdo): void {
	$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
	foreach (['topo_identity_keys', 'topo_observations', 'topo_edges', 'topo_nodes', 'topo_lld_snapshot', 'host_inventory', 'interface', 'items', 'hosts', 'globalmacro'] as $t) {
		$pdo->exec("DELETE FROM {$t}");
	}
	$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
}

function macro(PDO $pdo, ?string $value): void {
	$pdo->exec('DELETE FROM globalmacro');
	if ($value !== null) {
		$pdo->prepare('INSERT INTO globalmacro (macro, value) VALUES (?, ?)')->execute(['{$TOPOLOGY.LINK.FRESH.SECONDS}', $value]);
	}
}


function sees(int $if, string $chassis, string $port): array {
	return ['if_index' => $if, 'rem_chassis' => $chassis, 'rem_chassis_type' => 'macAddress', 'rem_port' => $port, 'rem_port_type' => 'interfaceName'];
}

function ports_snapshot(PDO $pdo, int $hostid, string $chassis, array $aliases, int $clock): void {
	$ports = array_map(static function (int $i) use ($chassis, $aliases): array {
		$row = ['if_index' => $i, 'name' => 'Gi0/'.$i, 'loc_chassis' => $chassis, 'itemids' => [$i]];
		if (($aliases[$i] ?? null) !== null) {
			$row['alias'] = $aliases[$i];
		}
		return $row;
	}, range(1, 4));
	snapshot($pdo, $hostid * 10 + 1, $hostid, 1, $clock, $ports);
}

function port_attrs(PDO $pdo, string $device_sysname, int $if_index): ?array {
	$stmt = $pdo->prepare("SELECT p.id, p.attrs FROM topo_nodes p JOIN topo_nodes d ON d.id = p.device_id WHERE p.type = 'port' AND JSON_UNQUOTE(JSON_EXTRACT(d.attrs, '$.sysname')) = ? AND JSON_EXTRACT(p.attrs, '$.if_index') = ?");
	$stmt->execute([$device_sysname, $if_index]);
	$row = $stmt->fetch(PDO::FETCH_ASSOC);
	return $row ? ['id' => (int) $row['id']] + json_decode($row['attrs'], true) : null;
}

reset_all($pdo);
add_host($pdo, 1, 'SW1', 'SW1', '10.0.0.1');
snapshot($pdo, 12, 1, 3, $NOW, []);
$pdo->prepare('REPLACE INTO items (itemid, hostid, status, topology_type) VALUES (12, 1, 0, 3)')->execute();

echo "== store, change, clear ==\n";
ports_snapshot($pdo, 1, $C1, [1 => 'uplink to core', 2 => '<b>x</b> & "q"', 3 => ''], $NOW);
$r = run_ingest($dsn, $user, $password);
check($r['code'] === 0, 'ingest runs', $r['err']);
$p1 = port_attrs($pdo, 'SW1', 1) ?? port_attrs($pdo, 'sw1', 1);
if ($p1 === null) {
	$any = $pdo->query("SELECT id, device_id, attrs FROM topo_nodes WHERE type = 'port'")->fetchAll(PDO::FETCH_ASSOC);
	fail_test('no port found '.json_encode($any));
}
check(($p1['alias'] ?? null) === 'uplink to core', 'the alias of the row is stored on the Port', $p1);
check(($p1['name'] ?? null) === 'Gi0/1', 'the alias is not the name', $p1);
$p2 = port_attrs($pdo, 'SW1', 2) ?? port_attrs($pdo, 'sw1', 2);
check(($p2['alias'] ?? null) === '<b>x</b> & "q"', 'markup is stored as text, as given', $p2);
$p3 = port_attrs($pdo, 'SW1', 3) ?? port_attrs($pdo, 'sw1', 3);
check(!array_key_exists('alias', $p3), 'an empty alias is no alias', $p3);
$p4 = port_attrs($pdo, 'SW1', 4) ?? port_attrs($pdo, 'sw1', 4);
check(!array_key_exists('alias', $p4), 'a row without alias has none', $p4);

ports_snapshot($pdo, 1, $C1, [1 => 'uplink to core 2'], $NOW + 60);
$r = run_ingest($dsn, $user, $password);
$p1 = port_attrs($pdo, 'SW1', 1) ?? port_attrs($pdo, 'sw1', 1);
check(($p1['alias'] ?? null) === 'uplink to core 2', 'a changed alias is stored', $p1);
$p2 = port_attrs($pdo, 'SW1', 2) ?? port_attrs($pdo, 'sw1', 2);
check(!array_key_exists('alias', $p2), 'a row without alias clears the stored alias', $p2);

ports_snapshot($pdo, 1, $C1, [], $NOW + 120);
$r = run_ingest($dsn, $user, $password);
$p1 = port_attrs($pdo, 'SW1', 1) ?? port_attrs($pdo, 'sw1', 1);
check(!array_key_exists('alias', $p1), 'the alias is cleared when the next snapshot has none', $p1);

echo "== never used to find a port ==\n";
// SW2 has a port whose alias is "Gi0/1" and a port named "Gi0/2" with the alias "Gi0/1" of the far port: a neighbor row that names the far port "Gi0/1" (SW1 sees SW2 port "Gi0/1")
// must give the port named Gi0/1, not the port with that alias
reset_all($pdo);
add_host($pdo, 1, 'SW1', 'SW1', '10.0.0.1');
add_host($pdo, 2, 'SW2', 'SW2', '10.0.0.2');
snapshot($pdo, 11, 1, 1, $NOW, array_map(static fn (int $i): array => ['if_index' => $i, 'name' => 'Gi0/'.$i, 'loc_chassis' => $C1], range(1, 4)));
snapshot($pdo, 21, 2, 1, $NOW, [
	['if_index' => 1, 'name' => 'Gi0/1', 'loc_chassis' => $C2, 'alias' => 'Gi0/3'],
	['if_index' => 2, 'name' => 'Gi0/2', 'loc_chassis' => $C2, 'alias' => 'Gi0/1'],
	['if_index' => 3, 'name' => 'Gi0/3', 'loc_chassis' => $C2],
	['if_index' => 4, 'name' => 'Gi0/4', 'loc_chassis' => $C2, 'alias' => 'Gi0/2'],
]);
snapshot($pdo, 12, 1, 2, $NOW, [['if_index' => 1, 'loc_chassis' => $C1, 'source' => 'lldp'] + sees(1, $C2, 'Gi0/1')]);
$r = run_ingest($dsn, $user, $password);
check($r['code'] === 0, 'ingest runs with aliases that equal other port names', $r['err']);
$edge = $pdo->query("SELECT a.attrs AS pa, b.attrs AS pb FROM topo_edges e JOIN topo_nodes a ON a.id = e.src_id JOIN topo_nodes b ON b.id = e.dst_id WHERE e.type = 'physical_link'")->fetchAll(PDO::FETCH_ASSOC);
check(count($edge) === 1, 'one link', $edge);
$names = array_map(static fn (array $e): array => [json_decode($e['pa'], true)['name'] ?? null, json_decode($e['pb'], true)['name'] ?? null], $edge);
check($names[0] === ['Gi0/1', 'Gi0/1'], 'the far port is found by its name, not by an alias that is the same text', $names);

echo "OK: all checks passed\n";
