<?php

/**
 * CTopologyTModel — T-model topology assembler (topology-t-model-prototype-spec.md).
 *
 * Hard constraint 1 (spec §1): no topology tables, no cache, no state outliving one request.
 * Every method here is either a pure Zabbix API read (`*.get`) or, for the §6 write endpoints, a
 * read → modify → write of one host's own tags. `assemble()` is the pure function described in
 * §5 — it is re-run, from scratch, on every call. Do NOT memoize it across requests (constraint 1
 * / §0's "do not add caching" — the cost of uncached assembly is itself one of the things being
 * measured, see t-model-findings.md E-D3).
 *
 * Node ids are opaque strings (§4.2), never raw integers:
 *   - a cluster bound to a Host -> "h:<hostid>"
 *   - an unbound cluster -> "d:<canonical cluster key>" (§5.2)
 *   - a Port -> "<node id>/<port name>"
 * These are derived fresh every render, not stored — if a cluster's key changes (A3), its id
 * changes with it; that is the documented, expected behavior (§4.2), not a bug to paper over with
 * an id-mapping table.
 *
 * Constraint 5: port-name normalization / lldpRemPortIdSubtype handling / MAC normalization below
 * are line-for-line ports of the G-model's ingest.php ($normalize_port_name /
 * $resolve_port_label) and push.py ($resolve_port_label / decode_snmp_value's Hex-STRING MAC
 * form) — not reinvented. The FNV-1a NBRKEY hash mirrors the LLD preprocessing script
 * (database/topology/discovery/topot/nbr_discovery.js) byte-for-byte; both are checked against
 * database/topology/discovery/topo-nbr-hash-vectors.json.
 *
 * §5.1 platform-fact adaptation (Step 0 finding V1, see t-model-findings.md): the spec assumes
 * `itemDiscovery.lastcheck` is readable via `item.get`'s `selectItemDiscovery` and freezes for a
 * lost LLD row while the item's own value keeps changing. On this Zabbix 8.0 instance,
 * `item_discovery.lastcheck` exists in the DB (confirmed via source, src/zabbix_server/lld/
 * lld_item.c) but `selectItemDiscovery`'s API output is restricted to
 * parent_itemid/key_/status/ts_delete/ts_disable/disable_source — `lastcheck` is NOT exposed.
 * Adapted design, using only fields the API actually returns:
 *   - `last_seen_<side>` = that side's neighbor item's own `lastclock` (items.lastclock, exposed).
 *     A fully silent reporter stops all dependent-item reprocessing, so every one of its items'
 *     `lastclock` freezes — this still catches the "silent reporter" staleness mechanism §5.4
 *     describes.
 *   - `lost_<side>` = the neighbor item's *own latest value* decodes to `{"present":false}` (the
 *     topo.nbr item prototype's step-1 preprocessing, §3.3, returns exactly this shape when its
 *     NBRKEY is no longer present in the current blob — value payload, not LLD lifecycle
 *     metadata). This is actually a *more* precise "reporter still pushes, neighbor gone" signal
 *     than a frozen lastcheck would have been, since it is asserted positively by the reporter's
 *     own current push rather than inferred from absence.
 *   `lastclock` still advances even when `present:false` (the item is being reprocessed every
 *   push, just with a "gone" value) — so a lost-but-not-silent side does NOT go stale by the
 *   `last_seen` clock alone; diagnostics (§5.6) surfaces `lost_<side>` as its own signal precisely
 *   so the two cases stay distinguishable, per §5.4's explicit requirement.
 */
class CTopologyTModel {

	private const TAG_ID = 'topo.id';
	private const TAG_UNBIND = 'topo.unbind';
	private const TAG_LINK_MANUAL = 'topo.link.manual';
	private const TAG_LINK_DISMISS = 'topo.link.dismiss';

	private const RAW_ITEM_KEY = 'topology.discovery.raw';
	private const SELF_ITEM_KEY = 'topo.self';
	private const NEIGHBOR_ITEM_KEY_PREFIX = 'topo.nbr[';

	private const STALE_SECONDS = 7 * 86400;

	// ---- FNV-1a NBRKEY hash (mirrors nbr_discovery.js / nbr_item.js byte-for-byte; §3.2's
	// "Hash:" note; test vectors in topo-nbr-hash-vectors.json) ----

	private static function fnv1a32(string $str, int $seed): int {
		$hash = $seed;
		$len = strlen($str);
		for ($i = 0; $i < $len; $i++) {
			$hash = $hash ^ ord($str[$i]);
			$hi = (($hash >> 16) & 0xffff) * 16777619 & 0xffff;
			$lo = ($hash & 0xffff) * 16777619;
			$hash = (($hi << 16) + $lo) & 0xffffffff;
		}
		return $hash & 0xffffffff;
	}

	private static function hexHash16(string $str): string {
		$h1 = str_pad(dechex(self::fnv1a32($str, 2166136261)), 8, '0', STR_PAD_LEFT);
		$h2 = str_pad(dechex(self::fnv1a32($str, 2654435761)), 8, '0', STR_PAD_LEFT);
		return $h1.$h2;
	}

	// Line-for-line port of ingest.php's $normalize_port_name (G-spec §3 rule 4 / constraint 5).
	private const LONG_TO_SHORT = [
		'gigabitethernet' => 'gi',
		'tengigabitethernet' => 'te',
		'fastethernet' => 'fa',
		'port-channel' => 'po'
	];

	private static function normalizePortName(string $name): string {
		$lower = strtolower(trim($name));
		foreach (self::LONG_TO_SHORT as $long => $short) {
			if (strncmp($lower, $long, strlen($long)) === 0) {
				return $short.substr($lower, strlen($long));
			}
		}
		return $lower;
	}

	// Line-for-line port of push.py's resolve_port_label()/ingest.php's $resolve_port_label.
	private static function resolvePortLabel(?string $desc, ?string $id, ?string $subtype): string {
		if ($desc) {
			return $desc;
		}
		if (in_array($subtype, ['interfaceName', 'macAddress'], true) && $id) {
			return $id;
		}
		return $id ?: 'unknown';
	}

	// Canonical peer id (T-model spec §4.1). $reporter_host/$local_if_index are only meaningful
	// for the sysname-fallback form and are ignored when $chassis_id is present.
	private static function canonicalPeerId(?string $chassis_id, ?string $sysname, string $reporter_host,
			?int $local_if_index): string {
		if ($chassis_id) {
			$id = 'c:'.strtolower($chassis_id);
		}
		else {
			$id = 's:'.$reporter_host.':'.($local_if_index ?? 'self').':'.($sysname ?? '');
		}
		return strlen($id) > 255 ? 'h:'.self::hexHash16($id) : $id;
	}

	private static function looksLikeMac(?string $s): bool {
		return $s !== null && preg_match('/^([0-9a-f]{2}:){5}[0-9a-f]{2}$/i', $s) === 1;
	}

