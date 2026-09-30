#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * test_snapshot_ingest.php — acceptance tests for ingest.php's LLD snapshot path
 * (topology-lld-part2-spec.md §5-§8, §10 "Ingest" and "Observations").
 *
 * Writes rows straight into topo_lld_snapshot (what the server's LLD worker would store) and runs the REAL
 * ingest.php as a subprocess after every change, so the actual CLI path is exercised end to end — no test-only
 * hook, no reimplementation of the algorithm here.
 *
 * Usage: test_snapshot_ingest.php <PDO DSN> <database user> [password]
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
$pdo->exec("CREATE TABLE hosts (hostid BIGINT UNSIGNED PRIMARY KEY, host VARCHAR(128) NOT NULL, name VARCHAR(128) NOT NULL DEFAULT '')");
$pdo->exec('CREATE TABLE proxy (proxyid BIGINT UNSIGNED PRIMARY KEY)');
$pdo->exec('CREATE TABLE items (itemid BIGINT UNSIGNED PRIMARY KEY, hostid BIGINT UNSIGNED NOT NULL, status INT NOT NULL DEFAULT 0, topology_role INT NOT NULL DEFAULT 0)');
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
$A = 'a0:00:00:00:00:01';
$B = 'b0:00:00:00:00:02';
$C = 'c0:00:00:00:00:03';
$ports_a = [
	['if_index' => 1, 'name' => 'Gi0/1', 'if_type' => '6', 'mac' => 'aa:aa:aa:00:00:01', 'admin_status' => '1', 'oper_status' => '1', 'loc_chassis' => $A],
	['if_index' => 2, 'name' => 'Gi0/2', 'if_type' => '6', 'admin_status' => '1', 'oper_status' => '2', 'loc_chassis' => $A],
	['if_index' => 3, 'name' => 'Gi0/3', 'if_type' => '6', 'admin_status' => '1', 'oper_status' => '1', 'loc_chassis' => $A],
	['if_index' => 50, 'name' => 'Po1', 'if_type' => '161', 'admin_status' => '1', 'oper_status' => '1', 'loc_chassis' => $A],
];
$nbr_a_to_b = ['if_index' => 1, 'rem_chassis' => $B, 'rem_chassis_type' => 'macAddress', 'rem_sysname' => 'SwB',
	'rem_port' => 'Gi0/24', 'rem_port_type' => 'interfaceName', 'source' => 'lldp'];

// ============================================================================================================
echo "\n=== 1: order independence — NEIGHBORS first, PORTS later ===\n";
add_host($pdo, 101, 'SwA', 'Switch A', '10.0.0.1');
add_host($pdo, 102, 'SwB', 'Switch B', '10.0.0.2');

snapshot($pdo, 1001, 101, 2, CLOCK, [$nbr_a_to_b + ['loc_chassis' => $A]]);
$r = run_ingest($dsn, $user, $password);
check($r['code'] === 0, 'ingest exits 0 with only a NEIGHBORS snapshot: '.trim($r['err']));
$dev_a = device_id($pdo, $A);
check($dev_a !== null, 'reporter Device created from loc_chassis');
$port_a1 = port_id($pdo, $dev_a, 1);
check($port_a1 !== null && attrs($pdo, $port_a1)['pseudo'] === false,
	'reporter-side Port exists and is confirmed although no PORTS rule ran');
check((int) scalar($pdo, "SELECT COUNT(*) FROM topo_nodes WHERE device_id=? AND type='port' AND JSON_EXTRACT(attrs,'\$.pseudo')=true", [$dev_a]) === 0,
	'no unconfirmed port on the reporter\'s own side, ever');
check(scalar($pdo, 'SELECT represented_by_matched_by FROM topo_nodes WHERE id=?', [$dev_a]) === 'reporter_self',
	'reporter Device is represented_by its own host (reporter_self)');

snapshot($pdo, 1002, 101, 1, CLOCK + 10, $ports_a);
$r = run_ingest($dsn, $user, $password);
check($r['code'] === 0, 'second ingest with PORTS added');
check(port_id($pdo, $dev_a, 1) === $port_a1, 'PORTS updates the SAME Port id the NEIGHBORS pass created');
$attrs = attrs($pdo, $port_a1);
check($attrs['name'] === 'Gi0/1' && $attrs['mac'] === 'aa:aa:aa:00:00:01' && $attrs['oper_status'] === 'up'
	&& $attrs['pseudo'] === false, 'PORTS row attributes merged onto the port');
check(attrs($pdo, (int) port_id($pdo, $dev_a, 2))['oper_status'] === 'down', 'IF-MIB oper status 2 maps to down');
check(attrs($pdo, (int) port_id($pdo, $dev_a, 50))['if_type'] === 'lag', 'ifType 161 maps to lag');
check((int) scalar($pdo, "SELECT COUNT(*) FROM topo_nodes WHERE type='device'") === 2, 'exactly Device A and its neighbor B');

// ============================================================================================================
echo "\n=== 2: convergence — reruns over unchanged snapshots change nothing ===\n";
$before = fingerprint($pdo);
for ($i = 0; $i < 5; $i++) {
	run_ingest($dsn, $user, $password);
}
check(fingerprint($pdo) === $before, 'five more runs: same ids, same last_seen_*, same observations, untouched updated_at');
$edge = edges($pdo)[0];
$edge_attrs = json_decode($edge['attrs'], true);
check(max($edge_attrs['last_seen_src'] ?? 0, $edge_attrs['last_seen_dst'] ?? 0) === CLOCK && $edge_attrs['last_seen'] === CLOCK,
	'link last_seen is the snapshot clock, not ingest wall-clock time');
check(($edge_attrs['discovered_via'] ?? '') === 'lldp', 'link discovered_via comes from the row source');

// ============================================================================================================
echo "\n=== 3: neighbor becomes a reporter later — same Device, unconfirmed port merged, one edge ===\n";
$dev_b = device_id($pdo, $B);
$pseudo_b = (int) scalar($pdo, "SELECT id FROM topo_nodes WHERE device_id=? AND type='port'", [$dev_b]);
check(attrs($pdo, $pseudo_b)['pseudo'] === true, 'before: the neighbor\'s port is unconfirmed (pseudo)');
snapshot($pdo, 2001, 102, 1, CLOCK + 20, [
	['if_index' => 24, 'name' => 'Gi0/24', 'if_type' => '6', 'admin_status' => '1', 'oper_status' => '1', 'loc_chassis' => $B],
]);
snapshot($pdo, 2002, 102, 2, CLOCK + 20, [
	['if_index' => 24, 'rem_chassis' => $A, 'rem_chassis_type' => 'macAddress', 'rem_port' => 'Gi0/1',
		'rem_port_type' => 'interfaceName', 'source' => 'lldp', 'loc_chassis' => $B],
]);
$r = run_ingest($dsn, $user, $password);
check($r['code'] === 0, 'ingest with B as a reporter: '.trim($r['err']));
check(device_id($pdo, $B) === $dev_b, 'the neighbor-created Device is reused, not duplicated');
check((int) scalar($pdo, "SELECT COUNT(*) FROM topo_nodes WHERE type='device'") === 2, 'still exactly two Devices');
check(count(edges($pdo)) === 1, 'still exactly one physical_link (both sides converge on it)');
check((int) scalar($pdo, "SELECT COUNT(*) FROM topo_nodes WHERE device_id=? AND type='port' AND JSON_EXTRACT(attrs,'\$.pseudo')=true", [$dev_b]) === 0,
	'the unconfirmed port was merged into the real one');
$edge_attrs = json_decode(edges($pdo)[0]['attrs'], true);
check(isset($edge_attrs['last_seen_src'], $edge_attrs['last_seen_dst'])
	&& max($edge_attrs['last_seen_src'], $edge_attrs['last_seen_dst']) === CLOCK + 20
	&& min($edge_attrs['last_seen_src'], $edge_attrs['last_seen_dst']) === CLOCK,
	'each side keeps its own reporter\'s snapshot clock');

// ============================================================================================================
echo "\n=== 4: LLDP and CDP rules on one reporter confirm the same link ===\n";
snapshot($pdo, 1003, 101, 2, CLOCK + 100, [$nbr_a_to_b + ['loc_chassis' => $A]] , 2); // the LLDP rule, newer clock
snapshot($pdo, 1004, 101, 2, CLOCK + 50, [['if_index' => 1, 'rem_chassis' => $B, 'rem_port' => 'Gi0/24',
	'rem_port_type' => 'interfaceName', 'source' => 'cdp', 'loc_chassis' => $A]]);
run_ingest($dsn, $user, $password);
check(count(edges($pdo)) === 1, 'LLDP + CDP: still one edge');
$edge_attrs = json_decode(edges($pdo)[0]['attrs'], true);
$a_side = $dev_a < $dev_b ? 'last_seen_src' : 'last_seen_dst';
check(max($edge_attrs['last_seen_src'], $edge_attrs['last_seen_dst']) >= CLOCK + 100, 'the side\'s last_seen is the newer of the two clocks');
check(in_array($edge_attrs['discovered_via'], ['lldp', 'cdp'], true), 'discovered_via is a discovered source, never manual');
$snap_before = fingerprint($pdo);
run_ingest($dsn, $user, $password);
check(fingerprint($pdo) === $snap_before, 'two rules on one link converge (no flapping between them)');
$pdo->exec('DELETE FROM topo_lld_snapshot WHERE itemid IN (1003, 1004)');
$pdo->exec('DELETE FROM items WHERE itemid IN (1003, 1004)');
$pdo->exec('DELETE FROM topo_observations WHERE itemid IN (1003, 1004)');

// ============================================================================================================
echo "\n=== 5: manual link shadows a discovered neighbor (observation evidence) ===\n";
$port_a3 = port_id($pdo, $dev_a, 3);
$pdo->prepare("INSERT INTO topo_nodes (type, attrs, created_at, updated_at) VALUES ('device', ?, 1, 1)")
	->execute([json_encode(['chassis_id' => 'ee:00:00:00:00:09', 'sysname' => 'Manual peer', 'mac' => null, 'mgmt_ip' => null, 'vendor' => 'unknown', 'last_seen' => 1])]);
$manual_dev = (int) $pdo->lastInsertId();
$pdo->prepare("INSERT INTO topo_nodes (type, device_id, attrs, created_at, updated_at) VALUES ('port', ?, ?, 1, 1)")
	->execute([$manual_dev, json_encode(['if_index' => 1, 'name' => 'eth0', 'if_type' => 'physical', 'pseudo' => false, 'learned_macs' => [], 'zabbix_itemids' => []])]);
$manual_port = (int) $pdo->lastInsertId();
$lo = min($port_a3, $manual_port);
$hi = max($port_a3, $manual_port);
$pdo->prepare("INSERT INTO topo_edges (type, src_id, dst_id, attrs, created_at) VALUES ('physical_link', ?, ?, ?, 1)")
	->execute([$lo, $hi, json_encode(['discovered_via' => 'manual', 'last_seen' => 1])]);
$manual_edge = (int) $pdo->lastInsertId();
snapshot($pdo, 1001, 101, 2, CLOCK + 200, [$nbr_a_to_b + ['loc_chassis' => $A],
	['if_index' => 3, 'rem_chassis' => $C, 'rem_sysname' => 'Printer1', 'rem_port' => 'eth0', 'rem_port_type' => 'interfaceName',
		'source' => 'lldp', 'loc_chassis' => $A]]);
run_ingest($dsn, $user, $password);
$obs = $pdo->query("SELECT outcome, edge_id, device_id FROM topo_observations WHERE remote_key LIKE '%c0:00:00:00:00:03'")->fetch(PDO::FETCH_ASSOC);
check($obs && $obs['outcome'] === 'shadowed', 'the neighbor behind the manual link is recorded as shadowed');
check((int) $obs['edge_id'] === $manual_edge, 'shadowed observation points at the manual link');
check($obs['device_id'] !== null && (int) $obs['device_id'] === device_id($pdo, $C), 'and at the neighbor Device that was resolved anyway');
check(device_id($pdo, $C) !== null, 'the neighbor Device is still created');
check((int) scalar($pdo, "SELECT COUNT(*) FROM topo_edges WHERE type='physical_link' AND (src_id=? OR dst_id=?)", [$port_a3, $port_a3]) === 1,
	'no discovered link was added next to the manual one');
check(json_decode((string) scalar($pdo, 'SELECT attrs FROM topo_edges WHERE id=?', [$manual_edge]), true)['discovered_via'] === 'manual',
	'the manual link is untouched');

// ============================================================================================================
echo "\n=== 6: device_only, cable move (conflict), disappearing neighbor ===\n";
snapshot($pdo, 1001, 101, 2, CLOCK + 300, [$nbr_a_to_b + ['loc_chassis' => $A],
	['if_index' => 2, 'rem_chassis' => $C, 'rem_sysname' => 'NoPort', 'source' => 'lldp', 'loc_chassis' => $A]]);
run_ingest($dsn, $user, $password);
$obs = $pdo->query("SELECT outcome, edge_id FROM topo_observations WHERE remote_key LIKE '%c0:00:00:00:00:03'")->fetch(PDO::FETCH_ASSOC);
check($obs['outcome'] === 'device_only' && $obs['edge_id'] === null, 'unparseable remote port: device_only, no edge');
check((int) scalar($pdo, "SELECT COUNT(*) FROM topo_observations WHERE itemid=1001") === 2, 'observations mirror the latest snapshot (2 rows)');
$link_count = count(edges($pdo));

// cable moved: port 1 now sees C instead of B
$b_edge = (int) scalar($pdo, "SELECT id FROM topo_edges WHERE (src_id=? OR dst_id=?) AND JSON_UNQUOTE(JSON_EXTRACT(attrs,'\$.discovered_via')) <> 'manual' ORDER BY id LIMIT 1", [$port_a1, $port_a1]);
snapshot($pdo, 1001, 101, 2, CLOCK + 400, [['if_index' => 1, 'rem_chassis' => $C, 'rem_sysname' => 'Printer1', 'rem_port' => 'eth0',
	'rem_port_type' => 'interfaceName', 'source' => 'lldp', 'loc_chassis' => $A]]);
run_ingest($dsn, $user, $password);
$obs = $pdo->query("SELECT outcome, edge_id FROM topo_observations WHERE itemid=1001")->fetchAll(PDO::FETCH_ASSOC);
// topology-link-replacement-spec.md: B's latest snapshot (clock CLOCK+20) still shows the old link but is older
// than R's contradiction (CLOCK+400), so the old link is replaced instead of lingering next to the new one.
check(count($obs) === 1 && $obs[0]['outcome'] === 'applied' && (int) $obs[0]['edge_id'] !== $b_edge,
	'cable move: the new neighbor is applied on a new link (the old one had no support as recent)');
check(count(edges($pdo)) === $link_count + 1, 'the new link is added and the old one is kept, not deleted');
$old = json_decode((string) scalar($pdo, 'SELECT attrs FROM topo_edges WHERE id=?', [$b_edge]), true);
check(($old['superseded_at'] ?? null) === CLOCK + 400, 'the old link is superseded at the contradiction clock');
check(max($old['last_seen_src'] ?? 0, $old['last_seen_dst'] ?? 0) < CLOCK + 400, 'the old link\'s last_seen stays frozen (no longer confirmed)');
check((int) scalar($pdo, "SELECT COUNT(*) FROM topo_edges WHERE type='physical_link' AND (src_id=? OR dst_id=?) AND JSON_EXTRACT(attrs,'\$.superseded_at') IS NULL", [$port_a1, $port_a1]) === 1,
	'port 1 has exactly one active link');
check((int) scalar($pdo, "SELECT COUNT(*) FROM topo_observations WHERE itemid=1001 AND remote_key LIKE '%b0:00:00:00:00:02'") === 0,
	'the neighbor that disappeared from the snapshot has no observation left');

// ============================================================================================================
echo "\n=== 7: LAG ===\n";
add_host($pdo, 103, 'SwL', 'Switch L', '10.0.0.3');
$L = 'd0:00:00:00:00:04';
$ports_l = [];
foreach ([1, 2, 3, 10] as $i) {
	$ports_l[] = ['if_index' => $i, 'name' => "Gi0/$i", 'if_type' => $i === 10 ? '161' : '6', 'admin_status' => '1', 'oper_status' => '1', 'loc_chassis' => $L];
}
snapshot($pdo, 3001, 103, 1, CLOCK, $ports_l);
run_ingest($dsn, $user, $password);
$dev_l = device_id($pdo, $L);
$lag_port = (int) port_id($pdo, $dev_l, 10);
$m1 = (int) port_id($pdo, $dev_l, 1);
$pdo->prepare('UPDATE topo_nodes SET lag_id = ? WHERE id = ?')->execute([$lag_port, $m1]); // pre-existing value
run_ingest($dsn, $user, $password);
check(scalar($pdo, 'SELECT lag_id FROM topo_nodes WHERE id=?', [$m1]) == $lag_port, 'a reporter without a LAG rule: lag_id untouched');

snapshot($pdo, 3002, 103, 4, CLOCK + 10, [['if_index' => 1, 'lag_if_index' => 10], ['if_index' => 2, 'lag_if_index' => 10], ['if_index' => 3, 'lag_if_index' => 3]]);
run_ingest($dsn, $user, $password);
$m2 = (int) port_id($pdo, $dev_l, 2);
check(scalar($pdo, 'SELECT lag_id FROM topo_nodes WHERE id=?', [$m2]) == $lag_port, 'LAG rule sets members\' lag_id to the aggregate');
check(scalar($pdo, 'SELECT lag_id FROM topo_nodes WHERE id=?', [(int) port_id($pdo, $dev_l, 3)]) === null, 'a port attached to itself is not aggregated');
check(attrs($pdo, $lag_port)['if_type'] === 'lag', 'the lag_id target is typed lag (invariant)');
snapshot($pdo, 3002, 103, 4, CLOCK + 20, [['if_index' => 1, 'lag_if_index' => 10]]);
run_ingest($dsn, $user, $password);
check(scalar($pdo, 'SELECT lag_id FROM topo_nodes WHERE id=?', [$m2]) === null, 'a member missing from the snapshot leaves the LAG');
check(scalar($pdo, 'SELECT lag_id FROM topo_nodes WHERE id=?', [$m1]) == $lag_port, 'a member still in the snapshot stays');
$lag_before = fingerprint($pdo);
run_ingest($dsn, $user, $password);
check(fingerprint($pdo) === $lag_before, 'PORTS + LAG on one reporter converge (a lag-typed target stays lag across runs)');

// ============================================================================================================
echo "\n=== 8: LEARNED_MACS ===\n";
snapshot($pdo, 3003, 103, 3, CLOCK + 30, [
	['if_index' => 1, 'mac' => 'AA:AA:AA:AA:AA:01'], ['if_index' => 1, 'mac' => 'aa:aa:aa:aa:aa:02'],
	['if_index' => 2, 'port_mac_count' => 47],
]);
$devices_before = (int) scalar($pdo, "SELECT COUNT(*) FROM topo_nodes WHERE type='device'");
run_ingest($dsn, $user, $password);
check(attrs($pdo, $m1)['learned_macs'] === ['aa:aa:aa:aa:aa:01', 'aa:aa:aa:aa:aa:02'], 'learned MACs replace the list (lowercased, sorted)');
$trunk = attrs($pdo, $m2);
check($trunk['learned_macs'] === [] && $trunk['learned_mac_count'] === 47, 'a count-only row: empty list plus learned_mac_count');
check((int) scalar($pdo, "SELECT COUNT(*) FROM topo_nodes WHERE type='device'") === $devices_before, 'no Device is ever created from a MAC');
snapshot($pdo, 3003, 103, 3, CLOCK + 40, [['if_index' => 1, 'mac' => 'aa:aa:aa:aa:aa:03']]);
run_ingest($dsn, $user, $password);
check(attrs($pdo, $m1)['learned_macs'] === ['aa:aa:aa:aa:aa:03'], 'replace, not append');
$trunk = attrs($pdo, $m2);
check($trunk['learned_macs'] === [] && !array_key_exists('learned_mac_count', $trunk), 'a port absent from the snapshot is reset');

// ============================================================================================================
echo "\n=== 9: reporter identity ===\n";
add_host($pdo, 104, 'SwAmb', 'Ambiguous', '10.0.0.4');
snapshot($pdo, 4001, 104, 1, CLOCK, [['if_index' => 1, 'name' => 'a', 'loc_chassis' => 'f0:00:00:00:00:01'],
	['if_index' => 2, 'name' => 'b', 'loc_chassis' => 'f0:00:00:00:00:02']]);
add_host($pdo, 105, 'SwNone', 'No identity', null);
snapshot($pdo, 5001, 105, 1, CLOCK, [['if_index' => 1, 'name' => 'a']]);
$devices_before = (int) scalar($pdo, "SELECT COUNT(*) FROM topo_nodes WHERE type='device'");
$r = run_ingest($dsn, $user, $password);
check((int) scalar($pdo, "SELECT COUNT(*) FROM topo_nodes WHERE type='device'") === $devices_before, 'neither skipped reporter created a Device');
check(($r['summary']['reporters_skipped']['identity_ambiguous'] ?? 0) === 1, 'two loc_chassis values: skipped as identity_ambiguous');
check(($r['summary']['reporters_skipped']['identity_missing'] ?? 0) === 1, 'no loc_chassis and no SNMP IP: skipped as identity_missing');
check(device_id($pdo, 'f0:00:00:00:00:01') === null && device_id($pdo, 'f0:00:00:00:00:02') === null, 'no chassis was picked');
$pdo->exec('DELETE FROM topo_lld_snapshot WHERE itemid IN (4001, 5001)');

// ============================================================================================================
echo "\n=== 10: stale roles, disabled rules, status counters ===\n";
snapshot($pdo, 6001, 101, 2, CLOCK + 500, [['if_index' => 3, 'rem_chassis' => 'ab:00:00:00:00:0b', 'rem_port' => 'x', 'loc_chassis' => $A]], 1);
$r = run_ingest($dsn, $user, $password);
check(device_id($pdo, 'ab:00:00:00:00:0b') === null, 'a snapshot whose role no longer matches the rule is ignored');
check(($r['summary']['snapshots_ignored_role_mismatch'] ?? 0) === 1, 'and counted in the status summary');
snapshot($pdo, 6001, 101, 2, CLOCK + 500, [['if_index' => 3, 'rem_chassis' => 'ab:00:00:00:00:0b', 'rem_port' => 'x', 'loc_chassis' => $A]], 2, 1);
run_ingest($dsn, $user, $password);
check(device_id($pdo, 'ab:00:00:00:00:0b') === null, 'a snapshot of a disabled rule is skipped');
check(isset($r['summary']['observations']) && is_array($r['summary']['observations']), 'the summary carries per-outcome observation counts');
check(($r['summary']['reporters_processed'] ?? 0) >= 3, 'the summary counts processed reporters');

// ============================================================================================================
echo "\n=== 11: --zabbix-host scoping and a host with no data ===\n";
$r = run_ingest($dsn, $user, $password, ['SwB']);
check($r['code'] === 0 && ($r['summary']['reporters_processed'] ?? 0) === 1, 'the host filter limits the run to one reporter');

echo "\nAll snapshot ingest tests passed.\n";
