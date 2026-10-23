<?php

declare(strict_types=1);

/**
 * IngestObserve — the step "Observe" (topology-1-model.md §6.0, §6.7.6) with the claims of pass B.
 * Input: the NEIGHBORS rows of one reporter (normalized, sorted). Output: for each row the local port, the claim (remote key and attributes), the far Device (through IngestIdentify) and the link candidate or the observation.
 * State: the run (IngestRun): the link candidates and the neighbor snapshots of the run.
 */
class IngestObserve {
	private IngestRun $run;

	public function __construct(IngestRun $run) {
		$this->run = $run;
	}

	/** Model §6.7.6: [if_index, null] or [null, reason] for a NEIGHBORS row with `loc_port_num`; $ports = PORTS rows [if_index, name, descr, mac] (cleaned) */
	public function resolveLocalPort(array $row, array $ports): array {
		$norm_clean = ['TopologyNormalize', 'clean'];
		$norm_name_key = ['TopologyNormalize', 'nameKey'];
		$norm_numeric_id = ['TopologyNormalize', 'numericId'];
		$norm_port_mac = ['TopologyNormalize', 'portMac'];
		if (isset($row['if_index']) && $row['if_index'] !== '') {
			return [(int) $row['if_index'], null];
		}
		if (!$ports) {
			return [null, 'no_ports'];
		}
		$by_index = [];
		foreach ($ports as $port) {
			$by_index[(int) $port['if_index']] = $port;
		}
		$id = isset($row['loc_port_id']) ? (string) $row['loc_port_id'] : null;
		$type = (string) ($row['loc_port_id_type'] ?? '');
		// step 1: by the subtype of the port id
		if ($id !== null && $id !== '') {
			if ($type === 'interfaceName') {
				$hits = array_keys(array_filter($ports, static fn (array $p): bool => ($p['name'] ?? null) !== null && $norm_clean((string) $p['name']) === $norm_clean($id)));
				if (count($hits) === 1) {
					return [(int) $ports[$hits[0]]['if_index'], null];
				}
			}
			elseif ($type === 'macAddress') {
				$hit = $norm_port_mac(strtolower($id), array_map(static fn (array $p): string => strtolower((string) ($p['mac'] ?? '')), $ports));
				if ($hit !== null) {
					return [(int) $ports[$hit]['if_index'], null];
				}
			}
			elseif ($type === 'local') {
				$number = $norm_numeric_id($id);
				if ($number !== null && isset($by_index[$number])) {
					return [$number, null];
				}
			}
		}
		// step 2: id, then description, against name, then descr; the cleaned names first, then the name key
		$several = false;
		foreach ([$norm_clean, $norm_name_key] as $compare) {
			foreach ([['loc_port_id', 'name'], ['loc_port_id', 'descr'], ['loc_port_desc', 'name'], ['loc_port_desc', 'descr']] as [$from, $to]) {
				$value = isset($row[$from]) ? $compare((string) $row[$from]) : null;
				if ($value === null) {
					continue;
				}
				$hits = array_keys(array_filter($ports, static fn (array $p): bool => isset($p[$to]) && $compare((string) $p[$to]) === $value));
				if (count($hits) === 1) {
					return [(int) $ports[$hits[0]]['if_index'], null];
				}
				$several = $several || count($hits) > 1;
			}
		}
		if ($several) {
			return [null, 'name_on_several_ports'];
		}
		// step 3: the port number as an ifIndex
		if (isset($row['loc_port_num']) && $norm_numeric_id((string) $row['loc_port_num']) !== null && isset($by_index[$norm_numeric_id((string) $row['loc_port_num'])])) {
			return [$norm_numeric_id((string) $row['loc_port_num']), null];
		}
		return [null, 'if_index_missing'];
	}

	// remote_key: the strongest identity present, prefixed with its kind, so the same neighbor seen by the same
	// rule on the same port always maps to the same row.
	public function remoteKey(array $row, string $segment): string {
		$chassis_key = [$this->run->keys, 'chassisKey'];
		$ip_key = [$this->run->keys, 'ipKey'];
		// the key string of §6.7.0: the same neighbor seen by the same rule on the same port maps to the same row
		if (($key = $chassis_key($row['rem_chassis'] ?? null, $row['rem_chassis_type'] ?? null, $segment)) !== null) {
			return $key;
		}
		if (($key = $ip_key($row['rem_mgmt_ip'] ?? null, $segment)) !== null) {
			return $key;
		}
		return substr('sysname:'.($row['rem_sysname'] ?? ''), 0, 300);
	}

