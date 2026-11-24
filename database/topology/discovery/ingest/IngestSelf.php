<?php

declare(strict_types=1);

/**
 * IngestSelf — the step "Self" (topology-1-model.md §6.0, §6.4, §6.6) and the loading of the usable snapshots (§6.2).
 * Input: the usable snapshots of one reporter (PORTS, LAG, LEARNED_MACS rows, the NEIGHBORS rows for the reporter's own chassis). Output: the reporter's Device and its keys, its ports and links to its own Host, the claims of its chassis; the failed steps of a reporter.
 * State: the run (IngestRun).
 */
class IngestSelf {
	private IngestRun $run;
	/** itemid of a rule => its allowed age in seconds (read once per run, 2.96) */
	private array $allowed_ages = [];
	/** hostid => macro => value, the user macros that apply to the host (its own, those of its templates, the global ones) */
	private array $host_macros = [];

	public function __construct(IngestRun $run) {
		$this->run = $run;
	}

	public function loadReporters(array $host_filter): array {
		$pdo = $this->run->pdo;
		$snap_normalize_row = ['TopologyNormalize', 'row'];
		$summary = &$this->run->summary;
		$rows = $pdo->query(
			'SELECT s.itemid, s.hostid, s.topology_type, s.clock, s.rows_hash, s.rows_json, i.topology_type AS rule_topology_type, i.type AS rule_type,'.
			' i.status AS rule_status, h.status AS host_status, h.host, h.name AS host_name'.
			' FROM topo_lld_snapshot s JOIN items i ON i.itemid = s.itemid JOIN hosts h ON h.hostid = s.hostid'.
			' ORDER BY s.hostid, s.topology_type, s.itemid')->fetchAll(PDO::FETCH_ASSOC);
		$reporters = [];
		$transports = []; // hostid => topology type => source => transport => itemids
		foreach ($rows as $row) {
			if ($host_filter && !in_array($row['host'], $host_filter, true)) {
				continue;
			}
			// A usable snapshot belongs to an enabled rule on an enabled (monitored) host
			// (topology-1-model.md "Reporters and usable snapshots").
			if ((int) $row['rule_status'] !== 0 || (int) $row['host_status'] !== 0) {
				continue;
			}
			if ((int) $row['topology_type'] !== (int) $row['rule_topology_type']) {
				$summary['snapshots_ignored_type_mismatch']++;
				echo "SKIP: snapshot of rule #{$row['itemid']} was written for topology type {$row['topology_type']} but the rule now has ".
					"topology type {$row['rule_topology_type']}\n";
				continue;
			}
			$decoded = json_decode($row['rows_json'], true);
			$summary['snapshots_total']['snapshots']++;
			$summary['snapshots_total']['snapshot_bytes'] += strlen($row['rows_json']);
			$summary['snapshots_total']['snapshot_rows'] += is_array($decoded) ? count($decoded) : 0;
			if (!is_array($decoded)) {
				echo "SKIP: snapshot of rule #{$row['itemid']} holds invalid JSON\n";
				continue;
			}
			// One normalization for every transport (topology-1-model.md "Identity normalization"); a row that cannot be normalized is skipped.
			$normalized = [];
			foreach ($decoded as $snapshot_row) {
				[$snapshot_row, $problem] = is_array($snapshot_row) ? $snap_normalize_row($snapshot_row) : [null, 'not an object'];
				if ($snapshot_row === null) {
					$summary['rows_identity_invalid'][(int) $row['itemid']] = ($summary['rows_identity_invalid'][(int) $row['itemid']] ?? 0) + 1;
					echo "SKIP: a row of rule #{$row['itemid']} has an identity that cannot be normalized ({$problem})\n";
					continue;
				}
				$normalized[] = $snapshot_row;
			}
			$hostid = (int) $row['hostid'];
			$reporters[$hostid] ??= ['hostid' => $hostid, 'host' => $row['host'], 'name' => $row['host_name'],
				'snapshots' => []];
			// §6.2 (2.59): a NEIGHBORS snapshot whose clock is older than the run time minus the freshness threshold is old; each clock is compared with the run time only
			// 2.96 (§6.2, FR-B D-55): the age that the rule allows (its interval or heartbeat), never more than the freshness threshold
			$allowed_age = (int) $row['topology_type'] === TOPO_TYPE_NEIGHBORS ? $this->allowedAge((int) $row['itemid']) : $this->run->fresh_seconds;
			$old = (int) $row['topology_type'] === TOPO_TYPE_NEIGHBORS && (int) $row['clock'] < $this->run->now - $allowed_age;
			if ($old) {
				$summary['snapshots_old'][] = ['hostid' => $hostid, 'itemid' => (int) $row['itemid'], 'clock' => (int) $row['clock'], 'allowed_age' => $allowed_age];
			}
			$reporters[$hostid]['snapshots'][(int) $row['topology_type']][] = ['itemid' => (int) $row['itemid'],
				'clock' => (int) $row['clock'], 'rows' => $normalized, 'old' => $old, 'rows_hash' => (string) $row['rows_hash'], 'trapper' => (int) $row['rule_type'] === 2];
			// ITEM_TYPE_TRAPPER = 2; every other rule type (SNMP agent, dependent on an SNMP walk, ...) is the LLD transport.
			// The same data from two transports is what is reported: the same topology type and, for NEIGHBORS, the same protocol
			// (the row's source, 'lldp' when absent). LLDP by SNMP and CDP by push is not a duplicate. An empty snapshot
			// has no rows, so no known source: it is not compared.
			$transport = (int) $row['rule_type'] === 2 ? 'trapper' : 'snmp';
			if ((int) $row['topology_type'] === TOPO_TYPE_NEIGHBORS) {
				$sources = array_unique(array_map(static fn(array $r): string => (string) ($r['source'] ?? 'lldp'), $normalized));
			}
			else {
				$sources = [''];
			}
			foreach ($sources as $source) {
				$transports[$hostid][(int) $row['topology_type']][$source][$transport][] = (int) $row['itemid'];
			}
		}
		// One host, one topology type (and protocol), rules of both transports: both are processed, the host is reported
		// (topology-1-model.md "Duplicate transports").
		foreach ($transports as $hostid => $by_type) {
			foreach ($by_type as $topology_type => $by_source) {
				foreach ($by_source as $source => $by_transport) {
					if (count($by_transport) > 1) {
						$summary['hosts_duplicate_transport'][] = ['hostid' => $hostid, 'host' => $reporters[$hostid]['host'],
							'topology_type' => $topology_type, 'source' => $source, 'itemids' => $by_transport];
					}
				}
			}
		}
		return $reporters;
	}

