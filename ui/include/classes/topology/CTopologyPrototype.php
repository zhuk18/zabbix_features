<?php

/**
 * CTopologyPrototype — tags-only topology model (topology-tags-model-spec.md).
 *
 * There is no persistent topology database. Every method here reads or writes Zabbix Host tags
 * directly via the Zabbix API and derives the graph fresh on every call. A Host is topology-relevant
 * only when it carries a `topology.chassis_id` (it self-reports as a discovery reporter) or a
 * `topology.identity` (it has been manually /promote'd to represent an observed device) tag — every
 * other Zabbix host is invisible to this model entirely (§5's lazy-relevance principle, carried over
 * from the point the previous DB-backed prototype had already reached).
 *
 * Node ids returned to callers are opaque strings, never raw integers: `host:<hostid>` for a
 * Zabbix-Host-backed node, `unmanaged:<chassis_id>` for a derived node with no Host behind it yet.
 * `chassis_id` is untrusted, LLDP/CDP-sourced input (§23) — it is never interpolated into anything
 * but a JSON string value or a URL-encoded query parameter, and the frontend must escape it at
 * render time the same as every other untrusted field.
 */
class CTopologyPrototype {

	private const TAG_CHASSIS_ID = 'topology.chassis_id';
	private const TAG_TYPE = 'topology.type';
	private const TAG_IDENTITY = 'topology.identity';
	private const PORT_TAG_RE = '/^topology\.port\.(\d+)\.([a-z_]+)$/';
	private const NEIGHBOR_TAG_RE = '/^topology\.neighbor\.(\d+)\.([a-z_]+)$/';

	private static function hostNodeId(string $hostid): string {
		return 'host:'.$hostid;
	}

	private static function unmanagedNodeId(string $chassis_id): string {
		return 'unmanaged:'.$chassis_id;
	}

	/**
	 * @return array{0: 'host'|'unmanaged', 1: string}  the node type and its raw hostid/chassis_id.
	 */
	private static function parseNodeId(string $id): array {
		if (str_starts_with($id, 'host:')) {
			return ['host', substr($id, 5)];
		}
		if (str_starts_with($id, 'unmanaged:')) {
			return ['unmanaged', substr($id, 10)];
		}
		return ['', ''];
	}

	/**
	 * Every topology-relevant Host (§2/§7.1: carries `topology.chassis_id` and/or
	 * `topology.identity`), fully parsed. API::Host()->get() already enforces the caller's real
	 * Zabbix permissions (§ — same floor every other page in this instance already has), so there is
	 * no separate ACL check layered on top here, unlike the old DB-backed prototype's raw-table joins.
	 *
	 * @param array|null $hostids  restrict to these Zabbix hostids; null = every visible host.
	 *
	 * @return array hostid => ['hostid','name','status','maintenance_status','chassis_id','identity',
	 *               'device_type','ports' => [ifIndex => [prop => value]],
	 *               'neighbors' => [ifIndex => [prop => value]]]
	 */
	private static function getTopologyHosts(?array $hostids = null): array {
		$params = [
			'output' => ['hostid', 'name', 'status', 'maintenance_status'],
			'selectTags' => 'extend'
		];
		if ($hostids !== null) {
			$params['hostids'] = $hostids;
		}

		$result = [];
		foreach (API::Host()->get($params) as $host) {
			$parsed = self::parseTopologyTags($host['tags']);
			if ($parsed['chassis_id'] === null && $parsed['identity'] === null) {
				continue;
			}
			$result[$host['hostid']] = $parsed + [
				'hostid' => $host['hostid'], 'name' => $host['name'],
				'status' => $host['status'], 'maintenance_status' => $host['maintenance_status']
			];
		}

		return $result;
	}

	private static function parseTopologyTags(array $tags): array {
		$chassis_id = null;
		$identity = null;
		$device_type = null;
		$ports = [];
		$neighbors = [];

		foreach ($tags as $tag) {
			$name = $tag['tag'];
			$value = $tag['value'];

			if ($name === self::TAG_CHASSIS_ID) {
				$chassis_id = $value !== '' ? $value : null;
			}
			elseif ($name === self::TAG_IDENTITY) {
				$identity = $value !== '' ? $value : null;
			}
			elseif ($name === self::TAG_TYPE) {
				$device_type = $value !== '' ? $value : null;
			}
			elseif (preg_match(self::PORT_TAG_RE, $name, $matches)) {
				$ports[(int) $matches[1]][$matches[2]] = $value;
			}
			elseif (preg_match(self::NEIGHBOR_TAG_RE, $name, $matches)) {
				$neighbors[(int) $matches[1]][$matches[2]] = $value;
			}
		}

		return [
			'chassis_id' => $chassis_id, 'identity' => $identity, 'device_type' => $device_type,
			'ports' => $ports, 'neighbors' => $neighbors
		];
	}

