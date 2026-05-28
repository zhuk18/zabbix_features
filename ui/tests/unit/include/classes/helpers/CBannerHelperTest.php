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


use PHPUnit\Framework\TestCase;

class CBannerHelperTest extends TestCase {

	private const PRIVATE_KEY_PEM = <<<'PEM'
-----BEGIN PRIVATE KEY-----
MIIEvQIBADANBgkqhkiG9w0BAQEFAASCBKcwggSjAgEAAoIBAQDj3pIzx2QKtRRL
ipcV3mF1JpGUHE451ICn57lIolbN76Xkk9h9kr66vb5Kj6xE7UVnW20vi3mGzgNc
aVx7XAzfgTsdu2JikC2gLM5t/wJ6dtUpILuVgNMBiDjN1FXVjaUZt+zW11tQSp8I
d6Moqh/4Zo+B/58l0XgyGpxSIseLX6ZCGxAFfAG0VCjGuLpeOQpFwrzbri072Avn
Hl2o+aFALZzEyLzxfV14MN6ISEpDFzVpCG36C/RfiuzR7B+ZDJHnDoNQr8ALpYqO
FciSJwFW9jErCU+gem38xhjU7d0+UiwMtgCZL7S7K7HPMX2eRUE8N1VyJLoDae21
bFYw4NmrAgMBAAECggEAXkTfViV4eUeFgGTy8TyhM+6DTnNRV3JW0V+3ktl6MNMi
XcheSUDyv92sgjsi6RcB3esAZil82qySzmHWPUCNIM/dTYUOhDkibv/qXK4hb9mG
gO/GOujjImChI2HjKqhhM50YEZ9havucBEw+Rx3ugPypBP5j8CjS4WOJ5R73T2w3
+l7hd1OBu8WXUY8+rG5ak97ZkRklfowP2Grdr4Wkw5GbCrfTmW77RSsNEkNlOebF
UvhQkejNb+fHQ1XQo20uMI+0M9V6EcMS11nVIT884MMzU3t72YgjflTy48Cox4Fi
8v4u9S8tyJu5BuON3Q2VewMJ1bLP4oSXwU/4L+vLGQKBgQD/ls0xZ6V1YXNP5CRW
nI1AepF9UC5UZoB3zGCGaKr/6nV7gNHN6CAWYawSXOKvWPZl88OSJ2fG+ho6+WQy
S1Nve2MvQtMCjYsVjj9DI3VZg+UyGoxRE32hlSpfSdsPuqPaO8qk1XoS+ey8jkIL
jhNmIwBCpyvA+FPe/iI3Fv8rYwKBgQDkPFxBmLdKFGzImJEcbc94Uye3i6mh36It
WvSfhlYdeS4xgyl9Gb2xtQovM09J6sZlIfIM/ZqwfmEsynTrkZXmZ3CvePtM9EZs
YlYz5hYaxeh0p7OBYYB4Bnt2r7CJw/Yh2LBiITa1/wCO8TqPY7hsxpAs9G1HwmHE
RmlPEqr/GQKBgQD/Jvunc5IREXz9Z4MDV/wHP5UYpb/qj/12GvjNlZYIL3ajGaHZ
Tf8ieNU/66x9YnFwrB40PNR0Jl+jOi7Vqq8bnvEQUES4yrbriPsMukw/VdWr5Cbq
FWwYsAIB6IghNrC2f3Q4g8j/QrMcNWQnhulE0HJFGAAs/3szJT7hAjswgwKBgHHx
KGWfJjIXjE+Ay0EUGTWK3hMl6GPlz4MxG1rgp/FC5CrXvki0Jx2mshTqWrUePjmS
/tI5cZaXIVBJKqHIJrvF/F292keK0/WcCkkSnwpyryA98MGwuYAyTETuZQYCDMjM
8xGqXzPwwIicKY4YTKQRZTzsMfpXMpPYSw6s1S1RAoGAcfOZz203NNY7k52vk3cw
Pi2lMd0D4IXx6xLEfz9YErDsnPC3E5qk6oiGyR1k32s3ONqIkeArUG9HfUXIiuKJ
fDqpwd2hY2VtZdmjqZJHrqwuBj75SUSfcciYO99UFRZ0CpUnUZJ1hsV4JRf/fzYy
SjQZXQFAOZ2hW7FO/Sc2+cU=
-----END PRIVATE KEY-----
PEM;

	public function testVerifyValidSignature(): void {
		$banners = [
			[
				'id' => 'test_banner',
				'from' => '2026-01-01T00:00:00Z',
				'to' => '2099-01-01T00:00:00Z',
				'content' => ['all' => '**test**']
			]
		];

		$payload = CBannerHelper::getPayload($banners);
		$this->assertNotSame('', $payload);

		$private_key = openssl_pkey_get_private(self::PRIVATE_KEY_PEM);
		$this->assertNotFalse($private_key);

		$signature_bin = '';
		$ok = openssl_sign($payload, $signature_bin, $private_key, OPENSSL_ALGO_SHA256);
		openssl_free_key($private_key);

		$this->assertTrue($ok);

		$signature = base64_encode($signature_bin);
		$this->assertTrue(CBannerHelper::verify($banners, $signature));
	}

	public function testVerifyTamperedPayloadFails(): void {
		$banners = [
			[
				'id' => 'test_banner',
				'from' => '2026-01-01T00:00:00Z',
				'to' => '2099-01-01T00:00:00Z',
				'content' => ['all' => '**test**']
			]
		];

		$payload = CBannerHelper::getPayload($banners);

		$private_key = openssl_pkey_get_private(self::PRIVATE_KEY_PEM);
		$signature_bin = '';
		openssl_sign($payload, $signature_bin, $private_key, OPENSSL_ALGO_SHA256);
		openssl_free_key($private_key);

		$signature = base64_encode($signature_bin);

		$banners[0]['content']['all'] = '**tampered**';

		$this->assertFalse(CBannerHelper::verify($banners, $signature));
	}

	public function testVerifyInvalidBase64Fails(): void {
		$this->assertFalse(CBannerHelper::verify([], '*not-base64*'));
	}
}

