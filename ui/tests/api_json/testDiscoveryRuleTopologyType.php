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
 * Discovery rule "topology_type" property: API, inheritance, export/import and audit.
 *
 * @onBefore prepareTestData
 * @onAfter  cleanTestData
 */
class testDiscoveryRuleTopologyType extends CAPITest {

	private const TYPES = [
		ZBX_TOPOLOGY_TYPE_NONE, ZBX_TOPOLOGY_TYPE_PORTS, ZBX_TOPOLOGY_TYPE_NEIGHBORS, ZBX_TOPOLOGY_TYPE_LEARNED_MACS,
		ZBX_TOPOLOGY_TYPE_LAG
	];

	private static array $ids = [];

	public static function prepareTestData(): void {
		self::$ids['groupid'] = CDataHelper::call('hostgroup.create', [
			'name' => 'API topology type hosts'
		])['groupids'][0];
		self::$ids['tpl_groupid'] = CDataHelper::call('templategroup.create', [
			'name' => 'API topology type templates'
		])['groupids'][0];

		self::$ids['templateid'] = CDataHelper::call('template.create', [
			'host' => 'API topology type template',
			'groups' => [['groupid' => self::$ids['tpl_groupid']]]
		])['templateids'][0];

		self::$ids['master_itemid'] = CDataHelper::call('item.create', [
			'hostid' => self::$ids['templateid'],
			'name' => 'Walk',
			'key_' => 'topo.walk',
			'type' => ITEM_TYPE_TRAPPER,
			'value_type' => ITEM_VALUE_TYPE_TEXT
		])['itemids'][0];

		$rules = [];

		foreach (self::TYPES as $topology_type) {
			$rules[] = [
				'hostid' => self::$ids['templateid'],
				'name' => 'Rule topology type '.$topology_type,
				'key_' => 'topo.rule['.$topology_type.']',
				'type' => ITEM_TYPE_TRAPPER,
				'topology_type' => $topology_type
			];
		}

		$rules[] = [
			'hostid' => self::$ids['templateid'],
			'name' => 'Dependent neighbors',
			'key_' => 'topo.dependent',
			'type' => ITEM_TYPE_DEPENDENT,
			'master_itemid' => self::$ids['master_itemid'],
			'topology_type' => ZBX_TOPOLOGY_TYPE_NEIGHBORS
		];
		$rules[] = [
			'hostid' => self::$ids['templateid'],
			'name' => 'No topology type given',
			'key_' => 'topo.notype',
			'type' => ITEM_TYPE_TRAPPER
		];

		$itemids = CDataHelper::call('discoveryrule.create', $rules)['itemids'];
		self::$ids['rules'] = array_combine(array_column($rules, 'key_'), $itemids);

		self::$ids['rule_prototypeid'] = CDataHelper::call('discoveryruleprototype.create', [
			'hostid' => self::$ids['templateid'],
			'ruleid' => self::$ids['rules']['topo.rule[0]'],
			'name' => 'Nested {#X}',
			'key_' => 'topo.nested[{#X}]',
			'type' => ITEM_TYPE_TRAPPER,
			'topology_type' => ZBX_TOPOLOGY_TYPE_LAG
		])['itemids'][0];

		self::$ids['hostid'] = CDataHelper::call('host.create', [
			'host' => 'API topology type host',
			'groups' => [['groupid' => self::$ids['groupid']]],
			'templates' => [['templateid' => self::$ids['templateid']]]
		])['hostids'][0];

		self::$ids['template2id'] = CDataHelper::call('template.create', [
			'host' => 'API topology type nested template',
			'groups' => [['groupid' => self::$ids['tpl_groupid']]],
			'templates' => [['templateid' => self::$ids['templateid']]]
		])['templateids'][0];
	}

	public static function cleanTestData(): void {
		$templateids = CDBHelper::getColumn(
			'SELECT hostid FROM hosts WHERE host LIKE '.zbx_dbstr('API topology type %').' AND status='.HOST_STATUS_TEMPLATE,
			'hostid'
		);
		$hostids = CDBHelper::getColumn(
			'SELECT hostid FROM hosts WHERE host LIKE '.zbx_dbstr('API topology type %').' AND status<>'.HOST_STATUS_TEMPLATE,
			'hostid'
		);

		if ($hostids) {
			CDataHelper::call('host.delete', $hostids);
		}

		if ($templateids) {
			CDataHelper::call('template.delete', $templateids);
		}

		CDataHelper::call('hostgroup.delete', [self::$ids['groupid']]);
		CDataHelper::call('templategroup.delete', [self::$ids['tpl_groupid']]);
	}

