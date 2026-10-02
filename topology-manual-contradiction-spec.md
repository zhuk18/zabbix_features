# Topology — manual link contradicted by discovery: build spec (v1.1)

**v1.1 (lab simulator S0, 2026-10-02):** a neighbor that reports a port of
the manual link as its own neighbor also contradicts it, even when it is not
an end of the link (third-party shadowing observation, §2). The hidden
neighbor of such an observation is the reporter, not `device_id`. While M
is contradicted, a superseded edge for the same port pair is not rendered
(§6.2). Ingest rules do not change.

## 0. Objective

Manual links win over discovery (model spec §3 rule 5), with no expiry.
Lab finding (Router1 / Printer1 / UPS1): after a recabling, a forgotten
manual link on Router1 Gi0/1 → UPS1 keeps hiding the live LLDP neighbor
Printer1. Printer1 becomes an isolated node with no badge; the loss is
visible only in `/topo/observations`.

A manual link that contradicts discovery can also be legitimate (an
unmanaged switch forwarding LLDP, a PC behind an IP phone, LLDP from
VMs). So this spec does **not** replace manual links automatically. It
makes the contradiction **visible** until the operator **resolves it
once**, per hidden neighbor:

> A manual link is *contradicted* while discovery shows a different
> neighbor on one of its ports and the operator hasn't acknowledged that
> neighbor. The operator either accepts discovery (manual link removed)
> or keeps the manual link (acknowledged for that neighbor).

References:
- `topology-prototype-spec.md` — model spec (§3 rule 5 manual links).
- `topology-lld-part2-spec.md` — `topo_observations` (§7), diagnostics (§8).
- `topology-link-replacement-spec.md` (v2.1) — superseded links, revival
  (§3.3), legacy cleanup manual + discovered (§6.5).

**Prerequisite:** v2.1 of the link replacement spec committed. This spec
builds on §6.5 (discovered link next to manual → superseded) and §3.3
(revival).

If a requirement not listed here seems necessary while implementing,
stop and flag it rather than silently expanding scope.

## 1. Scope

**In scope:**
- Derived state *contradicted* (§3)
- Acknowledgment storage on the manual link (§4)
- Operator actions: accept discovery, keep manual, revoke acknowledgment (§5)
- Rendering: badges, ghost link, details (§6)
- Diagnostics additions (§7)

**Out of scope:**
- Any automatic replacement or expiry of manual links. **Manual still
  wins**; ingest reconciliation rules don't change.
- Time comparison between manual links and observations. Manual links
  get no "confirmed at" timestamp used by any rule.
- Manual link confirmed by discovery with the same neighbor (redundant
  manual link).
- Manual link whose port or Device disappeared.
- Periodic ingest.

## 2. Terms

- **Shadowing observation** of manual link M: a `topo_observations` row
  with `outcome = shadowed` and `edge_id` = M. It comes either from an
  end of M (symmetric), or from a third party (below). For an observation
  from an end of M, its `device_id` is the **hidden neighbor**.
- **Third-party shadowing observation** of manual link M: an observation
  from reporter D, where D is not an end of M, and the remote port of the
  observation is an end port of M. It has `outcome = shadowed` and
  `edge_id` = M.
- For a third-party shadowing observation, the **hidden neighbor** is the
  reporter D, not `device_id` (`device_id` is an end of M).
- Observations mirror the **latest** snapshot of each rule (Part 2 §7),
  so "current" needs no clock comparison: a shadowing observation exists
  exactly while some latest snapshot shows the other neighbor.

## 3. State *contradicted* (derived, not stored)

Manual link M is **contradicted** if at least one shadowing observation
of M, including a third-party one, has a hidden neighbor (§2) that is
**not** in M's acknowledgments (§4).

Computed at read time from observations + `attrs.shadow_ack`. No new
column, no state written by ingest.

- A shadowing observation whose hidden neighbor is NULL can't be
  acknowledged by Device; treat it as unacknowledged (contradicted), no
  ghost (§6.2).
- If some latest NEIGHBORS snapshot also **contains** M (the far end
  confirms it, e.g. UPS1 reports Router1 Gi0/1), M is still
  contradicted; the details show "confirmed from <far end>" (§6.3).
  This is a persistent disagreement between two sides, and the operator
  still decides.

## 4. Acknowledgments

Stored on the manual link:

```
attrs.shadow_ack = [
  { "device_id": <hidden neighbor Device id>,
    "remote_key": "<remote_key of the observation at ack time>",   -- display / audit only
    "at": <unix time>,
    "by": "<Zabbix username>" }
]
```

