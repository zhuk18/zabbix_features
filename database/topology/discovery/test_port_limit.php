#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * test_port_limit.php — task 085, topology-1-model.md 2.65/2.66 §6.7.7, §7.2, §8.5: a reporter port with more neighbor identities than {$TOPOLOGY.PORT.NEIGHBORS.MAX} (default 32)
 * makes no Device; a row that finds none is an observation `port_limit`; the rows `port_limit` count as Devices of their own in §8.5, so a limited port gets no new link. The real ingest.php
 * runs as a process.
 *
 * Usage: test_port_limit.php <PDO DSN> <database user> [password]
 * Expects a THROWAWAY database.
 */

function fail_test(string $message): void {
	fwrite(STDERR, "FAIL: {$message}\n");
	exit(1);
}

function check(bool $condition, string $description, $detail = null): void {
	if (!$condition) {
		fail_test($description.($detail !== null ? " ".json_encode($detail) : ""));
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
$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
foreach (['topo_identity_keys', 'topo_observations', 'topo_edges', 'topo_nodes', 'topo_lld_snapshot', 'host_inventory', 'interface',
		'items', 'proxy', 'hosts', 'globalmacro'] as $table) {
	$pdo->exec("DROP TABLE IF EXISTS {$table}");
}
$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
$pdo->exec("CREATE TABLE hosts (hostid BIGINT UNSIGNED PRIMARY KEY, host VARCHAR(128) NOT NULL, name VARCHAR(128) NOT NULL DEFAULT '', status INT NOT NULL DEFAULT 0)");
$pdo->exec('CREATE TABLE proxy (proxyid BIGINT UNSIGNED PRIMARY KEY)');
$pdo->exec('CREATE TABLE globalmacro (globalmacroid BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT, macro VARCHAR(255) NOT NULL, value VARCHAR(2048) NOT NULL DEFAULT \'\')');
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

function run_ingest(string $dsn, string $user, string $password, array $extra = [], array $options = [], array $env = []): array {
	$cmd = [PHP_BINARY, __DIR__.'/ingest.php', '--pdo-dsn', $dsn, '--pdo-user', $user, '--pdo-password', $password];
	foreach ($options as $name => $value) {
		array_push($cmd, '--'.$name, $value);
	}
	foreach ($extra as $host) {
		array_push($cmd, '--zabbix-host', $host);
	}
	// A private temp dir keeps the run lock and status file away from any real ingest's.
	$tmp = sys_get_temp_dir().'/topology-snapshot-ingest-test';
	@mkdir($tmp);
	$process = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, ['TMPDIR' => $tmp] + $env);
	$stdout = stream_get_contents($pipes[1]);
	$stderr = stream_get_contents($pipes[2]);
	fclose($pipes[1]);
	fclose($pipes[2]);
	$code = proc_close($process);
	$status = json_decode((string) @file_get_contents($tmp.'/topology-ingest-status.json'), true);
	return ['code' => $code, 'out' => $stdout, 'err' => $stderr, 'summary' => $status['summary'] ?? [], 'status' => $status ?? []];
}

function scalar(PDO $pdo, string $sql, array $params = []) {
	$stmt = $pdo->prepare($sql);
	$stmt->execute($params);
	return $stmt->fetchColumn();
}



const OLD = 3600;
$NOW = time();
$C1 = 'a0:00:00:00:00:01';
$C2 = 'a0:00:00:00:00:02';
$C3 = 'a0:00:00:00:00:03';

function reset_all(PDO $pdo): void {
	$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
	foreach (['topo_identity_keys', 'topo_observations', 'topo_edges', 'topo_nodes', 'topo_lld_snapshot', 'host_inventory', 'interface', 'items', 'hosts', 'globalmacro'] as $t) {
		$pdo->exec("DELETE FROM {$t}");
	}
	$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
}

function macro(PDO $pdo, ?string $value): void {
	$pdo->exec('DELETE FROM globalmacro');
	if ($value !== null) {
		$pdo->prepare('INSERT INTO globalmacro (macro, value) VALUES (?, ?)')->execute(['{$TOPOLOGY.LINK.FRESH.SECONDS}', $value]);
	}
}

/** A reporter with 4 ports: PORTS rule H*10+1 (a fresh clock), NEIGHBORS rule H*10+2 with the given rows and clock. */
function reporter(PDO $pdo, int $hostid, string $chassis, array $neighbors, int $clock): void {
	global $NOW;
	add_host($pdo, $hostid, 'SW'.$hostid, 'Switch '.$hostid, '10.0.0.'.$hostid);
	$ports = array_map(static fn (int $i): array => ['if_index' => $i, 'name' => 'Gi0/'.$i, 'loc_chassis' => $chassis, 'itemids' => [$hostid * 100 + $i]], range(1, 4));
	snapshot($pdo, $hostid * 10 + 1, $hostid, 1, $NOW, $ports);
	snapshot($pdo, $hostid * 10 + 2, $hostid, 2, $clock, array_map(static fn (array $r): array => $r + ['loc_chassis' => $chassis, 'source' => 'lldp'], $neighbors));
}

function sees(int $if, string $chassis, string $port): array {
	return ['if_index' => $if, 'rem_chassis' => $chassis, 'rem_chassis_type' => 'macAddress', 'rem_port' => $port, 'rem_port_type' => 'interfaceName'];
}

function links(PDO $pdo): array {
	$out = [];
	foreach ($pdo->query("SELECT e.id, e.attrs, a.device_id AS da, a.attrs AS pa, b.device_id AS db, b.attrs AS pb FROM topo_edges e JOIN topo_nodes a ON a.id = e.src_id JOIN topo_nodes b ON b.id = e.dst_id WHERE e.type = 'physical_link'")->fetchAll(PDO::FETCH_ASSOC) as $row) {
		$attrs = json_decode($row['attrs'], true);
		$name = static function ($device, $port_attrs) use ($pdo): string {
			$d = json_decode((string) $pdo->query('SELECT attrs FROM topo_nodes WHERE id = '.(int) $device)->fetchColumn(), true);
			return ($d['chassis_id'] ?? '?').'/'.(json_decode($port_attrs, true)['name'] ?? '?');
		};
		$out[] = ['id' => (int) $row['id'], 'ends' => [$name($row['da'], $row['pa']), $name($row['db'], $row['pb'])], 'superseded' => isset($attrs['superseded_at']), 'last_seen' => $attrs['last_seen'] ?? null];
	}
	return $out;
}

function active(PDO $pdo): array {
	$ends = [];
	foreach (links($pdo) as $link) {
		if (!$link['superseded']) {
			sort($link['ends']);
			$ends[] = implode(' - ', $link['ends']);
		}
	}
	sort($ends);
	return $ends;
}

function outcomes(PDO $pdo, int $itemid): array {
	$rows = $pdo->prepare('SELECT outcome FROM topo_observations WHERE itemid = ? ORDER BY id');
	$rows->execute([$itemid]);
	return array_values(array_unique($rows->fetchAll(PDO::FETCH_COLUMN)));
}

function fingerprint(PDO $pdo): string {
	$edges = $pdo->query('SELECT id, src_id, dst_id, attrs FROM topo_edges ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
	$obs = $pdo->query('SELECT id, itemid, local_port_id, remote_key, outcome, edge_id, device_id, first_seen, last_seen FROM topo_observations ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
	$nodes = $pdo->query('SELECT id, type, attrs FROM topo_nodes ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
	return md5(json_encode([$edges, $obs, $nodes]));
}

function limit_macro(PDO $pdo, ?string $value): void {
	$pdo->exec("DELETE FROM globalmacro WHERE macro = '{\$TOPOLOGY.PORT.NEIGHBORS.MAX}'");
	if ($value !== null) {
		$pdo->prepare('INSERT INTO globalmacro (macro, value) VALUES (?, ?)')->execute(['{$TOPOLOGY.PORT.NEIGHBORS.MAX}', $value]);
	}
}

/** $n neighbors on the local port $if, chassis b0:00:00:00:<block>:<i> */
function many(int $if, int $n, int $block = 1, int $from = 1): array {
	$rows = [];
	for ($i = $from; $i < $from + $n; $i++) {
		$rows[] = sees($if, sprintf('b0:00:00:00:%02x:%02x', $block, $i), 'p'.$i);
	}
	return $rows;
}

/** new NEIGHBORS rows of the reporter 101 (rule 1012) */
function nb(PDO $pdo, array $rows): void {
	global $NOW, $C1;
	snapshot($pdo, 1012, 101, 2, $NOW, array_map(static fn (array $r): array => $r + ['loc_chassis' => $C1, 'source' => 'lldp'], $rows));
}

function mac(int $block, int $i): string {
	return sprintf('b0:00:00:00:%02x:%02x', $block, $i);
}

function by_outcome(PDO $pdo, int $itemid): array {
	$q = $pdo->prepare('SELECT outcome, COUNT(*) FROM topo_observations WHERE itemid = ? GROUP BY outcome');
	$q->execute([$itemid]);
	$out = $q->fetchAll(PDO::FETCH_KEY_PAIR);
	ksort($out);
	return $out;
}

function devices(PDO $pdo): int {
	return (int) scalar($pdo, "SELECT COUNT(*) FROM topo_nodes WHERE type = 'device'");
}

function unchanged_second_run(PDO $pdo, string $dsn, string $user, string $password, string $what): void {
	$sql = 'SELECT id, itemid, remote_key, first_seen, outcome, remote_attrs FROM topo_observations ORDER BY id';
	$before = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
	$fp = fingerprint($pdo);
	run_ingest($dsn, $user, $password);
	check($before === $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) && $fp === fingerprint($pdo), $what.': a second run over the same snapshots changes nothing (ids, first_seen, fingerprint)');
}

echo "\n=== 1. 33 different neighbors on one port, none known ===\n";
reset_all($pdo);
reporter($pdo, 101, $C1, many(1, 33), $NOW);
$r = run_ingest($dsn, $user, $password);
check(by_outcome($pdo, 1012) === ['port_limit' => 33], '33 observations port_limit', by_outcome($pdo, 1012));
check(devices($pdo) === 1, 'no Device made for a neighbor (only the reporter itself)', devices($pdo));
check(active($pdo) === [], 'no link');
check(count($r['summary']['ports_neighbor_limit'] ?? []) === 1 && $r['summary']['ports_neighbor_limit'][0]['neighbors'] === 33
	&& $r['summary']['ports_neighbor_limit'][0]['hostid'] == 101 && $r['summary']['ports_neighbor_limit'][0]['local_port_ref'] === 'ifindex:1', 'one entry in ports_neighbor_limit', $r['summary']['ports_neighbor_limit'] ?? null);
check(($r['summary']['observations']['port_limit'] ?? 0) === 33, 'the run summary counts 33 port_limit');
check(count(array_filter($r['summary']['attention'] ?? [], static fn (array $a): bool => $a['kind'] === 'port_neighbor_limit')) === 1, 'one line in Needs attention');
unchanged_second_run($pdo, $dsn, $user, $password, '33 unknown neighbors');

echo "\n=== 2. the same, and one of the 33 is a Device that another port makes (the 2.66 case) ===\n";
reset_all($pdo);
$rows = many(1, 32);
$rows[] = sees(1, mac(9, 9), 'p9');      // the 33rd neighbor
reporter($pdo, 101, $C1, $rows, $NOW);
reporter($pdo, 102, $C2, [sees(2, mac(9, 9), 'Gi0/9')], $NOW);      // another reporter, another port: it makes the Device mac(9,9) in the same run
$r = run_ingest($dsn, $user, $password);
$o = $pdo->query("SELECT outcome, remote_attrs FROM topo_observations WHERE itemid = 1012")->fetchAll(PDO::FETCH_ASSOC);
$of_x = array_values(array_filter($o, static fn (array $x): bool => (json_decode($x['remote_attrs'], true)['rem_chassis'] ?? '') === mac(9, 9)));
check(count($of_x) === 1 && $of_x[0]['outcome'] === 'ambiguous', 'the row of the known Device is ambiguous', array_column($of_x, 'outcome'));
check(by_outcome($pdo, 1012) === ['ambiguous' => 1, 'port_limit' => 32], '32 port_limit and the one ambiguous', by_outcome($pdo, 1012));
check(array_filter(active($pdo), static fn (string $l): bool => str_contains($l, $C1.'/Gi0/1')) === [], 'no link on the limited port', active($pdo));
check(devices($pdo) === 3, 'Devices: the two reporters and the one neighbor of the other port', devices($pdo));
unchanged_second_run($pdo, $dsn, $user, $password, 'a stored Device among the 33');

echo "\n=== 3. an active link on the port, its other end is among the 33 ===\n";
reset_all($pdo);
reporter($pdo, 101, $C1, [sees(1, $C2, 'Gi0/1')], $NOW);
reporter($pdo, 102, $C2, [sees(1, $C1, 'Gi0/1')], $NOW);
run_ingest($dsn, $user, $password);
check(active($pdo) === ['a0:00:00:00:00:01/Gi0/1 - a0:00:00:00:00:02/Gi0/1'], 'the link exists');
nb($pdo, array_merge([sees(1, $C2, 'Gi0/1')], many(1, 32)));
$r = run_ingest($dsn, $user, $password);
check(active($pdo) === ['a0:00:00:00:00:01/Gi0/1 - a0:00:00:00:00:02/Gi0/1'], 'the link stays', active($pdo));
check(by_outcome($pdo, 1012) === ['applied' => 1, 'port_limit' => 32], 'its row is applied, the others port_limit', by_outcome($pdo, 1012));
unchanged_second_run($pdo, $dsn, $user, $password, 'the link among the 33');

echo "\n=== 4. an active link on the port, its other end is not among the 33 ===\n";
reset_all($pdo);
reporter($pdo, 101, $C1, [sees(1, $C2, 'Gi0/1')], $NOW);
reporter($pdo, 102, $C2, [sees(1, $C1, 'Gi0/1')], $NOW);
run_ingest($dsn, $user, $password);
nb($pdo, many(1, 33));
run_ingest($dsn, $user, $password);
check(active($pdo) === ['a0:00:00:00:00:01/Gi0/1 - a0:00:00:00:00:02/Gi0/1'] && by_outcome($pdo, 1012) === ['port_limit' => 33],
	'the far end still reports it: the link stays (supported), 33 port_limit, no new link', [active($pdo), by_outcome($pdo, 1012)]);
snapshot($pdo, 1022, 102, 2, $NOW, []);      // the far end stops reporting it: nothing supports the link any more
run_ingest($dsn, $user, $password);
check(active($pdo) === [] && by_outcome($pdo, 1012) === ['port_limit' => 33], 'with no support the link is superseded, no new link', [active($pdo), by_outcome($pdo, 1012)]);
check(devices($pdo) === 2, 'no Device for the 33');
unchanged_second_run($pdo, $dsn, $user, $password, 'the superseded link');

echo "\n=== 5. 32 neighbors: the normal rules ===\n";
reset_all($pdo);
reporter($pdo, 101, $C1, many(1, 32), $NOW);
$r = run_ingest($dsn, $user, $password);
check(by_outcome($pdo, 1012) === ['ambiguous' => 32] && devices($pdo) === 33 && ($r['summary']['ports_neighbor_limit'] ?? []) === [], '32 Devices made, 32 rows ambiguous (several neighbors), nothing limited', [by_outcome($pdo, 1012), devices($pdo)]);

echo "\n=== 6. 20 rows in an old snapshot and 20 in a fresh one on the same port: only the fresh count ===\n";
reset_all($pdo);
macro($pdo, '60');
reporter($pdo, 101, $C1, many(1, 20, 1), $NOW);
snapshot($pdo, 1013, 101, 2, $NOW - OLD, array_map(static fn (array $x): array => $x + ['loc_chassis' => $C1, 'source' => 'lldp'], many(1, 20, 2)));
$r = run_ingest($dsn, $user, $password);
check(by_outcome($pdo, 1012) === ['ambiguous' => 20] && by_outcome($pdo, 1013) === ['old_snapshot' => 20] && devices($pdo) === 21 && ($r['summary']['ports_neighbor_limit'] ?? []) === [],
	'20 fresh rows are normal (Devices made), the 20 old rows are old_snapshot, the port is not limited', [by_outcome($pdo, 1012), by_outcome($pdo, 1013), devices($pdo)]);
macro($pdo, null);

echo "\n=== 7. the macro ===\n";
reset_all($pdo);
limit_macro($pdo, '10');
reporter($pdo, 101, $C1, array_merge(many(1, 11), many(2, 10, 2)), $NOW);
$r = run_ingest($dsn, $user, $password);
$q = $pdo->prepare('SELECT o.outcome, COUNT(*) FROM topo_observations o JOIN topo_nodes p ON p.id = o.local_port_id WHERE o.itemid = 1012 AND JSON_UNQUOTE(JSON_EXTRACT(p.attrs, \'$.if_index\')) = ? GROUP BY o.outcome');
$q->execute(['1']);
$port1 = $q->fetchAll(PDO::FETCH_KEY_PAIR);
$q->execute(['2']);
$port2 = $q->fetchAll(PDO::FETCH_KEY_PAIR);
check($port1 === ['port_limit' => 11] && $port2 === ['ambiguous' => 10] && count($r['summary']['ports_neighbor_limit'] ?? []) === 1, 'the macro 10: 11 neighbors are limited, 10 are not', [$port1, $port2]);
limit_macro($pdo, 'abc');
$r = run_ingest($dsn, $user, $password);
check(str_contains($r['err'], '{$TOPOLOGY.PORT.NEIGHBORS.MAX}') && count($r['summary']['ports_neighbor_limit'] ?? []) === 0, 'a bad value: the default 32 and a warning that names the macro', $r['err']);
limit_macro($pdo, null);

echo "\nAll port limit checks passed.\n";
