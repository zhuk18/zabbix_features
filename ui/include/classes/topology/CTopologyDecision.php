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
 * Operator decisions as records (topology-1-model.md §6.7.8, 2.106; task 129): the rules that the frontend (the operator actions that write the records) and the ingest CLI (every run
 * reads them) share. No database access here: the callers read and write the table `topo_decision` (mysql_schema.sql) themselves.
 *
 * A record names a Device by its natural key, never by a node id:
 *   1. its strongest identity key (§6.7.0): `chassis:mac:...`, then a scoped chassis key (`chassis:<type>:<segment>:<value>`), then `cdp:...`;
 *   2. for a Device without a key: `port:<reporter hostid>:<port name key>:<name key>`.
 */
class CTopologyDecision {

	public const KIND_MERGE = 'merge';
	public const KIND_NOT_SAME = 'not_same';
	public const KIND_ACCEPT_CHASSIS = 'accept_chassis';
	public const KIND_ASSOCIATE = 'associate';

	/** The version of the normalization rules when a record is written (FR-0 decision 13.3): the version of the normalization spec, 0.24 as 24. */
	public const NORM_VERSION = 24;

	/**
	 * The natural key of a Device.
	 *
	 * @param string[] $keys   the identity keys that the Device holds and that are not replaced
	 * @param array    $attrs  attrs of the Device (`sysname`)
	 * @param array    $ports  the reporter ports where the Device was seen (its `scope_ports`): [['hostid' => , 'name' => ], ...]; a pseudo port is not in the list
	 *
	 * @return string|null  null when the Device has neither a key nor a reporter port with a name (nothing to name it by)
	 */
	public static function naturalKey(array $keys, array $attrs, array $ports): ?string {
		$ranked = [];
		foreach ($keys as $key) {
			if (strncmp($key, 'chassis:mac:', 12) === 0) {
				$rank = 0;
			}
			elseif (strncmp($key, 'chassis:', 8) === 0) {
				$rank = 1;
			}
			elseif (strncmp($key, 'cdp:', 4) === 0) {
				$rank = 2;
			}
			else {
				continue;
			}
			$ranked[] = [$rank, $key];
		}
		if ($ranked) {
			sort($ranked);

			return $ranked[0][1];
		}

		$name_key = CTopologyAddress::nameKey((string) ($attrs['sysname'] ?? ''));
		$candidates = [];
		foreach ($ports as $port) {
			$port_key = CTopologyAddress::nameKey((string) ($port['name'] ?? ''));
			if ($port_key !== '') {
				$candidates[] = 'port:'.(int) $port['hostid'].':'.$port_key.':'.$name_key;
			}
		}
		if (!$candidates || $name_key === '') {
			return null;
		}
		sort($candidates);

		return $candidates[0];
	}

	/** The chassis value of a chassis key string: `chassis:mac:<mac>` or `chassis:<type>:<segment>:<value>`; null for another kind of key. */
	public static function chassisOfKey(string $key): ?string {
		if (str_starts_with($key, 'chassis:mac:')) {
			return substr($key, strlen('chassis:mac:'));
		}

		return preg_match('/^chassis:[^:]+:(?:server|pg:\d+|proxy:\d+):(.*)$/s', $key, $m) === 1 ? $m[1] : null;
	}

	public static function isPortKey(string $key): bool {
		return strncmp($key, 'port:', 5) === 0;
	}

	/**
	 * The keys of one side of a record: its natural key and, for the removed side of a merge, every key that the Device held (`keys_b`).
	 *
	 * @return string[]
	 */
	public static function recordKeys(array $record, string $side = 'b'): array {
		if ($side === 'a') {
			return [(string) $record['key_a']];
		}
		$keys = [];
		if (($record['key_b'] ?? null) !== null) {
			$keys[] = (string) $record['key_b'];
		}
		if (($record['keys_b'] ?? null) !== null && $record['keys_b'] !== '') {
			$list = json_decode((string) $record['keys_b'], true);
			foreach (is_array($list) ? $list : [] as $key) {
				if (is_string($key) && $key !== '') {
					$keys[] = $key;
				}
			}
		}

		return array_values(array_unique($keys));
	}

