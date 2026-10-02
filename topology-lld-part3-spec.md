# Topology collection via LLD — Part 3 build spec: preset preprocessing step

## 0. Objective

Part 2 made topology data flow through LLD snapshots, with a **temporary
JS template** turning a raw `walk[]` into contract rows. Part 3 replaces
that JS with a native preprocessing step:

```
walk[] master item (SNMP agent)
   └─ dependent discovery rule, topology_role = X
        └─ preprocessing: "SNMP walk to topology rows" (source = …)   ← this spec
             └─ contract rows → LLD → topo_lld_snapshot (Part 2, unchanged)
```

Why native and not JS: the joins (LLDP local port → ifIndex, bridge
port → ifIndex, FDB index decoding) are the part every user would get
wrong, JS on large FDB walks hits runtime limits, and one C
implementation gives one normalization of identities across all
devices — which reconciliation depends on (§4.2).

References:
- `topology-lld-role-spec.md` — Part 1: roles and macro contract (§3).
- `topology-lld-part2-spec.md` — Part 2: snapshots, ingest, the JS
  template (§9). **The JS in Part 2 §9 is the reference implementation
  for this step.** Where this spec and the JS disagree, stop and flag it.

If a requirement not listed here seems necessary while implementing,
stop and flag it rather than silently expanding scope.

## 1. Scope

**In scope:**
- New preprocessing step type "SNMP walk to topology rows" (§3), C
  implementation in the shared preprocessing library (§4)
- API, frontend, export/import, preprocessing Test support (§5)
- Golden-fixture tests: C output must equal the Part 2 JS output on
  recorded lab walks (§6)
- New template "Topology by SNMP" built on the step, replacing the
  temporary JS template (§7)

**Out of scope — do not build:**
- Any change to snapshots, ingest, observations or reconciliation
  (Part 2 stays as is).
- LLDP-V2-MIB, vendor-specific MIBs, per-VLAN FDB on Cisco
  (community@vlan / v3 context).
- Discovered-vs-discovered policy, observation deletion policy
  (`missing_since`), periodic ingest — separate decisions.
- Coupling the step to `topology_role` (§2 rule 1).

## 2. Design rules (read before implementing)

1. **The step is independent of the role.** It produces rows; the role
   says what rows mean. Nothing in API or server checks that the step's
   `source` matches the rule's role — the Part 1 Test dialog contract
   check covers mismatches. (Same principle as "role is independent of
   item type".)
2. **Output is ordinary LLD JSON with macro-named keys.** Each row is an
   object whose keys are the contract macros (`{"{#IFINDEX}":"3", …}`).
   LLD accepts macro keys directly, so the rule needs **no LLD macro
   paths**. Values are strings. Absent optional values are omitted, not
   emitted as `""`.
3. **Empty vs failed, same as Part 2 §2 rule 2.** "The table is there
   and has no rows" → `[]`. "The walk is unusable" → step error (rule
   goes unsupported, last good snapshot is kept). "The MIB is absent
   from the walk" → error by default, `[]` if the step's
   *missing MIB* option says so (§3.2). Never turn a parse failure into
   `[]`.
4. **One normalization, used everywhere.** Chassis ids, MACs and IPs are
   formatted by one set of C functions (§4.2), used for local and remote
   identities in every source. A remote chassis id from device A's LLDP
   must be byte-identical to device B's own `{#LOC_CHASSIS}` — otherwise
   reconciliation creates duplicate Devices.
5. **No discard in the chain.** The step never emits "unchanged"; the
   Part 2 ban on discard steps on topology rules and the
   master-item rule (Part 2 §4.4, §9) apply to the new template.

## 3. The step

### 3.1 Type and placement

- New preprocessing type, e.g. `ZBX_PREPROC_SNMP_WALK_TO_TOPOLOGY`,
  next to the existing SNMP walk steps (`SNMP walk value`,
  `SNMP walk to JSON`). Take the next free type number on the branch.
- Allowed on **discovery rules and discovery-rule prototypes only**.
  Not on items / item prototypes (its output only makes sense as LLD
  JSON). Rejected by API elsewhere.
- Input: the text a `walk[]` item returns. **Reuse the existing SNMP
  walk parser** used by `SNMP walk to JSON`; do not write a second one.

### 3.2 Parameters

| Parameter | Values | Default | Meaning |
|---|---|---|---|
| `source` | `ports` / `lldp` / `cdp` / `fdb` / `lag` | — (required) | which table set to read and which contract to emit |
| `missing_mib` | `error` / `empty` | `error` | what to do when the source's anchor OIDs (§3.3) are absent from the walk |
| `mac_limit` | integer ≥ 1 | `20` | `fdb` only: ports with more learned MACs than this get one count-only row |

