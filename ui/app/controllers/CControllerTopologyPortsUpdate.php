<?php

/**
 * POST topology.ports.update {id, name?, description?} — rename a manual port or change its description (§9.7); a port with a link and a discovered port are refused.
 */
class CControllerTopologyPortsUpdate extends CController {
	use CTopologyJsonInput;

	protected function init(): void {
		$this->setPostContentType(self::POST_CONTENT_TYPE_JSON);
	}

	protected function checkInput(): bool {
		return $this->validateJsonInput(['id' => 'required|id', 'name' => 'string', 'description' => 'string']);
	}

	// the same permission as manual link editing
	protected function checkPermissions(): bool { return !CWebUser::isGuest(); }

	protected function doAction() {
		try {
			// the run lock (FR-C O-14); the refusals come back as a message for the operator
			$result = CTopologyRun::guard(fn () => CTopologyPrototype::updatePort($this->getInput('id'), array_diff_key($this->getInputAll(), ['id' => 1]), (string) CWebUser::$data['userid']));

			$this->setResponse(new CControllerResponseData(['main_block' => json_encode(['success' => true] + $result)]));
		}
		catch (Exception $exception) {
			$this->setResponse(new CControllerResponseData(['main_block' => json_encode([
				'error' => ['messages' => [$exception->getMessage()]]
			])]));
		}
	}
}
