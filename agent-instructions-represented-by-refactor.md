# Change instructions: move `represented_by` off `topo_edges`

## 0. What this changes and why

`represented_by` moves from a row in `topo_edges` to three columns
directly on the `Device` row in `topo_nodes`. Rationale is fully written up
in the two addenda already produced for §2.3 — read those first if you
need the "why," not just the "what." This document is the "what": every
place in `topology-prototype-spec.md` that currently assumes
`represented_by` is an edge needs to change in lockstep. Do not apply this
partially — a prior pass on this spec already produced a case where a
terminology change was applied in most places but missed one paragraph
(the Proxy-MAC correction that left a stale §2.2 paragraph behind), and
this refactor touches more sections than that one did. Work through every
numbered step below in order; do not mark this done until every checklist
item at the end is verified against the actual current state of the file,
not against memory of having fixed it earlier in the pass.

**Sections of `topology-prototype-spec.md` this touches:** §2.1, §2.2,
§2.3, §3 (rules 2, 3), §4.1, §5, §6, §7, §8, §11. If you find a reference
to `represented_by` as an edge anywhere else while doing this (a grep pass
is required, not just re-reading the sections listed), fix it too and add
it to this list.

## 1. Schema migration

Replace this, in §2.3's edge table and everywhere `represented_by` is
described as a `topo_edges` row:

```sql
-- REMOVE this row from the topo_edges type comment and the §2.3 table:
-- | `represented_by` | Device → Host or Proxy | {match_type, matched_by, matched_mac, created_at} |
```