	/**
	 * identity string => hostid, for both `topology.chassis_id` and `topology.identity` values —
	 * §10's neighbor resolution matches against either. Where a Host carries both tags with
	 * different values (a reporter that has ALSO been manually /promote'd under a distinct claimed
	 * identity — an edge case the spec doesn't explicitly rule out), the explicit `topology.identity`
	 * wins for that Host's own displayed identity (§21), but its `chassis_id` is still indexed here
	 * so neighbors still reporting the old chassis_id keep resolving to this same Host.
	 */
	private static function buildIdentityIndex(array $topology_hosts): array {
		$index = [];
		foreach ($topology_hosts as $hostid => $host) {
			if ($host['chassis_id'] !== null) {
				$index[$host['chassis_id']] ??= $hostid;
			}
		}
		foreach ($topology_hosts as $hostid => $host) {
			if ($host['identity'] !== null) {
				$index[$host['identity']] = $hostid;
			}
		}

		return $index;
	}

	private static function effectiveIdentity(array $host): ?string {
		return $host['identity'] ?? $host['chassis_id'];
	}

	/**
	 * @param array|null $node_ids  restrict to these node id strings; null = unrestricted.
	 */
	public static function getDevices(?array $node_ids = null): array {
		$topology_hosts = self::getTopologyHosts();
		$identity_index = self::buildIdentityIndex($topology_hosts);

		$nodes = [];
		// chassis_id => best-known display name, collected while walking every reporter's neighbor
		// tags below — §11: an unmanaged node is never persisted, it only exists for as long as some
		// currently-visible neighbor observation still points at it.
		$unmanaged = [];

		foreach ($topology_hosts as $hostid => $host) {
			$node = [
				'id' => self::hostNodeId($hostid), 'type' => 'host', 'name' => $host['name'],
				'hostid' => $hostid,
				'monitoring_state' => $host['status'],
				'disabled' => ((int) $host['status']) === HOST_STATUS_NOT_MONITORED,
				'maintenance' => ((int) $host['maintenance_status']) === HOST_MAINTENANCE_STATUS_ON,
				'chassis_id' => $host['chassis_id'], 'identity' => $host['identity'],
				'device_type' => $host['device_type'],
				// §21: a promoted Host is always "represented" (it IS the node, not a separate
				// entity pointing at one) — kept as a field mainly so the frontend's promote/depromote
				// button logic can stay shaped the same way it already is.
				'represented' => $host['identity'] !== null
			];
			$node += self::describeSeverity($node['disabled'] ? null : self::getMaxActiveSeverityForHost($hostid));
			$nodes[] = $node;

			foreach ($host['neighbors'] as $neighbor) {
				$target_chassis_id = $neighbor['chassis_id'] ?? null;
				if ($target_chassis_id === null || isset($identity_index[$target_chassis_id])) {
					// No chassis_id reported, or it already resolves to a known Host — nothing
					// unmanaged to derive for this observation.
					continue;
				}
				if (!isset($unmanaged[$target_chassis_id])) {
					$unmanaged[$target_chassis_id] = ['name' => null];
				}
				if ($unmanaged[$target_chassis_id]['name'] === null && !empty($neighbor['name'])) {
					$unmanaged[$target_chassis_id]['name'] = $neighbor['name'];
				}
			}
		}

		foreach ($unmanaged as $chassis_id => $info) {
			$nodes[] = [
				'id' => self::unmanagedNodeId($chassis_id), 'type' => 'device',
				'name' => $info['name'] ?? $chassis_id, 'chassis_id' => $chassis_id,
				'monitoring_state' => null, 'represented' => false
			];
		}

		if ($node_ids !== null) {
			$node_id_set = array_flip($node_ids);
			$nodes = array_values(array_filter($nodes, static fn(array $node): bool => isset($node_id_set[$node['id']])));
		}

		return $nodes;
	}

