#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * test_decisions.php — the operator's decisions as records (topology-1-model.md §6.7.8, 2.106; task 129): the actions write records, a run applies them, the topology can be built again from the
 * snapshots and the records (the rebuild property), a merge record that is deleted separates the Devices, merges chain and a `not_same` wins, the upgrade of data made the old way writes the records.
 *
 * Same method as test_duplicate_candidates.php: rows straight into topo_lld_snapshot, the REAL ingest.php as a subprocess, the operator actions of CTopologyPrototype on the same tables.
 * **It reads the database directly** (the state compared by the rebuild check is in the tables: this checks storage).
 *
 * Usage: test_decisions.php <PDO DSN> <database user> [password]
 * Expects a THROWAWAY database (see test_duplicate_candidates.php).
 */

function fail_test(string $message): void {
	fwrite(STDERR, "FAIL: {$message}\n");
	exit(1);
}

$checks = 0;
function check(bool $condition, string $description, $detail = null): void {
	global $checks;
	$checks++;
	if (!$condition) {
		fail_test($description.($detail !== null ? ' '.json_encode($detail, JSON_UNESCAPED_SLASHES) : ''));
	}
	echo "  ok: {$description}\n";
}

$args = array_slice($argv, 1);
$first_pass_only = in_array('--first-pass', $args, true);
$args = array_values(array_filter($args, static fn (string $a): bool => $a !== '--first-pass'));
if (count($args) < 2 || count($args) > 3) {
	fwrite(STDERR, "Usage: {$argv[0]} [--first-pass] <PDO DSN> <database user> [password]\n");
	exit(1);
}
[$dsn, $user] = $args;
$password = $args[2] ?? '';
if (!preg_match('/dbname=([A-Za-z0-9_]+)/', $dsn, $dbm)) {
	fail_test('the DSN needs dbname=');
}
$dbname = $dbm[1];

$pdo = new PDO($dsn, $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
foreach (['topo_identity_keys', 'topo_observations', 'topo_edges', 'topo_nodes', 'topo_lld_snapshot', 'host_inventory', 'interface',
		'items', 'globalmacro', 'proxy', 'hosts', 'topo_decision'] as $table) {
	$pdo->exec("DROP TABLE IF EXISTS {$table}");
}
$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
$pdo->exec("CREATE TABLE hosts (hostid BIGINT UNSIGNED PRIMARY KEY, host VARCHAR(128) NOT NULL, name VARCHAR(128) NOT NULL DEFAULT '', status INT NOT NULL DEFAULT 0)");
$pdo->exec('CREATE TABLE proxy (proxyid BIGINT UNSIGNED PRIMARY KEY)');
$pdo->exec('CREATE TABLE globalmacro (globalmacroid BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT, macro VARCHAR(255) NOT NULL DEFAULT \'\', value VARCHAR(2048) NOT NULL DEFAULT \'\')');
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


CWebUser::$data = ['userid' => '1', 'username' => 'Admin', 'type' => USER_TYPE_SUPER_ADMIN];


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
	return $response === null ? null : json_decode($response->getData()['main_block'], true);
}

function login(bool $guest = false): void {
	CWebUser::$data = ['userid' => $guest ? '2' : '1', 'username' => $guest ? ZBX_GUEST_USER : 'Admin', 'type' => USER_TYPE_SUPER_ADMIN];
}

const CLOCK = 1_800_000_000;
$R = 'a0:00:00:00:00:01';
$X = 'b0:00:00:00:00:0a';
$Y = 'b0:00:00:00:00:0b';
$LY = 'chassis-y';       // Devices with a chassis that is not a MAC: same_address needs one of the two without a MAC chassis (2.48)
$LZ = 'chassis-z';
$IP = '10.5.5.5';

function reset_all(PDO $pdo): void {
	$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
	foreach (['topo_decision', 'topo_identity_keys', 'topo_observations', 'topo_edges', 'topo_nodes', 'topo_lld_snapshot', 'host_inventory', 'interface', 'items', 'hosts'] as $t) {
		$pdo->exec("DELETE FROM {$t}");
	}
	$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
	API::$visible = [];
}

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

