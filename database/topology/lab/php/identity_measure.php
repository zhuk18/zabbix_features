<?php

declare(strict_types=1);

/**
 * identity_measure.php — task 055 section 1: how the Devices are identified today, and what "identity by chassis only" (draft reconcile-as-function.md, Part B) would
 * do to the same data. READ ONLY: it never writes (the session is read only); point it at a throwaway COPY of the database, never at `zabbix`.
 *
 * Usage: php identity_measure.php <PDO DSN of the copy> <database user> [password]
 *
 * 1. Devices by the keys they hold (topo_identity_keys): chassis MAC; chassis of another subtype (per subtype); only IP; only sysname; IP and sysname without a chassis.
 * 2. The current neighbor claims (topo_observations) without a MAC chassis, per source.
 * 3. Of the Devices without a MAC chassis: seen by one reporter only, by two or more, by none now.
 * 4. Part B on the same claims: a claim with a chassis is the Device of that chassis (a reporter's own Device has its own chassis); a claim without a chassis is the Device
 *    of the claim with a chassis on the same local port of the same reporter when there is exactly one (LLDP and CDP of one reporter), else the Device of this reporter
 *    and local port. Counts: Devices; today's Devices that would split; pairs of Devices without a chassis with the same address ("possible duplicate"); links whose far end
 *    would change (the Device that keeps the id is the one with the chassis, else the one with most claims).
 */

