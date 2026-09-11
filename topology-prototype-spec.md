# Zabbix topology prototype — build spec

## 0. Objective

Build an MVP prototype that models physical network topology (L2 devices and
ports) alongside Zabbix monitoring objects (hosts), binds the two together,
and exposes a UI that shows both as one graph — including devices that are
not (yet) monitored as Zabbix hosts.

This is a prototype to validate the data model, not a production feature.
Favor simplicity and readability over performance or completeness.

## 1. Scope

**In scope:**
- Node types: `Device`, `Port`, `Host`, `Proxy`
- Edge types: `part_of`, `physical_link`, `member_of_lag`, `represented_by`, `monitored_by`
- Static seed data standing in for the discovery collector (see §4)
- Manually-triggered Zabbix API host pull
- MAC-based reconciliation (strong-key match only)
- Topology graph UI (Device/Host/Proxy nodes, click-to-expand neighbors)
- Port drill-down table per device (grouped by connection type)

**Explicitly out of scope for this prototype — do not build:**
- The discovery collector itself — deferred to a later iteration to save
  build time; §4 describes the seed data that replaces it for now. §4 also
  compares several delivery mechanisms for when the real collector is
  built (LLD item-per-neighbor, External check, Trapper, standalone daemon)
  — no single one is designated as "the" choice at the spec level, since
  the right pick depends on deployment specifics not yet known. Whichever
  is chosen, the ingest logic (§3) is unaffected by where the raw data came
  from, and the eventual output must still match the seed data's shape
  exactly (§4) — nothing else in this spec has to change when it's added.
- `VLAN`, `Subnet`, `IPAddress` nodes/edges
- `Service` tree and `APMService` nodes/edges
- Temporal versioning (`valid_from`/`valid_to`) beyond a simple `last_seen` timestamp
- Automated confidence-scored / fuzzy identity matching
- Scheduled or cron-based discovery
- Reachability / SPOF / blast-radius graph algorithms
- CAM-table (`dot1dTpFdbTable`) walk — the real collector (§4.1) now
  exists, so this is a live, deliberately-deferred gap rather than a
  hypothetical one: `Port.attrs.learned_macs` is never populated by
  `push.py`, so rule 1's "MAC learned only via CAM table → appended to
  `learned_macs`, no `Device` created" branch is currently unreachable
  through the real pipeline (only the static seed fixture in §4
  exercises it). See §11.
- **LAG membership discovery** (`ifStackTable`/`ieee8023adTable` walk) —
  `push.py` detects a LAG-type interface itself (`ifType`
  `ieee8023adLag`, reported as `if_type: "lag"` in a port's blob entry),
  but §4.1's blob schema was never specified to carry *which physical
  ports are members of that LAG* — no `ifStackTable`/`ieee8023adTable`
  walk was ever in scope, so there is no data for `ingest.php` to build a
  `member_of_lag` edge from. `ingest.php` is not missing anything on its
  end (it correctly creates no `member_of_lag` edge without the data); the
  gap is entirely on the push/blob-schema side. A `Port` with
  `if_type: "lag"` from the real collector today is expected to land
  member-less. See §11.

If a requirement not listed above seems necessary while implementing, stop and
flag it rather than silently expanding scope.

## 2. Data model

### 2.1 Storage

Generic property-graph tables, added as new tables in the target Zabbix
database (match the engine already in use — MySQL or PostgreSQL):

```sql
CREATE TABLE topo_nodes (
  id         BIGINT PRIMARY KEY AUTO_INCREMENT,
  type       VARCHAR(32) NOT NULL,   -- 'device' | 'port' | 'host' | 'proxy'
  host_ref   BIGINT NULL UNIQUE REFERENCES hosts(hostid) ON DELETE CASCADE,  -- set only when type='host'
  proxy_ref  BIGINT NULL UNIQUE REFERENCES proxy(proxyid) ON DELETE CASCADE, -- set only when type='proxy'
  attrs      JSON NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE topo_edges (
  id         BIGINT PRIMARY KEY AUTO_INCREMENT,
  type       VARCHAR(32) NOT NULL,   -- 'part_of' | 'physical_link' | 'member_of_lag' | 'represented_by' | 'monitored_by'
  src_id     BIGINT NOT NULL REFERENCES topo_nodes(id),
  dst_id     BIGINT NOT NULL REFERENCES topo_nodes(id),
  attrs      JSON NOT NULL DEFAULT ('{}'),
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_topo_edges_src ON topo_edges(src_id, type);
CREATE INDEX idx_topo_edges_dst ON topo_edges(dst_id, type);
```

`host_ref`/`proxy_ref` are plain `UNIQUE` columns (not partial/filtered) — this
works unmodified on both MySQL and PostgreSQL, since both treat multiple
`NULL`s as non-conflicting under a `UNIQUE` constraint, and the column is
`NULL` for every non-`host`/`proxy` node anyway.

**Edge-level uniqueness needs care — `topo_edges` is shared across types, so
a plain column constraint isn't enough.** Two rules to enforce, detailed
where they're most relevant: `represented_by` 1:1 cardinality (§2.3) and
`physical_link` duplicate/reversed-pair prevention (§2.3). Both require a
uniqueness check scoped to `type`, which is straightforward as a partial
index on PostgreSQL (`CREATE UNIQUE INDEX ... ON topo_edges(src_id) WHERE
type='represented_by'`) but needs the generated-column workaround on MySQL
(a virtual column that's `src_id` when `type='represented_by'` else `NULL`,
with a plain `UNIQUE` on that column) — pick whichever matches the engine
actually in use, per §2.1's original engine note.

Do not create separate typed tables per node/edge type for this prototype —
the JSON-attrs approach is deliberately chosen to keep the schema stable
while the model is still being validated.

### 2.2 Node attrs by type

- **device**: `{mac, chassis_id, mgmt_ip, sysname, vendor, last_seen}`.
  `sysname`/`chassis_id`/`vendor` come from LLDP/CDP data announced by network
  devices — untrusted input, not from Zabbix or the person operating this
  tool. See §9 before rendering any of these anywhere in the UI.
- **port**: `{if_index, name, if_type: "physical"|"lag"|"mgmt", mac, speed, admin_status, oper_status, learned_macs: [], zabbix_itemids: [], pseudo}`.
  Named `Port`, not `Interface`, specifically to avoid colliding with Zabbix's
  own `host.interfaces` (agent/SNMP/JMX/IPMI monitoring endpoints) — a
  different concept entirely. Use "port" consistently in code, comments, and
  endpoint names for this node type; reserve "interface" for Zabbix's own
  meaning when that comes up (e.g. `selectInterfaces`, `interfaces[]` in §3.2).
  **`pseudo` (boolean)**: `true` when this `Port` was created only from a
  *neighbor's* LLDP sighting of it (its owning `Device` has never pushed its
  own `ports[]` data — real SNMP `ifIndex` unknown, so ingest fabricates one,
  §4.1); `false` when it came from the owning `Device`'s own push. Exists to
  drive the pseudo-port merge in §3 rule 4: when a `Device` that only had
  pseudo-`Port`s eventually becomes a reporter itself and pushes its real
  ports, a pseudo-`Port` that turns out to be the same physical port as a
  newly-arrived real one needs to be recognized and merged rather than left
  standing alongside it as a duplicate (see the `physical_link` duplication
  note in §2.3 for the failure mode this prevents).
- **host**: `{}` (or empty) — a thin pointer only. `host_ref` (see §2.1) is the
  single source of truth for identity; name, status, and any other display
  data are resolved with a live join against `hosts` at read time, never
  copied into `attrs`. This node exists only so `represented_by` (and later
  `runs_on`, `service_composed_of`) have a stable graph endpoint to point at.
