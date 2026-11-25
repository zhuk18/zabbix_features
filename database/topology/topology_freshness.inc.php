<?php

/**
 * The freshness threshold of a discovered link (topology-1-model.md "Freshness threshold"): the one place that holds the
 * macro name, the default, the minimum and the rule that turns the raw macro text into seconds. No Zabbix dependencies;
 * each caller reads the raw text its own way (DBselect in the UI, PDO in the lab and in tests).
 */

const TOPOLOGY_FRESH_MACRO = '{$TOPOLOGY.LINK.FRESH.SECONDS}';
const TOPOLOGY_FRESH_DEFAULT = 604800;     // 7 days
const TOPOLOGY_FRESH_MIN = 60;

/**
 * @param string|null $raw  the macro value as stored, or null when the macro does not exist
 *
 * @return array  [seconds, warning]; the warning is null unless the macro exists with a rejected value
 */
function topology_fresh_seconds(?string $raw): array {
	if ($raw === null) {
		return [TOPOLOGY_FRESH_DEFAULT, null];
	}

	if (preg_match('/^[0-9]+$/D', $raw) === 1 && (int) $raw >= TOPOLOGY_FRESH_MIN) {
		return [(int) $raw, null];
	}

	return [TOPOLOGY_FRESH_DEFAULT, 'Macro '.TOPOLOGY_FRESH_MACRO.' has the value "'.
		htmlspecialchars($raw, ENT_QUOTES, 'UTF-8').'", which is not a number of seconds of at least '.TOPOLOGY_FRESH_MIN.
		'; the default '.TOPOLOGY_FRESH_DEFAULT.' is used.'];
}

/**
 * The limit of neighbors on one reporter port (topology-1-model.md §6.7.7, 2.65): global macro, read as the freshness threshold (§8.8): digits only, at least 1; any other text gives the
 * default and a warning that names the macro and the rejected text (escaped); a missing macro gives the default, no warning.
 */
const TOPOLOGY_PORT_NEIGHBORS_MACRO = '{$TOPOLOGY.PORT.NEIGHBORS.MAX}';
const TOPOLOGY_PORT_NEIGHBORS_DEFAULT = 32;
const TOPOLOGY_PORT_NEIGHBORS_MIN = 1;

/**
 * @param string|null $raw  the macro value as stored, or null when the macro does not exist
 *
 * @return array  [limit, warning]
 */
function topology_port_neighbors_max(?string $raw): array {
	if ($raw === null) {
		return [TOPOLOGY_PORT_NEIGHBORS_DEFAULT, null];
	}

	if (preg_match('/^[0-9]{1,9}$/D', $raw) === 1 && (int) $raw >= TOPOLOGY_PORT_NEIGHBORS_MIN) {
		return [(int) $raw, null];
	}

	return [TOPOLOGY_PORT_NEIGHBORS_DEFAULT, 'Macro '.TOPOLOGY_PORT_NEIGHBORS_MACRO.' has the value "'.
		htmlspecialchars($raw, ENT_QUOTES, 'UTF-8').'", which is not a number of at least '.TOPOLOGY_PORT_NEIGHBORS_MIN.
		'; the default '.TOPOLOGY_PORT_NEIGHBORS_DEFAULT.' is used.'];
}

/**
 * The allowed age of a snapshot (topology-1-model.md §6.2, §8.8, 2.96; FR-B D-55): a snapshot whose clock is older than this is old. The number of intervals and the minimum are
 * constants of this file: no macro in the prototype (FR-B D-55 "define in the Spec"; architecture review B9: settings, not macros, in the product).
 */
const TOPOLOGY_AGE_INTERVALS = 3;
const TOPOLOGY_AGE_MIN = 600;      // seconds

/**
 * The simple update interval of an item (the part of the delay before the first `;`: flexible and scheduling intervals are not used) in seconds. Null when there is no base: the text is empty,
 * 0 (a trapper rule, or an interval that is 0 with only flexible or scheduling intervals) or not an interval (an unresolved macro, text).
 */
function topology_interval_seconds(?string $text): ?int {
	if ($text === null) {
		return null;
	}

	$simple = explode(';', trim($text), 2)[0];

	if (preg_match('/^([0-9]{1,9})([smhdw]?)$/D', $simple, $m) !== 1) {
		return null;
	}

	$seconds = (int) $m[1] * ['' => 1, 's' => 1, 'm' => 60, 'h' => 3600, 'd' => 86400, 'w' => 604800][$m[2]];

	return $seconds > 0 ? $seconds : null;
}

