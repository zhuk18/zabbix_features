<?php

/**
 * POST topology.ports.interfaces {id} — the interfaces of the host of the Device of a manual port (topology-1-model.md §9.7 "Bind to interface", §11): name, index, the item ids and the Port of the
 * Device that has the index, if any. Only for a manual port of an associated Device. Read only: no run lock.
 */
class CControllerTopologyPortsInterfaces extends CController {
	use CTopologyJsonInput;

	protected function init(): void {
		$this->setPostContentType(self::POST_CONTENT_TYPE_JSON);
	}

	protected function checkInput(): bool {
		return $this->validateJsonInput(['id' => 'required|id']);
	}

	protected function checkPermissions(): bool { return !CWebUser::isGuest(); }

	protected function doAction() {
		try {
			$this->setResponse(new CControllerResponseData(['main_block' => json_encode(['interfaces' => CTopologyPrototype::portInterfaces($this->getInput('id'))])]));
		}
		catch (Exception $exception) {
			$this->setResponse(new CControllerResponseData(['main_block' => json_encode(['error' => ['messages' => [$exception->getMessage()]]])]));
		}
	}
}
