<?php

declare(strict_types=1);

/**
 * graph_page_check.php — task 090, topology-1-model.md 2.70 §12.1 (page header and filter bar), §12.3 (parallel links), §12.8 (leaf groups) on the real frontend, over HTTP: the page has no
 * "Links" select and no "Clean all" button, "Reset" instead of "Show all", the hint with the words of the view scope, the switch "Show devices without links"; the endpoint of "Clean all" is
 * gone; `topology.devices.get` gives every link as its own relation (two links between SW12 and SW18), and the leaf groups (four or more leaves, none with "Possible duplicates only").
 *
 * Needs the office stand data (R1) and, in the environment (never in a file): ZBX_UI_USER, ZBX_UI_PASSWORD (a Super admin). ZBX_UI_URL is the frontend (default http://localhost/zabbix/ui/).
 *
 * Usage: php graph_page_check.php        (exit 1 when a check fails, 2 when the environment is not there)
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

echo "=== the page header and the filter bar ===
";
$page = $admin->request('zabbix.php?action=topology.view');
check(str_contains($page, 'id="topology-filter-clear"') && str_contains($page, '>Reset<') && !str_contains($page, '>Show all<') && !str_contains($page, '&quot;Show all&quot;') && str_contains($page, '>Apply<'), 'the button "Show all" is "Reset" (same id), "Apply filter" is "Apply", no button or hint says "Show all"');
check(!str_contains($page, 'topology-link-filter') && !str_contains($page, 'Automatic + manual'), 'the page has no "Links" select');
check(!str_contains($page, 'topology-clean-all') && !str_contains($page, 'Clean All'), 'the page has no "Clean all" button');
check(str_contains($page, 'topology-filter-unlinked') && str_contains($page, 'Show devices without links') && str_contains($page, 'topology-filter-duplicates'), 'the filter has the two switches');
check(str_contains($page, 'distance of Depth or less') && str_contains($page, 'Reset&quot; clears the selection and displays every node') || str_contains($page, 'Reset" clears the selection and displays every node'), 'the hint next to Depth has the help text, with "Reset" in the place of "Show all"');
check(preg_match('/class="topology-filter-hint"/', $page) !== 1, 'the help text is not fixed text above the graph any more');
$clean = $admin->request('zabbix.php?action=topology.clean.all', null, json_encode(['_csrf_token' => '']));
check(!str_contains($clean, '"status":"ok"') && !str_contains($clean, 'nodes_deleted'), 'the endpoint of "Clean all" is gone');

echo "
=== links and leaf groups of the graph ===
";
$view = $admin->json('topology.devices.get');
$devices = array_column($view['devices'] ?? [], null, 'id');
$display = [];
foreach ($view['relations'] ?? [] as $r) {
	if ($r['type'] === 'represented_by') {
		$display[$r['source']] = $r['target'];
	}
}
$by_name = static fn (string $name) => array_values(array_filter($view['devices'], static fn (array $d): bool => ($d['label'] ?? '') === $name && $d['type'] === 'device'))[0]['id'] ?? null;
$pair = static function (string $a, string $b) use ($view, $display, $by_name): array {
	$ids = [$by_name($a), $by_name($b)];
	return array_values(array_filter($view['relations'], static fn (array $r): bool => $r['type'] === 'physical_link' && in_array($r['source'], $ids, true) && in_array($r['target'], $ids, true)));
};
$sw12_sw18 = $pair('SW12-10-CORE-6000-48G', 'SW18-09-ACC-2530-48G');
check(count($sw12_sw18) >= 2 && count(array_unique(array_column($sw12_sw18, 'edge_id'))) === count($sw12_sw18), 'SW12 and SW18: every link is its own relation with its own edge id (two ports 43/44 - 48/47)', array_column($sw12_sw18, 'source_port'));
$groups = $view['leaf_groups'] ?? null;
check(is_array($groups) && $groups !== [] && count(array_filter($groups, static fn (array $g): bool => count($g['devices']) < 4)) === 0, 'leaf_groups: every group has four or more leaves', array_map(static fn (array $g): int => count($g['devices']), $groups ?? []));
$grouped = array_merge(...array_column($groups ?? [], 'devices'));
check(count($grouped) === count(array_unique($grouped)), 'a Device is in one group only');
$links_of = [];
foreach ($view['relations'] as $r) {
	if (in_array($r['type'], ['physical_link', 'device_link'], true) && !$r['superseded_at']) {
		$links_of[$r['source']] = ($links_of[$r['source']] ?? 0) + 1;
		$links_of[$r['target']] = ($links_of[$r['target']] ?? 0) + 1;
	}
}
check(count(array_filter($grouped, static fn (string $id): bool => ($links_of[$id] ?? 0) !== 1 || !empty($devices[$id]['represented']) || !empty($devices[$id]['duplicates']))) === 0,
	'every Device in a group is without association, has one link and no duplicate badge');
$duplicates = $admin->json('topology.devices.get&duplicates=1');
check(($duplicates['leaf_groups'] ?? null) === [], 'with "Possible duplicates only" there are no groups');
$scoped = $admin->json('topology.devices.get&hostid=13524&hops=1');
$scoped_ok = true;
foreach ($scoped['leaf_groups'] ?? [] as $g) {
	$scoped_ok = $scoped_ok && count($g['devices']) >= 4;
}
check($scoped_ok, 'in a view with a focus host the groups also have four or more leaves in the view');

echo $failed ? "
{$failed} check(s) FAILED
" : "
All graph page checks passed.
";
exit($failed ? 1 : 0);
