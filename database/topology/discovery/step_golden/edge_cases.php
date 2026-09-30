#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Edge cases of the "SNMP walk to topology rows" step (topology-lld-part3-spec.md §6.2) on synthetic walks: anchors
 * and missing_mib, garbage input, the MAC limit, escaping of untrusted LLDP strings, parameter errors.
 *
 *   edge_cases.php [--topo-step=PATH]
 */

$options = getopt('', ['topo-step::']);
$topo_step = $options['topo-step'] ?? __DIR__.'/topo_step';

if (!is_executable($topo_step)) {
	fwrite(STDERR, "topo_step not found at {$topo_step} — run build.sh.\n");
	exit(1);
}

$failures = 0;
$checks = 0;

function check(bool $ok, string $what, $detail = null): void {
	global $failures, $checks;

	$checks++;
	$failures += $ok ? 0 : 1;
	echo ($ok ? '  ok: ' : '  FAIL: ').$what.(!$ok && $detail !== null ? "\n        ".(is_string($detail) ? $detail : json_encode($detail)) : '')."\n";
}

/** Runs the step; ['ok' => bool, 'rows' => array|null, 'text' => raw output]. */
function step(string $walk, string $source, string $missing = 'error', string $limit = ''): array {
	global $topo_step;

	$file = tempnam(sys_get_temp_dir(), 'walk');
	file_put_contents($file, $walk);
	$command = [$topo_step, $file, $source, $missing];

	if ($limit !== '') {
		$command[] = $limit;
	}

	$process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
	$out = trim(stream_get_contents($pipes[1]));
	stream_get_contents($pipes[2]);
	$code = proc_close($process);
	unlink($file);

	$rows = json_decode($out, true);

	return ['ok' => $code === 0, 'rows' => is_array($rows) ? $rows : null, 'text' => $out];
}

function line(string $oid, string $type, string $value): string {
	return ".{$oid} = {$type}: {$value}\n";
}

$if_mib = line('1.3.6.1.2.1.2.2.1.1.1', 'INTEGER', '1').line('1.3.6.1.2.1.2.2.1.3.1', 'INTEGER', 'ethernetCsmacd(6)').
	line('1.3.6.1.2.1.31.1.1.1.1.1', 'STRING', '"Gi0/1"');

echo "\n=== anchors and missing_mib ===\n";
$sources = ['ports', 'lldp', 'cdp', 'fdb', 'lag'];
$other_mib = line('1.3.6.1.2.1.1.5.0', 'STRING', '"sw"');	// a walk with variables, but none of the source's MIB

foreach ($sources as $source) {
	$r = step($other_mib, $source, 'error');
	check(!$r['ok'] && str_contains($r['text'], 'holds no'), "{$source}: the MIB is absent, missing_mib=error -> step error", $r['text']);
	$r = step($other_mib, $source, 'empty');
	check($r['ok'] && $r['rows'] === [], "{$source}: the MIB is absent, missing_mib=empty -> []", $r['text']);
}

$r = step($if_mib, 'ports');
check($r['ok'] && count($r['rows']) === 1, 'ports: the anchor (ifType) is present -> a row');

$r = step($if_mib.line('1.0.8802.1.1.2.1.3.7.1.2.1', 'INTEGER', '5'), 'lldp', 'error');
check($r['ok'] && $r['rows'] === [], 'lldp: the anchor is present and no neighbors -> [], not an error, even with missing_mib=error', $r['text']);

$r = step($if_mib.line('1.0.8802.1.1.2.1.4.1.1.9.0.1.1', 'STRING', '"peer"'), 'lldp', 'error');
check($r['ok'] && count($r['rows']) === 1, 'lldp: a device that has lldpRemTable but no lldpLocPortTable is still LLDP (LocalPortNum = ifIndex)', $r['text']);

$r = step(line('1.3.6.1.4.1.9.9.23.1.3.1.0', 'INTEGER', 'true(1)'), 'cdp', 'error');
check($r['ok'] && $r['rows'] === [], 'cdp: only cdpGlobalRun present -> []', $r['text']);

$r = step($if_mib.line('1.3.6.1.2.1.17.1.4.1.2.1', 'INTEGER', '1'), 'fdb', 'error');
check($r['ok'] && $r['rows'] === [], 'fdb: dot1dBasePortIfIndex present and no entries -> []', $r['text']);

$r = step($if_mib.line('1.2.840.10006.300.43.1.2.1.1.13.1', 'INTEGER', '0'), 'lag', 'error');
check($r['ok'] && $r['rows'] === [], 'lag: the column is present and nobody is aggregated -> []', $r['text']);

echo "\n=== empty and comment-only walks ===\n";
foreach (['', "\n", "# device has no bridge tables\n"] as $walk) {
	$label = json_encode($walk);
	$r = step($walk, 'fdb', 'empty');
	check($r['ok'] && $r['rows'] === [], "fdb: walk {$label} -> [] with missing_mib=empty", $r['text']);
	$r = step($walk, 'ports', 'error');
	check(!$r['ok'], "ports: walk {$label} -> step error", $r['text']);
}

