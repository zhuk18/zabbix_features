# Topology T-model — test campaign

## 0. Objective

Test the tag-based topology model ("T-model") as defined in
`topology-tags-use-cases.md` §1:

- **Layer 1, observations:** LLD on each reporter over `lldpRemTable`, one
  item per neighbor (`lldp.rem[{#LOCPORT},{#REMIDX}]`), item tags
  `interface`, `topo.peer.id`, `topo.peer.port`, `topo.via`; the reporter's
  own chassis in item `lldp.loc.chassis`.
- **Layer 2, identity and manual decisions:** host tags `topo.id`
  (multi-valued), `topo.link.manual`, `topo.link.suppress`.
- **Layer 3, read-time assembly:** `item.get` + `host.get` → graph in
  memory; reconciliation runs on every read, nothing is stored.

The goal is to **find where the T-model breaks** and to verify, with real
Zabbix behavior, the verdicts (✅/🟡/🔴) that the use-case document currently
only asserts. Each scenario below carries the use-case ID it tests (e.g.
`UC-A3`) so results can be written back into that table.

Read `topology-tags-use-cases.md` in full first. Use
`topology-prototype-spec.md` only as a reference for reconciliation rules
(§3: three-key matching, merging link sides, manual priority) — the reader
is expected to implement the same rules at read time.

## 1. Ground rules

1. **Do not modify the T-model implementation** (template, LLD rules,
   reader). Test tooling goes under `tests/tmodel/`. If a test needs a
   change to the implementation, mark it `BLOCKED` and explain.
2. **Write the expected result before running each test**, and never edit
   it afterwards. A mismatch is a finding.
3. **Isolated state per test.** Reset = delete the test hosts, recreate them
   via API from a fixed template/host export, clear their tags. Record the
   Zabbix item/host state and the reader's graph output before and after
   each step.
4. **Lab / dev Zabbix only.** If unsure which instance you're pointed at,
   stop and ask.
5. **Discover, don't guess.** If the implementation differs from §0 (macro
   names, tag names, reader entry point, Zabbix version), record the actual
   facts in the report's "Environment" section and adapt the tests.
6. **Record the Zabbix version precisely** — several verdicts depend on
   version-specific LLD behavior.

## 2. How to drive scenarios

LLD results depend on data *and* on time (LLD intervals, lost-resource
timers). Control both:

- **Data — SNMP layer (default for correctness tests):** reporters backed by
  a simulated SNMP agent (e.g. snmpsim) whose `lldpRemTable` /
  `lldpLocChassisId` data the harness can rewrite between steps.
- **Data — trapper layer (allowed for scale tests and hard-to-simulate
  cases):** a copy of the LLD rule as a Zabbix-trapper discovery rule, fed
  with LLD JSON via `zabbix_sender`, plus trapper items for values. Results
  obtained this way must be marked `[trapper]`, because they skip the SNMP
  walk and its failure modes.
- **Time:** set short LLD intervals on test templates; force LLD runs via
  "Execute now" (`task.create`) instead of sleeping. For lost-resource
  scenarios set the disable/delete timers explicitly per test and record
  them.

Build a reusable harness: `reset()`, `set_lldp(reporter, neighbors)`,
`run_lld(host)`, `set_host_tags(host, tags)`, `read_graph()`,
`zbx_state()`, `diff(a, b)`, `metrics()`.

## 3. Metrics collected after every step

From the reader output and the Zabbix API:

- Nodes in the assembled graph: monitored hosts, unmonitored peers
  (peer ids with no matching `topo.id` / inventory MAC)
