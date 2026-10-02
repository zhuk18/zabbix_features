#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * test_device_link_validation.php — topology-device-level-edge-spec.md §3 / §9 "Type validation" and "Reasons".
 *
 * Runs ingest.php in-process through TOPOLOGY_INGEST_TEST_HOOK (like test_reconciliation_scenarios.php) to reach the
 * closure that validates every edge write: a physical_link with a Device end, a device_link with a Port dst, and an
 * unknown far_port_reason are rejected; fdb_mac_only (reserved, never produced) is accepted.
 *
 * Usage: test_device_link_validation.php <PDO DSN> <database user> [password]
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
define('TOPOLOGY_INGEST_TEST_HOOK', function (array $ingest) use ($pdo, &$done) {
	$validate_edge = $ingest['validate_edge'];
	$snap_device_link = $ingest['snap_device_link'];
	$insert = $pdo->prepare('INSERT INTO topo_nodes (type, device_id, attrs, created_at, updated_at) VALUES (?, ?, ?, 0, 0)');
	$insert->execute(['device', null, '{}']);
	$device = (int) $pdo->lastInsertId();
	$insert->execute(['device', null, '{}']);
	$device2 = (int) $pdo->lastInsertId();
	$insert->execute(['port', $device, '{"if_index":1}']);
	$port = (int) $pdo->lastInsertId();
	$insert->execute(['port', $device2, '{"if_index":1}']);
	$port2 = (int) $pdo->lastInsertId();

	$rejected = static function (callable $call): bool {
		try {
			$call();
		}
		catch (InvalidArgumentException) {
			return true;
		}
		return false;
	};

	echo "\n=== type validation ===\n";
	check(!$rejected(fn () => $validate_edge('physical_link', $port, $port2)), 'physical_link Port <-> Port is accepted');
	check($rejected(fn () => $validate_edge('physical_link', $port, $device2)), 'a physical_link with a Device end is rejected');
	check($rejected(fn () => $validate_edge('physical_link', $device, $device2)), 'a physical_link between two Devices is rejected');
	check(!$rejected(fn () => $validate_edge('device_link', $port, $device2, ['far_port_reason' => 'port_unmatched'])),
		'device_link Port -> Device is accepted');
	check($rejected(fn () => $validate_edge('device_link', $port, $port2, ['far_port_reason' => 'port_unmatched'])),
		'a device_link with a Port dst_id is rejected');
	check($rejected(fn () => $validate_edge('device_link', $device, $device2, ['far_port_reason' => 'port_unmatched'])),
		'a device_link with a Device src_id is rejected');
	check($rejected(fn () => $validate_edge('some_other_link', $port, $port2)), 'an unknown edge type is rejected');

	echo "\n=== reasons ===\n";
	foreach (['port_unmatched', 'port_shared_id', 'lag_ambiguous', 'manual', 'fdb_mac_only'] as $reason) {
		check(!$rejected(fn () => $validate_edge('device_link', $port, $device2, ['far_port_reason' => $reason])),
			"reason {$reason} is accepted");
	}
	check($rejected(fn () => $validate_edge('device_link', $port, $device2, ['far_port_reason' => 'because'])), 'an unknown reason is rejected');
	check($rejected(fn () => $validate_edge('device_link', $port, $device2, ['far_port_reason' => 'port_lost'])),
		'port_lost is rejected: it was removed in device-level spec v0.8 (a link\'s precision only goes up)');
	check($rejected(fn () => $validate_edge('device_link', $port, $device2, [])), 'a missing reason is rejected');
	check($rejected(fn () => $snap_device_link($port, $device2, 'lldp', 'because', [], 1)), 'the writer rejects an unknown reason too');
	check($rejected(fn () => $snap_device_link($port, $port2, 'lldp', 'port_unmatched', [], 1)), 'and a Port as the far end');
	check((int) $pdo->query("SELECT COUNT(*) FROM topo_edges")->fetchColumn() === 0, 'nothing was written by the rejected calls');

	echo "\nAll device-level link validation tests passed.\n";
	$done = true;
});

require __DIR__.'/ingest.php';

if (!$done) {
	fail_test('TOPOLOGY_INGEST_TEST_HOOK never ran to completion');
}
exit(0);
