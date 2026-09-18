# Zabbix Topology — Tags-only Model

## 0. Objective

Implement a simplified topology model in which **Zabbix Host tags are the only persistent topology storage**.

There must be **no topology database tables** and no separate persisted `Device`, `Port`, or `Link` entities.

Topology is derived from the current tags of Zabbix Hosts.

The goal is to provide:

* discovery of physical/L2 topology from LLDP/CDP observations;
* topology visualization;
* representation of monitored devices by Zabbix Hosts;
* unmanaged neighbor devices;
* manual promotion of an unmanaged device to an existing Zabbix Host;
* manual de-promotion;
* basic topology metadata and port/neighbor information.

This is intentionally a simpler model than the previous `Device`/`Port`/`physical_link` model.

---

# 1. Architectural model

The persistent model is:

```text
Zabbix Host
    │
    ├── topology.identity
    ├── topology.type
    ├── topology.port.*
    └── topology.neighbor.*
             │
             ▼
       Topology builder
             │
             ▼
        Derived graph
```

There are no persistent topology nodes or edges.

A monitored device is represented by a Zabbix Host.

An unmanaged device exists only as a **derived node** created from neighbor information reported by monitored Hosts.

---

# 2. Ownership

Zabbix remains the owner of Host lifecycle.

Topology uses Host tags as its topology data source.

Topology must not maintain a separate topology database.

Topology-specific tags are divided conceptually into two categories:

### Discovery data

Written/updated by the topology discovery mechanism:

```text
topology.chassis_id
topology.type
topology.port.*
topology.neighbor.*
```

### Identity association

Used for explicit association of an unmanaged topology identity with a Host:

```text
topology.identity
```

The implementation must clearly distinguish these two concepts.

`topology.identity` is an explicit association created by a user through **promote**.

---

# 3. Device identity

The primary topology identity is:

```text
topology.chassis_id = <chassis-id>
```

When a device reports itself as a topology reporter, its Host receives/contains:

```text
topology.chassis_id = <id>
```

For unmanaged devices, the identity comes from the neighbor observation:

```text
topology.neighbor.<ifIndex>.chassis_id = <id>
```

If a chassis ID is available, it is the preferred identity.

The topology builder must use the same chassis ID to merge observations from multiple reporters.

Example:

```text
Switch1
  topology.neighbor.24.chassis_id = AA:BB:CC:DD:EE:FF

Switch2
  topology.neighbor.12.chassis_id = AA:BB:CC:DD:EE:FF
```

Both observations must produce the same derived topology node:

```text
AA:BB:CC:DD:EE:FF
```

No separate database entity is created.

---

# 4. Host identity association

A Zabbix Host can explicitly claim a topology identity using:

```text
topology.identity = <chassis-id>
```

Example:

```text
Host: Switch2

topology.identity = AA:BB:CC:DD:EE:FF
```

This means:

> The Zabbix Host represents the topology device identified by `AA:BB:CC:DD:EE:FF`.

The topology builder must resolve neighbor observations against `topology.identity`.

For example:

```text
Switch1
  topology.neighbor.24.chassis_id = AA:BB:CC:DD:EE:FF

Host Switch2
  topology.identity = AA:BB:CC:DD:EE:FF
```

produces:

```text
Switch1 ─── Switch2
```

rather than:

```text
Switch1 ─── unmanaged device
```

---

# 5. Promote

`Promote` associates an unmanaged topology identity with an existing Zabbix Host.

Example initial state:

```text
Switch1
   │
   │ Gi0/24
   │
   ▼
[unmanaged]
chassis_id = AA:BB:CC:DD:EE:FF
```

The user selects:

```text
Promote → Host: Switch2
```

The operation must add/update:

```text
Switch2
topology.identity = AA:BB:CC:DD:EE:FF
```

No topology database record is created.

On the next topology rebuild, the unmanaged node is resolved to `Switch2`.

### Promote validation

Reject the operation if:

