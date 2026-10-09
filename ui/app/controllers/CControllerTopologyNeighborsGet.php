<?php

class CControllerTopologyNeighborsGet extends CController {
	use CTopologyJsonInput;

    protected function init() {
		$this->disableCsrfValidation();
	}	    
	protected function checkInput(): bool { return $this->validateJsonInput(['id' => 'required|id']); }
	protected function checkPermissions(): bool { return !CWebUser::isGuest(); }
	protected function doAction() { $this->setResponse(new CControllerResponseData(['main_block' => json_encode(['neighbors' => CTopologyPrototype::getNeighbors($this->getInput('id'))])])); }
}