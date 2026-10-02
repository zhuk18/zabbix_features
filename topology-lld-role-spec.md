# Topology collection via LLD — `topology_role` build spec (Part 1)

## 0. Objective

Make topology data collectable through Zabbix's own low-level discovery,
with no external scripts, by letting a **discovery rule declare what its
rows mean for the topology model** (`topology_role`). The rows themselves
are ordinary LLD rows; the meaning of each row is fixed by a documented
**macro contract** per role.

This spec covers **Part 1 only: the configuration layer** — database,
API, frontend, template inheritance, export/import, audit, and a
contract check in the rule's Test dialog. After Part 1, a rule can be
configured, inherited, exported and validated against the contract, but
**no topology data flows yet** — the server does not act on the role
until Part 2 (§12). This is deliberate: Part 1 validates the
configuration model and UX before any server-side processing is built.

The topology data model this feeds (`topo_nodes` / `topo_edges`,
reconciliation rules) is defined in `topology-prototype-spec.md` and is
**not changed** by this spec.

If a requirement not listed here seems necessary while implementing,
stop and flag it rather than silently expanding scope.

## 1. Scope

**In scope (Part 1):**
- New discovery-rule property `topology_role` (§3), stored in DB (§4)
- API support on discovery rules and, if present in the target branch,
  discovery-rule prototypes (§5)
- Correct propagation through every inheritance path, **including the
  server-side C template-linking code** (§6)
- YAML export/import (§7)
- Frontend field + contract hint (§8)
- Contract check in the discovery rule Test dialog (§9)
- Audit log coverage (§10)

**Out of scope for Part 1 — do not build:**
- Any server-side processing of rows for a role (Part 2)
- The preset preprocessing step "SNMP walk to topology rows" (Part 3)
- Any change to `topo_nodes`/`topo_edges` or the ingest rules
- Restricting the role to particular item types — see §3.3

## 2. Terms

- **Role** — what a discovery rule's rows represent for the topology
  model. One role per rule.
- **Contract** — the set of LLD macros a row must/may carry for a given
  role. Rows are ordinary LLD JSON; the contract only fixes macro names.
- Rows reach the contract either from a source that already emits the
  right keys (SNMP walk to JSON with matching field names, a future
  preset step) or through the existing **LLD macros** tab (JSONPath).
  There is no separate mapping UI — the LLD macros tab *is* the mapping.

## 3. Roles and contract

### 3.1 Role values

| Value | Constant (PHP) | Export string | Row represents | Feeds (Part 2) |
|---|---|---|---|---|
| 0 | `ZBX_TOPOLOGY_ROLE_NONE` | `NONE` | — (default) | nothing |
| 1 | `ZBX_TOPOLOGY_ROLE_PORTS` | `PORTS` | one port of this host | `Port` upsert by `(device_id, if_index)` |
| 2 | `ZBX_TOPOLOGY_ROLE_NEIGHBORS` | `NEIGHBORS` | one LLDP/CDP neighbor seen on a local port | `Device` / unconfirmed `Port` / `physical_link` per §3 of the model spec |
| 3 | `ZBX_TOPOLOGY_ROLE_LEARNED_MACS` | `LEARNED_MACS` | one MAC learned on a local port, or a per-port summary | `Port.attrs.learned_macs` / `learned_mac_count` |
| 4 | `ZBX_TOPOLOGY_ROLE_LAG` | `LAG` | one LAG membership | `Port.lag_id` |

### 3.2 Macro contract

Required macros must be present and non-empty in a row for that row to
be usable. "One of" means at least one of the listed macros.

**PORTS**

| Macro | Req. | Meaning |
|---|---|---|
| `{#IFINDEX}` | yes* | local ifIndex |
| `{#IFNAME}` | no | port name |
| `{#IFTYPE}` | no | IANA ifType number |
| `{#IFMAC}` | no | port MAC |
| `{#IFADMINSTATUS}`, `{#IFOPERSTATUS}` | no | IF-MIB status values |
| `{#IFSPEED}` | no | speed |
| `{#LOC_CHASSIS}` | no | this device's own chassis id |

