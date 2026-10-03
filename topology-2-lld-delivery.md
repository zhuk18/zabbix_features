# Zabbix topology — 2. Delivery through LLD

Version 2.0 (2026-10-03). This spec replaces `topology-lld-role-spec.md`
(Part 1), the server part of `topology-lld-part2-spec.md` (§3–§4, §9) and
`topology-lld-part3-spec.md` (Part 3).

The input contract (roles, macros, normalized fields, snapshot table and
rules, identity normalization) is in `topology-1-model.md` §5. This spec
says how a Zabbix discovery rule fills it.

**Status markers.** A section without `Status: not implemented` is
implemented.

**Rule for the coding agent.** If a requirement that is not in this spec
seems necessary, stop and flag it. Do not expand the scope silently.

---

## 0. Objective

Collect topology data with Zabbix's own low-level discovery, with no
external scripts:

```
walk[] master item (SNMP agent, no preprocessing)
   └─ dependent discovery rule, topology_role = X
        └─ preprocessing: "SNMP walk to topology rows" (source = …)
             └─ contract rows → LLD processing (server, C)
                  └─ topo_lld_snapshot (model §5.4)
                       └─ ingest.php (model §6)
```

Three parts:
1. **Configuration:** the property `topology_role` on discovery rules (§2).
2. **Server snapshots:** LLD processing writes the rows of a rule with a
   role into `topo_lld_snapshot` (§3).
3. **Native step:** "SNMP walk to topology rows" turns a raw walk into
   contract rows (§4), used by the template "Topology by SNMP" (§5).

## 1. Design rules

1. **The role describes what rows mean, not how they are collected.** Any
   item type can carry a role: SNMP walk + dependent rules (main
   pattern), SNMP agent, HTTP agent (controller APIs), Zabbix agent
   (`lldpd`), Zabbix trapper (push delivery), script.
2. **The step does not depend on the role.** It produces rows; the role
   gives them meaning. No API or server check links the step's `source`
   to the rule's role. The contract check in the Test dialog covers
   mismatches.
3. **The server stays dumb about topology.** LLD processing extracts
   contract fields and writes them. It never reads `topo_nodes` /
   `topo_edges` and never matches anything. FDB rules can be large; the
   LLD worker must stay cheap.
4. **Empty is not failed** (model §5.5). Table present with no rows →
   `[]`. Walk unusable → error; the rule goes unsupported and the last
   snapshot stays.
5. **One normalization** of identities (model §5.6), used for local and
   remote identities in every source.
6. **No discard in the chain** (model §5.5 rule 7).

## 2. `topology_role` (configuration)

### 2.1 Where it applies

- Discovery rules (`flags = 1`) and discovery-rule prototypes (nested
  LLD), if the branch has them. Not items, item prototypes or discovered
  items.
- Any item type. Do not add item-type conditions.
- A rule with a role can also have ordinary prototypes (e.g. traffic
  items on a PORTS rule).

### 2.2 Database

- Column `items.topology_role`, integer, `NOT NULL DEFAULT 0`. Declared
  like other LLD-rule-only columns (`lifetime`, …) in the schema template.
  Server-only: not in proxy config sync.
- DB upgrade patch in the current upgrade file. Existing rules get 0 and
  behave as before.
- Noted for review: `ALTER TABLE items` is expensive on large DBs. The
  alternative is a separate table (as `lld_macro_path`). Not built.

### 2.3 API

- `discoveryrule.create` / `update` (and the rule-prototype service):
  accept `topology_role`, validated against model §5.1. Default 0.
- `get`: return it in `output`; support it in `filter`.
- **Inherited rules** (`templateid ≠ 0`): read-only. A different value on
  `update` fails with the usual read-only error.
- Items, item prototypes, discovered items: `topology_role` is rejected
  and never returned.
- The API does not check the macro contract (macros can come straight
  from source JSON).
- **Discard steps are rejected** on rules and rule prototypes with
  `topology_role ≠ NONE`: `Discard unchanged with heartbeat` (the only
  throttling step LLD rules support), on create and update. Validation
  uses the **resulting** state: role and steps each come from the
  request if present, otherwise from the DB. Removing the role and adding
  the step in one call (or the reverse) is accepted. Template linking
  overwrites the host rule's preprocessing, so inherited rules are
  covered by the template-level check.
- The **master item** of a dependent topology rule can't be checked by the
  API (there is no rule ↔ master link at validation time). A discard step
  there freezes the snapshot `clock`. The template (§5) and its test
  enforce "no steps on the master".