- **proxy**: `{}` (or empty) — same thin-pointer pattern as `host`, via
  `proxy_ref` (see §2.1). A `Proxy` is itself an ordinary physical/virtual
  machine, so it can also get its own `represented_by` edge from a `Device`,
  exactly like a `Host` — it is not a special case in the model, just a
  different `monitored_by` destination.

`Port` does not carry a redundant `device_id` attribute — its parent is
always resolved via the `part_of` edge. Do not duplicate this for query
convenience; keep the edge as the single source of truth.

`zabbix_itemids` holds real foreign keys into `items.itemid` (the items that
monitor this port — e.g. `ifInOctets`, `ifOperStatus`). There is no
separate `item` node type and no edge for this: unlike `Host`, an item is
never a hub other edges need to point at, so a plain FK list is enough. Item
key, name, and last value are resolved with a live join, same as `Host` name
and status — never duplicated into `attrs`.

### 2.3 Edges

| type | src → dst | attrs |
|---|---|---|
| `part_of` | Port → Device | `{}` |
| `physical_link` | Port → Port | `{discovered_via: "lldp"\|"manual", last_seen}` |
| `member_of_lag` | Port[physical] → Port[lag] | `{}` |
| `represented_by` | Device → Host or Proxy | `{match_type: "identity"\|"manual", matched_by: "mac"\|"reporter_self"\|"manual", matched_mac, created_at}` |
| `monitored_by` | Host → Proxy | `{}` |

**`represented_by` is 1:1 in both directions.** A `Device` can have at most
one active `represented_by` edge (to a `Host` *or* a `Proxy`, never both at
once), and a given `Host`/`Proxy` can be the target of at most one
`represented_by` edge. Enforce this with the uniqueness mechanism described
in §2.1 (partial index on `src_id` where `type='represented_by'`, and
separately on `dst_id` where `type='represented_by'`). Creating a second
`represented_by` for either side must fail, or must first require an
explicit `/depromote` (§6) — never silently replace the existing edge.

**Known limitation, deliberately accepted for MVP: "split" topologies aren't
representable.** A single physical device monitored as two separate Zabbix
identities (e.g. an OS-agent host and an IPMI/BMC host for the same server,
or separate SNMP and agent hosts for one box) cannot both be linked to the
same `Device` — the 1:1 rule above blocks the second `/promote`. This is a
conscious MVP simplification, not an oversight: supporting it would require
making the constraint asymmetric (drop uniqueness on `src_id`, keep it on
`dst_id`) and would break §7's rendering design, which assumes exactly one
`Device` per `Host`/`Proxy` (a `Host`/`Proxy`'s side panel has at most one
Device section — see §7). If this becomes a real need later, treat it as a
design task, not a quick constraint tweak — it touches §2.1, §6's
`/promote` validation, §7's rendering, and §8's acceptance criteria all at
once. For now, if a device genuinely has two Zabbix
identities, pick one to `represented_by` and leave the other unassociated
(or linked to the first via a manual `physical_link`, per §3.5, as a
workaround if the relationship needs to be visible at all).

**The same limitation holds in reverse, and this direction is more likely to
come up in practice.** Just as one `Device` can't `represented_by` two
`Host`s, the `dst_id` uniqueness means one `Host` can't be `represented_by`
by two `Device`s. This blocks representing **stacked switches** (multiple
physical chassis, each with its own `chassis_id` over LLDP, managed and
monitored as one logical Zabbix host) or an **MLAG pair** — both are common
enterprise topologies, more likely to be hit than the split-identity case
above. Same root cause, same fix if it's ever needed (asymmetric constraint
+ a §7 rendering redesign to show more than one Device section on a single
`Host`/`Proxy` panel), same "pick one `Device` to represent it, leave
the rest topology-only or manually linked" workaround for now.

**A reporter's own `Device` is `represented_by` its own `Host` deterministically —
this is a separate path from §3.2's opportunistic MAC reconciliation, not a
variant of it.** Ingest already knows, from the `item.get` call that located
this reporter's `topology.discovery.raw` item in the first place, exactly
which `hostid` that item belongs to (§4.1). There is no ambiguity to
resolve — the reporter *is* that `Host`, by construction, regardless of
whether its Zabbix inventory happens to carry a matching MAC. So ingest
creates the `represented_by` edge directly from the reporter's `Device` to
that exact `Host`, with `matched_by: "reporter_self"`, **without** calling
into the §3.2 MAC-matching function for this edge at all. This does not
change §3.2 itself — every entry in the blob's `neighbors[]` array still
goes through the same opportunistic MAC reconciliation as before; only the
reporter's *own* self-identification gets this deterministic shortcut,
since only the reporter's identity is actually known for certain. The 1:1
constraint above still applies in full: if the `Device` already carries a
*different* active `represented_by` (e.g. a prior manual `/promote` to the
wrong `Host`, or a stale match from before), this write is skipped and
logged as a conflict — never silently overridden, same rule as every other
`represented_by` write path.

