<?php

/**
 * POST topology.manual.accept — "accept discovery" for a manual link contradicted by discovery
 * (topology-manual-contradiction-spec.md §5.1): the manual link is deleted, then a full ingest is started so the
 * discovered link is decided from the stored snapshots (a superseded one is revived, keeping its id).
 *
 * The ingest runs in the background (same run lock as the CLI and the "Run discovery ingest" button), so the answer
 * is "started"; the page polls topology.ingest.status for the summary.
 */
class CControllerTopologyManualAccept extends CController {
	protected function init(): void {
		$this->setPostContentType(self::POST_CONTENT_TYPE_JSON);
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool { return $this->validateInput(['edge_id' => 'required|id']); }

	// The same permission as editing a manual link today (ports.link / ports.unlink).
	protected function checkPermissions(): bool { return !CWebUser::isGuest(); }

	protected function doAction() {
		try {
			CTopologyPrototype::acceptDiscovery($this->getInput('edge_id'));
			CTopologyIngest::start();

			$this->setResponse(new CControllerResponseData([
				'main_block' => json_encode(['success' => true, 'ingest' => 'started'])
			]));
		}
		catch (Exception $exception) {
			$this->setResponse(new CControllerResponseData(['main_block' => json_encode([
				'error' => ['messages' => [$exception->getMessage()]]
			])]));
		}
	}
}
