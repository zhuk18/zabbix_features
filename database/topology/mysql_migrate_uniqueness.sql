-- §2.1/§5 uniqueness constraints. MySQL has no partial/filtered unique index, so a represented_by/physical_link
-- edge's src/dst id is projected into a STORED GENERATED COLUMN that is NULL for every other edge type, and the
-- UNIQUE index goes on that generated column instead — MySQL (like Postgres) allows unlimited NULLs under a
-- UNIQUE constraint, so non-represented_by/non-physical_link rows never collide with each other, only rows of
-- the constrained type collide on genuine duplicates. On PostgreSQL, use a real partial index instead:
--   CREATE UNIQUE INDEX ON topo_edges (src_id) WHERE type = 'represented_by';
--   CREATE UNIQUE INDEX ON topo_edges (dst_id) WHERE type = 'represented_by';
--   CREATE UNIQUE INDEX ON topo_edges (src_id, dst_id) WHERE type = 'physical_link';

DELIMITER //

CREATE PROCEDURE topology_migrate_uniqueness()
BEGIN
	DECLARE has_col INT DEFAULT 0;
	DECLARE has_key INT DEFAULT 0;

	-- topo_nodes.host_ref / proxy_ref: plain UNIQUE, works as-is (both engines allow multiple NULLs under UNIQUE).
	SELECT COUNT(*) INTO has_key FROM information_schema.statistics
		WHERE table_schema = DATABASE() AND table_name = 'topo_nodes' AND index_name = 'topo_nodes_host_ref_uq';
	IF has_key = 0 THEN
		ALTER TABLE topo_nodes ADD UNIQUE KEY topo_nodes_host_ref_uq (host_ref);
	END IF;

	SELECT COUNT(*) INTO has_key FROM information_schema.statistics
		WHERE table_schema = DATABASE() AND table_name = 'topo_nodes' AND index_name = 'topo_nodes_proxy_ref_uq';
	IF has_key = 0 THEN
		ALTER TABLE topo_nodes ADD UNIQUE KEY topo_nodes_proxy_ref_uq (proxy_ref);
	END IF;

	-- represented_by: at most one edge per src (Device) and at most one per dst (Host/Proxy).
	SELECT COUNT(*) INTO has_col FROM information_schema.columns
		WHERE table_schema = DATABASE() AND table_name = 'topo_edges' AND column_name = 'represented_by_src';
	IF has_col = 0 THEN
		ALTER TABLE topo_edges ADD COLUMN represented_by_src BIGINT UNSIGNED
			GENERATED ALWAYS AS (CASE WHEN type = 'represented_by' THEN src_id END) STORED;
	END IF;

	SELECT COUNT(*) INTO has_col FROM information_schema.columns
		WHERE table_schema = DATABASE() AND table_name = 'topo_edges' AND column_name = 'represented_by_dst';
	IF has_col = 0 THEN
		ALTER TABLE topo_edges ADD COLUMN represented_by_dst BIGINT UNSIGNED
			GENERATED ALWAYS AS (CASE WHEN type = 'represented_by' THEN dst_id END) STORED;
	END IF;

	SELECT COUNT(*) INTO has_key FROM information_schema.statistics
		WHERE table_schema = DATABASE() AND table_name = 'topo_edges' AND index_name = 'topo_edges_represented_by_src_uq';
	IF has_key = 0 THEN
		ALTER TABLE topo_edges ADD UNIQUE KEY topo_edges_represented_by_src_uq (represented_by_src);
	END IF;

	SELECT COUNT(*) INTO has_key FROM information_schema.statistics
		WHERE table_schema = DATABASE() AND table_name = 'topo_edges' AND index_name = 'topo_edges_represented_by_dst_uq';
	IF has_key = 0 THEN
		ALTER TABLE topo_edges ADD UNIQUE KEY topo_edges_represented_by_dst_uq (represented_by_dst);
	END IF;

	-- physical_link: at most one edge per unordered port pair — the application canonicalizes src_id as the
	-- numerically smaller port id before insert (CTopologyPrototype::upsertPhysicalLink()), so a plain
	-- composite unique on (src_id, dst_id) restricted to this edge type is sufficient; no need to also generate
	-- the reverse pair.
	SELECT COUNT(*) INTO has_col FROM information_schema.columns
		WHERE table_schema = DATABASE() AND table_name = 'topo_edges' AND column_name = 'physical_link_src';
	IF has_col = 0 THEN
		ALTER TABLE topo_edges ADD COLUMN physical_link_src BIGINT UNSIGNED
			GENERATED ALWAYS AS (CASE WHEN type = 'physical_link' THEN src_id END) STORED;
	END IF;

	SELECT COUNT(*) INTO has_col FROM information_schema.columns
		WHERE table_schema = DATABASE() AND table_name = 'topo_edges' AND column_name = 'physical_link_dst';
	IF has_col = 0 THEN
		ALTER TABLE topo_edges ADD COLUMN physical_link_dst BIGINT UNSIGNED
			GENERATED ALWAYS AS (CASE WHEN type = 'physical_link' THEN dst_id END) STORED;
	END IF;

	SELECT COUNT(*) INTO has_key FROM information_schema.statistics
		WHERE table_schema = DATABASE() AND table_name = 'topo_edges' AND index_name = 'topo_edges_physical_link_pair_uq';
	IF has_key = 0 THEN
		ALTER TABLE topo_edges ADD UNIQUE KEY topo_edges_physical_link_pair_uq (physical_link_src, physical_link_dst);
	END IF;
END//

CALL topology_migrate_uniqueness()//
DROP PROCEDURE topology_migrate_uniqueness//

DELIMITER ;