	// ---- node/port id helpers (§4.2) ----

	private static function hostNodeId(string $hostid): string {
		return 'h:'.$hostid;
	}

	private static function unboundNodeId(string $cluster_key): string {
		return 'd:'.$cluster_key;
	}

	private static function portId(string $node_id, string $port_name): string {
		return $node_id.'/'.$port_name;
	}

	/**
	 * §5.1 fetch — a fixed number of batched API calls regardless of graph size. Returns the raw
	 * material every later step works from, plus per-step timings/counts for §5.6 diagnostics.
	 */
	private static function fetch(): array {
		$diag = ['steps' => [], 'api_calls' => 0];
		$step = static function (string $name, callable $fn) use (&$diag) {
			$t0 = microtime(true);
			$result = $fn();
			$diag['steps'][$name] = ['ms' => round((microtime(true) - $t0) * 1000, 1)];
			$diag['api_calls']++;
			return $result;
		};

		// Step 1: reporter self items.
		$self_items = $step('topo.self items', static fn () => API::Item()->get([
			'output' => ['itemid', 'hostid', 'lastvalue', 'lastclock'],
			'filter' => ['key_' => self::SELF_ITEM_KEY]
		]));

		// Step 2: neighbor items (topo.role=neighbor tag).
		$neighbor_items = $step('topo.nbr items', static fn () => API::Item()->get([
			'output' => ['itemid', 'hostid', 'key_', 'lastvalue', 'lastclock'],
			'tags' => [['tag' => 'topo.role', 'value' => 'neighbor', 'operator' => 0]],
			'selectTags' => 'extend'
		]));

		// Step 3: raw blobs, for §3.4 port inventory.
		$raw_items = $step('topology.discovery.raw items', static fn () => API::Item()->get([
			'output' => ['itemid', 'hostid', 'lastvalue'],
			'filter' => ['key_' => self::RAW_ITEM_KEY]
		]));

		// Steps 4+5 combined into one host.get (spec lists them separately for clarity; this
		// assembler folds them into a single batched call — still one query per render, just one
		// fewer than the spec's step count, documented here rather than silently).
		$hosts = $step('hosts (tags+inventory)', static fn () => API::Host()->get([
			'output' => ['hostid', 'host', 'name', 'status'],
			'selectTags' => 'extend',
			'selectInventory' => ['macaddress_a', 'macaddress_b'],
			'selectInterfaces' => ['ip'],
			'monitored_hosts' => true,
			'templated_hosts' => false
		]));

		return compact('self_items', 'neighbor_items', 'raw_items', 'hosts', 'diag');
	}

	/**
	 * §5.2 — cluster raw observations into participants.
	 *
	 * @return array{
	 *   clusters: array<string, array{ids: string[], self_hostids: string[], key: string}>,
	 *   obs_by_id: array<string, array>  -- one entry per (self|neighbor) observation, keyed by
	 *     its own canonical id, carrying enough to resolve links/binding later.
	 * }
	 */
	private static function clusterParticipants(array $self_items, array $hosts_by_id,
			array &$conflicts): array {
		// id -> cluster key, and cluster key -> {ids: [...], self_hostids: [...]}.
		$cluster_of_id = [];
		$clusters = [];

		$union = static function (string $id, string $cluster_key) use (&$cluster_of_id, &$clusters) {
			if (!isset($clusters[$cluster_key])) {
				$clusters[$cluster_key] = ['ids' => [], 'self_hostids' => []];
			}
			if (!in_array($id, $clusters[$cluster_key]['ids'], true)) {
				$clusters[$cluster_key]['ids'][] = $id;
			}
			$cluster_of_id[$id] = $cluster_key;
		};

		// Rule 1: every c: id is its own cluster key (identical c: ids collapse together).
		$self_by_hostid = [];
		$mgmt_ip_by_chassis_less_self = []; // hostid => mgmt_ip, only for chassis-less selves.
		foreach ($self_items as $item) {
			$hostid = $item['hostid'];
			$self = json_decode((string) $item['lastvalue'], true);
			if (!is_array($self)) {
				continue; // malformed/absent self value -- tolerate, §4's "assembler must tolerate".
			}
			$host = $hosts_by_id[$hostid]['host'] ?? $hostid;
			$id = self::canonicalPeerId($self['chassis_id'] ?? null, $self['sysname'] ?? null, $host, null);
			$self_by_hostid[$hostid] = $self + ['id' => $id, 'host' => $host];

			$cluster_key = str_starts_with($id, 'c:') ? $id : $id; // both forms are valid keys as-is
			$union($id, $cluster_key);
			$clusters[$cluster_key]['self_hostids'][] = $hostid;

			if (!str_starts_with($id, 'c:') && !empty($self['mgmt_ip'])) {
				$mgmt_ip_by_chassis_less_self[$hostid] = $self['mgmt_ip'];
			}
		}

		// Rule 2: mgmt_ip may attach a chassis-less self observation to exactly one other c:
		// cluster that shares the same mgmt_ip. No transitive merging (not union-find) -- only
		// ever folds a self-observation's own singleton into a pre-existing, differently-keyed
		// cluster; never merges two already-multi-member clusters into each other.
		foreach ($mgmt_ip_by_chassis_less_self as $hostid => $mgmt_ip) {
			$self_id = $self_by_hostid[$hostid]['id'];
			$self_cluster_key = $cluster_of_id[$self_id];
			if (count($clusters[$self_cluster_key]['ids']) > 1) {
				continue; // already anchored by a shared chassis id -- mgmt_ip must not re-merge it.
			}
			$candidates = [];
			foreach ($self_by_hostid as $other_hostid => $other_self) {
				if ($other_hostid === $hostid || ($other_self['mgmt_ip'] ?? null) !== $mgmt_ip) {
					continue;
				}
				$candidates[$cluster_of_id[$other_self['id']]] = true;
			}
			if (count($candidates) === 1) {
				$target_key = array_key_first($candidates);
				if ($target_key !== $self_cluster_key) {
					// Fold the singleton self-cluster's id into the target cluster; drop the
					// now-empty singleton.
					$union($self_id, $target_key);
					$clusters[$target_key]['self_hostids'][] = $hostid;
					unset($clusters[$self_cluster_key]);
				}
			}
			elseif (count($candidates) > 1) {
				$conflicts[] = [
					'type' => 'mgmt_ip', 'mgmt_ip' => $mgmt_ip,
					'clusters' => array_keys($candidates) + [$self_cluster_key],
					'message' => "mgmt_ip {$mgmt_ip} matches more than one chassis cluster -- not merged."
				];
			}
		}

		return ['clusters' => $clusters, 'cluster_of_id' => $cluster_of_id, 'self_by_hostid' => $self_by_hostid];
	}

