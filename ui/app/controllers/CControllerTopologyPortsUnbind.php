<?php

/**
 * POST topology.ports.unbind {id} — clear the binding of a manual port to an interface (topology-1-model.md §9.7 "Bind to interface"); the port stays. Takes the run lock (FR-C O-14).
 */
class CControllerTopologyPortsUnbind extends CController {
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
			$result = CTopologyRun::guard(fn () => CTopologyPrototype::unbindPort($this->getInput('id'), (string) CWebUser::$data['userid']));

			$this->setResponse(new CControllerResponseData(['main_block' => json_encode(['success' => true] + $result)]));
		}
		catch (Exception $exception) {
			$this->setResponse(new CControllerResponseData(['main_block' => json_encode(['error' => ['messages' => [$exception->getMessage()]]])]));
		}
	}
}
