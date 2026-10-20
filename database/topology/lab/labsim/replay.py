"""Replay devices (topology-4-lab-simulator.md §4.1a, §4.4, §5.3, §5.4): a device whose SNMP data is a snmprec file (a real capture or a hand-written file).
The generator never changes the data except for the deviations `omit_mib` and `override_oid`."""
import os
import re

from .model import LabError

SNMPREC_LINE = re.compile(r'^([0-9][0-9.]*)\|([^|]*)\|(.*)$')
LAB_TAIL = (1, 3, 6, 1, 4, 1, 99999, 1, 0)
SYS_NAME = (1, 3, 6, 1, 2, 1, 1, 5, 0)
ALLOWED_KEYS = {'ip', 'replay', 'zabbix'}
FORBIDDEN_KEYS = ('chassis', 'ports', 'lldp', 'cdp', 'fdb', 'port_mac_prefix', 'lldp_local_port_subtype', 'lldp_loc_port_num')
REPLAY_DEVIATIONS = ('omit_mib', 'override_oid')

# the subtrees that `omit_mib` removes (§4.4); each root is a labmultiplex subtree when a step changes it (§5.3)
MIB_ROOTS = {
    'lldp': [(1, 0, 8802, 1, 1, 2)],
    'cdp': [(1, 3, 6, 1, 4, 1, 9, 9, 23)],
    'fdb': [(1, 3, 6, 1, 2, 1, 17, 4), (1, 3, 6, 1, 2, 1, 17, 7, 1, 2)],
    'lag': [(1, 2, 840, 10006, 300, 43)],
}
LLDP_LOC_PORT_ID = (1, 0, 8802, 1, 1, 2, 1, 3, 7, 1, 3)       # lldpLocPortId.<lldpLocPortNum>
LLDP_REM_ENTRY = (1, 0, 8802, 1, 1, 2, 1, 4, 1, 1)             # lldpRemEntry.<column>.<timeMark>.<lldpRemLocalPortNum>.<lldpRemIndex>
IF_NAME = (1, 3, 6, 1, 2, 1, 31, 1, 1, 1, 1)                   # ifName.<ifIndex>
LLDP_REM_COLUMNS = {'lldpRemChassisIdSubtype': 4, 'lldpRemChassisId': 5, 'lldpRemPortIdSubtype': 6, 'lldpRemPortId': 7, 'lldpRemPortDesc': 8,
                    'lldpRemSysName': 9, 'lldpRemSysDesc': 10}


def parse_oid(s):
    return tuple(int(x) for x in s.strip('.').split('.'))


def oid_str(t):
    return '.'.join(map(str, t))


def decode(tag, value):
    """The bytes of an octet string record (`4` text, `4x` hex), else None."""
    if tag == '4':
        return value.encode('utf-8', errors='surrogateescape')
    if tag == '4x':
        try:
            return bytes.fromhex(value)
        except ValueError:
            return None
    return None


def encode(b):
    if all(32 <= c < 127 for c in b) and b == b.strip():
        return '4', b.decode()
    return '4x', b.hex()


def read_records(path, name):
    """The records of a replay file as {oid: (tag, value)} in file order. §5.4: the file exists and parses, the OIDs are sorted numerically and unique, the
    last record is the lab end record, `sysName.0` is the device name. Nothing is changed."""
    try:
        with open(path, 'rb') as f:
            raw = f.read()
    except OSError as e:
        raise LabError('replay file of %s: %s: %s' % (name, path, e.strerror))
    records, last = {}, None
    for n, line in enumerate(raw.decode('utf-8', errors='surrogateescape').splitlines(), 1):
        if line.strip() == '':
            continue
        m = SNMPREC_LINE.match(line)
        if not m:
            raise LabError('replay file of %s line %d does not parse: %r' % (name, n, line[:60]))
        oid = parse_oid(m.group(1))
        if last is not None and oid <= last:
            raise LabError('replay file of %s line %d: OIDs are not sorted or not unique (%s after %s)' % (name, n, oid_str(oid), oid_str(last)))
        records[oid] = (m.group(2), m.group(3))
        last = oid
    if not records:
        raise LabError('replay file of %s has no records' % name)
    if last != LAB_TAIL:
        raise LabError('replay file of %s does not end with the record %s (lab spec §5.2)' % (name, oid_str(LAB_TAIL)))
    tag, value = records.get(SYS_NAME, (None, None))
    got = decode(tag, value) if tag else None
    if got is None or got.decode('utf-8', errors='replace') != name:
        raise LabError('replay file of %s: sysName.0 is %r, the device name must be equal to it' % (name, None if got is None else got.decode('utf-8', errors='replace')))
    return records


def check_device(name, d):
    """§5.4: a replay device has only the keys ip, replay and zabbix."""
    bad = [k for k in FORBIDDEN_KEYS if k in d]
    if bad:
        raise LabError('replay device %s: key(s) %s not allowed (a replay device has only ip, replay, zabbix)' % (name, ', '.join(bad)))
    extra = set(d) - ALLOWED_KEYS
    if extra:
        raise LabError('replay device %s: key(s) %s not allowed (a replay device has only ip, replay, zabbix)' % (name, ', '.join(sorted(extra))))
    if not d.get('replay'):
        raise LabError('replay device %s: replay needs a file path' % name)


def _under(oid, roots):
    return any(oid[:len(r)] == r for r in roots)


def omit(records, mib):
    roots = MIB_ROOTS[mib]
    return {o: v for o, v in records.items() if not _under(o, roots)}


