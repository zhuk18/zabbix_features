<?php

declare(strict_types=1);

/**
 * addresses_check.php — the answer of CTopologyPrototype::getAddresses() (the data of topology.addresses.get) for the Devices without association of the office stand (tasks 049, 051).
 * Read only, the `zabbix` database. Prints, for three Devices that have addresses, the full list, and the counts: Devices without association with and without addresses,
 * addresses, sources, and (051) the addresses hidden (unspecified, loopback), marked link-local, and marked "not reported now".
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
		echo '  '.$line['address'].' (IPv'.$line['family'].($line['management'] ? ', management' : '').($line['link_local'] ? ', link-local' : '').($line['not_reported_now'] ? ', not reported now' : '').")\n";
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

// 051: the addresses the section does not show. Counted per Device and distinct address, from the observations the section reads (rem_man_addrs entries and rem_mgmt_ip)
// and from the Device's mgmt_ip; the shown ones from the answers.
$hidden = 0;
$hidden_examples = [];
foreach ($without as $id) {
	$found = [];
	foreach (DBfetchArray(DBselect('SELECT remote_attrs FROM topo_observations WHERE device_id='.$id)) as $row) {
		$remote = json_decode((string) $row['remote_attrs'], true) ?: [];
		foreach (is_string($remote['rem_man_addrs'] ?? null) ? CTopologyAddress::decodeEntries($remote['rem_man_addrs']) : [] as $entry) {
			$found[$entry['address']] = true;
		}
		if (is_string($remote['rem_mgmt_ip'] ?? null) && ($text = CTopologyAddress::ipText($remote['rem_mgmt_ip'])) !== null) {
			$found[$text] = true;
		}
	}
	$attrs = json_decode((string) (DBfetch(DBselect('SELECT attrs FROM topo_nodes WHERE id='.$id))['attrs'] ?? '{}'), true) ?: [];
	if (($attrs['mgmt_ip'] ?? '') !== '' && CTopologyAddress::ipText((string) $attrs['mgmt_ip']) !== null) {
		$found[(string) $attrs['mgmt_ip']] = true;
	}
	foreach (array_keys($found) as $address) {
		if (CTopologyAddress::isHidden((string) $address)) {
			$hidden++;
			$hidden_examples[] = "#{$id} {$address}";
		}
	}
}
$link_local = 0;
$not_reported = 0;
$link_local_devices = [];
foreach ($with as $id => $answer) {
	foreach ($answer['addresses'] as $line) {
		$link_local += $line['link_local'] ? 1 : 0;
		$not_reported += $line['not_reported_now'] ? 1 : 0;
		if ($line['link_local']) {
			$link_local_devices[$id] = true;
		}
	}
}
echo "hidden (unspecified, loopback): {$hidden}".($hidden_examples ? ' ('.implode(', ', array_slice($hidden_examples, 0, 5)).')' : '')."; marked link-local: {$link_local} on ".count($link_local_devices)." Devices; marked not reported now: {$not_reported}\n";
