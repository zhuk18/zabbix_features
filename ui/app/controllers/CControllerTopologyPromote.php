<?php

class CControllerTopologyPromote extends CController {
	protected function init(): void {
		$this->setPostContentType(self::POST_CONTENT_TYPE_JSON);
		$this->disableCsrfValidation();
	}	
	protected function checkInput(): bool { return $this->validateInput(['id' => 'required|id', 'host_id' => 'required|id']); }
	protected function checkPermissions(): bool { return !CWebUser::isGuest(); }
	protected function doAction() { CTopologyPrototype::promote($this->getInput('id'), $this->getInput('host_id')); $this->setResponse(new CControllerResponseData(['main_block' => json_encode(['success' => true])])); }
}