	/**
	 * §12/§13: a link is derived whenever a neighbor observation points from one topology identity to
	 * another — canonicalized (sorted node id pair) so a reciprocal observation from both sides
	 * collapses to one rendered link instead of two. There is no persistent `physical_link` record
	 * and no staleness (no `last_seen` is ever stored — the tags themselves only ever hold the CURRENT
	 * snapshot, §15). A manual link/unlink (see linkPort()/unlinkPort() below) writes/removes the same
	 * `topology.neighbor.*` tags discovery would — there is no separate `discovered_via`/provenance
	 * marker distinguishing the two once written (§24 rules out any extra persistence for that), so a
	 * manual link is indistinguishable from a discovered one until the next ingest run overwrites it
	 * (GOTCHAS.md #7).
	 *
	 * @param array|null $node_ids  restrict to relations where BOTH ends are in this set; null =
	 *                              unrestricted.
	 */
	public static function getRelations(?array $node_ids = null): array {
		$topology_hosts = self::getTopologyHosts();
		$identity_index = self::buildIdentityIndex($topology_hosts);

		$pairs = [];
		foreach ($topology_hosts as $hostid => $host) {
			$source_id = self::hostNodeId($hostid);

			foreach ($host['neighbors'] as $if_index => $neighbor) {
				$target_chassis_id = $neighbor['chassis_id'] ?? null;
				if ($target_chassis_id === null) {
					continue;
				}

				$target_id = isset($identity_index[$target_chassis_id])
					? self::hostNodeId($identity_index[$target_chassis_id])
					: self::unmanagedNodeId($target_chassis_id);
				if ($target_id === $source_id) {
					// A malformed/self-referential observation — never render a self-loop.
					continue;
				}

				$source_port = $host['ports'][$if_index]['name'] ?? (string) $if_index;
				$target_port = $neighbor['port'] ?? $neighbor['port_name'] ?? $neighbor['port_id'] ?? null;

				$pair = [$source_id, $target_id];
				sort($pair);
				$key = implode('|', $pair);
				if (isset($pairs[$key])) {
					// Already have this canonical pair from an earlier (or the reciprocal) observation
					// — first one wins, matching §13's "render only one link" requirement exactly.
					continue;
				}

				$source_first = ($pair[0] === $source_id);
				$pairs[$key] = [
					'source' => $pair[0], 'target' => $pair[1], 'type' => 'physical_link',
					'source_port' => $source_first ? $source_port : $target_port,
					'target_port' => $source_first ? $target_port : $source_port
				];
			}
		}

		$relations = array_values($pairs);
		if ($node_ids !== null) {
			$node_id_set = array_flip($node_ids);
			$relations = array_values(array_filter($relations, static fn(array $relation): bool =>
				isset($node_id_set[$relation['source']]) && isset($node_id_set[$relation['target']])));
		}

		return $relations;
	}

	// Flat undirected adjacency over node id strings, for CTopologyHopScope's BFS — same pairs as
	// getRelations(), just without the port-label/type decoration that endpoint doesn't need.
	public static function getAdjacency(): array {
		$pairs = [];
		foreach (self::getRelations() as $relation) {
			$pairs[] = [$relation['source'], $relation['target']];
		}

		return $pairs;
	}

	/**
	 * Resolve a hostgroup/host+hops filter request into a concrete node id scope, or null for
	 * "no filter" (unrestricted).
	 *
	 * @param array  $groupids  Zabbix host group ids (host-group filter mode)
	 * @param string $hostid    Zabbix hostid (host+hops filter mode); '' = not set
	 * @param int    $hops      expansion depth for the focus-host mode, ignored otherwise
	 *
	 * @return array|null node id strings in scope, or null when neither filter is active
	 */
	public static function resolveScope(array $groupids, string $hostid, int $hops): ?array {
		if ($hostid !== '') {
			if (!API::Host()->get(['hostids' => [$hostid], 'output' => []])) {
				return [];
			}

			return CTopologyHopScope::neighborhood([self::hostNodeId($hostid)], $hops, self::getAdjacency());
		}

		if ($groupids) {
			$seed_hostids = array_column(API::Host()->get([
				'output' => ['hostid'],
				'groupids' => $groupids
			]), 'hostid');
			if (!$seed_hostids) {
				return [];
			}

			$seed_ids = array_map([self::class, 'hostNodeId'], $seed_hostids);

			return CTopologyHopScope::neighborhood($seed_ids, PHP_INT_MAX, self::getAdjacency());
		}

		return null;
	}

