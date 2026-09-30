#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Writes fixtures/synthetic-*.walk: what the lab walks (../lld_js/fixtures) do not contain — lldpLocPortTable
 * subtypes, chassis / port id subtypes, Hex-STRING ids, management addresses, Q-BRIDGE and BRIDGE forwarding tables,
 * a trunk over the MAC limit, LAG members, a device without LLDP-MIB, and CISCO-CDP-MIB. The data is that of
 * ../lld_js/test_lld_js.php (§"synthetic walk"), split into one walk per situation.
 *
 * The walks are committed; run this only to change them, then `compare.php --generate` to refresh the expectations.
 */

function line(string $oid, string $type, string $value): string {
	return ".{$oid} = {$type}: {$value}\n";
}

function hex(string $mac): string {
	return strtoupper(implode(' ', explode(':', $mac)));
}

/** The interface part shared by the walks: ifTable + ifXTable of five ports. */
function interfaces(): string {
	$w = line('1.3.6.1.2.1.1.5.0', 'STRING', '"core1"');
	$if = [1 => ['Gi0/1', '02:00:00:00:00:01'], 2 => ['Gi0/2', '02:00:00:00:00:02'], 3 => ['Gi0/3', '02:00:00:00:00:03'],
		10 => ['Po1', '02:00:00:00:00:0a'], 11 => ['Gi0/4', '02:00:00:00:00:0b']];

	foreach ($if as $i => [$name, $mac]) {
		$w .= line("1.3.6.1.2.1.2.2.1.1.{$i}", 'INTEGER', (string) $i);
		$w .= line("1.3.6.1.2.1.2.2.1.2.{$i}", 'STRING', '"GigabitEthernet'.$name.'"');
		$w .= line("1.3.6.1.2.1.2.2.1.3.{$i}", 'INTEGER', $i === 10 ? 'ieee8023adLag(161)' : 'ethernetCsmacd(6)');
		$w .= line("1.3.6.1.2.1.2.2.1.6.{$i}", 'Hex-STRING', hex($mac));
		$w .= line("1.3.6.1.2.1.2.2.1.7.{$i}", 'INTEGER', 'up(1)');
		$w .= line("1.3.6.1.2.1.2.2.1.8.{$i}", 'INTEGER', $i === 11 ? 'down(2)' : 'up(1)');
		$w .= line("1.3.6.1.2.1.31.1.1.1.1.{$i}", 'STRING', '"'.$name.'"');
		$w .= line("1.3.6.1.2.1.31.1.1.1.15.{$i}", 'Gauge32', $i === 10 ? '2000' : '1000');
	}

	return $w;
}

function lldp_neighbors(): string {
	$w = '';
	$loc = '1.0.8802.1.1.2.1.3.7.1';

	foreach ([[101, 5, 'Gi0/1'], [102, 3, hex('02:00:00:00:00:02')], [103, 7, '3'], [104, 1, 'alias-x']] as [$num, $type, $id]) {
		$w .= line("{$loc}.2.{$num}", 'INTEGER', (string) $type);
		$w .= line("{$loc}.3.{$num}", $type === 3 ? 'Hex-STRING' : 'STRING', $type === 3 ? $id : '"'.$id.'"');
	}

	$rem = '1.0.8802.1.1.2.1.4.1.1';
	$w .= line("{$rem}.4.0.101.1", 'INTEGER', 'macAddress(4)');
	$w .= line("{$rem}.5.0.101.1", 'Hex-STRING', hex('aa:bb:cc:00:00:01'));
	$w .= line("{$rem}.6.0.101.1", 'INTEGER', 'interfaceName(5)');
	$w .= line("{$rem}.7.0.101.1", 'STRING', '"Te1/0/1"');
	$w .= line("{$rem}.8.0.101.1", 'STRING', '"TenGigabitEthernet1/0/1"');
	$w .= line("{$rem}.9.0.101.1", 'STRING', '"dist1"');
	$w .= line("{$rem}.4.0.102.1", 'INTEGER', 'networkAddress(5)');
	$w .= line("{$rem}.5.0.102.1", 'Hex-STRING', '01 C0 A8 01 0A');
	$w .= line("{$rem}.6.0.102.1", 'INTEGER', 'macAddress(3)');
	$w .= line("{$rem}.7.0.102.1", 'Hex-STRING', hex('aa:bb:cc:00:00:09'));
	$w .= line("{$rem}.4.0.103.1", 'INTEGER', 'local(7)');
	$w .= line("{$rem}.5.0.103.1", 'STRING', '"sw-local"');
	$w .= line("{$rem}.6.0.103.1", 'INTEGER', 'local(7)');
	$w .= line("{$rem}.7.0.103.1", 'STRING', '"12"');
	$w .= line("{$rem}.9.0.103.1", 'STRING', '"edge3"');
	$w .= line("{$rem}.5.0.104.1", 'STRING', '"orphan"');
	$w .= line("{$rem}.9.0.104.1", 'STRING', '"orphan-sys"');
	$w .= line('1.0.8802.1.1.2.1.4.2.1.3.0.101.1.1.4.10.0.0.9', 'INTEGER', '2');
	$w .= line('1.0.8802.1.1.2.1.4.2.1.3.0.103.1.2.16.32.1.13.184.0.0.0.0.0.0.0.0.0.0.0.1', 'INTEGER', '2');	// IPv6

	return $w;
}

function lag_members(): string {
	$w = '';

	foreach ([1 => 10, 2 => 10, 3 => 0, 10 => 10, 11 => 11] as $member => $agg) {
		$w .= line("1.2.840.10006.300.43.1.2.1.1.13.{$member}", 'INTEGER', (string) $agg);
	}

	return $w;
}

