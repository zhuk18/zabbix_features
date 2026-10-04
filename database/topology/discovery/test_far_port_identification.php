#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * test_far_port_identification.php — topology-1-model.md §6.7.2 "Far port": the far port of a neighbor row is decided by the port id
 * subtype (interfaceName / CDP: normalized name; macAddress: Port mac; local numeric: ifIndex, else name); the LLDP port
 * description is only a fallback. Several Ports at a step -> port_shared_id; none -> port_unmatched. A far Device that is not a
 * reporter gets a pseudo port named by the same subtype rules.
 *
 * Same method as test_snapshot_ingest.php. Writes rows straight into topo_lld_snapshot (what the server's LLD worker would store) and runs the REAL
 * ingest.php as a subprocess after every change, so the actual CLI path is exercised end to end — no test-only
 * hook, no reimplementation of the algorithm here.
 *
 * Usage: test_far_port_identification.php <PDO DSN> <database user> [password]
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
$R = 'a0:00:00:00:00:01';
$D = 'b0:00:00:00:00:0a';
$MAC1 = '02:00:00:0a:00:01';
$MAC2 = '02:00:00:0a:00:02';

function reset_all(PDO $pdo): void {
	$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
	foreach (['topo_observations', 'topo_edges', 'topo_nodes', 'topo_lld_snapshot', 'host_inventory', 'interface', 'items', 'hosts'] as $t) {
		$pdo->exec("DELETE FROM {$t}");
	}
	$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
}

/** R (reporter, ports Gi0/1..Gi0/8) and, when $d_ports is not null, D as a reporter with those ports ([ifIndex => [name, mac]]). */
function lab(PDO $pdo, string $R, string $D, ?array $d_ports): void {
	reset_all($pdo);
	add_host($pdo, 101, 'SwR', 'Switch R', '10.0.0.1');
	$ports = [];
	for ($i = 1; $i <= 8; $i++) {
		$ports[] = ['if_index' => $i, 'name' => "Gi0/{$i}", 'loc_chassis' => $R];
	}
	snapshot($pdo, 1001, 101, 1, CLOCK, $ports);
	if ($d_ports !== null) {
		add_host($pdo, 102, 'SwD', 'Switch D', '10.0.0.2');
		$rows = [];
		foreach ($d_ports as $if => [$name, $mac]) {
			$rows[] = ['if_index' => $if, 'name' => $name, 'mac' => $mac, 'loc_chassis' => $D];
		}
		snapshot($pdo, 2001, 102, 1, CLOCK, $rows);
	}
}

/** One NEIGHBORS row of R seeing D on local port $if. */
function nrow(string $D, int $if, array $extra): array {
	return $extra + ['if_index' => $if, 'rem_chassis' => $D, 'rem_chassis_type' => 'macAddress', 'rem_sysname' => 'D', 'source' => 'lldp',
		'loc_chassis' => 'a0:00:00:00:00:01'];
}

/** What R's local port $if got: [type, far port name or null, reason, outcome, observation precision]. */
function result_of(PDO $pdo, int $if): array {
	$stmt = $pdo->prepare("SELECT e.type, e.attrs, e.src_id, e.dst_id FROM topo_edges e JOIN topo_nodes p ON p.id IN (e.src_id, e.dst_id) AND p.type='port'".
		" AND JSON_EXTRACT(p.attrs,'\$.if_index') = ? JOIN topo_nodes d ON d.id = p.device_id AND JSON_UNQUOTE(JSON_EXTRACT(d.attrs,'\$.chassis_id')) = 'a0:00:00:00:00:01'".
		" WHERE JSON_EXTRACT(e.attrs,'\$.superseded_at') IS NULL");
	$stmt->execute([$if]);
	$e = $stmt->fetch(PDO::FETCH_ASSOC);
	if (!$e) {
		return ['none'];
	}
	$a = json_decode($e['attrs'], true);
	$far = null;
	if ($e['type'] === 'physical_link') {
		foreach ([$e['src_id'], $e['dst_id']] as $id) {
			$n = json_decode((string) scalar($GLOBALS['pdo'], 'SELECT attrs FROM topo_nodes WHERE id=?', [$id]), true);
			$dev = scalar($GLOBALS['pdo'], 'SELECT device_id FROM topo_nodes WHERE id=?', [$id]);
			if (json_decode((string) scalar($GLOBALS['pdo'], 'SELECT attrs FROM topo_nodes WHERE id=?', [$dev]), true)['chassis_id'] !== 'a0:00:00:00:00:01') {
				$far = $n['name'].(($n['pseudo'] ?? false) ? '*' : '');
			}
		}
	}
	return [$e['type'], $far, $a['far_port_reason'] ?? null];
}

function pseudo_ports(PDO $pdo, string $chassis): array {
	$out = [];
	foreach ($pdo->query("SELECT p.attrs FROM topo_nodes p JOIN topo_nodes d ON d.id=p.device_id WHERE p.type='port' AND JSON_UNQUOTE(JSON_EXTRACT(d.attrs,'\$.chassis_id'))='{$chassis}' ORDER BY p.id")->fetchAll(PDO::FETCH_COLUMN) as $a) {
		$a = json_decode($a, true);
		$out[] = [$a['name'], $a['if_index'], $a['pseudo'] ?? null];
	}
	return $out;
}

function check_res(PDO $pdo, int $if, array $expected, string $what): void {
	$actual = result_of($pdo, $if);
	check($actual === $expected, $what.($actual === $expected ? '' : ' (actual '.json_encode($actual).')'));
}

$d_ports = [10 => ['Gi0/1', $MAC1], 11 => ['Gi0/2', $MAC2], 12 => ['Gi0/3', $MAC2], 13 => ['Gi0/9', null], 14 => ['Gi0/9', null]];

echo "\n=== 1: the far Device is a reporter — primary match by subtype, the description is a fallback ===\n";
foreach ([
	'interfaceName Gi0/1 with the description "uplink to core": port-level to Gi0/1' => [['rem_port' => 'Gi0/1', 'rem_port_type' => 'interfaceName', 'rem_port_desc' => 'uplink to core'], ['physical_link', 'Gi0/1', null]],
	'macAddress = the MAC of one Port: port-level to that Port' => [['rem_port' => $MAC1, 'rem_port_type' => 'macAddress'], ['physical_link', 'Gi0/1', null]],
	'macAddress = the MAC of two Ports: device_link, port_shared_id' => [['rem_port' => $MAC2, 'rem_port_type' => 'macAddress'], ['device_link', null, 'port_shared_id']],
	'local "10" = the ifIndex of Gi0/1: port-level' => [['rem_port' => '10', 'rem_port_type' => 'local'], ['physical_link', 'Gi0/1', null]],
	'local "1": no Port of ifIndex 1, no name "1": device_link, port_unmatched (no phantom port)' => [['rem_port' => '1', 'rem_port_type' => 'local'], ['device_link', null, 'port_unmatched']],
	'no subtype: the name' => [['rem_port' => 'Gi0/2'], ['physical_link', 'Gi0/2', null]],
	'no Port by id: the description by normalized name (GigabitEthernet0/3 = Gi0/3)' => [['rem_port' => 'ge-9', 'rem_port_type' => 'interfaceName', 'rem_port_desc' => 'GigabitEthernet0/3'], ['physical_link', 'Gi0/3', null]],
	'two Ports by id: port_shared_id, the description is not tried' => [['rem_port' => 'Gi0/9', 'rem_port_type' => 'interfaceName', 'rem_port_desc' => 'Gi0/1'], ['device_link', null, 'port_shared_id']],
	'no match at both steps: port_unmatched' => [['rem_port' => 'nothing', 'rem_port_type' => 'interfaceName', 'rem_port_desc' => 'free text'], ['device_link', null, 'port_unmatched']],
] as $label => [$extra, $expected]) {
	lab($pdo, $R, $D, $d_ports);
	snapshot($pdo, 1002, 101, 2, CLOCK + 10, [nrow($D, 1, $extra)]);
	$r = run_ingest($dsn, $user, $password);
	check_res($pdo, 1, $expected, $label);
	check(count(pseudo_ports($pdo, $D)) === 5, '  no port was created on D');
}

echo "\n=== 2: CDP counts as interfaceName; LLDP + CDP with a free-text description give one far port, one link ===\n";
lab($pdo, $R, $D, $d_ports);
snapshot($pdo, 1002, 101, 2, CLOCK + 10, [nrow($D, 1, ['rem_port' => 'Gi0/1', 'rem_port_type' => 'interfaceName', 'rem_port_desc' => 'uplink to core'])]);
snapshot($pdo, 1003, 101, 2, CLOCK + 10, [nrow($D, 1, ['rem_port' => 'GigabitEthernet0/1', 'source' => 'cdp'])]);
run_ingest($dsn, $user, $password);
check_res($pdo, 1, ['physical_link', 'Gi0/1', null], 'one link to Gi0/1');
check((int) scalar($pdo, "SELECT COUNT(*) FROM topo_observations WHERE outcome='applied'") === 2 && (int) scalar($pdo, 'SELECT COUNT(*) FROM topo_edges') === 1, 'both observations applied, one edge');

echo "\n=== 3: the far Device is not a reporter — the pseudo port name by subtype ===\n";
$X = 'c0:00:00:00:00:0c';
foreach ([
	'interfaceName: rem_port, not the description' => [['rem_port' => 'Gi0/24', 'rem_port_type' => 'interfaceName', 'rem_port_desc' => 'uplink to core'], 'Gi0/24', null],
	'macAddress: the description when present' => [['rem_port' => $MAC1, 'rem_port_type' => 'macAddress', 'rem_port_desc' => 'eth0'], 'eth0', null],
	'macAddress: else rem_port' => [['rem_port' => $MAC1, 'rem_port_type' => 'macAddress'], $MAC1, null],
	'local non-numeric: rem_port' => [['rem_port' => 'ge-0/0/1', 'rem_port_type' => 'local', 'rem_port_desc' => 'free text'], 'ge-0/0/1', null],
	'local numeric: the ifIndex, the description as the name' => [['rem_port' => '7', 'rem_port_type' => 'local', 'rem_port_desc' => 'Port seven'], 'Port seven', 7],
	'no subtype: the description' => [['rem_port' => 'x1', 'rem_port_desc' => 'Port x'], 'Port x', null],
] as $label => [$extra, $name, $index]) {
	lab($pdo, $R, $D, null);
	snapshot($pdo, 1002, 101, 2, CLOCK + 10, [nrow($X, 1, $extra)]);
	run_ingest($dsn, $user, $password);
	$p = pseudo_ports($pdo, $X);
	check(count($p) === 1 && $p[0][0] === $name && $p[0][2] === true && ($index === null ? $p[0][1] < 0 : $p[0][1] === $index),
		"{$label}: pseudo port ".json_encode($p));
}
echo "\nAll far port identification checks passed.\n";
