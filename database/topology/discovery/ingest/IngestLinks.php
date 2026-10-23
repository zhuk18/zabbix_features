<?php

declare(strict_types=1);

/**
 * IngestLinks — the step "Links per local port" (topology-1-model.md §6.0, §8.2-§8.5, §9).
 * Input: the link candidates of all usable snapshots of the run and the snapshots of the neighbors. Output: links made, refined, superseded, revived, device-level links, observations with their verdicts.
 * State: the run (IngestRun): pdo, now, summary.
 */
class IngestLinks {
	private IngestRun $run;

	public function __construct(IngestRun $run) {
		$this->run = $run;
	}

	// physical_link upsert. Same canonicalization and per-side last_seen as $ensure_physical_link(), with three
	// deliberate changes (topology-1-model.md "`last_seen` sources"): the time is the snapshot clock and only ever moves forward (so an older
	// snapshot processed later cannot pull it back, and two rules confirming one side keep the newer clock);
	// discovered_via is the row's source; any non-manual source upgrades a manual link. Returns the edge id.
	public function link(int $port_a, int $port_b, string $source, int $reporter_port_id, int $seen_at,
		bool $keep_manual = false): int {
		$pdo = $this->run->pdo;
		$insert_edge = $this->run->insert_edge;
		$now = $this->run->now;
		$last_insert_id = [$this->run->store, 'lastInsertId'];
		$validate_edge = [$this->run->store, 'validateEdge'];
		$summary = &$this->run->summary;
		$reporter_is_a = $reporter_port_id === $port_a;
		if ($port_a > $port_b) {
			[$port_a, $port_b] = [$port_b, $port_a];
			$reporter_is_a = !$reporter_is_a;
		}
		$side = $reporter_is_a ? 'last_seen_src' : 'last_seen_dst';
		$validate_edge('physical_link', $port_a, $port_b);

		$stmt = $pdo->prepare("SELECT id, attrs FROM topo_edges WHERE type = 'physical_link' AND src_id = ? AND dst_id = ?");
		$stmt->execute([$port_a, $port_b]);
		if ($existing = $stmt->fetch(PDO::FETCH_ASSOC)) {
			$before = json_decode($existing['attrs'], true, 512, JSON_THROW_ON_ERROR) ?: [];
			$attrs = $before;
			// A discovery source upgrades a manual link it confirms, except while discovery also shows a different
			// neighbor on one of its ports or the operator acknowledged one (topology-1-model.md "Contradiction": the
			// operator decides that, not ingest): then the link stays manual. Revoking every acknowledgment hands the
			// link back to the upgrade. Every other attr, shadow_ack included, is carried over untouched.
			if (($attrs['discovered_via'] ?? 'manual') === 'manual' && !$keep_manual) {
				$attrs['discovered_via'] = $source;
				unset($attrs['shadow_ack']); // acknowledgments only make sense on a manual link (topology-1-model.md "Confirmation by discovery")
			}
			$attrs[$side] = max((int) ($attrs[$side] ?? 0), $seen_at);
			$attrs['last_seen'] = max((int) ($attrs['last_seen_src'] ?? 0), (int) ($attrs['last_seen_dst'] ?? 0));
			// topology-1-model.md "`physical_link` (Port ↔ Port)" (last_seen_port): the clock of the latest snapshot that contained this link at PORT level.
			$attrs['last_seen_port'] = max((int) ($attrs['last_seen_port'] ?? 0), $seen_at);
			if ($attrs !== $before) {
				$pdo->prepare('UPDATE topo_edges SET attrs = ? WHERE id = ?')
					->execute([json_encode($attrs, JSON_THROW_ON_ERROR), $existing['id']]);
			}
			return (int) $existing['id'];
		}
		$attrs = ['discovered_via' => $source, $side => $seen_at, 'last_seen' => $seen_at, 'last_seen_port' => $seen_at];
		$insert_edge->execute(['physical_link', $port_a, $port_b, json_encode($attrs, JSON_THROW_ON_ERROR), $now]);
		$summary['links_created']++;
		return $last_insert_id();
	}

	// device_link upsert (topology-1-model.md "`device_link` (Port → Device)"): src = the local Port, dst = the far Device, directed by
	// construction (no canonicalization). last_seen_src is the local reporter's side; last_seen_dst is set elsewhere,
	// when the far Device's own reporter names the local Device. A manual link that discovery confirms is upgraded like
	// a manual physical_link ($keep_manual says it must not be); far_port_reason / far_port_hint follow the latest
	// sighting.
	public function deviceLink(int $port_id, int $device_id, string $source, string $reason, array $hint,
		int $seen_at, bool $keep_manual = false): int {
		$pdo = $this->run->pdo;
		$insert_edge = $this->run->insert_edge;
		$now = $this->run->now;
		$last_insert_id = [$this->run->store, 'lastInsertId'];
		$validate_edge = [$this->run->store, 'validateEdge'];
		$summary = &$this->run->summary;
		$stmt = $pdo->prepare("SELECT id, attrs FROM topo_edges WHERE type = 'device_link' AND src_id = ? AND dst_id = ?");
		$stmt->execute([$port_id, $device_id]);
		if ($existing = $stmt->fetch(PDO::FETCH_ASSOC)) {
			$before = json_decode($existing['attrs'], true, 512, JSON_THROW_ON_ERROR) ?: [];
			$attrs = $before;
			$manual = ($attrs['discovered_via'] ?? 'manual') === 'manual';
			if (!$manual || !$keep_manual) {
				if ($manual) {
					$attrs['discovered_via'] = $source;
					unset($attrs['shadow_ack']); // confirmation turns it into a discovered link (topology-1-model.md "Confirmation by discovery")
				}
				$attrs['far_port_reason'] = $reason;
				if ($hint) {
					$attrs['far_port_hint'] = $hint;
				}
			}
			$attrs['last_seen_src'] = max((int) ($attrs['last_seen_src'] ?? 0), $seen_at);
			$attrs['last_seen'] = max((int) ($attrs['last_seen_src'] ?? 0), (int) ($attrs['last_seen_dst'] ?? 0));
			if ($attrs !== $before) {
				$validate_edge('device_link', $port_id, $device_id, $attrs);
				$pdo->prepare('UPDATE topo_edges SET attrs = ? WHERE id = ?')
					->execute([json_encode($attrs, JSON_THROW_ON_ERROR), $existing['id']]);
			}
			return (int) $existing['id'];
		}
		$attrs = ['discovered_via' => $source, 'far_port_reason' => $reason, 'last_seen_src' => $seen_at,
			'last_seen' => $seen_at];
		if ($hint) {
			$attrs['far_port_hint'] = $hint;
		}
		$validate_edge('device_link', $port_id, $device_id, $attrs);
		$insert_edge->execute(['device_link', $port_id, $device_id, json_encode($attrs, JSON_THROW_ON_ERROR), $now]);
		$summary['links_created']++;
		return $last_insert_id();
	}

