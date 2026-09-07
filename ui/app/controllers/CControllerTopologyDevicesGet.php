<?php

class CControllerTopologyDevicesGet extends CController {
    protected function init() {
		$this->disableCsrfValidation();
	}	
	protected function checkInput(): bool { return true; }
	protected function checkPermissions(): bool { return !CWebUser::isGuest(); }
	protected function doAction() {
		$this->setResponse(new CControllerResponseData(['main_block' => json_encode([
			'devices' => CTopologyPrototype::getDevices(),
			'relations' => CTopologyPrototype::getRelations(),
			'unassigned_hosts' => CTopologyPrototype::getUnassignedHosts(),
			'unassigned_proxies' => CTopologyPrototype::getUnassignedProxies()
		])]));
	}
}