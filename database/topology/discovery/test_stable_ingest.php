#!/usr/bin/env php
<?php

declare(strict_types=1);


/**
 * test_stable_ingest.php — task 038, topology-1-model.md 2.29: §6.7.1 "A key never makes a second Device", "Source priority of attributes",
 * "Same-port join"; §7.1 rows with the same claim key; §8.5 a port with an active link.
 *
 * Same method as test_join_identity.php: rows go straight into topo_lld_snapshot, the REAL ingest.php runs as a subprocess.
 *
 * Usage: test_stable_ingest.php <PDO DSN> <database user> [password]
 * Expects a THROWAWAY database (it drops and recreates the topology tables and stand-ins of the Zabbix tables).
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
$R = 'a0:00:00:00:00:01';
$Y = 'b0:00:00:00:00:0a';
$IP = '10.5.5.5';

function reset_all(PDO $pdo): void {
	$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
	foreach (['topo_identity_keys', 'topo_observations', 'topo_edges', 'topo_nodes', 'topo_lld_snapshot', 'host_inventory', 'interface', 'items', 'hosts'] as $t) {
		$pdo->exec("DELETE FROM {$t}");
	}
	$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
}

/** Devices, ports, links and observations as short strings (ids included), for the characterization output. */
/** A Device made by hand: old data, or a Device another reporter found first. */
function device(PDO $pdo, ?string $chassis, ?string $ip, string $sysname = ''): int {
	$pdo->prepare("INSERT INTO topo_nodes (type, attrs, created_at, updated_at) VALUES ('device', ?, 1, 1)")
		->execute([json_encode(['mac' => null, 'chassis_id' => $chassis, 'mgmt_ip' => $ip, 'sysname' => $sysname, 'vendor' => 'unknown', 'last_seen' => 1])]);
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

function picture(PDO $pdo): array {
	$nodes = [];
	foreach ($pdo->query('SELECT * FROM topo_nodes ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) as $n) {
		$n['a'] = json_decode($n['attrs'], true) ?: [];
		$nodes[(int) $n['id']] = $n;
	}
	$label = static function (int $id) use ($nodes): string {
		$n = $nodes[$id];
		if ($n['type'] === 'device') {
			return '#'.$id.'('.($n['a']['chassis_id'] ?? '-').'/'.($n['a']['mgmt_ip'] ?? '-').')';
		}
		return '#'.$id.'('.$nodes[(int) $n['device_id']]['a']['sysname'].':'.($n['a']['name'] ?? '?').')';
	};
	$out = ['devices' => [], 'ports' => [], 'links' => [], 'obs' => []];
	foreach ($nodes as $id => $n) {
		if ($n['type'] === 'device') {
			$out['devices'][] = $label($id).($n['represented_by_node_id'] ? ' represented' : '');
		}
		elseif ($n['type'] === 'port' && $n['device_id'] !== null) {
			$out['ports'][] = $label($id).(($n['a']['pseudo'] ?? false) ? '*' : '');
		}
	}
	foreach ($pdo->query("SELECT id, type, src_id, dst_id, attrs FROM topo_edges WHERE type IN ('physical_link','device_link') ORDER BY id")->fetchAll(PDO::FETCH_ASSOC) as $e) {
		$a = json_decode($e['attrs'], true);
		$out['links'][] = '#'.$e['id'].' '.$e['type'].' '.$label((int) $e['src_id']).' -> '.$label((int) $e['dst_id']).(isset($a['superseded_at']) ? ' superseded' : '');
	}
	foreach ($pdo->query('SELECT id, itemid, outcome, device_id, edge_id FROM topo_observations ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) as $o) {
		$out['obs'][] = '#'.$o['id'].' item '.$o['itemid'].' '.$o['outcome'].' device '.($o['device_id'] === null ? '-' : $o['device_id']).' edge '.($o['edge_id'] ?? '-');
	}
	return $out;
}

function nrow(int $if, string $source, array $extra): array {
	return array_merge(['if_index' => $if, 'rem_port' => 'p', 'rem_port_type' => 'interfaceName', 'source' => $source, 'loc_chassis' => 'a0:00:00:00:00:01'], $extra);
}

function counts(PDO $pdo): array {
	return ['nodes' => (int) scalar($pdo, 'SELECT COUNT(*) FROM topo_nodes'), 'keys' => (int) scalar($pdo, 'SELECT COUNT(*) FROM topo_identity_keys'),
		'devices' => (int) scalar($pdo, "SELECT COUNT(*) FROM topo_nodes WHERE type='device'"), 'edges' => (int) scalar($pdo, 'SELECT COUNT(*) FROM topo_edges')];
}

/** The reporter SwR (hostid 101) with 8 ports and the rules: PORTS 1001, LLDP 1002, CDP 1003. */
function reporter(PDO $pdo, array $lldp, array $cdp): void {
	reset_all($pdo);
	add_host($pdo, 101, 'SwR', 'Switch R', '10.0.0.1');
	$ports = [];
	for ($i = 1; $i <= 8; $i++) {
		$ports[] = ['if_index' => $i, 'name' => 'Gi0/'.$i, 'loc_chassis' => 'a0:00:00:00:00:01'];
	}
	snapshot($pdo, 1001, 101, 1, CLOCK, $ports);
	snapshot($pdo, 1002, 101, 2, CLOCK + 10, $lldp);
	snapshot($pdo, 1003, 101, 2, CLOCK + 10, $cdp);
}

function twice(PDO $pdo, string $dsn, string $user, string $password, string $label): array {
	$r1 = run_ingest($dsn, $user, $password);
	$c1 = counts($pdo);
	$p1 = picture($pdo);
	$r2 = run_ingest($dsn, $user, $password);
	$c2 = counts($pdo);
	$r3 = run_ingest($dsn, $user, $password);
	$c3 = counts($pdo);
	echo "  {$label}: run 1 created ".$r1['summary']['devices_created'].' Devices, '.$r1['summary']['links_created']." links; run 2 ".$r2['summary']['devices_created'].'/'.$r2['summary']['links_created']
		.'; outcomes '.json_encode($r2['summary']['observations'] ?? null)."\n";
	return [$r1, $r2, $r3, $c1, $c2, $c3, $p1];
}

// ============================================================================================================
echo "\n=== 1: a key never makes a second Device (model §6.7.1) ===\n";
$X = 'c0:00:00:00:00:01';
$cases = [
	// port 1: LLDP chassis X gets the link; the CDP row of another neighbor, sysname only, is `conflict` (the link has support)
	'conflict, sysname key' => [[nrow(1, 'lldp', ['rem_chassis' => $X, 'rem_chassis_type' => 'macAddress', 'rem_sysname' => 'X'])],
		[nrow(1, 'cdp', ['rem_sysname' => 'Ysys'])], 'conflict'],
	// the same with an IP key
	'conflict, IP key' => [[nrow(2, 'lldp', ['rem_chassis' => $X, 'rem_chassis_type' => 'macAddress', 'rem_sysname' => 'X'])],
		[nrow(2, 'cdp', ['rem_mgmt_ip' => '10.9.9.9', 'rem_sysname' => 'Ysys'])], 'conflict'],
	// the same with a chassis key
	'conflict, chassis key' => [[nrow(3, 'lldp', ['rem_chassis' => $X, 'rem_chassis_type' => 'macAddress', 'rem_sysname' => 'X'])],
		[nrow(3, 'cdp', ['rem_chassis' => 'c0:00:00:00:00:09', 'rem_chassis_type' => 'macAddress', 'rem_sysname' => 'Ysys'])], 'conflict'],
	// two sysname-only neighbors on one port, no link: both `ambiguous`
	'ambiguous, sysname keys' => [[], [nrow(4, 'cdp', ['rem_sysname' => 'Aaa']), nrow(4, 'cdp', ['rem_sysname' => 'Bbb'])], 'ambiguous'],
	'ambiguous, chassis keys' => [[nrow(5, 'lldp', ['rem_chassis' => 'c0:00:00:00:00:05', 'rem_chassis_type' => 'macAddress']),
		nrow(5, 'lldp', ['rem_chassis' => 'c0:00:00:00:00:06', 'rem_chassis_type' => 'macAddress'])], [], 'ambiguous'],
	'ambiguous, IP keys' => [[nrow(6, 'lldp', ['rem_mgmt_ip' => '10.6.6.1', 'rem_sysname' => 'S1']), nrow(6, 'lldp', ['rem_mgmt_ip' => '10.6.6.2', 'rem_sysname' => 'S2'])], [], 'ambiguous'],
	// applied
	'applied, sysname key' => [[], [nrow(7, 'cdp', ['rem_sysname' => 'Wsys'])], 'applied'],
	'applied, IP key' => [[nrow(8, 'lldp', ['rem_mgmt_ip' => '10.8.8.8', 'rem_sysname' => 'Ip8'])], [], 'applied'],
];
foreach ($cases as $name => [$lldp, $cdp, $outcome]) {
	echo "  -- {$name}\n";
	if ($outcome === 'conflict') {
		// the link of the LLDP claim exists first (an active link with support); the claim of the other rule comes later (model §8.4, §8.5)
		reporter($pdo, $lldp, []);
		run_ingest($dsn, $user, $password);
		snapshot($pdo, 1003, 101, 2, CLOCK + 20, $cdp);
	}
	else {
		reporter($pdo, $lldp, $cdp);
	}
	[$r1, $r2, $r3, $c1, $c2, $c3] = twice($pdo, $dsn, $user, $password, $name);
	$seen = $pdo->query('SELECT DISTINCT outcome FROM topo_observations')->fetchAll(PDO::FETCH_COLUMN);
	check(in_array($outcome, $seen, true), "{$name}: a row ends {$outcome}");
	check($r2['summary']['devices_created'] === 0 && $r2['summary']['links_created'] === 0 && $r3['summary']['devices_created'] === 0,
		"{$name}: the second and third run create no Device and no link");
	check($c1 === $c2 && $c2 === $c3, "{$name}: topo_nodes, topo_identity_keys and topo_edges counts are equal after every run ".json_encode($c3));
}
echo "\nALL OK\n";
