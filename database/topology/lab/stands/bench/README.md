# Stand `bench` (lab spec 5.6, B1; task 126)

A generated network at the size of the release 1 target (FR-0 decision 16): 1 000 reporters, 50 000 ports, 20 000 neighbor rows, a full run in 5 minutes. No snmpsim and no SNMP
rule is polled: a host for each reporter (tags `lab.managed=1`, `lab.stand=bench`, host group `lab-bench`, an SNMP interface on `198.18.x.y` that nothing answers) and four rules of the type
Zabbix trapper (Device, Ports, Neighbors LLDP, Neighbors CDP: keys `topology.bench.*`); the rows are sent with `zabbix_sender -T` and the server writes the snapshots by its normal path.

```
python -m labsim bench gen stands/bench/bench.yaml [--reporters N] [--neighbor-rows N] [--out DIR]
python -m labsim bench run stands/bench/bench.yaml --reporters 100 --neighbor-rows 78 --name B1-100    # B1 steps 0, 1, 2; report in reports/B1-100.json
python -m labsim bench shares        # the shares below, from the stand that is in the topo_* tables
python -m labsim bench remove        # the bench hosts (tag lab.stand=bench) and their group
```
`bench run` empties the `topo_*` tables (a reset, lab spec 8.2), makes the hosts and rules, then for each step sends the rows and starts one run of the ingest CLI. It reads the result from
the run summary (the status file of the CLI), not from the tables. It removes the hosts at the end unless `--keep`. Only the `zabbix` database. The bench and a lab stand are not up at the
same time (`labsim down` first); after a bench run restore R1 (`labsim run R1 --reset` and `up R1`).

Steps: 0 = all rows into an empty topology (cold run); 1 = the same rows again with a new clock (warm run); 2 = 5 % of the end device cables moved (the end device is on another access
switch, the rows of both ends change).

## Shape (measured, 2026-11-26)

`bench shares` on R1 (14 reporters, restored; the Zabbix snapshots) and `corpus/out/identity.json` (128 vendor walks of `corpus/librenms/`, 1 065 LLDP and 114 CDP rows):

| Share | R1 | Corpus | Default (`bench.yaml`) | Source |
|---|---|---|---|---|
| ports per reporter | 51.6 | – | 50 | R1 |
| neighbor rows per reporter (LLDP + CDP) | 11.4 | – | 20 | the target (20 000 rows / 1 000 reporters); R1 is a small office |
| reporters that also report CDP | 6 of 14 (0.43) | 15 of 123 walks (0.12) | 0.43 | R1 (a real office with HP/Aruba switches; the corpus is a collection of single devices) |
| CDP rows of a CDP reporter's end devices | 45 CDP rows for 79 end device rows (0.57) | – | 0.5 | estimate from R1 |
| LLDP rows to end devices (not a reporter) | 79 of 115 (0.69) | – | by the tree (0.89 at 1 000 reporters) | R1 is a small office: its inter-switch share is high; the tree gives most rows to end devices |
| rows without a hardware ID | 0.278 | 0.304 | 0.28 | R1 and the corpus agree |
| ports with several neighbors (junction) | 1 of 114 (0.009) | – | 0.01 | R1 |
| shared hardware IDs | 0 | – | 0.001 | **estimate**: R1 has none; the corpus walks are single devices |
| CDP-only devices (not in an LLDP row) | 27 of 40 CDP devices, an upper bound (0.25 of all neighbors) | – | 0.05 | **estimate** |
| rows `local_port_unresolved` | 0 of 115 | 95 of 1 065 (0.089) in 5 of 123 walks | 0.024 | the corpus rate over the reporters of those families (0.04 x about 0.6) |

R1 has too few cases of junctions, shared IDs, CDP-only devices and unresolved ports, and the corpus is one device per walk, so the last four shares carry the weight of an estimate; a
network without these cases would measure a run that is too easy, so they are not zero.

## Reference machine

Part 2 of task 126 runs on the reference machine, which Dima sets up (`126-machine.md` in the handoff folder). Not set up yet; the machine is recorded here when it is (CPU, RAM, disk,
OS, MySQL version, `innodb_buffer_pool_size`, PHP version). The measurements of part 1 are on the dev VM: 4 CPUs, 15 GB RAM, PHP 8.5.4, MariaDB 11.8.6; the VM also runs the editor and the
Zabbix server, so its times are only for comparison with model §6.1.
