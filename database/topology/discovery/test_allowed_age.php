#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * test_allowed_age.php — task 113, topology-1-model.md 2.96 §6.2, §8.8, FR-B D-55: the allowed age of a snapshot. max(3 x base, 600 s), never longer than the freshness threshold; the base is
 * the update interval of the rule, for a dependent rule the one of its first master that is not dependent, or the heartbeat when the rule or that master discards unchanged values with a
 * heartbeat; user macros are resolved on the host (own, template, global); a trapper, an interval of 0 or one that does not resolve: the threshold. snapshots_old gives allowed_age.
 * The real ingest.php runs as a subprocess over the real tables of the Zabbix configuration (as far as they matter here).
 *
 * Usage: test_allowed_age.php <PDO DSN> <database user> [password]
 * Expects a THROWAWAY database.
 */
function fail_test(string $message): void {
	fwrite(STDERR, "FAIL: {$message}\n");
	exit(1);
}

function check(bool $condition, string $description, $detail = null): void {
	if (!$condition) {
		fail_test($description.($detail === null ? '' : ' '.json_encode($detail)));
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
foreach (['topo_identity_keys', 'topo_observations', 'topo_edges', 'topo_nodes', 'topo_lld_snapshot', 'host_inventory', 'interface', 'items', 'proxy', 'hosts', 'globalmacro',
		'hostmacro', 'hosts_templates', 'item_preproc'] as $table) {
	$pdo->exec("DROP TABLE IF EXISTS {$table}");
}
$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
$pdo->exec("CREATE TABLE hosts (hostid BIGINT UNSIGNED PRIMARY KEY, host VARCHAR(128) NOT NULL, name VARCHAR(128) NOT NULL DEFAULT '', status INT NOT NULL DEFAULT 0)");
$pdo->exec('CREATE TABLE proxy (proxyid BIGINT UNSIGNED PRIMARY KEY)');
$pdo->exec('CREATE TABLE globalmacro (globalmacroid BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT, macro VARCHAR(255) NOT NULL, value VARCHAR(2048) NOT NULL DEFAULT \'\', type INT NOT NULL DEFAULT 0)');
$pdo->exec('CREATE TABLE hostmacro (hostmacroid BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT, hostid BIGINT UNSIGNED NOT NULL, macro VARCHAR(255) NOT NULL, value VARCHAR(2048) NOT NULL DEFAULT \'\', type INT NOT NULL DEFAULT 0)');
$pdo->exec('CREATE TABLE hosts_templates (hosttemplateid BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT, hostid BIGINT UNSIGNED NOT NULL, templateid BIGINT UNSIGNED NOT NULL)');
$pdo->exec('CREATE TABLE item_preproc (item_preprocid BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT, itemid BIGINT UNSIGNED NOT NULL, step INT NOT NULL DEFAULT 1, type INT NOT NULL, params TEXT)');
$pdo->exec('CREATE TABLE items (itemid BIGINT UNSIGNED PRIMARY KEY, hostid BIGINT UNSIGNED NOT NULL, status INT NOT NULL DEFAULT 0, topology_type INT NOT NULL DEFAULT 0, type INT NOT NULL DEFAULT 0,'.
	' delay VARCHAR(1024) NOT NULL DEFAULT \'0\', master_itemid BIGINT UNSIGNED NULL)');
$pdo->exec("CREATE TABLE interface (interfaceid BIGINT UNSIGNED PRIMARY KEY, hostid BIGINT UNSIGNED NOT NULL, type INT NOT NULL, useip INT NOT NULL DEFAULT 1, ip VARCHAR(64) NOT NULL DEFAULT '', main INT NOT NULL DEFAULT 1)");
$pdo->exec("CREATE TABLE host_inventory (hostid BIGINT UNSIGNED PRIMARY KEY, macaddress_a VARCHAR(64) NOT NULL DEFAULT '', macaddress_b VARCHAR(64) NOT NULL DEFAULT '')");
$pdo->exec('CREATE TABLE topo_lld_snapshot (itemid BIGINT UNSIGNED PRIMARY KEY, hostid BIGINT UNSIGNED NOT NULL, topology_type INT NOT NULL DEFAULT 0,'.
	' clock INT NOT NULL DEFAULT 0, rows_hash VARCHAR(64) NOT NULL DEFAULT \'\', rows_json LONGTEXT NOT NULL, rows_total INT NOT NULL DEFAULT 0,'.
	' rows_valid INT NOT NULL DEFAULT 0)');
$pdo->exec(file_get_contents(__DIR__.'/../mysql_schema.sql'));


function scalar(PDO $pdo, string $sql, array $params = []) {
	$stmt = $pdo->prepare($sql);
	$stmt->execute($params);
	return $stmt->fetchColumn();
}

function run_ingest(string $dsn, string $user, string $password): array {
	$cmd = [PHP_BINARY, __DIR__.'/ingest.php', '--pdo-dsn', $dsn, '--pdo-user', $user, '--pdo-password', $password];
	$tmp = sys_get_temp_dir().'/topology-snapshot-ingest-test';
	@mkdir($tmp);
	$process = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, ['TMPDIR' => $tmp]);
	stream_get_contents($pipes[1]);
	stream_get_contents($pipes[2]);
	fclose($pipes[1]);
	fclose($pipes[2]);
	proc_close($process);
	$status = json_decode((string) @file_get_contents($tmp.'/topology-ingest-status.json'), true);
	return $status['summary'] ?? [];
}

$NOW = time();

/** a host with one NEIGHBORS rule: the rule's own item row (type, delay, master) and its snapshot of the given age */
function rule(PDO $pdo, int $hostid, array $item, int $age, ?int $master = null): void {
	$pdo->prepare('INSERT IGNORE INTO hosts (hostid, host, name) VALUES (?, ?, ?)')->execute([$hostid, 'H'.$hostid, 'H'.$hostid]);
	$pdo->prepare('INSERT INTO items (itemid, hostid, status, topology_type, type, delay, master_itemid) VALUES (?, ?, 0, 2, ?, ?, ?)')
		->execute([$hostid * 10 + 2, $hostid, $item['type'], $item['delay'] ?? '0', $master]);
	$json = json_encode([['if_index' => 1, 'rem_sysname' => 'x', 'source' => 'cdp', 'loc_chassis' => 'a0:00:00:00:00:'.sprintf('%02x', $hostid)]]);
	$pdo->prepare('INSERT INTO topo_lld_snapshot (itemid, hostid, topology_type, clock, rows_hash, rows_json, rows_total, rows_valid) VALUES (?, ?, 2, ?, ?, ?, 1, 1)')
		->execute([$hostid * 10 + 2, $hostid, time() - $age, hash('sha256', $json), $json]);
}

function master(PDO $pdo, int $itemid, int $hostid, int $type, string $delay, ?int $master = null): void {
	$pdo->prepare('INSERT INTO items (itemid, hostid, status, topology_type, type, delay, master_itemid) VALUES (?, ?, 0, 0, ?, ?, ?)')->execute([$itemid, $hostid, $type, $delay, $master]);
}

function old_ages(array $summary): array {
	$ages = [];
	foreach ($summary['snapshots_old'] ?? [] as $old) {
		$ages[(int) $old['hostid']] = (int) $old['allowed_age'];
	}
	ksort($ages);
	return $ages;
}

function reset_all(PDO $pdo): void {
	$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
	foreach (['topo_identity_keys', 'topo_observations', 'topo_edges', 'topo_nodes', 'topo_lld_snapshot', 'host_inventory', 'interface', 'items', 'hosts', 'globalmacro', 'hostmacro', 'hosts_templates', 'item_preproc'] as $t) {
		$pdo->exec("DELETE FROM {$t}");
	}
	$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
}

echo "=== the interval of the rule ===\n";
reset_all($pdo);
// every case: [hostid, item, snapshot age in seconds, expected allowed age, old?]. Age = allowed age +/- 100 s; the threshold is the default (7 days)
$cases = [
	[101, ['type' => 4, 'delay' => '30m'], 5300, 5400, false, 'an SNMP rule of 30m: 5400 s, a snapshot of 5300 s is usable'],
	[102, ['type' => 4, 'delay' => '30m'], 5500, 5400, true, 'the same rule, a snapshot of 5500 s is old'],
	[103, ['type' => 4, 'delay' => '1m'], 500, 600, false, 'an interval of 1m: the minimum 600 s, 500 s is usable'],
	[104, ['type' => 4, 'delay' => '1m'], 700, 600, true, 'an interval of 1m: 700 s is old'],
	[105, ['type' => 4, 'delay' => '30m;50s/1-7,00:00-24:00;wd1-5h9-18'], 5500, 5400, true, 'flexible and scheduling intervals are not used'],
	[106, ['type' => 2], 100000, 604800, false, 'a trapper rule: the threshold, 100000 s is usable'],
	[107, ['type' => 4, 'delay' => '0;50s/1-7,00:00-24:00'], 100000, 604800, false, 'an interval of 0 with a flexible one: no base, the threshold'],
	[108, ['type' => 4, 'delay' => '1w'], 700000, 604800, true, 'an interval of 1w: 3 weeks cut to the threshold; 700000 s is old'],
];
foreach ($cases as [$hostid, $item, $age, , ,]) {
	rule($pdo, $hostid, $item, $age);
}
$ages = old_ages(run_ingest($dsn, $user, $password));
foreach ($cases as [$hostid, , $age, $allowed, $is_old, $what]) {
	check(($is_old && ($ages[$hostid] ?? null) === $allowed) || (!$is_old && !isset($ages[$hostid])), $what, $ages[$hostid] ?? 'not old');
}

echo "\n=== a dependent rule: the first master that is not dependent ===\n";
reset_all($pdo);
master($pdo, 9001, 201, 4, '1h');                 // the walk item
master($pdo, 9002, 201, 18, '0', 9001);          // a dependent item between
rule($pdo, 201, ['type' => 18], 10000, 9002);       // 10800 s: usable at 10000, old at 11000
rule($pdo, 202, ['type' => 18], 11000, 9001);
master($pdo, 9003, 203, 2, '0');                  // a trapper master
rule($pdo, 203, ['type' => 18], 100000, 9003);
$ages = old_ages(run_ingest($dsn, $user, $password));
check(!isset($ages[201]), 'a rule on a dependent item on a 1h walk: 10800 s, 10000 s is usable');
check(($ages[202] ?? null) === 10800, 'a rule on a 1h walk: 11000 s is old, allowed_age 10800');
check(!isset($ages[203]), 'a rule on a trapper master: the threshold');

echo "\n=== the heartbeat ===\n";
reset_all($pdo);
master($pdo, 9011, 211, 4, '30m');
$pdo->exec("INSERT INTO item_preproc (itemid, step, type, params) VALUES (9011, 1, 20, '2h')");      // the master discards unchanged values, heartbeat 2h
rule($pdo, 211, ['type' => 18], 20000, 9011);           // 3 x 7200 = 21600: usable at 20000
rule($pdo, 212, ['type' => 18], 22000, 9011);           // old at 22000
master($pdo, 9013, 213, 4, '30m');
rule($pdo, 213, ['type' => 18], 11000, 9013);            // the heartbeat on the rule itself
$pdo->exec("INSERT INTO item_preproc (itemid, step, type, params) VALUES (2132, 1, 20, '1h')");      // 10800
$pdo->exec("INSERT INTO item_preproc (itemid, step, type, params) VALUES (9011, 2, 19, '')");        // a plain "Discard unchanged" is not a heartbeat
$ages = old_ages(run_ingest($dsn, $user, $password));
check(!isset($ages[211]) && ($ages[212] ?? null) === 21600, 'the heartbeat of the master is the base: 3 x 2h = 21600 s');
check(($ages[213] ?? null) === 10800, 'the heartbeat of the rule itself: 3 x 1h, 11000 s is old', $ages[213] ?? null);

echo "\n=== user macros on the host ===\n";
reset_all($pdo);
master($pdo, 9021, 221, 4, '{$TOPO.DELAY}');
rule($pdo, 221, ['type' => 18], 2000, 9021);       // the macro is 10m: 1800 s, 2000 s is old
$pdo->exec("INSERT INTO hosts (hostid, host, name, status) VALUES (900, 'TPL', 'TPL', 3)");
$pdo->exec("INSERT INTO hosts_templates (hostid, templateid) VALUES (221, 900)");
$pdo->exec("INSERT INTO hostmacro (hostid, macro, value) VALUES (900, '{\$TOPO.DELAY}', '10m')");
master($pdo, 9022, 222, 4, '{$TOPO.DELAY}');
rule($pdo, 222, ['type' => 18], 2000, 9022);       // the host's own macro (2h) wins over the global one
$pdo->exec("INSERT INTO hostmacro (hostid, macro, value) VALUES (222, '{\$TOPO.DELAY}', '2h')");
$pdo->exec("INSERT INTO globalmacro (macro, value) VALUES ('{\$TOPO.DELAY}', '1m')");
master($pdo, 9023, 223, 4, '{$TOPO.UNKNOWN}');
rule($pdo, 223, ['type' => 18], 100000, 9023);       // a macro that does not resolve: no base
$ages = old_ages(run_ingest($dsn, $user, $password));
check(($ages[221] ?? null) === 1800, 'a macro of a linked template: 10m, allowed age 1800 s');
check(!isset($ages[222]), 'the host macro wins over the global one: 2h = 21600 s, 2000 s is usable');
check(!isset($ages[223]), 'a macro that does not resolve: the threshold');

echo "\n=== the cap: the freshness threshold ===\n";
reset_all($pdo);
$pdo->exec("INSERT INTO globalmacro (macro, value) VALUES ('{\$TOPOLOGY.LINK.FRESH.SECONDS}', '3600')");
rule($pdo, 231, ['type' => 4, 'delay' => '30m'], 4000);      // 5400 s is cut to 3600
rule($pdo, 232, ['type' => 4, 'delay' => '30m'], 3000);
$summary = run_ingest($dsn, $user, $password);
check(old_ages($summary) === [231 => 3600], 'allowed age 5400 is cut to the threshold 3600: 4000 s is old, 3000 s is not', old_ages($summary));
$pdo->exec("UPDATE globalmacro SET value = '60'");
$ages = old_ages(run_ingest($dsn, $user, $password));
check(($ages[231] ?? null) === 60 && ($ages[232] ?? null) === 60, 'freshness_seconds 60 (S10) keeps working: below the minimum of 600, the cap decides', $ages);

echo "\nAll allowed age checks passed.\n";
