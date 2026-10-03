# Zabbix topology — 1. Model spec

Version 2.0 (2026-10-03). This spec replaces: `topology-prototype-spec.md`,
`topology-spec-represented-by-refactor.md`, the ingest part of
`topology-lld-part2-spec.md` (§5–§8), `topology-link-replacement-spec.md`
(v2.2), `topology-manual-contradiction-spec.md` (v1.1),
`topology-device-level-edge-spec.md` (v0.8),
`topology-observation-cleanup-spec.md`, `topology-freshness-config-spec.md`
and `topology-spec-changes-2026-10-02.md`.

Companion specs:
- `topology-2-lld-delivery.md` — data delivery through LLD.
- `topology-3-push-delivery.md` — data delivery through push (trapper).
- `topology-4-lab-simulator.md` — lab generator and scenario runner.

**Status markers.** A section that the code does not implement yet has a
line `Status: not implemented`. A section without this line is
implemented.

**Rule for the coding agent.** If a requirement that is not in this spec
seems necessary, stop and flag it. Do not expand the scope silently. If
this spec conflicts with the code, stop and report the conflict.

---

## 0. Objective

Model the physical network (Devices, Ports, links) together with Zabbix
monitoring objects (Hosts, Proxies). Bind the two. Show both as one graph,
including devices that are not Zabbix hosts.

This is a prototype that validates the data model. Simplicity and
readability are more important than performance.

## 1. Scope

**In scope:**
- Nodes `Device`, `Port`, `Host`, `Proxy` (§3).
- Links `physical_link` and `device_link`; association `represented_by`;
  live monitoring assignment (§4).
- The input contract: roles, macros, snapshots (§5). Delivery specs fill
  the snapshots.
- Ingest and reconciliation (§6), observations (§7), link rules (§8),
  manual links (§9), Host/Proxy association (§10).
- API (§11), UI (§12), security (§13).

**Out of scope — do not build:**
- `VLAN`, `Subnet`, `IPAddress` nodes; `Service` and `APMService` nodes.
- History of the topology beyond `last_seen*` timestamps.
- Fuzzy or score-based identity matching.
- Scheduled ingest. Every run is started manually (CLI or UI button).
- Reachability, SPOF and blast-radius algorithms.
- Links found by FDB only. FDB data is stored only as `learned_macs`.
- Reciprocal pairing of ports (two reporters that name each other on one
  port each, without matching port ids).
- Reporter onboarding. A reporter is a Zabbix Host. How the Host was
  created (manually, Network Discovery, API, CMDB import) is not a
  topology concern.
- Manual creation of a `Device` (§18 backlog).
- Topology stored in Zabbix tags (rejected, §17.2).

## 2. Terms

- **Reporter** — a Zabbix Host whose own data reaches ingest: a host with
  at least one usable snapshot (§6.2), or a host with only the legacy blob
  item (§5.7).
- **Snapshot** — the latest rows of one discovery rule with a topology
  role (§5.4).
- **Usable snapshot** — §6.2.
- **Observation** — one NEIGHBORS row, after ingest resolved it (§7).
- **Link** — a `physical_link` or a `device_link`.
- **Port-level link** — `physical_link`, Port ↔ Port.
- **Device-level link** — `device_link`, Port → Device. The far port is
  not known.
- **Active link** — a link without `superseded_at`.
- **Discovered link** — `discovered_via` ≠ `manual`. **Manual link** —
  `discovered_via` = `manual`.
- **Authoritative ports** — ports of a Device that come from a walk by
  that Device's own reporter (`confirmed: true`).
- **Pseudo port** — a Port created from a neighbor advertisement
  (`confirmed: false`). Older documents call it "unconfirmed port".
- **Full run** / **partial run** — an ingest run over all reporters / a
  run restricted with `--zabbix-host`.

## 3. Nodes

### 3.1 Storage

```sql
CREATE TABLE topo_nodes (
  id         BIGINT PRIMARY KEY AUTO_INCREMENT,
  type       VARCHAR(32) NOT NULL,   -- 'device' | 'port' | 'host' | 'proxy'
  host_ref   BIGINT NULL UNIQUE REFERENCES hosts(hostid) ON DELETE CASCADE,   -- type='host'
  proxy_ref  BIGINT NULL UNIQUE REFERENCES proxy(proxyid) ON DELETE CASCADE,  -- type='proxy'
  device_id  BIGINT NULL REFERENCES topo_nodes(id) ON DELETE CASCADE,         -- type='port': owning Device
  lag_id     BIGINT NULL REFERENCES topo_nodes(id) ON DELETE SET NULL,        -- type='port', physical: its LAG Port
  represented_by_node_id    BIGINT NULL UNIQUE REFERENCES topo_nodes(id) ON DELETE SET NULL, -- type='device'
  represented_by_matched_by VARCHAR(16) NULL,  -- 'mac' | 'reporter_self' | 'manual'
  represented_by_at         TIMESTAMP NULL,    -- when the CURRENT association was made
  attrs      JSON NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX idx_topo_nodes_device_id ON topo_nodes(device_id);
CREATE INDEX idx_topo_nodes_lag_id ON topo_nodes(lag_id);
```

Use the engine of the target Zabbix database (MySQL or PostgreSQL). Do
not create one table per node type: JSON `attrs` keeps the schema stable
while the model changes.

- `device_id` is a plain foreign key, not an edge. A Port always has
  exactly one Device, known when the Port is created. Deleting a Device
  deletes its Ports.
- `lag_id` uses `ON DELETE SET NULL`, not `CASCADE`. Member ports are
  real interfaces; they must stay when their LAG goes away. LAG
  membership can change; re-pointing or clearing `lag_id` is an ordinary
  update.
- `host_ref`, `proxy_ref`, `represented_by_node_id` are plain `UNIQUE`.
  Both engines allow many `NULL`s under `UNIQUE`.

