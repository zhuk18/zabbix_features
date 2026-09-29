#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Tests the four topology JS rules in the REAL Zabbix JavaScript engine (zabbix_js), on
 *   - walks recorded from the lab (fixtures/lab-*.walk, made by record_fixture.sh), and
 *   - a synthetic walk that exercises what the lab data lacks: lldpLocPortTable subtype resolution, chassis/port
 *     subtypes, management addresses, Q-BRIDGE and BRIDGE FDB, count-only trunks, LAG membership.
 *
 * Usage: test_lld_js.php [path-to-zabbix_js]   (default: <repo>/src/zabbix_js/zabbix_js)
 */

$zabbix_js = $argv[1] ?? dirname(__DIR__, 4).'/src/zabbix_js/zabbix_js';
if (!is_executable($zabbix_js)) {
	fwrite(STDERR, "zabbix_js not found at {$zabbix_js} — build it (make) or pass its path.\n");
	exit(1);
}

$failures = 0;

function check(bool $ok, string $what, $detail = null): void {
	global $failures;
	echo ($ok ? '  ok: ' : '  FAIL: ').$what.(!$ok && $detail !== null ? '  '.json_encode($detail) : '')."\n";
	$failures += $ok ? 0 : 1;
}

function run_rule(string $zabbix_js, string $role, string $walk): array {
	$script = tempnam(sys_get_temp_dir(), 'rule');
	$input = tempnam(sys_get_temp_dir(), 'walk');
	file_put_contents($script, file_get_contents(__DIR__.'/common.js')."\n".file_get_contents(__DIR__."/{$role}.js"));
	file_put_contents($input, $walk);
	$process = proc_open([$zabbix_js, '-s', $script, '-i', $input], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
	$out = stream_get_contents($pipes[1]);
	$err = stream_get_contents($pipes[2]);
	$code = proc_close($process);
	unlink($script);
	unlink($input);
	$rows = json_decode(trim($out), true);
	// zabbix_js reports a thrown error on stdout (and a non-zero exit code); keep whatever it printed.
	return ['ok' => $code === 0 && is_array($rows), 'rows' => $rows, 'error' => trim($err.' '.$out)];
}

function line(string $oid, string $type, string $value): string {
	return ".{$oid} = {$type}: {$value}\n";
}

function hex(string $mac): string {
	return strtoupper(implode(' ', explode(':', $mac)));
}

// ---- lab fixtures ----
echo "\n=== lab fixtures (real walks of the snmpsim lab) ===\n";
$switch2 = file_get_contents(__DIR__.'/fixtures/lab-switch2.walk');
$ports = run_rule($zabbix_js, 'ports', $switch2);
check($ports['ok'] && count($ports['rows']) === 6 && $ports['rows'][0]['{#IFNAME}'] === 'Gi0/24'
	&& $ports['rows'][0]['{#LOC_CHASSIS}'] === '00:11:22:33:44:12' && $ports['rows'][4]['{#IFOPERSTATUS}'] === '2',
	'PORTS: 6 rows, ifName preferred, lldpLocChassisId used, status codes passed through');
$nbr = run_rule($zabbix_js, 'neighbors', $switch2);
check($nbr['ok'] && count($nbr['rows']) === 5 && $nbr['rows'][0]['{#IFINDEX}'] === '1'
	&& $nbr['rows'][0]['{#REM_CHASSIS}'] === '00:11:22:33:44:02' && $nbr['rows'][0]['{#REM_PORT_DESC}'] === 'GigabitEthernet0/2'
	&& !isset($nbr['rows'][0]['{#REM_CHASSIS_TYPE}']) && $nbr['rows'][0]['{#IFNAME}'] === 'Gi0/24',
	'NEIGHBORS: LocalPortNum used as ifIndex when there is no lldpLocPortTable; no subtype invented');
$macs = run_rule($zabbix_js, 'learned_macs', $switch2);
check($macs['ok'] && $macs['rows'] === [], 'LEARNED_MACS: no bridge tables -> [] (valid, empty)');
$lag = run_rule($zabbix_js, 'lag', $switch2);
check($lag['ok'] && $lag['rows'] === [], 'LAG: no aggregation table -> []');
foreach (['ports', 'neighbors', 'lag'] as $role) {
	$empty = run_rule($zabbix_js, $role, "# nothing\n");
	check(!$empty['ok'] && str_contains($empty['error'], 'holds no variables'), "{$role}: a walk without variables throws (last snapshot stays)");
}
$empty = run_rule($zabbix_js, 'learned_macs', "# device has no bridge tables\n");
check($empty['ok'] && $empty['rows'] === [], 'learned_macs: an empty walk is valid (device without bridge tables)');
foreach (['router1', 'switch1', 'ups1', 'accessswitch1', 'accessswitch2'] as $name) {
	$walk = file_get_contents(__DIR__."/fixtures/lab-{$name}.walk");
	$r = run_rule($zabbix_js, 'neighbors', $walk);
	check($r['ok'] && count($r['rows']) >= 1 && count(array_filter($r['rows'], static fn (array $row): bool => !isset($row['{#IFINDEX}']))) === 0,
		"lab {$name}: every neighbor row has an ifIndex");
}

// ---- synthetic walk ----
echo "\n=== synthetic walk (subtypes, lldpLocPortTable, management addresses, FDB, LAG) ===\n";
$w = '';
$w .= line('1.3.6.1.2.1.1.5.0', 'STRING', '"core1"');
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
// no lldpLocChassisId on purpose: the chassis falls back to the lowest-ifIndex port MAC.
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
$w .= line('1.0.8802.1.1.2.1.4.2.1.3.0.103.1.2.16.32.1.13.184.0.0.0.0.0.0.0.0.0.0.0.1', 'INTEGER', '2'); // IPv6: ignored

$n = run_rule($zabbix_js, 'neighbors', $w);
$by = [];
foreach ($n['rows'] ?? [] as $row) {
	$by[$row['{#REM_SYSNAME}'] ?? $row['{#REM_CHASSIS}']] = $row;
}
check($n['ok'] && count($n['rows']) === 4, 'NEIGHBORS: four rows', $n);
$d = $by['dist1'] ?? [];
check(($d['{#IFINDEX}'] ?? '') === '1' && ($d['{#REM_CHASSIS}'] ?? '') === 'aa:bb:cc:00:00:01' && ($d['{#REM_CHASSIS_TYPE}'] ?? '') === 'macAddress'
	&& ($d['{#REM_PORT}'] ?? '') === 'Te1/0/1' && ($d['{#REM_PORT_TYPE}'] ?? '') === 'interfaceName'
	&& ($d['{#REM_PORT_DESC}'] ?? '') === 'TenGigabitEthernet1/0/1' && ($d['{#REM_MGMT_IP}'] ?? '') === '10.0.0.9',
	'local port resolved by interfaceName; chassis/port subtypes named; IPv4 management address taken from the index', $d);
check(($d['{#LOC_CHASSIS}'] ?? '') === '02:00:00:00:00:01', 'no lldpLocChassisId: chassis falls back to the lowest-ifIndex port MAC', $d);
$m = $by['aa:bb:cc:00:00:09'] ?? $by['192.168.1.10'] ?? [];
$net = array_values(array_filter($n['rows'], static fn (array $r): bool => ($r['{#REM_CHASSIS_TYPE}'] ?? '') === 'networkAddress'))[0] ?? [];
check(($net['{#IFINDEX}'] ?? '') === '2' && ($net['{#REM_CHASSIS}'] ?? '') === '192.168.1.10' && ($net['{#REM_PORT}'] ?? '') === 'aa:bb:cc:00:00:09'
	&& ($net['{#REM_PORT_TYPE}'] ?? '') === 'macAddress', 'local port resolved by macAddress; networkAddress chassis decoded to dotted IPv4; MAC port id', $net);
$e = $by['edge3'] ?? [];
check(($e['{#IFINDEX}'] ?? '') === '3' && ($e['{#REM_CHASSIS}'] ?? '') === 'sw-local' && ($e['{#REM_PORT}'] ?? '') === '12'
	&& !isset($e['{#REM_MGMT_IP}']), 'local port resolved by "local" subtype; text ids kept; IPv6 management address not used as an IP', $e);
$o = $by['orphan-sys'] ?? [];
check($o && !isset($o['{#IFINDEX}']), 'a local port that cannot be resolved is emitted WITHOUT {#IFINDEX} (the server reports it)', $o);

$p = run_rule($zabbix_js, 'ports', $w);
check($p['ok'] && count($p['rows']) === 5 && $p['rows'][3]['{#IFINDEX}'] === '10' && $p['rows'][3]['{#IFTYPE}'] === '161'
	&& $p['rows'][3]['{#IFSPEED}'] === '2000' && $p['rows'][4]['{#IFOPERSTATUS}'] === '2', 'PORTS: enums reduced to numbers ("name(161)" -> 161), speeds kept', $p['rows'][3] ?? null);

// LAG
$l = '';
foreach ([1 => 10, 2 => 10, 3 => 0, 10 => 10, 11 => 11] as $member => $agg) {
	$l .= line("1.2.840.10006.300.43.1.2.1.1.13.{$member}", 'INTEGER', (string) $agg);
}
$lag = run_rule($zabbix_js, 'lag', $l);
check($lag['ok'] && $lag['rows'] === [['{#IFINDEX}' => '1', '{#LAG_IFINDEX}' => '10'], ['{#IFINDEX}' => '2', '{#LAG_IFINDEX}' => '10']],
	'LAG: members only (0 = not aggregated, self-attached skipped)', $lag['rows'] ?? null);

// FDB — Q-BRIDGE
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
for ($i = 0; $i < 25; $i++) {
	$add(30, [0x02, 0x99, 0x00, 0x00, 0x00, $i], 5, 3);	// trunk: 25 MACs on bridge port 5 = ifIndex 10
}
$fdb = run_rule($zabbix_js, 'learned_macs', $f);
$rows = $fdb['rows'] ?? [];
check($fdb['ok'] && count($rows) === 4, 'LEARNED_MACS (Q-BRIDGE): 2 + 1 MAC rows and one count-only trunk row', $rows);
check(($rows[0]['{#IFINDEX}'] ?? '') === '1' && ($rows[0]['{#MAC}'] ?? '') === '00:11:22:33:44:aa' && ($rows[0]['{#VLAN}'] ?? '') === '10'
	&& ($rows[2]['{#IFINDEX}'] ?? '') === '2' && ($rows[2]['{#MAC}'] ?? '') === '00:11:22:33:44:cc', 'MAC decoded from the index, bridge port mapped to ifIndex, VLAN kept', $rows);
check(($rows[3] ?? null) === ['{#IFINDEX}' => '10', '{#PORT_MAC_COUNT}' => '25'], 'more than 20 MACs: one count-only row', $rows[3] ?? null);

// FDB — BRIDGE only
$b = '';
$b .= line('1.3.6.1.2.1.17.1.4.1.2.1', 'INTEGER', '1');
$b .= line('1.3.6.1.2.1.17.4.3.1.2.0.17.34.51.68.170', 'INTEGER', '1');
$b .= line('1.3.6.1.2.1.17.4.3.1.3.0.17.34.51.68.170', 'INTEGER', 'learned(3)');
$fdb = run_rule($zabbix_js, 'learned_macs', $b);
check($fdb['ok'] && $fdb['rows'] === [['{#IFINDEX}' => '1', '{#MAC}' => '00:11:22:33:44:aa']], 'LEARNED_MACS (BRIDGE-MIB fallback, no VLAN)', $fdb['rows'] ?? null);

echo $failures === 0 ? "\nAll JS rule tests passed.\n" : "\n{$failures} check(s) FAILED.\n";
exit($failures === 0 ? 0 : 1);
