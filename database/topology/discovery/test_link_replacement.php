#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * test_link_replacement.php — acceptance tests of topology-link-replacement-spec.md v2 §8.
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
foreach (['older than' => 90, 'equal to' => 100, 'newer than' => 110] as $label => $b_clock) {
	echo "\n=== 2: recable, both ends are reporters — B's latest snapshot is {$label} R's (clock {$b_clock} vs 100) ===\n";
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
	ingest($dsn, $user, $password, $pdo, "with B {$label}");
	$L = link_between($pdo, $P, $Q);
	$obs = observation($pdo, 1002, $C);
	check(is_active($L), 'L stays: B\'s latest snapshot still contains it, whatever its clock');
	check($obs['outcome'] === 'conflict' && (int) $obs['edge_id'] === $L['id'], 'the C observation is a conflict pointing at L');
	check(link_between($pdo, $P, ports_of($pdo, $C)['eth0']) === null, 'no link to C was created');
}
// after B's next snapshot without R:P
snapshot($pdo, 2002, 102, 2, CLOCK + 120, [nbr($B, 23, $D, 'eth1')]);
ingest($dsn, $user, $password, $pdo, 'after B\'s next snapshot without R:P');
$L = link_between($pdo, $P, $Q);
check(($L['superseded_at'] ?? null) === CLOCK + 100, 'B stopped confirming L: the replacement goes through (superseded at 100)');
check(observation($pdo, 1002, $C)['outcome'] === 'applied', 'and the C observation becomes applied');

// ============================================================================================================
foreach (['R no longer shows B' => false, 'R still shows B' => true] as $label => $r_still) {
	echo "\n=== 3: the contradiction comes from the far end — {$label} ===\n";
	reset_db($pdo);
	two_switches($pdo, $R, $B);
	snapshot($pdo, 1002, 101, 2, CLOCK + 50, [nbr($R, 1, $B, 'Gi0/24')]);
	snapshot($pdo, 2002, 102, 2, CLOCK + 50, [nbr($B, 24, $R, 'Gi0/1')]);
	ingest($dsn, $user, $password, $pdo, 'with L');
	$P = ports_of($pdo, $R)['Gi0/1'];
	$Q = ports_of($pdo, $B)['Gi0/24'];
	snapshot($pdo, 1002, 101, 2, CLOCK + 100, $r_still ? [nbr($R, 1, $B, 'Gi0/24')] : []);
	snapshot($pdo, 2002, 102, 2, CLOCK + 110, [nbr($B, 24, $D, 'eth1')]);
	ingest($dsn, $user, $password, $pdo, 'after B sees D');
	if ($r_still) {
		check(is_active(link_between($pdo, $P, $Q)), 'L stays: R\'s latest snapshot still contains it');
		check(observation($pdo, 2002, $D)['outcome'] === 'conflict', 'the D observation is a conflict');
	}
	else {
		check((link_between($pdo, $P, $Q)['superseded_at'] ?? null) === CLOCK + 110, 'L is superseded at B\'s clock');
		check(is_active(link_between($pdo, $Q, ports_of($pdo, $D)['eth1'])), 'Q<->D is created');
	}
}