	// The reporter's own Device is represented by its own Host (topology-1-model.md "Reporter identity (`reporter_self`)"). $promote() itself is untouched.
	public function linkReporterSelf(int $device_id, string $hostid, string $host_label): void {
		$pdo = $this->run->pdo;
		$find_or_create_host_node = [$this->run->store, 'findOrCreateHostNode'];
		$promote = [$this->run->store, 'promote'];
		$host_node_id = $find_or_create_host_node($hostid);
		if ($promote($device_id, $host_node_id, 'reporter_self')) {
			echo "OK: reporter '{$host_label}' device #{$device_id} represented_by its own host node ".
				"#{$host_node_id} (matched_by: reporter_self)\n";
			return;
		}
		$already_correct = $pdo->prepare(
			"SELECT 1 FROM topo_nodes WHERE id = ? AND type = 'device' AND represented_by_node_id = ?");
		$already_correct->execute([$device_id, $host_node_id]);
		if (!$already_correct->fetch()) {
			echo "CONFLICT: reporter '{$host_label}' device #{$device_id} or its host node #{$host_node_id} ".
				"already has a represented_by link to something else — leaving it in place (1:1 constraint)\n";
		}
	}

	// ============================================================================================================
	// NEIGHBORS phase 2b "resolve" (topology-1-model.md "Replacement of discovered links", "Several neighbors or claims on one port", "Legacy cleanup"; topology-1-model.md "Refinement: device-level → port-level", "Lost precision")
	//
	// One decision for the whole run, from the candidates of EVERY reporter's latest NEIGHBORS snapshots, so the
	// result cannot depend on the order the reporters were walked in.
	//
	// An *entity* is a link or a candidate and has an identity (key): a pair of ports {P,X} for a port-level one, or
	// "P>dD" (local port, far Device) for a device-level one. A device-level entity occupies only its local port; it
	// occupies no port on the far Device. rules(entity) is the set of NEIGHBORS rules whose latest snapshot contains it,
	// from either end. A candidate that is not in a several-neighbors group (topology-1-model.md "Several neighbors or claims on one port") and not behind a manual link *claims* its entity. Every other
	// entity touching a port of the claim (active discovered links, and the other claims) is HELD against it when some
	// rule outside rules(claim) contains it: a snapshot other than the contradicting one still says it. Clocks are
	// never compared: a reporter's latest snapshot is its current claim however long ago it was polled (the clock only
	// becomes superseded_at). A claim WINS when nothing is held against it; it is then written (created, or revived when
	// it was superseded) and every existing active link it blocks becomes superseded. An existing active link stays
	// unless a winner supersedes it, and its own candidates are then "applied"; a losing claim is a "conflict" pointing at
	// the link that holds the port.
	//
	// Device level (device-level-edge spec): two entities are the same cable seen at different precision when they share
	// a local port and the far Device. A device-level sighting of an existing port-level link only confirms it (topology-1-model.md "Lost precision", it
	// does not contradict: contradiction compares far Devices: topology-1-model.md "Replacement of discovered links"), whatever the cause of the lost precision; a
	// port-level claim over an existing device_link refines it in place (topology-1-model.md "Refinement: device-level → port-level"). A physical_link never becomes a
	// device_link here (the one exception, a pseudo port corrected when its Device becomes a reporter, is topology-1-model.md "Pseudo-port merge").
	//
	// Before that, pre-spec data with two active discovered links on one port is cleaned up once (legacy).
	// ============================================================================================================
	public function resolveLinks(array $candidates, array $neighbor_snapshots, bool $replacement_enabled, array $frozen_edges = []): void {
		$pdo = $this->run->pdo;
		$snap_classify_candidates = [$this->run->farport, 'classifyCandidates'];
		$snap_link = [$this, 'link'];
		$snap_device_link = [$this, 'deviceLink'];
		$snap_link_confirm_lower = [$this->run->store, 'confirmLower'];
		$snap_observation = [$this->run->store, 'observation'];
		$validate_edge = [$this->run->store, 'validateEdge'];
		$now = $this->run->now;
		$summary = &$this->run->summary;
		$snap_classify_candidates($candidates);

		$pair_key = static fn (int $x, int $y): string => min($x, $y).'-'.max($x, $y);
		$dev_key = static fn (int $port, int $device): string => $port.'>d'.$device;

		$device_of_cache = [];
		$device_of = static function (int $port) use ($pdo, &$device_of_cache): ?int {
			if (!array_key_exists($port, $device_of_cache)) {
				$stmt = $pdo->prepare("SELECT device_id FROM topo_nodes WHERE id = ? AND type = 'port'");
				$stmt->execute([$port]);
				$value = $stmt->fetchColumn();
				$device_of_cache[$port] = ($value === false || $value === null) ? null : (int) $value;
			}
			return $device_of_cache[$port];
		};

		// The entity a candidate stands for: its key and the ports it occupies. Null key = no entity (not a link).
		$candidate_entity = static function (array $c) use ($pair_key, $dev_key): array {
			if ($c['level'] === 'device') {
				return [$dev_key($c['local_port_id'], $c['remote_device_id']), [$c['local_port_id']]];
			}
			if ($c['remote_port_id'] === null || $c['remote_port_id'] === $c['local_port_id']) {
				return [null, []];
			}
			return [$pair_key($c['local_port_id'], $c['remote_port_id']), [$c['local_port_id'], $c['remote_port_id']]];
		};

		// 1. Existing links of both types, by port.
		$links = [];
		$by_port = [];
		$link_by_key = [];
		$rows = $pdo->query("SELECT id, type, src_id, dst_id, attrs FROM topo_edges".
			" WHERE type IN ('physical_link', 'device_link') ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
		$register_link = static function (array $edge) use (&$links, &$by_port, &$link_by_key, $pair_key, $dev_key): void {
			$attrs = json_decode($edge['attrs'], true, 512, JSON_THROW_ON_ERROR) ?: [];
			$device_level = $edge['type'] === 'device_link';
			$a = (int) $edge['src_id'];
			$b = (int) $edge['dst_id'];
			$key = $device_level ? $dev_key($a, $b) : $pair_key($a, $b);
			$id = (int) $edge['id'];
			$links[$id] = ['id' => $id, 'type' => $edge['type'], 'a' => $a, 'b' => $b, 'key' => $key,
				'occupies' => $device_level ? [$a] : [$a, $b],
				'manual' => ($attrs['discovered_via'] ?? 'manual') === 'manual',
				'seen' => (int) ($attrs['last_seen'] ?? 0),
				// The operator has decided something about this manual link (kept it for a hidden neighbor): from then
				// on no ingest run changes its kind, whether or not a neighbor is hiding behind it right now.
				'acknowledged' => !empty($attrs['shadow_ack']),
				'superseded_at' => isset($attrs['superseded_at']) ? (int) $attrs['superseded_at'] : null];
			foreach ($links[$id]['occupies'] as $port) {
				$by_port[$port][] = $id;
			}
			$link_by_key[$key] = $id;
		};
		foreach ($rows as $edge) {
			$register_link($edge);
		}
		$is_active_discovered = static fn (array $link): bool => !$link['manual'] && $link['superseded_at'] === null;
		// Links that depend on the NEIGHBORS rows of a reporter with a failed step (§6.8): the rows are missing in this run, which says nothing about the cable.
		// They are held against every claim and left out of the legacy cleanup.
		$frozen_keys = [];
		foreach ($frozen_edges as $frozen_id) {
			if (isset($links[$frozen_id])) {
				$frozen_keys[$links[$frozen_id]['key']] = true;
			}
		}
		// The Device at the far end of a link as seen from one of its ports ($port is the port the link is read from).
		$far_device_from = static function (array $link, int $port) use ($device_of): ?int {
			if ($link['type'] === 'device_link') {
				return $link['a'] === $port ? $link['b'] : null;
			}
			return $device_of($link['a'] === $port ? $link['b'] : $link['a']);
		};
		// The ports of a link that can be named as "local" together with the far Device each implies (cable seen from
		// each end): a physical_link implies two, a device_link one.
		$implied_pairs = static function (array $link) use ($device_of): array {
			if ($link['type'] === 'device_link') {
				return [[$link['a'], $link['b']]];
			}
			return [[$link['a'], $device_of($link['b'])], [$link['b'], $device_of($link['a'])]];
		};

		// 2. What the latest snapshots say, whatever became of the candidate: the rules that contain each entity (from
		//    either end), and the newest clock among them (only used to date a legacy cleanup).
		$rules_port = [];      // key => itemid set, exactly that entity
		$rules_of = [];        // same, plus device-level sightings of a port-level link (they still say it is there)
		$support_clock = [];
		$groups = [];          // local port => token set: the neighbors on the port from ALL the rules (topology-1-model.md "Several neighbors or claims on one port")
		$rule_tokens = [];     // local port => itemid => token set
		$device_cands = [];    // local port => [candidate index] of the device-level candidates
		foreach ($candidates as $i => $candidate) {
			[$key] = $candidate_entity($candidate);
			if ($key === null) {
				continue;
			}
			$rules_port[$key][$candidate['itemid']] = true;
			$rules_of[$key][$candidate['itemid']] = true;
			$support_clock[$key] = max($support_clock[$key] ?? 0, $candidate['clock']);
			$token = $candidate['level'] === 'device' ? 'd'.$candidate['remote_device_id'] : (string) $candidate['remote_port_id'];
			$rule_tokens[$candidate['local_port_id']][$candidate['itemid']][$token] = true;
			$groups[$candidate['local_port_id']][$token] = true;
			if ($candidate['level'] === 'device') {
				$device_cands[$candidate['local_port_id']][] = $i;
			}
		}
		foreach ($links as $link) {
			if ($link['type'] !== 'physical_link') {
				continue;
			}
			foreach ($implied_pairs($link) as [$local, $device]) {
				foreach ($device_cands[$local] ?? [] as $i) {
					if ($candidates[$i]['remote_device_id'] === $device) {
						$rules_of[$link['key']][$candidates[$i]['itemid']] = true;
						$support_clock[$link['key']] = max($support_clock[$link['key']] ?? 0, $candidates[$i]['clock']);
					}
				}
			}
		}
		// Held against a claim: some rule OUTSIDE the claim's own rules still contains the entity.
		$held = static function (string $entity_key, string $claim_key) use (&$rules_of, &$frozen_keys): bool {
			return isset($frozen_keys[$entity_key]) || (bool) array_diff_key($rules_of[$entity_key] ?? [], $rules_of[$claim_key] ?? []);
		};

		// Helpers for rewriting a stored link's attrs (used by the refinement below). There is no port-level ->
		// device-level conversion here: a link's precision only goes up (topology-1-model.md "Lost precision").
		$set_attrs = static function (int $id, array $attrs) use ($pdo): void {
			$pdo->prepare('UPDATE topo_edges SET attrs = ? WHERE id = ?')
				->execute([json_encode($attrs, JSON_THROW_ON_ERROR), $id]);
		};
		$load_attrs = static function (int $id) use ($pdo): array {
			return json_decode((string) $pdo->query("SELECT attrs FROM topo_edges WHERE id = {$id}")->fetchColumn(), true,
				512, JSON_THROW_ON_ERROR) ?: [];
		};
		// 3. Legacy data: pre-spec ingest let a cable move leave two active discovered links on one port. Keep one per
		//    port, once, whether or not a candidate arrives for that port. A partial run cannot see every reporter, so it
		//    leaves the data alone (topology-1-model.md "Runs": partial runs).
		if ($replacement_enabled) {
			$active_on = static function (int $port) use (&$links, &$by_port, $is_active_discovered): array {
				$out = [];
				foreach ($by_port[$port] ?? [] as $lid) {
					if ($is_active_discovered($links[$lid])) {
						$out[] = $lid;
					}
				}
				return $out;
			};
			$supersede_legacy = static function (int $lid, int $at) use ($pdo, &$links, &$summary, &$frozen_keys): void {
				if (isset($frozen_keys[$links[$lid]['key']])) {
					return;
				}
				$attrs = json_decode((string) $pdo->query("SELECT attrs FROM topo_edges WHERE id = {$lid}")->fetchColumn(),
					true, 512, JSON_THROW_ON_ERROR);
				$attrs['superseded_at'] = $at;
				$pdo->prepare('UPDATE topo_edges SET attrs = ? WHERE id = ?')
					->execute([json_encode($attrs, JSON_THROW_ON_ERROR), $lid]);
				$links[$lid]['superseded_at'] = $at;
				$summary['links_superseded']++;
			};
			$ports = array_keys($by_port);
			sort($ports);
			// topology-1-model.md "Legacy cleanup": an active discovered link that shares a port with a manual link: manual wins.
			foreach ($ports as $port) {
				$has_manual = false;
				foreach ($by_port[$port] as $lid) {
					$has_manual = $has_manual || $links[$lid]['manual'];
				}
				if (!$has_manual) {
					continue;
				}
				foreach ($active_on($port) as $lid) {
					$supersede_legacy($lid, max($links[$lid]['seen'] ?? 0, $support_clock[$links[$lid]['key']] ?? 0));
				}
			}
			foreach ($ports as $port) {
				$active = $active_on($port);
				if (count($active) < 2) {
					continue;
				}
				// The link some snapshot still reports stays; then the one confirmed most recently; then the oldest id.
				usort($active, static function (int $x, int $y) use (&$links, &$rules_of): int {
					$sx = isset($rules_of[$links[$x]['key']]) ? 1 : 0;
					$sy = isset($rules_of[$links[$y]['key']]) ? 1 : 0;
					return [$sy, $links[$y]['seen'] ?? 0, $x] <=> [$sx, $links[$x]['seen'] ?? 0, $y];
				});
				array_shift($active);
				foreach ($active as $lid) {
					$supersede_legacy($lid, max($links[$lid]['seen'] ?? 0, $support_clock[$links[$lid]['key']] ?? 0));
				}
			}
		}

		// A MANUAL device_link is refined too (topology-1-model.md "Confirmation by discovery": discovery that identifies its far port turns it into a
		// discovered physical_link, same edge id), unless the operator acknowledged a neighbor on it: then it stays manual.
		$refinable = static function (array $claim, array $link) use ($device_of): bool {
			if ($claim['dev'] !== null || $link['type'] !== 'device_link' || $link['acknowledged']) {
				return false;
			}
			return ($link['a'] === $claim['a'] && $link['b'] === $device_of($claim['b']))
				|| ($link['a'] === $claim['b'] && $link['b'] === $device_of($claim['a']));
		};

		// 4. Classify every candidate: fixed outcome, shadowed by a manual link, part of a several-neighbors group, or a claim.
		$result = [];      // index => ['outcome' => ..., 'edge_id' => ..., 'key' => key|null, 'lower' => bool]
		$claims = [];      // key => ['a' =>, 'b' => port|null, 'dev' => device|null, 'occupies' => [], 'T' =>, 'indexes' => []]
		$confirming = [];  // key => [indexes] of several-neighbors candidates that confirm an existing active link
		$counted_groups = [];
		foreach ($candidates as $i => $candidate) {
			if ($candidate['pre_outcome'] !== null) {
				$result[$i] = ['outcome' => $candidate['pre_outcome'], 'edge_id' => null, 'key' => null];
				continue;
			}
			[$key, $occupies] = $candidate_entity($candidate);
			if ($key === null) {
				$result[$i] = ['outcome' => 'device_only', 'edge_id' => null, 'key' => null];
				continue;
			}
			$port = $candidate['local_port_id'];

			// Manual wins (topology-1-model.md "Manual wins"): unchanged, untouched by the replacement rule. Except that a manual
			// device_link is the same cable as a port-level candidate that identifies its far port: that is a confirmation.
			$as_claim = ['dev' => $candidate['level'] === 'device' ? $candidate['remote_device_id'] : null,
				'a' => $port, 'b' => $candidate['level'] === 'device' ? null : $candidate['remote_port_id']];
			$manual = [];
			foreach ($occupies as $touched) {
				foreach ($by_port[$touched] ?? [] as $lid) {
					if ($links[$lid]['manual'] && $links[$lid]['key'] !== $key && !$refinable($as_claim, $links[$lid])) {
						$manual[] = $lid;
					}
				}
			}
			if ($manual) {
				$result[$i] = ['outcome' => 'shadowed', 'edge_id' => min($manual), 'key' => null];
				continue;
			}

			// topology-1-model.md "Several neighbors or claims on one port": several neighbors on one port in one snapshot are not a sequence. None replaces another; the
			// existing active link on the port, if it is one of them, stays.
			// The neighbors on a port from all the rules are one set as soon as some rule shows several of them (LLDP shows X and Y,
			// CDP shows Z: the set is X, Y, Z). When every rule shows one neighbor, the claims of different rules are decided below
			// (competing claims, or §8.4 against the active link).
			$group_key = $port;
			$several = false;
			foreach ($rule_tokens[$port] ?? [] as $tokens) {
				$several = $several || count($tokens) >= 2;
			}
			if ($several) {
				if (!isset($counted_groups[$group_key])) {
					$counted_groups[$group_key] = true;
					$summary['ports_ambiguous']++;
				}
				$holder = null;
				foreach ($by_port[$port] ?? [] as $lid) {
					$link = $links[$lid];
					$token = $link['type'] === 'device_link' ? ($link['a'] === $port ? 'd'.$link['b'] : null)
						: (string) ($link['a'] === $port ? $link['b'] : $link['a']);
					if ($token !== null && $is_active_discovered($link) && isset($groups[$group_key][$token])) {
						$holder = $holder ?? $link;
					}
				}
				if ($holder !== null && $holder['key'] === $key) {
					$result[$i] = ['outcome' => 'applied', 'edge_id' => $holder['id'], 'key' => $key];
					$confirming[$key][] = $i;
					continue;
				}
				// the same Device as the one that holds the port, seen at device level by another rule: the same cable at a lower
				// precision, decided with the claims below (4a), not another neighbor
				if (!($holder !== null && $holder['type'] === 'physical_link' && $candidate['level'] === 'device'
						&& $far_device_from($holder, $port) === $candidate['remote_device_id'])) {
					$result[$i] = ['outcome' => 'ambiguous', 'edge_id' => null, 'key' => null];
					continue;
				}
			}

			$claims[$key] ??= ['a' => $port, 'b' => $candidate['level'] === 'device' ? null : $candidate['remote_port_id'],
				'dev' => $candidate['level'] === 'device' ? $candidate['remote_device_id'] : null,
				'occupies' => $occupies, 'T' => 0, 'indexes' => []];
			$claims[$key]['T'] = max($claims[$key]['T'], $candidate['clock']);
			$claims[$key]['indexes'][] = $i;
		}
		ksort($claims);

		// 4a. Same cable at two precisions (topology-1-model.md "Several neighbors or claims on one port", same Device at several precisions): a device-level claim whose local port and far Device
		//     are implied by a port-level claim or by an active port-level link is not a claim of its own. It confirms
		//     that entity at lower precision and takes its outcome.
		$lower_of = []; // device-level claim key => ['claim' => port-level claim key] | ['link' => link id]
		foreach ($claims as $key => $claim) {
			if ($claim['dev'] === null) {
				continue;
			}
			$target = null;
			foreach ($claims as $other_key => $other) {
				if ($other['dev'] !== null) {
					continue;
				}
				if (($other['a'] === $claim['a'] && $device_of($other['b']) === $claim['dev'])
						|| ($other['b'] === $claim['a'] && $device_of($other['a']) === $claim['dev'])) {
					$target = ['claim' => $other_key];
					break;
				}
			}
			if ($target === null) {
				foreach ($by_port[$claim['a']] ?? [] as $lid) {
					$link = $links[$lid];
					if ($link['type'] === 'physical_link' && $is_active_discovered($link)
							&& $far_device_from($link, $claim['a']) === $claim['dev']) {
						$target = ['link' => $lid];
						break;
					}
				}
			}
			if ($target !== null) {
				$lower_of[$key] = $target;
			}
		}
		foreach ($lower_of as $key => $target) {
			if (isset($target['claim'])) {
				foreach ($rules_of[$key] ?? [] as $itemid => $unused) {
					$rules_of[$target['claim']][$itemid] = true; // the device-level sighting says the cable is there
				}
			}
			unset($claims[$key]);
		}

		// 4b. topology-1-model.md "Several neighbors or claims on one port": competing new claims: a port without an active link that several different entities claim
		//     (X and Y both report R:P; R's LLDP shows X on P while its CDP shows Y). Nothing blocks any of them, the
		//     port simply has several claimants: no link, all ambiguous. Decided on the claims as they are, once.
		$claimants_of = [];
		foreach ($claims as $key => $claim) {
			foreach ($claim['occupies'] as $touched) {
				$claimants_of[$touched][$key] = true;
			}
		}
		$competing = [];
		ksort($claimants_of);
		foreach ($claimants_of as $port => $keys) {
			if (count($keys) < 2) {
				continue;
			}
			foreach ($by_port[$port] ?? [] as $lid) {
				if ($links[$lid]['manual'] || $is_active_discovered($links[$lid])) {
					continue 2; // the port has an active link: topology-1-model.md "Replacement of discovered links" decides, as usual
				}
			}
			foreach ($keys as $key => $unused) {
				$competing[$key] = true;
			}
			$summary['ports_ambiguous']++;
		}
		foreach (array_keys($competing) as $key) {
			foreach ($claims[$key]['indexes'] as $i) {
				$result[$i] = ['outcome' => 'ambiguous', 'edge_id' => null, 'key' => null];
			}
			unset($claims[$key]);
		}

		// 4c. topology-1-model.md §8.5 (2.40, 2.41): a port P with an active discovered link L, where the claims on P (the neighbors that the rules of the reporter show on P) resolve to
		//     two or more different Devices and NO rule on P shows L's other end. The claims do not compete one by one against L (§8.4): the port is in the same state as a port
		//     without an active link, so all of them are `ambiguous` (no edge_id) and none makes a link. L stays while something supports it (the far end's latest snapshot, or a
		//     held link of a failed reporter); with no support it is superseded at T = the newest clock of the snapshots on P that contradict it, and nothing replaces it. The next
		//     run sees P without an active link: 4b gives the same result. Not changed: a rule on P still shows L's other end, or the claims resolve to one Device (§8.4 per candidate).
		$linked_ambiguous_supersede = []; // link key => T
		$port_candidates = [];            // local port => candidate indexes that are claims on it (not fixed by a pre-outcome, not shadowed)
		foreach ($candidates as $i => $candidate) {
			if ($candidate['pre_outcome'] === null && ($candidate_entity($candidate)[0] ?? null) !== null
					&& (!isset($result[$i]) || $result[$i]['outcome'] === 'ambiguous')) {
				$port_candidates[$candidate['local_port_id']][] = $i;
			}
		}
		ksort($port_candidates);
		foreach ($port_candidates as $port => $indexes) {
			$active = [];
			foreach ($by_port[$port] ?? [] as $lid) {
				if ($is_active_discovered($links[$lid])) {
					$active[] = $links[$lid];
				}
			}
			if (count($active) !== 1) {
				continue;
			}
			$link = $active[0];
			$devices = [];
			$shows_link = false;
			foreach ($indexes as $i) {
				$c = $candidates[$i];
				$devices[$c['remote_device_id']] = true;
				[$entity_key] = $candidate_entity($c);
				// a rule on P shows L's other end: the same entity, or the same Device at device level (the same cable at a lower precision, 4a)
				if ($entity_key === $link['key'] || ($c['level'] === 'device' && $link['type'] === 'physical_link'
						&& $far_device_from($link, $port) === $c['remote_device_id'])) {
					$shows_link = true;
				}
			}
			if ($shows_link || count($devices) < 2) {
				continue;
			}
			if (!isset($counted_groups[$port]) && !isset($claimants_counted[$port])) {
				$claimants_counted[$port] = true;
				$summary['ports_ambiguous']++;
			}
			$newest = 0;
			foreach ($indexes as $i) {
				$result[$i] = ['outcome' => 'ambiguous', 'edge_id' => null, 'key' => null];
				$newest = max($newest, $candidates[$i]['clock']);
				// a claim entity that these candidates feed is no claim any more: the far end's candidate of the same entity must not make the link either
				[$entity_key] = $candidate_entity($candidates[$i]);
				if (isset($claims[$entity_key])) {
					foreach ($claims[$entity_key]['indexes'] as $j) {
						$result[$j] = ['outcome' => 'ambiguous', 'edge_id' => null, 'key' => null];
					}
					unset($claims[$entity_key]);
				}
			}
			$supported = isset($frozen_keys[$link['key']]) || !empty($rules_of[$link['key']]);
			if (!$supported && $replacement_enabled) {
				$linked_ambiguous_supersede[$link['key']] = min($linked_ambiguous_supersede[$link['key']] ?? PHP_INT_MAX, $newest);
			}
		}

		// 5. Which claims win, and which existing links they supersede. A device_link that is the same cable as a
		//    port-level claim (forward half R:P -> D, or reverse half D:Q -> R) is refined by that claim (topology-1-model.md "Refinement: device-level → port-level"), not
		//    contradicted by it.
		$claims_by_port = [];
		foreach ($claims as $key => $claim) {
			foreach ($claim['occupies'] as $touched) {
				$claims_by_port[$touched][] = $key;
			}
		}
		$wins = [];
		$blockers = [];      // claim key => [entity keys touching its ports]
		foreach ($claims as $key => $claim) {
			$entities = [];
			foreach ($claim['occupies'] as $touched) {
				foreach ($by_port[$touched] ?? [] as $lid) {
					if ($links[$lid]['key'] !== $key && $is_active_discovered($links[$lid])
							&& !$refinable($claim, $links[$lid])) {
						$entities[$links[$lid]['key']] = 'link';
					}
				}
				foreach ($claims_by_port[$touched] ?? [] as $other_key) {
					if ($other_key !== $key) {
						$entities[$other_key] ??= 'claim';
					}
				}
			}
			$blockers[$key] = $entities;
			$ok = true;
			foreach ($entities as $entity_key => $kind) {
				if ($held($entity_key, $key)) {
					$ok = false;
				}
				// A run scoped with --zabbix-host sees only some reporters: it cannot know that nothing else
				// supports an existing link, so it never replaces one (the old, conservative behaviour).
				if ($kind === 'link' && !$replacement_enabled) {
					$ok = false;
				}
			}
			$wins[$key] = $ok;
		}

		$superseded = $linked_ambiguous_supersede; // link key => T (the earliest winning contradiction; 4c: the newest clock of the claims on its port, nothing replaces it)
		$superseded_by = [];
		foreach ($claims as $key => $claim) {
			if (!$wins[$key]) {
				continue;
			}
			foreach ($blockers[$key] as $entity_key => $kind) {
				if ($kind === 'link' && isset($link_by_key[$entity_key])) {
					$superseded[$entity_key] = min($superseded[$entity_key] ?? PHP_INT_MAX, $claim['T']);
					$superseded_by[$entity_key][] = $key;
				}
			}
		}

		// 6. Write links: winners (created / revived / refined), stayers (confirmed), then the superseded ones.
		//    A manual link that hides a neighbor, or that has acknowledged neighbors, stays manual even when another
		//    reporter confirms it: a neighbor that flickers out of a snapshot must not make it upgradeable.
		$shadowing_manual = [];
		foreach ($result as $r) {
			if ($r['outcome'] === 'shadowed') {
				$shadowing_manual[$r['edge_id']] = true;
			}
		}
		// topology-1-model.md "Refinement: device-level → port-level": the port-level claim X = R:P <-> D:Q takes over an existing device_link of the same cable: the forward
		// half R:P -> D is converted in place (edge id kept), the reverse half D:Q -> R is absorbed (deleted, its clock
		// folded into the side for Q). Without a forward half, a reverse half is converted instead.
		$refine = static function (string $key, array $claim) use ($pdo, $pair_key, $device_of, $refinable, $candidates, &$links,
				&$by_port, &$link_by_key, $load_attrs, $set_attrs, $validate_edge, &$summary): void {
			$forward = null;
			$reverse = null;
			foreach ([$claim['a'], $claim['b']] as $touched) {
				foreach ($by_port[$touched] ?? [] as $lid) {
					$link = $links[$lid];
					if ($link['superseded_at'] !== null || !$refinable($claim, $link)) {
						continue;
					}
					if ($link['a'] === $claim['a']) {
						$forward = $forward ?? $link;
					}
					else {
						$reverse = $reverse ?? $link;
					}
				}
			}
			$converted = $forward ?? $reverse;
			if ($converted === null) {
				return;
			}
			$absorbed = $forward !== null ? $reverse : null;
			$existing_id = $link_by_key[$key] ?? null; // a physical_link row for this very pair (e.g. superseded earlier)
			$attrs = $load_attrs($converted['id']);
			$side_of_local = (int) ($attrs['last_seen_src'] ?? 0); // the converted device_link's src is its reporter's port
			$other_side = (int) ($attrs['last_seen_dst'] ?? 0);
			if ($absorbed !== null) {
				$other_side = max($other_side, (int) ($load_attrs($absorbed['id'])['last_seen_src'] ?? 0));
			}
			$src_port = $converted['a'];
			$other_port = $src_port === $claim['a'] ? $claim['b'] : $claim['a'];
			$new_src = min($src_port, $other_port);
			$new_dst = max($src_port, $other_port);
			// A manual link that discovery confirms becomes a discovered one (topology-1-model.md "Confirmation by discovery").
			$new = ['discovered_via' => $converted['manual'] ? $candidates[$claim['indexes'][0]]['source']
				: ($attrs['discovered_via'] ?? 'lldp')];
			$sides = [$src_port => $side_of_local, $other_port => $other_side];
			if ($sides[$new_src] > 0) {
				$new['last_seen_src'] = $sides[$new_src];
			}
			if ($sides[$new_dst] > 0) {
				$new['last_seen_dst'] = $sides[$new_dst];
			}
			$new['last_seen'] = max($sides[$new_src], $sides[$new_dst], (int) ($attrs['last_seen'] ?? 0));
			foreach (['superseded_at'] as $keep) {
				if (array_key_exists($keep, $attrs)) {
					$new[$keep] = $attrs[$keep];
				}
			}
			if ($existing_id !== null) {
				// The pair already has an edge: fold the device_link into it instead of creating a duplicate.
				$into = $load_attrs($existing_id);
				foreach (['last_seen_src', 'last_seen_dst'] as $field) {
					if (isset($new[$field])) {
						$into[$field] = max((int) ($into[$field] ?? 0), $new[$field]);
					}
				}
				$into['last_seen'] = max((int) ($into['last_seen_src'] ?? 0), (int) ($into['last_seen_dst'] ?? 0));
				$set_attrs($existing_id, $into);
				$pdo->prepare('DELETE FROM topo_edges WHERE id = ?')->execute([$converted['id']]);
			}
			else {
				$validate_edge('physical_link', $new_src, $new_dst);
				$pdo->prepare("UPDATE topo_edges SET type = 'physical_link', src_id = ?, dst_id = ?, attrs = ? WHERE id = ?")
					->execute([$new_src, $new_dst, json_encode($new, JSON_THROW_ON_ERROR), $converted['id']]);
			}
			if ($absorbed !== null) {
				$pdo->prepare('DELETE FROM topo_edges WHERE id = ?')->execute([$absorbed['id']]);
			}
			// Keep the in-memory picture in step: the converted / absorbed device_links are gone as such.
			foreach (array_filter([$converted, $absorbed]) as $gone) {
				unset($link_by_key[$gone['key']]);
				foreach ($gone['occupies'] as $port) {
					$by_port[$port] = array_values(array_diff($by_port[$port] ?? [], [$gone['id']]));
				}
				unset($links[$gone['id']]);
			}
			if ($existing_id === null) {
				$links[$converted['id']] = ['id' => $converted['id'], 'type' => 'physical_link', 'a' => $new_src, 'b' => $new_dst,
					'key' => $key, 'occupies' => [$new_src, $new_dst], 'manual' => false, 'seen' => $new['last_seen'],
					'acknowledged' => false,
					'superseded_at' => $new['superseded_at'] ?? null];
				$by_port[$new_src][] = $converted['id'];
				$by_port[$new_dst][] = $converted['id'];
				$link_by_key[$key] = $converted['id'];
			}
			$summary['links_refined']++;
		};

		$write_link = static function (string $key, array $indexes) use ($candidates, $snap_link, $snap_device_link, &$links,
				&$link_by_key, &$shadowing_manual): int {
			$id = 0;
			$existing = $link_by_key[$key] ?? null;
			$keep_manual = $existing !== null && $links[$existing]['manual']
				&& (isset($shadowing_manual[$existing]) || $links[$existing]['acknowledged']);
			foreach ($indexes as $i) {
				$c = $candidates[$i];
				if ($c['level'] === 'device') {
					$id = $snap_device_link($c['local_port_id'], $c['remote_device_id'], $c['source'], $c['far_port_reason'],
						$c['far_port_hint'], $c['clock'], $keep_manual);
				}
				else {
					$id = $snap_link($c['local_port_id'], $c['remote_port_id'], $c['source'], $c['local_port_id'], $c['clock'],
						$keep_manual);
				}
			}
			return $id;
		};
		$after = [];        // key => link id, for every entity that is an active link once this run is done
		foreach ($claims as $key => $claim) {
			$existing = isset($link_by_key[$key]) ? $links[$link_by_key[$key]] : null;
			$stays = $existing !== null && ($existing['manual'] || $existing['superseded_at'] === null)
				&& !isset($superseded[$key]);
			if ($wins[$key] || $stays) {
				if ($claim['dev'] === null) {
					$refine($key, $claim);
					$existing = isset($link_by_key[$key]) ? $links[$link_by_key[$key]] : null;
				}
				$id = $write_link($key, $claim['indexes']);
				if ($existing !== null && $existing['superseded_at'] !== null && $wins[$key]) {
					$attrs = json_decode((string) $pdo->query("SELECT attrs FROM topo_edges WHERE id = {$id}")->fetchColumn(),
						true, 512, JSON_THROW_ON_ERROR);
					unset($attrs['superseded_at']);
					$pdo->prepare('UPDATE topo_edges SET attrs = ? WHERE id = ?')
						->execute([json_encode($attrs, JSON_THROW_ON_ERROR), $id]);
					$summary['links_revived']++;
				}
				$after[$key] = $id;
			}
		}
		foreach ($confirming as $key => $indexes) {
			$after[$key] = $write_link($key, $indexes);
		}
		foreach ($superseded as $key => $at) {
			$id = $link_by_key[$key];
			$attrs = json_decode((string) $pdo->query("SELECT attrs FROM topo_edges WHERE id = {$id}")->fetchColumn(),
				true, 512, JSON_THROW_ON_ERROR);
			$attrs['superseded_at'] = $at;
			$pdo->prepare('UPDATE topo_edges SET attrs = ? WHERE id = ?')
				->execute([json_encode($attrs, JSON_THROW_ON_ERROR), $id]);
			$summary['links_superseded']++;
		}
		// Existing active links that stay are active too; a conflict points at them.
		foreach ($links as $link) {
			if (($link['manual'] || $link['superseded_at'] === null) && !isset($superseded[$link['key']])) {
				$after[$link['key']] ??= $link['id'];
			}
		}

		// 7. Outcomes of the claims.
		foreach ($claims as $key => $claim) {
			foreach ($claim['indexes'] as $i) {
				if (isset($after[$key])) {
					$result[$i] = ['outcome' => 'applied', 'edge_id' => $after[$key], 'key' => $key];
					continue;
				}
				// The link that keeps the port: an existing/created active link among the entities that beat this
				// claim (support at least as recent), or the winner that superseded this claim's own link.
				$holding = [];
				foreach (array_merge(array_keys($blockers[$key]), $superseded_by[$key] ?? []) as $entity_key) {
					if (isset($after[$entity_key]) && ($held($entity_key, $key)
							|| in_array($entity_key, $superseded_by[$key] ?? [], true))) {
						$holding[] = $after[$entity_key];
					}
				}
				foreach ($superseded_by[$key] ?? [] as $winner_key) {
					if (isset($after[$winner_key])) {
						$holding[] = $after[$winner_key];
					}
				}
				$result[$i] = ['outcome' => 'conflict', 'edge_id' => $holding ? min($holding) : (isset($link_by_key[$key])
					? $link_by_key[$key] : null), 'key' => $key];
			}
		}
		// 7b. Device-level sightings of a cable known at port level (4a): they take the outcome of that cable. A sighting
		//     of an existing active link only advances that link's clocks (last_seen_port stays), and says "lower".
		foreach ($candidates as $i => $candidate) {
			[$key] = $candidate_entity($candidate);
			if ($key === null || !isset($lower_of[$key]) || $candidate['level'] !== 'device') {
				continue;
			}
			$target = $lower_of[$key];
			if (isset($target['claim'])) {
				$first = $claims[$target['claim']]['indexes'][0];
				$result[$i] = ['outcome' => $result[$first]['outcome'], 'edge_id' => $result[$first]['edge_id'],
					'key' => $target['claim'], 'lower' => true];
				continue;
			}
			$result[$i] = ['outcome' => 'applied', 'edge_id' => $target['link'], 'key' => $links[$target['link']]['key'],
				'lower' => true];
			$snap_link_confirm_lower($target['link'], $candidate['local_port_id'], $candidate['clock']);
		}

		// 7c. last_seen_dst of a device_link: the far Device's own reporter names the local Device on some port.
		$dev_links = $pdo->query("SELECT id, src_id, dst_id, attrs FROM topo_edges WHERE type = 'device_link'")
			->fetchAll(PDO::FETCH_ASSOC);
		foreach ($dev_links as $edge) {
			$local_device = $device_of((int) $edge['src_id']);
			$reply = 0;
			foreach ($candidates as $candidate) {
				if ($candidate['remote_device_id'] === $local_device
						&& $device_of($candidate['local_port_id']) === (int) $edge['dst_id']) {
					$reply = max($reply, $candidate['clock']);
				}
			}
			$attrs = json_decode($edge['attrs'], true, 512, JSON_THROW_ON_ERROR) ?: [];
			if ($reply > (int) ($attrs['last_seen_dst'] ?? 0)) {
				$attrs['last_seen_dst'] = $reply;
				$attrs['last_seen'] = max((int) ($attrs['last_seen_src'] ?? 0), $reply);
				$pdo->prepare('UPDATE topo_edges SET attrs = ? WHERE id = ?')
					->execute([json_encode($attrs, JSON_THROW_ON_ERROR), $edge['id']]);
			}
		}

		// 8. Observations mirror the latest snapshot of each rule.
		$touched = [];
		foreach ($candidates as $i => $candidate) {
			$outcome = $result[$i]['outcome'];
			$precision = $candidate['pre_outcome'] !== null ? null : $candidate['level'];
			$touched[$candidate['itemid']][] = $snap_observation($candidate['itemid'], $candidate['local_port_id'],
				$candidate['remote_key'], $candidate['remote_attrs'], $outcome, $result[$i]['edge_id'],
				$candidate['remote_device_id'], $candidate['clock'], $precision,
				$candidate['level'] === 'device' ? $candidate['far_port_reason'] : null, !empty($result[$i]['lower']));
			$summary['observations'][$outcome]++;
		}
		foreach ($neighbor_snapshots as $itemid => $snapshot) {
			$all = $pdo->prepare('SELECT id FROM topo_observations WHERE itemid = ?');
			$all->execute([$itemid]);
			$stale = array_diff(array_map('intval', $all->fetchAll(PDO::FETCH_COLUMN)), $touched[$itemid] ?? []);
			foreach (array_chunk($stale, 500) as $chunk) {
				$pdo->prepare('DELETE FROM topo_observations WHERE id IN ('.implode(',', array_fill(0, count($chunk), '?')).')')
					->execute($chunk);
			}
		}

		// 9. Diagnostics (topology-1-model.md "Run summary"): active device-level links at the end of the run, per reason.
		$summary['links_device_level'] = 0;
		$summary['device_level_reasons'] = [];
		foreach ($pdo->query("SELECT attrs FROM topo_edges WHERE type = 'device_link'")->fetchAll(PDO::FETCH_COLUMN) as $raw) {
			$attrs = json_decode($raw, true, 512, JSON_THROW_ON_ERROR) ?: [];
			if (isset($attrs['superseded_at'])) {
				continue;
			}
			$summary['links_device_level']++;
			$reason = (string) ($attrs['far_port_reason'] ?? 'unknown');
			$summary['device_level_reasons'][$reason] = ($summary['device_level_reasons'][$reason] ?? 0) + 1;
		}
	}

	// Manual links contradicted by discovery at the end of a run (topology-1-model.md "Contradiction"): a
	// `shadowed` observation of the link whose hidden neighbor Device is not in its attrs.shadow_ack (a shadow without
	// a Device cannot be acknowledged). The hidden neighbor is decided by topo_hidden_neighbor(), the one definition shared
	// with CTopologyPrototype: for a third-party observation it is the reporter, not the end of the link it reports.
	// Ingest only reads shadow_ack here to count; it never writes it.
	public function countContradictedManualLinks(): int {
		$pdo = $this->run->pdo;
		$links = [];
		$device_of = $pdo->prepare("SELECT device_id FROM topo_nodes WHERE id = ? AND type = 'port'");
		foreach ($pdo->query("SELECT id, type, src_id, dst_id, attrs FROM topo_edges WHERE type IN ('physical_link', 'device_link')")
				->fetchAll(PDO::FETCH_ASSOC) as $edge) {
			$attrs = json_decode($edge['attrs'], true, 512, JSON_THROW_ON_ERROR) ?: [];
			if (($attrs['discovered_via'] ?? 'manual') === 'manual') {
				// The Ports and Devices the manual link itself joins: a neighbor that is one of those Devices is the link's own
				// far end seen at another precision (topology-1-model.md "Confirmation by discovery"), not a hidden neighbor.
				$end_ports = [];
				$end_devices = [];
				foreach ($edge['type'] === 'device_link' ? [[(int) $edge['src_id'], 'port'], [(int) $edge['dst_id'], 'device']]
						: [[(int) $edge['src_id'], 'port'], [(int) $edge['dst_id'], 'port']] as [$node, $kind]) {
					if ($kind === 'device') {
						$end_devices[] = $node;
						continue;
					}
					$end_ports[] = $node;
					$device_of->execute([$node]);
					if (($device = $device_of->fetchColumn()) !== false && $device !== null) {
						$end_devices[] = (int) $device;
					}
				}
				$links[(int) $edge['id']] = ['ack' => array_fill_keys(array_map('strval', array_column($attrs['shadow_ack'] ?? [], 'device_id')), true),
					'end_ports' => $end_ports, 'end_devices' => $end_devices];
			}
		}
		$contradicted = [];
		// Only observations of rules that still have a usable snapshot (the test of $clean_stale_observations).
		foreach ($pdo->query("SELECT o.edge_id, o.device_id, o.local_port_id, p.device_id AS local_device_id FROM topo_observations o".
				" JOIN items i ON i.itemid = o.itemid AND i.status = 0".
				" JOIN hosts h ON h.hostid = i.hostid AND h.status = 0".
				" JOIN topo_lld_snapshot s ON s.itemid = o.itemid AND s.topology_type = i.topology_type".
				" LEFT JOIN topo_nodes p ON p.id = o.local_port_id".
				" WHERE o.outcome = 'shadowed' AND o.edge_id IS NOT NULL")->fetchAll(PDO::FETCH_ASSOC) as $observation) {
			$edge_id = (int) $observation['edge_id'];
			if (!isset($links[$edge_id])) {
				continue;
			}
			$hidden = topo_hidden_neighbor((int) $observation['local_port_id'],
				$observation['local_device_id'] === null ? null : (int) $observation['local_device_id'],
				$observation['device_id'] === null ? null : (int) $observation['device_id'],
				$links[$edge_id]['end_ports'], $links[$edge_id]['end_devices']);
			if ($hidden['kind'] === 'unknown'
					|| ($hidden['kind'] === 'hidden' && !isset($links[$edge_id]['ack'][(string) $hidden['device_id']]))) {
				$contradicted[$edge_id] = true;
			}
		}
		return count($contradicted);
	}
}
