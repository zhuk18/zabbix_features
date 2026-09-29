/*
 * NEIGHBORS: one row per lldpRemTable entry.
 *
 * lldpRemTable index is TimeMark.LocalPortNum.RemIndex. LocalPortNum is an lldpLocPortNum, NOT always an ifIndex, so
 * it is resolved through lldpLocPortTable by the local port's id subtype: interfaceName -> match ifName, macAddress ->
 * match ifPhysAddress, local -> the value is the ifIndex. When the device does not expose lldpLocPortTable the
 * LocalPortNum is taken as the ifIndex (true for most switches). A port that cannot be resolved is emitted WITHOUT
 * {#IFINDEX}, so the server's contract check reports it instead of the row silently disappearing.
 */

requireWalk();

var ifs = interfaces(), chassis = localChassis(ifs), REM = '1.0.8802.1.1.2.1.4.1.1', LOC = '1.0.8802.1.1.2.1.3.7.1',
	remChassisType = table(REM + '.4'), remChassis = table(REM + '.5'), remPortType = table(REM + '.6'),
	remPort = table(REM + '.7'), remPortDesc = table(REM + '.8'), remSysname = table(REM + '.9'),
	locPortType = table(LOC + '.2'), locPortId = table(LOC + '.3'), manAddr = table('1.0.8802.1.1.2.1.4.2.1.3'),
	rows = [], suffixes = {}, byName = {}, byMac = {}, k, i, sfx, parts, m;

for (k in ifs) {
	if (ifs.hasOwnProperty(k)) {
		byName[ifs[k].name] = k;

		if (ifs[k].mac !== '') {
			byMac[ifs[k].mac] = k;
		}
	}
}

/* every column shares the same TimeMark.LocalPortNum.RemIndex suffixes */
[remChassis, remPort, remPortDesc, remSysname, remChassisType, remPortType].forEach(function (col) {
	for (var s in col) {
		if (col.hasOwnProperty(s)) {
			suffixes[s] = true;
		}
	}
});

function localIfIndex(portNum) {
	var type = locPortType[portNum] !== undefined ? parseInt(decode(locPortType[portNum]), 10) : 0,
		id = locPortId[portNum] !== undefined ? decode(locPortId[portNum]) : '';

	if (id !== '') {
		if (type === 5 && byName[id] !== undefined) {
			return byName[id];
		}

		if (type === 3 && byMac[id] !== undefined) {
			return byMac[id];
		}

		if (type === 7 && ifs[id] !== undefined) {
			return id;
		}
	}

	return ifs[portNum] !== undefined ? portNum : '';
}

/* chassis / port id by subtype: macAddress -> colon hex, networkAddress -> dotted IPv4, everything else as text */
function decodeId(row, subtype) {
	var text = decode(row), octets;

	if (subtype === 5 && row.type === 'Hex-STRING') {
		octets = trim(row.raw).split(/\s+/);

		if (octets.length === 5 && parseInt(octets[0], 16) === 1) {	/* address family 1 = IPv4 */
			return [parseInt(octets[1], 16), parseInt(octets[2], 16), parseInt(octets[3], 16),
				parseInt(octets[4], 16)].join('.');
		}
	}

	return text;
}

/* management IP of an entry: lldpRemManAddrTable index is <suffix>.<addrSubtype>.<len>.<address octets> */
function managementIp(suffix) {
	var key, rest, p;

	for (key in manAddr) {
		if (manAddr.hasOwnProperty(key) && key.indexOf(suffix + '.') === 0) {
			p = key.substring(suffix.length + 1).split('.');

			if (p[0] === '1' && p[1] === '4' && p.length === 6) {
				return p.slice(2).join('.');
			}
		}
	}

	return '';
}

var sorted = [];

for (sfx in suffixes) {
	if (suffixes.hasOwnProperty(sfx)) {
		sorted.push(sfx);
	}
}

sorted.sort();

for (i = 0; i < sorted.length; i++) {
	sfx = sorted[i];
	parts = sfx.split('.');

	if (parts.length < 3) {
		continue;	/* not a TimeMark.LocalPortNum.RemIndex index */
	}

	var row = {'{#IFINDEX}': localIfIndex(parts[1]), '{#SOURCE}': 'lldp'}, chassisType = remChassisType[sfx] !== undefined
		? parseInt(decode(remChassisType[sfx]), 10) : 0, portType = remPortType[sfx] !== undefined
		? parseInt(decode(remPortType[sfx]), 10) : 0, ip = managementIp(sfx);

	if (row['{#IFINDEX}'] === '') {
		delete row['{#IFINDEX}'];
	}
	else {
		row['{#IFNAME}'] = ifs[row['{#IFINDEX}']].name;
	}

	if (remChassis[sfx] !== undefined) {
		row['{#REM_CHASSIS}'] = decodeId(remChassis[sfx], chassisType);

		if (LLDP_CHASSIS_SUBTYPE[chassisType] !== undefined) {
			row['{#REM_CHASSIS_TYPE}'] = LLDP_CHASSIS_SUBTYPE[chassisType];
		}
	}

	if (ip !== '') {
		row['{#REM_MGMT_IP}'] = ip;
	}

	if (remSysname[sfx] !== undefined) {
		row['{#REM_SYSNAME}'] = decode(remSysname[sfx]);
	}

	if (remPort[sfx] !== undefined) {
		row['{#REM_PORT}'] = decodeId(remPort[sfx], portType);

		if (LLDP_PORT_SUBTYPE[portType] !== undefined) {
			row['{#REM_PORT_TYPE}'] = LLDP_PORT_SUBTYPE[portType];
		}
	}

	if (remPortDesc[sfx] !== undefined) {
		row['{#REM_PORT_DESC}'] = decode(remPortDesc[sfx]);
	}

	rows.push(withChassis(row, chassis));
}

return JSON.stringify(rows);
