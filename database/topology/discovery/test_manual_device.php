#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * test_manual_device.php — task 099, topology-1-model.md 2.80 §9.7 "Manual Device" and §11: the operations (create, edit, port create / rename / delete, delete Device) through the real controller
 * classes, with their refusals, the run lock and the guest; the identity of a manual Device in ingest (the hardware ID joins it, a name makes a mark, a run never deletes it or its ports, Promote with
 * "A port follows its name"). The real ingest.php runs as a process.
 *
 * Usage: test_manual_device.php <PDO DSN> <database user> [password]
 * Expects a THROWAWAY database (tables are dropped and recreated). Never point it at a real Zabbix database.
 */

function fail_test(string $message): void {
	fwrite(STDERR, "FAIL: {$message}\n");
	exit(1);
}

function check(bool $condition, string $description, $detail = null): void {
	if (!$condition) {
		fail_test($description.($detail !== null ? ' '.json_encode($detail) : ''));
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
foreach (['topo_identity_keys', 'topo_observations', 'topo_edges', 'topo_nodes', 'topo_lld_snapshot', 'host_inventory', 'interface', 'items', 'globalmacro',
		'proxy', 'hosts'] as $table) {
	$pdo->exec("DROP TABLE IF EXISTS {$table}");
}
$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
$pdo->exec("CREATE TABLE hosts (hostid BIGINT UNSIGNED PRIMARY KEY, host VARCHAR(128) NOT NULL, name VARCHAR(128) NOT NULL DEFAULT '', status INT NOT NULL DEFAULT 0)");
$pdo->exec('CREATE TABLE proxy (proxyid BIGINT UNSIGNED PRIMARY KEY)');
$pdo->exec('CREATE TABLE globalmacro (globalmacroid BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT, macro VARCHAR(255) NOT NULL DEFAULT \'\', value VARCHAR(2048) NOT NULL DEFAULT \'\')');
$pdo->exec('CREATE TABLE items (itemid BIGINT UNSIGNED PRIMARY KEY, hostid BIGINT UNSIGNED NOT NULL, name VARCHAR(255) NOT NULL DEFAULT \'\', status INT NOT NULL DEFAULT 0, topology_type INT NOT NULL DEFAULT 0, type INT NOT NULL DEFAULT 0)');
$pdo->exec(file_get_contents(__DIR__.'/../mysql_schema.sql'));
$pdo->exec('CREATE TABLE topo_lld_snapshot (itemid BIGINT UNSIGNED PRIMARY KEY, hostid BIGINT UNSIGNED NOT NULL, topology_type INT NOT NULL DEFAULT 0,'.
	' clock INT NOT NULL DEFAULT 0, rows_hash VARCHAR(64) NOT NULL DEFAULT \'\', rows_json LONGTEXT NOT NULL, rows_total INT NOT NULL DEFAULT 0,'.
	' rows_valid INT NOT NULL DEFAULT 0)');

// ---- the Zabbix PHP layer against the test database ----
$ui = realpath(__DIR__.'/../../../ui');
chdir($ui);
set_include_path($ui);
require_once 'include/classes/core/CAutoloader.php';

/** Stub: the real helper reads the severity names and colours from the Zabbix `config` table, which the test database does not have. */
class CSeverityHelper {
	public static function getName(int $severity): string {
		return 'Severity '.$severity;
	}

	public static function getColor(int $severity): string {
		return 'AA00'.sprintf('%02X', $severity);
	}
}

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
				foreach (API::$triggers as $n => $trigger) {
					$hit = array_values(array_intersect($trigger['itemids'], $options['itemids']));
					if ($hit) {
						$out[] = ['triggerid' => (string) ($n + 1), 'priority' => (string) $trigger['priority'],
							'items' => array_map(static fn ($id): array => ['itemid' => (string) $id], $hit)];
					}
				}
				return $out;
			}
		};
	}

	/** Problems of the active triggers above (the severity of a link comes from them, so that the tag-based permissions apply): a trigger
	 * with 'tag_hidden' => true is one the user may not see (a tag filter of the user group). */
	public static function Problem() {
		return new class {
			public function get($options) {
				$out = [];
				foreach (API::$triggers as $n => $trigger) {
					if (in_array((string) ($n + 1), array_map('strval', $options['objectids'] ?? []), true) && empty($trigger['tag_hidden'])) {
						$out[] = ['objectid' => (string) ($n + 1), 'severity' => (string) $trigger['priority']];
					}
				}
				return $out;
			}
		};
	}

	/** @var array[] items: itemid => ['key_', 'lastvalue', 'lastclock'] (the load of a port) */
	public static array $items = [];
	public static int $item_calls = 0;

	public static function Item() {
		return new class {
			public function get($options) {
				API::$item_calls++;
				$out = [];
				foreach ($options['itemids'] as $id) {
					if (isset(API::$items[$id])) {
						$out[] = ['itemid' => (string) $id] + API::$items[$id];
					}
				}
				return $out;
			}
		};
	}

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
	foreach (['topo_identity_keys', 'topo_observations', 'topo_edges', 'topo_nodes'] as $table) {
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


$pdo->exec("CREATE TABLE IF NOT EXISTS interface (interfaceid BIGINT UNSIGNED PRIMARY KEY, hostid BIGINT UNSIGNED NOT NULL, type INT NOT NULL, useip INT NOT NULL DEFAULT 1, ip VARCHAR(64) NOT NULL DEFAULT '', main INT NOT NULL DEFAULT 1, dns VARCHAR(255) NOT NULL DEFAULT '', port VARCHAR(64) NOT NULL DEFAULT '161')");
$pdo->exec('ALTER TABLE hosts ADD COLUMN maintenance_status INT NOT NULL DEFAULT 0');
$pdo->exec("ALTER TABLE proxy ADD COLUMN name VARCHAR(128) NOT NULL DEFAULT ''");
$pdo->exec('CREATE TABLE IF NOT EXISTS proxy_rtdata (proxyid BIGINT UNSIGNED PRIMARY KEY, state INT NOT NULL DEFAULT 0)');
$pdo->exec("CREATE TABLE IF NOT EXISTS host_inventory (hostid BIGINT UNSIGNED PRIMARY KEY, macaddress_a VARCHAR(64) NOT NULL DEFAULT '', macaddress_b VARCHAR(64) NOT NULL DEFAULT '')");

// ---- helpers (the same as the other ingest tests) ----
function scalar(PDO $pdo, string $sql, array $params = []) {
	$stmt = $pdo->prepare($sql);
	$stmt->execute($params);
	return $stmt->fetchColumn();
}

function add_host(PDO $pdo, int $hostid, string $host, string $name, ?string $ip): void {
	$pdo->prepare('INSERT INTO hosts (hostid, host, name) VALUES (?, ?, ?)')->execute([$hostid, $host, $name]);
	if ($ip !== null) {
		$pdo->prepare('INSERT INTO interface (interfaceid, hostid, type, useip, ip, main) VALUES (?, ?, 2, 1, ?, 1)')->execute([$hostid, $hostid, $ip]);
	}
	API::$visible[] = $hostid;
}

function snapshot(PDO $pdo, int $itemid, int $hostid, int $topology_type, int $clock, array $rows): void {
	$pdo->prepare('REPLACE INTO items (itemid, hostid, status, topology_type) VALUES (?, ?, 0, ?)')->execute([$itemid, $hostid, $topology_type]);
	$json = json_encode($rows, JSON_THROW_ON_ERROR);
	$pdo->prepare('REPLACE INTO topo_lld_snapshot (itemid, hostid, topology_type, clock, rows_hash, rows_json, rows_total, rows_valid) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
		->execute([$itemid, $hostid, $topology_type, $clock, hash('sha256', $json), $json, count($rows), count($rows)]);
}

function run_ingest(string $dsn, string $user, string $password): array {
	$cmd = [PHP_BINARY, __DIR__.'/ingest.php', '--pdo-dsn', $dsn, '--pdo-user', $user, '--pdo-password', $password];
	$tmp = sys_get_temp_dir().'/topology-manual-device-test';
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

function reset_all(PDO $pdo): void {
	$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
	foreach (['topo_identity_keys', 'topo_observations', 'topo_edges', 'topo_nodes', 'topo_lld_snapshot', 'host_inventory', 'interface', 'items', 'hosts', 'globalmacro'] as $t) {
		$pdo->exec("DELETE FROM {$t}");
	}
	$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
	API::$visible = [];
}

function attrs_of(PDO $pdo, int $id): array {
	return json_decode((string) scalar($pdo, 'SELECT attrs FROM topo_nodes WHERE id = ?', [$id]), true) ?: [];
}

/** a PORTS rule and a NEIGHBORS rule of a reporter: ports [[if_index, name]], neighbor rows */
function reporter(PDO $pdo, int $hostid, string $chassis, array $ports, array $neighbors): void {
	$rows = array_map(static fn (array $p): array => ['if_index' => $p[0], 'name' => $p[1], 'loc_chassis' => $chassis, 'itemids' => [$hostid * 1000 + $p[0]]], $ports);
	snapshot($pdo, $hostid * 10 + 1, $hostid, 1, time(), $rows);
	snapshot($pdo, $hostid * 10 + 2, $hostid, 2, time(), array_map(static fn (array $r): array => $r + ['loc_chassis' => $chassis, 'source' => 'lldp'], $neighbors));
}

function sees(int $if, string $chassis, string $port, array $more = []): array {
	return ['if_index' => $if, 'rem_chassis' => $chassis, 'rem_chassis_type' => 'macAddress', 'rem_port' => $port, 'rem_port_type' => 'interfaceName'] + $more;
}

function devices_count(PDO $pdo): int {
	return (int) scalar($pdo, "SELECT COUNT(*) FROM topo_nodes WHERE type = 'device'");
}

function ports_of(PDO $pdo, int $device_id): array {
	$out = [];
	foreach ($pdo->query("SELECT id, attrs FROM topo_nodes WHERE type = 'port' AND device_id = {$device_id} ORDER BY id")->fetchAll(PDO::FETCH_ASSOC) as $row) {
		$a = json_decode($row['attrs'], true);
		$out[(string) $a['name']] = (int) $row['id'];
	}
	return $out;
}

function key_rows(PDO $pdo, int $device_id): array {
	return $pdo->query("SELECT `key` FROM topo_identity_keys WHERE device_id = {$device_id} ORDER BY `key`")->fetchAll(PDO::FETCH_COLUMN);
}

$USER_ID = '1';
login();
$M1 = 'a0:00:00:00:00:77';

echo "\n=== 1. create: the fields, the ports, the identity key ===\n";
reset_all($pdo);
$a = call(CControllerTopologyDevicesCreate::class, ['name' => 'ISP-Router', 'device_class' => 'router', 'ports' => [['name' => 'wan0'], ['name' => 'lan0', 'description' => 'to the office']]]);
check(!failed($a) && !empty($a['success']) && isset($a['id']) && count($a['ports']) === 2, 'create "ISP-Router" (Router, no MAC, ports wan0, lan0) answers the Device id and the ports', $a);
$R1 = (int) $a['id'];
$at = attrs_of($pdo, $R1);
check($at['sysname'] === 'ISP-Router' && $at['source'] === 'manual' && $at['device_class'] === 'router' && $at['created_by'] === '1' && isset($at['created_at']) && !isset($at['chassis_id']), 'attrs: sysname, source manual, class, created_by, created_at, no hardware ID', $at);
check(key_rows($pdo, $R1) === [], 'no hardware ID: no identity key');
$ports = ports_of($pdo, $R1);
$pa = attrs_of($pdo, $ports['lan0']);
check(array_keys($ports) === ['wan0', 'lan0'] && $pa['source'] === 'manual' && $pa['if_type'] === 'physical' && !array_key_exists('if_index', $pa) && $pa['description'] === 'to the office' && $pa['pseudo'] === false, 'manual ports: name, no if_index, if_type physical, source manual, description', $pa);
$b = call(CControllerTopologyDevicesCreate::class, ['name' => 'Phone-1', 'device_class' => 'phone', 'chassis_id' => 'A0-00-00-00-00-77', 'mgmt_ip' => '10.1.2.3']);
$P = (int) $b['id'];
$at = attrs_of($pdo, $P);
check(!failed($b) && $at['chassis_id'] === $M1 && $at['mac'] === $M1 && $at['mgmt_ip'] === '10.1.2.3' && key_rows($pdo, $P) === ['chassis:mac:'.$M1], 'a hardware ID in any usual form is stored in its normal form, with its identity key (chassis:mac:<mac>), and the address', [$at, key_rows($pdo, $P)]);
$c = call(CControllerTopologyDevicesCreate::class, ['name' => 'Cisco-1', 'device_class' => 'switch', 'chassis_id' => 'a000.0000.0078']);
check(!failed($c) && attrs_of($pdo, (int) $c['id'])['chassis_id'] === 'a0:00:00:00:00:78', 'the Cisco form xxxx.xxxx.xxxx is accepted');
$d = call(CControllerTopologyDevicesCreate::class, ['name' => 'IPv6-1', 'device_class' => 'other', 'mgmt_ip' => '2001:DB8:0:0:0:0:0:1']);
check(!failed($d) && attrs_of($pdo, (int) $d['id'])['mgmt_ip'] === '2001:db8::1', 'an IPv6 address is stored in the text of normalization §4.3');

echo "\n=== 2. create: the refusals ===\n";
$count = devices_count($pdo);
$refuse = function (string $text, array $input) use ($pdo, &$count): void {
	$r = call(CControllerTopologyDevicesCreate::class, $input + ['name' => 'X', 'device_class' => 'router']);
	check(failed($r) && devices_count($pdo) === $count, 'refused: '.$text, $r);
};
$refuse('an empty name', ['name' => '  ']);
$refuse('a class that is not in the list', ['device_class' => 'toaster']);
$refuse('a port name twice', ['ports' => [['name' => 'p1'], ['name' => 'p1']]]);
$refuse('an empty port name', ['ports' => [['name' => ' ']]]);
$refuse('a hardware ID that is not a MAC', ['chassis_id' => 'not-a-mac']);
$refuse('a MAC with five octets', ['chassis_id' => 'a0:00:00:00:00']);
$refuse('the all-zero MAC', ['chassis_id' => '00:00:00:00:00:00']);
$refuse('the all-ff MAC', ['chassis_id' => 'FF-FF-FF-FF-FF-FF']);
$refuse('an address that is not an address', ['mgmt_ip' => '10.0.0']);
$refuse('the unspecified address', ['mgmt_ip' => '0.0.0.0']);
$refuse('a loopback address', ['mgmt_ip' => '127.0.0.1']);
$refuse('a multicast address', ['mgmt_ip' => '224.0.0.1']);
$r = call(CControllerTopologyDevicesCreate::class, ['name' => 'Twin', 'device_class' => 'router', 'chassis_id' => $M1]);
check(failed($r) && str_contains(implode(' ', $r['error']['messages']), 'Phone-1 has this hardware ID') && devices_count($pdo) === $count, 'refused: a hardware ID that another Device has (the message names that Device)', $r);

echo "\n=== 3. edit ===\n";
$r = call(CControllerTopologyDevicesUpdate::class, ['id' => (string) $R1, 'name' => 'ISP-Edge', 'device_class' => 'switch', 'mgmt_ip' => '192.0.2.50', 'chassis_id' => 'a0:00:00:00:00:99']);
$at = attrs_of($pdo, $R1);
check(!failed($r) && $at['sysname'] === 'ISP-Edge' && $at['device_class'] === 'switch' && $at['mgmt_ip'] === '192.0.2.50' && $at['chassis_id'] === 'a0:00:00:00:00:99' && key_rows($pdo, $R1) === ['chassis:mac:a0:00:00:00:00:99'] && $at['updated_by'] === '1', 'edit: name, class, address, hardware ID (the identity key follows), updated_by', [$at, key_rows($pdo, $R1)]);
$r = call(CControllerTopologyDevicesUpdate::class, ['id' => (string) $R1, 'chassis_id' => '', 'mgmt_ip' => '']);
$at = attrs_of($pdo, $R1);
check(!failed($r) && !isset($at['chassis_id']) && !isset($at['mac']) && !isset($at['mgmt_ip']) && key_rows($pdo, $R1) === [], 'an empty hardware ID and address clear them and the identity key', [$at, key_rows($pdo, $R1)]);
$r = call(CControllerTopologyDevicesUpdate::class, ['id' => (string) $R1, 'chassis_id' => $M1]);
check(failed($r) && key_rows($pdo, $R1) === [], 'refused: the hardware ID of another Device', $r);
$r = call(CControllerTopologyDevicesUpdate::class, ['id' => (string) $R1, 'name' => '']);
check(failed($r) && attrs_of($pdo, $R1)['sysname'] === 'ISP-Edge', 'refused: an empty name');
$r = call(CControllerTopologyDevicesUpdate::class, ['id' => (string) $R1, 'device_class' => 'toaster']);
check(failed($r), 'refused: a wrong class');
$DISC = device($pdo, 'Discovered-1');
$r = call(CControllerTopologyDevicesUpdate::class, ['id' => (string) $DISC, 'name' => 'Renamed']);
check(failed($r) && attrs_of($pdo, $DISC)['sysname'] === 'Discovered-1', 'refused: edit of a discovered Device', $r);
call(CControllerTopologyDevicesUpdate::class, ['id' => (string) $R1, 'name' => 'ISP-Router', 'device_class' => 'router']);

echo "\n=== 4. ports ===\n";
$ports = ports_of($pdo, $R1);
$r = call(CControllerTopologyPortsCreate::class, ['device_id' => (string) $R1, 'name' => 'lan1', 'description' => 'spare']);
check(!failed($r) && isset($r['id']) && array_key_exists('lan1', ports_of($pdo, $R1)), 'add a port', $r);
$r = call(CControllerTopologyPortsCreate::class, ['device_id' => (string) $R1, 'name' => 'lan1']);
check(failed($r), 'refused: a port name twice');
$r = call(CControllerTopologyPortsCreate::class, ['device_id' => (string) $R1, 'name' => ' ']);
check(failed($r), 'refused: an empty port name');
$r = call(CControllerTopologyPortsCreate::class, ['device_id' => (string) $DISC, 'name' => 'x']);
check(failed($r), 'refused: a port on a discovered Device');
$r = call(CControllerTopologyPortsUpdate::class, ['id' => (string) ports_of($pdo, $R1)['lan1'], 'name' => 'lan2', 'description' => '']);
$pa = attrs_of($pdo, ports_of($pdo, $R1)['lan2'] ?? 0);
check(!failed($r) && $pa['name'] === 'lan2' && !isset($pa['description']), 'rename a port and clear its description', [$r, $pa]);
$r = call(CControllerTopologyPortsUpdate::class, ['id' => (string) ports_of($pdo, $R1)['lan2'], 'name' => 'wan0']);
check(failed($r), 'refused: rename to a name that the Device has');
$r = call(CControllerTopologyPortsDelete::class, ['id' => (string) ports_of($pdo, $R1)['lan2']]);
check(!failed($r) && !array_key_exists('lan2', ports_of($pdo, $R1)), 'delete a free manual port', $r);
// a link on lan0: SW12-like discovered Device with a discovered port
$SW = device($pdo, 'SW-A');
$SWp = port($pdo, $SW, 1, '1/1/47');
$lan0 = ports_of($pdo, $R1)['lan0'];
$r = call(CControllerTopologyPortsLink::class, ['id' => (string) $SWp, 'dst_id' => (string) $lan0]);
check(!failed($r) && edge_row($pdo, (int) $r['edge_id'])['discovered_via'] === 'manual', 'link a manual port to a port (a manual link)', $r);
$EDGE = (int) $r['edge_id'];
$r = call(CControllerTopologyPortsUpdate::class, ['id' => (string) $lan0, 'name' => 'lan9']);
check(failed($r) && str_contains(implode(' ', $r['error']['messages']), 'unlink') && array_key_exists('lan0', ports_of($pdo, $R1)), 'refused: rename of a port with a link ("unlink the port first")', $r);
$r = call(CControllerTopologyPortsDelete::class, ['id' => (string) $lan0]);
check(failed($r) && array_key_exists('lan0', ports_of($pdo, $R1)), 'refused: delete of a port with a link');
$r = call(CControllerTopologyPortsUpdate::class, ['id' => (string) $SWp, 'name' => 'x']);
check(failed($r) && attrs_of($pdo, $SWp)['name'] === '1/1/47', 'refused: rename of a discovered port', $r);
$r = call(CControllerTopologyPortsDelete::class, ['id' => (string) $SWp]);
check(failed($r) && attrs_of($pdo, $SWp) !== [], 'refused: delete of a discovered port');
$r = call(CControllerTopologyPortsUnlink::class, ['id' => (string) $SWp, 'dst_id' => (string) $lan0]);
$r = call(CControllerTopologyPortsUpdate::class, ['id' => (string) $lan0, 'name' => 'lan9']);
check(!failed($r) && array_key_exists('lan9', ports_of($pdo, $R1)), 'after the unlink the port is renamed', $r);
call(CControllerTopologyPortsUpdate::class, ['id' => (string) $lan0, 'name' => 'lan0']);

echo "\n=== 5. the run lock and the guest ===\n";
login(true);
foreach ([[CControllerTopologyDevicesCreate::class, ['name' => 'G', 'device_class' => 'router']], [CControllerTopologyDevicesUpdate::class, ['id' => (string) $R1, 'name' => 'G']],
		[CControllerTopologyPortsCreate::class, ['device_id' => (string) $R1, 'name' => 'g']], [CControllerTopologyPortsUpdate::class, ['id' => (string) $lan0, 'name' => 'g']],
		[CControllerTopologyPortsDelete::class, ['id' => (string) $lan0]]] as [$class, $input]) {
	check(call($class, $input) === 'denied', 'the guest is denied: '.$class);
}
login();
CTopologyRun::lock();
$held = 'x';
// another owner holds the lock: write the owner directly (the test process is the same one, so a second lock() would succeed as another owner after the timeout)
DBexecute('UPDATE topo_run SET owner='.zbx_dbstr('run:other:1').', heartbeat='.time().' WHERE id=1');
CTopologyRun::release();
$_t = microtime(true);
$r = call(CControllerTopologyDevicesCreate::class, ['name' => 'Locked', 'device_class' => 'router']);
check(failed($r) && devices_count($pdo) === (int) scalar($pdo, "SELECT COUNT(*) FROM topo_nodes WHERE type = 'device'") && !scalar($pdo, "SELECT 1 FROM topo_nodes WHERE type = 'device' AND JSON_UNQUOTE(JSON_EXTRACT(attrs, '\$.sysname')) = 'Locked'"), 'a create while a run holds the lock is refused with the busy message (nothing written)', $r);
foreach ([[CControllerTopologyDevicesUpdate::class, ['id' => (string) $R1, 'name' => 'Locked']], [CControllerTopologyPortsCreate::class, ['device_id' => (string) $R1, 'name' => 'lk']],
		[CControllerTopologyPortsUpdate::class, ['id' => (string) $lan0, 'name' => 'lk']], [CControllerTopologyPortsDelete::class, ['id' => (string) $lan0]]] as [$class, $input]) {
	check(failed(call($class, $input)), 'refused while a run holds the lock: '.$class);
}
check(attrs_of($pdo, $R1)['sysname'] === 'ISP-Router' && array_key_exists('lan0', ports_of($pdo, $R1)), 'nothing changed while the lock was held');
DBexecute('UPDATE topo_run SET owner='.zbx_dbstr('').' WHERE id=1');

echo "\n=== 6. delete a manual Device: its manual links go with it ===\n";
$r = call(CControllerTopologyPortsLink::class, ['id' => (string) $SWp, 'dst_id' => (string) $lan0]);
$EDGE = (int) $r['edge_id'];
$r = call(CControllerTopologyDevicesDelete::class, ['id' => (string) $R1]);
check(!failed($r) && !scalar($pdo, 'SELECT 1 FROM topo_nodes WHERE id = ?', [$R1]) && !scalar($pdo, 'SELECT 1 FROM topo_edges WHERE id = ?', [$EDGE]) && !ports_of($pdo, $R1) && scalar($pdo, 'SELECT 1 FROM topo_nodes WHERE id = ?', [$SWp]), 'the Device, its ports and its manual link are gone; the other end stays', $r);
$MAN = (int) call(CControllerTopologyDevicesCreate::class, ['name' => 'ISP-Router', 'device_class' => 'router'])['id'];
$DP = (int) scalar($pdo, "SELECT id FROM topo_nodes WHERE type = 'device' AND JSON_UNQUOTE(JSON_EXTRACT(attrs, '\$.sysname')) = 'Discovered-1'");
$DPp = port($pdo, $DP, 1, 'p1');
$r = call(CControllerTopologyPortsLink::class, ['id' => (string) $DPp, 'dst_id' => (string) $SWp]);
$r = call(CControllerTopologyDevicesDelete::class, ['id' => (string) $DP]);
check(failed($r) && scalar($pdo, 'SELECT 1 FROM topo_nodes WHERE id = ?', [$DP]), 'a discovered Device with a manual link is still refused (delete the link first)', $r);

echo "\n=== 7. the view: a manual Device is data of the view with its chosen class ===\n";
$view = CTopologyPrototype::getView([], '', null);
$node = null;
foreach ($view['devices'] as $dev) {
	if ((string) $dev['id'] === (string) $MAN) {
		$node = $dev;
	}
}
check($node !== null && $node['manual'] === true && $node['device_class'] === 'router', 'getView: the manual Device with `manual` and its chosen class (router)', $node);
call(CControllerTopologyDevicesUpdate::class, ['id' => (string) $MAN, 'device_class' => 'other']);
$view = CTopologyPrototype::getView([], '', null);
foreach ($view['devices'] as $dev) {
	if ((string) $dev['id'] === (string) $MAN) {
		$node = $dev;
	}
}
check(!isset($node['device_class']) && $node['manual'] === true, 'class "other": no class (the generic icon)', $node);
// the chosen class wins over rem_sys_cap
$pdo->exec('INSERT INTO items (itemid, hostid, status, topology_type) VALUES (1, 1, 0, 2)');
$pdo->prepare("INSERT INTO topo_observations (itemid, local_port_ref, remote_key, outcome, device_id, remote_attrs, first_seen, last_seen) VALUES (1, '1', 'x', 'applied', ?, ?, 0, 0)")->execute([$MAN, json_encode(['rem_sys_cap' => 'wlanAccessPoint'])]);
call(CControllerTopologyDevicesUpdate::class, ['id' => (string) $MAN, 'device_class' => 'router']);
$node = null;
foreach (CTopologyPrototype::getView([], '', null)['devices'] as $dev) {
	if ((string) $dev['id'] === (string) $MAN) {
		$node = $dev;
	}
}
check($node['device_class'] === 'router', 'the chosen class wins over the rem_sys_cap of an observation', $node);
$pdo->exec('DELETE FROM topo_observations');
call(CControllerTopologyDevicesDelete::class, ['id' => (string) $MAN]);

echo "\n=== 8. ingest: the hardware ID joins the manual Device (§9.7 identity) ===\n";
reset_all($pdo);
$NM = (int) call(CControllerTopologyDevicesCreate::class, ['name' => 'ISP-Router', 'device_class' => 'router', 'chassis_id' => $M1, 'ports' => [['name' => 'wan0'], ['name' => 'lan0']]])['id'];
$mports = ports_of($pdo, $NM);
reporter_host($pdo, 101, 'a0:00:00:00:01:01');
$four = [[1, 'Gi0/1'], [2, 'Gi0/2'], [3, 'Gi0/3'], [4, 'Gi0/4']];
reporter($pdo, 101, 'a0:00:00:00:01:01', $four, []);
run_ingest($dsn, $user, $password);
$gi4 = (int) scalar($pdo, "SELECT id FROM topo_nodes WHERE type = 'port' AND JSON_UNQUOTE(JSON_EXTRACT(attrs, '\$.name')) = 'Gi0/4'");
$MLINK = (int) call(CControllerTopologyPortsLink::class, ['id' => (string) $mports['lan0'], 'dst_id' => (string) $gi4])['edge_id'];
reporter($pdo, 101, 'a0:00:00:00:01:01', $four, [sees(1, $M1, 'wan0')]);
$before = devices_count($pdo);
$r = run_ingest($dsn, $user, $password);
check($r['code'] === 0, 'the run finishes', $r['err']);
$at = attrs_of($pdo, $NM);
$links_to_manual = (int) scalar($pdo, "SELECT COUNT(*) FROM topo_edges e WHERE e.type IN ('physical_link', 'device_link') AND (e.src_id IN (SELECT id FROM topo_nodes WHERE device_id = {$NM}) OR e.dst_id IN (SELECT id FROM topo_nodes WHERE device_id = {$NM}) OR e.dst_id = {$NM}) AND e.id <> {$MLINK}");
check(devices_count($pdo) === $before && $links_to_manual === 1, 'a neighbor row with the chassis of the manual Device: no new Device, one link to the manual Device', [devices_count($pdo), $before, $links_to_manual]);
check($at['source'] === 'manual' && $at['sysname'] === 'ISP-Router' && array_keys(ports_of($pdo, $NM)) === ['wan0', 'lan0'] && edge_row($pdo, $MLINK) !== null && edge_row($pdo, $MLINK)['discovered_via'] === 'manual', 'source stays manual; its ports and its manual link stay', [$at, ports_of($pdo, $NM)]);
check(key_rows($pdo, $NM) === ['chassis:mac:'.$M1], 'the identity key is the same', key_rows($pdo, $NM));

echo "\n=== 9. a manual Device without a MAC: a neighbor with the same name is a new Device with the mark, merge keeps one ===\n";
reset_all($pdo);
$RN = (int) call(CControllerTopologyDevicesCreate::class, ['name' => 'R1', 'device_class' => 'router', 'ports' => [['name' => 'wan0']]])['id'];
reporter_host($pdo, 102, 'a0:00:00:00:02:02');
reporter($pdo, 102, 'a0:00:00:00:02:02', [[1, 'Gi0/1'], [2, 'Gi0/2']], [sees(1, 'a0:00:00:00:09:09', 'ge0', ['rem_sysname' => 'R1'])]);
run_ingest($dsn, $user, $password);
$other = (int) scalar($pdo, "SELECT id FROM topo_nodes WHERE type = 'device' AND id <> {$RN} AND JSON_UNQUOTE(JSON_EXTRACT(attrs, '\$.chassis_id')) = 'a0:00:00:00:09:09'");
$cands = array_merge(attrs_of($pdo, $RN)['duplicate_candidates'] ?? [], attrs_of($pdo, $other)['duplicate_candidates'] ?? []);
check($other > 0 && $other !== $RN && array_filter($cands, static fn (array $c): bool => $c['reason'] === 'same_name') !== [], 'a new Device and the mark "same name" on the pair', [$other, $cands]);
check(attrs_of($pdo, $RN)['source'] === 'manual' && scalar($pdo, 'SELECT 1 FROM topo_nodes WHERE id = ?', [$RN]), 'the manual Device is not merged by ingest');
$before = devices_count($pdo);
$m = CTopologyPrototype::mergeDevices((string) $RN, (string) $other, 'Admin');
check(devices_count($pdo) === $before - 1 && scalar($pdo, 'SELECT 1 FROM topo_nodes WHERE id = ?', [$RN]) && !scalar($pdo, 'SELECT 1 FROM topo_nodes WHERE id = ?', [$other]), 'the operator merges (O-5): one Device stays', $m);

echo "\n=== 10. a run never deletes a manual Device or a manual port ===\n";
reset_all($pdo);
$K = (int) call(CControllerTopologyDevicesCreate::class, ['name' => 'Keep', 'device_class' => 'station', 'chassis_id' => 'a0:00:00:00:00:55', 'ports' => [['name' => 'e0'], ['name' => 'e1']]])['id'];
$K2 = (int) call(CControllerTopologyDevicesCreate::class, ['name' => 'Keep2', 'device_class' => 'station'])['id'];
$kp = ports_of($pdo, $K);
$kl = (int) call(CControllerTopologyPortsLink::class, ['id' => (string) $kp['e0'], 'dst_id' => (string) call(CControllerTopologyPortsCreate::class, ['device_id' => (string) $K2, 'name' => 'e0'])['id']])['edge_id'];
$state = fn () => [devices_count($pdo), ports_of($pdo, $K), ports_of($pdo, $K2), attrs_of($pdo, $K) + [], edge_row($pdo, $kl) !== null];
$s0 = $state();
for ($i = 0; $i < 2; $i++) {
	$r = run_ingest($dsn, $user, $password);
	check($r['code'] === 0 && $state() === $s0, 'run '.($i + 1).' with no data at all: the manual Devices, their ports and their manual link are unchanged', $r['err']);
}
reporter_host($pdo, 103, 'a0:00:00:00:03:03');
reporter($pdo, 103, 'a0:00:00:00:03:03', [[1, 'Gi0/1']], []);
run_ingest($dsn, $user, $password);
$s1 = $state();
check($s1[1] === $s0[1] && $s1[2] === $s0[2] && $s1[4] === true && $s1[0] === $s0[0] + 1, 'a run with another reporter\'s data: still unchanged (only the reporter\'s Device is new)');

echo "\n=== 11. Promote to a host with a PORTS rule: a reported port with the name of a manual port takes it ===\n";
reset_all($pdo);
$PD = (int) call(CControllerTopologyDevicesCreate::class, ['name' => 'Sw-manual', 'device_class' => 'switch', 'chassis_id' => 'a0:00:00:00:04:04', 'ports' => [['name' => 'Gi0/1'], ['name' => 'Gi0/2'], ['name' => 'spare']]])['id'];
$pp = ports_of($pdo, $PD);
$OT = device($pdo, 'Other');
$OTp = port($pdo, $OT, 1, 'o1');
$PL = (int) call(CControllerTopologyPortsLink::class, ['id' => (string) $pp['Gi0/1'], 'dst_id' => (string) $OTp])['edge_id'];
add_host($pdo, 104, 'SW104', 'Switch 104', '10.0.0.104');
CTopologyPrototype::promote((string) $PD, 'host', '104');
// the node of the host is made by promote; the PORTS rule of the host reports Gi0/1 (index 1), Gi0/2 (index 2)
$rows = [['if_index' => 1, 'name' => 'Gi0/1', 'loc_chassis' => 'a0:00:00:00:04:04', 'itemids' => [1041]], ['if_index' => 2, 'name' => 'Gi0/2', 'loc_chassis' => 'a0:00:00:00:04:04', 'itemids' => [1042]]];
snapshot($pdo, 1041, 104, 1, time(), $rows);
$r = run_ingest($dsn, $user, $password);
check($r['code'] === 0, 'the run finishes', $r['err']);
$after = ports_of($pdo, $PD);
$g1 = attrs_of($pdo, $pp['Gi0/1']);
$g2 = attrs_of($pdo, $pp['Gi0/2']);
check($after['Gi0/1'] === $pp['Gi0/1'] && ($g1['if_index'] ?? null) === 1 && $after['Gi0/2'] === $pp['Gi0/2'] && ($g2['if_index'] ?? null) === 2, 'the reported ports take the manual Ports with the same names: same ids, they get the if_index', [$after, $pp, $g1, $g2]);
check(edge_row($pdo, $PL) !== null && edge_row($pdo, $PL)['discovered_via'] === 'manual', 'the manual link of a taken port stays');
check(count($after) === 3 && array_key_exists('spare', $after) && !array_key_exists('if_index', attrs_of($pdo, $pp['spare'])), 'a manual port that is not reported stays, without an if_index', $after);

echo "\nAll manual Device checks passed.\n";

function reporter_host(PDO $pdo, int $hostid, string $chassis): array {
	add_host($pdo, $hostid, 'SW'.$hostid, 'Switch '.$hostid, '10.0.0.'.($hostid % 250));
	return ['gi4' => 0];
}