- `lifetime` / `enabled_lifetime` work as before. They do not affect
  topology data.

### 2.4 Inheritance and copy paths

`topology_role` must survive every copy path. A missed path silently drops
the role on some hosts.
1. Template → host linking (API/frontend).
2. Template → template (nested).
3. Clone and full clone of hosts and templates.
4. Mass update, if it touches discovery rules.
5. **Server-side template linking in C** — hosts from host prototypes,
   network discovery actions, autoregistration actions. Add the column to
   the C template-copy code. Required.
6. Nested LLD: rules created from rule prototypes copy the role (C).
7. Unlink keeps the rule and its role as a non-inherited rule; unlink and
   clear removes both.

### 2.5 Export / import

- YAML key `topology_role` under discovery rules (and rule prototypes),
  as the export string of model §5.1. Omitted when `NONE`.
- Import validates the string. Add the key to the validator of the
  current format version.

### 2.6 Frontend

- Field **"Topology role"** on the main tab of the discovery rule form:
  `None`, `Ports`, `Neighbors`, `Learned MACs`, `LAG membership`. Shown
  for every item type. Read-only on inherited rules.
- Role ≠ `None` → a contract hint under the field, generated from
  `CTopologyRole`: required macros, "one of" groups, optional macros, the
  PORTS `{#SNMPINDEX}` fallback. Collapsible is fine.
- Next to lost-resources settings, when role ≠ `None`: *"Lost resources
  settings apply to discovered prototypes only, not to topology data."*
- Discovery rule list: a short role label.

### 2.7 Contract check in the Test dialog

When `topology_role ≠ NONE`, check the test result:
- Not LLD JSON (array of rows) → report and stop.
- A macro is **available** if it is a key in the row, or is defined on the
  rule's LLD macros tab. For the second case, do not evaluate JSONPath:
  mark it "via LLD macro path (not evaluated)".
- Report: row count; rows that pass / fail; for failures, the missing
  macro or group and the first failing row indexes; for PORTS, whether the
  `{#SNMPINDEX}` fallback was used.
- An empty value counts as missing.
- Information only; never blocks saving.

### 2.8 Audit

`topology_role` changes appear in the audit log like other rule fields
(rules and rule prototypes).

## 3. Server snapshots (C)

### 3.1 Where

In LLD rule processing, load `topology_role` with the other rule settings.
After the LLD filter, and independent of prototype processing: if the role
≠ 0, build the snapshot from the filtered rows. A snapshot failure does
not affect prototypes, and the reverse.

### 3.2 Contract table in C

A static data table that mirrors `CTopologyRole`: role →
`{macro, field, required, one_of_group}` + the PORTS-only `{#SNMPINDEX}`
fallback. Comments in both files point at each other.

### 3.3 Row extraction

For each filtered row:
- Resolve each contract macro **the same way prototypes do** (row key or
  LLD macro path). Reuse the existing resolver.
- Empty = missing. Check required and "one of" groups. Invalid rows are
  counted and dropped.
- Emit the normalized fields of model §5.3. Omit absent optional fields.
- Sort deterministically before hashing.
- Accept the LLD value as a bare array and as legacy `{"data":[...]}`.

### 3.4 Write

Model §5.4 and §5.5: write only on successful processing; `[]` is a
valid snapshot; same hash and role → update `clock`, `rows_total`,
`rows_valid` only; otherwise replace the row.

`clock` = the value timestamp. A value older than the stored `clock` does
not change the snapshot. Status: not implemented — see
`topology-3-push-delivery.md` §6 (the check and the change cover all rule
types).

### 3.5 Contract problems

If rows were dropped, add a line to the rule's LLD info text, grouped by
reason, with counts:

`Topology (NEIGHBORS): 3 of 41 rows skipped: missing {#IFINDEX}`

The rule stays supported; the partial snapshot is written.

### 3.6 Role change

`CDiscoveryRule::update` deletes the rule's snapshot (and its inherited
children's) when the role changes (model §5.5 rule 6).

### 3.7 Proxy

A proxy-monitored host needs no proxy change: values reach the server, and
the server writes the snapshot.

## 4. Preprocessing step "SNMP walk to topology rows"

### 4.1 Type and placement

- New preprocessing type `ZBX_PREPROC_SNMP_WALK_TO_TOPOLOGY`, next to
  `SNMP walk value` and `SNMP walk to JSON`.
