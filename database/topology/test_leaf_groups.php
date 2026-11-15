#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * test_leaf_groups.php — task 090, topology-1-model.md §12.8 (2.70), FR-C V-22: leaf groups. A leaf is a Device without association with exactly one link that is not superseded,
 * counted in the whole topology; a leaf in a group has a discovered, not stale link without a severity colour and no badge; a node with 4 or more leaves in a group gets a group;
 * no groups with "Possible duplicates only". No database: the pure class CTopologyLeafGroups.
 */

require_once __DIR__.'/../../ui/include/classes/topology/CTopologyLeafGroups.php';

function check(bool $condition, string $description, $detail = null): void {
	if (!$condition) {
		fwrite(STDERR, "FAIL: {$description}".($detail !== null ? ' '.json_encode($detail) : '')."\n");
		exit(1);
	}
	echo "  ok: {$description}\n";
}

/** A switch (Host 100, its Device 1) with $n leaf Devices 11.. each linked once; the options change one leaf or the view. */
function view(int $n, array $leaf_links = [], array $device_overrides = []): array {
	$devices = [['id' => '1', 'type' => 'device', 'represented' => true, 'duplicates' => []], ['id' => '100', 'type' => 'host']];
	$relations = [['type' => 'represented_by', 'source' => '1', 'target' => '100']];
	$totals = ['1' => $n];
	for ($i = 0; $i < $n; $i++) {
		$id = (string) (11 + $i);
		$devices[] = array_merge(['id' => $id, 'type' => 'device', 'represented' => false, 'duplicates' => []], $device_overrides[$id] ?? []);
		$relations[] = array_merge(['type' => 'physical_link', 'source' => '1', 'target' => $id, 'discovered_via' => 'lldp', 'stale' => false, 'superseded_at' => null, 'severity' => null], $leaf_links[$id] ?? []);
		$totals[$id] = 1;
	}

	return [$devices, $relations, $totals];
}

function groups(array $view, array $badges = [], bool $only_duplicates = false): array {
	return CTopologyLeafGroups::compute($view[0], $view[1], $view[2], $badges, $only_duplicates);
}

echo "\n=== 3 leaves: no group, 4: a group ===\n";
check(groups(view(3)) === [], '3 leaves in a group: no group');
$g = groups(view(4));
check(count($g) === 1 && $g[0]['parent'] === '100' && $g[0]['devices'] === ['11', '12', '13', '14'], '4 leaves: one group on the Host of the switch (the Device is drawn as its Host)', $g);
check(CTopologyLeafGroups::LEAF_GROUP_MIN === 4, 'the constant is 4');

echo "\n=== a leaf that is not a leaf in a group stays a node ===\n";
$g = groups(view(5, ['11' => ['discovered_via' => 'manual']]));
check(count($g) === 1 && $g[0]['devices'] === ['12', '13', '14', '15'], 'a leaf with a manual link: not in the group (the other four are)', $g);
$g = groups(view(5, ['11' => ['stale' => true]]));
check(count($g) === 1 && $g[0]['devices'] === ['12', '13', '14', '15'], 'a leaf with a stale link: not in the group');
$g = groups(view(5, ['11' => ['severity' => 3]]));
check(count($g) === 1 && $g[0]['devices'] === ['12', '13', '14', '15'], 'a leaf with a link that has a severity colour: not in the group');
$g = groups(view(5, [], ['11' => ['duplicates' => [['device_id' => '99', 'reason' => 'same_name']]]]));
check(count($g) === 1 && $g[0]['devices'] === ['12', '13', '14', '15'], 'a leaf with a badge (possible duplicate): not in the group');
$g = groups(view(5), ['11']);
check(count($g) === 1 && $g[0]['devices'] === ['12', '13', '14', '15'], 'a leaf with a badge (hidden neighbor of a contradicted manual link): not in the group');
check(groups(view(4, ['11' => ['discovered_via' => 'manual']])) === [], 'a manual link makes the fourth leaf missing: 3 leaves in a group, no group');

echo "\n=== a leaf is counted by the whole topology ===\n";
[$devices, $relations, $totals] = view(4);
$totals['11'] = 2;      // a second link of the Device that is outside the view: it is at the edge of the view, not a leaf
check(groups([$devices, $relations, $totals]) === [], 'a Device at the edge of the view with a link outside the view: not a leaf (3 leaves, no group)');
$relations[1]['superseded_at'] = 1700000000;
[$devices2, $relations2, $totals2] = view(4);
$relations2[1]['superseded_at'] = 1700000000;
$totals2['11'] = 1;
check(groups([$devices2, $relations2, $totals2]) === [], 'a link that is superseded does not make a leaf (the view shows no live link of the Device)');
[$devices3, $relations3, $totals3] = view(4);
$devices3[2]['represented'] = true;
check(groups([$devices3, $relations3, $totals3]) === [], 'a Device with an association is not a leaf of a group (it is drawn as its Host)');

echo "\n=== Possible duplicates only: no groups ===\n";
check(groups(view(6), [], true) === [] && count(groups(view(6))) === 1, 'with the filter on there are no groups; with it off there is one');

echo "\n=== two nodes, a leaf that the user cannot see ===\n";
[$devices, $relations, $totals] = view(5);
$g = CTopologyLeafGroups::compute($devices, $relations, $totals, [], false, ['11', '12', '13', '14']);       // 15 is not visible: 4 left
check(count($g) === 1 && $g[0]['devices'] === ['11', '12', '13', '14'], 'a leaf the user cannot see is not counted (the parameter `visible`; the page passes null: access of a Device without association is not implemented yet)');
$g = CTopologyLeafGroups::compute($devices, $relations, $totals, [], false, ['11', '12', '13']);
check($g === [], 'with 3 visible leaves there is no group');

echo "\n=== a Device in a Junction projection (§12.6, 104) is in no group ===\n";
[$devices, $relations, $totals] = view(5);
$g = CTopologyLeafGroups::compute($devices, $relations, $totals, [], false, null, ['12']);       // 12 is behind a Junction: 4 leaves are left, it is no leaf of a group
check(count($g) === 1 && $g[0]['devices'] === ['11', '13', '14', '15'], 'a leaf that is in a Junction projection is left out of the group', $g);
$g = CTopologyLeafGroups::compute($devices, $relations, $totals, [], false, null, ['12', '13']);
check($g === [], 'with two of five leaves in a projection there are 3 left: no group');

echo "\nAll leaf group checks passed.\n";
