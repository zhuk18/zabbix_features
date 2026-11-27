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
 * Topology types of discovery rules and the LLD macro contract of each topology type.
 *
 * Single source of truth for the UI hint, the Test dialog contract check and export strings. The server-side
 * processing of the rows of a topology type (C) must stay in sync with the data below.
 */
class CTopologyType {

	/**
	 * Macro that may stand in for {#IFINDEX}, only for topology types listed in $snmpindex_fallback.
	 */
	public const SNMPINDEX_MACRO = '{#SNMPINDEX}';

	/**
	 * Topology type definitions:
	 *   export   - export/import string;
	 *   required - macros that must be present and non-empty;
	 *   one_of   - groups of macros, at least one macro of each group must be present and non-empty;
	 *   optional - macros with a defined meaning that are not required;
	 *   numeric  - macros whose value must be an unsigned integer (`ZBX_LLD_TOPO_UINT` of lld_topology.c): a row with another value is invalid (model §5.2, FR-B D-14).
	 */
	private const TYPES = [
		ZBX_TOPOLOGY_TYPE_PORTS => [
			'export' => 'PORTS',
			'required' => ['{#IFINDEX}'],
			'one_of' => [],
			'optional' => ['{#IFNAME}', '{#IFDESCR}', '{#IFALIAS}', '{#IFTYPE}', '{#IFMAC}', '{#IFADMINSTATUS}', '{#IFOPERSTATUS}',
				'{#IFSPEED}', '{#LOC_CHASSIS}', '{#LOC_CHASSIS_TYPE}'
			],
			'numeric' => ['{#IFINDEX}']
		],
		ZBX_TOPOLOGY_TYPE_NEIGHBORS => [
			'export' => 'NEIGHBORS',
			'required' => [],
			'one_of' => [['{#IFINDEX}', '{#LOC_PORT_NUM}', '{#IFNAME}'],
				['{#REM_CHASSIS}', '{#REM_MGMT_IP}', '{#REM_MAN_ADDRS}', '{#REM_SYSNAME}']
			],
			'optional' => ['{#LOC_PORT_ID}', '{#LOC_PORT_ID_TYPE}', '{#LOC_PORT_DESC}', '{#REM_CHASSIS_TYPE}',
				'{#REM_PORT}', '{#REM_PORT_TYPE}', '{#REM_PORT_DESC}', '{#REM_SYS_CAP}', '{#LOC_CHASSIS}',
				'{#LOC_CHASSIS_TYPE}', '{#SOURCE}'
			],
			'numeric' => ['{#IFINDEX}', '{#LOC_PORT_NUM}']
		],
		ZBX_TOPOLOGY_TYPE_LEARNED_MACS => [
			'export' => 'LEARNED_MACS',
			'required' => ['{#IFINDEX}'],
			'one_of' => [['{#MAC}', '{#PORT_MAC_COUNT}']],
			'optional' => ['{#VLAN}'],
			'numeric' => ['{#IFINDEX}', '{#PORT_MAC_COUNT}']
		],
		ZBX_TOPOLOGY_TYPE_LAG => [
			'export' => 'LAG',
			'required' => ['{#IFINDEX}', '{#LAG_IFINDEX}'],
			'one_of' => [],
			'optional' => [],
			'numeric' => ['{#IFINDEX}', '{#LAG_IFINDEX}']
		],
		ZBX_TOPOLOGY_TYPE_DEVICE => [
			'export' => 'DEVICE',
			'required' => [],
			'one_of' => [['{#LOC_CHASSIS}', '{#LOC_CDP_DEVICE_ID}', '{#LOC_SYSNAME}']],
			'optional' => ['{#LOC_CHASSIS_TYPE}', '{#LOC_SYS_CAP}'],
			'numeric' => []
		]
	];

	/**
	 * Topology types for which {#SNMPINDEX} is accepted in place of a missing {#IFINDEX}. Stock IF-MIB interface discovery
	 * rules emit {#SNMPINDEX} = ifIndex; for other topology types {#SNMPINDEX} is a different table index.
	 */
	private const SNMPINDEX_FALLBACK = [ZBX_TOPOLOGY_TYPE_PORTS];

	/**
	 * Topology types that are reserved for release 3 (model 2.105 §5.1, 2.121; FR-B D-9, D-31; LLD spec 2.22): LEARNED_MACS and LAG. Their contract stays in TYPES (the server processes them and the
	 * contract tables are compared with the server's), their constants stay, but in release 1 the API refuses them on create, update and import with the error of an unknown value, and the
	 * rule form does not offer them.
	 */
	private const RESERVED = [ZBX_TOPOLOGY_TYPE_LEARNED_MACS, ZBX_TOPOLOGY_TYPE_LAG];

	/**
	 * The topology types that a rule may have in release 1: NONE, PORTS, NEIGHBORS, DEVICE.
	 */
	public static function getTypes(): array {
		return array_merge([ZBX_TOPOLOGY_TYPE_NONE], array_values(array_diff(array_keys(self::TYPES), self::RESERVED)));
	}

	private static function getAllLabels(): array {
		return [
			ZBX_TOPOLOGY_TYPE_NONE => _('None'),
			ZBX_TOPOLOGY_TYPE_PORTS => _('Ports'),
			ZBX_TOPOLOGY_TYPE_NEIGHBORS => _('Neighbors'),
			ZBX_TOPOLOGY_TYPE_LEARNED_MACS => _('Learned MACs'),
			ZBX_TOPOLOGY_TYPE_LAG => _('LAG membership'),
			ZBX_TOPOLOGY_TYPE_DEVICE => _('Device')
		];
	}

