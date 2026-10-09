<?php

class CControllerTopologyHostsSearch extends CController {
	use CTopologyJsonInput;

    protected function init() {
		$this->disableCsrfValidation();
	}
	protected function checkInput(): bool { return $this->validateJsonInput(['q' => 'string']); }
	protected function checkPermissions(): bool { return !CWebUser::isGuest(); }
	protected function doAction() { $this->setResponse(new CControllerResponseData(['main_block' => json_encode(['hosts' => CTopologyPrototype::searchHosts($this->getInput('q', ''))])])); }
}