/** The identity keys of a Device: key => replaced (0/1). */
function keys_of(PDO $pdo, int $id): array {
	$stmt = $pdo->prepare('SELECT `key`, replaced FROM topo_identity_keys WHERE device_id = ? ORDER BY `key`');
	$stmt->execute([$id]);
	return array_map('intval', $stmt->fetchAll(PDO::FETCH_KEY_PAIR));
}

function dev_attrs(PDO $pdo, int $id): ?array {
	$raw = scalar($pdo, 'SELECT attrs FROM topo_nodes WHERE id=?', [$id]);
	return $raw === false ? null : json_decode((string) $raw, true);
}

function candidates(PDO $pdo, int $id): array {
	return dev_attrs($pdo, $id)['duplicate_candidates'] ?? [];
}

function dev_count(PDO $pdo): int {
	return (int) scalar($pdo, "SELECT COUNT(*) FROM topo_nodes WHERE type='device'");
}

function exists(PDO $pdo, int $id): bool {
	return (int) scalar($pdo, "SELECT COUNT(*) FROM topo_nodes WHERE id=? AND type='device'", [$id]) === 1;
}

function nbr_row(string $R, string $sysname, ?string $chassis, ?string $ip, string $port = 'Gi0/1', string $source = 'lldp'): array {
	$row = ['if_index' => 1, 'rem_sysname' => $sysname, 'rem_port' => $port, 'rem_port_type' => 'interfaceName', 'source' => $source, 'loc_chassis' => $R];
	if ($chassis !== null) {
		$row += ['rem_chassis' => $chassis, 'rem_chassis_type' => preg_match('/^([0-9a-f]{2}:){5}[0-9a-f]{2}$/i', $chassis) ? 'macAddress' : 'local'];
	}
	if ($ip !== null) {
		$row['rem_mgmt_ip'] = $ip;
	}
	return $row;
}

function setup_r(PDO $pdo, string $R): void {
	add_host($pdo, 101, 'SwR', 'Switch R', '10.0.0.1');
	API::$visible[] = 101;
	snapshot($pdo, 1001, 101, 1, CLOCK, [['if_index' => 1, 'name' => 'Gi0/1', 'loc_chassis' => $R]]);
}


// ============================================================================================================
// helpers of this test
// ============================================================================================================
require_once __DIR__.'/decision_state.php';
$TOOL = __DIR__.'/decision_tool.php';
$MAC = static fn (string $c): string => 'c0:00:00:00:00:'.$c;      // the MAC of a neighbor
$tool = static function (string ...$arguments) use ($dsn, $user, $password, $TOOL): array {
	$cmd = array_merge([PHP_BINARY, $TOOL, '--pdo-dsn', $dsn, '--pdo-user', $user, '--pdo-password='.$password], $arguments);
	$process = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
	$out = stream_get_contents($pipes[1]);
	stream_get_contents($pipes[2]);
	proc_close($process);
	return array_values(array_filter(explode("\n", trim($out))));
};
function records(PDO $pdo, ?string $kind = null): array {
	$rows = $pdo->query('SELECT decisionid, kind, key_a, key_b, hostid, proxyid, keys_b FROM topo_decision ORDER BY decisionid')->fetchAll(PDO::FETCH_ASSOC);
	return array_values(array_filter($rows, static fn (array $r): bool => $kind === null || $r['kind'] === $kind));
}

