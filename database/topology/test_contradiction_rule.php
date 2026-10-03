#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * test_contradiction_rule.php — the hidden-neighbor rule shared by ingest.php and CTopologyPrototype
 * (topology-1-model.md "Contradiction", "Acknowledgments"). Pure function: no database needed.
 *
 * Usage: php test_contradiction_rule.php
 */

require_once __DIR__.'/topology_contradiction.inc.php';

$failed = 0;
function check(bool $condition, string $description): void {
	global $failed;
	echo ($condition ? '  ok: ' : '  FAIL: ').$description."\n";
	if (!$condition) {
		$failed++;
	}
}

// The lab case: M = Router1:Gi0/1 (port 11, device 1) <-> UPS1:eth0 (port 41, device 4). Printer = 9, Switch2 = 3.
$ends_ports = [11, 41];
$ends_devices = [1, 4];

echo "=== from an end of M: the resolved Device is hidden ===\n";
$r = topo_hidden_neighbor(11, 1, 9, $ends_ports, $ends_devices);        // Router1 sees Printer1 on Gi0/1
check($r === ['kind' => 'hidden', 'device_id' => 9, 'third_party' => false], 'near end: Printer1 is the hidden neighbor');
$r = topo_hidden_neighbor(41, 4, 3, $ends_ports, $ends_devices);        // UPS1 (with LLDP) sees Switch2 on eth0
check($r === ['kind' => 'hidden', 'device_id' => 3, 'third_party' => false], 'far end with LLDP: Switch2 is the hidden neighbor');

echo "\n=== third party: the reporter is hidden, not the end of M it reports ===\n";
$r = topo_hidden_neighbor(32, 3, 4, $ends_ports, $ends_devices);        // Switch2:Gi0/1 (port 32) sees UPS1
check($r === ['kind' => 'hidden', 'device_id' => 3, 'third_party' => true], 'Switch2 is the hidden neighbor, not UPS1');
$r = topo_hidden_neighbor(32, 3, null, $ends_ports, $ends_devices);
check($r['kind'] === 'hidden' && $r['device_id'] === 3 && $r['third_party'], 'third party without a resolved remote Device: still the reporter');

echo "\n=== the same neighbor from both directions is one hidden neighbor ===\n";
$a = topo_hidden_neighbor(32, 3, 4, $ends_ports, $ends_devices);        // Switch2 sees UPS1 (third party)
$b = topo_hidden_neighbor(41, 4, 3, $ends_ports, $ends_devices);        // UPS1 sees Switch2 (end of M)
check($a['device_id'] === $b['device_id'] && $a['device_id'] === 3, 'Switch2 from two observations: one hidden neighbor');

echo "\n=== own ends and unknown ===\n";
$r = topo_hidden_neighbor(11, 1, 4, $ends_ports, $ends_devices);        // Router1 sees UPS1 on Gi0/1: M's own far end
check($r['kind'] === 'own', 'an end of M seen at another precision is not hidden');
$r = topo_hidden_neighbor(11, 1, null, $ends_ports, $ends_devices);
check($r === ['kind' => 'unknown', 'device_id' => null, 'third_party' => false], 'no Device known: unknown, counts as contradicting');
$r = topo_hidden_neighbor(12, 1, 9, $ends_ports, $ends_devices);        // an end Device reporting from another port
check($r['kind'] === 'hidden' && $r['device_id'] === 9 && !$r['third_party'], 'an end Device on another port: not a third party');

echo "\n=== device_link M: one end port, one end Device ===\n";
$r = topo_hidden_neighbor(32, 3, 4, [11], [1, 4]);                      // M = Router1:Gi0/1 -> UPS1 (device level)
check($r['third_party'] && $r['device_id'] === 3, 'third party against a device-level manual link');

echo "\n=== the acknowledgment key of a third-party observation ===\n";
check(topo_reporter_port_key(['chassis_id' => '00:11:22:33:44:12', 'sysname' => 'Switch2'], 1) === 'port:chassis:-:00:11:22:33:44:12:1',
	'chassis identity + if_index');
check(topo_reporter_port_key(['mgmt_ip' => '10.0.0.3', 'sysname' => 'x'], 7) === 'port:ip:10.0.0.3:7', 'ip identity when there is no chassis id');
check(topo_reporter_port_key(['sysname' => 'x'], null) === 'port:sysname:x:', 'sysname identity, no if_index');
check(strlen(topo_reporter_port_key(['sysname' => str_repeat('x', 400)], 1)) === 255, 'at most 255 characters, like remote_key');

echo $failed === 0 ? "\nAll checks passed.\n" : "\n{$failed} check(s) failed.\n";
exit($failed === 0 ? 0 : 1);