	// §6's progressive-expansion endpoint: 1-hop neighbors of a given node, resolved fresh from the
	// same relations/nodes the graph view itself reads — no separate traversal machinery needed now
	// that there's no Port-level indirection to walk through (§11: ports are just tag groups, never
	// their own graph nodes).
	public static function getNeighbors(string $id): array {
		$nodes_by_id = array_column(self::getDevices(), null, 'id');
		$neighbors = [];

		foreach (self::getRelations() as $relation) {
			if ($relation['source'] !== $id && $relation['target'] !== $id) {
				continue;
			}

			$other_id = ($relation['source'] === $id) ? $relation['target'] : $relation['source'];
			if (!isset($nodes_by_id[$other_id])) {
				continue;
			}

			$local_port = ($relation['source'] === $id) ? $relation['source_port'] : $relation['target_port'];
			$remote_port = ($relation['source'] === $id) ? $relation['target_port'] : $relation['source_port'];

			$neighbors[] = $nodes_by_id[$other_id] + ['local_port' => $local_port, 'remote_port' => $remote_port];
		}

		return $neighbors;
	}

	// §6: /topo/nodes/{id}/problems — Host-only, same as the previous prototype's scope note: Zabbix
	// has no clean "this problem belongs to this proxy"-equivalent concern here since this model has
	// no Proxy representation at all; an `unmanaged:` id simply has no problems to show (it isn't
	// backed by a monitored Zabbix object).
	public static function getProblems(string $id): array {
		[$node_type, $raw_id] = self::parseNodeId($id);
		if ($node_type !== 'host') {
			return [];
		}

		$problems = [];
		foreach (API::Problem()->get([
			'output' => ['eventid', 'name', 'severity', 'clock'],
			'hostids' => [$raw_id],
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
	 * §8/§21: port drill-down for a node's side panel. An `unmanaged:` node has no ports of its own
	 * (nothing reports FOR it — it only ever appears as the remote end of someone else's neighbor
	 * observation), so it always returns the empty group shape.
	 */
	public static function getPorts(string $id): array {
		$groups = ['connected' => [], 'disconnected' => [], 'port_channel' => [], 'management' => []];

		[$node_type, $hostid] = self::parseNodeId($id);
		if ($node_type !== 'host') {
			return $groups;
		}

		$topology_hosts = self::getTopologyHosts();
		$host = $topology_hosts[$hostid] ?? null;
		if ($host === null) {
			return $groups;
		}
		$identity_index = self::buildIdentityIndex($topology_hosts);

		foreach ($host['ports'] as $if_index => $port) {
			$neighbor = $host['neighbors'][$if_index] ?? null;
			$port_type = $port['type'] ?? 'physical';

			if ($port_type === 'lag') {
				$group = 'port_channel';
			}
			elseif ($port_type === 'mgmt') {
				$group = 'management';
			}
			elseif ($neighbor !== null) {
				$group = 'connected';
			}
			else {
				$group = 'disconnected';
			}

			$connected_to = null;
			$connected_to_id = null;
			if ($neighbor !== null) {
				$target_chassis_id = $neighbor['chassis_id'] ?? null;
				if ($target_chassis_id !== null && isset($identity_index[$target_chassis_id])) {
					$target_hostid = $identity_index[$target_chassis_id];
					$connected_to = $topology_hosts[$target_hostid]['name'] ?? $target_chassis_id;
					$connected_to_id = self::hostNodeId($target_hostid);
				}
				elseif ($target_chassis_id !== null) {
					$connected_to = $neighbor['name'] ?? $target_chassis_id;
					$connected_to_id = self::unmanagedNodeId($target_chassis_id);
				}
			}

			$groups[$group][] = [
				'if_index' => $if_index, 'port' => $port['name'] ?? (string) $if_index,
				'mac' => $port['mac'] ?? null, 'status' => $port['status'] ?? null,
				'speed' => $port['speed'] ?? null,
				'connected_to' => $connected_to, 'connected_to_id' => $connected_to_id
			];
		}

		return $groups;
	}

	/**
	 * §5: manually associate an unmanaged topology identity with an existing Zabbix Host by setting
	 * `topology.identity` on it. Validation, in order (§5's numbered list):
	 *   1. reject if the target Host already carries a DIFFERENT `topology.identity`;
	 *   2. reject if the identity is already explicitly claimed by another Host;
	 *   3. for MVP, reject if the identity isn't currently visible anywhere in the derived topology
	 *      (as some Host's own chassis_id, or as a currently-reported neighbor observation).
	 */
	public static function promote(string $identity, string $hostid): void {
		if ($identity === '') {
			throw new Exception('A topology identity is required.');
		}
		if (!API::Host()->get(['hostids' => [$hostid], 'output' => [], 'limit' => 1])) {
			throw new Exception('The selected host does not exist or is not accessible.');
		}

		$topology_hosts = self::getTopologyHosts();

		if (isset($topology_hosts[$hostid]) && $topology_hosts[$hostid]['identity'] !== null
				&& $topology_hosts[$hostid]['identity'] !== $identity) {
			throw new Exception('This host already represents a different topology identity — depromote it first.');
		}

		foreach ($topology_hosts as $other_hostid => $host) {
			if ($other_hostid !== $hostid && $host['identity'] === $identity) {
				throw new Exception('This topology identity is already associated with another host — depromote it first.');
			}
		}

		$identity_index = self::buildIdentityIndex($topology_hosts);
		$visible = isset($identity_index[$identity]);
		if (!$visible) {
			foreach ($topology_hosts as $host) {
				foreach ($host['neighbors'] as $neighbor) {
					if (($neighbor['chassis_id'] ?? null) === $identity) {
						$visible = true;
						break 2;
					}
				}
			}
		}
		if (!$visible) {
			throw new Exception('This topology identity is not currently visible in the discovered topology.');
		}

		self::setHostTag($hostid, self::TAG_IDENTITY, $identity);
	}

	// §6: removes the explicit `topology.identity` association. If the device is still observed via
	// LLDP/CDP by some reporter, the next graph read simply shows it as an unmanaged node again —
	// nothing to delete or recreate (§6/§11: unmanaged nodes are never persisted in the first place).
	public static function depromote(string $hostid): void {
		self::removeHostTag($hostid, self::TAG_IDENTITY);
	}

	/**
	 * Manually link two ports by writing `topology.neighbor.<if_index>.*` on BOTH sides, the same tag
	 * shape discovery itself writes (§9) — there is no separate manual/discovered marker (§24 rules out
	 * any extra persistence for one), so this is indistinguishable from an LLDP-discovered link once
	 * written, and will be overwritten the next time either host's reporter is ingested (GOTCHAS.md #7).
	 * Writing both sides (rather than just the port the user clicked from) gives a real bidirectional
	 * pair immediately, matching what two reciprocally-reporting reporters would produce (§13) — either
	 * side can then be independently unlinked (unlinkPort() only ever touches one side, per §9's
	 * per-reporter tag ownership).
	 */
	public static function linkPort(string $hostid, int $if_index, string $target_hostid, int $target_if_index): void {
		if ($hostid === $target_hostid) {
			throw new Exception('A port cannot be linked to a port on the same host.');
		}

		$topology_hosts = self::getTopologyHosts([$hostid, $target_hostid]);
		if (!isset($topology_hosts[$hostid]['ports'][$if_index])) {
			throw new Exception('The selected port does not exist.');
		}
		if (!isset($topology_hosts[$target_hostid])) {
			throw new Exception('The target host has no topology data.');
		}
		if (!isset($topology_hosts[$target_hostid]['ports'][$target_if_index])) {
			throw new Exception('The selected target port does not exist.');
		}

		$host = $topology_hosts[$hostid];
		$target_host = $topology_hosts[$target_hostid];
		$identity = self::effectiveIdentity($host);
		$target_identity = self::effectiveIdentity($target_host);
		if ($identity === null) {
			throw new Exception('This host has no topology identity (chassis_id) to link from.');
		}
		if ($target_identity === null) {
			throw new Exception('The target host has no topology identity (chassis_id) to link against.');
		}

		$port_name = $host['ports'][$if_index]['name'] ?? (string) $if_index;
		$target_port_name = $target_host['ports'][$target_if_index]['name'] ?? (string) $target_if_index;

		self::replaceNeighborTags($hostid, $if_index, [
			'chassis_id' => $target_identity, 'port' => $target_port_name, 'name' => $target_host['name']
		]);
		self::replaceNeighborTags($target_hostid, $target_if_index, [
			'chassis_id' => $identity, 'port' => $port_name, 'name' => $host['name']
		]);
	}

	// Removes only THIS host's own `topology.neighbor.<if_index>.*` observation — the reciprocal side
	// (if any) is left untouched, same as an ordinary reporter that simply stops seeing its neighbor on
	// one side (§13: a link never requires a reciprocal observation, so this alone is enough to make
	// the link disappear from this side while the other side's observation, if still present, keeps it
	// visible from there).
	public static function unlinkPort(string $hostid, int $if_index): void {
		self::replaceNeighborTags($hostid, $if_index, null);
	}

	// §6: GET /topo/hosts/search?q= — the host picker for /promote. Thin wrapper over host.get,
	// filtered by name/IP; writes nothing.
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

	// host.update()'s `tags` field is a full REPLACE of every tag on the host (see CHostBase::
	// updateTags()) — fetch the current set, swap out just this one tag by name, and send the whole
	// set back so every OTHER tag (topology.* or not) survives untouched.
	private static function setHostTag(string $hostid, string $tag_name, string $value): void {
		$tags = self::replaceHostTag($hostid, $tag_name, ['tag' => $tag_name, 'value' => $value]);
		// host.update() expects a LIST of host objects, even for a single host — passing a bare
		// associative array silently no-ops (array_column() over it finds no 'hostid' column to
		// extract, so the update proceeds against an empty host list with no error surfaced).
		API::Host()->update([['hostid' => $hostid, 'tags' => $tags]]);
	}

	private static function removeHostTag(string $hostid, string $tag_name): void {
		$tags = self::replaceHostTag($hostid, $tag_name, null);
		API::Host()->update([['hostid' => $hostid, 'tags' => $tags]]);
	}

	// host.get's selectTags also returns 'automatic' (read-only: whether the tag was auto-created,
	// e.g. by LLD) alongside 'tag'/'value' — feeding that straight back into host.update() as input
	// is rejected by validation (silently, as a bare `false` return rather than a thrown exception,
	// at least under this class's test bootstrap — see GOTCHAS.md). Strip every fetched tag down to
	// just 'tag'/'value' before it's ever eligible to be re-sent.
	private static function replaceHostTag(string $hostid, string $tag_name, ?array $new_tag): array {
		$hosts = API::Host()->get(['hostids' => [$hostid], 'output' => [], 'selectTags' => 'extend']);
		$existing = $hosts ? $hosts[0]['tags'] : [];
		$tags = array_values(array_filter(
			array_map(static fn(array $tag): array => ['tag' => $tag['tag'], 'value' => $tag['value']], $existing),
			static fn(array $tag): bool => $tag['tag'] !== $tag_name
		));
		if ($new_tag !== null) {
			$tags[] = $new_tag;
		}

		return $tags;
	}

	// Same read-modify-write shape as replaceHostTag(), but for the whole `topology.neighbor.<if_index>.*`
	// tag group at once, since linkPort()/unlinkPort() write/remove several properties (chassis_id, port,
	// name) as one atomic host.update() call rather than one tag at a time.
	private static function replaceNeighborTags(string $hostid, int $if_index, ?array $neighbor): void {
		$hosts = API::Host()->get(['hostids' => [$hostid], 'output' => [], 'selectTags' => 'extend']);
		$existing = $hosts ? $hosts[0]['tags'] : [];
		$prefix = "topology.neighbor.{$if_index}.";
		$tags = array_values(array_filter(
			array_map(static fn(array $tag): array => ['tag' => $tag['tag'], 'value' => $tag['value']], $existing),
			static fn(array $tag): bool => strncmp($tag['tag'], $prefix, strlen($prefix)) !== 0
		));
		if ($neighbor !== null) {
			foreach ($neighbor as $property => $value) {
				if ($value !== null && $value !== '') {
					$tags[] = ['tag' => $prefix.$property, 'value' => (string) $value];
				}
			}
		}
		API::Host()->update([['hostid' => $hostid, 'tags' => $tags]]);
	}

	private static function describeSeverity(?int $severity): array {
		return [
			'severity' => $severity,
			'severity_name' => $severity !== null ? CSeverityHelper::getName($severity) : null,
			'color' => $severity !== null ? CSeverityHelper::getColor($severity) : null
		];
	}

	// Node-level severity for a Host on the graph itself — the worst of its own active problems, same
	// source as getProblems() but only the max. Pure live Zabbix join, unrelated to topology storage
	// (nothing about §24's "deliberately removed" list applies to this).
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
}
