<?php

class CControllerTopologyView extends CController {
    protected function init() {
		$this->disableCsrfValidation();
	}	
    protected function checkInput(): bool { return true; }
	protected function checkPermissions(): bool { return !CWebUser::isGuest(); }
	protected function doAction() {
		$response = new CControllerResponseData([]);
		$response->setTitle(_('Topology prototype'));
		$this->setResponse($response);
	}
}