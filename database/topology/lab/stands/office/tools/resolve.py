#!/usr/bin/env python3
"""Simplified one-run ingest of topology rows (model 2.27, normalization 0.7).

A first full run on an empty model: no stored links, no manual links,
no history. Implements: reporter identity §6.4 (steps 2-5), local port
§6.7.6, far Device §6.7.1, far port §6.7.2, pseudo ports, link rules §8.1
and §8.5 (several neighbors on one port). Not implemented: §8.2-§8.4
(need history), joins, operator merges.

Output: JSON with devices, links, observations, counters.
"""
import sys, os, re, json, glob, ipaddress
from collections import defaultdict

FORMS = []
def load_forms(fn):
    for line in open(fn):
        if line.startswith('#') or not line.strip(): continue
        a, b = line.rstrip('\n').split('\t'); FORMS.append((a, b))
    FORMS.sort(key=lambda x: -len(x[0]))

# ---- normalization §4 ----
def clean(s):
    if s is None: return None
    s = s.rstrip('\x00').strip()
    for a, b in (('"', '"'), ("'", "'"), ('“', '”')):
        if len(s) >= 2 and s[0] == a and s[-1] == b:
            s = s[1:-1].strip(); break
    return s or None

def namekey(s):
    s = clean(s)
    if s is None: return None
    s = ''.join(c.lower() if 'A' <= c <= 'Z' else c for c in s)
    s = re.sub(r'^([a-z-]+)\s+(?=\d)', r'\1', s)
    for a, b in FORMS:
        if s.startswith(a) and len(s) > len(a) and s[len(a)].isdigit():
            s = b + s[len(a):]; break
    return s

def norm_mac(s):
    if s is None: return None
    s = s.strip().lower()
    m = re.fullmatch(r'([0-9a-f]{2})[:-]([0-9a-f]{2})[:-]([0-9a-f]{2})[:-]([0-9a-f]{2})[:-]([0-9a-f]{2})[:-]([0-9a-f]{2})', s)
    if m: return ':'.join(m.groups())
    m = re.fullmatch(r'([0-9a-f]{4})\.([0-9a-f]{4})\.([0-9a-f]{4})', s)
    if m:
        h = ''.join(m.groups()); return ':'.join(h[i:i+2] for i in range(0, 12, 2))
    return None

PLACEHOLDER = {'00:00:00:00:00:00', 'ff:ff:ff:ff:ff:ff'}

def numeric(s):
    s = clean(s)
    if s and re.fullmatch(r'\d{1,10}', s):
        v = int(s)
        if 0 < v <= 2147483647: return v
    return None

def mgmt_addr(man):
    if not man: return None
    v4, v6 = [], []
    for e in man.split():
        if e.startswith('lldp:'):
            p = [int(x) for x in e[5:].split('.')]
            fam, rest = p[0], p[1:]
            if fam == 1:
                if len(rest) == 5 and rest[0] == 4: rest = rest[1:]
                if len(rest) == 4: v4.append('.'.join(map(str, rest)))
            elif fam == 2:
                if len(rest) == 17 and rest[0] == 16: rest = rest[1:]
                if len(rest) == 16: v6.append(str(ipaddress.IPv6Address(bytes(rest))))
        elif e.startswith('cdp:'):
            _, t, h = e.split(':', 2)
            b = [int(x, 16) for x in h.split(':')] if h else []
            if t == '1' and len(b) == 4: v4.append('.'.join(map(str, b)))
    return (v4 or v6 or [None])[0]

SEG = 'server'

class Model:
    def __init__(self):
        self.dev = {}            # id -> dict
        self.kidx = defaultdict(set)
        self.next = 1
        self.obs = []
        self.cnt = defaultdict(int)
        self.notes = []

    def new_dev(self, name, reporter=None):
        i = self.next; self.next += 1
        self.dev[i] = {'id': i, 'name': name, 'reporter': reporter, 'keys': {}, 'ports': {}, 'pseudo': {}, 'attrs': {}}
        return i

    def add_key(self, d, kind, key):
        self.dev[d]['keys'][key] = kind; self.kidx[key].add(d)

    def has_kind(self, d, kind):
        return kind in self.dev[d]['keys'].values()