// ============================================================================================================
foreach (['supported' => true, 'unsupported' => false] as $label => $supported) {
	echo "\n=== 4: far-end port occupancy — the link on X's port is {$label} ===\n";
	reset_db($pdo);
	$E = 'e0:00:00:00:00:05';
	$X = 'f0:00:00:00:00:06';
	add_host($pdo, 101, 'SwR', 'Switch R', '10.0.0.1');
	add_host($pdo, 105, 'SwE', 'Switch E', '10.0.0.5');
	snapshot($pdo, 1001, 101, 1, CLOCK, ports_rows($R, [1 => 'Gi0/1']));
	snapshot($pdo, 5001, 105, 1, CLOCK, ports_rows($E, [1 => 'Gi0/1']));
	snapshot($pdo, 5002, 105, 2, CLOCK + 50, [nbr($E, 1, $X, 'eth0')]);           // M = E:1 <-> X:eth0
	ingest($dsn, $user, $password, $pdo, 'with M');
	$Xp = ports_of($pdo, $X)['eth0'];
	$Ep = ports_of($pdo, $E)['Gi0/1'];
	check(is_active(link_between($pdo, $Ep, $Xp)), 'M = E:1<->X:eth0 is active');
	if (!$supported) {
		snapshot($pdo, 5002, 105, 2, CLOCK + 60, []);                             // E no longer reports it
	}
	snapshot($pdo, 1002, 101, 2, CLOCK + 100, [nbr($R, 1, $X, 'eth0')]);          // R says X:eth0 is on its port
	ingest($dsn, $user, $password, $pdo, 'with R claiming X:eth0');
	$M = link_between($pdo, $Ep, $Xp);
	$RP = ports_of($pdo, $R)['Gi0/1'];
	$obs = observation($pdo, 1002, $X);
	if ($supported) {
		check(is_active($M) && link_between($pdo, $RP, $Xp) === null, 'M stays and R:P<->X is not created');
		check($obs['outcome'] === 'conflict' && (int) $obs['edge_id'] === $M['id'], 'the candidate is a conflict pointing at M');
	}
	else {
		check(($M['superseded_at'] ?? null) === CLOCK + 100, 'M is superseded');
		check(is_active(link_between($pdo, $RP, $Xp)) && $obs['outcome'] === 'applied', 'R:P<->X is created and applied');
	}
}

// ============================================================================================================
echo "\n=== 5: persistent disagreement, staggered polls ===\n";
foreach (['no link before' => false, 'a link before' => true] as $label => $with_link) {
	echo "  -- {$label}\n";
	reset_db($pdo);
	$X = 'e0:00:00:00:00:05';
	$U = 'f0:00:00:00:00:06';
	add_host($pdo, 101, 'SwR', 'Switch R', '10.0.0.1');
	add_host($pdo, 105, 'SwX', 'Switch X', '10.0.0.5');
	snapshot($pdo, 1001, 101, 1, CLOCK, ports_rows($R, [1 => 'Gi0/1']));
	snapshot($pdo, 5001, 105, 1, CLOCK, ports_rows($X, [5 => 'Gi0/5']));
	if ($with_link) {
		snapshot($pdo, 1002, 101, 2, CLOCK + 1, [nbr($R, 1, $X, 'Gi0/5')]);
		snapshot($pdo, 5002, 105, 2, CLOCK + 1, [nbr($X, 5, $R, 'Gi0/1')]);
		ingest($dsn, $user, $password, $pdo, 'with the consistent link');
	}
	// from now on R keeps saying "X on P" and X keeps saying "UPS2 on q"; the polls interleave
	$pictures = [];
	for ($run = 1; $run <= 6; $run++) {
		if ($run % 2 === 1) {
			snapshot($pdo, 1002, 101, 2, CLOCK + 100 + $run, [nbr($R, 1, $X, 'Gi0/5')]);
		}
		else {
			snapshot($pdo, 5002, 105, 2, CLOCK + 100 + $run, [nbr($X, 5, $U, 'eth0')]);
		}
		if ($run === 1) {
			snapshot($pdo, 5002, 105, 2, CLOCK + 100, [nbr($X, 5, $U, 'eth0')]);
		}
		$r = ingest($dsn, $user, $password, $pdo, "in run {$run}");
		$pictures[$run] = natural_picture($pdo);
		if ($run > 1) {
			check((int) $r['summary']['links_superseded'] === 0 && (int) $r['summary']['links_revived'] === 0,
				"run {$run}: no link superseded or revived");
		}
	}
	check(count(array_unique(array_map('json_encode', array_slice($pictures, 1)))) === 1, 'one stable picture across all six runs');
	if ($with_link) {
		check((int) scalar($pdo, "SELECT COUNT(*) FROM topo_observations WHERE outcome='conflict'") >= 1,
			'the disagreement over an active link is a conflict');
	}
	else {
		check((int) scalar($pdo, "SELECT COUNT(*) FROM topo_observations WHERE outcome='conflict'") === 0
			&& (int) scalar($pdo, "SELECT COUNT(*) FROM topo_observations WHERE outcome='ambiguous' AND edge_id IS NULL") === 2
			&& (int) scalar($pdo, "SELECT COUNT(*) FROM topo_edges") === 0,
			'without an active link the claimants are ambiguous with no edge, and no link exists');
	}
}

