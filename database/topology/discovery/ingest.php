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
	'zabbix-host:', 'help']);

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

function write_status(array $status): void {
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

if (!$api_url || !$api_token || !$pdo_dsn || !$pdo_user) {
	fail("Usage: {$argv[0]} --api-url <url> --api-token <token> --pdo-dsn <dsn> --pdo-user <user> ".
		"[--pdo-password <password>] [--zabbix-host <host> ...]\n\n".
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

$device = static function (array $attrs, ?int $local_port_id = null) use ($insert_node, $update_node, $now, $last_insert_id, $find_device, &$summary): int {
	$match = $find_device($attrs['chassis_id'] ?? null, $attrs['mgmt_ip'] ?? null, $local_port_id, $attrs['sysname'] ?? null);
	if ($match !== null) {
		[$existing_id, $matched_by] = $match;
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

// Byte-for-byte mirror of CTopologyPrototype::promote() (~line 599)'s 1:1-guard + insert, called only from
// $reconcile_device() below with match_type='identity' — same as reconcileHost() calling promote() with
// 'identity', never 'manual' (manual promotion is the separate /promote endpoint's job, §2.3, not ingest's).
$promote = static function (int $device_id, int $target_nodeid, string $matched_by, ?string $matched_mac) use ($pdo, $insert_edge, $now): bool {
	$represented = $pdo->prepare("SELECT 1 FROM topo_edges WHERE type = 'represented_by' AND src_id = ?");
	$represented->execute([$device_id]);
	if ($represented->fetch()) {
		return false; // this Device already has a represented_by edge — not an error, just no match (§2.3 1:1).
	}
	$represented_target = $pdo->prepare("SELECT 1 FROM topo_edges WHERE type = 'represented_by' AND dst_id = ?");
	$represented_target->execute([$target_nodeid]);
	if ($represented_target->fetch()) {
		return false; // this Host/Proxy is already represented_by some other Device.
	}
	$insert_edge->execute(['represented_by', $device_id, $target_nodeid, json_encode([
		'match_type' => 'identity', 'matched_by' => $matched_by, 'matched_mac' => $matched_mac,
		'created_at' => $now,
	], JSON_THROW_ON_ERROR), $now]);
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
// concept at all (same gap noted in CTopologyPrototype::pullProxies()), so there is nothing to reconcile a
// Proxy against here either. Rule 2 (spec §3): matching is MAC-only — there is no generic Zabbix host
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
			$promote($device_id, (int) $row['id'], 'mac', $mac);
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
$find_matching_real_port = static function (int $device_id, string $name) use ($pdo, $normalize_port_name): ?int {
	$normalized = $normalize_port_name($name);
	$stmt = $pdo->prepare(
		"SELECT id, JSON_UNQUOTE(JSON_EXTRACT(attrs, '\$.name')) AS name FROM topo_nodes".
		" WHERE device_id = ? AND type = 'port' AND JSON_EXTRACT(attrs, '\$.pseudo') = false");
	$stmt->execute([$device_id]);
	$matches = array_values(array_filter($stmt->fetchAll(PDO::FETCH_ASSOC),
		static fn (array $p): bool => $normalize_port_name((string) $p['name']) === $normalized));
	// Same "never guess" rule as $merge_pseudo_port(): only act on an unambiguous single match.
	return count($matches) === 1 ? (int) $matches[0]['id'] : null;
};

$api = new ZabbixApi($api_url, $api_token);
$processed = 0;
$skipped = 0;

$reporter_items = $api->findAllReporterItems();
if ($zabbix_host_filter) {
	$reporter_items = array_values(array_filter($reporter_items,
		static fn (array $row): bool => in_array($row['host'], $zabbix_host_filter, true)));
}

if (!$reporter_items) {
	// Zero reporters is a normal "nothing onboarded yet" state (spec §4.1), not an error — succeed with
	// nothing to do rather than failing the run.
	echo "No reporters found (no host carries the topology.discovery.raw item yet — nothing onboarded, ".
		"or --zabbix-host didn't match any onboarded reporter). Nothing to ingest.\n";
	write_status(['status' => 'done', 'started_at' => $run_started_at, 'finished_at' => time(),
		'summary' => ['devices_created' => 0, 'devices_updated' => 0, 'ports_created' => 0, 'links_created' => 0],
		'error' => null]);
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
		if ($promote($reporter_device_id, $reporter_host_nodeid, 'reporter_self', null)) {
			echo "OK: reporter '{$zabbix_host}' device #{$reporter_device_id} represented_by its own host ".
				"node #{$reporter_host_nodeid} (matched_by: reporter_self)\n";
		}
		else {
			// $promote() returns false both for a genuine conflict (Device/Host already represented_by
			// something ELSE) and, on a re-run, for the idempotent case where it's already correctly
			// linked to this exact host node — distinguish the two here purely for clearer logging
			// ($promote() itself stays untouched, per scope).
			$already_correct = $pdo->prepare(
				"SELECT 1 FROM topo_edges WHERE type = 'represented_by' AND src_id = ? AND dst_id = ?");
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

$ok = $processed > 0 || $skipped === 0;
write_status([
	'status' => $ok ? 'done' : 'error',
	'started_at' => $run_started_at,
	'finished_at' => time(),
	'summary' => $summary,
	'error' => $ok ? null : "{$skipped} reporter(s) failed and none succeeded — see stderr/run log for details.",
]);
exit($ok ? 0 : 1);
