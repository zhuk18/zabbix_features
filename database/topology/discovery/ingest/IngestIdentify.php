<?php

declare(strict_types=1);

/**
 * IngestIdentify — the step "Identify the far Device" (topology-1-model.md §6.0, §6.7.1, §6.7.4).
 * Input: a claim (the chassis key or the addresses and the name of a neighbor row, the local port, the reporter). Output: the Device it resolves to, `ambiguous`, or a new Device; the duplicate candidates and the chassis changes of the run.
 * State: the run (IngestRun): the stored keys, the claims of the ports, the reporters' addresses.
 */
class IngestIdentify {
	private IngestRun $run;

	public function __construct(IngestRun $run) {
		$this->run = $run;
	}

	public function resolveDevice(array $identity, bool $for_reporter = false, array $exclude = []): ?array {
		$pdo = $this->run->pdo;
		$key_holders = [$this->run->keys, 'keyHolders'];
		if (empty($identity['chassis'])) {
			return null;
		}
		[$value, $raw] = $identity['chassis'];
		$ids = $key_holders($value);
		if ($for_reporter || $exclude) {
			// §6.4 step 5: a reporter never takes a Device that has another representation, nor one that another reporter takes
			$ids = array_values(array_filter($ids, static function (int $id) use ($pdo, $for_reporter, $exclude): bool {
				if (in_array($id, $exclude, true)) {
					return false;
				}
				if ($for_reporter) {
					$rep = $pdo->prepare('SELECT represented_by_node_id FROM topo_nodes WHERE id = ?');
					$rep->execute([$id]);
					if ($rep->fetchColumn() !== null) {
						return false;
					}
				}
				return true;
			}));
		}
		if (count($ids) > 1) {
			return ['shared', 'chassis_id', $raw, $ids];
		}
		if ($ids) {
			return [$ids[0], 'chassis_id'];
		}
		// topology-1-model.md §6.7.0 "Replaced chassis": a chassis key that is marked replaced on exactly one Device finds that Device, after the lookup by the
		// normal keys found nothing (a neighbor that still advertises the old chassis does not make a ghost Device)
		$replaced = $key_holders($value, true);
		if (count($replaced) === 1) {
			return [$replaced[0], 'replaced_chassis'];
		}
		return null;
	}

	// topology-1-model.md "Far Device" (§6.7.1) and "Shared identifier" (§6.7.4), for the snapshot path (2.47): the identity key is the chassis only.
	//  - a chassis key on one Device finds it; on none, a replaced chassis key of exactly one Device finds that Device; on several Devices: shared, nothing is picked.
	//  - a reporter never takes a Device that has another representation, nor one that $exclude names.
	// $identity = ['chassis' => [key, raw value] | null]. Returns [device_id, 'chassis_id'], null (nothing found) or ['shared', 'chassis_id', value, node_ids].
	// The claims without a chassis are looked up by $find_chassisless_device (the match of §6.7.1), not here.
	public function noteShared(string $kind, string $value, array $node_ids): void {
		$summary = &$this->run->summary;
		foreach ($summary['identity_keys_shared']['list'] as $entry) {
			if ($entry['key'] === $kind && $entry['value'] === $value) {
				return;
			}
		}
		$summary['identity_keys_shared']['count']++;
		if (count($summary['identity_keys_shared']['list']) < 200) {
			$summary['identity_keys_shared']['list'][] = ['key' => $kind, 'value' => $value, 'node_ids' => array_map('intval', $node_ids)];
		}
	}

