<?php

declare(strict_types=1);

/**
 * IngestStore — reads and writes of the model's records, with no rule of its own (topology-1-model.md §6.0): ports, node attributes, edges, observations, the association of a Device with a Host.
 * Input: ids and attribute arrays. Output: ids, rows, the written record. A rule step calls it; it decides nothing.
 * State: the run (IngestRun): pdo, now, summary and the prepared statements of topo_nodes and topo_edges.
 */
class IngestStore {
	private IngestRun $run;

	public function __construct(IngestRun $run) {
		$this->run = $run;
	}

	public function findPort(int $device_id, int $if_index): ?int {
		$pdo = $this->run->pdo;
		$stmt = $pdo->prepare("SELECT id FROM topo_nodes".
			" WHERE device_id = ? AND type = 'port' AND JSON_EXTRACT(attrs, '\$.if_index') = ?");
		$stmt->execute([$device_id, $if_index]);
		$row = $stmt->fetch(PDO::FETCH_ASSOC);
		return $row ? (int) $row['id'] : null;
	}

	public function lastInsertId(): int {
		$pdo = $this->run->pdo;
		return (int) $pdo->lastInsertId($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql' ? 'topo_nodes_id_seq' : null);
	}

	public function nodeAttrs(int $node_id): array {
		$pdo = $this->run->pdo;
		$stmt = $pdo->prepare('SELECT attrs FROM topo_nodes WHERE id = ?');
		$stmt->execute([$node_id]);
		$raw = $stmt->fetchColumn();
		return $raw ? (json_decode($raw, true, 512, JSON_THROW_ON_ERROR) ?: []) : [];
	}

	// Writes attrs only when they actually changed, so an idempotent re-run leaves updated_at alone too.
	public function writeAttrs(int $node_id, array $attrs, array $before): bool {
		$update_node = $this->run->update_node;
		$now = $this->run->now;
		$sorted_attrs = $attrs;
		$sorted_before = $before;
		ksort($sorted_attrs);
		ksort($sorted_before);
		if ($sorted_attrs === $sorted_before) {
			return false;
		}
		$update_node->execute([json_encode($attrs, JSON_THROW_ON_ERROR), $now, $node_id]);
		return true;
	}

	// topology-1-model.md "Relationships" (type validation): an edge's ends are checked on write. A physical_link joins two Ports, a
	// device_link goes from a Port to a Device; any other combination (and an unknown far_port_reason) is rejected, so
	// code that assumes "a physical link joins two ports" can never receive a device row.
	public function validateEdge(string $type, int $src_id, int $dst_id, ?array $attrs = null): void {
		$pdo = $this->run->pdo;
		$stmt = $pdo->prepare('SELECT id, type FROM topo_nodes WHERE id IN (?, ?)');
		$stmt->execute([$src_id, $dst_id]);
		$types = [];
		foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
			$types[(int) $row['id']] = $row['type'];
		}
		$want = ['physical_link' => ['port', 'port'], 'device_link' => ['port', 'device']][$type] ?? null;
		if ($want === null) {
			throw new InvalidArgumentException("unknown edge type '{$type}'");
		}
		if (($types[$src_id] ?? null) !== $want[0] || ($types[$dst_id] ?? null) !== $want[1]) {
			throw new InvalidArgumentException("a {$type} joins a {$want[0]} to a {$want[1]}, got ".
				($types[$src_id] ?? '?').' #'.$src_id.' -> '.($types[$dst_id] ?? '?').' #'.$dst_id);
		}
		if ($type === 'device_link' && $attrs !== null
				&& !in_array($attrs['far_port_reason'] ?? null, TOPO_FAR_PORT_REASONS, true)) {
			throw new InvalidArgumentException('a device_link needs a known far_port_reason, got '.
				json_encode($attrs['far_port_reason'] ?? null));
		}
	}

	// Merge-upserts a Port by (device_id, if_index): only the attrs in $overlay are written, everything else the
	// row already carries (learned_macs from a LEARNED_MACS rule, a name from a PORTS rule, ...) is kept. $soft keys
	// are applied only when the port has no value for them yet (a NEIGHBORS row's if_name must not overwrite the
	// name a PORTS rule reported). The reporter's own side is always confirmed, never pseudo.
	public function port(int $device_id, int $if_index, array $overlay = [], array $soft = []): int {
		$pdo = $this->run->pdo;
		$insert_port_node = $this->run->insert_port_node;
		$find_port = [$this, 'findPort'];
		$snap_node_attrs = [$this, 'nodeAttrs'];
		$snap_write_attrs = [$this, 'writeAttrs'];
		$now = $this->run->now;
		$last_insert_id = [$this, 'lastInsertId'];
		$summary = &$this->run->summary;
		$existing_id = $find_port($device_id, $if_index);
		if ($existing_id !== null) {
			$before = $snap_node_attrs($existing_id);
			$attrs = $overlay + $before;
			foreach ($soft as $key => $value) {
				if ($value !== null && $value !== '' && ($before[$key] ?? '') === '') {
					$attrs[$key] = $value;
				}
			}
			$snap_write_attrs($existing_id, $attrs, $before);
			return $existing_id;
		}
		$attrs = $overlay + [
			'if_index' => $if_index, 'name' => '', 'if_type' => 'physical', 'mac' => null, 'speed' => null,
			'admin_status' => 'up', 'oper_status' => 'up', 'learned_macs' => [], 'zabbix_itemids' => [],
			'pseudo' => false,
		];
		foreach ($soft as $key => $value) {
			if ($value !== null && $value !== '' && ($attrs[$key] ?? '') === '') {
				$attrs[$key] = $value;
			}
		}
		$insert_port_node->execute(['port', $device_id, json_encode($attrs, JSON_THROW_ON_ERROR), $now, $now]);
		$summary['ports_created']++;
		return $last_insert_id();
	}

