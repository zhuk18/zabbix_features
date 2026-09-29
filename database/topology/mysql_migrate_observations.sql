-- topo_observations: the evidence behind NEIGHBORS rows of LLD snapshots (topology-lld-part2-spec.md §7).
-- Edges are results, observations are evidence: one row per (rule, local port, remote identity), mirroring
-- the LATEST snapshot of each NEIGHBORS rule. Rows are written by ingest.php only.
--
-- edge_id/device_id are ON DELETE SET NULL (a deleted edge or Device must not delete the evidence that
-- pointed at it); itemid/local_port_id cascade (the rule or the local port is gone, so is its evidence).
CREATE TABLE IF NOT EXISTS topo_observations (
	id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
	itemid BIGINT UNSIGNED NOT NULL,
	local_port_id BIGINT UNSIGNED NOT NULL,
	remote_key VARCHAR(255) NOT NULL,
	remote_attrs JSON NOT NULL,
	outcome VARCHAR(16) NOT NULL,
	edge_id BIGINT UNSIGNED NULL,
	device_id BIGINT UNSIGNED NULL,
	first_seen INT UNSIGNED NOT NULL,
	last_seen INT UNSIGNED NOT NULL,
	PRIMARY KEY (id),
	UNIQUE KEY topo_observations_uq (itemid, local_port_id, remote_key),
	KEY topo_observations_1 (outcome),
	KEY topo_observations_2 (local_port_id),
	KEY topo_observations_3 (edge_id),
	KEY topo_observations_4 (device_id),
	CONSTRAINT topo_observations_1 FOREIGN KEY (itemid) REFERENCES items (itemid) ON DELETE CASCADE,
	CONSTRAINT topo_observations_2 FOREIGN KEY (local_port_id) REFERENCES topo_nodes (id) ON DELETE CASCADE,
	CONSTRAINT topo_observations_3 FOREIGN KEY (edge_id) REFERENCES topo_edges (id) ON DELETE SET NULL,
	CONSTRAINT topo_observations_4 FOREIGN KEY (device_id) REFERENCES topo_nodes (id) ON DELETE SET NULL
) ENGINE=InnoDB;
