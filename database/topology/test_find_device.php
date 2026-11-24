#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * test_find_device.php — task 119 section 2, topology-1-model.md 2.100 §12.1 "Find a device": the text of the search field as a MAC in any common form; a placeholder MAC and text that is
 * not a MAC find nothing by MAC. (The search of the data, by name, MAC and IP, is checked on the real page: ui_check_040/find_check.py.)
 */

require_once __DIR__.'/../../ui/include/classes/topology/CTopologyAddress.php';

function check(bool $condition, string $description, $detail = null): void {
	if (!$condition) {
		fwrite(STDERR, "FAIL: {$description}".($detail !== null ? ' '.json_encode($detail) : '')."\n");
		exit(1);
	}
	echo "  ok: {$description}\n";
}

$mac = static fn (string $text): ?string => CTopologyAddress::searchMac($text);
$expected = 'a8:52:d4:53:11:3f';

echo "\n=== the forms of a MAC ===\n";
foreach (['a8:52:d4:53:11:3f' => 'colons', 'A8:52:D4:53:11:3F' => 'colons, upper case', 'a8-52-d4-53-11-3f' => 'dashes', 'A8-52-D4-53-11-3F' => 'dashes, upper case',
		'a852.d453.113f' => 'Cisco dots', 'A852.D453.113F' => 'Cisco dots, upper case', 'a852d453113f' => 'no separator', 'A852D453113F' => 'no separator, upper case',
		'  a8:52:d4:53:11:3f  ' => 'spaces around', "\ta852.d453.113f\n" => 'tab and line feed around'] as $text => $label) {
	check($mac($text) === $expected, "a MAC with {$label} is read", $mac($text));
}

echo "\n=== not a MAC, or a placeholder: nothing ===\n";
foreach (['' => 'empty', 'sw12' => 'a name', 'a8:52:d4:53:11' => 'five octets', 'a8:52:d4:53:11:3f:00' => 'seven octets', 'a852d453113' => '11 digits', 'a852d453113f0' => '13 digits',
		'a8:52:d4:53:11:3g' => 'a non-hex digit', 'a8:52-d4:53-11:3f' => 'mixed separators', '10.0.0.1' => 'an IPv4 address', '00:00:00:00:00:00' => 'the all-zero placeholder',
		'ff:ff:ff:ff:ff:ff' => 'the all-ones placeholder', '000000000000' => 'the all-zero placeholder, no separator', 'FFFF.FFFF.FFFF' => 'the all-ones placeholder, dotted',
		'00-00-00-00-00-00' => 'the all-zero placeholder, dashes'] as $text => $label) {
	check($mac($text) === null, "{$label} is no MAC for the search", $mac($text));
}

echo "\nOK: all checks passed\n";
