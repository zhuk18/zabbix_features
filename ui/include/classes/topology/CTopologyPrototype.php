<?php

class CTopologyPrototype {

	private static function attrs(array $row): array {
		return json_decode($row['attrs'], true, 512, JSON_THROW_ON_ERROR);
	}

	public static function getDevices(): array {
		$nodes = [];
		$result = DBselect('SELECT node.id,node.type,node.attrs,'.
				'host.name AS host_name,host.status AS host_status,'.
				'proxy.name AS proxy_name,proxy_rt.state AS proxy_state,'.
				'mon.id AS mon_id,mon_proxy_rt.state AS mon_proxy_state'.
			' FROM topo_nodes node'.
			' LEFT JOIN hosts host ON host.hostid=node.host_ref'.
			' LEFT JOIN proxy ON proxy.proxyid=node.proxy_ref'.
			' LEFT JOIN proxy_rtdata proxy_rt ON proxy_rt.proxyid=proxy.proxyid'.
			' LEFT JOIN topo_edges rep ON rep.type='.zbx_dbstr('represented_by').' AND rep.dst_id=node.id'.
			' LEFT JOIN topo_edges mon ON mon.type='.zbx_dbstr('monitored_by').' AND mon.src_id=node.id'.
			' LEFT JOIN topo_nodes mon_proxy ON mon_proxy.id=mon.dst_id'.
			' LEFT JOIN proxy_rtdata mon_proxy_rt ON mon_proxy_rt.proxyid=mon_proxy.proxy_ref'.
			' WHERE node.type='.zbx_dbstr('device').
				' OR (node.type='.zbx_dbstr('host').' AND host.hostid IS NOT NULL AND rep.id IS NOT NULL)'.
				' OR (node.type='.zbx_dbstr('proxy').' AND proxy.proxyid IS NOT NULL AND rep.id IS NOT NULL)');

		// DBfetch()'s default $convertNulls=true turns every unmatched LEFT JOIN column (mon.id included)
		// into the string '0' instead of leaving it null, which would make the blind_spot check below always
		// true. Pass false here to keep real SQL NULLs distinguishable from an actual '0' id/state value.
		while ($row = DBfetch($result, false)) {
			$attrs = self::attrs($row);
			$node = ['id' => $row['id'], 'type' => $row['type'], 'monitoring_state' => null];

			switch ($row['type']) {
				case 'device':
					$node['name'] = $attrs['sysname'];
					$node['represented'] = self::isRepresented($row['id']);
					break;

				case 'host':
					$node['name'] = $row['host_name'];
					$node['monitoring_state'] = $row['host_status'];
					$node['blind_spot'] = $row['mon_id'] !== null && !self::isProxyOnline($row['mon_proxy_state']);
					break;

				case 'proxy':
					$node['name'] = $row['proxy_name'];
					$node['unreachable'] = !self::isProxyOnline($row['proxy_state']);
					break;
			}

			$nodes[] = $node;
		}

		return $nodes;
	}

	public static function getUnassignedHosts(): array {
		$nodes = [];
		$result = DBselect('SELECT node.id,host.name AS host_name,host.status AS host_status'.
			' FROM topo_nodes node JOIN hosts host ON host.hostid=node.host_ref'.
			' LEFT JOIN topo_edges rep ON rep.type='.zbx_dbstr('represented_by').' AND rep.dst_id=node.id'.
			' WHERE node.type='.zbx_dbstr('host').' AND rep.id IS NULL ORDER BY host.name');

		while ($row = DBfetch($result)) {
			$nodes[] = ['id' => $row['id'], 'type' => 'host', 'name' => $row['host_name'], 'monitoring_state' => $row['host_status']];
		}

		return $nodes;
	}

	public static function getUnassignedProxies(): array {
		$nodes = [];
		$result = DBselect('SELECT node.id,proxy.name AS proxy_name,proxy_rt.state AS proxy_state'.
			' FROM topo_nodes node JOIN proxy ON proxy.proxyid=node.proxy_ref'.
			' LEFT JOIN proxy_rtdata proxy_rt ON proxy_rt.proxyid=proxy.proxyid'.
			' LEFT JOIN topo_edges rep ON rep.type='.zbx_dbstr('represented_by').' AND rep.dst_id=node.id'.
			' WHERE node.type='.zbx_dbstr('proxy').' AND rep.id IS NULL ORDER BY proxy.name');

		while ($row = DBfetch($result)) {
			$nodes[] = [
				'id' => $row['id'], 'type' => 'proxy', 'name' => $row['proxy_name'],
				'unreachable' => !self::isProxyOnline($row['proxy_state'])
			];
		}

		return $nodes;
	}

