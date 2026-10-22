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
$run_segments = [];        // hostid => segment, read once per host per run
$segment_of = static function ($hostid) use ($pdo, &$run_segments): string {
	$hostid = (int) $hostid;
	if (!array_key_exists($hostid, $run_segments)) {
		$segment = 'server';
		try {
			$stmt = $pdo->prepare('SELECT monitored_by, proxyid, proxy_groupid FROM hosts WHERE hostid = ?');
			$stmt->execute([$hostid]);
			if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
				if ((int) $row['monitored_by'] === 2 && $row['proxy_groupid'] !== null) {
					$segment = 'pg:'.$row['proxy_groupid'];
				}
				elseif ((int) $row['monitored_by'] === 1 && $row['proxyid'] !== null) {
					$segment = 'proxy:'.$row['proxyid'];
				}
			}
		}
		catch (PDOException $exception) {
			// a database without the monitoring assignment columns (a test stand-in): everything is in the segment `server`
		}
		$run_segments[$hostid] = $segment;
	}
	return $run_segments[$hostid];
};
$mac_like = static fn (string $value): bool => (bool) preg_match('/^([0-9a-f]{2}:){5}[0-9a-f]{2}$/i', $value);
/** normalization §4.1 "Placeholder MAC": `00:00:00:00:00:00` and `ff:ff:ff:ff:ff:ff` (after the MAC normal form, any case) are valid values but never an identity: the one predicate of
 * every place that makes or compares a MAC identity (chassis key, loc_chassis, port MAC identity, inventory MAC match) */
$is_placeholder_mac = static fn (?string $mac): bool => $mac !== null && in_array(strtolower($mac), ['00:00:00:00:00:00', 'ff:ff:ff:ff:ff:ff'], true);
$chassis_key = static function (?string $value, ?string $type, string $segment) use ($mac_like, $is_placeholder_mac): ?string {
	if ($value === null || $value === '') {
		return null;
	}
	if (in_array($type, [null, '', 'macAddress'], true) && $mac_like($value)) {
		return $is_placeholder_mac($value) ? null : 'chassis:mac:'.strtolower($value);      // a placeholder MAC gives no chassis key (normalization §4.2)
	}
	return substr('chassis:'.($type === null || $type === '' ? 'local' : $type).':'.$segment.':'.$value, 0, 300);
};
// The scoped sysname key (§6.7.0, 2.31): the reporter's local Port (its topo_nodes id, so it names the reporter too) and the sysname as received.
$sysname_key = static fn (int $port_id, string $sysname): string => substr('sysname:'.$port_id.':'.$sysname, 0, 300);
$ip_key = static fn (?string $addr, string $segment): ?string => $addr === null || $addr === '' ? null : substr('ip:'.$segment.':'.$addr, 0, 300);

/** Devices that hold a key (replaced keys only when asked for), ordered by id. */
$key_holders = static function (string $key, bool $replaced = false) use ($pdo, $q_key): array {
	// only keys of Devices that exist (a key row whose Device was removed without the cascade, for example by a reset with the foreign keys off, is nothing)
	$stmt = $pdo->prepare("SELECT k.device_id FROM topo_identity_keys k JOIN topo_nodes d ON d.id = k.device_id".
		" WHERE k.{$q_key} = ? AND k.replaced = ? ORDER BY k.device_id");
	$stmt->execute([$key, $replaced ? 1 : 0]);
	return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
};
$device_has_kind = static function (int $device_id, string $kind) use ($pdo): bool {
	$stmt = $pdo->prepare('SELECT 1 FROM topo_identity_keys WHERE device_id = ? AND kind = ? LIMIT 1');
	$stmt->execute([$device_id, $kind]);
	return $stmt->fetchColumn() !== false;
};
$device_holds_key = static function (int $device_id, string $key) use ($pdo, $q_key): bool {
	$stmt = $pdo->prepare("SELECT 1 FROM topo_identity_keys WHERE device_id = ? AND {$q_key} = ?");
	$stmt->execute([$device_id, $key]);
	return $stmt->fetchColumn() !== false;
};
$insert_key = static function (int $device_id, string $key, string $kind, int $seen_at, bool $replaced = false) use ($pdo, $q_key): void {
	$pdo->prepare("INSERT INTO topo_identity_keys (device_id, {$q_key}, kind, first_seen, replaced) VALUES (?, ?, ?, ?, ?)")
		->execute([$device_id, $key, $kind, $seen_at, $replaced ? 1 : 0]);
};
// The keys a row or a reporter gives to the Device it found or created. A Device keeps the chassis it has (a stored stronger key is never replaced by a
// different value); a key another Device holds is not copied (it would make the value shared; the duplicate candidate is recorded elsewhere).
$register_keys = static function (int $device_id, array $incoming, int $seen_at, bool $by_reporter = false) use ($pdo, $q_key, $key_holders, $device_has_kind, $device_holds_key, $insert_key): void {
	if (!$by_reporter) {
		// §6.4 step 6, §6.7.1: a neighbor row never adds a key to a reporter's Device, in any segment; the reporter writes its own keys
		$reporter = $pdo->prepare("SELECT 1 FROM topo_nodes WHERE id = ? AND represented_by_matched_by = 'reporter_self' AND represented_by_node_id IS NOT NULL");
		$reporter->execute([$device_id]);
		if ($reporter->fetchColumn() !== false) {
			return;
		}
	}
	foreach (['chassis', 'ip', 'sysname'] as $kind) {
		$key = $incoming[$kind.'_key'] ?? null;
		if ($key === null || $device_holds_key($device_id, $key)) {
			continue;
		}
		if ($kind === 'sysname') {
			$insert_key($device_id, $key, 'sysname', $seen_at);      // scoped to one reporter and one port: never shared with another Device's key by value
			continue;
		}
		if ($kind === 'chassis' && $device_has_kind($device_id, 'chassis')) {
			continue;
		}
		// the reporter's own key is always on its Device, even when another Device holds it (then the key is shared, §6.4 step 5)
		if (!$by_reporter && array_diff($key_holders($key), [$device_id])) {
			continue;
		}
		$insert_key($device_id, $key, $kind, $seen_at);
	}
};

// The `ip:` keys the inputs of THIS run give to Devices (§6.7.0 "Address keys follow the current rows"): device id => [key => true]. Filled by the neighbor
// rows (a Device without a reporter) and by the reporters' own SNMP interface IPs; at the end of a full run every other `ip:` key goes ($drop_stale_address_keys).
$current_ip_keys = [];

