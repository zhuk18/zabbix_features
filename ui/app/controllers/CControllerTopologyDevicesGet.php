<?php

class CControllerTopologyDevicesGet extends CController {
	protected function init(): void {
		$this->disableCsrfValidation();
	}
	protected function checkInput(): bool {
		return $this->validateInput([
			// Hostgroup filter mode, ignored when 'hostid' is also set — a selected host overrides
			// the group selection.
			'groupids' => 'array_id',
			'hostid' => 'id',
			'hops' => 'int32'
		]);
	}
	protected function checkPermissions(): bool { return !CWebUser::isGuest(); }
	protected function doAction(): void {
		$groupids = $this->getInput('groupids', []);
		$hostid = (string) $this->getInput('hostid', '');
		// 1-6; a stray/garbage value falls back to 1 rather than silently expanding to an
		// unbounded walk.
		$hops = min(6, max(1, (int) $this->getInput('hops', 1)));

		$node_ids = CTopologyPrototype::resolveScope($groupids, $hostid, $hops);

		$this->setResponse(new CControllerResponseData(['main_block' => json_encode([
			'devices' => CTopologyPrototype::getDevices($node_ids),
			'relations' => CTopologyPrototype::getRelations($node_ids)
		])]));
	}
}