- In the shared preprocessing library: runs on the server and on proxies.
- Allowed on discovery rules and rule prototypes only. Rejected by the API
  on items and item prototypes.
- Input: the text of a `walk[]` item. Reuse the existing SNMP walk parser.
- An older proxy that gets an unknown step type must make the rule
  unsupported with a clear error. (Check and document the current
  behaviour.)

### 4.2 Parameters

Stored as `params`, newline-separated, in this order:

| Parameter | Values | Default | Meaning |
|---|---|---|---|
| `source` | `ports` / `lldp` / `cdp` / `fdb` / `lag` | required | tables to read, contract to emit |
| `missing_mib` | `error` / `empty` | `error` | what to do when the source's anchor OIDs are not in the walk |
| `mac_limit` | integer ≥ 1 | `20` | `fdb` only: ports with more MACs get one count-only row |

### 4.3 Output

- Ordinary LLD JSON, a bare array. Each row is an object with contract
  macro names as keys (`{"{#IFINDEX}":"3", …}`). The rule needs no LLD
  macro paths.
- Values are strings. Absent optional values are omitted.
- Every row gets `{#LOC_CHASSIS}` when `lldpLocChassisId` (+ subtype) is in
  the walk. Ingest uses it for reporter identity from any role.
- Rows sorted by ifIndex, then the other fields.
- JSON built with the existing writer (escapes untrusted strings).
- Memory: stream over the parsed walk; no per-OID copies of large FDB
  tables.

### 4.4 Sources

**`ports`** → PORTS
- Reads IF-MIB `ifName` (fallback `ifDescr`), `ifType`, `ifPhysAddress`,
  `ifAdminStatus`, `ifOperStatus`, `ifHighSpeed` (fallback `ifSpeed`, same
  unit).
- Anchor: `ifType`.
- Emits `{#IFINDEX}`, `{#IFNAME}`, `{#IFTYPE}`, `{#IFMAC}`,
  `{#IFADMINSTATUS}`, `{#IFOPERSTATUS}`, `{#IFSPEED}`, `{#LOC_CHASSIS}`.

**`lldp`** → NEIGHBORS
- Reads `lldpLocChassisIdSubtype` / `lldpLocChassisId`,
  `lldpLocPortIdSubtype` / `lldpLocPortId`, `lldpRemTable` (chassis and
  port ids with subtypes, `lldpRemPortDesc`, `lldpRemSysName`),
  `lldpRemManAddrTable` (address from the index), IF-MIB `ifName`,
  `ifPhysAddress`.
- Anchor: `lldpLocPortTable`. `lldpRemTable` absent or empty with the
  anchor present → `[]`.
- Local port → ifIndex (index `TimeMark.LocalPortNum.RemIndex`), by
  `lldpLocPortIdSubtype`: interfaceName → match `ifName`; macAddress →
  match `ifPhysAddress`; local → the value is the ifIndex; otherwise
  `LocalPortNum` = ifIndex. A neighbor whose local port can't be resolved
  is dropped.
- Mgmt IP: the first IPv4 from `lldpRemManAddrTable` for that remote
  index; IPv6 only if there is no IPv4.
- Emits `{#IFINDEX}`, `{#IFNAME}`, `{#REM_CHASSIS}`, `{#REM_CHASSIS_TYPE}`,
  `{#REM_MGMT_IP}`, `{#REM_SYSNAME}`, `{#REM_PORT}`, `{#REM_PORT_TYPE}`,
  `{#REM_PORT_DESC}`, `{#LOC_CHASSIS}`, `{#SOURCE}` = `lldp`.

**`cdp`** → NEIGHBORS
- Reads CISCO-CDP-MIB `cdpCacheTable` (index `ifIndex.DeviceIndex`):
  `cdpCacheDeviceId`, `cdpCacheDevicePort`, `cdpCacheAddressType` /
  `cdpCacheAddress`; IF-MIB `ifName`.
- Anchor: `cdpCacheTable` or `cdpGlobalRun`. Only `cdpGlobalRun` → `[]`.
- ifIndex comes from the index.
- `cdpCacheDeviceId` is a hostname-like string → `{#REM_SYSNAME}`, never
  `{#REM_CHASSIS}`. Identity uses mgmt IP, then sysname.
- Emits `{#IFINDEX}`, `{#IFNAME}`, `{#REM_SYSNAME}`, `{#REM_MGMT_IP}`,
  `{#REM_PORT}`, `{#REM_PORT_TYPE}` = `interfaceName`, `{#LOC_CHASSIS}`,
  `{#SOURCE}` = `cdp`.

