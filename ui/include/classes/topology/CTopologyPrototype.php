<?php

// The one definition of the hidden neighbor of a contradicted manual link, shared with database/topology/discovery/ingest.php.
require_once __DIR__.'/../../../../database/topology/topology_contradiction.inc.php';
require_once __DIR__.'/../../../../database/topology/topology_freshness.inc.php';

class CTopologyPrototype {

	// topology-1-model.md "`device_link` (Port → Device)": why a device_link ends at a Device and not at a Port. fdb_mac_only is
	// reserved (accepted, never produced); manual is what an operator-made device_link carries.
	public const FAR_PORT_REASONS = ['port_unmatched', 'port_shared_id', 'lag_ambiguous', 'manual', 'fdb_mac_only'];
	public const LINK_TYPES = ['physical_link', 'device_link'];


	/** A load value older than this many seconds is not shown (topology-1-model.md §4.8). */
	private const LOAD_RECENT = 900;

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
	/**
	 * topology-1-model.md §12.2 "Label": the first value that is not empty. Host or Proxy: its visible name. Device: `sysname`, then `chassis_id` (a text subtype as it
	 * is, a MAC in its normal form), then `mgmt_ip`, then `?` (the side panel shows the keys). The values come from the network and are returned as they are: the
	 * caller escapes them when it renders them (§13); the endpoint encodes them with JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT.
	 */
	public static function nodeLabel(string $type, ?string $name = null, ?string $sysname = null, ?string $chassis_id = null, ?string $mgmt_ip = null): string {
		$candidates = $type === 'device' ? [$sysname, self::chassisLabel($chassis_id), $mgmt_ip] : [$name];

		foreach ($candidates as $value) {
			if ($value !== null && trim($value) !== '') {
				return $value;
			}
		}

		return '?';
	}

	// A chassis id that is a MAC in any text form (aa:bb:.., aa-bb-.., aabb.ccdd.eeff, aabbccddeeff) in its normal form (lower case, colons); any other text as it is.
	private static function chassisLabel(?string $chassis_id): ?string {
		if ($chassis_id !== null && preg_match('/^[0-9a-fA-F]{2}([:.-]?[0-9a-fA-F]{2}){5}$/', $chassis_id) === 1) {
			return implode(':', str_split(strtolower(preg_replace('/[^0-9a-fA-F]/', '', $chassis_id)), 2));
		}

		return $chassis_id;
	}

