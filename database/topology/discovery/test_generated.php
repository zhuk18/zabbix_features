#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * test_generated.php — generated values for every input of topology-6-normalization.md (§3-§5) through the native step, push.py and the functions of ingest.php
 * (handoff 060). Fixed seed; no second copy of the rules: the properties compare the implementations with each other, or state what the spec says in terms of the
 * generated value (a binary value of n bytes has n pairs in its hex form; a text written with quotes and backslashes reads back as itself; a loopback address is
 * never the management address; ...).
 *
 * Properties: same result everywhere (native step = push.py = ingest), stable (a normal form normalizes to itself), no byte lost, classes, no crash.
 *
 * A failing property that the task reports as a question is in $known (key => question): it is printed as KNOWN and does not fail the run; when it passes
 * again the run says so and fails, so the entry is removed.
 *
 * Usage: test_generated.php <PDO DSN> <database user> [password] [--topo-step=PATH]
 * Expects a THROWAWAY database (ingest.php connects to it). Never point it at a real Zabbix database.
 */

const SEED = 20261023;
const COUNT_TEXT = 140;
const COUNT_BINARY = 90;
const COUNT_ADDR = 140;
const COUNT_MAC = 60;

$options = getopt('', ['topo-step::']);
$topo_step = $options['topo-step'] ?? __DIR__.'/step_golden/topo_step';
$args = array_values(array_filter(array_slice($argv, 1), static fn (string $a): bool => !str_starts_with($a, '--')));
if (count($args) < 2 || count($args) > 3) {
	fwrite(STDERR, "Usage: {$argv[0]} <PDO DSN> <database user> [password] [--topo-step=PATH]\n");
	exit(1);
}
if (!is_executable($topo_step)) {
	fwrite(STDERR, "topo_step not found at {$topo_step} — run step_golden/build.sh.\n");
	exit(1);
}
$pdo = new PDO($args[0], $args[1], $args[2] ?? '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
foreach (['topo_identity_keys', 'topo_observations', 'topo_edges', 'topo_nodes', 'topo_lld_snapshot', 'items', 'proxy', 'hosts'] as $table) {
	$pdo->exec("DROP TABLE IF EXISTS {$table}");
}
$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
$pdo->exec('CREATE TABLE hosts (hostid BIGINT UNSIGNED PRIMARY KEY, host VARCHAR(128) NOT NULL DEFAULT \'\')');
$pdo->exec('CREATE TABLE proxy (proxyid BIGINT UNSIGNED PRIMARY KEY)');
$pdo->exec('CREATE TABLE items (itemid BIGINT UNSIGNED PRIMARY KEY)');
$pdo->exec(file_get_contents(__DIR__.'/../mysql_schema.sql'));

putenv('ZABBIX_API_URL=http://unused.invalid');
putenv('ZABBIX_API_TOKEN=unused');
putenv('TOPOLOGY_PDO_DSN='.$args[0]);
putenv('TOPOLOGY_PDO_USER='.$args[1]);
putenv('TOPOLOGY_PDO_PASSWORD='.($args[2] ?? ''));

$checks = 0;
$failures = 0;
$known_seen = [];
$known_hit = [];

/** Known findings (questions of report 060): property key => the question. */
$known = [];

function check(bool $ok, string $what, $detail = null, ?string $key = null): void {
	global $checks, $failures, $known, $known_hit;

	$checks++;
	if (!$ok && $key !== null && isset($known[$key])) {
		$known_hit[$key] = true;
		echo "  KNOWN ({$known[$key]}): {$what}".($detail !== null ? "\n        ".(is_string($detail) ? $detail : json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)) : '')."\n";
		return;
	}
	$failures += $ok ? 0 : 1;
	echo ($ok ? '  ok: ' : '  FAIL: ').$what.(!$ok && $detail !== null ? "\n        ".(is_string($detail) ? $detail : json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)) : '')."\n";
}

/** A property over many values: one line, the smallest failing value (shortest input) and the number of failures. */
function property(string $key, string $what, array $failing, int $total): void {
	if (!$failing) {
		check(true, "{$what} ({$total} values)");
		return;
	}
	usort($failing, static fn (array $a, array $b): int => strlen((string) $a['in']) <=> strlen((string) $b['in']));
	$smallest = $failing[0];
	$more = array_map(static fn (array $f): string => json_encode((string) $f['in'], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), array_slice($failing, 1, getenv('GENERATED_SHOW') ? 40 : 3));
	check(false, "{$what}: ".count($failing)." of {$total} values fail; smallest: ".bin2hex((string) $smallest['in'])." = ".json_encode((string) $smallest['in'], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)
		.($more ? '; next: '.implode(' ', $more) : ''), $smallest['got'] ?? null, $key);
}

// ---- generators (fixed seed) ----
mt_srand(SEED);

function pick(array $list) {
	return $list[mt_rand(0, count($list) - 1)];
}