// ============================================================================================================
echo "\n=== 6: LLDP and CDP of one reporter disagree ===\n";
reset_db($pdo);
add_host($pdo, 101, 'SwR', 'Switch R', '10.0.0.1');
snapshot($pdo, 1001, 101, 1, CLOCK, ports_rows($R, [1 => 'Gi0/1']));
snapshot($pdo, 1002, 101, 2, CLOCK + 50, [nbr($R, 1, $B, 'Gi0/24')]);
ingest($dsn, $user, $password, $pdo, 'with L');
$P = ports_of($pdo, $R)['Gi0/1'];
$Q = ports_of($pdo, $B)['Gi0/24'];
snapshot($pdo, 1002, 101, 2, CLOCK + 300, [nbr($R, 1, $C, 'eth0')]);                 // the LLDP rule, much newer
snapshot($pdo, 1003, 101, 2, CLOCK + 100, [nbr($R, 1, $B, 'Gi0/24', 'cdp')]);        // the CDP rule
ingest($dsn, $user, $password, $pdo, 'LLDP vs CDP');
$L = link_between($pdo, $P, $Q);
check(is_active($L), 'L stays: the CDP rule still contains it');
$obs = observation($pdo, 1002, $C);
check($obs['outcome'] === 'conflict' && (int) $obs['edge_id'] === $L['id'], 'the LLDP observation is a conflict pointing at L');
check(observation($pdo, 1003, $B)['outcome'] === 'applied', 'the CDP observation confirming L is applied');

// ============================================================================================================
echo "\n=== 7: revival — with a holder, then without ===\n";
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
check(isset(link_between($pdo, $P, $Q)['superseded_at']) && is_active(link_between($pdo, $P, $PC)), 'L replaced by P<->C');
snapshot($pdo, 2002, 102, 2, CLOCK + 300, [nbr($B, 24, $R, 'Gi0/1')]);              // B reports R:P again, R still says C
$before = fingerprint($pdo);
$r = ingest($dsn, $user, $password, $pdo, 'B reports R:P again while R still reports C');
check(isset(link_between($pdo, $P, $Q)['superseded_at']) && is_active(link_between($pdo, $P, $PC)), 'the holder has support: L stays superseded');
check(observation($pdo, 2002, $R)['outcome'] === 'conflict', 'B\'s observation is a conflict');
check((int) $r['summary']['links_revived'] === 0 && (int) $r['summary']['links_superseded'] === 0, 'nothing revived, nothing superseded');
snapshot($pdo, 1002, 101, 2, CLOCK + 400, []);                                       // R stops reporting C
$r = ingest($dsn, $user, $password, $pdo, 'after R stops reporting C');
check(is_active(link_between($pdo, $P, $Q)), 'L is revived (superseded_at cleared)');
check((link_between($pdo, $P, $PC)['superseded_at'] ?? null) === CLOCK + 300, 'the link that held the port is superseded');
check((int) $r['summary']['links_revived'] === 1 && (int) $r['summary']['links_superseded'] === 1, 'revived and superseded are counted');

// ============================================================================================================
echo "\n=== 8: a manual link wins ===\n";
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
echo "\n=== 9: two neighbors on one port in one snapshot ===\n";
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
snapshot($pdo, 1002, 101, 2, CLOCK + 200, [nbr($R, 1, $D, 'eth1'), nbr($R, 1, $C, 'eth0')]);
ingest($dsn, $user, $password, $pdo, 'the same rows in the other order');
check(natural_picture($pdo) === $picture, 'the same rows in another order change no link and no observation');