	/**
	 * Labels of the topology types that the rule form offers.
	 */
	public static function getLabels(): array {
		return array_intersect_key(self::getAllLabels(), array_flip(self::getTypes()));
	}

	/**
	 * Label of a topology type for display; a rule that still has a reserved type (data of an earlier build) is named.
	 */
	public static function getLabel(int $topology_type): string {
		return self::getAllLabels()[$topology_type] ?? _('Unknown');
	}

	/**
	 * Export/import strings indexed by topology type value, including NONE; the import accepts the types of release 1 only.
	 */
	public static function getExportStrings(): array {
		return array_intersect_key(
			[ZBX_TOPOLOGY_TYPE_NONE => 'NONE'] + array_map(static fn(array $definition) => $definition['export'], self::TYPES),
			array_flip(self::getTypes())
		);
	}

	/**
	 * The sources of the preprocessing step "SNMP walk to topology" in release 1 (LLD spec 2.22 §4.2): the sources `fdb` and `lag` are release 3. The MAC limit (third parameter) belongs to `fdb`:
	 * the form does not offer it and only its default is accepted.
	 */
	public static function getStepSources(): array {
		return ZBX_PREPROC_TOPOLOGY_SOURCES_RELEASE_1;
	}

	/**
	 * Contract of a topology type, or null for NONE and unknown topology types.
	 *
	 * @return array|null  ['required' => [], 'one_of' => [[]], 'optional' => [], 'numeric' => [], 'snmpindex_fallback' => bool]
	 */
	public static function getContract(int $topology_type): ?array {
		if (!array_key_exists($topology_type, self::TYPES)) {
			return null;
		}

		return [
			'required' => self::TYPES[$topology_type]['required'],
			'one_of' => self::TYPES[$topology_type]['one_of'],
			'optional' => self::TYPES[$topology_type]['optional'],
			'numeric' => self::TYPES[$topology_type]['numeric'],
			'snmpindex_fallback' => in_array($topology_type, self::SNMPINDEX_FALLBACK)
		];
	}

	/**
	 * Check LLD rows against the contract of a topology type.
	 *
	 * @param int   $topology_type
	 * @param array $rows          Decoded LLD rows.
	 * @param array $path_macros   Macros defined on the rule's LLD macros tab; treated as available, not evaluated.
	 *
	 * @return array  ['rows' => int, 'passed' => int, 'failed' => int, 'snmpindex_fallback_rows' => int,
	 *                 'path_macros' => [], 'failures' => [<requirement> => [<row index>, ...]]]
	 */
	public static function checkRows(int $topology_type, array $rows, array $path_macros): array {
		$contract = self::getContract($topology_type);

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

		$valid_indexes = [];

		foreach (array_values($rows) as $index => $row) {
			$row = is_array($row) ? $row : [];
			$is_available = static fn(string $macro): bool => array_key_exists($macro, $path_macros)
				|| (array_key_exists($macro, $row) && is_scalar($row[$macro]) && (string) $row[$macro] !== '');

			$missing = [];

			// the value of an unsigned integer macro is checked as the server checks it (zbx_is_uint64); the value of {#SNMPINDEX} is the value of {#IFINDEX} when it stands in for it; a value that
			// comes from an LLD macro path of the rule is not evaluated here (as for the other checks)
			foreach ($contract['numeric'] as $macro) {
				$value = null;

				if (array_key_exists($macro, $row) && is_scalar($row[$macro]) && (string) $row[$macro] !== '') {
					$value = (string) $row[$macro];
				}
				elseif (!array_key_exists($macro, $path_macros) && $macro === '{#IFINDEX}' && $contract['snmpindex_fallback']
						&& array_key_exists(self::SNMPINDEX_MACRO, $row) && is_scalar($row[self::SNMPINDEX_MACRO])
						&& (string) $row[self::SNMPINDEX_MACRO] !== '' && !array_key_exists(self::SNMPINDEX_MACRO, $path_macros)) {
					$value = (string) $row[self::SNMPINDEX_MACRO];
				}

				if ($value !== null && !self::isUint64($value)) {
					$missing[] = $macro.' is not an unsigned integer';
				}
			}

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
				$valid_indexes[] = $index;
			}
		}

		// DEVICE (LLD spec 2.19 §3.5): one device has one identity; more than one valid row gives an empty snapshot, as the server does (lld_topology.c)
		if ($topology_type === ZBX_TOPOLOGY_TYPE_DEVICE && $result['passed'] > 1) {
			$result['failed'] += $result['passed'];
			$result['failures']['one row expected, '.$result['passed'].' valid rows'] = $valid_indexes;
			$result['passed'] = 0;
		}

		return $result;
	}

	/**
	 * A string that is an unsigned 64-bit integer, as zbx_is_uint64() takes it: digits only, no sign, no space, at most 18446744073709551615.
	 */
	private static function isUint64(string $value): bool {
		if (preg_match('/^[0-9]+$/', $value) !== 1) {
			return false;
		}

		$value = ltrim($value, '0');
		$value = $value === '' ? '0' : $value;

		return strlen($value) < 20 || (strlen($value) === 20 && strcmp($value, '18446744073709551615') <= 0);
	}
}
