<?php

// The one definition of the hidden neighbor of a contradicted manual link, shared with database/topology/discovery/ingest.php.
require_once __DIR__.'/../../../../database/topology/topology_contradiction.inc.php';
require_once __DIR__.'/../../../../database/topology/topology_freshness.inc.php';

class CTopologyPrototype {

	// topology-1-model.md "`device_link` (Port → Device)": why a device_link ends at a Device and not at a Port. fdb_mac_only is
	// reserved (accepted, never produced); manual is what an operator-made device_link carries.
	public const FAR_PORT_REASONS = ['port_unmatched', 'port_shared_id', 'lag_ambiguous', 'manual', 'fdb_mac_only'];
	public const LINK_TYPES = ['physical_link', 'device_link'];


	private static function attrs(array $row): array {
		return json_decode($row['attrs'], true, 512, JSON_THROW_ON_ERROR);
	}

	// the physical_link staleness indicator (topology-1-model.md "Disappearance and staleness"): the threshold is the global macro
	// {$TOPOLOGY.LINK.FRESH.SECONDS} (topology-1-model.md "Freshness threshold"), read once per request. Computed server-side
	// (both here and by every caller) so the raw threshold never needs to ship to the client — the client only ever sees the
	// already-derived boolean.
	private static ?int $fresh_seconds = null;

	private static function freshSeconds(): int {
		if (self::$fresh_seconds === null) {
			$macro = DBfetch(DBselect('SELECT value FROM globalmacro WHERE macro='.zbx_dbstr(TOPOLOGY_FRESH_MACRO), 1), false);
			[self::$fresh_seconds, $warning] = topology_fresh_seconds($macro ? (string) $macro['value'] : null);
			if ($warning !== null) {
				CMessageHelper::addWarning($warning);
			}
		}

		return self::$fresh_seconds;
	}

	/**
	 * Forget the threshold read earlier in this process, so the next call reads the macro again. A UI request is one
	 * process and never needs it; a long-lived caller (the lab's helper, a test that changes the macro) does.
	 */
	public static function reloadFreshness(): void {
		self::$fresh_seconds = null;
	}

	// A superseded link (topology-1-model.md "Replacement of discovered links", superseded state) lost its port to a newer one: it is stale
	// whatever its last_seen says.
	public static function isLinkStale(?int $last_seen, ?int $superseded_at = null): bool {
		return $superseded_at !== null || $last_seen === null || (time() - $last_seen) > self::freshSeconds();
	}

	private static function supersededAt(array $link_attrs): ?int {
		return isset($link_attrs['superseded_at']) ? (int) $link_attrs['superseded_at'] : null;
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
		$sql = 'SELECT node.id,node.type,node.attrs,node.created_at,node.host_ref,node.proxy_ref,'.
				'host.name AS host_name,host.status AS host_status,host.maintenance_status AS host_maintenance_status,'.
				'proxy.name AS proxy_name,proxy_rt.state AS proxy_state'.
			' FROM topo_nodes node'.
			' LEFT JOIN hosts host ON host.hostid=node.host_ref'.
			' LEFT JOIN proxy ON proxy.proxyid=node.proxy_ref'.
			' LEFT JOIN proxy_rtdata proxy_rt ON proxy_rt.proxyid=proxy.proxyid'.
			' LEFT JOIN topo_nodes rep ON rep.type='.zbx_dbstr('device').' AND rep.represented_by_node_id=node.id'.
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
					$node['created_at'] = (int) $row['created_at'];
					// topology-1-model.md §6.7.5: possible duplicates (the evidence ingest wrote); resolved below, in one query
					$node['duplicates'] = $attrs['duplicate_candidates'] ?? [];
					$node['dismissed'] = $attrs['duplicate_dismissed'] ?? [];
					$device_ids[] = $row['id'];
					break;

				case 'host':
					// topology-1-model.md "Node styles": a disabled host isn't polled, so it gets neutral/muted styling, never a severity
					// color (not even "ok") — skip the severity lookup entirely rather than compute one that
					// would be shown. Maintenance is a *different* state: still monitored, keeps its real
					// severity color, just adds a badge — the two must not be conflated.
					$node['name'] = $row['host_name'];
					$node['hostid'] = $row['host_ref'];
					$node['monitoring_state'] = $row['host_status'];
					$node['disabled'] = ((int) $row['host_status']) === HOST_STATUS_NOT_MONITORED;
					$node['maintenance'] = ((int) $row['host_maintenance_status']) === HOST_MAINTENANCE_STATUS_ON;
					$node += self::describeSeverity($node['disabled'] ? null : self::getMaxActiveSeverityForHost($row['host_ref']));
					// topology-1-model.md "Monitoring assignment (`MONITORED_BY`) — live, not stored": monitoring assignment is resolved live below (batched), never from a stored
					// edge — 'blind_spot'/'monitored_by'/etc. are patched onto this node after the loop.
					$host_rows[$row['id']] = $row['host_ref'];
					break;

				case 'proxy':
					// topology-1-model.md "Node styles": Proxy shares Host's "monitored, has severity" styling — resolved via the
					// zabbix.proxy.*[name] internal item convention, see getProxyHealthItemIds().
					$node['name'] = $row['proxy_name'];
					$node['proxyid'] = $row['proxy_ref'];
					$node['unreachable'] = !self::isProxyOnline($row['proxy_state']);
					$node['represented'] = self::isRepresentedTarget($row['id']);
					$node += self::describeSeverity(self::getMaxActiveSeverityForProxy($row['proxy_name']));
					break;
			}