// ============================================================================================================
echo "\n=== 10: a partial run never replaces ===\n";
reset_db($pdo);
add_host($pdo, 101, 'SwR', 'Switch R', '10.0.0.1');
snapshot($pdo, 1001, 101, 1, CLOCK, ports_rows($R, [1 => 'Gi0/1']));
snapshot($pdo, 1002, 101, 2, CLOCK + 50, [nbr($R, 1, $B, 'Gi0/24')]);
ingest($dsn, $user, $password, $pdo, 'with L');
$P = ports_of($pdo, $R)['Gi0/1'];
$Q = ports_of($pdo, $B)['Gi0/24'];
snapshot($pdo, 1002, 101, 2, CLOCK + 100, [nbr($R, 1, $C, 'eth0')]);
$r = ingest($dsn, $user, $password, $pdo, 'a scoped run', ['SwR']);
check(is_active(link_between($pdo, $P, $Q)) && (int) $r['summary']['links_superseded'] === 0, 'nothing is superseded by a scoped run');
check(observation($pdo, 1002, $C)['outcome'] === 'conflict', 'the unsupported contradiction is a conflict');
ingest($dsn, $user, $password, $pdo, 'the full run after it');
check(isset(link_between($pdo, $P, $Q)['superseded_at']), 'the full run supersedes it');

// ============================================================================================================
echo "\n=== 11: order independence — reporters processed in different orders ===\n";
$pictures = [];
foreach (['101,102', '102,101'] as $order) {
	reset_db($pdo);
	two_switches($pdo, $R, $B);
	snapshot($pdo, 1002, 101, 2, CLOCK + 50, [nbr($R, 1, $B, 'Gi0/24')]);
	snapshot($pdo, 2002, 102, 2, CLOCK + 50, [nbr($B, 24, $R, 'Gi0/1')]);
	ingest($dsn, $user, $password, $pdo, "with L, order {$order}", [], $order);
	snapshot($pdo, 1002, 101, 2, CLOCK + 100, [nbr($R, 1, $C, 'eth0')]);
	snapshot($pdo, 2002, 102, 2, CLOCK + 90, [nbr($B, 24, $R, 'Gi0/1')]);
	ingest($dsn, $user, $password, $pdo, "with the conflict, order {$order}", [], $order);
	$pictures[$order] = natural_picture($pdo);
	snapshot($pdo, 2002, 102, 2, CLOCK + 120, [nbr($B, 23, $D, 'eth1')]);
	ingest($dsn, $user, $password, $pdo, "after the replacement, order {$order}", [], $order);
	$pictures[$order.' replaced'] = natural_picture($pdo);
}
check($pictures['101,102'] === $pictures['102,101'], 'links, superseded_at and observations are the same for both orders (conflict)');
check($pictures['101,102 replaced'] === $pictures['102,101 replaced'], 'and after the replacement');
check(count($pictures['101,102 replaced'][0]) >= 2, '(and the picture is not empty)');

// ============================================================================================================
echo "\n=== 12: convergence — five runs over unchanged snapshots change nothing ===\n";
$before = fingerprint($pdo);
for ($i = 0; $i < 5; $i++) {
	ingest($dsn, $user, $password, $pdo, 'in a rerun of a replacement');
}
check(fingerprint($pdo) === $before, 'a replacement is stable: same ids, last_seen_*, superseded_at, observations');
reset_db($pdo);
two_switches($pdo, $R, $B);
snapshot($pdo, 1002, 101, 2, CLOCK + 50, [nbr($R, 1, $B, 'Gi0/24')]);
snapshot($pdo, 2002, 102, 2, CLOCK + 50, [nbr($B, 24, $R, 'Gi0/1')]);
ingest($dsn, $user, $password, $pdo, 'with L');
snapshot($pdo, 1002, 101, 2, CLOCK + 100, [nbr($R, 1, $C, 'eth0')]);
snapshot($pdo, 2002, 102, 2, CLOCK + 100, [nbr($B, 24, $R, 'Gi0/1')]);
ingest($dsn, $user, $password, $pdo, 'with a conflict');
$before = fingerprint($pdo);
for ($i = 0; $i < 5; $i++) {
	ingest($dsn, $user, $password, $pdo, 'in a rerun of a conflict');
}
check(fingerprint($pdo) === $before, 'a conflict is stable too');

