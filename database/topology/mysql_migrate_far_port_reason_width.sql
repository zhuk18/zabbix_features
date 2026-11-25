-- topology-1-model.md §4.3, §8.5 (2.104, task 125): the far_port_reason `lldp_cdp_ports_differ` has 21 characters; the column of topo_observations is 32 wide (was 16). Idempotent: running it again
-- changes nothing.
ALTER TABLE topo_observations MODIFY far_port_reason VARCHAR(32) NULL;
