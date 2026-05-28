#!/usr/bin/env node
/*
 * Minimal mock server for banner testing.
 *
 * Usage:
 *   node ui/dev/mock-banners-server.js
 *
 * Env:
 *   PORT=8443
 *   TLS_CERT=./localhost.pem
 *   TLS_KEY=./localhost-key.pem
 *   CORS_ORIGIN=*
 *   BANNERS_JSON='[{"id":"test","from":"2020-01-01T00:00:00Z","to":"2099-01-01T00:00:00Z","content":{"all":"Test banner"}}]'
 *
 * If TLS_CERT/TLS_KEY are provided, starts HTTPS. Otherwise starts HTTP.
 */

'use strict';

const http = require('http');
const https = require('https');
const fs = require('fs');

const PORT = Number(process.env.PORT || 8443);
const TLS_CERT = process.env.TLS_CERT || '';
const TLS_KEY = process.env.TLS_KEY || '';
const CORS_ORIGIN = process.env.CORS_ORIGIN || '*';

function getBanners() {
  if (!process.env.BANNERS_JSON) {
    return [
      {
        id: 'test_signed',
        from: '2020-01-01T00:00:00Z',
        to: '2099-12-31T23:59:59Z',
        content: { all: '**Mock banner** [link](https://example.com)' }
      }
    ];
  }

  const parsed = JSON.parse(process.env.BANNERS_JSON);
  if (!Array.isArray(parsed)) {
    throw new Error('BANNERS_JSON must be a JSON array.');
  }

  return parsed;
}

function handler(req, res) {
  if (req.method === 'OPTIONS') {
    res.writeHead(204, {
      'Access-Control-Allow-Origin': CORS_ORIGIN,
      'Access-Control-Allow-Methods': 'GET,OPTIONS',
      'Access-Control-Allow-Headers': 'Content-Type,Accept-Language'
    });
    return res.end();
  }

  if (req.method !== 'GET' || req.url !== '/banners/v1') {
    res.writeHead(404, { 'Content-Type': 'text/plain; charset=utf-8' });
    return res.end('Not found');
  }

  let banners;
  try {
    banners = getBanners();
  }
  catch (e) {
    res.writeHead(500, { 'Content-Type': 'application/json; charset=utf-8' });
    return res.end(JSON.stringify({ error: String(e && e.message ? e.message : e) }));
  }

  const body = JSON.stringify({ banners }, null, 2);

  res.writeHead(200, {
    'Content-Type': 'application/json; charset=utf-8',
    'Access-Control-Allow-Origin': CORS_ORIGIN,
    'Cache-Control': 'no-store'
  });
  res.end(body);
}

let server;
let proto = 'http';

if (TLS_CERT && TLS_KEY) {
  const cert = fs.readFileSync(TLS_CERT);
  const key = fs.readFileSync(TLS_KEY);
  server = https.createServer({ cert, key }, handler);
  proto = 'https';
}
else {
  server = http.createServer(handler);
}

server.listen(PORT, '0.0.0.0', () => {
  // eslint-disable-next-line no-console
  console.log(`Mock banner service running: ${proto}://localhost:${PORT}/banners/v1`);
  // eslint-disable-next-line no-console
  console.log(`CORS origin: ${CORS_ORIGIN}`);
});

