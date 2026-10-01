#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * ingest.php — topology discovery "ingest" component (spec §4.1, Trapper delivery mechanism).
 *
 * Reads the latest `topology.discovery.raw` value per reporter (via the Zabbix API's
 * item.get/history.get), applies spec §3 exactly (evidence threshold / rule 1, MAC-only Device<->
 * Host/Proxy reconciliation / rule 2, never-delete-on-unlink / rule 3, upsert-by-natural-key / rule 4,
 * manual-link-never-downgraded / rule 5), and writes to topo_nodes/topo_edges.
 *
 * Runs standalone against the topology tables with plain PDO — the same approach as
 * database/topology/seed.php — rather than bootstrapping Zabbix's full DB/API layer
 * (GOTCHAS.md #8) for a one-off CLI script. The upsert helpers below ($device/$port/
 * $ensure_edge) are deliberately a close mirror of seed.php's; $ensure_physical_link() and
 * $reconcile_device() are a byte-for-byte-equivalent reimplementation of
 * CTopologyPrototype::upsertPhysicalLink() and ::reconcileHost()/::promote() — see the
 * comments on each for the exact correspondence. Keep it that way: don't let this drift into
 * a second, subtly different set of rules (per the brief's explicit warning).
 *
 * host_inventory (macaddress_a/macaddress_b) is read with a direct SQL join against
 * topo_nodes.host_ref, since ingest already holds a PDO connection to the very same Zabbix
 * database that host_inventory lives in — no second round trip through the Zabbix API is
 * needed for that part (§3.2's gotcha about host.get()'s selectInterfaces having no MAC field
 * doesn't apply here, since this never goes through selectInterfaces at all).
 *
 * Reporter discovery is dynamic (spec §4.1, "Reporter discovery must be dynamic"): by default
 * this script finds every reporter itself via a single item.get filtered on key
 * 'topology.discovery.raw' across all hosts — no config file enumerating reporters by hand.
 * --zabbix-host remains as an optional operator override to scope a run to specific host(s)
 * (e.g. ad-hoc testing) — it is a filter on top of dynamic discovery, not a replacement
 * registry, and omitting it (the default, no-args case) is what runs full dynamic discovery.
 *
 * Usage:
 *   ingest.php --api-url <url> --api-token <token> --pdo-dsn <dsn> --pdo-user <user> \
 *       [--pdo-password <password>] [--zabbix-host <host> ...]
 *
 * Env vars (fallbacks for the flags above): ZABBIX_API_URL, ZABBIX_API_TOKEN,
 * TOPOLOGY_PDO_DSN, TOPOLOGY_PDO_USER, TOPOLOGY_PDO_PASSWORD.
 */

function fail(string $message): void {
	fwrite(STDERR, $message."\n");
	exit(1);
}

$options = getopt('', ['api-url:', 'api-token:', 'pdo-dsn:', 'pdo-user:', 'pdo-password:',
	'zabbix-host:', 'reporter-order:', 'help']);

if (isset($options['help'])) {
	fwrite(STDOUT, "See the file header for usage.\n");
	exit(0);
}

// ---- Run lock + status file (spec §6/§7: shared by the CLI and the web controller that spawns this
// same script — adding it once here, at the entrypoint every caller goes through, covers both without
// any separate locking code on the API path). Deliberately NOT next to this script (__DIR__, inside
// the git-tracked source tree): a real deployment runs the CLI as one OS user (an operator's shell)
// and the web-spawned copy as another (e.g. www-data under Apache/PHP-FPM), and a source directory is
// commonly owned by the former with no write access for the latter — confirmed the hard way against
// this box's own Apache vhost (docroot owned by a human user, group-writable but www-data isn't a
// member): the web-triggered run's fopen() on the lock file silently failed under www-data, so it hit
// the "already running" fail() path immediately, produced no new lock/status/log file, and the status
// endpoint kept serving a stale (from an earlier, same-OS-user) "done" result — the UI reported success
// for a run that never actually happened. sys_get_temp_dir() (world-writable, sticky bit) is a
// reliable common ground regardless of which OS user runs which caller. Acquired after --help (which
// should never be blocked by an in-progress run) but before argument validation, so even a malformed
// invocation can't race a real run — it just fails fast under the lock and the shutdown handler below
// turns that into a clean "error" status rather than leaving "running" stuck. ----

define('TOPOLOGY_INGEST_RUNTIME_DIR', sys_get_temp_dir());
const INGEST_LOCK_FILE = TOPOLOGY_INGEST_RUNTIME_DIR.'/topology-ingest.lock';
const INGEST_STATUS_FILE = TOPOLOGY_INGEST_RUNTIME_DIR.'/topology-ingest-status.json';
// Identifies this run in the status file: a caller that has just started a run can tell "my run" from the previous
// one (started_at has a resolution of one second, so two runs can share it).
define('INGEST_RUN_ID', bin2hex(random_bytes(6)));

function write_status(array $status): void {
	$status['run_id'] = INGEST_RUN_ID;
	// Atomic-ish: write to a temp file then rename, so a concurrent GET /topo/ingest/status read never
	// sees a half-written file.
	$tmp = INGEST_STATUS_FILE.'.tmp';
	file_put_contents($tmp, json_encode($status, JSON_THROW_ON_ERROR));
	rename($tmp, INGEST_STATUS_FILE);
}

$lock_handle = fopen(INGEST_LOCK_FILE, 'c');
if ($lock_handle === false || !flock($lock_handle, LOCK_EX | LOCK_NB)) {
	fail('Ingest already running (lock held on '.INGEST_LOCK_FILE.') — exiting rather than running '.
		'concurrently or blocking. Try again once the in-progress run finishes.');
}
// Lock is held for the lifetime of this process ($lock_handle stays open; PHP releases it on exit,
// including on a fatal error) — no explicit unlock call needed, and none is safe to add mid-script
// since a PHP fatal error would then skip it anyway.

$run_started_at = time();
write_status(['status' => 'running', 'started_at' => $run_started_at, 'finished_at' => null, 'summary' => null]);

register_shutdown_function(static function () use ($run_started_at) {
	// Catches every path that doesn't already write a terminal status itself: an uncaught Throwable
	// escaping the whole script, a PHP fatal error (e.g. OOM), or an early fail()/exit(1) for bad args
	// — all would otherwise leave the status file stuck on "running" forever, wedging the UI's poll loop.
	$error = error_get_last();
	$current = @file_get_contents(INGEST_STATUS_FILE);
	$current = $current ? json_decode($current, true) : null;
	if ($current !== null && $current['status'] === 'running') {
		write_status(['status' => 'error', 'started_at' => $run_started_at, 'finished_at' => time(),
			'summary' => null,
			'error' => $error ? $error['message'] : 'ingest.php exited without reporting a final status']);
	}
});

$api_url = $options['api-url'] ?? getenv('ZABBIX_API_URL') ?: null;
$api_token = $options['api-token'] ?? getenv('ZABBIX_API_TOKEN') ?: null;
$pdo_dsn = $options['pdo-dsn'] ?? getenv('TOPOLOGY_PDO_DSN') ?: null;
$pdo_user = $options['pdo-user'] ?? getenv('TOPOLOGY_PDO_USER') ?: null;
$pdo_password = $options['pdo-password'] ?? getenv('TOPOLOGY_PDO_PASSWORD') ?: '';

if (!$pdo_dsn || !$pdo_user) {
	fail("Usage: {$argv[0]} --pdo-dsn <dsn> --pdo-user <user> [--pdo-password <password>] ".
		"[--api-url <url> --api-token <token>] [--zabbix-host <host> ...]\n\n".
		"The API arguments are only needed for the legacy Trapper-blob reporters (hosts with the ".
		"topology.discovery.raw item); hosts with LLD snapshots (topo_lld_snapshot) need only the database.\n\n".
		"Credentials may also come from ZABBIX_API_URL / ZABBIX_API_TOKEN / TOPOLOGY_PDO_DSN / ".
		"TOPOLOGY_PDO_USER / TOPOLOGY_PDO_PASSWORD env vars — never hardcode them in a script ".
		"(see topo-change-sender.sh at the repo root for the anti-pattern this avoids).\n\n".
		"Reporters are discovered dynamically via item.get (spec §4.1) — no reporter list needed. ".
		"--zabbix-host optionally scopes a run to specific host(s) for ad-hoc testing.");
}

// Optional operator scoping — a filter on top of dynamic discovery, never a substitute for it (see the
// file header). Empty means "no filter": every host carrying topology.discovery.raw is a reporter.
$zabbix_host_filter = isset($options['zabbix-host']) ? (array) $options['zabbix-host'] : [];

// ---- Zabbix API (read-only: host.get/item.get/history.get) ----

final class ZabbixApi {
	private int $request_id = 0;

	public function __construct(private string $url, private string $token) {
	}

	public function call(string $method, array $params): mixed {
		$this->request_id++;
		$payload = json_encode(['jsonrpc' => '2.0', 'method' => $method, 'params' => $params,
			'id' => $this->request_id], JSON_THROW_ON_ERROR);

		$context = stream_context_create(['http' => [
			'method' => 'POST',
			'header' => "Content-Type: application/json-rpc\r\nAuthorization: Bearer {$this->token}\r\n",
			'content' => $payload,
			'timeout' => 15,
			'ignore_errors' => true,
		]]);
		$response = file_get_contents($this->url, false, $context);
		if ($response === false) {
			throw new RuntimeException("Zabbix API request to {$method} failed (no response).");
		}
		$decoded = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
		if (isset($decoded['error'])) {
			throw new RuntimeException("Zabbix API {$method} failed: ".json_encode($decoded['error']));
		}
		return $decoded['result'];
	}

	/** Spec §4.1 "Reporter discovery must be dynamic": finds every reporter by querying item.get for the
	 * topology.discovery.raw key across ALL hosts — no hostids filter, no config file. Every host carrying
	 * that item (i.e. every host onboarded with the Topology Discovery Reporter template) is automatically
	 * a reporter for this run. Returns one row per matching item: ['host' => ..., 'hostid' => ..., 'itemid' => ...]. */
	public function findAllReporterItems(): array {
		$items = $this->call('item.get', [
			'output' => ['itemid', 'hostid'],
			'filter' => ['key_' => 'topology.discovery.raw'],
			// templated => false: item.get otherwise also returns the item as defined on the template
			// itself (a pseudo-"host" row for the template, e.g. "Topology Discovery Reporter") — only
			// items actually inherited onto a real reporter Host count as reporters.
			'templated' => false,
			'selectHosts' => ['host'],
		]);
		$rows = [];
		foreach ($items as $item) {
			$rows[] = [
				'host' => $item['hosts'][0]['host'] ?? null,
				'hostid' => $item['hostid'],
				'itemid' => $item['itemid'],
			];
		}
		return $rows;
	}

	/** Latest text history value for an item, or null if it has never received one. */
	public function latestValue(string $itemid): ?string {
		$history = $this->call('history.get', ['output' => 'extend', 'itemids' => [$itemid],
			'history' => 4, 'sortfield' => 'clock', 'sortorder' => 'DESC', 'limit' => 1]);
		return $history ? $history[0]['value'] : null;
	}
}

// ---- DB layer: mirrors seed.php's upsert helpers, plus the two pieces seed.php doesn't need
// (physical_link's no-downgrade rule, and Device<->Host/Proxy reconciliation) ----

$pdo = new PDO($pdo_dsn, $pdo_user, $pdo_password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$now = time();

// Rule 4's third Device-match key (chassis_id -> mgmt_ip -> sysname). The sysname key is deliberately NOT a
// global "WHERE sysname = ?" scan (that would risk merging two unrelated devices that happen to share a
// sysname): it only matches a Device that was previously created/matched as the neighbor discovered on this
// exact (reporter, local_if_index) pair — i.e. a Device already reachable by walking the physical_link edge
// off $local_port_id (the local reporter Port at that if_index) to its far-side Port, then that Port's
// owning Device via device_id. $local_port_id/$sysname are only ever passed for neighbor Devices (see the
// $device() calls below) — the reporter Device itself is never matched this way.
$find_device = static function (?string $chassis_id, ?string $mgmt_ip, ?int $local_port_id, ?string $sysname) use ($pdo): ?array {
	if ($chassis_id) {
		$stmt = $pdo->prepare("SELECT id FROM topo_nodes WHERE type = 'device' AND JSON_UNQUOTE(JSON_EXTRACT(attrs, '\$.chassis_id')) = ?");
		$stmt->execute([$chassis_id]);
		if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
			return [(int) $row['id'], 'chassis_id'];
		}
	}
	if ($mgmt_ip) {
		$stmt = $pdo->prepare("SELECT id FROM topo_nodes WHERE type = 'device' AND JSON_UNQUOTE(JSON_EXTRACT(attrs, '\$.mgmt_ip')) = ?");
		$stmt->execute([$mgmt_ip]);
		if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
			return [(int) $row['id'], 'mgmt_ip'];
		}
	}
	if ($local_port_id !== null && $sysname) {
		$stmt = $pdo->prepare(
			"SELECT dev.id FROM topo_edges link".
			" JOIN topo_nodes port ON port.id = (CASE WHEN link.src_id = ? THEN link.dst_id ELSE link.src_id END)".
			" JOIN topo_nodes dev ON dev.id = port.device_id".
			" WHERE link.type = 'physical_link' AND (link.src_id = ? OR link.dst_id = ?)".
			" AND port.type = 'port' AND dev.type = 'device'".
			" AND JSON_UNQUOTE(JSON_EXTRACT(dev.attrs, '\$.sysname')) = ?"
		);
		$stmt->execute([$local_port_id, $local_port_id, $local_port_id, $sysname]);
		if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
			return [(int) $row['id'], 'sysname'];
		}
	}
	return null;
};

