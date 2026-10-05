<?php

/**
 * POST topology.devices.dismissduplicate {a, b, revoke?} — "Not the same device" for a pair of Devices, or its revocation (topology-1-model.md §6.7.5).
 */
class CControllerTopologyDevicesDismissDuplicate extends CController {
	protected function init(): void {
		// POST with a JSON body that carries the page's CSRF token, like every other topology write endpoint
		$this->setPostContentType(self::POST_CONTENT_TYPE_JSON);
	}

	protected function checkInput(): bool {
		return $this->validateInput(['a' => 'required|id', 'b' => 'required|id', 'revoke' => 'in 0,1']);
	}

	// the same permission as manual link editing
	protected function checkPermissions(): bool { return !CWebUser::isGuest(); }

	protected function doAction() {
		try {
			if ($this->getInput('revoke', 0) == 1) {
				CTopologyPrototype::revokeDismissal($this->getInput('a'), $this->getInput('b'));
			}
			else {
				CTopologyPrototype::dismissDuplicate($this->getInput('a'), $this->getInput('b'), (string) CWebUser::$data['username']);
			}

			$this->setResponse(new CControllerResponseData(['main_block' => json_encode(['success' => true])]));
		}
		catch (Exception $exception) {
			$this->setResponse(new CControllerResponseData(['main_block' => json_encode([
				'error' => ['messages' => [$exception->getMessage()]]
			])]));
		}
	}
}
