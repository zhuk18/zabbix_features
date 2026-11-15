#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * operator.php — the step key `operator` of the lab (topology-4-lab-simulator.md "Scenarios", 2.21).
 *
 * Runs one operator action of the topology page through the same code path as the UI endpoints (CTopologyPrototype: topology.devices.merge, .mergegroup,
 * .dismissduplicate, .dismissgroup, .delete), without a browser session (the classes use only the database layer; php/bootstrap.php).
 *
 * Usage: php operator.php <merge|merge_group|dismiss|dismiss_group|delete|create_device|link> '<json>'
 *   merge          {"keep": <device id>, "remove": <device id>}
 *   merge_group    {"keep": <device id>, "remove": [<device id>, ...]}
 *   dismiss        {"a": <device id>, "b": <device id>}
 *   dismiss_group  {"ids": [<device id>, ...]}
 *   delete         {"id": <device id>}
 *   create_device  {"name": ..., "device_class": ..., "ports": [<name>, ...], "chassis_id"?: ..., "mgmt_ip"?: ...}  (topology.devices.create, 2.27)
 *   link           {"a": <port node id>, "b": <port node id>}                       (topology.ports.link: CTopologyPrototype::linkPorts, 2.27)
 * Prints {"ok":true,...} or {"error":"..."} as one JSON line; exit code 0 / 1.
 */

const OPERATOR_USER = 'lab';

if ($argc !== 3 || !in_array($argv[1], ['merge', 'merge_group', 'dismiss', 'dismiss_group', 'delete', 'create_device', 'link'], true)) {
	fwrite(STDERR, "Usage: {$argv[0]} <merge|merge_group|dismiss|dismiss_group|delete|create_device|link> '<json>'\n");
	exit(2);
}
$args = json_decode($argv[2], true);
if (!is_array($args)) {
	fwrite(STDERR, "the second argument is not a JSON object\n");
	exit(2);
}

require_once __DIR__.'/bootstrap.php';

try {
	switch ($argv[1]) {
		case 'merge':
			$result = CTopologyPrototype::mergeDevices((string) $args['keep'], (string) $args['remove'], OPERATOR_USER);
			break;
		case 'merge_group':
			$result = CTopologyPrototype::mergeGroup((string) $args['keep'], array_map('strval', $args['remove']), OPERATOR_USER);
			break;
		case 'dismiss':
			CTopologyPrototype::dismissDuplicate((string) $args['a'], (string) $args['b'], OPERATOR_USER);
			$result = [];
			break;
		case 'dismiss_group':
			$result = CTopologyPrototype::dismissGroup(array_map('strval', $args['ids']), OPERATOR_USER);
			break;
		case 'create_device':
			$result = CTopologyPrototype::createDevice($args, OPERATOR_USER);
			break;
		case 'link':
			$result = ['id' => CTopologyPrototype::linkPorts((string) $args['a'], (string) $args['b'], OPERATOR_USER)];
			break;
		default:
			$result = CTopologyPrototype::deleteDevice((string) $args['id'], OPERATOR_USER);
	}
	echo json_encode(['ok' => true, 'result' => $result]), "\n";
	exit(0);
}
catch (Throwable $exception) {
	echo json_encode(['error' => $exception->getMessage()]), "\n";
	exit(1);
}
