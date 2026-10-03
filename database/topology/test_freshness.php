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
echo "\nAll checks passed.\n";
