# Topology lab — scenario simulator: build spec (v1)

## 0. Objective

The current lab has static snmpsim devices and one complex case
(Router1 / Printer1 / UPS1). Most reconciliation rules have no test on
SNMP data that changes over time.

Build two tools:

1. **Generator.** It reads a declarative lab description (YAML) and
   writes snmpsim data. One cable is described once. The generator
   writes consistent data for both ends.
2. **Scenario runner.** It moves the lab through numbered steps, makes
   Zabbix poll, runs ingest and checks the result. Each step is
   deterministic. No step depends on wall-clock timing.

References:
- `topology-prototype-spec.md` — model spec (natural keys, §3 rules).
- `topology-lld-part2-spec.md` — snapshots, `topo_observations` (§7),
  diagnostics (§8), JS template OID set (§9).
- `topology-lld-part3-spec.md` — "Topology by SNMP" template (§7),
  golden fixtures (§6.1).
- `topology-link-replacement-spec.md` (v2.1) — superseded links,
  revival, ambiguous.
- `topology-manual-contradiction-spec.md` — contradicted manual links.
- `topology-device-level-edge-spec.md` — device-level links.
- `topology-push-transport-spec.md` — push transport.

If a requirement not listed here seems necessary, stop and flag it.
Do not expand scope silently.

## 1. Scope

**In scope:**
- Lab description format (§4)
- Generator (§5)
- snmpsim deployment and control (§6)
- Zabbix host provisioning for lab devices (§7)
- Scenario runner and assertions (§8)
- Scenarios S0–S3 (§9)

**Out of scope:**
- Any change to topology code (ingest, reconciliation, UI, templates).
  If a scenario fails, report it. Do not fix topology code in this task.
- A custom snmpsim variation module for non-increasing OIDs (§11).
- Scale scenarios (hundreds of ports). The generator must support them,
  but no scale scenario is in v1.

## 2. Constraints

- Use **only** the `zabbix` database. **Never** connect to `zbx`.
  Put the database name in one config value and refuse to start if it
  is not `zabbix`.
- Do not change or delete existing lab files until S0 passes (§9).
- The tool changes only Zabbix hosts that it created. Mark them with the
  host tag `lab.managed=1`. Never change or delete hosts without this tag.
- Keep credentials out of commits.

## 3. Architecture

```
lab.yaml + scenarios/*.yaml
        │
        ▼
   generator ──► data/<device>/static.snmprec
             ──► data/<device>/<subtree>/00000.snmprec … (one file per step)
             ──► data/<device>/faults/<fault>.snmprec
             ──► hosts.json (provisioning input)
        │
        ▼
   labctl ──► one snmpsim process per device IP
        │
        ▼
   runner ──► per step: switch data → poll → wait → ingest → assert
```

Command names (`gen`, `labctl`, `runner`) are suggestions. Use one
Python package with subcommands if that is simpler.

## 4. Lab description (`lab.yaml`)

### 4.1 Devices

```yaml
network: 10.250.0.0/24        # lab address range, configurable
devices:
  Switch1:
    ip: 10.250.0.11
    community: lab
    sysname: Switch1
    chassis: {subtype: mac, value: "00:11:22:33:44:01"}
    lldp: true                # LLDP-MIB present
    cdp: false
    fdb: qbridge              # qbridge | bridge | none
    lldp_local_port_subtype: interfaceName   # interfaceName | macAddress | local
    lldp_loc_port_num: ifindex               # ifindex | offset:<n> | sequential
    ports:
      - {ifindex: 1, name: Gi0/1, type: physical, mac: "00:11:22:33:44:a1"}
      - {ifindex: 2, name: Gi0/2, type: physical}
      - {ifindex: 49, name: Po1, type: lag, members: [Gi0/47, Gi0/48]}
    zabbix:
      monitored: true         # false = device exists only as a neighbor
      transport: lld          # lld | push
      proxy: null
```

- `monitored: false` devices get no snmpsim process and no Zabbix host.
  Other devices still see them by LLDP.
- `lldp_loc_port_num` controls the `lldpLocPortNum` ↔ ifIndex mapping.
  `offset:<n>` and `sequential` make them different on purpose.

### 4.2 Cables

```yaml
cables:
  - [Switch1:Gi0/1, Router1:Gi0/0]
  - [Switch1:Gi0/2, Printer1:eth0]
```

