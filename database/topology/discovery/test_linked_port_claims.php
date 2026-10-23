#!/usr/bin/env php
<?php

declare(strict_types=1);


/**
 * test_linked_port_claims.php — task 047, topology-1-model.md 2.40/2.41 §8.5: a port P of reporter R with an active discovered link L = R:P<->B:Q, where the claims on P
 * (from the rules of R) resolve to two or more different Devices and no rule of R on P shows L's other end: all claims `ambiguous` (no edge_id), no new link on P, L stays
 * while it has support (B's latest snapshot still contains it), else L is superseded at T = the newest clock of the snapshots on P that contradict it, with no replacement.
 * Not changed: one rule of R on P still shows B, or the claims resolve to one Device: §8.4 per candidate (`conflict` with edge_id = L while L has support).
 * Cases A-F of the task (E: the result does not depend on the order of the reporters or of the rules).
 *
 * Same method as test_stable_ingest.php: rows go straight into topo_lld_snapshot, the REAL ingest.php runs as a subprocess.
 *
 * Usage: test_linked_port_claims.php <PDO DSN> <database user> [password]
 * Expects a THROWAWAY database (it drops and recreates the topology tables and stand-ins of the Zabbix tables).
 */

function fail_test(string $message): void {
	fwrite(STDERR, "FAIL: {$message}\n");
	exit(1);
}

$GLOBALS['failed_checks'] = 0;

