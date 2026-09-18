<?php

class CControllerTopologyDepromote extends CController {
	protected function init(): void {
		$this->setPostContentType(self::POST_CONTENT_TYPE_JSON);
		$this->disableCsrfValidation();
	}
	protected function checkInput(): bool { return $this->validateInput(['hostid' => 'required|id']); }
	protected function checkPermissions(): bool { return !CWebUser::isGuest(); }
	protected function doAction(): void { CTopologyPrototype::depromote($this->getInput('hostid')); $this->setResponse(new CControllerResponseData(['main_block' => json_encode(['success' => true])])); }
}