[$dsn, $user] = [$argv[1] ?? '', $argv[2] ?? ''];
if ($dsn === '' || $user === '') {
	fwrite(STDERR, "Usage: {$argv[0]} <PDO DSN of a throwaway copy> <database user> [password]\n");
	exit(1);
}
$pdo = new PDO($dsn, $user, $argv[3] ?? '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->exec('SET SESSION TRANSACTION READ ONLY');

$show = static function (string $title, array $list, int $limit = 20): void {
	echo $title.' ('.count($list).')'.(count($list) > $limit ? ': list not shown, more than '.$limit : ':')."\n";
	if (count($list) <= $limit) {
		foreach ($list as $line) {
			echo '    '.$line."\n";
		}
	}
};

// ---- the Devices, their keys, the reporters that see them ----
$devices = [];
foreach ($pdo->query("SELECT id, attrs, represented_by_matched_by AS matched_by FROM topo_nodes WHERE type = 'device' ORDER BY id") as $row) {
	$attrs = json_decode((string) $row['attrs'], true) ?: [];
	$devices[(int) $row['id']] = ['id' => (int) $row['id'], 'sysname' => (string) ($attrs['sysname'] ?? ''), 'matched_by' => $row['matched_by'], 'keys' => [], 'reporters' => []];
}
foreach ($pdo->query('SELECT device_id, `key`, kind, replaced FROM topo_identity_keys WHERE replaced = 0 ORDER BY device_id, `key`') as $row) {
	if (isset($devices[(int) $row['device_id']])) {
		$devices[(int) $row['device_id']]['keys'][] = $row['key'];
	}
}
$claims = [];
foreach ($pdo->query('SELECT o.id, o.itemid, o.local_port_id, o.remote_key, o.outcome, o.edge_id, o.device_id, o.remote_attrs, h.host AS reporter, h.hostid'.
		' FROM topo_observations o JOIN items i ON i.itemid = o.itemid JOIN hosts h ON h.hostid = i.hostid ORDER BY o.id') as $row) {
	$remote = json_decode((string) $row['remote_attrs'], true) ?: [];
	$row['source'] = (string) ($remote['source'] ?? 'lldp');
	$row['ip'] = isset($remote['rem_mgmt_ip']) && $remote['rem_mgmt_ip'] !== '' ? (string) $remote['rem_mgmt_ip'] : null;
	$row['sysname'] = (string) ($remote['rem_sysname'] ?? '');
	$claims[] = $row;
	if ($row['device_id'] !== null && isset($devices[(int) $row['device_id']])) {
		$devices[(int) $row['device_id']]['reporters'][$row['reporter']] = true;
	}
}
foreach ($pdo->query("SELECT d.id, h.host FROM topo_nodes d JOIN topo_nodes hn ON hn.id = d.represented_by_node_id AND hn.type = 'host' JOIN hosts h ON h.hostid = hn.host_ref".
		" WHERE d.type = 'device' AND d.represented_by_matched_by = 'reporter_self'") as $row) {
	$devices[(int) $row['id']]['own_reporter'] = $row['host'];
}

$kind_of = static function (array $d): string {
	$chassis = array_values(array_filter($d['keys'], static fn (string $k): bool => str_starts_with($k, 'chassis:')));
	$ip = (bool) array_filter($d['keys'], static fn (string $k): bool => str_starts_with($k, 'ip:'));
	$sys = (bool) array_filter($d['keys'], static fn (string $k): bool => str_starts_with($k, 'sysname:'));
	foreach ($chassis as $k) {
		if (str_starts_with($k, 'chassis:mac:')) {
			return 'chassis MAC';
		}
	}
	if ($chassis) {
		return 'chassis not MAC, subtype '.explode(':', $chassis[0])[1];
	}
	return $ip && $sys ? 'IP and sysname, no chassis' : ($ip ? 'only IP' : ($sys ? 'only sysname' : 'no key'));
};
$describe = static fn (array $d): string => '#'.$d['id'].' sysname='.json_encode($d['sysname']).' keys=['.implode(' ', $d['keys']).'] reporters=['.
	implode(', ', array_keys($d['reporters'] + (isset($d['own_reporter']) ? [$d['own_reporter'] => true] : []))).']'.(isset($d['own_reporter']) ? ' (own Device of '.$d['own_reporter'].')' : '');

echo "=== 1. Devices by the keys they hold: ".count($devices)." Devices ===\n";
$by_kind = ['chassis MAC' => [], 'only IP' => [], 'only sysname' => [], 'IP and sysname, no chassis' => [], 'no key' => []];
foreach ($devices as $d) {
	$by_kind[$kind_of($d)][] = $d;
}
ksort($by_kind);
foreach ($by_kind as $kind => $list) {
	echo sprintf("  %-40s %4d  (reporters' own Devices: %d)\n", $kind, count($list), count(array_filter($list, static fn (array $d): bool => isset($d['own_reporter']))));
}
foreach ($by_kind as $kind => $list) {
	if ($kind !== 'chassis MAC') {
		$show('  '.$kind, array_map($describe, $list));
	}
}

echo "\n=== 2. current neighbor claims (observations): ".count($claims)." ===\n";
$class_of = static function (array $c): string {
	$k = $c['remote_key'];
	if (str_starts_with($k, 'chassis:mac:')) {
		return 'MAC chassis';
	}
	if (str_starts_with($k, 'chassis:')) {
		return 'chassis not MAC ('.explode(':', $k)[1].')';
	}
	return str_starts_with($k, 'ip:') ? 'no chassis, key ip' : (str_starts_with($k, 'sysname:') ? 'no chassis, key sysname' : 'no chassis, other key');
};
$table = [];
foreach ($claims as $c) {
	$table[strtoupper($c['source'])][$class_of($c)] = ($table[strtoupper($c['source'])][$class_of($c)] ?? 0) + 1;
}
ksort($table);
foreach ($table as $source => $row) {
	arsort($row);
	$without = array_sum(array_filter($row, static fn (string $k): bool => $k !== 'MAC chassis', ARRAY_FILTER_USE_KEY));
	echo "  {$source}: ".array_sum($row)." claims, without a MAC chassis: {$without}; ".json_encode($row)."\n";
}

echo "\n=== 3. Devices without a MAC chassis, by the reporters that see them ===\n";
$nomac = array_filter($devices, static fn (array $d): bool => $kind_of($d) !== 'chassis MAC');
$seen = ['none now' => [], 'one reporter' => [], 'two or more reporters' => []];
foreach ($nomac as $d) {
	$n = count($d['reporters']);
	$seen[$n === 0 ? 'none now' : ($n === 1 ? 'one reporter' : 'two or more reporters')][] = $describe($d);
}
echo '  Devices without a MAC chassis: '.count($nomac)."\n";
foreach ($seen as $label => $list) {
	$show('  seen by '.$label, $list);
}

echo "\n=== 4. Part B (identity by chassis only) on the same claims ===\n";
// a chassis-less claim: the claim with a chassis on the same local port of the same reporter (exactly one), else reporter + port
$chassis_on_port = [];
foreach ($claims as $c) {
	if (str_starts_with($c['remote_key'], 'chassis:')) {
		$chassis_on_port[$c['hostid'].'|'.$c['local_port_id']][$c['remote_key']] = true;
	}
}
$part_b = [];       // Part B Device => ['claims' => n, 'ips' => [], 'chassis' => bool]
$claim_device = [];
$several_chassis_on_port = 0;
foreach ($claims as $n => $c) {
	if (str_starts_with($c['remote_key'], 'chassis:')) {
		$id = 'C|'.$c['remote_key'];
	}
	else {
		$on_port = array_keys($chassis_on_port[$c['hostid'].'|'.$c['local_port_id']] ?? []);
		if (count($on_port) === 1) {
			$id = 'C|'.$on_port[0];
		}
		else {
			$several_chassis_on_port += count($on_port) > 1 ? 1 : 0;
			$id = 'R|'.$c['reporter'].'|port '.$c['local_port_id'];
		}
	}
	$claim_device[$n] = $id;
	$part_b[$id]['claims'] = ($part_b[$id]['claims'] ?? 0) + 1;
	$part_b[$id]['chassis'] = str_starts_with($id, 'C|');
	if ($c['ip'] !== null) {
		$part_b[$id]['ips'][$c['ip']] = true;
	}
}
// the reporters' own Devices: with a chassis key they are C| Devices (possibly the same as a neighbor's), without one a Device of their own
foreach ($devices as $d) {
	if (isset($d['own_reporter'])) {
		$chassis = array_values(array_filter($d['keys'], static fn (string $k): bool => str_starts_with($k, 'chassis:')));
		$id = $chassis ? 'C|'.$chassis[0] : 'O|'.$d['own_reporter'];
		$part_b[$id]['claims'] = $part_b[$id]['claims'] ?? 0;
		$part_b[$id]['chassis'] = (bool) $chassis;
		$part_b[$id]['own'] = true;
	}
}
$no_chassis = array_filter($part_b, static fn (array $p): bool => !$p['chassis']);
echo '  Devices today: '.count($devices).'; Devices in Part B: '.count($part_b).' (with a chassis: '.(count($part_b) - count($no_chassis)).', without: '.count($no_chassis).")\n";
echo "  claims without a chassis on a port that has several chassis claims (not attached): {$several_chassis_on_port}\n";

// today's Devices that would split: the claims of one Device that Part B gives to several Devices
$split = [];
$links_changed = [];
$links_into_split = [];
foreach ($devices as $id => $d) {
	$mine = [];
	foreach ($claims as $n => $c) {
		if ((int) $c['device_id'] === $id) {
			$mine[$n] = $claim_device[$n];
		}
	}
	$kinds = array_count_values($mine);
	if (count($kinds) < 2) {
		continue;
	}
	$with_chassis = array_filter(array_keys($kinds), static fn (string $k): bool => str_starts_with($k, 'C|'));
	$keeper = count($with_chassis) === 1 ? array_values($with_chassis)[0] : (function () use ($kinds): string {
		arsort($kinds);
		return array_key_first($kinds);
	})();
	$split[$id] = $describe($d).' -> '.count($kinds).' Devices: '.implode(', ', array_map(static fn (string $k): string => $k.' ('.$kinds[$k].' claims)', array_keys($kinds)));
	foreach ($mine as $n => $part) {
		if ($claims[$n]['edge_id'] !== null) {
			$links_into_split[$claims[$n]['edge_id']] = true;
			if ($part !== $keeper) {
				$links_changed[$claims[$n]['edge_id']] = true;
			}
		}
	}
}
$show('  today\'s Devices that would split into several', array_values($split));
echo '  links whose far Device would split: '.count($links_into_split).'; links whose far end would change (not the Device that keeps the id): '.count($links_changed)."\n";

// pairs of Devices without a chassis with the same address
$by_ip = [];
foreach ($no_chassis as $id => $p) {
	foreach (array_keys($p['ips'] ?? []) as $ip) {
		$by_ip[$ip][] = $id;
	}
}
$pairs = [];
foreach ($by_ip as $ip => $ids) {
	for ($i = 0; $i < count($ids); $i++) {
		for ($j = $i + 1; $j < count($ids); $j++) {
			$pairs[] = $ip.': '.$ids[$i].' and '.$ids[$j];
		}
	}
}
$show('  pairs of Devices without a chassis with the same address ("possible duplicate (same address)")', $pairs);
$mixed = [];
foreach ($by_ip as $ip => $ids) {
	$with = array_filter(array_keys($part_b), static fn (string $id): bool => $part_b[$id]['chassis'] && isset($part_b[$id]['ips'][$ip]));
	if ($with && $ids) {
		$mixed[] = $ip.': '.implode(', ', $ids).' and the chassis Device(s) '.implode(', ', $with);
	}
}
$show('  addresses shared by a Device without a chassis and a Device with one (not marked in the draft)', $mixed);
$no_chassis_list = [];
foreach ($no_chassis as $id => $p) {
	$no_chassis_list[] = $id.' claims='.$p['claims'].' ips=['.implode(' ', array_keys($p['ips'] ?? [])).']';
}
$show('  Part B Devices without a chassis', $no_chassis_list);

// the claims without a chassis that today find a reporter's own Device (by its SNMP address or sysname): the links between two reporters seen only by CDP
$own_ids = array_filter(array_keys($devices), static fn (int $id): bool => isset($devices[$id]['own_reporter']));
$to_reporter = ['joined' => 0, 'alone' => 0];
$to_reporter_list = [];
foreach ($claims as $n => $c) {
	if (!str_starts_with($c['remote_key'], 'chassis:') && $c['device_id'] !== null && in_array((int) $c['device_id'], $own_ids, true)) {
		$joined = str_starts_with($claim_device[$n], 'C|');
		$to_reporter[$joined ? 'joined' : 'alone']++;
		if (!$joined) {
			$to_reporter_list[] = '#'.$c['id'].' '.strtoupper($c['source']).' on '.$c['reporter'].' port '.$c['local_port_id'].' -> reporter Device #'.$c['device_id'].' ('.$devices[(int) $c['device_id']]['own_reporter'].')';
		}
	}
}
echo "  claims without a chassis that today find a reporter's own Device: ".array_sum($to_reporter).' (joined to a chassis claim on the same port: '.$to_reporter['joined'].', alone: '.$to_reporter['alone'].")\n";
$show('  those that would lose the reporter\'s Device', $to_reporter_list);
