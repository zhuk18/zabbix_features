# Zabbix topology — 4. Lab simulator

Version 2.1 (2026-10-03). This spec replaces
`topology-lab-simulator-spec.md` (v1) and adds the lab parts of the
2026-10-02 change set and of the freshness threshold. Version 2.1
describes the tool as built (`database/topology/lab/`).

The tool is built. Sections or lines that are not built have
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
- The tool changes only Zabbix objects it owns:
  - **Created hosts** get the tag `lab.managed=1`.
  - **Adopted hosts.** A host that exists without the tag is changed only
    if it is listed in `lab.yaml` (`settings.adopt`). Before any change the
    tool exports the configuration of the hosts it will adopt
    (`configuration.export`) to a file outside the repository, then adds
    the tag and sets the interface to the `lab.yaml` address.
  - A host with neither the tag nor an `adopt` entry is never changed or
    deleted. `provision --prune` deletes only tagged hosts that are not in
    `lab.yaml`.
  - While a run is active, the update interval of the master items of the
    lab hosts is changed and restored afterwards (§8.1).
  - The global macros of §8.5.
- The API token is read only from the environment variable `ZBX_TOKEN`.
  The tool never writes it to a file. Keep credentials out of commits.

## 3. Architecture

```
lab.yaml + scenarios/*.yaml
        │
        ▼
   gen ──► data/<scenario>/<device>/<community>.snmprec
       ──► data/<scenario>/<device>/<subtree>/00000.snmprec … (one file per step)
       ──► manifest.json, hosts.json (provisioning input)
        │
        ▼
   labctl ──► one snmpsim process per device IP
        │
        ▼
   runner ──► per step: switch data → poll → wait → ingest → assert
```

One Python package `labsim` with subcommands: `gen`, `export-walks`,
`up`, `down`, `status`, `step`, `fault` (labctl), `provision`, `run`
(the runner), `compare-reports`, `restore`.

## 4. Lab description

### 4.1 Devices (`lab.yaml`)

```yaml
settings:
  database: zabbix            # the tool refuses to start with any other value
  network: 127.0.0.0/24       # 127/8 needs no IP aliases
  snmp_port: 1611             # 161 needs root
  community: zbxlab           # default community of the devices
  emit_subtypes: false        # write the lldp*IdSubtype columns
  emit_mgmt_addr: false       # write lldpRemManAddrTable
  adopt: [Router1, Switch1]   # existing hosts the tool may take over (§2)
  zabbix: {url: ..., group: Test, template_lld: ..., template_push: ...}
devices:
  Switch1:
    ip: 127.0.0.3
    chassis: {subtype: mac, value: "00:11:22:33:44:01"}
    port_mac_prefix: "02:00:00:02"       # port MAC = <prefix>:00:<ifindex>
    lldp: true                           # defaults: lldp true, cdp false, fdb none
    cdp: false
    fdb: qbridge                         # qbridge | bridge | none
    lldp_local_port_subtype: interfaceName   # interfaceName | macAddress | local;
                                             # without it (and without
                                             # lldp_loc_port_num) no lldpLocPortTable
    lldp_loc_port_num: ifindex               # ifindex | offset:<n> | sequential
    ports:
      - {ifindex: 1, name: Gi0/24}
      - {ifindex: 3, name: Gi0/3, admin: down, oper: down}
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
- `emit_subtypes: false` and `emit_mgmt_addr: false` reproduce the
  fixtures of the old lab (no subtype columns, no management address
  table), which S0 compares against.

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
| `zabbix_manual_link` | creates a manual `physical_link` between two existing ports through the **same code path as the UI endpoint** (`CTopologyPrototype::linkPorts`: existence checks, canonical direction), called from a lab PHP helper. The function uses only the database layer, so it needs no browser session. Disabling it in a later step is not supported. |

Status (`zabbix_manual_link`): not implemented. **Known deviation:** the
tool inserts the `topo_edges` row itself (`physical_link`,
`discovered_via: manual`, smaller port id as `src_id`), the way the test
suites do. It skips the checks of the real path. The helper is built with
the lab changes of S0 step 2.

**Conflict to decide (reported, not resolved here).** `linkPorts` as built
does not check port uniqueness (`linkPortToDevice` does), while model §9.1
and §15 say the endpoint rejects a port that has an active link. The S0
case needs the manual link on ports that carry active discovered links
(model §8.6 then supersedes them; model §15 "accept discovery" restores
them by id). Until it is decided, the lab follows the code.

- **Shared port id** (S3) is not a deviation type. It is built from
  cables: two cables from one reporter to one device whose ports advertise
  the same id (`lldp_local_port_subtype: macAddress` and the same `mac` on
  those ports).
- `override_oid` takes a symbolic name or a numeric OID, `value` or
  `value_hex`, and an optional `row` (the local port of an `lldpRem*` row).
- A deviation that needs ports or devices the base lab does not have is
  defined in the scenario `patch:` (§4.5). The generator checks every
  deviation against the lab it belongs to (§5.4).

### 4.5 Scenarios (`scenarios/<id>.yaml`)

```yaml
id: S1
title: Recabling, flicker and return
base: lab.yaml
settings:
  freshness_seconds: null      # §8.5, not implemented; null = do not touch the macro
