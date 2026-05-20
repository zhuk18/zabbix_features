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

			overlay.$dialogue[0].addEventListener('dialogue.submit', (e) => this.createProfile(e.detail));
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
				.catch(() => {
					clearMessages();
					addMessage(makeMessageBox('bad', [<?= json_encode(_('Unexpected server error.')) ?>]));
				});
		}
	};
</script>