	/**
	 * The allowed age of the snapshot of a rule (model §6.2 2.96): the base is the update interval of the rule, for a dependent rule the one of its first master that is not
	 * dependent; when the rule or that master has "Discard unchanged with heartbeat", the heartbeat instead. User macros are resolved on the host (its own, its templates, global);
	 * flexible and scheduling intervals are not used. No base (a trapper rule or master, an interval that does not resolve or is 0): the freshness threshold. Read once per run per rule.
	 * A database that is only a stand-in of the Zabbix tables (a test) has no base for any rule.
	 */
	public function allowedAge(int $itemid): int {
		if (isset($this->allowed_ages[$itemid])) {
			return $this->allowed_ages[$itemid];
		}
		$threshold = $this->run->fresh_seconds;
		try {
			$base = $this->ruleBase($itemid);
		}
		catch (PDOException $exception) {
			$base = null;
		}

		return $this->allowed_ages[$itemid] = topology_allowed_age($base, $threshold);
	}

	private function ruleBase(int $itemid): ?int {
		$pdo = $this->run->pdo;
		$item = $pdo->prepare('SELECT itemid, hostid, type, delay, master_itemid FROM items WHERE itemid = ?');
		$heartbeat = $pdo->prepare('SELECT params FROM item_preproc WHERE itemid = ? AND type = 20 ORDER BY step LIMIT 1');
		$heartbeat_of = function (int $id) use ($heartbeat): ?string {
			$heartbeat->execute([$id]);
			$value = $heartbeat->fetchColumn();

			return $value === false ? null : (string) $value;
		};
		$item->execute([$itemid]);
		if (($rule = $item->fetch(PDO::FETCH_ASSOC)) === false) {
			return null;
		}
		$hostid = (int) $rule['hostid'];
		$master = $rule;
		for ($depth = 0; (int) $master['type'] === 18 && $master['master_itemid'] !== null && $depth < 20; $depth++) {
			$item->execute([(int) $master['master_itemid']]);
			if (($master = $item->fetch(PDO::FETCH_ASSOC)) === false) {
				return null;
			}
		}
		if ((int) $master['type'] === 18) {
			return null;      // a chain that never ends in an item with its own interval
		}
		// the heartbeat: "Discard unchanged with heartbeat" on the rule, else on that master (the clock is accurate only to it, §5.5 rule 7)
		foreach ([(int) $rule['itemid'], (int) $master['itemid']] as $id) {
			if (($beat = $heartbeat_of($id)) !== null) {
				return topology_interval_seconds($this->resolveMacros($beat, $hostid));
			}
		}
		if ((int) $master['type'] === 2) {
			return null;      // a Zabbix trapper: no interval
		}

		return topology_interval_seconds($this->resolveMacros((string) $master['delay'], $hostid));
	}

	/** User macros in a text, resolved on the host: its own macros, then those of its templates (nearest first), then the global ones; an unresolved macro stays (the text is then no interval). */
	private function resolveMacros(string $text, int $hostid): string {
		if (!str_contains($text, '{$')) {
			return $text;
		}
		$macros = $this->hostMacros($hostid);

		return (string) preg_replace_callback('/\{\$[A-Z0-9_.]+\}/', static fn (array $m): string => $macros[$m[0]] ?? $m[0], $text);
	}

	private function hostMacros(int $hostid): array {
		if (isset($this->host_macros[$hostid])) {
			return $this->host_macros[$hostid];
		}
		$pdo = $this->run->pdo;
		$macros = [];
		$take = static function (array $rows) use (&$macros): void {
			foreach ($rows as [$macro, $value]) {
				$macros[$macro] ??= $value;      // the nearest definition wins
			}
		};
		$own = $pdo->prepare('SELECT macro, value FROM hostmacro WHERE hostid = ? AND type = 0');
		$links = $pdo->prepare('SELECT templateid FROM hosts_templates WHERE hostid = ?');
		$level = [$hostid];
		$seen = [$hostid => true];
		for ($depth = 0; $level && $depth < 20; $depth++) {
			$next = [];
			foreach ($level as $id) {
				$own->execute([$id]);
				$take($own->fetchAll(PDO::FETCH_NUM));
				$links->execute([$id]);
				foreach ($links->fetchAll(PDO::FETCH_COLUMN) as $template) {
					if (!isset($seen[(int) $template])) {
						$seen[(int) $template] = true;
						$next[] = (int) $template;
					}
				}
			}
			$level = $next;
		}
		$take($pdo->query('SELECT macro, value FROM globalmacro WHERE type = 0')->fetchAll(PDO::FETCH_NUM));

		return $this->host_macros[$hostid] = $macros;
	}