	public static function getRelations(): array {
		$relations = [];
		$result = DBselect('SELECT src_id,dst_id,type FROM topo_edges WHERE type IN ('.
			zbx_dbstr('represented_by').','.zbx_dbstr('monitored_by').')');

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

	public static function depromote(string $deviceid): void {
		DBexecute('DELETE FROM topo_edges WHERE type='.zbx_dbstr('represented_by').' AND src_id='.zbx_dbstr($deviceid));
	}

	public static function pullHosts(): int {
		self::pullProxies();

		$count = 0;
		foreach (API::Host()->get(['output' => ['hostid', 'proxyid', 'monitored_by'], 'selectInterfaces' => 'extend']) as $host) {
			$host_nodeid = self::upsertPointerNode('host', 'host_ref', $host['hostid']);
			self::reconcileHost($host_nodeid, $host['interfaces']);

			// hosts.proxyid can be non-zero even when monitored_by=ZBX_MONITORED_BY_SERVER (a stale/leftover
			// value from a past proxy assignment) — monitored_by is the actual source of truth for who's
			// polling the host, not the presence of a proxyid.
			if ((int) $host['monitored_by'] === ZBX_MONITORED_BY_PROXY) {
				$proxy_nodeid = self::upsertPointerNode('proxy', 'proxy_ref', $host['proxyid']);
				self::linkMonitoredBy($host_nodeid, $proxy_nodeid);
			}
			else {
				// Re-pulling must also retract a stale edge left over from a previous pull, e.g. a host that
				// was switched from proxy-monitored back to server-monitored in Zabbix since the last pull.
				self::unlinkMonitoredBy($host_nodeid);
			}

			$count++;
		}

		return $count;
	}

	public static function pullProxies(): int {
		$count = 0;
		// Reconciliation against Device nodes (§3.2, MAC-based) is intentionally not attempted here — see note
		// on reconcileHost() below; the Zabbix API exposes no MAC/interface data for either Host or Proxy objects.
		foreach (API::Proxy()->get(['output' => ['proxyid']]) as $proxy) {
			self::upsertPointerNode('proxy', 'proxy_ref', $proxy['proxyid']);
			$count++;
		}

		return $count;
	}

	private static function upsertPointerNode(string $type, string $ref_column, string $ref_value): string {
		$existing = DBfetch(DBselect('SELECT id FROM topo_nodes WHERE type='.zbx_dbstr($type).
			' AND '.$ref_column.'='.zbx_dbstr($ref_value)));
		if ($existing) {
			DBexecute('UPDATE topo_nodes SET updated_at='.time().' WHERE id='.zbx_dbstr($existing['id']));
			return $existing['id'];
		}

		DBexecute('INSERT INTO topo_nodes (type,'.$ref_column.',attrs,created_at,updated_at) VALUES ('.
			zbx_dbstr($type).','.zbx_dbstr($ref_value).','.zbx_dbstr('{}').','.time().','.time().')');
		return DBfetch(DBselect('SELECT id FROM topo_nodes WHERE type='.zbx_dbstr($type).
			' AND '.$ref_column.'='.zbx_dbstr($ref_value)))['id'];
	}

	private static function linkMonitoredBy(string $host_nodeid, string $proxy_nodeid): void {
		$existing = DBfetch(DBselect('SELECT id FROM topo_edges WHERE type='.zbx_dbstr('monitored_by').
			' AND src_id='.zbx_dbstr($host_nodeid).' AND dst_id='.zbx_dbstr($proxy_nodeid)));
		if ($existing) {
			return;
		}

		// A host can only be monitored by one proxy at a time, so re-pointing to a different proxy must
		// replace the old edge, not add a second one.
		self::unlinkMonitoredBy($host_nodeid);
		DBexecute('INSERT INTO topo_edges (type,src_id,dst_id,attrs,created_at) VALUES ('.
			zbx_dbstr('monitored_by').','.zbx_dbstr($host_nodeid).','.zbx_dbstr($proxy_nodeid).','.zbx_dbstr('{}').','.time().')');
	}

	private static function unlinkMonitoredBy(string $host_nodeid): void {
		DBexecute('DELETE FROM topo_edges WHERE type='.zbx_dbstr('monitored_by').' AND src_id='.zbx_dbstr($host_nodeid));
	}

	private static function isProxyOnline($proxy_state): bool {
		return $proxy_state !== null && (int) $proxy_state === ZBX_PROXY_STATE_ONLINE;
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

	// NOTE: host.get's selectInterfaces never returns a 'mac' field (the interface table only has
	// hostid/type/ip/dns/port/useip/main) — this MAC lookup can never match against a real Zabbix instance.
	// The same gap applies to Proxy (CProxy::get has no interface/MAC concept at all), so §5's "run Device
	// reconciliation for each Proxy" is not implemented; see pullProxies() above.
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