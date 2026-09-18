<?php

class CControllerTopologyHostsSearch extends CController {
	protected function init(): void {
		$this->disableCsrfValidation();
	}
	protected function checkInput(): bool { return $this->validateInput(['q' => 'string']); }
	protected function checkPermissions(): bool { return !CWebUser::isGuest(); }
	protected function doAction(): void { $this->setResponse(new CControllerResponseData(['main_block' => json_encode(['hosts' => CTopologyPrototype::searchHosts($this->getInput('q', ''))])])); }
}
