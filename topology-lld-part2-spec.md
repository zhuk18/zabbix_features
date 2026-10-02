# Topology collection via LLD — Part 2 build spec: server snapshots + ingest

## 0. Objective

Part 1 added `topology_role` to discovery rules as configuration only.
Part 2 makes data flow:

```
poller → preprocessing → LLD processing (server, C)
                             └─ role ≠ NONE → per-rule row snapshot (staging table)
ingest.php (PHP, manual trigger as today)
   reads snapshots → applies the model spec §3 rules → topo_nodes / topo_edges
                   → records NEIGHBORS outcomes in topo_observations
```

The LLD path **replaces the Trapper push blob as the transport** — exactly
what `topology-prototype-spec.md` §4 anticipated ("the transport could be
replaced … without touching §2, §3, or §7"). The reconciliation rules in
that spec's §3 are **not changed** by this spec except where §6.6 below
explicitly says so.

References:
- `topology-prototype-spec.md` — the model (§2), reconciliation (§3),
  ingest (§4.1). Called "model spec" below.
- `topology-lld-role-spec.md` — Part 1: roles and macro contract (§3).

If a requirement not listed here seems necessary while implementing,
stop and flag it rather than silently expanding scope.

## 1. Scope

**In scope:**
- Staging table `topo_lld_snapshot` (§3)
- Server: build and write snapshots during LLD processing (§4)
- Frontend/API: drop a rule's snapshot when its role changes (§4.6)
- `ingest.php`: read snapshots instead of the blob; per-role handling (§5, §6)
- `topo_observations` for NEIGHBORS outcomes (§7)
- Minimal diagnostics: ingest summary + observations endpoint (§8)
- A **temporary** lab template producing contract rows with JS (§9)

**Out of scope — do not build:**
- The preset preprocessing step (Part 3). JS in §9 stands in for it.
- Moving reconciliation into the server. Ingest stays PHP and manually
  triggered, as today.
- Any graph/UI change beyond §8 (no conflict badge yet — §7 only
  provides the data it will need).
- A link lifecycle state machine (model spec §3 rule 5 stands).
- Per-VLAN FDB on Cisco (community@vlan / v3 context).

## 2. Design rules (read before implementing)

1. **The server stays dumb about topology.** LLD processing extracts the
   contract fields from rows and writes them. It never reads
   `topo_nodes`/`topo_edges`, never matches anything. FDB rules can be
   large; the LLD worker must stay cheap.
2. **A snapshot is only ever written on successful processing.** A rule
   that fails (unsupported, invalid JSON) leaves its last good snapshot
   untouched. An empty result `[]` — or all rows filtered out — **is** a
   successful processing and writes an empty snapshot. "No neighbors" and
   "couldn't ask" must never look the same.
3. **Observation time is the snapshot's clock, not ingest time.** Every
   `last_seen` written by ingest comes from the snapshot's `clock`.
   Running ingest twice without new data must not advance any
   `last_seen`.
4. **Snapshots use normalized field names, not macro names.** The server
   maps contract macros → fields. Ingest never sees `{#…}` names, so the
   open macro-naming question (Part 1 §13) stays a change in two places
   (PHP `CTopologyRole`, C contract table) and never reaches ingest.

## 3. Staging table

```
topo_lld_snapshot
  itemid      bigint   PK, FK → items.itemid ON DELETE CASCADE   -- the discovery rule
  hostid      bigint   NOT NULL, FK → hosts.hostid ON DELETE CASCADE
  role        integer  NOT NULL                                  -- topology_role at write time
  clock       integer  NOT NULL                                  -- last successful processing
  rows_hash   varchar(64) NOT NULL                               -- hash of `rows`
  rows        longtext NOT NULL                                  -- JSON array, normalized fields (§4.3)
  rows_total  integer  NOT NULL                                  -- rows after LLD filter
  rows_valid  integer  NOT NULL                                  -- rows passing the contract
```

- Declare in `schema.tmpl` like other server-only tables; server-only,
  not synced to proxies. Use the schema's large-text type (as used for
  big text values elsewhere); an FDB snapshot can reach several MB.