# ---- pass A ----
def reporters(M, rows):
    claims = defaultdict(list)
    for r in rows:
        lc = r['loc_chassis']; keys = []
        if lc.get('{#LOC_CHASSIS_TYPE}') == 'macAddress':
            mac = norm_mac(lc['{#LOC_CHASSIS}'])
            if mac and mac not in PLACEHOLDER: keys.append(('chassis', 'chassis:mac:' + mac))
            elif not mac: M.notes.append(f"{r['sysName']}: loc_chassis '{lc['{#LOC_CHASSIS}']}' is not a MAC (invalid)")
        if r['snmp_ip']: keys.append(('ip', f"ip:{SEG}:{r['snmp_ip']}"))
        r['_keys'] = keys
        for k in keys: claims[k[1]].append(r['sysName'])
    for k, v in claims.items():
        if len(v) > 1: M.notes.append(f'reporters_share_key {k}: {v}')
    for r in rows:
        d = M.new_dev(r['sysName'], reporter=r['sysName'])
        for kind, k in r['_keys']: M.add_key(d, kind, k)
        M.dev[d]['attrs'] = {'mgmt_ip': r['snmp_ip'], 'sysDescr': r['sysDescr'], 'sysObjectID': r['sysObjectID'],
                             'chassis': r['loc_chassis'].get('{#LOC_CHASSIS}')}
        for p in r['ports']:
            ix = int(p['{#IFINDEX}'])
            t = p.get('{#IFTYPE}')
            M.dev[d]['ports'][ix] = {'if_index': ix, 'name': clean(p.get('{#IFNAME}')) or clean(p.get('{#IFDESCR}')),
                                    'descr': clean(p.get('{#IFDESCR}')), 'mac': norm_mac(p.get('{#IFMAC}')),
                                    'type': t, 'cls': 'lag' if t == '161' else ('virtual' if t in ('24', '53', '131', '135', '136', '150', '166') else 'physical'),
                                    'oper': p.get('{#IFOPERSTATUS}'), 'speed': p.get('{#IFSPEED}'), 'alias': p.get('_ifalias'),
                                    'lag_id': None, 'macs': []}
        for l in r['lag']:
            m, a = int(l['{#IFINDEX}']), int(l['{#LAG_IFINDEX}'])
            if m in M.dev[d]['ports']: M.dev[d]['ports'][m]['lag_id'] = a
        for f in r['learned_macs_full']:
            ix = int(f['{#IFINDEX}'])
            if ix in M.dev[d]['ports']: M.dev[d]['ports'][ix]['macs'].append(f['{#MAC}'])
        r['_dev'] = d

def by(ports, field, val, key=False):
    if val is None: return []
    if key:
        k = namekey(val)
        return [p for p in ports.values() if p.get(field) and namekey(p[field]) == k]
    return [p for p in ports.values() if p.get(field) == val]

def mac_identity(ports, mac):
    mac = norm_mac(mac)
    if not mac or mac in PLACEHOLDER: return []
    hit = [p for p in ports.values() if p['mac'] == mac]
    return hit if len(hit) == 1 else []

# ---- §6.7.6 local port ----
def local_port(M, d, row):
    ports = M.dev[d]['ports']
    if '{#IFINDEX}' in row:
        ix = int(row['{#IFINDEX}']); return (ports.get(ix), 'ifindex')
    t, pid, pd, num = row.get('{#LOC_PORT_ID_TYPE}'), row.get('{#LOC_PORT_ID}'), row.get('{#LOC_PORT_DESC}'), row.get('{#LOC_PORT_NUM}')
    if t == 'interfaceName':
        h = by(ports, 'name', clean(pid))
        if len(h) == 1: return (h[0], 'step1:interfaceName')
    elif t == 'macAddress':
        h = mac_identity(ports, pid)
        if h: return (h[0], 'step1:mac')
    elif t == 'local':
        n = numeric(pid)
        if n and n in ports: return (ports[n], 'step1:local-numeric')
    several = False
    for keyed in (False, True):
        for val, field in ((pid, 'name'), (pid, 'descr'), (pd, 'name'), (pd, 'descr')):
            v = clean(val)
            if v is None: continue
            h = by(ports, field, v, key=keyed)
            if len(h) == 1: return (h[0], f'step2:{field}{"/key" if keyed else ""}')
            if len(h) > 1: several = True
    if several: return (None, 'name_on_several_ports')
    n = numeric(num)
    if n and n in ports: return (ports[n], 'step3:num-as-ifindex')
    return (None, 'unresolved')

