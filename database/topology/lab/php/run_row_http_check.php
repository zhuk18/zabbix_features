<?php

declare(strict_types=1);

/**
 * run_row_http_check.php — task 087, topology-1-model.md 2.65 §6.1, §6.11, §11 on the real frontend, over HTTP: the UI only requests a run (POST topology.ingest.run), the CLI executes it
 * (`ingest.php --if-requested`); the status comes from the run row (`requested`, `executor_missing`); an operator write action waits for the run lock up to 5 seconds and is then refused with
 * "A reconciliation is in progress. Try again."; "accept discovery" records a request and the change applies at the next CLI run.
 *
 * Needs the office stand data (R1) in the database, a Super admin in the environment (ZBX_UI_USER, ZBX_UI_PASSWORD; never in a file), and the database of the frontend through
 * TOPOLOGY_PDO_DSN / TOPOLOGY_PDO_USER (default: the unix socket of the lab, user zabbix). The data is left as it was found.
 *
 * Usage: php run_row_http_check.php        (exit 1 when a check fails, 2 when the environment is not there)
 */

$base = rtrim((string) (getenv('ZBX_UI_URL') ?: 'http://localhost/zabbix/ui'), '/').'/';
$user = (string) getenv('ZBX_UI_USER');
$password = (string) getenv('ZBX_UI_PASSWORD');
if ($user === '' || $password === '') {
	fwrite(STDERR, "ZBX_UI_USER and ZBX_UI_PASSWORD are needed\n");
	exit(2);
}

$failed = 0;
function check(bool $condition, string $description, $detail = null): void {
	global $failed;
	echo ($condition ? '  ok: ' : '  FAIL: ').$description.(!$condition && $detail !== null ? ' '.json_encode($detail) : '')."\n";
	$failed += $condition ? 0 : 1;
}

/** One logged-in session: cookies in a private jar (removed at the end). */
class Session {
	private string $jar;

	public function __construct(private string $base, string $name, string $password) {
		$this->jar = tempnam(sys_get_temp_dir(), 'topo-lastrun-');
		$this->request('index.php', ['name' => $name, 'password' => $password, 'autologin' => '1', 'enter' => 'Sign in']);
	}

	public function request(string $path, ?array $post = null, ?string $json = null): string {
		$curl = curl_init($this->base.$path);
		curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $this->jar, CURLOPT_COOKIEFILE => $this->jar, CURLOPT_TIMEOUT => 60]);
		if ($post !== null) {
			curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($post));
		}
		if ($json !== null) {
			curl_setopt($curl, CURLOPT_POSTFIELDS, $json);
			curl_setopt($curl, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
		}
		$body = (string) curl_exec($curl);
		curl_close($curl);

		return $body;
	}

	public function json(string $query): array {
		return json_decode($this->request('zabbix.php?action='.$query), true) ?? [];
	}

	public function __destruct() {
		@unlink($this->jar);
	}
}