	private function getTypes(string $hostid): array {
		$rules = $this->call('discoveryrule.get', [
			'output' => ['key_', 'topology_type'],
			'hostids' => $hostid
		])['result'];

		return array_column($rules, 'topology_type', 'key_');
	}

	public function testDiscoveryRuleTopologyType_Create(): void {
		$types = $this->getTypes(self::$ids['templateid']);

		foreach (self::TYPES as $topology_type) {
			$this->assertEquals($topology_type, $types['topo.rule['.$topology_type.']']);
		}

		$this->assertEquals(ZBX_TOPOLOGY_TYPE_NEIGHBORS, $types['topo.dependent'], 'Dependent rule has no topology type.');
		$this->assertEquals(ZBX_TOPOLOGY_TYPE_NONE, $types['topo.notype'], 'Topology type does not default to NONE.');
	}

	public function testDiscoveryRuleTopologyType_Filter(): void {
		$rules = $this->call('discoveryrule.get', [
			'output' => ['key_'],
			'hostids' => self::$ids['templateid'],
			'filter' => ['topology_type' => ZBX_TOPOLOGY_TYPE_NEIGHBORS]
		])['result'];

		$keys = array_column($rules, 'key_');
		sort($keys);
		$this->assertSame(['topo.dependent', 'topo.rule[2]'], $keys);
	}

	public static function getInvalidCreateData(): array {
		return [
			'Invalid topology type value' => [
				'method' => 'discoveryrule.create',
				'params' => [
					'name' => 'Invalid',
					'key_' => 'topo.invalid',
					'type' => ITEM_TYPE_TRAPPER,
					'topology_type' => 9
				],
				'error' => 'Invalid parameter "/1/topology_type": value must be one of 0, 1, 2, 3, 4.'
			],
			'Item does not accept topology type' => [
				'method' => 'item.create',
				'params' => [
					'name' => 'Item',
					'key_' => 'topo.item',
					'type' => ITEM_TYPE_TRAPPER,
					'value_type' => ITEM_VALUE_TYPE_TEXT,
					'topology_type' => ZBX_TOPOLOGY_TYPE_PORTS
				],
				'error' => 'Invalid parameter "/1": unexpected parameter "topology_type".'
			],
			'Item prototype does not accept topology type' => [
				'method' => 'itemprototype.create',
				'params' => [
					'name' => 'Traffic {#IFNAME}',
					'key_' => 'topo.ifin[{#IFINDEX}]',
					'type' => ITEM_TYPE_TRAPPER,
					'value_type' => ITEM_VALUE_TYPE_UINT64,
					'topology_type' => ZBX_TOPOLOGY_TYPE_PORTS
				],
				'error' => 'Invalid parameter "/1": unexpected parameter "topology_type".'
			]
		];
	}

	/**
	 * @dataProvider getInvalidCreateData
	 */
	public function testDiscoveryRuleTopologyType_InvalidCreate(string $method, array $params, string $error): void {
		$params['hostid'] = self::$ids['templateid'];

		if ($method === 'itemprototype.create') {
			$params['ruleid'] = self::$ids['rules']['topo.rule[1]'];
		}

		$this->call($method, $params, $error);
	}

	public function testDiscoveryRuleTopologyType_ItemOutput(): void {
		$items = $this->call('item.get', [
			'output' => 'extend',
			'itemids' => self::$ids['master_itemid']
		])['result'];

		$this->assertArrayNotHasKey('topology_type', $items[0]);
	}

	public function testDiscoveryRuleTopologyType_RulePrototype(): void {
		$prototypes = $this->call('discoveryruleprototype.get', [
			'output' => ['topology_type'],
			'itemids' => self::$ids['rule_prototypeid']
		])['result'];

		$this->assertEquals(ZBX_TOPOLOGY_TYPE_LAG, $prototypes[0]['topology_type']);
	}

	public function testDiscoveryRuleTopologyType_Inheritance(): void {
		foreach ([self::$ids['hostid'], self::$ids['template2id']] as $hostid) {
			$types = $this->getTypes($hostid);

			foreach (self::TYPES as $topology_type) {
				$this->assertEquals($topology_type, $types['topo.rule['.$topology_type.']']);
			}

			$this->assertEquals(ZBX_TOPOLOGY_TYPE_NEIGHBORS, $types['topo.dependent']);
		}

		$prototypes = $this->call('discoveryruleprototype.get', [
			'output' => ['topology_type'],
			'hostids' => self::$ids['hostid']
		])['result'];

		$this->assertEquals(ZBX_TOPOLOGY_TYPE_LAG, $prototypes[0]['topology_type']);
	}

