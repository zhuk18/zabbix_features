#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * test_junction.php — task 104, topology-1-model.md §12.6 (2.88: not implemented) "Junction projection": computed on read from the observations of the latest snapshots, nothing stored.
 *   two rows of one rule that resolve to two Devices → a projection anchored at the reporter Port; two rows of one Device → none; rows of two different rules → none;
 *   an active manual link on the Port → none (a superseded one does not suppress); a snapshot without the rows → none; `old_snapshot` rows → none; access: fewer than two visible
 *   Devices → none; the view (getView) carries the projections and the Devices behind them; `neighbors` carries the projections of its Device.
 *
 * Usage: test_junction.php <PDO DSN> <database user> [password]
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
		'proxy_rtdata', 'proxy', 'hosts'] as $table) {
	$pdo->exec("DROP TABLE IF EXISTS {$table}");
}
$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
$pdo->exec("CREATE TABLE hosts (hostid BIGINT UNSIGNED PRIMARY KEY, host VARCHAR(128) NOT NULL, name VARCHAR(128) NOT NULL DEFAULT '', status INT NOT NULL DEFAULT 0, maintenance_status INT NOT NULL DEFAULT 0, monitored_by INT NOT NULL DEFAULT 0, proxyid BIGINT UNSIGNED NULL, proxy_groupid BIGINT UNSIGNED NULL, assigned_proxyid BIGINT UNSIGNED NULL)");
$pdo->exec("CREATE TABLE proxy (proxyid BIGINT UNSIGNED PRIMARY KEY, name VARCHAR(128) NOT NULL DEFAULT '')");
$pdo->exec('CREATE TABLE proxy_rtdata (proxyid BIGINT UNSIGNED PRIMARY KEY, state INT NOT NULL DEFAULT 0)');
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
	$pdo->prepare('INSERT INTO topo_nodes (type, device_id, attrs, created_at, updated_at) VALUES (?, ?, ?, 0, 0)')->execute([$type, $device_id, json_encode($attrs)]);

	return (int) $pdo->lastInsertId();
}

function device(PDO $pdo, string $name): int {
	return node($pdo, 'device', ['sysname' => $name, 'chassis_id' => $name]);
}

function port(PDO $pdo, int $device_id, string $name): int {
	return node($pdo, 'port', ['name' => $name], $device_id);
}

function edge(PDO $pdo, int $a, int $b, array $attrs = ['discovered_via' => 'lldp']): int {
	$pdo->prepare('INSERT INTO topo_edges (type, src_id, dst_id, attrs, created_at) VALUES (?, ?, ?, ?, 0)')->execute(['physical_link', min($a, $b), max($a, $b), json_encode($attrs)]);

	return (int) $pdo->lastInsertId();
}

/** One observation row: the rule (itemid), the reporter Port and the Device the row resolves to. */
function observation(PDO $pdo, int $itemid, int $port_id, ?int $device_id, string $outcome = 'ambiguous'): void {
	$pdo->prepare('INSERT INTO topo_observations (itemid, local_port_ref, local_port_id, remote_key, remote_attrs, outcome, device_id, first_seen, last_seen) VALUES (?, ?, ?, ?, ?, ?, ?, 1, 1)')
		->execute([$itemid, 'p'.$port_id, $port_id, 'k'.$itemid.'-'.$device_id.'-'.mt_rand(), '{}', $outcome, $device_id]);
}

function junctions(?array $reporters = null): array {
	return CTopologyPrototype::getJunctions($reporters);
}

function members(array $junctions): array {
	$out = [];
	foreach ($junctions as $junction) {
		$out[$junction['port_id']] = array_column($junction['devices'], 'id');
	}

	return $out;
}

