---
name: zabbix-codebase-map
description: Map the Zabbix-like codebase (C backend + PHP UI), including key entry points, request routing, JSON-RPC API dispatch/auth, and where DB schema/upgrades live. Use when you need to decide whether a change belongs in UI controller/view, API service, DB schema/upgrade code, or server/agent C code.
target: Zabbix vX.Y.Z (branch `release/X.Y` or commit `<hash>`). Update this map when targeting other versions.
disable-model-invocation: true
---

# Zabbix codebase map

## Quick orientation

### Two major parts
- **C backend**: `src/` (server/proxy/agent + shared `libs/`)
- **PHP UI**: `ui/` (MVC controllers/views + JSON-RPC API)

## API and request flow (PHP UI)

### External JSON-RPC API endpoint
- **Entry**: `ui/api_jsonrpc.php`
  - Boots app in API mode: `APP::getInstance()->run(APP::EXEC_MODE_API)`
  - Creates JSON-RPC executor: `ui/include/classes/core/CJsonRpc.php`

### JSON-RPC execution + auth behavior
- **Executor**: `ui/include/classes/core/CJsonRpc.php`
  - Validates JSON-RPC request (`CApiInputValidator`)
  - Auth source:
    - If `Authorization: Bearer <token>` is present, use it.
    - If the token is invalid or expired, return HTTP 401 and do not fall back to the encrypted cookie session.
    - If the header is missing, authenticate via the encrypted cookie session (`CEncryptedCookieSession`).
    - If neither method succeeds, return HTTP 401.
  - Dispatches: `CApiClient->callMethod($api, $method, $params, $auth)`

### API dispatcher and services
- **Client interface**: `ui/include/classes/api/clients/CApiClient.php`
- **Local implementation**: `ui/include/classes/api/clients/CLocalApiClient.php`
  - Validate the API name and method against the service registry first, then enforce the service's `ACCESS_RULES`.
  - After that, apply global `APP::EXEC_MODE_API` enforcement and any controller-level permission checks in that order.
  - Authenticate using `User->checkAuthentication()` (token vs sessionid)
  - Enforces permissions for external API calls (role rules) in `APP::EXEC_MODE_API`
  - Wraps calls in DB transactions (`DBstart()` / `DBend()`), with special-case commit for failed `user.login`
- **Service layer**: `ui/include/classes/api/services/*.php`
  - Business logic for API objects (e.g. `CHost.php`, `CItem.php`, `CSettings.php`)
  - Each service defines `ACCESS_RULES` (min role, feature flags, optional action rules)

## UI MVC routing (PHP UI)

### Actions and controllers
- **Route table**: `ui/include/classes/mvc/CRouter.php`
  - Maps `action` strings (e.g. `host.list`, `problem.view.data`) to:
    - controller class (`CController*`)
    - layout (`layout.htmlpage`, `layout.json`, etc.)
    - view name
- **Controllers**: `ui/app/controllers/CController*.php`
  - Usual pattern: `checkInput()` → `checkPermissions()` → `doAction()` → return `CControllerResponse*`
  - “Page” controllers often have paired “data” controllers for tables (`*List` + `*ListData`)
- **Views/partials**:
  - Views: `ui/app/views/**`
  - Partials: `ui/app/partials/**`

## Frontend JS calling patterns (PHP UI)

### API routing policy
- Use `ui/api_jsonrpc.php` for all new API functionality and for data needed by multiple pages or external clients.
- Reserve `ui/jsrpc.php` for backward-compatible, UI-only convenience endpoints.
- Do not add new functionality to `ui/jsrpc.php` unless it is strictly UI-only and cannot be implemented via JSON-RPC.

### Lightweight JS RPC (legacy/convenience)
- **Client helper**: `ui/js/class.rpc.js` uses `RPC.rpcurl()` default `jsrpc.php?output=json-rpc`
- **Endpoint**: `ui/jsrpc.php`
  - Switch-based small RPC surface for UI conveniences (search, multiselect, zabbix.status, etc.)
  - Still calls server-side API services internally via `API::*()->get()` in many cases

## DB schema / migrations / configuration storage

### PHP UI schema source-of-truth
- **Schema file**: `ui/include/schema.inc.php`
  - Used for field lengths/defaults/validation (via `DB::getSchema()` etc.)

### C backend schema upgrades
- **Upgrade code**: `src/libs/zbxdbupgrade/`
  - Versioned upgrade units like `dbupgrade_7050.c`, plus shared `dbupgrade_common.c`, `dbupgrade.c`
  - If a `dbupgrade` unit fails, roll back to the previous database state where possible, abort startup, log a clear error, and provide a recovery path.
  - Include a test that simulates a failed upgrade unit.

### UI config file (deployment config)
- Template: `ui/conf/zabbix.conf.php.example`
- Config loader: `ui/include/classes/core/CConfigFile.php` (config path under `ui/conf/`)

## Decision guide: where to implement a change (minimal/incremental)

### Add/modify a UI page/action
- Add/adjust route in `ui/include/classes/mvc/CRouter.php`
- Implement controller in `ui/app/controllers/`
- Add/update view in `ui/app/views/` (+ partials in `ui/app/partials/` if needed)

### Add/modify API behavior (JSON-RPC method logic)
- Update or add service method under `ui/include/classes/api/services/`
- Add/adjust `ACCESS_RULES` for permission gating
- New API implementations must accept both auth methods: if `Authorization: Bearer <token>` is present, use it; otherwise authenticate via the encrypted cookie session. If neither is present, return HTTP 401. Do not change existing token precedence or session expiry behavior.

### Add/modify DB schema/upgrade behavior
- If it is a backend DB schema upgrade path: implement in `src/libs/zbxdbupgrade/`
- If it is UI-side schema metadata/validation: update `ui/include/schema.inc.php`
- When a schema change affects both layers, follow this order: (1) implement non-destructive DB changes compatible with both old and new code; (2) add a C backend upgrade unit in `src/libs/zbxdbupgrade/` with idempotent steps; (3) update `ui/include/schema.inc.php` for new validations; (4) deploy the backend upgrade first, then the UI. Document compatibility guarantees and required version ranges.

### Multi-layer changes and verification
1. If the change touches DB schema or upgrades, add the C backend upgrade in `src/libs/zbxdbupgrade/` and update `ui/include/schema.inc.php` together.
2. Keep the upgrade backward-compatible for the supported release window and document any compatibility guarantees or version ranges.
3. Update the API service and `ACCESS_RULES` before changing UI controllers/views, then verify the permission path in the expected order.
4. Before merging, run the relevant unit and integration tests under `tests/`, run database migrations locally using `scripts/migrate.sh`, and include an automated CI job for API and DB integration tests when services or upgrades are touched.

### Add/modify server-side runtime logic (C)
- Identify component under `src/zabbix_server/`, `src/zabbix_proxy/`, `src/zabbix_agent/`
- Follow existing `src/libs/zbx*` patterns for implementation. Only introduce new abstractions when (a) code duplication exceeds 25% across components and (b) a unit test demonstrates reduced complexity. Document the new abstraction in a short RFC under `docs/`.

