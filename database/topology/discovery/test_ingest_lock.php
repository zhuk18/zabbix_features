#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * test_ingest_lock.php — task 087, topology-1-model.md §6.1 (2.65): the run lock is the row of the table topo_run (one conditional UPDATE, a heartbeat, a timeout), so two runs
 * exclude each other even when their temp dirs differ (PHP-FPM has a private /tmp; the CLI does not). The CLI executes every run: `--if-requested` runs only when a request waits; a run
 * that starts takes the request.
 *
 * Usage: test_ingest_lock.php <PDO DSN> <database user> [password]
 * Needs the stand-in schema of test_snapshot_ingest.php in the database (run that first), with the table topo_run. MySQL only.
 */

[$dsn, $user, $password] = [$argv[1] ?? '', $argv[2] ?? '', $argv[3] ?? ''];

function check(bool $condition, string $description, $detail = null): void {
	if (!$condition) {
		fwrite(STDERR, "FAIL: {$description}".($detail !== null ? ' '.json_encode($detail) : '')."\n");
		exit(1);
	}
	echo "  ok: {$description}\n";
}

function start(string $dsn, string $user, string $password, string $tmp, array $env = [], array $args = []) {
	@mkdir($tmp);
	$cmd = [PHP_BINARY, __DIR__.'/ingest.php', '--pdo-dsn', $dsn, '--pdo-user', $user, '--pdo-password', $password];
	foreach ($args as $i => $a) {
		$cmd[] = $a;
	}
	$p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, ['TMPDIR' => $tmp] + $env);
	return [$p, $pipes];
}

function finish($proc): array {
	[$p, $pipes] = $proc;
	$out = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
	return ['code' => proc_close($p), 'out' => $out];
}

require_once __DIR__.'/../topology_run.inc.php';

