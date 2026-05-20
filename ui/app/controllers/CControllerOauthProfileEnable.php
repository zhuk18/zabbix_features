<?php declare(strict_types=0);
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


class CControllerOauthProfileEnable extends CController {

	protected function init(): void {
		$this->setPostContentType(self::POST_CONTENT_TYPE_JSON);
	}

	protected function checkPermissions(): bool {
		return $this->checkAccess(CRoleHelper::UI_ADMINISTRATION_MEDIA_TYPES);
	}

	protected function checkInput(): bool {
		$fields = [
			'oauthprofileids' => 'required|array_db oauth_profile.oauthprofileid'
		];

		$ret = $this->validateInput($fields);

		if (!$ret) {
			$this->setResponse(
				new CControllerResponseData(['main_block' => json_encode([
					'error' => [
						'messages' => array_column(get_and_clear_messages(), 'message')
					]
				])])
			);
		}

		return $ret;
	}

	protected function doAction(): void {
		$output = [];
		$ids = $this->getInput('oauthprofileids');

		$upd = [];
		foreach ($ids as $id) {
			$upd[] = [
				'values' => ['status' => MEDIA_TYPE_STATUS_ACTIVE],
				'where' => ['oauthprofileid' => $id]
			];
		}

		DB::update('oauth_profile', $upd);

		$output['success']['title'] = _n('OAuth profile enabled', 'OAuth profiles enabled', count($ids));

		$this->setResponse(new CControllerResponseData(['main_block' => json_encode($output)]));
	}
}