patch:                         # applied before step 0
  devices:
    Switch1: {add_ports: [{ifindex: 4, name: Gi0/4}]}   # or a whole new device
  cables: {remove: [[Router1:Gi0/1, Printer1:Gi0/1]], add: [[Switch1:Gi0/4, Printer1:Gi0/1]]}
  deviations: []               # deviations that need the patched devices
steps:
  - id: 0
    note: baseline
    assert: [...]
  - id: 1
    note: Printer1 moved from Switch1:Gi0/4 to Switch1:Gi0/5
    cables:
      remove: [[Switch1:Gi0/4, Printer1:Gi0/1]]
      add:    [[Switch1:Gi0/5, Printer1:Gi0/1]]
    assert: [...]
  - id: 2
    deviations: {enable: [ups1-no-lldp], disable: []}   # ids from lab.yaml or the patch
    faults: {AP1: unreachable}                          # a fault name, or none
    wait: {unsupported: [AP1], timeout: 120}            # fault steps; default: the faulted devices
    assert: [...]
  - id: 3
    note: time-dependent step (S4)
    wait: {seconds: 90}                                 # wait this long after the poll
    assert: [...]
```

- A step changes only what it lists. Each step starts from the previous
  step's state.
- Step ids are consecutive from 0. Removing a step renumbers the later
  ones; compare reports of different versions by `note`.
- `deviations` enable or disable deviations from this step on.
- `wait` has two forms that can be combined: `{unsupported, timeout}` (the
  devices whose poll must fail, and how long to wait for it) and
  `{seconds: N}` (a plain wait of N seconds after the poll, for
  time-dependent steps such as S4). Status (`seconds`): not implemented.

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
  `<community>.snmprec` (snmpsim takes the community from the file name).
- **Changing device:** data split into subtrees. The main file
  `<community>.snmprec` holds the records of the subtrees that do not
  change. Each subtree that changes in any step goes through
  `labmultiplex` with a `control` OID:

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

- One snmpsim process per device, listening on the device IP and
  `settings.snmp_port`. The lab uses addresses in 127.0.0.0/8, which need no
  setup. For other addresses `labsim up` adds IP aliases on a dummy
  interface with `sudo -n ip` (not tested). Zabbix server and any lab proxy
  must reach the addresses.
- Reason: with one IP for all devices, `lldpRemManAddr` and `mgmt_ip`
  values collide, and `represented_by` matching is wrong.
- Reason for one process per device: snmpsim is single-threaded; a delay
  in one device would block the others.

### 6.2 Commands

- `labsim up [scenario]` — generate the scenario data, aliases if needed,
  processes at step 0.
- `labsim down` — stop processes, remove aliases.
- `labsim status` — device, IP, PID, current step, current fault.
- `labsim step <n>` — `snmpset` every control OID of every changing
  device, then read each back and check it.
- `labsim fault <device> <fault|none>` — §6.3.

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
2. `labsim step` changes the data seen by the next walk.
3. The control OID does not appear in the walk of the template OID set.

## 7. Zabbix provisioning

- From `hosts.json`, create or update one host per `monitored: true`
  device through the API. Match by host name. Tag `lab.managed=1`. A host
  that exists without the tag follows the adoption rule of §2.
- SNMP interface: device IP, port `settings.snmp_port` (1611 in the lab;
  161 needs root), SNMPv2c, community from `lab.yaml`.
- Link "Topology by SNMP" (`transport: lld`) or "Topology by push"
  (`transport: push`). Set the proxy from `lab.yaml`.
- `provision --prune` deletes `lab.managed=1` hosts that are not in
  `lab.yaml`. No other deletes.

## 8. Scenario runner

### 8.1 Step loop

For each step:
1. `labsim step <n>`; apply the step's deviations and faults (faults to
   clear first, then the step data, then new faults). A
   `zabbix_manual_link` deviation enabled by the step is created after the
   poll and before ingest.
2. Make Zabbix poll now: API `task.create` ("check now") on the master items
   of the lab hosts the step changes (all of them at step 0). **One master
   item at a time**, in a fixed order (host name, then `core`, then `fdb`),
   each started in a later second than the previous snapshot's clock. Reason:
   ingest counts a Device as updated when its attrs change, and `last_seen`
   is the snapshot clock, so two clocks that tie in one run and not in the
   next change the counters. Faulted devices are polled last, only to see
   the failure. For `push` hosts, run `push.py` for these hosts. Status
   (push): not implemented — the runner reports an error for `push` hosts.
3. Wait until the snapshot `clock` of each rule of the polled master item
   advances in `topo_lld_snapshot` (timeout configurable, default 120 s).
   For a fault step: a timed-out SNMP poll does not make the item
   unsupported; Zabbix sets `errors_from` and `disable_until` on the
   interface. The runner counts item `state` = unsupported, a non-empty
   item or interface `error`, `available` = unavailable, or `errors_from` /
   `disable_until` ≠ 0 as "the poll failed". `wait.seconds`: Status: not
   implemented.
4. Run ingest the same way as the existing test suites (full run).
5. Run the step's assertions (§8.3).
6. Write the step result to the report.

**Scheduled polling.** Before the first step the runner sets the update
interval of the master items of the lab hosts to the API maximum (`1d`),
and writes the original intervals to `run/schedule.json`. After the run —
also on failure (`finally`) — it restores them. A killed run is restored by
the next `labsim run` or by `labsim restore`. Reason: a poll that Zabbix
schedules itself between two steps writes a snapshot the runner did not ask
for, and the report is no longer reproducible.

### 8.2 Reset

`runner --reset` clears `topo_*` state the same way as the "clean `topo_*`
state" of the equivalence tests. Only on the `zabbix` database. Without
`--reset`, the runner continues from the current state.

### 8.3 Assertions

Natural keys (sysname or chassis, port name), never database ids. Key
names are the names of the model spec.

```yaml
assert:
  - link: [Switch1:Gi0/3, Printer1]          # far end as Device or Device:port
    state: active                             # active | superseded | stale | absent
    type: physical_link                       # optional: physical_link | device_link
    discovered_via: manual                    # optional
    far_port_reason: port_unmatched           # optional, device_link only
  - observation: {reporter: Switch1, port: Gi0/5, remote: Printer1}
    outcome: ambiguous
    precision_lower: true                     # optional; observations only (model §7.1)
  - edge_id_same_as: {step: 0, link: [Switch1:Gi0/2, Printer1]}
  - link_attr: {link: [Switch1:Gi0/7, AP2], attr: last_seen_port, vs_step: 2, is: gt}   # gt | eq
  - snapshot_unchanged: {host: AP1, since_step: 0}
  - status: {field: manual_links_contradicted, equals: 1}
  - observation_count: {outcome: shadowed, edge: [Router1:Gi0/1, UPS1:eth0], equals: 3}
  - contradiction: {link: [Router1:Gi0/1, UPS1:eth0], hidden_neighbors: [Printer1, Switch2]}
  - badge: {on: UPS1, present: false}
  - matches_reference: reference-s0.json      # S0: natural keys of the old lab
  - pending: "reason"                         # reported, not evaluated