	/** @param array $transient_hostids  hostids of the hosts without a Host node that the view draws (model 2.95 §12.1): nodes `host:<hostid>`, nothing is stored */
	public static function getDevices(?array $node_ids = null, array $transient_hostids = []): array {
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
				// 2.71 (§12.1, FR-C V-1): a Host node without a Device is data of the view too; the page draws it only by the rule for a node without a link
				' OR (node.type='.zbx_dbstr('host').' AND host.hostid IS NOT NULL'.
					' AND '.($visible_hostids ? dbConditionId('host.hostid', $visible_hostids) : '1=0').')'.
				' OR (node.type='.zbx_dbstr('proxy').' AND proxy.proxyid IS NOT NULL AND rep.id IS NOT NULL)'.
			')';
		if ($node_ids !== null) {
			// Empty scope (e.g. a host group with no members) must return no nodes, not every
			// node — spelled out explicitly rather than relying on dbConditionId()'s behavior
			// for an empty array.
			$sql .= $node_ids ? ' AND '.dbConditionId('node.id', $node_ids) : ' AND 1=0';
		}
		$rows = DBfetchArray(DBselect($sql));
		// the transient nodes: the live data of the host, the visible hosts only, and only a host that still has no Host node
		$transient_hostids = $transient_hostids ? array_values(array_intersect($transient_hostids, $visible_hostids)) : [];
		if ($transient_hostids) {
			$result = DBselect('SELECT h.hostid,h.name,h.status,h.maintenance_status FROM hosts h WHERE '.dbConditionId('h.hostid', $transient_hostids).
				' AND NOT EXISTS (SELECT 1 FROM topo_nodes n WHERE n.type='.zbx_dbstr('host').' AND n.host_ref=h.hostid)');
			while ($host = DBfetch($result)) {
				$rows[] = ['id' => self::TRANSIENT_PREFIX.$host['hostid'], 'type' => 'host', 'attrs' => '{}', 'created_at' => 0, 'host_ref' => $host['hostid'], 'proxy_ref' => null,
					'host_name' => $host['name'], 'host_status' => $host['status'], 'host_maintenance_status' => $host['maintenance_status'], 'proxy_name' => null, 'proxy_state' => null];
			}
		}

		// 'represented'/'represented_hostid' for device rows are patched in AFTER this loop
		// (batched below) rather than resolved inline per row — one isRepresented() call per
		// device was itself a lingering N+1 here, same class of fix as getNeighbors()/getPorts()
		// got earlier; adding the hostid lookup the same naive way would have doubled it.
		$device_ids = [];
		$host_rows = [];
		foreach ($rows as $row) {
			$attrs = self::attrs($row);
			$node = ['id' => $row['id'], 'type' => $row['type'], 'monitoring_state' => null];

			switch ($row['type']) {
				case 'device':
					$node['name'] = $attrs['sysname'];
					$node['chassis_id'] = $attrs['chassis_id'] ?? null;      // shown next to the name where two Devices have one name (§6.7.5)
					$node['mgmt_ip'] = $attrs['mgmt_ip'] ?? null;
					$node['label'] = self::nodeLabel('device', null, $attrs['sysname'] ?? null, $attrs['chassis_id'] ?? null, $attrs['mgmt_ip'] ?? null);
					$node['created_at'] = (int) $row['created_at'];
					// 2.80 (§9.7): a manual Device (the operator created it); its class is the operator's choice (§12.2), it wins over rem_sys_cap
					if (($attrs['source'] ?? null) === 'manual') {
						$node['manual'] = true;
						$node['chosen_class'] = $attrs['device_class'] ?? 'other';
					}
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
					$node['label'] = self::nodeLabel('host', $row['host_name']);
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
					$node['label'] = self::nodeLabel('proxy', $row['proxy_name']);
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

		// 2.71 (§12.2 "Device class"; FR-C V-3, FR-B D-52): the class and the bits for the icon, from the union of `rem_sys_cap` of the current observations of each Device. Display only;
		// the bits are data from the network: the caller escapes them. A Device without the field has no class (the generic icon).
		$device_ids = array_column(array_filter($nodes, static fn (array $node): bool => $node['type'] === 'device'), 'id');
		if ($device_ids) {
			$classes = self::classesOfDevices($device_ids);
			foreach ($nodes as &$node) {
				if ($node['type'] === 'device' && isset($classes[(string) $node['id']])) {
					$node['device_class'] = $classes[(string) $node['id']]['class'];
					$node['device_class_bits'] = $classes[(string) $node['id']]['bits'];
				}
				// §12.2 (2.80): the chosen class of a manual Device wins, also when neighbors report it later; "other" is the generic icon
				if ($node['type'] === 'device' && !empty($node['manual'])) {
					if ($node['chosen_class'] === 'other') {
						unset($node['device_class']);
					}
					else {
						$node['device_class'] = $node['chosen_class'];
					}
				}
			}
			unset($node);
		}

		// topology-1-model.md §6.7.5 "Merge a group": every member of a group of Devices linked by candidates lists the whole group
		$groups = self::duplicateGroups($nodes);
		foreach ($nodes as &$node) {
			if (isset($groups[$node['id']])) {
				$node['duplicate_group'] = $groups[$node['id']];
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

		// topology-5-access-control.md §3.6: a user who cannot see the proxy (or the proxy group) gets none of its ids and no monitoring assignment of the
		// host; `blind_spot` is the state of the host and stays. The proxies are read through the API, so the permissions of the user decide.
		$proxyids = $groupids = [];
		foreach ($nodes as $node) {
			if ($node['type'] === 'host') {
				foreach (['proxyid', 'assigned_proxyid'] as $field) {
					if (($node[$field] ?? null) !== null) {
						$proxyids[$node[$field]] = true;
					}
				}
				if (($node['proxy_groupid'] ?? null) !== null) {
					$groupids[$node['proxy_groupid']] = true;
				}
			}
		}
		if ($proxyids || $groupids) {
			$visible_proxies = $proxyids ? array_column(API::Proxy()->get(['output' => ['proxyid'], 'proxyids' => array_keys($proxyids)]), 'proxyid') : [];
			$visible_groups = $groupids ? array_column(API::ProxyGroup()->get(['output' => ['proxy_groupid'],
				'proxy_groupids' => array_keys($groupids)]), 'proxy_groupid') : [];
			foreach ($nodes as &$node) {
				if ($node['type'] !== 'host') {
					continue;
				}
				$hidden = (($node['proxyid'] ?? null) !== null && !in_array($node['proxyid'], $visible_proxies))
					|| (($node['assigned_proxyid'] ?? null) !== null && !in_array($node['assigned_proxyid'], $visible_proxies))
					|| (($node['proxy_groupid'] ?? null) !== null && !in_array($node['proxy_groupid'], $visible_groups));
				if ($hidden) {
					$node['monitored_by'] = null;
					$node['proxyid'] = $node['proxy_groupid'] = $node['assigned_proxyid'] = $node['target_node_id'] = null;
				}
			}
			unset($node);
		}

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
			// 2.70 (§12.3 "Parallel links"): every drawn link is its own relation, with its own channels; the graph draws links between the same two nodes as separate curved
			// lines (device-level links are still bundled, below)
			$key = implode('-', $pair).'#'.$row['link_id'];
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
					'source_lag_id' => $port_a['lag_id'] ?? null, 'source_lag' => $port_a['lag_name'] ?? null,
					'target_lag_id' => $port_b['lag_id'] ?? null, 'target_lag' => $port_b['lag_name'] ?? null,
					'source_speed' => $port_a['speed'] ?? null, 'target_speed' => $port_b['speed'] ?? null,
					'source_alias' => $port_a['alias'] ?? null, 'target_alias' => $port_b['alias'] ?? null,
					// 2.100 (§12.3, V-25): both ends are up reporter ports with different speeds (bps), not for a superseded link; computed here, never stored
					'speed_mismatch' => $superseded_at === null ? (($m = CTopologySpeed::mismatch($port_a, $port_b)) === null ? null : ['source' => $m['a'], 'target' => $m['b']]) : null,
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
		// Link colour = severity of the problems on the items of the end ports (§4.8, §12.3): ONE lookup for all the links
		$itemids_by_link = [];
		$items_of_port = self::portItems(array_merge(array_column($device_links, 'source_port_id'), array_column($device_links, 'target_port_id')));
		foreach ($device_links as $key => $relation) {
			$itemids_by_link[$key] = array_merge($items_of_port[(string) $relation['source_port_id']]['itemids'] ?? [],
				$items_of_port[(string) $relation['target_port_id']]['itemids'] ?? []);
		}
		$severity_by_link = self::getMaxActiveSeverityByItemIds($itemids_by_link);
		foreach ($device_links as $key => $relation) {
			$relations[] = $relation + self::describeSeverity($severity_by_link[$key] ?? null);
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

		return ($port_attrs['admin_status'] ?? 'up') === 'down' ? 'disabled' : ($port_attrs['oper_status'] ?? null);
	}

	/**
	 * The represented_by pairs (Device node id, Host/Proxy node id). They are not hops (topology-1-model.md §12.1): CTopologyHopScope keeps both ends of a
	 * pair in the view at the same distance.
	 *
	 * @return array list of [id_a, id_b] pairs.
	 */
	public static function getRepresentedPairs(): array {
		$pairs = [];
		$result = DBselect('SELECT id,represented_by_node_id FROM topo_nodes WHERE type='.zbx_dbstr('device').
			' AND represented_by_node_id IS NOT NULL');
		while ($row = DBfetch($result)) {
			$pairs[] = [$row['id'], $row['represented_by_node_id']];
		}

		return $pairs;
	}

	/**
	 * Flat undirected adjacency over topo_nodes.id, for CTopologyHopScope's BFS: every device-to-device pair
	 * implied by a physical_link — the same port.device_id -> physical_link -> port.device_id
	 * join chain getNeighbors() runs per-device below, but for every physical_link at once
	 * instead of one device's ports, and every device_link. Only links count as hops (topology-1-model.md §12.1 "View scope"): a Host->Proxy
	 * monitoring assignment is never a hop and is not walked at all; its line is drawn when both ends are in the scope.
	 *
	 * Not included: the represented_by pairs, getRepresentedPairs().
	 *
	 * @return array list of [id_a, id_b] pairs.
	 */
	public static function getAdjacency(): array {
		$pairs = [];

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
	/**
	 * The answer of topology.devices.get (the view page, topology-1-model.md §12.1): the nodes and relations of the view scope and the contradictions.
	 * $hops null: no limit. Nothing selected: every node.
	 */
	public static function getView(array $groupids, string $hostid, ?int $hops, bool $duplicates = false, array $include = [], array $include_hostids = []): array {
		[$node_ids, $transient_hostids] = self::splitScope(self::resolveScope($groupids, $hostid, $hops));
		// 2.80 (§12.1): a manual Device that the operator just created is in the view until the filter is applied. 2.100: a node that the operator chose in the search ("Find a device") is in
		// the view the same way: the Devices of `$include` (with the Host or Proxy that represents them) and the Host without a Host node (`$include_hostids`)
		if ($node_ids !== null && $include) {
			$result = DBselect('SELECT id,represented_by_node_id FROM topo_nodes WHERE type='.zbx_dbstr('device').' AND '.dbConditionId('id', $include));
			while ($row = DBfetch($result, false)) {
				$node_ids[] = (string) $row['id'];
				if ($row['represented_by_node_id'] !== null) {
					$node_ids[] = (string) $row['represented_by_node_id'];
				}
			}
			$result = DBselect('SELECT id FROM topo_nodes WHERE type IN ('.zbx_dbstr('host').','.zbx_dbstr('proxy').') AND '.dbConditionId('id', $include));
			while ($row = DBfetch($result, false)) {
				$node_ids[] = (string) $row['id'];
			}
			$node_ids = array_values(array_unique($node_ids));
		}
		if ($include_hostids) {
			$transient_hostids = array_values(array_unique(array_merge($transient_hostids, array_map('strval', $include_hostids))));
		}
		if ($duplicates) {
			$node_ids = self::duplicatesScope($node_ids);
		}

		// §12.6 (task 104): the Junction projections of the Ports of the view; the Devices behind a projection are in the view with it (they have no link of their own). The filter
		// "Possible duplicates only" draws no projection.
		$junctions = $duplicates ? [] : self::getJunctions($node_ids);
		if ($node_ids !== null && $junctions) {
			$node_ids = array_values(array_unique(array_merge($node_ids, ...array_map(static fn (array $junction): array => array_column($junction['devices'], 'id'), $junctions))));
		}

		$devices = self::getDevices($node_ids, $duplicates ? [] : $transient_hostids);
		$relations = self::getRelations($node_ids);
		$contradictions = self::getContradictions();
		// a Device with a badge other than "possible duplicate" (an unacknowledged hidden neighbor of a contradicted manual link) is in no leaf group (§12.8)
		$badge_device_ids = [];
		foreach ($contradictions as $entry) {
			foreach ($entry['shadows'] ?? [] as $shadow) {
				if (empty($shadow['acknowledged']) && ($shadow['hidden_device_id'] ?? null) !== null) {
					$badge_device_ids[] = $shadow['hidden_device_id'];
				}
			}
		}

		return [
			'devices' => $devices,
			'relations' => $relations,
			// §12.8 (2.70, FR-C V-22): the leaves that the graph draws as one group node of their node; a UI projection, nothing is stored. Access: the Devices without association are
			// not filtered by access yet (access spec §3.4 is not implemented), so every leaf counts.
			'leaf_groups' => CTopologyLeafGroups::compute($devices, $relations, self::getTopologyLinkCounts(), $badge_device_ids, $duplicates, null, self::junctionDeviceIds($junctions)),
			// §12.6: one projection per anchor Port (a UI projection, nothing is stored)
			'junctions' => $junctions,
			// Manual links hidden-behind by discovery (topology-1-model.md "Contradiction"): badges, ghost links
			// and details. Strings inside come from the network and are escaped where they are rendered.
			'contradictions' => $contradictions
		];
	}

	/**
	 * Device id => the number of links that are not superseded in the WHOLE topology (physical and device-level; §12.8: a leaf is counted by the whole topology, not by the view).
	 */
	private static function getTopologyLinkCounts(): array {
		$counts = [];
		$cursor = DBselect(
			'SELECT link.type,link.attrs,src_port.device_id AS device_a,dst_port.device_id AS port_device_b,link.dst_id AS dst_id'.
			' FROM topo_edges link'.
			' JOIN topo_nodes src_port ON src_port.id=link.src_id'.
			' LEFT JOIN topo_nodes dst_port ON dst_port.id=link.dst_id AND dst_port.type='.zbx_dbstr('port').
			' WHERE '.dbConditionString('link.type', ['physical_link', 'device_link'])
		);
		while ($row = DBfetch($cursor, false)) {
			if (self::supersededAt(self::attrs($row)) !== null) {
				continue;
			}
			$a = (string) $row['device_a'];
			$b = (string) ($row['type'] === 'physical_link' ? $row['port_device_b'] : $row['dst_id']);
			if ($a === '' || $b === '' || $a === $b) {
				continue;
			}
			$counts[$a] = ($counts[$a] ?? 0) + 1;
			$counts[$b] = ($counts[$b] ?? 0) + 1;
		}

		return $counts;
	}

	/**
	 * The scope of the filter "Possible duplicates only" (model §12.1, FR-C V-17): the Devices of the view scope that have a candidate, the partners of
	 * those candidates, and one hop of context around them (links and the Devices at their other ends). Pairs in duplicate_dismissed are not candidates.
	 *
	 * @param array|null $node_ids  the view scope, null = every node
	 *
	 * @return array topo_nodes.id values
	 */
	public static function duplicatesScope(?array $node_ids): array {
		$seeds = [];
		$result = DBselect('SELECT id,attrs FROM topo_nodes WHERE type='.zbx_dbstr('device').' AND attrs LIKE '.zbx_dbstr('%duplicate_candidates%'));
		while ($row = DBfetch($result)) {
			foreach (self::attrs($row)['duplicate_candidates'] ?? [] as $candidate) {
				$seeds[$row['id']] = true;
				if ($candidate['device_id'] !== null) {
					$seeds[$candidate['device_id']] = true;
				}
			}
		}
		if ($node_ids !== null) {
			$seeds = array_intersect_key($seeds, array_flip($node_ids));
		}
		if (!$seeds) {
			return [];
		}

		return CTopologyHopScope::neighborhood(array_map('strval', array_keys($seeds)), 1, self::getAdjacency(), self::getRepresentedPairs());
	}

	public static function resolveScope(array $groupids, string $hostid, ?int $hops): ?array {
		// topology-1-model.md §12.1 "View scope": the start nodes are the Hosts of the selected groups and the focus host, together. Nothing selected:
		// no scope (every node). $hops null: no limit.
		if ($hostid === '' && !$groupids) {
			return null;
		}

		$seed_hostids = [];
		if ($hostid !== '') {
			// API::Host()->get() first, not a raw topo_nodes lookup — a hostid the caller isn't
			// permitted to see must behave exactly like a hostid that doesn't exist at all
			// (empty scope), not resolve and hand back its neighborhood. getDevices()/
			// getRelations() would still strip the invisible host itself out of whatever this
			// returns, but without this check a restricted user could use it as a topology
			// pivot — learning which unrelated devices sit near a host they can't otherwise see,
			// even though the host's own data stays hidden.
			$seed_hostids = array_column(API::Host()->get(['hostids' => [$hostid], 'output' => ['hostid']]), 'hostid');
		}
		if ($groupids) {
			$seed_hostids = array_merge($seed_hostids, array_column(API::Host()->get([
				'output' => ['hostid'],
				'groupids' => $groupids
			]), 'hostid'));
		}
		if (!$seed_hostids) {
			return [];
		}

		// 2.71 (§12.1): a Host without a Device is a node of the view too. Since FR-B 0.29 D-49 a host with only the official interface templates is not a reporter, so no run makes its
		// Host node. 2.95: a read request writes nothing (task 112): such a host is a transient node of the answer, id `host:<hostid>`; the scope lists it next to the stored ids.
		$seed_hostids = array_values(array_unique($seed_hostids));
		$seed_ids = [];
		$have = [];
		$result = DBselect('SELECT id,host_ref FROM topo_nodes WHERE type='.zbx_dbstr('host').
			' AND '.dbConditionId('host_ref', $seed_hostids));
		while ($row = DBfetch($result)) {
			$seed_ids[] = $row['id'];
			$have[(string) $row['host_ref']] = true;
		}
		$transient = [];
		foreach ($seed_hostids as $seed_hostid) {
			if (!isset($have[(string) $seed_hostid])) {
				$transient[] = self::TRANSIENT_PREFIX.$seed_hostid;
			}
		}
		if (!$seed_ids) {
			return $transient;
		}

		return array_merge(CTopologyHopScope::neighborhood($seed_ids, $hops ?? PHP_INT_MAX, self::getAdjacency(), self::getRepresentedPairs()), $transient);
	}

	/**
	 * The class and the bits of Devices for the icon (model §12.2): the union of `rem_sys_cap` of their current observations; the own capabilities of a reporter's Device (`attrs.sys_cap`, 2.96) are used
	 * instead of the neighbors'. The bits are data from the network: the caller escapes them.
	 *
	 * @return array  Device id => ['class', 'bits']; a Device with no capabilities is not in it
	 */
	private static function classesOfDevices(array $device_ids): array {
		if (!$device_ids) {
			return [];
		}
		$own_caps = [];
		foreach (DBfetchArray(DBselect('SELECT id,attrs FROM topo_nodes WHERE type='.zbx_dbstr('device').' AND '.dbConditionId('id', $device_ids).
				' AND attrs LIKE '.zbx_dbstr('%sys_cap%'))) as $own_row) {
			$own_attrs = json_decode((string) $own_row['attrs'], true);

			if (is_array($own_attrs) && isset($own_attrs['sys_cap']) && is_string($own_attrs['sys_cap']) && trim($own_attrs['sys_cap']) !== '') {
				$own_caps[(string) $own_row['id']] = $own_attrs['sys_cap'];
			}
		}

		return CTopologyDeviceClass::ofObservations(DBfetchArray(DBselect(
			'SELECT device_id,remote_attrs FROM topo_observations WHERE device_id IS NOT NULL AND '.dbConditionId('device_id', $device_ids).
			' AND remote_attrs LIKE '.zbx_dbstr('%rem_sys_cap%')
		)), $own_caps);
	}

	/** At most this many results of the search (model §12.1 "Find a device"); more: "refine the search". */
	public const FIND_LIMIT = 20;

	/**
	 * Find a device (model 2.100 §12.1, FR-C V-24): Devices, Hosts and Proxies by (1) name: `sysname` of a Device, host name and visible name of a Host, proxy name (substring, any case); (2) hardware ID
	 * or port MAC: the text is read as a MAC in any common form and compared with the `chassis_id` keys of the Devices and the `mac` of their Ports (a placeholder MAC finds nothing); (3) IP address
	 * (exact): `mgmt_ip` and the addresses that neighbors report (V-15). One answer for one input; nothing is written. The names, MACs and addresses come from the network: the caller escapes them.
	 *
	 * @return array  ['results' => [['node' => the id of the node that the graph draws, 'type' => device|host|proxy, 'name', 'device_class'?, 'matched' => [text], 'include' => [topo_nodes.id],
	 *                'include_hostids' => [hostid of a transient Host node]]], 'more' => bool]
	 */
	public static function findDevices(string $text): array {
		$text = trim($text);
		if (mb_strlen($text) < 2) {
			return ['results' => [], 'more' => false, 'total' => 0];
		}

		$device_hits = [];      // Device id => [reason]
		$host_hits = [];        // hostid => [reason]
		$proxy_hits = [];       // proxyid => [reason]
		$add = static function (array &$list, $id, string $reason): void {
			$id = (string) $id;
			if (!in_array($reason, $list[$id] ?? [], true)) {
				$list[$id][] = $reason;
			}
		};
		$like = static function (string $value): string {
			return zbx_dbstr('%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value).'%').' ESCAPE '.zbx_dbstr('!');
		};

		// 1. name
		// (the column may compare case-sensitively: both sides are lower case; the name is compared again in PHP below)
		$needles = array_unique([mb_strtolower($text), mb_strtolower(substr(json_encode($text), 1, -1))]);
		$conditions = array_map(static fn (string $needle): string => 'LOWER(attrs) LIKE '.$like($needle), $needles);
		$result = DBselect('SELECT id,attrs FROM topo_nodes WHERE type='.zbx_dbstr('device').' AND ('.implode(' OR ', $conditions).')');
		while ($row = DBfetch($result, false)) {
			if (mb_stripos((string) (self::attrs($row)['sysname'] ?? ''), $text) !== false) {
				$add($device_hits, $row['id'], _('name'));
			}
		}
		foreach (API::Host()->get(['output' => ['hostid'], 'search' => ['host' => $text, 'name' => $text], 'searchByAny' => true, 'limit' => 200]) as $host) {
			$add($host_hits, $host['hostid'], _('name'));
		}
		foreach (API::Proxy()->get(['output' => ['proxyid'], 'search' => ['name' => $text], 'limit' => 200]) as $proxy) {
			$add($proxy_hits, $proxy['proxyid'], _('name'));
		}

		// 2. hardware ID or the MAC of a port
		$mac = CTopologyAddress::searchMac($text);
		if ($mac !== null) {
			$result = DBselect('SELECT k.device_id FROM topo_identity_keys k JOIN topo_nodes d ON d.id=k.device_id AND d.type='.zbx_dbstr('device').
				' WHERE k.'.self::keyColumn().'='.zbx_dbstr('chassis:mac:'.$mac).' AND k.replaced=0');
			while ($row = DBfetch($result, false)) {
				$add($device_hits, $row['device_id'], _('hardware ID'));
			}
			$result = DBselect('SELECT id,attrs FROM topo_nodes WHERE type='.zbx_dbstr('device').' AND attrs LIKE '.$like($mac));
			while ($row = DBfetch($result, false)) {
				if (strtolower((string) (self::attrs($row)['chassis_id'] ?? '')) === $mac) {
					$add($device_hits, $row['id'], _('hardware ID'));
				}
			}
			$result = DBselect('SELECT id,device_id,attrs FROM topo_nodes WHERE type='.zbx_dbstr('port').' AND device_id IS NOT NULL AND attrs LIKE '.$like($mac));
			while ($row = DBfetch($result, false)) {
				$attrs = self::attrs($row);
				if (strtolower((string) ($attrs['mac'] ?? '')) === $mac) {
					$add($device_hits, $row['device_id'], sprintf(_('port %1$s MAC'), (string) ($attrs['name'] ?? '')));
				}
			}
		}

		// 3. IP address (exact)
		$ip = CTopologyAddress::ipText($text);
		if ($ip !== null && !CTopologyAddress::isHidden($ip)) {
			$result = DBselect('SELECT id,attrs FROM topo_nodes WHERE type='.zbx_dbstr('device').' AND attrs LIKE '.$like($ip));
			while ($row = DBfetch($result, false)) {
				if ((string) (self::attrs($row)['mgmt_ip'] ?? '') === $ip) {
					$add($device_hits, $row['id'], _('management address'));
				}
			}
			$where = ['remote_attrs LIKE '.$like('rem_mgmt_ip'), 'remote_attrs LIKE '.$like('rem_man_addrs')];
			if (!str_contains($ip, ':')) {
				// IPv4: the dotted text is in rem_mgmt_ip and in the LLDP entries; a CDP entry has the hex octets
				$where = ['remote_attrs LIKE '.$like($ip), 'remote_attrs LIKE '.$like('cdp:1:'.implode(':', array_map(static fn (string $o): string => sprintf('%02x', (int) $o), explode('.', $ip))))];
			}
			$result = DBselect('SELECT device_id,remote_attrs FROM topo_observations WHERE device_id IS NOT NULL AND ('.implode(' OR ', $where).')');
			while ($row = DBfetch($result, false)) {
				$remote = json_decode((string) $row['remote_attrs'], true);
				$found = [];
				if (is_array($remote)) {
					if (is_string($remote['rem_man_addrs'] ?? null)) {
						$found = array_column(CTopologyAddress::decodeEntries($remote['rem_man_addrs']), 'address');
					}
					if (is_string($remote['rem_mgmt_ip'] ?? null) && ($address = CTopologyAddress::ipText($remote['rem_mgmt_ip'])) !== null) {
						$found[] = $address;
					}
				}
				if (in_array($ip, $found, true)) {
					$add($device_hits, $row['device_id'], _('reported address'));
				}
			}
		}

		// the subjects: the node that the graph draws (a Device that a Host or a Proxy represents is drawn as that Host or Proxy; a Host without a Host node is the transient node `host:<hostid>`)
		$visible = array_flip(self::getVisibleHostIds());
		$subjects = [];      // drawn node id => ['node', 'type', 'device_id', 'host_node_id', 'hostid', 'proxyid', 'reasons']
		$subject = static function (array &$subjects, string $key, array $init): void {
			$subjects[$key] ??= $init + ['device_id' => null, 'include' => [], 'include_hostids' => [], 'reasons' => []];
		};
		$device_rows = [];
		if ($device_hits) {
			$result = DBselect('SELECT d.id,d.attrs,d.represented_by_node_id,t.type AS target_type,t.host_ref,t.proxy_ref,t.id AS target_id'.
				' FROM topo_nodes d LEFT JOIN topo_nodes t ON t.id=d.represented_by_node_id'.
				' WHERE d.type='.zbx_dbstr('device').' AND '.dbConditionId('d.id', array_keys($device_hits)));
			while ($row = DBfetch($result, false)) {
				$device_rows[(string) $row['id']] = $row;
				$reasons = $device_hits[(string) $row['id']];
				if ($row['target_type'] === 'host') {
					if (!isset($visible[$row['host_ref']])) {
						continue;       // the host of a represented Device is not for this user to see: nothing of it is found
					}
					$key = (string) $row['target_id'];
					$subject($subjects, $key, ['node' => $key, 'type' => 'host', 'hostid' => (string) $row['host_ref']]);
					$subjects[$key]['device_id'] = (string) $row['id'];
					$subjects[$key]['include'] = [(string) $row['id'], $key];
				}
				elseif ($row['target_type'] === 'proxy') {
					$key = (string) $row['target_id'];
					$subject($subjects, $key, ['node' => $key, 'type' => 'proxy', 'proxyid' => (string) $row['proxy_ref']]);
					$subjects[$key]['device_id'] = (string) $row['id'];
					$subjects[$key]['include'] = [(string) $row['id'], $key];
				}
				else {
					$key = (string) $row['id'];
					$subject($subjects, $key, ['node' => $key, 'type' => 'device']);
					$subjects[$key]['device_id'] = $key;
					$subjects[$key]['include'] = [$key];
				}
				$subjects[$key]['reasons'] = array_merge($subjects[$key]['reasons'], $reasons);
			}
		}
		if ($host_hits) {
			$nodes = [];
			$result = DBselect('SELECT n.id,n.host_ref,d.id AS device_id FROM topo_nodes n LEFT JOIN topo_nodes d ON d.type='.zbx_dbstr('device').' AND d.represented_by_node_id=n.id'.
				' WHERE n.type='.zbx_dbstr('host').' AND '.dbConditionId('n.host_ref', array_keys($host_hits)));
			while ($row = DBfetch($result, false)) {
				$nodes[(string) $row['host_ref']] = $row;
			}
			foreach ($host_hits as $hostid => $reasons) {
				if (!isset($visible[$hostid])) {
					continue;
				}
				if (isset($nodes[$hostid])) {
					$key = (string) $nodes[$hostid]['id'];
					$subject($subjects, $key, ['node' => $key, 'type' => 'host', 'hostid' => (string) $hostid]);
					$subjects[$key]['device_id'] ??= $nodes[$hostid]['device_id'] !== null ? (string) $nodes[$hostid]['device_id'] : null;
					$subjects[$key]['include'] = array_values(array_unique(array_filter(array_merge($subjects[$key]['include'], [$key, $subjects[$key]['device_id']]), static fn ($id): bool => $id !== null)));
				}
				else {
					$key = self::TRANSIENT_PREFIX.$hostid;
					$subject($subjects, $key, ['node' => $key, 'type' => 'host', 'hostid' => (string) $hostid]);
					$subjects[$key]['include_hostids'] = [(string) $hostid];
				}
				$subjects[$key]['reasons'] = array_merge($subjects[$key]['reasons'], $reasons);
			}
		}
		if ($proxy_hits) {
			$result = DBselect('SELECT n.id,n.proxy_ref,d.id AS device_id FROM topo_nodes n JOIN topo_nodes d ON d.type='.zbx_dbstr('device').' AND d.represented_by_node_id=n.id'.
				' WHERE n.type='.zbx_dbstr('proxy').' AND '.dbConditionId('n.proxy_ref', array_keys($proxy_hits)));
			while ($row = DBfetch($result, false)) {
				$key = (string) $row['id'];
				$subject($subjects, $key, ['node' => $key, 'type' => 'proxy', 'proxyid' => (string) $row['proxy_ref']]);
				$subjects[$key]['device_id'] ??= (string) $row['device_id'];
				$subjects[$key]['include'] = [(string) $row['device_id'], $key];
				$subjects[$key]['reasons'] = array_merge($subjects[$key]['reasons'], $proxy_hits[(string) $row['proxy_ref']]);
			}
		}

		// the names (what the graph shows as the label) and the classes of the Devices
		$names = [];
		$host_names = [];
		$host_ids = array_filter(array_column($subjects, 'hostid'));
		if ($host_ids) {
			foreach (API::Host()->get(['output' => ['hostid', 'name'], 'hostids' => array_values($host_ids)]) as $host) {
				$host_names[(string) $host['hostid']] = $host['name'];
			}
		}
		$proxy_names = [];
		$proxy_ids = array_filter(array_column($subjects, 'proxyid'));
		if ($proxy_ids) {
			foreach (API::Proxy()->get(['output' => ['proxyid', 'name'], 'proxyids' => array_values($proxy_ids)]) as $proxy) {
				$proxy_names[(string) $proxy['proxyid']] = $proxy['name'];
			}
		}
		$device_attrs = [];
		$ids = array_filter(array_column($subjects, 'device_id'));
		if ($ids) {
			$result = DBselect('SELECT id,attrs FROM topo_nodes WHERE type='.zbx_dbstr('device').' AND '.dbConditionId('id', array_values($ids)));
			while ($row = DBfetch($result, false)) {
				$device_attrs[(string) $row['id']] = self::attrs($row);
			}
		}
		foreach ($subjects as $key => &$entry) {
			if ($entry['type'] === 'host') {
				$entry['name'] = self::nodeLabel('host', $host_names[$entry['hostid']] ?? '');
			}
			elseif ($entry['type'] === 'proxy') {
				$entry['name'] = self::nodeLabel('proxy', $proxy_names[$entry['proxyid']] ?? '');
			}
			else {
				$attrs = $device_attrs[$entry['device_id']] ?? [];
				$entry['name'] = self::nodeLabel('device', null, $attrs['sysname'] ?? null, $attrs['chassis_id'] ?? null, $attrs['mgmt_ip'] ?? null);
			}
		}
		unset($entry);
		// 2.104 (FR-C V-24): the order. Group 0: an exact match of a hardware ID, a port MAC or an address; group 1: the name starts with the text; group 2: the name contains it. Inside a group by name.
		$rank = static function (array $entry) use ($text): int {
			foreach ($entry['reasons'] as $reason) {
				if ($reason !== _('name')) {
					return 0;
				}
			}

			return mb_stripos($entry['name'], $text) === 0 ? 1 : 2;
		};
		uasort($subjects, static fn (array $a, array $b): int => $rank($a) <=> $rank($b) ?: strnatcasecmp($a['name'], $b['name']) ?: strcmp($a['node'], $b['node']));
		$total = count($subjects);
		$more = $total > self::FIND_LIMIT;
		$subjects = array_slice($subjects, 0, self::FIND_LIMIT, true);

		$classes = self::classesOfDevices(array_values(array_filter(array_column($subjects, 'device_id'))));
		$results = [];
		foreach ($subjects as $entry) {
			$row = ['node' => (string) $entry['node'], 'type' => $entry['type'], 'name' => $entry['name'], 'matched' => array_values(array_unique($entry['reasons'])),
				'include' => array_values(array_unique($entry['include'])), 'include_hostids' => $entry['include_hostids']];
			if ($entry['device_id'] !== null && isset($classes[(string) $entry['device_id']])) {
				$row['device_class'] = $classes[(string) $entry['device_id']]['class'];
			}
			if ($entry['device_id'] !== null && (($device_attrs[$entry['device_id']]['source'] ?? null) === 'manual') && ($device_attrs[$entry['device_id']]['device_class'] ?? 'other') !== 'other') {
				$row['device_class'] = $device_attrs[$entry['device_id']]['device_class'];
			}
			$results[] = $row;
		}

		return ['results' => $results, 'more' => $more, 'total' => $total];
	}

	/** The id of a transient Host node (model 2.95 §12.1): `host:<hostid>`; it is never a topo_nodes.id. */
	public const TRANSIENT_PREFIX = 'host:';

	/** @return array [the stored node ids, the hostids of the transient nodes] of a scope of resolveScope(); null stays null */
	private static function splitScope(?array $node_ids): array {
		if ($node_ids === null) {
			return [null, []];
		}
		$stored = $transient = [];
		foreach ($node_ids as $id) {
			if (strncmp((string) $id, self::TRANSIENT_PREFIX, strlen(self::TRANSIENT_PREFIX)) === 0) {
				$transient[] = substr((string) $id, strlen(self::TRANSIENT_PREFIX));
			}
			else {
				$stored[] = $id;
			}
		}

		return [$stored, $transient];
	}


	/**
	 * The Junction projections (topology-1-model.md §12.6, task 104): computed on read from the observations of the latest snapshots, never stored (the rule: CTopologyJunction).
	 *
	 * @param array|null $reporter_ids  the Devices whose Ports anchor the projections; null = every Device
	 *
	 * @return array  list of ['port_id', 'port_name', 'device_id' (the reporter), 'devices' => [['id', 'name']], 'links' => [Device id => the ids of the links on the anchor Port to it]]
	 */
	/** The Device ids of the members of the projections: they are not leaves (§12.8). */
	private static function junctionDeviceIds(array $junctions): array {
		$ids = [];
		foreach ($junctions as $junction) {
			foreach ($junction['devices'] as $device) {
				$ids[(string) $device['id']] = true;
			}
		}

		return array_keys($ids);
	}

	public static function getJunctions(?array $reporter_ids = null): array {
		if ($reporter_ids !== null && !$reporter_ids) {
			return [];
		}
		// the Ports with rows of one rule that resolve to two or more Devices: one grouped query, then the rows of those Ports only
		$candidates = DBselect(
			'SELECT o.itemid,o.local_port_id FROM topo_observations o'.
			' WHERE o.device_id IS NOT NULL AND o.local_port_id IS NOT NULL AND '.dbConditionString('o.outcome', ['old_snapshot', 'port_limit'], true).
			' GROUP BY o.itemid,o.local_port_id HAVING COUNT(DISTINCT o.device_id)>1'
		);
		$port_ids = [];
		while ($row = DBfetch($candidates, false)) {
			$port_ids[(string) $row['local_port_id']] = true;
		}
		if (!$port_ids) {
			return [];
		}
		$port_ids = array_keys($port_ids);
		$reporters = [];
		$cursor = DBselect('SELECT id,device_id FROM topo_nodes WHERE type='.zbx_dbstr('port').' AND '.dbConditionId('id', $port_ids));
		while ($row = DBfetch($cursor, false)) {
			$reporters[(string) $row['id']] = (string) $row['device_id'];
		}
		if ($reporter_ids !== null) {
			$wanted = array_flip(array_map('strval', $reporter_ids));
			$reporters = array_filter($reporters, static fn (string $device_id): bool => isset($wanted[$device_id]));
			$port_ids = array_keys($reporters);
			if (!$port_ids) {
				return [];
			}
		}

		$rows = DBfetchArray(DBselect(
			'SELECT o.itemid,o.local_port_id AS port_id,o.device_id FROM topo_observations o'.
			' WHERE o.device_id IS NOT NULL AND '.dbConditionId('o.local_port_id', $port_ids).' AND '.dbConditionString('o.outcome', ['old_snapshot', 'port_limit'], true)
		));
		// an active manual link on the Port suppresses the projection (§12.6); a superseded one does not
		$suppressed = [];
		$links_on_port = [];
		$cursor = DBselect('SELECT id,src_id,dst_id,attrs FROM topo_edges WHERE type='.zbx_dbstr('physical_link').
			' AND ('.dbConditionId('src_id', $port_ids).' OR '.dbConditionId('dst_id', $port_ids).')');
		while ($row = DBfetch($cursor, false)) {
			$attrs = self::attrs($row);
			$active = self::supersededAt($attrs) === null;
			foreach ([(string) $row['src_id'], (string) $row['dst_id']] as $end) {
				if (isset($reporters[$end])) {
					if ($active && ($attrs['discovered_via'] ?? null) === 'manual') {
						$suppressed[$end] = true;
					}
					$links_on_port[$end][] = ['id' => (string) $row['id'], 'far' => (string) ($end === (string) $row['src_id'] ? $row['dst_id'] : $row['src_id'])];
				}
			}
		}

		// access (§12.6): the Devices the user can see; a Device without association is visible, a Device of a Host is visible with its Host (access spec §3.4 is not implemented for the rest)
		$device_ids = array_unique(array_column($rows, 'device_id'));
		$visible_hostids = self::getVisibleHostIds();
		$represented = self::getRepresentedIds($device_ids);
		$visible_represented = self::getRepresentingHostIds($device_ids, $visible_hostids);
		$visible = [];
		foreach ($device_ids as $id) {
			if (!isset($represented[$id]) || isset($visible_represented[$id])) {
				$visible[(string) $id] = true;
			}
		}

		$projections = CTopologyJunction::project($rows, $suppressed, $visible, $reporters);
		if (!$projections) {
			return [];
		}
		$names = [];
		$wanted_names = array_unique(array_merge(array_values($reporters), ...array_column($projections, 'device_ids')));
		$cursor = DBselect('SELECT id,attrs FROM topo_nodes WHERE type='.zbx_dbstr('device').' AND '.dbConditionId('id', $wanted_names));
		while ($row = DBfetch($cursor, false)) {
			$attrs = self::attrs($row);
			$names[(string) $row['id']] = self::nodeLabel('device', null, $attrs['sysname'] ?? null, $attrs['chassis_id'] ?? null, $attrs['mgmt_ip'] ?? null);
		}
		$port_names = [];
		foreach (self::getPortDetails(array_column($projections, 'port_id')) as $id => $details) {
			$port_names[(string) $id] = $details['name'] ?? null;
		}
		$far_device = [];
		$far_ports = [];
		foreach ($projections as $projection) {
			$far_ports = array_merge($far_ports, array_column($links_on_port[$projection['port_id']] ?? [], 'far'));
		}
		$far_ports = array_unique($far_ports);
		if ($far_ports) {
			$cursor = DBselect('SELECT id,device_id FROM topo_nodes WHERE '.dbConditionId('id', $far_ports));
			while ($row = DBfetch($cursor, false)) {
				$far_device[(string) $row['id']] = (string) $row['device_id'];
			}
		}

		$out = [];
		foreach ($projections as $projection) {
			$port = $projection['port_id'];
			$links = [];
			foreach ($links_on_port[$port] ?? [] as $link) {
				$device = $far_device[$link['far']] ?? null;
				if ($device !== null && in_array($device, $projection['device_ids'], true)) {
					$links[$device][] = $link['id'];
				}
			}
			$out[] = [
				'port_id' => $port, 'port_name' => $port_names[$port] ?? null, 'device_id' => $reporters[$port], 'device_name' => $names[$reporters[$port]] ?? null,
				'devices' => array_map(static fn (string $id): array => ['id' => $id, 'name' => $names[$id] ?? $id], $projection['device_ids']),
				'links' => $links
			];
		}

		return $out;
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
		// 2.93 (§3.2): the items of the end ports are found now, one lookup for both ends of every link; one side with items is enough
		$items_of_port = self::portItems(array_merge(array_column($link_ports, 'local'), array_column($link_ports, 'remote')));
		$link_itemids = [];
		foreach ($link_ports as $id => $ends) {
			$link_itemids[$id] = array_merge($items_of_port[(string) $ends['local']]['itemids'] ?? [], $items_of_port[(string) $ends['remote']]['itemids'] ?? []);
		}
		$link_severity = self::getMaxActiveSeverityByItemIds($link_itemids);
		// Load of the far side of each shown link (§4.8, 2.88): one read for the far ports; the near side is in `ports.get` (the page puts the two together)
		$port_itemids = [];
		foreach ($link_ports as $ends) {
			$port_itemids[$ends['remote']] = $items_of_port[(string) $ends['remote']]['itemids'] ?? [];
		}
		$port_loads = self::getPortLoads($port_itemids);

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
			$neighbor = array_merge($neighbor, self::describeSeverity($link_severity[$id] ?? null));
			$neighbor['load'] = ['remote' => $port_loads[$link_ports[$id]['remote']] ?? null];
			// 2.93 (FR-C V-12 item 4): why the far side has no data
			$neighbor['load_reason'] = ['remote' => isset($port_loads[$link_ports[$id]['remote']]) ? null : ($items_of_port[(string) $link_ports[$id]['remote']]['reason'] ?? null)];
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
		// a transient Host node (model 2.95 §12.1): the problems of the live host; API::Problem()->get() gives only what the user may see
		if (strncmp($nodeid, self::TRANSIENT_PREFIX, strlen(self::TRANSIENT_PREFIX)) === 0) {
			$hostid = substr($nodeid, strlen(self::TRANSIENT_PREFIX));

			return ctype_digit($hostid) ? self::getProblemsForHost($hostid) : [];
		}
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

	/**
	 * The port table of a Device (topology-1-model.md §11 /ports, §12.5 "Port table groups"): each port in exactly one group, by the first rule that is true:
	 * connected (an end of a physical_link or device_link that is not superseded; stale counts), lag, mgmt, virtual (by if_type), mac_only (learned_macs not
	 * empty or learned_mac_count set), no_neighbor (all other ports). oper_status / admin_status are columns, not groups. Groups in this order; an empty
	 * group is left out. Each port carries its group id.
	 */
	public static function getPorts(string $deviceid): array {
		$groups = array_fill_keys(self::PORT_GROUPS, []);
		$result = DBselect(
			'SELECT port.id,port.attrs,port.lag_id,link.id AS linkid,link.attrs AS link_attrs,'.
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
		// task 094 (§12.5 "Links"): the far end of each link (its port name, its LAG, its Device) and the load of each local port, for the Links section; one query each for all the ports
		$far_ids = array_values(array_unique(array_filter(array_column($rows, 'linked_port_id'), static fn ($id): bool => $id !== null)));
		$far_details = self::getPortDetails($far_ids);
		$far_devices = [];
		if ($far_ids) {
			$cursor = DBselect('SELECT id,device_id FROM topo_nodes WHERE '.dbConditionId('id', $far_ids));
			while ($far = DBfetch($cursor)) {
				$far_devices[$far['id']] = (string) $far['device_id'];
			}
		}
		// (DBfetchArray turns a NULL lag_id into '0')
		$lag_names = [];
		$lag_ids = array_values(array_unique(array_filter(array_column($rows, 'lag_id'), static fn ($id): bool => $id !== null)));
		if ($lag_ids) {
			$cursor = DBselect('SELECT id,attrs FROM topo_nodes WHERE '.dbConditionId('id', $lag_ids));
			while ($lag = DBfetch($cursor)) {
				$lag_names[$lag['id']] = self::attrs($lag)['name'] ?? null;
			}
		}

		// Device-level links (device-level-edge spec): a port whose link ends at a Device is connected too, to a Device
		// whose port is not known. An active one only; the port keeps its row either way.
		$device_level = [];
		$cursor = DBselect(
			'SELECT link.id,link.src_id,link.dst_id,link.attrs,device.attrs AS device_attrs'.
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
					'device' => json_decode($link['device_attrs'], true)['sysname'] ?? null,
					'device_id' => (string) $link['dst_id'], 'last_seen' => isset($link_attrs['last_seen']) ? (int) $link_attrs['last_seen'] : null
				];
			}
		}

		// 2.87 (§11, task 102): the load (§4.8) is read only for the ports of the Links section, an end of a link that is not superseded (the rows of a superseded link have no linkid here) or of a
		// device-level link; the other ports have no load fields. The read of the last values of the items of a port (two values of each item, one query for each item) was the time of the call.
		// 2.93 (§3.2): the items of the ports are found now (one lookup); a bound manual port whose interface is not found shows "interface not found" (§9.7)
		$wanted = [];
		foreach ($rows as $row) {
			$attrs = self::attrs($row);
			if ($row['linkid'] !== null || isset($device_level[$row['id']]) || (($attrs['source'] ?? null) === 'manual' && (isset($attrs['if_index']) || self::cleanName($attrs['interface_name'] ?? null) !== ''))) {
				$wanted[] = $row['id'];
			}
		}
		$items_of_port = self::portItems($wanted);
		$itemids_by_port = [];
		foreach ($rows as $row) {
			if ($row['linkid'] !== null || isset($device_level[$row['id']])) {
				$itemids_by_port[$row['id']] = $items_of_port[(string) $row['id']]['itemids'] ?? [];
			}
		}
		$port_loads = self::getPortLoads($itemids_by_port);

		foreach ($rows as $row) {
			$attrs = self::attrs($row);
			$discovered_via = $row['linkid'] !== null
				? (json_decode($row['link_attrs'], true, 512, JSON_THROW_ON_ERROR)['discovered_via'] ?? 'lldp')
				: null;

			$device_link = $row['linkid'] === null ? ($device_level[$row['id']] ?? null) : null;
			$link_attrs_of_row = $row['linkid'] !== null ? (json_decode($row['link_attrs'], true, 512, JSON_THROW_ON_ERROR) ?: []) : [];
			$learned_macs = array_values((array) ($attrs['learned_macs'] ?? []));
			$learned_mac_count = isset($attrs['learned_mac_count']) ? (int) $attrs['learned_mac_count'] : null;
			$group = self::portGroup($row['linkid'] !== null || $device_link !== null, (string) ($attrs['if_type'] ?? 'physical'), $learned_macs,
				$learned_mac_count);

			$entry = [
				'id' => $row['id'], 'port' => $attrs['name'] ?? '', 'group' => $group,
				// 2.80 (§9.7): a manual port has no oper_status; `manual` and `description` are for its menu and the edit dialog
				'manual' => ($attrs['source'] ?? null) === 'manual', 'description' => $attrs['description'] ?? null,
				// 2.91: the interface a manual port is bound to ("lan0 → Gi0/1"); `missing`: its items are gone ("interface not found")
				// 2.99: a binding by name has no `if_index` (`if_index` null, `name` the interface name)
				'binding' => ($attrs['source'] ?? null) === 'manual' && (isset($attrs['if_index']) || self::cleanName($attrs['interface_name'] ?? null) !== '')
					? ['if_index' => isset($attrs['if_index']) ? (int) $attrs['if_index'] : null, 'name' => $attrs['interface_name'] ?? null,
					'missing' => !($items_of_port[(string) $row['id']]['itemids'] ?? [])] : null,
				'status' => $attrs['oper_status'] ?? null, 'admin_status' => $attrs['admin_status'] ?? null,
				// a LAG member shows the name of its LAG (§12.5)
				'lag' => $row['lag_id'] !== null ? ($lag_names[$row['lag_id']] ?? null) : null,
				'connected_to' => $linked_names[$row['id']] ?? $device_link['device'] ?? null,
				'linked_port_id' => $row['linkid'] !== null ? $row['linked_port_id'] : null,
				// 2.75 (§12.5 "Links"): the link's id, the far port and its LAG, the far Device, when the link was last confirmed, the load of this port, the LAG id of this port
				'edge_id' => $row['linkid'] ?? $device_link['id'] ?? null,
				'lag_id' => $row['lag_id'] === null || (string) $row['lag_id'] === '0' ? null : (string) $row['lag_id'],
				'remote_port' => $row['linkid'] !== null ? ($far_details[$row['linked_port_id']]['name'] ?? null) : null,
				'remote_lag_id' => $row['linkid'] !== null ? ($far_details[$row['linked_port_id']]['lag_id'] ?? null) : null,
				'remote_lag' => $row['linkid'] !== null ? ($far_details[$row['linked_port_id']]['lag_name'] ?? null) : null,
				'remote_device_id' => $row['linkid'] !== null ? ($far_devices[$row['linked_port_id']] ?? null) : ($device_link['device_id'] ?? null),
				'last_seen' => $row['linkid'] !== null ? (isset($link_attrs_of_row['last_seen']) ? (int) $link_attrs_of_row['last_seen'] : null) : ($device_link['last_seen'] ?? null),
				'stale' => self::isLinkStale($row['linkid'] !== null ? (isset($link_attrs_of_row['last_seen']) ? (int) $link_attrs_of_row['last_seen'] : null) : ($device_link['last_seen'] ?? null)),
				'speed' => self::speedBps($attrs['speed'] ?? null),
				// 2.100: the port alias (ifAlias) of this port and of the far port, shown only; the mark "speed mismatch" of a port-level link whose ends are up reporter ports with different speeds (V-25)
				'alias' => ($attrs['alias'] ?? '') !== '' ? (string) $attrs['alias'] : null,
				'remote_alias' => $row['linkid'] !== null ? ($far_details[$row['linked_port_id']]['alias'] ?? null) : null,
				'speed_mismatch' => $row['linkid'] !== null && self::supersededAt($link_attrs_of_row) === null
					&& ($m = CTopologySpeed::mismatch(self::portFacts($attrs) + ['oper_status' => $attrs['oper_status'] ?? null], $far_details[$row['linked_port_id']] ?? [])) !== null
					? ['local' => $m['a'], 'remote' => $m['b']] : null,
				// (no `load` key on a port that is not in the Links section, set below)
				// A device-level link: the link's id and why its far port is unknown (null for a port-level one).
				'device_link_id' => $device_link['id'] ?? null,
				'far_port_reason' => $device_link['reason'] ?? null,
				// group mac_only: the MACs the switch learned on the port, or their number only above the MAC limit (§6.6)
				'learned_macs' => $group === 'mac_only' ? $learned_macs : [],
				'learned_mac_count' => $group === 'mac_only' ? $learned_mac_count : null,
				'source' => $device_link !== null ? ($device_link['discovered_via'] === 'manual' ? 'Manual' : 'LLDP')
					: ($discovered_via === 'manual' ? 'Manual' : ($row['linkid'] !== null ? 'LLDP'
					: ($group === 'mac_only' ? 'FDB' : '-')))
			];
			if (array_key_exists($row['id'], $itemids_by_port)) {
				$entry['load'] = $port_loads[$row['id']] ?? null;
				$entry['load_reason'] = $entry['load'] !== null ? null : ($items_of_port[(string) $row['id']]['reason'] ?? null);
			}
			$groups[$group][] = $entry;
		}

		// Order in a group (§12.5): ports with oper_status up first, then the others; in each part the natural order of the port name (1/1/2 before 1/1/10)
		foreach ($groups as &$ports) {
			usort($ports, static function (array $a, array $b): int {
				$up = ($b['status'] === 'up') <=> ($a['status'] === 'up');

				return $up ?: (strnatcasecmp((string) $a['port'], (string) $b['port']) ?: (int) $a['id'] <=> (int) $b['id']);
			});
		}
		unset($ports);

		return array_filter($groups, static fn (array $ports): bool => (bool) $ports);
	}

	/** The groups of the port table, in the order of the rules of topology-1-model.md §12.5 (the UI shows them in this order). */
	public const PORT_GROUPS = ['connected', 'lag', 'mgmt', 'virtual', 'mac_only', 'no_neighbor'];

	/** topology-1-model.md §12.5: the first rule that is true gives the group of a port. */
	public static function portGroup(bool $has_active_link, string $if_type, array $learned_macs, ?int $learned_mac_count): string {
		if ($has_active_link) {
			return 'connected';
		}
		if (in_array($if_type, ['lag', 'mgmt', 'virtual'], true)) {
			return $if_type;
		}
		if ($learned_macs || $learned_mac_count !== null) {
			return 'mac_only';
		}

		return 'no_neighbor';
	}

	/**
	 * topology-1-model.md §11 `GET /topo/devices/{id}/addresses`, §12.5 "Addresses": the addresses of a Device as its neighbors report them, so that the operator can create a
	 * Zabbix host for it with the right interface and proxy. Read only.
	 *
	 * Source: the current observations of the Device (getObservations(): only observations of a rule that still has a usable snapshot, only reporters that the user can read),
	 * field `rem_man_addrs` (every usable entry, decoded by CTopologyAddress, the code of normalization §4.4 that ingest also uses) and `rem_mgmt_ip`. A placeholder or
	 * invalid, unspecified or loopback address is not shown (CTopologyAddress::isHidden). One line per address; per address every source (reporter, local port, source,
	 * monitoring of the reporter). A link-local address is marked (`link_local`) and shown after the others; the `mgmt_ip` line that no current observation reports has no
	 * source and the mark `not_reported_now` (2.44).
	 * Order: the Device's `mgmt_ip` first (`management` true, also when it is link-local), then IPv4, then IPv6, then the link-local addresses (IPv4 before IPv6), each in
	 * numeric order (CTopologyAddress::sortKey).
	 *
	 * The monitoring of the reporters is read live in one go for the whole answer: one host.get for all reporter hosts, then one proxy.get and one proxygroup.get for the names
	 * (never one call per source).
	 *
	 * Every string comes from the network (sysname, chassis id, host and port names): data, escaped by whoever renders it (§13).
	 *
	 * @return array  ['sysname' => ?string, 'chassis_id' => ?string, 'mgmt_ip' => ?string, 'addresses' => [...]]
	 */
	public static function getAddresses(string $device_id): array {
		$row = DBfetch(DBselect('SELECT attrs FROM topo_nodes WHERE id='.zbx_dbstr($device_id).' AND type='.zbx_dbstr('device')), false);
		$attrs = $row ? (json_decode((string) $row['attrs'], true) ?: []) : [];
		$sysname = ($attrs['sysname'] ?? '') !== '' ? (string) $attrs['sysname'] : null;
		$chassis_id = ($attrs['chassis_id'] ?? '') !== '' ? self::chassisLabel((string) $attrs['chassis_id']) : null;
		$mgmt_ip = ($attrs['mgmt_ip'] ?? '') !== '' ? (string) $attrs['mgmt_ip'] : null;
		if ($mgmt_ip !== null && (CTopologyAddress::ipText($mgmt_ip) === null || CTopologyAddress::isHidden($mgmt_ip))) {
			$mgmt_ip = null;
		}

		$lines = [];     // address => ['address', 'family', 'management', 'sources' => [key => source]]
		$add = static function (string $address, int $family, array $source) use (&$lines, $mgmt_ip): void {
			$lines[$address] ??= ['address' => $address, 'family' => $family, 'management' => $address === $mgmt_ip, 'sources' => []];
			$lines[$address]['sources'][$source['reporter']['hostid'].'|'.$source['local_port'].'|'.$source['source']] = $source;
		};
		$hostids = [];

		if ($row) {
			foreach (self::getObservations(self::OBSERVATION_OUTCOMES, $device_id, 5000) as $observation) {
				$remote = $observation['remote_attrs'];
				$source = ['reporter' => ['hostid' => (string) $observation['reporter']['hostid'], 'name' => $observation['reporter']['name']],
					'local_port' => $observation['local_port']['name'], 'source' => (string) ($remote['source'] ?? 'lldp')];
				$found = [];
				if (is_string($remote['rem_man_addrs'] ?? null)) {
					$found = CTopologyAddress::decodeEntries($remote['rem_man_addrs']);
				}
				if (is_string($remote['rem_mgmt_ip'] ?? null) && ($text = CTopologyAddress::ipText($remote['rem_mgmt_ip'])) !== null) {
					$found[] = ['address' => $text, 'family' => str_contains($text, ':') ? 6 : 4];
				}
				foreach ($found as $entry) {
					if (!CTopologyAddress::isHidden($entry['address'])) {
						$add($entry['address'], $entry['family'], $source);
						$hostids[$source['reporter']['hostid']] = true;
					}
				}
			}
		}

		// the Device's own management address is the first line even when no current observation reports it
		if ($mgmt_ip !== null && !isset($lines[$mgmt_ip])) {
			$lines[$mgmt_ip] = ['address' => $mgmt_ip, 'family' => str_contains($mgmt_ip, ':') ? 6 : 4, 'management' => true, 'sources' => []];
		}

		$monitoring = self::getReporterMonitoring(array_keys($hostids));

		uasort($lines, static function (array $a, array $b): int {
			return [$a['management'] ? 0 : 1, CTopologyAddress::sortKey($a['address'])] <=> [$b['management'] ? 0 : 1, CTopologyAddress::sortKey($b['address'])];
		});

		$addresses = [];
		foreach ($lines as $line) {
			$sources = array_values($line['sources']);
			usort($sources, static fn (array $a, array $b): int => [$a['reporter']['name'], $a['local_port'], $a['source']] <=> [$b['reporter']['name'], $b['local_port'], $b['source']]);
			foreach ($sources as &$source) {
				$source['monitoring'] = $monitoring[$source['reporter']['hostid']] ?? ['mode' => 'server', 'proxy' => null, 'proxy_group' => null];
			}
			unset($source);
			$addresses[] = ['address' => $line['address'], 'family' => $line['family'], 'management' => $line['management'],
				'link_local' => CTopologyAddress::isLinkLocal($line['address']),
				'not_reported_now' => $line['management'] && !$sources,
				'sources' => $sources];
		}

		return ['sysname' => $sysname, 'chassis_id' => $chassis_id, 'mgmt_ip' => $mgmt_ip, 'addresses' => $addresses];
	}

	/**
	 * How the reporter hosts are monitored, live (topology-1-model.md §4.6): hostid => ['mode' => 'server'|'proxy'|'proxy_group', 'proxy' => ['id', 'name']|null,
	 * 'proxy_group' => ['id', 'name']|null]; for a proxy group `proxy` is the proxy that serves the host now (host.get `assigned_proxyid`). One host.get for all hosts, one
	 * proxy.get and one proxygroup.get for the names.
	 */
	private static function getReporterMonitoring(array $hostids): array {
		if (!$hostids) {
			return [];
		}

		$hosts = API::Host()->get([
			'output' => ['hostid', 'monitored_by', 'proxyid', 'proxy_groupid', 'assigned_proxyid'],
			'hostids' => $hostids
		]);

		$proxyids = [];
		$groupids = [];
		foreach ($hosts as $host) {
			switch ((int) $host['monitored_by']) {
				case ZBX_MONITORED_BY_PROXY:
					$proxyids[$host['proxyid']] = true;
					break;

				case ZBX_MONITORED_BY_PROXY_GROUP:
					$groupids[$host['proxy_groupid']] = true;
					if (($host['assigned_proxyid'] ?? '0') !== '0' && ($host['assigned_proxyid'] ?? '') !== '') {
						$proxyids[$host['assigned_proxyid']] = true;
					}
					break;
			}
		}

		$proxy_names = $proxyids ? array_column(API::Proxy()->get(['output' => ['proxyid', 'name'], 'proxyids' => array_keys($proxyids)]), 'name', 'proxyid') : [];
		$group_names = $groupids ? array_column(API::ProxyGroup()->get(['output' => ['proxy_groupid', 'name'], 'proxy_groupids' => array_keys($groupids)]), 'name', 'proxy_groupid') : [];

		$result = [];
		foreach ($hosts as $host) {
			$proxy = static fn (?string $id): ?array => $id !== null && $id !== '0' && $id !== '' ? ['id' => (string) $id, 'name' => $proxy_names[$id] ?? null] : null;
			$result[$host['hostid']] = match ((int) $host['monitored_by']) {
				ZBX_MONITORED_BY_PROXY => ['mode' => 'proxy', 'proxy' => $proxy($host['proxyid']), 'proxy_group' => null],
				ZBX_MONITORED_BY_PROXY_GROUP => ['mode' => 'proxy_group', 'proxy' => $proxy($host['assigned_proxyid'] ?? null),
					'proxy_group' => ['id' => (string) $host['proxy_groupid'], 'name' => $group_names[$host['proxy_groupid']] ?? null]],
				default => ['mode' => 'server', 'proxy' => null, 'proxy_group' => null]
			};
		}

		return $result;
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
	// automatic inventory MAC match, which ingest.php does itself with 'mac'); ingest.php's mirror also passes 'reporter_self'. matched_mac is deliberately NOT accepted/stored here
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
				' JOIN topo_lld_snapshot snapshot ON snapshot.itemid=o.itemid AND snapshot.topology_type=i.topology_type'.
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

	/** A placeholder MAC (normalization §4.1: all zero or all ones, in any of the MAC spellings): never an identity. The mirror of `$is_placeholder_mac` of ingest.php. */
	private static function isPlaceholderMac(string $value): bool {
		$value = strtolower(trim($value));
		$octets = CTopologyAddress::macOctets($value);
		if ($octets === null || count($octets) !== 6) {
			return false;
		}
		$octets = array_map(static fn (string $o): string => str_pad($o, 2, '0', STR_PAD_LEFT), $octets);

		return in_array(implode(':', $octets), ['00:00:00:00:00:00', 'ff:ff:ff:ff:ff:ff'], true);
	}

	/** The distinct loc_chassis values the reporter host of a Device reports in its stored snapshots (empty: not a reporter). A placeholder MAC is not a chassis. */
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
				if (($row['loc_chassis'] ?? '') !== '' && !self::isPlaceholderMac((string) $row['loc_chassis'])) {
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
				if (in_array($key, ['last_seen', 'chassis_id', 'mac', 'replaced_chassis', 'duplicate_candidates', 'duplicate_dismissed', 'matched_by', 'scope_port', 'scope_ports'], true)) {
					continue;
				}
				if (($k[$key] ?? null) === null || $k[$key] === '') {
					if ($value !== null && $value !== '') {
						$k[$key] = $value;
					}
				}
			}
			$k['last_seen'] = max((int) ($k['last_seen'] ?? 0), (int) ($r['last_seen'] ?? 0));
			// §6.7.5 merge steps (2.52): K gets the union of the scope_ports of K and R (one Device on all the ports of its claims); a Device that has a chassis has none
			$scope_ports = CTopologyAddress::scopePorts($k);
			foreach (CTopologyAddress::scopePorts($r) as $scope_port) {
				if (!in_array($scope_port, $scope_ports, true)) {
					$scope_ports[] = $scope_port;
				}
			}
			sort($scope_ports);
			unset($k['scope_port'], $k['scope_ports']);
			if (($k['chassis_id'] ?? '') === '' && $scope_ports) {
				$k['scope_ports'] = $scope_ports;
			}
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

			self::mergeIdentityKeys($keep_id, $remove_id, $replaced, (string) ($k['chassis_id'] ?? ''));
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

	/**
	 * The groups of Devices linked by duplicate candidates, directly or through others (§6.7.5): [device id => sorted ids of its group, itself included].
	 * Only Devices that have a candidate pointing at a Device are in a group. Dismissed pairs are not candidates, so they do not link.
	 *
	 * @param array $nodes  nodes as getDevices() builds them (id, duplicates)
	 */
	public static function duplicateGroups(array $nodes): array {
		$parent = [];
		$find = static function (int $id) use (&$parent, &$find): int {
			$parent[$id] ??= $id;
			return $parent[$id] === $id ? $id : ($parent[$id] = $find($parent[$id]));
		};
		foreach ($nodes as $node) {
			foreach ($node['duplicates'] ?? [] as $candidate) {
				if ($candidate['device_id'] !== null) {
					$parent[$find((int) $node['id'])] = $find((int) $candidate['device_id']);
				}
			}
		}
		$members = [];
		foreach (array_keys($parent) as $id) {
			$members[$find($id)][] = $id;
		}
		$result = [];
		foreach ($members as $list) {
			sort($list);
			foreach ($list as $id) {
				$result[$id] = $list;
			}
		}

		return $result;
	}

	/**
	 * Merge a group (topology-1-model.md §6.7.5): keep K, remove R1..Rn, applied in a fixed order (by id), so the result does not depend on the order
	 * the caller lists them in. Refused before anything is written when more than one of the Devices has a represented_by.
	 *
	 * @param string[] $remove_ids
	 */
	public static function mergeGroup(string $keep_id, array $remove_ids, string $username): array {
		$remove_ids = array_values(array_unique(array_map('strval', $remove_ids)));
		$remove_ids = array_values(array_diff($remove_ids, [$keep_id]));
		if (!$remove_ids) {
			throw new Exception('Choose the Devices to merge.');
		}
		usort($remove_ids, static fn (string $a, string $b): int => (int) $a <=> (int) $b);

		$represented = 0;
		foreach (array_merge([$keep_id], $remove_ids) as $id) {
			$row = self::getDeviceRow($id);
			$represented += $row['represented_by_node_id'] !== null ? 1 : 0;
		}
		if ($represented > 1) {
			throw new Exception('More than one Device of the group is represented by a host or proxy: they cannot all be merged.');
		}

		$removed = [];
		foreach ($remove_ids as $id) {
			$removed[] = self::mergeDevices($keep_id, $id, $username)['removed'];
		}

		return ['kept' => (int) $keep_id, 'removed' => $removed];
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
		self::movePortSysnameKeys($from, $to);
	}

	/** The scoped sysname keys of the removed Port become keys of the kept Port: `sysname:<from>:<value>` -> `sysname:<to>:<value>` (§6.7.0); a Device that holds the new key loses the old one. */
	private static function movePortSysnameKeys(string $from, string $to): void {
		$col = self::keyColumn();
		$prefix = 'sysname:'.$from.':';
		$result = DBselect('SELECT device_id,'.$col.' AS k FROM topo_identity_keys WHERE kind='.zbx_dbstr('sysname').' AND '.$col.' LIKE '.zbx_dbstr(addcslashes($prefix, '%_\\').'%'));
		$rows = [];
		while ($row = DBfetch($result, false)) {
			if (str_starts_with($row['k'], $prefix)) {
				$rows[] = $row;
			}
		}
		foreach ($rows as $row) {
			$new = substr('sysname:'.$to.':'.substr($row['k'], strlen($prefix)), 0, 300);
			if (DBfetch(DBselect('SELECT device_id FROM topo_identity_keys WHERE device_id='.zbx_dbstr($row['device_id']).' AND '.$col.'='.zbx_dbstr($new), 1), false)) {
				DBexecute('DELETE FROM topo_identity_keys WHERE device_id='.zbx_dbstr($row['device_id']).' AND '.$col.'='.zbx_dbstr($row['k']));
			}
			else {
				DBexecute('UPDATE topo_identity_keys SET '.$col.'='.zbx_dbstr($new).' WHERE device_id='.zbx_dbstr($row['device_id']).' AND '.$col.'='.zbx_dbstr($row['k']));
			}
		}
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

		// the keys (§6.7.0): the chassis the host reports is the Device's chassis key now; the old chassis keys stay, marked replaced
		$col = self::keyColumn();
		$host = DBfetch(DBselect('SELECT host_ref FROM topo_nodes WHERE id='.zbx_dbstr($row['represented_by_node_id']).' AND type='.zbx_dbstr('host')), false);
		$new_key = self::chassisKeyOf((string) $entry['value'], $host ? (string) $host['host_ref'] : null);
		DBexecute('UPDATE topo_identity_keys SET replaced=1 WHERE device_id='.zbx_dbstr($device_id).' AND kind='.zbx_dbstr('chassis').' AND '.$col.'<>'.zbx_dbstr($new_key));
		if (DBfetch(DBselect('SELECT device_id FROM topo_identity_keys WHERE device_id='.zbx_dbstr($device_id).' AND '.$col.'='.zbx_dbstr($new_key), 1), false)) {
			DBexecute('UPDATE topo_identity_keys SET replaced=0 WHERE device_id='.zbx_dbstr($device_id).' AND '.$col.'='.zbx_dbstr($new_key));
		}
		else {
			DBexecute('INSERT INTO topo_identity_keys (device_id,'.$col.',kind,first_seen,replaced) VALUES ('.zbx_dbstr($device_id).','.zbx_dbstr($new_key).','.
				zbx_dbstr('chassis').','.time().',0)');
		}
	}

	// ---- identity keys (topology-1-model.md §6.7.0) ----
	private static function keyColumn(): string {
		global $DB;

		return $DB['TYPE'] === ZBX_DB_POSTGRESQL ? '"key"' : '`key`';
	}

	/** The chassis value of a chassis key string: `chassis:mac:<mac>` or `chassis:<type>:<segment>:<value>`; null for another kind of key. */
	private static function chassisOfKey(string $key): ?string {
		if (str_starts_with($key, 'chassis:mac:')) {
			return substr($key, strlen('chassis:mac:'));
		}

		return preg_match('/^chassis:[^:]+:(?:server|pg:\d+|proxy:\d+):(.*)$/s', $key, $m) === 1 ? $m[1] : null;
	}

	/** The key string of a chassis value reported by a host (`loc_chassis`): a MAC is global, any other value is scoped to the segment of the host. */
	private static function chassisKeyOf(string $value, ?string $hostid): string {
		if (preg_match('/^([0-9a-f]{2}:){5}[0-9a-f]{2}$/i', $value) === 1) {
			return 'chassis:mac:'.strtolower($value);
		}
		$segment = 'server';
		if ($hostid !== null) {
			$host = DBfetch(DBselect('SELECT monitored_by,proxyid,proxy_groupid FROM hosts WHERE hostid='.zbx_dbstr($hostid)), false);
			if ($host && (int) $host['monitored_by'] === ZBX_MONITORED_BY_PROXY_GROUP && $host['proxy_groupid'] !== null) {
				$segment = 'pg:'.$host['proxy_groupid'];
			}
			elseif ($host && (int) $host['monitored_by'] === ZBX_MONITORED_BY_PROXY && $host['proxyid'] !== null) {
				$segment = 'proxy:'.$host['proxyid'];
			}
		}

		return substr('chassis:local:'.$segment.':'.$value, 0, 300);
	}

	/**
	 * Merge: R's keys become K's (the ones K does not hold yet); the chassis keys whose chassis is in $replaced get replaced = 1, the one K ends with
	 * ($chosen) does not (§6.7.5). R's own rows go with the Device (ON DELETE CASCADE).
	 */
	private static function mergeIdentityKeys(string $keep_id, string $remove_id, array $replaced, string $chosen): void {
		$col = self::keyColumn();
		$have = [];
		$result = DBselect('SELECT '.$col.' AS k FROM topo_identity_keys WHERE device_id='.zbx_dbstr($keep_id));
		while ($row = DBfetch($result, false)) {
			$have[$row['k']] = true;
		}
		$result = DBselect('SELECT '.$col.' AS k,kind,first_seen,replaced FROM topo_identity_keys WHERE device_id='.zbx_dbstr($remove_id));
		while ($row = DBfetch($result, false)) {
			if (!isset($have[$row['k']])) {
				DBexecute('INSERT INTO topo_identity_keys (device_id,'.$col.',kind,first_seen,replaced) VALUES ('.zbx_dbstr($keep_id).','.
					zbx_dbstr($row['k']).','.zbx_dbstr($row['kind']).','.(int) $row['first_seen'].','.(int) $row['replaced'].')');
			}
		}
		$result = DBselect('SELECT '.$col.' AS k,replaced FROM topo_identity_keys WHERE device_id='.zbx_dbstr($keep_id).' AND kind='.zbx_dbstr('chassis'));
		while ($row = DBfetch($result, false)) {
			$value = self::chassisOfKey($row['k']);
			$want = $value !== null && $value !== $chosen && in_array($value, $replaced, true) ? 1 : 0;
			if ((int) $row['replaced'] !== $want) {
				DBexecute('UPDATE topo_identity_keys SET replaced='.$want.' WHERE device_id='.zbx_dbstr($keep_id).' AND '.$col.'='.zbx_dbstr($row['k']));
			}
		}
	}

	/**
	 * Delete Device (topology-1-model.md §6.7.5, 2.47, FR-C O-13): the operator deletes a Device that has no association and no manual link. The Device, its Ports and its
	 * discovered links go (the foreign keys cascade); the observations keep their claim, their `device_id` and `edge_id` become NULL (the next full ingest gives them a verdict);
	 * the entries that name the Device in `duplicate_dismissed` / `duplicate_candidates` of other Devices and in `shadow_ack` of manual links are removed. If a current row
	 * reports the neighbor again, ingest makes a Device again, with a new id.
	 *
	 * @throws Exception  with a message for the operator when the Device is refused
	 * @return array      ['deleted' => id, 'ports' => n, 'links' => n]
	 */
	public static function deleteDevice(string $device_id, string $username): array {
		$device = self::getDeviceRow($device_id);

		if ($device['represented_by_node_id'] !== null) {
			throw new Exception('The Device is associated with a host or proxy: remove the association first (Depromote).');
		}

		$manual = (self::attrs($device)['source'] ?? null) === 'manual';
		$ports = [];
		$result = DBselect('SELECT id FROM topo_nodes WHERE type='.zbx_dbstr('port').' AND device_id='.zbx_dbstr($device_id));
		while ($row = DBfetch($result, false)) {
			$ports[] = (string) $row['id'];
		}

		// the links of the Device: physical links on its Ports, device links on its Ports or to the Device
		$ends = array_merge([$device_id], $ports);
		$links = [];
		$result = DBselect('SELECT id,type,attrs FROM topo_edges WHERE type IN ('.zbx_dbstr('physical_link').','.zbx_dbstr('device_link').') AND ('.
			dbConditionInt('src_id', $ends).' OR '.dbConditionInt('dst_id', $ends).')');
		while ($edge = DBfetch($result, false)) {
			// a manual Device (§9.7) takes its manual links with it; the manual link of a discovered Device must be deleted first
			if (!self::isDiscovered($edge) && !$manual) {
				throw new Exception('The Device has a manual link: delete the link first.');
			}
			$links[] = (string) $edge['id'];
		}

		DBstart();

		try {
			// the entries that name the Device elsewhere
			$result = DBselect('SELECT id,attrs FROM topo_nodes WHERE type='.zbx_dbstr('device').' AND id<>'.zbx_dbstr($device_id).
				' AND (attrs LIKE '.zbx_dbstr('%duplicate_dismissed%').' OR attrs LIKE '.zbx_dbstr('%duplicate_candidates%').')');
			while ($row = DBfetch($result, false)) {
				$attrs = self::attrs($row);
				$changed = false;

				foreach (['duplicate_dismissed', 'duplicate_candidates'] as $list) {
					if (!array_key_exists($list, $attrs)) {
						continue;
					}

					$kept = array_values(array_filter($attrs[$list],
						static fn (array $entry): bool => (string) ($entry['device_id'] ?? '') !== $device_id));

					if (count($kept) !== count($attrs[$list])) {
						$changed = true;

						if ($kept) {
							$attrs[$list] = $kept;
						}
						else {
							unset($attrs[$list]);
						}
					}
				}

				if ($changed) {
					DBexecute('UPDATE topo_nodes SET attrs='.zbx_dbstr(json_encode($attrs)).',updated_at='.time().' WHERE id='.zbx_dbstr($row['id']));
				}
			}

			$result = DBselect('SELECT id,attrs FROM topo_edges WHERE attrs LIKE '.zbx_dbstr('%shadow_ack%'));
			while ($edge = DBfetch($result, false)) {
				$attrs = self::attrs($edge);
				$kept = array_values(array_filter($attrs['shadow_ack'] ?? [],
					static fn (array $entry): bool => (string) ($entry['device_id'] ?? '') !== $device_id));

				if (count($kept) !== count($attrs['shadow_ack'] ?? [])) {
					if ($kept) {
						$attrs['shadow_ack'] = $kept;
					}
					else {
						unset($attrs['shadow_ack']);
					}

					DBexecute('UPDATE topo_edges SET attrs='.zbx_dbstr(json_encode($attrs)).' WHERE id='.zbx_dbstr($edge['id']));
				}
			}

			// the discovered links, the Ports and the Device (observations: device_id and edge_id become NULL by the foreign keys)
			if ($links) {
				DBexecute('DELETE FROM topo_edges WHERE '.dbConditionInt('id', $links));
			}

			DBexecute('DELETE FROM topo_nodes WHERE id='.zbx_dbstr($device_id));
		}
		catch (Throwable $exception) {
			DBend(false);

			throw $exception;
		}

		DBend(true);

		return ['deleted' => (int) $device_id, 'ports' => count($ports), 'links' => count($links)];
	}

	// ---- Manual Device (topology-1-model.md §9.7, 2.80; FR-C O-15). Every method below runs under the run lock (the controllers wrap it in CTopologyRun::guard). ----

	public const MANUAL_DEVICE_CLASSES = ['router', 'switch', 'access_point', 'phone', 'station', 'other'];

	/**
	 * The hardware ID of a manual Device (§9.7): a MAC in one of the forms of normalization §4.1, in normal form; an all-zero or all-ff MAC is refused (§6.7.0). '' is no hardware ID.
	 */
	private static function manualHardwareId(string $value): string {
		$value = trim($value);
		if ($value === '') {
			return '';
		}
		$octets = CTopologyAddress::macOctets($value);
		if ($octets === null || count($octets) !== 6) {
			throw new Exception('The hardware ID is not a MAC address.');
		}
		$mac = CTopologyAddress::macFormId($value);
		if ($mac === null) {
			throw new Exception('The hardware ID is a placeholder MAC (all zero or all ff) and can not identify a Device.');
		}

		return $mac;
	}

	/** The management address of a manual Device: IPv4 or IPv6 in the text of normalization §4.3; unspecified, loopback and multicast are refused (§4.4). '' is none. */
	private static function manualAddress(string $value): string {
		$value = trim($value);
		if ($value === '') {
			return '';
		}
		$text = CTopologyAddress::ipText($value);
		if ($text === null || CTopologyAddress::isHidden($text)) {
			throw new Exception('The management address is not a usable IPv4 or IPv6 address.');
		}

		return $text;
	}

	/** The hardware ID is an identity key (§9.7): another Device that holds the key refuses it. */
	private static function assertHardwareIdFree(string $mac, ?string $device_id): void {
		$result = DBselect('SELECT k.device_id FROM topo_identity_keys k JOIN topo_nodes d ON d.id=k.device_id'.
			' WHERE k.'.self::keyColumn().'='.zbx_dbstr('chassis:mac:'.$mac).' AND k.replaced=0'.
			($device_id !== null ? ' AND k.device_id<>'.zbx_dbstr($device_id) : '').' ORDER BY k.device_id', 1);
		if ($other = DBfetch($result, false)) {
			throw new Exception(self::deviceLabel((string) $other['device_id']).' has this hardware ID (Device '.$other['device_id'].').');
		}
	}

	private static function manualDeviceRow(string $device_id): array {
		$row = self::getDeviceRow($device_id);
		if ((self::attrs($row)['source'] ?? null) !== 'manual') {
			throw new Exception('A discovered Device is not edited by hand.');
		}

		return $row;
	}

	private static function manualClass(string $class): string {
		if (!in_array($class, self::MANUAL_DEVICE_CLASSES, true)) {
			throw new Exception('The device class is not one of: '.implode(', ', self::MANUAL_DEVICE_CLASSES).'.');
		}

		return $class;
	}

	private static function manualPortName($value): string {
		$name = trim((string) $value);
		if ($name === '') {
			throw new Exception('The port name is empty.');
		}
		if (strlen($name) > 255) {
			throw new Exception('The port name is longer than 255 characters.');
		}

		return $name;
	}

	/** @return string[]  the names of the ports of a Device (as stored) */
	private static function portNamesOf(string $device_id, ?string $except_port_id = null): array {
		$names = [];
		$result = DBselect('SELECT id,attrs FROM topo_nodes WHERE type='.zbx_dbstr('port').' AND device_id='.zbx_dbstr($device_id));
		while ($row = DBfetch($result, false)) {
			if ($except_port_id === null || (string) $row['id'] !== $except_port_id) {
				$names[] = (string) (self::attrs($row)['name'] ?? '');
			}
		}

		return $names;
	}

	private static function insertManualPort(string $device_id, string $name, string $description, string $userid): string {
		$attrs = ['name' => $name, 'if_type' => 'physical', 'pseudo' => false, 'source' => 'manual', 'created_by' => $userid, 'created_at' => time()];
		if ($description !== '') {
			$attrs['description'] = $description;
		}
		DBexecute('INSERT INTO topo_nodes (type,device_id,attrs,created_at,updated_at) VALUES ('.zbx_dbstr('port').','.zbx_dbstr($device_id).','.
			zbx_dbstr(json_encode($attrs)).','.time().','.time().')');

		return (string) DBfetch(DBselect('SELECT MAX(id) AS id FROM topo_nodes WHERE type='.zbx_dbstr('port').' AND device_id='.zbx_dbstr($device_id)), false)['id'];
	}

	/**
	 * Create a manual Device (§9.7). `$input`: name (required), device_class (required), chassis_id, mgmt_ip, ports: [{name, description?}].
	 *
	 * @return array  ['id' => Device id, 'ports' => [{id, name}]]
	 */
	public static function createDevice(array $input, string $userid): array {
		$name = trim((string) ($input['name'] ?? ''));
		if ($name === '') {
			throw new Exception('The name is empty.');
		}
		$class = self::manualClass((string) ($input['device_class'] ?? ''));
		$mac = self::manualHardwareId((string) ($input['chassis_id'] ?? ''));
		$address = self::manualAddress((string) ($input['mgmt_ip'] ?? ''));
		$ports = [];
		$seen = [];
		foreach ($input['ports'] ?? [] as $port) {
			$port_name = self::manualPortName(is_array($port) ? ($port['name'] ?? '') : $port);
			if (isset($seen[$port_name])) {
				throw new Exception('The port name '.$port_name.' is used twice.');
			}
			$seen[$port_name] = true;
			$ports[] = [$port_name, is_array($port) ? trim((string) ($port['description'] ?? '')) : ''];
		}
		if ($mac !== '') {
			self::assertHardwareIdFree($mac, null);
		}

		$attrs = ['sysname' => $name, 'source' => 'manual', 'device_class' => $class, 'created_by' => $userid, 'created_at' => time()];
		if ($mac !== '') {
			$attrs['mac'] = $mac;
			$attrs['chassis_id'] = $mac;
		}
		if ($address !== '') {
			$attrs['mgmt_ip'] = $address;
		}

		DBstart();
		try {
			DBexecute('INSERT INTO topo_nodes (type,attrs,created_at,updated_at) VALUES ('.zbx_dbstr('device').','.zbx_dbstr(json_encode($attrs)).','.time().','.time().')');
			$device_id = (string) DBfetch(DBselect('SELECT MAX(id) AS id FROM topo_nodes WHERE type='.zbx_dbstr('device')), false)['id'];
			if ($mac !== '') {
				DBexecute('INSERT INTO topo_identity_keys (device_id,'.self::keyColumn().',kind,first_seen,replaced) VALUES ('.zbx_dbstr($device_id).','.
					zbx_dbstr('chassis:mac:'.$mac).','.zbx_dbstr('chassis').','.time().',0)');
			}
			$created = [];
			foreach ($ports as [$port_name, $description]) {
				$created[] = ['id' => self::insertManualPort($device_id, $port_name, $description, $userid), 'name' => $port_name];
			}
		}
		catch (Throwable $exception) {
			DBend(false);

			throw $exception;
		}
		DBend(true);

		return ['id' => $device_id, 'ports' => $created];
	}

	/** Edit a manual Device (§9.7): any of name, device_class, chassis_id (empty: none), mgmt_ip (empty: none). A discovered Device is refused. */
	public static function updateDevice(string $device_id, array $input, string $userid): array {
		$row = self::manualDeviceRow($device_id);
		$attrs = self::attrs($row);
		$old_mac = (string) ($attrs['chassis_id'] ?? '');
		if (array_key_exists('name', $input)) {
			$name = trim((string) $input['name']);
			if ($name === '') {
				throw new Exception('The name is empty.');
			}
			$attrs['sysname'] = $name;
		}
		if (array_key_exists('device_class', $input)) {
			$attrs['device_class'] = self::manualClass((string) $input['device_class']);
		}
		if (array_key_exists('mgmt_ip', $input)) {
			$address = self::manualAddress((string) $input['mgmt_ip']);
			if ($address === '') {
				unset($attrs['mgmt_ip']);
			}
			else {
				$attrs['mgmt_ip'] = $address;
			}
		}
		$new_mac = $old_mac;
		if (array_key_exists('chassis_id', $input)) {
			$new_mac = self::manualHardwareId((string) $input['chassis_id']);
			if ($new_mac !== '') {
				self::assertHardwareIdFree($new_mac, $device_id);
			}
			if ($new_mac === '') {
				unset($attrs['mac'], $attrs['chassis_id']);
			}
			else {
				$attrs['mac'] = $new_mac;
				$attrs['chassis_id'] = $new_mac;
			}
		}
		$attrs['updated_by'] = $userid;
		$attrs['updated_at'] = time();

		DBstart();
		try {
			if ($new_mac !== $old_mac) {
				if ($old_mac !== '') {
					DBexecute('DELETE FROM topo_identity_keys WHERE device_id='.zbx_dbstr($device_id).' AND '.self::keyColumn().'='.zbx_dbstr('chassis:mac:'.$old_mac));
				}
				if ($new_mac !== '') {
					DBexecute('INSERT INTO topo_identity_keys (device_id,'.self::keyColumn().',kind,first_seen,replaced) VALUES ('.zbx_dbstr($device_id).','.
						zbx_dbstr('chassis:mac:'.$new_mac).','.zbx_dbstr('chassis').','.time().',0)');
				}
			}
			DBexecute('UPDATE topo_nodes SET attrs='.zbx_dbstr(json_encode($attrs)).',updated_at='.time().' WHERE id='.zbx_dbstr($device_id));
		}
		catch (Throwable $exception) {
			DBend(false);

			throw $exception;
		}
		DBend(true);

		return ['id' => $device_id];
	}

	/** Add a port to a manual Device (§9.7): a name that is not used in the Device, an optional description. */
	public static function createPort(string $device_id, array $input, string $userid): array {
		self::manualDeviceRow($device_id);
		$name = self::manualPortName($input['name'] ?? '');
		if (in_array($name, self::portNamesOf($device_id), true)) {
			throw new Exception('The port name '.$name.' is used twice.');
		}
		$port_id = self::insertManualPort($device_id, $name, trim((string) ($input['description'] ?? '')), $userid);
		self::touchManualDevice($device_id, $userid);

		return ['id' => $port_id, 'name' => $name];
	}

	/** The port of a manual Device that the operator may change: a manual port, without a link ("Unlink the port first"). */
	private static function manualPortRow(string $port_id): array {
		$row = DBfetch(DBselect('SELECT id,device_id,attrs FROM topo_nodes WHERE id='.zbx_dbstr($port_id).' AND type='.zbx_dbstr('port')), false);
		if (!$row || $row['device_id'] === null) {
			throw new Exception('Port '.$port_id.' does not exist.');
		}
		if ((self::attrs($row)['source'] ?? null) !== 'manual') {
			throw new Exception('A discovered port is not changed by hand.');
		}
		self::manualDeviceRow((string) $row['device_id']);
		if (DBfetch(DBselect('SELECT id FROM topo_edges WHERE type IN ('.zbx_dbstr('physical_link').','.zbx_dbstr('device_link').') AND (src_id='.zbx_dbstr($port_id).
				' OR dst_id='.zbx_dbstr($port_id).')', 1), false)) {
			throw new Exception('The port has a link: unlink the port first.');
		}

		return $row;
	}

	/** Rename a manual port and change its description (§9.7). */
	public static function updatePort(string $port_id, array $input, string $userid): array {
		$row = self::manualPortRow($port_id);
		$attrs = self::attrs($row);
		if (array_key_exists('name', $input)) {
			$name = self::manualPortName($input['name']);
			if (in_array($name, self::portNamesOf((string) $row['device_id'], $port_id), true)) {
				throw new Exception('The port name '.$name.' is used twice.');
			}
			$attrs['name'] = $name;
		}
		if (array_key_exists('description', $input)) {
			$description = trim((string) $input['description']);
			if ($description === '') {
				unset($attrs['description']);
			}
			else {
				$attrs['description'] = $description;
			}
		}
		DBexecute('UPDATE topo_nodes SET attrs='.zbx_dbstr(json_encode($attrs)).',updated_at='.time().' WHERE id='.zbx_dbstr($port_id));
		self::touchManualDevice((string) $row['device_id'], $userid);

		return ['id' => $port_id];
	}

	public static function deletePort(string $port_id, string $userid): array {
		$row = self::manualPortRow($port_id);
		DBexecute('DELETE FROM topo_nodes WHERE id='.zbx_dbstr($port_id));
		self::touchManualDevice((string) $row['device_id'], $userid);

		return ['deleted' => (int) $port_id];
	}

	/** The prefixes of the item keys of an interface (topology-1-model.md §4.8, §9.7 "Bind to interface"): the load items and the status item. */
	// (task 118: the Zabbix agent template "Linux by Zabbix agent" keeps the speed and the operational status of an interface in `vfs.file.contents["/sys/class/net/<name>/speed"]` and `.../operstate"]`)
	private const INTERFACE_KEYS = ['net.if.in[', 'net.if.out[', 'net.if.speed[', 'net.if.status[', 'vfs.file.contents["/sys/class/net/'];

	/**
	 * A manual port that can be bound (§9.7 "Bind to interface"): a manual port of a manual Device that is associated with a HOST. A discovered port, a Device without an association and a Device
	 * associated with a proxy are refused. A link on the port does not matter (the manual link stays). Returns [port row, hostid].
	 */
	private static function bindablePort(string $port_id): array {
		$row = DBfetch(DBselect('SELECT id,device_id,attrs FROM topo_nodes WHERE id='.zbx_dbstr($port_id).' AND type='.zbx_dbstr('port')), false);
		if (!$row || $row['device_id'] === null) {
			throw new Exception('Port '.$port_id.' does not exist.');
		}
		if ((self::attrs($row)['source'] ?? null) !== 'manual') {
			throw new Exception('A discovered port is not changed by hand.');
		}
		$device = self::manualDeviceRow((string) $row['device_id']);
		if ($device['represented_by_node_id'] === null) {
			throw new Exception('The Device is not associated with a host (Promote it first): there is no interface to bind to.');
		}
		$host = DBfetch(DBselect('SELECT host_ref FROM topo_nodes WHERE id='.zbx_dbstr($device['represented_by_node_id']).' AND type='.zbx_dbstr('host')), false);
		if (!$host || $host['host_ref'] === null) {
			throw new Exception('The Device is associated with a proxy, not a host: there is no interface to bind to.');
		}

		return [$row, (string) $host['host_ref']];
	}

	/**
	 * The interfaces of hosts (§3.2, §9.7): the discovered items (made from item prototypes) with the key prefixes of INTERFACE_KEYS, grouped by the LLD value that they share. The value is read from the
	 * key of the item against the key of its prototype (`item_discovery.key_`): `net.if.in[ifHCInOctets.{#SNMPINDEX}]` and `net.if.in[ifHCInOctets.17]` give 17; `net.if.in["{#IFNAME}"]` and
	 * `net.if.in["eth0"]` give eth0. The macro is {#SNMPINDEX} or {#IFINDEX} when the key has one (then the value is a number: the `if_index`), else the first macro of the key. The name is the value of the item
	 * tag `interface` (the template sets it to {#IFNAME}); without it (an unresolved macro too) the LLD value. Only items of the hosts that the user can read (as item.get gives them). ONE SQL query for all the hosts.
	 * The tags cost as much as the items (task 110: 1368 items of 8 hosts, 30-60 ms), so they are read only when a name is needed (`$names`, or interfaceNames() for some hosts later).
	 *
	 * @param string[] $hostids
	 * @param bool     $names    read the tag `interface` too
	 *
	 * @return array  [hostid => [lld value => ['lld' => string, 'if_index' => int|null, 'name' => string, 'itemids' => [int, ...]]]]
	 */
	private static function interfacesOfHosts(array $hostids, bool $names = true): array {
		$hostids = array_values(array_unique(array_map('strval', $hostids)));
		if (!$hostids) {
			return [];
		}
		$hostids = self::readableHostIds($hostids);
		if (!$hostids) {
			return [];
		}
		// ONE query over items and item_discovery for all the hosts (task 111; model 2.94 section 18): the discovered items with the key prefixes, with the key of their prototype. The tag `interface`
		// is joined in when the names are wanted.
		$prefixes = [];
		foreach (self::INTERFACE_KEYS as $prefix) {
			$prefixes[] = 'i.key_ LIKE '.zbx_dbstr($prefix.'%');
		}
		$items = [];
		$cursor = DBselect(
			'SELECT i.itemid,i.hostid,i.key_,d.key_ AS prototype_key'.($names ? ',t.value AS tag_value' : '').
			' FROM items i JOIN item_discovery d ON d.itemid=i.itemid'.
			($names ? ' LEFT JOIN item_tag t ON t.itemid=i.itemid AND t.tag='.zbx_dbstr('interface') : '').
			' WHERE '.dbConditionId('i.hostid', $hostids).' AND i.flags='.ZBX_FLAG_DISCOVERY_CREATED.' AND i.type<>'.ITEM_TYPE_HTTPTEST.' AND ('.implode(' OR ', $prefixes).')'
		);
		while ($row = DBfetch($cursor, false)) {
			$items[] = ['itemid' => $row['itemid'], 'hostid' => $row['hostid'], 'key_' => $row['key_'], 'itemDiscovery' => ['key_' => $row['prototype_key']],
				'tags' => $names && $row['tag_value'] !== null ? [['tag' => 'interface', 'value' => $row['tag_value']]] : []];
		}
		$interfaces = [];
		$patterns = [];
		foreach ($items as $item) {
			if (!array_filter(self::INTERFACE_KEYS, static fn (string $prefix): bool => str_starts_with($item['key_'], $prefix))) {
				continue;
			}
			$prototype_key = (string) ($item['itemDiscovery']['key_'] ?? '');
			if (!array_key_exists($prototype_key, $patterns)) {
				$patterns[$prototype_key] = self::lldPattern($prototype_key);
			}
			if ($patterns[$prototype_key] === null || !preg_match($patterns[$prototype_key], $item['key_'], $match)) {
				continue;
			}
			$lld = (string) $match[1];
			$numeric = ctype_digit($lld);
			$interface = &$interfaces[$item['hostid']][$lld];
			$interface ??= ['lld' => $lld, 'if_index' => $numeric ? (int) $lld : null, 'name' => $lld, 'itemids' => []];
			$interface['itemids'][] = (int) $item['itemid'];
			self::applyInterfaceTags($interface, $item['tags'] ?? []);
			unset($interface);
		}
		foreach ($interfaces as &$of_host) {
			foreach ($of_host as &$interface) {
				sort($interface['itemids']);
			}
			unset($interface);
		}
		unset($of_host);

		return $interfaces;
	}

	private static function applyInterfaceTags(array &$interface, array $tags): void {
		foreach ($tags as $tag) {
			if ($tag['tag'] === 'interface' && $tag['value'] !== '' && !str_contains($tag['value'], '{#')) {
				$interface['name'] = $tag['value'];
			}
		}
	}

	/** Reads the names of the interfaces of some hosts (the tag `interface` of their items) into `$interfaces` ([hostid => [lld => interface]], from interfacesOfHosts($hostids, false)). */
	private static function interfaceNames(array &$interfaces, array $hostids): void {
		$itemids = [];
		$owner = [];
		foreach ($hostids as $hostid) {
			foreach ($interfaces[$hostid] ?? [] as $lld => $interface) {
				foreach ($interface['itemids'] as $itemid) {
					$itemids[] = $itemid;
					$owner[$itemid] = [$hostid, $lld];
				}
			}
		}
		if (!$itemids) {
			return;
		}
		// (the hosts were checked for read access by interfacesOfHosts(); the items are theirs)
		$cursor = DBselect('SELECT itemid,value FROM item_tag WHERE tag='.zbx_dbstr('interface').' AND '.dbConditionId('itemid', $itemids));
		while ($row = DBfetch($cursor, false)) {
			[$hostid, $lld] = $owner[(int) $row['itemid']];
			self::applyInterfaceTags($interfaces[$hostid][$lld], [['tag' => 'interface', 'value' => $row['value']]]);
		}
	}

	/**
	 * The hosts of the list that the user may read (what item.get gives: the items of readable hosts). A super admin reads every host; for another user one host.get.
	 *
	 * @param string[] $hostids
	 *
	 * @return string[]
	 */
	private static function readableHostIds(array $hostids): array {
		if (!$hostids || CWebUser::getType() == USER_TYPE_SUPER_ADMIN) {
			return $hostids;
		}

		return array_map('strval', array_column(API::Host()->get(['output' => ['hostid'], 'hostids' => $hostids]), 'hostid'));
	}

	/**
	 * The regular expression that gives the LLD value from the key of an item of a prototype, or null when the key of the prototype has no LLD macro ({#SNMPINDEX} and {#IFINDEX} give a number; else
	 * the first macro gives text; any other macro of the key matches anything).
	 */
	private static function lldPattern(string $prototype_key): ?string {
		if (!preg_match_all('/\{#([A-Z0-9_.]+)\}/', $prototype_key, $macros)) {
			return null;
		}
		$chosen = array_values(array_intersect($macros[1], ['SNMPINDEX', 'IFINDEX']))[0] ?? $macros[1][0];
		$numeric = in_array($chosen, ['SNMPINDEX', 'IFINDEX'], true);
		$taken = false;
		$pattern = preg_replace_callback('/\\\\\{\\\\?#([A-Z0-9_.]+)\\\\\}/', static function (array $m) use ($chosen, $numeric, &$taken): string {
			if ($m[1] === $chosen && !$taken) {
				$taken = true;

				return $numeric ? '(\d+)' : '(.+?)';
			}

			return '.+?';
		}, preg_quote($prototype_key, '/'));

		return '/^'.$pattern.'$/';
	}

	/**
	 * The interfaces of ONE host that the user can bind a port to (§9.7, 2.99): the same lookup as the graph reading (interfacesOfHosts, §3.2), no second one. An interface with a numeric LLD value has
	 * an `if_index` (SNMP templates); one whose LLD value is a name (Zabbix agent templates, `net.if.in["eth0"]`) has `if_index = null` and the name.
	 *
	 * @return array  list of ['if_index' => int|null, 'name' => string, 'itemids' => [int, ...]]: the interfaces with an index first (by index), then those found by name (by name)
	 */
	private static function hostInterfaces(string $hostid): array {
		$by_index = [];
		$by_name = [];
		foreach (self::interfacesOfHosts([$hostid])[$hostid] ?? [] as $interface) {
			$entry = ['if_index' => $interface['if_index'], 'name' => $interface['name'], 'itemids' => $interface['itemids']];
			if ($interface['if_index'] !== null) {
				$by_index[$interface['if_index']] = $entry;
			}
			else {
				$by_name[] = $entry;      // (a list: two interfaces can have the same name, and then none is found by it)
			}
		}
		ksort($by_index);
		usort($by_name, static fn (array $a, array $b): int => strnatcasecmp($a['name'], $b['name']));

		return array_merge(array_values($by_index), $by_name);
	}

	/** The interface of the list with this index, or null. */
	private static function interfaceByIndex(array $interfaces, int $if_index): ?array {
		foreach ($interfaces as $interface) {
			if ($interface['if_index'] === $if_index) {
				return $interface;
			}
		}

		return null;
	}

	/** Name cleaning (FR-B D-39): no trailing NUL, no white space at the start or the end, one pair of surrounding quotes; '' = no value. */
	private static function cleanName(?string $name): string {
		$name = trim(rtrim((string) $name, "\0"));
		if (strlen($name) >= 2 && $name[0] === $name[-1] && ($name[0] === '"' || $name[0] === "'")) {
			$name = trim(substr($name, 1, -1));
		}

		return $name;
	}

	/**
	 * The items of ports (§3.2, 2.93): not stored, found when the graph is read, for a real port of the Device of a reporter's host and for a bound manual port. ONE lookup for all the ports (and all the
	 * hosts) of the request: one query for the ports with their hosts, one SQL query over items and item_discovery (interfacesOfHosts). The interface of a port is found
	 *  1. by `if_index`: the interface whose LLD value is the index;
	 *  2. else by name: the interface whose name equals the port name (for a bound manual port: its `interface_name`) after cleaning, when exactly one interface of the host has this name. Only
	 *     an interface whose LLD value is the name (the key carries the name: Zabbix agent templates, `net.if.in["eth0"]`) is found this way. An interface with a numeric LLD value is the
	 *     interface of its index: after an ifIndex change its items poll the old index, so a port that has a new index shows no items until the rule is polled again (§18; found in S12);
	 *  3. else the port has no items.
	 * `reason` says why a port has none: `not_monitored` (the Device has no host), `not_reported` (a pseudo port), `no_binding` (a manual port that is not bound), `no_items` (the host has no interface items:
	 * no interface discovery template), `no_match` (the host has interfaces, none is this port's).
	 *
	 * @param string[] $port_ids
	 *
	 * @return array  [port id => ['itemids' => [string, ...], 'reason' => string|null]]
	 */
	public static function portItems(array $port_ids): array {
		$port_ids = array_values(array_unique(array_map('strval', array_filter($port_ids, static fn ($id): bool => $id !== null))));
		if (!$port_ids) {
			return [];
		}
		$rows = [];
		$result = DBselect(
			'SELECT p.id,p.attrs,h.host_ref FROM topo_nodes p'.
			' LEFT JOIN topo_nodes d ON d.id=p.device_id'.
			' LEFT JOIN topo_nodes h ON h.id=d.represented_by_node_id AND h.type='.zbx_dbstr('host').
			' WHERE p.type='.zbx_dbstr('port').' AND '.dbConditionId('p.id', $port_ids)
		);
		while ($row = DBfetch($result, false)) {      // (without the conversion of NULL to '0': no host is NULL)
			$rows[] = $row;
		}
		$interfaces = self::interfacesOfHosts(array_filter(array_column($rows, 'host_ref'), static fn ($id): bool => $id !== null), false);
		$result = [];
		$by_name = [];      // the ports that no interface has the index of: found by the name below (the names are read for their hosts only)
		foreach ($rows as $row) {
			$attrs = self::attrs($row);
			$manual = ($attrs['source'] ?? null) === 'manual';
			$name = self::cleanName($manual ? ($attrs['interface_name'] ?? null) : ($attrs['name'] ?? null));
			$id = (string) $row['id'];
			$result[$id] = ['itemids' => [], 'reason' => null];
			if ($row['host_ref'] === null) {
				$result[$id]['reason'] = 'not_monitored';
			}
			elseif (!empty($attrs['pseudo'])) {
				$result[$id]['reason'] = 'not_reported';
			}
			elseif ($manual && !isset($attrs['if_index']) && $name === '') {
				$result[$id]['reason'] = 'no_binding';
			}
			elseif (empty($interfaces[$row['host_ref']])) {
				$result[$id]['reason'] = 'no_items';
			}
			elseif (isset($attrs['if_index']) && isset($interfaces[$row['host_ref']][(string) (int) $attrs['if_index']])) {
				$result[$id]['itemids'] = array_map('strval', $interfaces[$row['host_ref']][(string) (int) $attrs['if_index']]['itemids']);
			}
			elseif ($name !== '' && array_filter($interfaces[$row['host_ref']], static fn (array $interface): bool => $interface['if_index'] === null)) {
				$by_name[$id] = [$row['host_ref'], $name];
			}
			else {
				$result[$id]['reason'] = 'no_match';
			}
		}
		if ($by_name) {
			self::interfaceNames($interfaces, array_values(array_unique(array_column($by_name, 0))));
			foreach ($by_name as $id => [$hostid, $name]) {
				$same = array_values(array_filter($interfaces[$hostid], static fn (array $interface): bool => $interface['if_index'] === null && self::cleanName($interface['name']) === $name));
				if (count($same) === 1) {
					$result[$id]['itemids'] = array_map('strval', $same[0]['itemids']);
				}
				else {
					$result[$id]['reason'] = 'no_match';
				}
			}
		}

		return $result;
	}

	/** The Ports of a Device that have an `if_index`: [if_index => ['id' => id, 'name' => name]]. */
	private static function indexedPortsOf(string $device_id): array {
		$ports = [];
		$result = DBselect('SELECT id,attrs FROM topo_nodes WHERE type='.zbx_dbstr('port').' AND device_id='.zbx_dbstr($device_id));
		while ($row = DBfetch($result, false)) {
			$attrs = self::attrs($row);
			if (isset($attrs['if_index'])) {
				$ports[(int) $attrs['if_index']] = ['id' => (string) $row['id'], 'name' => (string) ($attrs['name'] ?? '')];
			}
		}

		return $ports;
	}

	/** The Ports of a Device that have the interface by name (2.99): a manual Port bound by name (`interface_name`, no `if_index`), or a Port without an index whose name is the name of the interface: [name => ['id' => id, 'name' => port name]]. */
	private static function namedPortsOf(string $device_id): array {
		$ports = [];
		$result = DBselect('SELECT id,attrs FROM topo_nodes WHERE type='.zbx_dbstr('port').' AND device_id='.zbx_dbstr($device_id));
		while ($row = DBfetch($result, false)) {
			$attrs = self::attrs($row);
			if (isset($attrs['if_index'])) {
				continue;
			}
			$bound = (($attrs['source'] ?? null) === 'manual') ? self::cleanName($attrs['interface_name'] ?? null) : self::cleanName($attrs['name'] ?? null);
			if ($bound !== '') {
				$ports[$bound] ??= ['id' => (string) $row['id'], 'name' => (string) ($attrs['name'] ?? '')];
			}
		}

		return $ports;
	}

	/** GET /topo/ports/{id}/interfaces (§9.7, §11): the interfaces of the host of the port's Device, with the Port of the Device that has the interface (by the index, or by the name), if any. */
	public static function portInterfaces(string $port_id): array {
		[$row, $hostid] = self::bindablePort($port_id);
		$by_index = self::indexedPortsOf((string) $row['device_id']);
		$by_name = self::namedPortsOf((string) $row['device_id']);

		return array_values(array_map(static fn (array $interface): array => $interface + ['port' => $interface['if_index'] !== null
			? ($by_index[$interface['if_index']] ?? null) : ($by_name[self::cleanName($interface['name'])] ?? null)], self::hostInterfaces($hostid)));
	}

	/**
	 * POST /topo/ports/{id}/bind {if_index} or {name} (§9.7, 2.99): the port gets the index and the name of that interface, or, for an interface that is found by name (Zabbix agent templates), the name only
	 * (`interface_name`, no `if_index`); the items are found when the graph is read (§3.2) and the port keeps its name and `source = manual`. Refused: an interface that another Port of the Device already has,
	 * by index or by name ("<port> already has this interface"), an index or a name that the host has no interface for (or none that the user may read), a name that more than one interface of the host has
	 * (§3.2 item 3 would find no items), a discovered port, a Device without an association.
	 */
	public static function bindPort(string $port_id, ?int $if_index, string $userid, ?string $name = null): array {
		[$row, $hostid] = self::bindablePort($port_id);
		$attrs = self::attrs($row);
		if ($if_index === null) {
			$clean = self::cleanName($name);
			if ($clean === '') {
				throw new Exception('Give the index or the name of the interface.');
			}
			$same = array_values(array_filter(self::hostInterfaces($hostid), static fn (array $interface): bool => $interface['if_index'] === null && self::cleanName($interface['name']) === $clean));
			if (!$same) {
				throw new Exception('The host has no interface with the name '.$clean.' (or you may not read its items).');
			}
			if (count($same) > 1) {
				throw new Exception('More than one interface of the host has the name '.$clean.': it cannot be bound by name.');
			}
			$holder = self::namedPortsOf((string) $row['device_id'])[$clean] ?? null;
			if ($holder !== null && $holder['id'] !== (string) $port_id) {
				throw new Exception('The port '.$holder['name'].' already has this interface.');
			}
			unset($attrs['if_index']);
			$attrs['interface_name'] = $same[0]['name'];
			DBexecute('UPDATE topo_nodes SET attrs='.zbx_dbstr(json_encode($attrs)).',updated_at='.time().' WHERE id='.zbx_dbstr($port_id));
			self::touchManualDevice((string) $row['device_id'], $userid);

			return ['id' => $port_id, 'if_index' => null, 'interface' => $same[0]['name']];
		}
		$holder = self::indexedPortsOf((string) $row['device_id'])[$if_index] ?? null;
		if ($holder !== null && $holder['id'] !== (string) $port_id) {
			throw new Exception('The port '.$holder['name'].' already has this interface.');
		}
		$interface = self::interfaceByIndex(self::hostInterfaces($hostid), $if_index);
		if ($interface === null) {
			throw new Exception('The host has no interface with the index '.$if_index.' (or you may not read its items).');
		}
		$attrs['if_index'] = $interface['if_index'];
		$attrs['interface_name'] = $interface['name'];
		DBexecute('UPDATE topo_nodes SET attrs='.zbx_dbstr(json_encode($attrs)).',updated_at='.time().' WHERE id='.zbx_dbstr($port_id));
		self::touchManualDevice((string) $row['device_id'], $userid);

		return ['id' => $port_id, 'if_index' => $interface['if_index'], 'interface' => $interface['name']];
	}

	/** POST /topo/ports/{id}/unbind (§9.7): clears `if_index` and the interface name; the port stays. */
	public static function unbindPort(string $port_id, string $userid): array {
		[$row] = self::bindablePort($port_id);
		$attrs = self::attrs($row);
		unset($attrs['if_index'], $attrs['interface_name']);
		DBexecute('UPDATE topo_nodes SET attrs='.zbx_dbstr(json_encode($attrs)).',updated_at='.time().' WHERE id='.zbx_dbstr($port_id));
		self::touchManualDevice((string) $row['device_id'], $userid);

		return ['id' => $port_id];
	}

	/** Who changed the manual Device last (the prototype has no audit store yet, §18). */
	private static function touchManualDevice(string $device_id, string $userid): void {
		$row = self::getDeviceRow($device_id);
		$attrs = self::attrs($row);
		$attrs['updated_by'] = $userid;
		$attrs['updated_at'] = time();
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

	/**
	 * Not the same device for a whole group (topology-1-model.md §6.7.5, 2.48; FR-C O-12): every pair of the Devices of the group, as dismissDuplicate() for each pair, in one
	 * action. A group of n Devices has up to n(n-1)/2 pairs. The revocation stays per pair (revokeDismissal()).
	 *
	 * @param string[] $ids  the Devices of the group
	 * @return array         ['pairs' => the number of pairs dismissed]
	 */
	public static function dismissGroup(array $ids, string $username): array {
		$ids = array_values(array_unique(array_map('strval', $ids)));
		if (count($ids) < 2) {
			throw new Exception('Choose a group of at least two Devices.');
		}
		sort($ids, SORT_NUMERIC);
		foreach ($ids as $id) {
			self::getDeviceRow($id);
		}

		DBstart();

		try {
			$pairs = 0;
			for ($i = 0; $i < count($ids); $i++) {
				for ($j = $i + 1; $j < count($ids); $j++) {
					self::dismissDuplicate($ids[$i], $ids[$j], $username);
					$pairs++;
				}
			}
		}
		catch (Throwable $exception) {
			DBend(false);

			throw $exception;
		}

		DBend(true);

		return ['pairs' => $pairs];
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

	const OBSERVATION_OUTCOMES = ['applied', 'device_only', 'shadowed', 'conflict', 'ambiguous', 'old_snapshot', 'port_limit'];

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
			int $limit = 500, bool $contradicted_only = false, ?string $precision = null, ?string $reporter_hostid = null,
			?string $port_id = null): array {
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
			// Only observations of a rule that still has a usable snapshot: enabled rule on an enabled host, snapshot of its current topology type
			// (the ingest removes the others at the next full run; this keeps them out of sight until then).
			' JOIN items i ON i.itemid=o.itemid AND i.status='.ITEM_STATUS_ACTIVE.
			' JOIN topo_lld_snapshot snapshot ON snapshot.itemid=o.itemid AND snapshot.topology_type=i.topology_type'.
			' JOIN hosts h ON h.hostid=i.hostid AND h.status='.HOST_STATUS_MONITORED.
			// an observation `old_snapshot` has no Port when its local port is not resolved (model §7.2, 2.60)
			' LEFT JOIN topo_nodes port ON port.id=o.local_port_id'.
			' LEFT JOIN topo_nodes remote ON remote.id=o.device_id'.
			' WHERE '.dbConditionString('o.outcome', $outcomes).
				' AND '.dbConditionId('h.hostid', $visible_hostids);
		if ($device_id !== null) {
			$sql .= ' AND o.device_id='.zbx_dbstr($device_id);
		}
		if ($precision !== null) {
			$sql .= ' AND o.link_precision='.zbx_dbstr($precision);
		}
		// 2.57 (§11, §12.7): the observations of one reporter host, and the observations about one Port: reported on it, or
		// the claim of a link that ends at it (the other end of a link reports it from its own side)
		if ($reporter_hostid !== null) {
			$sql .= ' AND h.hostid='.zbx_dbstr($reporter_hostid);
		}
		if ($port_id !== null) {
			$sql .= ' AND (o.local_port_id='.zbx_dbstr($port_id).' OR o.edge_id IN (SELECT e.id FROM topo_edges e WHERE e.src_id='.
				zbx_dbstr($port_id).' OR e.dst_id='.zbx_dbstr($port_id).'))';
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
		$result = $contradicted_only ? array_slice($result, 0, $limit) : $result;
		self::addObservationReasons($result);

		return $result;
	}

	/** The name of a Device for a filter chip of the last run view: its sysname, else its chassis id, else the id. */
	public static function deviceLabel(string $device_id): string {
		$row = DBfetch(DBselect('SELECT attrs FROM topo_nodes WHERE type='.zbx_dbstr('device').' AND id='.zbx_dbstr($device_id)), false);
		$attrs = $row ? (json_decode((string) $row['attrs'], true) ?: []) : [];

		return (string) (($attrs['sysname'] ?? '') !== '' ? $attrs['sysname'] : ($attrs['chassis_id'] ?? '#'.$device_id));
	}

	/** The name of a Port with its Device, for a filter chip of the last run view. */
	public static function portLabel(string $port_id): string {
		$row = DBfetch(DBselect('SELECT p.attrs,p.device_id FROM topo_nodes p WHERE p.type='.zbx_dbstr('port').' AND p.id='.zbx_dbstr($port_id)), false);
		if (!$row) {
			return '#'.$port_id;
		}
		$attrs = json_decode((string) $row['attrs'], true) ?: [];

		return ($row['device_id'] !== null ? self::deviceLabel((string) $row['device_id']).' ' : '').(string) ($attrs['name'] ?? '#'.$port_id);
	}

	/**
	 * The reason of an observation for the list of the last run (model §12.7), one more field `reason` of each row: for `conflict` and `shadowed` the blocking link
	 * (its two ends as text), for `ambiguous` the other claims on the same local port (the Device name, else the claim key). Everything is text of the neighbor:
	 * escaped by whoever renders it.
	 */
	private static function addObservationReasons(array &$rows): void {
		$edge_ids = [];
		$ports = [];
		foreach ($rows as $row) {
			if (in_array($row['outcome'], ['conflict', 'shadowed'], true) && $row['edge_id'] !== null) {
				$edge_ids[] = $row['edge_id'];
			}
			if ($row['outcome'] === 'ambiguous' && $row['local_port']['id'] !== null) {
				$ports[] = $row['local_port']['id'];
			}
		}
		$ends = [];
		if ($edge_ids) {
			$edges = [];
			$node_ids = [];
			$cursor = DBselect('SELECT id,type,src_id,dst_id FROM topo_edges WHERE '.dbConditionId('id', array_values(array_unique($edge_ids))));
			while ($edge = DBfetch($cursor, false)) {
				$edges[$edge['id']] = $edge;
				$node_ids[] = $edge['src_id'];
				$node_ids[] = $edge['dst_id'];
			}
			$nodes = [];
			if ($node_ids) {
				$cursor = DBselect('SELECT id,type,device_id,attrs FROM topo_nodes WHERE '.dbConditionId('id', array_values(array_unique($node_ids))));
				while ($node = DBfetch($cursor, false)) {
					$nodes[$node['id']] = $node;
				}
				$device_ids = array_filter(array_unique(array_column($nodes, 'device_id')));
				if ($device_ids) {
					$cursor = DBselect('SELECT id,type,device_id,attrs FROM topo_nodes WHERE '.dbConditionId('id', array_values($device_ids)));
					while ($node = DBfetch($cursor, false)) {
						$nodes[$node['id']] = $node;
					}
				}
			}
			$label = static function ($node_id) use ($nodes): string {
				$node = $nodes[$node_id] ?? null;
				if ($node === null) {
					return '?';
				}
				$attrs = json_decode((string) $node['attrs'], true) ?: [];
				if ($node['type'] === 'port') {
					$device = $nodes[$node['device_id']] ?? null;
					$device_attrs = $device !== null ? (json_decode((string) $device['attrs'], true) ?: []) : [];

					return (string) ($device_attrs['sysname'] ?? $device_attrs['chassis_id'] ?? '?').' '.(string) ($attrs['name'] ?? '?');
				}

				return (string) ($attrs['sysname'] ?? $attrs['chassis_id'] ?? '?');
			};
			foreach ($edges as $edge_id => $edge) {
				$ends[$edge_id] = [$label($edge['src_id']), $label($edge['dst_id'])];
			}
		}
		$others = [];
		if ($ports) {
			$cursor = DBselect('SELECT o.id,o.local_port_id,o.remote_key,o.remote_attrs,remote.attrs AS device_attrs FROM topo_observations o'.
				' LEFT JOIN topo_nodes remote ON remote.id=o.device_id WHERE '.dbConditionId('o.local_port_id', array_values(array_unique($ports))).
					' AND o.outcome='.zbx_dbstr('ambiguous'));
			while ($other = DBfetch($cursor, false)) {
				$device_attrs = json_decode((string) $other['device_attrs'], true) ?: [];
				$others[$other['local_port_id']][$other['id']] = (string) ($device_attrs['sysname'] ?? $other['remote_key']);
			}
		}
		foreach ($rows as &$row) {
			$row['reason'] = null;
			if (in_array($row['outcome'], ['conflict', 'shadowed'], true) && $row['edge_id'] !== null && isset($ends[$row['edge_id']])) {
				$row['reason'] = ['type' => 'blocking_link', 'edge_id' => $row['edge_id'], 'ends' => $ends[$row['edge_id']]];
			}
			elseif ($row['outcome'] === 'ambiguous') {
				$row['reason'] = ['type' => 'other_neighbors',
					'neighbors' => array_values(array_diff_key($others[$row['local_port']['id']] ?? [], [$row['id'] => true]))
				];
			}
		}
		unset($row);
	}

	// Resolves the highest-severity *active* trigger (value=TRIGGER_VALUE_TRUE, i.e. currently in problem
	// state) attached to any of the items of the given ports (§3.2, found by portItems()) — oper_status and trigger severity are
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

		// The triggers of these items (as the user may read them), then THEIR PROBLEMS: Problem.get applies the tag-based permissions of the user
		// group too (access spec §3.5), a trigger lookup alone would not. The severity is the problem's own.
		$items_of_trigger = [];
		foreach (API::Trigger()->get([
			'output' => ['triggerid'],
			'itemids' => $all,
			'selectItems' => ['itemid'],
			'filter' => ['value' => TRIGGER_VALUE_TRUE]
		]) as $trigger) {
			$items_of_trigger[$trigger['triggerid']] = array_column($trigger['items'], 'itemid');
		}
		if (!$items_of_trigger) {
			return [];
		}

		$by_item = [];
		foreach (API::Problem()->get([
			'output' => ['objectid', 'severity'],
			'source' => EVENT_SOURCE_TRIGGERS,
			'object' => EVENT_OBJECT_TRIGGER,
			'objectids' => array_keys($items_of_trigger)
		]) as $problem) {
			foreach ($items_of_trigger[$problem['objectid']] ?? [] as $itemid) {
				$by_item[$itemid] = max($by_item[$itemid] ?? 0, (int) $problem['severity']);
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

	/**
	 * Load of ports (topology-1-model.md §4.8), display only: bits in, bits out, speed and max(in, out) / speed in %, from the items of the port with
	 * the keys net.if.in[...], net.if.out[...], net.if.speed[...] (the keys of the standard SNMP interface template, verified in "Network Generic Device by
	 * SNMP"). ONE item lookup for all the ports. A port without a speed, or with no value newer than LOAD_RECENT seconds, has no load.
	 *
	 * @param array $itemids_by_port  port id => item ids
	 *
	 * @return array  port id => ['in', 'out', 'speed', 'percent', 'items' => ['in' => itemid, 'out' => itemid, 'speed' => itemid]]
	 */
	private static function getPortLoads(array $itemids_by_port): array {
		$all = array_values(array_unique(array_merge(...array_values($itemids_by_port ?: [[]]))));
		if (!$all) {
			return [];
		}

		// 2.88 (§4.8): the items as the user may read them (without the last values), then the last value of all of them in one query per history table (CTopologyLoad); `item.get` with `lastvalue` read
		// the last two values of each item, one query for each item, in the whole HISTORY_PERIOD
		$items = [];
		foreach (API::Item()->get(['output' => ['itemid', 'hostid', 'key_', 'value_type', 'type', 'delay', 'master_itemid'], 'itemids' => $all]) as $item) {
			$items[$item['itemid']] = $item;
		}
		foreach (CTopologyLoad::lastValues($items) as $itemid => $last) {
			$items[$itemid]['lastvalue'] = $last['value'];
			$items[$itemid]['lastclock'] = $last['clock'];
		}

		$result = [];
		foreach ($itemids_by_port as $port_id => $itemids) {
			$load = ['in' => null, 'out' => null, 'speed' => null, 'percent' => null, 'items' => ['in' => null, 'out' => null, 'speed' => null]];
			foreach ($itemids as $itemid) {
				$item = $items[$itemid] ?? null;
				if ($item === null) {
					continue;
				}
				// task 118: the bytes keys of the SNMP template and of the Zabbix agent template (a mode other than bytes is not the load), the speed of either template
				$name = self::loadKind($item['key_']);
				if ($name !== null) {
					$load['items'][$name] = $item['itemid'];
					if (isset($item['lastclock'], $item['lastvalue']) && (int) $item['lastclock'] >= time() - self::LOAD_RECENT && is_numeric($item['lastvalue'])) {
						$load[$name] = (float) $item['lastvalue'];
					}
				}
			}
			if ($load['items'] === ['in' => null, 'out' => null, 'speed' => null]) {
				continue;
			}
			// task 119 section 5: an interface with no speed (`veth`, `docker0`, `lo` give -1 in /sys/class/net/<name>/speed) has no speed and no percent, not a negative speed
			$load['speed'] = CTopologySpeed::known($load['speed']);
			$load['percent'] = CTopologySpeed::percent($load['in'], $load['out'], $load['speed']);
			$result[$port_id] = $load;
		}

		return $result;
	}

	/**
	 * What an item of an interface is for the load (§4.8): 'in' and 'out' (`net.if.in[...]`, `net.if.out[...]`, bytes only), 'speed' (`net.if.speed[...]` of the SNMP template, `vfs.file.contents["/sys/class/net/<name>/speed"]`
	 * of the Zabbix agent template, in bit/s), else null.
	 */
	private static function loadKind(string $key): ?string {
		foreach (['in' => 'net.if.in[', 'out' => 'net.if.out['] as $kind => $prefix) {
			if (str_starts_with($key, $prefix)) {
				return self::isBytesKey($key) ? $kind : null;
			}
		}
		if (str_starts_with($key, 'net.if.speed[') || preg_match('#^vfs\.file\.contents\["/sys/class/net/[^"]+/speed"\]$#', $key)) {
			return 'speed';
		}

		return null;
	}

	/** An item of the load (§4.8): `net.if.in` and `net.if.out` count bytes. A Zabbix agent key has a mode as its second parameter (`net.if.in["eth0",errors]`): only no mode or `bytes` is the load. */
	private static function isBytesKey(string $key): bool {
		if (!preg_match('/^net\.if\.(?:in|out)\[(.*)\]$/', $key, $match)) {
			return true;
		}
		$params = str_getcsv($match[1], ',', '"', '');

		return count($params) < 2 || strtolower(trim($params[1])) === 'bytes';
	}

	/**
	 * The speed of a Port in bit/s (topology-1-model.md §4.8 "Speed unit", 2.89). The port attribute `speed` is the LLD macro {#IFSPEED}, which is ifHighSpeed in Mbit/s
	 * (template_topology_by_snmp_js.yaml, preproc_topology.c); the page formats bit/s. A value that is not a number stays as it is.
	 *
	 * @param mixed $value
	 *
	 * @return mixed
	 */
	public static function speedBps($value) {
		return is_numeric($value) ? (int) round((float) $value * 1000000) : $value;
	}

	// Zabbix has no proxyid column on problem/trigger/item — a Proxy's own health problems are only reachable
	// via the well-known internal item key convention 'zabbix.proxy.<metric>[<proxy name>]' (e.g.
	// zabbix.proxy.last_seen[Riga proxy]), the same keys the stock "Zabbix proxy health" template uses,
	// interpolating the proxy's exact name as the key parameter. This is a real, well-defined Zabbix
	// convention, not a fuzzy/weak-key guess — but it only finds anything if some host in the instance has
	// actually been set up to monitor this specific proxy via that convention; there's no structural guarantee
	// one exists, same opportunistic caveat as the inventory MAC match in ingest.php.
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
	/**
	 * What the speed mismatch (V-25) and the port alias (V-14) need of the attrs of a Port. A Port of a reporter is not a pseudo Port (made from an advertisement) and not a manual Port. The alias
	 * is text from the network (the page escapes it).
	 */
	private static function portFacts(array $attrs): array {
		return [
			'if_type' => $attrs['if_type'] ?? null,
			'reporter' => empty($attrs['pseudo']) && ($attrs['source'] ?? null) !== 'manual',
			'alias' => ($attrs['alias'] ?? '') !== '' ? (string) $attrs['alias'] : null,
			'speed' => self::speedBps($attrs['speed'] ?? null)
		];
	}

	private static function getPortDetails(array $portids): array {
		$portids = array_values(array_unique(array_filter($portids, static fn($id) => $id !== null)));
		if (!$portids) {
			return [];
		}

		$details = [];
		$result = DBselect('SELECT id,attrs,lag_id FROM topo_nodes WHERE '.dbConditionId('id', $portids).
			' AND type='.zbx_dbstr('port'));
		$rows = DBfetchArray($result);
		$lag_names = [];
		$lag_ids = array_values(array_unique(array_filter(array_column($rows, 'lag_id'), static fn ($id) => $id !== null && (string) $id !== '0')));
		if ($lag_ids) {
			foreach (DBfetchArray(DBselect('SELECT id,attrs FROM topo_nodes WHERE '.dbConditionId('id', $lag_ids).' AND type='.zbx_dbstr('port'))) as $lag) {
				$lag_names[$lag['id']] = self::attrs($lag)['name'] ?? null;
			}
		}
		foreach ($rows as $row) {
			$attrs = self::attrs($row);
			$details[$row['id']] = [
				// 2.72 (§12.3 "LAG bundle", FR-C V-6): the LAG Port that this port is a member of, and its name, when known (data from the network: the page escapes it)
				'lag_id' => $row['lag_id'] === null || (string) $row['lag_id'] === '0' ? null : (string) $row['lag_id'],
				'lag_name' => $row['lag_id'] === null || (string) $row['lag_id'] === '0' ? null : ($lag_names[$row['lag_id']] ?? null),
				'name' => $attrs['name'] ?? null,
				'oper_status' => $attrs['oper_status'] ?? null,
				'admin_status' => $attrs['admin_status'] ?? null,
			] + self::portFacts($attrs);
		}

		return $details;
	}
}
