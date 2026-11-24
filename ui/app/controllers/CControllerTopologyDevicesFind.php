<?php

/**
 * Find a device (model 2.100 §12.1, FR-C V-24): the answer of the search field of the graph page. One query per input; nothing is written. The names, MACs and addresses in the answer come from
 * the network: the page escapes them.
 */
class CControllerTopologyDevicesFind extends CController {
	use CTopologyJsonInput;

	protected function init() {
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		return $this->validateJsonInput(['q' => 'required|string']);
	}

	protected function checkPermissions(): bool {
		return !CWebUser::isGuest();
	}

	protected function doAction() {
		$this->setResponse(new CControllerResponseData(['main_block' => json_encode(CTopologyPrototype::findDevices((string) $this->getInput('q', '')),
			JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE)]));
	}
}
