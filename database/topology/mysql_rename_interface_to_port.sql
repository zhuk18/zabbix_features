-- 'Interface' collided with Zabbix's own host.interfaces (agent/SNMP/JMX/IPMI monitoring
-- endpoints) — a different concept entirely. Renamed the topology node type to 'port'.
-- No column rename needed: topo_nodes.type is a free-text discriminator.
UPDATE topo_nodes SET type = 'port' WHERE type = 'interface';
