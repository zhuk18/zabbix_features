#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * test_topology_model.php — tests of the PHP side of topology-1-model.md (device-level links): neighbor expansion
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
foreach (['topo_observations', 'topo_edges', 'topo_nodes', 'topo_lld_snapshot', 'host_inventory', 'interface', 'items', 'globalmacro',
		'proxy', 'hosts'] as $table) {
	$pdo->exec("DROP TABLE IF EXISTS {$table}");
}
$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
$pdo->exec("CREATE TABLE hosts (hostid BIGINT UNSIGNED PRIMARY KEY, host VARCHAR(128) NOT NULL, name VARCHAR(128) NOT NULL DEFAULT '', status INT NOT NULL DEFAULT 0)");
$pdo->exec('CREATE TABLE proxy (proxyid BIGINT UNSIGNED PRIMARY KEY)');
$pdo->exec('CREATE TABLE globalmacro (globalmacroid BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT, macro VARCHAR(255) NOT NULL DEFAULT \'\', value VARCHAR(2048) NOT NULL DEFAULT \'\')');
$pdo->exec('CREATE TABLE items (itemid BIGINT UNSIGNED PRIMARY KEY, hostid BIGINT UNSIGNED NOT NULL, name VARCHAR(255) NOT NULL DEFAULT \'\', status INT NOT NULL DEFAULT 0, topology_role INT NOT NULL DEFAULT 0, type INT NOT NULL DEFAULT 0)');
$pdo->exec(file_get_contents(__DIR__.'/../mysql_schema.sql'));
$pdo->exec('CREATE TABLE topo_lld_snapshot (itemid BIGINT UNSIGNED PRIMARY KEY, hostid BIGINT UNSIGNED NOT NULL, role INT NOT NULL DEFAULT 0,'.
	' clock INT NOT NULL DEFAULT 0, rows_hash VARCHAR(64) NOT NULL DEFAULT \'\', rows_json LONGTEXT NOT NULL, rows_total INT NOT NULL DEFAULT 0,'.
	' rows_valid INT NOT NULL DEFAULT 0)');

// ---- the Zabbix PHP layer against the test database ----
$ui = realpath(__DIR__.'/../../../ui');
chdir($ui);
set_include_path($ui);
require_once 'include/classes/core/CAutoloader.php';

/** Stub of the API facade: only the visible hosts matter to the topology classes. */
class API {
	public static array $visible = [];
	/** @var array[] active triggers: ['priority' => int, 'itemids' => [..]] */
	public static array $triggers = [];
	public static int $trigger_calls = 0;

	public static function Trigger() {
		return new class {
			public function get($options) {
				API::$trigger_calls++;
				$out = [];
				foreach (API::$triggers as $trigger) {
					$hit = array_values(array_intersect($trigger['itemids'], $options['itemids']));
					if ($hit) {
						$out[] = ['priority' => (string) $trigger['priority'],
							'items' => array_map(static fn ($id): array => ['itemid' => (string) $id], $hit)];
					}
				}
				return $out;
			}
		};
	}

	/** @var array[] inventory per hostid for the Host pull: [hostid => ['macaddress_a' => .., 'macaddress_b' => ..]] */
	public static array $inventory = [];