Store as the step's `params` in the usual newline-separated form; the
order above is the order in `params`.

### 3.3 Sources

Each source lists: OIDs read, the **anchor** (absent → `missing_mib`
applies), and the emitted contract (Part 1 §3.2). All sources add
`{#LOC_CHASSIS}` to every row when `lldpLocChassisId` (+ subtype) is in
the walk — Part 2 §5.2 uses it for reporter identity from any role.

**`ports`** → role PORTS
- Reads: IF-MIB `ifName` (fallback `ifDescr`), `ifType`,
  `ifPhysAddress`, `ifAdminStatus`, `ifOperStatus`, `ifHighSpeed`
  (fallback `ifSpeed`, converted to the same unit the JS emits).
- Anchor: `ifType`.
- Emits: `{#IFINDEX}`, `{#IFNAME}`, `{#IFTYPE}`, `{#IFMAC}`,
  `{#IFADMINSTATUS}`, `{#IFOPERSTATUS}`, `{#IFSPEED}`, `{#LOC_CHASSIS}`.

**`lldp`** → role NEIGHBORS
- Reads: `lldpLocChassisIdSubtype`/`lldpLocChassisId`,
  `lldpLocPortIdSubtype`/`lldpLocPortId`, `lldpRemTable`
  (chassis/port ids + subtypes, `lldpRemPortDesc`, `lldpRemSysName`),
  `lldpRemManAddrTable` (address from the index), IF-MIB `ifName`,
  `ifPhysAddress` for the local-port join.
- Anchor: `lldpLocPortTable`. `lldpRemTable` absent or empty with the
  anchor present → `[]` (no neighbors).
- Local port → ifIndex (index `TimeMark.LocalPortNum.RemIndex`), by
  `lldpLocPortIdSubtype`: interfaceName → match `ifName`; macAddress →
  match `ifPhysAddress`; local → treat value as ifIndex; otherwise
  fallback "LocalPortNum = ifIndex". Same chain and order as the JS.
  A neighbor whose local port can't be resolved is **dropped and
  counted** (§3.4), not emitted without `{#IFINDEX}`.
- Mgmt IP: first IPv4 from `lldpRemManAddrTable` for that remote index;
  IPv6 only if no IPv4 (as the JS does — check and keep).
- Emits: `{#IFINDEX}`, `{#IFNAME}`, `{#REM_CHASSIS}`,
  `{#REM_CHASSIS_TYPE}`, `{#REM_MGMT_IP}`, `{#REM_SYSNAME}`,
  `{#REM_PORT}`, `{#REM_PORT_TYPE}`, `{#REM_PORT_DESC}`,
  `{#LOC_CHASSIS}`, `{#SOURCE}` = `lldp`.

**`cdp`** → role NEIGHBORS
- Reads: CISCO-CDP-MIB `cdpCacheTable` (index `ifIndex.DeviceIndex`):
  `cdpCacheDeviceId`, `cdpCacheDevicePort`, `cdpCacheAddressType`/
  `cdpCacheAddress`, plus IF-MIB `ifName`.
- Anchor: `cdpCacheTable` or `cdpGlobalRun`. Only `cdpGlobalRun`
  present → `[]`.
- ifIndex comes straight from the index — no join.
- `{#REM_CHASSIS}` is **not** emitted from `cdpCacheDeviceId` (it's a
  hostname-like string, not a chassis id); it goes to `{#REM_SYSNAME}`.
  Identity then relies on mgmt IP → sysname, per the model's priority.
  If the JS does otherwise, flag it.
- Emits: `{#IFINDEX}`, `{#IFNAME}`, `{#REM_SYSNAME}`, `{#REM_MGMT_IP}`,
  `{#REM_PORT}` (and `{#REM_PORT_TYPE}` = `interfaceName`),
  `{#LOC_CHASSIS}`, `{#SOURCE}` = `cdp`.
- Not in the Part 2 JS if it only covered LLDP: then this source has no
  golden reference; fixtures (§6) are built from a lab walk and
  reviewed by hand.

**`fdb`** → role LEARNED_MACS
- Reads: Q-BRIDGE `dot1qTpFdbPort` + `dot1qTpFdbStatus`; if Q-BRIDGE is
  absent, BRIDGE `dot1dTpFdbPort` + `dot1dTpFdbStatus`;
  `dot1dBasePortIfIndex` for bridge port → ifIndex.
- Anchor: `dot1dBasePortIfIndex`. No FDB entries with it present → `[]`.
- MAC and VLAN/FDB id decoded from the index. Only status `learned`.
- Per ifIndex: MAC count ≤ `mac_limit` → one row per MAC; otherwise one
  row with `{#PORT_MAC_COUNT}` and no `{#MAC}`.