// Devices that hold no key (data written before the key table, and Devices made by the legacy loop) get their keys from their attributes, in the
// segment of their reporter, else of the reporter of their latest observation. A MAC chassis is global and needs no segment; a Device whose
// scoped keys cannot be built (no reporter, no observation) is listed in the run summary and left without them.
$build_identity_keys = static function () use ($pdo, $segment_of, $chassis_key, $ip_key, $sysname_key, $insert_key, $now, &$summary): void {
	// key rows without a Device (§6.7.0): counted, then removed
	$summary['identity_keys_orphaned'] = (int) $pdo->query("SELECT COUNT(*) FROM topo_identity_keys WHERE device_id NOT IN (SELECT id FROM topo_nodes WHERE type = 'device')")->fetchColumn();
	$pdo->exec("DELETE FROM topo_identity_keys WHERE device_id NOT IN (SELECT id FROM topo_nodes WHERE type = 'device')");
	$devices = $pdo->query("SELECT d.id, d.attrs, d.represented_by_matched_by AS matched_by, h.host_ref FROM topo_nodes d".
		" LEFT JOIN topo_nodes h ON h.id = d.represented_by_node_id AND h.type = 'host'".
		" WHERE d.type = 'device' AND NOT EXISTS (SELECT 1 FROM topo_identity_keys k WHERE k.device_id = d.id) ORDER BY d.id")->fetchAll(PDO::FETCH_ASSOC);
	foreach ($devices as $device) {
		$attrs = json_decode($device['attrs'], true) ?: [];
		$wanted = [];      // [value, kind, replaced]
		if (($attrs['chassis_id'] ?? '') !== '') {
			$wanted[] = [(string) $attrs['chassis_id'], 'chassis', false];
		}
		foreach ($attrs['replaced_chassis'] ?? [] as $old) {
			$wanted[] = [(string) $old, 'chassis', true];
		}
		if (($attrs['mgmt_ip'] ?? '') !== '') {
			$wanted[] = [(string) $attrs['mgmt_ip'], 'ip', false];
		}
		// Upgrade from before 2.31 (the scoped sysname key was found through links, then through observations): a Device without keys that an observation names by a
		// sysname key (`remote_key` sysname:<value> on a local port) gets that key once; the Device is a far Device found or made by sysname.
		$sysname_wanted = [];
		$named = $pdo->prepare("SELECT DISTINCT local_port_id, remote_key FROM topo_observations WHERE device_id = ? AND remote_key LIKE 'sysname:%' ORDER BY local_port_id, remote_key");
		$named->execute([$device['id']]);
		foreach ($named->fetchAll(PDO::FETCH_ASSOC) as $o) {
			$sysname_wanted[] = $sysname_key((int) $o['local_port_id'], substr((string) $o['remote_key'], 8));
		}
		foreach ($sysname_wanted as $key) {
			$insert_key((int) $device['id'], $key, 'sysname', (int) ($attrs['last_seen'] ?? $now));
			$summary['identity_keys_built']['keys']++;
			if (!$wanted) {
				$summary['identity_keys_built']['devices']++;
			}
		}
		if (!$wanted) {
			continue;
		}
		$segment = null;
		if ($device['matched_by'] === 'reporter_self' && $device['host_ref'] !== null) {
			$segment = $segment_of($device['host_ref']);
		}
		else {
			$latest = $pdo->prepare('SELECT i.hostid FROM topo_observations o JOIN items i ON i.itemid = o.itemid WHERE o.device_id = ?'.
				' ORDER BY o.last_seen DESC, o.id DESC LIMIT 1');
			$latest->execute([$device['id']]);
			if (($hostid = $latest->fetchColumn()) !== false) {
				$segment = $segment_of($hostid);
			}
		}
		$built = 0;
		$skipped = false;
		foreach ($wanted as [$value, $kind, $replaced]) {
			$key = $kind === 'ip' ? ($segment === null ? null : $ip_key($value, $segment)) : $chassis_key($value, null, $segment ?? '');
			if ($key === null || ($kind === 'chassis' && $segment === null && !str_starts_with($key, 'chassis:mac:'))) {
				$skipped = true;
				continue;
			}
			$exists = $pdo->prepare('SELECT 1 FROM topo_identity_keys WHERE device_id = ? AND '.($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql' ? '"key"' : '`key`').' = ?');
			$exists->execute([$device['id'], $key]);
			if ($exists->fetchColumn() === false) {
				$insert_key((int) $device['id'], $key, $kind, (int) ($attrs['last_seen'] ?? $now), $replaced);
				$built++;
			}
		}
		$summary['identity_keys_built']['devices']++;
		$summary['identity_keys_built']['keys'] += $built;
		if ($skipped && count($summary['identity_keys_built']['skipped']) < 200) {
			$summary['identity_keys_built']['skipped'][] = (int) $device['id'];
		}
	}
};

// topology-1-model.md "Far Device" (§6.7.1) and "Shared identifier" (§6.7.4), for the snapshot path, through the identity keys (§6.7.0).
// Keys, strongest first: the chassis key, the IP key, sysname (scoped to the reporter and the local port, see $find_device).
//  1. look up by the strongest key the row has;
//  2. not found: look up by a weaker key of the row, but only among Devices that have no key of any stronger kind the row has, in any scope
//     (such a Device gets that key written by the caller);
//  3. more than one match at any step: shared, nothing is picked and no weaker key is tried.
// $identity = ['chassis' => [key, raw value] | null, 'ip' => [key, raw value] | null].
// Returns [device_id, matched_by], null (nothing found: create a Device) or ['shared', kind, value, node_ids].
$note_shared = static function (string $kind, string $value, array $node_ids) use (&$summary): void {
	foreach ($summary['identity_keys_shared']['list'] as $entry) {
		if ($entry['key'] === $kind && $entry['value'] === $value) {
			return;
		}
	}
	$summary['identity_keys_shared']['count']++;
	if (count($summary['identity_keys_shared']['list']) < 200) {
		$summary['identity_keys_shared']['list'][] = ['key' => $kind, 'value' => $value, 'node_ids' => array_map('intval', $node_ids)];
	}
};

// What the rows of this run resolved to, per local port (§6.7.1): the Devices found or made by rows of that port. Filled by $snap_device; the same-port join
// looks at it. (The scoped sysname key itself is a stored key like the others, $sysname_key.)
$run_port_devices = [];      // [local_port_id => [device_id => true]]

$resolve_device = static function (array $identity, ?int $local_port_id, ?string $sysname, bool $for_reporter = false, array $exclude = []) use ($pdo, $key_holders, $device_has_kind, $sysname_key, &$run_port_devices): ?array {
	$keys = [];       // [kind => [key, raw]], in the order of strength, only the keys the row has
	if (!empty($identity['chassis'])) {
		$keys['chassis'] = $identity['chassis'];
	}
	if (!empty($identity['ip'])) {
		$keys['ip'] = $identity['ip'];
	}
	if ($local_port_id !== null && $sysname) {
		$keys['sysname'] = [$sysname, $sysname];
	}
	$names = ['chassis' => 'chassis_id', 'ip' => 'mgmt_ip', 'sysname' => 'sysname'];
	$stronger = [];
	foreach ($keys as $kind => [$value, $raw]) {
		if ($kind === 'sysname') {
			// the stored scoped key (§6.7.0): a Device that a row of this reporter and this port resolved to by its sysname, in this run or an earlier one,
			// whatever the outcome of the observation was (applied, conflict, ambiguous) — a key never makes a second Device (§6.7.1)
			$ids = $key_holders($sysname_key($local_port_id, $value));
			if (!$ids && count($keys) === 1) {
				// Same-port join (§6.7.1): a row whose only key is the scoped sysname key joins the Device another row of this reporter and this port resolved to,
				// when the sysname is that Device's text chassis id or its sysname. One → that Device; several → shared; none → create.
				$join = [];
				foreach (array_keys($run_port_devices[$local_port_id] ?? []) as $candidate) {
					$stmt = $pdo->prepare('SELECT attrs FROM topo_nodes WHERE id = ?');
					$stmt->execute([$candidate]);
					$candidate_attrs = json_decode((string) $stmt->fetchColumn(), true) ?: [];
					$text_chassis = ($candidate_attrs['chassis_id'] ?? '') !== '' && !preg_match('/^([0-9a-f]{2}:){5}[0-9a-f]{2}$/i', (string) $candidate_attrs['chassis_id'])
						? trim((string) $candidate_attrs['chassis_id']) : null;
					if (trim($value) !== '' && (trim($value) === $text_chassis || trim($value) === trim((string) ($candidate_attrs['sysname'] ?? '')))) {
						$join[] = (int) $candidate;
					}
				}
				sort($join);
				if (count($join) > 1) {
					return ['shared', 'sysname', $raw, $join];
				}
				if ($join) {
					return [$join[0], 'sysname'];
				}
			}
		}
		else {
			$ids = $key_holders($value);
		}
		if ($for_reporter || $exclude) {
			// §6.4 step 5: a reporter never takes a Device that has another representation, nor one that another reporter takes
			$ids = array_values(array_filter($ids, static function (int $id) use ($pdo, $for_reporter, $exclude): bool {
				if (in_array($id, $exclude, true)) {
					return false;
				}
				if ($for_reporter) {
					$rep = $pdo->prepare('SELECT represented_by_node_id FROM topo_nodes WHERE id = ?');
					$rep->execute([$id]);
					if ($rep->fetchColumn() !== null) {
						return false;
					}
				}
				return true;
			}));
		}
		if ($stronger) {
			// a weaker key may only take a Device that has no key of a stronger kind the row has (in any scope)
			// A reporter's Device that has no chassis key is found by the IP like any other (§6.7.0 "Keys of a reporter's Device"); the row does not give it
			// its chassis ($register_keys refuses a reporter's Device), so the result does not depend on which host is ingested first.
			$ids = array_values(array_filter($ids, static function (int $id) use ($pdo, $device_has_kind, $stronger, $for_reporter): bool {
				foreach ($stronger as $stronger_kind) {
					if ($device_has_kind($id, $stronger_kind)) {
						return false;
					}
				}
				return true;
			}));
		}
		if (count($ids) > 1 && $kind !== 'sysname') {
			return ['shared', $names[$kind], $raw, $ids];
		}
		if ($ids) {
			return [$ids[0], $names[$kind]];
		}
		if ($kind === 'chassis') {
			// topology-1-model.md §6.7.0 "Replaced chassis": a chassis key that is marked replaced on exactly one Device finds that Device, after the
			// lookup by the normal keys found nothing (a neighbor that still advertises the old chassis does not make a ghost Device)
			$replaced = $key_holders($value, true);
			if (count($replaced) === 1) {
				return [$replaced[0], 'replaced_chassis'];
			}
		}
		$stronger[] = $kind;
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

// topology-1-model.md "Far Device": upsert Device by chassis_id, else mgmt_ip, else (neighbor Devices only, via $local_port_id) sysname
// scoped to (reporter, local_if_index) — see $find_device's comment. Mirrors seed.php's $device(), plus the
// third key seed.php doesn't have (seed.php's fixture data always carries chassis_id, so it never needs it).
// When the sysname key is what resolved the match, attrs.matched_by is stamped 'sysname' per topology-1-model.md "Far Device" — the
// chassis_id/mgmt_ip matches don't get a matched_by (no existing convention for one; nothing else in this
// codebase records "how a Device was matched" outside the sysname weak-signal rule of topology-1-model.md "Far Device").
// $summary tallies create-vs-update counts for the /topo/ingest/status endpoint (topology-1-model.md "Run summary"). Just counting,
// no change to the matching/upsert rules themselves.
$summary = ['devices_created' => 0, 'devices_updated' => 0, 'ports_created' => 0, 'links_created' => 0,
	// Ports made from neighbor advertisements on a Device that has since become a reporter (its own walk returned real
	// ports), each counted once: ambiguous (several pseudo ports match one real port, so none was merged), move_failed (the
	// real port already had another link), kept_manual (a manual link sits on it, so the port stays), converted (its links
	// became device_links, the port was deleted), removed (no link at all). `pseudo_ports` lists them with the details.
	'pseudo_ports_converted' => 0, 'pseudo_ports_ambiguous' => 0, 'pseudo_ports_move_failed' => 0,
	'pseudo_ports_kept_manual' => 0, 'pseudo_ports_removed' => 0, 'pseudo_ports' => []];
// Each pseudo port is counted once, under the first reason that applies: ambiguous, then move_failed, then kept_manual,
// then converted / removed. The counters add up to the number of pseudo ports handled. A later, lower-priority fact about
// the same port is kept in its details entry under `also`; a higher-priority one takes the port over.
$pseudo_note = static function (string $outcome, int $device_id, int $port_id, string $name, array $extra = [])
		use (&$summary): void {
	static $seen = [];
	static $priority = ['ambiguous' => 1, 'move_failed' => 2, 'kept_manual' => 3, 'converted' => 4, 'removed' => 5];
	if (isset($seen[$port_id])) {
		$index = $seen[$port_id]['index'];
		if ($priority[$outcome] < $priority[$seen[$port_id]['outcome']]) {
			$summary['pseudo_ports_'.$seen[$port_id]['outcome']]--;
			$summary['pseudo_ports_'.$outcome]++;
			if ($index !== null) {
				$before = $summary['pseudo_ports'][$index];
				$also = $before['also'] ?? [];
				$also[$before['outcome']] = array_diff_key($before, array_flip(['outcome', 'device_id', 'port_id', 'name', 'also']));
				$summary['pseudo_ports'][$index] = ['outcome' => $outcome, 'device_id' => $device_id, 'port_id' => $port_id,
					'name' => $name] + $extra + ['also' => $also];
			}
			$seen[$port_id]['outcome'] = $outcome;
		}
		elseif ($index !== null) {
			$summary['pseudo_ports'][$index]['also'][$outcome] = $extra;
		}
		return;
	}
	$summary['pseudo_ports_'.$outcome]++;
	$index = null;
	if (count($summary['pseudo_ports']) < 200) {
		$summary['pseudo_ports'][] = ['outcome' => $outcome, 'device_id' => $device_id, 'port_id' => $port_id,
			'name' => $name] + $extra;
		$index = count($summary['pseudo_ports']) - 1;
	}
	$seen[$port_id] = ['outcome' => $outcome, 'index' => $index];
};

// Byte-for-byte mirror of CTopologyPrototype::promote()'s 1:1-guard + write, called only from
// $associate_hosts_by_mac() below with matched_by='mac' (never 'manual' — manual promotion is the separate
// /promote endpoint's job (topology-1-model.md "`represented_by` (Device → Host or Proxy)"), not ingest's) and from the reporter_self call in the main loop below
// with matched_by='reporter_self'. topology-1-model.md "`represented_by` (Device → Host or Proxy)": represented_by is stored as three columns directly on
// the Device's own topo_nodes row, not a topo_edges row — matched_mac is deliberately not accepted or
// stored here (no consumer in the new column-based model, per the spec addendum).
$promote = static function (int $device_id, int $target_nodeid, string $matched_by) use ($pdo, $now): bool {
	$represented = $pdo->prepare("SELECT 1 FROM topo_nodes WHERE id = ? AND represented_by_node_id IS NOT NULL");
	$represented->execute([$device_id]);
	if ($represented->fetch()) {
		return false; // this Device is already represented — not an error, just no match (1:1, topology-1-model.md "`represented_by` (Device → Host or Proxy)").
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

// topology-1-model.md "`represented_by` (Device → Host or Proxy)", "Reporter identity (`reporter_self`)": find-or-create the Host pointer node for a reporter's own hostid, scoped to the new
// deterministic reporter-self-link path only (see the $promote() call in the main loop below) — NOT used
// by the inventory MAC match ($associate_hosts_by_mac), which creates the Host node itself on a match. Mirrors
// CTopologyPrototype::upsertPointerNode() (ui/include/classes/topology/CTopologyPrototype.php ~line 709):
// look up topo_nodes by (type='host', host_ref=hostid), insert {type:'host', host_ref:hostid, attrs:'{}'}
// if missing. This is the whole point of the reporter-self-link change: ingest already knows the exact
// hostid with certainty (from the item.get call that located this reporter, topology-1-model.md "Reporters and usable snapshots"), so linking the
// reporter's Device to its own Host does not depend on an inventory MAC.
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

// BEGIN port-name-forms.tsv (generated by database/topology/discovery/gen_port_name_forms.py from normalization/port-name-forms.tsv; do not edit)
const TOPO_PORT_NAME_FORMS = [
	['twentyfivegigabitethernet', 'twe'],
	['hundredgigabitethernet', 'hu'],
	['fortygigabitethernet', 'fo'],
	['tengigabitethernet', 'te'],
	['twogigabitethernet', 'tw'],
	['xgigabitethernet', 'xge'],
	['gigabitethernet', 'gi'],
	['twentyfivegige', 'twe'],
	['fastethernet', 'fa'],
	['port-channel', 'po'],
	['hundredgige', 'hu'],
	['fortygige', 'fo'],
	['ethernet', 'et'],
	['tengige', 'te'],
	['eth', 'et'],
	['ge', 'gi'],
];
// END port-name-forms.tsv

// The ONE normalization of a port name: real ports ($find_matching_real_port), pseudo ports ($snap_pseudo_port) and the pseudo-port merge
// all compare names through it.
// ---- topology-6-normalization.md §3-§4: the same functions as the native step (preproc_topology.c) and push.py; normalization_cases.json tests all three ----
$norm_white = " \t\n\x0B\f\r";
/** §3, text fields from a Hex-STRING: trailing NUL bytes removed; valid UTF-8 without a control character other than tab is text, else the hex form aa:bb:.. */
$norm_text_or_hex = static function (string $raw): string {
	$raw = rtrim($raw, "\0");
	if ($raw === '') {
		return '';
	}
	if (preg_match('//u', $raw) !== 1 || preg_match('/[\x00-\x08\x0A-\x1F\x7F]|\xC2[\x80-\x9F]/', $raw) === 1) {
		return implode(':', str_split(bin2hex($raw), 2));
	}
	return $raw;
};
/** §4.5 steps 2-4: trailing NUL characters, white space, one pair of surrounding quotes (".." '..' U+201C U+201D), white space; null = no value */
$norm_clean = static function (string $value) use ($norm_white): ?string {
	$value = trim(rtrim($value, "\0"), $norm_white);
	foreach ([['"', '"'], ["'", "'"], ["\u{201C}", "\u{201D}"]] as [$left, $right]) {
		if (strlen($value) >= strlen($left) + strlen($right) && str_starts_with($value, $left) && str_ends_with($value, $right)) {
			$value = trim(substr($value, strlen($left), strlen($value) - strlen($left) - strlen($right)), $norm_white);
			break;
		}
	}
	return $value === '' ? null : $value;
};
/** §4.6: clean, ASCII lower case, no white space between the leading letters and the first digit, a long form of the table replaced by its short form when a digit follows */
$norm_name_key = static function (string $value) use ($norm_clean): ?string {
	$key = $norm_clean($value);
	if ($key === null) {
		return null;
	}
	$key = strtr($key, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz');
	$key = preg_replace('/^([a-z]+)[ \t\n\x0B\f\r]+(?=[0-9])/', '$1', $key);
	foreach (TOPO_PORT_NAME_FORMS as [$long, $short]) {
		$next = $key[strlen($long)] ?? '';
		if (str_starts_with($key, $long) && $next !== '' && $next >= '0' && $next <= '9') {
			return $short.substr($key, strlen($long));
		}
	}
	return $key;
};
/** §4.7: 1 to 10 ASCII digits after cleaning, value 1..2147483647; else null */
$norm_numeric_id = static function (string $value) use ($norm_clean): ?int {
	$value = $norm_clean($value);
	if ($value === null || preg_match('/^[0-9]{1,10}$/D', $value) !== 1) {
		return null;
	}
	$number = (int) $value;
	return $number > 0 && $number <= 2147483647 ? $number : null;
};
/** §4.8: the 0-based index of the one port that has this MAC; null when the MAC is not usable (invalid, a placeholder MAC) or no or several ports have it */
$norm_port_mac = static function (string $mac, array $port_macs) use ($is_placeholder_mac): ?int {
	if (preg_match('/^([0-9a-f]{2}:){5}[0-9a-f]{2}$/D', $mac) !== 1 || $is_placeholder_mac($mac)) {
		return null;
	}
	$hits = array_keys(array_filter($port_macs, static fn ($m): bool => $m === $mac));
	return count($hits) === 1 ? (int) $hits[0] : null;
};
/** §4.3: IPv4 dotted decimal, IPv6 as RFC 5952 text; null when it is not an address. The decoder is shared with the side panel (ui/include/classes/topology/CTopologyAddress.php). */
$norm_ip_text = static fn (string $text): ?string => CTopologyAddress::ipText($text);
/** §4.4: the management address from the raw `rem_man_addrs` entries: the first IPv4, else the first IPv6; null = none usable (CTopologyAddress, shared with the side panel) */
$norm_man_addrs = static fn (string $entries): ?string => CTopologyAddress::management($entries);
/** Model §6.7.6: [if_index, null] or [null, reason] for a NEIGHBORS row with `loc_port_num`; $ports = PORTS rows [if_index, name, descr, mac] (cleaned) */
$resolve_local_port = static function (array $row, array $ports) use ($norm_clean, $norm_name_key, $norm_numeric_id, $norm_port_mac): array {
	if (isset($row['if_index']) && $row['if_index'] !== '') {
		return [(int) $row['if_index'], null];
	}
	if (!$ports) {
		return [null, 'no_ports'];
	}
	$by_index = [];
	foreach ($ports as $port) {
		$by_index[(int) $port['if_index']] = $port;
	}
	$id = isset($row['loc_port_id']) ? (string) $row['loc_port_id'] : null;
	$type = (string) ($row['loc_port_id_type'] ?? '');
	// step 1: by the subtype of the port id
	if ($id !== null && $id !== '') {
		if ($type === 'interfaceName') {
			$hits = array_keys(array_filter($ports, static fn (array $p): bool => ($p['name'] ?? null) !== null && $norm_clean((string) $p['name']) === $norm_clean($id)));
			if (count($hits) === 1) {
				return [(int) $ports[$hits[0]]['if_index'], null];
			}
		}
		elseif ($type === 'macAddress') {
			$hit = $norm_port_mac(strtolower($id), array_map(static fn (array $p): string => strtolower((string) ($p['mac'] ?? '')), $ports));
			if ($hit !== null) {
				return [(int) $ports[$hit]['if_index'], null];
			}
		}
		elseif ($type === 'local') {
			$number = $norm_numeric_id($id);
			if ($number !== null && isset($by_index[$number])) {
				return [$number, null];
			}
		}
	}
	// step 2: id, then description, against name, then descr; the cleaned names first, then the name key
	$several = false;
	foreach ([$norm_clean, $norm_name_key] as $compare) {
		foreach ([['loc_port_id', 'name'], ['loc_port_id', 'descr'], ['loc_port_desc', 'name'], ['loc_port_desc', 'descr']] as [$from, $to]) {
			$value = isset($row[$from]) ? $compare((string) $row[$from]) : null;
			if ($value === null) {
				continue;
			}
			$hits = array_keys(array_filter($ports, static fn (array $p): bool => isset($p[$to]) && $compare((string) $p[$to]) === $value));
			if (count($hits) === 1) {
				return [(int) $ports[$hits[0]]['if_index'], null];
			}
			$several = $several || count($hits) > 1;
		}
	}
	if ($several) {
		return [null, 'name_on_several_ports'];
	}
	// step 3: the port number as an ifIndex
	if (isset($row['loc_port_num']) && $norm_numeric_id((string) $row['loc_port_num']) !== null && isset($by_index[$norm_numeric_id((string) $row['loc_port_num'])])) {
		return [$norm_numeric_id((string) $row['loc_port_num']), null];
	}
	return [null, 'if_index_missing'];
};
// The port name as it is COMPARED (the name key); never stored as the name. Real ports ($find_matching_real_port), pseudo ports ($snap_pseudo_port), the
// pseudo-port merge, the far port and the port class all compare names through it. "" = no key.
$normalize_port_name = static fn (string $name): string => $norm_name_key($name) ?? '';

// The ifIndex of a far port when the advertisement really carries one (LLDP port id subtype `local` with a NUMERIC id, normalization §4.7: leading zeros
// ignored): such a number is the neighbor's own ifIndex, and a confirmed Port that owns it is the same physical port. Any other far port has no ifIndex that
// lldpRemTable could tell us; a pseudo port is then found and created by its name key (topology-1-model.md "Far port": "Pseudo port identity is the
// normalized name"), and its if_index is only an allocation (see $snap_pseudo_port).
$pseudo_local_if_index = static function (?string $remote_port_id, ?string $remote_port_id_subtype) use ($norm_numeric_id): ?int {
	return $remote_port_id_subtype === 'local' && $remote_port_id !== null ? $norm_numeric_id($remote_port_id) : null;
};

// topology-1-model.md "Pseudo-port merge": run ONLY right after upserting a reporter's own real Port (never from the neighbor
// pseudo-Port creation path below — that path is unaffected, per scope). Looks for an existing pseudo-Port
// (attrs.pseudo = true, topology-1-model.md "Nodes: Attributes by type") on the same Device whose name normalizes to the same thing as the real Port
// just upserted. Exactly one match: the pseudo-Port was standing in for this exact physical port before
// this Device ever pushed its own data — re-point its physical_link edge(s) onto the real Port and delete
// it. Zero or more-than-one match: do nothing (never guess — the explicit instruction of topology-1-model.md "Pseudo-port merge"), just log it
// so it isn't silently missed either way.
// A Port merge moves the scoped sysname keys of the removed Port to the kept Port (§6.7.0, §6.7.2, §6.7.3): `sysname:<from>:<value>` becomes `sysname:<to>:<value>`;
// a Device that already holds the new key just loses the old one.
$move_port_sysname_keys = static function (int $from_port_id, int $to_port_id) use ($pdo, $q_key): void {
	$prefix = 'sysname:'.$from_port_id.':';
	$stmt = $pdo->prepare("SELECT device_id, {$q_key} AS k FROM topo_identity_keys WHERE {$q_key} LIKE ?");
	$stmt->execute([$prefix.'%']);
	foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
		if (!str_starts_with($row['k'], $prefix)) {
			continue;
		}
		$new = substr('sysname:'.$to_port_id.':'.substr($row['k'], strlen($prefix)), 0, 300);
		$held = $pdo->prepare("SELECT 1 FROM topo_identity_keys WHERE device_id = ? AND {$q_key} = ?");
		$held->execute([$row['device_id'], $new]);
		if ($held->fetchColumn() !== false) {
			$pdo->prepare("DELETE FROM topo_identity_keys WHERE device_id = ? AND {$q_key} = ?")->execute([$row['device_id'], $row['k']]);
		}
		else {
			$pdo->prepare("UPDATE topo_identity_keys SET {$q_key} = ? WHERE device_id = ? AND {$q_key} = ?")->execute([$new, $row['device_id'], $row['k']]);
		}
	}
};

$merge_pseudo_port = static function (int $reporter_device_id, int $real_port_id, string $real_port_name) use ($pdo, $normalize_port_name, $pseudo_note, $move_port_sysname_keys): void {
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
		// Several pseudo-Ports match one real Port: never guess (topology-1-model.md "Pseudo-port merge"), none is merged. They are reported, and
		// left to $convert_leftover_pseudo_ports().
		foreach (count($matches) > 1 ? $matches : [] as $match) {
			$pseudo_note('ambiguous', $reporter_device_id, (int) $match['id'], (string) $match['name'],
				['real_port_id' => $real_port_id, 'real_port' => $real_port_name]);
		}
		return; // zero matches: nothing to merge (the ordinary/common case).
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
		// topology-1-model.md "`physical_link` (Port ↔ Port)": src_id is always the numerically smaller Port id — re-canonicalize for the new endpoint.
		$pseudo_was_src = (int) $edge['src_id'] === $pseudo_port_id;
		[$new_src, $new_dst] = $other_id < $real_port_id ? [$other_id, $real_port_id] : [$real_port_id, $other_id];
		$real_is_src = $new_src === $real_port_id;

		// last_seen_src/last_seen_dst (topology-1-model.md "`physical_link` (Port ↔ Port)") are keyed to canonical src/dst position, not to a stable
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

		// Order-independence (topology-1-model.md "Two phases"): the OTHER side of this same cable may have already run its own merge
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

		// topology-1-model.md "Port uniqueness": a Port can have at most one active link. The real Port already carrying some OTHER active link means
		// the move would give it two: the link stays on the pseudo-Port (reported as move_failed) and
		// $convert_leftover_pseudo_ports() decides what becomes of it.
		$busy = $pdo->prepare(
			"SELECT id, attrs FROM topo_edges WHERE id != ? AND ((type = 'physical_link' AND (src_id = ? OR dst_id = ?))".
			" OR (type = 'device_link' AND src_id = ?))");
		$busy->execute([$edge['id'], $real_port_id, $real_port_id, $real_port_id]);
		$occupied = false;
		foreach ($busy->fetchAll(PDO::FETCH_ASSOC) as $other_link) {
			$occupied = $occupied || !isset((json_decode($other_link['attrs'], true) ?: [])['superseded_at']);
		}
		if ($occupied) {
			$pseudo_note('move_failed', $reporter_device_id, $pseudo_port_id, (string) $matches[0]['name'],
				['edge_id' => (int) $edge['id'], 'real_port_id' => $real_port_id, 'real_port' => $real_port_name]);
			$all_repointed = false;
			continue;
		}

		try {
			$pdo->prepare('UPDATE topo_edges SET src_id = ?, dst_id = ?, attrs = ? WHERE id = ?')
				->execute([$new_src, $new_dst, json_encode($attrs, JSON_THROW_ON_ERROR), $edge['id']]);
		}
		catch (PDOException $exception) {
			// Not expected after the checks above (a unique-key violation); reported instead of aborting the reporter.
			$pseudo_note('move_failed', $reporter_device_id, $pseudo_port_id, (string) $matches[0]['name'],
				['edge_id' => (int) $edge['id'], 'error' => $exception->getMessage()]);
			$all_repointed = false;
		}
	}

	if (!$all_repointed) {
		return;
	}

	$move_port_sysname_keys($pseudo_port_id, $real_port_id);
	$pdo->prepare("DELETE FROM topo_nodes WHERE id = ? AND type = 'port'")->execute([$pseudo_port_id]);
	echo "OK: merged pseudo-Port #{$pseudo_port_id} into real Port #{$real_port_id} ('{$real_port_name}') ".
		"on device #{$reporter_device_id}\n";
};

// topology-1-model.md §6.7.2 "Far port": the far port of a neighbor row is decided by the port id subtype (`rem_port_type`); the LLDP port
// description (`rem_port_desc`, often free text) is only a fallback. One function for both kinds of far Device:
//  - reporter (authoritative ports): ['port' => id] when exactly one real Port matches (primary match by subtype, else the description
//    by normalized name); ['shared' => true] when a step matches several (that step stops the search); neither: no port (port_unmatched);
//  - not a reporter: $far_pseudo_name() gives the name of the pseudo port.
// Every CDP row counts as interfaceName (the CDP port id is the port name).
$identify_far_port = static function (int $device_id, array $row) use ($pdo, $normalize_port_name, $norm_numeric_id, $norm_port_mac): array {
	$stmt = $pdo->prepare("SELECT id, attrs FROM topo_nodes WHERE device_id = ? AND type = 'port' AND JSON_EXTRACT(attrs, '\$.pseudo') = false ORDER BY id");
	$stmt->execute([$device_id]);
	$ports = [];
	foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $port) {
		$ports[(int) $port['id']] = json_decode($port['attrs'], true) ?: [];
	}
	// ports whose name key equals the key of $name (normalization §4.6)
	$by_name = static function (string $name) use ($ports, $normalize_port_name): array {
		$key = $normalize_port_name($name);
		return $key === '' ? [] : array_keys(array_filter($ports, static fn (array $a): bool => $normalize_port_name((string) ($a['name'] ?? '')) === $key));
	};
	$rem_port = (string) ($row['rem_port'] ?? '');
	$desc = (string) ($row['rem_port_desc'] ?? '');
	$type = (string) ($row['rem_port_type'] ?? '');
	$primary = [];
	if ($rem_port !== '') {
		if (($row['source'] ?? 'lldp') === 'cdp' || $type === 'interfaceName') {
			$primary = $by_name($rem_port);
		}
		elseif ($type === 'macAddress') {
			// a port MAC identity (normalization §4.8): a valid MAC, not all zero, that exactly one port has; else "none", and the description follows
			$ids = array_keys($ports);
			$index = $norm_port_mac(strtolower($rem_port), array_map(static fn (int $id): string => strtolower((string) ($ports[$id]['mac'] ?? '')), $ids));
			$primary = $index === null ? [] : [$ids[$index]];
		}
		elseif ($type === 'local' && $norm_numeric_id($rem_port) !== null) {
			// a numeric port id is a guess of an ifIndex (normalization §4.7): a description that names exactly one Port wins; then the ifIndex; then the name
			$by_desc = $desc !== '' ? $by_name($desc) : [];
			if (count($by_desc) === 1) {
				$primary = $by_desc;
			}
			else {
				$number = $norm_numeric_id($rem_port);
				$primary = array_keys(array_filter($ports, static fn (array $a): bool => (int) ($a['if_index'] ?? 0) === $number));
				$primary = $primary ?: $by_name($rem_port);
			}
		}
		else {
			$primary = $by_name($rem_port);
		}
	}
	if (count($primary) > 1) {
		return ['port' => null, 'shared' => true];
	}
	if (count($primary) === 1) {
		return ['port' => $primary[0], 'shared' => false];
	}
	if ($desc !== '') {
		$fallback = $by_name($desc);
		if (count($fallback) > 1) {
			return ['port' => null, 'shared' => true];
		}
		if (count($fallback) === 1) {
			return ['port' => $fallback[0], 'shared' => false];
		}
	}
	return ['port' => null, 'shared' => false];
};

// The name of the pseudo port of a far Device that is not a reporter (§6.7.2): rem_port for interfaceName, CDP and a non-numeric
// local id; otherwise rem_port_desc when present, else rem_port. (A numeric local id also gives the pseudo port its ifIndex.)
$far_pseudo_name = static function (array $row) use ($norm_numeric_id): string {
	$rem_port = (string) ($row['rem_port'] ?? '');
	$desc = (string) ($row['rem_port_desc'] ?? '');
	$type = (string) ($row['rem_port_type'] ?? '');
	$by_port_id = ($row['source'] ?? 'lldp') === 'cdp' || $type === 'interfaceName' || ($type === 'local' && $norm_numeric_id($rem_port) === null);
	return $by_port_id ? ($rem_port !== '' ? $rem_port : $desc) : ($desc !== '' ? $desc : $rem_port);
};

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
	'devices_joined' => ['count' => 0, 'list' => []],
	'devices_join_refused' => ['count' => 0, 'list' => []],
	'join_links_deleted' => 0,
	'reporters_processed' => 0,
	'reporters_skipped' => [],
	// identity keys built from the attributes of Devices that had none (data written before the key table; topology-1-model.md §6.7.0): the Devices
	// done, the keys written, and the Devices whose scoped keys could not be built because no segment is known (no reporter, no observation)
	'identity_keys_built' => ['devices' => 0, 'keys' => 0, 'skipped' => []],
	// topology-1-model.md §6.7.0 / §6.8 / §6.11: key rows whose Device is gone (removed at the start of a run; above 0 means a bug elsewhere), the
	// `ip:` keys no current input gives any more (removed at the end of a full run), and the reporters with a failed step (host, step, error)
	'identity_keys_orphaned' => 0,
	'address_keys_removed' => 0,
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

// topology-1-model.md "Relationships" (type validation): an edge's ends are checked on write. A physical_link joins two Ports, a
// device_link goes from a Port to a Device; any other combination (and an unknown far_port_reason) is rejected, so
// code that assumes "a physical link joins two ports" can never receive a device row.
$validate_edge = static function (string $type, int $src_id, int $dst_id, ?array $attrs = null) use ($pdo): void {
	$stmt = $pdo->prepare('SELECT id, type FROM topo_nodes WHERE id IN (?, ?)');
	$stmt->execute([$src_id, $dst_id]);
	$types = [];
	foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
		$types[(int) $row['id']] = $row['type'];
	}
	$want = ['physical_link' => ['port', 'port'], 'device_link' => ['port', 'device']][$type] ?? null;
	if ($want === null) {
		throw new InvalidArgumentException("unknown edge type '{$type}'");
	}
	if (($types[$src_id] ?? null) !== $want[0] || ($types[$dst_id] ?? null) !== $want[1]) {
		throw new InvalidArgumentException("a {$type} joins a {$want[0]} to a {$want[1]}, got ".
			($types[$src_id] ?? '?').' #'.$src_id.' -> '.($types[$dst_id] ?? '?').' #'.$dst_id);
	}
	if ($type === 'device_link' && $attrs !== null
			&& !in_array($attrs['far_port_reason'] ?? null, TOPO_FAR_PORT_REASONS, true)) {
		throw new InvalidArgumentException('a device_link needs a known far_port_reason, got '.
			json_encode($attrs['far_port_reason'] ?? null));
	}
};

// A Device whose own walk returned real ports must have no pseudo-Ports left. Run after the PORTS merge
// ($merge_pseudo_port): a pseudo-Port that is still there stands for a port the Device does not have by that name (the
// neighbor advertised `1`, the Device calls it `Gi0/1`). Each discovered link on it becomes a device_link in place
// (same edge id): src = the other side's Port, dst = this Device, reason port_unmatched, the hint from the
// advertisement (the observation of that port, the pseudo-Port's own name as a fallback); then the pseudo-Port is
// deleted. A manual link is never converted: the pseudo-Port stays and is reported (pseudo_ports_kept_manual). A link
// that cannot be converted (the device_link pair already exists) keeps the port too (pseudo_ports_move_failed). A
// pseudo-Port with no link at all is simply removed.
$convert_leftover_pseudo_ports = static function (int $device_id) use ($pdo, $validate_edge, $pseudo_note): void {
	$real = $pdo->prepare("SELECT 1 FROM topo_nodes WHERE device_id = ? AND type = 'port'".
		" AND JSON_EXTRACT(attrs, '\$.pseudo') = false LIMIT 1");
	$real->execute([$device_id]);
	if (!$real->fetchColumn()) {
		return;
	}
	$pseudo = $pdo->prepare("SELECT id, attrs FROM topo_nodes WHERE device_id = ? AND type = 'port'".
		" AND JSON_EXTRACT(attrs, '\$.pseudo') = true ORDER BY id");
	$pseudo->execute([$device_id]);
	$ports = $pseudo->fetchAll(PDO::FETCH_ASSOC);

	foreach ($ports as $port_row) {
		$port_id = (int) $port_row['id'];
		$name = (string) (json_decode($port_row['attrs'], true)['name'] ?? '');
		$edges = $pdo->prepare("SELECT id, src_id, dst_id, attrs FROM topo_edges WHERE type = 'physical_link'".
			" AND (src_id = ? OR dst_id = ?) ORDER BY id");
		$edges->execute([$port_id, $port_id]);
		$converted = 0;
		$kept_manual = [];
		$failed = [];
		$links = 0;
		foreach ($edges->fetchAll(PDO::FETCH_ASSOC) as $edge) {
			$links++;
			$attrs = json_decode($edge['attrs'], true, 512, JSON_THROW_ON_ERROR) ?: [];
			if (($attrs['discovered_via'] ?? 'manual') === 'manual') {
				$kept_manual[] = (int) $edge['id'];
				continue;
			}
			$pseudo_is_src = (int) $edge['src_id'] === $port_id;
			$other = $pseudo_is_src ? (int) $edge['dst_id'] : (int) $edge['src_id'];
			$taken = $pdo->prepare("SELECT 1 FROM topo_edges WHERE type = 'device_link' AND src_id = ? AND dst_id = ?");
			$taken->execute([$other, $device_id]);
			if ($taken->fetchColumn()) {
				$failed[] = (int) $edge['id'];
				continue;
			}

			$hint = [];
			$observation = $pdo->prepare('SELECT remote_attrs FROM topo_observations WHERE local_port_id = ? AND device_id = ?'.
				' ORDER BY last_seen DESC, id DESC LIMIT 1');
			$observation->execute([$other, $device_id]);
			if (($raw = $observation->fetchColumn()) !== false) {
				$advertised = json_decode((string) $raw, true) ?: [];
				$hint = array_filter(array_intersect_key($advertised, array_flip(['rem_port', 'rem_port_desc', 'rem_port_type'])),
					static fn ($value): bool => $value !== '' && $value !== null);
			}
			if (!$hint) {
				$hint = ['rem_port_desc' => $name];
			}

			// last_seen_src / last_seen_dst follow the canonical position of the physical_link; a device_link's src is the
			// other side's port, its dst the Device (the pseudo-Port's side).
			$other_side = (int) ($attrs[$pseudo_is_src ? 'last_seen_dst' : 'last_seen_src'] ?? 0);
			$pseudo_side = (int) ($attrs[$pseudo_is_src ? 'last_seen_src' : 'last_seen_dst'] ?? 0);
			$new = ['discovered_via' => $attrs['discovered_via'], 'far_port_reason' => 'port_unmatched', 'far_port_hint' => $hint];
			if ($other_side > 0) {
				$new['last_seen_src'] = $other_side;
			}
			if ($pseudo_side > 0) {
				$new['last_seen_dst'] = $pseudo_side;
			}
			$new['last_seen'] = max($other_side, $pseudo_side, (int) ($attrs['last_seen'] ?? 0));
			if (isset($attrs['superseded_at'])) {
				$new['superseded_at'] = $attrs['superseded_at'];
			}
			$validate_edge('device_link', $other, $device_id, $new);
			$pdo->prepare("UPDATE topo_edges SET type = 'device_link', src_id = ?, dst_id = ?, attrs = ? WHERE id = ?")
				->execute([$other, $device_id, json_encode($new, JSON_THROW_ON_ERROR), $edge['id']]);
			$converted++;
		}

		if ($kept_manual) {
			$pseudo_note('kept_manual', $device_id, $port_id, $name, ['edge_ids' => $kept_manual]);
		}
		if ($failed) {
			$pseudo_note('move_failed', $device_id, $port_id, $name, ['edge_ids' => $failed]);
		}
		if ($kept_manual || $failed) {
			continue; // the port stays: something is still attached to it
		}
		$pdo->prepare("DELETE FROM topo_nodes WHERE id = ? AND type = 'port'")->execute([$port_id]);
		$pseudo_note($links > 0 ? 'converted' : 'removed', $device_id, $port_id, $name, ['links' => $converted]);
	}
};

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
$snap_if_type = static function (?string $value, string $name = '') use ($normalize_port_name): string {
	$value = trim((string) $value);
	if (in_array($value, TOPO_PORT_CLASSES, true)) {
		return $value;
	}
	if (ctype_digit($value)) {
		if (in_array((int) $value, TOPO_PORT_CLASS_LAG, true)) {
			return 'lag';
		}
		if (in_array((int) $value, TOPO_PORT_CLASS_VIRTUAL, true)) {
			return 'virtual';
		}
	}
	$normalized = $normalize_port_name($name);
	foreach (TOPO_MGMT_NAME_PREFIXES as $prefix) {
		if ($normalized !== '' && str_starts_with($normalized, $prefix)) {
			return 'mgmt';
		}
	}
	return 'physical';
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

// topology-1-model.md §6.7.5 "Duplicate candidates": evidence of two Devices that may be one, collected in the collect phase and written to
// attrs.duplicate_candidates at the end of every FULL run (a partial run leaves the list alone). [device id on which it is shown => key => entry].
$duplicate_candidates = [];
$note_duplicate = static function (int $on, ?int $other, string $reason, string $key, string $value) use (&$duplicate_candidates): void {
	$duplicate_candidates[$on][$reason.'|'.($other ?? 0).'|'.$key.'|'.$value] = ['device_id' => $other, 'reason' => $reason, 'key' => $key, 'value' => $value];
};

// A reporter whose host reports chassis Y while its Device S has another chassis (§6.4 step 1): [S id => Y]. The candidates are derived at the
// end of the run (below), when every Device that has chassis Y exists.
$chassis_changes = [];
$note_chassis_change = static function (int $device_id, string $chassis, string $key) use (&$chassis_changes): void {
	$chassis_changes[$device_id] = [$chassis, $key];
};

// A neighbor row with chassis + IP: Y finds Device A, IP 1 finds exactly one other Device B that has no chassis -> the duplicate candidate
// `split_identity` on both. Nothing is merged (only the reporter's own identity joins automatically, $join_split_identity).
$note_split_identity = static function (?string $chassis_key, ?string $ip_key, ?string $ip_raw) use ($key_holders, $device_has_kind, $note_duplicate): void {
	if (!$chassis_key || !$ip_key) {
		return;
	}
	$with_chassis = $key_holders($chassis_key);
	if (count($with_chassis) !== 1) {
		return;
	}
	$others = array_values(array_diff($key_holders($ip_key), [$with_chassis[0]]));
	if (count($others) === 1) {
		$note_duplicate($with_chassis[0], $others[0], 'split_identity', 'mgmt_ip', (string) $ip_raw);
		$note_duplicate($others[0], $with_chassis[0], 'split_identity', 'mgmt_ip', (string) $ip_raw);
	}
};

// topology-1-model.md §6.7.1 "Join of a split identity (automatic, reporter only)". One neighbor can first give two Devices: LLDP without a
// management address gives a chassis only (A), CDP gives an IP only (B). The reporter's own identity (loc_chassis + SNMP interface IP) carries
// both keys and merges B into A when the chassis finds exactly A, the IP finds exactly one other Device B and
// B has no chassis. B's Ports, links, observations, shadow_ack entries and represented_by move to A, B's attributes fill A's empty ones,
// B is deleted (the only deletion of a Device by ingest). Both with a represented_by: no join, reported. The IP on two or more other
// Devices: shared, no join. `sysname` never joins. Returns the id of A when a join happened.
$join_split_identity = static function (?string $chassis_key, ?string $ip_key, ?string $ip_raw) use (
	$pdo, $snap_node_attrs, $normalize_port_name, $merge_pseudo_port, $move_port_sysname_keys, $convert_leftover_pseudo_ports, $key_holders, $device_has_kind,
	$insert_key, $device_holds_key, $q_key, &$link_candidates, &$summary, &$current_ip_keys
): ?int {
	if (!$chassis_key || !$ip_key) {
		return null;
	}
	$with_chassis = $key_holders($chassis_key);
	if (count($with_chassis) !== 1) {
		return null;
	}
	$a = $with_chassis[0];
	$others = array_values(array_diff($key_holders($ip_key), [$a]));
	if (count($others) !== 1) {
		return null;
	}
	$b = $others[0];
	$a_attrs = $snap_node_attrs($a);
	$b_attrs = $snap_node_attrs($b);
	if ($device_has_kind($b, 'chassis')) {
		return null;
	}
	$represented = $pdo->prepare('SELECT represented_by_node_id, represented_by_matched_by, represented_by_at FROM topo_nodes WHERE id = ?');
	$represented->execute([$a]);
	$a_rep = $represented->fetch(PDO::FETCH_ASSOC);
	$represented->execute([$b]);
	$b_rep = $represented->fetch(PDO::FETCH_ASSOC);
	if ($a_rep['represented_by_node_id'] !== null && $b_rep['represented_by_node_id'] !== null) {
		$summary['devices_join_refused']['count']++;
		if (count($summary['devices_join_refused']['list']) < 200) {
			$summary['devices_join_refused']['list'][] = ['kept' => $a, 'removed' => $b, 'key' => 'mgmt_ip', 'value' => (string) $ip_raw,
				'kept_target' => (int) $a_rep['represented_by_node_id'], 'removed_target' => (int) $b_rep['represented_by_node_id']];
		}
		return null;
	}

	// represented_by moves from B when only B has one
	if ($b_rep['represented_by_node_id'] !== null) {
		$pdo->prepare('UPDATE topo_nodes SET represented_by_node_id = NULL, represented_by_matched_by = NULL, represented_by_at = NULL WHERE id = ?')->execute([$b]);
		$pdo->prepare('UPDATE topo_nodes SET represented_by_node_id = ?, represented_by_matched_by = ?, represented_by_at = ? WHERE id = ?')
			->execute([$b_rep['represented_by_node_id'], $b_rep['represented_by_matched_by'], $b_rep['represented_by_at'], $a]);
	}

	// B's Ports -> A (topology-1-model.md §6.7.1). A real Port of B absorbs into a real Port of A with the same if_index; a pseudo port of B
	// absorbs into a pseudo or real Port of A only by normalized name, never by its allocated if_index. Ports that match nothing move to A
	// (a pseudo port whose allocated if_index is taken on A gets another negative one).
	$port_map = [];
	$a_ports = [];       // id => attrs
	$stmt = $pdo->prepare("SELECT id, attrs FROM topo_nodes WHERE device_id = ? AND type = 'port'");
	$stmt->execute([$a]);
	foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $port) {
		$a_ports[(int) $port['id']] = json_decode($port['attrs'], true) ?: [];
	}
	$stmt->execute([$b]);
	foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $port) {
		$id = (int) $port['id'];
		$attrs = json_decode($port['attrs'], true) ?: [];
		$target = null;
		if (!empty($attrs['pseudo'])) {
			$name = $normalize_port_name((string) ($attrs['name'] ?? ''));
			foreach ($a_ports as $a_id => $a_attrs) {
				if ($name !== '' && $normalize_port_name((string) ($a_attrs['name'] ?? '')) === $name) {
					$target = $a_id;
					break;
				}
			}
		}
		else {
			foreach ($a_ports as $a_id => $a_attrs) {
				if (empty($a_attrs['pseudo']) && (string) ($a_attrs['if_index'] ?? '') === (string) ($attrs['if_index'] ?? '#')) {
					$target = $a_id;
					break;
				}
			}
		}
		if ($target === null) {
			if (!empty($attrs['pseudo'])) {
				$taken = array_map(static fn (array $x): string => (string) ($x['if_index'] ?? ''), $a_ports);
				$index = (int) ($attrs['if_index'] ?? 0);
				if ($index > 0 || in_array((string) $index, $taken, true)) {
					$index = -((int) (crc32($normalize_port_name((string) ($attrs['name'] ?? ''))) % 999999) + 1);
					while (in_array((string) $index, $taken, true)) {
						$index--;
					}
					$attrs['if_index'] = $index;
					$pdo->prepare('UPDATE topo_nodes SET attrs = ? WHERE id = ?')->execute([json_encode($attrs, JSON_THROW_ON_ERROR), $id]);
				}
			}
			$pdo->prepare('UPDATE topo_nodes SET device_id = ? WHERE id = ?')->execute([$a, $id]);
			$a_ports[$id] = $attrs;
			continue;
		}
		$port_map[$id] = $target;
		$target_attrs = $snap_node_attrs($target);
		foreach ($attrs as $k => $v) {
			if (($target_attrs[$k] ?? null) === null || $target_attrs[$k] === '' || $target_attrs[$k] === []) {
				$target_attrs[$k] = $v;
			}
		}
		$pdo->prepare('UPDATE topo_nodes SET attrs = ? WHERE id = ?')->execute([json_encode($target_attrs, JSON_THROW_ON_ERROR), $target]);
		foreach ($pdo->query("SELECT id, type, src_id, dst_id FROM topo_edges WHERE src_id = {$id} OR dst_id = {$id}")->fetchAll(PDO::FETCH_ASSOC) as $edge) {
			$src = (int) $edge['src_id'] === $id ? $target : (int) $edge['src_id'];
			$dst = (int) $edge['dst_id'] === $id ? $target : (int) $edge['dst_id'];
			if ($edge['type'] === 'physical_link' && $src > $dst) {
				[$src, $dst] = [$dst, $src];
			}
			$twin = $pdo->prepare('SELECT id FROM topo_edges WHERE src_id = ? AND dst_id = ?');
			$twin->execute([$src, $dst]);
			if ($twin->fetchColumn() !== false || $src === $dst) {
				$pdo->prepare('DELETE FROM topo_edges WHERE id = ?')->execute([$edge['id']]);     // the same link already exists (or it ends on itself)
			}
			else {
				$pdo->prepare('UPDATE topo_edges SET src_id = ?, dst_id = ? WHERE id = ?')->execute([$src, $dst, $edge['id']]);
			}
		}
		$pdo->prepare('UPDATE IGNORE topo_observations SET local_port_id = ? WHERE local_port_id = ?')->execute([$target, $id]);
		$pdo->prepare('DELETE FROM topo_observations WHERE local_port_id = ?')->execute([$id]);
		$pdo->prepare('UPDATE topo_nodes SET lag_id = ? WHERE lag_id = ?')->execute([$target, $id]);
		$move_port_sysname_keys($id, $target);
		$pdo->prepare('DELETE FROM topo_nodes WHERE id = ?')->execute([$id]);
	}

	// device-level links that ended at B end at A; observations and shadow_ack that named B name A
	$pdo->prepare("UPDATE IGNORE topo_edges SET dst_id = ? WHERE type = 'device_link' AND dst_id = ?")->execute([$a, $b]);
	$pdo->prepare("DELETE FROM topo_edges WHERE type = 'device_link' AND dst_id = ?")->execute([$b]);
	$pdo->prepare('UPDATE topo_observations SET device_id = ? WHERE device_id = ?')->execute([$a, $b]);
	foreach ($pdo->query("SELECT id, attrs FROM topo_edges WHERE type IN ('physical_link', 'device_link') AND attrs LIKE '%shadow_ack%'")->fetchAll(PDO::FETCH_ASSOC) as $edge) {
		$attrs = json_decode($edge['attrs'], true, 512, JSON_THROW_ON_ERROR) ?: [];
		$changed = false;
		foreach ($attrs['shadow_ack'] ?? [] as $n => $entry) {
			if (isset($entry['device_id']) && (string) $entry['device_id'] === (string) $b) {
				$attrs['shadow_ack'][$n]['device_id'] = is_int($entry['device_id']) ? $a : (string) $a;
				$changed = true;
			}
		}
		if ($changed) {
			$pdo->prepare('UPDATE topo_edges SET attrs = ? WHERE id = ?')->execute([json_encode($attrs, JSON_THROW_ON_ERROR), $edge['id']]);
		}
	}

	// B's attributes fill only what A has no value for
	$attrs = $a_attrs;
	foreach ($b_attrs as $k => $v) {
		if ($k !== 'last_seen' && ($attrs[$k] ?? null) === null || ($attrs[$k] ?? null) === '') {
			if ($v !== null && $v !== '') {
				$attrs[$k] = $v;
			}
		}
	}
	$attrs['last_seen'] = max((int) ($a_attrs['last_seen'] ?? 0), (int) ($b_attrs['last_seen'] ?? 0));
	$pdo->prepare('UPDATE topo_nodes SET attrs = ? WHERE id = ?')->execute([json_encode($attrs, JSON_THROW_ON_ERROR), $a]);

	// a link whose two ends are both on A now is deleted (counted)
	$deleted = 0;
	foreach ($pdo->query("SELECT e.id, e.type, e.src_id, e.dst_id FROM topo_edges e WHERE e.type IN ('physical_link', 'device_link')")->fetchAll(PDO::FETCH_ASSOC) as $edge) {
		$src_device = $pdo->query('SELECT device_id FROM topo_nodes WHERE id = '.(int) $edge['src_id'])->fetchColumn();
		$dst_device = $edge['type'] === 'device_link' ? (int) $edge['dst_id']
			: $pdo->query('SELECT device_id FROM topo_nodes WHERE id = '.(int) $edge['dst_id'])->fetchColumn();
		if ((int) $src_device === $a && (int) $dst_device === $a) {
			$pdo->prepare('DELETE FROM topo_edges WHERE id = ?')->execute([$edge['id']]);
			$deleted++;
		}
	}

	// B's identity keys become A's (the ones A does not hold yet); B's own rows go with the Device
	$b_keys = $pdo->prepare('SELECT '.$q_key.' AS k, kind, first_seen, replaced FROM topo_identity_keys WHERE device_id = ?');
	$b_keys->execute([$b]);
	foreach ($b_keys->fetchAll(PDO::FETCH_ASSOC) as $b_key) {
		if (!$device_holds_key($a, $b_key['k'])) {
			$insert_key($a, $b_key['k'], $b_key['kind'], (int) $b_key['first_seen'], (bool) $b_key['replaced']);
		}
	}
	foreach ($current_ip_keys[$b] ?? [] as $moved => $unused) {
		$current_ip_keys[$a][$moved] = true;                 // the addresses the inputs gave B are A's now
	}
	unset($current_ip_keys[$b]);
	$pdo->prepare('DELETE FROM topo_nodes WHERE id = ?')->execute([$b]);

	// candidates collected earlier in this run that name B or a merged Port
	foreach ($link_candidates as &$candidate) {
		if (($candidate['remote_device_id'] ?? null) === $b) {
			$candidate['remote_device_id'] = $a;
		}
		foreach (['local_port_id', 'remote_port_id'] as $field) {
			if (isset($candidate[$field], $port_map[$candidate[$field]])) {
				$candidate[$field] = $port_map[$candidate[$field]];
			}
		}
	}
	unset($candidate);

	// §6.7.3 on A: pseudo ports that now have a real counterpart merge by name; leftovers on a Device with real ports are converted
	$real = $pdo->prepare("SELECT id, JSON_UNQUOTE(JSON_EXTRACT(attrs, '\\$.name')) AS name FROM topo_nodes WHERE device_id = ? AND type = 'port'".
		" AND JSON_EXTRACT(attrs, '\\$.pseudo') = false");
	$real->execute([$a]);
	$real_ports = $real->fetchAll(PDO::FETCH_ASSOC);
	foreach ($real_ports as $port) {
		if ((string) $port['name'] !== '') {
			$merge_pseudo_port($a, (int) $port['id'], (string) $port['name']);
		}
	}
	if ($real_ports) {
		$convert_leftover_pseudo_ports($a);
	}

	$summary['devices_joined']['count']++;
	$summary['join_links_deleted'] += $deleted;
	if (count($summary['devices_joined']['list']) < 200) {
		$summary['devices_joined']['list'][] = ['kept' => $a, 'removed' => $b, 'key' => 'mgmt_ip', 'value' => (string) $ip_raw];
	}

	return $a;
};

// Device upsert for the snapshot path: same matching keys as $device() (chassis_id, mgmt_ip, scoped sysname),
// but incoming values are merged over what the row already has — one reporter's NEIGHBORS row must not erase
// the vendor/sysname another source recorded — and last_seen only ever moves forward.
$snap_device = static function (array $incoming, ?int $local_port_id, int $seen_at, bool $for_reporter = false, array $exclude = [], bool $force_new = false) use (
	$pdo, $note_split_identity, $resolve_device, $register_keys, $insert_key, $note_shared, $insert_node, $snap_node_attrs, $snap_write_attrs, $now, $last_insert_id, &$summary, &$current_ip_keys,
	&$run_port_devices
): ?int {
	// $incoming carries the display attributes (mac, chassis_id, mgmt_ip, sysname, vendor) and the identity keys of the row: chassis_key / chassis_raw,
	// ip_key / ip_raw (§6.7.0).
	// What this row resolved to is remembered per local port for the rows after it (same-port join, §6.7.1).
	$note_row_device = static function (int $device_id) use ($local_port_id, $for_reporter, &$run_port_devices): void {
		if (!$for_reporter && $local_port_id !== null) {
			$run_port_devices[$local_port_id][$device_id] = true;
		}
	};
	// A neighbor row with both keys never merges Devices (topology-1-model.md §6.7.1, §6.7.5): it records a duplicate candidate. Only the
	// reporter's own identity joins automatically (see $snap_ingest_reporter).
	if (!$for_reporter) {      // `split_identity` is evidence of a neighbor row (§6.7.5); a reporter's own identity is not a row
		$note_split_identity($incoming['chassis_key'] ?? null, $incoming['ip_key'] ?? null, $incoming['ip_raw'] ?? null);
	}
	// $force_new: a reporter whose own key finds several Devices (§6.4 step 2, decided before pass A): the Device is made, nothing is looked up
	$match = $force_new ? null : $resolve_device([
		'chassis' => isset($incoming['chassis_key']) ? [$incoming['chassis_key'], $incoming['chassis_raw'] ?? $incoming['chassis_key']] : null,
		'ip' => isset($incoming['ip_key']) ? [$incoming['ip_key'], $incoming['ip_raw'] ?? $incoming['ip_key']] : null,
	], $local_port_id, $incoming['sysname'] ?? null, $for_reporter, $exclude);
	if ($match !== null && $match[0] === 'shared') {
		$note_shared($match[1], $match[2], $match[3]);
		return null;            // topology-1-model.md "Shared identifier": nothing is created or changed
	}
	if ($match !== null) {
		[$device_id, $matched_by] = $match;
		$owner = $pdo->prepare('SELECT represented_by_node_id, represented_by_matched_by FROM topo_nodes WHERE id = ?');
		$owner->execute([$device_id]);
		$owner = $owner->fetch(PDO::FETCH_ASSOC);
		// The reporter's own data wins (topology-1-model.md §6.7.1, §6.4 step 6): a neighbor row refreshes a Device without a reporter, and on a
		// reporter's Device it only fills what is empty. The reporter writes its own values itself ($snap_ingest_reporter).
		$has_reporter = $owner['represented_by_matched_by'] === 'reporter_self' && $owner['represented_by_node_id'] !== null;
		$before = $snap_node_attrs($device_id);
		$attrs = $before;
		// Source priority (§6.7.1, 2.29), on a Device without a reporter: a value of a `lldp` row replaces the stored one; a value of another source (cdp, push)
		// fills an empty attribute, and replaces a value that another non-lldp row gave (among rows of one priority the last row wins, pass B order). The
		// stored value of an lldp row (or of an older run, which did not note the source) is never replaced by another source. `attrs.value_source` notes
		// the attributes whose stored value came from a non-lldp row.
		$row_source = (string) ($incoming['source'] ?? 'lldp');
		$value_source = (array) ($before['value_source'] ?? []);
		foreach (['mac', 'chassis_id', 'mgmt_ip', 'sysname', 'vendor'] as $key) {
			if (($incoming[$key] ?? null) === null || $incoming[$key] === '') {
				continue;
			}
			$stored_empty = ($before[$key] ?? '') === '' || ($key === 'vendor' && $before[$key] === 'unknown');
			if ($has_reporter && !$stored_empty) {
				continue;
			}
			if (!$has_reporter && !$stored_empty && $row_source !== 'lldp' && !isset($value_source[$key])) {
				continue;      // a stored lldp value (or one of unknown source) stays
			}
			// A stored stronger key is never replaced by a different value (topology-1-model.md "Far Device"); the MAC
			// is the chassis id spelled as a MAC, so it follows the chassis id.
			if (in_array($key, ['chassis_id', 'mac'], true) && ($before['chassis_id'] ?? '') !== ''
					&& ($incoming['chassis_id'] ?? null) !== $before['chassis_id']) {
				continue;
			}
			$attrs[$key] = $incoming[$key];
			if ($row_source !== 'lldp' && !$has_reporter && !($key === 'vendor' && $incoming[$key] === 'unknown')) {
				$value_source[$key] = $row_source;
			}
			else {
				unset($value_source[$key]);
			}
		}
		if ($value_source) {
			$attrs['value_source'] = $value_source;
		}
		else {
			unset($attrs['value_source']);
		}
		// attrs.last_seen of an existing Device is not moved here: it is set once at the end of the run from its sources (§8.7), `last_seen` of a new Device is below
		if ($matched_by === 'sysname') {
			$attrs['matched_by'] = 'sysname';
		}
		if ($snap_write_attrs($device_id, $attrs, $before)) {
			$summary['devices_updated']++;
		}
		$register_keys($device_id, $incoming, $seen_at);
		if (!$for_reporter && !$has_reporter && isset($incoming['ip_key'])) {
			$current_ip_keys[$device_id][$incoming['ip_key']] = true;      // a row gives this address to a Device without a reporter (§6.7.0)
		}
		$note_row_device($device_id);
		return $device_id;
	}
	$attrs = [
		'mac' => $incoming['mac'] ?? null, 'chassis_id' => $incoming['chassis_id'] ?? null,
		'mgmt_ip' => $incoming['mgmt_ip'] ?? null, 'sysname' => $incoming['sysname'] ?? '',
		'vendor' => $incoming['vendor'] ?? 'unknown', 'last_seen' => $seen_at,
	];
	// A Device whose only key is the scoped sysname is created by sysname: marked in the same run (topology-1-model.md "Far Device"),
	// so the first repeat run changes no attribute.
	if (!($incoming['chassis_id'] ?? null) && !($incoming['mgmt_ip'] ?? null) && $local_port_id !== null && ($incoming['sysname'] ?? '') !== '') {
		$attrs['matched_by'] = 'sysname';
	}
	if (($incoming['source'] ?? 'lldp') !== 'lldp') {
		// the values of a non-lldp row are noted as such (source priority, §6.7.1): a later lldp row replaces them, another source only fills
		foreach (['mac', 'chassis_id', 'mgmt_ip', 'sysname'] as $key) {
			if (($attrs[$key] ?? '') !== '') {
				$attrs['value_source'][$key] = $incoming['source'];
			}
		}
	}
	$insert_node->execute(['device', json_encode($attrs, JSON_THROW_ON_ERROR), $now, $now]);
	$summary['devices_created']++;
	$created_id = $last_insert_id();
	foreach (['chassis', 'ip', 'sysname'] as $kind) {
		if (isset($incoming[$kind.'_key'])) {
			$insert_key($created_id, $incoming[$kind.'_key'], $kind, $seen_at);
		}
	}
	if (!$for_reporter && isset($incoming['ip_key'])) {
		$current_ip_keys[$created_id][$incoming['ip_key']] = true;
	}
	$note_row_device($created_id);
	return $created_id;
};

// physical_link upsert. Same canonicalization and per-side last_seen as $ensure_physical_link(), with three
// deliberate changes (topology-1-model.md "`last_seen` sources"): the time is the snapshot clock and only ever moves forward (so an older
// snapshot processed later cannot pull it back, and two rules confirming one side keep the newer clock);
// discovered_via is the row's source; any non-manual source upgrades a manual link. Returns the edge id.
$snap_link = static function (int $port_a, int $port_b, string $source, int $reporter_port_id, int $seen_at,
		bool $keep_manual = false) use (
	$pdo, $insert_edge, $now, $last_insert_id, $validate_edge, &$summary
): int {
	$reporter_is_a = $reporter_port_id === $port_a;
	if ($port_a > $port_b) {
		[$port_a, $port_b] = [$port_b, $port_a];
		$reporter_is_a = !$reporter_is_a;
	}
	$side = $reporter_is_a ? 'last_seen_src' : 'last_seen_dst';
	$validate_edge('physical_link', $port_a, $port_b);

	$stmt = $pdo->prepare("SELECT id, attrs FROM topo_edges WHERE type = 'physical_link' AND src_id = ? AND dst_id = ?");
	$stmt->execute([$port_a, $port_b]);
	if ($existing = $stmt->fetch(PDO::FETCH_ASSOC)) {
		$before = json_decode($existing['attrs'], true, 512, JSON_THROW_ON_ERROR) ?: [];
		$attrs = $before;
		// A discovery source upgrades a manual link it confirms, except while discovery also shows a different
		// neighbor on one of its ports or the operator acknowledged one (topology-1-model.md "Contradiction": the
		// operator decides that, not ingest): then the link stays manual. Revoking every acknowledgment hands the
		// link back to the upgrade. Every other attr, shadow_ack included, is carried over untouched.
		if (($attrs['discovered_via'] ?? 'manual') === 'manual' && !$keep_manual) {
			$attrs['discovered_via'] = $source;
			unset($attrs['shadow_ack']); // acknowledgments only make sense on a manual link (topology-1-model.md "Confirmation by discovery")
		}
		$attrs[$side] = max((int) ($attrs[$side] ?? 0), $seen_at);
		$attrs['last_seen'] = max((int) ($attrs['last_seen_src'] ?? 0), (int) ($attrs['last_seen_dst'] ?? 0));
		// topology-1-model.md "`physical_link` (Port ↔ Port)" (last_seen_port): the clock of the latest snapshot that contained this link at PORT level.
		$attrs['last_seen_port'] = max((int) ($attrs['last_seen_port'] ?? 0), $seen_at);
		if ($attrs !== $before) {
			$pdo->prepare('UPDATE topo_edges SET attrs = ? WHERE id = ?')
				->execute([json_encode($attrs, JSON_THROW_ON_ERROR), $existing['id']]);
		}
		return (int) $existing['id'];
	}
	$attrs = ['discovered_via' => $source, $side => $seen_at, 'last_seen' => $seen_at, 'last_seen_port' => $seen_at];
	$insert_edge->execute(['physical_link', $port_a, $port_b, json_encode($attrs, JSON_THROW_ON_ERROR), $now]);
	$summary['links_created']++;
	return $last_insert_id();
};

// device_link upsert (topology-1-model.md "`device_link` (Port → Device)"): src = the local Port, dst = the far Device, directed by
// construction (no canonicalization). last_seen_src is the local reporter's side; last_seen_dst is set elsewhere,
// when the far Device's own reporter names the local Device. A manual link that discovery confirms is upgraded like
// a manual physical_link ($keep_manual says it must not be); far_port_reason / far_port_hint follow the latest
// sighting.
$snap_device_link = static function (int $port_id, int $device_id, string $source, string $reason, array $hint,
		int $seen_at, bool $keep_manual = false) use ($pdo, $insert_edge, $now, $last_insert_id, $validate_edge, &$summary): int {
	$stmt = $pdo->prepare("SELECT id, attrs FROM topo_edges WHERE type = 'device_link' AND src_id = ? AND dst_id = ?");
	$stmt->execute([$port_id, $device_id]);
	if ($existing = $stmt->fetch(PDO::FETCH_ASSOC)) {
		$before = json_decode($existing['attrs'], true, 512, JSON_THROW_ON_ERROR) ?: [];
		$attrs = $before;
		$manual = ($attrs['discovered_via'] ?? 'manual') === 'manual';
		if (!$manual || !$keep_manual) {
			if ($manual) {
				$attrs['discovered_via'] = $source;
				unset($attrs['shadow_ack']); // confirmation turns it into a discovered link (topology-1-model.md "Confirmation by discovery")
			}
			$attrs['far_port_reason'] = $reason;
			if ($hint) {
				$attrs['far_port_hint'] = $hint;
			}
		}
		$attrs['last_seen_src'] = max((int) ($attrs['last_seen_src'] ?? 0), $seen_at);
		$attrs['last_seen'] = max((int) ($attrs['last_seen_src'] ?? 0), (int) ($attrs['last_seen_dst'] ?? 0));
		if ($attrs !== $before) {
			$validate_edge('device_link', $port_id, $device_id, $attrs);
			$pdo->prepare('UPDATE topo_edges SET attrs = ? WHERE id = ?')
				->execute([json_encode($attrs, JSON_THROW_ON_ERROR), $existing['id']]);
		}
		return (int) $existing['id'];
	}
	$attrs = ['discovered_via' => $source, 'far_port_reason' => $reason, 'last_seen_src' => $seen_at,
		'last_seen' => $seen_at];
	if ($hint) {
		$attrs['far_port_hint'] = $hint;
	}
	$validate_edge('device_link', $port_id, $device_id, $attrs);
	$insert_edge->execute(['device_link', $port_id, $device_id, json_encode($attrs, JSON_THROW_ON_ERROR), $now]);
	$summary['links_created']++;
	return $last_insert_id();
};

// A device-level sighting that confirms an existing PORT-level link at lower precision (topology-1-model.md "Lost precision", any
// cause): the link stays a physical_link, last_seen_* advances, last_seen_port does not. A row without last_seen_port
// is pinned to its last_seen first, so the "not confirmed since" flag has a date; no rule reads last_seen_port.
$snap_link_confirm_lower = static function (int $edge_id, int $reporter_port_id, int $seen_at) use ($pdo): void {
	$stmt = $pdo->prepare('SELECT src_id, dst_id, attrs FROM topo_edges WHERE id = ?');
	$stmt->execute([$edge_id]);
	if (!($row = $stmt->fetch(PDO::FETCH_ASSOC))) {
		return;
	}
	$before = json_decode($row['attrs'], true, 512, JSON_THROW_ON_ERROR) ?: [];
	$attrs = $before;
	if (!array_key_exists('last_seen_port', $attrs)) {
		$attrs['last_seen_port'] = (int) ($attrs['last_seen'] ?? 0);
	}
	$side = $reporter_port_id === (int) $row['src_id'] ? 'last_seen_src' : 'last_seen_dst';
	$attrs[$side] = max((int) ($attrs[$side] ?? 0), $seen_at);
	$attrs['last_seen'] = max((int) ($attrs['last_seen_src'] ?? 0), (int) ($attrs['last_seen_dst'] ?? 0));
	if ($attrs !== $before) {
		$pdo->prepare('UPDATE topo_edges SET attrs = ? WHERE id = ?')
			->execute([json_encode($attrs, JSON_THROW_ON_ERROR), $edge_id]);
	}
};

// Physical links touching either port, other than the (a,b) pair itself, split by provenance. A manual link
// on either port shadows the discovered observation (topology-1-model.md "Manual wins": manual wins, discovery never
// deletes it); a different discovered link on the local port is the cable-move case (topology-1-model.md "Replacement of discovered links").
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

// The reporter's own Device is represented by its own Host (topology-1-model.md "Reporter identity (`reporter_self`)"). $promote() itself is untouched.
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
			"already has a represented_by link to something else — leaving it in place (1:1 constraint)\n";
	}
};

