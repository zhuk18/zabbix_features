<?php

class CTopologyPrototype {

	private static function attrs(array $row): array {
		return json_decode($row['attrs'], true, 512, JSON_THROW_ON_ERROR);
	}

	// §7's physical_link staleness indicator: a starting-point threshold, not tuned against real
	// data yet (spec §7's own note on this). Computed server-side (both here and by every caller)
	// so the raw threshold never needs to ship to the client — the client only ever sees the
	// already-derived boolean.
	private const STALE_LINK_SECONDS = 7 * 24 * 60 * 60;

	private static function isLinkStale(?int $last_seen): bool {
		return $last_seen === null || (time() - $last_seen) > self::STALE_LINK_SECONDS;
	}

	/**
	 * Every hostid the CURRENTLY LOGGED-IN user has read access to. Not a filter someone chose —
	 * a floor that always applies, the same way every other Zabbix page (Problems, Latest data,
	 * host lists) implicitly scopes to the caller's permitted host groups. API::Host()->get()
	 * without an 'editable' flag enforces exactly that (a Super Admin gets everything, back to
	 * today's behavior for that role; anyone else gets only what they're actually allowed to
	 * see) — unlike the raw `hosts` table joins elsewhere in this class, which enforce nothing at
	 * all and were the actual bug: a restricted user saw every host on the map regardless of
	 * their real Zabbix permissions, same underlying gap the group-filter path in resolveScope()
	 * already avoided by going through this exact API call for its OWN, optional scoping.
	 */
	private static function getVisibleHostIds(): array {
		return array_column(API::Host()->get(['output' => ['hostid']]), 'hostid');
	}

