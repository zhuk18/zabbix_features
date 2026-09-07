DELIMITER //

CREATE PROCEDURE topology_migrate_proxy_ref()
BEGIN
	DECLARE has_proxy_ref INT DEFAULT 0;
	DECLARE has_proxy_ref_key INT DEFAULT 0;
	DECLARE has_proxy_ref_fk INT DEFAULT 0;

	SELECT COUNT(*) INTO has_proxy_ref
	FROM information_schema.columns
	WHERE table_schema = DATABASE() AND table_name = 'topo_nodes' AND column_name = 'proxy_ref';

	IF has_proxy_ref = 0 THEN
		ALTER TABLE topo_nodes ADD COLUMN proxy_ref BIGINT UNSIGNED NULL AFTER host_ref;
	END IF;

	SELECT COUNT(*) INTO has_proxy_ref_key
	FROM information_schema.statistics
	WHERE table_schema = DATABASE() AND table_name = 'topo_nodes' AND index_name = 'topo_nodes_3';

	IF has_proxy_ref_key = 0 THEN
		ALTER TABLE topo_nodes ADD KEY topo_nodes_3 (proxy_ref);
	END IF;

	SELECT COUNT(*) INTO has_proxy_ref_fk
	FROM information_schema.table_constraints
	WHERE constraint_schema = DATABASE() AND table_name = 'topo_nodes'
		AND constraint_name = 'topo_nodes_3' AND constraint_type = 'FOREIGN KEY';

	IF has_proxy_ref_fk = 0 THEN
		ALTER TABLE topo_nodes ADD CONSTRAINT topo_nodes_3
			FOREIGN KEY (proxy_ref) REFERENCES proxy (proxyid) ON DELETE CASCADE;
	END IF;
END//

CALL topology_migrate_proxy_ref()//
DROP PROCEDURE topology_migrate_proxy_ref//

DELIMITER ;