	/** Pass B: the NEIGHBORS rows of one reporter, sorted (§6.5, §6.8). */
	public function reporterNeighbors(array $reporter, int $device_id, string $segment): array {
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
		$snap_remote_key = [$this, 'remoteKey'];
		$resolve_local_port = [$this, 'resolveLocalPort'];
		$is_placeholder_mac = ['TopologyNormalize', 'isPlaceholderMac'];
		$find_chassisless_device = [$this->run->identify, 'findChassisless'];
		$register_port_device = [$this->run->identify, 'registerPortDevice'];
		$port_chassis_claims = &$this->run->port_chassis_claims;
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

		// ---- NEIGHBORS, pass B (topology-1-model.md §6.5, §6.8 "Collect"): the rows of this reporter in the sorted order (if_index, remote_key, itemid);
		// the reporters come in hostid order, so all rows of all reporters are processed in one sorted order ----
		// Devices and remote ports are resolved and created, but no link and no observation is written here: whether a candidate link may take its
		// port depends on what EVERY reporter's latest snapshot says, so the decision is made once, after all reporters, by $snap_resolve_links().
		$sorted_rows = [];
		// the reporter's ports from its PORTS snapshots (pass A): rows of several rules join by if_index, the first value of a field wins
		$ports = [];
		foreach ($snapshots[TOPO_TYPE_PORTS] ?? [] as $snapshot) {
			foreach ($snapshot['rows'] as $row) {
				$port = &$ports[(int) $row['if_index']];
				$port ??= ['if_index' => (int) $row['if_index']];
				foreach (['name', 'descr', 'mac'] as $field) {
					if (($row[$field] ?? '') !== '' && !isset($port[$field])) {
						$port[$field] = $row[$field];
					}
				}
				unset($port);
			}
		}
		$ports = array_values($ports);
		foreach ($snapshots[TOPO_TYPE_NEIGHBORS] ?? [] as $snapshot) {
			$neighbor_snapshots[$snapshot['itemid']] = ['itemid' => $snapshot['itemid'], 'clock' => $snapshot['clock'], 'hostid' => $reporter['hostid']];
			foreach ($snapshot['rows'] as $row) {
				[$resolved, $reason] = $resolve_local_port($row, $ports);
				if ($resolved === null) {
					$summary['rows_local_port_unresolved']['count']++;
					$summary['rows_local_port_unresolved']['by_reason'][$reason]++;
					echo "SKIP: a row of rule #{$snapshot['itemid']} has no local port ({$reason})\n";
					continue;
				}
				$row['if_index'] = $resolved;
				$sorted_rows[] = [(int) $row['if_index'], $snap_remote_key($row, $segment), (int) $snapshot['itemid'], (int) $snapshot['clock'], $row];
			}
		}
		// Within one local port the claims with a chassis come before the claims without (rank 0, 1, §6.5), so the same-port rule of §6.7.1 finds the claim with a
		// chassis first. A remote key `chassis:...` is a claim with a chassis; the rank states it, so a new key prefix cannot change it.
		$sort_key = static fn (array $r): array => [$r[0], str_starts_with($r[1], 'chassis:') ? 0 : 1, $r[1], $r[2]];
		usort($sorted_rows, static fn (array $a, array $b): int => $sort_key($a) <=> $sort_key($b));
		$claims_seen = [];
		foreach ($sorted_rows as [, $row_remote_key, $itemid, $clock, $row]) {
			$local_port_id = $snap_port($device_id, (int) $row['if_index'], ['if_index' => (int) $row['if_index'],
				'pseudo' => false], ['name' => $row['if_name'] ?? null]);
			// Rows of one snapshot with the same claim key (rule, local port, remote key): the first row in the row order makes the observation, the others are
			// counted per rule and give no Device, no observation (§7.1; the claim key of the table is (itemid, local_port_id, remote_key)).
			$claim_key = $itemid.'|'.$local_port_id.'|'.$row_remote_key;
			if (isset($claims_seen[$claim_key])) {
				$summary['rows_duplicate_claim']['count']++;
				$summary['rows_duplicate_claim']['by_rule'][$itemid] = ($summary['rows_duplicate_claim']['by_rule'][$itemid] ?? 0) + 1;
				continue;
			}
			$claims_seen[$claim_key] = true;

			$chassis_id = ($row['rem_chassis'] ?? '') !== '' ? $row['rem_chassis'] : null;
			if ($chassis_id !== null && in_array($row['rem_chassis_type'] ?? null, [null, '', 'macAddress'], true) && $is_placeholder_mac($chassis_id)) {
				$chassis_id = null;     // a placeholder MAC is no identity (normalization §4.1): no chassis key, no chassis_id/mac attribute; $remote_attrs keeps the value as received
			}
			$looks_like_mac = $chassis_id !== null && preg_match('/^([0-9a-f]{2}:){5}[0-9a-f]{2}$/i', $chassis_id);
			$remote_attrs = array_filter(array_intersect_key($row, array_flip(['rem_chassis', 'rem_chassis_type',
				'rem_mgmt_ip', 'rem_man_addrs', 'rem_sysname', 'rem_port', 'rem_port_type', 'rem_port_desc', 'source'])),
				static fn ($value): bool => $value !== '' && $value !== null);
			$source = ($row['source'] ?? 'lldp') !== '' ? (string) ($row['source'] ?? 'lldp') : 'lldp';

			$row_ip = ($row['rem_mgmt_ip'] ?? '') !== '' ? $row['rem_mgmt_ip'] : null;
			// model §6.7.1: a claim with no chassis, no address and no name (for example only a placeholder MAC chassis) makes no Device, no observation and no link; it is
			// counted. An address that never matches (unspecified, loopback, link-local: §6.7.1 "Match") is no address here: a Device made for such a claim could never be found
			// by the next run (a second Device every run, P1), so a claim whose only identity is such an address counts as having none.
			if ($chassis_key($chassis_id, $row['rem_chassis_type'] ?? null, $segment) === null && CTopologyAddress::claimAddresses($remote_attrs) === [] && ($row['rem_sysname'] ?? '') === '') {
				$summary['rows_no_identity']['count']++;
				$summary['rows_no_identity']['by_rule'][$itemid] = ($summary['rows_no_identity']['by_rule'][$itemid] ?? 0) + 1;
				continue;
			}
			$row_chassis_key = $chassis_key($chassis_id, $row['rem_chassis_type'] ?? null, $segment);
			$incoming = [
				'mac' => $looks_like_mac ? strtolower($chassis_id) : null,
				'chassis_id' => $chassis_id,
				'mgmt_ip' => $row_ip,
				'chassis_key' => $row_chassis_key,
				'chassis_raw' => $chassis_id,
				'sysname' => $row['rem_sysname'] ?? null,
				'vendor' => 'unknown',
				'source' => $source,
			];
			$claim_addrs = CTopologyAddress::claimAddresses($remote_attrs);
			if ($row_chassis_key === null) {
				// a claim without a chassis: the match of §6.7.1 (the reporter's SNMP address, the claim with a chassis on the same port, the Devices of this port)
				$found = $find_chassisless_device((int) $reporter['hostid'], $segment, $local_port_id, ['addrs' => $claim_addrs, 'name' => (string) ($row['rem_sysname'] ?? '')]);
				if ($found[0] === 'ambiguous') {
					$remote_device_id = null;          // several Devices of the port match: the observation is `ambiguous`, no Device, no link
				}
				else {
					if ($found[0] === 'found') {
						$incoming['found_device_id'] = $found[1];
					}
					else {
						$incoming['scope_port'] = $local_port_id;
					}
					$remote_device_id = $snap_device($incoming, $local_port_id, $clock);
					if ($remote_device_id !== null) {
						$register_port_device($local_port_id, $remote_device_id, $claim_addrs, (string) ($snap_node_attrs($remote_device_id)['sysname'] ?? ''));
					}
				}
			}
			else {
				$remote_device_id = $snap_device($incoming, $local_port_id, $clock);
				// a claim with a chassis: the claims without one on this port may join it (the claims of one port come in rank order, §6.5)
				$port_chassis_claims[$reporter['hostid'].'|'.$local_port_id][] = ['device_id' => $remote_device_id, 'addrs' => $claim_addrs,
					'name' => (string) ($row['rem_sysname'] ?? ''), 'chassis' => (string) $chassis_id];
			}
			// a shared identifier (null): the observation is `ambiguous`, no Device, no link, nothing else touched

			$has_remote_port = ($row['rem_port_desc'] ?? '') !== '' || ($row['rem_port'] ?? '') !== '';
			// The far port is decided in phase 2 ($snap_classify_candidates), once every reporter's own ports are known:
			// whether the advertisement names a real port, a pseudo one, or none (topology-1-model.md "Port-level or device-level").
			$candidate = ['hostid' => $reporter['hostid'], 'itemid' => $itemid, 'clock' => $clock, 'source' => $source,
				'local_port_id' => $local_port_id, 'remote_device_id' => $remote_device_id,
				'remote_key' => $snap_remote_key($row, $segment), 'remote_attrs' => $remote_attrs,
				'pre_outcome' => $remote_device_id === null ? 'ambiguous' : ($has_remote_port ? null : 'device_only'), // topology-1-model.md "Observations": device_only, no port advertised, no link
				'level' => 'port', 'far_port_reason' => null, 'remote_port_id' => null,
				'port_label' => $has_remote_port ? $far_pseudo_name($row + ['source' => $source]) : null,
				'rem_port_desc' => (string) ($row['rem_port_desc'] ?? ''),
				'pseudo_index' => $has_remote_port ? $pseudo_local_if_index($row['rem_port'] ?? null,
					$row['rem_port_type'] ?? null) : null,
				'pseudo_key' => $has_remote_port ? $normalize_port_name($far_pseudo_name($row + ['source' => $source])) : null,
				'rem_port' => (string) ($row['rem_port'] ?? ''), 'rem_port_type' => (string) ($row['rem_port_type'] ?? ''),
				'far_port_hint' => array_filter(array_intersect_key($row, array_flip(['rem_port', 'rem_port_desc',
					'rem_port_type'])), static fn ($value): bool => $value !== '' && $value !== null),
				'pseudo_mac' => $looks_like_mac ? strtolower($chassis_id) : null];

			$link_candidates[] = $candidate;
		}

		return ['status' => 'ok'];
	}
}