/**
 * @param int|null $base       the update interval of the rule (a dependent rule: of its first master that is not dependent), or the heartbeat when that item has "Discard unchanged with
 *                             heartbeat"; null: no base
 * @param int      $threshold  the freshness threshold (§8.8), the upper limit of every allowed age
 *
 * @return int  seconds: max(3 * base, 600), never more than the threshold; no base: the threshold
 */
function topology_allowed_age(?int $base, int $threshold): int {
	if ($base === null) {
		return $threshold;
	}

	return min($threshold, max(TOPOLOGY_AGE_INTERVALS * $base, TOPOLOGY_AGE_MIN));
}


/**
 * The base of the allowed age of the snapshot of a rule (model §6.2 2.96): the update interval of the rule, for a dependent rule the one of its first master that is not dependent; when the rule
 * or that master has "Discard unchanged with heartbeat", the heartbeat instead. User macros are resolved on the host (its own, its templates, global); flexible and scheduling intervals are not
 * used. No base (a trapper rule or master, an interval that does not resolve or is 0): null, the freshness threshold is the age. ONE definition for ingest and for the page (task 125 part 4:
 * `GET /topo/snapshots` computes the state of a snapshot the way a run does).
 *
 * @param callable $select  function (string $sql): array of rows (assoc), for a SELECT with no parameters (the ids in it are integers); it may throw: the base is then null
 */
function topology_rule_base(callable $select, int $itemid): ?int {
	$one = static fn (string $sql): ?array => $select($sql)[0] ?? null;
	$resolve = static function (string $text, int $hostid) use ($select): string {
		if (!str_contains($text, '{$')) {
			return $text;
		}
		// the macros of the host, nearest first: its own, then its templates (breadth first), then the global ones
		$macros = [];
		$take = static function (array $rows) use (&$macros): void {
			foreach ($rows as $row) {
				$macros[$row['macro']] ??= $row['value'];
			}
		};
		$level = [$hostid];
		$seen = [$hostid => true];
		for ($depth = 0; $level && $depth < 20; $depth++) {
			$next = [];
			foreach ($level as $id) {
				$take($select('SELECT macro, value FROM hostmacro WHERE hostid = '.$id.' AND type = 0'));
				foreach ($select('SELECT templateid FROM hosts_templates WHERE hostid = '.$id) as $link) {
					if (!isset($seen[(int) $link['templateid']])) {
						$seen[(int) $link['templateid']] = true;
						$next[] = (int) $link['templateid'];
					}
				}
			}
			$level = $next;
		}
		$take($select('SELECT macro, value FROM globalmacro WHERE type = 0'));

		return (string) preg_replace_callback('/\{\$[A-Z0-9_.]+\}/', static fn (array $m): string => $macros[$m[0]] ?? $m[0], $text);
	};
	try {
		if (($rule = $one('SELECT itemid, hostid, type, delay, master_itemid FROM items WHERE itemid = '.$itemid)) === null) {
			return null;
		}
		$hostid = (int) $rule['hostid'];
		$master = $rule;
		for ($depth = 0; (int) $master['type'] === 18 && $master['master_itemid'] !== null && $depth < 20; $depth++) {
			if (($master = $one('SELECT itemid, hostid, type, delay, master_itemid FROM items WHERE itemid = '.(int) $master['master_itemid'])) === null) {
				return null;
			}
		}
		if ((int) $master['type'] === 18) {
			return null;      // a chain that never ends in an item with its own interval
		}
		// the heartbeat: "Discard unchanged with heartbeat" on the rule, else on that master (the clock is accurate only to it, §5.5 rule 7)
		foreach ([(int) $rule['itemid'], (int) $master['itemid']] as $id) {
			if (($beat = $one('SELECT params FROM item_preproc WHERE itemid = '.$id.' AND type = 20 ORDER BY step LIMIT 1')) !== null) {
				return topology_interval_seconds($resolve((string) $beat['params'], $hostid));
			}
		}
		if ((int) $master['type'] === 2) {
			return null;      // a Zabbix trapper: no interval
		}

		return topology_interval_seconds($resolve((string) $master['delay'], $hostid));
	}
	catch (Throwable $exception) {
		return null;      // a database that is only a stand-in of the Zabbix tables (a test) has no base for any rule
	}
}