/** The reporter R (host 101) with four ports and the neighbors $nbr = [if_index => [mac, sysname]] on them (one LLDP row each). */
function network(PDO $pdo, string $R, array $nbr, int $clock = CLOCK): void {
	add_host($pdo, 101, 'SwR', 'Switch R', '10.0.0.1');
	API::$visible[] = 101;
	snapshot($pdo, 1001, 101, 1, $clock, array_map(static fn (int $i): array => ['if_index' => $i, 'name' => 'Gi0/'.$i, 'if_type' => '6', 'admin_status' => '1', 'oper_status' => '1', 'loc_chassis' => $R], [1, 2, 3, 4]));
	$rows = [];
	foreach ($nbr as $if_index => [$mac, $name]) {
		$rows[] = ['if_index' => $if_index, 'rem_chassis' => $mac, 'rem_chassis_type' => 'macAddress', 'rem_sysname' => $name, 'rem_port' => 'Eth'.$if_index, 'rem_port_type' => 'interfaceName', 'source' => 'lldp', 'loc_chassis' => $R];
	}
	snapshot($pdo, 1002, 101, 2, $clock + 10, $rows);
}

/** Delete the topology tables except topo_decision (manual links and acknowledgments: none in these tests) and run once. */
function rebuild(PDO $pdo, string $dsn, string $user, string $password): array {
	$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
	foreach (['topo_identity_keys', 'topo_observations', 'topo_edges', 'topo_nodes'] as $t) {
		$pdo->exec("DELETE FROM {$t}");
	}
	$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
	return run_ingest($dsn, $user, $password);
}

$AM = $MAC('0a'); $BM = $MAC('0b'); $CM = $MAC('0c'); $DM = $MAC('0d');
$KEY = static fn (string $mac): string => 'chassis:mac:'.$mac;

