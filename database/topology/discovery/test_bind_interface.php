#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * test_bind_interface.php — task 107, topology-1-model.md 2.91 §9.7 "Bind to interface" and §11: the operations topology.ports.interfaces / bind / unbind through the real controller classes, with
 * their refusals and the guest; an item that the user may not read is not listed; the ingest rules (a bound port on a host with a PORTS rule takes the row of its index, a host without a PORTS rule
 * leaves the binding alone, deleted items show "interface not found" and Unbind works). The real ingest.php runs as a process.
 *
 * Usage: test_bind_interface.php <PDO DSN> <database user> [password]
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
				if (isset($options['hostids'])) {
					// the interfaces of a host (task 107): its discovered items; an item with 'hidden' is one the user may not read
					foreach (API::$items as $id => $item) {
						if (!in_array((string) ($item['hostid'] ?? ''), array_map('strval', $options['hostids']), true) || !empty($item['hidden'])) {
							continue;
						}
						$out[] = ['itemid' => (string) $id, 'key_' => $item['key_'], 'itemDiscovery' => ['parent_itemid' => '1', 'key_' => $item['proto'] ?? ''], 'tags' => $item['tags'] ?? []];
					}
					return $out;
				}
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
	$tmp = sys_get_temp_dir().'/topology-bind-interface-test';
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
/** The items of one interface of a host (the stub of item.get above): the four keys of §4.8 with their prototypes and the tag `interface`. */
function add_interface(PDO $pdo, int $hostid, int $index, string $name, bool $hidden = false): array {
	$ids = [];
	foreach (['net.if.in' => 'ifHCInOctets', 'net.if.out' => 'ifHCOutOctets', 'net.if.speed' => 'ifHighSpeed', 'net.if.status' => 'ifOperStatus'] as $key => $oid) {
		$id = $hostid * 1000 + $index * 10 + count($ids);
		API::$items[$id] = ['hostid' => $hostid, 'key_' => "{$key}[{$oid}.{$index}]", 'proto' => "{$key}[{$oid}.{{$GLOBALS['MACRO']}}]", 'tags' => [['tag' => 'interface', 'value' => $name], ['tag' => 'component', 'value' => 'network']],
			'hidden' => $hidden, 'value_type' => '3', 'type' => '20', 'delay' => '1m', 'master_itemid' => '0'];
		$pdo->prepare('REPLACE INTO items (itemid, hostid, status, topology_type) VALUES (?, ?, 0, 0)')->execute([$id, $hostid]);
		$ids[] = $id;
	}
	// an item of another kind (errors) is not part of the interface
	$id = $hostid * 1000 + $index * 10 + 9;
	API::$items[$id] = ['hostid' => $hostid, 'key_' => "net.if.in.errors[ifInErrors.{$index}]", 'proto' => "net.if.in.errors[ifInErrors.{{$GLOBALS['MACRO']}}]", 'tags' => [], 'hidden' => $hidden];

	return $ids;
}
$MACRO = '#SNMPINDEX';
CTopologyLoad::$history_period = 86400;      // the setting HISTORY_PERIOD (the test database has no config table)
$pdo->exec('DROP TABLE IF EXISTS history');
$pdo->exec('DROP TABLE IF EXISTS history_uint');
$pdo->exec('CREATE TABLE history (itemid BIGINT UNSIGNED NOT NULL, clock INT NOT NULL DEFAULT 0, value DOUBLE NOT NULL DEFAULT 0, ns INT NOT NULL DEFAULT 0, PRIMARY KEY (itemid, clock, ns))');
$pdo->exec('CREATE TABLE history_uint (itemid BIGINT UNSIGNED NOT NULL, clock INT NOT NULL DEFAULT 0, value BIGINT UNSIGNED NOT NULL DEFAULT 0, ns INT NOT NULL DEFAULT 0, PRIMARY KEY (itemid, clock, ns))');

function drop_items(PDO $pdo, array $ids): void {
	foreach ($ids as $id) {
		unset(API::$items[$id]);
		$pdo->prepare('DELETE FROM items WHERE itemid = ?')->execute([$id]);
	}
}

function binding_of(int $port_id, int $device_id): ?array {
	foreach (CTopologyPrototype::getPorts((string) $device_id) as $group) {
		foreach ($group as $row) {
			if ((int) $row['id'] === $port_id) {
				return ['binding' => $row['binding'] ?? null, 'port' => $row['port']];
			}
		}
	}

	return null;
}

