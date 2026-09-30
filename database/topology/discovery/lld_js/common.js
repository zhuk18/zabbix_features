/*
 * Shared prelude of the four topology rules (PORTS, NEIGHBORS, LEARNED_MACS, LAG). build_template.php pastes this
 * file in front of each rule's own script, so every rule parses the walk exactly the same way. ES5 only: the Zabbix
 * JavaScript engine (Duktape) has no let/const, arrow functions or template strings.
 *
 * Input: `value` is the text of an SNMP walk[] item, one variable per line: ".1.3.6.1... = TYPE: value".
 *
 * Contract with the server (topology_role): a rule returns an array of LLD rows. It returns [] when the source table
 * is simply empty (the device has no neighbors / no MACs) and THROWS when the walk itself is unusable — an empty
 * result is a valid snapshot, a thrown error leaves the last good snapshot untouched.
 */

var LLDP_CHASSIS_SUBTYPE = {1: 'chassisComponent', 2: 'interfaceAlias', 3: 'portComponent', 4: 'macAddress',
	5: 'networkAddress', 6: 'interfaceName', 7: 'local'};
var LLDP_PORT_SUBTYPE = {1: 'interfaceAlias', 2: 'portComponent', 3: 'macAddress', 4: 'networkAddress',
	5: 'interfaceName', 6: 'agentCircuitId', 7: 'local'};

function trim(s) {
	return String(s).replace(/^\s+|\s+$/g, '');
}

/* Parses the walk into {rows: {oid: {type, raw}}, count}. Lines that are not a variable (wrapped continuations,
 * "No Such Object" markers) are ignored. OIDs are stored without the leading dot. */
function parseWalk(text) {
	var rows = {}, count = 0, lines = String(text).split(/\r?\n/), i, m;

	for (i = 0; i < lines.length; i++) {
		m = /^\s*\.?([0-9][0-9.]*)\s*=\s*(?:([A-Za-z][\w-]*):\s*)?(.*)$/.exec(lines[i]);

		if (m === null || /^No Such (Object|Instance)/.test(m[3]) || /No more variables/.test(lines[i])) {
			continue;
		}

		rows[m[1]] = {type: m[2] || 'STRING', raw: m[3]};
		count++;
	}

	return {rows: rows, count: count};
}

var WALK = parseWalk(value);

/* A walk with no variables at all means the walk itself is unusable: throw, so the last good snapshot stays. The
 * LEARNED_MACS rule does not call this — its master item turns "the device has no bridge tables" into an empty walk
 * on purpose, and that is a valid (empty) snapshot. */
function requireWalk() {
	if (WALK.count === 0) {
		throw 'topology: the SNMP walk holds no variables (device unreachable or wrong walk[] OIDs)';
	}
}

/* "aa bb cc" -> "aa:bb:cc" (lowercase), the same form push.py produces for Hex-STRING values. */
function hexToColon(raw) {
	var octets = trim(raw).split(/\s+/), i;

	for (i = 0; i < octets.length; i++) {
		octets[i] = octets[i].toLowerCase();
	}

	return octets.join(':');
}

/* Decoded text of a walk row: Hex-STRING -> colon hex, quotes stripped, "name(3)" enums -> "3". */
function decode(row) {
	var raw = trim(row.raw), m;

	if (row.type === 'Hex-STRING') {
		return hexToColon(raw);
	}

	if (raw.length > 1 && raw.charAt(0) === '"' && raw.charAt(raw.length - 1) === '"') {
		raw = raw.substring(1, raw.length - 1);
	}
	else if (row.type === 'INTEGER' || row.type === 'Gauge32' || row.type === 'Counter32' || row.type === 'Unsigned32') {
		m = /\((-?[0-9]+)\)\s*$/.exec(raw);

		if (m !== null) {
			raw = m[1];
		}
	}

	return raw;
}

