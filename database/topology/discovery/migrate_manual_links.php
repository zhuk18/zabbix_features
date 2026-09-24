#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * migrate_manual_links.php — one-time (but re-runnable) conversion of legacy topo.link.manual
 * tags from the old neighbor_ref format ("h:<hostid>") to the new one ("d:<chassis_id>" or
 * "h:<host technical name>") that survives export/import (a raw hostid does not).
 *
 * Detection: a tag's neighbor_ref (2nd '|'-delimited field, per the same escaping rules
 * CTopologyTModel::splitEscapedPipe() uses) is treated as legacy exactly when it matches
 * `h:<all-digit string>`. This is a deliberate, documented ambiguity — a real Zabbix host can
 * be named entirely with digits, in which case a genuinely NEW-format "h:12345" tag (referring
 * to a host literally named "12345") would be wrongly reinterpreted here as legacy hostid
 * 12345. Accepted per the task's own instruction: this is a one-time best-effort migration, not
 * an ongoing parsing rule — CTopologyTModel itself never treats "h:<digits>" as anything but a
 * literal host name once this script has run. Re-running this script is a no-op for tags
 * already in the new format for the same reason new-format tags are never misdetected as
 * legacy going forward: once converted, "h:11252" (a hostid) becomes "h:Switch1" (a name) or
 * "d:00:11:22:33:44:02" (a chassis id), neither of which matches the all-digit legacy pattern.
 *
 * For each legacy tag found:
 *   1. Host (identified by the OLD hostid) exists AND carries a topo.id tag -> rewrite using
 *      "d:<chassis>" from the first topo.id value (stripping this codebase's internal "c:"
 *      prefix if present; an s:-scoped topo.id has no real chassis id, and is used verbatim
 *      minus prefix as a documented best-effort fallback -- flagged in the report, not silently
 *      assumed correct).
 *   2. Host exists, no topo.id -> rewrite using "h:<host's own technical name>".
 *   3. Host not found (deleted since) -> left unchanged, listed in the report.
 *
 * Bootstrap: GOTCHAS.md #3 (same as every other CLI script in this directory) -- goes through
 * the real Zabbix API, not a direct DB connection, since host.get/host.update enforce the same
 * tag-replace-is-a-full-replace semantics (V5) the rest of this model depends on.
 *
 * Usage: migrate_manual_links.php [--dry-run]
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
	'userip' => '127.0.0.1', 'sessionid' => 'migrate-manual-links', 'gui_access' => GROUP_GUI_ACCESS_SYSTEM,
	'debug_mode' => false, 'mfaid' => 0, 'timezone' => 'default'
];
CWebUser::$data = $userdata;
CApiService::$userData = $userdata;

$dry_run = in_array('--dry-run', $argv, true);

// Mirrors CTopologyTModel's private splitEscapedPipe/joinEscapedPipe exactly (constraint: same
// escaping rule on both the reading and the migrating side) -- duplicated rather than made
// public on the model class purely for this one-off script, since exposing internal parsing
// helpers as public API surface for a migration tool isn't worth doing for a single caller.
function split_escaped_pipe(string $raw): array {
	$fields = [];
	$current = '';
	$len = strlen($raw);
	for ($i = 0; $i < $len; $i++) {
		$ch = $raw[$i];
		if ($ch === '\\' && $i + 1 < $len) {
			$current .= $raw[$i + 1];
			$i++;
		}
		elseif ($ch === '|') {
			$fields[] = $current;
			$current = '';
		}
		else {
			$current .= $ch;
		}
	}
	$fields[] = $current;
	return $fields;
}

function join_escaped_pipe(array $fields): string {
	return implode('|', array_map(static fn (string $s): string => str_replace(['\\', '|'], ['\\\\', '\\|'], $s), $fields));
}

$hosts = API::Host()->get(['output' => ['hostid', 'host'], 'selectTags' => 'extend']);
$hosts_by_id = array_column($hosts, null, 'hostid');

$converted_to_d = [];
$converted_to_h = [];
$not_found = [];

