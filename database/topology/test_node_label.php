#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * test_node_label.php — the node label of topology-1-model.md §12.2: the first value that is not empty. Host or Proxy: its visible name. Device: sysname, chassis_id (a text
 * subtype as it is, a MAC in its normal form), mgmt_ip, then `?`. Values from the network are returned as they are and rendered as text: the view writes the label with
 * d3's text(), never as markup (§13), and the endpoint encodes it with JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT. No database.
 */

require_once __DIR__.'/../../ui/include/classes/topology/CTopologyPrototype.php';

function check(bool $condition, string $description): void {
	if (!$condition) {
		fwrite(STDERR, "FAIL: {$description}\n");
		exit(1);
	}
	echo "  ok: {$description}\n";
}

function label(?string $sysname, ?string $chassis_id, ?string $mgmt_ip): string {
	return CTopologyPrototype::nodeLabel('device', null, $sysname, $chassis_id, $mgmt_ip);
}

// one Device for each step of the order
check(label('SW1', 'aa:bb:cc:dd:ee:ff', '10.0.0.1') === 'SW1', 'step 2: the sysname comes first');
check(label('', 'ZBX-5CD3053MWT', '10.0.0.1') === 'ZBX-5CD3053MWT', 'step 3: no sysname (an empty string): the chassis id, a text subtype as it is');
check(label(null, 'ZBX-5CD3053MWT', null) === 'ZBX-5CD3053MWT', 'step 3: sysname missing: the chassis id');
check(label('', 'AA-BB-CC-DD-EE-FF', '10.0.0.1') === 'aa:bb:cc:dd:ee:ff', 'step 3: a MAC in its normal form (lower case, colons)');
check(label(null, 'aabb.ccdd.eeff', null) === 'aa:bb:cc:dd:ee:ff', 'step 3: a MAC in the dotted form');
check(label('', '', '192.168.88.1') === '192.168.88.1', 'step 4: no sysname and no chassis id: the management address');
check(label(null, null, null) === '?', 'step 5: nothing: ?');
check(label('  ', ' ', '') === '?', 'a value of spaces is empty');

// Host and Proxy: the visible name
check(CTopologyPrototype::nodeLabel('host', 'Core switch') === 'Core switch', 'step 1: a Host is labelled by its visible name');
check(CTopologyPrototype::nodeLabel('proxy', 'Riga proxy') === 'Riga proxy', 'step 1: a Proxy is labelled by its visible name');
check(CTopologyPrototype::nodeLabel('host', '') === '?', 'a Host with no name: ?');

// a value with HTML: returned as it is, never interpreted
$html = '<img src=x onerror=alert(1)>&"\'';
check(label($html, 'x', 'y') === $html, 'HTML in a sysname is returned unchanged (escaped where it is rendered)');
check(label('', $html, null) === $html, 'HTML in a chassis id is returned unchanged');
$encoded = json_encode(['label' => $html], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
check(!str_contains($encoded, '<') && !str_contains($encoded, '>') && !str_contains($encoded, '&'), 'the endpoint encoding has no raw < > & in the label');

// the view writes the label as text, never as markup
$js = file_get_contents(__DIR__.'/../../ui/app/views/js/monitoring.topology.view.js.php');
check(str_contains($js, "node.label ?? node.name ?? '?'"), 'the view labels a node with `label`');
check(preg_match('/node_labels[^;]*\.(html|innerHTML)\b/', $js) !== 1, 'the label text element is never given markup');
check(str_contains($js, ".text(line)"), 'the label lines are written with text()');
echo "\nAll checks passed.\n";
