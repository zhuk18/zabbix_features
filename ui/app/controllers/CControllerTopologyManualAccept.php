<?php

/**
 * POST topology.manual.accept — "accept discovery" for a manual link contradicted by discovery
 * (topology-1-model.md "Operator actions": accept discovery): the manual link is deleted. The discovered link is then decided by a
 * full ingest from the stored snapshots (a superseded one is revived, keeping its id), which this action starts itself as the
 * system process (CTopologyIngest::startWhenIdle) — after any run already in progress, because a second run started meanwhile
 * loses the run lock and exits, and the running one may have read the manual link before it was deleted.
 */
class CControllerTopologyManualAccept extends CController {
	use CTopologyJsonInput;

	protected function init(): void {
		// POST with a JSON body that carries the page's CSRF token (_csrf_token, the one for the `topology` section):
		// a GET, a cross-site form or a text/plain fetch does not have it and is rejected by CController.
		$this->setPostContentType(self::POST_CONTENT_TYPE_JSON);
	}

	protected function checkInput(): bool { return $this->validateJsonInput(['edge_id' => 'required|id']); }

	// The same permission as editing a manual link today (ports.link / ports.unlink).
	protected function checkPermissions(): bool { return !CWebUser::isGuest(); }

	protected function doAction() {
		try {
			CTopologyRun::guard(fn () => CTopologyPrototype::acceptDiscovery($this->getInput('edge_id')));
			// The discovered link is decided by a full run. The UI does not execute it (model §6.1): it records a request, for a user who may not request a run himself (ingest.run is
			// Super admin only) too, and the change applies at the next run of the scheduled CLI job.
			CTopologyRun::request();

			$this->setResponse(new CControllerResponseData(['main_block' => json_encode(['success' => true, 'ingest' => 'requested',
				'message' => _('The change applies at the next run of the reconciliation.')])]));
		}
		catch (Exception $exception) {
			$this->setResponse(new CControllerResponseData(['main_block' => json_encode([
				'error' => ['messages' => [$exception->getMessage()]]
			])]));
		}
	}
}