/** TEST_CONTINUE=1 lists every failing check instead of stopping at the first (to see which cases an old code fails). */
function check(bool $condition, string $description): void {
	if (!$condition) {
		if (getenv('TEST_CONTINUE')) {
			echo "  FAIL: {$description}\n";
			$GLOBALS['failed_checks']++;
			return;
		}
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

function run_ingest(string $dsn, string $user, string $password, array $extra = [], array $options = []): array {
	$cmd = [PHP_BINARY, __DIR__.'/ingest.php', '--pdo-dsn', $dsn, '--pdo-user', $user, '--pdo-password', $password];
	foreach ($extra as $host) {
		array_push($cmd, '--zabbix-host', $host);
	}
	foreach ($options as $name => $value) {
		array_push($cmd, '--'.$name, (string) $value);
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


// ---- the lab: R (SwR, hostid 101) has the link L on its port Gi0/1 to B (SwB, hostid 102) port Gi0/24; the claims X (LLDP) and Y (CDP) come on Gi0/1 ----
const R_MAC = 'a0:00:00:00:00:01';
const B_MAC = 'b0:00:00:00:00:02';
const X_MAC = 'c0:00:00:00:00:0a';

function lldp_to_b(): array {
	return ['if_index' => 1, 'rem_chassis' => B_MAC, 'rem_chassis_type' => 'macAddress', 'rem_sysname' => 'SwB', 'rem_port' => 'Gi0/24', 'rem_port_type' => 'interfaceName',
		'source' => 'lldp', 'loc_chassis' => R_MAC];
}
function lldp_to_x(string $port = 'e0'): array {
	return ['if_index' => 1, 'rem_chassis' => X_MAC, 'rem_chassis_type' => 'macAddress', 'rem_sysname' => 'X', 'rem_mgmt_ip' => '10.9.9.10', 'rem_port' => $port, 'rem_port_type' => 'interfaceName',
		'source' => 'lldp', 'loc_chassis' => R_MAC];
}
function cdp_to_y(): array {
	return ['if_index' => 1, 'rem_mgmt_ip' => '10.9.9.9', 'rem_sysname' => 'Y', 'rem_port' => 'e1', 'rem_port_type' => 'interfaceName', 'source' => 'cdp', 'loc_chassis' => R_MAC];
}
function cdp_to_b(): array {
	return ['if_index' => 1, 'rem_mgmt_ip' => '10.0.0.2', 'rem_sysname' => 'SwB', 'rem_port' => 'Gi0/24', 'rem_port_type' => 'interfaceName', 'source' => 'cdp', 'loc_chassis' => R_MAC];
}
function cdp_to_x(): array {
	return ['if_index' => 1, 'rem_mgmt_ip' => '10.9.9.10', 'rem_sysname' => 'X', 'rem_port' => 'e9', 'rem_port_type' => 'interfaceName', 'source' => 'cdp', 'loc_chassis' => R_MAC];
}
function lldp_to_r(): array {
	return ['if_index' => 24, 'rem_chassis' => R_MAC, 'rem_chassis_type' => 'macAddress', 'rem_sysname' => 'SwR', 'rem_port' => 'Gi0/1', 'rem_port_type' => 'interfaceName',
		'source' => 'lldp', 'loc_chassis' => B_MAC];
}

/** Both reporters and the link L (R:Gi0/1 <-> B:Gi0/24) from the LLDP rows of both ends. $items = [R lldp item, R cdp item]. Returns the id of L. */
function establish(PDO $pdo, string $dsn, string $user, string $password, array $items = [1002, 1003], array $options = []): int {
	reset_all($pdo);
	add_host($pdo, 101, 'SwR', 'SwR', '10.0.0.1');
	add_host($pdo, 102, 'SwB', 'SwB', '10.0.0.2');
	snapshot($pdo, 1001, 101, 1, CLOCK, [['if_index' => 1, 'name' => 'Gi0/1', 'loc_chassis' => R_MAC], ['if_index' => 2, 'name' => 'Gi0/2', 'loc_chassis' => R_MAC]]);
	snapshot($pdo, 2001, 102, 1, CLOCK, [['if_index' => 24, 'name' => 'Gi0/24', 'loc_chassis' => B_MAC]]);
	snapshot($pdo, $items[0], 101, 2, CLOCK + 10, [lldp_to_b()]);
	snapshot($pdo, $items[1], 101, 2, CLOCK + 10, []);
	snapshot($pdo, 2002, 102, 2, CLOCK + 10, [lldp_to_r()]);
	run_ingest($dsn, $user, $password, [], $options);
	$links = edges($pdo);
	if (count($links) !== 1 || isset(json_decode($links[0]['attrs'], true)['superseded_at'])) {
		fail_test('setup: the link L was not made: '.json_encode($links));
	}
	return (int) $links[0]['id'];
}

/** What a case is judged by: the links (id, superseded_at) and the observations of R's rules on its port. */
function outcome_of(PDO $pdo): array {
	$links = [];
	foreach (edges($pdo) as $e) {
		$links[(int) $e['id']] = json_decode($e['attrs'], true)['superseded_at'] ?? null;
	}
	$obs = [];
	foreach ($pdo->query("SELECT o.remote_key, o.itemid, o.outcome, o.edge_id FROM topo_observations o WHERE o.itemid IN (1002, 1003) ORDER BY o.itemid, o.remote_key")->fetchAll(PDO::FETCH_ASSOC) as $o) {
		$obs[$o['itemid'].' '.$o['remote_key']] = [$o['outcome'], $o['edge_id'] === null ? null : (int) $o['edge_id']];
	}
	return ['links' => $links, 'obs' => $obs];
}

function ambiguous_all(array $o, array $keys): bool {
	foreach ($keys as $k) {
		$hit = array_filter($o['obs'], static fn ($v, $key) => str_ends_with($key, $k), ARRAY_FILTER_USE_BOTH);
		if (!$hit || array_filter($hit, static fn (array $v): bool => $v[0] !== 'ambiguous' || $v[1] !== null)) {
			return false;
		}
	}
	return true;
}

// the clocks of R's snapshots on Gi0/1 that contradict L
const T_LLDP = CLOCK + 30;
const T_CDP = CLOCK + 40;

// ============================================================================================================
echo "\n=== A: B still reports L; R's LLDP shows X and R's CDP shows Y on Gi0/1 ===\n";
$L = establish($pdo, $dsn, $user, $password);
snapshot($pdo, 1002, 101, 2, T_LLDP, [lldp_to_x()]);
snapshot($pdo, 1003, 101, 2, T_CDP, [cdp_to_y()]);
$r = run_ingest($dsn, $user, $password);
$a = outcome_of($pdo);
echo '  '.json_encode($a)."\n";
check($a['links'] === [$L => null], 'A: L stays active, no new link on Gi0/1');
check(ambiguous_all($a, ['chassis:mac:'.X_MAC, 'ip:server:10.9.9.9']), 'A: X and Y are ambiguous, no edge_id');
$fp = fingerprint($pdo);
run_ingest($dsn, $user, $password);
check(fingerprint($pdo) === $fp, 'A: a rerun changes nothing');

echo "\n=== B: B no longer reports L ===\n";
snapshot($pdo, 2002, 102, 2, CLOCK + 50, []);
run_ingest($dsn, $user, $password);
$b = outcome_of($pdo);
echo '  '.json_encode($b)."\n";
check($b['links'] === [$L => T_CDP], 'B: L is superseded, superseded_at = the newer clock of the two snapshots of R, and no new link');
check(ambiguous_all($b, ['chassis:mac:'.X_MAC, 'ip:server:10.9.9.9']), 'B: X and Y are ambiguous, no edge_id');
$fp = fingerprint($pdo);
$r = run_ingest($dsn, $user, $password);
check(fingerprint($pdo) === $fp && ($r['summary']['links_superseded'] ?? -1) === 0, 'B: a second run changes nothing (R-3)');

echo "\n=== C (no change): R's CDP shows B, R's LLDP shows X ===\n";
$L = establish($pdo, $dsn, $user, $password);
snapshot($pdo, 1002, 101, 2, T_LLDP, [lldp_to_x()]);
snapshot($pdo, 1003, 101, 2, T_CDP, [cdp_to_b()]);
run_ingest($dsn, $user, $password);
$c = outcome_of($pdo);
echo '  '.json_encode($c)."\n";
check($c['links'] === [$L => null], 'C: L stays active');
check(($c['obs']['1002 chassis:mac:'.X_MAC] ?? null) === ['conflict', $L], 'C: X is `conflict`, edge_id = L');
check(($c['obs']['1003 ip:server:10.0.0.2'][0] ?? null) === 'applied', 'C: the CDP claim for B is applied');

echo "\n=== D (no change): R's LLDP and CDP show the same Device X (different port ids) ===\n";
$L = establish($pdo, $dsn, $user, $password);
snapshot($pdo, 1002, 101, 2, T_LLDP, [lldp_to_x('e0')]);
snapshot($pdo, 1003, 101, 2, T_CDP, [cdp_to_x()]);
run_ingest($dsn, $user, $password);
$d = outcome_of($pdo);
echo '  '.json_encode($d)."\n";
check($d['links'] === [$L => null], 'D: L stays active (B supports it), no new link');
check(array_unique(array_column($d['obs'], 0)) === ['conflict'] && array_unique(array_column($d['obs'], 1)) === [$L], 'D: one Device, §8.4 per candidate as today: both claims `conflict`, edge_id = L');
snapshot($pdo, 2002, 102, 2, CLOCK + 50, []);
run_ingest($dsn, $user, $password);
$d2 = outcome_of($pdo);
echo '  without support: '.json_encode($d2)."\n";
check(count($d2['links']) >= 1 && !in_array('ambiguous', array_column($d2['obs'], 0), true), 'D: without support the claims are not ambiguous (one Device: not the new rule)');

echo "\n=== E: the result of A and B does not depend on the order of the reporters or of the rules ===\n";
$signature = static function (array $o, int $L): array {
	// natural form: ids replaced by "L" / null so that runs with another creation order compare
	$obs = [];
	foreach ($o['obs'] as $key => [$outcome, $edge]) {
		$obs[preg_replace('/^\d+ /', '', $key)] = [$outcome, $edge === null ? null : ($edge === $L ? 'L' : 'other')];
	}
	ksort($obs);
	return ['links' => array_values($o['links']), 'obs' => $obs];
};
$variants = [
	'default order' => [[1002, 1003], []],
	'reporters B, R' => [[1002, 1003], ['reporter-order' => '102,101']],
	'rules swapped (LLDP has the higher item id)' => [[1003, 1002], []],
	'rules swapped and reporters B, R' => [[1003, 1002], ['reporter-order' => '102,101']],
];
$sig_a = [];
$sig_b = [];
foreach ($variants as $name => [$items, $options]) {
	$L = establish($pdo, $dsn, $user, $password, $items, $options);
	snapshot($pdo, $items[0], 101, 2, T_LLDP, [lldp_to_x()]);
	snapshot($pdo, $items[1], 101, 2, T_CDP, [cdp_to_y()]);
	run_ingest($dsn, $user, $password, [], $options);
	$o = outcome_of($pdo);
	$o['obs'] = array_combine(array_map(static fn (string $k): string => preg_replace('/^(\d+) /', '$1 ', $k), array_keys($o['obs'])), array_values($o['obs']));
	$sig_a[$name] = $signature(['links' => $o['links'], 'obs' => $o['obs']], $L);
	snapshot($pdo, 2002, 102, 2, CLOCK + 50, []);
	run_ingest($dsn, $user, $password, [], $options);
	$o = outcome_of($pdo);
	$sig_b[$name] = $signature($o, $L);
}
// the item ids of the two rules differ between the variants: compare by the remote key only
$norm = static function (array $sigs): array {
	return array_map(static function (array $s): array {
		$obs = [];
		foreach ($s['obs'] as $k => $v) {
			$obs[$k] = $v;
		}
		return ['links' => $s['links'], 'outcomes' => array_values(array_map(static fn (array $v): array => $v, $obs))];
	}, $sigs);
};
echo '  A: '.json_encode(array_values($norm($sig_a))[0])."\n  B: ".json_encode(array_values($norm($sig_b))[0])."\n";
check(count(array_unique(array_map('json_encode', $norm($sig_a)))) === 1, 'E: case A gives the same result in all four orders');
check(count(array_unique(array_map('json_encode', $norm($sig_b)))) === 1, 'E: case B gives the same result in all four orders');

echo "\n=== F (model 2.41): several Devices in one snapshot of R's LLDP on Gi0/1, none of them B ===\n";
$L = establish($pdo, $dsn, $user, $password);
snapshot($pdo, 1002, 101, 2, T_LLDP, [lldp_to_x(), ['if_index' => 1, 'rem_mgmt_ip' => '10.9.9.9', 'rem_sysname' => 'Y', 'rem_port' => 'e1', 'rem_port_type' => 'interfaceName', 'source' => 'lldp', 'loc_chassis' => R_MAC]]);
run_ingest($dsn, $user, $password);
$f = outcome_of($pdo);
echo '  '.json_encode($f)."\n";
check($f['links'] === [$L => null], 'F: L stays active while B supports it');
check(ambiguous_all($f, ['chassis:mac:'.X_MAC, 'ip:server:10.9.9.9']), 'F: X and Y are ambiguous, no edge_id');
snapshot($pdo, 2002, 102, 2, CLOCK + 50, []);
run_ingest($dsn, $user, $password);
$f2 = outcome_of($pdo);
echo '  without support: '.json_encode($f2)."\n";
check($f2['links'] === [$L => T_LLDP], 'F: with no support L is superseded at the clock of the snapshot, no replacement');
check(ambiguous_all($f2, ['chassis:mac:'.X_MAC, 'ip:server:10.9.9.9']), 'F: X and Y stay ambiguous');
$fp = fingerprint($pdo);
run_ingest($dsn, $user, $password);
check(fingerprint($pdo) === $fp, 'F: a second run changes nothing');

echo $GLOBALS['failed_checks'] ? "\n".$GLOBALS['failed_checks']." FAILED\n" : "\nALL OK\n";
exit($GLOBALS['failed_checks'] ? 1 : 0);