/** Q-BRIDGE forwarding table; the trunk on bridge port 5 (ifIndex 10) holds $trunk_macs entries. */
function qbridge_fdb(int $trunk_macs): string {
	$f = '';

	foreach ([1 => 1, 2 => 2, 5 => 10, 6 => 11] as $bridge_port => $if_index) {
		$f .= line("1.3.6.1.2.1.17.1.4.1.2.{$bridge_port}", 'INTEGER', (string) $if_index);
	}

	$q = '1.3.6.1.2.1.17.7.1.2.2.1';
	$add = static function (int $vlan, array $mac, int $bridge_port, int $status) use (&$f, $q): void {
		$idx = $vlan.'.'.implode('.', $mac);
		$f .= line("{$q}.2.{$idx}", 'INTEGER', (string) $bridge_port);
		$f .= line("{$q}.3.{$idx}", 'INTEGER', $status === 3 ? 'learned(3)' : "other({$status})");
	};

	$add(10, [0, 0x11, 0x22, 0x33, 0x44, 0xaa], 1, 3);
	$add(10, [0, 0x11, 0x22, 0x33, 0x44, 0xbb], 1, 3);
	$add(20, [0, 0x11, 0x22, 0x33, 0x44, 0xcc], 2, 3);
	$add(20, [0, 0x11, 0x22, 0x33, 0x44, 0xdd], 2, 4);	// self, not learned
	$add(20, [0, 0x11, 0x22, 0x33, 0x44, 0xee], 0, 3);	// no port

	for ($i = 0; $i < $trunk_macs; $i++) {
		$add(30, [0x02, 0x99, 0x00, 0x00, 0x00, $i], 5, 3);
	}

	return $f;
}

function cdp(): string {
	$c = '1.3.6.1.4.1.9.9.23.1.2.1.1';
	$w = line('1.3.6.1.4.1.9.9.23.1.3.1.0', 'INTEGER', 'true(1)');

	// ifIndex.DeviceIndex: Gi0/1 sees a router (IPv4), Gi0/2 a switch (no address), Gi0/3 a device with IPv6
	$w .= line("{$c}.3.1.1", 'INTEGER', 'ip(1)');
	$w .= line("{$c}.4.1.1", 'Hex-STRING', '0A 00 00 01');
	$w .= line("{$c}.6.1.1", 'STRING', '"router-a.example.net"');
	$w .= line("{$c}.7.1.1", 'STRING', '"GigabitEthernet0/0"');
	$w .= line("{$c}.6.2.1", 'STRING', '"sw-b"');
	$w .= line("{$c}.7.2.1", 'STRING', '"Gi1/0/48"');
	$w .= line("{$c}.3.3.1", 'INTEGER', 'ipv6(20)');
	$w .= line("{$c}.4.3.1", 'Hex-STRING', '20 01 0D B8 00 00 00 00 00 00 00 00 00 00 00 01');
	$w .= line("{$c}.6.3.1", 'STRING', '"v6-host"');
	$w .= line("{$c}.7.3.1", 'STRING', '"eth1"');

	return $w;
}

$files = [
	// LLDP with every subtype, Hex-STRING ids, no lldpLocChassisId: the chassis is the lowest-ifIndex port MAC
	'synthetic-core.walk' => interfaces().lldp_neighbors().lag_members().qbridge_fdb(25),
	// the same device with lldpLocChassisId present as Hex-STRING
	'synthetic-locchassis.walk' => interfaces().line('1.0.8802.1.1.2.1.3.2.0', 'Hex-STRING', hex('AA:BB:CC:DD:EE:01')).
		line('1.0.8802.1.1.2.1.3.7.1.2.1', 'INTEGER', '5').line('1.0.8802.1.1.2.1.3.7.1.3.1', 'STRING', '"Gi0/1"'),
	// exactly at the MAC limit: still one row per MAC
	'synthetic-fdb-limit.walk' => interfaces().qbridge_fdb(20),
	// BRIDGE-MIB only (no Q-BRIDGE): no VLAN
	'synthetic-bridge.walk' => interfaces().line('1.3.6.1.2.1.17.1.4.1.2.1', 'INTEGER', '1').
		line('1.3.6.1.2.1.17.4.3.1.2.0.17.34.51.68.170', 'INTEGER', '1').
		line('1.3.6.1.2.1.17.4.3.1.3.0.17.34.51.68.170', 'INTEGER', 'learned(3)'),
	// IF-MIB only: a device without LLDP-MIB, LAG and bridge tables
	'synthetic-ifmib-only.walk' => interfaces(),
	// LLDP-MIB present, no neighbors
	'synthetic-lldp-empty.walk' => interfaces().line('1.0.8802.1.1.2.1.3.2.0', 'Hex-STRING', hex('AA:BB:CC:DD:EE:02')).
		line('1.0.8802.1.1.2.1.3.7.1.2.1', 'INTEGER', '5').line('1.0.8802.1.1.2.1.3.7.1.3.1', 'STRING', '"Gi0/1"'),
	// LAG table present, every member self-attached or 0
	'synthetic-lag-empty.walk' => interfaces().line('1.2.840.10006.300.43.1.2.1.1.13.1', 'INTEGER', '0').
		line('1.2.840.10006.300.43.1.2.1.1.13.2', 'INTEGER', '2'),
	// CISCO-CDP-MIB (no JS reference: the expectation is reviewed by hand)
	'synthetic-cdp.walk' => interfaces().cdp(),
];

@mkdir(__DIR__.'/fixtures');

foreach ($files as $name => $text) {
	file_put_contents(__DIR__.'/fixtures/'.$name, $text);
	echo "wrote fixtures/{$name}\n";
}