	/**
	 * §5.2 rule 4: cluster key = smallest c: id in the cluster, else its (single) s: id.
	 */
	private static function clusterDisplayKey(array $cluster): string {
		$c_ids = array_values(array_filter($cluster['ids'], static fn ($id) => str_starts_with($id, 'c:')));
		if ($c_ids) {
			sort($c_ids);
			return $c_ids[0];
		}
		return $cluster['ids'][0] ?? '';
	}

	/**
	 * §5.3 — bind clusters to hosts. Returns cluster_key => ['hostid' => ?, 'matched_by' => ?,
	 * 'candidates' => [...], 'conflict' => bool].
	 */
	private static function bindClusters(array $clusters, array $hosts_by_id, array $tags_index): array {
		$bindings = [];

		foreach ($clusters as $cluster_key => $cluster) {
			$candidates = [];

			// 1. reporter_self.
			foreach ($cluster['self_hostids'] as $hostid) {
				$candidates[] = ['hostid' => $hostid, 'matched_by' => 'reporter_self'];
			}

			// 2. manual (topo.id on some host names one of this cluster's ids).
			foreach ($cluster['ids'] as $id) {
				foreach ($tags_index['by_topo_id'][$id] ?? [] as $hostid) {
					$candidates[] = ['hostid' => $hostid, 'matched_by' => 'manual'];
				}
			}

			// 3. mac (chassis id, when MAC-shaped, matched against inventory -- unless that host
			// carries topo.unbind for this id).
			foreach ($cluster['ids'] as $id) {
				if (!str_starts_with($id, 'c:')) {
					continue;
				}
				$mac = substr($id, 2);
				if (!self::looksLikeMac($mac)) {
					continue;
				}
				foreach ($tags_index['by_mac'][$mac] ?? [] as $hostid) {
					if (in_array($id, $tags_index['unbind_by_host'][$hostid] ?? [], true)) {
						continue;
					}
					$candidates[] = ['hostid' => $hostid, 'matched_by' => 'mac'];
				}
			}

			if (!$candidates) {
				$bindings[$cluster_key] = ['hostid' => null, 'matched_by' => null, 'candidates' => [],
					'conflict' => false];
				continue;
			}

			$hostids = array_unique(array_column($candidates, 'hostid'));
			sort($hostids, SORT_STRING);
			$winner_hostid = $hostids[0];
			$winner_matched_by = null;
			// First binding rule that applies wins -- priority reporter_self > manual > mac,
			// evaluated for the winning (lowest-hostid) host specifically.
			foreach (['reporter_self', 'manual', 'mac'] as $priority) {
				foreach ($candidates as $c) {
					if ($c['hostid'] === $winner_hostid && $c['matched_by'] === $priority) {
						$winner_matched_by = $priority;
						break 2;
					}
				}
			}

			$bindings[$cluster_key] = [
				'hostid' => $winner_hostid,
				'matched_by' => $winner_matched_by,
				'candidates' => $candidates,
				'conflict' => count($hostids) > 1
			];
		}

		return $bindings;
	}

	private static function buildTagsIndex(array $hosts): array {
		$by_topo_id = [];
		$by_mac = [];
		$unbind_by_host = [];
		$manual_links = []; // hostid => [raw tag value, ...]
		$dismiss = []; // hostid => [raw tag value, ...]
		$malformed = [];

		foreach ($hosts as $host) {
			$hostid = $host['hostid'];
			foreach ($host['tags'] as $tag) {
				if ($tag['tag'] === self::TAG_ID) {
					if (str_contains($tag['value'], '|')) {
						$malformed[] = ['hostid' => $hostid, 'tag' => $tag['tag'], 'value' => $tag['value']];
						continue;
					}
					$by_topo_id[$tag['value']][] = $hostid;
				}
				elseif ($tag['tag'] === self::TAG_UNBIND) {
					$unbind_by_host[$hostid][] = $tag['value'];
				}
				elseif ($tag['tag'] === self::TAG_LINK_MANUAL) {
					$manual_links[$hostid][] = $tag['value'];
				}
				elseif ($tag['tag'] === self::TAG_LINK_DISMISS) {
					$dismiss[$hostid][] = $tag['value'];
				}
			}
			foreach ([$host['inventory']['macaddress_a'] ?? '', $host['inventory']['macaddress_b'] ?? ''] as $mac) {
				if ($mac !== '') {
					$by_mac[strtolower($mac)][] = $hostid;
				}
			}
		}

		return compact('by_topo_id', 'by_mac', 'unbind_by_host', 'manual_links', 'dismiss', 'malformed');
	}

