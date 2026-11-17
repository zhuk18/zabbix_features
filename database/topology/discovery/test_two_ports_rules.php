#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * test_two_ports_rules.php — task 089, topology-1-model.md 2.69 §6.6 "Two PORTS rules of one host" and §3.2 (item ids by if_index): the two PORTS snapshots of a host (the interface discovery
 * with item prototypes, and the topology rule without) agree: join by if_index as before; disagree: the newer snapshot gives names, if_index and attrs; an older row only when neither its name
 * nor its if_index is in the newer one; equal clocks or a trapper rule: the Ports of the host do not change and the host is in hosts_ports_disagree. The real ingest.php runs as a process.
 *
 * Usage: test_two_ports_rules.php <PDO DSN> <database user> [password]
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

const ITEM_RULE = 1011;      // the interface discovery: the rows have item ids
const TOPO_RULE = 1014;      // the topology rule: no item prototypes

/** rows [[if_index, name], ...]; the item rule's rows have the items of the index (1000 + if_index), the topology rule's have none */
function rule_rows(string $chassis, array $list, bool $with_items): array {
	return array_map(static fn (array $p): array => ['if_index' => $p[0], 'name' => $p[1], 'loc_chassis' => $chassis, 'mac' => sprintf('02:00:00:20:00:%02x', $p[0] % 256)]
		+ ['itemids' => $with_items ? [1000 + $p[0]] : []], $list);
}

function put_rule(PDO $pdo, int $rule, bool $with_items, array $list, int $clock): void {
	global $C1;
	snapshot($pdo, $rule, 101, 1, $clock, rule_rows($C1, $list, $with_items));
}

function device_ports(PDO $pdo, string $chassis): array {
	$device = $pdo->prepare("SELECT id FROM topo_nodes WHERE type = 'device' AND JSON_UNQUOTE(JSON_EXTRACT(attrs, '\$.chassis_id')) = ?");
	$device->execute([$chassis]);
	$id = (int) $device->fetchColumn();
	$out = [];
	foreach ($pdo->query("SELECT id, attrs FROM topo_nodes WHERE type = 'port' AND device_id = {$id} ORDER BY id")->fetchAll(PDO::FETCH_ASSOC) as $row) {
		$a = json_decode($row['attrs'], true);
		if (empty($a['pseudo'])) {
			$out[(string) ($a['name'] ?? '')][] = ['id' => (int) $row['id'], 'if_index' => $a['if_index'] ?? null];
		}
	}
	return $out;
}

$FOUR = [[1, 'Gi0/1'], [2, 'Gi0/2'], [3, 'Gi0/3'], [4, 'Gi0/4']];

/** both rules agree, one run: 4 Ports with the items of their index */
function base(PDO $pdo, string $dsn, string $user, string $password): array {
	global $NOW, $FOUR, $C1;
	reset_all($pdo);
	add_host($pdo, 101, 'SW101', 'Switch 101', '10.0.0.101');
	put_rule($pdo, ITEM_RULE, true, $FOUR, $NOW - 100);
	put_rule($pdo, TOPO_RULE, false, $FOUR, $NOW - 50);
	run_ingest($dsn, $user, $password);
	return device_ports($pdo, $C1);
}

function ids_of(array $ports): array {
	$out = [];
	foreach ($ports as $name => $list) {
		$out[$name] = array_column($list, 'id');
	}
	return $out;
}

echo "\n=== 1. the rules agree: the same Ports and item ids as before ===\n";
$p = base($pdo, $dsn, $user, $password);
check(count($p) === 4 && array_sum(array_map('count', $p)) === 4, 'four Ports', array_keys($p));
check($p['Gi0/3'][0]['if_index'] === 3, 'each Port has the index of its row', $p);

echo "\n=== 2. the newer rule has a new index for one port, the older the old one ===\n";
$before = base($pdo, $dsn, $user, $password);
put_rule($pdo, TOPO_RULE, false, [[11, 'Gi0/1'], [2, 'Gi0/2'], [3, 'Gi0/3'], [4, 'Gi0/4']], $NOW);       // newer; the item rule (older) still has Gi0/1 = 1
$r = run_ingest($dsn, $user, $password);
$p = device_ports($pdo, $C1);
check(array_sum(array_map('count', $p)) === 4 && $p['Gi0/1'][0]['id'] === $before['Gi0/1'][0]['id'] && $p['Gi0/1'][0]['if_index'] === 11, 'no second Port; the Port keeps its id and gets the new index', $p);
check(($r['summary']['hosts_ports_disagree']['count'] ?? -1) === 0, 'a newer rule decides: the host is not in hosts_ports_disagree');

