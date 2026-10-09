<?php

class CControllerTopologyPromote extends CController {
	use CTopologyJsonInput;

	protected function init(): void {
		$this->setPostContentType(self::POST_CONTENT_TYPE_JSON);
	}	
	protected function checkInput(): bool {
		return $this->validateJsonInput([
			'id' => 'required|id',
			'target_type' => 'required|string',
			'target_id' => 'required|id'
		]);
	}
	// Promote to a proxy: Super admin only (a proxy has no write permission for other users in Zabbix, topology-5-access-control.md §1, §4)
	protected function checkPermissions(): bool {
		return !CWebUser::isGuest() && ($this->getInput('target_type') !== 'proxy' || CWebUser::getType() == USER_TYPE_SUPER_ADMIN);
	}
	protected function doAction() {
		try {
			CTopologyPrototype::promote($this->getInput('id'), $this->getInput('target_type'),
				$this->getInput('target_id'));
			$this->setResponse(new CControllerResponseData(['main_block' => json_encode(['success' => true])]));
		}
		catch (Exception $exception) {
			$this->setResponse(new CControllerResponseData(['main_block' => json_encode([
				'error' => ['messages' => [$exception->getMessage()]]
			])]));
		}
	}
}