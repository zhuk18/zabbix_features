#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * test_closing_ticks.php — task 131 part 1, topology-1-model.md 2.110 §6.1 "Heartbeat gap": every loop of the closing stages of a run starts with `ingest_tick(...)`, so that the run's heartbeat gap is the
 * tick interval at every size. The check reads the source (no database, no timing): the closing stages are the code of ingest.php after `ingest_stage('closing')` up to the end, the functions of
 * the closing work in the ingest classes (the inventory MAC match, the stale observations, the pseudo ports left over, the manual links that are contradicted) and the end of `resolveLinks`
 * (the observations that mirror the snapshots). A loop without a tick must say why on the line before it: `// no tick: <reason>` (a loop over a handful of rows). A new loop in these places without
 * a tick fails this test, which is how a later task finds it (a timing test would depend on the size of the data).
 *
 * Usage: test_closing_ticks.php
 */

function fail_test(string $message): void {
	fwrite(STDERR, "FAIL: {$message}\n");
	exit(1);
}

function check(bool $condition, string $description): void {
	if (!$condition) {
		fail_test($description);
	}
	echo "  ok: {$description}\n";
}

/** The loops of a piece of source that have no tick: [line number => text]. A loop is a line that opens a foreach, for or while with `{`, or the continuation line `... as $x) {` of a foreach. */
function loops_without_tick(array $lines, int $from, int $to, string $name): array {
	$bad = [];
	for ($i = $from; $i < $to; $i++) {
		$line = $lines[$i];
		$opens = preg_match('/^\s*(foreach|for|while)\b.*\{\s*$/', $line) === 1
			|| (preg_match('/\bas \$\w+( => (\$\w+|\[[^\]]*\]))?\) \{\s*$/', $line) === 1 && preg_match('/^\s*(foreach|for|while)\b/', $line) !== 1);
		if (!$opens) {
			continue;
		}
		$next = '';
		for ($j = $i + 1; $j < $to && trim($lines[$j]) === ''; $j++) {
		}
		$next = trim($lines[$j] ?? '');
		$previous = trim($lines[$i - 1] ?? '');
		if (!str_starts_with($next, 'ingest_tick(') && !str_starts_with($previous, '// no tick:')) {
			$bad[$i + 1] = $name.': '.trim($line);
		}
	}

	return $bad;
}

/** The first and the last line of a method (from its signature to the closing brace at one tab). */
function method_range(array $lines, string $signature): array {
	foreach ($lines as $i => $line) {
		if (str_contains($line, $signature)) {
			for ($j = $i + 1; $j < count($lines); $j++) {
				if (rtrim($lines[$j]) === "\t}") {
					return [$i, $j];
				}
			}
		}
	}
	fail_test("no method with the signature {$signature}");
}

$bad = [];
$files = [
	'ingest.php' => null,
	'ingest/IngestMarks.php' => ['public function associateHostsByMac(): void {', 'public function cleanStaleObservations(array $usable_itemids): void {'],
	'ingest/IngestFarPort.php' => ['public function convertLeftoverPseudoPorts(int $device_id): void {'],
	'ingest/IngestLinks.php' => ['public function countContradictedManualLinks'],
];
$checked = 0;
foreach ($files as $file => $methods) {
	$lines = file(__DIR__.'/'.$file, FILE_IGNORE_NEW_LINES);
	if ($methods === null) {
		// the code after the clock moves to the closing work
		$from = null;
		foreach ($lines as $i => $line) {
			if (trim($line) === "ingest_stage('closing');") {
				$from = $i;
			}
		}
		check($from !== null, "ingest.php moves the clock to the closing work (ingest_stage('closing'))");
		$bad += loops_without_tick($lines, $from, count($lines), $file);
		$checked++;
		continue;
	}
	foreach ($methods as $signature) {
		[$a, $b] = method_range($lines, $signature);
		$bad += loops_without_tick($lines, $a, $b, $file.' '.trim($signature));
		$checked++;
	}
}
// the end of resolveLinks: the observations that mirror the snapshots (step 8)
$lines = file(__DIR__.'/ingest/IngestLinks.php', FILE_IGNORE_NEW_LINES);
$from = null;
foreach ($lines as $i => $line) {
	if (str_contains($line, '// 8. Observations mirror the latest snapshot of each rule.')) {
		$from = $i;
	}
}
check($from !== null, 'resolveLinks has the step 8 (the observations mirror the snapshots)');
$bad += loops_without_tick($lines, $from, $from + 40, 'ingest/IngestLinks.php step 8');
check($checked >= 5, "{$checked} closing places read");
check($bad === [], 'every loop of the closing stages starts with ingest_tick(): '.json_encode($bad, JSON_UNESCAPED_UNICODE));
echo "OK: all checks passed\n";
