<?php

/**
 * POST topology.link.convert — convert a MANUAL link in place (same edge id), topology-device-level-edge-spec.md §3.2:
 * far_port_id turns a device_link into a physical_link, keep_port_id turns a physical_link into a device_link.
 */
class CControllerTopologyLinkConvert extends CController {
	protected function init(): void {
		$this->setPostContentType(self::POST_CONTENT_TYPE_JSON);
	}

	protected function checkInput(): bool {
		return $this->validateInput(['id' => 'required|id', 'far_port_id' => 'id', 'keep_port_id' => 'id']);
	}

	protected function checkPermissions(): bool {
		return !CWebUser::isGuest();
	}

	protected function doAction() {
		try {
			CTopologyPrototype::convertManualLink($this->getInput('id'),
				$this->hasInput('far_port_id') ? $this->getInput('far_port_id') : null,
				$this->hasInput('keep_port_id') ? $this->getInput('keep_port_id') : null);
			$this->setResponse(new CControllerResponseData(['main_block' => json_encode(['success' => true])]));
		}
		catch (Exception $exception) {
			$this->setResponse(new CControllerResponseData(['main_block' => json_encode([
				'error' => ['messages' => [$exception->getMessage()]]
			])]));
		}
	}
}