	/** The own identity a reporter's snapshots and host give (§6.4 steps 2-4, 6, 7): no database write. */
	public function reporterOwn(array $reporter): array {
		$pdo = $this->run->pdo;
		$segment_of = [$this->run->keys, 'segmentOf'];
		$chassis_key = [$this->run->keys, 'chassisKey'];
		$chassis_values = [];
		// 2.96, §6.4 step 8: the own identity of the DEVICE row(s) of the host: the chassis (or the CDP device ID in MAC form when the row has no chassis), the CDP device ID key, the name, the capabilities
		$device_chassis = [];
		$cdp_names = [];
		$cdp_keys = [];
		$sysnames = [];
		$sys_caps = [];
		foreach ($reporter['snapshots'][TOPO_TYPE_DEVICE] ?? [] as $snapshot) {
			foreach ($snapshot['rows'] as $row) {
				$row_chassis = null;
				if (($row['loc_chassis'] ?? '') !== '' && $chassis_key($row['loc_chassis'], $row['loc_chassis_type'] ?? null, $segment_of($reporter['hostid'])) !== null) {
					$row_chassis = [$row['loc_chassis'], $row['loc_chassis_type'] ?? null];
				}
				if (($row['loc_cdp_device_id'] ?? '') !== '') {
					$cdp = TopologyNormalize::cdpDeviceId((string) $row['loc_cdp_device_id']);
					if ($cdp[0] === 'mac' && $row_chassis === null) {
						$row_chassis = [$cdp[1], 'macAddress'];      // a CDP device ID in MAC form is a MAC hardware ID; a loc_chassis of the row wins (it is not a key)
					}
					elseif ($cdp[0] === 'key') {
						$cdp_keys[substr('cdp:'.$segment_of($reporter['hostid']).':'.$cdp[1], 0, 300)] = true;
						$cdp_names[(string) TopologyNormalize::clean((string) $row['loc_cdp_device_id'])] = true;
					}
				}
				if ($row_chassis !== null) {
					$device_chassis[$row_chassis[0]] = $row_chassis[1];
				}
				if (($row['loc_sysname'] ?? '') !== '') {
					$sysnames[(string) $row['loc_sysname']] = true;
				}
				if (($row['loc_sys_cap'] ?? '') !== '') {
					$sys_caps[(string) $row['loc_sys_cap']] = true;
				}
			}
		}
		if ($device_chassis) {
			$chassis_values = $device_chassis;      // step 2: the DEVICE snapshot first, the other snapshots only when it gives no loc_chassis
		}
		else {
			foreach ($reporter['snapshots'] as $list) {
				foreach ($list as $snapshot) {
					foreach ($snapshot['rows'] as $row) {
						if (($row['loc_chassis'] ?? '') !== '') {
							// 2.60, 2.62 (FR-A RC-3): a chassis id that is no hardware ID (the subtypes interfaceAlias, portComponent, interfaceName, an undefined subtype) is no loc_chassis: the
							// reporter's Device is found by its SNMP interface address
							if ($chassis_key($row['loc_chassis'], $row['loc_chassis_type'] ?? null, $segment_of($reporter['hostid'])) === null) {
								continue;
							}
							$chassis_values[$row['loc_chassis']] = $row['loc_chassis_type'] ?? null;
						}
					}
				}
			}
		}
		$clocks = array_merge(...array_map(static fn (array $list): array => array_column($list, 'clock'), array_values($reporter['snapshots'])));
		$own = ['skip' => null, 'chassis_values' => array_keys($chassis_values), 'seen' => max($clocks), 'loc_chassis' => null, 'mgmt_ip' => null,
			'segment' => $segment_of($reporter['hostid']), 'loc_key' => null,
			'cdp_key' => count($cdp_keys) === 1 ? array_key_first($cdp_keys) : null,      // two DEVICE rows with two names: no key (P4)
			'cdp_name' => count($cdp_names) === 1 ? array_key_first($cdp_names) : null,
			'sysname' => count($sysnames) === 1 ? array_key_first($sysnames) : null, 'sys_cap' => count($sys_caps) === 1 ? array_key_first($sys_caps) : null,
			'lookup_key' => null, 'lookup_kind' => 'chassis_id', 'lookup_raw' => null];
		if (count($chassis_values) > 1) {
			$own['skip'] = 'identity_ambiguous';
			return $own;
		}
		$own['loc_chassis'] = $chassis_values ? (string) array_key_first($chassis_values) : null;
		$interface = $pdo->prepare(
			'SELECT ip FROM interface WHERE hostid = ? AND type = 2 AND useip = 1 AND ip <> \'\' ORDER BY main DESC, interfaceid LIMIT 1');
		$interface->execute([$reporter['hostid']]);
		$own['mgmt_ip'] = $interface->fetchColumn() ?: null;
		$own['loc_key'] = $chassis_key($own['loc_chassis'], $own['loc_chassis'] === null ? null : $chassis_values[$own['loc_chassis']], $own['segment']);
		// the key that finds the reporter's Device in step 2: its chassis; without one its own CDP device ID key (2.96, §6.4 step 2)
		if ($own['loc_key'] !== null) {
			[$own['lookup_key'], $own['lookup_raw']] = [$own['loc_key'], (string) $own['loc_chassis']];
		}
		elseif ($own['cdp_key'] !== null) {
			[$own['lookup_key'], $own['lookup_kind'], $own['lookup_raw']] = [$own['cdp_key'], 'cdp_key', (string) $own['cdp_name']];
		}
		if ($own['loc_chassis'] === null && $own['cdp_key'] === null && $own['mgmt_ip'] === null) {
			$own['skip'] = 'identity_missing';
		}
		return $own;
	}

