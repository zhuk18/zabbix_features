<?php

declare(strict_types=1);

/**
 * decision_state.php — the state that the rebuild property compares (topology-1-model.md §6.7.8 "Rebuild property", task 129): the Devices by natural key (their keys, chassis, replaced chassis,
 * representation, duplicate candidates, dismissed pairs) and the active links. `nodeid`, `last_seen*`, superseded links and observations are not in it. Read from the tables (it checks storage).
 * Used by test_decisions.php and by lab/php/rebuild_view.php.
 */

require_once __DIR__.'/../../../ui/include/classes/topology/CTopologyAddress.php';
require_once __DIR__.'/../../../ui/include/classes/topology/CTopologyDecision.php';

/** The Devices by natural key: their keys, chassis, replaced chassis, representation, candidates and dismissed pairs, and the active links (rebuild property, model §6.7.8). */
function state_view(PDO $pdo): array {
	$nodes = [];
	foreach ($pdo->query('SELECT id, type, device_id, attrs, represented_by_node_id, represented_by_matched_by, host_ref, proxy_ref FROM topo_nodes')->fetchAll(PDO::FETCH_ASSOC) as $row) {
		$nodes[(int) $row['id']] = $row + ['a' => json_decode($row['attrs'], true) ?: []];
	}
	$keys = [];
	foreach ($pdo->query('SELECT device_id, `key` AS k, replaced FROM topo_identity_keys')->fetchAll(PDO::FETCH_ASSOC) as $row) {
		$keys[(int) $row['device_id']][$row['k']] = (int) $row['replaced'];
	}
	$natural = [];
	foreach ($nodes as $id => $n) {
		if ($n['type'] !== 'device') {
			continue;
		}
		$held = array_keys(array_filter($keys[$id] ?? [], static fn (int $r): bool => $r === 0));
		$ports = [];
		if (!$held) {
			foreach (CTopologyAddress::scopePorts($n['a']) as $port_id) {
				$port = $nodes[$port_id] ?? null;
				$reporter = $port !== null ? ($nodes[(int) $port['device_id']] ?? null) : null;
				$host = $reporter !== null && $reporter['represented_by_node_id'] !== null ? ($nodes[(int) $reporter['represented_by_node_id']] ?? null) : null;
				if ($host !== null && $host['host_ref'] !== null) {
					$ports[] = ['hostid' => (int) $host['host_ref'], 'name' => (string) ($port['a']['name'] ?? '')];
				}
			}
		}
		$natural[$id] = CTopologyDecision::naturalKey($held, $n['a'], $ports) ?? 'nokey:'.$id;
	}
	$view = ['devices' => [], 'links' => []];
	foreach ($natural as $id => $nk) {
		$n = $nodes[$id];
		$ks = [];
		foreach ($keys[$id] ?? [] as $k => $r) {
			$ks[] = $k.':'.$r;
		}
		sort($ks);
		$rep = null;
		if ($n['represented_by_node_id'] !== null) {
			$t = $nodes[(int) $n['represented_by_node_id']] ?? null;
			$rep = $n['represented_by_matched_by'].':'.($t === null ? '?' : ($t['host_ref'] ?? 'p'.$t['proxy_ref']));
		}
		$cand = [];
		foreach ($n['a']['duplicate_candidates'] ?? [] as $c) {
			$cand[] = $c['reason'].'>'.($c['device_id'] === null ? '-' : ($natural[(int) $c['device_id']] ?? '?')).'>'.$c['value'];
		}
		sort($cand);
		$dis = [];
		foreach ($n['a']['duplicate_dismissed'] ?? [] as $d) {
			$dis[] = $natural[(int) $d['device_id']] ?? '?';
		}
		sort($dis);
		$rc = (array) ($n['a']['replaced_chassis'] ?? []);
		sort($rc);
		$view['devices'][$nk] = ['keys' => $ks, 'chassis' => $n['a']['chassis_id'] ?? null, 'replaced_chassis' => $rc, 'represented_by' => $rep, 'candidates' => $cand, 'dismissed' => $dis];
	}
	foreach ($pdo->query("SELECT id, type, src_id, dst_id, attrs FROM topo_edges WHERE type IN ('physical_link', 'device_link')")->fetchAll(PDO::FETCH_ASSOC) as $e) {
		if (isset((json_decode($e['attrs'], true) ?: [])['superseded_at'])) {
			continue;
		}
		$end = static function (int $port_id) use ($nodes, $natural): string {
			$p = $nodes[$port_id] ?? null;
			return $p ? ($natural[(int) $p['device_id']] ?? '?').'/'.($p['a']['name'] ?? '?') : '?';
		};
		$src = $end((int) $e['src_id']);
		$dst = $e['type'] === 'device_link' ? ($natural[(int) $e['dst_id']] ?? '?') : $end((int) $e['dst_id']);
		$pair = [$src, $dst];
		if ($e['type'] === 'physical_link') {
			sort($pair);
		}
		$view['links'][] = $e['type'].' '.implode(' <> ', $pair);
	}
	sort($view['links']);
	ksort($view['devices']);

	return $view;
}

function diff_views(array $a, array $b): array {
	$out = [];
	foreach (array_unique(array_merge(array_keys($a['devices']), array_keys($b['devices']))) as $nk) {
		if (($a['devices'][$nk] ?? null) !== ($b['devices'][$nk] ?? null)) {
			$out[$nk] = [$a['devices'][$nk] ?? null, $b['devices'][$nk] ?? null];
		}
	}
	if ($a['links'] !== $b['links']) {
		$out['links'] = [array_values(array_diff($a['links'], $b['links'])), array_values(array_diff($b['links'], $a['links']))];
	}
	return $out;
}