1. the target Host already has a different `topology.identity`;
2. the requested topology identity is already explicitly associated with another Host;
3. the selected identity does not exist in the currently derived topology, unless the API explicitly supports promoting an identity not currently visible.

For MVP, require the unmanaged identity to be currently present.

---

# 6. De-promote

`De-promote` removes the explicit association:

```text
topology.identity = <chassis-id>
```

from the Host.

After the next topology rebuild, if the device is still visible through LLDP/CDP, it becomes an unmanaged node again.

Example:

```text
Before:

Switch1 ─── Switch2
             ↑
       topology.identity=X
```

After de-promote:

```text
Switch1 ─── [unmanaged X]
```

No topology entity needs to be deleted or recreated because unmanaged nodes are derived.

---

# 7. Host tags

Use the following topology tag structure.

## 7.1 Device-level tags

```text
topology.chassis_id = <value>
topology.type = <value>
```

`topology.chassis_id` identifies the topology identity reported by the Host.

`topology.type` is optional metadata such as:

```text
switch
router
firewall
access-point
```

Do not depend on `topology.type` for identity resolution.

---

# 8. Port tags

Ports are not topology entities.

They are encoded as Host tag groups using the Zabbix interface index.

Format:

```text
topology.port.<ifIndex>.<property>
```

Example:

```text
topology.port.24.name = Gi0/24
topology.port.24.status = up
topology.port.24.mac = 00:11:22:33:44:24
```

Minimum required property:

```text
topology.port.<ifIndex>.name
```

Optional properties:

```text
topology.port.<ifIndex>.mac
topology.port.<ifIndex>.status
topology.port.<ifIndex>.speed
topology.port.<ifIndex>.type
```

`ifIndex` is the stable key within a reporter's port inventory.

Do not create separate persistent Port IDs.

---

# 9. Neighbor tags

Each reporter stores its current neighbor observations using:

```text
topology.neighbor.<ifIndex>.<property>
```

The `<ifIndex>` is the local reporter port.

Minimum required properties:

```text
topology.neighbor.<ifIndex>.chassis_id
topology.neighbor.<ifIndex>.port
```

Optional:

```text
topology.neighbor.<ifIndex>.name
topology.neighbor.<ifIndex>.port_name
topology.neighbor.<ifIndex>.port_id
```

Example:

```text
topology.neighbor.24.chassis_id = AA:BB:CC:DD:EE:FF
topology.neighbor.24.port = Gi0/1
topology.neighbor.24.name = Switch2
```

This means:

```text
<current Host>:port 24
        ↓
AA:BB:CC:DD:EE:FF:Gi0/1
```

---

# 10. Derived topology graph

The graph must be generated from Host tags.

For every Host:

1. Read `topology.chassis_id`.
2. Read its `topology.port.*` tags.
3. Read its `topology.neighbor.*` tags.
4. Create a derived graph node for the Host.
5. Resolve every neighbor.

### Neighbor resolution

If neighbor `chassis_id` matches:

```text
topology.chassis_id
```

or:

```text
topology.identity
```

of a Host, use that Host as the destination.

Otherwise create an unmanaged derived node.

Example:

```text
Host A
topology.chassis_id = A

Host A
topology.neighbor.24.chassis_id = B
topology.neighbor.24.port = Gi0/1

Host B
topology.chassis_id = B
```

Result:

```text
A:Gi0/24 ─── B:Gi0/1
```

---

# 11. Unmanaged nodes

An unmanaged node must **not be persisted**.

It exists only while at least one current neighbor observation refers to it.

Example:

```text
Host A
  topology.neighbor.24.chassis_id = X
```

produces:

```text
A ─── X
```

If the tag disappears:

```text
topology.neighbor.24.*
```

then `X` disappears from the derived graph unless another Host still reports it.

This eliminates the previous need for:

* `Device` rows;
* `Port` rows;
* unconfirmed Ports;
* merge operations;
* Device garbage collection.

---

# 12. Link construction

Links are also derived.

