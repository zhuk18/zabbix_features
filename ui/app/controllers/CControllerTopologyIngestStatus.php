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
		// the run row (model §6.1): written by the run, whatever its temporary directory
		$status = CTopologyRun::status();
		$state = CTopologyRun::state();

		// The run summary (counts and lists of the whole installation) is for Super admin only (topology-5-access-control.md §3.10);
		// everyone else gets the state of the run and nothing else.
		if (CWebUser::getType() != USER_TYPE_SUPER_ADMIN) {
			$status = ['status' => $status['status'] ?? 'idle'];
		}
		// the request and the lock (FR-C V-21): a request waits, a run works, a requested run does not start within 5 minutes
		// "<N> reporters have new data since the last run" (FR-C V-21): a count of the whole installation, for Super admin only like the run summary
		if (CWebUser::getType() == USER_TYPE_SUPER_ADMIN) {
			$status['reporters_new_data'] = CTopologyRun::reportersNewData();
		}
		$status['requested_at'] = $state['requested_at'] ?: null;
		$status['requested'] = $state['requested'];
		$status['running'] = $state['locked'] && ($status['status'] ?? '') === 'running';
		$status['executor_missing'] = $state['executor_missing'];

		// done / done_with_warnings / failed (model §12.7): the list "Needs attention" of a finished run is in its summary
		$status['result'] = CTopologyRunSummary::result($status['status'] ?? null, is_array($status['summary'] ?? null) ? $status['summary'] : null);

		$this->setResponse(new CControllerResponseData(['main_block' => json_encode($status, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE)]));
	}
}