// Observation upsert keyed by (rule, local port, remote identity). first_seen survives while the observation
// stays continuously present; last_seen is the snapshot clock. Nothing is written when nothing changed.
$snap_observation = static function (int $itemid, int $local_port_id, string $remote_key, array $remote_attrs,
		string $outcome, ?int $edge_id, ?int $device_id, int $seen_at, ?string $precision = null,
		?string $far_port_reason = null, bool $precision_lower = false) use ($pdo): int {
	// remote_attrs holds only what the remote device advertised. How precisely the far end was identified is ours:
	// link_precision ('port' / 'device', NULL when no link was made), far_port_reason (for 'device') and
	// precision_lower (the observation confirms a port-level link only at device level).
	$attrs_json = json_encode($remote_attrs, JSON_THROW_ON_ERROR);
	$lower = $precision_lower ? 1 : 0;
	$stmt = $pdo->prepare('SELECT id, remote_attrs, outcome, edge_id, device_id, link_precision, far_port_reason,'.
		' precision_lower, last_seen FROM topo_observations WHERE itemid = ? AND local_port_id = ? AND remote_key = ?');
	$stmt->execute([$itemid, $local_port_id, $remote_key]);
	if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
		$same = json_decode($row['remote_attrs'], true) === $remote_attrs && $row['outcome'] === $outcome
			&& ($row['edge_id'] === null ? null : (int) $row['edge_id']) === $edge_id
			&& ($row['device_id'] === null ? null : (int) $row['device_id']) === $device_id
			&& $row['link_precision'] === $precision && $row['far_port_reason'] === $far_port_reason
			&& (int) $row['precision_lower'] === $lower
			&& (int) $row['last_seen'] === $seen_at;
		if (!$same) {
			$pdo->prepare('UPDATE topo_observations SET remote_attrs = ?, outcome = ?, edge_id = ?, device_id = ?,'.
				' link_precision = ?, far_port_reason = ?, precision_lower = ?, last_seen = ? WHERE id = ?')
				->execute([$attrs_json, $outcome, $edge_id, $device_id, $precision, $far_port_reason, $lower, $seen_at,
					$row['id']]);
		}
		return (int) $row['id'];
	}
	$pdo->prepare('INSERT INTO topo_observations (itemid, local_port_id, remote_key, remote_attrs, outcome, edge_id,'.
		' device_id, link_precision, far_port_reason, precision_lower, first_seen, last_seen)'.
		' VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
		->execute([$itemid, $local_port_id, $remote_key, $attrs_json, $outcome, $edge_id, $device_id, $precision,
			$far_port_reason, $lower, $seen_at, $seen_at]);
	return (int) $pdo->lastInsertId();
};

