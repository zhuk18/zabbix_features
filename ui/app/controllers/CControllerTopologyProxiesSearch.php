<?php

class CControllerTopologyProxiesSearch extends CController {
    protected function init() {
		$this->disableCsrfValidation();
	}
	protected function checkInput(): bool { return $this->validateInput(['q' => 'string']); }
	protected function checkPermissions(): bool { return !CWebUser::isGuest(); }
	protected function doAction() { $this->setResponse(new CControllerResponseData(['main_block' => json_encode(['proxies' => CTopologyPrototype::searchProxies($this->getInput('q', ''))])])); }
}
