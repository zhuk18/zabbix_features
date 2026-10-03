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

	echo json_encode(['links' => $links, 'contradictions' => $contradictions, 'badges' => (object) $badges]), "\n";
	exit(0);
}
catch (Throwable $exception) {
	echo json_encode(['error' => $exception->getMessage()]), "\n";
	exit(1);
}