# ---- §6.7.1 far device ----
def row_keys(row, reporter, lp):
    keys = []
    ct, cv = row.get('{#REM_CHASSIS_TYPE}'), row.get('{#REM_CHASSIS}')
    chassis_disp = None
    if cv is not None:
        if ct == 'macAddress':
            mac = norm_mac(cv)
            if mac is None: return None, 'identity_invalid'
            if mac not in PLACEHOLDER: keys.append(('chassis', 'chassis:mac:' + mac)); chassis_disp = mac
        else:
            c = clean(cv)
            if c: keys.append(('chassis', f'chassis:{ct}:{SEG}:{c}')); chassis_disp = c
    ip = mgmt_addr(row.get('{#REM_MAN_ADDRS}'))
    if ip: keys.append(('ip', f'ip:{SEG}:{ip}'))
    sn = clean(row.get('{#REM_SYSNAME}'))
    if sn: keys.append(('sysname', f'sysname:{reporter}:{lp}:{sn}'))
    return keys, {'chassis': chassis_disp, 'ip': ip, 'sysname': sn}

def far_device(M, keys, disp, src='lldp', port_devs=None):
    kinds = ['chassis', 'ip', 'sysname']
    found = None; step = None
    # model 2.29 §6.7.1: same-port join for a row whose only key is the scoped sysname
    if [kd for kd, _ in keys] == ['sysname'] and port_devs:
        sn = disp['sysname']
        c = {d for d in port_devs if sn in (M.dev[d]['attrs'].get('chassis'), M.dev[d]['attrs'].get('sysname'))}
        if len(c) > 1: return None, 'ambiguous', keys[0][1]
        if len(c) == 1:
            found = next(iter(c)); step = 'same-port join'
            M.add_key(found, 'sysname', keys[0][1])
    for i, (kind, k) in enumerate(keys if found is None else []):
        stronger = [kk for kk, _ in keys[:i]]
        cands = M.kidx.get(k, set())
        if i > 0:
            cands = {d for d in cands if not any(M.has_kind(d, s) for s in [kd for kd, _ in keys[:i]])}
        if len(cands) > 1: return None, 'ambiguous', k
        if len(cands) == 1:
            found = next(iter(cands)); step = f'by {kind}'
            if i > 0 and not M.dev[found]['reporter']:
                for kd, kk in keys[:i]: M.add_key(found, kd, kk)
            break
    if found is None:
        name = disp['sysname'] or disp['chassis'] or disp['ip']
        found = M.new_dev(name); step = 'created'
        for kd, kk in keys: M.add_key(found, kd, kk)
        M.dev[found]['attrs'].update({k: v for k, v in disp.items() if v})
    else:
        D = M.dev[found]
        if not D['reporter']:
            for kd, kk in keys:
                if kd != 'sysname' and kk not in D['keys'] and not M.has_kind(found, kd): M.add_key(found, kd, kk)
            for k, v in disp.items():
                # model 2.29: lldp rows win; other sources fill empty attributes only
                if v and (src == 'lldp' or not D['attrs'].get(k)): D['attrs'][k] = v
        # split identity check: other keys of the row find another Device
        for kd, kk in keys:
            other = M.kidx.get(kk, set()) - {found}
            if kd != 'sysname' and other:
                M.notes.append(f'split_identity: row key {kk} also on {[M.dev[o]["name"] for o in other]} (resolved to {D["name"] if False else M.dev[found]["name"]})')
    return found, step, None

# ---- §6.7.2 far port ----
def far_port(M, fd, row):
    D = M.dev[fd]; t = row.get('{#REM_PORT_TYPE}'); rp, rpd = row.get('{#REM_PORT}'), row.get('{#REM_PORT_DESC}')
    src = row['{#SOURCE}']
    if D['reporter']:
        ports = D['ports']
        def prim():
            if src == 'cdp' or t == 'interfaceName': return by(ports, 'name', rp, key=True)
            if t == 'macAddress': return mac_identity(ports, rp)
            if t == 'local' and numeric(rp):
                h = by(ports, 'name', rpd, key=True)
                if len(h) == 1: return h
                n = numeric(rp)
                if n in ports: return [ports[n]]
                return by(ports, 'name', rp, key=True)
            return by(ports, 'name', rp, key=True)
        h = prim()
        if len(h) == 1: return ('port', h[0]['if_index'], None)
        if len(h) > 1: return ('device', None, 'port_shared_id')
        h = by(ports, 'name', rpd, key=True)
        if len(h) == 1: return ('port', h[0]['if_index'], 'via rem_port_desc')
        if len(h) > 1: return ('device', None, 'port_shared_id')
        return ('device', None, 'port_unmatched')
    # pseudo port
    if src == 'cdp' or t == 'interfaceName' or (t == 'local' and not numeric(rp)):
        nm = clean(rp)
    else:
        nm = clean(rpd) or clean(rp)
    if nm is None: return ('device', None, 'no_far_port_id')
    k = namekey(nm)
    if k not in D['pseudo']:
        D['pseudo'][k] = {'name': nm, 'if_index': numeric(rp) if t == 'local' and numeric(rp) else None}
    return ('pseudo', k, None)

