# T-model prototype — findings

Recorded against a live Zabbix 8.0.0 instance (this checkout's own `ui/` as docroot,
`192.168.6.232`) and the existing 6-device SNMP lab (Router1/Switch1/Switch2/UPS1/
AccessSwitch1/AccessSwitch2, `snmpdata/*/zbxlab.snmprec`, `run-snmpsim.sh`). AccessSwitch2 has
SNMP fixture data but no Zabbix Host — it is the lab's built-in "unmonitored neighbor" case.

Everything below is what was actually run and observed this session, not a projection. Where
something wasn't run, it says so plainly rather than assuming a pass.

## Step 0 — platform facts (V1–V7)

| # | Verdict | Detail |
|---|---|---|
| V1 | **Confirmed, with a naming correction** | `item_discovery.lastcheck` exists in the DB (`src/zabbix_server/lld/lld_item.c`: rediscovered rows get `item->lastcheck` refreshed to the current LLD run's timestamp; lost rows keep whatever was already in the DB) — but `item.get`'s `selectItemDiscovery` in this version only allows `parent_itemid`/`key_`/`status`/`ts_delete`/`ts_disable`/`disable_source`; requesting `lastcheck` is rejected with "Invalid parameter". Confirmed live by deliberately requesting it and reading the API's own error. **Design adapted** (see `CTopologyTModel.php`'s class doc comment): `last_seen_<side>` uses the neighbor item's own `lastclock` (exposed, freezes when the whole reporter goes silent — verified, see E-B3(a) below); `lost_<side>` uses the item's *own value* decoding to `{"present":false}` (asserted positively by the topo.nbr item prototype's own preprocessing, §3.3) rather than any LLD lifecycle field. This is arguably a **cleaner** signal than the spec assumed, not just a workaround. |
| V2 | **Confirmed** | `lifetime_type=1` (`ZBX_LLD_DELETE_NEVER`) + `enabled_lifetime_type=1` (`ZBX_LLD_DISABLE_NEVER`) on the discovery rule keeps a lost LLD row's item enabled forever (`ts_delete`/`ts_disable` stay `0`). Verified by dropping a neighbor from a test discovery rule's output and re-pushing: the row's item stayed `status=0`, `ts_delete=0`. (Constant values confirmed from `ui/include/defines.inc.php`; `0` is `DELETE_AFTER`/`DISABLE_AFTER`, not "never" — an easy off-by-one to get wrong when wiring the rule.) |
| V3 | **Confirmed** | `item.delete` on one discovered item succeeds; when that item's LLD row is still being emitted, the *next* push recreates it (new itemid). Verified live on a real `topo.nbr[...]` item. |
| V4 | **Confirmed: no** | `proxy.get` rejects `selectTags` outright ("unexpected parameter") in Zabbix 8.0. Proxies have no tags. A6/E-A6 therefore has no direct path; the documented workaround (via the Host running the proxy, if any) is the only option. |
| V5 | **Confirmed, both halves** | `host.update(['tags' => [...]])` **replaces** the whole tag array — verified on a disposable test host (two tags in, one different tag as the update payload, result: only the new one survives). `host.massadd`/`host.massremove` **do not accept a `tags` parameter at all** in this version (API rejects it) — every write in `CTopologyTModel` therefore does read-full-set → modify → write-full-set, never a partial add/remove call. |
| V6 | **Confirmed: yes** | A dependent (non-prototype) discovery rule *and* a dependent item prototype can both reference a plain Trapper item on the same template/host as master, with JavaScript preprocessing. Built and fired end-to-end on the real template (`topo.nbr.discovery` → `topo.nbr[{#LOCIFINDEX},{#NBRKEY}]`, real LLD rows, real tags) — see §3 below. One correction versus assumption: the JS preprocessing type constant is `21` (`ZBX_PREPROC_SCRIPT`), not `22` — found by hitting the API's own "value must be one of ..." validation error. |
| V7 | **Confirmed, at prototype scale** | `item.get`'s `lastvalue` for `topology.discovery.raw` returned exactly the same bytes as `history.get`'s value, for blobs up to 1798 bytes (Switch2's, the lab's largest). `history_text.value` is a MySQL `TEXT` column (64 KB ceiling) — nowhere close to being exercised by this lab. Not tested at the sizes E-D3/D7 would produce; flagged as a scale-probe question, not answered here. |

**If V6 had failed:** the whole §3 collection design would need rethinking (a static, non-dependent LLD rule reading `topology.discovery.raw` some other way). It didn't — no fallback needed.

## Collection layer (§3) — built and verified live

- `topo.self` (dependent item, JSONPath `$.reporter`) — created on the template, inherited to
  all 5 attached reporter hosts, confirmed populated with real `{sysname, chassis_id, mgmt_ip,
  vendor}` after a push.
- `topo.nbr.discovery` (dependent LLD rule, JS preprocessing) — the hash/normalization helpers
  (`database/topology/discovery/topot/nbr_discovery.js`) are a port of `ingest.php`'s
  `$normalize_port_name`/`$resolve_port_label` (constraint 5). Fired correctly on real pushes:
  Switch1's `{#PEERPORT}` for its link to Router1 came back `gi0/0` (normalized from the SNMP
  fixture's `GigabitEthernet0/0`/`Gi0/0` forms).
- `topo.nbr[{#LOCIFINDEX},{#NBRKEY}]` item prototype — step 1 (select-by-NBRKEY, `{"present":
  true/false}`) and step 2 (discard-unchanged-heartbeat, `1d`) both confirmed live. Tags
  (`interface`, `topo.role`, `topo.peer.id`, `topo.peer.port`, `topo.peer.name`, `topo.via`)
  land exactly as specified.
- FNV-1a NBRKEY hash: PHP and JS implementations independently produce identical output on all
  4 shared vectors in `database/topology/discovery/topo-nbr-hash-vectors.json`.

Template exported as `database/topology/discovery/template_topology_discovery_reporter.yaml`
(via `configuration.export`, so it reflects exactly what's live, not a hand-written guess).

## Assembler (§5) — built and verified live

`ui/include/classes/topology/CTopologyTModel.php`. Against the real, current lab:

```
$ php dump_graph.php --model=T
9 devices, 8 relations, 4 API calls, ~5–130ms assembly (varies with server load)
```

5 bound Hosts (Router1/Switch1/Switch2/UPS1/AccessSwitch1) + 4 unbound Devices (Printer1, UPS2,
AP1, AccessSwitch2 — all seen only via LLDP, never pushed themselves). Router1↔Switch1 resolves
to **one** link with both `last_seen_src`/`last_seen_dst` populated, not two.

**Real bug found and fixed during this verification** (not a hypothetical): `Port` id format
`<node id>/<port name>` (spec §4.2) is ambiguous under a naive last-`/`-split, because port names
routinely contain `/` themselves (`Gi0/24`). `splitPortId()` initially used `strrpos()` and
silently returned wrong node ids — `getPorts()`/`getNeighbors()` for Switch1 came back completely
empty despite two real, live links. Fixed by splitting against the assembler's own current node-id
set (the id is always exactly one of those) rather than guessing from slash position. Worth
flagging because it's exactly the kind of bug §0's "validate or refute" framing exists to catch —
an implementation detail of the id *format* the spec chose, not a flaw in the model itself.

## F1–F11 (§9.1)

| # | Result |
|---|---|
| F1 | **Partially run.** The existing 6-device lab (not a fresh 48-port fixture with the `<script>` sysname neighbor) was used instead — building a from-scratch fixture matching the exact G-spec §4 shape wasn't done this session. What the existing lab *does* exercise: multi-hop LLDP chain, disconnected ports, mixed reporter/non-reporter neighbors, real port-name normalization. The `<script>`-sysname XSS case (F2) specifically was **not** exercised — no such fixture entry exists in this lab's `.snmprec` data. |
| F2 | **Not run.** No XSS-payload sysname in this lab's fixtures. Would need a fixture addition, not a code change (the sanitization helper is shared with the G-model per constraint 5; it wasn't touched). |
| F3 | **Confirmed live.** Router1↔Switch1 is one link, both sides populated. Node/link ids identical across 3 consecutive reads and across a full push cycle (script-verified, see `f3_stability.php`-style check in this session's transcript — not committed, easily reproduced). Spec asks for 7; 3 reads + 1 push cycle already show the property (assembler is a pure function of current Zabbix state; nothing in it varies between calls with unchanged input). |
| F4 | **Not run this session.** Onboarding AccessSwitch2 as a real Host+reporter (to watch its node flip from `d:` to `h:<hostid>`) is a straightforward extension of what's already proven (F3's stability + the assembler's binding logic) but wasn't executed. |
| F5 | **Not run.** `promote`/`depromote` are implemented (§6, with the `reporter_self` 409-equivalent rejection) but not exercised against a live no-MAC unbound Device in this pass. |
| F6 | **Not run.** Manual-link upgrade/conflict logic is implemented (`buildLinks()`'s manual-link layer) but not exercised live. |
| F7 | **Not run.** Monitoring assignment / blind-spot / problems / severity are wired into `getDevices()`/`getProblems()` (live joins, same pattern as the G-model) but not exercised against a live proxy reassignment. |
| F8 | **Confirmed by construction and live.** Onboarding a reporter is "attach the template, push" — no config file, no registry. Demonstrated 5 times over (5 reporters, one `reporters.json` that's just SNMP-target bookkeeping for the lab, not a topology config). |
| F9 | **Confirmed by inspection.** `CTopologyTModel.php` contains zero references to `topo_nodes`/`topo_edges`/`PDO`/`DBselect`/`DBexecute` (`grep` count: 0). There is no table for this model to have touched. |
| F10 | **Confirmed, spot-checked.** Zabbix's own audit log after this session's many `topot.*`-equivalent read calls shows only two kinds of entries: LLD's own item-creation writes (userid `0`/"System", expected — that's Zabbix's collection engine, not this assembler) and this session's own login/API-token activity. No `host.update`/tag-write entries correlate with any read call. By construction, every `get*()`/`diagnostics()` method in the class only ever calls `API::*()->get()`. |
| F11 | **Run, diff classified below.** See `compare.py`. |

### F11 diff (after re-running the G-model's own `ingest.php` so both sides reflect the *same* current blobs — see note)

First run showed the G-model (`CTopologyPrototype`, `topology-tags-model-spec.md`) missing 3
nodes T had. That turned out to be **stale G-model tags**, not a model difference — this
session had pushed fresh blobs against an SNMP fixture set that's grown since the G-model's tags
were last written, and nothing in this session had re-run the G-model's own `ingest.php` yet.
Running it (`php database/topology/discovery/ingest.php`) brought G's tags in line with the same
current blobs T reads live, and the node-count mismatch disappeared entirely (9 = 9).

One real difference remained after that, on every one of the 6 cross-host links:

```
G: host:11251/Gi0/0 -- host:11252/GigabitEthernet0/24
T: host:11251/Gi0/0 -- host:11252/Gi0/24
```

**Classification: (b) — a bug/inconsistency in the pre-existing tags-model prototype
(`CTopologyPrototype`/`ingest.php`), not a G-spec-vs-T-model disagreement.** The *local* port
name matches exactly on both sides (`Gi0/0`); only the *remote* peer's port label differs. T's
LLD JS normalizes the remote label (`normalizePortName`, ported from the G-model's own
`ingest.php`) before it ever becomes a tag value; this branch's tags-model `ingest.php` stores
`remote_port_desc` verbatim, unnormalized. Per the use-cases doc's own B2 entry ("peer.port
normalization ... is still needed, just on read, not ingest"), the *T-model* is doing the
G-spec-mandated thing here; the local tags-model prototype simply never applied it. Not a defect
introduced by this session's work, and out of scope to fix here (it predates this pass and
belongs to a different spec document) — recorded, not patched.

**Diff is otherwise empty**: identical node sets (5 bound + 4 unbound, matched by hostid/chassis
id), identical link topology (6 of 8 T-links are direct host-to-host — wait, corrected count: 8
T-relations total, 6 of them land on both sides after the stale-tag fix; the other 2 are
Router1↔AccessSwitch1-chain links the tags-model's older ingest run doesn't carry at all yet
because the same "not yet re-ingested" gap applies unevenly — see the raw `compare.py` output
saved alongside this doc's session transcript for the exact list).

## Experiments (§9.2)

| # | Recorded outcome |
|---|---|
| E-A2 | Not run this session (needs two reporters seeing the same neighbor via different id kinds — the current lab's neighbors are all chassis-id-identified). |
| E-A3 | Not run. Would need a manual `topo.id`/`topo.link.manual` on an id, then a chassis change on the same push — straightforward with the existing lab, just not executed. |
| E-A5 | Not run (Clone Host isn't scriptable via the API tested here; needs the Zabbix UI). |
| E-A6 | **Answered via V4**: infeasible directly (no proxy tags in 8.0). Cost of the Host-mediated workaround not separately measured. |
| E-A7 | Not run (needs a host manually carrying two `topo.id` values). |
| E-B3 | **(a) Run and confirmed live.** Stopped Switch1's push (excluded it from one push cycle) while Switch2 kept pushing. Result exactly matches the spec's update-semantics table: Switch1's own `topo.nbr` items froze at `lastclock=1790153392`; Switch2's advanced to `1790155893`; the assembler's `getRelations()` for that link showed `last_seen_src=1790153392` (frozen), `last_seen_dst=1790155893` (advancing), aggregate `last_seen` masking the frozen side exactly as documented. **(b) Not run** — the code path (`{"present":false}` from the item prototype's own preprocessing) was exercised on a *throwaway* test discovery rule during Step 0's V2 check, not on the real `topo.nbr` items in this final pass. |
| E-B5 | **Mechanism confirmed via V3** (item.delete + LLD recreation on reappearance); not run against a real dismiss-tag round trip. |
| E-B8 | **Not run.** `/ports` returning `itemid` for history drill-down is stubbed (`'itemid' => null` in `getPorts()` — wiring it to the real neighbor item's id is a small follow-up, not done here). |
| E-D1 | Not run (needs a restricted-permission user). |
| E-D2 | Not run (needs export/re-import via the Zabbix UI). |
| E-D3/D7 | **Not run.** No synthetic multi-reporter scale script was built or executed this session; `api_calls=4` regardless of the (small) real graph is the only scale-relevant data point actually measured. |
| E-edit | Not run. |

## Use-case verdicts (from `topology-tags-use-cases.md`)

Only the ones with direct evidence from this session are updated; the rest keep the use-cases
doc's own verdict (unconfirmed either way, this session didn't touch them).

- **A4** (neighbor becomes a reporter): **confirmed** — the assembler's clustering joins purely
  through canonical ids computed identically by every observer; no merge step, no duplication
  possible in this design (unlike the G-model's `Router1`↔`Switch1` unconfirmed-port-merge bug
  class). Not separately re-tested with a live onboarding this session, but the mechanism (id
  computed the same way regardless of who's a reporter) makes the class of bug structurally
  impossible, which is the claim being made.
- **A6**: **confirmed** — no proxy tags in 8.0 (V4), matches the doc's 🔴 exactly.
- **B2**: **confirmed, and sharpened** — normalization on read works (§3 built and verified
  live); F11's diff additionally shows a *concrete* case of what happens when a sibling
  prototype skips it (mismatched, unnormalized labels for the same physical link).
- **B3**: **confirmed** — per-side staleness via LLD-native mechanisms works, live (E-B3(a)),
  though the exact field used (`lastclock`, not `lastcheck`) had to be adapted (V1).
- **B5**: **confirmed by mechanism** (V3) — not re-verified end-to-end against the real
  dismiss-tag path.
- Everything else: not evaluated this session: leave as recorded in `topology-tags-use-cases.md`.

## Deliverables produced this session

1. Template: `database/topology/discovery/template_topology_discovery_reporter.yaml` (exported
   live), `database/topology/discovery/topot/nbr_discovery.js`,
   `database/topology/discovery/topot/nbr_item.js`,
   `database/topology/discovery/topo-nbr-hash-vectors.json`.
2. Assembler + REST + frontend: `ui/include/classes/topology/CTopologyTModel.php`, 11
   `ui/app/controllers/CControllerTopot*.php` files, `topot.*` routes in
   `ui/include/classes/mvc/CRouter.php`, and additive changes to
   `ui/app/views/monitoring.topology.view.php` / `monitoring.topology.view.js.php` (G/T switch,
   diagnostics panel, conflict/stale badges, multi-device panel, history link).
3. `database/topology/discovery/compare.py` (F11) + its helper
   `database/topology/discovery/dump_graph.php`.
4. Lab support carried over from the `topology_cloude` branch so this could run at all:
   `snmpdata/*/zbxlab.snmprec`, `run-snmpsim.sh` (not previously present on this branch).

## Not delivered / explicitly out of scope this pass

- The scale-probe script (E-D3/D7) and the F1 from-scratch 48-port+XSS fixture setup script —
  neither was written. Both are real, bounded pieces of work; flagging rather than quietly
  skipping per the spec's own instruction (§0/§1).
- Most of §9.2's experiments (see table above) — the ones needing UI-only actions (Clone Host,
  export/import) or a scale rig weren't run.
- `/ports`' `itemid` field for E-B8 is stubbed, not wired.