A link exists whenever a neighbor observation points from one topology identity to another.

Example:

```text
A
neighbor.24 → B:Gi0/1
```

creates:

```text
A:Gi0/24 ─── B:Gi0/1
```

If B also reports:

```text
B
neighbor.1 → A:Gi0/24
```

the renderer must recognize these as the same logical connection and render only one link.

The implementation must canonicalize the pair:

```text
min(endpointA, endpointB)
max(endpointA, endpointB)
```

for rendering/deduplication.

There is no persistent `physical_link` record.

---

# 13. Reciprocal observations

Reciprocal LLDP observations are expected.

Example:

```text
Switch1
  neighbor.24 → Switch2:Gi0/1

Switch2
  neighbor.1 → Switch1:Gi0/24
```

The topology must render:

```text
Switch1:Gi0/24 ─── Switch2:Gi0/1
```

not two links.

If only one side reports the relationship:

```text
Switch1
  neighbor.24 → X
```

render:

```text
Switch1:Gi0/24 ─── X
```

The link does not require a reciprocal observation.

---

# 14. Reporter onboarding

A reporter is simply a Zabbix Host that has topology discovery tags.

Topology must not maintain a separate reporter registry.

Reporter lifecycle remains owned by Zabbix.

The discovery mechanism should continue to use the existing topology discovery infrastructure from the prototype:

```text
Reporter
   ↓
topology discovery
   ↓
Host tags
```

The topology component reads the resulting Host tags and builds the graph.

---

# 15. Discovery update semantics

Discovery must update the reporter's topology tags as a **replacement of its current topology snapshot**.

For example, if the previous state contains:

```text
topology.neighbor.24.*
topology.neighbor.25.*
```

and the next discovery result contains only:

```text
topology.neighbor.24.*
```

then all `topology.neighbor.25.*` tags must be removed.

The same applies to port tags.

This is important because tags represent **current topology state**, not historical observations.

---

# 16. No topology JSON tag

Do **not** store the complete discovery result as:

```text
topology.observation = <JSON>
```

The purpose of this model is to make topology state directly represented by tags.

The implementation must unpack the discovery result into individual tags.

---

# 17. Identity changes

If a reporter's chassis ID changes:

```text
old:
topology.chassis_id = A

new:
topology.chassis_id = B
```

the Host remains the same Zabbix Host.

The topology graph simply sees the Host under identity `B`.

Do not create a persistent second Device because there are no persistent Device objects.

Existing neighbor observations referencing `A` will no longer resolve to that Host unless another Host claims `A`.

---

# 18. Host recreation

If a Zabbix Host is deleted and recreated:

```text
Old Host
topology.identity = X
```

the topology association is lost together with the Host.

The identity `X` may subsequently appear as an unmanaged node if another reporter still observes it.

This is an intentional consequence of the tags-only model.

If persistence of topology identity across Host recreation is required, that is outside the scope of this model and would require an independent persistence mechanism.

---

# 19. Multiple Hosts representing one device

For MVP, `topology.identity` must be unique across Hosts.

Therefore:

```text
Host A
topology.identity = X

Host B
topology.identity = X
```

must be rejected or treated as an invalid topology configuration.

This preserves a deterministic mapping:

```text
topology identity → one Host
```

Support for multiple Zabbix Hosts representing one physical device is **out of scope for this model**.

---

# 20. Multiple topology identities per Host

A Host may have only one:

```text
topology.identity
```

This keeps the mapping intentionally simple:

```text
Host 1 ↔ topology identity
```

More complex representations such as stacked switches, MLAG members, BMC + OS host, etc. are out of scope.

---

# 21. UI

The topology graph must expose:

### Monitored node

Render the Zabbix Host itself.

Its topology identity is resolved from:

```text
topology.identity
```

or, for a reporter that has not been explicitly promoted:

```text
topology.chassis_id
```

### Unmanaged node

Render:

* chassis ID;
* discovered name, if available;
* vendor/type information if available;
* reporting Hosts/ports.

