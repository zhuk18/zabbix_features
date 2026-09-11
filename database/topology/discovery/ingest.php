#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * ingest.php — topology discovery "ingest" component (spec §4.1, Trapper delivery mechanism).
 *
 * Reads the latest `topology.discovery.raw` value per reporter (via the Zabbix API's
 * item.get/history.get), applies spec §3 exactly (evidence threshold / rule 1, MAC/chassis-ID
 * reconciliation / rule 2, never-delete-on-unlink / rule 3, upsert-by-natural-key / rule 4,
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
 * host_inventory (macaddress_a/macaddress_b/chassis) is read with a direct SQL join against
 * topo_nodes.host_ref, since ingest already holds a PDO connection to the very same Zabbix
 * database that host_inventory lives in — no second round trip through the Zabbix API is
 * needed for that part (§3.2's gotcha about host.get()'s selectInterfaces having no MAC field
 * doesn't apply here, since this never goes through selectInterfaces at all).
 *
 * Usage:
 *   ingest.php --api-url <url> --api-token <token> --pdo-dsn <dsn> --pdo-user <user> \
 *       [--pdo-password <password>] (--config reporters.json | --zabbix-host <host> [--zabbix-host <host> ...])
 *
 * Env vars (fallbacks for the flags above): ZABBIX_API_URL, ZABBIX_API_TOKEN,
 * TOPOLOGY_PDO_DSN, TOPOLOGY_PDO_USER, TOPOLOGY_PDO_PASSWORD.
 */

function fail(string $message): void {
	fwrite(STDERR, $message."\n");
	exit(1);
}

$options = getopt('', ['api-url:', 'api-token:', 'pdo-dsn:', 'pdo-user:', 'pdo-password:',
	'config:', 'zabbix-host:', 'help']);

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
		"[--pdo-password <password>] (--config reporters.json | --zabbix-host <host>...)\n\n".
		"Credentials may also come from ZABBIX_API_URL / ZABBIX_API_TOKEN / TOPOLOGY_PDO_DSN / ".
		"TOPOLOGY_PDO_USER / TOPOLOGY_PDO_PASSWORD env vars — never hardcode them in a script ".
		"(see topo-change-sender.sh at the repo root for the anti-pattern this avoids).");
}

$reporter_hosts = [];
if (isset($options['config'])) {
	$config = json_decode((string) file_get_contents($options['config']), true, 512, JSON_THROW_ON_ERROR);
	foreach ($config as $reporter) {
		$reporter_hosts[] = $reporter['zabbix_host'];
	}
}
if (isset($options['zabbix-host'])) {
	$reporter_hosts = array_merge($reporter_hosts, (array) $options['zabbix-host']);
}
if (!$reporter_hosts) {
	fail('No reporters given — pass --config reporters.json or one or more --zabbix-host.');
}

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

	/** Returns [hostid, itemid] for the reporter's topology.discovery.raw item, or null if either is missing —
	 * an absent item is a normal "push hasn't run against this reporter yet" state, not fatal to the run. */
	public function findRawItem(string $host): ?array {
		$hosts = $this->call('host.get', ['output' => ['hostid'], 'filter' => ['host' => [$host]]]);
		if (!$hosts) {
			return null;
		}
		$hostid = $hosts[0]['hostid'];
		$items = $this->call('item.get', ['output' => ['itemid'], 'hostids' => [$hostid],
			'filter' => ['key_' => 'topology.discovery.raw']]);
		if (!$items) {
			return null;
		}
		return [$hostid, $items[0]['itemid']];
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
// owning Device via part_of. $local_port_id/$sysname are only ever passed for neighbor Devices (see the
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
			" JOIN topo_edges part_of ON part_of.type = 'part_of' AND part_of.src_id = port.id".
			" JOIN topo_nodes dev ON dev.id = part_of.dst_id".
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
	$stmt = $pdo->prepare("SELECT port.id FROM topo_nodes port".
		" JOIN topo_edges part_of ON part_of.type = 'part_of' AND part_of.src_id = port.id".
		" WHERE part_of.dst_id = ? AND port.type = 'port' AND JSON_EXTRACT(port.attrs, '\$.if_index') = ?");
	$stmt->execute([$device_id, $if_index]);
	$row = $stmt->fetch(PDO::FETCH_ASSOC);
	return $row ? (int) $row['id'] : null;
};

$insert_node = $pdo->prepare('INSERT INTO topo_nodes (type, attrs, created_at, updated_at) VALUES (?, ?, ?, ?)');
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

// Rule 4: upsert Port by (device via part_of, if_index) + owns the part_of edge. Mirrors seed.php's $port().
$port = static function (int $device_id, array $attrs) use ($insert_node, $update_node, $insert_edge, $now, $last_insert_id, $find_port, &$summary): int {
	$existing_id = $find_port($device_id, $attrs['if_index']);
	if ($existing_id !== null) {
		$update_node->execute([json_encode($attrs, JSON_THROW_ON_ERROR), $now, $existing_id]);
		return $existing_id;
	}
	$insert_node->execute(['port', json_encode($attrs, JSON_THROW_ON_ERROR), $now, $now]);
	$port_id = $last_insert_id();
	$insert_edge->execute(['part_of', $port_id, $device_id, '{}', $now]);
	$summary['ports_created']++;
	return $port_id;
};

