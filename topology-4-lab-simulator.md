# Zabbix topology — 4. Lab simulator

Version 2.0 (2026-10-03). This spec replaces
`topology-lab-simulator-spec.md` (v1) and adds the lab parts of the
2026-10-02 change set and of the freshness threshold.

The tool is built. Sections that are not built have
`Status: not implemented`.

References:
- `topology-1-model.md` — model, rules, state and outcome names.
- `topology-2-lld-delivery.md` — template "Topology by SNMP", golden
  fixtures (§6.1).
- `topology-3-push-delivery.md` — template "Topology by push".

**Rule for the coding agent.** If a requirement that is not in this spec
seems necessary, stop and flag it. Do not expand the scope silently.

---

## 0. Objective

The lab must test reconciliation rules on SNMP data that changes over
time. Two tools:

1. **Generator.** Reads a declarative lab description (YAML) and writes
   snmpsim data. One cable is described once; the generator writes
   consistent data for both ends.
2. **Scenario runner.** Moves the lab through numbered steps, makes Zabbix
   poll, runs ingest and checks the result. Each step is deterministic. No
   step depends on wall-clock timing, except where the step says so
   (S4).

## 1. Scope

**In scope:** lab description (§4), generator (§5), snmpsim control (§6),
Zabbix provisioning (§7), runner and assertions (§8), scenarios (§9).

**Out of scope:**
- Any change to topology code (ingest, reconciliation, UI, templates). If
  a scenario fails, report it. Do not fix topology code in this task.
- A custom snmpsim variation module for non-increasing OIDs (§11).
- Scale scenarios. The generator must support them; no scale scenario now.

## 2. Constraints

- Use **only** the `zabbix` database. **Never** connect to `zbx`. The
  database name is one config value; the tool refuses to start if it is
  not `zabbix`.
- The lab runs on a test VM (test Zabbix + DB). The tool can use the
  existing API token there.
- The tool changes only Zabbix hosts it created: host tag
  `lab.managed=1`. Never change or delete hosts without this tag.
- The tool changes only the global macros it owns (§8.5).
- Keep credentials out of commits.

## 3. Architecture

```
lab.yaml + scenarios/*.yaml
        │
        ▼
   gen ──► data/<device>/static.snmprec
       ──► data/<device>/<subtree>/00000.snmprec … (one file per step)
       ──► hosts.json (provisioning input)
        │
        ▼
   labctl ──► one snmpsim process per device IP
        │
        ▼
   runner ──► per step: switch data → poll → wait → ingest → assert
```

One Python package with subcommands (`labsim gen`, `labsim labctl`,
`labsim runner`, `labsim provision`, `labsim restore`).

## 4. Lab description

### 4.1 Devices (`lab.yaml`)

```yaml
network: 10.250.0.0/24
devices:
  Switch1:
    ip: 10.250.0.11
    community: lab
    sysname: Switch1
    chassis: {subtype: mac, value: "00:11:22:33:44:01"}
    lldp: true
    cdp: false
    fdb: qbridge              # qbridge | bridge | none
    lldp_local_port_subtype: interfaceName   # interfaceName | macAddress | local
    lldp_loc_port_num: ifindex               # ifindex | offset:<n> | sequential
    ports:
      - {ifindex: 1, name: Gi0/1, type: physical, mac: "00:11:22:33:44:a1"}
      - {ifindex: 2, name: Gi0/2, type: physical}
      - {ifindex: 49, name: Po1, type: lag, members: [Gi0/47, Gi0/48]}
    zabbix:
      monitored: true         # false = exists only as a neighbor
      transport: lld          # lld | push
      proxy: null
```

- `monitored: false` → no snmpsim process, no Zabbix host. Other devices
  still see it by LLDP.
- `lldp_loc_port_num`: `offset:<n>` and `sequential` make `lldpLocPortNum`
  differ from ifIndex on purpose.

### 4.2 Cables