Do not create a Zabbix Host automatically.

### Port information

Port details are derived from:

```text
topology.port.*
```

and neighbor information from:

```text
topology.neighbor.*
```

---

# 22. API

The implementation should expose operations conceptually equivalent to:

```text
GET /topology
```

Build the current graph from Host tags.

```text
POST /topology/promote
```

Input:

```json
{
  "identity": "AA:BB:CC:DD:EE:FF",
  "hostid": "12345"
}
```

Effect:

```text
Host 12345
topology.identity = AA:BB:CC:DD:EE:FF
```

```text
POST /topology/depromote
```

Input:

```json
{
  "hostid": "12345"
}
```

Effect:

Remove:

```text
topology.identity
```

from the Host.

The API must use the normal Zabbix API for Host tag modification.

No topology-specific persistence layer is allowed.

---

# 23. Security

All values originating from LLDP/CDP must be treated as **untrusted network input**.

This includes:

* chassis ID;
* sysname;
* port name;
* vendor;
* neighbor name;
* other discovered strings.

They must be safely escaped when rendered in the UI.

The previous prototype explicitly identified LLDP/CDP values as untrusted network-sourced input, so this remains a mandatory requirement.

---

# 24. What is deliberately removed

The following concepts from the previous topology model must **not** be implemented:

```text
Device table
Port table
topo_nodes
topo_edges
physical_link persistence
represented_by persistence
unconfirmed Port
Port merge
Device merge
Device garbage collection
topology database
```

There is also no need for persistent:

```text
last_seen_src
last_seen_dst
```

because the tags represent the current discovery snapshot.

---

# 25. Acceptance criteria

### Basic discovery

Given:

```text
Switch1
  neighbor.24 → Switch2:Gi0/1
```

the graph contains:

```text
Switch1 ─── Switch2
```

if Switch2 has a matching topology identity.

### Unmanaged device

Given:

```text
Switch1
  neighbor.24.chassis_id = X
```

with no Host claiming `X`:

```text
Switch1 ─── X
```

must be displayed as an unmanaged node.

### Promote

After:

```text
POST /topology/promote
identity=X
hostid=Switch2
```

the Host contains:

```text
topology.identity=X
```

and the graph changes from:

```text
Switch1 ─── X
```

to:

```text
Switch1 ─── Switch2
```

without creating any topology database object.

### De-promote

After de-promote:

```text
topology.identity=X
```

is removed.

If `X` is still observed:

```text
Switch1 ─── X
```

appears again.

### Reciprocal discovery

Given:

```text
A → B
B → A
```

only one link is rendered.

### Disappearance

If the neighbor tags disappear from all reporters, the unmanaged node and corresponding link disappear from the graph.

### Stable identity

Repeated graph rebuilds from unchanged Host tags must produce the same topology identities and connections.

---

# 26. Architectural principle

The implementation should follow this principle throughout:

> **Zabbix Hosts and their tags are the persistent state. The topology graph is a derived view.**

Do not introduce persistence simply because a topology concept does not have a direct tag representation.

If a concept cannot be represented naturally using the simplified tag model, first consider whether that concept is actually required by the simplified topology scope.

Only introduce a new mechanism if it is explicitly justified as a requirement.

---

## Final model

The entire persistent topology model is essentially:

```text
Host
│
├── topology.chassis_id
├── topology.type
│
├── topology.identity          ← manual association
│
├── topology.port.<ifIndex>.*
│
└── topology.neighbor.<ifIndex>.*
```

Everything else is derived:

```text
                    Host tags
                       │
                       ▼
               Topology resolver
                       │
             ┌─────────┴─────────┐
             ▼                   ▼
        Host nodes          unmanaged nodes
             │                   │
             └─────────┬─────────┘
                       ▼
                  derived links
                       │
                       ▼
                  topology graph
```

This is the crucial difference from the current DB model: **there is no topology state underneath the graph. The graph itself is the projection of the current Zabbix tag state.**