function fill_bytes(int $n, bool $printable): string {
	$out = '';
	for ($i = 0; $i < $n; $i++) {
		$out .= $printable ? chr(mt_rand(0x21, 0x7e)) : chr(pick([mt_rand(0x01, 0x1f), mt_rand(0x80, 0xff), 0x7f]));
	}
	return $out;
}

/** Binary values of 1-8 bytes with 00 at the start, inside and at the end; printable and not printable bytes. */
function gen_binary(int $extra): array {
	$values = [];
	for ($len = 1; $len <= 8; $len++) {
		foreach (['start', 'inside', 'end', 'none', 'end2'] as $where) {
			foreach ([true, false] as $printable) {
				$raw = fill_bytes($len, $printable);
				if ($where === 'start') {
					$raw[0] = "\0";
				}
				elseif ($where === 'inside' && $len >= 3) {
					$raw[intdiv($len, 2)] = "\0";
				}
				elseif ($where === 'end') {
					$raw[$len - 1] = "\0";
				}
				elseif ($where === 'end2' && $len >= 2) {
					$raw[$len - 1] = $raw[$len - 2] = "\0";
				}
				$values[$raw] = true;
			}
		}
	}
	// the MACs that SW12 and the HP 2530 send as a CDP device id
	foreach (['a852d4531100', 'f40343e8ba00', '000000000000', 'fffefdfcfb00'] as $hex) {
		$values[hex2bin($hex)] = true;
	}
	for ($i = 0; $i < $extra; $i++) {
		$raw = fill_bytes(mt_rand(1, 8), (bool) mt_rand(0, 1));
		$raw[mt_rand(0, strlen($raw) - 1)] = "\0";
		$values[$raw] = true;
	}
	return array_map('strval', array_keys($values));
}

/** Text with trailing NULs, quotes, white space, control characters and invalid UTF-8. */
function gen_text(int $count): array {
	$bases = ['Gi0/1', 'tengigabitethernet1/1/1', 'SW-A', 'Uplink x', "\u{e4}\u{20ac}", 'port 1', 'N9K-1(FDO2101X0AB)', 'a', 'eth0', '1/1/1'];
	$values = [];
	for ($i = 0; $i < $count; $i++) {
		$text = pick($bases);
		switch (mt_rand(0, 9)) {
			case 0:
				$text .= str_repeat("\0", mt_rand(1, 3));
				break;
			case 1:
				[$l, $r] = pick([['"', '"'], ["'", "'"], ["\u{201c}", "\u{201d}"], ['"', "'"]]);
				$text = $l.$text.$r;
				break;
			case 2:
				$text = pick([' ', "\t", "\n", "\r", '  ']).$text.pick([' ', "\t", "\n", '  ']);
				break;
			case 3:
				$text = substr($text, 0, 1).pick(["\x01", "\x1f", "\x7f", "\xc2\x85", "\x0a", "\x09"]).substr($text, 1);
				break;
			case 4:
				$text .= pick(["\x80", "\xc3", "\xe2\x82", "\xf0\x9f", "\xff", "\xc0\xaf"]);
				break;
			case 5:
				$text = pick(["\x80", "\xff"]).$text;
				break;
			case 6:
				$text = '"'.$text."\0".'"';
				break;
			case 7:
				$text = '""'.$text.'""';
				break;
			case 8:
				$text = "\u{201c}".'"'.$text.'"'."\u{201d}";
				break;
		}
		$values[$text] = true;
	}
	// fixed shapes of the field cases
	foreach (["", "\0", "\0\0", ' ', '""', "''", '"', "a\0b", "\tab", 'Gi0/1 ', "\"Gi0/1\"\0"] as $text) {
		$values[$text] = true;
	}
	return array_map('strval', array_keys($values));
}

$hex_pairs = static fn (string $raw): string => implode(':', str_split(bin2hex($raw), 2));
$hexs = static fn (string $raw): string => implode(' ', str_split(strtoupper(bin2hex($raw)), 2)).' ';

// ---- walks ----
function line(string $oid, string $type, string $value): string {
	return ".{$oid} = {$type}: {$value}\n";
}

const IFX = '1.3.6.1.2.1.31.1.1.1.1';
const CDP = '1.3.6.1.4.1.9.9.23.1.2.1.1';

function ports_walk(string $extra): string {
	return line('1.3.6.1.2.1.2.2.1.1.1', 'INTEGER', '1').line('1.3.6.1.2.1.2.2.1.3.1', 'INTEGER', 'ethernetCsmacd(6)').$extra;
}

/** @param array $rem [[column, type, value]]; $man [[index tail, value]] */
function lldp_walk(array $rem, array $man = []): string {
	$w = '';
	foreach ($rem as [$col, $typ, $value]) {
		$w .= line("1.0.8802.1.1.2.1.4.1.1.{$col}.0.7.1", $typ, $value);
	}
	foreach ($man as [$tail, $value]) {
		$w .= line("1.0.8802.1.1.2.1.4.2.1.3.{$tail}", 'INTEGER', (string) $value);
	}
	return $w;
}

