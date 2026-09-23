<?php

class CControllerTopotPromote extends CController {
	protected function init(): void {
		$this->setPostContentType(self::POST_CONTENT_TYPE_JSON);
		$this->disableCsrfValidation();
	}
	protected function checkInput(): bool {
		return $this->validateInput(['device_id' => 'required|string', 'hostid' => 'required|id']);
	}
	protected function checkPermissions(): bool { return !CWebUser::isGuest(); }
	protected function doAction(): void {
		try {
			CTopologyTModel::promote($this->getInput('device_id'), $this->getInput('hostid'));
			$this->setResponse(new CControllerResponseData(['main_block' => json_encode(['success' => true])]));
		}
		catch (CTopologyTModelConflictException $exception) {
			// §6: "on a mismatch it returns 409 Conflict" -- this MVC layer's JSON controllers have
			// no HTTP-status hook (every example in this codebase returns 200 with an embedded
			// 'error' payload, see CControllerTopologyPromote), so the 409 is surfaced as a
			// conflict:true flag instead; the frontend distinguishes it from an ordinary validation
			// error by that flag, not by transport status code. Documented deviation, not an
			// oversight -- t-model-findings.md's §6 write-path note.
			$this->setResponse(new CControllerResponseData(['main_block' => json_encode([
				'error' => ['messages' => [$exception->getMessage()], 'conflict' => true]
			])]));
		}
		catch (Exception $exception) {
			$this->setResponse(new CControllerResponseData(['main_block' => json_encode([
				'error' => ['messages' => [$exception->getMessage()]]
			])]));
		}
	}
}
