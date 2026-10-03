# Zabbix topology — 3. Delivery through push (trapper)

Version 1.0 (2026-10-03). This spec replaces
`topology-push-transport-spec.md` (v0.2) and the push component of
`topology-prototype-spec.md` §4.1.

Status: implemented: `push.py`, the template "Topology by push" and
`test_push.py`. Not done: the server checks 2 and 3 of §6 (value size,
proxy path), the equivalence run of §7 (the lab runner does not run
`push.py`, simulator spec §8.1) and the open questions (§9).

The input contract is in `topology-1-model.md` §5. The configuration of
discovery rules with a role is in `topology-2-lld-delivery.md` §2.

**Rule for the coding agent.** If a requirement that is not in this spec
seems necessary, stop and flag it. Do not expand the scope silently.

---

## 0. Objective

Keep two transports and one reconciliation:

```
LLD (SNMP walk + native step)  ──┐
                                 ├──► topo_lld_snapshot ──► ingest.php (one path)
push.py ──► trapper discovery    ┘
            rules with a role
```

Reconciliation (model §6–§9) works only on the snapshot path. This spec
moves the push collector onto that path.

**No new server code and no adapter are needed.** A role is allowed on a
discovery rule of any item type, including Zabbix trapper (LLD spec
§2.1). The server writes a snapshot for any rule with a role after
successful LLD processing (LLD spec §3). So a trapper discovery rule with
a role, filled by `zabbix_sender`, gives the same snapshot as an SNMP-walk
rule. The work is in `push.py`, a template and server checks (§6).

**When push is the right transport:** the collector must run near the
device (no SNMP path from the Zabbix server or proxy), or must run on
demand under its own control.

## 1. Scope

**In scope:**
- `push.py` emits contract rows per role and sends them to trapper
  discovery rules (§3).
- Template "Topology by push" (§4).
- Server checks: snapshot clock and value size for trapper rules (§6).
- Equivalence tests: LLD path vs push path (§7).

Ingest normalization and the duplicate-transport report are part of
ingest: model §5.6 and §6.9.

**Out of scope:**
- Any change to the legacy blob loop (§8).
- LEARNED_MACS and LAG from `push.py` if it does not collect them today.
  Add them only if they exist.
- Removing LLD or the native step. Both transports stay.
- Reporter onboarding (model §1).

## 2. Design rules

1. **One contract.** Push rows use the macro names of model §5.2. The
   server maps them to the normalized fields of model §5.3. No second
   format.
2. **Same rule granularity as the LLD template.** One trapper discovery
   rule per role, and separate rules for LLDP and CDP neighbors. Link
   replacement counts support per rule (model §8.4).
3. **Failed ≠ empty** (model §5.5, FR 5.d). `push.py` sends `[]` only
   when the source table was read and has no rows. When the walk fails,
   times out or is partial, it sends **nothing** for that role, and the
   last snapshot stays. "Partial" includes the silent cases: a
   non-increasing OID and an early `endOfMibView` are failures, not ends
   of the table.
4. **Identity bytes match across transports.** `push.py` normalizes
   (model §5.6), and ingest normalizes again, so a collector bug can't
   create duplicate Devices.

## 3. `push.py`

### 3.1 Output

Per reporter, per role: one JSON array (bare array). Each row is an
object with contract macro names as keys (`{"{#IFINDEX}":"3", …}`),
values as strings, absent optional values omitted — the same as the
native step (LLD spec §4.3).

| Trapper rule key | Role | Rows |
|---|---|---|
| `topology.push.ports` | PORTS | one per port |
| `topology.push.lldp` | NEIGHBORS | one per LLDP neighbor, `{#SOURCE}` = `lldp` |
| `topology.push.cdp` | NEIGHBORS | one per CDP neighbor, `{#SOURCE}` = `cdp` (only if `push.py` reads CDP) |
| `topology.push.fdb` | LEARNED_MACS | only if `push.py` reads FDB |
| `topology.push.lag` | LAG | only if `push.py` reads LAG |

Every row carries `{#LOC_CHASSIS}` when the device reports it (model §6.4).

### 3.2 Sending

- One `zabbix_sender` call per reporter with all its role values, each
  with the **collection timestamp** (`-T`, the time the walk finished).
- A role whose collection failed is not in the call (§2 rule 3).
- Heartbeat item `topology.push.heartbeat`: sent on every run, even when
  every role failed.
- `push.py` never calls the Zabbix API. It needs only SNMP reach to the
  devices and network reach to the trapper port. Items exist on the host
  through the template.

### 3.3 Normalization

Apply model §5.6 to every identity field (`{#LOC_CHASSIS}`,
`{#REM_CHASSIS}`, `{#REM_CHASSIS_TYPE}`, `{#REM_MGMT_IP}`, `{#IFMAC}`,
`{#MAC}`). Output strings must equal the native step's output for the
same walk. Port the logic from the native step; do not write a new
variant.

### 3.4 Mapping from the legacy blob

List the mapping in the code. Notable points:
- `neighbors[].local_if_index` → `{#IFINDEX}`.
- `remote_port_id_subtype` → `{#REM_PORT_TYPE}`, same strings as the
  native step.
- `reporter.chassis_id` → `{#LOC_CHASSIS}` on every row.
- `stats` has no place in the contract. Drop it; contract checks and the
  ingest summary replace it.

