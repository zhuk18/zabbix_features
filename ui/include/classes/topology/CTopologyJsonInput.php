<?php

/**
 * Input validation of the topology JSON endpoints: a refused request answers in JSON like every other refusal of these endpoints (access denied,
 * a wrong CSRF token: {"error": {"title", "messages"}}), never with a redirect to an HTML page.
 */
trait CTopologyJsonInput {

	protected function validateJsonInput(array $rules): bool {
		if ($this->validateInput($rules)) {
			return true;
		}

		$this->setResponse(new CControllerResponseData(['main_block' => json_encode([
			'error' => ['title' => _('Invalid request'), 'messages' => [_('The request has missing or malformed parameters.')]]
		])]));

		return false;
	}
}