$base = sys_get_temp_dir().'/topology-lock-test-'.getmypid();
$pdo = new PDO($dsn, $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
TopologyRunRow::ensure($pdo);
$reset = static function () use ($pdo): void {
	$pdo->exec("UPDATE topo_run SET status = 'idle', owner = '', heartbeat = 0, requested_at = 0, started_at = 0, finished_at = 0, started_by = '', run_id = '', error = NULL, summary = NULL WHERE id = 1");
};
$row = static fn (): array => TopologyRunRow::fetch($pdo);
$reset();

echo "\n=== --if-requested ===\n";
$t0 = microtime(true);
$run = finish(start($dsn, $user, $password, $base.'-cli', [], ['--if-requested']));
check($run['code'] === 0 && str_contains($run['out'], 'No run is requested') && $row()['status'] === 'idle' && (int) $row()['started_at'] === 0, 'no request: exit 0 at once, no run (the row is untouched)', [$run, $row()]);
$pdo->exec('UPDATE topo_run SET requested_at = '.(time() - 30).' WHERE id = 1');
$run = finish(start($dsn, $user, $password, $base.'-cli', [], ['--if-requested']));
$r = $row();
check(in_array($run['code'], [0, 3], true) && $r['status'] === 'done' && (int) $r['requested_at'] === 0 && $r['owner'] === '' && (int) $r['finished_at'] > 0, 'a request: the run takes it (cleared), status done, the lock is free again', $r);
check(is_array(json_decode((string) $r['summary'], true)) && $r['started_by'] === 'CLI' && $r['run_id'] !== '', 'the row has the summary, started_by and the run id');

echo "\n=== a full run takes a waiting request, a partial run does not ===\n";
$pdo->exec('UPDATE topo_run SET requested_at = '.time().' WHERE id = 1');
$run = finish(start($dsn, $user, $password, $base.'-cli', [], ['--zabbix-host', 'no-such-host']));
check((int) $row()['requested_at'] > 0, 'a run with --zabbix-host leaves the request');
$run = finish(start($dsn, $user, $password, $base.'-cli'));
check((int) $row()['requested_at'] === 0 && in_array($run['code'], [0, 3], true), 'a full run without the option clears it');

echo "\n=== the lock is the row ===\n";
$reset();
$pdo->exec("UPDATE topo_run SET owner = 'someone:1:x', heartbeat = ".time().", status = 'running' WHERE id = 1");
$run = finish(start($dsn, $user, $password, $base.'-cli'));
check($run['code'] === 1 && str_contains($run['out'], 'Ingest already running'), 'a run while a fresh lock is held exits with 1 and says "Ingest already running"', $run);
check($row()['owner'] === 'someone:1:x' && $row()['status'] === 'running', 'the holder\'s row is not touched by the loser');
$status = json_decode((string) @file_get_contents($base.'-cli/topology-ingest-status.json'), true);
check(($status['status'] ?? '') === 'error' && str_contains($status['error'] ?? '', 'Ingest already running'), 'its own status file reports the same');
$pdo->exec('UPDATE topo_run SET requested_at = '.time().' WHERE id = 1');
$run = finish(start($dsn, $user, $password, $base.'-cli', [], ['--if-requested']));
check($run['code'] === 0 && (int) $row()['requested_at'] > 0, '--if-requested while a run works: exit 0, the request stays for the next start');

echo "\n=== a dead run: an old heartbeat frees the lock ===\n";
$pdo->exec("UPDATE topo_run SET owner = 'dead:1:x', status = 'running', heartbeat = ".(time() - TOPOLOGY_RUN_LOCK_TIMEOUT - 5).' WHERE id = 1');
$state = topology_run_state($row(), time());
check($state['status'] === 'error' && !$state['locked'], 'the state of a "running" row with an old heartbeat: error, not locked', $state);
$run = finish(start($dsn, $user, $password, $base.'-cli'));
check(in_array($run['code'], [0, 3], true) && $row()['status'] === 'done' && $row()['owner'] === '', 'the next run takes the lock and finishes', [$run['code'], $row()]);

echo "\n=== the heartbeat belongs to the owner ===\n";
$reset();
check(TopologyRunRow::lock($pdo, 'a:1', time()) && !TopologyRunRow::lock($pdo, 'b:2', time()), 'one takes the lock, the other does not');
check(TopologyRunRow::heartbeat($pdo, 'a:1', time() + 1) && !TopologyRunRow::heartbeat($pdo, 'b:2', time() + 1), 'only the owner updates the heartbeat');
$pdo->exec('UPDATE topo_run SET heartbeat = '.(time() - TOPOLOGY_RUN_LOCK_TIMEOUT - 1).' WHERE id = 1');
check(TopologyRunRow::lock($pdo, 'b:2', time()) && !TopologyRunRow::heartbeat($pdo, 'a:1', time()), 'after the timeout the other takes it; the first finds that its lock is gone');
TopologyRunRow::release($pdo, 'b:2');

echo "\n=== a real run holds the lock (stopped mid-flight), a second run with another temp dir exits ===\n";
$reset();
$first = start($dsn, $user, $password, $base.'-a');
$holder = false;
for ($i = 0; $i < 100 && !$holder; $i++) {
	usleep(20000);
	$holder = $row()['status'] === 'running' && $row()['owner'] !== '';
}
$st = proc_get_status($first[0]);
if ($st['running'] && $holder) {
	posix_kill($st['pid'], SIGSTOP);
	$second = finish(start($dsn, $user, $password, $base.'-b'));
	check($second['code'] === 1 && str_contains($second['out'], 'Ingest already running'), 'a real run in progress (other temp dir) makes a second run exit with "Ingest already running"');
	posix_kill($st['pid'], SIGCONT);
	$r = finish($first);
	check(in_array($r['code'], [0, 3], true) && $row()['owner'] === '', 'the stopped run finishes normally after SIGCONT and frees the lock');
}
else {
	finish($first);
	echo "  note: the first run finished before it could be stopped; this scenario not exercised\n";
}

echo "\nAll ingest lock tests passed.\n";