// Byte-for-byte mirror of CTopologyPrototype::upsertPhysicalLink() (ui/include/classes/topology/
// CTopologyPrototype.php ~line 680): canonicalize direction (smaller Port id -> src_id), never let a
// repeated 'lldp' call downgrade a 'manual' edge (only lldp->lldp or manual->lldp upgrade is written), and
// never let a repeated 'manual' call overwrite an existing edge at all. Discovery must never delete a
// manually-created link (rule 5) — this function has no delete path, only upsert, satisfying that by
// construction.
$ensure_physical_link = static function (int $port_a, int $port_b, string $discovered_via) use ($pdo, $insert_edge, $now, &$summary): void {
	if ($port_a > $port_b) {
		[$port_a, $port_b] = [$port_b, $port_a];
	}
	$stmt = $pdo->prepare("SELECT id, attrs FROM topo_edges WHERE type = 'physical_link' AND src_id = ? AND dst_id = ?");
	$stmt->execute([$port_a, $port_b]);
	if ($existing = $stmt->fetch(PDO::FETCH_ASSOC)) {
		$attrs = json_decode($existing['attrs'], true, 512, JSON_THROW_ON_ERROR) ?: [];
		if ($discovered_via === 'lldp' && ($attrs['discovered_via'] ?? null) !== 'lldp') {
			$attrs['discovered_via'] = 'lldp';
			$attrs['last_seen'] = $now;
			$pdo->prepare('UPDATE topo_edges SET attrs = ? WHERE id = ?')
				->execute([json_encode($attrs, JSON_THROW_ON_ERROR), $existing['id']]);
		}
		// discovered_via === 'manual' on an existing edge (of either provenance), or a repeated
		// 'lldp' on an already-'lldp' edge: no-op by design (never downgrade, never duplicate).
		return;
	}
	$insert_edge->execute(['physical_link', $port_a, $port_b,
		json_encode(['discovered_via' => $discovered_via, 'last_seen' => $now], JSON_THROW_ON_ERROR), $now]);
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

// Byte-for-byte mirror of CTopologyPrototype::reconcileHost() (~line 973)'s two lookups (Port.attrs.mac via
// host_inventory.macaddress_a/b, then Device.attrs.chassis_id via host_inventory.chassis) — run per Device
// instead of per Host, since ingest discovers/touches Devices, not Hosts (§5's Host/Proxy pull is a
// separate, already-existing pull path this script doesn't duplicate). Only Host is reconciled against,
// not Proxy — proxy.get/CProxy::get exposes no MAC/inventory concept at all (same gap noted in
// CTopologyPrototype::pullProxies()), so there is nothing to reconcile a Proxy against here either.
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
	if (!empty($device_attrs['chassis_id'])) {
		$stmt = $pdo->prepare("SELECT node.id FROM topo_nodes node".
			" JOIN host_inventory hi ON hi.hostid = node.host_ref".
			" WHERE node.type = 'host' AND hi.chassis = ?");
		$stmt->execute([$device_attrs['chassis_id']]);
		while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
			$promote($device_id, (int) $row['id'], 'chassis_id', null);
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

$api = new ZabbixApi($api_url, $api_token);
$processed = 0;
$skipped = 0;

foreach ($reporter_hosts as $zabbix_host) {
	echo "--- {$zabbix_host} ---\n";

	try {
		$located = $api->findRawItem($zabbix_host);
		if ($located === null) {
			echo "SKIP: no topology.discovery.raw item found for '{$zabbix_host}' (push hasn't run yet?)\n";
			$skipped++;
			continue;
		}
		[, $itemid] = $located;

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
			];
			$local_ports[$attrs['if_index']] = $port($reporter_device_id, $attrs);
			if ($attrs['mac']) {
				$reporter_macs[] = $attrs['mac'];
			}
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

			$neighbor_if_index = $pseudo_if_index($neighbor['remote_port_id'] ?? null, $neighbor['remote_port_id_subtype'] ?? null);
			$neighbor_port_attrs = [
				'if_index' => $neighbor_if_index,
				'name' => $resolve_port_label($neighbor['remote_port_desc'] ?? null, $neighbor['remote_port_id'] ?? null, $neighbor['remote_port_id_subtype'] ?? null),
				'if_type' => 'physical',
				'mac' => $looks_like_mac ? strtolower($chassis_id) : null,
				'speed' => null,
				'admin_status' => 'up',
				'oper_status' => 'up',
				'learned_macs' => [],
				'zabbix_itemids' => [],
			];
			$neighbor_port_id = $port($neighbor_device_id, $neighbor_port_attrs);

			if ($local_port_id !== null) {
				$ensure_physical_link($local_port_id, $neighbor_port_id, 'lldp');
			}

			// Rule 2: reconcile the neighbor Device too, not just the reporter — a neighbor discovered by
			// LLDP might itself be an already-onboarded Zabbix Host with inventory MAC/chassis data.
			$reconcile_device($neighbor_device_id, $neighbor_attrs, array_filter([$neighbor_attrs['mac']]));
		}

		// Rule 2: reconcile the reporter Device against Host/Proxy inventory.
		$reconcile_device($reporter_device_id, $reporter_attrs, $reporter_macs);

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