	// Device upsert for the snapshot path (2.47): a Device is found by its chassis key (a reporter's own chassis, or a claim with a chassis) or decided by the caller
	// ($incoming['found_device_id']: the match of §6.7.1 for a claim without a chassis); incoming values are merged over what the Device already has — one reporter's
	// NEIGHBORS row must not erase the vendor/sysname another source recorded — and last_seen only ever moves forward.
	public function device(array $incoming, ?int $local_port_id, int $seen_at, bool $for_reporter = false, array $exclude = [], bool $force_new = false): ?int {
		$pdo = $this->run->pdo;
		$resolve_device = [$this, 'resolveDevice'];
		$register_keys = [$this->run->keys, 'registerKeys'];
		$insert_key = [$this->run->keys, 'insertKey'];
		$note_shared = [$this, 'noteShared'];
		$insert_node = $this->run->insert_node;
		$snap_node_attrs = [$this->run->store, 'nodeAttrs'];
		$snap_write_attrs = [$this->run->store, 'writeAttrs'];
		$now = $this->run->now;
		$last_insert_id = [$this->run->store, 'lastInsertId'];
		$summary = &$this->run->summary;
		// $incoming carries the display attributes (mac, chassis_id, mgmt_ip, sysname, vendor) and the identity key of the row: chassis_key / chassis_raw (§6.7.0); for a new
		// Device without a chassis `scope_port` (§6.7.1).
		// $force_new: a reporter whose own key finds several Devices (§6.4 step 2, decided before pass A): the Device is made, nothing is looked up
		if (isset($incoming['found_device_id'])) {
			$match = [(int) $incoming['found_device_id'], 'match'];
		}
		else {
			$match = $force_new ? null : $resolve_device([
				'chassis' => isset($incoming['chassis_key']) ? [$incoming['chassis_key'], $incoming['chassis_raw'] ?? $incoming['chassis_key']] : null,
			], $for_reporter, $exclude);
		}
		if ($match !== null && $match[0] === 'shared') {
			$note_shared($match[1], $match[2], $match[3]);
			return null;            // topology-1-model.md "Shared identifier": nothing is created or changed
		}
		if ($match !== null) {
			[$device_id, $matched_by] = $match;
			$owner = $pdo->prepare('SELECT represented_by_node_id, represented_by_matched_by FROM topo_nodes WHERE id = ?');
			$owner->execute([$device_id]);
			$owner = $owner->fetch(PDO::FETCH_ASSOC);
			// The reporter's own data wins (topology-1-model.md §6.7.1, §6.4 step 6): a neighbor row refreshes a Device without a reporter, and on a
			// reporter's Device it only fills what is empty. The reporter writes its own values itself ($snap_ingest_reporter).
			$has_reporter = $owner['represented_by_matched_by'] === 'reporter_self' && $owner['represented_by_node_id'] !== null;
			$before = $snap_node_attrs($device_id);
			$attrs = $before;
			// Source priority (§6.7.1, 2.29), on a Device without a reporter: a value of a `lldp` row replaces the stored one; a value of another source (cdp, push)
			// fills an empty attribute, and replaces a value that another non-lldp row gave (among rows of one priority the last row wins, pass B order). The
			// stored value of an lldp row (or of an older run, which did not note the source) is never replaced by another source. `attrs.value_source` notes
			// the attributes whose stored value came from a non-lldp row.
			$row_source = (string) ($incoming['source'] ?? 'lldp');
			$value_source = (array) ($before['value_source'] ?? []);
			foreach (['mac', 'chassis_id', 'mgmt_ip', 'sysname', 'vendor'] as $key) {
				if (($incoming[$key] ?? null) === null || $incoming[$key] === '') {
					continue;
				}
				$stored_empty = ($before[$key] ?? '') === '' || ($key === 'vendor' && $before[$key] === 'unknown');
				if ($has_reporter && !$stored_empty) {
					continue;
				}
				if (!$has_reporter && !$stored_empty && $row_source !== 'lldp' && !isset($value_source[$key])) {
					continue;      // a stored lldp value (or one of unknown source) stays
				}
				// A stored stronger key is never replaced by a different value (topology-1-model.md "Far Device"); the MAC
				// is the chassis id spelled as a MAC, so it follows the chassis id.
				if (in_array($key, ['chassis_id', 'mac'], true) && ($before['chassis_id'] ?? '') !== ''
						&& ($incoming['chassis_id'] ?? null) !== $before['chassis_id']) {
					continue;
				}
				$attrs[$key] = $incoming[$key];
				if ($row_source !== 'lldp' && !$has_reporter && !($key === 'vendor' && $incoming[$key] === 'unknown')) {
					$value_source[$key] = $row_source;
				}
				else {
					unset($value_source[$key]);
				}
			}
			if ($value_source) {
				$attrs['value_source'] = $value_source;
			}
			else {
				unset($attrs['value_source']);
			}
			// attrs.last_seen of an existing Device is not moved here: it is set once at the end of the run from its sources (§8.7), `last_seen` of a new Device is below
			if ($snap_write_attrs($device_id, $attrs, $before)) {
				$summary['devices_updated']++;
			}
			$register_keys($device_id, $incoming, $seen_at);
			return $device_id;
		}
		$attrs = [
			'mac' => $incoming['mac'] ?? null, 'chassis_id' => $incoming['chassis_id'] ?? null,
			'mgmt_ip' => $incoming['mgmt_ip'] ?? null, 'sysname' => $incoming['sysname'] ?? '',
			'vendor' => $incoming['vendor'] ?? 'unknown', 'last_seen' => $seen_at,
		];
		if (isset($incoming['scope_port'])) {
			$attrs['scope_port'] = (int) $incoming['scope_port'];      // §6.7.1: the reporter's Port of a Device without a chassis; the match searches only the Devices of one port
		}
		if (($incoming['source'] ?? 'lldp') !== 'lldp') {
			// the values of a non-lldp row are noted as such (source priority, §6.7.1): a later lldp row replaces them, another source only fills
			foreach (['mac', 'chassis_id', 'mgmt_ip', 'sysname'] as $key) {
				if (($attrs[$key] ?? '') !== '') {
					$attrs['value_source'][$key] = $incoming['source'];
				}
			}
		}
		$insert_node->execute(['device', json_encode($attrs, JSON_THROW_ON_ERROR), $now, $now]);
		$summary['devices_created']++;
		$created_id = $last_insert_id();
		if (isset($incoming['chassis_key'])) {
			$insert_key($created_id, $incoming['chassis_key'], 'chassis', $seen_at);
		}
		return $created_id;
	}

