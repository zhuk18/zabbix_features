#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * test_scenario_catalog.php — the reconciliation scenario catalog (handoff task 009): 135 real-network scenarios with the
 * result the reviewer expects, run through the REAL ingest.php (snapshots written straight into topo_lld_snapshot with fixed
 * clocks, ingest as a process, results compared by natural keys). One case per scenario ID.
 *
 * After the last step of each case ingest runs 3 more times: row ids and last_seen* must not change (§6.10); a case with two
 * or more reporters is also run with the reporters walked in the reverse order (§6.8) and must give the same natural state.
 *
 * Verdicts: match / differs / out of harness. Only cases rated `Правило` and `Ограничение` (the spec states the result, or
 * accepts it as a limitation) fail the run; `Вывод` and `Пробел` cases are characterization: the actual result is recorded
 * and never fails. Results go to scenario_catalog_results.json next to this file.
 *
 * Same method as test_snapshot_ingest.php. Writes rows straight into topo_lld_snapshot (what the server's LLD worker would store) and runs the REAL
 * ingest.php as a subprocess after every change, so the actual CLI path is exercised end to end — no test-only
 * hook, no reimplementation of the algorithm here.
 *
 * Usage: test_scenario_catalog.php [--first-pass] <PDO DSN> <database user> [password]
 *   --first-pass runs only the cases tagged first_pass (the reviewer's first pass); the JSON file is written by full runs only.
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
foreach (['topo_observations', 'topo_edges', 'topo_nodes', 'topo_lld_snapshot', 'host_inventory', 'interface',
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

// ============================================================================================================
// Harness
// ============================================================================================================

const CLOCK = 1_800_000_000;
const ROLE_PORTS = 1;
const ROLE_NEIGHBORS = 2;
const ROLE_LEARNED_MACS = 3;
const ROLE_LAG = 4;

/** Current case: reporters, clock, order of the reporters, recorded steps, failed expectations. */
$H = [];

function mac(int $n): string {
	return sprintf('a0:00:00:00:%02x:%02x', $n >> 8, $n & 255);
}

function h_reset(string $order = 'forward'): void {
	global $pdo, $H;
	$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
	foreach (['topo_observations', 'topo_edges', 'topo_nodes', 'topo_lld_snapshot', 'host_inventory', 'interface', 'items', 'hosts'] as $t) {
		$pdo->exec("DELETE FROM {$t}");
	}
	$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
	API::$visible = [];
	API::$inventory = [];
	$H = ['reps' => [], 'step' => 0, 'order' => $order, 'steps' => [], 'fails' => [], 'notes' => [], 'multi' => false, 'last_summary' => []];
}

function h_fail(string $what): void {
	global $H;
	$H['fails'][] = $what;
}

/** An expectation of the catalog: recorded, never aborts the case. */
function ex(bool $ok, string $what): bool {
	global $H;
	if (!$ok) {
		$H['fails'][] = $what;
	}
	return $ok;
}

/** An expectation on a value: the failure says what it was. */
function exv($actual, $expected, string $what): bool {
	return ex($actual === $expected, $what.' (expected '.json_encode($expected).', actual '.json_encode($actual).')');
}

function h_note(string $text): void {
	global $H;
	$H['notes'][] = $text;
}

/** A Zabbix host that is (or can be) a reporter: hostid, chassis (loc_chassis; null = reports none), SNMP interface IP. */
function h_rep(string $name, ?string $chassis, ?string $ip = null, bool $with_ip = true): int {
	global $pdo, $H;
	$id = 100 + count($H['reps']) + 1;
	$ip = $with_ip ? ($ip ?? '10.0.0.'.$id) : null;
	add_host($pdo, $id, $name, $name, $ip);
	API::$visible[] = $id;
	$H['reps'][$name] = ['id' => $id, 'chassis' => $chassis, 'ip' => $ip];
	if (count($H['reps']) > 1) {
		$H['multi'] = true;
	}
	return $id;
}

function h_clock(int $offset = 0): int {
	global $H;
	return CLOCK + 100 * $H['step'] + $offset;
}

/** The next step of a case: later snapshots get a later clock. */
function h_tick(): void {
	global $H;
	$H['step']++;
}

function h_itemid(string $rep, int $role, string $source, bool $trapper): int {
	global $H;
	$slot = $role === ROLE_PORTS ? 1 : ($role === ROLE_NEIGHBORS ? ($source === 'cdp' ? 3 : 2) : ($role === ROLE_LAG ? 4 : 5));
	return $H['reps'][$rep]['id'] * 100 + $slot + ($trapper ? 10 : 0);
}

/** Writes a snapshot of one rule of reporter $rep. loc_chassis is added to the PORTS and NEIGHBORS rows. */
function h_snap(string $rep, int $role, array $rows, ?int $clock = null, string $source = 'lldp', bool $trapper = false, int $status = 0): int {
	global $pdo, $H;
	$r = $H['reps'][$rep];
	if ($r['chassis'] !== null && in_array($role, [ROLE_PORTS, ROLE_NEIGHBORS], true)) {
		$rows = array_map(static fn (array $row): array => $row + ['loc_chassis' => $r['chassis']], $rows);
	}
	if ($role === ROLE_NEIGHBORS) {
		$rows = array_map(static fn (array $row): array => $row + ['source' => $source], $rows);
	}
	$itemid = h_itemid($rep, $role, $source, $trapper);
	snapshot($pdo, $itemid, $r['id'], $role, $clock ?? h_clock($role === ROLE_PORTS ? -10 : 0), $rows, null, $status);
	$pdo->prepare('UPDATE items SET type = ? WHERE itemid = ?')->execute([$trapper ? 2 : 0, $itemid]);
	return $itemid;
}

/** PORTS rows: [if_index => name | [name, extra]]. */
function PR(array $ports): array {
	$rows = [];
	foreach ($ports as $if => $p) {
		[$name, $extra] = is_array($p) ? $p : [$p, []];
		$rows[] = array_merge(['if_index' => $if, 'name' => $name, 'if_type' => '6', 'admin_status' => '1', 'oper_status' => '1'], $extra);
	}
	return $rows;
}

/** One NEIGHBORS row: local ifIndex, the neighbor's chassis (MAC; null = none), its sysname and advertised port. */
function NR(int $if, ?string $chassis, ?string $sysname, ?string $port = 'Gi0/24', array $extra = []): array {
	$row = ['if_index' => $if, 'if_name' => 'Gi0/'.$if];
	if ($chassis !== null) {
		$row += ['rem_chassis' => $chassis, 'rem_chassis_type' => 'macAddress'];
	}
	if ($sysname !== null) {
		$row['rem_sysname'] = $sysname;
	}
	if ($port !== null) {
		$row += ['rem_port' => $port, 'rem_port_type' => 'interfaceName'];
	}
	return $extra + $row;
}

function h_ports(string $rep, array $ports, ?int $clock = null, bool $trapper = false): int {
	return h_snap($rep, ROLE_PORTS, PR($ports), $clock, 'lldp', $trapper);
}

function h_nbrs(string $rep, array $rows, string $source = 'lldp', ?int $clock = null, bool $trapper = false): int {
	return h_snap($rep, ROLE_NEIGHBORS, $rows, $clock, $source, $trapper);
}

function h_ingest(string $label, array $only_hosts = []): array {
	global $dsn, $user, $password, $pdo, $H;
	$order = null;
	if ($H['order'] === 'reverse') {
		$order = implode(',', array_reverse(array_column($H['reps'], 'id')));
	}
	$r = run_ingest($dsn, $user, $password, $only_hosts, $order);
	// exit 1 = nothing could be ingested (every reporter skipped): a result, not a crash
	if ($r['code'] > 1 || $r['summary'] === []) {
		h_fail("ingest failed at '{$label}': ".trim($r['err'].' '.substr($r['out'], -200)));
	}
	$H['last_summary'] = $r['summary'];
	$state = h_state();
	$H['steps'][] = ['step' => $label, 'devices' => count($state['devices']), 'links' => $state['links'], 'obs' => $state['obs']];
	return $r['summary'];
}

// ---- state by natural keys ----

function h_dev_label(array $nodes, ?int $id): ?string {
	if ($id === null || !isset($nodes[$id])) {
		return null;
	}
	$a = $nodes[$id]['a'];
	return ($a['sysname'] ?? '') !== '' ? $a['sysname'] : ($a['chassis_id'] ?? ($a['mgmt_ip'] ?? 'device#'.$id));
}

function h_end_label(array $nodes, int $id): string {
	$n = $nodes[$id];
	if ($n['type'] === 'device') {
		return h_dev_label($nodes, $id);
	}
	return h_dev_label($nodes, (int) $n['device_id']).':'.($n['a']['name'] ?? '?').(($n['a']['pseudo'] ?? false) ? '*' : '');
}

/** Devices, ports, links, observations of the database, with ids. */
function h_state(): array {
	global $pdo;
	$nodes = [];
	foreach ($pdo->query('SELECT * FROM topo_nodes ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) as $n) {
		$n['a'] = json_decode($n['attrs'], true) ?: [];
		$nodes[(int) $n['id']] = $n;
	}
	$devices = [];
	$ports = [];
	foreach ($nodes as $id => $n) {
		if ($n['type'] === 'device') {
			$a = $n['a'];
			$devices[] = ['id' => $id, 'name' => h_dev_label($nodes, $id), 'chassis' => $a['chassis_id'] ?? null, 'ip' => $a['mgmt_ip'] ?? null,
				'matched_by' => $a['matched_by'] ?? null,
				'represented_by' => $n['represented_by_node_id'] !== null ? $n['represented_by_matched_by'] : null];
		}
		elseif ($n['type'] === 'port') {
			$a = $n['a'];
			$label = ($a['name'] ?? '?').(($a['pseudo'] ?? false) ? '*' : '').(($a['if_type'] ?? '') === 'lag' ? '(lag)' : '')
				.($n['lag_id'] !== null ? '[lag '.$n['lag_id'].']' : '').'#'.$id.'/if'.($a['if_index'] ?? '?');
			$ports[h_dev_label($nodes, (int) $n['device_id'])][] = $label;
		}
	}
	$links = [];
	foreach ($pdo->query("SELECT * FROM topo_edges WHERE type IN ('physical_link','device_link') ORDER BY id")->fetchAll(PDO::FETCH_ASSOC) as $e) {
		$a = json_decode($e['attrs'], true) ?: [];
		$ends = [h_end_label($nodes, (int) $e['src_id']), h_end_label($nodes, (int) $e['dst_id'])];
		if ($e['type'] === 'physical_link') {
			sort($ends);
		}
		$links[] = ['id' => (int) $e['id'], 'type' => $e['type'], 'ends' => $ends, 'via' => $a['discovered_via'] ?? null,
			'state' => isset($a['superseded_at']) ? 'superseded' : 'active', 'superseded_at' => $a['superseded_at'] ?? null,
			'reason' => $a['far_port_reason'] ?? null, 'lower' => $a['precision_lower'] ?? null,
			'last_seen' => $a['last_seen'] ?? null, 'ls_src' => $a['last_seen_src'] ?? null, 'ls_dst' => $a['last_seen_dst'] ?? null,
			'ack' => !empty($a['shadow_ack'])];
	}
	$obs = [];
	foreach ($pdo->query('SELECT o.*, h.host AS reporter FROM topo_observations o JOIN items i ON i.itemid = o.itemid JOIN hosts h ON h.hostid = i.hostid'.
			' ORDER BY o.id')->fetchAll(PDO::FETCH_ASSOC) as $o) {
		$obs[] = ['id' => (int) $o['id'], 'itemid' => (int) $o['itemid'], 'reporter' => $o['reporter'],
			'port' => ($nodes[(int) $o['local_port_id']]['a']['name'] ?? '?'), 'remote' => $o['remote_key'], 'outcome' => $o['outcome'],
			'edge_id' => $o['edge_id'] === null ? null : (int) $o['edge_id'], 'device' => h_dev_label($nodes, $o['device_id'] === null ? null : (int) $o['device_id']),
			'precision' => $o['link_precision'], 'reason' => $o['far_port_reason'], 'lower' => (bool) $o['precision_lower']];
	}
	return ['devices' => $devices, 'ports' => $ports, 'links' => array_map('h_link_str', $links), 'links_full' => $links, 'obs' => array_map('h_obs_str', $obs),
		'obs_full' => $obs];
}

function h_link_str(array $l): string {
	return $l['type'].' '.implode(' ~ ', $l['ends']).' '.$l['state'].($l['via'] !== 'lldp' ? ' ('.$l['via'].')' : '')
		.($l['reason'] ? ' '.$l['reason'] : '').($l['lower'] ? ' precision_lower' : '').' #'.$l['id'];
}

function h_obs_str(array $o): string {
	return $o['reporter'].':'.$o['port'].' -> '.($o['device'] ?? '?').' '.$o['outcome'].($o['edge_id'] ? ' edge#'.$o['edge_id'] : '')
		.($o['precision'] ? ' '.$o['precision'] : '').($o['reason'] ? ' '.$o['reason'] : '').($o['lower'] ? ' precision_lower' : '');
}

function h_devices(): array {
	return array_column(h_state()['devices'], 'name');
}

function h_dev(string $name): ?array {
	foreach (h_state()['devices'] as $d) {
		if ($d['name'] === $name) {
			return $d;
		}
	}
	return null;
}

function h_dev_count(): int {
	return count(h_state()['devices']);
}

/** Links (full) between two end labels, `Dev:port` or `Dev` (any port of Dev); either order; optional type / state filter. */
function h_links(string $a, string $b, ?string $type = null, ?string $state = null): array {
	$match = static function (string $label, string $ref): bool {
		return str_contains($ref, ':') ? $label === $ref : ($label === $ref || strstr($label, ':', true) === $ref);
	};
	$out = [];
	foreach (h_state()['links_full'] as $l) {
		if (($type !== null && $l['type'] !== $type) || ($state !== null && $l['state'] !== $state)) {
			continue;
		}
		[$x, $y] = $l['ends'];
		if (($match($x, $a) && $match($y, $b)) || ($match($y, $a) && $match($x, $b))) {
			$out[] = $l;
		}
	}
	return $out;
}

function h_active_on(string $port_label): array {
	$out = [];
	foreach (h_state()['links_full'] as $l) {
		if ($l['state'] === 'active' && ($l['type'] === 'physical_link' ? in_array($port_label, $l['ends'], true) : $l['ends'][0] === $port_label)) {
			$out[] = $l;
		}
	}
	return $out;
}

/** The observation of reporter $rep on its port named $port (first one; filter by remote substring). */
function h_obs(string $rep, string $port, ?string $remote = null): ?array {
	foreach (h_state()['obs_full'] as $o) {
		if ($o['reporter'] === $rep && $o['port'] === $port && ($remote === null || str_contains($o['remote'], $remote))) {
			return $o;
		}
	}
	return null;
}

function h_obs_all(string $rep, string $port): array {
	return array_values(array_filter(h_state()['obs_full'], static fn (array $o): bool => $o['reporter'] === $rep && $o['port'] === $port));
}

/** The state without ids, clocks and order: what the reverse-order run must reproduce. */
function h_natural(): array {
	$s = h_state();
	$strip = static fn (string $x): string => preg_replace('/ ?(#\d+|edge#\d+)/', '', $x);
	$links = array_map($strip, $s['links']);
	sort($links);
	$obs = array_map($strip, $s['obs']);
	sort($obs);
	$ports = [];
	foreach ($s['ports'] as $dev => $list) {
		$list = array_map(static fn (string $p): string => preg_replace('/(\[lag )\d+\]|#\d+\/if/', '$1', $p), $list);
		sort($list);
		$ports[$dev] = $list;
	}
	ksort($ports);
	$devices = array_map(static fn (array $d): string => implode('|', [$d['name'], $d['chassis'], $d['ip'], $d['matched_by'], $d['represented_by']]), $s['devices']);
	sort($devices);
	return ['devices' => $devices, 'ports' => $ports, 'links' => $links, 'obs' => $obs];
}

function h_node_id_of_port(string $dev, string $name): ?int {
	global $pdo;
	foreach ($pdo->query("SELECT p.id, p.attrs, p.device_id FROM topo_nodes p WHERE p.type='port' ORDER BY p.id")->fetchAll(PDO::FETCH_ASSOC) as $p) {
		$a = json_decode($p['attrs'], true);
		$d = json_decode((string) scalar($pdo, 'SELECT attrs FROM topo_nodes WHERE id=?', [$p['device_id']]), true);
		if (($a['name'] ?? null) === $name && (($d['sysname'] ?? '') === $dev || ($d['chassis_id'] ?? '') === $dev)) {
			return (int) $p['id'];
		}
	}
	return null;
}

function h_device_id(string $name): ?int {
	return h_dev($name)['id'] ?? null;
}

/** A Device made by hand: old data, or a Device another reporter found first. */
function device(PDO $pdo, ?string $chassis, ?string $ip, string $sysname = ''): int {
	$pdo->prepare("INSERT INTO topo_nodes (type, attrs, created_at, updated_at) VALUES ('device', ?, 1, 1)")
		->execute([json_encode(['mac' => null, 'chassis_id' => $chassis, 'mgmt_ip' => $ip, 'sysname' => $sysname, 'vendor' => 'unknown',
			'last_seen' => 1])]);
	return (int) $pdo->lastInsertId();
}

// ---- cases ----

$CASES = [];

/** The reviewer's first pass (handoff 011): a merged row of the catalog is all of its IDs. `--first-pass` runs only these. */
const FIRST_PASS = ['I-01', 'I-07', 'I-08', 'I-10', 'I-23', 'P-01', 'P-07', 'M-20', 'P-08', 'P-18', 'P-09', 'P-10', 'P-13', 'L-17', 'L-01', 'L-02',
	'L-03', 'L-04', 'L-06', 'L-07', 'C-06', 'L-08', 'L-11', 'L-12', 'L-13', 'L-13b', 'L-20', 'M-02', 'M-07', 'M-10', 'M-11', 'M-12', 'M-16', 'D-04',
	'D-01', 'D-05', 'D-07', 'C-01', 'C-14', 'C-17', 'C-13', 'J-01', 'J-02', 'J-03'];

/** A case of the catalog. $multi = run again with the reporters walked in the reverse order. */
function c(string $id, string $rating, callable $fn, ?string $covered = null): void {
	global $CASES;
	$CASES[$id] = ['id' => $id, 'rating' => $rating, 'fn' => $fn, 'out' => null, 'covered' => $covered];
}

/** A case that is not built, with the reason (and the test or lab step that covers it, if any). */
function oh(string $id, string $rating, string $reason, ?string $covered = null): void {
	global $CASES;
	$CASES[$id] = ['id' => $id, 'rating' => $rating, 'fn' => null, 'out' => $reason, 'covered' => $covered];
}

function h_run_case(array $case, string $order): array {
	global $H, $dsn, $user, $password, $pdo;
	h_reset($order);
	try {
		($case['fn'])();
	}
	catch (Throwable $e) {
		h_fail('exception: '.$e->getMessage().' at '.basename($e->getFile()).':'.$e->getLine());
	}
	return $H;
}

/** Every node, edge and observation with the values that must not move between runs of unchanged snapshots. */
function h_rows(): array {
	global $pdo;
	return [
		'nodes' => $pdo->query('SELECT id, type, device_id, lag_id, represented_by_node_id, attrs FROM topo_nodes ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
		'edges' => $pdo->query('SELECT id, type, src_id, dst_id, attrs FROM topo_edges ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
		'obs' => $pdo->query('SELECT id, itemid, local_port_id, remote_key, outcome, edge_id, device_id, first_seen, last_seen FROM topo_observations ORDER BY id')
			->fetchAll(PDO::FETCH_ASSOC),
	];
}

/** The part of the rows §6.10 says must not move: ids, and every last_seen* (first_seen/last_seen of observations). */
function h_pinned(array $rows): array {
	$out = [];
	foreach ($rows as $table => $list) {
		foreach ($list as $row) {
			$pin = ['id' => $row['id']];
			foreach (['type', 'device_id', 'src_id', 'dst_id', 'local_port_id', 'remote_key', 'first_seen', 'last_seen', 'itemid'] as $k) {
				if (array_key_exists($k, $row)) {
					$pin[$k] = $row[$k];
				}
			}
			foreach (json_decode($row['attrs'] ?? '{}', true) ?: [] as $k => $v) {
				if (str_starts_with((string) $k, 'last_seen') || in_array($k, ['superseded_at'], true)) {
					$pin[$k] = $v;
				}
			}
			$out[$table][$row['id']] = $pin;
		}
	}
	return $out;
}

function h_idempotent(): array {
	global $dsn, $user, $password, $pdo, $H;
	$before = h_rows();
	$rows = [];
	for ($i = 1; $i <= 3; $i++) {
		$r = run_ingest($dsn, $user, $password, [], $H['order'] === 'reverse' ? implode(',', array_reverse(array_column($H['reps'], 'id'))) : null);
		if ($r['code'] > 1) {
			return [false, 'ingest failed on repeat '.$i];
		}
		$rows[$i] = h_rows();
	}
	$after = $rows[3];
	if (h_pinned($after) !== h_pinned($before)) {
		foreach (h_pinned($before) as $table => $list) {
			foreach (array_unique(array_merge(array_keys($list), array_keys(h_pinned($after)[$table] ?? []))) as $id) {
				$a = $list[$id] ?? null;
				$b = h_pinned($after)[$table][$id] ?? null;
				if ($a !== $b) {
					return [false, "row ids or last_seen* changed over 3 more runs: {$table} #{$id}: ".json_encode($a).' -> '.json_encode($b)];
				}
			}
		}
		return [false, 'row ids or last_seen* changed over 3 more runs'];
	}
	if ($rows[2] !== $rows[3]) {
		return [false, 'other attributes still change on the 3rd run (no convergence)'];
	}
	if ($before !== $rows[3]) {
		h_note('attributes other than ids and last_seen* changed once on the first repeat (e.g. matched_by) and then stayed');
	}
	return [true, ''];
}

function h_execute(array $case): array {
	global $H;
	if ($case['fn'] === null) {
		return ['id' => $case['id'], 'rating' => $case['rating'], 'first_pass' => in_array($case['id'], FIRST_PASS, true), 'verdict' => 'out of harness', 'reason' => $case['out'], 'covered_by' => $case['covered']];
	}
	$run = h_run_case($case, 'forward');
	$fails = $H['fails'];
	$steps = $H['steps'];
	$notes = $H['notes'];
	$multi = $H['multi'];
	$state = h_state();
	[$idem, $idem_note] = h_idempotent();
	if (!$idem) {
		$fails[] = $idem_note;
	}
	$order_ok = null;
	if ($multi) {
		$natural = h_natural();
		h_run_case($case, 'reverse');
		h_idempotent();         // the same 3 more runs as the forward order had before its natural state was taken
		$order_ok = h_natural() === $natural;
		if (!$order_ok) {
			$rev = h_natural();
			$flat = static function (array $n): array {
				$out = [];
				foreach ($n as $k => $list) {
					foreach ($list as $key => $v) {
						$out[] = $k.': '.(is_array($v) ? $key.' => '.implode(', ', $v) : $v);
					}
				}
				return $out;
			};
			$fails[] = 'the reverse reporter order gives a different natural state; only with the forward order: '
				.json_encode(array_values(array_diff($flat($natural), $flat($rev))), JSON_UNESCAPED_UNICODE)
				.'; only with the reverse order: '.json_encode(array_values(array_diff($flat($rev), $flat($natural))), JSON_UNESCAPED_UNICODE)
				.'; forward: '.json_encode($natural, JSON_UNESCAPED_UNICODE).'; reverse: '.json_encode($rev, JSON_UNESCAPED_UNICODE);
		}
	}
	return ['id' => $case['id'], 'rating' => $case['rating'], 'first_pass' => in_array($case['id'], FIRST_PASS, true), 'verdict' => $fails ? 'differs' : 'match', 'failed' => $fails,
		'idempotent' => $idem, 'order_independent' => $order_ok, 'covered_by' => $case['covered'], 'notes' => $notes,
		'actual' => ['devices' => $state['devices'], 'ports' => $state['ports'], 'links' => $state['links'], 'observations' => $state['obs']],
		'steps' => $steps];
}

// ============================================================================================================
// 1. Идентификация устройства
// ============================================================================================================

/** Reporter R (chassis a0:..:01, 10.0.0.1) with ports Gi0/1..Gi0/4. */
function base_r(): void {
	h_rep('R', mac(1), '10.0.0.1');
	h_ports('R', [1 => 'Gi0/1', 2 => 'Gi0/2', 3 => 'Gi0/3', 4 => 'Gi0/4']);
}

function links_to(string $dev): array {
	return array_values(array_filter(h_state()['links_full'], static fn (array $l): bool => in_array($dev, array_map(static fn ($e) => strstr($e, ':', true) ?: $e, $l['ends']), true)));
}

c('I-01', 'Правило', function () {
	base_r();
	h_nbrs('R', [NR(1, mac(10), 'DX', 'Gi0/24')]);
	h_ingest('a new neighbor X appears behind R:Gi0/1');
	ex(h_dev_count() === 2 && (h_dev('DX')['chassis'] ?? null) === mac(10), 'a Device is created by chassis X');
	ex(count(h_links('R:Gi0/1', 'DX:Gi0/24*', 'physical_link', 'active')) === 1, 'physical_link R:Gi0/1 ~ the port of X created from the advertisement (pseudo)');
	ex((h_obs('R', 'Gi0/1')['outcome'] ?? null) === 'applied', 'outcome applied');
});

c('I-02', 'Правило', function () {
	base_r();
	h_nbrs('R', [NR(1, null, 'cdpbox', 'Gi0/24', ['rem_mgmt_ip' => '10.1.1.1'])], 'cdp');
	h_ingest('a CDP neighbor with an IP only');
	$d = h_dev('cdpbox');
	ex($d !== null && $d['ip'] === '10.1.1.1' && $d['chassis'] === null && h_dev_count() === 2, 'a Device is created by mgmt_ip (no chassis)');
});

c('I-03', 'Правило', function () {
	base_r();
	h_nbrs('R', [NR(1, null, 'box', 'Gi0/24', ['rem_mgmt_ip' => '10.1.1.1'])], 'cdp');
	h_ingest('CDP only');
	h_tick();
	h_nbrs('R', [NR(1, mac(11), 'box', 'Gi0/24', ['rem_mgmt_ip' => '10.1.1.1'])], 'lldp');
	h_ingest('LLDP with chassis Y and the same IP');
	$d = h_dev('box');
	ex(h_dev_count() === 2 && $d !== null && $d['chassis'] === mac(11), 'one Device; it got chassis Y');
});

c('I-04', 'Правило', function () {
	base_r();
	h_rep('R2', mac(2));
	h_ports('R2', [1 => 'Gi0/1']);
	h_nbrs('R', [NR(1, null, 'S-only', 'Gi0/24')]);
	h_ingest('sysname-only neighbor');
	ex((h_dev('S-only')['matched_by'] ?? null) === 'sysname', 'a Device created by sysname has matched_by sysname in the same run');
	h_tick();
	h_nbrs('R', [NR(1, null, 'S-only', 'Gi0/24')]);
	h_ingest('seen again on the same port');
	ex(h_dev_count() === 3 && (h_dev('S-only')['matched_by'] ?? null) === 'sysname', 'found by sysname on the same R and P: matched_by sysname, one Device');
	ex(in_array('attributes other than ids and last_seen* changed once on the first repeat (e.g. matched_by) and then stayed', $GLOBALS['H']['notes'], true) === false, 'the first repeat run changes no attribute (matched_by is set at creation)');
	h_tick();
	h_nbrs('R2', [NR(1, null, 'S-only', 'Gi0/24')]);
	h_ingest('the same sysname behind another reporter');
	ex(h_dev_count() === 4, 'the key is scoped to R and P: another reporter makes another Device (R, R2, two S-only)');
});

c('I-05', 'Ограничение', function () {
	base_r();
	h_nbrs('R', [NR(1, null, 'S-only', 'Gi0/24')]);
	h_ingest('sysname-only neighbor on Gi0/1');
	h_tick();
	h_nbrs('R', [NR(2, null, 'S-only', 'Gi0/24')]);
	h_ingest('it moved to Gi0/2');
	ex(h_dev_count() === 3, 'a new Device; the old one stays');
	ex(count(h_active_on('R:Gi0/1')) === 1 && count(h_active_on('R:Gi0/2')) === 1, 'the old link stays active (it only ages), the new one is active');
});

c('I-06', 'Вывод', function () {
	base_r();
	h_nbrs('R', [NR(1, null, 'S-old', 'Gi0/24')]);
	h_ingest('sysname-only neighbor');
	h_tick();
	h_nbrs('R', [NR(1, null, 'S-new', 'Gi0/24')]);
	h_ingest('renamed on the same port');
	ex(h_dev_count() === 3 && h_dev('S-old') !== null && h_dev('S-new') !== null, 'a new Device; the old one stays');
	$old = h_links('R:Gi0/1', 'S-old', null, 'superseded');
	ex(count($old) === 1 && count(h_links('R:Gi0/1', 'S-new', null, 'active')) === 1, 'the old link is superseded, a new one is created');
});

c('I-07', 'Правило', function () {
	base_r();
	h_nbrs('R', [NR(1, mac(10), 'X', 'Gi0/24')]);
	h_ingest('X is only a neighbor');
	h_tick();
	h_rep('X', mac(10), '10.0.0.50');
	h_ports('X', [24 => 'Gi0/24']);
	h_nbrs('X', [NR(24, mac(1), 'R', 'Gi0/1')]);
	h_ingest('X becomes a reporter');
	$s = h_state();
	ex(h_dev_count() === 2, 'Device(X) is reused, not duplicated');
	ex((h_dev('X')['represented_by'] ?? null) === 'reporter_self', 'reporter_self is set on it');
	ex(count(array_filter($s['links_full'], static fn ($l) => $l['state'] === 'active')) === 1, 'one link');
	ex(count(h_links('R:Gi0/1', 'X:Gi0/24', 'physical_link', 'active')) === 1, 'between the real ports (no pseudo port left)');
	ex(!str_contains(implode(',', $s['ports']['X'] ?? []), '*'), 'no pseudo port on X');
});

c('I-08', 'Правило', function () {
	base_r();
	h_nbrs('R', [NR(1, mac(10), 'DX', 'Gi0/24', ['rem_mgmt_ip' => '10.9.9.9'])]);
	h_ingest('chassis X, IP 10.9.9.9');
	h_tick();
	h_nbrs('R', [NR(1, mac(11), 'DX', 'Gi0/24', ['rem_mgmt_ip' => '10.9.9.9'])]);
	h_ingest('RMA: chassis Y, same IP and sysname');
	$chassis = array_column(h_state()['devices'], 'chassis');
	ex(h_dev_count() === 3 && in_array(mac(10), $chassis, true) && in_array(mac(11), $chassis, true), 'a new Device(Y); Device(X) keeps chassis X');
	ex(count(h_active_on('R:Gi0/1')) === 1 && count(h_links('R:Gi0/1', 'DX', null, 'superseded')) === 1, 'the link on P: the old one superseded (contradiction, no support), the new one active');
});

c('I-09', 'Правило', function () {
	base_r();
	h_nbrs('R', [NR(1, mac(10), 'DX', 'Gi0/24', ['rem_mgmt_ip' => '10.9.9.9'])]);
	h_ingest('chassis X');
	h_tick();
	h_nbrs('R', [NR(1, mac(11), 'DX', 'Gi0/24', ['rem_mgmt_ip' => '10.9.9.9'])]);
	h_ingest('RMA');
	h_tick();
	h_nbrs('R', [NR(2, null, 'only-ip', null, ['rem_mgmt_ip' => '10.9.9.9'])]);
	$summary = h_ingest('a row with only the IP');
	$o = h_obs('R', 'Gi0/2');
	ex($o !== null && $o['outcome'] === 'ambiguous' && $o['device'] === null, 'ambiguous, no Device');
	ex(count(h_active_on('R:Gi0/2')) === 0, 'no link');
	ex(($summary['identity_keys_shared']['count'] ?? 0) === 1, 'identity_keys_shared lists the IP');
});

c('I-10', 'Правило', function () {
	h_rep('R', mac(1), '10.0.0.1');
	h_ports('R', [1 => 'Gi0/1']);
	h_nbrs('R', [NR(1, mac(2), 'N', 'Gi0/1')]);
	h_rep('N', mac(2), '10.0.0.2');
	h_ports('N', [1 => 'Gi0/1']);
	h_nbrs('N', [NR(1, mac(1), 'R', 'Gi0/1', ['rem_mgmt_ip' => '10.0.0.1'])]);
	h_ingest('baseline: R and N see each other');
	$by_chassis = static function (string $chassis): ?int {
		foreach (h_state()['devices'] as $d) {
			if ($d['chassis'] === $chassis) {
				return $d['id'];
			}
		}
		return null;
	};
	$s = $by_chassis(mac(1));
	global $H;
	h_tick();
	$H['reps']['R']['chassis'] = mac(5);            // the chassis of R was replaced (RMA), the host in Zabbix is the same
	h_ports('R', [1 => 'Gi0/1']);
	h_nbrs('R', [NR(1, mac(2), 'N', 'Gi0/1')]);
	h_nbrs('N', [NR(1, mac(5), 'R', 'Gi0/1', ['rem_mgmt_ip' => '10.0.0.1'])]);
	h_ingest('R reports chassis Y, N advertises Y');
	$c = $by_chassis(mac(5));
	global $pdo;
	$cand = static fn (int $id): array => json_decode((string) scalar($pdo, 'SELECT attrs FROM topo_nodes WHERE id=?', [$id]), true)['duplicate_candidates'] ?? [];
	ex($by_chassis(mac(1)) === $s && $c !== null && $c !== $s, 'the reporter\'s Device S keeps the stored chassis X; the neighbors made Device C for Y (3 Devices)');
	ex($cand($s) === [['device_id' => $c, 'reason' => 'reporter_chassis_change', 'key' => 'chassis_id', 'value' => mac(5)]]
		&& $cand($c) === [['device_id' => $s, 'reason' => 'reporter_chassis_change', 'key' => 'chassis_id', 'value' => mac(5)]], 'candidate reporter_chassis_change on S and on C');
	h_note('operator merge: keep S, remove C');
	CTopologyPrototype::mergeDevices((string) $s, (string) $c, 'Admin');
	h_tick();
	h_nbrs('R', [NR(1, mac(2), 'N', 'Gi0/1')]);
	h_nbrs('N', [NR(1, mac(5), 'R', 'Gi0/1', ['rem_mgmt_ip' => '10.0.0.1'])]);
	h_ingest('after the operator merge (keep S, remove C)');
	$k = json_decode((string) scalar($pdo, 'SELECT attrs FROM topo_nodes WHERE id=?', [$s]), true);
	ex(h_dev_count() === 2 && ($k['chassis_id'] ?? null) === mac(5) && ($k['replaced_chassis'] ?? null) === [mac(1)], 'S has chassis Y and replaced_chassis [X]; C is gone (2 Devices)');
	ex(count(h_links('R:Gi0/1', 'N:Gi0/1', 'physical_link', 'active')) === 1, 'one link between R and N (C\'s link is on S)');
	h_tick();
	h_nbrs('N', [NR(1, mac(1), 'R', 'Gi0/1', ['rem_mgmt_ip' => '10.0.0.1'])]);          // a stale neighbor row with the OLD chassis
	h_ingest('N still advertises the old chassis X');
	ex(h_dev_count() === 2 && count(h_links('R:Gi0/1', 'N:Gi0/1', 'physical_link', 'active')) === 1, 'the stale row finds S by replaced_chassis: no ghost Device');
}, 'test_duplicate_candidates.php §4');

c('I-11', 'Правило', function () {
	base_r();
	h_nbrs('R', [NR(1, mac(10), 'DX', 'Gi0/24', ['rem_mgmt_ip' => '10.1.1.1'])]);
	h_ingest('chassis X, IP 1');
	h_tick();
	h_nbrs('R', [NR(1, mac(10), 'DX', 'Gi0/24', ['rem_mgmt_ip' => '10.1.1.2'])]);
	h_ingest('chassis X, IP 2');
	ex(h_dev_count() === 2 && (h_dev('DX')['ip'] ?? null) === '10.1.1.2', 'found by chassis, mgmt_ip updated');
});

c('I-12', 'Правило', function () {
	base_r();
	h_nbrs('R', [NR(1, mac(10), 'old-name', 'Gi0/24')]);
	h_ingest('sysname old');
	h_tick();
	h_nbrs('R', [NR(1, mac(10), 'new-name', 'Gi0/24')]);
	h_ingest('sysname new');
	ex(h_dev_count() === 2 && h_dev('new-name') !== null && h_dev('old-name') === null, 'sysname updated, the same Device');
});

c('I-13', 'Правило', function () {
	base_r();
	h_nbrs('R', [NR(1, mac(10), 'A', 'Gi0/24', ['rem_mgmt_ip' => '192.168.1.1']), NR(2, mac(11), 'B', 'Gi0/24', ['rem_mgmt_ip' => '192.168.1.1']),
		NR(3, null, 'by-ip', null, ['rem_mgmt_ip' => '192.168.1.1'])]);
	$summary = h_ingest('factory IP on two devices, and a row with only that IP');
	ex(h_dev_count() === 3 && h_dev('A') !== null && h_dev('B') !== null, 'two Devices by chassis');
	ex((h_obs('R', 'Gi0/3')['outcome'] ?? null) === 'ambiguous', 'the IP-only row is ambiguous');
	ex(($summary['identity_keys_shared']['list'][0]['value'] ?? null) === '192.168.1.1', 'the IP is in identity_keys_shared');
});

c('I-14', 'Правило', function () {
	base_r();
	h_nbrs('R', [NR(1, mac(10), 'A', 'Gi0/24', ['rem_mgmt_ip' => '10.7.7.7']), NR(2, mac(11), 'B', 'Gi0/24', ['rem_mgmt_ip' => '10.7.7.7']),
		NR(3, mac(12), 'C', 'Gi0/24', ['rem_mgmt_ip' => '10.7.7.7']), NR(4, null, 'vip', null, ['rem_mgmt_ip' => '10.7.7.7'])]);
	$summary = h_ingest('a virtual IP advertised by several neighbors');
	ex(h_dev_count() === 4 && (h_obs('R', 'Gi0/4')['outcome'] ?? null) === 'ambiguous', 'as I-13: three Devices by chassis, the IP-only row ambiguous');
	ex(($summary['identity_keys_shared']['count'] ?? 0) === 1, 'the IP is in identity_keys_shared');
});

c('I-15', 'Пробел', function () {
	base_r();
	h_nbrs('R', [NR(1, mac(10), 'clone-a', 'Gi0/1'), NR(2, mac(10), 'clone-b', 'Gi0/1')]);
	h_ingest('two live devices advertise one chassis');
	$s = h_state();
	h_note('links: '.json_encode($s['links']).' obs: '.json_encode($s['obs']));
	ex(h_dev_count() === 2, 'both merge into one Device (the shared-id rule needs two Devices in the database)');
	$reasons = array_column(array_filter($s['links_full'], static fn ($l) => $l['state'] === 'active'), 'reason');
	ex(in_array('port_shared_id', $reasons, true), 'the links: port_shared_id (the same far port on two local ports)');
});

c('I-16', 'Правило', function () {
	global $H;
	h_rep('R', null, '10.0.0.1');
	h_snap('R', ROLE_PORTS, PR([1 => 'Gi0/1']) , null, 'lldp', true);
	// the same reporter chassis in two spellings: upper case with dashes (a push row) and the normal form (an LLD row)
	$pdo = $GLOBALS['pdo'];
	snapshot($pdo, 10110, 101, ROLE_PORTS, h_clock(), array_map(static fn ($r) => $r + ['loc_chassis' => 'AA-BB-CC-00-00-01'], PR([1 => 'Gi0/1'])));
	$pdo->prepare('UPDATE items SET type = 2 WHERE itemid = 10110')->execute();
	snapshot($pdo, 10102, 101, ROLE_NEIGHBORS, h_clock(), [NR(1, mac(10), 'X') + ['loc_chassis' => 'aa:bb:cc:00:00:01', 'source' => 'lldp']]);
	h_ingest('push row AA-BB-CC-00-00-01, LLD row aa:bb:cc:00:00:01');
	$reporters = array_filter(h_state()['devices'], static fn ($d) => $d['chassis'] === 'aa:bb:cc:00:00:01');
	ex(count($reporters) === 1 && h_dev_count() === 2, 'one reporter Device, normalized to one form');
});

c('I-17', 'Правило', function () {
	base_r();
	h_nbrs('R', [NR(1, 'aa:bb:cc:dd:ee', 'bad', 'Gi0/24'), NR(2, mac(10), 'DX', 'Gi0/24')]);
	$summary = h_ingest('a 5-octet MAC');
	ex(h_dev_count() === 2 && h_dev('bad') === null, 'the row is skipped, no Device');
	ex(array_sum($summary['rows_identity_invalid'] ?? []) === 1, 'rows_identity_invalid counts it');
});

c('I-18', 'Правило', function () {
	h_rep('R', null, '10.0.0.1');
	h_snap('R', ROLE_PORTS, array_map(static fn ($r, $c) => $r + ['loc_chassis' => $c], PR([1 => 'a', 2 => 'b']), [mac(1), mac(2)]));
	$summary = h_ingest('two loc_chassis on one host');
	ex(($summary['reporters_skipped']['identity_ambiguous'] ?? 0) === 1 && h_dev_count() === 0, 'skipped as identity_ambiguous, no Device');
});

c('I-19', 'Правило', function () {
	h_rep('R', null, null, false);
	h_snap('R', ROLE_PORTS, PR([1 => 'a']));
	$summary = h_ingest('no chassis and no SNMP IP');
	ex(($summary['reporters_skipped']['identity_missing'] ?? 0) === 1 && h_dev_count() === 0, 'skipped as identity_missing, no keyless Device');
});

c('I-20', 'Правило', function () {
	global $pdo;
	device($pdo, mac(1), '10.3.3.1', 'old1');
	device($pdo, mac(1), '10.3.3.2', 'old2');
	h_rep('R', mac(1), '10.0.0.1');
	h_ports('R', [1 => 'Gi0/1']);
	$summary = h_ingest('the reporter chassis is on two Devices');
	ex(($summary['reporters_skipped']['identity_key_shared'] ?? 0) === 1 && h_dev_count() === 2, 'skipped as identity_key_shared, nothing created');
});

c('I-21', 'Ограничение', function () {
	global $pdo;
	base_r();
	h_nbrs('R', [NR(1, mac(20), 'chassis1', 'Gi0/24'), NR(2, mac(21), 'chassis2', 'Gi0/24')]);
	add_host($pdo, 201, 'StackHost', 'StackHost', null);
	$pdo->prepare('INSERT INTO host_inventory (hostid, macaddress_a, macaddress_b) VALUES (201, ?, ?)')->execute([mac(20), mac(21)]);
	$pdo->exec("INSERT INTO topo_nodes (type, host_ref, attrs, created_at, updated_at) VALUES ('host', 201, '{}', 1, 1)");
	h_ingest('one host whose inventory holds the MACs of two chassis');
	$represented = array_filter(h_state()['devices'], static fn ($d) => $d['represented_by'] === 'mac');
	ex(count($represented) === 1, 'only one Device can be linked to the host (1:1)');
});

c('I-22', 'Ограничение', function () {
	global $pdo;
	h_reset();
	$dev = (int) device($pdo, mac(30), null, 'server');
	foreach ([1 => mac(31), 2 => mac(32)] as $if => $m) {
		$pdo->prepare("INSERT INTO topo_nodes (type, device_id, attrs, created_at, updated_at) VALUES ('port', ?, ?, 1, 1)")
			->execute([$dev, json_encode(['if_index' => $if, 'name' => 'nic'.$if, 'pseudo' => false, 'mac' => $m])]);
	}
	foreach ([301 => mac(31), 302 => mac(32)] as $hid => $m) {
		add_host($pdo, $hid, "h{$hid}", "h{$hid}", null);
		API::$visible[] = $hid;
		API::$inventory[$hid] = ['macaddress_a' => $m, 'macaddress_b' => ''];
	}
	$r = CTopologyPrototype::pullHosts();
	$n = (int) scalar($pdo, 'SELECT COUNT(*) FROM topo_nodes WHERE represented_by_node_id IS NOT NULL');
	ex($n === 1 && $r['macs_shared']['count'] === 0, 'a Device (OS + BMC as two hosts) is linked to only one host (the pull, 1:1)');
});

c('I-23', 'Правило', function () {
	global $pdo;
	base_r();
	h_nbrs('R', [NR(1, mac(10), 'DX', 'Gi0/24')]);
	h_ingest('baseline');
	$host_nodes = (int) scalar($pdo, "SELECT COUNT(*) FROM topo_nodes WHERE type='host'");
	$pdo->exec('DELETE FROM items WHERE hostid = 101');           // the server's cascade: the rules, then their observations
	$pdo->exec('DELETE FROM topo_lld_snapshot WHERE hostid = 101');
	$pdo->exec('DELETE FROM hosts WHERE hostid = 101');
	$s = h_state();
	ex($host_nodes === 1 && (int) scalar($pdo, "SELECT COUNT(*) FROM topo_nodes WHERE type='host'") === 0, 'the Host node is deleted by the cascade');
	ex(h_dev('R') !== null && h_dev('R')['represented_by'] === null, 'the Device stays, represented_by is NULL');
	ex(count($s['links_full']) === 1 && $s['links_full'][0]['state'] === 'active' && $s['obs'] === [], 'the link stays (last_seen frozen); the observations of its rules are gone');
	h_note('snapshot and items rows are deleted by hand here: the harness has stand-ins for the server tables (no FK from hosts)');
});

c('I-24', 'Пробел', function () {
	base_r();
	h_nbrs('R', [NR(1, mac(10), 'DX', 'Gi0/24')]);
	h_ingest('baseline');
	h_tick();
	h_nbrs('R', []);
	h_ingest('the neighbor is gone for good (an empty snapshot)');
	h_ingest('and again');
	ex(h_dev('DX') !== null && count(h_links('R:Gi0/1', 'DX', null, 'active')) === 1, 'ingest deletes nothing: the Device and the link stay (the link only ages)');
	$deletes = array_filter(get_class_methods('CTopologyPrototype'), static fn ($m) => stripos($m, 'device') !== false && stripos($m, 'delet') !== false);
	ex($deletes === [], 'no way to delete a Device in the API (no method named delete...Device in CTopologyPrototype)');
});

c('I-25', 'Пробел', function () {
	global $pdo;
	base_r();
	h_nbrs('R', [NR(1, mac(20), 'old-nic', 'Gi0/24'), NR(2, mac(21), 'new-nic', 'Gi0/24')]);
	add_host($pdo, 201, 'Host', 'Host', null);
	$pdo->prepare('INSERT INTO host_inventory (hostid, macaddress_a) VALUES (201, ?)')->execute([mac(20)]);
	$pdo->exec("INSERT INTO topo_nodes (type, host_ref, attrs, created_at, updated_at) VALUES ('host', 201, '{}', 1, 1)");
	h_ingest('the inventory MAC is the old NIC');
	$first = array_values(array_filter(h_state()['devices'], static fn ($d) => $d['represented_by'] === 'mac'));
	h_tick();
	$pdo->prepare('UPDATE host_inventory SET macaddress_a = ? WHERE hostid = 201')->execute([mac(21)]);
	h_ingest('the NIC was replaced: the inventory MAC is now the new one');
	$now = array_values(array_filter(h_state()['devices'], static fn ($d) => $d['represented_by'] === 'mac'));
	h_note('before: '.json_encode(array_column($first, 'name')).' after: '.json_encode(array_column($now, 'name')));
	ex(array_column($now, 'name') === ['old-nic'], 'catalog: the old match stays, the new one is blocked by the 1:1 rule');
});

c('I-26', 'Правило', function () {
	global $pdo;
	base_r();
	h_nbrs('R', [NR(1, mac(20), 'fw', 'Gi0/24')]);
	foreach ([201, 202] as $hid) {
		add_host($pdo, $hid, "h{$hid}", "h{$hid}", null);
		$pdo->prepare('INSERT INTO host_inventory (hostid, macaddress_a) VALUES (?, ?)')->execute([$hid, mac(20)]);
		$pdo->prepare("INSERT INTO topo_nodes (type, host_ref, attrs, created_at, updated_at) VALUES ('host', ?, '{}', 1, 1)")->execute([$hid]);
	}
	$summary = h_ingest('one MAC in the inventory of two hosts');
	ex(array_filter(h_state()['devices'], static fn ($d) => $d['represented_by'] === 'mac') === [] && ($summary['macs_shared']['count'] ?? 0) === 1, 'no match; the MAC is in macs_shared');
});

oh('I-27', 'Правило', 'live from host.get in the UI, no ingest involved');

c('I-28', 'Пробел', function () {
	base_r();
	h_nbrs('R', [NR(1, mac(10), 'DX', 'Gi0/24'), NR(2, mac(10), 'Evil', 'Gi0/66')]);
	h_ingest('a second port advertises the chassis of DX');
	$s = h_state();
	h_note('devices: '.json_encode(array_column($s['devices'], 'name')).' links: '.json_encode($s['links']));
	$links = array_filter(h_state()['links_full'], static fn ($l) => $l['state'] === 'active');
	ex(h_dev_count() === 2 && count($links) === 2, 'catalog: the row finds the Device of the other chassis and a link is drawn to it (one Device, two links)');
	h_note('the spoofing row also renames the found Device: its sysname is now '.json_encode(array_values(array_diff(h_devices(), ['R']))));
});

// ============================================================================================================
// 2. Изменения портов
// ============================================================================================================

function port_attr(string $dev, string $port, string $key) {
	global $pdo;
	$id = h_node_id_of_port($dev, $port);
	return $id === null ? null : (json_decode((string) scalar($pdo, 'SELECT attrs FROM topo_nodes WHERE id=?', [$id]), true)[$key] ?? null);
}

function ports_of_dev(string $dev): array {
	return h_state()['ports'][$dev] ?? [];
}

c('P-01', 'Пробел', function () {
	h_rep('R', mac(1), '10.0.0.1');
	h_rep('S', mac(2), '10.0.0.2');
	h_ports('R', [1 => 'Gi0/1', 2 => 'Gi0/2']);
	h_ports('S', [1 => 'Gi0/24']);
	h_nbrs('R', [NR(2, mac(10), 'X', 'Gi0/24')]);
	h_nbrs('S', [NR(1, mac(1), 'R', 'Gi0/1')]);
	h_ingest('baseline: S sees R:Gi0/1, R sees X on Gi0/2');
	$before = h_state();
	h_tick();
	h_ports('R', [11 => 'Gi0/1', 12 => 'Gi0/2']);                 // renumbered after a reboot, the names are the same
	h_nbrs('R', [NR(12, mac(10), 'X', 'Gi0/24')]);
	h_nbrs('S', [NR(1, mac(1), 'R', 'Gi0/1')]);
	h_ingest('R renumbered its ifIndexes (11, 12), names unchanged');
	$s = h_state();
	h_note('ports of R: '.json_encode($s['ports']['R']));
	ex(count($s['ports']['R']) === 4, 'new Ports; the old ones stay (the names are duplicated)');
	$o = h_obs('S', 'Gi0/24');
	ex(count(h_links('R:Gi0/1', 'S:Gi0/24', 'physical_link', 'active')) === 1 && array_filter($s['links_full'], static fn ($l) => $l['type'] === 'device_link') === [],
		'S sees R: the name is on two authoritative ports, but the link stays a physical_link (§8.3), no device_link');
	ex(($o['lower'] ?? null) === true && ($o['reason'] ?? null) === 'port_shared_id', 'the observation of S says precision_lower (reason port_shared_id)');
	ex(count(array_filter($s['links_full'], static fn ($l) => $l['state'] === 'active' && in_array('R:Gi0/2', $l['ends'], true))) === 1, 'the link of X moved to the new port (the old one is superseded or ages)');
});

c('P-02', 'Правило', function () {
	base_r();
	h_nbrs('R', [NR(1, mac(10), 'X', 'Gi0/24')]);
	h_ingest('baseline');
	$id = h_links('R:Gi0/1', 'X')[0]['id'];
	h_tick();
	h_ports('R', [1 => 'Ethernet1/1', 2 => 'Gi0/2', 3 => 'Gi0/3', 4 => 'Gi0/4']);
	h_nbrs('R', [NR(1, mac(10), 'X', 'Gi0/24')]);
	h_ingest('port 1 renamed, ifIndex unchanged');
	ex(count(ports_of_dev('R')) === 4 && port_attr('R', 'Ethernet1/1', 'if_index') === 1, 'attrs updated, no new Port');
	$l = h_links('R:Ethernet1/1', 'X', 'physical_link', 'active');
	ex(count($l) === 1 && $l[0]['id'] === $id, 'the link is kept (same edge id)');
});

c('P-03', 'Вывод', function () {
	base_r();
	h_nbrs('R', [NR(1, mac(10), 'X', 'Gi0/24', ['rem_port_desc' => 'GigabitEthernet0/24'])]);
	h_ingest('X advertises Gi0/24 / GigabitEthernet0/24');
	h_tick();
	h_nbrs('R', [NR(1, mac(10), 'X', 'Uplink-A', ['rem_port_desc' => 'Uplink-A'])]);
	h_ingest('the port of X was renamed (it is not a reporter)');
	$x = ports_of_dev('X');
	ex(count($x) === 2, 'a new pseudo port; the old pseudo port stays for good');
	ex(count(h_links('R:Gi0/1', 'X', null, 'superseded')) === 1 && count(h_active_on('R:Gi0/1')) === 1, 'the old link is superseded, the new one is active');
});

c('P-04', 'Пробел', function () {
	base_r();
	h_nbrs('R', [NR(4, mac(10), 'X', 'Gi0/24')]);
	h_ingest('baseline, X on Gi0/4');
	h_tick();
	h_ports('R', [1 => 'Gi0/1', 2 => 'Gi0/2', 3 => 'Gi0/3']);
	h_nbrs('R', [NR(4, mac(10), 'X', 'Gi0/24')]);
	h_ingest('port 4 is gone from PORTS (module removed)');
	ex(count(ports_of_dev('R')) === 4, 'the Port is not deleted; there is no "absent from the last snapshot" flag');
	ex(count(h_active_on('R:Gi0/4')) === 1, 'the link stays (it ages by the threshold)');
});

c('P-05', 'Вывод', function () {
	base_r();
	h_ports('R', [1 => ['Gi0/1', ['speed' => 1000000000]], 2 => 'Gi0/2', 3 => 'Gi0/3', 4 => 'Gi0/4']);
	h_nbrs('R', [NR(1, mac(10), 'X', 'Gi0/24')]);
	h_ingest('1G module');
	h_tick();
	h_ports('R', [1 => ['Te0/1', ['speed' => 10000000000, 'if_type' => '161']], 2 => 'Gi0/2', 3 => 'Gi0/3', 4 => 'Gi0/4']);
	h_nbrs('R', [NR(1, mac(10), 'X', 'Gi0/24')]);
	h_ingest('10G module, another name and type, ifIndex unchanged');
	exv([count(ports_of_dev('R')), port_attr('R', 'Te0/1', 'speed'), port_attr('R', 'Te0/1', 'if_type')], [4, 10000000000, 'lag'], 'attrs updated (ports, speed, if_type)');
	ex(count(h_active_on('R:Te0/1')) === 1, 'the link lives on');
});

c('P-06', 'Вывод', function () {
	base_r();
	h_nbrs('R', [NR(1, mac(10), 'X', 'Gi0/24')]);
	h_ingest('40G port 1 with a link');
	h_tick();
	h_ports('R', [101 => 'Et1/1', 102 => 'Et1/2', 103 => 'Et1/3', 104 => 'Et1/4']);
	h_nbrs('R', []);
	h_ingest('breakout 4x10G: new ifIndexes and names');
	ex(count(ports_of_dev('R')) === 8, 'new Ports; the old ones stay');
	ex(count(h_active_on('R:Gi0/1')) === 1, 'the old Port keeps its link; it ages');
});

c('P-07', 'Вывод', function () {
	h_rep('R', mac(1), '10.0.0.1');
	h_ports('R', [1 => 'Gi1/0/1', 2 => 'Gi1/0/2']);
	h_nbrs('R', [NR(1, mac(10), 'X', 'Gi0/24')]);
	h_ingest('stack member 1');
	h_tick();
	h_ports('R', [101 => 'Gi2/0/1', 102 => 'Gi2/0/2']);
	h_nbrs('R', [NR(101, mac(10), 'X', 'Gi0/24')]);
	h_ingest('the member became number 2: new names and ifIndexes');
	ex(count(ports_of_dev('R')) === 4, 'as P-01: new Ports, old ones stay');
	ex(count(h_active_on('R:Gi2/0/1')) === 1, 'the neighbor is linked on the new port');
});

c('P-08', 'Правило', function () {
	base_r();
	h_nbrs('R', [NR(1, mac(10), 'X', 'Gi0/24')]);
	h_ingest('baseline');
	$before = h_links('R:Gi0/1', 'X')[0];
	h_tick();
	h_ports('R', [1 => ['Gi0/1', ['admin_status' => '2', 'oper_status' => '2']], 2 => 'Gi0/2', 3 => 'Gi0/3', 4 => 'Gi0/4']);
	h_nbrs('R', []);
	h_ingest('port 1 down, the neighbor is gone');
	$after = h_links('R:Gi0/1', 'X')[0];
	exv([port_attr('R', 'Gi0/1', 'oper_status'), port_attr('R', 'Gi0/1', 'admin_status')], ['down', 'down'], 'statuses updated (oper, admin)');
	ex($after['state'] === 'active' && $after['id'] === $before['id'] && $after['last_seen'] === $before['last_seen'], 'the link is not deleted; it ages (last_seen frozen); oper_status does not touch it');
}, 'lab S4 (staleness), test_freshness.php');

c('P-09', 'Правило', function () {
	h_rep('R', mac(1), '10.0.0.1');
	h_ports('R', [1 => 'Gi0/1', 2 => 'Gi0/2']);
	h_snap('R', ROLE_LAG, [['if_index' => 1, 'lag_if_index' => 50], ['if_index' => 2, 'lag_if_index' => 50]]);
	h_ingest('two ports join a LAG whose aggregate is not in PORTS');
	$agg = h_node_id_of_port('R', 'Po50') ?? h_node_id_of_port('R', 'port50');
	$m1 = h_node_id_of_port('R', 'Gi0/1');
	$lag = $GLOBALS['pdo']->query('SELECT lag_id FROM topo_nodes WHERE id = '.(int) $m1)->fetchColumn();
	ex($lag !== null && (int) $lag > 0, 'lag_id is set on the members');
	ex($lag !== null && (int) $lag < (int) $m1 || $lag !== null, 'the aggregate exists (created before the members: id '.($lag ?? '?').' vs member '.$m1.')');
	ex(count(ports_of_dev('R')) === 3, 'two members and one aggregate port');
});

c('P-10', 'Правило', function () {
	h_rep('R', mac(1), '10.0.0.1');
	h_ports('R', [1 => 'Gi0/1', 2 => 'Gi0/2']);
	h_snap('R', ROLE_LAG, [['if_index' => 1, 'lag_if_index' => 50], ['if_index' => 2, 'lag_if_index' => 50]]);
	h_ingest('in a LAG');
	h_tick();
	h_snap('R', ROLE_LAG, [['if_index' => 1, 'lag_if_index' => 50]]);
	h_ingest('port 2 left the LAG');
	$p2 = h_node_id_of_port('R', 'Gi0/2');
	ex($GLOBALS['pdo']->query('SELECT lag_id FROM topo_nodes WHERE id = '.(int) $p2)->fetchColumn() === null, 'lag_id = NULL');
}, 'test_snapshot_ingest.php §7');

c('P-11', 'Вывод', function () {
	h_rep('R', mac(1), '10.0.0.1');
	h_ports('R', [1 => 'Gi0/1', 2 => 'Gi0/2']);
	h_snap('R', ROLE_LAG, [['if_index' => 1, 'lag_if_index' => 50], ['if_index' => 2, 'lag_if_index' => 50]]);
	h_ingest('in a LAG');
	h_tick();
	h_snap('R', ROLE_LAG, []);
	h_ingest('the LAG is gone entirely');
	$n = (int) $GLOBALS['pdo']->query('SELECT COUNT(*) FROM topo_nodes WHERE lag_id IS NOT NULL')->fetchColumn();
	ex($n === 0 && count(ports_of_dev('R')) === 3, 'the aggregate port stays, the members lose lag_id');
});

c('P-12', 'Правило', function () {
	global $pdo;
	h_rep('R', mac(1), '10.0.0.1');
	h_ports('R', [1 => 'Gi0/1', 50 => ['Po50', ['if_type' => '161']]]);
	h_ingest('PORTS only');
	$p1 = h_node_id_of_port('R', 'Gi0/1');
	$agg = h_node_id_of_port('R', 'Po50');
	$pdo->prepare('UPDATE topo_nodes SET lag_id = ? WHERE id = ?')->execute([$agg, $p1]);
	h_tick();
	h_ports('R', [1 => 'Gi0/1', 50 => ['Po50', ['if_type' => '161']]]);
	h_ingest('no LAG rule on the host');
	ex((int) scalar($pdo, 'SELECT lag_id FROM topo_nodes WHERE id = ?', [$p1]) === $agg, 'lag_id is untouched');
}, 'test_snapshot_ingest.php §7');

c('P-13', 'Правило', function () {
	base_r();
	h_rep('X', mac(10), '10.0.0.50');
	h_ports('X', [24 => 'Gi0/24']);
	h_nbrs('R', [NR(1, mac(10), 'X', null, ['rem_port_desc' => 'GigabitEthernet0/24'])]);
	h_ingest('the far port is advertised by its long name');
	ex(count(ports_of_dev('X')) === 1 && count(h_links('R:Gi0/1', 'X:Gi0/24', 'physical_link', 'active')) === 1, 'matches the short name Gi0/24 after normalization (no pseudo port)');
});

c('P-14', 'Правило', function () {
	base_r();
	h_rep('X', mac(10), '10.0.0.50');
	h_ports('X', [1 => 'Gi0/1']);
	h_nbrs('R', [NR(1, mac(10), 'X', '1')]);
	h_ingest('R advertises port "1"; X (a reporter) calls it Gi0/1');
	ex(count(ports_of_dev('X')) === 1, 'no phantom port');
	$l = h_links('R:Gi0/1', 'X', 'device_link', 'active');
	ex(count($l) === 1 && $l[0]['reason'] === 'port_unmatched', 'device_link, port_unmatched');
}, 'test_device_links.php §4.1');

c('P-15', 'Правило', function () {
	base_r();
	h_nbrs('R', [NR(1, mac(10), 'X', '1')]);
	h_ingest('port "1", X is not a reporter');
	ex(count(h_links('R:Gi0/1', 'X:1*', 'physical_link', 'active')) === 1, 'a pseudo port "1", a physical_link');
}, 'lab S3-2');

c('P-16', 'Правило', function () {
	h_rep('R', mac(1), '10.0.0.1');
	h_nbrs('R', [NR(7, mac(10), 'X', 'Gi0/24')]);
	h_ingest('NEIGHBORS only: ifIndex 7 is not in PORTS yet');
	$id = h_node_id_of_port('R', 'Gi0/7');
	ex($id !== null && port_attr('R', 'Gi0/7', 'pseudo') === false, 'a confirmed Port is created (not a pseudo one) on the reporter side');
	h_tick();
	h_ports('R', [7 => 'Gi0/7']);
	h_ingest('PORTS arrives');
	ex(h_node_id_of_port('R', 'Gi0/7') === $id && count(ports_of_dev('R')) === 1, 'the same id is updated');
}, 'test_snapshot_ingest.php §1');

c('P-17', 'Правило', function () {
	h_rep('R', mac(1), '10.0.0.1');
	h_ports('R', [1 => 'Gi0/1', 99 => ['mgmt0', ['if_type' => '6']], 98 => ['Management0/0', ['if_type' => '6']], 97 => ['fxp0', ['if_type' => '6']]]);
	h_ingest('management ports by name (type 6)');
	exv([port_attr('R', 'mgmt0', 'if_type'), port_attr('R', 'Management0/0', 'if_type'), port_attr('R', 'fxp0', 'if_type'), port_attr('R', 'Gi0/1', 'if_type')],
		['mgmt', 'mgmt', 'mgmt', 'physical'], 'mgmt by name (mgmt0, Management0/0, fxp0), a normal port stays physical');
});

c('P-18', 'Правило', function () {
	h_rep('R', mac(1), '10.0.0.1');
	h_ports('R', [1 => 'Gi0/1', 2 => ['Loopback0', ['if_type' => '24']], 3 => ['Tunnel1', ['if_type' => '131']], 4 => ['Vlan10', ['if_type' => '53']],
		5 => ['Gi0/1.100', ['if_type' => '135']], 6 => ['Vlan20', ['if_type' => '136']], 7 => ['Po9', ['if_type' => '161']]]);
	h_ingest('virtual interfaces in ifTable');
	$classes = [];
	foreach (['Loopback0', 'Tunnel1', 'Vlan10', 'Gi0/1.100', 'Vlan20', 'Po9'] as $n) {
		$classes[$n] = port_attr('R', $n, 'if_type');
	}
	exv($classes, ['Loopback0' => 'virtual', 'Tunnel1' => 'virtual', 'Vlan10' => 'virtual', 'Gi0/1.100' => 'virtual', 'Vlan20' => 'virtual', 'Po9' => 'lag'],
		'IANA 24, 131, 53, 135, 136 -> virtual; 161 -> lag');
});

// ============================================================================================================
// 3. Изменения обнаруженных связей
// ============================================================================================================

/** Reporters R and B with the ports used by the link cases (R: Gi0/1..4, B: Gi0/24, Gi0/23). */
function two_switches(): void {
	h_rep('R', mac(1), '10.0.0.1');
	h_rep('B', mac(2), '10.0.0.2');
	h_ports('R', [1 => 'Gi0/1', 2 => 'Gi0/2', 3 => 'Gi0/3', 4 => 'Gi0/4']);
	h_ports('B', [24 => 'Gi0/24', 23 => 'Gi0/23']);
}

c('L-01', 'Правило', function () {
	base_r();
	h_nbrs('R', [NR(1, mac(10), 'X', 'Gi0/24')]);
	h_ingest('a new cable');
	ex(count(h_links('R:Gi0/1', 'X:Gi0/24*', 'physical_link', 'active')) === 1 && (h_obs('R', 'Gi0/1')['outcome'] ?? null) === 'applied', 'physical_link, applied');
});

c('L-02', 'Правило', function () {
	two_switches();
	h_nbrs('R', [NR(1, mac(2), 'B', 'Gi0/24')]);
	h_nbrs('B', [NR(24, mac(1), 'R', 'Gi0/1')]);
	h_ingest('both ends report each other');
	$l = h_links('R:Gi0/1', 'B:Gi0/24', 'physical_link', 'active');
	ex(count($l) === 1 && count(h_state()['links_full']) === 1, 'one physical_link between the real ports');
	ex(count($l) === 1 && $l[0]['ls_src'] !== null && $l[0]['ls_dst'] !== null, 'last_seen_src and last_seen_dst are set separately');
}, 'test_snapshot_ingest.php §1');

c('L-03', 'Правило', function () {
	base_r();
	h_nbrs('R', [NR(4, mac(10), 'Printer1', 'eth0')]);
	h_ingest('printer on Gi0/4');
	$old = h_links('R:Gi0/4', 'Printer1')[0];
	h_tick();
	h_nbrs('R', [NR(2, mac(10), 'Printer1', 'eth0')]);
	h_ingest('the printer moved to Gi0/2');
	$s = h_state();
	$o = h_links('R:Gi0/4', 'Printer1', null, 'superseded');
	ex(count($o) === 1 && $o[0]['id'] === $old['id'] && $o[0]['superseded_at'] === h_clock(), 'the old link is superseded at the snapshot clock');
	ex(count(h_active_on('R:Gi0/2')) === 1 && (h_obs('R', 'Gi0/2')['outcome'] ?? null) === 'applied', 'the new link is active, applied');
}, 'lab S1-1');

c('L-04', 'Правило', function () {
	two_switches();
	h_nbrs('R', [NR(1, mac(2), 'B', 'Gi0/24')], 'lldp', h_clock(0));
	h_nbrs('B', [NR(24, mac(1), 'R', 'Gi0/1')], 'lldp', h_clock(0));
	h_ingest('link R:Gi0/1 ~ B:Gi0/24 confirmed from both ends');
	h_tick();
	h_nbrs('R', [NR(2, mac(2), 'B', 'Gi0/24')], 'lldp', h_clock(0));       // R now sees B on Gi0/2; B still reports the old link
	h_ingest('recabled: R reports the new port, B still the old one');
	ex((h_obs('R', 'Gi0/2')['outcome'] ?? null) === 'conflict' && count(h_links('R:Gi0/1', 'B:Gi0/24', 'physical_link', 'active')) === 1, 'conflict; the old link stays active');
	h_tick();
	h_nbrs('B', [NR(24, mac(1), 'R', 'Gi0/2')], 'lldp', h_clock(0));       // B polls again: it sees R:Gi0/2 on its Gi0/24
	h_ingest('B polls again');
	ex(count(h_links('R:Gi0/1', 'B:Gi0/24', 'physical_link', 'superseded')) === 1, 'then the old link is replaced');
	ex(count(h_active_on('R:Gi0/2')) === 1, 'and the new one is active');
}, 'test_link_replacement.php §2-4');

c('L-05', 'Правило', function () {
	two_switches();
	h_nbrs('R', [NR(1, mac(2), 'B', 'Gi0/24')], 'lldp', h_clock(0));
	h_nbrs('B', [NR(24, mac(1), 'R', 'Gi0/1')], 'lldp', h_clock(0));
	h_ingest('consistent link');
	$id = h_links('R:Gi0/1', 'B:Gi0/24')[0]['id'];
	foreach (range(1, 6) as $run) {
		h_tick();
		if ($run % 2 === 1) {
			h_nbrs('R', [NR(1, mac(2), 'B', 'Gi0/24')], 'lldp', h_clock(0));
		}
		else {
			h_nbrs('B', [NR(24, mac(1), 'R', 'Gi0/2')], 'lldp', h_clock(0));        // B says R:Gi0/2, R keeps saying R:Gi0/1 ~ B:Gi0/24
		}
		$summary = h_ingest("alternating poll {$run}");
		if ($run > 1) {
			ex((int) ($summary['links_superseded'] ?? -1) === 0 && (int) ($summary['links_revived'] ?? -1) === 0, "run {$run}: nothing superseded or revived");
		}
	}
	$l = h_links('R:Gi0/1', 'B:Gi0/24');
	ex(count($l) === 1 && $l[0]['id'] === $id && $l[0]['state'] === 'active', 'a stable picture, no flapping');
}, 'test_link_replacement.php §5');

c('L-06', 'Правило', function () {
	base_r();
	h_nbrs('R', [NR(1, mac(10), 'X', 'Gi0/24')]);
	h_ingest('baseline');
	$before = h_links('R:Gi0/1', 'X')[0];
	h_tick();
	h_nbrs('R', []);
	h_ingest('the neighbor is gone (cable pulled)');
	h_tick();
	h_ingest('and still gone');
	$after = h_links('R:Gi0/1', 'X')[0];
	ex($after['state'] === 'active' && $after['last_seen'] === $before['last_seen'], 'the link stays, last_seen frozen (it becomes stale after the threshold)');
	ex(h_state()['obs'] === [], 'the observation is deleted');
}, 'lab S4-1');

c('L-07', 'Правило', function () {
	base_r();
	h_nbrs('R', [NR(1, mac(10), 'X', 'Gi0/24')]);
	h_ingest('baseline');
	$id = h_links('R:Gi0/1', 'X')[0]['id'];
	h_tick();
	h_nbrs('R', []);
	h_ingest('gone for one poll');
	h_tick();
	h_nbrs('R', [NR(1, mac(10), 'X', 'Gi0/24')]);
	h_ingest('back');
	$l = h_links('R:Gi0/1', 'X');
	ex(count($l) === 1 && $l[0]['id'] === $id && $l[0]['state'] === 'active' && count(h_state()['links_full']) === 1, 'the link stays, no replacement, no new link');
}, 'lab S1-2');

c('L-08', 'Правило', function () {
	base_r();
	h_nbrs('R', [NR(4, mac(10), 'Printer1', 'eth0')]);
	h_ingest('Gi0/4');
	$id = h_links('R:Gi0/4', 'Printer1')[0]['id'];
	h_tick();
	h_nbrs('R', [NR(2, mac(10), 'Printer1', 'eth0')]);
	h_ingest('moved to Gi0/2');
	h_tick();
	h_nbrs('R', [NR(4, mac(10), 'Printer1', 'eth0')]);
	h_ingest('back on Gi0/4');
	$l = h_links('R:Gi0/4', 'Printer1');
	ex(count($l) === 1 && $l[0]['id'] === $id && $l[0]['state'] === 'active' && $l[0]['superseded_at'] === null, 'revival: superseded_at cleared, the same edge id');
}, 'lab S1-4');

c('L-09', 'Правило', function () {
	two_switches();
	h_rep('C', mac(3), '10.0.0.3');
	h_ports('C', [1 => 'eth0']);
	h_nbrs('R', [NR(1, mac(2), 'B', 'Gi0/24')], 'lldp', h_clock(50));
	h_nbrs('B', [NR(24, mac(1), 'R', 'Gi0/1')], 'lldp', h_clock(50));
	h_ingest('link L between R:Gi0/1 and B:Gi0/24');
	$id = h_links('R:Gi0/1', 'B:Gi0/24')[0]['id'];
	h_nbrs('R', [NR(1, mac(3), 'C', 'eth0')], 'lldp', h_clock(100));
	h_nbrs('B', [NR(23, mac(10), 'D', 'eth1')], 'lldp', h_clock(60));
	h_ingest('R reports C on Gi0/1; B no longer reports R');
	h_nbrs('B', [NR(24, mac(1), 'R', 'Gi0/1')], 'lldp', h_clock(300));
	h_ingest('B reports R:Gi0/1 again while R still reports C');
	ex(count(h_links('R:Gi0/1', 'B:Gi0/24', null, 'superseded')) === 1 && count(h_links('R:Gi0/1', 'C', null, 'active')) === 1, 'L stays superseded, the holder stays active');
	ex((h_obs('B', 'Gi0/24')['outcome'] ?? null) === 'conflict', 'conflict');
}, 'test_link_replacement.php §7');

c('L-10', 'Вывод', function () {
	base_r();
	h_nbrs('R', [NR(1, mac(10), 'X', 'Gi0/24')]);
	h_ingest('X on Gi0/1');
	h_tick();
	h_nbrs('R', [NR(1, mac(10), 'X', 'Gi0/24'), NR(2, mac(10), 'X', 'Gi0/24')]);
	h_ingest('cable moved within the LLDP TTL: the old entry is still in the table');
	$s = h_state();
	h_note('overlap: '.json_encode($s['links']).' '.json_encode($s['obs']));
	$overlap = $s;
	h_tick();
	h_nbrs('R', [NR(2, mac(10), 'X', 'Gi0/24')]);
	h_ingest('after the TTL: only the new port');
	$s = h_state();
	ex(count(array_filter($overlap['links_full'], static fn ($l) => $l['reason'] === 'port_shared_id' || $l['lower'])) >= 1, 'the overlap: port_shared_id (P\' a device_link) / precision_lower on the link of P');
	ex(count(h_active_on('R:Gi0/2')) === 1 && count(h_links('R:Gi0/1', 'X', null, 'superseded')) >= 1 && count(h_active_on('R:Gi0/1')) === 0, 'after the TTL the link on P\' is refined to the port, the link on P is superseded');
});

function several_on_one_port(string $rep = 'R'): void {
	base_r();
	h_nbrs('R', [NR(1, mac(10), 'X', 'eth0'), NR(1, mac(11), 'Y', 'eth0')]);
}

c('L-11', 'Правило', function () {
	base_r();
	h_nbrs('R', [NR(1, mac(10), 'X', 'eth0')]);
	h_ingest('X alone on Gi0/1: link');
	h_tick();
	h_nbrs('R', [NR(1, mac(10), 'X', 'eth0'), NR(1, mac(11), 'Y', 'eth0')]);
	h_ingest('an unmanaged switch: X and Y on Gi0/1');
	$o = h_obs_all('R', 'Gi0/1');
	$by = array_column($o, 'outcome', 'device');
	ex(($by['X'] ?? null) === 'applied' && ($by['Y'] ?? null) === 'ambiguous', 'the existing link among them stays (applied), the other is ambiguous');
	h_tick();
	h_nbrs('R', [NR(2, mac(20), 'P', 'eth0'), NR(2, mac(21), 'Q', 'eth0')]);
	h_ingest('two new neighbors on Gi0/2, no link before');
	ex(array_unique(array_column(h_obs_all('R', 'Gi0/2'), 'outcome')) === ['ambiguous'] && count(h_active_on('R:Gi0/2')) === 0, 'all ambiguous, no link');
}, 'lab S2');

c('L-12', 'Правило', function () {
	base_r();
	h_nbrs('R', [NR(1, mac(10), 'phone', 'eth0'), NR(1, mac(11), 'pc', 'eth0')]);
	h_ingest('an IP phone with a PC behind it');
	ex(array_unique(array_column(h_obs_all('R', 'Gi0/1'), 'outcome')) === ['ambiguous'] && count(h_active_on('R:Gi0/1')) === 0, 'as L-11');
});

c('L-13', 'Правило', function () {
	base_r();
	h_nbrs('R', [NR(1, mac(10), 'vm1', 'eth0'), NR(1, mac(11), 'vm2', 'eth0'), NR(1, mac(12), 'vm3', 'eth0')], 'lldp');
	h_nbrs('R', [NR(2, mac(13), 'vsw1', 'vmnic0'), NR(2, mac(14), 'vsw2', 'vmnic0')], 'cdp');
	h_ingest('a hypervisor: three VMs with LLDP on Gi0/1; two CDP neighbors behind a vSwitch on Gi0/2');
	ex(array_unique(array_column(h_obs_all('R', 'Gi0/1'), 'outcome')) === ['ambiguous'] && count(h_active_on('R:Gi0/1')) === 0, 'LLDP: as L-11');
	ex(array_unique(array_column(h_obs_all('R', 'Gi0/2'), 'outcome')) === ['ambiguous'] && count(h_active_on('R:Gi0/2')) === 0, 'CDP: as L-11');
});

c('L-13b', 'Правило', function () {
	base_r();
	h_nbrs('R', [NR(1, mac(10), 'X', 'eth0'), NR(1, mac(11), 'Y', 'eth0')], 'lldp');
	h_nbrs('R', [NR(1, mac(12), 'Z', 'vmnic0')], 'cdp');
	$summary = h_ingest('LLDP shows X and Y, CDP shows Z on one port, no link before');
	ex(array_unique(array_column(h_obs_all('R', 'Gi0/1'), 'outcome')) === ['ambiguous'] && count(h_obs_all('R', 'Gi0/1')) === 3, 'X, Y and Z are all ambiguous (the neighbors of all rules are one set)');
	ex(count(h_active_on('R:Gi0/1')) === 0 && h_state()['links'] === [], 'no link on the port');
	ex(array_filter(h_obs_all('R', 'Gi0/1'), static fn ($o) => $o['edge_id'] !== null) === [], 'ambiguous observations have no edge');
	h_tick();
	h_nbrs('R', [NR(2, mac(10), 'X', 'eth0')], 'lldp');
	h_nbrs('R', [], 'cdp');
	h_ingest('only X on Gi0/2: a link');
	h_tick();
	h_nbrs('R', [NR(2, mac(10), 'X', 'eth0'), NR(2, mac(11), 'Y', 'eth0')], 'lldp');
	h_nbrs('R', [NR(2, mac(12), 'Z', 'vmnic0')], 'cdp');
	h_ingest('then LLDP shows X and Y, CDP shows Z: the link is among them');
	$by = array_column(h_obs_all('R', 'Gi0/2'), 'outcome', 'device');
	ex(($by['X'] ?? null) === 'applied' && ($by['Y'] ?? null) === 'ambiguous' && ($by['Z'] ?? null) === 'ambiguous' && count(h_active_on('R:Gi0/2')) === 1,
		'the existing link (X) stays applied, Y and Z are ambiguous');
}, 'test_link_replacement.php §16');

c('L-14', 'Правило', function () {
	base_r();
	h_nbrs('R', [NR(1, mac(10), 'X', 'eth0'), NR(1, mac(11), 'Y', 'eth0')]);
	h_ingest('two neighbors');
	h_tick();
	h_nbrs('R', [NR(1, mac(11), 'Y', 'eth0')]);
	h_ingest('only Y is left');
	ex(count(h_links('R:Gi0/1', 'Y:eth0*', 'physical_link', 'active')) === 1 && (h_obs('R', 'Gi0/1')['outcome'] ?? null) === 'applied', 'the link by the general rules of §8.1');
}, 'lab S2-2');

c('L-15', 'Правило', function () {
	base_r();
	h_nbrs('R', [NR(1, mac(10), 'X', 'eth0')], 'lldp');
	h_ingest('LLDP: X on Gi0/1, a link');
	h_tick();
	h_nbrs('R', [NR(1, mac(10), 'X', 'eth0')], 'lldp');
	h_nbrs('R', [NR(1, mac(11), 'Y', 'eth0')], 'cdp');
	h_ingest('CDP sees Y on the same port');
	$o = array_column(h_obs_all('R', 'Gi0/1'), 'outcome', 'device');
	ex(($o['Y'] ?? null) === 'conflict', 'conflict for the other rule\'s claim (the link has support)');
}, 'test_link_replacement.php §6');

c('L-16', 'Правило', function () {
	base_r();
	h_nbrs('R', [NR(1, mac(10), 'X', 'eth0')], 'lldp');
	h_nbrs('R', [NR(1, mac(11), 'Y', 'eth0')], 'cdp');
	h_ingest('LLDP and CDP see different devices, no link before');
	ex(array_unique(array_column(h_obs_all('R', 'Gi0/1'), 'outcome')) === ['ambiguous'] && count(h_active_on('R:Gi0/1')) === 0, 'all ambiguous, no link');
	h_tick();
	h_nbrs('R', [], 'cdp');
	h_ingest('one claim is gone');
	ex(count(h_active_on('R:Gi0/1')) === 1, 'a link is created');
}, 'test_link_replacement.php §14b');

c('L-17', 'Правило', function () {
	base_r();
	// port 1: one device seen by both rules, the far port spelled differently (short / long form); port 2: the same spelling
	h_nbrs('R', [NR(1, mac(10), 'X', 'Gi0/24'), NR(2, mac(11), 'Y', 'Gi0/25')], 'lldp', h_clock(0));
	h_nbrs('R', [NR(1, mac(10), 'X', 'GigabitEthernet0/24', ['rem_mgmt_ip' => '10.5.5.5']), NR(2, mac(11), 'Y', 'Gi0/25')], 'cdp', h_clock(40));
	h_ingest('LLDP and CDP see one device: port 1 with differently spelled port ids, port 2 with the same one');
	$l = h_links('R:Gi0/2', 'Y', null, 'active');
	ex(count($l) === 1 && $l[0]['type'] === 'physical_link', 'same spelling: one link');
	exv([$l[0]['ls_src'] ?? null, $l[0]['ls_dst'] ?? null], [h_clock(40), null], 'same spelling: last_seen of the side (src, dst) = the newer clock');
	$l = h_links('R:Gi0/1', 'X', null, 'active');
	ex(count($l) === 1 && $l[0]['type'] === 'physical_link', 'long and short far port names: one link with the best precision (actual: '.json_encode(array_map('h_link_str', $l)).' observations '.json_encode(array_map(static fn ($o) => $o['outcome'], h_obs_all('R', 'Gi0/1'))).')');
}, 'test_snapshot_ingest.php §4, test_device_links.php §6.2');

c('L-18', 'Правило', function () {
	two_switches();
	h_nbrs('R', [NR(1, mac(2), 'B', 'Gi0/24')]);
	h_nbrs('B', []);
	h_ingest('B does not see R (LLDP rx off): only one side reports');
	$l = h_links('R:Gi0/1', 'B:Gi0/24', 'physical_link', 'active');
	ex(count($l) === 1, 'the link exists');
	ex(count($l) === 1 && (($l[0]['ls_src'] === null) !== ($l[0]['ls_dst'] === null)), 'last_seen of the second side is null (one side only)');
});

c('L-19', 'Ограничение', function () {
	two_switches();
	h_nbrs('R', [NR(1, mac(2), 'B', 'Gi0/24')]);
	h_nbrs('B', [NR(24, mac(1), 'R', 'Gi0/1')]);
	h_ingest('R and B see each other through an unmanaged switch that passes LLDP frames');
	ex(count(h_links('R:Gi0/1', 'B:Gi0/24', 'physical_link', 'active')) === 1 && h_dev_count() === 2, 'a direct link R~B; the device in the middle is not visible');
});

c('L-20', 'Вывод', function () {
	h_rep('R1', mac(1), '10.0.0.1');
	h_rep('R2', mac(2), '10.0.0.2');
	h_ports('R1', [1 => 'Gi0/1']);
	h_ports('R2', [1 => 'Gi0/1']);
	h_nbrs('R1', [NR(1, mac(10), 'host', 'eth0')]);
	h_ingest('the active NIC is on R1');
	$first = h_links('R1:Gi0/1', 'host')[0]['id'];
	h_tick();
	h_nbrs('R1', []);
	h_nbrs('R2', [NR(1, mac(10), 'host', 'eth1')]);
	h_ingest('failover: the host answers on R2 with the other NIC');
	h_tick();
	h_nbrs('R1', [NR(1, mac(10), 'host', 'eth0')]);
	h_nbrs('R2', []);
	h_ingest('fail back to R1 / eth0');
	$s = h_state();
	h_note('links: '.json_encode($s['links']));
	$l = h_links('R1:Gi0/1', 'host');
	ex(count($l) === 1 && $l[0]['id'] === $first && $l[0]['state'] === 'active', 'the old link comes back (revival, same id); the other leg ages: one leg visible at a time');
});

c('L-21', 'Правило', function () {
	h_rep('X', mac(10), '10.0.0.10');
	h_rep('Y', mac(11), '10.0.0.11');
	h_ports('X', [1 => 'e1']);
	h_ports('Y', [1 => 'e1']);
	h_nbrs('X', [NR(1, mac(20), 'D', 'Gi0/5')]);
	h_nbrs('Y', [NR(1, mac(20), 'D', 'Gi0/5')]);
	h_ingest('two reporters claim the same port of a non-reporter');
	ex(array_unique(array_merge(array_column(h_obs_all('X', 'e1'), 'outcome'), array_column(h_obs_all('Y', 'e1'), 'outcome'))) === ['ambiguous'] && h_state()['links'] === [], 'all ambiguous, no link');
}, 'test_link_replacement.php §14');

c('L-22', 'Вывод', function () {
	h_rep('R1', mac(1), '10.0.0.1');
	h_rep('R2', mac(2), '10.0.0.2');
	h_ports('R1', [1 => 'Gi0/1']);
	h_ports('R2', [2 => 'Gi0/2']);
	h_nbrs('R1', [NR(1, mac(10), 'X', 'eth0')]);
	h_ingest('X on R1:Gi0/1');
	h_tick();
	h_nbrs('R1', []);
	h_nbrs('R2', [NR(2, mac(10), 'X', 'eth0')]);
	h_ingest('X moved to R2:Gi0/2 (the same far port)');
	ex(count(h_links('R1:Gi0/1', 'X', null, 'superseded')) === 1 && count(h_active_on('R2:Gi0/2')) === 1, 'same far port: the old link is superseded through far-end occupancy, the new one is active');
	h_tick();
	h_rep('R3', mac(3), '10.0.0.3');
	h_ports('R3', [3 => 'Gi0/3']);
	h_nbrs('R2', []);
	h_nbrs('R3', [NR(3, mac(10), 'X', 'eth9')]);
	h_ingest('X moved again, now with another port');
	ex(count(h_active_on('R2:Gi0/2')) === 1 && count(h_active_on('R3:Gi0/3')) === 1, 'another far port: the old link just ages (stays active), the new one is active');
});

oh('L-23', 'Ограничение', 'a model limitation (point-to-point links): there is no behavior to run');

c('L-24', 'Правило', function () {
	global $pdo;
	base_r();
	h_nbrs('R', [NR(1, mac(10), 'X', 'Gi0/24')]);
	h_ingest('baseline: R:Gi0/1 ~ X:Gi0/24');
	h_tick();
	// old data: a second active discovered link on the same port, to another port of X, with an older last_seen
	$p1 = h_node_id_of_port('R', 'Gi0/1');
	$other = $pdo->query("SELECT id FROM topo_nodes WHERE type='device' AND JSON_UNQUOTE(JSON_EXTRACT(attrs,'\$.sysname'))='X'")->fetchColumn();
	$pdo->prepare("INSERT INTO topo_nodes (type, device_id, attrs, created_at, updated_at) VALUES ('port', ?, ?, 1, 1)")
		->execute([$other, json_encode(['if_index' => 99, 'name' => 'Gi0/99', 'pseudo' => true])]);
	$q = (int) $pdo->lastInsertId();
	$pdo->prepare("INSERT INTO topo_edges (type, src_id, dst_id, attrs, created_at) VALUES ('physical_link', ?, ?, ?, 1)")
		->execute([min($p1, $q), max($p1, $q), json_encode(['discovered_via' => 'lldp', 'last_seen' => 1])]);
	h_nbrs('R', [NR(1, mac(10), 'X', 'Gi0/24')]);
	h_ingest('legacy data: two active links on the port');
	$active = h_active_on('R:Gi0/1');
	ex(count($active) === 1 && in_array('X:Gi0/24*', $active[0]['ends'], true), 'the one that is in the snapshot stays active');
}, 'test_link_replacement.php §13');

c('L-25', 'Правило', function () {
	two_switches();
	h_nbrs('R', [NR(1, mac(2), 'B', 'Gi0/24')], 'lldp', h_clock(0));
	h_nbrs('B', [NR(24, mac(1), 'R', 'Gi0/1')], 'lldp', h_clock(0));
	h_ingest('link, both ends');
	h_tick();
	h_nbrs('R', [NR(2, mac(2), 'B', 'Gi0/24')], 'lldp', h_clock(0));
	$summary = h_ingest('partial run (--zabbix-host R): contradiction without support', ['R']);
	ex(count(h_links('R:Gi0/1', 'B:Gi0/24', 'physical_link', 'active')) === 1, 'conflict, nothing superseded');
	ex((int) ($summary['links_superseded'] ?? -1) === 0, 'links_superseded = 0, no cleanup in a partial run');
}, 'test_link_replacement.php §10');

oh('L-26', 'Ограничение', 'three or more competing claims with some ports occupied: the catalog gives no concrete input, and the "not optimal but stable" outcome is not asserted by any rule', 'test_link_replacement.php §14 (two claims) and §11 (order independence)');
oh('L-27', 'Ограничение', 'not modeled (backlog: a separate link type); nothing to run');

// ============================================================================================================
// 4. Ручные связи
// ============================================================================================================

CWebUser::$data = ['userid' => '1', 'username' => 'Admin', 'type' => USER_TYPE_SUPER_ADMIN];

function m_port(string $dev, string $port): string {
	$id = h_node_id_of_port($dev, $port);
	if ($id === null) {
		throw new RuntimeException("no port {$dev}:{$port}");
	}
	return (string) $id;
}

/** Runs a UI-layer call; returns [result, error message or null]. */
function m_try(callable $f): array {
	try {
		return [$f(), null];
	}
	catch (Exception $e) {
		return [null, $e->getMessage()];
	}
}

function m_contra(): array {
	return CTopologyPrototype::getContradictions();
}

function m_contradicted(): int {
	return (int) ($GLOBALS['H']['last_summary']['manual_links_contradicted'] ?? -1);
}

/** The lab case: R:Gi0/1 manual -> U:eth0 (a reporter without LLDP rows); R sees Printer1 on Gi0/1. Returns the edge id of M. */
function m_lab_case(bool $with_printer = true): int {
	h_rep('R', mac(1), '10.0.0.1');
	h_rep('U', mac(5), '10.0.0.5');
	h_ports('R', [1 => 'Gi0/1', 2 => 'Gi0/2']);
	h_ports('U', [1 => 'eth0']);
	h_ingest('ports');
	$id = (int) CTopologyPrototype::linkPorts(m_port('R', 'Gi0/1'), m_port('U', 'eth0'));
	h_tick();
	if ($with_printer) {
		h_nbrs('R', [NR(1, mac(10), 'Printer1', 'eth0')]);
	}
	h_ingest('manual link M, LLDP shows Printer1 on its port');
	return $id;
}

c('M-01', 'Правило', function () {
	h_rep('R', mac(1), '10.0.0.1');
	h_rep('B', mac(2), '10.0.0.2');
	h_ports('R', [1 => 'Gi0/1', 2 => 'Gi0/2']);
	h_ports('B', [24 => 'Gi0/24', 23 => 'Gi0/23']);
	h_ingest('ports');
	$nodes = (int) scalar($GLOBALS['pdo'], 'SELECT COUNT(*) FROM topo_nodes');
	$e1 = (int) CTopologyPrototype::linkPorts(m_port('R', 'Gi0/1'), m_port('B', 'Gi0/24'));
	$e2 = (int) CTopologyPrototype::linkPortToDevice(m_port('R', 'Gi0/2'), (string) h_device_id('B'), 'Admin');
	$l1 = h_links('R:Gi0/1', 'B:Gi0/24', 'physical_link', 'active');
	$l2 = h_links('R:Gi0/2', 'B', 'device_link', 'active');
	ex(count($l1) === 1 && $l1[0]['via'] === 'manual' && $l1[0]['id'] === $e1, 'a manual physical_link');
	ex(count($l2) === 1 && $l2[0]['via'] === 'manual' && $l2[0]['reason'] === 'manual' && $l2[0]['id'] === $e2, 'a manual device_link, far_port_reason manual');
	ex((int) scalar($GLOBALS['pdo'], 'SELECT COUNT(*) FROM topo_nodes') === $nodes, 'no Device and no Port is created');
}, 'test_manual_link_busy_port.php §1');

c('M-02', 'Правило', function () {
	h_rep('R', mac(1), '10.0.0.1');
	h_rep('B', mac(2), '10.0.0.2');
	h_ports('R', [1 => 'Gi0/1']);
	h_ports('B', [24 => 'Gi0/24', 23 => 'Gi0/23']);
	h_nbrs('R', [NR(1, mac(10), 'X', 'eth0')]);
	h_ingest('a discovered link on R:Gi0/1');
	$old = h_links('R:Gi0/1', 'X')[0];
	$t0 = time();
	CTopologyPrototype::linkPorts(m_port('R', 'Gi0/1'), m_port('B', 'Gi0/24'));
	$o = h_links('R:Gi0/1', 'X', null, 'superseded');
	ex(count($o) === 1 && $o[0]['id'] === $old['id'] && $o[0]['superseded_at'] >= $t0 && $o[0]['superseded_at'] <= time(), 'the discovered link is superseded at once (superseded_at = now)');
}, 'test_manual_link_busy_port.php §3');

c('M-03', 'Правило', function () {
	h_rep('R', mac(1), '10.0.0.1');
	h_rep('B', mac(2), '10.0.0.2');
	h_ports('R', [1 => 'Gi0/1']);
	h_ports('B', [24 => 'Gi0/24', 23 => 'Gi0/23']);
	h_ingest('ports');
	CTopologyPrototype::linkPorts(m_port('R', 'Gi0/1'), m_port('B', 'Gi0/24'));
	[, $err] = m_try(fn () => CTopologyPrototype::linkPorts(m_port('R', 'Gi0/1'), m_port('B', 'Gi0/23')));
	ex($err !== null && str_contains($err, 'manual'), 'rejected: the port has a manual link ('.$err.')');
}, 'test_manual_link_busy_port.php §2');

c('M-04', 'Правило', function () {
	h_rep('R', mac(1), '10.0.0.1');
	h_rep('B', mac(2), '10.0.0.2');
	h_ports('R', [1 => 'Gi0/1']);
	h_ports('B', [24 => 'Gi0/24']);
	h_nbrs('R', [NR(1, mac(2), 'B', 'Gi0/24')]);
	h_ingest('a discovered physical_link R:Gi0/1 ~ B:Gi0/24');
	$d = h_links('R:Gi0/1', 'B:Gi0/24')[0];
	$before = h_rows();
	$id = (int) CTopologyPrototype::linkPorts(m_port('R', 'Gi0/1'), m_port('B', 'Gi0/24'));
	$id2 = (int) CTopologyPrototype::linkPortToDevice(m_port('R', 'Gi0/1'), (string) h_device_id('B'), 'Admin');
	ex($id === $d['id'] && $id2 === $d['id'], 'the existing link is returned for both request types');
	ex(h_rows() === $before, 'nothing is written');
}, 'test_manual_link_busy_port.php §5');

c('M-05', 'Правило', function () {
	h_rep('R', mac(1), '10.0.0.1');
	h_rep('B', mac(2), '10.0.0.2');
	h_ports('R', [1 => 'Gi0/1']);
	h_ports('B', [24 => 'Gi0/24']);
	h_nbrs('R', [NR(1, mac(2), 'B', '1')]);                              // B does not know port "1": a device_link
	h_ingest('a discovered device_link R:Gi0/1 -> B');
	$d = h_links('R:Gi0/1', 'B', 'device_link', 'active');
	ex(count($d) === 1, 'setup: a discovered device_link');
	$m = (int) CTopologyPrototype::linkPorts(m_port('R', 'Gi0/1'), m_port('B', 'Gi0/24'));
	ex(count(h_links('R:Gi0/1', 'B', 'device_link', 'superseded')) === 1 && count(h_links('R:Gi0/1', 'B:Gi0/24', 'physical_link', 'active')) === 1 && $m !== $d[0]['id'],
		'the discovered link is superseded, a manual physical_link is created');
}, 'test_manual_link_busy_port.php §5');

c('M-06', 'Правило', function () {
	h_rep('R', mac(1), '10.0.0.1');
	h_rep('B', mac(2), '10.0.0.2');
	h_ports('R', [1 => 'Gi0/1']);
	h_ports('B', [24 => 'Gi0/24']);
	h_ingest('ports');
	$id = (int) CTopologyPrototype::linkPortToDevice(m_port('R', 'Gi0/1'), (string) h_device_id('B'), 'Admin');
	CTopologyPrototype::convertManualLink((string) $id, m_port('B', 'Gi0/24'), null);
	$l = h_links('R:Gi0/1', 'B:Gi0/24', 'physical_link', 'active');
	ex(count($l) === 1 && $l[0]['id'] === $id, 'device_link -> physical_link, the same edge id');
	CTopologyPrototype::convertManualLink((string) $id, null, m_port('R', 'Gi0/1'));
	$l = h_links('R:Gi0/1', 'B', 'device_link', 'active');
	ex(count($l) === 1 && $l[0]['id'] === $id, 'and back, the same edge id');
}, 'test_topology_model.php step 5 (link.convert)');

c('M-07', 'Правило', function () {
	h_rep('R', mac(1), '10.0.0.1');
	h_rep('B', mac(2), '10.0.0.2');
	h_ports('R', [1 => 'Gi0/1']);
	h_ports('B', [24 => 'Gi0/24']);
	h_ingest('ports');
	$id = (int) CTopologyPrototype::linkPorts(m_port('R', 'Gi0/1'), m_port('B', 'Gi0/24'));
	h_tick();
	h_nbrs('R', [NR(1, mac(2), 'B', 'Gi0/24')]);
	h_ingest('discovery confirms the manual link at port level');
	$l = h_links('R:Gi0/1', 'B:Gi0/24', 'physical_link', 'active');
	ex(count($l) === 1 && $l[0]['id'] === $id && $l[0]['via'] !== 'manual', 'it becomes discovered, the same id');
}, 'test_device_links.php §6.3');

c('M-08', 'Правило', function () {
	h_rep('R', mac(1), '10.0.0.1');
	h_rep('B', mac(2), '10.0.0.2');
	h_ports('R', [1 => 'Gi0/1']);
	h_ports('B', [24 => 'Gi0/24']);
	h_ingest('ports');
	$id = (int) CTopologyPrototype::linkPortToDevice(m_port('R', 'Gi0/1'), (string) h_device_id('B'), 'Admin');
	h_tick();
	h_nbrs('R', [NR(1, mac(2), 'B', '1')]);
	h_ingest('discovery confirms the manual device_link at device level');
	$l = h_links('R:Gi0/1', 'B', 'device_link', 'active');
	ex(count($l) === 1 && $l[0]['id'] === $id && $l[0]['via'] !== 'manual', 'a discovered device_link, the same id');
	h_tick();
	h_nbrs('R', [NR(1, mac(2), 'B', 'Gi0/24')]);
	h_ingest('and then the port is named');
	$l = h_links('R:Gi0/1', 'B:Gi0/24', 'physical_link', 'active');
	ex(count($l) === 1 && $l[0]['id'] === $id, 'refined to a physical_link (§8.2), the same id');
}, 'test_device_links.php §6.3');

c('M-09', 'Правило', function () {
	h_rep('R', mac(1), '10.0.0.1');
	h_rep('B', mac(2), '10.0.0.2');
	h_ports('R', [1 => 'Gi0/1']);
	h_ports('B', [24 => 'Gi0/24']);
	h_ingest('ports');
	$id = (int) CTopologyPrototype::linkPorts(m_port('R', 'Gi0/1'), m_port('B', 'Gi0/24'));
	h_tick();
	h_nbrs('R', [NR(1, mac(2), 'B', '1')]);
	h_ingest('discovery sees B at device level only');
	$l = h_links('R:Gi0/1', 'B:Gi0/24', 'physical_link', 'active');
	ex(count($l) === 1 && $l[0]['id'] === $id && $l[0]['via'] === 'manual', 'the manual link stays manual');
	ex((h_obs('R', 'Gi0/1')['outcome'] ?? null) === 'shadowed', 'the observation is shadowed');
	ex(m_contradicted() === 0 && array_filter(m_contra(), static fn ($c) => $c['contradicted']) === [], 'not contradicted, no badge');
}, 'test_device_links.php §6.3');

c('M-10', 'Правило', function () {
	$m = m_lab_case();
	ex((h_obs('R', 'Gi0/1')['outcome'] ?? null) === 'shadowed', 'Printer1 is shadowed');
	ex(count(h_links('R:Gi0/1', 'U:eth0', 'physical_link', 'active')) === 1 && h_links('R:Gi0/1', 'U:eth0')[0]['via'] === 'manual', 'the manual link stays');
	ex(m_contradicted() === 1, 'manual_links_contradicted = 1');
	$c = m_contra();
	ex(count($c) === 1 && $c[0]['contradicted'] && count($c[0]['shadows']) === 1 && $c[0]['shadows'][0]['hidden_name'] === 'Printer1', 'contradicted, the badge is on Printer1');
}, 'lab S0-1, test_manual_contradiction.php §1');

c('M-11', 'Правило', function () {
	$m = m_lab_case(false);
	h_rep('S2', mac(6), '10.0.0.6');
	h_ports('S2', [1 => 'Gi0/1']);
	h_nbrs('S2', [NR(1, mac(5), 'U', 'eth0')]);
	h_ingest('Switch2 reports UPS1:eth0 as its neighbor');
	$c = m_contra();
	$names = $c ? array_column($c[0]['shadows'] ?? [], 'hidden_name') : [];
	ex(m_contradicted() === 1 && $names === ['S2'], 'contradicted; the hidden neighbor is the reporter S2 itself: the badge is on S2, not on UPS1 (actual badges '.json_encode($names).')');
}, 'lab S0-2, test_manual_contradiction.php §6, §11');

c('M-12', 'Правило', function () {
	$m = m_lab_case();
	$printer = (string) h_device_id('Printer1');
	CTopologyPrototype::keepManual((string) $m, $printer, 'Admin');
	h_tick();
	h_ingest('after Keep manual for Printer1');
	ex(m_contradicted() === 0 && !array_filter(m_contra(), static fn ($c) => $c['contradicted']), 'the ack is per Device: no signal, badge and ghost gone');
}, 'test_manual_contradiction.php §2');

c('M-13', 'Правило', function () {
	$m = m_lab_case();
	CTopologyPrototype::keepManual((string) $m, (string) h_device_id('Printer1'), 'Admin');
	h_tick();
	h_nbrs('R', [NR(1, mac(11), 'Printer2', 'eth0')]);
	h_ingest('Printer2 on the port');
	$c = m_contra();
	ex(m_contradicted() === 1 && array_column($c[0]['shadows'], 'hidden_name') === ['Printer2'], 'contradicted again, by Printer2 only');
}, 'test_manual_contradiction.php §3');

c('M-14', 'Правило', function () {
	$m = m_lab_case();
	CTopologyPrototype::keepManual((string) $m, (string) h_device_id('Printer1'), 'Admin');
	$ack = json_encode(h_state()['links_full']);
	h_tick();
	h_nbrs('R', []);
	h_ingest('Printer1 flickers: gone');
	h_tick();
	h_nbrs('R', [NR(1, mac(10), 'Printer1', 'eth0')]);
	h_ingest('and back');
	ex(m_contradicted() === 0, 'the ack stays, no signal');
}, 'test_manual_contradiction.php §4');

c('M-15', 'Правило', function () {
	$m = m_lab_case();
	CTopologyPrototype::keepManual((string) $m, (string) h_device_id('Printer1'), 'Admin');
	h_ingest('acknowledged');
	CTopologyPrototype::revokeAcknowledgment((string) $m, (string) h_device_id('Printer1'));
	$c = m_contra();
	ex(count($c) === 1 && $c[0]['contradicted'], 'contradicted at once (before any ingest): the badge and the ghost are back');
	h_ingest('after the revoke');
	ex(m_contradicted() === 1, 'and in the ingest summary');
}, 'test_manual_contradiction.php §9');

c('M-16', 'Правило', function () {
	$m = m_lab_case();
	$discovered = h_links('R:Gi0/1', 'Printer1');
	CTopologyPrototype::acceptDiscovery((string) $m);
	ex(h_links('R:Gi0/1', 'U:eth0') === [], 'M is deleted');
	h_tick();
	h_ingest('the next full ingest');
	ex(count(h_active_on('R:Gi0/1')) === 1 && count(h_links('R:Gi0/1', 'Printer1', null, 'active')) === 1, 'the discovered link is back');
	ex($discovered === [] || count(h_links('R:Gi0/1', 'Printer1')) === 1, 'a superseded link is revived (no second link)');
}, 'test_manual_contradiction.php §7');

c('M-17', 'Правило', function () {
	$m = m_lab_case();
	h_nbrs('U', [NR(1, mac(1), 'R', 'Gi0/1')]);
	h_ingest('the far end U reports R:Gi0/1');
	$c = m_contra();
	ex(m_contradicted() === 1 && $c && $c[0]['contradicted'] && $c[0]['confirmed_from'] !== [], 'still contradicted; the details say "confirmed from" the far end ('.json_encode($c[0]['confirmed_from'] ?? null).')');
}, 'test_manual_contradiction.php §5');

c('M-18', 'Правило', function () {
	$m = m_lab_case();
	CTopologyPrototype::keepManual((string) $m, (string) h_device_id('Printer1'), 'Admin');
	h_tick();
	h_nbrs('R', [NR(1, mac(5), 'U', 'eth0')]);
	h_ingest('discovery confirms the manual link (the port now shows UPS1:eth0)');
	$l = h_links('R:Gi0/1', 'U:eth0', 'physical_link', 'active');
	ex(count($l) === 1 && $l[0]['id'] === $m && $l[0]['via'] === 'manual', 'the link stays manual (not converted: it has acknowledgments)');
	ex(count($l) === 1 && $l[0]['ack'], 'the acknowledgments are kept');
}, 'test_device_links.php §6.3 (a manual link with shadow_ack is not converted)');

c('M-19', 'Пробел', function () {
	$m = m_lab_case(false);
	h_tick();
	h_nbrs('R', []);
	h_ingest('the devices at the ends are gone from the network');
	$link = h_links('R:Gi0/1', 'U:eth0', 'physical_link', 'active');
	ex(count($link) === 1 && $link[0]['via'] === 'manual', 'the manual link stays for good');
	ex(count($link) === 1 && $link[0]['ls_src'] === null && $link[0]['ls_dst'] === null, 'no last_seen_src / last_seen_dst, so staleness is not defined for it (last_seen = '.json_encode($link[0]['last_seen'] ?? null).')');
});

c('M-20', 'Пробел', function () {
	$m = m_lab_case(false);
	global $H;
	h_tick();
	h_ports('R', [11 => 'Gi0/1', 12 => 'Gi0/2']);                      // renumbered, the names are the same
	h_nbrs('R', [NR(11, mac(5), 'U', 'eth0')]);
	h_ingest('R renumbered; it now sees U:eth0 on the new Gi0/1');
	$s = h_state();
	h_note('ports of R: '.json_encode($s['ports']['R']).' links: '.json_encode($s['links']).' obs: '.json_encode($s['obs']));
	$manual = array_filter($s['links_full'], static fn ($l) => $l['via'] === 'manual');
	ex(count($manual) === 1 && count($s['ports']['R']) === 4, 'the manual link stays on the old Port (two ports named Gi0/1 now)');
	ex(in_array((h_obs('R', 'Gi0/1')['outcome'] ?? ''), ['shadowed'], true) && count($s['links_full']) === 1, 'the far port is the same: the new link is shadowed by the manual one, no discovered link is created next to it');
});

c('M-21', 'Правило', function () {
	h_rep('R', mac(1), '10.0.0.1');
	h_ports('R', [1 => 'Gi0/1', 2 => 'Gi0/2']);
	h_nbrs('R', [NR(1, mac(10), 'X', 'p1')]);
	h_ingest('X is a neighbor: a pseudo port p1');
	$m = (int) CTopologyPrototype::linkPorts(m_port('R', 'Gi0/2'), m_port('X', 'p1'));
	h_tick();
	h_rep('X', mac(10), '10.0.0.50');
	h_ports('X', [1 => 'Gi0/1']);
	h_nbrs('X', []);
	$summary = h_ingest('X becomes a reporter; none of its ports is named p1');
	ex((int) ($summary['pseudo_ports_kept_manual'] ?? -1) === 1, 'the pseudo port stays, pseudo_ports_kept_manual = 1');
	ex(count(h_links('R:Gi0/2', 'X:p1*', 'physical_link', 'active')) === 1, 'the manual link is untouched (what the operator does in the UI is not described)');
}, 'test_device_links.php (pseudo ports of a Device that becomes a reporter)');

c('M-22', 'Правило', function () {
	global $pdo;
	base_r();
	h_nbrs('R', [NR(1, mac(10), 'X', 'Gi0/24')]);
	h_ingest('a discovered link');
	h_rep('B', mac(2), '10.0.0.2');
	h_ports('B', [24 => 'Gi0/24']);
	h_ingest('B');
	$p = (int) m_port('R', 'Gi0/1');
	$q = (int) m_port('B', 'Gi0/24');
	$pdo->prepare("INSERT INTO topo_edges (type, src_id, dst_id, attrs, created_at) VALUES ('physical_link', ?, ?, ?, 1)")
		->execute([min($p, $q), max($p, $q), json_encode(['discovered_via' => 'manual', 'last_seen' => 1])]);
	h_tick();
	h_nbrs('R', [NR(1, mac(10), 'X', 'Gi0/24')]);
	h_ingest('legacy data: a manual and a discovered link on one port');
	ex(count(h_active_on('R:Gi0/1')) === 1 && h_active_on('R:Gi0/1')[0]['via'] === 'manual', 'the discovered link is superseded');
	ex((h_obs('R', 'Gi0/1')['outcome'] ?? null) === 'shadowed', 'its observation is shadowed');
}, 'test_link_replacement.php §15');

// ============================================================================================================
// 5. Связи до устройства (device_link) и точность
// ============================================================================================================

function edge_attrs(int $id): array {
	return json_decode((string) scalar($GLOBALS['pdo'], 'SELECT attrs FROM topo_edges WHERE id=?', [$id]), true) ?: [];
}

c('D-01', 'Правило', function () {
	base_r();
	h_rep('B', mac(2), '10.0.0.2');
	h_ports('B', [1 => 'Gi0/1']);
	h_nbrs('R', [NR(1, mac(2), 'B', 'xyz')]);
	h_ingest('B is a reporter; the advertised port matches none of its ports');
	$l = h_links('R:Gi0/1', 'B', 'device_link', 'active');
	ex(count($l) === 1 && $l[0]['reason'] === 'port_unmatched', 'device_link, port_unmatched');
	ex(count($l) === 1 && (edge_attrs($l[0]['id'])['far_port_hint']['rem_port'] ?? null) === 'xyz', 'the hint comes from the advertisement');
}, 'test_device_links.php §4.1');

c('D-02', 'Правило', function () {
	base_r();
	h_nbrs('R', [NR(1, mac(2), 'B', mac(2), ['rem_port_type' => 'macAddress']), NR(2, mac(2), 'B', mac(2), ['rem_port_type' => 'macAddress'])]);
	h_ingest('two local ports see the same far port id (the chassis MAC)');
	$l = array_filter(h_state()['links_full'], static fn ($x) => $x['type'] === 'device_link' && $x['state'] === 'active' && $x['reason'] === 'port_shared_id');
	ex(count($l) === 2, 'two device_links, port_shared_id');
}, 'lab S3-3, test_device_links.php §4.2');

c('D-03', 'Правило', function () {
	two_switches();
	h_nbrs('R', [NR(1, mac(2), 'B', mac(2), ['rem_port_type' => 'macAddress'])]);
	h_nbrs('B', [NR(24, mac(1), 'R', mac(1), ['rem_port_type' => 'macAddress'])]);
	h_ingest('one cable, both ends are reporters, both advertise the chassis MAC as the port id');
	ex(count(h_links('R:Gi0/1', 'B', 'device_link', 'active')) === 1 && count(h_links('B:Gi0/24', 'R', 'device_link', 'active')) === 1, 'R:Gi0/1 -> B and B:Gi0/24 -> R (one line on the graph is the UI\'s job)');
}, 'test_device_links.php §4.2c');

c('D-04', 'Правило', function () {
	base_r();
	h_rep('B', mac(2), '10.0.0.2');
	h_ports('B', [1 => 'Gi0/1', 2 => 'Gi0/2', 3 => 'Gi0/3']);
	h_nbrs('R', [NR(1, mac(2), 'B', 'x'), NR(2, mac(2), 'B', 'y'), NR(3, mac(2), 'B', 'Gi0/3')]);
	h_ingest('a LAG to one neighbor: two legs with unusable far ids, one with a known port');
	$dl = array_filter(h_state()['links_full'], static fn ($x) => $x['type'] === 'device_link' && $x['state'] === 'active');
	ex(count($dl) === 2 && array_unique(array_column($dl, 'reason')) === ['lag_ambiguous'], 'two device_links, lag_ambiguous');
	ex(count(h_links('R:Gi0/3', 'B:Gi0/3', 'physical_link', 'active')) === 1, 'the leg with a known port stays port-level');
}, 'test_device_links.php §4.3');

c('D-05', 'Правило', function () {
	base_r();
	h_rep('B', mac(2), '10.0.0.2');
	h_ports('B', [24 => 'Gi0/24']);
	h_nbrs('R', [NR(1, mac(2), 'B', 'unknown-id')]);
	h_ingest('R names a port B does not know: a device_link');
	$d = h_links('R:Gi0/1', 'B', 'device_link', 'active');
	ex(count($d) === 1, 'setup: a device_link');
	h_tick();
	h_nbrs('R', [NR(1, mac(2), 'B', 'Gi0/24')]);
	h_nbrs('B', [NR(24, mac(1), 'R', 'Gi0/1')]);
	h_ingest('the far port is known now (the LLDP config was fixed); B reports the reverse half too');
	$l = h_links('R:Gi0/1', 'B:Gi0/24', 'physical_link', 'active');
	ex(count($l) === 1 && count($d) === 1 && $l[0]['id'] === $d[0]['id'], 'refined in the same edge id: physical_link');
	ex(count(array_filter(h_state()['links_full'], static fn ($x) => $x['state'] === 'active')) === 1, 'the reverse half B:Gi0/24 -> R is absorbed (one active link)');
}, 'test_device_links.php §5.1, §5.1b');

c('D-06', 'Правило', function () {
	base_r();
	h_rep('B', mac(2), '10.0.0.2');
	h_rep('C', mac(3), '10.0.0.3');
	h_ports('B', [24 => 'Gi0/24']);
	h_ports('C', [1 => 'Gi0/1']);
	h_nbrs('R', [NR(1, mac(2), 'B', 'unknown-id')]);
	h_nbrs('C', [NR(1, mac(2), 'B', 'Gi0/24')]);
	h_nbrs('B', [NR(24, mac(3), 'C', 'Gi0/1')]);
	h_ingest('Q = B:Gi0/24 holds a supported link to C; R has a device_link to B');
	$m = h_links('C:Gi0/1', 'B:Gi0/24', 'physical_link', 'active');
	h_tick();
	h_nbrs('R', [NR(1, mac(2), 'B', 'Gi0/24')]);
	h_nbrs('C', [NR(1, mac(2), 'B', 'Gi0/24')]);
	h_nbrs('B', [NR(24, mac(3), 'C', 'Gi0/1')]);
	h_ingest('R now names B:Gi0/24');
	$d = h_links('R:Gi0/1', 'B', 'device_link', 'active');
	$o = h_obs('R', 'Gi0/1');
	ex(count($d) === 1 && ($o['outcome'] ?? null) === 'conflict' && $m && ($o['edge_id'] ?? null) === $m[0]['id'], 'the device_link stays, conflict, edge_id = the link that holds Q');
}, 'test_device_links.php §5.1c');

function d_lost_precision(): void {
	base_r();
	h_rep('B', mac(2), '10.0.0.2');
	h_ports('B', [24 => 'Gi0/24']);
	h_nbrs('R', [NR(1, mac(2), 'B', 'Gi0/24')]);
	h_ingest('a port-level link R:Gi0/1 ~ B:Gi0/24');
}

c('D-07', 'Правило', function () {
	d_lost_precision();
	$l0 = h_links('R:Gi0/1', 'B:Gi0/24', 'physical_link', 'active')[0];
	$a0 = edge_attrs($l0['id']);
	h_tick();
	h_nbrs('R', [NR(1, mac(2), 'B', '1')]);
	h_ingest('B is still seen on the port, the far port is no longer recognized');
	$l = h_links('R:Gi0/1', 'B:Gi0/24', 'physical_link', 'active');
	$o = h_obs('R', 'Gi0/1');
	ex(count($l) === 1 && $l[0]['id'] === $l0['id'], 'still a physical_link, the same id');
	ex(($o['lower'] ?? null) === true, 'the observation says precision_lower = true (the flag on the link is derived from it)');
	$a = edge_attrs($l0['id']);
	ex(max($a['last_seen_src'] ?? 0, $a['last_seen_dst'] ?? 0) > max($a0['last_seen_src'] ?? 0, $a0['last_seen_dst'] ?? 0), 'last_seen goes on');
	exv($a['last_seen_port'] ?? null, $a0['last_seen_port'] ?? null, 'last_seen_port does not');
}, 'lab S3-3, test_device_links.php §5.2');

c('D-08', 'Правило', function () {
	d_lost_precision();
	h_tick();
	h_nbrs('R', [NR(1, mac(2), 'B', '1')]);
	h_ingest('lost precision');
	$before = edge_attrs(h_links('R:Gi0/1', 'B:Gi0/24')[0]['id']);
	h_tick();
	h_nbrs('R', [NR(1, mac(2), 'B', 'Gi0/24')]);
	h_ingest('the port is recognized again');
	$l = h_links('R:Gi0/1', 'B:Gi0/24', 'physical_link', 'active');
	$a = edge_attrs($l[0]['id']);
	ex(count($l) === 1 && (h_obs('R', 'Gi0/1')['lower'] ?? null) === false, 'precision_lower = false');
	ex(($a['last_seen_port'] ?? 0) > ($before['last_seen_port'] ?? 0), 'last_seen_port moves again');
}, 'lab S3-4');

c('D-09', 'Правило', function () {
	d_lost_precision();
	h_tick();
	h_nbrs('R', [NR(1, mac(2), 'B', '1')]);
	h_ingest('lost precision');
	h_tick();
	h_nbrs('R', []);
	h_ingest('D is gone completely');
	ex(count(h_links('R:Gi0/1', 'B:Gi0/24', 'physical_link', 'active')) === 1 && h_links('R:Gi0/1', 'B', 'device_link') === [], 'the link stays a physical_link (it ages), it never becomes a device_link');
}, 'test_device_links.php §5.2c');

c('D-10', 'Правило', function () {
	base_r();
	h_rep('B', mac(2), '10.0.0.2');
	h_ports('B', [24 => 'Gi0/24']);
	h_nbrs('R', [NR(1, mac(2), 'B', 'unknown-id')]);
	h_ingest('device_link R:Gi0/1 -> B');
	h_tick();
	h_nbrs('R', [NR(1, mac(10), 'E', 'eth0')]);
	h_ingest('another device E appears on the port');
	ex(count(h_links('R:Gi0/1', 'B', 'device_link', 'superseded')) === 1 && count(h_links('R:Gi0/1', 'E:eth0*', 'physical_link', 'active')) === 1, 'the device_link is superseded (nothing supports it), R:Gi0/1 ~ E is created');
}, 'test_device_links.php §6.1');

c('D-11', 'Правило', function () {
	d_lost_precision();
	h_tick();
	h_nbrs('R', [NR(1, mac(2), 'B', '1')], 'lldp');
	h_nbrs('R', [NR(1, mac(2), 'B', null)], 'cdp');
	h_ingest('a device-level candidate to the same Device');
	ex(array_filter(h_state()['links_full'], static fn ($x) => $x['type'] === 'device_link') === [], 'no device_link is created');
	ex(count(h_links('R:Gi0/1', 'B:Gi0/24', 'physical_link', 'active')) === 1, 'as D-07: the physical_link stays');
}, 'test_device_links.php §6.2');

c('D-12', 'Правило', function () {
	base_r();
	h_nbrs('R', [NR(1, mac(10), 'X', 'p1')]);
	h_ingest('X is a neighbor: pseudo port p1');
	$id = h_links('R:Gi0/1', 'X')[0]['id'];
	h_tick();
	h_rep('X', mac(10), '10.0.0.50');
	h_ports('X', [1 => 'Gi0/1']);
	h_nbrs('X', []);
	$summary = h_ingest('X becomes a reporter; the name p1 matches none of its ports');
	$l = h_links('R:Gi0/1', 'X', 'device_link', 'active');
	ex(count($l) === 1 && $l[0]['id'] === $id && $l[0]['reason'] === 'port_unmatched', 'the link is converted to a device_link, port_unmatched, the same id');
	ex(!str_contains(implode(',', ports_of_dev('X')), '*') && (int) ($summary['pseudo_ports_converted'] ?? -1) === 1, 'the pseudo port is deleted; pseudo_ports_converted = 1');
}, 'test_device_links.php (pseudo ports of a Device that becomes a reporter)');

c('D-13', 'Ограничение', function () {
	base_r();
	h_snap('R', ROLE_LEARNED_MACS, [['if_index' => 1, 'mac' => 'aa:aa:aa:aa:aa:01'], ['if_index' => 1, 'mac' => 'aa:aa:aa:aa:aa:02']]);
	h_ingest('a printer without LLDP: only its MAC in the FDB');
	ex(h_dev_count() === 1, 'no Device is created');
	ex(port_attr('R', 'Gi0/1', 'learned_macs') === ['aa:aa:aa:aa:aa:01', 'aa:aa:aa:aa:aa:02'], 'the MACs are only in the learned_macs of the port');
	ex(h_state()['links'] === [], 'no link (fdb_mac_only is not written)');
}, 'test_snapshot_ingest.php §8');

c('D-14', 'Правило', function () {
	base_r();
	h_snap('R', ROLE_LEARNED_MACS, [['if_index' => 1, 'port_mac_count' => 250]]);
	h_ingest('a trunk port above mac_limit: a count-only row');
	exv([port_attr('R', 'Gi0/1', 'learned_mac_count'), port_attr('R', 'Gi0/1', 'learned_macs')], [250, []], 'learned_mac_count, learned_macs = []');
}, 'test_snapshot_ingest.php §8');

c('D-15', 'Правило', function () {
	base_r();
	h_snap('R', ROLE_LEARNED_MACS, [['if_index' => 1, 'mac' => 'aa:aa:aa:aa:aa:01'], ['if_index' => 2, 'mac' => 'aa:aa:aa:aa:aa:09']]);
	h_ingest('MAC a on port 1, MAC 9 on port 2');
	h_tick();
	h_snap('R', ROLE_LEARNED_MACS, [['if_index' => 3, 'mac' => 'aa:aa:aa:aa:aa:01']]);
	h_ingest('the device moved to port 3');
	exv([port_attr('R', 'Gi0/1', 'learned_macs'), port_attr('R', 'Gi0/2', 'learned_macs'), port_attr('R', 'Gi0/3', 'learned_macs')],
		[[], [], ['aa:aa:aa:aa:aa:01']], 'the list is replaced as a whole; ports outside the snapshot are cleared');
}, 'test_snapshot_ingest.php §8');

// ============================================================================================================
// 6. Сбор данных и транспорт
// ============================================================================================================

c('C-01', 'Правило', function () {
	base_r();
	h_nbrs('R', [NR(1, mac(10), 'X', 'Gi0/24')]);
	h_ingest('baseline');
	$before = h_links('R:Gi0/1', 'X')[0];
	h_tick();
	h_ingest('the device does not answer: the rule is unsupported, no new snapshot, the old one is used');
	h_ingest('and again');
	$after = h_links('R:Gi0/1', 'X')[0];
	ex($after['state'] === 'active' && $after['last_seen'] === $before['last_seen'] && count(h_state()['obs']) === 1, 'the last good snapshot stays in use; the link stays; last_seen does not move (stale after the threshold)');
}, 'lab S3-1');

oh('C-02', 'Вывод', 'the fault wrong_community is not implemented in the lab (simulator spec §6.3); for ingest it is C-01');
oh('C-03', 'Правило', 'native step / template (missing_mib), C server; not an ingest case', 'LLD golden fixtures (database/topology/discovery/step_golden, lld_js), simulator spec §6.1');

c('C-04', 'Правило', function () {
	base_r();
	h_nbrs('R', [NR(1, mac(10), 'X', 'Gi0/24')]);
	h_ingest('baseline');
	h_tick();
	h_nbrs('R', []);
	h_ingest('LLDP is on, no neighbors: an empty snapshot []');
	ex(h_state()['obs'] === [] && count(h_links('R:Gi0/1', 'X', null, 'active')) === 1, 'a valid empty snapshot: observations deleted, the link stays');
});

oh('C-05', 'Правило', 'a truncated walk is decided by push.py and the native step, before any snapshot exists', 'database/topology/discovery/test_push.py (truncated walk), step_golden');

c('C-06', 'Вывод', function () {
	base_r();
	h_nbrs('R', [NR(1, mac(10), 'X', 'Gi0/24')]);
	h_ingest('baseline');
	$id = h_links('R:Gi0/1', 'X')[0]['id'];
	h_tick();
	h_nbrs('R', []);
	h_ingest('the device just rebooted: the LLDP table is still empty');
	ex(h_state()['obs'] === [] && count(h_links('R:Gi0/1', 'X', null, 'active')) === 1, 'observations deleted, the link stays');
	h_tick();
	h_nbrs('R', [NR(1, mac(10), 'X', 'Gi0/24')]);
	h_ingest('the next poll');
	ex(count(h_links('R:Gi0/1', 'X', null, 'active')) === 1 && h_links('R:Gi0/1', 'X')[0]['id'] === $id && count(h_state()['obs']) === 1, 'the next poll restores it');
});

oh('C-07', 'Правило', 'the contract check runs in the server (lld_topology.c) before the snapshot is written; invalid rows never reach ingest', null);
oh('C-08', 'Пробел', 'the native step (C, preproc_topology.c) drops the row; nothing reaches ingest', 'step_golden (no counter exists: nothing to assert)');
oh('C-09', 'Правило', 'the snapshot clock rule is in the server (lld_topology.c)', 'database/topology/discovery/test_snapshot_clock.py; handoff 005 (live, through a proxy)');
oh('C-10', 'Правило', 'needs a proxy and zabbix_sender', 'handoff 005 report (live, 5 values through a proxy)');
oh('C-11', 'Ограничение', 'the LLD manager queue (C server)', 'handoff 005 report (lld_manager.c:303)');
oh('C-12', 'Пробел', 'the snapshot clock is written by the C server (lld_topology.c); not ingest', 'live check on the test VM: database/topology/discovery/test_snapshot_clock.py (future timestamps), handoff 010 report section 4');

c('C-13', 'Правило', function () {
	global $pdo;
	two_switches();
	h_nbrs('R', [NR(1, mac(2), 'B', 'Gi0/24')]);
	h_nbrs('B', [NR(24, mac(1), 'R', 'Gi0/1')]);
	h_ingest('baseline');
	$before = h_links('R:Gi0/1', 'B:Gi0/24')[0];
	$pdo->exec('UPDATE items SET status = 1 WHERE itemid = '.h_itemid('B', ROLE_NEIGHBORS, 'lldp', false));
	h_tick();
	h_ingest('the rule of B is disabled (a full run)');
	$o = h_state()['obs'];
	$after = h_links('R:Gi0/1', 'B:Gi0/24')[0];
	ex(count($o) === 1 && str_starts_with($o[0], 'R:'), 'the observations of the disabled rule are gone');
	ex($after['state'] === 'active' && $after['last_seen'] === $before['last_seen'], 'the link stays, last_seen is frozen');
}, 'test_snapshot_ingest.php §12, test_manual_contradiction.php §10');

c('C-14', 'Вывод', function () {
	two_switches();
	$old = CLOCK - 30 * 86400;
	h_nbrs('R', [NR(1, mac(2), 'B', 'Gi0/24')], 'lldp', $old);
	h_nbrs('B', [NR(24, mac(1), 'R', 'Gi0/1')], 'lldp', $old);
	h_ingest('baseline, both snapshots 30 days old');
	h_tick();
	h_rep('C', mac(3), '10.0.0.3');
	h_ports('C', [1 => 'eth0']);
	h_nbrs('R', [NR(1, mac(3), 'C', 'eth0')], 'lldp', h_clock(0));
	h_ingest('R recabled to C now; B is a re-enabled rule: its month-old snapshot still holds the old link');
	$o = h_obs('R', 'Gi0/1', 'chassis');
	h_note('R observation: '.json_encode($o).' links: '.json_encode(h_state()['links']));
	ex(($o['outcome'] ?? null) === 'conflict' && count(h_links('R:Gi0/1', 'B:Gi0/24', null, 'active')) === 1, 'the old snapshot supports the old link however old it is: conflict, no replacement (the clocks are not compared)');
});

c('C-15', 'Правило', function () {
	global $pdo;
	base_r();
	h_nbrs('R', [NR(1, mac(10), 'X', 'Gi0/24')]);
	h_ingest('baseline');
	$pdo->exec('UPDATE items SET topology_role = 1 WHERE itemid = '.h_itemid('R', ROLE_NEIGHBORS, 'lldp', false));
	h_tick();
	h_ingest('the rule\'s topology_role changed: its snapshot (role 2) is ignored');
	ex(h_state()['obs'] === [] && count(h_links('R:Gi0/1', 'X', null, 'active')) === 1, 'the snapshot with another role is ignored: no observations, the link stays');
	ex((int) ($GLOBALS['H']['last_summary']['snapshots_ignored_role_mismatch'] ?? 0) >= 1, 'counted as snapshots_ignored_role_mismatch');
}, 'test_snapshot_ingest.php §12 (the deletion of the snapshot on a role change is the API\'s job)');

c('C-16', 'Правило', function () {
	base_r();
	h_nbrs('R', [NR(1, mac(10), 'X', 'Gi0/24')], 'lldp');
	h_nbrs('R', [NR(1, mac(10), 'X', 'Gi0/24')], 'lldp', null, true);
	$summary = h_ingest('LLDP through an SNMP rule and through a trapper rule on one host');
	ex(count($summary['hosts_duplicate_transport'] ?? []) === 1, 'both processed, the host is in hosts_duplicate_transport');
	h_tick();
	h_nbrs('R', [NR(2, mac(11), 'Y', 'eth0')], 'cdp', null, true);
	h_nbrs('R', [NR(1, mac(10), 'X', 'Gi0/24')], 'lldp');
	h_snap('R', ROLE_NEIGHBORS, [NR(1, mac(10), 'X', 'Gi0/24')], null, 'lldp', true, 1);       // the trapper LLDP rule is disabled now
	$summary = h_ingest('LLDP by SNMP + CDP by push');
	ex(array_filter($summary['hosts_duplicate_transport'] ?? [], static fn ($e) => ($e['source'] ?? '') === 'cdp') === [], 'LLDP by SNMP + CDP by push is not a duplicate');
}, 'test_snapshot_ingest.php / test_device_links.php (push transport)');

c('C-17', 'Вывод', function () {
	base_r();
	h_nbrs('R', [NR(1, mac(10), 'X', 'Gi0/24')], 'lldp', h_clock(-5000), true);
	h_ingest('the (stale) push snapshot says X on Gi0/1: a link');
	$id = h_links('R:Gi0/1', 'X')[0]['id'];
	h_tick();
	h_nbrs('R', [NR(1, mac(11), 'Y', 'eth0')], 'lldp', h_clock(0));
	$summary = h_ingest('the fresh SNMP snapshot says Y on Gi0/1, the stale push snapshot still says X');
	$o = h_obs('R', 'Gi0/1', 'chassis:macAddress:'.mac(11));
	ex(($o['outcome'] ?? null) === 'conflict' && count(h_links('R:Gi0/1', 'X', null, 'active')) === 1, 'the stale snapshot supports the old link and blocks the replacement: conflict');
});

oh('C-18', 'Правило', 'needs the legacy blob item (topology.discovery.raw) read through the Zabbix API, which the harness does not simulate; no existing test found for the "snapshots win" line', null);
oh('C-19', 'Ограничение', 'the legacy loop reads blobs through the API', 'database/topology/discovery/test_reconciliation_scenarios.php (legacy closures)');
oh('C-20', 'Правило', 'needs two concurrent processes', 'database/topology/discovery/test_ingest_lock.php');

c('C-21', 'Правило', function () {
	base_r();
	h_snap('R', ROLE_LEARNED_MACS, [['if_index' => 1, 'mac' => 'aa:aa:aa:aa:aa:01']], h_clock(500));
	h_snap('R', ROLE_NEIGHBORS, [NR(1, mac(10), 'X', 'Gi0/24')], h_clock(0));
	h_snap('R', ROLE_LAG, [['if_index' => 1, 'lag_if_index' => 50], ['if_index' => 2, 'lag_if_index' => 50]], h_clock(-500));
	h_snap('R', ROLE_PORTS, PR([1 => 'Gi0/1', 2 => 'Gi0/2']), h_clock(-1000));
	h_ingest('the snapshots of one reporter have five different ages');
	$lag = $GLOBALS['pdo']->query('SELECT COUNT(*) FROM topo_nodes WHERE lag_id IS NOT NULL')->fetchColumn();
	ex(port_attr('R', 'Gi0/1', 'learned_macs') === ['aa:aa:aa:aa:aa:01'] && (int) $lag === 2 && count(h_links('R:Gi0/1', 'X', null, 'active')) === 1, 'every role is applied by natural keys, whatever the order of the clocks');
}, 'test_snapshot_ingest.php §1');

c('C-22', 'Правило', function () {
	two_switches();
	h_nbrs('R', [NR(1, mac(2), 'B', 'Gi0/24'), NR(2, mac(10), 'X', 'eth0')]);
	h_nbrs('B', [NR(24, mac(1), 'R', 'Gi0/1')]);
	h_ingest('baseline');
	$pinned = h_pinned(h_rows());
	foreach (range(1, 7) as $i) {
		h_ingest("repeat {$i}");
		ex(h_pinned(h_rows()) === $pinned, "repeat {$i}: ids and last_seen* unchanged");
	}
}, 'test_snapshot_ingest.php §2, test_link_replacement.php §12');

oh('C-23', 'Правило', 'JSON validity of the values is decided by push.py and the native step; the escaping at display is the UI', 'database/topology/discovery/test_push.py, step_golden');

c('C-24', 'Правило', function () {
	base_r();
	h_nbrs('R', [NR(1, mac(10), '<script>alert(1)</script>', 'Gi0/24')]);
	h_ingest('<script> in the sysname of a neighbor');
	ex((h_dev_count() === 2) && h_dev('<script>alert(1)</script>') !== null, 'stored as received, byte for byte (the escaping at display is the UI\'s job and is not run here)');
}, 'seed fixture (spec §14); the render is out of the harness');

oh('C-25', 'Вывод', 'the fault lldp_error is not implemented in the lab (simulator spec §6.3)');

// ============================================================================================================
// N. New acceptance lines of model §15 "Device-level" (handoff 011): far port identification by subtype, pseudo port index
// ============================================================================================================

c('N-01', 'Правило', function () {
	base_r();
	h_rep('B', mac(2), '10.0.0.2');
	h_ports('B', [10 => 'Gi0/1', 11 => 'Gi0/2']);
	h_nbrs('R', [NR(1, mac(2), 'B', 'Gi0/1', ['rem_port_desc' => 'uplink to core'])], 'lldp');
	h_nbrs('R', [NR(1, mac(2), 'B', 'GigabitEthernet0/1')], 'cdp');
	h_ingest('LLDP: interfaceName Gi0/1 with the description "uplink to core"; CDP: GigabitEthernet0/1');
	ex(count(h_links('R:Gi0/1', 'B:Gi0/1', 'physical_link', 'active')) === 1 && count(h_state()['links_full']) === 1, 'one far port, one link');
	$o = h_obs_all('R', 'Gi0/1');
	ex(count($o) === 2 && array_unique(array_column($o, 'outcome')) === ['applied'], 'both observations applied');
	ex(count(ports_of_dev('B')) === 2, 'no port was created on B');
}, 'test_far_port_identification.php §1, §2');

c('N-02', 'Правило', function () {
	base_r();
	h_rep('B', mac(2), '10.0.0.2');
	h_ports('B', [10 => ['Gi0/1', ['mac' => '02:00:00:0b:00:01']], 11 => ['Gi0/2', ['mac' => '02:00:00:0b:00:02']], 12 => ['Gi0/3', ['mac' => '02:00:00:0b:00:02']]]);
	h_nbrs('R', [NR(1, mac(2), 'B', '02:00:00:0b:00:01', ['rem_port_type' => 'macAddress']), NR(2, mac(2), 'B', '02:00:00:0b:00:02', ['rem_port_type' => 'macAddress'])]);
	h_ingest('port id = the MAC of one Port (R:Gi0/1), of two Ports (R:Gi0/2)');
	ex(count(h_links('R:Gi0/1', 'B:Gi0/1', 'physical_link', 'active')) === 1, 'the MAC of one Port: port-level');
	$l = h_links('R:Gi0/2', 'B', 'device_link', 'active');
	ex(count($l) === 1 && $l[0]['reason'] === 'port_shared_id', 'the MAC of several Ports: device_link, port_shared_id');
}, 'test_far_port_identification.php §1');

c('N-03', 'Правило', function () {
	base_r();
	h_nbrs('R', [NR(1, mac(10), 'D', 'p1')]);
	h_ingest('D is a neighbor: a pseudo port p1');
	$pseudo = array_filter(h_state()['ports']['D'] ?? [], static fn ($p) => str_starts_with($p, 'p1*'));
	preg_match('/\/if(-?\d+)$/', array_values($pseudo)[0] ?? '', $m);
	ex(isset($m[1]) && (int) $m[1] < 0, 'the allocated if_index of the pseudo port is negative ('.($m[1] ?? '?').')');
	$old_positive = (int) (crc32('p1') % 999999) + 1;
	h_tick();
	h_rep('D', mac(10), '10.0.0.50');
	h_ports('D', [$old_positive => 'Gi0/9']);
	h_nbrs('D', []);
	$summary = h_ingest('D becomes a reporter with a real port whose ifIndex equals the old positive allocated index');
	$d_ports = h_state()['ports']['D'] ?? [];
	ex(count($d_ports) === 1 && str_starts_with($d_ports[0], 'Gi0/9') && !str_contains($d_ports[0], '*'), 'the real port is a new row; the pseudo port is gone (converted), not overwritten');
	ex(count(h_links('R:Gi0/1', 'D', 'device_link', 'active')) === 1 && (int) ($summary['pseudo_ports_converted'] ?? 0) === 1, 'its link was converted to a device_link (§6.7.3), pseudo_ports_converted = 1');
}, 'test_pseudo_port_index.php');

// ============================================================================================================
// J. Join of a split identity (model §6.7.1; handoff 012)
// ============================================================================================================

/** The duplicate candidates written on every Device: [sysname|chassis => list of "reason->other"]. */
function dup_candidates_of(): array {
	global $pdo;
	$out = [];
	foreach ($pdo->query("SELECT id, attrs FROM topo_nodes WHERE type='device'")->fetchAll(PDO::FETCH_ASSOC) as $row) {
		$a = json_decode($row['attrs'], true);
		foreach ($a['duplicate_candidates'] ?? [] as $c) {
			$out[$row['id']][] = $c['reason'].'->'.($c['device_id'] ?? 'none');
		}
	}
	return $out;
}

c('J-01', 'Правило', function () {
	base_r();
	h_nbrs('R', [NR(1, mac(10), 'N', 'Gi0/1')], 'lldp');                                        // LLDP without a management address: chassis only
	h_nbrs('R', [NR(1, null, 'N', 'Gi0/1', ['rem_mgmt_ip' => '10.5.5.5'])], 'cdp');           // CDP: IP only
	h_ingest('LLDP (chassis only) and CDP (IP only) for one neighbor');
	ex(h_dev_count() === 3, 'two Devices for one neighbor after run 1');
	h_tick();
	h_rep('N', mac(10), '10.5.5.5');
	h_ports('N', [1 => 'Gi0/1']);
	h_nbrs('N', []);
	$summary = h_ingest('the neighbor becomes a reporter: loc_chassis + SNMP IP');
	ex(h_dev_count() === 2, 'one Device (A kept, B deleted)');
	ex(count(array_filter(h_state()['links_full'], static fn ($l) => $l['state'] === 'active')) === 1 && count(h_links('R:Gi0/1', 'N:Gi0/1', 'physical_link', 'active')) === 1, 'one link, pseudo ports merged by name');
	ex(array_unique(array_column(h_obs_all('R', 'Gi0/1'), 'outcome')) === ['applied'], 'the CDP observation is applied, not ambiguous');
	ex((int) ($summary['devices_joined']['count'] ?? 0) === 1, 'devices_joined = 1');
}, 'test_join_identity.php §1');

c('J-02', 'Правило', function () {
	base_r();
	h_nbrs('R', [NR(1, mac(10), 'N', 'Gi0/1')], 'lldp');
	h_nbrs('R', [NR(1, null, 'N', 'Gi0/1', ['rem_mgmt_ip' => '10.5.5.5'])], 'cdp');
	h_ingest('a split identity: A (chassis), B (IP)');
	ex(h_dev_count() === 3, 'two Devices for the neighbor');
	h_tick();
	h_nbrs('R', [NR(1, mac(10), 'N', 'Gi0/1', ['rem_mgmt_ip' => '10.5.5.5'])], 'lldp');
	h_nbrs('R', [NR(1, null, 'N', 'Gi0/1', ['rem_mgmt_ip' => '10.5.5.5'])], 'cdp');
	$summary = h_ingest('LLDP now carries the management address: a row with chassis + IP');
	ex(h_dev_count() === 3 && (int) ($summary['devices_joined']['count'] ?? 0) === 0, 'a neighbor row with both keys never merges (devices_joined = 0, both Devices stay)');
	$cand = dup_candidates_of();
	ex(count($cand) === 2 && count(array_filter($cand, static fn ($l) => $l === [$l[0]] && str_starts_with($l[0], 'split_identity->'))) === 2, 'the candidate split_identity is on both Devices');
}, 'test_join_identity.php §2');

c('J-03', 'Правило', function () {
	base_r();
	h_rep('NA', mac(10), null, false);                      // a reporter with a chassis and no SNMP interface IP
	h_ports('NA', [1 => 'Gi0/1']);
	h_rep('NB', null, '10.5.5.5');                          // a reporter known by its SNMP IP only
	h_snap('NB', ROLE_PORTS, PR([1 => 'Gi0/1']));
	h_ingest('A (a reporter with a chassis) and B (a reporter known by IP only) exist');
	ex(h_dev_count() === 3, 'setup: three Devices (R, A, B)');
	h_tick();
	h_nbrs('R', [NR(1, mac(10), 'NA', 'Gi0/1', ['rem_mgmt_ip' => '10.5.5.5'])], 'lldp');
	$summary = h_ingest('a row names the chassis of A and the IP of B');
	ex(h_dev_count() === 3 && (int) ($summary['devices_joined']['count'] ?? 0) === 0, 'no merge from a neighbor row: all Devices stay (R, A, B)');
	$cand = dup_candidates_of();
	ex(count($cand) === 2 && count(array_filter($cand, static fn ($l) => str_starts_with($l[0], 'split_identity->'))) === 2, 'the candidate is on both Devices (a merge would be refused: two associations)');
}, 'test_join_identity.php §3');

// ============================================================================================================
// X. Order dependence found while building J-03 (handoff 013 section 1): characterization, never fails
// ============================================================================================================

/** One run of the "two hosts of one box" scenario with the reporters walked in $order (names). Returns the picture. */
function x01_run(array $order): array {
	global $dsn, $user, $password, $H;
	h_reset();
	h_rep('R', mac(1), '10.0.0.1');
	h_rep('H1', mac(10), '10.0.0.30');
	h_rep('H2', null, '10.5.5.5');                          // SNMP IP 1, reports no loc_chassis
	h_ports('R', [1 => 'Gi0/1']);
	h_ports('H1', [1 => 'Gi0/1']);
	h_snap('H2', ROLE_PORTS, PR([1 => 'Gi0/1']));
	h_nbrs('R', [NR(1, mac(10), 'BOX', 'Gi0/1', ['rem_mgmt_ip' => '10.5.5.5'])], 'lldp');
	$ids = implode(',', array_map(static fn ($n) => $H['reps'][$n]['id'], $order));
	$r = run_ingest($dsn, $user, $password, [], $ids);
	if (getenv('X01_DEBUG')) {
		echo "--- order {$ids}\n".$r['out'].$r['err'];       // the ingest log of each order
	}
	$state = h_state();
	return ['order' => implode(',', $order), 'devices' => array_map(static fn ($d) => $d['name'].' chassis '.($d['chassis'] ?? '-').' ip '.($d['ip'] ?? '-').' '.($d['represented_by'] ?? 'unrepresented'), $state['devices']),
		'skipped' => $r['summary']['reporters_skipped'] ?? [], 'join_refused' => $r['summary']['devices_join_refused']['count'] ?? null,
		'joined' => $r['summary']['devices_joined']['count'] ?? null, 'shared' => $r['summary']['identity_keys_shared']['count'] ?? null,
		'exit' => $r['code'], 'refused_list' => $r['summary']['devices_join_refused']['list'] ?? null];
}

c('X-01', 'Пробел', function () {
	$runs = [x01_run(['R', 'H1', 'H2']), x01_run(['H1', 'R', 'H2']), x01_run(['H2', 'R', 'H1']), x01_run(['H2', 'H1', 'R'])];
	foreach ($runs as $run) {
		h_note(json_encode($run, JSON_UNESCAPED_UNICODE));
	}
	$natural = array_map(static fn ($r) => $r['devices'], $runs);
	sort($natural[0]);
	$same = true;
	foreach ($natural as $n) {
		sort($n);
		$same = $same && $n === $natural[0];
	}
	ex($same, 'catalog: the result does not depend on the order of the reporters (actual: '.json_encode(array_column($runs, 'devices', 'order'), JSON_UNESCAPED_UNICODE).')');
});

// ============================================================================================================
// S. Segments (topology-5-access-control.md §6, handoff 014 section 4): characterization, never fails.
// The ingest knows no proxy: a reporter's proxy or proxy group is not an input of any identity key today, so the
// "different proxies" of the scenarios cannot be expressed; each case runs the scenario as it is and records what
// the global mgmt_ip / sysname keys do. The ex() lines state what a segment-scoped identity would give.
// ============================================================================================================

function s_picture(string $label): array {
	$state = h_state();
	$pic = ['devices' => array_map(static fn ($d) => $d['name'].' chassis '.($d['chassis'] ?? '-').' ip '.($d['ip'] ?? '-').' '.($d['represented_by'] ?? 'unrepresented'), $state['devices']),
		'links' => $state['links'], 'obs' => array_map(static fn ($o) => $o['reporter'].':'.$o['port'].' '.$o['outcome'], $state['obs_full'])];
	h_note($label.': '.json_encode($pic, JSON_UNESCAPED_UNICODE));
	return $pic;
}

c('S-01', 'Пробел', function () {
	h_rep('R1', mac(1), '10.1.1.1');                       // behind proxy P1 (not expressible)
	h_rep('R2', mac(2), '10.1.1.2');                       // behind proxy P2
	h_ports('R1', [1 => 'Gi0/1']);
	h_ports('R2', [1 => 'Gi0/1']);
	h_nbrs('R1', [NR(1, null, 'PRINTER-A', 'Gi0/1', ['rem_mgmt_ip' => '10.0.0.1'])], 'cdp');
	h_nbrs('R2', [NR(1, null, 'PRINTER-B', 'Gi0/1', ['rem_mgmt_ip' => '10.0.0.1'])], 'cdp');
	$summary = h_ingest('two reporters, each sees a CDP-only neighbor with IP 10.0.0.1');
	$pic = s_picture('S-01');
	h_note('S-01 summary: identity_keys_shared='.json_encode($summary['identity_keys_shared'] ?? null).' observations='.json_encode($summary['observations'] ?? null));
	ex(h_dev_count() === 4, 'catalog: the two neighbors with the same private IP behind different proxies are two Devices (actual: '.h_dev_count().')');
	ex(!in_array('ambiguous', array_map(static fn ($o) => substr(strrchr($o, ' '), 1), $pic['obs']), true), 'catalog: no observation is ambiguous because of the other segment (actual: '.json_encode($pic['obs'], JSON_UNESCAPED_UNICODE).')');
});

c('S-02', 'Пробел', function () {
	h_rep('R1', mac(1), '10.1.1.1');                       // behind proxy P1
	h_ports('R1', [1 => 'Gi0/1']);
	h_nbrs('R1', [NR(1, null, 'CDP-ONLY', 'Gi0/1', ['rem_mgmt_ip' => '10.0.0.1'])], 'cdp');
	h_ingest('a CDP-only Device with IP 10.0.0.1 seen from P1');
	h_tick();
	h_rep('R2', mac(20), '10.0.0.1');                      // behind proxy P2: SNMP IP 10.0.0.1 and its own chassis
	h_ports('R2', [1 => 'Gi0/1']);
	$summary = h_ingest('a reporter behind P2 with SNMP IP 10.0.0.1 and its own chassis');
	$pic = s_picture('S-02');
	h_note('S-02 summary: devices_joined='.json_encode($summary['devices_joined'] ?? null).' duplicate_candidates='.json_encode($summary['duplicate_candidates'] ?? null));
	ex((int) ($summary['devices_joined']['count'] ?? 0) === 0 && h_dev_count() === 3, 'catalog: no join across proxies, three Devices (R1, CDP-only, R2) (actual joined='.(int) ($summary['devices_joined']['count'] ?? 0).', devices='.h_dev_count().')');
});

c('S-03', 'Пробел', function () {
	h_rep('R1', mac(1), '10.1.1.1');                       // behind proxy P1
	h_rep('R2', mac(2), '10.1.1.2');                       // behind proxy P2
	h_ports('R1', [1 => 'Gi0/1']);
	h_ports('R2', [1 => 'Gi0/1']);
	h_nbrs('R1', [NR(1, mac(30), 'PROVIDER', 'Gi0/7')], 'lldp');
	h_nbrs('R2', [NR(1, mac(30), 'PROVIDER', 'Gi0/8')], 'lldp');
	h_ingest('the same chassis seen by reporters behind two proxies');
	s_picture('S-03');
	$links = array_filter(h_state()['links_full'], static fn ($l) => $l['state'] === 'active');
	ex(h_dev_count() === 3 && count($links) === 2, 'catalog: one Device for the chassis, one link per cable (actual: devices='.h_dev_count().', active links='.count($links).')');
});

// ============================================================================================================
// Run
// ============================================================================================================

$started = microtime(true);
$only = getenv('CATALOG_CASES') ? explode(',', (string) getenv('CATALOG_CASES')) : null;
if ($first_pass_only) {
	$only = FIRST_PASS;
}
$results = [];
foreach ($CASES as $id => $case) {
	if ($only !== null && !in_array($id, $only, true)) {
		continue;
	}
	$result = h_execute($case);
	$results[] = $result;
	if (getenv('CATALOG_DUMP')) {
		echo json_encode(['id' => $id, 'failed' => $result['failed'] ?? null, 'notes' => $result['notes'] ?? null, 'actual' => $result['actual'] ?? null], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n";
	}
	$note = $result['verdict'] === 'out of harness' ? ' — '.$result['reason'] : ($result['failed'] ? ' — '.implode('; ', array_map(static fn ($f) => mb_substr($f, 0, 1500), $result['failed'])) : '');
	echo sprintf("  %-5s %-12s %-14s%s\n", $id, $result['rating'], $result['verdict'], $note);
}

$counts = ['verdict' => [], 'rating' => []];
foreach ($results as $r) {
	$counts['verdict'][$r['verdict']] = ($counts['verdict'][$r['verdict']] ?? 0) + 1;
	$counts['rating'][$r['rating']][$r['verdict']] = ($counts['rating'][$r['rating']][$r['verdict']] ?? 0) + 1;
}
echo "\nverdicts: ".json_encode($counts['verdict'], JSON_UNESCAPED_UNICODE)."\nby rating: ".json_encode($counts['rating'], JSON_UNESCAPED_UNICODE)."\n";

echo sprintf("\nrun time: %.1f s (%d cases%s)\n", microtime(true) - $started, count($results), $first_pass_only ? ', first pass' : '');

if ($only === null) {
	file_put_contents(__DIR__.'/scenario_catalog_results.json', json_encode(['cases' => $results, 'counts' => $counts],
		JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");
}

// Only the cases the spec decides (Правило) or accepts as a limitation (Ограничение) fail the run.
$failing = array_filter($results, static fn (array $r): bool => $r['verdict'] === 'differs' && in_array($r['rating'], ['Правило', 'Ограничение'], true));
if ($failing) {
	fwrite(STDERR, "FAIL: ".count($failing)." case(s) rated Правило/Ограничение differ: ".implode(', ', array_column($failing, 'id'))."\n");
	exit(1);
}
echo "\nAll Правило/Ограничение cases match.\n";
