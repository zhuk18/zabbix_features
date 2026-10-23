<?php

declare(strict_types=1);

/**
 * IngestMarks — the end of the run: the inventory MAC match (topology-1-model.md §10.2) and the observations of rules that no longer have a usable snapshot (§7.3).
 * Input: the stored state after pass B. Output: Devices associated with Hosts, stale observations removed, counters of the run summary.
 * State: the run (IngestRun).
 */
class IngestMarks {
	private IngestRun $run;

	public function __construct(IngestRun $run) {
		$this->run = $run;
	}

	// topology-1-model.md §10.2 "Automatic MAC match (Host only)": part of every run (full and partial), after pass B; there is no separate action. One batched read of the
	// inventory MACs (host_inventory.macaddress_a / macaddress_b of the monitored and not monitored hosts: what host.get with selectInventory returns; a run has no API
	// user, so it reads the table, like the reporters), matched against the MACs of the Devices (the Device's own `mac` and the MAC of its Ports). A MAC in the inventory of
	// several Hosts, or on several Devices, matches nothing (§6.7.4, `macs_shared`). A Device or a Host that already has an association is never matched (§4.5). A Host gets its
	// topo_nodes row only here, on a match (§10.1). Returns nothing; counts the new associations in `hosts_associated_by_mac`.
	public function associateHostsByMac(): void {
		$pdo = $this->run->pdo;
		$promote = [$this->run->store, 'promote'];
		$find_or_create_host_node = [$this->run->store, 'findOrCreateHostNode'];
		$is_placeholder_mac = ['TopologyNormalize', 'isPlaceholderMac'];
		$summary = &$this->run->summary;
		$mac_text = static function (?string $value) use ($is_placeholder_mac): ?string {
			$digits = strtolower(preg_replace('/[^0-9a-fA-F]/', '', (string) $value));
			if (strlen($digits) !== 12 || preg_match('/[^0-9a-fA-F:.\- ]/', (string) $value) === 1) {
				return null;
			}
			$mac = implode(':', str_split($digits, 2));
			return $is_placeholder_mac($mac) ? null : $mac;          // a placeholder on either side never matches (normalization §4.1)
		};
		$hosts_by_mac = [];
		try {
			$rows = $pdo->query('SELECT hi.hostid, hi.macaddress_a, hi.macaddress_b FROM host_inventory hi JOIN hosts h ON h.hostid = hi.hostid'.
				' WHERE h.status IN (0, 1) AND (hi.macaddress_a <> \'\' OR hi.macaddress_b <> \'\') ORDER BY hi.hostid')->fetchAll(PDO::FETCH_ASSOC);
		}
		catch (PDOException $exception) {
			return;                    // a database without the Zabbix inventory table (a test stand-in): nothing to match
		}
		foreach ($rows as $row) {
			foreach (['macaddress_a', 'macaddress_b'] as $field) {
				if (($mac = $mac_text($row[$field])) !== null) {
					$hosts_by_mac[$mac][(int) $row['hostid']] = (int) $row['hostid'];
				}
			}
		}
		if (!$hosts_by_mac) {
			return;
		}
		$devices_by_mac = [];
		foreach ($pdo->query("SELECT id, device_id, type, JSON_UNQUOTE(JSON_EXTRACT(attrs, '\$.mac')) AS mac FROM topo_nodes".
				" WHERE type IN ('device', 'port') AND JSON_EXTRACT(attrs, '\$.mac') IS NOT NULL ORDER BY id")->fetchAll(PDO::FETCH_ASSOC) as $row) {
			$device_id = $row['type'] === 'device' ? (int) $row['id'] : ($row['device_id'] === null ? null : (int) $row['device_id']);
			if ($device_id !== null && ($mac = $mac_text($row['mac'])) !== null) {
				$devices_by_mac[$mac][$device_id] = $device_id;
			}
		}
		$note_shared = static function (string $kind, string $mac, array $ids) use (&$summary): void {
			$summary['macs_shared']['count']++;
			if (count($summary['macs_shared']['list']) < 200) {
				$summary['macs_shared']['list'][] = ['kind' => $kind, 'value' => $mac, ($kind === 'host' ? 'host_ids' : 'node_ids') => array_values($ids)];
			}
		};
		ksort($hosts_by_mac);
		foreach ($hosts_by_mac as $mac => $hosts) {
			$devices = $devices_by_mac[$mac] ?? [];
			if (count($hosts) > 1) {
				$note_shared('host', $mac, $hosts);
				continue;
			}
			if (count($devices) > 1) {
				$note_shared('device', $mac, $devices);
				continue;
			}
			if (!$devices) {
				continue;
			}
			$device_id = reset($devices);
			$hostid = reset($hosts);
			$represented = $pdo->prepare("SELECT 1 FROM topo_nodes WHERE id = ? AND represented_by_node_id IS NOT NULL");
			$represented->execute([$device_id]);
			if ($represented->fetch()) {
				continue;                // an existing association is never replaced
			}
			$host_node = $pdo->prepare("SELECT id FROM topo_nodes WHERE type = 'host' AND host_ref = ?");
			$host_node->execute([$hostid]);
			if (($node_id = $host_node->fetchColumn()) !== false) {
				$taken = $pdo->prepare("SELECT 1 FROM topo_nodes WHERE type = 'device' AND represented_by_node_id = ?");
				$taken->execute([$node_id]);
				if ($taken->fetch()) {
					continue;            // this Host represents another Device already
				}
			}
			$node_id = $find_or_create_host_node((string) $hostid);
			if ($promote($device_id, $node_id, 'mac')) {
				$summary['hosts_associated_by_mac']['count']++;
				if (count($summary['hosts_associated_by_mac']['list']) < 200) {
					$summary['hosts_associated_by_mac']['list'][] = ['host_id' => $hostid, 'node_id' => $node_id, 'device_id' => $device_id];
				}
			}
		}
	}

	// An observation mirrors the latest snapshot of its rule (topology-1-model.md "Observations"). When the rule has no usable snapshot any more —
	// disabled (the rule or its host), its topology type changed, its reporter skipped (identity), the snapshot invalid or gone — nothing refreshes the
	// rows and they would keep saying what the rule said last: a stale shadow would keep a manual link contradicted, a
	// stale conflict would keep blocking an operator's eye. A FULL run therefore removes the observations of every rule
	// that did not contribute a snapshot to it; a run scoped with --zabbix-host cannot tell "not selected" from "not
	// usable" and leaves them alone. The readers (topology.observations.get, the contradictions) apply the same test at
	// read time (enabled rule, snapshot of the rule's current topology type), so a stale row is not shown even before the next run.
	public function cleanStaleObservations(array $usable_itemids): void {
		$pdo = $this->run->pdo;
		$summary = &$this->run->summary;
		$usable = array_fill_keys(array_map('intval', $usable_itemids), true);
		$stale = [];
		foreach ($pdo->query('SELECT id, itemid FROM topo_observations')->fetchAll(PDO::FETCH_ASSOC) as $row) {
			if (!isset($usable[(int) $row['itemid']])) {
				$stale[] = (int) $row['id'];
			}
		}
		foreach (array_chunk($stale, 500) as $chunk) {
			$pdo->prepare('DELETE FROM topo_observations WHERE id IN ('.implode(',', array_fill(0, count($chunk), '?')).')')
				->execute($chunk);
		}
		$summary['observations_removed'] += count($stale);
	}
}
