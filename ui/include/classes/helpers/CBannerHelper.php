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

	public const KID_V1 = 'v1';
	public const KID_V2 = 'v2';

	/**
	 * Banner signing public keys indexed by key ID (kid).
	 * Replace placeholder keys with production keys provided by Zabbix Services.
	 */
	private const PUBLIC_KEYS_PEM = [
		self::KID_V1 => <<<'PEM'
-----BEGIN PUBLIC KEY-----
MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAwCTd71eM0sl7LvxvSXhd
K7U2YGeIcwgoe84/hJwVLK9yNBJnvvvXRezECw8eWp8Ul0DacbIxTP+aOSEdA1Xx
LFGSHwt/ie6X4Lf7j48cibHhH0mPGrnIjBqVnizyXOpV+CxP0N/aocHQN1z6QwZT
gNZKByff0v/1ZKiU1w7OCULTmv1tZT8tuYDy/zBssd7/EA3ZtpjySLn+1GsBac4B
FqDXwwLtSyLUqrAZxTRROqEMtplE09r02La/25jupohF/nsxKimDurUCwR/6SC+E
/JVFZtmsYhqJaSoEq0p60Lz/g2/HwYh+5cbSnuffBNy16VGKFNfT84TxWj+EFdLW
dwIDAQAB
-----END PUBLIC KEY-----
PEM,
		self::KID_V2 => <<<'PEM'
-----BEGIN PUBLIC KEY-----
MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEA496SM8dkCrUUS4qXFd5h
dSaRlBxOOdSAp+e5SKJWze+l5JPYfZK+ur2+So+sRO1FZ1ttL4t5hs4DXGlce1wM
34E7HbtiYpAtoCzObf8CenbVKSC7lYDTAYg4zdRV1Y2lGbfs1tdbUEqfCHejKKof
+GaPgf+fJdF4MhqcUiLHi1+mQhsQBXwBtFQoxri6XjkKRcK8264tO9gL5x5dqPmh
QC2cxMi88X1deDDeiEhKQxc1aQht+gv0X4rs0ewfmQyR5w6DUK/AC6WKjhXIkicB
VvYxKwlPoHpt/MYY1O3dPlIsDLYAmS+0uyuxzzF9nkVBPDdVciS6A2nttWxWMODZ
qwIDAQAB
-----END PUBLIC KEY-----
PEM
	];

	public static function getPayload(array $banners): string {
		$payload = json_encode(
			['banners' => $banners],
			JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
		);

		return ($payload === false) ? '' : $payload;
	}

	public static function verify(array $banners, string $signature, string $kid = ''): bool {
		$signature_bin = base64_decode($signature, true);
		if ($signature_bin === false) {
			return false;
		}

		$payload = self::getPayload($banners);
		if ($payload === '') {
			return false;
		}

		if ($kid !== '') {
			if (!array_key_exists($kid, self::PUBLIC_KEYS_PEM)) {
				return false;
			}

			return self::verifyWithPublicKeyPem($payload, $signature_bin, self::PUBLIC_KEYS_PEM[$kid]);
		}

		foreach (self::PUBLIC_KEYS_PEM as $public_key_pem) {
			if (self::verifyWithPublicKeyPem($payload, $signature_bin, $public_key_pem)) {
				return true;
			}
		}

		return false;
	}

	private static function verifyWithPublicKeyPem(string $payload, string $signature_bin, string $public_key_pem): bool {
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
