# Topology tags-model prototype — gotchas log

Notes from building the tags-only topology model (`topology-tags-model-spec.md`). Kept here so the
next iteration doesn't re-discover these the hard way.

## 1. `host.get`'s `selectTags` output can't be fed straight back into `host.update`

`API::Host()->get(['selectTags' => 'extend'])` returns each tag as `{tag, value, automatic}` —
`automatic` is a read-only field (whether the tag was auto-created, e.g. by LLD). Passing that
object straight back as one of `host.update`'s `tags` entries is **silently rejected**: no
exception under this class's own test bootstrap (see #2 below) — `API::Host()->update()` just
returns `bool(false)` instead of the normal `['hostids' => [...]]` result, with no thrown
`APIException` to catch. If a `/promote` or `/depromote` call appears to succeed (no exception) but
the tag never actually lands on the host, this is the first thing to check: strip every fetched tag
down to just `['tag' => ..., 'value' => ...]` before ever including it in a write payload.

## 2. `host.update()` needs a LIST of host objects, even for exactly one host

`API::Host()->update(['hostid' => $id, 'tags' => $tags])` — a bare associative array, not wrapped
in an outer array — does not reliably work. `CHost::update()`'s internals (`updateForce()`) do
`foreach ($hosts as &$host)` and `array_column($hosts, 'hostid')`, both of which expect `$hosts` to
be a list of host objects. Always call `API::Host()->update([['hostid' => $id, 'tags' => $tags]])`
(note the double `[[`), matching how every other multi-object Zabbix API method is meant to be
called, rather than relying on any input-normalization to rescue a bare single object.

## 3. Bootstrapping a standalone PHP script against the full Zabbix API layer (not just DB)

`CTopologyPrototype` calls `API::Host()->update()`/`API::Host()->get()`/`API::Problem()->get()`
directly — testing it from a CLI script needs the full API authentication chain, not just a DB
connection. The minimal chain that works:

```php
chdir('/abs/path/to/ui');
$_SERVER['REMOTE_ADDR'] = '127.0.0.1'; // CWebUser::getIp() dereferences this unconditionally
require_once 'include/classes/core/APP.php';
APP::getInstance()->run(APP::EXEC_MODE_API); // sets up DB/config/API service factory
API::getWrapper()->auth = ['type' => 0]; // MUST be set before the first authenticated call
```

From there, either reuse a currently-active session:

```php
$session = DBfetch(DBselect("SELECT sessionid FROM sessions WHERE status=0 ORDER BY lastaccess DESC", 1));
CWebUser::checkAuthentication($session['sessionid']);
```

or — if `CUser::checkAuthentication()` itself fails because the running database's schema is older
than what this branch's PHP code expects (see #4 below) — populate the authenticated-user context
directly instead of going through a real session/DB lookup:

```php
$userdata = [
    'userid' => '1', 'username' => 'Admin', 'name' => '', 'surname' => '', 'url' => '',
    'autologin' => 0, 'autologout' => '15m', 'lang' => 'en_US', 'refresh' => '30s',
    'theme' => 'default', 'attempt_failed' => 0, 'attempt_ip' => '', 'attempt_clock' => 0,
    'rows_per_page' => 50, 'roleid' => 3, 'userdirectoryid' => null, 'type' => USER_TYPE_SUPER_ADMIN,
    'userip' => '127.0.0.1', 'sessionid' => 'test', 'gui_access' => GROUP_GUI_ACCESS_SYSTEM,
    'debug_mode' => false, 'mfaid' => 0, 'timezone' => 'default'
];
CWebUser::$data = $userdata;
CApiService::$userData = $userdata; // public static property — the API layer's real permission
                                     // gate, separate from (and not populated by) CWebUser::$data
```

Both `CWebUser::$data` (used by e.g. `CWebUser::getType()`, audit logging) and
`CApiService::$userData` (used by `CLocalApiClient::isAllowedMethod()` for the actual
per-method access check) need to be set — setting only one leaves API calls failing partway
through with a confusing, unrelated-looking error.

## 4. This dev DB's schema is older than this branch's PHP code expects

`CUser::checkAuthentication()` fails with `Unknown column 'default_maintenance_period' in 'SELECT'`
against the `users` table on this environment's live DB — the running MySQL schema predates a
column this branch's `include/db.inc.php` already queries for. This is a real DB-migration gap
(the DB needs the schema upgrade this branch's code assumes), not a topology-specific bug — it
blocks the *entire* Zabbix frontend through the normal web/session path, not just the topology
pages. Workaround #3's direct `$userData` population above sidesteps it for CLI-script testing
without needing to run a full DB schema upgrade first.

## 5. `class_exists()`/autoloading a new `include/classes/<dir>` needs registering in `ZBase.php`

Unlike namespaced classes, plain global class names (`CTopologyPrototype`, `CTopologyHopScope`,
etc.) are autoloaded from an **explicit list** of directories in
`ZBase::getIncludePaths()` — there is no recursive directory scan. Adding a new
`ui/include/classes/<name>/` directory with new classes in it does nothing until that path is
also added to the list in `getIncludePaths()`; the failure mode is a plain
`Class "CFoo" not found` fatal with no hint about *why* the autoloader didn't find a file that's
plainly sitting right there on disk.
