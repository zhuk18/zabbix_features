<?php

class CControllerTopotPortsGet extends CController {
	protected function init(): void {
		$this->disableCsrfValidation();
	}
	protected function checkInput(): bool { return $this->validateInput(['id' => 'required|string']); }
	protected function checkPermissions(): bool { return !CWebUser::isGuest(); }
	protected function doAction(): void {
		$this->setResponse(new CControllerResponseData(['main_block' =>
			json_encode(['groups' => CTopologyTModel::getPorts($this->getInput('id'))])]));
	}
}
