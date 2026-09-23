<?php

class CControllerTopotPortUnlink extends CController {
	protected function init(): void {
		$this->setPostContentType(self::POST_CONTENT_TYPE_JSON);
		$this->disableCsrfValidation();
	}
	protected function checkInput(): bool {
		return $this->validateInput(['src' => 'required|string', 'dst' => 'required|string']);
	}
	protected function checkPermissions(): bool { return !CWebUser::isGuest(); }
	protected function doAction(): void {
		try {
			$result = CTopologyTModel::unlinkPorts($this->getInput('src'), $this->getInput('dst'));
			$this->setResponse(new CControllerResponseData(['main_block' => json_encode(['success' => true] + $result)]));
		}
		catch (Exception $exception) {
			$this->setResponse(new CControllerResponseData(['main_block' => json_encode([
				'error' => ['messages' => [$exception->getMessage()]]
			])]));
		}
	}
}
