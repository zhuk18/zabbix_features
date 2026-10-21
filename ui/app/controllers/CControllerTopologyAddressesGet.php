<?php

/**
 * GET topology.addresses.get (topology-1-model.md §11 `/topo/devices/{id}/addresses`, §12.5 "Addresses") — the addresses of a Device as its neighbors report them,
 * with the reporters and their monitoring. Read only.
 *
 * Input: id (the Device). JSON only. Every string comes from the network (sysname, chassis id, host and port names) and is data: whoever renders it must escape
 * (§13); the JSON hex flags below only keep the payload inert if it is ever embedded in HTML.
 */
class CControllerTopologyAddressesGet extends CController {
	use CTopologyJsonInput;

	protected function init(): void {
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		return $this->validateJsonInput(['id' => 'required|id']);
	}

	protected function checkPermissions(): bool {
		return !CWebUser::isGuest();
	}

	protected function doAction(): void {
		$this->setResponse(new CControllerResponseData(['main_block' => json_encode(
			CTopologyPrototype::getAddresses((string) $this->getInput('id')),
			JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE
		)]));
	}
}