function cdp_walk(array $columns): string {
	$w = line(IFX.'.5', 'STRING', '"Gi0/5"');
	foreach ($columns as [$col, $typ, $value]) {
		$w .= line(CDP.".{$col}.5.1", $typ, $value);
	}
	return $w;
}

$step = static function (string $walk, string $source) use ($topo_step): ?array {
	$file = tempnam(sys_get_temp_dir(), 'walk');
	file_put_contents($file, $walk);
	$process = proc_open([$topo_step, $file, $source, 'error'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
	$out = trim(stream_get_contents($pipes[1]));
	stream_get_contents($pipes[2]);
	$code = proc_close($process);
	unlink($file);
	$rows = json_decode($out, true);

	return $code === 0 && is_array($rows) ? $rows : null;
};

$push = static function (array $cases): array {
	$process = proc_open(['python3', '-I', __DIR__.'/step_golden/push_rows.py'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
	fwrite($pipes[0], json_encode(array_values($cases), JSON_INVALID_UTF8_SUBSTITUTE));
	fclose($pipes[0]);
	$out = stream_get_contents($pipes[1]);
	$err = stream_get_contents($pipes[2]);
	proc_close($process);
	$rows = json_decode($out, true);
	if (!is_array($rows)) {
		fwrite(STDERR, "push_rows.py failed: {$err}\n");
		exit(1);
	}

	return $rows;
};

define('TOPOLOGY_INGEST_TEST_HOOK', function (array $ingest) use ($step, $push, $hex_pairs, $hexs) {
	global $checks, $failures, $known, $known_hit;

	$text_or_hex = $ingest['norm_text_or_hex'];
	$clean = $ingest['norm_clean'];
	$name_key = $ingest['norm_name_key'];
	$numeric_id = $ingest['norm_numeric_id'];
	$ip_text = $ingest['norm_ip_text'];
	$man_addrs = $ingest['norm_man_addrs'];
	$normalize_row = $ingest['snap_normalize_row'];
	$chassis_key = $ingest['chassis_key'];
	$is_placeholder = $ingest['is_placeholder_mac'];

	/** Calls a function; an exception is a failed property, never a crash of the test. */
	$safe = static function (callable $fn, ...$args): array {
		try {
			return [true, $fn(...$args)];
		}
		catch (Throwable $e) {
			return [false, get_class($e).': '.$e->getMessage()];
		}
	};

	echo 'seed '.SEED.', counts: text '.COUNT_TEXT.', binary '.COUNT_BINARY.', addresses '.COUNT_ADDR.', MAC '.COUNT_MAC."\n";

	// =====================================================================================================================================
	echo "\n=== 1. text columns as a Hex-STRING: native step = push.py = ingest (normalization §3) ===\n";
	$raws = array_values(array_unique(array_merge(gen_binary(COUNT_BINARY), gen_text(COUNT_TEXT))));
	$cases = [];     // [source, raw, walk, macro]
	foreach ($raws as $raw) {
		$cases[] = ['ports', $raw, ports_walk(line(IFX.'.1', 'Hex-STRING', $hexs($raw))), '{#IFNAME}'];
		$cases[] = ['lldp', $raw, lldp_walk([[9, 'Hex-STRING', $hexs($raw)]]), '{#REM_SYSNAME}'];
		$cases[] = ['cdp', $raw, cdp_walk([[6, 'Hex-STRING', $hexs($raw)]]), '{#REM_SYSNAME}'];
	}
	$push_in = [];
	foreach ($cases as $i => [$source, , $walk]) {
		if ($source !== 'cdp') {
			$push_in[$i] = ['source' => $source, 'walk' => $walk];
		}
	}
	$push_out = array_combine(array_keys($push_in), $push($push_in) ?: []);
	$crash = $diff_push = $diff_ingest = $lost = $lost_in_text = [];
	foreach ($cases as $i => [$source, $raw, $walk, $macro]) {
		$rows = $step($walk, $source);
		if ($rows === null || count($rows) !== 1) {
			$crash[] = ['in' => $raw, 'got' => "{$source}: native step gave no row"];
			continue;
		}
		$native = $rows[0][$macro] ?? null;
		[$ok, $ingest_value] = $safe($text_or_hex, $raw);
		if (!$ok) {
			$crash[] = ['in' => $raw, 'got' => $ingest_value];
			continue;
		}
		$ingest_value = $ingest_value === '' ? null : $ingest_value;
		if ($native !== $ingest_value) {
			$diff_ingest[] = ['in' => $raw, 'got' => "{$source}: native ".json_encode($native, JSON_INVALID_UTF8_SUBSTITUTE).' ingest '.json_encode($ingest_value, JSON_INVALID_UTF8_SUBSTITUTE)];
		}
		if (isset($push_out[$i])) {
			$p = $push_out[$i][0][$macro] ?? null;
			if ($p !== $native) {
				$diff_push[] = ['in' => $raw, 'got' => "{$source}: native ".json_encode($native, JSON_INVALID_UTF8_SUBSTITUTE).' push.py '.json_encode($p, JSON_INVALID_UTF8_SUBSTITUTE)];
			}
		}
		// no byte lost: a value that is not text is the hex form of ALL bytes
		if ($native !== null && $native !== rtrim($raw, "\0") && $native !== $hex_pairs($raw)) {
			$lost[] = ['in' => $raw, 'got' => "{$source}: ".json_encode($native, JSON_INVALID_UTF8_SUBSTITUTE)];
		}
		// the reverse: a hex form of the bytes of a value that is valid text without control characters
		if ($native === $hex_pairs($raw) && $raw === rtrim($raw, "\0") && preg_match('//u', $raw) === 1 && !preg_match('/[\x00-\x08\x0A-\x1F\x7F]|\xC2[\x80-\x9F]/', $raw)) {
			$lost_in_text[] = ['in' => $raw, 'got' => "{$source}: valid text came as hex"];
		}
	}
	property('crash.hex', 'no crash: every value gives a result', $crash, count($cases));
	property('same.step_ingest', 'same result: the native step and ingest give the same text or hex form', $diff_ingest, count($cases));
	property('same.step_push', 'same result: the native step and push.py give the same text or hex form', $diff_push, count($push_in));
	property('bytes.lost', 'no byte lost: a value that is not text is the hex form of all its bytes (trailing 00 included)', $lost, count($cases));
	property('bytes.text', 'a valid text without control characters is text, not hex', $lost_in_text, count($cases));

	// =====================================================================================================================================
	echo "\n=== 2. text columns as a STRING: written with quotes and backslashes, read back as itself ===\n";
	$texts = array_values(array_filter(gen_text(COUNT_TEXT), static fn (string $t): bool => $t !== '' && !str_contains($t, "\0") && preg_match('//u', $t) === 1));
	$cases = [];
	foreach ($texts as $text) {
		$escaped = str_replace(['\\', '"'], ['\\\\', '\\"'], $text);
		$cases[] = [$text, ports_walk(line(IFX.'.1', 'STRING', '"'.$escaped.'"'))];
	}
	$push_out = $push(array_map(static fn (array $c): array => ['source' => 'ports', 'walk' => $c[1]], $cases));
	$bad_native = $bad_push = $bad_same = [];
	foreach ($cases as $i => [$text, $walk]) {
		$rows = $step($walk, 'ports');
		$native = $rows[0]['{#IFNAME}'] ?? null;
		$p = $push_out[$i][0]['{#IFNAME}'] ?? null;
		if ($native !== $text) {
			$bad_native[] = ['in' => $text, 'got' => 'native '.json_encode($native, JSON_UNESCAPED_UNICODE)];
		}
		if ($p !== $text) {
			$bad_push[] = ['in' => $text, 'got' => 'push.py '.json_encode($p, JSON_UNESCAPED_UNICODE)];
		}
		if ($native !== $p) {
			$bad_same[] = ['in' => $text, 'got' => 'native '.json_encode($native, JSON_UNESCAPED_UNICODE).' push.py '.json_encode($p, JSON_UNESCAPED_UNICODE)];
		}
	}
	property('string.native', 'native step: a STRING reads back as the text written (§3: unescape once)', $bad_native, count($cases));
	property('string.push', 'push.py: a STRING reads back as the text written (§3: unescape once)', $bad_push, count($cases));
	property('string.same', 'same result: native step and push.py', $bad_same, count($cases));

	// =====================================================================================================================================
	echo "\n=== 3. binary columns: the hex form of all bytes, native step = push.py ===\n";
	$binary = array_values(array_filter(gen_binary(COUNT_BINARY), static fn (string $b): bool => strlen($b) >= 1));
	$cases = [];
	foreach ($binary as $raw) {
		$cases[] = [$raw, 'lldp', lldp_walk([[4, 'INTEGER', 'macAddress(4)'], [5, 'Hex-STRING', $hexs($raw)], [9, 'STRING', '"peer"']]), '{#REM_CHASSIS}'];
		$cases[] = [$raw, 'ports', ports_walk(line('1.3.6.1.2.1.2.2.1.6.1', 'Hex-STRING', $hexs($raw))), '{#IFMAC}'];
	}
	$push_out = $push(array_map(static fn (array $c): array => ['source' => $c[1], 'walk' => $c[2]], $cases));
	$bad_len = $bad_same = $crash = [];
	foreach ($cases as $i => [$raw, $source, $walk, $macro]) {
		$rows = $step($walk, $source);
		if ($rows === null || count($rows) !== 1) {
			$crash[] = ['in' => $raw, 'got' => "{$source}: no row"];
			continue;
		}
		$native = $rows[0][$macro] ?? null;
		$p = $push_out[$i][0][$macro] ?? null;
		if ($native !== $hex_pairs($raw)) {
			$bad_len[] = ['in' => $raw, 'got' => "{$source} {$macro}: ".json_encode($native)];
		}
		if ($native !== $p) {
			$bad_same[] = ['in' => $raw, 'got' => "{$source}: native ".json_encode($native).' push.py '.json_encode($p)];
		}
	}
	property('crash.binary', 'no crash: every value gives a row', $crash, count($cases));
	property('binary.bytes', 'no byte lost: the hex form of n bytes has n pairs (a macAddress chassis id, ifPhysAddress)', $bad_len, count($cases));
	property('binary.same', 'same result: native step and push.py', $bad_same, count($cases));

	// =====================================================================================================================================
	echo "\n=== 4. addresses: lldpRemManAddr entries and CDP addresses; native step = push.py; ingest classes (§4.3, §4.4) ===\n";
	$v4 = ['0.0.0.0', '127.0.0.1', '127.255.255.254', '169.254.1.1', '10.0.0.9', '192.168.88.1', '172.16.5.5', '8.8.8.8', '224.0.0.251', '239.1.2.3', '255.255.255.255', '1.2.3.4'];
	$v6 = ['::', '::1', 'fe80::1', 'fe80::6ad1:54ff:fe69:9fd0', '2001:db8::1', '2001:4860:4860::8888', 'ff02::1', 'fd00::5', '::ffff:10.0.0.1', '::10.0.0.1', '2001:db8:0:0:1:0:0:1'];
	$octets4 = static fn (string $a): array => array_map('ord', str_split(inet_pton($a)));
	$entries_lldp = static function () use ($v4, $v6, $octets4): array {
		$kind = mt_rand(0, 9);
		if ($kind <= 3) {
			$a = $octets4(pick($v4));
			$family = 1;
		}
		elseif ($kind <= 6) {
			$a = $octets4(pick($v6));
			$family = 2;
		}
		elseif ($kind === 7) {
			$a = $octets4(pick($v4));
			$family = pick([0, 3, 6, 255]);                      // unknown families
		}
		elseif ($kind === 8) {
			$a = array_slice($octets4(pick($v4)), 0, mt_rand(1, 3));  // a short address
			$family = 1;
		}
		else {
			$a = array_merge($octets4(pick($v4)), [mt_rand(0, 255)]);
			$family = 1;
		}
		$with_length = (bool) mt_rand(0, 1);
		$length = pick([count($a), count($a), count($a) + 1, 0, 255]);
		return [$family, $a, $with_length, $length];
	};
	$cases = [];
	for ($i = 0; $i < COUNT_ADDR; $i++) {
		$man = [];
		for ($k = mt_rand(1, 3); $k > 0; $k--) {
			[$family, $a, $with_length, $length] = $entries_lldp();
			$man[] = ['0.7.1.'.$family.'.'.($with_length ? $length.'.' : '').implode('.', $a), 2];
		}
		$cases[] = ['lldp', lldp_walk([[5, 'Hex-STRING', 'AA BB CC 00 00 01 '], [9, 'STRING', '"peer"']], $man)];
	}
	foreach ($v4 as $a) {
		foreach ([1, 20, 65535] as $type) {
			$cases[] = ['cdp', cdp_walk([[3, 'INTEGER', (string) $type], [4, 'Hex-STRING', $hexs(inet_pton($a))], [6, 'STRING', '"sw"']])];
		}
	}
	foreach ($v6 as $a) {
		foreach ([1, 20] as $type) {
			$cases[] = ['cdp', cdp_walk([[3, 'INTEGER', (string) $type], [4, 'Hex-STRING', $hexs(inet_pton($a))], [6, 'STRING', '"sw"']])];
		}
	}
	foreach ([1, 2, 3, 5, 6, 7, 16, 17] as $n) {       // CDP addresses of type 1 with other lengths
		$cases[] = ['cdp', cdp_walk([[3, 'INTEGER', '1'], [4, 'Hex-STRING', $hexs(str_repeat("\x0a", $n))], [6, 'STRING', '"sw"']])];
	}
	$push_idx = [];
	foreach ($cases as $i => [$source, $walk]) {
		if ($source === 'lldp') {
			$push_idx[$i] = ['source' => 'lldp', 'walk' => $walk];
		}
	}
	$push_out = array_combine(array_keys($push_idx), $push($push_idx) ?: []);
	$is_hidden = static function (string $address): bool {
		$b = inet_pton($address);
		if ($b === false) {
			return false;
		}
		if (strlen($b) === 4) {
			return $b === "\0\0\0\0" || $b[0] === "\x7f" || (ord($b[0]) >= 224 && ord($b[0]) <= 239);
		}
		return $b === str_repeat("\0", 16) || $b === str_repeat("\0", 15)."\x01" || $b[0] === "\xff";
	};
	$crash = $diff_push = $hidden_chosen = $not_stable = $not_address = [];
	$entries_seen = 0;
	foreach ($cases as $i => [$source, $walk]) {
		$rows = $step($walk, $source);
		if ($rows === null || count($rows) !== 1) {
			$crash[] = ['in' => $walk, 'got' => "{$source}: no row"];
			continue;
		}
		$raw = $rows[0]['{#REM_MAN_ADDRS}'] ?? null;
		if (isset($push_out[$i])) {
			$p = $push_out[$i][0]['{#REM_MAN_ADDRS}'] ?? null;
			if ($p !== $raw) {
				$diff_push[] = ['in' => (string) $raw, 'got' => 'native '.json_encode($raw).' push.py '.json_encode($p)];
			}
		}
		if ($raw === null) {
			continue;
		}
		$entries_seen++;
		[$ok, $management] = $safe($man_addrs, $raw);
		if (!$ok) {
			$crash[] = ['in' => $raw, 'got' => $management];
			continue;
		}
		if ($management === null) {
			continue;
		}
		if (inet_pton($management) === false) {
			$not_address[] = ['in' => $raw, 'got' => $management];
		}
		if ($is_hidden($management)) {
			$hidden_chosen[] = ['in' => $raw, 'got' => $management];
		}
		[$ok2, $again] = $safe($ip_text, $management);
		if (!$ok2 || $again !== $management) {
			$not_stable[] = ['in' => $management, 'got' => $again];
		}
	}
	property('crash.addr', 'no crash: every entry list gives an address or none', $crash, count($cases));
	property('addr.same', 'same result: native step and push.py give the same raw entries', $diff_push, count($push_idx));
	property('addr.hidden', 'classes: an unspecified, loopback or multicast address is never the management address', $hidden_chosen, $entries_seen);
	property('addr.valid', 'the management address is an address', $not_address, $entries_seen);
	property('addr.stable', 'stable: the text of the management address normalizes to itself (§4.3)', $not_stable, $entries_seen);

	// address text in every spelling through ip_text: idempotent, no crash
	$spellings = array_merge($v4, $v6, ['010.0.0.1', '1.2.3', '1.2.3.4.5', '256.1.1.1', '1.2.3.04', ' 10.0.0.1', '10.0.0.1 ', 'FE80::1', '2001:DB8:0:0:0:0:0:1', '2001:db8::', '::ffff:0:0', '::ffff:7f00:1', '1::2::3', 'g::1', '', '[::1]', '::1%eth0', '0:0:0:0:0:0:0:0', '1:2:3:4:5:6:7:8:9']);
	$bad = $badcrash = [];
	foreach ($spellings as $text) {
		[$ok, $n] = $safe($ip_text, $text);
		if (!$ok) {
			$badcrash[] = ['in' => $text, 'got' => $n];
			continue;
		}
		if ($n !== null) {
			[$ok2, $again] = $safe($ip_text, $n);
			if (!$ok2 || $again !== $n) {
				$bad[] = ['in' => $text, 'got' => "{$n} -> ".json_encode($again)];
			}
		}
	}
	property('ip.crash', 'no crash: an address text gives an address or none', $badcrash, count($spellings));
	property('ip.stable', 'stable: ip_text(ip_text(x)) = ip_text(x)', $bad, count($spellings));

	// =====================================================================================================================================
	echo "\n=== 5. MAC (§4.1, §4.2, §5): accepted forms, placeholders, near-MACs ===\n";
	$forms = static function (string $six): array {
		$h = str_split(bin2hex($six), 2);
		return [
			'colon' => implode(':', $h), 'COLON' => strtoupper(implode(':', $h)), 'dash' => implode('-', $h), 'DASH' => strtoupper(implode('-', $h)),
			'cisco' => implode('.', str_split(bin2hex($six), 4)), 'CISCO' => strtoupper(implode('.', str_split(bin2hex($six), 4))),
		];
	};
	$macs = [];
	for ($i = 0; $i < COUNT_MAC; $i++) {
		$macs[] = random_bytes_seeded(6);
	}
	$macs = array_merge($macs, ["\0\0\0\0\0\0", str_repeat("\xff", 6), "\x00\x1a\x2b\x3c\x4d\x5e", "\x02\x00\x00\x00\x00\x0a"]);
	$accepted = $near = [];
	foreach ($macs as $six) {
		foreach ($forms($six) as $form => $text) {
			$accepted[] = [$text, implode(':', str_split(bin2hex($six), 2))];
		}
	}
	for ($i = 0; $i < COUNT_MAC; $i++) {
		$h = str_split(bin2hex(random_bytes_seeded(7)), 2);
		$near[] = ['5 octets', implode(':', array_slice($h, 0, 5))];
		$near[] = ['7 octets', implode(':', $h)];
		$near[] = ['5 octets dash', implode('-', array_slice($h, 0, 5))];
		$near[] = ['7 octets dash', implode('-', $h)];
		$near[] = ['underscore', implode('_', array_slice($h, 0, 6))];
		$near[] = ['space', implode(' ', array_slice($h, 0, 6))];
		$near[] = ['mixed', implode(':', array_slice($h, 0, 3)).'-'.implode('-', array_slice($h, 3, 3))];
		$near[] = ['cisco 2 groups', substr(implode('', array_slice($h, 0, 4)), 0, 4).'.'.substr(implode('', array_slice($h, 0, 4)), 4, 4)];
		$near[] = ['cisco 4 groups', implode('.', str_split(bin2hex(random_bytes_seeded(8)), 4))];
		$near[] = ['non hex', 'gg:'.implode(':', array_slice($h, 1, 5))];
		// 6 groups of 1 or 2 hex digits separated by ':' are a MAC (0.12): accepted, with a leading 0 for a group of one digit
		$mixed = array_map(static fn (string $o): string => mt_rand(0, 1) && $o[0] === '0' ? $o[1] : $o, array_slice($h, 0, 6));
		$accepted[] = [implode(':', $mixed), implode(':', array_slice($h, 0, 6))];
	}
	$bad_form = $bad_stable = $bad_key = $crash = $bad_near = $bad_near_key = [];
	foreach ($accepted as [$text, $normal]) {
		$row = ['rem_chassis' => $text, 'rem_chassis_type' => 'macAddress', 'rem_sysname' => 'x', 'mac' => $text, 'loc_chassis' => $text];
		[$ok, $result] = $safe($normalize_row, $row);
		if (!$ok) {
			$crash[] = ['in' => $text, 'got' => $result];
			continue;
		}
		[$got_row, $problem] = $result;
		if ($got_row === null || ($got_row['rem_chassis'] ?? null) !== $normal || ($got_row['mac'] ?? null) !== $normal) {
			$bad_form[] = ['in' => $text, 'got' => json_encode([$got_row['rem_chassis'] ?? null, $got_row['mac'] ?? null, $problem])];
			continue;
		}
		[$second] = $normalize_row($got_row);
		if ($second !== null && (($second['rem_chassis'] ?? null) !== $normal || ($second['mac'] ?? null) !== $normal)) {
			$bad_stable[] = ['in' => $text, 'got' => json_encode($second)];
		}
		$key = $chassis_key($got_row['rem_chassis'], 'macAddress', 'seg');
		$want = in_array($normal, ['00:00:00:00:00:00', 'ff:ff:ff:ff:ff:ff'], true) ? null : 'chassis:mac:'.$normal;
		if ($key !== $want || $is_placeholder($normal) !== ($want === null)) {
			$bad_key[] = ['in' => $text, 'got' => json_encode([$key, $want])];
		}
	}
	foreach ($near as [$label, $text]) {
		$row = ['rem_chassis' => $text, 'rem_chassis_type' => 'macAddress', 'rem_sysname' => 'x'];
		[$ok, $result] = $safe($normalize_row, $row);
		if (!$ok) {
			$crash[] = ['in' => $text, 'got' => $result];
			continue;
		}
		[$got_row] = $result;
		if ($got_row !== null && preg_match('/^([0-9a-f]{2}:){5}[0-9a-f]{2}$/', (string) ($got_row['rem_chassis'] ?? '')) === 1) {
			$bad_near[] = ['in' => $text, 'got' => "{$label}: became a MAC ".$got_row['rem_chassis']];
		}
		if ($got_row !== null && ($key = $chassis_key($got_row['rem_chassis'] ?? null, 'macAddress', 'seg')) !== null && str_starts_with($key, 'chassis:mac:')) {
			$bad_near_key[] = ['in' => $text, 'got' => "{$label}: {$key}"];
		}
	}
	property('crash.mac', 'no crash: every MAC text gives a row or "invalid row"', $crash, count($accepted) + count($near));
	property('mac.forms', 'a MAC in every accepted form (colon, dash, Cisco dotted, any case) has the normal form', $bad_form, count($accepted));
	property('mac.stable', 'stable: the normal form of a MAC normalizes to itself', $bad_stable, count($accepted));
	property('mac.placeholder', 'classes: a placeholder MAC never gives a chassis key, any other MAC gives chassis:mac:<normal form>', $bad_key, count($accepted));
	property('mac.near', 'classes: a near-MAC (5 or 7 octets, a wrong separator, a wrong shape) is never a MAC', $bad_near, count($near));
	property('mac.near_key', 'classes: a near-MAC never gives a chassis:mac: key', $bad_near_key, count($near));

	// =====================================================================================================================================
	echo "\n=== 6. names (§4.5), name key and the forms table (§4.6), numeric ids (§4.7) ===\n";
	$texts = gen_text(COUNT_TEXT);
	$crash = $unstable_clean = $unstable_key = $unstable_row = [];
	foreach ($texts as $text) {
		[$ok, $c] = $safe($clean, $text);
		if (!$ok) {
			$crash[] = ['in' => $text, 'got' => $c];
			continue;
		}
		if ($c !== null) {
			[, $c2] = $safe($clean, $c);
			if ($c2 !== $c) {
				$unstable_clean[] = ['in' => $text, 'got' => json_encode($c)." -> ".json_encode($c2)];
			}
		}
		[$ok, $k] = $safe($name_key, $text);
		if (!$ok) {
			$crash[] = ['in' => $text, 'got' => $k];
			continue;
		}
		if ($k !== null) {
			[, $k2] = $safe($name_key, $k);
			if ($k2 !== $k) {
				$unstable_key[] = ['in' => $text, 'got' => json_encode($k)." -> ".json_encode($k2)];
			}
		}
		$row = ['rem_sysname' => $text, 'rem_port' => $text, 'rem_port_type' => 'interfaceName', 'rem_port_desc' => $text, 'name' => $text, 'descr' => $text, 'loc_port_desc' => $text];
		[$ok, $result] = $safe($normalize_row, $row);
		if (!$ok) {
			$crash[] = ['in' => $text, 'got' => $result];
			continue;
		}
		if ($result[0] !== null) {
			[$again] = $normalize_row($result[0]);
			if ($again !== $result[0]) {
				$unstable_row[] = ['in' => $text, 'got' => json_encode($result[0], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE).' -> '.json_encode($again, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)];
			}
		}
	}
	property('crash.names', 'no crash: every text gives a cleaned value, a key, or no value', $crash, count($texts));
	property('clean.stable', 'stable: a cleaned name cleans to itself (§4.5)', $unstable_clean, count($texts));
	property('key.stable', 'stable: a name key is its own key (§4.6)', $unstable_key, count($texts));
	property('row.stable', 'stable: a normalized row normalizes to itself (§4.5)', $unstable_row, count($texts));

	// the forms table: a long form and its short form give one key, with white space, upper case and leading zeros
	$forms_file = getenv('TOPOLOGY_NORMALIZATION_FORMS') ?: '/media/sf_topology-specs/normalization/port-name-forms.tsv';
	$rows = [];
	if (is_readable($forms_file)) {
		foreach (file($forms_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $l) {
			if ($l[0] !== '#' && substr_count($l, "\t") === 1) {
				$rows[] = explode("\t", $l);
			}
		}
	}
	$bad_forms = $bad_numbers = $bad_ids = [];
	$suffixes = ['0/1', '1/1/1', '1', '0/0/01', '10', '0/49', '1.5', '01'];
	foreach ($rows as [$long, $short]) {
		foreach ($suffixes as $suffix) {
			foreach ([$long.$suffix, strtoupper($long).$suffix, ucfirst($long).' '.$suffix, $long.$suffix."\0"] as $variant) {
				[$ok, $k_long] = $safe($name_key, $variant);
				[, $k_short] = $safe($name_key, $short.$suffix);
				if (!$ok || $k_long !== $short.$suffix || $k_short !== $short.$suffix) {
					$bad_forms[] = ['in' => $variant, 'got' => json_encode([$k_long, $k_short])];
				}
			}
		}
	}
	if (!$rows) {
		echo "  skip: {$forms_file} is not readable\n";
	}
	else {
		property('forms.table', 'the long and the short form of every row of port-name-forms.tsv give the short form (any case, white space, NUL)', $bad_forms, count($rows) * count($suffixes) * 4);
	}
	foreach (['1' => 1, '01' => 1, '001' => 1, '0000000007' => 7, '2147483647' => 2147483647, ' 12 ' => 12, "12\0" => 12, '"5"' => 5] as $text => $want) {
		[$ok, $n] = $safe($numeric_id, (string) $text);
		if (!$ok || $n !== $want) {
			$bad_ids[] = ['in' => (string) $text, 'got' => json_encode($n)];
		}
	}
	foreach (['0', '00', '', '-1', '+1', '1.0', '2147483648', '12345678901', '1e3', '0x10', "\u{0661}", '1 2'] as $text) {
		[$ok, $n] = $safe($numeric_id, $text);
		if (!$ok || $n !== null) {
			$bad_ids[] = ['in' => $text, 'got' => json_encode($n)];
		}
	}
	property('numeric.ids', 'numeric port ids: digits with leading zeros are the number; zero, signs, decimals, other digits and overflow are no value (§4.7)', $bad_ids, 20);

	// =====================================================================================================================================
	foreach ($known as $key => $question) {
		if (!isset($known_hit[$key])) {
			check(false, "the known finding {$key} ({$question}) passes now: remove it from \$known");
		}
	}
	echo "\n".($failures === 0 ? "OK: {$checks} checks\n" : "FAILED: {$failures} of {$checks} checks\n");
	exit($failures === 0 ? 0 : 1);
});

/** Bytes from the seeded generator (random_bytes is not seeded). */
function random_bytes_seeded(int $n): string {
	$out = '';
	for ($i = 0; $i < $n; $i++) {
		$out .= chr(mt_rand(0, 255));
	}
	return $out;
}

$argv = [$argv[0]];
require __DIR__.'/ingest.php';