def _loc_port_nums(records, row):
    """lldpLocPortNum values whose lldpLocPortId is `row`, else those whose ifName (same number as ifIndex) is `row`."""
    want = row.encode()
    nums = {o[len(LLDP_LOC_PORT_ID)] for o, (t, v) in records.items() if len(o) == len(LLDP_LOC_PORT_ID) + 1 and o[:len(LLDP_LOC_PORT_ID)] == LLDP_LOC_PORT_ID
            and decode(t, v) == want}
    return nums


def _if_name_nums(records, row):
    want = row.encode()
    return {o[len(IF_NAME)] for o, (t, v) in records.items() if len(o) == len(IF_NAME) + 1 and o[:len(IF_NAME)] == IF_NAME and decode(t, v) == want}


def override(records, dev, d):
    """`override_oid` on a replay device (§4.4). Without `row`, every record of the OID (or of the column) takes the value. With `row` the OID names an lldpRem* column and `row`
    is the local port of the entry (lldpLocPortId, else ifName of the same lldpLocPortNum); exactly one entry must match, `rem_index` (lldpRemIndex) picks one of several."""
    oid_name = d['oid']
    column = LLDP_REM_COLUMNS.get(oid_name)
    if 'value_hex' in d:
        tag, value = '4x', str(d['value_hex'])
    else:
        tag, value = encode(str(d['value']).encode())
    if 'row' in d:
        if column is None:
            raise LabError('deviation %s: `row` needs an lldpRem* column name, got %r' % (d['id'], oid_name))
        entries = {}
        for source in (_loc_port_nums, _if_name_nums):
            nums = source(records, str(d['row']))
            entries = {o: v for o, v in records.items() if o[:len(LLDP_REM_ENTRY)] == LLDP_REM_ENTRY and len(o) == len(LLDP_REM_ENTRY) + 4
                       and o[len(LLDP_REM_ENTRY)] == column and o[len(LLDP_REM_ENTRY) + 2] in nums
                       and ('rem_index' not in d or o[len(LLDP_REM_ENTRY) + 3] == int(d['rem_index']))}
            if entries:
                break
        if not entries:
            raise LabError('deviation %s: device %s has no lldpRem entry on the local port %r' % (d['id'], dev, d['row']))
        if len(entries) > 1:
            raise LabError('deviation %s: device %s has %d lldpRem entries on the local port %r (add rem_index)' % (d['id'], dev, len(entries), d['row']))
        hit = list(entries)
    else:
        prefix = (LLDP_REM_ENTRY + (column,)) if column else (parse_oid(oid_name) if oid_name[:1].isdigit() else None)
        if prefix is None:
            raise LabError('deviation %s: unknown OID name %r' % (d['id'], oid_name))
        hit = [o for o in records if o[:len(prefix)] == prefix and (len(o) > len(prefix) or not column)]
        if not hit:
            raise LabError('deviation %s: no OID of device %s matches %s' % (d['id'], dev, oid_name))
    out = dict(records)
    for o in hit:
        out[o] = (tag, value)
    return out


def records_at(lab, name):
    """The records of a replay device in one lab state: the file with the active deviations of the device applied (omit_mib first)."""
    dev = lab.devices[name]
    records = read_records(dev['replay_path'], name)
    active = [d for d in lab.active_deviations() if d.get('device') == name]
    for d in active:
        if d['type'] == 'omit_mib':
            if d.get('mib') not in MIB_ROOTS:
                raise LabError('deviation %s: omit_mib on a replay device takes mib %s, got %r' % (d['id'], '/'.join(MIB_ROOTS), d.get('mib')))
            records = omit(records, d['mib'])
    for d in active:
        if d['type'] == 'override_oid':
            records = override(records, name, d)
    return records


def check_deviation(d, devices):
    """§5.4: a deviation of a type other than omit_mib or override_oid names a replay device; an omit_mib names a known MIB."""
    dev = devices.get(d.get('device'))
    if dev is not None and dev.get('replay') and d['type'] not in REPLAY_DEVIATIONS:
        raise LabError('deviation %s: type %s can not name replay device %s (only %s)' % (d['id'], d['type'], d['device'], ', '.join(REPLAY_DEVIATIONS)))
    for ref_key in ('port', 'writes'):
        ref = d.get(ref_key)
        if ref and ':' in ref and devices.get(ref.split(':', 1)[0], {}).get('replay'):
            raise LabError('deviation %s: type %s uses a port of replay device %s' % (d['id'], d['type'], ref.split(':', 1)[0]))
    for key in ('link', 'cable', 'neighbors'):
        for ref in d.get(key) or []:
            if ':' in ref and devices.get(ref.split(':', 1)[0], {}).get('replay'):
                raise LabError('deviation %s: type %s uses a port of replay device %s' % (d['id'], d['type'], ref.split(':', 1)[0]))


def subtree_roots(prev_records, cur_records_list):
    """The MIB roots that differ between the steps of a replay device: {key: root} for every root with a different record set in any step."""
    changing = {}
    for mib, roots in MIB_ROOTS.items():
        for i, root in enumerate(roots):
            key = mib if len(roots) == 1 else '%s%d' % (mib, i)
            base = {o: v for o, v in prev_records.items() if o[:len(root)] == root}
            if any({o: v for o, v in r.items() if o[:len(root)] == root} != base for r in cur_records_list):
                changing[key] = root
    return changing


def check_applies(records, dev, d):
    """§5.4 at load time: the deviation applies to the data of the device (an override_oid row gives exactly one entry); errors as at generation."""
    if d['type'] == 'omit_mib':
        if d.get('mib') not in MIB_ROOTS:
            raise LabError('deviation %s: omit_mib on a replay device takes mib %s, got %r' % (d['id'], '/'.join(MIB_ROOTS), d.get('mib')))
    elif d['type'] == 'override_oid':
        override(records, dev, d)
