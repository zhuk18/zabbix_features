DELIMITER //

CREATE PROCEDURE topology_migrate_host_ref()
BEGIN
	DECLARE has_host_ref INT DEFAULT 0;
	DECLARE has_host_ref_key INT DEFAULT 0;
	DECLARE has_host_ref_fk INT DEFAULT 0;

	SELECT COUNT(*) INTO has_host_ref
	FROM information_schema.columns
	WHERE table_schema = DATABASE() AND table_name = 'topo_nodes' AND column_name = 'host_ref';

	IF has_host_ref = 0 THEN
		ALTER TABLE topo_nodes ADD COLUMN host_ref BIGINT UNSIGNED NULL AFTER type;
	END IF;

	UPDATE topo_nodes
	SET host_ref = CAST(JSON_UNQUOTE(JSON_EXTRACT(attrs, '$.zabbix_host_id')) AS UNSIGNED),
		attrs = JSON_OBJECT(),
		updated_at = UNIX_TIMESTAMP()
	WHERE type = 'host'
		AND host_ref IS NULL
		AND JSON_EXTRACT(attrs, '$.zabbix_host_id') IS NOT NULL;

	DELETE FROM topo_nodes WHERE type = 'host' AND host_ref IS NULL;

	UPDATE topo_nodes
	SET attrs = JSON_SET(attrs, '$.zabbix_itemids', JSON_ARRAY()), updated_at = UNIX_TIMESTAMP()
	WHERE type = 'interface' AND JSON_EXTRACT(attrs, '$.zabbix_itemids') IS NULL;

	SELECT COUNT(*) INTO has_host_ref_key
	FROM information_schema.statistics
	WHERE table_schema = DATABASE() AND table_name = 'topo_nodes' AND index_name = 'topo_nodes_2';

	IF has_host_ref_key = 0 THEN
		ALTER TABLE topo_nodes ADD KEY topo_nodes_2 (host_ref);
	END IF;

	SELECT COUNT(*) INTO has_host_ref_fk
	FROM information_schema.table_constraints
	WHERE constraint_schema = DATABASE() AND table_name = 'topo_nodes'
		AND constraint_name = 'topo_nodes_1' AND constraint_type = 'FOREIGN KEY';

	IF has_host_ref_fk = 0 THEN
		ALTER TABLE topo_nodes ADD CONSTRAINT topo_nodes_1
			FOREIGN KEY (host_ref) REFERENCES hosts (hostid) ON DELETE CASCADE;
	END IF;
END//

CALL topology_migrate_host_ref()//
DROP PROCEDURE topology_migrate_host_ref//

DELIMITER ;