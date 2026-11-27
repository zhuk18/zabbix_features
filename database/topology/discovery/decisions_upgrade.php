#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * decisions_upgrade.php — the upgrade of the prototype data to the operator's decisions as records (topology-1-model.md §6.7.8 "Upgrade of the prototype data", 2.106; task 129).
 *
 * One function writes a record of `topo_decision` for
 *   - each pair in `attrs.duplicate_dismissed`  -> `not_same` (the time and the user of the entry);
 *   - each manual association (`represented_by_matched_by = manual`) -> `associate`;
 *   - each chassis key that a Device holds as replaced (`replaced = 1`: a chassis that the Device had before an operator merge or "Accept new chassis", or that a removed Device had) -> one
 *     `merge` record for the Device, with the replaced keys as `keys_b`: the keys that moved to it.
 * It lists the Devices that carry a replaced chassis in `attrs.replaced_chassis` for which no key row exists (a merge made before task 056 left no trace of the keys of the removed Device): those
 * merges cannot be separated later. It writes a record only when an equal one does not exist, so it can run again.
 *
 * Usage: decisions_upgrade.php --pdo-dsn <dsn> --pdo-user <user> [--pdo-password <password>]
 * Prints one JSON object: {written: {...}, skipped_no_natural_key: [...], cannot_separate: [...]}.
 */

require_once __DIR__.'/../../../ui/include/classes/topology/CTopologyAddress.php';
require_once __DIR__.'/../../../ui/include/classes/topology/CTopologyDecision.php';

/**
 * @return array  ['written' => [kind => n], 'skipped_no_natural_key' => [Device id, ...], 'cannot_separate' => [['device_id' =>, 'natural_key' =>, 'replaced_chassis' => []], ...]]
 */
