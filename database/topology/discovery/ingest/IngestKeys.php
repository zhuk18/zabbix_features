<?php

declare(strict_types=1);

/**
 * IngestKeys — identity keys and segments (topology-1-model.md §6.7.0).
 * Input: a Device id, a chassis value and type, a reporter's host id. Output: key strings, the Devices that hold a key, rows of topo_identity_keys, counters of the run summary.
 * State: the run (IngestRun): pdo, now, q_key, run_segments, summary.
 */
class IngestKeys {
	private IngestRun $run;

	public function __construct(IngestRun $run) {
		$this->run = $run;
	}

	public function segmentOf($hostid): string {
		$pdo = $this->run->pdo;
		$run_segments = &$this->run->run_segments;
		$hostid = (int) $hostid;
		if (!array_key_exists($hostid, $run_segments)) {
			$segment = 'server';
			try {
				$stmt = $pdo->prepare('SELECT monitored_by, proxyid, proxy_groupid FROM hosts WHERE hostid = ?');
				$stmt->execute([$hostid]);
				if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
					if ((int) $row['monitored_by'] === 2 && $row['proxy_groupid'] !== null) {
						$segment = 'pg:'.$row['proxy_groupid'];
					}
					elseif ((int) $row['monitored_by'] === 1 && $row['proxyid'] !== null) {
						$segment = 'proxy:'.$row['proxyid'];
					}
				}
			}
			catch (PDOException $exception) {
				// a database without the monitoring assignment columns (a test stand-in): everything is in the segment `server`
			}
			$run_segments[$hostid] = $segment;
		}
		return $run_segments[$hostid];
	}

	public function chassisKey(?string $value, ?string $type, string $segment): ?string {
		$mac_like = ['TopologyNormalize', 'macLike'];
		$is_placeholder_mac = ['TopologyNormalize', 'isPlaceholderMac'];
		if ($value === null || $value === '') {
			return null;
		}
		if (in_array($type, [null, '', 'macAddress'], true) && $mac_like($value)) {
			return $is_placeholder_mac($value) ? null : 'chassis:mac:'.strtolower($value);      // a placeholder MAC gives no chassis key (normalization §4.2)
		}
		return substr('chassis:'.($type === null || $type === '' ? 'local' : $type).':'.$segment.':'.$value, 0, 300);
	}

	// The scoped sysname key (§6.7.0, 2.31): the reporter's local Port (its topo_nodes id, so it names the reporter too) and the sysname as received.
	public function ipKey(?string $addr, string $segment): ?string {
		return $addr === null || $addr === '' ? null : substr('ip:'.$segment.':'.$addr, 0, 300);
	}

	/** Devices that hold a key (replaced keys only when asked for), ordered by id. */
	public function keyHolders(string $key, bool $replaced = false): array {
		$pdo = $this->run->pdo;
		$q_key = $this->run->q_key;
		// only keys of Devices that exist (a key row whose Device was removed without the cascade, for example by a reset with the foreign keys off, is nothing)
		$stmt = $pdo->prepare("SELECT k.device_id FROM topo_identity_keys k JOIN topo_nodes d ON d.id = k.device_id".
			" WHERE k.{$q_key} = ? AND k.replaced = ? ORDER BY k.device_id");
		$stmt->execute([$key, $replaced ? 1 : 0]);
		return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
	}

	public function deviceHasKind(int $device_id, string $kind): bool {
		$pdo = $this->run->pdo;
		$stmt = $pdo->prepare('SELECT 1 FROM topo_identity_keys WHERE device_id = ? AND kind = ? LIMIT 1');
		$stmt->execute([$device_id, $kind]);
		return $stmt->fetchColumn() !== false;
	}

	public function deviceHoldsKey(int $device_id, string $key): bool {
		$pdo = $this->run->pdo;
		$q_key = $this->run->q_key;
		$stmt = $pdo->prepare("SELECT 1 FROM topo_identity_keys WHERE device_id = ? AND {$q_key} = ?");
		$stmt->execute([$device_id, $key]);
		return $stmt->fetchColumn() !== false;
	}

	public function insertKey(int $device_id, string $key, string $kind, int $seen_at, bool $replaced = false): void {
		$pdo = $this->run->pdo;
		$q_key = $this->run->q_key;
		$pdo->prepare("INSERT INTO topo_identity_keys (device_id, {$q_key}, kind, first_seen, replaced) VALUES (?, ?, ?, ?, ?)")
			->execute([$device_id, $key, $kind, $seen_at, $replaced ? 1 : 0]);
	}

	// The keys a row or a reporter gives to the Device it found or created. A Device keeps the chassis it has (a stored stronger key is never replaced by a
	// different value); a key another Device holds is not copied (it would make the value shared; the duplicate candidate is recorded elsewhere).
	public function registerKeys(int $device_id, array $incoming, int $seen_at, bool $by_reporter = false): void {
		$pdo = $this->run->pdo;
		$q_key = $this->run->q_key;
		$key_holders = [$this, 'keyHolders'];
		$device_has_kind = [$this, 'deviceHasKind'];
		$device_holds_key = [$this, 'deviceHoldsKey'];
		$insert_key = [$this, 'insertKey'];
		if (!$by_reporter) {
			// §6.4 step 6, §6.7.1: a neighbor row never adds a key to a reporter's Device, in any segment; the reporter writes its own keys
			$reporter = $pdo->prepare("SELECT 1 FROM topo_nodes WHERE id = ? AND represented_by_matched_by = 'reporter_self' AND represented_by_node_id IS NOT NULL");
			$reporter->execute([$device_id]);
			if ($reporter->fetchColumn() !== false) {
				return;
			}
		}
		// 2.47 (§6.7.0): the identity key is the chassis only; addresses and names are attributes
		$key = $incoming['chassis_key'] ?? null;
		if ($key === null || $device_holds_key($device_id, $key) || $device_has_kind($device_id, 'chassis')) {
			return;
		}
		// the reporter's own key is always on its Device, even when another Device holds it (then the key is shared, §6.4 step 5)
		if (!$by_reporter && array_diff($key_holders($key), [$device_id])) {
			return;
		}
		$insert_key($device_id, $key, 'chassis', $seen_at);
	}

	// Identity keys of the stored Devices at the start of a run (§6.7.0):
	//  1. Orphan key rows (no Device) are counted and removed.
	//  2. Upgrade to 2.47: the identity keys are the chassis only. A Device without a chassis (no chassis attribute, no chassis key, no association) gets `attrs.scope_port`
	//     from its former sysname key (`sysname:<port id>:<name>`, the newest), else from the local port of its latest observation, else none; then the key rows of kind
	//     `ip` and `sysname` are deleted. The Devices stay. Idempotent: with no such key row and every chassis-less Device either with a scope_port or without any
	//     observation, a run changes nothing here.
	//  3. Devices that hold no chassis key (data written before the key table) get it from their attributes, in the segment of their reporter, else of the reporter of
	//     their latest observation. A MAC chassis is global and needs no segment; a Device whose scoped key cannot be built (no reporter, no observation) is listed in the
	//     run summary and left without it.
	public function buildIdentityKeys(): void {
		$pdo = $this->run->pdo;
		$q_key = $this->run->q_key;
		$segment_of = [$this, 'segmentOf'];
		$chassis_key = [$this, 'chassisKey'];
		$insert_key = [$this, 'insertKey'];
		$now = $this->run->now;
		$summary = &$this->run->summary;
		$summary['identity_keys_orphaned'] = (int) $pdo->query("SELECT COUNT(*) FROM topo_identity_keys WHERE device_id NOT IN (SELECT id FROM topo_nodes WHERE type = 'device')")->fetchColumn();
		$pdo->exec("DELETE FROM topo_identity_keys WHERE device_id NOT IN (SELECT id FROM topo_nodes WHERE type = 'device')");

		// 2. scope_ports of the chassis-less Devices, then the old key rows go
		// 2.52: one `scope_port` P becomes `scope_ports` [P] (idempotent: a Device with `scope_ports` has no `scope_port`)
		foreach ($pdo->query("SELECT id, attrs FROM topo_nodes WHERE type = 'device' AND attrs LIKE '%scope_port\"%'")->fetchAll(PDO::FETCH_ASSOC) as $row) {
			$attrs = json_decode($row['attrs'], true) ?: [];
			if (!isset($attrs['scope_port'])) {
				continue;
			}
			$attrs['scope_ports'] = CTopologyAddress::scopePorts($attrs);
			unset($attrs['scope_port']);
			$pdo->prepare('UPDATE topo_nodes SET attrs = ?, updated_at = ? WHERE id = ?')->execute([json_encode($attrs, JSON_THROW_ON_ERROR), $now, (int) $row['id']]);
		}
		$sysname_keys = [];
		foreach ($pdo->query("SELECT device_id, {$q_key} AS k FROM topo_identity_keys WHERE kind = 'sysname' AND replaced = 0 ORDER BY device_id, first_seen DESC, {$q_key}")->fetchAll(PDO::FETCH_ASSOC) as $row) {
			if (preg_match('/^sysname:(\d+):/', $row['k'], $m) === 1) {
				$sysname_keys[(int) $row['device_id']] ??= (int) $m[1];       // the newest key first
			}
		}
		$latest_port = $pdo->prepare('SELECT local_port_id FROM topo_observations WHERE device_id = ? AND local_port_id IS NOT NULL ORDER BY last_seen DESC, id DESC LIMIT 1');
		foreach ($pdo->query("SELECT d.id, d.attrs FROM topo_nodes d WHERE d.type = 'device' AND d.represented_by_node_id IS NULL".
				" AND NOT EXISTS (SELECT 1 FROM topo_identity_keys k WHERE k.device_id = d.id AND k.kind = 'chassis')")->fetchAll(PDO::FETCH_ASSOC) as $device) {
			$attrs = json_decode($device['attrs'], true) ?: [];
			if (($attrs['chassis_id'] ?? '') !== '' || CTopologyAddress::scopePorts($attrs)) {
				continue;
			}
			$port = $sysname_keys[(int) $device['id']] ?? null;
			if ($port === null) {
				$latest_port->execute([(int) $device['id']]);
				$found = $latest_port->fetchColumn();
				$port = $found === false ? null : (int) $found;
			}
			if ($port !== null) {
				$attrs['scope_ports'] = [$port];
				$pdo->prepare('UPDATE topo_nodes SET attrs = ?, updated_at = ? WHERE id = ?')->execute([json_encode($attrs, JSON_THROW_ON_ERROR), $now, (int) $device['id']]);
			}
		}
		$pdo->exec("DELETE FROM topo_identity_keys WHERE kind IN ('ip', 'sysname')");

		// 3. chassis keys of Devices that hold none
		$devices = $pdo->query("SELECT d.id, d.attrs, d.represented_by_matched_by AS matched_by, h.host_ref FROM topo_nodes d".
			" LEFT JOIN topo_nodes h ON h.id = d.represented_by_node_id AND h.type = 'host'".
			" WHERE d.type = 'device' AND NOT EXISTS (SELECT 1 FROM topo_identity_keys k WHERE k.device_id = d.id) ORDER BY d.id")->fetchAll(PDO::FETCH_ASSOC);
		foreach ($devices as $device) {
			$attrs = json_decode($device['attrs'], true) ?: [];
			$wanted = [];      // [value, replaced]
			if (($attrs['chassis_id'] ?? '') !== '') {
				$wanted[] = [(string) $attrs['chassis_id'], false];
			}
			foreach ($attrs['replaced_chassis'] ?? [] as $old) {
				$wanted[] = [(string) $old, true];
			}
			if (!$wanted) {
				continue;
			}
			$segment = null;
			if ($device['matched_by'] === 'reporter_self' && $device['host_ref'] !== null) {
				$segment = $segment_of($device['host_ref']);
			}
			else {
				$latest = $pdo->prepare('SELECT i.hostid FROM topo_observations o JOIN items i ON i.itemid = o.itemid WHERE o.device_id = ?'.
					' ORDER BY o.last_seen DESC, o.id DESC LIMIT 1');
				$latest->execute([$device['id']]);
				if (($hostid = $latest->fetchColumn()) !== false) {
					$segment = $segment_of($hostid);
				}
			}
			$built = 0;
			$skipped = false;
			foreach ($wanted as [$value, $replaced]) {
				$key = $chassis_key($value, null, $segment ?? '');
				if ($key === null || ($segment === null && !str_starts_with($key, 'chassis:mac:'))) {
					$skipped = true;
					continue;
				}
				$exists = $pdo->prepare("SELECT 1 FROM topo_identity_keys WHERE device_id = ? AND {$q_key} = ?");
				$exists->execute([$device['id'], $key]);
				if ($exists->fetchColumn() === false) {
					$insert_key((int) $device['id'], $key, 'chassis', (int) ($attrs['last_seen'] ?? $now), $replaced);
					$built++;
				}
			}
			$summary['identity_keys_built']['devices']++;
			$summary['identity_keys_built']['keys'] += $built;
			if ($skipped && count($summary['identity_keys_built']['skipped']) < 200) {
				$summary['identity_keys_built']['skipped'][] = (int) $device['id'];
			}
		}
	}
}
