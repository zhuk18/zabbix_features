#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * measure_ingest.php — the decisions of ingest.php (local port model §6.7.6, management address normalization §4.4, cleaning) on step output of the corpus
 * (handoff 025 section 4). Input: a JSON file {walk: {ports: [rows], lldp: [rows]}} in the macro form of the step; output: per LLDP row the local port, the
 * reason and the management address. Nothing of the corpus is committed.
 *
 * Usage: measure_ingest.php <input.json> <output.json> <PDO DSN> <database user> [password]
 * Expects a THROWAWAY database (ingest.php connects to it). Never point it at a real Zabbix database.
 */

function fail_test(string $message): void {
	fwrite(STDERR, "FAIL: {$message}\n");
	exit(1);
}

$in_path = $argv[1] ?? '';
$out_path = $argv[2] ?? '';
$args = array_slice($argv, 3);
if ($in_path === '' || count($args) < 2 || count($args) > 3) {
	fwrite(STDERR, "Usage: {$argv[0]} <input.json> <output.json> <PDO DSN> <database user> [password]\n");
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

define('TOPOLOGY_INGEST_TEST_HOOK', function (array $ingest) use ($in_path, $out_path) {
	$normalize = $ingest['snap_normalize_row'];
	$resolve = $ingest['resolve_local_port'];
	$field = ['{#IFINDEX}' => 'if_index', '{#IFNAME}' => 'if_name', '{#LOC_PORT_NUM}' => 'loc_port_num', '{#LOC_PORT_ID}' => 'loc_port_id', '{#LOC_PORT_ID_TYPE}' => 'loc_port_id_type',
		'{#LOC_PORT_DESC}' => 'loc_port_desc', '{#REM_CHASSIS}' => 'rem_chassis', '{#REM_CHASSIS_TYPE}' => 'rem_chassis_type', '{#REM_PORT}' => 'rem_port', '{#REM_PORT_TYPE}' => 'rem_port_type',
		'{#REM_PORT_DESC}' => 'rem_port_desc', '{#REM_SYSNAME}' => 'rem_sysname', '{#REM_MGMT_IP}' => 'rem_mgmt_ip', '{#REM_MAN_ADDRS}' => 'rem_man_addrs', '{#SOURCE}' => 'source',
		'{#LOC_CHASSIS}' => 'loc_chassis', '{#IFDESCR}' => 'descr', '{#IFMAC}' => 'mac'];
	$map = static function (array $row, bool $ports = false) use ($field): array {
		$out = [];
		foreach ($row as $macro => $value) {
			$out[($ports && $macro === '{#IFNAME}') ? 'name' : ($field[$macro] ?? $macro)] = $value;
		}
		return $out;
	};
	$result = [];

	foreach (json_decode((string) file_get_contents($in_path), true, 512, JSON_THROW_ON_ERROR) as $name => $walk) {
		$ports = [];
		foreach ($walk['ports'] ?? [] as $row) {
			[$row] = $normalize($map($row, true));
			if ($row !== null) {
				$ports[] = array_intersect_key($row, array_flip(['if_index', 'name', 'descr', 'mac']));
			}
		}
		$out = ['rows' => [], 'invalid' => 0];

		foreach ($walk['lldp'] ?? [] as $row) {
			[$row, $problem] = $normalize($map($row));
			if ($row === null) {
				$out['invalid']++;
				continue;
			}
			[$index, $reason] = $resolve($row, $ports);
			$out['rows'][] = ['loc_port_num' => $row['loc_port_num'] ?? null, 'if_index' => $index, 'reason' => $reason, 'ip' => $row['rem_mgmt_ip'] ?? null];
		}
		$result[$name] = $out;
	}

	file_put_contents($out_path, json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
	exit(0);
});

$argv = [$argv[0]];
require __DIR__.'/../discovery/ingest.php';
