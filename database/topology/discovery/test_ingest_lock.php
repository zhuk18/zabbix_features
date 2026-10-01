#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * test_ingest_lock.php — ingest.php's run exclusion is a DATABASE advisory lock, so two runs exclude each other
 * even when their temp dirs differ (PHP-FPM has a private /tmp; the CLI does not).
 *
 * Usage: test_ingest_lock.php <PDO DSN> <database user> [password]
 * Needs the stand-in schema of test_snapshot_ingest.php in the database (run that first). MySQL only.
 */

[$dsn, $user, $password] = [$argv[1] ?? '', $argv[2] ?? '', $argv[3] ?? ''];

function check(bool $condition, string $description): void {
	if (!$condition) {
		fwrite(STDERR, "FAIL: {$description}\n");
		exit(1);
	}
	echo "  ok: {$description}\n";
}

function start(string $dsn, string $user, string $password, string $tmp, array $env = []) {
	@mkdir($tmp);
	$cmd = [PHP_BINARY, __DIR__.'/ingest.php', '--pdo-dsn', $dsn, '--pdo-user', $user, '--pdo-password', $password];
	$p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, ['TMPDIR' => $tmp] + $env);
	return [$p, $pipes];
}

function finish($proc): array {
	[$p, $pipes] = $proc;
	$out = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
	return ['code' => proc_close($p), 'out' => $out];
}

$base = sys_get_temp_dir().'/topology-lock-test-'.getmypid();
$pdo = new PDO($dsn, $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$name = 'topology_ingest:'.substr(md5((string) $pdo->query('SELECT DATABASE()')->fetchColumn()), 0, 16);

// 1. A holder with another temp dir (a "web" run) — here a plain connection holding the lock.
check((int) $pdo->query("SELECT GET_LOCK('{$name}', 0)")->fetchColumn() === 1, 'the test holds the ingest lock');
$run = finish(start($dsn, $user, $password, $base.'-cli'));
check($run['code'] === 1, 'a run while the lock is held exits with 1');
check(str_contains($run['out'], 'Ingest already running'), 'and says "Ingest already running"');
$status = json_decode((string) @file_get_contents($base.'-cli/topology-ingest-status.json'), true);
check(($status['status'] ?? '') === 'error' && str_contains($status['error'] ?? '', 'Ingest already running'),
	'its own status file reports the same');

// 2. The lock is released when the holder goes away.
$pdo->query("SELECT RELEASE_LOCK('{$name}')");
$run = finish(start($dsn, $user, $password, $base.'-cli'));
check($run['code'] === 0, 'a run after the lock is released succeeds');

// 3. A real run that holds the lock (stopped mid-flight) with temp dir A; a second run with temp dir B exits.
$first = start($dsn, $user, $password, $base.'-a');
$holder = false;
for ($i = 0; $i < 100 && !$holder; $i++) {
	usleep(20000);
	$s = json_decode((string) @file_get_contents($base.'-a/topology-ingest-status.json'), true);
	$holder = ($s['status'] ?? '') === 'running';
}
// The first run may already be over (the data set is tiny); in that case stop here with a note.
$st = proc_get_status($first[0]);
if ($st['running']) {
	posix_kill($st['pid'], SIGSTOP);
	$second = finish(start($dsn, $user, $password, $base.'-b'));
	check($second['code'] === 1 && str_contains($second['out'], 'Ingest already running'),
		'a real run in progress (other temp dir) makes a second run exit with "Ingest already running"');
	posix_kill($st['pid'], SIGCONT);
	$r = finish($first);
	check($r['code'] === 0, 'the stopped run finishes normally after SIGCONT');
}
else {
	finish($first);
	echo "  note: the first run finished before it could be stopped; scenario 3 not exercised\n";
}

echo "\nAll ingest lock tests passed.\n";