From one cable the generator writes:
- an LLDP (or CDP) entry on each end that has LLDP (or CDP),
- FDB entries for the far MAC on each end that has FDB.

### 4.3 Extra MACs

```yaml
fdb_extra:
  Switch1:Gi0/10: {count: 25, vlan: 10}   # synthetic MACs behind a port
```

### 4.4 Deviations

A deviation is a named change that makes the data **inconsistent on
purpose**. Each deviation has a `reason` that names the rule it tests.

```yaml
deviations:
  - id: router1-gi01-manual-vs-lldp
    reason: "manual-contradiction spec §8, lab case"
    type: zabbix_manual_link          # created in the prototype, not in SNMP
    link: [Router1:Gi0/1, UPS1:port1]
  - id: unmanaged-switch-sw1-gi05
    reason: "replacement spec: several neighbors on one port → ambiguous"
    type: extra_lldp_neighbor
    port: Switch1:Gi0/5
    neighbors: [PC1:eth0, PC2:eth0]
  - id: printer1-hex-sysname
    reason: "Part 3 §6.2 non-UTF-8 bytes"
    type: override_oid
    device: Printer1
    oid: lldpRemSysName           # symbolic names resolved by the generator
    value_hex: "50726e74fffe"
```

Supported deviation types in v1:

| Type | Effect |
|---|---|
| `extra_lldp_neighbor` | Adds LLDP entries on one port with no cable |
| `one_sided_cable` | Writes the LLDP entry on one end only |
| `override_oid` | Sets one OID value (string or hex) |
| `omit_mib` | Removes a MIB (`lldp`, `cdp`, `fdb`, `lag`) from a device |
| `ifindex_renumber` | Changes ifIndex values; names stay the same |
| `zabbix_manual_link` | Creates a manual link through the prototype API or the existing mechanism |

If a manual link can't be created without new topology code, stop and
flag it.

### 4.5 Scenarios (`scenarios/<id>.yaml`)

```yaml
id: S1
title: Recabling, flicker and return
base: lab.yaml
steps:
  - id: 0
    note: baseline
  - id: 1
    note: Printer1 moved from Switch1:Gi0/2 to Switch1:Gi0/3
    cables:
      remove: [[Switch1:Gi0/2, Printer1:eth0]]
      add:    [[Switch1:Gi0/3, Printer1:eth0]]
    assert: [...]           # §8.3
  - id: 2
    note: fault on Printer1
    faults: {Printer1: unreachable}
    assert: [...]
```

A step changes only what it lists. Each step starts from the previous
step's state.

## 5. Generator

### 5.1 OID set

Write the same OID set as the master item of "Topology by SNMP"
(Part 3 §7): IF-MIB, LLDP-MIB, `dot1dBasePortIfIndex`, Q-BRIDGE and
BRIDGE FDB, `dot3adAggPortAttachedAggID`, CISCO-CDP-MIB `cdpCacheTable`
and `cdpGlobalRun`. Also write `system` group values.

Take the OID list from the template file, not from memory.

### 5.2 Encoding

- Use tag `4x` (hex OctetString) for chassis ids, port ids of subtype
  `macAddress`, MACs and any `value_hex`.
- Write a value as hex if it has bytes outside printable ASCII.
- Sort OIDs in each file numerically.

### 5.3 Files per device

- **Static device** (no change in any step of the active scenario):
  one `static.snmprec`.
- **Changing device:** the generator splits data into subtrees. Each
  subtree that changes in any step goes through `labmultiplex`
  with a `control` OID:

  ```
  1.3.6.1.2.1|:labmultiplex|dir=<device>/mib2,control=1.3.6.1.2.1.99999.0
  1.0.8802.1.1.2|:labmultiplex|dir=<device>/lldp,control=1.0.8802.1.1.2.99999.0
  ```

  The control OID must be **inside** the subtree of its own record.
  snmpsim sends a request to a variation module only when the OID is
  under the record's subtree, so a control OID outside it answers
  "No Such Instance" to GET and SET. The control OID is not in the step
  files, so a walk does not return it. Use a fixed suffix (`.99999.0`)
  under each subtree root.
  The generator writes one file per step in each subtree folder:
  `00000.snmprec` = step 0, `00001.snmprec` = step 1, and so on.
  If a subtree does not change in a step, copy the previous file.
- **Faults:** one file per fault in `faults/`. See §6.3.