	public function noteDuplicate(int $on, ?int $other, string $reason, string $key, string $value): void {
		$duplicate_candidates = &$this->run->duplicate_candidates;
		$duplicate_candidates[$on][$reason.'|'.($other ?? 0).'|'.$key.'|'.$value] = ['device_id' => $other, 'reason' => $reason, 'key' => $key, 'value' => $value];
	}

	public function noteChassisChange(int $device_id, string $chassis, string $key): void {
		$chassis_changes = &$this->run->chassis_changes;
		$chassis_changes[$device_id] = [$chassis, $key];
	}

	public function loadPortScope(): void {
		$pdo = $this->run->pdo;
		$port_scope = &$this->run->port_scope;
		if ($port_scope !== null) {
			return;
		}
		$port_scope = [];
		$known = [];
		foreach ($pdo->query("SELECT d.id, d.attrs FROM topo_nodes d WHERE d.type = 'device' AND d.represented_by_node_id IS NULL".
				" AND NOT EXISTS (SELECT 1 FROM topo_identity_keys k WHERE k.device_id = d.id AND k.kind = 'chassis')")->fetchAll(PDO::FETCH_ASSOC) as $row) {
			$attrs = json_decode($row['attrs'], true) ?: [];
			if (($attrs['chassis_id'] ?? '') !== '' || !isset($attrs['scope_port'])) {
				continue;
			}
			$addrs = [];
			if (is_string($attrs['mgmt_ip'] ?? null) && ($text = CTopologyAddress::ipText($attrs['mgmt_ip'])) !== null && CTopologyAddress::countsAsIdentity($text)) {
				$addrs[$text] = true;
			}
			$port_scope[(int) $attrs['scope_port']][(int) $row['id']] = ['addrs' => $addrs, 'name' => (string) ($attrs['sysname'] ?? '')];
			$known[(int) $row['id']] = (int) $attrs['scope_port'];
		}
		if ($known) {
			foreach ($pdo->query("SELECT o.device_id, o.remote_attrs FROM topo_observations o JOIN topo_nodes d ON d.id = o.device_id AND d.type = 'device'".
					" AND d.represented_by_node_id IS NULL WHERE NOT EXISTS (SELECT 1 FROM topo_identity_keys k WHERE k.device_id = d.id AND k.kind = 'chassis')")->fetchAll(PDO::FETCH_ASSOC) as $row) {
				if (isset($known[(int) $row['device_id']])) {
					foreach (CTopologyAddress::claimAddresses(json_decode((string) $row['remote_attrs'], true) ?: []) as $address) {
						$port_scope[$known[(int) $row['device_id']]][(int) $row['device_id']]['addrs'][$address] = true;
					}
				}
			}
		}
	}