```yaml
cables:
  - [Switch1:Gi0/1, Router1:Gi0/0]
  - [Switch1:Gi0/2, Printer1:eth0]
```

From one cable the generator writes an LLDP (or CDP) entry on each end
that has LLDP (or CDP), and FDB entries for the far MAC on each end that
has FDB.

### 4.3 Extra MACs

```yaml
fdb_extra:
  Switch1:Gi0/10: {count: 25, vlan: 10}
```

### 4.4 Deviations

A deviation makes the data **inconsistent on purpose**. Each has a
`reason` that names the rule it tests.

```yaml
deviations:
  - id: router1-gi01-manual-vs-lldp
    reason: "model §9.4, §15 lab case"
    type: zabbix_manual_link
    link: [Router1:Gi0/1, UPS1:eth0]
  - id: unmanaged-switch-sw1-gi05
    reason: "model §8.5: several neighbors on one port → ambiguous"
    type: extra_lldp_neighbor
    port: Switch1:Gi0/5
    neighbors: [PC1:eth0, PC2:eth0]
  - id: printer1-hex-sysname
    reason: "LLD spec §6.2: non-UTF-8 bytes"
    type: override_oid
    device: Printer1
    oid: lldpRemSysName
    value_hex: "50726e74fffe"
```

| Type | Effect |
|---|---|
| `extra_lldp_neighbor` | adds LLDP entries on one port, with no cable |
| `one_sided_cable` | writes the LLDP entry on one end only |
| `override_oid` | sets one OID value (string or hex) |
| `omit_mib` | removes a MIB (`lldp`, `cdp`, `fdb`, `lag`) from a device |
| `ifindex_renumber` | changes ifIndex values; names stay |
| `shared_port_id` | makes several ports of a device advertise the same LLDP port id |
| `zabbix_manual_link` | creates a manual link through the prototype API |

Status (`shared_port_id`): not confirmed as a named type. S3 step 4 needs
it; if the built tool does this with `override_oid`, keep that and update
this table.

### 4.5 Scenarios (`scenarios/<id>.yaml`)

```yaml
id: S1
title: Recabling, flicker and return
base: lab.yaml
settings:
  freshness_seconds: null      # §8.5; null = do not touch the macro
steps:
  - id: 0
    note: baseline
  - id: 1
    note: Printer1 moved from Switch1:Gi0/2 to Switch1:Gi0/3
    cables:
      remove: [[Switch1:Gi0/2, Printer1:eth0]]
      add:    [[Switch1:Gi0/3, Printer1:eth0]]
    assert: [...]
  - id: 2
    deviations:
      add: [ups1-no-lldp]       # ids from lab.yaml or the scenario
      remove: []
    faults: {Printer1: unreachable}
    wait: {seconds: 0}          # only for time-dependent steps (S4)
    assert: [...]
```

- A step changes only what it lists. Each step starts from the previous
  step's state.
- Step ids are stable. A removed step keeps its id free, so reports stay
  comparable.
- Step-level `deviations` add or remove deviations for this step and the
  next ones. Status: not implemented (needed by S0 step 2 and S3 step 4).

## 5. Generator

### 5.1 OID set

The OID set of the master item of "Topology by SNMP" (LLD spec §5), plus
the `system` group. Read the OID list from the template file, not from
memory.

### 5.2 Encoding

- Tag `4x` (hex OctetString) for chassis ids, `macAddress` port ids, MACs
  and any `value_hex`.
- Hex for any value with bytes outside printable ASCII.
- OIDs in each file sorted numerically.

### 5.3 Files per device

- **Static device** (no change in any step of the active scenario): one
  `static.snmprec`.