- Keyed by **Device**, not by remote port and not by `remote_key`: the
  decision is "this device is legitimately behind this manual link".
  Device rows are never deleted (model spec §3 rule 3), so the id is
  stable. A different Device on the same port → contradicted again.
- An acknowledgment stays after its shadowing observation disappears (so
  an LLDP neighbor that flickers in and out doesn't re-raise the signal).
  It's removed only by revoke (§5.3) or together with the link.
- For a third-party shadowing observation (§2), `remote_key` records the
  natural key of the reporter's local port, in the same kind-prefixed form
  as every `remote_key` (`chassis:<type>:<value>`, `ip:<addr>`,
  `sysname:<name>`): `port:<reporter identity key>:<if_index>`, where the
  reporter identity key is the reporter Device's own key in that form (for
  example `port:chassis:-:00:11:22:33:44:12:1`). It is not a node id; the
  `if_index` is the last segment. Display and audit only, as for the other
  acknowledgments.
- Ingest never reads or writes `shadow_ack`. Ingest rewrites of the
  manual link's other attrs must preserve it — check this.

## 5. Operator actions

Available only on a **manual** link. Follow the existing routing and
permission conventions of the topology endpoints; changes require the
same permission as manual link editing today.

### 5.1 Accept discovery

Allowed only while M is contradicted.

1. Delete M (same code path as manual link removal today).
2. Discovery takes the port at the next **full** ingest from the stored
   snapshots, no new poll needed. Typically the discovered link R:P↔X
   already exists as **superseded** (legacy cleanup §6.5 or earlier
   history) and is **revived** (§3.3), keeping its id; otherwise it's
   created.
3. If the prototype has an existing way to trigger a full ingest from
   the frontend, run it after the delete and return its summary. If
   ingest is CLI-only, don't add a trigger: return "applies at next
   ingest" and flag it in the report.

If several hidden neighbors shadow M (both ends, or LLDP and CDP
disagree), accepting removes M; the resulting links are decided by the
normal rules (replacement spec §3, §5 — possibly `ambiguous`).

### 5.2 Keep manual

Per hidden neighbor: append `{device_id, remote_key, at, by}` to
`shadow_ack` of M. Idempotent (same `device_id` twice → one entry, keep
the first). M stops being contradicted when all its current hidden
neighbors are acknowledged.

### 5.3 Revoke acknowledgment

Remove one entry from `shadow_ack` by `device_id`. If that neighbor still
shadows M, M is contradicted again immediately.

## 6. Rendering

### 6.1 Badges (only while contradicted)

- **On M:** warning badge. Tooltip: "Discovery sees <neighbor> on
  <local port> (since <first_seen>)", one line per unacknowledged hidden
  neighbor.
- **On each unacknowledged hidden neighbor node**, whether it has other
  links or not: badge with tooltip "Link hidden by manual link <A port>
  ↔ <B> on <reporter>". For a third-party observation the badge goes on D
  (the reporter), not on the end of M that D reports.
- Style distinct from stale, superseded and conflict indicators.

### 6.2 Ghost link (only while unacknowledged)

- For each unacknowledged shadowing observation with `device_id` set:
  a dashed ghost line from the Device of `local_port_id` to `device_id`.
  Label/tooltip from `remote_attrs.rem_port` / `rem_port_desc` (escaped,
  model spec §9). For a third-party observation the ghost goes from D's
  local port to the end Device of M (`device_id`).
- Several observations for the same Device pair (LLDP + CDP, both ends)
  → one ghost.
- Not a `topo_edges` row, not counted for port uniqueness, not
  selectable as a link; clicking it opens M's details.
- While M is contradicted, a superseded edge for the same port pair is not
  rendered; only the ghost is drawn. When the hidden neighbor is
  acknowledged, the ghost is removed and the superseded edge renders as
  usual.
- Acknowledged → no ghost.

### 6.3 Details of M

- `Manual link, created <date> by <user>` if that data already exists;
  don't add columns for it.
- Unacknowledged hidden neighbors, with actions *Accept discovery* /
  *Keep manual* (per neighbor).
- Acknowledged neighbors: `<neighbor> — kept by <by> on <at>`, whether
  currently shadowing or not, with *Revoke*.
- "Confirmed from <far end>" when some latest snapshot contains M.

### 6.4 Details of a hidden neighbor node

Lists manual links hiding it, acknowledged ones included (info, no
badge), so an acknowledged isolated node still explains itself.

