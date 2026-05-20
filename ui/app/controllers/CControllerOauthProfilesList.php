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


class CControllerOauthProfilesList extends CController {

	protected function init(): void {
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		$fields = [
			'sort' => 'in profile_name,status',
			'sortorder' => 'in '.ZBX_SORT_DOWN.','.ZBX_SORT_UP,
			'page' => 'ge 1'
		];

		$ret = $this->validateInput($fields);

		if (!$ret) {
			$this->setResponse(new CControllerResponseFatal());
		}

		return $ret;
	}

	protected function checkPermissions(): bool {
		return $this->checkAccess(CRoleHelper::UI_ADMINISTRATION_MEDIA_TYPES);
	}

	protected function doAction(): void {
		$sort_field = $this->getInput('sort', CProfile::get('web.oauth_profiles.sort', 'profile_name'));
		$sort_order = $this->getInput('sortorder', CProfile::get('web.oauth_profiles.sortorder', ZBX_SORT_UP));

		CProfile::update('web.oauth_profiles.sort', $sort_field, PROFILE_TYPE_STR);
		CProfile::update('web.oauth_profiles.sortorder', $sort_order, PROFILE_TYPE_STR);

		$data = [
			'sort' => $sort_field,
			'sortorder' => $sort_order
		];

		$sql_sort_fields = [
			'profile_name' => 'profile_name',
			'status' => 'status'
		];

		$order_by = array_key_exists($sort_field, $sql_sort_fields) ? $sql_sort_fields[$sort_field] : 'profile_name';
		$order_dir = ($sort_order === ZBX_SORT_DOWN) ? ZBX_SORT_DOWN : ZBX_SORT_UP;

		$limit = CSettingsHelper::get(CSettingsHelper::SEARCH_LIMIT) + 1;

		$db_result = DBselect(
			'SELECT profileid, profile_name, mode, status, tokens_status, access_expires_in, access_token_updated'.
				' FROM oauth_profile'.
				' ORDER BY '.$order_by.' '.$order_dir,
			$limit
		);

		$data['oauth_profiles'] = [];

		while ($row = DBfetch($db_result)) {
			$data['oauth_profiles'][] = $row;
		}

		$data['page'] = $this->getInput('page', 1);
		CPagerHelper::savePage('oauth.profiles', $data['page']);
		$data['paging'] = CPagerHelper::paginate($data['page'], $data['oauth_profiles'], $sort_order,
			(new CUrl('zabbix.php'))->setArgument('action', $this->getAction())
		);

		$response = new CControllerResponseData($data);
		$response->setTitle(_('OAuth profiles'));
		$this->setResponse($response);
	}
}