	/**
	 * The merge classes of the records (§6.7.8 "Effect in each run", `merge`): every key of `key_a`, `key_b` and `keys_b` of a merge names one Device. Merges chain (A = B and B = C give
	 * one class). A pair with a `not_same` record is not joined, also through a chain: the merge that would join two classes with a `not_same` pair between them is refused (the later record
	 * of a chain is the one that is refused) and listed as a conflict. The records are read in the order of `decisionid`.
	 *
	 * @param array[] $records  rows of topo_decision of the kinds merge and not_same, in the order of decisionid
	 *
	 * @return array  ['class_of' => key => class number, 'keys' => class number => [keys], 'conflicts' => [['merge' => decisionid, 'not_same' => decisionid], ...]]
	 */
	public static function mergeClasses(array $records): array {
		$class_of = [];
		$keys = [];
		$next = 0;
		$not_same = [];
		foreach ($records as $record) {
			if ($record['kind'] === self::KIND_NOT_SAME) {
				$not_same[] = $record;
			}
		}
		$class_for = static function (string $key) use (&$class_of, &$keys, &$next): int {
			if (!isset($class_of[$key])) {
				$class_of[$key] = $next;
				$keys[$next] = [$key];
				$next++;
			}

			return $class_of[$key];
		};
		$conflicts = [];
		foreach ($records as $record) {
			if ($record['kind'] !== self::KIND_MERGE) {
				continue;
			}
			$a = $class_for((string) $record['key_a']);
			$b_keys = self::recordKeys($record);
			if (!$b_keys) {
				continue;
			}
			// the classes of the removed side: all its keys are one Device (a record names them as one), so their classes are joined with each other
			$target = $a;
			$refused = null;
			$join = [];
			foreach ($b_keys as $key) {
				$c = $class_for($key);
				if ($c !== $target && !in_array($c, $join, true)) {
					$join[] = $c;
				}
			}
			foreach ($join as $c) {
				foreach ($not_same as $pair) {
					$x = $class_of[(string) $pair['key_a']] ?? null;
					$y = isset($pair['key_b']) ? ($class_of[(string) $pair['key_b']] ?? null) : null;
					if (($x === $target && $y === $c) || ($x === $c && $y === $target)) {
						$refused = (int) $pair['decisionid'];
						break 2;
					}
				}
			}
			if ($refused !== null) {
				$conflicts[] = ['merge' => (int) $record['decisionid'], 'not_same' => $refused];
				continue;
			}
			foreach ($join as $c) {
				foreach ($keys[$c] as $key) {
					$class_of[$key] = $target;
					$keys[$target][] = $key;
				}
				unset($keys[$c]);
			}
		}

		return ['class_of' => $class_of, 'keys' => array_map(static fn (array $list): array => array_values(array_unique($list)), $keys), 'conflicts' => $conflicts];
	}

	/**
	 * The pairs that a `not_same` record names, as the two classes' keys (a pair is "not the same" for every key of the classes that its two keys belong to).
	 *
	 * @param array[] $records  rows of topo_decision (any kinds)
	 * @param array   $classes  mergeClasses() of the same records
	 *
	 * @return array  list of [keys of side a, keys of side b]
	 */
	public static function notSamePairs(array $records, array $classes): array {
		$pairs = [];
		foreach ($records as $record) {
			if ($record['kind'] !== self::KIND_NOT_SAME || ($record['key_b'] ?? null) === null) {
				continue;
			}
			$a = (string) $record['key_a'];
			$b = (string) $record['key_b'];
			$pairs[] = [
				isset($classes['class_of'][$a]) ? $classes['keys'][$classes['class_of'][$a]] : [$a],
				isset($classes['class_of'][$b]) ? $classes['keys'][$classes['class_of'][$b]] : [$b]
			];
		}

		return $pairs;
	}
}