echo "\n=== 1. the interfaces of the host of a Device (§9.7, §11) ===\n";
reset_all($pdo);
API::$items = [];
$BD = (int) call(CControllerTopologyDevicesCreate::class, ['name' => 'BIND-TEST', 'device_class' => 'switch', 'ports' => [['name' => 'p1'], ['name' => 'p2'], ['name' => 'p3']]])['id'];
$bp = ports_of($pdo, $BD);
$r = call(CControllerTopologyPortsInterfaces::class, ['id' => (string) $bp['p1']]);
check(failed($r), 'a Device without an association has no interfaces: refused', $r);
$r = call(CControllerTopologyPortsBind::class, ['id' => (string) $bp['p1'], 'if_index' => 1]);
check(failed($r), 'a Device without an association: Bind is refused', $r);
add_host($pdo, 201, 'SW201', 'Switch 201', '10.0.0.201');
$i1 = add_interface($pdo, 201, 1, 'Gi0/1');
$i2 = add_interface($pdo, 201, 2, 'Gi0/2');
$i7 = add_interface($pdo, 201, 7, 'Te1/0/1');
add_interface($pdo, 201, 9, 'Hidden0/9', true);
add_interface($pdo, 202, 1, 'OtherHost');      // an interface of another host is not listed
CTopologyPrototype::promote((string) $BD, 'host', '201');
$r = call(CControllerTopologyPortsInterfaces::class, ['id' => (string) $bp['p1']]);
$names = array_map(static fn (array $i): string => $i['if_index'].':'.$i['name'], $r['interfaces'] ?? []);
check(!failed($r) && $names === ['1:Gi0/1', '2:Gi0/2', '7:Te1/0/1'], 'the interfaces of the host: index and name (the tag), sorted by index; an item the user may not read and another host are not listed', [$names, $r]);
check(($r['interfaces'][0]['itemids'] ?? null) === $i1 && ($r['interfaces'][2]['itemids'] ?? null) === $i7 && $r['interfaces'][0]['port'] === null, 'the items of an interface: in, out, speed, status (not the errors item); no Port has it yet', $r['interfaces'][0] ?? null);

echo "\n=== 2. Bind ===\n";
$r = call(CControllerTopologyPortsBind::class, ['id' => (string) $bp['p1'], 'if_index' => 2]);
$at = attrs_of($pdo, $bp['p1']);
check(!failed($r) && ($at['if_index'] ?? null) === 2 && $at['zabbix_itemids'] === $i2 && $at['name'] === 'p1' && $at['source'] === 'manual' && $at['interface_name'] === 'Gi0/2', 'Bind p1 to the interface 2: if_index, zabbix_itemids; name and source stay', [$r, $at]);
$dev = attrs_of($pdo, $BD);
check(($dev['updated_by'] ?? null) === '1' && isset($dev['updated_at']), 'updated_by and updated_at of the Device are recorded', $dev);
$r = call(CControllerTopologyPortsInterfaces::class, ['id' => (string) $bp['p2']]);
check(($r['interfaces'][1]['port']['name'] ?? null) === 'p1' && $r['interfaces'][0]['port'] === null, 'the list shows the Port that has the index', $r['interfaces'][1] ?? null);
$r = call(CControllerTopologyPortsBind::class, ['id' => (string) $bp['p2'], 'if_index' => 2]);
check(failed($r) && str_contains(implode(' ', $r['error']['messages']), 'p1 already has') && !array_key_exists('if_index', attrs_of($pdo, $bp['p2'])), 'Bind p2 to the same interface: refused ("p1 already has this interface")', $r);
check(!failed(call(CControllerTopologyPortsBind::class, ['id' => (string) $bp['p1'], 'if_index' => 2])), 'Bind p1 to the interface it already has: no refusal');
foreach ([[99, 'an index that the host has no interface for'], [9, 'an interface whose items the user may not read'], [1000000, 'a very large index']] as [$index, $text]) {
	$r = call(CControllerTopologyPortsBind::class, ['id' => (string) $bp['p2'], 'if_index' => $index]);
	check(failed($r) && !array_key_exists('if_index', attrs_of($pdo, $bp['p2'])), 'Bind refused: '.$text, $r);
}
$r = call(CControllerTopologyPortsBind::class, ['id' => (string) $bp['p1'], 'if_index' => 7]);
check(!failed($r) && attrs_of($pdo, $bp['p1'])['zabbix_itemids'] === $i7, 'Bind p1 again, to another interface: it moves (the old items are replaced)');
// a port with a link can be bound: the manual link stays
$other = device($pdo, 'Other');
$op = port($pdo, $other, 1, 'o1');
$LK = (int) call(CControllerTopologyPortsLink::class, ['id' => (string) $bp['p3'], 'dst_id' => (string) $op])['edge_id'];
$r = call(CControllerTopologyPortsBind::class, ['id' => (string) $bp['p3'], 'if_index' => 1]);
check(!failed($r) && edge_row($pdo, $LK) !== null && edge_row($pdo, $LK)['discovered_via'] === 'manual' && attrs_of($pdo, $bp['p3'])['if_index'] === 1, 'a port with a manual link can be bound; the link stays', $r);
$r = call(CControllerTopologyPortsBind::class, ['id' => (string) $op, 'if_index' => 1]);
check(failed($r), 'a port of a discovered Device is refused', $r);
$r = call(CControllerTopologyPortsInterfaces::class, ['id' => (string) $op]);
check(failed($r), 'the list for a port of a discovered Device is refused', $r);

