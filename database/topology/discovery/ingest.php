#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * ingest.php — topology discovery "ingest" component (topology-1-model.md "Ingest").
 *
 * Reads the LLD snapshots (topo_lld_snapshot: the rows of discovery rules with a topology_type, written by the server, from the native step or from
 * "Topology by push"), applies the reconciliation rules (topology-1-model.md "Far Device": upsert by identity key; the inventory MAC match
 * of Devices to Hosts (§10.2); never-delete-on-unlink; manual links are never downgraded (§9.2)), and writes to topo_nodes/topo_edges.
 * There is no other input (the Trapper blob path was removed, model §5.7).
 *
 * Runs standalone against the topology tables with plain PDO — the same approach as
 * database/topology/seed.php — rather than bootstrapping Zabbix's full DB/API layer
 * (GOTCHAS.md #8) for a one-off CLI script. $promote() is a byte-for-byte-equivalent reimplementation of
 * CTopologyPrototype::promote() — see the comment on it for the exact correspondence. Keep it that way: don't let this drift into
 * a second, subtly different set of rules.
 *
 * host_inventory (macaddress_a/macaddress_b) is read by SQL: a run has no Zabbix API user, only the database.
 *
 * Reporters are the hosts that have a usable snapshot (topology-1-model.md "Reporters and usable snapshots"), found in the database.
 * --zabbix-host is an optional operator filter to scope a run to specific host(s) (e.g. ad-hoc testing); omitting it runs everything.
 *
 * Usage:
 *   ingest.php --pdo-dsn <dsn> --pdo-user <user> [--pdo-password <password>] [--zabbix-host <host> ...]
 *   (the same settings may come from TOPOLOGY_PDO_DSN / _USER / _PASSWORD: a run started by the web server passes them that way, so nothing
 *   secret is on the command line.)
 */

function fail(string $message): void {
	fwrite(STDERR, $message."\n");
	exit(1);
}

$options = getopt('', ['pdo-dsn:', 'pdo-user:', 'pdo-password:',
	'zabbix-host:', 'reporter-order:', 'fail-pass-b:', 'help']);

if (isset($options['help'])) {
	fwrite(STDOUT, "See the file header for usage.\n");
	exit(0);
}

// ---- Run exclusion + status file (topology-1-model.md "Runs", "Run summary"). The run lock is a DATABASE advisory lock (MySQL GET_LOCK /
// PostgreSQL pg_try_advisory_lock), taken below once the connection exists: a file lock cannot do this job because
// the CLI and the web server's PHP-FPM do not share a temporary directory (FPM runs with a private /tmp), so they
// took different lock files and never excluded each other. The database is the one thing every caller shares. The
// STATUS file still lives in sys_get_temp_dir(): a run's status is visible to callers of the same temporary
// directory only (a web-started run to the web page; a CLI run is reported on the CLI) — see the loser's branch. ----

require_once __DIR__.'/../topology_contradiction.inc.php';
require_once __DIR__.'/ingest/TopologyNormalize.php';          // the step Normalize (model §6.0)
require_once __DIR__.'/ingest/IngestRun.php';                  // the state of a run, the step modules
require_once __DIR__.'/ingest/IngestKeys.php';
require_once __DIR__.'/ingest/IngestStore.php';
require_once __DIR__.'/ingest/IngestIdentify.php';
require_once __DIR__.'/ingest/IngestFarPort.php';
require_once __DIR__.'/ingest/IngestLinks.php';
require_once __DIR__.'/ingest/IngestObserve.php';
require_once __DIR__.'/../../../ui/include/classes/topology/CTopologyAddress.php';      // the decoder of the addresses of a neighbor (§4.3, §4.4), shared with the side panel

define('TOPOLOGY_INGEST_RUNTIME_DIR', sys_get_temp_dir());
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

$pdo_dsn = $options['pdo-dsn'] ?? getenv('TOPOLOGY_PDO_DSN') ?: null;
$pdo_user = $options['pdo-user'] ?? getenv('TOPOLOGY_PDO_USER') ?: null;
$pdo_password = $options['pdo-password'] ?? getenv('TOPOLOGY_PDO_PASSWORD') ?: '';

if (!$pdo_dsn || !$pdo_user) {
	fail("Usage: {$argv[0]} --pdo-dsn <dsn> --pdo-user <user> [--pdo-password <password>] [--zabbix-host <host> ...]\n\n".
		"The settings may also come from the TOPOLOGY_PDO_DSN / TOPOLOGY_PDO_USER / TOPOLOGY_PDO_PASSWORD env vars — never hardcode them in a script.\n\n".
		"Reporters are the hosts with a usable LLD snapshot (topology-1-model.md, Reporters and usable snapshots) — no reporter list needed. ".
		"--zabbix-host optionally scopes a run to specific host(s) for ad-hoc testing.");
}

// Optional operator scoping — a filter on top of dynamic discovery, never a substitute for it (see the
// file header). Empty means "no filter": every host with a usable snapshot is a reporter.
$zabbix_host_filter = isset($options['zabbix-host']) ? (array) $options['zabbix-host'] : [];

// ---- Connect, then take the run lock (the very first thing a run does with the database) ----

try {
	$pdo = new PDO($pdo_dsn, $pdo_user, $pdo_password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
}
catch (Throwable $exception) {
	fail('Cannot connect to the database: '.$exception->getMessage());
}

/** True when this connection now holds the topology ingest lock. Non-blocking; released when the connection ends. */
function acquire_ingest_lock(PDO $pdo): bool {
	if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql') {
		// advisory locks are per database already; the key only has to be the same for every caller
		return (bool) $pdo->query('SELECT pg_try_advisory_lock('.crc32('topology_ingest').')')->fetchColumn();
	}
	// MySQL lock names are server-wide: the database name keeps two databases on one server apart
	$name = 'topology_ingest:'.substr(md5((string) $pdo->query('SELECT DATABASE()')->fetchColumn()), 0, 16);
	$stmt = $pdo->prepare('SELECT GET_LOCK(?, 0)');
	$stmt->execute([$name]);
	return (int) $stmt->fetchColumn() === 1;
}

if (!acquire_ingest_lock($pdo)) {
	$message = 'Ingest already running (database advisory lock held) — exiting rather than running concurrently or '.
		'blocking. Try again once the in-progress run finishes.';
	// If the status file of THIS temporary directory says "running", that run is the holder and the file is its
	// truth: leave it alone. Otherwise the holder is a process with another temporary directory (the CLI next to a
	// web-started run, or the reverse): record the refusal, so a caller that follows the status (the page after
	// "Run discovery ingest") is told instead of waiting.
	$current = is_file(INGEST_STATUS_FILE) ? json_decode((string) @file_get_contents(INGEST_STATUS_FILE), true) : null;
	if (($current['status'] ?? null) !== 'running') {
		write_status(['status' => 'error', 'started_at' => time(), 'finished_at' => time(), 'summary' => null,
			'error' => $message]);
	}
	fail($message);
}
// The lock belongs to the connection: it is released when this process ends, however it ends (a fatal error, kill -9
// included), and is never released earlier — $pdo must stay the one connection of the run.

$run_started_at = time();
write_status(['status' => 'running', 'started_at' => $run_started_at, 'finished_at' => null, 'summary' => null]);

register_shutdown_function(static function () use ($run_started_at) {
	// Catches every path that doesn't already write a terminal status itself: an uncaught Throwable
	// escaping the whole script, a PHP fatal error (e.g. OOM), or an early fail()/exit(1) — all would otherwise
	// leave the status file stuck on "running" forever, wedging the UI's poll loop. Registered only after the
	// lock is held, so a run that lost it can never overwrite the status of the one that holds it.
	$error = error_get_last();
	$current = @file_get_contents(INGEST_STATUS_FILE);
	$current = $current ? json_decode($current, true) : null;
	if ($current !== null && $current['status'] === 'running') {
		write_status(['status' => 'error', 'started_at' => $run_started_at, 'finished_at' => time(),
			'summary' => null,
			'error' => $error ? $error['message'] : 'ingest.php exited without reporting a final status']);
	}
});

// ---- DB layer: mirrors seed.php's upsert helpers, plus the two pieces seed.php doesn't need
// (physical_link's no-downgrade rule, and Device<->Host/Proxy reconciliation) ----

$now = time();

// ---- Identity keys and segments (topology-1-model.md §6.7.0) ----
// A Device holds a set of identity keys (topo_identity_keys). The scope belongs to the key: a MAC chassis is global (`chassis:mac:<mac>`), any other
// chassis subtype and a management IP are scoped to the segment of the reporter that saw them (`chassis:<type>:<segment>:<value>`,
// `ip:<segment>:<addr>`). Segment of a reporter: its proxy group, else its proxy, else `server` (`pg:<id>`, `proxy:<id>`, `server`).
// attrs.chassis_id and attrs.mgmt_ip stay what the UI shows; identity is the key set.
$q_key = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql' ? '"key"' : '`key`';
// the state of the run and the step modules (ingest/): what the closures shared is a property of $run
$run = new IngestRun($pdo, $now, $q_key);
$run->keys = new IngestKeys($run);
$run->store = new IngestStore($run);
$run->identify = new IngestIdentify($run);
$run->farport = new IngestFarPort($run);
$run->links = new IngestLinks($run);
$run->observe = new IngestObserve($run);
// the state of the run is bound BEFORE any closure captures it by reference: the closures, the modules and the frame write the same arrays
$summary = &$run->summary;
$duplicate_candidates = &$run->duplicate_candidates;
$chassis_changes = &$run->chassis_changes;
$port_scope = &$run->port_scope;
$port_chassis_claims = &$run->port_chassis_claims;
$reporter_by_address = &$run->reporter_by_address;
$reporter_device_of = &$run->reporter_device_of;
$link_candidates = &$run->link_candidates;
$neighbor_snapshots = &$run->neighbor_snapshots;
$segment_of = [$run->keys, 'segmentOf'];
$mac_like = static fn (string $value): bool => TopologyNormalize::macLike($value);
/** normalization §4.1 "Placeholder MAC": the one predicate (TopologyNormalize::isPlaceholderMac) */
$is_placeholder_mac = static fn (?string $mac): bool => TopologyNormalize::isPlaceholderMac($mac);
$chassis_key = [$run->keys, 'chassisKey'];
$ip_key = [$run->keys, 'ipKey'];

$key_holders = [$run->keys, 'keyHolders'];
$device_has_kind = [$run->keys, 'deviceHasKind'];
$device_holds_key = [$run->keys, 'deviceHoldsKey'];
$insert_key = [$run->keys, 'insertKey'];
$register_keys = [$run->keys, 'registerKeys'];

$build_identity_keys = [$run->keys, 'buildIdentityKeys'];

$note_shared = [$run->identify, 'noteShared'];

$resolve_device = [$run->identify, 'resolveDevice'];

$find_port = [$run->store, 'findPort'];

$insert_node = $pdo->prepare('INSERT INTO topo_nodes (type, attrs, created_at, updated_at) VALUES (?, ?, ?, ?)');
$run->insert_node = $insert_node;
$insert_port_node = $pdo->prepare('INSERT INTO topo_nodes (type, device_id, attrs, created_at, updated_at) VALUES (?, ?, ?, ?, ?)');
$run->insert_port_node = $insert_port_node;
$update_node = $pdo->prepare('UPDATE topo_nodes SET attrs = ?, updated_at = ? WHERE id = ?');
$run->update_node = $update_node;
$insert_edge = $pdo->prepare('INSERT INTO topo_edges (type, src_id, dst_id, attrs, created_at) VALUES (?, ?, ?, ?, ?)');
$run->insert_edge = $insert_edge;
$last_insert_id = [$run->store, 'lastInsertId'];

// topology-1-model.md "Far Device" (§6.7.1, 2.47): a Device is found by its chassis key, or (a claim without a chassis) by the match of a port; there is no matched_by.
// $summary tallies create-vs-update counts for the /topo/ingest/status endpoint (topology-1-model.md "Run summary"). Just counting,
// no change to the matching/upsert rules themselves.
$summary = ['devices_created' => 0, 'devices_updated' => 0, 'ports_created' => 0, 'links_created' => 0,
	// Ports made from neighbor advertisements on a Device that has since become a reporter (its own walk returned real
	// ports), each counted once: ambiguous (several pseudo ports match one real port, so none was merged), move_failed (the
	// real port already had another link), kept_manual (a manual link sits on it, so the port stays), converted (its links
	// became device_links, the port was deleted), removed (no link at all). `pseudo_ports` lists them with the details.
	'pseudo_ports_converted' => 0, 'pseudo_ports_ambiguous' => 0, 'pseudo_ports_move_failed' => 0,
	'pseudo_ports_kept_manual' => 0, 'pseudo_ports_removed' => 0, 'pseudo_ports' => []];
$pseudo_note = [$run->farport, 'pseudoNote'];

$promote = [$run->store, 'promote'];

$find_or_create_host_node = [$run->store, 'findOrCreateHostNode'];

// topology-1-model.md §10.2 "Automatic MAC match (Host only)": part of every run (full and partial), after pass B; there is no separate action. One batched read of the
// inventory MACs (host_inventory.macaddress_a / macaddress_b of the monitored and not monitored hosts: what host.get with selectInventory returns; a run has no API
// user, so it reads the table, like the reporters), matched against the MACs of the Devices (the Device's own `mac` and the MAC of its Ports). A MAC in the inventory of
// several Hosts, or on several Devices, matches nothing (§6.7.4, `macs_shared`). A Device or a Host that already has an association is never matched (§4.5). A Host gets its
// topo_nodes row only here, on a match (§10.1). Returns nothing; counts the new associations in `hosts_associated_by_mac`.
$associate_hosts_by_mac = static function () use ($pdo, $promote, $find_or_create_host_node, $is_placeholder_mac, &$summary): void {
	$mac_text = static function (?string $value) use ($is_placeholder_mac): ?string {
		$digits = strtolower(preg_replace('/[^0-9a-fA-F]/', '', (string) $value));
		if (strlen($digits) !== 12 || preg_match('/[^0-9a-fA-F:.\- ]/', (string) $value) === 1) {
			return null;
		}
		$mac = implode(':', str_split($digits, 2));
		return $is_placeholder_mac($mac) ? null : $mac;          // a placeholder on either side never matches (normalization §4.1)
	};
	$hosts_by_mac = [];
	try {
		$rows = $pdo->query('SELECT hi.hostid, hi.macaddress_a, hi.macaddress_b FROM host_inventory hi JOIN hosts h ON h.hostid = hi.hostid'.
			' WHERE h.status IN (0, 1) AND (hi.macaddress_a <> \'\' OR hi.macaddress_b <> \'\') ORDER BY hi.hostid')->fetchAll(PDO::FETCH_ASSOC);
	}
	catch (PDOException $exception) {
		return;                    // a database without the Zabbix inventory table (a test stand-in): nothing to match
	}
	foreach ($rows as $row) {
		foreach (['macaddress_a', 'macaddress_b'] as $field) {
			if (($mac = $mac_text($row[$field])) !== null) {
				$hosts_by_mac[$mac][(int) $row['hostid']] = (int) $row['hostid'];
			}
		}
	}
	if (!$hosts_by_mac) {
		return;
	}
	$devices_by_mac = [];
	foreach ($pdo->query("SELECT id, device_id, type, JSON_UNQUOTE(JSON_EXTRACT(attrs, '\$.mac')) AS mac FROM topo_nodes".
			" WHERE type IN ('device', 'port') AND JSON_EXTRACT(attrs, '\$.mac') IS NOT NULL ORDER BY id")->fetchAll(PDO::FETCH_ASSOC) as $row) {
		$device_id = $row['type'] === 'device' ? (int) $row['id'] : ($row['device_id'] === null ? null : (int) $row['device_id']);
		if ($device_id !== null && ($mac = $mac_text($row['mac'])) !== null) {
			$devices_by_mac[$mac][$device_id] = $device_id;
		}
	}
	$note_shared = static function (string $kind, string $mac, array $ids) use (&$summary): void {
		$summary['macs_shared']['count']++;
		if (count($summary['macs_shared']['list']) < 200) {
			$summary['macs_shared']['list'][] = ['kind' => $kind, 'value' => $mac, ($kind === 'host' ? 'host_ids' : 'node_ids') => array_values($ids)];
		}
	};
	ksort($hosts_by_mac);
	foreach ($hosts_by_mac as $mac => $hosts) {
		$devices = $devices_by_mac[$mac] ?? [];
		if (count($hosts) > 1) {
			$note_shared('host', $mac, $hosts);
			continue;
		}
		if (count($devices) > 1) {
			$note_shared('device', $mac, $devices);
			continue;
		}
		if (!$devices) {
			continue;
		}
		$device_id = reset($devices);
		$hostid = reset($hosts);
		$represented = $pdo->prepare("SELECT 1 FROM topo_nodes WHERE id = ? AND represented_by_node_id IS NOT NULL");
		$represented->execute([$device_id]);
		if ($represented->fetch()) {
			continue;                // an existing association is never replaced
		}
		$host_node = $pdo->prepare("SELECT id FROM topo_nodes WHERE type = 'host' AND host_ref = ?");
		$host_node->execute([$hostid]);
		if (($node_id = $host_node->fetchColumn()) !== false) {
			$taken = $pdo->prepare("SELECT 1 FROM topo_nodes WHERE type = 'device' AND represented_by_node_id = ?");
			$taken->execute([$node_id]);
			if ($taken->fetch()) {
				continue;            // this Host represents another Device already
			}
		}
		$node_id = $find_or_create_host_node((string) $hostid);
		if ($promote($device_id, $node_id, 'mac')) {
			$summary['hosts_associated_by_mac']['count']++;
			if (count($summary['hosts_associated_by_mac']['list']) < 200) {
				$summary['hosts_associated_by_mac']['list'][] = ['host_id' => $hostid, 'node_id' => $node_id, 'device_id' => $device_id];
			}
		}
	}
};


// The ONE normalization of a port name: real ports ($find_matching_real_port), pseudo ports ($snap_pseudo_port) and the pseudo-port merge
// all compare names through it.
// ---- topology-6-normalization.md §3-§4: the same functions as the native step (preproc_topology.c) and push.py; normalization_cases.json tests all three ----
$norm_text_or_hex = static fn (string $raw): string => TopologyNormalize::textOrHex($raw);
$norm_clean = static fn (string $value): ?string => TopologyNormalize::clean($value);
$norm_name_key = static fn (string $value): ?string => TopologyNormalize::nameKey($value);
$norm_numeric_id = static fn (string $value): ?int => TopologyNormalize::numericId($value);
$norm_port_mac = static fn (string $mac, array $port_macs): ?int => TopologyNormalize::portMac($mac, $port_macs);
$norm_ip_text = static fn (string $text): ?string => TopologyNormalize::ipText($text);
$norm_man_addrs = static fn (string $entries): ?string => TopologyNormalize::manAddrs($entries);
$resolve_local_port = [$run->observe, 'resolveLocalPort'];
$normalize_port_name = [$run->farport, 'normalizePortName'];

$pseudo_local_if_index = [$run->farport, 'pseudoLocalIfIndex'];

// topology-1-model.md "Pseudo-port merge": run ONLY right after upserting a reporter's own real Port (never from the neighbor
// pseudo-Port creation path below — that path is unaffected, per scope). Looks for an existing pseudo-Port
// (attrs.pseudo = true, topology-1-model.md "Nodes: Attributes by type") on the same Device whose name normalizes to the same thing as the real Port
// just upserted. Exactly one match: the pseudo-Port was standing in for this exact physical port before
// this Device ever pushed its own data — re-point its physical_link edge(s) onto the real Port and delete
// it. Zero or more-than-one match: do nothing (never guess — the explicit instruction of topology-1-model.md "Pseudo-port merge"), just log it
// so it isn't silently missed either way.

$merge_pseudo_port = [$run->farport, 'mergePseudoPort'];

$identify_far_port = [$run->farport, 'identifyFarPort'];

$far_pseudo_name = [$run->farport, 'farPseudoName'];

// ============================================================================================================
// LLD snapshot path (topology-1-model.md "Ingest"). A host with rows in topo_lld_snapshot is a reporter whose
// data comes from discovery rules with a topology_type (the only input). It reuses the closures defined earlier
// (pseudo-port merge, promote, keys). Every last_seen written here comes from the snapshot's own
// clock, never from $now (topology-1-model.md "Snapshot rules", rule 3): running ingest twice over unchanged snapshots changes nothing.
// ============================================================================================================

const TOPO_TYPE_PORTS = 1;
const TOPO_TYPE_NEIGHBORS = 2;
const TOPO_TYPE_LEARNED_MACS = 3;
const TOPO_TYPE_LAG = 4;

// topology-1-model.md "`device_link` (Port → Device)": why a link ends at a Device instead of a Port. fdb_mac_only is
// reserved: validation accepts it, nothing in this script produces it.
const TOPO_FAR_PORT_REASONS = ['port_unmatched', 'port_shared_id', 'lag_ambiguous', 'manual', 'fdb_mac_only'];

$summary += [
	'pseudo_ports_reindexed' => 0,
	// topology-1-model.md §6.7.1 "Join of a split identity": Devices merged (kept, removed, key, value) and joins refused because both had a represented_by
	'reporters_processed' => 0,
	'reporters_skipped' => [],
	// identity keys built from the attributes of Devices that had none (data written before the key table; topology-1-model.md §6.7.0): the Devices
	// done, the keys written, and the Devices whose scoped keys could not be built because no segment is known (no reporter, no observation)
	'identity_keys_built' => ['devices' => 0, 'keys' => 0, 'skipped' => []],
	// topology-1-model.md §6.7.0 / §6.8 / §6.11: key rows whose Device is gone (removed at the start of a run; above 0 means a bug elsewhere), the
	// `ip:` keys no current input gives any more (removed at the end of a full run), and the reporters with a failed step (host, step, error)
	'identity_keys_orphaned' => 0,
	'reporters_failed' => ['count' => 0, 'list' => []],
	// hosts whose PORTS rows have no item ids at all (no standard interface discovery with the topology type PORTS): their links cannot be coloured (model §3.2)
	'hosts_ports_without_items' => ['count' => 0, 'list' => []],
	'snapshots_ignored_type_mismatch' => 0,
	'observations' => ['applied' => 0, 'device_only' => 0, 'shadowed' => 0, 'conflict' => 0, 'ambiguous' => 0],
	// topology-1-model.md "Run summary": per-run counts of the discovered-vs-discovered replacement rule.
	'links_superseded' => 0,
	'links_revived' => 0,
	'ports_ambiguous' => 0,
	// topology-1-model.md "Run summary": manual links that discovery contradicts and nobody acknowledged.
	'manual_links_contradicted' => 0,
	// Observations of rules that have no usable snapshot any more (rule or host disabled, topology type changed, reporter skipped,
	// snapshot gone): removed by a full run, see $clean_stale_observations.
	'observations_removed' => 0,
	// topology-1-model.md "Run summary": active device-level links at the end of the run, links converted to
	// port level (topology-1-model.md "Refinement: device-level → port-level") in this run, and the device-level links per reason.
	'links_device_level' => 0,
	// topology-1-model.md "Identity normalization": snapshot rows skipped because an identity field could not be normalized (per
	// rule: itemid => count), and hosts that report one topology type through both transports (SNMP rule and trapper rule).
	'rows_identity_invalid' => [],
	// model §6.7.1: neighbor rows with no key at all (no Device, no observation): the total and per rule (itemid => count)
	'rows_no_identity' => ['count' => 0, 'by_rule' => []],
	'rows_duplicate_claim' => ['count' => 0, 'by_rule' => []],
	'rows_local_port_unresolved' => ['count' => 0, 'by_reason' => ['name_on_several_ports' => 0, 'if_index_missing' => 0, 'no_ports' => 0]],
	// topology-1-model.md "Shared identifier (FR 4.h)": an identifier that matches more than one node identifies none of them.
	// A count and a list (key kind, value as received, node ids) of the chassis ids / IPs found on several Devices by this run
	// (neighbor rows and reporters), and of the MACs found on several Devices or in the inventory of several Hosts.
	'identity_keys_shared' => ['count' => 0, 'list' => []],
	'macs_shared' => ['count' => 0, 'list' => []],
	// topology-1-model.md §10.2: new Host associations by inventory MAC in this run (host id, Host node id, Device id)
	'hosts_associated_by_mac' => ['count' => 0, 'list' => []],
	'hosts_duplicate_transport' => [],
	'links_refined' => 0,
	'device_level_reasons' => [],
];

$snap_node_attrs = [$run->store, 'nodeAttrs'];

$snap_write_attrs = [$run->store, 'writeAttrs'];

$validate_edge = [$run->store, 'validateEdge'];

$convert_leftover_pseudo_ports = [$run->farport, 'convertLeftoverPseudoPorts'];

// IANA ifType 161 = ieee8023adLag; anything else is "physical" (same classification as push.py — there is no
// SNMP signal for "mgmt"). A value that is already one of the model's own labels passes through.
// topology-1-model.md §6.6 "Port class" — the ONE table of port classes (push.py and the native step follow it; the native step passes the IANA
// number through in {#IFTYPE}, this function decides). An already-classified value (physical, lag, mgmt, virtual) is kept.
//   lag      IANA 161
//   virtual  IANA 24 softwareLoopback, 53 propVirtual, 131 tunnel, 135 l2vlan, 136 l3ipvlan, 150 mplsTunnel, 166 mpls
//   mgmt     any other type whose normalized name starts with one of TOPO_MGMT_NAME_PREFIXES
//   physical everything else, including IANA 1 (other) and a missing type
// The name list is data: extending it is a change of this list only (and of push.py's PORT_CLASS_MGMT_NAME_PREFIXES).
const TOPO_PORT_CLASSES = ['physical', 'lag', 'mgmt', 'virtual'];
const TOPO_PORT_CLASS_LAG = [161];
const TOPO_PORT_CLASS_VIRTUAL = [24, 53, 131, 135, 136, 150, 166];
const TOPO_MGMT_NAME_PREFIXES = ['mgmt', 'management', 'fxp0', 'me0', 'oob'];
$snap_if_type = [$run->farport, 'ifType'];

$snap_status = [$run->farport, 'status'];

$snap_port = [$run->store, 'port'];

// topology-1-model.md §6.7.5 "Duplicate candidates": evidence of two Devices that may be one, collected in the collect phase and written to
// attrs.duplicate_candidates at the end of every FULL run (a partial run leaves the list alone). [device id on which it is shown => key => entry].
$duplicate_candidates = [];
$note_duplicate = [$run->identify, 'noteDuplicate'];

// A reporter whose host reports chassis Y while its Device S has another chassis (§6.4 step 1): [S id => Y]. The candidates are derived at the
// end of the run (below), when every Device that has chassis Y exists.
$chassis_changes = [];
$note_chassis_change = [$run->identify, 'noteChassisChange'];

$snap_device = [$run->identify, 'device'];

$snap_link = [$run->links, 'link'];

$snap_device_link = [$run->links, 'deviceLink'];

$snap_link_confirm_lower = [$run->store, 'confirmLower'];

$snap_other_links = [$run->store, 'otherLinks'];

$snap_link_reporter_self = [$run->links, 'linkReporterSelf'];

$snap_observation = [$run->store, 'observation'];

$snap_remote_key = [$run->observe, 'remoteKey'];

// Reporters = hosts with at least one usable snapshot. Snapshots of disabled rules are skipped; a snapshot
// written under a topology type the rule no longer has is ignored (the API also deletes it on a change of the topology type — this is
// the defense in depth from topology-2-lld-delivery.md "Topology type change"). Returns [hostid => reporter].
// Identity normalization of a snapshot row: TopologyNormalize::row (ingest/TopologyNormalize.php), the step "Normalize" of model §6.0.
$snap_normalize_row = static fn (array $row): array => TopologyNormalize::row($row);

$snap_load_reporters = static function (array $host_filter) use ($pdo, $snap_normalize_row, &$summary): array {
	$rows = $pdo->query(
		'SELECT s.itemid, s.hostid, s.topology_type, s.clock, s.rows_json, i.topology_type AS rule_topology_type, i.type AS rule_type,'.
		' i.status AS rule_status, h.status AS host_status, h.host, h.name AS host_name'.
		' FROM topo_lld_snapshot s JOIN items i ON i.itemid = s.itemid JOIN hosts h ON h.hostid = s.hostid'.
		' ORDER BY s.hostid, s.topology_type, s.itemid')->fetchAll(PDO::FETCH_ASSOC);
	$reporters = [];
	$transports = []; // hostid => topology type => source => transport => itemids
	foreach ($rows as $row) {
		if ($host_filter && !in_array($row['host'], $host_filter, true)) {
			continue;
		}
		// A usable snapshot belongs to an enabled rule on an enabled (monitored) host
		// (topology-1-model.md "Reporters and usable snapshots").
		if ((int) $row['rule_status'] !== 0 || (int) $row['host_status'] !== 0) {
			continue;
		}
		if ((int) $row['topology_type'] !== (int) $row['rule_topology_type']) {
			$summary['snapshots_ignored_type_mismatch']++;
			echo "SKIP: snapshot of rule #{$row['itemid']} was written for topology type {$row['topology_type']} but the rule now has ".
				"topology type {$row['rule_topology_type']}\n";
			continue;
		}
		$decoded = json_decode($row['rows_json'], true);
		if (!is_array($decoded)) {
			echo "SKIP: snapshot of rule #{$row['itemid']} holds invalid JSON\n";
			continue;
		}
		// One normalization for every transport (topology-1-model.md "Identity normalization"); a row that cannot be normalized is skipped.
		$normalized = [];
		foreach ($decoded as $snapshot_row) {
			[$snapshot_row, $problem] = is_array($snapshot_row) ? $snap_normalize_row($snapshot_row) : [null, 'not an object'];
			if ($snapshot_row === null) {
				$summary['rows_identity_invalid'][(int) $row['itemid']] = ($summary['rows_identity_invalid'][(int) $row['itemid']] ?? 0) + 1;
				echo "SKIP: a row of rule #{$row['itemid']} has an identity that cannot be normalized ({$problem})\n";
				continue;
			}
			$normalized[] = $snapshot_row;
		}
		$hostid = (int) $row['hostid'];
		$reporters[$hostid] ??= ['hostid' => $hostid, 'host' => $row['host'], 'name' => $row['host_name'],
			'snapshots' => []];
		$reporters[$hostid]['snapshots'][(int) $row['topology_type']][] = ['itemid' => (int) $row['itemid'],
			'clock' => (int) $row['clock'], 'rows' => $normalized];
		// ITEM_TYPE_TRAPPER = 2; every other rule type (SNMP agent, dependent on an SNMP walk, ...) is the LLD transport.
		// The same data from two transports is what is reported: the same topology type and, for NEIGHBORS, the same protocol
		// (the row's source, 'lldp' when absent). LLDP by SNMP and CDP by push is not a duplicate. An empty snapshot
		// has no rows, so no known source: it is not compared.
		$transport = (int) $row['rule_type'] === 2 ? 'trapper' : 'snmp';
		if ((int) $row['topology_type'] === TOPO_TYPE_NEIGHBORS) {
			$sources = array_unique(array_map(static fn(array $r): string => (string) ($r['source'] ?? 'lldp'), $normalized));
		}
		else {
			$sources = [''];
		}
		foreach ($sources as $source) {
			$transports[$hostid][(int) $row['topology_type']][$source][$transport][] = (int) $row['itemid'];
		}
	}
	// One host, one topology type (and protocol), rules of both transports: both are processed, the host is reported
	// (topology-1-model.md "Duplicate transports").
	foreach ($transports as $hostid => $by_type) {
		foreach ($by_type as $topology_type => $by_source) {
			foreach ($by_source as $source => $by_transport) {
				if (count($by_transport) > 1) {
					$summary['hosts_duplicate_transport'][] = ['hostid' => $hostid, 'host' => $reporters[$hostid]['host'],
						'topology_type' => $topology_type, 'source' => $source, 'itemids' => $by_transport];
				}
			}
		}
	}
	return $reporters;
};

// ---- Reporters: identity in two passes (topology-1-model.md §6.4, §6.5, §6.8) ----
// Pass A: the own identity of every reporter ($snap_reporter_identity), then per reporter PORTS -> LAG -> LEARNED_MACS ($snap_reporter_tables).
// Pass B: the NEIGHBORS rows of all reporters ($snap_reporter_neighbors), reporters in hostid order, rows sorted. So the result does not
// depend on the order in which the reporters happen to be listed. Each step returns ['status' => 'ok', ...] or ['status' => 'skipped', 'reason' => ...];
// the caller owns the transaction of each step. Nothing is written for a skipped reporter.
$link_candidates = [];
$neighbor_snapshots = [];

/** The own identity a reporter's snapshots and host give (§6.4 steps 2-4, 6, 7): no database write. */
$reporter_own = static function (array $reporter) use ($pdo, $segment_of, $chassis_key): array {
	$chassis_values = [];
	foreach ($reporter['snapshots'] as $list) {
		foreach ($list as $snapshot) {
			foreach ($snapshot['rows'] as $row) {
				if (($row['loc_chassis'] ?? '') !== '') {
					$chassis_values[$row['loc_chassis']] = true;
				}
			}
		}
	}
	$clocks = array_merge(...array_map(static fn (array $list): array => array_column($list, 'clock'), array_values($reporter['snapshots'])));
	$own = ['skip' => null, 'chassis_values' => array_keys($chassis_values), 'seen' => max($clocks), 'loc_chassis' => null, 'mgmt_ip' => null,
		'segment' => $segment_of($reporter['hostid']), 'loc_key' => null];
	if (count($chassis_values) > 1) {
		$own['skip'] = 'identity_ambiguous';
		return $own;
	}
	$own['loc_chassis'] = $chassis_values ? (string) array_key_first($chassis_values) : null;
	$interface = $pdo->prepare(
		'SELECT ip FROM interface WHERE hostid = ? AND type = 2 AND useip = 1 AND ip <> \'\' ORDER BY main DESC, interfaceid LIMIT 1');
	$interface->execute([$reporter['hostid']]);
	$own['mgmt_ip'] = $interface->fetchColumn() ?: null;
	$own['loc_key'] = $chassis_key($own['loc_chassis'], null, $own['segment']);
	if ($own['loc_chassis'] === null && $own['mgmt_ip'] === null) {
		$own['skip'] = 'identity_missing';
	}
	return $own;
};

/**
 * Pass A, step 1: the reporter's Device (§6.4). $claimed = the chassis keys that two or more reporters claim by their own data: this reporter does
 * not look for its Device by them, but its Device carries them (the key is then shared, §6.7.4). $key_shared = the reporter's own chassis finds several stored
 * Devices (decided before pass A, §6.4 step 2): the reporter gets a Device of its own that carries the key. The SNMP interface address is not looked up (2.47).
 */
$snap_reporter_identity = static function (array $reporter, array $own, array $claimed, bool $key_shared = false) use (
	$q_key,
	$pdo, $snap_device, $note_chassis_change, $segment_of, $chassis_key, $ip_key, $register_keys, $device_has_kind, $device_holds_key, $snap_port, $snap_link, $snap_other_links, $snap_observation, $snap_remote_key,
	$snap_node_attrs, $snap_write_attrs, $snap_if_type, $snap_status, $snap_link_reporter_self, $merge_pseudo_port,
	$far_pseudo_name, $pseudo_local_if_index, $normalize_port_name, $update_node, $now, &$summary,
	$convert_leftover_pseudo_ports, &$link_candidates, &$neighbor_snapshots
): array {
	$label = $reporter['host'];
	$loc_chassis = $own['loc_chassis'];
	$mgmt_ip = $own['mgmt_ip'];
	$loc_key = $own['loc_key'];
	$segment = $own['segment'];
	$reporter_seen = $own['seen'];
	if ($own['skip'] === 'identity_ambiguous') {
		echo "SKIP: reporter '{$label}' reports more than one loc_chassis (".implode(', ', $own['chassis_values']).
			") — identity is ambiguous, not picking one\n";
		return ['status' => 'skipped', 'reason' => 'identity_ambiguous'];
	}
	// the key this reporter looks its Device up by: its own chassis, unless another reporter claims it too
	$look_chassis = $loc_key !== null && !isset($claimed[$loc_key]) ? $loc_key : null;

	$existing = $pdo->prepare(
		"SELECT d.id FROM topo_nodes d JOIN topo_nodes h ON h.id = d.represented_by_node_id".
		" WHERE d.type = 'device' AND h.host_ref = ? AND d.represented_by_matched_by = 'reporter_self'");
	$existing->execute([$reporter['hostid']]);
	$device_id = $existing->fetchColumn();
	if ($device_id === false) {
		// the host is already the representation of a Device that an operator or a MAC match chose: that is the Device of this reporter
		$by_host = $pdo->prepare("SELECT d.id FROM topo_nodes h JOIN topo_nodes d ON d.represented_by_node_id = h.id".
			" WHERE h.type = 'host' AND h.host_ref = ? LIMIT 1");
		$by_host->execute([$reporter['hostid']]);
		$device_id = $by_host->fetchColumn();
	}

	if ($device_id === false) {
		if ($own['skip'] === 'identity_missing') {
			echo "SKIP: reporter '{$label}' has neither a loc_chassis nor an SNMP interface IP — nothing to make a Device of\n";
			return ['status' => 'skipped', 'reason' => 'identity_missing'];
		}
		// §6.4 step 2 and 5: found by its own unclaimed chassis among Devices that have no representation, else created (a reporter with only an SNMP interface
		// address gets a Device without a key); the Device carries the reporter's chassis, a claimed one too
		$device_id = $snap_device(['chassis_id' => $loc_chassis, 'mgmt_ip' => $mgmt_ip,
			'chassis_key' => $look_chassis, 'chassis_raw' => $loc_chassis,
			'sysname' => $reporter['name'], 'vendor' => 'unknown'], null, $reporter_seen, true, [], $key_shared);
		if ($device_id === null) {
			throw new RuntimeException("reporter '{$label}': no Device for its own identity");      // not reached: a reporter's key never stays shared
		}
		$register_keys((int) $device_id, ['chassis_key' => $loc_key], $reporter_seen, true);
		// The Device becomes this reporter's Device (§6.7.0 "Keys of a reporter's Device"): the chassis keys that neighbor rows gave it go, except the
		// reporter's own and the replaced ones. (A Device that already was somebody's - found by `represented_by` below - keeps its chassis: §6.4 step 1.)
		$pdo->prepare("DELETE FROM topo_identity_keys WHERE device_id = ? AND kind = 'chassis' AND replaced = 0".($loc_key !== null ? " AND {$q_key} <> ?" : ''))
			->execute($loc_key !== null ? [(int) $device_id, $loc_key] : [(int) $device_id]);
	}
	else {
		$device_id = (int) $device_id;
		$before = $snap_node_attrs($device_id);
		$attrs = $before;
		// a stored chassis id is never replaced by a different value (topology-1-model.md "Far Device")
		if ($loc_chassis !== null && ($before['chassis_id'] ?? '') === '') {
			$attrs['chassis_id'] = $loc_chassis;
		}
		// Chassis change (RMA, §6.4 step 1): the host reports chassis Y, S keeps X. The duplicate candidate `reporter_chassis_change` on S, and on
		// each Device C that has chassis Y (typically the Device the neighbors made for the new chassis); the operator decides (§6.7.5).
		// (the Devices C that have chassis Y are looked up at the end of the run: the neighbors that make them may be processed later)
		// S has a chassis when it holds a key of the chassis kind; the reported one is another when S does not hold that key.
		if ($loc_key !== null && $device_has_kind($device_id, 'chassis') && !$device_holds_key($device_id, $loc_key)) {
			$note_chassis_change($device_id, (string) $loc_chassis, $loc_key);
		}
		// the key the reporter gives its own Device: when S has no chassis yet, the reported one
		$register_keys($device_id, ['chassis_key' => $loc_key], $reporter_seen, true);
		if ($mgmt_ip !== null) {
			$attrs['mgmt_ip'] = $mgmt_ip;
		}
		$snap_write_attrs($device_id, $attrs, $before);      // attrs.last_seen is set at the end of the run (§8.7)
	}
	$device_id = (int) $device_id;
	$snap_link_reporter_self($device_id, (string) $reporter['hostid'], $label);

	return ['status' => 'ok', 'device_id' => $device_id, 'segment' => $segment];
};

/** Pass A, step 2: PORTS, LAG and LEARNED_MACS of one reporter (§6.5). */
$snap_reporter_tables = static function (array $reporter, int $device_id) use (

	$pdo, $snap_device, $note_chassis_change, $segment_of, $chassis_key, $ip_key, $register_keys, $device_has_kind, $device_holds_key, $snap_port, $snap_link, $snap_other_links, $snap_observation, $snap_remote_key,
	$snap_node_attrs, $snap_write_attrs, $snap_if_type, $snap_status, $snap_link_reporter_self, $merge_pseudo_port,
	$far_pseudo_name, $pseudo_local_if_index, $normalize_port_name, $update_node, $now, &$summary,
	$convert_leftover_pseudo_ports, &$link_candidates, &$neighbor_snapshots
): array {
	$snapshots = $reporter['snapshots'];

	// ---- PORTS ----
	// zabbix_itemids (topology-1-model.md §3.2): the items the rule's own LLD run made from the row (`itemids`, set by the server). A host can have two
	// PORTS rules (the topology template and the standard interface discovery): their rows join by if_index, the ids come from the one with item
	// prototypes (the other has []). The list is replaced by every run; a row without the field (an older snapshot) has none.
	$port_items = [];
	foreach ($snapshots[TOPO_TYPE_PORTS] ?? [] as $snapshot) {
		foreach ($snapshot['rows'] as $row) {
			$port_items[(int) $row['if_index']] = array_values(array_unique(array_merge($port_items[(int) $row['if_index']] ?? [],
				array_map('intval', is_array($row['itemids'] ?? null) ? $row['itemids'] : []))));
			sort($port_items[(int) $row['if_index']]);
		}
	}
	if ($port_items && !array_filter($port_items)) {
		$summary['hosts_ports_without_items']['count']++;
		if (count($summary['hosts_ports_without_items']['list']) < 200) {
			$summary['hosts_ports_without_items']['list'][] = ['hostid' => (int) $reporter['hostid'], 'host' => (string) $reporter['host']];
		}
	}
	foreach ($snapshots[TOPO_TYPE_PORTS] ?? [] as $snapshot) {
		foreach ($snapshot['rows'] as $row) {
			$if_index = (int) $row['if_index'];
			$overlay = ['if_index' => $if_index, 'pseudo' => false, 'zabbix_itemids' => $port_items[$if_index] ?? []];
			$row['name'] = ($row['name'] ?? '') !== '' ? $row['name'] : ($row['descr'] ?? '');       // the port's display name: ifName, else ifDescr (the step does not choose)
			foreach (['name', 'mac', 'speed'] as $key) {
				if (($row[$key] ?? '') !== '') {
					$overlay[$key] = $row[$key];
				}
			}
			if (array_key_exists('if_type', $row) || ($row['name'] ?? '') !== '') {
				$overlay['if_type'] = $snap_if_type((string) ($row['if_type'] ?? ''), (string) ($row['name'] ?? ''));
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

	// A Device with real ports keeps no pseudo-Port made from an advertisement (see $convert_leftover_pseudo_ports).
	if (!empty($snapshots[TOPO_TYPE_PORTS])) {
		$convert_leftover_pseudo_ports($device_id);
	}

	// ---- LAG (only when the reporter has a LAG snapshot; otherwise no lag_id is ever touched) ----
	if (!empty($snapshots[TOPO_TYPE_LAG])) {
		$member_ids = [];
		foreach ($snapshots[TOPO_TYPE_LAG] as $snapshot) {
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

	// ---- LEARNED_MACS (only when the reporter has such a snapshot) ----
	if (!empty($snapshots[TOPO_TYPE_LEARNED_MACS])) {
		$per_port = [];
		foreach ($snapshots[TOPO_TYPE_LEARNED_MACS] as $snapshot) {
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


	return ['status' => 'ok'];
};

// ============================================================================================================
// The Device of a claim without a chassis (topology-1-model.md §6.7.1, 2.47). Addresses and names are attributes, matched within one reporter port.
//  - $reporter_by_address: segment => address => [hostid => true]: the SNMP interface addresses of the reporters identified in this run (filled after pass A);
//    $reporter_device_of: hostid => the id of that reporter's Device.
//  - $port_scope: local port node id => [Device id => ['addrs' => [address => true], 'name' => text]]: the Devices without a chassis and without an association that
//    have attrs.scope_port = that port, with their addresses (attrs.mgmt_ip and the addresses of their observations of the PREVIOUS run: the observations are written
//    at the end of the run, so during pass B the table still holds them) and their name (attrs.sysname). Loaded by two queries when the first claim without a chassis
//    needs it (no query per claim) and kept up to date by the claims of this run (a claim never makes a second Device, §6.7.1).
//  - $port_chassis_claims: "hostid|local port" => [the claims with a chassis of this run]: pass B resolves them first (rank 0, §6.5).
$reporter_by_address = [];
$reporter_device_of = [];
$port_scope = null;
$port_chassis_claims = [];
$load_port_scope = [$run->identify, 'loadPortScope'];
$register_port_device = [$run->identify, 'registerPortDevice'];
$find_chassisless_device = [$run->identify, 'findChassisless'];

$snap_reporter_neighbors = [$run->observe, 'reporterNeighbors'];

$alloc_pseudo_index = [$run->farport, 'allocPseudoIndex'];

$snap_pseudo_port = [$run->farport, 'pseudoPort'];

$reindex_pseudo_ports = [$run->farport, 'reindexPseudoPorts'];

$snap_classify_candidates = [$run->farport, 'classifyCandidates'];

$snap_resolve_links = [$run->links, 'resolveLinks'];

// ---- Testability hook, no effect on a normal CLI invocation (the constant is never defined
// there) ----
//
// test_normalization.php, test_identity_normalization.php and test_device_link_validation.php include this script with TOPOLOGY_INGEST_TEST_HOOK defined
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

$processed = 0;
$skipped = 0;

// ---- LLD snapshot reporters (topology-1-model.md "Reporters and usable snapshots") ----
$reindex_pseudo_ports();
$snapshot_reporters = $snap_load_reporters($zabbix_host_filter);
$build_identity_keys();

$count_contradicted_manual_links = [$run->links, 'countContradictedManualLinks'];

// An observation mirrors the latest snapshot of its rule (topology-1-model.md "Observations"). When the rule has no usable snapshot any more —
// disabled (the rule or its host), its topology type changed, its reporter skipped (identity), the snapshot invalid or gone — nothing refreshes the
// rows and they would keep saying what the rule said last: a stale shadow would keep a manual link contradicted, a
// stale conflict would keep blocking an operator's eye. A FULL run therefore removes the observations of every rule
// that did not contribute a snapshot to it; a run scoped with --zabbix-host cannot tell "not selected" from "not
// usable" and leaves them alone. The readers (topology.observations.get, the contradictions) apply the same test at
// read time (enabled rule, snapshot of the rule's current topology type), so a stale row is not shown even before the next run.
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
else {
	ksort($snapshot_reporters);       // §6.5: the reporters are processed in hostid order
}

$link_candidates = [];
$neighbor_snapshots = [];
// A reporter with a failed step is failed for the rest of the run (§6.8): its later steps do not run, the run summary lists it (reporters_failed), and
// Resolve changes nothing that depends on its NEIGHBORS rows: its observations stay, and the links they stand for are held (not superseded, not touched).
$failed_hosts = [];
$frozen_itemids = [];
$note_failed_reporter = static function ($hostid, array $reporter, string $step, string $error) use (&$failed_hosts, &$frozen_itemids, &$summary): void {
	$failed_hosts[(int) $hostid] = true;
	foreach ($reporter['snapshots'][TOPO_TYPE_NEIGHBORS] ?? [] as $snapshot) {
		$frozen_itemids[(int) $snapshot['itemid']] = true;
	}
	$summary['reporters_failed']['count']++;
	if (count($summary['reporters_failed']['list']) < 200) {
		$summary['reporters_failed']['list'][] = ['hostid' => (int) $hostid, 'host' => (string) $reporter['host'], 'step' => $step, 'error' => substr($error, 0, 300)];
	}
};
// ---- the claims: a chassis that two reporters claim by their own data identifies neither (§6.4 step 5) ----
$reporter_own_of = [];
$claimants = [];
foreach ($snapshot_reporters as $hostid => $reporter) {
	$reporter_own_of[$hostid] = $reporter_own($reporter);
	if ($reporter_own_of[$hostid]['skip'] !== null) {
		continue;
	}
	if (($claimed_key = $reporter_own_of[$hostid]['loc_key']) !== null) {
		$claimants[$claimed_key][$hostid] = true;
	}
}
$claimed_keys = [];
foreach ($claimants as $claimed_key => $hosts) {
	if (count($hosts) > 1) {
		$claimed_keys[$claimed_key] = array_keys($hosts);
	}
}
// A reporter whose own chassis finds several stored Devices gets a Device of its own (§6.4 step 2). Decided here, before pass A, from the stored state, so the
// result does not depend on the order of the reporters.
$key_shared_hosts = [];
foreach ($snapshot_reporters as $hostid => $reporter) {
	$own = $reporter_own_of[$hostid];
	if ($own['skip'] !== null || $own['loc_key'] === null || isset($claimed_keys[$own['loc_key']])) {
		continue;
	}
	$has_device = $pdo->prepare("SELECT 1 FROM topo_nodes h JOIN topo_nodes d ON d.represented_by_node_id = h.id WHERE h.type = 'host' AND h.host_ref = ?");
	$has_device->execute([$hostid]);
	if ($has_device->fetchColumn() !== false) {
		continue;
	}
	$match = $resolve_device(['chassis' => [$own['loc_key'], (string) $own['loc_chassis']]], true);
	if ($match !== null && $match[0] === 'shared') {
		$key_shared_hosts[(int) $hostid] = $match;      // the reporter's own chassis finds several Devices
	}
}

// ---- pass A, step 1: the own identity of every reporter ----
$identified = [];
$reporter_seen = [];          // hostid of a reporter => newest clock of its usable snapshots (§8.7)
foreach ($snapshot_reporters as $hostid => $reporter) {
	echo "--- {$reporter['host']} (LLD snapshots) ---\n";
	try {
		$pdo->beginTransaction();
		$result = $snap_reporter_identity($reporter, $reporter_own_of[$hostid], $claimed_keys, isset($key_shared_hosts[$hostid]));
		if ($result['status'] === 'ok') {
			$pdo->commit();
			$identified[$hostid] = $result;
			$reporter_seen[(int) $hostid] = (int) $reporter_own_of[$hostid]['seen'];      // §8.7: the clock of its usable snapshots, kept if a later step fails
			if (isset($key_shared_hosts[$hostid])) {
				$note_shared($key_shared_hosts[$hostid][1], (string) $key_shared_hosts[$hostid][2], $key_shared_hosts[$hostid][3]);
			}
		}
		else {
			$pdo->rollBack();
			$summary['reporters_skipped'][$result['reason']] = ($summary['reporters_skipped'][$result['reason']] ?? 0) + 1;
			$skipped++;
		}
	}
	catch (Throwable $exception) {
		if ($pdo->inTransaction()) {
			$pdo->rollBack();
		}
		fwrite(STDERR, "ERROR: failed to ingest the identity of '{$reporter['host']}': {$exception->getMessage()}\n");
		$summary['reporters_skipped']['error'] = ($summary['reporters_skipped']['error'] ?? 0) + 1;
		$skipped++;
		$note_failed_reporter($hostid, $reporter, 'identity', $exception->getMessage());
	}
}
// the SNMP interface addresses of the reporters (§6.7.1: a claim without a chassis finds the Device of the one reporter that has its address in the segment)
foreach ($identified as $hostid => $result) {
	$own = $reporter_own_of[$hostid];
	$reporter_device_of[(int) $hostid] = (int) $result['device_id'];
	if ($own['mgmt_ip'] !== null && ($text = CTopologyAddress::ipText((string) $own['mgmt_ip'])) !== null && CTopologyAddress::countsAsIdentity($text)) {
		$reporter_by_address[$own['segment']][$text][(int) $hostid] = true;
	}
}
// `reporters_share_key` (§6.7.5): the reporters that claim one key get the candidate on their Devices, and the key is reported as shared (§6.7.4)
foreach ($claimed_keys as $claimed_key => $hosts) {
	$devices = [];
	foreach ($hosts as $hostid) {
		if (isset($identified[$hostid])) {
			$devices[$hostid] = (int) $identified[$hostid]['device_id'];
		}
	}
	$first_own = $reporter_own_of[array_key_first($devices) ?? $hosts[0]];
	$raw_value = (string) $first_own['loc_chassis'];
	$kind_name = 'chassis_id';
	$device_ids = array_values(array_unique($devices));
	foreach ($device_ids as $one) {
		foreach ($device_ids as $other) {
			if ($one !== $other) {
				$note_duplicate($one, $other, 'reporters_share_key', $kind_name, $raw_value);
			}
		}
	}
	if (count($device_ids) > 1) {
		$note_shared($kind_name, $raw_value, $device_ids);
	}
}
// `reporter_key_shared` (§6.4 step 2, §6.7.5; the chassis only since 2.47): the evidence is current: a reporter's `loc_chassis` is on two or more Devices besides the
// reporter's own Device. A chassis that two reporters claim is `reporters_share_key`. Computed here for every reporter, so the next runs (which find the Device by
// step 1) keep the candidate.
foreach ($identified as $hostid => $result) {
	$own = $reporter_own_of[$hostid];
	$device = (int) $result['device_id'];
	if ($own['loc_key'] === null || isset($claimed_keys[$own['loc_key']])) {
		continue;
	}
	$others = array_values(array_diff($key_holders($own['loc_key']), [$device]));
	if (count($others) > 1) {
		foreach ($others as $other) {
			$note_duplicate($device, $other, 'reporter_key_shared', 'chassis_id', (string) $own['loc_chassis']);
			$note_duplicate($other, $device, 'reporter_key_shared', 'chassis_id', (string) $own['loc_chassis']);
		}
	}
}
// ---- pass A, step 2: PORTS, LAG, LEARNED_MACS of every identified reporter ----
foreach ($identified as $hostid => $result) {
	$reporter = $snapshot_reporters[$hostid];
	try {
		$pdo->beginTransaction();
		$snap_reporter_tables($reporter, (int) $result['device_id']);
		$pdo->commit();
	}
	catch (Throwable $exception) {
		if ($pdo->inTransaction()) {
			$pdo->rollBack();
		}
		fwrite(STDERR, "ERROR: failed to ingest the ports of '{$reporter['host']}': {$exception->getMessage()}\n");
		$summary['reporters_skipped']['error'] = ($summary['reporters_skipped']['error'] ?? 0) + 1;
		$skipped++;
		unset($identified[$hostid]);
		$note_failed_reporter($hostid, $reporter, 'tables', $exception->getMessage());
	}
}
// ---- pass B: the NEIGHBORS rows of all reporters (reporters in hostid order, rows sorted) ----
// §6.5: pass B walks the claims of all reporters in one sorted order, (reporter hostid, if_index, rank, remote_key, itemid), whatever order pass A used (the test hook
// --reporter-order changes only pass A): the attributes that "the last row wins" (§6.7.1) do not depend on the order of the reporters.
$pass_b_reporters = $identified;
ksort($pass_b_reporters);
foreach ($pass_b_reporters as $hostid => $result) {
	$reporter = $snapshot_reporters[$hostid];
	$mark = [count($link_candidates), $neighbor_snapshots];
	try {
		$pdo->beginTransaction();
		$snap_reporter_neighbors($reporter, (int) $result['device_id'], (string) $result['segment']);
		if (isset($options['fail-pass-b']) && (int) $options['fail-pass-b'] === (int) $hostid) {
			throw new RuntimeException('forced failure in pass B (test hook --fail-pass-b)');
		}
		$pdo->commit();
		$summary['reporters_processed']++;
		$processed++;
		echo "OK: ingested LLD snapshots for '{$reporter['host']}' — device #{$result['device_id']}, topology types: ".
			implode(',', array_keys($reporter['snapshots']))."\n";
	}
	catch (Throwable $exception) {
		if ($pdo->inTransaction()) {
			$pdo->rollBack();
		}
		$link_candidates = array_slice($link_candidates, 0, $mark[0]);
		$neighbor_snapshots = $mark[1];
		fwrite(STDERR, "ERROR: failed to ingest the neighbors of '{$reporter['host']}': {$exception->getMessage()}\n");
		$summary['reporters_skipped']['error'] = ($summary['reporters_skipped']['error'] ?? 0) + 1;
		$skipped++;
		$note_failed_reporter($hostid, $reporter, 'neighbors', $exception->getMessage());
	}
}

// Phase 2: links and observations, decided once for all reporters (topology-1-model.md "Two phases").
$phase_2_done = true;
if ($link_candidates || $neighbor_snapshots) {
	try {
		$pdo->beginTransaction();
		$frozen_edges = [];
		if ($frozen_itemids) {
			$frozen_edges = array_map('intval', $pdo->query('SELECT DISTINCT edge_id FROM topo_observations WHERE edge_id IS NOT NULL AND itemid IN ('.
				implode(',', array_map('intval', array_keys($frozen_itemids))).')')->fetchAll(PDO::FETCH_COLUMN));
		}
		$snap_resolve_links($link_candidates, $neighbor_snapshots, !$zabbix_host_filter, $frozen_edges);
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
		$clean_stale_observations(array_merge(array_keys($neighbor_snapshots), array_keys($frozen_itemids)));
		$pdo->commit();
	}
	catch (Throwable $exception) {
		if ($pdo->inTransaction()) {
			$pdo->rollBack();
		}
		fwrite(STDERR, "ERROR: failed to remove stale observations: {$exception->getMessage()}\n");
	}
}

// ---- topology-1-model.md §10.2: the inventory MAC match, part of every run, after pass B ----
try {
	$pdo->beginTransaction();
	$associate_hosts_by_mac();
	$pdo->commit();
}
catch (Throwable $exception) {
	if ($pdo->inTransaction()) {
		$pdo->rollBack();
	}
	fwrite(STDERR, "ERROR: the inventory MAC match failed: {$exception->getMessage()}\n");
}

if (!$snapshot_reporters) {
	// Zero reporters is a normal "nothing onboarded yet" state (topology-1-model.md "Reporters and usable snapshots"), not an error — succeed with
	// nothing to do rather than failing the run.
	echo "No reporters found (no host has an LLD snapshot yet — nothing onboarded, or --zabbix-host didn't match any onboarded ".
		"reporter). Nothing to ingest.\n";
	if (!$zabbix_host_filter) {
		$clean_stale_observations([]);
	}
	write_status(['status' => 'done', 'started_at' => $run_started_at, 'finished_at' => time(),
		'summary' => $summary, 'error' => null]);
	exit(0);
}

echo "\nDone: {$processed} reporter(s) ingested, {$skipped} skipped/failed.\n";

// topology-1-model.md §6.7.5: every FULL run rewrites attrs.duplicate_candidates on every Device from the evidence of this run (an entry whose
// evidence is gone disappears); a pair that either Device lists in attrs.duplicate_dismissed is not listed. A partial run does not touch the lists.
// Ingest never writes duplicate_dismissed.
// topology-1-model.md §8.7 "Device not seen": attrs.last_seen of a Device = the max of (a) the clock of the usable snapshots of the host of its
// `represented_by` (any matched_by) and (b) last_seen of the observations whose device_id is this Device, whatever the outcome. It only moves
// forward, so two runs without new snapshots change nothing and an older snapshot never makes it smaller. "Not seen" is computed by the reader.
$observed = [];
foreach ($pdo->query('SELECT device_id, MAX(last_seen) AS seen FROM topo_observations WHERE device_id IS NOT NULL GROUP BY device_id')->fetchAll(PDO::FETCH_ASSOC) as $row) {
	$observed[(int) $row['device_id']] = (int) $row['seen'];
}
$summary['devices_last_seen_moved'] = 0;
foreach ($pdo->query("SELECT d.id, d.attrs, h.host_ref FROM topo_nodes d LEFT JOIN topo_nodes h ON h.id = d.represented_by_node_id AND h.type = 'host'".
		" WHERE d.type = 'device'")->fetchAll(PDO::FETCH_ASSOC) as $row) {
	$id = (int) $row['id'];
	// (a): the host of the Device's `represented_by`, whatever `matched_by` is (reporter_self, manual, mac); a host without a usable snapshot gives nothing
	$own = $row['host_ref'] !== null ? ($reporter_seen[(int) $row['host_ref']] ?? 0) : 0;
	$wanted = max($own, $observed[$id] ?? 0);
	$attrs = json_decode($row['attrs'], true) ?: [];
	if ($wanted > (int) ($attrs['last_seen'] ?? 0)) {
		$before = $attrs;
		$attrs['last_seen'] = $wanted;
		$snap_write_attrs($id, $attrs, $before);
		$summary['devices_last_seen_moved']++;
	}
}

$summary['duplicate_candidates'] = ['count' => 0];
if (!$zabbix_host_filter) {
	// `same_address` and `same_name` (§6.7.5, 2.47), from the current evidence: two Devices that have one address in one segment (the addresses of their current
	// observations: `rem_mgmt_ip` and every entry of `rem_man_addrs`, and a reporter's SNMP interface address; unspecified, loopback, multicast and link-local addresses do not
	// count); two Devices without a chassis with equal name keys. A pair with the address only when one of the two has no MAC chassis (2.48). Read from the tables as the run leaves them: the observations
	// of a failed reporter are the ones of its last good snapshot.
	$by_address = [];      // "segment|address" => [device id => true]
	$device_segments = []; // device id => [segment => true]: where the Device is seen from (its observations, its own host, the port it is scoped to)
	foreach ($pdo->query('SELECT o.device_id, o.remote_attrs, i.hostid FROM topo_observations o JOIN items i ON i.itemid = o.itemid WHERE o.device_id IS NOT NULL')->fetchAll(PDO::FETCH_ASSOC) as $row) {
		$device_segments[(int) $row['device_id']][$segment_of($row['hostid'])] = true;
		foreach (CTopologyAddress::claimAddresses(json_decode((string) $row['remote_attrs'], true) ?: []) as $address) {
			$by_address[$segment_of($row['hostid']).'|'.$address][(int) $row['device_id']] = true;
		}
	}
	foreach ($identified as $hostid => $result) {
		$device_segments[(int) $result['device_id']][$reporter_own_of[$hostid]['segment']] = true;
	}
	// a Device without a chassis that no row sees now ("not seen") still has its last address (attrs.mgmt_ip), in the segment of the reporter whose port it is scoped to
	$port_hosts = [];
	foreach ($pdo->query("SELECT p.id, h.host_ref FROM topo_nodes p JOIN topo_nodes d ON d.id = p.device_id JOIN topo_nodes h ON h.id = d.represented_by_node_id AND h.type = 'host'".
			" WHERE p.type = 'port'")->fetchAll(PDO::FETCH_ASSOC) as $row) {
		$port_hosts[(int) $row['id']] = (int) $row['host_ref'];
	}
	foreach ($pdo->query("SELECT id, attrs FROM topo_nodes WHERE type = 'device' AND attrs LIKE '%scope_port%'")->fetchAll(PDO::FETCH_ASSOC) as $row) {
		$device_attrs = json_decode($row['attrs'], true) ?: [];
		if (isset($device_attrs['scope_port'], $port_hosts[(int) $device_attrs['scope_port']])) {
			$device_segments[(int) $row['id']][$segment_of($port_hosts[(int) $device_attrs['scope_port']])] = true;
		}
	}
	foreach ($pdo->query("SELECT id, attrs FROM topo_nodes WHERE type = 'device' AND attrs LIKE '%mgmt_ip%'")->fetchAll(PDO::FETCH_ASSOC) as $row) {
		$device_attrs = json_decode($row['attrs'], true) ?: [];
		if (is_string($device_attrs['mgmt_ip'] ?? null) && ($text = CTopologyAddress::ipText($device_attrs['mgmt_ip'])) !== null && CTopologyAddress::countsAsIdentity($text)) {
			foreach (array_keys($device_segments[(int) $row['id']] ?? []) as $segment) {
				$by_address[$segment.'|'.$text][(int) $row['id']] = true;
			}
		}
	}
	foreach ($identified as $hostid => $result) {
		$own = $reporter_own_of[$hostid];
		if ($own['mgmt_ip'] !== null && ($text = CTopologyAddress::ipText((string) $own['mgmt_ip'])) !== null && CTopologyAddress::countsAsIdentity($text)) {
			$by_address[$own['segment'].'|'.$text][(int) $result['device_id']] = true;
		}
	}
	// 2.48: a pair is marked only when at least one of the two Devices has no MAC chassis (four FortiAP with four MAC chassis and one factory address are four devices)
	$mac_chassis = array_flip(array_map('intval', $pdo->query("SELECT DISTINCT device_id FROM topo_identity_keys WHERE kind = 'chassis' AND replaced = 0".
		" AND {$q_key} LIKE 'chassis:mac:%'")->fetchAll(PDO::FETCH_COLUMN)));
	foreach ($by_address as $key => $device_ids) {
		$device_ids = array_keys($device_ids);
		$address = substr($key, strpos($key, '|') + 1);
		foreach ($device_ids as $one) {
			foreach ($device_ids as $other) {
				if ($one !== $other && !(isset($mac_chassis[$one]) && isset($mac_chassis[$other]))) {
					$note_duplicate($one, $other, 'same_address', 'address', $address);
				}
			}
		}
	}
	$by_name = [];         // name key => [device id => true], the Devices without a chassis
	foreach ($pdo->query("SELECT d.id, d.attrs FROM topo_nodes d WHERE d.type = 'device' AND NOT EXISTS (SELECT 1 FROM topo_identity_keys k WHERE k.device_id = d.id AND k.kind = 'chassis')")
			->fetchAll(PDO::FETCH_ASSOC) as $row) {
		$device_attrs = json_decode($row['attrs'], true) ?: [];
		if (($device_attrs['chassis_id'] ?? '') === '' && ($name_key = CTopologyAddress::nameKey((string) ($device_attrs['sysname'] ?? ''))) !== '') {
			$by_name[$name_key][(int) $row['id']] = true;
		}
	}
	foreach ($by_name as $name_key => $device_ids) {
		$device_ids = array_keys($device_ids);
		foreach ($device_ids as $one) {
			foreach ($device_ids as $other) {
				if ($one !== $other) {
					$note_duplicate($one, $other, 'same_name', 'sysname', $name_key);
				}
			}
		}
	}
	foreach ($chassis_changes as $s_id => [$chassis, $chassis_key_y]) {
		$found_c = array_values(array_diff($key_holders($chassis_key_y), [$s_id]));
		if (!$found_c) {
			$note_duplicate($s_id, null, 'reporter_chassis_change', 'chassis_id', $chassis);
		}
		foreach ($found_c as $c) {
			$note_duplicate($s_id, $c, 'reporter_chassis_change', 'chassis_id', $chassis);
			$note_duplicate($c, $s_id, 'reporter_chassis_change', 'chassis_id', $chassis);
		}
	}
	$exists = array_flip(array_map('intval', $pdo->query("SELECT id FROM topo_nodes WHERE type = 'device'")->fetchAll(PDO::FETCH_COLUMN)));
	$dismissed = [];
	foreach ($pdo->query("SELECT id, attrs FROM topo_nodes WHERE type = 'device' AND attrs LIKE '%duplicate_dismissed%'")->fetchAll(PDO::FETCH_ASSOC) as $row) {
		foreach ((json_decode($row['attrs'], true)['duplicate_dismissed'] ?? []) as $entry) {
			$dismissed[(int) $row['id'].'|'.(int) ($entry['device_id'] ?? 0)] = true;
			$dismissed[(int) ($entry['device_id'] ?? 0).'|'.(int) $row['id']] = true;
		}
	}
	foreach ($pdo->query("SELECT id, attrs FROM topo_nodes WHERE type = 'device'")->fetchAll(PDO::FETCH_ASSOC) as $row) {
		$id = (int) $row['id'];
		$attrs = json_decode($row['attrs'], true) ?: [];
		$want = [];
		foreach ($duplicate_candidates[$id] ?? [] as $entry) {
			if ($entry['device_id'] !== null && (!isset($exists[$entry['device_id']]) || isset($dismissed[$id.'|'.$entry['device_id']]))) {
				continue;
			}
			$want[] = $entry;
		}
		usort($want, static fn (array $a, array $b): int => [$a['reason'], $a['device_id'] ?? 0, $a['value']] <=> [$b['reason'], $b['device_id'] ?? 0, $b['value']]);
		$summary['duplicate_candidates']['count'] += count($want);
		if (($attrs['duplicate_candidates'] ?? []) === $want) {
			continue;
		}
		if ($want) {
			$attrs['duplicate_candidates'] = $want;
		}
		else {
			unset($attrs['duplicate_candidates']);
		}
		$pdo->prepare('UPDATE topo_nodes SET attrs = ? WHERE id = ?')->execute([json_encode($attrs, JSON_THROW_ON_ERROR), $id]);
	}
}

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
