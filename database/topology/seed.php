#!/usr/bin/env php
<?php

declare(strict_types=1);

if ($argc < 3 || $argc > 4) {
	fwrite(STDERR, "Usage: {$argv[0]} <PDO DSN> <database user> [password]\n");
	exit(1);
}

$pdo = new PDO($argv[1], $argv[2], $argv[3] ?? '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$now = time();
$pdo->beginTransaction();

try {
	$pdo->exec('DELETE FROM topo_edges');
	$pdo->exec('DELETE FROM topo_nodes');
	$insert_node = $pdo->prepare('INSERT INTO topo_nodes (type, attrs, created_at, updated_at) VALUES (?, ?, ?, ?)');
	$insert_edge = $pdo->prepare('INSERT INTO topo_edges (type, src_id, dst_id, attrs, created_at) VALUES (?, ?, ?, ?, ?)');
	$node = static function(string $type, array $attrs) use ($insert_node, $pdo, $now): int {
		$insert_node->execute([$type, json_encode($attrs, JSON_THROW_ON_ERROR), $now, $now]);
		return (int) $pdo->lastInsertId($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql' ? 'topo_nodes_id_seq' : null);
	};
	$edge = static function(string $type, int $source, int $target, array $attrs = []) use ($insert_edge, $now): void {
		$insert_edge->execute([$type, $source, $target, json_encode($attrs, JSON_THROW_ON_ERROR), $now]);
	};
	$switch = $node('device', ['mac' => '00:11:22:33:44:55', 'chassis_id' => 'switch-48-core', 'mgmt_ip' => '192.0.2.10', 'sysname' => 'core-switch-48', 'vendor' => 'Example Networks', 'last_seen' => $now]);
	$ports = [];
	for ($port = 1; $port <= 44; $port++) {
		$connected = $port <= 40;
		$ports[$port] = $node('interface', ['if_index' => $port, 'name' => 'GigabitEthernet1/0/'.$port, 'if_type' => 'physical', 'mac' => sprintf('00:11:22:33:%02x:%02x', intdiv($port, 256), $port % 256), 'speed' => 1000000000, 'admin_status' => 'up', 'oper_status' => $connected ? 'up' : 'down', 'learned_macs' => $connected && $port > 2 ? [sprintf('02:00:00:00:00:%02x', $port)] : []]);
		$edge('part_of', $ports[$port], $switch);
	}
	for ($port = 1; $port <= 2; $port++) {
		$neighbor = $node('device', ['mac' => sprintf('00:aa:bb:cc:dd:%02x', $port), 'chassis_id' => 'neighbor-'.$port, 'mgmt_ip' => '192.0.2.'.(20 + $port), 'sysname' => 'edge-switch-'.$port, 'vendor' => 'Example Networks', 'last_seen' => $now]);
		$interface = $node('interface', ['if_index' => 1, 'name' => 'Ethernet1', 'if_type' => 'physical', 'mac' => sprintf('00:aa:bb:cc:dd:%02x', $port), 'speed' => 1000000000, 'admin_status' => 'up', 'oper_status' => 'up', 'learned_macs' => []]);
		$edge('part_of', $interface, $neighbor);
		$edge('physical_link', $ports[$port], $interface, ['discovered_via' => 'lldp', 'last_seen' => $now]);
	}
	foreach ([[1, 37, 38], [2, 39, 40]] as [$lag_index, $first, $second]) {
		$lag = $node('interface', ['if_index' => 100 + $lag_index, 'name' => 'Port-channel'.$lag_index, 'if_type' => 'lag', 'mac' => sprintf('00:11:22:33:fe:%02x', $lag_index), 'speed' => 2000000000, 'admin_status' => 'up', 'oper_status' => 'up', 'learned_macs' => []]);
		$edge('part_of', $lag, $switch);
		$edge('member_of_lag', $ports[$first], $lag);
		$edge('member_of_lag', $ports[$second], $lag);
	}
	for ($index = 1; $index <= 2; $index++) {
		$management = $node('interface', ['if_index' => 200 + $index, 'name' => 'Management'.$index, 'if_type' => 'mgmt', 'mac' => sprintf('00:11:22:33:fd:%02x', $index), 'speed' => 100000000, 'admin_status' => 'up', 'oper_status' => 'up', 'learned_macs' => []]);
		$edge('part_of', $management, $switch);
	}
	$pdo->commit();
	echo "Topology fixture loaded: 48 switch interfaces (40 connected, 4 disconnected, 2 LAG, 2 management).\n";
}
catch (Throwable $exception) {
	$pdo->rollBack();
	fwrite(STDERR, "Unable to load topology fixture: {$exception->getMessage()}\n");
	exit(1);
}