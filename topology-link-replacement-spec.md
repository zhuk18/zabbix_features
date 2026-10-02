# Topology — discovered-vs-discovered link replacement: build spec (v2.1)

## Changes

**v2.1 (after `012d1f544de`):**
1. Competing new claims on a port without an active link → `ambiguous`,
   not `conflict` (§5.2).
2. Legacy cleanup also covers a discovered link sharing a port with a
   manual link (§6.5).

**v2 (implemented in `012d1f544de`), vs v1 (`5273f5b387c`, `fc72e919d58`):**
1. Time comparison removed from the rule (§3). v1 kept the old link only
   if some support was at least as recent as the contradiction; with
   persistent disagreement and staggered polls this flapped. v2: the old
   link keeps its port while any other NEIGHBORS snapshot **still contains
   it**, regardless of clocks.
2. Far-end port occupancy (§3.2) and partial runs never replacing (§6.3)
   made part of the spec.
3. Tests for persistent disagreement and legacy double links (§8).

## 0. Objective

A port has at most one **active** `physical_link` (model spec §2.3). Before
this spec, when a port reported a different neighbor than its existing
discovered link (cable moved: P↔B, now P↔C), the old link kept the port
until it went stale (7 days), and the new observation was recorded as
`conflict` (Part 2 §6.7). A real recabling stayed invisible for a week.

This spec replaces Part 2 §6.7 with a rule based on **the latest
snapshots**, not on the freshness threshold:

> A discovered link keeps its port while at least one other NEIGHBORS
> snapshot still contains it. When nothing but the past supports it, the
> new neighbor replaces it.

References:
- `topology-prototype-spec.md` — model spec (§2.3 uniqueness and
  canonicalization, §3 rule 5 manual links and disappearance).
- `topology-lld-part2-spec.md` — snapshots, ingest, `topo_observations`
  (§6.7 is superseded by this spec).

If a requirement not listed here seems necessary while implementing,
stop and flag it rather than silently expanding scope.

## 1. Scope

**In scope:**
- Replacement rule for discovered links (§3)
- Link state `superseded` (§4)
- Several neighbors or several claims on one port (§5)
- Two-phase ingest, order independence, partial runs, legacy cleanup (§6)
- Rendering and diagnostics (§7)

**Out of scope:**
- Manual links: **manual still wins**, unchanged (model spec §3 rule 5).
  Timestamped manual links are a separate decision.
- Observation deletion policy (`missing_since`) — separate decision.
- Shared-segment modelling (hub / phone+PC / hypervisor as a first-class
  object) — §5 only makes behaviour deterministic.

## 2. Terms

- **Contradiction** of link L on port P: the **latest** snapshot of some
  NEIGHBORS rule shows on P a neighbor that resolves to a different
  remote port (or different Device) than L's other end. The snapshot's
  `clock` is the contradiction time T (used only as `superseded_at`,
  never for comparison).
- **Support** of L: the **latest** snapshot of any NEIGHBORS rule
  **other than the contradicting rule** that contains L (from either
  end). This includes another NEIGHBORS rule of the contradicting host
  (e.g. its CDP rule when LLDP contradicts).
- Clocks of supporting snapshots are **not** compared with T. A
  reporter's latest snapshot is its current claim, however long ago it
  was polled.

Both are read from `topo_lld_snapshot` rows (Part 2), never from the order
in which ingest processes reporters.

## 3. Rule

### 3.1 Replacement on the reporting port

Existing discovered link L = R:P↔B:Q. The latest snapshot of a NEIGHBORS
rule shows on P (or on Q) a neighbor X ≠ the other end of L.

| Support of L | Result |
|---|---|
| none | L **superseded** at T (§4); new link P↔X created, X observation `applied` |
| at least one | L stays; X observation `conflict`, `edge_id` = L |

- Symmetric: the contradiction may come from either end of L.
- **Expected transient.** Right after a recable, the far end's latest
  snapshot (taken before the recable, or within its LLDP TTL) still
  contains L → `conflict` until the far end's next poll, then
  replacement. Delay ≤ one NEIGHBORS interval of the far end.
