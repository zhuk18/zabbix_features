#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * test_addresses.php — task 049, topology-1-model.md 2.42 §11 `GET /topo/devices/{id}/addresses` and §12.5 "Addresses", through the real controller
 * (CControllerTopologyAddressesGet) and CTopologyPrototype::getAddresses(), against the test database. Also CTopologyAddress, the decoder of the addresses of a neighbor
 * (normalization §4.3, §4.4) that ingest.php shares with the page.
 *
 * The Zabbix PHP layer is loaded with a stub of the API: the visible hosts, and for the monitoring of the reporters (host.get monitored_by / proxyid / proxy_groupid /
 * assigned_proxyid, proxy.get, proxygroup.get) a table; the calls are counted (the whole answer needs one of each, not one per source).
 *
 * Usage: test_addresses.php <PDO DSN> <database user> [password]
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

	/** @var array[] hostid => ['monitored_by', 'proxyid', 'proxy_groupid', 'assigned_proxyid'] (live monitoring of a host); a host not listed is monitored by the server */
	public static array $monitoring = [];
	public static array $calls = ['host' => 0, 'host_by_id' => 0, 'proxy' => 0, 'proxygroup' => 0];
	public static array $proxies = [];       // proxyid => name
	public static array $proxy_groups = [];  // proxy_groupid => name

	public static function Host() {
		return new class {
			public function get($options) {
				API::$calls['host']++;
				$ids = isset($options['hostids']) ? array_map('strval', (array) $options['hostids']) : null;
				API::$calls['host_by_id'] += $ids !== null ? 1 : 0;      // the monitoring query (the other call is the list of the visible hosts)
				$out = [];
				foreach (API::$visible as $id) {
					if ($ids !== null && !in_array((string) $id, $ids, true)) {
						continue;
					}
					$out[] = ['hostid' => (string) $id] + (API::$monitoring[$id] ?? []) + ['monitored_by' => '0', 'proxyid' => '0', 'proxy_groupid' => '0', 'assigned_proxyid' => '0'];
				}
				return $out;
			}
		};
	}

	public static function Proxy() {
		return new class {
			public function get($options) {
				API::$calls['proxy']++;
				$out = [];
				foreach ($options['proxyids'] as $id) {
					if (isset(API::$proxies[$id])) {
						$out[] = ['proxyid' => (string) $id, 'name' => API::$proxies[$id]];
					}
				}
				return $out;
			}
		};
	}

	public static function ProxyGroup() {
		return new class {
			public function get($options) {
				API::$calls['proxygroup']++;
				$out = [];
				foreach ($options['proxy_groupids'] as $id) {
					if (isset(API::$proxy_groups[$id])) {
						$out[] = ['proxy_groupid' => (string) $id, 'name' => API::$proxy_groups[$id]];
					}
				}
				return $out;
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

login();

// ---- helpers ----

function reset_all(PDO $pdo): void {
	reset_topology($pdo);
	$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
	foreach (['topo_lld_snapshot', 'items', 'hosts'] as $table) {
		$pdo->exec("DELETE FROM {$table}");
	}
	$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
	API::$visible = [];
	API::$monitoring = [];
	API::$proxies = [];
	API::$proxy_groups = [];
	API::$calls = ['host' => 0, 'host_by_id' => 0, 'proxy' => 0, 'proxygroup' => 0];
}

/** A reporter host with a NEIGHBORS rule that has a usable snapshot, and a port; returns [itemid, local port id]. */
function reporter(PDO $pdo, int $hostid, string $name, string $port_name): array {
	$pdo->prepare('INSERT INTO hosts (hostid, host, name, status) VALUES (?, ?, ?, 0)')->execute([$hostid, $name, $name]);
	$itemid = $hostid * 10 + 2;
	$pdo->prepare("INSERT INTO items (itemid, hostid, name, status, topology_type, type) VALUES (?, ?, 'Topology neighbors', 0, 2, 0)")->execute([$itemid, $hostid]);
	$pdo->prepare("INSERT INTO topo_lld_snapshot (itemid, hostid, topology_type, clock, rows_hash, rows_json) VALUES (?, ?, 2, 1800000000, 'h', '[]')")->execute([$itemid, $hostid]);
	$dev = device($pdo, $name);
	$port = port($pdo, $dev, 1, $port_name);
	API::$visible[] = $hostid;
	return [$itemid, $port];
}

function observe(PDO $pdo, int $itemid, int $port, int $device, array $remote_attrs, string $key = null): void {
	$pdo->prepare("INSERT INTO topo_observations (itemid, local_port_id, remote_key, remote_attrs, outcome, device_id, first_seen, last_seen) VALUES (?, ?, ?, ?, 'applied', ?, 1, 1)")
		->execute([$itemid, $port, $key ?? 'chassis:mac:'.md5(json_encode($remote_attrs)), json_encode($remote_attrs), $device]);
}

function addresses_of(int $device): array {
	return call(CControllerTopologyAddressesGet::class, ['id' => (string) $device]);
}

// ============================================================================================================
echo "\n=== CTopologyAddress: the decoder of normalization §4.3, §4.4 ===\n";
$entries = 'lldp:2.16.32.1.13.184.0.0.0.0.0.0.0.0.0.0.0.1 lldp:1.4.10.0.0.9 lldp:1.5.10.0.0.10.7 lldp:1.4.10.0.0.5 cdp:1:0a:00:00:63 cdp:20:20:01 lldp:7.4.1.2.3.4 lldp:1.9.1.2.3.4.5.6.7.8 cdp:1:0a:00:00 bogus';
check(CTopologyAddress::decodeEntries($entries) === [
	['address' => '2001:db8::1', 'family' => 6], ['address' => '10.0.0.9', 'family' => 4], ['address' => '10.0.0.10', 'family' => 4],
	['address' => '10.0.0.5', 'family' => 4], ['address' => '10.0.0.99', 'family' => 4]],
	'every usable entry in the order of the entries: IPv6 text as RFC 5952, IPv4 with a length octet (also length 5 with an extra octet), CDP type 1; other families, wrong lengths, CDP types, junk left out');
check(CTopologyAddress::management($entries) === '10.0.0.9', 'the management address is the first IPv4 (the choice of ingest, unchanged)');
check(CTopologyAddress::management('lldp:2.16.32.1.13.184.0.0.0.0.0.0.0.0.0.0.0.1') === '2001:db8::1' && CTopologyAddress::management('junk') === null, 'IPv6 only when there is no IPv4; no usable entry: none');
check(CTopologyAddress::ipText('2001:0db8:0000:0000:0000:0000:0000:0001') === '2001:db8::1' && CTopologyAddress::ipText('10.0.0.1') === '10.0.0.1' && CTopologyAddress::ipText('not an ip') === null,
	'§4.3 address text');
check(CTopologyAddress::isHidden('0.0.0.0') && CTopologyAddress::isHidden('::') && CTopologyAddress::isHidden('127.0.0.1') && CTopologyAddress::isHidden('127.255.255.254')
	&& CTopologyAddress::isHidden('::1') && CTopologyAddress::isHidden('::ffff:127.0.0.1') && CTopologyAddress::isHidden('not an ip')
	&& CTopologyAddress::isHidden('224.0.0.1') && CTopologyAddress::isHidden('239.255.255.250') && CTopologyAddress::isHidden('ff02::1') && !CTopologyAddress::isHidden('223.255.255.255') && !CTopologyAddress::isHidden('240.0.0.1')
	&& !CTopologyAddress::isHidden('10.0.0.1') && !CTopologyAddress::isHidden('169.254.1.1') && !CTopologyAddress::isHidden('fe80::1') && !CTopologyAddress::isHidden('2001:db8::1'),
	'not shown (2.44, multicast 0.11): unspecified, loopback (127.0.0.0/8, ::1, also mapped), multicast (224.0.0.0/4, ff00::/8), not an address; link-local is shown');
check(CTopologyAddress::isLinkLocal('169.254.1.1') && CTopologyAddress::isLinkLocal('169.254.255.255') && CTopologyAddress::isLinkLocal('fe80::1') && CTopologyAddress::isLinkLocal('febf::1')
	&& !CTopologyAddress::isLinkLocal('169.253.1.1') && !CTopologyAddress::isLinkLocal('fec0::1') && !CTopologyAddress::isLinkLocal('2001:db8::1') && !CTopologyAddress::isLinkLocal('10.0.0.1'),
	'link-local: 169.254.0.0/16 and fe80::/10');
$rules = 'lldp:1.4.127.0.0.1 lldp:1.4.169.254.1.1 lldp:2.254.128.0.0.0.0.0.0.0.0.0.0.0.0.0.1 cdp:1:0a:00:00:07';
// normalization 0.11 §4.4: skip unspecified, loopback and multicast; then the first IPv4 that is not link-local, else the first IPv6 that is not link-local, else the first link-local
check(CTopologyAddress::management($rules) === '10.0.0.7' && CTopologyAddress::management('lldp:2.254.128.0.0.0.0.0.0.0.0.0.0.0.0.0.1') === 'fe80::1'
	&& CTopologyAddress::management('lldp:1.4.169.254.1.1 lldp:1.4.10.0.0.7') === '10.0.0.7' && CTopologyAddress::management('lldp:1.4.169.254.1.1 lldp:2.254.128.0.0.0.0.0.0.0.0.0.0.0.0.0.1') === '169.254.1.1',
	'the management address (§4.4): loopback skipped, link-local last (an IPv4 link-local before an IPv6 one)');
check(CTopologyAddress::management('lldp:1.4.127.0.0.1') === null && CTopologyAddress::management('lldp:1.4.0.0.0.0 lldp:1.4.224.0.0.251 lldp:2.2.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.1') === null
	&& CTopologyAddress::management('lldp:1.4.224.0.0.251 lldp:1.4.10.1.1.1') === '10.1.1.1' && CTopologyAddress::management('lldp:2.255.2.0.0.0.0.0.0.0.0.0.0.0.0.0.0.1 lldp:2.32.1.13.184.0.0.0.0.0.0.0.0.0.0.0.1') === '2001:db8::1',
	'loopback only, unspecified, multicast, ::1 only: no management address; a multicast entry before a real one is skipped');
check(!CTopologyAddress::countsAsIdentity('224.0.0.251') && !CTopologyAddress::countsAsIdentity('ff02::fb') && CTopologyAddress::countsAsIdentity('10.0.0.1')
	&& CTopologyAddress::claimAddresses(['rem_mgmt_ip' => '10.0.0.1', 'rem_man_addrs' => 'lldp:1.4.224.0.0.251 lldp:1.4.10.0.0.2 lldp:1.4.127.0.0.1']) === ['10.0.0.2', '10.0.0.1'],
	'multicast, loopback and link-local never match (claimAddresses keeps the others)');
// the name key (§6.7.1 "Names", 2.49) and the MAC form of a CDP device id (2.48)
check(CTopologyAddress::nameKey('N9K-1(FDO2101X0AB)') === 'n9k-1' && CTopologyAddress::nameKey('SW-X.lab') === 'sw-x' && CTopologyAddress::nameKey('  SW-X.Lab.Example (FDO1) ') === 'sw-x'
	&& CTopologyAddress::nameKey('10.0.0.1') === '10.0.0.1' && CTopologyAddress::nameKey('(serial)') === '(serial)' && CTopologyAddress::nameKey('a(b)c') === 'a(b)c',
	'name key: a final group in parentheses removed, then the domain; an address keeps its dots; a name that is only a group stays');
check(CTopologyAddress::macFormId('A8-52-D4-53-11-00') === 'a8:52:d4:53:11:00' && CTopologyAddress::macFormId('a852.d453.1100') === 'a8:52:d4:53:11:00' && CTopologyAddress::macFormId('f4:03:43:e8:ba:00') === 'f4:03:43:e8:ba:00'
	&& CTopologyAddress::macFormId('0:c:29:a:b:c') === '00:0c:29:0a:0b:0c' && CTopologyAddress::macFormId('0:0:0:0:0:0') === null && CTopologyAddress::macFormId('0:c:29-a-b-c') === null && CTopologyAddress::macFormId('0-c-29-a-b-c') === null && CTopologyAddress::macFormId('f4:03:43:e8:ba') === null && CTopologyAddress::macFormId('f4:03:43-e8-ba-00') === null && CTopologyAddress::macFormId('204c9e6e08fe') === null && CTopologyAddress::macFormId('00:00:00:00:00:00') === null
	&& CTopologyAddress::macFormId('FF:FF:FF:FF:FF:FF') === null && CTopologyAddress::macFormId('SW-A') === null,
	'a CDP device id in MAC form (6 octets with : or -, or xxxx.xxxx.xxxx) is a MAC; 5 octets, 12 digits, a placeholder, a name are not');
// the one match predicate (§6.7.1 "Match"): the 11 cases of task 056, the NX-OS name, multicast
$m = static fn (array $a, array $b): bool => CTopologyAddress::claimsMatch($a, $b);
$claim = static fn (array $attrs, string $name = '', ?string $chassis = null): array => ['addrs' => CTopologyAddress::claimAddresses($attrs), 'name' => $name] + ($chassis !== null ? ['chassis' => $chassis] : []);
$match_cases = [
	'the same address, no names' => [$claim(['rem_mgmt_ip' => '10.0.0.5']), $claim(['rem_mgmt_ip' => '10.0.0.5']), true],
	'different addresses, equal names' => [$claim(['rem_mgmt_ip' => '10.0.0.5'], 'SW-X'), $claim(['rem_mgmt_ip' => '10.0.9.9'], 'SW-X'), true],
	'the name with a domain and in capitals' => [$claim([], 'SW-X.lab'), $claim([], 'sw-x'), true],
	'the same address, different names (NAT, a factory IP)' => [$claim(['rem_mgmt_ip' => '192.168.1.1'], 'SW-X'), $claim(['rem_mgmt_ip' => '192.168.1.1'], 'PHONE-Y'), false],
	'different addresses and different names' => [$claim(['rem_mgmt_ip' => '10.0.0.5'], 'SW-X'), $claim(['rem_mgmt_ip' => '10.0.0.6'], 'PHONE-Y'), false],
	'nothing in common (a name on one side, an address on the other)' => [$claim([], 'SW-X'), $claim(['rem_mgmt_ip' => '10.0.0.6']), false],
	'a loopback address never matches' => [$claim(['rem_mgmt_ip' => '127.0.17.5']), $claim(['rem_mgmt_ip' => '127.0.17.5']), false],
	'a link-local address never matches' => [$claim(['rem_mgmt_ip' => '169.254.1.1']), $claim(['rem_mgmt_ip' => '169.254.1.1']), false],
	'the unspecified address never matches' => [$claim(['rem_mgmt_ip' => '0.0.0.0']), $claim(['rem_mgmt_ip' => '0.0.0.0']), false],
	'a multicast address never matches' => [$claim(['rem_man_addrs' => 'lldp:1.4.224.0.0.251']), $claim(['rem_man_addrs' => 'lldp:1.4.224.0.0.251']), false],
	'a loopback address and a name: the name matches' => [$claim(['rem_mgmt_ip' => '127.0.17.5'], 'SW-X'), $claim(['rem_mgmt_ip' => '127.0.17.5'], 'SW-X'), true],
	'a CDP name that equals the chassis id of the other claim as text matches that chassis' => [$claim([], 'PC-7'), $claim([], '', 'PC-7'), true],
	'... also when the case differs' => [$claim([], 'pc-7'), $claim([], 'PH', 'PC-7'), true],
	'the NX-OS device id name(serial) and the LLDP sysname name: the same name key' => [$claim([], 'N9K-1(FDO2101X0AB)'), $claim([], 'N9K-1'), true],
	'the NX-OS name with a domain and a serial' => [$claim([], 'N9K-1.lab(FDO2101X0AB)'), $claim([], 'n9k-1'), true],
	'another NX-OS name' => [$claim([], 'N9K-2(FDO2101X0AB)'), $claim([], 'N9K-1'), false],
	'the reporter-address row: the reporter has no name, the address decides' => [$claim(['rem_mgmt_ip' => '10.0.0.7'], 'R2'), $claim(['rem_mgmt_ip' => '10.0.0.7']), true],
	'the Device of the port with no name: the address decides' => [$claim(['rem_mgmt_ip' => '10.0.0.7'], 'AP-7'), $claim(['rem_mgmt_ip' => '10.0.0.7']), true],
];
foreach ($match_cases as $label => [$a, $b, $want]) {
	check($m($a, $b) === $want && $m($b, $a) === $want, 'match: '.$label.' -> '.($want ? 'match' : 'no match'));
}

// ============================================================================================================
echo "\n=== /addresses: two addresses from LLDP, one from CDP; management first; IPv4 before IPv6; numeric order; the monitoring of the reporters ===\n";
reset_all($pdo);
login();
$D = node($pdo, 'device', ['sysname' => 'Dev Z', 'chassis_id' => 'AA-BB-CC-DD-EE-01', 'mgmt_ip' => '10.0.0.9']);
[$i1, $p1] = reporter($pdo, 101, 'Sw1', 'Gi0/1');
[$i2, $p2] = reporter($pdo, 102, 'Sw2', 'Gi0/2');
[$i3, $p3] = reporter($pdo, 103, 'Sw3', 'Gi0/3');
API::$monitoring[102] = ['monitored_by' => '1', 'proxyid' => '7'];
API::$monitoring[103] = ['monitored_by' => '2', 'proxy_groupid' => '5', 'assigned_proxyid' => '8'];
API::$proxies = [7 => 'Proxy A', 8 => 'Proxy B'];
API::$proxy_groups = [5 => 'Group G'];
// Sw1, LLDP: an IPv6 first, then 10.0.0.10 and 10.0.0.9 (the management address of the Device), then 10.0.0.5
observe($pdo, $i1, $p1, $D, ['source' => 'lldp', 'rem_mgmt_ip' => '10.0.0.9',
	'rem_man_addrs' => 'lldp:2.16.32.1.13.184.0.0.0.0.0.0.0.0.0.0.0.1 lldp:1.4.10.0.0.10 lldp:1.4.10.0.0.9 lldp:1.4.10.0.0.5']);
// Sw2 (behind a proxy), CDP: 10.0.0.9 again (same as an LLDP address)
observe($pdo, $i2, $p2, $D, ['source' => 'cdp', 'rem_mgmt_ip' => '10.0.0.9', 'rem_man_addrs' => 'cdp:1:0a:00:00:09'], 'ip:server:10.0.0.9');
// Sw3 (behind a proxy group): a placeholder and an invalid address, and one real
observe($pdo, $i3, $p3, $D, ['source' => 'lldp', 'rem_mgmt_ip' => '0.0.0.0', 'rem_man_addrs' => 'lldp:1.4.0.0.0.0 lldp:1.4.192.168.1.1'], 'chassis:mac:cc');
$answer = addresses_of($D);
echo '  '.json_encode(array_map(static fn (array $a): array => [$a['address'], $a['management'], count($a['sources'])], $answer['addresses']))."\n";
check($answer['sysname'] === 'Dev Z' && $answer['chassis_id'] === 'aa:bb:cc:dd:ee:01', 'sysname, and the chassis id in normal form (a MAC in lower case with colons)');
check(array_column($answer['addresses'], 'address') === ['10.0.0.9', '10.0.0.5', '10.0.0.10', '192.168.1.1', '2001:db8::1'],
	'mgmt_ip first, then IPv4 in numeric order (10.0.0.5, 10.0.0.10, 192.168.1.1), then IPv6');
check($answer['addresses'][0]['management'] === true && !in_array(true, array_column(array_slice($answer['addresses'], 1), 'management'), true), 'only the management address is marked');
check(array_column($answer['addresses'], 'family') === [4, 4, 4, 4, 6], 'family 4 / 6');
check(count($answer['addresses'][0]['sources']) === 2 && array_column(array_column($answer['addresses'][0]['sources'], 'reporter'), 'name') === ['Sw1', 'Sw2']
	&& array_column($answer['addresses'][0]['sources'], 'source') === ['lldp', 'cdp'], 'an address that LLDP and CDP both report: one line, two sources (reporter, source)');
check(array_column($answer['addresses'][0]['sources'], 'local_port') === ['Gi0/1', 'Gi0/2'], 'with the local port of each source');
check(!in_array('0.0.0.0', array_column($answer['addresses'], 'address'), true) && !array_filter($answer['addresses'], static fn (array $a): bool => $a['address'] === ''),
	'a placeholder address is not shown');
$by = [];
foreach ($answer['addresses'] as $a) {
	foreach ($a['sources'] as $s) {
		$by[$s['reporter']['name']] = $s['monitoring'];
	}
}
check($by['Sw1'] === ['mode' => 'server', 'proxy' => null, 'proxy_group' => null], 'a reporter on the server: mode server');
check($by['Sw2'] === ['mode' => 'proxy', 'proxy' => ['id' => '7', 'name' => 'Proxy A'], 'proxy_group' => null], 'a reporter behind a proxy: the proxy (live)');
check($by['Sw3'] === ['mode' => 'proxy_group', 'proxy' => ['id' => '8', 'name' => 'Proxy B'], 'proxy_group' => ['id' => '5', 'name' => 'Group G']], 'a reporter behind a proxy group: the group and the proxy that serves it now');
check(API::$calls['host_by_id'] === 1 && API::$calls['proxy'] === 1 && API::$calls['proxygroup'] === 1,
	'the monitoring of all reporters (three hosts, five sources) is read in one go: one host.get with the hostids, one proxy.get, one proxygroup.get for the whole answer '.json_encode(API::$calls));

echo "\n=== /addresses: only the reporters the user can read; no address; unknown Device; guest ===\n";
API::$visible = [101, 102];
$answer = addresses_of($D);
check(!in_array('192.168.1.1', array_column($answer['addresses'], 'address'), true) && array_column($answer['addresses'], 'address')[0] === '10.0.0.9', 'a reporter the user cannot read gives no source and no address');
API::$visible = [101, 102, 103];
$E = node($pdo, 'device', ['sysname' => 'No address', 'chassis_id' => 'AA:BB:CC:DD:EE:02', 'mgmt_ip' => null]);
observe($pdo, $i1, $p1, $E, ['source' => 'lldp', 'rem_sysname' => 'No address'], 'chassis:mac:aa:bb:cc:dd:ee:02');
$answer = addresses_of($E);
check($answer['addresses'] === [] && $answer['sysname'] === 'No address' && $answer['chassis_id'] === 'aa:bb:cc:dd:ee:02', 'a Device with no address: an empty list (the page says "No address reported"), the name and the chassis id are there');
$F = node($pdo, 'device', ['sysname' => 'Only mgmt', 'chassis_id' => null, 'mgmt_ip' => '10.1.1.1']);
$answer = addresses_of($F);
check(array_column($answer['addresses'], 'address') === ['10.1.1.1'] && $answer['addresses'][0]['management'] === true && $answer['addresses'][0]['sources'] === [] && $answer['chassis_id'] === null,
	'a Device with only its mgmt_ip and no current observation: that line, marked management, no source');
$answer = addresses_of(999999);
check($answer['addresses'] === [] && $answer['sysname'] === null, 'an unknown Device: an empty answer');
login(true);
check(call(CControllerTopologyAddressesGet::class, ['id' => (string) $D]) === 'denied', 'the guest is denied');
login();

echo "\n=== /addresses (2.44): loopback not shown; link-local last and marked; link-local mgmt_ip first with both marks; mgmt_ip with no source ===\n";
$L = node($pdo, 'device', ['sysname' => 'Link local', 'chassis_id' => null, 'mgmt_ip' => '10.4.4.4']);
observe($pdo, $i1, $p1, $L, ['source' => 'lldp', 'rem_mgmt_ip' => '10.4.4.4', 'rem_man_addrs' => 'lldp:1.4.127.0.0.1 lldp:2.254.128.0.0.0.0.0.0.0.0.0.0.0.0.0.1 lldp:1.4.169.254.1.1 '.
	'lldp:2.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.1 lldp:2.16.32.1.13.184.0.0.0.0.0.0.0.0.0.0.0.1 lldp:1.4.10.4.4.4 lldp:1.4.10.4.4.5 lldp:1.4.169.254.0.9 lldp:2.254.128.0.0.0.0.0.0.0.0.0.0.0.0.0.2'], 'ip:server:10.4.4.4');
$answer = addresses_of($L);
echo '  '.json_encode(array_map(static fn (array $a): array => [$a['address'], $a['management'], $a['link_local'], $a['not_reported_now']], $answer['addresses']))."\n";
check(!array_intersect(['127.0.0.1', '::1', '0.0.0.0', '::'], array_column($answer['addresses'], 'address')), '127.0.0.1 and ::1 are not shown');
check(array_column($answer['addresses'], 'address') === ['10.4.4.4', '10.4.4.5', '2001:db8::1', '169.254.0.9', '169.254.1.1', 'fe80::1', 'fe80::2'],
	'mgmt_ip first, then IPv4, IPv6, then the link-local addresses: IPv4 (169.254.0.9, 169.254.1.1) before IPv6 (fe80::1, fe80::2), numeric order');
check(array_column($answer['addresses'], 'link_local') === [false, false, false, true, true, true, true], 'the field link_local marks 169.254.0.0/16 and fe80::/10 only');
check(!in_array(true, array_column($answer['addresses'], 'not_reported_now'), true), 'every address here has a source: none is "not reported now"');
$M = node($pdo, 'device', ['sysname' => 'Link local mgmt', 'chassis_id' => null, 'mgmt_ip' => 'fe80::7']);
observe($pdo, $i1, $p1, $M, ['source' => 'lldp', 'rem_mgmt_ip' => 'fe80::7', 'rem_man_addrs' => 'lldp:1.4.10.5.5.5 lldp:1.4.169.254.3.3'], 'ip:server:fe80::7');
$answer = addresses_of($M);
check(array_column($answer['addresses'], 'address') === ['fe80::7', '10.5.5.5', '169.254.3.3'], 'a link-local mgmt_ip stays first, then the others, the link-local ones last (fe80::7 is listed once, first)');
check($answer['addresses'][0]['management'] === true && $answer['addresses'][0]['link_local'] === true && $answer['addresses'][0]['not_reported_now'] === false && count($answer['addresses'][0]['sources']) === 1,
	'... with both marks (management, link-local); it is reported by rem_mgmt_ip, so it has its source');
$N = node($pdo, 'device', ['sysname' => 'Not reported', 'chassis_id' => null, 'mgmt_ip' => '169.254.7.7']);
$answer = addresses_of($N);
check(count($answer['addresses']) === 1 && $answer['addresses'][0]['address'] === '169.254.7.7' && $answer['addresses'][0]['sources'] === [] && $answer['addresses'][0]['not_reported_now'] === true
	&& $answer['addresses'][0]['management'] === true && $answer['addresses'][0]['link_local'] === true, 'a mgmt_ip that no current observation reports: one line, no source, not_reported_now (and link-local here)');
$answer = addresses_of($F);
check($answer['addresses'][0]['not_reported_now'] === true && $answer['addresses'][0]['link_local'] === false, 'the same for a plain mgmt_ip: not_reported_now, not link-local');
$O = node($pdo, 'device', ['sysname' => 'Loopback mgmt', 'chassis_id' => null, 'mgmt_ip' => '127.0.0.1']);
$answer = addresses_of($O);
check($answer['addresses'] === [] && $answer['mgmt_ip'] === null, 'a loopback mgmt_ip is not shown either: no line');

echo "\n=== /addresses: a value with HTML stays text ===\n";
$H = node($pdo, 'device', ['sysname' => '<img src=x onerror="alert(1)">', 'chassis_id' => '<b>local</b>', 'mgmt_ip' => '10.2.2.2']);
[$ih, $ph] = reporter($pdo, 104, '<script>alert(2)</script>', '<i>Gi0/9</i>');
observe($pdo, $ih, $ph, $H, ['source' => 'lldp', 'rem_mgmt_ip' => '10.2.2.2'], 'chassis:local:server:x');
$controller = new CControllerTopologyAddressesGet();
$set = static function (string $property, $value) use ($controller): void {
	$reflection = new ReflectionProperty(CController::class, $property);
	$reflection->setAccessible(true);
	$reflection->setValue($controller, $value);
};
$set('raw_input', ['id' => (string) $H]);
$set('validate_csrf_token', false);
$raw = $controller->run()->getData()['main_block'];
$answer = json_decode($raw, true);
check($answer['sysname'] === '<img src=x onerror="alert(1)">' && $answer['addresses'][0]['sources'][0]['reporter']['name'] === '<script>alert(2)</script>'
	&& $answer['addresses'][0]['sources'][0]['local_port'] === '<i>Gi0/9</i>', 'the values come back as they are (data)');
check(!str_contains($raw, '<') && !str_contains($raw, '>') && !str_contains($raw, '&') && !str_contains($raw, "'") && str_contains($raw, chr(92).'u003Cimg') && str_contains($raw, chr(92).'u0022alert'),
	'and the payload itself holds no markup: < > & \' " are hex escapes');

echo "\nAll address tests passed.\n";
