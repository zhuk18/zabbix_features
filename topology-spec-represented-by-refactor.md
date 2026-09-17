**Reconsidered — `represented_by` moved from `topo_edges` to `topo_nodes`.**

`represented_by` is a relationship, but it is not an independently evolving
topology entity. Unlike `physical_link`, it is directed, has at most one
*active* representation per Device (not single-valued across the Device's
whole history — a Device can be promoted, depromoted, and promoted again
to a different target over time), is not confirmed independently by
multiple reporters, and has no stale or reconciliation lifecycle. It
therefore does not require generic edge infrastructure.

The relationship is stored directly on the Device node:

```sql
-- topo_nodes, only for type='device'
represented_by_node_id    BIGINT NULL
    REFERENCES topo_nodes(id)
    ON DELETE SET NULL,

represented_by_matched_by VARCHAR(16) NULL,
    -- 'mac' | 'reporter_self' | 'manual'

represented_by_at         TIMESTAMP NULL
    -- when the CURRENT representation was established —
    -- not the Device's creation time, and not the time of any
    -- earlier representation this Device may have had before a
    -- depromote/re-promote cycle.
```

The target uses the existing `topo_nodes.id` namespace, where `Host` and
`Proxy` are already node types. This is therefore a native self-referencing
foreign key, not the polymorphic external reference rejected in §12: the FK
target is always `topo_nodes.id`, so referential integrity and
`ON DELETE SET NULL` are preserved — the same pattern already used for
`device_id` and `lag_id`.

**Two invariants, enforced at the application layer, not the database** —
the same category as the existing `lag_id IS NOT NULL ⇒ target.if_type =
'lag'` rule in §2.1 (a cross-row check neither MySQL nor PostgreSQL can
express as a plain `CHECK`):

1. **Representation target must be a Host or a Proxy:**
   ```
   represented_by_node_id IS NOT NULL
       → target node.type ∈ {host, proxy}
   ```
2. **Provenance fields exist only alongside an active representation:**
   ```
   represented_by_node_id IS NULL
       → represented_by_matched_by IS NULL
       → represented_by_at IS NULL
   ```
   `/depromote` (§6) must therefore clear all three fields together, not
   just `represented_by_node_id`.

`represented_by_matched_by` and `represented_by_at` are retained because
they are required to distinguish the established representation mechanism
and its establishment time — `matched_by` in particular is what §8's
acceptance criterion for `reporter_self` linking checks directly.
`matched_mac` is not persisted: it has no consumer in the current API, UI,
or acceptance criteria, and would represent a potentially stale snapshot
(the underlying `host.inventory.macaddress_a/b` can change after the match)
rather than active topology state. If a genuine need for reconciliation
audit/debug history arises later, that is a separate, more general
mechanism (a decision log, not a node column) — the same category as the
"discovery-quality visibility" backlog item in §11, not a field to carry in
`topo_nodes` on the strength of hypothetical future use.

**Net effect on §2.1/§2.3's storage shape:** `topo_edges` now holds only
`physical_link`. This is not just the removal of one edge type — it leaves
`topo_edges` semantically homogeneous: every row in it is now an
independently evolving topology relationship with its own multi-source
lifecycle (§2.3's `last_seen_src`/`last_seen_dst` reasoning applies to
every remaining row, with no exception to carve out). `represented_by`, in
turn, is no longer modeled as an edge between two peer entities — it is a
property of `Device`: the field that connects a topology identity to a
monitoring identity.

```
topo_nodes
├── Device
│   └── represented_by_node_id → Host | Proxy
├── Port  (device_id, lag_id — both already node-level references)
├── Host
└── Proxy

topo_edges
└── physical_link  (Port ↔ Port only, from here on)
```

Every other §2.1/§2.3 rule about `represented_by` is restated in this new
shape rather than dropped: 1:1-per-Device is now "at most one non-null
`represented_by_node_id`, enforced by a plain `UNIQUE` constraint on that
column" instead of a partial index scoped by `type`; the reverse
uniqueness (a Host/Proxy can be the target of at most one Device) is a
plain `UNIQUE` on `represented_by_node_id` itself, the same way
`host_ref`/`proxy_ref` already are (§2.1); `/promote` and `/depromote`
(§6) become `UPDATE topo_nodes` statements setting or clearing these three
columns together, instead of inserting/deleting a `topo_edges` row; every
§8 acceptance criterion written against "a `represented_by` edge" should
be read as "a non-null `represented_by_node_id` with matching
`represented_by_matched_by`" going forward.
