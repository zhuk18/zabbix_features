<?php

class CControllerTopologyDevicesGet extends CController {
	use CTopologyJsonInput;

    protected function init() {
		$this->disableCsrfValidation();
	}
	protected function checkInput(): bool {
		return $this->validateJsonInput([
			// topology-1-model.md §12.1 "View scope": the Hosts of the groups and the focus host are the start nodes, together.
			'groupids' => 'array_id',
			'hostid' => 'id',
			'hops' => 'int32'
		]);
	}
	protected function checkPermissions(): bool { return !CWebUser::isGuest(); }
	protected function doAction() {
		$groupids = $this->getInput('groupids', []);
		$hostid = (string) $this->getInput('hostid', '');
		// Depth: absent = no limit (§12.1); otherwise 1-6, the values of the page's Depth select.
		$hops = $this->hasInput('hops') ? min(6, max(1, (int) $this->getInput('hops'))) : null;

		$this->setResponse(new CControllerResponseData(['main_block' => json_encode(
			CTopologyPrototype::getView($groupids, $hostid, $hops),
			JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE
		)]));
	}
}