	/**
	 * Pass A, step 1: the reporter's Device (§6.4). $claimed = the chassis keys that two or more reporters claim by their own data: this reporter does
	 * not look for its Device by them, but its Device carries them (the key is then shared, §6.7.4). $key_shared = the reporter's own chassis finds several stored
	 * Devices (decided before pass A, §6.4 step 2): the reporter gets a Device of its own that carries the key. The SNMP interface address is not looked up (2.47).
	 */
	public function reporterIdentity(array $reporter, array $own, array $claimed, bool $key_shared = false): array {
		$q_key = $this->run->q_key;
		$pdo = $this->run->pdo;
		$snap_device = [$this->run->identify, 'device'];
		$note_chassis_change = [$this->run->identify, 'noteChassisChange'];
		$segment_of = [$this->run->keys, 'segmentOf'];
		$chassis_key = [$this->run->keys, 'chassisKey'];
		$ip_key = [$this->run->keys, 'ipKey'];
		$register_keys = [$this->run->keys, 'registerKeys'];
		$device_has_kind = [$this->run->keys, 'deviceHasKind'];
		$device_holds_key = [$this->run->keys, 'deviceHoldsKey'];
		$snap_port = [$this->run->store, 'port'];
		$snap_link = [$this->run->links, 'link'];
		$snap_other_links = [$this->run->store, 'otherLinks'];
		$snap_observation = [$this->run->store, 'observation'];
		$snap_remote_key = [$this->run->observe, 'remoteKey'];
		$snap_node_attrs = [$this->run->store, 'nodeAttrs'];
		$snap_write_attrs = [$this->run->store, 'writeAttrs'];
		$snap_if_type = [$this->run->farport, 'ifType'];
		$snap_status = [$this->run->farport, 'status'];
		$snap_link_reporter_self = [$this->run->links, 'linkReporterSelf'];
		$merge_pseudo_port = [$this->run->farport, 'mergePseudoPort'];
		$far_pseudo_name = [$this->run->farport, 'farPseudoName'];
		$pseudo_local_if_index = [$this->run->farport, 'pseudoLocalIfIndex'];
		$normalize_port_name = [$this->run->farport, 'normalizePortName'];
		$update_node = $this->run->update_node;
		$now = $this->run->now;
		$summary = &$this->run->summary;
		$convert_leftover_pseudo_ports = [$this->run->farport, 'convertLeftoverPseudoPorts'];
		$link_candidates = &$this->run->link_candidates;
		$neighbor_snapshots = &$this->run->neighbor_snapshots;
		$label = $reporter['host'];
		$loc_chassis = $own['loc_chassis'];
		$mgmt_ip = $own['mgmt_ip'];
		$loc_key = $own['loc_key'];
		$segment = $own['segment'];
		$reporter_seen = $own['seen'];
		if ($own['skip'] === 'identity_ambiguous') {
			echo "SKIP: reporter '{$label}' reports more than one loc_chassis (".implode(', ', $own['chassis_values']).
				") — identity is ambiguous, not picking one\n";
			return ['status' => 'skipped', 'reason' => 'identity_ambiguous'];
		}
		// the key this reporter looks its Device up by: its own chassis, unless another reporter claims it too
		$lookup_key = $own['lookup_key'];
		$look_chassis = $loc_key !== null && !isset($claimed[$loc_key]) ? $loc_key : null;
		// 2.96: a reporter without a chassis finds its Device by its own CDP device ID key, in the same way (§6.4 step 2)
		$look_cdp = $loc_key === null && $lookup_key !== null && !isset($claimed[$lookup_key]) ? $lookup_key : null;

		$existing = $pdo->prepare(
			"SELECT d.id FROM topo_nodes d JOIN topo_nodes h ON h.id = d.represented_by_node_id".
			" WHERE d.type = 'device' AND h.host_ref = ? AND d.represented_by_matched_by = 'reporter_self'");
		$existing->execute([$reporter['hostid']]);
		$device_id = $existing->fetchColumn();
		if ($device_id === false) {
			// the host is already the representation of a Device that an operator or a MAC match chose: that is the Device of this reporter
			$by_host = $pdo->prepare("SELECT d.id FROM topo_nodes h JOIN topo_nodes d ON d.represented_by_node_id = h.id".
				" WHERE h.type = 'host' AND h.host_ref = ? LIMIT 1");
			$by_host->execute([$reporter['hostid']]);
			$device_id = $by_host->fetchColumn();
		}

		if ($device_id === false) {
			if ($own['skip'] === 'identity_missing') {
				echo "SKIP: reporter '{$label}' has neither a loc_chassis nor an SNMP interface IP — nothing to make a Device of\n";
				return ['status' => 'skipped', 'reason' => 'identity_missing'];
			}
			// §6.4 step 2 and 5: found by its own unclaimed chassis among Devices that have no representation, else created (a reporter with only an SNMP interface
			// address gets a Device without a key); the Device carries the reporter's chassis, a claimed one too
			$device_id = $snap_device(['chassis_id' => $loc_chassis, 'mgmt_ip' => $mgmt_ip,
				'chassis_key' => $look_chassis, 'chassis_raw' => $loc_chassis, 'own_cdp_key' => $look_cdp, 'own_cdp_raw' => $own['lookup_raw'],
				'sysname' => $own['sysname'] ?? $reporter['name'], 'vendor' => 'unknown'], null, $reporter_seen, true, [], $key_shared);
			if ($device_id === null) {
				throw new RuntimeException("reporter '{$label}': no Device for its own identity");      // not reached: a reporter's key never stays shared
			}
			$register_keys((int) $device_id, ['chassis_key' => $loc_key], $reporter_seen, true);
			// The Device becomes this reporter's Device (§6.7.0 "Keys of a reporter's Device"): the chassis keys that neighbor rows gave it go, except the
			// reporter's own and the replaced ones. (A Device that already was somebody's - found by `represented_by` below - keeps its chassis: §6.4 step 1.)
			$pdo->prepare("DELETE FROM topo_identity_keys WHERE device_id = ? AND kind = 'chassis' AND replaced = 0".($loc_key !== null ? " AND {$q_key} <> ?" : ''))
				->execute($loc_key !== null ? [(int) $device_id, $loc_key] : [(int) $device_id]);
		}
		else {
			$device_id = (int) $device_id;
			$before = $snap_node_attrs($device_id);
			$attrs = $before;
			// a stored chassis id is never replaced by a different value (topology-1-model.md "Far Device")
			if ($loc_chassis !== null && ($before['chassis_id'] ?? '') === '') {
				$attrs['chassis_id'] = $loc_chassis;
			}
			// Chassis change (RMA, §6.4 step 1): the host reports chassis Y, S keeps X. The duplicate candidate `reporter_chassis_change` on S, and on
			// each Device C that has chassis Y (typically the Device the neighbors made for the new chassis); the operator decides (§6.7.5).
			// (the Devices C that have chassis Y are looked up at the end of the run: the neighbors that make them may be processed later)
			// S has a chassis when it holds a key of the chassis kind; the reported one is another when S does not hold that key.
			if ($loc_key !== null && $device_has_kind($device_id, 'chassis') && !$device_holds_key($device_id, $loc_key)) {
				$note_chassis_change($device_id, (string) $loc_chassis, $loc_key);
			}
			// the key the reporter gives its own Device: when S has no chassis yet, the reported one
			$register_keys($device_id, ['chassis_key' => $loc_key], $reporter_seen, true);
			if ($mgmt_ip !== null) {
				$attrs['mgmt_ip'] = $mgmt_ip;
			}
			$snap_write_attrs($device_id, $attrs, $before);      // attrs.last_seen is set at the end of the run (§8.7)
		}
		$device_id = (int) $device_id;
		$this->ownDeviceData($device_id, $own, $reporter_seen);
		$snap_link_reporter_self($device_id, (string) $reporter['hostid'], $label);

		return ['status' => 'ok', 'device_id' => $device_id, 'segment' => $segment];
	}