- Bridge ports without an ifIndex mapping are dropped and counted.
- Emits: `{#IFINDEX}`, `{#MAC}` or `{#PORT_MAC_COUNT}`, `{#VLAN}`,
  `{#LOC_CHASSIS}`.

**`lag`** → role LAG
- Reads: IEEE8023-LAG-MIB `dot3adAggPortAttachedAggID`.
- Anchor: that column. Present but every port attached to itself or 0
  → `[]`.
- Skip members attached to themselves and to 0.
- Emits: `{#IFINDEX}`, `{#LAG_IFINDEX}`, `{#LOC_CHASSIS}`.

### 3.4 Dropped rows

Rows the step can't build (unresolved local port, unmapped bridge port)
are dropped, not emitted half-filled. The step can't write the rule's
info text itself, so it reports drops the only way a successful step
can: **it doesn't**. Instead the Part 2 contract check would never see
them — so the step must be conservative about what it drops and the
golden fixtures (§6) must cover every drop path. Flag if you find a
clean way to surface counts (e.g. an existing mechanism for a step to
attach a warning to a successful result); don't invent one.

## 4. Server / proxy (C)

### 4.1 Where

The shared preprocessing library that runs on **both server and
proxy** (the same place `SNMP walk to JSON` lives). Proxy-monitored
hosts run this step on the proxy; nothing extra is needed for that
beyond the step being in the shared library.

Server and proxy versions are assumed equal in the prototype. An older
proxy receiving a rule with an unknown step type must make the rule
unsupported with a clear error, not crash or skip the step — check what
the preprocessing code does for unknown types today and note it.

### 4.2 Normalization (shared functions)

One module used by every source:

| Value | Normal form |
|---|---|
| MAC (chassis subtype macAddress, ifPhysAddress, FDB index) | lowercase, colon-separated, 6 octets: `00:1a:2b:3c:4d:5e` |
| Chassis subtype networkAddress | decoded address (IANA family byte + address), IPv4 dotted / IPv6 compressed |
| Chassis subtype local / interfaceName / others | string as received, trimmed of trailing NUL/spaces |
| `{#REM_CHASSIS_TYPE}` | `mac` / `netaddr` / `local` / `ifname` / … — **the same strings the JS emits** |
| Mgmt IP | IPv4 dotted / IPv6 compressed lowercase |
| Hex-STRING vs STRING | the walk may return either for the same OID on different devices; normalize before applying the rules above |

The exact output strings must match the Part 2 JS byte for byte — that's
what existing `topo_nodes.chassis_id` values were created from. If the
JS is inconsistent somewhere (e.g. different MAC formats for local vs
remote), **stop and flag it**: fixing it changes identities of existing
Devices and needs a decision.

### 4.3 Output

- JSON built with the existing JSON writer (escaping of untrusted LLDP
  strings is its job; model spec §9 still applies at render time).
- Rows sorted deterministically (by ifIndex, then remaining fields), so
  output is stable for the same walk. (Part 2 sorts again before
  hashing; this just makes fixtures and tests simpler.)
- Memory: FDB walks on large cores can be many MB. Build rows by
  streaming over the parsed walk; don't build intermediate per-OID
  copies of the whole table.

## 5. API, frontend, export/import, Test

- **API**: accept the new type on discovery rules and rule prototypes;
  validate `source` (enum), `missing_mib` (enum), `mac_limit` (int ≥ 1,
  only meaningful for `fdb`, ignored otherwise); reject the type on
  items and item prototypes. Follow how `SNMP walk to JSON` params are
  validated.
- **Frontend**: new entry in the preprocessing type list, in the SNMP
  group, label **"SNMP walk to topology rows"**; parameter controls:
  Source (select), Missing MIB (select: Error / Empty result),
  MAC limit (shown only for FDB). Only offered on discovery rule forms.
- **Export/import**: new step type string and params in YAML; import
  validator for the current format version; round-trip test.
- **Preprocessing Test dialog**: works like other SNMP walk steps
  (server-side test). Together with the Part 1 contract check, the
  admin sees rows and contract pass/fail in one place.
- **Audit**: nothing new beyond existing preprocessing auditing — check
  the step shows up there like any other.

## 6. Tests

### 6.1 Golden fixtures (the main acceptance tool)

- Record `walk[]` outputs from every lab device (the same OID set as the
  Part 2 JS master item) into fixture files. Include at least: a device
  with LLDP neighbors of each chassis subtype seen in the lab, a device
  with LLDP-MIB but no neighbors, a device without LLDP-MIB, a switch
  with FDB over the `mac_limit`, a device with a LAG, and one where
  the walk returns Hex-STRING for chassis/port ids.
- For each fixture and source: expected output = **the Part 2 JS
  output** on the same walk (sorted). Commit expected outputs next to
  the fixtures.