// ============================================================================================================
echo "\n=== 1: the actions write records; the topology is built again from the snapshots and the records ===\n";
reset_all($pdo);
network($pdo, $R, [1 => [$AM, 'a'], 2 => [$BM, 'b'], 3 => [$CM, 'c'], 4 => [$DM, 'd']]);
add_host($pdo, 201, 'HostD', 'Host of D', null);
API::$visible[] = 201;
run_ingest($dsn, $user, $password);
$a = device_id($pdo, $AM); $b = device_id($pdo, $BM); $c = device_id($pdo, $CM); $d = device_id($pdo, $DM);
check($a !== null && $b !== null && $c !== null && $d !== null && records($pdo) === [], 'four neighbor Devices, no record yet');
CTopologyPrototype::mergeDevices((string) $a, (string) $b, 'Admin');
$merge = records($pdo, 'merge');
check(count($merge) === 1 && $merge[0]['key_a'] === $KEY($AM) && $merge[0]['key_b'] === $KEY($BM) && json_decode($merge[0]['keys_b'], true) === [$KEY($BM)], 'Merge writes a merge record: key_a the kept Device, key_b the removed one, keys_b all its keys', $merge);
CTopologyPrototype::dismissDuplicate((string) $a, (string) $c, 'Admin');
$ns = records($pdo, 'not_same');
check(count($ns) === 1 && [$ns[0]['key_a'], $ns[0]['key_b']] === [$KEY($AM), $KEY($CM)], 'Not the same device writes a not_same record by the natural keys (sorted)', $ns);
CTopologyPrototype::promote((string) $d, 'host', '201');
$as = records($pdo, 'associate');
check(count($as) === 1 && $as[0]['key_a'] === $KEY($DM) && (string) $as[0]['hostid'] === '201' && $as[0]['proxyid'] === null, 'a manual association writes an associate record', $as);
$r = run_ingest($dsn, $user, $password);
check(($r['summary']['decisions']['merge'] ?? -1) === 1 && ($r['summary']['decisions_ambiguous'] ?? [1]) === [] && ($r['summary']['decisions_conflict'] ?? [1]) === [], 'a run reads the records and lists nothing');
check(device_id($pdo, $BM) === null && dev_attrs($pdo, $a)['replaced_chassis'] === [$BM], 'the removed Device is gone; its chassis is a replaced chassis of the kept one (the visible result of the merge is as before)');
check(array_column(dev_attrs($pdo, $a)['duplicate_dismissed'] ?? [], 'device_id') === [$c], 'attrs.duplicate_dismissed is computed from the records and lists the pair');
$view1 = state_view($pdo);
$stable = static fn (PDO $pdo): string => md5(json_encode([
	$pdo->query('SELECT id, type, device_id, attrs FROM topo_nodes ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
	$pdo->query('SELECT id, src_id, dst_id, attrs FROM topo_edges ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
	$pdo->query('SELECT device_id, `key`, replaced FROM topo_identity_keys ORDER BY device_id, `key`')->fetchAll(PDO::FETCH_ASSOC)]));
$fp = $stable($pdo);
$r = run_ingest($dsn, $user, $password);
check($stable($pdo) === $fp, 'a second run changes nothing (ids and values; the time of the last write is not a value: two claims of one merged Device each refresh its display attributes)');
$r = rebuild($pdo, $dsn, $user, $password);
$view2 = state_view($pdo);
check(diff_views($view1, $view2) === [], 'the rebuild: the Devices, their keys, replaced chassis, representation, candidates, dismissed pairs and the active links are equal to those before', diff_views($view1, $view2));
check(($r['summary']['decisions_ambiguous'] ?? [1]) === [] && records($pdo) !== [], 'the rebuild lists nothing and the records stay');
check(count($view2['devices']) === 4 && array_key_exists($KEY($AM), $view2['devices']) && !array_key_exists($KEY($BM), $view2['devices']), 'one Device for A and B after the rebuild (4 Devices with the reporter)', array_keys($view2['devices']));
$d2 = device_id($pdo, $DM);
check($d2 !== null && scalar($pdo, 'SELECT represented_by_matched_by FROM topo_nodes WHERE id=?', [$d2]) === 'manual', 'the association is applied again (matched_by manual)');

// ============================================================================================================
echo "\n=== 2: separation: the merge record is deleted, the next run finds two Devices ===\n";
$out = $tool('delete-merge', $KEY($BM));
check(count($out) === 1 && records($pdo, 'merge') === [], 'the tool deletes the merge record', $out);
$r = run_ingest($dsn, $user, $password);
$a = device_id($pdo, $AM); $b = device_id($pdo, $BM);
$keys_a = array_keys($pdo->query('SELECT `key` AS k, 1 FROM topo_identity_keys WHERE device_id='.(int) $a)->fetchAll(PDO::FETCH_KEY_PAIR));
$keys_b = array_keys($pdo->query('SELECT `key` AS k, 1 FROM topo_identity_keys WHERE device_id='.(int) $b)->fetchAll(PDO::FETCH_KEY_PAIR));
check($a !== null && $b !== null && $a !== $b, 'two Devices again', [$a, $b]);
check($keys_a === [$KEY($AM)] && $keys_b === [$KEY($BM)], 'each Device has its own key', [$keys_a, $keys_b]);
$links = state_view($pdo)['links'];
check(count(array_filter($links, static fn (string $l): bool => str_contains($l, $KEY($AM)))) === 1 && count(array_filter($links, static fn (string $l): bool => str_contains($l, $KEY($BM)))) === 1, 'each Device has its own link, from the snapshots', $links);
check(!isset(dev_attrs($pdo, $a)['replaced_chassis']) && !isset(dev_attrs($pdo, $a)['merged_keys']), 'the kept Device has no replaced chassis and no merged keys of the removed one any more', dev_attrs($pdo, $a));
check(($r['summary']['decisions_ambiguous'] ?? [1]) === [], 'nothing is listed');

// ============================================================================================================
echo "\n=== 3: chain and conflict ===\n";
reset_all($pdo);
network($pdo, $R, [1 => [$AM, 'a'], 2 => [$BM, 'b'], 3 => [$CM, 'c']]);
run_ingest($dsn, $user, $password);
$ins = $pdo->prepare('INSERT INTO topo_decision (kind, key_a, key_b, keys_b, created_at, norm_version) VALUES (?, ?, ?, ?, ?, ?)');
$ins->execute(['merge', $KEY($AM), $KEY($BM), json_encode([$KEY($BM)]), time(), 24]);
$ins->execute(['merge', $KEY($BM), $KEY($CM), json_encode([$KEY($CM)]), time(), 24]);
$r = rebuild($pdo, $dsn, $user, $password);
$view = state_view($pdo);
$not_reporter = static fn (array $v): array => array_values(array_filter(array_keys($v['devices']), static fn (string $k): bool => $k !== 'chassis:mac:'.$R));
check(count($not_reporter($view)) === 1, 'A = B and B = C: one Device for the three (and the reporter)', array_keys($view['devices']));
check(count($view['devices'][$not_reporter($view)[0] ?? '']['keys'] ?? []) === 3, 'it holds the three keys', $view['devices'][$not_reporter($view)[0] ?? ''] ?? null);
$ins->execute(['not_same', $KEY($AM), $KEY($CM), null, time(), 24]);
$r = rebuild($pdo, $dsn, $user, $password);
$view = state_view($pdo);
check(count($not_reporter($view)) === 2, 'with a not_same of A and C the three are two Devices: A and C are not joined', $not_reporter($view));
check(count($r['summary']['decisions_conflict'] ?? []) === 1, 'the conflict is listed in the run summary', $r['summary']['decisions_conflict'] ?? null);
check(count(array_filter($r['summary']['attention'] ?? [], static fn (array $l): bool => $l['kind'] === 'decision_conflict')) === 1, 'and in "Needs attention"', array_column($r['summary']['attention'] ?? [], 'kind'));
$sizes = array_map(static fn (string $k): int => count($view['devices'][$k]['keys']), $not_reporter($view));
sort($sizes);
check($sizes === [1, 2], 'A and B are one Device (the merge before the conflict holds), C is the other one', $sizes);

// ============================================================================================================
echo "\n=== 4: a key that finds several Devices: the record does nothing and is listed ===\n";
reset_all($pdo);
network($pdo, $R, [1 => [$AM, 'a'], 2 => [$BM, 'b']]);
run_ingest($dsn, $user, $password);
$pdo->prepare('INSERT INTO topo_decision (kind, key_a, key_b, keys_b, created_at, norm_version) VALUES (?, ?, ?, ?, ?, ?)')->execute(['merge', $KEY($AM), $KEY($BM), json_encode([$KEY($BM)]), time(), 24]);
$r = run_ingest($dsn, $user, $password);
check(device_id($pdo, $AM) !== null && device_id($pdo, $BM) !== null && device_id($pdo, $AM) !== device_id($pdo, $BM), 'the two Devices exist as two (no guess)');
check(count($r['summary']['decisions_ambiguous'] ?? []) >= 1 && count(array_filter($r['summary']['attention'] ?? [], static fn (array $l): bool => $l['kind'] === 'decision_ambiguous')) >= 1, 'the record is listed in "Needs attention"', $r['summary']['decisions_ambiguous'] ?? null);
$pdo->exec('DELETE FROM topo_decision');
$pdo->prepare('INSERT INTO topo_decision (kind, key_a, key_b, created_at, norm_version) VALUES (?, ?, ?, ?, ?)')->execute(['merge', 'chassis:mac:ff:ff:ff:00:00:99', 'chassis:mac:ff:ff:ff:00:00:98', time(), 24]);
$r = run_ingest($dsn, $user, $password);
check(($r['summary']['decisions_ambiguous'] ?? [1]) === [] && records($pdo) !== [], 'a record whose keys find no Device does nothing and stays');

// ============================================================================================================
echo "\n=== 5: accept new chassis ===\n";
reset_all($pdo);
$M1 = 'd0:00:00:00:00:01'; $M2 = 'd0:00:00:00:00:02';
add_host($pdo, 101, 'SwR', 'Switch R', '10.0.0.1');
API::$visible[] = 101;
add_host($pdo, 102, 'SwS', 'Switch S', '10.0.0.2');
API::$visible[] = 102;
snapshot($pdo, 1001, 101, 1, CLOCK, [['if_index' => 1, 'name' => 'Gi0/1', 'loc_chassis' => $R]]);
snapshot($pdo, 1011, 102, 1, CLOCK, [['if_index' => 1, 'name' => 'Gi0/1', 'loc_chassis' => $M1]]);
run_ingest($dsn, $user, $password);
snapshot($pdo, 1011, 102, 1, CLOCK + 100, [['if_index' => 1, 'name' => 'Gi0/1', 'loc_chassis' => $M2]]);
run_ingest($dsn, $user, $password);
$s = device_id($pdo, $M1);
check($s !== null && array_column(candidates($pdo, $s), 'reason') === ['reporter_chassis_change'], 'the reporter reports a new chassis: a candidate reporter_chassis_change');
CTopologyPrototype::acceptChassis((string) $s, 'Admin');
$ac = records($pdo, 'accept_chassis');
check(count($ac) === 1 && $ac[0]['key_a'] === $KEY($M1) && $ac[0]['key_b'] === $KEY($M2), 'Accept new chassis writes an accept_chassis record: key_a the Device before, key_b the new chassis key', $ac);
run_ingest($dsn, $user, $password);
$view1 = state_view($pdo);
$r = rebuild($pdo, $dsn, $user, $password);
$view2 = state_view($pdo);
check(diff_views($view1, $view2) === [], 'the rebuild gives the same Device: the new chassis is its key, the old one a replaced chassis', diff_views($view1, $view2));
check(($view2['devices'][$KEY($M2)]['replaced_chassis'] ?? null) === [$M1] && in_array($KEY($M1).':1', $view2['devices'][$KEY($M2)]['keys'] ?? [], true), 'the old chassis is replaced on the Device', $view2['devices'][$KEY($M2)] ?? null);

// ============================================================================================================
echo "\n=== 6: the upgrade of data made the old way ===\n";
reset_all($pdo);
network($pdo, $R, [1 => [$AM, 'a'], 2 => [$BM, 'b'], 3 => [$CM, 'c'], 4 => [$DM, 'd']]);
add_host($pdo, 201, 'HostD', 'Host of D', null);
API::$visible[] = 201;
run_ingest($dsn, $user, $password);
$a = device_id($pdo, $AM); $b = device_id($pdo, $BM); $c = device_id($pdo, $CM); $d = device_id($pdo, $DM);
CTopologyPrototype::mergeDevices((string) $a, (string) $b, 'Admin');
CTopologyPrototype::dismissDuplicate((string) $a, (string) $c, 'Admin');
CTopologyPrototype::promote((string) $d, 'host', '201');
run_ingest($dsn, $user, $password);
$view1 = state_view($pdo);
$pdo->exec('DELETE FROM topo_decision');      // the data as an earlier build left it: the Devices, their keys and attrs, no record
require_once __DIR__.'/decisions_upgrade.php';
$up = decisions_upgrade($pdo);
check($up['written'] === ['not_same' => 1, 'associate' => 1, 'merge' => 1], 'the upgrade writes a record for the merge, the dismissed pair and the association', $up);
check($up['cannot_separate'] === [] && $up['skipped_no_natural_key'] === [], 'nothing is listed as not separable');
$again = decisions_upgrade($pdo);
check($again['written'] === ['not_same' => 0, 'associate' => 0, 'merge' => 0], 'the upgrade can run again: nothing is written twice');
$r = rebuild($pdo, $dsn, $user, $password);
$view2 = state_view($pdo);
check(diff_views($view1, $view2) === [], 'a rebuild from the upgraded records gives the same state', diff_views($view1, $view2));
// the keys that moved are marked, so that the upgraded merge can be separated: delete its record, the next run finds two Devices
$out = $tool('delete-merge', $KEY($BM));
run_ingest($dsn, $user, $password);
check(count($out) === 1 && device_id($pdo, $AM) !== null && device_id($pdo, $BM) !== null && device_id($pdo, $AM) !== device_id($pdo, $BM), 'the merge made the old way and upgraded is separated when its record is deleted');
// a merge from before task 056: the replaced chassis is in the attrs, there is no key row
$a2 = device_id($pdo, $AM);
$attrs = dev_attrs($pdo, $a2);
$attrs['replaced_chassis'] = array_merge($attrs['replaced_chassis'] ?? [], ['e0:00:00:00:00:77']);
$pdo->prepare('UPDATE topo_nodes SET attrs=? WHERE id=?')->execute([json_encode($attrs), $a2]);
$up = decisions_upgrade($pdo);
check(count($up['cannot_separate']) === 1 && $up['cannot_separate'][0]['replaced_chassis'] === ['e0:00:00:00:00:77'], 'a replaced chassis that no key row names is listed: that merge cannot be separated', $up['cannot_separate']);

// ============================================================================================================
echo "\n=== 7: the Device of a merge keeps the chassis of the kept side, whichever claim comes first ===\n";
foreach ([['kept mac, removed local', true], ['kept local, removed mac', false]] as [$label, $keep_mac]) {
	reset_all($pdo);
	network($pdo, $R, [1 => [$AM, 'p']]);
	$rows = json_decode((string) scalar($pdo, 'SELECT rows_json FROM topo_lld_snapshot WHERE itemid = 1002'), true);
	$rows[] = ['if_index' => 2, 'rem_chassis' => 'q-chassis', 'rem_chassis_type' => 'local', 'rem_sysname' => 'q', 'rem_port' => 'Eth2', 'rem_port_type' => 'interfaceName', 'source' => 'lldp', 'loc_chassis' => $R];
	snapshot($pdo, 1002, 101, 2, CLOCK + 10, $rows);
	run_ingest($dsn, $user, $password);
	$p = device_id($pdo, $AM); $q = device_id($pdo, 'q-chassis');
	[$keep, $remove] = $keep_mac ? [$p, $q] : [$q, $p];
	CTopologyPrototype::mergeDevices((string) $keep, (string) $remove, 'Admin');
	run_ingest($dsn, $user, $password);
	$view1 = state_view($pdo);
	$r = rebuild($pdo, $dsn, $user, $password);
	$view2 = state_view($pdo);
	check(diff_views($view1, $view2) === [], "{$label}: the rebuild gives the same Device (the chassis of the kept side is its chassis)", diff_views($view1, $view2));
}

// ============================================================================================================
echo "\n=== 8: delete a merged Device to fix a wrong merge (FR-C O-13, model 2.122) ===\n";
reset_all($pdo);
network($pdo, $R, [1 => [$AM, 'a'], 2 => [$BM, 'b'], 3 => [$CM, 'c']]);
run_ingest($dsn, $user, $password);
$a = device_id($pdo, $AM); $b = device_id($pdo, $BM); $c = device_id($pdo, $CM);
CTopologyPrototype::mergeDevices((string) $a, (string) $b, 'Admin');
CTopologyPrototype::dismissDuplicate((string) $a, (string) $c, 'Admin');
check(count(records($pdo, 'merge')) === 1 && count(records($pdo, 'not_same')) === 1, 'a merge record and a not_same record name the merged Device');
$result = CTopologyPrototype::deleteDevice((string) $a, 'Admin');
check(records($pdo) === [], 'Delete Device removes the merge and the not_same record that name it', records($pdo));
run_ingest($dsn, $user, $password);
$view = state_view($pdo);
$new_a = device_id($pdo, $AM); $new_b = device_id($pdo, $BM);
check($new_a !== null && $new_b !== null && $new_a !== $new_b && $new_a > $a, 'the run makes one new Device for each device that the data reports (a new id for A, B apart)', [$new_a, $new_b]);
check(count(array_filter($view['links'], static fn (string $l): bool => str_contains($l, $KEY($AM)))) === 1 && count(array_filter($view['links'], static fn (string $l): bool => str_contains($l, $KEY($BM)))) === 1, 'each has its own key and its own link', $view['links']);
$fp = $stable($pdo);
run_ingest($dsn, $user, $password);
check($stable($pdo) === $fp && device_id($pdo, $AM) === $new_a && device_id($pdo, $BM) === $new_b, 'the second run changes nothing; the Devices are not joined again');

echo "\nAll decision checks passed ({$checks}).\n";
