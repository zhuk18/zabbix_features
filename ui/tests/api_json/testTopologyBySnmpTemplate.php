<?php
/*
** Copyright (C) 2001-2026 Zabbix SIA
**
** This program is free software: you can redistribute it and/or modify it under the terms of
** the GNU Affero General Public License as published by the Free Software Foundation, version 3.
**
** This program is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY;
** without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
** See the GNU Affero General Public License for more details.
**
** You should have received a copy of the GNU Affero General Public License along with this program.
** If not, see <https://www.gnu.org/licenses/>.
**/


require_once __DIR__.'/../include/CAPITest.php';

/**
 * The "Topology by SNMP" template (topology-lld-part3-spec.md §7): its shape, and that it is the file the builder
 * writes. The template is imported under another name and uuids, so a lab template of the same name is not touched.
 *
 * @onBefore prepareTestData
 * @onAfter  cleanTestData
 */
class testTopologyBySnmpTemplate extends CAPITest {

	private const DIR = __DIR__.'/../../../database/topology/discovery';
	private const COPY = 'API topology by SNMP copy';

	private static array $ids = [];

	private static function makeCopy(string $file, string $name): string {
		$yaml = file_get_contents(self::DIR.'/'.$file);
		$yaml = preg_replace("/^(\s+template: ).*$/m", '$1'."'".$name."'", $yaml);
		$yaml = preg_replace("/^(\s+name: )'Topology by SNMP[^']*'$/m", '$1'."'".$name."'", $yaml);

		return preg_replace_callback('/uuid: [0-9a-f]{32}/', static fn() => 'uuid: '.generateUuidV4(), $yaml);
	}

	private const RULES = [
		'topology.ports' => [ZBX_TOPOLOGY_ROLE_PORTS, 'topology.walk.core', "ports\nerror\n20"],
		'topology.lldp' => [ZBX_TOPOLOGY_ROLE_NEIGHBORS, 'topology.walk.core', "lldp\nempty\n20"],
		'topology.cdp' => [ZBX_TOPOLOGY_ROLE_NEIGHBORS, 'topology.walk.core', "cdp\nempty\n20"],
		'topology.fdb' => [ZBX_TOPOLOGY_ROLE_LEARNED_MACS, 'topology.walk.fdb', "fdb\nempty\n20"],
		'topology.lag' => [ZBX_TOPOLOGY_ROLE_LAG, 'topology.walk.core', "lag\nempty\n20"]
	];

	public static function prepareTestData(): void {
		self::$ids['groupid'] = CDataHelper::call('templategroup.create', ['name' => 'API topology by SNMP group'])
			['groupids'][0];
		self::$ids['hostgroupid'] = CDataHelper::call('hostgroup.create', ['name' => 'API topology by SNMP hosts'])
			['groupids'][0];
	}

	public static function cleanTestData(): void {
		$hostids = CDBHelper::getColumn(
			'SELECT hostid FROM hosts WHERE host LIKE '.zbx_dbstr('API topology by SNMP %').' AND status<>'.HOST_STATUS_TEMPLATE,
			'hostid'
		);

		if ($hostids) {
			CDataHelper::call('host.delete', $hostids);
		}

		$templateids = CDBHelper::getColumn(
			'SELECT hostid FROM hosts WHERE host LIKE '.zbx_dbstr('API topology by SNMP %').' AND status='.HOST_STATUS_TEMPLATE,
			'hostid'
		);

		if ($templateids) {
			CDataHelper::call('template.delete', $templateids);
		}

		CDataHelper::call('hostgroup.delete', [self::$ids['hostgroupid']]);
		CDataHelper::call('templategroup.delete', [self::$ids['groupid']]);
	}

	/**
	 * The committed YAML must be what the builder writes: the YAML is generated, edit the builder.
	 */
	public function testTopologyBySnmpTemplate_IsBuiltFromTheBuilder(): void {
		$file = tempnam(sys_get_temp_dir(), 'tpl');
		exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg(self::DIR.'/build_template.php').' '.escapeshellarg($file),
			$output, $code
		);

