#!/usr/bin/env php
<?php

declare(strict_types=1);


/**
 * test_stable_ingest.php — task 038, topology-1-model.md 2.29: §6.7.1 "A key never makes a second Device", "Source priority of attributes",
 * "Same-port join"; §7.1 rows with the same claim key; §8.5 a port with an active link.
 *
 * Same method as test_snapshot_ingest.php: rows go straight into topo_lld_snapshot, the REAL ingest.php runs as a subprocess.
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

function exists_device(PDO $pdo, int $id): bool {
	return (int) scalar($pdo, "SELECT COUNT(*) FROM topo_nodes WHERE id = ? AND type = 'device'", [$id]) === 1;
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

// ============================================================================================================
echo "\n=== 2: source priority of attributes (model §6.7.1) ===\n";
$sysname_of = static fn (string $chassis): string => (string) (attrs($pdo, device_id($pdo, $chassis))['sysname'] ?? '');
$lldp_row = nrow(1, 'lldp', ['rem_chassis' => $X, 'rem_chassis_type' => 'macAddress', 'rem_sysname' => 'L-name']);
$cdp_row = nrow(1, 'cdp', ['rem_chassis' => $X, 'rem_chassis_type' => 'macAddress', 'rem_sysname' => 'C-name']);
foreach (['LLDP row first in pass B (item order)' => [1002, 1003], 'CDP row first in pass B (item order)' => [1003, 1002]] as $label => [$lldp_item, $cdp_item]) {
	reporter($pdo, [], []);
	snapshot($pdo, $lldp_item, 101, 2, CLOCK + 10, [$lldp_row]);
	snapshot($pdo, $cdp_item, 101, 2, CLOCK + 10, [$cdp_row]);
	run_ingest($dsn, $user, $password);
	check($sysname_of($X) === 'L-name', "{$label}: LLDP and CDP rows of one Device with different rem_sysname -> the LLDP value");
	run_ingest($dsn, $user, $password);
	check($sysname_of($X) === 'L-name', "{$label}: and the next run keeps it");
}
reporter($pdo, [], [$cdp_row]);
run_ingest($dsn, $user, $password);
check($sysname_of($X) === 'C-name', 'CDP only -> the CDP value');
snapshot($pdo, 1003, 101, 2, CLOCK + 20, [array_merge($cdp_row, ['rem_sysname' => 'C-name-2'])]);
run_ingest($dsn, $user, $password);
check($sysname_of($X) === 'C-name-2', 'CDP only, the CDP value changes -> the last CDP row wins (one priority)');
snapshot($pdo, 1002, 101, 2, CLOCK + 30, [$lldp_row]);
run_ingest($dsn, $user, $password);
check($sysname_of($X) === 'L-name', 'then an LLDP row replaces the CDP value');
snapshot($pdo, 1002, 101, 2, CLOCK + 40, []);
snapshot($pdo, 1003, 101, 2, CLOCK + 40, [array_merge($cdp_row, ['rem_sysname' => 'C-name-3'])]);
run_ingest($dsn, $user, $password);
check($sysname_of($X) === 'L-name', 'a stored LLDP value and a new CDP value -> unchanged');
$fp = fingerprint($pdo);
run_ingest($dsn, $user, $password);
check(fingerprint($pdo) === $fp, 'a rerun changes nothing');

// ============================================================================================================
echo "\n=== 3: same-port join (model §6.7.1, §6.5) ===\n";
$obs_outcomes = static fn (): array => $pdo->query('SELECT outcome FROM topo_observations ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
$phys = static fn (): int => (int) scalar($pdo, "SELECT COUNT(*) FROM topo_edges WHERE type IN ('physical_link','device_link')");
$non_reporter_devices = static fn (): int => (int) scalar($pdo, "SELECT COUNT(*) FROM topo_nodes WHERE type='device' AND represented_by_node_id IS NULL AND JSON_UNQUOTE(JSON_EXTRACT(attrs,'\$.chassis_id')) <> 'a0:00:00:00:00:01'");
$lldp_local = nrow(1, 'lldp', ['rem_chassis' => 'PC-7', 'rem_chassis_type' => 'local', 'rem_sysname' => '']);
$cdp_name = nrow(1, 'cdp', ['rem_sysname' => 'PC-7']);

foreach (['LLDP item first' => [1002, 1003], 'CDP item first' => [1003, 1002]] as $label => [$lldp_item, $cdp_item]) {
	reporter($pdo, [], []);
	snapshot($pdo, $lldp_item, 101, 2, CLOCK + 10, [$lldp_local]);
	snapshot($pdo, $cdp_item, 101, 2, CLOCK + 10, [$cdp_name]);
	run_ingest($dsn, $user, $password);
	$r = run_ingest($dsn, $user, $password);
	check($non_reporter_devices() === 1 && $phys() === 1 && $obs_outcomes() === ['applied', 'applied'] && $r['summary']['devices_created'] === 0,
		"{$label}: LLDP chassis-local PC-7 + CDP device id PC-7 on one port -> one Device, one link, both observations applied");
}
reporter($pdo, [$lldp_local], [nrow(2, 'cdp', ['rem_sysname' => 'PC-7'])]);
run_ingest($dsn, $user, $password);
check($non_reporter_devices() === 2 && $phys() === 2, 'the same on two different ports -> no join: two Devices, two links');

// two claims with a chassis on the port: the same-port rule needs exactly one, so the CDP claim gets a Device of its own (scope_port = the port, 2.47) and the
// three claims of the port are ambiguous (no link); a rerun makes nothing new
reporter($pdo, [$lldp_local, nrow(1, 'lldp', ['rem_chassis' => 'c0:00:00:00:00:07', 'rem_chassis_type' => 'macAddress', 'rem_sysname' => 'PC-7'])], [$cdp_name]);
$r = run_ingest($dsn, $user, $password);
$cdp_outcome = scalar($pdo, "SELECT outcome FROM topo_observations WHERE remote_key = 'sysname:PC-7'");
check($cdp_outcome === 'ambiguous' && $non_reporter_devices() === 3, 'two claims with a chassis on the port (chassis id PC-7, MAC with sysname PC-7) -> the CDP claim has a Device of its own, all three claims are ambiguous');
check((int) scalar($pdo, "SELECT COUNT(*) FROM topo_nodes WHERE type='device' AND JSON_EXTRACT(attrs,'\$.scope_port') IS NOT NULL") === 1, 'only the Device without a chassis has a scope_port');
$r = run_ingest($dsn, $user, $password);
check($non_reporter_devices() === 3 && $r['summary']['devices_created'] === 0, 'and a rerun creates nothing');

// the MikroTik case of R1 step 0 (SW18 port 6): two LLDP chassis with one IP, and the CDP row with that IP
$mk = ['rem_mgmt_ip' => '192.168.88.1', 'rem_sysname' => 'MikroTik', 'rem_port' => 'bridge/ether1'];
reporter($pdo, [nrow(1, 'lldp', $mk + ['rem_chassis' => 'MikroTik', 'rem_chassis_type' => 'local']),
	nrow(1, 'lldp', $mk + ['rem_chassis' => '64:d1:54:69:9f:cf', 'rem_chassis_type' => 'macAddress'])], [nrow(1, 'cdp', $mk)]);
run_ingest($dsn, $user, $password);
$r = run_ingest($dsn, $user, $password);
$o = $pdo->query('SELECT remote_key, outcome FROM topo_observations ORDER BY remote_key')->fetchAll(PDO::FETCH_KEY_PAIR);
check(count($o) === 3 && array_unique(array_values($o)) === ['ambiguous'] && $r['summary']['devices_created'] === 0, 'MikroTik: two LLDP chassis, one IP -> all three observations stay ambiguous, a rerun creates nothing '.json_encode($o));

// the order inside one port: the sysname-only row after the rows with a chassis or IP key (explicit sort rank)
reporter($pdo, [], []);
snapshot($pdo, 1000, 101, 2, CLOCK + 10, [$cdp_name]);           // the lowest item id, but a sysname-only row
snapshot($pdo, 1004, 101, 2, CLOCK + 10, [$lldp_local]);
run_ingest($dsn, $user, $password);
check($non_reporter_devices() === 1 && $phys() === 1, 'the sysname-only row of the lowest rule is resolved after the chassis row of the highest rule: one Device');

// ============================================================================================================
echo "\n=== 4: rows with the same claim key (model §7.1); a port with and without an active link (model §8.5) ===\n";
$c1 = nrow(1, 'cdp', ['rem_mgmt_ip' => '10.4.4.4', 'rem_sysname' => 'first-id']);
$c2 = nrow(1, 'cdp', ['rem_mgmt_ip' => '10.4.4.4', 'rem_sysname' => 'second-id']);
foreach (['first-id' => [$c1, $c2], 'second-id' => [$c2, $c1]] as $kept => $rows) {
	reporter($pdo, [], $rows);
	$r = run_ingest($dsn, $user, $password);
	$n = (int) scalar($pdo, "SELECT COUNT(*) FROM topo_observations WHERE itemid = 1003");
	$name = json_decode((string) scalar($pdo, 'SELECT remote_attrs FROM topo_observations WHERE itemid = 1003'), true)['rem_sysname'] ?? null;
	check($n === 1 && $name === $kept, "two CDP rows, one claim key: one observation, from the first row of the snapshot ({$kept})");
	check(($r['summary']['rows_duplicate_claim']['count'] ?? -1) === 1 && ($r['summary']['rows_duplicate_claim']['by_rule'][1003] ?? -1) === 1,
		'rows_duplicate_claim counts it per rule: '.json_encode($r['summary']['rows_duplicate_claim'] ?? null));
	check($non_reporter_devices() === 1, 'one Device for the neighbor');
}
reporter($pdo, [nrow(1, 'lldp', ['rem_chassis' => $X, 'rem_chassis_type' => 'macAddress'])], [nrow(2, 'cdp', ['rem_sysname' => 'Z'])]);
$r = run_ingest($dsn, $user, $password);
check(($r['summary']['rows_duplicate_claim']['count'] ?? -1) === 0, 'no duplicate claim when the claim keys differ');

echo "  -- §8.5 without an active link: the claims of two rules on one port are one set\n";
reporter($pdo, [nrow(1, 'lldp', ['rem_chassis' => $X, 'rem_chassis_type' => 'macAddress', 'rem_sysname' => 'X'])], [nrow(1, 'cdp', ['rem_mgmt_ip' => '10.7.7.7', 'rem_sysname' => 'Y'])]);
run_ingest($dsn, $user, $password);
run_ingest($dsn, $user, $password);
check(array_unique($obs_outcomes()) === ['ambiguous'] && $phys() === 0 && scalar($pdo, 'SELECT COUNT(*) FROM topo_observations WHERE edge_id IS NOT NULL') == 0,
	'LLDP shows X, CDP shows Y, no active link -> no link, both ambiguous, no edge_id');

echo "  -- §8.5 with an active link: a claim of another rule is a §8.4 candidate\n";
reporter($pdo, [nrow(1, 'lldp', ['rem_chassis' => $X, 'rem_chassis_type' => 'macAddress', 'rem_sysname' => 'X'])], []);
run_ingest($dsn, $user, $password);
$link = (int) scalar($pdo, "SELECT id FROM topo_edges WHERE type IN ('physical_link','device_link')");
snapshot($pdo, 1003, 101, 2, CLOCK + 20, [nrow(1, 'cdp', ['rem_mgmt_ip' => '10.7.7.7', 'rem_sysname' => 'Y'])]);
run_ingest($dsn, $user, $password);
$o = $pdo->query('SELECT itemid, outcome, edge_id FROM topo_observations ORDER BY itemid')->fetchAll(PDO::FETCH_ASSOC);
check(count($o) === 2 && $o[0]['outcome'] === 'applied' && (int) $o[0]['edge_id'] === $link && $o[1]['outcome'] === 'conflict' && (int) $o[1]['edge_id'] === $link && $phys() === 1,
	'the link has support: the LLDP claim stays applied, the CDP claim is `conflict` with edge_id = the link (not ambiguous)');

// ============================================================================================================
echo "\n=== 5: a Device without a chassis is found by its port, its name and its addresses (model §6.7.1, §6.7.0 upgrade, 2.47) ===\n";
reporter($pdo, [nrow(1, 'lldp', ['rem_chassis' => $X, 'rem_chassis_type' => 'macAddress', 'rem_sysname' => 'X'])], []);
run_ingest($dsn, $user, $password);
snapshot($pdo, 1003, 101, 2, CLOCK + 20, [nrow(1, 'cdp', ['rem_sysname' => 'Ysys'])]);
run_ingest($dsn, $user, $password);
$rdev = (int) scalar($pdo, "SELECT id FROM topo_nodes WHERE type='device' AND JSON_UNQUOTE(JSON_EXTRACT(attrs,'\$.chassis_id')) = 'a0:00:00:00:00:01'");
$port1 = port_id($pdo, $rdev, 1);
$ydev = (int) scalar($pdo, "SELECT id FROM topo_nodes WHERE type='device' AND JSON_UNQUOTE(JSON_EXTRACT(attrs,'\$.sysname')) = 'Ysys'");
check(attrs($pdo, $ydev)['scope_port'] === $port1 && (int) scalar($pdo, 'SELECT COUNT(*) FROM topo_identity_keys WHERE device_id = ?', [$ydev]) === 0,
	"a Device made for a claim without a chassis has scope_port = the reporter's port ({$port1}) and no key row");
$before = counts($pdo);
$pdo->exec('DELETE FROM topo_observations');
$r = run_ingest($dsn, $user, $password);
check($r['summary']['devices_created'] === 0 && counts($pdo) === $before, 'the observations of the port are deleted: the next run finds the Device by its port and name and creates nothing');
check((int) scalar($pdo, "SELECT COUNT(*) FROM topo_observations WHERE device_id = ?", [$ydev]) === 1, 'and the observation points at the same Device again');
echo "  -- the name or an address stays: the same Device; both change at once: a new Device\n";
snapshot($pdo, 1003, 101, 2, CLOCK + 30, [nrow(1, 'cdp', ['rem_sysname' => 'Ysys', 'rem_mgmt_ip' => '10.1.1.1'])]);
run_ingest($dsn, $user, $password);
snapshot($pdo, 1003, 101, 2, CLOCK + 40, [nrow(1, 'cdp', ['rem_sysname' => 'Ysys.lab', 'rem_mgmt_ip' => '10.1.1.2'])]);
$r = run_ingest($dsn, $user, $password);
check($r['summary']['devices_created'] === 0 && (int) scalar($pdo, "SELECT COUNT(*) FROM topo_observations WHERE device_id = ?", [$ydev]) === 1,
	'the name without its domain (Ysys.lab = Ysys) and a new address -> the same Device');
snapshot($pdo, 1003, 101, 2, CLOCK + 50, [nrow(1, 'cdp', ['rem_sysname' => 'Zsys', 'rem_mgmt_ip' => '10.1.1.3'])]);
$r = run_ingest($dsn, $user, $password);
check($r['summary']['devices_created'] === 1, 'another name and an address nobody had -> a new Device; the old one stays ("not seen")');
check(exists_device($pdo, $ydev), 'the Device of the old claim is not deleted by ingest');
$fp = fingerprint($pdo);
run_ingest($dsn, $user, $password);
check(fingerprint($pdo) === $fp, 'a rerun changes nothing');

echo "  -- upgrade 2.46 -> 2.47: the old key rows go, a chassis-less Device gets its scope_port\n";
reporter($pdo, [], []);
$put = static function (string $sysname, ?string $ip, array $keys) use ($pdo): int {
	$pdo->prepare("INSERT INTO topo_nodes (type, attrs, created_at, updated_at) VALUES ('device', ?, 1, 1)")
		->execute([json_encode(['mac' => null, 'chassis_id' => null, 'mgmt_ip' => $ip, 'sysname' => $sysname, 'vendor' => 'unknown', 'last_seen' => 1])]);
	$id = (int) $pdo->lastInsertId();
	foreach ($keys as $key => $kind) {
		$pdo->prepare("INSERT INTO topo_identity_keys (device_id, `key`, kind, first_seen, replaced) VALUES (?, ?, ?, 1, 0)")->execute([$id, $key, $kind]);
	}
	return $id;
};
run_ingest($dsn, $user, $password);
$rdev = (int) scalar($pdo, "SELECT id FROM topo_nodes WHERE type='device' AND JSON_UNQUOTE(JSON_EXTRACT(attrs,'\$.chassis_id')) = 'a0:00:00:00:00:01'");
$port3 = port_id($pdo, $rdev, 3);
$port4 = port_id($pdo, $rdev, 4);
$d_sys = $put('OldSys', '10.3.3.3', ['sysname:'.$port3.':OldSys' => 'sysname', 'ip:server:10.3.3.3' => 'ip']);          // scope_port from the sysname key
$d_obs = $put('OldObs', '10.4.4.4', ['ip:server:10.4.4.4' => 'ip']);                                                     // scope_port from the latest observation
$pdo->prepare("INSERT INTO topo_observations (itemid, local_port_id, remote_key, remote_attrs, outcome, device_id, first_seen, last_seen)"
	." VALUES (1003, ?, 'ip:server:10.4.4.4', '{}', 'applied', ?, 1, 1)")->execute([$port4, $d_obs]);
$d_none = $put('OldNone', null, ['ip:server:10.5.5.5' => 'ip']);                                                         // neither: no scope_port
$deleted_before = (int) scalar($pdo, "SELECT COUNT(*) FROM topo_identity_keys WHERE kind IN ('ip','sysname')");
run_ingest($dsn, $user, $password);
check((int) scalar($pdo, "SELECT COUNT(*) FROM topo_identity_keys WHERE kind IN ('ip','sysname')") === 0 && $deleted_before === 4, 'the key rows of kind ip and sysname are deleted (4 rows)');
check((attrs($pdo, $d_sys)['scope_port'] ?? null) === $port3 && (attrs($pdo, $d_obs)['scope_port'] ?? null) === $port4 && !isset(attrs($pdo, $d_none)['scope_port']),
	'scope_port: from the sysname key; from the local port of the latest observation; none when there is neither');
check(exists_device($pdo, $d_sys) && exists_device($pdo, $d_obs) && exists_device($pdo, $d_none), 'the Devices stay');
$fp = fingerprint($pdo);
run_ingest($dsn, $user, $password);
check(fingerprint($pdo) === $fp, 'a rerun changes nothing');

// ============================================================================================================
echo "\n=== 6: the observation keeps the raw rem_man_addrs (task 049, model §12.5 \"Addresses\"); the management address is still the first IPv4 (normalization §4.4) ===\n";
$entries = 'lldp:2.16.32.1.13.184.0.0.0.0.0.0.0.0.0.0.0.1 lldp:1.4.10.0.0.9 lldp:1.4.10.0.0.5 cdp:1:0a:00:00:63';
reporter($pdo, [nrow(1, 'lldp', ['rem_chassis' => $X, 'rem_chassis_type' => 'macAddress', 'rem_sysname' => 'X', 'rem_man_addrs' => $entries])], []);
run_ingest($dsn, $user, $password);
$obs = json_decode((string) scalar($pdo, 'SELECT remote_attrs FROM topo_observations WHERE itemid = 1002'), true);
check(($obs['rem_man_addrs'] ?? null) === $entries, 'the observation has every entry as received');
check(($obs['rem_mgmt_ip'] ?? null) === '10.0.0.9' && attrs($pdo, device_id($pdo, $X))['mgmt_ip'] === '10.0.0.9', 'rem_mgmt_ip and the Device mgmt_ip: the first IPv4, as before');
$fp = fingerprint($pdo);
run_ingest($dsn, $user, $password);
check(fingerprint($pdo) === $fp, 'a rerun changes nothing');

echo "\n=== 7: the display rules of 2.44 (loopback, link-local) do not change the management address of ingest (task 051) ===\n";
foreach ([
	'loopback first' => ['lldp:1.4.127.0.0.1 lldp:1.4.10.0.0.7', '127.0.0.1'],
	'link-local IPv4 before a real one' => ['lldp:1.4.169.254.1.1 lldp:1.4.10.0.0.7', '169.254.1.1'],
	'IPv6 link-local only' => ['lldp:2.254.128.0.0.0.0.0.0.0.0.0.0.0.0.0.1', 'fe80::1'],
	'IPv6 loopback only' => ['lldp:2.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.1', '::1'],
] as $label => [$man, $want]) {
	$pdo->exec('DELETE FROM topo_observations');
	reporter($pdo, [nrow(1, 'lldp', ['rem_chassis' => $X, 'rem_chassis_type' => 'macAddress', 'rem_sysname' => 'X', 'rem_man_addrs' => $man])], []);
	run_ingest($dsn, $user, $password);
	$obs = json_decode((string) scalar($pdo, 'SELECT remote_attrs FROM topo_observations WHERE itemid = 1002'), true);
	check(($obs['rem_mgmt_ip'] ?? null) === $want, "{$label}: rem_mgmt_ip is {$want}, as before");
}

echo "\nALL OK\n";
