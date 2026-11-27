#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * test_topology_type_form.php — release 1 offers four topology types and four step sources (model 2.105 §5.1, 2.121; LLD spec 2.22 §2.6, §4.2; task 138), checked in PHP, not in a browser:
 *   - the rule form (`makeTopologyTypeFormRows()`, the code of the form of a discovery rule) offers None, Ports, Neighbors and Device, and its hint lists the contracts of those;
 *   - CTopologyType refuses the reserved types 3 and 4 (the list that the API validates against) and still names them for display and keeps their contract (the server and the contract table);
 *   - the step form (the partial of the preprocessing tab and its script) offers the sources ports, LLDP, CDP and Device, and has no MAC limit field.
 * No database.
 *
 * Usage: test_topology_type_form.php
 */

$ui = realpath(__DIR__.'/../../../ui');
chdir($ui);
set_include_path($ui);
require_once 'include/classes/core/CAutoloader.php';
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

foreach (['debug', 'gettextwrapper', 'defines', 'func', 'html', 'perm', 'users', 'validate', 'locales', 'db'] as $file) {
	require_once "include/{$file}.inc.php";
}
$loader = new CAutoloader();
$loader->addNamespace('', array_map(static fn (string $path): string => "{$ui}/include/classes/{$path}", [
	'api', 'api/services', 'api/helpers', 'api/item_types', 'api/managers', 'api/clients', 'api/wrappers', 'core', 'data',
	'mvc', 'db', 'debug', 'validators', 'validators/schema', 'validators/string', 'validators/object', 'helpers', 'json',
	'routing', 'controllers', 'user', 'topology', 'parsers', 'parsers/results', 'regexp', 'macros', 'items', 'triggers', 'html', 'html/widgets', 'html/interfaces', 'html/svg', 'html/partials'
]));
$loader->register();

echo "=== CTopologyType ===\n";
check(CTopologyType::getTypes() === [0, 1, 2, 5], 'the types of release 1: 0, 1, 2, 5', CTopologyType::getTypes());
check(array_values(CTopologyType::getLabels()) === ['None', 'Ports', 'Neighbors', 'Device'], 'the labels that the form offers: None, Ports, Neighbors, Device', CTopologyType::getLabels());
check(array_values(CTopologyType::getExportStrings()) === ['NONE', 'PORTS', 'NEIGHBORS', 'DEVICE'], 'the import accepts NONE, PORTS, NEIGHBORS, DEVICE only', CTopologyType::getExportStrings());
check(CTopologyType::getLabel(ZBX_TOPOLOGY_TYPE_LEARNED_MACS) === 'Learned MACs' && CTopologyType::getLabel(ZBX_TOPOLOGY_TYPE_LAG) === 'LAG membership', 'a rule that still has a reserved type (data of an earlier build) is named in the list');
check(CTopologyType::getContract(ZBX_TOPOLOGY_TYPE_LEARNED_MACS) !== null && CTopologyType::getContract(ZBX_TOPOLOGY_TYPE_LAG) !== null, 'the contract of the reserved types stays (the server table is compared with it)');
check(CTopologyType::getStepSources() === ['ports', 'lldp', 'cdp', 'device'], 'the step sources of release 1', CTopologyType::getStepSources());
check(ZBX_TOPOLOGY_TYPE_LEARNED_MACS === 3 && ZBX_TOPOLOGY_TYPE_LAG === 4 && ZBX_PREPROC_TOPOLOGY_SOURCE_FDB === 'fdb' && ZBX_PREPROC_TOPOLOGY_SOURCE_LAG === 'lag', 'the constants stay: the numbers do not move later');

echo "=== the rule form ===\n";
$rows = makeTopologyTypeFormRows(ZBX_TOPOLOGY_TYPE_NONE, false);
$html = '';
foreach ($rows as $row) {
	foreach ((array) $row as $part) {
		$html .= is_object($part) ? $part->toString() : (string) $part;
	}
}
preg_match('/data-options="([^"]*)"/', $html, $options);
$offered = json_decode(html_entity_decode($options[1] ?? '[]'), true) ?: [];
check(array_column($offered, 'value') === [0, 1, 2, 5] && array_column($offered, 'label') === ['None', 'Ports', 'Neighbors', 'Device'], 'the select of the topology type offers None, Ports, Neighbors and Device (0, 1, 2, 5) and no other', $offered);
check(!str_contains($html, 'Learned MACs') && !str_contains($html, 'LAG membership') && !str_contains($html, 'LEARNED_MACS') && !str_contains($html, '{#MAC}') && !str_contains($html, '{#LAG_IFINDEX}'), 'the form and its hint name neither Learned MACs nor LAG');
check(str_contains($html, '{#IFINDEX}') && str_contains($html, '{#LOC_CHASSIS}'), 'the hint has the contracts of Ports, Neighbors and Device');

echo "=== the step form ===\n";
$partial = (string) file_get_contents($ui.'/app/partials/item.edit.preprocessing.tab.php');
$script = (string) file_get_contents($ui.'/app/partials/js/item.edit.preprocessing.tab.js.php');
check(str_contains($partial, 'ZBX_PREPROC_TOPOLOGY_SOURCE_PORTS') && str_contains($partial, 'ZBX_PREPROC_TOPOLOGY_SOURCE_LLDP') && str_contains($partial, 'ZBX_PREPROC_TOPOLOGY_SOURCE_CDP') && str_contains($partial, 'ZBX_PREPROC_TOPOLOGY_SOURCE_DEVICE'), 'the form offers the sources ports, LLDP, CDP and Device');
check(!str_contains($partial, 'ZBX_PREPROC_TOPOLOGY_SOURCE_FDB') && !str_contains($partial, 'ZBX_PREPROC_TOPOLOGY_SOURCE_LAG') && !str_contains($partial, 'Learned MACs') && !str_contains($partial, 'LAG members'), 'the form does not offer fdb and lag');
check(!str_contains($partial, 'MAC limit') && !str_contains($partial, 'topology-mac-limit') && !str_contains($script, 'topology-mac-limit') && !str_contains($script, 'TOPOLOGY_SOURCE_FDB'), 'the form has no MAC limit field and the script does not handle it');
check(ZBX_PREPROC_TOPOLOGY_SOURCES_RELEASE_1 === ['ports', 'lldp', 'cdp', 'device'], 'the validators of the step (API and form) use the list of release 1');

echo "\nAll topology type form checks passed ({$checks}).\n";
