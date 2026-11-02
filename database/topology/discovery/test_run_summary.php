#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * test_run_summary.php — task 076, topology-1-model.md 2.57 §12.7, §6.11: the run summary. `started_by` (the user name from the environment, else CLI), the status label
 * (done / done with warnings / failed), the list "Needs attention" with the host and the rule of each line, the new duplicate candidates, the text that the CLI prints and its
 * exit code (0 done, 3 done with warnings, 1 failed). CTopologyRunSummary directly, then the real ingest.php as a process.
 *
 * Usage: test_run_summary.php <PDO DSN> <database user> [password]
 * Expects a THROWAWAY database.
 */

require_once __DIR__.'/../../../ui/include/classes/topology/CTopologyRunSummary.php';

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
		'items', 'proxy', 'hosts'] as $table) {
	$pdo->exec("DROP TABLE IF EXISTS {$table}");
}
$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
$pdo->exec("CREATE TABLE hosts (hostid BIGINT UNSIGNED PRIMARY KEY, host VARCHAR(128) NOT NULL, name VARCHAR(128) NOT NULL DEFAULT '', status INT NOT NULL DEFAULT 0)");
$pdo->exec('CREATE TABLE proxy (proxyid BIGINT UNSIGNED PRIMARY KEY)');
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


const CLOCK = 1_800_000_000;

function reset_all(PDO $pdo): void {
	$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
	foreach (['topo_identity_keys', 'topo_observations', 'topo_edges', 'topo_nodes', 'topo_lld_snapshot', 'host_inventory', 'interface', 'items', 'hosts'] as $t) {
		$pdo->exec("DELETE FROM {$t}");
	}
	$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
}

function reporter(PDO $pdo, int $hostid, string $ip, string $chassis, array $rows, array $ports = null): void {
	add_host($pdo, $hostid, 'R'.$hostid, 'Reporter '.$hostid, $ip);
	if ($ports !== false) {
		$ports ??= array_map(static fn (int $i): array => ['if_index' => $i, 'name' => 'Gi0/'.$i, 'loc_chassis' => $chassis, 'itemids' => [900000 + $i]], range(1, 4));
		snapshot($pdo, $hostid * 10 + 1, $hostid, 1, CLOCK, $ports);
	}
	snapshot($pdo, $hostid * 10 + 2, $hostid, 2, CLOCK + 10, array_map(static fn (array $r): array => $r + ['loc_chassis' => $chassis, 'source' => 'lldp'], $rows));
}

$lldp = static fn (int $if, string $mac, string $name): array => ['if_index' => $if, 'rem_chassis' => $mac, 'rem_chassis_type' => 'macAddress', 'rem_sysname' => $name,
	'rem_port' => 'p'.$if, 'rem_port_type' => 'interfaceName'];

echo "\n=== the class: result, exit code, attention, text ===\n";
check(CTopologyRunSummary::result('done', ['attention' => []]) === 'done' && CTopologyRunSummary::result('done', ['attention' => [['kind' => 'x']]]) === 'done_with_warnings'
	&& CTopologyRunSummary::result('error', null) === 'failed' && CTopologyRunSummary::result('running', null) === 'running' && CTopologyRunSummary::result(null, null) === 'idle',
	'the status label of the file: done, done with warnings (Needs attention not empty), failed, running, idle');
check(CTopologyRunSummary::exitCode('done') === 0 && CTopologyRunSummary::exitCode('done_with_warnings') !== 0 && CTopologyRunSummary::exitCode('failed') !== 0
	&& CTopologyRunSummary::exitCode('done_with_warnings') !== CTopologyRunSummary::exitCode('failed'), 'the exit code is 0 only for done');
$lines = CTopologyRunSummary::attention([
	'reporters_skipped_list' => [['hostid' => 5, 'host' => 'H5', 'reason' => 'identity_ambiguous']],
	'reporters_failed' => ['list' => [['hostid' => 6, 'host' => 'H6', 'step' => 'neighbors', 'error' => 'boom']]],
	'rows_local_port_unresolved' => ['by_rule' => [31 => ['no_ports' => 2, 'if_index_missing' => 0]]],
	'rows_no_identity' => ['by_rule' => [32 => 3]],
	'hosts_ports_without_items' => ['list' => [['hostid' => 7, 'host' => 'H7']]]
], [31 => ['hostid' => 8, 'host' => 'H8', 'rule' => 'Rule 31'], 32 => ['hostid' => 9, 'host' => 'H9', 'rule' => 'Rule 32']]);
check(count($lines) === 5 && $lines[2]['kind'] === 'local_port_unresolved' && $lines[2]['reason'] === 'no_ports' && $lines[2]['count'] === 2 && $lines[2]['host'] === 'H8'
	&& $lines[2]['rule'] === 'Rule 31' && $lines[2]['itemid'] === 31 && $lines[3]['count'] === 3 && $lines[3]['hostid'] === 9 && $lines[0]['rule'] === null && $lines[1]['reason'] === 'neighbors: boom',
	'one line for each host and rule with a problem, with the reason and the count', $lines);

