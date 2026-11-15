#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * derived.php — the read-only helper of the lab (topology-4-lab-simulator.md "Open questions", reading derived state).
 *
 * Loads CTopologyPrototype against the real `zabbix` database (bootstrap.php) and prints, as one JSON line, the state
 * the UI derives at read time and the lab assertions `stale`, `contradiction` and `badge` check:
 *   links:          every stored link, by edge id: `stale` (CTopologyPrototype::isLinkStale(), the UI's own function and
 *                   the freshness macro) and `superseded_at`
 *   contradictions: every manual link with shadowing observations or acknowledgments: `contradicted` and the
 *                   unacknowledged / acknowledged hidden neighbors by Device name
 *   junctions:      the Junction projections (model §12.6): the reporter Device, its Port and the Devices behind it
 *   badges:         Device name -> the manual links (edge ids) whose unacknowledged hidden neighbor it is, the
 *                   condition of the UI's "Link hidden by manual link" badge (topology-1-model.md "Contradicted manual links")
 *
 * It writes nothing: the session is read only, so a write would fail.
 *
 * Usage: php derived.php
 */

require_once __DIR__.'/bootstrap.php';

try {
	DBexecute('SET SESSION TRANSACTION READ ONLY');

	CTopologyPrototype::reloadFreshness();
	$links = [];
	$result = DBselect('SELECT id,attrs FROM topo_edges WHERE type IN ('.zbx_dbstr('physical_link').','.zbx_dbstr('device_link').')');
	while ($row = DBfetch($result, false)) {
		$attrs = json_decode($row['attrs'], true, 512, JSON_THROW_ON_ERROR);
		$last_seen = isset($attrs['last_seen']) ? (int) $attrs['last_seen'] : null;
		$superseded_at = isset($attrs['superseded_at']) ? (int) $attrs['superseded_at'] : null;
		$links[] = ['edge_id' => (string) $row['id'], 'stale' => CTopologyPrototype::isLinkStale($last_seen, $superseded_at),
			'superseded_at' => $superseded_at];
	}

	$pick = static fn (?array $side): ?array => $side === null ? null : ['in' => $side['in'] ?? null, 'out' => $side['out'] ?? null, 'speed' => $side['speed'] ?? null, 'percent' => $side['percent'] ?? null];
	// per physical link (by edge id): the items of the end ports, the severity and the load that the UI shows (model §4.8, getNeighbors() of both ends)
	// task 103 (model §4.8, 2.88): neighbors.get gives the load of the FAR port only (the near side is in ports.get), so the load of a port is read from the entry of the neighbor that has it as its far
	// port: both ends of a link are there, because every Device is asked.
	$detail = [];
	$port_load = [];
	$entries = [];
	$devices = DBselect('SELECT id FROM topo_nodes WHERE type='.zbx_dbstr('device'));
	while ($device = DBfetch($devices, false)) {
		foreach (CTopologyPrototype::getNeighbors((string) $device['id']) as $neighbor) {
			if (($neighbor['link_type'] ?? '') !== 'physical_link') {
				continue;
			}
			$port_load[$neighbor['remote_port_id']] = $neighbor['load']['remote'] ?? null;
			if (!isset($detail[$neighbor['edge_id']])) {
				$detail[$neighbor['edge_id']] = true;
				$entries[$neighbor['edge_id']] = $neighbor;
			}
		}
	}
	$detail = [];
	foreach ($entries as $edge_id => $neighbor) {
		$items = [];
		foreach (['local_port_id', 'remote_port_id'] as $end) {
			$row = DBfetch(DBselect('SELECT attrs FROM topo_nodes WHERE id='.zbx_dbstr($neighbor[$end])), false);
			$attrs = $row ? json_decode($row['attrs'], true) : [];
			$items[$end] = count($attrs['zabbix_itemids'] ?? []);
		}
		// the status items of the end ports (net.if.status[...]): the trigger "Link down" needs a value before the change and one after it, so the runner polls them around a step (task 104)
		$status_items = [];
		foreach (['local_port_id', 'remote_port_id'] as $end) {
			$row = DBfetch(DBselect('SELECT attrs FROM topo_nodes WHERE id='.zbx_dbstr($neighbor[$end])), false);
			$ids = array_map('intval', (json_decode($row['attrs'] ?? '{}', true)['zabbix_itemids'] ?? []));
			if ($ids) {
				foreach (DBselect('SELECT itemid FROM items WHERE itemid IN ('.implode(',', $ids).') AND key_ LIKE '.zbx_dbstr('net.if.status[%')) as $item) {
					$status_items[] = (string) $item['itemid'];
				}
			}
		}
		$local = $port_load[$neighbor['local_port_id']] ?? null;
		$remote = $port_load[$neighbor['remote_port_id']] ?? null;
		$detail[$edge_id] = ['status_items' => $status_items, 'items_local' => $items['local_port_id'], 'items_remote' => $items['remote_port_id'],
			'severity' => $neighbor['severity'], 'load_local' => $local['percent'] ?? null,
			'load_remote' => $remote['percent'] ?? null,
			'load_items' => ['local' => $local['items'] ?? null, 'remote' => $remote['items'] ?? null],
			'load_detail' => ['local' => $pick($local), 'remote' => $pick($remote)]];
	}
	foreach ($links as &$link) {
		$link += $detail[$link['edge_id']] ?? [];
	}
	unset($link);

	$contradictions = [];
	$badges = [];
	foreach (CTopologyPrototype::getContradictions() as $entry) {
		$unacknowledged = [];
		$acknowledged = [];
		foreach ($entry['shadows'] as $shadow) {
			if ($shadow['hidden_name'] === null) {
				continue;
			}
			if ($shadow['acknowledged']) {
				$acknowledged[$shadow['hidden_name']] = true;
			}
			else {
				$unacknowledged[$shadow['hidden_name']] = true;
				$badges[$shadow['hidden_name']][(string) $entry['edge_id']] = true;
			}
		}
		$contradictions[] = ['edge_id' => (string) $entry['edge_id'], 'contradicted' => (bool) $entry['contradicted'],
			'hidden_neighbors' => array_keys($unacknowledged), 'acknowledged_neighbors' => array_keys($acknowledged)];
	}
	foreach ($badges as $name => $edges) {
		$badges[$name] = array_keys($edges);
	}

	// §12.6 (task 104): the Junction projections the page draws, by the names of the reporter Device, its Port and the Devices behind it
	$junctions = [];
	foreach (CTopologyPrototype::getJunctions(null) as $junction) {
		$junctions[] = ['device' => $junction['device_name'], 'port' => $junction['port_name'], 'devices' => array_column($junction['devices'], 'name'), 'port_id' => $junction['port_id']];
	}

	echo json_encode(['links' => $links, 'contradictions' => $contradictions, 'badges' => (object) $badges, 'junctions' => $junctions]), "\n";
	exit(0);
}
catch (Throwable $exception) {
	echo json_encode(['error' => $exception->getMessage()]), "\n";
	exit(1);
}
