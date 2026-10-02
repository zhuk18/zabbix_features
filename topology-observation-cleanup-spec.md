# Topology — observations of rules without a usable snapshot (as built, v1)

Addendum to `topology-lld-part2-spec.md` §7 (observation lifecycle).
Implemented in `2201ad594db`; this file records the agreed rule and the
as-built behaviour.

## Problem

Part 2 §7 deletes a rule's observations only after processing that rule.
Snapshots skipped by ingest (Part 2 §5.1) are never processed, so their
observations stayed forever and kept affecting results (a `shadowed` row
of a disabled LLDP rule kept a manual link contradicted; `conflict` /
`ambiguous` rows stayed in diagnostics).

## Rule

1. **Usable snapshot:** the latest snapshot of an enabled rule on an
   enabled host, with `role` = the rule's current role, of a reporter not
   skipped by identity (Part 2 §5.2).
2. **Full run:** after resolve, delete observations of every NEIGHBORS
   rule without a usable snapshot in this run. Counter
   `observations_removed` in `/topo/ingest/status`.
3. **Partial run** (`--zabbix-host`): deletes nothing (can't tell "not
   selected" from "unusable").
4. **Read side:** observations list, contradictions and
   `manual_links_contradicted` consider only observations of rules with a
   usable snapshot, so disabling a rule hides its effects before the
   next ingest.
5. **Support** in link replacement (`topology-link-replacement-spec.md`
   §2) is built only from usable snapshots.
6. Unsupported rule: keeps its last good snapshot, which stays usable
   (Part 2 §2 rule 2).
7. Never touched: edges, `shadow_ack`.

## Known behaviour

A re-enabled rule's last snapshot is used again as its current claim,
however old (no clock comparison), until the rule's next poll.

## Open (at time of writing)

- Disabled **host**: confirm it's covered by rule 1 in ingest, read side
  and tests.
