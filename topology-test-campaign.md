# Topology prototype — test campaign

## 0. Objective

Run the scenarios below against the topology prototype, record what actually
happens, and produce a statistics report. The goal is to **find the
limits** of the model and implementation, not to make everything pass.

Read `topology-prototype-spec.md` first. Every rule referenced here (§3
rule 1, rule 4, rule 5, §4.1, §9, …) is defined there. When this document
and the spec disagree about expected behavior, the spec wins — record the
disagreement as a finding.

## 1. Ground rules

1. **Do not modify prototype code** (`ingest.php`, API, UI, schema). You may
   only add test tooling under `tests/campaign/`. If a test cannot be run
   without changing the prototype, mark it `BLOCKED`, explain why, and move on.
2. **Write the expected result before running each test.** Commit it to
   `tests/campaign/expected/<id>.md` (or the results file) *before*
   executing. Never adjust the expectation after seeing the actual result —
   a mismatch is a finding, not a typo.
3. **Isolated state per test.** Every test starts from a known baseline:
   `seed --reset`, then the scenario's setup. Snapshot `topo_nodes`,
   `topo_edges` (and any other topo tables) before and after each step.
4. **Never run against a production Zabbix.** Use the lab / dev instance only.
   If you can't tell which one you are pointed at, stop and ask.
5. If you discover the environment differs from what this document assumes
   (paths, DB engine, push schema, available lab devices), write the actual
   facts into the report's "Environment" section and adapt — don't guess.

## 2. How to drive scenarios

Two injection layers. Pick the lowest one that still exercises what the
test is about:

- **Ingest layer (default):** craft Trapper push payloads matching the
  current push schema (§4 / §4.1) and send them with `zabbix_sender` (or
  the equivalent the prototype already uses). Fully controllable, fast,
  needed for synthetic scale.
- **Collector layer:** needed only where the SNMP walk/parsing itself is
  under test (marked `[collector]`). Use the live SNMP lab; if the lab is
  simulated (e.g. snmpsim), vary the recorded data. If a collector-layer
  scenario can't be reproduced in the lab, run its ingest-layer equivalent
  and mark the collector part `BLOCKED`.

UI checks use a headless browser (Playwright or similar).

Build a small reusable harness: `reset()`, `push(reporter, neighbors)`,
`ingest()`, `api_pull()`, `snapshot()`, `diff(a, b)`, `metrics()`.

## 3. Metrics collected after every step

`metrics()` returns at least:

- Device count; Devices with / without `represented_by`, by `matched_by`
- **Suspected duplicate Devices** — same chassis_id, mgmt_ip or sysname on
  more than one Device row
- Port count; unconfirmed Ports
- `physical_link` count by `discovered_via`; stale links (per the prototype's
  own staleness definition)
- `stats.device_only` from the last ingest
- Dangling references (links/represented_by pointing to missing rows or to
  Zabbix objects that no longer exist)
- Ingest wall time; API response time for the graph endpoint

## 4. Scenarios

⚖️ = the result feeds the "stateless vs stateful" design debate
(`topology-tags-use-cases.md`). Report these with extra care: state plainly
whether the graph model actually delivered its claimed advantage.

### A. Device identity

| ID | Scenario | Probing |
|---|---|---|
| A1 ⚖️ | Neighbor's chassis_id changes, mgmt_ip unchanged. Before the change: `/promote` it and add a manual link to it | Rule 4 upsert via mgmt_ip; do manual decisions survive the key change? |
| A2 ⚖️ | Same as A1 but no mgmt_ip advertised | Expect duplicate Device + orphaned stale links; confirm or refute |
| A3 | One neighbor: reporter X sees chassis_id, reporter Y sees only sysname. Then Y starts sending chassis_id | Two Devices expected (sysname fallback is reporter+port scoped); do they merge afterwards? |
| A4 | Neighbor becomes a reporter — run both orders (seen as neighbor first / onboarded first) | Regression for known bugs: duplicate Devices, two-pass unconfirmed-port merge |
| A5 | Two Zabbix hosts with the same inventory MAC (simulate Clone host) | MAC matching under ambiguity: first wins, silent, or surfaced conflict? |
| A6 | Stack/MLAG: one host, two chassis_ids. BMC+OS: two hosts, one chassis_id | 1:1 `represented_by` limit — document what the user sees in UI |
| A7 | Delete, rename, disable a represented host in Zabbix; run API pull | Dangling pointers, Device "resurrection", UI errors |

