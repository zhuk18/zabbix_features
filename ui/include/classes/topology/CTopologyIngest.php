<?php

/**
 * Starts database/topology/discovery/ingest.php as a detached background OS process — the one place that knows how,
 * shared by POST topology.ingest.run and by the manual-contradiction "accept discovery" action, so both go through
 * the same script and the same run lock (spec §6). See CControllerTopologyIngestRun's header for why the script is
 * spawned rather than reimplemented and how its credentials are sourced.
 */
class CTopologyIngest {

	/**
	 * @throws Exception when the run cannot even be spawned. A run that starts and then fails or loses the run lock
	 *                   is not seen here: callers find out from topology.ingest.status (run_id / started_at).
	 */
	public static function start(): void {
		global $DB;

		$discovery_dir = dirname(__DIR__, 4).'/database/topology/discovery';
		$ingest_script = $discovery_dir.'/ingest.php';
		if (!is_file($ingest_script)) {
			throw new Exception('The ingest script was not found: '.$ingest_script);
		}
		// Deliberately NOT under $discovery_dir (git-tracked source tree, typically owned by a human
		// deployer/operator) — this process runs as the web server's OS user (e.g. www-data), which a
		// real deployment's source directory commonly doesn't grant write access to. Confirmed the hard
		// way: with the log (and, before this fix, the lock/status files too — see ingest.php's own
		// comment on INGEST_LOCK_FILE/INGEST_STATUS_FILE) under $discovery_dir, a run spawned through
		// Apache/PHP-FPM silently failed to even start (the shell redirect below couldn't open the log
		// file for writing), while this endpoint still reported {"status":"started"} and the status
		// endpoint kept serving stale data from an earlier, different-OS-user run — the UI showed
		// "success" for a run that never happened. sys_get_temp_dir() matches ingest.php's own runtime
		// directory choice, so both land somewhere any OS user running either caller can write to.
		$log_file = sys_get_temp_dir().'/topology-ingest-run.log';

		// Deliberately always spawns rather than peeking at the status file first to decide "already
		// running, don't bother": ingest.php's own flock() (§6) is the actual source of truth on whether
		// a run is in progress, and it's cheap for a second spawn to lose that race and exit immediately.
		// Pre-checking the status file instead would risk a permanently-stuck "running" state if a prior
		// run ever died without reaching its own shutdown-triggered status write (e.g. `kill -9` on the
		// PHP process — flock() itself is always released by the OS on process death, but a stale status
		// read here wouldn't know that, whereas always attempting a fresh spawn self-heals it).

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
			'--pdo-password', $DB['PASSWORD']
		]));

		// Detached background process: stdout/stderr redirected to a log file (not left connected to
		// this short-lived web request), backgrounded with '&', and the shell itself detached via
		// nohup-less setsid-free `exec ... &` (proc_close() below never waits on it — see the comment
		// there) so it keeps running after this PHP-FPM/php-built-in-server request returns.
		$full_command = $command.' > '.escapeshellarg($log_file).' 2>&1 &';

		$process = proc_open($full_command, [], $pipes);
		if (!is_resource($process)) {
			throw new Exception('The ingest process could not be started (proc_open failed; is it disabled for the web server?)');
		}
		if (is_resource($process)) {
			// Deliberately not proc_close()'d synchronously with a wait — proc_close() blocks until the
			// child exits, which would defeat "returns immediately". The trailing '&' in $full_command
			// already backgrounds the actual ingest.php process at the shell level; closing our handle
			// to the (already-returned) wrapper shell doesn't wait on the backgrounded grandchild.
			proc_close($process);
		}
	}
}