	/**
	 * §5.4 — links. Groups neighbor items into (reporter_host, local_port) <-> (peer_node,
	 * peer_port) pairs, merges the two observations of one physical cable into a single link,
	 * then layers manual links on top per the priority rules.
	 */
	private static function buildLinks(array $neighbor_items, array $self_by_hostid, array $hosts_by_id,
			array $id_to_node, array $ports_by_hostid, array $cluster_of_id, array $bindings_by_cluster,
			array $tags_index, array &$conflicts): array {
		$now = time();

		// side observations, keyed by "<node_id>/<port_name>" (this side's own port).
		$side_by_port_id = [];

		foreach ($neighbor_items as $item) {
			$tags = [];
			foreach ($item['tags'] as $t) {
				$tags[$t['tag']] = $t['value'];
			}
			$peer_port_raw = $tags['topo.peer.port'] ?? '';
			if ($peer_port_raw === '') {
				continue; // §3.2: participant resolved, port didn't -- no link to draw (G-spec rule 1).
			}
			$hostid = $item['hostid'];
			$reporter_node = self::hostNodeId($hostid);
			$local_port = $tags['interface'] ?? '';
			if ($local_port === '') {
				continue;
			}
			$peer_id = $tags['topo.peer.id'] ?? '';
			$peer_cluster_key = $cluster_of_id[$peer_id] ?? null;
			$peer_node = $peer_cluster_key !== null
				? ($id_to_node[$peer_cluster_key] ?? self::unboundNodeId($peer_cluster_key))
				: self::unboundNodeId($peer_id);

			// Resolve far port (§5.4): if the peer cluster contains a reporter, match against
			// that reporter's own ports; otherwise synthetic.
			$peer_port_name = self::normalizePortName($peer_port_raw);
			if ($peer_cluster_key !== null && ($bindings_by_cluster[$peer_cluster_key]['matched_by'] ?? null) === 'reporter_self') {
				$peer_hostid = $bindings_by_cluster[$peer_cluster_key]['hostid'];
				foreach ($ports_by_hostid[$peer_hostid] ?? [] as $p) {
					if (self::normalizePortName($p['name']) === $peer_port_name) {
						$peer_port_name = $p['name'];
						break;
					}
				}
			}

			$value = json_decode((string) $item['lastvalue'], true);
			$present = is_array($value) ? ($value['present'] ?? false) : false;

			$this_port_id = self::portId($reporter_node, $local_port);
			$peer_port_id = self::portId($peer_node, $peer_port_name);

			$side_by_port_id[$this_port_id] = [
				'port_id' => $this_port_id, 'peer_port_id' => $peer_port_id,
				'last_seen' => (int) $item['lastclock'], 'present' => (bool) $present,
				'via' => $tags['topo.via'] ?? 'lldp'
			];
		}

		// Merge into links: two sides pointing at each other collapse to one link.
		$links = [];
		$seen_pairs = [];
		foreach ($side_by_port_id as $port_id => $side) {
			$other = $side_by_port_id[$side['peer_port_id']] ?? null;
			$pair_key = $other !== null
				? implode('|', [min($port_id, $side['peer_port_id']), max($port_id, $side['peer_port_id'])])
				: $port_id;
			if (isset($seen_pairs[$pair_key])) {
				continue;
			}
			$seen_pairs[$pair_key] = true;

			$src_port = min($port_id, $side['peer_port_id']);
			$dst_port = max($port_id, $side['peer_port_id']);
			$src_side = ($src_port === $port_id) ? $side : $other;
			$dst_side = ($dst_port === $port_id) ? $side : $other;

			$last_seen_src = $src_side['last_seen'] ?? null;
			$last_seen_dst = $dst_side['last_seen'] ?? null;
			$last_seen = max($last_seen_src ?? 0, $last_seen_dst ?? 0) ?: null;

			$links[] = [
				'src_port_id' => $src_port, 'dst_port_id' => $dst_port,
				'discovered_via' => ($src_side['via'] ?? $dst_side['via'] ?? 'lldp'),
				'manual' => false,
				'last_seen_src' => $last_seen_src, 'last_seen_dst' => $last_seen_dst,
				'last_seen' => $last_seen,
				'lost_src' => $src_side !== null ? !($src_side['present'] ?? true) : null,
				'lost_dst' => $dst_side !== null ? !($dst_side['present'] ?? true) : null,
				'stale' => $last_seen === null || ($now - $last_seen) > self::STALE_SECONDS,
				'conflict' => false
			];
		}

		$links_by_port = [];
		foreach ($links as $i => $link) {
			$links_by_port[$link['src_port_id']][] = $i;
			$links_by_port[$link['dst_port_id']][] = $i;
		}

		// Manual links, layered on top (§5.4).
		foreach ($tags_index['manual_links'] as $hostid => $values) {
			$reporter_node = self::hostNodeId($hostid);
			foreach ($values as $raw) {
				$parts = explode('|', $raw);
				if (count($parts) !== 3) {
					$conflicts[] = ['type' => 'malformed_tag', 'hostid' => $hostid,
						'tag' => self::TAG_LINK_MANUAL, 'value' => $raw];
					continue;
				}
				[$local_port, $peer_ref, $peer_port] = $parts;
				if (str_starts_with($peer_ref, 'h:')) {
					$peer_node = 'h:'.substr($peer_ref, 2);
				}
				elseif (str_starts_with($peer_ref, 'd:')) {
					$peer_node = self::unboundNodeId(substr($peer_ref, 2));
				}
				else {
					$conflicts[] = ['type' => 'malformed_tag', 'hostid' => $hostid,
						'tag' => self::TAG_LINK_MANUAL, 'value' => $raw];
					continue;
				}

				$this_port_id = self::portId($reporter_node, $local_port);
				$peer_port_id = self::portId($peer_node, $peer_port);
				$src_port = min($this_port_id, $peer_port_id);
				$dst_port = max($this_port_id, $peer_port_id);

				$existing_here = $links_by_port[$this_port_id] ?? [];
				$discovered_same_pair = null;
				foreach ($existing_here as $li) {
					if ($links[$li]['src_port_id'] === $src_port && $links[$li]['dst_port_id'] === $dst_port) {
						$discovered_same_pair = $li;
					}
				}

				if ($discovered_same_pair !== null) {
					// Same port pair discovered + manual -> upgrade in place (G-spec §3.5).
					$links[$discovered_same_pair]['manual'] = true;
				}
				else {
					// Does this port already carry a *different* discovered neighbor? Manual wins,
					// the discovered one is flagged as a conflict (§5.4).
					foreach ($existing_here as $li) {
						$links[$li]['conflict'] = true;
						$links[$li]['conflict_reason'] = 'manual link on this port overrides a different discovered neighbor';
					}
					$links[] = [
						'src_port_id' => $src_port, 'dst_port_id' => $dst_port,
						'discovered_via' => 'manual', 'manual' => true,
						'last_seen_src' => null, 'last_seen_dst' => null, 'last_seen' => null,
						'lost_src' => null, 'lost_dst' => null, 'stale' => false, 'conflict' => false
					];
					$new_i = count($links) - 1;
					$links_by_port[$src_port][] = $new_i;
					$links_by_port[$dst_port][] = $new_i;
				}
			}
		}

		// A port ending up with more than one link -> draw the newest, flag the rest (§5.4).
		$by_port_final = [];
		foreach ($links as $i => $link) {
			$by_port_final[$link['src_port_id']][] = $i;
			$by_port_final[$link['dst_port_id']][] = $i;
		}
		$suppressed = [];
		foreach ($by_port_final as $port_id => $indexes) {
			$indexes = array_unique($indexes);
			if (count($indexes) <= 1) {
				continue;
			}
			usort($indexes, static function ($a, $b) use ($links) {
				$va = $links[$a]['manual'] ? PHP_INT_MAX : ($links[$a]['last_seen'] ?? 0);
				$vb = $links[$b]['manual'] ? PHP_INT_MAX : ($links[$b]['last_seen'] ?? 0);
				return $vb <=> $va;
			});
			foreach (array_slice($indexes, 1) as $loser) {
				$links[$loser]['conflict'] = true;
				$links[$loser]['conflict_reason'] = 'more than one link claims port '.$port_id;
				$suppressed[$loser] = true;
			}
		}

		return ['links' => $links, 'suppressed' => $suppressed];
	}

