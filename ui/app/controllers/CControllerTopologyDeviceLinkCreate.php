<?php

/**
 * POST topology.devicelink.create — a manual device-level link from a local Port to a Device whose port is not chosen
 * (topology-device-level-edge-spec.md §3.2). Same permission as a manual port-to-port link.
 */
class CControllerTopologyDeviceLinkCreate extends CController {
	protected function init(): void {
		$this->setPostContentType(self::POST_CONTENT_TYPE_JSON);
	}

	protected function checkInput(): bool {
		return $this->validateInput(['port_id' => 'required|id', 'device_id' => 'required|id']);
	}

	protected function checkPermissions(): bool {
		return !CWebUser::isGuest();
	}

	protected function doAction() {
		try {
			$edge_id = CTopologyPrototype::linkPortToDevice($this->getInput('port_id'), $this->getInput('device_id'),
				(string) CWebUser::$data['username']);
			$this->setResponse(new CControllerResponseData(['main_block' => json_encode(['success' => true, 'edge_id' => $edge_id])]));
		}
		catch (Exception $exception) {
			$this->setResponse(new CControllerResponseData(['main_block' => json_encode([
				'error' => ['messages' => [$exception->getMessage()]]
			])]));
		}
	}
}
