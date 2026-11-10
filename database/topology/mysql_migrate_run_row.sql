-- topology-1-model.md §6.1 (2.65, task 087): the run row (the run lock, the request, the last run). Idempotent: running it again changes nothing.
CREATE TABLE IF NOT EXISTS topo_run (
	id TINYINT UNSIGNED NOT NULL,
	status VARCHAR(16) NOT NULL DEFAULT 'idle',
	owner VARCHAR(190) NOT NULL DEFAULT '',
	heartbeat INT UNSIGNED NOT NULL DEFAULT 0,
	requested_at INT UNSIGNED NOT NULL DEFAULT 0,
	requested_by VARCHAR(100) NOT NULL DEFAULT '',
	started_at INT UNSIGNED NOT NULL DEFAULT 0,
	finished_at INT UNSIGNED NOT NULL DEFAULT 0,
	started_by VARCHAR(100) NOT NULL DEFAULT '',
	run_id VARCHAR(32) NOT NULL DEFAULT '',
	error TEXT NULL,
	summary LONGTEXT NULL,
	PRIMARY KEY (id)
) ENGINE=InnoDB;
INSERT IGNORE INTO topo_run (id) VALUES (1);
-- a table made by an earlier version of this file has no `requested_by` (who pressed the button: the run that takes the request records it as `started_by`)
ALTER TABLE topo_run ADD COLUMN IF NOT EXISTS requested_by VARCHAR(100) NOT NULL DEFAULT '' AFTER requested_at;