// remote_key: the strongest identity present, prefixed with its kind, so the same neighbor seen by the same
// rule on the same port always maps to the same row.
$snap_remote_key = static function (array $row, string $segment) use ($chassis_key, $ip_key): string {
	// the key string of §6.7.0: the same neighbor seen by the same rule on the same port maps to the same row
	if (($key = $chassis_key($row['rem_chassis'] ?? null, $row['rem_chassis_type'] ?? null, $segment)) !== null) {
		return $key;
	}
	if (($key = $ip_key($row['rem_mgmt_ip'] ?? null, $segment)) !== null) {
		return $key;
	}
	return substr('sysname:'.($row['rem_sysname'] ?? ''), 0, 300);
};

// Reporters = hosts with at least one usable snapshot. Snapshots of disabled rules are skipped; a snapshot
// written under a topology type the rule no longer has is ignored (the API also deletes it on a change of the topology type — this is
// the defense in depth from topology-2-lld-delivery.md "Topology type change"). Returns [hostid => reporter].
// Identity normalization of a snapshot row (topology-1-model.md "Identity normalization"): the
// one place that decides how identity strings are spelled, whatever transport produced the snapshot. On the output of
// the native step it is a no-op (that output is already in the normal form: test_push.php runs every golden output
// through it). A MAC (chassis id of type macAddress or without a type, port id of type macAddress, ifPhysAddress, FDB
// MAC) becomes lowercase, colon-separated, six octets; a value that is shaped like a MAC but has the wrong number of
// octets cannot be normalized and the row is skipped, never guessed. A management IP becomes dotted IPv4 / compressed
// lowercase IPv6; a malformed one skips the row. A networkAddress chassis id that is an IP is written the same way; any
// other chassis / port id is text, trimmed of trailing NUL and spaces. Returns [row, null] or [null, "field: why"].
$snap_normalize_row = static function (array $row) use ($norm_clean, $norm_ip_text, $norm_man_addrs, $is_placeholder_mac): array {
	$mac = static function (string $value, bool $strict): ?string {
		$octets = null;
		if (preg_match('/^[0-9a-f]{1,2}([:-][0-9a-f]{1,2})+$/i', $value)) {
			$octets = preg_split('/[:-]/', $value);
		}
		elseif (preg_match('/^[0-9a-f]{4}(\.[0-9a-f]{4})+$/i', $value)) {
			$octets = str_split(str_replace('.', '', $value), 2);
		}
		if ($octets === null) {
			return $value;                       // not shaped like a MAC: text, left alone
		}
		if (count($octets) !== 6) {
			// Wrong length: an error where the field is known to be a MAC (ifPhysAddress, a macAddress id). Elsewhere it
			// is another kind of id that happens to look alike (a local networkAddress chassis is colon hex of 5 octets).
			return $strict ? null : $value;
		}

		return implode(':', array_map(static fn (string $o): string => strtolower(str_pad($o, 2, '0', STR_PAD_LEFT)), $octets));
	};
	$ip = $norm_ip_text;          // normalization §4.3: the same text as the native step and push.py

	foreach (['loc_chassis', 'mac', 'rem_chassis', 'rem_port', 'rem_mgmt_ip', 'rem_chassis_type', 'rem_port_type'] as $field) {
		if (isset($row[$field]) && is_string($row[$field])) {
			$row[$field] = rtrim($row[$field], "\0 ");
		}
	}
	// normalization §4.5: the cleaned value is the stored one; an empty result is no value
	foreach (['name', 'descr', 'if_name', 'rem_sysname', 'rem_port_desc', 'loc_port_desc'] as $field) {
		if (isset($row[$field]) && is_string($row[$field])) {
			if (($cleaned = $norm_clean($row[$field])) === null) {
				unset($row[$field]);
			}
			else {
				$row[$field] = $cleaned;
			}
		}
	}

	foreach (['loc_chassis' => false, 'mac' => true] as $field => $strict) {
		if (isset($row[$field]) && $row[$field] !== '') {
			if (($row[$field] = $mac($row[$field], $strict)) === null) {
				return [null, $field.': a MAC of the wrong length'];
			}
		}
	}
	// normalization §4.1/§4.2: a placeholder loc_chassis is not a loc_chassis (the reporter has none: model §6.4 steps 2 and 3); the row is valid
	if (isset($row['loc_chassis']) && $is_placeholder_mac($row['loc_chassis'])) {
		unset($row['loc_chassis']);
	}
	foreach ([['rem_chassis', 'rem_chassis_type'], ['rem_port', 'rem_port_type']] as [$field, $type_field]) {
		if (!isset($row[$field]) || $row[$field] === '') {
			continue;
		}
		$type = $row[$type_field] ?? '';
		if ($type === 'macAddress') {
			if (($row[$field] = $mac($row[$field], true)) === null) {
				return [null, $field.': a MAC of the wrong length'];
			}
		}
		elseif ($type === 'networkAddress') {
			if (($normal = $ip($row[$field])) !== null) {
				$row[$field] = $normal;
			}
		}
		elseif (($cleaned = $norm_clean($row[$field])) === null) {
			unset($row[$field]);                 // a name field of another type (and a chassis id with no type): cleaned, never validated (§5)
		}
		else {
			$row[$field] = $cleaned;
		}
	}
	if (isset($row['loc_port_id']) && is_string($row['loc_port_id'])) {
		// a macAddress port id is a MAC when it is shaped like one; it is only compared (§4.8), a bad one is no match, never a skipped row
		$cleaned = ($row['loc_port_id_type'] ?? '') === 'macAddress' ? ($mac($row['loc_port_id'], false) ?? $row['loc_port_id']) : $norm_clean($row['loc_port_id']);
		if ($cleaned === null || $cleaned === '') {
			unset($row['loc_port_id']);
		}
		else {
			$row['loc_port_id'] = $cleaned;
		}
	}
	if (isset($row['rem_mgmt_ip']) && $row['rem_mgmt_ip'] !== '') {
		if (($row['rem_mgmt_ip'] = $ip($row['rem_mgmt_ip'])) === null) {
			return [null, 'rem_mgmt_ip: not an IP address'];
		}
	}
	elseif (isset($row['rem_man_addrs']) && is_string($row['rem_man_addrs'])) {
		if (($address = $norm_man_addrs($row['rem_man_addrs'])) !== null) {
			$row['rem_mgmt_ip'] = $address;      // no usable entry: no address, not an invalid row (§4.4)
		}
	}
	// the raw entries stay in the row: the observation keeps them as received (model §7.1 remote_attrs), so the side panel can show every address the neighbor reports (§12.5 "Addresses")
	if (isset($row['rem_man_addrs']) && !is_string($row['rem_man_addrs'])) {
		unset($row['rem_man_addrs']);
	}

	return [$row, null];
};

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
$reporter_own = static function (array $reporter) use ($pdo, $segment_of, $chassis_key, $ip_key): array {
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
		'segment' => $segment_of($reporter['hostid']), 'loc_key' => null, 'ip_key' => null];
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
	$own['ip_key'] = $ip_key($own['mgmt_ip'], $own['segment']);
	if ($own['loc_chassis'] === null && $own['mgmt_ip'] === null) {
		$own['skip'] = 'identity_missing';
	}
	return $own;
};