**Invariants checked by the application before every write** (a `CHECK`
can't read another row):
1. `lag_id IS NOT NULL` → the target Port has `if_type = 'lag'`. A Port
   with `if_type = 'lag'` never has a `lag_id`.
2. `represented_by_node_id IS NOT NULL` → the target node type is `host`
   or `proxy`.
3. `represented_by_node_id IS NULL` → `represented_by_matched_by IS NULL`
   and `represented_by_at IS NULL`. Clear all three together.

**The cascade chain must be complete.** Deleting a Zabbix Host deletes its
`topo_nodes` row (`host_ref`), which must not be blocked by edges
(`topo_edges` FKs cascade) or by a Device that points at it
(`represented_by_node_id` → `SET NULL`; then invariant 3 is restored by
the application or by the next ingest).

### 3.2 Attributes by type

- **device** — an observed network participant. It can exist without a
  Zabbix Host.
  `{mac, chassis_id, mgmt_ip, sysname, vendor, last_seen, matched_by?}`.
  `matched_by: "sysname"` marks a Device found by the weak key (§6.7.1).
  `sysname`, `chassis_id`, `vendor` come from LLDP/CDP and are untrusted
  (§13).
- **port** — a connectivity point of a Device.
  `{if_index, name, if_type: "physical"|"lag"|"mgmt", mac, speed,
  admin_status, oper_status, learned_macs: [], learned_mac_count?,
  zabbix_itemids: [], confirmed: true|false}`.
  - Use the word "port", never "interface". "Interface" means Zabbix's
    `host.interfaces`.
  - `if_type` and LAG membership are independent. A member port stays
    `physical`; membership is `lag_id`.
  - `zabbix_itemids` are item ids that monitor the port. Item data is
    read live; it is never copied into `attrs`.
- **host**, **proxy** — thin pointers (`host_ref`, `proxy_ref`).
  `attrs` is empty. Name, status, maintenance and problems are read live.
  A Proxy has no inventory and can't own items.

## 4. Relationships

### 4.1 Storage

```sql
CREATE TABLE topo_edges (
  id         BIGINT PRIMARY KEY AUTO_INCREMENT,
  type       VARCHAR(32) NOT NULL,   -- 'physical_link' | 'device_link'
  src_id     BIGINT NOT NULL REFERENCES topo_nodes(id) ON DELETE CASCADE,
  dst_id     BIGINT NOT NULL REFERENCES topo_nodes(id) ON DELETE CASCADE,
  attrs      JSON NOT NULL DEFAULT ('{}'),
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX idx_topo_edges_src ON topo_edges(src_id, type);
CREATE INDEX idx_topo_edges_dst ON topo_edges(dst_id, type);
```

Every row in `topo_edges` is a link with its own multi-source lifecycle.
`represented_by` is a node column (§4.5). `MONITORED_BY` is not stored
(§4.6). LAG membership is a node column (§4.7).

Generic storage does not give generic constraints. Each type has its own
write-path checks (§4.2–§4.4). A new edge type must bring its own checks.

**Type validation on write:** `physical_link` — both ends `port`;
`device_link` — `src_id` `port`, `dst_id` `device`. Reject any other
combination.

### 4.2 `physical_link` (Port ↔ Port)

- **Canonical direction:** the smaller Port id is `src_id`. Every write
  path applies this, including the manual link endpoint. Unique on
  `(src_id, dst_id)` for `physical_link`.
- **attrs:**

| Attr | Meaning |
|---|---|
| `discovered_via` | `manual`, or the row's `source` (`lldp`, `cdp`, other). Anything ≠ `manual` is discovered. Code must test `!== 'manual'`, never `=== 'lldp'`. |
| `last_seen_src`, `last_seen_dst` | latest confirmation from the Device that owns the canonical `src` / `dst` Port. Not "the reporter that sent this push". |
| `last_seen` | max of the two. |
| `last_seen_port` | clock of the latest snapshot that contained the link at port level. Display only (§8.3). Missing on old rows → read as `last_seen`. |
| `superseded_at` | §8.4. |
| `shadow_ack` | manual links only (§9.5). |

- **Per-side update rules:**
  - Values come from snapshot clocks of `applied` observations (§8.9),
    never from ingest wall-clock time.
  - A field only moves forward or stays frozen. It never goes back to
    `null`.
  - The side that is not a reporter stays `null`.
  - When one side stops reporting, its field freezes while the other
    side's field and `last_seen` keep moving. This is why the two sides
    are separate.
- Manual links have no per-side values.

### 4.3 `device_link` (Port → Device)

- The far Device is known; its port is not. `src_id` = local Port,
  `dst_id` = far Device. No canonicalization.
- attrs: as `physical_link`, plus:
  - `far_port_reason` — required, fixed enum (below). Unknown values are
    rejected.
  - `far_port_hint` — the far end's advertisement as received
    (`rem_port`, `rem_port_desc`, port id subtype). Display only;
    untrusted.
- `last_seen_dst` is set when the far Device's reporter names the local
  Device on any of its ports.

| Reason | Produced by | Text for the operator |
|---|---|---|
| `port_unmatched` | §8.1 | "\<D\> reports its own ports, and none of them matches the port \<R\> sees (\<hint\>). The link is drawn to the device until the port can be matched." |
| `port_shared_id` | §8.1 | "\<D\> advertises the same identifier (\<hint\>) on several ports, so the exact port can't be told apart." |
| `lag_ambiguous` | §8.1 | "\<R\> has several links to \<D\> (\<local ports\>) whose remote ports can't be told apart, typically a link aggregation." |
| `manual` | §9.1 | "Created manually without a remote port by \<user\> on \<date\>." |
| `fdb_mac_only` | reserved | "Found by a learned MAC address (FDB) only; FDB doesn't tell which port of \<D\> it is." Accepted by validation; nothing writes it. |

The wording of the texts can change; the meaning can't.

### 4.4 Port uniqueness

- A Port has **at most one active link**, counting both types. A
  `device_link` occupies its local port only, not a port of the far
  Device.
- This applies to LAG member ports too. Each member connects to exactly
  one far port (or one far Device).
- The model is point-to-point. It does not represent TAPs, SPAN or mirror
  ports, or optical splitters. Topology shows operational peer
  relationships, not packet-replication paths.
- Enforcement: write-path checks over active links. Superseded links are
  not counted.

### 4.5 `represented_by` (Device → Host or Proxy)

- Connects a topology identity (Device) to a monitoring identity
  (Host or Proxy). Stored on the Device row (§3.1).
- **1:1 in both directions.** A Device has at most one association; a
  Host or Proxy is the target of at most one Device. A second association
  fails, or needs `/depromote` first. Never replace one silently.
- `matched_by`: `mac` (§10.2), `reporter_self` (§6.4), `manual` (§10.3).
  A manual association stays manual.
- A Device is never deleted when its association is removed.
- **Known limitations (accepted):**
  - Split identity: one box monitored as two Zabbix hosts (OS + BMC).
    Only one can be associated.
  - Stacked switches and MLAG pairs: several chassis monitored as one
    host. Only one Device can be associated.
  - Workaround for both: associate one; leave the rest as topology-only
    Devices.

### 4.6 Monitoring assignment (`MONITORED_BY`) — live, not stored

- Read from `host.get`: `monitored_by`, `proxyid`, `proxy_groupid`,
  `assigned_proxyid`. In the proxy-group case, `assigned_proxyid` is the
  proxy that the server picked inside the group.
- One batched `host.get` per request, never one call per host.
- **Blind spot, not outage.** A Proxy that is down means Zabbix can't see
  its hosts. It does not mean those hosts are down. The UI must show a
  blind-spot marker, never the problem colour.

### 4.7 LAG membership

- Stored as `Port.lag_id`, not as an edge. Membership comes in the same
  data as the Port itself and goes stale with it, so it has no separate
  lifecycle.
- **Trigger to revisit:** if membership gets its own collection path, its
  own staleness or per-member attributes (LACP state), make it an edge
  `member_of_lag`.

### 4.8 Link severity (derived)

- Severity of a link = highest active trigger severity on items in
  `zabbix_itemids` of its end ports. Read live; never stored.
- Severity is not `oper_status`. A link can be up and still have an
  active trigger. Keep the two signals apart.

## 5. Input contract: roles, rows and snapshots

Both delivery specs produce rows by this contract and write them into
`topo_lld_snapshot`. Ingest reads only snapshots (and the legacy blob,
§5.7).

### 5.1 Roles

A discovery rule has the property `topology_role`. One role per rule.

| Value | Constant | Export string | One row is |
|---|---|---|---|
| 0 | `ZBX_TOPOLOGY_ROLE_NONE` | `NONE` | — (default) |
| 1 | `ZBX_TOPOLOGY_ROLE_PORTS` | `PORTS` | one port of this host |
| 2 | `ZBX_TOPOLOGY_ROLE_NEIGHBORS` | `NEIGHBORS` | one LLDP/CDP neighbor seen on a local port |
| 3 | `ZBX_TOPOLOGY_ROLE_LEARNED_MACS` | `LEARNED_MACS` | one learned MAC on a local port, or a per-port count |
| 4 | `ZBX_TOPOLOGY_ROLE_LAG` | `LAG` | one LAG membership |

### 5.2 Macro contract

A required macro must be present and not empty. "One of" means at least
one of the listed macros. An empty value counts as missing.

**PORTS**

| Macro | Req. | Meaning |
|---|---|---|
| `{#IFINDEX}` | yes* | local ifIndex |
| `{#IFNAME}` | no | port name |
| `{#IFTYPE}` | no | IANA ifType number |
| `{#IFMAC}` | no | port MAC |
| `{#IFADMINSTATUS}`, `{#IFOPERSTATUS}` | no | IF-MIB status |
| `{#IFSPEED}` | no | speed |
| `{#LOC_CHASSIS}` | no | this device's own chassis id |

\* PORTS only: if `{#IFINDEX}` is absent, `{#SNMPINDEX}` is the ifIndex.
Stock IF-MIB discovery rules emit `{#SNMPINDEX}`. This fallback never
applies to other roles: in NEIGHBORS, `{#SNMPINDEX}` is the lldpRemTable
index.

**NEIGHBORS**

| Macro | Req. | Meaning |
|---|---|---|
| `{#IFINDEX}` | yes | local ifIndex where the neighbor is seen |
| `{#REM_CHASSIS}`, `{#REM_MGMT_IP}`, `{#REM_SYSNAME}` | one of | remote identity, in upsert priority order |
| `{#REM_CHASSIS_TYPE}` | no | chassis id subtype (`mac`, `netaddr`, `local`, …) |
| `{#REM_PORT}` | no | remote port id |
| `{#REM_PORT_TYPE}` | no | port id subtype |
| `{#REM_PORT_DESC}` | no | remote port description (preferred label) |
| `{#IFNAME}` | no | local port name |
| `{#LOC_CHASSIS}` | no | this device's own chassis id |
| `{#SOURCE}` | no | `lldp` / `cdp` / free text; default `lldp` |

**LEARNED_MACS**

| Macro | Req. | Meaning |
|---|---|---|
| `{#IFINDEX}` | yes | local ifIndex |
| `{#MAC}`, `{#PORT_MAC_COUNT}` | one of | a learned MAC, or only the count |
| `{#VLAN}` | no | VLAN / FDB id |

**LAG**

| Macro | Req. | Meaning |
|---|---|---|
| `{#IFINDEX}` | yes | member port ifIndex |
| `{#LAG_IFINDEX}` | yes | aggregate port ifIndex |

**Single source of truth.** Roles and contracts are data in one PHP class
(`CTopologyRole`) and one C table. Each has a comment that points at the
other. Change both together.

### 5.3 Normalized fields

The server maps macros to these field names. Ingest never sees `{#…}`
names.

| Role | Fields |
|---|---|
| PORTS | `if_index`, `name`, `if_type`, `mac`, `admin_status`, `oper_status`, `speed`, `loc_chassis` |
| NEIGHBORS | `if_index`, `if_name`, `rem_chassis`, `rem_chassis_type`, `rem_mgmt_ip`, `rem_sysname`, `rem_port`, `rem_port_type`, `rem_port_desc`, `loc_chassis`, `source` (default `lldp`) |
| LEARNED_MACS | `if_index`, `mac`, `port_mac_count`, `vlan` |
| LAG | `if_index`, `lag_if_index` |

Absent optional fields are omitted, not written as empty strings.

### 5.4 Snapshot table

```
topo_lld_snapshot
  itemid      bigint      PK, FK → items.itemid ON DELETE CASCADE   -- the discovery rule
  hostid      bigint      NOT NULL, FK → hosts.hostid ON DELETE CASCADE
  role        integer     NOT NULL      -- topology_role at write time
  clock       integer     NOT NULL      -- observation time (§5.5)
  rows_hash   varchar(64) NOT NULL
  rows        longtext    NOT NULL      -- JSON array, normalized fields, sorted
  rows_total  integer     NOT NULL      -- rows after the LLD filter
  rows_valid  integer     NOT NULL      -- rows that pass the contract
```

- Server-only table; not synced to proxies. FDB snapshots can be several
  MB.
- `hostid` is stored on purpose: ingest finds reporters with one query.
- One row per rule. Discovered rules (from rule prototypes) have their
  own rows.
- Deleting the rule or the host deletes the snapshot. Topology objects
  are not touched.

### 5.5 Snapshot rules

1. **Written only after successful processing.** A rule that fails
   (unsupported, invalid JSON) keeps its last good snapshot unchanged.
2. **Empty is valid.** `[]`, or all rows filtered out, writes an empty
   snapshot. "No neighbors" and "could not ask" never look the same.
3. **`clock` is the observation time** — the timestamp of the value, not
   the processing time.
4. **`clock` never goes back.** A value with a timestamp older than the
   stored `clock` does not change the snapshot (all rule types; see
   `topology-3-push-delivery.md` §6).
5. **Same data:** same `rows_hash` and role → update `clock`,
   `rows_total`, `rows_valid` only.
6. **Role change** of a rule (any value to any other value, including
   `NONE`) deletes the snapshot of the rule and of its inherited
   children. Ingest also ignores a snapshot whose `role` ≠ the rule's
   current role.
7. **No discard steps.** Every processed value confirms freshness, so
   throttling only makes `clock` less accurate. Enforcement is in the
   delivery specs.
8. Rows are sorted deterministically (by `if_index`, then the other
   fields) before hashing.

### 5.6 Identity normalization

The same device seen through any transport must resolve to one Device.
Identity strings must be byte-identical.

| Value | Normal form |
|---|---|
| MAC (chassis subtype macAddress, `ifPhysAddress`, FDB index, `{#IFMAC}`, `{#MAC}`) | lowercase, colon-separated, 6 octets: `00:1a:2b:3c:4d:5e` |
| Chassis subtype networkAddress | decoded address: IPv4 dotted / IPv6 compressed |
| Chassis subtype local / interfaceName / others | as received, trailing NUL and spaces removed |
| `rem_chassis_type` | `mac` / `netaddr` / `local` / `ifname` / … (fixed strings) |
| Mgmt IP | IPv4 dotted / IPv6 compressed, lowercase |
| Hex-STRING vs STRING | normalize first, then apply the rules above |

Three implementations exist: the C preprocessing step, `push.py` and PHP
ingest. **PHP ingest is the final authority**; the other two must agree
with it. One shared fixture set tests all three.

**Ingest normalization.**
- One PHP function normalizes identity fields of every snapshot row,
  from both transports.
- It runs **first**, before reporter identity (§6.4). Otherwise two
  spellings of one `loc_chassis` count as two values, and the reporter is
  skipped as `identity_ambiguous`.
- On rows from the native step it must change nothing. Test: every golden
  fixture output passes through it unchanged.
- A field with a defined format (`rem_chassis` of type `mac`, `mac`,
  `if_mac`, `rem_mgmt_ip`, `loc_chassis` that looks like a MAC) whose
  value does not fit → row skipped, counted in `rows_identity_invalid`
  per rule. Do not guess.
- `rem_chassis` with no type, or with another type: trim only.
- A `netaddr` value that can't be decoded: keep as received.

### 5.7 Legacy blob path

- A host with only the item `topology.discovery.raw` (old trapper blob)
  is still ingested by the legacy loop. The legacy loop has **none** of
  §7–§9 (observations, replacement, contradiction, device-level links,
  pseudo-port cleanup). Do not extend it.
- A host with snapshots **and** the blob item → snapshots only; the blob
  is ignored; one log line per run.
- Removing the legacy loop is an open decision (§18).

## 6. Ingest

### 6.1 Runs

- Ingest is PHP. It writes `topo_*` directly.
- Start: CLI, or the UI button through `POST /topo/ingest/run`. Both use
  the **same code path**. A **database advisory lock** (MySQL `GET_LOCK`,
  PostgreSQL `pg_try_advisory_lock`) prevents two runs at the same time. A
  file lock does not work: the CLI and PHP-FPM have different temporary
  directories. The HTTP request returns at once; status is read by polling.
- **Full run:** all reporters. **Partial run** (`--zabbix-host`): some
  reporters. A partial run can't know that nothing supports a link, so it
  never supersedes (§8.4), never runs legacy cleanup (§8.6) and never
  deletes observations (§7.3).

### 6.2 Reporters and usable snapshots

- A **usable snapshot** is the latest snapshot of an enabled rule on an
  enabled host, whose `role` = the rule's current role, of a reporter
  that is not skipped by identity (§6.4).
- Reporters = hosts with at least one usable snapshot. Read them from
  `topo_lld_snapshot` grouped by `hostid`. There is no reporter list in
  a config file.
- An unsupported rule keeps its last good snapshot, and that snapshot
  stays usable.
- A re-enabled rule's old snapshot is used again, however old, until its
  next poll. No clock comparison.

### 6.3 Normalization

§5.6.

### 6.4 Reporter identity (`reporter_self`)

1. A Device that already has `represented_by` = this host with
   `matched_by = reporter_self` is the reporter's Device.
2. Otherwise upsert a Device by §6.7.1 keys:
   `chassis_id` ← `loc_chassis` from the host's snapshots (any role);
   `mgmt_ip` ← IP of the host's SNMP interface.
3. More than one distinct `loc_chassis` across the host's snapshots →
   skip the whole reporter, reason `identity_ambiguous`. Do not pick one.
4. No key available → skip the reporter, reason `identity_missing`.
   **Never create a Device without a key.**
5. Set `represented_by` to this host with `matched_by = reporter_self`.
   If the Device already has a different association: log and skip; do
   not replace it.

A Device first seen as somebody's neighbor is reused when it becomes a
reporter (step 2), not duplicated. `reporter_self` does not need
inventory MAC. A Proxy can't be a reporter (§10.1).

### 6.5 Processing order inside one reporter

Self Device → PORTS → LAG → NEIGHBORS → LEARNED_MACS. Snapshots of one
reporter can have different ages. Natural keys make this safe.

### 6.6 PORTS, LAG, LEARNED_MACS

**PORTS**
- Upsert `Port` by `(device_id, if_index)`. Refresh the attrs present in
  the row.
- Map the IANA `if_type` number to `physical` / `lag` / `mgmt` with the
  same classification as `push.py`.
- Ports missing from the snapshot are not deleted.
- Then run the pseudo-port merge (§6.7.3).

**Reporter-side ports from other roles.** A NEIGHBORS, LEARNED_MACS or
LAG row can name an `if_index` that has no Port yet. Upsert a
**confirmed** `Port(device_id, if_index)` with what the row gives
(`if_name`). Never create a pseudo port on the reporter's own side. A
later PORTS snapshot updates the same row.

**LAG** — only if the reporter has a LAG snapshot. A reporter without a
LAG rule: never touch any `lag_id`.
- Upsert the aggregate `Port(device_id, lag_if_index)` with
  `if_type = lag` **before** any member's `lag_id` is written.
- Set members' `lag_id`. Members of this Device that are not in the
  snapshot → `lag_id = NULL` (the snapshot is the full truth for this
  pass).
- Check invariant 1 (§3.1).

**LEARNED_MACS** — only if the reporter has a LEARNED_MACS snapshot.
- Per port: `learned_macs` := the MACs in the snapshot (replace, do not
  append). A row with `port_mac_count` and no `mac` → set
  `learned_mac_count`, `learned_macs = []`.
- Ports of this Device not in the snapshot → `learned_macs = []`,
  `learned_mac_count` removed.
- **Never create a Device from a MAC.** Most ports end at devices without
  LLDP (PCs, phones, printers); a node per MAC is noise. A server with
  LLDP off is therefore invisible to the model. This is intentional,
  incomplete coverage, not filtered bad data.

### 6.7 NEIGHBORS

Each row is one observation. Every observation gets an outcome (§7).

#### 6.7.1 Far Device

- Create a Device only for an LLDP/CDP neighbor that resolves to an
  identity.
- Upsert keys, in order: `chassis_id`, then `mgmt_ip`, then `sysname`
  **scoped to the same reporter and the same local port**.
- Keys `chassis_id` and `mgmt_ip` are global. Device identity does not
  depend on the reporter. Only the weak `sysname` key is scoped.
- When a lower-priority key finds the Device, overwrite the stored
  higher-priority value (a changed `chassis_id` after a card swap is the
  same Device). Refresh every attribute the row gives.
- A Device found by `sysname` gets `matched_by: "sysname"`.
- Known limitation: a sysname-only neighbor that moves to another port
  gets a new Device. The old one stays.
- Devices are never deleted by ingest.

#### 6.7.2 Far port

- Far port identification: prefer `rem_port_desc`; otherwise branch on
  `rem_port_type`. Then match a Port of the far Device by normalized name
  (vendor long/short forms, e.g. `GigabitEthernet0/24` ↔ `Gi0/24`,
  case-insensitive), or create one.
- **Ports are created from advertisements only if the far Device is not a
  reporter.** A reporter's walk is the only description of its ports.
  Otherwise §8.1 applies.
- A far port that can't be identified → §8.1.

#### 6.7.3 Pseudo-port merge

Both halves are required.
1. **Reactive:** when a reporter's real Ports are upserted, look for a
   pseudo port on the same Device with a matching normalized name. One
   match → move its links to the real Port, delete the pseudo port.
2. **Proactive:** before creating a pseudo port for a neighbor, look for a
   real Port of that Device with a matching name. Found → use it.

Zero or several candidates → do nothing.

**Leftover pseudo ports on a reporter.** After the merge, a Device with
real Ports has no pseudo ports left:

| Leftover pseudo port | Result | Counter |
|---|---|---|
| has discovered links | each link converted in place to `device_link` (src = the other side's port, dst = this Device, `port_unmatched`, hint from the advertisement, same edge id); pseudo port deleted | `pseudo_ports_converted` |
| name matches several real ports | not merged; then as the row above | `pseudo_ports_ambiguous` |
| matched real port already has another active link | move refused; then as the first row | `pseudo_ports_move_failed` |
| has a manual link | kept, not converted; reported | `pseudo_ports_kept_manual` |
| has no links | deleted | `pseudo_ports_removed` |

Snapshot path only; the legacy loop does not do this.

### 6.8 Two phases

1. **Collect.** Process all reporters up to the candidate links: resolve
   Devices, Ports, candidates. Do not write links or observations.
2. **Resolve.** Legacy cleanup (§8.6), then per port §8.5, then §8.1–§8.4
   against existing links, with support from all usable NEIGHBORS
   snapshots. Then write links, `superseded_at`, `last_seen*` and
   observations. Then delete stale observations (§7.3).

The result must not depend on reporter order. Compare by natural keys:
node ids can differ between orders.

### 6.9 Duplicate transports

One host with snapshots for the same role from rules of different item
types (SNMP and trapper) — for NEIGHBORS, with the same `source` — →
report in `hosts_duplicate_transport` (host, role, source). Process both.
LLDP by SNMP + CDP by push is not a duplicate.

### 6.10 Convergence check method

Equal row **counts** across repeated runs do not prove convergence. A
create-one/delete-one cycle keeps the counts flat. Compare row **ids**
and values (`lag_id`, `last_seen*`) across 5–7 runs.

### 6.11 Run summary (`/topo/ingest/status`)

`status` (`idle|running|done|error`), `started_at`, `finished_at`, and
per-run counts:
- `devices_created`, `devices_updated`, `ports_created`, `links_created`;
- `reporters_processed`, `reporters_skipped` (with reason),
  `snapshots_ignored_role_mismatch`;
- observations per outcome, `observations_removed`;
- `links_superseded`, `links_revived`, `ports_ambiguous`;
- `manual_links_contradicted`;
- `links_device_level`, `links_refined`, counts per `far_port_reason`;
- `pseudo_ports_*` (§6.7.3) and a detail list;
- `hosts_duplicate_transport`, `rows_identity_invalid`.

## 7. Observations

### 7.1 Table

NEIGHBORS only. Ports, MACs and LAG are the reporter's description of
itself and go straight to `topo_nodes`.

```
topo_observations
  id               bigint       PK
  itemid           bigint       NOT NULL, FK → items.itemid ON DELETE CASCADE   -- the NEIGHBORS rule
  local_port_id    bigint       NOT NULL, FK → topo_nodes.id ON DELETE CASCADE
  remote_key       varchar(255) NOT NULL  -- 'chassis:<type>:<value>' | 'ip:<addr>' | 'sysname:<name>'
  remote_attrs     json/text    NOT NULL  -- rem_* as received (untrusted)
  outcome          varchar(16)  NOT NULL
  edge_id          bigint       NULL, FK → topo_edges.id ON DELETE SET NULL
  device_id        bigint       NULL, FK → topo_nodes.id ON DELETE SET NULL    -- resolved remote Device
  link_precision   varchar(8)   NULL      -- 'port' | 'device'
  far_port_reason  varchar(32)  NULL
  precision_lower  boolean      NOT NULL DEFAULT false   -- §8.3
  first_seen       integer      NOT NULL
  last_seen        integer      NOT NULL  -- snapshot clock
  UNIQUE (itemid, local_port_id, remote_key)
```

`remote_key` uses the strongest key present (chassis → ip → sysname), so
one neighbor seen by one rule always maps to one row.

### 7.2 Outcomes

| Outcome | Meaning | `edge_id` | `device_id` |
|---|---|---|---|
| `applied` | link created or confirmed (either type) | the link | remote Device |
| `device_only` | remote Device resolved, no link created | NULL | remote Device |
| `shadowed` | blocked by a **manual** link (§9) | the manual link | remote Device |
| `conflict` | blocked by a **discovered** link that has support (§8.4) | the blocking link | remote Device |
| `ambiguous` | several neighbors or claims on one port (§8.5), or a merge/match with 0 or many candidates | NULL | NULL or best known |

### 7.3 Lifecycle

- The table mirrors the **latest** snapshot of each rule. After a rule is
  processed, its observations that are not in the snapshot are deleted.
  History lives on links (`last_seen*`).
- `first_seen` is kept while the observation stays present.
- **Full run:** after resolve, delete observations of every NEIGHBORS
  rule without a usable snapshot (disabled rule, disabled host, role
  mismatch, skipped reporter). Count in `observations_removed`.
- **Partial run:** deletes nothing.
- **Read side:** the observations list, contradiction (§9.4) and
  `manual_links_contradicted` use only observations of rules with a usable
  snapshot. Disabling a rule hides its effects before the next ingest.
- Observation cleanup never touches links or `shadow_ack`.
- **Links are results; observations are evidence.** `last_seen*` on a
  link moves only from `applied` observations.

## 8. Link rules

### 8.1 Port-level or device-level

The far Device is resolved (§6.7.1). Then:
- Far port identified → port-level candidate.
- Otherwise a device-level candidate is produced in exactly these cases,
  and **only on a local port that has no active discovered
  `physical_link` to the same far Device** (if it has one, §8.3
  applies):
  - **`port_unmatched`** — the far Device has authoritative ports, and
    the advertised port matches none of them. Do not create a port from
    the advertisement (it would be a phantom: the device calls its port
    `Gi0/1`, the neighbor advertises `1`).
  - **`port_shared_id`** — the advertised port id names several ports:
    the far Device's authoritative ports share it, or two or more local
    ports **of the same reporter** see the same far port id in its latest
    snapshots. Compare within one reporter only.
  - **`lag_ambiguous`** — two or more local ports of one reporter resolve
    to the same far Device, and their advertisements don't single out
    distinct far ports. All of them become device-level. No guessing.
    LAG members whose far ports are identified stay port-level.
  - When `port_shared_id` and `lag_ambiguous` both apply, use
    `port_shared_id`.
- A far Device **without** authoritative ports: its port is created from
  the advertisement (§6.7.2) → port-level.

### 8.2 Refinement: device-level → port-level

Device-level link L = R:P → D. A later full ingest identifies the far
port Q of D for the same observation.
- L is **converted in place**: `type := physical_link`, `dst_id := Q`,
  reason and hint removed, canonicalization applied. Same edge id;
  `last_seen*` carried over. Outcome `applied`. Not a contradiction.
- **Reverse half.** An active `device_link` K = D:Q → R (R = Device of P)
  is the same cable seen from the other side. K is absorbed: deleted, its
  `last_seen` folded into the converted link's side for Q. K does not
  count as far-end occupancy of Q.
- Far-end occupancy (§8.4) applies to Q for any other active link M. M
  has support → no conversion; L stays `device_link`; outcome `conflict`,
  `edge_id` = M.

### 8.3 Lost precision

**A link's precision only goes up.** A `physical_link` never becomes a
`device_link`. The one exception is the pseudo-port correction (§6.7.3):
a link to a pseudo port never had port-level precision.

- A discovered `physical_link` whose far port is no longer identified in
  the latest snapshots, while the far Device is still seen on the local
  port, stays `physical_link` and active. Outcome `applied`,
  `precision_lower: true`.
- This applies for every cause: port not matched, shared port id, LAG
  ambiguity.
- `last_seen*` is updated; `last_seen_port` is not.
- The link shows the flag: "The remote port \<Q\> hasn't been confirmed
  since \<last_seen_port\>; \<D\> is still seen on this port."
- The far port identified again → `precision_lower: false`,
  `last_seen_port` advances.
- The far Device no longer seen either → the link goes stale by §8.7.

`port_lost` is not in the reason enum and no code path writes it. Checked
2026-10-02, before it was removed: 0 `device_link` rows with
`far_port_reason = port_lost` in the `zabbix` database.

### 8.4 Replacement of discovered links

**Terms**
- **Contradiction** of link L on port P: the latest snapshot of some
  NEIGHBORS rule shows on P a neighbor that resolves to a different far
  port, or to a different Device, than L's other end. For a
  `device_link`, compare far **Devices** only: a candidate that resolves
  to the same Device D (any precision) does not contradict L = R:P → D.
- **Support** of L: a usable snapshot of any NEIGHBORS rule **other than
  the contradicting rule** that still contains L, from either end. This
  includes another rule of the same host (CDP when LLDP contradicts).
- Clocks are not compared. A reporter's latest snapshot is its current
  claim, however old.

**Rule** — existing discovered link L = R:P↔B:Q; a latest snapshot shows
X on P (or on Q), X ≠ L's other end:

| Support of L | Result |
|---|---|
| none | L **superseded** at T (T = clock of the contradicting snapshot); new link to X created; X `applied` |
| at least one | L stays; X `conflict`, `edge_id` = L |

- Symmetric: the contradiction can come from either end.
- **Expected transient:** right after a recabling, the far end's latest
  snapshot still contains L → `conflict` until the far end's next poll.
  Delay ≤ one NEIGHBORS interval of the far end. Do not escalate it.
- **Persistent disagreement** (two sides keep reporting different
  things) → stable `conflict`, no replacement, no flapping.
- Manual links are never superseded by this rule (§9.2).

**Far-end occupancy.** The candidate P↔X also contradicts any active
discovered link M on X's port. M is checked by the same rule. If both L
and M exist, the candidate is applied only if both can be superseded;
otherwise X `conflict` with `edge_id` = the link that has support. A
`device_link` occupies no far port.

**Revival.** A superseded link that appears again in a latest snapshot is
a candidate like any other. If the current holder of its ports has
support → `conflict`, the link stays superseded. If not → the holder is
superseded and the old link is revived: `superseded_at` cleared, same
edge id. A link is replaced only when **nothing** reports it.

**Superseded state**
- `attrs.superseded_at` = T. Not active: ignored by port uniqueness;
  treated as stale whatever `last_seen*` says.
- Not deleted. Manual deletion works as for any link.
- `last_seen*` stay frozen.

**Known limitation.** With three or more competing claims where some
ports already hold links, a losing claim can block a weaker one. Stable,
no flapping, not optimal.

### 8.5 Several neighbors or claims on one port

LLDP can show several neighbors on one port (unmanaged switch, phone with
a PC behind it, hypervisor with VMs). These are not a sequence, so no
order picks a winner. This section has priority over §8.4 for that port.

- **Several neighbors in one snapshot,** resolving to different Devices:
  - the existing active link on P is one of them → it stays (`applied`);
    the others `ambiguous`;
  - otherwise → no link created or replaced on P; all `ambiguous`.
- **Same Device in several observations** (LLDP and CDP with different
  port ids) → one link, at the best precision any of them reaches.
- **Competing new claims from different rules** on a port P with **no
  active link** (X and Y both report R:P; or R's LLDP shows X and R's CDP
  shows Y), each still in its rule's latest snapshot, different far ends →
  no link on P; all `ambiguous`. If P has an active link, §8.4 applies to
  each candidate.
- `ambiguous` observations have no `edge_id`.

### 8.6 Legacy cleanup

Older data can break port uniqueness. Every full run, in phase 2 before
any decision, for every port, whether or not it has a new candidate:
- **Two or more active discovered links on one port:** keep the one that
  some latest snapshot still contains; if none or several, keep the one
  with the newest `last_seen`. The others → superseded,
  `superseded_at` = max(their `last_seen`, the latest clock that
  confirmed them).
- **An active discovered link next to a manual link** on one port → the
  discovered link is superseded. Its observation, if still reported, is
  `shadowed`.

A second run over the same data changes nothing.

### 8.7 Disappearance and staleness

- Links are never deleted by ingest. A link that is no longer reported
  keeps its last `last_seen*`.
- A link is **stale** when `last_seen` is older than the freshness
  threshold (§8.8), or when it is superseded. Staleness is computed at
  read time; it is not stored.
- Any link can be deleted manually. A deleted discovered link is created
  again if discovery reports it again.
- A reporter whose neighbor data could not be collected keeps its last
  snapshot (§5.5 rule 1), so its links don't disappear.

### 8.8 Freshness threshold

Status: not implemented. Today the value 7 days is a literal in
`CTopologyPrototype.php` (`STALE_LINK_SECONDS`, used by `isLinkStale()`).
The ingest constant `TOPO_LINK_FRESH_SECONDS` was removed with the old
downgrade (§8.3).

- **Setting:** global macro `{$TOPOLOGY.LINK.FRESH.SECONDS}` (table
  `globalmacro`). No new table, no file, no restart. The lab can change it
  through the API.
- **One definition:** one small file without Zabbix dependencies (e.g.
  `database/topology/topology_freshness.inc.php`) holds the macro name,
  the default, the minimum and one function that turns the raw macro text
  into seconds. Each caller reads the raw text its own way (PDO in ingest,
  `DBselect` in the UI). No other place holds the number or the name.
- **Values:** default 604800 (7 days); minimum 60; digits only. Any other
  text, or a value below 60 → default, and a warning that names the macro
  and the rejected text (escaped). Missing macro → default, no warning.
- **Read** once per ingest run and once per UI request, not per link.
- Ingest does not need the threshold (§8.3); the UI is the only reader.
  If ingest ever reads it again, it reads the same macro through the same
  definition.
- Only the derived `stale` boolean goes to the browser.

### 8.9 `last_seen` sources

- `last_seen*` come from the snapshot `clock` of `applied` observations.
  Two ingest runs without new snapshots must not advance any value.
- Two rules confirming one side (LLDP and CDP of one reporter): that
  side's value = the max clock of all `applied` observations for the
  link from that reporter.

## 9. Manual links

### 9.1 Creation

- An operator can create a manual `physical_link` between two existing
  Ports, or a manual `device_link` from an existing Port to an existing
  Device (`far_port_reason = manual`, no hint).
- This never creates a Device or a Port.
- The endpoint applies canonicalization (§4.2) and port uniqueness
  (§4.4).
- The operator can convert a manual `device_link` to a manual
  `physical_link` by choosing the far port, and back. Same edge id.
- Any link, manual or discovered, can be deleted manually.

### 9.2 Manual wins

- Ingest never deletes, supersedes or changes a manual link or its
  `shadow_ack`. No expiry.
- A discovered observation that contradicts a manual link → `shadowed`.
- A discovered link on the same port → superseded (§8.6).

### 9.3 Confirmation by discovery

Discovery that confirms a manual link turns it into a discovered link, in
place, same edge id. From then on it follows the discovered rules.

| Manual link | Discovery sees on its local port | Result |
|---|---|---|
| `device_link` R:P → D | D, device level | discovered `device_link`, reason from discovery |
| `device_link` R:P → D | D:Q, port level | discovered `physical_link` P↔Q (§8.2) |
| `physical_link` R:P↔D:Q | D:Q, port level | discovered `physical_link` |
| `physical_link` R:P↔D:Q | D, device level only | **stays manual**; observation `shadowed`, not contradicted |

**Exception:** a manual link (either type) with at least one `shadow_ack`
entry stays manual and keeps its acks. It stays manual until the operator
revokes all acks.

### 9.4 Contradiction

**Terms**
- **Shadowing observation** of manual link M: an observation with
  `outcome = shadowed` and `edge_id` = M.
  - From an end of M: the **hidden neighbor** is its `device_id`.
  - **Third-party:** from reporter D that is not an end of M, where the
    remote port of the observation is an end port of M. The **hidden
    neighbor** is the reporter D, not `device_id` (`device_id` is an end
    of M). Status: not implemented.
- Observations mirror the latest snapshots, so "current" needs no clock.

**State *contradicted*** (derived at read time, not stored): M is
contradicted if at least one shadowing observation of M (third-party
included) has a hidden neighbor that is not in `shadow_ack`.
- A shadowing observation without a hidden neighbor (NULL) counts as
  unacknowledged; no ghost.
- If some latest snapshot also contains M (the far end confirms it), M is
  still contradicted. The details show "confirmed from \<far end\>".
- A manual `physical_link` seen by discovery at device level with the
  same Device is not contradicted (§9.3).

**Why manual links are not replaced automatically:** a contradicting
neighbor can be legitimate (unmanaged switch forwarding LLDP, PC behind a
phone, VMs). The operator decides once, per hidden neighbor.

### 9.5 Acknowledgments

```
attrs.shadow_ack = [
  { "device_id": <hidden neighbor Device id>,
    "remote_key": "<display/audit only>",
    "at": <unix time>,
    "by": "<Zabbix username>" }
]
```

- Keyed by **Device**. Devices are never deleted, so the id is stable. A
  different Device on the same port → contradicted again.
- An ack stays after its observation disappears (a flickering neighbor
  does not raise the signal again). Removed only by revoke or with the
  link.
- `remote_key` of a third-party ack: `port:<reporter identity key>:<if_index>`,
  where the reporter identity key uses the same kind-prefixed form
  (e.g. `port:chassis:-:00:11:22:33:44:12:1`). Not a node id.
- Ingest never reads or writes `shadow_ack`. Ingest writes to other attrs
  of M must keep it byte-identical.

### 9.6 Operator actions

Manual links only. Same permission as manual link editing.
- **Accept discovery** (only while contradicted): delete M (same code path
  as manual deletion). The next full ingest from stored snapshots
  decides; a superseded discovered link for the same port pair is revived
  with its old edge id, otherwise a link is created. Several hidden
  neighbors → normal rules decide (possibly `ambiguous`). If the UI has a
  way to start a full ingest, run it after the delete and return its
  summary; otherwise return "applies at next ingest".
- **Keep manual** (per hidden neighbor): append an ack. Idempotent: the
  same `device_id` twice → one entry, the first is kept.
- **Revoke** (per ack): remove the entry. If that neighbor still shadows
  M, M is contradicted again at once.

Rendering: §12.4.

## 10. Host and Proxy association

### 10.1 Lazy Host/Proxy nodes

- A Host or Proxy gets a `topo_nodes` row only when it has a reason: a MAC
  match (§10.2), `reporter_self` (§6.4) or manual `/promote` (§10.3).
- Most Zabbix hosts never become topology nodes. There is no
  "unassociated hosts" list.
- **Proxy:** no inventory, can't own items, so no MAC match and no
  `reporter_self`. Manual `/promote` is the only path. If the proxy's
  machine must be a reporter, onboard it as a separate Host.

### 10.2 Automatic MAC match (Host only)

- Manual run (CLI / UI "Pull Zabbix hosts"). `host.get` with
  `selectInventory`. `proxy.get` is not called.
- Match a neighbor Device to a Host by MAC only:
  `host.inventory.macaddress_a` / `macaddress_b`. Not chassis id (Zabbix
  has no chassis-id field), not `host.interfaces` (no MAC there), not
  hostname or IP.
- Inventory MAC exists only when inventory is automatic and a
  `system.hw.macaddr` item fills it. No MAC → silent non-match. Manual
  `/promote` covers the rest.
- On a match: upsert the Host node and set `represented_by`
  (`matched_by = mac`). No match → write nothing.

### 10.3 Manual `/promote` and `/depromote`

- `/promote` takes a Zabbix `hostid` (or `proxyid`) from the search
  endpoints. It upserts the Host/Proxy node, then sets the association
  (`matched_by = manual`). It does not check MAC. It fails if either side
  already has an association.
- `/depromote` clears the three columns. The Device stays. The Host/Proxy
  node is deleted unless it has another reason to exist (MAC match,
  `reporter_self`).

## 11. API

Every endpoint that returns a Host or Proxy joins `hosts` / `proxy` live.
Every endpoint with port items joins `items` live. List endpoints batch
live joins in one query per result set, never one query per node.

| Endpoint | Purpose |
|---|---|
| `GET /topo/devices` | Device, Host, Proxy nodes for the graph. Host: live `maintenance_status`, monitoring assignment (`monitored_by`, `proxyid`, `proxy_groupid`, `assigned_proxyid`, resolved target node id). |
| `GET /topo/devices/{id}/neighbors` | 1-hop neighbors through links (both types). Ports are hidden. Per link: type, `severity`, `last_seen`, `stale`, `superseded_at`, `precision_lower`, `far_port_reason`, hint, contradiction state. |
| `GET /topo/devices/{id}/ports` | Port table grouped: Connected / Partial connectivity evidence (MAC only) / Disconnected / Port-channel / Management. |
| `GET /topo/nodes/{id}/problems` | Active problems of a Host node. Not for Proxy. |
| `GET /topo/hosts/search?q=`, `GET /topo/proxies/search?q=` | Search Zabbix directly (`host.get`, `proxy.get`). Pickers for `/promote`. |
| `POST /topo/devices/{id}/promote {host_id \| proxy_id}` | §10.3. |
| `POST /topo/devices/{id}/depromote` | §10.3. |
| `POST /topo/ports/{port_id}/link {dst_port_id \| dst_device_id}` | Manual link (§9.1). |
| `DELETE` link (by edge id or endpoints) | Manual deletion, any link. |
| Manual-link actions: accept discovery, keep manual (per Device), revoke (per Device) | §9.6. Follow the existing routing and permission conventions. |
| `POST /topo/ingest/run`, `GET /topo/ingest/status` | §6.1, §6.11. |
| `GET /topo/observations` | Filters: `outcome`, `device_id`, `contradicted=1`, `precision=device`. Default: everything except `applied`. Fields: local port name, reporter host name (live), `remote_attrs` (escaped), blocking edge, `age`, `precision`, `far_port_reason`, `acknowledged` for `shadowed` rows. |

## 12. UI

### 12.1 Graph

- Nodes: Device, Host, Proxy. Ports are never graph nodes.
- Expansion through `/neighbors`, not a full dump.
- A Device with an active association is not drawn. Its Host/Proxy is
  drawn, and links attach to it. The Device data moves into the
  Host/Proxy side panel.
- Global controls: "Pull Zabbix hosts", "Run discovery ingest" (spinner
  while running, summary at the end, the UI is not blocked).

### 12.2 Node styles

| Node | Style |
|---|---|
| Host | solid fill, severity colour |
| Host, disabled | neutral / muted fill (no severity) |
| Host in maintenance | severity colour + maintenance badge |
| Host behind an unreachable Proxy (or ProxyGroup offline) | blind-spot badge, not problem colour |
| Proxy | solid fill, distinct icon |
| Device without association | dashed outline, grey fill |
| Hidden neighbor of a contradicted manual link | contradiction badge (§12.4) |

### 12.3 Link channels

Each channel is independent. Never put two meanings on one channel.

| Channel | Meaning |
|---|---|
| Dash pattern | provenance: solid = discovered, dashed = manual |
| Colour / weight | severity (§4.8) |
| Opacity (≈40–50 %) | stale or superseded (§8.7) |
| End shape / distinct style | device-level link: ends at the Device border |
| Flag | lost precision (§8.3 text) |

- Superseded link tooltip: "Replaced on \<date\>" and what replaced it,
  when known.
- Device-level tooltip: the reason text (§4.3) with the hint.
- **LAG bundling:** device-level links between the same two Devices
  (either direction) render as one line with a count and the known local
  ports ("2 links: Gi0/1, Gi0/2 ↔ ?"). Storage stays per port.
- Host↔Proxy monitoring assignment: its own line style and colour, from
  live data. `represented_by` is never drawn as a line.

### 12.4 Contradicted manual links

- **Badges** (only while contradicted), style distinct from stale,
  superseded and conflict:
  - on M: "Discovery sees \<neighbor\> on \<local port\> (since
    \<first_seen\>)", one line per unacknowledged hidden neighbor;
  - on each unacknowledged hidden neighbor, whether it has other links or
    not: "Link hidden by manual link \<A port\> ↔ \<B\> on \<reporter\>".
    For a third-party observation the badge goes on the reporter D, not on
    the end of M.
- **Ghost link** (only while unacknowledged, observation with a Device):
  a dashed line from the Device of `local_port_id` to `device_id`; for a
  third-party observation, from D's local port to the end Device of M.
  Label from `rem_port` / `rem_port_desc` (escaped). Several observations
  for one Device pair → one ghost. Not a `topo_edges` row; click opens
  M's details. Ends at the Device border for a device-level observation.
- While M is contradicted, a superseded edge for the same port pair is
  **not drawn**; only the ghost is drawn. After the ack, the ghost goes
  and the superseded edge renders as usual.
  Status: not implemented (third-party case and this rendering rule).
- **Details of M:** created date/user if stored (no new columns);
  unacknowledged hidden neighbors with *Accept discovery* / *Keep manual*;
  acknowledged neighbors "kept by \<by\> on \<at\>" with *Revoke*;
  "confirmed from \<far end\>".
- **Details of a hidden neighbor:** the manual links that hide it,
  acknowledged ones included (no badge for those).

### 12.5 Side panels

- **Device without association:** port table (§11), "Promote to host".
- **Host / Proxy:** Problems section (Host only; empty state when there
  are none); Device section with the port table and "Depromote" when an
  association exists.

## 13. Security: untrusted strings

Any device on the LAN can announce arbitrary LLDP/CDP content. Treat as
untrusted, everywhere it is shown: Device `sysname`, `chassis_id`,
`vendor`; Port `name`; `remote_attrs`; `far_port_hint`; ghost labels;
`learned_macs`; warning texts that quote macro values.

- Escape at render time, or use `textContent`, never `innerHTML`. No
  exception for "trusted internal network".
- One shared escaping helper (or the framework default). Enable a lint
  rule for unsafe DOM writes if the stack has one.
- Store as received; escape at render.

## 14. Seed fixture

A static fixture loads a 48-port switch for UI checks: 40 connected ports
(2 with LLDP neighbors, 38 MAC-only), 4 down, 2 LAG ports with 2 members
each, 2 management ports. One neighbor has `sysname` =
`<script>alert(1)</script>`. Two modes: default upsert (tests §6.7.1
keys), `--reset` (empty tables first; tests nothing about upsert).

The lab simulator (`topology-4-lab-simulator.md`) is the main test tool
for changing data.

## 15. Acceptance criteria

Unless stated, snapshots are written directly into `topo_lld_snapshot`
with fixed clocks, and ingest runs as a real process. Compare by natural
keys, check convergence by ids (§6.10).

**Nodes and association**
- Deleting a Device deletes its Ports. Deleting a Zabbix Host with
  topology data works through the whole cascade.
- A Host that matches a Device by inventory MAC gets `represented_by`
  (`mac`) after the pull. A Host without a match and never promoted has
  no `topo_nodes` row. A Proxy never gets an automatic association.
- `/promote` to a Host without a node creates the node and the
  association in one call. A second `/promote` onto an associated
  Host/Proxy is rejected.
- `/depromote` deletes the Host node unless the Host is a reporter.
- A reporter without inventory MAC gets `represented_by` with
  `reporter_self`.
- Reassigning a host to another proxy in Zabbix shows on the next
  `/topo/devices` call, with no ingest.
- Invariants 1–3 (§3.1) hold after every write path.

**Ingest**
- Convergence: 5–7 runs over unchanged snapshots keep the same row ids
  and the same `last_seen*`.
- Order independence: two reporter orders give identical results.
- Only a NEIGHBORS snapshot present → the reporter-side Port is created
  confirmed; a later PORTS snapshot updates the same id; no pseudo port
  on the reporter's side.
- Reporter identity: two `loc_chassis` values → `identity_ambiguous`; no
  key → `identity_missing`; no keyless Device.
- Changed `chassis_id` with the same `mgmt_ip` → same Device, updated.
- Neighbor becomes reporter (A sees B; later B reports; B reports A):
  Device(B) reused, `reporter_self` set, pseudo port merged, one link,
  checked by id.
- Two reporters that see each other converge to one `physical_link`
  between real ports, whatever runs first.
- LAG: no LAG rule → `lag_id` untouched; member removed from the snapshot
  → `lag_id = NULL`.
- LEARNED_MACS replaces the list; a port above the limit gets
  `learned_mac_count` and an empty list; no Device from any MAC.
- LLDP and CDP of one reporter confirm one link → one link; the side's
  `last_seen*` = the newer clock. A CDP link renders as discovered.
- Host with snapshots and the blob item → snapshots only, one log line.
  Host with only the blob → legacy loop, unchanged.
- Ingest normalization: a push PORTS row with
  `loc_chassis` in upper case with dashes and an LLD row with the normal
  form on one host → one reporter Device; a 5-octet MAC row → skipped,
  `rows_identity_invalid`; native output unchanged.
- Duplicate transport: LLDP by SNMP + LLDP by push →
  both processed, reported; LLDP by SNMP + CDP by push → not reported.
- Leftover pseudo ports: each row of the §6.7.3 table, with its counter.

**Observations**
- Shadowing case → `shadowed`, `edge_id` = manual link, `device_id` =
  neighbor.
- A neighbor that leaves the snapshot → its observation is deleted on the
  next ingest; the link keeps frozen `last_seen*`.
- A disabled rule (or host) → its observations are hidden at once and
  deleted by the next full run; a partial run deletes nothing.

**Link rules**
- Recable, far end not a reporter: old link superseded at the snapshot
  clock; new link active, `applied`.
- Recable, both ends reporters: the far end still reports the old link
  (older, equal or newer clock) → old link stays, `conflict`; the far
  end's next snapshot without it → replacement.
- Persistent disagreement, 6 alternating snapshots → `links_superseded`
  and `links_revived` = 0 on runs 2–6.
- Contradiction from the far end: replaced if unsupported, `conflict` if
  supported.
- Far-end occupancy: supported M blocks the candidate (`conflict`,
  `edge_id` = M); unsupported M is superseded.
- LLDP vs CDP on one reporter with an existing link → `conflict`.
- Revival: old link reported again while the new holder is still
  reported → `conflict`; holder no longer reported → old link revived,
  same edge id.
- Two neighbors on one port: existing link among them stays, other
  `ambiguous`; none among them → no change, all `ambiguous`.
- Competing new claims on a port without a link → no link, all
  `ambiguous`, no `edge_id`; one claim leaves → link created.
- Partial run: unsupported contradiction → `conflict`, nothing
  superseded, no legacy cleanup.
- Legacy data: two active discovered links on one port (one in a
  snapshot / none) and manual + discovered → resolved as §8.6; a second
  run changes nothing.
- Port uniqueness: after every full run, at most one non-superseded link
  per port, both types and manual links counted; device-level links
  occupy no far port.
- **Device-level:**
  - phantom avoided: D is a reporter with `Gi0/1`, R sees D advertising
    `1` → `device_link` `port_unmatched`, no new port on D;
  - D not a reporter → port created from the advertisement, port-level;
  - shared chassis MAC on two local ports → two `device_link`s,
    `port_shared_id`;
  - shared chassis MAC, one cable, both reporters → R:P → D and
    D:Q → R, one bundled line;
  - LAG with unusable ids → four `device_link`s, one bundled line
    "2 links"; one leg resolved → that leg port-level;
  - refinement → same edge id, now `physical_link`; with reverse half →
    one link, reverse half deleted, no `conflict`, 5 more runs change
    nothing; blocked by supported M → stays `device_link`, `conflict`;
  - contradiction by Device: R:P → D, R reports E on P, no support → link
    superseded, R:P↔E created;
  - type validation rejects wrong endpoints; unknown reasons rejected;
    `fdb_mac_only` accepted, never produced.
- **Lost precision:** port-level R:P↔D:Q; later
  snapshots no longer identify Q while D is still seen on P → same edge,
  still `physical_link`, `applied` with `precision_lower: true`,
  `last_seen` advances, `last_seen_port` does not, flag shown; Q
  identified again → `precision_lower: false`, `last_seen_port` advances;
  D disappears → stale, never `device_link`; an active `physical_link` to
  D blocks a new `device_link` on P; no code writes `port_lost`; existing
  refinement tests pass.
- **Freshness (not implemented):** no numeric literal for the threshold
  in topology code; macro absent → behaviour unchanged; changing the
  macro changes the UI (and ingest, while it reads it) with no restart;
  `abc`, `-5`, `7d`, `59` → default and a warning.

**Manual links**
- Manual link survives seed reload and discovery runs.
- Manual `physical_link` confirmed at port level → discovered, same id.
  Manual `device_link` confirmed at device / port level → discovered
  `device_link` / `physical_link`, same id. Manual `physical_link` seen
  at device level only → stays manual, no badge. A manual link with an
  ack is never converted.
- `/link` on a port that already has an active link is rejected; a
  reversed pair hits the uniqueness check, not a duplicate.
- Lab case (Router1 Gi0/1 manual → UPS1, LLDP shows Printer1): manual
  stays; Printer1 `shadowed`; M contradicted; badges on M and Printer1;
  ghost Router1–Printer1.
- Keep manual (Printer1) → no badge, no ghost; details list the ack; 5
  runs change nothing. Printer2 appears → contradicted again (Printer2
  only). Printer1 flickers → stays acknowledged. Revoke while it still
  shadows → badge and ghost back.
- Accept discovery → M deleted; after a full ingest Router1 Gi0/1 ↔
  Printer1 active, same edge id if a superseded one existed.
- Far end confirms M → still contradicted, "confirmed from UPS1".
- Shadow from the far end: manual R:P↔B:Q, B sees D on Q → badge on D,
  ghost B–D.
- Hidden neighbor with other links still gets the badge.
- Ingest keeps `shadow_ack` byte-identical; no ingest run changes a
  manual link.
- **Third party (not implemented):**
  - UPS1 without LLDP (`omit_mib: lldp`), M = Router1:Gi0/1 ↔ UPS1:eth0,
    Router1 sees Printer1 on Gi0/1, Switch2 sees UPS1:eth0 → two
    shadowing observations (Printer1 near end, Switch2 third party);
    hidden neighbors Printer1 and Switch2; badges on M, Printer1,
    Switch2; **no badge on UPS1**; ghosts Router1–Printer1 and
    Switch2–UPS1; superseded edges of these pairs not drawn;
  - UPS1 with LLDP → three shadowing observations, same two hidden
    neighbors, one badge and one ghost each;
  - keep manual for Switch2 only → M still contradicted; Switch2 badge
    and ghost gone; superseded Switch2–UPS1 edge renders;
  - accept discovery → Router1–Printer1 and Switch2–UPS1 active, with the
    edge ids they had before M.

**UI and security**
- Disabled host: muted. Host in maintenance with a problem: severity
  colour + maintenance badge. Hosts behind an unreachable proxy:
  blind-spot badge.
- A stale link is faded whatever its dash and colour; a fresh one is not.
- Superseded link: faded, "Replaced on …".
- Device-level link ends at the Device border; bundle label; hint
  escaped.
- `<script>` and `<img onerror>` payloads in every untrusted field render
  as inert text in labels, tables, tooltips, ghosts.
- UI ingest button while a CLI run is active → rejected by the lock.

## 16. Scaling notes (not required now)

1. `GET /topo/devices` must become a search entry point, not a full dump.
2. Add generated, indexed columns for `attrs->>'mac'` (MAC match) and
   `attrs->>'chassis_id'`, `mgmt_ip` (Device upsert); put `UNIQUE` on
   `chassis_id`.
3. Batch every live join (no N+1).
4. Port rows grow with every switch (1000 × 48 ≈ 48 000). This is volume,
   not query cost (port reads are scoped by `device_id`). No retention
   now.

## 17. Decisions

### 17.1 Decision log (current state only)

| Date | Decision |
|---|---|
| 2026-09 | Relational model (dedicated tables), not tags. |
| 2026-09 | Physical topology is on-prem first; not a near-term Cloud priority. |
| 2026-09-30 | Collection through LLD with `topology_role` + macro contract; no separate Topology tab. |
| 2026-09-30 | Discovered links: replaced by the latest snapshots, not by time; replaced link kept as superseded. Several neighbors on one port in one poll → ambiguous. |
| 2026-09-30 | Manual links are never replaced automatically. Contradiction is shown and resolved per neighbor, not by time. |
| 2026-10-02 | Two transports (LLD, push) feed one snapshot path and one reconciliation. |
| 2026-10-02 | Ingest accepts LLD values as a bare array and as legacy `{"data":[...]}`; native steps emit a bare array. |
| 2026-10-02 | Device-level links as a separate edge type `device_link`; refinement in place. Manual device-level links allowed. `fdb_mac_only` reserved. |
| 2026-10-02 | A manual link confirmed by discovery becomes discovered (both types); exceptions §9.3. |
| 2026-10-02 | A third-party neighbor that reports a port of a manual link contradicts it. |
| 2026-10-02 | Precision only goes up; lost precision is a flag, not a type change. |
| 2026-10-02 | One configurable freshness threshold (global macro); not blocking. |
| 2026-10 | `represented_by` stored on the Device node, not as an edge. |
| 2026-10-03 | The lab reads derived state (`stale`, contradiction, badges) with a read-only PHP helper that runs `CTopologyPrototype` (simulator spec §11). |

### 17.2 Review answers

**"Why not Zabbix Network Discovery for LLDP?"** Its SNMP check reads one
OID as an alive probe; it does not walk tables and does not keep results
(`zbx_snmp_get_values() num:1`). Walking and storing tables is the job of
items + LLD — which is what this project uses. Network Discovery is used
only to onboard reporter Hosts.

**"Why not store topology in tags?"**
- Tags exist only on Zabbix objects. An unmonitored neighbor would need a
  fake Host.
- Tags are flat strings, not typed relationships with attributes.
- No referential integrity: a renamed target leaves a dangling tag.
- No adjacency for traversal.
- Precedent: `zabbix-AutoMapper` (Zabbix Summit 2024) uses host tags for
  a narrower problem, and its author calls tags convenient but not
  designed for this.

Tags are only shown in the UI (backlog), never used to store the graph.

## 18. Open questions and backlog

**Open — decide before the related work**
- Legacy blob loop: remove after push equivalence passes (push spec §7).
- FR 4.h: an identifier (e.g. MAC) that matches more than one Device
  identifies none; the neighbor is not linked. Needs a small spec.
- Periodic ingest: still manual.
- Surfacing rows dropped by the native step (LLD spec §9).
- Normalization: if the C step and the JS reference differ, fixing it
  changes existing chassis ids; needs a migration or a re-ingest decision.

**Backlog (deferred on purpose)**
- `represented_by` 1:1 constraint — the most likely redesign (split
  identity, stacks, MLAG).
- Reciprocal pairing; FDB-based links.
- LAG membership as an edge (trigger in §4.7).
- Manual Device creation for passive and SNMP-silent devices
  (`attrs.source: "manual"`, with at least one manual Port).
- Wireless AP → client: needs its own relationship type.
- Device type/role from LLDP capability bits.
- Hosting dependency (`hosted_by`) — needed by any future blast-radius
  work.
- Admission policies (which neighbors become Devices).
- `Service` tree as a second projection; Perspectives only after that.
- Proxy groups: blind spot must use `ProxyGroup.state`, not
  `assigned_proxyid` reachability.
- Tag display; indicator icon for Host/Proxy with a Device; node position
  persistence with optimistic concurrency; continuous utilisation
  colouring; discovery-quality view; vendor variance notes.
- Out of scope, not backlog: Zabbix trigger dependencies.

## 19. Appendix: FR v1.1 lines (Topology model)

```
1.b.i.1. CONNECTED_TO (physical link between ports)
1.b.i.3. CONNECTED_TO_DEVICE (physical link from a port to a device whose
         port can't be identified)

d.ii. topo_edges.type: physical_link / device_link
      device_link: src = port, dst = device; attrs as physical_link plus
      the reason the remote port is unknown
      REPRESENTED_BY is stored on the device node (represented_by_node_id)

4.g. If the remote device is identified but its port is not, a
     CONNECTED_TO_DEVICE link is created. It becomes CONNECTED_TO as soon
     as the remote port is identified. The reason the port is unknown is
     shown with the link.
4.h. An identifier (e.g. MAC address) that matches more than one device
     identifies none of them; the neighbor is not linked to any of them.
4.i. Ports of a device are created from neighbor advertisements only if the
     device is not itself a reporter.

5.d. A reporter whose neighbor data could not be collected keeps its last
     reported neighbors; links disappear only when collected data no
     longer contains them.
5.e. A link between a port and a device becomes a link between two ports
     as soon as the remote port is identified. A link between two ports is
     never reduced to a port-to-device link; if the remote port is no
     longer confirmed, the link is kept and marked as not confirmed since
     the last confirmation.
5.f. CONNECTED_TO_DEVICE links can be created manually; manual links of
     both kinds follow the same rules (manual wins, contradiction shown).

Lifecycle f. A port with a manual link reports a different neighbor:
   i. the manual link stays; the discovered neighbor is recorded as shadowed;
   ii. the manual link and the hidden neighbor are marked as contradicted
       by discovery until the operator resolves it;
   iii. accept discovery: the manual link is removed and the discovered
        link is created from the latest snapshots;
   iv. keep manual: the contradiction is acknowledged for this neighbor;
       a different neighbor marks the link as contradicted again;
   v. a neighbor that reports a port of the manual link as its own
      neighbor also contradicts the manual link, even if it is not an end
      of the link.
```