- **Changing device:** data split into subtrees. Each subtree that changes
  in any step goes through `labmultiplex` with a `control` OID:

  ```
  1.3.6.1.2.1|:labmultiplex|dir=<device>/mib2,control=1.3.6.1.2.1.99999.0
  1.0.8802.1.1.2|:labmultiplex|dir=<device>/lldp,control=1.0.8802.1.1.2.99999.0
  ```

  - The control OID is **inside** its record's subtree (fixed suffix
    `.99999.0`). snmpsim passes a request to a variation module only for
    OIDs under the record's subtree.
  - The control OID is not in the step files, so a walk does not return
    it. It answers GET and SET only.
  - One file per step: `00000.snmprec` = step 0, … A subtree that does not
    change in a step gets a copy of the previous file.
- No variation modules (`delay`, `error`, `writecache`) inside step files;
  snmpsim does not allow them under `labmultiplex`.

### 5.4 Checks

The generator writes nothing and fails when:
- a cable uses a port that does not exist;
- two cables use the same port and no deviation allows it;
- a deviation names a device or port that does not exist.

### 5.5 Fixture export

`gen export-walks <scenario> <step>` writes the walk text that snmpsim
returns for each device, in the golden fixture format (LLD spec §6.1).
File names end with `.synthetic.walk`.

## 6. snmpsim control (`labctl`)

### 6.1 Addresses

- One snmpsim process per device, listening on the device IP (IP aliases
  on a dummy interface). Zabbix server and any lab proxy must reach them.
- Reason: with one IP for all devices, `lldpRemManAddr` and `mgmt_ip`
  values collide, and `represented_by` matching is wrong.
- Reason for one process per device: snmpsim is single-threaded; a delay
  in one device would block the others.

### 6.2 Commands

- `labctl up [scenario]` — aliases, processes at step 0.
- `labctl down` — stop processes, remove aliases.
- `labctl status` — device, IP, PID, current step, current fault.
- `labctl step <n>` — `snmpset` every control OID of every changing
  device, then read each back and check it.
- `labctl fault <device> <fault|none>` — §6.3.

### 6.3 Faults

| Fault | Implementation | Status |
|---|---|---|
| `unreachable` | stop the device process (SIGSTOP); `none` → SIGCONT | implemented |
| `slow` | `delay` with `wait` and `deviation` (ms) | not implemented |
| `wrong_community` | restart the process with a different community | not implemented |
| `lldp_error` | `error` with `status=genError` on one `lldpRemTable` OID | not implemented |

The open part for `slow` and `lldp_error`: `sysUpTime.0` and the first OID
of each walked subtree are under the multiplexed roots, and step files
can't hold variation modules. Either confirm that a `delay` / `error`
record can take precedence there, or use a fault-specific main file that
replaces the multiplex records, and send the control values again after
the restart.

### 6.4 Spike (done)

Confirmed on one device before the full build:
1. A Zabbix `walk[]` (GETBULK) across a `labmultiplex` subtree returns the
   selected step's data and does not stop at the subtree edge.
2. `labctl step` changes the data seen by the next walk.
3. The control OID does not appear in the walk of the template OID set.

## 7. Zabbix provisioning

- From `hosts.json`, create or update one host per `monitored: true`
  device through the API. Match by host name. Tag `lab.managed=1`.
- SNMP interface: device IP, port 161, SNMPv2c, community from `lab.yaml`.
- Link "Topology by SNMP" (`transport: lld`) or "Topology by push"
  (`transport: push`). Set the proxy from `lab.yaml`.
- `provision --prune` deletes `lab.managed=1` hosts that are not in
  `lab.yaml`. No other deletes.

## 8. Scenario runner

### 8.1 Step loop

For each step:
1. `labctl step <n>`; apply the step's deviations and faults.
2. Make Zabbix poll now: API `task.create` ("check now") on the master item
   of every lab host the step changes. For `push` hosts, run `push.py`
   for these hosts. Status (push): not implemented — the runner reports an
   error for `push` hosts.
3. Wait until the snapshot `clock` of each expected rule advances in
   `topo_lld_snapshot` (timeout configurable, default 120 s). For a fault
   step, wait for the master item to become unsupported, or for the
   timeout, as the step says. For a step with `wait`, also wait that many
   seconds after the poll.