	// Observation upsert keyed by (rule, local port, remote identity). first_seen survives while the observation
	// stays continuously present; last_seen is the snapshot clock. Nothing is written when nothing changed.
	public function observation(int $itemid, int $local_port_id, string $remote_key, array $remote_attrs,
		string $outcome, ?int $edge_id, ?int $device_id, int $seen_at, ?string $precision = null,
		?string $far_port_reason = null, bool $precision_lower = false): int {
		$pdo = $this->run->pdo;
		// remote_attrs holds only what the remote device advertised. How precisely the far end was identified is ours:
		// link_precision ('port' / 'device', NULL when no link was made), far_port_reason (for 'device') and
		// precision_lower (the observation confirms a port-level link only at device level).
		$attrs_json = json_encode($remote_attrs, JSON_THROW_ON_ERROR);
		$lower = $precision_lower ? 1 : 0;
		$stmt = $pdo->prepare('SELECT id, remote_attrs, outcome, edge_id, device_id, link_precision, far_port_reason,'.
			' precision_lower, last_seen FROM topo_observations WHERE itemid = ? AND local_port_id = ? AND remote_key = ?');
		$stmt->execute([$itemid, $local_port_id, $remote_key]);
		if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
			$same = json_decode($row['remote_attrs'], true) === $remote_attrs && $row['outcome'] === $outcome
				&& ($row['edge_id'] === null ? null : (int) $row['edge_id']) === $edge_id
				&& ($row['device_id'] === null ? null : (int) $row['device_id']) === $device_id
				&& $row['link_precision'] === $precision && $row['far_port_reason'] === $far_port_reason
				&& (int) $row['precision_lower'] === $lower
				&& (int) $row['last_seen'] === $seen_at;
			if (!$same) {
				$pdo->prepare('UPDATE topo_observations SET remote_attrs = ?, outcome = ?, edge_id = ?, device_id = ?,'.
					' link_precision = ?, far_port_reason = ?, precision_lower = ?, last_seen = ? WHERE id = ?')
					->execute([$attrs_json, $outcome, $edge_id, $device_id, $precision, $far_port_reason, $lower, $seen_at,
						$row['id']]);
			}
			return (int) $row['id'];
		}
		$pdo->prepare('INSERT INTO topo_observations (itemid, local_port_id, remote_key, remote_attrs, outcome, edge_id,'.
			' device_id, link_precision, far_port_reason, precision_lower, first_seen, last_seen)'.
			' VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
			->execute([$itemid, $local_port_id, $remote_key, $attrs_json, $outcome, $edge_id, $device_id, $precision,
				$far_port_reason, $lower, $seen_at, $seen_at]);
		return (int) $pdo->lastInsertId();
	}

	// Byte-for-byte mirror of CTopologyPrototype::promote()'s 1:1-guard + write, called only from
	// $associate_hosts_by_mac() below with matched_by='mac' (never 'manual' — manual promotion is the separate
	// /promote endpoint's job (topology-1-model.md "`represented_by` (Device → Host or Proxy)"), not ingest's) and from the reporter_self call in the main loop below
	// with matched_by='reporter_self'. topology-1-model.md "`represented_by` (Device → Host or Proxy)": represented_by is stored as three columns directly on
	// the Device's own topo_nodes row, not a topo_edges row — matched_mac is deliberately not accepted or
	// stored here (no consumer in the new column-based model, per the spec addendum).
	public function promote(int $device_id, int $target_nodeid, string $matched_by): bool {
		$pdo = $this->run->pdo;
		$now = $this->run->now;
		$represented = $pdo->prepare("SELECT 1 FROM topo_nodes WHERE id = ? AND represented_by_node_id IS NOT NULL");
		$represented->execute([$device_id]);
		if ($represented->fetch()) {
			return false; // this Device is already represented — not an error, just no match (1:1, topology-1-model.md "`represented_by` (Device → Host or Proxy)").
		}
		$represented_target = $pdo->prepare(
			"SELECT 1 FROM topo_nodes WHERE type = 'device' AND represented_by_node_id = ?");
		$represented_target->execute([$target_nodeid]);
		if ($represented_target->fetch()) {
			return false; // this Host/Proxy is already represented by some other Device.
		}
		$pdo->prepare(
			"UPDATE topo_nodes SET represented_by_node_id = ?, represented_by_matched_by = ?, represented_by_at = ?".
			" WHERE id = ? AND type = 'device'"
		)->execute([$target_nodeid, $matched_by, $now, $device_id]);
		return true;
	}

