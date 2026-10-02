"""Generator: lab state -> snmpsim data (spec §5)."""
import hashlib
import json
import os
import re
import shutil

from .model import LabError, split_port

# subtree name -> root OID. A subtree that changes in any step of the scenario goes through labmultiplex.
SUBTREES = {
    'lldp': (1, 0, 8802, 1, 1, 2),
    'lag': (1, 2, 840, 10006, 300, 43),
    'mib2': (1, 3, 6, 1, 2, 1),
    'cdp': (1, 3, 6, 1, 4, 1, 9, 9, 23),
}
CONTROL_SUFFIX = (99999, 0)

# OID roots of the two master items of "Topology by SNMP" (template_topology_by_snmp.yaml; the template is the source).
CORE_ROOTS = [(1, 3, 6, 1, 2, 1, 1, 5), (1, 3, 6, 1, 2, 1, 2, 2, 1), (1, 3, 6, 1, 2, 1, 31, 1, 1, 1, 1),
              (1, 3, 6, 1, 2, 1, 31, 1, 1, 1, 15), (1, 0, 8802, 1, 1, 2, 1, 3, 2), (1, 0, 8802, 1, 1, 2, 1, 3, 7),
              (1, 0, 8802, 1, 1, 2, 1, 4, 1), (1, 0, 8802, 1, 1, 2, 1, 4, 2), (1, 2, 840, 10006, 300, 43, 1, 2, 1, 1, 13),
              (1, 3, 6, 1, 4, 1, 9, 9, 23, 1, 2, 1, 1), (1, 3, 6, 1, 4, 1, 9, 9, 23, 1, 3, 1)]
FDB_ROOTS = [(1, 3, 6, 1, 2, 1, 17, 1, 4, 1, 2), (1, 3, 6, 1, 2, 1, 17, 7, 1, 2, 2, 1), (1, 3, 6, 1, 2, 1, 17, 4, 3, 1)]

LLDP_REM = (1, 0, 8802, 1, 1, 2, 1, 4, 1, 1)
SYMBOLIC = {   # oid names accepted by override_oid
    'lldpRemChassisIdSubtype': LLDP_REM + (4,), 'lldpRemChassisId': LLDP_REM + (5,),
    'lldpRemPortIdSubtype': LLDP_REM + (6,), 'lldpRemPortId': LLDP_REM + (7,),
    'lldpRemPortDesc': LLDP_REM + (8,), 'lldpRemSysName': LLDP_REM + (9,),
    'lldpLocChassisId': (1, 0, 8802, 1, 1, 2, 1, 3, 2), 'sysName': (1, 3, 6, 1, 2, 1, 1, 5),
    'ifName': (1, 3, 6, 1, 2, 1, 31, 1, 1, 1, 1), 'ifDescr': (1, 3, 6, 1, 2, 1, 2, 2, 1, 2),
}


def oid_str(t):
    return '.'.join(map(str, t))


def parse_oid(s):
    return tuple(int(x) for x in s.strip('.').split('.'))


def mac_bytes(mac):
    return bytes(int(x, 16) for x in mac.split(':'))


def mac_text(b):
    return ':'.join('%02x' % x for x in b)


def enc_str(s):
    b = s.encode() if isinstance(s, str) else s
    if all(32 <= c < 127 for c in b) and b == b.strip():
        return '4', b.decode()
    return '4x', b.hex()


def long_name(name):
    m = re.match(r'^Gi(\d.*)$', name)
    return 'GigabitEthernet' + m.group(1) if m else name


def default_port_mac(dev, p):
    prefix = dev.get('port_mac_prefix')
    if prefix:
        return '%s:00:%02x' % (prefix, p['ifindex'])
    h = hashlib.sha1(('%s/%s' % (dev['sysname'], p['name'])).encode()).digest()
    return '02:' + ':'.join('%02x' % x for x in h[:5])


def port_mac(dev, p):
    return (p.get('mac') or default_port_mac(dev, p)).lower()


