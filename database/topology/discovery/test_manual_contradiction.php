#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * test_manual_contradiction.php — the ingest side of topology-manual-contradiction-spec.md §8.
 *
 * What ingest must do with a manual link that discovery contradicts: leave it (and its attrs.shadow_ack) alone,
 * record the hidden neighbor as `shadowed`, count the link in manual_links_contradicted unless every hidden
 * neighbor is acknowledged, and let a removed manual link be replaced by the discovered one (a superseded link is
 * revived with its id). The operator actions themselves (keep, revoke, accept) are written by the frontend; here
 * an acknowledgment is written straight into attrs.shadow_ack, as the frontend does.
 *
 * Usage: test_manual_contradiction.php <PDO DSN> <database user> [password]
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
	fwrite(STDERR, "Usage: {$argv[0]} <PDO DSN> <database user> [password]\n");
	exit(1);
}
[$dsn, $user] = $args;
$password = $args[2] ?? '';

$pdo = new PDO($dsn, $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

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

/** Spec §8: at most one non-superseded link per port, over ALL ports. */
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

/** The same picture without ids: what a reader of the topology sees. Independent of the order nodes were created in. */
function natural_picture(PDO $pdo): array {
	$name = static function (int $port_id) use ($pdo): string {
		$row = $pdo->query("SELECT p.attrs AS pa, d.attrs AS da FROM topo_nodes p JOIN topo_nodes d ON d.id=p.device_id WHERE p.id={$port_id}")->fetch(PDO::FETCH_ASSOC);
		return json_decode($row['da'], true)['chassis_id'].'#'.json_decode($row['pa'], true)['name'];
	};
	$links = [];
	foreach ($pdo->query("SELECT id, src_id, dst_id, attrs FROM topo_edges WHERE type='physical_link'")->fetchAll(PDO::FETCH_ASSOC) as $e) {
		$pair = [$name((int) $e['src_id']), $name((int) $e['dst_id'])];
		sort($pair);
		$attrs = json_decode($e['attrs'], true);
		$links[] = implode('<->', $pair).' via '.$attrs['discovered_via'].' superseded_at='.($attrs['superseded_at'] ?? '-');
	}
	sort($links);
	$obs = [];
	foreach ($pdo->query('SELECT itemid, local_port_id, remote_key, outcome, edge_id FROM topo_observations')->fetchAll(PDO::FETCH_ASSOC) as $o) {
		$edge = '-';
		if ($o['edge_id'] !== null) {
			$e = $pdo->query("SELECT src_id, dst_id FROM topo_edges WHERE id={$o['edge_id']}")->fetch(PDO::FETCH_ASSOC);
			$pair = [$name((int) $e['src_id']), $name((int) $e['dst_id'])];
			sort($pair);
			$edge = implode('<->', $pair);
		}
		$obs[] = "{$o['itemid']} {$name((int) $o['local_port_id'])} {$o['remote_key']} {$o['outcome']} {$edge}";
	}
	sort($obs);
	return [$links, $obs];
}

const CLOCK = 1_800_000_000;
$R = 'a0:00:00:00:00:01';
$B = 'b0:00:00:00:00:02';
$C = 'c0:00:00:00:00:03';
$D = 'd0:00:00:00:00:04';

/** Reporters R (hostid 101) and B (102); the neighbors are R:Gi0/1 <-> B:Gi0/24. */
function two_switches(PDO $pdo, string $R, string $B): void {
	add_host($pdo, 101, 'SwR', 'Switch R', '10.0.0.1');
	add_host($pdo, 102, 'SwB', 'Switch B', '10.0.0.2');
	snapshot($pdo, 1001, 101, 1, CLOCK, ports_rows($R, [1 => 'Gi0/1', 2 => 'Gi0/2']));
	snapshot($pdo, 2001, 102, 1, CLOCK, ports_rows($B, [24 => 'Gi0/24', 23 => 'Gi0/23']));
}

function ports_of(PDO $pdo, string $chassis): array {
	$dev = device_id($pdo, $chassis);
	$out = [];
	foreach ($pdo->query("SELECT id, attrs FROM topo_nodes WHERE type='port' AND device_id={$dev}")->fetchAll(PDO::FETCH_ASSOC) as $row) {
		$out[json_decode($row['attrs'], true)['name']] = (int) $row['id'];
	}
	return $out;
}


$U = 'b0:00:00:00:00:02';     // the manual link's far end (UPS1); $R (Router1) comes from the shared fixtures above
$PR = 'c0:00:00:00:00:03';    // the live neighbor on the port (Printer1)
$PR2 = 'd0:00:00:00:00:04';   // another neighbor (Printer2)

function raw_link(PDO $pdo, int $a, int $b, string $via, array $extra = []): int {
	$pdo->prepare("INSERT INTO topo_edges (type, src_id, dst_id, attrs, created_at) VALUES ('physical_link', ?, ?, ?, 1)")
		->execute([min($a, $b), max($a, $b), json_encode(['discovered_via' => $via, 'last_seen' => 1] + $extra)]);
	return (int) $pdo->lastInsertId();
}

/** The acknowledgment the frontend's "keep manual" writes. */
function acknowledge(PDO $pdo, int $edge_id, int $device_id, string $remote_key): void {
	$attrs = json_decode((string) scalar($pdo, 'SELECT attrs FROM topo_edges WHERE id=?', [$edge_id]), true);
	$attrs['shadow_ack'][] = ['device_id' => $device_id, 'remote_key' => $remote_key, 'at' => CLOCK, 'by' => 'Admin'];
	$pdo->prepare('UPDATE topo_edges SET attrs=? WHERE id=?')->execute([json_encode($attrs), $edge_id]);
}

function shadow_ack_json(PDO $pdo, int $edge_id): string {
	return json_encode(json_decode((string) scalar($pdo, 'SELECT attrs FROM topo_edges WHERE id=?', [$edge_id]), true)['shadow_ack'] ?? null);
}

function contradicted(array $r): int {
	return (int) $r['summary']['manual_links_contradicted'];
}

/** R with Gi0/1 manually linked to UPS1:eth0; R's LLDP shows the printer on Gi0/1 (the lab case). */
function lab_case(PDO $pdo, string $dsn, string $user, string $password, string $R, string $U, string $PR): array {
	add_host($pdo, 101, 'Router1', 'Router1', '10.0.0.1');
	snapshot($pdo, 1001, 101, 1, CLOCK, ports_rows($R, [1 => 'Gi0/1']));
	snapshot($pdo, 1002, 101, 2, CLOCK + 10, [nbr($R, 1, $U, 'eth0')]);
	ingest($dsn, $user, $password, $pdo, 'to create the ports');
	$P = ports_of($pdo, $R)['Gi0/1'];
	$Up = ports_of($pdo, $U)['eth0'];
	$pdo->exec('DELETE FROM topo_edges');
	$m = raw_link($pdo, $P, $Up, 'manual');
	snapshot($pdo, 1002, 101, 2, CLOCK + 100, [nbr($R, 1, $PR, 'Gi0/1')]);
	return ['P' => $P, 'Up' => $Up, 'M' => $m];
}

// ============================================================================================================
echo "\n=== 1: the lab case ===\n";
reset_db($pdo);
$lab = lab_case($pdo, $dsn, $user, $password, $R, $U, $PR);
$r = ingest($dsn, $user, $password, $pdo, 'with the printer behind the manual link');
$m = link_between($pdo, $lab['P'], $lab['Up']);
check($m['discovered_via'] === 'manual' && is_active($m), 'the manual link stays, still manual and active');
$obs = observation($pdo, 1002, $PR);
check($obs['outcome'] === 'shadowed' && (int) $obs['edge_id'] === $lab['M'], 'the printer observation is shadowed, pointing at the manual link');
check(contradicted($r) === 1, 'manual_links_contradicted = 1');
$printer = device_id($pdo, $PR);
check((int) scalar($pdo, 'SELECT device_id FROM topo_observations WHERE itemid=1002') === $printer, 'the hidden neighbor is the printer Device');

// ============================================================================================================
echo "\n=== 2: keep manual — acknowledged, five runs change nothing ===\n";
acknowledge($pdo, $lab['M'], $printer, 'chassis:macAddress:'.$PR);
$ack_before = shadow_ack_json($pdo, $lab['M']);
$r = ingest($dsn, $user, $password, $pdo, 'after the acknowledgment');
check(contradicted($r) === 0, 'an acknowledged link is not contradicted');
$fp = fingerprint($pdo);
for ($i = 0; $i < 5; $i++) {
	$r = ingest($dsn, $user, $password, $pdo, 'in a rerun');
}
check(fingerprint($pdo) === $fp && contradicted($r) === 0, 'five runs change nothing');
check(shadow_ack_json($pdo, $lab['M']) === $ack_before, 'shadow_ack is byte-identical after the ingest runs');

// ============================================================================================================
echo "\n=== 3: a different neighbor after the acknowledgment ===\n";
snapshot($pdo, 1002, 101, 2, CLOCK + 200, [nbr($R, 1, $PR2, 'Gi0/1')]);
$r = ingest($dsn, $user, $password, $pdo, 'with Printer2 on the port');
check(contradicted($r) === 1, 'contradicted again, by Printer2');
check(observation($pdo, 1002, $PR2)['outcome'] === 'shadowed', 'Printer2 is shadowed');
check(shadow_ack_json($pdo, $lab['M']) === $ack_before, 'the acknowledgment of Printer1 stays');

// ============================================================================================================
echo "\n=== 4: neighbor flicker — the acknowledgment survives ===\n";
snapshot($pdo, 1002, 101, 2, CLOCK + 300, []);                                   // nothing on the port
$r = ingest($dsn, $user, $password, $pdo, 'with the port silent');
check(contradicted($r) === 0 && is_active(link_between($pdo, $lab['P'], $lab['Up'])), 'no shadow, no contradiction, the manual link stays');
snapshot($pdo, 1002, 101, 2, CLOCK + 400, [nbr($R, 1, $PR, 'Gi0/1')]);           // the printer is back
$r = ingest($dsn, $user, $password, $pdo, 'with the printer back');
check(contradicted($r) === 0, 'Printer1 is back and still acknowledged: no signal again');
check(shadow_ack_json($pdo, $lab['M']) === $ack_before, 'shadow_ack is untouched');

// ============================================================================================================
echo "\n=== 5: the far end confirms the manual link ===\n";
reset_db($pdo);
$lab = lab_case($pdo, $dsn, $user, $password, $R, $U, $PR);
add_host($pdo, 102, 'UPS1', 'UPS1', '10.0.0.2');
snapshot($pdo, 2001, 102, 1, CLOCK, ports_rows($U, [1 => 'eth0']));
snapshot($pdo, 2002, 102, 2, CLOCK + 100, [nbr($U, 1, $R, 'Gi0/1')]);            // UPS1 reports Router1 Gi0/1
$r = ingest($dsn, $user, $password, $pdo, 'with UPS1 confirming');
$lab['P'] = ports_of($pdo, $R)['Gi0/1'];
$lab['Up'] = ports_of($pdo, $U)['eth0'];        // UPS1's own PORTS snapshot merged the placeholder port into a real one
$m = link_between($pdo, $lab['P'], $lab['Up']);
check($m['discovered_via'] === 'manual' && is_active($m), 'the manual link stays manual although the far end reports it');
check(contradicted($r) === 1, 'and it is still contradicted');
check(observation($pdo, 2002, $R)['outcome'] === 'applied' && (int) observation($pdo, 2002, $R)['edge_id'] === $lab['M'],
	'UPS1\'s observation confirms the manual link (the frontend shows "confirmed from UPS1")');

// ============================================================================================================
echo "\n=== 6: the shadow comes from the far end ===\n";
reset_db($pdo);
add_host($pdo, 101, 'Router1', 'Router1', '10.0.0.1');
add_host($pdo, 102, 'UPS1', 'UPS1', '10.0.0.2');
snapshot($pdo, 1001, 101, 1, CLOCK, ports_rows($R, [1 => 'Gi0/1']));
snapshot($pdo, 2001, 102, 1, CLOCK, ports_rows($U, [1 => 'eth0']));
snapshot($pdo, 1002, 101, 2, CLOCK + 10, [nbr($R, 1, $U, 'eth0')]);
ingest($dsn, $user, $password, $pdo, 'to create the ports');
$P = ports_of($pdo, $R)['Gi0/1'];
$Up = ports_of($pdo, $U)['eth0'];
$pdo->exec('DELETE FROM topo_edges');
$m = raw_link($pdo, $P, $Up, 'manual');
snapshot($pdo, 1002, 101, 2, CLOCK + 100, []);
snapshot($pdo, 2002, 102, 2, CLOCK + 110, [nbr($U, 1, $PR, 'Gi0/1')]);            // UPS1 sees the printer on eth0
$r = ingest($dsn, $user, $password, $pdo, 'with the printer seen from UPS1');
$obs = observation($pdo, 2002, $PR);
check($obs['outcome'] === 'shadowed' && (int) $obs['edge_id'] === $m && contradicted($r) === 1, 'shadowed by the manual link, contradicted');

// ============================================================================================================
echo "\n=== 7: accept discovery — the superseded discovered link is revived ===\n";
reset_db($pdo);
add_host($pdo, 101, 'Router1', 'Router1', '10.0.0.1');
snapshot($pdo, 1001, 101, 1, CLOCK, ports_rows($R, [1 => 'Gi0/1']));
snapshot($pdo, 1002, 101, 2, CLOCK + 10, [nbr($R, 1, $PR, 'Gi0/1')]);
ingest($dsn, $user, $password, $pdo, 'with the printer link');
$P = ports_of($pdo, $R)['Gi0/1'];
$printer_port = ports_of($pdo, $PR)['Gi0/1'];
$discovered = link_between($pdo, $P, $printer_port)['id'];
$Up = ports_of($pdo, $R)['Gi0/1'] + 1000;                                          // a far end for the manual link
$pdo->prepare("INSERT INTO topo_nodes (type, attrs, created_at, updated_at) VALUES ('device', ?, 1, 1)")
	->execute([json_encode(['chassis_id' => $U, 'sysname' => 'UPS1', 'mac' => null, 'mgmt_ip' => null, 'vendor' => 'unknown', 'last_seen' => 1])]);
$ups_device = (int) $pdo->lastInsertId();
$pdo->prepare("INSERT INTO topo_nodes (type, device_id, attrs, created_at, updated_at) VALUES ('port', ?, ?, 1, 1)")
	->execute([$ups_device, json_encode(['if_index' => 1, 'name' => 'eth0', 'if_type' => 'physical', 'pseudo' => false, 'learned_macs' => [], 'zabbix_itemids' => []])]);
$Up = (int) $pdo->lastInsertId();
$m = raw_link($pdo, $P, $Up, 'manual');                                            // forgotten manual link to UPS1
$r = ingest($dsn, $user, $password, $pdo, 'with the manual link next to the discovered one');
check(isset(link_between($pdo, $P, $printer_port)['superseded_at']), 'the discovered link was superseded by the legacy cleanup');
check(observation($pdo, 1002, $PR)['outcome'] === 'shadowed' && contradicted($r) === 1, 'the printer is shadowed, the manual link is contradicted');
$pdo->prepare('DELETE FROM topo_edges WHERE id=?')->execute([$m]);                  // what "accept discovery" does
$r = ingest($dsn, $user, $password, $pdo, 'after accepting discovery');
$now = link_between($pdo, $P, $printer_port);
check(is_active($now) && $now['id'] === $discovered, 'the SAME edge id is active again (revived), not a new edge');
check(observation($pdo, 1002, $PR)['outcome'] === 'applied' && contradicted($r) === 0, 'the printer is applied, nothing is contradicted');
check((int) scalar($pdo, "SELECT COUNT(*) FROM topo_edges WHERE type='physical_link'") === 1, 'exactly one link');
assert_port_uniqueness($pdo, 'after accepting');

// ============================================================================================================
echo "\n=== 8: no automatic change ===\n";
reset_db($pdo);
$lab = lab_case($pdo, $dsn, $user, $password, $R, $U, $PR);
ingest($dsn, $user, $password, $pdo, 'with the printer behind the manual link');
acknowledge($pdo, $lab['M'], device_id($pdo, $PR), 'chassis:macAddress:'.$PR);
$before = (string) scalar($pdo, 'SELECT attrs FROM topo_edges WHERE id=?', [$lab['M']]);
$runs = [
	[CLOCK + 100, [nbr($R, 1, $PR, 'Gi0/1')]], [CLOCK + 200, [nbr($R, 1, $PR2, 'Gi0/1')]], [CLOCK + 300, []],
	[CLOCK + 400, [nbr($R, 1, $PR, 'Gi0/1', 'cdp')]], [CLOCK + 500, [nbr($R, 1, $PR, 'Gi0/1'), nbr($R, 1, $PR2, 'Gi0/1')]],
];
foreach ($runs as [$clock, $rows]) {
	snapshot($pdo, 1002, 101, 2, $clock, $rows);
	ingest($dsn, $user, $password, $pdo, "at clock {$clock}");
	$attrs = json_decode((string) scalar($pdo, 'SELECT attrs FROM topo_edges WHERE id=?', [$lab['M']]), true);
	check($attrs !== null && $attrs['discovered_via'] === 'manual' && !isset($attrs['superseded_at'])
		&& json_encode($attrs['shadow_ack']) === shadow_ack_json($pdo, $lab['M']), "the manual link is untouched at clock {$clock}");
}
check(json_encode(json_decode($before, true)['shadow_ack']) === shadow_ack_json($pdo, $lab['M']), 'shadow_ack is byte-identical at the end');

// ============================================================================================================
echo "\n=== 9: far end confirms + keep manual + the neighbor flickers — the decision must survive ===\n";
reset_db($pdo);
$lab = lab_case($pdo, $dsn, $user, $password, $R, $U, $PR);
add_host($pdo, 102, 'UPS1', 'UPS1', '10.0.0.2');
snapshot($pdo, 2001, 102, 1, CLOCK, ports_rows($U, [1 => 'eth0']));
snapshot($pdo, 2002, 102, 2, CLOCK + 100, [nbr($U, 1, $R, 'Gi0/1')]);            // UPS1 confirms the manual link
ingest($dsn, $user, $password, $pdo, 'with UPS1 confirming and the printer hidden');
$lab['P'] = ports_of($pdo, $R)['Gi0/1'];
$lab['Up'] = ports_of($pdo, $U)['eth0'];
$lab['M'] = link_between($pdo, $lab['P'], $lab['Up'])['id'];
$printer = device_id($pdo, $PR);
acknowledge($pdo, $lab['M'], $printer, 'chassis:macAddress:'.$PR);
$ack_before = shadow_ack_json($pdo, $lab['M']);
snapshot($pdo, 1002, 101, 2, CLOCK + 200, []);                                   // the printer drops out for one poll
snapshot($pdo, 2002, 102, 2, CLOCK + 210, [nbr($U, 1, $R, 'Gi0/1')]);
ingest($dsn, $user, $password, $pdo, 'with the printer gone');
$m = link_between($pdo, $lab['P'], $lab['Up']);
check($m['discovered_via'] === 'manual' && is_active($m), 'the link is still manual although nothing hides behind it right now');
snapshot($pdo, 1002, 101, 2, CLOCK + 300, [nbr($R, 1, $PR, 'Gi0/1')]);           // the printer is back
snapshot($pdo, 2002, 102, 2, CLOCK + 310, [nbr($U, 1, $R, 'Gi0/1')]);
$r = ingest($dsn, $user, $password, $pdo, 'with the printer back');
$obs = observation($pdo, 1002, $PR);
check($obs['outcome'] === 'shadowed' && (int) $obs['edge_id'] === $lab['M'], 'the printer is shadowed again, not a conflict');
check(contradicted($r) === 0 && shadow_ack_json($pdo, $lab['M']) === $ack_before, 'still acknowledged, shadow_ack unchanged');
check(link_between($pdo, $lab['P'], $lab['Up'])['discovered_via'] === 'manual', 'and the link is still manual');
// the acknowledgments are revoked and nothing hides behind the link: the old upgrade applies again
$attrs = json_decode((string) scalar($pdo, 'SELECT attrs FROM topo_edges WHERE id=?', [$lab['M']]), true);
unset($attrs['shadow_ack']);
$pdo->prepare('UPDATE topo_edges SET attrs=? WHERE id=?')->execute([json_encode($attrs), $lab['M']]);
snapshot($pdo, 1002, 101, 2, CLOCK + 400, []);
snapshot($pdo, 2002, 102, 2, CLOCK + 410, [nbr($U, 1, $R, 'Gi0/1')]);
ingest($dsn, $user, $password, $pdo, 'after revoking every acknowledgment');
check(link_between($pdo, $lab['P'], $lab['Up'])['discovered_via'] !== 'manual', 'without acknowledgments and without a shadow the confirmed manual link is upgraded, as before');

// ============================================================================================================
echo "\n=== 10: a disabled rule or host takes its shadows with it ===\n";
reset_db($pdo);
$lab = lab_case($pdo, $dsn, $user, $password, $R, $U, $PR);
$r = ingest($dsn, $user, $password, $pdo, 'with the printer behind the manual link');
check(contradicted($r) === 1, 'contradicted while the rule and the host are enabled');
$pdo->exec('UPDATE hosts SET status=1 WHERE hostid=101');
$r = ingest($dsn, $user, $password, $pdo, 'with the host disabled');
check(contradicted($r) === 0, 'not contradicted: the only reporter that sees the printer is disabled');
check((int) scalar($pdo, "SELECT COUNT(*) FROM topo_observations WHERE outcome='shadowed'") === 0, 'and its shadowing observation is gone');
check(is_active(link_between($pdo, $lab['P'], $lab['Up'])) && link_between($pdo, $lab['P'], $lab['Up'])['discovered_via'] === 'manual',
	'the manual link itself is untouched');
$pdo->exec('UPDATE hosts SET status=0 WHERE hostid=101');
$r = ingest($dsn, $user, $password, $pdo, 'with the host enabled again');
check(contradicted($r) === 1, 'contradicted again from the same last snapshot');

echo "\nAll manual contradiction ingest tests passed.\n";