$find_port = static function (int $device_id, int $if_index) use ($pdo): ?int {
	$stmt = $pdo->prepare("SELECT id FROM topo_nodes".
		" WHERE device_id = ? AND type = 'port' AND JSON_EXTRACT(attrs, '\$.if_index') = ?");
	$stmt->execute([$device_id, $if_index]);
	$row = $stmt->fetch(PDO::FETCH_ASSOC);
	return $row ? (int) $row['id'] : null;
};

$insert_node = $pdo->prepare('INSERT INTO topo_nodes (type, attrs, created_at, updated_at) VALUES (?, ?, ?, ?)');
$insert_port_node = $pdo->prepare('INSERT INTO topo_nodes (type, device_id, attrs, created_at, updated_at) VALUES (?, ?, ?, ?, ?)');
$update_node = $pdo->prepare('UPDATE topo_nodes SET attrs = ?, updated_at = ? WHERE id = ?');
$insert_edge = $pdo->prepare('INSERT INTO topo_edges (type, src_id, dst_id, attrs, created_at) VALUES (?, ?, ?, ?, ?)');
$last_insert_id = static function () use ($pdo): int {
	return (int) $pdo->lastInsertId($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql' ? 'topo_nodes_id_seq' : null);
};

// Rule 4: upsert Device by chassis_id, else mgmt_ip, else (neighbor Devices only, via $local_port_id) sysname
// scoped to (reporter, local_if_index) — see $find_device's comment. Mirrors seed.php's $device(), plus the
// third key seed.php doesn't have (seed.php's fixture data always carries chassis_id, so it never needs it).
// When the sysname key is what resolved the match, attrs.matched_by is stamped 'sysname' per rule 4 — the
// chassis_id/mgmt_ip matches don't get a matched_by (no existing convention for one; nothing else in this
// codebase records "how a Device was matched" outside rule 4's own sysname-weak-signal requirement).
// $summary tallies create-vs-update counts for the §6 /topo/ingest/status endpoint. Just counting,
// no change to the matching/upsert rules themselves.
$summary = ['devices_created' => 0, 'devices_updated' => 0, 'ports_created' => 0, 'links_created' => 0];

$device = static function (array $attrs, ?int $local_port_id = null) use ($pdo, $insert_node, $update_node, $now, $last_insert_id, $find_device, &$summary): int {
	$match = $find_device($attrs['chassis_id'] ?? null, $attrs['mgmt_ip'] ?? null, $local_port_id, $attrs['sysname'] ?? null);
	if ($match !== null) {
		[$existing_id, $matched_by] = $match;
		// A neighbor sighting carries less than the Device's own report (lldpRemTable has no mgmt IP, vendor is
		// unknown): merge instead of overwriting, or a later neighbor sighting from another reporter would wipe
		// the mgmt_ip/vendor/sysname the Device's own push recorded — making the result depend on reporter order.
		$existing = $pdo->prepare('SELECT attrs FROM topo_nodes WHERE id = ?');
		$existing->execute([$existing_id]);
		$before = json_decode((string) $existing->fetchColumn(), true) ?: [];
		foreach ($attrs as $key => $incoming) {
			if (($incoming === null || $incoming === '' || ($key === 'vendor' && $incoming === 'unknown'))
					&& ($before[$key] ?? null) !== null && $before[$key] !== '') {
				$attrs[$key] = $before[$key];
			}
		}
		if ($matched_by === 'sysname') {
			$attrs['matched_by'] = 'sysname';
		}
		$update_node->execute([json_encode($attrs, JSON_THROW_ON_ERROR), $now, $existing_id]);
		$summary['devices_updated']++;
		return $existing_id;
	}
	$insert_node->execute(['device', json_encode($attrs, JSON_THROW_ON_ERROR), $now, $now]);
	$summary['devices_created']++;
	return $last_insert_id();
};

// Rule 4: upsert Port by (device_id, if_index), setting device_id directly on insert. Mirrors seed.php's $port().
$port = static function (int $device_id, array $attrs) use ($insert_port_node, $update_node, $now, $last_insert_id, $find_port, &$summary): int {
	$existing_id = $find_port($device_id, $attrs['if_index']);
	if ($existing_id !== null) {
		$update_node->execute([json_encode($attrs, JSON_THROW_ON_ERROR), $now, $existing_id]);
		return $existing_id;
	}
	$insert_port_node->execute(['port', $device_id, json_encode($attrs, JSON_THROW_ON_ERROR), $now, $now]);
	$port_id = $last_insert_id();
	$summary['ports_created']++;
	return $port_id;
};

// Byte-for-byte mirror of CTopologyPrototype::upsertPhysicalLink() (ui/include/classes/topology/
// CTopologyPrototype.php ~line 680): canonicalize direction (smaller Port id -> src_id), never let a
// repeated 'lldp' call downgrade a 'manual' edge (only lldp->lldp or manual->lldp upgrade is written), and
// never let a repeated 'manual' call overwrite an existing edge at all. Discovery must never delete a
// manually-created link (rule 5) — this function has no delete path, only upsert, satisfying that by
// construction.
//
// $reporter_port_id (spec §2.3): the port belonging to the reporter whose blob is being processed right
// now — always $local_port_id at the one call site below, never the neighbor's (possibly pseudo-) port.
// Once direction is canonicalized, that tells us which of last_seen_src/last_seen_dst is "this reporter's
// side" of the edge, so a reporter whose push pipeline goes stale only stops advancing its own side —
// the other reporter (if any) keeps confirming its side independently, and the aggregate last_seen (used
// by §7's staleness indicator, unchanged) stays max(last_seen_src, last_seen_dst). Only ever 'lldp' here;
// CTopologyPrototype::upsertPhysicalLink()'s 'manual' path has no reporter and leaves both fields unset.
$ensure_physical_link = static function (int $port_a, int $port_b, string $discovered_via, int $reporter_port_id) use ($pdo, $insert_edge, $now, &$summary): void {
	$reporter_is_a = $reporter_port_id === $port_a;
	if ($port_a > $port_b) {
		[$port_a, $port_b] = [$port_b, $port_a];
		$reporter_is_a = !$reporter_is_a;
	}
	$reporter_side = $reporter_is_a ? 'last_seen_src' : 'last_seen_dst';

	$stmt = $pdo->prepare("SELECT id, attrs FROM topo_edges WHERE type = 'physical_link' AND src_id = ? AND dst_id = ?");
	$stmt->execute([$port_a, $port_b]);
	if ($existing = $stmt->fetch(PDO::FETCH_ASSOC)) {
		$attrs = json_decode($existing['attrs'], true, 512, JSON_THROW_ON_ERROR) ?: [];
		if ($discovered_via === 'lldp' && ($attrs['discovered_via'] ?? null) !== 'lldp') {
			$attrs['discovered_via'] = 'lldp';
		}
		if ($discovered_via === 'lldp') {
			// Update only this reporter's own side — the other side's last_seen_* is left exactly as
			// it was, so a dead reporter on the other end shows up as that side going stale even while
			// this confirmation keeps landing.
			$attrs[$reporter_side] = $now;
			$attrs['last_seen'] = max($attrs['last_seen_src'] ?? 0, $attrs['last_seen_dst'] ?? 0);
			$pdo->prepare('UPDATE topo_edges SET attrs = ? WHERE id = ?')
				->execute([json_encode($attrs, JSON_THROW_ON_ERROR), $existing['id']]);
		}
		// discovered_via === 'manual' on an existing edge (of either provenance): no-op by design
		// (never downgrade, never duplicate, never touch the per-side fields).
		return;
	}
	$attrs = ['discovered_via' => $discovered_via];
	if ($discovered_via === 'lldp') {
		$attrs[$reporter_side] = $now;
		$attrs['last_seen'] = $now;
	}
	else {
		// 'manual': no reporter confirmation cycle — last_seen/last_seen_src/last_seen_dst all stay
		// unset rather than being zero-initialized or defaulted to "now" (spec §2.3).
		$attrs['last_seen'] = $now;
	}
	$insert_edge->execute(['physical_link', $port_a, $port_b, json_encode($attrs, JSON_THROW_ON_ERROR), $now]);
	$summary['links_created']++;
};

// Byte-for-byte mirror of CTopologyPrototype::promote()'s 1:1-guard + write, called only from
// $reconcile_device() below with matched_by='mac' (never 'manual' — manual promotion is the separate
// /promote endpoint's job, §2.3, not ingest's) and from the reporter_self call in the main loop below
// with matched_by='reporter_self'. §2.1/§2.3: represented_by is stored as three columns directly on
// the Device's own topo_nodes row, not a topo_edges row — matched_mac is deliberately not accepted or
// stored here (no consumer in the new column-based model, per the spec addendum).
$promote = static function (int $device_id, int $target_nodeid, string $matched_by) use ($pdo, $now): bool {
	$represented = $pdo->prepare("SELECT 1 FROM topo_nodes WHERE id = ? AND represented_by_node_id IS NOT NULL");
	$represented->execute([$device_id]);
	if ($represented->fetch()) {
		return false; // this Device is already represented — not an error, just no match (§2.3 1:1).
	}
	$represented_target = $pdo->prepare(
		"SELECT 1 FROM topo_nodes WHERE type = 'device' AND represented_by_node_id = ?");
	$represented_target->execute([$target_nodeid]);
	if ($represented_target->fetch()) {
		return false; // this Host/Proxy is already represented by some other Device.
	}
	$pdo->prepare(
		"UPDATE topo_nodes SET represented_by_node_id = ?, represented_by_matched_by = ?, represented_by_at = ?".
		" WHERE id = ? AND type = 'device'"
	)->execute([$target_nodeid, $matched_by, $now, $device_id]);
	return true;
};

// Spec §2.3/§4.1: find-or-create the Host pointer node for a reporter's own hostid, scoped to the new
// deterministic reporter-self-link path only (see the $promote() call in the main loop below) — NOT used
// by $reconcile_device()'s neighbor-MAC path, which must keep only ever SELECTing an existing host node
// (a neighbor reconciling against a Host that was never pulled via the "Pull Zabbix hosts" button is
// correctly a non-match, per §3.2's "opportunistic" framing; that stays unchanged). Mirrors
// CTopologyPrototype::upsertPointerNode() (ui/include/classes/topology/CTopologyPrototype.php ~line 709):
// look up topo_nodes by (type='host', host_ref=hostid), insert {type:'host', host_ref:hostid, attrs:'{}'}
// if missing. This is the whole point of the reporter-self-link change: ingest already knows the exact
// hostid with certainty (from the item.get call that located this reporter, §4.1), so linking the
// reporter's Device to its own Host must not depend on "Pull Zabbix hosts" having been run first.
$find_or_create_host_node = static function (string $hostid) use ($pdo, $now, $last_insert_id): int {
	$stmt = $pdo->prepare("SELECT id FROM topo_nodes WHERE type = 'host' AND host_ref = ?");
	$stmt->execute([$hostid]);
	if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
		return (int) $row['id'];
	}
	$pdo->prepare('INSERT INTO topo_nodes (type, host_ref, attrs, created_at, updated_at) VALUES (?, ?, ?, ?, ?)')
		->execute(['host', $hostid, '{}', $now, $now]);
	return $last_insert_id();
};

// Byte-for-byte mirror of CTopologyPrototype::reconcileHost()'s MAC lookup (Port.attrs.mac via
// host_inventory.macaddress_a/b) — run per Device instead of per Host, since ingest discovers/touches
// Devices, not Hosts (§5's Host/Proxy pull is a separate, already-existing pull path this script doesn't
// duplicate). Only Host is reconciled against, not Proxy — proxy.get/CProxy::get exposes no MAC/inventory
// concept at all, so there is nothing to reconcile a Proxy against here either (a Proxy node is only ever
// created via manual /promote, §5/§6). Rule 2 (spec §3): matching is MAC-only — there is no generic Zabbix host
// field carrying an LLDP chassis ID (host_inventory.chassis is an unrelated free-text field), so
// $device_attrs is unused here now; it stays a parameter only because callers pass it (see the neighbor
// Device call below), not because this function reads it.
$reconcile_device = static function (int $device_id, array $device_attrs, array $port_macs) use ($pdo, $promote): void {
	foreach ($port_macs as $mac) {
		if (!$mac) {
			continue;
		}
		$mac = strtolower($mac);
		$stmt = $pdo->prepare("SELECT node.id FROM topo_nodes node".
			" JOIN host_inventory hi ON hi.hostid = node.host_ref".
			" WHERE node.type = 'host' AND (LOWER(hi.macaddress_a) = ? OR LOWER(hi.macaddress_b) = ?)");
		$stmt->execute([$mac, $mac]);
		while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
			$promote($device_id, (int) $row['id'], 'mac');
		}
	}
};

