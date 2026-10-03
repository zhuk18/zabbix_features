#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * test_manual_link_busy_port.php — topology-1-model.md "Manual links" (creation and "Manual wins"): linkPorts() and
 * linkPortToDevice() reject a port that already has an active MANUAL link; an active discovered link on the port is
 * superseded at once (superseded_at = now), and "Accept discovery" later revives it with the same edge id.
 *
 * The calls go through CTopologyPrototype, the code the UI endpoints use, loaded on the database layer against the
 * throwaway database; ingest.php runs against the same database.
 *
 * Usage: test_manual_link_busy_port.php <database> <database user> [password]   (MySQL on localhost)
 * Expects a THROWAWAY database (tables are dropped and recreated).
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
	fwrite(STDERR, "Usage: {$argv[0]} <database> <database user> [password]\n");
	exit(1);
}
[$database, $user] = $args;
$password = $args[2] ?? '';
$dsn = 'mysql:host=localhost;dbname='.$database.';charset=utf8mb4';

$pdo = new PDO($dsn, $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

// ---- the Zabbix PHP layer on the same database (no session; the API facade is stubbed to "every host") ----

$ui = realpath(__DIR__.'/../../../ui');
chdir($ui);
set_include_path($ui);
require_once 'include/defines.inc.php';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
require_once 'include/classes/core/CAutoloader.php';

class API {
	public static function Host() {
		return new class {
			public function get($options) {
				$hosts = [];
				foreach (DBselect('SELECT hostid FROM hosts') as $row) {
					$hosts[] = ['hostid' => (string) $row['hostid']];
				}
				return $hosts;
			}
		};
	}
}

foreach (['debug', 'gettextwrapper', 'defines', 'func', 'html', 'perm', 'users', 'validate', 'locales', 'db'] as $file) {
	require_once "include/{$file}.inc.php";
}
$loader = new CAutoloader();
$loader->addNamespace('', array_map(static fn (string $path): string => "{$ui}/include/classes/{$path}", [
	'api', 'api/services', 'api/helpers', 'api/item_types', 'api/managers', 'api/clients', 'api/wrappers', 'core', 'data',
	'mvc', 'db', 'debug', 'validators', 'validators/schema', 'validators/string', 'validators/object', 'helpers', 'json',
	'routing', 'controllers', 'user', 'topology', 'parsers', 'parsers/results', 'regexp', 'macros', 'items', 'triggers'
]));
$loader->addNamespace('', ["{$ui}/app/controllers"]);
$loader->register();

global $DB;
$DB = ['TYPE' => ZBX_DB_MYSQL, 'SERVER' => 'localhost', 'PORT' => '0', 'DATABASE' => $database, 'USER' => $user,
	'PASSWORD' => $password, 'SCHEMA' => '', 'ENCRYPTION' => false, 'TRANSACTIONS' => 0,
	'TRANSACTION_NO_FAILED_SQLS' => true, 'SELECT_COUNT' => 0, 'EXECUTE_COUNT' => 0];
$backend = new MysqlDbBackend();
$DB['DB'] = $backend->connect($DB['SERVER'], $DB['PORT'], $DB['USER'], $DB['PASSWORD'], $DB['DATABASE'], $DB['SCHEMA']);
if ($backend->getError()) {
	fail_test('cannot connect: '.$backend->getError());
}
$backend->init();

// ---- helpers (as in test_manual_contradiction.php) ----

function reset_db(PDO $pdo): void {
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
}

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


/** A NEIGHBORS row: this reporter's port $if sees $rem_chassis / $rem_port. */
function nbr(string $loc, int $if, string $rem_chassis, string $rem_port, string $source = 'lldp'): array {
	return ['if_index' => $if, 'rem_chassis' => $rem_chassis, 'rem_chassis_type' => 'macAddress', 'rem_sysname' => 'sys-'.$rem_chassis,
		'rem_port' => $rem_port, 'rem_port_type' => 'interfaceName', 'source' => $source, 'loc_chassis' => $loc];
}

/** A PORTS snapshot giving the reporter the named real ports. */
function ports_rows(string $loc, array $names): array {
	$rows = [];
	foreach ($names as $if => $name) {
		$rows[] = ['if_index' => $if, 'name' => $name, 'if_type' => '6', 'admin_status' => '1', 'oper_status' => '1', 'loc_chassis' => $loc];
	}
	return $rows;
}

function active_link_count(PDO $pdo, int $port_id): int {
	return (int) scalar($pdo, "SELECT COUNT(*) FROM topo_edges WHERE type='physical_link' AND (src_id=? OR dst_id=?)".
		" AND JSON_EXTRACT(attrs,'\$.superseded_at') IS NULL", [$port_id, $port_id]);
}

/** topology-1-model.md "Port uniqueness": at most one non-superseded link per port, over ALL ports. */
function assert_port_uniqueness(PDO $pdo, string $when): void {
	$bad = $pdo->query("SELECT p.id FROM topo_nodes p WHERE p.type='port' AND (SELECT COUNT(*) FROM topo_edges e".
		" WHERE e.type='physical_link' AND (e.src_id=p.id OR e.dst_id=p.id) AND JSON_EXTRACT(e.attrs,'\$.superseded_at') IS NULL) > 1")
		->fetchAll(PDO::FETCH_COLUMN);
	check(!$bad, "port uniqueness holds over all ports {$when}");
}

function ingest(string $dsn, string $user, string $password, PDO $pdo, string $when, array $extra = [], ?string $order = null,
		bool $check_unique = true): array {
	$r = run_ingest($dsn, $user, $password, $extra, $order);
	if ($r['code'] !== 0) {
		fail_test("ingest failed {$when}: ".trim($r['err'].' '.$r['out']));
	}
	if ($check_unique) {
		assert_port_uniqueness($pdo, $when);
	}
	return $r;
}

/** The link between two ports (or null), with its decoded attrs. */
function link_between(PDO $pdo, int $a, int $b): ?array {
	$stmt = $pdo->prepare("SELECT id, attrs FROM topo_edges WHERE type='physical_link' AND src_id=? AND dst_id=?");
	$stmt->execute([min($a, $b), max($a, $b)]);
	$row = $stmt->fetch(PDO::FETCH_ASSOC);
	return $row ? ['id' => (int) $row['id']] + json_decode($row['attrs'], true) : null;
}

function is_active(?array $link): bool {
	return $link !== null && !array_key_exists('superseded_at', $link);
}

function observation(PDO $pdo, int $itemid, string $chassis): ?array {
	$stmt = $pdo->prepare('SELECT outcome, edge_id FROM topo_observations WHERE itemid=? AND remote_key LIKE ?');
	$stmt->execute([$itemid, '%'.$chassis]);
	$row = $stmt->fetch(PDO::FETCH_ASSOC);
	return $row ?: null;
}


const CLOCK = 1_800_000_000;
$R = 'a0:00:00:00:00:01';
$U = 'b0:00:00:00:00:02';     // UPS1, the manual link's far end
$PR = 'c0:00:00:00:00:03';    // Printer1, the live neighbor on R:Gi0/1

function ports_of(PDO $pdo, string $chassis): array {
	$dev = device_id($pdo, $chassis);
	$out = [];
	foreach ($pdo->query("SELECT id, attrs FROM topo_nodes WHERE type='port' AND device_id={$dev}")->fetchAll(PDO::FETCH_ASSOC) as $row) {
		$out[json_decode($row['attrs'], true)['name']] = (int) $row['id'];
	}
	return $out;
}

function contradicted(array $r): int {
	return (int) $r['summary']['manual_links_contradicted'];
}

/** A Device with one port, created by hand (the far end the lab's UPS1 is). */
function add_device_with_port(PDO $pdo, string $chassis, string $name, string $port): array {
	$pdo->prepare("INSERT INTO topo_nodes (type, attrs, created_at, updated_at) VALUES ('device', ?, 1, 1)")
		->execute([json_encode(['chassis_id' => $chassis, 'sysname' => $name, 'mac' => null, 'mgmt_ip' => null, 'vendor' => 'unknown', 'last_seen' => 1])]);
	$device = (int) $pdo->lastInsertId();
	$pdo->prepare("INSERT INTO topo_nodes (type, device_id, attrs, created_at, updated_at) VALUES ('port', ?, ?, 1, 1)")
		->execute([$device, json_encode(['if_index' => 1, 'name' => $port, 'if_type' => 'physical', 'pseudo' => false, 'learned_macs' => [], 'zabbix_itemids' => []])]);
	return ['device' => $device, 'port' => (int) $pdo->lastInsertId()];
}

function attempt(callable $call): ?string {
	try {
		$call();
		return null;
	}
	catch (Exception $exception) {
		return $exception->getMessage();
	}
}

/** Router1 with Gi0/1 discovered against Printer1:Gi0/1, plus UPS1 (no discovery) with eth0. Returns the ids. */
function busy_discovered(PDO $pdo, string $dsn, string $user, string $password, string $R, string $U, string $PR): array {
	reset_db($pdo);
	add_host($pdo, 101, 'Router1', 'Router1', '10.0.0.1');
	snapshot($pdo, 1001, 101, 1, CLOCK, ports_rows($R, [1 => 'Gi0/1']));
	snapshot($pdo, 1002, 101, 2, CLOCK + 10, [nbr($R, 1, $PR, 'Gi0/1')]);
	ingest($dsn, $user, $password, $pdo, 'with the printer link');
	$P = ports_of($pdo, $R)['Gi0/1'];
	$printer_port = ports_of($pdo, $PR)['Gi0/1'];
	$ups = add_device_with_port($pdo, $U, 'UPS1', 'eth0');
	$discovered = link_between($pdo, $P, $printer_port);
	return ['P' => $P, 'printer_port' => $printer_port, 'ups' => $ups, 'discovered' => $discovered['id'],
		'printer_device' => device_id($pdo, $PR)];
}

// ============================================================================================================
echo "\n=== 1: both ports free — a manual physical_link is created, a manual device_link is created ===\n";
reset_db($pdo);
add_host($pdo, 101, 'Router1', 'Router1', '10.0.0.1');
snapshot($pdo, 1001, 101, 1, CLOCK, ports_rows($R, [1 => 'Gi0/1', 2 => 'Gi0/2']));
ingest($dsn, $user, $password, $pdo, 'to create the ports');
$ports = ports_of($pdo, $R);
$ups = add_device_with_port($pdo, $U, 'UPS1', 'eth0');
check(attempt(fn () => CTopologyPrototype::linkPorts((string) $ports['Gi0/1'], (string) $ups['port'])) === null, 'linkPorts on free ports works');
$l = link_between($pdo, $ports['Gi0/1'], $ups['port']);
check($l !== null && $l['discovered_via'] === 'manual' && is_active($l), 'the physical_link is manual and active');
$id = CTopologyPrototype::linkPortToDevice((string) $ports['Gi0/2'], (string) $ups['device'], 'Admin');
$row = $pdo->query("SELECT attrs FROM topo_edges WHERE id={$id} AND type='device_link'")->fetchColumn();
$attrs = json_decode((string) $row, true);
check($attrs['discovered_via'] === 'manual' && !isset($attrs['superseded_at']), 'the device_link is manual and active');

// ============================================================================================================
echo "\n=== 2: a port with an active MANUAL link rejects both link types, and nothing changes ===\n";
$other = add_device_with_port($pdo, 'e0:00:00:00:00:05', 'Other', 'p1');
$before = fingerprint($pdo);
$msg = attempt(fn () => CTopologyPrototype::linkPorts((string) $ports['Gi0/1'], (string) $other['port']));
check($msg !== null && str_contains($msg, 'manual'), 'linkPorts: near port busy with a manual link -> rejected ("'.$msg.'")');
$msg = attempt(fn () => CTopologyPrototype::linkPorts((string) $other['port'], (string) $ups['port']));
check($msg !== null && str_contains($msg, 'manual'), 'linkPorts: far port busy with a manual link -> rejected');
$msg = attempt(fn () => CTopologyPrototype::linkPorts((string) $ups['port'], (string) $ports['Gi0/1']));
check($msg !== null, 'linkPorts: the same pair again (reversed) -> rejected');
$msg = attempt(fn () => CTopologyPrototype::linkPortToDevice((string) $ports['Gi0/2'], (string) $other['device'], 'Admin'));
check($msg !== null && str_contains($msg, 'manual'), 'linkPortToDevice: port busy with a manual device_link -> rejected');
$msg = attempt(fn () => CTopologyPrototype::linkPortToDevice((string) $ports['Gi0/1'], (string) $other['device'], 'Admin'));
check($msg !== null && str_contains($msg, 'manual'), 'linkPortToDevice: port busy with a manual physical_link -> rejected');
check(fingerprint($pdo) === $before, 'the rejected calls wrote nothing');
assert_port_uniqueness($pdo, 'after the rejections');

// ============================================================================================================
echo "\n=== 3: a port with an active DISCOVERED link — it is superseded at once, port uniqueness holds ===\n";
$b = busy_discovered($pdo, $dsn, $user, $password, $R, $U, $PR);
check(is_active(link_between($pdo, $b['P'], $b['printer_port'])), 'before: the discovered link is active');
$t0 = time();
CTopologyPrototype::linkPorts((string) $b['P'], (string) $b['ups']['port']);
$old = link_between($pdo, $b['P'], $b['printer_port']);
$new = link_between($pdo, $b['P'], $b['ups']['port']);
check(!is_active($old) && $old['superseded_at'] >= $t0 && $old['superseded_at'] <= time(), 'the discovered link has superseded_at = now');
check($old['id'] === $b['discovered'] && $old['discovered_via'] === 'lldp', 'it is the same edge, still discovered, not deleted');
check($new !== null && is_active($new) && $new['discovered_via'] === 'manual', 'the manual link is active');
assert_port_uniqueness($pdo, 'after the manual link');

echo "  -- the far port is the busy one --\n";
$b = busy_discovered($pdo, $dsn, $user, $password, $R, $U, $PR);
$spare = add_device_with_port($pdo, 'e0:00:00:00:00:05', 'Other', 'p1');
CTopologyPrototype::linkPorts((string) $spare['port'], (string) $b['printer_port']);
check(!is_active(link_between($pdo, $b['P'], $b['printer_port'])), 'a discovered link on the FAR port is superseded as well');
check(is_active(link_between($pdo, $spare['port'], $b['printer_port'])), 'the manual link is active');
assert_port_uniqueness($pdo, 'after the manual link on the far port');

echo "  -- a device_link --\n";
$b = busy_discovered($pdo, $dsn, $user, $password, $R, $U, $PR);
$id = CTopologyPrototype::linkPortToDevice((string) $b['P'], (string) $b['ups']['device'], 'Admin');
check(!is_active(link_between($pdo, $b['P'], $b['printer_port'])), 'linkPortToDevice supersedes the discovered link on the port');
$attrs = json_decode((string) scalar($pdo, 'SELECT attrs FROM topo_edges WHERE id=?', [$id]), true);
check(!isset($attrs['superseded_at']) && $attrs['discovered_via'] === 'manual', 'the device_link is manual and active');
assert_port_uniqueness($pdo, 'after the manual device_link');

// ============================================================================================================
echo "\n=== 4: Accept discovery later revives the superseded link with the same edge id ===\n";
$b = busy_discovered($pdo, $dsn, $user, $password, $R, $U, $PR);
CTopologyPrototype::linkPorts((string) $b['P'], (string) $b['ups']['port']);
$m = link_between($pdo, $b['P'], $b['ups']['port'])['id'];
$r = ingest($dsn, $user, $password, $pdo, 'with the manual link in place');
check(is_active(link_between($pdo, $b['P'], $b['ups']['port'])) && !is_active(link_between($pdo, $b['P'], $b['printer_port'])),
	'ingest leaves the manual link active and the discovered one superseded');
check(observation($pdo, 1002, $PR)['outcome'] === 'shadowed' && contradicted($r) === 1, 'the printer is shadowed, the manual link is contradicted');
CTopologyPrototype::acceptDiscovery((string) $m);
$r = ingest($dsn, $user, $password, $pdo, 'after accepting discovery');
$now = link_between($pdo, $b['P'], $b['printer_port']);
check(is_active($now) && $now['id'] === $b['discovered'], 'the SAME edge id is active again (revived)');
check(link_between($pdo, $b['P'], $b['ups']['port']) === null && contradicted($r) === 0, 'the manual link is gone, nothing is contradicted');
check((int) scalar($pdo, "SELECT COUNT(*) FROM topo_edges WHERE type='physical_link'") === 1, 'exactly one link');

echo "\nAll checks passed.\n";
