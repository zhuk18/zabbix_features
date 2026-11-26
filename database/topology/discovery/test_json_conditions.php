#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__.'/../topology_json.inc.php';

/**
 * test_json_conditions.php — task 133, topology-1-model.md §18 (2.111): a JSON value is never compared with a value of another type, so that the result is the same on MariaDB and on MySQL 8.4.
 *
 * (1) The source: the topology code has no raw `JSON_EXTRACT`, `JSON_UNQUOTE`, `JSON_CONTAINS`, `JSON_VALUE` or `->>` outside topology_json.inc.php (and its twin topology_json.py); a place that
 *     must keep one says `json-ok: <reason>` on the line or the line before. Test files, `.sql` migrations (typed, one-time) and documents are not read. This is the check that MariaDB can make:
 *     on MariaDB the raw form `JSON_EXTRACT(attrs, '$.if_index') = '5'` is TRUE, so no query on MariaDB can fail for it; the raw form is what failed on MySQL 8.4 (`findPort` made a second Port without
 *     a name), and this test fails for each of the 13 raw places that the code had before the helper.
 * (2) The helper on the database: for every kind of condition, a parameter of the wrong type (an integer as a string and the other way round) does not change the result; a float is refused;
 *     a column or a path that is not a constant of the code is refused.
 *
 * Usage: test_json_conditions.php <PDO DSN> <database user> [password]
 * Expects a THROWAWAY database.
 */

function fail_test(string $message): void {
	fwrite(STDERR, "FAIL: {$message}\n");
	exit(1);
}

function check(bool $condition, string $description, $detail = null): void {
	if (!$condition) {
		fail_test($description.($detail !== null ? ' '.json_encode($detail) : ''));
	}
	echo "  ok: {$description}\n";
}

echo "== (1) the source ==\n";
$root = realpath(__DIR__.'/../../..');
$raw = '/JSON_EXTRACT\s*\(|JSON_UNQUOTE\s*\(|JSON_CONTAINS\s*\(|JSON_VALUE\s*\(|->>/';
$skip_dir = ['/ui/vendor/', '/.git/', '/__pycache__/', '/database/topology/corpus/out/', '/database/topology/lab/run/', '/database/topology/lab/data/', '/database/topology/lab/reports/', '/lab/stands/'];
$found = [];
$read = 0;
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/database/topology', FilesystemIterator::SKIP_DOTS));
$files = [];
foreach ($iterator as $file) {
	$files[] = $file->getPathname();
}
foreach (glob($root.'/ui/include/classes/topology/*.php') as $file) {
	$files[] = $file;
}
foreach (glob($root.'/ui/app/controllers/*Topology*.php') as $file) {
	$files[] = $file;
}
foreach (glob($root.'/ui/app/views/*topology*') as $file) {
	$files[] = $file;
}
foreach (glob($root.'/ui/app/views/js/*topology*') as $file) {
	$files[] = $file;
}
foreach ($files as $path) {
	$name = basename($path);
	$skipped = false;
	foreach ($skip_dir as $part) {
		$skipped = $skipped || str_contains($path, $part);
	}
	if ($skipped || !preg_match('/\.(php|py|js|c|h|sh)$/', $name) || str_starts_with($name, 'test_') || $name === 'topology_json.inc.php' || $name === 'topology_json.py'
			|| str_contains($path, '/lab/tests/') || str_contains($path, '/step_golden/')) {
		continue;
	}
	$lines = file($path, FILE_IGNORE_NEW_LINES);
	$read++;
	foreach ($lines as $i => $line) {
		if (preg_match($raw, $line) === 1 && !str_contains($line, 'json-ok:') && !str_contains($lines[$i - 1] ?? '', 'json-ok:')) {
			$found[substr($path, strlen($root) + 1).':'.($i + 1)] = trim(substr($line, 0, 100));
		}
	}
}
check($read > 40, "{$read} source files of the topology code read");
check($found === [], 'no raw JSON comparison outside the helper (a place that keeps one says "json-ok: <reason>")', $found);