	public static function Host() {
		return new class {
			public function get($options) {
				if (isset($options['selectInventory'])) {
					return array_map(static fn ($id): array => ['hostid' => (string) $id, 'inventory' => API::$inventory[$id]],
						array_keys(API::$inventory));
				}
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
$C = device($pdo, 'Switch C');
$Cp1 = port($pdo, $C, 1, 'Gi0/1');
$Cp2 = port($pdo, $C, 2, 'Gi0/2');
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

// topology-1-model.md "Manual links": a port with an active DISCOVERED link takes the manual link and the discovered
// one is superseded at once; only an active MANUAL link rejects (test_manual_link_busy_port.php covers this in depth,
// including a discovered link to the same Device, which the call leaves alone).
$disc = edge($pdo, 'physical_link', min($Ap2, $Cp1), max($Ap2, $Cp1), ['discovered_via' => 'lldp', 'last_seen' => time()]);
$r = call($class, ['port_id' => (string) $Ap2, 'device_id' => (string) $B]);
check(!failed($r) && isset($r['edge_id']), 'the port has an active discovered physical_link: the call succeeds');
check(isset(edge_row($pdo, $disc)['superseded_at']), 'and the discovered link is superseded');
check(failed(call($class, ['port_id' => (string) $Ap2, 'device_id' => (string) $B])), 'the port now has an active manual link: rejected');
$pdo->prepare('DELETE FROM topo_edges WHERE id = ?')->execute([(int) $r['edge_id']]);
check(!failed(call($class, ['port_id' => (string) $Ap2, 'device_id' => (string) $B])), 'a superseded link does not occupy the port');
$disc3 = edge($pdo, 'physical_link', min($Cp2, $Bp1), max($Cp2, $Bp1), ['discovered_via' => 'lldp', 'last_seen' => time()]);
$r = call($class, ['port_id' => (string) $Bp1, 'device_id' => (string) $A]);
check(!failed($r) && isset(edge_row($pdo, $disc3)['superseded_at']), 'the far end of an active discovered physical_link counts too: superseded');

login(true);
$before = edge_count($pdo);
check(call($class, ['port_id' => (string) $Ap3, 'device_id' => (string) $B]) === 'denied', 'the guest user is denied');
check(edge_count($pdo) === $before, 'and nothing was written');
login();

// ============================================================================================================
echo "\n=== step 5: topology.ports.link returns the edge id (topology-1-model.md \"Manual links\": creation) ===\n";
reset_topology($pdo);
$A = device($pdo, 'Switch A');
$B = device($pdo, 'Switch B');
$Ap1 = port($pdo, $A, 1, 'Gi0/1');
$Ap2 = port($pdo, $A, 2, 'Gi0/2');
$Bp1 = port($pdo, $B, 1, 'Gi0/1');
$Bp2 = port($pdo, $B, 2, 'Gi0/2');
$class = CControllerTopologyPortsLink::class;

$r = call($class, ['id' => (string) $Ap1, 'dst_id' => (string) $Bp1]);
$new = $pdo->query("SELECT id FROM topo_edges WHERE type='physical_link' AND src_id=".min($Ap1, $Bp1).' AND dst_id='.max($Ap1, $Bp1))->fetchColumn();
check(!failed($r) && ($r['success'] ?? false) === true && isset($r['edge_id']) && (int) $r['edge_id'] === (int) $new,
	'a new link: success and the id of the created link');
check(edge_row($pdo, (int) $r['edge_id'])['discovered_via'] === 'manual', 'it is the manual link');

$disc = edge($pdo, 'physical_link', min($Ap2, $Bp2), max($Ap2, $Bp2), ['discovered_via' => 'lldp', 'last_seen' => time()]);
$edges = edge_count($pdo);
$r = call($class, ['id' => (string) $Ap2, 'dst_id' => (string) $Bp2]);
check(!failed($r) && (int) ($r['edge_id'] ?? 0) === $disc, 'same ends as a discovered link: its id comes back');
check(edge_count($pdo) === $edges && !isset(edge_row($pdo, $disc)['superseded_at']) && edge_row($pdo, $disc)['discovered_via'] === 'lldp',
	'and nothing changed');
$r = call(CControllerTopologyDeviceLinkCreate::class, ['port_id' => (string) $Ap2, 'device_id' => (string) $B]);
check(!failed($r) && (int) ($r['edge_id'] ?? 0) === $disc, 'the device-link endpoint returns the discovered physical_link to that Device too');
check(failed(call($class, ['id' => (string) $Ap1, 'dst_id' => (string) $Bp2])), 'a busy port still fails, with no edge_id');

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

// ============================================================================================================
echo "\n=== step 6: third-party shadowing (topology-1-model.md \"Contradiction\") ===\n";
reset_topology($pdo);
$pdo->exec('DELETE FROM items');
$pdo->exec('DELETE FROM hosts');
$Rd = device($pdo, 'Router1');
$Sd = device($pdo, 'Switch2');
$Ud = device($pdo, 'UPS1');
$Pd = device($pdo, 'Printer1');
$Rp = port($pdo, $Rd, 1, 'Gi0/1');
$Sp = port($pdo, $Sd, 1, 'Gi0/1');
$Up = port($pdo, $Ud, 1, 'eth0');
$Pp = port($pdo, $Pd, 1, 'Gi0/1');
foreach ([[101, 'Router1', 1002], [103, 'Switch2', 3002]] as [$hostid, $hostname, $itemid]) {
	$pdo->prepare('INSERT INTO hosts (hostid, host, name, status) VALUES (?, ?, ?, 0)')->execute([$hostid, $hostname, $hostname]);
	$pdo->prepare('INSERT INTO items (itemid, hostid, status, topology_role, type) VALUES (?, ?, 0, 2, 0)')->execute([$itemid, $hostid]);
	$pdo->prepare("INSERT INTO topo_lld_snapshot (itemid, hostid, role, clock, rows_hash, rows_json) VALUES (?, ?, 2, 1, 'h', '[]')")
		->execute([$itemid, $hostid]);
}
API::$visible = [101, 103];
// M = Router1:Gi0/1 <-> UPS1:eth0 (manual). Router1 sees the printer on Gi0/1; Switch2 (not an end of M) sees UPS1:eth0 on
// its own Gi0/1. Both discovered links are superseded by M; UPS1 does not report (no LLDP).
$M = edge($pdo, 'physical_link', min($Rp, $Up), max($Rp, $Up), ['discovered_via' => 'manual', 'last_seen' => time()]);
$e_rp = edge($pdo, 'physical_link', min($Rp, $Pp), max($Rp, $Pp), ['discovered_via' => 'lldp', 'last_seen' => time() - 100, 'superseded_at' => time() - 50]);
$e_su = edge($pdo, 'physical_link', min($Sp, $Up), max($Sp, $Up), ['discovered_via' => 'lldp', 'last_seen' => time() - 100, 'superseded_at' => time() - 50]);
$observe = $pdo->prepare("INSERT INTO topo_observations (itemid, local_port_id, remote_key, remote_attrs, outcome, edge_id, device_id, first_seen, last_seen)".
	" VALUES (?, ?, ?, ?, 'shadowed', ?, ?, 1, 1)");
$observe->execute([1002, $Rp, 'chassis:-:Printer1', '{"rem_port":"Gi0/1"}', $M, $Pd]);
$observe->execute([3002, $Sp, 'chassis:-:UPS1', '{"rem_port":"eth0"}', $M, $Ud]);

$c = CTopologyPrototype::getContradictions();
check(count($c) === 1 && $c[0]['contradicted'], 'M is contradicted');
$by_reporter = [];
foreach ($c[0]['shadows'] as $shadow) {
	$by_reporter[$shadow['reporter']['name']] = $shadow;
}
$third = $by_reporter['Switch2'];
check($third['third_party'] === true && (int) $third['hidden_device_id'] === $Sd, 'Switch2\'s shadow is third party; the hidden neighbor is Switch2');
check((int) $third['remote_device_id'] === $Ud && $third['remote_name'] === 'UPS1', 'it saw UPS1, an end of M');
check((int) $third['local_device_id'] === $Sd, 'and it comes from Switch2\'s own port (the ghost starts there)');
check($third['remote_key'] === 'port:chassis:-:Switch2:1', 'its acknowledgment key is the natural key of the reporter\'s local port');
$near = $by_reporter['Router1'];
check($near['third_party'] === false && (int) $near['hidden_device_id'] === $Pd && $near['remote_key'] === 'chassis:-:Printer1',
	'Router1\'s shadow is the near end: the hidden neighbor is Printer1, remote_key as received');
check(!in_array((string) $Ud, array_map(static fn (array $x): string => (string) $x['hidden_device_id'], $c[0]['shadows']), true),
	'UPS1 is not a hidden neighbor');

echo "\n  -- the superseded edges under the ghost lines are not drawn\n";
$drawn = static function () use ($e_rp, $e_su): array {
	$ids = [];
	foreach (CTopologyPrototype::getRelations() as $relation) {
		if (($relation['type'] ?? '') === 'physical_link') {
			$ids[(int) $relation['edge_id']] = true;
		}
	}
	return $ids;
};
$ids = $drawn();
check(isset($ids[$M]) && !isset($ids[$e_rp]) && !isset($ids[$e_su]), 'M is drawn; neither superseded edge is');
$neighbors = CTopologyPrototype::getNeighbors((string) $Ud);
check(neighbor($neighbors, $Sd) === null, 'the neighbor expansion of UPS1 does not list Switch2 through the hidden superseded edge');

echo "\n  -- keep manual for Switch2 only\n";
CTopologyPrototype::keepManual((string) $M, (string) $Sd, 'Admin');
$ack = json_decode((string) $pdo->query("SELECT attrs FROM topo_edges WHERE id={$M}")->fetchColumn(), true)['shadow_ack'];
check(count($ack) === 1 && (int) $ack[0]['device_id'] === $Sd && $ack[0]['remote_key'] === 'port:chassis:-:Switch2:1',
	'one acknowledgment, keyed by the reporter Device, remote_key = the reporter\'s port');
$c = CTopologyPrototype::getContradictions();
check($c[0]['contradicted'] === true, 'M is still contradicted (Printer1 is not acknowledged)');
$ids = $drawn();
check(isset($ids[$e_su]) && !isset($ids[$e_rp]), 'the Switch2 - UPS1 edge renders again; the Router1 - Printer1 one stays hidden');
check(neighbor(CTopologyPrototype::getNeighbors((string) $Ud), $Sd) !== null, 'and UPS1\'s neighbor expansion lists Switch2 again');
$rows = CTopologyPrototype::getObservations(['shadowed']);
$ack_by_reporter = [];
foreach ($rows as $row) {
	$ack_by_reporter[$row['reporter']['name']] = $row['acknowledged'];
}
ksort($ack_by_reporter);
check($ack_by_reporter === ['Router1' => false, 'Switch2' => true], '/topo/observations: acknowledged is decided by the hidden neighbor');
$contradicted_rows = CTopologyPrototype::getObservations(null, null, 500, true);
check(count($contradicted_rows) === 1 && $contradicted_rows[0]['reporter']['name'] === 'Router1', 'contradicted=1 lists only Router1\'s row');

echo "\n  -- keep manual for Printer1 too\n";
CTopologyPrototype::keepManual((string) $M, (string) $Pd, 'Admin');
check(CTopologyPrototype::getContradictions()[0]['contradicted'] === false, 'no longer contradicted');
$ids = $drawn();
check(isset($ids[$e_su]) && isset($ids[$e_rp]), 'both superseded edges render as usual');

echo "\n  -- a far end of M seen at another precision is not a hidden neighbor\n";
$pdo->exec('DELETE FROM topo_observations');
$observe->execute([1002, $Rp, 'chassis:-:UPS1', '{"rem_port":"eth0"}', $M, $Ud]);       // Router1 sees UPS1 on Gi0/1: M's own far end
$shadows = CTopologyPrototype::getContradictions()[0]['shadows'] ?? [];
check($shadows === [], 'no shadow for it');

// ============================================================================================================
echo "\n=== step 7: /neighbors carries the per-link fields (topology-1-model.md \"API\") ===\n";
reset_topology($pdo);
$pdo->exec('DELETE FROM topo_lld_snapshot');
$pdo->exec('DELETE FROM items');
$pdo->exec('DELETE FROM hosts');
$Rd = device($pdo, 'Router1');
$Ud = device($pdo, 'UPS1');
$Pd = device($pdo, 'Printer1');
$Ad = device($pdo, 'AP1');
$Rp1 = port($pdo, $Rd, 1, 'Gi0/1');
$Rp2 = port($pdo, $Rd, 2, 'Gi0/2');
$Rp3 = port($pdo, $Rd, 3, 'Gi0/3');
$Up = port($pdo, $Ud, 1, 'eth0');
$Pp = port($pdo, $Pd, 1, 'Gi0/1');
$Ap = port($pdo, $Ad, 1, 'eth0');
$pdo->prepare('INSERT INTO hosts (hostid, host, name, status) VALUES (101, ?, ?, 0)')->execute(['Router1', 'Router1']);
$pdo->prepare('INSERT INTO items (itemid, hostid, status, topology_role, type) VALUES (1002, 101, 0, 2, 0)')->execute();
$pdo->prepare("INSERT INTO topo_lld_snapshot (itemid, hostid, role, clock, rows_hash, rows_json) VALUES (1002, 101, 2, 1, 'h', '[]')")->execute();
API::$visible = [101];
$now = time();
// M: manual Gi0/1 <-> UPS1:eth0, shadowed by Printer1; Gi0/2 <-> Printer1 discovered, seen at device level only by one
// observation; Gi0/3 -> AP1 a device_link with a reason and a hint.
$M = edge($pdo, 'physical_link', min($Rp1, $Up), max($Rp1, $Up), ['discovered_via' => 'manual', 'last_seen' => $now]);
$L = edge($pdo, 'physical_link', min($Rp2, $Pp), max($Rp2, $Pp), ['discovered_via' => 'lldp', 'last_seen' => $now - 10,
	'last_seen_port' => $now - 500]);
$D = edge($pdo, 'device_link', $Rp3, $Ad, ['discovered_via' => 'lldp', 'far_port_reason' => 'port_unmatched',
	'far_port_hint' => ['kind' => 'port_id', 'value' => 'eth0'], 'last_seen' => $now - 20]);
$observe = $pdo->prepare("INSERT INTO topo_observations (itemid, local_port_id, remote_key, remote_attrs, outcome, edge_id, device_id, first_seen, last_seen, precision_lower)".
	" VALUES (1002, ?, ?, '{}', ?, ?, ?, 1, 1, ?)");
$observe->execute([$Rp1, 'chassis:-:Printer1', 'shadowed', $M, $Pd, 0]);
$observe->execute([$Rp2, 'chassis:-:Printer1b', 'applied', $L, $Pd, 1]);

$n = CTopologyPrototype::getNeighbors((string) $Rd);
$u = neighbor($n, $Ud);
$p = neighbor($n, $Pd);
$a = neighbor($n, $Ad);
check(count($n) === 3 && $u !== null && $p !== null && $a !== null, 'Router1 has three neighbors');
check((int) $u['edge_id'] === $M && $u['contradiction'] === 'contradicted', 'the manual link to UPS1 is contradicted');
check($p['contradiction'] === null && $a['contradiction'] === null, 'a discovered link has no contradiction state');
check($u['precision_lower'] === false && $p['precision_lower'] === true, 'precision_lower comes from the observations of the shown link');
check($p['last_seen'] === $now - 10 && $p['last_seen_port'] === $now - 500, 'last_seen and last_seen_port are the link\'s own');
check($u['last_seen_port'] === $now && $u['far_port_reason'] === null && $u['far_port_hint'] === null,
	'last_seen_port falls back to last_seen; a physical_link has no far_port_reason or hint');
check($a['link_type'] === 'device_link' && $a['far_port_reason'] === 'port_unmatched' && $a['far_port_hint'] === ['kind' => 'port_id', 'value' => 'eth0'],
	'a device_link neighbor carries far_port_reason and the hint');
check(array_key_exists('superseded_at', $u) && $u['superseded_at'] === null, 'superseded_at is present (null while active)');
CTopologyPrototype::keepManual((string) $M, (string) $Pd, 'Admin');
$u = neighbor(CTopologyPrototype::getNeighbors((string) $Rd), $Ud);
check($u['contradiction'] === 'acknowledged', 'after Keep manual the state is acknowledged');
CTopologyPrototype::revokeAcknowledgment((string) $M, (string) $Pd);
$u = neighbor(CTopologyPrototype::getNeighbors((string) $Rd), $Ud);
check($u['contradiction'] === 'contradicted', 'after Revoke it is contradicted again');

// ============================================================================================================
echo "\n=== step 8: the freshness threshold is the global macro (topology-1-model.md \"Freshness threshold\") ===\n";
reset_topology($pdo);
$A = device($pdo, 'Switch A');
$B = device($pdo, 'Switch B');
$Ap = port($pdo, $A, 1, 'Gi0/1');
$Bp = port($pdo, $B, 1, 'Gi0/1');
edge($pdo, 'physical_link', min($Ap, $Bp), max($Ap, $Bp), ['discovered_via' => 'lldp', 'last_seen' => time() - 100]);
$stale = static function () use ($A, $B): bool {
	CTopologyPrototype::reloadFreshness();
	return neighbor(CTopologyPrototype::getNeighbors((string) $A), $B)['stale'];
};
$set_macro = static function (?string $value) use ($pdo): void {
	$pdo->exec('DELETE FROM globalmacro');
	if ($value !== null) {
		$pdo->prepare('INSERT INTO globalmacro (macro, value) VALUES (?, ?)')->execute(['{$TOPOLOGY.LINK.FRESH.SECONDS}', $value]);
	}
};
$warnings = static fn (): int => count(array_filter(CMessageHelper::getMessages(), static fn (array $m): bool => $m['type'] === 'warning'));
$last_warning = static function (): string {
	$all = array_values(array_filter(CMessageHelper::getMessages(), static fn (array $m): bool => $m['type'] === 'warning'));
	return $all[count($all) - 1]['message'];
};
$set_macro(null);
check($stale() === false && $warnings() === 0, 'no macro: the default (7 days), no warning; a link seen 100 s ago is fresh');
$set_macro('60');
check($stale() === true, 'macro 60: the same link is stale');
$set_macro('3600');
check($stale() === false, 'macro 3600: fresh again');
$set_macro('59');
check($stale() === false && $warnings() === 1, 'macro 59 (below the minimum): the default, one warning');
$set_macro('1h');
check($stale() === false && $warnings() === 2, 'macro "1h" (not digits): the default, one more warning');
$message = $last_warning();
check(str_contains($message, '{$TOPOLOGY.LINK.FRESH.SECONDS}') && str_contains($message, '"1h"'), 'the warning names the macro and the rejected text');
$set_macro('<b>x</b>');
$stale();
$message = $last_warning();
check(!str_contains($message, '<b>') && str_contains($message, '&lt;b&gt;'), 'the rejected text is escaped');
$set_macro('60');
$stale();
check($warnings() === 3, 'a valid value adds no warning');
$set_macro(null);
CTopologyPrototype::reloadFreshness();

// ============================================================================================================
echo "\n=== step 9: /neighbors carries the link severity (topology-1-model.md \"Link severity (derived)\"), batched ===\n";
reset_topology($pdo);
$R = device($pdo, 'Router1');
$names = ['Switch A', 'Switch B', 'Switch C', 'Switch D'];
$far = [];
$near_ports = [];
foreach ($names as $k => $name) {
	$far[$name] = device($pdo, $name);
	$near_ports[$name] = node($pdo, 'port', ['if_index' => 10 + $k, 'name' => 'Gi0/'.(10 + $k), 'pseudo' => false, 'oper_status' => 'up',
		'zabbix_itemids' => [1000 + $k]], $R);
	$far_port = node($pdo, 'port', ['if_index' => 1, 'name' => 'Gi0/1', 'pseudo' => false, 'oper_status' => 'up',
		'zabbix_itemids' => [2000 + $k]], $far[$name]);
	edge($pdo, 'physical_link', min($near_ports[$name], $far_port), max($near_ports[$name], $far_port),
		['discovered_via' => 'lldp', 'last_seen' => time()]);
}
// Switch A: a warning (2) on the near port's item; Switch B: a high (4) on the far port's item and an average (3) on the near one;
// Switch C: a trigger that is not active is not in the stub; Switch D: no items with triggers.
API::$triggers = [['priority' => 2, 'itemids' => [1000]], ['priority' => 4, 'itemids' => [2001]], ['priority' => 3, 'itemids' => [1001]]];
API::$trigger_calls = 0;
$n = CTopologyPrototype::getNeighbors((string) $R);
check(API::$trigger_calls === 1, 'four neighbors, ONE trigger lookup (no query per neighbor)');
check(neighbor($n, $far['Switch A'])['severity'] === 2, 'near-port trigger: severity 2');
check(neighbor($n, $far['Switch B'])['severity'] === 4, 'the highest of both end ports: severity 4');
check(neighbor($n, $far['Switch C'])['severity'] === null && neighbor($n, $far['Switch D'])['severity'] === null, 'no active trigger: severity null');
check(neighbor($n, $far['Switch C'])['link_type'] === 'physical_link', 'oper_status is untouched by it (a separate signal): the link is still reported as up');
check(neighbor($n, $far['Switch A'])['local_status'] === 'up', 'local_status stays the port status, not the severity');
API::$triggers = [];
check(neighbor(CTopologyPrototype::getNeighbors((string) $R), $far['Switch A'])['severity'] === null, 'a resolved trigger: severity null again');

// ============================================================================================================
echo "\n=== step 10: the Host pull — a shared MAC matches nothing (topology-1-model.md \"Automatic MAC match\", \"Shared identifier\") ===\n";
reset_topology($pdo);
$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
$D1 = device($pdo, 'Dev 1');
$D2 = device($pdo, 'Dev 2');
$D3 = device($pdo, 'Dev 3');
node($pdo, 'port', ['if_index' => 1, 'name' => 'p', 'pseudo' => false, 'mac' => 'c0:ff:ee:00:00:01'], $D1);
node($pdo, 'port', ['if_index' => 1, 'name' => 'p', 'pseudo' => false, 'mac' => 'c0:ff:ee:00:00:02'], $D2);
node($pdo, 'port', ['if_index' => 1, 'name' => 'p', 'pseudo' => false, 'mac' => 'c0:ff:ee:00:00:02'], $D3);
$represented = static fn (): int => (int) $pdo->query('SELECT COUNT(*) FROM topo_nodes WHERE represented_by_node_id IS NOT NULL')->fetchColumn();
login();
API::$visible = [301, 302, 303];
foreach ([301, 302, 303] as $hostid) {
	$pdo->prepare('REPLACE INTO hosts (hostid, host, name, status) VALUES (?, ?, ?, 0)')->execute([$hostid, "h{$hostid}", "h{$hostid}"]);
}
$pull = static fn () => call(CControllerTopologyHostsPull::class, []);

API::$inventory = [301 => ['macaddress_a' => 'C0:FF:EE:00:00:01', 'macaddress_b' => ''],
	302 => ['macaddress_a' => '', 'macaddress_b' => 'c0:ff:ee:00:00:01'], 303 => ['macaddress_a' => '', 'macaddress_b' => '']];
$r = $pull();
check($r['hosts'] === 3 && $represented() === 0, 'a MAC in the inventory of two Hosts (either field, any case): no represented_by');
check($r['macs_shared']['count'] === 1 && $r['macs_shared']['list'][0]['kind'] === 'host'
	&& $r['macs_shared']['list'][0]['value'] === 'c0:ff:ee:00:00:01' && $r['macs_shared']['list'][0]['host_ids'] === [301, 302],
	'macs_shared lists the MAC with both hostids');

API::$inventory[302]['macaddress_b'] = '';
$r = $pull();
check($represented() === 1 && $r['macs_shared'] === ['count' => 0, 'list' => []], 'one Host with the MAC: Device 1 is matched, nothing shared');
check((int) $pdo->query("SELECT COUNT(*) FROM topo_nodes WHERE id = {$D1} AND represented_by_matched_by = 'mac'")->fetchColumn() === 1,
	'matched_by is mac');

API::$inventory[303]['macaddress_a'] = 'c0:ff:ee:00:00:02';
$r = $pull();
check($represented() === 1, 'a MAC on two Devices matches nothing');
check($r['macs_shared']['count'] === 1 && $r['macs_shared']['list'][0]['kind'] === 'device'
	&& $r['macs_shared']['list'][0]['node_ids'] === [$D2, $D3], 'macs_shared lists the MAC with both node ids');
$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

// ============================================================================================================
echo "\n=== step 11: /topo/devices carries the duplicate candidates and the dismissed pairs (topology-1-model.md §6.7.5, §12.2) ===\n";
// getDevices() joins two more Zabbix tables the other steps do not need: stand-ins for them
foreach (['ALTER TABLE hosts ADD COLUMN maintenance_status INT NOT NULL DEFAULT 0', 'ALTER TABLE proxy ADD COLUMN name VARCHAR(128) NOT NULL DEFAULT \'\''] as $ddl) {
	try {
		$pdo->exec($ddl);
	}
	catch (PDOException) {
	}
}
$pdo->exec('CREATE TABLE IF NOT EXISTS proxy_rtdata (proxyid BIGINT UNSIGNED PRIMARY KEY, state INT NOT NULL DEFAULT 0)');
reset_topology($pdo);
$Da = node($pdo, 'device', ['sysname' => 'Dev <A>', 'chassis_id' => 'aa', 'duplicate_candidates' => [
	['device_id' => 0, 'reason' => 'split_identity', 'key' => 'mgmt_ip', 'value' => '<b>1</b>'], ['device_id' => null, 'reason' => 'reporter_chassis_change', 'key' => 'chassis_id', 'value' => 'bb']]]);
$Db = node($pdo, 'device', ['sysname' => 'Dev B', 'chassis_id' => null, 'duplicate_dismissed' => [['device_id' => $Da, 'at' => 5, 'by' => 'Admin']]]);
$pdo->prepare('UPDATE topo_nodes SET attrs = ? WHERE id = ?')->execute([json_encode(['sysname' => 'Dev <A>', 'chassis_id' => 'aa', 'duplicate_candidates' => [
	['device_id' => $Db, 'reason' => 'split_identity', 'key' => 'mgmt_ip', 'value' => '<b>1</b>'], ['device_id' => null, 'reason' => 'reporter_chassis_change', 'key' => 'chassis_id', 'value' => 'bb']]]), $Da]);
$devices = array_column(CTopologyPrototype::getDevices([$Da, $Db]), null, 'id');
check(count($devices[$Da]['duplicates']) === 2 && $devices[$Da]['duplicates'][0]['name'] === 'Dev B' && $devices[$Da]['duplicates'][0]['value'] === '<b>1</b>'
	&& $devices[$Da]['duplicates'][1]['device_id'] === null, 'duplicates: reason, key, value as stored (the UI escapes), the other Device\'s name, null for "no Device C"');
check($devices[$Db]['dismissed'][0]['name'] === 'Dev <A>' && $devices[$Db]['dismissed'][0]['by'] === 'Admin' && isset($devices[$Da]['created_at']), 'dismissed pairs with the other name, and created_at (for the proposed K)');

echo "\nAll topology model tests passed.\n";
