<?php

class CControllerTopologyPortLink extends CController {
	protected function init(): void {
		$this->setPostContentType(self::POST_CONTENT_TYPE_JSON);
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		return $this->validateInput([
			'hostid' => 'required|id',
			'if_index' => 'required|int32',
			'target_hostid' => 'required|id',
			'target_if_index' => 'required|int32'
		]);
	}

	protected function checkPermissions(): bool {
		return !CWebUser::isGuest();
	}

	protected function doAction(): void {
		try {
			CTopologyPrototype::linkPort($this->getInput('hostid'), (int) $this->getInput('if_index'),
				$this->getInput('target_hostid'), (int) $this->getInput('target_if_index'));
			$this->setResponse(new CControllerResponseData(['main_block' => json_encode(['success' => true])]));
		}
		catch (Exception $exception) {
			$this->setResponse(new CControllerResponseData(['main_block' => json_encode([
				'error' => ['messages' => [$exception->getMessage()]]
			])]));
		}
	}
}
