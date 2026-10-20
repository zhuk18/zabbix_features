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

## Changes needed in the lab tool (lab spec 2.6, proposal)

The tool as built (spec 2.5) can't run this stand. Proposed text:

> **§4.1a Replay devices.** A device with `replay: <path>` takes its SNMP
> data from that snmprec file. The generator does not write data for it:
> it copies the file to `data/<scenario>/<device>/<community>.snmprec`
> and checks that the file ends with the record of §5.2. A replay device
> has no `ports`, `chassis`, `lldp`, `cdp`, `fdb` keys; cables can't use
> its ports. `zabbix` and `ip` are as for other devices.
> Deviations on a replay device: `omit_mib` (removes the records of the
> MIB subtree: `lldp` 1.0.8802.1.1.2, `cdp` 1.3.6.1.4.1.9.9.23, `fdb`
> 1.3.6.1.2.1.17.4 and 1.3.6.1.2.1.17.7, `lag` 1.2.840.10006.300.43) and
> `override_oid`. Other deviations are rejected (§5.4). A replay device
> with a deviation that changes in a step is a changing device (§5.3);
> its subtree files are cut from the replay file.

Open points for the tool:

- **Q-1. Natural keys with `:`.** §8.3 writes an end as `Device:port`.
  Here Device names (`18:60:24:b2:bd:06`, FortiAP chassis MACs) and port
  names (pseudo ports named by a MAC) contain `:`. `R1.yaml` uses the
  form `{device: "…", port: "…"}` for these ends. Proposal: add this form
  to §8.3.
- **Q-2. Device key for duplicate sysnames.** 12 FortiAP-231K and 6
  FortiAP-231F devices share sysnames; after step 3 two Devices are
  named NA12501003431. §8.3 says "sysname or chassis"; `R1.yaml` uses
  the chassis MAC for them. The runner must accept a chassis value as
  the Device key.
- **Q-3. Step 2 outcome of the CDP claims.** Model §8.5 says the port
  is one set when the neighbors from all rules resolve to two or more
  Devices, and the existing link stays (`applied`, the others
  `ambiguous`). The bullet before it says: "If P has an active link,
  §8.4 applies to each candidate", and §8.4 gives `conflict` (the LLDP
  snapshot supports the link). `R1.yaml` expects `ambiguous`. The model
  spec must say which bullet wins.
- `status` assertions on list fields (`identity_keys_shared`) are
  `pending`.

## Scenario R1

| Step | Change | Expected (by the model spec; my reading) |
|---|---|---|
| 0 | capture as received + gateways | 69 `physical_link` active (13 switch–switch, 4 core–gateway, 1 gateway HA, 51 endpoint); `rows_no_identity` = 10 (F-2); 26 Windows-host ports on HP: two Devices, observations `ambiguous`, no link (F-1); SW18 port 6: MikroTik `ambiguous` (F-4) |
| 1 | `omit_mib: cdp` on the 6 HP switches | 26 new links to the Windows hosts; the 69 links stay; MikroTik still `ambiguous` (two LLDP Devices) |
| 2 | CDP back | same data as step 0, but the 26 links stay active; the CDP claims are `ambiguous` (or `conflict`, Q-3) |
| 3 | NA12501003431 restarts with a new chassis | new Device and new link on SW15 1/1/1; old link `superseded`; `address_keys_removed` = 1; the old Device stays without a link (ghost, F-7) |

Step 2 shows that the result depends on history: steps 0 and 2 have the
same input and different topology (report F-9).

Expected values come from `analysis/tools/resolve.py`, a simplified
reading of the spec (step 0 directly; step 1 checked with CDP rows
removed: +26 links, none lost; step 3 checked without history: one new
Device and one new link on SW15 1/1/1; the superseded link and the
address key follow from model §8.4 and §6.7.0, not from the script). A difference in the real run is a
question for the spec, the ingest code or the script.
