<?php

declare(strict_types=1);

/**
 * IngestFarPort — the step "Far port" (topology-1-model.md §6.0, §6.7.2, §6.7.3, §8.1).
 * Input: the far Device of a claim and the remote port id, name and description. Output: the port of the far Device (a real port, or a pseudo port made for the claim), the pseudo ports merged or converted.
 * State: the run (IngestRun): pdo, now, summary.
 */
class IngestFarPort {
	private IngestRun $run;

	public function __construct(IngestRun $run) {
		$this->run = $run;
	}

	// The port name as it is COMPARED (the name key); never stored as the name. Real ports ($find_matching_real_port), pseudo ports ($snap_pseudo_port), the
	// pseudo-port merge, the far port and the port class all compare names through it. "" = no key.
	public function normalizePortName(string $name): string {
		$norm_name_key = ['TopologyNormalize', 'nameKey'];
		return $norm_name_key($name) ?? '';
	}

	// The ifIndex of a far port when the advertisement really carries one (LLDP port id subtype `local` with a NUMERIC id, normalization §4.7: leading zeros
	// ignored): such a number is the neighbor's own ifIndex, and a confirmed Port that owns it is the same physical port. Any other far port has no ifIndex that
	// lldpRemTable could tell us; a pseudo port is then found and created by its name key (topology-1-model.md "Far port": "Pseudo port identity is the
	// normalized name"), and its if_index is only an allocation (see $snap_pseudo_port).
	public function pseudoLocalIfIndex(?string $remote_port_id, ?string $remote_port_id_subtype): ?int {
		$norm_numeric_id = ['TopologyNormalize', 'numericId'];
		return $remote_port_id_subtype === 'local' && $remote_port_id !== null ? $norm_numeric_id($remote_port_id) : null;
	}

	// Each pseudo port is counted once, under the first reason that applies: ambiguous, then move_failed, then kept_manual,
	// then converted / removed. The counters add up to the number of pseudo ports handled. A later, lower-priority fact about
	// the same port is kept in its details entry under `also`; a higher-priority one takes the port over.
	public function pseudoNote(string $outcome, int $device_id, int $port_id, string $name, array $extra = []): void {
		$summary = &$this->run->summary;
		static $seen = [];
		static $priority = ['ambiguous' => 1, 'move_failed' => 2, 'kept_manual' => 3, 'converted' => 4, 'removed' => 5];
		if (isset($seen[$port_id])) {
			$index = $seen[$port_id]['index'];
			if ($priority[$outcome] < $priority[$seen[$port_id]['outcome']]) {
				$summary['pseudo_ports_'.$seen[$port_id]['outcome']]--;
				$summary['pseudo_ports_'.$outcome]++;
				if ($index !== null) {
					$before = $summary['pseudo_ports'][$index];
					$also = $before['also'] ?? [];
					$also[$before['outcome']] = array_diff_key($before, array_flip(['outcome', 'device_id', 'port_id', 'name', 'also']));
					$summary['pseudo_ports'][$index] = ['outcome' => $outcome, 'device_id' => $device_id, 'port_id' => $port_id,
						'name' => $name] + $extra + ['also' => $also];
				}
				$seen[$port_id]['outcome'] = $outcome;
			}
			elseif ($index !== null) {
				$summary['pseudo_ports'][$index]['also'][$outcome] = $extra;
			}
			return;
		}
		$summary['pseudo_ports_'.$outcome]++;
		$index = null;
		if (count($summary['pseudo_ports']) < 200) {
			$summary['pseudo_ports'][] = ['outcome' => $outcome, 'device_id' => $device_id, 'port_id' => $port_id,
				'name' => $name] + $extra;
			$index = count($summary['pseudo_ports']) - 1;
		}
		$seen[$port_id] = ['outcome' => $outcome, 'index' => $index];
	}

