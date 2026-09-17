-- Port->Device is a stable 1:many relationship — it doesn't need the generic topo_edges table's
-- type-specific constraint machinery (§2.1's uniqueness caveats don't apply to a plain FK). Replaces
-- the 'part_of' edge type with topo_nodes.device_id (set only when type='port'), per the spec's §2.1/
-- §2.2/§2.3/§3 rule 4 update.
DELIMITER //

CREATE PROCEDURE topology_migrate_device_id()
BEGIN
	DECLARE has_device_id INT DEFAULT 0;
	DECLARE has_device_id_key INT DEFAULT 0;
	DECLARE has_device_id_fk INT DEFAULT 0;

	SELECT COUNT(*) INTO has_device_id
	FROM information_schema.columns
	WHERE table_schema = DATABASE() AND table_name = 'topo_nodes' AND column_name = 'device_id';

	IF has_device_id = 0 THEN
		ALTER TABLE topo_nodes ADD COLUMN device_id BIGINT UNSIGNED NULL AFTER proxy_ref;
	END IF;

	UPDATE topo_nodes port
		JOIN topo_edges part_of ON part_of.type = 'part_of' AND part_of.src_id = port.id
	SET port.device_id = part_of.dst_id
	WHERE port.type = 'port' AND port.device_id IS NULL;

	DELETE FROM topo_edges WHERE type = 'part_of';

	SELECT COUNT(*) INTO has_device_id_key
	FROM information_schema.statistics
	WHERE table_schema = DATABASE() AND table_name = 'topo_nodes' AND index_name = 'idx_topo_nodes_device_id';

	IF has_device_id_key = 0 THEN
		ALTER TABLE topo_nodes ADD KEY idx_topo_nodes_device_id (device_id);
	END IF;

	SELECT COUNT(*) INTO has_device_id_fk
	FROM information_schema.table_constraints
	WHERE constraint_schema = DATABASE() AND table_name = 'topo_nodes'
		AND constraint_name = 'topo_nodes_4' AND constraint_type = 'FOREIGN KEY';

	IF has_device_id_fk = 0 THEN
		ALTER TABLE topo_nodes ADD CONSTRAINT topo_nodes_4
			FOREIGN KEY (device_id) REFERENCES topo_nodes (id) ON DELETE CASCADE;
	END IF;
END//

CALL topology_migrate_device_id()//
DROP PROCEDURE topology_migrate_device_id//

DELIMITER ;