## 7. Diagnostics

- `GET /topo/ingest/status`: add `manual_links_contradicted` (count at
  end of run, computed as in §3).
- `GET /topo/observations`: add filter `contradicted=1` (shadowed and
  not acknowledged); each `shadowed` row gets `acknowledged: true|false`,
  decided by its hidden neighbor (§2).

## 8. Acceptance criteria

Snapshots written directly into `topo_lld_snapshot` with fixed clocks;
ingest runs as a real process.

- **Lab case:** manual Router1 Gi0/1 ↔ UPS1, Router1 LLDP shows Printer1
  on Gi0/1 → manual link stays; Printer1 observation `shadowed`; M
  contradicted; badge on M and Printer1; ghost Router1 – Printer1.
- **Keep manual:** ack Printer1 → no badges, no ghost; Printer1 details
  list the acknowledged hidden link; 5 ingest runs change nothing.
- **Different neighbor after ack:** snapshot shows Printer2 on Gi0/1 →
  contradicted again (Printer2 only); Printer1's ack remains in details.
- **Neighbor flicker:** Printer1 disappears from the snapshot and comes
  back → stays acknowledged, no badge.
- **Revoke:** revoke Printer1 while it still shadows → badge and ghost
  back immediately.
- **Accept discovery:** from the lab case → M deleted; after a full
  ingest Router1 Gi0/1 ↔ Printer1 is active, Printer1 `applied`. If a
  superseded Router1–Printer1 link existed, it's the **same edge id**
  (revived), not a new edge. Port uniqueness holds.
- **Far end confirms M:** UPS1's snapshot reports Router1 Gi0/1 →
  still contradicted, details show "confirmed from UPS1".
- **UPS1 without LLDP (third party, main case):** lab deviation
  `omit_mib: lldp` on UPS1. M = Router1:Gi0/1 ↔ UPS1:eth0; Router1 LLDP
  shows Printer1 on Gi0/1; Switch2 LLDP shows UPS1:eth0. → Two shadowing
  observations with `edge_id` = M (Printer1 near-end, Switch2 third-party).
  Hidden neighbors: Printer1 and Switch2. Badges on M, Printer1, Switch2.
  **No badge on UPS1.** Two ghosts: Router1–Printer1 and Switch2–UPS1; the
  superseded edges of the same two port pairs are not rendered.
- **UPS1 with LLDP (current lab):** three shadowing observations. Hidden
  neighbors: Printer1 and Switch2 (Switch2 from two observations → one
  hidden neighbor, one badge, one ghost).
- **Keep manual for Switch2 only:** M stays contradicted (Printer1 not
  acknowledged); badge on Switch2 gone; ghost Switch2–UPS1 gone, and the
  superseded Switch2–UPS1 edge renders as usual.
- **Accept discovery with a third party:** M deleted. After a full
  ingest, Router1–Printer1 and Switch2–UPS1 are active, with the same
  edge ids they had before M was created.
- **Lab simulator:** S0 step 1 assertions updated; new scenario step for
  the UPS1-without-LLDP case.
- **Shadow from the far end:** manual R:P ↔ B:Q, B's snapshot shows D on
  Q → contradicted, badge on D, ghost B – D.
- **Hidden neighbor with other links:** Printer1 also has an active link
  elsewhere → badge on Printer1 still shown while unacknowledged.
- **Ingest preserves acks:** ingest runs that touch M's other attrs keep
  `shadow_ack` byte-identical.
- **No automatic change:** no ingest run ever deletes, supersedes or
  modifies a manual link or its `shadow_ack`.
- **Browser:** badges, ghost and details render; untrusted `remote_attrs`
  escaped.

## 9. FR lines (for Dima, Topology model → Lifecycle)

```
f. A port with a manual link reports a different neighbor:
   i. the manual link stays; the discovered neighbor is recorded as shadowed;
   ii. the manual link and the hidden neighbor are marked as contradicted
       by discovery until the operator resolves it;
   iii. accept discovery: the manual link is removed and the discovered
        link is created from the latest snapshots;
   iv. keep manual: the contradiction is acknowledged for this neighbor;
       a different neighbor marks the link as contradicted again;
   v. a neighbor that reports a port of the manual link as its own
      neighbor also contradicts the manual link, even if it is not an end
      of the link.
```

Decisions made:

```
Manual links are never replaced automatically; contradiction by
discovery is shown to the operator and resolved per neighbor, not by time.
```