\* **For PORTS only**, if `{#IFINDEX}` is absent, `{#SNMPINDEX}` is
accepted as the ifIndex. Reason: Zabbix's stock network-interfaces
discovery rules already emit `{#SNMPINDEX}`/`{#IFNAME}`/`{#IFTYPE}` over
IF-MIB, so setting the role on an existing stock rule should just work.
**This fallback must not apply to any other role** — for NEIGHBORS,
`{#SNMPINDEX}` is the lldpRemTable index (`TimeMark.LocalPortNum.RemIndex`),
not an ifIndex.

**NEIGHBORS**

| Macro | Req. | Meaning |
|---|---|---|
| `{#IFINDEX}` | yes | local ifIndex the neighbor is seen on |
| `{#REM_CHASSIS}`, `{#REM_MGMT_IP}`, `{#REM_SYSNAME}` | one of | remote identity keys, in the model's upsert priority order |
| `{#REM_CHASSIS_TYPE}` | no | LLDP chassis-id subtype (or `mac`/`netaddr`/`local`…) |
| `{#REM_PORT}` | no | remote port id |
| `{#REM_PORT_TYPE}` | no | LLDP port-id subtype |
| `{#REM_PORT_DESC}` | no | remote port description (preferred label, per model spec §11) |
| `{#IFNAME}` | no | local port name |
| `{#LOC_CHASSIS}` | no | this device's own chassis id |
| `{#SOURCE}` | no | `lldp` / `cdp` / free text; default `lldp` |

**LEARNED_MACS**

| Macro | Req. | Meaning |
|---|---|---|
| `{#IFINDEX}` | yes | local ifIndex |
| `{#MAC}`, `{#PORT_MAC_COUNT}` | one of | a learned MAC, or (for trunk-like ports) only the count |
| `{#VLAN}` | no | VLAN / FDB id |

**LAG**

| Macro | Req. | Meaning |
|---|---|---|
| `{#IFINDEX}` | yes | member port ifIndex |
| `{#LAG_IFINDEX}` | yes | aggregate port ifIndex |

### 3.3 Where the role applies

- **Discovery rules only** (`flags = 1`) and discovery-rule prototypes
  (nested LLD), if the target branch has them. Not on items, item
  prototypes, or discovered items.
- **Any item type.** The role describes how rows are interpreted, not
  how the value is collected. The main pattern is several **dependent**
  discovery rules over one `walk[]` master item (one per role, because
  rows have different granularity: port vs neighbor vs MAC). Other valid
  sources: SNMP agent, HTTP agent (controller APIs), Zabbix agent
  (`lldpd`), Zabbix trapper (the existing push collector, emitting rows
  instead of a blob), script. Do **not** add item-type conditions.
- A rule with a role may also have ordinary prototypes (e.g. a PORTS
  rule with traffic item prototypes). Do not forbid this.

### 3.4 Single source of truth for the contract

Define roles and contracts in **one PHP class** (e.g. `CTopologyRole`)
exposing: role list, labels, required/optional macros, "one of" groups,
and the PORTS-only `{#SNMPINDEX}` fallback. The UI hint (§8), the Test
check (§9), and documentation strings read from it. Part 2 will need the
same definitions in C — put a comment in the class that the C side must
stay in sync, and keep the definition data-only (arrays), not logic
spread across call sites.

## 4. Database

- Add column `items.topology_role`, integer, `NOT NULL DEFAULT 0`.
  Locate the schema template (`create/src/schema.tmpl` or equivalent in
  the target branch) and follow how other LLD-rule-only scalar columns
  on `items` (e.g. `lifetime`, `lifetime_type`, `enabled_lifetime*`) are
  declared, including which flags mark the field for server/proxy
  config sync. **Server-only**: the proxy never needs this field; do
  not include it in proxy config sync.
- Add a DB upgrade patch in the current upgrade file for the branch,
  bumping the DB version the usual way.
- **Flag for review, not a blocker**: `ALTER TABLE items` on a large
  production DB is expensive. Acceptable for the prototype. The
  alternative (a separate `item_topology` table, following the
  `lld_macro_path` precedent for LLD-rule-specific config) is noted in
  §13 — don't implement it unless asked.

## 5. API

Locate the discovery rule API service (`CDiscoveryRule` and, if
present, the discovery-rule prototype service).

- `create` / `update`: accept `topology_role`, validated as one of
  §3.1's values. Default `0`.
