<?php

/**
 * CTopologyHopScope
 *
 * Depth-limited BFS over the topology_prototype node graph (topo_nodes/topo_edges) — the
 * server-side core of both "host group" and "single host + N hops" view scoping.
 *
 * Modeled on Modules\NetworkTopology\Topology\HopScope from the community network_topology
 * module, generalized to start from MULTIPLE seed nodes at once (needed for the group-filter
 * case: every host in the selected group(s) is a seed) rather than a single host.
 *
 * The graph is treated as undirected: a represented_by/monitored_by/physical_link edge counts
 * as one hop in either direction. Kept free of API calls and controller state, same as
 * CTopologyPrototype's other query methods — adjacency in, node-id list out.
 */
class CTopologyHopScope {

    /**
     * @param array $seed_ids  topo_nodes.id values to start from (already reachable at depth 0)
     * @param int   $max_hops  maximum hop distance (>= 0); PHP_INT_MAX for "unlimited"
     * @param array $adjacency list of [id_a, id_b] pairs (topo_nodes.id on both sides)
     *
     * @return array topo_nodes.id values (strings) within $max_hops of any seed, including the
     *               seeds themselves. Empty $seed_ids yields [].
     */
    public static function neighborhood(array $seed_ids, int $max_hops, array $adjacency): array {
        $adj = [];
        foreach ($adjacency as $pair) {
            $a = (string) ($pair[0] ?? '');
            $b = (string) ($pair[1] ?? '');
            if ($a === '' || $b === '' || $a === $b) {
                continue;
            }
            $adj[$a][$b] = true;
            $adj[$b][$a] = true;
        }

        // depth[id] = hop distance to the nearest seed; expand only below the limit. Queue via
        // index pointer — array_shift() is O(n) per call and this can see thousands of nodes.
        $depth = [];
        $queue = [];
        foreach ($seed_ids as $seed) {
            $seed = (string) $seed;
            if ($seed === '' || isset($depth[$seed])) {
                continue;
            }
            $depth[$seed] = 0;
            $queue[] = $seed;
        }

        for ($qi = 0; $qi < count($queue); $qi++) {
            $cur = $queue[$qi];
            $d   = $depth[$cur];
            if ($d >= $max_hops) {
                continue;
            }
            foreach (array_keys($adj[$cur] ?? []) as $nb) {
                $nb = (string) $nb;
                if (isset($depth[$nb])) {
                    continue;
                }
                $depth[$nb] = $d + 1;
                $queue[] = $nb;
            }
        }

        // PHP silently casts numeric-string array keys to int — map back so the documented
        // string contract holds regardless of id shape.
        return array_map('strval', array_keys($depth));
    }
}
