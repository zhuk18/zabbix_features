-- topology-1-model.md §7.2 (2.60, task 078): an observation `old_snapshot` has no local Port when §6.7.6 does not resolve one. The column local_port_id of
-- topo_observations may be NULL. The unique key (itemid, local_port_id, remote_key) holds only for resolved ports; ingest finds a row without a port by
-- (itemid, remote_key). Idempotent: running it again changes nothing.
ALTER TABLE topo_observations MODIFY local_port_id BIGINT UNSIGNED NULL;
