<?php

/**
 * POST topology.devices.create {name, device_class, chassis_id?, mgmt_ip?, ports?: [{name, description?}]} — create a manual Device (topology-1-model.md §9.7, 2.80; FR-C O-15). Answers the new Device id.
 */
class CControllerTopologyDevicesCreate extends CController {
	use CTopologyJsonInput;

	protected function init(): void {
		$this->setPostContentType(self::POST_CONTENT_TYPE_JSON);
	}

	protected function checkInput(): bool {
		return $this->validateJsonInput(['name' => 'required|string', 'device_class' => 'required|string', 'chassis_id' => 'string', 'mgmt_ip' => 'string', 'ports' => 'array']);
	}

	// the same permission as manual link editing
	protected function checkPermissions(): bool { return !CWebUser::isGuest(); }

	protected function doAction() {
		try {
			// the run lock (FR-C O-14); the refusals come back as a message for the operator
			$result = CTopologyRun::guard(fn () => CTopologyPrototype::createDevice($this->getInputAll(), (string) CWebUser::$data['userid']));

			$this->setResponse(new CControllerResponseData(['main_block' => json_encode(['success' => true] + $result)]));
		}
		catch (Exception $exception) {
			$this->setResponse(new CControllerResponseData(['main_block' => json_encode([
				'error' => ['messages' => [$exception->getMessage()]]
			])]));
		}
	}
}
