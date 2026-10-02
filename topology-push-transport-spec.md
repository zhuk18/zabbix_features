# Topology — push transport through trapper discovery rules: build spec (v0.2)

Status: **draft for review by Dima.**

**v0.2 (self-review):**
1. Heartbeat key `topology.push.heartbeat`: the old key would collide
   with the legacy template on a host in transition (§4).
2. Snapshot clock never goes back: a late value is ignored (§6.1).
3. Ingest normalization runs before reporter identity, and defines what
   to do without a chassis type or with an undecodable value (§5.1).
4. Duplicate-transport check compares role **and source**, so LLDP by
   SNMP + CDP by push is not flagged; reason corrected (§4, §5.2).
5. `push.py` must treat a truncated walk as a failure (§2 rule 3).
6. Equivalence covers only what both transports collect (§7).
7. `--legacy-blob` output stays while the legacy loop stays (§3.4).

## 0. Objective

Keep two transports for topology data and one reconciliation:

```
LLD (SNMP walk + native step)  ──┐
                                 ├──► topo_lld_snapshot ──► ingest.php (one path)
push.py ──► trapper discovery    ┘
            rules with a role
```

Reconciliation (link replacement v2.1, observations, manual
contradiction, device-level links, pseudo-port cleanup) works only on the
snapshot path. The legacy blob loop has none of it (path audit,
2026-10-02). This spec does **not** extend the legacy loop. It moves the
push collector onto the snapshot path.

**No new server code and no adapter are needed.** Part 1 already allows
`topology_role` on a discovery rule of any item type, including Zabbix
trapper (role spec §3.3). Part 2 writes a snapshot for any rule with a
role after successful LLD processing (Part 2 §4). So a trapper discovery
rule with a role, filled by `zabbix_sender`, produces the same snapshot as
an SNMP-walk rule. The work is in `push.py`, a template and ingest-side
checks.

References:
- `topology-lld-role-spec.md` — Part 1: roles, macro contract (§3.2).
- `topology-lld-part2-spec.md` — Part 2: snapshots (§2–§4), reporter
  identity (§5.2), transition rule (§5.1).
- `topology-lld-part3-spec.md` — Part 3: normalization (§4.2), golden
  fixtures (§6).
- `topology-prototype-spec.md` — §4.1 push component, heartbeat.

If a requirement not listed here seems necessary while implementing,
stop and flag it rather than silently expanding scope.

## 1. Scope

**In scope:**
- `push.py` emits contract rows per role and sends them to trapper
  discovery rules (§3)
- Template "Topology by push" (§4)
- Identity normalization in ingest for every snapshot (§5)
- Server checks: snapshot clock and value size for trapper rules (§6)
- Equivalence tests: LLD path vs push path (§7)

**Out of scope:**
- Any change to the legacy blob loop. It stays as it is for hosts that
  only have the old `topology.discovery.raw` item (Dima, 2026-10-02).
  Part 2 §5.1 already makes a host with snapshots use only the snapshots.
- LEARNED_MACS and LAG from `push.py` if it doesn't collect them today
  (prototype spec §4.1 known gap). Add them only if they exist.
- Removing LLD or the native step. Both transports stay.

## 2. Design rules

1. **One contract.** Push rows use the same macro names as LLD rows
   (role spec §3.2). The server maps them to the same normalized fields
   (Part 2 §4.3). No second format.
2. **Same rule granularity as the LLD template.** One trapper discovery
   rule per role, and separate rules for LLDP and CDP neighbors (as in
   Part 3 §7). Rule identity is what "support from another NEIGHBORS
   rule" (replacement spec §2) counts; LLDP and CDP in one rule could not
   support each other.
3. **Failed ≠ empty (FR 5.d).** `push.py` sends `[]` only when the source
   table was read and has no rows. When the walk fails, times out or is
   partial, it sends **nothing** for that role. The last snapshot then
   stays, as on the LLD path. "Partial" includes the silent cases found
   in the 5.d check (`topology-walk-failure-check.md`): a non-increasing
   OID and an early `endOfMibView` are failures in `push.py`, not ends of
   the table.
4. **Identity strings must match byte for byte across transports.** The
   same device seen through LLD and through push must resolve to one
   Device. `push.py` normalizes (§3.3), and ingest normalizes again (§5),
   so a collector bug can't create duplicate Devices.

## 3. `push.py`

