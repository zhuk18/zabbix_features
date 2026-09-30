<?php

/**
 * POST topology.manual.revoke — removes one acknowledgment from a manual link's shadow_ack by Device
 * (topology-manual-contradiction-spec.md §5.3). If that neighbor still shadows the link it is contradicted again.
 */
class CControllerTopologyManualRevoke extends CController {
	protected function init(): void {
		// POST with a JSON body that carries the page's CSRF token (_csrf_token, the one for the `topology` section):
		// a GET, a cross-site form or a text/plain fetch does not have it and is rejected by CController.
		$this->setPostContentType(self::POST_CONTENT_TYPE_JSON);
	}

	protected function checkInput(): bool {
		return $this->validateInput(['edge_id' => 'required|id', 'device_id' => 'required|id']);
	}

	protected function checkPermissions(): bool { return !CWebUser::isGuest(); }

	protected function doAction() {
		try {
			CTopologyPrototype::revokeAcknowledgment($this->getInput('edge_id'), $this->getInput('device_id'));

			$this->setResponse(new CControllerResponseData(['main_block' => json_encode(['success' => true])]));
		}
		catch (Exception $exception) {
			$this->setResponse(new CControllerResponseData(['main_block' => json_encode([
				'error' => ['messages' => [$exception->getMessage()]]
			])]));
		}
	}
}
