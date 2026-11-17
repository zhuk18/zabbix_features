#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * test_port_follows_name.php — task 086, topology-1-model.md 2.65 §6.6 "A port follows its name": before the PORTS upsert, a row whose name is on one row of the snapshot and on exactly one
 * stored real Port of the Device gives that Port its if_index; all changes of a snapshot together; a Port whose index another Port takes and that gets no row stays without if_index; an empty name
 * or a name on several rows or Ports goes by if_index. The real ingest.php runs as a process.
 *
 * Usage: test_port_follows_name.php <PDO DSN> <database user> [password]
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

/** PORTS rows of a reporter: [[if_index, name], ...]; the items of a row are host*1000+if_index */
function ports_of_host(PDO $pdo, int $hostid, string $chassis, array $list, ?int $clock = null): void {
	global $NOW;
	$rows = array_map(static fn (array $p): array => ['if_index' => $p[0], 'name' => $p[1], 'loc_chassis' => $chassis, 'itemids' => $p[2] ?? [$hostid * 1000 + $p[0]]], $list);
	snapshot($pdo, $hostid * 10 + 1, $hostid, 1, $clock ?? $NOW, $rows);
}

function neighbors_of_host(PDO $pdo, int $hostid, string $chassis, array $rows): void {
	global $NOW;
	snapshot($pdo, $hostid * 10 + 2, $hostid, 2, $NOW, array_map(static fn (array $r): array => $r + ['loc_chassis' => $chassis, 'source' => 'lldp'], $rows));
}

/** name => ['id', 'if_index', 'items'] of the real Ports of the Device with this chassis */
function device_ports(PDO $pdo, string $chassis): array {
	$device = $pdo->prepare("SELECT id FROM topo_nodes WHERE type = 'device' AND JSON_UNQUOTE(JSON_EXTRACT(attrs, '\$.chassis_id')) = ?");
	$device->execute([$chassis]);
	$id = (int) $device->fetchColumn();
	$out = [];
	foreach ($pdo->query("SELECT id, attrs FROM topo_nodes WHERE type = 'port' AND device_id = {$id} ORDER BY id")->fetchAll(PDO::FETCH_ASSOC) as $row) {
		$a = json_decode($row['attrs'], true);
		if (empty($a['pseudo'])) {
			$out[(string) ($a['name'] ?? '')] = ['id' => (int) $row['id'], 'if_index' => $a['if_index'] ?? null, 'has_index' => array_key_exists('if_index', $a)];
		}
	}
	return $out;
}

function base(PDO $pdo): void {
	global $NOW, $C1, $C2;
	reset_all($pdo);
	add_host($pdo, 101, 'SW101', 'Switch 101', '10.0.0.101');
	add_host($pdo, 102, 'SW102', 'Switch 102', '10.0.0.102');
	$four = [[1, 'Gi0/1'], [2, 'Gi0/2'], [3, 'Gi0/3'], [4, 'Gi0/4']];
	ports_of_host($pdo, 101, $C1, $four);
	ports_of_host($pdo, 102, $C2, $four);
	neighbors_of_host($pdo, 101, $C1, [sees(1, $C2, 'Gi0/1')]);
	neighbors_of_host($pdo, 102, $C2, [sees(1, $C1, 'Gi0/1')]);
}

function obs_of(PDO $pdo, int $itemid): array {
	return $pdo->query("SELECT outcome, link_precision, far_port_reason, precision_lower, edge_id FROM topo_observations WHERE itemid = {$itemid}")->fetchAll(PDO::FETCH_ASSOC);
}

