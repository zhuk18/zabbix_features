#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * test_device_links.php — acceptance tests of topology-device-level-edge-spec.md §9.
 *
 * Snapshots are written straight into topo_lld_snapshot with fixed clocks, and the REAL ingest.php runs as a
 * subprocess after every change. After every run the port uniqueness invariant is checked over all ports, counting
 * both link types: at most one non-superseded link per local port, device-level links included.
 *
 * Usage: test_device_links.php <PDO DSN> <database user> [password]
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
	$pdo->exec('CREATE TABLE items (itemid BIGINT UNSIGNED PRIMARY KEY, hostid BIGINT UNSIGNED NOT NULL, status INT NOT NULL DEFAULT 0, topology_role INT NOT NULL DEFAULT 0)');
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


// ---- device-level helpers ----

/** The whole topology as sorted text lines, so a failed "nothing changed" check can show what did. */
function state_lines(PDO $pdo): array {
	$lines = [];
	foreach ($pdo->query('SELECT id, type, device_id, attrs, updated_at FROM topo_nodes ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) as $r) {
		$lines[] = 'node '.implode(' | ', $r);
	}
	foreach ($pdo->query('SELECT id, type, src_id, dst_id, attrs FROM topo_edges ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) as $r) {
		$lines[] = 'edge '.implode(' | ', $r);
	}
	foreach ($pdo->query('SELECT id, itemid, local_port_id, remote_key, remote_attrs, outcome, edge_id, device_id, link_precision,'.
			' far_port_reason, precision_lower, first_seen, last_seen FROM topo_observations ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) as $r) {
		$lines[] = 'obs '.implode(' | ', array_map(static fn ($v) => (string) $v, $r));
	}
	return $lines;
}

function check_unchanged(PDO $pdo, array $before, string $description): void {
	$after = state_lines($pdo);
	if ($before !== $after) {
		fwrite(STDERR, "--- changed between the two runs:\n".implode("\n", array_merge(
			array_map(static fn ($l) => '- '.$l, array_values(array_diff($before, $after))),
			array_map(static fn ($l) => '+ '.$l, array_values(array_diff($after, $before))))).PHP_EOL);
	}
	check($before === $after, $description);
}

/** Every link of either type, with its decoded attrs and the Port / Device ends. */
function all_links(PDO $pdo): array {
	$out = [];
	foreach ($pdo->query("SELECT id, type, src_id, dst_id, attrs FROM topo_edges WHERE type IN ('physical_link','device_link') ORDER BY id")
			->fetchAll(PDO::FETCH_ASSOC) as $e) {
		$out[] = ['id' => (int) $e['id'], 'type' => $e['type'], 'src' => (int) $e['src_id'], 'dst' => (int) $e['dst_id']]
			+ json_decode($e['attrs'], true);
	}
	return $out;
}

function device_link(PDO $pdo, int $port_id, int $device_id): ?array {
	$stmt = $pdo->prepare("SELECT id, attrs FROM topo_edges WHERE type='device_link' AND src_id=? AND dst_id=?");
	$stmt->execute([$port_id, $device_id]);
	$row = $stmt->fetch(PDO::FETCH_ASSOC);
	return $row ? ['id' => (int) $row['id']] + json_decode($row['attrs'], true) : null;
}

function count_type(PDO $pdo, string $type): int {
	return (int) scalar($pdo, "SELECT COUNT(*) FROM topo_edges WHERE type=?", [$type]);
}

function port_count(PDO $pdo, int $device_id): int {
	return (int) scalar($pdo, "SELECT COUNT(*) FROM topo_nodes WHERE type='port' AND device_id=?", [$device_id]);
}

/** Spec §3 "Port uniqueness": at most one non-superseded link per local port, both types counted. */
function assert_unique_both(PDO $pdo, string $when): void {
	$bad = $pdo->query("SELECT p.id FROM topo_nodes p WHERE p.type='port' AND (".
		"(SELECT COUNT(*) FROM topo_edges e WHERE e.type='physical_link' AND (e.src_id=p.id OR e.dst_id=p.id) AND JSON_EXTRACT(e.attrs,'\$.superseded_at') IS NULL)".
		"+(SELECT COUNT(*) FROM topo_edges e WHERE e.type='device_link' AND e.src_id=p.id AND JSON_EXTRACT(e.attrs,'\$.superseded_at') IS NULL)) > 1")
		->fetchAll(PDO::FETCH_COLUMN);
	check(!$bad, "port uniqueness holds over all ports, both link types {$when}");
}

function run(string $dsn, string $user, string $password, PDO $pdo, string $when, array $extra = [], ?string $order = null): array {
	$r = run_ingest($dsn, $user, $password, $extra, $order);
	if ($r['code'] !== 0) {
		fail_test("ingest failed {$when}: ".trim($r['err'].' '.$r['out']));
	}
	assert_unique_both($pdo, $when);
	return $r;
}

/** A NEIGHBORS row whose far port id is given with an explicit subtype (e.g. the chassis MAC as macAddress). */
function nbr_typed(string $loc, int $if, string $rem_chassis, string $rem_port, string $port_type, string $source = 'lldp'): array {
	return ['if_index' => $if, 'rem_chassis' => $rem_chassis, 'rem_chassis_type' => 'macAddress', 'rem_sysname' => 'sys-'.$rem_chassis,
		'rem_port' => $rem_port, 'rem_port_type' => $port_type, 'source' => $source, 'loc_chassis' => $loc];
}

/** The name a reporter's host gives its own Device; neighbors must advertise the same, or sysname flips between runs. */
function sysname_of(string $chassis): string {
	return ['a0:00:00:00:00:01' => 'Switch R', 'd0:00:00:00:00:04' => 'Switch D', 'e0:00:00:00:00:05' => 'Switch E'][$chassis]
		?? 'sys-'.$chassis;
}

function nb(string $loc, int $if, string $rem_chassis, string $rem_port, string $source = 'lldp'): array {
	return ['rem_sysname' => sysname_of($rem_chassis)] + nbr($loc, $if, $rem_chassis, $rem_port, $source);
}

function nbt(string $loc, int $if, string $rem_chassis, string $rem_port, string $port_type, string $source = 'lldp'): array {
	return ['rem_sysname' => sysname_of($rem_chassis)] + nbr_typed($loc, $if, $rem_chassis, $rem_port, $port_type, $source);
}

function obs_full(PDO $pdo, int $itemid, string $chassis): ?array {
	$stmt = $pdo->prepare('SELECT outcome, edge_id, link_precision, far_port_reason, precision_lower FROM topo_observations'.
		' WHERE itemid=? AND remote_key LIKE ?');
	$stmt->execute([$itemid, '%'.$chassis]);
	$row = $stmt->fetch(PDO::FETCH_ASSOC);

	return $row ? ['precision_lower' => (int) $row['precision_lower']] + $row : null;
}

function ports_by_name(PDO $pdo, string $chassis): array {
	$dev = device_id($pdo, $chassis);
	$out = [];
	if ($dev === null) {
		return $out;
	}
	foreach ($pdo->query("SELECT id, attrs FROM topo_nodes WHERE type='port' AND device_id={$dev}")->fetchAll(PDO::FETCH_ASSOC) as $row) {
		$out[json_decode($row['attrs'], true)['name']] = (int) $row['id'];
	}
	return $out;
}

const CLOCK = 1_800_000_000;
$R = 'a0:00:00:00:00:01';
$D = 'd0:00:00:00:00:04';
$E = 'e0:00:00:00:00:05';
$X = 'f0:00:00:00:00:06';

function reporters(PDO $pdo, string $R, string $D): void {
	add_host($pdo, 101, 'SwR', 'Switch R', '10.0.0.1');
	add_host($pdo, 104, 'SwD', 'Switch D', '10.0.0.4');
}

// ============================================================================================================
echo "\n=== 4.1: phantom avoided — the far Device is a reporter and the advertised port matches none of its ports ===\n";
reset_db($pdo);
reporters($pdo, $R, $D);
snapshot($pdo, 1001, 101, 1, CLOCK, ports_rows($R, [1 => 'Gi0/1']));
snapshot($pdo, 4001, 104, 1, CLOCK, ports_rows($D, [1 => 'Gi0/1']));
snapshot($pdo, 1002, 101, 2, CLOCK + 10, [nb($R, 1, $D, '1')]);
run($dsn, $user, $password, $pdo, 'with R naming D by port "1"');
$Dd = device_id($pdo, $D);
$P = ports_by_name($pdo, $R)['Gi0/1'];
$link = device_link($pdo, $P, $Dd);
check($link !== null, 'R:P -> D is a device_link');
check(($link['far_port_reason'] ?? null) === 'port_unmatched', 'the reason is port_unmatched');
check(($link['far_port_hint']['rem_port'] ?? null) === '1', 'the far end\'s advertisement is kept as far_port_hint');
check(port_count($pdo, $Dd) === 1, 'no port was created on D from the advertisement (no phantom)');
check(count_type($pdo, 'physical_link') === 0, 'no physical_link exists');
$o = obs_full($pdo, 1002, $D);
check($o['link_precision'] === 'device' && $o['far_port_reason'] === 'port_unmatched' && $o['outcome'] === 'applied'
	&& (int) $o['edge_id'] === $link['id'], 'the observation says precision=device, reason port_unmatched, applied to the link');
$fp = state_lines($pdo);
run($dsn, $user, $password, $pdo, 'again');
check_unchanged($pdo, $fp, 'a second run changes nothing');

// ============================================================================================================
echo "\n=== 4.1b: the far Device is not a reporter — its port is created from the advertisement, port-level as before ===\n";
reset_db($pdo);
add_host($pdo, 101, 'SwR', 'Switch R', '10.0.0.1');
snapshot($pdo, 1001, 101, 1, CLOCK, ports_rows($R, [1 => 'Gi0/1']));
snapshot($pdo, 1002, 101, 2, CLOCK + 10, [nb($R, 1, $X, 'eth0')]);
run($dsn, $user, $password, $pdo, 'with a non-reporter far end');
$P = ports_by_name($pdo, $R)['Gi0/1'];
$Xp = ports_by_name($pdo, $X)['eth0'] ?? null;
check($Xp !== null && is_active(link_between($pdo, $P, $Xp)), 'a physical_link to the port created from the advertisement');
check(count_type($pdo, 'device_link') === 0, 'no device_link');
check(obs_full($pdo, 1002, $X)['link_precision'] === 'port', 'the observation says precision=port');

// ============================================================================================================
echo "\n=== 4.2: shared chassis MAC — two local ports of one reporter advertise the same far port id ===\n";
foreach (['a reporter' => true, 'not a reporter' => false] as $label => $d_reports) {
	echo "  -- far Device is {$label}\n";
	reset_db($pdo);
	add_host($pdo, 101, 'SwR', 'Switch R', '10.0.0.1');
	snapshot($pdo, 1001, 101, 1, CLOCK, ports_rows($R, [1 => 'Gi0/1', 2 => 'Gi0/2']));
	if ($d_reports) {
		add_host($pdo, 104, 'SwD', 'Switch D', '10.0.0.4');
		snapshot($pdo, 4001, 104, 1, CLOCK, ports_rows($D, [1 => 'Gi0/1', 2 => 'Gi0/2']));
	}
	snapshot($pdo, 1002, 101, 2, CLOCK + 10, [nbt($R, 1, $D, $D, 'macAddress'), nbt($R, 2, $D, $D, 'macAddress')]);
	run($dsn, $user, $password, $pdo, "shared id, far Device is {$label}");
	$Dd = device_id($pdo, $D);
	$ports = ports_by_name($pdo, $R);
	$l1 = device_link($pdo, $ports['Gi0/1'], $Dd);
	$l2 = device_link($pdo, $ports['Gi0/2'], $Dd);
	check($l1 !== null && $l2 !== null, 'two device-level links R:P1 -> D and R:P2 -> D');
	check(($l1['far_port_reason'] ?? null) === 'port_shared_id' && ($l2['far_port_reason'] ?? null) === 'port_shared_id',
		'the reason is port_shared_id on both');
	check(count_type($pdo, 'physical_link') === 0, 'no physical_link');
}

// ============================================================================================================
echo "\n=== 4.2c: shared chassis MAC, single cable — each side names the other once, both advertise the chassis MAC ===\n";
reset_db($pdo);
reporters($pdo, $R, $D);
snapshot($pdo, 1001, 101, 1, CLOCK, ports_rows($R, [1 => 'Gi0/1']));
snapshot($pdo, 4001, 104, 1, CLOCK, ports_rows($D, [1 => 'Gi0/1']));
snapshot($pdo, 1002, 101, 2, CLOCK + 10, [nbt($R, 1, $D, $D, 'macAddress')]);
snapshot($pdo, 4002, 104, 2, CLOCK + 10, [nbt($D, 1, $R, $R, 'macAddress')]);
run($dsn, $user, $password, $pdo, 'single cable, chassis MAC as port id');
$Dd = device_id($pdo, $D);
$Rd = device_id($pdo, $R);
$P = ports_by_name($pdo, $R)['Gi0/1'];
$Q = ports_by_name($pdo, $D)['Gi0/1'];
check(device_link($pdo, $P, $Dd) !== null && device_link($pdo, $Q, $Rd) !== null, 'R:P -> D and D:Q -> R are both device-level');
check(count_type($pdo, 'physical_link') === 0, 'no reciprocal pairing: no physical_link');
$fp = state_lines($pdo);
run($dsn, $user, $password, $pdo, 'again');
check_unchanged($pdo, $fp, 'a second run changes nothing');

// ============================================================================================================
echo "\n=== 4.3: LAG — two local ports on each side, ids unusable ===\n";
reset_db($pdo);
reporters($pdo, $R, $D);
snapshot($pdo, 1001, 101, 1, CLOCK, ports_rows($R, [1 => 'Gi0/1', 2 => 'Gi0/2']));
snapshot($pdo, 4001, 104, 1, CLOCK, ports_rows($D, [1 => 'Gi0/1', 2 => 'Gi0/2']));
snapshot($pdo, 1002, 101, 2, CLOCK + 10, [nb($R, 1, $D, 'Po1'), nb($R, 2, $D, 'Po1x')]);
snapshot($pdo, 4002, 104, 2, CLOCK + 10, [nb($D, 1, $R, 'Po9'), nb($D, 2, $R, 'Po9x')]);
run($dsn, $user, $password, $pdo, 'LAG with unusable ids');
$Dd = device_id($pdo, $D);
$Rd = device_id($pdo, $R);
$rp = ports_by_name($pdo, $R);
$dp = ports_by_name($pdo, $D);
$reasons = [];
foreach ([[$rp['Gi0/1'], $Dd], [$rp['Gi0/2'], $Dd], [$dp['Gi0/1'], $Rd], [$dp['Gi0/2'], $Rd]] as [$port, $dev]) {
	$l = device_link($pdo, $port, $dev);
	check($l !== null, 'a device-level link from port #'.$port);
	$reasons[] = $l['far_port_reason'] ?? '';
}
check($reasons === array_fill(0, 4, 'lag_ambiguous'), 'four device-level links, reason lag_ambiguous');
check(count_type($pdo, 'physical_link') === 0 && count_type($pdo, 'device_link') === 4, 'nothing but those four links');

// ============================================================================================================
echo "\n=== 4.3b: LAG leg resolved — P1's advertisement identifies Q1 ===\n";
reset_db($pdo);
reporters($pdo, $R, $D);
snapshot($pdo, 1001, 101, 1, CLOCK, ports_rows($R, [1 => 'Gi0/1', 2 => 'Gi0/2']));
snapshot($pdo, 4001, 104, 1, CLOCK, ports_rows($D, [1 => 'Gi0/1', 2 => 'Gi0/2']));
snapshot($pdo, 1002, 101, 2, CLOCK + 10, [nb($R, 1, $D, 'Gi0/1'), nb($R, 2, $D, 'Po1x')]);
snapshot($pdo, 4002, 104, 2, CLOCK + 10, [nb($D, 1, $R, 'Gi0/1'), nb($D, 2, $R, 'Po9x')]);
run($dsn, $user, $password, $pdo, 'LAG with one leg resolved');
$Dd = device_id($pdo, $D);
$Rd = device_id($pdo, $R);
$rp = ports_by_name($pdo, $R);
$dp = ports_by_name($pdo, $D);
check(is_active(link_between($pdo, $rp['Gi0/1'], $dp['Gi0/1'])), 'P1 <-> Q1 is a port-level link');
check(device_link($pdo, $rp['Gi0/2'], $Dd) !== null && device_link($pdo, $dp['Gi0/2'], $Rd) !== null,
	'P2 -> D and Q2 -> R stay device-level');
check(count_type($pdo, 'physical_link') === 1 && count_type($pdo, 'device_link') === 2, 'one physical_link and two device_links');

// ============================================================================================================
echo "\n=== 5.1: refinement — the advertisement becomes matchable; same edge id, now a physical_link ===\n";
reset_db($pdo);
reporters($pdo, $R, $D);
snapshot($pdo, 1001, 101, 1, CLOCK, ports_rows($R, [1 => 'Gi0/1']));
snapshot($pdo, 4001, 104, 1, CLOCK, ports_rows($D, [1 => 'Gi0/1']));
snapshot($pdo, 1002, 101, 2, CLOCK + 10, [nb($R, 1, $D, '1')]);
run($dsn, $user, $password, $pdo, 'device-level first');
$Dd = device_id($pdo, $D);
$P = ports_by_name($pdo, $R)['Gi0/1'];
$Q = ports_by_name($pdo, $D)['Gi0/1'];
$before = device_link($pdo, $P, $Dd);
snapshot($pdo, 1002, 101, 2, CLOCK + 20, [nb($R, 1, $D, 'Gi0/1')]);
$r = run($dsn, $user, $password, $pdo, 'after the advertisement matches');
$link = link_between($pdo, $P, $Q);
check($link !== null && $link['id'] === $before['id'], 'the same edge id is now a physical_link R:P <-> D:Q');
check(count_type($pdo, 'device_link') === 0, 'the device_link is gone');
check(!array_key_exists('far_port_reason', $link) && !array_key_exists('far_port_hint', $link), 'reason and hint were removed');
check(($link['last_seen_src'] ?? $link['last_seen_dst'] ?? 0) > 0, 'last_seen is carried over');
check(($r['summary']['links_refined'] ?? 0) === 1, 'the run reports links_refined = 1');
check(obs_full($pdo, 1002, $D)['link_precision'] === 'port' && obs_full($pdo, 1002, $D)['outcome'] === 'applied', 'the observation is applied at port level');

// ============================================================================================================
echo "\n=== 5.1b: refinement with the reverse half — D:Q -> R is absorbed ===\n";
reset_db($pdo);
reporters($pdo, $R, $D);
snapshot($pdo, 1001, 101, 1, CLOCK, ports_rows($R, [1 => 'Gi0/1']));
snapshot($pdo, 4001, 104, 1, CLOCK, ports_rows($D, [1 => 'Gi0/1']));
snapshot($pdo, 1002, 101, 2, CLOCK + 10, [nb($R, 1, $D, '1')]);
snapshot($pdo, 4002, 104, 2, CLOCK + 10, [nb($D, 1, $R, '7')]);
run($dsn, $user, $password, $pdo, 'both halves device-level');
$Dd = device_id($pdo, $D);
$Rd = device_id($pdo, $R);
$P = ports_by_name($pdo, $R)['Gi0/1'];
$Q = ports_by_name($pdo, $D)['Gi0/1'];
$forward = device_link($pdo, $P, $Dd);
check($forward !== null && device_link($pdo, $Q, $Rd) !== null, 'both device_links R:P -> D and D:Q -> R are active');
snapshot($pdo, 1002, 101, 2, CLOCK + 20, [nb($R, 1, $D, 'Gi0/1')]);
run($dsn, $user, $password, $pdo, 'R\'s advertisement becomes matchable');
$link = link_between($pdo, $P, $Q);
check($link !== null && $link['id'] === $forward['id'], 'one physical_link P <-> Q, with the id of R:P -> D');
check(count_type($pdo, 'device_link') === 0, 'D:Q -> R was absorbed (deleted)');
check(obs_full($pdo, 1002, $D)['outcome'] === 'applied', 'R\'s observation is applied, no conflict');
$o = obs_full($pdo, 4002, $R);
check($o['outcome'] === 'applied' && (int) $o['edge_id'] === $forward['id'] && (int) $o['precision_lower'] === 1,
	'D\'s device-level observation of R confirms that link at lower precision');
$fp = state_lines($pdo);
for ($i = 1; $i <= 5; $i++) {
	run($dsn, $user, $password, $pdo, "extra run {$i}");
}
check_unchanged($pdo, $fp, '5 more ingest runs change nothing');

// ============================================================================================================
echo "\n=== 5.1c: refinement blocked — Q holds a supported link to a third Device ===\n";
reset_db($pdo);
reporters($pdo, $R, $D);
add_host($pdo, 105, 'SwE', 'Switch E', '10.0.0.5');
snapshot($pdo, 1001, 101, 1, CLOCK, ports_rows($R, [1 => 'Gi0/1']));
snapshot($pdo, 4001, 104, 1, CLOCK, ports_rows($D, [1 => 'Gi0/1']));
snapshot($pdo, 5001, 105, 1, CLOCK, ports_rows($E, [1 => 'Gi0/9']));
snapshot($pdo, 1002, 101, 2, CLOCK + 10, [nb($R, 1, $D, '1')]);
snapshot($pdo, 5002, 105, 2, CLOCK + 10, [nb($E, 1, $D, 'Gi0/1')]);       // M = E:1 <-> D:Gi0/1 (supported by E)
run($dsn, $user, $password, $pdo, 'L and M');
$Dd = device_id($pdo, $D);
$P = ports_by_name($pdo, $R)['Gi0/1'];
$Q = ports_by_name($pdo, $D)['Gi0/1'];
$M = link_between($pdo, ports_by_name($pdo, $E)['Gi0/9'], $Q);
check($M !== null && device_link($pdo, $P, $Dd) !== null, 'R:P -> D (device-level) and M = E <-> D:Q exist');
snapshot($pdo, 1002, 101, 2, CLOCK + 20, [nb($R, 1, $D, 'Gi0/1')]);
run($dsn, $user, $password, $pdo, 'R\'s advertisement now names Q, which M holds');
check(device_link($pdo, $P, $Dd) !== null && count_type($pdo, 'physical_link') === 1, 'R:P -> D stays a device_link');
$o = obs_full($pdo, 1002, $D);
check($o['outcome'] === 'conflict' && (int) $o['edge_id'] === $M['id'], 'the observation is a conflict pointing at M');

// ============================================================================================================
echo "\n=== 6.1: contradiction compares far Devices — R reports E on P, nothing supports the device-level link ===\n";
reset_db($pdo);
reporters($pdo, $R, $D);
snapshot($pdo, 1001, 101, 1, CLOCK, ports_rows($R, [1 => 'Gi0/1']));
snapshot($pdo, 4001, 104, 1, CLOCK, ports_rows($D, [1 => 'Gi0/1']));
snapshot($pdo, 1002, 101, 2, CLOCK + 10, [nb($R, 1, $D, '1')]);
run($dsn, $user, $password, $pdo, 'R -> D device-level');
$Dd = device_id($pdo, $D);
$P = ports_by_name($pdo, $R)['Gi0/1'];
check(device_link($pdo, $P, $Dd) !== null, 'L = R:P -> D exists');
snapshot($pdo, 1002, 101, 2, CLOCK + 50, [nb($R, 1, $E, 'eth0')]);
run($dsn, $user, $password, $pdo, 'R now reports E on P');
$L = device_link($pdo, $P, $Dd);
check(($L['superseded_at'] ?? null) === CLOCK + 50, 'L is superseded at the contradicting snapshot\'s clock');
$Ep = ports_by_name($pdo, $E)['eth0'] ?? null;
check($Ep !== null && is_active(link_between($pdo, $P, $Ep)), 'R:P <-> E:eth0 is created');

// ============================================================================================================
echo "\n=== 6.2: the same Device at two precisions is one link (LLDP port-level, CDP device-level) ===\n";
reset_db($pdo);
reporters($pdo, $R, $D);
snapshot($pdo, 1001, 101, 1, CLOCK, ports_rows($R, [1 => 'Gi0/1']));
snapshot($pdo, 4001, 104, 1, CLOCK, ports_rows($D, [1 => 'Gi0/1']));
snapshot($pdo, 1002, 101, 2, CLOCK + 10, [nb($R, 1, $D, 'Gi0/1')]);                   // LLDP: port-level
snapshot($pdo, 1003, 101, 2, CLOCK + 10, [nb($R, 1, $D, '1', 'cdp')]);                // CDP: device-level
run($dsn, $user, $password, $pdo, 'LLDP at port level, CDP at device level');
$P = ports_by_name($pdo, $R)['Gi0/1'];
$Q = ports_by_name($pdo, $D)['Gi0/1'];
check(is_active(link_between($pdo, $P, $Q)) && count_type($pdo, 'device_link') === 0, 'one port-level link, no device_link');
$o = obs_full($pdo, 1003, $D);
check($o['outcome'] === 'applied' && $o['link_precision'] === 'device' && (int) $o['precision_lower'] === 1,
	'the CDP observation is applied and flagged precision_lower');

// ============================================================================================================
$T = time();
$day = 86400;
echo "\n=== 5.2: no early downgrade — the far port was confirmed recently ===\n";
reset_db($pdo);
reporters($pdo, $R, $D);
snapshot($pdo, 1001, 101, 1, $T - 3 * $day, ports_rows($R, [1 => 'Gi0/1']));
snapshot($pdo, 4001, 104, 1, $T - 3 * $day, ports_rows($D, [1 => 'Gi0/1']));
snapshot($pdo, 1002, 101, 2, $T - 3 * $day, [nb($R, 1, $D, 'Gi0/1')]);
run($dsn, $user, $password, $pdo, 'port-level, 3 days ago');
$P = ports_by_name($pdo, $R)['Gi0/1'];
$Q = ports_by_name($pdo, $D)['Gi0/1'];
$L = link_between($pdo, $P, $Q);
check(($L['last_seen_port'] ?? 0) === $T - 3 * $day, 'last_seen_port is the snapshot clock');
snapshot($pdo, 1002, 101, 2, $T - $day, [nb($R, 1, $D, '1')]);                         // now only at device level
$r = run($dsn, $user, $password, $pdo, 'device-level, 1 day ago');
$L2 = link_between($pdo, $P, $Q);
check($L2 !== null && $L2['id'] === $L['id'] && count_type($pdo, 'device_link') === 0, 'the link is unchanged (still a physical_link)');
check(($L2['last_seen_port'] ?? 0) === $T - 3 * $day, 'last_seen_port did not advance');
check(max($L2['last_seen_src'] ?? 0, $L2['last_seen_dst'] ?? 0) === $T - $day, 'last_seen advanced to the device-level snapshot');
$o = obs_full($pdo, 1002, $D);
check($o['link_precision'] === 'device' && (int) $o['precision_lower'] === 1 && $o['outcome'] === 'applied',
	'the observation is applied with precision_lower');

// ============================================================================================================
echo "\n=== 5.2b: conversion after the threshold — same edge id, now a device_link with reason port_lost ===\n";
reset_db($pdo);
reporters($pdo, $R, $D);
add_host($pdo, 105, 'SwE', 'Switch E', '10.0.0.5');
snapshot($pdo, 1001, 101, 1, $T - 20 * $day, ports_rows($R, [1 => 'Gi0/1']));
snapshot($pdo, 4001, 104, 1, $T - 20 * $day, ports_rows($D, [1 => 'Gi0/1']));
snapshot($pdo, 5001, 105, 1, $T - 20 * $day, ports_rows($E, [1 => 'Gi0/9']));
snapshot($pdo, 1002, 101, 2, $T - 20 * $day, [nb($R, 1, $D, 'Gi0/1')]);
run($dsn, $user, $password, $pdo, 'port-level, 20 days ago');
$P = ports_by_name($pdo, $R)['Gi0/1'];
$Q = ports_by_name($pdo, $D)['Gi0/1'];
$Dd = device_id($pdo, $D);
$L = link_between($pdo, $P, $Q);
check($L !== null, 'R:P <-> D:Q exists');
snapshot($pdo, 1002, 101, 2, $T - $day, [nb($R, 1, $D, '1')]);
$r = run($dsn, $user, $password, $pdo, 'only device-level for 19 days');
$conv = device_link($pdo, $P, $Dd);
check($conv !== null && $conv['id'] === $L['id'], 'same edge id, now device_link R:P -> D');
check(($conv['far_port_reason'] ?? null) === 'port_lost', 'reason port_lost');
check(link_between($pdo, $P, $Q) === null, 'no physical_link remains');
check(($r['summary']['links_downgraded'] ?? 0) === 1, 'the run reports links_downgraded = 1');
// D:Q is free: a candidate for Q in the same run gets it.
snapshot($pdo, 5002, 105, 2, $T - $day, [nb($E, 1, $D, 'Gi0/1')]);
run($dsn, $user, $password, $pdo, 'E claims D:Q');
check(is_active(link_between($pdo, ports_by_name($pdo, $E)['Gi0/9'], $Q)), 'E:1 <-> D:Q is created on the freed port');
// Port identified again -> back to a physical_link (5.1).
snapshot($pdo, 1002, 101, 2, $T - $day + 60, [nb($R, 1, $D, 'Gi0/1')]);
snapshot($pdo, 5002, 105, 2, $T - $day + 60, []);
run($dsn, $user, $password, $pdo, 'R names Q again');
$back = link_between($pdo, $P, $Q);
check($back !== null && $back['id'] === $L['id'] && !array_key_exists('far_port_reason', $back), 'refined back to a physical_link, same id');

// ============================================================================================================
echo "\n=== 5.2c: no conversion without a device-level sighting; a manual physical_link never converts ===\n";
reset_db($pdo);
reporters($pdo, $R, $D);
snapshot($pdo, 1001, 101, 1, $T - 20 * $day, ports_rows($R, [1 => 'Gi0/1']));
snapshot($pdo, 4001, 104, 1, $T - 20 * $day, ports_rows($D, [1 => 'Gi0/1']));
snapshot($pdo, 1002, 101, 2, $T - 20 * $day, [nb($R, 1, $D, 'Gi0/1')]);
run($dsn, $user, $password, $pdo, 'port-level, 20 days ago');
$P = ports_by_name($pdo, $R)['Gi0/1'];
$Q = ports_by_name($pdo, $D)['Gi0/1'];
snapshot($pdo, 1002, 101, 2, $T - $day, []);                                            // nothing resolves P to D
run($dsn, $user, $password, $pdo, 'nothing names D');
check(link_between($pdo, $P, $Q) !== null && count_type($pdo, 'device_link') === 0, 'the link stays a physical_link (stale by the normal rule)');
// manual
$pdo->prepare("UPDATE topo_edges SET attrs = JSON_SET(attrs, '\$.discovered_via', 'manual') WHERE type='physical_link'")->execute();
snapshot($pdo, 1002, 101, 2, $T - $day, [nb($R, 1, $D, '1')]);
run($dsn, $user, $password, $pdo, 'a manual link, device-level sighting');
check(link_between($pdo, $P, $Q) !== null && count_type($pdo, 'device_link') === 0, 'a manual physical_link never converts');

// ============================================================================================================
echo "\n=== 6.3: confirmation of a manual device_link ===\n";
function manual_setup(PDO $pdo, string $dsn, string $user, string $password, string $R, string $D, string $E): array {
	reset_db($pdo);
	reporters($pdo, $R, $D);
	add_host($pdo, 105, 'SwE', 'Switch E', '10.0.0.5');
	snapshot($pdo, 1001, 101, 1, CLOCK, ports_rows($R, [1 => 'Gi0/1']));
	snapshot($pdo, 4001, 104, 1, CLOCK, ports_rows($D, [1 => 'Gi0/1']));
	snapshot($pdo, 5001, 105, 1, CLOCK, ports_rows($E, [1 => 'Gi0/9']));
	snapshot($pdo, 1002, 101, 2, CLOCK + 10, []);
	run($dsn, $user, $password, $pdo, 'ports only');
	$Dd = device_id($pdo, $D);
	$P = ports_by_name($pdo, $R)['Gi0/1'];
	$Q = ports_by_name($pdo, $D)['Gi0/1'];
	// A manual device_link R:P -> D (3.2), with an acknowledgment-free attrs set
	$pdo->prepare("INSERT INTO topo_edges (type, src_id, dst_id, attrs, created_at) VALUES ('device_link', ?, ?, ?, ?)")
		->execute([$P, $Dd, json_encode(['discovered_via' => 'manual', 'far_port_reason' => 'manual', 'last_seen' => CLOCK]), CLOCK]);

	return [$Dd, $P, $Q, (int) $pdo->lastInsertId()];
}

// discovery of D at device level: the manual link becomes a discovered device_link, same id
[$Dd, $P, $Q, $manual_id] = manual_setup($pdo, $dsn, $user, $password, $R, $D, $E);
snapshot($pdo, 1002, 101, 2, CLOCK + 20, [nb($R, 1, $D, '1')]);
$r = run($dsn, $user, $password, $pdo, 'discovery sees D at device level behind the manual device_link');
$l = device_link($pdo, $P, $Dd);
check($l !== null && $l['id'] === $manual_id && $l['discovered_via'] === 'lldp', 'a discovered device_link, same id');
check(($l['far_port_reason'] ?? null) === 'port_unmatched', 'the reason now comes from discovery');
check(($r['summary']['manual_links_contradicted'] ?? -1) === 0, 'no contradiction');

// discovery of D:Q on P (port level): the manual device_link becomes a discovered physical_link, same id
[$Dd, $P, $Q, $manual_id] = manual_setup($pdo, $dsn, $user, $password, $R, $D, $E);
snapshot($pdo, 1002, 101, 2, CLOCK + 20, [nb($R, 1, $D, 'Gi0/1')]);
$r = run($dsn, $user, $password, $pdo, 'discovery sees D:Q behind the manual device_link');
$link = link_between($pdo, $P, $Q);
check($link !== null && $link['id'] === $manual_id && $link['discovered_via'] === 'lldp', 'a discovered physical_link P <-> Q, same id');
check(count_type($pdo, 'device_link') === 0, 'the device_link is gone');
$o = obs_full($pdo, 1002, $D);
check($o['outcome'] === 'applied' && (int) $o['edge_id'] === $manual_id, 'the observation is applied to that link');
check(($r['summary']['manual_links_contradicted'] ?? -1) === 0, 'no contradiction');

// discovery of E on P: contradicted
[$Dd, $P, $Q, $manual_id] = manual_setup($pdo, $dsn, $user, $password, $R, $D, $E);
snapshot($pdo, 1002, 101, 2, CLOCK + 30, [nb($R, 1, $E, 'Gi0/9')]);
$r = run($dsn, $user, $password, $pdo, 'discovery sees E behind the manual device_link');
$o = obs_full($pdo, 1002, $E);
check($o['outcome'] === 'shadowed' && (int) $o['edge_id'] === $manual_id, 'the E observation is shadowed by the manual link');
check(device_link($pdo, $P, $Dd)['discovered_via'] === 'manual', 'the manual link stays manual');
check(($r['summary']['manual_links_contradicted'] ?? -1) === 1, 'a different Device on the manual link\'s port is a contradiction');

echo "\n  -- a manual physical_link R:P <-> D:Q, device-level sighting of D, then of E\n";
reset_db($pdo);
reporters($pdo, $R, $D);
add_host($pdo, 105, 'SwE', 'Switch E', '10.0.0.5');
snapshot($pdo, 1001, 101, 1, CLOCK, ports_rows($R, [1 => 'Gi0/1']));
snapshot($pdo, 4001, 104, 1, CLOCK, ports_rows($D, [1 => 'Gi0/1']));
snapshot($pdo, 5001, 105, 1, CLOCK, ports_rows($E, [1 => 'Gi0/9']));
snapshot($pdo, 1002, 101, 2, CLOCK + 10, []);
run($dsn, $user, $password, $pdo, 'ports only');
$P = ports_by_name($pdo, $R)['Gi0/1'];
$Q = ports_by_name($pdo, $D)['Gi0/1'];
$pdo->prepare("INSERT INTO topo_edges (type, src_id, dst_id, attrs, created_at) VALUES ('physical_link', ?, ?, ?, ?)")
	->execute([min($P, $Q), max($P, $Q), json_encode(['discovered_via' => 'manual', 'last_seen' => CLOCK]), CLOCK]);
snapshot($pdo, 1002, 101, 2, CLOCK + 20, [nb($R, 1, $D, '1')]);
$r = run($dsn, $user, $password, $pdo, 'device-level sighting of D on a manual physical_link');
check(obs_full($pdo, 1002, $D)['outcome'] === 'shadowed' && ($r['summary']['manual_links_contradicted'] ?? -1) === 0,
	'D at device level on the manual port-level link: shadowed, not contradicted');
snapshot($pdo, 1002, 101, 2, CLOCK + 30, [nb($R, 1, $E, 'Gi0/9')]);
$r = run($dsn, $user, $password, $pdo, 'E on the manual physical_link');
check(($r['summary']['manual_links_contradicted'] ?? -1) === 1, 'E on the manual link\'s port: contradicted');

// ============================================================================================================
echo "\n=== 6.3: a manual link with shadow_ack entries is not converted on confirmation (both types) ===\n";
function acked_setup(PDO $pdo, string $dsn, string $user, string $password, string $R, string $D, string $E, string $type): array {
	reset_db($pdo);
	reporters($pdo, $R, $D);
	add_host($pdo, 105, 'SwE', 'Switch E', '10.0.0.5');
	snapshot($pdo, 1001, 101, 1, CLOCK, ports_rows($R, [1 => 'Gi0/1']));
	snapshot($pdo, 4001, 104, 1, CLOCK, ports_rows($D, [1 => 'Gi0/1']));
	snapshot($pdo, 5001, 105, 1, CLOCK, ports_rows($E, [1 => 'Gi0/9']));
	snapshot($pdo, 1002, 101, 2, CLOCK + 10, []);
	run($dsn, $user, $password, $pdo, 'ports only');
	$Dd = device_id($pdo, $D);
	$Ed = device_id($pdo, $E) ?? 0;
	$P = ports_by_name($pdo, $R)['Gi0/1'];
	$Q = ports_by_name($pdo, $D)['Gi0/1'];
	$ack = [['device_id' => $Ed, 'remote_key' => 'chassis:-:x', 'at' => CLOCK, 'by' => 'Admin']];
	$attrs = ['discovered_via' => 'manual', 'last_seen' => CLOCK, 'shadow_ack' => $ack];
	if ($type === 'device_link') {
		$attrs['far_port_reason'] = 'manual';
		$pdo->prepare("INSERT INTO topo_edges (type, src_id, dst_id, attrs, created_at) VALUES ('device_link', ?, ?, ?, ?)")
			->execute([$P, $Dd, json_encode($attrs), CLOCK]);
	}
	else {
		$pdo->prepare("INSERT INTO topo_edges (type, src_id, dst_id, attrs, created_at) VALUES ('physical_link', ?, ?, ?, ?)")
			->execute([min($P, $Q), max($P, $Q), json_encode($attrs), CLOCK]);
	}

	return [$Dd, $P, $Q, (int) $pdo->lastInsertId()];
}

// manual physical_link R:P <-> D:Q with an acknowledged neighbor, confirmed at port level: stays manual
[$Dd, $P, $Q, $id] = acked_setup($pdo, $dsn, $user, $password, $R, $D, $E, 'physical_link');
snapshot($pdo, 1002, 101, 2, CLOCK + 20, [nb($R, 1, $D, 'Gi0/1')]);
run($dsn, $user, $password, $pdo, 'confirmation of an acknowledged manual physical_link');
$link = link_between($pdo, $P, $Q);
check($link !== null && $link['id'] === $id && $link['discovered_via'] === 'manual', 'the manual physical_link stays manual');
check(count($link['shadow_ack'] ?? []) === 1, 'and keeps its shadow_ack');

// manual device_link with an acknowledged neighbor, confirmed at device level: stays manual
[$Dd, $P, $Q, $id] = acked_setup($pdo, $dsn, $user, $password, $R, $D, $E, 'device_link');
snapshot($pdo, 1002, 101, 2, CLOCK + 20, [nb($R, 1, $D, '1')]);
run($dsn, $user, $password, $pdo, 'device-level confirmation of an acknowledged manual device_link');
$l = device_link($pdo, $P, $Dd);
check($l !== null && $l['id'] === $id && $l['discovered_via'] === 'manual' && count($l['shadow_ack'] ?? []) === 1,
	'the manual device_link stays manual with its shadow_ack');

// manual device_link with an acknowledged neighbor, the far port identified: not converted either
[$Dd, $P, $Q, $id] = acked_setup($pdo, $dsn, $user, $password, $R, $D, $E, 'device_link');
snapshot($pdo, 1002, 101, 2, CLOCK + 20, [nb($R, 1, $D, 'Gi0/1')]);
run($dsn, $user, $password, $pdo, 'port-level confirmation of an acknowledged manual device_link');
$l = device_link($pdo, $P, $Dd);
check($l !== null && $l['id'] === $id && $l['discovered_via'] === 'manual' && link_between($pdo, $P, $Q) === null,
	'the manual device_link is not converted to a physical_link');

// ============================================================================================================
echo "\n=== pseudo ports of a Device that becomes a reporter ===\n";

function pp_node(PDO $pdo, string $type, array $attrs, ?int $device_id = null): int {
	$pdo->prepare('INSERT INTO topo_nodes (type, device_id, attrs, created_at, updated_at) VALUES (?, ?, ?, 0, 0)')
		->execute([$type, $device_id, json_encode($attrs)]);

	return (int) $pdo->lastInsertId();
}

function pp_edge(PDO $pdo, int $a, int $b, array $attrs): int {
	$pdo->prepare("INSERT INTO topo_edges (type, src_id, dst_id, attrs, created_at) VALUES ('physical_link', ?, ?, ?, 0)")
		->execute([min($a, $b), max($a, $b), json_encode($attrs)]);

	return (int) $pdo->lastInsertId();
}

function pseudo_ports(PDO $pdo, int $device_id): array {
	$out = [];
	foreach ($pdo->query("SELECT id, attrs FROM topo_nodes WHERE type='port' AND device_id={$device_id}")->fetchAll(PDO::FETCH_ASSOC) as $row) {
		$attrs = json_decode($row['attrs'], true);
		if (!empty($attrs['pseudo'])) {
			$out[(int) $row['id']] = $attrs['name'];
		}
	}

	return $out;
}

/** D (not yet a reporter) with a real port, plus the pieces a scenario needs; D's reporter snapshot is written by the caller. */
function pp_setup(PDO $pdo, string $R, string $D, string $E): array {
	reset_db($pdo);
	add_host($pdo, 104, 'SwD', 'Switch D', '10.0.0.4');
	$d = pp_node($pdo, 'device', ['mac' => $D, 'chassis_id' => $D, 'mgmt_ip' => '10.0.0.4', 'sysname' => 'Switch D', 'vendor' => 'unknown', 'last_seen' => CLOCK]);
	$r = pp_node($pdo, 'device', ['chassis_id' => $R, 'sysname' => 'Switch R', 'last_seen' => CLOCK]);
	$e = pp_node($pdo, 'device', ['chassis_id' => $E, 'sysname' => 'Switch E', 'last_seen' => CLOCK]);
	$rp = pp_node($pdo, 'port', ['if_index' => 1, 'name' => 'Gi0/1', 'pseudo' => false], $r);
	$rp2 = pp_node($pdo, 'port', ['if_index' => 2, 'name' => 'Gi0/2', 'pseudo' => false], $r);
	$ep = pp_node($pdo, 'port', ['if_index' => 9, 'name' => 'Gi0/9', 'pseudo' => false], $e);

	return [$d, $r, $e, $rp, $rp2, $ep];
}

$discovered = static fn (): array => ['discovered_via' => 'lldp', 'last_seen' => CLOCK, 'last_seen_src' => CLOCK];

// -- an unmatched pseudo port with a discovered link: the link becomes a device_link, the port is deleted
reset_db($pdo);
add_host($pdo, 101, 'SwR', 'Switch R', '10.0.0.1');
snapshot($pdo, 1001, 101, 1, CLOCK, ports_rows($R, [1 => 'Gi0/1']));
snapshot($pdo, 1002, 101, 2, CLOCK + 10, [nb($R, 1, $D, 'eth0')]);
run($dsn, $user, $password, $pdo, 'D known only from an advertisement');
$Dd = device_id($pdo, $D);
$P = ports_by_name($pdo, $R)['Gi0/1'];
check(array_values(pseudo_ports($pdo, $Dd)) === ['eth0'], 'before: D has one port, made from the advertisement');
$pseudo_id = array_key_first(pseudo_ports($pdo, $Dd));
$physical = link_between($pdo, $P, $pseudo_id);
check(is_active($physical), 'before: R:P <-> D:eth0 is a physical_link');
add_host($pdo, 104, 'SwD', 'Switch D', '10.0.0.4');
snapshot($pdo, 4001, 104, 1, CLOCK + 20, ports_rows($D, [1 => 'Gi0/1']));
$r = run($dsn, $user, $password, $pdo, 'D becomes a reporter, its port does not match the advertisement');
$l = device_link($pdo, $P, $Dd);
check($l !== null && $l['id'] === $physical['id'], 'the link is a device_link R:P -> D with the same edge id');
check($l['far_port_reason'] === 'port_unmatched' && ($l['far_port_hint']['rem_port'] ?? null) === 'eth0', 'reason port_unmatched, hint from the advertisement');
check(pseudo_ports($pdo, $Dd) === [], 'the pseudo port is gone; D has only its real port');
check(count_type($pdo, 'physical_link') === 0, 'no physical_link is left');
check(($r['summary']['pseudo_ports_converted'] ?? -1) === 1 && ($r['summary']['pseudo_ports_ambiguous'] ?? -1) === 0
	&& ($r['summary']['pseudo_ports_move_failed'] ?? -1) === 0 && ($r['summary']['pseudo_ports_kept_manual'] ?? -1) === 0,
	'status: pseudo_ports_converted = 1, the others 0');
check(($r['summary']['pseudo_ports'][0]['outcome'] ?? null) === 'converted' && ($r['summary']['pseudo_ports'][0]['name'] ?? null) === 'eth0',
	'status: the details name the port');
$state = state_lines($pdo);
$r = run($dsn, $user, $password, $pdo, 'a second ingest');
check_unchanged($pdo, $state, 'a second ingest changes nothing');
check(($r['summary']['pseudo_ports_converted'] ?? -1) === 0, 'and converts nothing');

// -- ambiguous: two pseudo ports match one real port by name; none is merged, both links become device_links
[$d, $rd, $e, $rp, $rp2, $ep] = pp_setup($pdo, $R, $D, $E);
$x1 = pp_node($pdo, 'port', ['if_index' => 701, 'name' => 'Gi0/1', 'pseudo' => true], $d);
$x2 = pp_node($pdo, 'port', ['if_index' => 702, 'name' => 'GigabitEthernet0/1', 'pseudo' => true], $d);
$l1 = pp_edge($pdo, $rp, $x1, $discovered());
$l2 = pp_edge($pdo, $rp2, $x2, $discovered());
snapshot($pdo, 4001, 104, 1, CLOCK + 20, ports_rows($D, [1 => 'Gi0/1']));
$r = run($dsn, $user, $password, $pdo, 'ambiguous pseudo ports');
check(($r['summary']['pseudo_ports_ambiguous'] ?? -1) === 2, 'status: pseudo_ports_ambiguous = 2');
check(pseudo_ports($pdo, $d) === [], 'no pseudo port is left on D');
check(device_link($pdo, $rp, $d)['id'] === $l1 && device_link($pdo, $rp2, $d)['id'] === $l2, 'both links are device_links with their own edge ids');
check(($r['summary']['pseudo_ports_converted'] ?? -1) === 2, 'and both ports counted as converted');
$state = state_lines($pdo);
run($dsn, $user, $password, $pdo, 'a second ingest');
check_unchanged($pdo, $state, 'a second ingest changes nothing');

// -- failed move: the real port already has another active link, so the link cannot move onto it
[$d, $rd, $e, $rp, $rp2, $ep] = pp_setup($pdo, $R, $D, $E);
$real = pp_node($pdo, 'port', ['if_index' => 1, 'name' => 'Gi0/1', 'pseudo' => false], $d);
$m = pp_edge($pdo, $ep, $real, $discovered());                                           // M = E:9 <-> D:Gi0/1
$x = pp_node($pdo, 'port', ['if_index' => 703, 'name' => 'GigabitEthernet0/1', 'pseudo' => true], $d);
$l = pp_edge($pdo, $rp, $x, $discovered());                                              // L = R:1 <-> D:pseudo
snapshot($pdo, 4001, 104, 1, CLOCK + 20, ports_rows($D, [1 => 'Gi0/1']));
$r = run($dsn, $user, $password, $pdo, 'the real port already has a link');
check(($r['summary']['pseudo_ports_move_failed'] ?? -1) === 1, 'status: pseudo_ports_move_failed = 1');
check(is_active(link_between($pdo, $ep, $real)), 'the existing link on the real port is untouched');
check(device_link($pdo, $rp, $d)['id'] === $l && pseudo_ports($pdo, $d) === [], 'the link became a device_link (same id) and the pseudo port is gone');
$state = state_lines($pdo);
run($dsn, $user, $password, $pdo, 'a second ingest');
check_unchanged($pdo, $state, 'a second ingest changes nothing');

// -- a manual link on a pseudo port: not converted, the port stays and is reported
[$d, $rd, $e, $rp, $rp2, $ep] = pp_setup($pdo, $R, $D, $E);
$x = pp_node($pdo, 'port', ['if_index' => 704, 'name' => 'eth0', 'pseudo' => true], $d);
$man = pp_edge($pdo, $rp, $x, ['discovered_via' => 'manual', 'last_seen' => CLOCK]);
$x2 = pp_node($pdo, 'port', ['if_index' => 705, 'name' => 'eth1', 'pseudo' => true], $d);
$auto = pp_edge($pdo, $rp2, $x2, $discovered());
snapshot($pdo, 4001, 104, 1, CLOCK + 20, ports_rows($D, [1 => 'Gi0/1']));
$r = run($dsn, $user, $password, $pdo, 'a manual link on a pseudo port');
check(($r['summary']['pseudo_ports_kept_manual'] ?? -1) === 1, 'status: pseudo_ports_kept_manual = 1');
$kept = array_values(array_filter($r['summary']['pseudo_ports'] ?? [], static fn (array $n): bool => $n['outcome'] === 'kept_manual'));
check(($kept[0]['edge_ids'] ?? null) === [$man] && ($kept[0]['name'] ?? null) === 'eth0', 'the details name the port and the manual edge');
check(isset(pseudo_ports($pdo, $d)[$x]) && is_active(link_between($pdo, $rp, $x)) && link_between($pdo, $rp, $x)['discovered_via'] === 'manual',
	'the manual link and its pseudo port stay');
check(device_link($pdo, $rp2, $d)['id'] === $auto && !isset(pseudo_ports($pdo, $d)[$x2]), 'the discovered link on the other pseudo port is converted');
$state = state_lines($pdo);
$r = run($dsn, $user, $password, $pdo, 'a second ingest');
check_unchanged($pdo, $state, 'a second ingest changes nothing');
check(($r['summary']['pseudo_ports_kept_manual'] ?? -1) === 1, 'the manual one is reported again');

// ============================================================================================================
echo "\n=== order independence: the same snapshots, reporters walked in either order ===\n";
$picture = [];
foreach (['101,104', '104,101'] as $order) {
	reset_db($pdo);
	reporters($pdo, $R, $D);
	snapshot($pdo, 1001, 101, 1, CLOCK, ports_rows($R, [1 => 'Gi0/1', 2 => 'Gi0/2']));
	snapshot($pdo, 4001, 104, 1, CLOCK, ports_rows($D, [1 => 'Gi0/1', 2 => 'Gi0/2']));
	snapshot($pdo, 1002, 101, 2, CLOCK + 10, [nb($R, 1, $D, 'Gi0/1'), nb($R, 2, $D, 'Po1x')]);
	snapshot($pdo, 4002, 104, 2, CLOCK + 10, [nb($D, 1, $R, 'Gi0/1'), nb($D, 2, $R, 'Po9x')]);
	run($dsn, $user, $password, $pdo, "order {$order}", [], $order);
	$rows = [];
	foreach (all_links($pdo) as $l) {
		$rows[] = $l['type'].' '.($l['far_port_reason'] ?? '-').' '.(array_key_exists('superseded_at', $l) ? 's' : 'a');
	}
	sort($rows);
	$picture[$order] = $rows;
}
check($picture['101,104'] === $picture['104,101'], 'both orders give the same set of links');

echo "\nAll device-level link tests passed.\n";