// ============================================================================================================
echo "\n=== 13: legacy data — two active discovered links on one port ===\n";
reset_db($pdo);
add_host($pdo, 101, 'SwR', 'Switch R', '10.0.0.1');
snapshot($pdo, 1001, 101, 1, CLOCK, ports_rows($R, [1 => 'Gi0/1']));
snapshot($pdo, 1002, 101, 2, CLOCK + 50, [nbr($R, 1, $B, 'Gi0/24')]);
ingest($dsn, $user, $password, $pdo, 'to create R:P<->B');
$P = ports_of($pdo, $R)['Gi0/1'];
$Q = ports_of($pdo, $B)['Gi0/24'];
$raw_port = static function (PDO $pdo, string $chassis, string $name) : int {
	$pdo->prepare("INSERT INTO topo_nodes (type, attrs, created_at, updated_at) VALUES ('device', ?, 1, 1)")
		->execute([json_encode(['chassis_id' => $chassis, 'sysname' => $chassis, 'mac' => null, 'mgmt_ip' => null, 'vendor' => 'unknown', 'last_seen' => 1])]);
	$device = (int) $pdo->lastInsertId();
	$pdo->prepare("INSERT INTO topo_nodes (type, device_id, attrs, created_at, updated_at) VALUES ('port', ?, ?, 1, 1)")
		->execute([$device, json_encode(['if_index' => 1, 'name' => $name, 'if_type' => 'physical', 'pseudo' => false, 'learned_macs' => [], 'zabbix_itemids' => []])]);
	return (int) $pdo->lastInsertId();
};
$raw_link = static function (PDO $pdo, int $a, int $b, int $seen): void {
	$pdo->prepare("INSERT INTO topo_edges (type, src_id, dst_id, attrs, created_at) VALUES ('physical_link', ?, ?, ?, 1)")
		->execute([min($a, $b), max($a, $b), json_encode(['discovered_via' => 'lldp', 'last_seen' => $seen, 'last_seen_src' => $seen])]);
};
// (a) P has the supported link to B (from R's snapshot) and an old one to a device nobody reports any more
$stale_peer = $raw_port($pdo, 'aa:00:00:00:00:0a', 'x1');
$raw_link($pdo, $P, $stale_peer, CLOCK + 900);          // more recent last_seen, but no snapshot contains it
// (b) a port with two links and no candidate anywhere near it
$Z = $raw_port($pdo, 'bb:00:00:00:00:0b', 'z1');
$W1 = $raw_port($pdo, 'cc:00:00:00:00:0c', 'w1');
$W2 = $raw_port($pdo, 'dd:00:00:00:00:0d', 'w2');
$raw_link($pdo, $Z, $W1, 10);
$raw_link($pdo, $Z, $W2, 20);
check(active_link_count($pdo, $P) === 2 && active_link_count($pdo, $Z) === 2, 'fixture: two active links on P and on Z');
$r = ingest($dsn, $user, $password, $pdo, 'in a scoped run over legacy data', ['SwR'], null, false);
check(active_link_count($pdo, $P) === 2, 'a partial run leaves legacy data alone');
$r = ingest($dsn, $user, $password, $pdo, 'in the first full run over legacy data');
check(active_link_count($pdo, $P) === 1 && is_active(link_between($pdo, $P, $Q)), 'P keeps the link that a snapshot still reports');
check(isset(link_between($pdo, $P, $stale_peer)['superseded_at']), 'and the unreported one is superseded');
check(active_link_count($pdo, $Z) === 1 && is_active(link_between($pdo, $Z, $W2)) && isset(link_between($pdo, $Z, $W1)['superseded_at']),
	'a port with no candidate at all keeps its most recently confirmed link');
check((int) $r['summary']['links_superseded'] === 2, 'both cleanups are counted');
$before = fingerprint($pdo);
ingest($dsn, $user, $password, $pdo, 'in the second full run');
check(fingerprint($pdo) === $before, 'and the cleanup is one-time');

