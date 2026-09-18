CREATE TABLE IF NOT EXISTS topo_nodes (
	id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
	type VARCHAR(32) NOT NULL,
	host_ref BIGINT UNSIGNED NULL,
	proxy_ref BIGINT UNSIGNED NULL,
	-- Set only when type='port' — the owning Device's topo_nodes.id. Port->Device is a stable 1:many
	-- relationship, so it's a plain FK column rather than a generic topo_edges row (see
	-- mysql_migrate_device_id.sql's header comment for why this replaced the old 'part_of' edge type).
	device_id BIGINT UNSIGNED NULL,
	-- Set only when type='device' — the Host/Proxy topo_nodes.id this Device is represented by. Plain
	-- self-referential FK, not a topo_edges row (see mysql_migrate_represented_by.sql's header comment
	-- for why this replaced the old 'represented_by' edge type). matched_by/at are provenance for the
	-- CURRENT representation only — both NULL exactly when represented_by_node_id is NULL (§2.3).
	represented_by_node_id BIGINT UNSIGNED NULL,
	represented_by_matched_by VARCHAR(16) NULL,
	represented_by_at INT UNSIGNED NULL,
	attrs JSON NOT NULL,
	created_at INT UNSIGNED NOT NULL DEFAULT 0,
	updated_at INT UNSIGNED NOT NULL DEFAULT 0,
	PRIMARY KEY (id),
	KEY topo_nodes_1 (type),
	KEY idx_topo_nodes_device_id (device_id),
	UNIQUE KEY topo_nodes_host_ref_uq (host_ref),
	UNIQUE KEY topo_nodes_proxy_ref_uq (proxy_ref),
	UNIQUE KEY topo_nodes_represented_by_node_id_uq (represented_by_node_id),
	CONSTRAINT topo_nodes_1 FOREIGN KEY (host_ref) REFERENCES hosts (hostid) ON DELETE CASCADE,
	CONSTRAINT topo_nodes_3 FOREIGN KEY (proxy_ref) REFERENCES proxy (proxyid) ON DELETE CASCADE,
	CONSTRAINT topo_nodes_4 FOREIGN KEY (device_id) REFERENCES topo_nodes (id) ON DELETE CASCADE,
	CONSTRAINT topo_nodes_5 FOREIGN KEY (represented_by_node_id) REFERENCES topo_nodes (id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- The old physical_link_src/physical_link_dst STORED GENERATED COLUMNS (MySQL has no partial unique
-- index, so a constrained edge type used to be projected into its own column with the UNIQUE on
-- that — see mysql_migrate_uniqueness.sql's header comment for the original rationale) are gone:
-- `represented_by` has since moved off topo_edges entirely onto topo_nodes.represented_by_node_id
-- (see the comment above and mysql_migrate_represented_by.sql), so topo_edges effectively holds only
-- `physical_link` rows now — `member_of_lag` (as written by database/topology/seed.php) is stale,
-- dead-on-write code that nothing in the read path (CTopologyPrototype.php, ingest.php) queries for;
-- flagged separately, not fixed here, since it's a different problem than this schema simplification.
-- With one live edge type left, generating a type-filtered duplicate of src_id/dst_id just to hang a
-- UNIQUE off of achieves nothing a plain UNIQUE(src_id, dst_id) doesn't already give for free — see
-- mysql_migrate_simplify_physical_link_uniqueness.sql for the migration off the old columns.
CREATE TABLE IF NOT EXISTS topo_edges (
	id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
	type VARCHAR(32) NOT NULL,
	src_id BIGINT UNSIGNED NOT NULL,
	dst_id BIGINT UNSIGNED NOT NULL,
	attrs JSON NOT NULL,
	created_at INT UNSIGNED NOT NULL DEFAULT 0,
	PRIMARY KEY (id),
	KEY topo_edges_1 (src_id, type),
	KEY topo_edges_2 (dst_id, type),
	UNIQUE KEY topo_edges_src_dst_uq (src_id, dst_id),
	CONSTRAINT topo_edges_1 FOREIGN KEY (src_id) REFERENCES topo_nodes (id) ON DELETE CASCADE,
	CONSTRAINT topo_edges_2 FOREIGN KEY (dst_id) REFERENCES topo_nodes (id) ON DELETE CASCADE
) ENGINE=InnoDB;
