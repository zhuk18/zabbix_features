-- §5/§6: Host/Proxy topo_nodes rows are now created lazily (only on a MAC match, reporter_self, or
-- manual /promote), not eagerly for every Zabbix host/proxy on pull. Any environment that already ran
-- the old eager-pull logic has Host/Proxy topo_nodes rows with no active representation — orphans
-- under the new policy (no Device's represented_by_node_id points at them). This is a one-time
-- cleanup, not a schema change: represented_by_node_id/_matched_by/_at already exist on topo_nodes
-- (see mysql_migrate_represented_by.sql). Run this once, after the code changes are deployed — not
-- before, so nothing gets immediately re-created by a pull still running the old eager-upsert logic.
--
-- Row counts are reported via SELECT so the cleanup's effect is visible in the migration's own
-- output, not just implied.
SELECT COUNT(*) AS host_proxy_nodes_before FROM topo_nodes WHERE type IN ('host', 'proxy');

-- The subquery is wrapped in an extra derived-table SELECT (MySQL disallows selecting from the same
-- table a DELETE targets, even in a subquery — the derived table works around that by materializing
-- the represented ids first).
DELETE FROM topo_nodes
WHERE type IN ('host', 'proxy')
	AND id NOT IN (
		SELECT represented_by_node_id FROM (
			SELECT represented_by_node_id FROM topo_nodes WHERE represented_by_node_id IS NOT NULL
		) AS represented_ids
	);

SELECT COUNT(*) AS host_proxy_nodes_after FROM topo_nodes WHERE type IN ('host', 'proxy');