snmpsim does not allow variation modules inside `labmultiplex` files. Do
not put `delay`, `error` or `writecache` lines in step files.

### 5.4 Checks

The generator fails (no output) when:
- a cable uses a port that does not exist,
- two cables use the same port and no deviation allows it,
- a deviation refers to a device or port that does not exist.

### 5.5 Fixture export

`gen export-walks <scenario> <step>` writes the walk text that snmpsim
returns for each device. Format = the format of Part 3 §6.1 fixtures.
Mark these files as synthetic in their name (`*.synthetic.walk`).

## 6. snmpsim deployment (`labctl`)

### 6.1 Addresses

- One snmpsim process per device. Each process listens on the device IP.
- Add IP aliases from `lab.yaml` to a dummy interface. Zabbix server and
  any lab proxy must reach these addresses.
- Reason: snmpsim selects data by community. With one IP for all devices,
  `lldpRemManAddr` and `mgmt_ip` values collide, and `represented_by`
  matching gives wrong results.

### 6.2 Commands

- `labctl up [scenario]` — create aliases, start processes at step 0.
- `labctl down` — stop processes, remove aliases.
- `labctl status` — device, IP, PID, current step, current fault.
- `labctl step <n>` — send `snmpset` to every control OID of every
  changing device. Then read each control OID back and check the value.
- `labctl fault <device> <fault|none>` — see §6.3.

### 6.3 Faults

snmpsim is single-threaded. A delay blocks every device in the same
process. This is the reason for one process per device.

To apply a fault, `labctl` restarts the device process with
`static.snmprec` (or the current step files) plus the fault file:

| Fault | Implementation |
|---|---|
| `unreachable` | `delay` with `wait=1000000` on `sysUpTime.0` and on the first OID of each walked subtree |
| `slow` | `delay` with `wait` and `deviation` from the scenario (ms) |
| `wrong_community` | start the process with a different community |
| `lldp_error` | `error` with `status=genError` on one `lldpRemTable` OID |

After a restart, the device must serve the same step. `labctl` sends the
control values again.

### 6.4 Spike (do first)

Before the full build, check these on one device and report:
1. A Zabbix `walk[]` item (GETBULK) across a `labmultiplex` subtree returns
   the data of the selected step and does not stop at the subtree edge.
2. `labctl step` changes the data seen by the next walk.
3. The control OID (inside its subtree, §5.3) does not appear in the
   walk output of the template OID set. It answers GET and SET only.

If check 1 fails, stop and report. The fallback is a process restart per
step; do not build it without confirmation.

## 7. Zabbix provisioning

- From `hosts.json`, create or update one host per `monitored: true`
  device through the Zabbix API. Match by host name. Add the tag
  `lab.managed=1`.
- SNMP interface: device IP, port 161, SNMPv2c, community from
  `lab.yaml`.
- Link "Topology by SNMP" (`transport: lld`) or "Topology by push"
  (`transport: push`). Set the proxy from `lab.yaml`.
- `provision --prune` deletes hosts with `lab.managed=1` that are not in
  `lab.yaml`. No other deletes.

## 8. Scenario runner

### 8.1 Step loop

For each step:
1. `labctl step <n>`; apply faults of the step.
2. Make Zabbix poll now: API `task.create`, type "check now", on the
   master item of every lab host that the step changes. For push hosts,
   run `push.py` for these hosts.
3. Wait until the snapshot `clock` of each expected rule advances in
   `topo_lld_snapshot`. Timeout is configurable (default 120 s). For a
   fault step, wait for the master item to become unsupported, or for
   the timeout, as the step says.
4. Run ingest the same way as the existing test suites.
5. Run the step assertions (§8.3).
6. Write the step result to the report.

### 8.2 Reset

`runner --reset` clears `topo_*` state the same way as the "clean
`topo_*` state" in Part 2 §10. Run only against the `zabbix` database
(§2). Without `--reset`, the runner continues from the current state.

### 8.3 Assertions

Assertions use natural keys (device sysname or chassis, port name), not
database ids. Supported forms:

```yaml
assert:
  - link: [Switch1:Gi0/3, Printer1]       # active link exists
    state: active
  - link: [Switch1:Gi0/2, Printer1]
    state: superseded
  - observation: {reporter: Switch1, port: Gi0/5}
    outcome: ambiguous
  - edge_id_same_as: {step: 0, link: [Switch1:Gi0/2, Printer1]}
  - status: {field: manual_links_contradicted, equals: 1}
```