### 3.1 Output

Per reporter, per role: one JSON array of rows. Each row is an object
with contract macro names as keys (`{"{#IFINDEX}":"3", …}`), values as
strings, absent optional values omitted. Same rules as the native step
(Part 3 §2 rule 2).

| Trapper rule key | Role | Rows |
|---|---|---|
| `topology.push.ports` | PORTS | one per port |
| `topology.push.lldp` | NEIGHBORS | one per LLDP neighbor, `{#SOURCE}` = `lldp` |
| `topology.push.cdp` | NEIGHBORS | one per CDP neighbor, `{#SOURCE}` = `cdp` (only if push.py reads CDP) |
| `topology.push.fdb` | LEARNED_MACS | only if push.py reads FDB |
| `topology.push.lag` | LAG | only if push.py reads LAG |

Every row carries `{#LOC_CHASSIS}` when the device reports it (Part 2
§5.2 reporter identity).

### 3.2 Sending

- One `zabbix_sender` call per reporter with all its role values, each
  with the **collection timestamp** (`-T`, the time the walk finished).
- A role whose collection failed is left out of the call (§2 rule 3).
- Heartbeat item `topology.push.heartbeat` (§4): sent on every run, even
  when every role failed.

### 3.3 Normalization

Apply the normalization table of Part 3 §4.2 to every identity field
(`{#LOC_CHASSIS}`, `{#REM_CHASSIS}`, `{#REM_CHASSIS_TYPE}`,
`{#REM_MGMT_IP}`, `{#IFMAC}`, `{#MAC}`). Output strings must equal what
the native step emits for the same walk. Port the logic from the Part 2
JS / Part 3 C, don't write a new variant.

### 3.4 Mapping from today's blob

The current blob fields (`ports[]`, `neighbors[]`, `reporter`) map to
the contract. List the mapping in the code. Notable points:
- `neighbors[].local_if_index` → `{#IFINDEX}`.
- `remote_port_id_subtype` → `{#REM_PORT_TYPE}` with the same strings as
  the native step.
- `reporter.chassis_id` → `{#LOC_CHASSIS}` on every row.
- `stats` has no place in the contract. Drop it; contract checks and the
  ingest summary replace it.

Keep the old blob output behind a flag (`--legacy-blob`) while the
legacy loop stays (§8).

## 4. Template "Topology by push"

- Discovery rules of type **Zabbix trapper**, keys as in §3.1, each with
  its `topology_role`. No preprocessing, no LLD macro paths (rows already
  have macro keys), no discard steps (Part 2 §4.4).
- No item prototypes.
- Trapper item `topology.push.heartbeat` + a `nodata()` trigger
  (interval: 2 × push interval). Not `topology.discovery.heartbeat`: the
  legacy template defines that key, and a host in transition has both
  templates linked, so the link would fail on a duplicate key.
- `Allowed hosts` on the trapper rules: leave empty in the template;
  document it as the place to restrict senders.
- Don't collect the same data twice on one host: same role and, for
  NEIGHBORS, same source (e.g. LLDP by SNMP **and** LLDP by push). It
  isn't wrong for reconciliation (two rules are handled like LLDP + CDP
  today), but it doubles the load and makes freshness and diagnostics
  hard to read. Mixing is fine: LLDP by SNMP + CDP by push. Not enforced
  by API; ingest reports it (§5.2).

## 5. Ingest

### 5.1 Normalization (all snapshots)

Normalize the identity fields of every snapshot row with one PHP
function that implements Part 3 §4.2. It applies to snapshots from both
transports. It runs **first**, before reporter identity (Part 2 §5.2):
there, two spellings of one `loc_chassis` would count as two values and
skip the whole reporter as `identity_ambiguous`.

For rows from the native step it must be a no-op (output already
normal). Test this: every golden fixture output of Part 3 passes through
it unchanged.

Rules:
- Fields with a defined format: `rem_chassis` when its type is `mac`,
  `mac`, `if_mac` (MAC); `rem_mgmt_ip` (IP); `loc_chassis` when it looks
  like a MAC. A value that doesn't fit the format → row skipped, counted
  in `rows_identity_invalid` per rule. Don't guess.
- `rem_chassis` without `rem_chassis_type`, or with another type
  (`local`, `ifname`, …): trim only, keep as received.
- A `netaddr` value the native step can't decode either: keep as
  received (same as the native step today), don't skip.

