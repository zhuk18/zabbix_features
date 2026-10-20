<?php

declare(strict_types=1);

/**
 * view_scope_check.php — topology-1-model.md §12.1 "View scope" on the data path of the view page: CTopologyPrototype::getView(), the method that
 * topology.devices.get (CControllerTopologyDevicesGet) answers with. Read only, on the office stand in the `zabbix` database (task 040 section 1).
 * The API facade is the lab stub (bootstrap.php): every host is visible, host.get applies `hostids` and `groupids`.
 *
 * Usage: php view_scope_check.php            (exit 1 when a check fails; prints the nodes and links of each case)
 */

require_once __DIR__.'/bootstrap.php';
DBexecute('SET SESSION TRANSACTION READ ONLY');

$failed = 0;
function check(bool $condition, string $description): void {
	global $failed;
	echo ($condition ? '  ok: ' : '  FAIL: ').$description."\n";
	$failed += $condition ? 0 : 1;
}

function hostid(string $prefix): string {
	$row = DBfetch(DBselect('SELECT hostid FROM hosts WHERE host LIKE '.zbx_dbstr($prefix.'%')));
	if (!$row) {
		fwrite(STDERR, "no host {$prefix}: is the office stand provisioned?\n");
		exit(2);
	}
	return (string) $row['hostid'];
}

/** The view as the page draws it: a Device with represented_by is drawn as its Host/Proxy; a link attaches to the drawn node. */
function drawn(array $view): array {
	$draw_as = [];
	$label = [];
	foreach ($view['devices'] as $node) {
		$label[(string) $node['id']] = ($node['type'] === 'device' ? 'Device ' : ucfirst($node['type']).' ').$node['label'];
	}
	foreach ($view['relations'] as $relation) {
		if ($relation['type'] === 'represented_by') {
			$draw_as[(string) $relation['source']] = (string) $relation['target'];
		}
	}
	$nodes = [];
	foreach ($view['devices'] as $node) {
		$id = (string) $node['id'];
		if (!isset($draw_as[$id])) {
			$nodes[$id] = $label[$id];
		}
	}
	$links = [];
	foreach ($view['relations'] as $relation) {
		if ($relation['type'] === 'represented_by') {
			continue;
		}
		$a = $draw_as[(string) $relation['source']] ?? (string) $relation['source'];
		$b = $draw_as[(string) $relation['target']] ?? (string) $relation['target'];
		$pair = [$nodes[$a] ?? "missing #{$a}", $nodes[$b] ?? "missing #{$b}"];
		sort($pair);
		$links[] = $relation['type'].' '.implode(' — ', $pair);
	}
	sort($links);
	$names = array_values($nodes);
	sort($names);
	return ['nodes' => $names, 'links' => $links, 'represented_devices_shown_bare' => array_values(array_filter($view['devices'],
		static fn (array $node): bool => $node['type'] === 'device' && !empty($node['represented']) && !isset($draw_as[(string) $node['id']])))];
}

function show(string $title, array $d): void {
	echo "\n=== {$title}: ".count($d['nodes']).' nodes, '.count($d['links'])." links\n";
	echo '  nodes: '.implode(', ', $d['nodes'])."\n";
	echo "  links:\n    ".implode("\n    ", $d['links'])."\n";
}

$sw13 = hostid('SW13-');
$sw12 = hostid('SW12-');

// Device neighbors of a Device node (physical_link or device_link), from the tables, for the expected sets
function neighbor_devices(string $device_id): array {
	$out = [];
	foreach (DBfetchArray(DBselect('SELECT sp.device_id AS a,dp.device_id AS b FROM topo_edges e JOIN topo_nodes sp ON sp.id=e.src_id'.
			' JOIN topo_nodes dp ON dp.id=e.dst_id WHERE e.type='.zbx_dbstr('physical_link'))) as $row) {
		if ((string) $row['a'] === $device_id) { $out[] = (string) $row['b']; }
		if ((string) $row['b'] === $device_id) { $out[] = (string) $row['a']; }
	}
	foreach (DBfetchArray(DBselect('SELECT sp.device_id AS a,e.dst_id AS b FROM topo_edges e JOIN topo_nodes sp ON sp.id=e.src_id'.
			' WHERE e.type='.zbx_dbstr('device_link'))) as $row) {
		if ((string) $row['a'] === $device_id) { $out[] = (string) $row['b']; }
		if ((string) $row['b'] === $device_id) { $out[] = (string) $row['a']; }
	}
	return array_values(array_unique($out));
}
function device_of_host(string $hostid): string {
	return (string) DBfetch(DBselect('SELECT d.id FROM topo_nodes d JOIN topo_nodes h ON h.id=d.represented_by_node_id WHERE h.host_ref='.zbx_dbstr($hostid)))['id'];
}
function drawn_name(string $device_id): string {
	$row = DBfetch(DBselect('SELECT d.attrs,COALESCE(ho.name,'.zbx_dbstr('').') AS name FROM topo_nodes d LEFT JOIN topo_nodes h ON h.id=d.represented_by_node_id'.
		' LEFT JOIN hosts ho ON ho.hostid=h.host_ref WHERE d.id='.zbx_dbstr($device_id)));
	if ($row['name'] !== '') {
		return 'Host '.$row['name'];
	}
	$a = json_decode($row['attrs'], true);
	return 'Device '.CTopologyPrototype::nodeLabel('device', null, $a['sysname'] ?? null, $a['chassis_id'] ?? null, $a['mgmt_ip'] ?? null);
}

