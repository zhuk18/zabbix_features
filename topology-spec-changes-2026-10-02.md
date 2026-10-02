# Topology — spec changes from lab simulator run (2026-10-02)

Three approved changes. Apply each to the named spec, raise its version,
and show the diff. Do not implement code until the diffs are approved.

If a change conflicts with text already in the target spec, stop and
list the conflict. Do not resolve it yourself.

---

## C1. Manual link contradicted by a third-party observation

**Target:** `topology-manual-contradiction-spec.md` (v1 → v1.1).

### Finding

Lab simulator S0 step 1: manual link M = Router1:Gi0/1 ↔ UPS1:eth0.
Switch2's LLDP shows UPS1:eth0 on Switch2:Gi0/1. Switch2 is not an end
of M.

Ingest already handles this case correctly at the data level:
- Switch2:Gi0/1 → UPS1 is `shadowed` by M (third-party).
- UPS1:eth0 → Switch2 is `shadowed` by M (near-end, from UPS1's LLDP).
- Router1:Gi0/1 → Printer1 is `shadowed` by M (near-end).
- `manual_links_contradicted` = 1.

The gap is in the definition of the **hidden neighbor**. §2 defines it
as the observation's `device_id`. For the third-party observation,
`device_id` = UPS1, which is an **end of M**. So:
- the badge goes on UPS1 instead of Switch2;
- "Keep manual" acknowledges UPS1, which is meaningless.

In the lab this is masked, because UPS1 has LLDP and its own near-end
observation names Switch2 correctly. A far end without LLDP (a typical
UPS, printer or camera) has only the third-party observation.

### Change

Ingest rules do not change. Only the hidden neighbor definition and
everything that uses it.

Add to §2 (Terms):

- **Third-party shadowing observation** of manual link M: an observation
  from reporter D, where D is not an end of M, and the remote port of
  the observation is an end port of M. It has `outcome = shadowed` and
  `edge_id` = M.
- For a third-party shadowing observation, the **hidden neighbor** is
  the reporter D, not `device_id`.

Change §3 (contradicted): "at least one shadowing observation" includes
third-party shadowing observations. Acknowledgment (§4, §5.2) uses the
hidden neighbor as defined above.

Add to §6.2 (ghost link): for a third-party shadowing observation, the
ghost goes from D's local port to the end Device of M. If a superseded
edge already exists for the same port pair, render the ghost on that
edge. Do not draw a second line.

Add to §6.1 (badges): the badge goes on M and on D.

§5.1 (accept discovery) does not change: M is removed, then normal rules
apply. A superseded edge for the same port pair is revived (same edge
id), per replacement spec §3.3.

### Acceptance criteria (add to §8)

- **UPS1 without LLDP (main case):** lab deviation `omit_mib: lldp` on
  UPS1. M = Router1:Gi0/1 ↔ UPS1:eth0; Router1 LLDP shows Printer1 on
  Gi0/1; Switch2 LLDP shows UPS1:eth0. → Two shadowing observations with
  `edge_id` = M (Printer1 near-end, Switch2 third-party). Hidden
  neighbors: Printer1 and Switch2. Badges on M, Printer1, Switch2.
  **No badge on UPS1.** Two ghosts: Router1–Printer1, Switch2–UPS1,
  each drawn on the superseded edge of the same port pair.
- **UPS1 with LLDP (current lab):** three shadowing observations. Hidden
  neighbors: Printer1 and Switch2 (Switch2 from two observations → one
  hidden neighbor, one badge, one ghost).
- **Keep manual for Switch2 only:** M stays contradicted (Printer1 not
  acknowledged); badge on Switch2 gone; ghost Switch2–UPS1 gone.
- **Accept discovery:** M deleted. After a full ingest, Router1–Printer1
  (344) and Switch2–UPS1 (346) are active, with the same edge ids.
- Lab simulator: S0 step 1 assertions updated; new scenario step for
  the UPS1-without-LLDP case.

### FR line (Topology model → Lifecycle, item f)

```
v. a neighbor that reports a port of the manual link as its own
   neighbor also contradicts the manual link, even if it is not an end
   of the link.
```

---

## C2. Configurable link freshness threshold

**Target:** new build spec `topology-freshness-config-spec.md` (v1).

**Priority:** not blocking. After C3, no conversion depends on this
threshold. It remains a cleanup and lets the lab test link staleness
(FR Lifecycle 5a).

### Finding

The freshness threshold is a hard-coded 7 days, in two places:
`TOPO_LINK_FRESH_SECONDS` (`ingest.php`) and `STALE_LINK_SECONDS`
(`CTopologyPrototype`). The lab simulator cannot test staleness.
Two definitions of one value can drift apart.

### Requirements

1. First, check whether the two constants mean the same thing. Report
   where each one is used. If they mean different things, stop and
   report; do not merge them.
2. Define the value in **one** place. Ingest (CLI) and the UI must read
   the same value.
3. The value comes from one setting that both ingest and the UI can
   read, with no web server restart. Propose the mechanism before you
   build it. No new DB tables.
4. Default: 604800 (7 days). Minimum: 60. A value below the minimum or
   not an integer → use the default and log a warning.
5. The lab runner can set the value for a scenario and restores it
   after the run, also when the run fails.

### Acceptance criteria

- No numeric literal for this threshold remains in topology code.
- With no setting, behavior is unchanged (all existing suites pass).
- A lab scenario with a short threshold: a link with no evidence becomes
  stale; the runner restores the original value.
- An invalid setting → default used, warning logged.

---

## C3. Link precision only goes up: remove port-level → device-level

**Target:** `topology-device-level-edge-spec.md` (v0.7 → v0.8).

### Finding

Lab simulator S3 step 4: Switch1:Gi0/7 ↔ AP2 is port-level. Then a
second AP2 port advertises the same port id. The spec does not say what
happens. Today the link stays `physical_link`.

Review of the spec: the downgrade path (§5.2, reason `port_lost`) causes
much of the complexity: `last_seen_port`, a time threshold, a second
conversion direction with its manual-link exceptions, and a lab step that
needs time control. A type change gives the operator no information that
a flag on the link can't give.

### Decision (Dima, 2026-10-02)

**A link's precision only goes up.** A `device_link` becomes a
`physical_link` when the far port is identified (§5.1, unchanged). A
`physical_link` never becomes a `device_link`.

### Change

1. **Remove §5.2** (port-level → device-level after the freshness
   threshold) completely.
2. **Replace it with a short §5.2 "Lost precision":**
   - A discovered `physical_link` whose far port is no longer identified
     in the latest snapshots, while the far Device is still seen on the
     local port, stays `physical_link` and active. Observation outcome
     `applied`, with `precision_lower: true` (as today before the
     threshold).
   - This applies for every cause: port not matched, shared port id,
     LAG ambiguity.
   - `last_seen_*` is updated. `last_seen_port` is not.
   - The link shows a flag with the text: "The remote port <Q> hasn't
     been confirmed since <last_seen_port>; <D> is still seen on this
     port."
   - If the far Device is no longer seen either, the link goes stale by
     the normal rule.
3. **Remove reason `port_lost`** from §3.1 (table and enum). Before you
   change the enum, count `device_link` rows with `port_lost` in the
   `zabbix` DB. If the count is not 0, stop and report. Do not convert
   or delete them yourself.
4. **Keep `last_seen_port`.** It is now used for display only (the flag
   text above), not for any rule.
5. **§10 decision 2** ("Lost precision"): replace with the decision
   above.
6. **§6.0 and §6.3:** remove every rule, exception or line that exists
   only because of the downgrade path. List each removal in the diff
   summary. Do not remove anything that §5.1 or §4 still needs.
7. **§11 FR lines:** list every line that describes the downgrade.
   Replace it with the FR line below.

### Acceptance criteria

- S3 step 4: Switch1:Gi0/7 ↔ AP2 stays `physical_link`, same edge id as
  at step 3; the observation has `precision_lower: true`.
- New S3 step 5: shared id removed → `physical_link` unchanged (same
  edge id), `precision_lower` false, `last_seen_port` advanced.
- A `physical_link` whose far Device disappears from all snapshots goes
  stale by the normal rule and never becomes `device_link`.
- `device_link` → `physical_link` refinement (§5.1) works as before
  (existing tests pass).
- No code path writes `far_port_reason = port_lost`.

### Lab simulator changes

- Remove S3 step 2 (it tested the downgrade).
- S3 step 4: change the assertion as above. Remove `pending`.
- Add S3 step 5 as above.

### FR line

```
A link between a port and a device becomes a link between two ports
as soon as the remote port is identified. A link between two ports is
never reduced to a port-to-device link; if the remote port is no longer
confirmed, the link is kept and marked as not confirmed since the last
confirmation.
```