def run(rows_dir, forms):
    load_forms(forms)
    rows = [json.load(open(f)) for f in sorted(glob.glob(os.path.join(rows_dir, '*.json')))]
    M = Model(); reporters(M, rows)
    # pass B
    allrows = []
    for hid, r in enumerate(rows):
        for rule, lst in (('lldp', r['neighbors_lldp']), ('cdp', r['neighbors_cdp'])):
            for row in lst: allrows.append((hid, rule, r, row))
    resolved = []
    for hid, rule, r, row in allrows:
        d = r['_dev']
        p, how = local_port(M, d, row)
        o = {'reporter': r['sysName'], 'rule': rule, 'row': {k[2:-1]: v for k, v in row.items() if 'LOC_CHASSIS' not in k}, 'local_port_how': how}
        if p is None:
            o['outcome'] = 'local_port_unresolved'; M.cnt['rows_local_port_unresolved:' + how] += 1; M.obs.append(o); continue
        o['local_port'] = p['if_index']; o['local_name'] = p['name']
        keys, disp = row_keys(row, r['sysName'], p['if_index'])
        if keys is None:
            o['outcome'] = 'identity_invalid'; M.cnt['rows_identity_invalid'] += 1; M.obs.append(o); continue
        if not keys:
            o['outcome'] = 'no_identity'; M.cnt['rows_no_identity'] += 1; M.obs.append(o); continue
        o['remote_key'] = keys[0][1]
        resolved.append((hid, p['if_index'], keys[0][1], rule, r, row, o, keys, disp))
    resolved.sort(key=lambda x: (x[0], x[1], x[2], x[3]))
    port_res = {}
    for hid, ix, rk, rule, r, row, o, keys, disp in resolved:
        pd = port_res.setdefault((r['sysName'], ix), set())
        fd, step, shared = far_device(M, keys, disp, row['{#SOURCE}'], pd)
        if fd is not None: pd.add(fd)
        o['far_lookup'] = step
        if fd is None:
            o['outcome'] = 'ambiguous'; o['shared_key'] = shared; M.cnt['identity_keys_shared'] += 1; M.obs.append(o); continue
        o['far_device'] = fd
        o['far'] = far_port(M, fd, row)
        M.obs.append(o)
    # §8.1 + §8.5 per local port
    byport = defaultdict(list)
    for o in M.obs:
        if 'far_device' in o: byport[(o['reporter'], o['local_port'])].append(o)
    cand = {}
    for lp, os_ in byport.items():
        devs = {o['far_device'] for o in os_}
        if len(devs) > 1:
            for o in os_: o['outcome'] = 'ambiguous'; o['why'] = f'{len(devs)} Devices on one port (§8.5)'
            M.cnt['ports_several_neighbors'] += 1
            continue
        fd = devs.pop()
        best = [o for o in os_ if o['far'][0] in ('port', 'pseudo')]
        ends = {(o['far'][0], o['far'][1]) for o in best}
        if len(ends) > 1:
            for o in os_: o['outcome'] = 'conflict'; o['why'] = 'same Device, different far ports'
            continue
        if ends:
            kind, fp = ends.pop(); cand[lp] = ('physical', fd, kind, fp, os_)
        else:
            cand[lp] = ('device', fd, None, os_[0]['far'][2], os_)
    # lag_ambiguous / port_shared_id inside one reporter
    rep_far = defaultdict(list)
    for lp, c in cand.items(): rep_far[(lp[0], c[1])].append(lp)
    for (rep, fd), lps in rep_far.items():
        if len(lps) < 2: continue
        fps = [(cand[lp][2], cand[lp][3]) for lp in lps if cand[lp][0] == 'physical']
        dup = {x for x in fps if fps.count(x) > 1}
        for lp in lps:
            c = cand[lp]
            if c[0] == 'physical' and (c[2], c[3]) in dup:
                cand[lp] = ('device', fd, None, 'port_shared_id', c[4])
            elif c[0] == 'device' and c[3] not in ('port_shared_id',):
                cand[lp] = ('device', fd, None, 'lag_ambiguous', c[4])
    # build links; merge two halves of one cable
    links = {}
    devlinks = []
    for lp, c in cand.items():
        rep = lp[0]; rd = next(i for i, D in M.dev.items() if D['reporter'] == rep)
        a = (rd, 'port', lp[1])
        if c[0] == 'physical':
            b = (c[1], c[2], c[3])
            k = tuple(sorted([a, b], key=str))
            L = links.setdefault(k, {'a': k[0], 'b': k[1], 'seen_by': set(), 'sources': set()})
            L['seen_by'].add(rep); L['sources'] |= {o['rule'] for o in c[4]}
            for o in c[4]: o['outcome'] = 'applied'
        else:
            devlinks.append({'local': a, 'far_device': c[1], 'reason': c[3], 'sources': sorted({o['rule'] for o in c[4]}),
                             'hint': [o['row'].get('REM_PORT') for o in c[4]]})
            for o in c[4]: o['outcome'] = 'applied'
    # port uniqueness
    use = defaultdict(list)
    for k, L in links.items():
        use[L['a']].append(k); use[L['b']].append(k)
    for e in devlinks: use[e['local']].append(('dev', e['far_device']))
    clashes = {p: v for p, v in use.items() if len(v) > 1}
    # reverse half of device_link: D:Q -> R where R:P -> D also exists
    for e in devlinks:
        e['reverse_of'] = [str(x) for x in devlinks if x is not e and x['far_device'] == e['local'][0] and x['local'][0] == e['far_device']]
    for D in M.dev.values():
        if not D['reporter']:
            a = D['attrs']; D['name'] = a.get('sysname') or a.get('chassis') or a.get('ip') or '?'
    return M, rows, links, devlinks, clashes

