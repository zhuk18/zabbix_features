#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * test_claim_key.php — task 079 §1, topology-1-model.md 2.62 §7.1: the identity of an observation is the claim (itemid, local_port_ref, remote_key). `local_port_ref` is the row's own
 * reference to the port (`ifindex:<n>` or `num:<n>`), the Port (`local_port_id`) is the resolution and may change without a new observation.
 *
 * Usage: test_claim_key.php <PDO DSN> <database user> [password]
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
$C2 = 'c0:00:00:00:00:02';

function reset_all(PDO $pdo): void {
	$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
	foreach (['topo_identity_keys', 'topo_observations', 'topo_edges', 'topo_nodes', 'topo_lld_snapshot', 'host_inventory', 'interface', 'items', 'hosts', 'globalmacro'] as $t) {
		$pdo->exec("DELETE FROM {$t}");
	}
	$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
	$pdo->prepare('INSERT INTO globalmacro (macro, value) VALUES (?, ?)')->execute(['{$TOPOLOGY.LINK.FRESH.SECONDS}', '60']);
}

/** The reporter R101: PORTS rule 1011 (4 ports, or none), NEIGHBORS rule 1012 with the rows and the clock. */
function reporter(PDO $pdo, array $neighbors, int $clock, bool $ports = true): void {
	global $NOW, $C1;
	if ((int) $pdo->query('SELECT COUNT(*) FROM hosts')->fetchColumn() === 0) {
		add_host($pdo, 101, 'R101', 'Reporter 101', '10.0.0.1');
	}
	snapshot($pdo, 1011, 101, 1, $NOW, $ports ? array_map(static fn (int $i): array => ['if_index' => $i, 'name' => 'Gi0/'.$i, 'loc_chassis' => $C1, 'itemids' => [900 + $i]], range(1, 4))
		: [['if_index' => 9, 'name' => 'Gi0/9', 'loc_chassis' => $C1, 'itemids' => [909]]]);
	snapshot($pdo, 1012, 101, 2, $clock, array_map(static fn (array $r): array => $r + ['loc_chassis' => $C1, 'source' => 'lldp'], $neighbors));
}

function row(array $extra): array {
	global $C2;
	return $extra + ['rem_chassis' => $C2, 'rem_chassis_type' => 'macAddress', 'rem_port' => 'p1', 'rem_port_type' => 'interfaceName'];
}

function obs(PDO $pdo): array {
	return $pdo->query('SELECT id, local_port_ref, local_port_id, remote_key, outcome, first_seen, last_seen FROM topo_observations WHERE itemid = 1012 ORDER BY local_port_ref')->fetchAll(PDO::FETCH_ASSOC);
}

echo "\n=== 1. one rule, the same neighbor on two local ports that do not resolve: two observations ===\n";
reset_all($pdo);
reporter($pdo, [row(['loc_port_num' => '91']), row(['loc_port_num' => '92'])], $NOW - OLD);
$r = run_ingest($dsn, $user, $password);
$o1 = obs($pdo);
check(count($o1) === 2 && array_column($o1, 'local_port_ref') === ['num:91', 'num:92'] && array_filter(array_column($o1, 'local_port_id')) === [] && array_unique(array_column($o1, 'outcome')) === ['old_snapshot'],
	'two observations, refs num:91 and num:92, no Port, old_snapshot', $o1);
check(($r['summary']['rows_duplicate_claim']['count'] ?? 0) === 0, 'no row is counted as a duplicate claim (the claim key has the reference)');
run_ingest($dsn, $user, $password);
run_ingest($dsn, $user, $password);
check(obs($pdo) === $o1, 'a third run: the same two rows, the same ids and first_seen');
// the same reference twice is one claim: counted per rule
reset_all($pdo);
reporter($pdo, [row(['loc_port_num' => '91', 'rem_sysname' => 'A']), row(['loc_port_num' => '91', 'rem_sysname' => 'B'])], $NOW - OLD);
$r = run_ingest($dsn, $user, $password);
check(count(obs($pdo)) === 1 && ($r['summary']['rows_duplicate_claim']['by_rule'][1012] ?? 0) === 1, 'two rows with one claim key: one observation, the other counted in rows_duplicate_claim of the rule');

echo "\n=== 2. two rows with different local_port_ref that resolve to one Port: two observations of that Port ===\n";
reset_all($pdo);
reporter($pdo, [row(['if_index' => 1]), row(['loc_port_num' => '77', 'loc_port_id' => 'Gi0/1', 'loc_port_id_type' => 'interfaceName'])], $NOW);
run_ingest($dsn, $user, $password);
$o = obs($pdo);
check(count($o) === 2 && array_column($o, 'local_port_ref') === ['ifindex:1', 'num:77'] && count(array_unique(array_column($o, 'local_port_id'))) === 1 && $o[0]['local_port_id'] !== null,
	'refs ifindex:1 and num:77, both observations of the same Port', $o);
$fp = md5(json_encode(obs($pdo)));
run_ingest($dsn, $user, $password);
check(md5(json_encode(obs($pdo))) === $fp, 'a second run: the same rows');

echo "\n=== 3. the resolution of the Port changes: the row id and first_seen stay ===\n";
reset_all($pdo);
reporter($pdo, [row(['loc_port_num' => '3', 'loc_port_id' => 'Gi0/3', 'loc_port_id_type' => 'interfaceName'])], $NOW - OLD, false);      // the reporter has no port Gi0/3 yet: not resolved
run_ingest($dsn, $user, $password);
$before = obs($pdo);
check(count($before) === 1 && $before[0]['local_port_id'] === null && $before[0]['local_port_ref'] === 'num:3', 'not resolved: one observation without a Port, ref num:3', $before);
// the PORTS snapshot now has Gi0/3 (an UPDATE: a REPLACE of the rule would delete its observations by the foreign key)
$ports = json_encode(array_map(static fn (int $i): array => ['if_index' => $i, 'name' => 'Gi0/'.$i, 'loc_chassis' => $C1, 'itemids' => [900 + $i]], range(1, 4)));
$pdo->prepare('UPDATE topo_lld_snapshot SET rows_json = ?, rows_hash = ? WHERE itemid = 1011')->execute([$ports, md5($ports)]);
run_ingest($dsn, $user, $password);
$after = obs($pdo);
check(count($after) === 1 && $after[0]['id'] === $before[0]['id'] && $after[0]['first_seen'] === $before[0]['first_seen'] && $after[0]['local_port_id'] !== null && $after[0]['local_port_ref'] === 'num:3',
	'resolved: the same row id and first_seen, the Port is set', [$before, $after]);

echo "\n=== 4. old snapshot rows with and without a Port: the same ids after two more runs ===\n";
reset_all($pdo);
reporter($pdo, [row(['if_index' => 1]), row(['loc_port_num' => '91'])], $NOW - OLD);
run_ingest($dsn, $user, $password);
$a = obs($pdo);
run_ingest($dsn, $user, $password);
run_ingest($dsn, $user, $password);
check(count($a) === 2 && obs($pdo) === $a && count(array_filter(array_column($a, 'local_port_id'))) === 1, 'one row with its Port, one without; the same ids, refs and times after two more runs', $a);

echo "\nAll claim key checks passed.\n";
