#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * test_old_snapshot.php — task 078 §1, topology-1-model.md 2.60 §6.2, §7.2, §8.8: a NEIGHBORS snapshot older than the freshness threshold is kept, but its rows are used for no
 * link and no Device: each row is an observation `old_snapshot`. The threshold comes from the macro {$TOPOLOGY.LINK.FRESH.SECONDS} (stand-in table globalmacro) through
 * topology_freshness.inc.php, once per run. The real ingest.php runs as a process; the clocks are relative to the time of the test (an old snapshot is a clock of an hour ago,
 * the threshold is 60 s).
 *
 * Usage: test_old_snapshot.php <PDO DSN> <database user> [password]
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

echo "\n=== the threshold: the macro through the one definition, read once per run ===\n";
reset_all($pdo);
reporter($pdo, 101, $C1, [sees(1, $C2, 'Gi0/1')], $NOW);
foreach ([[null, 604800, false], ['60', 60, false], ['3600', 3600, false], ['30', 604800, true], ['abc', 604800, true], ['<b>1</b>', 604800, true]] as [$raw, $want, $warn]) {
	macro($pdo, $raw);
	$r = run_ingest($dsn, $user, $password);
	check(($r['summary']['fresh_seconds'] ?? null) === $want && (($r['summary']['freshness_warning'] ?? null) !== null) === $warn
		&& (!$warn || (str_contains($r['err'], '{$TOPOLOGY.LINK.FRESH.SECONDS}') && !str_contains($r['err'], '<b>'))),
		'macro '.json_encode($raw).': threshold '.$want.($warn ? ', a warning that names the macro, the text escaped' : ', no warning'), [$r['summary']['fresh_seconds'] ?? null, $r['err']]);
}

echo "\n=== 1. a cable moves, SW2 stops reporting (rule not supported / host not reachable) ===\n";
reset_all($pdo);
macro($pdo, '60');
reporter($pdo, 101, $C1, [sees(1, $C2, 'Gi0/1')], $NOW);
reporter($pdo, 102, $C2, [sees(1, $C1, 'Gi0/1')], $NOW);
reporter($pdo, 103, $C3, [], $NOW);
run_ingest($dsn, $user, $password);
check(active($pdo) === ['a0:00:00:00:00:01/Gi0/1 - a0:00:00:00:00:02/Gi0/1'], 'both report: the link SW1:Gi0/1 - SW2:Gi0/1');
// the cable moves to SW3; SW1 reports it; SW2 does not report any more, but its snapshot is still fresh
snapshot($pdo, 1012, 101, 2, $NOW, [sees(1, $C3, 'Gi0/1') + ['loc_chassis' => $C1, 'source' => 'lldp']]);
snapshot($pdo, 1032, 103, 2, $NOW, [sees(1, $C1, 'Gi0/1') + ['loc_chassis' => $C3, 'source' => 'lldp']]);
$r = run_ingest($dsn, $user, $password);
check(active($pdo) === ['a0:00:00:00:00:01/Gi0/1 - a0:00:00:00:00:02/Gi0/1'] && outcomes($pdo, 1012) === ['conflict'], 'before the threshold: the old link has support (SW2), the new claim is a conflict (as before)', [active($pdo), outcomes($pdo, 1012)]);
$pdo->exec('UPDATE topo_lld_snapshot SET clock = '.($NOW - OLD).' WHERE itemid = 1022');      // SW2's snapshot is now older than the threshold
$r = run_ingest($dsn, $user, $password);
check(active($pdo) === ['a0:00:00:00:00:01/Gi0/1 - a0:00:00:00:00:03/Gi0/1'], 'after the threshold: the old link is superseded, SW1:Gi0/1 - SW3:Gi0/1 is made', active($pdo));
check(outcomes($pdo, 1022) === ['old_snapshot'] && outcomes($pdo, 1012) === ['applied'], 'the rows of the old snapshot are old_snapshot, the new claim is applied');
check(($r['summary']['snapshots_old'] ?? null) === [['hostid' => 102, 'itemid' => 1022, 'clock' => $NOW - OLD, 'allowed_age' => 60]], 'snapshots_old lists the snapshot (hostid, itemid, clock, 2.96: allowed_age; a stand-in of the tables has no interval, so the threshold)', $r['summary']['snapshots_old'] ?? null);
check(count(array_filter($r['summary']['attention'] ?? [], static fn (array $l): bool => $l['kind'] === 'snapshot_old' && $l['hostid'] === 102 && $l['clock'] === $NOW - OLD)) === 1, 'Needs attention has a line for the old snapshot with its time');

