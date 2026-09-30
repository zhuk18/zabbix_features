# "SNMP walk to topology rows" — golden tests

Tests of the native preprocessing step of `topology-lld-part3-spec.md` (`src/libs/zbxpreproc/preproc_topology.c`).
The reference is the temporary JavaScript of Part 2 (`../lld_js`): on the same walk the step must give the same rows.

| File | What |
|---|---|
| `driver.c`, `build.sh` | a standalone driver of the step, linked against the built tree (`make` the server first) |
| `compare.php` | the golden test: the step on every walk of `../lld_js/fixtures` and `fixtures/` against `expected/` |
| `edge_cases.php` | spec §6.2: anchors and `missing_mib`, garbage, MAC limit, escaping, parameters |
| `make_synthetic.php` | writes `fixtures/synthetic-*.walk` (what the lab walks lack: subtypes, FDB, LAG, CDP, Hex-STRING ids) |
| `expected/*.json` | the JS output for each walk and source; `*.cdp.json` are written by hand (the JS has no CDP) |

    ./build.sh                      # builds ./topo_step
    php compare.php                 # step vs expected (and expected vs the live JS when zabbix_js is built)
    php compare.php --generate      # rewrite expected/ from the JS; the diff is the review
    php edge_cases.php

Rows are compared as sets. The step orders rows by ifIndex, the JS by ifIndex or by the index string; ingest sorts
again before hashing (Part 2), so the order carries no meaning.

The step's own unit cases (YAML, run by `zbx_item_preproc`) are in `tests/libs/zbxpreproc/zbx_item_preproc.yaml`.
That framework needs cmocka and libyaml; on a machine without them the same walks and parameters are covered here.

## Where the step follows the JS instead of the spec

Spec §2 says that where it and the JS disagree, the JS wins until someone decides otherwise. Decisions taken while
implementing (the first two by the maintainer, the rest are consequences); each is one place in the C file:

| Point | Spec | JS = step |
|---|---|---|
| LLDP neighbor whose local port cannot be resolved | dropped and counted (§3.3) | emitted **without** `{#IFINDEX}`, so the contract check of Part 2 reports it in the rule's info text. This also answers the "dropped rows" open question of §9 for LLDP. |
| `{#LOC_CHASSIS}` | added to every row of every source (§3.3) | rows of PORTS and NEIGHBORS only, not FDB and LAG |
| local chassis id | `lldpLocChassisId` | `lldpLocChassisId`, else the MAC of the lowest-ifIndex port that has one |
| management address | "IPv6 only if no IPv4 (as the JS does)" | IPv4 only; the JS does not read IPv6 |
| `{#IFSPEED}` | falls back to `ifSpeed`, converted | `ifHighSpeed` only; nothing when it is absent |
| ids, MACs | one normalization: lowercase, colon, 6 octets (§4.2) | Hex-STRING → lowercase colon hex; a text MAC (`STRING: 0:11:..`) is kept as received; a networkAddress chassis id is decoded only when it is a Hex-STRING IPv4 |
| quoted strings | trailing spaces trimmed | leading and trailing white space is trimmed (the JS trims before removing quotes) |

Changing any of these changes the identities or rows that existing `topo_*` data was built from: it needs a decision
and, for identities, a migration or a one-time re-ingest (spec §9 "Normalization vs existing data").

Additions that do not change any JS output:

- the LLDP anchor is `lldpLocPortTable` **or** `lldpRemTable` (the JS works on a device that only has the latter);
- `# comment` and blank lines in front of the walk are ignored, so the bridge master item of the template can keep
  storing `# device has no bridge tables` for a device without bridge tables;
- an unparsable walk is always an error, whatever `missing_mib` says.

An older proxy that does not know the step type answers "unknown preprocessing step" (`pp_execute.c`): the rule
becomes unsupported with that message and keeps its last snapshot. Nothing crashes and no step is skipped.

## Identity normalization: what is and is not normalized

A remote chassis id reported by device A must be byte-identical to device B's own `{#LOC_CHASSIS}`, otherwise the
neighbor becomes a second Device. The lab equivalence check cannot show this: its reference is the JS, with its gaps.
Facts below are outputs of the step (= the JS) on probe walks; `ingest.php` lowercases a chassis id only when it is
already `aa:bb:cc:dd:ee:ff` (`$looks_like_mac`), and matches `chassis_id` as received (binary collation).