class Records(dict):
    """oid tuple -> (tag, value)."""

    def put(self, oid, tag, value):
        self[tuple(oid)] = (tag, str(value))

    def put_str(self, oid, s):
        tag, v = enc_str(s)
        self.put(oid, tag, v)

    def put_hex(self, oid, b):
        self.put(oid, '4x', b.hex())


def _loc_port_num(dev, p):
    mode = dev.get('lldp_loc_port_num', 'ifindex')
    if mode == 'ifindex':
        return p['ifindex']
    if mode == 'sequential':
        return [q['ifindex'] for q in sorted(dev['ports'], key=lambda q: q['ifindex'])].index(p['ifindex']) + 1
    m = re.match(r'^offset:(-?\d+)$', str(mode))
    if m:
        return p['ifindex'] + int(m.group(1))
    raise LabError('device %s: bad lldp_loc_port_num %r' % (dev['sysname'], mode))


SUBTYPE_CODES = {'interfaceName': 5, 'macAddress': 3, 'local': 7}


def _port_id(dev, p):
    sub = dev.get('lldp_local_port_subtype') or 'interfaceName'
    if sub == 'interfaceName':
        return 5, p['name']
    if sub == 'macAddress':
        return 3, bytes.fromhex(port_mac(dev, p).replace(':', ''))
    return 7, str(p['ifindex'])