- `hostid` is denormalized on purpose: ingest discovers reporters with
  one query on this table, no join through `items`.
- One row per rule. Discovered rules (from rule prototypes) get their own
  rows like any other rule.
- Deleting the rule or the host removes the snapshot by cascade. Topology
  objects are **not** touched by that — `Device` rows are never deleted
  (model spec §3 rule 3).
- DB upgrade patch numbered after Part 1's patch, on the rebased branch.

## 4. Server (C)

### 4.1 Where

In LLD rule processing, locate where the rule's settings are loaded
(filter, lifetime, etc.) and add `topology_role`. After the LLD filter
has been applied and **before/independent of** prototype processing:
if `topology_role ≠ 0`, build the snapshot from the filtered rows.

A snapshot failure must not affect prototype processing, and vice versa.

### 4.2 Contract table in C

Mirror `CTopologyRole` (Part 1 §3.4) as a static data table in C:
role → list of `{macro, field, required, one_of_group}` + the
PORTS-only `{#SNMPINDEX}` fallback. Add a comment pointing at
`CTopologyRole.php` and vice versa: **both must change together**.

### 4.3 Row extraction and normalization

For each filtered row:
- Resolve each contract macro **the same way prototypes resolve it** —
  row JSON key or LLD macro path (JSONPath). Reuse the existing
  resolution function; do not write a second resolver.
- Empty value = missing.
- Check required / one-of. Invalid rows are counted and dropped, not
  written.
- Emit an object with normalized field names:

| Role | Fields |
|---|---|
| PORTS | `if_index` (from `{#IFINDEX}`, else `{#SNMPINDEX}`), `name`, `if_type`, `mac`, `admin_status`, `oper_status`, `speed`, `loc_chassis` |
| NEIGHBORS | `if_index`, `if_name`, `rem_chassis`, `rem_chassis_type`, `rem_mgmt_ip`, `rem_sysname`, `rem_port`, `rem_port_type`, `rem_port_desc`, `loc_chassis`, `source` (default `lldp`) |
| LEARNED_MACS | `if_index`, `mac`, `port_mac_count`, `vlan` |
| LAG | `if_index`, `lag_if_index` |

Absent optional fields are omitted, not written as empty strings.

Sort rows deterministically (by `if_index`, then remaining fields) before
hashing, so identical data always hashes identically.

### 4.4 Write

- Compute `rows_hash`. If a snapshot exists with the same hash and role:
  update only `clock`, `rows_total`, `rows_valid`. Otherwise replace the
  row. (Keeps DB write volume low when a rule reports the same data
  every interval.)