	/**
	 * @param array|null $node_ids  restrict to these topo_nodes.id values (hostgroup/hop scope
	 *                              from CTopologyHopScope::neighborhood()); null = unrestricted,
	 *                              today's default behavior.
	 */
	public static function getDevices(?array $node_ids = null): array {
		$nodes = [];
		$visible_hostids = self::getVisibleHostIds();
		$sql = 'SELECT node.id,node.type,node.attrs,node.host_ref,'.
				'host.name AS host_name,host.status AS host_status,host.maintenance_status AS host_maintenance_status,'.
				'proxy.name AS proxy_name,proxy_rt.state AS proxy_state'.
			' FROM topo_nodes node'.
			' LEFT JOIN hosts host ON host.hostid=node.host_ref'.
			' LEFT JOIN proxy ON proxy.proxyid=node.proxy_ref'.
			' LEFT JOIN proxy_rtdata proxy_rt ON proxy_rt.proxyid=proxy.proxyid'.
			' LEFT JOIN topo_edges rep ON rep.type='.zbx_dbstr('represented_by').' AND rep.dst_id=node.id'.
			' WHERE ('.
				'node.type='.zbx_dbstr('device').
				' OR (node.type='.zbx_dbstr('host').' AND host.hostid IS NOT NULL AND rep.id IS NOT NULL'.
					' AND '.($visible_hostids ? dbConditionId('host.hostid', $visible_hostids) : '1=0').')'.
				' OR (node.type='.zbx_dbstr('proxy').' AND proxy.proxyid IS NOT NULL AND rep.id IS NOT NULL)'.
			')';
		if ($node_ids !== null) {
			// Empty scope (e.g. a host group with no members) must return no nodes, not every
			// node — spelled out explicitly rather than relying on dbConditionId()'s behavior
			// for an empty array.
			$sql .= $node_ids ? ' AND '.dbConditionId('node.id', $node_ids) : ' AND 1=0';
		}
		$result = DBselect($sql);

		// 'represented'/'represented_hostid' for device rows are patched in AFTER this loop
		// (batched below) rather than resolved inline per row — one isRepresented() call per
		// device was itself a lingering N+1 here, same class of fix as getNeighbors()/getPorts()
		// got earlier; adding the hostid lookup the same naive way would have doubled it.
		$device_ids = [];
		$host_rows = [];
		while ($row = DBfetch($result)) {
			$attrs = self::attrs($row);
			$node = ['id' => $row['id'], 'type' => $row['type'], 'monitoring_state' => null];

			switch ($row['type']) {
				case 'device':
					$node['name'] = $attrs['sysname'];
					$device_ids[] = $row['id'];
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
					$node += self::describeSeverity($node['disabled'] ? null : self::getMaxActiveSeverityForHost($row['host_ref']));
					// §2.3/§6: monitoring assignment is resolved live below (batched), never from a stored
					// edge — 'blind_spot'/'monitored_by'/etc. are patched onto this node after the loop.
					$host_rows[$row['id']] = $row['host_ref'];
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

		$represented_ids = self::getRepresentedIds($device_ids);
		$representing_hostids = self::getRepresentingHostIds($device_ids, $visible_hostids);
		// §2.3/§6: one batched host.get call for every Host node's live monitoring assignment —
		// never one call per host (§10 point 3). $host_rows maps topo_nodes.id => hostid.
		$assignments = self::getMonitoringAssignments(array_values($host_rows));
		foreach ($nodes as &$node) {
			if ($node['type'] === 'device') {
				$node['represented'] = isset($represented_ids[$node['id']]);
				// Which host to scope the "attach item" picker to on the Link details panel —
				// null when unrepresented, represented by a proxy (proxies have no items), or
				// the representing host isn't visible to this caller.
				$node['represented_hostid'] = $representing_hostids[$node['id']] ?? null;
			}
			elseif ($node['type'] === 'host') {
				$hostid = $host_rows[$node['id']];
				$assignment = $assignments[$hostid] ?? null;
				$node['monitored_by'] = $assignment['monitored_by'] ?? ZBX_MONITORED_BY_SERVER;
				$node['proxyid'] = $assignment['proxyid'] ?? null;
				$node['proxy_groupid'] = $assignment['proxy_groupid'] ?? null;
				$node['assigned_proxyid'] = $assignment['assigned_proxyid'] ?? null;
				// Resolved topo_nodes.id of whichever proxy actually applies (proxyid or
				// assigned_proxyid, depending on monitored_by) — spares the frontend from
				// replicating that branching itself (§6). Null when server-monitored, or when the
				// target proxy has no topo_nodes row yet (never pulled).
				$node['target_node_id'] = $assignment['target_node_id'] ?? null;
				$node['blind_spot'] = $assignment['blind_spot'] ?? false;
			}
		}
		unset($node);

		return $nodes;
	}

	/**
	 * Live-resolved Host->Proxy/ProxyGroup monitoring assignment (§2.3/§6) — replaces the old stored
	 * `monitored_by` edge entirely. One batched host.get() call for every hostid given, never one per
	 * host (§10 point 3).
	 *
	 * @param array $hostids  Zabbix hostids (not topo_nodes.id) to resolve.
	 *
	 * @return array hostid => ['monitored_by' => int, 'proxyid' => ?string, 'proxy_groupid' => ?string,
	 *               'assigned_proxyid' => ?string, 'target_node_id' => ?string, 'blind_spot' => bool].
	 */
	private static function getMonitoringAssignments(array $hostids): array {
		if (!$hostids) {
			return [];
		}

		$hosts = API::Host()->get([
			'output' => ['hostid', 'monitored_by', 'proxyid', 'proxy_groupid', 'assigned_proxyid'],
			'hostids' => $hostids
		]);

		$target_proxyids = [];
		foreach ($hosts as $host) {
			$target_proxyid = self::resolveTargetProxyId($host);
			if ($target_proxyid !== null) {
				$target_proxyids[$target_proxyid] = true;
			}
		}
		$target_proxyids = array_keys($target_proxyids);

		// topo_nodes.id for each target proxy — only exists once that Proxy has been pulled (§5); a
		// target proxy that was never pulled simply has no graph node to point the line/badge at yet.
		$node_by_proxyid = [];
		// Live proxy_rtdata state for each target proxy — independent of whether it has a topo_nodes
		// row, so blind_spot is correct even for a target proxy that was never pulled.
		$online_by_proxyid = [];
		if ($target_proxyids) {
			$result = DBselect('SELECT id,proxy_ref FROM topo_nodes WHERE type='.zbx_dbstr('proxy').
				' AND '.dbConditionId('proxy_ref', $target_proxyids));
			while ($row = DBfetch($result)) {
				$node_by_proxyid[$row['proxy_ref']] = $row['id'];
			}

			$result = DBselect('SELECT proxyid,state FROM proxy_rtdata WHERE '.
				dbConditionId('proxyid', $target_proxyids));
			while ($row = DBfetch($result)) {
				$online_by_proxyid[$row['proxyid']] = self::isProxyOnline($row['state']);
			}
		}

		$assignments = [];
		foreach ($hosts as $host) {
			$target_proxyid = self::resolveTargetProxyId($host);
			$assignments[$host['hostid']] = [
				'monitored_by' => (int) $host['monitored_by'],
				'proxyid' => $host['proxyid'] !== '0' ? $host['proxyid'] : null,
				'proxy_groupid' => $host['proxy_groupid'] !== '0' ? $host['proxy_groupid'] : null,
				'assigned_proxyid' => $host['assigned_proxyid'] ?: null,
				'target_node_id' => $target_proxyid !== null ? ($node_by_proxyid[$target_proxyid] ?? null) : null,
				'blind_spot' => $target_proxyid !== null && !($online_by_proxyid[$target_proxyid] ?? false)
			];
		}

		return $assignments;
	}

	// §2.3: proxy_groupid alone doesn't say which proxy is actually serving a proxy-group-monitored
	// host — assigned_proxyid (host.get's server-computed answer) is the one that matters there.
	private static function resolveTargetProxyId(array $host): ?string {
		return match ((int) $host['monitored_by']) {
			ZBX_MONITORED_BY_PROXY => $host['proxyid'] !== '0' ? $host['proxyid'] : null,
			ZBX_MONITORED_BY_PROXY_GROUP => $host['assigned_proxyid'] ?: null,
			default => null
		};
	}

	/**
	 * @param array $groupids  Zabbix host group ids to restrict to; [] = unrestricted (today's
	 *                         default). Only meaningful in hostgroup-filter mode — the tray isn't
	 *                         restricted by the focus-host+depth mode, since an unassigned host
	 *                         isn't a node in that graph to begin with and has no hop distance to
	 *                         speak of. Proxies have no host-group concept in Zabbix at all, so
	 *                         getUnassignedProxies() has no equivalent parameter.
	 */
	public static function getUnassignedHosts(array $groupids = []): array {
		$nodes = [];
		$sql = 'SELECT node.id,node.host_ref,host.name AS host_name,host.status AS host_status'.
			' FROM topo_nodes node JOIN hosts host ON host.hostid=node.host_ref'.
			' LEFT JOIN topo_edges rep ON rep.type='.zbx_dbstr('represented_by').' AND rep.dst_id=node.id'.
			' WHERE node.type='.zbx_dbstr('host').' AND rep.id IS NULL';
		// Permission floor, always applied — same as getDevices(); a raw `hosts` join enforces
		// nothing on its own, unlike the API call below.
		$visible_hostids = self::getVisibleHostIds();
		$sql .= $visible_hostids ? ' AND '.dbConditionId('host.hostid', $visible_hostids) : ' AND 1=0';
		if ($groupids) {
			// API::Host()->get() (not a raw hosts_groups join) for the same reason resolveScope()
			// uses it: this makes the group filter ACL-safe for free, consistent with how the
			// main graph's own group scoping already behaves. Redundant with the floor above for
			// a non-Super-Admin caller, but cheap, and keeps this branch correct on its own even
			// if the floor's implementation ever changes.
			$member_hostids = array_column(API::Host()->get([
				'output' => ['hostid'],
				'groupids' => $groupids
			]), 'hostid');
			$sql .= $member_hostids ? ' AND '.dbConditionId('host.hostid', $member_hostids) : ' AND 1=0';
		}
		$sql .= ' ORDER BY host.name';
		$result = DBselect($sql);

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

	/**
	 * @param array|null $node_ids  restrict to relations where BOTH ends are in this set; null
	 *                              = unrestricted, today's default behavior.
	 */
	public static function getRelations(?array $node_ids = null): array {
		$relations = [];
		// Permission floor, always applied: represented_by (device->host) is the only stored edge
		// type that ever touches a 'host' topo_node (monitoring assignment is resolved live from
		// /topo/devices instead — §2.3/§6, no stored edge to filter here), so excluding any row
		// whose src/dst is a host the caller can't see is enough — physical_link rows (added below)
		// are device-to-device only and never need this. dbConditionId(..., true) already renders
		// "exclude every host node" (1=1) when getVisibleHostIds() is empty, so no separate
		// empty-array branch is needed here the way getDevices()/getUnassignedHosts() need one for
		// their positive IN() case.
		$invisible_host_nodes = 'SELECT id FROM topo_nodes WHERE type='.zbx_dbstr('host').
			' AND '.dbConditionId('host_ref', self::getVisibleHostIds(), true);
		$sql = 'SELECT src_id,dst_id,type FROM topo_edges WHERE type='.zbx_dbstr('represented_by').
			' AND src_id NOT IN ('.$invisible_host_nodes.')'.
			' AND dst_id NOT IN ('.$invisible_host_nodes.')';
		if ($node_ids !== null) {
			$sql .= $node_ids
				? ' AND '.dbConditionId('src_id', $node_ids).' AND '.dbConditionId('dst_id', $node_ids)
				: ' AND 1=0';
		}
		$result = DBselect($sql);

		while ($row = DBfetch($result)) {
			$relations[] = ['source' => $row['src_id'], 'target' => $row['dst_id'], 'type' => $row['type']];
		}

		// Device-to-device physical_link pairs, so the canvas shows the actual LLDP/manual wiring
		// on first load instead of only after a user clicks each device in turn.
		$link_sql = 'SELECT src_port.device_id AS device_a,dst_port.device_id AS device_b,'.
				'link.src_id AS port_a,link.dst_id AS port_b,link.attrs AS link_attrs'.
			' FROM topo_edges link'.
			' JOIN topo_nodes src_port ON src_port.id=link.src_id'.
			' JOIN topo_nodes dst_port ON dst_port.id=link.dst_id'.
			' WHERE link.type='.zbx_dbstr('physical_link');
		if ($node_ids !== null) {
			$link_sql .= $node_ids
				? ' AND '.dbConditionId('src_port.device_id', $node_ids).' AND '.dbConditionId('dst_port.device_id', $node_ids)
				: ' AND 1=0';
		}
		$rows = DBfetchArray(DBselect($link_sql));
		$port_details = self::getPortDetails(array_merge(array_column($rows, 'port_a'), array_column($rows, 'port_b')));

		// A device pair can be reached by more than one physical_link (redundant cabling, or one
		// manual + one LLDP-discovered link between the same two devices) — collapse to a single
		// edge per pair rather than stacking duplicates, same "most-confirmed wins" precedent as
		// getNeighbors()' per-device discovered_via.
		$device_links = [];
		foreach ($rows as $row) {
			$pair = [$row['device_a'], $row['device_b']];
			sort($pair);
			$key = implode('-', $pair);
			$link_attrs = json_decode($row['link_attrs'], true, 512, JSON_THROW_ON_ERROR);
			$discovered_via = ($link_attrs['discovered_via'] ?? 'lldp') === 'lldp' ? 'lldp' : 'manual';
			if (!isset($device_links[$key]) || $discovered_via === 'lldp') {
				$port_a = $port_details[$row['port_a']] ?? [];
				$port_b = $port_details[$row['port_b']] ?? [];
				$last_seen = isset($link_attrs['last_seen']) ? (int) $link_attrs['last_seen'] : null;
				$device_links[$key] = ['source' => $row['device_a'], 'target' => $row['device_b'],
					'type' => 'physical_link', 'discovered_via' => $discovered_via,
					'source_port' => $port_a['name'] ?? null, 'target_port' => $port_b['name'] ?? null,
					// Ids, not just labels — port_status_of()/port_speed_of() below key on these too.
					'source_port_id' => $row['port_a'], 'target_port_id' => $row['port_b'],
					'source_status' => self::portStatus($port_a), 'target_status' => self::portStatus($port_b),
					'source_speed' => $port_a['speed'] ?? null, 'target_speed' => $port_b['speed'] ?? null,
					// §7 staleness indicator: rides along with whichever row won the discovered_via
					// collapse above, same precedent — not a separate merge policy of its own.
					'stale' => self::isLinkStale($last_seen)];
			}
		}
		foreach ($device_links as $relation) {
			$relations[] = $relation;
		}

		return $relations;
	}

	/**
	 * A link's own connectivity, from one port's attrs — 'disabled' (an operator turned the
	 * interface off on purpose, not alarm-worthy) takes precedence over the raw oper_status,
	 * which otherwise passes through as-is ('up'/'down'). Shared by getRelations() and
	 * getNeighbors() so both report the same three-state value the same way.
	 */
	private static function portStatus(array $port_attrs): ?string {
		if (!array_key_exists('oper_status', $port_attrs)) {
			return null;
		}

		return ($port_attrs['admin_status'] ?? 'up') === 'down' ? 'disabled' : $port_attrs['oper_status'];
	}

	/**
	 * Flat undirected adjacency over topo_nodes.id, for CTopologyHopScope's BFS: every
	 * represented_by pair (same source as getRelations()) plus every device-to-device pair
	 * implied by a physical_link — the same port.device_id -> physical_link -> port.device_id
	 * join chain getNeighbors() runs per-device below, but for every physical_link at once
	 * instead of one device's ports — plus every live-resolved Host->Proxy monitoring pair
	 * (§2.3/§6: no stored edge for this one, so it's resolved the same way getDevices() does).
	 *
	 * @return array list of [id_a, id_b] pairs.
	 */
	public static function getAdjacency(): array {
		$pairs = [];

		$result = DBselect('SELECT src_id,dst_id FROM topo_edges WHERE type='.zbx_dbstr('represented_by'));
		while ($row = DBfetch($result)) {
			$pairs[] = [$row['src_id'], $row['dst_id']];
		}

		$result = DBselect(
			'SELECT src_port.device_id AS device_a,dst_port.device_id AS device_b'.
			' FROM topo_edges link'.
			' JOIN topo_nodes src_port ON src_port.id=link.src_id'.
			' JOIN topo_nodes dst_port ON dst_port.id=link.dst_id'.
			' WHERE link.type='.zbx_dbstr('physical_link')
		);
		while ($row = DBfetch($result)) {
			$pairs[] = [$row['device_a'], $row['device_b']];
		}

		$host_nodes = []; // hostid => topo_nodes.id
		$result = DBselect('SELECT id,host_ref FROM topo_nodes WHERE type='.zbx_dbstr('host'));
		while ($row = DBfetch($result)) {
			$host_nodes[$row['host_ref']] = $row['id'];
		}
		if ($host_nodes) {
			$assignments = self::getMonitoringAssignments(array_keys($host_nodes));
			foreach ($assignments as $hostid => $assignment) {
				if ($assignment['target_node_id'] !== null) {
					$pairs[] = [$host_nodes[$hostid], $assignment['target_node_id']];
				}
			}
		}

		return $pairs;
	}

	/**
	 * Resolve a hostgroup/host+hops filter request into a concrete topo_nodes.id scope for
	 * getDevices()/getRelations(), or null for "no filter" (today's unrestricted behavior).
	 *
	 * Precedence matches the community network_topology module's own filter: a selected host
	 * overrides the group selection when both are somehow supplied.
	 *
	 * @param array  $groupids  Zabbix host group ids (host-group filter mode)
	 * @param string $hostid    Zabbix hostid (host+hops filter mode); '' = not set
	 * @param int    $hops      expansion depth for the focus-host mode, ignored otherwise
	 *
	 * @return array|null topo_nodes.id values in scope, or null when neither filter is active
	 */
	public static function resolveScope(array $groupids, string $hostid, int $hops): ?array {
		if ($hostid !== '') {
			// API::Host()->get() first, not a raw topo_nodes lookup — a hostid the caller isn't
			// permitted to see must behave exactly like a hostid that doesn't exist at all
			// (empty scope), not resolve and hand back its neighborhood. getDevices()/
			// getRelations() would still strip the invisible host itself out of whatever this
			// returns, but without this check a restricted user could use it as a topology
			// pivot — learning which unrelated devices sit near a host they can't otherwise see,
			// even though the host's own data stays hidden.
			if (!API::Host()->get(['hostids' => [$hostid], 'output' => []])) {
				return [];
			}

			$seed_node = DBfetch(DBselect('SELECT id FROM topo_nodes WHERE type='.zbx_dbstr('host').
				' AND host_ref='.zbx_dbstr($hostid), 1));
			if (!$seed_node) {
				// Host has no pointer node yet (never pulled) — nothing can be in scope of it.
				return [];
			}

			return CTopologyHopScope::neighborhood([$seed_node['id']], $hops, self::getAdjacency());
		}

		if ($groupids) {
			$seed_hostids = array_column(API::Host()->get([
				'output' => ['hostid'],
				'groupids' => $groupids
			]), 'hostid');
			if (!$seed_hostids) {
				return [];
			}

			$seed_ids = [];
			$result = DBselect('SELECT id FROM topo_nodes WHERE type='.zbx_dbstr('host').
				' AND '.dbConditionId('host_ref', $seed_hostids));
			while ($row = DBfetch($result)) {
				$seed_ids[] = $row['id'];
			}
			if (!$seed_ids) {
				return [];
			}

			return CTopologyHopScope::neighborhood($seed_ids, PHP_INT_MAX, self::getAdjacency());
		}

		return null;
	}

	public static function getNeighbors(string $deviceid): array {
		// No DISTINCT here (unlike before the severity field existed): a neighbor reached via more than one
		// physical_link (e.g. redundant cabling) needs every one of those links' port pairs collected so the
		// severity aggregation below sees every relevant port, not just whichever row happened to come first.
		$result = DBselect(
			'SELECT device.id,device.type,device.attrs,link.attrs AS link_attrs,local_port.id AS local_port_id,'.
				'remote_port.id AS remote_port_id'.
			' FROM topo_nodes local_port'.
			' JOIN topo_edges link ON link.type='.zbx_dbstr('physical_link').
				' AND (link.src_id=local_port.id OR link.dst_id=local_port.id)'.
			' JOIN topo_nodes remote_port ON remote_port.id='.
				'CASE WHEN link.src_id=local_port.id THEN link.dst_id ELSE link.src_id END'.
			' JOIN topo_nodes device ON device.id=remote_port.device_id AND device.type='.zbx_dbstr('device').
			' WHERE local_port.device_id='.zbx_dbstr($deviceid)
		);

		// $discovered_via buffered per neighbor during the fetch loop, same as before — what
		// changed is that 'represented' is no longer resolved inline per row. That used to mean
		// one extra DB round trip PER NEIGHBOR; clicking a highly-connected device (dozens of
		// neighbors) fired dozens of those synchronously, which is where the per-click delay
		// came from. Batched below instead: a handful of small queries total, regardless of how
		// many neighbors there are.
		$neighbors = [];
		$discovered_via = [];
		// §7 staleness indicator: last_seen of whichever physical_link row is currently "the" one
		// shown for this neighbor — updated in lockstep with $link_ports below (same row wins both).
		$last_seen = [];
		// Which specific port pair to LABEL the link with, for the "Link details" panel — a
		// neighbor reached via more than one physical_link (redundant cabling) still only shows
		// one pair, upgraded to an LLDP-confirmed pair the same moment $discovered_via upgrades
		// (below), rather than tracking it completely independently.
		$link_ports = [];
		while ($row = DBfetch($result)) {
			if (!array_key_exists($row['id'], $neighbors)) {
				$attrs = self::attrs($row);
				$neighbors[$row['id']] = ['id' => $row['id'], 'type' => 'device', 'name' => $attrs['sysname'],
					'monitoring_state' => null];
				$discovered_via[$row['id']] = 'manual';
				$link_ports[$row['id']] = ['local' => $row['local_port_id'], 'remote' => $row['remote_port_id']];
			}

			// A neighbor can be reached by more than one physical_link (e.g. one manually declared, one
			// LLDP-discovered via a different port pair). Same "most-confirmed wins" precedent used
			// throughout this class: if any one of them is LLDP-confirmed, render the neighbor link as such.
			$link_attrs = json_decode($row['link_attrs'], true, 512, JSON_THROW_ON_ERROR);
			$row_last_seen = isset($link_attrs['last_seen']) ? (int) $link_attrs['last_seen'] : null;
			if (($link_attrs['discovered_via'] ?? 'lldp') === 'lldp') {
				if ($discovered_via[$row['id']] !== 'lldp') {
					$link_ports[$row['id']] = ['local' => $row['local_port_id'], 'remote' => $row['remote_port_id']];
					$last_seen[$row['id']] = $row_last_seen;
				}
				$discovered_via[$row['id']] = 'lldp';
			}
			elseif (!array_key_exists($row['id'], $last_seen)) {
				$last_seen[$row['id']] = $row_last_seen;
			}
		}

		$represented_ids = self::getRepresentedIds(array_keys($neighbors));
		$visible_hostids = self::getVisibleHostIds();
		$representing_hostids = self::getRepresentingHostIds(array_keys($neighbors), $visible_hostids);
		$port_details = self::getPortDetails(array_merge(
			array_column($link_ports, 'local'), array_column($link_ports, 'remote')
		));

		foreach ($neighbors as $id => &$neighbor) {
			$neighbor['represented'] = isset($represented_ids[$id]);
			$neighbor['represented_hostid'] = $representing_hostids[$id] ?? null;
			$neighbor['discovered_via'] = $discovered_via[$id];
			$neighbor['stale'] = self::isLinkStale($last_seen[$id] ?? null);
			// 'local'/'remote' from $deviceid's own point of view — local_port belongs to the
			// clicked device, remote_port to this neighbor. Matches the naming already used for
			// local_port_id/remote_port_id above. Ids ride along too (not just the display
			// names/status/speed) — selectLink() on the frontend keys off them.
			$local = $port_details[$link_ports[$id]['local']] ?? [];
			$remote = $port_details[$link_ports[$id]['remote']] ?? [];
			$neighbor['local_port'] = $local['name'] ?? null;
			$neighbor['remote_port'] = $remote['name'] ?? null;
			$neighbor['local_port_id'] = $link_ports[$id]['local'];
			$neighbor['remote_port_id'] = $link_ports[$id]['remote'];
			$neighbor['local_status'] = self::portStatus($local);
			$neighbor['remote_status'] = self::portStatus($remote);
			$neighbor['local_speed'] = $local['speed'] ?? null;
			$neighbor['remote_speed'] = $remote['speed'] ?? null;
		}
		unset($neighbor);

		return array_values($neighbors);
	}

	// §6: /topo/nodes/{id}/problems — id is a Host node id (never a Device or Proxy id). Zabbix has no
	// clean "this problem belongs to this proxy" semantics (problems attach to triggers, which attach
	// to hosts/items, not to the Proxy config object itself) — don't invent a heuristic for it; a
	// Proxy node simply has no Problems section in the UI (§7).
	public static function getProblems(string $nodeid): array {
		// DBfetch(..., false) — see GOTCHAS.md #1: with the default $convertNulls=true, a Device/Proxy
		// node's NULL host_ref would come back as the string '0' instead of null, defeating this check.
		$node = DBfetch(DBselect('SELECT node.type,node.host_ref FROM topo_nodes node'.
			' WHERE node.id='.zbx_dbstr($nodeid)), false);
		if (!$node || $node['type'] !== 'host' || $node['host_ref'] === null) {
			return [];
		}

		return self::getProblemsForHost($node['host_ref']);
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

	public static function getPorts(string $deviceid): array {
		$groups = [
			'connected_lldp' => [], 'connected_mac_only' => [], 'disconnected' => [], 'port_channel' => [], 'management' => []
		];
		$result = DBselect(
			'SELECT port.id,port.attrs,link.id AS linkid,link.attrs AS link_attrs,'.
				'CASE WHEN link.src_id=port.id THEN link.dst_id ELSE link.src_id END AS linked_port_id'.
			' FROM topo_nodes port'.
			' LEFT JOIN topo_edges link ON link.type='.zbx_dbstr('physical_link').
				' AND (link.src_id=port.id OR link.dst_id=port.id)'.
			' WHERE port.type='.zbx_dbstr('port').' AND port.device_id='.zbx_dbstr($deviceid).
			' ORDER BY port.id'
		);

		// DBfetch(..., false) — see GOTCHAS.md #1: with the default $convertNulls=true, a disconnected/mgmt
		// port's unmatched linkid came back as '0' instead of null, so `linkid !== null` was always true and
		// every port (management ports included) was misreported as LLDP-connected.
		// Buffered into $rows first (rather than resolving 'connected_to' inline per row) so the
		// linked-device-name lookup below can run as ONE batched query for every connected port
		// instead of one getLinkedDeviceName() call per port — the difference between one extra
		// DB round trip and dozens, on a device with many connected ports.
		$rows = [];
		$connected_port_ids = [];
		while ($row = DBfetch($result, false)) {
			$rows[] = $row;
			if ($row['linkid'] !== null) {
				$connected_port_ids[] = $row['id'];
			}
		}
		$linked_names = self::getLinkedDeviceNames($connected_port_ids);

		foreach ($rows as $row) {
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
				'connected_to' => $linked_names[$row['id']] ?? null,
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
	// "mac"|"manual" — for a manual promotion matched_by is always the literal string 'manual'. chassis_id is
	// not a valid matched_by value here — Device<->Host/Proxy matching is MAC-only per §3 rule 2; chassis_id
	// remains valid only for the unrelated Device-to-itself upsert match in rule 4).
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

		// §2.3/§5: monitoring assignment (Host->Proxy/ProxyGroup) is resolved live at read time
		// (getMonitoringAssignments()), never stored — nothing to pull/upsert/retract for it here.
		$count = 0;
		foreach (API::Host()->get([
			'output' => ['hostid'],
			'selectInventory' => ['macaddress_a', 'macaddress_b']
		]) as $host) {
			$host_nodeid = self::upsertPointerNode('host', 'host_ref', $host['hostid']);
			self::reconcileHost($host_nodeid, $host['inventory'] ?? []);
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
	// that exists but has no device_id, rather than silently creating anything as a side effect.
	private static function assertPortOnDevice(string $port_id): void {
		$port = DBfetch(DBselect('SELECT node.id FROM topo_nodes node'.
			' JOIN topo_nodes device ON device.id=node.device_id AND device.type='.zbx_dbstr('device').
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
	//
	// This method is only ever called with discovered_via='manual' (the manual /link endpoint above) —
	// discovery's own lldp-confirmed path lives in ingest.php's $ensure_physical_link(), which additionally
	// tracks attrs.last_seen_src/last_seen_dst per reporter side (§2.3). There's no reporter to attribute a
	// side to here, so per spec those two fields are deliberately left unset for manual links, never
	// zero-initialized or defaulted — only attrs.last_seen (kept for §7's staleness indicator) is stamped.
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

	// §7: Proxy shares Host's severity-colored fill, but Zabbix has no proxyid linkage on problem/trigger/item
	// to resolve it from — see getProxyHealthItemIds() for how this is actually found. (Note: this is
	// the Proxy node's own graph fill color, unrelated to §6's Problems side-panel section, which is
	// Host-only and has no Proxy equivalent at all — see getProblems().)
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

	// Zabbix has no proxyid column on problem/trigger/item — a Proxy's own health problems are only reachable
	// via the well-known internal item key convention 'zabbix.proxy.<metric>[<proxy name>]' (e.g.
	// zabbix.proxy.last_seen[Riga proxy]), the same keys the stock "Zabbix proxy health" template uses,
	// interpolating the proxy's exact name as the key parameter. This is a real, well-defined Zabbix
	// convention, not a fuzzy/weak-key guess — but it only finds anything if some host in the instance has
	// actually been set up to monitor this specific proxy via that convention; there's no structural guarantee
	// one exists, same opportunistic caveat as MAC reconciliation in reconcileHost().
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

	/**
	 * Same result as calling isRepresented() once per id in $ids, in one query instead of one
	 * per id — getNeighbors() previously did exactly that in a loop, one extra DB round trip per
	 * neighbor found, which is where a highly-connected device's per-click delay came from.
	 *
	 * @return array a lookup set: id => true for every id (of the ones given) that IS represented.
	 */
	private static function getRepresentedIds(array $ids): array {
		if (!$ids) {
			return [];
		}

		$represented = [];
		$result = DBselect('SELECT DISTINCT src_id FROM topo_edges WHERE type='.zbx_dbstr('represented_by').
			' AND '.dbConditionId('src_id', $ids));
		while ($row = DBfetch($result)) {
			$represented[$row['src_id']] = true;
		}

		return $represented;
	}

	/**
	 * device topo_nodes.id => the hostid of whatever HOST represents it (never a proxy — Zabbix
	 * items only ever belong to hosts) — which host to scope the "attach item" picker on the Link
	 * details panel to. Excludes anything the caller can't see, same principle as every other ACL
	 * floor in this class: a device represented by an invisible host must not leak that hostid.
	 *
	 * @param array $device_ids      topo_nodes.id values to resolve (device type)
	 * @param array $visible_hostids from getVisibleHostIds() — passed in rather than recomputed,
	 *                               since every current caller already has it on hand.
	 */
	private static function getRepresentingHostIds(array $device_ids, array $visible_hostids): array {
		if (!$device_ids || !$visible_hostids) {
			return [];
		}

		$map = [];
		$result = DBselect('SELECT rep.src_id AS device_id,host.hostid'.
			' FROM topo_edges rep'.
			' JOIN topo_nodes host_node ON host_node.id=rep.dst_id AND host_node.type='.zbx_dbstr('host').
			' JOIN hosts host ON host.hostid=host_node.host_ref'.
			' WHERE rep.type='.zbx_dbstr('represented_by').
				' AND '.dbConditionId('rep.src_id', $device_ids).
				' AND '.dbConditionId('host.hostid', $visible_hostids));
		while ($row = DBfetch($result)) {
			$map[$row['device_id']] = $row['hostid'];
		}

		return $map;
	}

	private static function isRepresentedTarget(string $target_id): bool {
		return (bool) DBfetch(DBselect('SELECT id FROM topo_edges WHERE type='.zbx_dbstr('represented_by').
			' AND dst_id='.zbx_dbstr($target_id), 1));
	}

	private static function getLinkedDeviceName(string $portid): ?string {
		$row = DBfetch(DBselect('SELECT device.attrs FROM topo_edges link'.
			' JOIN topo_nodes port ON port.id=CASE WHEN link.src_id='.zbx_dbstr($portid).' THEN link.dst_id ELSE link.src_id END'.
			' JOIN topo_nodes device ON device.id=port.device_id WHERE link.type='.zbx_dbstr('physical_link').
			' AND (link.src_id='.zbx_dbstr($portid).' OR link.dst_id='.zbx_dbstr($portid).')', 1));
		return $row ? self::attrs($row)['sysname'] : null;
	}

	/**
	 * Same result as calling getLinkedDeviceName() once per id in $portids, in one query instead
	 * of one per port — getPorts() previously did exactly that for every connected port in a
	 * loop, another source of the per-click delay on a device with many connected ports.
	 *
	 * @return array port id => linked device's sysname, only for ports that resolved to one.
	 */
	private static function getLinkedDeviceNames(array $portids): array {
		if (!$portids) {
			return [];
		}

		$names = [];
		$result = DBselect(
			'SELECT link.src_id,link.dst_id,device.attrs'.
			' FROM topo_edges link'.
			' JOIN topo_nodes port ON port.id=CASE WHEN '.dbConditionId('link.src_id', $portids).
				' THEN link.dst_id ELSE link.src_id END'.
			' JOIN topo_nodes device ON device.id=port.device_id'.
			' WHERE link.type='.zbx_dbstr('physical_link').
				' AND ('.dbConditionId('link.src_id', $portids).' OR '.dbConditionId('link.dst_id', $portids).')'
		);
		while ($row = DBfetch($result)) {
			$name = self::attrs($row)['sysname'];
			// Whichever end of this physical_link is one of our ports gets the OTHER end's
			// device name — same CASE logic as the single-port version, just evaluated for both
			// possible sides since this query no longer has one fixed $portid to pivot on.
			if (in_array($row['src_id'], $portids)) {
				$names[$row['src_id']] = $name;
			}
			if (in_array($row['dst_id'], $portids)) {
				$names[$row['dst_id']] = $name;
			}
		}

		return $names;
	}

	/**
	 * Port topo_nodes.id => the subset of its attrs a link needs: name (e.g.
	 * "GigabitEthernet1/0/1", for the local/remote port labels), oper_status/admin_status (via
	 * portStatus()) and speed — what a physical_link's "Link details" panel and its line color on
	 * the map are actually built from. One query for however many port ids are given, same
	 * batching precedent as getLinkedDeviceNames()/getRepresentedIds().
	 */
	private static function getPortDetails(array $portids): array {
		$portids = array_values(array_unique(array_filter($portids, static fn($id) => $id !== null)));
		if (!$portids) {
			return [];
		}

		$details = [];
		$result = DBselect('SELECT id,attrs FROM topo_nodes WHERE '.dbConditionId('id', $portids).
			' AND type='.zbx_dbstr('port'));
		while ($row = DBfetch($result)) {
			$attrs = self::attrs($row);
			$details[$row['id']] = [
				'name' => $attrs['name'] ?? null,
				'oper_status' => $attrs['oper_status'] ?? null,
				'admin_status' => $attrs['admin_status'] ?? null,
				'speed' => $attrs['speed'] ?? null
			];
		}

		return $details;
	}

	// host.get's selectInterfaces never returns a 'mac' field (the Zabbix interface table only has
	// hostid/type/ip/dns/port/useip/main) — this can never match against a real Zabbix instance. The actual
	// MAC source is host_inventory.macaddress_a/macaddress_b (host.get selectInventory), populated only when
	// the host uses Automatic inventory mode with a system.hw.macaddr item — most hosts on a real instance
	// won't have it set, and an empty/missing value is a normal non-match, not an error, same as any other
	// unmatched host. Device<->Host/Proxy matching is MAC-only (§3 rule 2, corrected) — there is no generic
	// Zabbix host field carrying an LLDP chassis ID; host_inventory.chassis is an unrelated free-text
	// inventory field, not a valid match key here (chassis_id remains valid only for the separate
	// Device-to-itself upsert match in rule 4, which this method has nothing to do with). The same MAC gap
	// applies to Proxy (CProxy::get has no interface/inventory concept at all), so §5's "run Device
	// reconciliation for each Proxy" is not implemented; see pullProxies() above.
	private static function reconcileHost(string $host_nodeid, array $inventory): void {
		foreach (['macaddress_a', 'macaddress_b'] as $field) {
			if (empty($inventory[$field])) {
				continue;
			}
			$mac = strtolower($inventory[$field]);
			$result = DBselect('SELECT device_id FROM topo_nodes port'.
				' WHERE port.type='.zbx_dbstr('port').' AND port.device_id IS NOT NULL'.
				' AND port.attrs LIKE '.zbx_dbstr('%"mac":"'.$mac.'"%'));
			while ($device = DBfetch($result)) {
				try {
					self::promote($device['device_id'], $host_nodeid, 'identity', 'mac', $mac);
				}
				catch (Exception $exception) {
					// §2.1's 1:1 constraint applies here too, not just to the manual endpoint: the device or
					// this host is already represented elsewhere. Skip this candidate — it's a normal "no
					// match" outcome for automatic reconciliation, not a failure worth aborting the pull over.
					continue;
				}
			}
		}
	}
}