$d13 = device_of_host($sw13);
$d12 = device_of_host($sw12);
$n13 = neighbor_devices($d13);
$n12 = neighbor_devices($d12);

// focus SW13, depth 1
$d = drawn(CTopologyPrototype::getView([], $sw13, 1));
show('focus SW13, depth 1', $d);
$expected = array_map('drawn_name', array_merge([$d13], $n13));
sort($expected);
$expected = array_values(array_unique($expected));
sort($expected);
check($d['nodes'] === $expected, 'SW13 and its direct neighbors only, each as its Host when it has one'.($d['nodes'] === $expected ? '' : ': expected '.json_encode($expected)));
check(in_array('Host SW12-10-CORE-6000-48G', $d['nodes'], true) && in_array('Host SW13-10-ACC-6000-48G', $d['nodes'], true), 'SW12 and SW13 are Hosts');
check(!array_filter($d['links'], static fn (string $l): bool => str_contains($l, 'missing')), 'only links with both ends in the view');
check(!array_filter($d['nodes'], static fn (string $n): bool => str_contains($n, 'SW14') || str_contains($n, 'gw1')), 'no neighbor of SW12 (2 links away)');
check(!$d['represented_devices_shown_bare'], 'no represented Device drawn bare');

// focus SW13, depth 2
$d = drawn(CTopologyPrototype::getView([], $sw13, 2));
show('focus SW13, depth 2', $d);
$n2 = $n13;
foreach ($n13 as $n) {
	$n2 = array_merge($n2, neighbor_devices($n));
}
$expected = array_values(array_unique(array_map('drawn_name', array_merge([$d13], $n2))));
sort($expected);
check($d['nodes'] === $expected, 'also the direct neighbors of SW12 (and of the other neighbors of SW13), as Hosts where they have one');
foreach (['SW14-10-ACC-6000-48G', 'SW15-10-POE-6000-24G', 'SW18-09-ACC-2530-48G', 'SW20-07-ACC-2530-48G', 'zbx-office-gw1', 'zbx-office-gw2'] as $h) {
	check(in_array('Host '.$h, $d['nodes'], true), "{$h} is a Host");
}
check(!array_filter($d['links'], static fn (string $l): bool => str_contains($l, 'missing')) && !$d['represented_devices_shown_bare'], 'links inside, no bare represented Device');

// focus SW12, depth 1
$d = drawn(CTopologyPrototype::getView([], $sw12, 1));
show('focus SW12, depth 1', $d);
$want = ['Host SW12-10-CORE-6000-48G', 'Host SW13-10-ACC-6000-48G', 'Host SW14-10-ACC-6000-48G', 'Host SW15-10-POE-6000-24G', 'Host SW18-09-ACC-2530-48G',
	'Host SW20-07-ACC-2530-48G', 'Host zbx-office-gw1', 'Host zbx-office-gw2'];
check(!array_diff($want, $d['nodes']), 'SW13, SW14, SW15, SW18, SW20, gw1, gw2 as Hosts');
$extra = array_values(array_diff($d['nodes'], $want));
$expected = array_values(array_unique(array_map('drawn_name', array_merge([$d12], $n12))));
sort($expected);
check($d['nodes'] === $expected, 'and the other direct neighbors of SW12 (Devices without a Host): '.json_encode($extra));
check(in_array('physical_link Host zbx-office-gw1 — Host zbx-office-gw2', $d['links'], true), 'the gw1–gw2 link');
check(!array_filter($d['links'], static fn (string $l): bool => str_contains($l, 'missing')) && !$d['represented_devices_shown_bare'], 'links inside, no bare represented Device');

// a host group as the start: every Host of the group is a start node
$group = DBfetch(DBselect('SELECT g.groupid,g.name FROM hstgrp g JOIN hosts_groups hg ON hg.groupid=g.groupid JOIN hosts h ON h.hostid=hg.hostid'.
	' WHERE h.hostid='.zbx_dbstr($sw13)));
$group_hosts = array_column(DBfetchArray(DBselect('SELECT h.name FROM hosts h JOIN hosts_groups hg ON hg.hostid=h.hostid JOIN topo_nodes n ON n.host_ref=h.hostid'.
	' WHERE hg.groupid='.zbx_dbstr($group['groupid']))), 'name');
$d = drawn(CTopologyPrototype::getView([$group['groupid']], '', 1));
echo "\n=== group {$group['name']}, depth 1: ".count($d['nodes']).' nodes, '.count($d['links'])." links\n";
check(!array_diff(array_map(static fn (string $h): string => 'Host '.$h, $group_hosts), $d['nodes']), 'every Host of the group ('.count($group_hosts).') is in the view');
check(!array_filter($d['links'], static fn (string $l): bool => str_contains($l, 'missing')) && !$d['represented_devices_shown_bare'], 'links inside, no bare represented Device');
// groups and focus host together
$solo = drawn(CTopologyPrototype::getView([], $sw13, 1));
$both = drawn(CTopologyPrototype::getView([$group['groupid']], $sw13, 1));
check(!array_diff($solo['nodes'], $both['nodes']) && !array_diff(array_map(static fn (string $h): string => 'Host '.$h, $group_hosts), $both['nodes']),
	'group and focus host together: both are start nodes (the focus host does not replace the group)');
// no selection: every node
check(CTopologyPrototype::resolveScope([], '', 1) === null, 'nothing selected: no scope (every node)');

echo $failed ? "\n{$failed} FAILED\n" : "\nALL OK\n";
exit($failed ? 1 : 0);
