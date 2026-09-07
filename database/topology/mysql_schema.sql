CREATE TABLE IF NOT EXISTS topo_nodes (
	id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
	type VARCHAR(32) NOT NULL,
	host_ref BIGINT UNSIGNED NULL,
	proxy_ref BIGINT UNSIGNED NULL,
	attrs JSON NOT NULL,
	created_at INT UNSIGNED NOT NULL DEFAULT 0,
	updated_at INT UNSIGNED NOT NULL DEFAULT 0,
	PRIMARY KEY (id),
	KEY topo_nodes_1 (type),
	UNIQUE KEY topo_nodes_host_ref_uq (host_ref),
	UNIQUE KEY topo_nodes_proxy_ref_uq (proxy_ref),
	CONSTRAINT topo_nodes_1 FOREIGN KEY (host_ref) REFERENCES hosts (hostid) ON DELETE CASCADE,
	CONSTRAINT topo_nodes_3 FOREIGN KEY (proxy_ref) REFERENCES proxy (proxyid) ON DELETE CASCADE
) ENGINE=InnoDB;

-- represented_by_* / physical_link_* are STORED GENERATED COLUMNS, NULL for every edge except the one type
-- they're named after — see mysql_migrate_uniqueness.sql's header comment for why (MySQL has no partial
-- unique index, so the constrained edge type is projected into its own column and the UNIQUE goes on that).
CREATE TABLE IF NOT EXISTS topo_edges (
	id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
	type VARCHAR(32) NOT NULL,
	src_id BIGINT UNSIGNED NOT NULL,
	dst_id BIGINT UNSIGNED NOT NULL,
	attrs JSON NOT NULL,
	created_at INT UNSIGNED NOT NULL DEFAULT 0,
	represented_by_src BIGINT UNSIGNED GENERATED ALWAYS AS (CASE WHEN type = 'represented_by' THEN src_id END) STORED,
	represented_by_dst BIGINT UNSIGNED GENERATED ALWAYS AS (CASE WHEN type = 'represented_by' THEN dst_id END) STORED,
	physical_link_src BIGINT UNSIGNED GENERATED ALWAYS AS (CASE WHEN type = 'physical_link' THEN src_id END) STORED,
	physical_link_dst BIGINT UNSIGNED GENERATED ALWAYS AS (CASE WHEN type = 'physical_link' THEN dst_id END) STORED,
	PRIMARY KEY (id),
	KEY topo_edges_1 (src_id, type),
	KEY topo_edges_2 (dst_id, type),
	UNIQUE KEY topo_edges_represented_by_src_uq (represented_by_src),
	UNIQUE KEY topo_edges_represented_by_dst_uq (represented_by_dst),
	UNIQUE KEY topo_edges_physical_link_pair_uq (physical_link_src, physical_link_dst),
	CONSTRAINT topo_edges_1 FOREIGN KEY (src_id) REFERENCES topo_nodes (id) ON DELETE CASCADE,
	CONSTRAINT topo_edges_2 FOREIGN KEY (dst_id) REFERENCES topo_nodes (id) ON DELETE CASCADE
) ENGINE=InnoDB;
