#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Builds template_topology_by_snmp_js.yaml ("Topology by SNMP (temporary JS)") from lld_js/*.js.
 *
 * TEMPORARY: Part 3 replaces the JavaScript with a preset preprocessing step. Until then this JS is the reference
 * implementation of the contract rows (topology-lld-part2-spec.md §9) — keep it readable.
 *
 * Layout: two SNMP walk[] master items (interfaces + LLDP + LAG every 30m, bridge/FDB tables every 10m, no history)
 * and four DEPENDENT discovery rules, one per role, each with one JavaScript step made of common.js + <role>.js.
 *
 * Usage: build_template.php  [output-file]   (default: ../template_topology_by_snmp_js.yaml)
 */

$dir = __DIR__;
$output = $argv[1] ?? dirname($dir).'/template_topology_by_snmp_js.yaml';

// Same OID list as walk_oids.txt, split by refresh interval.
$core_oids = ['1.3.6.1.2.1.1.5', '1.3.6.1.2.1.2.2.1', '1.3.6.1.2.1.31.1.1.1.1', '1.3.6.1.2.1.31.1.1.1.15',
	'1.0.8802.1.1.2.1.3.2', '1.0.8802.1.1.2.1.3.7', '1.0.8802.1.1.2.1.4.1', '1.0.8802.1.1.2.1.4.2',
	'1.2.840.10006.300.43.1.2.1.1.13'];
$fdb_oids = ['1.3.6.1.2.1.17.1.4.1.2', '1.3.6.1.2.1.17.7.1.2.2.1', '1.3.6.1.2.1.17.4.3.1'];

$all = array_merge($core_oids, $fdb_oids);
$listed = array_filter(array_map('trim', file($dir.'/walk_oids.txt')));
if (array_values(array_diff($all, $listed)) || array_values(array_diff($listed, $all))) {
	fwrite(STDERR, "walk_oids.txt and the OID lists in build_template.php differ — keep them identical.\n");
	exit(1);
}

$uuid = static function (string $seed): string {
	$hex = md5('topology-by-snmp-js:'.$seed);
	// UUIDv4 shape: version nibble 4, variant 8..b — the importer validates it.
	return substr($hex, 0, 12).'4'.substr($hex, 13, 3).dechex(8 + (hexdec($hex[16]) & 3)).substr($hex, 17, 15);
};

$script = static function (string $role) use ($dir): string {
	$text = file_get_contents($dir.'/common.js')."\n".file_get_contents($dir."/{$role}.js");
	$lines = [];
	foreach (explode("\n", rtrim($text)) as $line) {
		// Leading tabs -> 4 spaces (YAML block scalars are safest with plain spaces); no trailing blanks.
		$lines[] = rtrim(preg_replace_callback('/^\t+/', static fn (array $m): string => str_repeat('    ', strlen($m[0])), $line));
	}
	return implode("\n", $lines);
};

$indent = static function (string $text, int $spaces): string {
	$pad = str_repeat(' ', $spaces);
	return implode("\n", array_map(static fn (string $line): string => $line === '' ? '' : $pad.$line, explode("\n", $text)));
};

$rules = [
	['role' => 'ports', 'name' => 'Topology ports', 'role_name' => 'PORTS', 'master' => 'topology.walk.core'],
	['role' => 'neighbors', 'name' => 'Topology neighbors (LLDP)', 'role_name' => 'NEIGHBORS', 'master' => 'topology.walk.core'],
	['role' => 'learned_macs', 'name' => 'Topology learned MACs', 'role_name' => 'LEARNED_MACS', 'master' => 'topology.walk.fdb'],
	['role' => 'lag', 'name' => 'Topology LAG membership', 'role_name' => 'LAG', 'master' => 'topology.walk.core'],
];

$yaml = <<<YAML
zabbix_export:
  version: '8.0'
  template_groups:
    - uuid: {$uuid('group')}
      name: 'Templates/Network devices'
  templates:
    - uuid: {$uuid('template')}
      template: 'Topology by SNMP temporary JS'
      name: 'Topology by SNMP (temporary JS)'
      description: |
        TEMPORARY lab template (topology-lld-part2-spec.md §9): collects topology through Zabbix low-level discovery
        with no external scripts. Two SNMP walk[] master items feed four dependent discovery rules, one per
        topology role; each rule's JavaScript emits the rows of that role's macro contract. The master items store
        no history.

        Replaced by a preset preprocessing step in Part 3 — the JavaScript is the reference implementation of it.
      groups:
        - name: 'Templates/Network devices'
      items:
        - uuid: {$uuid('item:core')}
          name: 'Topology walk: interfaces, LLDP, LAG'
          type: SNMP_AGENT
          snmp_oid: 'walk[
YAML;
$yaml .= implode(',', $core_oids)."]'\n";
$yaml .= <<<YAML
          key: topology.walk.core
          delay: 30m
          history: '0'
          trends: '0'
          value_type: TEXT
          description: 'One walk of IF-MIB, LLDP-MIB and the 802.3ad aggregation table; feeds the PORTS, NEIGHBORS and LAG rules.'
        - uuid: {$uuid('item:fdb')}
          name: 'Topology walk: bridge tables (FDB)'
          type: SNMP_AGENT
          snmp_oid: 'walk[
YAML;
$yaml .= implode(',', $fdb_oids)."]'\n";
$yaml .= <<<YAML
          key: topology.walk.fdb
          delay: 10m
          history: '0'
          trends: '0'
          value_type: TEXT
          description: 'BRIDGE-MIB / Q-BRIDGE-MIB forwarding tables; feeds the LEARNED_MACS rule. A device without these tables answers "No Such Instance"; that specific error becomes an empty walk (a valid, empty snapshot), any other error (timeout, wrong community) keeps the item unsupported so the last good snapshot stays.'
          preprocessing:
            - type: CHECK_NOT_SUPPORTED
              parameters:
                - '0'
                - 'No Such (Instance|Object)'
              error_handler: CUSTOM_VALUE
              error_handler_params: '# device has no bridge tables'
      discovery_rules:

YAML;

foreach ($rules as $rule) {
	$yaml .= "        - uuid: {$uuid('rule:'.$rule['role'])}\n";
	$yaml .= "          name: '{$rule['name']}'\n";
	$yaml .= "          type: DEPENDENT\n";
	$yaml .= "          key: topology.{$rule['role']}\n";
	$yaml .= "          topology_role: {$rule['role_name']}\n";
	$yaml .= "          lifetime_type: DELETE_NEVER\n";
	$yaml .= "          enabled_lifetime_type: DISABLE_NEVER\n";
	$yaml .= "          master_item:\n            key: {$rule['master']}\n";
	$yaml .= "          preprocessing:\n";
	$yaml .= "            - type: JAVASCRIPT\n";
	$yaml .= "              parameters:\n";
	$yaml .= "                - |\n".$indent($script($rule['role']), 18)."\n";
}

file_put_contents($output, $yaml);
echo "wrote {$output} (".strlen($yaml)." bytes)\n";
