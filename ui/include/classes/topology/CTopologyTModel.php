<?php

/**
 * CTopologyTModel — T-model topology assembler.
 *
 * Revised per the XWiki "Topology model (T-model: items + tags)" design (see the delivery
 * report for the full section-by-section mapping). Superseded from this class's first pass
 * (built against topology-t-model-prototype-spec.md): topo.self -> lldp.loc.chassis/
 * lldp.loc.name; topo.nbr[{#LOCIFINDEX},{#NBRKEY}] (hash-keyed) -> lldp.rem[{#LOCPORT},
 * "{#REM.CHASSIS}","{#REM.PORTID}"] (chassis/port-id-keyed, stable across a neighbor's
 * lldpRemIndex changing) + a parallel cdp.rem[...] rule; topo.peer.id (a pre-computed tag) ->
 * computed at READ TIME from topo.neighbor.chassis/mgmt_ip/name (canonicalPeerId()/
 * neighborCanonicalId()); a hardcoded 7-day staleness constant -> the {$TOPO.FRESHNESS} global
 * macro, with a documented default+warning fallback.
 *
 * Hard constraint 1 (spec §1): no topology tables, no cache, no state outliving one request.
 * Every method here is either a pure Zabbix API read (`*.get`) or, for the §6 write endpoints, a
 * read → modify → write of one host's own tags. `assemble()` is the pure function described in
 * §5 — it is re-run, from scratch, on every call. Do NOT memoize it across requests.
 *
 * Node ids are opaque strings, never raw integers:
 *   - a cluster bound to a Host -> "h:<hostid>"
 *   - an unbound cluster -> "d:<canonical cluster key>" ("c:<chassis>" / "m:<mgmt_ip>" /
 *     "s:<reporter>:<local_port>:<name>", in that priority order per §4.2)
 *   - a Port -> "<node id>/<port name>"
 * These are derived fresh every render, not stored — if a cluster's key changes, its id changes
 * with it; that is documented, expected behavior, not a bug to paper over with an id-mapping
 * table.
 *
 * `last_seen_<side>` = that side's neighbor item's own `lastclock`. `lost_<side>` =
 * `discoveryData.status = 1` (§5's explicit instruction to use `selectDiscoveryData`, not the
 * deprecated `selectItemDiscovery`) -- confirmed live during the T-model test campaign that
 * `status` is exactly the LLD lost/rediscovered boolean (0=currently discovered, 1=lost), not
 * something inferred from `ts_delete`/`ts_disable` (those only move once lifetime_type isn't
 * NEVER).
 */
class CTopologyTModel {

	private const TAG_ID = 'topo.id';
	private const TAG_UNBIND = 'topo.unbind';
	private const TAG_LINK_MANUAL = 'topo.link.manual';
	private const TAG_LINK_DISMISS = 'topo.link.dismiss';
	// Revised spec §4.6.3/§4.6.7/§4.7: a distinct, simpler mechanism from topo.link.dismiss
	// above (which is time-boxed and per-observation) -- topo.link.suppress's value is just the
	// local port name, and its effect is unconditional: that port's link never renders,
	// discovered or manual, until the tag is removed. Both mechanisms are kept side by side
	// (this task's "out of scope: don't change the d:/h: manual-link format" only protects that
	// one format, not this tag) since dismiss's timestamp-scoped semantics and suppress's
	// blanket-hide semantics serve genuinely different cases.
	private const TAG_LINK_SUPPRESS = 'topo.link.suppress';

	private const RAW_ITEM_KEY = 'topology.discovery.raw';
	private const LOC_CHASSIS_ITEM_KEY = 'lldp.loc.chassis';
	private const LOC_NAME_ITEM_KEY = 'lldp.loc.name';

	private const FRESHNESS_MACRO = '{$TOPO.FRESHNESS}';
	private const DEFAULT_FRESHNESS_SECONDS = 7 * 86400; // used only when the macro (§5.1) is missing

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

	// Revised spec §4.5: expand SHORT interface-name abbreviations to their long form (the
	// reverse direction of the old G-model normalization, which shortened long->short) so
	// "Gi0/1" and "GigabitEthernet0/1" compare equal, case-insensitively. One table, easy to
	// extend with more vendor abbreviations later.
	private const SHORT_TO_LONG_IFNAME = [
		'gi' => 'gigabitethernet',
		'te' => 'tengigabitethernet',
		'fa' => 'fastethernet',
		'eth' => 'ethernet'
	];

	private static function normalizePortName(string $name): string {
		$lower = strtolower(trim($name));
		foreach (self::SHORT_TO_LONG_IFNAME as $short => $long) {
			// Already in long form (e.g. "gigabitethernet0/1" also starts with "gi") -- leave
			// as-is rather than double-expanding.
			if (strncmp($lower, $long, strlen($long)) === 0) {
				return $lower;
			}
			// Abbreviation immediately followed by a digit (the slot number) -- "gi0/1", not
			// some unrelated word that happens to start with "gi".
			if (strncmp($lower, $short, strlen($short)) === 0
					&& strlen($lower) > strlen($short) && ctype_digit($lower[strlen($short)])) {
				return $long.substr($lower, strlen($short));
			}
		}
		return $lower;
	}

