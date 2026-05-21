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
 */
?>

<script>
	const view = {
		csrf_token: null,

		init({csrf_token}) {
			this.csrf_token = csrf_token;

			const create = document.getElementById('js-create');

			if (create !== null) {
				create.addEventListener('click', () => this.openCreatePopup());
			}

			this.initMassActions();
			this.initEditLinks();
		},

		initEditLinks() {
			document.querySelectorAll('.js-edit-oauth-profile').forEach((link) => {
				link.addEventListener('click', (e) => {
					e.preventDefault();
					this.openEditPopup(link.getAttribute('data-oauthprofileid'));
				});
			});
		},

		openCreatePopup() {
			const overlay = PopUp('oauth.edit', {
				advanced_form: 1,
				update: 0,
				profile_name: '',
				mode: 'authorization_code',
				status: <?= MEDIA_TYPE_STATUS_ACTIVE ?>,
				authorization_url: 'https://accounts.google.com/o/oauth2/v2/auth',
				token_url: 'https://oauth2.googleapis.com/token'
			}, {dialogue_class: 'modal-popup-generic'});

			overlay.$dialogue[0].addEventListener('dialogue.submit', (e) => this.saveProfile(e.detail));
		},

		openEditPopup(oauthprofileid) {
			const overlay = PopUp('oauth.edit', {oauthprofileid}, {dialogue_class: 'modal-popup-generic'});

			overlay.$dialogue[0].addEventListener('dialogue.submit', (e) => this.saveProfile(e.detail));
		},

		saveProfile(fields) {
			const is_update = fields.oauthprofileid !== undefined && fields.oauthprofileid !== null
				&& fields.oauthprofileid !== '' && fields.oauthprofileid !== '0';

			if (is_update) {
				this.updateProfile(fields);
			}
			else {
				this.createProfile(fields);
			}
		},

		createProfile(fields) {
			const curl = new Curl('zabbix.php');
			curl.setArgument('action', 'oauth.profile.create');

			fetch(curl.getUrl(), {
				method: 'POST',
				headers: {'Content-Type': 'application/json'},
				body: JSON.stringify({
					...fields,
					[CSRF_TOKEN_NAME]: this.csrf_token
				})
			})
				.then((response) => response.json())
				.then((response) => {
					if ('error' in response) {
						throw {error: response.error};
					}

					if ('form_errors' in response) {
						throw {error: {title: null, messages: [<?= json_encode(_('Invalid OAuth profile.')) ?>]}};
					}

					if ('success' in response) {
						postMessageOk(response.success.title);
					}

					location.href = location.href;
				})
				.catch((exception) => this.showError(exception));
		},

		updateProfile(fields) {
			const curl = new Curl('zabbix.php');
			curl.setArgument('action', 'oauth.profile.update');

			fetch(curl.getUrl(), {
				method: 'POST',
				headers: {'Content-Type': 'application/json'},
				body: JSON.stringify({
					...fields,
					[CSRF_TOKEN_NAME]: this.csrf_token
				})
			})
				.then((response) => response.json())
				.then((response) => {
					if ('error' in response) {
						throw {error: response.error};
					}

					if ('form_errors' in response) {
						throw {error: {title: null, messages: [<?= json_encode(_('Invalid OAuth profile.')) ?>]}};
					}

					if ('success' in response) {
						postMessageOk(response.success.title);
					}

					location.href = location.href;
				})
				.catch((exception) => this.showError(exception));
		},

		showError(exception) {
			clearMessages();

			if (typeof exception === 'object' && exception !== null && 'error' in exception) {
				addMessage(makeMessageBox('bad', exception.error.messages ?? [], exception.error.title));
			}
			else {
				addMessage(makeMessageBox('bad', [<?= json_encode(_('Unexpected server error.')) ?>]));
			}
		},

		initMassActions() {
			const form = document.forms['oauth_profiles'];

			if (!form) {
				return;
			}

			form.querySelector('.js-massenable-oauth-profile')?.addEventListener('click', (e) => {
				this.massAction(e.target, 'oauth.profile.enable', <?= json_encode(_('Enable selected OAuth profiles?')) ?>);
			});

			form.querySelector('.js-massdisable-oauth-profile')?.addEventListener('click', (e) => {
				this.massAction(e.target, 'oauth.profile.disable', <?= json_encode(_('Disable selected OAuth profiles?')) ?>);
			});

			form.querySelector('.js-massdelete-oauth-profile')?.addEventListener('click', (e) => {
				this.massAction(e.target, 'oauth.profile.massdelete', <?= json_encode(_('Delete selected OAuth profiles?')) ?>);
			});
		},

		massAction(button, action, confirm_text) {
			const ids = Object.keys(chkbxRange.getSelectedIds());

			if (!ids.length) {
				return;
			}

			if (!confirm(confirm_text)) {
				return;
			}

			button.classList.add('is-loading');

			const curl = new Curl('zabbix.php');
			curl.setArgument('action', action);
			curl.setArgument(CSRF_TOKEN_NAME, this.csrf_token);

			fetch(curl.getUrl(), {
				method: 'POST',
				headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
				body: urlEncodeData({oauthprofileids: ids})
			})
				.then((response) => response.json())
				.then((response) => {
					clearMessages();

					if ('error' in response) {
						addMessage(makeMessageBox('bad', response.error.messages ?? [], response.error.title));
					}
					else if ('success' in response) {
						postMessageOk(response.success.title);
					}

					uncheckTableRows('oauth_profiles', response.keepids ?? []);
					location.href = location.href;
				})
				.catch(() => {
					clearMessages();
					addMessage(makeMessageBox('bad', [<?= json_encode(_('Unexpected server error.')) ?>]));
				})
				.finally(() => {
					button.classList.remove('is-loading');
				});
		}
	};
</script>

