#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * row_contract_frontend.php — the frontend side of the row contract (task 128 part 1; model §5.2, FR-B D-14).
 *
 * Reads discovery/row_contract_cases.json and prints one JSON object:
 *   cases:  case name -> the rows that CTopologyType::checkRows() (the check of the Test dialog, the one place of the frontend that validates rows) passes: `passed`, `failed`;
 *   tables: the differences between the contract table of the server (src/zabbix_server/lld/lld_topology.c) and CTopologyType, read from the sources: a macro, its required flag and its "one of"
 *           group, for every topology type; an empty list means the two tables name the same macros.
 * It needs no database and writes nothing.
 *
 * Usage: php row_contract_frontend.php
 */

$root = realpath(__DIR__.'/../../../..');
require_once $root.'/ui/include/defines.inc.php';
require_once $root.'/ui/include/classes/topology/CTopologyType.php';

$table = json_decode((string) file_get_contents(__DIR__.'/../../discovery/row_contract_cases.json'), true, 512, JSON_THROW_ON_ERROR);
$cases = [];

foreach ($table['cases'] as $case) {
	$check = CTopologyType::checkRows((int) $case['type'], $case['rows'], []);
	$cases[$case['name']] = ['passed' => $check['passed'], 'failed' => $check['failed'], 'snmpindex_fallback_rows' => $check['snmpindex_fallback_rows']];
}

// the server's table: `static const zbx_lld_topo_field_t topo_<name>[] = { {"{#MACRO}", "field", TYPE, required, group, deflt}, ... }`
$source = (string) file_get_contents($root.'/src/zabbix_server/lld/lld_topology.c');
$server = [];
$of_type = [1 => 'topo_ports', 2 => 'topo_neighbors', 3 => 'topo_learned_macs', 4 => 'topo_lag', 5 => 'topo_device'];

foreach ($of_type as $type => $name) {
	if (!preg_match('/'.$name.'\[\] = \{(.*?)\n\};/s', $source, $block)) {
		throw new RuntimeException('no table '.$name.' in lld_topology.c');
	}

	preg_match_all('/\{"(\{#[A-Z_]+\})",\s*"[a-z_]+",\s*ZBX_LLD_TOPO_(STR|UINT),\s*(\d),\s*(\d),/', $block[1], $rows, PREG_SET_ORDER);

	foreach ($rows as $row) {
		$server[$type][$row[1]] = ['required' => (int) $row[3], 'group' => (int) $row[4], 'numeric' => $row[2] === 'UINT'];
	}
}

$differences = [];
// the numbers of the "one of" groups are labels: the groups are compared as sets of macros
$shape = static function (array $macros): array {
	$groups = [];

	foreach ($macros as $macro => $d) {
		if ($d['group'] !== 0) {
			$groups[$d['group']][] = $macro;
		}
	}

	foreach ($groups as &$members) {
		sort($members);
	}
	unset($members);

	$groups = array_map(static fn (array $members): string => implode('|', $members), $groups);
	sort($groups);

	return [
		'macros' => array_keys($macros),
		'numeric' => array_keys(array_filter($macros, static fn (array $d): bool => ($d['numeric'] ?? false) === true)),
		'required' => array_keys(array_filter($macros, static fn (array $d): bool => $d['required'] === 1)),
		'groups' => $groups
	];
};

foreach ($of_type as $type => $name) {
	$contract = CTopologyType::getContract($type);
	$front = [];

	foreach ($contract['required'] as $macro) {
		$front[$macro] = ['required' => 1, 'group' => 0];
	}

	foreach ($contract['one_of'] as $i => $group) {
		foreach ($group as $macro) {
			$front[$macro] = ['required' => 0, 'group' => $i + 1];
		}
	}

	foreach ($contract['optional'] as $macro) {
		$front[$macro] = ['required' => 0, 'group' => 0];
	}

	foreach ($contract['numeric'] as $macro) {
		$front[$macro]['numeric'] = true;
	}

	$s = $shape($server[$type]);
	$f = $shape($front);
	sort($s['macros']);
	sort($f['macros']);
	sort($s['required']);
	sort($f['required']);

	foreach (array_diff($s['macros'], $f['macros']) as $macro) {
		$differences[] = CTopologyType::getLabel($type).' '.$macro.': in the server table, not in CTopologyType';
	}

	foreach (array_diff($f['macros'], $s['macros']) as $macro) {
		$differences[] = CTopologyType::getLabel($type).' '.$macro.': in CTopologyType, not in the server table';
	}

	sort($s['numeric']);
	sort($f['numeric']);

	if ($s['numeric'] !== $f['numeric']) {
		$differences[] = CTopologyType::getLabel($type).': numeric macros differ: server '.implode(' ', $s['numeric']).'; frontend '.implode(' ', $f['numeric']);
	}

	if ($s['required'] !== $f['required']) {
		$differences[] = CTopologyType::getLabel($type).': required macros differ: server '.implode(' ', $s['required']).'; frontend '.implode(' ', $f['required']);
	}

	if ($s['groups'] !== $f['groups']) {
		$differences[] = CTopologyType::getLabel($type).': "one of" groups differ: server '.implode(' ; ', $s['groups']).'; frontend '.implode(' ; ', $f['groups']);
	}
}

echo json_encode(['cases' => $cases, 'tables' => $differences], JSON_UNESCAPED_SLASHES), "\n";