Read data from `/topo/observations`, `/topo/ingest/status` and the edge
tables. Take state and outcome names from the referenced specs. Do not
invent names. If a scenario needs a state that no spec defines, mark the
assertion `pending` and flag it.

### 8.4 Report

- Console summary: scenario, step, pass/fail per assertion.
- JSON report file with the same data, plus snapshot clocks and ingest
  status per step.
- Exit code 0 only if every non-pending assertion passed.

## 9. Scenarios v1

### S0 — Baseline (current lab)

- Describe the current lab in `lab.yaml`, including the Router1 /
  Printer1 / UPS1 case as deviation `router1-gi01-manual-vs-lldp`.
- Pass condition: a fresh ingest from `lab.yaml` data gives the same
  Devices, Ports, links and `represented_by` targets by natural keys as
  the current lab (Part 2 §10 method).
- Only after S0 passes, replace the old lab files with generated files.

### S1 — Recabling, flicker, return

| Step | Change | Check against |
|---|---|---|
| 0 | baseline | — |
| 1 | Printer1 moves Gi0/2 → Gi0/3 | replacement spec: old link superseded, new link active |
| 2 | Printer1 absent from LLDP for one poll | replacement spec: behavior of a missing neighbor |
| 3 | Printer1 back on Gi0/3 | no new edge; state as in step 1 |
| 4 | Printer1 back on Gi0/2 | revival: same edge id as step 0 |

### S2 — Unmanaged switch (several neighbors on one port)

| Step | Change | Check against |
|---|---|---|
| 0 | baseline | — |
| 1 | PC1 and PC2 both on Switch1:Gi0/5 (deviation) | replacement spec: `ambiguous` |
| 2 | PC2 removed | single neighbor; link per replacement spec |

### S3 — Neighbor without a known far port

| Step | Change | Check against |
|---|---|---|
| 0 | Switch1:Gi0/6 ↔ AP1, both monitored | port-level link |
| 1 | AP1 `unreachable`; Switch1 still sees AP1 | snapshot of AP1 unchanged; link stays port-level |
| 2 | freshness threshold passes, AP1 still seen on Gi0/6 | device-level link spec |
| 3 | AP2 (`monitored: false`) on Switch1:Gi0/7 | device-level link spec |

Step 2 depends on time. If the prototype has a configurable freshness
threshold, set a short value for the lab run and restore it after. If it
has none, mark step 2 `pending` and flag it. Do not change topology code.

## 10. Acceptance criteria

- §6.4 spike reported before the full build.
- S0 passes. Old lab files are kept until then.
- S1, S2, S3 run end to end. Each assertion passes, fails with a clear
  message, or is `pending` with a reason. A failure in topology code is
  a valid result; report it.
- Two runs of the same scenario from `--reset` give the same report
  (except clocks and ids).
- `labctl down` leaves no processes and no IP aliases.
- Generator rejects the invalid inputs in §5.4.
- The tool refuses to start when the database name is not `zabbix`.
- No host without `lab.managed=1` is changed.

## 11. Open questions

- **Non-increasing OID** (push spec §7 "truncated walk"). snmpsim sorts
  data. A custom variation module can return a lower OID on GETNEXT.
  Not checked that snmpsim passes it through. Separate task.
- **Scale scenarios.** Core switch with several hundred ports and about
  50 access devices. After v1.
- **Both transports in one run.** The runner supports `push` hosts, but
  v1 scenarios use `lld`. Equivalence runs (push spec §7) can use this
  tool later.
- **Real captures.** Synthetic walks don't replace walks from real
  devices. Keep both fixture sets.
- **Faults `slow`, `wrong_community`, `lldp_error` (§6.3).** Not implemented
  in v1; no v1 scenario uses them. `unreachable` is implemented by stopping
  the device process (SIGSTOP, SIGCONT to clear), not with `delay` records.
  §5.3 forbids variation modules in step files, and `sysUpTime.0` and the first
  OID of each walked subtree lie under the multiplexed roots; whether a
  `delay` record can take precedence there was not tried. The remaining faults
  need that answer (or a fault-specific main file that replaces the multiplex
  records, with the control values re-sent after the restart).
- **Push transport in the runner (§7, §8.1).** `provision` can link
  "Topology by push", but the runner does not run `push.py` for `push` hosts
  and reports an error for them. Both transports in one run (see above)
  depend on this.
