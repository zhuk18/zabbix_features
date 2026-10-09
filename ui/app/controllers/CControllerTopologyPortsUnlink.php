<?php

class CControllerTopologyPortsUnlink extends CController {
	use CTopologyJsonInput;

	protected function init(): void {
		$this->setPostContentType(self::POST_CONTENT_TYPE_JSON);
	}
	protected function checkInput(): bool { return $this->validateJsonInput(['id' => 'required|id', 'dst_id' => 'required|id']); }
	protected function checkPermissions(): bool { return !CWebUser::isGuest(); }
	protected function doAction() {
		CTopologyPrototype::unlinkPorts($this->getInput('id'), $this->getInput('dst_id'));
		$this->setResponse(new CControllerResponseData(['main_block' => json_encode(['success' => true])]));
	}
}