	/**
	 * The own data of the DEVICE row on the reporter's Device (model §6.4 steps 6 and 8, 2.96): `attrs.sysname` (own data wins), `attrs.sys_cap` (the own capabilities, §12.2) and the own
	 * CDP device ID key. The key follows the reporter's data: a new own CDP device ID replaces the old key of this Device (the device was renamed). A key that another Device without a
	 * host holds (made from CDP-only neighbor reports) is not taken: the candidate `reporter_cdp_key` goes on both (decided in the end of pass A, from the stored state); a key that a Device
	 * with a host or several Devices hold is shared (the reporter's own key is always on its Device, §6.7.4).
	 */
	public function ownDeviceData(int $device_id, array $own, int $seen_at): void {
		$snap_node_attrs = [$this->run->store, 'nodeAttrs'];
		$snap_write_attrs = [$this->run->store, 'writeAttrs'];
		$key_holders = [$this->run->keys, 'keyHolders'];
		$device_holds_key = [$this->run->keys, 'deviceHoldsKey'];
		$insert_key = [$this->run->keys, 'insertKey'];
		$note_shared = [$this->run->identify, 'noteShared'];
		$pdo = $this->run->pdo;
		$q_key = $this->run->q_key;
		$before = $snap_node_attrs($device_id);
		$attrs = $before;
		if ($own['sysname'] !== null) {
			$attrs['sysname'] = $own['sysname'];
		}
		if ($own['sys_cap'] !== null) {
			$attrs['sys_cap'] = $own['sys_cap'];
		}
		$snap_write_attrs($device_id, $attrs, $before);
		$cdp_key = $own['cdp_key'];
		if ($cdp_key === null || $device_holds_key($device_id, $cdp_key)) {
			return;
		}
		$others = array_values(array_diff($key_holders($cdp_key), [$device_id]));
		// the old own key goes: the key of a renamed device (the kind cdp of a reporter's Device comes from its own data only)
		$pdo->prepare("DELETE FROM topo_identity_keys WHERE device_id = ? AND kind = 'cdp' AND replaced = 0 AND {$q_key} <> ?")->execute([$device_id, $cdp_key]);
		if (count($others) === 1 && !$this->hasHost($others[0])) {
			return;      // two own keys, two Devices (step 8): no automatic merge; the candidate `reporter_cdp_key` is noted at the end of pass A
		}
		$insert_key($device_id, $cdp_key, 'cdp', $seen_at);
		if ($others) {
			$note_shared('cdp_key', (string) $own['cdp_name'], array_merge([$device_id], $others));
		}
	}

	/** has the Device a `represented_by` (a reporter or an operator's choice) */
	public function hasHost(int $device_id): bool {
		$stmt = $this->run->pdo->prepare('SELECT represented_by_node_id FROM topo_nodes WHERE id = ?');
		$stmt->execute([$device_id]);

		return $stmt->fetchColumn() !== null;
	}

