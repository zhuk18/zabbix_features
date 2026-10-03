#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * manual_link.php — the `zabbix_manual_link` deviation of the lab (topology-4-lab-simulator.md "Deviations").
 *
 * Creates a manual physical_link between two existing Ports through the same code path as the UI endpoint
 * (topology.ports.link -> CTopologyPrototype::linkPorts(): existence checks, canonical direction). The function uses only
 * the database layer, so no browser session is needed.
 *
 * Usage: php manual_link.php <port node id> <port node id>
 * Prints {"ok":true} or {"error":"..."} as one JSON line; exit code 0 / 1.
 */

if ($argc !== 3 || !ctype_digit($argv[1]) || !ctype_digit($argv[2])) {
	fwrite(STDERR, "Usage: {$argv[0]} <port node id> <port node id>\n");
	exit(2);
}

require_once __DIR__.'/bootstrap.php';

try {
	CTopologyPrototype::linkPorts($argv[1], $argv[2]);
	echo json_encode(['ok' => true]), "\n";
	exit(0);
}
catch (Exception $exception) {
	echo json_encode(['error' => $exception->getMessage()]), "\n";
	exit(1);
}