// Mirror of push.py's resolve_port_label() (see that file's docstring for why the rule lives in both
// places): prefer lldpRemPortDesc; only when empty, branch on lldpRemPortIdSubtype.
$resolve_port_label = static function (?string $remote_port_desc, ?string $remote_port_id, ?string $remote_port_id_subtype): string {
	if ($remote_port_desc) {
		return $remote_port_desc;
	}
	if (in_array($remote_port_id_subtype, ['interfaceName', 'macAddress'], true) && $remote_port_id) {
		return $remote_port_id;
	}
	return $remote_port_id ?: 'unknown';
};

// LLDP's remote_port_id is a string (interface name, MAC, locally-assigned number — depending on
// remote_port_id_subtype), not a numeric SNMP ifIndex, except when the subtype is literally 'local' and
// the value happens to be numeric. Rule 4's Port upsert key needs *a* stable if_index-shaped number for
// the neighbor's port, so this derives one deterministically from remote_port_id when a real numeric
// ifIndex isn't available — same value every run (crc32 is stable), so upsert idempotency (rule 4) still
// holds even though it is NOT a genuine SNMP ifIndex. Flagged as a known gap: the real ifIndex of a
// neighbor's port is never actually discoverable from lldpRemTable alone (see the delivery report).
$pseudo_if_index = static function (?string $remote_port_id, ?string $remote_port_id_subtype): int {
	if ($remote_port_id_subtype === 'local' && $remote_port_id !== null && ctype_digit($remote_port_id)) {
		return (int) $remote_port_id;
	}
	return (int) (crc32((string) $remote_port_id) % 1000000);
};

// Spec §3 rule 4 "pseudo-port merge" / §2.3's "canonicalization alone does not prevent a same-cable
// duplicate" paragraph. Normalizes an SNMP ifDescr/ifName-style port name to a vendor-abbreviation-
// independent, case-insensitive canonical form so e.g. "GigabitEthernet0/24" (lldpRemPortDesc's long form,
// see the lab's *.snmprec ifDescr/lldpRemPortDesc rows) and "Gi0/24" (ifName's short form) compare equal.
// The Gi/GigabitEthernet pair is the one actually exercised by this repo's snmpdata/ lab fixtures (grepped
// for ifDescr/ifName/lldpRemPortDesc); Te/TenGigabitEthernet, Fa/FastEthernet and Po/Port-channel aren't
// present in the fixtures but are the same well-known Cisco IOS ifDescr long-form convention, named
// explicitly in the spec text this implements — kept here, not factored into a general-purpose utility
// elsewhere, since nothing else in this file needs vendor name normalization (per the brief's scope note).
$normalize_port_name = static function (string $name): string {
	static $long_to_short = [
		'gigabitethernet' => 'gi',
		'tengigabitethernet' => 'te',
		'fastethernet' => 'fa',
		'port-channel' => 'po',
	];
	$lower = strtolower(trim($name));
	foreach ($long_to_short as $long => $short) {
		if (strncmp($lower, $long, strlen($long)) === 0) {
			return $short.substr($lower, strlen($long));
		}
	}
	return $lower;
};

// Spec §3 rule 4: run ONLY right after upserting a reporter's own real Port (never from the neighbor
// pseudo-Port creation path below — that path is unaffected, per scope). Looks for an existing pseudo-Port
// (attrs.pseudo = true, §2.2) on the same Device whose name normalizes to the same thing as the real Port
// just upserted. Exactly one match: the pseudo-Port was standing in for this exact physical port before
// this Device ever pushed its own data — re-point its physical_link edge(s) onto the real Port and delete
// it. Zero or more-than-one match: do nothing (never guess — §3 rule 4's explicit instruction), just log it
// so it isn't silently missed either way.
$merge_pseudo_port = static function (int $reporter_device_id, int $real_port_id, string $real_port_name) use ($pdo, $normalize_port_name): void {
	$normalized_real = $normalize_port_name($real_port_name);

	$stmt = $pdo->prepare(
		"SELECT id, JSON_UNQUOTE(JSON_EXTRACT(attrs, '\$.name')) AS name FROM topo_nodes".
		" WHERE device_id = ? AND type = 'port' AND id != ?".
		" AND JSON_EXTRACT(attrs, '\$.pseudo') = true");
	$stmt->execute([$reporter_device_id, $real_port_id]);
	$pseudo_ports = $stmt->fetchAll(PDO::FETCH_ASSOC);

	$matches = array_values(array_filter($pseudo_ports,
		static fn (array $p): bool => $normalize_port_name((string) $p['name']) === $normalized_real));

	if (count($matches) !== 1) {
		if (count($matches) > 1) {
			$ids = implode(', ', array_map(static fn (array $p): string => '#'.$p['id'], $matches));
			echo "PSEUDO-MERGE SKIP: device #{$reporter_device_id} has ".count($matches)." pseudo-Port(s) ".
				"({$ids}) whose name normalizes to match real Port #{$real_port_id} ('{$real_port_name}') — ".
				"ambiguous, leaving all of them in place (spec §3 rule 4: never guess).\n";
		}
		return; // zero matches: nothing to merge, not worth logging (the ordinary/common case).
	}

	$pseudo_port_id = (int) $matches[0]['id'];

	$edges_stmt = $pdo->prepare(
		"SELECT id, src_id, dst_id, attrs FROM topo_edges WHERE type = 'physical_link' AND (src_id = ? OR dst_id = ?)");
	$edges_stmt->execute([$pseudo_port_id, $pseudo_port_id]);
	$edges = $edges_stmt->fetchAll(PDO::FETCH_ASSOC);

	$all_repointed = true;
	foreach ($edges as $edge) {
		$other_id = ((int) $edge['src_id'] === $pseudo_port_id) ? (int) $edge['dst_id'] : (int) $edge['src_id'];
		if ($other_id === $real_port_id) {
			// Pseudo-Port and real Port were somehow already directly linked to each other — nothing
			// sensible to "merge" here, just drop the now-redundant edge.
			$pdo->prepare('DELETE FROM topo_edges WHERE id = ?')->execute([$edge['id']]);
			continue;
		}
		// §2.3: src_id is always the numerically smaller Port id — re-canonicalize for the new endpoint.
		$pseudo_was_src = (int) $edge['src_id'] === $pseudo_port_id;
		[$new_src, $new_dst] = $other_id < $real_port_id ? [$other_id, $real_port_id] : [$real_port_id, $other_id];
		$real_is_src = $new_src === $real_port_id;

		// last_seen_src/last_seen_dst (§2.3) are keyed to canonical src/dst position, not to a stable
		// port identity — if replacing the pseudo-Port with the real one flips which of {other_id,
		// real_port_id} is numerically smaller, the side that used to be "src" is now "dst" and vice
		// versa. Swap the two fields along with src_id/dst_id so a reporter's already-recorded
		// confirmation stays attributed to its own physical port, not to whichever side happens to be
		// numerically smaller after the merge.
		$attrs = json_decode($edge['attrs'], true, 512, JSON_THROW_ON_ERROR) ?: [];
		if ($pseudo_was_src !== $real_is_src
				&& (array_key_exists('last_seen_src', $attrs) || array_key_exists('last_seen_dst', $attrs))) {
			[$attrs['last_seen_src'], $attrs['last_seen_dst']] =
				[$attrs['last_seen_dst'] ?? null, $attrs['last_seen_src'] ?? null];
		}

		// Order-independence (§8): the OTHER side of this same cable may have already run its own merge
		// first (e.g. real-real edge 167<->171 already exists because Switch1's pass merged its pseudo-Port
		// into Router1's real Port before Router1's own pass got a chance to merge the mirror-image
		// pseudo-Port pointing back). That leaves this pseudo-Port's edge fully redundant, not ambiguous —
		// dropping it (rather than trying to UPDATE onto an already-taken pair, which would just violate
		// topo_edges_physical_link_pair_uq) is what lets a second/later ingest pass still converge instead
		// of getting stuck retrying the same collision forever.
		$dup = $pdo->prepare(
			"SELECT id FROM topo_edges WHERE type = 'physical_link' AND src_id = ? AND dst_id = ? AND id != ?");
		$dup->execute([$new_src, $new_dst, $edge['id']]);
		if ($dup->fetch()) {
			$pdo->prepare('DELETE FROM topo_edges WHERE id = ?')->execute([$edge['id']]);
			continue;
		}

		try {
			$pdo->prepare('UPDATE topo_edges SET src_id = ?, dst_id = ?, attrs = ? WHERE id = ?')
				->execute([$new_src, $new_dst, json_encode($attrs, JSON_THROW_ON_ERROR), $edge['id']]);
		}
		catch (PDOException $exception) {
			// §2.3: a Port can have at most one active physical_link. This is the genuinely pathological
			// case left after the duplicate-pair check above: the real Port already has some OTHER active
			// physical_link. Log and leave this pseudo-Port alone rather than letting a constraint
			// violation escape and abort the whole reporter's ingest transaction (caught locally here, not
			// left to the outer per-reporter catch).
			echo "PSEUDO-MERGE SKIP: could not re-point physical_link edge #{$edge['id']} from pseudo-Port ".
				"#{$pseudo_port_id} onto real Port #{$real_port_id} ({$exception->getMessage()}) — leaving ".
				"the pseudo-Port in place.\n";
			$all_repointed = false;
		}
	}

	if (!$all_repointed) {
		return;
	}

	$pdo->prepare("DELETE FROM topo_nodes WHERE id = ? AND type = 'port'")->execute([$pseudo_port_id]);
	echo "OK: merged pseudo-Port #{$pseudo_port_id} into real Port #{$real_port_id} ('{$real_port_name}') ".
		"on device #{$reporter_device_id}\n";
};

// The symmetric half $merge_pseudo_port() alone doesn't cover: that function only runs while upserting a
// reporter's OWN real ports, so it only cleans up a pseudo-Port that already existed *before* this pass.
// It does nothing to stop the neighbor-port-creation code below (unconditional, per rule 1) from
// fabricating a *fresh* pseudo-Port on a neighbor that already has a matching real port from some earlier
// pass — e.g. Router1 processed first in this run (its own merge already ran and found nothing to do
// yet), then Switch1 processed later in the same run mentions Router1 as a neighbor and would otherwise
// mint a brand-new pseudo-Port right next to Router1's real one, every single run, forever (confirmed live:
// without this, the same duplicate reappears under a new id after every ingest pass, never actually
// converging). This is the mirror-image check, run *before* fabricating a neighbor pseudo-Port at all: if
// the neighbor Device already has a real (non-pseudo) Port whose name normalizes to match, use that real
// Port's id directly and never create a pseudo one in the first place. Returns null when there's no such
// real port (the ordinary case — proceed with pseudo-Port creation as before).
$find_matching_real_port = static function (int $device_id, string $name, ?bool &$ambiguous = null) use ($pdo, $normalize_port_name): ?int {
	$normalized = $normalize_port_name($name);
	$stmt = $pdo->prepare(
		"SELECT id, JSON_UNQUOTE(JSON_EXTRACT(attrs, '\$.name')) AS name FROM topo_nodes".
		" WHERE device_id = ? AND type = 'port' AND JSON_EXTRACT(attrs, '\$.pseudo') = false");
	$stmt->execute([$device_id]);
	$matches = array_values(array_filter($stmt->fetchAll(PDO::FETCH_ASSOC),
		static fn (array $p): bool => $normalize_port_name((string) $p['name']) === $normalized));
	// Same "never guess" rule as $merge_pseudo_port(): only act on an unambiguous single match. $ambiguous
	// tells a caller that cares (the LLD snapshot path records it) apart from "no candidate at all".
	$ambiguous = count($matches) > 1;
	return count($matches) === 1 ? (int) $matches[0]['id'] : null;
};

// ============================================================================================================
// LLD snapshot path (topology-lld-part2-spec.md). A host with rows in topo_lld_snapshot is a reporter whose
// data comes from discovery rules with a topology_role instead of the Trapper blob above. Everything below
// reuses the closures defined earlier (device/port matching, pseudo-port merge, promote, reconcile) — only the
// input shape and the per-role handling are new. Every last_seen written here comes from the snapshot's own
// clock, never from $now (spec §2 rule 3): running ingest twice over unchanged snapshots changes nothing.
// ============================================================================================================

const TOPO_ROLE_PORTS = 1;
const TOPO_ROLE_NEIGHBORS = 2;
const TOPO_ROLE_LEARNED_MACS = 3;
const TOPO_ROLE_LAG = 4;

$summary += [
	'reporters_processed' => 0,
	'reporters_skipped' => [],
	'snapshots_ignored_role_mismatch' => 0,
	'observations' => ['applied' => 0, 'device_only' => 0, 'shadowed' => 0, 'conflict' => 0, 'ambiguous' => 0],
	// topology-link-replacement-spec.md §7: per-run counts of the discovered-vs-discovered replacement rule.
	'links_superseded' => 0,
	'links_revived' => 0,
	'ports_ambiguous' => 0,
	// topology-manual-contradiction-spec.md §7: manual links that discovery contradicts and nobody acknowledged.
	'manual_links_contradicted' => 0,
	// Observations of rules that have no usable snapshot any more (rule or host disabled, role changed, reporter skipped,
	// snapshot gone): removed by a full run, see $clean_stale_observations.
	'observations_removed' => 0,
];