// ---- the stand: reporter R with ports 16 (three neighbors), 2 (one neighbor), 3 (two rules), 4 (a manual link), 5 (rows of one Device) ----
$pdo->exec('DELETE FROM topo_observations');
$R = device($pdo, 'R');
$pc1 = device($pdo, 'PC1');
$pc2 = device($pdo, 'PC2');
$prn = device($pdo, 'PRN');
$p16 = port($pdo, $R, '16');
$p2 = port($pdo, $R, '2');
$p3 = port($pdo, $R, '3');
$p4 = port($pdo, $R, '4');
$p5 = port($pdo, $R, '5');
$pc1_eth = port($pdo, $pc1, 'eth0');
$rule_a = 101;
$rule_b = 102;
$pdo->exec("INSERT INTO hosts (hostid, host, name) VALUES (1, 'R', 'R')");
$pdo->exec("INSERT INTO items (itemid, hostid, name, topology_type) VALUES (101, 1, 'lldp', 1), (102, 1, 'cdp', 1)");

echo "two rows of one rule that resolve to different Devices\n";
foreach ([$pc1, $pc2, $prn] as $d) {
	observation($pdo, $rule_a, $p16, $d);
}
$found = junctions();
check(count($found) === 1 && $found[0]['port_id'] === (string) $p16 && $found[0]['port_name'] === '16' && $found[0]['device_id'] === (string) $R && $found[0]['device_name'] === 'R',
	'one projection, anchored at the reporter Port', $found);
check(members($found) === [(string) $p16 => array_map('strval', [$pc1, $pc2, $prn])], 'its Devices are the three behind the Port, sorted by id', members($found));
check($found[0]['links'] === [], 'no link on the Port: nothing replaced');

echo "one row on a Port: no projection\n";
observation($pdo, $rule_a, $p2, $pc1);
check(!isset(members(junctions())[(string) $p2]), 'one neighbor: none');

echo "several rows of one Device: no projection\n";
observation($pdo, $rule_a, $p5, $pc1);
observation($pdo, $rule_a, $p5, $pc1);
check(!isset(members(junctions())[(string) $p5]), 'two rows of one Device: none');

echo "rows of two different rules: no projection\n";
observation($pdo, $rule_a, $p3, $pc1);
observation($pdo, $rule_b, $p3, $pc2);
check(!isset(members(junctions())[(string) $p3]), 'one Device in the rule of LLDP and another in the rule of CDP: none');
observation($pdo, $rule_b, $p3, $prn);
check(members(junctions())[(string) $p3] === array_map('strval', [$pc2, $prn]), 'a rule with two Devices triggers it by itself; the other rule\'s Device is not a member');

echo "a link on the Port\n";
observation($pdo, $rule_a, $p4, $pc1);
observation($pdo, $rule_a, $p4, $pc2);
check(isset(members(junctions())[(string) $p4]), 'before the link: a projection');
$superseded = edge($pdo, $p4, $pc1_eth, ['discovered_via' => 'lldp', 'superseded_at' => 5]);
check(isset(members(junctions())[(string) $p4]), 'a superseded link does not suppress it');
$found = array_values(array_filter(junctions(), static fn (array $j): bool => $j['port_id'] === (string) $p4))[0];
check($found['links'] === [(string) $pc1 => [(string) $superseded]], 'the link of the Port to a member is listed (it is drawn from the projection)', $found['links']);
$pdo->exec("UPDATE topo_edges SET attrs='".json_encode(['discovered_via' => 'lldp'])."' WHERE id={$superseded}");
check(isset(members(junctions())[(string) $p4]), 'an active discovered link does not suppress it');
$pdo->exec("UPDATE topo_edges SET attrs='".json_encode(['discovered_via' => 'manual'])."' WHERE id={$superseded}");
check(!isset(members(junctions())[(string) $p4]), 'an active manual link suppresses it');
$pdo->exec("UPDATE topo_edges SET attrs='".json_encode(['discovered_via' => 'manual', 'superseded_at' => 9])."' WHERE id={$superseded}");
check(isset(members(junctions())[(string) $p4]), 'a superseded manual link does not');

echo "a snapshot without the rows\n";
$pdo->exec("DELETE FROM topo_observations WHERE local_port_id={$p16} AND device_id IN ({$pc2},{$prn})");
check(!isset(members(junctions())[(string) $p16]), 'one row left: the projection is gone, nothing to clean up');
observation($pdo, $rule_a, $p16, $pc2);
observation($pdo, $rule_a, $p16, $prn);
check(isset(members(junctions())[(string) $p16]), 'the rows are back: the projection is back');

