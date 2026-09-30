<?php

/**
 * POST topology.manual.keep — "keep manual" for one hidden neighbor of a manual link
 * (topology-manual-contradiction-spec.md §5.2): appends {device_id, remote_key, at, by} to the link's shadow_ack.
 * Idempotent: the same device twice is one entry.
 */
class CControllerTopologyManualKeep extends CController {
	protected function init(): void {
		$this->setPostContentType(self::POST_CONTENT_TYPE_JSON);
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		return $this->validateInput(['edge_id' => 'required|id', 'device_id' => 'required|id']);
	}

	protected function checkPermissions(): bool { return !CWebUser::isGuest(); }

	protected function doAction() {
		try {
			CTopologyPrototype::keepManual($this->getInput('edge_id'), $this->getInput('device_id'),
				(string) CWebUser::$data['username']
			);

			$this->setResponse(new CControllerResponseData(['main_block' => json_encode(['success' => true])]));
		}
		catch (Exception $exception) {
			$this->setResponse(new CControllerResponseData(['main_block' => json_encode([
				'error' => ['messages' => [$exception->getMessage()]]
			])]));
		}
	}
}