echo "\n=== 1. a port gets an index that was not used before ===\n";
base($pdo);
run_ingest($dsn, $user, $password);
$before = device_ports($pdo, $C2);
$link = active($pdo);
check($link === ['a0:00:00:00:00:01/Gi0/1 - a0:00:00:00:00:02/Gi0/1'] && count($before) === 4, 'start: the port-level link Gi0/1 - Gi0/1, 4 Ports on the Device 102', [$link, array_keys($before)]);
$edge = (int) scalar($pdo, "SELECT id FROM topo_edges WHERE type = 'physical_link'");
// 102 reboots: its Gi0/1 has the ifIndex 11 now
ports_of_host($pdo, 102, $C2, [[11, 'Gi0/1'], [2, 'Gi0/2'], [3, 'Gi0/3'], [4, 'Gi0/4']]);
neighbors_of_host($pdo, 102, $C2, [sees(11, $C1, 'Gi0/1')]);
$r = run_ingest($dsn, $user, $password);
$after = device_ports($pdo, $C2);
check(count($after) === 4 && $after['Gi0/1']['id'] === $before['Gi0/1']['id'] && $after['Gi0/1']['if_index'] === 11, 'the same Port id has the new if_index, still 4 Ports', $after);
check(active($pdo) === $link && (int) scalar($pdo, "SELECT id FROM topo_edges WHERE type = 'physical_link'") === $edge, 'the link stays (the same edge)');
$o = obs_of($pdo, 1012);
check(count($o) === 1 && $o[0]['outcome'] === 'applied' && $o[0]['link_precision'] === 'port' && $o[0]['far_port_reason'] === null && (int) $o[0]['precision_lower'] === 0,
	'the neighbor finds one Port (no port_shared_id), its link stays port-level, no precision_lower', $o);
check(($r['summary']['ports_index_followed'] ?? 0) === 1 && ($r['summary']['ports_index_dropped'] ?? 0) === 0, 'the run summary counts one Port that followed its name');
$fp = fingerprint($pdo);
run_ingest($dsn, $user, $password);
check($fp === fingerprint($pdo), 'a second run changes nothing');

echo "\n=== 2. two ports exchange their indexes in one snapshot ===\n";
base($pdo);
run_ingest($dsn, $user, $password);
$before = device_ports($pdo, $C2);
ports_of_host($pdo, 102, $C2, [[2, 'Gi0/1', [777]], [1, 'Gi0/2', [888]], [3, 'Gi0/3'], [4, 'Gi0/4']]);
neighbors_of_host($pdo, 102, $C2, [sees(2, $C1, 'Gi0/1')]);
run_ingest($dsn, $user, $password);
$after = device_ports($pdo, $C2);
check($after['Gi0/1']['id'] === $before['Gi0/1']['id'] && $after['Gi0/2']['id'] === $before['Gi0/2']['id'] && count($after) === 4, 'each Port id keeps its name, no new Port', $after);
check($after['Gi0/1']['if_index'] === 2 && $after['Gi0/2']['if_index'] === 1, 'the indexes are exchanged');
check(active($pdo) === ['a0:00:00:00:00:01/Gi0/1 - a0:00:00:00:00:02/Gi0/1'], 'the link follows the names: still Gi0/1 - Gi0/1', active($pdo));
$o = obs_of($pdo, 1012);
check($o[0]['outcome'] === 'applied' && $o[0]['link_precision'] === 'port' && (int) $o[0]['precision_lower'] === 0, 'the neighbor observation is applied at port level');

echo "\n=== 3. a port that is gone, and another port takes its index (§6.6 item 4) ===\n";
base($pdo);
run_ingest($dsn, $user, $password);
$before = device_ports($pdo, $C2);
$manual = (function () use ($pdo, $before, $C1): int {
	// a manual link on the Port Gi0/3 of 102 (to Gi0/3 of 101)
	$mine = device_ports($pdo, $C1);
	$pdo->prepare("INSERT INTO topo_edges (type, src_id, dst_id, attrs, created_at) VALUES ('physical_link', ?, ?, ?, 1)")
		->execute([min($before['Gi0/3']['id'], $mine['Gi0/3']['id']), max($before['Gi0/3']['id'], $mine['Gi0/3']['id']), json_encode(['discovered_via' => 'manual', 'last_seen' => 1])]);
	return (int) $pdo->lastInsertId();
})();
// Gi0/3 (index 3) is not in the snapshot any more; Gi0/4 now has the index 3
ports_of_host($pdo, 102, $C2, [[1, 'Gi0/1'], [2, 'Gi0/2'], [3, 'Gi0/4']]);
$r = run_ingest($dsn, $user, $password);
$after = device_ports($pdo, $C2);
check($after['Gi0/4']['id'] === $before['Gi0/4']['id'] && $after['Gi0/4']['if_index'] === 3 && count($after) === 4, 'Gi0/4 has the index 3, no new Port');
check($after['Gi0/3']['id'] === $before['Gi0/3']['id'] && $after['Gi0/3']['has_index'] === false , 'Gi0/3 stays (same id, same name), without if_index', $after['Gi0/3']);
check((int) scalar($pdo, "SELECT COUNT(*) FROM topo_edges WHERE id = {$manual} AND JSON_EXTRACT(attrs, '\$.superseded_at') IS NULL") === 1
	&& (int) scalar($pdo, "SELECT COUNT(*) FROM topo_edges WHERE id = {$manual} AND (src_id = {$before['Gi0/3']['id']} OR dst_id = {$before['Gi0/3']['id']})") === 1, 'the manual link on Gi0/3 stays on that Port');