$admin = new Session($base, $user, $password);
$pdo = new PDO((string) (getenv('TOPOLOGY_PDO_DSN') ?: 'mysql:unix_socket=/run/mysqld/mysqld.sock;dbname=zabbix'), (string) (getenv('TOPOLOGY_PDO_USER') ?: 'zabbix'),
	(string) getenv('TOPOLOGY_PDO_PASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$ingest = dirname(__DIR__, 2).'/discovery/ingest.php';

/** The CLI job: `ingest.php [args]` (the way the scheduled job and the lab start a run). */
function cli(array $args = []): array {
	global $pdo, $ingest;
	$cmd = [PHP_BINARY, $ingest, '--pdo-dsn', (string) (getenv('TOPOLOGY_PDO_DSN') ?: 'mysql:unix_socket=/run/mysqld/mysqld.sock;dbname=zabbix'),
		'--pdo-user', (string) (getenv('TOPOLOGY_PDO_USER') ?: 'zabbix')];
	$process = proc_open(array_merge($cmd, $args), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
	$out = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);

	return ['code' => proc_close($process), 'out' => $out];
}

function row(PDO $pdo): array {
	return $pdo->query('SELECT * FROM topo_run WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
}

function csrf(Session $s): string {
	preg_match('/"_csrf_token"\]?:\s*"([0-9a-f]+)"/', $s->request('zabbix.php?action=topology.view'), $m);

	return $m[1] ?? '';
}

function post(Session $s, string $action, array $body): array {
	return json_decode($s->request('zabbix.php?action='.$action, null, json_encode($body + ['_csrf_token' => csrf($s)])), true) ?? [];
}

echo "=== 1. POST topology.ingest.run only requests ===\n";
$pdo->exec('UPDATE topo_run SET requested_at = 0, requested_by = \'\' WHERE id = 1');
$before = row($pdo);
$answer = post($admin, 'topology.ingest.run', []);
check(($answer['status'] ?? null) === 'requested', 'the answer is "requested"', $answer);
$after = row($pdo);
check((int) $after['requested_at'] > 0 && $after['requested_by'] === $user && $after['run_id'] === $before['run_id'] && $after['finished_at'] === $before['finished_at'],
	'the row has the request and the user; no run ran (run_id and finished_at are those of the earlier run)', [$before, $after]);
$status = $admin->json('topology.ingest.status');
check(($status['requested'] ?? false) === true && ($status['running'] ?? true) === false && ($status['executor_missing'] ?? true) === false, 'the status: requested, not running, executor not missing', $status);
$run = cli(['--if-requested']);
check(in_array($run['code'], [0, 3], true), '--if-requested runs it', $run);
$status = $admin->json('topology.ingest.status');
check(in_array($status['status'] ?? '', ['done'], true) && ($status['requested'] ?? true) === false && ($status['started_by'] ?? '') === $user && $status['run_id'] !== $before['run_id'],
	'the status is done, the request is cleared, started_by is the user of the request', $status);
check(in_array($status['result'] ?? null, ['done', 'done_with_warnings'], true) && is_array($status['summary']['attention'] ?? null), 'the status has the result and the list "Needs attention" (read from the row)');
$last = $admin->request('zabbix.php?action=topology.lastrun');
check(str_contains($last, 'topology-lastrun-result') && str_contains($last, $user), 'the last run view shows the run of the row');

echo "\n=== 2. a request that no run took for 5 minutes: executor_missing ===\n";
$pdo->exec('UPDATE topo_run SET requested_at = '.(time() - 301).' WHERE id = 1');
$status = $admin->json('topology.ingest.status');
check(($status['executor_missing'] ?? false) === true && ($status['requested'] ?? false) === true, 'executor_missing is true after 301 seconds', $status);
$pdo->exec('UPDATE topo_run SET requested_at = '.(time() - 60).' WHERE id = 1');
check(($admin->json('topology.ingest.status')['executor_missing'] ?? true) === false, 'a request of 60 seconds ago: not missing yet');
$run = cli(['--if-requested']);
check(($admin->json('topology.ingest.status')['requested'] ?? true) === false, 'the CLI run clears it');

echo "\n=== 3. a merge while a run holds the lock ===\n";
$ids = [];
foreach (['zz-lock-a', 'zz-lock-b'] as $name) {
	$pdo->prepare("INSERT INTO topo_nodes (type, attrs, created_at, updated_at) VALUES ('device', ?, 1, 1)")->execute([json_encode(['sysname' => $name, 'chassis_id' => null, 'mac' => null])]);
	$ids[] = (int) $pdo->lastInsertId();
}
$pdo->exec("UPDATE topo_run SET owner = 'test:1:x', heartbeat = ".time().' WHERE id = 1');
$started = microtime(true);
$refused = post($admin, 'topology.devices.merge', ['keep_id' => (string) $ids[0], 'remove_id' => (string) $ids[1]]);
$elapsed = microtime(true) - $started;
check(($refused['error']['messages'][0] ?? '') === 'A reconciliation is in progress. Try again.', 'refused with the message of the model', $refused);
check($elapsed >= 4.5 && $elapsed < 12, 'after about 5 seconds ('.round($elapsed, 1).' s)', $elapsed);
check((int) $pdo->query('SELECT COUNT(*) FROM topo_nodes WHERE id IN ('.implode(',', $ids).')')->fetchColumn() === 2, 'nothing was merged');
check(row($pdo)['owner'] === 'test:1:x', 'the lock of the run is not released by the refused action');
$pdo->exec("UPDATE topo_run SET owner = '' WHERE id = 1");
$done = post($admin, 'topology.devices.merge', ['keep_id' => (string) $ids[0], 'remove_id' => (string) $ids[1]]);
check(($done['success'] ?? false) === true && (int) $pdo->query('SELECT COUNT(*) FROM topo_nodes WHERE id = '.$ids[1])->fetchColumn() === 0, 'the same merge after the run: done', $done);
check(row($pdo)['owner'] === '', 'the action freed the lock');
$pdo->exec('DELETE FROM topo_nodes WHERE id = '.$ids[0]);

echo "\n=== 4. accept discovery: a request, the effect at the next CLI run ===\n";
// a discovered link L between two real ports; a manual link M from one of its ports to a free port makes the discovered one shadowed at the next run; accepting deletes M
$link = $pdo->query("SELECT e.id, e.src_id, e.dst_id FROM topo_edges e WHERE e.type = 'physical_link' AND JSON_UNQUOTE(JSON_EXTRACT(e.attrs, '\$.discovered_via')) <> 'manual' AND JSON_EXTRACT(e.attrs, '\$.superseded_at') IS NULL ORDER BY e.id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$free = (int) $pdo->query("SELECT p.id FROM topo_nodes p WHERE p.type = 'port' AND JSON_EXTRACT(p.attrs, '\$.pseudo') = false AND NOT EXISTS (SELECT 1 FROM topo_edges e WHERE e.src_id = p.id OR e.dst_id = p.id) ORDER BY p.id LIMIT 1")->fetchColumn();
check($link !== false && $free > 0, 'the data has a discovered link and a free port', [$link, $free]);
$made = post($admin, 'topology.ports.link', ['id' => (string) $link['src_id'], 'dst_id' => (string) $free]);
$manual = (int) ($made['edge_id'] ?? 0);
check($manual > 0, 'a manual link on one port of the discovered link', $made);
cli();
$contradicted = (int) $pdo->query("SELECT COUNT(*) FROM topo_observations WHERE edge_id = {$manual} AND outcome = 'shadowed'")->fetchColumn();
check($contradicted >= 1, 'the next run: discovery contradicts the manual link (a shadowed observation)', $contradicted);
$pdo->exec('UPDATE topo_run SET requested_at = 0 WHERE id = 1');
$answer = post($admin, 'topology.manual.accept', ['edge_id' => (string) $manual]);
check(($answer['success'] ?? false) === true && ($answer['ingest'] ?? '') === 'requested' && str_contains((string) ($answer['message'] ?? ''), 'next run'), 'the answer says that the change applies at the next run', $answer);
check((int) $pdo->query("SELECT COUNT(*) FROM topo_edges WHERE id = {$manual}")->fetchColumn() === 0 && (int) row($pdo)['requested_at'] > 0, 'the manual link is deleted and a run is requested');
$run = cli(['--if-requested']);
$active = (int) $pdo->query("SELECT COUNT(*) FROM topo_edges WHERE id = {$link['id']} AND JSON_EXTRACT(attrs, '\$.superseded_at') IS NULL")->fetchColumn();
check(in_array($run['code'], [0, 3], true) && $active === 1, 'after the CLI run the discovered link is active again', [$run['code'], $active]);
check((int) row($pdo)['requested_at'] === 0, 'the request is cleared');

echo "\n=== 5. reporters with new data (task 088) ===\n";
cli();
$status = $admin->json('topology.ingest.status');
check(($status['reporters_new_data'] ?? null) === 0, 'after a run and no change: reporters_new_data = 0', $status['reporters_new_data'] ?? null);
check(is_int($status['summary']['stats']['heartbeat_gap_max_seconds'] ?? null), 'the summary of the run has stats.heartbeat_gap_max_seconds', $status['summary']['stats'] ?? null);
$item = $pdo->query("SELECT s.itemid, s.rows_hash FROM topo_lld_snapshot s JOIN items i ON i.itemid = s.itemid AND i.status = 0 AND i.topology_type = s.topology_type ORDER BY s.itemid LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$pdo->prepare("UPDATE topo_lld_snapshot SET rows_hash = 'zz-changed' WHERE itemid = ?")->execute([$item['itemid']]);
check(($admin->json('topology.ingest.status')['reporters_new_data'] ?? null) === 1, 'one rule\'s rows change: reporters_new_data = 1');
check(str_contains($admin->request('zabbix.php?action=topology.view'), 'topology-run-line'), 'the page has the place of the note');
$pdo->prepare('UPDATE topo_lld_snapshot SET rows_hash = ? WHERE itemid = ?')->execute([$item['rows_hash'], $item['itemid']]);
cli();
check(($admin->json('topology.ingest.status')['reporters_new_data'] ?? null) === 0, 'the data restored and a run: 0 again');

echo $failed ? "\n{$failed} check(s) FAILED\n" : "\nAll run row checks passed.\n";
exit($failed ? 1 : 0);
