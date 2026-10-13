#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * test_normalization.php — the normalization functions of ingest.php (topology-6-normalization.md §3-§4) against normalization_cases.json, the case file
 * that the native step and push.py run too (handoff 025). Reaches the closures through TOPOLOGY_INGEST_TEST_HOOK.
 *
 * Usage: test_normalization.php <PDO DSN> <database user> [password]
 * Expects a THROWAWAY database (ingest.php connects to it). Never point it at a real Zabbix database.
 */

function fail_test(string $message): void {
	fwrite(STDERR, "FAIL: {$message}\n");
	exit(1);
}

$args = array_slice($argv, 1);
if (count($args) < 2 || count($args) > 3) {
	fwrite(STDERR, "Usage: {$argv[0]} <PDO DSN> <database user> [password]\n");
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

function check(bool $ok, string $what, $detail = null): void {
	global $checks, $failures;

	$checks++;
	$failures += $ok ? 0 : 1;
	echo ($ok ? '  ok: ' : '  FAIL: ').$what.(!$ok && $detail !== null ? "\n        ".(is_string($detail) ? $detail : json_encode($detail, JSON_UNESCAPED_UNICODE)) : '')."\n";
}

define('TOPOLOGY_INGEST_TEST_HOOK', function (array $ingest) {
	global $checks, $failures;

	$cases = json_decode((string) file_get_contents(__DIR__.'/normalization_cases.json'), true, 512, JSON_THROW_ON_ERROR);
	$functions = [
		'text_or_hex' => 'norm_text_or_hex', 'clean' => 'norm_clean', 'name_key' => 'norm_name_key', 'numeric_id' => 'norm_numeric_id',
		'port_mac' => 'norm_port_mac', 'ip_text' => 'norm_ip_text', 'man_addrs' => 'norm_man_addrs', 'row' => 'snap_normalize_row', 'local_port' => 'resolve_local_port',
	];

	foreach ($cases as $group => $list) {
		echo "\n=== {$group} ===\n";
		$fn = $ingest[$functions[$group]] ?? null;

		foreach ($list as $case) {
			$in = $case['in'];

			if ($fn === null) {
				check(false, "{$group} ".json_encode($in, JSON_UNESCAPED_UNICODE), "ingest.php has no {$functions[$group]}");
				continue;
			}

			switch ($group) {
				case 'text_or_hex':
					$got = $fn(hex2bin(str_replace(' ', '', $in)));
					break;
				case 'port_mac':
					$got = $fn($in['mac'], $in['ports']);
					break;
				case 'row':
					[$got] = $fn($in);
					break;
				case 'local_port':
					[$index, $reason] = $fn($in['row'], $in['ports']);
					$got = ['if_index' => $index, 'reason' => $reason];
					check($got === $case['out'], "{$group} {$in['name']}", 'got '.json_encode($got));
					continue 2;
				default:
					$got = $fn($in);
			}

			check($got === $case['out'], "{$group} ".json_encode($in, JSON_UNESCAPED_UNICODE).' -> '.json_encode($case['out'], JSON_UNESCAPED_UNICODE), 'got '.json_encode($got, JSON_UNESCAPED_UNICODE));
		}
	}

	echo "\n=== the code copy of port-name-forms.tsv ===\n";
	$spec = getenv('TOPOLOGY_NORMALIZATION_FORMS') ?: '/media/sf_topology-specs/normalization/port-name-forms.tsv';

	if (is_readable($spec)) {
		exec('python3 '.escapeshellarg(__DIR__.'/gen_port_name_forms.py').' --check --spec '.escapeshellarg($spec), $lines, $code);
		check($code === 0, 'the table in ingest.php is the file of the spec folder', implode("\n", $lines));
	}
	else {
		echo "  skip: {$spec} is not readable\n";
	}

	echo "\n".($failures === 0 ? "OK: {$checks} checks\n" : "FAILED: {$failures} of {$checks} checks\n");
	exit($failures === 0 ? 0 : 1);
});

$argv = [$argv[0]];
require __DIR__.'/ingest.php';
