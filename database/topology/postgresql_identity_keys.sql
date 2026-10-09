-- Identity keys and segments (topology-1-model.md §6.7.0, §7.1), PostgreSQL. The topology tables of the prototype exist as DDL for MySQL only
-- (mysql_schema.sql); this is the same table for PostgreSQL. NOT RUN: no PostgreSQL server was available when it was written.
-- Run it after the topology tables exist. `replaced` is 0/1 (a SMALLINT, as in MySQL) so one query text works on both engines.
CREATE TABLE IF NOT EXISTS topo_identity_keys (
	device_id bigint NOT NULL,
	"key" varchar(300) NOT NULL,
	kind varchar(16) NOT NULL,
	first_seen integer NOT NULL DEFAULT 0,
	replaced smallint NOT NULL DEFAULT 0,
	PRIMARY KEY (device_id, "key"),
	CONSTRAINT topo_identity_keys_1 FOREIGN KEY (device_id) REFERENCES topo_nodes (id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS topo_identity_keys_1 ON topo_identity_keys ("key");

ALTER TABLE topo_observations ALTER COLUMN remote_key TYPE varchar(300);
