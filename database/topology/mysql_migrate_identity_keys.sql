-- Identity keys and segments (topology-1-model.md §6.7.0, §7.1). Safe to run twice.
-- 1. The key table (the same text as the end of mysql_schema.sql).
-- 2. topo_observations.remote_key is a key string now (up to 300 characters).
-- Existing Devices get their keys from their attributes in the next ingest run (summary: identity_keys_built).
CREATE TABLE IF NOT EXISTS topo_identity_keys (
	device_id BIGINT UNSIGNED NOT NULL,
	`key` VARCHAR(300) NOT NULL,
	kind VARCHAR(16) NOT NULL,
	first_seen INT UNSIGNED NOT NULL DEFAULT 0,
	replaced TINYINT NOT NULL DEFAULT 0,
	PRIMARY KEY (device_id, `key`),
	KEY topo_identity_keys_1 (`key`),
	CONSTRAINT topo_identity_keys_1 FOREIGN KEY (device_id) REFERENCES topo_nodes (id) ON DELETE CASCADE
) ENGINE=InnoDB;

ALTER TABLE topo_observations MODIFY remote_key VARCHAR(300) NOT NULL;