// ============================================================================================================
echo "\n=== 14: competing new claims on a port without an active link ===\n";
reset_db($pdo);
$X = 'e0:00:00:00:00:05';
$Y = 'f0:00:00:00:00:06';
add_host($pdo, 105, 'SwX', 'Switch X', '10.0.0.5');
add_host($pdo, 106, 'SwY', 'Switch Y', '10.0.0.6');
snapshot($pdo, 5001, 105, 1, CLOCK, ports_rows($X, [1 => 'Gi0/1']));
snapshot($pdo, 6001, 106, 1, CLOCK, ports_rows($Y, [1 => 'Gi0/1']));
snapshot($pdo, 5002, 105, 2, CLOCK + 50, [nbr($X, 1, $R, 'Gi0/1')]);        // X and Y both report R:Gi0/1
snapshot($pdo, 6002, 106, 2, CLOCK + 60, [nbr($Y, 1, $R, 'Gi0/1')]);        // R is not a reporter
ingest($dsn, $user, $password, $pdo, 'with X and Y claiming R:P');
$RP = ports_of($pdo, $R)['Gi0/1'];
check((int) scalar($pdo, "SELECT COUNT(*) FROM topo_edges WHERE src_id=? OR dst_id=?", [$RP, $RP]) === 0, 'no link on R:P');
$ox = observation($pdo, 5002, $R);
$oy = observation($pdo, 6002, $R);
check($ox['outcome'] === 'ambiguous' && $oy['outcome'] === 'ambiguous' && $ox['edge_id'] === null && $oy['edge_id'] === null,
	'both observations are ambiguous, without an edge');
$picture = natural_picture($pdo);
for ($i = 0; $i < 3; $i++) {
	ingest($dsn, $user, $password, $pdo, 'in a rerun');
}
check(natural_picture($pdo) === $picture, 'stable over reruns');
snapshot($pdo, 6002, 106, 2, CLOCK + 100, []);                                 // Y stops reporting R:P
ingest($dsn, $user, $password, $pdo, 'after Y stops reporting');
$XP = ports_of($pdo, $X)['Gi0/1'];
$link = link_between($pdo, $XP, $RP);
check(is_active($link) && observation($pdo, 5002, $R)['outcome'] === 'applied', 'X<->R:P is created and applied');

echo "\n=== 14b: LLDP against CDP on a port without a link ===\n";
reset_db($pdo);
add_host($pdo, 101, 'SwR', 'Switch R', '10.0.0.1');
snapshot($pdo, 1001, 101, 1, CLOCK, ports_rows($R, [1 => 'Gi0/1']));
snapshot($pdo, 1002, 101, 2, CLOCK + 50, [nbr($R, 1, $B, 'Gi0/24')]);                       // LLDP: B
snapshot($pdo, 1003, 101, 2, CLOCK + 50, [nbr($R, 1, $C, 'eth0', 'cdp')]);                  // CDP: C
ingest($dsn, $user, $password, $pdo, 'with LLDP and CDP disagreeing');
$P = ports_of($pdo, $R)['Gi0/1'];
check((int) scalar($pdo, "SELECT COUNT(*) FROM topo_edges WHERE src_id=? OR dst_id=?", [$P, $P]) === 0, 'no link on R:P');
check(observation($pdo, 1002, $B)['outcome'] === 'ambiguous' && observation($pdo, 1003, $C)['outcome'] === 'ambiguous'
	&& observation($pdo, 1002, $B)['edge_id'] === null, 'both are ambiguous, without an edge (not conflict)');
snapshot($pdo, 1003, 101, 2, CLOCK + 80, []);                                                 // CDP stops showing C
ingest($dsn, $user, $password, $pdo, 'after CDP stops showing C');
check(is_active(link_between($pdo, $P, ports_of($pdo, $B)['Gi0/24'])) && observation($pdo, 1002, $B)['outcome'] === 'applied',
	'the link appears when one claimant goes away');
$fp = fingerprint($pdo);
ingest($dsn, $user, $password, $pdo, 'in a rerun');
check(fingerprint($pdo) === $fp, 'and stays');