	public function testDiscoveryRuleTopologyType_InheritedReadonly(): void {
		$itemid = $this->getHostRuleId('topo.rule[1]');

		$this->call('discoveryrule.update', ['itemid' => $itemid, 'topology_type' => ZBX_TOPOLOGY_TYPE_NEIGHBORS],
			'Invalid parameter "/1": cannot update readonly parameter "topology_type" of inherited object.'
		);

		$this->call('discoveryrule.update', ['itemid' => $itemid, 'description' => 'Editable on host']);
	}

	/**
	 * @depends testDiscoveryRuleTopologyType_InheritedReadonly
	 */
	public function testDiscoveryRuleTopologyType_Propagation(): void {
		$this->call('discoveryrule.update', [
			'itemid' => self::$ids['rules']['topo.rule[1]'],
			'topology_type' => ZBX_TOPOLOGY_TYPE_LEARNED_MACS
		]);

		$this->assertEquals(ZBX_TOPOLOGY_TYPE_LEARNED_MACS, $this->getTypes(self::$ids['hostid'])['topo.rule[1]']);
		$this->assertEquals(ZBX_TOPOLOGY_TYPE_LEARNED_MACS, $this->getTypes(self::$ids['template2id'])['topo.rule[1]']);

		$audit = $this->call('auditlog.get', [
			'output' => ['details'],
			'filter' => [
				'resourceid' => self::$ids['rules']['topo.rule[1]'],
				'action' => 1 /* CAudit::ACTION_UPDATE */
			],
			'sortfield' => 'clock',
			'sortorder' => ZBX_SORT_DOWN,
			'limit' => 1
		])['result'];

		$this->assertSame(['update', (string) ZBX_TOPOLOGY_TYPE_LEARNED_MACS, (string) ZBX_TOPOLOGY_TYPE_PORTS],
			json_decode($audit[0]['details'], true)['discoveryrule.topology_type']
		);
	}

	private function insertSnapshot(string $itemid, string $hostid, int $topology_type): void {
		DBexecute('INSERT INTO topo_lld_snapshot (itemid,hostid,topology_type,clock,rows_hash,rows_json,rows_total,rows_valid)'.
			' VALUES ('.zbx_dbstr($itemid).','.zbx_dbstr($hostid).','.$topology_type.',1,'.zbx_dbstr('hash').','.zbx_dbstr('[]').',0,0)'
		);
	}

	private function hasSnapshot(string $itemid): bool {
		return CDBHelper::getCount('SELECT NULL FROM topo_lld_snapshot WHERE itemid='.zbx_dbstr($itemid)) == 1;
	}

	/**
	 * A snapshot must never be read under a topology type it was not written for: changing a rule's topology type drops its snapshot,
	 * and the snapshots of the inherited rules that change with it. Other updates keep it.
	 *
	 * @depends testDiscoveryRuleTopologyType_Propagation
	 */
	public function testDiscoveryRuleTopologyType_SnapshotDroppedOnTypeChange(): void {
		$template_rule = self::$ids['rules']['topo.rule[2]'];
		$nested_rule = $this->call('discoveryrule.get', [
			'output' => ['itemid'],
			'hostids' => self::$ids['template2id'],
			'filter' => ['key_' => 'topo.rule[2]']
		])['result'][0]['itemid'];

		$this->insertSnapshot($template_rule, self::$ids['templateid'], ZBX_TOPOLOGY_TYPE_NEIGHBORS);
		$this->insertSnapshot($nested_rule, self::$ids['template2id'], ZBX_TOPOLOGY_TYPE_NEIGHBORS);

		$this->call('discoveryrule.update', ['itemid' => $template_rule, 'description' => 'not a topology type change']);
		$this->assertTrue($this->hasSnapshot($template_rule), 'A non-topology type update dropped the snapshot.');
		$this->assertTrue($this->hasSnapshot($nested_rule));

		$this->call('discoveryrule.update', ['itemid' => $template_rule, 'topology_type' => ZBX_TOPOLOGY_TYPE_PORTS]);
		$this->assertFalse($this->hasSnapshot($template_rule), 'Topology type change kept the rule\'s snapshot.');
		$this->assertFalse($this->hasSnapshot($nested_rule), 'Topology type change kept the inherited rule\'s snapshot.');
	}

