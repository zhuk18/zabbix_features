-- Model 2.93 §3.2 (task 110): a Port has no `zabbix_itemids` (the view finds the items of a port when it reads the graph).
-- Removes the key from the Ports that were stored before. Safe to run again.
UPDATE topo_nodes
	SET attrs = JSON_REMOVE(attrs, '$.zabbix_itemids')
	WHERE type = 'port' AND JSON_CONTAINS_PATH(attrs, 'one', '$.zabbix_itemids');