4. Run ingest the same way as the existing test suites (full run).
5. Run the step's assertions (§8.3).
6. Write the step result to the report.

### 8.2 Reset

`runner --reset` clears `topo_*` state the same way as the "clean `topo_*`
state" of the equivalence tests. Only on the `zabbix` database. Without
`--reset`, the runner continues from the current state.

### 8.3 Assertions

Natural keys (sysname or chassis, port name), never database ids.

```yaml
assert:
  - link: [Switch1:Gi0/3, Printer1]          # far end as Device or Device:port
    state: active                             # active | superseded | stale | absent
    type: physical_link                       # optional: physical_link | device_link
    discovered_via: manual                    # optional
    precision_lower: true                     # optional
    far_port_reason: port_unmatched           # optional, device_link only
  - observation: {reporter: Switch1, port: Gi0/5}
    outcome: ambiguous
  - edge_id_same_as: {step: 0, link: [Switch1:Gi0/2, Printer1]}
  - status: {field: manual_links_contradicted, equals: 1}
  - contradiction: {link: [Router1:Gi0/1, UPS1:eth0], hidden_neighbors: [Printer1, Switch2]}
  - badge: {on: UPS1, present: false}
```

- Read from `/topo/observations`, `/topo/ingest/status` and the edge
  tables.
- State, outcome and field names come from the model spec. Do not invent
  names. A check that needs something no spec defines is marked `pending`
  with a reason.
- `contradiction` and `badge` read the derived state that the UI uses
  (model §9.4, §12.4). If the runner has no way to read it without a
  browser session, mark them `pending` (same open question as `stale`,
  §11).

### 8.4 Report

- Console: scenario, step, pass / fail / pending per assertion.
- JSON report with the same data, plus snapshot clocks, ingest status and
  the freshness macro state (§8.5) per step.
- Exit code 0 only if every non-pending assertion passed.

### 8.5 Freshness threshold override

Status: not implemented (depends on model §8.8).

- A scenario can set `settings.freshness_seconds` (≥ 60). Before the
  scenario the runner sets the global macro `{$TOPOLOGY.LINK.FRESH.SECONDS}`
  through the API (`usermacro.createglobal` / `updateglobal`).
- Before the change, the runner writes the original state (old value, or
  "absent") to a file under `run/`.
- After the run — also on failure or interrupt (`finally`) — the runner
  restores it: the old value, or deletion if the macro did not exist.
- A killed run is restored by the next `labsim` start, or by
  `labsim restore`.
- The report shows the macro value before, during and after.

## 9. Scenarios

### S0 — Baseline and the manual-link case

| Step | Change | Check against |
|---|---|---|
| 0 | baseline (current lab, incl. deviation `router1-gi01-manual-vs-lldp`) | a fresh ingest gives the same Devices, Ports, links and `represented_by` targets by natural keys as the old lab |
| 1 | — (UPS1 with LLDP) | model §15 "UPS1 with LLDP": three shadowing observations; hidden neighbors Printer1 and Switch2; `manual_links_contradicted` = 1 |
| 2 | deviation `ups1-no-lldp` (`omit_mib: lldp` on UPS1) | model §15 "UPS1 without LLDP": two shadowing observations (Printer1 near end, Switch2 third party); no badge on UPS1 |

- Old lab files were replaced by generated files after step 0 passed.
- Step 1 assertions for Switch2 as a hidden neighbor, and all of step 2,
  test the third-party rule. Until model §9.4 (third party) is
  implemented, they are expected to fail; that is a valid result.
- Step 2 needs step-level deviations (§4.5).

### S1 — Recabling, flicker, return