	public function testDiscoveryRuleTopologyType_ExportImport(): void {
		$yaml = $this->call('configuration.export', [
			'format' => 'yaml',
			'options' => ['templates' => [self::$ids['templateid']]]
		])['result'];

		preg_match_all('/^\s*topology_type: (\S+)$/m', $yaml, $matches);
		$exported = $matches[1];
		sort($exported);

		// NONE rules are omitted; rule[1] may already be LEARNED_MACS if the propagation test ran first.
		$this->assertNotContains('NONE', $exported);
		$this->assertContains('LAG', $exported);
		$this->assertCount(6, $exported);

		$yaml = str_replace("'API topology type template'", "'API topology type imported'", $yaml);
		$yaml = preg_replace_callback('/uuid: [0-9a-f]{32}/', static fn() => 'uuid: '.generateUuidV4(), $yaml);

		$rules = [
			'template_groups' => ['createMissing' => true],
			'templates' => ['createMissing' => true],
			'items' => ['createMissing' => true],
			'discoveryRules' => ['createMissing' => true]
		];

		$bad_yaml = preg_replace('/topology_type: LAG/', 'topology_type: BOGUS', $yaml, 1);
		$this->call('configuration.import', ['format' => 'yaml', 'source' => $bad_yaml, 'rules' => $rules],
			'Invalid tag "/zabbix_export/templates/template(1)/discovery_rules/discovery_rule(2)/topology_type":'.
				' unexpected constant "BOGUS".'
		);

		$this->call('configuration.import', ['format' => 'yaml', 'source' => $yaml, 'rules' => $rules]);

		$imported = $this->call('template.get', [
			'output' => ['templateid'],
			'filter' => ['host' => 'API topology type imported']
		])['result'];

		$this->assertEquals($this->getTypes(self::$ids['templateid']), $this->getTypes($imported[0]['templateid']));
	}

	/**
	 * @depends testDiscoveryRuleTopologyType_Propagation
	 */
	public function testDiscoveryRuleTopologyType_Unlink(): void {
		$this->call('host.massremove', [
			'hostids' => [self::$ids['hostid']],
			'templateids' => [self::$ids['templateid']]
		]);

		$rules = $this->call('discoveryrule.get', [
			'output' => ['itemid', 'templateid', 'topology_type'],
			'hostids' => self::$ids['hostid'],
			'filter' => ['key_' => 'topo.rule[3]']
		])['result'];

		$this->assertEquals(0, $rules[0]['templateid']);
		$this->assertEquals(ZBX_TOPOLOGY_TYPE_LEARNED_MACS, $rules[0]['topology_type']);

		$this->call('discoveryrule.update', ['itemid' => $rules[0]['itemid'], 'topology_type' => ZBX_TOPOLOGY_TYPE_LAG]);
	}

	/**
	 * Topology types that refuse "Discard unchanged with heartbeat": topology type => the text of the refusal (LLD spec 2.3). PORTS accepts it (FR-B D-23).
	 */
	private const DISCARD_REFUSED = [
		ZBX_TOPOLOGY_TYPE_NEIGHBORS => ['Neighbors', 'every value must confirm the neighbors'],
		ZBX_TOPOLOGY_TYPE_LEARNED_MACS => ['Learned MACs', 'every value must confirm the learned MACs'],
		ZBX_TOPOLOGY_TYPE_LAG => ['LAG membership', 'every value must confirm the LAG membership']
	];

	private static function discardError(int $topology_type, string $path): string {
		[$name, $reason] = self::DISCARD_REFUSED[$topology_type];

		return 'Invalid parameter "'.$path.'": "Discard unchanged" is not allowed for the topology type "'.$name.'": '.$reason.'.';
	}

	private static function heartbeatStep(): array {
		return [
			'type' => ZBX_PREPROC_THROTTLE_TIMED_VALUE,
			'params' => '1h',
			'error_handler' => ZBX_PREPROC_FAIL_DEFAULT,
			'error_handler_params' => ''
		];
	}

	private function createDiscardTemplate(string $name): string {
		return CDataHelper::call('template.create', [
			'host' => 'API topology type '.$name,
			'groups' => [['groupid' => self::$ids['tpl_groupid']]]
		])['templateids'][0];
	}

