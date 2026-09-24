#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * cleanup_old_neighbor_items.php — one-time (but re-runnable) cleanup for the T-model revised
 * spec's item-key migration (§1.1.1). After the neighbor item prototype's key changed to
 * chassis+port-id-keyed ("lldp.rem[{#LOCPORT},"{#REM.CHASSIS}","{#REM.PORTID}"]"), every
 * previously-discovered item under the OLD key form becomes an orphaned lost resource — and
 * because this template runs with lifetime_type=NEVER (V2, t-model-findings.md), that lost
 * resource stays around forever instead of ever being cleaned up automatically. This script is
 * the one-time cleanup the spec calls for.
 *
 * Detects TWO old forms, not just one, because the deployed template's actual prior key
 * (topo.nbr[{#LOCIFINDEX},{#NBRKEY}], an FNV-1a hash of the canonical peer id) differs from the
 * 2-parameter lldpRemIndex-keyed form the revised spec's own §1 text assumes as the starting
 * point (lldp.rem[{#LOCPORT},{#REMIDX}]) -- see the delivery report for why they diverge. Both
 * are cleaned up here so this script is useful whichever "old" state a given install actually
 * has, not just the one this specific lab happened to be running.
 *
 * Re-runnable: an item already in the new 3-parameter quoted key form never matches either old
 * pattern, so a second run finds nothing to delete.
 *
 * Bootstrap: GOTCHAS.md #3, same as every other CLI script in this directory.
 *
 * Usage: cleanup_old_neighbor_items.php [--dry-run]
 */

chdir(__DIR__.'/../../../ui');
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
require_once 'include/classes/core/APP.php';
APP::getInstance()->run(APP::EXEC_MODE_API);
API::getWrapper()->auth = ['type' => 0];

$userdata = [
	'userid' => '1', 'username' => 'Admin', 'name' => '', 'surname' => '', 'url' => '',
	'autologin' => 0, 'autologout' => '15m', 'lang' => 'en_US', 'refresh' => '30s',
	'theme' => 'default', 'attempt_failed' => 0, 'attempt_ip' => '', 'attempt_clock' => 0,
	'rows_per_page' => 50, 'roleid' => 3, 'userdirectoryid' => null, 'type' => USER_TYPE_SUPER_ADMIN,
	'userip' => '127.0.0.1', 'sessionid' => 'cleanup-old-neighbor-items', 'gui_access' => GROUP_GUI_ACCESS_SYSTEM,
	'debug_mode' => false, 'mfaid' => 0, 'timezone' => 'default'
];
CWebUser::$data = $userdata;
CApiService::$userData = $userdata;

$dry_run = in_array('--dry-run', $argv, true);

function is_old_key(string $key): bool {
	// Old deployed form: topo.nbr[<if_index>,<16-hex-char hash>]
	if (preg_match('/^topo\.nbr\[\d+,[0-9a-f]{16}\]$/', $key)) {
		return true;
	}
	// Old form the revised spec's own §1 text assumes: lldp.rem[<port>,<numeric REMIDX>] --
	// exactly 2 unquoted params, second one purely numeric (a real chassis/port-id-keyed value
	// is always quoted and never purely numeric on its own, since REM.CHASSIS/REM.PORTID are
	// always non-empty strings for any neighbor that resolved at all... except the deliberate
	// empty-string edge case, which still comes through QUOTED ("") not bare -- so "bare numeric
	// second param" stays an unambiguous old-key signature).
	if (preg_match('/^lldp\.rem\[[^,\]]+,\d+\]$/', $key)) {
		return true;
	}
	return false;
}

// Plain substring search, no wildcards flag and no literal '[' in the search string -- with
// searchWildcardsEnabled on, Zabbix's query builder mishandles '[' in the search term (found
// live: searching for 'lldp.rem[' with wildcards enabled silently matched nothing, even though
// items with that exact key substring existed). 'lldp.rem'/'topo.nbr' alone as a plain
// substring search is unambiguous enough for this script's purpose.
$items = API::Item()->get(['output' => ['itemid', 'hostid', 'key_'], 'search' => ['key_' => 'lldp.rem']]);
$items = array_merge($items, API::Item()->get(['output' => ['itemid', 'hostid', 'key_'],
	'search' => ['key_' => 'topo.nbr']]));

$hosts = API::Host()->get(['output' => ['hostid', 'host']]);
$host_names = array_column($hosts, 'host', 'hostid');

$to_delete = [];
$per_host_count = [];
foreach ($items as $item) {
	if (is_old_key($item['key_'])) {
		$to_delete[] = $item['itemid'];
		$hostname = $host_names[$item['hostid']] ?? $item['hostid'];
		$per_host_count[$hostname] = ($per_host_count[$hostname] ?? 0) + 1;
	}
}

if ($to_delete && !$dry_run) {
	API::Item()->delete($to_delete);
}

echo "=== Deleted ".count($to_delete)." old-key neighbor item(s) ===\n";
foreach ($per_host_count as $hostname => $count) {
	echo "  {$hostname}: {$count}\n";
}
if ($dry_run) {
	echo "\n[--dry-run: no items were actually deleted]\n";
}