### 5.2 Duplicate transports

If one host has snapshots for the same role from rules of different
item types (SNMP / trapper) — and for NEIGHBORS, with the same `source`
in their rows — report it in the ingest summary
(`hosts_duplicate_transport`: host, role, source). Process both; don't
pick one.

### 5.3 No other change

Reporter discovery, identity, processing order, reconciliation and
observations are unchanged (Part 2 §5–§7). `observations.itemid` is the
trapper rule's itemid.

## 6. Server checks (report first, change only if needed)

Check and report, with file:line. Change code only for the case marked
**required**.

1. **Snapshot clock.** For a trapper discovery rule, does
   `topo_lld_snapshot.clock` come from the value timestamp sent with
   `-T`, or from processing time? **Required:** it must be the value
   timestamp (Part 2 §2 rule 3: observation time, not processing time).
   If it is processing time, change it for all rule types (for SNMP
   rules the two are nearly equal).
   **Required:** the clock never goes back. A value with a timestamp older
   than the stored snapshot `clock` (late value, e.g. from a proxy
   buffer) is ignored for the snapshot. Otherwise the same-hash branch
   (Part 2 §4.4) would move `clock` back and `last_seen` with it.
2. **Value size.** The maximum size of a value a trapper discovery rule
   accepts, server and proxy. Compare with the largest lab FDB array.
3. **Proxy.** A trapper discovery rule on a proxy-monitored host: the
   value goes through the proxy and the server writes the snapshot. Check
   that nothing in the proxy path drops or reorders it.

## 7. Acceptance criteria

- **Equivalence.** The lab ingested from a clean `topo_*` state via
  "Topology by SNMP" and, separately, via "Topology by push" gives the
  same Devices, Ports, links (both types) and `represented_by` targets by
  natural keys (Part 2 §10 method). Same for `topo_observations` outcomes
  per neighbor. Compare only roles and sources both transports collect
  (if `push.py` has no CDP, disable the CDP rule on the SNMP side for
  this test).
- **Identity bytes.** For every lab device, `{#LOC_CHASSIS}` and every
  `{#REM_CHASSIS}` from push equal the native step's output for the same
  walk.
- **Normalization is a no-op on native output** (§5.1).
- **Bad identity row:** a push row with a 5-octet MAC is skipped,
  counted in `rows_identity_invalid`; other rows are processed.
- **Failure keeps the snapshot:** block SNMP to one lab device → push
  sends nothing for its roles → snapshots byte-identical (`clock`,
  `rows_hash`), heartbeat still arrives.
- **Empty is empty:** a device with LLDP and no neighbors → `[]` sent →
  empty snapshot, rule supported.
- **Clock:** a value sent with `-T` 10 minutes in the past → snapshot
  `clock` = that time; link `last_seen_*` from it.
- **Clock never goes back:** send a value with `-T` older than the stored
  `clock` → snapshot unchanged.
- **Ingest normalization before identity:** a push PORTS row with
  `{#LOC_CHASSIS}` in upper case and dashes, and an LLD NEIGHBORS row on
  the same host with the normal form → one reporter Device, not
  `identity_ambiguous`.
- **Duplicate transport:** one host with LLDP by SNMP and LLDP by push →
  both processed, reported in `hosts_duplicate_transport`. LLDP by SNMP +
  CDP by push → not reported.
- **Truncated walk:** a simulated non-increasing OID in the LLDP table →
  `push.py` sends nothing for that role (§2 rule 3).
- **Legacy untouched:** a host with only `topology.discovery.raw` is
  still ingested by the legacy loop, results unchanged.
- **Transition:** a host with the legacy template and "Topology by push"
  linked together (no key conflict) → ingested from snapshots only
  (Part 2 §5.1 rule).
- Contract violations in push rows show in the rule's LLD info text, as
  for any role rule (Part 2 §4.5).
- All existing topology suites pass.

## 8. Open questions

- **Removing the legacy loop** (and `--legacy-blob`) after §7 passes.
  Separate decision.
- **CDP, FDB, LAG in `push.py`.** Add them, or let devices that need
  them use the LLD template?
- **Normalization in two languages** (C step, PHP ingest, Python
  push.py). §5.1 makes PHP the final authority; the C and Python copies
  only need to agree with it. A shared fixture set (Part 3 §6.1) is the
  test for all three.