```

Status (names): the tool still uses `via`, `reason` and a separate
`link_absent` form; they are renamed to `discovered_via`,
`far_port_reason` and `state: absent`, with no aliases. Status:
not implemented: `state: stale`, `observation_count`, `contradiction` and
`badge`.

- Read from the `topo_*` tables and the ingest status file, and, for
  derived state (`stale`, contradiction, badges), from the read-only helper
  of §11. The UI endpoints need a browser session; the runner has none.
- State, outcome and field names come from the model spec. Do not invent
  names. A check that needs something no spec defines is marked `pending`
  with a reason.
- `contradiction` and `badge` read the derived state that the UI uses
  (model §9.4, §12.4).

### 8.4 Report

- Console: scenario, step, pass / fail / pending per assertion.
- JSON report with the same data, plus snapshot clocks and ingest status
  per step, `schedule_suspended` (§8.1) and, when implemented, the
  freshness macro state (§8.5).
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
- A killed run is restored by the next `labsim run`, or by `labsim restore`.
  `labsim restore` restores the macro and the item intervals of §8.1
  (today only the intervals).
- The report shows the macro value before, during and after.

## 9. Scenarios

### S0 — Baseline and the manual-link case

| Step | Change | Check against |
|---|---|---|
| 0 | baseline: the current lab, no manual link | a fresh ingest gives the same Devices, Ports, links and `represented_by` targets by natural keys as `reference-s0.json` (the state of the old lab, which had no manual link) |
| 1 | deviation `router1-gi01-manual-vs-lldp` enabled: manual Router1:Gi0/1 ↔ UPS1:eth0, UPS1 with LLDP | model §15 "UPS1 with LLDP": three shadowing observations; hidden neighbors Printer1 and Switch2; `manual_links_contradicted` = 1 |
| 2 | deviation `ups1-no-lldp` (`omit_mib: lldp` on UPS1) enabled; the manual link stays | model §15 "UPS1 without LLDP": two shadowing observations (Printer1 near end, Switch2 third party); no badge on UPS1 |

- Built: steps 0 and 1 (assertions: the manual link is active; the
  Printer1 observation is `shadowed`; `manual_links_contradicted` = 1).
  Status: not implemented: step 2, and the hidden-neighbor, badge and
  observation-count assertions of step 1 (they need model §9.4 third party
  and the helper of §11).
- The old `snmpdata/*` files were replaced by generated files after step 0
  passed (a copy is kept outside the repository).
- Until model §9.4 (third party) is implemented, the third-party
  assertions are expected to fail; that is a valid result.

### S1 — Recabling, flicker, return

| Step | Change | Check against |
|---|---|---|
| 0 | baseline: Printer1 on Switch1:Gi0/4 (the patch gives Switch1 the ports Gi0/4 and Gi0/5 and moves Printer1 off Router1:Gi0/1) | — |
| 1 | Printer1 moves Gi0/4 → Gi0/5 | model §8.4: old link superseded, new link active |
| 2 | Printer1 absent from LLDP for one poll | model §8.7: link stays, no replacement |
| 3 | Printer1 back on Gi0/5 | no new edge; state as in step 1 |
| 4 | Printer1 back on Gi0/4 | model §8.4 revival: same edge id as step 0 |

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
| 2 | AP2 (`monitored: false`) on Switch1:Gi0/7 | AP2 is not a reporter → port from the advertisement, `physical_link` (model §8.1) |
| 3 | a second AP2 port on Switch1:Gi0/8 advertises the same port id | model §8.3: Switch1:Gi0/7 ↔ AP2 stays `physical_link`, same edge id as step 2, observation `precision_lower: true`, `last_seen_port` unchanged; Switch1:Gi0/8 is a `device_link`, `port_shared_id` |
| 4 | shared id removed (second port gone) | `physical_link` unchanged (same edge id), observation `precision_lower: false`, `last_seen_port` advanced |

- All steps pass (model §8.3 is implemented). The earlier step that tested
  the old precision downgrade was removed.

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
- `labsim down` leaves no processes and no IP aliases.
- The generator rejects the invalid inputs of §5.4.
- The tool refuses to start when the database name is not `zabbix`.
- No host without `lab.managed=1` is changed.
- Verified: S0–S3 run end to end; S3 twice from `--reset` gives equal
  reports.
- Not implemented yet: the renamed assertion keys and `stale`,
  `observation_count`, `contradiction`, `badge`; S0 step 2; the freshness
  override with restore after failure and after a killed run (§8.5, S4).

## 11. Open questions

- **Reading derived state — decided (2026-10-03).** A read-only PHP
  helper in the lab directory loads `CTopologyPrototype` against the real
  `zabbix` database, stubs only the API calls that do not affect `stale` or
  contradiction, writes nothing, and prints JSON for the assertions
  `stale`, `contradiction` and `badge`. Status: not implemented.
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
