<?php

declare(strict_types=1);

/**
 * lastrun_http_check.php — topology-1-model.md 2.57 §12.7 on the real frontend, over HTTP (task 076): the menu item, the page for Super admin only, `started_by` of a run
 * that the page starts, the new filters of `topology.observations.get` (`reporter_hostid`, `port_id`), the last run view and its filter links.
 *
 * Needs the office stand data in the lab and, in the environment (never in a file): ZBX_UI_USER, ZBX_UI_PASSWORD (a Super admin), and ZBX_TOKEN (an API token, to create and
 * remove the user that is not a Super admin; that part is skipped without it). ZBX_UI_URL is the frontend (default http://localhost/zabbix/ui/).
 *
 * Usage: php lastrun_http_check.php        (exit 1 when a check fails, 2 when the environment is not there)
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

echo "=== the menu and the page ===\n";
$dashboard = $admin->request('zabbix.php?action=dashboard.view');
check(str_contains($dashboard, 'action=topology.view"'), 'the main menu of a Super admin has Monitoring → Topology');
check(preg_match('/action=map\.view">Maps<\/a><\/li><li><a href="zabbix\.php\?action=topology\.view">Topology</', $dashboard) === 1, 'the item follows Maps');
$page = $admin->request('zabbix.php?action=topology.view');
check(str_contains($page, 'topology-canvas') && str_contains($page, 'action=topology.lastrun'), 'the topology page opens and has the link "Last run"');
$lastrun = $admin->request('zabbix.php?action=topology.lastrun');
check(str_contains($lastrun, 'topology-lastrun-summary') && str_contains($lastrun, 'topology-lastrun-list'), 'the last run view opens');

echo "\n=== a run started from the page: started_by ===\n";
check(preg_match('/"_csrf_token"\]?:\s*"([0-9a-f]+)"/', $page, $m) === 1, 'the page carries the CSRF token');
$before = $admin->json('topology.ingest.status')['run_id'] ?? null;
$started = json_decode($admin->request('zabbix.php?action=topology.ingest.run', null, json_encode(['_csrf_token' => $m[1] ?? ''])), true);
check(($started['status'] ?? null) === 'started', 'POST topology.ingest.run starts a run', $started);
$status = [];
for ($i = 0; $i < 90; $i++) {
	sleep(2);
	$status = $admin->json('topology.ingest.status');
	if (($status['run_id'] ?? $before) !== $before && ($status['status'] ?? '') !== 'running') {
		break;
	}
}
check(($status['started_by'] ?? null) === $user, 'started_by is the user name of the page ('.$user.')', $status['started_by'] ?? null);
check(in_array($status['result'] ?? null, ['done', 'done_with_warnings'], true) && is_array($status['summary']['attention'] ?? null), 'the status has the result and the list "Needs attention"', $status['result'] ?? null);

echo "\n=== topology.observations.get: reporter_hostid and port_id ===\n";
$all = $admin->json('topology.observations.get&limit=5000&outcome[]=applied&outcome[]=device_only&outcome[]=shadowed&outcome[]=conflict&outcome[]=ambiguous')['observations'] ?? [];
check(count($all) > 5, 'the lab has observations', count($all));
$reporter = $all[0]['reporter']['hostid'] ?? null;
$by_reporter = $admin->json('topology.observations.get&limit=5000&reporter_hostid='.$reporter.'&outcome[]=applied&outcome[]=device_only&outcome[]=shadowed&outcome[]=conflict&outcome[]=ambiguous')['observations'] ?? [];
check($by_reporter !== [] && count($by_reporter) < count($all) && count(array_filter($by_reporter, static fn (array $o): bool => (string) $o['reporter']['hostid'] !== (string) $reporter)) === 0
	&& count($by_reporter) === count(array_filter($all, static fn (array $o): bool => (string) $o['reporter']['hostid'] === (string) $reporter)), 'reporter_hostid: only the observations of that host, all of them');
$port = $all[0]['local_port']['id'] ?? null;
$by_port = $admin->json('topology.observations.get&limit=5000&port_id='.$port.'&outcome[]=applied&outcome[]=device_only&outcome[]=shadowed&outcome[]=conflict&outcome[]=ambiguous')['observations'] ?? [];
check($by_port !== [] && count($by_port) < count($all) && count(array_filter($by_port, static fn (array $o): bool => (string) $o['local_port']['id'] !== (string) $port && $o['edge_id'] === null)) === 0
	&& count(array_filter($by_port, static fn (array $o): bool => (string) $o['local_port']['id'] === (string) $port)) === count(array_filter($all, static fn (array $o): bool => (string) $o['local_port']['id'] === (string) $port)),
	'port_id: the observations reported on that Port and the claims of the links that end at it', count($by_port));
$device = $all[0]['remote_device']['id'] ?? null;
$by_device = $admin->json('topology.observations.get&limit=5000&device_id='.$device.'&outcome[]=applied&outcome[]=device_only&outcome[]=shadowed&outcome[]=conflict&outcome[]=ambiguous')['observations'] ?? [];
check($by_device !== [] && count(array_filter($by_device, static fn (array $o): bool => (string) ($o['remote_device']['id'] ?? '') !== (string) $device)) === 0, 'device_id still gives the observations about that Device');
$bad = $admin->json('topology.observations.get&reporter_hostid=abc');
check(isset($bad['error']) || !isset($bad['observations']), 'a bad reporter_hostid is refused');
$ambiguous = array_values(array_filter($all, static fn (array $o): bool => $o['outcome'] === 'ambiguous'));
check($ambiguous === [] || ($ambiguous[0]['reason']['type'] ?? null) === 'other_neighbors', 'an ambiguous observation has its reason (the other neighbors of the port)');

echo "\n=== the view: the links of the outcome numbers and the filters ===\n";
$summary_page = $admin->request('zabbix.php?action=topology.lastrun');
foreach (['applied', 'device_only', 'shadowed', 'conflict', 'ambiguous'] as $outcome) {
	check(str_contains($summary_page, 'topology-lastrun-outcome-'.$outcome) && str_contains($summary_page, 'filter_outcomes%5B0%5D='.$outcome), 'the number of '.$outcome.' is a link that sets the filter');
}
check(str_contains($summary_page, 'only_duplicates=1') && str_contains($summary_page, 'action=topology.view'), 'the number of duplicate candidates opens the graph with "Possible duplicates only"');
$ambiguous_page = $admin->request('zabbix.php?action=topology.lastrun&filter_set=1&filter_outcomes[0]=ambiguous');
check(substr_count($ambiguous_page, '<td>ambiguous</td>') === count(array_filter($all, static fn (array $o): bool => $o['outcome'] === 'ambiguous'))
	&& !str_contains($ambiguous_page, '<td>applied</td>'), 'a click on the number of ambiguous: the list has the ambiguous observations only');
$device_page = $admin->request('zabbix.php?action=topology.lastrun&device_id='.$device);
check(substr_count($device_page, '<td>applied</td>') + substr_count($device_page, '<td>ambiguous</td>') + substr_count($device_page, '<td>conflict</td>') + substr_count($device_page, '<td>shadowed</td>') + substr_count($device_page, '<td>device_only</td>') === count($by_device),
	'the link of a Device: the list has its observations, with every outcome', count($by_device));
check(str_contains($admin->request('zabbix.php?action=topology.lastrun&filter_rst=1'), 'topology-lastrun-list'), 'the filter is reset');
check(str_contains($admin->request('zabbix.php?action=topology.lastrun&filter_set=1&filter_outcomes[0]=nonsense'), 'no permissions') === false
	&& !str_contains($admin->request('zabbix.php?action=topology.lastrun&filter_set=1&filter_outcomes[0]=nonsense'), 'topology-lastrun-list'), 'a wrong outcome is refused');

echo "\n=== a user that is not a Super admin ===\n";
$token = (string) getenv('ZBX_TOKEN');
if ($token === '') {
	echo "  skip: ZBX_TOKEN is not set\n";
}
else {
	$rpc = static function (string $method, array $params) use ($base, $token): array {
		$curl = curl_init($base.'api_jsonrpc.php');
		curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_HTTPHEADER => ['Content-Type: application/json-rpc', 'Authorization: Bearer '.$token],
			CURLOPT_POSTFIELDS => json_encode(['jsonrpc' => '2.0', 'method' => $method, 'params' => $params, 'id' => 1])]);
		$result = json_decode((string) curl_exec($curl), true) ?? [];
		curl_close($curl);

		return $result;
	};
	$name = 'topo076-'.bin2hex(random_bytes(3));
	$secret = 'Aa1!'.bin2hex(random_bytes(8));
	$groups = $rpc('usergroup.get', ['output' => ['usrgrpid'], 'filter' => ['users_status' => 0], 'limit' => 1])['result'] ?? [];
	$roles = $rpc('role.get', ['output' => ['roleid'], 'filter' => ['type' => 1]])['result'] ?? [];
	$created = $rpc('user.create', ['username' => $name, 'passwd' => $secret, 'roleid' => $roles[0]['roleid'] ?? 1, 'usrgrps' => [['usrgrpid' => $groups[0]['usrgrpid'] ?? 7]]]);
	$userid = $created['result']['userids'][0] ?? null;
	check($userid !== null, 'a user of the role "User" was created for the check', $created['error'] ?? null);
	if ($userid !== null) {
		try {
			$plain = new Session($base, $name, $secret);
			$home = $plain->request('zabbix.php?action=dashboard.view');
			check(str_contains($home, 'Monitoring') && !str_contains($home, 'action=topology.view"'), 'the main menu of that user has no Topology item',
				[strlen($home), preg_match('/.{60}topology\.view.{40}/s', $home, $near) ? $near[0] : substr(strip_tags($home), 0, 200)]);
			foreach (['topology.view', 'topology.lastrun'] as $action) {
				$denied = $plain->request('zabbix.php?action='.$action);
				check(str_contains($denied, 'You have no permissions to access this page') && !str_contains($denied, 'topology-canvas') && !str_contains($denied, 'topology-lastrun-summary'),
					$action.': the standard "no permissions" page');
			}
			$state = $plain->json('topology.ingest.status');
			check(($state['status'] ?? null) !== null && !isset($state['summary']) && !isset($state['started_by']), 'topology.ingest.status gives that user the state of the run and nothing else');
		}
		finally {
			$rpc('user.delete', [$userid]);
		}
	}
}

echo $failed === 0 ? "\nAll checks passed.\n" : "\n{$failed} check(s) failed.\n";
exit($failed === 0 ? 0 : 1);