### B. Links

| ID | Scenario | Probing |
|---|---|---|
| B1 `[collector]` | Two sides of one link report the port with different `lldpRemPortIdSubtype` (ifName vs macAddress vs local) | Port normalization; does the two-sided merge still produce one link? |
| B2 | Run the full real lab as-is | Baseline `stats.device_only`: how much connectivity rule 1's strictness loses |
| B3 | Two or more neighbors on one local port (unmanaged switch in between; LLDP-MED phone + PC) | Behavior for a port with more than one peer |
| B4 ⚖️ | One side of a link stops reporting | Is staleness visible per side, or only a single `last_seen` per link? |
| B5 | Manual link on a port where LLDP reports a different neighbor | Rule 5 priority; is the conflict visible in UI? |
| B6 | Delete a discovered link via API, ingest again | Does it come back (FR 5c)? Any way to suppress it permanently? |
| B7 ⚖️ | Move a cable: neighbor moves from port 24 to 25 | Old link → stale, new link created; can "what was on port 24" be answered? |
| B8 | LAG of two ports | Collector doesn't walk LAG: expect two parallel links; check readability |

### C. Ingest robustness

| ID | Scenario | Probing |
|---|---|---|
| C1 | Reporter pushes an empty table, then a partial one (simulated SNMP timeout / truncated walk) | **Highest risk:** must not wipe topology or mass-stale links |
| C2 | Same push ingested twice; seed upsert vs `--reset` | Idempotency — second run must produce an empty diff |
| C3 | Hostile LLDP strings in sysname/sysdesc/port desc: `<script>`, `"><img src=x onerror=…>`, 10 KB string, emoji, empty, control chars | §9 escaping at every render point: graph labels, tooltips, side panel, error messages. Verify in headless browser that nothing executes (hook `window.alert`, check DOM) |
| C4 | Concurrent: CLI ingest + UI ingest button; two parallel `/promote` on the same Device | Concurrency is deferred by design — record *how* it fails: constraint error vs silent lost write |

### D. Scale (synthetic, ingest layer)

| ID | Scenario | Probing |
|---|---|---|
| D1 | 200 / 500 / 1000 reporters × 48 ports; ~30% ports with LLDP-MED phones | Ingest time, graph API time, UI render time + usability. Run each size 3×, report median |
| D2 | One core switch with 100+ neighbors | Graph and side-panel readability on a star |

Stop scaling up when any step exceeds 5 minutes or the UI becomes
unusable; record where that happened.

## 5. Classification of each result

Every test ends with exactly one verdict:

- `PASS` — actual matches expected and the spec
- `BUG` — implementation deviates from the spec (fixable in prototype)
- `MODEL_LIMIT` — implementation follows the spec, but the spec/model can't
  handle the case
- `SPEC_GAP` — the spec doesn't say what should happen
- `BLOCKED` — couldn't run; say why

`BUG` vs `MODEL_LIMIT` is the most important distinction in the report —
justify it with a spec reference every time.

## 6. Deliverables

In `tests/campaign/`:

1. `results.json` — one record per test: id, expected, actual, verdict,
   spec reference, metrics before/after, snapshot diff summary, timings.
2. `report.md`:
   - Environment (versions, DB, lab composition, deviations from this doc)
   - Summary table: id / verdict / one-line finding
   - Counts by verdict
   - ⚖️ section: for each ⚖️ test, one paragraph on whether the graph model
     delivered its claimed advantage
   - Scale results as a table (size × median timings)
   - Top findings ranked by severity
3. The harness and scenario scripts, runnable again with one command.

Order of execution: C1, B1, A2, B3 first (cheap, most likely to find real
defects), then the ⚖️ tests, then the rest, D last.