	/**
	 * The assembler (§5): a pure function, re-run on every call (constraint 1/§0 — no caching).
	 * Runs with the requesting user's own API permissions (constraint 6) — every call below goes
	 * through the ordinary API::*() facade, same as the rest of this Zabbix instance, no elevated
	 * token.
	 */
	public static function assemble(): array {
		$conflicts = [];
		$fetched = self::fetch();
		['self_items' => $self_items, 'neighbor_items' => $neighbor_items, 'raw_items' => $raw_items,
			'hosts' => $hosts, 'diag' => $diag] = $fetched;

		$hosts_by_id = array_column($hosts, null, 'hostid');
		$tags_index = self::buildTagsIndex($hosts);

		$ports_by_hostid = [];
		foreach ($raw_items as $item) {
			$blob = json_decode((string) $item['lastvalue'], true);
			$ports_by_hostid[$item['hostid']] = is_array($blob) ? ($blob['ports'] ?? []) : [];
		}

		['clusters' => $clusters, 'cluster_of_id' => $cluster_of_id, 'self_by_hostid' => $self_by_hostid] =
			self::clusterParticipants($self_items, $hosts_by_id, $conflicts);

		// Neighbor observations join clusters purely through their already-canonical topo.peer.id
		// tag (§5.2 rule 3's "s: observations join only through an exact s: match" holds
		// automatically here, since NBRKEY/PEERID are computed identically by every reporter that
		// might see the same s:-keyed participant).
		foreach ($neighbor_items as $item) {
			foreach ($item['tags'] as $t) {
				if ($t['tag'] === 'topo.peer.id' && $t['value'] !== '' && !isset($cluster_of_id[$t['value']])) {
					$cluster_of_id[$t['value']] = $t['value'];
					if (!isset($clusters[$t['value']])) {
						$clusters[$t['value']] = ['ids' => [$t['value']], 'self_hostids' => []];
					}
				}
			}
		}

		$bindings = self::bindClusters($clusters, $hosts_by_id, $tags_index);

		// Display-name fallback for a cluster with no self observation of its own: its display
		// name is only ever known through *someone else's* neighbor observation of it (topo.peer.
		// name), same as the old tags-model's "topology.neighbor.N.name" fallback for unmanaged
		// nodes. First non-empty name any reporter has seen for this id wins.
		$peer_name_by_id = [];
		foreach ($neighbor_items as $item) {
			$tags = [];
			foreach ($item['tags'] as $t) {
				$tags[$t['tag']] = $t['value'];
			}
			$pid = $tags['topo.peer.id'] ?? '';
			if ($pid !== '' && !empty($tags['topo.peer.name']) && empty($peer_name_by_id[$pid])) {
				$peer_name_by_id[$pid] = $tags['topo.peer.name'];
			}
		}

		$id_to_node = [];
		$nodes = [];
		foreach ($clusters as $cluster_key => $cluster) {
			$binding = $bindings[$cluster_key];
			$display_key = self::clusterDisplayKey($cluster);
			if ($binding['hostid'] !== null) {
				$node_id = self::hostNodeId($binding['hostid']);
			}
			else {
				$node_id = self::unboundNodeId($display_key);
			}
			$id_to_node[$cluster_key] = $node_id;

			if ($binding['conflict']) {
				$conflicts[] = ['type' => 'binding', 'cluster' => $cluster_key,
					'candidates' => $binding['candidates'],
					'message' => 'more than one host binds to this cluster; lowest hostid wins for drawing.'];
			}

			if (!isset($nodes[$node_id])) {
				$nodes[$node_id] = [
					'id' => $node_id, 'bound' => $binding['hostid'] !== null,
					'hostid' => $binding['hostid'], 'devices' => []
				];
			}
			$self_data = null;
			foreach ($cluster['self_hostids'] as $hid) {
				$self_data = $self_by_hostid[$hid] ?? $self_data;
			}
			$peer_name = null;
			foreach ($cluster['ids'] as $cid) {
				$peer_name = $peer_name_by_id[$cid] ?? $peer_name;
			}
			$nodes[$node_id]['devices'][] = [
				'cluster_key' => $cluster_key, 'display_key' => $display_key,
				'matched_by' => $binding['matched_by'], 'ids' => $cluster['ids'],
				'sysname' => $self_data['sysname'] ?? $peer_name, 'vendor' => $self_data['vendor'] ?? null,
				'mgmt_ip' => $self_data['mgmt_ip'] ?? null,
				'conflict' => $binding['conflict']
			];
		}

		['links' => $links, 'suppressed' => $suppressed] = self::buildLinks($neighbor_items, $self_by_hostid,
			$hosts_by_id, $id_to_node, $ports_by_hostid, $cluster_of_id, $bindings, $tags_index, $conflicts);

		foreach ($links as $link) {
			if (!empty($link['conflict'])) {
				$conflicts[] = ['type' => 'port', 'src' => $link['src_port_id'], 'dst' => $link['dst_port_id'],
					'message' => $link['conflict_reason'] ?? 'link conflict'];
			}
		}

		$diag['conflicts'] = $conflicts;
		$diag['malformed_tags'] = $tags_index['malformed'];
		$diag['counts'] = [
			'nodes' => count($nodes), 'links' => count($links),
			'lost_sides' => count(array_filter($links, static fn ($l) => ($l['lost_src'] ?? false) || ($l['lost_dst'] ?? false))),
			'silent_sides' => count(array_filter($links, static fn ($l) => $l['stale']))
		];

		return ['nodes' => $nodes, 'links' => $links, 'hosts_by_id' => $hosts_by_id, 'diag' => $diag,
			'tags_index' => $tags_index, 'cluster_of_id' => $cluster_of_id, 'clusters' => $clusters,
			'bindings' => $bindings, 'ports_by_hostid' => $ports_by_hostid, 'id_to_node' => $id_to_node];
	}

	// ---- §6 read surface, mirroring the G-spec's shape (CTopologyPrototype::getDevices() etc.) ----

	private static function describeSeverity(?int $severity): array {
		return [
			'severity' => $severity,
			'severity_name' => $severity !== null ? CSeverityHelper::getName($severity) : null,
			'color' => $severity !== null ? CSeverityHelper::getColor($severity) : null
		];
	}

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

	/**
	 * §7 constraint 4/point 1-2: the existing (G-model-shaped) frontend already renders whatever
	 * this shape returns -- 'type'/'disabled'/'maintenance'/'represented'/severity fields below are
	 * NOT part of the T-model spec's own vocabulary (this class's own richer 'bound'/'devices'/
	 * 'conflict' fields are the real T-model data), they exist purely so the G-model's
	 * monitoring.topology.view.js.php needs zero read-path changes beyond the data-source prefix
	 * switch -- one data shape, read by either backend's frontend code without a fork.
	 */
	public static function getDevices(): array {
		$graph = self::assemble();
		$devices = [];
		foreach ($graph['nodes'] as $node_id => $node) {
			$host = $node['bound'] ? ($graph['hosts_by_id'][$node['hostid']] ?? null) : null;
			$name = $node['bound']
				? ($host['name'] ?? $node['hostid'])
				: ($node['devices'][0]['sysname'] ?: $node['devices'][0]['display_key']);
			$conflict = (bool) array_filter($node['devices'], static fn ($d) => $d['conflict']);
			$status = $host['status'] ?? null;
			$disabled = $status !== null && (int) $status === HOST_STATUS_NOT_MONITORED;

			$devices[] = [
				'id' => $node_id, 'name' => $name, 'bound' => $node['bound'],
				'hostid' => $node['hostid'], 'status' => $status,
				'devices' => $node['devices'], 'conflict' => $conflict,
				// compatibility fields for the existing frontend (see doc comment above):
				'type' => $node['bound'] ? 'host' : 'device',
				'disabled' => $disabled,
				'maintenance' => false, // proxy-group/maintenance join not modeled in this pass
				'chassis_id' => $node['devices'][0]['display_key'] ?? null,
				'identity' => ($node['bound'] && ($node['devices'][0]['matched_by'] ?? null) !== 'reporter_self')
					? ($node['devices'][0]['display_key'] ?? null) : null,
				'device_type' => null,
				'represented' => $node['bound']
			] + self::describeSeverity($disabled || !$node['bound'] ? null : self::getMaxActiveSeverityForHost($node['hostid']));
		}
		return $devices;
	}