| Case | Output | In the lab? | Risk |
|---|---|---|---|
| MAC as Hex-STRING, any case (chassis type 4, or no type row) | lowercase colon hex | **yes, all of it** (13 chassis ids, 6 local, 18 port MACs) | none |
| MAC as text `"AA:BB:.."` (STRING) | kept as received, UPPER | no | remote `AA:..` vs local `aa:..` are two Devices unless ingest's `strtolower` catches it (it does for the padded colon form) |
| MAC as text unpadded `0:bb:cc:0:0:a` | kept as received | no | never matches; not even recognised as a MAC by ingest |
| MAC with hyphens / dots `aa-bb-..` | kept as received | no | never matches |
| chassis type 5 (networkAddress), IPv4, Hex | dotted IPv4 | no | remote side only, see next row |
| **local** chassis that is a networkAddress | raw colon hex `01:c0:a8:01:0b` (the local id is not decoded by subtype; `lldpLocChassisIdSubtype` is not even in the walk) | no | its neighbors report `192.168.1.11`: two Devices |
| chassis type 5, IPv6, Hex | colon hex with the family byte `02:20:01:..` | no | never matches an IPv6 address written any other way |
| chassis type 6 / 7 / others (text) | trimmed only, case kept | no | local and remote spell it the same way in practice; not guaranteed |
| no `lldpLocChassisId` | MAC of the lowest-ifIndex port | no (all lab devices have it) | a port MAC is not the chassis id the neighbors see: two Devices |
| management address IPv6 (LLDP) | not read | no | none for LLDP (identity is the chassis id) |
| CDP neighbor without IPv4 | no `{#REM_MGMT_IP}` for IPv6 is emitted only when the address type is 20 and 16 octets; nothing else | no | CDP has no chassis id: identity is the management IP and the name, so an IPv6-only CDP neighbor rests on the name |

The lab uses only the first row, so the equivalence result holds for it and says nothing about a mixed fleet. To make
identities safe on one, decide before this leaves the prototype:

1. Read `lldpLocChassisIdSubtype` and decode the local id with the same function as the remote one.
2. One normalizer for MAC-like text (lowercase, colon, padded to two digits) applied to local and remote ids, in the step
   (changes ids of Devices that were created from text MACs) or in ingest.
3. IPv6 network addresses in dotted/compressed form for both chassis ids and CDP management addresses.

Each changes existing `topo_nodes.chassis_id` values, so it needs a one-time re-ingest or a migration (spec §9).

## `CHECK_NOT_SUPPORTED` on the bridge master (checked live)

The template's bridge master turns the error `No Such (Instance|Object)` into the comment `# device has no bridge tables`,
which the step reads as "no bridge tables" (an empty snapshot). The step is `match type 0` (error matches the regular
expression), not "any error", so other errors stay errors. Two runs against a simulated device with real FDB data
(3 + 25 MACs, one trunk over the limit):

- the device stops answering (timeout): a network error, the master gets **no value**, the snapshot (4 rows) keeps its
  `rows_hash` and `clock`; nothing empties `learned_macs`;
- the device answers `No Such Instance` for the bridge tables: snapshot `[]`, rule supported. That is "there is
  nothing", which is what it says.

Removing the step and relying on `missing_mib = empty` would not work: a master that fails has no value at all, so
the rule would never run and the last snapshot would stay forever after a device really drops its tables.

## Acceptance record (lab, 2026-09-30)

Run on the snmpsim lab (5 hosts, 127.0.0.2-6:1611) with the rebuilt server (the old binary is kept as
`sbin/zabbix_server.old-20260930`). Clones of the lab hosts on the native template ("Topology by SNMP") were
compared with the originals on the temporary JS template, so neither side was unlinked.

| Criterion (spec §8) | Result |
|---|---|
| golden fixture × source = JS output | 126 checks (`compare.php`), edge cases 56 (`edge_cases.php`) |
| snapshots JS vs native, per host and role | `rows_hash` identical for all 5 hosts × ports / LLDP / FDB / LAG |
| ingest from a clean `topo_*` state, by natural keys (`natural_keys.py`) | same 9 Devices, 19 Ports, 8 links, 12 observations, 5 `represented_by`; only the device `sysname` of the two clones differs, because ingest takes it from the Zabbix host name ("E2E native UPS1") |
| device without CDP / FDB / LAG MIB with the template linked | rules supported, empty snapshots (lab devices have none of them) |
| wrong community: master gets no value, rules keep snapshots | interface unavailable, `rows_hash` and `clock` of all rules unchanged; data flows again after the fix |
| Test dialog, ports rule, role PORTS | Rows: 6, passing: 6, failing: 0 |
| Test dialog, LLDP rule, role NEIGHBORS, no LLD macro paths | Rows: 5, passing: 5, failing: 0 |
| garbage walk in the Test dialog | step error "cannot parse the SNMP walk: invalid OID format", not `[]` |
| API rejects the step on items and item prototypes; invalid `source` / `missing_mib` / `mac_limit` | `testDiscoveryRuleTopologyStep` |
| export → import round trip of the template | every step and parameter preserved (5 rules) |
| template: masters without discard steps, one step per rule, no JS, no macro paths | `testTopologyBySnmpTemplate` |

Not verified:

- **Proxy-monitored device:** no proxy in the lab. The step is in the shared library, and an older proxy answers
  "unknown preprocessing step" (seen on the old server binary through the Test dialog).
- **LAG with data on a live server:** the lab devices have none. FDB with data and a trunk over the limit was run live on
  a simulated device (see above); LAG is covered by the synthetic golden walks only.
- **The YAML unit cases** in `tests/libs/zbxpreproc/zbx_item_preproc.yaml` were run through `driver.c` by hand, not by
  cmocka (cmocka and libyaml-dev are not installed here).
