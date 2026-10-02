#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * test_topology_model.php — tests of the PHP side of topology-device-level-edge-spec.md: neighbor expansion
 * (CTopologyPrototype::getNeighbors / getLinkedDeviceName) and the three endpoints that create, remove and convert a
 * link by hand (topology.devicelink.create, topology.link.remove, topology.link.convert), run through the real
 * controller classes.
 *
 * The Zabbix PHP layer is loaded against the test database with a stub for the API (the visible hosts) and a
 * controllable web user. The controllers' only permission rule is "not the guest user", like the other topology
 * endpoints; that is what the "without permission" cases check.
 *
 * Usage: test_topology_model.php <PDO DSN> <database user> [password]
 * Expects a THROWAWAY database (tables are dropped and recreated). Never point it at a real Zabbix database.
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
if (!preg_match('/dbname=([A-Za-z0-9_]+)/', $dsn, $m)) {
	fail_test('the DSN needs dbname=');
}
$dbname = $m[1];

$pdo = new PDO($dsn, $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
foreach (['topo_observations', 'topo_edges', 'topo_nodes', 'topo_lld_snapshot', 'host_inventory', 'interface', 'items',
		'proxy', 'hosts'] as $table) {
	$pdo->exec("DROP TABLE IF EXISTS {$table}");
}
$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
$pdo->exec("CREATE TABLE hosts (hostid BIGINT UNSIGNED PRIMARY KEY, host VARCHAR(128) NOT NULL, name VARCHAR(128) NOT NULL DEFAULT '', status INT NOT NULL DEFAULT 0)");
$pdo->exec('CREATE TABLE proxy (proxyid BIGINT UNSIGNED PRIMARY KEY)');
$pdo->exec('CREATE TABLE items (itemid BIGINT UNSIGNED PRIMARY KEY, hostid BIGINT UNSIGNED NOT NULL, status INT NOT NULL DEFAULT 0, topology_role INT NOT NULL DEFAULT 0)');
$pdo->exec(file_get_contents(__DIR__.'/../mysql_schema.sql'));

// ---- the Zabbix PHP layer against the test database ----
$ui = realpath(__DIR__.'/../../../ui');
chdir($ui);
set_include_path($ui);
require_once 'include/classes/core/CAutoloader.php';

/** Stub of the API facade: only the visible hosts matter to the topology classes. */
class API {
	public static array $visible = [];

	public static function Host() {
		return new class {
			public function get($options) {
				return array_map(static fn ($id): array => ['hostid' => (string) $id], API::$visible);
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
$DB = ['TYPE' => ZBX_DB_MYSQL, 'SERVER' => 'localhost', 'PORT' => '0', 'DATABASE' => $dbname, 'USER' => $user,
	'PASSWORD' => $password, 'SCHEMA' => '', 'ENCRYPTION' => false, 'TRANSACTIONS' => 0,
	'TRANSACTION_NO_FAILED_SQLS' => true, 'SELECT_COUNT' => 0, 'EXECUTE_COUNT' => 0];
$backend = new MysqlDbBackend();
$DB['DB'] = $backend->connect($DB['SERVER'], $DB['PORT'], $DB['USER'], $DB['PASSWORD'], $DB['DATABASE'], $DB['SCHEMA']);
if ($backend->getError()) {
	fail_test('cannot connect: '.$backend->getError());
}
$backend->init();

// ---- helpers ----

function node(PDO $pdo, string $type, array $attrs, ?int $device_id = null): int {
	$pdo->prepare('INSERT INTO topo_nodes (type, device_id, attrs, created_at, updated_at) VALUES (?, ?, ?, 0, 0)')
		->execute([$type, $device_id, json_encode($attrs)]);

	return (int) $pdo->lastInsertId();
}

function device(PDO $pdo, string $name): int {
	return node($pdo, 'device', ['sysname' => $name, 'chassis_id' => $name]);
}

function port(PDO $pdo, int $device_id, int $if_index, string $name): int {
	return node($pdo, 'port', ['if_index' => $if_index, 'name' => $name, 'pseudo' => false, 'oper_status' => 'up'], $device_id);
}

function edge(PDO $pdo, string $type, int $src, int $dst, array $attrs): int {
	$pdo->prepare('INSERT INTO topo_edges (type, src_id, dst_id, attrs, created_at) VALUES (?, ?, ?, ?, 0)')
		->execute([$type, $src, $dst, json_encode($attrs)]);

	return (int) $pdo->lastInsertId();
}

function reset_topology(PDO $pdo): void {
	$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
	foreach (['topo_observations', 'topo_edges', 'topo_nodes'] as $table) {
		$pdo->exec("TRUNCATE TABLE {$table}");
	}
	$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
}

function edge_row(PDO $pdo, int $id): ?array {
	$stmt = $pdo->prepare('SELECT id, type, src_id, dst_id, attrs FROM topo_edges WHERE id = ?');
	$stmt->execute([$id]);
	$row = $stmt->fetch(PDO::FETCH_ASSOC);

	return $row ? ['id' => (int) $row['id'], 'type' => $row['type'], 'src' => (int) $row['src_id'],
		'dst' => (int) $row['dst_id']] + json_decode($row['attrs'], true) : null;
}

function edge_count(PDO $pdo): int {
	return (int) $pdo->query('SELECT COUNT(*) FROM topo_edges')->fetchColumn();
}

function login(bool $guest = false): void {
	CWebUser::$data = ['userid' => $guest ? '2' : '1', 'username' => $guest ? ZBX_GUEST_USER : 'Admin', 'type' => USER_TYPE_SUPER_ADMIN];
}

/** Runs a controller class with $input as its JSON body; returns the decoded JSON answer, or 'denied'. */
function call(string $class, array $input) {
	$controller = new $class();
	$set = static function (string $property, $value) use ($controller): void {
		$reflection = new ReflectionProperty(CController::class, $property);
		$reflection->setAccessible(true);
		$reflection->setValue($controller, $value);
	};
	$set('raw_input', $input);
	$set('validate_csrf_token', false);

	try {
		$response = $controller->run();
	}
	catch (CAccessDeniedException) {
		return 'denied';
	}

	return json_decode($response->getData()['main_block'], true);
}

function failed(array|string $answer): bool {
	return is_array($answer) && isset($answer['error']);
}

function neighbor(array $neighbors, int $id): ?array {
	foreach ($neighbors as $n) {
		if ((int) $n['id'] === $id) {
			return $n;
		}
	}

	return null;
}

login();

// ============================================================================================================
echo "\n=== step 3: neighbor expansion includes device_link ===\n";
reset_topology($pdo);
$A = device($pdo, 'Switch A');
$B = device($pdo, 'Switch B');
$C = device($pdo, 'Switch C');
$Ap = port($pdo, $A, 1, 'Gi0/1');
$Bp = port($pdo, $B, 1, 'Gi0/1');
$Cp = port($pdo, $C, 1, 'Gi0/1');
$link = edge($pdo, 'device_link', $Ap, $B, ['discovered_via' => 'lldp', 'far_port_reason' => 'port_unmatched',
	'last_seen' => time(), 'last_seen_src' => time()]);

$from_a = CTopologyPrototype::getNeighbors((string) $A);
$b = neighbor($from_a, $B);
check(count($from_a) === 1 && $b !== null, 'A has only a device_link to B: expanding A shows B');
check($b['link_type'] === 'device_link' && $b['name'] === 'Switch B', 'B is marked as reached by a device-level link');
check($b['local_port'] === 'Gi0/1' && (int) $b['local_port_id'] === $Ap && $b['remote_port'] === null && $b['remote_port_id'] === null,
	'the local port is known, the remote port is not');
check($b['discovered_via'] === 'lldp' && $b['stale'] === false, 'provenance and staleness come from the link');
$from_b = CTopologyPrototype::getNeighbors((string) $B);
$a = neighbor($from_b, $A);
check(count($from_b) === 1 && $a !== null && $a['link_type'] === 'device_link', 'expanding B (the far end) shows A');
check($a['local_port'] === null && (int) $a['remote_port_id'] === $Ap, 'seen from B, A\'s port is the remote one');
check(CTopologyPrototype::getNeighbors((string) $C) === [], 'a device with no link has no neighbors');

// a physical_link to the same neighbor keeps port-level precision
$link_b = edge($pdo, 'physical_link', min($Ap, $Bp), max($Ap, $Bp), ['discovered_via' => 'lldp', 'last_seen' => time()]);
$from_a = CTopologyPrototype::getNeighbors((string) $A);
check(count($from_a) === 1 && $from_a[0]['link_type'] === 'physical_link' && (int) $from_a[0]['remote_port_id'] === $Bp,
	'a neighbor reached both ways is reported once, at port level');

// a superseded device_link is stale and gives way to nothing active
$pdo->exec('DELETE FROM topo_edges WHERE id = '.$link_b);
$pdo->prepare('UPDATE topo_edges SET attrs = ? WHERE id = ?')->execute([json_encode(['discovered_via' => 'lldp',
	'far_port_reason' => 'port_unmatched', 'last_seen' => time(), 'superseded_at' => time() - 5]), $link]);
$from_a = CTopologyPrototype::getNeighbors((string) $A);
check(count($from_a) === 1 && $from_a[0]['stale'] === true && $from_a[0]['superseded_at'] !== null, 'a superseded device_link is shown as stale');

$method = new ReflectionMethod(CTopologyPrototype::class, 'getLinkedDeviceName');
$method->setAccessible(true);
$pdo->prepare('UPDATE topo_edges SET attrs = ? WHERE id = ?')->execute([json_encode(['discovered_via' => 'lldp',
	'far_port_reason' => 'port_unmatched', 'last_seen' => time()]), $link]);
check($method->invoke(null, (string) $Ap) === 'Switch B', 'getLinkedDeviceName: a port with a device_link names the far Device');
check($method->invoke(null, (string) $Cp) === null, 'getLinkedDeviceName: a port with no link has none');
edge($pdo, 'physical_link', min($Bp, $Cp), max($Bp, $Cp), ['discovered_via' => 'lldp', 'last_seen' => time()]);
check($method->invoke(null, (string) $Cp) === 'Switch B', 'getLinkedDeviceName: a physical_link still works');

// ============================================================================================================
echo "\n=== step 5: topology.devicelink.create ===\n";
reset_topology($pdo);
$A = device($pdo, 'Switch A');
$B = device($pdo, 'Switch B');
$Ap1 = port($pdo, $A, 1, 'Gi0/1');
$Ap2 = port($pdo, $A, 2, 'Gi0/2');
$Ap3 = port($pdo, $A, 3, 'Gi0/3');
$Bp1 = port($pdo, $B, 1, 'Gi0/1');
$class = CControllerTopologyDeviceLinkCreate::class;

$r = call($class, ['port_id' => (string) $Ap1, 'device_id' => (string) $B]);
check(!failed($r) && ($r['success'] ?? false) === true && isset($r['edge_id']), 'success: the link is created');
$e = edge_row($pdo, (int) $r['edge_id']);
check($e['type'] === 'device_link' && $e['src'] === $Ap1 && $e['dst'] === $B && $e['discovered_via'] === 'manual'
	&& $e['far_port_reason'] === 'manual' && $e['created_by'] === 'Admin' && !isset($e['far_port_hint']),
	'a manual device_link from the port to the device, reason manual, created by the user, no hint');
$again = call($class, ['port_id' => (string) $Ap1, 'device_id' => (string) $B]);
check(failed($again), 'the same port again: it already has a link');
check(edge_count($pdo) === 1, 'nothing was added');

check(failed(call($class, ['port_id' => (string) $B, 'device_id' => (string) $B])), 'wrong node type: a device as the port');
check(failed(call($class, ['port_id' => (string) $Ap2, 'device_id' => (string) $Bp1])), 'wrong node type: a port as the device');
check(failed(call($class, ['port_id' => (string) $Ap2, 'device_id' => '999999'])), 'a device that does not exist');
check(failed(call($class, ['port_id' => '999999', 'device_id' => (string) $B])), 'a port that does not exist');
check(failed(call($class, ['port_id' => (string) $Ap2, 'device_id' => (string) $A])), 'a port cannot be linked to its own device');

edge($pdo, 'physical_link', min($Ap2, $Bp1), max($Ap2, $Bp1), ['discovered_via' => 'lldp', 'last_seen' => time()]);
check(failed(call($class, ['port_id' => (string) $Ap2, 'device_id' => (string) $B])), 'the port has an active physical_link: rejected');
check(failed(call($class, ['port_id' => (string) $Bp1, 'device_id' => (string) $A])), 'the far end of an active physical_link counts too');
$pdo->prepare("UPDATE topo_edges SET attrs = ? WHERE type = 'physical_link'")->execute([json_encode(['discovered_via' => 'lldp',
	'last_seen' => time(), 'superseded_at' => time()])]);
check(!failed(call($class, ['port_id' => (string) $Ap2, 'device_id' => (string) $B])), 'a superseded link does not occupy the port');

login(true);
$before = edge_count($pdo);
check(call($class, ['port_id' => (string) $Ap3, 'device_id' => (string) $B]) === 'denied', 'the guest user is denied');
check(edge_count($pdo) === $before, 'and nothing was written');
login();

// ============================================================================================================
echo "\n=== step 5: topology.link.remove ===\n";
reset_topology($pdo);
$A = device($pdo, 'Switch A');
$B = device($pdo, 'Switch B');
$Ap1 = port($pdo, $A, 1, 'Gi0/1');
$Ap2 = port($pdo, $A, 2, 'Gi0/2');
$Bp1 = port($pdo, $B, 1, 'Gi0/1');
$Bp2 = port($pdo, $B, 2, 'Gi0/2');
$class = CControllerTopologyLinkRemove::class;

$manual = edge($pdo, 'device_link', $Ap1, $B, ['discovered_via' => 'manual', 'far_port_reason' => 'manual']);
$discovered = edge($pdo, 'device_link', $Ap2, $B, ['discovered_via' => 'lldp', 'far_port_reason' => 'port_unmatched']);
$physical = edge($pdo, 'physical_link', min($Bp1, $Bp2), max($Bp1, $Bp2), ['discovered_via' => 'lldp']);
$r = call($class, ['id' => (string) $manual]);
check(!failed($r) && edge_row($pdo, $manual) === null, 'success: a manual device_link is removed');
$r = call($class, ['id' => (string) $discovered]);
check(!failed($r) && edge_row($pdo, $discovered) === null, 'success: a discovered device_link is removed too');
check(!failed(call($class, ['id' => (string) $physical])) && edge_row($pdo, $physical) === null, 'success: a physical_link is removed');
check(failed(call($class, ['id' => (string) $manual])), 'removing it again: no such link');
check(failed(call($class, ['id' => (string) $A])), 'wrong node type: a device id is not a link');
check(failed(call($class, ['id' => (string) $Ap1])), 'wrong node type: a port id is not a link');
check((int) $pdo->query('SELECT COUNT(*) FROM topo_nodes')->fetchColumn() === 6, 'no node was removed by any of it');
$kept = edge($pdo, 'device_link', $Ap1, $B, ['discovered_via' => 'manual', 'far_port_reason' => 'manual']);
login(true);
check(call($class, ['id' => (string) $kept]) === 'denied' && edge_row($pdo, $kept) !== null, 'the guest user is denied and the link stays');
login();
echo "  note: \"the port already has an active link\" does not apply to removing a link\n";

// ============================================================================================================
echo "\n=== step 5: topology.link.convert ===\n";
reset_topology($pdo);
$A = device($pdo, 'Switch A');
$B = device($pdo, 'Switch B');
$E = device($pdo, 'Switch E');
$Ap1 = port($pdo, $A, 1, 'Gi0/1');
$Ap2 = port($pdo, $A, 2, 'Gi0/2');
$Bp1 = port($pdo, $B, 1, 'Gi0/1');
$Bp2 = port($pdo, $B, 2, 'Gi0/2');
$Ep1 = port($pdo, $E, 1, 'Gi0/9');
$class = CControllerTopologyLinkConvert::class;

// device_link -> physical_link
$dl = edge($pdo, 'device_link', $Ap1, $B, ['discovered_via' => 'manual', 'far_port_reason' => 'manual', 'created_by' => 'Admin']);
$r = call($class, ['id' => (string) $dl, 'far_port_id' => (string) $Bp1]);
$e = edge_row($pdo, $dl);
check(!failed($r) && $e['type'] === 'physical_link' && [$e['src'], $e['dst']] === [min($Ap1, $Bp1), max($Ap1, $Bp1)]
	&& $e['discovered_via'] === 'manual' && !isset($e['far_port_reason']), 'success: a manual device_link becomes a manual physical_link, same id');
// physical_link -> device_link, keeping one of its ports
$r = call($class, ['id' => (string) $dl, 'keep_port_id' => (string) $Bp1]);
$e = edge_row($pdo, $dl);
check(!failed($r) && $e['type'] === 'device_link' && $e['src'] === $Bp1 && $e['dst'] === $A && $e['far_port_reason'] === 'manual',
	'success: and back, keeping the chosen port, same id');

// wrong node types
$dl2 = edge($pdo, 'device_link', $Ap2, $B, ['discovered_via' => 'manual', 'far_port_reason' => 'manual']);
check(failed(call($class, ['id' => (string) $dl2, 'far_port_id' => (string) $A])), 'wrong node type: a device as the far port');
check(failed(call($class, ['id' => (string) $dl2, 'far_port_id' => (string) $Ep1])), 'wrong node type: a port of another device');
check(failed(call($class, ['id' => (string) $dl2, 'far_port_id' => (string) $Ap1])), 'wrong node type: a port of the local device');
check(failed(call($class, ['id' => (string) $dl2])), 'a device_link needs a far port');
check(failed(call($class, ['id' => (string) $A, 'far_port_id' => (string) $Bp2])), 'wrong node type: a device id is not a link');
$pl = edge($pdo, 'physical_link', min($Ap1, $Ep1), max($Ap1, $Ep1), ['discovered_via' => 'manual']);
check(failed(call($class, ['id' => (string) $pl, 'keep_port_id' => (string) $Bp2])), 'a port that is not an end of the link cannot stay');
check(failed(call($class, ['id' => (string) $pl])), 'a physical_link needs the port to keep');
check(edge_row($pdo, $dl2)['type'] === 'device_link' && edge_row($pdo, $pl)['type'] === 'physical_link', 'nothing changed');

// the far port already has an active link
$busy = edge($pdo, 'physical_link', min($Bp2, $Ep1), max($Bp2, $Ep1), ['discovered_via' => 'lldp', 'last_seen' => time()]);
check(failed(call($class, ['id' => (string) $dl2, 'far_port_id' => (string) $Bp2])), 'the far port has an active physical_link: rejected');
check(edge_row($pdo, $dl2)['type'] === 'device_link', 'and the link stays a device_link');
$pdo->prepare('UPDATE topo_edges SET attrs = ? WHERE id = ?')->execute([json_encode(['discovered_via' => 'lldp', 'superseded_at' => time()]), $busy]);
check(!failed(call($class, ['id' => (string) $dl2, 'far_port_id' => (string) $Bp2])), 'a superseded link does not occupy it');
$dl3 = edge($pdo, 'device_link', $Ap1, $B, ['discovered_via' => 'manual', 'far_port_reason' => 'manual']);

// a discovered link is never converted by hand
$disc = edge($pdo, 'device_link', $Bp1, $E, ['discovered_via' => 'lldp', 'far_port_reason' => 'port_unmatched']);
check(failed(call($class, ['id' => (string) $disc, 'far_port_id' => (string) $Ep1])), 'a discovered link is not converted by hand');

login(true);
$snapshot = $pdo->query('SELECT id, type, src_id, dst_id, attrs FROM topo_edges ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
check(call($class, ['id' => (string) $dl3, 'far_port_id' => (string) $Bp1]) === 'denied', 'the guest user is denied');
check($pdo->query('SELECT id, type, src_id, dst_id, attrs FROM topo_edges ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) === $snapshot, 'and nothing changed');
login();

echo "\nAll topology model tests passed.\n";
