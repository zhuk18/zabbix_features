// lldp.rem[{#LOCPORT},"{#REM.CHASSIS}","{#REM.PORTID}"] item prototype preprocessing, step 1 of
// 2 (T-model revised spec §1.1.1/§1.1.3). Selects the neighbor whose (local port, chassis id,
// port id) matches this item's own key parameters and returns it as JSON. Absent ->
// {"present":false}; never throws (an exception here would flood the item with "not supported"
// errors on every push after this neighbor leaves).

var TARGET_LOCPORT = '{#LOCPORT}';
var TARGET_CHASSIS = '{#REM.CHASSIS}';
var TARGET_PORTID = '{#REM.PORTID}';

function truncate255(s) {
	s = (s === undefined || s === null) ? '' : String(s);
	return s.length > 255 ? s.substring(0, 255) : s;
}

var data;
try {
	data = JSON.parse(value);
}
catch (e) {
	return JSON.stringify({present: false});
}

var portsByIndex = {};
var ports = data.ports || [];
for (var p = 0; p < ports.length; p++) {
	portsByIndex[ports[p].if_index] = ports[p];
}

var neighbors = data.neighbors || [];
for (var i = 0; i < neighbors.length; i++) {
	var n = neighbors[i];
	if (!n.remote_chassis_id && !n.remote_sysname) {
		continue;
	}
	var localPort = portsByIndex[n.local_if_index];
	var locPortName = truncate255(localPort ? localPort.name : String(n.local_if_index));
	if (locPortName === TARGET_LOCPORT
			&& truncate255(n.remote_chassis_id || '') === TARGET_CHASSIS
			&& truncate255(n.remote_port_id || '') === TARGET_PORTID) {
		return JSON.stringify({present: true, neighbor: n});
	}
}

return JSON.stringify({present: false});