Keep the old blob output behind a flag (`--legacy-blob`) while the legacy
loop exists (§8).

## 4. Template "Topology by push"

- Discovery rules of type **Zabbix trapper**, keys as in §3.1, each with
  its `topology_role`. No preprocessing, no LLD macro paths (rows already
  have macro keys), no discard steps.
- No item prototypes.
- Trapper item `topology.push.heartbeat` + a `nodata()` trigger
  (interval: 2 × push interval). Not `topology.discovery.heartbeat`: the
  legacy template defines that key, and a host in transition has both
  templates linked; a duplicate key would fail the link.
- `Allowed hosts` on the trapper rules: empty in the template. Document it
  as the place to restrict senders.
- Do not collect the same data twice on one host (same role and, for
  NEIGHBORS, same source — e.g. LLDP by SNMP **and** LLDP by push). It is
  not wrong for reconciliation, but it doubles the load and makes
  freshness and diagnostics hard to read. Mixing is fine (LLDP by SNMP +
  CDP by push). Not enforced; ingest reports it (model §6.9).

## 5. Ingest

No push-specific ingest code. The rules that push depends on:
- identity normalization before reporter identity (model §5.6, §6.3);
- duplicate-transport report (model §6.9);
- `observations.itemid` = the trapper rule's itemid (model §7.1).

## 6. Server checks

Check and report with file:line. Change code only where marked
**required**.

1. **Snapshot clock** (done: the value timestamp, never back; lab test
   `test_snapshot_clock.py`). For a trapper discovery rule, does
   `topo_lld_snapshot.clock` come from the value timestamp sent with
   `-T`, or from processing time?
   - **Required:** it is the value timestamp (model §5.5 rule 3). If it is
     processing time, change it for all rule types (for SNMP rules the two
     are almost equal).
   - **Required:** `clock` never goes back. A value older than the stored
     `clock` (late value, e.g. from a proxy buffer) does not change the
     snapshot. Otherwise the same-hash branch would move `clock` back, and
     `last_seen` with it.
2. **Value size.** The maximum size of a value a trapper discovery rule
   accepts, on server and proxy. Compare with the largest lab FDB array.
3. **Proxy.** A trapper discovery rule on a proxy-monitored host: the
   value goes through the proxy, the server writes the snapshot. Check
   that the proxy path does not drop or reorder it.

## 7. Acceptance criteria

- **Equivalence.** The lab ingested from clean `topo_*` state via
  "Topology by SNMP" and, separately, via "Topology by push" gives the
  same Devices, Ports, links (both types) and `represented_by` targets by
  natural keys, and the same observation outcomes per neighbor. Compare
  only roles and sources both transports collect (if `push.py` has no
  CDP, disable the CDP rule on the SNMP side for this test).
- **Identity bytes.** For every lab device, `{#LOC_CHASSIS}` and every
  `{#REM_CHASSIS}` from push equal the native step's output for the same
  walk.
- **Failure keeps the snapshot.** Block SNMP to one lab device → push sends
  nothing for its roles → snapshots byte-identical (`clock`, `rows_hash`);
  heartbeat still arrives.
- **Empty is empty.** A device with LLDP and no neighbors → `[]` sent →
  empty snapshot, rule supported.
- **Truncated walk.** A non-increasing OID in the LLDP table → `push.py`
  sends nothing for that role.
- **Clock.** A value sent with `-T` 10 minutes in the past → snapshot
  `clock` = that time; link `last_seen*` from it.
- **Clock never goes back.** A value with `-T` older than the stored
  `clock` → snapshot unchanged.
- **Contract violations** in push rows show in the rule's LLD info text,
  as for any rule with a role.
- **Legacy untouched.** A host with only `topology.discovery.raw` is still
  ingested by the legacy loop, results unchanged.
- **Transition.** A host with the legacy template and "Topology by push"
  linked together (no key conflict) → ingested from snapshots only.
- The ingest criteria for normalization and duplicate transports (model
  §15) pass with push rows.
- All existing topology suites pass.

## 8. Legacy blob path

Status: implemented (old path). Kept, not extended.

- With `--legacy-blob`, `push.py` writes the old blob to the trapper item
  `topology.discovery.raw` on the reporter host, plus
  `topology.discovery.heartbeat`. Without the flag it sends contract rows
  (§3).
- Old blob shape: `reporter{sysname, chassis_id, mgmt_ip, vendor}`,
  `ports[]`, `neighbors[]` (`local_if_index`, `remote_chassis_id`,
  `remote_sysname`, `remote_port_id`, `remote_port_id_subtype`,
  `remote_port_desc`), `stats`, `collected_at`.
- Ingest rules for this path: model §5.7.

## 9. Open questions

- **Remove the legacy loop** (and `--legacy-blob`) after §7 passes.
  Separate decision.
- **CDP, FDB, LAG in `push.py`:** add them, or let devices that need them
  use the LLD template?
- **Normalization in three languages** (C, Python, PHP): PHP is the final
  authority; one shared fixture set tests all three (LLD spec §6.1).
- **Lab:** the simulator can provision push hosts but its runner does not
  run `push.py` yet (simulator spec §8.1). Equivalence runs depend on it.