echo "\n=== 3. the port data, Unbind ===\n";
$b = binding_of($bp['p3'], $BD);
check(($b['binding']['if_index'] ?? null) === 1 && $b['binding']['name'] === 'Gi0/1' && $b['binding']['missing'] === false, 'ports.get: the binding (index, interface name, not missing)', $b);
$b = binding_of($bp['p2'], $BD);
check($b !== null && $b['binding'] === null, 'a port that is not bound has no binding', $b);
drop_items($pdo, $i1);
$b = binding_of($bp['p3'], $BD);
check(($b['binding']['missing'] ?? null) === true, 'the items of the interface are deleted: the binding shows "interface not found" (missing)', $b);
$r = call(CControllerTopologyPortsUnbind::class, ['id' => (string) $bp['p3']]);
$at = attrs_of($pdo, $bp['p3']);
check(!failed($r) && !array_key_exists('if_index', $at) && $at['zabbix_itemids'] === [] && !array_key_exists('interface_name', $at) && $at['name'] === 'p3' && $at['source'] === 'manual' && edge_row($pdo, $LK) !== null, 'Unbind works on a stale binding: if_index and items cleared, the port and its link stay', $at);
check(binding_of($bp['p3'], $BD)['binding'] === null, 'after Unbind the binding is gone from ports.get');
check(!failed(call(CControllerTopologyPortsUnbind::class, ['id' => (string) $bp['p3']])), 'Unbind of a port that is not bound: no refusal');
$r = call(CControllerTopologyPortsUnbind::class, ['id' => (string) $op]);
check(failed($r), 'Unbind of a port of a discovered Device is refused', $r);

echo "\n=== 4. the guest and the run lock ===\n";
login(true);
foreach ([[CControllerTopologyPortsInterfaces::class, ['id' => (string) $bp['p1']]], [CControllerTopologyPortsBind::class, ['id' => (string) $bp['p1'], 'if_index' => 2]], [CControllerTopologyPortsUnbind::class, ['id' => (string) $bp['p1']]]] as [$class, $input]) {
	check(call($class, $input) === 'denied', 'the guest is denied: '.$class);
}
login();
CTopologyRun::lock();
DBexecute('UPDATE topo_run SET owner='.zbx_dbstr('run:other:1').', heartbeat='.time().' WHERE id=1');
CTopologyRun::release();
$before = attrs_of($pdo, $bp['p2']);
foreach ([[CControllerTopologyPortsBind::class, ['id' => (string) $bp['p2'], 'if_index' => 2]], [CControllerTopologyPortsUnbind::class, ['id' => (string) $bp['p1']]]] as [$class, $input]) {
	check(failed(call($class, $input)), 'refused while a run holds the lock: '.$class);
}
check(attrs_of($pdo, $bp['p2']) === $before && isset(attrs_of($pdo, $bp['p1'])['if_index']), 'nothing changed while the lock was held');
DBexecute('UPDATE topo_run SET owner='.zbx_dbstr('').' WHERE id=1');

