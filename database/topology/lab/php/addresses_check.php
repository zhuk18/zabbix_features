<?php

declare(strict_types=1);

/**
 * addresses_check.php — the answer of CTopologyPrototype::getAddresses() (the data of topology.addresses.get) for the Devices without association of the office stand (task 049).
 * Read only, the `zabbix` database. Prints, for three Devices that have addresses, the full list, and the number of Devices without association that have none.
 *
 * Usage: php addresses_check.php [how many Devices to list, default 3]
 */

require_once __DIR__.'/bootstrap.php';
DBexecute('SET SESSION TRANSACTION READ ONLY');

$limit = (int) ($argv[1] ?? 3);
$without = [];
foreach (DBfetchArray(DBselect("SELECT id FROM topo_nodes WHERE type='device' AND represented_by_node_id IS NULL ORDER BY id")) as $row) {
	$without[] = (int) $row['id'];
}

$with = [];
$none = 0;
foreach ($without as $id) {
	$answer = CTopologyPrototype::getAddresses((string) $id);
	if ($answer['addresses']) {
		$with[$id] = $answer;
	}
	else {
		$none++;
	}
}

echo 'Devices without association: '.count($without).'; with at least one address: '.count($with).'; with none: '.$none."\n";
// the first Device that has an address with two or more sources (LLDP and CDP, or two reporters), then the first Devices with addresses
$multi = array_filter($with, static function (array $a): bool {
	foreach ($a['addresses'] as $line) {
		if (count($line['sources']) >= 2) {
			return true;
		}
	}
	return false;
});
$pick = array_slice(array_slice($multi, 0, 1, true) + $with, 0, $limit, true);
foreach ($pick as $id => $answer) {
	echo "\n#{$id}  sysname=".json_encode($answer['sysname']).' chassis_id='.json_encode($answer['chassis_id']).' mgmt_ip='.json_encode($answer['mgmt_ip'])."\n";
	foreach ($answer['addresses'] as $line) {
		echo '  '.$line['address'].' (IPv'.$line['family'].($line['management'] ? ', management' : '').")\n";
		foreach ($line['sources'] as $s) {
			$m = $s['monitoring'];
			echo '      '.$s['reporter']['name'].' · '.$s['local_port'].' · '.strtoupper($s['source']).' · monitored by '.$m['mode']
				.($m['proxy'] ? ' '.$m['proxy']['name'] : '').($m['proxy_group'] ? ' '.$m['proxy_group']['name'] : '')."\n";
		}
	}
}
$sources_total = 0;
$multi_sources = 0;
foreach ($with as $answer) {
	foreach ($answer['addresses'] as $line) {
		$sources_total += count($line['sources']);
		$multi_sources += count($line['sources']) >= 2 ? 1 : 0;
	}
}
echo "\naddresses: ".array_sum(array_map(static fn (array $a): int => count($a['addresses']), $with)).", sources: {$sources_total}, addresses with two or more sources: {$multi_sources}\n";
