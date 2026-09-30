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
 * The "SNMP walk to topology rows" preprocessing step (topology-lld-part3-spec.md §3, §5): API validation, where it is
 * allowed, defaults, export/import and audit.
 *
 * @onBefore prepareTestData
 * @onAfter  cleanTestData
 */
class testDiscoveryRuleTopologyStep extends CAPITest {

	private static array $ids = [];

	private static function step(string $params): array {
		return [[
			'type' => ZBX_PREPROC_SNMP_WALK_TO_TOPOLOGY,
			'params' => $params,
			'error_handler' => ZBX_PREPROC_FAIL_DEFAULT,
			'error_handler_params' => ''
		]];
	}

	public static function prepareTestData(): void {
		self::$ids['groupid'] = CDataHelper::call('templategroup.create', [
			'name' => 'API topology step templates'
		])['groupids'][0];

		self::$ids['templateid'] = CDataHelper::call('template.create', [
			'host' => 'API topology step template',
			'groups' => [['groupid' => self::$ids['groupid']]]
		])['templateids'][0];

		self::$ids['parent_ruleid'] = CDataHelper::call('discoveryrule.create', [
			'hostid' => self::$ids['templateid'],
			'name' => 'Parent rule',
			'key_' => 'topo.step.parent',
			'type' => ITEM_TYPE_TRAPPER
		])['itemids'][0];
	}

	public static function cleanTestData(): void {
		$templateids = CDBHelper::getColumn(
			'SELECT hostid FROM hosts WHERE host LIKE '.zbx_dbstr('API topology step %').' AND status='.HOST_STATUS_TEMPLATE,
			'hostid'
		);

		if ($templateids) {
			CDataHelper::call('template.delete', $templateids);
		}

		CDataHelper::call('templategroup.delete', [self::$ids['groupid']]);
	}

	private function getParams(string $itemid): string {
		return $this->call('discoveryrule.get', [
			'output' => [],
			'itemids' => $itemid,
			'selectPreprocessing' => ['type', 'params']
		])['result'][0]['preprocessing'][0]['params'];
	}

	public static function getSources(): array {
		return [
			'ports' => ['ports', ZBX_TOPOLOGY_ROLE_PORTS],
			'lldp' => ['lldp', ZBX_TOPOLOGY_ROLE_NEIGHBORS],
			'cdp' => ['cdp', ZBX_TOPOLOGY_ROLE_NEIGHBORS],
			'fdb' => ['fdb', ZBX_TOPOLOGY_ROLE_LEARNED_MACS],
			'lag' => ['lag', ZBX_TOPOLOGY_ROLE_LAG]
		];
	}

	/**
	 * @dataProvider getSources
	 */
	public function testDiscoveryRuleTopologyStep_CreateForEverySource(string $source, int $role): void {
		$itemid = $this->call('discoveryrule.create', [
			'hostid' => self::$ids['templateid'],
			'name' => 'Rule '.$source,
			'key_' => 'topo.step.'.$source,
			'type' => ITEM_TYPE_TRAPPER,
			'topology_role' => $role,
			'preprocessing' => self::step($source."\nempty\n50")
		])['result']['itemids'][0];

		$this->assertSame($source."\nempty\n50", $this->getParams($itemid));
	}

	public function testDiscoveryRuleTopologyStep_DefaultsFilled(): void {
		$itemid = $this->call('discoveryrule.create', [
			'hostid' => self::$ids['templateid'],
			'name' => 'Only the source',
			'key_' => 'topo.step.defaults',
			'type' => ITEM_TYPE_TRAPPER,
			'preprocessing' => self::step('fdb')
		])['result']['itemids'][0];

		$this->assertSame("fdb\nerror\n20", $this->getParams($itemid), 'missing_mib defaults to error, mac_limit to 20');
	}

	public static function getInvalidParams(): array {
		$sources = 'value must be one of "ports", "lldp", "cdp", "fdb", "lag".';

		return [
			'no source' => ['', 'Invalid parameter "/1/preprocessing/1/params/1": '.$sources],
			'unknown source' => ['routes', 'Invalid parameter "/1/preprocessing/1/params/1": '.$sources],
			'unknown missing MIB action' => ["ports\nmaybe",
				'Invalid parameter "/1/preprocessing/1/params/2": value must be one of "error", "empty".'
			],
			'zero MAC limit' => ["fdb\nempty\n0",
				'Invalid parameter "/1/preprocessing/1/params/3": value must be one of 1-2147483647.'
			],
			'MAC limit is not a number' => ["fdb\nempty\nmany",
				'Invalid parameter "/1/preprocessing/1/params/3": an integer is expected.'
			],
			'too many parameters' => ["fdb\nempty\n20\nx",
				'Invalid parameter "/1/preprocessing/1/params": unexpected parameter "4".'
			]
		];
	}