- `get`: return `topology_role` in `output`; support it in `filter`.
- **Inherited rules** (`templateid != 0`): `topology_role` is
  read-only, like other inherited definition fields. `update` with a
  different value on an inherited rule must fail with the same kind of
  error the API already returns for other read-only inherited fields.
- Items/item prototypes/discovered items: `topology_role` must not be
  accepted (validation error if passed), and must not appear in their
  `get` output.
- **Do not** try to validate the macro contract in the API — it can't
  be done statically (macros can come straight from source JSON).
  Contract validation is §9 (Test) and Part 2 (runtime).
- `lifetime`/`enabled_lifetime` (lost resources) keep working as
  today; they will **not** affect topology data (see model spec §3
  rule 5). No API change for them — this is only a UI note (§8).

## 6. Inheritance and copy paths — including C

`topology_role` is part of the rule definition and must survive every
path by which a discovery rule is copied. **Find every path; missing
one silently drops the role on some hosts.** Known paths to check:

1. Template → host linking via the frontend/API (PHP inheritance in the
   discovery rule service).
2. Template → template (nested templates).
3. Host/template **clone** and **full clone**.
4. Mass update, if it touches discovery rules.
5. **Server-side template linking in C** — templates linked by the
   server rather than the API: hosts created from **host prototypes**,
   network discovery actions, and autoregistration actions. These go
   through the server's template-copy code (locate the C function that
   copies template items/discovery rules to a host, e.g. in a
   `template_item.c`). Add `topology_role` to what it selects and
   inserts. This is the one C change in Part 1 and is required, not
   optional — without it, every discovered host silently loses the role.
6. **Nested LLD** (if present): discovery rules created by the server
   from discovery-rule prototypes must copy `topology_role` from the
   prototype (C, LLD processing code).
7. Unlink / unlink-and-clear: role is removed together with the rule;
   plain unlink keeps the rule and its role on the host as a
   non-inherited rule, like other fields.

## 7. Export / import

- YAML: add `topology_role` under discovery rules (and discovery-rule
  prototypes if present), exported as the §3.1 string, **omitted when
  `NONE`** (follow how other default-valued fields are omitted).
- Import: accept the key; validate against the §3.1 strings.
- Add it to the import validator for the current format version.
  Older export versions don't have the key — no converter change
  needed beyond whatever the version bump mechanics already require.
- Round-trip test: export → import into a clean instance → identical
  `topology_role` on template and on linked hosts.

## 8. Frontend

In the discovery rule edit form (locate the current form — modal or
page, depending on branch), on the main tab:

- Field **"Topology role"**: select with `None` (default), `Ports`,
  `Neighbors`, `Learned MACs`, `LAG membership`.
- Shown for all item types (§3.3). Read-only on inherited rules,
  showing the template link the same way other inherited fields do.
- When a role other than `None` is selected, show a **contract hint**
  directly under the field, generated from `CTopologyRole` (§3.4):
  required macros, "one of" groups, optional macros, and for PORTS the
  `{#SNMPINDEX}` fallback note. Collapsible is fine; must not require
  opening documentation.
- Next to the lost-resources settings, when role ≠ `None`, a short
  note: *"Lost resources settings apply to discovered prototypes only,
  not to topology data."*
- Discovery rule list: add an optional column or icon indicating the
  role (keep it compact — a short label is enough).

## 9. Contract check in the Test dialog

The discovery rule's Test dialog already runs preprocessing and shows
the resulting value. When `topology_role ≠ NONE`, add a contract check
on that result:

- Parse the result as LLD JSON (array of rows). If it isn't, report
  that and stop.
- For each role requirement, a macro counts as **available** if it is
  (a) a key present in the row, or (b) defined on the rule's **LLD
  macros** tab. For (b), don't evaluate JSONPath in PHP — just treat
  the macro as available and mark it "via LLD macro path (not
  evaluated)".
- Report:
  - number of rows;
  - rows passing / failing the contract;
  - for failures: which required macro or "one of" group is missing,
    with the first few failing row indexes;
  - for PORTS: whether `{#SNMPINDEX}` fallback was used.
- Empty-value handling: a macro present but empty counts as missing.
- This is informational only — it never blocks saving the rule.

