<?php

/**
 * POST /topo/ingest/run (spec §6) — starts database/topology/discovery/ingest.php (the same CLI script,
 * §4.1) as a detached background OS process and returns immediately. This is deliberately *not* a
 * reimplementation of ingest.php's logic in PHP-in-process: spawning the literal same script file is
 * what satisfies the spec's "must call the exact same ingest logic the CLI already uses" requirement
 * without a second, parallel implementation of §3's rules.
 *
 * The concurrency lock (§6 — "must go through the same run-lock as the CLI") lives inside ingest.php
 * itself (flock() on .ingest.lock next to the script), so both a CLI-triggered run and a button-triggered
 * one always go through the same lock regardless of which process gets there first — nothing extra is
 * needed here beyond spawning the script.
 *
 * Arg sourcing (flagged rather than hardcoded silently, per spec §1's "flag rather than expand scope"):
 *  - --api-url / --api-token: this controller runs inside an authenticated Zabbix web session, so rather
 *    than minting a new API token it reuses the current session id (CWebUser::$data['sessionid']) as the
 *    bearer credential against this same frontend's api_jsonrpc.php — the JSON-RPC layer
 *    (CLocalApiClient::authenticate()) already accepts either a 64-char API token or a session id under
 *    the same Bearer header, so no new credential-management surface is needed.
 *  - --pdo-dsn/--pdo-user/--pdo-password: read from the frontend's own $DB global (populated from
 *    ui/conf/zabbix.conf.php), which is the same database ingest.php needs to reach directly via PDO.
 *  - --config: the reporters list has no frontend-side source yet (no UI for picking/managing reporters
 *    exists in this prototype) — this is a real product gap, not an implementation detail, so it's
 *    flagged here rather than guessed silently. For now this defaults to
 *    database/topology/discovery/reporters.json if that real (non-`.example`) config exists (the file
 *    push.py's own docstring already expects an operator to create, typically gitignored since it may
 *    carry SNMP community strings), falling back to reporters.example.json only so the button has
 *    something to run against in a fresh checkout / this lab environment. A real product decision is
 *    needed on how reporters are selected from the UI — this is a placeholder, not a design choice.
 */
class CControllerTopologyIngestRun extends CController {
	protected function init(): void {
		$this->setPostContentType(self::POST_CONTENT_TYPE_JSON);
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool { return true; }
	protected function checkPermissions(): bool { return !CWebUser::isGuest(); }

	protected function doAction() {
		global $DB;

		$discovery_dir = dirname(__DIR__, 3).'/database/topology/discovery';
		$ingest_script = $discovery_dir.'/ingest.php';
		$log_file = $discovery_dir.'/.ingest-run.log';

		// Deliberately always spawns rather than peeking at the status file first to decide "already
		// running, don't bother": ingest.php's own flock() (§6) is the actual source of truth on whether
		// a run is in progress, and it's cheap for a second spawn to lose that race and exit immediately.
		// Pre-checking the status file instead would risk a permanently-stuck "running" state if a prior
		// run ever died without reaching its own shutdown-triggered status write (e.g. `kill -9` on the
		// PHP process — flock() itself is always released by the OS on process death, but a stale status
		// read here wouldn't know that, whereas always attempting a fresh spawn self-heals it).

		$config_path = is_file($discovery_dir.'/reporters.json')
			? $discovery_dir.'/reporters.json'
			: $discovery_dir.'/reporters.example.json';

		if (!is_file($config_path)) {
			$this->setResponse(new CControllerResponseData(['main_block' => json_encode([
				'error' => ['messages' => ['No reporters config found ('.$config_path.').']]
			])]));
			return;
		}

		$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
		$host = $_SERVER['HTTP_HOST'] ?? '127.0.0.1';
		$base_path = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
		$api_url = $scheme.'://'.$host.$base_path.'/api_jsonrpc.php';

		// Reuse the current session's own credential rather than minting an API token — see file header.
		$api_token = CWebUser::$data['sessionid'];

		$pdo_dsn = 'mysql:host='.$DB['SERVER'].';port='.$DB['PORT'].';dbname='.$DB['DATABASE'];
		if ($DB['TYPE'] === ZBX_DB_POSTGRESQL) {
			$pdo_dsn = 'pgsql:host='.$DB['SERVER'].';port='.$DB['PORT'].';dbname='.$DB['DATABASE'];
		}

		$php_bin = defined('PHP_BINARY') && PHP_BINARY !== '' ? PHP_BINARY : 'php';

		$command = implode(' ', array_map('escapeshellarg', [
			$php_bin, $ingest_script,
			'--api-url', $api_url,
			'--api-token', $api_token,
			'--pdo-dsn', $pdo_dsn,
			'--pdo-user', $DB['USER'],
			'--pdo-password', $DB['PASSWORD'],
			'--config', $config_path
		]));

		// Detached background process: stdout/stderr redirected to a log file (not left connected to
		// this short-lived web request), backgrounded with '&', and the shell itself detached via
		// nohup-less setsid-free `exec ... &` (proc_close() below never waits on it — see the comment
		// there) so it keeps running after this PHP-FPM/php-built-in-server request returns.
		$full_command = $command.' > '.escapeshellarg($log_file).' 2>&1 &';

		$process = proc_open($full_command, [], $pipes);
		if (is_resource($process)) {
			// Deliberately not proc_close()'d synchronously with a wait — proc_close() blocks until the
			// child exits, which would defeat "returns immediately". The trailing '&' in $full_command
			// already backgrounds the actual ingest.php process at the shell level; closing our handle
			// to the (already-returned) wrapper shell doesn't wait on the backgrounded grandchild.
			proc_close($process);
		}

		$this->setResponse(new CControllerResponseData([
			'main_block' => json_encode(['status' => 'started'])
		]));
	}
}
