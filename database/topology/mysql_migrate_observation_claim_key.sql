-- topology-1-model.md §7.1 (task 079): the identity of an observation is the claim (itemid, local_port_ref, remote_key). local_port_ref is NOT NULL (the row's own reference to the
-- port: `ifindex:<n>` or `num:<n>`), the unique key has claim columns only, outcome is varchar(32). Upgrade as §7.1 says: the table is emptied; the next full run rebuilds it from the
-- snapshots, so first_seen starts again once. MariaDB (IF NOT EXISTS). The new key is made before the old one goes: the foreign key on itemid needs an index.
DELETE FROM topo_observations;
ALTER TABLE topo_observations ADD COLUMN IF NOT EXISTS local_port_ref VARCHAR(64) NOT NULL AFTER itemid;
ALTER TABLE topo_observations ADD UNIQUE KEY topo_observations_uq_claim (itemid, local_port_ref, remote_key);
ALTER TABLE topo_observations DROP INDEX topo_observations_uq;
ALTER TABLE topo_observations RENAME INDEX topo_observations_uq_claim TO topo_observations_uq;
ALTER TABLE topo_observations MODIFY outcome VARCHAR(32) NOT NULL;
