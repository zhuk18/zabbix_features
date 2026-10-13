#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * normalization_cases.php — the native step against ../normalization_cases.json (topology-6-normalization.md §3-§4, handoff 025).
 *
 * The step is reached through `topo_step --fn <function> <hex>`: the input is the hex of its UTF-8 bytes (a Hex-STRING value for text_or_hex; the string
 * "mac|mac1,mac2,..." for port_mac), the output is the hex of the result bytes, a decimal number (numeric_id, port_mac) or `-` for no value.
 * Also checks that the table in preproc_topology.c is the file of the spec folder.
 *
 *   normalization_cases.php [--topo-step=PATH]
 */

$options = getopt('', ['topo-step::']);
$topo_step = $options['topo-step'] ?? __DIR__.'/topo_step';

if (!is_executable($topo_step)) {
	fwrite(STDERR, "topo_step not found at {$topo_step} — run build.sh.\n");
	exit(1);
}

$checks = 0;
$failures = 0;

function check(bool $ok, string $what, $detail = null): void {
	global $checks, $failures;

	$checks++;
	$failures += $ok ? 0 : 1;
	echo ($ok ? '  ok: ' : '  FAIL: ').$what.(!$ok && $detail !== null ? "\n        ".(is_string($detail) ? $detail : json_encode($detail, JSON_UNESCAPED_UNICODE)) : '')."\n";
}

function call(string $function, string $input): string {
	global $topo_step;

	$process = proc_open([$topo_step, '--fn', $function, bin2hex($input)], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
	$out = trim(stream_get_contents($pipes[1]));
	stream_get_contents($pipes[2]);
	proc_close($process);

	return $out;
}

$cases = json_decode((string) file_get_contents(__DIR__.'/../normalization_cases.json'), true, 512, JSON_THROW_ON_ERROR);

foreach ($cases as $function => $list) {
	echo "\n=== {$function} ===\n";

	foreach ($list as $case) {
		$in = $case['in'];

		switch ($function) {
			case 'text_or_hex':
				$arg = hex2bin(str_replace(' ', '', $in));
				break;
			case 'port_mac':
				$arg = $in['mac'].'|'.implode(',', $in['ports']);
				break;
			default:
				$arg = $in;
		}

		$out = call($function, $arg);

		if (in_array($function, ['numeric_id', 'port_mac'], true)) {
			$got = $out === '-' ? null : (ctype_digit($out) ? (int) $out : $out);
		}
		else {
			$got = $out === '-' ? null : hex2bin($out);
		}

		check($got === $case['out'], $function.' '.json_encode($in, JSON_UNESCAPED_UNICODE).' -> '.json_encode($case['out'], JSON_UNESCAPED_UNICODE), 'got '.json_encode($got, JSON_UNESCAPED_UNICODE));
	}
}

echo "\n=== the code copy of port-name-forms.tsv ===\n";
$spec = getenv('TOPOLOGY_NORMALIZATION_FORMS') ?: '/media/sf_topology-specs/normalization/port-name-forms.tsv';

if (is_readable($spec)) {
	exec('python3 '.escapeshellarg(__DIR__.'/../gen_port_name_forms.py').' --check --spec '.escapeshellarg($spec), $lines, $code);
	check($code === 0, 'the table in the three implementations is the file of the spec folder', implode("\n", $lines));
}
else {
	echo "  skip: {$spec} is not readable\n";
}

echo "\n".($failures === 0 ? "OK: {$checks} checks\n" : "FAILED: {$failures} of {$checks} checks\n");
exit($failures === 0 ? 0 : 1);
