# Topology prototype — gotchas log

Notes from building `Host`/`Proxy` support in the topology prototype. Kept here so the
next iteration (or the real collector work) doesn't re-discover these the hard way.

## 1. `DBfetch()` turns SQL `NULL` into the string `'0'`

Zabbix's `DBfetch($cursor, $convertNulls = true)` (`ui/include/db.inc.php`) walks every
column of a fetched row and replaces `NULL` with `'0'`, by default, on every call site in
the whole codebase. This is fine for the common case (a column that's genuinely `0`), but
it silently destroys the one signal you need when checking "did this `LEFT JOIN` match
anything?":

```php
// mon.id is NULL when no monitored_by edge exists — or so you'd think.
' LEFT JOIN topo_edges mon ON mon.type='.zbx_dbstr('monitored_by').' AND mon.src_id=node.id'.
...
while ($row = DBfetch($result)) {
    // BUG: $row['mon_id'] is the string '0', never null, so this is always true.
    $blind_spot = $row['mon_id'] !== null && !isProxyOnline($row['mon_proxy_state']);
}
```

**Fix**: pass `DBfetch($result, false)` for any query where you need to distinguish "no
matching row" from "matched a row whose value happens to be 0". Since `topo_nodes`/
`topo_edges` ids are `AUTO_INCREMENT` starting at 1, `id IS NULL` vs `id = 0` is exactly
the distinction that matters for "does this edge exist".

**Symptom to watch for**: a boolean flag derived from a `LEFT JOIN ... IS NULL`-style
check in PHP that is *always true* (or always false), even though the equivalent raw SQL
`WHERE x IS NULL` run directly against the DB gives the right answer. If the SQL is right
but the PHP is wrong, suspect this.

## 2. Zabbix API host interfaces have no `mac` field

`host.get(['selectInterfaces' => 'extend'])` returns interfaces with
`hostid, type, ip, dns, port, useip, main, details, interface_ref, items, interfaceid` —
there is no `mac` key (see `ui/include/classes/api/services/CHostInterface.php`, the
`OUTPUT_FIELDS`-equivalent list). Code that does:

```php
if (empty($host_interface['mac'])) { continue; }
```

will *always* `continue` against a real Zabbix instance — it's not a bug that shows up as
an error, it's a feature that silently never fires. If a spec asks for "MAC-based
reconciliation" against `host.get` output, that data source doesn't exist; flag it rather
than building on top of an always-empty field. The same applies to `proxy.get` — proxies
have no interface/MAC concept in the API at all.

## 3. `hosts.proxyid` can be stale — `monitored_by` is the real source of truth

Since Zabbix added proxy groups, `hosts` has both `proxyid` (legacy single-proxy pointer)
and `monitored_by` (0 = server, 1 = proxy, 2 = proxy group — see
`ZBX_MONITORED_BY_*` in `defines.inc.php`). Observed in a live dev DB: a host with
`monitored_by = 0` (server-monitored) still had a non-NULL `proxyid` left over from a
previous proxy assignment. Checking `proxyid !== '0'` alone to decide "is this host proxy
routed" gives false positives. Always gate on `monitored_by === ZBX_MONITORED_BY_PROXY`.

## 4. Pull/sync logic needs a removal path, not just upsert

A "pull hosts from Zabbix" routine that only ever *adds* edges (e.g. `Host → Proxy` via
`monitored_by`) will accumulate stale edges forever once the real-world relationship
changes (host un-assigned from its proxy, re-assigned to a different one). Mirror the
`promote()`/`depromote()` pattern — DELETE-then-INSERT, or an explicit
`unlink...()` call in the "this is no longer true" branch — every time a pull can observe
a relationship that used to exist but doesn't anymore. An upsert-only sync is a one-way
ratchet; the "if not applicable, clean up" branch is not optional.

## 5. D3: filter `links` to nodes that actually exist before handing them to `forceLink`

If your node set is dynamically gated (e.g. only "linked"/promoted nodes are fetched by
default, with the rest sitting in a lazy-loaded tray), a `relations`/`edges` payload from
the backend can reference a node id that isn't in the currently-loaded node set yet.
`d3.forceLink(links).id(...)` throws `Error: node not found: <id>` and kills the whole
render if that happens — it doesn't degrade gracefully. Filter before constructing the
simulation:

```js
const simulation_links = this.links
    .filter(link => this.nodes.has(link.source) && this.nodes.has(link.target))
    .map(link => ({...link}));
```

This also means edges "activate" automatically as their endpoints get dragged onto the
canvas, with no extra bookkeeping needed.

## 6. OPcache + editing files under a live Apache dev server

`opcache.validate_timestamps=On` with `opcache.revalidate_freq=2` (this box's config)
means a saved `.php` change is picked up within ~2s — not instant, but not a real
blocker either. The actual trap in this session wasn't opcache: it was **timing against
the user's own action** — a "click X again" fix landed *after* the user's last click, so
the retry appeared to not work. Before declaring "still broken" after a fix, check file
mtimes against the relevant DB row's `updated_at`/edge `created_at` to confirm the fixed
code path actually ran, rather than re-guessing at the logic.

## 7. Schema template vs. live DB are two different things

Editing `create/src/schema.tmpl` (or the standalone `database/topology/mysql_schema.sql`)
only describes what a *fresh install* gets. A already-provisioned dev/test database needs
its own migration run (`mysql_migrate_*.sql` here) — editing the template does nothing to
existing tables. `Unknown column 'x' in 'ON'/'WHERE'` in the Apache error log against a
column you just added to the schema file is *always* this: the migration exists in the
repo but hasn't been executed against the actual running database yet.

## 8. Bootstrapping a standalone PHP script against Zabbix's DB layer

For quick "call this one static method and print the result" debugging (bypassing the
full HTTP/session/permission stack), the minimal chain is:

```php
spl_autoload_register(function ($class) {
    foreach (['include/classes/db', 'include/classes/api', 'include/classes/topology'] as $dir) {
        $file = "/abs/path/to/ui/$dir/$class.php";
        if (is_file($file)) { require_once $file; return; }
    }
});
require_once 'include/defines.inc.php';
require_once 'include/func.inc.php';
$DB = [];
require_once 'conf/zabbix.conf.php'; // must come AFTER defines.inc.php (uses its constants)
require_once 'include/db.inc.php';
DBconnect($error);
```

Two traps along the way:
- `zabbix.conf.php` references constants (e.g. `IMAGE_FORMAT_PNG`) defined in
  `defines.inc.php`, so include order matters.
- `DBconnect()` resolves the backend class (`MysqlDbBackend` / `PostgresqlDbBackend`) by
  name at runtime — a plain `require_once` list misses it unless you also pull in
  `classes/db/DbBackend.php` + the driver-specific subclass, or just register an
  autoloader as above.
- If the script lives in `/tmp` but you `cd` into the app directory first, `__DIR__`
  inside the script still resolves to `/tmp` (it's the *script's* path, not the cwd) —
  build paths from the app's absolute path, not `__DIR__`, when the two differ.

**This minimal chain is enough for DB-only code (`DBselect`/`DBexecute`), but breaks the
moment the code under test calls `API::Host()->get()` or anything else through Zabbix's
own API layer** — `promote()`'s new target-existence check (§5/§6, lazy Host/Proxy node
creation) is exactly this case. The failure mode is a `getObject() on null` fatal from
deep inside `API::getApiService()`: the minimal chain above never calls
`API::setApiServiceFactory()`, which only real HTTP bootstrap (or the recipe below) sets
up. Reaching for `CWebUser::$data = [...]` by hand does *not* fix this — the API layer
checks `CApiService::$userData` (set only by a real `CUser::checkAuthentication()` call
going through the wrapper) and `CApiWrapper::$auth['type']` (set only by the full request
bootstrap this script also skips), neither of which `CWebUser::$data` touches.

The actual working recipe, found by tracing the fatal down through
`CApiWrapper::callClientMethod()` → `CLocalApiClient::isAllowedMethod()`:

```php
chdir('/abs/path/to/ui');
$_SERVER['REMOTE_ADDR'] = '127.0.0.1'; // CWebUser::getIp() dereferences this unconditionally
require_once 'include/classes/core/APP.php';
APP::getInstance()->run(APP::EXEC_MODE_API); // sets up DB/config/API service factory
API::getWrapper()->auth = ['type' => 0]; // MUST be set before the first authenticated call —
                                          // real HTTP bootstrap does this, this doesn't
$session = DBfetch(DBselect("SELECT sessionid FROM sessions WHERE status=0 ORDER BY lastaccess DESC", 1));
CWebUser::checkAuthentication($session['sessionid']); // reuses an already-active session
                                                        // (e.g. a logged-in browser tab) —
                                                        // this is what actually populates
                                                        // CApiService::$userData
```

After this, `API::Host()->get()` etc. work exactly as they would from a real controller,
with that session's real permissions. If no session is currently active in the `sessions`
table, there's nothing to authenticate against — treat that as "skip this check," not a
bug, rather than trying to fabricate credentials.
