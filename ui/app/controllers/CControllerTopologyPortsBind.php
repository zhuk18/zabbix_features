<?php

/**
 * POST topology.ports.bind {id, if_index} — bind a manual port to an interface of the host of its Device (topology-1-model.md §9.7 "Bind to interface"). Takes the run lock (FR-C O-14);
 * the refusals come back as a message for the operator.
 */
class CControllerTopologyPortsBind extends CController {
	use CTopologyJsonInput;

	protected function init(): void {
		$this->setPostContentType(self::POST_CONTENT_TYPE_JSON);
	}

	protected function checkInput(): bool {
		return $this->validateJsonInput(['id' => 'required|id', 'if_index' => 'required|int32']);
	}

	protected function checkPermissions(): bool { return !CWebUser::isGuest(); }

	protected function doAction() {
		try {
			$result = CTopologyRun::guard(fn () => CTopologyPrototype::bindPort($this->getInput('id'), (int) $this->getInput('if_index'), (string) CWebUser::$data['userid']));

			$this->setResponse(new CControllerResponseData(['main_block' => json_encode(['success' => true] + $result)]));
		}
		catch (Exception $exception) {
			$this->setResponse(new CControllerResponseData(['main_block' => json_encode(['error' => ['messages' => [$exception->getMessage()]]])]));
		}
	}
}
