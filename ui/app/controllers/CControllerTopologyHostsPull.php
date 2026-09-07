<?php

class CControllerTopologyHostsPull extends CController {
	protected function init(): void {
		$this->setPostContentType(self::POST_CONTENT_TYPE_JSON);
		$this->disableCsrfValidation();
	}
	protected function checkInput(): bool { return true; }
	protected function checkPermissions(): bool { return !CWebUser::isGuest(); }
	protected function doAction() { $this->setResponse(new CControllerResponseData(['main_block' => json_encode(['hosts' => CTopologyPrototype::pullHosts()])])); }
}