| Step | Change | Check against |
|---|---|---|
| 0 | baseline | — |
| 1 | Printer1 moves Gi0/2 → Gi0/3 | model §8.4: old link superseded, new link active |
| 2 | Printer1 absent from LLDP for one poll | model §8.7: link stays, no replacement |
| 3 | Printer1 back on Gi0/3 | no new edge; state as in step 1 |
| 4 | Printer1 back on Gi0/2 | model §8.4 revival: same edge id as step 0 |

### S2 — Unmanaged switch (several neighbors on one port)

| Step | Change | Check against |
|---|---|---|
| 0 | baseline | — |
| 1 | PC1 and PC2 both on Switch1:Gi0/5 (deviation) | model §8.5: `ambiguous` |
| 2 | PC2 removed | single neighbor; link per model §8.5 / §8.1 |

### S3 — Neighbor without a known far port

| Step | Change | Check against |
|---|---|---|
| 0 | Switch1:Gi0/6 ↔ AP1, both monitored | port-level link |
| 1 | AP1 `unreachable`; Switch1 still sees AP1 | AP1 snapshots unchanged; link stays port-level |
| 2 | *(removed: tested the old precision downgrade)* | — |
| 3 | AP2 (`monitored: false`) on Switch1:Gi0/7 | AP2 is not a reporter → port from the advertisement, `physical_link` (model §8.1) |
| 4 | a second AP2 port advertises the same port id (deviation) | model §8.3: Switch1:Gi0/7 ↔ AP2 stays `physical_link`, same edge id as step 3, `precision_lower: true` |
| 5 | shared id removed | `physical_link` unchanged (same edge id), `precision_lower: false`, `last_seen_port` advanced |

- Steps 4 and 5 test model §8.3 (not implemented). Expected to fail until
  then.
- Step 4 needs step-level deviations (§4.5).

### S4 — Link staleness

Status: not implemented (depends on model §8.8 and on §8.5 here).

| Step | Change | Check against |
|---|---|---|
| 0 | baseline, `freshness_seconds: 60` | links active, not stale |
| 1 | Printer1 removed from Switch1's LLDP; `wait: {seconds: 90}` | Switch1:Gi0/2 ↔ Printer1 `stale`, not deleted |
| 2 | Printer1 back | link not stale; same edge id |

- After the run, the macro is restored (§8.5), also on failure.
- The `stale` check depends on the open question in §11.

## 10. Acceptance criteria

- S0 step 0 passes.
- S0–S3 run end to end. Each assertion passes, fails with a clear message,
  or is `pending` with a reason. A failure in topology code is a valid
  result; report it.
- Two runs of one scenario from `--reset` give the same report (except
  clocks and ids).
- `labctl down` leaves no processes and no IP aliases.
- The generator rejects the invalid inputs of §5.4.
- The tool refuses to start when the database name is not `zabbix`.
- No host without `lab.managed=1` is changed.
- Not implemented yet: step-level deviations (S0 step 2, S3 step 4);
  freshness override with restore after failure and after a killed run
  (§8.5, S4).

## 11. Open questions

- **Reading derived state.** `stale`, contradiction and badges are derived
  in `CTopologyPrototype` behind a browser session; the runner has none.
  Options: a read-only script that runs the same class; a session for the
  lab; the runner computes the rule itself (tests a copy). Decide before
  S4 and before the `contradiction` / `badge` assertions.
- **Time in S4.** With the minimum of 60 s, a step must wait longer than
  the threshold after the last sighting; the step timeout must allow it.
- **Non-increasing OID** (push spec §7 "truncated walk"). snmpsim sorts
  data. A custom variation module could return a lower OID on GETNEXT; not
  checked. Separate task.
- **Push transport in the runner** (§8.1). Needed for equivalence runs
  (push spec §7).
- **Faults `slow`, `wrong_community`, `lldp_error`** (§6.3).
- **Scale scenarios:** a core switch with several hundred ports and about
  50 access devices.
- **Real captures:** synthetic walks do not replace walks from real
  devices. Keep both fixture sets.