echo "\n=== 2. the Device is down, no other neighbor on the port: the link stays ===\n";
reset_all($pdo);
macro($pdo, '60');
reporter($pdo, 101, $C1, [sees(1, $C2, 'Gi0/1')], $NOW);
reporter($pdo, 102, $C2, [sees(1, $C1, 'Gi0/1')], $NOW);
run_ingest($dsn, $user, $password);
$before = links($pdo);
$pdo->exec('UPDATE topo_lld_snapshot SET clock = '.($NOW - OLD).' WHERE itemid IN (1012, 1022)');      // both sides stop reporting
run_ingest($dsn, $user, $password);
$after = links($pdo);
check(count($after) === 1 && !$after[0]['superseded'] && $after[0]['id'] === $before[0]['id'], 'the link is not superseded and not deleted');
check($after[0]['last_seen'] === $before[0]['last_seen'], 'the last_seen of the link does not move (an old snapshot confirms nothing): the link goes stale', [$before[0]['last_seen'], $after[0]['last_seen']]);
check(outcomes($pdo, 1012) === ['old_snapshot'] && outcomes($pdo, 1022) === ['old_snapshot'], 'the observations are old_snapshot');
check((int) $pdo->query('SELECT COUNT(*) FROM topo_observations WHERE outcome = \'old_snapshot\' AND edge_id IS NOT NULL OR device_id IS NOT NULL')->fetchColumn() === 0, 'an old_snapshot observation has no link and no Device');
check((int) $pdo->query("SELECT COUNT(*) FROM topo_nodes WHERE type = 'device'")->fetchColumn() === 2, 'no new Device');

echo "\n=== 4. two runs over the same old snapshot change nothing (G-8) ===\n";
$fp = fingerprint($pdo);
run_ingest($dsn, $user, $password);
run_ingest($dsn, $user, $password);
check(fingerprint($pdo) === $fp, 'row ids, links, last_seen: the same after two more runs');

echo "\n=== a row whose local port is not resolved: the observation has no Port, one row, and it stays one ===\n";
reset_all($pdo);
macro($pdo, '60');
reporter($pdo, 101, $C1, [sees(1, $C2, 'Gi0/1'), ['rem_chassis' => $C3, 'rem_chassis_type' => 'macAddress', 'rem_port' => 'x', 'rem_port_type' => 'interfaceName', 'loc_port_id' => 'Gi0/99', 'loc_port_id_type' => 'interfaceName']], $NOW - OLD);
run_ingest($dsn, $user, $password);
$null = (int) $pdo->query('SELECT COUNT(*) FROM topo_observations WHERE local_port_id IS NULL')->fetchColumn();
check($null === 1 && outcomes($pdo, 1012) === ['old_snapshot'] && (int) $pdo->query('SELECT COUNT(*) FROM topo_observations')->fetchColumn() === 2, 'two old rows: one with its Port, one without');
$fp = fingerprint($pdo);
run_ingest($dsn, $user, $password);
check(fingerprint($pdo) === $fp && (int) $pdo->query('SELECT COUNT(*) FROM topo_observations WHERE local_port_id IS NULL')->fetchColumn() === 1, 'a second run: the same rows (the row without a Port is found again)');

echo "\n=== 3. the rule is disabled, then enabled after the threshold ===\n";
reset_all($pdo);
macro($pdo, '60');
reporter($pdo, 101, $C1, [sees(1, $C2, 'Gi0/1')], $NOW);
reporter($pdo, 102, $C2, [sees(1, $C1, 'Gi0/1')], $NOW);
run_ingest($dsn, $user, $password);
$pdo->exec('UPDATE items SET status = 1 WHERE itemid = 1012');      // disabled: no usable snapshot, its observations go
run_ingest($dsn, $user, $password);
check(outcomes($pdo, 1012) === [], 'a disabled rule: no observation');
$pdo->exec('UPDATE topo_lld_snapshot SET clock = '.($NOW - OLD).' WHERE itemid = 1012');      // it is enabled again after the threshold: the stored snapshot is old
$pdo->exec('UPDATE items SET status = 0 WHERE itemid = 1012');
run_ingest($dsn, $user, $password);
check(outcomes($pdo, 1012) === ['old_snapshot'], 'enabled again: its old snapshot gives old_snapshot until the next poll');
snapshot($pdo, 1012, 101, 2, $NOW, [sees(1, $C2, 'Gi0/1') + ['loc_chassis' => $C1, 'source' => 'lldp']]);      // the next poll
run_ingest($dsn, $user, $password);
check(outcomes($pdo, 1012) === ['applied'] && count(active($pdo)) === 1, 'after the poll: the normal outcome');

echo "\nAll old snapshot checks passed.\n";