def pname(M, end):
    d, kind, x = end
    D = M.dev[d]
    if kind == 'port': return f"{D['name']}:{D['ports'][x]['name']}"
    if kind == 'pseudo': return f"{D['name']}:{D['pseudo'][x]['name']}*"
    return D['name']

def endinfo(M, end):
    d, kind, x = end; D = M.dev[d]
    port = D['ports'][x]['name'] if kind == 'port' else (D['pseudo'][x]['name'] if kind == 'pseudo' else None)
    return {'device': D['name'], 'reporter': bool(D['reporter']), 'sysname': D['attrs'].get('sysname'),
            'chassis': D['attrs'].get('chassis'), 'port': port}

if __name__ == '__main__':
    M, rows, links, devlinks, clashes = run(sys.argv[1], sys.argv[2])
    out = sys.argv[3]
    res = {
        'devices': [{'id': D['id'], 'name': D['name'], 'reporter': D['reporter'], 'keys': sorted(D['keys']),
                     'attrs': D['attrs'], 'pseudo_ports': [v['name'] for v in D['pseudo'].values()]} for D in M.dev.values()],
        'physical_links': sorted([{'a': pname(M, L['a']), 'b': pname(M, L['b']), 'seen_by': sorted(L['seen_by']), 'sources': sorted(L['sources']),
                                   'ends': [endinfo(M, L['a']), endinfo(M, L['b'])]} for L in links.values()], key=lambda x: (x['a'], x['b'])),
        'device_links': [{'local': pname(M, e['local']), 'far_device': M.dev[e['far_device']]['name'], 'reason': e['reason'], 'sources': e['sources'], 'hint': e['hint'], 'has_reverse_half': bool(e['reverse_of'])} for e in devlinks],
        'port_clashes': {pname(M, p): [str(x) for x in v] for p, v in clashes.items()},
        'observations': [{k: v for k, v in o.items() if k != 'far_device'} | ({'far_device_name': M.dev[o['far_device']]['name']} if 'far_device' in o else {}) for o in M.obs],
        'counters': dict(M.cnt), 'notes': M.notes,
    }
    json.dump(res, open(out, 'w'), indent=1, ensure_ascii=False, default=str)
    print('devices', len(M.dev), 'reporters', sum(1 for D in M.dev.values() if D['reporter']))
    print('physical_links', len(links), 'device_links', len(devlinks), 'clashes', len(clashes))
    print('counters', dict(M.cnt))
    from collections import Counter
    print('outcomes', Counter(o.get('outcome') for o in M.obs))
    for n in M.notes: print('NOTE', n)
