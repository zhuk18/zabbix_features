#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Golden test of the "SNMP walk to topology rows" step (topology-lld-part3-spec.md §6.1): the C step must produce
 * exactly what the reference JavaScript of Part 2 produces on the same walk.
 *
 *   compare.php [--generate] [--topo-step=PATH] [--zabbix-js=PATH]
 *
 * Default: runs the C driver (build.sh) on every fixtures/*.walk for every source and compares with the committed
 * expected/<fixture>.<source>.json. --generate rewrites the expected files from the JavaScript (zabbix_js) instead;
 * the JS is the reference, the diff of that rewrite is the review.
 *
 * Rows are compared as sets: the JS and the C step order rows differently, and ingest sorts them again before
 * hashing (Part 2). Every fixture is also compared live against the JS when zabbix_js is available.
 */

$root = dirname(__DIR__, 4);
$options = getopt('', ['generate', 'topo-step::', 'zabbix-js::']);
$topo_step = $options['topo-step'] ?? __DIR__.'/topo_step';
$zabbix_js = $options['zabbix-js'] ?? $root.'/src/zabbix_js/zabbix_js';
$generate = array_key_exists('generate', $options);
$js_dir = dirname(__DIR__).'/lld_js';
$fixtures = array_merge(glob(dirname(__DIR__).'/lld_js/fixtures/*.walk'), glob(__DIR__.'/fixtures/*.walk'));
sort($fixtures);

// source => JS rule file. The C step runs with missing_mib=empty: a source the device does not have is an empty
// result, which is what the JS returns for it. cdp has no JS: its expected/*.cdp.json files are written by hand.
$sources = ['ports' => 'ports', 'lldp' => 'neighbors', 'fdb' => 'learned_macs', 'lag' => 'lag', 'cdp' => null];

$failures = 0;
$checks = 0;

function check(bool $ok, string $what, string $detail = ''): void {
	global $failures, $checks;

	$checks++;
	$failures += $ok ? 0 : 1;
	echo ($ok ? '  ok: ' : '  FAIL: ').$what.($ok || $detail === '' ? '' : "\n        ".$detail)."\n";
}

function canonical(array $rows): array {
	$out = [];

	foreach ($rows as $row) {
		ksort($row);
		$out[] = json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
	}

	sort($out);

	return $out;
}

function run(array $command): array {
	$process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
	$out = stream_get_contents($pipes[1]);
	$err = stream_get_contents($pipes[2]);
	$code = proc_close($process);

	return ['code' => $code, 'out' => trim($out), 'err' => trim($err)];
}

function run_js(string $zabbix_js, string $js_dir, string $rule, string $walk_file): ?array {
	$script = tempnam(sys_get_temp_dir(), 'rule');
	file_put_contents($script, file_get_contents($js_dir.'/common.js')."\n".file_get_contents($js_dir.'/'.$rule.'.js'));
	$result = run([$zabbix_js, '-s', $script, '-i', $walk_file]);
	unlink($script);

	// a thrown error is a failed step: the caller treats it as "no expectation"
	$rows = json_decode($result['out'], true);

	return $result['code'] === 0 && is_array($rows) ? $rows : null;
}

function run_c(string $topo_step, string $walk_file, string $source, string $missing = 'empty', string $limit = ''): array {
	$result = run($limit === '' ? [$topo_step, $walk_file, $source, $missing] : [$topo_step, $walk_file, $source, $missing, $limit]);
	$rows = json_decode($result['out'], true);

	return ['ok' => $result['code'] === 0 && is_array($rows), 'rows' => $rows, 'text' => $result['out']];
}

if (!is_executable($topo_step)) {
	fwrite(STDERR, "topo_step not found at {$topo_step} — run build.sh (needs the built server tree).\n");
	exit(1);
}

$have_js = is_executable($zabbix_js);

if ($generate && !$have_js) {
	fwrite(STDERR, "--generate needs zabbix_js ({$zabbix_js}).\n");
	exit(1);
}

echo "\n=== lab fixtures: C step against the reference JS ===\n";

foreach ($fixtures as $walk_file) {
	$name = basename($walk_file, '.walk');

	foreach ($sources as $source => $rule) {
		$expected_file = __DIR__.'/expected/'.$name.'.'.$source.'.json';
		$js_rows = $have_js && $rule !== null ? run_js($zabbix_js, $js_dir, $rule, $walk_file) : null;

		if ($generate) {
			if ($rule === null) {
				continue;	// hand-reviewed expectation, never overwritten
			}

			if ($js_rows === null) {
				// the JS refuses this walk (no such MIB in it): nothing to compare, and nothing to commit
				if (file_exists($expected_file)) {
					unlink($expected_file);
				}

				echo "  skip: {$name} {$source}: the JS rejects this walk\n";
				continue;
			}

			@mkdir(__DIR__.'/expected');
			usort($js_rows, static fn ($a, $b) => strcmp(json_encode($a), json_encode($b)));
			file_put_contents($expected_file, json_encode(array_map(static function (array $row) {
				ksort($row);
				return $row;
			}, $js_rows), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
			echo "  wrote: {$name} {$source} (".count($js_rows)." rows)\n";
			continue;
		}

		$c = run_c($topo_step, $walk_file, $source);

		if (!file_exists($expected_file) && $rule === null) {
			// CDP is reviewed by hand: a walk without an expectation has no CDP data and must yield no rows
			check($c['ok'] && $c['rows'] === [], "{$name} {$source}: no CDP data, no rows", $c['text']);
			continue;
		}

		if (!file_exists($expected_file)) {
			// where the JS gives up, the step may be an error or an empty result depending on missing_mib
			check($c['ok'], "{$name} {$source}: no expectation (JS rejects the walk), the step must not crash", $c['text']);
			continue;
		}

		$expected = json_decode(file_get_contents($expected_file), true);

		check($c['ok'] && canonical($c['rows']) === canonical($expected),
			"{$name} {$source}: ".count($expected).' rows equal the expected output',
			$c['ok'] ? 'C:  '.implode("\n             ", array_diff(canonical($c['rows']), canonical($expected))).
				"\n        JS: ".implode("\n             ", array_diff(canonical($expected), canonical($c['rows']))) : $c['text']
		);

		if ($js_rows !== null && $rule !== null) {
			check(canonical($js_rows) === canonical($expected), "{$name} {$source}: the committed expected output is the JS output");
		}
	}
}

if (!$generate) {
	echo "\n".($failures === 0 ? "OK: {$checks} checks\n" : "FAILED: {$failures} of {$checks} checks\n");
}

exit($failures === 0 ? 0 : 1);
