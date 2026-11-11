#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * test_run_input.php — task 088, topology-1-model.md 2.67 §12.7 "Behind the data" and §6.1 "Heartbeat gap": a run writes the rows_hash of the snapshots it read into topo_run_input (a full run
 * replaces all rows, a partial run the rows of its reporters, a run that fails writes nothing, a reporter with a failed step gets no rows, a deleted rule loses its row by the cascade); the number
 * of reporters with new data is the number of hosts with a usable snapshot whose hash is not the stored one. Every run gives stats.heartbeat_gap_max_seconds.
 *
 * Usage: test_run_input.php <PDO DSN> <database user> [password]
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



const OLD = 3600;
$NOW = time();
$C1 = 'a0:00:00:00:00:01';
$C2 = 'a0:00:00:00:00:02';
$C3 = 'a0:00:00:00:00:03';

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

/** A reporter with 4 ports: PORTS rule H*10+1 (a fresh clock), NEIGHBORS rule H*10+2 with the given rows and clock. */
function reporter(PDO $pdo, int $hostid, string $chassis, array $neighbors, int $clock): void {
	global $NOW;
	add_host($pdo, $hostid, 'SW'.$hostid, 'Switch '.$hostid, '10.0.0.'.$hostid);
	$ports = array_map(static fn (int $i): array => ['if_index' => $i, 'name' => 'Gi0/'.$i, 'loc_chassis' => $chassis, 'itemids' => [$hostid * 100 + $i]], range(1, 4));
	snapshot($pdo, $hostid * 10 + 1, $hostid, 1, $NOW, $ports);
	snapshot($pdo, $hostid * 10 + 2, $hostid, 2, $clock, array_map(static fn (array $r): array => $r + ['loc_chassis' => $chassis, 'source' => 'lldp'], $neighbors));
}

function sees(int $if, string $chassis, string $port): array {
	return ['if_index' => $if, 'rem_chassis' => $chassis, 'rem_chassis_type' => 'macAddress', 'rem_port' => $port, 'rem_port_type' => 'interfaceName'];
}

function links(PDO $pdo): array {
	$out = [];
	foreach ($pdo->query("SELECT e.id, e.attrs, a.device_id AS da, a.attrs AS pa, b.device_id AS db, b.attrs AS pb FROM topo_edges e JOIN topo_nodes a ON a.id = e.src_id JOIN topo_nodes b ON b.id = e.dst_id WHERE e.type = 'physical_link'")->fetchAll(PDO::FETCH_ASSOC) as $row) {
		$attrs = json_decode($row['attrs'], true);
		$name = static function ($device, $port_attrs) use ($pdo): string {
			$d = json_decode((string) $pdo->query('SELECT attrs FROM topo_nodes WHERE id = '.(int) $device)->fetchColumn(), true);
			return ($d['chassis_id'] ?? '?').'/'.(json_decode($port_attrs, true)['name'] ?? '?');
		};
		$out[] = ['id' => (int) $row['id'], 'ends' => [$name($row['da'], $row['pa']), $name($row['db'], $row['pb'])], 'superseded' => isset($attrs['superseded_at']), 'last_seen' => $attrs['last_seen'] ?? null];
	}
	return $out;
}

function active(PDO $pdo): array {
	$ends = [];
	foreach (links($pdo) as $link) {
		if (!$link['superseded']) {
			sort($link['ends']);
			$ends[] = implode(' - ', $link['ends']);
		}
	}
	sort($ends);
	return $ends;
}

function outcomes(PDO $pdo, int $itemid): array {
	$rows = $pdo->prepare('SELECT outcome FROM topo_observations WHERE itemid = ? ORDER BY id');
	$rows->execute([$itemid]);
	return array_values(array_unique($rows->fetchAll(PDO::FETCH_COLUMN)));
}