Add to `topo_nodes` (§2.1's `CREATE TABLE topo_nodes`):

```sql
ALTER TABLE topo_nodes
  ADD COLUMN represented_by_node_id    BIGINT NULL
      REFERENCES topo_nodes(id) ON DELETE SET NULL,
  ADD COLUMN represented_by_matched_by VARCHAR(16) NULL,
      -- 'mac' | 'reporter_self' | 'manual'
  ADD COLUMN represented_by_at         TIMESTAMP NULL;

CREATE UNIQUE INDEX idx_topo_nodes_represented_by_node_id
  ON topo_nodes(represented_by_node_id);
```

The `UNIQUE` index above is what gives you the reverse-direction 1:1
constraint (a Host/Proxy can be the target of at most one Device) for
free, the same way `host_ref`/`proxy_ref` already work (§2.1: multiple
`NULL`s don't conflict under `UNIQUE` on either engine). The
forward-direction constraint (a Device has at most one active
representation) needs no separate index — `represented_by_node_id` is a
single column on the Device's own row, so there is structurally nowhere
for a second value to live.

**Do not add a `CHECK` for the target-type invariant** (`represented_by_node_id
IS NOT NULL ⇒ target.type ∈ {host, proxy}`) — this is a cross-row check,
neither MySQL nor PostgreSQL can express it as a plain `CHECK`. It is
validated in the write path only (§4 below). Same for the
provenance-fields-only-with-active-representation invariant. Both are
documented in §2.1's rationale text (already drafted in the earlier
addendum) — carry that prose into the spec, don't just make the schema
change silently.

**If any existing `topo_edges` rows with `type='represented_by'` exist in a
running instance of this prototype**, write a one-time data migration that,
for each such row, sets `represented_by_node_id = dst_id`,
`represented_by_matched_by = attrs->>'matched_by'`,
`represented_by_at = attrs->>'created_at'` on the `topo_nodes` row at
`src_id`, then deletes the `topo_edges` row. Do not silently drop the data.

Drop the now-obsolete partial-index / generated-column workaround
(§2.1's original `represented_by` uniqueness mechanism) — it no longer
applies to anything.

## 2. §2.2 — node attrs

No change to `attrs` shape for any node type. `represented_by_*` are
first-class columns, not `attrs` fields — don't let them leak into the
`device` or `host`/`proxy` `attrs` JSON blobs by mistake in the ORM/model
layer.

## 3. §2.3 — edges table and rationale

Replace the `represented_by` row and its surrounding rationale paragraphs
with the two addenda text already drafted (the "why `physical_link` is
stored but `monitored_by` isn't" note, and the "`represented_by` moved to
`topo_nodes`" note with both invariants). Both are ready to paste in as
written — insert them in this order, right after the existing `monitored_by`
paragraph and before the (now-obsolete, to be removed per step 1)
`represented_by` 1:1 paragraph.

**Explicitly remove**, since they described the now-gone edge-based
mechanism:
- The "`represented_by` is 1:1 in both directions... Enforce this with the
  uniqueness mechanism described in §2.1 (partial index...)" paragraph —
  superseded by the plain `UNIQUE` index in step 1.
- Any cross-reference elsewhere in the doc to "the `represented_by` edge"
  that assumes a `topo_edges` row — reword to "the Device's
  `represented_by_node_id`" or "the Device's active representation,"
  whichever reads naturally in context.

**Keep unchanged, only reworded for the new storage shape** (the
underlying rule doesn't change, only its enforcement mechanism):
- The "split topologies aren't representable" limitation (§2.3) — still
  true, now phrased as "the `UNIQUE` index on `represented_by_node_id`
  blocks the second `/promote`" instead of referencing the old partial
  index.
- The "reverse limitation... stacked switches... MLAG" paragraph — same
  reasoning, same fix-if-ever-needed note, just update the mechanism
  description.
- "`represented_by` can be created manually, independent of
  reconciliation" — unchanged in substance; `/promote` still skips §3.2's
  strong-key check.

## 4. §3 — reconciliation rules

**Rule 2** (opportunistic MAC matching) and **rule 3** ("`Device` nodes are
never deleted") currently describe creating/removing a `represented_by`
edge. Reword both to describe setting/clearing `represented_by_node_id`
(+ the other two columns together, per the provenance invariant) instead
of inserting/deleting a `topo_edges` row. No behavioral change — same
matching logic, same "leave unassociated if no strong match" fallback,
same "clearing the representation never deletes the Device node."

**Add the two invariants explicitly to rule 2 or as a new sub-note**, since
this is where the write path for automatic matching lives:
```
represented_by_node_id IS NOT NULL ⇒ target node.type ∈ {host, proxy}
represented_by_node_id IS NULL ⇒ represented_by_matched_by IS NULL
                                ⇒ represented_by_at IS NULL
```
Implement both checks in the same code path that currently enforces
`lag_id`'s `if_type='lag'` invariant (§2.1) — same category of
application-layer validation, arguably the same helper function if one
exists, or at minimum the same code review checklist item.

## 5. §4.1 — `reporter_self` linking

The paragraph describing how a reporter's own `Device` gets
`represented_by` its own `Host` "automatically and deterministically" is
unchanged in logic, only in mechanism: instead of creating a `topo_edges`
row with `matched_by: "reporter_self"`, set
`represented_by_node_id = <the Host's topo_nodes.id>`,
`represented_by_matched_by = 'reporter_self'`,
`represented_by_at = now()` on the resolved (possibly pre-existing)
`Device` row. **The "if that `Device` somehow already has a different
active `represented_by`, don't silently override it, log and skip" rule is
unchanged** — check `represented_by_node_id IS NOT NULL AND
represented_by_node_id != <this Host's id>` instead of checking for an
existing edge row.

## 6. §5 — Zabbix API pull

No structural change to the pull logic itself (`host.get`/`proxy.get` →
upsert `Host`/`Proxy` nodes is unaffected — those are still separate
`topo_nodes` rows). Only the "attempt Device reconciliation per §3.2"
step's write target changes, per step 4 above. The "`Proxy` does not go
through §3.2's MAC reconciliation" and "the only way a `Proxy` gets a
`represented_by` edge at all is manual `/promote`" statements are
unchanged in substance — reword "edge" to "representation" where it reads
oddly otherwise.

## 7. §6 — backend API surface

**`GET /topo/devices`**: wherever this currently joins against
`topo_edges` to determine whether a Device has an active representation
(for the "merge into Host/Proxy, don't render as separate node" decision),
change the join to read `represented_by_node_id` directly off the Device
row — this is now a plain column read, no join needed at all for the
Device side. For a Host/Proxy row, "does this have an associated Device"
becomes `SELECT ... FROM topo_nodes WHERE represented_by_node_id = <this
Host/Proxy's id>` — still needs a lookup, but it's a single indexed lookup
against the new `UNIQUE` index (step 1), not a `topo_edges` scan.

**`POST /topo/devices/{id}/promote {host_id}`**: change from `INSERT INTO
topo_edges` to `UPDATE topo_nodes SET represented_by_node_id = ?,
represented_by_matched_by = 'manual', represented_by_at = now() WHERE id =
? AND type = 'device'`. Must still enforce both directions of uniqueness —
now via `UNIQUE` constraint on `represented_by_node_id` (target side) plus
a plain existence check (`represented_by_node_id IS NOT NULL` on the
source Device) before the update, returning the same rejection behavior as
before if either check fails. Must still validate the target is a `Host`
or `Proxy` node (invariant 1, step 4) before writing.

**`POST /topo/devices/{id}/depromote`**: change from `DELETE FROM
topo_edges WHERE ...` to `UPDATE topo_nodes SET represented_by_node_id =
NULL, represented_by_matched_by = NULL, represented_by_at = NULL WHERE id
= ?`. **All three columns together** — this is invariant 2 from step 4;
clearing only `represented_by_node_id` and leaving stale
`matched_by`/`at` values behind is a bug, not a partial success.

**`GET /topo/devices/{id}/ports`, `GET /topo/nodes/{id}/problems`**: no
change — neither reads `represented_by` at all.

## 8. §7 — frontend

No change to the visual encoding rules themselves ("Device with active
`represented_by` is not rendered as a graph node," "Device section appears
only when Host/Proxy has an active representation," etc.) — these are
already phrased in terms of "has an active representation," which is
storage-agnostic. Only the API response shape they're reading from
changes (step 7), which is a backend/frontend contract concern, not a
frontend logic concern, unless the current frontend code directly
inspects an `edges` array in the API response looking for `type ===
'represented_by'` — if so, that lookup must change to reading a field
(e.g. `device.represented_by` or similar, whatever `GET /topo/devices`
now returns per step 7) instead of filtering an edge list.

## 9. §8 — acceptance criteria

Every criterion currently phrased as "...ends up with a `represented_by`
edge..." should be read and, where the spec text itself will be edited,
rewritten as "...ends up with `represented_by_node_id` set (and
`represented_by_matched_by` matching the expected mechanism)...". This is
wording only — the underlying test scenarios, sequencing, and assertions
are unchanged. Specifically:

- "A Zabbix host matching the seed Device by MAC... ends up with a
  `represented_by` edge after the API pull runs" → assert
  `represented_by_node_id` is set and `represented_by_matched_by = 'mac'`.
- "A seed Proxy never gets a `represented_by` edge automatically" →
  assert `represented_by_node_id IS NULL` after the pull, for the seed
  Proxy, unchanged in every other respect.
- "Attempting to `/promote` a second Device onto a Host/Proxy that already
  has an active `represented_by` edge is rejected" → same test, now
  exercising the `UNIQUE` index from step 1 instead of the old partial
  index — verify the rejection still happens (should be a straightforward
  unique-constraint violation surfaced as an API error, not a behavior
  change).
- The full neighbor-to-reporter lifecycle test (§8, the `Device(B)`
  scenario) — step 2's "gets `represented_by` to its own `Host` via
  `reporter_self`" assertion changes its check to
  `represented_by_node_id = <B's Host id> AND represented_by_matched_by =
  'reporter_self'`. Nothing else in that scenario changes.
- A freshly onboarded reporter with no inventory MAC still getting
  `represented_by` with `matched_by: "reporter_self"` — same reword as
  above.

**Add one new acceptance criterion** that didn't exist before because the
old model didn't need it: depromoting a Device clears all three
`represented_by_*` columns, not just `represented_by_node_id` — write a
test that promotes, depromotes, then asserts `represented_by_matched_by`
and `represented_by_at` are both `NULL`, not just `represented_by_node_id`.
This directly exercises invariant 2 from step 4, which is new enforcement
surface that the edge-based model didn't have (deleting a row deleted all
its attrs atomically; three separate columns being cleared together is not
automatically atomic unless the code does it deliberately).

## 10. §11 — backlog

No backlog items change in substance. If `represented_by`'s 1:1 constraint
is still flagged there as "the most likely redesign candidate," that
framing is unaffected by this refactor — loosening 1:1 later would mean
changing the `UNIQUE` index to something else (or reintroducing a
proper edge table if the relationship ever needs its own multi-valued
lifecycle), not reversing this refactor. Leave that entry as is.

## 11. Verification checklist (run after implementing, not before)

- [ ] `grep -rn "represented_by" topology-prototype-spec.md` — every
      remaining hit describes a node column, not an edge/row in
      `topo_edges`, except historical/rationale prose explicitly discussing
      the old design.
- [ ] §2.1's `CREATE TABLE topo_edges` no longer mentions `represented_by`
      anywhere, including the comment listing valid `type` values.
- [ ] §2.3's edge table has only one row (`physical_link`).
- [ ] Both invariants (target-type, provenance-fields-together) are
      written out explicitly in the spec text, not just implemented in
      code.
- [ ] `/promote` and `/depromote` in §6 both describe `UPDATE topo_nodes`,
      not `INSERT`/`DELETE` on `topo_edges`.
- [ ] Every §8 criterion mentioning `represented_by` reads correctly
      against the new column-based storage, and the new depromote-clears-
      all-three-columns criterion has been added.
- [ ] The two rationale addenda (`physical_link` storage vs.
      `monitored_by` live-resolution; `represented_by` moved to
      `topo_nodes`) are both present in §2.3, in that order.
- [ ] No section outside this checklist's scope (§9 security, §10
      scaling, §12 review-questions) references `represented_by` as an
      edge — these sections don't currently discuss it directly, but
      confirm rather than assume.
