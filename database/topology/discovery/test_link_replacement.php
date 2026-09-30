#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * test_link_replacement.php — acceptance tests of topology-link-replacement-spec.md §8.
 *
 * Snapshots are written straight into topo_lld_snapshot with fixed clocks, and the REAL ingest.php runs as a
 * subprocess after every change. Every scenario starts from an empty topology, and after every run the port
 * uniqueness invariant is checked over all ports: at most one non-superseded link per port.
 *
 * Usage: test_link_replacement.php <PDO DSN> <database user> [password]
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
	$pdo->exec("CREATE TABLE hosts (hostid BIGINT UNSIGNED PRIMARY KEY, host VARCHAR(128) NOT NULL, name VARCHAR(128) NOT NULL DEFAULT '')");
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

function ingest(string $dsn, string $user, string $password, PDO $pdo, string $when, array $extra = [], ?string $order = null): array {
	$r = run_ingest($dsn, $user, $password, $extra, $order);
	if ($r['code'] !== 0) {
		fail_test("ingest failed {$when}: ".trim($r['err'].' '.$r['out']));
	}
	assert_port_uniqueness($pdo, $when);
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

// ============================================================================================================
echo "\n=== 1: recable, the far end is not a reporter ===\n";
reset_db($pdo);
add_host($pdo, 101, 'SwR', 'Switch R', '10.0.0.1');
snapshot($pdo, 1001, 101, 1, CLOCK, ports_rows($R, [1 => 'Gi0/1']));
snapshot($pdo, 1002, 101, 2, CLOCK + 50, [nbr($R, 1, $B, 'Gi0/24')]);
ingest($dsn, $user, $password, $pdo, 'with R:P<->B');
$P = ports_of($pdo, $R)['Gi0/1'];
$Q = ports_of($pdo, $B)['Gi0/24'];
check(is_active(link_between($pdo, $P, $Q)), 'R:P<->B is an active link');
snapshot($pdo, 1002, 101, 2, CLOCK + 100, [nbr($R, 1, $C, 'eth0')]);
$r = ingest($dsn, $user, $password, $pdo, 'after the recable');
$old = link_between($pdo, $P, $Q);
check(($old['superseded_at'] ?? null) === CLOCK + 100, 'the old link is superseded at the clock of the contradicting snapshot');
$new = link_between($pdo, $P, ports_of($pdo, $C)['eth0']);
check(is_active($new), 'P<->C is active');
$obs = observation($pdo, 1002, $C);
check($obs['outcome'] === 'applied' && (int) $obs['edge_id'] === $new['id'], 'the C observation is applied and points at the new link');
check((int) $r['summary']['links_superseded'] === 1 && (int) $r['summary']['links_revived'] === 0, 'the status summary counts one superseded link');
check(link_between($pdo, $P, $Q) !== null, 'the superseded link is kept, not deleted');
check(max($old['last_seen_src'] ?? 0, $old['last_seen_dst'] ?? 0) === CLOCK + 50, 'its last_seen_* stay frozen');

// ============================================================================================================
foreach ([90 => 'replace', 100 => 'conflict', 110 => 'conflict'] as $b_clock => $expected) {
	echo "\n=== 2: recable, both ends are reporters — B's latest snapshot at ".($b_clock)." vs R's at 100 ({$expected}) ===\n";
	reset_db($pdo);
	two_switches($pdo, $R, $B);
	snapshot($pdo, 1002, 101, 2, CLOCK + 50, [nbr($R, 1, $B, 'Gi0/24')]);
	snapshot($pdo, 2002, 102, 2, CLOCK + 50, [nbr($B, 24, $R, 'Gi0/1')]);
	ingest($dsn, $user, $password, $pdo, 'with L confirmed from both ends');
	$P = ports_of($pdo, $R)['Gi0/1'];
	$Q = ports_of($pdo, $B)['Gi0/24'];
	check(is_active(link_between($pdo, $P, $Q)), 'L = R:P<->B:Q is active');
	snapshot($pdo, 1002, 101, 2, CLOCK + 100, [nbr($R, 1, $C, 'eth0')]);
	snapshot($pdo, 2002, 102, 2, CLOCK + $b_clock, [nbr($B, 24, $R, 'Gi0/1')]);
	ingest($dsn, $user, $password, $pdo, "with B at {$b_clock}");
	$L = link_between($pdo, $P, $Q);
	$obs = observation($pdo, 1002, $C);
	if ($expected === 'replace') {
		check(($L['superseded_at'] ?? null) === CLOCK + 100, 'L is superseded: B\'s support (90) is older than the contradiction (100)');
		check($obs['outcome'] === 'applied', 'the C observation is applied');
	}
	else {
		check(is_active($L), "L stays: B's support ({$b_clock}) is not older than the contradiction (100)");
		check($obs['outcome'] === 'conflict' && (int) $obs['edge_id'] === $L['id'], 'the C observation is a conflict pointing at L');
		check(link_between($pdo, $P, ports_of($pdo, $C)['eth0']) === null, 'no link to C was created');
	}
}
// after B's next snapshot without R:P
snapshot($pdo, 2002, 102, 2, CLOCK + 120, [nbr($B, 23, $D, 'eth1')]);
ingest($dsn, $user, $password, $pdo, 'after B\'s next snapshot at 120 without R:P');
$L = link_between($pdo, $P, $Q);
check(($L['superseded_at'] ?? null) === CLOCK + 100, 'B stopped confirming L: the replacement now goes through (superseded at 100)');
check(observation($pdo, 1002, $C)['outcome'] === 'applied', 'and the C observation becomes applied');

// ============================================================================================================
echo "\n=== 3: the contradiction comes from the far end ===\n";
reset_db($pdo);
two_switches($pdo, $R, $B);
snapshot($pdo, 1002, 101, 2, CLOCK + 50, [nbr($R, 1, $B, 'Gi0/24')]);
snapshot($pdo, 2002, 102, 2, CLOCK + 50, [nbr($B, 24, $R, 'Gi0/1')]);
ingest($dsn, $user, $password, $pdo, 'with L');
$P = ports_of($pdo, $R)['Gi0/1'];
$Q = ports_of($pdo, $B)['Gi0/24'];
snapshot($pdo, 1002, 101, 2, CLOCK + 100, [nbr($R, 1, $B, 'Gi0/24')]);      // R's latest (older) still shows B on P
snapshot($pdo, 2002, 102, 2, CLOCK + 110, [nbr($B, 24, $D, 'eth1')]);       // B now shows D on Q
ingest($dsn, $user, $password, $pdo, 'after B sees D');
check((link_between($pdo, $P, $Q)['superseded_at'] ?? null) === CLOCK + 110, 'L is superseded at B\'s clock');
check(is_active(link_between($pdo, $Q, ports_of($pdo, $D)['eth1'])), 'Q<->D is created');
check(observation($pdo, 1002, $B)['outcome'] === 'conflict', 'R\'s (older) confirmation of the replaced link is a conflict');

// ============================================================================================================
echo "\n=== 4: LLDP and CDP of one reporter disagree at the same clock ===\n";
reset_db($pdo);
add_host($pdo, 101, 'SwR', 'Switch R', '10.0.0.1');
snapshot($pdo, 1001, 101, 1, CLOCK, ports_rows($R, [1 => 'Gi0/1']));
snapshot($pdo, 1002, 101, 2, CLOCK + 50, [nbr($R, 1, $B, 'Gi0/24')]);
ingest($dsn, $user, $password, $pdo, 'with L');
$P = ports_of($pdo, $R)['Gi0/1'];
$Q = ports_of($pdo, $B)['Gi0/24'];
snapshot($pdo, 1002, 101, 2, CLOCK + 100, [nbr($R, 1, $C, 'eth0')]);                 // the LLDP rule
snapshot($pdo, 1003, 101, 2, CLOCK + 100, [nbr($R, 1, $B, 'Gi0/24', 'cdp')]);        // the CDP rule, same clock
ingest($dsn, $user, $password, $pdo, 'LLDP vs CDP');
$L = link_between($pdo, $P, $Q);
check(is_active($L), 'L stays: the CDP rule supports it at the same clock');
$obs = observation($pdo, 1002, $C);
check($obs['outcome'] === 'conflict' && (int) $obs['edge_id'] === $L['id'], 'the LLDP observation is a conflict pointing at L');
check(observation($pdo, 1003, $B)['outcome'] === 'applied', 'the CDP observation confirming L is applied');

// ============================================================================================================
echo "\n=== 5: revival ===\n";
reset_db($pdo);
two_switches($pdo, $R, $B);
snapshot($pdo, 1002, 101, 2, CLOCK + 50, [nbr($R, 1, $B, 'Gi0/24')]);
snapshot($pdo, 2002, 102, 2, CLOCK + 50, [nbr($B, 24, $R, 'Gi0/1')]);
ingest($dsn, $user, $password, $pdo, 'with L');
$P = ports_of($pdo, $R)['Gi0/1'];
$Q = ports_of($pdo, $B)['Gi0/24'];
snapshot($pdo, 1002, 101, 2, CLOCK + 100, [nbr($R, 1, $C, 'eth0')]);
snapshot($pdo, 2002, 102, 2, CLOCK + 60, [nbr($B, 23, $D, 'eth1')]);
ingest($dsn, $user, $password, $pdo, 'after the replacement');
$PC = ports_of($pdo, $C)['eth0'];
check(($L = link_between($pdo, $P, $Q)) && isset($L['superseded_at']) && is_active(link_between($pdo, $P, $PC)), 'L replaced by P<->C');
snapshot($pdo, 2002, 102, 2, CLOCK + 300, [nbr($B, 24, $R, 'Gi0/1')]);              // B reports R:P again, newer
$r = ingest($dsn, $user, $password, $pdo, 'after B reports R:P again');
check(is_active(link_between($pdo, $P, $Q)), 'L is revived (superseded_at cleared)');
check((link_between($pdo, $P, $PC)['superseded_at'] ?? null) === CLOCK + 300, 'the link that replaced it goes through the rule and is superseded in turn');
check((int) $r['summary']['links_revived'] === 1 && (int) $r['summary']['links_superseded'] === 1, 'revived and superseded are counted');
check(observation($pdo, 1002, $C)['outcome'] === 'conflict', 'R\'s C observation is now a conflict');

// ============================================================================================================
echo "\n=== 6: a manual link wins ===\n";
reset_db($pdo);
add_host($pdo, 101, 'SwR', 'Switch R', '10.0.0.1');
snapshot($pdo, 1001, 101, 1, CLOCK, ports_rows($R, [1 => 'Gi0/1']));
snapshot($pdo, 1002, 101, 2, CLOCK + 50, [nbr($R, 1, $B, 'Gi0/24')]);
ingest($dsn, $user, $password, $pdo, 'to create the ports');
$P = ports_of($pdo, $R)['Gi0/1'];
$Q = ports_of($pdo, $B)['Gi0/24'];
$pdo->exec("DELETE FROM topo_edges");
$pdo->prepare("INSERT INTO topo_edges (type, src_id, dst_id, attrs, created_at) VALUES ('physical_link', ?, ?, ?, 1)")
	->execute([min($P, $Q), max($P, $Q), json_encode(['discovered_via' => 'manual', 'last_seen' => 1])]);
snapshot($pdo, 1002, 101, 2, CLOCK + 100, [nbr($R, 1, $C, 'eth0')]);
$r = ingest($dsn, $user, $password, $pdo, 'LLDP shows C behind a manual link');
$manual = link_between($pdo, $P, $Q);
check(is_active($manual) && $manual['discovered_via'] === 'manual', 'the manual link stays, untouched');
$obs = observation($pdo, 1002, $C);
check($obs['outcome'] === 'shadowed' && (int) $obs['edge_id'] === $manual['id'], 'the C observation is shadowed by the manual link');
check((int) $r['summary']['links_superseded'] === 0, 'nothing is superseded');

// ============================================================================================================
echo "\n=== 7: two neighbors on one port in one snapshot ===\n";
reset_db($pdo);
add_host($pdo, 101, 'SwR', 'Switch R', '10.0.0.1');
snapshot($pdo, 1001, 101, 1, CLOCK, ports_rows($R, [1 => 'Gi0/1']));
snapshot($pdo, 1002, 101, 2, CLOCK + 50, [nbr($R, 1, $B, 'Gi0/24')]);
ingest($dsn, $user, $password, $pdo, 'with L to B');
$P = ports_of($pdo, $R)['Gi0/1'];
$Q = ports_of($pdo, $B)['Gi0/24'];
snapshot($pdo, 1002, 101, 2, CLOCK + 100, [nbr($R, 1, $B, 'Gi0/24'), nbr($R, 1, $C, 'eth0')]);
$r = ingest($dsn, $user, $password, $pdo, 'two neighbors, one of them the existing link');
check(is_active(link_between($pdo, $P, $Q)), 'the existing link stays');
check(observation($pdo, 1002, $B)['outcome'] === 'applied' && observation($pdo, 1002, $C)['outcome'] === 'ambiguous',
	'its neighbor is applied, the other is ambiguous');
check(isset(ports_of($pdo, $C)['eth0']) && link_between($pdo, $P, ports_of($pdo, $C)['eth0']) === null, 'no link to the other');
check((int) $r['summary']['ports_ambiguous'] === 1, 'ports_ambiguous is counted');
snapshot($pdo, 1002, 101, 2, CLOCK + 200, [nbr($R, 1, $C, 'eth0'), nbr($R, 1, $D, 'eth1')]);
ingest($dsn, $user, $password, $pdo, 'two neighbors, none of them the existing link');
check(is_active(link_between($pdo, $P, $Q)), 'no link change on the port');
check(observation($pdo, 1002, $C)['outcome'] === 'ambiguous' && observation($pdo, 1002, $D)['outcome'] === 'ambiguous'
	&& observation($pdo, 1002, $C)['edge_id'] === null, 'both are ambiguous with no edge');
$picture = natural_picture($pdo);
$row_order = [nbr($R, 1, $D, 'eth1'), nbr($R, 1, $C, 'eth0')];
snapshot($pdo, 1002, 101, 2, CLOCK + 200, $row_order);
ingest($dsn, $user, $password, $pdo, 'the same rows in the other order');
check(natural_picture($pdo) === $picture, 'the same rows in another order change no link and no observation');

// ============================================================================================================
echo "\n=== 8: order independence — reporters processed in different orders ===\n";
$pictures = [];
foreach (['101,102', '102,101'] as $order) {
	reset_db($pdo);
	two_switches($pdo, $R, $B);
	snapshot($pdo, 1002, 101, 2, CLOCK + 50, [nbr($R, 1, $B, 'Gi0/24')]);
	snapshot($pdo, 2002, 102, 2, CLOCK + 50, [nbr($B, 24, $R, 'Gi0/1')]);
	ingest($dsn, $user, $password, $pdo, "with L, order {$order}", [], $order);
	snapshot($pdo, 1002, 101, 2, CLOCK + 100, [nbr($R, 1, $C, 'eth0')]);
	snapshot($pdo, 2002, 102, 2, CLOCK + 90, [nbr($B, 24, $R, 'Gi0/1')]);
	ingest($dsn, $user, $password, $pdo, "after the recable, order {$order}", [], $order);
	$pictures[$order] = natural_picture($pdo);
}
check($pictures['101,102'] === $pictures['102,101'], 'links, superseded_at and observations are the same for both orders');
check(count($pictures['101,102'][0]) >= 2, '(and the picture is not empty)');

// ============================================================================================================
echo "\n=== 9: convergence — five runs over unchanged snapshots change nothing ===\n";
$before = fingerprint($pdo);
for ($i = 0; $i < 5; $i++) {
	ingest($dsn, $user, $password, $pdo, 'in a rerun');
}
check(fingerprint($pdo) === $before, 'same ids, same last_seen_*, same superseded_at, same observations (no replace/revive flapping)');
reset_db($pdo);
two_switches($pdo, $R, $B);
snapshot($pdo, 1002, 101, 2, CLOCK + 50, [nbr($R, 1, $B, 'Gi0/24')]);
snapshot($pdo, 2002, 102, 2, CLOCK + 50, [nbr($B, 24, $R, 'Gi0/1')]);
ingest($dsn, $user, $password, $pdo, 'with L');
snapshot($pdo, 1002, 101, 2, CLOCK + 100, [nbr($R, 1, $C, 'eth0')]);
snapshot($pdo, 2002, 102, 2, CLOCK + 100, [nbr($B, 24, $R, 'Gi0/1')]);     // a tie: L stays
ingest($dsn, $user, $password, $pdo, 'with a tie');
$before = fingerprint($pdo);
for ($i = 0; $i < 5; $i++) {
	ingest($dsn, $user, $password, $pdo, 'in a rerun of a tie');
}
check(fingerprint($pdo) === $before, 'a conflict is stable too');

// ============================================================================================================
echo "\n=== 10: a run scoped with --zabbix-host never replaces ===\n";
reset_db($pdo);
add_host($pdo, 101, 'SwR', 'Switch R', '10.0.0.1');
snapshot($pdo, 1001, 101, 1, CLOCK, ports_rows($R, [1 => 'Gi0/1']));
snapshot($pdo, 1002, 101, 2, CLOCK + 50, [nbr($R, 1, $B, 'Gi0/24')]);
ingest($dsn, $user, $password, $pdo, 'with L');
$P = ports_of($pdo, $R)['Gi0/1'];
$Q = ports_of($pdo, $B)['Gi0/24'];
snapshot($pdo, 1002, 101, 2, CLOCK + 100, [nbr($R, 1, $C, 'eth0')]);
ingest($dsn, $user, $password, $pdo, 'a scoped run', ['SwR']);
check(is_active(link_between($pdo, $P, $Q)), 'the link stays: a scoped run cannot see the other reporters, so it keeps the old behaviour');
check(observation($pdo, 1002, $C)['outcome'] === 'conflict', 'and the new neighbor is a conflict until an unscoped run decides');

echo "\nAll link replacement tests passed.\n";
