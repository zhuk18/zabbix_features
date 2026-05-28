## Mock banner service (local testing)

This mock server serves a static banner JSON response compatible with `CBanner.URL`.

### Start (HTTP)

```bash
node ui/dev/mock-banners-server.js
```

It will print the URL, by default `http://localhost:8443/banners/v1`.

### Start (HTTPS)

If your Zabbix UI is loaded over HTTPS, the browser will typically block `http://...` as mixed content.
Provide a local TLS certificate and key:

```bash
PORT=8443 TLS_CERT=./localhost.pem TLS_KEY=./localhost-key.pem node ui/dev/mock-banners-server.js
```

### Override banner source in browser

In DevTools Console (for a test instance):

```js
CBanner.URL = 'https://localhost:8443/banners/v1';
location.reload();
```

### Customize banners

Pass a JSON array via `BANNERS_JSON`:

```bash
BANNERS_JSON='[{"id":"test","from":"2020-01-01T00:00:00Z","to":"2099-01-01T00:00:00Z","content":{"all":"Hello from mock"}}]' \
  node ui/dev/mock-banners-server.js
```

