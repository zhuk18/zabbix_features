-- topology-1-model.md §12.7 (2.67, task 088): the hashes of the snapshots that the last runs read. Idempotent: running it again changes nothing.
CREATE TABLE IF NOT EXISTS topo_run_input (
	itemid BIGINT UNSIGNED NOT NULL,
	rows_hash VARCHAR(64) NOT NULL DEFAULT '',
	PRIMARY KEY (itemid),
	CONSTRAINT topo_run_input_1 FOREIGN KEY (itemid) REFERENCES items (itemid) ON DELETE CASCADE
) ENGINE=InnoDB;