	/** Pass A, step 2: PORTS, LAG and LEARNED_MACS of one reporter (§6.5). */
	public function reporterTables(array $reporter, int $device_id): array {
		$pdo = $this->run->pdo;
		$snap_device = [$this->run->identify, 'device'];
		$note_chassis_change = [$this->run->identify, 'noteChassisChange'];
		$segment_of = [$this->run->keys, 'segmentOf'];
		$chassis_key = [$this->run->keys, 'chassisKey'];
		$ip_key = [$this->run->keys, 'ipKey'];
		$register_keys = [$this->run->keys, 'registerKeys'];
		$device_has_kind = [$this->run->keys, 'deviceHasKind'];
		$device_holds_key = [$this->run->keys, 'deviceHoldsKey'];
		$snap_port = [$this->run->store, 'port'];
		$snap_link = [$this->run->links, 'link'];
		$snap_other_links = [$this->run->store, 'otherLinks'];
		$snap_observation = [$this->run->store, 'observation'];
		$snap_remote_key = [$this->run->observe, 'remoteKey'];
		$snap_node_attrs = [$this->run->store, 'nodeAttrs'];
		$snap_write_attrs = [$this->run->store, 'writeAttrs'];
		$snap_if_type = [$this->run->farport, 'ifType'];
		$snap_status = [$this->run->farport, 'status'];
		$snap_link_reporter_self = [$this->run->links, 'linkReporterSelf'];
		$merge_pseudo_port = [$this->run->farport, 'mergePseudoPort'];
		$far_pseudo_name = [$this->run->farport, 'farPseudoName'];
		$pseudo_local_if_index = [$this->run->farport, 'pseudoLocalIfIndex'];
		$normalize_port_name = [$this->run->farport, 'normalizePortName'];
		$update_node = $this->run->update_node;
		$now = $this->run->now;
		$summary = &$this->run->summary;
		$convert_leftover_pseudo_ports = [$this->run->farport, 'convertLeftoverPseudoPorts'];
		$link_candidates = &$this->run->link_candidates;
		$neighbor_snapshots = &$this->run->neighbor_snapshots;
		$snapshots = $reporter['snapshots'];

		// ---- PORTS ----
		// model §6.6 "Two PORTS rules of one host" (2.69): one list of port rows from the PORTS snapshots of the host; when two snapshots disagree and none is newer, the Ports do not change
		$port_decision = $this->portSnapshots($snapshots[TOPO_TYPE_PORTS] ?? []);
		if ($port_decision['unchanged']) {
			$summary['hosts_ports_disagree']['count']++;
			if (count($summary['hosts_ports_disagree']['list']) < 200) {
				$summary['hosts_ports_disagree']['list'][] = ['hostid' => (int) $reporter['hostid'], 'host' => (string) $reporter['host']];
			}
		}
		else {
			$this->followNames((int) $device_id, $port_decision['snapshots']);
		}
		foreach ($port_decision['unchanged'] ? [] : $port_decision['snapshots'] as $snapshot) {
			foreach ($snapshot['rows'] as $row) {
				$if_index = (int) $row['if_index'];
				$overlay = ['if_index' => $if_index, 'pseudo' => false];
				$row['name'] = ($row['name'] ?? '') !== '' ? $row['name'] : ($row['descr'] ?? '');       // the port's display name: ifName, else ifDescr (the step does not choose)
				foreach (['name', 'mac', 'speed'] as $key) {
					if (($row[$key] ?? '') !== '') {
						$overlay[$key] = $row[$key];
					}
				}
				if (array_key_exists('if_type', $row) || ($row['name'] ?? '') !== '') {
					$overlay['if_type'] = $snap_if_type((string) ($row['if_type'] ?? ''), (string) ($row['name'] ?? ''));
				}
				foreach (['admin_status', 'oper_status'] as $key) {
					if (array_key_exists($key, $row)) {
						$overlay[$key] = $snap_status((string) $row[$key]);
					}
				}
				// A port that is the target of a LAG membership stays typed "lag" even when its ifType says
				// otherwise (some vendors report port-channels as ethernetCsmacd); the LAG rule set it.
				$existing_id = $pdo->prepare("SELECT id FROM topo_nodes WHERE device_id = ? AND type = 'port'".
					" AND JSON_EXTRACT(attrs, '\$.if_index') = ?");
				$existing_id->execute([$device_id, $if_index]);
				if (($port_row = $existing_id->fetchColumn()) !== false) {
					$members = $pdo->prepare('SELECT COUNT(*) FROM topo_nodes WHERE lag_id = ?');
					$members->execute([$port_row]);
					if ((int) $members->fetchColumn() > 0) {
						unset($overlay['if_type']);
					}
				}
				$port_id = $snap_port($device_id, $if_index, $overlay);
				// model §9.7 "Host with a PORTS rule" (2.92, task 108): a manual Port that a row takes (by its bound index, or by its name, §6.6) is a reported port from now on: `source` is
				// reported, the binding (`interface_name`) is gone; its name, index, items and attrs are the row's, its manual links stay
				$taken = $snap_node_attrs($port_id);
				$became = $taken;
				if (($taken['source'] ?? null) === 'manual') {
					$became['source'] = 'reported';
					unset($became['interface_name']);
				}
				// model §6.6 (2.100, task 119): the port alias (ifAlias) is stored as the row gives it and is never the name, never a key; an empty or missing value clears it
				if (($row['alias'] ?? '') !== '') {
					$became['alias'] = (string) $row['alias'];
				}
				else {
					unset($became['alias']);
				}
				if ($became !== $taken) {
					$snap_write_attrs($port_id, $became, $taken);
				}
				$port_name = $snap_node_attrs($port_id)['name'] ?? '';
				if ($port_name !== '') {
					$merge_pseudo_port($device_id, $port_id, $port_name);
				}
			}
		}

		// A Device with real ports keeps no pseudo-Port made from an advertisement (see $convert_leftover_pseudo_ports).
		if (!empty($snapshots[TOPO_TYPE_PORTS]) && !$port_decision['unchanged']) {
			$convert_leftover_pseudo_ports($device_id);
		}

		// ---- LAG (only when the reporter has a LAG snapshot; otherwise no lag_id is ever touched) ----
		if (!empty($snapshots[TOPO_TYPE_LAG])) {
			$member_ids = [];
			foreach ($snapshots[TOPO_TYPE_LAG] as $snapshot) {
				foreach ($snapshot['rows'] as $row) {
					$member_index = (int) $row['if_index'];
					$lag_index = (int) $row['lag_if_index'];
					if ($member_index === $lag_index) {
						continue; // attached to itself = not aggregated
					}
					// The aggregate exists (typed "lag") before any member points at it — the lag_id invariant.
					$lag_port_id = $snap_port($device_id, $lag_index, ['if_index' => $lag_index, 'if_type' => 'lag',
						'pseudo' => false]);
					$member_id = $snap_port($device_id, $member_index, ['if_index' => $member_index, 'pseudo' => false]);
					$pdo->prepare('UPDATE topo_nodes SET lag_id = ? WHERE id = ? AND (lag_id IS NULL OR lag_id <> ?)')
						->execute([$lag_port_id, $member_id, $lag_port_id]);
					$member_ids[] = $member_id;
				}
			}
			// Silence overwrites: members of this Device that no longer appear in the snapshot leave the LAG.
			$sql = "UPDATE topo_nodes SET lag_id = NULL WHERE device_id = ? AND type = 'port' AND lag_id IS NOT NULL";
			$params = [$device_id];
			if ($member_ids) {
				$sql .= ' AND id NOT IN ('.implode(',', array_fill(0, count($member_ids), '?')).')';
				$params = array_merge($params, $member_ids);
			}
			$pdo->prepare($sql)->execute($params);
		}

		// ---- LEARNED_MACS (only when the reporter has such a snapshot) ----
		if (!empty($snapshots[TOPO_TYPE_LEARNED_MACS])) {
			$per_port = [];
			foreach ($snapshots[TOPO_TYPE_LEARNED_MACS] as $snapshot) {
				foreach ($snapshot['rows'] as $row) {
					$entry = &$per_port[(int) $row['if_index']];
					$entry ??= ['macs' => [], 'count' => null];
					if (($row['mac'] ?? '') !== '') {
						$entry['macs'][strtolower($row['mac'])] = true;
					}
					elseif (isset($row['port_mac_count'])) {
						$entry['count'] = max((int) $entry['count'], (int) $row['port_mac_count']);
					}
					unset($entry);
				}
			}
			foreach (array_keys($per_port) as $if_index) {
				$snap_port($device_id, $if_index, ['if_index' => $if_index, 'pseudo' => false]);
			}
			$ports = $pdo->prepare("SELECT id, attrs FROM topo_nodes WHERE device_id = ? AND type = 'port'");
			$ports->execute([$device_id]);
			foreach ($ports->fetchAll(PDO::FETCH_ASSOC) as $port_row) {
				$before = json_decode($port_row['attrs'], true, 512, JSON_THROW_ON_ERROR) ?: [];
				$attrs = $before;
				$entry = $per_port[(int) ($before['if_index'] ?? -1)] ?? null;
				unset($attrs['learned_mac_count']);
				if ($entry === null) {
					$attrs['learned_macs'] = [];
				}
				elseif ($entry['macs']) {
					$attrs['learned_macs'] = array_keys($entry['macs']);
					sort($attrs['learned_macs']);
				}
				else {
					$attrs['learned_macs'] = [];
					$attrs['learned_mac_count'] = $entry['count'];
				}
				$snap_write_attrs((int) $port_row['id'], $attrs, $before);
			}
		}


		return ['status' => 'ok'];
	}

