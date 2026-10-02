# Topology lab simulator

Build spec: `topology-lab-simulator-spec.md` (repository root).

```
lab.yaml + scenarios/*.yaml ──► labsim gen ──► data/<scenario>/<device>/<community>.snmprec (+ <subtree>/NNNNN.snmprec)
                                labsim up/down/step/fault   one snmpsim process per device
                                labsim provision            Zabbix hosts (API)
                                labsim run                  step loop: switch data, poll, ingest, assert, report
```

## Setup

```
python3 -m venv --system-site-packages ~/work/labenv        # PyYAML comes from the system
~/work/labenv/bin/pip install -r requirements.txt           # snmpsim is pinned to 1.2.2 (see below)
```

`labsim` starts the snmpsim from the repository's `.venv` (`settings.snmpsim_bin` overrides it). That venv stays stock.

## Commands (run from this directory)

```
python -m labsim gen S1                 # write data/S1 and hosts.json
python -m labsim export-walks S1 2      # <scenario>.<device>.step2.synthetic.walk files
python -m labsim up S1 / down / status  # start / stop the processes, show pid, step, fault
python -m labsim step 3                 # snmpset every control OID to 3, read each one back
python -m labsim fault AP1 unreachable  # unreachable | none
ZBX_TOKEN=... python -m labsim provision S3 [--prune]
ZBX_TOKEN=... python -m labsim run S1 --reset [--timeout 120] [--report path]
python -m labsim compare-reports a.json b.json    # equal except clocks and ids
python3 -m unittest discover -s tests             # generator, validation, DB guard
python3 -m unittest tests/test_labmultiplex.py    # the snmpsim variation module (needs snmpsim + net-snmp)
```

`run` needs `zabbix_server` running and the lab hosts provisioned. The API token is read **only** from the `ZBX_TOKEN`
environment variable; it is never written to a file. Exit code 0 only if every non-pending assertion passed and no step had an error.

## Rules the tool keeps

- Only the `zabbix` database (`settings.database`; the tool refuses to start otherwise, and checks `SELECT DATABASE()`).
- Zabbix configuration changes go through the API. Hosts get the tag `lab.managed=1`. A host without the tag is only touched
  when it is listed in `settings.adopt`, and its configuration is exported (`configuration.export`) to
  `settings.export_dir` (default `~/work/lab-backup`) first. `provision --prune` deletes only tagged hosts.
- Writes to `topo_*`: `run --reset` (clear, spec §8.2) and the `zabbix_manual_link` deviation (one `topo_edges` row, the way
  `test_manual_contradiction.php` creates a manual link; the UI action needs a browser session).
- No topology code is changed.

## snmpsim: why `labmultiplex`

snmpsim 1.2.2 `multiplex` has two bugs: step files are indexed in `os.listdir()` order, and SET on the control OID raises
`TypeError`. `variation/labmultiplex.py` is a copy with both fixed; generated files use `:labmultiplex`. Keep snmpsim at 1.2.2.
The control OID of a subtree sits **inside** that subtree (`<root>.99999.0`); snmpsim routes a request to the module only
for OIDs under the record's subtree, and the control OID is not in the step files, so walks never return it.

## Faults

`unreachable` stops the process (SIGSTOP; SIGCONT clears it). Spec §6.3 describes `delay` records, which cannot sit inside a
`labmultiplex` subtree. `slow`, `wrong_community`, `lldp_error` are not implemented (no v1 scenario uses them).

## Files

- `lab.yaml` — the current lab, one-to-one with the old `snmpdata/*` fixtures (S0 proves it against `reference-s0.json`).
- `scenarios/S0..S3.yaml` — steps and assertions. A scenario may carry a `patch:` (devices, ports, cables, deviations)
  applied before step 0.
- `reference-s0.json` — natural keys of the `topo_*` state the lab produced before this tool existed.
- `reports/` — run reports (JSON); `data/`, `run/` — generated, not tracked.