def device_records(lab, name):
    """All SNMP records of one monitored device in one lab state."""
    dev = lab.devices[name]
    s = lab.settings
    omit = {d['mib'] for d in lab.active_deviations('omit_mib') if d['device'] == name}
    renum = {}
    for d in lab.active_deviations('ifindex_renumber'):
        if d['device'] == name:
            for i, p in enumerate(sorted(dev['ports'], key=lambda q: q['ifindex'])):
                renum[p['name']] = d['map'][p['name']] if 'map' in d and p['name'] in d['map'] \
                    else p['ifindex'] + int(d.get('offset', 0))
    ports = []
    for p in dev['ports']:
        q = dict(p)
        q['ifindex'] = renum.get(p['name'], p['ifindex'])
        ports.append(q)
    by_name = {p['name']: p for p in ports}
    dev = dict(dev, ports=ports)
    r = Records()

    # system group: sysName is walked by the template; sysDescr/sysUpTime serve the fault files
    r.put_str((1, 3, 6, 1, 2, 1, 1, 1, 0), 'lab device %s' % name)
    r.put((1, 3, 6, 1, 2, 1, 1, 3, 0), '67', 0)
    r.put_str((1, 3, 6, 1, 2, 1, 1, 5, 0), dev['sysname'])

    # IF-MIB
    base = (1, 3, 6, 1, 2, 1, 2, 2, 1)
    xbase = (1, 3, 6, 1, 2, 1, 31, 1, 1, 1)
    for p in ports:
        i = p['ifindex']
        r.put(base + (1, i), '2', i)
        r.put_str(base + (2, i), p['name'])
        r.put(base + (3, i), '2', 161 if p.get('type') == 'lag' else 6)
        r.put(base + (4, i), '2', 1500)
        r.put(base + (5, i), '66', p.get('speed', 1000000000))
        r.put_hex(base + (6, i), bytes.fromhex(port_mac(dev, p).replace(':', '')))
        r.put(base + (7, i), '2', 2 if p.get('admin') == 'down' else 1)
        r.put(base + (8, i), '2', 2 if p.get('oper') == 'down' else 1)
        r.put(base + (9, i), '67', 0)
        r.put_str(xbase + (1, i), p['name'])

    # LAG
    if 'lag' not in omit:
        for p in ports:
            if p.get('type') == 'lag':
                for m in p.get('members') or []:
                    r.put((1, 2, 840, 10006, 300, 43, 1, 2, 1, 1, 13, by_name[m]['ifindex']), '2', p['ifindex'])

    # neighbors per local port: (far device name, far port name)
    neighbors = {}
    for a, b in lab.cables:
        for near, far in ((a, b), (b, a)):
            nd, np_ = split_port(near)
            fd, fp = split_port(far)
            neighbors.setdefault((nd, np_), []).append((fd, fp))
    for d in lab.active_deviations('one_sided_cable'):
        a, b = d['cable']
        writer = d['writes']
        silent = b if writer == a else a
        sd, sp = split_port(silent)
        neighbors[(sd, sp)] = [n for n in neighbors.get((sd, sp), []) if '%s:%s' % n != writer]
    for d in lab.active_deviations('extra_lldp_neighbor'):
        nd, np_ = split_port(d['port'])
        for far in d['neighbors']:
            fd, fp = split_port(far)
            if (fd, fp) not in neighbors.setdefault((nd, np_), []):
                neighbors[(nd, np_)].append((fd, fp))

    # LLDP
    if dev['lldp'] and 'lldp' not in omit:
        with_sub = bool(s.get('emit_subtypes')) or bool(dev.get('lldp_local_port_subtype'))
        loc_table = bool(dev.get('lldp_local_port_subtype')) or dev.get('lldp_loc_port_num', 'ifindex') != 'ifindex' \
            or bool(s.get('emit_loc_port_table'))
        lb = (1, 0, 8802, 1, 1, 2, 1, 3)
        r.put_hex(lb + (2, 0), mac_bytes(dev['chassis']['value']))
        if s.get('emit_subtypes'):
            r.put(lb + (1, 0), '2', 4)
        if loc_table:
            for p in ports:
                n = _loc_port_num(dev, p)
                sub, pid = _port_id(dev, p)
                r.put(lb + (7, 1, 2, n), '2', sub)
                (r.put_hex if isinstance(pid, bytes) else r.put_str)(lb + (7, 1, 3, n), pid)
                r.put_str(lb + (7, 1, 4, n), long_name(p['name']))
        for p in ports:
            n = _loc_port_num(dev, p)
            for k, (fd, fp) in enumerate(neighbors.get((name, p['name']), []), 1):
                if fd not in lab.devices:
                    raise LabError('cable to unknown device %s' % fd)
                far = lab.devices[fd]
                fport = next((q for q in far['ports'] if q['name'] == fp), None)
                if fport is None:
                    raise LabError('unknown port %s:%s' % (fd, fp))
                ix = (0, n, k)
                if with_sub or far.get('lldp_local_port_subtype') or s.get('emit_subtypes'):
                    r.put(LLDP_REM + (4,) + ix, '2', 4)
                    fsub, fpid = _port_id(far, fport)
                    r.put(LLDP_REM + (6,) + ix, '2', fsub)
                else:
                    fpid = fport['name']
                r.put_hex(LLDP_REM + (5,) + ix, mac_bytes(far['chassis']['value']))
                (r.put_hex if isinstance(fpid, bytes) else r.put_str)(LLDP_REM + (7,) + ix, fpid)
                r.put_str(LLDP_REM + (8,) + ix, fport.get('desc') or long_name(fport['name']))
                r.put_str(LLDP_REM + (9,) + ix, far['sysname'])
                if s.get('emit_mgmt_addr') and far.get('ip'):
                    ip = bytes(int(x) for x in far['ip'].split('.'))
                    r.put((1, 0, 8802, 1, 1, 2, 1, 4, 2, 1, 3) + ix + (1, 4) + tuple(ip), '2', 2)

    # CDP
    if dev['cdp'] and 'cdp' not in omit:
        r.put((1, 3, 6, 1, 4, 1, 9, 9, 23, 1, 3, 1, 0), '2', 1)
        cb = (1, 3, 6, 1, 4, 1, 9, 9, 23, 1, 2, 1, 1)
        for p in ports:
            for k, (fd, fp) in enumerate(neighbors.get((name, p['name']), []), 1):
                far = lab.devices[fd]
                if not far['cdp']:
                    continue
                ix = (p['ifindex'], k)
                r.put_str(cb + (6,) + ix, far['sysname'])
                r.put_str(cb + (7,) + ix, fp)
                if far.get('ip'):
                    r.put(cb + (3,) + ix, '2', 1)
                    r.put_hex(cb + (4,) + ix, bytes(int(x) for x in far['ip'].split('.')))

    # FDB
    if dev['fdb'] != 'none' and 'fdb' not in omit:
        macs = {}      # ifindex -> [mac bytes]
        for p in ports:
            for fd, fp in neighbors.get((name, p['name']), []):
                far = lab.devices[fd]
                fport = next(q for q in far['ports'] if q['name'] == fp)
                macs.setdefault(p['ifindex'], []).append(bytes.fromhex(port_mac(far, fport).replace(':', '')))
        # synthetic MACs behind a port: lab.yaml fdb_extra, kept in settings as {Dev:Port: {count, vlan}}
        for ref, e in (s.get('fdb_extra') or {}).items():
            ed, ep = split_port(ref)
            if ed == name and ep in by_name:
                for k in range(int(e['count'])):
                    macs.setdefault(by_name[ep]['ifindex'], []).append(bytes([2, 0xfe, 0, by_name[ep]['ifindex'] & 255, k >> 8, k & 255]))
        for p in ports:
            r.put((1, 3, 6, 1, 2, 1, 17, 1, 4, 1, 2, p['ifindex']), '2', p['ifindex'])
        for i, ml in macs.items():
            for m in ml:
                if dev['fdb'] == 'qbridge':
                    r.put((1, 3, 6, 1, 2, 1, 17, 7, 1, 2, 2, 1, 2, 1) + tuple(m), '2', i)
                    r.put((1, 3, 6, 1, 2, 1, 17, 7, 1, 2, 2, 1, 3, 1) + tuple(m), '2', 3)
                else:
                    r.put_hex((1, 3, 6, 1, 2, 1, 17, 4, 3, 1, 1) + tuple(m), m)
                    r.put((1, 3, 6, 1, 2, 1, 17, 4, 3, 1, 2) + tuple(m), '2', i)
                    r.put((1, 3, 6, 1, 2, 1, 17, 4, 3, 1, 3) + tuple(m), '2', 3)

    # override_oid deviations (last: they replace values written above)
    for d in lab.active_deviations('override_oid'):
        if d['device'] != name:
            continue
        key = SYMBOLIC.get(d['oid'])
        prefix = key if key else parse_oid(d['oid'])
        hit = [o for o in r if o[:len(prefix)] == prefix and (len(prefix) < len(o) or not key)]
        if 'row' in d:      # restrict to the row of a local port (lldpRem* rows only)
            n = _loc_port_num(dev, by_name[d['row']])
            hit = [o for o in hit if len(o) > len(prefix) + 1 and o[len(prefix) + 1] == n]
        if not hit:
            raise LabError('deviation %s: no OID matches %s' % (d['id'], d['oid']))
        for o in hit:
            if 'value_hex' in d:
                r.put(o, '4x', d['value_hex'])
            else:
                tag, v = enc_str(str(d['value']))
                r.put(o, tag, v)
    return r