function decisions_upgrade(PDO $pdo): array {
	$q_key = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql' ? '"key"' : '`key`';
	$now = time();
	$out = ['written' => ['not_same' => 0, 'associate' => 0, 'merge' => 0], 'skipped_no_natural_key' => [], 'cannot_separate' => []];

	$keys = [];       // Device id => [key => replaced]
	foreach ($pdo->query("SELECT device_id, {$q_key} AS k, replaced FROM topo_identity_keys")->fetchAll(PDO::FETCH_ASSOC) as $row) {
		$keys[(int) $row['device_id']][$row['k']] = (int) $row['replaced'];
	}
	$ports = [];      // port id => ['hostid', 'name'] of the reporter's ports
	foreach ($pdo->query("SELECT p.id, p.attrs, h.host_ref FROM topo_nodes p JOIN topo_nodes d ON d.id = p.device_id JOIN topo_nodes h ON h.id = d.represented_by_node_id AND h.type = 'host'".
			" WHERE p.type = 'port'")->fetchAll(PDO::FETCH_ASSOC) as $row) {
		$ports[(int) $row['id']] = ['hostid' => (int) $row['host_ref'], 'name' => (string) (json_decode($row['attrs'], true)['name'] ?? '')];
	}
	$devices = [];    // Device id => [attrs, represented_by_node_id, matched_by]
	foreach ($pdo->query("SELECT id, attrs, represented_by_node_id, represented_by_matched_by FROM topo_nodes WHERE type = 'device' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC) as $row) {
		$devices[(int) $row['id']] = [json_decode($row['attrs'], true) ?: [], $row['represented_by_node_id'], $row['represented_by_matched_by']];
	}
	$natural = [];
	foreach ($devices as $id => [$attrs]) {
		$held = array_keys(array_filter($keys[$id] ?? [], static fn (int $replaced): bool => $replaced === 0));
		$scope = [];
		if (!$held) {
			foreach (CTopologyAddress::scopePorts($attrs) as $port_id) {
				if (isset($ports[$port_id])) {
					$scope[] = $ports[$port_id];
				}
			}
		}
		$natural[$id] = CTopologyDecision::naturalKey($held, $attrs, $scope);
	}
	$users = [];
	try {
		$users = array_flip($pdo->query('SELECT userid, username FROM users')->fetchAll(PDO::FETCH_KEY_PAIR));
	}
	catch (PDOException $exception) {
		$users = [];
	}

	$exists = $pdo->prepare('SELECT 1 FROM topo_decision WHERE kind = ? AND key_a = ? AND (key_b <=> ?) AND (hostid <=> ?) AND (proxyid <=> ?)');
	$insert = $pdo->prepare('INSERT INTO topo_decision (kind, key_a, key_b, hostid, proxyid, keys_b, created_at, created_by, norm_version) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
	$write = static function (string $kind, string $key_a, ?string $key_b, ?string $hostid, ?string $proxyid, ?array $keys_b, int $at, ?int $by) use ($exists, $insert, $now, &$out): void {
		$exists->execute([$kind, $key_a, $key_b, $hostid, $proxyid]);
		if ($exists->fetchColumn() !== false) {
			return;
		}
		$insert->execute([$kind, $key_a, $key_b, $hostid, $proxyid, $keys_b === null ? null : json_encode(array_values($keys_b)), $at > 0 ? $at : $now, $by, CTopologyDecision::NORM_VERSION]);
		$out['written'][$kind]++;
	};

	// not_same
	foreach ($devices as $id => [$attrs]) {
		foreach ($attrs['duplicate_dismissed'] ?? [] as $entry) {
			$other = (int) ($entry['device_id'] ?? 0);
			if ($other <= $id || !isset($devices[$other])) {
				continue;      // each pair once (the other side lists it too); a Device that is gone
			}
			if ($natural[$id] === null || $natural[$other] === null) {
				$out['skipped_no_natural_key'][] = $natural[$id] === null ? $id : $other;
				continue;
			}
			$pair = [$natural[$id], $natural[$other]];
			sort($pair);
			$write('not_same', $pair[0], $pair[1], null, null, null, (int) ($entry['at'] ?? 0), isset($users[$entry['by'] ?? '']) ? (int) $users[$entry['by']] : null);
		}
	}
	// associate
	foreach ($devices as $id => [$attrs, $represented, $matched_by]) {
		if ($matched_by !== 'manual' || $represented === null) {
			continue;
		}
		$node = $pdo->prepare('SELECT type, host_ref, proxy_ref FROM topo_nodes WHERE id = ?');
		$node->execute([$represented]);
		$target = $node->fetch(PDO::FETCH_ASSOC);
		if (!$target || $natural[$id] === null) {
			if ($target && $natural[$id] === null) {
				$out['skipped_no_natural_key'][] = $id;
			}
			continue;
		}
		$write('associate', $natural[$id], null, $target['type'] === 'host' ? (string) $target['host_ref'] : null, $target['type'] === 'proxy' ? (string) $target['proxy_ref'] : null, null, 0, null);
	}
	// merge: the replaced chassis keys of a Device moved to it
	foreach ($devices as $id => [$attrs]) {
		$replaced_keys = array_keys(array_filter($keys[$id] ?? [], static fn (int $replaced): bool => $replaced === 1));
		sort($replaced_keys);
		if ($replaced_keys) {
			if ($natural[$id] === null) {
				$out['skipped_no_natural_key'][] = $id;
			}
			else {
				$write('merge', $natural[$id], $replaced_keys[0], null, null, $replaced_keys, 0, null);
				// the keys that moved are marked on the Device (attrs.merged_keys), so that deleting the record separates them (§6.7.8 "Separation")
				$marked = array_values(array_unique(array_merge((array) ($attrs['merged_keys'] ?? []), $replaced_keys)));
				if ($marked !== (array) ($attrs['merged_keys'] ?? [])) {
					$attrs['merged_keys'] = $marked;
					$pdo->prepare('UPDATE topo_nodes SET attrs = ? WHERE id = ?')->execute([json_encode($attrs, JSON_THROW_ON_ERROR), $id]);
				}
			}
		}
		// the chassis values that attrs lists as replaced and no key row names: the keys of that merge are gone
		$named = array_filter(array_map(static fn (string $key): ?string => CTopologyDecision::chassisOfKey($key), array_keys($keys[$id] ?? [])), static fn ($v): bool => $v !== null);
		$orphans = array_values(array_diff((array) ($attrs['replaced_chassis'] ?? []), $named));
		if ($orphans) {
			$out['cannot_separate'][] = ['device_id' => $id, 'natural_key' => $natural[$id], 'replaced_chassis' => $orphans];
		}
	}
	$out['skipped_no_natural_key'] = array_values(array_unique($out['skipped_no_natural_key']));

	return $out;
}

if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__) {
	$options = getopt('', ['pdo-dsn:', 'pdo-user:', 'pdo-password::']);
	if (!isset($options['pdo-dsn'], $options['pdo-user'])) {
		fwrite(STDERR, "Usage: {$argv[0]} --pdo-dsn <dsn> --pdo-user <user> [--pdo-password <password>]\n");
		exit(2);
	}
	$pdo = new PDO($options['pdo-dsn'], $options['pdo-user'], $options['pdo-password'] ?? '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
	echo json_encode(decisions_upgrade($pdo), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
}