$r = step("# device has no bridge tables\n".$if_mib, 'ports');
check($r['ok'] && count($r['rows']) === 1, 'a comment line in front of a real walk is ignored');

echo "\n=== garbage is an error, never [] ===\n";
foreach (['garbage', "this is not a walk\nat all\n", '.1.3.6.1 not-an-assignment', $if_mib."\x01\x02broken"] as $garbage) {
	foreach ($sources as $source) {
		$r = step($garbage, $source, 'empty');
		check(!$r['ok'] && $r['rows'] === null, "{$source} with missing_mib=empty: ".json_encode(substr($garbage, 0, 24))." -> error", $r['text']);
	}
}

// a walk cut in the middle of a variable
$r = step($if_mib.'.1.3.6.1.2.1.2.2.1.6.1 = Hex-STRING: 02 00 00', 'ports', 'empty');
check($r['ok'] || str_starts_with($r['text'], 'ERROR'), 'a truncated last variable is either parsed or an error, never a crash', $r['text']);

echo "\n=== MAC limit ===\n";
$macs = static function (int $n): string {
	$w = line('1.3.6.1.2.1.17.1.4.1.2.1', 'INTEGER', '1');

	for ($i = 0; $i < $n; $i++) {
		$w .= line("1.3.6.1.2.1.17.4.3.1.2.2.153.0.0.0.{$i}", 'INTEGER', '1');
		$w .= line("1.3.6.1.2.1.17.4.3.1.3.2.153.0.0.0.{$i}", 'INTEGER', 'learned(3)');
	}

	return $w;
};
$w = $if_mib.$macs(3);
$r = step($w, 'fdb', 'empty', '3');
check($r['ok'] && count($r['rows']) === 3 && isset($r['rows'][0]['{#MAC}']), 'exactly mac_limit MACs -> one row per MAC', $r['text']);
$r = step($if_mib.$macs(4), 'fdb', 'empty', '3');
check($r['ok'] && $r['rows'] === [['{#IFINDEX}' => '1', '{#PORT_MAC_COUNT}' => '4']], 'mac_limit + 1 MACs -> one count-only row', $r['text']);
$r = step($if_mib.$macs(21), 'fdb', 'empty');
check($r['ok'] && $r['rows'] === [['{#IFINDEX}' => '1', '{#PORT_MAC_COUNT}' => '21']], 'the default limit is 20', $r['text']);
$r = step($if_mib.$macs(20), 'fdb', 'empty', '');
check($r['ok'] && count($r['rows']) === 20, 'an empty mac_limit parameter means the default', $r['text']);

echo "\n=== untrusted strings come out as valid JSON ===\n";
$nasty = "sys \"quoted\" back\\slash \x01\x1f tab\t \xff\xfe bad-utf8 \u{1F600}";
$w = $if_mib.line('1.0.8802.1.1.2.1.3.7.1.2.1', 'INTEGER', '5').line('1.0.8802.1.1.2.1.3.7.1.3.1', 'STRING', '"Gi0/1"').
	line('1.0.8802.1.1.2.1.4.1.1.9.0.1.1', 'STRING', '"'.str_replace('"', '\\"', $nasty).'"').
	line('1.0.8802.1.1.2.1.4.1.1.8.0.1.1', 'STRING', '"desc\\\\ with \\"quotes\\""');
$r = step($w, 'lldp', 'error');
check($r['ok'] && $r['rows'] !== null && count($r['rows']) === 1 && $r['text'] === json_encode($r['rows'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_IGNORE) || $r['rows'] !== null,
	'quotes, backslashes, control characters and invalid UTF-8 in lldpRemSysName / lldpRemPortDesc: valid JSON', $r['text']);
check($r['rows'] !== null && isset($r['rows'][0]['{#REM_SYSNAME}']) && str_contains($r['rows'][0]['{#REM_SYSNAME}'], 'quoted')
	&& mb_check_encoding($r['rows'][0]['{#REM_SYSNAME}'], 'UTF-8'), 'the decoded sysname is valid UTF-8 and keeps the readable text', $r['rows'][0] ?? null);

echo "\n=== parameters ===\n";
foreach ([['', 'error'], ['bogus', 'error'], ['ports', 'sometimes'], ['fdb', 'empty', '0'], ['fdb', 'empty', '-1'], ['fdb', 'empty', 'many']] as $args) {
	$r = step($if_mib, ...$args);
	check(!$r['ok'] && str_contains($r['text'], 'invalid'), 'invalid parameters '.json_encode($args).' -> error', $r['text']);
}

echo "\n".($failures === 0 ? "OK: {$checks} checks\n" : "FAILED: {$failures} of {$checks} checks\n");
exit($failures === 0 ? 0 : 1);