**`represented_by` can be created manually, independent of reconciliation.**
The `/promote` endpoint (§6) is a deliberate user action and does **not**
run the strong-key check from §3.2 — a person can link any `Device` to any
`Host`/`Proxy` they choose, with no MAC/chassis-ID match required. This is
intentional, not a gap to close: automatic reconciliation is opportunistic
(see §3.2 on why the match data isn't always available), and manual
`/promote` is the explicit fallback for every case it doesn't cover. Edges
created manually, and there's no promotion to LLDP over time like
`physical_link` has (§3.5) — a manual association stays manual unless
someone `/depromote`s it and a fresh match happens some other way.

**`physical_link` direction must be canonicalized to prevent reversed
duplicates.** Because `src_id`/`dst_id` are directional columns but a
physical link is not (A↔B and B↔A are the same cable), always write
`physical_link` with the numerically smaller `Port` node id as `src_id`
before insert. Combined with the uniqueness mechanism in §2.1 (unique on
`(src_id, dst_id)` where `type='physical_link'`), this prevents both an
exact duplicate and a reversed-direction duplicate of the same link.

**Canonicalization alone does not prevent a same-cable duplicate when both
endpoints are independently discovered.** Found in live testing: when both
`Device`s on either end of a cable are themselves reporters, each one's own
push independently reports the other as an LLDP neighbor. Ingest processes
each reporter's blob separately, and — before the pseudo-port merge in §3
rule 4 existed — had no way to recognize that a pseudo-`Port` it was about
to fabricate for "the neighbor's port" was the *same* physical port a
different ingest pass had already (or would later) create for real from
that neighbor's own push. Canonicalizing `(src_id, dst_id)` only de-dupes
when both sides already agree on the same two `Port` ids — it does nothing
when each side's pass ends up minting its own distinct pseudo-`Port` for
the other, producing two separate `physical_link` rows (and two phantom
`Port`s) for one real cable. The fix is the pseudo-port merge (§3 rule 4,
`Port.attrs.pseudo` in §2.2) — canonicalization still matters for the
same-port-pair case, it just isn't sufficient on its own for this one.

**A `Port` can have at most one active `physical_link`.** This was missing
from earlier passes on this spec — a physical port has exactly one cable in
it, so nothing should allow a second `physical_link` row from (or to) a
`Port` that already has one. Enforce with the same partial-unique-index
mechanism as elsewhere (§2.1): unique on `src_id` where
`type='physical_link'`, and separately on `dst_id` where
`type='physical_link'`. This holds even for LAG member ports — aggregation
happens at the `member_of_lag` level, not by letting one physical port carry
multiple `physical_link` rows; each physical member port still connects to
exactly one specific port on the other side.

**`monitored_by` semantics — do not conflate with an outage.** A `Proxy`
becoming unreachable means Zabbix loses *visibility* into every `Host` it
monitors — it does not mean those hosts are actually down. Any UI or logic
that walks `monitored_by` backwards from a broken `Proxy` must present this
as "monitoring blind spot behind this proxy", never as "these hosts are
down". Silently treating a proxy outage as a host outage would make the
graph actively misleading, not just incomplete.

**`physical_link` problem styling — derived, not stored.** A link can be
styled by Zabbix trigger severity (see §6, §7) by resolving the triggers
attached to its two endpoint `Port`s' `zabbix_itemids`, live at read time —
same live-join pattern as everything else in §2.2. No new edge attrs are
needed for this and none should be added. Keep this signal distinct from
the port's own `oper_status`: `oper_status` is a raw SNMP fact, a trigger is
Zabbix's evaluated judgment on top of it — a link can be `oper_status: up`
while still carrying an active trigger (e.g. on error rate), and the two
must never be collapsed into a single indicator.

## 3. Node creation and reconciliation rules

These rules are the core of the model — implement them exactly, do not
"improve" them without checking back:

1. **Create a `Device` node only for an LLDP/CDP-speaking neighbor** (i.e. an
   `lldpRemTable` entry that resolves to a chassis ID / sysName). A MAC learned
   only via the CAM table (no LLDP response) is **not** a `Device` — it is
   appended to `learned_macs` on the local `Port` and nothing else is
   created for it. This is intentional: most switch ports terminate in
   non-LLDP endpoints (PCs, phones, printers), and creating a node per MAC
   would flood the graph with noise that carries no topological value.

2. **`Device` ↔ `Host`/`Proxy` matching uses MAC address only — not chassis
   ID, despite what an earlier version of this rule said.** There is no
   generic Zabbix host field that carries an LLDP chassis ID (host
   inventory has `macaddress_a`/`macaddress_b` and serial-number fields,
   nothing called "chassis ID") — a chassis-ID-based match was never
   actually implementable as stated, it just went unnoticed because a
   chassis ID is frequently *itself* a MAC address (LLDP subtype 4), so
   matches were silently going through the MAC path anyway. When a chassis
   ID is a non-MAC value (e.g. subtype 5, a locally-assigned string), there
   is no way to match it against a Host today. **The MAC source on the
   Zabbix side is `host.inventory.macaddress_a`/`macaddress_b`, not
   `host.interfaces[]`** — Zabbix's host interface object (`hostinterface`)
   has no MAC field at all (only `ip`/`dns`/`port`/`type`), so don't look
   for it there. Inventory MAC is populated only when a host's Inventory
   mode is set to Automatic *and* a `system.hw.macaddr` item is configured
   to populate that field — this is opt-in per host, not guaranteed to
   exist on an arbitrary Zabbix instance. Treat automatic reconciliation as
   opportunistic: when inventory MAC isn't populated for a given host,
   automatic matching simply won't find it — that's an expected, silent
   non-match, not an error. If no strong match is found, leave the `Device`
   unassociated — do **not** fall back to weak keys (hostname, bare IP) and
   do not auto-merge on a guess. The manual `/promote` path (§2.3, §6) is
   the intended way to cover every host that automatic matching can't reach
   for this reason — the two mechanisms are complementary, not redundant.
   (`chassis_id` remains a valid key for `Device`-to-itself upsert in rule
   4 below — that's a different match, `Device` recognizing the same
   physical entity across ingest runs, not `Device`-to-`Host` linking, and
   isn't affected by this correction.)

3. **`Device` nodes are never deleted** when a `represented_by` edge is
   removed (e.g. a Zabbix host is decommissioned). The physical device may
   still exist and participate in the network; only the edge goes away.

4. **Upsert, not insert**: re-running the collector against the same device
   must update existing nodes, not create duplicates. Match `Device` by
   `chassis_id` if present, else `mgmt_ip`, **else `sysname` scoped to the
   same local port of the same reporter** (i.e. match against a `Device`
   previously seen as a neighbor on that exact `local_if_index` of that
   exact reporter — never a global `sysname` match across the whole
   network). This third key exists specifically for LLDP neighbors known
   only by name (no chassis ID, no management IP exposed) — confirmed as a
   real gap in live testing: without it, every ingest pass created a fresh
   `Device` for these neighbors instead of recognizing the same one, with
   unbounded growth on repeated runs. This is a different risk than the
   weak-key prohibition in rule 2: rule 2 is about not conflating two
   *different* real entities (`Device`↔`Host`/`Proxy`) on weak evidence;
   this is about re-recognizing the *same* previously-seen neighbor between
   ingest passes, which is why the narrow reporter+port scope is enough to
   keep it safe — a false match here would require two physically distinct
   devices sharing a `sysname` on the exact same port of the exact same
   reporter, not just anywhere on the network. Mark `Device.attrs` with
   `matched_by: "sysname"` when this key was used, so it's visibly a
   weaker signal than `chassis_id`/`mgmt_ip` on inspection. Match `Port` by
   `(device_id via part_of, if_index)`.

   **Pseudo-port merge, run only when upserting a reporter's own real
   ports** (never triggered by, and never part of, ordinary pseudo-port
   creation from a neighbor observation under rule 1 — that path is
   unaffected). After upserting a real `Port` (`pseudo: false`, §2.2) from
   a reporter's own `ports[]`, look for an existing **pseudo** `Port`
   (`pseudo: true`) on the *same* `Device` whose `name` matches the real
   port's `name` after normalization (vendor long/short forms —
   `GigabitEthernet` ↔ `Gi`, `TenGigabitEthernet` ↔ `Te`, and similarly for
   other common SNMP `ifDescr`/`ifName` abbreviations — case-insensitive).
   - **Exactly one match**: this is the same physical port, previously
     fabricated as a pseudo-port because this `Device` hadn't pushed its
     own data yet. Re-point every `physical_link` edge currently on the
     pseudo-`Port` onto the real `Port`, then delete the pseudo-`Port`.
   - **Zero matches, or more than one**: do nothing — leave every
     pseudo-`Port` as-is. Never guess; an ambiguous match left alone is
     recoverable (the next ingest pass gets another chance once the
     ambiguity resolves itself, e.g. a second pseudo-port gets cleaned up
     by some other means), a wrong merge is not.
   - A `Device` that never becomes a reporter itself (e.g. an end device
     with no SNMP/management access) keeps its pseudo-`Port`s forever —
     there's nothing to merge them with, and that's the expected, correct
     end state for such a device, not a gap to close.

5. **Manual `physical_link` creation is allowed, manual `Device` creation is
   not.** A person can draw a `physical_link` (`discovered_via: "manual"`)
   between two `Port`s that already exist on already-existing `Device`
   nodes — e.g. LLDP missed a real link because a vendor disabled the
   protocol. This does **not** reopen rule 1: it is never a way to create a
   new `Device` node just to have something to link — that stays governed
   entirely by rule 1. If LLDP later independently confirms the same link,
   upsert its `discovered_via` from `"manual"` to `"lldp"` rather than
   creating a duplicate edge. Discovery must never delete a manually-created
   link — same non-destructive-upsert principle as rule 3 for `Device` nodes.

## 4. Seed data (stands in for the discovery collector)

The SNMP/LLDP collector is deferred (see §1). To keep the data model, API,
and UI work unblocked, load a static fixture directly into `topo_nodes` /
`topo_edges` instead of collecting it live. The fixture must model the same
switch used in the earlier port-table mockup, so the UI can be checked
against a known-correct picture:

- One `Device` node for the 48-port switch (`mac`, `chassis_id`, `mgmt_ip`, `sysname`, `vendor`)
- 48 `Port` nodes with `part_of` edges to that `Device`, covering:
  - 40 connected ports — only 2 with a resolvable neighbor (create a second,
    neighbor `Device` + `Port` + `physical_link` edge for those); the
    other 38 are `learned_macs`-only, per the rule in §3.1 — no node created for them
  - 4 disconnected ports (`oper_status: down`, no `physical_link`)
  - 2 `Port` nodes with `if_type: "lag"`, each with two physical member
    ports joined via `member_of_lag`
  - 2 management ports (`if_type: "mgmt"`, no `physical_link` expected)
- One of the two neighbor `Device`s from the "40 connected ports" bullet above
  should have `sysname` set to an HTML/script payload (e.g.
  `<script>alert(1)</script>`) instead of a normal hostname — this is what
  the §8 XSS-rendering acceptance criterion checks against. Don't sanitize
  it in the fixture itself; the point is to verify the render path handles
  it, not to pre-clean the test data.
- Write this as a SQL script or a JSON fixture loaded by a small seed
  script, with **two explicit modes, not one truncate-and-reload behavior**:
  - default (no flag): **upsert** — match existing rows by the same natural
    keys as rule 4 in §3 (`Device` by `chassis_id`/`mgmt_ip`, `Port` by
    `device_id`+`if_index`) and update them, exactly like a real discovery
    run would. This is the mode that actually exercises the upsert logic,
    and it's what AC §8 checks.
  - `--reset` flag: truncate `topo_nodes`/`topo_edges` first, then load —
    kept only as a convenience to get back to a clean slate while iterating
    on the UI. Running with `--reset` does **not** test upsert logic (an
    empty table can't produce a meaningful match/no-match result) and
    should never be treated as if it does.

**When the real collector is built later, prefer delivering data through
Zabbix's own item/sync infrastructure over a fully standalone daemon** (see
§1) — but there are several viable ways to do that, with real trade-offs
between them, not one obvious winner. All of them share two things
regardless of which is picked:

- The collector logic itself (SNMP walk, `lldpRemTable`/`ifTable`/`ifXTable`
  parsing, `lldpRemPortIdSubtype` branching, vendor-specific handling —
  §11's backlog notes on this) doesn't change between options below; only
  *how the result gets from the polled device into `topo_nodes`/`topo_edges`*
  changes.
- **Reporter onboarding is deliberately out of scope for topology
  itself — this is a scope boundary, not a gap.** A reporter must already
  exist as a Zabbix `Host` (with the template from §4.1 attached) before
  its data can be ingested. Topology doesn't care, and must never be made
  to care, *how* that `Host` came to exist — manually, via Zabbix's own
  Network Discovery + Discovery Actions, via the API, via a CMDB import,
  anything. This spec should never grow a bespoke "find and register
  reporters" mechanism of its own: Zabbix already owns host lifecycle, and
  topology's job starts only once a reporter `Host` exists, full stop. If
  faster reporter onboarding is wanted operationally, recommend configuring
  Zabbix's own Network Discovery (an SNMP-check discovery rule over the
  relevant range, with a Discovery Action that creates the `Host` and
  attaches the template) as a **deployment pattern** — this is Zabbix
  configuration a deployer chooses to do, not something this spec
  implements or depends on.
- **This is also where topology's real value over Network Discovery shows
  up, and it's worth being precise about what that value actually is.**
  Network Discovery only ever produces `Host`s — it cannot see a device
  that doesn't answer whatever discovery check was configured (no SNMP, no
  agent, nothing pollable). LLDP-sourced topology data still reveals such a
  device as long as *some* onboarded reporter can see it as a neighbor —
  e.g. `Switch1` (a `Host`, found by Network Discovery or onboarded by
  hand) reports `Printer1` (never a `Host`, no discoverable service of its
  own) as an LLDP neighbor, and topology still renders `Printer1` as an
  unassociated `Device` node with a real `physical_link` to `Switch1`. This
  is the concrete case for the unmanaged-device support this whole model
  was built around (§1) — not a vaguer "topology discovers the network"
  claim. Once enough `Host`s exist as reporters (by whatever means),
  LLDP's role shifts from *finding new devices* to *finding relationships*
  between what's already known plus whatever unmanaged neighbors those
  reporters can see — that's the accurate framing, not "self-revealing
  network discovery."