	// topology-1-model.md "`represented_by` (Device → Host or Proxy)", "Reporter identity (`reporter_self`)": find-or-create the Host pointer node for a reporter's own hostid, scoped to the new
	// deterministic reporter-self-link path only (see the $promote() call in the main loop below) — NOT used
	// by the inventory MAC match ($associate_hosts_by_mac), which creates the Host node itself on a match. Mirrors
	// CTopologyPrototype::upsertPointerNode() (ui/include/classes/topology/CTopologyPrototype.php ~line 709):
	// look up topo_nodes by (type='host', host_ref=hostid), insert {type:'host', host_ref:hostid, attrs:'{}'}
	// if missing. This is the whole point of the reporter-self-link change: ingest already knows the exact
	// hostid with certainty (from the item.get call that located this reporter, topology-1-model.md "Reporters and usable snapshots"), so linking the
	// reporter's Device to its own Host does not depend on an inventory MAC.
	public function findOrCreateHostNode(string $hostid): int {
		$pdo = $this->run->pdo;
		$now = $this->run->now;
		$last_insert_id = [$this, 'lastInsertId'];
		$stmt = $pdo->prepare("SELECT id FROM topo_nodes WHERE type = 'host' AND host_ref = ?");
		$stmt->execute([$hostid]);
		if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
			return (int) $row['id'];
		}
		$pdo->prepare('INSERT INTO topo_nodes (type, host_ref, attrs, created_at, updated_at) VALUES (?, ?, ?, ?, ?)')
			->execute(['host', $hostid, '{}', $now, $now]);
		return $last_insert_id();
	}

	// Physical links touching either port, other than the (a,b) pair itself, split by provenance. A manual link
	// on either port shadows the discovered observation (topology-1-model.md "Manual wins": manual wins, discovery never
	// deletes it); a different discovered link on the local port is the cable-move case (topology-1-model.md "Replacement of discovered links").
	public function otherLinks(int $port_a, int $port_b): array {
		$pdo = $this->run->pdo;
		$stmt = $pdo->prepare(
			"SELECT id, src_id, dst_id, attrs FROM topo_edges WHERE type = 'physical_link'".
			" AND (src_id IN (?, ?) OR dst_id IN (?, ?)) ORDER BY id");
		$stmt->execute([$port_a, $port_b, $port_a, $port_b]);
		$manual = [];
		$discovered = [];
		foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $edge) {
			$pair = [(int) $edge['src_id'], (int) $edge['dst_id']];
			sort($pair);
			$wanted = [$port_a, $port_b];
			sort($wanted);
			if ($pair === $wanted) {
				continue;
			}
			$attrs = json_decode($edge['attrs'], true, 512, JSON_THROW_ON_ERROR) ?: [];
			if (($attrs['discovered_via'] ?? 'manual') === 'manual') {
				$manual[] = (int) $edge['id'];
			}
			elseif (in_array($port_a, $pair, true)) {
				$discovered[] = (int) $edge['id'];
			}
		}
		return ['manual' => $manual, 'discovered' => $discovered];
	}

	// A device-level sighting that confirms an existing PORT-level link at lower precision (topology-1-model.md "Lost precision", any
	// cause): the link stays a physical_link, last_seen_* advances, last_seen_port does not. A row without last_seen_port
	// is pinned to its last_seen first, so the "not confirmed since" flag has a date; no rule reads last_seen_port.
	public function confirmLower(int $edge_id, int $reporter_port_id, int $seen_at): void {
		$pdo = $this->run->pdo;
		$stmt = $pdo->prepare('SELECT src_id, dst_id, attrs FROM topo_edges WHERE id = ?');
		$stmt->execute([$edge_id]);
		if (!($row = $stmt->fetch(PDO::FETCH_ASSOC))) {
			return;
		}
		$before = json_decode($row['attrs'], true, 512, JSON_THROW_ON_ERROR) ?: [];
		$attrs = $before;
		if (!array_key_exists('last_seen_port', $attrs)) {
			$attrs['last_seen_port'] = (int) ($attrs['last_seen'] ?? 0);
		}
		$side = $reporter_port_id === (int) $row['src_id'] ? 'last_seen_src' : 'last_seen_dst';
		$attrs[$side] = max((int) ($attrs[$side] ?? 0), $seen_at);
		$attrs['last_seen'] = max((int) ($attrs['last_seen_src'] ?? 0), (int) ($attrs['last_seen_dst'] ?? 0));
		if ($attrs !== $before) {
			$pdo->prepare('UPDATE topo_edges SET attrs = ? WHERE id = ?')
				->execute([json_encode($attrs, JSON_THROW_ON_ERROR), $edge_id]);
		}
	}
}