$snap_node_attrs = static function (int $node_id) use ($pdo): array {
	$stmt = $pdo->prepare('SELECT attrs FROM topo_nodes WHERE id = ?');
	$stmt->execute([$node_id]);
	$raw = $stmt->fetchColumn();
	return $raw ? (json_decode($raw, true, 512, JSON_THROW_ON_ERROR) ?: []) : [];
};

// Writes attrs only when they actually changed, so an idempotent re-run leaves updated_at alone too.
$snap_write_attrs = static function (int $node_id, array $attrs, array $before) use ($update_node, $now): bool {
	$sorted_attrs = $attrs;
	$sorted_before = $before;
	ksort($sorted_attrs);
	ksort($sorted_before);
	if ($sorted_attrs === $sorted_before) {
		return false;
	}
	$update_node->execute([json_encode($attrs, JSON_THROW_ON_ERROR), $now, $node_id]);
	return true;
};

// IANA ifType 161 = ieee8023adLag; anything else is "physical" (same classification as push.py — there is no
// SNMP signal for "mgmt"). A value that is already one of the model's own labels passes through.
$snap_if_type = static function (?string $value): string {
	if ($value === null || $value === '') {
		return 'physical';
	}
	if (in_array($value, ['physical', 'lag', 'mgmt'], true)) {
		return $value;
	}
	return $value === '161' ? 'lag' : 'physical';
};

// IF-MIB: 1 = up; everything else counts as down (push.py does the same).
$snap_status = static function (?string $value): string {
	return $value === '1' || $value === 'up' ? 'up' : 'down';
};

// Merge-upserts a Port by (device_id, if_index): only the attrs in $overlay are written, everything else the
// row already carries (learned_macs from a LEARNED_MACS rule, a name from a PORTS rule, ...) is kept. $soft keys
// are applied only when the port has no value for them yet (a NEIGHBORS row's if_name must not overwrite the
// name a PORTS rule reported). The reporter's own side is always confirmed, never pseudo.
$snap_port = static function (int $device_id, int $if_index, array $overlay = [], array $soft = []) use (
	$pdo, $insert_port_node, $find_port, $snap_node_attrs, $snap_write_attrs, $now, $last_insert_id, &$summary
): int {
	$existing_id = $find_port($device_id, $if_index);
	if ($existing_id !== null) {
		$before = $snap_node_attrs($existing_id);
		$attrs = $overlay + $before;
		foreach ($soft as $key => $value) {
			if ($value !== null && $value !== '' && ($before[$key] ?? '') === '') {
				$attrs[$key] = $value;
			}
		}
		$snap_write_attrs($existing_id, $attrs, $before);
		return $existing_id;
	}
	$attrs = $overlay + [
		'if_index' => $if_index, 'name' => '', 'if_type' => 'physical', 'mac' => null, 'speed' => null,
		'admin_status' => 'up', 'oper_status' => 'up', 'learned_macs' => [], 'zabbix_itemids' => [],
		'pseudo' => false,
	];
	foreach ($soft as $key => $value) {
		if ($value !== null && $value !== '' && ($attrs[$key] ?? '') === '') {
			$attrs[$key] = $value;
		}
	}
	$insert_port_node->execute(['port', $device_id, json_encode($attrs, JSON_THROW_ON_ERROR), $now, $now]);
	$summary['ports_created']++;
	return $last_insert_id();
};

// Device upsert for the snapshot path: same matching keys as $device() (chassis_id, mgmt_ip, scoped sysname),
// but incoming values are merged over what the row already has — one reporter's NEIGHBORS row must not erase
// the vendor/sysname another source recorded — and last_seen only ever moves forward.
$snap_device = static function (array $incoming, ?int $local_port_id, int $seen_at) use (
	$find_device, $insert_node, $snap_node_attrs, $snap_write_attrs, $now, $last_insert_id, &$summary
): int {
	$match = $find_device($incoming['chassis_id'] ?? null, $incoming['mgmt_ip'] ?? null, $local_port_id,
		$incoming['sysname'] ?? null);
	if ($match !== null) {
		[$device_id, $matched_by] = $match;
		$before = $snap_node_attrs($device_id);
		$attrs = $before;
		foreach (['mac', 'chassis_id', 'mgmt_ip', 'sysname', 'vendor'] as $key) {
			if (($incoming[$key] ?? null) !== null && $incoming[$key] !== '') {
				$attrs[$key] = $incoming[$key];
			}
		}
		$attrs['last_seen'] = max((int) ($before['last_seen'] ?? 0), $seen_at);
		if ($matched_by === 'sysname') {
			$attrs['matched_by'] = 'sysname';
		}
		if ($snap_write_attrs($device_id, $attrs, $before)) {
			$summary['devices_updated']++;
		}
		return $device_id;
	}
	$attrs = [
		'mac' => $incoming['mac'] ?? null, 'chassis_id' => $incoming['chassis_id'] ?? null,
		'mgmt_ip' => $incoming['mgmt_ip'] ?? null, 'sysname' => $incoming['sysname'] ?? '',
		'vendor' => $incoming['vendor'] ?? 'unknown', 'last_seen' => $seen_at,
	];
	$insert_node->execute(['device', json_encode($attrs, JSON_THROW_ON_ERROR), $now, $now]);
	$summary['devices_created']++;
	return $last_insert_id();
};

// physical_link upsert. Same canonicalization and per-side last_seen as $ensure_physical_link(), with three
// deliberate changes (spec §6.6): the time is the snapshot clock and only ever moves forward (so an older
// snapshot processed later cannot pull it back, and two rules confirming one side keep the newer clock);
// discovered_via is the row's source; any non-manual source upgrades a manual link. Returns the edge id.
$snap_link = static function (int $port_a, int $port_b, string $source, int $reporter_port_id, int $seen_at,
		bool $keep_manual = false) use (
	$pdo, $insert_edge, $now, $last_insert_id, &$summary
): int {
	$reporter_is_a = $reporter_port_id === $port_a;
	if ($port_a > $port_b) {
		[$port_a, $port_b] = [$port_b, $port_a];
		$reporter_is_a = !$reporter_is_a;
	}
	$side = $reporter_is_a ? 'last_seen_src' : 'last_seen_dst';

	$stmt = $pdo->prepare("SELECT id, attrs FROM topo_edges WHERE type = 'physical_link' AND src_id = ? AND dst_id = ?");
	$stmt->execute([$port_a, $port_b]);
	if ($existing = $stmt->fetch(PDO::FETCH_ASSOC)) {
		$before = json_decode($existing['attrs'], true, 512, JSON_THROW_ON_ERROR) ?: [];
		$attrs = $before;
		// A discovery source upgrades a manual link it confirms, except while discovery also shows a different
		// neighbor on one of its ports or the operator acknowledged one (topology-manual-contradiction-spec.md: the
		// operator decides that, not ingest): then the link stays manual. Revoking every acknowledgment hands the
		// link back to the upgrade. Every other attr, shadow_ack included, is carried over untouched.
		if (($attrs['discovered_via'] ?? 'manual') === 'manual' && !$keep_manual) {
			$attrs['discovered_via'] = $source;
		}
		$attrs[$side] = max((int) ($attrs[$side] ?? 0), $seen_at);
		$attrs['last_seen'] = max((int) ($attrs['last_seen_src'] ?? 0), (int) ($attrs['last_seen_dst'] ?? 0));
		if ($attrs !== $before) {
			$pdo->prepare('UPDATE topo_edges SET attrs = ? WHERE id = ?')
				->execute([json_encode($attrs, JSON_THROW_ON_ERROR), $existing['id']]);
		}
		return (int) $existing['id'];
	}
	$attrs = ['discovered_via' => $source, $side => $seen_at, 'last_seen' => $seen_at];
	$insert_edge->execute(['physical_link', $port_a, $port_b, json_encode($attrs, JSON_THROW_ON_ERROR), $now]);
	$summary['links_created']++;
	return $last_insert_id();
};

// Physical links touching either port, other than the (a,b) pair itself, split by provenance. A manual link
// on either port shadows the discovered observation (model spec §3 rule 5: manual wins, discovery never
// deletes it); a different discovered link on the local port is the cable-move case (§6.7).
$snap_other_links = static function (int $port_a, int $port_b) use ($pdo): array {
	$stmt = $pdo->prepare(
		"SELECT id, src_id, dst_id, attrs FROM topo_edges WHERE type = 'physical_link'".
		" AND (src_id IN (?, ?) OR dst_id IN (?, ?)) ORDER BY id");
	$stmt->execute([$port_a, $port_b, $port_a, $port_b]);
	$manual = [];
	$discovered = [];
	foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $edge) {
		$pair = [(int) $edge['src_id'], (int) $edge['dst_id']];
		sort($pair);
		$wanted = [$port_a, $port_b];
		sort($wanted);
		if ($pair === $wanted) {
			continue;
		}
		$attrs = json_decode($edge['attrs'], true, 512, JSON_THROW_ON_ERROR) ?: [];
		if (($attrs['discovered_via'] ?? 'manual') === 'manual') {
			$manual[] = (int) $edge['id'];
		}
		elseif (in_array($port_a, $pair, true)) {
			$discovered[] = (int) $edge['id'];
		}
	}
	return ['manual' => $manual, 'discovered' => $discovered];
};

// The reporter's own Device is represented by its own Host (model spec §4.1). Same code path and log lines
// as the blob path; $promote() itself is untouched.
$snap_link_reporter_self = static function (int $device_id, string $hostid, string $host_label) use (
	$pdo, $find_or_create_host_node, $promote
): void {
	$host_node_id = $find_or_create_host_node($hostid);
	if ($promote($device_id, $host_node_id, 'reporter_self')) {
		echo "OK: reporter '{$host_label}' device #{$device_id} represented_by its own host node ".
			"#{$host_node_id} (matched_by: reporter_self)\n";
		return;
	}
	$already_correct = $pdo->prepare(
		"SELECT 1 FROM topo_nodes WHERE id = ? AND type = 'device' AND represented_by_node_id = ?");
	$already_correct->execute([$device_id, $host_node_id]);
	if (!$already_correct->fetch()) {
		echo "CONFLICT: reporter '{$host_label}' device #{$device_id} or its host node #{$host_node_id} ".
			"already has a represented_by link to something else — leaving it in place (§2.3 1:1 constraint)\n";
	}
};

// Observation upsert keyed by (rule, local port, remote identity). first_seen survives while the observation
// stays continuously present; last_seen is the snapshot clock. Nothing is written when nothing changed.
$snap_observation = static function (int $itemid, int $local_port_id, string $remote_key, array $remote_attrs,
		string $outcome, ?int $edge_id, ?int $device_id, int $seen_at) use ($pdo): int {
	$attrs_json = json_encode($remote_attrs, JSON_THROW_ON_ERROR);
	$stmt = $pdo->prepare('SELECT id, remote_attrs, outcome, edge_id, device_id, last_seen FROM topo_observations'.
		' WHERE itemid = ? AND local_port_id = ? AND remote_key = ?');
	$stmt->execute([$itemid, $local_port_id, $remote_key]);
	if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
		$same = json_decode($row['remote_attrs'], true) === $remote_attrs && $row['outcome'] === $outcome
			&& ($row['edge_id'] === null ? null : (int) $row['edge_id']) === $edge_id
			&& ($row['device_id'] === null ? null : (int) $row['device_id']) === $device_id
			&& (int) $row['last_seen'] === $seen_at;
		if (!$same) {
			$pdo->prepare('UPDATE topo_observations SET remote_attrs = ?, outcome = ?, edge_id = ?, device_id = ?,'.
				' last_seen = ? WHERE id = ?')->execute([$attrs_json, $outcome, $edge_id, $device_id, $seen_at, $row['id']]);
		}
		return (int) $row['id'];
	}
	$pdo->prepare('INSERT INTO topo_observations (itemid, local_port_id, remote_key, remote_attrs, outcome, edge_id,'.
		' device_id, first_seen, last_seen) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
		->execute([$itemid, $local_port_id, $remote_key, $attrs_json, $outcome, $edge_id, $device_id, $seen_at, $seen_at]);
	return (int) $pdo->lastInsertId();
};

// remote_key: the strongest identity present, prefixed with its kind, so the same neighbor seen by the same
// rule on the same port always maps to the same row.
$snap_remote_key = static function (array $row): string {
	if (($row['rem_chassis'] ?? '') !== '') {
		return substr('chassis:'.($row['rem_chassis_type'] ?? '-').':'.$row['rem_chassis'], 0, 255);
	}
	if (($row['rem_mgmt_ip'] ?? '') !== '') {
		return substr('ip:'.$row['rem_mgmt_ip'], 0, 255);
	}
	return substr('sysname:'.($row['rem_sysname'] ?? ''), 0, 255);
};

