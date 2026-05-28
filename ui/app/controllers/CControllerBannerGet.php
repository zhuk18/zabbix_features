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


class CControllerBannerGet extends CController {

	protected function init(): void {
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		return true;
	}

	protected function checkPermissions(): bool {
		return !CWebUser::isGuest();
	}

	protected function doAction(): void {
		global $ZBX_FEATURE_FLAGS;

		$output = [
			'allow_banners' => $ZBX_FEATURE_FLAGS['banners_enabled'],
			'dismissed_banner_ids' => json_decode(CProfile::get('web.banner.dismissed_ids', '[]'), true),
			'language' => CWebUser::$data['lang'],
			'storage_idx' => 'web.banner.dismissed_ids'
		];

		$this->setResponse(new CControllerResponseData(['main_block' => json_encode($output)]));
	}
}