echo "\n=== 5. ingest: a bound port on a host with a PORTS rule takes the row of its index ===\n";
reset_all($pdo);
API::$items = [];
$M2 = 'a0:00:00:00:05:05';
$ND = (int) call(CControllerTopologyDevicesCreate::class, ['name' => 'Sw-bind', 'device_class' => 'switch', 'chassis_id' => $M2, 'ports' => [['name' => 'lan0'], ['name' => 'lan1']]])['id'];
$np = ports_of($pdo, $ND);
add_host($pdo, 301, 'SW301', 'Switch 301', '10.0.0.30');
add_interface($pdo, 301, 1, 'Gi0/1');
$j2 = add_interface($pdo, 301, 2, 'Gi0/2');
CTopologyPrototype::promote((string) $ND, 'host', '301');
check(!failed(call(CControllerTopologyPortsBind::class, ['id' => (string) $np['lan0'], 'if_index' => 2])), 'lan0 is bound to the interface 2');
$far = device($pdo, 'Far');
$farp = port($pdo, $far, 1, 'f1');
$ML = (int) call(CControllerTopologyPortsLink::class, ['id' => (string) $np['lan0'], 'dst_id' => (string) $farp])['edge_id'];
$rows = [['if_index' => 1, 'name' => 'Gi0/1', 'loc_chassis' => $M2, 'itemids' => [301010, 301011]], ['if_index' => 2, 'name' => 'Gi0/2', 'loc_chassis' => $M2, 'itemids' => $j2]];
snapshot($pdo, 3011, 301, 1, time(), $rows);
$r = run_ingest($dsn, $user, $password);
check($r['code'] === 0, 'the run finishes', $r['err']);
$with_index_2 = $pdo->query("SELECT id FROM topo_nodes WHERE type = 'port' AND device_id = {$ND} AND JSON_EXTRACT(attrs, '\$.if_index') = 2")->fetchAll(PDO::FETCH_COLUMN);
$at = attrs_of($pdo, $np['lan0']);
check(array_map('intval', $with_index_2) === [$np['lan0']], 'the row with the bound index takes the bound Port: no second Port with the index 2', $with_index_2);
check(($at['name'] ?? null) === 'Gi0/2' && $at['zabbix_itemids'] === $j2, 'the name of the row replaces the manual name; the items are those of the row', $at);
check(edge_row($pdo, $ML) !== null && edge_row($pdo, $ML)['discovered_via'] === 'manual' && (int) scalar($pdo, "SELECT src_id FROM topo_edges WHERE id = ?", [$ML]) + (int) scalar($pdo, "SELECT dst_id FROM topo_edges WHERE id = ?", [$ML]) === $np['lan0'] + $farp, 'the manual link stays on that Port', edge_row($pdo, $ML));
$names = array_keys(ports_of($pdo, $ND));
sort($names);
check($names === ['Gi0/1', 'Gi0/2', 'lan1'], 'the Ports of the Device: the reported ones and the manual port that was not bound', $names);

echo "\n=== 6. ingest: a host without a PORTS rule leaves the binding alone ===\n";
reset_all($pdo);
API::$items = [];
$M3 = 'a0:00:00:00:06:06';
$SD = (int) call(CControllerTopologyDevicesCreate::class, ['name' => 'Sw-nolld', 'device_class' => 'switch', 'chassis_id' => $M3, 'ports' => [['name' => 'e0'], ['name' => 'e1']]])['id'];
$sp = ports_of($pdo, $SD);
add_host($pdo, 302, 'SW302', 'Switch 302', '10.0.0.32');
$k3 = add_interface($pdo, 302, 3, 'ge-0/0/3');
CTopologyPrototype::promote((string) $SD, 'host', '302');
call(CControllerTopologyPortsBind::class, ['id' => (string) $sp['e0'], 'if_index' => 3]);
$snap = fn () => [attrs_of($pdo, $sp['e0']), attrs_of($pdo, $sp['e1']), ports_of($pdo, $SD), attrs_of($pdo, $SD)];
$s0 = $snap();
add_host($pdo, 303, 'SW303', 'Switch 303', '10.0.0.33');
for ($i = 0; $i < 2; $i++) {
	reporter($pdo, 303, 'a0:00:00:00:07:07', [[1, 'Gi0/1']], []);      // another reporter runs; the host 302 has no PORTS rule and no snapshot
	$r = run_ingest($dsn, $user, $password);
	check($r['code'] === 0 && $snap() === $s0, 'run '.($i + 1).': the bound port, the other port and the Device of a host without a PORTS rule are unchanged', $r['err']);
}

echo "\n=== 7. the items of the bound interface deleted: \"interface not found\", Unbind works ===\n";
drop_items($pdo, $k3);
$b = binding_of($sp['e0'], $SD);
check(($b['binding']['missing'] ?? null) === true && $b['binding']['if_index'] === 3 && $b['binding']['name'] === 'ge-0/0/3', 'the port data says the interface is not found (the binding stays, with its name)', $b);
$r = run_ingest($dsn, $user, $password);
check($r['code'] === 0 && attrs_of($pdo, $sp['e0'])['if_index'] === 3, 'a run does not follow the change: the binding is stale, not cleared');
check(!failed(call(CControllerTopologyPortsUnbind::class, ['id' => (string) $sp['e0']])) && binding_of($sp['e0'], $SD)['binding'] === null, 'Unbind works');
$k3b = add_interface($pdo, 302, 3, 'ge-0/0/3');
check(!failed(call(CControllerTopologyPortsBind::class, ['id' => (string) $sp['e0'], 'if_index' => 3])) && binding_of($sp['e0'], $SD)['binding']['missing'] === false, 'Bind again to the interface that is back: the binding is not missing');

echo "\nAll Bind to interface checks passed.\n";

function reporter_host(PDO $pdo, int $hostid, string $chassis): array {
	add_host($pdo, $hostid, 'SW'.$hostid, 'Switch '.$hostid, '10.0.0.'.($hostid % 250));
	return ['gi4' => 0];
}
