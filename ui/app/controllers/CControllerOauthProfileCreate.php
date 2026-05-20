<?php declare(strict_types = 0);
/*
** Copyright (C) 2001-2026 Zabbix SIA
**
** This program is free software: you can redistribute it and/or modify it under the terms of
** the GNU Affero General Public License as published by the Free Software Foundation, version 3.
**
** This program is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY;
** without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
** See the GNU Affero General Public License for more details.
**
** You should have received a copy of the GNU Affero General Public License along with this program.
** If not, see <https://www.gnu.org/licenses/>.
**/


class CControllerOauthProfileCreate extends CController {

	protected function init(): void {
		$this->setInputValidationMethod(self::INPUT_VALIDATION_FORM);
		$this->setPostContentType(self::POST_CONTENT_TYPE_JSON);
		$this->disableCsrfValidation();
	}

	public static function getValidationRules(): array {
		return ['object', 'fields' => [
			'profile_name' => ['db oauth_profile.profile_name', 'required', 'not_empty'],
			'mode' => ['db oauth_profile.mode', 'required', 'not_empty'],
			'status' => ['db oauth_profile.status', 'in' => [MEDIA_TYPE_STATUS_ACTIVE, MEDIA_TYPE_STATUS_DISABLED]],

			'redirection_url' => ['db oauth_profile.redirection_url', 'required', 'not_empty'],
			'client_id' => ['db oauth_profile.client_id', 'required', 'not_empty'],
			'client_secret' => ['db oauth_profile.client_secret', 'required', 'not_empty'],
			'authorization_url' => ['db oauth_profile.authorization_url', 'required', 'not_empty'],
			'token_url' => ['db oauth_profile.token_url', 'required', 'not_empty'],

			'tokens_status' => ['db oauth_profile.tokens_status'],
			'access_token' => ['db oauth_profile.access_token'],
			'access_token_updated' => ['db oauth_profile.access_token_updated'],
			'access_expires_in' => ['db oauth_profile.access_expires_in'],
			'refresh_token' => ['db oauth_profile.refresh_token']
		]];
	}

	protected function checkInput(): bool {
		$ret = $this->validateInput(self::getValidationRules());

		if (!$ret) {
			$form_errors = $this->getValidationError();
			$response = $form_errors
				? ['form_errors' => $form_errors]
				: ['error' => [
					'title' => _('Cannot create OAuth profile'),
					'messages' => array_column(get_and_clear_messages(), 'message')
				]];

			$this->setResponse(new CControllerResponseData(['main_block' => json_encode($response)]));
		}

		return $ret;
	}

	protected function checkPermissions(): bool {
		return $this->checkAccess(CRoleHelper::UI_ADMINISTRATION_MEDIA_TYPES);
	}

	protected function doAction(): void {
		$profile = [
			'profile_name' => $this->getInput('profile_name'),
			'mode' => $this->getInput('mode'),
			'status' => $this->getInput('status', MEDIA_TYPE_STATUS_ACTIVE),
			'redirection_url' => $this->getInput('redirection_url'),
			'client_id' => $this->getInput('client_id'),
			'client_secret' => $this->getInput('client_secret'),
			'authorization_url' => $this->getInput('authorization_url'),
			'token_url' => $this->getInput('token_url'),
			'tokens_status' => $this->getInput('tokens_status', 0),
			'access_token' => $this->getInput('access_token', ''),
			'access_token_updated' => $this->getInput('access_token_updated', 0),
			'access_expires_in' => $this->getInput('access_expires_in', 0),
			'refresh_token' => $this->getInput('refresh_token', '')
		];

		DB::insert('oauth_profile', [$profile]);

		$this->setResponse(new CControllerResponseData([
			'main_block' => json_encode([
				'success' => [
					'title' => _('OAuth profile created')
				]
			])
		]));
	}
}