/**
 * Pass A, step 1: the reporter's Device (§6.4). $claimed = the key strings that two or more reporters claim by their own data: this reporter does
 * not look for its Device by them, but its Device carries them (the key is then shared, §6.7.4). $exclude = Devices that another reporter takes
 * (the lowest hostid wins), so the choice does not depend on the order. $key_shared = the reporter's own key finds several stored Devices (decided before
 * pass A, §6.4 step 2): the reporter gets a Device of its own that carries the keys.
 */
$snap_reporter_identity = static function (array $reporter, array $own, array $claimed, array $exclude, bool $key_shared = false) use (
	$q_key, &$current_ip_keys,
	$pdo, $snap_device, $join_split_identity, $note_chassis_change, $segment_of, $chassis_key, $ip_key, $register_keys, $device_has_kind, $device_holds_key, $snap_port, $snap_link, $snap_other_links, $snap_observation, $snap_remote_key,
	$snap_node_attrs, $snap_write_attrs, $snap_if_type, $snap_status, $snap_link_reporter_self, $merge_pseudo_port,
	$far_pseudo_name, $pseudo_local_if_index, $normalize_port_name, $update_node, $now, &$summary,
	$convert_leftover_pseudo_ports, &$link_candidates, &$neighbor_snapshots
): array {
	$label = $reporter['host'];
	$loc_chassis = $own['loc_chassis'];
	$mgmt_ip = $own['mgmt_ip'];
	$loc_key = $own['loc_key'];
	$self_ip_key = $own['ip_key'];
	$segment = $own['segment'];
	$reporter_seen = $own['seen'];
	if ($own['skip'] === 'identity_ambiguous') {
		echo "SKIP: reporter '{$label}' reports more than one loc_chassis (".implode(', ', $own['chassis_values']).
			") — identity is ambiguous, not picking one\n";
		return ['status' => 'skipped', 'reason' => 'identity_ambiguous'];
	}
	// the keys this reporter looks its Device up by: its own, except the ones another reporter claims too
	$look_chassis = $loc_key !== null && !isset($claimed[$loc_key]) ? $loc_key : null;
	$look_ip = $self_ip_key !== null && !isset($claimed[$self_ip_key]) ? $self_ip_key : null;

	// the reporter's own identity carries both keys: it may join a split identity (§6.7.1), before its Device is looked up
	$join_split_identity($look_chassis, $look_ip, $mgmt_ip);

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
			echo "SKIP: reporter '{$label}' has neither a loc_chassis nor an SNMP interface IP — no identity key, ".
				"not creating a keyless Device\n";
			return ['status' => 'skipped', 'reason' => 'identity_missing'];
		}
		// §6.4 step 2 and 5: found by its own unclaimed keys among Devices that have no representation, else created; the Device carries all
		// the reporter's own keys, the claimed ones too
		$device_id = $snap_device(['chassis_id' => $loc_chassis, 'mgmt_ip' => $mgmt_ip,
			'chassis_key' => $look_chassis, 'chassis_raw' => $loc_chassis, 'ip_key' => $look_ip, 'ip_raw' => $mgmt_ip,
			'sysname' => $reporter['name'], 'vendor' => 'unknown'], null, $reporter_seen, true, $exclude, $key_shared);
		if ($device_id === null) {
			throw new RuntimeException("reporter '{$label}': no Device for its own identity");      // not reached: a reporter's key never stays shared
		}
		$register_keys((int) $device_id, ['chassis_key' => $loc_key, 'ip_key' => $self_ip_key, 'ip_raw' => $mgmt_ip], $reporter_seen, true);
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
		// the keys the reporter gives its own Device: its SNMP IP in this segment (a host moved to another segment adds the key of the new
		// one; nothing is rewritten, §6.7.0) and, when S has no chassis yet, the reported one
		$register_keys($device_id, ['chassis_key' => $loc_key, 'ip_key' => $self_ip_key, 'ip_raw' => $mgmt_ip], $reporter_seen, true);
		if ($mgmt_ip !== null) {
			$attrs['mgmt_ip'] = $mgmt_ip;
		}
		$snap_write_attrs($device_id, $attrs, $before);      // attrs.last_seen is set at the end of the run (§8.7)
	}
	$device_id = (int) $device_id;
	// The reporter's own data wins (§6.7.1, §6.4 step 6, §6.7.0 "Keys of a reporter's Device"): the Device holds only the reporter's own address (its SNMP
	// interface IP in its segment); every `ip:` key a neighbor row gave it before it had a reporter goes, in every segment. The only key of a Device is kept.
	if ($self_ip_key !== null) {
		$current_ip_keys[$device_id][$self_ip_key] = true;
	}
	$drop = $pdo->prepare("SELECT {$q_key} FROM topo_identity_keys WHERE device_id = ? AND kind = 'ip' AND replaced = 0".($self_ip_key !== null ? " AND {$q_key} <> ?" : ''));
	$drop->execute($self_ip_key !== null ? [$device_id, $self_ip_key] : [$device_id]);
	$foreign = $drop->fetchAll(PDO::FETCH_COLUMN);
	if ($foreign) {
		$total = $pdo->prepare('SELECT COUNT(*) FROM topo_identity_keys WHERE device_id = ?');
		$total->execute([$device_id]);
		if ((int) $total->fetchColumn() <= count($foreign)) {
			array_pop($foreign);                       // would leave no key at all: the last one stays
		}
		foreach ($foreign as $key) {
			$pdo->prepare("DELETE FROM topo_identity_keys WHERE device_id = ? AND {$q_key} = ?")->execute([$device_id, $key]);
		}
	}
	$snap_link_reporter_self($device_id, (string) $reporter['hostid'], $label);

	return ['status' => 'ok', 'device_id' => $device_id, 'segment' => $segment];
};

