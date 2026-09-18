#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * ingest.php — topology discovery "ingest" component for the tags-only model
 * (topology-tags-model-spec.md §14/§14.1/§14.2/§15).
 *
 * Reads the latest `topology.discovery.raw` value per reporter (via the Zabbix API's
 * item.get/history.get — reporters are discovered dynamically, never a static list, per §14)
 * and, for each one, replaces that Host's discovery-owned tag namespace (topology.chassis_id,
 * topology.type, topology.port.* and topology.neighbor.*) with a freshly rebuilt snapshot
 * derived from the blob (§15's "replace the snapshot" semantics), using the
 * read-modify-write sequence in §14.1: every non-topology tag and the topology.identity tag
 * (owned exclusively by promote/depromote, never touched here) are carried through unchanged.
 *
 * There is no topology database in this model (§0) — this script never opens a DB connection
 * of its own; it goes entirely through the Zabbix API (host.get/host.update), the same way
 * CTopologyPrototype does, bootstrapped the same way database/topology/test_tags_model.php is
 * (see GOTCHAS.md #3/#4 for why checkAuthentication() can't be used in this environment and
 * CWebUser::$data/CApiService::$userData are populated by hand instead).
 *
 * The raw blob shape (produced by push.py) is unchanged from the previous DB-backed prototype:
 *   {
 *     "reporter": {"chassis_id", "mgmt_ip", "sysname", "vendor"},
 *     "ports": [{"if_index", "name", "if_type", "mac", "admin_status", "oper_status"}, ...],
 *     "neighbors": [{"local_if_index", "remote_chassis_id", "remote_sysname", "remote_port_id",
 *                    "remote_port_id_subtype", "remote_port_desc"}, ...],
 *     "stats": {...}
 *   }
 * Only chassis_id, port.* and neighbor.* fields defined by the tags spec (§7.1/§8/§9) are
 * carried into tags — mgmt_ip/vendor/sysname/stats have no defined tag in this model and are
 * dropped.
 * A neighbor entry with no remote_chassis_id is not written at all: §9's minimum required
 * neighbor properties are chassis_id + port, and without a chassis_id there is no topology
 * identity for the derived graph (§10) to resolve against in this model (no sysname-matching
 * fallback here, unlike the old DB model's rule 4 — that entire mechanism doesn't exist
 * because there's no persistent Device to match sysname against).
 *
 * Usage:
 *   ingest.php [--zabbix-host <host> ...] [--help]
 *
 * --zabbix-host optionally scopes a run to specific reporter Host(s) (e.g. ad-hoc testing) —
 * a filter on top of dynamic discovery, never a replacement for it; omitting it (the default)
 * runs a full dynamic pass over every reporter.
 */

function fail(string $message): void {
	fwrite(STDERR, $message."\n");
	exit(1);
}

$options = getopt('', ['zabbix-host:', 'help']);

if (isset($options['help'])) {
	fwrite(STDOUT, "See the file header for usage.\n");
	exit(0);
}

// ---- Run lock + status file (§14.2: shared by the CLI and the web controller that spawns this
// same script, so both entry points are covered by one lock). Lives under sys_get_temp_dir()
// rather than next to this script, since a real deployment may run the CLI as one OS user and
// the web-spawned copy as another (e.g. www-data) — see the topology_cloude branch's equivalent
// note, the same reasoning applies unchanged here. ----

define('TOPOLOGY_INGEST_RUNTIME_DIR', sys_get_temp_dir());
const INGEST_LOCK_FILE = TOPOLOGY_INGEST_RUNTIME_DIR.'/topology-tags-ingest.lock';
const INGEST_STATUS_FILE = TOPOLOGY_INGEST_RUNTIME_DIR.'/topology-tags-ingest-status.json';

function write_status(array $status): void {
	// Atomic-ish: write to a temp file then rename, so a concurrent GET /topology/ingest/status
	// read never sees a half-written file.
	$tmp = INGEST_STATUS_FILE.'.tmp';
	file_put_contents($tmp, json_encode($status, JSON_THROW_ON_ERROR));
	rename($tmp, INGEST_STATUS_FILE);
}

$lock_handle = fopen(INGEST_LOCK_FILE, 'c');
if ($lock_handle === false || !flock($lock_handle, LOCK_EX | LOCK_NB)) {
	fail('Ingest already running (lock held on '.INGEST_LOCK_FILE.') — exiting rather than running '.
		'concurrently or blocking. Try again once the in-progress run finishes.');
}
// Lock is held for the lifetime of this process; PHP releases it on exit (including a fatal
// error) — no explicit unlock call needed, and none would be safe to add mid-script.

$run_started_at = time();
write_status(['status' => 'running', 'started_at' => $run_started_at, 'finished_at' => null, 'summary' => null]);

register_shutdown_function(static function () use ($run_started_at) {
	// Catches every path that doesn't already write a terminal status itself (an uncaught
	// Throwable, a PHP fatal error, an early fail()/exit(1)) — otherwise the status file would
	// stay stuck on "running" forever, wedging the UI's poll loop (§14.2).
	$error = error_get_last();
	$current = @file_get_contents(INGEST_STATUS_FILE);
	$current = $current ? json_decode($current, true) : null;
	if ($current !== null && $current['status'] === 'running') {
		write_status(['status' => 'error', 'started_at' => $run_started_at, 'finished_at' => time(),
			'summary' => null,
			'error' => $error ? $error['message'] : 'ingest.php exited without reporting a final status']);
	}
});

$zabbix_host_filter = isset($options['zabbix-host']) ? (array) $options['zabbix-host'] : [];

// ---- Zabbix API bootstrap (GOTCHAS.md #3/#4) ----

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
	'userip' => '127.0.0.1', 'sessionid' => 'topology-ingest-cli', 'gui_access' => GROUP_GUI_ACCESS_SYSTEM,
	'debug_mode' => false, 'mfaid' => 0, 'timezone' => 'default'
];
CWebUser::$data = $userdata;
CApiService::$userData = $userdata;

// §9: prefer lldpRemPortDesc; only when empty, branch on lldpRemPortIdSubtype. Mirrors
// push.py's resolve_port_label()/the previous DB model's ingest.php — same rule, same reason.
function resolve_port_label(?string $remote_port_desc, ?string $remote_port_id,
		?string $remote_port_id_subtype): string {
	if ($remote_port_desc) {
		return $remote_port_desc;
	}
	if (in_array($remote_port_id_subtype, ['interfaceName', 'macAddress'], true) && $remote_port_id) {
		return $remote_port_id;
	}
	return $remote_port_id ?: 'unknown';
}

/**
 * §14.1's read-modify-write, specialized for discovery ingest: rebuilds the discovery-owned
 * namespace (chassis_id, type, port.* and neighbor.*) from $blob, leaves every non-topology tag
 * and topology.identity untouched. Returns the number of topology.* tags written, for the run
 * summary.
 */
function apply_ingest_blob(string $hostid, array $blob): int {
	$hosts = API::Host()->get(['hostids' => [$hostid], 'output' => [], 'selectTags' => 'extend']);
	$existing = $hosts ? $hosts[0]['tags'] : [];

	$kept = array_values(array_filter($existing, static function (array $tag): bool {
		return strncmp($tag['tag'], 'topology.', 9) !== 0 || $tag['tag'] === 'topology.identity';
	}));
	$kept = array_map(static fn(array $tag): array => ['tag' => $tag['tag'], 'value' => $tag['value']], $kept);

	$fresh = [];
	if (!empty($blob['reporter']['chassis_id'])) {
		$fresh[] = ['tag' => 'topology.chassis_id', 'value' => (string) $blob['reporter']['chassis_id']];
	}

	foreach ($blob['ports'] ?? [] as $port_blob) {
		$if_index = (int) $port_blob['if_index'];
		if (($port_blob['name'] ?? '') === '') {
			continue; // §8: topology.port.<ifIndex>.name is the minimum required property.
		}
		$fresh[] = ['tag' => "topology.port.{$if_index}.name", 'value' => (string) $port_blob['name']];
		if (!empty($port_blob['mac'])) {
			$fresh[] = ['tag' => "topology.port.{$if_index}.mac", 'value' => (string) $port_blob['mac']];
		}
		if (!empty($port_blob['oper_status'])) {
			$fresh[] = ['tag' => "topology.port.{$if_index}.status", 'value' => (string) $port_blob['oper_status']];
		}
		if (!empty($port_blob['if_type'])) {
			$fresh[] = ['tag' => "topology.port.{$if_index}.type", 'value' => (string) $port_blob['if_type']];
		}
	}

	foreach ($blob['neighbors'] ?? [] as $neighbor) {
		$chassis_id = $neighbor['remote_chassis_id'] ?? null;
		if (!$chassis_id) {
			continue; // §9: chassis_id is a minimum required property; nothing to resolve without it.
		}
		$if_index = (int) $neighbor['local_if_index'];
		$fresh[] = ['tag' => "topology.neighbor.{$if_index}.chassis_id", 'value' => (string) $chassis_id];
		$fresh[] = ['tag' => "topology.neighbor.{$if_index}.port", 'value' => resolve_port_label(
			$neighbor['remote_port_desc'] ?? null, $neighbor['remote_port_id'] ?? null,
			$neighbor['remote_port_id_subtype'] ?? null
		)];
		if (!empty($neighbor['remote_sysname'])) {
			$fresh[] = ['tag' => "topology.neighbor.{$if_index}.name", 'value' => (string) $neighbor['remote_sysname']];
		}
	}

	$tags = array_merge($kept, $fresh);
	$result = API::Host()->update([['hostid' => $hostid, 'tags' => $tags]]);
	if ($result === false) {
		throw new RuntimeException("host.update failed for hostid {$hostid}");
	}
	return count($fresh);
}

// ---- Testability hook (mirrors the previous DB model's ingest.php): lets a test script drive
// apply_ingest_blob()/resolve_port_label() directly against fixture Hosts without going through
// item.get/history.get or the file lock. Runs after every function above is defined and before
// the Zabbix API is queried for reporters, so nothing below this point executes when present.
if (defined('TOPOLOGY_INGEST_TEST_HOOK')) {
	(TOPOLOGY_INGEST_TEST_HOOK)(get_defined_vars());
	return;
}

// ---- Dynamic reporter discovery (§14): every Host carrying the topology.discovery.raw item,
// no static registry. --zabbix-host is a filter on top of this, never a substitute for it. ----

$items = API::Item()->get([
	'output' => ['itemid', 'hostid'],
	'filter' => ['key_' => 'topology.discovery.raw'],
	'templated' => false,
	'selectHosts' => ['host']
]);
$reporter_items = [];
foreach ($items as $item) {
	$reporter_items[] = [
		'host' => $item['hosts'][0]['host'] ?? ('hostid:'.$item['hostid']),
		'hostid' => $item['hostid'],
		'itemid' => $item['itemid']
	];
}
if ($zabbix_host_filter) {
	$reporter_items = array_values(array_filter($reporter_items,
		static fn(array $row): bool => in_array($row['host'], $zabbix_host_filter, true)));
}

if (!$reporter_items) {
	echo "No reporters found (no host carries the topology.discovery.raw item yet, or ".
		"--zabbix-host didn't match any onboarded reporter). Nothing to ingest.\n";
	write_status(['status' => 'done', 'started_at' => $run_started_at, 'finished_at' => time(),
		'summary' => ['reporters_processed' => 0, 'tags_written' => 0, 'errors' => 0], 'error' => null]);
	exit(0);
}

$processed = 0;
$errors = 0;
$tags_written_total = 0;

foreach ($reporter_items as $reporter_item) {
	$zabbix_host = $reporter_item['host'];
	echo "--- {$zabbix_host} ---\n";
	try {
		$history = API::History()->get([
			'output' => 'extend', 'itemids' => [$reporter_item['itemid']], 'history' => ITEM_VALUE_TYPE_TEXT,
			'sortfield' => 'clock', 'sortorder' => 'DESC', 'limit' => 1
		]);
		if (!$history) {
			echo "SKIP: topology.discovery.raw has no history yet for '{$zabbix_host}'\n";
			continue;
		}
		$blob = json_decode($history[0]['value'], true, 512, JSON_THROW_ON_ERROR);
		$tags_written = apply_ingest_blob((string) $reporter_item['hostid'], $blob);
		$tags_written_total += $tags_written;
		echo "OK: ingested blob for '{$zabbix_host}' — {$tags_written} topology.* tags written ".
			"(".count($blob['ports'] ?? [])." ports, ".count($blob['neighbors'] ?? [])." neighbor entries)\n";
		$processed++;
	}
	catch (Throwable $exception) {
		// One reporter's bad/unparseable blob must never abort the whole ingest run.
		fwrite(STDERR, "ERROR: failed to ingest '{$zabbix_host}': {$exception->getMessage()}\n");
		$errors++;
	}
}

echo "\nDone: {$processed} reporter(s) ingested, {$errors} failed.\n";

$ok = $errors === 0 || $processed > 0;
write_status([
	'status' => $errors === 0 ? 'done' : ($processed > 0 ? 'done' : 'error'),
	'started_at' => $run_started_at,
	'finished_at' => time(),
	'summary' => ['reporters_processed' => $processed, 'tags_written' => $tags_written_total, 'errors' => $errors],
	'error' => ($processed === 0 && $errors > 0) ? "{$errors} reporter(s) failed and none succeeded." : null
]);
exit($ok ? 0 : 1);
