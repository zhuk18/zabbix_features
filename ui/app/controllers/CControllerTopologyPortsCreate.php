<?php

/**
 * POST topology.ports.create {device_id, name, description?} — add a port to a manual Device (§9.7).
 */
class CControllerTopologyPortsCreate extends CController {
	use CTopologyJsonInput;

	protected function init(): void {
		$this->setPostContentType(self::POST_CONTENT_TYPE_JSON);
	}

	protected function checkInput(): bool {
		return $this->validateJsonInput(['device_id' => 'required|id', 'name' => 'required|string', 'description' => 'string']);
	}

	// the same permission as manual link editing
	protected function checkPermissions(): bool { return !CWebUser::isGuest(); }

	protected function doAction() {
		try {
			// the run lock (FR-C O-14); the refusals come back as a message for the operator
			$result = CTopologyRun::guard(fn () => CTopologyPrototype::createPort($this->getInput('device_id'), array_diff_key($this->getInputAll(), ['device_id' => 1]), (string) CWebUser::$data['userid']));

			$this->setResponse(new CControllerResponseData(['main_block' => json_encode(['success' => true] + $result)]));
		}
		catch (Exception $exception) {
			$this->setResponse(new CControllerResponseData(['main_block' => json_encode([
				'error' => ['messages' => [$exception->getMessage()]]
			])]));
		}
	}
}
