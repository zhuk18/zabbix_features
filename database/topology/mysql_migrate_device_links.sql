-- Device-level links (topology-device-level-edge-spec.md): topo_edges gets a second edge type, `device_link`
-- (src = Port, dst = Device) -- topo_edges.type is a free VARCHAR(32), so nothing changes there -- and
-- topo_observations records how precisely the far end was identified. Idempotent.
DELIMITER //

CREATE PROCEDURE topology_migrate_device_links()
BEGIN
	IF (SELECT COUNT(*) FROM information_schema.columns
			WHERE table_schema = DATABASE() AND table_name = 'topo_observations' AND column_name = 'link_precision') = 0 THEN
		ALTER TABLE topo_observations
			ADD COLUMN link_precision VARCHAR(8) NULL AFTER device_id,
			ADD COLUMN far_port_reason VARCHAR(16) NULL AFTER link_precision,
			ADD COLUMN precision_lower TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER far_port_reason;
	END IF;
END//

CALL topology_migrate_device_links()//
DROP PROCEDURE topology_migrate_device_links//

DELIMITER ;
