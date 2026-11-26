# Stand `corpus` (lab spec 9 C1; task 127)

The 128 real walks of `corpus/librenms/` (LibreNMS, GPLv3, internal lab use only) replayed **one at a time** through the real path: snmpsim -> Zabbix with the native step -> snapshot -> run. This folder has
tools, the lab file template and results only; **the walks are read from `/media/sf_topology-specs/corpus/librenms/` and are never copied into this repository.**

```
python -m labsim c1 run [--files aos,zynos] [--result reports/C1.json]   every walk, one at a time (about 37 s each); keeps going after a failed walk; the result is written after each walk
python -m labsim c1 baseline [--result reports/C1.json]                  write stands/corpus/baseline.json from a complete result
python -m labsim c1 compare  [--result reports/C1.json]                  only the differences against the baseline; no output = no difference
python -m labsim c1 analyze  [--result reports/C1.json]                  corpus_tool.py analyze (a separate implementation) against the run, per walk: reports/C1-analyzer.json
```
For each walk: the walk is one replay device on the lab address `192.0.2.201`, provisioned alone with the templates of the lab ("Topology by SNMP" rules and "Network Generic Device by SNMP"),
polled once, one run of the ingest CLI, the result read (the state and the rows of every rule through the API, the run summary of the CLI, the identity keys of the reporter's Device from the tables),
the host removed. Every host carries `lab.stand=corpus` and is removed after its walk (`delete_hosts`). Only the `zabbix` database. Reason for one walk at a time: walks from different
contributors can share anonymized or cloned identifiers; together they would make duplicates that no real network has.

## Changes to a walk (the same for every walk)
Made in memory when the device is generated (`corpus_records`, `lines`), only to make the walk a valid replay device; nothing is written back to the corpus:
1. the end record the end record `LAB_TAIL.0 = "end of lab data"` is added (lab spec 5.2);
2. `ifIndex.N = N` is added for every row of `ifTable` and `ifXTable` (the corpus does not record that column; `--ifindex` of `corpus-smoke`): without it the native step finds no ports and every LLDP row
   would be `local_port_unresolved` (`no_ports`);
3. the OIDs are written sorted numerically (snmpsim needs it).

**Not changed** (the task and lab 9 C1 name them; the existing generator does not do them): `sysName` stays what the walk has (the host is named by the file name), and the SNMP interface address is not
written into the management address tables of the walk. Effect: a walk whose neighbors name the reporter by an address that no host has cannot show that join in one-walk replay; the identity of the
reporter's own Device comes from its chassis (`lldpLocChassisId`), or it has none (see the report).

A walk that cannot be replayed (a line that does not parse, no records) is a record with `status: failed` and the reason; it is not skipped.

## `C1-core` (lab spec 2.42 §9 C1; task 135)
A short set for level 3: for each OS family the walk with the most neighbor rows (LLDP plus CDP; a family with none has none; the first name on a tie), plus `slxos_slx9150`, `aos7`, `ciena-sds`, `vrp_5720`,
`ciena-waveserver` and `eltex-mes23xx_mes2324fb`. **48 walks** (47 families have neighbor rows; the six named walks are in them, except `eltex-mes23xx_mes2324fb`, which is added), about 30 minutes. The list is
`core.txt` (made by `labsim.c1.core_walks()` from `baseline.json`; `tests/test_c1.py` checks it), its own baseline is `baseline-core.json` (the same record of each walk as `baseline.json`).
```
python -m labsim c1 run --core        the walks of core.txt; result reports/C1-core.json
python -m labsim c1 compare --core    only the differences against baseline-core.json
python -m labsim c1 baseline --core   write baseline-core.json from a complete core result
lab/run_c1_core.sh [full]             the corpus stand needs the office stand down: takes it down, runs, compares, restores R1 (about 5 minutes); `full`: all 128 walks against baseline.json
```
