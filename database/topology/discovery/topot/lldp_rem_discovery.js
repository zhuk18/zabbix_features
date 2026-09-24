// lldp.rem.discovery LLD preprocessing (T-model revised spec §1.1.1/§1.1.3/§2/§3/§4).
//
// Item key uses the neighbor's OWN chassis id + port id as key parameters (quoted -- both can
// contain commas/spaces/brackets) instead of the transient lldpRemIndex the neighbor happened
// to get assigned this run, or a synthetic hash of them: lldpRemIndex changes whenever a
// neighbor's LLDP session restarts (even for the exact same physical link), which used to mint
// a brand-new item and orphan the old one's history every time that happened -- the whole
// reason for this key change (§1.1.1's "stable item keys").
//
// Raw neighbor facts travel as item tags (§1.1.3), not folded into one resolved "port" string
// at collection time -- interface/port matching and "is this really an interface name" both
// become read-time (reader) decisions instead, so the template stays a thin pass-through of
// what the agent actually said.

var CHASSIS_TYPE_MAP = {
	'macAddress': 'mac',
	'networkAddress': 'netaddr',
	'interfaceName': 'ifname',
	'local': 'local'
	// chassisComponent/interfaceAlias/portComponent have no slot in the 4-value
	// mac/netaddr/ifname/local set this spec defines -- left unmapped (empty chassis_type),
	// same as an absent chassis id subtype at all.
};

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
var neighbors = data.neighbors || [];
for (var i = 0; i < neighbors.length; i++) {
	var n = neighbors[i];

	// T-model spec / G-spec rule 1: a participant with neither chassis id nor sysname can't be
	// identified at all -- skip it.
	if (!n.remote_chassis_id && !n.remote_sysname) {
		continue;
	}

	var localPort = portsByIndex[n.local_if_index];
	var locPortName = localPort ? localPort.name : String(n.local_if_index);

	var chassisType = n.remote_chassis_id_subtype ? (CHASSIS_TYPE_MAP[n.remote_chassis_id_subtype] || '') : '';

	out.push({
		'{#LOCPORT}': truncate255(locPortName),
		'{#REM.CHASSIS}': truncate255(n.remote_chassis_id || ''),
		'{#REM.CHASSISTYPE}': chassisType,
		'{#REM.MGMTIP}': truncate255(n.remote_mgmt_ip || ''),
		'{#REM.NAME}': truncate255(n.remote_sysname || ''),
		'{#REM.PORTID}': truncate255(n.remote_port_id || ''),
		'{#REM.PORTIDSUBTYPE}': n.remote_port_id_subtype || '',
		'{#REM.PORTDESCR}': truncate255(n.remote_port_desc || '')
	});
}

return JSON.stringify(out);