	public static function getRelations(): array {
		$graph = self::assemble();
		$relations = [];
		$known_nodes = array_keys($graph['nodes']);
		foreach ($graph['links'] as $link) {
			[$src_node, $src_port] = self::splitPortId($link['src_port_id'], $known_nodes);
			[$dst_node, $dst_port] = self::splitPortId($link['dst_port_id'], $known_nodes);
			$relations[] = [
				'source' => $src_node, 'target' => $dst_node,
				// plain port NAMES (matches the G-model frontend's existing rendering contract) --
				// the full "<node id>/<port name>" strings the link/unlink write endpoints need are
				// exposed separately as *_port_id, not reused under these two keys.
				'source_port' => $src_port, 'target_port' => $dst_port,
				'source_port_id' => $link['src_port_id'], 'target_port_id' => $link['dst_port_id'],
				'type' => 'physical_link',
				'discovered_via' => $link['discovered_via'], 'manual' => $link['manual'],
				'last_seen' => $link['last_seen'], 'last_seen_src' => $link['last_seen_src'],
				'last_seen_dst' => $link['last_seen_dst'], 'stale' => $link['stale'],
				'lost_src' => $link['lost_src'], 'lost_dst' => $link['lost_dst'],
				'conflict' => $link['conflict']
			];
		}
		return $relations;
	}

	/**
	 * Splits a "<node id>/<port name>" compound id (§4.2) back into its parts. NOT a plain
	 * last-slash split: a port name routinely contains '/' itself (e.g. "Gi0/24"), which a naive
	 * strrpos() split gets wrong (found live, testing getPorts()/getNeighbors() against the real
	 * lab -- Switch1's own links came back empty because "h:11252/Gi0/24" split at the LAST slash
	 * instead of the one right after the node id). Since a node id is always exactly one of the
	 * graph's own current node ids, matching against that known set is unambiguous for every real
	 * case; $known_node_ids must come from the same assemble() call the port id itself was built
	 * from. Falls back to the old last-slash heuristic only when no known set is available (there
	 * is currently no such caller) -- kept as a defensive floor, not a real split strategy.
	 */
	private static function splitPortId(string $port_id, ?array $known_node_ids = null): array {
		if ($known_node_ids !== null) {
			$best = '';
			foreach ($known_node_ids as $node_id) {
				if (strlen($node_id) > strlen($best) && str_starts_with($port_id, $node_id.'/')) {
					$best = $node_id;
				}
			}
			if ($best !== '') {
				return [$best, substr($port_id, strlen($best) + 1)];
			}
		}
		$pos = strrpos($port_id, '/');
		return $pos === false ? [$port_id, ''] : [substr($port_id, 0, $pos), substr($port_id, $pos + 1)];
	}

	public static function getNeighbors(string $id): array {
		$graph = self::assemble();
		$devices_by_id = array_column(self::getDevices(), null, 'id');
		$out = [];
		$known_nodes = array_keys($graph['nodes']);
		foreach ($graph['links'] as $link) {
			[$src_node, $src_port] = self::splitPortId($link['src_port_id'], $known_nodes);
			[$dst_node, $dst_port] = self::splitPortId($link['dst_port_id'], $known_nodes);
			if ($src_node !== $id && $dst_node !== $id) {
				continue;
			}
			$other_id = ($src_node === $id) ? $dst_node : $src_node;
			if (!isset($devices_by_id[$other_id])) {
				continue;
			}
			$out[] = $devices_by_id[$other_id] + [
				'local_port' => ($src_node === $id) ? $src_port : $dst_port,
				'remote_port' => ($src_node === $id) ? $dst_port : $src_port,
				'discovered_via' => $link['discovered_via'], 'manual' => $link['manual'],
				'stale' => $link['stale'], 'conflict' => $link['conflict']
			];
		}
		return $out;
	}

	public static function getPorts(string $id): array {
		$groups = ['connected' => [], 'disconnected' => [], 'port_channel' => [], 'management' => []];
		if (!str_starts_with($id, 'h:')) {
			return $groups; // unbound node -- nothing reports FOR it (§21 parity note).
		}
		$hostid = substr($id, 2);
		$graph = self::assemble();
		$ports = $graph['ports_by_hostid'][$hostid] ?? [];

		$connected_port_names = [];
		$known_nodes = array_keys($graph['nodes']);
		foreach ($graph['links'] as $link) {
			foreach ([$link['src_port_id'], $link['dst_port_id']] as $pid) {
				[$node, $port_name] = self::splitPortId($pid, $known_nodes);
				if ($node === $id) {
					$connected_port_names[$port_name] = $link;
				}
			}
		}

		foreach ($ports as $port) {
			$name = $port['name'];
			$if_type = $port['if_type'] ?? 'physical';
			$link = $connected_port_names[$name] ?? null;

			if ($if_type === 'lag') {
				$group = 'port_channel';
			}
			elseif ($if_type === 'mgmt') {
				$group = 'management';
			}
			elseif ($link !== null) {
				$group = 'connected';
			}
			else {
				$group = 'disconnected';
			}

			$connected_to = null;
			$connected_to_id = null;
			$connected_to_port = null;
			if ($link !== null) {
				[$src_node, $src_port] = self::splitPortId($link['src_port_id'], $known_nodes);
				[$dst_node, $dst_port] = self::splitPortId($link['dst_port_id'], $known_nodes);
				$other = ($src_node === $id) ? $dst_node : $src_node;
				$connected_to_id = $other;
				$connected_to_port = ($src_node === $id) ? $dst_port : $src_port;
				$connected_to = $graph['nodes'][$other]['bound']
					? ($graph['hosts_by_id'][$graph['nodes'][$other]['hostid']]['name'] ?? $other)
					: ($graph['nodes'][$other]['devices'][0]['sysname'] ?? $other);
			}

			$groups[$group][] = [
				'if_index' => $port['if_index'] ?? null, 'port' => $name, 'mac' => $port['mac'] ?? null,
				'status' => $port['oper_status'] ?? null, 'speed' => $port['speed'] ?? null,
				'connected_to' => $connected_to, 'connected_to_id' => $connected_to_id,
				// full "<node id>/<port name>" strings, so the frontend can call the T-model's
				// src/dst-string link/unlink endpoints (§6) without reconstructing them itself.
				'connected_to_port' => $connected_to_port,
				'port_id' => self::portId($id, $name),
				'connected_to_port_id' => $connected_to_id !== null ? self::portId($connected_to_id, $connected_to_port) : null,
				'stale' => $link['stale'] ?? null, 'conflict' => $link['conflict'] ?? null,
				'itemid' => null // filled in by the controller (§6: "/ports returns ... itemid" for E-B8)
			];
		}

		return $groups;
	}

