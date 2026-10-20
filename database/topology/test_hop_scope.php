#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * test_hop_scope.php — topology-1-model.md §12.1 (2.30): a Device with an active association is drawn as its Host/Proxy in every view. A scope of N hops that contains such
 * a Device also contains its Host/Proxy, and the association is not a hop. A Device without association stays a Device. No database: the walk of CTopologyHopScope.
 */

require_once __DIR__.'/../../ui/include/classes/topology/CTopologyHopScope.php';

function check(bool $condition, string $description): void {
	if (!$condition) {
		fwrite(STDERR, "FAIL: {$description}\n");
		exit(1);
	}
	echo "  ok: {$description}\n";
}

function scope(array $seeds, int $hops, array $links, array $represented): array {
	$ids = CTopologyHopScope::neighborhood($seeds, $hops, $links, $represented);
	sort($ids);
	return $ids;
}

// Host 1 (Device 2) -- cable -- Device 3 (Host 4) -- cable -- Device 5 (Proxy 6) -- cable -- Device 7 (no association) -- cable -- Device 8 (Host 9)
$links = [[2, 3], [3, 5], [5, 7], [7, 8]];
$represented = [[2, 1], [3, 4], [5, 6], [8, 9]];

check(scope([1], 0, $links, $represented) === ['1', '2'], 'scope 0 from a Host: the Host and its Device');
check(scope([1], 1, $links, $represented) === ['1', '2', '3', '4'], 'scope 1 from a Host whose neighbor is a represented Device: the neighbor Device and its Host (the association is no hop)');
check(scope([1], 2, $links, $represented) === ['1', '2', '3', '4', '5', '6'], 'scope 2: the Proxy of the next Device is in the view too');
check(scope([1], 3, $links, $represented) === ['1', '2', '3', '4', '5', '6', '7'], 'scope 3: a Device without association stays a Device (no Host node)');
check(scope([1], 4, $links, $represented) === ['1', '2', '3', '4', '5', '6', '7', '8', '9'], 'scope 4: and the Host behind the next represented Device');
check(scope([4], 1, $links, $represented) === ['1', '2', '3', '4', '5', '6'], 'scope 1 from the Host 4 in the middle: both neighbors with their Host/Proxy');
check(scope([6], 1, $links, $represented) === ['3', '4', '5', '6', '7'], 'scope 1 from a Proxy: its neighbors (Device 3 with Host 4, Device 7 without association)');
check(scope([1, 9], 1, $links, $represented) === ['1', '2', '3', '4', '7', '8', '9'], 'several seeds (a host group): the walk starts from each');
check(scope([1], PHP_INT_MAX, $links, $represented) === ['1', '2', '3', '4', '5', '6', '7', '8', '9'], 'unlimited scope (a host group view): everything reachable');
check(scope([1], 1, $links, []) === ['1'], 'without any association (no represented pairs) the Host node has no link: only itself');
check(scope([2], 1, $links, []) === ['2', '3'], 'old behavior of a pair list without free pairs is unchanged (Device seed, one hop)');

// topology-1-model.md §12.1 "View scope" (2.32): a Host monitored by a Proxy. The scope is walked over links and represented_by pairs only
// (CTopologyPrototype::getAdjacency() has no monitoring pair, getRepresentedPairs() only Device <-> Host/Proxy): the Proxy is no hop.
// Host 21 (Device 22) is monitored by Proxy 30 (Device 31); Device 31 -- cable -- Device 32. Device 22 -- cable -- Device 23.
$links2 = [[22, 23], [31, 32]];
$represented2 = [[22, 21], [31, 30]];
check(scope([21], 1, $links2, $represented2) === ['21', '22', '23'], 'a Host monitored by a Proxy: the Proxy (and what is behind it) is not in a scope of 1');
check(scope([21], PHP_INT_MAX, $links2, $represented2) === ['21', '22', '23'], 'not even without a limit: the monitoring assignment is not walked');
check(scope([30], 1, $links2, $represented2) === ['30', '31', '32'], 'a Proxy as a node of the scope is drawn as itself (its Device at the same distance)');

echo "\nALL OK\n";
