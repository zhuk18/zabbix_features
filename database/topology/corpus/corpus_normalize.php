<?php
/**
 * corpus_normalize.php — the PHP ingest normalization (ingest.php $snap_normalize_row, model §5.6) on rows given as JSON.
 *
 *   php corpus_normalize.php <PDO DSN> <db user> [password] < rows.json > result.json
 *
 * stdin: a JSON list of snapshot rows (field names of the server contract, e.g. if_index, rem_chassis). stdout: a JSON list of [row, null] or
 * [null, "field: why"], one per input row. It includes ingest.php with the test hook, so it needs a (throwaway) database for the connection.
 */
$args = array_slice($argv, 1);
putenv('ZABBIX_API_URL=http://unused.invalid');
putenv('ZABBIX_API_TOKEN=unused');
putenv('TOPOLOGY_PDO_DSN='.$args[0]);
putenv('TOPOLOGY_PDO_USER='.$args[1]);
putenv('TOPOLOGY_PDO_PASSWORD='.($args[2] ?? ''));
$rows = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
define('TOPOLOGY_INGEST_TEST_HOOK', function (array $ingest) use ($rows) {
	$normalize = $ingest['snap_normalize_row'];
	$out = [];
	foreach ($rows as $row) {
		$out[] = is_array($row) ? $normalize($row) : [null, 'not an object'];
	}
	echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), "\n";
});
$argv = [$argv[0]];
require __DIR__.'/../discovery/ingest.php';
