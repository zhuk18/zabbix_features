#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * test_freshness.php — the one definition of the freshness threshold (topology-1-model.md "Freshness threshold"):
 * the raw macro text turned into seconds. No database.
 */

require_once __DIR__.'/topology_freshness.inc.php';

function check(bool $condition, string $description): void {
	if (!$condition) {
		fwrite(STDERR, "FAIL: {$description}\n");
		exit(1);
	}
	echo "  ok: {$description}\n";
}

check(TOPOLOGY_FRESH_MACRO === '{$TOPOLOGY.LINK.FRESH.SECONDS}' && TOPOLOGY_FRESH_DEFAULT === 604800 && TOPOLOGY_FRESH_MIN === 60,
	'name, default (7 days) and minimum (60)');
check(topology_fresh_seconds(null) === [604800, null], 'missing macro: default, no warning');
check(topology_fresh_seconds('60') === [60, null], 'the minimum is accepted');
check(topology_fresh_seconds('86400') === [86400, null], 'a normal value is accepted');
check(topology_fresh_seconds('007200') === [7200, null], 'leading zeros are digits');
foreach (['59', '0', '', ' 60', '60 ', '1h', '-100', '60.5', '6e2', "60\n", '1d'] as $bad) {
	[$seconds, $warning] = topology_fresh_seconds($bad);
	check($seconds === 604800 && $warning !== null && str_contains($warning, '{$TOPOLOGY.LINK.FRESH.SECONDS}'),
		'rejected '.json_encode($bad).': default and a warning that names the macro');
}
check(!str_contains(topology_fresh_seconds('<script>')[1], '<script>'), 'the rejected text is escaped in the warning');

echo "\n=== the allowed age of a snapshot (model 2.96 section 6.2, FR-B D-55) ===\n";
check(TOPOLOGY_AGE_INTERVALS === 3 && TOPOLOGY_AGE_MIN === 600, 'constants: 3 intervals, minimum 600 s');
foreach (['30s' => 30, '30' => 30, '5m' => 300, '1h' => 3600, '2d' => 172800, '1w' => 604800, '30m;50s/1-7,00:00-24:00' => 1800, '1m;wd1-5h9-18' => 60] as $text => $seconds) {
	check(topology_interval_seconds((string) $text) === $seconds, 'interval '.json_encode((string) $text).' = '.$seconds.' s (flexible and scheduling parts are not used)');
}
foreach ([null, '', '0', '0;50s/1-7,00:00-24:00', 'wd1-5h9-18', '{$DELAY}', 'abc', '-5m', '1.5h', '10x'] as $text) {
	check(topology_interval_seconds($text) === null, 'no base for '.json_encode($text));
}
check(topology_allowed_age(1800, 604800) === 5400, 'interval 30m: 3 x 1800 = 5400 s');
check(topology_allowed_age(60, 604800) === 600, 'interval 1m: 180 s is below the minimum: 600 s');
check(topology_allowed_age(600, 604800) === 1800, 'interval 10m: 1800 s');
check(topology_allowed_age(86400, 604800) === 259200, 'interval 1d: 3 days');
check(topology_allowed_age(604800, 604800) === 604800, 'interval 1w: 3 weeks is cut to the threshold');
check(topology_allowed_age(1800, 3600) === 3600 && topology_allowed_age(60, 60) === 60, 'never longer than the threshold (also below the minimum: freshness_seconds 60 keeps working)');
check(topology_allowed_age(null, 604800) === 604800 && topology_allowed_age(null, 60) === 60, 'no base: the threshold');
echo "\nAll checks passed.\n";