	/**
	 * "Discard unchanged with heartbeat" stops the snapshot clock, so a rule with the topology type Neighbors, Learned MACs or LAG membership must not have it;
	 * a Ports rule may. The check runs on the resulting state, in every direction: create, topology type change, preprocessing change.
	 */
	public function testDiscoveryRuleTopologyType_DiscardUnchangedCreate(): void {
		$hostid = $this->createDiscardTemplate('discard create');

		foreach ([ZBX_TOPOLOGY_TYPE_PORTS, ZBX_TOPOLOGY_TYPE_NEIGHBORS, ZBX_TOPOLOGY_TYPE_LEARNED_MACS, ZBX_TOPOLOGY_TYPE_LAG] as $topology_type) {
			$rule = ['hostid' => $hostid, 'name' => 'Discard '.$topology_type, 'key_' => 'topo.discard.create'.$topology_type, 'type' => ITEM_TYPE_TRAPPER,
				'topology_type' => $topology_type, 'preprocessing' => [self::heartbeatStep()]
			];

			if (array_key_exists($topology_type, self::DISCARD_REFUSED)) {
				$this->call('discoveryrule.create', $rule, self::discardError($topology_type, '/1/preprocessing'));
			}
			else {
				$this->call('discoveryrule.create', $rule);		// Ports keeps the step
			}
		}

		// Without a topology type the step stays available.
		$this->call('discoveryrule.create', ['hostid' => $hostid, 'name' => 'No topology type', 'key_' => 'topo.discard.notype', 'type' => ITEM_TYPE_TRAPPER,
			'preprocessing' => [self::heartbeatStep()]
		]);
	}

	public function testDiscoveryRuleTopologyType_DiscardUnchangedUpdate(): void {
		$hostid = $this->createDiscardTemplate('discard update');

		foreach (self::DISCARD_REFUSED as $topology_type => $text) {
			$with_step = $this->call('discoveryrule.create', [
				'hostid' => $hostid, 'name' => 'Has step '.$topology_type, 'key_' => 'topo.discard.step'.$topology_type, 'type' => ITEM_TYPE_TRAPPER,
				'preprocessing' => [self::heartbeatStep()]
			])['result']['itemids'][0];
			$with_type = $this->call('discoveryrule.create', [
				'hostid' => $hostid, 'name' => 'Has topology type '.$topology_type, 'key_' => 'topo.discard.type'.$topology_type, 'type' => ITEM_TYPE_TRAPPER,
				'topology_type' => $topology_type
			])['result']['itemids'][0];

			// The step is already stored; only the topology type arrives: a refusing topology type fails, Ports does not.
			$this->call('discoveryrule.update', ['itemid' => $with_step, 'topology_type' => $topology_type], self::discardError($topology_type, '/1/topology_type'));
			$this->call('discoveryrule.update', ['itemid' => $with_step, 'topology_type' => ZBX_TOPOLOGY_TYPE_PORTS]);

			// The topology type is already stored; only the step arrives.
			$this->call('discoveryrule.update', ['itemid' => $with_type, 'preprocessing' => [self::heartbeatStep()]],
				self::discardError($topology_type, '/1/preprocessing')
			);

			// Unrelated updates of a rule that is valid stay possible.
			$this->call('discoveryrule.update', ['itemid' => $with_type, 'description' => 'still fine']);
			$this->call('discoveryrule.update', ['itemid' => $with_step, 'description' => 'still fine']);

			// Removing the topology type and setting the step, or removing the step and setting the topology type, in one call is valid.
			$this->call('discoveryrule.update', [
				'itemid' => $with_type, 'topology_type' => ZBX_TOPOLOGY_TYPE_NONE, 'preprocessing' => [self::heartbeatStep()]
			]);
			$this->call('discoveryrule.update', ['itemid' => $with_step, 'topology_type' => $topology_type, 'preprocessing' => []]);

			// A topology type change with the step present: Ports to a refusing topology type fails.
			$this->call('discoveryrule.update', ['itemid' => $with_step, 'topology_type' => ZBX_TOPOLOGY_TYPE_PORTS, 'preprocessing' => [self::heartbeatStep()]]);
			$this->call('discoveryrule.update', ['itemid' => $with_step, 'topology_type' => $topology_type], self::discardError($topology_type, '/1/topology_type'));
		}
	}