- **Persistent disagreement** (two sides keep reporting different
  things): stable `conflict`, no replacement, no flapping, however the
  polls interleave.
- **Manual L:** model spec §3 rule 5 unchanged (manual wins, X observation
  → `shadowed`). Never superseded by this spec.

### 3.2 Far-end port occupancy

The candidate P↔X also contradicts any active discovered link M that
already uses **X's** port (otherwise X's port would get a second active
link). M is evaluated by the same rule: superseded if no NEIGHBORS
snapshot other than the contradicting one contains M; otherwise the
candidate is blocked and the X observation is `conflict` with
`edge_id` = M. If both L and M exist, the candidate is applied only if
both can be superseded.

### 3.3 Revival

A superseded link L that appears again in some latest snapshot is a
candidate like any other: it contradicts whatever now holds its ports,
and §3.1/§3.2 decide. If the current holder has support → `conflict`,
L stays superseded. If not → the holder is superseded and L is revived
(`superseded_at` cleared). No flapping: a link is only ever replaced
when **nothing** currently reports it.

## 4. `superseded` state

- Stored on the link as `attrs.superseded_at` = T (no state column in
  this branch).
- A superseded link is **not active**: ignored by the port-uniqueness
  check and treated as stale regardless of `last_seen_*`.
- **Not deleted** (same principle as a disappeared link, FR Lifecycle
  5b). Manual removal works as for any link.
- `last_seen_src` / `last_seen_dst` stay frozen.

## 5. Several neighbors or claims on one port

LLDP legitimately shows several neighbors on one port (unmanaged switch,
IP phone with a PC behind it, hypervisor with LLDP-speaking VMs). The
same physical situation can also be seen from the other side: several
devices each report the same port as their neighbor. None of this is a
sequence, so §3 must not pick a winner by order.

### 5.1 Several neighbors in one snapshot

For a port P with ≥2 neighbors resolving to different remote ports in the
same snapshot:
- none of them replaces another, and §3 isn't evaluated among them;
- if the existing active link on P is one of them, it stays (`applied`);
  the others get `ambiguous`;
- otherwise no link is created or replaced on P; all get `ambiguous`.

### 5.2 Competing new claims from different rules