	public function mergePseudoPort(int $reporter_device_id, int $real_port_id, string $real_port_name): void {
		$pdo = $this->run->pdo;
		$normalize_port_name = [$this, 'normalizePortName'];
		$pseudo_note = [$this, 'pseudoNote'];
		$normalized_real = $normalize_port_name($real_port_name);

		$stmt = $pdo->prepare(
			"SELECT id, JSON_UNQUOTE(JSON_EXTRACT(attrs, '\$.name')) AS name FROM topo_nodes".
			" WHERE device_id = ? AND type = 'port' AND id != ?".
			" AND JSON_EXTRACT(attrs, '\$.pseudo') = true");
		$stmt->execute([$reporter_device_id, $real_port_id]);
		$pseudo_ports = $stmt->fetchAll(PDO::FETCH_ASSOC);

		$matches = array_values(array_filter($pseudo_ports,
			static fn (array $p): bool => $normalize_port_name((string) $p['name']) === $normalized_real));

		if (count($matches) !== 1) {
			// Several pseudo-Ports match one real Port: never guess (topology-1-model.md "Pseudo-port merge"), none is merged. They are reported, and
			// left to $convert_leftover_pseudo_ports().
			foreach (count($matches) > 1 ? $matches : [] as $match) {
				$pseudo_note('ambiguous', $reporter_device_id, (int) $match['id'], (string) $match['name'],
					['real_port_id' => $real_port_id, 'real_port' => $real_port_name]);
			}
			return; // zero matches: nothing to merge (the ordinary/common case).
		}

		$pseudo_port_id = (int) $matches[0]['id'];

		$edges_stmt = $pdo->prepare(
			"SELECT id, src_id, dst_id, attrs FROM topo_edges WHERE type = 'physical_link' AND (src_id = ? OR dst_id = ?)");
		$edges_stmt->execute([$pseudo_port_id, $pseudo_port_id]);
		$edges = $edges_stmt->fetchAll(PDO::FETCH_ASSOC);

		$all_repointed = true;
		foreach ($edges as $edge) {
			$other_id = ((int) $edge['src_id'] === $pseudo_port_id) ? (int) $edge['dst_id'] : (int) $edge['src_id'];
			if ($other_id === $real_port_id) {
				// Pseudo-Port and real Port were somehow already directly linked to each other — nothing
				// sensible to "merge" here, just drop the now-redundant edge.
				$pdo->prepare('DELETE FROM topo_edges WHERE id = ?')->execute([$edge['id']]);
				continue;
			}
			// topology-1-model.md "`physical_link` (Port ↔ Port)": src_id is always the numerically smaller Port id — re-canonicalize for the new endpoint.
			$pseudo_was_src = (int) $edge['src_id'] === $pseudo_port_id;
			[$new_src, $new_dst] = $other_id < $real_port_id ? [$other_id, $real_port_id] : [$real_port_id, $other_id];
			$real_is_src = $new_src === $real_port_id;

			// last_seen_src/last_seen_dst (topology-1-model.md "`physical_link` (Port ↔ Port)") are keyed to canonical src/dst position, not to a stable
			// port identity — if replacing the pseudo-Port with the real one flips which of {other_id,
			// real_port_id} is numerically smaller, the side that used to be "src" is now "dst" and vice
			// versa. Swap the two fields along with src_id/dst_id so a reporter's already-recorded
			// confirmation stays attributed to its own physical port, not to whichever side happens to be
			// numerically smaller after the merge.
			$attrs = json_decode($edge['attrs'], true, 512, JSON_THROW_ON_ERROR) ?: [];
			if ($pseudo_was_src !== $real_is_src
					&& (array_key_exists('last_seen_src', $attrs) || array_key_exists('last_seen_dst', $attrs))) {
				[$attrs['last_seen_src'], $attrs['last_seen_dst']] =
					[$attrs['last_seen_dst'] ?? null, $attrs['last_seen_src'] ?? null];
			}

			// Order-independence (topology-1-model.md "Two phases"): the OTHER side of this same cable may have already run its own merge
			// first (e.g. real-real edge 167<->171 already exists because Switch1's pass merged its pseudo-Port
			// into Router1's real Port before Router1's own pass got a chance to merge the mirror-image
			// pseudo-Port pointing back). That leaves this pseudo-Port's edge fully redundant, not ambiguous —
			// dropping it (rather than trying to UPDATE onto an already-taken pair, which would just violate
			// topo_edges_physical_link_pair_uq) is what lets a second/later ingest pass still converge instead
			// of getting stuck retrying the same collision forever.
			$dup = $pdo->prepare(
				"SELECT id FROM topo_edges WHERE type = 'physical_link' AND src_id = ? AND dst_id = ? AND id != ?");
			$dup->execute([$new_src, $new_dst, $edge['id']]);
			if ($dup->fetch()) {
				$pdo->prepare('DELETE FROM topo_edges WHERE id = ?')->execute([$edge['id']]);
				continue;
			}

			// topology-1-model.md "Port uniqueness": a Port can have at most one active link. The real Port already carrying some OTHER active link means
			// the move would give it two: the link stays on the pseudo-Port (reported as move_failed) and
			// $convert_leftover_pseudo_ports() decides what becomes of it.
			$busy = $pdo->prepare(
				"SELECT id, attrs FROM topo_edges WHERE id != ? AND ((type = 'physical_link' AND (src_id = ? OR dst_id = ?))".
				" OR (type = 'device_link' AND src_id = ?))");
			$busy->execute([$edge['id'], $real_port_id, $real_port_id, $real_port_id]);
			$occupied = false;
			foreach ($busy->fetchAll(PDO::FETCH_ASSOC) as $other_link) {
				$occupied = $occupied || !isset((json_decode($other_link['attrs'], true) ?: [])['superseded_at']);
			}
			if ($occupied) {
				$pseudo_note('move_failed', $reporter_device_id, $pseudo_port_id, (string) $matches[0]['name'],
					['edge_id' => (int) $edge['id'], 'real_port_id' => $real_port_id, 'real_port' => $real_port_name]);
				$all_repointed = false;
				continue;
			}

			try {
				$pdo->prepare('UPDATE topo_edges SET src_id = ?, dst_id = ?, attrs = ? WHERE id = ?')
					->execute([$new_src, $new_dst, json_encode($attrs, JSON_THROW_ON_ERROR), $edge['id']]);
			}
			catch (PDOException $exception) {
				// Not expected after the checks above (a unique-key violation); reported instead of aborting the reporter.
				$pseudo_note('move_failed', $reporter_device_id, $pseudo_port_id, (string) $matches[0]['name'],
					['edge_id' => (int) $edge['id'], 'error' => $exception->getMessage()]);
				$all_repointed = false;
			}
		}

		if (!$all_repointed) {
			return;
		}

		$pdo->prepare("DELETE FROM topo_nodes WHERE id = ? AND type = 'port'")->execute([$pseudo_port_id]);
		echo "OK: merged pseudo-Port #{$pseudo_port_id} into real Port #{$real_port_id} ('{$real_port_name}') ".
			"on device #{$reporter_device_id}\n";
	}

