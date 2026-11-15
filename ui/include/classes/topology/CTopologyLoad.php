<?php

/**
 * The last values of the items of the port load (topology-1-model.md §4.8 "Reading the load", 2.88; task 103).
 *
 * One query for all the items of a request, per history table (`history` for float items, `history_uint` for unsigned integer items): only the last value of each item, in a window of three times
 * the longest update interval of those items (from `items.delay`, the macros resolved as the frontend does), at most the setting HISTORY_PERIOD. An item with no value in the window has no entry.
 * The permission is that of the caller: the items come from an item.get of the user, so an item that the user may not read is not in the list.
 */
class CTopologyLoad {

	/** @var int|null  the HISTORY_PERIOD in seconds; null: read the setting (a test sets it) */
	public static ?int $history_period = null;

	/** the factor of the window: three times the longest update interval of the items */
	public const WINDOW_FACTOR = 3;

	/**
	 * @param array $items  rows of item.get: itemid, hostid, value_type, type, delay, master_itemid
	 * @param int|null $now  the time (a test sets it)
	 *
	 * @return array  itemid => ['value' => string, 'clock' => int]
	 */
	public static function lastValues(array $items, ?int $now = null): array {
		if (!$items) {
			return [];
		}

		$now ??= time();
		$from = $now - self::window($items);
		$ids_by_table = [];

		foreach ($items as $item) {
			$table = [ITEM_VALUE_TYPE_FLOAT => 'history', ITEM_VALUE_TYPE_UINT64 => 'history_uint'][(int) $item['value_type']] ?? null;

			if ($table !== null) {
				$ids_by_table[$table][] = $item['itemid'];
			}
		}

		$result = [];

		foreach ($ids_by_table as $table => $itemids) {
			// The last row of each item in one query: the newest clock of each item in the window (the primary key is (itemid, clock, ns): a range of the key for each item, no sort), joined back to
			// its row(s); two rows of the same second are told apart by ns below.
			$rows = DBselect(
				'SELECT h.itemid,h.clock,h.ns,h.value FROM '.$table.' h JOIN ('.
					'SELECT itemid,MAX(clock) AS clock FROM '.$table.
					' WHERE '.dbConditionId('itemid', $itemids).' AND clock>='.zbx_dbstr($from).' GROUP BY itemid'.
				') m ON m.itemid=h.itemid AND m.clock=h.clock'
			);

			while ($row = DBfetch($rows, false)) {
				$id = (string) $row['itemid'];

				if (!array_key_exists($id, $result) || (int) $row['ns'] > $result[$id]['ns']) {
					$result[$id] = ['value' => (string) $row['value'], 'clock' => (int) $row['clock'], 'ns' => (int) $row['ns']];
				}
			}
		}

		foreach ($result as &$value) {
			unset($value['ns']);
		}
		unset($value);

		return $result;
	}

	/**
	 * The window in seconds: three times the longest update interval of the items, at most HISTORY_PERIOD. An item without an interval that can be known (a trapper, a log item, a scheduled one) takes
	 * the whole HISTORY_PERIOD.
	 */
	public static function window(array $items): int {
		$maximum = self::$history_period ?? (int) timeUnitToSeconds(CSettingsHelper::get(CSettingsHelper::HISTORY_PERIOD));
		$longest = 0;

		foreach (self::delays($items) as $delay) {
			if ($delay === null || $delay <= 0) {
				return $maximum;
			}

			$longest = max($longest, $delay);
		}

		return $longest > 0 ? min($maximum, self::WINDOW_FACTOR * $longest) : $maximum;
	}

	/**
	 * The update interval of each item in seconds (the longest of the default and the flexible intervals, as the frontend does it for the graphs), null when it can not be known. A dependent item takes
	 * the interval of its master item.
	 *
	 * @return array  itemid => int|null
	 */
	private static function delays(array $items): array {
		$by_id = [];

		foreach ($items as $item) {
			$by_id[$item['itemid']] = $item + ['type' => ITEM_TYPE_ZABBIX, 'delay' => '0', 'master_itemid' => 0, 'hostid' => 0];
		}

		// a dependent item: its master item (and the master of that one) gives the interval
		for ($level = 0; $level < 5; $level++) {
			$missing = [];

			foreach ($by_id as $item) {
				if ((int) $item['type'] === ITEM_TYPE_DEPENDENT && (int) $item['master_itemid'] > 0 && !array_key_exists($item['master_itemid'], $by_id)) {
					$missing[$item['master_itemid']] = true;
				}
			}

			if (!$missing) {
				break;
			}

			foreach (DBfetchArray(DBselect('SELECT itemid,hostid,type,delay,master_itemid FROM items WHERE '.dbConditionId('itemid', array_keys($missing)))) as $master) {
				$by_id[$master['itemid']] = $master;
			}
		}

		// macros in the interval: resolved as the frontend does (only when there is one: no query otherwise)
		$with_macros = array_filter($by_id, static fn (array $item): bool => str_contains((string) $item['delay'], '{$'));

		if ($with_macros) {
			foreach (CMacrosResolverHelper::resolveTimeUnitMacros($with_macros, ['delay']) as $itemid => $item) {
				$by_id[$itemid]['delay'] = $item['delay'];
			}
		}

		$delays = [];

		foreach ($items as $item) {
			$source = $by_id[$item['itemid']];

			for ($level = 0; $level < 5 && (int) $source['type'] === ITEM_TYPE_DEPENDENT; $level++) {
				$source = $by_id[$source['master_itemid']] ?? null;

				if ($source === null) {
					break;
				}
			}

			$delays[$item['itemid']] = $source === null ? null : self::maxDelay($source);
		}

		return $delays;
	}

	private static function maxDelay(array $item): ?int {
		if ((int) $item['type'] === ITEM_TYPE_TRAPPER || (int) $item['type'] === ITEM_TYPE_DEPENDENT) {
			return null;
		}

		$parser = new CUpdateIntervalParser();

		if ($parser->parse((string) $item['delay']) != CParser::PARSE_SUCCESS) {
			return null;
		}

		$delay = (int) timeUnitToSeconds($parser->getDelay());

		foreach ($parser->getIntervals(ITEM_DELAY_FLEXIBLE) as $flexible_interval) {
			$delay = max($delay, (int) timeUnitToSeconds(explode('/', $flexible_interval)[0]));
		}

		if ($delay == 0 && $parser->getIntervals(ITEM_DELAY_SCHEDULING)) {
			return null;
		}

		return $delay;
	}
}
