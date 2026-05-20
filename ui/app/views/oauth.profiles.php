<?php declare(strict_types = 0);
/*
** Copyright (C) 2001-2026 Zabbix SIA
**
** This program is free software: you can redistribute it and/or modify it under the terms of
** the GNU Affero General Public License as published by the Free Software Foundation, version 3.
**
** This program is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY;
** without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
**
** You should have received a copy of the GNU Affero General Public License along with this program.
**/


/**
 * @var CView $this
 * @var array $data
 */


$html_page = (new CHtmlPage())
	->setTitle(_('OAuth profiles'))
	->setDocUrl(CDocHelper::getUrl(CDocHelper::ALERTS_MEDIATYPE_LIST))
	->setControls((new CTag('nav', true,
		(new CList())
			->addItem(
				(new CSimpleButton(_('Create OAuth profile')))
					->setId('js-create')
					->onClick(
					'PopUp("oauth.edit", {
						advanced_form: 1,
						authorization_url: "https://accounts.google.com/o/oauth2/v2/auth?scope=https%3A%2F%2Fmail.google.com&access_type=offline&prompt=consent&response_type=code",
						token_url: "https://oauth2.googleapis.com/token?grant_type=authorization_code",
								mode: "authorization_code",
								create_profile: 1,
							rules_preset: "oauthprofile", '.
							CSRF_TOKEN_NAME.': "'.CCsrfTokenHelper::get('import').
						'"},{
							dialogueid: "popup_import",
							dialogue_class: "modal-popup-generic"
						});'
					)
					->setEnabled((bool) CMediatypeHelper::getSupportedMediaTypes())
					//->setEnabled((bool) COauthProfileHelper::getSupportedOAuthProfiles())
			)
		))->setAttribute('aria-label', _('Content controls'))
	)
/*		->addItem((new CFilter())
		->setResetUrl((new CUrl('zabbix.php'))->setArgument('action', 'oauth.profiles'))
		->setProfile($data['profileIdx'])
		->setActiveTab($data['active_tab'])
		->addFilterTab(_('Filter'), [
			(new CFormGrid())
				->addClass(CFormGrid::ZBX_STYLE_FORM_GRID_LABEL_WIDTH_TRUE)
				->addItem([
					new CLabel(_('Name'), 'filter_name'),
					new CFormField(
						(new CTextBox('filter_name', $data['filter']['name']))
							->setWidth(ZBX_TEXTAREA_FILTER_SMALL_WIDTH)
							->setAttribute('autofocus', 'autofocus')
					)
				]),
			(new CFormGrid())
				->addClass(CFormGrid::ZBX_STYLE_FORM_GRID_LABEL_WIDTH_TRUE)
				->addItem([
					new CLabel(_('Status')),
					new CFormField(
						(new CRadioButtonList('filter_status', (int) $data['filter']['status']))
							->addValue(_('Any'), -1)
							->addValue(_('Enabled'), MEDIA_TYPE_STATUS_ACTIVE)
							->addValue(_('Disabled'), MEDIA_TYPE_STATUS_DISABLED)
							->setModern()
					)
				]),
			(new CFormGrid())
				->addClass(CFormGrid::ZBX_STYLE_FORM_GRID_LABEL_WIDTH_TRUE)
				->addItem([
					new CLabel([_('Display actions'), makeHelpIcon([
						_('Filter actions by the scope of media type usage:'),
						(new CList([
							_('All').' - '._('display all actions'),
							[_('All available'), ' - ', make_decoration(
								_('display only actions where All available media types are used in action operation'),
								_('All available')
							)],
							_('Specific').' - '.
								_('display only actions where specific media type is used in action operation')
						]))->addClass(ZBX_STYLE_LIST_DASHED)
					])]),
					new CFormField(
						(new CRadioButtonList('filter_actions', (int) $data['filter']['actions']))
							->addValue(_('All'), ZBX_MEDIA_TYPE_ACTIONS_ALL)
							->addValue(_('All available'), ZBX_MEDIA_TYPE_ACTIONS_AVAILABLE)
							->addValue(_('Specific'), ZBX_MEDIA_TYPE_ACTIONS_SPECIFIC)
							->setModern()
					)
				])
		])
		->addVar('action', 'mediatype.list')
	)*/
	;

$view_url = (new CUrl('zabbix.php'))
	->setArgument('action', 'oauth.profiles')
	->getUrl();

$oauth_profiles_table = (new CTableInfo())
	->setHeader([
		make_sorting_header(_('Profile name'), 'profile_name', $data['sort'], $data['sortorder'], $view_url),
		_('Mode'),
		_('Expires in'),
		_('Last updated'),
		_('Status')
	])
	->setPageNavigation($data['paging']);

foreach ($data['oauth_profiles'] as $oauth_profile) {
	$status = $oauth_profile['status'] == MEDIA_TYPE_STATUS_ACTIVE
		? (new CSpan(_('Enabled')))->addClass(ZBX_STYLE_STATUS_GREEN)
		: (new CSpan(_('Disabled')))->addClass(ZBX_STYLE_STATUS_RED);

	if ($oauth_profile['tokens_status'] == 0) {
		$mode = _('Not authorized');
	}
	elseif ($oauth_profile['tokens_status'] == OAUTH_ACCESS_TOKEN_VALID) {
		$mode = _('Access token only');
	}
	elseif ($oauth_profile['tokens_status'] == OAUTH_REFRESH_TOKEN_VALID) {
		$mode = _('Refresh token only');
	}
	else {
		$mode = _('Access and refresh tokens valid');
	}

	$expires_in = $oauth_profile['access_expires_in'] > 0
		? _n('%1$s second', '%1$s seconds', $oauth_profile['access_expires_in'], $oauth_profile['access_expires_in'])
		: _('N/A');

	$last_updated = $oauth_profile['access_token_updated'] > 0
		? zbx_date2str(DATE_TIME_FORMAT_SECONDS, $oauth_profile['access_token_updated'])
		: _('N/A');

	$oauth_profiles_table->addRow([
		(new CCol($oauth_profile['profile_name']))->addClass(ZBX_STYLE_WORDBREAK),
		(new CCol($oauth_profile['mode'] === 'client_credentials'
			? _('Client credentials')
			: ($oauth_profile['mode'] === 'authorization_code'
				? _('Authorization code')
				: $oauth_profile['mode'])))
			->addClass(ZBX_STYLE_NOWRAP),
		(new CCol($expires_in))->addClass(ZBX_STYLE_NOWRAP),
		(new CCol($last_updated))->addClass(ZBX_STYLE_NOWRAP),
		$status
	]);
}

$html_page->addItem($oauth_profiles_table)->show();
