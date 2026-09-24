# BUG-SEARCHHOSTS — searchHosts() output missing 'hostid', breaking the port-link/promote pickers

Discovered live via the actual browser UI (user report, not a planned campaign scenario),
during manual testing of B4 (manual link, host<->host variant). Written after the anomaly was
already understood from the screenshot (port-link picker on Switch1 showing "This host has no
ports reported" and a disabled Link button) but before re-confirming the exact root cause below.

Expected once suspected: the shared frontend (monitoring.topology.view.js.php, written against
CTopologyPrototype::searchHosts()'s {hostid, name, monitoring_state} shape) reads
target_host.hostid directly in both the promote-picker and the port-link-picker flows. If
CTopologyTModel::searchHosts() returns {id, name} instead (missing hostid), target_host.hostid
is undefined in both flows -- promote silently sends hostid=undefined, and the port-link
picker's follow-up ports.get call fetches id="h:undefined", which matches no host and returns
empty groups, exactly matching the screenshot.
