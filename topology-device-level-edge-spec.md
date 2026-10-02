# Topology — device-level link (far port unknown): build spec (v0.8)

Status: **implemented and committed** (ingest, API, view, tests); browser
check pending. v0.8 is a spec change only; the code follows after approval.

**v0.8:** a link's precision only goes up. The port-level → device-level
path (old §5.2: reason `port_lost`, freshness threshold, conversion) is
removed; a `physical_link` whose far port is no longer identified stays
`physical_link` with a "not confirmed since" flag (§5.2 "Lost precision").
§4.4 (a pseudo port corrected when its device becomes a reporter) is named
as the one allowed `physical_link` → `device_link` conversion. §4.1–§4.3
create a `device_link` only where no active discovered `physical_link` to
the same far Device exists on the local port (§4, §5.2). `port_lost` is
removed from the reasons (§3.1). `last_seen_port` stays, for display only.
The v0.4 note below describes the removed path. The duplicated "Different
Device" bullets of §6.3 (copy error in v0.7) are reduced to one each.

**v0.7:** §4.4 — pseudo ports left on a device that becomes a reporter are
removed; their discovered links become `device_link`. Observation columns
`link_precision`, `far_port_reason`, `precision_lower` in
`topo_observations` (4cbc6bcc876), not in remote_attrs.

**v0.6:** a manual link with `shadow_ack` entries is not converted on
confirmation, both types (§6.3). Implemented and committed.

**v0.5:** manual link confirmed by discovery becomes discovered, for both
types (§6.3, table). §4.2 shared id compared within one reporter only;
`port_shared_id` wins over `lag_ambiguous` (§4.3).

**v0.4:** §10.2–§10.4 decided. Port-level link that loses port-level
confirmation past the freshness threshold becomes a `device_link` (§5.2,
new attr `last_seen_port`). Manual `device_link` allowed (§3.2, §6.3).
Reasons table with operator-facing explanations; `fdb_mac_only` reserved
(§3.1).

**v0.3:** §10.1 decided — a separate edge type `device_link`, not a
`physical_link` with a Device endpoint (§3). Refinement changes the type
in place, keeping the edge id (§5.1). §6.0 lists every existing rule that
must now cover both types.

**v0.2:** reciprocal pairing (both ends reporters, each naming the other
on exactly one port) removed from this spec and deferred to a separate
one (§1). FR lines (§11) cover the whole FR v1.1 identification /
lifecycle change set, not only this spec.

## 0. Objective

Today every `physical_link` joins two **Port** nodes. When the far
**Device** is identified but its **port** is not, the prototype has two
bad options: drop the link (the far end shows up isolated) or attach it
to a port that may not exist (a phantom port).

This spec adds a third outcome:

> A discovered link may end at a **Device** instead of a **Port** when the far
> device is identified and its port is not. It is stored as a separate edge
> type `device_link`, occupies the local port like any other link, and becomes
> a `physical_link` (same edge id) as soon as the far port becomes
> identifiable.

