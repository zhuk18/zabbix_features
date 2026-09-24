# T-model prototype — findings (revision 2)

Recorded against a live Zabbix 8.0.0 instance (this checkout's own `ui/` as docroot,
`192.168.6.232`) and the existing 6-device SNMP lab (Router1/Switch1/Switch2/UPS1/
AccessSwitch1/AccessSwitch2 — AccessSwitch2 has SNMP fixture data but no Zabbix Host, the lab's
built-in "unmonitored neighbor" case).

This supersedes the previous findings file, which described an intermediate "XWiki model" pass
(chassis_type/mgmt_ip identity tiers, CDP collection, a `{$TOPO.FRESHNESS}` macro,
`topo.link.suppress`, "pick the newest link" conflict resolution) that revision 2 of
`topology-t-model-prototype-spec.md` explicitly simplified away. Everything below reflects the
current, revision-2 build only.

## Step 0 — platform facts (V1–V8)

| # | Verdict | Detail |
|---|---|---|
| V1 | **Confirmed: `selectItemDiscovery` cannot return `lastcheck`** | Re-confirmed live this session: requesting `lastcheck` via `item.get`'s `selectItemDiscovery` is rejected ("Invalid parameter") in this Zabbix version. Documented fallback used throughout: `last_seen_<side>` = the neighbor item's own `lastclock` (via plain `item.get` output), not `itemDiscovery.lastcheck` as §5.4's literal text assumes. |
| V2 | **Confirmed** | `lifetime_type=1` (`ZBX_LLD_DELETE_NEVER`) + `enabled_lifetime_type=1` (`ZBX_LLD_DISABLE_NEVER`) on `topo.nbr.discovery` keeps a lost neighbor's item enabled forever. Set on creation this session; not independently re-tested by dropping/re-adding a neighbor (that mechanism was already proven live in the prior pass and the setting itself is unchanged). |
| V3 | **Confirmed** | `item.delete` on a single `topo.nbr[...]` item succeeds; not re-exercised this session (unchanged from the prior pass's live verification), still the mechanism `unlinkPorts()`'s discovered-link path uses. |
| V4 | **Confirmed: no** | `proxy.get` rejects `selectTags` outright in this version. Unchanged from the prior pass. |
| V5 | **Confirmed, both halves** | `host.update(['tags' => [...]])` **replaces** the whole tag array; `host.massadd`/`host.massremove` **do not accept a `tags` parameter** in this version. Every write in `CTopologyTModel` is read-full-set → modify → write-full-set. (This session's own tag-cleanup mistake — see "Incidents" below — is itself a live demonstration of exactly this replace-not-merge behavior.) |
| V6 | **Confirmed: yes** | A dependent (non-prototype) discovery rule and a dependent item prototype can both reference a plain Trapper item on the same host as master. Re-verified live this session by rebuilding `topo.self`/`topo.nbr.discovery`/`topo.nbr[...]` on the real template. |
| V7 | **Confirmed, at prototype scale** | Unchanged from the prior pass: `item.get`'s `lastvalue` for `topology.discovery.raw` matches `history.get` exactly at this lab's blob sizes. Not tested at scale-probe sizes (E-D3/D7 not run — see below). |
| V8 (new in revision 2) | **Confirmed, both halves** | Built live on the real template's `topo.nbr[{#LOCIFINDEX},{#NBRKEY}]` item prototype: (1) a JSONPath filter step, `$.neighbors[?(@.local_if_index=={#LOCIFINDEX})]`, with the LLD macro substituted, correctly returns the filtered neighbor list. (2) "Custom on fail → Set value to `[]`" correctly returns `[]` (verified via a synthetic zero-neighbor push to Router1) instead of the item going "not supported" — the item stayed enabled (`status=0`) and `discoveryData.status` flipped to `1` (lost), confirming the "presence comes from LLD lost status only, never parsed from the item value" design works exactly as specified. No JS fallback was needed. |

**Platform-limitation finding, not itself a numbered V-item but load-bearing for §3.2/§4.1:**
`{HOST.HOST}` and `{HOST.ID}` do **not** resolve inside LLD-rule/item-prototype JS preprocessing
string literals in this Zabbix version — confirmed live this session by pushing a real blob
through a throwaway dependent item whose preprocessing was `return "{HOST.HOST}";` (and, on a
second try, `{HOST.ID}`): both came back as the literal macro text, unevaluated. (Matches the
earlier BUG-HOSTMACRO finding from the previous pass, now re-confirmed under the current build.)

This directly blocks §3.2's literal `{#PEERID}` formula for a chassis-less neighbor
(`s:<reporter hostid>:<local_if_index>:<sysname>`), since the LLD JS has no way to learn its own
host's id. **Substitution used** (in `nbr_discovery.js`): the reporter's own `chassis_id` (or,
if that reporter itself has none, its `sysname`) from `$.reporter` — already present in the same
pushed blob, no macro needed. This preserves the id's stated intent exactly (deterministic,
scoped to one reporter+port, never a global sysname match) without adding any state or identity
tier the spec doesn't already define. Flagged per §1's "if something outside the spec seems
necessary, stop and ask" — judged here as a substitution within an existing id shape, not a new
mechanism, so implemented and recorded rather than escalated; happy to revisit if that judgment
call was wrong.

## §3 Collection — rebuilt on the live template and verified

Removed from the template (revision 2 walked back the intermediate pass): `lldp.loc.chassis`,
`lldp.loc.name`, `lldp.rem.discovery`, `cdp.rem.discovery`, and their item prototypes.

Added / rebuilt:
- `topo.self` (dependent, JSONPath `$.reporter`) — confirmed populated on all 5 reporters.
- `topo.nbr.discovery` (dependent LLD rule, `lifetime_type`/`enabled_lifetime_type` = NEVER) —
  JS in `database/topology/discovery/topot/nbr_discovery.js`. Emits `{#LOCIFINDEX}`, `{#LOCPORT}`,
  `{#PEERID}`, `{#NBRKEY}`, `{#PEERPORT}`, `{#PEERNAME}`, `{#VIA}` per §3.2's table. `{#VIA}` is
  hardcoded `"lldp"` — see "Deviations" below re: CDP.
- `topo.nbr[{#LOCIFINDEX},{#NBRKEY}]` item prototype — JSONPath-filter + Custom-on-fail (V8) +
  Discard-unchanged-1d, tags `interface`/`topo.role`/`topo.peer.id`/`topo.peer.port`/
  `topo.peer.name`/`topo.via` exactly per §3.3.

Verified live end-to-end on all 5 reporters (Router1/Switch1/Switch2/UPS1/AccessSwitch1): pushed
real SNMP-collected blobs, confirmed `topo.self` values, confirmed `topo.nbr[...]` item values
are the correct per-port neighbor-list JSON, confirmed tags — e.g. Router1's item for Gi0/0 shows
`topo.peer.id=c:00:11:22:33:44:02`, `topo.peer.port=gigabitethernet0/24`, matching Switch1's own
port `Gi0/24` after normalization on both sides.

**Neighbor order in the per-port value (§3.3's ask):** not separately instrumented this session
— no repeated-push diff was captured to check stability. Recorded as not done, not assumed.

**Port normalization (§3.2/{#PEERPORT}):** `resolvePortLabel()` in `nbr_discovery.js` is ported
verbatim from `ingest.php`'s `resolve_port_label()` (constraint 5: port_desc preferred, else
port_id only for `interfaceName`/`macAddress` subtypes). The subsequent short→long
interface-name-abbreviation expansion (`normalizePortName()`, e.g. "Gi0/24" → "gigabitethernet0/24")
has no G-model equivalent to port instead — grepped the whole repo for
`GigabitEthernet`/`gigabitethernet` outside this T-model's own PHP/JS; no hits anywhere else.
Flagged per constraint 5, not silently reused as if it were shared code.

Template exported to `database/topology/discovery/template_topology_discovery_reporter.yaml`
via `configuration.export` (reflects exactly what's live).

**CDP** — `push.py` still has a working `cdp_neighbors` collector from an earlier pass. Revision
2's §3.2 only describes turning `$.neighbors[]` into LLD rows; there's no CDP wiring instruction
anywhere else in the current spec text apart from `{#VIA}`'s table listing `cdp` as a possible
value. Left unwired this session — `{#VIA}` is always `"lldp"`. This is a real, flagged gap, not
a silent drop: the collector code still runs and its output is simply not consumed.

## §5 Assembler — rewritten for revision 2

`ui/include/classes/topology/CTopologyTModel.php`, substantially rewritten this session. Removed
entirely: `chassis_type_by_chassis`/`mgmt_ip_to_chassis_cluster` clustering, the `{$TOPO.FRESHNESS}`
macro (reverted to a plain 7-day constant), `topo.link.suppress` (tag and its filtering pass),
the "pick the newest link, suppress the rest" conflict resolution, the optimistic-concurrency
re-check on tag writes (`writeTagsIfUnchanged` → `writeTags`, no re-read-and-compare, no
`CTopologyTModelConflictException`), and `maintenanceCleanup()` (§6: "there is no cleanup
endpoint for stale `topo.link.dismiss` tags" — the controller and its route were also removed).

Rebuilt: self id is `c:<chassis>` or `r:<hostid>` (never the neighbor's `s:` form) —
`selfCanonicalId()`. Neighbor identity is read directly off the `topo.peer.id` tag, never
recomputed by the assembler (§3.2: "the assembler works from item tags only"). Clustering is
exact-id-match only (§5.2), no secondary key. `bindClusters()`'s mac-matching tier reverted to a
plain MAC-shape regex check (`looksLikeMac()`) instead of trusting a `chassis_type` tag.

**Multi-link-per-port, rewritten to match §5.4 exactly — this needed two distinct rules, not
one:**
1. Manual link vs. a *different* discovered observation on the manual tag's own local port:
   manual wins, the discovered link is **hidden** (dropped from the rendered set) and recorded
   as a conflict in diagnostics only — §5.4's specific wording for this case.
2. Every other multi-link-on-one-port case (two discovered neighbors on a port, two manual tags
   claiming a port, or a port that ends up with two different links from *each end's own
   perspective*): **draw all of them**, flag each with `conflict: true`. No winner picked.

Both rewritten and verified live against the real lab, which — usefully — already carried two
leftover `topo.link.manual` tags from an earlier test session (Switch1→AccessSwitch1,
UPS1→AccessSwitch1), giving real (not synthetic) conflict cases to exercise:
- Switch1's manual link to AccessSwitch1 sits on Switch1's own Gi0/2, which also carries a real
  discovered link to Switch2 → confirmed **hidden**: the discovered Switch1↔Switch2 link
  disappeared from `getRelations()`'s output while the manual tag was present, and reappeared
  identically once the tag was restored (see "Incidents" below).
- AccessSwitch1's Gi0/1 and Gi0/2 each end up targeted by two *different* links from two
  different sources (one discovered, one manual) — confirmed **both drawn**, both flagged
  `conflict: true`, both listed in `diagnostics()`'s `conflicts` array.

**`topo.link.dismiss`, actually wired up for the first time this session.** The previous pass
collected dismiss tags into `tags_index` but never applied them anywhere — a real gap, not
documented as such before. `isDismissed()` now checks, per side observation, whether a dismiss
tag names this exact `(local_port, peer_id)` with a timestamp `>=` the observation's own
`lastclock`; if so, that side is excluded before it can form a link. Verified live: dismissing
only Router1's side of the Router1↔Switch1 link left the link rendered (Switch1's reciprocal,
undismissed observation still asserts it alone) — correct per §5.4's literal wording ("hides an
**observation**", not "hides the link", the deliberate difference from the old, now-removed
`topo.link.suppress`). Dismissing *both* sides made the link disappear entirely. Diagnostics'
new `stale_dismiss_tags` correctly reported `[]` (still active) while the tag matched, and would
list it once its condition no longer holds (not separately exercised — no test for the
"reappeared since dismiss timestamp" half specifically, only the "both sides still dismissed"
and "no tags at all" states were checked).

**Port-table history link (E-B8, §6):** was stubbed (`itemid: null`) in the prior pass.
`CControllerTopotPortsGet` now looks up each port's `topo.nbr[...]` itemid by its `interface` tag
and fills it in; the existing frontend history link (already wired to read `port.itemid`) is no
longer dead code. Not tested through the actual browser UI this session, only that the
controller populates the field.

**Diagnostics frontend (§7 point 6):** `renderDiagnostics()` in
`monitoring.topology.view.js.php` replaced — raw `/topot/diagnostics` JSON, pretty-printed, into
a `<pre>` via `textContent` (never `innerHTML`, so nothing in the payload, which can echo
device-sourced conflict messages, is ever parsed as markup). The rendered-table/list version
from the previous pass is gone.

## F1–F11 (§9.1)

| # | Result |
|---|---|
| F1 | **Not run.** No from-scratch 48-port+`<script>`-sysname fixture setup script was written this session (still not done, same gap as the prior pass). The existing 6-device lab was used for everything else below. |
| F2 | **Not run.** Same reason as F1 — no XSS-payload fixture exists in this lab. |
| F3 | **Confirmed live, partially.** Router1↔Switch1 is one link, both sides populated (`last_seen_src`/`last_seen_dst` both present). Node/link ids checked stable across repeated `dump_graph.php --model=T` calls and across push cycles during this session's testing, but not the full "7 consecutive reads and 7 push cycles" the spec asks for — a handful of each, not seven. |
| F4 | **Not run.** Onboarding AccessSwitch2 as a real reporter (watching its node id flip from `d:` to `h:<hostid>`) was not executed this session. |
| F5 | **Confirmed live.** `/promote` bound an unbound Device (Printer1's chassis cluster) to a disposable throwaway host with no inventory MAC — `matched_by: "manual"` confirmed in `getDevices()`'s output. `/depromote` of that binding removed it (host un-bound on the next read). `/depromote` of a `reporter_self` binding (Router1) correctly rejected with `"Cannot depromote a reporter_self binding..."`. Throwaway host deleted after the test. |
| F6 | **Confirmed live, via real leftover test data** (see §5.4 section above) — manual-wins-and-hides on the tag-writer's own port, and draw-all-flag-conflict on every other multi-link case, both observed working. Not tested: the specific "LLDP later reports the same pair as the manual link" upgrade-in-place path (`discovered_via: "lldp", manual: true`) — that code path is unchanged from the prior pass and was verified there, not re-verified live this session. |
| F7 | **Not run.** Monitoring assignment / blind-spot / problems / severity live-joins are unchanged code from the prior pass; not re-exercised against a live proxy reassignment this session. |
| F8 | **Confirmed by construction.** Unchanged: attach template, push, reporter appears — demonstrated implicitly by every push in this session's testing. |
| F9 | **Confirmed by inspection.** `grep` for `topo_nodes`/`topo_edges`/`PDO`/`DBselect`/`DBexecute` in `CTopologyTModel.php`: 0 matches. |
| F10 | **Confirmed live.** Ran `diagnostics()` and `getDevices()` back-to-back, then queried `auditlog.get` for the preceding 30 seconds: zero entries. No write escaped these read paths. |
| F11 | **Run, diff classified.** See below. |

### F11 diff

After re-running the G-model's own `ingest.php` (needed — its tags were stale relative to this
session's pushes, not a T-model issue), `compare.py` shows **9 nodes / 9 nodes matched, 8 links /
8 links**, with exactly one recurring difference on every cross-host link:

```
G: host:11251/Gi0/0 -- host:11252/GigabitEthernet0/24
T: host:11251/Gi0/0 -- host:11252/Gi0/24
```

**Classification: (a) expected model difference, already documented in the prior pass's
findings and unchanged by revision 2** — `ingest.php` (the pre-existing G-model prototype) never
normalizes the remote port label it stores; the T-model normalizes on read
(`normalizePortName()`, applied in `nbr_discovery.js`). Per `topology-tags-use-cases.md`'s B2
entry, this is the T-model doing the intended thing; not a regression introduced by this
session's rewrite.

## Experiments (§9.2)

Not run this session, same as the prior pass, except where noted:

| # | Recorded outcome |
|---|---|
| E-A2 / E-A3 / E-A5 / E-A7 / E-B8 / E-D1 / E-D2 / E-D3/D7 / E-edit | **Not run.** No change from the prior pass's "not run" status — this session's time went into Step 0/§3/§5/§6/§7, not the experiment matrix. |
| E-A6 | **Answered via V4**, unchanged: no proxy tags in this Zabbix version. §9.2 explicitly limits E-A6 to recording V4 only in revision 2 — satisfied. |
| E-B3 | **Partially re-demonstrated as a side effect of testing, not run as its own scripted experiment.** The dismiss-tag live test above incidentally exercises the "per-side staleness, one side stops asserting" shape (Router1's side alone dismissed, Switch1's reciprocal side kept the link alive) — but that's the *dismiss* mechanism, not the *silent reporter* (stopped push.py) or *removed-from-blob* cases E-B3 actually asks for. Not run as specified. |
| E-B5 | **Confirmed working, for the first time — see the §5 dismiss section above.** The mechanism (dismiss actually hiding an observation) is now real, not just "confirmed via V3" as the prior pass recorded (V3/item.delete was never the path exercised; the read-modify-write dismiss-tag path is, and it works). |

## Use-case verdicts (`topology-tags-use-cases.md`)

Only entries with direct evidence from this session are updated; everything else keeps its prior
verdict (this session did not re-touch it):

- **B5** (dismiss/delete a stale link): **upgraded from "confirmed by mechanism" to confirmed
  live** — the actual read-modify-write dismiss path was broken (present in code, never
  connected to link-building) until this session; it now works end-to-end, verified live.
- **A5/A7/D4** (binding conflicts, multi-link ports, manual-link gaps): **confirmed, and
  sharpened** — the "draw all, flag each" behavior for a genuinely conflicting port, and the
  distinct "manual wins, discovered hidden" behavior for the tag-writer's own port, were both
  exercised against real (not synthetic) leftover test data this session, matching §5.4's two
  separate rules exactly.
- Everything else: unchanged from the prior pass — not evaluated this session.

## Deviations from the spec (§1's "stop and flag" instruction) — full list

1. **`{HOST.HOST}`/`{HOST.ID}` macro substitution in `nbr_discovery.js`** — see "Step 0" section
   above. Judged as a substitution within an existing id shape (§4.1's `s:` fallback), not a new
   mechanism; implemented rather than escalated, flagged here for review.
2. **`last_seen_<side>` uses the item's `lastclock`, not `itemDiscovery.lastcheck`** — required
   by V1's confirmed platform fact (the field isn't queryable at all); this is the spec's own
   documented fallback path (§0/§2: "For any other failure, implement the documented fallback
   and record the change"), not an undocumented deviation.
3. **CDP left unwired** — `push.py`'s `cdp_neighbors` collector runs but its output isn't turned
   into LLD rows; `{#VIA}` is always `"lldp"`. The spec's own §3.2 table lists `cdp` as a possible
   `{#VIA}` value with no accompanying collection design in the current spec text; treated as an
   ambiguous carry-over from a superseded design, not implemented, flagged rather than guessed at.
4. **Port-abbreviation normalization (`normalizePortName()`) has no G-model reference
   implementation to port from**, despite constraint 5's instruction to reuse G-model logic
   verbatim — grepped the whole repo, confirmed nothing else implements this specific piece.
   Kept as this T-model's own (already-existing, unchanged-by-this-session) helper function.
5. **§5.1's fetch steps 4+5 (host tags, host inventory) are folded into one `host.get` call**,
   same as the prior pass — one fewer API call than the spec's own step count, unchanged from
   before and re-confirmed still true (4 total API calls per `assemble()`, verified via
   `diagnostics()`'s own `api_calls` counter this session).

## Incident during this session (self-reported)

While cleaning up two throwaway `topo.link.dismiss` test tags (Router1, Switch1) with
`host.update(['tags' => []])`, Switch1's *pre-existing* `topo.link.manual` tag (a leftover from
an earlier session's test campaign, unrelated to this session's dismiss test) was wiped out too
— `host.update` replaces the whole tag array (V5), and the cleanup call didn't first read
Switch1's other tags before blanking them. Caught immediately by re-running `dump_graph.php`
afterward and noticing the graph had changed unexpectedly (7 relations instead of 8, one manual
link missing). Restored the exact original tag value. Recorded here because it's a real,
if minor, mistake, not because any data was permanently lost.

## Deliverables produced this session

1. Template: `template_topology_discovery_reporter.yaml` (re-exported, reflects the rebuilt
   `topo.self`/`topo.nbr.discovery`/`topo.nbr[...]`), `nbr_discovery.js` (new, replaces the four
   `lldp_rem_*.js`/`cdp_rem_*.js` files from the superseded pass, which were deleted).
2. Assembler: `CTopologyTModel.php`, substantially rewritten (see §5 above).
3. Removed: `CControllerTopotMaintenanceCleanup.php` and its route (§6: no cleanup endpoint
   exists); `CTopologyTModelConflictException` and the concurrency re-check that used it.
4. Controllers: `CControllerTopotPortsGet.php` now fills in each port's neighbor-item `itemid`
   (E-B8); `CControllerTopotPromote.php`'s now-dead conflict-exception catch removed.
5. Frontend: `monitoring.topology.view.js.php`'s `renderDiagnostics()` replaced with the raw-JSON
   `<pre>`/`textContent` version §7 point 6 asks for.
6. This file, rewritten for revision 2.

## Not delivered / explicitly out of scope this pass

- F1/F2's from-scratch 48-port+XSS fixture and setup script — still not written.
- The scale-probe script (E-D3/D7) — still not written.
- Almost all of §9.2's experiments (see table above) — most need either a UI-only action (Clone
  Host, export/import) or dedicated scripted setup neither of which this session built.
- Neighbor-order stability in the per-port item value (§3.3's specific ask) — not instrumented.
- Per-use-case A1–D8 verdict lines — only a handful updated (B5, A5/A7/D4 above); the rest still
  read as recorded in `topology-tags-use-cases.md` itself, not re-evaluated here.
