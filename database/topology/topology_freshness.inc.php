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
