<?php

class CControllerTopologyDepromote extends CController {
	use CTopologyJsonInput;

	protected function init(): void {
		$this->setPostContentType(self::POST_CONTENT_TYPE_JSON);
	}
	protected function checkInput(): bool { return $this->validateJsonInput(['id' => 'required|id']); }
	// A Device represented by a proxy: Super admin only, as the promotion to it
	protected function checkPermissions(): bool {
		return !CWebUser::isGuest()
			&& (CWebUser::getType() == USER_TYPE_SUPER_ADMIN || !CTopologyPrototype::isRepresentedByProxy($this->getInput('id')));
	}
	protected function doAction() { CTopologyPrototype::depromote($this->getInput('id')); $this->setResponse(new CControllerResponseData(['main_block' => json_encode(['success' => true])])); }
}
