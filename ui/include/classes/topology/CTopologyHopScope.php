<?php

/**
 * CTopologyHopScope
 *
 * Depth-limited BFS over the derived topology graph (node id strings in, node id strings out) — the
 * server-side core of both "host group" and "single host + N hops" view scoping in
 * CTopologyPrototype::resolveScope().
 *
 * Generalized to start from MULTIPLE seed nodes at once (needed for the group-filter case: every
 * host in the selected group(s) is a seed) rather than a single host.
 *
 * The graph is treated as undirected: a relation from CTopologyPrototype::getAdjacency() counts as
 * one hop in either direction. Kept free of API calls — adjacency in, node-id list out.
 */
class CTopologyHopScope {

	/**
	 * @param array $seed_ids    node id strings to start from (already in scope at depth 0)
	 * @param int   $max_hops    maximum BFS depth to expand from the seeds (PHP_INT_MAX for
	 *                           "no limit" — the host-group filter mode's behavior)
	 * @param array $adjacency   list of [id_a, id_b] pairs, undirected
	 *
	 * @return array node id strings within $max_hops of any seed, seeds included
	 */
	public static function neighborhood(array $seed_ids, int $max_hops, array $adjacency): array {
		$neighbors_of = [];
		foreach ($adjacency as [$a, $b]) {
			$neighbors_of[$a][$b] = true;
			$neighbors_of[$b][$a] = true;
		}

		$visited = array_fill_keys($seed_ids, true);
		$frontier = $seed_ids;
		$hops = 0;

		while ($frontier && $hops < $max_hops) {
			$next_frontier = [];
			foreach ($frontier as $id) {
				foreach (array_keys($neighbors_of[$id] ?? []) as $neighbor_id) {
					if (!isset($visited[$neighbor_id])) {
						$visited[$neighbor_id] = true;
						$next_frontier[] = $neighbor_id;
					}
				}
			}
			$frontier = $next_frontier;
			$hops++;
		}

		return array_keys($visited);
	}
}
