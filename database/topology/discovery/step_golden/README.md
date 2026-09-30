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