		$this->assertSame(0, $code, implode("\n", $output));
		$this->assertSame(file_get_contents($file), file_get_contents(self::DIR.'/template_topology_by_snmp.yaml'));
		unlink($file);
	}

	public function testTopologyBySnmpTemplate_Import(): void {
		$this->call('configuration.import', [
			'format' => 'yaml',
			'source' => self::makeCopy('template_topology_by_snmp.yaml', self::COPY),
			'rules' => [
				'template_groups' => ['createMissing' => true],
				'templates' => ['createMissing' => true],
				'items' => ['createMissing' => true],
				'discoveryRules' => ['createMissing' => true]
			]
		]);

		self::$ids['templateid'] = $this->call('template.get', [
			'output' => ['templateid'],
			'filter' => ['host' => self::COPY]
		])['result'][0]['templateid'];
	}

	/**
	 * @depends testTopologyBySnmpTemplate_Import
	 */
	public function testTopologyBySnmpTemplate_MasterItems(): void {
		$items = $this->call('item.get', [
			'output' => ['key_', 'type', 'delay', 'history', 'trends', 'value_type', 'snmp_oid'],
			'hostids' => self::$ids['templateid'],
			'selectPreprocessing' => ['type', 'params'],
			'sortfield' => 'key_'
		])['result'];

		$this->assertSame(['topology.walk.core', 'topology.walk.fdb'], array_column($items, 'key_'));

		foreach ($items as $item) {
			$this->assertEquals(ITEM_TYPE_SNMP, $item['type']);
			$this->assertEquals(ITEM_VALUE_TYPE_TEXT, $item['value_type']);
			$this->assertSame('0', $item['history'], 'the master item stores no history');
			$this->assertSame('0', $item['trends']);

			// No discard / throttling step: it would stop values reaching the rules and freeze the snapshot clock.
			$this->assertSame([], array_intersect(array_column($item['preprocessing'], 'type'),
				[ZBX_PREPROC_THROTTLE_VALUE, ZBX_PREPROC_THROTTLE_TIMED_VALUE]
			), $item['key_'].' has a discard step');
		}

		$this->assertSame('30m', $items[0]['delay']);
		$this->assertSame('10m', $items[1]['delay']);

		// The core walk carries the OID set of the JavaScript template and CISCO-CDP-MIB.
		$walked = explode(',', substr($items[0]['snmp_oid'], 5, -1));
		$this->assertContains('1.3.6.1.4.1.9.9.23.1.2.1.1', $walked, 'cdpCacheTable');
		$this->assertContains('1.3.6.1.4.1.9.9.23.1.3.1', $walked, 'cdpGlobalRun');
		$this->assertContains('1.0.8802.1.1.2.1.4.1', $walked, 'lldpRemTable');

		$fdb = explode(',', substr($items[1]['snmp_oid'], 5, -1));
		$this->assertSame(['1.3.6.1.2.1.17.1.4.1.2', '1.3.6.1.2.1.17.7.1.2.2.1', '1.3.6.1.2.1.17.4.3.1'], $fdb);

		// The bridge master turns "no bridge tables" into the comment the step ignores; nothing else.
		$this->assertSame([], array_column($items[0]['preprocessing'], 'type'));
		$this->assertEquals([ZBX_PREPROC_VALIDATE_NOT_SUPPORTED], array_column($items[1]['preprocessing'], 'type'));
	}

	/**
	 * @depends testTopologyBySnmpTemplate_Import
	 */
	public function testTopologyBySnmpTemplate_Rules(): void {
		$rules = $this->call('discoveryrule.get', [
			'output' => ['key_', 'type', 'topology_role', 'master_itemid', 'lifetime_type', 'enabled_lifetime_type'],
			'hostids' => self::$ids['templateid'],
			'selectPreprocessing' => ['type', 'params', 'error_handler'],
			'selectLLDMacroPaths' => ['lld_macro', 'path'],
			'sortfield' => 'key_'
		])['result'];

		$masters = array_column($this->call('item.get', [
			'output' => ['itemid', 'key_'],
			'hostids' => self::$ids['templateid']
		])['result'], 'key_', 'itemid');

		$this->assertEqualsCanonicalizing(array_keys(self::RULES), array_column($rules, 'key_'));

		foreach ($rules as $rule) {
			[$role, $master, $params] = self::RULES[$rule['key_']];

			$this->assertEquals(ITEM_TYPE_DEPENDENT, $rule['type'], $rule['key_']);
			$this->assertEquals($role, $rule['topology_role'], $rule['key_'].' role');
			$this->assertSame($master, $masters[$rule['master_itemid']], $rule['key_'].' master');

			// exactly one step: the native one, with no custom on-fail, no JS and no LLD macro paths
			$this->assertCount(1, $rule['preprocessing'], $rule['key_']);
			$this->assertEquals(ZBX_PREPROC_SNMP_WALK_TO_TOPOLOGY, $rule['preprocessing'][0]['type']);
			$this->assertSame($params, $rule['preprocessing'][0]['params'], $rule['key_'].' parameters');
			$this->assertEquals(ZBX_PREPROC_FAIL_DEFAULT, $rule['preprocessing'][0]['error_handler']);
			$this->assertSame([], $rule['lld_macro_paths'], $rule['key_'].' has LLD macro paths');

			// a rule that must not lose links when a device drops out for a while
			$this->assertEquals(ZBX_LLD_DELETE_NEVER, $rule['lifetime_type']);
		}
	}

	/**
	 * The two templates are alternatives: they share item and rule keys, and Zabbix refuses to link both.
	 *
	 * @depends testTopologyBySnmpTemplate_Import
	 */
	public function testTopologyBySnmpTemplate_ExcludesTheTemporaryTemplate(): void {
		$this->call('configuration.import', [
			'format' => 'yaml',
			'source' => self::makeCopy('template_topology_by_snmp_js.yaml', 'API topology by SNMP js copy'),
			'rules' => [
				'template_groups' => ['createMissing' => true],
				'templates' => ['createMissing' => true],
				'items' => ['createMissing' => true],
				'discoveryRules' => ['createMissing' => true]
			]
		]);

		$js_templateid = $this->call('template.get', [
			'output' => ['templateid'],
			'filter' => ['host' => 'API topology by SNMP js copy']
		])['result'][0]['templateid'];

		$hostid = $this->call('host.create', [
			'host' => 'API topology by SNMP host',
			'groups' => [['groupid' => self::$ids['hostgroupid']]],
			'interfaces' => [[
				'type' => INTERFACE_TYPE_SNMP, 'main' => INTERFACE_PRIMARY, 'useip' => INTERFACE_USE_IP,
				'ip' => '127.0.0.1', 'dns' => '', 'port' => '161',
				'details' => ['version' => SNMP_V2C, 'bulk' => SNMP_BULK_ENABLED, 'community' => 'zbxlab']
			]],
			'templates' => [['templateid' => self::$ids['templateid']]]
		])['result']['hostids'][0];

		$response = CAPIHelper::call('host.update', [
			'hostid' => $hostid,
			'templates' => [['templateid' => self::$ids['templateid']], ['templateid' => $js_templateid]]
		]);

		$this->assertArrayHasKey('error', $response, 'both templates were linked to one host');
		$this->assertStringContainsString('an item with the same key is already inherited from template', $response['error']['data']);

		// and the host got the five rules from the native template
		$keys = array_column($this->call('discoveryrule.get', [
			'output' => ['key_'],
			'hostids' => $hostid
		])['result'], 'key_');

		$this->assertEqualsCanonicalizing(array_keys(self::RULES), $keys);
	}
}