	public function registerPortDevice(int $local_port_id, int $device_id, array $addrs, string $name): void {
		$load_port_scope = [$this, 'loadPortScope'];
		$port_scope = &$this->run->port_scope;
		$load_port_scope();
		$entry = &$port_scope[$local_port_id][$device_id];
		$entry ??= ['addrs' => [], 'name' => ''];
		foreach ($addrs as $address) {
			$entry['addrs'][$address] = true;
		}
		if ($name !== '') {
			$entry['name'] = $name;
		}
	}

	/**
	 * The Device of a claim without a chassis, by the rows of §6.7.1 in order. $claim = ['addrs' => [...], 'name' => text]. Returns ['found', Device id], ['ambiguous'] or
	 * ['new'] (the caller makes the Device with scope_port = the local port).
	 */
	public function findChassisless(int $hostid, string $segment, int $local_port_id, array $claim): array {
		$pdo = $this->run->pdo;
		$load_port_scope = [$this, 'loadPortScope'];
		$port_scope = &$this->run->port_scope;
		$port_chassis_claims = &$this->run->port_chassis_claims;
		$reporter_by_address = &$this->run->reporter_by_address;
		$reporter_device_of = &$this->run->reporter_device_of;
		// the SNMP interface address of exactly one reporter in the segment of the claim's reporter, and the names do not differ: the reporter has no name of its own
		// to compare (the sysname from its own data; the Zabbix host name is never used, 2.49), so the address decides; the predicate is the one of the other rows
		$reporters = [];
		foreach ($claim['addrs'] as $address) {
			$hosts = array_keys($reporter_by_address[$segment][$address] ?? []);
			if (count($hosts) === 1 && (int) $hosts[0] !== $hostid && CTopologyAddress::claimsMatch($claim, ['addrs' => [$address], 'name' => ''])) {
				$reporters[(int) $hosts[0]] = true;
			}
		}
		if (count($reporters) === 1 && isset($reporter_device_of[array_key_first($reporters)])) {
			return ['found', $reporter_device_of[array_key_first($reporters)]];
		}
		// the claims with a chassis on the same local port of the same reporter that match this claim resolve to exactly one Device (2.50: with two LLDP claims of two
		// Devices on the port, a claim that matches both takes neither: no guess)
		$matching = [];
		foreach ($port_chassis_claims[$hostid.'|'.$local_port_id] ?? [] as $on_port) {
			if ($on_port['device_id'] !== null && CTopologyAddress::claimsMatch($claim, $on_port)) {
				$matching[(int) $on_port['device_id']] = $on_port['device_id'];
			}
		}
		if (count($matching) === 1) {
			return ['found', reset($matching)];
		}
		// exactly one Device without a chassis with this scope_port that matches
		$load_port_scope();
		$found = [];
		foreach ($port_scope[$local_port_id] ?? [] as $device_id => $device) {
			if (CTopologyAddress::claimsMatch($claim, ['addrs' => array_keys($device['addrs']), 'name' => $device['name']])) {
				$found[] = $device_id;
			}
		}
		return count($found) === 1 ? ['found', $found[0]] : ($found ? ['ambiguous'] : ['new']);
	}
}
