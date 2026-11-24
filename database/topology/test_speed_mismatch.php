#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * test_speed_mismatch.php — task 119 sections 3 and 5, topology-1-model.md 2.100 §12.3 "Speed mismatch" (FR-C V-25) and §4.8: the rules of the mark and the speed of a load.
 */

require_once __DIR__.'/../../ui/include/classes/topology/CTopologySpeed.php';

function check(bool $condition, string $description, $detail = null): void {
	if (!$condition) {
		fwrite(STDERR, "FAIL: {$description}".($detail !== null ? ' '.json_encode($detail) : '')."\n");
		exit(1);
	}
	echo "  ok: {$description}\n";
}

const GIGA = 1000000000;
const FAST = 100000000;

$port = static fn (array $over = []): array => $over + ['reporter' => true, 'if_type' => 'physical', 'oper_status' => 'up', 'speed' => GIGA];
$mismatch = static fn (array $a, array $b): ?array => CTopologySpeed::mismatch($a, $b);

echo "\n=== the mark of a link ===\n";
check($mismatch($port(), $port(['speed' => FAST])) === ['a' => GIGA, 'b' => FAST], 'both up, both known, different: the mark with both speeds (side A first)', $mismatch($port(), $port(['speed' => FAST])));
check($mismatch($port(['speed' => FAST]), $port()) === ['a' => FAST, 'b' => GIGA], 'the order of the speeds follows the sides');
check($mismatch($port(), $port()) === null, 'equal speeds: no mark');
check($mismatch($port(['oper_status' => 'down']), $port(['speed' => FAST])) === null, 'one side down: no mark');
check($mismatch($port(), $port(['speed' => FAST, 'oper_status' => 'down'])) === null, 'the other side down: no mark');
check($mismatch($port(['oper_status' => null]), $port(['speed' => FAST])) === null, 'one side with no status: no mark');
check($mismatch($port(['speed' => null]), $port(['speed' => FAST])) === null, 'one side with an unknown speed: no mark');
check($mismatch($port(), $port(['speed' => 0])) === null, 'a speed of 0: no mark');
check($mismatch($port(['speed' => -1000000]), $port()) === null, 'a negative speed (-1 Mbit/s of a virtual interface): no mark');
check($mismatch($port(), $port(['speed' => '1000'])) === null, 'a speed that is not a number of bits: no mark');
check($mismatch($port(['if_type' => 'lag']), $port(['speed' => FAST])) === null, 'a LAG aggregate has no mark (its member links are compared)');
check($mismatch($port(), $port(['if_type' => 'lag', 'speed' => FAST])) === null, 'a LAG aggregate at the other end: no mark');
check($mismatch($port(['reporter' => false]), $port(['speed' => FAST])) === null, 'a port that is not a reporter port (a pseudo or a manual port): no mark');
check($mismatch($port(), $port(['speed' => FAST, 'reporter' => false])) === null, 'the same at the other end');
check($mismatch([], []) === null, 'ports with no data: no mark');

echo "\n=== the speed of a load, the percent (section 5) ===\n";
check(CTopologySpeed::known(-1) === null && CTopologySpeed::known(-1000000) === null && CTopologySpeed::known(0) === null && CTopologySpeed::known(null) === null, 'speed -1, -1000000, 0 and none are no speed');
check(CTopologySpeed::known(1000000000) === 1000000000.0 && CTopologySpeed::known('100000000') === 100000000.0, 'a positive speed is a speed');
check(CTopologySpeed::percent(8000000.0, 4000000.0, 1000000000.0) === 0.8, 'the load is the larger direction of the speed: 0.8 %');
check(CTopologySpeed::percent(8000000.0, null, 1000000000.0) === 0.8 && CTopologySpeed::percent(null, 4000000.0, 1000000000.0) === 0.4, 'one direction is enough');
check(CTopologySpeed::percent(8000000.0, 4000000.0, -1.0) === null && CTopologySpeed::percent(8000000.0, 4000000.0, -1000000.0) === null, 'speed -1: no percent');
check(CTopologySpeed::percent(8000000.0, 4000000.0, 0.0) === null && CTopologySpeed::percent(8000000.0, 4000000.0, null) === null, 'speed 0 or none: no percent');
check(CTopologySpeed::percent(null, null, 1000000000.0) === null, 'no value in either direction: no percent');

echo "\nOK: all checks passed\n";