## 10. Audit

`topology_role` changes must appear in the audit log for discovery
rules the same way other rule fields do. Locate the audit field
registry for discovery rules (and prototypes) and add the field;
otherwise changes will be silently unaudited.

## 11. Acceptance criteria

- A discovery rule on a template can be created with each role value;
  `get` returns it; `filter` by it works.
- Passing `topology_role` to `item.create` / `itemprototype.create`
  fails validation.
- A rule of type **Dependent item** can have a role (confirms no
  item-type restriction).
- Linking the template to a host via API/UI gives the host rule the
  same role; changing it on the host fails (read-only inherited);
  changing it on the template propagates.
- **A host created from a host prototype, whose prototype links the
  template, gets the role on its rule** — confirms the C
  template-linking path (§6.5). Same for a host created by a network
  discovery action and by an autoregistration action. This is the
  criterion most likely to fail if §6.5 is skipped; test it
  explicitly.
- If nested LLD exists: a discovery rule created from a discovery-rule
  prototype carries the prototype's role.
- Full clone of a host with a role-bearing rule keeps the role.
- Export omits `topology_role` when `NONE`, includes the string
  otherwise; export → import round-trip preserves it on template and
  linked hosts.
- Audit log shows old/new values when the role changes.
- Test dialog, PORTS role, on a stock IF-MIB interfaces discovery rule:
  reports all rows passing via `{#SNMPINDEX}` fallback.
- Test dialog, NEIGHBORS role, on rows with `{#SNMPINDEX}` but no
  `{#IFINDEX}`: reports every row failing on `{#IFINDEX}` — confirms the
  fallback is PORTS-only.
- Test dialog, NEIGHBORS role, where `{#REM_CHASSIS}` is defined only
  on the LLD macros tab: counts it as available and labels it "not
  evaluated".
- Upgrading a DB with existing discovery rules leaves them all at
  `topology_role = 0` and behaving exactly as before.

## 12. Later parts (outline only — do not build now)

**Part 2 — server consumes rows (C + existing PHP ingest).**
- Config cache loads `topology_role` for discovery rules.
- In LLD processing, after filters and macro paths are applied, if
  role ≠ NONE: write the rule's resulting rows as one snapshot
  (`itemid`, `clock`, rows JSON) to a staging table, replacing the
  previous snapshot. No DB comparisons against topology tables in the
  LLD worker — it must stay cheap (FDB rules can be large).
- Snapshot write happens only on successful processing. A rule that
  goes unsupported must not overwrite the last good snapshot.
- Empty result (`[]`) is a valid snapshot for NEIGHBORS/LEARNED_MACS
  (no neighbors / no MACs), distinct from unsupported.
- `ingest.php` switches its input from the Trapper blob to these
  snapshots (reporter = the rule's host, i.e. `reporter_self` as today),
  keeping every §3 reconciliation rule of the model spec unchanged.
  Order independence between roles relies on natural keys
  (`Port` by `(device_id, if_index)`), not on arrival order.
- `topo_observations` table for NEIGHBORS outcomes
  (`applied` / `device_only` / `shadowed` / `ambiguous`) — designed in
  the preceding discussion; to be specified with Part 2.

**Part 3 — preset preprocessing step** "SNMP walk to topology rows"
(sources: LLDP, CDP, FDB, LAG, IF-MIB) emitting contract-compliant
rows, including the multi-table index/value joins and the "empty
result is valid" option.

## 13. Open questions (decide before or during Part 2, not blockers now)

- **Macro naming**: short names (`{#REM_CHASSIS}`) vs a reserved prefix
  (`{#TOPO_REM_CHASSIS}`). Short names read better and let the PORTS
  role reuse stock `{#IFNAME}`/`{#IFTYPE}`; a prefix avoids accidental
  meaning in old templates when a role is switched on. Part 1 uses
  short names; keep them in `CTopologyRole` only so renaming is one
  place.
- **Storage**: column on `items` (chosen) vs separate `item_topology`
  table (avoids altering the largest config table; follows
  `lld_macro_path` precedent).
- **Two sources for one link** (LLDP and CDP rules both seeing the same
  neighbor): how per-side `last_seen_src/dst` accounts for two
  confirming rules on the same host.
