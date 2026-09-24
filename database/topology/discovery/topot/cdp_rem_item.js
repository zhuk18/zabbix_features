// cdp.rem[{#IFINDEX},"{#REM.DEVICEID}","{#REM.PORTID}"] item prototype preprocessing, step 1 of
// 2 (T-model revised spec §3.1). Selects the CDP neighbor matching this item's own key
// parameters. Absent -> {"present":false}; never throws.

var TARGET_IFINDEX = '{#IFINDEX}';
var TARGET_DEVICEID = '{#REM.DEVICEID}';
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

var neighbors = data.cdp_neighbors || [];
for (var i = 0; i < neighbors.length; i++) {
	var n = neighbors[i];
	if (!n.remote_device_id) {
		continue;
	}
	if (String(n.local_if_index) === TARGET_IFINDEX
			&& truncate255(n.remote_device_id || '') === TARGET_DEVICEID
			&& truncate255(n.remote_port_id || '') === TARGET_PORTID) {
		return JSON.stringify({present: true, neighbor: n});
	}
}

return JSON.stringify({present: false});