def split_subtrees(records):
    out = {k: {} for k in SUBTREES}
    for oid, v in records.items():
        for k, root in SUBTREES.items():
            if oid[:len(root)] == root:
                out[k][oid] = v
                break
        else:
            raise LabError('record %s is outside every subtree' % oid_str(oid))
    return out


def lines(recs):
    return ''.join('%s|%s|%s\n' % (oid_str(o), t, v) for o, (t, v) in sorted(recs.items()))


def master_of(oid):
    for r in CORE_ROOTS:
        if oid[:len(r)] == r:
            return 'core'
    for r in FDB_ROOTS:
        if oid[:len(r)] == r:
            return 'fdb'
    return None


def changed_masters(prev, cur):
    """Which master items (core / fdb) see different data between two record sets."""
    out = set()
    for oid in set(prev) | set(cur):
        if prev.get(oid) != cur.get(oid):
            m = master_of(oid)
            if m:
                out.add(m)
    return out


def generate(scenario, out_dir, variation_dir=None):
    """Write data/<device>/... for a scenario. Returns a manifest dict (also written to manifest.json)."""
    if os.path.isdir(out_dir):
        shutil.rmtree(out_dir)
    os.makedirs(out_dir)
    manifest = {'scenario': scenario.id, 'devices': {}, 'steps': len(scenario.steps)}
    monitored = sorted(set().union(*[set(l.monitored()) for l in scenario.states]))
    for name in monitored:
        per_step = []
        for lab in scenario.states:
            if name in lab.monitored():
                per_step.append(split_subtrees(device_records(lab, name)))
            else:
                per_step.append(None)
        if any(p is None for p in per_step):
            raise LabError('device %s is monitored in some steps only; a scenario must not toggle it' % name)
        dev = scenario.states[0].devices[name]
        ddir = os.path.join(out_dir, name)
        os.makedirs(ddir)
        main = {}
        mux = {}
        changing = {k for k in SUBTREES if any(per_step[0][k] != p[k] for p in per_step[1:])}
        for k, root in SUBTREES.items():
            if k in changing:
                ctl = root + CONTROL_SUFFIX
                kdir = os.path.join(ddir, k)
                os.makedirs(kdir)
                for n, p in enumerate(per_step):
                    with open(os.path.join(kdir, '%05d.snmprec' % n), 'w') as f:
                        f.write(lines(p[k]))
                mux[k] = {'root': oid_str(root), 'control': oid_str(ctl), 'dir': kdir}
            else:
                main.update(per_step[0][k])
        text = ''.join(l for l in [lines(main)])
        recs = [(o, '%s|%s|%s\n' % (oid_str(o), t, v)) for o, (t, v) in main.items()]
        for k, m in mux.items():
            recs.append((parse_oid(m['root']), '%s|:labmultiplex|dir=%s,control=%s\n' % (m['root'], m['dir'], m['control'])))
        recs.sort(key=lambda x: x[0])
        with open(os.path.join(ddir, '%s.snmprec' % dev['community']), 'w') as f:
            f.write(''.join(l for _, l in recs))
        # per-step master changes, for the runner's "check now"
        masters = [sorted(set().union(*[set()])) for _ in per_step]
        flat = [{o: v for k in SUBTREES for o, v in p[k].items()} for p in per_step]
        step_masters = [[]] + [sorted(changed_masters(flat[i - 1], flat[i])) for i in range(1, len(flat))]
        manifest['devices'][name] = {'ip': dev['ip'], 'community': dev['community'], 'dir': ddir,
                                     'mux': mux, 'step_masters': step_masters,
                                     'step_hashes': [hashlib.sha1(json.dumps(sorted((oid_str(o), v) for o, v in f.items())).encode()).hexdigest() for f in flat]}
    with open(os.path.join(out_dir, 'manifest.json'), 'w') as f:
        json.dump(manifest, f, indent=1)
    return manifest


