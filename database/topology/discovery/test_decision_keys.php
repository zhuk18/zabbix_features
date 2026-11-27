#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * test_decision_keys.php — the rules of CTopologyDecision (topology-1-model.md §6.7.8, task 129): the natural key of a Device, the merge classes (chains, `not_same` wins) and the pairs. No database.
 *
 * Usage: test_decision_keys.php
 */

require_once __DIR__.'/../../../ui/include/classes/topology/CTopologyAddress.php';
require_once __DIR__.'/../../../ui/include/classes/topology/CTopologyDecision.php';

$checks = 0;
function check(bool $ok, string $what, $detail = null): void {
	global $checks;
	$checks++;
	if (!$ok) {
		fwrite(STDERR, "FAIL: {$what}".($detail !== null ? ' '.json_encode($detail) : '')."\n");
		exit(1);
	}
	echo "  ok: {$what}\n";
}

echo "=== natural key ===\n";
check(CTopologyDecision::naturalKey(['cdp:server:core', 'chassis:mac:aa:bb:cc:00:00:02', 'chassis:mac:aa:bb:cc:00:00:01'], [], []) === 'chassis:mac:aa:bb:cc:00:00:01', 'the strongest key: a MAC chassis first, the smallest of equal strength');
check(CTopologyDecision::naturalKey(['cdp:server:core', 'chassis:local:server:sw-1'], [], []) === 'chassis:local:server:sw-1', 'a scoped chassis key before a CDP key');
check(CTopologyDecision::naturalKey(['cdp:server:b', 'cdp:server:a'], [], []) === 'cdp:server:a', 'a CDP key alone: the smallest');
check(CTopologyDecision::naturalKey(['ip:server:10.0.0.1'], [], []) === null, 'an address is no identity key: no natural key without a port');
check(CTopologyDecision::naturalKey([], ['sysname' => 'Phone-1'], [['hostid' => 101, 'name' => 'Gi0/3'], ['hostid' => 100, 'name' => 'Gi0/9']]) === 'port:100:gi0/9:phone-1', 'no key: the reporter port and the name key (the smallest of the ports)');
check(CTopologyDecision::naturalKey([], ['sysname' => ''], [['hostid' => 101, 'name' => 'Gi0/3']]) === null, 'no key and no name: nothing to name the Device by');
check(CTopologyDecision::naturalKey([], ['sysname' => 'x'], []) === null, 'no key and no port: nothing to name the Device by');

echo "=== merge classes ===\n";
$m = static fn (int $id, string $a, string $b, array $more = []): array => ['decisionid' => $id, 'kind' => 'merge', 'key_a' => $a, 'key_b' => $b, 'keys_b' => json_encode($more)];
$n = static fn (int $id, string $a, string $b): array => ['decisionid' => $id, 'kind' => 'not_same', 'key_a' => $a, 'key_b' => $b, 'keys_b' => null];
$c = CTopologyDecision::mergeClasses([$m(1, 'cdp:s:a', 'cdp:s:b', ['cdp:s:b', 'chassis:mac:b'])]);
check($c['class_of']['cdp:s:a'] === $c['class_of']['chassis:mac:b'] && $c['class_of']['cdp:s:b'] === $c['class_of']['cdp:s:a'] && !$c['conflicts'], 'a merge: key_a, key_b and every key of keys_b are one class');
$c = CTopologyDecision::mergeClasses([$m(1, 'A', 'B'), $m(2, 'B', 'C')]);
check($c['class_of']['A'] === $c['class_of']['C'] && count($c['keys']) === 1, 'a chain: A = B and B = C give one class');
$c = CTopologyDecision::mergeClasses([$m(1, 'A', 'B'), $m(2, 'B', 'C'), $n(3, 'A', 'C')]);
check($c['class_of']['A'] !== $c['class_of']['C'] && $c['class_of']['A'] === $c['class_of']['B'] && $c['conflicts'] === [['merge' => 2, 'not_same' => 3]], 'a not_same of A and C wins over the chain: the later merge is refused and listed', $c);
$c = CTopologyDecision::mergeClasses([$n(1, 'A', 'C'), $m(2, 'A', 'B'), $m(3, 'C', 'B')]);
check($c['class_of']['A'] === $c['class_of']['B'] && $c['class_of']['C'] !== $c['class_of']['A'] && $c['conflicts'] === [['merge' => 3, 'not_same' => 1]], 'the order of the records does not hide the conflict: a not_same written first');
$c = CTopologyDecision::mergeClasses([$n(1, 'A', 'B'), $m(2, 'B', 'A')]);
check($c['class_of']['A'] !== $c['class_of']['B'] && count($c['conflicts']) === 1, 'a merge of a pair that has a not_same is refused');
$c = CTopologyDecision::mergeClasses([$m(1, 'A', 'B'), $m(2, 'A', 'B')]);
check(count($c['keys']) === 1 && !$c['conflicts'], 'the same merge twice: one class, no conflict');
$c = CTopologyDecision::mergeClasses([$m(1, 'A', 'B'), $n(2, 'X', 'Y')]);
check(count($c['keys']) === 1 && !$c['conflicts'], 'a not_same of keys that no merge names does nothing here');

echo "=== not_same pairs ===\n";
$records = [$m(1, 'A', 'B'), $n(2, 'B', 'C')];
$pairs = CTopologyDecision::notSamePairs($records, CTopologyDecision::mergeClasses($records));
check($pairs === [[['A', 'B'], ['C']]], 'a not_same names the whole merge class of each side', $pairs);

echo "\nAll decision key checks passed ({$checks}).\n";