	/**
	 * Revised spec §4.5: "use topo.neighbor.port if it is an interface name, otherwise
	 * topo.neighbor.port_descr." Neither field's item tag carries the original
	 * lldpRemPortIdSubtype value (not in this revision's tag list, §1.1.3), so "is an interface
	 * name" is approximated here rather than known for certain: non-empty, not MAC-shaped, not
	 * purely numeric. Documented interpretation, not a literal subtype check -- flagged in the
	 * delivery report.
	 */
	private static function pickPortLabel(string $port, string $port_descr): string {
		$looks_like_name = $port !== '' && !self::looksLikeMac($port) && !ctype_digit($port);
		if ($looks_like_name) {
			return $port;
		}
		return $port_descr !== '' ? $port_descr : $port;
	}

	// Canonical peer id (T-model spec §4.1). $reporter_host/$local_if_index are only meaningful
	// for the sysname-fallback form and are ignored when $chassis_id is present.
	private static function canonicalPeerId(?string $chassis_id, ?string $sysname, string $reporter_host,
			?string $local_port): string {
		if ($chassis_id) {
			$id = 'c:'.strtolower($chassis_id);
		}
		else {
			$id = 's:'.$reporter_host.':'.($local_port ?? 'self').':'.($sysname ?? '');
		}
		return strlen($id) > 255 ? 'h:'.self::hexHash16($id) : $id;
	}

	/**
	 * Revised spec §4.2: neighbor identity priority is topo.neighbor.chassis, then
	 * topo.neighbor.mgmt_ip, then topo.neighbor.name scoped to (reporter, local port). The
	 * mgmt_ip tier gets its own "m:" prefix -- neither "c:" (chassis) nor "s:" (name, always
	 * reporter+port-scoped) fits a value that's meant to be globally comparable across
	 * reporters, the same way a real chassis id is. In practice this tier is rarely reached in
	 * this lab: push.py doesn't walk lldpRemManAddrTable (documented collector gap), so
	 * topo.neighbor.mgmt_ip is empty for every LLDP-sourced neighbor observed here -- the logic
	 * is implemented and correct, just not exercisable against real collected data in this
	 * environment.
	 */
	private static function neighborCanonicalId(?string $chassis, ?string $mgmt_ip, ?string $name,
			string $reporter_host, string $local_port): string {
		if ($chassis) {
			$id = 'c:'.strtolower($chassis);
		}
		elseif ($mgmt_ip) {
			$id = 'm:'.$mgmt_ip;
		}
		else {
			$id = 's:'.$reporter_host.':'.$local_port.':'.($name ?? '');
		}
		return strlen($id) > 255 ? 'h:'.self::hexHash16($id) : $id;
	}

	private static function looksLikeMac(?string $s): bool {
		return $s !== null && preg_match('/^([0-9a-f]{2}:){5}[0-9a-f]{2}$/i', $s) === 1;
	}

	/** Revised spec §1.2.2/§4.4.3: normalize a MAC for comparison -- lowercase, separators
	 * (':', '-', '.') stripped -- so "AA:BB:CC:DD:EE:FF", "aa-bb-cc-dd-ee-ff" and a bare
	 * "aabbccddeeff" all compare equal. */
	private static function normalizeMac(string $mac): string {
		return strtolower(preg_replace('/[^0-9a-f]/i', '', $mac));
	}

	// ---- topo.link.manual field escaping (per-field '|' and '\' are escaped as '\|'/'\\') ----

	private static function escapeLinkField(string $s): string {
		return str_replace(['\\', '|'], ['\\\\', '\\|'], $s);
	}

	/** Splits on unescaped '|', then unescapes each field. Field count is NOT validated here --
	 * callers check count(). */
	private static function splitEscapedPipe(string $raw): array {
		$fields = [];
		$current = '';
		$len = strlen($raw);
		for ($i = 0; $i < $len; $i++) {
			$ch = $raw[$i];
			if ($ch === '\\' && $i + 1 < $len) {
				$current .= $raw[$i + 1];
				$i++;
			}
			elseif ($ch === '|') {
				$fields[] = $current;
				$current = '';
			}
			else {
				$current .= $ch;
			}
		}
		$fields[] = $current;
		return $fields;
	}

	private static function joinEscapedPipe(array $fields): string {
		return implode('|', array_map([self::class, 'escapeLinkField'], $fields));
	}

	/**
	 * Splits a neighbor_ref ("d:<chassis_id>" / "h:<host name>") on the FIRST ':' only -- a MAC
	 * chassis id contains further colons ("d:aa:bb:cc:dd:ee:ff" -> prefix "d", value
	 * "aa:bb:cc:dd:ee:ff"). Returns null if there's no ':' at all or the value half is empty.
	 */
	private static function splitNeighborRef(string $ref): ?array {
		$pos = strpos($ref, ':');
		if ($pos === false) {
			return null;
		}
		$prefix = substr($ref, 0, $pos);
		$value = substr($ref, $pos + 1);
		return $value === '' ? null : [$prefix, $value];
	}

