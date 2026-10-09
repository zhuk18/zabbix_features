<?php

/**
 * POST topology.devices.mergegroup (topology-1-model.md §6.7.5 "Merge a group"): keep_id, remove_ids[]. Same permission as the merge of two Devices.
 */
class CControllerTopologyDevicesMergeGroup extends CController {
	use CTopologyJsonInput;

	protected function init(): void {
		$this->setPostContentType(self::POST_CONTENT_TYPE_JSON);
	}

	protected function checkInput(): bool {
		return $this->validateJsonInput(['keep_id' => 'required|id', 'remove_ids' => 'required|array']);
	}

	protected function checkPermissions(): bool { return !CWebUser::isGuest(); }

	protected function doAction() {
		try {
			$ids = array_filter((array) $this->getInput('remove_ids'), static fn ($id): bool => is_string($id) || is_int($id));
			$result = CTopologyPrototype::mergeGroup((string) $this->getInput('keep_id'), array_map('strval', $ids), (string) CWebUser::$data['username']);
			$this->setResponse(new CControllerResponseData(['main_block' => json_encode(['success' => true] + $result)]));
		}
		catch (Exception $exception) {
			$this->setResponse(new CControllerResponseData(['main_block' => json_encode([
				'error' => ['messages' => [$exception->getMessage()]]
			])]));
		}
	}
}