			$nodes[] = $node;
		}

		// names of the Devices the duplicate candidates point at (data from the network: the caller escapes them)
		$other_ids = [];
		foreach ($nodes as $node) {
			foreach (array_merge($node['duplicates'] ?? [], $node['dismissed'] ?? []) as $candidate) {
				if ($candidate['device_id'] !== null) {
					$other_ids[(int) $candidate['device_id']] = true;
				}
			}
		}
		$other_names = [];
		if ($other_ids) {
			$result = DBselect('SELECT id,attrs FROM topo_nodes WHERE type='.zbx_dbstr('device').' AND '.dbConditionId('id', array_keys($other_ids)));
			while ($row = DBfetch($result)) {
				$other_names[$row['id']] = self::attrs($row)['sysname'] ?? '';
			}
		}
		foreach ($nodes as &$node) {
			foreach (['duplicates', 'dismissed'] as $list) {
				if (isset($node[$list])) {
					$node[$list] = array_map(static fn (array $candidate): array => $candidate + ['name' => $candidate['device_id'] !== null
						? ($other_names[$candidate['device_id']] ?? null) : null], $node[$list]);
				}
			}
		}
		unset($node);

		$represented_ids = self::getRepresentedIds($device_ids);
		$representing_hostids = self::getRepresentingHostIds($device_ids, $visible_hostids);
		// topology-1-model.md "Monitoring assignment (`MONITORED_BY`) — live, not stored": one batched host.get call for every Host node's live monitoring assignment —
		// never one call per host (topology-1-model.md "Scaling notes (not required now)"). $host_rows maps topo_nodes.id => hostid.
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
				// replicating that branching itself (topology-1-model.md "API"). Null when server-monitored, or when the
				// target proxy has no topo_nodes row yet (never pulled).
				$node['target_node_id'] = $assignment['target_node_id'] ?? null;
				$node['blind_spot'] = $assignment['blind_spot'] ?? false;
			}
		}
		unset($node);

		return $nodes;
	}

	/**
	 * Live-resolved Host->Proxy/ProxyGroup monitoring assignment (topology-1-model.md "Monitoring assignment (`MONITORED_BY`) — live, not stored") — replaces the old stored
	 * `monitored_by` edge entirely. One batched host.get() call for every hostid given, never one per
	 * host (topology-1-model.md "Scaling notes (not required now)").
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

		// topo_nodes.id for each target proxy — only exists once that Proxy has been pulled (topology-1-model.md "Host and Proxy association"); a
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

	// topology-1-model.md "Monitoring assignment (`MONITORED_BY`) — live, not stored": proxy_groupid alone doesn't say which proxy is actually serving a proxy-group-monitored
	// host — assigned_proxyid (host.get's server-computed answer) is the one that matters there.
	private static function resolveTargetProxyId(array $host): ?string {
		return match ((int) $host['monitored_by']) {
			ZBX_MONITORED_BY_PROXY => $host['proxyid'] !== '0' ? $host['proxyid'] : null,
			ZBX_MONITORED_BY_PROXY_GROUP => $host['assigned_proxyid'] ?: null,
			default => null
		};
	}

	/**
	 * topology-1-model.md "API": GET /topo/hosts/search?q= — the only host picker for /promote under the lazy-creation
	 * policy: most Zabbix hosts have no `topo_nodes` row at all, so there's no local list to search
	 * against. Thin wrapper over `host.get`, filtered by name/IP; writes nothing to `topo_nodes`.
	 */
	public static function searchHosts(string $q): array {
		if ($q === '') {
			return [];
		}

		$hosts = API::Host()->get([
			'output' => ['hostid', 'name', 'status'],
			'search' => ['name' => $q, 'ip' => $q],
			'searchByAny' => true,
			'sortfield' => 'name',
			'limit' => 20
		]);

		return array_map(static fn(array $host): array => [
			'hostid' => $host['hostid'], 'name' => $host['name'], 'monitoring_state' => $host['status']
		], $hosts);
	}

	// topology-1-model.md "API": GET /topo/proxies/search?q= — same role as searchHosts() above, for /promote's Proxy side.
	public static function searchProxies(string $q): array {
		if ($q === '') {
			return [];
		}

		$proxies = API::Proxy()->get([
			'output' => ['proxyid', 'name'],
			'search' => ['name' => $q],
			'sortfield' => 'name',
			'limit' => 20
		]);
		$proxy_states = [];
		$result = DBselect('SELECT proxyid,state FROM proxy_rtdata WHERE '.
			dbConditionId('proxyid', array_column($proxies, 'proxyid')));
		while ($row = DBfetch($result)) {
			$proxy_states[$row['proxyid']] = $row['state'];
		}

		return array_map(static fn(array $proxy): array => [
			'proxyid' => $proxy['proxyid'], 'name' => $proxy['name'],
			'unreachable' => !self::isProxyOnline($proxy_states[$proxy['proxyid']] ?? null)
		], $proxies);
	}

	/**
	 * @param array|null $node_ids  restrict to relations where BOTH ends are in this set; null
	 *                              = unrestricted, today's default behavior.
	 */
	public static function getRelations(?array $node_ids = null): array {
		$relations = [];
		// Permission floor, always applied: represented_by (device->host, resolved below straight off
		// the Device row — topology-1-model.md "Nodes: Storage", no topo_edges row for this anymore) is the only relationship here
		// that ever touches a 'host' topo_node (monitoring assignment is resolved live from
		// /topo/devices instead — topology-1-model.md "Monitoring assignment (`MONITORED_BY`) — live, not stored"), so excluding any row whose target is a host the caller
		// can't see is enough — physical_link rows (added below) are device-to-device only and never
		// need this. Only the target (dst) side needs the floor: the source is always a 'device' node,
		// which is never itself subject to this per-host visibility check. dbConditionId(..., true)
		// already renders "exclude every host node" (1=1) when getVisibleHostIds() is empty, so no
		// separate empty-array branch is needed here the way getDevices() needs one for its
		// positive IN() case.
		$invisible_host_nodes = 'SELECT id FROM topo_nodes WHERE type='.zbx_dbstr('host').
			' AND '.dbConditionId('host_ref', self::getVisibleHostIds(), true);
		$sql = 'SELECT id,represented_by_node_id FROM topo_nodes WHERE type='.zbx_dbstr('device').
			' AND represented_by_node_id IS NOT NULL'.
			' AND represented_by_node_id NOT IN ('.$invisible_host_nodes.')';
		if ($node_ids !== null) {
			$sql .= $node_ids
				? ' AND '.dbConditionId('id', $node_ids).' AND '.dbConditionId('represented_by_node_id', $node_ids)
				: ' AND 1=0';
		}
		$result = DBselect($sql);
		while ($row = DBfetch($result)) {
			$relations[] = ['source' => $row['id'], 'target' => $row['represented_by_node_id'], 'type' => 'represented_by'];
		}

		$link_sql = 'SELECT src_port.device_id AS device_a,dst_port.device_id AS device_b,'.
				'link.id AS link_id,link.src_id AS port_a,link.dst_id AS port_b,link.attrs AS link_attrs'.
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

		// Lost precision (topology-1-model.md "Lost precision"): a port-level link that some latest snapshot
		// confirms only at device level. The reporting side is the observation's local port; the remote port is the
		// link's other end.
		$lower_from = [];
		if ($rows) {
			$cursor = DBselect('SELECT edge_id,local_port_id FROM topo_observations WHERE precision_lower=1 AND '.
				dbConditionId('edge_id', array_column($rows, 'link_id')));
			while ($observation = DBfetch($cursor, false)) {
				$lower_from[$observation['edge_id']][$observation['local_port_id']] = true;
			}
		}

		// A device pair can be reached by more than one physical_link (redundant cabling, or one
		// manual + one LLDP-discovered link between the same two devices) — collapse to a single
		// edge per pair rather than stacking duplicates, same "most-confirmed wins" precedent as
		// getNeighbors()' per-device discovered_via.
		$device_links = [];
		// A manual link that discovery contradicts (or that the operator acknowledged) keeps its own line, so its
		// badge and its details are reachable even when the same two devices are also linked another way.
		$contradictions = self::getContradictions();
		$own_line = array_column($contradictions, 'edge_id', 'edge_id');
		// Superseded edges that sit under a ghost line are not drawn while it is unacknowledged.
		$hidden_edges = self::getEdgesHiddenByGhosts($contradictions);
		// Active discovered links by port, to say which link replaced a superseded one ("Replaced on ...").
		$active_by_port = [];
		foreach ($rows as $row) {
			$row_attrs = json_decode($row['link_attrs'], true, 512, JSON_THROW_ON_ERROR);
			if (self::supersededAt($row_attrs) === null) {
				$active_by_port[$row['port_a']][] = $row;
				$active_by_port[$row['port_b']][] = $row;
			}
		}
		foreach ($rows as $row) {
			if (isset($hidden_edges[$row['link_id']])) {
				continue;
			}
			$pair = [$row['device_a'], $row['device_b']];
			sort($pair);
			$key = implode('-', $pair).(isset($own_line[$row['link_id']]) ? '#'.$row['link_id'] : '');
			$link_attrs = json_decode($row['link_attrs'], true, 512, JSON_THROW_ON_ERROR);
			$superseded_at = self::supersededAt($link_attrs);
			// Any source other than 'manual' (lldp, cdp, ...) is a discovered link. 'lldp' stays the normalized
			// "discovered" value the frontend filter and styling already key on; the raw source rides along in
			// discovery_source for labels.
			$discovery_source = (string) ($link_attrs['discovered_via'] ?? 'lldp');
			$discovered_via = $discovery_source === 'manual' ? 'manual' : 'lldp';
			// An active link between the pair wins over a superseded one; among equals a discovered link wins.
			$replace = !isset($device_links[$key])
				|| ($device_links[$key]['superseded_at'] !== null && $superseded_at === null)
				|| (($device_links[$key]['superseded_at'] === null) === ($superseded_at === null)
					&& $discovered_via === 'lldp');
			if ($replace) {
				$replaced_by = null;
				if ($superseded_at !== null) {
					foreach ([$row['port_a'], $row['port_b']] as $port_id) {
						foreach ($active_by_port[$port_id] ?? [] as $active) {
							$replaced_by = $active['port_a'] === $port_id ? $active['device_b'] : $active['device_a'];
							break 2;
						}
					}
				}
				$port_a = $port_details[$row['port_a']] ?? [];
				$port_b = $port_details[$row['port_b']] ?? [];
				$last_seen = isset($link_attrs['last_seen']) ? (int) $link_attrs['last_seen'] : null;
				$device_links[$key] = ['source' => $row['device_a'], 'target' => $row['device_b'],
					'type' => 'physical_link', 'discovered_via' => $discovered_via,
					'discovery_source' => $discovery_source,
					'source_port' => $port_a['name'] ?? null, 'target_port' => $port_b['name'] ?? null,
					// Ids, not just labels — port_status_of()/port_speed_of() below key on these too.
					'source_port_id' => $row['port_a'], 'target_port_id' => $row['port_b'],
					'source_status' => self::portStatus($port_a), 'target_status' => self::portStatus($port_b),
					'source_speed' => $port_a['speed'] ?? null, 'target_speed' => $port_b['speed'] ?? null,
					// staleness indicator (topology-1-model.md "Disappearance and staleness"): rides along with whichever row won the discovered_via
					// collapse above, same precedent — not a separate merge policy of its own.
					'stale' => self::isLinkStale($last_seen, $superseded_at),
					'superseded_at' => $superseded_at, 'replaced_by_device' => $replaced_by,
					// topology-1-model.md "Lost precision" flag: one entry per side that sees only the far Device ("The remote port <Q> hasn't been
					// confirmed since <last_seen_port>; <D> is still seen on this port."). last_seen_port is for display
					// only; a row without it counts as last_seen.
					'lost_precision' => array_values(array_filter([
						isset($lower_from[$row['link_id']][$row['port_a']]) ? ['remote_port' => $port_b['name'] ?? null,
							'remote_device' => $row['device_b']] : null,
						isset($lower_from[$row['link_id']][$row['port_b']]) ? ['remote_port' => $port_a['name'] ?? null,
							'remote_device' => $row['device_a']] : null
					])),
					'last_seen_port' => isset($link_attrs['last_seen_port']) ? (int) $link_attrs['last_seen_port'] : $last_seen,
					// The stored link, so the manual-contradiction panel (topology-1-model.md "Contradiction")
					// can tell which drawn line is which topo_edges row.
					'edge_id' => $row['link_id']];
			}
		}
		foreach ($device_links as $relation) {
			$relations[] = $relation;
		}
		foreach (self::getDeviceLevelRelations($node_ids, $own_line, $hidden_edges) as $relation) {
			$relations[] = $relation;
		}

		return $relations;
	}

	/**
	 * Device-level links (topology-1-model.md "Link channels") as relations: a line from a Device to the far Device's
	 * border (the graph draws Devices, so there is no far port to end at). Links between the same two Devices, in
	 * either direction, are bundled into one relation that carries every link: storage stays per local port.
	 *
	 * far_port_hint is the far end's advertisement as received: untrusted text, to be escaped on render (topology-1-model.md "Security: untrusted strings").
	 *
	 * @param array|null $node_ids  restrict to relations where BOTH Devices are in this set; null = unrestricted
	 * @param array      $own_line  edge ids that keep a line of their own (contradicted / acknowledged manual links)
	 */
	private static function getDeviceLevelRelations(?array $node_ids, array $own_line, array $hidden_edges = []): array {
		$sql = 'SELECT link.id AS link_id,link.src_id AS port_id,link.dst_id AS far_device,'.
				'src_port.device_id AS near_device,link.attrs AS link_attrs,link.created_at AS link_created_at'.
			' FROM topo_edges link'.
			' JOIN topo_nodes src_port ON src_port.id=link.src_id'.
			' JOIN topo_nodes dst_device ON dst_device.id=link.dst_id'.
			' WHERE link.type='.zbx_dbstr('device_link');
		if ($node_ids !== null) {
			$sql .= $node_ids
				? ' AND '.dbConditionId('src_port.device_id', $node_ids).' AND '.dbConditionId('link.dst_id', $node_ids)
				: ' AND 1=0';
		}
		$rows = DBfetchArray(DBselect($sql));
		if (!$rows) {
			return [];
		}
		$port_details = self::getPortDetails(array_column($rows, 'port_id'));

		$bundles = [];
		foreach ($rows as $row) {
			if (isset($hidden_edges[$row['link_id']])) {
				continue;
			}
			$link_attrs = json_decode($row['link_attrs'], true, 512, JSON_THROW_ON_ERROR);
			$pair = [$row['near_device'], $row['far_device']];
			sort($pair);
			$key = implode('-', $pair).(isset($own_line[$row['link_id']]) ? '#'.$row['link_id'] : '');
			$discovery_source = (string) ($link_attrs['discovered_via'] ?? 'lldp');
			$last_seen = isset($link_attrs['last_seen']) ? (int) $link_attrs['last_seen'] : null;
			$superseded_at = self::supersededAt($link_attrs);
			$bundles[$key][] = [
				'edge_id' => $row['link_id'],
				'near_device' => $row['near_device'], 'far_device' => $row['far_device'],
				'near_port_id' => $row['port_id'],
				'near_port' => $port_details[$row['port_id']]['name'] ?? null,
				'near_status' => self::portStatus($port_details[$row['port_id']] ?? []),
				'near_speed' => $port_details[$row['port_id']]['speed'] ?? null,
				'far_port_reason' => (string) ($link_attrs['far_port_reason'] ?? ''),
				'far_port_hint' => is_array($link_attrs['far_port_hint'] ?? null) ? $link_attrs['far_port_hint'] : null,
				'created_by' => isset($link_attrs['created_by']) ? (string) $link_attrs['created_by'] : null,
				'created_by_name' => self::userName($link_attrs['created_by'] ?? null),
				'created_at' => (int) ($link_attrs['created_at'] ?? $row['link_created_at']),
				'discovered_via' => $discovery_source === 'manual' ? 'manual' : 'lldp',
				'discovery_source' => $discovery_source,
				'last_seen' => $last_seen,
				'stale' => self::isLinkStale($last_seen, $superseded_at),
				'superseded_at' => $superseded_at
			];
		}

		$relations = [];
		foreach ($bundles as $links) {
			// Like physical links: an active link between the pair wins over a superseded one.
			$active = array_values(array_filter($links, static fn (array $l): bool => $l['superseded_at'] === null));
			$shown = $active ?: $links;
			usort($shown, static fn (array $a, array $b): int => (int) $a['edge_id'] <=> (int) $b['edge_id']);
			$first = $shown[0];
			$manual = !array_filter($shown, static fn (array $l): bool => $l['discovered_via'] !== 'manual');
			$relations[] = [
				'source' => $first['near_device'], 'target' => $first['far_device'], 'type' => 'device_link',
				'discovered_via' => $manual ? 'manual' : 'lldp',
				'discovery_source' => $manual ? 'manual' : $first['discovery_source'],
				'count' => count($shown), 'links' => $shown,
				'stale' => !array_filter($shown, static fn (array $l): bool => !$l['stale']),
				'superseded_at' => $active ? null : max(array_column($shown, 'superseded_at')),
				// Same contract as physical links: the stored link, for the manual-contradiction panel.
				'edge_id' => $first['edge_id']
			];
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
	 * (topology-1-model.md "Monitoring assignment (`MONITORED_BY`) — live, not stored": no stored edge for this one, so it's resolved the same way getDevices() does).
	 *
	 * @return array list of [id_a, id_b] pairs.
	 */
	public static function getAdjacency(): array {
		$pairs = [];

		$result = DBselect('SELECT id,represented_by_node_id FROM topo_nodes WHERE type='.zbx_dbstr('device').
			' AND represented_by_node_id IS NOT NULL');
		while ($row = DBfetch($result)) {
			$pairs[] = [$row['id'], $row['represented_by_node_id']];
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

		// A device-level link (device-level-edge spec) connects its local Device to the far Device just the same.
		$result = DBselect(
			'SELECT src_port.device_id AS device_a,link.dst_id AS device_b'.
			' FROM topo_edges link'.
			' JOIN topo_nodes src_port ON src_port.id=link.src_id'.
			' WHERE link.type='.zbx_dbstr('device_link')
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
		// Superseded edges under an unacknowledged ghost line are not drawn (topology-1-model.md "Contradicted manual links").
		$contradictions = self::getContradictions();
		$hidden_edges = self::getEdgesHiddenByGhosts($contradictions);
		$result = DBselect(
			'SELECT device.id,device.type,device.attrs,link.attrs AS link_attrs,link.id AS link_id,local_port.id AS local_port_id,'.
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
		$discovery_source = [];
		// staleness indicator (topology-1-model.md "Disappearance and staleness"): last_seen of whichever physical_link row is currently "the" one
		// shown for this neighbor — updated in lockstep with $link_ports below (same row wins both).
		$last_seen = [];
		// topology-1-model.md "Replacement of discovered links": a superseded link is stale; an active link to the same neighbor
		// takes precedence over it, the same way an LLDP-confirmed one does over a manual one.
		$superseded_at = [];
		// Which specific port pair to LABEL the link with, for the "Link details" panel — a
		// neighbor reached via more than one physical_link (redundant cabling) still only shows
		// one pair, upgraded to an LLDP-confirmed pair the same moment $discovered_via upgrades
		// (below), rather than tracking it completely independently.
		$link_ports = [];
		// The stored link that $link_ports labels, with its attrs: the per-link fields of the answer (topology-1-model.md "API")
		// are read from it, so they describe the same link as the port pair does.
		$shown = [];
		while ($row = DBfetch($result)) {
			if (isset($hidden_edges[$row['link_id']])) {
				continue;
			}
			if (!array_key_exists($row['id'], $neighbors)) {
				$attrs = self::attrs($row);
				$neighbors[$row['id']] = ['id' => $row['id'], 'type' => 'device', 'name' => $attrs['sysname'],
					'monitoring_state' => null];
				$discovered_via[$row['id']] = 'manual';
				$discovery_source[$row['id']] = 'manual';
				$link_ports[$row['id']] = ['local' => $row['local_port_id'], 'remote' => $row['remote_port_id']];
				$shown[$row['id']] = ['edge_id' => $row['link_id'], 'attrs' => self::attrs(['attrs' => $row['link_attrs']])];
			}

			// A neighbor can be reached by more than one physical_link (e.g. one manually declared, one
			// LLDP-discovered via a different port pair). Same "most-confirmed wins" precedent used
			// throughout this class: if any one of them is LLDP-confirmed, render the neighbor link as such.
			$link_attrs = json_decode($row['link_attrs'], true, 512, JSON_THROW_ON_ERROR);
			$row_last_seen = isset($link_attrs['last_seen']) ? (int) $link_attrs['last_seen'] : null;
			$row_superseded = self::supersededAt($link_attrs);
			if ($row_superseded !== null && array_key_exists($row['id'], $superseded_at)
					&& $superseded_at[$row['id']] === null) {
				continue; // an active link to this neighbor is already the one shown
			}
			if ($row_superseded === null && ($superseded_at[$row['id']] ?? null) !== null) {
				// the first row seen was a superseded link: this active one replaces it as "the" link
				$discovered_via[$row['id']] = 'manual';
				$link_ports[$row['id']] = ['local' => $row['local_port_id'], 'remote' => $row['remote_port_id']];
				$shown[$row['id']] = ['edge_id' => $row['link_id'], 'attrs' => $link_attrs];
				unset($last_seen[$row['id']]);
			}
			$superseded_at[$row['id']] = $row_superseded;
			if (($link_attrs['discovered_via'] ?? 'lldp') !== 'manual') {
				if ($discovered_via[$row['id']] !== 'lldp') {
					$link_ports[$row['id']] = ['local' => $row['local_port_id'], 'remote' => $row['remote_port_id']];
					$shown[$row['id']] = ['edge_id' => $row['link_id'], 'attrs' => $link_attrs];
					$last_seen[$row['id']] = $row_last_seen;
					$discovery_source[$row['id']] = (string) ($link_attrs['discovered_via'] ?? 'lldp');
				}
				$discovered_via[$row['id']] = 'lldp';
			}
			elseif (!array_key_exists($row['id'], $last_seen)) {
				$last_seen[$row['id']] = $row_last_seen;
			}
		}

		// Device-level links (topology-1-model.md "`device_link` (Port → Device)"): a Device reached only through a link that ends at a
		// Device is a neighbor too. Both directions: a link from one of this Device's ports to the neighbor, and a link
		// from one of the neighbor's ports to this Device. A neighbor that is also reached by a physical_link keeps
		// that one (port-level precision wins), as it does for several physical links.
		$link_types = [];
		$device_level = DBselect(
			'SELECT device.id,device.attrs,link.attrs AS link_attrs,link.id AS link_id,local_port.id AS local_port_id,'.
				'NULL AS remote_port_id'.
			' FROM topo_nodes local_port'.
			' JOIN topo_edges link ON link.type='.zbx_dbstr('device_link').' AND link.src_id=local_port.id'.
			' JOIN topo_nodes device ON device.id=link.dst_id AND device.type='.zbx_dbstr('device').
			' WHERE local_port.device_id='.zbx_dbstr($deviceid).
			' UNION ALL '.
			'SELECT device.id,device.attrs,link.attrs AS link_attrs,link.id AS link_id,NULL AS local_port_id,'.
				'remote_port.id AS remote_port_id'.
			' FROM topo_edges link'.
			' JOIN topo_nodes remote_port ON remote_port.id=link.src_id'.
			' JOIN topo_nodes device ON device.id=remote_port.device_id AND device.type='.zbx_dbstr('device').
			' WHERE link.type='.zbx_dbstr('device_link').' AND link.dst_id='.zbx_dbstr($deviceid)
		);
		while ($row = DBfetch($device_level, false)) {
			if ((string) $row['id'] === $deviceid || isset($hidden_edges[$row['link_id']])) {
				continue;
			}
			$link_attrs = json_decode($row['link_attrs'], true, 512, JSON_THROW_ON_ERROR);
			$row_superseded = self::supersededAt($link_attrs);
			$row_last_seen = isset($link_attrs['last_seen']) ? (int) $link_attrs['last_seen'] : null;
			$source = (string) ($link_attrs['discovered_via'] ?? 'lldp');
			if (array_key_exists($row['id'], $neighbors)) {
				// Already reached by a physical_link: port-level precision wins. Already reached by another device-level
				// link: an active one only replaces a superseded one.
				if (!isset($link_types[$row['id']])
						|| !($row_superseded === null && ($superseded_at[$row['id']] ?? null) !== null)) {
					continue;
				}
			}
			else {
				$neighbors[$row['id']] = ['id' => $row['id'], 'type' => 'device', 'name' => self::attrs($row)['sysname'] ?? null,
					'monitoring_state' => null];
				$link_types[$row['id']] = 'device_link';
			}
			$link_ports[$row['id']] = ['local' => $row['local_port_id'], 'remote' => $row['remote_port_id']];
			$shown[$row['id']] = ['edge_id' => $row['link_id'], 'attrs' => $link_attrs];
			$discovered_via[$row['id']] = $source === 'manual' ? 'manual' : 'lldp';
			$discovery_source[$row['id']] = $source;
			$last_seen[$row['id']] = $row_last_seen;
			$superseded_at[$row['id']] = $row_superseded;
		}

		// Lost precision (topology-1-model.md "Lost precision"): some latest snapshot confirms the shown link only at device level.
		$precision_lower = [];
		if ($shown) {
			$cursor = DBselect('SELECT DISTINCT edge_id FROM topo_observations WHERE precision_lower=1 AND '.
				dbConditionId('edge_id', array_column($shown, 'edge_id')));
			while ($observation = DBfetch($cursor, false)) {
				$precision_lower[$observation['edge_id']] = true;
			}
		}
		$contradiction_by_edge = array_column($contradictions, null, 'edge_id');

		$represented_ids = self::getRepresentedIds(array_keys($neighbors));
		$visible_hostids = self::getVisibleHostIds();
		$representing_hostids = self::getRepresentingHostIds(array_keys($neighbors), $visible_hostids);
		$port_details = self::getPortDetails(array_merge(
			array_column($link_ports, 'local'), array_column($link_ports, 'remote')
		));
		// Link severity (topology-1-model.md "Link severity (derived)"): one trigger lookup for the items of every end port of every
		// shown link, not one per neighbor.
		$link_itemids = [];
		foreach ($link_ports as $id => $ends) {
			$link_itemids[$id] = array_merge($port_details[$ends['local']]['zabbix_itemids'] ?? [],
				$port_details[$ends['remote']]['zabbix_itemids'] ?? []);
		}
		$link_severity = self::getMaxActiveSeverityByItemIds($link_itemids);

		foreach ($neighbors as $id => &$neighbor) {
			// 'physical_link', or 'device_link' for a neighbor reached only through a link that ends at a Device (its
			// far port is unknown: no remote_port, and the frontend takes the line from the relations, not from here).
			$neighbor['link_type'] = $link_types[$id] ?? 'physical_link';
			$neighbor['represented'] = isset($represented_ids[$id]);
			$neighbor['represented_hostid'] = $representing_hostids[$id] ?? null;
			$neighbor['discovered_via'] = $discovered_via[$id];
			$neighbor['discovery_source'] = $discovery_source[$id];
			$neighbor['stale'] = self::isLinkStale($last_seen[$id] ?? null, $superseded_at[$id] ?? null);
			$neighbor['superseded_at'] = $superseded_at[$id] ?? null;
			// The rest of the per-link fields (topology-1-model.md "API"), all from the stored link that is shown.
			$link = $shown[$id];
			$link_attrs = $link['attrs'];
			$neighbor['edge_id'] = $link['edge_id'];
			$neighbor['severity'] = $link_severity[$id] ?? null;
			$neighbor['last_seen'] = isset($link_attrs['last_seen']) ? (int) $link_attrs['last_seen'] : null;
			$neighbor['last_seen_port'] = isset($link_attrs['last_seen_port']) ? (int) $link_attrs['last_seen_port']
				: $neighbor['last_seen'];
			$neighbor['precision_lower'] = isset($precision_lower[$link['edge_id']]);
			// far_port_reason and the hint exist on device_links only; the hint is untrusted text, escaped on render.
			$neighbor['far_port_reason'] = $neighbor['link_type'] === 'device_link'
				? (string) ($link_attrs['far_port_reason'] ?? '') : null;
			$neighbor['far_port_hint'] = $neighbor['link_type'] === 'device_link' && is_array($link_attrs['far_port_hint'] ?? null)
				? $link_attrs['far_port_hint'] : null;
			// Only a manual link has one: 'contradicted' (an unacknowledged hidden neighbor), 'acknowledged' (all of them are), or null.
			$entry = $contradiction_by_edge[$link['edge_id']] ?? null;
			$neighbor['contradiction'] = $entry === null ? null : ($entry['contradicted'] ? 'contradicted' : 'acknowledged');
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

	// topology-1-model.md "API": /topo/nodes/{id}/problems — id is a Host node id (never a Device or Proxy id). Zabbix has no
	// clean "this problem belongs to this proxy" semantics (problems attach to triggers, which attach
	// to hosts/items, not to the Proxy config object itself) — don't invent a heuristic for it; a
	// Proxy node simply has no Problems section in the UI (topology-1-model.md "Side panels").
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
			'connected_lldp' => [], 'connected_mac_only' => [], 'disconnected' => [], 'port_channel' => [], 'management' => [], 'virtual' => []
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
		$seen_ports = [];
		while ($row = DBfetch($result, false)) {
			// A superseded link (topology-1-model.md "Replacement of discovered links", superseded state) is not active: it neither connects the
			// port nor gives it a second row next to the link that replaced it.
			if ($row['linkid'] !== null
					&& self::supersededAt(json_decode($row['link_attrs'], true, 512, JSON_THROW_ON_ERROR)) !== null) {
				if (isset($seen_ports[$row['id']])) {
					continue;
				}
				$row['linkid'] = null;
				$row['link_attrs'] = null;
				$row['linked_port_id'] = null;
			}
			elseif ($row['linkid'] !== null && isset($seen_ports[$row['id']])) {
				// an active link after a superseded placeholder for the same port: it takes the port's row
				$rows = array_values(array_filter($rows, static fn (array $r): bool => $r['id'] !== $row['id']));
			}
			$seen_ports[$row['id']] = true;
			$rows[] = $row;
			if ($row['linkid'] !== null) {
				$connected_port_ids[] = $row['id'];
			}
		}
		$linked_names = self::getLinkedDeviceNames($connected_port_ids);

		// Device-level links (device-level-edge spec): a port whose link ends at a Device is connected too, to a Device
		// whose port is not known. An active one only; the port keeps its row either way.
		$device_level = [];
		$cursor = DBselect(
			'SELECT link.id,link.src_id,link.attrs,device.attrs AS device_attrs'.
			' FROM topo_edges link'.
			' JOIN topo_nodes port ON port.id=link.src_id AND port.device_id='.zbx_dbstr($deviceid).
			' JOIN topo_nodes device ON device.id=link.dst_id'.
			' WHERE link.type='.zbx_dbstr('device_link')
		);
		while ($link = DBfetch($cursor)) {
			$link_attrs = json_decode($link['attrs'], true, 512, JSON_THROW_ON_ERROR) ?: [];
			if (self::supersededAt($link_attrs) === null) {
				$device_level[$link['src_id']] = [
					'id' => $link['id'], 'discovered_via' => (string) ($link_attrs['discovered_via'] ?? 'lldp'),
					'reason' => (string) ($link_attrs['far_port_reason'] ?? ''),
					'device' => json_decode($link['device_attrs'], true)['sysname'] ?? null
				];
			}
		}

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
			elseif ($attrs['if_type'] === 'virtual') {
				// topology-1-model.md §6.6 / §11: virtual ports (loopback, tunnel, SVI, subinterface) have a group of their own, never Disconnected
				$group = 'virtual';
			}
			elseif ($row['linkid'] !== null || isset($device_level[$row['id']])) {
				$group = 'connected_lldp';
			}
			elseif ($attrs['oper_status'] === 'down') {
				$group = 'disconnected';
			}
			else {
				$group = 'connected_mac_only';
			}

			$device_link = $row['linkid'] === null ? ($device_level[$row['id']] ?? null) : null;
			$groups[$group][] = [
				'id' => $row['id'], 'port' => $attrs['name'], 'status' => $attrs['oper_status'],
				'connected_to' => $linked_names[$row['id']] ?? $device_link['device'] ?? null,
				'linked_port_id' => $row['linkid'] !== null ? $row['linked_port_id'] : null,
				// A device-level link: the link's id and why its far port is unknown (null for a port-level one).
				'device_link_id' => $device_link['id'] ?? null,
				'far_port_reason' => $device_link['reason'] ?? null,
				'source' => $device_link !== null ? ($device_link['discovered_via'] === 'manual' ? 'Manual' : 'LLDP')
					: ($discovered_via === 'manual' ? 'Manual' : ($row['linkid'] !== null ? 'LLDP'
					: ($group === 'connected_mac_only' ? 'MAC only' : '-')))
			];
		}

		return $groups;
	}

	/**
	 * A manual physical_link between two ports. When the active discovered link on the port already joins these two
	 * ports, nothing changes and that link is returned (topology-1-model.md "Manual links": creation, "Same ends already
	 * discovered").
	 *
	 * @return string  the edge id
	 */
	public static function linkPorts(string $src_port_id, string $dst_port_id, ?string $userid = null): string {
		if ($src_port_id === $dst_port_id) {
			throw new Exception('A port cannot be linked to itself.');
		}

		self::assertPortOnDevice($src_port_id);
		self::assertPortOnDevice($dst_port_id);

		foreach (self::getActiveLinksOnPort($src_port_id) as $link) {
			if ($link['type'] === 'physical_link' && self::isDiscovered($link)
					&& self::farPortOf($link, $src_port_id) === $dst_port_id) {
				return (string) $link['id'];
			}
		}

		self::claimPortsForManualLink([$src_port_id, $dst_port_id]);
		self::upsertPhysicalLink($src_port_id, $dst_port_id, 'manual', $userid);

		[$low, $high] = (int) $src_port_id < (int) $dst_port_id ? [$src_port_id, $dst_port_id] : [$dst_port_id, $src_port_id];

		return (string) DBfetch(DBselect('SELECT id FROM topo_edges WHERE type='.zbx_dbstr('physical_link').
			' AND src_id='.zbx_dbstr($low).' AND dst_id='.zbx_dbstr($high), 1))['id'];
	}

	/**
	 * Name of the author of a manual link (topology-5-access-control.md §3.11): only if the current user can see that user by Zabbix rules
	 * (user.get answers with the permissions of the caller), else null ("another user" in the UI). A value that is not a user id is an older
	 * link that stored a name: shown as it is.
	 */
	private static function userName($created_by): ?string {
		static $names = [];
		if ($created_by === null || $created_by === '') {
			return null;
		}
		$created_by = (string) $created_by;
		if (!ctype_digit($created_by)) {
			return $created_by;
		}
		if (!array_key_exists($created_by, $names)) {
			$user = API::User()->get(['userids' => [$created_by], 'output' => ['username']]);
			$names[$created_by] = $user ? (string) $user[0]['username'] : null;
		}

		return $names[$created_by];
	}

	private static function isDiscovered(array $link): bool {
		return (self::attrs($link)['discovered_via'] ?? null) !== 'manual';
	}

	/** The port at the other end of a physical_link row (src_id, dst_id) from $port_id. */
	private static function farPortOf(array $link, string $port_id): string {
		return (string) $link['src_id'] === $port_id ? (string) $link['dst_id'] : (string) $link['src_id'];
	}

	// ---- Manual device-level links (topology-1-model.md "Manual links": creation) ----

	/**
	 * The active (not superseded) links on a port, either type and either end of a physical_link. A device_link
	 * occupies only its local port (topology-1-model.md "Port uniqueness"), so it is looked up by src_id. Normally at
	 * most one; older data can hold more (topology-1-model.md "Legacy cleanup").
	 */
	private static function getActiveLinksOnPort(string $port_id, ?string $except_edge_id = null): array {
		$links = [];
		$result = DBselect('SELECT id,type,src_id,dst_id,attrs FROM topo_edges WHERE ('.
			'(type='.zbx_dbstr('physical_link').' AND (src_id='.zbx_dbstr($port_id).' OR dst_id='.zbx_dbstr($port_id).'))'.
			' OR (type='.zbx_dbstr('device_link').' AND src_id='.zbx_dbstr($port_id).'))');
		while ($row = DBfetch($result, false)) {
			if ($except_edge_id !== null && (string) $row['id'] === $except_edge_id) {
				continue;
			}
			if (self::supersededAt(self::attrs($row)) === null) {
				$links[] = $row;
			}
		}

		return $links;
	}

	private static function getActiveLinkOnPort(string $port_id, ?string $except_edge_id = null): ?array {
		return self::getActiveLinksOnPort($port_id, $except_edge_id)[0] ?? null;
	}

	/**
	 * Make the ports free for a new manual link (topology-1-model.md "Manual links": creation and "Manual wins"): reject
	 * if any of them already has an active MANUAL link, otherwise supersede the active discovered links on them at
	 * once (superseded_at = now; "Accept discovery" or a later ingest can revive them with the same edge id). Nothing is
	 * written when it rejects.
	 *
	 * @param string[] $port_ids
	 */
	private static function claimPortsForManualLink(array $port_ids): void {
		$discovered = [];
		foreach ($port_ids as $port_id) {
			foreach (self::getActiveLinksOnPort($port_id) as $link) {
				if ((self::attrs($link)['discovered_via'] ?? null) === 'manual') {
					throw new Exception('Port '.$port_id.' already has a manual link.');
				}
				$discovered[(string) $link['id']] = $link;
			}
		}

		$now = time();
		foreach ($discovered as $id => $link) {
			$attrs = self::attrs($link);
			$attrs['superseded_at'] = $now;
			DBexecute('UPDATE topo_edges SET attrs='.zbx_dbstr(json_encode($attrs)).' WHERE id='.zbx_dbstr($id));
		}
	}

	/**
	 * A manual device_link from a local Port to a Device whose port is not chosen. Same permission and entry point as
	 * a manual physical_link; far_port_reason = manual, no hint. An existing link on that pair is left alone (never
	 * downgraded), except a superseded one, which the operator declaring it again makes a manual link.
	 *
	 * @return string  the edge id
	 */
	public static function linkPortToDevice(string $port_id, string $device_id, string $userid): string {
		self::assertPortOnDevice($port_id);
		$device = DBfetch(DBselect('SELECT id FROM topo_nodes WHERE id='.zbx_dbstr($device_id).
			' AND type='.zbx_dbstr('device'), 1));
		if (!$device) {
			throw new Exception('Device '.$device_id.' does not exist.');
		}
		$own = DBfetch(DBselect('SELECT id FROM topo_nodes WHERE id='.zbx_dbstr($port_id).
			' AND device_id='.zbx_dbstr($device_id), 1));
		if ($own) {
			throw new Exception('A port cannot be linked to its own device.');
		}

		// The active discovered link on the port already reaches this Device (a device_link to it, or a physical_link
		// to one of its ports): nothing changes, that link is returned ("Same ends already discovered"). A manual one
		// falls through to the rejection below.
		foreach (self::getActiveLinksOnPort($port_id) as $link) {
			if (!self::isDiscovered($link)) {
				continue;
			}
			if ($link['type'] === 'device_link' && (string) $link['dst_id'] === $device_id) {
				return (string) $link['id'];
			}
			if ($link['type'] === 'physical_link' && DBfetch(DBselect('SELECT id FROM topo_nodes WHERE id='.
					zbx_dbstr(self::farPortOf($link, $port_id)).' AND device_id='.zbx_dbstr($device_id), 1))) {
				return (string) $link['id'];
			}
		}

		// At most one active link per port (topology-1-model.md "Port uniqueness"), both types counted: an active manual
		// link rejects, an active discovered one is superseded.
		self::claimPortsForManualLink([$port_id]);

		$existing = DBfetch(DBselect('SELECT id,attrs FROM topo_edges WHERE type='.zbx_dbstr('device_link').
			' AND src_id='.zbx_dbstr($port_id).' AND dst_id='.zbx_dbstr($device_id), 1));
		if ($existing) {
			$attrs = self::attrs($existing);
			if (self::supersededAt($attrs) !== null) {
				$attrs = ['discovered_via' => 'manual', 'far_port_reason' => 'manual', 'last_seen' => time(),
					'created_by' => $userid, 'created_at' => time()];
				DBexecute('UPDATE topo_edges SET attrs='.zbx_dbstr(json_encode($attrs)).' WHERE id='.zbx_dbstr($existing['id']));
			}

			return (string) $existing['id'];
		}

		DBexecute('INSERT INTO topo_edges (type,src_id,dst_id,attrs,created_at) VALUES ('.
			zbx_dbstr('device_link').','.zbx_dbstr($port_id).','.zbx_dbstr($device_id).','.
			zbx_dbstr(json_encode(['discovered_via' => 'manual', 'far_port_reason' => 'manual', 'last_seen' => time(),
				'created_by' => $userid, 'created_at' => time()])).','.time().')');

		return (string) DBfetch(DBselect('SELECT id FROM topo_edges WHERE type='.zbx_dbstr('device_link').
			' AND src_id='.zbx_dbstr($port_id).' AND dst_id='.zbx_dbstr($device_id), 1))['id'];
	}

	/**
	 * Remove a link by id, either type and either provenance (a discovered one is re-created if it is reported again,
	 * FR Lifecycle 5c).
	 */
	public static function removeLink(string $edge_id): void {
		$link = DBfetch(DBselect('SELECT id FROM topo_edges WHERE id='.zbx_dbstr($edge_id).' AND '.
			dbConditionString('type', self::LINK_TYPES), 1));
		if (!$link) {
			throw new Exception('Link '.$edge_id.' does not exist.');
		}
		DBexecute('DELETE FROM topo_edges WHERE id='.zbx_dbstr($edge_id).' AND '.dbConditionString('type', self::LINK_TYPES));
	}

	/**
	 * Convert a MANUAL link in place (same edge id): a device_link to a physical_link by choosing the far Device's
	 * port, or a physical_link to a device_link by keeping one of its ports and dropping the other port.
	 * Discovered links are never converted by hand: discovery owns their precision.
	 *
	 * @param string|null $far_port_id   device_link -> physical_link: a port of the link's far Device
	 * @param string|null $keep_port_id  physical_link -> device_link: the port that stays the local one
	 */
	public static function convertManualLink(string $edge_id, ?string $far_port_id, ?string $keep_port_id): void {
		$row = self::getManualLink($edge_id);
		$attrs = self::attrs($row);

		if ($row['type'] === 'device_link') {
			if ($far_port_id === null) {
				throw new Exception('Choose the far port.');
			}
			$far = DBfetch(DBselect('SELECT id FROM topo_nodes WHERE id='.zbx_dbstr($far_port_id).
				' AND type='.zbx_dbstr('port').' AND device_id='.zbx_dbstr($row['dst_id']), 1));
			if (!$far) {
				throw new Exception('That port does not belong to the linked device.');
			}
			if (self::getActiveLinkOnPort($far_port_id, $edge_id) !== null) {
				throw new Exception('Port '.$far_port_id.' already has a link.');
			}
			[$src, $dst] = (int) $row['src_id'] < (int) $far_port_id ? [$row['src_id'], $far_port_id]
				: [$far_port_id, $row['src_id']];
			$taken = DBfetch(DBselect('SELECT id FROM topo_edges WHERE type='.zbx_dbstr('physical_link').
				' AND src_id='.zbx_dbstr($src).' AND dst_id='.zbx_dbstr($dst), 1));
			if ($taken) {
				throw new Exception('These two ports are already linked.');
			}
			unset($attrs['far_port_reason'], $attrs['far_port_hint'], $attrs['last_seen_src'], $attrs['last_seen_dst']);
			DBexecute('UPDATE topo_edges SET type='.zbx_dbstr('physical_link').',src_id='.zbx_dbstr($src).
				',dst_id='.zbx_dbstr($dst).',attrs='.zbx_dbstr(json_encode($attrs)).' WHERE id='.zbx_dbstr($edge_id));

			return;
		}

		if ($keep_port_id === null || !in_array((string) $keep_port_id, [(string) $row['src_id'], (string) $row['dst_id']], true)) {
			throw new Exception('Choose which port of the link stays.');
		}
		$other_port = (string) $keep_port_id === (string) $row['src_id'] ? $row['dst_id'] : $row['src_id'];
		$device = DBfetch(DBselect('SELECT device_id FROM topo_nodes WHERE id='.zbx_dbstr($other_port).
			' AND type='.zbx_dbstr('port'), 1));
		if (!$device || $device['device_id'] === null) {
			throw new Exception('The other port has no device.');
		}
		$taken = DBfetch(DBselect('SELECT id FROM topo_edges WHERE type='.zbx_dbstr('device_link').
			' AND src_id='.zbx_dbstr($keep_port_id).' AND dst_id='.zbx_dbstr($device['device_id']), 1));
		if ($taken) {
			throw new Exception('That port already has a device-level link to the device.');
		}
		$attrs['far_port_reason'] = 'manual';
		DBexecute('UPDATE topo_edges SET type='.zbx_dbstr('device_link').',src_id='.zbx_dbstr($keep_port_id).
			',dst_id='.zbx_dbstr($device['device_id']).',attrs='.zbx_dbstr(json_encode($attrs)).
			' WHERE id='.zbx_dbstr($edge_id));
	}

	public static function unlinkPorts(string $src_port_id, string $dst_port_id): void {
		DBexecute('DELETE FROM topo_edges WHERE type='.zbx_dbstr('physical_link').
			' AND ((src_id='.zbx_dbstr($src_port_id).' AND dst_id='.zbx_dbstr($dst_port_id).
			') OR (src_id='.zbx_dbstr($dst_port_id).' AND dst_id='.zbx_dbstr($src_port_id).'))');
	}

	// topology-1-model.md "`represented_by` (Device → Host or Proxy)": represented_by is 1:1 on both ends, stored as three columns on the Device's own row
	// (represented_by_node_id/represented_by_matched_by/represented_by_at) — not a topo_edges row.
	// $matched_by defaults to 'manual' for the user-triggered /promote endpoint (no MAC validation
	// happens here, or ever — promotion is a deliberate user action per topology-1-model.md "Manual `/promote` and `/depromote`", independent of the
	// automatic reconciliation in reconcileHost()); reconcileHost() passes 'mac' explicitly, and
	// ingest.php's mirror passes 'reporter_self'. matched_mac is deliberately NOT accepted/stored here
	// — it has no consumer in the API/UI/acceptance criteria and would be a potentially stale snapshot
	// (topology-1-model.md "`represented_by` (Device → Host or Proxy)", matched_by). chassis_id is not a valid $matched_by value here — Device<->Host/Proxy
	// matching is MAC-only per topology-1-model.md "Automatic MAC match (Host only)"; chassis_id remains valid only for the unrelated
	// Device-to-itself upsert match (topology-1-model.md "Far Device").
	public static function promote(string $deviceid, string $target_type, string $target_id,
			string $matched_by = 'manual'): void {
		if (!in_array($target_type, ['host', 'proxy'], true)) {
			throw new Exception('The representation target must be a host or proxy.');
		}
		$ref_column = $target_type === 'host' ? 'host_ref' : 'proxy_ref';
		$target = $target_type === 'host'
			? API::Host()->get(['hostids' => [$target_id], 'output' => ['hostid'], 'limit' => 1])
			: API::Proxy()->get(['proxyids' => [$target_id], 'output' => ['proxyid'], 'limit' => 1]);
		if (!$target) {
			throw new Exception('The selected host or proxy does not exist or is not accessible.');
		}
		if (self::isRepresented($deviceid)) {
			throw new Exception('This device is already represented by a host or proxy — depromote it first.');
		}
		$target_node = DBfetch(DBselect('SELECT id FROM topo_nodes WHERE type='.zbx_dbstr($target_type).
			' AND '.$ref_column.'='.zbx_dbstr($target_id)), false);
		if ($target_node && self::isRepresentedTarget($target_node['id'])) {
			throw new Exception('This host/proxy is already represented by a device — depromote it first.');
		}
		$target_nodeid = $target_node['id'] ?? self::upsertPointerNode($target_type, $ref_column, $target_id);

		DBexecute('UPDATE topo_nodes SET represented_by_node_id='.zbx_dbstr($target_nodeid).
			',represented_by_matched_by='.zbx_dbstr($matched_by).',represented_by_at='.time().
			' WHERE id='.zbx_dbstr($deviceid).' AND type='.zbx_dbstr('device'));
	}

	// topology-1-model.md "Nodes: Storage", invariant 3: all three represented_by_* columns clear together, never just
	// represented_by_node_id — a partial clear would leave stale matched_by/at values behind.
	// the lazy-creation policy (topology-1-model.md "Lazy Host/Proxy nodes") makes this asymmetric with Device (Devices are never deleted by ingest): a Host/Proxy
	// node has no independent existence the way a Device does (topology-1-model.md "Nodes: Attributes by type" — it's a thin pointer, trivially
	// recreated from host_ref/proxy_ref on the next promote or reconciliation pass), so after
	// clearing this Device's own link, delete the target node too unless some other Device still
	// represents it (isRepresentedTarget() — covers both an automatic mac/reporter_self match and a
	// manual promotion by someone else). Leaving an orphaned node behind would silently reintroduce
	// the exact "node with nothing to show" clutter the lazy-creation policy exists to avoid.
	/** A Device whose represented_by points to a Proxy node (the promotion to a proxy is Super admin only). */
	public static function isRepresentedByProxy(string $deviceid): bool {
		return (bool) DBfetch(DBselect('SELECT dev.id FROM topo_nodes dev JOIN topo_nodes target ON target.id=dev.represented_by_node_id'.
			' WHERE dev.id='.zbx_dbstr($deviceid).' AND dev.type='.zbx_dbstr('device').' AND target.type='.zbx_dbstr('proxy'), 1));
	}

	public static function depromote(string $deviceid): void {
		$device = DBfetch(DBselect('SELECT represented_by_node_id FROM topo_nodes WHERE id='.zbx_dbstr($deviceid)), false);
		$target_nodeid = $device['represented_by_node_id'] ?? null;

		DBexecute('UPDATE topo_nodes SET represented_by_node_id=NULL,represented_by_matched_by=NULL,'.
			'represented_by_at=NULL WHERE id='.zbx_dbstr($deviceid));

		if ($target_nodeid !== null && !self::isRepresentedTarget($target_nodeid)) {
			DBexecute('DELETE FROM topo_nodes WHERE id='.zbx_dbstr($target_nodeid).
				' AND '.dbConditionString('type', ['host', 'proxy']));
		}
	}

	// topology-1-model.md "Automatic MAC match (Host only)": proxy.get is not called by this pull at all — Proxy has no automatic matching path (no MAC
	// source, reporter_self doesn't apply, topology-1-model.md "Lazy Host/Proxy nodes"), so there's nothing here to reconcile it
	// against; a Proxy node is only ever created via manual /promote.
	/**
	 * @return array  ['hosts' => hosts looked at, 'macs_shared' => ['count' => n, 'list' => [[kind, value, host_ids|node_ids]]]]:
	 *                a MAC in the inventory of more than one Host, or on more than one Device, matches nothing
	 *                (topology-1-model.md "Shared identifier (FR 4.h)")
	 */
	public static function pullHosts(): array {
		// topology-1-model.md "Automatic MAC match (Host only)": only on a match does a Host get a topo_nodes row at all — reconcileHost() (via
		// promote()) upserts the node and sets the representation together, in the same step. No
		// match → nothing written for that host. This does not reduce Zabbix API traffic versus the
		// old eager-upsert design — inventory still has to be pulled for every host to know whether
		// it matches — the savings are in topo_nodes row count and graph size, not API call volume.
		$hosts = API::Host()->get([
			'output' => ['hostid'],
			'selectInventory' => ['macaddress_a', 'macaddress_b']
		]);

		$hosts_by_mac = [];
		foreach ($hosts as $host) {
			foreach (['macaddress_a', 'macaddress_b'] as $field) {
				if (!empty($host['inventory'][$field])) {
					$hosts_by_mac[strtolower($host['inventory'][$field])][(string) $host['hostid']] = (int) $host['hostid'];
				}
			}
		}

		$shared = ['count' => 0, 'list' => []];
		foreach ($hosts as $host) {
			self::reconcileHost($host['hostid'], $host['inventory'] ?? [], $hosts_by_mac, $shared);
		}

		return ['hosts' => count($hosts), 'macs_shared' => $shared];
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
	// linkPorts() has already rejected an active manual link on either port and superseded the active discovered
	// ones, so a 'manual' call finds only a superseded edge for the pair (it revives it as manual); only 'lldp' is allowed
	// to upgrade a prior 'manual' edge, and discovery must never delete a manual link on its own (topology-1-model.md "Manual wins"); the DELETE endpoint
	// (unlinkPorts) is the only thing that removes a link, of either provenance.
	//
	// This method is only ever called with discovered_via='manual' (the manual /link endpoint above) —
	// discovery's own lldp-confirmed path lives in ingest.php's $ensure_physical_link(), which additionally
	// tracks attrs.last_seen_src/last_seen_dst per reporter side (topology-1-model.md "`physical_link` (Port ↔ Port)"). There's no reporter to attribute a
	// side to here, so per spec those two fields are deliberately left unset for manual links, never
	// zero-initialized or defaulted — only attrs.last_seen (kept for the staleness indicator, topology-1-model.md "Disappearance and staleness") is stamped.
	private static function upsertPhysicalLink(string $port_a, string $port_b, string $discovered_via, ?string $userid = null): void {
		// topology-1-model.md "`physical_link` (Port ↔ Port)": canonicalize direction (numerically smaller port id always src_id) so A→B and B→A collapse to
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
			if ($discovered_via === 'manual' && self::supersededAt($attrs) !== null) {
				// The user declares the pair again: it is a manual link now, and manual links are never superseded.
				$attrs['discovered_via'] = 'manual';
				$attrs['last_seen'] = time();
				if ($userid !== null) {
					$attrs['created_by'] = $userid;
					$attrs['created_at'] = time();
				}
				unset($attrs['superseded_at']);
				DBexecute('UPDATE topo_edges SET attrs='.zbx_dbstr(json_encode($attrs)).' WHERE id='.zbx_dbstr($existing['id']));
				return;
			}
			if ($discovered_via === 'lldp' && ($attrs['discovered_via'] ?? null) !== 'lldp') {
				$attrs['discovered_via'] = 'lldp';
				$attrs['last_seen'] = time();
				DBexecute('UPDATE topo_edges SET attrs='.zbx_dbstr(json_encode($attrs)).' WHERE id='.zbx_dbstr($existing['id']));
			}
			return;
		}

		DBexecute('INSERT INTO topo_edges (type,src_id,dst_id,attrs,created_at) VALUES ('.
			zbx_dbstr('physical_link').','.zbx_dbstr($port_a).','.zbx_dbstr($port_b).','.
			zbx_dbstr(json_encode(['discovered_via' => $discovered_via, 'last_seen' => time()]
				+ ($discovered_via === 'manual' && $userid !== null ? ['created_by' => $userid, 'created_at' => time()] : []))).','.time().')');
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


	// ---- Manual links contradicted by discovery (topology-1-model.md "Contradiction") ----
	//
	// Nothing here is stored by ingest: a manual link M is *contradicted* while a shadowing observation (outcome
	// `shadowed`, edge_id = M) has a hidden neighbor Device that M's attrs.shadow_ack does not list. Observations
	// mirror the latest snapshot of each rule, so no clock is involved. attrs.shadow_ack is written only by the
	// operator actions below; ingest never reads or writes it.

	/**
	 * Every manual link that is hidden-behind by discovery or has acknowledgments, with what the reader needs.
	 * remote_attrs strings come from the network (topology-1-model.md "Security: untrusted strings"): data, to be escaped by whoever renders them.
	 *
	 * @return array list of ['edge_id', 'port_a', 'port_b', 'device_a', 'device_b', 'port_a_name', 'port_b_name',
	 *               'device_a_name', 'device_b_name', 'created_at', 'acks' => [...], 'shadows' => [...],
	 *               'confirmed_from' => [host names], 'contradicted' => bool]
	 */
	public static function getContradictions(): array {
		$manual = [];
		$result = DBselect('SELECT id,type,src_id,dst_id,attrs,created_at FROM topo_edges WHERE '.
			dbConditionString('type', self::LINK_TYPES));
		while ($row = DBfetch($result, false)) {
			$attrs = self::attrs($row);
			if (($attrs['discovered_via'] ?? 'manual') === 'manual') {
				$manual[$row['id']] = ['row' => $row, 'attrs' => $attrs];
			}
		}
		if (!$manual) {
			return [];
		}

		$observations = [];
		$visible_hostids = self::getVisibleHostIds();
		if ($visible_hostids) {
			$cursor = DBselect(
				'SELECT o.id,o.local_port_id,o.remote_key,o.remote_attrs,o.outcome,o.edge_id,o.device_id,o.first_seen,'.
					'h.hostid,h.name AS host_name'.
				' FROM topo_observations o'.
				' JOIN items i ON i.itemid=o.itemid AND i.status='.ITEM_STATUS_ACTIVE.
				' JOIN topo_lld_snapshot snapshot ON snapshot.itemid=o.itemid AND snapshot.role=i.topology_role'.
				' JOIN hosts h ON h.hostid=i.hostid AND h.status='.HOST_STATUS_MONITORED.
				' WHERE '.dbConditionId('o.edge_id', array_keys($manual)).
					' AND '.dbConditionString('o.outcome', ['shadowed', 'applied']).
					' AND '.dbConditionId('h.hostid', $visible_hostids).
				' ORDER BY o.first_seen,o.id'
			);
			while ($row = DBfetch($cursor, false)) {
				$observations[$row['edge_id']][] = $row;
			}
		}

		$port_ids = [];
		$device_ids = [];
		foreach ($manual as $id => $link) {
			// A device_link ends at a Device (topology-1-model.md "`device_link` (Port → Device)"): dst_id is a node of type device, not a port.
			$port_ids[] = $link['row']['src_id'];
			if ($link['row']['type'] === 'physical_link') {
				$port_ids[] = $link['row']['dst_id'];
			}
			else {
				$device_ids[] = $link['row']['dst_id'];
			}
			foreach ($observations[$id] ?? [] as $observation) {
				$port_ids[] = $observation['local_port_id'];
				if ($observation['device_id'] !== null) {
					$device_ids[] = $observation['device_id'];
				}
			}
		}
		$ports = [];
		$cursor = DBselect('SELECT id,device_id,attrs FROM topo_nodes WHERE '.dbConditionId('id', array_values(array_unique($port_ids))).
			' AND type='.zbx_dbstr('port'));
		while ($row = DBfetch($cursor, false)) {
			$ports[$row['id']] = ['device_id' => $row['device_id'], 'name' => self::attrs($row)['name'] ?? null,
				'if_index' => isset(self::attrs($row)['if_index']) ? (int) self::attrs($row)['if_index'] : null];
			$device_ids[] = $row['device_id'];
		}
		$devices = [];
		$device_attrs = [];
		if ($device_ids) {
			$cursor = DBselect('SELECT id,attrs FROM topo_nodes WHERE '.dbConditionId('id', array_values(array_unique($device_ids))).
				' AND type='.zbx_dbstr('device'));
			while ($row = DBfetch($cursor, false)) {
				$device_attrs[$row['id']] = self::attrs($row);
				$devices[$row['id']] = $device_attrs[$row['id']]['sysname'] ?? null;
			}
		}

		$result = [];
		foreach ($manual as $id => $link) {
			$acks = [];
			foreach ($link['attrs']['shadow_ack'] ?? [] as $ack) {
				if (isset($ack['device_id'])) {
					$acks[(string) $ack['device_id']] = [
						'device_id' => (string) $ack['device_id'],
						'device_name' => $devices[(string) $ack['device_id']] ?? null,
						'remote_key' => (string) ($ack['remote_key'] ?? ''), 'at' => (int) ($ack['at'] ?? 0),
						'by' => (string) ($ack['by'] ?? '')
					];
				}
			}

			// The Ports and Devices the manual link itself joins. A neighbor that is one of those Devices is this link's own far
			// end seen at another precision (topology-1-model.md "Confirmation by discovery"): not a hidden neighbor, so not
			// a contradiction. The hidden neighbor itself is decided by topo_hidden_neighbor(), shared with ingest.php.
			$device_level = $link['row']['type'] === 'device_link';
			$end_ports = [(int) $link['row']['src_id']];
			if (!$device_level) {
				$end_ports[] = (int) $link['row']['dst_id'];
			}
			$end_devices = array_values(array_filter([
				(int) ($ports[$link['row']['src_id']]['device_id'] ?? 0),
				(int) ($device_level ? $link['row']['dst_id'] : ($ports[$link['row']['dst_id']]['device_id'] ?? 0))
			]));

			$shadows = [];
			$confirmed_from = [];
			foreach ($observations[$id] ?? [] as $observation) {
				if ($observation['outcome'] === 'applied') {
					$confirmed_from[$observation['host_name']] = true;
					continue;
				}
				$remote_attrs = json_decode((string) $observation['remote_attrs'], true) ?: [];
				$local_device = $ports[$observation['local_port_id']]['device_id'] ?? null;
				$verdict = topo_hidden_neighbor((int) $observation['local_port_id'],
					$local_device === null ? null : (int) $local_device,
					$observation['device_id'] === null ? null : (int) $observation['device_id'], $end_ports, $end_devices);
				if ($verdict['kind'] === 'own') {
					continue;
				}
				$hidden = $verdict['device_id'] === null ? null : (string) $verdict['device_id'];
				$shadows[] = [
					'observation_id' => $observation['id'],
					'local_port_id' => $observation['local_port_id'],
					'local_port' => $ports[$observation['local_port_id']]['name'] ?? null,
					'local_device_id' => $local_device,
					// Where the observation is from and what it saw. For a third-party observation the hidden neighbor is the
					// reporter (local_device_id), and the Device it saw (remote_device_id) is an end of the manual link.
					'remote_device_id' => $observation['device_id'],
					'remote_name' => $observation['device_id'] !== null ? ($devices[$observation['device_id']] ?? null) : null,
					'third_party' => $verdict['third_party'],
					'hidden_device_id' => $hidden,
					'hidden_name' => $hidden !== null ? ($devices[$hidden] ?? null) : null,
					// A third-party acknowledgment records the natural key of the reporter's local port (model "Acknowledgments").
					'remote_key' => $verdict['third_party']
						? topo_reporter_port_key($device_attrs[$local_device] ?? [], $ports[$observation['local_port_id']]['if_index'] ?? null)
						: $observation['remote_key'],
					'remote_port' => $remote_attrs['rem_port_desc'] ?? ($remote_attrs['rem_port'] ?? null),
					'first_seen' => (int) $observation['first_seen'],
					'reporter' => ['hostid' => $observation['hostid'], 'name' => $observation['host_name']],
					// A shadow without a Device cannot be acknowledged by Device: it counts as contradicting.
					'acknowledged' => $hidden !== null && isset($acks[$hidden])
				];
			}
			if (!$shadows && !$acks) {
				continue;
			}

			$device_b = $device_level ? $link['row']['dst_id'] : ($ports[$link['row']['dst_id']]['device_id'] ?? null);
			$result[] = [
				'edge_id' => $id, 'type' => $link['row']['type'],
				'port_a' => $link['row']['src_id'], 'port_b' => $device_level ? null : $link['row']['dst_id'],
				'port_a_name' => $ports[$link['row']['src_id']]['name'] ?? null,
				'port_b_name' => $device_level ? null : ($ports[$link['row']['dst_id']]['name'] ?? null),
				'device_a' => $ports[$link['row']['src_id']]['device_id'] ?? null,
				'device_b' => $device_b,
				'device_a_name' => $devices[$ports[$link['row']['src_id']]['device_id'] ?? 0] ?? null,
				'device_b_name' => $devices[$device_b ?? 0] ?? null,
				'created_at' => (int) $link['row']['created_at'],
				'acks' => array_values($acks),
				'shadows' => $shadows,
				'confirmed_from' => array_keys($confirmed_from),
				'contradicted' => (bool) array_filter($shadows, static fn (array $shadow): bool => !$shadow['acknowledged'])
			];
		}

		return $result;
	}

	/**
	 * Superseded edges that are not drawn while a manual link is contradicted (topology-1-model.md "Contradicted manual
	 * links"): for every unacknowledged shadowing observation, the ghost line is drawn from the reporter's Device to the
	 * Device it saw, and a superseded edge for the same port pair is not drawn next to it (one line, not two). Once the
	 * hidden neighbor is acknowledged the ghost goes and the superseded edge renders as usual.
	 *
	 * @param array $contradictions  the result of getContradictions()
	 *
	 * @return array  edge id => true
	 */
	private static function getEdgesHiddenByGhosts(array $contradictions): array {
		$hidden = [];
		foreach ($contradictions as $entry) {
			foreach ($entry['shadows'] as $shadow) {
				if ($shadow['acknowledged'] || $shadow['local_port_id'] === null || $shadow['remote_device_id'] === null) {
					continue;
				}
				$local = zbx_dbstr($shadow['local_port_id']);
				$remote_device = zbx_dbstr($shadow['remote_device_id']);
				$remote_ports = 'SELECT id FROM topo_nodes WHERE device_id='.$remote_device.' AND type='.zbx_dbstr('port');
				$cursor = DBselect('SELECT id,attrs FROM topo_edges WHERE ('.
					'type='.zbx_dbstr('physical_link').' AND ((src_id='.$local.' AND dst_id IN ('.$remote_ports.'))'.
						' OR (dst_id='.$local.' AND src_id IN ('.$remote_ports.'))))'.
					' OR (type='.zbx_dbstr('device_link').' AND src_id='.$local.' AND dst_id='.$remote_device.')');
				while ($row = DBfetch($cursor, false)) {
					if (self::supersededAt(self::attrs($row)) !== null) {
						$hidden[$row['id']] = true;
					}
				}
			}
		}

		return $hidden;
	}

	private static function getManualLink(string $edge_id): array {
		$row = DBfetch(DBselect('SELECT id,type,src_id,dst_id,attrs FROM topo_edges WHERE id='.zbx_dbstr($edge_id).
			' AND '.dbConditionString('type', self::LINK_TYPES)), false);
		if (!$row || (self::attrs($row)['discovered_via'] ?? 'manual') !== 'manual') {
			throw new Exception('Only a manual link can be resolved this way.');
		}

		return $row;
	}

	private static function getContradictionOf(string $edge_id): ?array {
		foreach (self::getContradictions() as $entry) {
			if ((string) $entry['edge_id'] === $edge_id) {
				return $entry;
			}
		}

		return null;
	}

	/**
	 * Accept discovery: allowed only while the link is contradicted. Removes the manual link; the discovered link
	 * is decided at the next full ingest from the stored snapshots (a superseded one is revived).
	 */
	public static function acceptDiscovery(string $edge_id): void {
		self::getManualLink($edge_id);
		$entry = self::getContradictionOf($edge_id);
		if ($entry === null || !$entry['contradicted']) {
			throw new Exception('This manual link is not contradicted by discovery.');
		}

		DBexecute('DELETE FROM topo_edges WHERE id='.zbx_dbstr($edge_id).' AND '.dbConditionString('type', self::LINK_TYPES));
	}

	/**
	 * Keep manual: acknowledge one hidden neighbor Device. Idempotent: the first entry for a Device is kept.
	 */
	public static function keepManual(string $edge_id, string $device_id, string $username): void {
		$row = self::getManualLink($edge_id);
		$entry = self::getContradictionOf($edge_id);
		$shadow = null;
		foreach ($entry['shadows'] ?? [] as $candidate) {
			if ((string) $candidate['hidden_device_id'] === $device_id) {
				$shadow = $candidate;
				break;
			}
		}
		if ($shadow === null) {
			throw new Exception('That device is not hidden by this manual link.');
		}

		$attrs = self::attrs($row);
		$acks = $attrs['shadow_ack'] ?? [];
		foreach ($acks as $ack) {
			if ((string) ($ack['device_id'] ?? '') === $device_id) {
				return;
			}
		}
		$acks[] = ['device_id' => (int) $device_id, 'remote_key' => $shadow['remote_key'], 'at' => time(), 'by' => $username];
		$attrs['shadow_ack'] = $acks;
		DBexecute('UPDATE topo_edges SET attrs='.zbx_dbstr(json_encode($attrs)).' WHERE id='.zbx_dbstr($edge_id));
	}

	/**
	 * Revoke one acknowledgment by Device; if that neighbor still shadows the link it is contradicted again at once.
	 */
	public static function revokeAcknowledgment(string $edge_id, string $device_id): void {
		$row = self::getManualLink($edge_id);
		$attrs = self::attrs($row);
		$acks = array_values(array_filter($attrs['shadow_ack'] ?? [],
			static fn (array $ack): bool => (string) ($ack['device_id'] ?? '') !== $device_id));
		if ($acks) {
			$attrs['shadow_ack'] = $acks;
		}
		else {
			unset($attrs['shadow_ack']);
		}
		DBexecute('UPDATE topo_edges SET attrs='.zbx_dbstr(json_encode($attrs)).' WHERE id='.zbx_dbstr($edge_id));
	}

	// ---- Duplicate candidates and operator merge (topology-1-model.md "Duplicate candidates and operator merge") ----
	//
	// Ingest lists possible duplicates in attrs.duplicate_candidates and never merges on weaker evidence than the reporter's own identity.
	// The operator decides: merge, accept a new chassis, or "not the same device" (attrs.duplicate_dismissed, written only here).

	// The ONE normalization of a port name (long/short vendor forms, case-insensitive): a mirror of $normalize_port_name in
	// database/topology/discovery/ingest.php; keep the two lists in sync.
	private static function normalizePortName(string $name): string {
		$lower = strtolower(trim($name));
		foreach (['gigabitethernet' => 'gi', 'tengigabitethernet' => 'te', 'fastethernet' => 'fa', 'port-channel' => 'po'] as $long => $short) {
			if (strncmp($lower, $long, strlen($long)) === 0) {
				return $short.substr($lower, strlen($long));
			}
		}

		return $lower;
	}

	private static function getDeviceRow(string $device_id): array {
		$row = DBfetch(DBselect('SELECT id,attrs,represented_by_node_id,represented_by_matched_by,represented_by_at,created_at FROM topo_nodes WHERE id='.
			zbx_dbstr($device_id).' AND type='.zbx_dbstr('device')), false);
		if (!$row) {
			throw new Exception('Device '.$device_id.' does not exist.');
		}

		return $row;
	}

	/** The distinct loc_chassis values the reporter host of a Device reports in its stored snapshots (empty: not a reporter). */
	private static function reportedChassis(array $device_row): array {
		if ($device_row['represented_by_node_id'] === null) {
			return [];
		}
		$host = DBfetch(DBselect('SELECT host_ref FROM topo_nodes WHERE id='.zbx_dbstr($device_row['represented_by_node_id']).' AND type='.zbx_dbstr('host')), false);
		if (!$host || $host['host_ref'] === null) {
			return [];
		}
		$values = [];
		$result = DBselect('SELECT rows_json FROM topo_lld_snapshot WHERE hostid='.zbx_dbstr($host['host_ref']));
		while ($snapshot = DBfetch($result, false)) {
			foreach (json_decode($snapshot['rows_json'], true) ?: [] as $row) {
				if (($row['loc_chassis'] ?? '') !== '') {
					$values[$row['loc_chassis']] = true;
				}
			}
		}

		return array_keys($values);
	}

	/**
	 * Merge: keep K, remove R (any two Devices, with or without a candidate). The merge steps of topology-1-model.md §6.7.1 (the same as the
	 * automatic join of ingest.php, $join_split_identity), plus the chassis rule of §6.7.5. Refused when both have a represented_by.
	 * The next full ingest re-resolves the observations from the stored snapshots.
	 *
	 * @return array  ['kept' => id, 'removed' => id]
	 */
	public static function mergeDevices(string $keep_id, string $remove_id, string $username): array {
		if ($keep_id === $remove_id) {
			throw new Exception('A Device cannot be merged into itself.');
		}
		$keep = self::getDeviceRow($keep_id);
		$remove = self::getDeviceRow($remove_id);
		if ($keep['represented_by_node_id'] !== null && $remove['represented_by_node_id'] !== null) {
			throw new Exception('Both Devices are represented by a host or proxy: they cannot be merged.');
		}
		$k = self::attrs($keep);
		$r = self::attrs($remove);

		// chassis (§6.7.5): K's own reporter reports R's chassis (RMA) -> K takes it and K's old chassis becomes a replaced one; otherwise K keeps its
		// keys and R's chassis goes to K's replaced_chassis; a K without a chassis takes R's
		$replaced = array_values(array_unique(array_merge($k['replaced_chassis'] ?? [], $r['replaced_chassis'] ?? [])));
		$k_chassis = (string) ($k['chassis_id'] ?? '');
		$r_chassis = (string) ($r['chassis_id'] ?? '');
		if ($r_chassis !== '' && $r_chassis !== $k_chassis) {
			if ($k_chassis === '') {
				$k['chassis_id'] = $r_chassis;
				if (($r['mac'] ?? null) !== null && ($k['mac'] ?? null) === null) {
					$k['mac'] = $r['mac'];
				}
			}
			elseif (in_array($r_chassis, self::reportedChassis($keep), true)) {
				$k['chassis_id'] = $r_chassis;
				$k['mac'] = $r['mac'] ?? $k['mac'] ?? null;
				$replaced[] = $k_chassis;
			}
			else {
				$replaced[] = $r_chassis;
			}
		}
		$replaced = array_values(array_unique(array_diff($replaced, [(string) ($k['chassis_id'] ?? '')])));
		if ($replaced) {
			$k['replaced_chassis'] = $replaced;
		}
		else {
			unset($k['replaced_chassis']);
		}

		DBstart();
		try {
			// represented_by moves from R when only R has one
			if ($remove['represented_by_node_id'] !== null) {
				DBexecute('UPDATE topo_nodes SET represented_by_node_id=NULL,represented_by_matched_by=NULL,represented_by_at=NULL WHERE id='.zbx_dbstr($remove_id));
				DBexecute('UPDATE topo_nodes SET represented_by_node_id='.zbx_dbstr($remove['represented_by_node_id']).',represented_by_matched_by='.
					zbx_dbstr($remove['represented_by_matched_by']).',represented_by_at='.zbx_dbstr($remove['represented_by_at']).' WHERE id='.zbx_dbstr($keep_id));
			}

			self::moveDevicePorts($keep_id, $remove_id);

			// device-level links that ended at R end at K; observations and shadow_ack that named R name K
			DBexecute('UPDATE IGNORE topo_edges SET dst_id='.zbx_dbstr($keep_id).' WHERE type='.zbx_dbstr('device_link').' AND dst_id='.zbx_dbstr($remove_id));
			DBexecute('DELETE FROM topo_edges WHERE type='.zbx_dbstr('device_link').' AND dst_id='.zbx_dbstr($remove_id));
			DBexecute('UPDATE topo_observations SET device_id='.zbx_dbstr($keep_id).' WHERE device_id='.zbx_dbstr($remove_id));
			$result = DBselect('SELECT id,attrs FROM topo_edges WHERE type IN ('.zbx_dbstr('physical_link').','.zbx_dbstr('device_link').') AND attrs LIKE '.zbx_dbstr('%shadow_ack%'));
			while ($edge = DBfetch($result, false)) {
				$attrs = self::attrs($edge);
				$changed = false;
				foreach ($attrs['shadow_ack'] ?? [] as $n => $entry) {
					if (isset($entry['device_id']) && (string) $entry['device_id'] === $remove_id) {
						$attrs['shadow_ack'][$n]['device_id'] = is_int($entry['device_id']) ? (int) $keep_id : $keep_id;
						$changed = true;
					}
				}
				if ($changed) {
					DBexecute('UPDATE topo_edges SET attrs='.zbx_dbstr(json_encode($attrs)).' WHERE id='.zbx_dbstr($edge['id']));
				}
			}

			// R's other attributes fill only what K has no value for
			foreach ($r as $key => $value) {
				if (in_array($key, ['last_seen', 'chassis_id', 'mac', 'replaced_chassis', 'duplicate_candidates', 'duplicate_dismissed', 'matched_by'], true)) {
					continue;
				}
				if (($k[$key] ?? null) === null || $k[$key] === '') {
					if ($value !== null && $value !== '') {
						$k[$key] = $value;
					}
				}
			}
			$k['last_seen'] = max((int) ($k['last_seen'] ?? 0), (int) ($r['last_seen'] ?? 0));
			unset($k['duplicate_candidates']);          // re-derived by the next full ingest
			DBexecute('UPDATE topo_nodes SET attrs='.zbx_dbstr(json_encode($k)).',updated_at='.time().' WHERE id='.zbx_dbstr($keep_id));

			// a link whose two ends are both on K now is deleted
			$result = DBselect('SELECT e.id,e.type,e.src_id,e.dst_id,s.device_id AS src_device,d.device_id AS dst_device FROM topo_edges e'.
				' JOIN topo_nodes s ON s.id=e.src_id LEFT JOIN topo_nodes d ON d.id=e.dst_id'.
				' WHERE e.type IN ('.zbx_dbstr('physical_link').','.zbx_dbstr('device_link').')');
			while ($edge = DBfetch($result, false)) {
				$dst = $edge['type'] === 'device_link' ? $edge['dst_id'] : $edge['dst_device'];
				if ((string) $edge['src_device'] === $keep_id && (string) $dst === $keep_id) {
					DBexecute('DELETE FROM topo_edges WHERE id='.zbx_dbstr($edge['id']));
				}
			}

			DBexecute('DELETE FROM topo_nodes WHERE id='.zbx_dbstr($remove_id).' AND type='.zbx_dbstr('device'));

			// §6.7.3 on K: a pseudo port that now has a real counterpart of the same normalized name is merged into it (links move)
			self::mergePseudoPortsOf($keep_id);
		}
		catch (Throwable $exception) {
			DBend(false);
			throw $exception;
		}
		DBend(true);

		return ['kept' => (int) $keep_id, 'removed' => (int) $remove_id];
	}

	/** B's Ports -> A: real by if_index into real, pseudo into any Port of A by normalized name only; the rest move. Links and observations follow. */
	private static function moveDevicePorts(string $a, string $b): void {
		$a_ports = [];
		$result = DBselect('SELECT id,attrs FROM topo_nodes WHERE device_id='.zbx_dbstr($a).' AND type='.zbx_dbstr('port'));
		while ($port = DBfetch($result, false)) {
			$a_ports[$port['id']] = self::attrs($port);
		}
		$b_ports = [];
		$result = DBselect('SELECT id,attrs FROM topo_nodes WHERE device_id='.zbx_dbstr($b).' AND type='.zbx_dbstr('port'));
		while ($port = DBfetch($result, false)) {
			$b_ports[$port['id']] = self::attrs($port);
		}

		foreach ($b_ports as $id => $attrs) {
			$target = null;
			if (!empty($attrs['pseudo'])) {
				$name = self::normalizePortName((string) ($attrs['name'] ?? ''));
				foreach ($a_ports as $a_id => $a_attrs) {
					if ($name !== '' && self::normalizePortName((string) ($a_attrs['name'] ?? '')) === $name) {
						$target = $a_id;
						break;
					}
				}
			}
			else {
				foreach ($a_ports as $a_id => $a_attrs) {
					if (empty($a_attrs['pseudo']) && (string) ($a_attrs['if_index'] ?? '') === (string) ($attrs['if_index'] ?? '#')) {
						$target = $a_id;
						break;
					}
				}
			}

			if ($target === null) {
				if (!empty($attrs['pseudo'])) {
					$taken = array_map(static fn (array $x): string => (string) ($x['if_index'] ?? ''), $a_ports);
					$index = (int) ($attrs['if_index'] ?? 0);
					if ($index > 0 || in_array((string) $index, $taken, true)) {
						$index = -((int) (crc32(self::normalizePortName((string) ($attrs['name'] ?? ''))) % 999999) + 1);
						while (in_array((string) $index, $taken, true)) {
							$index--;
						}
						$attrs['if_index'] = $index;
						DBexecute('UPDATE topo_nodes SET attrs='.zbx_dbstr(json_encode($attrs)).' WHERE id='.zbx_dbstr($id));
					}
				}
				DBexecute('UPDATE topo_nodes SET device_id='.zbx_dbstr($a).' WHERE id='.zbx_dbstr($id));
				$a_ports[$id] = $attrs;
				continue;
			}

			$target_attrs = $a_ports[$target];
			foreach ($attrs as $key => $value) {
				if (($target_attrs[$key] ?? null) === null || $target_attrs[$key] === '' || $target_attrs[$key] === []) {
					$target_attrs[$key] = $value;
				}
			}
			DBexecute('UPDATE topo_nodes SET attrs='.zbx_dbstr(json_encode($target_attrs)).' WHERE id='.zbx_dbstr($target));
			self::repointPort((string) $id, (string) $target);
			DBexecute('DELETE FROM topo_nodes WHERE id='.zbx_dbstr($id));
		}
	}

	/** Everything that referred to Port $from refers to Port $to: links (a duplicate is dropped), observations, lag members. */
	private static function repointPort(string $from, string $to): void {
		$edges = [];
		$result = DBselect('SELECT id,type,src_id,dst_id FROM topo_edges WHERE src_id='.zbx_dbstr($from).' OR dst_id='.zbx_dbstr($from));
		while ($edge = DBfetch($result, false)) {
			$edges[] = $edge;
		}
		foreach ($edges as $edge) {
			$src = (string) $edge['src_id'] === $from ? $to : (string) $edge['src_id'];
			$dst = (string) $edge['dst_id'] === $from ? $to : (string) $edge['dst_id'];
			if ($edge['type'] === 'physical_link' && (int) $src > (int) $dst) {
				[$src, $dst] = [$dst, $src];
			}
			$twin = DBfetch(DBselect('SELECT id FROM topo_edges WHERE src_id='.zbx_dbstr($src).' AND dst_id='.zbx_dbstr($dst)), false);
			if ($twin || $src === $dst) {
				DBexecute('DELETE FROM topo_edges WHERE id='.zbx_dbstr($edge['id']));
			}
			else {
				DBexecute('UPDATE topo_edges SET src_id='.zbx_dbstr($src).',dst_id='.zbx_dbstr($dst).' WHERE id='.zbx_dbstr($edge['id']));
			}
		}
		DBexecute('UPDATE IGNORE topo_observations SET local_port_id='.zbx_dbstr($to).' WHERE local_port_id='.zbx_dbstr($from));
		DBexecute('DELETE FROM topo_observations WHERE local_port_id='.zbx_dbstr($from));
		DBexecute('UPDATE topo_nodes SET lag_id='.zbx_dbstr($to).' WHERE lag_id='.zbx_dbstr($from));
	}

	/** §6.7.3 after a merge: a pseudo port of $device_id with exactly one real Port of the same normalized name moves its links there and is deleted. */
	private static function mergePseudoPortsOf(string $device_id): void {
		$real = [];
		$pseudo = [];
		$result = DBselect('SELECT id,attrs FROM topo_nodes WHERE device_id='.zbx_dbstr($device_id).' AND type='.zbx_dbstr('port'));
		while ($port = DBfetch($result, false)) {
			$attrs = self::attrs($port);
			if (!empty($attrs['pseudo'])) {
				$pseudo[$port['id']] = self::normalizePortName((string) ($attrs['name'] ?? ''));
			}
			else {
				$real[self::normalizePortName((string) ($attrs['name'] ?? ''))][] = $port['id'];
			}
		}
		foreach ($pseudo as $id => $name) {
			if ($name !== '' && count($real[$name] ?? []) === 1) {
				self::repointPort((string) $id, (string) $real[$name][0]);
				DBexecute('DELETE FROM topo_nodes WHERE id='.zbx_dbstr($id));
			}
		}
	}

	/**
	 * Accept new chassis: on a reporter's Device S with a `reporter_chassis_change` candidate and no Device C. S takes the host's loc_chassis Y,
	 * X goes to replaced_chassis.
	 */
	public static function acceptChassis(string $device_id, string $username): void {
		$row = self::getDeviceRow($device_id);
		$attrs = self::attrs($row);
		$entry = null;
		foreach ($attrs['duplicate_candidates'] ?? [] as $candidate) {
			if ($candidate['reason'] === 'reporter_chassis_change') {
				if ($candidate['device_id'] !== null) {
					throw new Exception('Another Device has that chassis: merge it instead.');
				}
				$entry = $candidate;
			}
		}
		if ($entry === null) {
			throw new Exception('This Device has no chassis change to accept.');
		}
		$reported = self::reportedChassis($row);
		if ($reported !== [$entry['value']]) {
			throw new Exception('The host no longer reports that chassis.');
		}
		$old = (string) ($attrs['chassis_id'] ?? '');
		$replaced = $attrs['replaced_chassis'] ?? [];
		if ($old !== '' && $old !== $entry['value']) {
			$replaced[] = $old;
		}
		$attrs['chassis_id'] = $entry['value'];
		$attrs['mac'] = preg_match('/^([0-9a-f]{2}:){5}[0-9a-f]{2}$/i', $entry['value']) ? strtolower($entry['value']) : null;
		$attrs['replaced_chassis'] = array_values(array_unique(array_diff($replaced, [$entry['value']])));
		if (!$attrs['replaced_chassis']) {
			unset($attrs['replaced_chassis']);
		}
		$attrs['duplicate_candidates'] = array_values(array_filter($attrs['duplicate_candidates'] ?? [],
			static fn (array $c): bool => $c['reason'] !== 'reporter_chassis_change'));
		if (!$attrs['duplicate_candidates']) {
			unset($attrs['duplicate_candidates']);
		}
		DBexecute('UPDATE topo_nodes SET attrs='.zbx_dbstr(json_encode($attrs)).',updated_at='.time().' WHERE id='.zbx_dbstr($device_id));
	}

	/** Not the same device: the other Device's id goes to attrs.duplicate_dismissed of both (with at and by), and out of both candidate lists. */
	public static function dismissDuplicate(string $a, string $b, string $username): void {
		if ($a === $b) {
			throw new Exception('Choose two different Devices.');
		}
		$rows = [$a => self::getDeviceRow($a), $b => self::getDeviceRow($b)];
		foreach ([$a => $b, $b => $a] as $device_id => $other) {
			$attrs = self::attrs($rows[$device_id]);
			$entries = $attrs['duplicate_dismissed'] ?? [];
			$known = false;
			foreach ($entries as $entry) {
				$known = $known || (string) ($entry['device_id'] ?? '') === (string) $other;
			}
			if (!$known) {
				$entries[] = ['device_id' => (int) $other, 'at' => time(), 'by' => $username];
			}
			$attrs['duplicate_dismissed'] = $entries;
			$attrs['duplicate_candidates'] = array_values(array_filter($attrs['duplicate_candidates'] ?? [],
				static fn (array $c): bool => (string) ($c['device_id'] ?? '') !== (string) $other));
			if (!$attrs['duplicate_candidates']) {
				unset($attrs['duplicate_candidates']);
			}
			DBexecute('UPDATE topo_nodes SET attrs='.zbx_dbstr(json_encode($attrs)).' WHERE id='.zbx_dbstr($device_id));
		}
	}

	/** Revoke "not the same device": the pair is listed again by the next full ingest if the evidence is still there. */
	public static function revokeDismissal(string $a, string $b): void {
		foreach ([$a => $b, $b => $a] as $device_id => $other) {
			$attrs = self::attrs(self::getDeviceRow((string) $device_id));
			$entries = array_values(array_filter($attrs['duplicate_dismissed'] ?? [],
				static fn (array $entry): bool => (string) ($entry['device_id'] ?? '') !== (string) $other));
			if ($entries) {
				$attrs['duplicate_dismissed'] = $entries;
			}
			else {
				unset($attrs['duplicate_dismissed']);
			}
			DBexecute('UPDATE topo_nodes SET attrs='.zbx_dbstr(json_encode($attrs)).' WHERE id='.zbx_dbstr($device_id));
		}
	}

	const OBSERVATION_OUTCOMES = ['applied', 'device_only', 'shadowed', 'conflict', 'ambiguous'];

	/**
	 * NEIGHBORS evidence recorded by ingest (topology-1-model.md "Observations", "API"): one row per neighbor a
	 * discovery rule reported, with what ingest made of it. Only observations of rules on hosts the current
	 * user can see are returned.
	 *
	 * Every string in `remote_attrs` and the port/host names comes from LLDP/CDP or the network (topology-1-model.md "Security: untrusted strings"):
	 * they are returned as data and must be escaped by whoever renders them — never inserted as HTML.
	 *
	 * @param array|null $outcomes   restrict to these outcomes; null = everything except 'applied'
	 * @param string|null $device_id  restrict to observations resolved to this remote Device
	 * @param string|null $precision  'port' or 'device' (topology-1-model.md "API"): only observations whose
	 *                                far end was identified that precisely
	 */
	public static function getObservations(?array $outcomes = null, ?string $device_id = null,
			int $limit = 500, bool $contradicted_only = false, ?string $precision = null): array {
		$outcomes ??= array_values(array_diff(self::OBSERVATION_OUTCOMES, ['applied']));
		if ($contradicted_only) {
			// topology-1-model.md "API" (contradicted filter): shadowed and not acknowledged.
			$outcomes = ['shadowed'];
		}
		$visible_hostids = self::getVisibleHostIds();
		if (!$visible_hostids) {
			return [];
		}

		$sql = 'SELECT o.id,o.itemid,o.local_port_id,o.remote_key,o.remote_attrs,o.outcome,o.edge_id,o.device_id,'.
				'o.link_precision,o.far_port_reason,o.precision_lower,o.first_seen,o.last_seen,i.name AS rule_name,h.hostid,h.name AS host_name,port.attrs AS port_attrs,'.
				'port.device_id AS local_device_id,'.
				'remote.attrs AS device_attrs'.
			' FROM topo_observations o'.
			// Only observations of a rule that still has a usable snapshot: enabled rule on an enabled host, snapshot of its current role
			// (the ingest removes the others at the next full run; this keeps them out of sight until then).
			' JOIN items i ON i.itemid=o.itemid AND i.status='.ITEM_STATUS_ACTIVE.
			' JOIN topo_lld_snapshot snapshot ON snapshot.itemid=o.itemid AND snapshot.role=i.topology_role'.
			' JOIN hosts h ON h.hostid=i.hostid AND h.status='.HOST_STATUS_MONITORED.
			' JOIN topo_nodes port ON port.id=o.local_port_id'.
			' LEFT JOIN topo_nodes remote ON remote.id=o.device_id'.
			' WHERE '.dbConditionString('o.outcome', $outcomes).
				' AND '.dbConditionId('h.hostid', $visible_hostids);
		if ($device_id !== null) {
			$sql .= ' AND o.device_id='.zbx_dbstr($device_id);
		}
		if ($precision !== null) {
			$sql .= ' AND o.link_precision='.zbx_dbstr($precision);
		}
		$sql .= ' ORDER BY o.last_seen DESC,o.id DESC';

		$result = [];
		$cursor = DBselect($sql, $contradicted_only ? null : $limit);
		$rows = [];
		while ($row = DBfetch($cursor, false)) {
			$rows[] = $row;
		}
		// Acknowledgments of the manual links that shadow these rows (attrs.shadow_ack, by hidden Device), and the Ports and
		// Devices each manual link joins: the hidden neighbor of a row is decided by topo_hidden_neighbor(), the definition
		// shared with ingest.php and getContradictions() (third party: the reporter, not the end of the link it reports).
		$acknowledged_devices = [];
		$edge_ends = [];
		$edge_ids = array_values(array_unique(array_filter(array_column($rows, 'edge_id'))));
		if ($edge_ids) {
			$edge_rows = [];
			$end_port_ids = [];
			$edges = DBselect('SELECT id,type,src_id,dst_id,attrs FROM topo_edges WHERE '.dbConditionId('id', $edge_ids));
			while ($edge = DBfetch($edges, false)) {
				$edge_rows[$edge['id']] = $edge;
				$end_port_ids[] = $edge['src_id'];
				if ($edge['type'] === 'physical_link') {
					$end_port_ids[] = $edge['dst_id'];
				}
				foreach (self::attrs($edge)['shadow_ack'] ?? [] as $ack) {
					$acknowledged_devices[$edge['id']][(string) ($ack['device_id'] ?? '')] = true;
				}
			}
			$port_device = [];
			if ($end_port_ids) {
				$cursor = DBselect('SELECT id,device_id FROM topo_nodes WHERE '.dbConditionId('id', array_values(array_unique($end_port_ids))));
				while ($port = DBfetch($cursor, false)) {
					$port_device[$port['id']] = (int) $port['device_id'];
				}
			}
			foreach ($edge_rows as $edge_id => $edge) {
				$ports_of_edge = [(int) $edge['src_id']];
				$devices_of_edge = [$port_device[$edge['src_id']] ?? 0];
				if ($edge['type'] === 'physical_link') {
					$ports_of_edge[] = (int) $edge['dst_id'];
					$devices_of_edge[] = $port_device[$edge['dst_id']] ?? 0;
				}
				else {
					$devices_of_edge[] = (int) $edge['dst_id'];
				}
				$edge_ends[$edge_id] = ['ports' => $ports_of_edge, 'devices' => array_values(array_filter($devices_of_edge))];
			}
		}
		// DBfetch(..., false): with the default $convertNulls a NULL edge_id/device_id would come back as '0'
		// (GOTCHAS.md #1), which is exactly the "no edge / no remote Device" state that must stay null.
		foreach ($rows as $row) {
			$acknowledged = false;
			$not_hidden = false;      // a shadow of the link's own far end at another precision: not contradicting
			if ($row['outcome'] === 'shadowed' && $row['edge_id'] !== null && isset($edge_ends[$row['edge_id']])) {
				$verdict = topo_hidden_neighbor((int) $row['local_port_id'],
					$row['local_device_id'] === null ? null : (int) $row['local_device_id'],
					$row['device_id'] === null ? null : (int) $row['device_id'],
					$edge_ends[$row['edge_id']]['ports'], $edge_ends[$row['edge_id']]['devices']);
				$not_hidden = $verdict['kind'] === 'own';
				$acknowledged = $verdict['kind'] === 'hidden'
					&& isset($acknowledged_devices[$row['edge_id']][(string) $verdict['device_id']]);
			}
			if ($contradicted_only && ($acknowledged || $not_hidden)) {
				continue;
			}
			$port_attrs = json_decode((string) $row['port_attrs'], true) ?: [];
			$device_attrs = json_decode((string) $row['device_attrs'], true) ?: [];
			$result[] = [
				'id' => $row['id'],
				'outcome' => $row['outcome'],
				'reporter' => ['hostid' => $row['hostid'], 'name' => $row['host_name']],
				'rule' => ['itemid' => $row['itemid'], 'name' => $row['rule_name']],
				'local_port' => ['id' => $row['local_port_id'], 'name' => $port_attrs['name'] ?? null,
					'if_index' => $port_attrs['if_index'] ?? null],
				'remote_key' => $row['remote_key'],
				'remote_attrs' => json_decode((string) $row['remote_attrs'], true) ?: [],
				'remote_device' => $row['device_id'] === null ? null
					: ['id' => $row['device_id'], 'name' => $device_attrs['sysname'] ?? null],
				// The link that blocked this observation (shadowed/conflict) or that it confirmed (applied).
				'edge_id' => $row['edge_id'],
				// How precisely the far end was identified: 'port', or 'device' (the far Device is known, its port is not)
				// with the reason why; null when the observation made no link. precision_lower: it confirms a
				// port-level link only at device level.
				'precision' => $row['link_precision'],
				'far_port_reason' => $row['far_port_reason'],
				'precision_lower' => (bool) $row['precision_lower'],
				'first_seen' => (int) $row['first_seen'],
				'last_seen' => (int) $row['last_seen'],
				// How long the observation has been continuously present: a `conflict` younger than the
				// reporter's NEIGHBORS interval is the expected transient after a recable
				// (topology-1-model.md "Replacement of discovered links", "Observations"), so a UI can filter on it.
				'age' => max(0, time() - (int) $row['first_seen']),
				// Only meaningful for `shadowed`: the operator chose "keep manual" for this hidden neighbor.
				'acknowledged' => $row['outcome'] === 'shadowed' ? $acknowledged : null
			];
		}

		return $contradicted_only ? array_slice($result, 0, $limit) : $result;
	}

	// Resolves the highest-severity *active* trigger (value=TRIGGER_VALUE_TRUE, i.e. currently in problem
	// state) attached to any of zabbix_itemids across the given ports — oper_status and trigger severity are
	// deliberately kept apart (topology-1-model.md "Link severity (derived)"): a port can be oper_status=up and still carry an active trigger.
	// Node-level severity for a Host on the graph itself (topology-1-model.md "Node styles": "Host: solid fill, colored by Zabbix
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

	// topology-1-model.md "Node styles": Proxy shares Host's severity-colored fill, but Zabbix has no proxyid linkage on problem/trigger/item
	// to resolve it from — see getProxyHealthItemIds() for how this is actually found. (Note: this is
	// the Proxy node's own graph fill color, unrelated to the Problems side-panel section (topology-1-model.md "Side panels"), which is
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

	/**
	 * The highest active trigger severity per key, for several item sets in ONE trigger lookup (no query per key). Keys
	 * with no active trigger (or no items) are absent from the result.
	 *
	 * @param array $itemids_by_key  key => list of item ids
	 *
	 * @return array  key => severity (0..5)
	 */
	private static function getMaxActiveSeverityByItemIds(array $itemids_by_key): array {
		$all = array_values(array_unique(array_merge(...array_values($itemids_by_key ?: [[]]))));
		if (!$all) {
			return [];
		}

		$by_item = [];
		foreach (API::Trigger()->get([
			'output' => ['priority'],
			'itemids' => $all,
			'selectItems' => ['itemid'],
			'filter' => ['value' => TRIGGER_VALUE_TRUE]
		]) as $trigger) {
			foreach ($trigger['items'] as $item) {
				$by_item[$item['itemid']] = max($by_item[$item['itemid']] ?? 0, (int) $trigger['priority']);
			}
		}

		$result = [];
		foreach ($itemids_by_key as $key => $itemids) {
			foreach ($itemids as $itemid) {
				if (isset($by_item[$itemid])) {
					$result[$key] = max($result[$key] ?? 0, $by_item[$itemid]);
				}
			}
		}

		return $result;
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
		return (bool) DBfetch(DBselect('SELECT id FROM topo_nodes WHERE id='.zbx_dbstr($deviceid).
			' AND represented_by_node_id IS NOT NULL', 1));
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
		$result = DBselect('SELECT id FROM topo_nodes WHERE represented_by_node_id IS NOT NULL'.
			' AND '.dbConditionId('id', $ids));
		while ($row = DBfetch($result)) {
			$represented[$row['id']] = true;
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
		$result = DBselect('SELECT dev.id AS device_id,host.hostid'.
			' FROM topo_nodes dev'.
			' JOIN topo_nodes host_node ON host_node.id=dev.represented_by_node_id AND host_node.type='.zbx_dbstr('host').
			' JOIN hosts host ON host.hostid=host_node.host_ref'.
			' WHERE '.dbConditionId('dev.id', $device_ids).
				' AND '.dbConditionId('host.hostid', $visible_hostids));
		while ($row = DBfetch($result)) {
			$map[$row['device_id']] = $row['hostid'];
		}

		return $map;
	}

	private static function isRepresentedTarget(string $target_id): bool {
		return (bool) DBfetch(DBselect('SELECT id FROM topo_nodes WHERE type='.zbx_dbstr('device').
			' AND represented_by_node_id='.zbx_dbstr($target_id), 1));
	}

	private static function getLinkedDeviceName(string $portid): ?string {
		$row = DBfetch(DBselect('SELECT device.attrs FROM topo_edges link'.
			' JOIN topo_nodes port ON port.id=CASE WHEN link.src_id='.zbx_dbstr($portid).' THEN link.dst_id ELSE link.src_id END'.
			' JOIN topo_nodes device ON device.id=port.device_id WHERE link.type='.zbx_dbstr('physical_link').
			' AND (link.src_id='.zbx_dbstr($portid).' OR link.dst_id='.zbx_dbstr($portid).')', 1));
		if ($row) {
			return self::attrs($row)['sysname'];
		}

		// A device-level link (topology-1-model.md "`device_link` (Port → Device)") ends at the Device itself: no Port hop.
		$row = DBfetch(DBselect('SELECT device.attrs FROM topo_edges link'.
			' JOIN topo_nodes device ON device.id=link.dst_id AND device.type='.zbx_dbstr('device').
			' WHERE link.type='.zbx_dbstr('device_link').' AND link.src_id='.zbx_dbstr($portid), 1));

		return $row ? (self::attrs($row)['sysname'] ?? null) : null;
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
				'speed' => $attrs['speed'] ?? null,
				'zabbix_itemids' => array_map('strval', $attrs['zabbix_itemids'] ?? [])
			];
		}

		return $details;
	}

	// host.get's selectInterfaces never returns a 'mac' field (the Zabbix interface table only has
	// hostid/type/ip/dns/port/useip/main) — this can never match against a real Zabbix instance. The actual
	// MAC source is host_inventory.macaddress_a/macaddress_b (host.get selectInventory), populated only when
	// the host uses Automatic inventory mode with a system.hw.macaddr item — most hosts on a real instance
	// won't have it set, and an empty/missing value is a normal non-match, not an error, same as any other
	// unmatched host. Device<->Host/Proxy matching is MAC-only (topology-1-model.md "Automatic MAC match (Host only)") — there is no generic
	// Zabbix host field carrying an LLDP chassis ID; host_inventory.chassis is an unrelated free-text
	// inventory field, not a valid match key here (chassis_id remains valid only for the separate
	// Device-to-itself upsert match (topology-1-model.md "Far Device"), which this method has nothing to do with). The same MAC gap
	// applies to Proxy (CProxy::get has no interface/inventory concept at all), so the "run Device
	// reconciliation for each Proxy" is not implemented, and pullHosts() never calls proxy.get at
	// all — a Proxy node is only ever created via manual /promote.
	private static function reconcileHost(string $hostid, array $inventory, array $hosts_by_mac, array &$shared): void {
		$note = static function (string $kind, string $mac, array $ids) use (&$shared): void {
			foreach ($shared['list'] as $entry) {
				if ($entry['kind'] === $kind && $entry['value'] === $mac) {
					return;
				}
			}
			$shared['count']++;
			if (count($shared['list']) < 200) {
				$shared['list'][] = ['kind' => $kind, 'value' => $mac, ($kind === 'host' ? 'host_ids' : 'node_ids') => array_values($ids)];
			}
		};

		foreach (['macaddress_a', 'macaddress_b'] as $field) {
			if (empty($inventory[$field])) {
				continue;
			}
			$mac = strtolower($inventory[$field]);

			// topology-1-model.md "Shared identifier": a MAC in the inventory of several Hosts matches nothing
			if (count($hosts_by_mac[$mac] ?? []) > 1) {
				$note('host', $mac, $hosts_by_mac[$mac]);
				continue;
			}

			$devices = [];
			$result = DBselect('SELECT device_id FROM topo_nodes port'.
				' WHERE port.type='.zbx_dbstr('port').' AND port.device_id IS NOT NULL'.
				' AND port.attrs LIKE '.zbx_dbstr('%"mac":"'.$mac.'"%'));
			while ($device = DBfetch($result)) {
				$devices[(int) $device['device_id']] = (int) $device['device_id'];
			}

			// a MAC on several Devices matches nothing either
			if (count($devices) > 1) {
				$note('device', $mac, $devices);
				continue;
			}

			foreach ($devices as $device_id) {
				try {
					self::promote((string) $device_id, 'host', $hostid, 'mac');
				}
				catch (Exception $exception) {
					// the 1:1 constraint (topology-1-model.md "`represented_by` (Device → Host or Proxy)") applies here too, not just to the manual endpoint: the device or
					// this host is already represented elsewhere. Skip this candidate — it's a normal "no
					// match" outcome for automatic reconciliation, not a failure worth aborting the pull over.
					continue;
				}
			}
		}
	}
}
