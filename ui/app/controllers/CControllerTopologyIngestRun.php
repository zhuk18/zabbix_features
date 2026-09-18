<?php

/**
 * §14.2: POST /topology/ingest/run — starts a discovery ingest pass in the background and
 * returns immediately. Shells out to the same database/topology/discovery/ingest.php CLI
 * script used by an operator running it by hand, so the CLI and the UI button always go
 * through the exact one code path (and therefore the exact one run lock, held inside that
 * script) — this controller adds no locking of its own.
 */
class CControllerTopologyIngestRun extends CController {
	protected function init(): void {
		$this->setPostContentType(self::POST_CONTENT_TYPE_JSON);
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		return true;
	}

	protected function checkPermissions(): bool {
		return !CWebUser::isGuest();
	}

	protected function doAction(): void {
		$script = realpath(__DIR__.'/../../../database/topology/discovery/ingest.php');
		$log = sys_get_temp_dir().'/topology-tags-ingest.log';

		// Backgrounded: '&' plus redirected stdio, so this HTTP request never blocks on a run
		// that may cover many reporters (§14.2 "must never block the HTTP request"). The lock
		// file inside ingest.php itself is what actually prevents two overlapping runs — this
		// call always attempts to start one; if a run is already in progress, the spawned
		// process just exits immediately via its own fail() and the log records that.
		exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($script).
			' > '.escapeshellarg($log).' 2>&1 &');

		$this->setResponse(new CControllerResponseData([
			'main_block' => json_encode(['status' => 'started'])
		]));
	}
}