	public function testDiscoveryRuleTopologyType_DiscardUnchangedPrototype(): void {
		$hostid = $this->createDiscardTemplate('discard prototype');

		$parent = $this->call('discoveryrule.create', [
			'hostid' => $hostid, 'name' => 'Parent', 'key_' => 'topo.discard.parent', 'type' => ITEM_TYPE_TRAPPER
		])['result']['itemids'][0];
		$prototype = ['hostid' => $hostid, 'ruleid' => $parent, 'type' => ITEM_TYPE_TRAPPER];

		foreach ([ZBX_TOPOLOGY_TYPE_PORTS, ZBX_TOPOLOGY_TYPE_NEIGHBORS, ZBX_TOPOLOGY_TYPE_LEARNED_MACS, ZBX_TOPOLOGY_TYPE_LAG] as $topology_type) {
			$create = $prototype + [
				'name' => 'Proto '.$topology_type.' {#N}', 'key_' => 'topo.discard.proto'.$topology_type.'[{#N}]',
				'topology_type' => $topology_type, 'preprocessing' => [self::heartbeatStep()]
			];

			if (array_key_exists($topology_type, self::DISCARD_REFUSED)) {
				$this->call('discoveryruleprototype.create', $create, self::discardError($topology_type, '/1/preprocessing'));
			}
			else {
				$this->call('discoveryruleprototype.create', $create);
			}

			$plain = $this->call('discoveryruleprototype.create', $prototype + [
				'name' => 'Proto plain '.$topology_type.' {#N}', 'key_' => 'topo.discard.plain'.$topology_type.'[{#N}]',
				'preprocessing' => [self::heartbeatStep()]
			])['result']['itemids'][0];

			if (array_key_exists($topology_type, self::DISCARD_REFUSED)) {
				$this->call('discoveryruleprototype.update', ['itemid' => $plain, 'topology_type' => $topology_type],
					self::discardError($topology_type, '/1/topology_type')
				);
			}
			else {
				$this->call('discoveryruleprototype.update', ['itemid' => $plain, 'topology_type' => $topology_type]);
			}
		}
	}

	/**
	 * Linking goes through the inheritance code, not through validateCreate/validateUpdate. Template linking overwrites the host rule's preprocessing
	 * with the template's, so the template-level check covers inherited rules: the host rule ends with the template's steps, and a refusing topology type never
	 * ends together with the step.
	 */
	public function testDiscoveryRuleTopologyType_DiscardUnchangedOnLink(): void {
		foreach ([ZBX_TOPOLOGY_TYPE_PORTS, ZBX_TOPOLOGY_TYPE_NEIGHBORS, ZBX_TOPOLOGY_TYPE_LEARNED_MACS, ZBX_TOPOLOGY_TYPE_LAG] as $topology_type) {
			$templateid = $this->createDiscardTemplate('discard link template '.$topology_type);
			$this->call('discoveryrule.create', [
				'hostid' => $templateid, 'name' => 'Linked', 'key_' => 'topo.discard.link', 'type' => ITEM_TYPE_TRAPPER,
				'topology_type' => $topology_type
			]);

			$hostid = $this->call('host.create', [
				'host' => 'API topology type discard link host '.$topology_type,
				'groups' => [['groupid' => self::$ids['groupid']]]
			])['result']['hostids'][0];
			$this->call('discoveryrule.create', [
				'hostid' => $hostid, 'name' => 'Linked', 'key_' => 'topo.discard.link', 'type' => ITEM_TYPE_TRAPPER,
				'preprocessing' => [self::heartbeatStep()]
			]);

			$this->call('host.update', ['hostid' => $hostid, 'templates' => [['templateid' => $templateid]]]);

			$rule = $this->call('discoveryrule.get', [
				'output' => ['itemid', 'topology_type'],
				'hostids' => $hostid,
				'filter' => ['key_' => 'topo.discard.link']
			])['result'][0];

			$steps = CDBHelper::getColumn(
				'SELECT type FROM item_preproc WHERE itemid='.zbx_dbstr($rule['itemid']), 'type'
			);

			$this->assertEquals($topology_type, $rule['topology_type']);
			$this->assertFalse(array_key_exists($topology_type, self::DISCARD_REFUSED)
					&& array_intersect($steps, [ZBX_PREPROC_THROTTLE_VALUE, ZBX_PREPROC_THROTTLE_TIMED_VALUE]) !== [],
				'A linked rule ended up with the topology type '.$topology_type.' and a "Discard unchanged" step.'
			);
		}
	}

	private function getHostRuleId(string $key): string {
		return $this->call('discoveryrule.get', [
			'output' => ['itemid'],
			'hostids' => self::$ids['hostid'],
			'filter' => ['key_' => $key]
		])['result'][0]['itemid'];
	}
}
