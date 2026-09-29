<?php declare(strict_types = 0);
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


/**
 * Topology roles of discovery rules and the LLD macro contract of each role.
 *
 * Single source of truth for the UI hint, the Test dialog contract check and export strings. The server-side
 * processing of role rows (C) must stay in sync with the data below.
 */
class CTopologyRole {

	/**
	 * Macro that may stand in for {#IFINDEX}, only for roles listed in $snmpindex_fallback.
	 */
	public const SNMPINDEX_MACRO = '{#SNMPINDEX}';

	/**
	 * Role definitions:
	 *   export   - export/import string;
	 *   required - macros that must be present and non-empty;
	 *   one_of   - groups of macros, at least one macro of each group must be present and non-empty;
	 *   optional - macros with a defined meaning that are not required.
	 */
	private const ROLES = [
		ZBX_TOPOLOGY_ROLE_PORTS => [
			'export' => 'PORTS',
			'required' => ['{#IFINDEX}'],
			'one_of' => [],
			'optional' => ['{#IFNAME}', '{#IFTYPE}', '{#IFMAC}', '{#IFADMINSTATUS}', '{#IFOPERSTATUS}', '{#IFSPEED}',
				'{#LOC_CHASSIS}'
			]
		],
		ZBX_TOPOLOGY_ROLE_NEIGHBORS => [
			'export' => 'NEIGHBORS',
			'required' => ['{#IFINDEX}'],
			'one_of' => [['{#REM_CHASSIS}', '{#REM_MGMT_IP}', '{#REM_SYSNAME}']],
			'optional' => ['{#REM_CHASSIS_TYPE}', '{#REM_PORT}', '{#REM_PORT_TYPE}', '{#REM_PORT_DESC}', '{#IFNAME}',
				'{#LOC_CHASSIS}', '{#SOURCE}'
			]
		],
		ZBX_TOPOLOGY_ROLE_LEARNED_MACS => [
			'export' => 'LEARNED_MACS',
			'required' => ['{#IFINDEX}'],
			'one_of' => [['{#MAC}', '{#PORT_MAC_COUNT}']],
			'optional' => ['{#VLAN}']
		],
		ZBX_TOPOLOGY_ROLE_LAG => [
			'export' => 'LAG',
			'required' => ['{#IFINDEX}', '{#LAG_IFINDEX}'],
			'one_of' => [],
			'optional' => []
		]
	];

	/**
	 * Roles for which {#SNMPINDEX} is accepted in place of a missing {#IFINDEX}. Stock IF-MIB interface discovery
	 * rules emit {#SNMPINDEX} = ifIndex; for other roles {#SNMPINDEX} is a different table index.
	 */
	private const SNMPINDEX_FALLBACK = [ZBX_TOPOLOGY_ROLE_PORTS];

	public static function getRoles(): array {
		return array_merge([ZBX_TOPOLOGY_ROLE_NONE], array_keys(self::ROLES));
	}

	public static function getLabels(): array {
		return [
			ZBX_TOPOLOGY_ROLE_NONE => _('None'),
			ZBX_TOPOLOGY_ROLE_PORTS => _('Ports'),
			ZBX_TOPOLOGY_ROLE_NEIGHBORS => _('Neighbors'),
			ZBX_TOPOLOGY_ROLE_LEARNED_MACS => _('Learned MACs'),
			ZBX_TOPOLOGY_ROLE_LAG => _('LAG membership')
		];
	}

	public static function getLabel(int $role): string {
		return self::getLabels()[$role] ?? _('Unknown');
	}

	/**
	 * Export strings indexed by role value, including NONE.
	 */
	public static function getExportStrings(): array {
		return [ZBX_TOPOLOGY_ROLE_NONE => 'NONE'] + array_map(static fn(array $role) => $role['export'], self::ROLES);
	}

	/**
	 * Contract of a role, or null for NONE and unknown roles.
	 *
	 * @return array|null  ['required' => [], 'one_of' => [[]], 'optional' => [], 'snmpindex_fallback' => bool]
	 */
	public static function getContract(int $role): ?array {
		if (!array_key_exists($role, self::ROLES)) {
			return null;
		}

		return [
			'required' => self::ROLES[$role]['required'],
			'one_of' => self::ROLES[$role]['one_of'],
			'optional' => self::ROLES[$role]['optional'],
			'snmpindex_fallback' => in_array($role, self::SNMPINDEX_FALLBACK)
		];
	}

	/**
	 * Check LLD rows against the contract of a role.
	 *
	 * @param int   $role
	 * @param array $rows          Decoded LLD rows.
	 * @param array $path_macros   Macros defined on the rule's LLD macros tab; treated as available, not evaluated.
	 *
	 * @return array  ['rows' => int, 'passed' => int, 'failed' => int, 'snmpindex_fallback_rows' => int,
	 *                 'path_macros' => [], 'failures' => [<requirement> => [<row index>, ...]]]
	 */
	public static function checkRows(int $role, array $rows, array $path_macros): array {
		$contract = self::getContract($role);

		$result = [
			'rows' => count($rows),
			'passed' => 0,
			'failed' => 0,
			'snmpindex_fallback_rows' => 0,
			'path_macros' => [],
			'failures' => []
		];

		if ($contract === null) {
			return $result;
		}

		$path_macros = array_fill_keys($path_macros, true);
		$contract_macros = array_merge($contract['required'], $contract['optional'], ...$contract['one_of']);
		$result['path_macros'] = array_values(array_intersect($contract_macros, array_keys($path_macros)));

		foreach (array_values($rows) as $index => $row) {
			$row = is_array($row) ? $row : [];
			$is_available = static fn(string $macro): bool => array_key_exists($macro, $path_macros)
				|| (array_key_exists($macro, $row) && is_scalar($row[$macro]) && (string) $row[$macro] !== '');

			$missing = [];

			foreach ($contract['required'] as $macro) {
				if ($is_available($macro)) {
					continue;
				}

				if ($macro === '{#IFINDEX}' && $contract['snmpindex_fallback'] && $is_available(self::SNMPINDEX_MACRO)) {
					$result['snmpindex_fallback_rows']++;
					continue;
				}

				$missing[] = $macro;
			}

			foreach ($contract['one_of'] as $group) {
				if (!array_filter($group, $is_available)) {
					$missing[] = implode(' | ', $group);
				}
			}

			if ($missing) {
				$result['failed']++;

				foreach ($missing as $requirement) {
					$result['failures'][$requirement][] = $index;
				}
			}
			else {
				$result['passed']++;
			}
		}

		return $result;
	}
}