// ============================================================================================================
echo "\n=== 15: legacy data (c) — an active discovered link sharing a port with a manual link ===\n";
reset_db($pdo);
add_host($pdo, 101, 'SwR', 'Switch R', '10.0.0.1');
snapshot($pdo, 1001, 101, 1, CLOCK, ports_rows($R, [1 => 'Gi0/1']));
snapshot($pdo, 1002, 101, 2, CLOCK + 50, [nbr($R, 1, $B, 'Gi0/24')]);
ingest($dsn, $user, $password, $pdo, 'to create R:P<->B');
$P = ports_of($pdo, $R)['Gi0/1'];
$Q = ports_of($pdo, $B)['Gi0/24'];
$raw_port = static function (PDO $pdo, string $chassis, string $name): int {
	$pdo->prepare("INSERT INTO topo_nodes (type, attrs, created_at, updated_at) VALUES ('device', ?, 1, 1)")
		->execute([json_encode(['chassis_id' => $chassis, 'sysname' => $chassis, 'mac' => null, 'mgmt_ip' => null, 'vendor' => 'unknown', 'last_seen' => 1])]);
	$device = (int) $pdo->lastInsertId();
	$pdo->prepare("INSERT INTO topo_nodes (type, device_id, attrs, created_at, updated_at) VALUES ('port', ?, ?, 1, 1)")
		->execute([$device, json_encode(['if_index' => 1, 'name' => $name, 'if_type' => 'physical', 'pseudo' => false, 'learned_macs' => [], 'zabbix_itemids' => []])]);
	return (int) $pdo->lastInsertId();
};
$raw_link = static function (PDO $pdo, int $a, int $b, string $via, int $seen): int {
	$pdo->prepare("INSERT INTO topo_edges (type, src_id, dst_id, attrs, created_at) VALUES ('physical_link', ?, ?, ?, 1)")
		->execute([min($a, $b), max($a, $b), json_encode(['discovered_via' => $via, 'last_seen' => $seen])]);
	return (int) $pdo->lastInsertId();
};
$M = $raw_port($pdo, 'aa:00:00:00:00:0a', 'm1');
$manual_id = $raw_link($pdo, $P, $M, 'manual', 1);                       // manual next to the reported discovered link
$Z = $raw_port($pdo, 'bb:00:00:00:00:0b', 'z1');                         // (no candidate anywhere near it)
$W3 = $raw_port($pdo, 'cc:00:00:00:00:0c', 'w3');
$W4 = $raw_port($pdo, 'dd:00:00:00:00:0d', 'w4');
$manual_z = $raw_link($pdo, $Z, $W3, 'manual', 1);
$raw_link($pdo, $Z, $W4, 'lldp', 500);
check(active_link_count($pdo, $P) === 2 && active_link_count($pdo, $Z) === 2, 'fixture: a manual and a discovered link on P and on Z');
$r = ingest($dsn, $user, $password, $pdo, 'in a scoped run', ['SwR'], null, false);
check(active_link_count($pdo, $P) === 2, 'a partial run leaves it alone');
$r = ingest($dsn, $user, $password, $pdo, 'in the first full run');
check(active_link_count($pdo, $P) === 1 && isset(link_between($pdo, $P, $Q)['superseded_at']), 'the discovered link on P is superseded');
check(($m = link_between($pdo, $P, $M)) && $m['discovered_via'] === 'manual' && is_active($m), 'the manual link is untouched');
check(active_link_count($pdo, $Z) === 1 && isset(link_between($pdo, $Z, $W4)['superseded_at'])
	&& is_active(link_between($pdo, $Z, $W3)), 'and so is the one on Z, which no candidate touches');
check((int) $r['summary']['links_superseded'] === 2, 'both are counted');
$obs = observation($pdo, 1002, $B);
check($obs['outcome'] === 'shadowed' && (int) $obs['edge_id'] === $manual_id, 'the neighbor that is still reported is shadowed by the manual link');
$fp = fingerprint($pdo);
ingest($dsn, $user, $password, $pdo, 'in the second run');
check(fingerprint($pdo) === $fp, 'a second run changes nothing');

echo "\nAll link replacement tests passed.\n";
