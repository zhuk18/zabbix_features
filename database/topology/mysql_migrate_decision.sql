-- topology-1-model.md §6.7.8 (2.106, task 129): the table of the operator's decisions. Idempotent: running it again changes nothing. Apply to a `zabbix` database that has the topology tables:
--   mysql -uzabbix zabbix < database/topology/mysql_migrate_decision.sql
-- topo_decision: the identity decisions of the operator as records by natural keys (topology-1-model.md §6.7.8, 2.106, task 129; FR-0 decisions 12, 13, 18). Ingest reads them in every run;
-- only operator actions write them. A record never names a nodeid: key_a and key_b are natural keys of a Device (§6.7.8: its strongest identity key, else `port:<reporter hostid>:<port name key>:<name key>`).
--   merge          key_a = the kept Device, key_b = the removed one, keys_b = every key of the removed Device at the time of the merge (JSON list)
--   not_same       key_a, key_b = the two Devices that are not the same device
--   accept_chassis key_a = the old chassis key of the reporter's Device, key_b = its new chassis key
--   associate      key_a = the Device, hostid or proxyid = the host or proxy that represents it (represented_by_matched_by = manual)
-- norm_version = the version of the normalization rules when the record was written (FR-0 decision 13.3). The rows of hostid and proxyid go with the host or proxy.
CREATE TABLE IF NOT EXISTS topo_decision (
	decisionid BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
	kind VARCHAR(16) NOT NULL,
	key_a VARCHAR(255) NOT NULL,
	key_b VARCHAR(255) NULL,
	hostid BIGINT UNSIGNED NULL,
	proxyid BIGINT UNSIGNED NULL,
	keys_b TEXT NULL,
	created_at INT UNSIGNED NOT NULL,
	created_by BIGINT UNSIGNED NULL,
	norm_version INT NOT NULL,
	PRIMARY KEY (decisionid),
	KEY topo_decision_1 (kind),
	KEY topo_decision_2 (key_a),
	KEY topo_decision_3 (hostid),
	KEY topo_decision_4 (proxyid),
	CONSTRAINT topo_decision_1 FOREIGN KEY (hostid) REFERENCES hosts (hostid) ON DELETE CASCADE,
	CONSTRAINT topo_decision_2 FOREIGN KEY (proxyid) REFERENCES proxy (proxyid) ON DELETE CASCADE
) ENGINE=InnoDB;