// Reporters = hosts with at least one usable snapshot. Snapshots of disabled rules are skipped; a snapshot
// written under a role the rule no longer has is ignored (the API also deletes it on a role change — this is
// the defense in depth from spec §4.6). Returns [hostid => reporter].
$snap_load_reporters = static function (array $host_filter) use ($pdo, &$summary): array {
	$rows = $pdo->query(
		'SELECT s.itemid, s.hostid, s.role, s.clock, s.rows_json, i.topology_role AS rule_role,'.
		' i.status AS rule_status, h.status AS host_status, h.host, h.name AS host_name'.
		' FROM topo_lld_snapshot s JOIN items i ON i.itemid = s.itemid JOIN hosts h ON h.hostid = s.hostid'.
		' ORDER BY s.hostid, s.role, s.itemid')->fetchAll(PDO::FETCH_ASSOC);
	$reporters = [];
	foreach ($rows as $row) {
		if ($host_filter && !in_array($row['host'], $host_filter, true)) {
			continue;
		}
		// A usable snapshot belongs to an enabled rule on an enabled (monitored) host
		// (topology-observation-cleanup-spec.md, rule 1).
		if ((int) $row['rule_status'] !== 0 || (int) $row['host_status'] !== 0) {
			continue;
		}
		if ((int) $row['role'] !== (int) $row['rule_role']) {
			$summary['snapshots_ignored_role_mismatch']++;
			echo "SKIP: snapshot of rule #{$row['itemid']} was written for role {$row['role']} but the rule now has ".
				"role {$row['rule_role']}\n";
			continue;
		}
		$decoded = json_decode($row['rows_json'], true);
		if (!is_array($decoded)) {
			echo "SKIP: snapshot of rule #{$row['itemid']} holds invalid JSON\n";
			continue;
		}
		$hostid = (int) $row['hostid'];
		$reporters[$hostid] ??= ['hostid' => $hostid, 'host' => $row['host'], 'name' => $row['host_name'],
			'snapshots' => []];
		$reporters[$hostid]['snapshots'][(int) $row['role']][] = ['itemid' => (int) $row['itemid'],
			'clock' => (int) $row['clock'], 'rows' => $decoded];
	}
	return $reporters;
};

// Ingests one reporter from its snapshots. Order (spec §5.3): self Device -> PORTS -> LAG -> NEIGHBORS ->
// LEARNED_MACS. Returns ['status' => 'ok'] or ['status' => 'skipped', 'reason' => ...]; the caller owns the
// transaction. Nothing is written for a skipped reporter.
$link_candidates = [];
$neighbor_snapshots = [];
$snap_ingest_reporter = static function (array $reporter) use (
	$pdo, $find_device, $snap_device, $snap_port, $snap_link, $snap_other_links, $snap_observation, $snap_remote_key,
	$snap_node_attrs, $snap_write_attrs, $snap_if_type, $snap_status, $snap_link_reporter_self, $merge_pseudo_port,
	$find_matching_real_port, $resolve_port_label, $pseudo_if_index, $reconcile_device, $update_node, $now, &$summary,
	&$link_candidates, &$neighbor_snapshots
): array {
	$snapshots = $reporter['snapshots'];
	$label = $reporter['host'];
	$clocks = array_merge(...array_map(static fn (array $list): array => array_column($list, 'clock'),
		array_values($snapshots)));
	$reporter_seen = max($clocks);

	// ---- identity (spec §5.2) ----
	$chassis_values = [];
	foreach ($snapshots as $list) {
		foreach ($list as $snapshot) {
			foreach ($snapshot['rows'] as $row) {
				if (($row['loc_chassis'] ?? '') !== '') {
					$chassis_values[$row['loc_chassis']] = true;
				}
			}
		}
	}
	if (count($chassis_values) > 1) {
		echo "SKIP: reporter '{$label}' reports more than one loc_chassis (".implode(', ', array_keys($chassis_values)).
			") — identity is ambiguous, not picking one\n";
		return ['status' => 'skipped', 'reason' => 'identity_ambiguous'];
	}
	$loc_chassis = $chassis_values ? (string) array_key_first($chassis_values) : null;

	$existing = $pdo->prepare(
		"SELECT d.id FROM topo_nodes d JOIN topo_nodes h ON h.id = d.represented_by_node_id".
		" WHERE d.type = 'device' AND h.host_ref = ? AND d.represented_by_matched_by = 'reporter_self'");
	$existing->execute([$reporter['hostid']]);
	$device_id = $existing->fetchColumn();

	$interface = $pdo->prepare(
		'SELECT ip FROM interface WHERE hostid = ? AND type = 2 AND useip = 1 AND ip <> \'\' ORDER BY main DESC, interfaceid LIMIT 1');
	$interface->execute([$reporter['hostid']]);
	$mgmt_ip = $interface->fetchColumn() ?: null;

	if ($device_id === false) {
		if ($loc_chassis === null && $mgmt_ip === null) {
			echo "SKIP: reporter '{$label}' has neither a loc_chassis nor an SNMP interface IP — no identity key, ".
				"not creating a keyless Device\n";
			return ['status' => 'skipped', 'reason' => 'identity_missing'];
		}
		$device_id = $snap_device(['chassis_id' => $loc_chassis, 'mgmt_ip' => $mgmt_ip,
			'sysname' => $reporter['name'], 'vendor' => 'unknown'], null, $reporter_seen);
	}
	else {
		$device_id = (int) $device_id;
		$before = $snap_node_attrs($device_id);
		$attrs = $before;
		if ($loc_chassis !== null) {
			$attrs['chassis_id'] = $loc_chassis;
		}
		if ($mgmt_ip !== null) {
			$attrs['mgmt_ip'] = $mgmt_ip;
		}
		$attrs['last_seen'] = max((int) ($before['last_seen'] ?? 0), $reporter_seen);
		$snap_write_attrs($device_id, $attrs, $before);
	}
	$device_id = (int) $device_id;
	$snap_link_reporter_self($device_id, (string) $reporter['hostid'], $label);

	// ---- PORTS ----
	foreach ($snapshots[TOPO_ROLE_PORTS] ?? [] as $snapshot) {
		foreach ($snapshot['rows'] as $row) {
			$if_index = (int) $row['if_index'];
			$overlay = ['if_index' => $if_index, 'pseudo' => false];
			foreach (['name', 'mac', 'speed'] as $key) {
				if (($row[$key] ?? '') !== '') {
					$overlay[$key] = $row[$key];
				}
			}
			if (array_key_exists('if_type', $row)) {
				$overlay['if_type'] = $snap_if_type((string) $row['if_type']);
			}
			foreach (['admin_status', 'oper_status'] as $key) {
				if (array_key_exists($key, $row)) {
					$overlay[$key] = $snap_status((string) $row[$key]);
				}
			}
			// A port that is the target of a LAG membership stays typed "lag" even when its ifType says
			// otherwise (some vendors report port-channels as ethernetCsmacd); the LAG rule set it.
			$existing_id = $pdo->prepare("SELECT id FROM topo_nodes WHERE device_id = ? AND type = 'port'".
				" AND JSON_EXTRACT(attrs, '\$.if_index') = ?");
			$existing_id->execute([$device_id, $if_index]);
			if (($port_row = $existing_id->fetchColumn()) !== false) {
				$members = $pdo->prepare('SELECT COUNT(*) FROM topo_nodes WHERE lag_id = ?');
				$members->execute([$port_row]);
				if ((int) $members->fetchColumn() > 0) {
					unset($overlay['if_type']);
				}
			}
			$port_id = $snap_port($device_id, $if_index, $overlay);
			$port_name = $snap_node_attrs($port_id)['name'] ?? '';
			if ($port_name !== '') {
				$merge_pseudo_port($device_id, $port_id, $port_name);
			}
		}
	}

	// ---- LAG (only when the reporter has a LAG snapshot; otherwise no lag_id is ever touched) ----
	if (!empty($snapshots[TOPO_ROLE_LAG])) {
		$member_ids = [];
		foreach ($snapshots[TOPO_ROLE_LAG] as $snapshot) {
			foreach ($snapshot['rows'] as $row) {
				$member_index = (int) $row['if_index'];
				$lag_index = (int) $row['lag_if_index'];
				if ($member_index === $lag_index) {
					continue; // attached to itself = not aggregated
				}
				// The aggregate exists (typed "lag") before any member points at it — the lag_id invariant.
				$lag_port_id = $snap_port($device_id, $lag_index, ['if_index' => $lag_index, 'if_type' => 'lag',
					'pseudo' => false]);
				$member_id = $snap_port($device_id, $member_index, ['if_index' => $member_index, 'pseudo' => false]);
				$pdo->prepare('UPDATE topo_nodes SET lag_id = ? WHERE id = ? AND (lag_id IS NULL OR lag_id <> ?)')
					->execute([$lag_port_id, $member_id, $lag_port_id]);
				$member_ids[] = $member_id;
			}
		}
		// Silence overwrites: members of this Device that no longer appear in the snapshot leave the LAG.
		$sql = "UPDATE topo_nodes SET lag_id = NULL WHERE device_id = ? AND type = 'port' AND lag_id IS NOT NULL";
		$params = [$device_id];
		if ($member_ids) {
			$sql .= ' AND id NOT IN ('.implode(',', array_fill(0, count($member_ids), '?')).')';
			$params = array_merge($params, $member_ids);
		}
		$pdo->prepare($sql)->execute($params);
	}

	// ---- NEIGHBORS: phase 1 "collect" (topology-link-replacement-spec.md §6) ----
	// Devices and remote ports are resolved and created exactly as before, but no link and no observation is
	// written here: whether a candidate link may take its port depends on what EVERY reporter's latest snapshot
	// says, so the decision is made once, after all reporters, by $snap_resolve_links().
	foreach ($snapshots[TOPO_ROLE_NEIGHBORS] ?? [] as $snapshot) {
		$itemid = $snapshot['itemid'];
		$clock = $snapshot['clock'];
		$neighbor_snapshots[$itemid] = ['itemid' => $itemid, 'clock' => $clock, 'hostid' => $reporter['hostid']];

		foreach ($snapshot['rows'] as $row) {
			$local_port_id = $snap_port($device_id, (int) $row['if_index'], ['if_index' => (int) $row['if_index'],
				'pseudo' => false], ['name' => $row['if_name'] ?? null]);

			$chassis_id = ($row['rem_chassis'] ?? '') !== '' ? $row['rem_chassis'] : null;
			$looks_like_mac = $chassis_id !== null && preg_match('/^([0-9a-f]{2}:){5}[0-9a-f]{2}$/i', $chassis_id);
			$remote_attrs = array_filter(array_intersect_key($row, array_flip(['rem_chassis', 'rem_chassis_type',
				'rem_mgmt_ip', 'rem_sysname', 'rem_port', 'rem_port_type', 'rem_port_desc', 'source'])),
				static fn ($value): bool => $value !== '' && $value !== null);
			$source = ($row['source'] ?? 'lldp') !== '' ? (string) ($row['source'] ?? 'lldp') : 'lldp';

			$remote_device_id = $snap_device([
				'mac' => $looks_like_mac ? strtolower($chassis_id) : null,
				'chassis_id' => $chassis_id,
				'mgmt_ip' => ($row['rem_mgmt_ip'] ?? '') !== '' ? $row['rem_mgmt_ip'] : null,
				'sysname' => $row['rem_sysname'] ?? null,
				'vendor' => 'unknown',
			], $local_port_id, $clock);
			$reconcile_device($remote_device_id, [], array_filter([$looks_like_mac ? strtolower($chassis_id) : null]));

			$candidate = ['hostid' => $reporter['hostid'], 'itemid' => $itemid, 'clock' => $clock, 'source' => $source,
				'local_port_id' => $local_port_id, 'remote_device_id' => $remote_device_id,
				'remote_key' => $snap_remote_key($row), 'remote_attrs' => $remote_attrs,
				'pre_outcome' => null, 'remote_port_id' => null, 'port_label' => null, 'pseudo_index' => null,
				'pseudo_mac' => $looks_like_mac ? strtolower($chassis_id) : null];
			$has_remote_port = ($row['rem_port_desc'] ?? '') !== '' || ($row['rem_port'] ?? '') !== '';

			if (!$has_remote_port) {
				// Model spec §3 rule 1: no physical_link without a resolved Port on both ends.
				$candidate['pre_outcome'] = 'device_only';
			}
			else {
				$port_label = $resolve_port_label($row['rem_port_desc'] ?? null, $row['rem_port'] ?? null,
					$row['rem_port_type'] ?? null);
				$ambiguous = false;
				$remote_port_id = $find_matching_real_port($remote_device_id, $port_label, $ambiguous);
				$pseudo_index = $pseudo_if_index($row['rem_port'] ?? null, $row['rem_port_type'] ?? null);
				if ($ambiguous) {
					$candidate['pre_outcome'] = 'ambiguous'; // several real ports match the label: never guess
				}
				else {
					if ($remote_port_id === null) {
						$pseudo_id = $pdo->prepare("SELECT id FROM topo_nodes WHERE device_id = ? AND type = 'port'".
							" AND JSON_EXTRACT(attrs, '\$.if_index') = ?");
						$pseudo_id->execute([$remote_device_id, $pseudo_index]);
						// A confirmed port already owns that if_index (LLDP "local" subtype carries a real
						// ifIndex): it is the same physical port, do not turn it into a pseudo one.
						$remote_port_id = ($found = $pseudo_id->fetchColumn()) !== false ? (int) $found
							: $snap_port($remote_device_id, $pseudo_index, [
								'if_index' => $pseudo_index, 'name' => $port_label, 'pseudo' => true,
								'mac' => $looks_like_mac ? strtolower($chassis_id) : null,
							]);
					}
					$candidate['remote_port_id'] = $remote_port_id;
					$candidate['port_label'] = $port_label;
					$candidate['pseudo_index'] = $pseudo_index;
				}
			}

			$link_candidates[] = $candidate;
		}
	}

	// ---- LEARNED_MACS (only when the reporter has such a snapshot) ----
	if (!empty($snapshots[TOPO_ROLE_LEARNED_MACS])) {
		$per_port = [];
		foreach ($snapshots[TOPO_ROLE_LEARNED_MACS] as $snapshot) {
			foreach ($snapshot['rows'] as $row) {
				$entry = &$per_port[(int) $row['if_index']];
				$entry ??= ['macs' => [], 'count' => null];
				if (($row['mac'] ?? '') !== '') {
					$entry['macs'][strtolower($row['mac'])] = true;
				}
				elseif (isset($row['port_mac_count'])) {
					$entry['count'] = max((int) $entry['count'], (int) $row['port_mac_count']);
				}
				unset($entry);
			}
		}
		foreach (array_keys($per_port) as $if_index) {
			$snap_port($device_id, $if_index, ['if_index' => $if_index, 'pseudo' => false]);
		}
		$ports = $pdo->prepare("SELECT id, attrs FROM topo_nodes WHERE device_id = ? AND type = 'port'");
		$ports->execute([$device_id]);
		foreach ($ports->fetchAll(PDO::FETCH_ASSOC) as $port_row) {
			$before = json_decode($port_row['attrs'], true, 512, JSON_THROW_ON_ERROR) ?: [];
			$attrs = $before;
			$entry = $per_port[(int) ($before['if_index'] ?? -1)] ?? null;
			unset($attrs['learned_mac_count']);
			if ($entry === null) {
				$attrs['learned_macs'] = [];
			}
			elseif ($entry['macs']) {
				$attrs['learned_macs'] = array_keys($entry['macs']);
				sort($attrs['learned_macs']);
			}
			else {
				$attrs['learned_macs'] = [];
				$attrs['learned_mac_count'] = $entry['count'];
			}
			$snap_write_attrs((int) $port_row['id'], $attrs, $before);
		}
	}

	return ['status' => 'ok', 'device_id' => $device_id];
};

