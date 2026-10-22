#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * test_device_last_seen.php — topology-1-model.md §8.7 "Device not seen" (2.43), the source of attrs.last_seen of a Device: the max of (a) the clock of
 * the usable snapshots of its own reporter (represented_by, matched_by reporter_self) and (b) last_seen of the observations with device_id = this Device,
 * any outcome. It only moves forward; two runs without new snapshots change nothing; an older snapshot never makes it smaller.
 *
 * Same method as test_shared_identifier.php: rows straight into topo_lld_snapshot, the real ingest.php as a subprocess.
 *
 * Usage: test_device_last_seen.php <PDO DSN> <database user> [password]
 * Expects a THROWAWAY database (drops and recreates the topology tables and the stand-ins of the Zabbix tables).
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

function run_ingest(string $dsn, string $user, string $password, array $extra = [], ?string $order = null): array {
	$cmd = [PHP_BINARY, __DIR__.'/ingest.php', '--pdo-dsn', $dsn, '--pdo-user', $user, '--pdo-password', $password];
	foreach ($extra as $host) {
		array_push($cmd, '--zabbix-host', $host);
	}
	if ($order !== null) {
		array_push($cmd, '--reporter-order', $order);
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


/** A reporter: its PORTS snapshot at $ports_clock and, when $rows is not null, its NEIGHBORS snapshot at $nbr_clock (items $base, $base + 1). */
function reporter(PDO $pdo, int $hostid, int $base, string $chassis, int $ports_clock, ?array $rows = null, int $nbr_clock = 0): void {
	snapshot($pdo, $base, $hostid, 1, $ports_clock, array_map(static fn (int $i): array => ['if_index' => $i, 'name' => 'Gi0/'.$i, 'if_type' => '6',
		'admin_status' => '1', 'oper_status' => '1', 'loc_chassis' => $chassis], [1, 2]));
	if ($rows !== null) {
		snapshot($pdo, $base + 1, $hostid, 2, $nbr_clock, array_map(static fn (array $r): array => $r + ['loc_chassis' => $chassis, 'source' => 'lldp',
			'rem_port' => 'Gi0/24', 'rem_port_type' => 'interfaceName'], $rows));
	}
}

function last_seen_of(PDO $pdo, string $chassis): ?int {
	$id = device_id($pdo, $chassis);
	return $id === null ? null : (int) (attrs($pdo, $id)['last_seen'] ?? 0);
}

$R1 = 'a0:00:00:00:00:01';
$R2 = 'a0:00:00:00:00:02';
$X = 'b0:00:00:00:00:0a';
$neighbor = static fn (string $chassis, string $name): array => ['if_index' => 1, 'rem_chassis' => $chassis, 'rem_chassis_type' => 'macAddress', 'rem_sysname' => $name];

// ============================================================================================================
echo "\n=== 1: from the reporter's own snapshots only ===\n";
reset_all($pdo);
add_host($pdo, 101, 'SwR1', 'Switch R1', '10.0.0.1');
reporter($pdo, 101, 1001, $R1, CLOCK + 5);
$r = run_ingest($dsn, $user, $password);
check(last_seen_of($pdo, $R1) === CLOCK + 5, 'the reporter Device has the clock of its snapshot');
check(($r['summary']['reporters_skipped'] ?? []) === [], 'nothing is skipped');
echo "  -- a newer snapshot: it moves forward --\n";
reporter($pdo, 101, 1001, $R1, CLOCK + 50);
run_ingest($dsn, $user, $password);
check(last_seen_of($pdo, $R1) === CLOCK + 50, 'the new clock');
echo "  -- no new snapshot: nothing changes --\n";
$fp = fingerprint($pdo);
$r = run_ingest($dsn, $user, $password);
check(fingerprint($pdo) === $fp && ($r['summary']['devices_last_seen_moved'] ?? -1) === 0, 'a second run changes nothing (nodes, edges, observations; no Device moved)');
echo "  -- an older snapshot: never smaller --\n";
reporter($pdo, 101, 1001, $R1, CLOCK + 1);
run_ingest($dsn, $user, $password);
check(last_seen_of($pdo, $R1) === CLOCK + 50, 'an older snapshot leaves it at the larger value');

// ============================================================================================================
echo "\n=== 2: from observations only (a Device without a reporter) ===\n";
reset_all($pdo);
add_host($pdo, 101, 'SwR1', 'Switch R1', '10.0.0.1');
$xid = device($pdo, $X, '10.9.9.9', 'X');
check(last_seen_of($pdo, $X) === 1, 'the stored Device starts at last_seen 1');
reporter($pdo, 101, 1001, $R1, CLOCK, [$neighbor($X, 'X')], CLOCK + 10);
run_ingest($dsn, $user, $password);
check(last_seen_of($pdo, $X) === CLOCK + 10, 'its last_seen is the clock of the observation of the neighbor row');
check(device_id($pdo, $X) === $xid && dev_count($pdo) === 2, 'the same Device, none created');
echo "  -- the observation is seen again later --\n";
reporter($pdo, 101, 1001, $R1, CLOCK, [$neighbor($X, 'X')], CLOCK + 70);
run_ingest($dsn, $user, $password);
check(last_seen_of($pdo, $X) === CLOCK + 70, 'it follows the observation');
$fp = fingerprint($pdo);
$r = run_ingest($dsn, $user, $password);
check(fingerprint($pdo) === $fp && ($r['summary']['devices_last_seen_moved'] ?? -1) === 0, 'a second run changes nothing');
echo "  -- an older snapshot --\n";
reporter($pdo, 101, 1001, $R1, CLOCK, [$neighbor($X, 'X')], CLOCK + 20);
run_ingest($dsn, $user, $password);
check(last_seen_of($pdo, $X) === CLOCK + 70, 'never smaller');

// ============================================================================================================
echo "\n=== 3: both sources: the max ===\n";
foreach (['the observation is newer than the own snapshots' => [CLOCK + 10, CLOCK + 100, CLOCK + 100],
		'the own snapshots are newer than the observation' => [CLOCK + 200, CLOCK + 30, CLOCK + 200]] as $label => [$own_clock, $other_clock, $want]) {
	reset_all($pdo);
	add_host($pdo, 101, 'SwR1', 'Switch R1', '10.0.0.1');
	add_host($pdo, 102, 'SwR2', 'Switch R2', '10.0.0.2');
	reporter($pdo, 101, 1001, $R1, $own_clock);
	reporter($pdo, 102, 2001, $R2, $other_clock - 5, [$neighbor($R1, 'SwR1')], $other_clock);
	run_ingest($dsn, $user, $password);
	check(last_seen_of($pdo, $R1) === $want, "{$label}: the reporter Device of R1 has the larger clock");
	check(last_seen_of($pdo, $R2) === $other_clock, "{$label}: R2 has its own clock");
	$fp = fingerprint($pdo);
	$r = run_ingest($dsn, $user, $password);
	check(fingerprint($pdo) === $fp && ($r['summary']['devices_last_seen_moved'] ?? -1) === 0, "{$label}: a second run changes nothing");
}

echo "\nAll Device last_seen tests passed.\n";
