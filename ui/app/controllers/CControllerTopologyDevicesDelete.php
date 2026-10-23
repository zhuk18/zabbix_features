<?php

/**
 * POST topology.devices.delete {id} — Delete Device (topology-1-model.md §6.7.5, 2.47; FR-C O-13): the operator deletes a Device that has no association and no manual
 * link. The same permission as manual link editing (not a guest). The refusals come back as a message for the operator.
 */
class CControllerTopologyDevicesDelete extends CController {
	use CTopologyJsonInput;

	protected function init(): void {
		// POST with a JSON body that carries the page's CSRF token, like every other topology write endpoint
		$this->setPostContentType(self::POST_CONTENT_TYPE_JSON);
	}

	protected function checkInput(): bool {
		return $this->validateJsonInput(['id' => 'required|id']);
	}

	// the same permission as manual link editing
	protected function checkPermissions(): bool { return !CWebUser::isGuest(); }

	protected function doAction() {
		try {
			$result = CTopologyPrototype::deleteDevice($this->getInput('id'), (string) CWebUser::$data['username']);

			$this->setResponse(new CControllerResponseData(['main_block' => json_encode(['success' => true] + $result)]));
		}
		catch (Exception $exception) {
			$this->setResponse(new CControllerResponseData(['main_block' => json_encode([
				'error' => ['messages' => [$exception->getMessage()]]
			])]));
		}
	}
}
