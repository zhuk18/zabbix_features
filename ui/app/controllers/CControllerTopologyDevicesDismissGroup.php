<?php

/**
 * POST topology.devices.dismissgroup {ids} — "Not the same device" for a whole group of Devices in one action (topology-1-model.md §6.7.5, 2.48): every pair of the group, as
 * topology.devices.dismissduplicate for each pair. Revoke stays per pair (topology.devices.dismissduplicate with revoke). The same permission as manual link editing.
 */
class CControllerTopologyDevicesDismissGroup extends CController {
	use CTopologyJsonInput;

	protected function init(): void {
		$this->setPostContentType(self::POST_CONTENT_TYPE_JSON);
	}

	protected function checkInput(): bool {
		return $this->validateJsonInput(['ids' => 'required|array']);
	}

	protected function checkPermissions(): bool { return !CWebUser::isGuest(); }

	protected function doAction() {
		try {
			$ids = array_filter((array) $this->getInput('ids'), static fn ($id): bool => (is_string($id) && ctype_digit($id)) || is_int($id));
			$result = CTopologyPrototype::dismissGroup(array_map('strval', $ids), (string) CWebUser::$data['username']);
			$this->setResponse(new CControllerResponseData(['main_block' => json_encode(['success' => true] + $result)]));
		}
		catch (Exception $exception) {
			$this->setResponse(new CControllerResponseData(['main_block' => json_encode([
				'error' => ['messages' => [$exception->getMessage()]]
			])]));
		}
	}
}
