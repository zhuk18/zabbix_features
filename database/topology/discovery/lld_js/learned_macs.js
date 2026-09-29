/*
 * LEARNED_MACS: MACs learned per port. Q-BRIDGE (dot1qTpFdbTable) when the device has it, else BRIDGE
 * (dot1dTpFdbTable). Only entries with status "learned" (3) on a real port count. A bridge port maps to an ifIndex
 * through dot1dBasePortIfIndex. A port with more than 20 MACs (a trunk / uplink) is reported by count only.
 *
 * Per-VLAN FDB on Cisco (community@vlan / v3 context) is out of scope.
 */

var COUNT_ONLY_ABOVE = 20, basePorts = table('1.3.6.1.2.1.17.1.4.1.2'), perIf = {}, q = table('1.3.6.1.2.1.17.7.1.2.2.1.2'),
	useQ = false, k, s, bridge, statusTable, portTable, fdb, parts, mac, vlan, port, ifIndex, rows = [], keys, i, j;

for (s in q) {
	if (q.hasOwnProperty(s)) {
		useQ = true;
		break;
	}
}

portTable = useQ ? q : table('1.3.6.1.2.1.17.4.3.1.2');
statusTable = useQ ? table('1.3.6.1.2.1.17.7.1.2.2.1.3') : table('1.3.6.1.2.1.17.4.3.1.3');

for (k in portTable) {
	if (!portTable.hasOwnProperty(k) || statusTable[k] === undefined || decode(statusTable[k]) !== '3') {
		continue;
	}

	parts = k.split('.');
	/* Q-BRIDGE index: <fdbId>.<6 mac octets>; BRIDGE index: <6 mac octets> */
	vlan = useQ ? parts.shift() : '';

	if (parts.length !== 6) {
		continue;
	}

	port = decode(portTable[k]);
	ifIndex = basePorts[port] !== undefined ? decode(basePorts[port]) : '';

	if (port === '0' || ifIndex === '') {
		continue;	/* not learned on a port we can name */
	}

	mac = decimalOctetsToMac(parts);
	perIf[ifIndex] = perIf[ifIndex] || [];
	perIf[ifIndex].push({mac: mac, vlan: vlan});
}

keys = numericKeys(perIf);

for (i = 0; i < keys.length; i++) {
	fdb = perIf[keys[i]];

	if (fdb.length > COUNT_ONLY_ABOVE) {
		rows.push({'{#IFINDEX}': String(keys[i]), '{#PORT_MAC_COUNT}': String(fdb.length)});
		continue;
	}

	fdb.sort(function (a, b) {
		return a.mac < b.mac ? -1 : (a.mac > b.mac ? 1 : 0);
	});

	for (j = 0; j < fdb.length; j++) {
		bridge = {'{#IFINDEX}': String(keys[i]), '{#MAC}': fdb[j].mac};

		if (fdb[j].vlan !== '') {
			bridge['{#VLAN}'] = fdb[j].vlan;
		}

		rows.push(bridge);
	}
}

return JSON.stringify(rows);
