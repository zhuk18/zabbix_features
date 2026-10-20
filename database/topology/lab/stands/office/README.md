# Lab stand "office" — replay of a real capture

Real SNMP data of 12 office switches (capture 2026-10-14, see
`../analysis/REPORT.md`) and 2 **synthetic** gateways, served by snmpsim
as lab devices. This is the first lab set with real device pairs. Lab spec §11 "Real captures" asks
to keep real and synthetic sets apart: this stand does not use the
generator.

## Content

| Path | What |
|---|---|
| `data/<device>/zbxlab.snmprec` | one snmpsim file per device (community `zbxlab`): 12 switches from the capture, 2 gateways synthetic |
| `data/manifest.json` | per device: source, captured and lab address, record count, changes; `synthetic: true` for the gateways |
| `lab-office.yaml` | lab description: 14 replay devices, deviations `no-cdp-*`, `na-reboot-sw15-1-1-1` |
| `scenarios/R1.yaml` | scenario R1, 4 steps, 284 assertions |
| `tools/walk2snmprec.py` | converter (capture → snmprec) |
| `tools/gen_gateways.py` | writes the two synthetic gateway files |
| `tools/make_stand.py` | writes `lab-office.yaml` and `R1.yaml` from the expected result |

## Synthetic part

The capture has no data of the gateways and no second capture after a
restart. These parts are invented. They are kept consistent with the
capture:

| Item | From the capture (kept) | Invented |
|---|---|---|
| `zbx-office-gw1`, `zbx-office-gw2` | sysName; chassis `84:39:8f:37:3a:7c` / `84:39:8f:1b:a3:28`; port ids `port1`, `port2` (interfaceName); cables to SW12 1/1/37–40; LACP system id `…:3a:82` / `…:a3:2e` (SW12 partner id); no management address towards SW12 | sysDescr "FortiGate-100F v7.2.8 (synthetic)", sysObjectID, ifIndex values, port MACs, LAG `core-lag` (ifIndex 30, members port1 and port2), HA cable `gw1 ha1 ↔ gw2 ha1`, lab addresses 127.0.17.251 / .252, no FDB, no CDP |
| NA restart (step 3) | device NA12501003431 on SW15 1/1/1 | new chassis `3a:5c:91:e2:07:4b` (locally administered, as the real ones); the IPv6 address in the index keeps the old EUI-64 (an `override_oid` can't change an index) |

Effect of the gateways on the result: the 4 core–gateway links go from
pseudo ports to real ports, both ends confirm them; one more link (HA).
The gateway side has a LAG (`lag_id`), the core side has none (F-3).

## Conversion

`walk2snmprec.py` changes only these things (T1–T6 in the script):

1. Types → snmprec tags. Every octet string → `4x` (bytes as captured).
   `""` → empty `4`.
2. Untyped integers (net-snmp `-Ot`) → TimeTicks `67`.
3. `No Such Object` and `No more variables` lines → removed.
4. **Address remap 192.168.17.N → 127.0.17.N** in the LLDP management
   address indexes (`lldpConfigManAddr`, `lldpLocManAddr`,
   `lldpRemManAddr`), `ipAddrTable`, `ipNetToMediaTable` and the 4-octet
   `cdpCacheAddress`. Other addresses (192.168.88.1, 192.168.131.x,
   192.168.132.4) stay. Reason: the reporter key is the SNMP interface IP
   (`ip:<segment>:<addr>`, model §6.4); a CDP neighbor on HP finds the
   switch only by that IP. Without the remap, all CDP rows to switches
   make new Devices.
5. End record `1.3.6.1.4.1.99999.1.0` (lab spec §5.2).
6. OIDs sorted numerically.

**Check done (2026-10-19).** All 14 files served by snmpsim 1.x on
127.0.17.N:1611, walked with `snmpbulkwalk -v2c` (GETBULK), converted
back to rows and resolved: Devices, links, device links and counters are
equal to the result from the original capture (switches only). The only
differences are the 14 remapped CDP addresses. With the gateways: 14
reporters, 119 Devices, 69 links.

## Use

1. Copy the stand into the lab directory (`database/topology/lab/`),
   for example as `stands/office/`.
2. `labsim run R1 --reset` with `lab-office.yaml` as the lab file.
3. The 127.0.17.0/24 addresses need no alias (127/8).

## Lab tool support

Replay devices, deviations, checks and the end forms are built (task
034, lab spec 2.6–2.10). The explicit keys `{chassis: …}` of `R1.yaml`
need lab 2.10 §8.3 (task 038).

Closed points:

- **Q-1. Natural keys with `:`** — lab 2.6 §8.3 (`{device, port}`).
- **Q-2. Duplicate sysnames** — lab 2.6 §8.3, and explicit keys in 2.10.
- **Q-3. Step 2 outcome of the CDP claims** — model 2.29 §8.5: with an
  active link, a claim of another rule is a §8.4 candidate (`conflict`).
  With the same-port join (model 2.29 §6.7.1) the CDP claims of R1 now
  find the same Device, so they are `applied`, not `conflict`.
- **Rows with a zero chassis (F-2)** — dropped by the server by the
  contract (model §5.2: an empty identity macro is missing). They never
  reach ingest; `rows_no_identity` stays 0. (The first version of this
  README expected 10: wrong.)
- `status` assertions on list fields (`identity_keys_shared`) stay
  `pending`.

## Scenario R1

Expected values for model 2.29 and lab 2.10 (decisions of 2026-10-20).
They need task 038 (ingest). Before 038, R1 fails at least on: twin
Devices of Windows hosts (F-1), names from CDP (F-10), Device churn.

| Step | Change | Expected |
|---|---|---|
| 0 | capture as received + gateways | 93 Devices created (14 reporters + 79); 95 `physical_link` active (13 switch–switch, 4 core–gateway, 1 gateway HA, 51 endpoint, 26 Windows hosts on HP ports); LLDP and CDP rows of a Windows host find one Device (same-port join); Lab-u22 and ND names from LLDP; SW18 port 6 MikroTik `ambiguous` (F-4) |
| 1 | `omit_mib: cdp` on the 6 HP switches | nothing created; the 95 links stay; MikroTik still `ambiguous` |
| 2 | CDP back | same data and same result as step 0; nothing created |
| 3 | NA12501003431 restarts with a new chassis | 1 Device, 1 link created on SW15 1/1/1; old link `superseded`; `address_keys_removed` = 1; the old Device stays without a link (ghost, F-7) |
| 4 | no change, ingest again | nothing created or superseded (task 034 found 26 new Devices per run) |

F-9 (same input, different topology) is gone for the Windows hosts: with
one Device per host, steps 0 and 2 give the same result.

Expected values come from `analysis/tools/resolve.py` with the 2.29
rules (same-port join, LLDP priority), a simplified reading of the spec:
step 0 directly; step 1 with the CDP rows removed (same 95 links); step 3
without history (one new Device, one new link on SW15 1/1/1; the
superseded link and the address key follow from model §8.4 and §6.7.0,
not from the script). A difference in a real run is a question for the
spec, the ingest code or the script.
