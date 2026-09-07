<?php

class CTopologyPrototype {

	private static function attrs(array $row): array {
		return json_decode($row['attrs'], true, 512, JSON_THROW_ON_ERROR);
	}

	public static function getDevices(): array {
		$nodes = [];
		$result = DBselect('SELECT node.id,node.type,node.attrs,host.name AS host_name,host.status AS host_status'.
			' FROM topo_nodes node LEFT JOIN hosts host ON host.hostid=node.host_ref'.
			' WHERE node.type='.zbx_dbstr('device').' OR (node.type='.zbx_dbstr('host').' AND host.hostid IS NOT NULL)');

		while ($row = DBfetch($result)) {
			$attrs = self::attrs($row);
			$nodes[] = [
				'id' => $row['id'], 'type' => $row['type'],
				'name' => $row['type'] === 'device' ? $attrs['sysname'] : $row['host_name'],
				'monitoring_state' => $row['type'] === 'host' ? $row['host_status'] : null,
				'represented' => $row['type'] === 'device' && self::isRepresented($row['id'])
			];
		}

		return $nodes;
	}

	public static function getRelations(): array {
		$relations = [];
		$result = DBselect('SELECT src_id,dst_id,type FROM topo_edges WHERE type='.zbx_dbstr('represented_by'));

		while ($row = DBfetch($result)) {
			$relations[] = ['source' => $row['src_id'], 'target' => $row['dst_id'], 'type' => $row['type']];
		}

		return $relations;
	}

	public static function getNeighbors(string $deviceid): array {
		$neighbors = [];
		$result = DBselect(
			'SELECT DISTINCT device.id,device.type,device.attrs'.
			' FROM topo_edges local_part'.
			' JOIN topo_edges link ON link.type='.zbx_dbstr('physical_link').
				' AND (link.src_id=local_part.src_id OR link.dst_id=local_part.src_id)'.
			' JOIN topo_edges remote_part ON remote_part.type='.zbx_dbstr('part_of').
				' AND remote_part.src_id=CASE WHEN link.src_id=local_part.src_id THEN link.dst_id ELSE link.src_id END'.
			' JOIN topo_nodes device ON device.id=remote_part.dst_id AND device.type='.zbx_dbstr('device').
			' WHERE local_part.type='.zbx_dbstr('part_of').' AND local_part.dst_id='.zbx_dbstr($deviceid)
		);

		while ($row = DBfetch($result)) {
			$attrs = self::attrs($row);
			$neighbors[] = ['id' => $row['id'], 'type' => 'device', 'name' => $attrs['sysname'], 'monitoring_state' => null,
				'represented' => self::isRepresented($row['id'])];
		}

		return $neighbors;
	}

	public static function getInterfaces(string $deviceid): array {
		$groups = [
			'connected_lldp' => [], 'connected_mac_only' => [], 'disconnected' => [], 'port_channel' => [], 'management' => []
		];
		$result = DBselect(
			'SELECT interface.id,interface.attrs,link.id AS linkid'.
			' FROM topo_edges part_of JOIN topo_nodes interface ON interface.id=part_of.src_id'.
			' LEFT JOIN topo_edges link ON link.type='.zbx_dbstr('physical_link').
				' AND (link.src_id=interface.id OR link.dst_id=interface.id)'.
			' WHERE part_of.type='.zbx_dbstr('part_of').' AND part_of.dst_id='.zbx_dbstr($deviceid).
			' ORDER BY interface.id'
		);

		while ($row = DBfetch($result)) {
			$attrs = self::attrs($row);
			if ($attrs['if_type'] === 'lag') {
				$group = 'port_channel';
			}
			elseif ($attrs['if_type'] === 'mgmt') {
				$group = 'management';
			}
			elseif ($row['linkid'] !== null) {
				$group = 'connected_lldp';
			}
			elseif ($attrs['oper_status'] === 'down') {
				$group = 'disconnected';
			}
			else {
				$group = 'connected_mac_only';
			}

			$groups[$group][] = [
				'port' => $attrs['name'], 'status' => $attrs['oper_status'],
				'connected_to' => $row['linkid'] !== null ? self::getLinkedDeviceName($row['id']) : null,
				'source' => $row['linkid'] !== null ? 'LLDP' : ($group === 'connected_mac_only' ? 'MAC only' : '-')
			];
		}

		return $groups;
	}

