#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * decision_tool.php — a tool for the checks, not for the operator (topology-1-model.md §6.7.8 "Separation", task 129): list the records of `topo_decision` and delete one `merge` record, so that
 * the next run finds the keys of the removed Device as their own Device again. There is no UI action for this in release 1 (FR-0 Later 11).
 *
 * Usage: decision_tool.php --pdo-dsn <dsn> --pdo-user <user> [--pdo-password <password>] list
 *        decision_tool.php ... delete-merge <key>      deletes the merge records whose key_a, key_b or one of keys_b is <key> (a natural key or any key of the removed Device)
 *        decision_tool.php ... delete <decisionid>     deletes one record of any kind
 */

$options = getopt('', ['pdo-dsn:', 'pdo-user:', 'pdo-password::'], $rest);
$args = array_slice($argv, $rest);
if (!isset($options['pdo-dsn'], $options['pdo-user']) || !$args) {
	fwrite(STDERR, "Usage: {$argv[0]} --pdo-dsn <dsn> --pdo-user <user> [--pdo-password <password>] list | delete-merge <key> | delete <decisionid>\n");
	exit(2);
}
$pdo = new PDO($options['pdo-dsn'], $options['pdo-user'], $options['pdo-password'] ?? '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$command = $args[0];
if ($command === 'list') {
	foreach ($pdo->query('SELECT decisionid, kind, key_a, key_b, hostid, proxyid, keys_b, created_at, created_by, norm_version FROM topo_decision ORDER BY decisionid')->fetchAll(PDO::FETCH_ASSOC) as $row) {
		echo json_encode($row, JSON_UNESCAPED_SLASHES), "\n";
	}
}
elseif ($command === 'delete-merge' && isset($args[1])) {
	$ids = [];
	foreach ($pdo->query("SELECT decisionid, key_a, key_b, keys_b FROM topo_decision WHERE kind = 'merge'")->fetchAll(PDO::FETCH_ASSOC) as $row) {
		$keys = array_merge([$row['key_a'], $row['key_b']], json_decode((string) $row['keys_b'], true) ?: []);
		if (in_array($args[1], $keys, true)) {
			$ids[] = (int) $row['decisionid'];
		}
	}
	foreach ($ids as $id) {
		$pdo->prepare('DELETE FROM topo_decision WHERE decisionid = ?')->execute([$id]);
	}
	echo json_encode(['deleted' => $ids]), "\n";
	exit($ids ? 0 : 1);
}
elseif ($command === 'delete' && isset($args[1])) {
	$stmt = $pdo->prepare('DELETE FROM topo_decision WHERE decisionid = ?');
	$stmt->execute([(int) $args[1]]);
	echo json_encode(['deleted' => $stmt->rowCount()]), "\n";
}
else {
	fwrite(STDERR, "unknown command\n");
	exit(2);
}