	// topology-1-model.md §6.7.2 "Far port": the far port of a neighbor row is decided by the port id subtype (`rem_port_type`); the LLDP port
	// description (`rem_port_desc`, often free text) is only a fallback. One function for both kinds of far Device:
	//  - reporter (authoritative ports): ['port' => id] when exactly one real Port matches (primary match by subtype, else the description
	//    by normalized name); ['shared' => true] when a step matches several (that step stops the search); neither: no port (port_unmatched);
	//  - not a reporter: $far_pseudo_name() gives the name of the pseudo port.
	// Every CDP row counts as interfaceName (the CDP port id is the port name).
	public function identifyFarPort(int $device_id, array $row): array {
		$pdo = $this->run->pdo;
		$normalize_port_name = [$this, 'normalizePortName'];
		$norm_numeric_id = ['TopologyNormalize', 'numericId'];
		$norm_port_mac = ['TopologyNormalize', 'portMac'];
		$stmt = $pdo->prepare("SELECT id, attrs FROM topo_nodes WHERE device_id = ? AND type = 'port' AND JSON_EXTRACT(attrs, '\$.pseudo') = false ORDER BY id");
		$stmt->execute([$device_id]);
		$ports = [];
		foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $port) {
			$ports[(int) $port['id']] = json_decode($port['attrs'], true) ?: [];
		}
		// ports whose name key equals the key of $name (normalization §4.6)
		$by_name = static function (string $name) use ($ports, $normalize_port_name): array {
			$key = $normalize_port_name($name);
			return $key === '' ? [] : array_keys(array_filter($ports, static fn (array $a): bool => $normalize_port_name((string) ($a['name'] ?? '')) === $key));
		};
		$rem_port = (string) ($row['rem_port'] ?? '');
		$desc = (string) ($row['rem_port_desc'] ?? '');
		$type = (string) ($row['rem_port_type'] ?? '');
		$primary = [];
		if ($rem_port !== '') {
			if (($row['source'] ?? 'lldp') === 'cdp' || $type === 'interfaceName') {
				$primary = $by_name($rem_port);
			}
			elseif ($type === 'macAddress') {
				// a port MAC identity (normalization §4.8): a valid MAC, not all zero, that exactly one port has; else "none", and the description follows
				$ids = array_keys($ports);
				$index = $norm_port_mac(strtolower($rem_port), array_map(static fn (int $id): string => strtolower((string) ($ports[$id]['mac'] ?? '')), $ids));
				$primary = $index === null ? [] : [$ids[$index]];
			}
			elseif ($type === 'local' && $norm_numeric_id($rem_port) !== null) {
				// a numeric port id is a guess of an ifIndex (normalization §4.7): a description that names exactly one Port wins; then the ifIndex; then the name
				$by_desc = $desc !== '' ? $by_name($desc) : [];
				if (count($by_desc) === 1) {
					$primary = $by_desc;
				}
				else {
					$number = $norm_numeric_id($rem_port);
					$primary = array_keys(array_filter($ports, static fn (array $a): bool => (int) ($a['if_index'] ?? 0) === $number));
					$primary = $primary ?: $by_name($rem_port);
				}
			}
			else {
				$primary = $by_name($rem_port);
			}
		}
		if (count($primary) > 1) {
			return ['port' => null, 'shared' => true];
		}
		if (count($primary) === 1) {
			return ['port' => $primary[0], 'shared' => false];
		}
		if ($desc !== '') {
			$fallback = $by_name($desc);
			if (count($fallback) > 1) {
				return ['port' => null, 'shared' => true];
			}
			if (count($fallback) === 1) {
				return ['port' => $fallback[0], 'shared' => false];
			}
		}
		return ['port' => null, 'shared' => false];
	}

	// The name of the pseudo port of a far Device that is not a reporter (§6.7.2): rem_port for interfaceName, CDP and a non-numeric
	// local id; otherwise rem_port_desc when present, else rem_port. (A numeric local id also gives the pseudo port its ifIndex.)
	public function farPseudoName(array $row): string {
		$norm_numeric_id = ['TopologyNormalize', 'numericId'];
		$rem_port = (string) ($row['rem_port'] ?? '');
		$desc = (string) ($row['rem_port_desc'] ?? '');
		$type = (string) ($row['rem_port_type'] ?? '');
		$by_port_id = ($row['source'] ?? 'lldp') === 'cdp' || $type === 'interfaceName' || ($type === 'local' && $norm_numeric_id($rem_port) === null);
		return $by_port_id ? ($rem_port !== '' ? $rem_port : $desc) : ($desc !== '' ? $desc : $rem_port);
	}

	// A Device whose own walk returned real ports must have no pseudo-Ports left. Run after the PORTS merge
	// ($merge_pseudo_port): a pseudo-Port that is still there stands for a port the Device does not have by that name (the
	// neighbor advertised `1`, the Device calls it `Gi0/1`). Each discovered link on it becomes a device_link in place
	// (same edge id): src = the other side's Port, dst = this Device, reason port_unmatched, the hint from the
	// advertisement (the observation of that port, the pseudo-Port's own name as a fallback); then the pseudo-Port is
	// deleted. A manual link is never converted: the pseudo-Port stays and is reported (pseudo_ports_kept_manual). A link
	// that cannot be converted (the device_link pair already exists) keeps the port too (pseudo_ports_move_failed). A
	// pseudo-Port with no link at all is simply removed.
	public function convertLeftoverPseudoPorts(int $device_id): void {
		$pdo = $this->run->pdo;
		$validate_edge = [$this->run->store, 'validateEdge'];
		$pseudo_note = [$this, 'pseudoNote'];
		$real = $pdo->prepare("SELECT 1 FROM topo_nodes WHERE device_id = ? AND type = 'port'".
			" AND JSON_EXTRACT(attrs, '\$.pseudo') = false LIMIT 1");
		$real->execute([$device_id]);
		if (!$real->fetchColumn()) {
			return;
		}
		$pseudo = $pdo->prepare("SELECT id, attrs FROM topo_nodes WHERE device_id = ? AND type = 'port'".
			" AND JSON_EXTRACT(attrs, '\$.pseudo') = true ORDER BY id");
		$pseudo->execute([$device_id]);
		$ports = $pseudo->fetchAll(PDO::FETCH_ASSOC);

		foreach ($ports as $port_row) {
			$port_id = (int) $port_row['id'];
			$name = (string) (json_decode($port_row['attrs'], true)['name'] ?? '');
			$edges = $pdo->prepare("SELECT id, src_id, dst_id, attrs FROM topo_edges WHERE type = 'physical_link'".
				" AND (src_id = ? OR dst_id = ?) ORDER BY id");
			$edges->execute([$port_id, $port_id]);
			$converted = 0;
			$kept_manual = [];
			$failed = [];
			$links = 0;
			foreach ($edges->fetchAll(PDO::FETCH_ASSOC) as $edge) {
				$links++;
				$attrs = json_decode($edge['attrs'], true, 512, JSON_THROW_ON_ERROR) ?: [];
				if (($attrs['discovered_via'] ?? 'manual') === 'manual') {
					$kept_manual[] = (int) $edge['id'];
					continue;
				}
				$pseudo_is_src = (int) $edge['src_id'] === $port_id;
				$other = $pseudo_is_src ? (int) $edge['dst_id'] : (int) $edge['src_id'];
				$taken = $pdo->prepare("SELECT 1 FROM topo_edges WHERE type = 'device_link' AND src_id = ? AND dst_id = ?");
				$taken->execute([$other, $device_id]);
				if ($taken->fetchColumn()) {
					$failed[] = (int) $edge['id'];
					continue;
				}

				$hint = [];
				$observation = $pdo->prepare('SELECT remote_attrs FROM topo_observations WHERE local_port_id = ? AND device_id = ?'.
					' ORDER BY last_seen DESC, id DESC LIMIT 1');
				$observation->execute([$other, $device_id]);
				if (($raw = $observation->fetchColumn()) !== false) {
					$advertised = json_decode((string) $raw, true) ?: [];
					$hint = array_filter(array_intersect_key($advertised, array_flip(['rem_port', 'rem_port_desc', 'rem_port_type'])),
						static fn ($value): bool => $value !== '' && $value !== null);
				}
				if (!$hint) {
					$hint = ['rem_port_desc' => $name];
				}

				// last_seen_src / last_seen_dst follow the canonical position of the physical_link; a device_link's src is the
				// other side's port, its dst the Device (the pseudo-Port's side).
				$other_side = (int) ($attrs[$pseudo_is_src ? 'last_seen_dst' : 'last_seen_src'] ?? 0);
				$pseudo_side = (int) ($attrs[$pseudo_is_src ? 'last_seen_src' : 'last_seen_dst'] ?? 0);
				$new = ['discovered_via' => $attrs['discovered_via'], 'far_port_reason' => 'port_unmatched', 'far_port_hint' => $hint];
				if ($other_side > 0) {
					$new['last_seen_src'] = $other_side;
				}
				if ($pseudo_side > 0) {
					$new['last_seen_dst'] = $pseudo_side;
				}
				$new['last_seen'] = max($other_side, $pseudo_side, (int) ($attrs['last_seen'] ?? 0));
				if (isset($attrs['superseded_at'])) {
					$new['superseded_at'] = $attrs['superseded_at'];
				}
				$validate_edge('device_link', $other, $device_id, $new);
				$pdo->prepare("UPDATE topo_edges SET type = 'device_link', src_id = ?, dst_id = ?, attrs = ? WHERE id = ?")
					->execute([$other, $device_id, json_encode($new, JSON_THROW_ON_ERROR), $edge['id']]);
				$converted++;
			}

			if ($kept_manual) {
				$pseudo_note('kept_manual', $device_id, $port_id, $name, ['edge_ids' => $kept_manual]);
			}
			if ($failed) {
				$pseudo_note('move_failed', $device_id, $port_id, $name, ['edge_ids' => $failed]);
			}
			if ($kept_manual || $failed) {
				continue; // the port stays: something is still attached to it
			}
			$pdo->prepare("DELETE FROM topo_nodes WHERE id = ? AND type = 'port'")->execute([$port_id]);
			$pseudo_note($links > 0 ? 'converted' : 'removed', $device_id, $port_id, $name, ['links' => $converted]);
		}
	}

	public function ifType(?string $value, string $name = ''): string {
		$normalize_port_name = [$this, 'normalizePortName'];
		$value = trim((string) $value);
		if (in_array($value, TOPO_PORT_CLASSES, true)) {
			return $value;
		}
		if (ctype_digit($value)) {
			if (in_array((int) $value, TOPO_PORT_CLASS_LAG, true)) {
				return 'lag';
			}
			if (in_array((int) $value, TOPO_PORT_CLASS_VIRTUAL, true)) {
				return 'virtual';
			}
		}
		$normalized = $normalize_port_name($name);
		foreach (TOPO_MGMT_NAME_PREFIXES as $prefix) {
			if ($normalized !== '' && str_starts_with($normalized, $prefix)) {
				return 'mgmt';
			}
		}
		return 'physical';
	}

	// IF-MIB: 1 = up; everything else counts as down (push.py does the same).
	public function status(?string $value): string {
		return $value === '1' || $value === 'up' ? 'up' : 'down';
	}

	// ============================================================================================================
	// NEIGHBORS phase 2a "classify" (topology-1-model.md "Port-level or device-level")
	//
	// For every candidate that advertises a far port, decides at which precision the far end is known. level 'port':
	// the far Port is a real one that matches the advertisement, or (far Device is not a reporter) a pseudo-Port made
	// from it, as before. level 'device': the far Device is identified but its port is not, with a far_port_reason:
	//   port_shared_id  several real ports match the advertisement, the far Device's authoritative ports share
	//                   the advertised MAC, or several local ports advertise the same far port id
	//   lag_ambiguous   several local ports of one reporter reach the same far Device and none is told apart
	//   port_unmatched  the far Device is a reporter (its ports are authoritative) and no port matches
	// A candidate that is both unmatched and in a shared / LAG group takes the group's reason (shared id wins over LAG).
	// No port is created on a Device that has authoritative ports: that would be a phantom.
	// ============================================================================================================
	// An internal if_index for a pseudo port that has no real ifIndex (topology-1-model.md §6.6 "Pseudo ports never take a PORTS row"):
	// NEGATIVE, from the hash of the normalized name, moved on (further down) past any if_index the Device already uses. Real ifIndex
	// values are positive, so a reporter's PORTS row can never land on such a pseudo port.
	public function allocPseudoIndex(int $device_id, string $key): int {
		$pdo = $this->run->pdo;
		$index = -((int) (crc32($key) % 999999) + 1);
		$used = $pdo->prepare("SELECT 1 FROM topo_nodes WHERE device_id = ? AND type = 'port' AND JSON_EXTRACT(attrs, '\$.if_index') = ?");
		for ($tries = 0; $tries < 1000; $tries++, $index--) {
			$used->execute([$device_id, $index]);
			if ($used->fetchColumn() === false) {
				break;
			}
		}
		return $index;
	}

	// The pseudo port of a far Device that is not a reporter (topology-1-model.md "Far port"). Found by the normalized name of the
	// advertisement, never by the raw string, so `GigabitEthernet0/24` (LLDP) and `Gi0/24` (CDP) are one port and two different
	// names are never one port. Only a numeric `local` port id (a real ifIndex) is first looked up by if_index: a confirmed Port that
	// owns it is the same physical port, and a pseudo port made from it keeps that real ifIndex (marked local_ifindex). Any other new
	// pseudo port gets a negative allocated if_index.
	public function pseudoPort(array $candidate): int {
		$pdo = $this->run->pdo;
		$snap_port = [$this->run->store, 'port'];
		$normalize_port_name = [$this, 'normalizePortName'];
		$alloc_pseudo_index = [$this, 'allocPseudoIndex'];
		$device_id = $candidate['remote_device_id'];
		$key = (string) $candidate['pseudo_key'];
		if ($candidate['pseudo_index'] !== null) {
			$stmt = $pdo->prepare("SELECT id FROM topo_nodes WHERE device_id = ? AND type = 'port' AND JSON_EXTRACT(attrs, '\$.if_index') = ?");
			$stmt->execute([$device_id, $candidate['pseudo_index']]);
			if (($found = $stmt->fetchColumn()) !== false) {
				return (int) $found;
			}
		}
		$stmt = $pdo->prepare("SELECT id, JSON_UNQUOTE(JSON_EXTRACT(attrs, '\$.name')) AS name FROM topo_nodes".
			" WHERE device_id = ? AND type = 'port' AND JSON_EXTRACT(attrs, '\$.pseudo') = true ORDER BY id");
		$stmt->execute([$device_id]);
		foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $pseudo) {
			if ($normalize_port_name((string) $pseudo['name']) === $key) {
				return (int) $pseudo['id'];
			}
		}
		$local = $candidate['pseudo_index'] !== null;
		$index = $local ? $candidate['pseudo_index'] : $alloc_pseudo_index($device_id, $key);
		return $snap_port($device_id, $index, ['if_index' => $index, 'name' => $candidate['port_label'], 'pseudo' => true,
			'mac' => $candidate['pseudo_mac']] + ($local ? ['local_ifindex' => true] : []));
	}

	// Existing pseudo ports with a positive allocated if_index (made before the allocation became negative) move to a negative one, so a
	// real port with that ifIndex can never land on them (topology-1-model.md §6.6). A pseudo port made from a numeric local port id keeps
	// its real ifIndex: it is marked local_ifindex, or (older data) is named by that number. Runs before any reporter is ingested.
	public function reindexPseudoPorts(): void {
		$pdo = $this->run->pdo;
		$normalize_port_name = [$this, 'normalizePortName'];
		$alloc_pseudo_index = [$this, 'allocPseudoIndex'];
		$update_node = $this->run->update_node;
		$now = $this->run->now;
		$summary = &$this->run->summary;
		$rows = $pdo->query("SELECT id, device_id, attrs FROM topo_nodes WHERE type = 'port' AND device_id IS NOT NULL".
			" AND JSON_EXTRACT(attrs, '\$.pseudo') = true AND JSON_EXTRACT(attrs, '\$.if_index') > 0 ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
		foreach ($rows as $row) {
			$attrs = json_decode($row['attrs'], true, 512, JSON_THROW_ON_ERROR) ?: [];
			if (!empty($attrs['local_ifindex']) || (string) ($attrs['name'] ?? '') === (string) $attrs['if_index']) {
				continue;
			}
			$attrs['if_index'] = $alloc_pseudo_index((int) $row['device_id'], $normalize_port_name((string) ($attrs['name'] ?? '')));
			$update_node->execute([json_encode($attrs, JSON_THROW_ON_ERROR), $now, $row['id']]);
			$summary['pseudo_ports_reindexed']++;
		}
	}

	public function classifyCandidates(array &$candidates): void {
		$pdo = $this->run->pdo;
		$identify_far_port = [$this, 'identifyFarPort'];
		$snap_port = [$this->run->store, 'port'];
		$snap_pseudo_port = [$this, 'pseudoPort'];
		$authoritative = [];
		$is_authoritative = static function (int $device_id) use ($pdo, &$authoritative): bool {
			if (!array_key_exists($device_id, $authoritative)) {
				$stmt = $pdo->prepare("SELECT 1 FROM topo_nodes WHERE device_id = ? AND type = 'port'".
					" AND JSON_EXTRACT(attrs, '\$.pseudo') = false LIMIT 1");
				$stmt->execute([$device_id]);
				$authoritative[$device_id] = (bool) $stmt->fetchColumn();
			}
			return $authoritative[$device_id];
		};
		foreach ($candidates as &$candidate) {
			$candidate['level'] = 'port';
			$candidate['far_port_reason'] = null;
			$candidate['remote_port_id'] = null;
			if ($candidate['pre_outcome'] !== null) {
				continue;
			}
			if ($is_authoritative($candidate['remote_device_id'])) {
				// a reporter: its walk is the only description of its ports (§6.7.2)
				$identified = $identify_far_port($candidate['remote_device_id'], $candidate);
				if ($identified['port'] !== null) {
					$candidate['remote_port_id'] = $identified['port'];
				}
				else {
					$candidate['level'] = 'device';
					$candidate['far_port_reason'] = $identified['shared'] ? 'port_shared_id' : 'port_unmatched';
				}
			}
			else {
				$candidate['level'] = 'pending'; // far Device without authoritative ports: a pseudo-Port, unless shared
			}
		}
		unset($candidate);

		// topology-1-model.md "Port-level or device-level" (port_shared_id): the same far port id advertised on two or more local ports of one reporter names the device, not a port.
		$by_id = [];
		foreach ($candidates as $i => $candidate) {
			if (!in_array($candidate['level'], ['device', 'pending'], true)
					|| $candidate['far_port_reason'] === 'port_shared_id' || $candidate['rem_port'] === '') {
				continue;
			}
			// One reporter's several local ports: a single far port cannot be cabled to two of them. The same id coming
			// from two different reporters is the far-end occupancy contest of topology-1-model.md "Replacement of discovered links", not a shared id.
			$id_key = $candidate['hostid'].'|'.$candidate['remote_device_id'].'|'.$candidate['rem_port_type'].'|'
				.strtolower($candidate['rem_port']);
			$by_id[$id_key][$candidate['local_port_id']][] = $i;
		}
		foreach ($by_id as $local_ports) {
			if (count($local_ports) < 2) {
				continue;
			}
			foreach ($local_ports as $indexes) {
				foreach ($indexes as $i) {
					$candidates[$i]['level'] = 'device';
					$candidates[$i]['far_port_reason'] = 'port_shared_id';
				}
			}
		}

		// topology-1-model.md "Port-level or device-level" (lag_ambiguous): several local ports of one reporter, one far Device, no port told apart.
		$by_device = [];
		foreach ($candidates as $i => $candidate) {
			if ($candidate['level'] === 'device' && $candidate['far_port_reason'] === 'port_unmatched') {
				$by_device[$candidate['hostid'].'|'.$candidate['remote_device_id']][$candidate['local_port_id']][] = $i;
			}
		}
		foreach ($by_device as $local_ports) {
			if (count($local_ports) < 2) {
				continue;
			}
			foreach ($local_ports as $indexes) {
				foreach ($indexes as $i) {
					$candidates[$i]['far_port_reason'] = 'lag_ambiguous';
				}
			}
		}

		// The remaining far Devices have no authoritative ports: the advertisement is the only description of their port.
		foreach ($candidates as &$candidate) {
			if ($candidate['level'] !== 'pending') {
				continue;
			}
			$candidate['level'] = 'port';
			$candidate['remote_port_id'] = $snap_pseudo_port($candidate);
		}
		unset($candidate);
	}
}
