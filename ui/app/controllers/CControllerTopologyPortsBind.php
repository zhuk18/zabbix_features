<?php

/**
 * POST topology.ports.bind {id, if_index} or {id, name} (2.99: an interface found by name) — bind a manual port to an interface of the host of its Device (topology-1-model.md §9.7 "Bind to interface"). Takes the run lock (FR-C O-14);
 * the refusals come back as a message for the operator.
 */
class CControllerTopologyPortsBind extends CController {
	use CTopologyJsonInput;

	protected function init(): void {
		$this->setPostContentType(self::POST_CONTENT_TYPE_JSON);
	}

	protected function checkInput(): bool {
		$ret = $this->validateJsonInput(['id' => 'required|id', 'if_index' => 'int32', 'name' => 'string']);

		// the index or the name (2.99): one of them is needed
		if ($ret && !$this->hasInput('if_index') && !$this->hasInput('name')) {
			$this->setResponse(new CControllerResponseData(['main_block' => json_encode(['error' => ['title' => _('Invalid request'), 'messages' => [_('The request has missing or malformed parameters.')]]])]));
			$ret = false;
		}

		return $ret;
	}

	protected function checkPermissions(): bool { return !CWebUser::isGuest(); }

	protected function doAction() {
		try {
			$result = CTopologyRun::guard(fn () => CTopologyPrototype::bindPort($this->getInput('id'), $this->hasInput('if_index') ? (int) $this->getInput('if_index') : null,
				(string) CWebUser::$data['userid'], $this->hasInput('name') ? (string) $this->getInput('name') : null));

			$this->setResponse(new CControllerResponseData(['main_block' => json_encode(['success' => true] + $result)]));
		}
		catch (Exception $exception) {
			$this->setResponse(new CControllerResponseData(['main_block' => json_encode(['error' => ['messages' => [$exception->getMessage()]]])]));
		}
	}
}