def hosts_json(scenario):
    """Provisioning input: one entry per monitored device (spec §3)."""
    lab = scenario.states[0]
    out = []
    for name, d in sorted(lab.monitored().items()):
        out.append({'host': name, 'ip': d['ip'], 'port': lab.settings.get('snmp_port', 161), 'community': d['community'],
                    'transport': d['zabbix']['transport'], 'proxy': d['zabbix']['proxy']})
    return out


def export_walk(lab, name):
    """Walk text of a device (net-snmp style, for fixtures: Part 3 §6.1 format), sorted by OID."""
    out = []
    for oid, (tag, v) in sorted(device_records(lab, name).items()):
        if tag == '4x':
            out.append('.%s = Hex-STRING: %s' % (oid_str(oid), ' '.join(v[i:i + 2].upper() for i in range(0, len(v), 2))))
        elif tag == '4':
            out.append('.%s = STRING: "%s"' % (oid_str(oid), v))
        elif tag == '2':
            out.append('.%s = INTEGER: %s' % (oid_str(oid), v))
        elif tag == '66':
            out.append('.%s = Gauge32: %s' % (oid_str(oid), v))
        elif tag == '67':
            out.append('.%s = Timeticks: (%s)' % (oid_str(oid), v))
        else:
            out.append('.%s = %s: %s' % (oid_str(oid), tag, v))
    return '\n'.join(out) + '\n'
