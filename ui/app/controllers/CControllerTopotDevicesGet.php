<?php

class CControllerTopotDevicesGet extends CController {
	protected function init(): void {
		$this->disableCsrfValidation();
	}
	protected function checkInput(): bool { return true; }
	protected function checkPermissions(): bool { return !CWebUser::isGuest(); }
	protected function doAction(): void {
		$this->setResponse(new CControllerResponseData(['main_block' => json_encode([
			'devices' => CTopologyTModel::getDevices(),
			'relations' => CTopologyTModel::getRelations()
		])]));
	}
}
