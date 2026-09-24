# Zabbix topology — T-model prototype build spec

## 0. Objective

Build a second prototype, next to the existing graph-model prototype
(`topology-prototype-spec.md`, referred to below as **the G-spec**), that
stores **no topology state of its own**. It works like this:

- discovery observations live in ordinary Zabbix items, created and aged by
  Zabbix's own LLD;
- identity and manual decisions live in Zabbix tags;
- the graph is assembled in memory on every read.

This is the "T-model" analysed in `topology-tags-use-cases.md` (use case
IDs A1–D8 below refer to that document).

The goal is **to validate or refute the T-model hypotheses on the same lab
and the same UI as the G-model**, not to build a better topology feature.
A clean negative result ("A3 cannot be done statelessly, here is exactly
what breaks") is a successful outcome. Do not paper over a T-model
limitation by quietly adding persistent state. That would destroy the
comparison.

Favor simplicity and readability over performance. Do **not** add caching:
the cost of uncached read-time assembly is one of the things being
measured (§9, E-D3).

## 1. Hard constraints

1. **No topology tables.** T-model code must never read or write
   `topo_nodes`/`topo_edges` and must not create any table, file, or cache
   of its own that outlives a single request. All state lives in Zabbix
   objects: items, item tags, host tags, and history.
2. **Reads have no side effects.** Every `GET` endpoint and the assembler
   itself perform only Zabbix API read calls (`*.get`). Writes happen only
   in the explicit write endpoints of §6, and only as host tag changes.
3. **Same transport as the G-model.** Reuse the existing `push.py` and the
   `topology.discovery.raw` Trapper blob unchanged (G-spec §4.1). The
   T-model hangs dependent LLD off that same master item (§3). This keeps
   the storage model as the only variable between the two prototypes, and
   both can run side by side on the same reporters.
4. **Same REST contract shape as the G-spec §6**, under a separate prefix
   `/topot/…`, so the existing frontend can be pointed at either backend
   (§7). Node and port ids become opaque strings (§4.4). That is the only
   contract-level change.
5. **Reuse, don't reinvent, G-model logic that is not about storage.** Port
   label resolution, `lldpRemPortIdSubtype` handling, peer-port
   normalization, MAC normalization, and the sanitization helper (G-spec
   §9) must be the same functions, or line-for-line ports of them. Otherwise
   differences between the prototypes will come from parsing, not from the
   model.
6. **The assembler runs with the requesting user's API permissions**, not
   with a super-admin token. Experiment E-D1 depends on this.

If a requirement not listed here seems necessary, stop and flag it. Do not
expand scope silently.

## 2. Step 0 — verify platform facts before building

Several verdicts in `topology-tags-use-cases.md` rest on Zabbix behaviour
not yet confirmed for the version in the lab. Check each item against the
running instance (API calls, not documentation alone) and record the
result in `t-model-findings.md` (§10) **before** implementing the part that
depends on it:

| # | Fact to verify | Depends on it |
|---|---|---|
| V1 | `item.get` with `selectItemDiscovery` returns `lastcheck` and a lost-resource status field (`status`, `ts_delete`, `ts_disable` or equivalent) for discovered items | §5.4 staleness, E-B3 |
| V2 | LLD rule setting for lost resources that keeps them forever and leaves them enabled | §3.2 |
| V3 | Whether a single discovered item can be deleted via `item.delete` | §6 `DELETE` link, E-B5 |
| V4 | Whether the proxy object has tags in this version | A6, E-A6 |
| V5 | Whether `host.update` with `tags` replaces the whole tag set, and whether `host.massadd`/`host.massremove` accept tags | §6 write path |
| V6 | Whether a dependent LLD rule and dependent item prototypes can use a regular (non-prototype) Trapper item on the same template as master | §3 |
| V7 | Whether `item.get` `lastvalue` returns the full text value for the size of a real blob, or is truncated | §5.1 |
| V8 | Whether a dependent item prototype accepts a JSONPath filter containing an LLD macro (`[?(@.local_if_index=={#LOCIFINDEX})]`), and whether "Custom on fail → Set value to" works on that step | §3.3 |

If V6 fails, stop and report. If V8 fails, fall back to the JavaScript
variant of §3.3 described in the note there, and record it. The whole collection design in §3 depends on
it. For any other failure, implement the documented fallback and record the
change.

## 3. Collection: dependent LLD on the existing blob

All objects below are added to **the existing reporter template** that
already holds `topology.discovery.raw`. Dependent items cannot reference a
master item on a different template. None of them affect G-model ingest.

### 3.1 Reporter self item

| Key | Type | Master | Preprocessing | Value |
|---|---|---|---|---|
| `topo.self` | Dependent, text | `topology.discovery.raw` | JSONPath `$.reporter` | Reporter's own `{sysname, chassis_id, mgmt_ip, vendor}` |

This item is how the assembler knows which host is a reporter and what its
own identity keys are (the T-model equivalent of `reporter_self`,
G-spec §4.1).

### 3.2 Neighbor discovery rule

- Key `topo.nbr.discovery`, type Dependent, master `topology.discovery.raw`.
- JavaScript preprocessing turns `$.neighbors[]` into LLD rows. For each
  neighbor it emits:

| Macro | Content |
|---|---|
| `{#LOCIFINDEX}` | `local_if_index` (integer) |
| `{#LOCPORT}` | Local port name, resolved from `$.ports[]` by `if_index` |
| `{#PEERID}` | Canonical peer id (§4.1); if longer than 255 chars, `h:` + hash |
| `{#NBRKEY}` | Hash of (`{#LOCIFINDEX}`, canonical peer id), 16 hex chars |
| `{#PEERPORT}` | Normalized remote port id, or empty string if unresolved |
| `{#PEERNAME}` | `remote_sysname`, truncated to 255, display only |
| `{#VIA}` | `lldp` or `cdp` |

- Lost resources: never delete, never disable (per V2). A neighbor that
  disappears must stay as a lost item, so that it can show up as stale
  (FR 5b).
- Neighbors whose participant does not resolve at all (no chassis id and no
  sysname) are skipped and counted in `stats`, exactly as in the G-model.
  Neighbors whose participant resolves but whose port does not are **kept**,
  with `{#PEERPORT}` empty. This mirrors G-spec §3 rule 1: the Device
  exists, but no link is drawn.

**Hash:** use a deterministic, non-cryptographic hash (for example two
FNV-1a 32-bit passes with different seeds, concatenated). It is implemented
**only** in the LLD rule's preprocessing JS. Its only job is to make item
keys unique and safe (§8). Nothing else computes or parses it: the item
prototype does not need it (§3.3), and the assembler works from item tags
only (§5). Do not port the hash to the assembler.

### 3.3 Neighbor item prototype

- Key `topo.nbr[{#LOCIFINDEX},{#NBRKEY}]`. **Only the integer and the hex
  hash go into the key.** Never put a device-sourced string into an item
  key (§8).
- Type Dependent, master `topology.discovery.raw`, value type text.
- Name: `Topology neighbor on {#LOCPORT}`.
- Preprocessing:
  1. JSONPath `$.neighbors[?(@.local_if_index=={#LOCIFINDEX})]`, with
     "Custom on fail → Set value to `[]`". Only the integer macro goes into
     the expression. The value is therefore **the list of all neighbors
     currently on this local port**, not one specific neighbor. Several
     neighbor items on the same port carry the same value; that is
     accepted. Custom on fail matters: without it the item would go "not
     supported" on every push once the port has no neighbors.
  2. Discard unchanged with heartbeat `1d`. History then records changes
     plus a daily heartbeat, which is what E-B8 queries.
- Whether *this particular* neighbor is still present is **not** read from
  the item value. It comes from the item's LLD lost-resource status (V1).
  The assembler does not parse neighbor item values at all.
- Record in findings whether the order of neighbors inside the value is
  stable between pushes. If it is not, "Discard unchanged" writes spurious
  history entries; note it, do not sort in preprocessing unless E-B8
  becomes unreadable.
- Fallback if V8 fails: a JavaScript step that filters `$.neighbors[]` by
  `local_if_index` equal to the integer `{#LOCIFINDEX}` and returns `[]`
  when nothing matches. It must still not compute the hash.
- Tags:

| Tag | Value |
|---|---|
| `interface` | `{#LOCPORT}` (same tag name as the stock network templates) |
| `topo.role` | `neighbor` |
| `topo.peer.id` | `{#PEERID}` |
| `topo.peer.port` | `{#PEERPORT}` |
| `topo.peer.name` | `{#PEERNAME}` |
| `topo.via` | `{#VIA}` |

### 3.4 Ports

Port inventory (all ports, including ones without neighbors) is read from
the `$.ports[]` array of the latest `topology.discovery.raw` value
(`lastvalue`, per V7). If V7 shows truncation, fall back to `history.get`
with `limit=1` per reporter and record the call-count cost. Ports are not
turned into LLD items in this prototype: port-level lifecycle is not one of
the use cases under test.

## 4. Host tags (identity and manual decisions)

All values use `|` as field separator. Write endpoints must reject any
input part that contains `|`. The assembler must tolerate malformed values,
because tags can also be edited by hand in the Zabbix UI; it reports them
in diagnostics (§5.6) and ignores them.

| Tag | Where | Value | Meaning |
|---|---|---|---|
| `topo.id` | Any host | Canonical peer id (§4.1) | "This host represents the participant with this id" (manual promote; stacks may carry several values, A7) |
| `topo.unbind` | Any host | Canonical peer id | Cancels an automatic MAC match for this id on this host (depromote of a MAC match) |
| `topo.link.manual` | One end of the link (§6) | `<local_port>\|<peer_ref>\|<peer_port>` | Manual link. `peer_ref` is `h:<hostid>` or `d:<canonical peer id>` |
| `topo.link.dismiss` | Reporter host | `<local_port>\|<canonical peer id>\|<unix_ts>` | Hide the discovered observation while its `lastcheck <= unix_ts` (FR 5b/5c, §6) |

### 4.1 Canonical peer id

Must be the same normalization the G-model ingest uses for Device identity
(constraint 5):

- chassis id present → `c:` + normalized chassis id (MAC-subtype values in
  lowercase colon form);
- otherwise → `s:` + reporter hostid + `:` + local if_index + `:` + sysname.

The `s:` form is deliberately scoped to reporter and port, like G-spec §3
rule 4's sysname fallback. It must never become a global sysname match.

For reporters, the self id comes from `topo.self`:

- chassis id present → `c:` + normalized chassis id, as above;
- no chassis id → `r:<hostid>`. The `s:` form does not apply to a self
  observation (there is no local port), and this id cannot match any
  neighbor observation. Such a reporter therefore never merges with the
  Device other reporters see; it is listed in diagnostics and recorded as a
  finding. Do not add a sysname or `mgmt_ip` fallback to fix it.

`mgmt_ip` is not used for identity anywhere in the T-model (§5.2).

### 4.2 Stable ids exposed to the API

- Participant bound to a host → node id `h:<hostid>`.
- Unbound participant → node id `d:<canonical cluster key>` (§5.2).
- Port → `<node id>/<port name>`.

These ids are derived, not stored. If a cluster's key changes (A3), its id
changes too. That is expected and is exactly what E-A3 records. Do not add
an id-mapping store to hide it.

## 5. Assembler

A pure function `assemble(user_session) → Graph`, invoked on every `GET`.
It is built as a sequence of steps.

### 5.1 Fetch (batched, a fixed number of calls regardless of graph size)

1. `item.get` with key `topo.self`: all reporters, `lastvalue`, `hostid`.
2. `item.get` with tag `topo.role=neighbor`, plus `selectTags`,
   `selectItemDiscovery`, `lastvalue`.
3. `item.get` with key `topology.discovery.raw` and `lastvalue`, for ports
   (§3.4).
4. `host.get` with tags `topo.id` / `topo.unbind` / `topo.link.manual` /
   `topo.link.dismiss` existing (`selectTags`).
5. `host.get` with `selectInventory` (MAC fields) for all hosts. This is the
   same cost as G-spec §5, needed for MAC matching.

The live joins in §6 (monitoring assignment, problems, triggers) are
additional batched calls, identical to the G-model. Log the number of API
calls and the wall time of every step. Diagnostics reports these (§5.6),
and E-D3 uses them.

### 5.2 Cluster observations into participants

Inputs:
- reporter self observations (`topo.self`);
- neighbor observations (the `topo.peer.id` item tag).

Clustering is **exact id match only**:

1. Observations with the same id (`c:`, `s:` or `r:`) form one cluster.
   Since each observation carries exactly one id, a cluster has exactly
   one id, and the cluster key is that id.
2. There is no secondary key. In particular, `mgmt_ip` is **not** used:
   in a stateless model the old chassis id survives only in lost neighbor
   items, so an `mgmt_ip` merge would add complexity without restoring
   continuity (A3). The G-model's chassis → mgmt_ip → sysname upsert is
   deliberately not reproduced; every G/T difference this causes is
   classified in F11 as an expected model difference (A2/A3).

### 5.3 Bind clusters to hosts

Bindings are resolved in this order. The first binding that applies wins,
and every competing candidate is recorded:

1. **reporter_self**: the cluster contains a reporter's self observation →
   that reporter's host.
2. **manual**: some host carries `topo.id` equal to one of the cluster's
   ids.
3. **mac**: the cluster's chassis id (when it is a MAC), or a MAC from
   neighbor data (read from the reporter's blob, fetch step 3 of §5.1, not
   from neighbor item values), matches `inventory.macaddress_a`/`macaddress_b` of a host,
   and that host does not carry `topo.unbind` for this id.

Cardinality is **not enforced; it is detected**:

- **One host, several clusters** (a host with several `topo.id` values —
  stack/MLAG, A7): allowed. The host node gets several Device sections
  (§7).
- **Several hosts, one cluster** (A5 conflict, or BMC+OS): the host with the
  lowest `hostid` is used for drawing. The node gets a conflict flag, and
  diagnostics lists all candidates. This is not an error path: the
  assembler must still return a graph.

Proxies are never bound (V4 permitting; see E-A6).

### 5.4 Links

For every neighbor observation with a non-empty `topo.peer.port`, resolve
the far port:

- If the peer cluster contains a reporter, match `topo.peer.port` against
  that reporter's ports (§3.4), using the G-model port-matching function.
- Otherwise the far port is a synthetic port `<peer node id>/<peer.port>`.
  This is the T-model equivalent of an unconfirmed port. It is synthetic on
  every read, so no merge step is needed.

Two observations that describe the same pair of ports, one from each side,
become **one link** with two sides. For each side:

- `last_seen_<side>` = that side's `itemDiscovery.lastcheck`;
- `lost_<side>` = that side's lost-resource status (V1).

For the whole link:

- `last_seen` = the maximum over the sides;
- `stale` = `last_seen` older than 7 days (same threshold as the G-model).

Note the two different stale mechanisms:
- a **silent reporter** runs no LLD at all, so its `lastcheck` freezes and
  the item is *not* marked lost;
- a **reporter that still pushes but no longer sees the neighbor** gets a
  lost item.

Both must be reported as stale by age. Diagnostics distinguishes the two
cases.

Manual links (`topo.link.manual`) are added after discovered ones:

- If a manual link and a discovered link describe the same port pair →
  a single link with `discovered_via: "lldp"` and `manual: true` (G-spec
  §3.5 upgrade semantics).
- If a manual link occupies a port that also has a *different* discovered
  observation → the manual link wins (FR 4f). The discovered observation is
  hidden and listed as a conflict.

A port that ends up with more than one link after these rules (for example
two discovered neighbors on one port, or two manual tags claiming the same
port) → **draw all of them**, flag each with a conflict, and list them in
diagnostics. There is no "pick the winner" logic beyond the manual-wins
rule above. Before implementing, check that the G-model frontend and
port table can show more than one link per port; if they cannot, flag it
(§1) instead of adding selection logic.

`topo.link.dismiss` hides an observation while its `lastcheck <= ts`.

### 5.5 Nodes rendered

The rendering rules are the same as the G-spec §7:
- a bound cluster is drawn only as its host;
- an unbound cluster is drawn as a dashed Device;
- ports are never drawn as nodes.

### 5.6 Diagnostics

`GET /topot/diagnostics` returns:
- per-step timings and API call counts from the last assembly;
- all conflicts (binding, port);
- reporters without a chassis id (`r:` ids, §4.1);
- malformed tags;
- dangling references: `topo.id`, `topo.unbind`, `topo.link.manual` or
  `topo.link.dismiss` pointing at ids or ports that no longer occur in any
  observation;
- counts of lost and silent sides;
- stale `topo.link.dismiss` tags: those whose observation no longer exists
  or has reappeared since the dismiss timestamp. They are only counted and
  listed, never removed automatically (see §6).

This endpoint is the main instrument for the experiments in §9.

## 6. REST surface (`/topot/…`)

The read endpoints mirror G-spec §6 one-to-one, with string ids:
- `/devices`, `/devices/{id}/neighbors`, `/devices/{id}/ports`;
- `/nodes/{id}/problems`;
- `/hosts/search`.

The monitoring assignment (`monitored_by`, `assigned_proxyid`), the
blind-spot data and the trigger severity on links are live-joined exactly
as in the G-model. Additionally, `/ports` returns, for each port with a
neighbor item, that item's `itemid`, so that the UI can link to its history
(E-B8).

Write endpoints. How tags are written depends on V5:

- if `host.massadd`/`host.massremove` accept tags, use them to add or
  remove a single tag value, so the rest of the host's tags are never
  rewritten;
- otherwise read the host's tags, modify, and write them back with
  `host.update`.

There is **no concurrency control** (no re-check, no `409` on concurrent
modification). Zabbix gives no version on the host object, so a re-check
would not be atomic anyway. If the read-modify-write path is used, the
possible lost update is a finding (D1/A5), not something to fix.

Each write endpoint validates against a fresh assembly before writing, but
must not assume that validation holds at read time: tags can always be
edited directly in the Zabbix UI.

| Endpoint | Effect |
|---|---|
| `POST /devices/{id}/promote {host_id}` | Adds `topo.id=<cluster key>` to the host. Rejects if the cluster is already bound to another host (API-level only; see E-edit) |
| `POST /devices/{id}/depromote` | `manual` binding → remove the `topo.id` value. `mac` binding → add `topo.unbind`. `reporter_self` → reject with `409` and an explanation |
| `POST /ports/{src}/link {dst}` | Adds `topo.link.manual` on one end: the lower `hostid` if both ends are hosts, otherwise the host end. **Rejects if neither end is a host.** That is the documented T-model gap (B4, D4), not a bug to work around |
| `DELETE /ports/{src}/link/{dst}` | Manual link → remove the tag. Discovered link → if V3 allows it, try `item.delete` of that side's item(s) (LLD recreates it if it is reported again, which satisfies FR 5c naturally); otherwise add `topo.link.dismiss` with the current timestamp. Record which path was used |

There is no cleanup endpoint for stale `topo.link.dismiss` tags.
Diagnostics counts them (§5.6); the fact that they accumulate and would
need housekeeping is the finding. There are no ingest or pull endpoints.
Zabbix LLD is the ingest.

## 7. Frontend

Reuse the G-model frontend. The allowed changes are:

1. A data-source switch (G / T) that selects the API prefix. The two graphs
   must be viewable one after the other on the same lab without restarting
   anything.
2. String ids.
3. A host node's side panel may contain **several** Device sections (A7).
   A plain list of sections is enough.
4. A conflict badge on nodes and links flagged by §5.3/§5.4, including
   several links drawn on one port. It must be
   visually distinct from the blind-spot, maintenance and severity
   encodings.
5. In the port table, a plain "history" link built from the neighbor
   item's `itemid` to the Zabbix history page (E-B8). No extra layout.
6. A diagnostics view: the raw `/topot/diagnostics` JSON, pretty-printed,
   inserted into a `<pre>` via `textContent`. No rendered panel.

Everything else, including visual encodings, grouping and the XSS rules,
stays exactly as in the G-spec §7/§9.

## 8. Security

G-spec §9 applies unchanged. In addition:

- Device-sourced strings (sysname, chassis id, port ids) now also land in
  LLD macros, item tags and item names. They must never reach item keys:
  keys contain only `{#LOCIFINDEX}` and `{#NBRKEY}`.
- Preprocessing JS treats every blob field as untrusted:
  - no `eval`;
  - no building JSONPath expressions or keys from field values;
  - every field is truncated to 255 before it becomes a macro.
- Tag values rendered by the stock Zabbix UI are escaped by Zabbix. Values
  rendered by our frontend go through the shared sanitization helper like
  every other device-sourced string.

## 9. Acceptance criteria

### 9.1 Functional — must pass

| # | Criterion |
|---|---|
| F1 | Synthetic fixture: a setup script creates a fake reporter host with the template and pushes, via `zabbix_sender`, a blob equivalent to the G-spec §4 48-port fixture (including the `<script>` sysname neighbor). The T-graph shows the same 40/4/2/2 port grouping and 2 resolved neighbors |
| F2 | The XSS payload renders inert in node labels, the port table, tooltips and the diagnostics view |
| F3 | Router1↔Switch1 (two reporters that see each other) yields **one** link with both sides populated. Node, port and link ids are identical across 7 consecutive reads **and** across 7 push cycles. Compare ids, not counts (G-spec §8 methodology) |
| F4 | Neighbor → reporter lifecycle (G-spec §8 end-to-end scenario): after SwitchB is onboarded, the node changes from `d:` to `h:<hostid>`, there are no duplicate nodes or links, and the reciprocal LLDP view converges to the same single link |
| F5 | `/promote` binds a Device to a host with no inventory MAC; `/depromote` of that binding removes it; `/depromote` of a reporter_self binding is rejected |
| F6 | A manual link renders dashed. When LLDP later reports the same pair, it renders solid with `manual: true`. A manual link on a port with a different discovered neighbor wins, and the discovered neighbor is listed as a conflict |
| F7 | Monitoring assignment, blind-spot indicator, problems panel and link severity behave exactly as in the G-spec §8 criteria, including "reassign proxy in Zabbix → next read reflects it" |
| F8 | Reporter onboarding needs no config edit (the template is attached → the reporter appears) |
| F9 | Constraint 1: after a full test run, `topo_nodes`/`topo_edges` row counts and contents are unchanged by anything the T-model did (compare against a snapshot taken before) |
| F10 | Constraint 2: the Zabbix audit log shows no write operations caused by `GET` calls during a scripted read-only session |
| F11 | Comparison harness: `compare.py` fetches the G and T graphs for the lab and diffs them (nodes matched by `hostid` or chassis id; links matched by endpoint port names). The diff is empty, or every difference is classified in `t-model-findings.md` as either (a) an expected model difference with a reference to the use case (in particular: no `mgmt_ip`/sysname identity fallback in T, §5.2), or (b) a bug in one of the prototypes |

### 9.2 Experiments — run, measure, record (pass = recorded, not "worked")

| # | Use case | Procedure | Record |
|---|---|---|---|
| E-A2 | Mixed identifiers | Fixture neighbor seen by one reporter with a chassis id and by another with sysname only | One node or two? Is the diagnostics output understandable? |
| E-A3 | Chassis change | Push a reporter blob with a new `chassis_id` and the same `mgmt_ip`, after a manual `topo.id` and a manual link had referenced the old id | Do the ids change? Which manual decisions dangle? Does diagnostics detect all of them? Where does the old id still live (lost items only?) and for how long? |
| E-A5 | Clone host | Clone a host that carries `topo.id` in the Zabbix UI | Is the conflict detected? What is rendered? |
| E-A6 | Proxy | Result of V4 only. Do not prototype `topo.id` on a proxy | Proxy tags exist yes/no; if yes, a one-paragraph estimate of what binding would need |
| E-A7 | Stack | Two chassis bound to one host via two `topo.id` values | Readability of the multi-Device panel; interaction with A5 conflict detection |
| E-B3 | Per-side staleness | (a) stop one reporter's `push.py`; (b) remove a neighbor from one reporter's blob | Which fields change, after how long `stale` appears, and whether the two cases are distinguishable |
| E-B5 | Dismiss / delete | Delete a stale discovered link, then make it reappear in the blob | Which path V3 allowed; whether FR 5b/5c hold; how many stale dismiss tags diagnostics reports afterwards |
| E-B8 | History | "What was on port X a week ago", answered from item history | Query shape and effort; a screenshot; whether unstable neighbor order in the per-port value produced spurious history entries (§3.3) |
| E-D1 | Permissions | Read as a user who can see Switch1 but not Switch2 | What leaks through tags; whether hidden hosts appear as unmanaged Devices |
| E-D2 | Export/import | On the lab instance: export a host with `topo.*` tags, delete it, re-import it, re-link the template. No second instance | What survives; how long until the graph is equivalent again |
| E-D3/D7 | Scale probe | Script pushing synthetic blobs for N fake reporters × M neighbors (for example 50×20, 200×48, 1000×48 if feasible) | Item count, LLD processing time on the server, assembler wall time and API call count per read, API response sizes, DB size of the item tables |
| E-edit | Direct tag edit | In the Zabbix host form, create a malformed `topo.link.manual` and a duplicate port claim | Does the assembler survive, and what does it show? |

## 10. Deliverables

1. Template changes (exported YAML), setup script for the fixture and the
   fake reporters, and the scale-probe script.
2. The assembler, the `/topot` endpoints, and the frontend changes of §7.
3. `compare.py` (F11).
4. `t-model-findings.md`, structured as:
   - Step 0 results (V1–V8);
   - F1–F11 pass/fail;
   - one section per experiment with the recorded outcome;
   - **one line per use case A1–D8** stating whether the verdict in
     `topology-tags-use-cases.md` is confirmed, upgraded or downgraded, and
     why.

## 11. Out of scope

- Everything that is out of scope in the G-spec §1 (VLAN/L3 nodes, Service
  tree, graph algorithms, CAM table, LAG member collection, scheduling,
  position persistence).
- Any cache, materialized view or state store (constraint 1). If the scale
  probe shows that one is unavoidable, that is a finding. Write it up; do
  not build it.
- Native SNMP-walk LLD collection without `push.py`. It is a possible later
  variant, but it would change the transport, and the transport must stay
  identical here (constraint 3).
- Changes to the G-model prototype, apart from what `compare.py` needs to
  read from it.
- Identity fallbacks beyond exact id match (`mgmt_ip`, cross-reporter
  sysname), §5.2.
- Concurrency control on tag writes, and any automatic or manual cleanup of
  stale dismiss tags (§6).

## 12. Revision notes

Revision 2 simplified the spec without changing what the experiments
measure:

- the hash exists only in the LLD rule JS; the item prototype uses a
  JSONPath filter by `{#LOCIFINDEX}` and its value is the per-port neighbor
  list (§3.2, §3.3, new V8);
- clustering is exact id match only; `mgmt_ip` merging removed; reporters
  without a chassis id get `r:<hostid>` (§4.1, §5.2);
- several links on one port are all drawn and flagged, no winner selection
  (§5.4);
- no `409` concurrency control and no cleanup endpoint; stale dismiss tags
  are only reported (§5.6, §6);
- diagnostics shown as raw JSON, plain history link, reduced E-A6 and E-D2
  (§7, §9).
