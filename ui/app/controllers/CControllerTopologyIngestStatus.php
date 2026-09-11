<?php

/**
 * GET /topo/ingest/status (spec §6) — reads the status file ingest.php (database/topology/discovery/
 * ingest.php) writes at start and end of a run. No state of its own: this controller is a thin reader,
 * same "logic lives elsewhere" shape as CControllerTopologyIngestRun.
 */
class CControllerTopologyIngestStatus extends CController {
	protected function init(): void {
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool { return true; }
	protected function checkPermissions(): bool { return !CWebUser::isGuest(); }

	protected function doAction() {
		$status_file = dirname(__DIR__, 3).'/database/topology/discovery/.ingest-status.json';

		$status = is_file($status_file) ? json_decode((string) file_get_contents($status_file), true) : null;
		if (!is_array($status)) {
			// Never run before (or the status file was removed) — §6's "idle" state.
			$status = ['status' => 'idle', 'started_at' => null, 'finished_at' => null, 'summary' => null];
		}

		$this->setResponse(new CControllerResponseData(['main_block' => json_encode($status)]));
	}
}
