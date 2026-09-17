<?php

class CControllerTopologyPromote extends CController {
	protected function init(): void {
		$this->setPostContentType(self::POST_CONTENT_TYPE_JSON);
		$this->disableCsrfValidation();
	}	
	protected function checkInput(): bool {
		return $this->validateInput([
			'id' => 'required|id',
			'target_type' => 'required|string',
			'target_id' => 'required|id'
		]);
	}
	protected function checkPermissions(): bool { return !CWebUser::isGuest(); }
	protected function doAction() {
		try {
			CTopologyPrototype::promote($this->getInput('id'), $this->getInput('target_type'),
				$this->getInput('target_id'));
			$this->setResponse(new CControllerResponseData(['main_block' => json_encode(['success' => true])]));
		}
		catch (Exception $exception) {
			$this->setResponse(new CControllerResponseData(['main_block' => json_encode([
				'error' => ['messages' => [$exception->getMessage()]]
			])]));
		}
	}
}