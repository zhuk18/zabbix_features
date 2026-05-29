#!/usr/bin/env node
/*
 * Mock banner service with RS256 signing (kid v2).
 *
 * Usage:
 *   node ui/dev/mock-banners-server.js
 *
 * Env:
 *   PORT=8443
 *   TLS_CERT=./localhost.pem
 *   TLS_KEY=./localhost-key.pem
 *   CORS_ORIGIN=*
 *   SIGNING_KID=v2
 *   BANNERS_JSON='[...]'
 *   PRIVATE_KEY_PEM=/path/to/banner_private_key.pem  (optional; uses test key by default)
 */

'use strict';

const http = require('http');
const https = require('https');
const fs = require('fs');
const crypto = require('crypto');

const PORT = Number(process.env.PORT || 8443);
const TLS_CERT = process.env.TLS_CERT || '';
const TLS_KEY = process.env.TLS_KEY || '';
const CORS_ORIGIN = process.env.CORS_ORIGIN || '*';
const SIGNING_KID = process.env.SIGNING_KID || 'v2';

const DEFAULT_PRIVATE_KEY_PEM = `-----BEGIN PRIVATE KEY-----
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
-----END PRIVATE KEY-----`;

function nowIso() {
	return new Date().toISOString();
}

function logRequest(req) {
	// eslint-disable-next-line no-console
	console.log(
		`[${nowIso()}] req ${req.method} ${req.url} ` +
			`lang=${JSON.stringify(req.headers['accept-language'] || '')}`
	);
}

function logResponse(req, statusCode, body) {
	// eslint-disable-next-line no-console
	console.log(`[${nowIso()}] res ${req.method} ${req.url} status=${statusCode}`);
	// eslint-disable-next-line no-console
	console.log(`[${nowIso()}] body ${JSON.stringify(body)}`);
}

function getBanners() {
	if (!process.env.BANNERS_JSON) {
		return [{
			id: 'test_signed',
			from: '2020-01-01T00:00:00Z',
			to: '2099-12-31T23:59:59Z',
			content: {all: '**Mock signed banner** [link](https://example.com)'}
		}];
	}

	const parsed = JSON.parse(process.env.BANNERS_JSON);
	if (!Array.isArray(parsed)) {
		throw new Error('BANNERS_JSON must be a JSON array.');
	}

	return parsed;
}

function getPayload(banners) {
	return JSON.stringify({banners});
}

function signPayload(payload) {
	const private_key_pem = process.env.PRIVATE_KEY_PEM
		? fs.readFileSync(process.env.PRIVATE_KEY_PEM, 'utf8')
		: DEFAULT_PRIVATE_KEY_PEM;

	return crypto.sign('RSA-SHA256', Buffer.from(payload, 'utf8'), private_key_pem).toString('base64');
}

function handler(req, res) {
	logRequest(req);

	if (req.method === 'OPTIONS') {
		res.writeHead(204, {
			'Access-Control-Allow-Origin': CORS_ORIGIN,
			'Access-Control-Allow-Methods': 'GET,OPTIONS',
			'Access-Control-Allow-Headers': 'Content-Type,Accept-Language'
		});
		logResponse(req, 204);
		return res.end();
	}

	if (req.method !== 'GET' || req.url !== '/banners/v1') {
		res.writeHead(404, {'Content-Type': 'text/plain; charset=utf-8'});
		logResponse(req, 404, 'Not found');
		return res.end('Not found');
	}

	let banners;
	try {
		banners = getBanners();
	}
	catch (e) {
		const body = {error: String(e && e.message ? e.message : e)};
		res.writeHead(500, {'Content-Type': 'application/json; charset=utf-8'});
		logResponse(req, 500, body);
		return res.end(JSON.stringify(body));
	}

	const payload = getPayload(banners);
	const signature = signPayload(payload);
	const response = {banners, signature, kid: SIGNING_KID};

	res.writeHead(200, {
		'Content-Type': 'application/json; charset=utf-8',
		'Access-Control-Allow-Origin': CORS_ORIGIN,
		'Cache-Control': 'no-store'
	});
	logResponse(req, 200, response);
	res.end(JSON.stringify(response, null, 2));
}

let server;
let proto = 'http';

if (TLS_CERT && TLS_KEY) {
	server = https.createServer({
		cert: fs.readFileSync(TLS_CERT),
		key: fs.readFileSync(TLS_KEY)
	}, handler);
	proto = 'https';
}
else {
	server = http.createServer(handler);
}

server.listen(PORT, '0.0.0.0', () => {
	// eslint-disable-next-line no-console
	console.log(`Mock banner service: ${proto}://localhost:${PORT}/banners/v1 (kid=${SIGNING_KID})`);
});
