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
 * Discovery rule "topology_role" property: API, inheritance, export/import and audit.
 *
 * @onBefore prepareTestData
 * @onAfter  cleanTestData
 */
class testDiscoveryRuleTopologyRole extends CAPITest {

	private const ROLES = [
		ZBX_TOPOLOGY_ROLE_NONE, ZBX_TOPOLOGY_ROLE_PORTS, ZBX_TOPOLOGY_ROLE_NEIGHBORS, ZBX_TOPOLOGY_ROLE_LEARNED_MACS,
		ZBX_TOPOLOGY_ROLE_LAG
	];

	private static array $ids = [];

	public static function prepareTestData(): void {
		self::$ids['groupid'] = CDataHelper::call('hostgroup.create', [
			'name' => 'API topology role hosts'
		])['groupids'][0];
		self::$ids['tpl_groupid'] = CDataHelper::call('templategroup.create', [
			'name' => 'API topology role templates'
		])['groupids'][0];

		self::$ids['templateid'] = CDataHelper::call('template.create', [
			'host' => 'API topology role template',
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

		foreach (self::ROLES as $role) {
			$rules[] = [
				'hostid' => self::$ids['templateid'],
				'name' => 'Rule role '.$role,
				'key_' => 'topo.rule['.$role.']',
				'type' => ITEM_TYPE_TRAPPER,
				'topology_role' => $role
			];
		}

		$rules[] = [
			'hostid' => self::$ids['templateid'],
			'name' => 'Dependent neighbors',
			'key_' => 'topo.dependent',
			'type' => ITEM_TYPE_DEPENDENT,
			'master_itemid' => self::$ids['master_itemid'],
			'topology_role' => ZBX_TOPOLOGY_ROLE_NEIGHBORS
		];
		$rules[] = [
			'hostid' => self::$ids['templateid'],
			'name' => 'No role given',
			'key_' => 'topo.norole',
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
			'topology_role' => ZBX_TOPOLOGY_ROLE_LAG
		])['itemids'][0];

		self::$ids['hostid'] = CDataHelper::call('host.create', [
			'host' => 'API topology role host',
			'groups' => [['groupid' => self::$ids['groupid']]],
			'templates' => [['templateid' => self::$ids['templateid']]]
		])['hostids'][0];

		self::$ids['template2id'] = CDataHelper::call('template.create', [
			'host' => 'API topology role nested template',
			'groups' => [['groupid' => self::$ids['tpl_groupid']]],
			'templates' => [['templateid' => self::$ids['templateid']]]
		])['templateids'][0];
	}

	public static function cleanTestData(): void {
		$templateids = CDBHelper::getColumn(
			'SELECT hostid FROM hosts WHERE host LIKE '.zbx_dbstr('API topology role %').' AND status='.HOST_STATUS_TEMPLATE,
			'hostid'
		);
		$hostids = CDBHelper::getColumn(
			'SELECT hostid FROM hosts WHERE host LIKE '.zbx_dbstr('API topology role %').' AND status<>'.HOST_STATUS_TEMPLATE,
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

	private function getRoles(string $hostid): array {
		$rules = $this->call('discoveryrule.get', [
			'output' => ['key_', 'topology_role'],
			'hostids' => $hostid
		])['result'];

		return array_column($rules, 'topology_role', 'key_');
	}

	public function testDiscoveryRuleTopologyRole_Create(): void {
		$roles = $this->getRoles(self::$ids['templateid']);

		foreach (self::ROLES as $role) {
			$this->assertEquals($role, $roles['topo.rule['.$role.']']);
		}

		$this->assertEquals(ZBX_TOPOLOGY_ROLE_NEIGHBORS, $roles['topo.dependent'], 'Dependent rule has no role.');
		$this->assertEquals(ZBX_TOPOLOGY_ROLE_NONE, $roles['topo.norole'], 'Role does not default to NONE.');
	}

	public function testDiscoveryRuleTopologyRole_Filter(): void {
		$rules = $this->call('discoveryrule.get', [
			'output' => ['key_'],
			'hostids' => self::$ids['templateid'],
			'filter' => ['topology_role' => ZBX_TOPOLOGY_ROLE_NEIGHBORS]
		])['result'];

		$keys = array_column($rules, 'key_');
		sort($keys);
		$this->assertSame(['topo.dependent', 'topo.rule[2]'], $keys);
	}

	public static function getInvalidCreateData(): array {
		return [
			'Invalid role value' => [
				'method' => 'discoveryrule.create',
				'params' => [
					'name' => 'Invalid',
					'key_' => 'topo.invalid',
					'type' => ITEM_TYPE_TRAPPER,
					'topology_role' => 9
				],
				'error' => 'Invalid parameter "/1/topology_role": value must be one of 0, 1, 2, 3, 4.'
			],
			'Item does not accept role' => [
				'method' => 'item.create',
				'params' => [
					'name' => 'Item',
					'key_' => 'topo.item',
					'type' => ITEM_TYPE_TRAPPER,
					'value_type' => ITEM_VALUE_TYPE_TEXT,
					'topology_role' => ZBX_TOPOLOGY_ROLE_PORTS
				],
				'error' => 'Invalid parameter "/1": unexpected parameter "topology_role".'
			],
			'Item prototype does not accept role' => [
				'method' => 'itemprototype.create',
				'params' => [
					'name' => 'Traffic {#IFNAME}',
					'key_' => 'topo.ifin[{#IFINDEX}]',
					'type' => ITEM_TYPE_TRAPPER,
					'value_type' => ITEM_VALUE_TYPE_UINT64,
					'topology_role' => ZBX_TOPOLOGY_ROLE_PORTS
				],
				'error' => 'Invalid parameter "/1": unexpected parameter "topology_role".'
			]
		];
	}

	/**
	 * @dataProvider getInvalidCreateData
	 */
	public function testDiscoveryRuleTopologyRole_InvalidCreate(string $method, array $params, string $error): void {
		$params['hostid'] = self::$ids['templateid'];

		if ($method === 'itemprototype.create') {
			$params['ruleid'] = self::$ids['rules']['topo.rule[1]'];
		}

		$this->call($method, $params, $error);
	}

	public function testDiscoveryRuleTopologyRole_ItemOutput(): void {
		$items = $this->call('item.get', [
			'output' => 'extend',
			'itemids' => self::$ids['master_itemid']
		])['result'];

		$this->assertArrayNotHasKey('topology_role', $items[0]);
	}

	public function testDiscoveryRuleTopologyRole_RulePrototype(): void {
		$prototypes = $this->call('discoveryruleprototype.get', [
			'output' => ['topology_role'],
			'itemids' => self::$ids['rule_prototypeid']
		])['result'];

		$this->assertEquals(ZBX_TOPOLOGY_ROLE_LAG, $prototypes[0]['topology_role']);
	}

	public function testDiscoveryRuleTopologyRole_Inheritance(): void {
		foreach ([self::$ids['hostid'], self::$ids['template2id']] as $hostid) {
			$roles = $this->getRoles($hostid);

			foreach (self::ROLES as $role) {
				$this->assertEquals($role, $roles['topo.rule['.$role.']']);
			}

			$this->assertEquals(ZBX_TOPOLOGY_ROLE_NEIGHBORS, $roles['topo.dependent']);
		}

		$prototypes = $this->call('discoveryruleprototype.get', [
			'output' => ['topology_role'],
			'hostids' => self::$ids['hostid']
		])['result'];

		$this->assertEquals(ZBX_TOPOLOGY_ROLE_LAG, $prototypes[0]['topology_role']);
	}

	public function testDiscoveryRuleTopologyRole_InheritedReadonly(): void {
		$itemid = $this->getHostRuleId('topo.rule[1]');

		$this->call('discoveryrule.update', ['itemid' => $itemid, 'topology_role' => ZBX_TOPOLOGY_ROLE_NEIGHBORS],
			'Invalid parameter "/1": cannot update readonly parameter "topology_role" of inherited object.'
		);

		$this->call('discoveryrule.update', ['itemid' => $itemid, 'description' => 'Editable on host']);
	}

	/**
	 * @depends testDiscoveryRuleTopologyRole_InheritedReadonly
	 */
	public function testDiscoveryRuleTopologyRole_Propagation(): void {
		$this->call('discoveryrule.update', [
			'itemid' => self::$ids['rules']['topo.rule[1]'],
			'topology_role' => ZBX_TOPOLOGY_ROLE_LEARNED_MACS
		]);

		$this->assertEquals(ZBX_TOPOLOGY_ROLE_LEARNED_MACS, $this->getRoles(self::$ids['hostid'])['topo.rule[1]']);
		$this->assertEquals(ZBX_TOPOLOGY_ROLE_LEARNED_MACS, $this->getRoles(self::$ids['template2id'])['topo.rule[1]']);

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

		$this->assertSame(['update', (string) ZBX_TOPOLOGY_ROLE_LEARNED_MACS, (string) ZBX_TOPOLOGY_ROLE_PORTS],
			json_decode($audit[0]['details'], true)['discoveryrule.topology_role']
		);
	}

	private function insertSnapshot(string $itemid, string $hostid, int $role): void {
		DBexecute('INSERT INTO topo_lld_snapshot (itemid,hostid,role,clock,rows_hash,rows_json,rows_total,rows_valid)'.
			' VALUES ('.zbx_dbstr($itemid).','.zbx_dbstr($hostid).','.$role.',1,'.zbx_dbstr('hash').','.zbx_dbstr('[]').',0,0)'
		);
	}

	private function hasSnapshot(string $itemid): bool {
		return CDBHelper::getCount('SELECT NULL FROM topo_lld_snapshot WHERE itemid='.zbx_dbstr($itemid)) == 1;
	}

	/**
	 * A snapshot must never be read under a role it was not written for: changing a rule's role drops its snapshot,
	 * and the snapshots of the inherited rules that change with it. Other updates keep it.
	 *
	 * @depends testDiscoveryRuleTopologyRole_Propagation
	 */
	public function testDiscoveryRuleTopologyRole_SnapshotDroppedOnRoleChange(): void {
		$template_rule = self::$ids['rules']['topo.rule[2]'];
		$nested_rule = $this->call('discoveryrule.get', [
			'output' => ['itemid'],
			'hostids' => self::$ids['template2id'],
			'filter' => ['key_' => 'topo.rule[2]']
		])['result'][0]['itemid'];

		$this->insertSnapshot($template_rule, self::$ids['templateid'], ZBX_TOPOLOGY_ROLE_NEIGHBORS);
		$this->insertSnapshot($nested_rule, self::$ids['template2id'], ZBX_TOPOLOGY_ROLE_NEIGHBORS);

		$this->call('discoveryrule.update', ['itemid' => $template_rule, 'description' => 'not a role change']);
		$this->assertTrue($this->hasSnapshot($template_rule), 'A non-role update dropped the snapshot.');
		$this->assertTrue($this->hasSnapshot($nested_rule));

		$this->call('discoveryrule.update', ['itemid' => $template_rule, 'topology_role' => ZBX_TOPOLOGY_ROLE_PORTS]);
		$this->assertFalse($this->hasSnapshot($template_rule), 'Role change kept the rule\'s snapshot.');
		$this->assertFalse($this->hasSnapshot($nested_rule), 'Role change kept the inherited rule\'s snapshot.');
	}

	public function testDiscoveryRuleTopologyRole_ExportImport(): void {
		$yaml = $this->call('configuration.export', [
			'format' => 'yaml',
			'options' => ['templates' => [self::$ids['templateid']]]
		])['result'];

		preg_match_all('/^\s*topology_role: (\S+)$/m', $yaml, $matches);
		$exported = $matches[1];
		sort($exported);

		// NONE rules are omitted; rule[1] may already be LEARNED_MACS if the propagation test ran first.
		$this->assertNotContains('NONE', $exported);
		$this->assertContains('LAG', $exported);
		$this->assertCount(6, $exported);

		$yaml = str_replace("'API topology role template'", "'API topology role imported'", $yaml);
		$yaml = preg_replace_callback('/uuid: [0-9a-f]{32}/', static fn() => 'uuid: '.generateUuidV4(), $yaml);

		$rules = [
			'template_groups' => ['createMissing' => true],
			'templates' => ['createMissing' => true],
			'items' => ['createMissing' => true],
			'discoveryRules' => ['createMissing' => true]
		];

		$bad_yaml = preg_replace('/topology_role: LAG/', 'topology_role: BOGUS', $yaml, 1);
		$this->call('configuration.import', ['format' => 'yaml', 'source' => $bad_yaml, 'rules' => $rules],
			'Invalid tag "/zabbix_export/templates/template(1)/discovery_rules/discovery_rule(2)/topology_role":'.
				' unexpected constant "BOGUS".'
		);

		$this->call('configuration.import', ['format' => 'yaml', 'source' => $yaml, 'rules' => $rules]);

		$imported = $this->call('template.get', [
			'output' => ['templateid'],
			'filter' => ['host' => 'API topology role imported']
		])['result'];

		$this->assertEquals($this->getRoles(self::$ids['templateid']), $this->getRoles($imported[0]['templateid']));
	}

	/**
	 * @depends testDiscoveryRuleTopologyRole_Propagation
	 */
	public function testDiscoveryRuleTopologyRole_Unlink(): void {
		$this->call('host.massremove', [
			'hostids' => [self::$ids['hostid']],
			'templateids' => [self::$ids['templateid']]
		]);

		$rules = $this->call('discoveryrule.get', [
			'output' => ['itemid', 'templateid', 'topology_role'],
			'hostids' => self::$ids['hostid'],
			'filter' => ['key_' => 'topo.rule[3]']
		])['result'];

		$this->assertEquals(0, $rules[0]['templateid']);
		$this->assertEquals(ZBX_TOPOLOGY_ROLE_LEARNED_MACS, $rules[0]['topology_role']);

		$this->call('discoveryrule.update', ['itemid' => $rules[0]['itemid'], 'topology_role' => ZBX_TOPOLOGY_ROLE_LAG]);
	}

	private function getHostRuleId(string $key): string {
		return $this->call('discoveryrule.get', [
			'output' => ['itemid'],
			'hostids' => self::$ids['hostid'],
			'filter' => ['key_' => $key]
		])['result'][0]['itemid'];
	}
}
