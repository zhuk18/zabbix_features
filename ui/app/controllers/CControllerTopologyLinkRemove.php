<?php

/**
 * POST topology.link.remove — remove a link by id, either type and either provenance. A discovered link is re-created
 * if it is reported again (FR Lifecycle 5c).
 */
class CControllerTopologyLinkRemove extends CController {
	protected function init(): void {
		$this->setPostContentType(self::POST_CONTENT_TYPE_JSON);
	}

	protected function checkInput(): bool {
		return $this->validateInput(['id' => 'required|id']);
	}

	protected function checkPermissions(): bool {
		return !CWebUser::isGuest();
	}

	protected function doAction() {
		try {
			CTopologyPrototype::removeLink($this->getInput('id'));
			$this->setResponse(new CControllerResponseData(['main_block' => json_encode(['success' => true])]));
		}
		catch (Exception $exception) {
			$this->setResponse(new CControllerResponseData(['main_block' => json_encode([
				'error' => ['messages' => [$exception->getMessage()]]
			])]));
		}
	}
}