A port P has **no active link**, and ≥2 candidates for P come from
**different** NEIGHBORS rules, each still contained in its rule's latest
snapshot, resolving to different far ends (e.g. X and Y both report R:P
as their neighbor, R is not a reporter; or R's LLDP shows X on P while
R's CDP shows Y):
- no link is created on P;
- all these candidates get `ambiguous` (not `conflict`: nothing is
  blocking them, the port simply has several claimants).

If P **has** an active link, §3 applies to each candidate against it as
usual.

### 5.3 Common

- `ambiguous` observations carry no `edge_id`.
- §5 takes precedence over §3 for that port.

## 6. Ingest

### 6.1 Two phases

1. **Collect.** Process every reporter's snapshots up to observation
   building: resolve Devices, ports, candidate links. Don't write links
   or observations yet.
2. **Resolve.** Legacy cleanup (§6.5), then per port §5, then §3 against
   existing links, with support computed from all latest NEIGHBORS
   snapshots. Then write links, `superseded_at`, `last_seen_*` (Part 2
   §6.6) and observations.

Existing invariants stay: canonicalized `src_id`/`dst_id`, `last_seen_*`
only from `applied` observations and snapshot clocks.

### 6.2 Order independence

The result must not depend on reporter order. Node ids may differ
between orders (creation order); comparison is by natural keys.

### 6.3 Partial runs

A run restricted to some reporters (`--zabbix-host`) can't know that
nothing supports a link, so it **never supersedes** and skips legacy
cleanup: contradictions are recorded as `conflict`, as before this spec.

### 6.4 Known limitation

With three or more competing claims on the same ports where some ports
already hold links, a losing claim can still block a weaker one, so a
chain may stay blocked. Stable, no flapping; not optimal. Open question.

### 6.5 Legacy cleanup

Data written before this spec can violate port uniqueness. In every full
run, in phase 2 before any decision, independent of candidates:

- **Two or more active discovered links on one port:** keep the one some
  latest snapshot still contains; if none or several are, keep the one
  with the newest `last_seen`. The others become superseded with
  `superseded_at` = max(their `last_seen`, the latest clock confirming
  them). *(Implemented in `012d1f544de`.)*
- **An active discovered link sharing a port with a manual link:** manual
  wins (model spec §3 rule 5), so the discovered link becomes superseded,
  `superseded_at` as above. Its observation, if the neighbor is still
  reported, is `shadowed` as usual. *(New in v2.1.)*

A second run over the same data changes nothing.

## 7. Rendering and diagnostics

- Superseded links render like stale links (faded). Tooltip / details:
  "Replaced on <date>" and, when known, what replaced it. Hiding them by
  default is not part of this spec.
- A `conflict` younger than the far end's NEIGHBORS interval is the
  expected transient; don't escalate it. Observations expose `age`.
- `/topo/ingest/status` per-run counts: `links_superseded`,
  `links_revived`, `ports_ambiguous`.

## 8. Acceptance criteria

Snapshots are written directly into `topo_lld_snapshot` with fixed
clocks; ingest runs as a real process.

- **Recable, far end not a reporter:** R:P↔B; R's new snapshot shows C on
  P → L superseded, `superseded_at` = that clock; P↔C active; C
  `applied`.
- **Recable, both ends reporters:** L = R:P↔B:Q. R's snapshot shows C on
  P; B's latest still shows R:P on Q — with B's clock older, equal or
  newer than R's → in all three cases L stays, C `conflict`. B's next
  snapshot without R:P → replacement.
- **Persistent disagreement, staggered polls:** R keeps reporting X on P,
  X keeps reporting UPS2 on q; 6 alternating new snapshots with
  increasing clocks, ingest after each → `links_superseded` and
  `links_revived` = 0 on runs 2–6.
- **Contradiction from the far end:** B's snapshot shows D on Q, R's
  latest no longer shows B on P → L superseded, Q↔D created. R's latest
  still shows B on P → `conflict`.
- **Far-end occupancy:** candidate P↔X where X's port holds link M:
  M supported → candidate blocked, `conflict` with `edge_id` = M;
  M unsupported → M superseded, P↔X created.
- **LLDP vs CDP on one reporter,** existing link L on P, LLDP shows C →
  `conflict`, L stays.
- **Revival:** after replacement, B reports R:P again while R still
  reports C on P → `conflict`, nothing changes. After R also stops
  reporting C → L revived, P↔C superseded.
- **Manual link:** manual R:P↔B, LLDP shows C on P → manual stays, C
  `shadowed`.
- **Two neighbors on P in one snapshot:** existing link among them →
  stays, the other `ambiguous`; none among them → no change on P, both
  `ambiguous`.
- **Competing new claims (§5.2):** no link on R:P; X's and Y's latest
  snapshots both report R:P → no link on R:P, both observations
  `ambiguous` with no `edge_id`. Same for R's LLDP showing X and R's CDP
  showing Y on a port without a link. When Y stops reporting R:P →
  R:P↔X created, `applied`.
- **Partial run** with `--zabbix-host`: an unsupported contradiction →
  `conflict`, nothing superseded, no legacy cleanup.
- **Order independence:** two reporter orders → identical links,
  `superseded_at` and observations by natural keys.
- **Convergence:** 5 runs over unchanged snapshots change nothing.
- **Port uniqueness:** at most one non-superseded link per port after
  every full run, counting manual links.
- **Legacy data:** (a) two active discovered links on one port, one of
  them in a latest snapshot → that one stays; (b) same, none in any
  snapshot → newest `last_seen` stays; (c) manual + active discovered on
  one port → discovered superseded, manual untouched. In all cases, even
  without a new candidate for that port; a second run changes nothing.
- **Browser:** superseded link renders faded with "Replaced on …" in its
  tooltip.