- **Suspected duplicates** — one physical device appearing as more than one
  node (compare against the scenario's ground truth)
- Links; links with only one side observed; stale sides (lost-resource
  items), by `topo.via`
- Conflicts the reader reports (same `topo.id` on two hosts, manual vs
  discovered, malformed manual tag) — and conflicts that exist in ground
  truth but the reader **did not** report
- Item counts: discovered neighbor items per reporter and total; lost items
- Timings: LLD processing time (from server log / internal items), reader
  assembly time, graph API response time

## 4. Scenarios

### Q. Platform facts (run first — other verdicts depend on them)

These come from §4 of the use-case document. Answer each with evidence
(API call + response, or UI screenshot), not by reading documentation.

| ID | Question | Affects |
|---|---|---|
| Q1 | Can a single discovered item be deleted manually (UI and API)? | UC-B5 |
| Q2 | Which fields does `selectItemDiscovery` return in this version (`lastcheck`, lost status, timestamps)? | UC-B3 |
| Q3 | Does the proxy object support tags in this version? | UC-A6 |
| Q4 | LLD host prototype: what happens when two LLD rules on different hosts try to create a host with the same name? | Rejected sub-variant, UC-A2 |
| Q5 | Which hypervisor macros does VMware LLD expose in host prototypes? | UC-C6 |
| Q6 | What does LLD do with items when the rule returns an **empty array** vs an **error / unsupported** state? | C1 below — critical |

### A. Discovery and identity

| ID | UC | Scenario | Probing |
|---|---|---|---|
| A1 | A1 | Neighbor visible, not monitored by Zabbix | Appears as an unmonitored node. Check: can it be labeled, pinned, or ignored at all? Document what's impossible |
| A2 | A2 | One neighbor seen by two reporters: (a) both by chassis_id, (b) one by chassis_id, the other only by sysname | (a) one node expected. (b) does read-time three-key matching merge them — and does it do so **consistently across repeated reads**? |
| A3 | A3 | Neighbor's chassis_id changes. Before the change set `topo.id` on its host and a `topo.link.manual` pointing to it. Variants: mgmt_ip present / absent | Expected 🔴: manual decisions silently detach. Confirm, and measure what the user actually sees (orphaned manual link? error? nothing?) |
| A4 | A4 | Neighbor becomes a reporter (both orders) | Claimed ✅: merge happens automatically, no duplicates. Verify |
| A5 | A5 | Clone a host that has `topo.id` → two hosts with the same `topo.id` | Does the reader detect and surface the conflict, or silently pick one? |
| A6 | A6 | Proxy as a topology node | Depends on Q3. Test the proposed workaround (tag on the proxy machine's Host) and note what breaks |
| A7 | A7 | Stack/MLAG: one host with two `topo.id` values. BMC+OS: two hosts, one chassis_id | Stack claimed ✅. For BMC+OS: can the reader distinguish it from A5's error case? If not, the validity rules contradict each other — record as `MODEL_LIMIT` |

### B. Links

| ID | UC | Scenario | Probing |
|---|---|---|---|
| B1 | B1 | Port-to-port links; ports without neighbors visible via standard interface items | Drill-down correctness; `interface` tag matches the standard network template |
| B2 | B2 | Two sides of one link with different `lldpRemPortIdSubtype` (ifName, macAddress, local) | Read-time port normalization; one link or two half-links? |
| B3 | B3 | One side stops reporting | Claimed ✅ natively: lost-resource item + `lastcheck` act as per-side last_seen. With Delete lost resources = Never, side stays stale until removed. Verify against Q2 |
| B4 | B4 | Manual link: (a) host↔host, (b) host↔unmonitored device, (c) typo in the peer id, (d) port already occupied by a discovered link | (a) ✅ expected; (b) binding is only a string; (c)/(d) no write-time validation — how and where does the error surface? |
| B5 | B5 | Delete a discovered neighbor item, rerun LLD; then use `topo.link.suppress` | Depends on Q1. Does the item come back? Does suppress hide it permanently and survive LLD runs? |
| B6 | B6 | LAG (if an `ieee8023adTable` LLD exists; otherwise model-level check only) | `topo.lag` tagging; readability of member links |
| B7 | B7 | Trigger on an interface item fires | Link coloring via inherited `interface` tag |
| B8 | B8 | Cable moved from port 24 to 25 | Claimed bonus: history of the neighbor item answers "what was on port 24 last week". Verify the answer is actually retrievable, and how long it survives given lost-resource deletion and history retention |

### C. Ingest robustness

| ID | UC | Scenario | Probing |
|---|---|---|---|
| C1 | — | Reporter's walk returns (a) empty table, (b) partial table, (c) SNMP timeout | **Highest risk.** Per Q6: does an empty LLD result turn every neighbor into a lost resource and, with short timers, delete them — erasing topology and history? |
| C2 | — | Reader called twice on identical state; host/item order shuffled | Determinism: identical graph every time |
| C3 | D8 | Hostile LLDP strings (`<script>`, `"><img src=x onerror=…>`, emoji, empty, control chars) and a peer id longer than 255 chars | Escaping in the reader's UI (verify in headless browser, hook `window.alert`); what happens at the 255-char tag limit — truncation, LLD error, silent collision of two ids? |

### D. Platform

| ID | UC | Scenario | Probing |
|---|---|---|---|
| D1 | D1 | User with read access to host X only; user with write access to host X | Does the read-only user learn ids of devices behind X they can't access? Can the write user change `topo.*` tags unnoticed? |
| D2 | D2 | Export/import a reporter host; Clone host | Manual decisions travel with config; LLD objects recreate themselves. Clone duplicates `topo.id` (links to A5) |
| D3 | D3 | Bulk queries: "all stale links", "all unmonitored devices" | Correctness and time at each scale step below |
| D7 | D7, C1 | Scale `[trapper]`: 200 / 500 / 1000 reporters × 48 ports, ~30% ports with LLDP-MED phones | Total neighbor item count; LLD processing time and server load; reader assembly time; graph render time. Each size 3×, report median. Stop when a step exceeds 5 minutes or the UI becomes unusable, and record where |

## 5. Verdicts

Every test ends with exactly one:

- `PASS` — matches expected
- `BUG` — the T-model implementation deviates from its own design (§1 of the
  use-case document); fixable without changing the model
- `MODEL_LIMIT` — implementation follows the design, but the T-model can't
  handle the case
- `ZBX_LIMIT` — blocked by Zabbix platform behavior (e.g. no tags on proxies,
  LLD lost-resource semantics)
- `SPEC_GAP` — the design doesn't say what should happen
- `BLOCKED` — couldn't run; say why

For each test also state whether the use-case document's verdict
(✅/🟡/🔴) **held**, and propose a corrected verdict if not.

## 6. Deliverables

In `tests/tmodel/`:

1. `results.json` — per test: id, UC id, expected, actual, verdict, original
   UC verdict, proposed UC verdict, evidence (API calls/responses,
   screenshots), metrics before/after, timings.
2. `report.md`:
   - Environment (exact Zabbix version, lab composition, LLD/lost-resource
     settings, deviations from §0)
   - Answers to Q1–Q6 with evidence
   - Summary table: id / verdict / one-line finding
   - **Verdict delta:** the use-case table with every changed ✅/🟡/🔴
     highlighted and justified
   - Scale results table (size × median timings, item counts)
   - Top findings ranked by severity
3. Harness and scenario scripts, rerunnable with one command.

Order: Q1–Q6, then C1, A2, A3, B2, B3, then the rest, D7 last.
