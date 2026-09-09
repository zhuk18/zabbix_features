<?php

class CTopologyPrototype {

	private static function attrs(array $row): array {
		return json_decode($row['attrs'], true, 512, JSON_THROW_ON_ERROR);
	}

	public static function getDevices(): array {
		$nodes = [];
		$result = DBselect('SELECT node.id,node.type,node.attrs,node.host_ref,'.
				'host.name AS host_name,host.status AS host_status,host.maintenance_status AS host_maintenance_status,'.
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
					// §7: a disabled host isn't polled, so it gets neutral/muted styling, never a severity
					// color (not even "ok") — skip the severity lookup entirely rather than compute one that
					// would be shown. Maintenance is a *different* state: still monitored, keeps its real
					// severity color, just adds a badge — the two must not be conflated.
					$node['name'] = $row['host_name'];
					$node['monitoring_state'] = $row['host_status'];
					$node['disabled'] = ((int) $row['host_status']) === HOST_STATUS_NOT_MONITORED;
					$node['maintenance'] = ((int) $row['host_maintenance_status']) === HOST_MAINTENANCE_STATUS_ON;
					$node['blind_spot'] = $row['mon_id'] !== null && !self::isProxyOnline($row['mon_proxy_state']);
					$node += self::describeSeverity($node['disabled'] ? null : self::getMaxActiveSeverityForHost($row['host_ref']));
					break;

				case 'proxy':
					// §7: Proxy shares Host's "monitored, has severity" styling — resolved via the
					// zabbix.proxy.*[name] internal item convention, see getProxyHealthItemIds().
					$node['name'] = $row['proxy_name'];
					$node['unreachable'] = !self::isProxyOnline($row['proxy_state']);
					$node['represented'] = self::isRepresentedTarget($row['id']);
					$node += self::describeSeverity(self::getMaxActiveSeverityForProxy($row['proxy_name']));
					break;
			}

			$nodes[] = $node;
		}

