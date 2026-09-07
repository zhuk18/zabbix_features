#!/usr/bin/env php
<?php

declare(strict_types=1);

$args = array_slice($argv, 1);
$reset = false;
foreach ($args as $index => $arg) {
	if ($arg === '--reset') {
		$reset = true;
		unset($args[$index]);
	}
}
$args = array_values($args);

if (count($args) < 2 || count($args) > 3) {
	fwrite(STDERR, "Usage: {$argv[0]} [--reset] <PDO DSN> <database user> [password]\n\n".
		"Default: upsert — matches existing Device rows by chassis_id (else mgmt_ip) and Port rows by\n".
		"(device, if_index), updating them in place. Safe to re-run; never creates duplicates.\n".
		"--reset: dev-convenience full wipe — truncates every device/port node and their edges first, then\n".
		"loads fresh. Not what validates upsert behavior; run without --reset twice for that.\n");
	exit(1);
}

$pdo = new PDO($args[0], $args[1], $args[2] ?? '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$now = time();
$pdo->beginTransaction();

try {
	if ($reset) {
		$pdo->exec("DELETE edge FROM topo_edges edge JOIN topo_nodes source ON source.id=edge.src_id JOIN topo_nodes target ON target.id=edge.dst_id WHERE source.type <> 'host' OR target.type <> 'host'");
		$pdo->exec("DELETE FROM topo_nodes WHERE type IN ('device', 'port')");
	}

	$insert_node = $pdo->prepare('INSERT INTO topo_nodes (type, attrs, created_at, updated_at) VALUES (?, ?, ?, ?)');
	$update_node = $pdo->prepare('UPDATE topo_nodes SET attrs = ?, updated_at = ? WHERE id = ?');
	$insert_edge = $pdo->prepare('INSERT INTO topo_edges (type, src_id, dst_id, attrs, created_at) VALUES (?, ?, ?, ?, ?)');
	$update_edge_attrs = $pdo->prepare('UPDATE topo_edges SET attrs = ? WHERE id = ?');
	$last_insert_id = static function() use ($pdo): int {
		return (int) $pdo->lastInsertId($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql' ? 'topo_nodes_id_seq' : null);
	};

	// §3 rule 4 upsert keys: Device by chassis_id, else mgmt_ip; Port by (device via part_of, if_index).
	$find_device = static function(string $chassis_id, string $mgmt_ip) use ($pdo): ?int {
		$stmt = $pdo->prepare("SELECT id FROM topo_nodes WHERE type = 'device' AND JSON_UNQUOTE(JSON_EXTRACT(attrs, '\$.chassis_id')) = ?");
		$stmt->execute([$chassis_id]);
		if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
			return (int) $row['id'];
		}
		$stmt = $pdo->prepare("SELECT id FROM topo_nodes WHERE type = 'device' AND JSON_UNQUOTE(JSON_EXTRACT(attrs, '\$.mgmt_ip')) = ?");
		$stmt->execute([$mgmt_ip]);
		$row = $stmt->fetch(PDO::FETCH_ASSOC);
		return $row ? (int) $row['id'] : null;
	};
	$find_port = static function(int $device_id, int $if_index) use ($pdo): ?int {
		$stmt = $pdo->prepare("SELECT port.id FROM topo_nodes port".
			" JOIN topo_edges part_of ON part_of.type = 'part_of' AND part_of.src_id = port.id".
			" WHERE part_of.dst_id = ? AND port.type = 'port' AND JSON_EXTRACT(port.attrs, '\$.if_index') = ?");
		$stmt->execute([$device_id, $if_index]);
		$row = $stmt->fetch(PDO::FETCH_ASSOC);
		return $row ? (int) $row['id'] : null;
	};

	// Device upsert. In --reset mode $find_device always misses (table was just truncated), so this collapses
	// to a plain insert; the branch is identical either way, no separate reset-mode code path needed.
	$device = static function(array $attrs) use ($insert_node, $update_node, $now, $last_insert_id, $find_device): int {
		$existing_id = $find_device($attrs['chassis_id'], $attrs['mgmt_ip']);
		if ($existing_id !== null) {
			$update_node->execute([json_encode($attrs, JSON_THROW_ON_ERROR), $now, $existing_id]);
			return $existing_id;
		}
		$insert_node->execute(['device', json_encode($attrs, JSON_THROW_ON_ERROR), $now, $now]);
		return $last_insert_id();
	};

	// Port upsert — also owns the part_of edge to its device, since the upsert key (device, if_index) needs
	// the device id up front and a newly-inserted port always needs a fresh part_of edge; an already-matched
	// port's part_of edge is left untouched (a port's parent device is assumed stable across reseeds).
	$port = static function(int $device_id, array $attrs) use ($insert_node, $update_node, $insert_edge, $now, $last_insert_id, $find_port): int {
		$existing_id = $find_port($device_id, $attrs['if_index']);
		if ($existing_id !== null) {
			$update_node->execute([json_encode($attrs, JSON_THROW_ON_ERROR), $now, $existing_id]);
			return $existing_id;
		}
		$insert_node->execute(['port', json_encode($attrs, JSON_THROW_ON_ERROR), $now, $now]);
		$port_id = $last_insert_id();
		$insert_edge->execute(['part_of', $port_id, $device_id, '{}', $now]);
		return $port_id;
	};

	// Edge upsert for everything besides part_of (which $port() owns). $canonicalize mirrors
	// CTopologyPrototype::upsertPhysicalLink()'s direction canonicalization for physical_link — both node ids
	// are now stable across reseeds (matched by key), so the same two ports always produce the same
	// (src_id, dst_id) pair here, same as at request time.
	$ensure_edge = static function(string $type, int $src, int $dst, array $attrs, bool $canonicalize = false)
			use ($pdo, $insert_edge, $update_edge_attrs, $now): void {
		if ($canonicalize && $src > $dst) {
			[$src, $dst] = [$dst, $src];
		}
		$stmt = $pdo->prepare('SELECT id FROM topo_edges WHERE type = ? AND src_id = ? AND dst_id = ?');
		$stmt->execute([$type, $src, $dst]);
		if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
			$update_edge_attrs->execute([json_encode($attrs, JSON_THROW_ON_ERROR), $row['id']]);
			return;
		}
		$insert_edge->execute([$type, $src, $dst, json_encode($attrs, JSON_THROW_ON_ERROR), $now]);
	};

	$switch = $device(['mac' => '00:11:22:33:44:55', 'chassis_id' => 'switch-48-core', 'mgmt_ip' => '192.0.2.10', 'sysname' => 'core-switch-48', 'vendor' => 'Example Networks', 'last_seen' => $now]);
	$ports = [];
	for ($port_index = 1; $port_index <= 44; $port_index++) {
		$connected = $port_index <= 40;
		$ports[$port_index] = $port($switch, ['if_index' => $port_index, 'name' => 'GigabitEthernet1/0/'.$port_index, 'if_type' => 'physical', 'mac' => sprintf('00:11:22:33:%02x:%02x', intdiv($port_index, 256), $port_index % 256), 'speed' => 1000000000, 'admin_status' => 'up', 'oper_status' => $connected ? 'up' : 'down', 'learned_macs' => $connected && $port_index > 2 ? [sprintf('02:00:00:00:00:%02x', $port_index)] : [], 'zabbix_itemids' => []]);
	}
	$edge_switches = [];
	for ($neighbor_index = 1; $neighbor_index <= 2; $neighbor_index++) {
		$neighbor = $device(['mac' => sprintf('00:aa:bb:cc:dd:%02x', $neighbor_index), 'chassis_id' => 'neighbor-'.$neighbor_index, 'mgmt_ip' => '192.0.2.'.(20 + $neighbor_index), 'sysname' => 'edge-switch-'.$neighbor_index, 'vendor' => 'Example Networks', 'last_seen' => $now]);
		$neighbor_port = $port($neighbor, ['if_index' => 1, 'name' => 'Ethernet1', 'if_type' => 'physical', 'mac' => sprintf('00:aa:bb:cc:dd:%02x', $neighbor_index), 'speed' => 1000000000, 'admin_status' => 'up', 'oper_status' => 'up', 'learned_macs' => [], 'zabbix_itemids' => []]);
		$ensure_edge('physical_link', $ports[$neighbor_index], $neighbor_port, ['discovered_via' => 'lldp', 'last_seen' => $now], true);
		$edge_switches[] = $neighbor;
	}
	foreach ([[1, 37, 38], [2, 39, 40]] as [$lag_index, $first, $second]) {
		$lag = $port($switch, ['if_index' => 100 + $lag_index, 'name' => 'Port-channel'.$lag_index, 'if_type' => 'lag', 'mac' => sprintf('00:11:22:33:fe:%02x', $lag_index), 'speed' => 2000000000, 'admin_status' => 'up', 'oper_status' => 'up', 'learned_macs' => [], 'zabbix_itemids' => []]);
		$ensure_edge('member_of_lag', $ports[$first], $lag, []);
		$ensure_edge('member_of_lag', $ports[$second], $lag, []);
	}
	for ($mgmt_index = 1; $mgmt_index <= 2; $mgmt_index++) {
		$port($switch, ['if_index' => 200 + $mgmt_index, 'name' => 'Management'.$mgmt_index, 'if_type' => 'mgmt', 'mac' => sprintf('00:11:22:33:fd:%02x', $mgmt_index), 'speed' => 100000000, 'admin_status' => 'up', 'oper_status' => 'up', 'learned_macs' => [], 'zabbix_itemids' => []]);
	}

	// Access-layer fanout: a few dozen more devices branching off the two edge switches, so the graph has
	// real depth/breadth to exercise multi-hop expansion, dragging many hosts/proxies in, and a busier canvas
	// generally. Deterministic (not random) so re-running the fixture always produces the same shape.
	$access_switch_count = 10;
	$vendors = ['Example Networks', 'Acme Switching', 'Contoso Netgear', 'Fabrikam Systems'];
	$pool = $edge_switches;
	$uplink_port_counter = [];
	for ($i = 1; $i <= $access_switch_count; $i++) {
		$parent = $pool[$i % count($pool)];
		$uplink_port_counter[$parent] = ($uplink_port_counter[$parent] ?? 0) + 1;
		$parent_downlink = $port($parent, ['if_index' => 10 + $uplink_port_counter[$parent], 'name' => 'GigabitEthernet0/'.$uplink_port_counter[$parent], 'if_type' => 'physical', 'mac' => sprintf('00:cc:dd:%02x:%02x:%02x', intdiv($parent, 65536) % 256, intdiv($parent, 256) % 256, $parent % 256), 'speed' => 1000000000, 'admin_status' => 'up', 'oper_status' => 'up', 'learned_macs' => [], 'zabbix_itemids' => []]);

		$access_device = $device(['mac' => sprintf('00:bb:cc:%02x:%02x:%02x', intdiv($i, 65536) % 256, intdiv($i, 256) % 256, $i % 256), 'chassis_id' => 'access-'.$i, 'mgmt_ip' => '192.0.3.'.(($i % 254) + 1), 'sysname' => 'access-switch-'.$i, 'vendor' => $vendors[$i % count($vendors)], 'last_seen' => $now]);
		$uplink = $port($access_device, ['if_index' => 1, 'name' => 'GigabitEthernet0/1', 'if_type' => 'physical', 'mac' => sprintf('00:bb:cc:%02x:%02x:01', intdiv($i, 256) % 256, $i % 256), 'speed' => 1000000000, 'admin_status' => 'up', 'oper_status' => 'up', 'learned_macs' => [], 'zabbix_itemids' => []]);
		$ensure_edge('physical_link', $parent_downlink, $uplink, ['discovered_via' => 'lldp', 'last_seen' => $now], true);

		// one MAC-only port (no LLDP neighbor — most ports terminate in non-LLDP endpoints, §3.1) and one
		// disconnected port per access switch, for interface-table variety matching the core switch's mix
		$port($access_device, ['if_index' => 2, 'name' => 'GigabitEthernet0/2', 'if_type' => 'physical', 'mac' => sprintf('00:bb:cc:%02x:%02x:02', intdiv($i, 256) % 256, $i % 256), 'speed' => 1000000000, 'admin_status' => 'up', 'oper_status' => 'up', 'learned_macs' => [sprintf('02:00:00:cc:%02x:%02x', intdiv($i, 256) % 256, $i % 256)], 'zabbix_itemids' => []]);
		$port($access_device, ['if_index' => 3, 'name' => 'GigabitEthernet0/3', 'if_type' => 'physical', 'mac' => sprintf('00:bb:cc:%02x:%02x:03', intdiv($i, 256) % 256, $i % 256), 'speed' => 1000000000, 'admin_status' => 'up', 'oper_status' => 'down', 'learned_macs' => [], 'zabbix_itemids' => []]);

		$pool[] = $access_device;
	}

	$pdo->commit();
	echo ($reset ? 'Topology fixture reset and reloaded' : 'Topology fixture upserted').
		": 48 switch ports (40 connected, 4 disconnected, 2 LAG, 2 management) ".
		"plus {$access_switch_count} access-layer devices branching off the 2 edge switches.\n";
}
catch (Throwable $exception) {
	$pdo->rollBack();
	fwrite(STDERR, "Unable to load topology fixture: {$exception->getMessage()}\n");
	exit(1);
}
