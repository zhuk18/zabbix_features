#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * test_identity_normalization.php — the identity normalization of ingest.php (topology-push-transport-spec.md §5.1).
 *
 * Reaches $snap_normalize_row through TOPOLOGY_INGEST_TEST_HOOK. (1) It is a no-op on the native step's output: every
 * golden output (step_golden/expected, the reference JavaScript's rows) goes through it unchanged. (2) It normalizes the
 * spellings a collector other than the step could produce, and refuses what cannot be normalized.
 *
 * Usage: test_identity_normalization.php <PDO DSN> <database user> [password]
 * Expects a THROWAWAY database. Never point it at a real Zabbix database.
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
$pdo = new PDO($args[0], $args[1], $args[2] ?? '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
foreach (['topo_observations', 'topo_edges', 'topo_nodes', 'topo_lld_snapshot', 'items', 'proxy', 'hosts'] as $table) {
	$pdo->exec("DROP TABLE IF EXISTS {$table}");
}
$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
$pdo->exec('CREATE TABLE hosts (hostid BIGINT UNSIGNED PRIMARY KEY, host VARCHAR(128) NOT NULL DEFAULT \'\')');
$pdo->exec('CREATE TABLE proxy (proxyid BIGINT UNSIGNED PRIMARY KEY)');
$pdo->exec('CREATE TABLE items (itemid BIGINT UNSIGNED PRIMARY KEY)');
$pdo->exec(file_get_contents(__DIR__.'/../mysql_schema.sql'));

putenv('ZABBIX_API_URL=http://unused.invalid');
putenv('ZABBIX_API_TOKEN=unused');
putenv('TOPOLOGY_PDO_DSN='.$args[0]);
putenv('TOPOLOGY_PDO_USER='.$args[1]);
putenv('TOPOLOGY_PDO_PASSWORD='.($args[2] ?? ''));

$done = false;
define('TOPOLOGY_INGEST_TEST_HOOK', function (array $ingest) use (&$done) {
	$normalize = $ingest['snap_normalize_row'];

	// macro of the contract => snapshot field (src/zabbix_server/lld/lld_topology.c, topo_* tables)
	$field = ['{#IFINDEX}' => 'if_index', '{#IFNAME}' => 'name', '{#IFTYPE}' => 'if_type', '{#IFMAC}' => 'mac',
		'{#IFADMINSTATUS}' => 'admin_status', '{#IFOPERSTATUS}' => 'oper_status', '{#IFSPEED}' => 'speed',
		'{#LOC_CHASSIS}' => 'loc_chassis', '{#REM_CHASSIS}' => 'rem_chassis', '{#REM_MGMT_IP}' => 'rem_mgmt_ip',
		'{#REM_SYSNAME}' => 'rem_sysname', '{#REM_CHASSIS_TYPE}' => 'rem_chassis_type', '{#REM_PORT}' => 'rem_port',
		'{#REM_PORT_TYPE}' => 'rem_port_type', '{#REM_PORT_DESC}' => 'rem_port_desc', '{#SOURCE}' => 'source',
		'{#MAC}' => 'mac', '{#PORT_MAC_COUNT}' => 'port_mac_count', '{#VLAN}' => 'vlan', '{#LAG_IFINDEX}' => 'lag_if_index'];

	echo "\n=== a no-op on the native step's output ===\n";
	$files = glob(__DIR__.'/step_golden/expected/*.json');
	$rows_checked = 0;
	foreach ($files as $file) {
		foreach (json_decode((string) file_get_contents($file), true) as $row) {
			$snapshot_row = [];
			foreach ($row as $macro => $value) {
				$snapshot_row[$field[$macro] ?? $macro] = $value;
			}
			[$out, $problem] = $normalize($snapshot_row);
			if ($out !== $snapshot_row) {
				fail_test(basename($file).': '.json_encode($snapshot_row).' became '.json_encode($out ?? $problem));
			}
			$rows_checked++;
		}
	}
	check($rows_checked > 100, "{$rows_checked} rows of ".count($files).' golden outputs pass unchanged');

	echo "\n=== spellings it normalizes ===\n";
	foreach ([
		['loc_chassis', 'AA-BB-CC-00-00-0A', 'aa:bb:cc:00:00:0a'], ['loc_chassis', 'aabb.cc00.000a', 'aa:bb:cc:00:00:0a'],
		['loc_chassis', '0:BB:cc:0:0:a', '00:bb:cc:00:00:0a'], ['mac', 'AA:BB:CC:DD:EE:FF', 'aa:bb:cc:dd:ee:ff'],
		['loc_chassis', "switch-1  \0", 'switch-1'],
	] as [$name, $in, $want]) {
		[$out] = $normalize([$name => $in]);
		check(($out[$name] ?? null) === $want, "{$name} ".json_encode($in).' -> '.json_encode($want));
	}
	[$out] = $normalize(['rem_chassis' => 'AA-BB-CC-00-00-0A', 'rem_chassis_type' => 'macAddress']);
	check($out['rem_chassis'] === 'aa:bb:cc:00:00:0a', 'a macAddress chassis id is normalized');
	[$out] = $normalize(['rem_port' => 'AABB.CC00.000A', 'rem_port_type' => 'macAddress']);
	check($out['rem_port'] === 'aa:bb:cc:00:00:0a', 'a macAddress port id is normalized');
	[$out] = $normalize(['rem_mgmt_ip' => '2001:DB8:0:0:0:0:0:1']);
	check($out['rem_mgmt_ip'] === '2001:db8::1', 'an IPv6 management address is compressed and lowercase');
	[$out] = $normalize(['rem_chassis' => '2001:DB8::1', 'rem_chassis_type' => 'networkAddress']);
	check($out['rem_chassis'] === '2001:db8::1', 'a networkAddress chassis id that is an IP is written the same way');

	echo "\n=== what it leaves alone or refuses ===\n";
	[$out] = $normalize(['loc_chassis' => '01:c0:a8:01:0b']);
	check($out['loc_chassis'] === '01:c0:a8:01:0b', 'a local chassis of 5 colon-hex octets (a networkAddress) is not called a bad MAC');
	[$out] = $normalize(['rem_chassis' => 'Switch-1', 'rem_chassis_type' => 'local']);
	check($out['rem_chassis'] === 'Switch-1', 'a text chassis id is kept as received');
	[$out, $problem] = $normalize(['mac' => 'aa:bb:cc:dd:ee']);
	check($out === null && str_starts_with((string) $problem, 'mac'), 'a port MAC of 5 octets is refused');
	[$out] = $normalize(['rem_chassis' => 'aa:bb:cc:dd:ee:ff:00', 'rem_chassis_type' => 'macAddress']);
	check($out === null, 'a macAddress chassis id of 7 octets is refused');
	[$out] = $normalize(['rem_mgmt_ip' => '10.0.0.4.5']);
	check($out === null, 'a malformed management IPv4 is refused');
	[$out] = $normalize(['rem_mgmt_ip' => '300.1.1.1']);
	check($out === null, 'an out-of-range management IPv4 is refused');
	[$out] = $normalize(['rem_mgmt_ip' => '']);
	check($out === ['rem_mgmt_ip' => ''], 'an empty optional value is not an error');

	echo "\nAll identity normalization tests passed.\n";
	$done = true;
});

require __DIR__.'/ingest.php';

if (!$done) {
	fail_test('TOPOLOGY_INGEST_TEST_HOOK never ran to completion');
}
exit(0);
