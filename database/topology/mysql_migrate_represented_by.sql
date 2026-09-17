-- `represented_by` moves from a topo_edges row to three columns directly on the Device's topo_nodes
-- row (represented_by_node_id/represented_by_matched_by/represented_by_at). Unlike physical_link, it's
-- directed, single-valued (at most one active representation per Device), not confirmed independently
-- by multiple reporters, and has no reconciliation lifecycle of its own — it doesn't need the generic
-- edge table. See topology-prototype-spec.md §2.1/§2.3 for the full rationale and the two invariants
-- (target must be host/proxy; provenance fields only alongside a non-NULL represented_by_node_id) this
-- shape depends on.
DELIMITER //

CREATE PROCEDURE topology_migrate_represented_by()
BEGIN
	DECLARE has_col INT DEFAULT 0;
	DECLARE has_key INT DEFAULT 0;
	DECLARE has_fk INT DEFAULT 0;
	DECLARE has_old_col INT DEFAULT 0;
	DECLARE has_old_key INT DEFAULT 0;

	SELECT COUNT(*) INTO has_col
	FROM information_schema.columns
	WHERE table_schema = DATABASE() AND table_name = 'topo_nodes' AND column_name = 'represented_by_node_id';

	IF has_col = 0 THEN
		ALTER TABLE topo_nodes
			ADD COLUMN represented_by_node_id BIGINT UNSIGNED NULL AFTER device_id,
			ADD COLUMN represented_by_matched_by VARCHAR(16) NULL AFTER represented_by_node_id,
			ADD COLUMN represented_by_at INT UNSIGNED NULL AFTER represented_by_matched_by;
	END IF;

	-- Data migration: for every existing 'represented_by' topo_edges row, copy it onto the Device
	-- (src_id) row, then drop the edge. attrs->>'matched_by' / attrs->>'created_at' are the old edge's
	-- provenance fields (§2.3's old attrs shape); attrs->>'matched_mac' is deliberately NOT migrated —
	-- it has no consumer in the new column-based model (see the spec addendum for why).
	UPDATE topo_nodes device
		JOIN topo_edges rep ON rep.type = 'represented_by' AND rep.src_id = device.id
	SET device.represented_by_node_id = rep.dst_id,
		device.represented_by_matched_by = JSON_UNQUOTE(JSON_EXTRACT(rep.attrs, '$.matched_by')),
		device.represented_by_at = CAST(JSON_UNQUOTE(JSON_EXTRACT(rep.attrs, '$.created_at')) AS UNSIGNED)
	WHERE device.type = 'device' AND device.represented_by_node_id IS NULL;

	DELETE FROM topo_edges WHERE type = 'represented_by';

	SELECT COUNT(*) INTO has_key
	FROM information_schema.statistics
	WHERE table_schema = DATABASE() AND table_name = 'topo_nodes' AND index_name = 'topo_nodes_represented_by_node_id_uq';

	IF has_key = 0 THEN
		ALTER TABLE topo_nodes ADD UNIQUE KEY topo_nodes_represented_by_node_id_uq (represented_by_node_id);
	END IF;

	SELECT COUNT(*) INTO has_fk
	FROM information_schema.table_constraints
	WHERE constraint_schema = DATABASE() AND table_name = 'topo_nodes'
		AND constraint_name = 'topo_nodes_5' AND constraint_type = 'FOREIGN KEY';

	IF has_fk = 0 THEN
		ALTER TABLE topo_nodes ADD CONSTRAINT topo_nodes_5
			FOREIGN KEY (represented_by_node_id) REFERENCES topo_nodes (id) ON DELETE SET NULL;
	END IF;

	-- Drop the now-obsolete generated-column uniqueness workaround for the old edge-based model
	-- (mysql_migrate_uniqueness.sql) — represented_by is no longer a topo_edges row, so nothing
	-- projects onto these columns anymore.
	SELECT COUNT(*) INTO has_old_key
	FROM information_schema.statistics
	WHERE table_schema = DATABASE() AND table_name = 'topo_edges' AND index_name = 'topo_edges_represented_by_src_uq';
	IF has_old_key > 0 THEN
		ALTER TABLE topo_edges DROP KEY topo_edges_represented_by_src_uq;
	END IF;

	SELECT COUNT(*) INTO has_old_key
	FROM information_schema.statistics
	WHERE table_schema = DATABASE() AND table_name = 'topo_edges' AND index_name = 'topo_edges_represented_by_dst_uq';
	IF has_old_key > 0 THEN
		ALTER TABLE topo_edges DROP KEY topo_edges_represented_by_dst_uq;
	END IF;

	SELECT COUNT(*) INTO has_old_col
	FROM information_schema.columns
	WHERE table_schema = DATABASE() AND table_name = 'topo_edges' AND column_name = 'represented_by_src';
	IF has_old_col > 0 THEN
		ALTER TABLE topo_edges DROP COLUMN represented_by_src;
	END IF;

	SELECT COUNT(*) INTO has_old_col
	FROM information_schema.columns
	WHERE table_schema = DATABASE() AND table_name = 'topo_edges' AND column_name = 'represented_by_dst';
	IF has_old_col > 0 THEN
		ALTER TABLE topo_edges DROP COLUMN represented_by_dst;
	END IF;
END//

CALL topology_migrate_represented_by()//
DROP PROCEDURE topology_migrate_represented_by//

DELIMITER ;
