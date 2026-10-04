#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * test_pseudo_port_index.php — topology-1-model.md §6.6 "Pseudo ports never take a PORTS row": a pseudo port made from an
 * advertisement has a negative if_index (an internal allocation), so a reporter's real port can never land on it; a pseudo
 * port from a numeric local port id keeps that real ifIndex; ingest moves an existing positive allocated index to a negative one.
 *
 * Same method as test_snapshot_ingest.php. Writes rows straight into topo_lld_snapshot (what the server's LLD worker would store) and runs the REAL
 * ingest.php as a subprocess after every change, so the actual CLI path is exercised end to end — no test-only
 * hook, no reimplementation of the algorithm here.
 *
 * Usage: test_pseudo_port_index.php <PDO DSN> <database user> [password]
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
foreach (['topo_observations', 'topo_edges', 'topo_nodes', 'topo_lld_snapshot', 'host_inventory', 'interface',
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
	foreach (['topo_observations', 'topo_edges', 'topo_nodes', 'topo_lld_snapshot', 'host_inventory', 'interface', 'items', 'hosts'] as $t) {
		$pdo->exec("DELETE FROM {$t}");
	}
	$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
}

function ports_of_device(PDO $pdo, int $device): array {
	$out = [];
	foreach ($pdo->query("SELECT id, attrs FROM topo_nodes WHERE type='port' AND device_id={$device} ORDER BY id")->fetchAll(PDO::FETCH_ASSOC) as $p) {
		$a = json_decode($p['attrs'], true);
		$out[(int) $p['id']] = ['name' => $a['name'] ?? null, 'if_index' => $a['if_index'] ?? null, 'pseudo' => $a['pseudo'] ?? null];
	}
	return $out;
}

$R = 'a0:00:00:00:00:01';
$X = 'b0:00:00:00:00:0a';
$N = (int) (crc32('p1') % 999999) + 1;       // the index the code of handoff 010 allocates for the pseudo port "p1"

// ============================================================================================================
echo "\n=== 1: D is a neighbor (a pseudo port p1), then becomes a reporter whose real port has the allocated if_index ===\n";
reset_all($pdo);
add_host($pdo, 101, 'SwR', 'Switch R', '10.0.0.1');
snapshot($pdo, 1001, 101, 1, CLOCK, [['if_index' => 1, 'name' => 'Gi0/1', 'loc_chassis' => $R]]);
snapshot($pdo, 1002, 101, 2, CLOCK + 10, [['if_index' => 1, 'rem_chassis' => $X, 'rem_chassis_type' => 'macAddress', 'rem_sysname' => 'D',
	'rem_port' => 'p1', 'rem_port_type' => 'interfaceName', 'source' => 'lldp', 'loc_chassis' => $R]]);
run_ingest($dsn, $user, $password);
$D = (int) device_id($pdo, $X);
$before = ports_of_device($pdo, $D);
$pseudo_id = (int) array_key_first($before);
echo '  observed before: '.json_encode($before)."  (allocated index expected: {$N})\n";
$link_before = $pdo->query("SELECT id, src_id, dst_id FROM topo_edges WHERE type='physical_link'")->fetchAll(PDO::FETCH_ASSOC);

add_host($pdo, 102, 'SwD', 'Switch D', '10.0.0.2');
snapshot($pdo, 2001, 102, 1, CLOCK + 100, [['if_index' => $N, 'name' => 'Gi0/9', 'loc_chassis' => $X]]);
$r = run_ingest($dsn, $user, $password);
$after = ports_of_device($pdo, $D);
$link_after = $pdo->query("SELECT id, src_id, dst_id, attrs FROM topo_edges WHERE type='physical_link'")->fetchAll(PDO::FETCH_ASSOC);
echo '  observed after: '.json_encode($after)."\n";
echo '  links before: '.json_encode($link_before).' after: '.json_encode(array_map(static fn ($l) => [$l['id'], $l['src_id'], $l['dst_id']], $link_after))."\n";
echo '  pseudo-port counters: '.json_encode(array_intersect_key($r['summary'], array_flip(['pseudo_ports_converted', 'pseudo_ports_ambiguous', 'pseudo_ports_move_failed',
	'pseudo_ports_kept_manual', 'pseudo_ports_removed', 'ports_created'])))."\n";

// what the code of handoff 010 does (characterization; flipped by the fix):
check(isset($before[$pseudo_id]) && $before[$pseudo_id]['pseudo'] === true && $before[$pseudo_id]['if_index'] === $N, 'before: the pseudo port p1 has the allocated positive if_index '.$N);
check(count($after) === 1 && isset($after[$pseudo_id]) && $after[$pseudo_id]['pseudo'] === false && $after[$pseudo_id]['name'] === 'Gi0/9',
	'after: the PORTS row landed ON the pseudo port: same row, now a real port named Gi0/9, the name p1 is gone');
check(count($link_after) === 1 && (int) $link_after[0]['id'] === (int) $link_before[0]['id'], 'the link stayed on that row (the same edge): it now ends at Gi0/9, not at p1');

echo "\nAll pseudo port index checks passed.\n";
