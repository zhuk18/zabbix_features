#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * test_join_identity.php — topology-1-model.md §6.7.1 "Join of a split identity": a neighbor seen by LLDP without a management address
 * (a chassis only: Device A) and by CDP (an IP only: Device B) is two Devices until a row carries both keys; then B is merged into A.
 *
 * Same method as test_snapshot_ingest.php. Writes rows straight into topo_lld_snapshot (what the server's LLD worker would store) and runs the REAL
 * ingest.php as a subprocess after every change, so the actual CLI path is exercised end to end — no test-only
 * hook, no reimplementation of the algorithm here.
 *
 * Usage: test_join_identity.php <PDO DSN> <database user> [password]
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

function show(string $label, array $p): void {
	echo "  --- {$label}\n";
	foreach ($p as $k => $list) {
		echo "    {$k}: ".implode('; ', $list)."\n";
	}
}

function split_case(PDO $pdo, string $dsn, string $user, string $password, string $R, string $Y, string $IP): void {
	reset_all($pdo);
	add_host($pdo, 101, 'SwR', 'Switch R', '10.0.0.1');
	snapshot($pdo, 1001, 101, 1, CLOCK, [['if_index' => 1, 'name' => 'Gi0/1', 'loc_chassis' => $R]]);
	// the same neighbor on one port: LLDP without a management address (chassis only), CDP (IP only)
	snapshot($pdo, 1002, 101, 2, CLOCK + 10, [['if_index' => 1, 'rem_chassis' => $Y, 'rem_chassis_type' => 'macAddress', 'rem_sysname' => 'N',
		'rem_port' => 'Gi0/1', 'rem_port_type' => 'interfaceName', 'source' => 'lldp', 'loc_chassis' => $R]]);
	snapshot($pdo, 1003, 101, 2, CLOCK + 10, [['if_index' => 1, 'rem_mgmt_ip' => $IP, 'rem_sysname' => 'N', 'rem_port' => 'Gi0/1',
		'rem_port_type' => 'interfaceName', 'source' => 'cdp', 'loc_chassis' => $R]]);
}

// ============================================================================================================
echo "\n=== 1: a split identity, then the neighbor becomes a reporter (loc_chassis Y + SNMP IP 1) ===\n";
split_case($pdo, $dsn, $user, $password, $R, $Y, $IP);
$r1 = run_ingest($dsn, $user, $password);
$p1 = picture($pdo);
show('run 1: LLDP (chassis Y) and CDP (IP 1)', $p1);

add_host($pdo, 102, 'SwN', 'Switch N', $IP);
snapshot($pdo, 2001, 102, 1, CLOCK + 100, [['if_index' => 1, 'name' => 'Gi0/1', 'loc_chassis' => $Y]]);
$r2 = run_ingest($dsn, $user, $password);
$p2 = picture($pdo);
show('run 2: the neighbor is a reporter now', $p2);
$r3 = run_ingest($dsn, $user, $password);
$p3 = picture($pdo);
show('run 3: nothing new', $p3);
echo '  summary: '.json_encode(array_intersect_key($r3['summary'], array_flip(['devices_created', 'identity_keys_shared', 'devices_joined', 'devices_join_refused'])))."\n";

check(count($p1['devices']) === 3, 'run 1: two Devices for one neighbor (A by chassis, B by IP), plus R');
check(str_contains(implode(' ', $p1['obs']), 'ambiguous') && !str_contains(implode(' ', $p1['obs']), 'applied'), 'run 1: both observations are ambiguous (two claims on one port), no link');
check(count($p2['devices']) === 2 && !array_filter($p2['devices'], static fn ($d) => str_starts_with($d, '#5')), 'run 2 (the neighbor reports itself): one Device — A kept, B deleted');
check(count($p2['links']) === 1 && !str_contains(implode(' ', $p2['links']), 'device_link') && count(array_filter($p2['ports'], static fn ($p) => str_contains($p, 'N:') || str_contains($p, 'Switch N:'))) === 1,
	'run 2: pseudo ports merged by name (one port of the neighbor), one link');
check(substr_count(implode(' ', $p2['obs']), 'applied') === 2 && !str_contains(implode(' ', $p2['obs']), 'ambiguous'), 'run 2: the CDP observation is applied (not ambiguous), the LLDP one too');
check(($r2['summary']['devices_joined']['count'] ?? 0) === 1 && ($r2['summary']['devices_joined']['list'][0]['key'] ?? '') === 'mgmt_ip'
	&& ($r2['summary']['devices_joined']['list'][0]['value'] ?? '') === $IP, 'devices_joined = 1 with {kept, removed, key mgmt_ip, value}');
$fp = fingerprint($pdo);
for ($i = 0; $i < 5; $i++) {
	$r = run_ingest($dsn, $user, $password);
}
check(fingerprint($pdo) === $fp && ($r['summary']['devices_joined']['count'] ?? -1) === 0 && ($r['summary']['identity_keys_shared']['count'] ?? -1) === 0, '5 more runs change nothing, nothing is joined or shared again');

// ============================================================================================================
echo "\n=== 2: a neighbor row with both keys never merges (model §6.7.1: only the reporter's own identity joins) ===\n";
foreach (['full run' => [], 'partial run (--zabbix-host)' => ['SwR']] as $label => $only) {
	echo "  -- {$label}\n";
	reset_all($pdo);
	add_host($pdo, 101, 'SwR', 'Switch R', '10.0.0.1');
	snapshot($pdo, 1001, 101, 1, CLOCK, [['if_index' => 1, 'name' => 'Gi0/1', 'loc_chassis' => $R]]);
	run_ingest($dsn, $user, $password);
	$A = device($pdo, $Y, null, 'N');
	$B = device($pdo, null, $IP, 'N');
	snapshot($pdo, 1002, 101, 2, CLOCK + 50, [['if_index' => 1, 'rem_chassis' => $Y, 'rem_chassis_type' => 'macAddress', 'rem_mgmt_ip' => $IP, 'rem_sysname' => 'N',
		'rem_port' => 'Gi0/1', 'rem_port_type' => 'interfaceName', 'source' => 'lldp', 'loc_chassis' => $R]]);
	$r = run_ingest($dsn, $user, $password, $only);
	check(scalar($pdo, 'SELECT COUNT(*) FROM topo_nodes WHERE id IN (?, ?)', [$A, $B]) == 2 && ($r['summary']['devices_joined']['count'] ?? -1) === 0,
		'both Devices stay, nothing is joined');
	check(attrs($pdo, $A)['mgmt_ip'] === $IP, 'the row refreshed the IP of A (a found Device gets the attributes the row gives)');
}

// ============================================================================================================
echo "\n=== 3: the reporter's own identity: no join when both Devices have a represented_by; no join when the IP is shared ===\n";
reset_all($pdo);
add_host($pdo, 111, 'HostA', 'Host A', null);
add_host($pdo, 112, 'HostB', 'Host B', null);
add_host($pdo, 101, 'SwH', 'Switch H', $IP);                      // the reporter: loc_chassis Y + SNMP IP 1
snapshot($pdo, 1001, 101, 1, CLOCK, [['if_index' => 1, 'name' => 'Gi0/1', 'loc_chassis' => $Y]]);
$A = device($pdo, $Y, null, 'N');
$B = device($pdo, null, $IP, 'N');
foreach ([[$A, 111], [$B, 112]] as [$dev, $hostid]) {
	$pdo->prepare("INSERT INTO topo_nodes (type, host_ref, attrs, created_at, updated_at) VALUES ('host', ?, '{}', 1, 1)")->execute([$hostid]);
	$node = (int) $pdo->lastInsertId();
	$pdo->prepare("UPDATE topo_nodes SET represented_by_node_id = ?, represented_by_matched_by = 'manual', represented_by_at = 1 WHERE id = ?")->execute([$node, $dev]);
}
$targets = [(int) scalar($pdo, 'SELECT represented_by_node_id FROM topo_nodes WHERE id=?', [$A]), (int) scalar($pdo, 'SELECT represented_by_node_id FROM topo_nodes WHERE id=?', [$B])];
$r = run_ingest($dsn, $user, $password);
check(scalar($pdo, 'SELECT COUNT(*) FROM topo_nodes WHERE id IN (?, ?)', [$A, $B]) == 2 && ($r['summary']['devices_joined']['count'] ?? -1) === 0, 'both Devices stay, nothing is joined');
$refused = $r['summary']['devices_join_refused'] ?? [];
check(($refused['count'] ?? 0) === 1 && $refused['list'][0]['kept'] === $A && $refused['list'][0]['removed'] === $B
	&& $refused['list'][0]['kept_target'] === $targets[0] && $refused['list'][0]['removed_target'] === $targets[1], 'devices_join_refused names both node ids and both targets');
$fp = fingerprint($pdo);
run_ingest($dsn, $user, $password);
check(fingerprint($pdo) === $fp, 'a rerun changes nothing');

echo "  -- the IP on two Devices without a chassis\n";
reset_all($pdo);
add_host($pdo, 101, 'SwH', 'Switch H', $IP);
snapshot($pdo, 1001, 101, 1, CLOCK, [['if_index' => 1, 'name' => 'Gi0/1', 'loc_chassis' => $Y]]);
$A = device($pdo, $Y, null, 'N');
$B1 = device($pdo, null, $IP, 'old1');
$B2 = device($pdo, null, $IP, 'old2');
$r = run_ingest($dsn, $user, $password);
check(scalar($pdo, 'SELECT COUNT(*) FROM topo_nodes WHERE id IN (?, ?, ?)', [$A, $B1, $B2]) == 3 && ($r['summary']['devices_joined']['count'] ?? -1) === 0, 'IP 1 on two other Devices: no join, all three stay');

// ============================================================================================================
echo "\n=== 4: ports of the removed Device: a pseudo port by normalized name only (§6.7.1) ===\n";
reset_all($pdo);
add_host($pdo, 101, 'SwH', 'Switch H', $IP);
snapshot($pdo, 1001, 101, 1, CLOCK, [['if_index' => 1, 'name' => 'Gi0/1', 'loc_chassis' => $Y]]);
$A = device($pdo, $Y, null, 'N');
$B = device($pdo, null, $IP, 'N');
$add = static function (int $device, int $if, string $name) use ($pdo): int {
	$pdo->prepare("INSERT INTO topo_nodes (type, device_id, attrs, created_at, updated_at) VALUES ('port', ?, ?, 1, 1)")
		->execute([$device, json_encode(['if_index' => $if, 'name' => $name, 'pseudo' => true, 'learned_macs' => [], 'zabbix_itemids' => []])]);
	return (int) $pdo->lastInsertId();
};
$add($A, -5, 'q1');            // the same allocated if_index as B's pseudo port p1, another name: two ports after the join
$add($B, -5, 'p1');
$add($A, -9, 'Uplink-1');      // the same normalized name: one port
$add($B, -11, 'UPLINK-1');
$r = run_ingest($dsn, $user, $password);
check(($r['summary']['devices_joined']['count'] ?? 0) === 1, 'the reporter identity joins B into A');
$ports = array_map(static fn ($a) => json_decode($a, true), $pdo->query("SELECT attrs FROM topo_nodes WHERE type='port' AND device_id={$A}")->fetchAll(PDO::FETCH_COLUMN));
$names = array_map(static fn ($p) => $p['name'], $ports);
sort($names);
// B's pseudo ports moved to A (by name only), then the leftover pseudo ports of a reporter without links are removed by §6.7.3: only the real port stays.
// (The two-ports / one-port rule is observable on the operator merge, test_duplicate_candidates.php §3.)
check($names === ['Gi0/1'] && (int) ($r['summary']['pseudo_ports_removed'] ?? 0) === 3, 'the moved pseudo ports (Uplink-1 and UPLINK-1 are one) are leftovers of a reporter without links: removed, only Gi0/1 stays — '.implode(', ', $names));
check(count(array_unique(array_column($ports, 'if_index'))) === count($ports), 'no two ports of A share an if_index');

echo "\nAll join checks passed.\n";