echo "\n=== a clean run: exit 0, started_by ===\n";
reset_all($pdo);
reporter($pdo, 101, '10.0.0.1', 'a0:00:00:00:00:01', [$lldp(1, 'c0:00:00:00:00:01', 'N1')]);
$r = run_ingest($dsn, $user, $password);
check($r['code'] === 0 && $r['status']['status'] === 'done' && ($r['summary']['attention'] ?? null) === [], 'done: exit 0, the list "Needs attention" is empty');
check(($r['status']['started_by'] ?? null) === 'CLI', 'started_by is CLI for a run from the command line');
check(str_contains($r['out'], "Run summary\n  status:      done\n") && str_contains($r['out'], 'by CLI') && str_contains($r['out'], 'needs attention: nothing'), 'the CLI prints the run summary as text');
$r = run_ingest($dsn, $user, $password, [], [], ['TOPOLOGY_STARTED_BY' => 'alice']);
check(($r['status']['started_by'] ?? null) === 'alice' && str_contains($r['out'], 'by alice'), 'started_by is the user name that the page passes in the environment');
$status_file = sys_get_temp_dir().'/topology-snapshot-ingest-test/topology-ingest-status.json';
check(CTopologyRunSummary::result($r['status']['status'], $r['summary']) === 'done', 'the label of the file is done');

echo "\n=== duplicate candidates of the run ===\n";
reset_all($pdo);
reporter($pdo, 101, '10.0.0.1', 'a0:00:00:00:00:01', [
	['if_index' => 1, 'rem_sysname' => 'Switch', 'rem_mgmt_ip' => '10.9.9.9', 'source' => 'cdp'], ['if_index' => 2, 'rem_sysname' => 'Switch', 'rem_mgmt_ip' => '10.9.9.9', 'source' => 'cdp']]);
$r = run_ingest($dsn, $user, $password);
check(($r['summary']['duplicate_candidates']['pairs'] ?? 0) === 2 && ($r['summary']['duplicate_candidates']['new'] ?? 0) === 2, 'two Devices of the default name Switch, one address: 2 pairs (same_address, same_name), both new');
$r = run_ingest($dsn, $user, $password);
check(($r['summary']['duplicate_candidates']['pairs'] ?? 0) === 2 && ($r['summary']['duplicate_candidates']['new'] ?? -1) === 0, 'the next run: still 2 pairs, none new');

echo "\n=== done with warnings: exit 3, status done ===\n";
reset_all($pdo);
// rows with no identity (a placeholder chassis and nothing else) and a row with no local port, on one rule
reporter($pdo, 102, '10.0.0.2', 'a0:00:00:00:00:02', [
	$lldp(1, 'c0:00:00:00:00:02', 'N2'),
	['if_index' => 2, 'rem_chassis' => '00:00:00:00:00:00', 'rem_chassis_type' => 'macAddress', 'rem_port' => 'x'],
	['rem_chassis' => 'c0:00:00:00:00:03', 'rem_chassis_type' => 'macAddress', 'rem_sysname' => 'N3', 'rem_port' => 'y']]);
$r = run_ingest($dsn, $user, $password);
$kinds = array_column($r['summary']['attention'] ?? [], 'kind');
check($r['code'] === 3 && $r['status']['status'] === 'done', 'exit 3, the status is done (not an error)');
check(in_array('rows_no_identity', $kinds, true) && in_array('local_port_unresolved', $kinds, true), 'Needs attention has the rows without identity and the rows without a local port', $r['summary']['attention'] ?? null);
$line = array_values(array_filter($r['summary']['attention'], static fn (array $l): bool => $l['kind'] === 'rows_no_identity'))[0] ?? [];
check(($line['itemid'] ?? null) === 1022 && ($line['hostid'] ?? null) === 102 && ($line['count'] ?? 0) === 1, 'each line names the rule and the host', $line);
check(str_contains($r['out'], 'status:      done with warnings') && str_contains($r['out'], 'rows_no_identity'), 'the CLI text says done with warnings and lists the lines');
check(CTopologyRunSummary::result($r['status']['status'], $r['summary']) === 'done_with_warnings', 'the label of the file is done with warnings');

echo "\n=== a skipped reporter: a line with its host ===\n";
reset_all($pdo);
reporter($pdo, 101, '10.0.0.1', 'a0:00:00:00:00:01', [$lldp(1, 'c0:00:00:00:00:09', 'N9')]);
reporter($pdo, 103, '10.0.0.3', 'a0:00:00:00:00:03', [$lldp(1, 'c0:00:00:00:00:08', 'N8')],
	[['if_index' => 1, 'name' => 'Gi0/1', 'loc_chassis' => 'a0:00:00:00:00:03', 'itemids' => [900001]], ['if_index' => 2, 'name' => 'Gi0/2', 'loc_chassis' => 'a0:00:00:00:00:99', 'itemids' => [900002]]]);
$r = run_ingest($dsn, $user, $password);
$skipped = array_values(array_filter($r['summary']['attention'] ?? [], static fn (array $l): bool => $l['kind'] === 'reporter_skipped'));
check($r['code'] === 3 && count($skipped) === 1 && $skipped[0]['hostid'] === 103 && $skipped[0]['host'] === 'R103' && $skipped[0]['reason'] === 'identity_ambiguous',
	'a reporter with two chassis ids is skipped: a line with its host and the reason, exit 3', $r['summary']['attention'] ?? null);

echo "\n=== failed: exit 1 ===\n";
reset_all($pdo);
reporter($pdo, 103, '10.0.0.3', 'a0:00:00:00:00:03', [$lldp(1, 'c0:00:00:00:00:08', 'N8')],
	[['if_index' => 1, 'name' => 'Gi0/1', 'loc_chassis' => 'a0:00:00:00:00:03', 'itemids' => [900001]], ['if_index' => 2, 'name' => 'Gi0/2', 'loc_chassis' => 'a0:00:00:00:00:99', 'itemids' => [900002]]]);
$r = run_ingest($dsn, $user, $password);
check($r['code'] === 1 && $r['status']['status'] === 'error' && str_contains($r['out'], 'status:      failed'), 'no reporter could be ingested: exit 1, the status is failed');

echo "\nAll run summary checks passed.\n";
