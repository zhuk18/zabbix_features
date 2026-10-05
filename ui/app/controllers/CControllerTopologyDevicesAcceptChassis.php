<?php

/**
 * POST topology.devices.acceptchassis {id} — accept the new chassis a reporter's host reports (topology-1-model.md §6.7.5: Accept new chassis).
 */
class CControllerTopologyDevicesAcceptChassis extends CController {
	protected function init(): void {
		// POST with a JSON body that carries the page's CSRF token, like every other topology write endpoint
		$this->setPostContentType(self::POST_CONTENT_TYPE_JSON);
	}

	protected function checkInput(): bool {
		return $this->validateInput(['id' => 'required|id']);
	}

	// the same permission as manual link editing
	protected function checkPermissions(): bool { return !CWebUser::isGuest(); }

	protected function doAction() {
		try {
			CTopologyPrototype::acceptChassis($this->getInput('id'), (string) CWebUser::$data['username']);

			$this->setResponse(new CControllerResponseData(['main_block' => json_encode(['success' => true])]));
		}
		catch (Exception $exception) {
			$this->setResponse(new CControllerResponseData(['main_block' => json_encode([
				'error' => ['messages' => [$exception->getMessage()]]
			])]));
		}
	}
}
