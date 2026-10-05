#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * test_ingest_identity.php — the ingest is a system process (topology-5-access-control.md §2.1, handoff 016 section 1).
 *
 * Starts the REAL ingest.php the way the web server does: no --api-url, no --api-token, no password on the command line; the database
 * settings come through the environment. A legacy (Trapper blob) reporter is found in the database tables (items, hosts, history_text),
 * not with item.get under some user's rights, so a run does not depend on who started it.
 *
 * Usage: test_ingest_identity.php <PDO DSN> <database user> [password]
 * Expects a THROWAWAY database (drops and recreates the topology tables and stand-ins of the Zabbix tables).
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
$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
foreach (['topo_observations', 'topo_edges', 'topo_nodes', 'topo_lld_snapshot', 'host_inventory', 'interface', 'history_text',
		'items', 'proxy', 'hosts'] as $table) {
	$pdo->exec("DROP TABLE IF EXISTS {$table}");
}
$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
$pdo->exec("CREATE TABLE hosts (hostid BIGINT UNSIGNED PRIMARY KEY, host VARCHAR(128) NOT NULL, name VARCHAR(128) NOT NULL DEFAULT '', status INT NOT NULL DEFAULT 0)");
$pdo->exec('CREATE TABLE proxy (proxyid BIGINT UNSIGNED PRIMARY KEY)');
$pdo->exec("CREATE TABLE items (itemid BIGINT UNSIGNED PRIMARY KEY, hostid BIGINT UNSIGNED NOT NULL, key_ VARCHAR(2048) NOT NULL DEFAULT '', status INT NOT NULL DEFAULT 0, topology_role INT NOT NULL DEFAULT 0, type INT NOT NULL DEFAULT 0)");
$pdo->exec('CREATE TABLE history_text (itemid BIGINT UNSIGNED NOT NULL, clock INT NOT NULL DEFAULT 0, value TEXT NOT NULL, ns INT NOT NULL DEFAULT 0)');
$pdo->exec("CREATE TABLE interface (interfaceid BIGINT UNSIGNED PRIMARY KEY, hostid BIGINT UNSIGNED NOT NULL, type INT NOT NULL, useip INT NOT NULL DEFAULT 1, ip VARCHAR(64) NOT NULL DEFAULT '', main INT NOT NULL DEFAULT 1)");
$pdo->exec("CREATE TABLE host_inventory (hostid BIGINT UNSIGNED PRIMARY KEY, macaddress_a VARCHAR(64) NOT NULL DEFAULT '', macaddress_b VARCHAR(64) NOT NULL DEFAULT '')");
$pdo->exec('CREATE TABLE topo_lld_snapshot (itemid BIGINT UNSIGNED PRIMARY KEY, hostid BIGINT UNSIGNED NOT NULL, role INT NOT NULL DEFAULT 0,'.
	' clock INT NOT NULL DEFAULT 0, rows_hash VARCHAR(64) NOT NULL DEFAULT \'\', rows_json LONGTEXT NOT NULL, rows_total INT NOT NULL DEFAULT 0,'.
	' rows_valid INT NOT NULL DEFAULT 0)');
$pdo->exec(file_get_contents(__DIR__.'/../mysql_schema.sql'));

// a real legacy reporter, and a template that carries the same item (a template is not a reporter)
$pdo->exec("INSERT INTO hosts (hostid, host, name, status) VALUES (501, 'legacy-sw', 'legacy-sw', 0), (502, 'Topology Discovery Reporter', 'tpl', 3)");
$pdo->exec("INSERT INTO items (itemid, hostid, key_) VALUES (5001, 501, 'topology.discovery.raw'), (5002, 502, 'topology.discovery.raw')");
$blob = ['reporter' => ['sysname' => 'legacy-sw', 'chassis_id' => '02:aa:00:00:00:01', 'mgmt_ip' => '10.9.9.1', 'vendor' => 'unknown'],
	'ports' => [['if_index' => 1, 'name' => 'Gi0/1', 'if_type' => 'physical', 'mac' => '02:aa:00:00:00:11', 'admin_status' => 'up', 'oper_status' => 'up']],
	'neighbors' => []];
$pdo->prepare('INSERT INTO history_text (itemid, clock, value, ns) VALUES (?, ?, ?, 0)')->execute([5001, time() - 60, '{"stale": true}']);
$pdo->prepare('INSERT INTO history_text (itemid, clock, value, ns) VALUES (?, ?, ?, 0)')->execute([5001, time(), json_encode($blob)]);

$cmd = [PHP_BINARY, __DIR__.'/ingest.php'];
check(!preg_grep('/pdo|api|token|password|'.preg_quote($password !== '' ? $password : 'no-password-set', '/').'/', array_slice($cmd, 1)),
	'the command line carries no credential and no database setting');
$tmp = sys_get_temp_dir().'/topology-ingest-identity-test';
@mkdir($tmp);
$env = ['TMPDIR' => $tmp, 'TOPOLOGY_PDO_DSN' => $dsn, 'TOPOLOGY_PDO_USER' => $user, 'TOPOLOGY_PDO_PASSWORD' => $password,
	'PATH' => (string) getenv('PATH')];
$process = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
$stdout = stream_get_contents($pipes[1]);
$stderr = stream_get_contents($pipes[2]);
$code = proc_close($process);
check($code === 0, "the run succeeds with the settings from the environment only (exit {$code}) {$stderr}");

$device = $pdo->query("SELECT id, attrs FROM topo_nodes WHERE type = 'device'")->fetchAll(PDO::FETCH_ASSOC);
check(count($device) === 1 && (json_decode($device[0]['attrs'], true)['chassis_id'] ?? null) === '02:aa:00:00:00:01',
	'the legacy reporter was found in the database (items + hosts) and its latest history_text value was ingested: one Device');
check((int) $pdo->query("SELECT COUNT(*) FROM topo_nodes WHERE type = 'port'")->fetchColumn() === 1, 'its port was written');
check(!str_contains($stdout.$stderr, 'Topology Discovery Reporter'), 'the item of a template is not a reporter');
echo "\nAll checks passed.\n";