	public static function promote(string $deviceid, string $hostid): void {
		DBexecute('DELETE FROM topo_edges WHERE type='.zbx_dbstr('represented_by').' AND src_id='.zbx_dbstr($deviceid));
		DBexecute('INSERT INTO topo_edges (type,src_id,dst_id,attrs,created_at) VALUES ('.
			zbx_dbstr('represented_by').','.zbx_dbstr($deviceid).','.zbx_dbstr($hostid).','.zbx_dbstr(json_encode([
				'match_type' => 'identity', 'matched_by' => 'mac', 'matched_mac' => null, 'created_at' => time()
			])).','.time().')');
	}

	public static function pullHosts(): int {
		$count = 0;
		foreach (API::Host()->get(['output' => ['hostid'], 'selectInterfaces' => 'extend']) as $host) {
			$existing = DBfetch(DBselect('SELECT id FROM topo_nodes WHERE type='.zbx_dbstr('host').
				' AND host_ref='.zbx_dbstr($host['hostid'])));
			if ($existing) {
				DBexecute('UPDATE topo_nodes SET updated_at='.time().' WHERE id='.zbx_dbstr($existing['id']));
				$host_nodeid = $existing['id'];
			}
			else {
				DBexecute('INSERT INTO topo_nodes (type,host_ref,attrs,created_at,updated_at) VALUES ('.
					zbx_dbstr('host').','.zbx_dbstr($host['hostid']).','.zbx_dbstr('{}').','.time().','.time().')');
				$host_nodeid = DBfetch(DBselect('SELECT id FROM topo_nodes WHERE type='.zbx_dbstr('host').
					' AND host_ref='.zbx_dbstr($host['hostid'])))['id'];
			}
			self::reconcileHost($host_nodeid, $host['interfaces']);
			$count++;
		}

		return $count;
	}

	private static function isRepresented(string $deviceid): bool {
		return (bool) DBfetch(DBselect('SELECT id FROM topo_edges WHERE type='.zbx_dbstr('represented_by').
			' AND src_id='.zbx_dbstr($deviceid), 1));
	}

	private static function getLinkedDeviceName(string $interfaceid): ?string {
		$row = DBfetch(DBselect('SELECT device.attrs FROM topo_edges link JOIN topo_edges part_of ON part_of.type='.zbx_dbstr('part_of').
			' AND part_of.src_id=CASE WHEN link.src_id='.zbx_dbstr($interfaceid).' THEN link.dst_id ELSE link.src_id END'.
			' JOIN topo_nodes device ON device.id=part_of.dst_id WHERE link.type='.zbx_dbstr('physical_link').
			' AND (link.src_id='.zbx_dbstr($interfaceid).' OR link.dst_id='.zbx_dbstr($interfaceid).')', 1));
		return $row ? self::attrs($row)['sysname'] : null;
	}

	private static function reconcileHost(string $host_nodeid, array $interfaces): void {
		foreach ($interfaces as $host_interface) {
			if (empty($host_interface['mac'])) {
				continue;
			}
			$mac = strtolower($host_interface['mac']);
			$result = DBselect('SELECT part_of.dst_id FROM topo_nodes interface JOIN topo_edges part_of ON part_of.src_id=interface.id'.
				' WHERE interface.type='.zbx_dbstr('interface').' AND interface.attrs LIKE '.zbx_dbstr('%"mac":"'.$mac.'"%'));
			while ($device = DBfetch($result)) {
				self::promote($device['dst_id'], $host_nodeid);
			}
		}
	}
}