**`fdb`** → LEARNED_MACS
- Reads Q-BRIDGE `dot1qTpFdbPort` + `dot1qTpFdbStatus`; if absent, BRIDGE
  `dot1dTpFdbPort` + `dot1dTpFdbStatus`; `dot1dBasePortIfIndex`.
- Anchor: `dot1dBasePortIfIndex`. No entries → `[]`.
- MAC and VLAN/FDB id from the index. Status `learned` only.
- Per ifIndex: count ≤ `mac_limit` → one row per MAC; otherwise one row
  with `{#PORT_MAC_COUNT}` and no `{#MAC}`.
- Bridge ports without an ifIndex mapping are dropped.
- Emits `{#IFINDEX}`, `{#MAC}` or `{#PORT_MAC_COUNT}`, `{#VLAN}`,
  `{#LOC_CHASSIS}`.

**`lag`** → LAG
- Reads IEEE8023-LAG-MIB `dot3adAggPortAttachedAggID`.
- Anchor: that column. Every port attached to itself or to 0 → `[]`.
- Members attached to themselves or to 0 are skipped.
- Emits `{#IFINDEX}`, `{#LAG_IFINDEX}`, `{#LOC_CHASSIS}`.

### 4.5 Dropped rows

Rows the step can't build (unresolved local port, unmapped bridge port)
are dropped, not emitted half-filled. A successful step has no warning
channel today, so these drops are not visible in the rule's info text.
The step must drop only in these cases, and golden fixtures (§6) cover
every drop path. Surfacing the count is an open question (§8).

### 4.6 API, frontend, export, Test, audit

- API: validate `source`, `missing_mib` (enums) and `mac_limit`
  (integer ≥ 1; meaningful only for `fdb`). Follow how `SNMP walk to
  JSON` params are validated.
- Frontend: "SNMP walk to topology rows" in the SNMP group, only on
  discovery rule forms. Controls: Source, Missing MIB (Error / Empty
  result), MAC limit (FDB only).
- Export/import: step type string and params; validator of the current
  format version.
- Test dialog: server-side test like other SNMP walk steps. With the
  contract check (§2.7) the admin sees rows and contract results in one
  place.
- Audit: the step shows like any other preprocessing step.

## 5. Template "Topology by SNMP"

- Master: SNMP agent `walk[…]` with IF-MIB (`ifName`, `ifDescr`, `ifType`,
  `ifPhysAddress`, `ifAdminStatus`, `ifOperStatus`, `ifHighSpeed`,
  `ifSpeed`), LLDP-MIB (`lldpLocChassisId*`, `lldpLocPortTable`,
  `lldpRemTable`, `lldpRemManAddrTable`), `dot1dBasePortIfIndex`, Q-BRIDGE
  and BRIDGE FDB, `dot3adAggPortAttachedAggID`, CISCO-CDP-MIB
  `cdpCacheTable`, `cdpGlobalRun`. Type Text. No history. **No
  preprocessing steps.**
- Dependent discovery rules, each with exactly one step:

| Rule | `topology_role` | `source` | `missing_mib` |
|---|---|---|---|
| Ports | PORTS | `ports` | `error` |
| LLDP neighbors | NEIGHBORS | `lldp` | `empty` |
| CDP neighbors | NEIGHBORS | `cdp` | `empty` |
| Learned MACs | LEARNED_MACS | `fdb` | `empty` |
| LAG | LAG | `lag` | `empty` |

  `empty` everywhere except Ports: on a mixed fleet, "no CDP / no LAG" must
  not make rules unsupported. No IF-MIB means a broken walk.
- Separate LLDP and CDP rules: link replacement counts support per rule
  (model §8.4), so LLDP and CDP in one rule could not support each other.
- Intervals: LLDP/CDP 30 min, FDB 10 min. Split the master in two if FDB
  needs it.
- No LLD macro paths, no JS, no discard steps.
- The temporary JS template ("Topology by SNMP (temporary JS)") was the
  reference implementation. Remove it from the repo after the equivalence
  check (§7) passes.

## 6. Tests

### 6.1 Golden fixtures

- Recorded `walk[]` outputs of every lab device (template OID set), plus
  synthetic walks from the lab generator (`*.synthetic.walk`, simulator
  spec §5.5). Include: each LLDP chassis subtype seen in the lab, LLDP-MIB
  with no neighbors, no LLDP-MIB, FDB above `mac_limit`, a LAG,
  Hex-STRING chassis and port ids.
