<?php

class CControllerTopologyPortsUnlink extends CController {
	protected function init(): void {
		$this->setPostContentType(self::POST_CONTENT_TYPE_JSON);
		$this->disableCsrfValidation();
	}
	protected function checkInput(): bool { return $this->validateInput(['id' => 'required|id', 'dst_id' => 'required|id']); }
	protected function checkPermissions(): bool { return !CWebUser::isGuest(); }
	protected function doAction() {
		CTopologyPrototype::unlinkPorts($this->getInput('id'), $this->getInput('dst_id'));
		$this->setResponse(new CControllerResponseData(['main_block' => json_encode(['success' => true])]));
	}
}
