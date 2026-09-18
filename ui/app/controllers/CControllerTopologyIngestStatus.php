<?php

/**
 * §14.2: GET /topology/ingest/status — polled by the UI while a run is in progress. Reads the
 * status file ingest.php maintains (started_at/finished_at/summary), written the same way
 * regardless of whether the run was triggered from the CLI or from CControllerTopologyIngestRun.
 */
class CControllerTopologyIngestStatus extends CController {
	protected function init(): void {
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		return true;
	}

	protected function checkPermissions(): bool {
		return !CWebUser::isGuest();
	}

	protected function doAction(): void {
		$status_file = sys_get_temp_dir().'/topology-tags-ingest-status.json';
		$status = is_file($status_file) ? json_decode((string) file_get_contents($status_file), true) : null;
		if (!is_array($status)) {
			$status = ['status' => 'idle', 'started_at' => null, 'finished_at' => null, 'summary' => null];
		}

		$this->setResponse(new CControllerResponseData(['main_block' => json_encode($status)]));
	}
}
