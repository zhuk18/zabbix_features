## Mock signed banner service

Serves `GET /banners/v1` with `{ banners, signature, kid }` compatible with `CBannerHelper` (RS256, kid `v2` by default).

### Start

```bash
node ui/dev/mock-banners-server.js
```

### HTTPS (if Zabbix UI uses HTTPS)

```bash
PORT=8443 TLS_CERT=./localhost.pem TLS_KEY=./localhost-key.pem node ui/dev/mock-banners-server.js
```

### Point Zabbix UI to mock

```js
CBanner.URL = 'https://localhost:8443/banners/v1';
location.reload();
```

### Services contract (for production)

```json
{
  "banners": [ ... ],
  "signature": "<base64 RS256 over json_encode({\"banners\":...})>",
  "kid": "v2"
}
```

Public keys `v1` and `v2` are embedded in `CBannerHelper` (replace placeholders before release).