	/**
	 * @dataProvider getInvalidParams
	 */
	public function testDiscoveryRuleTopologyStep_InvalidParams(string $params, string $error): void {
		$this->call('discoveryrule.create', [
			'hostid' => self::$ids['templateid'],
			'name' => 'Invalid',
			'key_' => 'topo.step.invalid',
			'type' => ITEM_TYPE_TRAPPER,
			'preprocessing' => self::step($params)
		], $error);
	}

	/**
	 * The step makes LLD JSON: items and item prototypes do not get it, discovery rule prototypes do.
	 */
	public function testDiscoveryRuleTopologyStep_OnlyOnDiscoveryRules(): void {
		if (CAPIHelper::getSessionId() === null) {
			$this->authorize(PHPUNIT_LOGIN_NAME, PHPUNIT_LOGIN_PWD);
		}

		// Both must answer that the type is not in the list of the object's supported types (31 is not there).
		foreach ([
			'item.create' => [
				'hostid' => self::$ids['templateid'],
				'name' => 'Item with the step',
				'key_' => 'topo.step.item',
				'type' => ITEM_TYPE_TRAPPER,
				'value_type' => ITEM_VALUE_TYPE_TEXT,
				'preprocessing' => self::step("ports\nerror\n20")
			],
			'itemprototype.create' => [
				'hostid' => self::$ids['templateid'],
				'ruleid' => self::$ids['parent_ruleid'],
				'name' => 'Prototype with the step {#N}',
				'key_' => 'topo.step.itemproto[{#N}]',
				'type' => ITEM_TYPE_TRAPPER,
				'value_type' => ITEM_VALUE_TYPE_TEXT,
				'preprocessing' => self::step("ports\nerror\n20")
			]
		] as $method => $params) {
			$response = CAPIHelper::call($method, $params);

			$this->assertArrayHasKey('error', $response, $method.' accepted the topology step');
			$this->assertStringStartsWith('Invalid parameter "/1/preprocessing/1/type": value must be one of ',
				$response['error']['data']
			);
			$this->assertStringNotContainsString(', '.ZBX_PREPROC_SNMP_WALK_TO_TOPOLOGY, $response['error']['data']);
		}

		$prototypeid = $this->call('discoveryruleprototype.create', [
			'hostid' => self::$ids['templateid'],
			'ruleid' => self::$ids['parent_ruleid'],
			'name' => 'Rule prototype {#N}',
			'key_' => 'topo.step.ruleproto[{#N}]',
			'type' => ITEM_TYPE_TRAPPER,
			'topology_role' => ZBX_TOPOLOGY_ROLE_NEIGHBORS,
			'preprocessing' => self::step("lldp\nempty\n20")
		])['result']['itemids'][0];

		$prototype = $this->call('discoveryruleprototype.get', [
			'output' => [],
			'itemids' => $prototypeid,
			'selectPreprocessing' => ['type', 'params']
		])['result'][0]['preprocessing'][0];

		$this->assertEquals(ZBX_PREPROC_SNMP_WALK_TO_TOPOLOGY, $prototype['type']);
		$this->assertSame("lldp\nempty\n20", $prototype['params']);
	}

	/**
	 * The step has no "custom on fail": a failed walk keeps the last snapshot, it must not be turned into a value.
	 */
	public function testDiscoveryRuleTopologyStep_NoErrorHandler(): void {
		$this->call('discoveryrule.create', [
			'hostid' => self::$ids['templateid'],
			'name' => 'With handler',
			'key_' => 'topo.step.handler',
			'type' => ITEM_TYPE_TRAPPER,
			'preprocessing' => [[
				'type' => ZBX_PREPROC_SNMP_WALK_TO_TOPOLOGY,
				'params' => "ports\nerror\n20",
				'error_handler' => ZBX_PREPROC_FAIL_SET_VALUE,
				'error_handler_params' => '[]'
			]]
		], 'Invalid parameter "/1/preprocessing/1/error_handler": value must be 0.');
	}

