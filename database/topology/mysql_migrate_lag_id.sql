-- LAG membership (topology model spec §2.1): topo_nodes.lag_id, set only when type='port' — the LAG
-- Port a physical member belongs to. Unlike device_id this is ON DELETE SET NULL, not CASCADE: member
-- ports are real interfaces that must survive their LAG going away. The invariant "lag_id IS NOT NULL
-- implies the target's attrs.if_type = 'lag'" is a cross-row rule enforced by ingest.php, not the schema.
DELIMITER //

CREATE PROCEDURE topology_migrate_lag_id()
BEGIN
	DECLARE has_lag_id INT DEFAULT 0;
	DECLARE has_lag_id_key INT DEFAULT 0;
	DECLARE has_lag_id_fk INT DEFAULT 0;

	SELECT COUNT(*) INTO has_lag_id
	FROM information_schema.columns
	WHERE table_schema = DATABASE() AND table_name = 'topo_nodes' AND column_name = 'lag_id';

	IF has_lag_id = 0 THEN
		ALTER TABLE topo_nodes ADD COLUMN lag_id BIGINT UNSIGNED NULL AFTER device_id;
	END IF;

	SELECT COUNT(*) INTO has_lag_id_key
	FROM information_schema.statistics
	WHERE table_schema = DATABASE() AND table_name = 'topo_nodes' AND index_name = 'idx_topo_nodes_lag_id';

	IF has_lag_id_key = 0 THEN
		ALTER TABLE topo_nodes ADD KEY idx_topo_nodes_lag_id (lag_id);
	END IF;

	SELECT COUNT(*) INTO has_lag_id_fk
	FROM information_schema.table_constraints
	WHERE constraint_schema = DATABASE() AND table_name = 'topo_nodes'
		AND constraint_name = 'topo_nodes_6' AND constraint_type = 'FOREIGN KEY';

	IF has_lag_id_fk = 0 THEN
		ALTER TABLE topo_nodes ADD CONSTRAINT topo_nodes_6
			FOREIGN KEY (lag_id) REFERENCES topo_nodes (id) ON DELETE SET NULL;
	END IF;
END//

CALL topology_migrate_lag_id()//
DROP PROCEDURE topology_migrate_lag_id//

DELIMITER ;