// ============================================================================================================
// NEIGHBORS phase 2 "resolve" (topology-link-replacement-spec.md v2, §3-§6)
//
// One decision for the whole run, from the candidates of EVERY reporter's latest NEIGHBORS snapshots, so the
// result cannot depend on the order the reporters were walked in.
//
// An *entity* is a pair of ports {P,X}: an existing link, a candidate, or both. rules(entity) is the set of
// NEIGHBORS rules whose latest snapshot contains the pair, from either end. A candidate that is not in a §5 group
// and not behind a manual link *claims* its pair. Every other entity touching either port of the claim (active
// discovered links, and the other claims) is HELD against it when some rule outside rules(claim) contains it: a
// snapshot other than the contradicting one still says it. Clocks are never compared: a reporter's latest snapshot
// is its current claim however long ago it was polled (the clock only becomes superseded_at). A claim WINS when
// nothing is held against it; it is then written (created, or revived when it was superseded) and every existing
// active link it blocks becomes superseded. An existing active link stays unless a winner supersedes it, and its
// own candidates are then "applied"; a losing claim is a "conflict" pointing at the link that holds the port.
//
// Before that, pre-spec data with two active discovered links on one port is cleaned up once (legacy).
// ============================================================================================================
$snap_resolve_links = static function (array $candidates, array $neighbor_snapshots, bool $replacement_enabled) use (
	$pdo, $find_matching_real_port, $snap_port, $snap_link, $snap_observation, &$summary
): void {
	$pair_key = static fn (int $x, int $y): string => min($x, $y).'-'.max($x, $y);

	// 1. Remote ports are looked up again: a reporter processed later in phase 1 may have merged the pseudo-Port
	//    a candidate was pointing at into its real Port.
	foreach ($candidates as &$candidate) {
		if ($candidate['remote_port_id'] === null) {
			continue;
		}
		$ambiguous = false;
		$real = $find_matching_real_port($candidate['remote_device_id'], $candidate['port_label'], $ambiguous);
		if ($ambiguous) {
			$candidate['pre_outcome'] = 'ambiguous';
			$candidate['remote_port_id'] = null;
		}
		elseif ($real !== null) {
			$candidate['remote_port_id'] = $real;
		}
		else {
			$stmt = $pdo->prepare("SELECT id FROM topo_nodes WHERE device_id = ? AND type = 'port'".
				" AND JSON_EXTRACT(attrs, '\$.if_index') = ?");
			$stmt->execute([$candidate['remote_device_id'], $candidate['pseudo_index']]);
			$candidate['remote_port_id'] = ($found = $stmt->fetchColumn()) !== false ? (int) $found
				: $snap_port($candidate['remote_device_id'], $candidate['pseudo_index'], ['if_index' => $candidate['pseudo_index'],
					'name' => $candidate['port_label'], 'pseudo' => true, 'mac' => $candidate['pseudo_mac']]);
		}
	}
	unset($candidate);

	// 2. Existing physical links, by port.
	$links = [];
	$by_port = [];
	$link_by_key = [];
	$rows = $pdo->query("SELECT id, src_id, dst_id, attrs FROM topo_edges WHERE type = 'physical_link' ORDER BY id")
		->fetchAll(PDO::FETCH_ASSOC);
	foreach ($rows as $edge) {
		$attrs = json_decode($edge['attrs'], true, 512, JSON_THROW_ON_ERROR) ?: [];
		$key = $pair_key((int) $edge['src_id'], (int) $edge['dst_id']);
		$links[(int) $edge['id']] = ['id' => (int) $edge['id'], 'a' => (int) $edge['src_id'], 'b' => (int) $edge['dst_id'],
			'key' => $key, 'manual' => ($attrs['discovered_via'] ?? 'manual') === 'manual',
			'seen' => (int) ($attrs['last_seen'] ?? 0),
			// The operator has decided something about this manual link (kept it for a hidden neighbor): from then
			// on no ingest run changes its kind, whether or not a neighbor is hiding behind it right now.
			'acknowledged' => !empty($attrs['shadow_ack']),
			'superseded_at' => isset($attrs['superseded_at']) ? (int) $attrs['superseded_at'] : null];
		$by_port[(int) $edge['src_id']][] = (int) $edge['id'];
		$by_port[(int) $edge['dst_id']][] = (int) $edge['id'];
		$link_by_key[$key] = (int) $edge['id'];
	}
	$is_active_discovered = static fn (array $link): bool => !$link['manual'] && $link['superseded_at'] === null;

	// 3. What the latest snapshots say, whatever became of the candidate: the rules that contain each pair (from
	//    either end), and the newest clock among them (only used to date a legacy cleanup).
	$rules_of = [];
	$support_clock = [];
	$groups = [];
	foreach ($candidates as $i => $candidate) {
		if ($candidate['remote_port_id'] === null || $candidate['remote_port_id'] === $candidate['local_port_id']) {
			continue;
		}
		$key = $pair_key($candidate['local_port_id'], $candidate['remote_port_id']);
		$rules_of[$key][$candidate['itemid']] = true;
		$support_clock[$key] = max($support_clock[$key] ?? 0, $candidate['clock']);
		$groups[$candidate['itemid'].'|'.$candidate['local_port_id']][$candidate['remote_port_id']] = true;
	}
	// Held against a claim: some rule OUTSIDE the claim's own rules still contains the entity.
	$held = static function (string $entity_key, string $claim_key) use (&$rules_of): bool {
		return (bool) array_diff_key($rules_of[$entity_key] ?? [], $rules_of[$claim_key] ?? []);
	};

	// 3b. Legacy data: pre-spec ingest let a cable move leave two active discovered links on one port. Keep one
	//     per port, once, whether or not a candidate arrives for that port. A partial run cannot see every
	//     reporter, so it leaves the data alone (§6.3).
	if ($replacement_enabled) {
		$active_on = static function (int $port) use (&$links, &$by_port, $is_active_discovered): array {
			$out = [];
			foreach ($by_port[$port] ?? [] as $lid) {
				if ($is_active_discovered($links[$lid])) {
					$out[] = $lid;
				}
			}
			return $out;
		};
		$supersede_legacy = static function (int $lid, int $at) use ($pdo, &$links, &$summary): void {
			$attrs = json_decode((string) $pdo->query("SELECT attrs FROM topo_edges WHERE id = {$lid}")->fetchColumn(),
				true, 512, JSON_THROW_ON_ERROR);
			$attrs['superseded_at'] = $at;
			$pdo->prepare('UPDATE topo_edges SET attrs = ? WHERE id = ?')
				->execute([json_encode($attrs, JSON_THROW_ON_ERROR), $lid]);
			$links[$lid]['superseded_at'] = $at;
			$summary['links_superseded']++;
		};
		$ports = array_keys($by_port);
		sort($ports);
		// (v2.1 §6.5) An active discovered link that shares a port with a manual link: manual wins.
		foreach ($ports as $port) {
			$has_manual = false;
			foreach ($by_port[$port] as $lid) {
				$has_manual = $has_manual || $links[$lid]['manual'];
			}
			if (!$has_manual) {
				continue;
			}
			foreach ($active_on($port) as $lid) {
				$supersede_legacy($lid, max($links[$lid]['seen'] ?? 0, $support_clock[$links[$lid]['key']] ?? 0));
			}
		}
		foreach ($ports as $port) {
			$active = $active_on($port);
			if (count($active) < 2) {
				continue;
			}
			// The link some snapshot still reports stays; then the one confirmed most recently; then the oldest id.
			usort($active, static function (int $x, int $y) use (&$links, &$rules_of, $rows): int {
				$sx = isset($rules_of[$links[$x]['key']]) ? 1 : 0;
				$sy = isset($rules_of[$links[$y]['key']]) ? 1 : 0;
				return [$sy, $links[$y]['seen'] ?? 0, $x] <=> [$sx, $links[$x]['seen'] ?? 0, $y];
			});
			array_shift($active);
			foreach ($active as $lid) {
				$supersede_legacy($lid, max($links[$lid]['seen'] ?? 0, $support_clock[$links[$lid]['key']] ?? 0));
			}
		}
	}

	// 4. Classify every candidate: fixed outcome, shadowed by a manual link, part of a §5 group, or a claim.
	$result = [];      // index => ['outcome' => ..., 'edge_id' => ..., 'link' => key|null (link it confirms/claims)]
	$claims = [];      // key => ['a' =>, 'b' =>, 'T' =>, 'indexes' => []]
	$confirming = [];  // key => [indexes] of §5 candidates that confirm an existing active link
	$counted_groups = [];
	foreach ($candidates as $i => $candidate) {
		if ($candidate['pre_outcome'] !== null) {
			$result[$i] = ['outcome' => $candidate['pre_outcome'], 'edge_id' => null, 'key' => null];
			continue;
		}
		$port = $candidate['local_port_id'];
		$remote = $candidate['remote_port_id'];
		if ($remote === $port) {
			$result[$i] = ['outcome' => 'device_only', 'edge_id' => null, 'key' => null];
			continue;
		}
		$key = $pair_key($port, $remote);

		// Manual wins (model spec §3 rule 5): unchanged, untouched by the replacement rule.
		$manual = [];
		foreach ([$port, $remote] as $touched) {
			foreach ($by_port[$touched] ?? [] as $lid) {
				if ($links[$lid]['manual'] && $links[$lid]['key'] !== $key) {
					$manual[] = $lid;
				}
			}
		}
		if ($manual) {
			$result[$i] = ['outcome' => 'shadowed', 'edge_id' => min($manual), 'key' => null];
			continue;
		}

		// §5: several neighbors on one port in one snapshot are not a sequence. None replaces another; the
		// existing active link on the port, if it is one of them, stays.
		$group_key = $candidate['itemid'].'|'.$port;
		if (count($groups[$group_key]) >= 2) {
			if (!isset($counted_groups[$group_key])) {
				$counted_groups[$group_key] = true;
				$summary['ports_ambiguous']++;
			}
			$holder = null;
			foreach ($by_port[$port] ?? [] as $lid) {
				$link = $links[$lid];
				$other = $link['a'] === $port ? $link['b'] : $link['a'];
				if ($is_active_discovered($link) && isset($groups[$group_key][$other])) {
					$holder = $holder ?? $link;
				}
			}
			if ($holder !== null && $holder['key'] === $key) {
				$result[$i] = ['outcome' => 'applied', 'edge_id' => $holder['id'], 'key' => $key];
				$confirming[$key][] = $i;
			}
			else {
				$result[$i] = ['outcome' => 'ambiguous', 'edge_id' => null, 'key' => null];
			}
			continue;
		}

		$claims[$key] ??= ['a' => $port, 'b' => $remote, 'T' => 0, 'indexes' => []];
		$claims[$key]['T'] = max($claims[$key]['T'], $candidate['clock']);
		$claims[$key]['indexes'][] = $i;
	}
	ksort($claims);

	// 4b. (v2.1 §5.2) Competing new claims: a port without an active link that several different pairs claim
	//     (X and Y both report R:P; R's LLDP shows X on P while its CDP shows Y). Nothing blocks any of them, the
	//     port simply has several claimants: no link, all ambiguous. Decided on the claims as they are, once.
	$claimants_of = [];
	foreach ($claims as $key => $claim) {
		$claimants_of[$claim['a']][$key] = true;
		$claimants_of[$claim['b']][$key] = true;
	}
	$competing = [];
	ksort($claimants_of);
	foreach ($claimants_of as $port => $keys) {
		if (count($keys) < 2) {
			continue;
		}
		foreach ($by_port[$port] ?? [] as $lid) {
			if ($links[$lid]['manual'] || $is_active_discovered($links[$lid])) {
				continue 2; // the port has an active link: §3 decides, as usual
			}
		}
		foreach ($keys as $key => $unused) {
			$competing[$key] = true;
		}
		$summary['ports_ambiguous']++;
	}
	foreach (array_keys($competing) as $key) {
		foreach ($claims[$key]['indexes'] as $i) {
			$result[$i] = ['outcome' => 'ambiguous', 'edge_id' => null, 'key' => null];
		}
		unset($claims[$key]);
	}

	// 5. Which claims win, and which existing links they supersede.
	$claims_by_port = [];
	foreach ($claims as $key => $claim) {
		$claims_by_port[$claim['a']][] = $key;
		$claims_by_port[$claim['b']][] = $key;
	}
	$wins = [];
	$blockers = [];      // claim key => [entity keys touching its ports]
	foreach ($claims as $key => $claim) {
		$entities = [];
		foreach ([$claim['a'], $claim['b']] as $touched) {
			foreach ($by_port[$touched] ?? [] as $lid) {
				if ($links[$lid]['key'] !== $key && $is_active_discovered($links[$lid])) {
					$entities[$links[$lid]['key']] = 'link';
				}
			}
			foreach ($claims_by_port[$touched] ?? [] as $other_key) {
				if ($other_key !== $key) {
					$entities[$other_key] ??= 'claim';
				}
			}
		}
		$blockers[$key] = $entities;
		$ok = true;
		foreach ($entities as $entity_key => $kind) {
			if ($held($entity_key, $key)) {
				$ok = false;
			}
			// A run scoped with --zabbix-host sees only some reporters: it cannot know that nothing else
			// supports an existing link, so it never replaces one (the old, conservative behaviour).
			if ($kind === 'link' && !$replacement_enabled) {
				$ok = false;
			}
		}
		$wins[$key] = $ok;
	}

	$superseded = []; // link key => T (the earliest winning contradiction)
	$superseded_by = [];
	foreach ($claims as $key => $claim) {
		if (!$wins[$key]) {
			continue;
		}
		foreach ($blockers[$key] as $entity_key => $kind) {
			if ($kind === 'link' && isset($link_by_key[$entity_key])) {
				$superseded[$entity_key] = min($superseded[$entity_key] ?? PHP_INT_MAX, $claim['T']);
				$superseded_by[$entity_key][] = $key;
			}
		}
	}

	// 6. Write links: winners (created / revived), stayers (confirmed), then the superseded ones.
	//    A manual link that hides a neighbor, or that has acknowledged neighbors, stays manual even when another
	//    reporter confirms it: a neighbor that flickers out of a snapshot must not make it upgradeable.
	$shadowing_manual = [];
	foreach ($result as $r) {
		if ($r['outcome'] === 'shadowed') {
			$shadowing_manual[$r['edge_id']] = true;
		}
	}
	$write_link = static function (string $key, array $indexes) use ($candidates, $snap_link, &$links, &$link_by_key,
			&$shadowing_manual): int {
		$id = 0;
		$existing = $link_by_key[$key] ?? null;
		$keep_manual = $existing !== null && $links[$existing]['manual']
			&& (isset($shadowing_manual[$existing]) || $links[$existing]['acknowledged']);
		foreach ($indexes as $i) {
			$c = $candidates[$i];
			$id = $snap_link($c['local_port_id'], $c['remote_port_id'], $c['source'], $c['local_port_id'], $c['clock'],
				$keep_manual);
		}
		return $id;
	};
	$after = [];        // key => link id, for every entity that is an active link once this run is done
	foreach ($claims as $key => $claim) {
		$existing = isset($link_by_key[$key]) ? $links[$link_by_key[$key]] : null;
		$stays = $existing !== null && ($existing['manual'] || $existing['superseded_at'] === null)
			&& !isset($superseded[$key]);
		if ($wins[$key] || $stays) {
			$id = $write_link($key, $claim['indexes']);
			if ($existing !== null && $existing['superseded_at'] !== null && $wins[$key]) {
				$attrs = json_decode((string) $pdo->query("SELECT attrs FROM topo_edges WHERE id = {$id}")->fetchColumn(),
					true, 512, JSON_THROW_ON_ERROR);
				unset($attrs['superseded_at']);
				$pdo->prepare('UPDATE topo_edges SET attrs = ? WHERE id = ?')
					->execute([json_encode($attrs, JSON_THROW_ON_ERROR), $id]);
				$summary['links_revived']++;
			}
			$after[$key] = $id;
		}
	}
	foreach ($confirming as $key => $indexes) {
		$after[$key] = $write_link($key, $indexes);
	}
	foreach ($superseded as $key => $at) {
		$id = $link_by_key[$key];
		$attrs = json_decode((string) $pdo->query("SELECT attrs FROM topo_edges WHERE id = {$id}")->fetchColumn(),
			true, 512, JSON_THROW_ON_ERROR);
		$attrs['superseded_at'] = $at;
		$pdo->prepare('UPDATE topo_edges SET attrs = ? WHERE id = ?')
			->execute([json_encode($attrs, JSON_THROW_ON_ERROR), $id]);
		$summary['links_superseded']++;
	}
	// Existing active links that stay are active too; a conflict points at them.
	foreach ($links as $link) {
		if (($link['manual'] || $link['superseded_at'] === null) && !isset($superseded[$link['key']])) {
			$after[$link['key']] ??= $link['id'];
		}
	}

	// 7. Outcomes of the claims.
	foreach ($claims as $key => $claim) {
		foreach ($claim['indexes'] as $i) {
			if (isset($after[$key])) {
				$result[$i] = ['outcome' => 'applied', 'edge_id' => $after[$key], 'key' => $key];
				continue;
			}
			// The link that keeps the port: an existing/created active link among the entities that beat this
			// claim (support at least as recent), or the winner that superseded this claim's own link.
			$holding = [];
			foreach (array_merge(array_keys($blockers[$key]), $superseded_by[$key] ?? []) as $entity_key) {
				if (isset($after[$entity_key]) && ($held($entity_key, $key)
						|| in_array($entity_key, $superseded_by[$key] ?? [], true))) {
					$holding[] = $after[$entity_key];
				}
			}
			foreach ($superseded_by[$key] ?? [] as $winner_key) {
				if (isset($after[$winner_key])) {
					$holding[] = $after[$winner_key];
				}
			}
			$result[$i] = ['outcome' => 'conflict', 'edge_id' => $holding ? min($holding) : (isset($link_by_key[$key])
				? $link_by_key[$key] : null), 'key' => $key];
		}
	}

	// 8. Observations mirror the latest snapshot of each rule.
	$touched = [];
	foreach ($candidates as $i => $candidate) {
		$outcome = $result[$i]['outcome'];
		$touched[$candidate['itemid']][] = $snap_observation($candidate['itemid'], $candidate['local_port_id'],
			$candidate['remote_key'], $candidate['remote_attrs'], $outcome, $result[$i]['edge_id'],
			$candidate['remote_device_id'], $candidate['clock']);
		$summary['observations'][$outcome]++;
	}
	foreach ($neighbor_snapshots as $itemid => $snapshot) {
		$all = $pdo->prepare('SELECT id FROM topo_observations WHERE itemid = ?');
		$all->execute([$itemid]);
		$stale = array_diff(array_map('intval', $all->fetchAll(PDO::FETCH_COLUMN)), $touched[$itemid] ?? []);
		foreach (array_chunk($stale, 500) as $chunk) {
			$pdo->prepare('DELETE FROM topo_observations WHERE id IN ('.implode(',', array_fill(0, count($chunk), '?')).')')
				->execute($chunk);
		}
	}
};