- C unit tests (the existing preprocessing test framework, YAML cases)
  run every fixture × source and compare to expected. Mismatch = fail;
  no "close enough".
- Keep `walk.sh` and raw captures with credentials out of commits;
  fixtures contain walk output only.

### 6.2 Edge cases (unit tests, synthetic walks)

- Anchor present, data table empty → `[]` for each source.
- Anchor absent → error with `missing_mib = error`, `[]` with `empty`.
- Truncated / garbage walk text → error, never `[]`.
- LLDP local port unresolvable → row dropped.
- FDB: exactly `mac_limit` MACs → per-MAC rows; `mac_limit + 1` → one
  count row. Non-`learned` statuses ignored.
- LAG: self-attached and 0 members skipped.
- Strings with quotes, backslashes, control chars and non-UTF-8 bytes in
  `lldpRemSysName` / `lldpRemPortDesc` → valid JSON out.

## 7. Template "Topology by SNMP"

- Replaces "Topology by SNMP (temporary JS)" (Part 2 §9).
- Master: SNMP agent `walk[…]` with the same OID set as the JS template
  plus CISCO-CDP-MIB `cdpCacheTable`, `cdpGlobalRun`. Type Text, no
  history. **No preprocessing steps on the master** (Part 2 §9).
- Dependent discovery rules, each with exactly one step
  "SNMP walk to topology rows":

| Rule | `topology_role` | `source` | `missing_mib` |
|---|---|---|---|
| Ports | PORTS | `ports` | `error` |
| LLDP neighbors | NEIGHBORS | `lldp` | `empty` |
| CDP neighbors | NEIGHBORS | `cdp` | `empty` |
| Learned MACs | LEARNED_MACS | `fdb` | `empty` |
| LAG | LAG | `lag` | `empty` |

  `empty` for everything but ports because a template applied to a
  mixed fleet must not turn "device has no CDP/LAG" into unsupported
  rules. Ports stay `error`: no IF-MIB means the walk is broken.
- Intervals: as in the JS template (LLDP/CDP 30 min, FDB 10 min); split
  the master in two if FDB requires it — same rule as Part 2 §9.
- No LLD macro paths, no JS, no discard steps anywhere in the template.
- The JS template stays in the lab until §8's equivalence check passes,
  then is removed from the repo.

## 8. Acceptance criteria

- Every golden fixture × source produces exactly the Part 2 JS output
  (§6.1). CDP fixtures, if the JS has no CDP, match hand-reviewed
  expected output.
- **End-to-end equivalence**: the lab ingested from a clean `topo_*`
  state via the JS template and, separately, via "Topology by SNMP"
  yields the same Devices, Ports, `physical_link`s and `represented_by`
  targets by natural keys (same method as Part 2 §10). No new Devices
  appear — i.e. normalization matches.
- A device without LLDP-MIB, with the template linked: LLDP rule stays
  supported and writes an empty snapshot; the Ports rule works.
- A device with LLDP but no neighbors: `[]`, empty snapshot, rule
  supported.
- Unreachable device / wrong community: master unsupported, all
  dependent rules keep their last snapshots byte-identical.
- Garbage walk text in the Test dialog → step error shown, not `[]`.
- FDB port above `mac_limit` → count-only row; ingest sets
  `learned_mac_count` and empty `learned_macs`.
- Proxy-monitored lab device: same snapshots as when monitored by the
  server.
- API rejects the step on `item.create` / `itemprototype.create`;
  rejects invalid `source` / `missing_mib` / `mac_limit`.
- Export → import round-trip of "Topology by SNMP" preserves every step
  and parameter.
- Test dialog on the Ports rule with role PORTS: contract check passes
  all rows; on the LLDP rule with role NEIGHBORS: passes all rows with
  no LLD macro paths defined.
- The template's master item has no preprocessing steps (checked in the
  template test, per Part 2 §9).

## 9. Open questions (decide during or after Part 3)

- **Surfacing dropped rows** (§3.4): successful steps have no warning
  channel today. Options: accept silent drops for the prototype; add a
  `{#TOPO_DROPPED}` meta-row that LLD ignores (hack); extend Part 2's
  snapshot to count "step-reported" drops. Needs a decision before GA.
- **Step on ordinary items**: useful for debugging (see rows in latest
  data). Excluded now to keep LLD-only semantics.
- **LLDP-V2-MIB and vendor MIBs**: which devices in real customer fleets
  need them; separate sources later.
- **Normalization vs existing data**: if §4.2 finds JS inconsistencies,
  fixing them changes chassis ids of existing Devices — needs a
  migration or a one-time re-ingest decision.
- **Older proxies**: behavior of an outdated proxy with this step
  (§4.1) — document once checked.