/* A MAC written as text -> "aa:bb:cc:00:00:0a": six octets of one or two hex digits joined by ':' or '-' (net-snmp
 * prints "0:c:29:..", some agents "AA-BB-.."), or the Cisco form "aabb.cc00.000a". Anything else is returned as it
 * came. The same MAC arrives as a Hex-STRING from another device, so both must end in one spelling (Hex-STRING is
 * already in it, see decode()). */
function normMac(text) {
	var m = /^([0-9a-fA-F]{1,2})([:-])([0-9a-fA-F]{1,2})\2([0-9a-fA-F]{1,2})\2([0-9a-fA-F]{1,2})\2([0-9a-fA-F]{1,2})\2([0-9a-fA-F]{1,2})$/.exec(text), i,
		octets = [], d;

	if (m !== null) {
		for (i = 1; i <= 7; i++) {
			if (i !== 2) {
				octets.push(m[i]);
			}
		}
	}
	else if ((d = /^([0-9a-fA-F]{4})\.([0-9a-fA-F]{4})\.([0-9a-fA-F]{4})$/.exec(text)) !== null) {
		for (i = 1; i <= 3; i++) {
			octets.push(d[i].substring(0, 2), d[i].substring(2));
		}
	}
	else {
		return text;
	}

	for (i = 0; i < octets.length; i++) {
		octets[i] = (octets[i].length < 2 ? '0' : '') + octets[i].toLowerCase();
	}

	return octets.join(':');
}

/* {suffix: row} for every variable under `base` (base without a trailing dot). */
function table(base) {
	var out = {}, prefix = base + '.', oid;

	for (oid in WALK.rows) {
		if (WALK.rows.hasOwnProperty(oid) && oid.indexOf(prefix) === 0) {
			out[oid.substring(prefix.length)] = WALK.rows[oid];
		}
	}

	return out;
}

function numericKeys(obj) {
	var keys = [], k;

	for (k in obj) {
		if (obj.hasOwnProperty(k) && /^[0-9]+$/.test(k)) {
			keys.push(parseInt(k, 10));
		}
	}

	keys.sort(function (a, b) {
		return a - b;
	});

	return keys;
}

/* dotted decimal octets ("170.187.204") -> "aa:bb:cc" */
function decimalOctetsToMac(parts) {
	var out = [], i, h;

	for (i = 0; i < parts.length; i++) {
		h = parseInt(parts[i], 10).toString(16);
		out.push(h.length < 2 ? '0' + h : h);
	}

	return out.join(':');
}

/* Interfaces: {ifIndex: {name, mac}} from ifName (preferred), ifDescr and ifPhysAddress. */
function interfaces() {
	var IF = '1.3.6.1.2.1.2.2.1', ifIndexes = table(IF + '.1'), descr = table(IF + '.2'), phys = table(IF + '.6'),
		names = table('1.3.6.1.2.1.31.1.1.1.1'), result = {}, k, name;

	for (k in ifIndexes) {
		if (ifIndexes.hasOwnProperty(k)) {
			name = names[k] !== undefined ? decode(names[k]) : '';

			if (name === '' && descr[k] !== undefined) {
				name = decode(descr[k]);
			}

			result[k] = {
				name: name !== '' ? name : 'if' + k,
				mac: phys[k] !== undefined ? normMac(decode(phys[k])) : ''
			};
		}
	}

	return result;
}

/* The device's own chassis id: lldpLocChassisId, else the MAC of its lowest-ifIndex port that has one. The fallback
 * is push.py's own convention; every rule of a host must derive the same value, so it lives here. */
function localChassis(ifs) {
	var row = WALK.rows['1.0.8802.1.1.2.1.3.2.0'], value = row !== undefined ? normMac(decode(row)) : '', keys, i;

	if (value !== '') {
		return value;
	}

	keys = numericKeys(ifs);

	for (i = 0; i < keys.length; i++) {
		if (ifs[keys[i]].mac !== '') {
			return ifs[keys[i]].mac;
		}
	}

	return '';
}

/* Adds {#LOC_CHASSIS} to a row when the device's own chassis id is known. */
function withChassis(row, chassis) {
	if (chassis !== '') {
		row['{#LOC_CHASSIS}'] = chassis;
	}

	return row;
}