foreach ($hosts as $host) {
	$hostid = $host['hostid'];
	$tags = $host['tags'];
	$changed = false;
	$new_tags = [];

	foreach ($tags as $tag) {
		if ($tag['tag'] !== 'topo.link.manual') {
			$new_tags[] = ['tag' => $tag['tag'], 'value' => $tag['value']];
			continue;
		}

		$parts = split_escaped_pipe($tag['value']);
		if (count($parts) !== 3) {
			$new_tags[] = ['tag' => $tag['tag'], 'value' => $tag['value']]; // malformed -- not this script's job
			continue;
		}

		[$local_port, $neighbor_ref, $peer_port] = $parts;
		if (!preg_match('/^h:(\d+)$/', $neighbor_ref, $m)) {
			$new_tags[] = ['tag' => $tag['tag'], 'value' => $tag['value']]; // already new format, or unrelated
			continue;
		}

		$old_hostid = $m[1];
		$old_host = $hosts_by_id[$old_hostid] ?? null;

		if ($old_host === null) {
			$not_found[] = ['hostid' => $hostid, 'host' => $host['host'], 'local_port' => $local_port,
				'old_hostid' => $old_hostid, 'raw' => $tag['value']];
			$new_tags[] = ['tag' => $tag['tag'], 'value' => $tag['value']]; // left unchanged
			continue;
		}

		$topo_id = null;
		foreach ($old_host['tags'] ?? [] as $t2) {
			if ($t2['tag'] === 'topo.id') {
				$topo_id = $t2['value'];
				break;
			}
		}

		if ($topo_id !== null) {
			$chassis = str_starts_with($topo_id, 'c:') ? substr($topo_id, 2) : $topo_id;
			$new_ref = 'd:'.$chassis;
			$converted_to_d[] = ['hostid' => $hostid, 'host' => $host['host'], 'local_port' => $local_port,
				'old_ref' => $neighbor_ref, 'new_ref' => $new_ref,
				'note' => str_starts_with($topo_id, 'c:') ? null : "topo.id '{$topo_id}' has no chassis prefix -- used verbatim, best effort"];
		}
		else {
			$new_ref = 'h:'.$old_host['host'];
			$converted_to_h[] = ['hostid' => $hostid, 'host' => $host['host'], 'local_port' => $local_port,
				'old_ref' => $neighbor_ref, 'new_ref' => $new_ref];
		}

		$new_tags[] = ['tag' => $tag['tag'], 'value' => join_escaped_pipe([$local_port, $new_ref, $peer_port])];
		$changed = true;
	}

	if ($changed && !$dry_run) {
		$result = API::Host()->update([['hostid' => $hostid, 'tags' => $new_tags]]);
		if ($result === false) {
			fwrite(STDERR, "WARNING: host.update rejected for hostid {$hostid} ({$host['host']}) -- V5 gotcha, ".
				"check for a stray 'automatic' field or similar.\n");
		}
	}
}

echo "=== Converted to d:<chassis_id> (".count($converted_to_d).") ===\n";
foreach ($converted_to_d as $c) {
	echo "  {$c['host']} ({$c['hostid']}) port {$c['local_port']}: {$c['old_ref']} -> {$c['new_ref']}".
		($c['note'] ? " [{$c['note']}]" : '')."\n";
}
echo "\n=== Converted to h:<host name> (".count($converted_to_h).") ===\n";
foreach ($converted_to_h as $c) {
	echo "  {$c['host']} ({$c['hostid']}) port {$c['local_port']}: {$c['old_ref']} -> {$c['new_ref']}\n";
}
echo "\n=== Not found (old host no longer exists -- left unchanged) (".count($not_found).") ===\n";
foreach ($not_found as $n) {
	echo "  {$n['host']} ({$n['hostid']}) port {$n['local_port']}: old hostid {$n['old_hostid']} not found (raw: {$n['raw']})\n";
}
if ($dry_run) {
	echo "\n[--dry-run: no tags were actually written]\n";
}