check(($r['summary']['ports_index_followed'] ?? 0) === 1 && ($r['summary']['ports_index_dropped'] ?? 0) === 1, 'the run summary: one Port followed its name, one lost its index');
$fp = fingerprint($pdo);
run_ingest($dsn, $user, $password);
check($fp === fingerprint($pdo) && device_ports($pdo, $C2)['Gi0/3']['has_index'] === false, 'a second run changes nothing; the gone Port still has no index');
// a later row with its name gives the Port an index again
ports_of_host($pdo, 102, $C2, [[1, 'Gi0/1'], [2, 'Gi0/2'], [3, 'Gi0/4'], [7, 'Gi0/3']]);
run_ingest($dsn, $user, $password);
$back = device_ports($pdo, $C2);
check($back['Gi0/3']['id'] === $before['Gi0/3']['id'] && $back['Gi0/3']['if_index'] === 7 && count($back) === 4, 'a later row with its name gives the Port an index again (the same id)', $back['Gi0/3']);

echo "\n=== 4. an empty name, and a name on two rows: by if_index as before ===\n";
base($pdo);
run_ingest($dsn, $user, $password);
$before = device_ports($pdo, $C2);
// the row 1 has no name and no descr; the name "Gi0/2" is on two rows (2 and 6)
ports_of_host($pdo, 102, $C2, [[11, ''], [2, 'Gi0/2'], [6, 'Gi0/2'], [3, 'Gi0/3'], [4, 'Gi0/4']]);
run_ingest($dsn, $user, $password);
$after = device_ports($pdo, $C2);
$by_index = [];
foreach ($pdo->query("SELECT id, JSON_UNQUOTE(JSON_EXTRACT(attrs, '\$.if_index')) AS i FROM topo_nodes WHERE type = 'port' AND device_id = (SELECT device_id FROM topo_nodes WHERE id = {$before['Gi0/1']['id']})")->fetchAll(PDO::FETCH_ASSOC) as $row) {
	$by_index[(int) $row['i']] = (int) $row['id'];
}
check($by_index[1] === $before['Gi0/1']['id'] && isset($by_index[11]) && $by_index[11] !== $before['Gi0/1']['id'], 'the empty-name row 11 is upserted by if_index: a new Port; the old Port with index 1 stays', $by_index);
check($by_index[2] === $before['Gi0/2']['id'] && isset($by_index[6]) && $by_index[6] !== $before['Gi0/2']['id'], 'the name on two rows: no move, the row 6 is a new Port by if_index', $by_index);

echo "\n=== 5. a manual link on a port whose index changes stays on the Port with the same name ===\n";
base($pdo);
run_ingest($dsn, $user, $password);
$before = device_ports($pdo, $C2);
$mine = device_ports($pdo, $C1);
$pdo->prepare("INSERT INTO topo_edges (type, src_id, dst_id, attrs, created_at) VALUES ('physical_link', ?, ?, ?, 1)")
	->execute([min($before['Gi0/4']['id'], $mine['Gi0/4']['id']), max($before['Gi0/4']['id'], $mine['Gi0/4']['id']), json_encode(['discovered_via' => 'manual', 'last_seen' => 1])]);
$manual = (int) $pdo->lastInsertId();
ports_of_host($pdo, 102, $C2, [[1, 'Gi0/1'], [2, 'Gi0/2'], [3, 'Gi0/3'], [14, 'Gi0/4']]);
run_ingest($dsn, $user, $password);
$after = device_ports($pdo, $C2);
check($after['Gi0/4']['id'] === $before['Gi0/4']['id'] && $after['Gi0/4']['if_index'] === 14, 'Gi0/4 has the new index, the same Port');
check((int) scalar($pdo, "SELECT COUNT(*) FROM topo_edges WHERE id = {$manual} AND (src_id = {$before['Gi0/4']['id']} OR dst_id = {$before['Gi0/4']['id']}) AND JSON_EXTRACT(attrs, '\$.superseded_at') IS NULL") === 1,
	'the manual link is still on that Port, active');

echo "\nAll port follows name checks passed.\n";