echo "== (2) the helper on the database ==\n";
$args = array_slice($argv, 1);
if (count($args) < 2 || count($args) > 3) {
	fwrite(STDERR, "Usage: {$argv[0]} <PDO DSN> <database user> [password]\n");
	exit(1);
}
$pdo = new PDO($args[0], $args[1], $args[2] ?? '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
foreach (['topo_identity_keys', 'topo_observations', 'topo_edges', 'topo_nodes', 'topo_lld_snapshot', 'host_inventory', 'interface', 'items', 'proxy', 'hosts'] as $table) {
	$pdo->exec("DROP TABLE IF EXISTS {$table}");
}
$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
$pdo->exec('CREATE TABLE hosts (hostid BIGINT UNSIGNED PRIMARY KEY, host VARCHAR(128) NOT NULL)');
$pdo->exec('CREATE TABLE proxy (proxyid BIGINT UNSIGNED PRIMARY KEY)');
$pdo->exec('CREATE TABLE items (itemid BIGINT UNSIGNED PRIMARY KEY)');
$pdo->exec(file_get_contents(__DIR__.'/../mysql_schema.sql'));
$insert = $pdo->prepare('INSERT INTO topo_nodes (type, attrs, created_at, updated_at) VALUES (?, ?, 1, 1)');
foreach ([
	['port', ['if_index' => 5, 'pseudo' => false, 'name' => 'Gi0/5']],
	['port', ['if_index' => 12, 'pseudo' => false, 'name' => '12']],
	['port', ['if_index' => -7, 'pseudo' => true, 'name' => 'eth0*']],
	['port', ['pseudo' => false, 'name' => 'no-index']],
	['device', ['sysname' => 'SW1', 'mgmt_ip' => '10.0.0.1', 'chassis_id' => 'aa:bb:cc:00:00:01']],
	['device', ['sysname' => '5', 'mac' => null]],
] as [$type, $attrs]) {
	$insert->execute([$type, json_encode($attrs)]);
}
$ids = static function (string $condition, array $params = []) use ($pdo): array {
	$stmt = $pdo->prepare('SELECT id FROM topo_nodes WHERE '.$condition.' ORDER BY id');
	$stmt->execute($params);

	return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
};
$by = static function (string $column, string $path, $value) use ($ids): array {
	[$sql, $params] = TopologyJson::equals($column, $path, $value);

	return $ids($sql, $params);
};
check($by('attrs', '$.if_index', 5) === [1], 'a number as an integer finds the Port');
check($by('attrs', '$.if_index', '5') === [1], 'the same number as a string finds the same Port (the type of the parameter does not change the result)');
check($ids(TopologyJson::number('attrs', '$.if_index').' = ?', ['5']) === [1] && $ids(TopologyJson::number('attrs', '$.if_index').' = ?', [5]) === [1], 'the number condition with a string and with an integer parameter: the same Port');
check($ids(TopologyJson::number('attrs', '$.if_index').' = ?', ['-7']) === [3] && $ids(TopologyJson::number('attrs', '$.if_index').' > 0') === [1, 2], 'a negative number and "> 0"');
check($ids(TopologyJson::number('attrs', '$.if_index').' = ?', ['999']) === [] && $ids(TopologyJson::number('attrs', '$.if_index').' = ?', [0]) === [], 'a missing key and a missing value are no match (a Port without if_index is not if_index 0)');
check($by('attrs', '$.name', '12') === [2] && $by('attrs', '$.name', 'Gi0/5') === [1], 'a text: a string compared as an unquoted string (the name "12" is a string, not the number 12)');
check($by('attrs', '$.sysname', '5') === [6] && $by('attrs', '$.if_index', 12) === [2], 'a string "5" and a number 5 in two keys do not mix');
check($by('attrs', '$.pseudo', true) === [3] && $by('attrs', '$.pseudo', false) === [1, 2, 4], 'a boolean: true and false (a missing key is neither)');
check($ids(TopologyJson::isFalse('attrs', '$.pseudo').' AND type = ?', ['port']) === [1, 2, 4] && $ids(TopologyJson::isTrue('attrs', '$.pseudo')) === [3], 'isTrue and isFalse');
check($by('attrs', '$.if_index', null) === [4, 5, 6] && $ids(TopologyJson::exists('attrs', '$.if_index')) === [1, 2, 3], 'null is a missing key; exists is the opposite');
check($ids(TopologyJson::exists('attrs', '$.mac')) === [6], 'a JSON null counts as present (it is not a missing key)');
foreach ([1.5, [], new stdClass()] as $wrong) {
	$refused = false;
	try {
		TopologyJson::equals('attrs', '$.if_index', $wrong);
	}
	catch (InvalidArgumentException $exception) {
		$refused = true;
	}
	check($refused, 'a value of the type '.gettype($wrong).' is refused');
}
foreach ([['attrs; DROP', '$.a'], ['attrs', '$.a\') OR (1=1'], ['attrs', '$..a'], ['attrs', 'a'], ['At', '$.a']] as [$column, $path]) {
	$refused = false;
	try {
		TopologyJson::text($column, $path);
	}
	catch (InvalidArgumentException $exception) {
		$refused = true;
	}
	check($refused, 'a column or a path that is not a constant of the code is refused: '.$column.' '.$path);
}
echo "OK: all checks passed\n";