	public function noteFailedReporter($hostid, array $reporter, string $step, string $error): void {
		$failed_hosts = &$this->run->failed_hosts;
		$frozen_itemids = &$this->run->frozen_itemids;
		$summary = &$this->run->summary;
		$failed_hosts[(int) $hostid] = true;
		foreach ($reporter['snapshots'][TOPO_TYPE_NEIGHBORS] ?? [] as $snapshot) {
			$frozen_itemids[(int) $snapshot['itemid']] = true;
		}
		$summary['reporters_failed']['count']++;
		if (count($summary['reporters_failed']['list']) < 200) {
			$summary['reporters_failed']['list'][] = ['hostid' => (int) $hostid, 'host' => (string) $reporter['host'], 'step' => $step, 'error' => substr($error, 0, 300)];
		}
	}
	/**
	 * A port follows its name (topology-1-model.md §6.6, 2.65): before the PORTS upsert, a row whose name is on one row of the snapshot and on exactly one stored real Port of the Device gives that
	 * Port its `if_index`. All changes of one snapshot are computed first and written together, so two ports that exchange their indexes do not collide. A stored Port whose index another Port takes
	 * and that gets no row itself keeps its id, name, links and decisions but loses `attrs.if_index` (the key is absent: no row resolves to it by ifIndex; a later row with its name gives it an index
	 * again). A row with an empty name, or a name on several rows or on several stored Ports, is upserted by `if_index` as before. Pseudo ports
	 * never take a row; a row whose index a pseudo port holds goes by `if_index` as before (the upsert makes that pseudo port the real one).
	 */
	public function followNames(int $device_id, array $snapshots): void {
		$store = $this->run->store;
		$row_name = [];      // if_index => the port name of the row (ifName, else ifDescr); rows of several rules join by if_index, the first value wins
		foreach ($snapshots as $snapshot) {
			foreach ($snapshot['rows'] as $row) {
				$index = (int) $row['if_index'];
				$name = ($row['name'] ?? '') !== '' ? (string) $row['name'] : (string) ($row['descr'] ?? '');
				if (!isset($row_name[$index]) || $row_name[$index] === '') {
					$row_name[$index] = $name;
				}
			}
		}
		if (!$row_name) {
			return;
		}
		$rows_with_name = [];
		foreach ($row_name as $index => $name) {
			if ($name !== '') {
				$rows_with_name[$name][] = $index;
			}
		}
		$stored = $this->run->pdo->prepare("SELECT id FROM topo_nodes WHERE device_id = ? AND type = 'port' ORDER BY id");
		$stored->execute([$device_id]);
		$ports = [];      // id => attrs of the stored Ports of the Device
		foreach ($stored->fetchAll(PDO::FETCH_COLUMN) as $id) {
			$ports[(int) $id] = $store->nodeAttrs((int) $id);
		}
		$real_by_name = [];
		$index_holder = [];      // if_index => [id] of the Ports (real or pseudo) that hold it
		foreach ($ports as $id => $attrs) {
			if (isset($attrs['if_index'])) {
				$index_holder[(int) $attrs['if_index']][] = $id;
			}
			if (empty($attrs['pseudo']) && ($attrs['name'] ?? '') !== '') {
				$real_by_name[(string) $attrs['name']][] = $id;
			}
		}
		$moves = [];      // port id => the new if_index
		foreach ($rows_with_name as $name => $indexes) {
			if (count($indexes) !== 1 || count($real_by_name[$name] ?? []) !== 1) {
				continue;      // the name is on several rows or on several stored Ports (or on none): by if_index, as before
			}
			$id = $real_by_name[$name][0];
			$index = $indexes[0];
			if (isset($ports[$id]['if_index']) && (int) $ports[$id]['if_index'] === $index) {
				continue;
			}
			foreach ($index_holder[$index] ?? [] as $holder) {
				if (!empty($ports[$holder]['pseudo'])) {
					continue 2;      // a pseudo port holds the index: the row goes by if_index as before
				}
			}
			$moves[$id] = $index;
		}
		if (!$moves) {
			return;
		}
		$taken = array_flip($moves);      // new if_index => port id
		foreach ($moves as $id => $index) {
			$attrs = $ports[$id];
			$attrs['if_index'] = $index;
			$store->writeAttrs($id, $attrs, $ports[$id]);
			$this->run->summary['ports_index_followed']++;
		}
		foreach ($ports as $id => $attrs) {
			if (isset($moves[$id]) || !isset($attrs['if_index']) || !isset($taken[(int) $attrs['if_index']]) || !empty($attrs['pseudo'])) {
				continue;
			}
			// the Port is gone (its name is not in the snapshot, or the name is not unique): it stays, without an index (and so without items: the view finds them by the index or the name, §3.2)
			unset($attrs['if_index']);
			$store->writeAttrs($id, $attrs, $ports[$id]);
			$this->run->summary['ports_index_dropped']++;
		}
	}
	/**
	 * Model §6.6 "Two PORTS rules of one host" (2.93; up to 2.92 the newer snapshot decided): the PORTS snapshots of one host as one list of port rows. The snapshots agree when each port name
	 * that both have has the same `if_index` in both and each `if_index` that both have has the same name: then they are returned as they are (the rows join by if_index). When they disagree,
	 * the Ports of the host do not change in this run (`unchanged`: no upsert, no "follows its name"), and the run summary lists the host (`hosts_ports_disagree`); there is no choice by time
	 * (FR-A 5.7 R-11, R-16). A host has two PORTS rules only when a user links two templates with PORTS rules (the official interface templates have no topology type, FR-B 0.29 D-49).
	 *
	 * @return array  ['snapshots' => the snapshots to use, 'unchanged' => bool]
	 */
	public function portSnapshots(array $snapshots): array {
		if (count($snapshots) < 2) {
			return ['snapshots' => $snapshots, 'unchanged' => false];
		}
		$name_of = static fn (array $row): string => ($row['name'] ?? '') !== '' ? (string) $row['name'] : (string) ($row['descr'] ?? '');
		$maps = [];      // per snapshot: name => if_index, if_index => name
		foreach ($snapshots as $k => $snapshot) {
			foreach ($snapshot['rows'] as $row) {
				$name = $name_of($row);
				if ($name !== '') {
					$maps[$k]['by_name'][$name] = (int) $row['if_index'];
				}
				$maps[$k]['by_index'][(int) $row['if_index']] = $name;
			}
		}
		$agree = true;
		foreach (array_keys($snapshots) as $a) {
			foreach (array_keys($snapshots) as $b) {
				if ($a >= $b) {
					continue;
				}
				foreach ($maps[$a]['by_name'] ?? [] as $name => $index) {
					if (isset($maps[$b]['by_name'][$name]) && $maps[$b]['by_name'][$name] !== $index) {
						$agree = false;
					}
				}
				foreach ($maps[$a]['by_index'] ?? [] as $index => $name) {
					if (isset($maps[$b]['by_index'][$index]) && $name !== '' && $maps[$b]['by_index'][$index] !== '' && $maps[$b]['by_index'][$index] !== $name) {
						$agree = false;
					}
				}
			}
		}
		if ($agree) {
			return ['snapshots' => $snapshots, 'unchanged' => false];
		}
		// 2.93 (FR-A 5.7 R-11, R-16): no choice by time; the Ports of the host do not change in this run (the next poll of either rule usually ends it)
		return ['snapshots' => $snapshots, 'unchanged' => true];
	}
}