	public function testDiscoveryRuleTopologyStep_Update(): void {
		$itemid = $this->call('discoveryrule.create', [
			'hostid' => self::$ids['templateid'],
			'name' => 'To update',
			'key_' => 'topo.step.update',
			'type' => ITEM_TYPE_TRAPPER,
			'topology_role' => ZBX_TOPOLOGY_ROLE_LEARNED_MACS,
			'preprocessing' => self::step("fdb\nempty\n20")
		])['result']['itemids'][0];

		$this->call('discoveryrule.update', [
			'itemid' => $itemid,
			'preprocessing' => self::step("fdb\nempty\n7")
		]);
		$this->assertSame("fdb\nempty\n7", $this->getParams($itemid));

		$this->call('discoveryrule.update', [
			'itemid' => $itemid,
			'preprocessing' => self::step("nothing\nempty\n7")
		], 'Invalid parameter "/1/preprocessing/1/params/1": value must be one of "ports", "lldp", "cdp", "fdb", "lag".');
	}

	public function testDiscoveryRuleTopologyStep_Inheritance(): void {
		$template2id = CDataHelper::call('template.create', [
			'host' => 'API topology step nested template',
			'groups' => [['groupid' => self::$ids['groupid']]],
			'templates' => [['templateid' => self::$ids['templateid']]]
		])['templateids'][0];

		$itemid = $this->call('discoveryrule.get', [
			'output' => ['itemid'],
			'hostids' => $template2id,
			'filter' => ['key_' => 'topo.step.lldp']
		])['result'][0]['itemid'];

		$this->assertSame("lldp\nempty\n50", $this->getParams($itemid));
	}

	/**
	 * A template with the step survives export and import with every step and parameter.
	 *
	 * @depends testDiscoveryRuleTopologyStep_Inheritance
	 */
	public function testDiscoveryRuleTopologyStep_ExportImport(): void {
		$yaml = $this->call('configuration.export', [
			'format' => 'yaml',
			'options' => ['templates' => [self::$ids['templateid']]]
		])['result'];

		$this->assertSame(1, preg_match('/type: SNMP_WALK_TO_TOPOLOGY/', $yaml));

		$yaml = str_replace("'API topology step template'", "'API topology step imported'", $yaml);
		$yaml = preg_replace_callback('/uuid: [0-9a-f]{32}/', static fn() => 'uuid: '.generateUuidV4(), $yaml);

		$rules = [
			'template_groups' => ['createMissing' => true],
			'templates' => ['createMissing' => true],
			'items' => ['createMissing' => true],
			'discoveryRules' => ['createMissing' => true]
		];

		$this->call('configuration.import', ['format' => 'yaml', 'source' => $yaml, 'rules' => $rules]);

		$imported = $this->call('template.get', [
			'output' => ['templateid'],
			'filter' => ['host' => 'API topology step imported']
		])['result'][0]['templateid'];

		$read = function (string $templateid): array {
			$result = [];

			foreach ($this->call('discoveryrule.get', [
				'output' => ['key_', 'topology_role'],
				'hostids' => $templateid,
				'selectPreprocessing' => ['type', 'params']
			])['result'] as $rule) {
				$result[$rule['key_']] = [$rule['topology_role'], $rule['preprocessing']];
			}

			ksort($result);

			return $result;
		};

		$original = $read(self::$ids['templateid']);
		$this->assertNotEmpty($original['topo.step.fdb'][1]);
		$this->assertEquals($original, $read($imported));

	}

	public function testDiscoveryRuleTopologyStep_Audit(): void {
		$itemid = $this->call('discoveryrule.create', [
			'hostid' => self::$ids['templateid'],
			'name' => 'Audited',
			'key_' => 'topo.step.audit',
			'type' => ITEM_TYPE_TRAPPER,
			'preprocessing' => self::step("cdp\nerror\n20")
		])['result']['itemids'][0];

		$details = $this->call('auditlog.get', [
			'output' => ['details'],
			'filter' => ['resourceid' => $itemid, 'action' => 0 /* CAudit::ACTION_ADD */],
			'sortfield' => 'clock',
			'sortorder' => ZBX_SORT_DOWN,
			'limit' => 1
		])['result'][0]['details'];

		$this->assertStringContainsString('discoveryrule.preprocessing[', $details);
		$this->assertStringContainsString("cdp\\nerror\\n20", $details);
	}
}