echo "rows of an old snapshot\n";
$pdo->exec("UPDATE topo_observations SET outcome='old_snapshot' WHERE local_port_id={$p16} AND device_id IN ({$pc2},{$prn})");
check(!isset(members(junctions())[(string) $p16]), 'old_snapshot rows are no usable rows: none');
$pdo->exec("UPDATE topo_observations SET outcome='ambiguous' WHERE local_port_id={$p16}");

echo "the reporters\n";
check(members(junctions([$R])) === members(junctions()), 'the reporter given: the same');
check(junctions([$pc1]) === [] && junctions([]) === [], 'another Device, or none: none');

echo "access: the Devices the user can see\n";
$pdo->exec("INSERT INTO hosts (hostid, host, name) VALUES (777, 'h777', 'h777'), (778, 'h778', 'h778')");
$host_pc2 = node($pdo, 'host', ['name' => 'h777']);
$host_prn = node($pdo, 'host', ['name' => 'h778']);
$pdo->exec("UPDATE topo_nodes SET host_ref=777 WHERE id={$host_pc2}");
$pdo->exec("UPDATE topo_nodes SET host_ref=778 WHERE id={$host_prn}");
$pdo->exec("UPDATE topo_nodes SET represented_by_node_id={$host_pc2} WHERE id={$pc2}");
$pdo->exec("UPDATE topo_nodes SET represented_by_node_id={$host_prn} WHERE id={$prn}");
API::$visible = [];
check(!isset(members(junctions())[(string) $p16]), 'two of three Devices are of hosts the user cannot see: one visible Device, no projection', members(junctions()));
API::$visible = [777];
check(members(junctions())[(string) $p16] === [(string) $pc1, (string) $pc2], 'one host visible: the projection has the two visible Devices, not the hidden one', members(junctions()));
API::$visible = [777, 778];
check(count(members(junctions())[(string) $p16]) === 3, 'both hosts visible: all three Devices', members(junctions()));
$pdo->exec("UPDATE topo_nodes SET represented_by_node_id=NULL WHERE id IN ({$pc2},{$prn})");
API::$visible = [];

echo "the view and the neighbors\n";
$view = CTopologyPrototype::getView([], '', null);
check(count(array_filter($view['junctions'], static fn (array $j): bool => $j['port_id'] === (string) $p16)) === 1, 'getView carries the projection');
$ids = array_column($view['devices'], 'id');
check(in_array((string) $pc1, array_map('strval', $ids), true), 'the Devices behind it are in the view');
$neighbors = CTopologyPrototype::getJunctions([$R]);
check(count($neighbors) >= 1 && $neighbors[0]['device_id'] === (string) $R, 'getJunctions([Device]) is what neighbors.get carries for it');
check(CTopologyPrototype::getView([], '', null, true)['junctions'] === [], 'the filter "Possible duplicates only" draws none');

echo "a scoped view\n";
$host_r = node($pdo, 'host', ['name' => 'R']);
$pdo->exec("UPDATE topo_nodes SET host_ref=1 WHERE id={$host_r}");
$pdo->exec("UPDATE topo_nodes SET represented_by_node_id={$host_r} WHERE id={$R}");
API::$visible = [1];
$scoped = CTopologyPrototype::getView([], '1', 1);
$ids = array_map('strval', array_column($scoped['devices'], 'id'));
check(count($scoped['junctions']) >= 1 && in_array((string) $pc1, $ids, true) && in_array((string) $pc2, $ids, true) && in_array((string) $prn, $ids, true),
	'the focus host of the reporter: its projection and the Devices behind it, which are no neighbors of it by a link', $ids);
$other = CTopologyPrototype::getView([], '', 1);
check($other['junctions'] === [] || count($other['junctions']) >= 0, 'no scope given with hops only: no error');

echo "nothing is stored\n";
check((int) $pdo->query("SELECT COUNT(*) FROM topo_nodes WHERE type NOT IN ('device','port','host')")->fetchColumn() === 0, 'no node of another kind');
check((int) $pdo->query("SELECT COUNT(*) FROM topo_edges WHERE type<>'physical_link'")->fetchColumn() === 0, 'no edge of another kind');

echo "all junction tests passed\n";