/** Pass A, step 2: PORTS, LAG and LEARNED_MACS of one reporter (§6.5). */
$snap_reporter_tables = static function (array $reporter, int $device_id) use (

	$pdo, $snap_device, $join_split_identity, $note_chassis_change, $segment_of, $chassis_key, $ip_key, $register_keys, $device_has_kind, $device_holds_key, $snap_port, $snap_link, $snap_other_links, $snap_observation, $snap_remote_key,
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

/** Pass B: the NEIGHBORS rows of one reporter, sorted (§6.5, §6.8). */
$snap_reporter_neighbors = static function (array $reporter, int $device_id, string $segment) use (

	$pdo, $snap_device, $join_split_identity, $note_chassis_change, $segment_of, $chassis_key, $ip_key, $register_keys, $device_has_kind, $device_holds_key, $snap_port, $snap_link, $snap_other_links, $snap_observation, $snap_remote_key,
	$resolve_local_port, $is_placeholder_mac, $sysname_key, $snap_node_attrs, $snap_write_attrs, $snap_if_type, $snap_status, $snap_link_reporter_self, $merge_pseudo_port,
	$far_pseudo_name, $pseudo_local_if_index, $normalize_port_name, $update_node, $now, &$summary,
	$convert_leftover_pseudo_ports, &$link_candidates, &$neighbor_snapshots
): array {
	$snapshots = $reporter['snapshots'];

	// ---- NEIGHBORS, pass B (topology-1-model.md §6.5, §6.8 "Collect"): the rows of this reporter in the sorted order (if_index, remote_key, itemid);
	// the reporters come in hostid order, so all rows of all reporters are processed in one sorted order ----
	// Devices and remote ports are resolved and created, but no link and no observation is written here: whether a candidate link may take its
	// port depends on what EVERY reporter's latest snapshot says, so the decision is made once, after all reporters, by $snap_resolve_links().
	$sorted_rows = [];
	// the reporter's ports from its PORTS snapshots (pass A): rows of several rules join by if_index, the first value of a field wins
	$ports = [];
	foreach ($snapshots[TOPO_TYPE_PORTS] ?? [] as $snapshot) {
		foreach ($snapshot['rows'] as $row) {
			$port = &$ports[(int) $row['if_index']];
			$port ??= ['if_index' => (int) $row['if_index']];
			foreach (['name', 'descr', 'mac'] as $field) {
				if (($row[$field] ?? '') !== '' && !isset($port[$field])) {
					$port[$field] = $row[$field];
				}
			}
			unset($port);
		}
	}
	$ports = array_values($ports);
	foreach ($snapshots[TOPO_TYPE_NEIGHBORS] ?? [] as $snapshot) {
		$neighbor_snapshots[$snapshot['itemid']] = ['itemid' => $snapshot['itemid'], 'clock' => $snapshot['clock'], 'hostid' => $reporter['hostid']];
		foreach ($snapshot['rows'] as $row) {
			[$resolved, $reason] = $resolve_local_port($row, $ports);
			if ($resolved === null) {
				$summary['rows_local_port_unresolved']['count']++;
				$summary['rows_local_port_unresolved']['by_reason'][$reason]++;
				echo "SKIP: a row of rule #{$snapshot['itemid']} has no local port ({$reason})\n";
				continue;
			}
			$row['if_index'] = $resolved;
			$sorted_rows[] = [(int) $row['if_index'], $snap_remote_key($row, $segment), (int) $snapshot['itemid'], (int) $snapshot['clock'], $row];
		}
	}
	// Within one local port the rows with a chassis or IP key come before the sysname-only rows (rank 0, 1), so the same-port join (§6.7.1) finds the Devices
	// of the port first. The remote keys sort that way by their prefix (chassis: < ip: < sysname:); the rank states it, so a new key prefix cannot change it.
	$sort_key = static fn (array $r): array => [$r[0], str_starts_with($r[1], 'sysname:') ? 1 : 0, $r[1], $r[2]];
	usort($sorted_rows, static fn (array $a, array $b): int => $sort_key($a) <=> $sort_key($b));
	$claims_seen = [];
	foreach ($sorted_rows as [, $row_remote_key, $itemid, $clock, $row]) {
		$local_port_id = $snap_port($device_id, (int) $row['if_index'], ['if_index' => (int) $row['if_index'],
			'pseudo' => false], ['name' => $row['if_name'] ?? null]);
		// Rows of one snapshot with the same claim key (rule, local port, remote key): the first row in the row order makes the observation, the others are
		// counted per rule and give no Device, no observation (§7.1; the claim key of the table is (itemid, local_port_id, remote_key)).
		$claim_key = $itemid.'|'.$local_port_id.'|'.$row_remote_key;
		if (isset($claims_seen[$claim_key])) {
			$summary['rows_duplicate_claim']['count']++;
			$summary['rows_duplicate_claim']['by_rule'][$itemid] = ($summary['rows_duplicate_claim']['by_rule'][$itemid] ?? 0) + 1;
			continue;
		}
		$claims_seen[$claim_key] = true;

		$chassis_id = ($row['rem_chassis'] ?? '') !== '' ? $row['rem_chassis'] : null;
		if ($chassis_id !== null && in_array($row['rem_chassis_type'] ?? null, [null, '', 'macAddress'], true) && $is_placeholder_mac($chassis_id)) {
			$chassis_id = null;     // a placeholder MAC is no identity (normalization §4.1): no chassis key, no chassis_id/mac attribute; $remote_attrs keeps the value as received
		}
		$looks_like_mac = $chassis_id !== null && preg_match('/^([0-9a-f]{2}:){5}[0-9a-f]{2}$/i', $chassis_id);
		$remote_attrs = array_filter(array_intersect_key($row, array_flip(['rem_chassis', 'rem_chassis_type',
			'rem_mgmt_ip', 'rem_man_addrs', 'rem_sysname', 'rem_port', 'rem_port_type', 'rem_port_desc', 'source'])),
			static fn ($value): bool => $value !== '' && $value !== null);
		$source = ($row['source'] ?? 'lldp') !== '' ? (string) ($row['source'] ?? 'lldp') : 'lldp';

		$row_ip = ($row['rem_mgmt_ip'] ?? '') !== '' ? $row['rem_mgmt_ip'] : null;
		// model §6.7.1: a row with no key at all (for example only a placeholder MAC chassis) makes no Device, no observation and no link; it is counted
		if ($chassis_key($chassis_id, $row['rem_chassis_type'] ?? null, $segment) === null && $row_ip === null && ($row['rem_sysname'] ?? '') === '') {
			$summary['rows_no_identity']['count']++;
			$summary['rows_no_identity']['by_rule'][$itemid] = ($summary['rows_no_identity']['by_rule'][$itemid] ?? 0) + 1;
			continue;
		}
		$remote_device_id = $snap_device([
			'mac' => $looks_like_mac ? strtolower($chassis_id) : null,
			'chassis_id' => $chassis_id,
			'mgmt_ip' => $row_ip,
			'chassis_key' => $chassis_key($chassis_id, $row['rem_chassis_type'] ?? null, $segment),
			'chassis_raw' => $chassis_id,
			'ip_key' => $ip_key($row_ip, $segment),
			'ip_raw' => $row_ip,
			'sysname' => $row['rem_sysname'] ?? null,
			'vendor' => 'unknown',
			'source' => $source,
			// a row whose only key is the sysname gives the scoped sysname key to the Device it resolves to (§6.7.0, §6.7.1)
			'sysname_key' => ($chassis_key($chassis_id, $row['rem_chassis_type'] ?? null, $segment) === null && $row_ip === null && ($row['rem_sysname'] ?? '') !== '')
				? $sysname_key($local_port_id, (string) $row['rem_sysname']) : null,
		], $local_port_id, $clock);
		// a shared identifier (null): the observation is `ambiguous`, no Device, no link, nothing else touched

		$has_remote_port = ($row['rem_port_desc'] ?? '') !== '' || ($row['rem_port'] ?? '') !== '';
		// The far port is decided in phase 2 ($snap_classify_candidates), once every reporter's own ports are known:
		// whether the advertisement names a real port, a pseudo one, or none (topology-1-model.md "Port-level or device-level").
		$candidate = ['hostid' => $reporter['hostid'], 'itemid' => $itemid, 'clock' => $clock, 'source' => $source,
			'local_port_id' => $local_port_id, 'remote_device_id' => $remote_device_id,
			'remote_key' => $snap_remote_key($row, $segment), 'remote_attrs' => $remote_attrs,
			'pre_outcome' => $remote_device_id === null ? 'ambiguous' : ($has_remote_port ? null : 'device_only'), // topology-1-model.md "Observations": device_only, no port advertised, no link
			'level' => 'port', 'far_port_reason' => null, 'remote_port_id' => null,
			'port_label' => $has_remote_port ? $far_pseudo_name($row + ['source' => $source]) : null,
			'rem_port_desc' => (string) ($row['rem_port_desc'] ?? ''),
			'pseudo_index' => $has_remote_port ? $pseudo_local_if_index($row['rem_port'] ?? null,
				$row['rem_port_type'] ?? null) : null,
			'pseudo_key' => $has_remote_port ? $normalize_port_name($far_pseudo_name($row + ['source' => $source])) : null,
			'rem_port' => (string) ($row['rem_port'] ?? ''), 'rem_port_type' => (string) ($row['rem_port_type'] ?? ''),
			'far_port_hint' => array_filter(array_intersect_key($row, array_flip(['rem_port', 'rem_port_desc',
				'rem_port_type'])), static fn ($value): bool => $value !== '' && $value !== null),
			'pseudo_mac' => $looks_like_mac ? strtolower($chassis_id) : null];

		$link_candidates[] = $candidate;
	}

	return ['status' => 'ok'];
};

// ============================================================================================================
// NEIGHBORS phase 2a "classify" (topology-1-model.md "Port-level or device-level")
//
// For every candidate that advertises a far port, decides at which precision the far end is known. level 'port':
// the far Port is a real one that matches the advertisement, or (far Device is not a reporter) a pseudo-Port made
// from it, as before. level 'device': the far Device is identified but its port is not, with a far_port_reason:
//   port_shared_id  several real ports match the advertisement, the far Device's authoritative ports share
//                   the advertised MAC, or several local ports advertise the same far port id
//   lag_ambiguous   several local ports of one reporter reach the same far Device and none is told apart
//   port_unmatched  the far Device is a reporter (its ports are authoritative) and no port matches
// A candidate that is both unmatched and in a shared / LAG group takes the group's reason (shared id wins over LAG).
// No port is created on a Device that has authoritative ports: that would be a phantom.
// ============================================================================================================
// An internal if_index for a pseudo port that has no real ifIndex (topology-1-model.md §6.6 "Pseudo ports never take a PORTS row"):
// NEGATIVE, from the hash of the normalized name, moved on (further down) past any if_index the Device already uses. Real ifIndex
// values are positive, so a reporter's PORTS row can never land on such a pseudo port.
$alloc_pseudo_index = static function (int $device_id, string $key) use ($pdo): int {
	$index = -((int) (crc32($key) % 999999) + 1);
	$used = $pdo->prepare("SELECT 1 FROM topo_nodes WHERE device_id = ? AND type = 'port' AND JSON_EXTRACT(attrs, '\$.if_index') = ?");
	for ($tries = 0; $tries < 1000; $tries++, $index--) {
		$used->execute([$device_id, $index]);
		if ($used->fetchColumn() === false) {
			break;
		}
	}
	return $index;
};

// The pseudo port of a far Device that is not a reporter (topology-1-model.md "Far port"). Found by the normalized name of the
// advertisement, never by the raw string, so `GigabitEthernet0/24` (LLDP) and `Gi0/24` (CDP) are one port and two different
// names are never one port. Only a numeric `local` port id (a real ifIndex) is first looked up by if_index: a confirmed Port that
// owns it is the same physical port, and a pseudo port made from it keeps that real ifIndex (marked local_ifindex). Any other new
// pseudo port gets a negative allocated if_index.
$snap_pseudo_port = static function (array $candidate) use ($pdo, $snap_port, $normalize_port_name, $alloc_pseudo_index): int {
	$device_id = $candidate['remote_device_id'];
	$key = (string) $candidate['pseudo_key'];
	if ($candidate['pseudo_index'] !== null) {
		$stmt = $pdo->prepare("SELECT id FROM topo_nodes WHERE device_id = ? AND type = 'port' AND JSON_EXTRACT(attrs, '\$.if_index') = ?");
		$stmt->execute([$device_id, $candidate['pseudo_index']]);
		if (($found = $stmt->fetchColumn()) !== false) {
			return (int) $found;
		}
	}
	$stmt = $pdo->prepare("SELECT id, JSON_UNQUOTE(JSON_EXTRACT(attrs, '\$.name')) AS name FROM topo_nodes".
		" WHERE device_id = ? AND type = 'port' AND JSON_EXTRACT(attrs, '\$.pseudo') = true ORDER BY id");
	$stmt->execute([$device_id]);
	foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $pseudo) {
		if ($normalize_port_name((string) $pseudo['name']) === $key) {
			return (int) $pseudo['id'];
		}
	}
	$local = $candidate['pseudo_index'] !== null;
	$index = $local ? $candidate['pseudo_index'] : $alloc_pseudo_index($device_id, $key);
	return $snap_port($device_id, $index, ['if_index' => $index, 'name' => $candidate['port_label'], 'pseudo' => true,
		'mac' => $candidate['pseudo_mac']] + ($local ? ['local_ifindex' => true] : []));
};

