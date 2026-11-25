# Lab stand "office-demo" — the office stand with a few synthetic rows, for the product owners

A copy of the stand `../office/` (12 real switches of the capture of 2026-10-14, 2 synthetic gateways) with **one** synthetic block of LLDP rows, and the scenario `D1` that
shows six typical network situations one step at a time (lab spec 9 D1). `../office/` and its scenario R1 stay unchanged. The script for the person who shows it is
[DEMO.md](DEMO.md).

## Files

| Path | What |
|---|---|
| `lab-office-demo.yaml` | the lab file: the 14 replay devices of `../office/lab-office.yaml` (same names, same addresses 192.0.2.N), its deviations, and two more: `link-down-sw12-42`, `link-down-sw13-48` |
| `scenarios/D1.yaml` | the demo scenario, 7 steps (0 to 6) |
| `tools/add_demo_rows.py` | builds `data/` from `../office/data/` and adds the synthetic rows (below) |
| `data/` | built, not in the repository (the lab `.gitignore` ignores `data/`); `python3 tools/add_demo_rows.py` makes it |
| `DEMO.md` | the demo script: command, where to look, one plain line per step |

## Build

```
cd database/topology/lab/stands/office-demo
python3 tools/add_demo_rows.py          # needs ../office/data (see ../office/README.md)
```

`data/manifest.json` gets the key `synthetic_rows` on `SW17-09-ACC-2530-48G` (the same list as below).

## Synthetic rows (every one)

Only the file `data/SW17-09-ACC-2530-48G/zbxlab.snmprec` differs from the office stand: 27 records (9 columns × 3 rows) are added to `lldpRemTable` on the local port 16
(ifIndex 16, ifOperStatus up, no `lldpRem` and no `cdpCache` row in the capture). The rows copy the form of real rows of the same switch.

| remIndex | Device | form copied from | chassis id (subtype) | port id (subtype) | other columns |
|---|---|---|---|---|---|
| 1 | `DEMO-PC1` | the Windows hosts of SW17 (port 3) | `DEMO-PC1` (7, local) | `02:de:00:00:00:11` (3, MAC) | no port description, no sysName, no sysDesc, capabilities `00`/`00` |
| 2 | `DEMO-PC2` | the same | `DEMO-PC2` (7, local) | `02:de:00:00:00:12` (3, MAC) | the same |
| 3 | `DEMO-PRN1` | the Android printer on SW17 port 37 | `02:de:00:00:00:03` (4, MAC) | `02:de:00:00:00:13` (3, MAC) | port description `eth0`, sysName `DEMO-PRN1`, sysDesc `DEMO printer (synthetic)`, capabilities `39`/`01` |

- Invented: the three names, the locally administered MACs `02:de:00:00:00:xx`, the printer sysDesc. No management address (the real rows of this switch have none in the lab range).
- The rows are in the data from step 0 (a replay device takes only `omit_mib` and `override_oid`, lab spec 4.4), so the Junction projection on SW17 port 16 is there from the first step.
  Step 2 of the demo only points at it.
- Everything else of the stand is real or is synthetic in the same way as in `../office/` (the gateways, the restart of NA12501003431).

## The ports of the demo (task 104 section 0)

| Step | Port | Why |
|---|---|---|
| 1 link down | `SW12-10-CORE-6000-48G:1/1/42` – `SW13-10-ACC-6000-48G:1/1/48` | a switch-to-switch LLDP link, both ends confirmed in the capture (both ends have the row), not on the core–gateway LAG (SW12 1/1/37–40) |
| 2 unmanaged switch | `SW17-09-ACC-2530-48G:16` | HP 2530, up, no neighbor row in the capture; SW17 has few links, so the Junction is easy to see |
| 3 one device looks like three | `SW18-09-ACC-2530-48G:6` (the MikroTik) | three Devices until the operator merges them; `merge_group: {sysname: MikroTik}`, `keep: {chassis: "64:d1:54:69:9f:cf"}` — the key of the Device of the MAC chassis (the Devices have no link, so the usual `keep: <Device:port>` can not name it) |
| 4 a device was replaced | `SW15-10-POE-6000-24G:1/1/1` | the step 3 deviation of R1 (new chassis of NA12501003431) |
| 5 a switch stops answering | `SW21-07-ACC-2530-48G` | an access switch with one uplink (to SW20:42) and two endpoints: its links are easy to see |
| 6 a router without LLDP/CDP | `SW22-07-ACC-2530-48G:41` | up, no neighbor row in the capture (a real port, not changed); SW22 is not the switch of step 5 |
| 7 two ends disagree on speed | `SW15-10-POE-6000-24G:1/1/21` | the deviation `speed-mismatch-sw15-21` (`ifHighSpeed.21` = 100) |
| 8 LLDP and CDP disagree on the remote port | `SW16-09-ACC-2530-48G:48` (uplink to `SW18-09-ACC-2530-48G:44`) | HP 2530, LLDP and CDP both report SW18 on this port; the deviation `cdp-port-differs` sets `cdpCacheDevicePort.48.1` to `40` (a real port of SW18 with no neighbor; the LLDP row keeps `44`). A deviation, the capture file is not changed; the manifest of the stand lists it (task 130) |

## Switching between stands

Both office stands use 192.0.2.0/24 and the same host names (the same Zabbix hosts), so one of them is up at a time (`labsim status`). To switch: `labsim down`, then `labsim show` of the
other. `provision` of the stand that starts sets the host tag `lab.stand` to its name (`lab-office-demo`, `lab-office`); a host of the other stand is never pruned (lab spec 2, 7).
