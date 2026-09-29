/* PORTS: one row per interface (IF-MIB ifTable + ifXTable). {#IFINDEX} is the real ifIndex. */

requireWalk();

var ifs = interfaces(), chassis = localChassis(ifs), IF = '1.3.6.1.2.1.2.2.1', types = table(IF + '.3'),
	admin = table(IF + '.7'), oper = table(IF + '.8'), speeds = table('1.3.6.1.2.1.31.1.1.1.15'), rows = [],
	keys = numericKeys(ifs), i, k, row;

for (i = 0; i < keys.length; i++) {
	k = String(keys[i]);
	row = {'{#IFINDEX}': k, '{#IFNAME}': ifs[k].name};

	if (types[k] !== undefined) {
		row['{#IFTYPE}'] = decode(types[k]);
	}

	if (ifs[k].mac !== '') {
		row['{#IFMAC}'] = ifs[k].mac;
	}

	if (admin[k] !== undefined) {
		row['{#IFADMINSTATUS}'] = decode(admin[k]);
	}

	if (oper[k] !== undefined) {
		row['{#IFOPERSTATUS}'] = decode(oper[k]);
	}

	if (speeds[k] !== undefined) {
		row['{#IFSPEED}'] = decode(speeds[k]);	/* ifHighSpeed, Mbit/s */
	}

	rows.push(withChassis(row, chassis));
}

return JSON.stringify(rows);
