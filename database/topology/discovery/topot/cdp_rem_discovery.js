// cdp.rem.discovery LLD preprocessing (T-model revised spec §3.1). Reads $.cdp_neighbors[]
// (populated by push.py's cdpCacheTable walk -- a genuinely separate, Cisco-proprietary MIB
// from lldpRemTable, hence a separate LLD rule rather than folding into lldp.rem.discovery).
// CDP never carries a chassis id or its subtype (cdpCacheTable has no equivalent column) --
// topo.neighbor.chassis/chassis_type stay empty for every CDP-sourced item by design, not an
// oversight; identity for a CDP-only neighbor falls through to name/mgmt_ip matching only
// (§4.2's priority order already handles an empty chassis field the same way for any source).

function truncate255(s) {
	s = (s === undefined || s === null) ? '' : String(s);
	return s.length > 255 ? s.substring(0, 255) : s;
}

var data;
try {
	data = JSON.parse(value);
}
catch (e) {
	return JSON.stringify([]);
}

var portsByIndex = {};
var ports = data.ports || [];
for (var p = 0; p < ports.length; p++) {
	portsByIndex[ports[p].if_index] = ports[p];
}

var out = [];
var neighbors = data.cdp_neighbors || [];
for (var i = 0; i < neighbors.length; i++) {
	var n = neighbors[i];
	if (!n.remote_device_id) {
		continue; // nothing to key a Device on at all
	}
	var localPort = portsByIndex[n.local_if_index];
	var locPortName = localPort ? localPort.name : String(n.local_if_index);

	out.push({
		'{#IFINDEX}': String(n.local_if_index),
		'{#LOCPORT}': truncate255(locPortName),
		'{#REM.DEVICEID}': truncate255(n.remote_device_id || ''),
		'{#REM.PORTID}': truncate255(n.remote_port_id || '')
	});
}

return JSON.stringify(out);
