<?php

/**
 * GET /topo/ingest/status (topology-1-model.md "Run summary") — reads the status file ingest.php (database/topology/discovery/
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
		// the status file is the one of ingest.php (sys_get_temp_dir(), not the git-tracked discovery/ directory: see the constant INGEST_STATUS_FILE there)
		$status = CTopologyRunSummary::read();

		// The run summary (counts and lists of the whole installation) is for Super admin only (topology-5-access-control.md §3.10);
		// everyone else gets the state of the run and nothing else.
		if (CWebUser::getType() != USER_TYPE_SUPER_ADMIN) {
			$status = ['status' => $status['status'] ?? 'idle'];
		}

		// done / done_with_warnings / failed (model §12.7): the list "Needs attention" of a finished run is in its summary
		$status['result'] = CTopologyRunSummary::result($status['status'] ?? null, is_array($status['summary'] ?? null) ? $status['summary'] : null);

		$this->setResponse(new CControllerResponseData(['main_block' => json_encode($status, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE)]));
	}
}
