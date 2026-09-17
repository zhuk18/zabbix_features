-- Host<->Proxy/ProxyGroup monitoring assignment (§2.3, §6) is no longer a stored edge — it's
-- resolved live from host.get at read time (CTopologyPrototype::getMonitoringAssignments()),
-- same thin-pointer principle already applied to Host/Proxy nodes themselves. Storing it as our
-- own edge meant it went stale the moment someone reassigned a host to a different proxy in
-- Zabbix, until the next pull. There is no DB-level enum/CHECK constraint on topo_edges.type to
-- update (it's a plain VARCHAR(32) — see mysql_schema.sql) — this migration only needs to remove
-- the now-superseded rows.
DELETE FROM topo_edges WHERE type = 'monitored_by';