// ---- Testability hook, no effect on a normal CLI invocation (the constant is never defined
// there) ----
//
// test_reconciliation_scenarios.php includes this script with TOPOLOGY_INGEST_TEST_HOOK defined
// as a callable, so it can exercise the exact upsert/matching closures above ($device, $port,
// $find_device, $ensure_physical_link, $promote, $merge_pseudo_port, ...) against a throwaway DB
// without going through the Zabbix API, the file lock, or the per-reporter loop below — none of
// which a pure DB-layer reconciliation test needs or can safely drive in an automated run. This
// runs after every closure is defined and before $api is ever touched, so nothing below this
// point executes when the hook is present.
if (defined('TOPOLOGY_INGEST_TEST_HOOK')) {
	(TOPOLOGY_INGEST_TEST_HOOK)(get_defined_vars());
	return;
}

$api = ($api_url && $api_token) ? new ZabbixApi($api_url, $api_token) : null;
$processed = 0;
$skipped = 0;

// ---- LLD snapshot reporters (topology-lld-part2-spec.md) ----
$snapshot_reporters = $snap_load_reporters($zabbix_host_filter);

// Manual links contradicted by discovery at the end of a run (topology-manual-contradiction-spec.md §3): a
// `shadowed` observation of the link whose hidden neighbor Device is not in its attrs.shadow_ack (a shadow without
// a Device cannot be acknowledged). Ingest only reads shadow_ack here to count; it never writes it.
$count_contradicted_manual_links = static function () use ($pdo): int {
	$links = [];
	foreach ($pdo->query("SELECT id, attrs FROM topo_edges WHERE type = 'physical_link'")->fetchAll(PDO::FETCH_ASSOC) as $edge) {
		$attrs = json_decode($edge['attrs'], true, 512, JSON_THROW_ON_ERROR) ?: [];
		if (($attrs['discovered_via'] ?? 'manual') === 'manual') {
			$links[(int) $edge['id']] = array_fill_keys(array_map('strval', array_column($attrs['shadow_ack'] ?? [], 'device_id')), true);
		}
	}
	$contradicted = [];
	// Only observations of rules that still have a usable snapshot (the test of $clean_stale_observations).
	foreach ($pdo->query("SELECT o.edge_id, o.device_id FROM topo_observations o".
			" JOIN items i ON i.itemid = o.itemid AND i.status = 0".
			" JOIN hosts h ON h.hostid = i.hostid AND h.status = 0".
			" JOIN topo_lld_snapshot s ON s.itemid = o.itemid AND s.role = i.topology_role".
			" WHERE o.outcome = 'shadowed' AND o.edge_id IS NOT NULL")->fetchAll(PDO::FETCH_ASSOC) as $observation) {
		$edge_id = (int) $observation['edge_id'];
		if (isset($links[$edge_id]) && ($observation['device_id'] === null || !isset($links[$edge_id][(string) $observation['device_id']]))) {
			$contradicted[$edge_id] = true;
		}
	}
	return count($contradicted);
};

// An observation mirrors the latest snapshot of its rule (Part 2 §7). When the rule has no usable snapshot any more —
// disabled (the rule or its host), its role changed, its reporter skipped (identity), the snapshot invalid or gone — nothing refreshes the
// rows and they would keep saying what the rule said last: a stale shadow would keep a manual link contradicted, a
// stale conflict would keep blocking an operator's eye. A FULL run therefore removes the observations of every rule
// that did not contribute a snapshot to it; a run scoped with --zabbix-host cannot tell "not selected" from "not
// usable" and leaves them alone. The readers (topology.observations.get, the contradictions) apply the same test at
// read time (enabled rule, snapshot of the rule's current role), so a stale row is not shown even before the next run.
$clean_stale_observations = static function (array $usable_itemids) use ($pdo, &$summary): void {
	$usable = array_fill_keys(array_map('intval', $usable_itemids), true);
	$stale = [];
	foreach ($pdo->query('SELECT id, itemid FROM topo_observations')->fetchAll(PDO::FETCH_ASSOC) as $row) {
		if (!isset($usable[(int) $row['itemid']])) {
			$stale[] = (int) $row['id'];
		}
	}
	foreach (array_chunk($stale, 500) as $chunk) {
		$pdo->prepare('DELETE FROM topo_observations WHERE id IN ('.implode(',', array_fill(0, count($chunk), '?')).')')
			->execute($chunk);
	}
	$summary['observations_removed'] += count($stale);
};

// Test hook: --reporter-order <hostid,hostid,...> walks the reporters in that order (the rest after them), to show
// that the result does not depend on it. Normal runs go by hostid.
if (isset($options['reporter-order'])) {
	$order = array_map('intval', explode(',', (string) $options['reporter-order']));
	uksort($snapshot_reporters, static function (int $x, int $y) use ($order): int {
		$px = array_search($x, $order, true);
		$py = array_search($y, $order, true);
		return ($px === false ? PHP_INT_MAX : $px) <=> ($py === false ? PHP_INT_MAX : $py) ?: $x <=> $y;
	});
}

