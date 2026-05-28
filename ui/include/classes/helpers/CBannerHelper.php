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


class CBannerHelper {

	private const PUBLIC_KEY_PATH = __DIR__.'/../../data/banner_signing_public.pem';

	private static ?string $public_key_pem = null;

	public static function getPayload(array $banners): string {
		$payload = json_encode(
			['banners' => $banners],
			JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
		);

		return ($payload === false) ? '' : $payload;
	}

	private static function getPublicKeyPem(): string {
		if (self::$public_key_pem !== null) {
			return self::$public_key_pem;
		}

		$pem = @file_get_contents(self::PUBLIC_KEY_PATH);

		self::$public_key_pem = ($pem === false) ? '' : $pem;

		return self::$public_key_pem;
	}

	public static function verify(array $banners, string $signature): bool {
		$signature_bin = base64_decode($signature, true);
		if ($signature_bin === false) {
			return false;
		}

		$payload = self::getPayload($banners);
		if ($payload === '') {
			return false;
		}

		$public_key_pem = self::getPublicKeyPem();
		if ($public_key_pem === '') {
			return false;
		}

		$public_key = @openssl_pkey_get_public($public_key_pem);
		if ($public_key === false) {
			return false;
		}

		try {
			return openssl_verify($payload, $signature_bin, $public_key, OPENSSL_ALGO_SHA256) === 1;
		}
		finally {
			openssl_free_key($public_key);
		}
	}
}

