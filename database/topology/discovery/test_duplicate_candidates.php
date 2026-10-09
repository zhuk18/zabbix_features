#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * test_duplicate_candidates.php — topology-1-model.md §6.7.5 "Duplicate candidates and operator merge" and §6.4 step 1 (chassis change, RMA):
 * ingest lists possible duplicates in attrs.duplicate_candidates (split_identity, reporter_chassis_change) and never merges on weaker evidence
 * than the reporter's own identity; the operator merges (CTopologyPrototype::mergeDevices), accepts a new chassis or dismisses.
 *
 * Same method as test_snapshot_ingest.php. Writes rows straight into topo_lld_snapshot (what the server's LLD worker would store) and runs the REAL
 * ingest.php as a subprocess after every change, so the actual CLI path is exercised end to end — no test-only
 * hook, no reimplementation of the algorithm here.
 *
 * Usage: test_duplicate_candidates.php <PDO DSN> <database user> [password]
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
		'items', 'globalmacro', 'proxy', 'hosts'] as $table) {
	$pdo->exec("DROP TABLE IF EXISTS {$table}");
}
$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
$pdo->exec("CREATE TABLE hosts (hostid BIGINT UNSIGNED PRIMARY KEY, host VARCHAR(128) NOT NULL, name VARCHAR(128) NOT NULL DEFAULT '', status INT NOT NULL DEFAULT 0)");
$pdo->exec('CREATE TABLE proxy (proxyid BIGINT UNSIGNED PRIMARY KEY)');
$pdo->exec('CREATE TABLE globalmacro (globalmacroid BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT, macro VARCHAR(255) NOT NULL DEFAULT \'\', value VARCHAR(2048) NOT NULL DEFAULT \'\')');
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
$IP = '10.5.5.5';