$link_candidates = [];
$neighbor_snapshots = [];
foreach ($snapshot_reporters as $reporter) {
	echo "--- {$reporter['host']} (LLD snapshots) ---\n";
	$mark = [count($link_candidates), $neighbor_snapshots];
	try {
		$pdo->beginTransaction();
		$result = $snap_ingest_reporter($reporter);
		if ($result['status'] === 'ok') {
			$pdo->commit();
			$summary['reporters_processed']++;
			$processed++;
			echo "OK: ingested LLD snapshots for '{$reporter['host']}' — device #{$result['device_id']}, roles: ".
				implode(',', array_keys($reporter['snapshots']))."\n";
		}
		else {
			$pdo->rollBack();
			$link_candidates = array_slice($link_candidates, 0, $mark[0]);
			$neighbor_snapshots = $mark[1];
			$summary['reporters_skipped'][$result['reason']] = ($summary['reporters_skipped'][$result['reason']] ?? 0) + 1;
			$skipped++;
		}
	}
	catch (Throwable $exception) {
		if ($pdo->inTransaction()) {
			$pdo->rollBack();
		}
		$link_candidates = array_slice($link_candidates, 0, $mark[0]);
		$neighbor_snapshots = $mark[1];
		fwrite(STDERR, "ERROR: failed to ingest LLD snapshots of '{$reporter['host']}': {$exception->getMessage()}\n");
		$summary['reporters_skipped']['error'] = ($summary['reporters_skipped']['error'] ?? 0) + 1;
		$skipped++;
	}
}

// Phase 2: links and observations, decided once for all reporters (topology-link-replacement-spec.md §6).
$phase_2_done = true;
if ($link_candidates || $neighbor_snapshots) {
	try {
		$pdo->beginTransaction();
		$snap_resolve_links($link_candidates, $neighbor_snapshots, !$zabbix_host_filter);
		$pdo->commit();
	}
	catch (Throwable $exception) {
		if ($pdo->inTransaction()) {
			$pdo->rollBack();
		}
		$phase_2_done = false;
		fwrite(STDERR, "ERROR: failed to resolve the neighbor links: {$exception->getMessage()}\n");
		$summary['reporters_skipped']['error'] = ($summary['reporters_skipped']['error'] ?? 0) + 1;
		$skipped++;
	}
}
if ($phase_2_done && !$zabbix_host_filter) {
	try {
		$pdo->beginTransaction();
		$clean_stale_observations(array_keys($neighbor_snapshots));
		$pdo->commit();
	}
	catch (Throwable $exception) {
		if ($pdo->inTransaction()) {
			$pdo->rollBack();
		}
		fwrite(STDERR, "ERROR: failed to remove stale observations: {$exception->getMessage()}\n");
	}
}

// ---- Legacy Trapper-blob reporters. A host that has LLD snapshots is ingested from them only. ----
$reporter_items = $api ? $api->findAllReporterItems() : [];
if ($zabbix_host_filter) {
	$reporter_items = array_values(array_filter($reporter_items,
		static fn (array $row): bool => in_array($row['host'], $zabbix_host_filter, true)));
}
foreach ($reporter_items as $key => $row) {
	if (isset($snapshot_reporters[(int) $row['hostid']])) {
		echo "NOTE: '{$row['host']}' has both a topology.discovery.raw item and LLD snapshots — using the ".
			"snapshots, ignoring the blob\n";
		unset($reporter_items[$key]);
	}
}
$reporter_items = array_values($reporter_items);

if (!$reporter_items && !$snapshot_reporters) {
	// Zero reporters is a normal "nothing onboarded yet" state (spec §4.1), not an error — succeed with
	// nothing to do rather than failing the run.
	echo "No reporters found (no host has LLD snapshots or carries the topology.discovery.raw item yet — nothing ".
		"onboarded, or --zabbix-host didn't match any onboarded reporter). Nothing to ingest.\n";
	if (!$zabbix_host_filter) {
		$clean_stale_observations([]);
	}
	write_status(['status' => 'done', 'started_at' => $run_started_at, 'finished_at' => time(),
		'summary' => $summary, 'error' => null]);
	exit(0);
}

foreach ($reporter_items as $reporter_item) {
	$zabbix_host = $reporter_item['host'] ?? "hostid:{$reporter_item['hostid']}";
	echo "--- {$zabbix_host} ---\n";

	try {
		$itemid = $reporter_item['itemid'];

		$raw = $api->latestValue($itemid);
		if ($raw === null) {
			echo "SKIP: topology.discovery.raw has no history yet for '{$zabbix_host}'\n";
			$skipped++;
			continue;
		}

		$blob = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

		$pdo->beginTransaction();

		// Reporter Device + its Ports (rule 4 upsert).
		$reporter_attrs = [
			'mac' => null,
			'chassis_id' => $blob['reporter']['chassis_id'] ?? null,
			'mgmt_ip' => $blob['reporter']['mgmt_ip'] ?? null,
			'sysname' => $blob['reporter']['sysname'] ?? '',
			'vendor' => $blob['reporter']['vendor'] ?? 'unknown',
			'last_seen' => $now,
		];
		$reporter_device_id = $device($reporter_attrs);

		$local_ports = []; // if_index => port node id, for wiring neighbors below
		$reporter_macs = [];
		foreach ($blob['ports'] as $port_blob) {
			$attrs = [
				'if_index' => (int) $port_blob['if_index'],
				'name' => $port_blob['name'] ?? '',
				'if_type' => $port_blob['if_type'] ?? 'physical',
				'mac' => $port_blob['mac'] ?? null,
				'speed' => $port_blob['speed'] ?? null, // not carried by the §4.1 blob shape — see report
				'admin_status' => $port_blob['admin_status'] ?? 'down',
				'oper_status' => $port_blob['oper_status'] ?? 'down',
				'learned_macs' => [], // CAM-table walk is out of scope for this iteration (spec §1)
				'zabbix_itemids' => [],
				'pseudo' => false, // §2.2: real port, from this Device's own push.
			];
			$local_ports[$attrs['if_index']] = $port($reporter_device_id, $attrs);
			if ($attrs['mac']) {
				$reporter_macs[] = $attrs['mac'];
			}

			// §3 rule 4 pseudo-port merge — real ports only, right after upserting one (see the closure's
			// own comment for why it must not run anywhere else, e.g. the neighbor pseudo-port path below).
			$merge_pseudo_port($reporter_device_id, $local_ports[$attrs['if_index']], $attrs['name']);
		}

		// Rule 1: only an LLDP-resolved neighbor becomes a Device — the push component already computed
		// stats.resolved on this same "chassis_id or sysname present" test; re-derive per-entry here since
		// that's the actual gate for whether *this* entry creates a Device, not just a count.
		foreach ($blob['neighbors'] as $neighbor) {
			$chassis_id = $neighbor['remote_chassis_id'] ?? null;
			$sysname = $neighbor['remote_sysname'] ?? null;
			if (!$chassis_id && !$sysname) {
				continue; // unresolved neighbor: not a Device, and nothing else to do with it in this
				          // iteration (no CAM-table learned_macs pass here — see report gap notes).
			}

			$looks_like_mac = $chassis_id !== null && preg_match('/^([0-9a-f]{2}:){5}[0-9a-f]{2}$/i', $chassis_id);
			$neighbor_attrs = [
				'mac' => $looks_like_mac ? strtolower($chassis_id) : null,
				'chassis_id' => $chassis_id,
				'mgmt_ip' => null, // lldpRemTable carries no IP; a real mgmt IP needs a separate lookup (report gap)
				'sysname' => $sysname ?? '',
				'vendor' => 'unknown',
				'last_seen' => $now,
			];
			// Rule 4's third match key needs the local reporter Port at this neighbor's local_if_index up
			// front (it scopes the sysname lookup to "already linked off this exact port") — compute it
			// before the Device upsert rather than after, unlike the physical_link wiring below which only
			// needs it once the neighbor Port also exists.
			$local_if_index = (int) $neighbor['local_if_index'];
			$local_port_id = $local_ports[$local_if_index] ?? null;
			$neighbor_device_id = $device($neighbor_attrs, $local_port_id);

			$neighbor_port_label = $resolve_port_label($neighbor['remote_port_desc'] ?? null, $neighbor['remote_port_id'] ?? null, $neighbor['remote_port_id_subtype'] ?? null);

			// Spec §3 rule 4: before fabricating a pseudo-Port for this neighbor, check whether it already
			// has a real one (from its own push, in this run or an earlier one) that's plainly the same
			// physical port — see $find_matching_real_port()'s comment for why this proactive check is
			// needed in addition to (not instead of) $merge_pseudo_port() below.
			$neighbor_port_id = $find_matching_real_port($neighbor_device_id, $neighbor_port_label);
			if ($neighbor_port_id === null) {
				$neighbor_if_index = $pseudo_if_index($neighbor['remote_port_id'] ?? null, $neighbor['remote_port_id_subtype'] ?? null);
				$neighbor_port_attrs = [
					'if_index' => $neighbor_if_index,
					'name' => $neighbor_port_label,
					'if_type' => 'physical',
					'mac' => $looks_like_mac ? strtolower($chassis_id) : null,
					'speed' => null,
					'admin_status' => 'up',
					'oper_status' => 'up',
					'learned_macs' => [],
					'zabbix_itemids' => [],
					'pseudo' => true, // §2.2: fabricated only from a neighbor's LLDP sighting, not this Device's own push.
				];
				$neighbor_port_id = $port($neighbor_device_id, $neighbor_port_attrs);
			}

			if ($local_port_id !== null) {
				$ensure_physical_link($local_port_id, $neighbor_port_id, 'lldp', $local_port_id);
			}

			// Rule 2: reconcile the neighbor Device too, not just the reporter — a neighbor discovered by
			// LLDP might itself be an already-onboarded Zabbix Host with inventory MAC data.
			$reconcile_device($neighbor_device_id, $neighbor_attrs, array_filter([$neighbor_attrs['mac']]));
		}

		// §2.3/§4.1: the reporter's own Device is represented_by its own Host deterministically — this
		// is a separate path from §3.2's opportunistic MAC reconciliation, not a variant of it.
		// $reconcile_device() is never called for the reporter itself (only for neighbors, above): ingest
		// already knows the reporter's exact hostid for certain (from $reporter_item, the very item.get
		// row that located this reporter in the first place), so there's no ambiguity to resolve via MAC
		// matching, and no need to wait on a MAC even existing in inventory at all.
		$reporter_host_nodeid = $find_or_create_host_node((string) $reporter_item['hostid']);
		if ($promote($reporter_device_id, $reporter_host_nodeid, 'reporter_self')) {
			echo "OK: reporter '{$zabbix_host}' device #{$reporter_device_id} represented_by its own host ".
				"node #{$reporter_host_nodeid} (matched_by: reporter_self)\n";
		}
		else {
			// $promote() returns false both for a genuine conflict (Device/Host already represented_by
			// something ELSE) and, on a re-run, for the idempotent case where it's already correctly
			// linked to this exact host node — distinguish the two here purely for clearer logging
			// ($promote() itself stays untouched, per scope).
			$already_correct = $pdo->prepare(
				"SELECT 1 FROM topo_nodes WHERE id = ? AND type = 'device' AND represented_by_node_id = ?");
			$already_correct->execute([$reporter_device_id, $reporter_host_nodeid]);
			if ($already_correct->fetch()) {
				echo "OK: reporter '{$zabbix_host}' device #{$reporter_device_id} already represented_by ".
					"its own host node #{$reporter_host_nodeid} (matched_by: reporter_self) — no change\n";
			}
			else {
				echo "CONFLICT: reporter '{$zabbix_host}' device #{$reporter_device_id} or its host node ".
					"#{$reporter_host_nodeid} already has a represented_by edge to something else — leaving ".
					"the existing edge in place, not overriding it (§2.3 1:1 constraint)\n";
			}
		}

		$pdo->commit();
		echo "OK: ingested blob for '{$zabbix_host}' — device #{$reporter_device_id}, ".
			count($blob['ports'])." ports, ".count($blob['neighbors'])." neighbor entries ".
			"(stats: ".json_encode($blob['stats'] ?? []).")\n";
		$processed++;
	}
	catch (Throwable $exception) {
		if ($pdo->inTransaction()) {
			$pdo->rollBack();
		}
		// One reporter's bad/unparseable blob must never abort the whole ingest run — same
		// partial-failure principle as the push component's per-neighbor handling (§4.1).
		fwrite(STDERR, "ERROR: failed to ingest '{$zabbix_host}': {$exception->getMessage()}\n");
		$skipped++;
	}
}

echo "\nDone: {$processed} reporter(s) ingested, {$skipped} skipped/failed.\n";

$summary['manual_links_contradicted'] = $count_contradicted_manual_links();
$ok = $processed > 0 || $skipped === 0;
write_status([
	'status' => $ok ? 'done' : 'error',
	'started_at' => $run_started_at,
	'finished_at' => time(),
	'summary' => $summary,
	'error' => $ok ? null : "{$skipped} reporter(s) failed and none succeeded — see stderr/run log for details.",
]);
exit($ok ? 0 : 1);