- Whichever option is picked, an **ingest step still applies the exact same
  §3 rules** (evidence threshold, MAC/chassis-ID reconciliation, upsert,
  `physical_link` canonicalization) to turn the delivered data into
  `topo_nodes`/`topo_edges` rows — persisted, not recomputed live on every
  render (unlike the community reference module's approach), since
  `represented_by`'s 1:1 constraint, `/promote`/`/depromote`, and manual
  `/link` all depend on that persistence.

| | **LLD item-per-neighbor** | **External check (blob)** | **Trapper (blob)** | **Standalone daemon** |
|---|---|---|---|---|
| How it runs | Zabbix LLD rule discovers `lldpRemTable` rows, one item prototype per neighbor (community module's approach) | Zabbix executes our script on its own item interval; script does the full SNMP walk + parses + emits one JSON blob | Our own process (cron/systemd) runs independently and pushes one JSON blob via `zabbix_sender` | Fully separate process outside Zabbix entirely, writes straight to `topo_nodes`/`topo_edges` |
| Proxy-distributed automatically | Yes | Yes (runs on the proxy monitoring the host) | Only if we deploy it near each proxy ourselves | Only if we deploy it near each segment ourselves |
| New sync channel needed | No (existing item sync) | No (existing item sync) | No (existing trapper protocol) | Yes — the exact problem that started this whole discussion |
| Item/history namespace impact | High — dozens of items per reporter, visible in ordinary item browsing | Low — one blob item per reporter | Low — one blob item per reporter | None — never touches Zabbix items at all |
| Code deployment location | N/A (just SNMP OIDs in item config) | Must live in Zabbix's `ExternalScripts` dir, run as the Zabbix server/proxy user | Anywhere with network access to a trapper port | Anywhere with network + DB/API access |
| On-demand re-poll | No — bound to LLD discovery interval | Limited — "Execute now" in UI, still fundamentally interval-based | Yes — full control, we trigger it | Yes — full control |
| Self-monitoring ("is discovery even running") | Standard item staleness applies per item | Standard item timeout/unsupported handling | Must build our own (e.g. a `nodata()` trigger) | Must build our own |
| Requires Zabbix-side admin access to set up | Yes (template + LLD rule) | Yes (host + item + script deployed to Zabbix's own filesystem) | Yes (a trapper item must exist), but not filesystem access to Zabbix itself | No — pure network/API access to the target devices |

**Controller-API vendors (e.g. Ubiquiti UniFi — see §11's vendor-variance
note) are a separate case regardless of which row above is chosen**: for a
device whose topology only exists behind a REST API, not SNMP, a plain
**HTTP agent item with JS preprocessing** can poll and reshape that API's
JSON natively, with no external script needed at all. This isn't a
competing option to the table above — it's a delivery mechanism for a
specific vendor class that the table's SNMP-oriented rows don't cover, and
whichever primary option is chosen, this one will still be needed alongside
it for that vendor class.

No option above is designated as "the" choice here — it depends on
deployment specifics (firewall direction, who has filesystem access to the
Zabbix proxy/server, how many reporters, how time-sensitive re-discovery
needs to be) that aren't known yet at the spec level.

### 4.1 Chosen delivery mechanism for this iteration: Trapper

Picked over the other three rows in the table above for the on-prem-first
case (Zabbix Cloud compatibility was the deciding factor for the other
options — see the earlier design discussion — but isn't a near-term
priority given the current Cloud user profile). Two independent components,
not one script:

**Push component** (runs near each reporter/segment): does the SNMP walk
and pushes one JSON blob per reporter via `zabbix_sender`, into a Trapper
item on that reporter's `Host` (e.g. key `topology.discovery.raw`). No
access to `topo_nodes`/`topo_edges` or the Zabbix API needed — only SNMP
reach to the segment and network reach to the Trapper port.

```json
{
  "reporter": {"sysname": "...", "chassis_id": "...", "mgmt_ip": "...", "vendor": "..."},
  "ports": [{"if_index": 1, "name": "Gi0/1", "if_type": "physical", "mac": "...", "admin_status": "up", "oper_status": "up"}],
  "neighbors": [{"local_if_index": 1, "remote_chassis_id": "...", "remote_sysname": "...", "remote_port_id": "...", "remote_port_id_subtype": "interfaceName", "remote_port_desc": "..."}],
  "stats": {"neighbors_total": 0, "resolved": 0},
  "collected_at": "2026-09-10T12:00:00Z"
}
```

**Known gap in this blob shape, symmetric to §1's CAM-table exclusion:**
a `ports[]` entry with `if_type: "lag"` carries no information about which
*other* `ports[]` entries (by `if_index`) are members of that LAG — the
shape above was never extended with something like
`"lag_members": [<if_index>, ...]`, and no `ifStackTable`/
`ieee8023adTable` walk was ever specified to populate it. Concretely,
until this is picked up: `push.py` can correctly identify a LAG port
(`ifType` `ieee8023adLag`) and report it, but `ingest.php` has nothing to
build a `member_of_lag` edge (§2.3) from — a LAG `Port` node from the real
collector lands member-less, not a bug in `ingest.php`, a missing input.
See §11 for both this and the CAM-table gap together.

Rules for the push component:
- One blob per reporter — a single unresolvable/malformed device must never
  block data from the rest of the network. Partial failure within a
  reporter (one neighbor entry that doesn't parse) is skipped and counted
  in `stats`, not fatal to the whole blob — this is the seed for the
  "discovery-quality visibility" backlog item (§11), so build the counting
  in from the start rather than bolting it on later.
- Port label resolution: prefer `lldpRemPortDesc`; fall back to branching
  on `lldpRemPortIdSubtype` only when `PortDesc` is empty (§11's existing
  note on this).
- **Self-monitoring**: also push a separate heartbeat Trapper item
  (`topology.discovery.heartbeat`, just a timestamp) on the same interval.
  A `nodata()` trigger on it is the "is discovery even running" signal —
  standard Zabbix pattern, nothing custom needed.
- **Bootstrap — via template, not a runtime API call.** The push component
  must never call `item.create` or any other Zabbix API method — that would
  contradict its whole reason for existing as a separate component (no API
  access needed, only SNMP + the Trapper port; see the opening of this
  section). Instead, both Trapper items (`topology.discovery.raw`,
  `topology.discovery.heartbeat`) are defined once on a Zabbix template,
  which gets attached to a reporter `Host` at onboarding time — the same
  step, by the same person/access-level, that already has to create the
  `Host` itself per §1/§4's bootstrap requirement. The push component just
  writes to an item that's already there by the time it runs; it never
  provisions anything.

**Ingest component**: reads the latest `topology.discovery.raw` value per
reporter (via `item.get`/`history.get`), applies §3 exactly as already
specified (evidence threshold, MAC-based reconciliation, upsert,
`physical_link` canonicalization), writes to `topo_nodes`/`topo_edges`.
Runs on its own schedule, independent of the push interval — they don't
need to be synchronized. **A reporter's own `Device` is additionally
`represented_by` its own `Host` deterministically** (§2.3) — since the
`item.get` call that located this reporter already identifies its exact
`hostid`, there is no need to fall back to opportunistic MAC matching for
the reporter's own identity, only for the neighbors it reports.

**Reporter discovery must be dynamic, not a static list.** The ingest
component finds its set of reporters by calling `item.get` filtered on the
key `topology.discovery.raw` (or a key prefix, e.g. `topology.discovery.`)
across all hosts — every host with that item attached (i.e. every host
onboarded with the template from the bootstrap rule above) is a reporter.
**No config file (`reporters.json` or similar) listing reporters by hand** —
that reintroduces exactly the kind of manually-maintained registry this
architecture was meant to avoid, and silently drifts out of sync with
reality the moment a reporter is onboarded or decommissioned without
someone remembering to update the file too.

## 5. Zabbix API pull

- `host.get` with `selectInterfaces` **and `selectInventory`** → upsert
  `Host` nodes. `selectInventory` is not optional here — §3.2 reconciliation
  reads MAC from `inventory.macaddress_a`/`macaddress_b`, which isn't
  returned at all unless explicitly requested via `selectInventory`.
  Upsert here means ensuring a `topo_nodes` row with `type='host'` and
  `host_ref=hostid` exists — do not write `name`/`status` into `attrs`
  (see §2.2).
- `proxy.get` → upsert `Proxy` nodes the same way, via `proxy_ref`.
- For each `Host`, attempt Device reconciliation per §3.2 against existing
  `Device`/`Port` MACs, **and** create the `monitored_by` edge to its
  `Proxy` node (from `host.get`'s `proxyid` field) if one is assigned.
- For each `Proxy`, attempt the same Device reconciliation per §3.2 — a
  proxy is a machine like any other and can get its own `represented_by` edge.
- **If both a `Host` and a `Proxy` processed in the same pull match the same
  `Device`** (rare, but possible if they happen to share MAC/chassis-ID
  data), only the first one processed gets the `represented_by` edge — the
  1:1 constraint (§2.3) rejects the second. Log this as a skipped match, not
  an error, and continue the pull; one unresolved match in a large batch
  must never abort the rest of the run.
- Run manually — no scheduler in this prototype.

## 6. Backend API surface

Minimal REST surface for the UI. Every endpoint that returns a `Host` or
`Proxy` node must join `host_ref`/`proxy_ref` against `hosts`/`proxy` for
display name/status, and every endpoint returning port item info must
join `zabbix_itemids` against `items` — never read stale copies from `attrs`
(see §2.2).

- `GET /topo/devices` — all `Device`+`Host`+`Proxy` nodes (id, type, display name, monitoring state) for the graph view. For `Host` nodes, include `maintenance_status` (live-joined from `hosts.maintenance_status`/`maintenanceid`, never cached into `attrs`) — this is needed at the graph level, not only in a side panel, since it changes how the node's severity color should be read at a glance.
- `GET /topo/devices/{id}/neighbors` — 1-hop `physical_link` neighbors, for
  progressive graph expansion (never return the full graph in one call).
  Since the graph view never renders `Port` nodes (§7), this endpoint must
  traverse `Device → Port → physical_link → Port → Device` internally and
  return the resulting `Device`/`Host`/`Proxy` neighbors — `Port` nodes are
  an implementation detail of the traversal, never part of the response.
  Each neighbor edge includes a `severity` field, derived live from the
  trigger(s) tied to the two endpoint ports' `zabbix_itemids` (see §2.3) —
  highest active severity, or null if none.
- `GET /topo/devices/{id}/ports` — full port list for the side-panel table, grouped as: connected via LLDP / connected MAC-only / disconnected / port-channel / management
- `GET /topo/nodes/{id}/problems` — active problems/triggers for a `Host` or
  `Proxy` node (`id` here is a `Host`/`Proxy` node id, not a `Device` id —
  hence the different path prefix from the `Device`-rooted endpoints above,
  which all take a `Device` id). Live-joined from Zabbix (`problem.get`/
  `trigger.get`), never stored in `attrs`. `Proxy` nodes use this endpoint
  too when they themselves have a `represented_by` and their own problems
  (§7); not applicable to an unassociated `Device`.
- `POST /topo/devices/{id}/promote {host_id}` — manually create a
  `represented_by` edge. Deliberate user action, never automatic, and does
  **not** run the §3.2 strong-key check — see the "manual override" and
  "1:1 cardinality" notes under §2.3 for the exact rules this endpoint must
  enforce (reject if either side already has an active `represented_by`).
- `POST /topo/devices/{id}/depromote` — manually delete the `represented_by` edge for this `Device`. Hard-deletes the edge row; no soft-delete or `valid_to` marker (temporal versioning is out of scope, see §1). Does not touch the `Device` node itself — it reverts to the same unassociated state described in §3.3.
- `POST /topo/ports/{src_port_id}/link {dst_port_id}` — manually create a
  `physical_link` between two existing `Port`s (`discovered_via: "manual"`,
  per §3.5). Both ports must already exist on already-existing `Device`
  nodes — this endpoint never creates a `Device` or `Port`. **This endpoint
  itself must apply the canonicalization from §2.3** (store with the
  numerically smaller `Port` id as `src_id`) regardless of the order the
  caller passed `src_port_id`/`dst_port_id` in — don't rely on some other
  layer to have already normalized it, or a caller passing the pair in
  reverse order will create a duplicate instead of hitting the unique
  constraint.
- `DELETE /topo/ports/{src_port_id}/link/{dst_port_id}` — manually remove a
  `physical_link`. Applies to both `"manual"` and `"lldp"`-sourced links —
  removing an LLDP-confirmed link by hand is allowed (e.g. correcting a
  stale/wrong observation); it will simply reappear on the next discovery
  run if LLDP still reports it, same as any other upsert.
- `POST /topo/ingest/run` — manually trigger the ingest component (§4.1)
  from the UI, alongside the existing "Pull Zabbix hosts" control for §5.
  **Must call the exact same ingest code path as the CLI** (§4.1) — not a
  separate implementation — so the file-lock against concurrent runs
  already required there covers this entry point too without extra work.
  Starts the run in the background and returns immediately (e.g.
  `{status: "started"}`); must never block the HTTP request until the whole
  ingest pass completes, since that risks a request timeout on a real
  reporter count.
- `GET /topo/ingest/status` — poll for the state of the most recent run:
  `{status: "idle"|"running"|"done"|"error", started_at, finished_at,
  summary: {devices_created, devices_updated, ports_created, links_created}}`.
  Enough for a toast/summary in the UI — this is not the same as the
  per-reporter matched/unmatched diagnostics in §11's "discovery-quality
  visibility" backlog item, which is a separate, heavier feature.

## 7. Frontend

- **Global controls**: a "Run discovery ingest" button alongside the
  existing "Pull Zabbix hosts" control (§5) — both are manual triggers for
  a backend sync pass, same UI pattern. Calls `POST /topo/ingest/run`
  (§6), shows a running/spinner state while `GET /topo/ingest/status`
  reports `"running"`, then a brief summary (from `status`'s `summary`
  field) on completion — don't block the button or the rest of the UI
  while it runs, per §6's async requirement.
- **Graph view**: nodes are `Device`/`Host`/`Proxy` only — never render
  `Port` as a graph node (see §4 of the earlier design discussion: a
  48-port device would make the graph unreadable). Progressive expansion via
  the `/neighbors` endpoint, not a full-graph dump.
- **Visual encoding** (carry over exactly from the agreed mockups):
  - `Host`: solid fill, colored by Zabbix severity/status
  - `Host` that is **disabled** in Zabbix: neutral/muted fill, not a position
    on the severity scale — a disabled host isn't polled, so there is no
    severity to show; this is a distinct visual state from "severity: ok"
    (which means "monitored and currently fine"), not the same thing.
  - `Host` **in maintenance**: this is different from disabled — a host in
    maintenance is still actively monitored and can still have real active
    problems, just with notifications suppressed. Keep the normal severity
    color and add a separate maintenance badge/icon overlay on top, rather
    than muting the node. Do not reuse the blind-spot badge (§ below) for
    this — the two mean different things and must stay visually distinct.
  - `Proxy`: same solid-fill convention as `Host`, but visually distinguishable
    (e.g. a distinct icon or badge) — it is a different kind of node even
    though it shares the "monitored, has severity" styling
  - `Device` **with no `represented_by`**: rendered as its own node, dashed
    outline, neutral gray fill, no severity color.
  - `Device` **with a `represented_by` edge**: **not rendered as its own
    graph node at all.** An earlier version of this spec had it merge with
    its `Host`/`Proxy` into a single split-square node (dashed left half +
    solid right half, independently clickable halves); that design is
    dropped in favor of something simpler — once a `Device` is
    `represented_by` a `Host`/`Proxy`, only the `Host`/`Proxy` shows on the
    graph, with its styling exactly as already described above (severity
    color, disabled/maintenance/proxy-badge rules), completely unchanged by
    whether it happens to have an associated `Device`. The `Device`'s data
    (ports, etc.) isn't gone — it moves into a section of the `Host`/
    `Proxy`'s side panel (see below), it just has no on-screen position of
    its own anymore. No split shape, no half-and-half click routing to
    maintain.
  - **`represented_by` is never drawn as a line** — same rule as before,
    just simpler to state now: since the `Device` side has no on-screen
    position when `represented_by` is active, there's nothing for a line to
    connect to on that end anyway. `monitored_by` (`Host → Proxy`) remains
    the only edge type connecting monitoring-related nodes that's ever
    drawn as a line, with its own distinct line style/color — not the
    dashed/solid channel already used for `physical_link` provenance
    (that's a separate meaning and shouldn't be reused here).
  - `physical_link` **reattachment**: a `physical_link` whose endpoint
    `Port` belongs to a `Device` that is `represented_by` something must
    visually attach to that `Host`/`Proxy` node instead of the (now
    off-graph) `Device` — same "attach to whatever's actually on screen"
    principle the old merged-node design already applied, just pointing at
    the `Host`/`Proxy` directly now instead of a merged node's bounding box.
  - `physical_link` line style (unrelated to the reattachment above — this
    is about the link's own provenance, §2.3): solid when `discovered_via:
    "lldp"`, dashed when `discovered_via: "manual"`. Same dashed/solid
    language as `Device` association status, reused deliberately rather
    than inventing a new channel — but note it's carried by the edge here,
    not the node.
- **Blind-spot indicator**: when a `Proxy` node is unreachable, any `Host`
  connected to it via `monitored_by` must be visually marked as "monitoring
  blind spot" (e.g. a distinct badge/hatching), never rendered with the same
  problem-color styling used for an actual host-level issue — see the
  `monitored_by` semantics note in §2.3.
- **`physical_link` severity styling**: color/weight the link using the
  `severity` field from `/neighbors` (§6) — e.g. a link carrying an active
  disaster-level trigger renders differently from a quiet one. This is
  separate from the dashed/solid convention used for node association
  status; do not repurpose that same visual channel for link severity.
- **Side panel — standalone `Device`** (no `represented_by`): click → call
  `/ports`, render the grouped table (port / status / connected-to / source
  badge: `LLDP` / `MAC only` / `—`), same as always. Unchanged by this
  section's rewrite below — this only ever applied to a `Device` that has
  no on-screen `Host`/`Proxy` counterpart to begin with.
- **Side panel — `Host`/`Proxy`: one panel, up to two sections** (replaces
  the old two-panel / two-click-zone merged-node design above). A single
  click on a `Host`/`Proxy` node opens one panel with:
  - **Problems section** — always present: the same `/problems` call and
    rendering as before (severity, name, age; empty state if none).
  - **Device section** — present only when this `Host`/`Proxy` has an
    active `represented_by`: the same grouped `/ports` table that used to
    be the standalone `Device` panel's whole content, now living here
    instead, with the **"Depromote" button** inside it (it belongs to the
    `Device` side of the relationship, same as before — just relocated
    from "inside the merged node's left half" to "inside this section").
    If there's no associated `Device`, this section is simply absent — no
    empty placeholder to fill the gap.
- **"Promote to host" button**: unchanged — visible only on a standalone
  `Device` node (no `represented_by`); calls `/promote`. Once promoted, the
  `Device` stops being rendered as its own node (per the visual-encoding
  rule above) — its data now lives in the Device section of its `Host`/
  `Proxy`'s panel instead of a panel of its own.
- **Depromote**: calling `/depromote` (from the Device section above)
  reverses this exactly — the `Device` reappears as its own standalone
  dashed node, and the Device section disappears from the `Host`/`Proxy`'s
  panel, which goes back to Problems-only.

## 8. Acceptance criteria

- Loading the seed data produces `Device` + `Port` nodes whose
  connected/disconnected/LAG/management counts match §4 exactly (40/4/2/2),
  with only 2 of the 40 connected ports resolving to a neighbor `Device`.
- A Zabbix host **or proxy** matching the seed `Device` by MAC per §3.2
  (via `inventory.macaddress_a`/`macaddress_b` — not chassis ID, which
  isn't a valid `Device`↔`Host` match key; see rule 2's correction) ends
  up with a `represented_by` edge after the API pull runs. Test both the
  `Host` and `Proxy` paths, since the model allows either as the target.
- The UI graph correctly distinguishes `Host` (solid) from unassociated
  `Device` (dashed), and the port drill-down table groups ports the
  same way as in §7.
- Running ingest for a reporter whose Zabbix `Host` has **no** inventory MAC
  configured still produces a `represented_by` edge from that reporter's
  `Device` to its own `Host`, with `matched_by: "reporter_self"` — confirms
  the deterministic self-link (§2.3, §4.1) doesn't depend on §3.2's
  opportunistic MAC matching at all. Manually `/promote`-ing a reporter's
  `Device` to the *wrong* `Host` first, then running ingest, must log a
  conflict and leave that (wrong) edge in place — never silently move it to
  the correct `Host`.
- Re-running the seed load **in its default (upsert) mode, without
  `--reset`**, does not create duplicate `Device`/`Port` nodes — this
  validates the upsert logic in §3.4 ahead of the real collector. (Running
  with `--reset` trivially avoids duplicates too, but proves nothing about
  upsert matching — see §4.)
- After the Zabbix API pull, a `Host` assigned to a `Proxy` in Zabbix shows a
  `monitored_by` edge to the corresponding `Proxy` node.
- Manually marking a seed `Proxy` as unreachable renders its `monitored_by`
  hosts with the blind-spot indicator from §7, not with problem/severity
  styling.
- Clicking a `Host` with at least one active problem in the seed/test data
  shows that problem (severity, name, age) in the side panel via `/problems`.
- A `physical_link` whose endpoint port has an active trigger renders with
  the corresponding severity styling from `/neighbors`, and this remains
  visually distinct from the dashed/solid association-status styling on nodes.
- Manually linking two ports on existing seed `Device`s via `/link` creates a
  `physical_link` with `discovered_via: "manual"`, renders dashed per §7, and
  is not removed by re-running the seed load in its default upsert mode
  (§4) or by a discovery pass. (Running the seed load with `--reset` wipes
  it along with everything else — that's expected, not a bug, since
  `--reset` is a full-slate wipe by design.)
- Re-running discovery for a manually-linked pair that LLDP now also reports
  upgrades the edge's `discovered_via` to `"lldp"` (and its styling to solid)
  rather than creating a duplicate edge.
- A disabled seed `Host` renders with the neutral/muted styling, not a
  severity color, and a `Host` in maintenance with at least one active
  problem still shows its severity color plus a separate maintenance badge —
  not the blind-spot badge, not a muted/disabled style.
- Attempting to `/promote` a second `Device` onto a `Host`/`Proxy` that
  already has an active `represented_by` edge is rejected (or requires an
  explicit `/depromote` first) — confirms the 1:1 constraint from §2.3 is
  actually enforced, not just documented.
- Attempting to `/link` a `Port` that already has an active `physical_link`
  to a third port is rejected — confirms the port-level uniqueness
  constraint from §2.3 is enforced, not just the (src, dst) pair uniqueness.
- A seed `Device` with an HTML/script payload in `sysname` (e.g.
  `<script>alert(1)</script>` or `<img src=x onerror=alert(1)>`) renders as
  inert text everywhere it appears — node label, port drill-down table,
  tooltips — never executes. Test this specifically; don't assume general
  framework escaping covers it without checking the actual render path (§9).
- Clicking "Run discovery ingest" while a CLI-triggered ingest run is
  already in progress is rejected/queued by the same file-lock (§4.1), not
  a second, independent run — confirms the button and the CLI share one
  code path rather than two parallel implementations.
- Onboarding a new reporter (template attached, at least one push already
  sent) and running ingest picks it up **without editing any config file**
  — confirms reporter discovery is live via `item.get`, not a static list.
- Two `Device`s that are both reporters and see each other as an LLDP
  neighbor end up with **exactly one** `physical_link` between them and
  **no leftover pseudo-`Port`** on either side, regardless of which of the
  two gets ingested first — confirms the pseudo-port merge (§3 rule 4)
  actually converges, not just that it fires in one specific order. A
  `Device` with two pseudo-`Port`s whose names would both plausibly
  normalize to match one incoming real port name is left alone (no merge,
  no guess) when that real port arrives. A neighbor that never becomes a
  reporter (no real ports ever pushed) keeps its pseudo-`Port` indefinitely
  — not a bug, the expected end state per rule 4.

## 9. Security — untrusted network-sourced strings

**This is not optional hardening — treat it as part of the base implementation,
not a follow-up.** Several `Device`/`Port` string fields originate from LLDP/CDP
data announced by devices on the network — not from Zabbix, not from the
person operating this tool. Any device on the LAN (including a compromised or
rogue one) can announce arbitrary content in these fields, since LLDP/CDP put
no restrictions on what a neighbor claims about itself.

**Fields to treat as untrusted, wherever they end up in the UI:**
- `Device.attrs.sysname`, `chassis_id`, `vendor` (§2.2)
- `Port.attrs.name`, and anything derived from `lldpRemPortDesc`/`lldpRemPortId`
  once port labeling is implemented
- `Port.attrs.learned_macs` display (MAC format is constrained, but don't
  assume a parser upstream enforced that before it reached this field)
- Any manually-entered field the person types in through `/promote` or
  `/link` (e.g. a future free-text label) is a separate, ordinary
  input-validation concern — not the focus here, but don't assume it's safer
  just because it's local: validate it too.

**Rule: every one of these fields must be escaped/sanitized at render
time (or set via a non-executing DOM API like `textContent`, not
`innerHTML`), with no exception for "trusted internal network" — a single
compromised switch or a spoofed LLDP frame on the segment is enough to
inject through this path.** This applies to every surface that displays
these fields: the graph node labels, the port drill-down table (§7), any
tooltip, any exported/printed view.

Don't invent a bespoke escaping approach per call site — use one shared
sanitization helper (or the framework's built-in escaping, e.g. React's
default JSX text interpolation) applied consistently, and make it easy to
verify in review that every render path for these fields goes through it.
If the implementation stack has a lint rule for this class of bug (e.g.
`no-unsanitized`-style rules for JS), enable it rather than relying on
manual review alone.

## 10. Scaling considerations (not yet implemented — flagging for a deployment with thousands of hosts)

The seed/prototype scale (tens of nodes) hides three issues that will matter
before this is used against a real multi-thousand-host environment. None of
these are required for the current acceptance criteria (§8) — they're listed
here so they aren't rediscovered from scratch later.

1. **`GET /topo/devices` must stop being a full dump.** As specified in §6 it
   returns "all `Device`+`Host`+`Proxy` nodes" — fine at prototype scale, but
   this is the same full-graph-render problem we already solved for ports on
   a single device (§7), just one level up: at real scale the frontend would
   receive thousands of nodes in one response and try to render them at
   once. This endpoint needs to become a search/entry-point (by name, IP,
   host group) instead of a full listing; all further exploration should go
   through `/neighbors` from that entry point, same as it already does for
   graph expansion.

2. **Reconciliation needs an index on `mac`/`chassis_id`.** §2.1 deliberately
   keeps node data in JSON `attrs` rather than typed columns, to keep the
   schema stable while the model was still moving. But §3.2 reconciliation
   matches on exactly those two fields, buried inside that JSON — without a
   generated/indexed column over `attrs->>'mac'` (and `chassis_id`), every
   Zabbix API pull becomes a full scan over all `Port` nodes at real scale.
   Add generated columns with indexes for `mac` and `chassis_id` specifically
   — this does not require reverting the JSON-attrs decision for anything else.

   A related gap worth flagging in the same breath: `Device` upsert (§3 rule
   4) matches by `chassis_id` (falling back to `mgmt_ip`), but nothing at
   the database level actually enforces that a `chassis_id` is unique across
   `Device` nodes — the uniqueness exists only in application logic. A bug
   in the import path could silently create two `Device` rows with the same
   `chassis_id`. Not critical at prototype scale (§2.1 already deliberately
   left `Device` fields unindexed), but once the generated `chassis_id`
   column above is added, it should carry a uniqueness constraint too —
   `Device` is currently the one node type whose identity uniqueness isn't
   backed by the database at all (contrast with `host_ref`/`proxy_ref`,
   which are enforced `UNIQUE` from the start per §2.1).

3. **Live joins must be batched, not per-node.** The live-join rule (never
   copy `Host`/`Proxy` name/status/problems into `attrs` — see §2.2, §6) is
   correct on the data side, but doesn't specify the query shape. A naive
   implementation issuing one query per node while assembling a list
   response is an N+1 query pattern that will dominate response time at
   scale. Every endpoint that returns a list of nodes must resolve their
   live-joined data (`hosts`, `proxy`, `items`, `problem`) with a single
   batched query against the whole result set, not one query per node.

What already holds up without changes: the per-device port table (§7) is
bounded by port count on one device, not overall network size; `physical_link`
severity via `/neighbors` (§6) is bounded to 1 hop from the selected node; and
the "unassociated hosts" list already needs search/collapse at scale, which
was flagged when it first came up.

## 11. Backlog (deliberately deferred, not required for §8)

- **`Service` tree** — a second, symmetric projection of the graph (business
  impact / SLA, top-down) alongside the physical topology (bottom-up). Not a
  small extension of the current model, a parallel direction of work — see
  the earlier design discussion for the full reasoning.
- **`Proxy Group`** (Zabbix HA proxy clustering) — adds a `ProxyGroup` node
  (thin pointer, same pattern as `Host`/`Proxy`) and a `member_of` edge
  (`Proxy → ProxyGroup`). `monitored_by` (§2.3) would then point at either a
  `Proxy` or a `ProxyGroup`. The one non-additive change: the blind-spot rule
  in §2.3 currently assumes a single `Proxy`, and does not hold for a group —
  one member proxy going down in an HA group is not a blind spot if others
  in the group are still serving. When this is implemented, blind-spot status
  for a group-monitored `Host` must read `ProxyGroup.state` (Zabbix computes
  this itself) live, rather than inferring it from individual `Proxy` status.
- **Tag display** — showing existing Zabbix tags (on `Host`, etc.) in the UI,
  e.g. in the side panel or as a graph filter. Not the same question as
  "should tags store the topology" (rejected — see the earlier design
  discussion); this is only about surfacing tags that already exist.
- **`Device` type/role** — an additional attribute on `Device` (§2.2),
  e.g. `device_type: "switch"|"router"|"ap"|"firewall"|"server"|...`,
  eventually populated from LLDP capability bits (IEEE 802.1AB) once the
  real collector exists, with a manual-override path for the meantime
  (comparable in spirit to how a third-party Zabbix topology module lets an
  operator override device type via a host tag). Purely additive — no
  existing constraint, edge, or reconciliation rule depends on this field
  existing or being accurate. Useful for node icons and for any future
  grouped/management-style view, neither of which exists yet either.
- **Hosting/containment dependency** (`hosted_by`, working name) — a third
  relationship category, distinct from both `physical_link` (network
  adjacency) and `monitored_by` (monitoring route): a VM/container's
  dependency on the physical or virtual machine it runs on, which LLDP
  cannot see and which holds regardless of network path (if the hypervisor
  dies, the VM dies too, even though no cable was involved). **This is not
  purely additive — it matters for the deferred reachability/blast-radius
  work above (`Service` entry) and in §1's exclusions**: whenever that work
  gets built, it needs to walk `hosted_by` as a hard dependency alongside
  `physical_link`-based reachability, not `physical_link` alone, or a
  blast-radius calculation would miss every VM/container whose outage has
  nothing to do with the network path. Flagging this now specifically so
  it isn't rediscovered mid-implementation of the reachability feature.
- **Node position + manual-link persistence with concurrency handling** — the
  graph currently has no server-side layout state at all (position is
  presumably recomputed on every load). Worth adding: persisted node
  positions (a shared baseline plus optional per-user override, rather than
  one global position everyone fights over), and — once any such shared
  mutable state exists, including the manual `physical_link`/`represented_by`
  writes that already exist today — optimistic concurrency on the write
  endpoints (a revision token the client must send back, not just the
  uniqueness constraints already in §2.1/§2.3, which catch bad end-states
  but don't tell a losing concurrent request what to do). Not needed while
  this is single-operator; becomes relevant the moment more than one person
  uses it at once.
- **Continuous (weathermap-style) `physical_link` coloring** — color a link
  by continuous measured utilization (%) in addition to, or instead of, the
  discrete trigger-severity styling already in §7. Purely a rendering
  refinement on top of the existing `zabbix_itemids` join (§2.2, §6) —
  no model change.
- **Discovery-quality visibility** — once the real collector exists (§1,
  §4), expose per-device resolution diagnostics (how many LLDP neighbors
  resolved vs. didn't, and why) as an operator-facing view, not just silent
  success/failure. Meaningless against static seed data; relevant the
  moment discovery runs against a real, imperfect network.
- **CAM-table walk + LAG membership discovery** — two related, currently
  unimplemented gaps in the real collector (§4.1, `push.py`/`ingest.php`),
  documented together in §1's exclusions since they're both "the collector
  doesn't walk a table it would need to for this" gaps, not modeling gaps:
  - *CAM-table* (`dot1dTpFdbTable`): needed to populate
    `Port.attrs.learned_macs` (§2.2) for real, so rule 1's MAC-only-port
    branch is exercised by something other than the static seed fixture.
  - *LAG membership* (`ifStackTable`/`ieee8023adTable`): needed so a
    `Port` with `if_type: "lag"` can actually get its member ports wired
    up via `member_of_lag` (§2.3) — `push.py` already detects the LAG
    interface itself, it just has no membership data to put in the blob.
    This needs a schema addition to §4.1's blob shape (some
    `lag_members: [if_index, ...]` per LAG port, or equivalent) as well as
    the SNMP walk itself; `ingest.php`'s side is a comparatively small
    addition once the data exists.
  Neither is required for §8's acceptance criteria against the real
  collector (only the static seed fixture needs to demonstrate these
  branches for §8); both are real, scoped follow-ups once picked up.
- **Vendor-specific SNMP/LLDP variance** — not every vendor exposes a
  queryable LLDP neighbor table the same way (or at all — some devices only
  send LLDP without serving the neighbor table back, some need a controller
  API instead of SNMP entirely, per the community module's vendor notes).
  With the item-based ingest architecture (§4), this mostly becomes a
  template/LLD-rule concern on the reporter host rather than collector code
  — some devices may need a different template variant, or won't work as a
  reporter at all and can only appear as a manually-linked neighbor (§3.5).
  Worth a vendor compatibility note once real hardware is in scope.
- **Port label source preference** — when parsing `lldpRemPortId` (via the
  item data, per §4), prefer a human-readable port description field when
  the device provides one, falling back to branching on
  `lldpRemPortIdSubtype` (§4's existing note on this) only when it doesn't.
  A simplification to layer on top of, not a replacement for, the
  subtype-branching already planned.

**Deliberately out of scope, not backlog** — considered and rejected, not
just deferred:
- **Zabbix trigger dependencies** — this is event-correlation/notification
  logic, a different concern from the topology model. The graph could in
  principle inform trigger dependency configuration, but that's a distinct
  feature, not a gap in this spec.
