<?php

/**
 * POST topology.devices.merge {keep_id, remove_id} — merge two Devices (topology-1-model.md §6.7.5 "Duplicate candidates and operator merge"). Refused when both have a represented_by.
 */
class CControllerTopologyDevicesMerge extends CController {
	protected function init(): void {
		// POST with a JSON body that carries the page's CSRF token, like every other topology write endpoint
		$this->setPostContentType(self::POST_CONTENT_TYPE_JSON);
	}

	protected function checkInput(): bool {
		return $this->validateInput(['keep_id' => 'required|id', 'remove_id' => 'required|id']);
	}

	// the same permission as manual link editing
	protected function checkPermissions(): bool { return !CWebUser::isGuest(); }

	protected function doAction() {
		try {
			$result = CTopologyPrototype::mergeDevices($this->getInput('keep_id'), $this->getInput('remove_id'), (string) CWebUser::$data['username']);

			$this->setResponse(new CControllerResponseData(['main_block' => json_encode(['success' => true] + $result)]));
		}
		catch (Exception $exception) {
			$this->setResponse(new CControllerResponseData(['main_block' => json_encode([
				'error' => ['messages' => [$exception->getMessage()]]
			])]));
		}
	}
}