- Only on successful processing (§2 rule 2).
- **Discard steps are not allowed on topology rules.** Every processed
  value is a freshness confirmation for `clock`; unchanged data is
  already cheap through the same-hash branch above, so throttling gains
  nothing and silently coarsens freshness. The API rejects
  `Discard unchanged with heartbeat` (the only throttling step LLD rules
  support) on discovery rules and rule prototypes with
  `topology_role ≠ NONE`, on create and update. Validation checks the
  **resulting** state: role and steps each taken from the request if
  present, otherwise from the DB, so changing only the role or only the
  preprocessing is caught in both directions. Inherited rules are
  covered by the template-level check (template linking overwrites the
  host rule's preprocessing).
- The **master item** of a dependent topology rule can't be restricted
  by the API (no rule ↔ master link at validation time). A discard step
  there stops values reaching the rule and freezes the snapshot `clock`.
  Documented only; see §9.

### 4.5 Surfacing problems

If rows were dropped for contract violations, add a line to the rule's
existing LLD error/info text (the same place LLD reports "cannot create
item…"), e.g.:

`Topology (NEIGHBORS): 3 of 41 rows skipped: missing {#IFINDEX}`

Group by reason, show counts, not per-row detail. Don't make the rule
unsupported for this — a partially valid snapshot is still written.

### 4.6 Role changes (PHP, API)

When `topology_role` of a rule changes (any value → any other value,
including to NONE), `CDiscoveryRule::update` deletes that rule's
`topo_lld_snapshot` row (and those of its inherited children, which
change with it). A snapshot must never be read under a role it wasn't
written for. Ingest additionally ignores snapshots whose `role` doesn't
match the rule's current role (defense in depth).

## 5. Ingest: inputs and reporter identity

### 5.1 Reporter discovery

A reporter = a host with at least one snapshot. Read snapshots grouped
by `hostid` directly from the DB (ingest already writes `topo_*`
directly; the staging table has no API). Skip snapshots of disabled
rules or rules whose current role ≠ snapshot role.

**Transition rule:** if a host has **both** LLD snapshots and the old
Trapper blob item (`topology.discovery.raw`), use the snapshots, ignore
the blob, and log it once per run. Hosts with only the blob keep working
through the old path — both paths coexist during Part 2 so results can
be compared (§10).

### 5.2 Reporter's own `Device` (`reporter_self`)

The blob used to carry `reporter.{chassis_id, mgmt_ip, sysname}`. Now:

1. If a `Device` already has `represented_by` = this host with
   `matched_by = 'reporter_self'` → that's the Device.
2. Else upsert by model spec §3 rule 4 keys, sourced as:
   - `chassis_id` ← `loc_chassis` from the host's snapshots (any role);
   - `mgmt_ip` ← IP of the host's SNMP interface.
3. If `loc_chassis` has **more than one distinct value** across the
   host's snapshots → identity is ambiguous: skip the whole reporter for
   this run and report it (§8). Don't pick one.
4. If neither key is available → skip the reporter and report it. **Never
   create a keyless Device.**
5. Then set `represented_by` exactly as model spec §4.1 describes
   (including "log and skip" on conflict).

The "was a neighbor first, became a reporter later" transition (model
spec §8's end-to-end criterion) must keep working through step 2.

### 5.3 Processing order within one reporter

All of a reporter's snapshots are read in the same run, so order is
under ingest's control: **self Device → PORTS → LAG → NEIGHBORS →
LEARNED_MACS**. Snapshots may still be of different ages (e.g. PORTS 30
min old, NEIGHBORS fresh); natural keys make that safe (§6.2).

## 6. Ingest: per-role handling

### 6.1 PORTS

- Upsert `Port` by `(device_id, if_index)`; refresh the attrs present in
  the row.
- `if_type`: map the IANA number to `physical` / `lag` / `mgmt` with the
  **same classification logic `push.py` uses today** — port it, don't
  reinvent it.
- Ports missing from the snapshot: do whatever the current blob path
  does (keep current behaviour; don't add deletion here).
- Before upserting, run the existing unconfirmed-port merge, reactive
  half (model spec §3 rule 4), exactly as the blob path does.

### 6.2 Reporter-side ports created by other roles

A NEIGHBORS / LEARNED_MACS / LAG row may reference an `if_index` that has
no `Port` yet (no PORTS rule, or the PORTS snapshot is older). Upsert a
**confirmed** `Port(device_id, if_index)` with whatever the row provides
(`if_name` for NEIGHBORS). The reporter's own side is always confirmed —
its `if_index` is real. **Never** create an unconfirmed port on the
reporter's own side. When a PORTS snapshot arrives later it updates the
same row (same id).

### 6.3 LAG

- Only if the reporter **has** a LAG snapshot. A reporter without a LAG
  rule: never touch any `lag_id`.
- With a snapshot: upsert the aggregate `Port(device_id, lag_if_index)`
  (`if_type = lag`) **before** writing any member's `lag_id`; set members'
  `lag_id`; members of this Device not in the snapshot → `lag_id = NULL`
  (model spec §3 rule 4, "silence overwrites").
- Enforce the `lag_id` target `if_type = lag` invariant (model spec §2.1).

### 6.4 LEARNED_MACS

- Only if the reporter has a LEARNED_MACS snapshot.
- Per port: `learned_macs` := MACs from the snapshot (replace, don't
  append). Rows with `port_mac_count` and no `mac` → set
  `attrs.learned_mac_count`, `learned_macs = []`.
- Ports of this Device absent from the snapshot → `learned_macs = []`,
  `learned_mac_count` removed.
- No `Device` is ever created from a MAC (model spec §3 rule 1).

### 6.5 NEIGHBORS

Each row is one neighbor observation, processed with the **existing**
neighbor logic: `Device` upsert (chassis_id → mgmt_ip → sysname scoped to
reporter+port), remote port resolution by subtype (prefer
`rem_port_desc`), unconfirmed port with both merge halves,
canonicalized `physical_link`, manual-wins conflict handling. Only the
input shape changes (row fields instead of `neighbors[]` entries).

Every observation gets an outcome (§7).

### 6.6 Deliberate changes to the model spec (small, explicit)

1. **`last_seen` source.** `last_seen_src`/`last_seen_dst` are set from
   the snapshot `clock`, not from ingest wall-clock time.
2. **Two rules confirming one side** (e.g. LLDP and CDP rules on the same
   reporter both see the same neighbor): the side's `last_seen_*` =
   max clock of all `applied` observations for that edge from that
   reporter. Decided here; this was Part 1 §13's third open question.
3. **`discovered_via` values.** Set from the row's `source`
   (`lldp`/`cdp`/other). Everything ≠ `manual` counts as discovered: the
   "manual → lldp upgrade" rule (model spec §3 rule 5) becomes "manual →
   discovered". **Check the frontend**: any code comparing
   `discovered_via === 'lldp'` must become `!== 'manual'`, or CDP links
   will render as manual.

### 6.7 Discovered-vs-discovered contradiction — keep current behaviour

A reporter's port P has a discovered `physical_link` P↔B; a new snapshot
from the **same** reporter shows P↔C (cable moved). The model spec's port
uniqueness forbids both links; §3 rule 5 covers "manual wins" and
"disappearance", but not this case. **First check what the current blob
ingest does here and keep exactly that**; record the observation's
outcome as `conflict` with `edge_id` = the blocking link. Don't design a
new rule in Part 2 — see §12.

## 7. `topo_observations`

NEIGHBORS only. Ports, MACs and LAG are self-description of the reporter
and go straight to `topo_nodes`; storing them here would only duplicate
data and let FDB volume in.

```
topo_observations
  id             bigint   PK
  itemid         bigint   NOT NULL, FK → items.itemid ON DELETE CASCADE      -- the NEIGHBORS rule
  local_port_id  bigint   NOT NULL, FK → topo_nodes.id ON DELETE CASCADE
  remote_key     varchar(255) NOT NULL   -- 'chassis:<type>:<value>' | 'ip:<addr>' | 'sysname:<name>'
  remote_attrs   json/text NOT NULL      -- rem_* fields as received (untrusted, model spec §9)
  outcome        varchar(16) NOT NULL    -- see below
  edge_id        bigint   NULL, FK → topo_edges.id ON DELETE SET NULL
  device_id      bigint   NULL, FK → topo_nodes.id ON DELETE SET NULL        -- resolved remote Device, if any
  first_seen     integer  NOT NULL
  last_seen      integer  NOT NULL       -- snapshot clock
  UNIQUE (itemid, local_port_id, remote_key)
```

`remote_key` uses the strongest identity key present (chassis → ip →
sysname), prefixed with its type, so the same neighbor seen by the same
rule always maps to the same row.

| outcome | Meaning | `edge_id` | `device_id` |
|---|---|---|---|
| `applied` | link created/confirmed | the confirmed link | remote Device |
| `device_only` | remote Device resolved, remote port didn't (model spec §3 rule 1 note) | NULL | remote Device |
| `shadowed` | blocked by a **manual** link on either port (model spec §3 rule 5) | the manual link | remote Device |
| `conflict` | blocked by a **discovered** link (§6.7) | the blocking link | remote Device |
| `ambiguous` | merge/match had 0-or-many candidates where exactly one is required | NULL | NULL or best known |

Lifecycle:
- The table mirrors the **latest** snapshot of each rule: after
  processing a rule, delete its observations not present in that
  snapshot. History lives on edges (`last_seen_*`), not here.
- `first_seen` is kept while the observation stays continuously present.
- Invariant: **edges are results, observations are evidence**.
  `last_seen_*` on an edge is only ever advanced from `applied`
  observations.

This gives the data for the lab finding "manual-link shadowing is
invisible" (Router1 Gi0/1 manual → UPS1, LLDP sees Printer1): the
Printer1 observation becomes `shadowed` with `edge_id` = the manual link
and `device_id` = Printer1. The UI badge proposed for that finding is
**not** part of this spec; it can now be built on this row.

## 8. Diagnostics (minimal)

- `GET /topo/ingest/status` summary gains per-run counts:
  `reporters_processed`, `reporters_skipped` (with reason:
  `identity_ambiguous` / `identity_missing`), `snapshots_ignored_role_mismatch`,
  and observation counts per `outcome`.
- `GET /topo/observations?outcome=…&device_id=…` — list observations
  (default: everything except `applied`), with local port name, reporter
  host name (live join), remote attrs (escaped per model spec §9), and
  the blocking edge where relevant. JSON only; no new UI page required.

## 9. Temporary lab template (JS)

Needed because the preset step is Part 3. One template, **"Topology by
SNMP (temporary JS)"**:

- Master: SNMP agent `walk[…]` with IF-MIB (`ifName`, `ifType`,
  `ifPhysAddress`, `ifAdminStatus`, `ifOperStatus`, `ifHighSpeed`),
  LLDP (`lldpLocChassisId`, `lldpLocPortTable`, `lldpRemTable`,
  `lldpRemManAddrTable`), `dot1dBasePortIfIndex`, Q-BRIDGE and BRIDGE FDB,
  `dot3adAggPortAttachedAggID`. Type Text, **no history** (store 0).
  Split into two masters if FDB makes the LLDP interval impractical
  (LLDP 30 min, FDB 10 min).
- **The master item must have no discard / throttling preprocessing
  steps** (`Discard unchanged`, `Discard unchanged with heartbeat`):
  they would stop values reaching the dependent topology rules and
  freeze snapshot `clock` (§4.4). The API can't enforce this; the
  template and Part 3's preset test must.
- Four **dependent** discovery rules, one per role, each with one JS
  step emitting contract rows:
  - PORTS: straightforward per-ifIndex rows.
  - NEIGHBORS: join `lldpRemTable` → `lldpLocPortTable` → ifIndex with
    the subtype-based chain (interfaceName → match `ifName`; macAddress →
    match `ifPhysAddress`; local → treat as ifIndex) and fallback
    "LocalPortNum = ifIndex". Decode chassis/port ids by subtype, mgmt
    IP from the `lldpRemManAddrTable` index. **Port the logic from
    `push.py`** where it exists rather than rewriting it.
  - LEARNED_MACS: Q-BRIDGE if present, else BRIDGE; bridge port →
    ifIndex; only `learned` status; MAC decoded from index; ports with
    more than 20 MACs → one count-only row.
  - LAG: `dot3adAggPortAttachedAggID` member → aggregator, skipping
    members attached to themselves (not aggregated).
- Each JS step must return `[]` (not an error) when its source table is
  simply empty, and throw when the walk itself is unusable — this is §2
  rule 2 in template form.
- **This JS is the reference implementation for Part 3's preset step.**
  Keep it readable and commented per branch/subtype; Part 3 will port
  it to C.

## 10. Acceptance criteria

**Server snapshots**
- A NEIGHBORS rule on a device with no LLDP neighbors writes a snapshot
  with `rows = []` and a current `clock`.
- Make the rule fail (wrong SNMP community): the previous snapshot stays
  byte-identical (same `rows_hash`, same `clock`).
- Same data twice: the second processing updates `clock` only —
  `rows_hash` unchanged and the row not rewritten.
- Rows violating the contract are dropped, counted in `rows_valid` vs
  `rows_total`, and reported in the rule's LLD info text; the rule stays
  supported.
- A macro supplied only via an LLD macro path (JSONPath) lands in the
  snapshot under its normalized field name.
- Changing a rule's role deletes its snapshot (and its inherited
  children's); a snapshot whose `role` ≠ current role is ignored by
  ingest even if it somehow remains.
- Proxy-monitored host: snapshot is written by the server exactly as for
  a server-monitored host (no proxy change needed or made).
- A discovery rule or rule prototype with `topology_role ≠ NONE` and a
  `Discard unchanged with heartbeat` step is rejected on create, and on
  update when only the role or only the preprocessing is changed so that
  the resulting state has both. Removing the role and adding the step in
  one call (or vice versa) is accepted.
- Linking a template with a role-bearing rule to a host that already has
  a rule with the same key and a discard step leaves the host rule
  without the step.

**Ingest**
- **Equivalence with the blob path:** the lab network ingested from a
  clean `topo_*` state via the old blob path and, separately, via LLD
  snapshots yields the same `Device`s (by `chassis_id`/`mgmt_ip`),
  `Port`s (by Device + `if_index`), `physical_link`s (by both endpoints'
  natural keys) and `represented_by` targets. Compare by natural keys —
  ids differ between two clean runs.
- **Convergence:** 5–7 consecutive ingest runs over unchanged snapshots
  keep the same row **ids** and the same `last_seen_*` values (model
  spec §3 rule 4's methodological note; `last_seen` must not advance
  without a new snapshot clock).
- **Order independence:** with only a NEIGHBORS snapshot present, the
  reporter-side `Port` is created confirmed; adding a PORTS snapshot
  later updates the same `Port` id; no unconfirmed port ever exists on
  the reporter's side.
- A reporter with no LAG rule: pre-existing `lag_id` values are
  untouched. With a LAG rule: removing a member from the snapshot sets
  its `lag_id` to NULL.
- LEARNED_MACS replaces `learned_macs`; a trunk port gets
  `learned_mac_count` and an empty `learned_macs`; no Device is created
  from any MAC.
- The neighbor-to-reporter lifecycle scenario from model spec §8 passes
  with both switches on LLD snapshots (same Device reused, unconfirmed
  port merged, one edge), checked by id.
- Reporter identity: two different `loc_chassis` values on one host →
  reporter skipped, reported as `identity_ambiguous`; no `loc_chassis`
  and no SNMP interface IP → skipped as `identity_missing`; no keyless
  Device is ever created.
- A host with both a blob item and LLD snapshots is ingested from the
  snapshots only, with one log line.
- LLDP and CDP rules on the same reporter confirming the same link →
  one edge; that side's `last_seen_*` = the newer of the two clocks.
- A CDP-sourced link renders as discovered (solid), not manual.

**Observations**
- Reproduce the shadowing case with a manual link: the LLDP neighbor
  behind the manual link gets `outcome = shadowed`, `edge_id` = the
  manual link, `device_id` = the neighbor Device; it's returned by
  `GET /topo/observations`.
- A neighbor whose remote port can't be parsed → `device_only` with the
  Device set and no edge.
- A neighbor disappearing from the snapshot → its observation row is
  deleted on the next ingest; the edge keeps its frozen `last_seen_*`.
- Cable-move case (§6.7): behaviour identical to the blob path, with the
  observation recorded as `conflict` and pointing at the blocking edge.

## 11. Security

Snapshot rows and `remote_attrs` carry untrusted LLDP/CDP strings.
Nothing about Part 2 makes them safer: model spec §9 applies to every
place they're rendered, including the new `/topo/observations`
endpoint's consumers. Store as received; escape at render.

## 12. Open questions (not blockers for Part 2)

- **Discovered-vs-discovered contradiction (§6.7).** Proposed rule for
  a later part: if the **same reporter's same port** now observes a
  different neighbor, its previous discovered link on that port is
  contradicted by the same source and can be replaced; if the
  contradiction comes from a *different* reporter, record `conflict`
  and keep both sides visible. Needs a decision before it's built — it
  softens model spec §3 rule 5 for discovered links.
- **Timestamped manual links** (lab finding): with observation clocks
  now available, "manual link older than a contradicting `shadowed`
  observation" becomes detectable. Model change; separate decision.
- **Snapshot size.** FDB snapshots on large cores can be MBs. If the
  count-only threshold isn't enough, consider moving MAC aggregation
  into the server (Part 3's preset step makes this natural).
- **Scheduling ingest.** Still manual. With snapshots written
  continuously by the server, a periodic ingest is the obvious next
  step; out of scope here.