- Expected output per fixture × source is committed next to the fixture.
  The JS reference produced the original expected outputs; CDP fixtures
  are reviewed by hand.
- C unit tests (preprocessing YAML cases) compare exactly. No "close
  enough".
- The same fixtures test identity normalization in `push.py` and in PHP
  ingest (model §5.6).
- No credentials in commits; fixtures contain walk output only.

### 6.2 Edge cases (synthetic walks)

- Anchor present, table empty → `[]` for each source.
- Anchor absent → error with `missing_mib = error`; `[]` with `empty`.
- Truncated or garbage walk text → error, never `[]`.
- LLDP local port unresolvable → row dropped.
- FDB: exactly `mac_limit` MACs → per-MAC rows; one more → one count row.
  Non-`learned` statuses ignored.
- LAG: self-attached and 0 members skipped.
- Quotes, backslashes, control characters and non-UTF-8 bytes in
  `lldpRemSysName` / `lldpRemPortDesc` → valid JSON.

## 7. Acceptance criteria

**Configuration**
- A template rule can be created with each role; `get` returns it;
  `filter` works.
- `topology_role` on `item.create` / `itemprototype.create` fails.
- A Dependent item rule can have a role.
- Linking a template gives the host rule the role; changing it on the host
  fails; changing it on the template propagates.
- A host created from a host prototype, by a network discovery action and
  by an autoregistration action gets the role (C path, §2.4 item 5).
- Nested LLD (if present): a rule from a rule prototype carries the role.
- Full clone keeps the role.
- Export omits `NONE`; export → import keeps the role on the template and
  linked hosts.
- The audit log shows old and new values.
- Test dialog: PORTS on a stock IF-MIB rule passes via `{#SNMPINDEX}`;
  NEIGHBORS rows with only `{#SNMPINDEX}` fail on `{#IFINDEX}`; a macro
  defined only on the LLD macros tab is "available, not evaluated".
- DB upgrade leaves every existing rule at 0, unchanged.

**Snapshots**
- No LLDP neighbors → snapshot `[]` with a current `clock`.
- Failure (wrong community) → previous snapshot byte-identical (`rows_hash`,
  `clock`).
- Same data twice → `clock` updated only.
- Contract violations → dropped, counted (`rows_valid` vs `rows_total`),
  shown in the LLD info text; rule supported.
- A macro from an LLD macro path lands under its normalized field name.
- Role change deletes the snapshot (and inherited children's).
- Proxy-monitored host → same snapshot as server-monitored.
- Discard step + role: rejected on create and on update when the resulting
  state has both; removing the role and adding the step in one call is
  accepted. Linking a template onto a host rule with a discard step leaves
  the host rule without the step.

**Native step and template**
- Every golden fixture × source gives exactly the expected output.
- End-to-end: the lab ingested from clean `topo_*` state via the JS
  template and via "Topology by SNMP" gives the same Devices, Ports, links
  and `represented_by` targets by natural keys. No new Devices.
- No LLDP-MIB → LLDP rule supported, empty snapshot; Ports works.
- LLDP without neighbors → `[]`.
- Unreachable device → master unsupported; every dependent rule keeps its
  snapshot byte-identical.
- Garbage walk in the Test dialog → step error, not `[]`.
- FDB port above `mac_limit` → count row; ingest sets `learned_mac_count`.
- API rejects the step on items and item prototypes, and invalid params.
- Export → import of the template keeps every step and parameter.
- Test dialog: Ports/PORTS and LLDP/NEIGHBORS pass all rows with no LLD
  macro paths.
- The template's master item has no preprocessing steps (template test).

## 8. Open questions

- **Surfacing dropped rows** (§4.5): accept silent drops for the
  prototype; a meta-row LLD ignores (hack); or snapshot counts of
  step-reported drops. Decide before GA.
- **Step on ordinary items** for debugging: excluded now.
- **LLDP-V2-MIB, vendor MIBs, per-VLAN FDB on Cisco**
  (community@vlan / v3 context): later sources.
- **Macro naming:** short names (`{#REM_CHASSIS}`) or a reserved prefix
  (`{#TOPO_…}`). Short names now; renaming is one place on each side.
- **Storage of the role:** column on `items` (chosen) or a separate table.
- **Snapshot size:** if `mac_limit` is not enough on large cores, move MAC
  aggregation into the server.
