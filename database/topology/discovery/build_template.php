#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Builds template_topology_by_snmp.yaml ("Topology by SNMP") — topology-lld-part3-spec.md §7.
 *
 * Two SNMP walk[] master items (interfaces + LLDP + CDP + LAG every 30m, bridge/FDB tables every 10m, no history,
 * no discard steps) and five DEPENDENT discovery rules, each with exactly one preprocessing step
 * "SNMP walk to topology rows": no JavaScript, no LLD macro paths.
 *
 * It replaces "Topology by SNMP (temporary JS)" (template_topology_by_snmp_js.yaml, built from lld_js/). The two use
 * the same item and rule keys on purpose: Zabbix refuses to link both to one host.
 *
 * Usage: build_template.php [output-file]   (default: template_topology_by_snmp.yaml next to this script)
 */

$output = $argv[1] ?? __DIR__.'/template_topology_by_snmp.yaml';

// The OID set of lld_js/walk_oids.txt plus CISCO-CDP-MIB (cdpCacheTable, cdpGlobalRun), split by refresh interval.
$core_oids = ['1.3.6.1.2.1.1.5', '1.3.6.1.2.1.2.2.1', '1.3.6.1.2.1.31.1.1.1.1', '1.3.6.1.2.1.31.1.1.1.15',
	'1.0.8802.1.1.2.1.3.2', '1.0.8802.1.1.2.1.3.7', '1.0.8802.1.1.2.1.4.1', '1.0.8802.1.1.2.1.4.2',
	'1.2.840.10006.300.43.1.2.1.1.13', '1.3.6.1.4.1.9.9.23.1.2.1.1', '1.3.6.1.4.1.9.9.23.1.3.1'];
$fdb_oids = ['1.3.6.1.2.1.17.1.4.1.2', '1.3.6.1.2.1.17.7.1.2.2.1', '1.3.6.1.2.1.17.4.3.1'];

$listed = array_filter(array_map('trim', file(__DIR__.'/lld_js/walk_oids.txt')));
$expected = array_merge(array_slice($core_oids, 0, 9), $fdb_oids);

if (array_values(array_diff($expected, $listed)) || array_values(array_diff($listed, $expected))) {
	fwrite(STDERR, "lld_js/walk_oids.txt and the OID lists in build_template.php differ — keep them identical.\n");
	exit(1);
}

$uuid = static function (string $seed): string {
	$hex = md5('topology-by-snmp:'.$seed);

	// UUIDv4 shape: version nibble 4, variant 8..b — the importer validates it.
	return substr($hex, 0, 12).'4'.substr($hex, 13, 3).dechex(8 + (hexdec($hex[16]) & 3)).substr($hex, 17, 15);
};

// key, name, role, step source, missing MIB action, master. An empty result for everything but ports: a template
// applied to a mixed fleet must not turn "the device has no CDP / LAG / bridge tables" into an unsupported rule.
// Ports stay "error": no IF-MIB means the walk itself is broken.
$rules = [
	['key' => 'topology.ports', 'name' => 'Topology ports', 'role' => 'PORTS', 'source' => 'ports',
		'missing' => 'error', 'master' => 'topology.walk.core',
		'description' => 'One row per interface (IF-MIB ifTable / ifXTable).'],
	['key' => 'topology.lldp', 'name' => 'Topology neighbors (LLDP)', 'role' => 'NEIGHBORS', 'source' => 'lldp',
		'missing' => 'empty', 'master' => 'topology.walk.core',
		'description' => 'LLDP neighbors (LLDP-MIB lldpRemTable), joined to the local ifIndex.'],
	['key' => 'topology.cdp', 'name' => 'Topology neighbors (CDP)', 'role' => 'NEIGHBORS', 'source' => 'cdp',
		'missing' => 'empty', 'master' => 'topology.walk.core',
		'description' => 'CDP neighbors (CISCO-CDP-MIB cdpCacheTable).'],
	['key' => 'topology.fdb', 'name' => 'Topology learned MACs', 'role' => 'LEARNED_MACS', 'source' => 'fdb',
		'missing' => 'empty', 'master' => 'topology.walk.fdb',
		'description' => 'MAC addresses learned per port (Q-BRIDGE-MIB, else BRIDGE-MIB); a port with more MACs than the limit is reported by count only.'],
	['key' => 'topology.lag', 'name' => 'Topology LAG membership', 'role' => 'LAG', 'source' => 'lag',
		'missing' => 'empty', 'master' => 'topology.walk.core',
		'description' => 'Member ports of link aggregations (IEEE8023-LAG-MIB).']
];

$yaml = <<<YAML
zabbix_export:
  version: '8.0'
  template_groups:
    - uuid: {$uuid('group')}
      name: 'Templates/Network devices'
  templates:
    - uuid: {$uuid('template')}
      template: 'Topology by SNMP'
      name: 'Topology by SNMP'
      description: |
        Collects the network topology through Zabbix low-level discovery (topology-lld-part3-spec.md). Two SNMP
        walk[] master items feed five dependent discovery rules, one per data set; each rule has one preprocessing
        step "SNMP walk to topology rows" that turns the walk into the rows of its topology role. The master items
        store no history and have no discard steps: every value is a confirmation that the links are alive.

        The rules for LLDP, CDP, learned MACs and LAG return an empty result when the device does not have that MIB,
        so the template can be applied to a mixed fleet. The ports rule fails when there is no IF-MIB.

        Replaces "Topology by SNMP (temporary JS)"; the two cannot be linked to one host.
      groups:
        - name: 'Templates/Network devices'
      items:
        - uuid: {$uuid('item:core')}
          name: 'Topology walk: interfaces, LLDP, CDP, LAG'
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
          description: 'One walk of IF-MIB, LLDP-MIB, CISCO-CDP-MIB and the 802.3ad aggregation table; feeds the ports, LLDP, CDP and LAG rules.'
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
          description: 'BRIDGE-MIB / Q-BRIDGE-MIB forwarding tables; feeds the learned MACs rule. A device without these tables answers "No Such Instance"; that specific error becomes an empty walk (the rule then returns an empty result), any other error (timeout, wrong community) keeps the item unsupported so the last good snapshot stays.'
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
	$yaml .= "        - uuid: {$uuid('rule:'.$rule['key'])}\n";
	$yaml .= "          name: '{$rule['name']}'\n";
	$yaml .= "          type: DEPENDENT\n";
	$yaml .= "          key: {$rule['key']}\n";
	$yaml .= "          topology_role: {$rule['role']}\n";
	$yaml .= "          lifetime_type: DELETE_NEVER\n";
	$yaml .= "          enabled_lifetime_type: DISABLE_NEVER\n";
	$yaml .= "          description: '{$rule['description']}'\n";
	$yaml .= "          master_item:\n            key: {$rule['master']}\n";
	$yaml .= "          preprocessing:\n";
	$yaml .= "            - type: SNMP_WALK_TO_TOPOLOGY\n";
	$yaml .= "              parameters:\n";
	$yaml .= "                - {$rule['source']}\n";
	$yaml .= "                - {$rule['missing']}\n";
	$yaml .= "                - '20'\n";
}

file_put_contents($output, $yaml);
echo "wrote {$output} (".strlen($yaml)." bytes)\n";