	/**
	 * Resolves a manual link's neighbor_ref to a node id, or null if it doesn't resolve to
	 * anything current (a "broken" reference -- the caller draws it differently, never drops it).
	 * 'd:<chassis_id>': matched first against a host's topo.id (bound), else against a currently
	 * observed but unbound cluster; 'h:<host name>': matched by exact technical host name.
	 */
	private static function resolveNeighborRef(string $prefix, string $value, array $cluster_of_id,
			array $bindings, array $chassis_to_hostid, array $host_by_name): ?string {
		if ($prefix === 'd') {
			$cluster_key = 'c:'.strtolower($value);
			if (isset($cluster_of_id[$cluster_key])) {
				$ck = $cluster_of_id[$cluster_key];
				$binding = $bindings[$ck] ?? null;
				return ($binding !== null && $binding['hostid'] !== null)
					? self::hostNodeId($binding['hostid'])
					: self::unboundNodeId($ck);
			}
			// Not currently observed at all this render -- still worth checking topo.id directly
			// (a manual topo.id can name a chassis nothing has reported this session).
			return isset($chassis_to_hostid[strtolower($value)])
				? self::hostNodeId($chassis_to_hostid[strtolower($value)])
				: null;
		}
		if ($prefix === 'h') {
			return isset($host_by_name[$value]) ? self::hostNodeId($host_by_name[$value]) : null;
		}
		return null; // unknown prefix
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

		// Step 1: reporter self items -- lldp.loc.chassis + lldp.loc.name (revised spec §1.1.5/
		// §1.3.3/§4.4.2), replacing the old single topo.self JSONPath item.
		$self_items = $step('lldp.loc.* items', static fn () => API::Item()->get([
			'output' => ['itemid', 'hostid', 'key_', 'lastvalue', 'lastclock'],
			'filter' => ['key_' => [self::LOC_CHASSIS_ITEM_KEY, self::LOC_NAME_ITEM_KEY]]
		]));

		// Step 2: neighbor items (topo.role=neighbor tag, both lldp.rem[...] and cdp.rem[...]
		// item prototypes carry it). selectDiscoveryData (not the deprecated selectItemDiscovery,
		// revised spec §5's explicit instruction) -- discoveryData.status is the real LLD
		// lost/rediscovered boolean (0=currently discovered, 1=lost); confirmed live during the
		// T-model test campaign that this is the correct field, not ts_delete/ts_disable (those
		// only move once lifetime_type is something other than NEVER).
		$neighbor_items = $step('neighbor items', static fn () => API::Item()->get([
			'output' => ['itemid', 'hostid', 'key_', 'lastvalue', 'lastclock'],
			'tags' => [['tag' => 'topo.role', 'value' => 'neighbor', 'operator' => 0]],
			'selectTags' => 'extend',
			'selectDiscoveryData' => ['status']
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

		// Freshness (§5.1): a global macro, not hardcoded -- read once per render (still one
		// extra, fixed-cost API call regardless of graph size, same batching discipline as
		// everything else here). Missing macro -> hardcoded default + a diagnostics warning the
		// UI surfaces, per the spec's explicit fallback instruction.
		$freshness_warning = null;
		$freshness_seconds = self::DEFAULT_FRESHNESS_SECONDS;
		$freshness_macros = $step('freshness macro', static fn () => API::UserMacro()->get([
			'globalmacro' => true, 'filter' => ['macro' => self::FRESHNESS_MACRO], 'output' => ['value']
		]));
		if ($freshness_macros) {
			$raw = $freshness_macros[0]['value'];
			// Zabbix macros of type "time" accept suffixes (30d, 12h); this endpoint doesn't
			// declare the macro's type for us, so parse the common suffixed forms by hand rather
			// than assuming a bare integer.
			if (preg_match('/^(\d+)([smhdw]?)$/i', trim($raw), $m)) {
				$unit_seconds = ['' => 1, 's' => 1, 'm' => 60, 'h' => 3600, 'd' => 86400, 'w' => 604800];
				$freshness_seconds = (int) $m[1] * $unit_seconds[strtolower($m[2])];
			}
			else {
				$freshness_warning = "{".self::FRESHNESS_MACRO."} value '{$raw}' is not a recognized ".
					"time value -- using the default (".self::DEFAULT_FRESHNESS_SECONDS." s) instead.";
			}
		}
		else {
			$freshness_warning = "{".self::FRESHNESS_MACRO."} is not defined -- using the default (".
				self::DEFAULT_FRESHNESS_SECONDS." s).";
		}

		return compact('self_items', 'neighbor_items', 'raw_items', 'hosts', 'diag',
			'freshness_seconds', 'freshness_warning');
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
		// Revised spec §1.1.5/§4.4.2: own identity now comes from TWO plain-text items
		// (lldp.loc.chassis, lldp.loc.name), not one JSON-valued topo.self item -- group by
		// hostid first.
		$self_raw_by_hostid = [];
		foreach ($self_items as $item) {
			$hostid = $item['hostid'];
			$field = $item['key_'] === self::LOC_CHASSIS_ITEM_KEY ? 'chassis_id' : 'sysname';
			$self_raw_by_hostid[$hostid][$field] = trim((string) $item['lastvalue']);
		}

		$self_by_hostid = [];
		foreach ($self_raw_by_hostid as $hostid => $self) {
			$chassis_id = $self['chassis_id'] ?? '';
			$sysname = $self['sysname'] ?? '';
			if ($chassis_id === '' && $sysname === '') {
				continue; // neither identity item has a value yet -- tolerate, nothing to cluster on.
			}
			$host = $hosts_by_id[$hostid]['host'] ?? $hostid;
			$id = self::canonicalPeerId($chassis_id !== '' ? $chassis_id : null, $sysname !== '' ? $sysname : null,
				$host, null);
			$self_by_hostid[$hostid] = ['chassis_id' => $chassis_id, 'sysname' => $sysname, 'id' => $id, 'host' => $host];

			$union($id, $id); // both c: and s: forms are valid cluster keys as-is
			$clusters[$id]['self_hostids'][] = $hostid;
		}

		// Revised spec §4.4.2 only specifies chassis/name for the REPORTER'S OWN identity (no
		// lldp.loc.mgmt_ip item) -- the old mgmt_ip-based self-cluster-merge rule (previous
		// prototype pass) has no data to run on any more and is dropped rather than kept as dead
		// code. mgmt_ip still participates in NEIGHBOR identity resolution (§4.2), just not here.
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
	private static function bindClusters(array $clusters, array $hosts_by_id, array $tags_index,
			array $chassis_type_by_chassis = []): array {
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

			// 3. mac (revised spec §1.2.2/§4.4.3: ONLY when the observing reporter's own
			// topo.neighbor.chassis_type tag said 'mac' -- not merely "looks MAC-shaped" by
			// regex. Both sides normalized (lowercase, separators stripped) before comparing,
			// since a Zabbix inventory MAC and an LLDP chassis id can use different separator
			// conventions for the identical physical address.)
			foreach ($cluster['ids'] as $id) {
				if (!str_starts_with($id, 'c:')) {
					continue;
				}
				$chassis = substr($id, 2);
				if (($chassis_type_by_chassis[strtolower($chassis)] ?? '') !== 'mac') {
					continue;
				}
				$normalized = self::normalizeMac($chassis);
				foreach ($tags_index['by_mac'][$normalized] ?? [] as $hostid) {
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
		$suppress_by_host = []; // hostid => [local port name, ...]
		$malformed = [];
		$chassis_to_hostid = []; // bare chassis (no 'c:' prefix) => hostid, for topo.id-based d: resolution
		$host_by_name = []; // technical host name => hostid, for h: resolution

		foreach ($hosts as $host) {
			$hostid = $host['hostid'];
			$host_by_name[$host['host']] = $hostid;
			foreach ($host['tags'] as $tag) {
				if ($tag['tag'] === self::TAG_ID) {
					if (str_contains($tag['value'], '|')) {
						$malformed[] = ['hostid' => $hostid, 'tag' => $tag['tag'], 'value' => $tag['value']];
						continue;
					}
					$by_topo_id[$tag['value']][] = $hostid;
					if (str_starts_with($tag['value'], 'c:')) {
						$chassis_to_hostid[strtolower(substr($tag['value'], 2))] = $hostid;
					}
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
				elseif ($tag['tag'] === self::TAG_LINK_SUPPRESS) {
					$suppress_by_host[$hostid][] = $tag['value'];
				}
			}
			foreach ([$host['inventory']['macaddress_a'] ?? '', $host['inventory']['macaddress_b'] ?? ''] as $mac) {
				if ($mac !== '') {
					// Normalized (lowercase, separators stripped) -- §1.2.2/§4.4.3's "normalize
					// MACs on both sides before comparing".
					$by_mac[self::normalizeMac($mac)][] = $hostid;
				}
			}
		}

		return compact('by_topo_id', 'by_mac', 'unbind_by_host', 'manual_links', 'dismiss', 'malformed',
			'chassis_to_hostid', 'host_by_name', 'suppress_by_host');
	}

	/**
	 * §5.4 — links. Groups neighbor items into (reporter_host, local_port) <-> (peer_node,
	 * peer_port) pairs, merges the two observations of one physical cable into a single link,
	 * then layers manual links on top per the priority rules.
	 */
	private static function buildLinks(array $neighbor_items, array $self_by_hostid, array $hosts_by_id,
			array $id_to_node, array $ports_by_hostid, array $cluster_of_id, array $bindings_by_cluster,
			array $tags_index, array &$conflicts, array $neighbor_id_by_itemid, int $freshness_seconds): array {
		$now = time();

		// side observations, keyed by "<node_id>/<port_name>" (this side's own port).
		$side_by_port_id = [];

		foreach ($neighbor_items as $item) {
			$tags = [];
			foreach ($item['tags'] as $t) {
				$tags[$t['tag']] = $t['value'];
			}
			$hostid = $item['hostid'];
			$local_port = $tags['interface'] ?? '';
			if ($local_port === '') {
				continue;
			}


			// Revised spec §4.5: prefer topo.neighbor.port when it looks like an interface
			// name, else topo.neighbor.port_descr (pickPortLabel()); this is the raw label to
			// resolve against the peer's own port inventory below -- not a pre-resolved value
			// from the collector any more.
			$port_raw = $tags['topo.neighbor.port'] ?? '';
			$port_descr_raw = $tags['topo.neighbor.port_descr'] ?? '';
			$peer_port_raw = self::pickPortLabel($port_raw, $port_descr_raw);
			if ($peer_port_raw === '') {
				continue; // §3.2: participant resolved, port didn't -- no link to draw (G-spec rule 1).
			}

			$reporter_node = self::hostNodeId($hostid);
			$peer_id = $neighbor_id_by_itemid[$item['itemid']] ?? '';
			if ($peer_id === '') {
				continue; // no chassis/mgmt_ip/name at all -- nothing to identify this participant by.
			}
			$peer_cluster_key = $cluster_of_id[$peer_id] ?? null;
			$peer_node = $peer_cluster_key !== null
				? ($id_to_node[$peer_cluster_key] ?? self::unboundNodeId($peer_cluster_key))
				: self::unboundNodeId($peer_id);

			// Resolve far port (§4.5): if the peer cluster contains a reporter, match against
			// that reporter's own ports (normalized, case-insensitive, abbreviation-expanded);
			// otherwise synthetic.
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

			// Revised spec §5: lost = discoveryData.status = 1 (the real LLD lost/rediscovered
			// flag, confirmed live during the T-model test campaign to mean exactly that -- not
			// ts_delete/ts_disable, which only move once lifetime_type isn't NEVER), last_seen =
			// the item's own lastclock.
			$lost = ($item['discoveryData']['status'] ?? '0') === '1';

			$this_port_id = self::portId($reporter_node, $local_port);
			$peer_port_id = self::portId($peer_node, $peer_port_name);

			$side_by_port_id[$this_port_id] = [
				'port_id' => $this_port_id, 'peer_port_id' => $peer_port_id,
				'last_seen' => (int) $item['lastclock'], 'lost' => $lost,
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
				'lost_src' => $src_side !== null ? ($src_side['lost'] ?? false) : null,
				'lost_dst' => $dst_side !== null ? ($dst_side['lost'] ?? false) : null,
				'stale' => $last_seen === null || ($now - $last_seen) > $freshness_seconds,
				'conflict' => false
			];
		}

		$links_by_port = [];
		foreach ($links as $i => $link) {
			$links_by_port[$link['src_port_id']][] = $i;
			$links_by_port[$link['dst_port_id']][] = $i;
		}

		// Manual links, layered on top (§5.4). Neighbor references are "d:<chassis_id>" (preferred)
		// or "h:<host name>" (technical name, fallback when no chassis id is known) -- portable
		// across export/import, unlike a raw hostid. Fields are '|'-delimited with '\'/'|'
		// escaping within a field (splitEscapedPipe/joinEscapedPipe).
		$invalid_manual = []; // hostid => [['raw'=>, 'reason'=>], ...]
		$broken_manual = []; // hostid => [['local_port'=>, 'ref'=>, 'peer_port'=>], ...]

		// First pass per host: detect "two manual links claim the same local port" independent of
		// whether either one resolves -- a raw-tag-level conflict, not a rendered-link one.
		$port_claims = []; // hostid => [local_port => count]
		foreach ($tags_index['manual_links'] as $hostid => $values) {
			foreach ($values as $raw) {
				$parts = self::splitEscapedPipe($raw);
				if (count($parts) === 3) {
					$port_claims[$hostid][$parts[0]] = ($port_claims[$hostid][$parts[0]] ?? 0) + 1;
				}
			}
		}

		foreach ($tags_index['manual_links'] as $hostid => $values) {
			$reporter_node = self::hostNodeId($hostid);
			foreach ($values as $raw) {
				$parts = self::splitEscapedPipe($raw);
				if (count($parts) !== 3) {
					$invalid_manual[$hostid][] = ['raw' => $raw, 'reason' => 'expected 3 fields, got '.count($parts)];
					$conflicts[] = ['type' => 'malformed_tag', 'hostid' => $hostid,
						'tag' => self::TAG_LINK_MANUAL, 'value' => $raw];
					continue;
				}
				[$local_port, $peer_ref, $peer_port] = $parts;
				$port_conflict = ($port_claims[$hostid][$local_port] ?? 0) > 1;

				$split_ref = self::splitNeighborRef($peer_ref);
				if ($split_ref === null || !in_array($split_ref[0], ['d', 'h'], true)) {
					$invalid_manual[$hostid][] = ['raw' => $raw, 'reason' => "unrecognized neighbor_ref '{$peer_ref}'",
						'local_port' => $local_port, 'conflict' => $port_conflict];
					$conflicts[] = ['type' => 'malformed_tag', 'hostid' => $hostid,
						'tag' => self::TAG_LINK_MANUAL, 'value' => $raw];
					continue;
				}
				[$ref_prefix, $ref_value] = $split_ref;
				$peer_node = self::resolveNeighborRef($ref_prefix, $ref_value, $cluster_of_id, $bindings_by_cluster,
					$tags_index['chassis_to_hostid'], $tags_index['host_by_name']);

				if ($peer_node === null) {
					// Broken, not dropped (per spec): the reference is well-formed but resolves
					// to nothing current (renamed host, chassis that's never been observed, etc).
					$broken_manual[$hostid][] = ['local_port' => $local_port, 'ref' => $peer_ref,
						'peer_port' => $peer_port, 'conflict' => $port_conflict];
					$conflicts[] = ['type' => 'broken_manual_link', 'hostid' => $hostid, 'local_port' => $local_port,
						'ref' => $peer_ref, 'message' => "manual link neighbor_ref '{$peer_ref}' does not resolve to any current host or observation"];
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
					if ($port_conflict) {
						$links[$discovered_same_pair]['conflict'] = true;
						$links[$discovered_same_pair]['conflict_reason'] = 'more than one topo.link.manual tag claims this local port';
					}
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
						'lost_src' => null, 'lost_dst' => null, 'stale' => false,
						'conflict' => $port_conflict,
						'conflict_reason' => $port_conflict ? 'more than one topo.link.manual tag claims this local port' : null
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

		// Revised spec §4.6.3: a topo.link.suppress tag on either end hides the link outright,
		// applied as a final filter here (not as an early skip on just one side's own
		// observation) -- a link can be fully asserted by the OTHER, unsuppressed side alone
		// (found live: suppressing only Router1's own Gi0/0 observation didn't stop Switch1's
		// reciprocal Gi0/24 observation from independently re-forming the same link), so
		// suppression has to be checked against BOTH ports of the final, merged link,
		// regardless of which side(s) actually asserted it or whether it's discovered or
		// manual.
		$suppressed_port_ids = [];
		foreach ($tags_index['suppress_by_host'] as $hostid => $ports) {
			foreach ($ports as $port) {
				$suppressed_port_ids[self::portId(self::hostNodeId($hostid), $port)] = true;
			}
		}
		if ($suppressed_port_ids) {
			$links = array_values(array_filter($links, static fn ($l) =>
				!isset($suppressed_port_ids[$l['src_port_id']]) && !isset($suppressed_port_ids[$l['dst_port_id']])));
		}

		return ['links' => $links, 'suppressed' => $suppressed, 'invalid_manual' => $invalid_manual,
			'broken_manual' => $broken_manual];
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
			'hosts' => $hosts, 'diag' => $diag, 'freshness_seconds' => $freshness_seconds,
			'freshness_warning' => $freshness_warning] = $fetched;

		$hosts_by_id = array_column($hosts, null, 'hostid');
		$tags_index = self::buildTagsIndex($hosts);

		$ports_by_hostid = [];
		foreach ($raw_items as $item) {
			$blob = json_decode((string) $item['lastvalue'], true);
			$ports_by_hostid[$item['hostid']] = is_array($blob) ? ($blob['ports'] ?? []) : [];
		}

		['clusters' => $clusters, 'cluster_of_id' => $cluster_of_id, 'self_by_hostid' => $self_by_hostid] =
			self::clusterParticipants($self_items, $hosts_by_id, $conflicts);

		// Revised spec §4.2: neighbor identity is computed HERE, at read time, from the raw
		// topo.neighbor.chassis/mgmt_ip/name tags -- there is no pre-computed topo.peer.id tag
		// in this revision (removed per the spec's explicit "remove all uses of
		// topo.neighbor.id" instruction, folded into "compute the id yourself instead of
		// trusting a stored one"). Computed once here and cached per itemid so buildLinks()
		// below reuses the exact same value rather than recomputing it a second time and
		// risking the two drifting apart.
		$neighbor_id_by_itemid = [];
		$chassis_type_by_chassis = []; // lowercase bare chassis -> 'mac'/'netaddr'/'ifname'/'local'/''
		$mgmt_ip_to_chassis_cluster = []; // mgmt_ip => [chassis cluster_key => true, ...]
		foreach ($neighbor_items as $item) {
			$tags = [];
			foreach ($item['tags'] as $t) {
				$tags[$t['tag']] = $t['value'];
			}
			$reporter_host = $hosts_by_id[$item['hostid']]['host'] ?? $item['hostid'];
			$local_port = $tags['interface'] ?? '';
			$chassis = $tags['topo.neighbor.chassis'] ?? '';
			$mgmt_ip = $tags['topo.neighbor.mgmt_ip'] ?? '';
			$name = $tags['topo.neighbor.name'] ?? '';
			if ($chassis === '' && $mgmt_ip === '' && $name === '') {
				continue; // nothing to identify this participant by at all
			}
			$id = self::neighborCanonicalId($chassis !== '' ? $chassis : null, $mgmt_ip !== '' ? $mgmt_ip : null,
				$name !== '' ? $name : null, $reporter_host, $local_port);
			$neighbor_id_by_itemid[$item['itemid']] = $id;

			if ($chassis !== '') {
				$bare = strtolower($chassis);
				$type = $tags['topo.neighbor.chassis_type'] ?? '';
				if ($type !== '' || !isset($chassis_type_by_chassis[$bare])) {
					$chassis_type_by_chassis[$bare] = $type;
				}
			}

			if (!isset($cluster_of_id[$id])) {
				$cluster_of_id[$id] = $id;
				if (!isset($clusters[$id])) {
					$clusters[$id] = ['ids' => [$id], 'self_hostids' => []];
				}
			}

			// An observation carrying BOTH a chassis id and an mgmt_ip ties the two together --
			// remembered so a DIFFERENT observation of the same physical device that only ever
			// gets mgmt_ip (no chassis) can still be recognized as the same device below,
			// rather than forming its own separate "m:"-keyed cluster forever. Without this, the
			// test scenario "one reporter sees chassis, another sees only mgmt_ip, for what's
			// actually the same box" would never converge to one device no matter how the
			// mgmt_ip-only side's own id is computed -- found live while testing this exact case.
			if ($chassis !== '' && $mgmt_ip !== '') {
				$mgmt_ip_to_chassis_cluster[$mgmt_ip][$id] = true;
			}
		}

		// Fold each "m:<ip>" cluster into the one chassis cluster that same ip was seen paired
		// with -- unless the ip was seen paired with more than one DIFFERENT chassis, in which
		// case merging would be a guess, not a fact (recorded as a conflict instead, same
		// posture as the old self mgmt_ip-merge rule this generalizes).
		foreach ($mgmt_ip_to_chassis_cluster ?? [] as $mgmt_ip => $chassis_clusters) {
			$mgmt_ip_cluster_key = 'm:'.$mgmt_ip;
			if (!isset($clusters[$mgmt_ip_cluster_key])) {
				continue; // nothing observed this mgmt_ip as its OWN (chassis-less) id -- nothing to fold.
			}
			if (count($chassis_clusters) > 1) {
				$conflicts[] = ['type' => 'mgmt_ip', 'mgmt_ip' => $mgmt_ip, 'clusters' => array_keys($chassis_clusters),
					'message' => "mgmt_ip {$mgmt_ip} was seen paired with more than one chassis id -- not merged."];
				continue;
			}
			$target_key = array_key_first($chassis_clusters);
			foreach ($clusters[$mgmt_ip_cluster_key]['ids'] as $alias_id) {
				$cluster_of_id[$alias_id] = $target_key;
				if (!in_array($alias_id, $clusters[$target_key]['ids'], true)) {
					$clusters[$target_key]['ids'][] = $alias_id;
				}
			}
			unset($clusters[$mgmt_ip_cluster_key]);
		}

		$bindings = self::bindClusters($clusters, $hosts_by_id, $tags_index, $chassis_type_by_chassis);

		// Display-name fallback for a cluster with no self observation of its own: its display
		// name is only ever known through *someone else's* neighbor observation of it
		// (topo.neighbor.name), same as the old tags-model's "topology.neighbor.N.name" fallback
		// for unmanaged nodes. First non-empty name any reporter has seen for this id wins.
		$peer_name_by_id = [];
		foreach ($neighbor_items as $item) {
			$pid = $neighbor_id_by_itemid[$item['itemid']] ?? '';
			if ($pid === '') {
				continue;
			}
			foreach ($item['tags'] as $t) {
				if ($t['tag'] === 'topo.neighbor.name' && $t['value'] !== '' && empty($peer_name_by_id[$pid])) {
					$peer_name_by_id[$pid] = $t['value'];
				}
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

		['links' => $links, 'suppressed' => $suppressed, 'invalid_manual' => $invalid_manual,
			'broken_manual' => $broken_manual] = self::buildLinks($neighbor_items, $self_by_hostid,
			$hosts_by_id, $id_to_node, $ports_by_hostid, $cluster_of_id, $bindings, $tags_index, $conflicts,
			$neighbor_id_by_itemid, $freshness_seconds);

		foreach ($links as $link) {
			if (!empty($link['conflict'])) {
				$conflicts[] = ['type' => 'port', 'src' => $link['src_port_id'], 'dst' => $link['dst_port_id'],
					'message' => $link['conflict_reason'] ?? 'link conflict'];
			}
		}

		$diag['conflicts'] = $conflicts;
		$diag['malformed_tags'] = $tags_index['malformed'];
		$diag['freshness_seconds'] = $freshness_seconds;
		$diag['freshness_warning'] = $freshness_warning; // §5.1: surfaced so the UI can show it
		$diag['invalid_manual_links'] = $invalid_manual;
		$diag['broken_manual_links'] = $broken_manual;
		$diag['counts'] = [
			'nodes' => count($nodes), 'links' => count($links),
			'lost_sides' => count(array_filter($links, static fn ($l) => ($l['lost_src'] ?? false) || ($l['lost_dst'] ?? false))),
			'silent_sides' => count(array_filter($links, static fn ($l) => $l['stale']))
		];

		return ['nodes' => $nodes, 'links' => $links, 'hosts_by_id' => $hosts_by_id, 'diag' => $diag,
			'tags_index' => $tags_index, 'cluster_of_id' => $cluster_of_id, 'clusters' => $clusters,
			'bindings' => $bindings, 'ports_by_hostid' => $ports_by_hostid, 'id_to_node' => $id_to_node,
			'invalid_manual' => $invalid_manual, 'broken_manual' => $broken_manual];
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

		$broken_by_port = [];
		foreach ($graph['broken_manual'][$hostid] ?? [] as $b) {
			$broken_by_port[$b['local_port']] = $b;
		}
		$invalid_by_port = [];
		foreach ($graph['invalid_manual'][$hostid] ?? [] as $inv) {
			if (isset($inv['local_port'])) {
				$invalid_by_port[$inv['local_port']] = $inv;
			}
		}

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
			$broken = $broken_by_port[$name] ?? null;
			$invalid = $invalid_by_port[$name] ?? null;

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
				'stale' => $link['stale'] ?? null, 'conflict' => $link['conflict'] ?? ($broken['conflict'] ?? $invalid['conflict'] ?? null),
				'itemid' => null, // filled in by the controller (§6: "/ports returns ... itemid" for E-B8)
				// A manual link that's well-formed but doesn't resolve to anything current is
				// shown here, not dropped -- 'manual_broken_ref' carries the raw unresolved
				// neighbor_ref for the side panel. A malformed tag (bad field count / unknown
				// prefix) shows as 'manual_invalid_raw' instead, with the raw tag value verbatim.
				'manual_broken_ref' => $broken['ref'] ?? null,
				'manual_invalid_raw' => $invalid['raw'] ?? null
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
		// 'hostid' (not just 'id') is required here: the shared frontend (monitoring.topology.
		// view.js.php, written against CTopologyPrototype::searchHosts()'s shape) reads
		// target_host.hostid directly when building a promote/link payload -- returning only
		// 'id' left that field undefined, so the ports.get follow-up call silently fetched
		// "id=h:undefined" and came back empty ("This host has no ports reported"), found live
		// via the browser UI, not by any of this class's own backend-level tests (none of them
		// exercise searchHosts()'s exact output shape against what the frontend reads from it).
		return array_map(static fn ($h) => ['id' => self::hostNodeId($h['hostid']), 'hostid' => $h['hostid'],
			'name' => $h['name']], $hosts);
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
	/**
	 * Preferred neighbor_ref for a manual link's peer, per the write rules: "d:<chassis_id>" if
	 * the peer has ANY known chassis id (even if it's also a bound host -- d: is always
	 * preferred over h: when available, since it's the more specific/portable identity), else
	 * "h:<host technical name>" for a bound host, else (an unbound peer with no chassis at
	 * all -- an s:-only observation) the raw internal id as a last resort, since there is
	 * neither a chassis nor a host name to reference. That last case is a real gap: an s:-only
	 * peer's identity is reporter+port-scoped by design (§4.1), so referencing it by that same
	 * scoped string is at least consistent, but isn't portable across export/import the way a
	 * real chassis id or host name is -- flagged, not silently pretended away.
	 */
	private static function preferredNeighborRef(string $peer_node, array $graph): string {
		if (str_starts_with($peer_node, 'h:')) {
			foreach ($graph['nodes'][$peer_node]['devices'] ?? [] as $d) {
				foreach ($d['ids'] as $id) {
					if (str_starts_with($id, 'c:')) {
						return 'd:'.substr($id, 2);
					}
				}
			}
			$hostid = substr($peer_node, 2);
			$host = $graph['hosts_by_id'][$hostid] ?? null;
			return 'h:'.($host['host'] ?? $hostid);
		}
		$cluster_key = substr($peer_node, 2); // "d:<cluster_key>" -- cluster_key is "c:..."/"s:..."
		return str_starts_with($cluster_key, 'c:') ? ('d:'.substr($cluster_key, 2)) : ('d:'.$cluster_key);
	}

	public static function linkPorts(string $src_port_id, string $dst_port_id): void {
		// Split against the current graph's own node id set, not a blind last-slash split -- a
		// port name routinely contains '/' itself (see splitPortId()'s own comment).
		$graph = self::assemble();
		$known_nodes = array_keys($graph['nodes']);
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
		$peer_ref = self::preferredNeighborRef($peer_node, $graph);

		$expected = self::currentTags($owner_hostid);
		foreach ($expected as $t) {
			if ($t['tag'] !== self::TAG_LINK_MANUAL) {
				continue;
			}
			$parts = self::splitEscapedPipe($t['value']);
			if (count($parts) === 3 && $parts[0] === $local_port) {
				throw new Exception("Port {$local_port} already has a manual link (to '{$parts[1]}') -- ".
					"remove it first (unlinkPorts) before creating a new one.");
			}
		}
		$new = $expected;
		$new[] = ['tag' => self::TAG_LINK_MANUAL, 'value' => self::joinEscapedPipe([$local_port, $peer_ref, $peer_port])];
		self::writeTagsIfUnchanged($owner_hostid, $expected, $new);
	}

	/** DELETE /ports/{src}/link/{dst} -- manual: drop the tag; discovered: item.delete (V3) or dismiss tag. */
	public static function unlinkPorts(string $src_port_id, string $dst_port_id): array {
		$graph = self::assemble();
		$known_nodes = array_keys($graph['nodes']);
		[$src_node, $src_port] = self::splitPortId($src_port_id, $known_nodes);
		[$dst_node, $dst_port] = self::splitPortId($dst_port_id, $known_nodes);

		foreach ([[$src_node, $src_port, $dst_node, $dst_port], [$dst_node, $dst_port, $src_node, $src_port]] as
				[$node, $port, $peer_node, $peer_port]) {
			if (!str_starts_with($node, 'h:')) {
				continue;
			}
			$hostid = substr($node, 2);
			$expected = self::currentTags($hostid);
			$manual_removed = false;
			// Match by RESOLVING each tag's neighbor_ref (same resolution buildLinks() uses for
			// reading), not by reconstructing an expected ref string -- the stored ref could be
			// either d: or h: form depending on what was known at write time, and that can drift
			// from what preferredNeighborRef() would compute now.
			$new = array_values(array_filter($expected, function ($t) use (&$manual_removed, $port, $peer_node,
					$peer_port, $graph) {
				if ($t['tag'] !== self::TAG_LINK_MANUAL) {
					return true;
				}
				$parts = self::splitEscapedPipe($t['value']);
				if (count($parts) !== 3 || $parts[0] !== $port || $parts[2] !== $peer_port) {
					return true;
				}
				$split_ref = self::splitNeighborRef($parts[1]);
				if ($split_ref === null) {
					return true;
				}
				$resolved = self::resolveNeighborRef($split_ref[0], $split_ref[1], $graph['cluster_of_id'],
					$graph['bindings'], $graph['tags_index']['chassis_to_hostid'], $graph['tags_index']['host_by_name']);
				if ($resolved === $peer_node) {
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