echo "\n=== 3. the same, the rule with the items is the newer one ===\n";
$before = base($pdo, $dsn, $user, $password);
put_rule($pdo, ITEM_RULE, true, [[11, 'Gi0/1'], [2, 'Gi0/2'], [3, 'Gi0/3'], [4, 'Gi0/4']], $NOW);        // newer; the topology rule (older) still has Gi0/1 = 1
run_ingest($dsn, $user, $password);
$p = device_ports($pdo, $C1);
check(array_sum(array_map('count', $p)) === 4 && $p['Gi0/1'][0]['id'] === $before['Gi0/1'][0]['id'] && $p['Gi0/1'][0]['if_index'] === 11,
	'the Port gets the new index and the items of its new index', $p);

echo "\n=== 4. two ports exchange their indexes in the newer rule only ===\n";
$before = base($pdo, $dsn, $user, $password);
put_rule($pdo, TOPO_RULE, false, [[2, 'Gi0/1'], [1, 'Gi0/2'], [3, 'Gi0/3'], [4, 'Gi0/4']], $NOW);
run_ingest($dsn, $user, $password);
$p = device_ports($pdo, $C1);
check(ids_of($p) === ids_of($before) && $p['Gi0/1'][0]['if_index'] === 2 && $p['Gi0/2'][0]['if_index'] === 1, 'the Port ids stay, the indexes are exchanged', $p);

echo "\n=== 5. the older rule has a port that the newer one does not have ===\n";
$before = base($pdo, $dsn, $user, $password);
put_rule($pdo, TOPO_RULE, false, [[11, 'Gi0/1'], [2, 'Gi0/2'], [3, 'Gi0/3']], $NOW);       // no Gi0/4 (its name and index 4 are free in the newer rule)
run_ingest($dsn, $user, $password);
$p = device_ports($pdo, $C1);
check(array_sum(array_map('count', $p)) === 4 && $p['Gi0/4'][0]['id'] === $before['Gi0/4'][0]['id'] && $p['Gi0/4'][0]['if_index'] === 4 && $p['Gi0/1'][0]['if_index'] === 11,
	'the Port Gi0/4 is kept from the older row (index and items); Gi0/1 follows the newer one', $p);

echo "\n=== 6. disagree with equal clocks, and with a trapper rule: the Ports do not change ===\n";
$before = base($pdo, $dsn, $user, $password);
put_rule($pdo, ITEM_RULE, true, [[11, 'Gi0/1'], [2, 'Gi0/2'], [3, 'Gi0/3'], [4, 'Gi0/4']], $NOW);
put_rule($pdo, TOPO_RULE, false, $FOUR, $NOW);       // equal clocks, they disagree on Gi0/1
$r = run_ingest($dsn, $user, $password);
check(device_ports($pdo, $C1) === $before && ($r['summary']['hosts_ports_disagree']['count'] ?? 0) === 1 && ($r['summary']['hosts_ports_disagree']['list'][0]['host'] ?? '') === 'SW101',
	'equal clocks: the Ports (ids, indexes, items) are as before; hosts_ports_disagree = 1', [$r['summary']['hosts_ports_disagree'] ?? null]);
check(str_contains($r['out'], 'hosts_ports_disagree') && count(array_filter($r['summary']['attention'] ?? [], static fn (array $a): bool => $a['kind'] === 'hosts_ports_disagree')) === 1, 'the CLI text and "Needs attention" show the host');
$before = base($pdo, $dsn, $user, $password);
put_rule($pdo, ITEM_RULE, true, [[11, 'Gi0/1'], [2, 'Gi0/2'], [3, 'Gi0/3'], [4, 'Gi0/4']], $NOW - 100);
put_rule($pdo, TOPO_RULE, false, $FOUR, $NOW);       // the topology rule is newer, but the other one is a trapper rule: no comparison of clocks
$pdo->exec('UPDATE items SET type = 2 WHERE itemid = '.ITEM_RULE);
$r = run_ingest($dsn, $user, $password);
check(device_ports($pdo, $C1) === $before && ($r['summary']['hosts_ports_disagree']['count'] ?? 0) === 1, 'a trapper rule: the Ports are as before; hosts_ports_disagree = 1');

echo "\n=== 7. a second run over the same snapshots changes nothing ===\n";
base($pdo, $dsn, $user, $password);
put_rule($pdo, TOPO_RULE, false, [[2, 'Gi0/1'], [1, 'Gi0/2'], [3, 'Gi0/3'], [4, 'Gi0/4']], $NOW);
run_ingest($dsn, $user, $password);
$fp = fingerprint($pdo);
$ports = $pdo->query("SELECT id, attrs FROM topo_nodes WHERE type = 'port' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
run_ingest($dsn, $user, $password);
check($fp === fingerprint($pdo) && $ports === $pdo->query("SELECT id, attrs FROM topo_nodes WHERE type = 'port' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC), 'ids, Ports and fingerprint are the same');

echo "\nAll two PORTS rules checks passed.\n";