function fingerprint(PDO $pdo): string {
	$edges = $pdo->query('SELECT id, src_id, dst_id, attrs FROM topo_edges ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
	$obs = $pdo->query('SELECT id, itemid, local_port_id, remote_key, outcome, edge_id, device_id, first_seen, last_seen FROM topo_observations ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
	$nodes = $pdo->query('SELECT id, type, attrs FROM topo_nodes ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
	return md5(json_encode([$edges, $obs, $nodes]));
}

/** The count of GET /topo/ingest/status: hosts with a usable snapshot whose rows_hash is not the stored one (the same SQL as CTopologyRun::reportersNewData). */
function new_data(PDO $pdo): int {
	return (int) scalar($pdo, 'SELECT COUNT(DISTINCT s.hostid) FROM topo_lld_snapshot s JOIN items i ON i.itemid = s.itemid AND i.status = 0 AND i.topology_type = s.topology_type'.
		' JOIN hosts h ON h.hostid = s.hostid AND h.status = 0 LEFT JOIN topo_run_input r ON r.itemid = s.itemid WHERE r.itemid IS NULL OR r.rows_hash <> s.rows_hash');
}

function inputs(PDO $pdo): array {
	return $pdo->query('SELECT itemid, rows_hash FROM topo_run_input ORDER BY itemid')->fetchAll(PDO::FETCH_KEY_PAIR);
}

function changed(PDO $pdo, int $itemid): void {
	$pdo->prepare("UPDATE topo_lld_snapshot SET rows_hash = CONCAT('c', ?) WHERE itemid = ?")->execute([bin2hex(random_bytes(3)), $itemid]);
}

function gap_ok(array $r, string $what): void {
	$gap = $r['summary']['stats']['heartbeat_gap_max_seconds'] ?? null;
	check(is_int($gap) && $gap >= 0 && str_contains($r['out'], 'heartbeat gap:'), $what.': stats.heartbeat_gap_max_seconds is a number >= 0 and the CLI text shows it', [$gap, $r['summary']['stats'] ?? null]);
}

echo "\n=== 2. rows_hash of the snapshots a run read ===\n";
reset_all($pdo);
reporter($pdo, 101, $C1, [sees(1, $C2, 'Gi0/1')], $NOW);
snapshot($pdo, 1013, 101, 2, $NOW, [sees(2, $C3, 'Gi0/1') + ['loc_chassis' => $C1, 'source' => 'lldp']]);      // a second NEIGHBORS rule on the host 101
reporter($pdo, 102, $C2, [sees(1, $C1, 'Gi0/1')], $NOW);
$all = (int) scalar($pdo, 'SELECT COUNT(*) FROM topo_lld_snapshot');
check(new_data($pdo) === 2, 'before any run both hosts have new data');
$r = run_ingest($dsn, $user, $password);
gap_ok($r, 'the first run');
check(count(inputs($pdo)) === $all && new_data($pdo) === 0, 'a run, then no change: N = 0, a row for each snapshot', [count(inputs($pdo)), $all]);
$r = run_ingest($dsn, $user, $password);
check(new_data($pdo) === 0, 'a second run: still 0');
gap_ok($r, 'the second run');

changed($pdo, 1012);
check(new_data($pdo) === 1, 'one rule\'s rows change: N = 1');
changed($pdo, 1013);
check(new_data($pdo) === 1, 'a second changed rule of the same host: still N = 1 (one host)');
changed($pdo, 1022);
check(new_data($pdo) === 2, 'a rule of the other host: N = 2');

echo "\n=== a partial run of one host ===\n";
$r = run_ingest($dsn, $user, $password, ['SW101']);
check(new_data($pdo) === 1, 'a partial run of SW101 reads its host: N = 1 (only SW102 left)', new_data($pdo));
check(inputs($pdo)[1012] === scalar($pdo, 'SELECT rows_hash FROM topo_lld_snapshot WHERE itemid = 1012') && inputs($pdo)[1022] !== scalar($pdo, 'SELECT rows_hash FROM topo_lld_snapshot WHERE itemid = 1022'),
	'it replaced the rows of its reporter only');
gap_ok($r, 'a partial run');
$r = run_ingest($dsn, $user, $password);
check(new_data($pdo) === 0, 'a full run: N = 0');

echo "\n=== a reporter with a failed step gets no rows ===\n";
changed($pdo, 1012);
$r = run_ingest($dsn, $user, $password, [], ['fail-pass-b' => '101']);
check(!isset(inputs($pdo)[1011]) && !isset(inputs($pdo)[1012]) && !isset(inputs($pdo)[1013]) && isset(inputs($pdo)[1022]) && new_data($pdo) === 1,
	'the failed host has no rows (so it counts), the other host has its rows', [array_keys(inputs($pdo)), new_data($pdo)]);
$r = run_ingest($dsn, $user, $password);
check(new_data($pdo) === 0, 'the next full run reads it again: N = 0');

echo "\n=== a run that fails writes nothing ===\n";
$pdo->exec('DELETE FROM items WHERE itemid IN (1021, 1022)');
check(!isset(inputs($pdo)[1021]) && !isset(inputs($pdo)[1022]), 'a deleted rule: its rows are gone (the cascade)', array_keys(inputs($pdo)));
$before = inputs($pdo);
changed($pdo, 1012);
$r = run_ingest($dsn, $user, $password, [], ['fail-pass-b' => '101']);
check(($r['status']['status'] ?? '') === 'error' && inputs($pdo) === $before,
	'a run that fails (its only reporter failed) leaves topo_run_input as it was', [$r['status']['status'] ?? null, inputs($pdo), $before]);

echo "\nAll run input checks passed.\n";