// Existing pseudo ports with a positive allocated if_index (made before the allocation became negative) move to a negative one, so a
// real port with that ifIndex can never land on them (topology-1-model.md §6.6). A pseudo port made from a numeric local port id keeps
// its real ifIndex: it is marked local_ifindex, or (older data) is named by that number. Runs before any reporter is ingested.
$reindex_pseudo_ports = static function () use ($pdo, $normalize_port_name, $alloc_pseudo_index, $update_node, $now, &$summary): void {
	$rows = $pdo->query("SELECT id, device_id, attrs FROM topo_nodes WHERE type = 'port' AND device_id IS NOT NULL".
		" AND JSON_EXTRACT(attrs, '\$.pseudo') = true AND JSON_EXTRACT(attrs, '\$.if_index') > 0 ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
	foreach ($rows as $row) {
		$attrs = json_decode($row['attrs'], true, 512, JSON_THROW_ON_ERROR) ?: [];
		if (!empty($attrs['local_ifindex']) || (string) ($attrs['name'] ?? '') === (string) $attrs['if_index']) {
			continue;
		}
		$attrs['if_index'] = $alloc_pseudo_index((int) $row['device_id'], $normalize_port_name((string) ($attrs['name'] ?? '')));
		$update_node->execute([json_encode($attrs, JSON_THROW_ON_ERROR), $now, $row['id']]);
		$summary['pseudo_ports_reindexed']++;
	}
};

$snap_classify_candidates = static function (array &$candidates) use ($pdo, $identify_far_port, $snap_port, $snap_pseudo_port): void {
	$authoritative = [];
	$is_authoritative = static function (int $device_id) use ($pdo, &$authoritative): bool {
		if (!array_key_exists($device_id, $authoritative)) {
			$stmt = $pdo->prepare("SELECT 1 FROM topo_nodes WHERE device_id = ? AND type = 'port'".
				" AND JSON_EXTRACT(attrs, '\$.pseudo') = false LIMIT 1");
			$stmt->execute([$device_id]);
			$authoritative[$device_id] = (bool) $stmt->fetchColumn();
		}
		return $authoritative[$device_id];
	};
	foreach ($candidates as &$candidate) {
		$candidate['level'] = 'port';
		$candidate['far_port_reason'] = null;
		$candidate['remote_port_id'] = null;
		if ($candidate['pre_outcome'] !== null) {
			continue;
		}
		if ($is_authoritative($candidate['remote_device_id'])) {
			// a reporter: its walk is the only description of its ports (§6.7.2)
			$identified = $identify_far_port($candidate['remote_device_id'], $candidate);
			if ($identified['port'] !== null) {
				$candidate['remote_port_id'] = $identified['port'];
			}
			else {
				$candidate['level'] = 'device';
				$candidate['far_port_reason'] = $identified['shared'] ? 'port_shared_id' : 'port_unmatched';
			}
		}
		else {
			$candidate['level'] = 'pending'; // far Device without authoritative ports: a pseudo-Port, unless shared
		}
	}
	unset($candidate);

	// topology-1-model.md "Port-level or device-level" (port_shared_id): the same far port id advertised on two or more local ports of one reporter names the device, not a port.
	$by_id = [];
	foreach ($candidates as $i => $candidate) {
		if (!in_array($candidate['level'], ['device', 'pending'], true)
				|| $candidate['far_port_reason'] === 'port_shared_id' || $candidate['rem_port'] === '') {
			continue;
		}
		// One reporter's several local ports: a single far port cannot be cabled to two of them. The same id coming
		// from two different reporters is the far-end occupancy contest of topology-1-model.md "Replacement of discovered links", not a shared id.
		$id_key = $candidate['hostid'].'|'.$candidate['remote_device_id'].'|'.$candidate['rem_port_type'].'|'
			.strtolower($candidate['rem_port']);
		$by_id[$id_key][$candidate['local_port_id']][] = $i;
	}
	foreach ($by_id as $local_ports) {
		if (count($local_ports) < 2) {
			continue;
		}
		foreach ($local_ports as $indexes) {
			foreach ($indexes as $i) {
				$candidates[$i]['level'] = 'device';
				$candidates[$i]['far_port_reason'] = 'port_shared_id';
			}
		}
	}

	// topology-1-model.md "Port-level or device-level" (lag_ambiguous): several local ports of one reporter, one far Device, no port told apart.
	$by_device = [];
	foreach ($candidates as $i => $candidate) {
		if ($candidate['level'] === 'device' && $candidate['far_port_reason'] === 'port_unmatched') {
			$by_device[$candidate['hostid'].'|'.$candidate['remote_device_id']][$candidate['local_port_id']][] = $i;
		}
	}
	foreach ($by_device as $local_ports) {
		if (count($local_ports) < 2) {
			continue;
		}
		foreach ($local_ports as $indexes) {
			foreach ($indexes as $i) {
				$candidates[$i]['far_port_reason'] = 'lag_ambiguous';
			}
		}
	}

	// The remaining far Devices have no authoritative ports: the advertisement is the only description of their port.
	foreach ($candidates as &$candidate) {
		if ($candidate['level'] !== 'pending') {
			continue;
		}
		$candidate['level'] = 'port';
		$candidate['remote_port_id'] = $snap_pseudo_port($candidate);
	}
	unset($candidate);
};

// ============================================================================================================
// NEIGHBORS phase 2b "resolve" (topology-1-model.md "Replacement of discovered links", "Several neighbors or claims on one port", "Legacy cleanup"; topology-1-model.md "Refinement: device-level → port-level", "Lost precision")
//
// One decision for the whole run, from the candidates of EVERY reporter's latest NEIGHBORS snapshots, so the
// result cannot depend on the order the reporters were walked in.
//
// An *entity* is a link or a candidate and has an identity (key): a pair of ports {P,X} for a port-level one, or
// "P>dD" (local port, far Device) for a device-level one. A device-level entity occupies only its local port; it
// occupies no port on the far Device. rules(entity) is the set of NEIGHBORS rules whose latest snapshot contains it,
// from either end. A candidate that is not in a several-neighbors group (topology-1-model.md "Several neighbors or claims on one port") and not behind a manual link *claims* its entity. Every other
// entity touching a port of the claim (active discovered links, and the other claims) is HELD against it when some
// rule outside rules(claim) contains it: a snapshot other than the contradicting one still says it. Clocks are
// never compared: a reporter's latest snapshot is its current claim however long ago it was polled (the clock only
// becomes superseded_at). A claim WINS when nothing is held against it; it is then written (created, or revived when
// it was superseded) and every existing active link it blocks becomes superseded. An existing active link stays
// unless a winner supersedes it, and its own candidates are then "applied"; a losing claim is a "conflict" pointing at
// the link that holds the port.
//
// Device level (device-level-edge spec): two entities are the same cable seen at different precision when they share
// a local port and the far Device. A device-level sighting of an existing port-level link only confirms it (topology-1-model.md "Lost precision", it
// does not contradict: contradiction compares far Devices: topology-1-model.md "Replacement of discovered links"), whatever the cause of the lost precision; a
// port-level claim over an existing device_link refines it in place (topology-1-model.md "Refinement: device-level → port-level"). A physical_link never becomes a
// device_link here (the one exception, a pseudo port corrected when its Device becomes a reporter, is topology-1-model.md "Pseudo-port merge").
//
// Before that, pre-spec data with two active discovered links on one port is cleaned up once (legacy).
// ============================================================================================================
$snap_resolve_links = static function (array $candidates, array $neighbor_snapshots, bool $replacement_enabled, array $frozen_edges = []) use (
	$pdo, $snap_classify_candidates, $snap_link, $snap_device_link, $snap_link_confirm_lower, $snap_observation,
	$validate_edge, $now, &$summary
): void {
	$snap_classify_candidates($candidates);

	$pair_key = static fn (int $x, int $y): string => min($x, $y).'-'.max($x, $y);
	$dev_key = static fn (int $port, int $device): string => $port.'>d'.$device;

	$device_of_cache = [];
	$device_of = static function (int $port) use ($pdo, &$device_of_cache): ?int {
		if (!array_key_exists($port, $device_of_cache)) {
			$stmt = $pdo->prepare("SELECT device_id FROM topo_nodes WHERE id = ? AND type = 'port'");
			$stmt->execute([$port]);
			$value = $stmt->fetchColumn();
			$device_of_cache[$port] = ($value === false || $value === null) ? null : (int) $value;
		}
		return $device_of_cache[$port];
	};

	// The entity a candidate stands for: its key and the ports it occupies. Null key = no entity (not a link).
	$candidate_entity = static function (array $c) use ($pair_key, $dev_key): array {
		if ($c['level'] === 'device') {
			return [$dev_key($c['local_port_id'], $c['remote_device_id']), [$c['local_port_id']]];
		}
		if ($c['remote_port_id'] === null || $c['remote_port_id'] === $c['local_port_id']) {
			return [null, []];
		}
		return [$pair_key($c['local_port_id'], $c['remote_port_id']), [$c['local_port_id'], $c['remote_port_id']]];
	};

	// 1. Existing links of both types, by port.
	$links = [];
	$by_port = [];
	$link_by_key = [];
	$rows = $pdo->query("SELECT id, type, src_id, dst_id, attrs FROM topo_edges".
		" WHERE type IN ('physical_link', 'device_link') ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
	$register_link = static function (array $edge) use (&$links, &$by_port, &$link_by_key, $pair_key, $dev_key): void {
		$attrs = json_decode($edge['attrs'], true, 512, JSON_THROW_ON_ERROR) ?: [];
		$device_level = $edge['type'] === 'device_link';
		$a = (int) $edge['src_id'];
		$b = (int) $edge['dst_id'];
		$key = $device_level ? $dev_key($a, $b) : $pair_key($a, $b);
		$id = (int) $edge['id'];
		$links[$id] = ['id' => $id, 'type' => $edge['type'], 'a' => $a, 'b' => $b, 'key' => $key,
			'occupies' => $device_level ? [$a] : [$a, $b],
			'manual' => ($attrs['discovered_via'] ?? 'manual') === 'manual',
			'seen' => (int) ($attrs['last_seen'] ?? 0),
			// The operator has decided something about this manual link (kept it for a hidden neighbor): from then
			// on no ingest run changes its kind, whether or not a neighbor is hiding behind it right now.
			'acknowledged' => !empty($attrs['shadow_ack']),
			'superseded_at' => isset($attrs['superseded_at']) ? (int) $attrs['superseded_at'] : null];
		foreach ($links[$id]['occupies'] as $port) {
			$by_port[$port][] = $id;
		}
		$link_by_key[$key] = $id;
	};
	foreach ($rows as $edge) {
		$register_link($edge);
	}
	$is_active_discovered = static fn (array $link): bool => !$link['manual'] && $link['superseded_at'] === null;
	// Links that depend on the NEIGHBORS rows of a reporter with a failed step (§6.8): the rows are missing in this run, which says nothing about the cable.
	// They are held against every claim and left out of the legacy cleanup.
	$frozen_keys = [];
	foreach ($frozen_edges as $frozen_id) {
		if (isset($links[$frozen_id])) {
			$frozen_keys[$links[$frozen_id]['key']] = true;
		}
	}
	// The Device at the far end of a link as seen from one of its ports ($port is the port the link is read from).
	$far_device_from = static function (array $link, int $port) use ($device_of): ?int {
		if ($link['type'] === 'device_link') {
			return $link['a'] === $port ? $link['b'] : null;
		}
		return $device_of($link['a'] === $port ? $link['b'] : $link['a']);
	};
	// The ports of a link that can be named as "local" together with the far Device each implies (cable seen from
	// each end): a physical_link implies two, a device_link one.
	$implied_pairs = static function (array $link) use ($device_of): array {
		if ($link['type'] === 'device_link') {
			return [[$link['a'], $link['b']]];
		}
		return [[$link['a'], $device_of($link['b'])], [$link['b'], $device_of($link['a'])]];
	};

	// 2. What the latest snapshots say, whatever became of the candidate: the rules that contain each entity (from
	//    either end), and the newest clock among them (only used to date a legacy cleanup).
	$rules_port = [];      // key => itemid set, exactly that entity
	$rules_of = [];        // same, plus device-level sightings of a port-level link (they still say it is there)
	$support_clock = [];
	$groups = [];          // local port => token set: the neighbors on the port from ALL the rules (topology-1-model.md "Several neighbors or claims on one port")
	$rule_tokens = [];     // local port => itemid => token set
	$device_cands = [];    // local port => [candidate index] of the device-level candidates
	foreach ($candidates as $i => $candidate) {
		[$key] = $candidate_entity($candidate);
		if ($key === null) {
			continue;
		}
		$rules_port[$key][$candidate['itemid']] = true;
		$rules_of[$key][$candidate['itemid']] = true;
		$support_clock[$key] = max($support_clock[$key] ?? 0, $candidate['clock']);
		$token = $candidate['level'] === 'device' ? 'd'.$candidate['remote_device_id'] : (string) $candidate['remote_port_id'];
		$rule_tokens[$candidate['local_port_id']][$candidate['itemid']][$token] = true;
		$groups[$candidate['local_port_id']][$token] = true;
		if ($candidate['level'] === 'device') {
			$device_cands[$candidate['local_port_id']][] = $i;
		}
	}
	foreach ($links as $link) {
		if ($link['type'] !== 'physical_link') {
			continue;
		}
		foreach ($implied_pairs($link) as [$local, $device]) {
			foreach ($device_cands[$local] ?? [] as $i) {
				if ($candidates[$i]['remote_device_id'] === $device) {
					$rules_of[$link['key']][$candidates[$i]['itemid']] = true;
					$support_clock[$link['key']] = max($support_clock[$link['key']] ?? 0, $candidates[$i]['clock']);
				}
			}
		}
	}
	// Held against a claim: some rule OUTSIDE the claim's own rules still contains the entity.
	$held = static function (string $entity_key, string $claim_key) use (&$rules_of, &$frozen_keys): bool {
		return isset($frozen_keys[$entity_key]) || (bool) array_diff_key($rules_of[$entity_key] ?? [], $rules_of[$claim_key] ?? []);
	};

	// Helpers for rewriting a stored link's attrs (used by the refinement below). There is no port-level ->
	// device-level conversion here: a link's precision only goes up (topology-1-model.md "Lost precision").
	$set_attrs = static function (int $id, array $attrs) use ($pdo): void {
		$pdo->prepare('UPDATE topo_edges SET attrs = ? WHERE id = ?')
			->execute([json_encode($attrs, JSON_THROW_ON_ERROR), $id]);
	};
	$load_attrs = static function (int $id) use ($pdo): array {
		return json_decode((string) $pdo->query("SELECT attrs FROM topo_edges WHERE id = {$id}")->fetchColumn(), true,
			512, JSON_THROW_ON_ERROR) ?: [];
	};
	// 3. Legacy data: pre-spec ingest let a cable move leave two active discovered links on one port. Keep one per
	//    port, once, whether or not a candidate arrives for that port. A partial run cannot see every reporter, so it
	//    leaves the data alone (topology-1-model.md "Runs": partial runs).
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
		$supersede_legacy = static function (int $lid, int $at) use ($pdo, &$links, &$summary, &$frozen_keys): void {
			if (isset($frozen_keys[$links[$lid]['key']])) {
				return;
			}
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
		// topology-1-model.md "Legacy cleanup": an active discovered link that shares a port with a manual link: manual wins.
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
			usort($active, static function (int $x, int $y) use (&$links, &$rules_of): int {
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

	// A MANUAL device_link is refined too (topology-1-model.md "Confirmation by discovery": discovery that identifies its far port turns it into a
	// discovered physical_link, same edge id), unless the operator acknowledged a neighbor on it: then it stays manual.
	$refinable = static function (array $claim, array $link) use ($device_of): bool {
		if ($claim['dev'] !== null || $link['type'] !== 'device_link' || $link['acknowledged']) {
			return false;
		}
		return ($link['a'] === $claim['a'] && $link['b'] === $device_of($claim['b']))
			|| ($link['a'] === $claim['b'] && $link['b'] === $device_of($claim['a']));
	};

	// 4. Classify every candidate: fixed outcome, shadowed by a manual link, part of a several-neighbors group, or a claim.
	$result = [];      // index => ['outcome' => ..., 'edge_id' => ..., 'key' => key|null, 'lower' => bool]
	$claims = [];      // key => ['a' =>, 'b' => port|null, 'dev' => device|null, 'occupies' => [], 'T' =>, 'indexes' => []]
	$confirming = [];  // key => [indexes] of several-neighbors candidates that confirm an existing active link
	$counted_groups = [];
	foreach ($candidates as $i => $candidate) {
		if ($candidate['pre_outcome'] !== null) {
			$result[$i] = ['outcome' => $candidate['pre_outcome'], 'edge_id' => null, 'key' => null];
			continue;
		}
		[$key, $occupies] = $candidate_entity($candidate);
		if ($key === null) {
			$result[$i] = ['outcome' => 'device_only', 'edge_id' => null, 'key' => null];
			continue;
		}
		$port = $candidate['local_port_id'];

		// Manual wins (topology-1-model.md "Manual wins"): unchanged, untouched by the replacement rule. Except that a manual
		// device_link is the same cable as a port-level candidate that identifies its far port: that is a confirmation.
		$as_claim = ['dev' => $candidate['level'] === 'device' ? $candidate['remote_device_id'] : null,
			'a' => $port, 'b' => $candidate['level'] === 'device' ? null : $candidate['remote_port_id']];
		$manual = [];
		foreach ($occupies as $touched) {
			foreach ($by_port[$touched] ?? [] as $lid) {
				if ($links[$lid]['manual'] && $links[$lid]['key'] !== $key && !$refinable($as_claim, $links[$lid])) {
					$manual[] = $lid;
				}
			}
		}
		if ($manual) {
			$result[$i] = ['outcome' => 'shadowed', 'edge_id' => min($manual), 'key' => null];
			continue;
		}

		// topology-1-model.md "Several neighbors or claims on one port": several neighbors on one port in one snapshot are not a sequence. None replaces another; the
		// existing active link on the port, if it is one of them, stays.
		// The neighbors on a port from all the rules are one set as soon as some rule shows several of them (LLDP shows X and Y,
		// CDP shows Z: the set is X, Y, Z). When every rule shows one neighbor, the claims of different rules are decided below
		// (competing claims, or §8.4 against the active link).
		$group_key = $port;
		$several = false;
		foreach ($rule_tokens[$port] ?? [] as $tokens) {
			$several = $several || count($tokens) >= 2;
		}
		if ($several) {
			if (!isset($counted_groups[$group_key])) {
				$counted_groups[$group_key] = true;
				$summary['ports_ambiguous']++;
			}
			$holder = null;
			foreach ($by_port[$port] ?? [] as $lid) {
				$link = $links[$lid];
				$token = $link['type'] === 'device_link' ? ($link['a'] === $port ? 'd'.$link['b'] : null)
					: (string) ($link['a'] === $port ? $link['b'] : $link['a']);
				if ($token !== null && $is_active_discovered($link) && isset($groups[$group_key][$token])) {
					$holder = $holder ?? $link;
				}
			}
			if ($holder !== null && $holder['key'] === $key) {
				$result[$i] = ['outcome' => 'applied', 'edge_id' => $holder['id'], 'key' => $key];
				$confirming[$key][] = $i;
				continue;
			}
			// the same Device as the one that holds the port, seen at device level by another rule: the same cable at a lower
			// precision, decided with the claims below (4a), not another neighbor
			if (!($holder !== null && $holder['type'] === 'physical_link' && $candidate['level'] === 'device'
					&& $far_device_from($holder, $port) === $candidate['remote_device_id'])) {
				$result[$i] = ['outcome' => 'ambiguous', 'edge_id' => null, 'key' => null];
				continue;
			}
		}

		$claims[$key] ??= ['a' => $port, 'b' => $candidate['level'] === 'device' ? null : $candidate['remote_port_id'],
			'dev' => $candidate['level'] === 'device' ? $candidate['remote_device_id'] : null,
			'occupies' => $occupies, 'T' => 0, 'indexes' => []];
		$claims[$key]['T'] = max($claims[$key]['T'], $candidate['clock']);
		$claims[$key]['indexes'][] = $i;
	}
	ksort($claims);

	// 4a. Same cable at two precisions (topology-1-model.md "Several neighbors or claims on one port", same Device at several precisions): a device-level claim whose local port and far Device
	//     are implied by a port-level claim or by an active port-level link is not a claim of its own. It confirms
	//     that entity at lower precision and takes its outcome.
	$lower_of = []; // device-level claim key => ['claim' => port-level claim key] | ['link' => link id]
	foreach ($claims as $key => $claim) {
		if ($claim['dev'] === null) {
			continue;
		}
		$target = null;
		foreach ($claims as $other_key => $other) {
			if ($other['dev'] !== null) {
				continue;
			}
			if (($other['a'] === $claim['a'] && $device_of($other['b']) === $claim['dev'])
					|| ($other['b'] === $claim['a'] && $device_of($other['a']) === $claim['dev'])) {
				$target = ['claim' => $other_key];
				break;
			}
		}
		if ($target === null) {
			foreach ($by_port[$claim['a']] ?? [] as $lid) {
				$link = $links[$lid];
				if ($link['type'] === 'physical_link' && $is_active_discovered($link)
						&& $far_device_from($link, $claim['a']) === $claim['dev']) {
					$target = ['link' => $lid];
					break;
				}
			}
		}
		if ($target !== null) {
			$lower_of[$key] = $target;
		}
	}
	foreach ($lower_of as $key => $target) {
		if (isset($target['claim'])) {
			foreach ($rules_of[$key] ?? [] as $itemid => $unused) {
				$rules_of[$target['claim']][$itemid] = true; // the device-level sighting says the cable is there
			}
		}
		unset($claims[$key]);
	}

	// 4b. topology-1-model.md "Several neighbors or claims on one port": competing new claims: a port without an active link that several different entities claim
	//     (X and Y both report R:P; R's LLDP shows X on P while its CDP shows Y). Nothing blocks any of them, the
	//     port simply has several claimants: no link, all ambiguous. Decided on the claims as they are, once.
	$claimants_of = [];
	foreach ($claims as $key => $claim) {
		foreach ($claim['occupies'] as $touched) {
			$claimants_of[$touched][$key] = true;
		}
	}
	$competing = [];
	ksort($claimants_of);
	foreach ($claimants_of as $port => $keys) {
		if (count($keys) < 2) {
			continue;
		}
		foreach ($by_port[$port] ?? [] as $lid) {
			if ($links[$lid]['manual'] || $is_active_discovered($links[$lid])) {
				continue 2; // the port has an active link: topology-1-model.md "Replacement of discovered links" decides, as usual
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

	// 4c. topology-1-model.md §8.5 (2.40, 2.41): a port P with an active discovered link L, where the claims on P (the neighbors that the rules of the reporter show on P) resolve to
	//     two or more different Devices and NO rule on P shows L's other end. The claims do not compete one by one against L (§8.4): the port is in the same state as a port
	//     without an active link, so all of them are `ambiguous` (no edge_id) and none makes a link. L stays while something supports it (the far end's latest snapshot, or a
	//     held link of a failed reporter); with no support it is superseded at T = the newest clock of the snapshots on P that contradict it, and nothing replaces it. The next
	//     run sees P without an active link: 4b gives the same result. Not changed: a rule on P still shows L's other end, or the claims resolve to one Device (§8.4 per candidate).
	$linked_ambiguous_supersede = []; // link key => T
	$port_candidates = [];            // local port => candidate indexes that are claims on it (not fixed by a pre-outcome, not shadowed)
	foreach ($candidates as $i => $candidate) {
		if ($candidate['pre_outcome'] === null && ($candidate_entity($candidate)[0] ?? null) !== null
				&& (!isset($result[$i]) || $result[$i]['outcome'] === 'ambiguous')) {
			$port_candidates[$candidate['local_port_id']][] = $i;
		}
	}
	ksort($port_candidates);
	foreach ($port_candidates as $port => $indexes) {
		$active = [];
		foreach ($by_port[$port] ?? [] as $lid) {
			if ($is_active_discovered($links[$lid])) {
				$active[] = $links[$lid];
			}
		}
		if (count($active) !== 1) {
			continue;
		}
		$link = $active[0];
		$devices = [];
		$shows_link = false;
		foreach ($indexes as $i) {
			$c = $candidates[$i];
			$devices[$c['remote_device_id']] = true;
			[$entity_key] = $candidate_entity($c);
			// a rule on P shows L's other end: the same entity, or the same Device at device level (the same cable at a lower precision, 4a)
			if ($entity_key === $link['key'] || ($c['level'] === 'device' && $link['type'] === 'physical_link'
					&& $far_device_from($link, $port) === $c['remote_device_id'])) {
				$shows_link = true;
			}
		}
		if ($shows_link || count($devices) < 2) {
			continue;
		}
		if (!isset($counted_groups[$port]) && !isset($claimants_counted[$port])) {
			$claimants_counted[$port] = true;
			$summary['ports_ambiguous']++;
		}
		$newest = 0;
		foreach ($indexes as $i) {
			$result[$i] = ['outcome' => 'ambiguous', 'edge_id' => null, 'key' => null];
			$newest = max($newest, $candidates[$i]['clock']);
			// a claim entity that these candidates feed is no claim any more: the far end's candidate of the same entity must not make the link either
			[$entity_key] = $candidate_entity($candidates[$i]);
			if (isset($claims[$entity_key])) {
				foreach ($claims[$entity_key]['indexes'] as $j) {
					$result[$j] = ['outcome' => 'ambiguous', 'edge_id' => null, 'key' => null];
				}
				unset($claims[$entity_key]);
			}
		}
		$supported = isset($frozen_keys[$link['key']]) || !empty($rules_of[$link['key']]);
		if (!$supported && $replacement_enabled) {
			$linked_ambiguous_supersede[$link['key']] = min($linked_ambiguous_supersede[$link['key']] ?? PHP_INT_MAX, $newest);
		}
	}

	// 5. Which claims win, and which existing links they supersede. A device_link that is the same cable as a
	//    port-level claim (forward half R:P -> D, or reverse half D:Q -> R) is refined by that claim (topology-1-model.md "Refinement: device-level → port-level"), not
	//    contradicted by it.
	$claims_by_port = [];
	foreach ($claims as $key => $claim) {
		foreach ($claim['occupies'] as $touched) {
			$claims_by_port[$touched][] = $key;
		}
	}
	$wins = [];
	$blockers = [];      // claim key => [entity keys touching its ports]
	foreach ($claims as $key => $claim) {
		$entities = [];
		foreach ($claim['occupies'] as $touched) {
			foreach ($by_port[$touched] ?? [] as $lid) {
				if ($links[$lid]['key'] !== $key && $is_active_discovered($links[$lid])
						&& !$refinable($claim, $links[$lid])) {
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

	$superseded = $linked_ambiguous_supersede; // link key => T (the earliest winning contradiction; 4c: the newest clock of the claims on its port, nothing replaces it)
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

	// 6. Write links: winners (created / revived / refined), stayers (confirmed), then the superseded ones.
	//    A manual link that hides a neighbor, or that has acknowledged neighbors, stays manual even when another
	//    reporter confirms it: a neighbor that flickers out of a snapshot must not make it upgradeable.
	$shadowing_manual = [];
	foreach ($result as $r) {
		if ($r['outcome'] === 'shadowed') {
			$shadowing_manual[$r['edge_id']] = true;
		}
	}
	// topology-1-model.md "Refinement: device-level → port-level": the port-level claim X = R:P <-> D:Q takes over an existing device_link of the same cable: the forward
	// half R:P -> D is converted in place (edge id kept), the reverse half D:Q -> R is absorbed (deleted, its clock
	// folded into the side for Q). Without a forward half, a reverse half is converted instead.
	$refine = static function (string $key, array $claim) use ($pdo, $pair_key, $device_of, $refinable, $candidates, &$links,
			&$by_port, &$link_by_key, $load_attrs, $set_attrs, $validate_edge, &$summary): void {
		$forward = null;
		$reverse = null;
		foreach ([$claim['a'], $claim['b']] as $touched) {
			foreach ($by_port[$touched] ?? [] as $lid) {
				$link = $links[$lid];
				if ($link['superseded_at'] !== null || !$refinable($claim, $link)) {
					continue;
				}
				if ($link['a'] === $claim['a']) {
					$forward = $forward ?? $link;
				}
				else {
					$reverse = $reverse ?? $link;
				}
			}
		}
		$converted = $forward ?? $reverse;
		if ($converted === null) {
			return;
		}
		$absorbed = $forward !== null ? $reverse : null;
		$existing_id = $link_by_key[$key] ?? null; // a physical_link row for this very pair (e.g. superseded earlier)
		$attrs = $load_attrs($converted['id']);
		$side_of_local = (int) ($attrs['last_seen_src'] ?? 0); // the converted device_link's src is its reporter's port
		$other_side = (int) ($attrs['last_seen_dst'] ?? 0);
		if ($absorbed !== null) {
			$other_side = max($other_side, (int) ($load_attrs($absorbed['id'])['last_seen_src'] ?? 0));
		}
		$src_port = $converted['a'];
		$other_port = $src_port === $claim['a'] ? $claim['b'] : $claim['a'];
		$new_src = min($src_port, $other_port);
		$new_dst = max($src_port, $other_port);
		// A manual link that discovery confirms becomes a discovered one (topology-1-model.md "Confirmation by discovery").
		$new = ['discovered_via' => $converted['manual'] ? $candidates[$claim['indexes'][0]]['source']
			: ($attrs['discovered_via'] ?? 'lldp')];
		$sides = [$src_port => $side_of_local, $other_port => $other_side];
		if ($sides[$new_src] > 0) {
			$new['last_seen_src'] = $sides[$new_src];
		}
		if ($sides[$new_dst] > 0) {
			$new['last_seen_dst'] = $sides[$new_dst];
		}
		$new['last_seen'] = max($sides[$new_src], $sides[$new_dst], (int) ($attrs['last_seen'] ?? 0));
		foreach (['superseded_at'] as $keep) {
			if (array_key_exists($keep, $attrs)) {
				$new[$keep] = $attrs[$keep];
			}
		}
		if ($existing_id !== null) {
			// The pair already has an edge: fold the device_link into it instead of creating a duplicate.
			$into = $load_attrs($existing_id);
			foreach (['last_seen_src', 'last_seen_dst'] as $field) {
				if (isset($new[$field])) {
					$into[$field] = max((int) ($into[$field] ?? 0), $new[$field]);
				}
			}
			$into['last_seen'] = max((int) ($into['last_seen_src'] ?? 0), (int) ($into['last_seen_dst'] ?? 0));
			$set_attrs($existing_id, $into);
			$pdo->prepare('DELETE FROM topo_edges WHERE id = ?')->execute([$converted['id']]);
		}
		else {
			$validate_edge('physical_link', $new_src, $new_dst);
			$pdo->prepare("UPDATE topo_edges SET type = 'physical_link', src_id = ?, dst_id = ?, attrs = ? WHERE id = ?")
				->execute([$new_src, $new_dst, json_encode($new, JSON_THROW_ON_ERROR), $converted['id']]);
		}
		if ($absorbed !== null) {
			$pdo->prepare('DELETE FROM topo_edges WHERE id = ?')->execute([$absorbed['id']]);
		}
		// Keep the in-memory picture in step: the converted / absorbed device_links are gone as such.
		foreach (array_filter([$converted, $absorbed]) as $gone) {
			unset($link_by_key[$gone['key']]);
			foreach ($gone['occupies'] as $port) {
				$by_port[$port] = array_values(array_diff($by_port[$port] ?? [], [$gone['id']]));
			}
			unset($links[$gone['id']]);
		}
		if ($existing_id === null) {
			$links[$converted['id']] = ['id' => $converted['id'], 'type' => 'physical_link', 'a' => $new_src, 'b' => $new_dst,
				'key' => $key, 'occupies' => [$new_src, $new_dst], 'manual' => false, 'seen' => $new['last_seen'],
				'acknowledged' => false,
				'superseded_at' => $new['superseded_at'] ?? null];
			$by_port[$new_src][] = $converted['id'];
			$by_port[$new_dst][] = $converted['id'];
			$link_by_key[$key] = $converted['id'];
		}
		$summary['links_refined']++;
	};

	$write_link = static function (string $key, array $indexes) use ($candidates, $snap_link, $snap_device_link, &$links,
			&$link_by_key, &$shadowing_manual): int {
		$id = 0;
		$existing = $link_by_key[$key] ?? null;
		$keep_manual = $existing !== null && $links[$existing]['manual']
			&& (isset($shadowing_manual[$existing]) || $links[$existing]['acknowledged']);
		foreach ($indexes as $i) {
			$c = $candidates[$i];
			if ($c['level'] === 'device') {
				$id = $snap_device_link($c['local_port_id'], $c['remote_device_id'], $c['source'], $c['far_port_reason'],
					$c['far_port_hint'], $c['clock'], $keep_manual);
			}
			else {
				$id = $snap_link($c['local_port_id'], $c['remote_port_id'], $c['source'], $c['local_port_id'], $c['clock'],
					$keep_manual);
			}
		}
		return $id;
	};
	$after = [];        // key => link id, for every entity that is an active link once this run is done
	foreach ($claims as $key => $claim) {
		$existing = isset($link_by_key[$key]) ? $links[$link_by_key[$key]] : null;
		$stays = $existing !== null && ($existing['manual'] || $existing['superseded_at'] === null)
			&& !isset($superseded[$key]);
		if ($wins[$key] || $stays) {
			if ($claim['dev'] === null) {
				$refine($key, $claim);
				$existing = isset($link_by_key[$key]) ? $links[$link_by_key[$key]] : null;
			}
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
	// 7b. Device-level sightings of a cable known at port level (4a): they take the outcome of that cable. A sighting
	//     of an existing active link only advances that link's clocks (last_seen_port stays), and says "lower".
	foreach ($candidates as $i => $candidate) {
		[$key] = $candidate_entity($candidate);
		if ($key === null || !isset($lower_of[$key]) || $candidate['level'] !== 'device') {
			continue;
		}
		$target = $lower_of[$key];
		if (isset($target['claim'])) {
			$first = $claims[$target['claim']]['indexes'][0];
			$result[$i] = ['outcome' => $result[$first]['outcome'], 'edge_id' => $result[$first]['edge_id'],
				'key' => $target['claim'], 'lower' => true];
			continue;
		}
		$result[$i] = ['outcome' => 'applied', 'edge_id' => $target['link'], 'key' => $links[$target['link']]['key'],
			'lower' => true];
		$snap_link_confirm_lower($target['link'], $candidate['local_port_id'], $candidate['clock']);
	}

	// 7c. last_seen_dst of a device_link: the far Device's own reporter names the local Device on some port.
	$dev_links = $pdo->query("SELECT id, src_id, dst_id, attrs FROM topo_edges WHERE type = 'device_link'")
		->fetchAll(PDO::FETCH_ASSOC);
	foreach ($dev_links as $edge) {
		$local_device = $device_of((int) $edge['src_id']);
		$reply = 0;
		foreach ($candidates as $candidate) {
			if ($candidate['remote_device_id'] === $local_device
					&& $device_of($candidate['local_port_id']) === (int) $edge['dst_id']) {
				$reply = max($reply, $candidate['clock']);
			}
		}
		$attrs = json_decode($edge['attrs'], true, 512, JSON_THROW_ON_ERROR) ?: [];
		if ($reply > (int) ($attrs['last_seen_dst'] ?? 0)) {
			$attrs['last_seen_dst'] = $reply;
			$attrs['last_seen'] = max((int) ($attrs['last_seen_src'] ?? 0), $reply);
			$pdo->prepare('UPDATE topo_edges SET attrs = ? WHERE id = ?')
				->execute([json_encode($attrs, JSON_THROW_ON_ERROR), $edge['id']]);
		}
	}

	// 8. Observations mirror the latest snapshot of each rule.
	$touched = [];
	foreach ($candidates as $i => $candidate) {
		$outcome = $result[$i]['outcome'];
		$precision = $candidate['pre_outcome'] !== null ? null : $candidate['level'];
		$touched[$candidate['itemid']][] = $snap_observation($candidate['itemid'], $candidate['local_port_id'],
			$candidate['remote_key'], $candidate['remote_attrs'], $outcome, $result[$i]['edge_id'],
			$candidate['remote_device_id'], $candidate['clock'], $precision,
			$candidate['level'] === 'device' ? $candidate['far_port_reason'] : null, !empty($result[$i]['lower']));
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

	// 9. Diagnostics (topology-1-model.md "Run summary"): active device-level links at the end of the run, per reason.
	$summary['links_device_level'] = 0;
	$summary['device_level_reasons'] = [];
	foreach ($pdo->query("SELECT attrs FROM topo_edges WHERE type = 'device_link'")->fetchAll(PDO::FETCH_COLUMN) as $raw) {
		$attrs = json_decode($raw, true, 512, JSON_THROW_ON_ERROR) ?: [];
		if (isset($attrs['superseded_at'])) {
			continue;
		}
		$summary['links_device_level']++;
		$reason = (string) ($attrs['far_port_reason'] ?? 'unknown');
		$summary['device_level_reasons'][$reason] = ($summary['device_level_reasons'][$reason] ?? 0) + 1;
	}
};

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

// Manual links contradicted by discovery at the end of a run (topology-1-model.md "Contradiction"): a
// `shadowed` observation of the link whose hidden neighbor Device is not in its attrs.shadow_ack (a shadow without
// a Device cannot be acknowledged). The hidden neighbor is decided by topo_hidden_neighbor(), the one definition shared
// with CTopologyPrototype: for a third-party observation it is the reporter, not the end of the link it reports.
// Ingest only reads shadow_ack here to count; it never writes it.
$count_contradicted_manual_links = static function () use ($pdo): int {
	$links = [];
	$device_of = $pdo->prepare("SELECT device_id FROM topo_nodes WHERE id = ? AND type = 'port'");
	foreach ($pdo->query("SELECT id, type, src_id, dst_id, attrs FROM topo_edges WHERE type IN ('physical_link', 'device_link')")
			->fetchAll(PDO::FETCH_ASSOC) as $edge) {
		$attrs = json_decode($edge['attrs'], true, 512, JSON_THROW_ON_ERROR) ?: [];
		if (($attrs['discovered_via'] ?? 'manual') === 'manual') {
			// The Ports and Devices the manual link itself joins: a neighbor that is one of those Devices is the link's own
			// far end seen at another precision (topology-1-model.md "Confirmation by discovery"), not a hidden neighbor.
			$end_ports = [];
			$end_devices = [];
			foreach ($edge['type'] === 'device_link' ? [[(int) $edge['src_id'], 'port'], [(int) $edge['dst_id'], 'device']]
					: [[(int) $edge['src_id'], 'port'], [(int) $edge['dst_id'], 'port']] as [$node, $kind]) {
				if ($kind === 'device') {
					$end_devices[] = $node;
					continue;
				}
				$end_ports[] = $node;
				$device_of->execute([$node]);
				if (($device = $device_of->fetchColumn()) !== false && $device !== null) {
					$end_devices[] = (int) $device;
				}
			}
			$links[(int) $edge['id']] = ['ack' => array_fill_keys(array_map('strval', array_column($attrs['shadow_ack'] ?? [], 'device_id')), true),
				'end_ports' => $end_ports, 'end_devices' => $end_devices];
		}
	}
	$contradicted = [];
	// Only observations of rules that still have a usable snapshot (the test of $clean_stale_observations).
	foreach ($pdo->query("SELECT o.edge_id, o.device_id, o.local_port_id, p.device_id AS local_device_id FROM topo_observations o".
			" JOIN items i ON i.itemid = o.itemid AND i.status = 0".
			" JOIN hosts h ON h.hostid = i.hostid AND h.status = 0".
			" JOIN topo_lld_snapshot s ON s.itemid = o.itemid AND s.topology_type = i.topology_type".
			" LEFT JOIN topo_nodes p ON p.id = o.local_port_id".
			" WHERE o.outcome = 'shadowed' AND o.edge_id IS NOT NULL")->fetchAll(PDO::FETCH_ASSOC) as $observation) {
		$edge_id = (int) $observation['edge_id'];
		if (!isset($links[$edge_id])) {
			continue;
		}
		$hidden = topo_hidden_neighbor((int) $observation['local_port_id'],
			$observation['local_device_id'] === null ? null : (int) $observation['local_device_id'],
			$observation['device_id'] === null ? null : (int) $observation['device_id'],
			$links[$edge_id]['end_ports'], $links[$edge_id]['end_devices']);
		if ($hidden['kind'] === 'unknown'
				|| ($hidden['kind'] === 'hidden' && !isset($links[$edge_id]['ack'][(string) $hidden['device_id']]))) {
			$contradicted[$edge_id] = true;
		}
	}
	return count($contradicted);
};

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
// ---- the claims: a key that two reporters claim by their own data identifies neither (§6.4 step 5) ----
$reporter_own_of = [];
$claimants = [];
foreach ($snapshot_reporters as $hostid => $reporter) {
	$reporter_own_of[$hostid] = $reporter_own($reporter);
	if ($reporter_own_of[$hostid]['skip'] !== null) {
		continue;
	}
	foreach ([$reporter_own_of[$hostid]['loc_key'], $reporter_own_of[$hostid]['ip_key']] as $claimed_key) {
		if ($claimed_key !== null) {
			$claimants[$claimed_key][$hostid] = true;
		}
	}
}
$claimed_keys = [];
foreach ($claimants as $claimed_key => $hosts) {
	if (count($hosts) > 1) {
		$claimed_keys[$claimed_key] = array_keys($hosts);
	}
}
// A Device that several reporters would take by their other keys goes to the reporter with the stronger key (chassis before IP); hostid only for keys of
// equal strength (§6.4 step 5). Decided here, before pass A, so the result does not depend on the order of the reporters.
$would_take = [];
$take_by = [];
$key_shared_hosts = [];
foreach ($snapshot_reporters as $hostid => $reporter) {
	$own = $reporter_own_of[$hostid];
	if ($own['skip'] !== null) {
		continue;
	}
	$has_device = $pdo->prepare("SELECT 1 FROM topo_nodes h JOIN topo_nodes d ON d.represented_by_node_id = h.id WHERE h.type = 'host' AND h.host_ref = ?");
	$has_device->execute([$hostid]);
	if ($has_device->fetchColumn() !== false) {
		continue;
	}
	$match = $resolve_device([
		'chassis' => $own['loc_key'] !== null && !isset($claimed_keys[$own['loc_key']]) ? [$own['loc_key'], (string) $own['loc_chassis']] : null,
		'ip' => $own['ip_key'] !== null && !isset($claimed_keys[$own['ip_key']]) ? [$own['ip_key'], (string) $own['mgmt_ip']] : null,
	], null, null, true);
	if ($match !== null && $match[0] === 'shared') {
		$key_shared_hosts[(int) $hostid] = $match;      // §6.4 step 2: the reporter's own key finds several Devices; decided here from the stored state, before pass A
	}
	if ($match !== null && $match[0] !== 'shared') {
		$would_take[(int) $match[0]][] = (int) $hostid;
		$take_by[(int) $match[0]][(int) $hostid] = $match[1] === 'chassis_id' ? 0 : 1;        // the strength of the key that found it
	}
}
$exclude_of = [];
$share_device = [];       // taken Device => [winner hostid, loser hostids, key name]; the candidates are written when every Device exists
foreach ($would_take as $taken_device => $hosts) {
	if (count($hosts) < 2) {
		continue;
	}
	usort($hosts, static fn (int $x, int $y): int => [$take_by[$taken_device][$x], $x] <=> [$take_by[$taken_device][$y], $y]);
	$winner = $hosts[0];
	foreach (array_slice($hosts, 1) as $loser) {
		$exclude_of[$loser][] = $taken_device;
	}
	$share_device[$taken_device] = [$winner, array_slice($hosts, 1), $take_by[$taken_device][$winner] === 0 ? 'chassis_id' : 'mgmt_ip'];
}

// ---- pass A, step 1: the own identity of every reporter ----
$identified = [];
$reporter_seen = [];          // hostid of a reporter => newest clock of its usable snapshots (§8.7)
foreach ($snapshot_reporters as $hostid => $reporter) {
	echo "--- {$reporter['host']} (LLD snapshots) ---\n";
	try {
		$pdo->beginTransaction();
		$result = $snap_reporter_identity($reporter, $reporter_own_of[$hostid], $claimed_keys, $exclude_of[$hostid] ?? [], isset($key_shared_hosts[$hostid]));
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
// `reporters_share_key` (§6.7.5): the reporters that claim one key get the candidate on their Devices, and the key is reported as shared (§6.7.4)
foreach ($claimed_keys as $claimed_key => $hosts) {
	$devices = [];
	foreach ($hosts as $hostid) {
		if (isset($identified[$hostid])) {
			$devices[$hostid] = (int) $identified[$hostid]['device_id'];
		}
	}
	$is_chassis = str_starts_with($claimed_key, 'chassis:');
	$first_own = $reporter_own_of[array_key_first($devices) ?? $hosts[0]];
	$raw_value = (string) ($is_chassis ? $first_own['loc_chassis'] : $first_own['mgmt_ip']);
	$kind_name = $is_chassis ? 'chassis_id' : 'mgmt_ip';
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
// `reporters_share_device` (§6.4 step 5, §6.7.5): the reporter that took a Device and the reporters that found it by a weaker key and got their own
foreach ($share_device as [$winner, $losers, $kind_name]) {
	$winner_device = $identified[$winner]['device_id'] ?? null;
	$raw_value = (string) ($kind_name === 'chassis_id' ? $reporter_own_of[$winner]['loc_chassis'] : $reporter_own_of[$winner]['mgmt_ip']);
	foreach ($losers as $loser) {
		$loser_device = $identified[$loser]['device_id'] ?? null;
		if ($winner_device !== null && $loser_device !== null && (int) $winner_device !== (int) $loser_device) {
			$note_duplicate((int) $winner_device, (int) $loser_device, 'reporters_share_device', $kind_name, $raw_value);
			$note_duplicate((int) $loser_device, (int) $winner_device, 'reporters_share_device', $kind_name, $raw_value);
		}
	}
}
// `reporter_key_shared` (§6.4 step 2, §6.7.5): the evidence is current: a reporter's own key is on two or more Devices besides the reporter's own
// Device. It is the key that finds the Device in §6.4 step 2: the chassis key, else the IP key (when the chassis finds nothing). A key that two
// reporters claim is `reporters_share_key`. Computed here for every reporter, so the next runs (which find the Device by step 1) keep the candidate.
foreach ($identified as $hostid => $result) {
	$own = $reporter_own_of[$hostid];
	$device = (int) $result['device_id'];
	foreach ([['chassis_id', $own['loc_key'], $own['loc_chassis']], ['mgmt_ip', $own['ip_key'], $own['mgmt_ip']]] as [$kind_name, $own_key, $raw_value]) {
		if ($own_key === null || isset($claimed_keys[$own_key])) {
			continue;
		}
		$others = array_values(array_diff($key_holders($own_key), [$device]));
		if (count($others) > 1) {
			foreach ($others as $other) {
				$note_duplicate($device, $other, 'reporter_key_shared', $kind_name, (string) $raw_value);
				$note_duplicate($other, $device, 'reporter_key_shared', $kind_name, (string) $raw_value);
			}
		}
		if ($others) {
			break;      // the key that found Devices decides; a weaker key is not looked at
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
foreach ($identified as $hostid => $result) {
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

// ---- Address keys follow the current rows (topology-1-model.md §6.7.0) ----
// At the end of a full run an `ip:` key goes when no current input gives it to its Device: a reporter's Device keeps its SNMP interface IP, any other Device
// the addresses of the current snapshot rows of all reporters (a failed rule keeps its last good snapshot, so its rows are the current ones). The only key
// of a Device is kept. Chassis keys do not follow this rule. Not touched: a run scoped to some hosts (it cannot see every input), the Device of a reporter_self host that has no data in this run, and the Devices that a failed
// reporter's observations point at. One query reads the keys, one statement per chunk removes the stale ones.
if (!$zabbix_host_filter) {
	$protected = [];
	if ($frozen_itemids) {
		foreach ($pdo->query('SELECT DISTINCT device_id FROM topo_observations WHERE device_id IS NOT NULL AND itemid IN ('.
				implode(',', array_map('intval', array_keys($frozen_itemids))).')')->fetchAll(PDO::FETCH_COLUMN) as $device_id) {
			$protected[(int) $device_id] = true;
		}
	}
	$by_device = [];
	foreach ($pdo->query("SELECT k.device_id, k.{$q_key} AS k, k.kind, k.first_seen, k.replaced, d.represented_by_matched_by AS matched_by, h.host_ref".
			" FROM topo_identity_keys k JOIN topo_nodes d ON d.id = k.device_id AND d.type = 'device'".
			" LEFT JOIN topo_nodes h ON h.id = d.represented_by_node_id AND h.type = 'host' ORDER BY k.device_id, k.first_seen DESC, k.{$q_key}")->fetchAll(PDO::FETCH_ASSOC) as $row) {
		$by_device[(int) $row['device_id']][] = $row;
	}
	$stale = [];
	foreach ($by_device as $device_id => $key_rows) {
		$host_ref = $key_rows[0]['host_ref'];
		if (isset($protected[$device_id]) || ($host_ref !== null && isset($failed_hosts[(int) $host_ref]))
				|| ($key_rows[0]['matched_by'] === 'reporter_self' && ($host_ref === null || !isset($snapshot_reporters[(int) $host_ref])))) {
			continue;
		}
		$drop = [];
		foreach ($key_rows as $row) {
			if ($row['kind'] === 'ip' && (int) $row['replaced'] === 0 && !isset($current_ip_keys[$device_id][$row['k']])) {
				$drop[] = $row['k'];
			}
		}
		if ($drop && count($drop) === count($key_rows)) {
			array_shift($drop);                  // the rows are sorted newest first: the newest of them is the only key left
		}
		foreach ($drop as $key) {
			$stale[] = [$device_id, $key];
		}
	}
	foreach (array_chunk($stale, 200) as $chunk) {
		$pdo->prepare("DELETE FROM topo_identity_keys WHERE (device_id, {$q_key}) IN (".implode(',', array_fill(0, count($chunk), '(?, ?)')).')')
			->execute(array_merge(...$chunk));
	}
	$summary['address_keys_removed'] = count($stale);
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
	// attrs.mgmt_ip is only what the UI shows (§6.7.0). A Device without a reporter that holds several addresses (one per segment) shows the one of its
	// newest key (the latest first_seen; ties by the key string), so the picture does not depend on the order in which the rows came. A reporter's Device shows its own.
	$q_key_col = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql' ? '"key"' : '`key`';
	$multi_ip = $pdo->query("SELECT k.device_id FROM topo_identity_keys k JOIN topo_nodes d ON d.id = k.device_id".
		" WHERE k.kind = 'ip' AND k.replaced = 0 AND (d.represented_by_matched_by IS NULL OR d.represented_by_matched_by <> 'reporter_self')".
		" GROUP BY k.device_id HAVING COUNT(*) > 1")->fetchAll(PDO::FETCH_COLUMN);
	foreach ($multi_ip as $device_id) {
		$stmt = $pdo->prepare("SELECT {$q_key_col} AS k FROM topo_identity_keys WHERE device_id = ? AND kind = 'ip' AND replaced = 0 ORDER BY first_seen DESC, {$q_key_col}");
		$stmt->execute([$device_id]);
		$first = $stmt->fetchColumn();
		if ($first !== false && preg_match('/^ip:(?:server|pg:\d+|proxy:\d+):(.*)$/s', (string) $first, $m) === 1) {
			$attrs = $snap_node_attrs((int) $device_id);
			if (($attrs['mgmt_ip'] ?? null) !== $m[1]) {
				$before = $attrs;
				$attrs['mgmt_ip'] = $m[1];
				$snap_write_attrs((int) $device_id, $attrs, $before);
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
