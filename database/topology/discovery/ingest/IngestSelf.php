<?php

declare(strict_types=1);

/**
 * IngestSelf — the step "Self" (topology-1-model.md §6.0, §6.4, §6.6) and the loading of the usable snapshots (§6.2).
 * Input: the usable snapshots of one reporter (PORTS, LAG, LEARNED_MACS rows, the NEIGHBORS rows for the reporter's own chassis). Output: the reporter's Device and its keys, its ports and links to its own Host, the claims of its chassis; the failed steps of a reporter.
 * State: the run (IngestRun).
 */
class IngestSelf {
	private IngestRun $run;

	public function __construct(IngestRun $run) {
		$this->run = $run;
	}

	public function loadReporters(array $host_filter): array {
		$pdo = $this->run->pdo;
		$snap_normalize_row = ['TopologyNormalize', 'row'];
		$summary = &$this->run->summary;
		$rows = $pdo->query(
			'SELECT s.itemid, s.hostid, s.topology_type, s.clock, s.rows_json, i.topology_type AS rule_topology_type, i.type AS rule_type,'.
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
			$reporters[$hostid]['snapshots'][(int) $row['topology_type']][] = ['itemid' => (int) $row['itemid'],
				'clock' => (int) $row['clock'], 'rows' => $normalized];
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

	/** The own identity a reporter's snapshots and host give (§6.4 steps 2-4, 6, 7): no database write. */
	public function reporterOwn(array $reporter): array {
		$pdo = $this->run->pdo;
		$segment_of = [$this->run->keys, 'segmentOf'];
		$chassis_key = [$this->run->keys, 'chassisKey'];
		$chassis_values = [];
		foreach ($reporter['snapshots'] as $list) {
			foreach ($list as $snapshot) {
				foreach ($snapshot['rows'] as $row) {
					if (($row['loc_chassis'] ?? '') !== '') {
						$chassis_values[$row['loc_chassis']] = true;
					}
				}
			}
		}
		$clocks = array_merge(...array_map(static fn (array $list): array => array_column($list, 'clock'), array_values($reporter['snapshots'])));
		$own = ['skip' => null, 'chassis_values' => array_keys($chassis_values), 'seen' => max($clocks), 'loc_chassis' => null, 'mgmt_ip' => null,
			'segment' => $segment_of($reporter['hostid']), 'loc_key' => null];
		if (count($chassis_values) > 1) {
			$own['skip'] = 'identity_ambiguous';
			return $own;
		}
		$own['loc_chassis'] = $chassis_values ? (string) array_key_first($chassis_values) : null;
		$interface = $pdo->prepare(
			'SELECT ip FROM interface WHERE hostid = ? AND type = 2 AND useip = 1 AND ip <> \'\' ORDER BY main DESC, interfaceid LIMIT 1');
		$interface->execute([$reporter['hostid']]);
		$own['mgmt_ip'] = $interface->fetchColumn() ?: null;
		$own['loc_key'] = $chassis_key($own['loc_chassis'], null, $own['segment']);
		if ($own['loc_chassis'] === null && $own['mgmt_ip'] === null) {
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
		$look_chassis = $loc_key !== null && !isset($claimed[$loc_key]) ? $loc_key : null;

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
				'chassis_key' => $look_chassis, 'chassis_raw' => $loc_chassis,
				'sysname' => $reporter['name'], 'vendor' => 'unknown'], null, $reporter_seen, true, [], $key_shared);
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
		$snap_link_reporter_self($device_id, (string) $reporter['hostid'], $label);

		return ['status' => 'ok', 'device_id' => $device_id, 'segment' => $segment];
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
		// zabbix_itemids (topology-1-model.md §3.2): the items the rule's own LLD run made from the row (`itemids`, set by the server). A host can have two
		// PORTS rules (the topology template and the standard interface discovery): their rows join by if_index, the ids come from the one with item
		// prototypes (the other has []). The list is replaced by every run; a row without the field (an older snapshot) has none.
		$port_items = [];
		foreach ($snapshots[TOPO_TYPE_PORTS] ?? [] as $snapshot) {
			foreach ($snapshot['rows'] as $row) {
				$port_items[(int) $row['if_index']] = array_values(array_unique(array_merge($port_items[(int) $row['if_index']] ?? [],
					array_map('intval', is_array($row['itemids'] ?? null) ? $row['itemids'] : []))));
				sort($port_items[(int) $row['if_index']]);
			}
		}
		if ($port_items && !array_filter($port_items)) {
			$summary['hosts_ports_without_items']['count']++;
			if (count($summary['hosts_ports_without_items']['list']) < 200) {
				$summary['hosts_ports_without_items']['list'][] = ['hostid' => (int) $reporter['hostid'], 'host' => (string) $reporter['host']];
			}
		}
		foreach ($snapshots[TOPO_TYPE_PORTS] ?? [] as $snapshot) {
			foreach ($snapshot['rows'] as $row) {
				$if_index = (int) $row['if_index'];
				$overlay = ['if_index' => $if_index, 'pseudo' => false, 'zabbix_itemids' => $port_items[$if_index] ?? []];
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
				$port_name = $snap_node_attrs($port_id)['name'] ?? '';
				if ($port_name !== '') {
					$merge_pseudo_port($device_id, $port_id, $port_name);
				}
			}
		}

		// A Device with real ports keeps no pseudo-Port made from an advertisement (see $convert_leftover_pseudo_ports).
		if (!empty($snapshots[TOPO_TYPE_PORTS])) {
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
}