Origin: Scanopy (`interface_neighbor_hosts`, GH #701) keeps "port →
device" adjacencies in a table of their own, next to port ↔ port ones.
Their motivating cases are the same ones we will meet: switches that
advertise one chassis MAC as the port id on every port (D-Link,
TP-Link/Omada, UniFi, Westermo), and LAGs.

References:

- `topology-prototype-spec.md` — model spec (§2.3 port uniqueness,
  canonicalization; §3 rule 5 manual links).
- `topology-lld-part2-spec.md` — snapshots, ingest, `topo_observations`.
- `topology-link-replacement-spec.md` (v2.1) — contradiction, support,
  superseded, ambiguous.
- `topology-manual-contradiction-spec.md` (v1.1) — shadowing, acks keyed by
  Device.
- FR: Topology model v1.0 — §1 nodes and relationships, §4
  reconciliation.

If a requirement not listed here seems necessary while implementing,
stop and flag it rather than silently expanding scope.

## 1. Scope

**In scope:**

- Edge type `device_link` (§3), reasons (§3.1), manual `device_link`
  (§3.2)
- When it is created instead of a port-to-port link (§4)
- Refinement to port level, and lost precision (§5)
- Interaction with replacement, ambiguity and manual-contradiction
  rules (§6)
- Rendering and diagnostics (§7, §8)

**Out of scope:**

- FDB-based links (FR decision 1: LLDP/CDP only for MVP). Only the
  reason `fdb_mac_only` is reserved (§3.1); no FDB rule is added now.
- Pairing LAG members (§4.3 deliberately leaves them device-level).
- **Reciprocal pairing** — linking P ↔ Q port-to-port because two
  reporters name each other on exactly one port each, without matching
  advertised port ids. Deferred to a separate spec, to be written when
  the lab has a switch that advertises one chassis MAC on every port.
  Until then such links stay device-level (§4.2).
- Snapshots / history of the topology.

## 2. Terms

- **Port-level link:** `physical_link` Port ↔ Port (unchanged).
- **Device-level link:** `device_link` Port → Device. The local side is
  always a Port (a reporter always knows its own port).
- **Link** without qualifier: either type. Wherever an existing spec says
  "link" or `physical_link` in a rule listed in §6.0, it now means both.
- **Far port identification** — the existing chain that turns
  `rem_port` / `rem_port_desc` / port id subtype into a Port of the far
  Device (create or match).

## 3. Storage

- New `topo_edges.type` value `device_link`. `src_id` → node of type
  `port` (the local port), `dst_id` → node of type `device`. Directed by
  construction; no canonicalization (the existing canonicalization stays
  for `physical_link` only).
- Validation on write: `src_id` must be a port, `dst_id` a device; any
  other combination is rejected. `physical_link` keeps rejecting device
  endpoints — so code that assumes "a physical link joins two ports" stays
  correct and can't silently receive a device row.
- `attrs.far_port_reason` — why the far port is unknown (§4); required.
- `attrs.far_port_hint` — the far end's advertisement as received
  (`rem_port`, `rem_port_desc`, port id subtype), for display only.
  Untrusted text, escaped on render (model spec §9).
- `attrs`: same discovery attrs as `physical_link` (`auto`, `last_seen`,
  `last_seen_src`, `last_seen_dst`, `superseded_at`). `last_seen_dst` is
  set when the far Device's reporter names the local Device on any of its
  ports.
- New attr on discovered `physical_link`: `last_seen_port` — clock of the
  latest snapshot that contained the link at port level (both ports
  identified). Updated together with last_seen_* by port-level applied
  observations only; device-level observations of the same far Device keep
  the link alive (last_seen_*) but don't touch it. Missing on existing rows
  → treat as `last_seen` (no migration of values). For display only (the
  flag text of §5.2); no rule reads it.

**Port uniqueness (model spec §2.3):** "at most one active link per port"
counts **both types**. A `device_link` occupies its local port; it
occupies **no** port on the far Device.

**Why a separate type (decision, v0.3):** existing code and queries that
read `physical_link` keep their port ↔ port assumption; a device row can
never reach them by accident. The price is that every rule which must
apply to device-level links has to name the new type explicitly — §6.0 is
that list.

### 3.1 Reasons

`far_port_reason` is a fixed enum. Each value has an operator-facing
explanation shown in the link tooltip / details (§7) and in diagnostics
(§8). Texts below are the source for UI strings; wording may be polished,
meaning may not change.

| Reason | Produced by | Explanation shown to the operator |
|---|---|---|
| `port_unmatched` | §4.1 | "<D> reports its own ports, and none of them matches the port <R> sees (<hint>). The link is drawn to the device until the port can be matched." |
| `port_shared_id` | §4.2 | "<D> advertises the same identifier (<hint>) on several ports, so the exact port can't be told apart." |
| `lag_ambiguous` | §4.3 | "<R> has several links to <D> (<local ports>) whose remote ports can't be told apart, typically a link aggregation." |
| `manual` | §3.2 | "Created manually without a remote port by <user> on <date>." |
| `fdb_mac_only` | **reserved** | "Found by a learned MAC address (FDB) only; FDB doesn't tell which port of <D> it is." — **Not produced by this spec.** Accepted by validation so a later FDB spec adds no enum change; nothing writes it until then. |

Unknown values are rejected on write.

### 3.2 Manual `device_link`

- Operator creates it from a local port to a Device (port of the far
  Device not chosen), same UI entry point and permission as manual
  `physical_link` creation. `attrs.auto = false`,
  `far_port_reason = manual`, no `far_port_hint`.
- Manual-link rules apply (model spec §3 rule 5: manual wins, never replaced
  by a different neighbor; manual-contradiction spec v1.1: shadowing, acks,
  accept / keep). One exception — confirmation by discovery turns a manual
  link into a discovered one (§6.3). Details in §6.3.
- The operator can convert it to a manual `physical_link` by choosing the
  far port (edit in place, same edge id), and back.

## 4. When a device-level link is created

Far Device resolved as today (hardware ID → management IP → name within
reporter and local port, FR §4b). Then, instead of creating or matching a
far port, a device-level link is produced in exactly these cases, and only
on a local port that has no active discovered `physical_link` to the same
far Device (if it has one, §5.2 applies):

### 4.1 `port_unmatched` — far Device has authoritative ports

The far Device's ports come from a **walk by its own reporter**
(authoritative), and the advertised port matches none of them by the
existing matching chain.

- Do **not** create a port on that Device from the advertisement (it
  would be a phantom: the device calls its port `Gi0/1`, the neighbor
  advertises `1`).
- **Precondition check for the coding agent:** report what the prototype
  does today in this case (creates a port? drops the observation?) before
  changing it.

A far Device **without** authoritative ports (not a reporter) keeps
today's behaviour: its ports are created from what neighbors advertise —
that is the only description of them there will be.

### 4.2 `port_shared_id` — the advertised id names many ports

The port id (typically subtype MAC address) is the same on two or more
ports of the far Device: either its authoritative ports share it, or two
or more local ports **of the same reporter**, in its latest snapshots,
advertise the same far port id. The id names the device, not a port.

Only within one reporter (v0.5, as implemented): comparing ids across
reporters conflicts with the far-end occupancy rule (replacement spec
§3.2).

### 4.3 `lag_ambiguous` — several local ports to one far Device

Two or more local ports of the same reporter resolve to the same far
Device **and** their advertisements don't single out distinct far ports
(4.1 or 4.2 applies to them). All of them become device-level links to
that Device. No guessing which member is which: an arbitrary pairing is
worse than an honest "port unknown".

When 4.2 and 4.3 both apply, the reason is `port_shared_id` (the more
specific cause).

LAG members whose far ports **are** identified stay port-level as today.

### 4.4 Advertisement ports on a device that becomes a reporter (v0.7)

A device that was not a reporter has ports created from neighbor
advertisements (pseudo ports). When it becomes a reporter, its walk gives
real ports. After the PORTS merge (pseudo port matched to a real port by
if_index or normalized name → links moved, pseudo port deleted), a device
with real ports has no pseudo ports left:

| Leftover pseudo port | Result | Counter |
|---|---|---|
| has discovered links | each link converted in place to `device_link` (src = the other side's port, dst = this device, `port_unmatched`, hint from the advertisement, same edge id); pseudo port deleted | `pseudo_ports_converted` |
| name matches several real ports | not merged; then handled as the row above | `pseudo_ports_ambiguous` |
| matched real port already has another active link | move refused; then handled as the first row | `pseudo_ports_move_failed` |
| has a manual link | kept, not converted; reported | `pseudo_ports_kept_manual` |
| has no links | deleted | `pseudo_ports_removed` |

Without this rule a pseudo port that no real port matches keeps its
`physical_link` forever: the neighbor's snapshot still supports it, and a
new device-level observation of the same Device doesn't contradict it (§6.1)
and loses on precision (§6.2).

Counters and a pseudo_ports detail list are in the ingest summary (replace
the old PSEUDO-MERGE SKIP log lines). Snapshot ingest path only; the legacy
blob path is not covered. Implemented: 18a53cf7e3d.

## 5. Refinement

### 5.1 Device-level → port-level (in place)

Device-level link L = R:P → D. A later full ingest identifies the far
port Q of D for the same observation (D got walked, or the advertisement
changed):

- L is **converted in place**: `type` := `physical_link`, `dst_id` := Q,
  `far_port_reason` and `far_port_hint` removed, `physical_link`
  canonicalization applied. Same edge id, `last_seen_*` carried over.
  Converting rather than superseding + creating: it is the same cable,
  and a superseded `device_link` would leave a faded duplicate line on
  the graph.
- Not a contradiction and not a replacement: same local port, same far
  Device. Observation outcome `applied`.
- **Reverse half of the same cable.** If an active `device_link`
  K = D:Q → R exists (R being the Device of P), K describes the same
  cable from the other side. K is **absorbed**: deleted, its `last_seen`
  folded into the converted link's side for Q. K does **not** count as
  far-end occupancy of Q — otherwise K, supported by D's own snapshot,
  would block the refinement forever.
- Far-end occupancy (replacement spec §3.2) applies to Q for any other
  active link M (a `physical_link` on Q, or a `device_link` from Q to a
  Device other than R): M is evaluated by the usual rule. If M has
  support → no conversion, L stays `device_link`, observation `conflict`
  with `edge_id` = M.

### 5.2 Lost precision

A link's precision only goes up: a `device_link` becomes a `physical_link`
when the far port is identified (§5.1). A `physical_link` never becomes a
`device_link`, with one exception, §4.4 (below).

- A discovered `physical_link` whose far port is no longer identified in the
  latest snapshots, while the far Device is still seen on the local port,
  stays `physical_link` and active. Observation outcome applied, with
  `precision_lower`: true.
- This applies for every cause: port not matched, shared port id, LAG
  ambiguity.
- **Interaction with §4:** §4.1–§4.3 create a `device_link` only on a local
  port with no active discovered `physical_link` to the same far Device. If
  such a link exists, this section applies and the link stays
  `physical_link`.
- last_seen_* is updated. `last_seen_port` is not.
- The link shows a flag with the text: "The remote port <Q> hasn't been
  confirmed since <`last_seen_port`>; <D> is still seen on this port."
- If the far Device is no longer seen either, the link goes stale by the
  normal rule.
- **Allowed: §4.4.** A `physical_link` to a pseudo port is not port-level
  precision: the far port was only an advertisement of a device that was not
  a reporter. Converting it to a `device_link` when that device becomes a
  reporter (§4.4) corrects a phantom; it is not a downgrade.

## 6. Interaction with existing rules

### 6.0 Rules that must cover both types

With a separate type, none of these extends itself. Each must be changed
to read "`physical_link` or `device_link`" and covered by a test:

| Rule | Where | Change |
|---|---|---|
| Port uniqueness | model spec §2.3 | count both types on the local port |
| Staleness / freshness | model spec §3, FR Lifecycle 5a–5c | same threshold, same `last_seen_*` semantics |
| Contradiction, support | replacement spec §2, §3.1 | compare far **Device** for `device_link` (§6.1) |
| Far-end occupancy | replacement spec §3.2 | a `device_link` occupies no far port; reverse half exception (§5.1) |
| Superseded, revival | replacement spec §3.3, §4 | apply to `device_link` |
| Several neighbors / competing claims | replacement spec §5 | §6.2 |
| Legacy cleanup | replacement spec §6.5 | count both types as "active link on port" |
| Manual wins, shadowing, acks | manual-contradiction spec §2–§4 | §6.3 |
| Manual link creation / removal | model spec | manual `device_link` (§3.2); a discovered `device_link` can be removed manually like any discovered link (FR Lifecycle 5c: re-created if reported again) |
| Diagnostics, rendering | Part 2 §8, model spec | §7, §8 |

Anything else in the code that filters on `type = 'physical_link'`: the
coding agent lists every such place in its report and states for each
whether it must include `device_link`. Don't extend silently.

### 6.1 Replacement (v2.1)

- "Contradiction" compares **far Devices** for a `device_link`: a
  candidate on P resolving to the same Device D (any precision) does not
  contradict L = R:P → D. A candidate resolving to E ≠ D does, and §3 of
  the replacement spec applies unchanged.
- Superseded, revival and legacy cleanup apply to `device_link` as to
  `physical_link` (§6.0).

### 6.2 Several neighbors on one port (§5 of the replacement spec)

Unchanged. Several neighbors on P resolving to **different Devices** →
`ambiguous`, as today. Several observations on P resolving to the **same**
Device (e.g. LLDP and CDP with different port ids) → one link, at the
best precision any of them reaches.

### 6.3 Manual links and contradiction (v1.1)

Applies to manual links of both types. Contradiction stays keyed by
**Device**.

**Confirmation (decision v0.5).** Discovery that confirms a manual link
turns it into a discovered link, in place, same edge id. From then on it
follows the discovered rules (staleness, superseded). This is the existing
behavior for manual `physical_link`; it now applies to both types:

| Manual link | Discovery sees on its local port | Result |
|---|---|---|
| `device_link` R:P → D | D, device level | discovered `device_link`, reason from discovery |
| `device_link` R:P → D | D:Q, port level | discovered `physical_link` P ↔ Q (§5.1 conversion) |
| `physical_link` R:P ↔ D:Q | D:Q, port level | discovered `physical_link` (as today) |
| `physical_link` R:P ↔ D:Q | D, device level only | **stays manual** — discovery knows less than the operator; observation `shadowed`, not contradicted |

**Acknowledged links are not converted (v0.6).** A manual link (either type)
with at least one `shadow_ack` entry stays manual when discovery confirms
it, and keeps its `shadow_ack`. An ack is the operator's decision about
another neighbor on this port; converting the link would drop it and put
that neighbor under the replacement rules (ambiguous). Acks are kept until
revoked (manual-contradiction spec §4), so such a link stays manual until
the operator revokes all its acks.

**Different Device:**

- Discovery sees a **different** Device on the manual link's local port →
  `shadowed`, contradicted, badge, ghost, accept / keep — as in the
  manual-contradiction spec. Ghost line ends at the hidden Device's
  border when that observation is device-level.
- Accept discovery on a manual `device_link`: same as for a manual
  `physical_link` — manual link removed, discovered link (either type)
  created or revived at the next full ingest.

## 7. Rendering

- A device-level link is drawn from the local port to the **Device
  border**, not to a port. Line style distinct from stale, superseded,
  conflict and ghost.
- Tooltip / details: the reason's explanation from §3.1, with
  `far_port_hint` substituted when present.
- Manual `device_link`: device-level line style combined with the
  existing manual-link indicator.
- **LAG bundling:** device-level links between the same two Devices (in
  either direction) render as one bundled line labelled with the count
  and the known local ports on each side ("2 links: Gi0/1, Gi0/2 ↔
  ?"). Storage stays per local port.
- After refinement (§5.1) the link simply renders port-to-port.
- A `physical_link` with lost precision (§5.2) renders port-to-port with the
  "not confirmed since" flag; the flag text is the one in §5.2.

## 8. Diagnostics

- `GET /topo/ingest/status`: `links_device_level` (active, end of run),
  `links_refined` (this run), counts per `far_port_reason`.
- `GET /topo/observations`: field `precision` (`port` / `device`),
  `far_port_reason` when device; filter `precision=device`.
- Reasons are diagnosable without an snmpwalk: each device-level link
  says which reporter saw it, on which local port, and what the far end
  advertised.

## 9. Acceptance criteria

Snapshots written directly into `topo_lld_snapshot` with fixed clocks;
ingest runs as a real process.

- **Phantom avoided (4.1):** D is a reporter with port `Gi0/1`; R reports
  D on P advertising port `1` → device-level R:P → D, reason
  `port_unmatched`; no new port on D.
- **Non-reporter far end unchanged:** D is not a reporter → port created
  from the advertisement, port-level link, as today.
- **Shared chassis MAC (4.2):** R:P1 and R:P2 both report D with the same
  MAC as port id → two device-level links, reason `port_shared_id`.
- **Shared chassis MAC, single cable:** R names D only on P, D names R
  only on Q, both advertise the chassis MAC as port id → R:P → D and
  D:Q → R, both device-level, one bundled line (no reciprocal pairing in
  this spec).
- **LAG (4.3):** R names D on P1, P2; D names R on Q1, Q2; ids unusable →
  four device-level links, one bundled line R – D with "2 links".
- **LAG leg resolved:** same, but P1's advertisement identifies Q1 →
  P1 ↔ Q1 port-level; P2 → D and Q2 → R stay device-level.
- **Refinement (5.1):** `device_link` R:P → D, then D's walk exposes the
  advertised port Q → same edge id, now `physical_link` R:P ↔ D:Q.
- **Refinement with reverse half (5.1):** `device_link`s R:P → D and
  D:Q → R both active; R's advertisement becomes matchable to Q → one
  `physical_link` P ↔ Q (id of R:P → D), D:Q → R deleted, no `conflict`.
  5 more ingest runs change nothing.
- **Refinement blocked:** as above, Q holds a supported link M to a third
  Device → R:P → D stays `device_link`, observation `conflict`,
  `edge_id` = M.
- **Type validation:** writing a `physical_link` with a device endpoint
  or a `device_link` with a port `dst_id` is rejected.
- **Lost precision (5.2):** port-level R:P ↔ D:Q; later snapshots no longer
  identify Q (any cause: port not matched, shared id, LAG) while D is still
  seen on P → the link stays `physical_link`, same edge id; observation
  applied with `precision_lower`: true; `last_seen` advances,
  `last_seen_port` doesn't; the flag text is shown. Q identified again →
  `precision_lower` false, `last_seen_port` advanced (lab: S3 steps 4 and
  5).
- **Far Device disappears (5.2):** a `physical_link` whose far Device is in
  no latest snapshot goes stale by the normal rule and never becomes a
  `device_link`.
- **Interaction with §4 (5.2):** R:P has an active discovered
  `physical_link` to D; later snapshots would produce a device-level result
  for P → D under §4.2 or §4.3 → the link stays `physical_link`, no
  `device_link` is created on P.
- **Pseudo port (4.4, 5.2):** a `physical_link` to a pseudo port is still
  converted to a `device_link` when the device becomes a reporter (§4.4);
  this is not a downgrade.
- **No `port_lost` (3.1):** no code path writes `far_port_reason` =
  `port_lost`; validation rejects it as an unknown reason.
- **Refinement (5.1) works as before:** the existing refinement tests pass.
- **Manual `device_link` (3.2):** created from the UI on R:P → D; port
  uniqueness holds (a second manual or discovered link on P is
  rejected / handled as today); discovery of E on P → contradicted,
  ghost R – E. Operator sets the far port → manual `physical_link`, same
  id.
- **Confirmation (6.3):** manual `device_link` R:P → D, discovery of D on
  P → discovered `device_link`, same id. Discovery of D:Q on P →
  discovered `physical_link` P ↔ Q, same id. Manual `physical_link`
  R:P ↔ D:Q, discovery of D on P at device level only → stays manual, no
  badge.
- **Acknowledged manual link (6.3, v0.6):** manual `physical_link` and
  manual `device_link`, each with a `shadow_ack` entry, confirmed by
  discovery (device level and port level) → stays manual, `shadow_ack`
  unchanged, no conversion to `physical_link`.
- **Reasons (3.1):** every produced reason renders its explanation;
  `fdb_mac_only` accepted by validation, never produced; unknown reason
  rejected.
- **Contradiction by Device (6.1):** device-level R:P → D; R reports E on
  P, nothing else supports L → L superseded, R:P ↔ E created.
- **Manual (6.3):** manual R:P ↔ D:Q, device-level observation of D on P
  → not contradicted. Of E on P → contradicted, ghost R – E.
- **Port uniqueness:** at most one non-superseded link per local port,
  device-level ones included; device-level links occupy no far port.
- **Convergence / order independence:** as in replacement spec §8.
- **Browser:** device-level link ends at the Device border; bundle label;
  `far_port_hint` escaped.

## 10. Decisions (Dima, 2026-10-02)

1. **Storage shape:** separate edge type `device_link`; conversion to
   `physical_link` in place on refinement (§3, §5.1). Revisit if the §6.0
   list turns out longer in code than on paper.
2. **Precision only goes up (Dima, 2026-10-02):** a `device_link` becomes a
   `physical_link` when the far port is identified (§5.1); a `physical_link`
   never becomes a `device_link` (the one exception is the pseudo-port
   correction of §4.4). Lost precision is shown as a flag on the link, not
   as a type change (§5.2). Replaces the earlier decision to downgrade after
   the freshness threshold.
3. **Manual device-level links:** allowed (§3.2, §6.3).
4. **FDB:** reason `fdb_mac_only` reserved with its explanation, not
   produced until an FDB spec (§3.1).
5. **Manual confirmed by discovery (v0.5):** becomes discovered, both types
   (§6.3). Exception: a manual `physical_link` is not reduced to a
   discovered `device_link`. Since v0.6: a manual link with `shadow_ack`
   entries is never converted.

**Implemented:** c230a0298b4 (ingest), 095010c24de (API and view),
0f49adb9363 (tests), 4cbc6bcc876 (observation columns), 18a53cf7e3d (§4.4).
Still open, not blocking: whether the coding agent's §6.0 audit finds
`physical_link` filters that make the separate type too costly (→ back to
decision 1).

## 11. FR v1.1 lines (draft, Topology model)

The whole v1.1 change set agreed on 2026-10-02 after the Scanopy review.
Only 1.b, d.ii, 4.g, 4.i, 5.e and 5.f are implemented by this spec; 4.h
and 5.d need their own small specs.

```
1.b.i.1. CONNECTED_TO (physical link between ports)
1.b.i.3. CONNECTED_TO_DEVICE (physical link from a port to a device whose
         port can't be identified)

d.ii. topo_edges.type: physical_link / device_link / represented_by
      device_link: src = port, dst = device; attrs as physical_link plus
      the reason the remote port is unknown

4.g. If the remote device is identified but its port is not, a
     CONNECTED_TO_DEVICE link is created. It becomes CONNECTED_TO as soon
     as the remote port is identified. The reason the port is unknown is
     shown with the link.
4.h. An identifier (e.g. MAC address) that matches more than one device
     identifies none of them; the neighbor is not linked to any of them.
4.i. Ports of a device are created from neighbor advertisements only if the
     device is not itself a reporter.

5.d. A reporter whose neighbor data could not be collected keeps its last
     reported neighbors; links disappear only when collected data no
     longer contains them.
5.e. A link between a port and a device becomes a link between two ports
     as soon as the remote port is identified. A link between two ports is
     never reduced to a port-to-device link; if the remote port is no
     longer confirmed, the link is kept and marked as not confirmed since
     the last confirmation.
5.f. CONNECTED_TO_DEVICE links can be created manually; manual links of
     both kinds follow the same rules (manual wins, contradiction shown).
```

Before writing the 5.d spec: check what the Part 3 "SNMP walk to
topology rows" step returns on an SNMP timeout or a partial walk. An
error (item / LLD rule unsupported, no new value) already satisfies 5.d;
an empty array would be read as "no neighbors" and violate it.

Deferred, not in v1.1: reciprocal pairing (§1), FDB-based links (FR
decision 1), separate classification of unresolved neighbors (an unknown
far end already becomes a Device without REPRESENTED_BY, FR use case
1a; the only remaining case is covered by 4.h).
