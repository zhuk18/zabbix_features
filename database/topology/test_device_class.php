#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * test_device_class.php — task 091, topology-1-model.md §12.2 "Device class" (2.71): the class of a Device from the union of `rem_sys_cap`, the first rule of the table that is true. No database.
 */

require_once __DIR__.'/../../ui/include/classes/topology/CTopologyDeviceClass.php';

function check(bool $condition, string $description, $detail = null): void {
	if (!$condition) {
		fwrite(STDERR, "FAIL: {$description}".($detail !== null ? ' '.json_encode($detail) : '')."\n");
		exit(1);
	}
	echo "  ok: {$description}\n";
}

$class = static fn (string $caps): ?string => CTopologyDeviceClass::fromBits(CTopologyDeviceClass::bitsOf($caps));

echo "\n=== one test for each rule of the table ===\n";
check($class('wlanAccessPoint') === 'access_point', 'rule 1: wlanAccessPoint → Access point');
check($class('telephone') === 'phone', 'rule 2: telephone → Phone');
check($class('switch') === 'switch', 'rule 3: switch (CDP) → Switch');
foreach (['bridge', 'transBridge', 'srcRouteBridge'] as $bit) {
	check($class($bit) === 'switch', "rule 4: {$bit} → Switch");
}
check($class('router') === 'router', 'rule 5: router → Router');
check($class('stationOnly') === 'station' && $class('host') === 'station', 'rule 6: stationOnly, host (CDP) → Station');
check($class('repeater') === null && $class('other') === null && $class('docsisCableDevice') === null, 'rule 7: none of the bits (repeater, other, docsis) → generic');

echo "\n=== the order ===\n";
check($class('bridge router wlanAccessPoint') === 'access_point', '`bridge router wlanAccessPoint` → Access point');
check($class('switch router bridge') === 'switch', '`switch router bridge` → Switch');
check($class('router bridge') === 'switch', '`router bridge` → Switch (2.72: bridge before router; an LLDP layer-3 switch is a Switch)');
check($class('router') === 'router' && $class('router host') === 'router', 'only `router` (no bridge bit) → Router');
check($class('telephone bridge') === 'phone' && $class('stationOnly bridge') === 'switch', 'a phone before a bridge; a bridge before a station');

echo "\n=== no class ===\n";
check($class('') === null && CTopologyDeviceClass::fromBits([]) === null, 'no `rem_sys_cap` → generic');
check(CTopologyDeviceClass::ofObservations([]) === [], 'a Device without an observation that has the field is not listed (the page draws the generic icon; a Host without a Device has no class)');

echo "\n=== the union over the observations ===\n";
$rows = [
	['device_id' => 7, 'remote_attrs' => json_encode(['rem_sys_cap' => 'bridge'])],
	['device_id' => 7, 'remote_attrs' => json_encode(['rem_sys_cap' => 'router wlanAccessPoint'])],
	['device_id' => 8, 'remote_attrs' => json_encode(['rem_sys_cap' => 'stationOnly'])],
	['device_id' => 9, 'remote_attrs' => json_encode(['rem_sysname' => 'x'])],
	['device_id' => 10, 'remote_attrs' => 'not json']
];
$union = CTopologyDeviceClass::ofObservations($rows);
check($union['7']['class'] === 'access_point' && $union['7']['bits'] === ['bridge', 'router', 'wlanAccessPoint'], 'two observations of one Device: the union of the bits, the class of the union', $union['7']);
check($union['8']['class'] === 'station' && !isset($union['9']) && !isset($union['10']), 'an observation without the field or with bad JSON gives nothing');

echo "\n=== a value with HTML (§13) ===\n";
$evil = '<img src=x onerror=alert(1)> router';
$bits = CTopologyDeviceClass::bitsOf($evil);
check(CTopologyDeviceClass::fromBits($bits) === 'router', 'the unknown bits are no class; the known one in the same value still counts');
check(CTopologyDeviceClass::fromBits(CTopologyDeviceClass::bitsOf('<b>wlanAccessPoint</b>')) === null, 'a bit with markup is not a name of the table: generic');
check(in_array('<img', $bits, true), 'the bits are returned as received (the page escapes them where it shows them)');

echo "\nAll device class checks passed.\n";
