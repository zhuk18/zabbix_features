-- topo_edges.physical_link_src/physical_link_dst were STORED GENERATED COLUMNS duplicating
-- src_id/dst_id (NULL for every row except type='physical_link') — a MySQL workaround for the lack
-- of a partial unique index, from back when topo_edges also held `represented_by` rows and the
-- uniqueness constraint needed to be scoped to one type without colliding with the other (see
-- mysql_migrate_uniqueness.sql). `represented_by` moved off topo_edges onto
-- topo_nodes.represented_by_node_id in mysql_migrate_represented_by.sql, so topo_edges now
-- effectively holds only `physical_link` rows — a plain UNIQUE(src_id, dst_id) gives the identical
-- constraint with no duplicate storage. Drop the generated columns and the old constrained-column
-- unique key, replace with a plain composite unique key.
DELIMITER //

CREATE PROCEDURE topology_migrate_simplify_physical_link_uniqueness()
BEGIN
	DECLARE has_old_key INT DEFAULT 0;
	DECLARE has_old_col INT DEFAULT 0;
	DECLARE has_new_key INT DEFAULT 0;

	SELECT COUNT(*) INTO has_old_key
	FROM information_schema.statistics
	WHERE table_schema = DATABASE() AND table_name = 'topo_edges' AND index_name = 'topo_edges_physical_link_pair_uq';
	IF has_old_key > 0 THEN
		ALTER TABLE topo_edges DROP KEY topo_edges_physical_link_pair_uq;
	END IF;

	SELECT COUNT(*) INTO has_old_col
	FROM information_schema.columns
	WHERE table_schema = DATABASE() AND table_name = 'topo_edges' AND column_name = 'physical_link_src';
	IF has_old_col > 0 THEN
		ALTER TABLE topo_edges DROP COLUMN physical_link_src;
	END IF;

	SELECT COUNT(*) INTO has_old_col
	FROM information_schema.columns
	WHERE table_schema = DATABASE() AND table_name = 'topo_edges' AND column_name = 'physical_link_dst';
	IF has_old_col > 0 THEN
		ALTER TABLE topo_edges DROP COLUMN physical_link_dst;
	END IF;

	SELECT COUNT(*) INTO has_new_key
	FROM information_schema.statistics
	WHERE table_schema = DATABASE() AND table_name = 'topo_edges' AND index_name = 'topo_edges_src_dst_uq';
	IF has_new_key = 0 THEN
		ALTER TABLE topo_edges ADD UNIQUE KEY topo_edges_src_dst_uq (src_id, dst_id);
	END IF;
END//

CALL topology_migrate_simplify_physical_link_uniqueness()//
DROP PROCEDURE topology_migrate_simplify_physical_link_uniqueness//

DELIMITER ;
