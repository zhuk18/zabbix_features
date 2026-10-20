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
 * The graph is treated as undirected: a represented_by/physical_link edge, or a live-resolved
 * Host->Proxy monitoring pair (topology-1-model.md "Monitoring assignment (`MONITORED_BY`) — live, not stored"), counts as one hop in either
 * direction. A represented_by pair is not a hop (topology-1-model.md §12.1: a Device with an active association is drawn as its Host/Proxy, in every view):
 * the two are one place, so a view that reaches one of them contains both, at the same distance. Kept free of API calls and controller state itself — CTopologyPrototype::getAdjacency()
 * is the one that resolves the live piece; this class only walks the flat pairs it returns.
 */
class CTopologyHopScope {

    /**
     * @param array $seed_ids  topo_nodes.id values to start from (already reachable at depth 0)
     * @param int   $max_hops  maximum hop distance (>= 0); PHP_INT_MAX for "unlimited"
     * @param array $adjacency list of [id_a, id_b] pairs (topo_nodes.id on both sides), each one hop
     * @param array $free_pairs list of [id_a, id_b] pairs that cost no hop (represented_by: Device <-> Host/Proxy)
     *
     * @return array topo_nodes.id values (strings) within $max_hops of any seed, including the
     *               seeds themselves. Empty $seed_ids yields [].
     */
    public static function neighborhood(array $seed_ids, int $max_hops, array $adjacency, array $free_pairs = []): array {
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

        $free = [];
        foreach ($free_pairs as $pair) {
            $a = (string) ($pair[0] ?? '');
            $b = (string) ($pair[1] ?? '');
            if ($a === '' || $b === '' || $a === $b) {
                continue;
            }
            $free[$a][$b] = true;
            $free[$b][$a] = true;
        }

        // depth[id] = hop distance to the nearest seed; expand only below the limit. A free pair keeps the distance, so the walk is a 0-1 BFS: a node is
        // settled when it is taken from the queue (the queue is ordered by depth: free neighbors go to the front of their level, hops to the back).
        // Queue via index pointer — array_shift() is O(n) per call and this can see thousands of nodes.
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

        // Step 1: close the seeds (and every later node) over the free pairs at the same depth.
        $close = static function (string $start, int $d) use (&$depth, &$queue, $free): void {
            $stack = [$start];
            while ($stack) {
                $cur = array_pop($stack);
                foreach (array_keys($free[$cur] ?? []) as $nb) {
                    $nb = (string) $nb;
                    if (!isset($depth[$nb])) {
                        $depth[$nb] = $d;
                        $queue[] = $nb;
                        $stack[] = $nb;
                    }
                }
            }
        };
        foreach ($queue as $seed) {
            $close($seed, 0);
        }

        // Step 2: breadth first over the hop pairs; every node found is closed over the free pairs at once. The queue stays ordered by depth.
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
                $close($nb, $d + 1);
            }
        }

        // PHP silently casts numeric-string array keys to int — map back so the documented
        // string contract holds regardless of id shape.
        return array_map('strval', array_keys($depth));
    }
}
