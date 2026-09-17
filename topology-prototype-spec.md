# Zabbix topology prototype — build spec

## 0. Objective

Build an MVP prototype that models physical network topology (L2 devices and
ports) alongside Zabbix monitoring objects (hosts), binds the two together,
and exposes a UI that shows both as one graph — including devices that are
not (yet) monitored as Zabbix hosts.

This is a prototype to validate the data model, not a production feature.
Favor simplicity and readability over performance or completeness.

## 1. Scope

**In scope — grouped by what each covers, not a flat list, since a lot has
accumulated since this section was first written:**

*Model* (§2):
- Node types: `Device`, `Port` (independent physical-topology entities,
  exist without a Zabbix counterpart), `Host`, `Proxy` (thin pointers into
  Zabbix's own tables, never data copies)
- Edge types: `physical_link`, `represented_by`. (LAG membership is a
  structural `Port` reference — `Port.lag_id` — not an edge type; see
  §2.1/§2.3 for why. `Host`↔`Proxy` monitoring assignment is resolved live
  from Zabbix, not stored — see §2.3's note.)

*Identity resolution* (§3, §4.1):
- Neighbor `Device`↔`Host` matching: MAC-only, opportunistic (§3 rule 2).
  `Proxy` has no MAC source (no Zabbix inventory subsystem for proxies) and
  can't use `reporter_self` either (a Zabbix `Proxy` object can't own
  Trapper items) — manual `/promote` (§6) is the only way a `Proxy` ever
  gets a `represented_by` edge.
- Reporter self-identification: deterministic `reporter_self` linking,
  independent of MAC matching (§4.1)
- `Device` self-recognition across ingest runs: upsert by `chassis_id` →
  `mgmt_ip` → reporter+port-scoped `sysname` (§3 rule 4)
- Unconfirmed-port merge, both the reactive and proactive halves (§3 rule 4)
- Manual overrides, independent of automatic reconciliation: `/promote`,
  `/depromote`, manual `physical_link` creation/deletion with explicit
  LLDP-conflict resolution (§3 rule 5, §6)

*Data source* (§4, §4.1):
- Static seed data (two modes: default upsert, `--reset`) as the baseline
  fixture
- The Trapper-based push/ingest pipeline as currently implemented and
  tested against a live SNMP lab — documented as **provisional transport**
  (§4's opening note): what's actually built today, not a permanent
  architectural commitment

*API and UI* (§6, §7):
- Manually-triggered Zabbix API host/proxy pull (§5) and manually-triggered
  ingest, both from the CLI and from a UI button sharing the same code path
  (§6)
- Topology graph: `Device`/`Host`/`Proxy` nodes only, `Port` never rendered
  as a node; a `Device` with an active `represented_by` is not drawn at all
  — only its `Host`/`Proxy`, with the `Device`'s data moved into a section
  of that node's side panel (§7)
- Side panel: `Port` drill-down table (grouped by connection type),
  `Host`/`Proxy` Problems section, conditional Device section
- Visual states: severity, disabled, maintenance, blind-spot,
  `physical_link` provenance (`discovered_via`) and severity styling,
  `Host`↔`Proxy` monitoring assignment on its own distinct line style,
  drawn from live Zabbix data (§7)

*Security* (§9):
- Escaping/sanitizing every LLDP/CDP-sourced string wherever it renders —
  treated as base implementation, not optional hardening

**Explicitly out of scope for this prototype — do not build:**
- The discovery collector's raw SNMP-walking logic itself is implemented
  (§4.1), but reporter *onboarding* (finding and registering new reporters)
  is not — that's deliberately delegated to Zabbix's own mechanisms
  (Network Discovery, manual, API, CMDB import), never built into this spec
  (§4's "reporter onboarding is out of scope for topology itself" note)
- Manual `Device` creation — rule 5 explicitly forbids it; a candidate
  design exists (§11's "Passive infrastructure" item) but is not being
  built now
- `VLAN`, `Subnet`, `IPAddress` nodes/edges
- `Service` tree and `APMService` nodes/edges
- Temporal versioning (`valid_from`/`valid_to`) beyond a simple `last_seen` timestamp
- Automated confidence-scored / fuzzy identity matching beyond what §3
  already specifies (MAC-only, `reporter_self`, the narrowly-scoped
  `sysname` fallback)
- Scheduled or cron-based discovery — every trigger point (push, ingest,
  API pull) is manual, whether from CLI or UI button
- Reachability / SPOF / blast-radius graph algorithms
- CAM-table (`dot1dTpFdbTable`) collection — not implemented in the
  current push schema; `learned_macs` remains part of the topology model
  (§2.2) and the seed fixture (§4) populates it, but the real collector
  (§4.1) does not yet — model supports it, seed supports it, collector
  doesn't populate it yet. Tracked in §11.
- `ifStackTable`/`ieee8023adTable` walk for LAG membership — confirmed
  missing during implementation (§4.1), tracked in §11 rather than worked
  around in `ingest.php`
- Node position persistence, optimistic concurrency on writes, a lifecycle
  state machine for stale `physical_link`s, and everything else listed in
  §10 (scaling) and §11 (backlog) — all deliberately deferred, not gaps

If a requirement not listed above seems necessary while implementing, stop and
flag it rather than silently expanding scope.

## 2. Data model

**Semantics before schema — read this before the SQL below.** The model is
two kinds of thing: **Nodes**, representing a topology entity (`Device`,
`Port`, `Host`, `Proxy`), and **Relationships**, representing a semantic
connection between two Nodes (`physical_link`, `represented_by` — and
`MONITORED_BY`, `Host`'s monitoring assignment to a `Proxy`/`ProxyGroup`,
which is real and semantically part of the model but is *resolved live
from Zabbix, not stored as our own edge* — §2.3 explains why, the same
thin-pointer reasoning already applied to `Host`/`Proxy` themselves, just
extended to this one relationship). This build spec uses lowercase names
(`physical_link`, `represented_by`) for the two relationship types that
*are* stored; the FR document uses `CONNECTED_TO`/`REPRESENTED_BY` for the
same two — same concepts, different naming convention. LAG membership is
**not** a relationship type in this model — `Port.lag_id` (§2.1) — for the
same reason `Port`→`Device` isn't: it's a structural reference a Node
carries about itself, not an independent topology fact with its own
identity/lifecycle/provenance. §2.1 and §2.3 explain the line between the
two categories, and why LAG membership landed on the reference side of it
after being reconsidered.
`Device`/`Port` exist on their own, independent of Zabbix, whether or not
Zabbix ever knows about them; `Host`/`Proxy` are nodes too, but each one
refers to an already-existing Zabbix object rather than being a new
entity in its own right. The storage below is one way of representing
that — a generic node/edge table pair, plus one direct `device_id`
reference where the relationship is simple enough not to need the general
case (§2.1's note explains why). Don't let the shape of the tables be
read as the model itself; the model is the semantics above, the tables
are just where it's currently persisted.

### 2.1 Storage

Generic property-graph tables, added as new tables in the target Zabbix
database (match the engine already in use — MySQL or PostgreSQL):

```sql
CREATE TABLE topo_nodes (
  id         BIGINT PRIMARY KEY AUTO_INCREMENT,
  type       VARCHAR(32) NOT NULL,   -- 'device' | 'port' | 'host' | 'proxy'
  host_ref   BIGINT NULL UNIQUE REFERENCES hosts(hostid) ON DELETE CASCADE,  -- set only when type='host'
  proxy_ref  BIGINT NULL UNIQUE REFERENCES proxy(proxyid) ON DELETE CASCADE, -- set only when type='proxy'
  device_id  BIGINT NULL REFERENCES topo_nodes(id) ON DELETE CASCADE,  -- set only when type='port'; the Port's owning Device
  lag_id     BIGINT NULL REFERENCES topo_nodes(id) ON DELETE SET NULL, -- set only when type='port' and if_type='physical'; the LAG Port this member belongs to
  attrs      JSON NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_topo_nodes_device_id ON topo_nodes(device_id);
CREATE INDEX idx_topo_nodes_lag_id ON topo_nodes(lag_id);
```

`device_id` is a plain, self-referential foreign key — not a `topo_edges`
row. `Port`→`Device` is a stable 1:many relationship known at the moment a
`Port` is created; it never needs the generic edge machinery (no
cardinality question, no multiple relationship types between the same pair,
nothing `physical_link`/`represented_by`-style ever attaches to it). Using
a real column here is simpler than the generic edge table and gets
`ON DELETE CASCADE` for free — deleting a `Device` removes its `Port`s
automatically, same pattern as `host_ref`/`proxy_ref`. This replaces what
an earlier version of this spec modeled as a `part_of` edge; that edge
type no longer exists.

**`lag_id` follows the same structural-reference pattern as `device_id`,
but is not a copy of it — two things about it are deliberately
different, and worth stating explicitly since the two columns look alike
enough to invite the wrong assumption by analogy.**

1. **`ON DELETE SET NULL`, not `CASCADE`.** For `device_id`, cascade is
   correct: a `Port` cannot physically exist without its `Device`, so
   deleting the `Device` deleting the `Port` is the right behavior. For
   `lag_id` this is wrong — if the LAG port is deleted or reconfigured
   away, the physical member ports **do not disappear**: they are real
   interfaces with their own `zabbix_itemids`, `learned_macs`, MAC address,
   and monitoring history. `CASCADE` here would silently delete real,
   independently-existing `Port` rows as a side effect of a LAG going
   away. `SET NULL` is correct: the member port survives, simply reverts
   to unaggregated (`if_type` stays `"physical"` — see §2.2 — `lag_id`
   goes back to `NULL`).
2. **`lag_id` is not fixed at creation like `device_id` is.** A `Port`'s
   `device_id` is effectively permanent for the life of that row. LAG
   membership is dynamic: a physical port can join a LAG, leave it, or move
   to a different LAG over the device's operational life, and reconciliation
   (§3) must treat re-pointing or nulling `lag_id` as an ordinary update,
   not as evidence of a different physical port.

**Invariant, enforced at the application layer, not the database:**
`lag_id IS NOT NULL ⇒` the target row's `if_type = 'lag'`. This is a
cross-row check — neither MySQL nor PostgreSQL can express "the row this
FK points at must have column X = Y" as a plain `CHECK` constraint (a
`CHECK` can't reference another row). So this must be validated in the
ingest/API code path before every write to `lag_id`, the same way
`topo_edges`' type-specific rules in §2.3 are already flagged as
logic layered on top of generic storage, not something the schema itself
guarantees. Don't go looking for this constraint in the schema — it isn't
there by design, only in the write path.

```sql
CREATE TABLE topo_edges (
  id         BIGINT PRIMARY KEY AUTO_INCREMENT,
  type       VARCHAR(32) NOT NULL,   -- 'physical_link' | 'represented_by'
  src_id     BIGINT NOT NULL REFERENCES topo_nodes(id) ON DELETE CASCADE,
  dst_id     BIGINT NOT NULL REFERENCES topo_nodes(id) ON DELETE CASCADE,
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

**The cascade chain must be complete, not just start at `host_ref`.** When a
Zabbix `Host` is deleted, `host_ref`'s `ON DELETE CASCADE` removes the
corresponding `topo_nodes` row — but without `ON DELETE CASCADE` on
`topo_edges.src_id`/`dst_id` as well (now added above), that node deletion
would be blocked by any edge still pointing at it (e.g. a `represented_by`
edge from a `Device`), or silently orphan the edge if the constraint isn't
enforced strictly. This was found via a real scenario, not hypothetically:
deleting a Zabbix `Host` that already had topology data attached needs the
full chain to work cleanly, not just the first hop.

**Generic storage does not imply generic constraints — say this explicitly
so a future reader doesn't assume otherwise.** `topo_edges` already needs
five separate pieces of type-specific database logic layered on top of its
generic shape: `represented_by` source uniqueness, `represented_by`
destination uniqueness, `physical_link` pair uniqueness, `physical_link`
source uniqueness, `physical_link` destination uniqueness (all detailed in
§2.3). A single shared table does not mean a new edge type is free to add —
each one brings its own constraint logic that has to be designed, not
inherited automatically from the generic schema.

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

**Conceptual framing, sharpened after review — no behavior change, just
precision.** `Device` represents an *observed network participant* that may
exist independently of a Zabbix `Host` representation — not "the generic
topology node." `represented_by` associates that participant with a Zabbix
domain object without replacing the participant itself (this is why it
persists after `/depromote`, per rule 3 in §3). `Port` represents a
connectivity point of the observed participant — and **resolving the
participant's identity and resolving a specific remote port on that
participant are separate concerns**, not one combined resolution step; see
the explicit rule on this in §3 (rule 1's follow-up note) for what happens
when one succeeds and the other doesn't.

- **device**: `{mac, chassis_id, mgmt_ip, sysname, vendor, last_seen}`.
  `sysname`/`chassis_id`/`vendor` come from LLDP/CDP data announced by network
  devices — untrusted input, not from Zabbix or the person operating this
  tool. See §9 before rendering any of these anywhere in the UI.
- **port**: `{if_index, name, if_type: "physical"|"lag"|"mgmt", mac, speed, admin_status, oper_status, learned_macs: [], zabbix_itemids: [], confirmed: true}`.
  Named `Port`, not `Interface`, specifically to avoid colliding with Zabbix's
  own `host.interfaces` (agent/SNMP/JMX/IPMI monitoring endpoints) — a
  different concept entirely. Use "port" consistently in code, comments, and
  endpoint names for this node type; reserve "interface" for Zabbix's own
  meaning when that comes up (e.g. `selectInterfaces`, `interfaces[]` in §3.2).
  `confirmed: false` marks a `Port` created only from a neighbor's LLDP
  observation (no real `if_index` from that device's own push) — see §3
  rule 4's unconfirmed port merge rule for what happens when the neighbor turns
  out to be a reporter itself. **`if_type` and LAG membership are
  orthogonal, not the same axis — a physical port that joins a LAG stays
  `if_type: "physical"`, it does not become some third value like
  `"lag_member"`.** The interface is physically the same kind of thing
  whether or not it's currently aggregated; membership is expressed
  separately via `lag_id` (§2.1: nullable, points at another `Port` node
  whose own `if_type` is `"lag"`). Only the aggregate itself — the logical
  port LACP/static-LAG exposes — gets `if_type: "lag"`, and a `Port` with
  `if_type: "lag"` never itself has a non-null `lag_id` (enforced as
  described in §2.1's invariant note).
- **host**: `{}` (or empty) — a thin pointer only. `host_ref` (see §2.1) is the
  single source of truth for identity; name, status, and any other display
  data are resolved with a live join against `hosts` at read time, never
  copied into `attrs`. This node exists only so `represented_by` (and later
  `runs_on`, `service_composed_of`) have a stable graph endpoint to point at.
- **proxy**: `{}` (or empty) — same thin-pointer pattern as `host`, via
  `proxy_ref` (see §2.1). A `Proxy` is a Zabbix monitoring object
  representing proxy infrastructure, but it has no inventory/MAC source
  and cannot own Zabbix items. Therefore it can receive a `represented_by`
  edge only through explicit manual `/promote`; it does not participate in
  automatic MAC-based reconciliation or `reporter_self` (§3.2, §4.1, §5).
  If the physical machine running the proxy is also monitored as a Zabbix
  `Host`, that `Host` is a separate topology node and may independently be
  associated with a `Device`.

`Port`'s parent `Device` is resolved via the `device_id` column (§2.1), a
real foreign key — not a `topo_edges` row. This was modeled as a `part_of`
edge in an earlier version of this spec; the plain column replaced it once
the relationship's cardinality (always exactly one `Device` per `Port`,
known at creation time) made the generic edge machinery unnecessary
overhead rather than useful flexibility.

`zabbix_itemids` holds real foreign keys into `items.itemid` (the items that
monitor this port — e.g. `ifInOctets`, `ifOperStatus`). There is no
separate `item` node type and no edge for this: unlike `Host`, an item is
never a hub other edges need to point at, so a plain FK list is enough. Item
key, name, and last value are resolved with a live join, same as `Host` name
and status — never duplicated into `attrs`.

### 2.3 Edges

| type | src → dst | attrs |
|---|---|---|
| `physical_link` | Port → Port | `{discovered_via: "lldp"\|"manual", last_seen, last_seen_src, last_seen_dst}` |
| `represented_by` | Device → Host or Proxy | `{match_type: "identity"\|"manual", matched_by: "mac"\|"reporter_self"\|"manual", matched_mac, created_at}` |

**LAG membership is deliberately not in this table — reconsidered and
settled on `Port.lag_id` (§2.1) instead of a `member_of_lag` edge.** The
question that decides it: is membership an independent topology fact with
its own identity/lifecycle/provenance, the way `physical_link` and
`represented_by` are, or is it a structural property one `Port` carries
about itself, the way `device_id` is? The case for treating it as an edge
— generic graph traversal, room for future attributes like a per-member
LACP state, an independent staleness/lifecycle — is real, but rests on a
premise this project's architecture doesn't currently have:
**independent observability.** `physical_link` needs `last_seen_src`/
`last_seen_dst` tracked separately (§2.3 below) specifically because two
different reporters can each confirm their own side on independent
schedules, so one can go stale while the other doesn't. LAG membership has
no equivalent multi-source path today — per §4.1, `lag_members` is
proposed as a field on the *same* push blob that creates and updates the
`Port` itself, not a separately-collected fact. A member port and its
membership therefore go stale in lockstep with the `Port` row they're
part of; there is nothing for a separate `last_seen`/`discovered_via` to
track that the `Port`'s own update doesn't already cover. Combined with
`if_type` already needing the "physical stays physical, aggregation is
orthogonal" distinction (§2.2), the structural-reference side of the
line fits better for now. **This is a deliberate, revisitable call, not a
closed one** — see §11's backlog note: if LAG membership ever becomes
independently observable (its own collection path, its own staleness
apart from the `Port` row), that is the trigger to promote it into
`topo_edges` as `member_of_lag`, the same way `represented_by`'s 1:1
constraint and the sparse-`Port` model are both flagged elsewhere in this
spec as decisions to reopen if their triggering condition arrives.

**`Host`↔`Proxy` monitoring assignment (`MONITORED_BY` in the FR document)
is not a stored edge — resolve it live from Zabbix, the same thin-pointer
principle already applied to `Host`/`Proxy` nodes themselves.** Zabbix's
`host.get` already returns everything needed: `monitored_by` (server /
proxy / proxy group), `proxyid`, `proxy_groupid`, and — for the proxy-group
case specifically — `assigned_proxyid` (the proxy Zabbix's server actually
assigned within that group; a host can be assigned to a *group* while the
*specific* member proxy handling it is a separate, server-computed fact,
so don't assume `proxy_groupid` alone tells you which proxy is serving a
given host). `host.get` batches (`proxyids`/`proxy_groupids` filters, or a
single call across all relevant `hostid`s), so this is one query per
render, not N+1 — same batching discipline as every other live join in
this spec (§10 point 3). Storing this as our own edge would mean it goes
stale the moment someone reassigns a host to a different proxy in Zabbix,
until the next `/5` pull — exactly the staleness problem the thin-pointer
pattern exists to avoid, just at the relationship level instead of the
node-attribute level.

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

**`physical_link` tracks `last_seen` per side, not just once — a link can
have two independent reporters, and one of them can silently go stale
while the other keeps confirming it.** If only a single aggregated
`last_seen` were kept (whichever side confirmed most recently), one
reporter's push pipeline breaking (a dead cron, a network issue) would be
invisible as long as the *other* side of the same link keeps reporting —
the link would look perfectly fresh in the existing staleness indicator
(§7) while one whole source of truth for it has gone dark. `last_seen_src`
and `last_seen_dst` record each side's most recent confirmation
independently; `last_seen` (used by §7's indicator) stays `max(last_seen_src,
last_seen_dst)` for backward compatibility with what's already built. For
`discovered_via: "manual"` links, neither per-side field is populated —
there's no reporter confirmation cycle to track, staleness works
differently there (§3 rule 5) and doesn't need this.

**Be precise about what "src"/"dst" mean here — it's not ingest order or
"whichever reporter sent this particular push."** `last_seen_src` and
`last_seen_dst` refer to the reporter corresponding to the *canonicalized*
`src_id`/`dst_id` (below) — i.e. the device owning whichever `Port` ended
up as `src` after canonicalization (numerically smaller `Port` id), not
whichever side happened to report first or most recently. E.g. for
`Port 17 ↔ Port 42`, canonicalization makes `src_id=17`/`dst_id=42`
regardless of which of the two actually sent the confirming push — so
`last_seen_src` always means "last confirmation from the device owning
Port 17," even on a push that came from Port 42's device confirming the
same link. Get this backwards and the two fields silently swap meaning
depending on which side happens to be numerically smaller, which is not
something to leave to interpretation.

**Update semantics, made explicit rather than left to interpretation:**

| Situation | `last_seen_src` | `last_seen_dst` | `last_seen` |
|---|---|---|---|
| Link just created — only the discovering reporter's side has ever confirmed it (the other device isn't a reporter yet, or hasn't pushed since) | set to now | `null` | = `last_seen_src` |
| That same side keeps confirming on every subsequent push; the other device still isn't a reporter | advances each time | stays `null` | advances with it |
| The other device becomes a reporter (or was already one) and its push confirms the same link for the first time | unchanged | set to now | advances if this is now the max |
| Both sides are reporters and both keep confirming normally | advances each time | advances each time | advances with whichever is newer |
| One side stops pushing (dead cron, network issue) while the other keeps confirming | **frozen at its last value** — never reverts to `null` once set | keeps advancing | keeps advancing (masks the frozen side — this is exactly why the two fields are tracked separately, not just the aggregate) |

A field only ever moves forward or stays frozen — it never reverts to
`null` once it has a real timestamp, even if that side's reporter later
stops confirming.

**`physical_link` direction must be canonicalized to prevent reversed
duplicates.** Because `src_id`/`dst_id` are directional columns but a
physical link is not (A↔B and B↔A are the same cable), always write
`physical_link` with the numerically smaller `Port` node id as `src_id`
before insert. Combined with the uniqueness mechanism in §2.1 (unique on
`(src_id, dst_id)` where `type='physical_link'`), this prevents both an
exact duplicate and a reversed-direction duplicate of the same link.

**This also resolves the case where both ends of a link are independent
reporters — but only once the unconfirmed-port merge rule in §3 rule 4 has run;
canonicalization alone was not sufficient, and an earlier version of this
note overstated that it was.** E.g. `Core1` and `Core2` are both reporters
and each independently asserts the same link from its own side (`Core1`'s
blob says "my port X connects to Core2's port Y", `Core2`'s blob says the
reverse). The first reporter processed creates an unconfirmed `Port` for the
other side (LLDP never exposes a real `if_index` for the far end — §3 rule
4). Only once the second reporter's own push arrives and the merge rule
re-points that unconfirmed port's edges onto the now-real `Port` do both `Port`s
resolve to the same node IDs — **at that point**, canonicalization collapses
both assertions to the same `(src_id, dst_id)` pair and the second
assertion becomes a clean upsert. Confirmed as a real, previously-missing
step during implementation (the `Router1`↔`Switch1` case): without the
merge, two separate edges and two fabricated ports persist instead of
converging to one.

**A `Port` can have at most one active `physical_link`.** This was missing
from earlier passes on this spec — a physical port has exactly one cable in
it, so nothing should allow a second `physical_link` row from (or to) a
`Port` that already has one. Enforce with the same partial-unique-index
mechanism as elsewhere (§2.1): unique on `src_id` where
`type='physical_link'`, and separately on `dst_id` where
`type='physical_link'`. This holds even for LAG member ports — aggregation
is expressed via `lag_id` (§2.1), not by letting one physical port carry
multiple `physical_link` rows; each physical member port still connects to
exactly one specific port on the other side.

**This makes the point-to-point assumption explicit, deliberate, and
worth stating outright: the model assumes each port connects to exactly one
other port.** It does not represent TAPs, SPAN/mirror destinations, optical
splitters, or other passive one-to-many physical topologies — those need
more than one active link per port by design, which this constraint
excludes on purpose. If that becomes a real requirement later, it's a
model change (relaxing this uniqueness for specific port roles), not a bug
to patch quietly. The sharpest way to state the boundary: **topology
represents operational peer relationships, not packet-replication paths.**
Anyone questioning why a SPAN/TAP/mirror setup doesn't show up correctly
should read this as the model correctly excluding a different kind of
connection, not as a defect.

**Monitoring-assignment semantics — do not conflate with an outage.** A
`Proxy` becoming unreachable means Zabbix loses *visibility* into every
`Host` it monitors — it does not mean those hosts are actually down. Any UI
or logic that resolves this relationship (live, per the note above — no
`monitored_by` edge to walk) must present it as "monitoring blind spot
behind this proxy", never as "these hosts are down". Silently treating a
proxy outage as a host outage would make the graph actively misleading,
not just incomplete.

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

   **Be precise about what this trade-off actually costs.** A switch
   connected to a server with LLDP disabled on either end is invisible to
   this model even though the physical link is real and the MAC is right
   there in `learned_macs` — there is no fallback path that promotes a
   learned MAC into a `Device` later. This is **coverage that is
   intentionally incomplete**, not bad data being filtered out — say it
   this way rather than "ignored," since the two read very differently to
   someone auditing what the model can and can't see.

   **A separate, previously-unaddressed case: the remote participant
   resolves but the remote port doesn't.** Participant identity (chassis
   ID / sysname, this rule) and remote port identity (`remote_port_id`
   parsing, §4.1) are resolved independently — nothing guarantees both
   succeed together. If a neighbor's `remote_chassis_id`/`remote_sysname`
   resolves but `remote_port_id` doesn't parse into a usable `Port`
   identifier (an unhandled `lldpRemPortIdSubtype`, a malformed value,
   etc.), **the `Device` node is still created** — this rule (1) only
   depends on participant identity, not port resolution — but **no
   `physical_link` is created for that observation**, since `physical_link`
   requires a resolved `Port` on both ends (§2.3) and there is currently no
   "connected to this Device, exact port unknown" representation. The
   connectivity information for that specific observation is silently
   lost — the `Device` exists, but floats with no edge from this neighbor
   relationship (it may still get one later, from a different, better-
   resolved observation). This is a **deliberate MVP boundary, now decided
   rather than left implicit**: `physical_link` stays strictly `Port` ↔
   `Port`, never `Device` ↔ `Device` with one side unresolved. Relaxing
   this (allowing a link with an unresolved remote port) is a real model
   change, not a quick fix — see §11's backlog note on this exact
   question if it needs revisiting.

2. **This rule applies only to neighbor devices — a reporter's own `Device`
   uses the separate, deterministic `reporter_self` association defined in
   §4.1, not this rule.** Keep the two distinct: `Device` ↔ `Host`/`Proxy`
   matching for anything found as a *neighbor* uses MAC address only — not
   chassis ID, despite what an earlier version of this rule said. There is no
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
   **This entire rule applies to `Host` only.** `Proxy` has no MAC source
   at all — the Zabbix Proxy object has no `inventory` field (inventory is
   a `Host`-only subsystem) — so a `Proxy` never goes through this rule.
   See §5 for why `reporter_self` doesn't reach `Proxy` either, and why
   manual `/promote` is its only path.
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
   network).

   **When a lower-priority key finds the match, overwrite the stored
   higher-priority identity — don't treat a changed `chassis_id` as a new
   `Device`.** E.g. a device previously stored with `chassis_id=A`,
   `mgmt_ip=X` shows up in a later scan with `chassis_id=B`, `mgmt_ip=X`
   (a chassis ID can legitimately change — a card swap, a firmware
   reset). The `chassis_id` lookup finds nothing (no `Device` has
   `chassis_id=B` yet), falls through to `mgmt_ip`, and matches the
   existing `Device` via `X`. At that point, **update the stored
   `chassis_id` from `A` to `B` on that same `Device` row** — don't create
   a second `Device`. The match that succeeded is what's authoritative for
   that pass, and every attribute the blob provides gets refreshed on the
   matched row, not just the key that happened to find it. Without this
   stated explicitly, it's easy to assume a changed `chassis_id` means a
   new physical entity — it doesn't, by itself; §3.5's stacked-switch/MLAG
   limitation is the case where a genuinely different physical mapping is
   the concern, not an ordinary attribute change on the same box.

   This third key exists specifically for LLDP neighbors known
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
   `(device_id, if_index)`.

   **Device identity is independent of the reporter — say this explicitly,
   don't leave it to be inferred.** The `chassis_id`/`mgmt_ip` keys above
   are global lookups, not scoped to any particular reporter: if `Reporter
   A` and `Reporter B` each independently see the same physical
   `Switch-X` as a neighbor, both observations resolve to the *same*
   `Device` node, because the match is on the device's own identity, not
   on who observed it. Only the third, weak `sysname` fallback is
   deliberately scoped to (reporter, port) — and that narrow scoping is
   specific to that one weak key, not a property of matching in general.
   A reporter is the *source of an observation*, never part of a device's
   identity. Without this stated plainly, the reporter-scoping on the
   `sysname` fallback right above could be misread as applying to the
   whole rule.

   **Known limitation, not a bug: a `sysname`-scoped `Device` doesn't
   survive moving to a different port.** Because the match is scoped to
   `(reporter, local_if_index)`, a neighbor identified only by `sysname`
   that later shows up on a *different* port of the same reporter (cable
   moved) won't match its old `Device` node — the old node is left behind
   (never deleted, per rule 3) and a new one is created at the new port.
   This is an accepted MVP trade-off, not something to silently work around
   with a broader match — if it becomes a real problem in practice, it's a
   candidate for the same future-iteration discussion as `represented_by`'s
   1:1 constraint, not a quick fix now.

   **Unconfirmed-port merge — confirmed as a real gap during implementation,
   not previously specified.** When a `Device` is created from a neighbor
   observation (rule 1), its `Port` is necessarily an **unconfirmed port**
   (`attrs.confirmed: false`, §2.2) — LLDP only tells you the neighbor's port
   *name*/*ID*, never a real `if_index`, since that's private to the
   neighbor's own SNMP tree. If that neighbor is, or later becomes, a
   reporter itself, its own push independently creates its *real* `Port`
   for the same physical port — and without reconciling the two, both an
   unconfirmed port and a real port end up representing one physical port, each
   with its own `physical_link` to the same far end. This is exactly what
   happened with `Router1`↔`Switch1` in testing: two edges, two fabricated
   ports, one cable.

   **Merge rule has two halves — both are required, the first alone is not
   enough.** An initial implementation with only the reactive half looked
   stable (node/edge counts held steady across repeated ingest runs) but
   wasn't: one unconfirmed port was being deleted and a fresh one immediately
   fabricated each cycle, which canceled out in the totals while the actual
   duplication never resolved. Confirmed only by checking entity IDs across
   repeated runs, not counts — see the methodological note at the end of
   this rule.

   1. **Reactive half**: whenever ingest upserts a reporter's own real
      `Port`s (the confirmed case in this rule), check whether that same
      `Device` already has an unconfirmed `Port` whose name matches the real
      port's name after normalization (vendor long/short forms — e.g.
      `GigabitEthernet0/24` ↔ `Gi0/24` — case-insensitive). If exactly one
      unconfirmed port matches: re-point every `physical_link` edge from the
      unconfirmed port to the real port, then delete the unconfirmed port.
   2. **Proactive half (the missing piece the first fix skipped)**: before
      creating a *new* unconfirmed port for a neighbor observation at all, check
      whether that neighbor `Device` already has a **real** `Port` with a
      matching normalized name. If so, link directly to that real port
      instead of fabricating an unconfirmed port in the first place. Without this
      half, the reactive half above cleans up one generation of duplicate
      only for the very next ingest pass to immediately recreate one, since
      nothing stopped unconfirmed port creation from running unconditionally
      even when a matching real port already existed at that moment.

   **If zero or more than one candidate matches in either half, do
   nothing — same "don't auto-merge on ambiguous evidence" principle as the
   `sysname` fallback above.** Together, both halves are what make the
   multi-observer case in §2.3 (`Core1`/`Core2` both independently
   reporting the same link) actually converge to one edge and *stay*
   converged — canonicalization alone only merges two *already-real* ports;
   it was never sufficient on its own when one side starts out as an
   unconfirmed port, which is the normal case for any newly-discovered reporter
   pair.

   **Methodological note, worth generalizing to other idempotency checks in
   this spec**: stable node/edge *counts* across repeated runs are not
   sufficient evidence of convergence — a create-one/delete-one cycle each
   pass looks perfectly flat in aggregate counts while never actually
   stabilizing. Verify by comparing entity **IDs** across repeated runs
   (same rows persisting, not same row *count*), not just counts, for any
   future check of this kind. **This applies to LAG membership too, not
   just the unconfirmed-port merge above.** `lag_id` reconciliation
   (immediately below) rewrites a `Port` row in place rather than creating
   or deleting one, so a naive count check would never even look flat or
   unstable — it would just look untouched. Verify LAG reconciliation by
   comparing the actual `lag_id` value on the same `Port` ID across
   repeated runs, not merely by confirming the row count of `Port`s with
   `if_type: "lag"` hasn't changed — a port silently flapping between two
   LAG ids each run, or drifting to a third `Device`'s LAG by a bad match,
   would pass a count-only check while the model quietly gets it wrong.

   **`lag_id` reconciliation on ingest**: when a reporter's push blob
   reports LAG membership for one of its ports (`lag_members`, §4.1), the
   LAG `Port` itself is found or created by the same `(device_id,
   if_index)` upsert as any other `Port` (this section) **before** any
   member's `lag_id` is written — a member can't point at a LAG `Port`
   that doesn't exist yet. If a push reports a port with `lag_id` set to a
   different LAG than what's currently stored, treat it as an ordinary
   update to that field (the port moved to a different aggregate) — not as
   evidence of a new or different physical port; `if_index`/`chassis_id`/
   `mgmt_ip` identity is unaffected by which LAG a port happens to belong
   to right now. If a previously-aggregated port is reported without
   `lag_id`/`lag_if_index` in a given push at all, set `lag_id` to `NULL`
   explicitly — the blob is authoritative for what it reports about a port
   in that pass (same "silence overwrites, doesn't get skipped" principle
   already applied to upsert generally in this rule), so a port dropping
   out of LAG membership is a real, immediate fact, not something to leave
   stale until some other signal arrives.

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

   **Explicit conflict resolution when LLDP disagrees with an existing
   manual link on the same port.** E.g. an operator manually links PortA↔PortB,
   and a later discovery run reports PortA↔PortC instead — these can't both
   exist (a `Port` has at most one active `physical_link`, above). **The
   manual link wins**: this follows directly from the "discovery must never
   delete a manually-created link" rule just stated, combined with the
   port-uniqueness constraint — LLDP's conflicting observation for that port
   is skipped and logged, not silently applied, and does not overwrite or
   queue behind the manual link. If the manual link is genuinely wrong (the
   cable really did move), an operator has to `DELETE` it (§6) before the
   new LLDP-discovered link can take its place — this is not automatic, by
   design, same as never auto-deleting a manual link in the first place.

   **Explicit policy for a link that stops being reported by discovery
   (not the same case as the conflict above — this is disappearance, not
   contradiction).** If a `physical_link` with `discovered_via: "lldp"` was
   present in a previous ingest run but is absent from the current one
   (the neighbor moved, the cable was pulled, whatever the real cause),
   **do not delete it and do not mark it differently** — this spec
   deliberately has no lifecycle state machine (Active/Stale/Removed or
   similar), only the `last_seen` timestamp already in `physical_link.attrs`
   (§2.3). A link that isn't reconfirmed simply keeps its last known
   `last_seen` value, unchanged, indefinitely. The only way to remove it is
   the manual `DELETE` endpoint (§6) — same principle as manual links never
   being auto-deleted, just applied here to LLDP-sourced ones that have
   gone stale. If stale-link visibility or automatic cleanup becomes a real
   need, that's a lifecycle-model addition for a future iteration (§11),
   not something to improvise now.

## 4. Seed data (baseline fixture, alongside the real collector in §4.1)

**Discovery transport is provisional — read everything in this section
(and §4.1) with that in mind.** This spec's stated goal (§0) is validating
the data model, not building a production discovery pipeline. Trapper is
documented below as the mechanism actually implemented and tested against
a live SNMP lab — useful because running real, dynamic data through the
pipeline surfaced genuine bugs in the *model* (the duplicate-`Device`
upsert gap, the incomplete cascade chain, the wrong MAC source in
reconciliation — see git history for context), not because the transport
mechanism itself is architecturally significant. The transport could be
replaced by static fixtures, local files, direct DB/API insertion, or
anything else, without touching §2 (data model), §3 (reconciliation rules),
or §7 (UI) at all, as long as the resulting `topo_nodes`/`topo_edges` shape
stays the same. Don't treat §4.1's specific mechanism (Trapper, the
push/ingest security boundary, template-based bootstrap, etc.) as something
to keep defending or polishing — further transport refinement (deployment
config shapes, alternate delivery mechanisms, Cloud compatibility, that
class of question) is off the critical path from here on. The parts of this
spec that actually matter are `Device`/`Port`/`physical_link`/
`represented_by`, reconciliation, and the UI — that's where remaining
effort should go.

The SNMP/LLDP collector is implemented as the Trapper push component
described in §4.1. For the prototype's baseline UI/model validation,
however, load a static fixture directly into `topo_nodes` / `topo_edges`
instead of relying on live collection. The fixture must model the same
switch used in the earlier port-table mockup, so the UI can be checked
against a known-correct picture:

- One `Device` node for the 48-port switch (`mac`, `chassis_id`, `mgmt_ip`, `sysname`, `vendor`)
- 48 `Port` nodes, each with `device_id` set to that `Device`, covering:
  - 40 connected ports — only 2 with a resolvable neighbor (create a second,
    neighbor `Device` + `Port` + `physical_link` edge for those); the
    other 38 are `learned_macs`-only, per the rule in §3.1 — no node created for them
  - 4 disconnected ports (`oper_status: down`, no `physical_link`)
  - 2 `Port` nodes with `if_type: "lag"`, each with two physical member
    ports (`if_type: "physical"`) whose `lag_id` points at it
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
  "stats": {"neighbors_total": 0, "resolved": 0, "device_only": 0},
  "collected_at": "2026-09-10T12:00:00Z"
}
```

`stats.device_only` counts neighbors where the participant (chassis ID /
sysname) resolved but the remote port didn't — the case decided in §3 rule
1's follow-up note (`Device` created, no `physical_link`). Keep it distinct
from `resolved` (fully resolved, link created) rather than lumping both
into one success count — this is exactly the granularity the
"discovery-quality visibility" backlog item (§11) needs, and it costs
nothing extra to track since ingest already has this information at the
point it decides not to create the edge.

**Known gap, confirmed during implementation: this schema doesn't capture
LAG membership, so no `Port.lag_id` (§2.1) is ever set by the real
collector today.** `push.py` detects `if_type: "lag"` from `ifType` (161),
but nothing here walks `ifStackTable` or `ieee8023adTable` to find *which*
physical ports roll up into a given LAG port — so a LAG port currently
lands in `topo_nodes` as an isolated, member-less `Port`, and `ingest.php`
has no membership data to set `lag_id` from on the member ports, even
though its own upsert logic (§3 rule 4) is otherwise correct and ready to
apply it. Fixing this needs two things together, not one: (1) `push.py`
walks the relevant MIB to get the mapping, (2) the blob schema above gains
a field for it (e.g. `"lag_members": [{"lag_if_index": 45,
"member_if_index": 1}, ...]`) — don't build one without the other. Until
then, LAG ports are correctly typed but every member port's `lag_id` stays
`NULL`; this is a real, tracked gap, not a silent one. Same status as the
CAM-table/`learned_macs` gap noted in §1 (also not yet in this blob
shape) — both are real collector work, not something to work around in
`ingest.php`. Note also that because `lag_members` lands in the *same*
blob that creates/updates the `Port`s themselves, once this is built LAG
membership will go stale in lockstep with the `Port` row it belongs to —
this is the concrete basis for §2.3's decision to model membership as
`lag_id` rather than a separately-tracked edge; see that note if this
gap's fix ever changes shape (e.g. a future separate LAG-discovery path)
in a way that breaks that assumption.

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
specified (evidence threshold, MAC/chassis-ID reconciliation, upsert,
`physical_link` canonicalization), writes to `topo_nodes`/`topo_edges`.
Runs on its own schedule, independent of the push interval — they don't
need to be synchronized.

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

**A reporter's own `Device` node gets `represented_by` its own `Host`
automatically and deterministically — this is not the same mechanism as
§3.2, and not a weakening of it.** When ingest reads a reporter's blob via
`item.get`, it already knows *which Host* that item belongs to — that's a
direct fact of where the data came from, not something inferred from
MAC/chassis-ID matching. **Building "the `Device` node" from the blob's
`reporter.*` fields means the same rule 4 upsert (§3) as anywhere else —
match by `chassis_id`, then `mgmt_ip` — never an unconditional create.**
This matters specifically for the case where the device was already seen
as someone else's LLDP neighbor before it became a reporter itself: rule 4
must find and reuse that pre-existing `Device` row (created earlier as a
neighbor observation), not create a second one. Skipping this check would
produce a `Device`-level duplicate that the unconfirmed-port merge rule
(§3 rule 4) cannot fix, since that merge operates on `Port`s, not on
`Device` identity itself — the two mechanisms solve different layers of
the same "this thing was seen before it became a reporter" problem, and
both have to fire correctly for the full transition to work. Once the
correct (possibly pre-existing) `Device` is resolved this way, it can be
linked to that exact `Host` with certainty, no opportunistic matching
involved, subject to the same `represented_by` 1:1 constraint as any other
case (§2.3) — if that `Device` somehow already has a *different* active
`represented_by`, don't silently override it, log and skip, same as any
other conflict. Mark these edges `matched_by: "reporter_self"` (§2.3) to
distinguish them from an opportunistic MAC match.
**This applies only to the reporter's own `Device` — every neighbor in the
blob's `neighbors[]` still goes through §3.2's opportunistic MAC-based
reconciliation unchanged**, since there the identity genuinely is uncertain
and needs a real matching decision, unlike the reporter's self-identity.

## 5. Zabbix API pull

**`topo_nodes` rows for `Host`/`Proxy` are created lazily, not for every
Zabbix object — this is a deliberate design decision, not the default you'd
get from a naive reading of `host.get`/`proxy.get`.** A `Host`/`Proxy` only
gets a `topo_nodes` row when there is an actual reason for it to exist in
the topology: a MAC match under §3.2 below, `reporter_self` (§4.1, happens
during ingest, not this pull), or explicit manual `/promote` (§6). A Zabbix
host with no network-topology role — most application/VM hosts in a
typical instance — never gets a `topo_nodes` row at all, and is never
rendered, searched, or paginated as part of the topology graph. This is
what the FR document's "shows the real network topology" (§0) actually
implies once taken literally: mirroring every Zabbix host into the
topology store regardless of relevance was never the goal. It also removes
the need for a separate "unassociated hosts" filter/panel (§11's backlog
item, now superseded) — there's nothing left to filter once irrelevant
hosts never become nodes in the first place.

- `host.get` with `selectInterfaces` **and `selectInventory`**, across all
  hosts. `selectInventory` is not optional — §3.2 reconciliation reads MAC
  from `inventory.macaddress_a`/`macaddress_b`, which isn't returned at all
  unless explicitly requested. This call is read-only against Zabbix and by
  itself writes nothing to `topo_nodes` — it's purely the data source for
  the matching step below. (This does not reduce Zabbix API traffic versus
  the earlier eager-upsert design — inventory still has to be pulled for
  every host to know whether it matches — the savings are in `topo_nodes`
  row count and graph size, not API call volume.)
- For each `Host` returned, attempt Device reconciliation per §3.2 against
  existing `Device`/`Port` MACs. **Only on a match**: upsert a `topo_nodes`
  row (`type='host'`, `host_ref=hostid` — do not write `name`/`status` into
  `attrs`, see §2.2) and create the `represented_by` edge in the same step.
  No match → no node, no edge, nothing written for that host. (Monitoring
  assignment to a `Proxy`/`ProxyGroup` is not part of this pull at all —
  it's resolved live at read time, §2.3, §6, and is only ever shown for a
  `Host` that already has a node for some other reason.)
- `proxy.get` is **not** called by this pull at all, since `Proxy` has no
  automatic matching path (see below) and so nothing to reconcile it
  against. A `Proxy` node is created only via manual `/promote` (§6), which
  upserts it on demand.
- **`Proxy` does not go through §3.2's MAC reconciliation — there is no
  MAC source for it.** Confirmed via the Zabbix API's Proxy object: it has
  no `inventory` field at all (inventory is a `Host`-only subsystem), so
  there is nothing analogous to `host.inventory.macaddress_a/b` to read.
  **`reporter_self` (§4.1) doesn't apply to `Proxy` either — not
  opportunistically, not ever.** `reporter_self`'s certainty comes from
  knowing which `Host` a Trapper item belongs to, and a Zabbix `Proxy`
  object cannot own items at all — `zabbix_sender`'s `-s` flag always
  names a `Host`, never a proxy (confirmed against Zabbix's own docs/
  forum guidance). If the physical machine running a proxy needs to be a
  reporter, it has to be onboarded as its own separate `Host` — the
  resulting `reporter_self` link then attaches to *that* `Host`, not to
  the `Proxy` topology node. **The only way a `Proxy` gets a
  `represented_by` edge — or a `topo_nodes` row at all — is manual
  `/promote` (§6).** Do not implement or advertise any automatic path for
  `Device`↔`Proxy` linking — neither MAC-based nor `reporter_self`-based
  exists for it.
- **The Host+Proxy same-pull race this used to describe no longer applies.**
  An earlier version of this rule handled the case where a `Host` and a
  `Proxy` processed in the same pull both matched the same `Device` — that
  scenario is now impossible via automatic matching, since `Proxy` never
  auto-matches at all (see above). The general 1:1 rejection behavior
  (§2.3) still covers any *manual* conflict (e.g. two people racing to
  `/promote` the same `Device`), just not as a pull-specific case anymore.
- Run manually — no scheduler in this prototype.

## 6. Backend API surface

Minimal REST surface for the UI. Every endpoint that returns a `Host` or
`Proxy` node must join `host_ref`/`proxy_ref` against `hosts`/`proxy` for
display name/status, and every endpoint returning port item info must
join `zabbix_itemids` against `items` — never read stale copies from `attrs`
(see §2.2).

- `GET /topo/devices` — all `Device`+`Host`+`Proxy` nodes (id, type, display
  name, monitoring state) for the graph view. For `Host` nodes, include
  `maintenance_status` (live-joined from `hosts.maintenance_status`/
  `maintenanceid`, never cached into `attrs`) — this is needed at the graph
  level, not only in a side panel, since it changes how the node's severity
  color should be read at a glance. **Also for `Host` nodes**: include the
  live-resolved monitoring assignment (§2.3) — `monitored_by`, `proxyid`,
  `proxy_groupid`, and `assigned_proxyid` (only meaningful in the
  proxy-group case) straight from `host.get`, plus the resolved target
  node id (whichever of `proxyid`/`assigned_proxyid` actually applies) so
  the frontend doesn't need to replicate that branching logic itself. This
  is what §7's monitoring-assignment line and blind-spot indicator render
  from — batch this across all returned `Host`s in one `host.get` call
  (`proxyids`/`proxy_groupids` filters, or a single call scoped to the
  returned `hostid`s), never one call per host (§10 point 3).
- `GET /topo/devices/{id}/neighbors` — 1-hop `physical_link` neighbors, for
  progressive graph expansion (never return the full graph in one call).
  Since the graph view never renders `Port` nodes (§7), this endpoint must
  traverse `Device → Port → physical_link → Port → Device` internally and
  return the resulting `Device`/`Host`/`Proxy` neighbors — `Port` nodes are
  an implementation detail of the traversal, never part of the response.
  Each neighbor edge includes a `severity` field, derived live from the
  trigger(s) tied to the two endpoint ports' `zabbix_itemids` (see §2.3) —
  highest active severity, or null if none. Each edge also includes the
  underlying `physical_link.attrs.last_seen` value and a derived `stale`
  boolean (`last_seen` older than the threshold in §7) — compute `stale`
  server-side rather than shipping the raw threshold logic to the client.
- `GET /topo/devices/{id}/ports` — full port list for the side-panel table,
  grouped as: **Connected (LLDP)** / **Partial connectivity evidence** /
  Disconnected / Port-channel / Management. Not "connected MAC-only" —
  a MAC-only port has no `physical_link` (§3 rule 1), so labeling it
  "connected" the same way as an LLDP-confirmed one invites the reasonable
  but wrong question "where's the physical link for this port?" The
  group name must make clear this is partial evidence, not a connection.
- `GET /topo/nodes/{id}/problems` — active problems/triggers for a `Host`
  node only (`id` here is a `Host` node id, not a `Device` id — hence the
  different path prefix from the `Device`-rooted endpoints above, which
  all take a `Device` id). Live-joined from Zabbix (`problem.get`/
  `trigger.get`), never stored in `attrs`. **Not for `Proxy`**: Zabbix has
  no clean "this problem belongs to this proxy" semantics — problems
  attach to triggers, which attach to hosts/items, not to the `Proxy`
  config object itself. Don't invent a heuristic for it (e.g. "if the
  proxy's machine happens to also be a monitored host"); a `Proxy` node
  simply has no Problems section in the UI (§7).
- `GET /topo/hosts/search?q=` — search Zabbix hosts by name/IP directly via
  `host.get`, **not** against `topo_nodes`. This is the only host picker for
  `/promote` below: under §5's lazy-creation policy, most Zabbix hosts have
  no `topo_nodes` row at all, so there's no local list to search against.
  Returns `hostid`/name/status only; writes nothing. `GET
  /topo/proxies/search?q=`, backed by `proxy.get`, serves the same role for
  promoting to a `Proxy`.
- `POST /topo/devices/{id}/promote {host_id}` — manually create a
  `represented_by` edge, where `host_id` is a **Zabbix `hostid`** (from the
  search endpoint above), not a `topo_nodes` id — the target `Host` may not
  have a `topo_nodes` row yet. This endpoint must itself upsert the `Host`
  node (`host_ref=hostid`) if it doesn't already exist, using the same
  upsert-not-insert discipline as §3 rule 4, before creating the edge — a
  second `/promote` against an already-materialized `Host` must not create
  a duplicate node. Deliberate user action, never automatic, and does
  **not** run the §3.2 strong-key check — see the "manual override" and
  "1:1 cardinality" notes under §2.3 for the exact rules this endpoint must
  enforce (reject if either side already has an active `represented_by`). A
  `{proxy_id}` variant (a Zabbix `proxyid`) works the same way for `Proxy`.
- `POST /topo/devices/{id}/depromote` — manually delete the `represented_by`
  edge for this `Device`. Hard-deletes the edge row; no soft-delete or
  `valid_to` marker (temporal versioning is out of scope, see §1). Does not
  touch the `Device` node itself — it reverts to the same unassociated state
  described in §3.3. **Also deletes the `Host`/`Proxy` `topo_nodes` row this
  edge pointed at, unless something else still gives it a reason to exist**
  (a MAC match under §3.2, or `reporter_self`) — under §5's lazy-creation
  policy a `Host`/`Proxy` node has no independent existence the way a
  `Device` does (§2.2: it's a thin pointer, trivially recreated from
  `host_ref`/`proxy_ref` on the next promote or reconciliation pass), so
  leaving an orphaned node behind after depromote would silently
  reintroduce the exact "node with nothing to show" clutter §5 exists to
  avoid. This is a deliberate asymmetry with `Device` (rule 3, §3): `Device`
  represents a real physical entity that persists whether or not Zabbix
  knows about it, so it is never deleted; `Host`/`Proxy` is only ever a
  pointer into Zabbix's own tables, so deleting and recreating it costs
  nothing.
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
  - `Device` **with an active `represented_by`**: not rendered as a graph
    node at all. Only its `Host`/`Proxy` is shown, using the exact same
    styling it would have on its own — no visual change to signal the
    association, no split, no badge, for now. (A small indicator icon
    showing "this Host/Proxy has an associated Device" is a reasonable
    future addition — deliberately deferred, not designed now.) The
    `Device`'s own data (ports, etc.) moves into a section of the
    `Host`/`Proxy`'s side panel instead — see below.
  - `physical_link` connects to the `Host`/`Proxy` node when the underlying
    `Device` is the endpoint of that link — since the `Device` has no
    separate visual position once merged into its `Host`/`Proxy`, the edge
    attaches to wherever that `Host`/`Proxy` node is drawn.
  - `physical_link` line style: solid when `discovered_via: "lldp"`, dashed
    when `discovered_via: "manual"`. Same dashed/solid language as `Device`
    association status, reused deliberately rather than inventing a new
    channel — but note it's carried by the edge here, not the node.
- **`Host`↔`Proxy`/`ProxyGroup` monitoring assignment gets its own
  distinct line style/color** — not the dashed/solid channel already used
  for `physical_link` provenance (that's a separate meaning and shouldn't
  be reused here). Drawn from the live-resolved data in `/topo/devices`
  (§6) — there is no stored edge to draw from. `represented_by` is never
  drawn as a line at all (see above), so there's no risk of confusing the
  two on screen.
- **Blind-spot indicator**: when a `Proxy` node is unreachable, any `Host`
  whose live-resolved monitoring assignment (§6) points at it — or, for
  the proxy-group case, whose `assigned_proxyid` currently resolves to it
  — must be visually marked as "monitoring blind spot" (e.g. a distinct
  badge/hatching), never rendered with the same problem-color styling used
  for an actual host-level issue — see the monitoring-assignment semantics
  note in §2.3.
- **`physical_link` severity styling**: color/weight the link using the
  `severity` field from `/neighbors` (§6) — e.g. a link carrying an active
  disaster-level trigger renders differently from a quiet one. This is
  separate from the dashed/solid convention used for node association
  status; do not repurpose that same visual channel for link severity.
- **`physical_link` staleness indicator**: when `/neighbors`' `stale` flag
  is `true` (`last_seen` older than **7 days**, computed server-side —
  this threshold is a starting point, not tuned against real data yet),
  render the link at reduced opacity (roughly 40–50%). This is a **third,
  independent visual channel** — it must not touch the dash pattern
  (provenance: manual vs. LLDP) or the color/weight (severity). A stale
  manual link is still dashed, just faded; a stale link with an active
  critical trigger is still colored for that severity, just faded. Don't
  collapse staleness into either of the other two channels. This is
  read-time-only — no data model or ingest change, per the reasoning
  already in §11's lifecycle note; it doesn't replace a real lifecycle
  model, just makes the existing `last_seen` fact visible.
- **Side panel — `Device` with no `represented_by`**: click → call
  `/ports`, render the grouped table (port / status / connected-to / source
  badge: `LLDP` / `MAC only` / `—`). Includes the "Promote to host" button
  (below).
- **Side panel — `Host`/`Proxy`**: click → one panel, up to two sections,
  no half-click routing to worry about:
  - **Problems section** (`Host` only — never shown for `Proxy`, per
    `/problems`'s scope above): call `/problems`, render active problems
    (severity, name, age). Empty state, not a blank panel, when there
    are none.
  - **Device section** (present only when this `Host`/`Proxy` has an active
    `represented_by`): the same grouped port table as the standalone
    `Device` panel above, via `/ports` on the associated `Device`. Includes
    the "Depromote" button (below). Omit this section entirely for a
    `Host`/`Proxy` with no associated `Device` — under §5's lazy-creation
    policy this should be a rare, transitional case rather than the common
    one: a `Host`/`Proxy` node now only exists in the first place *because*
    it has (or briefly had, mid-depromote) a `represented_by` edge, unlike
    an earlier version of this spec where most of a large "unassociated
    hosts" list would hit this branch. Still worth handling defensively in
    the UI rather than assumed impossible.
  - A `Proxy` panel, then, should effectively always have a Device section
    under §5's lazy-creation policy — a `Proxy` node with no
    `represented_by` shouldn't normally exist to be clicked on in the first
    place.
- **"Promote to host" button**: in the standalone `Device` panel (no
  `represented_by` yet); calls the `/promote` endpoint. Once promoted, the
  `Device` stops appearing as its own graph node (see above) and this
  button no longer applies — the same data now shows in the Device section
  of its `Host`/`Proxy`'s panel.
- **"Depromote" button**: in the `Host`/`Proxy` panel's Device section;
  calls the `/depromote` endpoint. After depromotion, the `Device` reappears
  as its own standalone graph node (dashed), and the Device section
  disappears from the `Host`/`Proxy`'s panel.

## 8. Acceptance criteria

- Loading the seed data produces `Device` + `Port` nodes whose
  connected/disconnected/LAG/management counts match §4 exactly (40/4/2/2),
  with only 2 of the 40 connected ports resolving to a neighbor `Device`.
- Deleting a `Device` node removes all of its `Port` nodes automatically
  (via `device_id`'s `ON DELETE CASCADE`, §2.1) — confirms the cascade
  works the same way it does for `host_ref`/`proxy_ref`, now that `Port`'s
  parent is a plain column rather than a `topo_edges` row.
- A Zabbix host matching the seed `Device` by MAC per §3.2 (via
  `inventory.macaddress_a`/`macaddress_b` — not chassis ID, which isn't a
  valid `Device`↔`Host` match key; see rule 2's correction) ends up with a
  `represented_by` edge after the API pull runs. **This MAC-based path is
  `Host`-only** — do not write an equivalent test for `Proxy`, since
  `Proxy` has no MAC source and never goes through this path (§5).
- A seed `Proxy` never gets a `represented_by` edge automatically — not by
  MAC, not by `reporter_self`. Confirm the API pull does *not* attempt or
  claim to auto-match a `Proxy` at all; the only way it gets one is a
  manual `/promote` call.
- The UI graph correctly distinguishes `Host` (solid) from unassociated
  `Device` (dashed), and the port drill-down table groups ports the
  same way as in §7.
- Re-running the seed load **in its default (upsert) mode, without
  `--reset`**, does not create duplicate `Device`/`Port` nodes — this
  validates the upsert logic in §3.4 ahead of the real collector. (Running
  with `--reset` trivially avoids duplicates too, but proves nothing about
  upsert matching — see §4.)
- A `Host` assigned to a `Proxy` in Zabbix shows the correct live-resolved
  monitoring assignment in `/topo/devices` (§6) — no separate pull step
  required for this, unlike `represented_by`, since it's never stored.
  Reassign the same host to a different proxy directly in Zabbix and
  confirm the very next `/topo/devices` call reflects it immediately, with
  no ingest/pull run in between — this is the concrete difference from a
  stored edge worth explicitly testing.
- Manually marking a seed `Proxy` as unreachable renders its
  live-resolved `Host`s with the blind-spot indicator from §7, not with
  problem/severity styling.
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
- A Zabbix host with no MAC match to any seed `Device` and never manually
  `/promote`d never gets a `topo_nodes` row at all — confirms the
  lazy-creation policy in §5, not just that MAC matching itself works
  correctly on hosts that do match.
- Promoting a `Device` to a `Host` that has never previously matched by MAC
  (no prior `topo_nodes` row for it) creates that `Host`'s node and the
  `represented_by` edge in the same `/promote` call — confirms the
  on-demand upsert path in §6, not just edge creation against an
  already-existing node.
- Depromoting a `Device` whose `Host` has no other reason to exist in
  topology (no MAC match, not a reporter) removes that `Host`'s
  `topo_nodes` row along with the edge. Depromoting a `Device` whose `Host`
  is itself a reporter (`reporter_self` gives it an independent reason to
  exist, per §6) leaves that `Host`'s node in place — confirms the
  depromote cleanup rule in §6 checks for other reasons to exist, not a
  blanket delete.
- Attempting to `/promote` a second `Device` onto a `Host`/`Proxy` that
  already has an active `represented_by` edge is rejected (or requires an
  explicit `/depromote` first) — confirms the 1:1 constraint from §2.3 is
  actually enforced, not just documented.
- Attempting to `/link` a `Port` that already has an active `physical_link`
  to a third port is rejected — confirms the port-level uniqueness
  constraint from §2.3 is enforced, not just the (src, dst) pair uniqueness.
- Two reporters that are each other's LLDP neighbor (the `Router1`↔`Switch1`
  case) converge to **one** `physical_link` between their two real `Port`s,
  with no leftover unconfirmed `Port` on either side, regardless of which
  reporter's push/ingest runs first — confirms both halves of the
  unconfirmed-port merge rule in §3 rule 4 actually fire, not just
  canonicalization. **Verify this by re-running ingest at least 5–7 times
  in a row and confirming the same `Port`/`physical_link` row IDs persist
  unchanged across runs — not just that the total counts stay flat.**
  Stable counts alone do not prove convergence for this rule (see the
  methodological note in §3 rule 4); a create-one/delete-one cycle each
  pass would pass a counts-only check while never actually stabilizing.
- A seed `Device` re-ingested with the same `mgmt_ip` but a **changed**
  `chassis_id` updates the existing `Device` row's `chassis_id` in place —
  it does not create a second `Device`. Confirms the lower-priority-match
  overwrites-identity rule in §3 rule 4, not just the matching order.
- A `physical_link` with `last_seen` older than 7 days renders faded
  (reduced opacity) regardless of its `discovered_via` (dashed or solid)
  or severity color — confirms staleness is a genuinely independent visual
  channel, not collapsed into either of the other two. A fresh link with
  the same `discovered_via`/severity combination renders at full opacity.
- For a `physical_link` between two reporters, stopping one reporter's
  push while the other keeps confirming updates only that side's
  `last_seen_src`/`last_seen_dst` — the other side's field, and the
  aggregate `last_seen`, keep advancing. Confirms the two sides are
  tracked independently, not silently masked by whichever side happens to
  report more recently.
- **Dedicated test, not just covered incidentally by the above: an
  observation confirming a link updates the field matching its
  *canonicalized* side, not the identity of whichever reporter sent the
  push.** Set up a link canonicalized as `src_id=17`/`dst_id=42` (per
  §2.3's canonicalization rule), then send a push from `Port 42`'s
  reporter confirming the link — assert `last_seen_dst` updates, not
  `last_seen_src`, even though the push came "from the Port 42 side."
  This is exactly the mapping described in §2.3's `last_seen_src`/`dst`
  semantics note and table — worth its own test specifically because it's
  easy to implement backwards (updating whichever field matches "the
  reporter that sent this," rather than "whichever canonical side that
  reporter's port maps to") and have every other test still pass by
  coincidence on a symmetric fixture.
- **The full neighbor-to-reporter lifecycle transition, tested end to end
  as one scenario, not just as separate unit tests of the three
  mechanisms it touches.** Sequence: (1) `Reporter A` pushes, sees
  `SwitchB` as an LLDP neighbor — confirms exactly one `Device(B)` is
  created, with an unconfirmed `Port` and a `physical_link` to `A`'s real
  port. (2) `SwitchB` is later onboarded and starts pushing as a reporter
  in its own right — confirms `Device(B)` is **reused, not duplicated**
  (rule 4 upsert via `chassis_id`, per this section's correction), gets
  `represented_by` to its own `Host` via `reporter_self`, its unconfirmed
  `Port` is merged into the newly-created real one (§3 rule 4's merge),
  and the `physical_link` from step 1 ends up pointing at real `Port`s on
  both ends with no duplicate edge. (3) If `SwitchB`'s own push also
  reports `A` as its neighbor (reciprocal LLDP), confirms this still
  converges to the same single edge, not a second one. Check the end
  state by ID, not just by count, per the methodological note in §3 rule 4.
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
- A freshly onboarded reporter with **no inventory MAC configured** still
  gets a `represented_by` edge to its own `Host` after ingest, with
  `matched_by: "reporter_self"` — confirms the self-identity link doesn't
  depend on the opportunistic MAC path from §3.2, unlike neighbor matching.

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

The seed/prototype scale (tens of nodes) hides four issues that will matter
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
   graph expansion. **§5's lazy `Host`/`Proxy` creation shrinks this
   response's likely size** — most Zabbix hosts never become `topo_nodes`
   rows at all now — but doesn't make this entry stop applying: `Device`
   count is driven by LLDP-discovered neighbors, not by Zabbix's host
   population, and can still be large on a dense real network regardless of
   how few Host/Proxy nodes end up alongside it.

2. **Reconciliation and self-upsert both need indexes, on different fields
   for different reasons — don't conflate them.** §2.1 deliberately keeps
   node data in JSON `attrs` rather than typed columns, to keep the schema
   stable while the model was still moving. Two separate lookups both pay
   for that today:
   - §3.2's `Device`↔`Host` reconciliation matches on `Device.attrs.mac`
     only (per rule 2's correction — `chassis_id` is not used here, and
     `Proxy` isn't part of this lookup at all, since it has no MAC source —
     see §5). Without a generated/indexed column over `attrs->>'mac'`,
     every Zabbix API pull becomes a full scan over all `Device` nodes at
     real scale.
   - §3 rule 4's `Device` self-upsert (recognizing the same physical entity
     across ingest runs) and the unconfirmed-port merge (§3 rule 4) both match
     on `Device.attrs.chassis_id`/`mgmt_ip` — a different lookup, for a
     different purpose, needing its own index over `attrs->>'chassis_id'`
     (and `mgmt_ip`).

   Add generated columns with indexes for `mac` and `chassis_id`
   specifically — this does not require reverting the JSON-attrs decision
   for anything else.

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
   copy `Host` name/status/problems or `Proxy` name/status into `attrs` —
   see §2.2, §6; `Proxy` has no problems to copy in the first place, per
   §6's note on this) is correct on the data side, but doesn't specify the
   query shape. A naive
   implementation issuing one query per node while assembling a list
   response is an N+1 query pattern that will dominate response time at
   scale. Every endpoint that returns a list of nodes must resolve their
   live-joined data (`hosts`, `proxy`, `items`, `problem`) with a single
   batched query against the whole result set, not one query per node.

4. **Raw row count in `topo_nodes` is a storage-volume concern, separate
   from the query-performance issues above — don't conflate the two.**
   `Port` rows are never filtered down to only linked ones (§2.2/§7's port
   drill-down deliberately needs disconnected and MAC-only ports too — see
   the seed fixture's 40/4/2 breakdown, §4, and its acceptance criteria,
   §8). At real scale this adds up: 1,000 switches × 48 ports ≈ 48,000
   `Port` rows alone, on top of `Device`/`Host`/`Proxy` and every edge
   type. This is not a query-performance problem — every read path that
   touches ports is already scoped to one device via the indexed
   `device_id` column (`idx_topo_nodes_device_id`, §2.1), so total table size
   doesn't affect any single query's cost. It's purely about how much the
   table grows over time, and most of that growth is low-signal rows
   (unused or MAC-only ports, per the same 40-of-48 pattern already
   observed in testing). No retention/archival mechanism exists for this
   today, and none is proposed here — this entry exists so the volume
   question isn't mistaken for a performance regression, and so retention
   for genuinely stale, low-signal rows (e.g. a MAC-only port with no
   `learned_macs` activity on a device that hasn't reported in a long
   time) is treated as a real, separate design task if it comes up, not an
   emergency query-optimization fix.

What already holds up without changes: the per-device port table (§7) is
bounded by port count on one device, not overall network size; `physical_link`
severity via `/neighbors` (§6) is bounded to 1 hop from the selected node; and
the host/proxy search endpoints added in §6 (`/topo/hosts/search`,
`/topo/proxies/search`) already query Zabbix directly rather than a locally
stored list, so they need no separate scaling fix of their own — unlike the
"unassociated hosts" list §11 used to describe, which no longer exists as a
feature under §5's lazy-creation policy.

## 11. Backlog (deliberately deferred, not required for §8)

- **Sparse `Port` model — an alternative architecture, tied to a specific
  trigger, not a replacement for the current design.** Proposed: store
  only `Port`s that participate in an actual (discovered or manual)
  connection — no disconnected/MAC-only/management ports at all — cutting
  `Port` rows per device from the full inventory (up to 48+) down to just
  the handful that matter for connectivity. Rejected as the current
  design for three concrete reasons, not as a bad idea in the abstract:
  1. The full-inventory `Port` model is what surfaced real implementation
     gaps during testing (the LAG-membership gap, the missing
     `learned_macs` collection, the basis for discovery-quality stats) —
     it's an already-proven diagnostic tool, not incidental weight.
  2. MAC-only ports are partial connectivity evidence with an unresolved
     far end (§3 rule 1), not "no connectivity" — a real sparse model
     would need its own answer for them, which this proposal doesn't
     resolve; on real hardware they're the majority of "connected" ports
     (§4's 40-of-48 breakdown), so this isn't a minor edge case.
  3. It solves §10 point 4's storage-volume concern with a much more
     drastic tool (don't collect the data at all) than that entry itself
     calls for (explicitly speculative, no retention mechanism proposed,
     revisit only if it's a real problem in practice).

  **Revisit only if §10 point 4 turns from a speculative concern into an
  actual operational problem** — not before. One smaller piece of the
  proposal was worth taking independently and already has been: the
  generic `part_of` edge was replaced with a plain `device_id` foreign key
  column on `Port` (§2.1) — that part didn't need to wait for a decision
  on full vs. sparse storage. Still open, and still independent of that
  question: renaming `Port` to something like `Topology Port`/`Endpoint`,
  if the current name is genuinely causing confusion with a full interface
  inventory.

- **`represented_by` 1:1 constraint — the most likely redesign candidate if
  this prototype proves out.** Nearly every documented limitation in this
  spec (the split-identity and stacked-switch/MLAG cases in §2.3, the
  BMC+OS multi-host-identity case) traces back to this single constraint.
  It's the right MVP compromise — simple, safe, and the known workarounds
  (pick one `Device`/`Host` to represent the physical entity, manually link
  the rest) are usable in the meantime — but if the prototype demonstrates
  real value, this is where the next iteration's architecture work should
  start, not a peripheral feature. See §2.3's limitation notes for the
  specific cases and what loosening the constraint would require (an
  asymmetric constraint plus a §7 rendering redesign to show more than one
  Device section on a single `Host`/`Proxy` panel, per that discussion).
- **Promoting LAG membership from `Port.lag_id` to a `member_of_lag` edge
  in `topo_edges`** — reconsidered during design and deliberately kept as
  a structural `Port` reference for now (§2.1, §2.3), not an independent
  relationship, specifically because §4.1's proposed `lag_members` data
  arrives in the same blob that creates/updates the `Port` itself, giving
  membership no independent staleness/provenance to track. **The explicit
  trigger to revisit**: if LAG membership ever becomes observable through
  a separate path from ordinary `Port` discovery — its own collection
  schedule, its own possible staleness independent of the `Port` row, or a
  need for per-member attributes like LACP active/standby state — promote
  it to `topo_edges` as `member_of_lag` (`Port[physical] → Port[lag]`),
  the same category of redesign trigger as `represented_by`'s 1:1
  constraint above and the sparse-`Port` model earlier in this section.
  Until that trigger arrives, `lag_id` is the simpler, sufficient model.
- **Blob generation/snapshot identifier** — the push blob (§4.1) currently
  has `collected_at` but no sequence/version marker (e.g. `generation: 42`
  or `snapshot_id: <uuid>`). Not needed for MVP, but worth adding before
  push frequency increases enough that ingest could plausibly read a blob
  from the middle of a sequence of rapid pushes rather than a clean
  before/after snapshot — a generation counter would let ingest detect and
  reason about that rather than silently processing an ambiguous read.
- **Full `physical_link` lifecycle state machine** — §3 rule 5 deliberately
  keeps a link that's stopped being reported by discovery forever (deletion
  is manual only, per §6), consistent with this spec having no lifecycle
  state machine at all (§1). A lightweight staleness *indicator* is now
  implemented (§7) — this entry is only about a full state model
  (something like the reference FR document's Active/Stale/Removed states,
  with transitions, possibly automatic cleanup). Revisit only if the
  indicator alone turns out not to be enough in practice.
- **Topology scope / admission policies** — right now, any LLDP-resolved
  neighbor (§3 rule 1) becomes a `Device` node, unconditionally. At real
  scale this raises a different question from either discovery-quality
  visibility or reconciliation: which discovered neighbors should even be
  admitted as nodes at all — e.g. excluding certain device classes
  (printers, phones), certain VLANs, or requiring a specific LLDP
  capability bit (e.g. Bridge) before admitting something as a `Device`.
  This is a distinct concern from both other backlog items above: it's not
  about matching quality or identity resolution, it's about deciding what's
  in scope for the graph to represent in the first place. Not needed at
  prototype scale — flagging so it isn't confused with either of the
  above when it does come up.
- **`Service` tree** — a second, symmetric projection of the graph (business
  impact / SLA, top-down) alongside the physical topology (bottom-up). Not a
  small extension of the current model, a parallel direction of work — see
  the earlier design discussion for the full reasoning. Structurally,
  though, it fits the existing pattern cleanly, confirmed when stress-testing
  the model against `Service → Host` as a candidate: `Service` would be a
  thin pointer node exactly like `Host`/`Proxy` (`service_ref` FK, live
  join), and `service_composed_of` (`Service → Host`) is a plain DAG edge —
  many-to-many, no uniqueness constraint needed, nothing like
  `represented_by`'s 1:1. This is why it's a parallel *direction* of work
  (a second projection, plus filtering/UI to show it) rather than a
  structural strain on the graph itself. **This is also the
  trigger condition for revisiting "Perspectives"** (the reference FR
  document's concept of multiple projections over one canonical graph —
  which domain objects render, which relationship types show, filtering
  behavior): with only the physical/monitoring layer that exists today,
  there's one real graph, not several independently valuable slices of it,
  so a full Perspectives system would be solving a problem that doesn't
  exist yet. Once `Service` gives a genuine second, orthogonal projection,
  revisit — not before.
- ~~**"Unassociated hosts" filter/panel**~~ — **superseded by the
  lazy-creation policy in §5.** This item assumed a `Host`/`Proxy` node is
  always created for every Zabbix object (via `host.get`/`proxy.get`) and
  then has to be hidden or filtered after the fact once it turns out to
  have no topology context. Under §5's current policy, a `Host`/`Proxy`
  with no `represented_by` (no MAC match, not a reporter, never promoted)
  never gets a `topo_nodes` row in the first place, so there's nothing left
  to filter — the clutter this item was designed to fix no longer occurs
  by construction. Left here, struck through, so the original reasoning
  isn't lost if lazy creation is ever reconsidered.
- **`Proxy Group`** (Zabbix HA proxy clustering) — largely resolved
  already, not by a new node/edge design but by the same live-resolution
  approach now used for ordinary `Host`↔`Proxy` assignment (§2.3, §6):
  `host.get`'s `monitored_by`/`proxy_groupid`/`assigned_proxyid` fields
  already tell you whether a `Host` is assigned to a group and which
  specific proxy is currently serving it — no stored edge, no `ProxyGroup`
  node required for basic display. **The blind-spot case still needs care,
  though — don't oversimplify it to "just check `assigned_proxyid`
  reachability."** Proxy groups have a `failover_delay`
  (`proxygroup.get`): during that window, `assigned_proxyid` can still
  point at a proxy that just went down while the group itself is mid-
  failover, not actually blind. The correct signal is `ProxyGroup.state`
  (which Zabbix computes itself — online/recovering/offline), read live,
  the same conclusion reached earlier when this was first discussed —
  resolving `assigned_proxyid` alone and inferring health from that one
  proxy's reachability would reintroduce exactly the false-positive risk
  the group feature exists to avoid. A `ProxyGroup` **node** purely as a
  visual/grouping entity on the graph (e.g. a box representing the whole
  HA group, showing `ProxyGroup.state` as a group-level health indicator)
  remains optional/cosmetic — but the *state resolution logic* itself is
  not optional if group support is added at all.
- **Tag display** — showing existing Zabbix tags (on `Host`, etc.) in the UI,
  e.g. in the side panel or as a graph filter. Not the same question as
  "should tags store the topology" (rejected — see the earlier design
  discussion); this is only about surfacing tags that already exist.
- **Indicator icon for a `Host`/`Proxy` with an associated `Device`** — §7
  currently shows no visual difference on the graph between a `Host`/`Proxy`
  with a `represented_by` `Device` and one without; the only way to tell is
  opening the side panel and checking for the Device section. A small icon
  would surface this at a glance without reviving the split-node design
  that was tried and abandoned for this purpose.
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
- **Passive infrastructure (patch panels, wall jacks, fiber patches) and
  SNMP-silent active devices** — related but distinct from the MAC-only
  case in §3 rule 1. MAC-only neighbors at least generate *some* evidence
  (a MAC in the CAM table), just not enough to clear the `Device` creation
  bar. These generate **no evidence at all, ever, from any protocol they
  themselves speak** — patch panels because they're passive, and consumer
  gear like TP-Link Easy Smart or Ubiquiti UniFi (§11's vendor-variance
  note) because they don't expose SNMP at all, even though they're real,
  actively-connected devices someone might know about and want represented.
  There is currently no way to represent either case, even manually: rule 5
  explicitly forbids manual `Device` creation. **Candidate resolution,
  raised as a customer-facing want rather than a lab-blocking need**: a
  narrow, explicit `POST /topo/devices` endpoint for manual `Device`
  creation — distinct from rule 5's prohibition, which exists to stop
  `/link` from silently creating unverified nodes as a side effect, not to
  block a deliberate, explicit "I know this device exists and represent
  it" action. Mark such nodes `Device.attrs.source: "manual"` (same
  audit-trail convention as `discovered_via`/`match_type` elsewhere), create
  at least one manually-tagged `Port` alongside it so the existing `Port`↔
  `Port` `physical_link` mechanism (§3.5, §6) works unchanged — no new edge
  type needed, just an entry point into what already exists. DCIM/cable-
  management import remains a separate, heavier alternative if this ever
  needs to scale beyond one-off manual entries.
- **Wireless connectivity (AP → client)** — an AP↔switch link fits
  `physical_link` fine, but an AP-to-wireless-client relationship doesn't:
  there's no physical port on the client side for `Port`'s model to
  attach to. If wireless visualization is ever wanted, `physical_link` is
  the wrong name and likely the wrong shape for that edge — probably a
  distinct relationship type (something like `connectivity`, not
  physically portless) rather than a forced fit into the existing one.
  Flagging the naming/semantic mismatch now so it isn't a surprise later.
- **Relaxing `physical_link` to allow an unresolved remote port** — the
  MVP decision in §3 rule 1 is strict: no `physical_link` without a
  resolved `Port` on both ends, even when the remote *participant*
  resolved fine (tracked separately via `stats.device_only`, §4.1). If
  this connectivity loss turns out to matter in practice, the alternative
  is a `Device`-to-`Device` (or `Port`-to-`Device`) connectivity edge for
  the unresolved case — a real model change (a new edge shape or a
  nullable endpoint), not a quick fix. Worth watching `stats.device_only`
  in practice before deciding whether this is worth building.
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

## 12. Anticipated review questions

Rationale for two decisions likely to be challenged in review, written to
be quoted directly rather than re-explained from scratch each time.

### "Why not use Zabbix Network Discovery to collect LLDP data?"

**Technical fact, not a design preference: Network Discovery cannot do
this, regardless of how it's configured.** Its SNMP check queries exactly
one OID per check, as a presence/alive probe — confirmed via Zabbix's own
debug logging (`zbx_snmp_get_values() num:1`). It does not walk SNMP
tables, and a check's result is never persisted as structured/historical
data — no item, no history — it's used once to evaluate discovery status
and trigger an Action, then discarded. `lldpRemTable` is a multi-row table
that needs walking and needs its result kept for processing; that
capability belongs to items + LLD, a structurally different Zabbix
subsystem with its own polling engine and storage. "Extending" Network
Discovery to do this would mean rebuilding LLD-style walking and storage
inside the wrong subsystem, not a small patch on top of it.

This is exactly why **LLD item-per-neighbor is already the first row of
§4's comparison table** — it's the correct native mechanism for walking
`lldpRemTable`, evaluated and not chosen for the reason already documented
there (item/history namespace pollution — dozens of items per reporter,
visible in ordinary item browsing). Wanting "LLDP via Network Discovery"
is, in substance, wanting LLD under a different name. Network Discovery in
this spec is used only for what it's actually built for: onboarding
reporter `Host`s (§4.1's bootstrap note) — a job it's well-suited to,
distinct from the data-collection job it can't do.

### "Why not use Zabbix Tags to store topology relationships?"

Considered early in this project's design (a community reference project,
`zabbix-AutoMapper`, does exactly this for a narrower problem — see below)
and rejected for structural reasons, not a style preference:

- **Tags exist only on Zabbix domain objects** (`Host`/`Trigger`/`Item`) —
  there is nowhere to put a tag on something that isn't one, which breaks
  the unmanaged-device requirement (§1) at the root. Representing an
  unmonitored neighbor would mean creating a fake `Host` just to have
  somewhere to hang a tag — recreating exactly the "node-per-MAC noise"
  problem §3 rule 1 exists to prevent, just via a different mechanism.
- **Tags are flat key-value strings, not typed edges with attributes.**
  `represented_by` carries `match_type`/`matched_by`/`created_at`;
  `physical_link` carries `discovered_via`/`last_seen` (§2.3). Encoding
  this in tags means either one tag per attribute (multiplying per edge)
  or serializing a blob into a tag value — both break the normal
  tag-based filtering/search tags exist for in the first place.
- **No referential integrity.** A tag like `parent=core-switch-1` is just
  a string; rename or delete the target and the tag dangles with no
  signal. This model gets that for free via real foreign keys plus
  `ON DELETE CASCADE` (§2.1).
- **No structure suited to graph traversal.** Tag lookup is a value scan,
  not adjacency — multi-hop traversal (root cause, blast radius, even the
  simple `/neighbors` expansion in §6) would need a hand-rolled traversal
  layer on top regardless, built on storage worse-suited to it than what
  this spec already uses.
- **Real-world precedent, already evaluated**: Pascal de Jessey's
  `zabbix-AutoMapper` (Zabbix Summit 2024) uses host tags (`link`/`label`/
  `type`) for exactly this. The author's own "Improvements" slide states
  plainly that tags are *"convenient, but not specifically designed for
  this purpose,"* and floats inventory fields as a better alternative he
  hadn't yet built. That project solves a narrower problem than this one
  (laying out already-known `Host`s on a map, not modeling unmonitored
  devices plus typed relationships) — and even there, tags are acknowledged
  as a workaround, not a good fit.

Tags could cover a narrow, `Host`-only version of this problem with no
unmanaged-device support. They don't structurally solve what this spec's
data model needs, which is why they're used only for their legitimate role
here — surfacing existing tags in the UI (§11's "Tag display" backlog
item) — never for storing the graph itself.
