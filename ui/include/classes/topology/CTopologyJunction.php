<?php

/**
 * Junction projection (topology-1-model.md §12.6, task 104): a UI-only projection, computed on read, nothing is stored. Pure: no database, no framework calls, so that a unit test
 * can run it.
 *
 * Rule: for a reporter Port P, if the latest usable snapshot of ONE NEIGHBORS rule has two or more rows that resolve to different Devices, and P has no active manual link, the
 * projection is anchored at P and connects those Devices. Rows of different rules are not combined to trigger it; several rows of one Device do not trigger it. Access: the
 * condition is evaluated on the Devices the user can see; fewer than two visible Devices give no projection.
 */
class CTopologyJunction {

	/**
	 * @param array      $rows        the usable observation rows: each ['itemid' => the rule, 'port_id' => the reporter Port, 'device_id' => the Device the row resolves to]
	 * @param array      $suppressed  Port ids that have an active manual link (the key is the id; the value is ignored)
	 * @param array|null $visible     Device ids the user can see (the key is the id); null = every Device
	 * @param array      $reporters   Port id => the Device id that reports it (a Device is never a member of the projection on its own Port)
	 *
	 * @return array  list of ['port_id' => P, 'device_ids' => [the Devices, sorted by id]], sorted by Port id
	 */
	public static function project(array $rows, array $suppressed, ?array $visible, array $reporters = []): array {
		$by_rule = [];
		foreach ($rows as $row) {
			$port = (string) $row['port_id'];
			$device = (string) $row['device_id'];
			if (isset($suppressed[$port]) || ($visible !== null && !isset($visible[$device])) || (($reporters[$port] ?? null) === $device)) {
				continue;
			}
			$by_rule[$port][(string) $row['itemid']][$device] = true;
		}

		$junctions = [];
		foreach ($by_rule as $port => $rules) {
			$members = [];
			foreach ($rules as $devices) {
				if (count($devices) >= 2) {
					$members += $devices;
				}
			}
			if ($members) {
				$ids = array_map('strval', array_keys($members));
				sort($ids, SORT_NUMERIC);
				$junctions[] = ['port_id' => (string) $port, 'device_ids' => $ids];
			}
		}
		usort($junctions, static fn (array $a, array $b): int => (int) $a['port_id'] <=> (int) $b['port_id']);

		return $junctions;
	}
}
