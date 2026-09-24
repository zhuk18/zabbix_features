// topo.nbr.discovery LLD preprocessing (T-model revision-2 spec §3.2).
//
// Turns $.neighbors[] of the pushed topology.discovery.raw blob into one LLD row per neighbor
// observation. The hash (NBRKEY) is computed ONLY here -- the item prototype (nbr_item.js /
// the JSONPath-filter step, §3.3) never needs it and the assembler never parses it either
// (§3.2's "Hash:" note). Item keys only ever get the integer LOCIFINDEX and this hex hash,
// never a device-sourced string (§8).
//
// Reused verbatim from the G-model (constraint 5): resolve_port_label()'s exact preference
// order (port_desc, then port_id for interfaceName/macAddress subtypes only, else port_id) --
// ported from database/topology/discovery/ingest.php's resolve_port_label(). No G-model
// equivalent exists for the long/short interface-name abbreviation table below; grepping the
// whole repo for it (GigabitEthernet/gigabitethernet) turns up nothing outside this T-model
// work, so there is nothing else to port -- this normalizePortName() is a new but shared (JS
// and PHP, see CTopologyTModel.php) T-model helper, not a divergence from an existing G-model
// implementation.
//
// Known platform limitation (confirmed live on this Zabbix 8.0.0 instance, matches the earlier
// BUG-HOSTMACRO finding): {HOST.HOST} and {HOST.ID} do NOT resolve inside LLD/item-prototype JS
// preprocessing string literals -- they come through as the literal macro text. That blocks the
// spec's literal §4.1 formula for a chassis-less neighbor's canonical id
// ("s:<reporter hostid>:<local_if_index>:<sysname>"), since this script has no way to learn its
// own host's id. Substituted: the reporter's own chassis_id (or, if that reporter itself has
// none, its sysname) from $.reporter, already present in this same blob without needing any
// macro. This preserves the exact scoping intent the spec states for this id (deterministic,
// scoped to one reporter+port, never a global sysname match) -- it does not add persistent
// state or a new identity tier, it only substitutes the reporter-scoping component of an id
// shape the spec already defines. Flagged in t-model-findings.md.
//
// CDP: push.py's blob can carry a separate cdp_neighbors[] array (task-g leftover), but §3.2
// only describes turning $.neighbors[] into rows. Not wired in here -- {#VIA} is always "lldp".
// Flagged as a known gap, not silently dropped.

var SHORT_TO_LONG_IFNAME = {
	'gi': 'gigabitethernet',
	'te': 'tengigabitethernet',
	'fa': 'fastethernet',
	'eth': 'ethernet'
};

function truncate255(s) {
	s = (s === undefined || s === null) ? '' : String(s);
	return s.length > 255 ? s.substring(0, 255) : s;
}

function normalizePortName(name) {
	var lower = String(name || '').replace(/^\s+|\s+$/g, '').toLowerCase();
	for (var short in SHORT_TO_LONG_IFNAME) {
		var long = SHORT_TO_LONG_IFNAME[short];
		if (lower.indexOf(long) === 0) {
			return lower;
		}
		if (lower.indexOf(short) === 0 && lower.length > short.length && /[0-9]/.test(lower.charAt(short.length))) {
			return long + lower.substring(short.length);
		}
	}
	return lower;
}

// Ported verbatim from ingest.php's resolve_port_label() (constraint 5).
function resolvePortLabel(port_desc, port_id, port_id_subtype) {
	if (port_desc) {
		return port_desc;
	}
	if ((port_id_subtype === 'interfaceName' || port_id_subtype === 'macAddress') && port_id) {
		return port_id;
	}
	return port_id || '';
}

function fnv1a32(str, seed) {
	var hash = seed;
	for (var i = 0; i < str.length; i++) {
		hash = hash ^ str.charCodeAt(i);
		var hi = (((hash >>> 16) & 0xffff) * 16777619) & 0xffff;
		var lo = ((hash & 0xffff) * 16777619) >>> 0;
		hash = (((hi << 16) >>> 0) + lo) >>> 0;
	}
	return hash >>> 0;
}

function hexHash16(str) {
	function pad(n) {
		var h = n.toString(16);
		while (h.length < 8) {
			h = '0' + h;
		}
		return h;
	}
	return pad(fnv1a32(str, 2166136261)) + pad(fnv1a32(str, 2654435761));
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

var reporter = data.reporter || {};
var reporterScope = reporter.chassis_id
	? ('c:' + String(reporter.chassis_id).toLowerCase())
	: ('n:' + truncate255(reporter.sysname || ''));

var out = [];
var neighbors = data.neighbors || [];
for (var i = 0; i < neighbors.length; i++) {
	var n = neighbors[i];

	// §3.2 / G-spec rule 1: a participant with neither chassis id nor sysname can't be
	// identified at all -- skip it (counted in stats by push.py already, not re-counted here).
	if (!n.remote_chassis_id && !n.remote_sysname) {
		continue;
	}

	var localPort = portsByIndex[n.local_if_index];
	var locPortName = localPort ? localPort.name : String(n.local_if_index);

	var peerId;
	if (n.remote_chassis_id) {
		peerId = 'c:' + String(n.remote_chassis_id).toLowerCase();
	}
	else {
		peerId = 's:' + reporterScope + ':' + n.local_if_index + ':' + truncate255(n.remote_sysname || '');
	}
	if (peerId.length > 255) {
		peerId = 'h:' + hexHash16(peerId);
	}

	var rawLabel = resolvePortLabel(n.remote_port_desc || '', n.remote_port_id || '', n.remote_port_id_subtype || '');
	var peerPort = rawLabel ? normalizePortName(rawLabel) : '';

	var nbrKey = hexHash16(n.local_if_index + '|' + peerId);

	out.push({
		'{#LOCIFINDEX}': n.local_if_index,
		'{#LOCPORT}': truncate255(locPortName),
		'{#PEERID}': peerId,
		'{#NBRKEY}': nbrKey,
		'{#PEERPORT}': truncate255(peerPort),
		'{#PEERNAME}': truncate255(n.remote_sysname || ''),
		'{#VIA}': 'lldp'
	});
}

return JSON.stringify(out);