	public static function getProblems(string $id): array {
		if (!str_starts_with($id, 'h:')) {
			return [];
		}
		$hostid = substr($id, 2);
		$problems = [];
		foreach (API::Problem()->get([
			'output' => ['eventid', 'name', 'severity', 'clock'],
			'hostids' => [$hostid], 'sortfield' => ['eventid'], 'sortorder' => ZBX_SORT_DOWN
		]) as $problem) {
			$problems[] = [
				'eventid' => $problem['eventid'], 'name' => $problem['name'],
				'severity' => (int) $problem['severity'], 'age' => time() - (int) $problem['clock']
			];
		}
		return $problems;
	}

	public static function searchHosts(string $q): array {
		if ($q === '') {
			return [];
		}
		$hosts = API::Host()->get(['output' => ['hostid', 'name'], 'search' => ['name' => $q], 'limit' => 20]);
		return array_map(static fn ($h) => ['id' => self::hostNodeId($h['hostid']), 'name' => $h['name']], $hosts);
	}

	/** §5.6 diagnostics — the main instrument for the §9 experiments. */
	public static function diagnostics(): array {
		$graph = self::assemble();
		$diag = $graph['diag'];

		// Dangling references: topo.id/topo.unbind/topo.link.manual/topo.link.dismiss pointing at
		// ids or ports that no longer occur in any observation.
		$dangling = [];
		foreach ($graph['tags_index']['by_topo_id'] as $tid => $hostids) {
			if (!isset($graph['cluster_of_id'][$tid])) {
				foreach ($hostids as $hostid) {
					$dangling[] = ['tag' => 'topo.id', 'hostid' => $hostid, 'value' => $tid];
				}
			}
		}
		foreach ($graph['tags_index']['unbind_by_host'] as $hostid => $ids) {
			foreach ($ids as $id) {
				if (!isset($graph['cluster_of_id'][$id])) {
					$dangling[] = ['tag' => 'topo.unbind', 'hostid' => $hostid, 'value' => $id];
				}
			}
		}
		$diag['dangling'] = $dangling;

		return $diag;
	}

	// ---- §6 write endpoints. Each does read -> modify -> write of the TARGET HOST's tags only,
	// re-checking the tag set hasn't changed since the read (optimistic concurrency, V5) --
	// host.update REPLACES the whole tags array (confirmed live, V5), so every write here must
	// read the full current set first and send the full new set back, never a partial patch. ----

	private static function currentTags(string $hostid): array {
		$host = API::Host()->get(['output' => ['hostid'], 'hostids' => [$hostid], 'selectTags' => 'extend']);
		if (!$host) {
			throw new Exception("Host {$hostid} not found or not visible.");
		}
		return array_map(static fn ($t) => ['tag' => $t['tag'], 'value' => $t['value']], $host[0]['tags']);
	}

	private static function writeTagsIfUnchanged(string $hostid, array $expected_tags, array $new_tags): void {
		// Re-check immediately before writing (§6: "must not assume validation holds at read
		// time"). A tag can always be hand-edited in the Zabbix UI between our read and write;
		// API::Host()->update() itself has no native optimistic-lock primitive for tags, so this
		// re-read-and-compare is the closest available approximation of the spec's "409 on
		// mismatch" -- documented limitation: a change landing in the narrow window between this
		// check and the update() call below is still possible (t-model-findings.md, D1/write-path
		// note).
		$now_tags = self::currentTags($hostid);
		$sort = static function (array $tags) {
			usort($tags, static fn ($a, $b) => [$a['tag'], $a['value']] <=> [$b['tag'], $b['value']]);
			return $tags;
		};
		if ($sort($now_tags) !== $sort($expected_tags)) {
			throw new CTopologyTModelConflictException("Host {$hostid}'s tags changed since they were read.");
		}
		$result = API::Host()->update([['hostid' => $hostid, 'tags' => $new_tags]]);
		if ($result === false) {
			// V5 gotcha: a rejected write (e.g. a stray 'automatic' field) returns bool(false), no
			// exception -- surface it as one here so callers don't see a silent no-op success.
			throw new Exception("Host {$hostid} tag update was rejected by the API.");
		}
	}

	public static function promote(string $device_id, string $hostid): void {
		$graph = self::assemble();
		if (!isset($graph['clusters'][$device_id]) && !isset($graph['cluster_of_id'][$device_id])) {
			throw new Exception("Unknown device id {$device_id}.");
		}
		$cluster_key = $graph['cluster_of_id'][$device_id] ?? $device_id;
		$binding = $graph['bindings'][$cluster_key] ?? null;
		if ($binding !== null && $binding['hostid'] !== null && $binding['hostid'] !== $hostid) {
			throw new Exception("Device {$device_id} is already bound to host {$binding['hostid']}.");
		}

		$expected = self::currentTags($hostid);
		$new = $expected;
		$new[] = ['tag' => self::TAG_ID, 'value' => $graph['clusters'][$cluster_key]['ids'][0] ?? $device_id];
		self::writeTagsIfUnchanged($hostid, $expected, $new);
	}

	public static function depromote(string $device_id): void {
		$graph = self::assemble();
		$cluster_key = $graph['cluster_of_id'][$device_id] ?? $device_id;
		$binding = $graph['bindings'][$cluster_key] ?? null;
		if ($binding === null || $binding['hostid'] === null) {
			throw new Exception("Device {$device_id} is not currently bound to a host.");
		}
		if ($binding['matched_by'] === 'reporter_self') {
			throw new Exception('Cannot depromote a reporter_self binding -- this host reports for itself.');
		}

		$hostid = $binding['hostid'];
		$expected = self::currentTags($hostid);
		if ($binding['matched_by'] === 'manual') {
			$new = array_values(array_filter($expected,
				static fn ($t) => !($t['tag'] === self::TAG_ID && in_array($t['value'], $graph['clusters'][$cluster_key]['ids'], true))));
		}
		else { // 'mac'
			$new = $expected;
			$new[] = ['tag' => self::TAG_UNBIND, 'value' => $graph['clusters'][$cluster_key]['ids'][0] ?? $device_id];
		}
		self::writeTagsIfUnchanged($hostid, $expected, $new);
	}