function reset_all(PDO $pdo): void {
	$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
	foreach (['topo_identity_keys', 'topo_observations', 'topo_edges', 'topo_nodes', 'topo_lld_snapshot', 'host_inventory', 'interface', 'items', 'hosts'] as $t) {
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
		$row += ['rem_chassis' => $chassis, 'rem_chassis_type' => 'macAddress'];
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
echo "\n=== 1: split_identity — the candidate on both Devices, nothing merged ===\n";
reset_all($pdo);
setup_r($pdo, $R);
run_ingest($dsn, $user, $password);
$A = device($pdo, $X, null, 'N');
$B = device($pdo, null, $IP, 'N');
snapshot($pdo, 1002, 101, 2, CLOCK + 50, [nbr_row($R, 'N', $X, $IP)]);
$r = run_ingest($dsn, $user, $password);
$want_a = [['device_id' => $B, 'reason' => 'split_identity', 'key' => 'mgmt_ip', 'value' => $IP]];
$want_b = [['device_id' => $A, 'reason' => 'split_identity', 'key' => 'mgmt_ip', 'value' => $IP]];
check(exists($pdo, $A) && exists($pdo, $B), 'both Devices stay (a neighbor row never merges)');
check(candidates($pdo, $A) === $want_a && candidates($pdo, $B) === $want_b, 'the candidate split_identity is on A (naming B) and on B (naming A)');
$fp = fingerprint($pdo);
for ($i = 0; $i < 5; $i++) {
	run_ingest($dsn, $user, $password);
}
check(fingerprint($pdo) === $fp, '5 more runs keep the same candidates (nothing changes)');
snapshot($pdo, 1002, 101, 2, CLOCK + 60, []);
run_ingest($dsn, $user, $password, ['SwR']);
check(candidates($pdo, $A) === $want_a, 'a partial run does not touch the list');
run_ingest($dsn, $user, $password);
check(candidates($pdo, $A) === [] && candidates($pdo, $B) === [], 'a full run without the evidence removes the entry');

// ============================================================================================================
echo "\n=== 2: 'Not the same device' and its revocation ===\n";
snapshot($pdo, 1002, 101, 2, CLOCK + 70, [nbr_row($R, 'N', $X, $IP)]);
run_ingest($dsn, $user, $password);
check(candidates($pdo, $A) === $want_a, 'the candidate is back');
CTopologyPrototype::dismissDuplicate((string) $A, (string) $B, 'Admin');
$dismissed_a = dev_attrs($pdo, $A)['duplicate_dismissed'] ?? [];
check(count($dismissed_a) === 1 && $dismissed_a[0]['device_id'] === $B && $dismissed_a[0]['by'] === 'Admin' && isset($dismissed_a[0]['at'])
	&& (dev_attrs($pdo, $B)['duplicate_dismissed'][0]['device_id'] ?? null) === $A, 'the other id is appended to duplicate_dismissed of both, with at and by');
$before = [json_encode(dev_attrs($pdo, $A)['duplicate_dismissed']), json_encode(dev_attrs($pdo, $B)['duplicate_dismissed'])];
for ($i = 0; $i < 3; $i++) {
	run_ingest($dsn, $user, $password);
}
check(candidates($pdo, $A) === [] && candidates($pdo, $B) === [], 'no candidate on later runs');
check($before === [json_encode(dev_attrs($pdo, $A)['duplicate_dismissed']), json_encode(dev_attrs($pdo, $B)['duplicate_dismissed'])], 'ingest keeps duplicate_dismissed byte-identical');
CTopologyPrototype::revokeDismissal((string) $A, (string) $B);
run_ingest($dsn, $user, $password);
check(candidates($pdo, $A) === $want_a && !isset(dev_attrs($pdo, $A)['duplicate_dismissed']), 'revoke: the candidate is listed again');

// ============================================================================================================
echo "\n=== 3: operator merge (keep A, remove B): ports, links, observations and shadow_ack move ===\n";
function add_port(PDO $pdo, int $device, int $if, string $name, bool $pseudo): int {
	$pdo->prepare("INSERT INTO topo_nodes (type, device_id, attrs, created_at, updated_at) VALUES ('port', ?, ?, 1, 1)")
		->execute([$device, json_encode(['if_index' => $if, 'name' => $name, 'pseudo' => $pseudo, 'learned_macs' => [], 'zabbix_itemids' => []])]);
	return (int) $pdo->lastInsertId();
}

reset_all($pdo);
setup_r($pdo, $R);
run_ingest($dsn, $user, $password);
$rp = (int) scalar($pdo, "SELECT id FROM topo_nodes WHERE type='port' AND JSON_UNQUOTE(JSON_EXTRACT(attrs,'\$.name'))='Gi0/1'");
$A = device($pdo, $X, null, 'N');
$B = device($pdo, null, $IP, 'N');
$Z = device($pdo, 'c0:00:00:00:00:0c', null, 'Z');
$a_q1 = add_port($pdo, $A, -5, 'q1', true);          // the allocated index of A's pseudo port equals B's, with another name
$b_p1 = add_port($pdo, $B, -5, 'p1', true);
$a_same = add_port($pdo, $A, -9, 'Uplink-1', true);   // the same normalized name on both: one port after the merge
$b_same = add_port($pdo, $B, -11, 'UPLINK-1', true);
$z_p = add_port($pdo, $Z, 1, 'eth0', false);
$r_p2 = add_port($pdo, (int) scalar($pdo, 'SELECT device_id FROM topo_nodes WHERE id=?', [$rp]), 2, 'Gi0/2', false);
$pdo->prepare("INSERT INTO topo_edges (type, src_id, dst_id, attrs, created_at) VALUES ('physical_link', ?, ?, ?, 1)")
	->execute([min($rp, $b_p1), max($rp, $b_p1), json_encode(['discovered_via' => 'lldp', 'last_seen' => CLOCK, 'last_seen_src' => CLOCK])]);
$link = (int) $pdo->lastInsertId();
$ack = [['device_id' => $B, 'remote_key' => 'ip:'.$IP, 'at' => CLOCK, 'by' => 'Admin'], ['device_id' => 999, 'remote_key' => 'other', 'at' => 5, 'by' => 'Someone']];
$pdo->prepare("INSERT INTO topo_edges (type, src_id, dst_id, attrs, created_at) VALUES ('physical_link', ?, ?, ?, 1)")
	->execute([min($r_p2, $z_p), max($r_p2, $z_p), json_encode(['discovered_via' => 'manual', 'last_seen' => 1, 'shadow_ack' => $ack])]);
$manual = (int) $pdo->lastInsertId();
snapshot($pdo, 1002, 101, 2, CLOCK + 5, []);
$pdo->prepare("INSERT INTO topo_observations (itemid, local_port_id, remote_key, remote_attrs, outcome, device_id, first_seen, last_seen) VALUES (1002, ?, 'ip:10.5.5.5', '{}', 'applied', ?, 1, 1)")
	->execute([$rp, $B]);
$pdo->prepare('UPDATE topo_nodes SET attrs = ? WHERE id = ?')->execute([json_encode(['sysname' => 'N', 'chassis_id' => $X, 'mgmt_ip' => null, 'mac' => null, 'vendor' => 'unknown', 'last_seen' => 1,
	'duplicate_candidates' => [['device_id' => $B, 'reason' => 'split_identity', 'key' => 'mgmt_ip', 'value' => $IP]]]), $A]);

$result = CTopologyPrototype::mergeDevices((string) $A, (string) $B, 'Admin');
check($result === ['kept' => $A, 'removed' => $B] && exists($pdo, $A) && !exists($pdo, $B), 'B is merged into A: B deleted, A kept');
check((int) scalar($pdo, 'SELECT device_id FROM topo_nodes WHERE id=?', [$b_p1]) === $A || scalar($pdo, 'SELECT COUNT(*) FROM topo_nodes WHERE id=?', [$b_p1]) == 1,
	'the pseudo port p1 (another name) moved to A');
$a_ports = array_map(static fn ($p) => $p['attrs'], $pdo->query("SELECT attrs FROM topo_nodes WHERE type='port' AND device_id={$A} ORDER BY id")->fetchAll(PDO::FETCH_ASSOC));
$names = array_map(static fn ($a) => json_decode($a, true)['name'].'/'.json_decode($a, true)['if_index'], $a_ports);
check(count($a_ports) === 3, 'ports: q1 and p1 stay two ports (the allocated if_index was the same, the names differ), Uplink-1 and UPLINK-1 are one: '.implode(', ', $names));
$indexes = array_map(static fn ($a) => json_decode($a, true)['if_index'], $a_ports);
check(count(array_unique($indexes)) === 3, 'no two ports of A share an if_index');
check((int) scalar($pdo, "SELECT COUNT(*) FROM topo_edges WHERE id=? AND type='physical_link'", [$link]) === 1
	&& in_array((int) scalar($pdo, 'SELECT src_id FROM topo_edges WHERE id=?', [$link]), [$b_p1], true) | in_array((int) scalar($pdo, 'SELECT dst_id FROM topo_edges WHERE id=?', [$link]), [$b_p1], true),
	'the link of B is the same edge, on the moved port');
check((int) scalar($pdo, 'SELECT device_id FROM topo_observations WHERE itemid=1002') === $A, 'the observation that named B names A');
$attrs = json_decode((string) scalar($pdo, 'SELECT attrs FROM topo_edges WHERE id=?', [$manual]), true);
check($attrs['shadow_ack'][0]['device_id'] === $A && $attrs['shadow_ack'][0]['remote_key'] === 'ip:'.$IP && $attrs['shadow_ack'][0]['by'] === 'Admin'
	&& $attrs['shadow_ack'][1] === $ack[1], 'the shadow_ack that named B names A; every other byte is the same');
$k = dev_attrs($pdo, $A);
check($k['chassis_id'] === $X && $k['mgmt_ip'] === $IP && !isset($k['duplicate_candidates']) && !isset($k['replaced_chassis']), 'A keeps its chassis, B\'s IP fills A\'s empty mgmt_ip, no stale candidate, no replaced chassis');
check(keys_of($pdo, $A) === ['chassis:mac:'.$X => 0, 'ip:server:'.$IP => 0], 'keys: B\'s keys moved to A (the chassis of A and the address of B)');
$fp = fingerprint($pdo);
run_ingest($dsn, $user, $password);
check(!exists($pdo, $B) && candidates($pdo, $A) === [], 'the next ingest finds no candidate and does not bring B back');

echo "  -- refused when both have a represented_by\n";
reset_all($pdo);
add_host($pdo, 111, 'HostA', 'Host A', null);
add_host($pdo, 112, 'HostB', 'Host B', null);
$A = device($pdo, $X, null, 'A');
$B = device($pdo, null, $IP, 'B');
foreach ([[$A, 111], [$B, 112]] as [$dev, $hostid]) {
	$pdo->prepare("INSERT INTO topo_nodes (type, host_ref, attrs, created_at, updated_at) VALUES ('host', ?, '{}', 1, 1)")->execute([$hostid]);
	$pdo->prepare("UPDATE topo_nodes SET represented_by_node_id = ?, represented_by_matched_by = 'manual', represented_by_at = 1 WHERE id = ?")->execute([(int) $pdo->lastInsertId(), $dev]);
}
try {
	CTopologyPrototype::mergeDevices((string) $A, (string) $B, 'Admin');
	check(false, 'merge of two Devices that both have a represented_by is refused');
}
catch (Exception $e) {
	check(exists($pdo, $A) && exists($pdo, $B), 'merge of two Devices that both have a represented_by is refused ('.$e->getMessage().')');
}

// ============================================================================================================
echo "\n=== 4: reporter chassis change (RMA): S keeps X, candidates on S and C; both orders agree ===\n";
function rma_setup(PDO $pdo, string $R, string $X, string $Y): void {
	reset_all($pdo);
	setup_r($pdo, $R);
	add_host($pdo, 102, 'SwS', 'Switch S', '10.0.0.30');
	API::$visible[] = 102;
	snapshot($pdo, 2001, 102, 1, CLOCK, [['if_index' => 1, 'name' => 'Gi0/1', 'loc_chassis' => $X]]);
	snapshot($pdo, 1002, 101, 2, CLOCK + 10, [nbr_row($R, 'S', $X, null)]);
}
$states = [];
foreach (['101,102' => 'R first', '102,101' => 'S first'] as $order => $label) {
	rma_setup($pdo, $R, $X, $Y);
	run_ingest($dsn, $user, $password, [], $order);
	$S = (int) device_id($pdo, $X);
	check($S > 0 && dev_count($pdo) === 2, "{$label}: baseline, S has chassis X");
	// the host now reports chassis Y, and the neighbors advertise Y
	snapshot($pdo, 2001, 102, 1, CLOCK + 100, [['if_index' => 1, 'name' => 'Gi0/1', 'loc_chassis' => $Y]]);
	snapshot($pdo, 1002, 101, 2, CLOCK + 110, [nbr_row($R, 'S', $Y, null)]);
	$r = run_ingest($dsn, $user, $password, [], $order);
	$C = (int) device_id($pdo, $Y);
	check(dev_attrs($pdo, $S)['chassis_id'] === $X && $C > 0 && $C !== $S, "{$label}: S is unchanged (chassis X), the neighbors made Device C for Y");
	$on_s = candidates($pdo, $S);
	$on_c = candidates($pdo, $C);
	check($on_s === [['device_id' => $C, 'reason' => 'reporter_chassis_change', 'key' => 'chassis_id', 'value' => $Y]]
		&& $on_c === [['device_id' => $S, 'reason' => 'reporter_chassis_change', 'key' => 'chassis_id', 'value' => $Y]], "{$label}: reporter_chassis_change on S and on C");
	$states[$label] = [dev_count($pdo), array_map(static fn ($c) => $c['reason'], $on_s), array_map(static fn ($c) => $c['reason'], $on_c)];
}
check($states['R first'] === $states['S first'], 'both reporter orders give the same state');

echo "  -- operator merge (keep S, remove C)\n";
$fp_before = null;
$result = CTopologyPrototype::mergeDevices((string) $S, (string) $C, 'Admin');
$k = dev_attrs($pdo, $S);
check($k['chassis_id'] === $Y && ($k['replaced_chassis'] ?? null) === [$X] && !exists($pdo, $C), 'S has chassis Y, replaced_chassis [X], C is deleted (K\'s own reporter reports Y)');
check(keys_of($pdo, $S)['chassis:mac:'.$X] === 1 && keys_of($pdo, $S)['chassis:mac:'.$Y] === 0,
	'keys: the chassis key of X is replaced, the one of Y is not');
run_ingest($dsn, $user, $password);
check(dev_count($pdo) === 2 && count(array_filter(candidates($pdo, $S))) === 0, 'after the next ingest: C\'s link is on S, no candidate');
$s_link = (int) scalar($pdo, "SELECT COUNT(*) FROM topo_edges WHERE type='physical_link' AND JSON_EXTRACT(attrs,'\$.superseded_at') IS NULL");
check($s_link === 1, 'one active link between R and S');
snapshot($pdo, 1002, 101, 2, CLOCK + 200, [nbr_row($R, 'S', $X, null)]);               // a stale neighbor row with the old chassis
run_ingest($dsn, $user, $password);
check(dev_count($pdo) === 2 && (int) scalar($pdo, "SELECT COUNT(*) FROM topo_edges WHERE type='physical_link' AND JSON_EXTRACT(attrs,'\$.superseded_at') IS NULL") === 1,
	'a stale row with chassis X finds S (replaced_chassis): no new Device');
$fp = fingerprint($pdo);
for ($i = 0; $i < 5; $i++) {
	run_ingest($dsn, $user, $password);
}
check(fingerprint($pdo) === $fp, '5 more runs change nothing');

echo "  -- accept new chassis (no Device C)\n";
rma_setup($pdo, $R, $X, $Y);
run_ingest($dsn, $user, $password);
snapshot($pdo, 2001, 102, 1, CLOCK + 100, [['if_index' => 1, 'name' => 'Gi0/1', 'loc_chassis' => $Y]]);
run_ingest($dsn, $user, $password);
$S = (int) device_id($pdo, $X);
check(candidates($pdo, $S) === [['device_id' => null, 'reason' => 'reporter_chassis_change', 'key' => 'chassis_id', 'value' => $Y]], 'the candidate on S has no Device C');
CTopologyPrototype::acceptChassis((string) $S, 'Admin');
$k = dev_attrs($pdo, $S);
check($k['chassis_id'] === $Y && $k['replaced_chassis'] === [$X], 'S has chassis Y, replaced_chassis [X]');
check(keys_of($pdo, $S)['chassis:mac:'.$X] === 1 && keys_of($pdo, $S)['chassis:mac:'.$Y] === 0, 'keys: accept new chassis marks the old chassis key replaced and adds the new one');
run_ingest($dsn, $user, $password);
check(candidates($pdo, $S) === [] && dev_count($pdo) === 2, 'no candidate after the next ingest, no new Device');


// ============================================================================================================
echo "\n=== 5: the three endpoints (same permission as manual link editing) ===\n";
reset_all($pdo);
$A = device($pdo, $X, null, 'A');
$B = device($pdo, null, $IP, 'B');
$C = device($pdo, $Y, null, 'C');
login(true);
check(call(CControllerTopologyDevicesMerge::class, ['keep_id' => (string) $A, 'remove_id' => (string) $B]) === 'denied', 'the guest user is denied: merge');
check(call(CControllerTopologyDevicesAcceptChassis::class, ['id' => (string) $A]) === 'denied', 'the guest user is denied: accept-chassis');
check(call(CControllerTopologyDevicesDismissDuplicate::class, ['a' => (string) $A, 'b' => (string) $B]) === 'denied', 'the guest user is denied: dismiss-duplicate');
check(exists($pdo, $B), 'and nothing was written');
login();
$r = call(CControllerTopologyDevicesDismissDuplicate::class, ['a' => (string) $A, 'b' => (string) $C]);
check(($r['success'] ?? false) === true && isset(dev_attrs($pdo, $A)['duplicate_dismissed']), 'dismiss-duplicate: success, written on both');
$r = call(CControllerTopologyDevicesDismissDuplicate::class, ['a' => (string) $A, 'b' => (string) $C, 'revoke' => 1]);
check(($r['success'] ?? false) === true && !isset(dev_attrs($pdo, $A)['duplicate_dismissed']), 'dismiss-duplicate with revoke: removed');
$r = call(CControllerTopologyDevicesAcceptChassis::class, ['id' => (string) $A]);
check(isset($r['error']), 'accept-chassis without a chassis change: an error message, nothing changes');
$r = call(CControllerTopologyDevicesMerge::class, ['keep_id' => (string) $A, 'remove_id' => (string) $A]);
check(isset($r['error']), 'merge of a Device into itself: an error message');
$r = call(CControllerTopologyDevicesMerge::class, ['keep_id' => (string) $A, 'remove_id' => (string) $B]);
check(($r['success'] ?? false) === true && $r['kept'] === $A && $r['removed'] === $B && !exists($pdo, $B), 'merge: success, answers kept and removed');
check(call(CControllerTopologyDevicesMerge::class, ['keep_id' => 'x', 'remove_id' => '1']) === null, 'a malformed id is rejected (the input validation, no answer)');

// ============================================================================================================
echo "\n=== 6: merge a group (keep K, remove R1..Rn): any order inside gives the same result ===\n";
$nodes = [['id' => '1', 'duplicates' => [['device_id' => 2]]], ['id' => '2', 'duplicates' => [['device_id' => 1], ['device_id' => 3]]],
	['id' => '3', 'duplicates' => [['device_id' => 2]]], ['id' => '9', 'duplicates' => []], ['id' => '10', 'duplicates' => [['device_id' => 11]]], ['id' => '11', 'duplicates' => []]];
$g = CTopologyPrototype::duplicateGroups($nodes);
check($g[1] === [1, 2, 3] && $g[2] === [1, 2, 3] && $g[3] === [1, 2, 3] && $g[10] === [10, 11] && $g[11] === [10, 11] && !isset($g[9]),
	'the group listing: A-B and B-C are one group of three, a pair is a group of two, a Device without a candidate is in none');

function group_setup(PDO $pdo, string $X, string $IP): array {
	reset_all($pdo);
	$A = device($pdo, $X, null, 'A');
	$B = device($pdo, null, $IP, 'B');
	$C = device($pdo, null, '10.9.9.9', 'C');
	foreach ([[$B, 'lb', 'p1', 1], [$C, 'lc', 'p2', 2]] as [$dev, $location, $port, $if_index]) {
		$attrs = dev_attrs($pdo, $dev);
		$attrs['location'] = $location;
		$pdo->prepare('UPDATE topo_nodes SET attrs = ? WHERE id = ?')->execute([json_encode($attrs), $dev]);
		$pdo->prepare("INSERT INTO topo_nodes (type, device_id, attrs, created_at, updated_at) VALUES ('port', ?, ?, 1, 1)")
			->execute([$dev, json_encode(['if_index' => $if_index, 'pseudo' => false, 'name' => $port, 'if_type' => 'physical'])]);
	}
	return [$A, $B, $C];
}
function group_state(PDO $pdo, int $kept): array {
	$names = $pdo->query("SELECT JSON_UNQUOTE(JSON_EXTRACT(attrs, '\$.name')) FROM topo_nodes WHERE type = 'port' ORDER BY 1")->fetchAll(PDO::FETCH_COLUMN);
	$attrs = dev_attrs($pdo, $kept);
	unset($attrs['last_seen']);
	return ['devices' => dev_count($pdo), 'attrs' => $attrs, 'keys' => keys_of($pdo, $kept), 'ports' => $names];
}
$states = [];
foreach (['B,C', 'C,B'] as $order) {
	[$A, $B, $C] = group_setup($pdo, $X, $IP);
	$ids = $order === 'B,C' ? [(string) $B, (string) $C] : [(string) $C, (string) $B];
	$result = CTopologyPrototype::mergeGroup((string) $A, $ids, 'Admin');
	check($result['kept'] === $A && !exists($pdo, $B) && !exists($pdo, $C), "order {$order}: both Devices are merged into A");
	$states[$order] = group_state($pdo, $A);
}
check($states['B,C'] === $states['C,B'], 'the two orders give the same state: '.json_encode($states['B,C']));
$want_keys = ['chassis:mac:'.$X => 0, 'ip:server:10.9.9.9' => 0, 'ip:server:'.$IP => 0];
ksort($want_keys);
check($states['B,C']['keys'] === $want_keys && $states['B,C']['ports'] === ['p1', 'p2']
	&& ($states['B,C']['attrs']['location'] ?? null) === 'lb', 'A holds the keys and the ports of all three; the empty attribute is filled from the lowest id (B)');

[$A, $B, $C] = group_setup($pdo, $X, $IP);
foreach ([[$B, 111], [$C, 112]] as [$dev, $hostid]) {
	add_host($pdo, $hostid, 'H'.$hostid, 'H'.$hostid, null);
	$pdo->prepare("INSERT INTO topo_nodes (type, host_ref, attrs, created_at, updated_at) VALUES ('host', ?, '{}', 1, 1)")->execute([$hostid]);
	$pdo->prepare("UPDATE topo_nodes SET represented_by_node_id = ?, represented_by_matched_by = 'manual', represented_by_at = 1 WHERE id = ?")->execute([(int) $pdo->lastInsertId(), $dev]);
}
try {
	CTopologyPrototype::mergeGroup((string) $A, [(string) $B, (string) $C], 'Admin');
	check(false, 'a group with two represented Devices is refused');
}
catch (Exception $e) {
	check(exists($pdo, $A) && exists($pdo, $B) && exists($pdo, $C), 'a group with two represented Devices is refused before anything is written ('.$e->getMessage().')');
}
login();
$r = call(CControllerTopologyDevicesMergeGroup::class, ['keep_id' => (string) $A, 'remove_ids' => []]);
check(is_array($r) && isset($r['error']) || $r === null, 'the endpoint answers an empty list with an error');

echo "\nAll duplicate candidate checks passed.\n";
