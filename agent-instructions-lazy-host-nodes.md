# Task: switch Host/Proxy topo_nodes creation from eager to lazy

## Context

`topology-prototype-spec.md` (§5, §6, §7, §8, §10, §11) has been updated to
change how `Host`/`Proxy` rows enter `topo_nodes`. Read those sections in
full before touching code — this file is a task list, not a replacement for
the spec's reasoning. If anything below conflicts with the spec, the spec
wins; flag the conflict rather than picking one silently.

**The change in one sentence**: a `Host`/`Proxy` `topo_nodes` row is no
longer created for every Zabbix host/proxy on pull. It is created only when
there is an actual reason for it to exist in the topology — a MAC match
(§3.2), `reporter_self` (§4.1), or manual `/promote` (§6) — and it is
deleted again if that reason goes away.

## Required changes

### 1. Zabbix API pull (§5)

- Stop upserting a `topo_nodes` row for every host returned by `host.get`.
  `host.get` (with `selectInterfaces`, `selectInventory`) stays as the data
  source for MAC reconciliation (§3.2) — it must no longer also be a node
  upsert step.
- Only upsert a `Host` node (`type='host'`, `host_ref=hostid`) **and**
  create the `represented_by` edge together, in the same step, when
  reconciliation finds a MAC match. No match → nothing written for that
  host.
- Remove the `proxy.get` call from this pull entirely. `Proxy` has no
  automatic matching path (no MAC source, `reporter_self` doesn't apply —
  see §5's rationale), so there's nothing for this pull to reconcile it
  against. A `Proxy` node is only ever created via `/promote`.

### 2. New search endpoints (§6)

Add, if not already present in some form:

- `GET /topo/hosts/search?q=` — thin wrapper over `host.get` filtered by
  name/IP. Returns `hostid` + display name/status only. Writes nothing to
  `topo_nodes`.
- `GET /topo/proxies/search?q=` — same, backed by `proxy.get`.

These are the only host/proxy picker for `/promote` now that most hosts
have no local `topo_nodes` row to search against.

### 3. `/promote` (§6)

- Change the accepted `host_id`/`proxy_id` to mean a **Zabbix**
  `hostid`/`proxyid`, not a `topo_nodes` id.
- Before creating the `represented_by` edge, upsert the `Host`/`Proxy` node
  if it doesn't already exist (`host_ref`/`proxy_ref` as the match key —
  this is a simple exact-key upsert, not the multi-key `Device` upsert in
  §3 rule 4). A second `/promote` call against an already-materialized
  node must not create a duplicate row.
- Keep the existing 1:1 / strong-key-bypass behavior unchanged (§2.3) —
  this task only changes *how the target node comes to exist*, not the
  edge-creation rules themselves.

### 4. `/depromote` (§6)

- After deleting the `represented_by` edge, check whether the `Host`/
  `Proxy` node has any other reason to exist:
  - a MAC match under §3.2, or
  - a `reporter_self` link (§4.1).
- If neither applies, delete the `topo_nodes` row for that `Host`/`Proxy`
  too. If either applies, leave the node in place.
- `Device` nodes are unaffected by any of this — they are still never
  deleted (rule 3, §3).

### 5. One-time data migration (not explicitly in the spec — needed because of it)

Any environment that already ran the old eager-pull logic will have
`Host`/`Proxy` `topo_nodes` rows with no `represented_by` edge and no
`reporter_self` origin — orphans under the new policy. Write a one-time
cleanup migration that deletes exactly these rows:

```sql
DELETE FROM topo_nodes
WHERE type IN ('host', 'proxy')
  AND id NOT IN (SELECT dst_id FROM topo_edges WHERE type = 'represented_by');
```

Run this once, after the code changes above are deployed, not before (so
nothing gets immediately re-created by a pull still running the old
logic). Confirm row counts before/after so the change is visible in the
migration's own output, not just implied.

### 6. UI (§7)

No functional change required — the side panel already conditionally
shows the Device section only `when this Host/Proxy has an active
represented_by`. Update the comment/doc string there if it currently
references "most of the unassociated hosts list falls here" — that framing
is now wrong (§7's note explains why).

### 7. Tests (§8)

Add tests for the new criteria added to §8:

1. A host with no MAC match and never promoted never gets a `topo_nodes`
   row.
2. `/promote` against a host with no prior `topo_nodes` row creates the
   node and the edge in one call.
3. `/depromote` deletes the `Host` node when nothing else justifies it,
   and leaves it in place when the host is a reporter (`reporter_self`).
4. Existing 1:1-constraint and MAC-reconciliation tests should still pass
   unchanged — this change doesn't touch those rules, only when the node
   backing them gets created.

## Out of scope for this task

Do not touch `Device`/`Port` creation, reconciliation rules (§3), the
Trapper push/ingest pipeline (§4.1), or anything about `physical_link`.
This task is scoped to Host/Proxy node lifecycle only.