	/** POST /ports/{src}/link {dst} -- rejects if neither end is a host (documented T-model gap, B4/D4). */
	public static function linkPorts(string $src_port_id, string $dst_port_id): void {
		// Split against the current graph's own node id set, not a blind last-slash split -- a
		// port name routinely contains '/' itself (see splitPortId()'s own comment).
		$known_nodes = array_keys(self::assemble()['nodes']);
		[$src_node, $src_port] = self::splitPortId($src_port_id, $known_nodes);
		[$dst_node, $dst_port] = self::splitPortId($dst_port_id, $known_nodes);

		$src_is_host = str_starts_with($src_node, 'h:');
		$dst_is_host = str_starts_with($dst_node, 'h:');
		if (!$src_is_host && !$dst_is_host) {
			throw new Exception('Neither end of this link is a Zabbix host -- manual links need at '.
				'least one host to carry the topo.link.manual tag on (documented T-model gap, use cases B4/D4).');
		}

		if ($src_is_host && $dst_is_host) {
			$src_hostid = substr($src_node, 2);
			$dst_hostid = substr($dst_node, 2);
			$owner_hostid = min($src_hostid, $dst_hostid);
			$owner_is_src = $owner_hostid === $src_hostid;
		}
		else {
			$owner_is_src = $src_is_host;
			$owner_hostid = substr($owner_is_src ? $src_node : $dst_node, 2);
		}

		$local_port = $owner_is_src ? $src_port : $dst_port;
		$peer_node = $owner_is_src ? $dst_node : $src_node;
		$peer_port = $owner_is_src ? $dst_port : $src_port;
		$peer_ref = str_starts_with($peer_node, 'h:') ? ('h:'.substr($peer_node, 2)) : ('d:'.substr($peer_node, 2));

		$expected = self::currentTags($owner_hostid);
		$new = $expected;
		$new[] = ['tag' => self::TAG_LINK_MANUAL, 'value' => implode('|', [$local_port, $peer_ref, $peer_port])];
		self::writeTagsIfUnchanged($owner_hostid, $expected, $new);
	}

	/** DELETE /ports/{src}/link/{dst} -- manual: drop the tag; discovered: item.delete (V3) or dismiss tag. */
	public static function unlinkPorts(string $src_port_id, string $dst_port_id): array {
		$known_nodes = array_keys(self::assemble()['nodes']);
		[$src_node, $src_port] = self::splitPortId($src_port_id, $known_nodes);
		[$dst_node, $dst_port] = self::splitPortId($dst_port_id, $known_nodes);

		foreach ([[$src_node, $src_port, $dst_node, $dst_port], [$dst_node, $dst_port, $src_node, $src_port]] as
				[$node, $port, $peer_node, $peer_port]) {
			if (!str_starts_with($node, 'h:')) {
				continue;
			}
			$hostid = substr($node, 2);
			$expected = self::currentTags($hostid);
			$peer_ref_variants = [
				str_starts_with($peer_node, 'h:') ? ('h:'.substr($peer_node, 2)) : null,
				str_starts_with($peer_node, 'd:') ? ('d:'.substr($peer_node, 2)) : null
			];
			$manual_removed = false;
			$new = array_values(array_filter($expected, function ($t) use (&$manual_removed, $port, $peer_ref_variants, $peer_port) {
				if ($t['tag'] !== self::TAG_LINK_MANUAL) {
					return true;
				}
				$parts = explode('|', $t['value']);
				if (count($parts) === 3 && $parts[0] === $port && $parts[2] === $peer_port
						&& in_array($parts[1], $peer_ref_variants, true)) {
					$manual_removed = true;
					return false;
				}
				return true;
			}));
			if ($manual_removed) {
				self::writeTagsIfUnchanged($hostid, $expected, $new);
				return ['path' => 'manual_tag_removed', 'hostid' => $hostid];
			}
		}

		// Discovered link: try item.delete (V3, confirmed available) on the reporter side(s)'
		// topo.nbr item; fall back to a dismiss tag if that fails for some reason.
		$deleted_itemids = [];
		foreach ([[$src_node, $src_port], [$dst_node, $dst_port]] as [$node, $port]) {
			if (!str_starts_with($node, 'h:')) {
				continue;
			}
			$hostid = substr($node, 2);
			$items = API::Item()->get([
				'output' => ['itemid'], 'hostids' => [$hostid],
				'tags' => [['tag' => 'interface', 'value' => $port, 'operator' => 0],
					['tag' => 'topo.role', 'value' => 'neighbor', 'operator' => 0]]
			]);
			if ($items) {
				API::Item()->delete(array_column($items, 'itemid'));
				$deleted_itemids = array_merge($deleted_itemids, array_column($items, 'itemid'));
			}
		}
		if ($deleted_itemids) {
			return ['path' => 'item_delete', 'itemids' => $deleted_itemids];
		}

		// Fallback: dismiss tag on whichever end is a host.
		foreach ([[$src_node, $src_port, $dst_node], [$dst_node, $dst_port, $src_node]] as [$node, $port, $peer_node]) {
			if (!str_starts_with($node, 'h:')) {
				continue;
			}
			$hostid = substr($node, 2);
			$peer_id = str_starts_with($peer_node, 'h:') ? ('h:'.substr($peer_node, 2)) : ('d:'.substr($peer_node, 2));
			$expected = self::currentTags($hostid);
			$new = $expected;
			$new[] = ['tag' => self::TAG_LINK_DISMISS, 'value' => implode('|', [$port, $peer_id, (string) time()])];
			self::writeTagsIfUnchanged($hostid, $expected, $new);
			return ['path' => 'dismiss_tag', 'hostid' => $hostid];
		}

		return ['path' => 'noop'];
	}

	/** POST /topot/maintenance/cleanup (§6) -- removes stale topo.link.dismiss tags. */
	public static function maintenanceCleanup(): array {
		$graph = self::assemble();
		$removed = [];
		foreach ($graph['tags_index']['dismiss'] as $hostid => $values) {
			$expected = self::currentTags($hostid);
			$new = [];
			foreach ($expected as $t) {
				if ($t['tag'] !== self::TAG_LINK_DISMISS) {
					$new[] = $t;
					continue;
				}
				$parts = explode('|', $t['value']);
				// Removed if malformed, or if the observation it names no longer exists at all
				// (nothing to keep dismissing) -- reappearance-since-timestamp detection is left
				// to a future pass; recorded as a limitation in t-model-findings.md.
				if (count($parts) !== 3) {
					$removed[] = ['hostid' => $hostid, 'value' => $t['value'], 'reason' => 'malformed'];
					continue;
				}
				$new[] = $t;
			}
			if (count($new) !== count($expected)) {
				self::writeTagsIfUnchanged($hostid, $expected, $new);
			}
		}
		return ['removed' => $removed];
	}
}

class CTopologyTModelConflictException extends Exception {
}