		return $nodes;
	}

	public static function getUnassignedHosts(): array {
		$nodes = [];
		$result = DBselect('SELECT node.id,node.host_ref,host.name AS host_name,host.status AS host_status'.
			' FROM topo_nodes node JOIN hosts host ON host.hostid=node.host_ref'.
			' LEFT JOIN topo_edges rep ON rep.type='.zbx_dbstr('represented_by').' AND rep.dst_id=node.id'.
			' WHERE node.type='.zbx_dbstr('host').' AND rep.id IS NULL ORDER BY host.name');

		while ($row = DBfetch($result)) {
			$nodes[] = [
				'id' => $row['id'], 'type' => 'host', 'name' => $row['host_name'],
				// Raw Zabbix hostid alongside the topo_nodes pointer id — lets a caller that just created a host
				// via the API (and knows its hostid, not this pointer id) find its match after a pull without
				// relying on the name staying exactly as it was when the host was first created.
				'hostid' => $row['host_ref'],
				'monitoring_state' => $row['host_status']
			];
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
		// No DISTINCT here (unlike before the severity field existed): a neighbor reached via more than one
		// physical_link (e.g. redundant cabling) needs every one of those links' port pairs collected so the
		// severity aggregation below sees every relevant port, not just whichever row happened to come first.
		$result = DBselect(
			'SELECT device.id,device.type,device.attrs,link.attrs AS link_attrs,local_part.src_id AS local_port_id,'.
				'remote_part.src_id AS remote_port_id'.
			' FROM topo_edges local_part'.
			' JOIN topo_edges link ON link.type='.zbx_dbstr('physical_link').
				' AND (link.src_id=local_part.src_id OR link.dst_id=local_part.src_id)'.
			' JOIN topo_edges remote_part ON remote_part.type='.zbx_dbstr('part_of').
				' AND remote_part.src_id=CASE WHEN link.src_id=local_part.src_id THEN link.dst_id ELSE link.src_id END'.
			' JOIN topo_nodes device ON device.id=remote_part.dst_id AND device.type='.zbx_dbstr('device').
			' WHERE local_part.type='.zbx_dbstr('part_of').' AND local_part.dst_id='.zbx_dbstr($deviceid)
		);

		$neighbors = [];
		$port_ids = [];
		$discovered_via = [];
		while ($row = DBfetch($result)) {
			if (!array_key_exists($row['id'], $neighbors)) {
				$attrs = self::attrs($row);
				$neighbors[$row['id']] = ['id' => $row['id'], 'type' => 'device', 'name' => $attrs['sysname'],
					'monitoring_state' => null, 'represented' => self::isRepresented($row['id'])];
				$port_ids[$row['id']] = [];
				$discovered_via[$row['id']] = 'manual';
			}
			$port_ids[$row['id']][] = $row['local_port_id'];
			$port_ids[$row['id']][] = $row['remote_port_id'];

			// A neighbor can be reached by more than one physical_link (e.g. one manually declared, one
			// LLDP-discovered via a different port pair). Same "most-confirmed wins" precedent as severity's
			// "highest wins" below: if any one of them is LLDP-confirmed, render the neighbor link as such.
			$link_attrs = json_decode($row['link_attrs'], true, 512, JSON_THROW_ON_ERROR);
			if (($link_attrs['discovered_via'] ?? 'lldp') === 'lldp') {
				$discovered_via[$row['id']] = 'lldp';
			}
		}

		foreach ($neighbors as $id => &$neighbor) {
			$neighbor += self::describeSeverity(self::getMaxActiveSeverityForPorts($port_ids[$id]));
			$neighbor['discovered_via'] = $discovered_via[$id];
		}
		unset($neighbor);

		return array_values($neighbors);
	}

	// §6: /topo/nodes/{id}/problems — id is a Host or Proxy node id (never a Device id). Proxy nodes use this
	// too (§7's Proxy severity/panel), resolved via getProblemsForProxy()'s item-key convention below.
	public static function getProblems(string $nodeid): array {
		// DBfetch(..., false) — see GOTCHAS.md #1: with the default $convertNulls=true, a Device node's NULL
		// host_ref/proxy_ref would come back as the string '0' instead of null, defeating these checks.
		$node = DBfetch(DBselect('SELECT node.type,node.host_ref,node.proxy_ref,proxy.name AS proxy_name'.
			' FROM topo_nodes node LEFT JOIN proxy ON proxy.proxyid=node.proxy_ref WHERE node.id='.zbx_dbstr($nodeid)), false);
		if (!$node) {
			return [];
		}
		if ($node['type'] === 'host' && $node['host_ref'] !== null) {
			return self::getProblemsForHost($node['host_ref']);
		}
		if ($node['type'] === 'proxy' && $node['proxy_ref'] !== null) {
			return self::getProblemsForProxy($node['proxy_name']);
		}

		return [];
	}

	private static function getProblemsForHost(string $hostid): array {
		$problems = [];
		foreach (API::Problem()->get([
			'output' => ['eventid', 'name', 'severity', 'clock'],
			'hostids' => [$hostid],
			'sortfield' => ['eventid'],
			'sortorder' => ZBX_SORT_DOWN
		]) as $problem) {
			$problems[] = array_merge([
				'eventid' => $problem['eventid'],
				'name' => $problem['name'],
				'age' => time() - (int) $problem['clock']
			], self::describeSeverity((int) $problem['severity']));
		}

		return $problems;
	}

	private static function getProblemsForProxy(string $proxy_name): array {
		$triggerids = self::getProxyHealthTriggerIds($proxy_name);
		if (!$triggerids) {
			return [];
		}

		$problems = [];
		foreach (API::Problem()->get([
			'output' => ['eventid', 'name', 'severity', 'clock'],
			'objectids' => $triggerids,
			'sortfield' => ['eventid'],
			'sortorder' => ZBX_SORT_DOWN
		]) as $problem) {
			$problems[] = array_merge([
				'eventid' => $problem['eventid'],
				'name' => $problem['name'],
				'age' => time() - (int) $problem['clock']
			], self::describeSeverity((int) $problem['severity']));
		}

		return $problems;
	}

	public static function getPorts(string $deviceid): array {
		$groups = [
			'connected_lldp' => [], 'connected_mac_only' => [], 'disconnected' => [], 'port_channel' => [], 'management' => []
		];
		$result = DBselect(
			'SELECT port.id,port.attrs,link.id AS linkid,link.attrs AS link_attrs,'.
				'CASE WHEN link.src_id=port.id THEN link.dst_id ELSE link.src_id END AS linked_port_id'.
			' FROM topo_edges part_of JOIN topo_nodes port ON port.id=part_of.src_id'.
			' LEFT JOIN topo_edges link ON link.type='.zbx_dbstr('physical_link').
				' AND (link.src_id=port.id OR link.dst_id=port.id)'.
			' WHERE part_of.type='.zbx_dbstr('part_of').' AND part_of.dst_id='.zbx_dbstr($deviceid).
			' ORDER BY port.id'
		);

		// DBfetch(..., false) — see GOTCHAS.md #1: with the default $convertNulls=true, a disconnected/mgmt
		// port's unmatched linkid came back as '0' instead of null, so `linkid !== null` was always true and
		// every port (management ports included) was misreported as LLDP-connected.
		while ($row = DBfetch($result, false)) {
			$attrs = self::attrs($row);
			$discovered_via = $row['linkid'] !== null
				? (json_decode($row['link_attrs'], true, 512, JSON_THROW_ON_ERROR)['discovered_via'] ?? 'lldp')
				: null;

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
				'id' => $row['id'], 'port' => $attrs['name'], 'status' => $attrs['oper_status'],
				'connected_to' => $row['linkid'] !== null ? self::getLinkedDeviceName($row['id']) : null,
				'linked_port_id' => $row['linkid'] !== null ? $row['linked_port_id'] : null,
				'source' => $discovered_via === 'manual' ? 'Manual' : ($row['linkid'] !== null ? 'LLDP'
					: ($group === 'connected_mac_only' ? 'MAC only' : '-'))
			];
		}

		return $groups;
	}

	public static function linkPorts(string $src_port_id, string $dst_port_id): void {
		if ($src_port_id === $dst_port_id) {
			throw new Exception('A port cannot be linked to itself.');
		}

		self::assertPortOnDevice($src_port_id);
		self::assertPortOnDevice($dst_port_id);
		self::upsertPhysicalLink($src_port_id, $dst_port_id, 'manual');
	}

	public static function unlinkPorts(string $src_port_id, string $dst_port_id): void {
		DBexecute('DELETE FROM topo_edges WHERE type='.zbx_dbstr('physical_link').
			' AND ((src_id='.zbx_dbstr($src_port_id).' AND dst_id='.zbx_dbstr($dst_port_id).
			') OR (src_id='.zbx_dbstr($dst_port_id).' AND dst_id='.zbx_dbstr($src_port_id).'))');
	}

	// §2.1: represented_by is 1:1 on both ends. This never replaces an existing edge on either side — the
	// caller must depromote() first. $match_type is 'manual' for the user-triggered /promote endpoint (no MAC
	// validation happens here, or ever — promotion is a deliberate user action per §3.2/§6, independent of the
	// automatic reconciliation in reconcileHost()) vs. 'identity' when reconcileHost() calls this after a real
	// match. $matched_by/$matched_mac are only meaningful for 'identity' (§2.3's attrs table: matched_by is
	// "mac"|"chassis_id"|"manual" — for a manual promotion matched_by is always the literal string 'manual').
	public static function promote(string $deviceid, string $hostid, string $match_type = 'manual',
			?string $matched_by = null, ?string $matched_mac = null): void {
		if (self::isRepresented($deviceid)) {
			throw new Exception('This device is already represented by a host or proxy — depromote it first.');
		}
		if (self::isRepresentedTarget($hostid)) {
			throw new Exception('This host/proxy is already represented by a device — depromote it first.');
		}

		DBexecute('INSERT INTO topo_edges (type,src_id,dst_id,attrs,created_at) VALUES ('.
			zbx_dbstr('represented_by').','.zbx_dbstr($deviceid).','.zbx_dbstr($hostid).','.zbx_dbstr(json_encode([
				'match_type' => $match_type, 'matched_by' => $match_type === 'identity' ? $matched_by : 'manual',
				'matched_mac' => $matched_mac, 'created_at' => time()
			])).','.time().')');
	}

	public static function depromote(string $deviceid): void {
		DBexecute('DELETE FROM topo_edges WHERE type='.zbx_dbstr('represented_by').' AND src_id='.zbx_dbstr($deviceid));
	}

	public static function pullHosts(): int {
		self::pullProxies();

		$count = 0;
		foreach (API::Host()->get([
			'output' => ['hostid', 'proxyid', 'monitored_by'],
			'selectInventory' => ['macaddress_a', 'macaddress_b', 'chassis']
		]) as $host) {
			$host_nodeid = self::upsertPointerNode('host', 'host_ref', $host['hostid']);
			self::reconcileHost($host_nodeid, $host['inventory'] ?? []);

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
		// Reconciliation against Device nodes (§3.2, MAC-based) is intentionally not attempted here: unlike
		// Host (which has host_inventory.macaddress_a/b, see reconcileHost()), CProxy::get() exposes no
		// MAC/interface/inventory data at all — there is no source to reconcile a Proxy against.
		foreach (API::Proxy()->get(['output' => ['proxyid']]) as $proxy) {
			self::upsertPointerNode('proxy', 'proxy_ref', $proxy['proxyid']);
			$count++;
		}

		return $count;
	}

	// linkPorts()'s whole job is linking, never creating identity — reject a port id that doesn't exist, or
	// that exists but isn't part_of a Device, rather than silently creating anything as a side effect.
	private static function assertPortOnDevice(string $port_id): void {
		$port = DBfetch(DBselect('SELECT node.id FROM topo_nodes node'.
			' JOIN topo_edges part_of ON part_of.type='.zbx_dbstr('part_of').' AND part_of.src_id=node.id'.
			' JOIN topo_nodes device ON device.id=part_of.dst_id AND device.type='.zbx_dbstr('device').
			' WHERE node.id='.zbx_dbstr($port_id).' AND node.type='.zbx_dbstr('port'), 1));

		if (!$port) {
			throw new Exception('Port '.$port_id.' does not exist or is not attached to a device.');
		}
	}

	// Shared by the manual /link endpoint (discovered_via='manual') and by discovery/seed reconciliation
	// (discovered_via='lldp') so the same two ports never end up with two physical_link edges between them.
	// A 'manual' call on an existing edge never downgrades it — only 'lldp' is allowed to upgrade a prior
	// 'manual' edge, and discovery must never delete a manual link on its own (§3.5); the DELETE endpoint
	// (unlinkPorts) is the only thing that removes a link, of either provenance.
	private static function upsertPhysicalLink(string $port_a, string $port_b, string $discovered_via): void {
		// §5: canonicalize direction (numerically smaller port id always src_id) so A→B and B→A collapse to
		// the same row — both for the lookup below and for the DB-level unique index on (src_id, dst_id)
		// WHERE type='physical_link' (mysql_migrate_uniqueness.sql), which only catches duplicates that agree
		// on direction.
		if ((int) $port_a > (int) $port_b) {
			[$port_a, $port_b] = [$port_b, $port_a];
		}

		$existing = DBfetch(DBselect('SELECT id,attrs FROM topo_edges WHERE type='.zbx_dbstr('physical_link').
			' AND src_id='.zbx_dbstr($port_a).' AND dst_id='.zbx_dbstr($port_b), 1));

		if ($existing) {
			$attrs = self::attrs($existing);
			if ($discovered_via === 'lldp' && ($attrs['discovered_via'] ?? null) !== 'lldp') {
				$attrs['discovered_via'] = 'lldp';
				$attrs['last_seen'] = time();
				DBexecute('UPDATE topo_edges SET attrs='.zbx_dbstr(json_encode($attrs)).' WHERE id='.zbx_dbstr($existing['id']));
			}
			return;
		}

		DBexecute('INSERT INTO topo_edges (type,src_id,dst_id,attrs,created_at) VALUES ('.
			zbx_dbstr('physical_link').','.zbx_dbstr($port_a).','.zbx_dbstr($port_b).','.
			zbx_dbstr(json_encode(['discovered_via' => $discovered_via, 'last_seen' => time()])).','.time().')');
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

	// Resolves the highest-severity *active* trigger (value=TRIGGER_VALUE_TRUE, i.e. currently in problem
	// state) attached to any of zabbix_itemids across the given ports — oper_status and trigger severity are
	// deliberately kept apart (§5): a port can be oper_status=up and still carry an active trigger.
	// Node-level severity for a Host on the graph itself (spec §7: "Host: solid fill, colored by Zabbix
	// severity/status") — the worst of its own active problems, same source as getProblems() but only the max.
	private static function getMaxActiveSeverityForHost(string $hostid): ?int {
		$max_severity = null;
		foreach (API::Problem()->get(['output' => ['severity'], 'hostids' => [$hostid]]) as $problem) {
			$severity = (int) $problem['severity'];
			if ($max_severity === null || $severity > $max_severity) {
				$max_severity = $severity;
			}
		}

		return $max_severity;
	}

	private static function getMaxActiveSeverityForPorts(array $port_ids): ?int {
		$port_ids = array_values(array_unique(array_filter($port_ids, static function($id) {
			return $id !== null;
		})));
		if (!$port_ids) {
			return null;
		}

		$itemids = [];
		$result = DBselect('SELECT attrs FROM topo_nodes WHERE '.dbConditionId('id', $port_ids).
			' AND type='.zbx_dbstr('port'));
		while ($row = DBfetch($result)) {
			foreach (self::attrs($row)['zabbix_itemids'] ?? [] as $itemid) {
				$itemids[] = $itemid;
			}
		}

		return self::getMaxActiveSeverityForItemIds(array_values(array_unique($itemids)));
	}

	// §7: Proxy shares Host's severity-colored fill, but Zabbix has no proxyid linkage on problem/trigger/item
	// to resolve it from — see getProxyHealthTriggerIds() for how this is actually found.
	private static function getMaxActiveSeverityForProxy(string $proxy_name): ?int {
		return self::getMaxActiveSeverityForItemIds(self::getProxyHealthItemIds($proxy_name));
	}

	private static function getMaxActiveSeverityForItemIds(array $itemids): ?int {
		if (!$itemids) {
			return null;
		}

		$max_severity = null;
		foreach (API::Trigger()->get([
			'output' => ['priority'],
			'itemids' => $itemids,
			'filter' => ['value' => TRIGGER_VALUE_TRUE]
		]) as $trigger) {
			$priority = (int) $trigger['priority'];
			if ($max_severity === null || $priority > $max_severity) {
				$max_severity = $priority;
			}
		}

		return $max_severity;
	}

	private static function getProxyHealthTriggerIds(string $proxy_name): array {
		$itemids = self::getProxyHealthItemIds($proxy_name);
		if (!$itemids) {
			return [];
		}

		return array_column(API::Trigger()->get(['output' => ['triggerid'], 'itemids' => $itemids]), 'triggerid');
	}

	// Zabbix has no proxyid column on problem/trigger/item — a Proxy's own health problems are only reachable
	// via the well-known internal item key convention 'zabbix.proxy.<metric>[<proxy name>]' (e.g.
	// zabbix.proxy.last_seen[Riga proxy]), the same keys the stock "Zabbix proxy health" template uses,
	// interpolating the proxy's exact name as the key parameter. This is a real, well-defined Zabbix
	// convention, not a fuzzy/weak-key guess — but it only finds anything if some host in the instance has
	// actually been set up to monitor this specific proxy via that convention; there's no structural guarantee
	// one exists, same opportunistic caveat as MAC/chassis reconciliation in reconcileHost().
	private static function getProxyHealthItemIds(string $proxy_name): array {
		$needle = '['.$proxy_name.']';
		$itemids = [];
		foreach (API::Item()->get([
			'output' => ['itemid', 'key_'],
			'search' => ['key_' => 'zabbix.proxy.'],
			'startSearch' => true
		]) as $item) {
			if (substr($item['key_'], -strlen($needle)) === $needle) {
				$itemids[] = $item['itemid'];
			}
		}

		return $itemids;
	}

	private static function describeSeverity(?int $severity): array {
		return [
			'severity' => $severity,
			'severity_name' => $severity !== null ? CSeverityHelper::getName($severity) : null,
			'color' => $severity !== null ? CSeverityHelper::getColor($severity) : null
		];
	}

	private static function isRepresented(string $deviceid): bool {
		return (bool) DBfetch(DBselect('SELECT id FROM topo_edges WHERE type='.zbx_dbstr('represented_by').
			' AND src_id='.zbx_dbstr($deviceid), 1));
	}

	private static function isRepresentedTarget(string $target_id): bool {
		return (bool) DBfetch(DBselect('SELECT id FROM topo_edges WHERE type='.zbx_dbstr('represented_by').
			' AND dst_id='.zbx_dbstr($target_id), 1));
	}

	private static function getLinkedDeviceName(string $portid): ?string {
		$row = DBfetch(DBselect('SELECT device.attrs FROM topo_edges link JOIN topo_edges part_of ON part_of.type='.zbx_dbstr('part_of').
			' AND part_of.src_id=CASE WHEN link.src_id='.zbx_dbstr($portid).' THEN link.dst_id ELSE link.src_id END'.
			' JOIN topo_nodes device ON device.id=part_of.dst_id WHERE link.type='.zbx_dbstr('physical_link').
			' AND (link.src_id='.zbx_dbstr($portid).' OR link.dst_id='.zbx_dbstr($portid).')', 1));
		return $row ? self::attrs($row)['sysname'] : null;
	}

	// host.get's selectInterfaces never returns a 'mac' field (the Zabbix interface table only has
	// hostid/type/ip/dns/port/useip/main) — this can never match against a real Zabbix instance. The actual
	// MAC source is host_inventory.macaddress_a/macaddress_b (host.get selectInventory), populated only when
	// the host uses Automatic inventory mode with a system.hw.macaddr item — most hosts on a real instance
	// won't have it set, and an empty/missing value is a normal non-match, not an error, same as any other
	// unmatched host. §3.2's other strong key, chassis ID, is host_inventory.chassis vs. Device.attrs.chassis_id
	// — a Device-level attribute, so this branch matches against Device rows directly, not via Port like MAC
	// does. The same gap applies to Proxy for both keys (CProxy::get has no interface/inventory concept at
	// all), so §5's "run Device reconciliation for each Proxy" is not implemented; see pullProxies() above.
	private static function reconcileHost(string $host_nodeid, array $inventory): void {
		foreach (['macaddress_a', 'macaddress_b'] as $field) {
			if (empty($inventory[$field])) {
				continue;
			}
			$mac = strtolower($inventory[$field]);
			$result = DBselect('SELECT part_of.dst_id FROM topo_nodes port JOIN topo_edges part_of ON part_of.src_id=port.id'.
				' WHERE port.type='.zbx_dbstr('port').' AND port.attrs LIKE '.zbx_dbstr('%"mac":"'.$mac.'"%'));
			while ($device = DBfetch($result)) {
				try {
					self::promote($device['dst_id'], $host_nodeid, 'identity', 'mac', $mac);
				}
				catch (Exception $exception) {
					// §2.1's 1:1 constraint applies here too, not just to the manual endpoint: the device or
					// this host is already represented elsewhere. Skip this candidate — it's a normal "no
					// match" outcome for automatic reconciliation, not a failure worth aborting the pull over.
					continue;
				}
			}
		}

		if (!empty($inventory['chassis'])) {
			$result = DBselect('SELECT id FROM topo_nodes WHERE type='.zbx_dbstr('device').
				' AND attrs LIKE '.zbx_dbstr('%"chassis_id":"'.$inventory['chassis'].'"%'));
			while ($device = DBfetch($result)) {
				try {
					self::promote($device['id'], $host_nodeid, 'identity', 'chassis_id');
				}
				catch (Exception $exception) {
					continue;
				}
			}
		}
	}
}