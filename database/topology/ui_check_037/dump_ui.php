<?php
// read only: the JSON that topology.devices.get returns (scope: one host, N hops), for the offline screenshots (037 section 4)
require_once __DIR__.'/../lab/php/bootstrap.php';
DBexecute('SET SESSION TRANSACTION READ ONLY');
$hostid = $argv[1] ?? '11921';
$hops = (int) ($argv[2] ?? 3);
$ids = CTopologyPrototype::resolveScope([], (string) $hostid, $hops);
echo json_encode(['devices' => CTopologyPrototype::getDevices($ids), 'relations' => CTopologyPrototype::getRelations($ids), 'contradictions' => CTopologyPrototype::getContradictions()],
	JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR), "\n";
