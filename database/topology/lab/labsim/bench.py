"""The bench of the lab (topology-4-lab-simulator.md §5.6, §9 B1; task 126): a generated network at the size of the release 1 target (FR-0 decision 16) that goes
through the real path of delivery to the snapshot: one host for each reporter, topology rules of the type Zabbix trapper (FR-B D-2, D-33), the rows sent with `zabbix_sender -T`
(the server writes the snapshots by its normal path, FR-B D-15 to D-21), then one run of the ingest CLI. No snmpsim and no SNMP rule is polled. It changes no topology code: it
reads the results of the run from the run summary (the status file that the CLI writes), not from the tables.

    python -m labsim bench gen stands/bench/bench.yaml [--out DIR]      the rows of the network (JSON) and the counts
    python -m labsim bench provision stands/bench/bench.yaml            hosts and rules in Zabbix (tags lab.managed=1, lab.stand=bench)
    python -m labsim bench push stands/bench/bench.yaml <step>          the rows of the step (0, 1, 2) with zabbix_sender
    python -m labsim bench run stands/bench/bench.yaml [--steps 0,1,2]  B1: reset, provision, then push and one run for each step; report in reports/B1-<name>.json
    python -m labsim bench shares                                       the shares of the shape measured on the stand that is in the topo_* tables (R1)
    python -m labsim bench remove                                       the hosts of the bench (tag lab.stand=bench)
"""
import collections
import json
import os
import random
import subprocess
import tempfile
import time

from . import db, runner, zbx
from .model import HERE, LabError

STAND = 'bench'
GROUP = 'lab-bench'
SENDER = os.path.abspath(os.path.join(HERE, '..', '..', '..', 'bin', 'zabbix_sender'))
RULES = {      # kind -> (key, topology_type: 5 DEVICE, 1 PORTS, 2 NEIGHBORS)
    'device': ('topology.bench.device', 5),
    'ports': ('topology.bench.ports', 1),
    'lldp': ('topology.bench.lldp', 2),
    'cdp': ('topology.bench.cdp', 2),
}
DEFAULTS = {
    'seed': 20261125,
    'reporters': 1000,
    'ports_per_reporter': 50,
    'neighbor_rows_per_reporter': 20,     # LLDP rows and CDP rows together, on average
    'core': 2,
    'distribution': 0.05,                 # share of the reporters that are distribution switches
    'cdp_reporters': 0.4,                 # share of the reporters that also report CDP
    'cdp_rows': 0.5,                      # of a CDP reporter's cables to end devices, the share that the CDP rule shows too
    'no_hardware_id': 0.2,                # share of the end device rows without a hardware ID (a name and an address only)
    'junction_ports': 0.02,               # share of the end device ports with several neighbors (2 to 4)
    'shared_ids': 0.002,                  # share of the end devices whose hardware ID is also on another port
    'cdp_only': 0.01,                     # share of the end devices that only the CDP rule shows
    'unresolved': 0.01,                   # share of the LLDP rows whose local port number is no ifIndex of the reporter
    'step2_moved': 0.05,                  # step 2: the share of the end device cables that are moved
}


def load_config(path=None, **override):
    cfg = dict(DEFAULTS)
    if path:
        import yaml
        with open(path) as f:
            cfg.update(yaml.safe_load(f) or {})
    cfg.update({k: v for k, v in override.items() if v is not None})
    return cfg


def mac(prefix, n):
    return '%s:%02x:%02x:%02x:%02x' % (prefix, (n >> 24) & 255, (n >> 16) & 255, (n >> 8) & 255, n & 255)


def generate(cfg):
    """The network and the rows of every rule at the steps 0, 1 and 2. The same seed gives the same rows. Rows are as the native step gives them (macros, raw values), before normalization."""
    rng = random.Random(cfg['seed'])
    n, ports = int(cfg['reporters']), int(cfg['ports_per_reporter'])
    core = min(int(cfg['core']), n)
    dist = max(1, int(round(n * cfg['distribution']))) if n > core else 0
    kind = ['core'] * core + ['distribution'] * dist + ['access'] * (n - core - dist)
    reporters = []
    for i in range(n):
        reporters.append({'host': 'BENCH-%04d' % i, 'kind': kind[i], 'chassis': mac('02:be', i), 'ip': '198.18.%d.%d' % (i // 250, i % 250 + 1),
                          'cdp': False, 'used': set(), 'cables': []})
    for i in rng.sample(range(n), int(round(n * cfg['cdp_reporters']))):
        reporters[i]['cdp'] = True

    def free_port(r, start=2):
        p = start
        while p in r['used']:
            p += 1
        if p > ports:
            return None
        r['used'].add(p)
        return p

    # the tree: access -> distribution -> core, core - core
    cables = []        # {'a': reporter, 'pa', 'b': reporter | None, 'pb', 'end': end device | None}
    for i in range(1, core):
        pa, pb = free_port(reporters[0]), free_port(reporters[i])
        cables.append({'a': 0, 'pa': pa, 'b': i, 'pb': pb})
    dist_ids = list(range(core, core + dist))
    for k, d in enumerate(dist_ids):
        c = k % max(1, core)
        pa, pb = free_port(reporters[c]), free_port(reporters[d], 1)
        if pa and pb:
            cables.append({'a': c, 'pa': pa, 'b': d, 'pb': pb})
    for a in range(core + dist, n):
        d = dist_ids[(a - core - dist) % len(dist_ids)] if dist_ids else 0
        pa, pb = free_port(reporters[d]), free_port(reporters[a], 1)
        if pa and pb:
            cables.append({'a': d, 'pa': pa, 'b': a, 'pb': pb})
    reporter_rows = len(cables) * 2
    target = int(n * cfg['neighbor_rows_per_reporter'])
    # the end devices: the rows that are left, on the access switches (a junction port has 2 to 4 of them)
    ends = []
    access = [i for i in range(n) if reporters[i]['kind'] == 'access'] or list(range(n))
    wanted = max(0, int((target - reporter_rows) / (1 + cfg['cdp_reporters'] * cfg['cdp_rows'])))
    attempts = 0
    while len(ends) < wanted and attempts < wanted * 4 + 100:
        attempts += 1
        r = reporters[rng.choice(access)]
        port = free_port(r, 10)
        if port is None:
            continue
        several = rng.random() < cfg['junction_ports']
        for _ in range(rng.randint(2, 4) if several else 1):
            if len(ends) >= wanted:
                break
            e = len(ends)
            ends.append({'id': e, 'reporter': r['host'], 'port': port, 'chassis': mac('02:ed', e), 'name': 'END-%05d' % e, 'ip': '198.19.%d.%d' % (e // 250, e % 250 + 1),
                         'hw': rng.random() >= cfg['no_hardware_id'], 'cdp_only': False, 'cdp_row': False, 'junction': several})
    for e in rng.sample(range(len(ends)), int(len(ends) * cfg['shared_ids'])) if ends else []:
        ends[e]['chassis'] = ends[(e + 1) % len(ends)]['chassis']      # the hardware ID is on another port too
    for e in rng.sample(range(len(ends)), int(len(ends) * cfg['cdp_only'])) if ends else []:
        ends[e]['cdp_only'] = True
    by_host = {r['host']: r for r in reporters}
    for e in ends:
        e['cdp_row'] = by_host[e['reporter']]['cdp'] and (e['cdp_only'] or rng.random() < cfg['cdp_rows'])
    unresolved = set(rng.sample(range(len(ends)), int(len(ends) * cfg['unresolved']))) if ends else set()
    # step 2: end device cables moved to another free port of another access switch
    moved = {}
    for e in rng.sample(range(len(ends)), int(len(ends) * cfg['step2_moved'])) if ends else []:
        r = reporters[rng.choice(access)]
        if r['host'] == ends[e]['reporter']:
            continue
        port = free_port(r, 10)
        if port is not None:
            moved[e] = (r['host'], port)

    def port_name(p):
        return 'Gi0/%d' % p

    def rows_at(step):
        out = {r['host']: {'device': [], 'ports': [], 'lldp': [], 'cdp': []} for r in reporters}
        for r in reporters:
            rules = out[r['host']]
            rules['device'].append({'{#LOC_CHASSIS}': r['chassis'], '{#LOC_CHASSIS_TYPE}': 'macAddress', '{#LOC_SYSNAME}': r['host']})
            for p in range(1, ports + 1):
                rules['ports'].append({'{#IFINDEX}': str(p), '{#IFNAME}': port_name(p), '{#IFDESCR}': 'GigabitEthernet0/%d' % p, '{#IFTYPE}': '6',
                                       '{#IFADMINSTATUS}': '1', '{#IFOPERSTATUS}': '1', '{#IFSPEED}': '1000000000', '{#LOC_CHASSIS}': r['chassis'], '{#LOC_CHASSIS_TYPE}': 'macAddress'})
        for c in cables:
            for here, port, there, there_port in ((c['a'], c['pa'], c['b'], c['pb']), (c['b'], c['pb'], c['a'], c['pa'])):
                a, b = reporters[here], reporters[there]
                row = {'{#LOC_PORT_NUM}': str(port), '{#LOC_PORT_ID}': port_name(port), '{#LOC_PORT_ID_TYPE}': 'interfaceName', '{#REM_CHASSIS}': b['chassis'],
                       '{#REM_CHASSIS_TYPE}': 'macAddress', '{#REM_SYSNAME}': b['host'], '{#REM_MGMT_IP}': b['ip'], '{#REM_PORT}': port_name(there_port),
                       '{#REM_PORT_TYPE}': 'interfaceName', '{#SOURCE}': 'lldp'}
                out[a['host']]['lldp'].append(row)
                if a['cdp'] and b['cdp']:
                    out[a['host']]['cdp'].append({'{#IFINDEX}': str(port), '{#REM_SYSNAME}': b['host'], '{#REM_MGMT_IP}': b['ip'], '{#REM_PORT}': port_name(there_port),
                                                  '{#SOURCE}': 'cdp'})
        for e in ends:
            host, port = (moved[e['id']] if step >= 2 and e['id'] in moved else (e['reporter'], e['port']))
            if not e['cdp_only']:
                row = {'{#LOC_PORT_NUM}': str(port + (1000 if e['id'] in unresolved else 0)), '{#LOC_PORT_ID_TYPE}': 'interfaceName', '{#SOURCE}': 'lldp',
                       '{#REM_SYSNAME}': e['name'], '{#REM_MGMT_IP}': e['ip'], '{#REM_PORT}': 'eth0', '{#REM_PORT_TYPE}': 'interfaceName'}
                if e['id'] not in unresolved:
                    row['{#LOC_PORT_ID}'] = port_name(port)
                if e['hw']:
                    row.update({'{#REM_CHASSIS}': e['chassis'], '{#REM_CHASSIS_TYPE}': 'macAddress'})
                out[host]['lldp'].append(row)
            if e['cdp_row']:
                out[host]['cdp'].append({'{#IFINDEX}': str(port), '{#REM_SYSNAME}': e['name'], '{#REM_MGMT_IP}': e['ip'], '{#REM_PORT}': 'eth0', '{#SOURCE}': 'cdp'})
        return out

    net = {'reporters': reporters, 'cables': cables, 'ends': ends, 'moved': moved, 'rows_at': rows_at}
    counts = counts_of(rows_at(0), reporters, ends, cables)
    counts['step2_moved'] = len(moved)
    net['counts'] = counts
    return net


def counts_of(rows, reporters, ends, cables):
    c = collections.Counter()
    for host, rules in rows.items():
        for kind, lst in rules.items():
            c['rows_' + kind] += len(lst)
    c['reporters'] = len(reporters)
    c['end_devices'] = len(ends)
    c['cables_between_reporters'] = len(cables)
    c['neighbor_rows'] = c['rows_lldp'] + c['rows_cdp']
    c['ports'] = c['rows_ports']
    return dict(c)


def hosts_of(net):
    return [r['host'] for r in net['reporters']]


def provision(api, cfg, net, log=print):
    """One host for each reporter with an SNMP interface on a unique address that nothing answers, and the four trapper topology rules (no SNMP rule is polled)."""
    groups = api.call('hostgroup.get', {'filter': {'name': [GROUP]}})
    groupid = groups[0]['groupid'] if groups else api.call('hostgroup.create', {'name': GROUP})['groupids'][0]
    have = {h['host'] for h in api.call('host.get', {'filter': {'host': hosts_of(net)}, 'output': ['host']})}
    todo = [r for r in net['reporters'] if r['host'] not in have]
    tags = zbx.with_stand([], STAND)
    for i in range(0, len(todo), 100):
        batch = todo[i:i + 100]
        api.call('host.create', [{'host': r['host'], 'groups': [{'groupid': groupid}], 'tags': tags,
                                 'interfaces': [{'type': 2, 'main': 1, 'useip': 1, 'ip': r['ip'], 'dns': '', 'port': '161', 'details': {'version': 2, 'bulk': 1, 'community': 'bench'}}]}
                                for r in batch])
        log('hosts: %d of %d' % (min(i + 100, len(todo)), len(todo)))
    ids = {h['host']: h['hostid'] for h in api.call('host.get', {'filter': {'host': hosts_of(net)}, 'output': ['hostid', 'host']})}
    rules = [{'hostid': ids[r['host']], 'name': 'Bench ' + kind, 'key_': key, 'type': 2, 'topology_type': ttype}
             for r in net['reporters'] for kind, (key, ttype) in RULES.items()
             if r['host'] in {h for h in ids} and (kind != 'cdp' or r['cdp'])]
    existing = {(x['hostid'], x['key_']) for x in api.call('discoveryrule.get', {'hostids': list(ids.values()), 'output': ['hostid', 'key_']})}
    rules = [x for x in rules if (x['hostid'], x['key_']) not in existing]
    for i in range(0, len(rules), 500):
        api.call('discoveryrule.create', rules[i:i + 500])
        log('rules: %d of %d' % (min(i + 500, len(rules)), len(rules)))
    return ids


def send(rows, clock, log=print, chunk=4000, wait=120):
    """The rows of every rule with `zabbix_sender -T` (the clock is the value's time). A rule that the server does not know yet (the configuration is read every few seconds) is tried again."""
    lines = []
    for host, rules in rows.items():
        for kind, lst in rules.items():
            if kind == 'cdp' and not lst:
                continue
            lines.append('%s\t%s\t%d\t%s\n' % (host, RULES[kind][0], clock, json.dumps(lst, separators=(',', ':'))))
    sent = 0
    for i in range(0, len(lines), chunk):
        part = lines[i:i + chunk]
        deadline = time.time() + wait
        while True:
            with tempfile.NamedTemporaryFile('w', suffix='.txt', delete=False) as f:
                f.writelines(part)
            try:
                out = subprocess.run([SENDER, '-z', '127.0.0.1', '-p', '10051', '-T', '-i', f.name], capture_output=True, text=True, timeout=600).stdout
            finally:
                os.unlink(f.name)
            if 'failed: 0' in out:
                break
            if time.time() > deadline:
                raise LabError('zabbix_sender: %s' % out.strip()[-300:])
            time.sleep(5)
        sent += len(part)
        log('sent %d of %d values' % (sent, len(lines)))
    return len(lines)


def remove(api, log=print):
    ids = [h['hostid'] for h in api.call('host.get', {'tags': [{'tag': zbx.STAND_TAG, 'value': STAND, 'operator': 1}], 'output': ['hostid']})]
    for i in range(0, len(ids), 100):
        api.call('host.delete', ids[i:i + 100])
    groups = api.call('hostgroup.get', {'filter': {'name': [GROUP]}})
    if groups:
        try:
            api.call('hostgroup.delete', [groups[0]['groupid']])
        except LabError:
            pass
    log('removed %d bench host(s)' % len(ids))
    return len(ids)


def wait_snapshots(api, hosts_count, clock, log=print, wait=300):
    """The snapshots are written by the server's normal path: wait until the API says every bench rule has its value (item lastclock is not used: the rules are discovery rules)."""
    time.sleep(5)


def one_run(settings, name):
    """One run of the ingest CLI; the time of the process and the run summary (status file)."""
    t0 = time.time()
    st = runner.run_ingest(settings)
    wall = time.time() - t0
    summary = st.get('summary') or {}
    keep = ('devices_created', 'devices_updated', 'ports_created', 'links_created', 'links_superseded', 'links_revived', 'ports_ambiguous', 'links_ports_differ', 'links_device_level',
            'reporters_processed', 'rows_duplicate_claim', 'rows_no_identity', 'rows_local_port_unresolved', 'observations', 'snapshots_old', 'identity_keys_shared')
    out = {'step': name, 'wall_seconds': round(wall, 2), 'status': st.get('status'), 'stats': summary.get('stats'), 'summary': {k: summary.get(k) for k in keep}}
    out['summary']['rows_local_port_unresolved'] = (summary.get('rows_local_port_unresolved') or {}).get('count')
    out['summary']['rows_no_identity'] = (summary.get('rows_no_identity') or {}).get('count')
    out['summary']['rows_duplicate_claim'] = (summary.get('rows_duplicate_claim') or {}).get('count')
    out['summary']['snapshots_old'] = len(summary.get('snapshots_old') or [])
    out['summary']['identity_keys_shared'] = (summary.get('identity_keys_shared') or {}).get('count')
    return out


def run(settings, api, conn, cfg, steps=(0, 1, 2), log=print, remove_after=False):
    """B1 (lab §9): reset the topology, make the hosts and the rules, then for each step push the rows and run once. Returns the report."""
    t0 = time.time()
    net = generate(cfg)
    report = {'config': cfg, 'counts': net['counts'], 'steps': [], 'started': int(t0)}
    db.reset_topo(conn)
    log('topology reset; %d reporters, %s' % (len(net['reporters']), net['counts']))
    provision(api, cfg, net, log)
    time.sleep(40)      # the configuration reaches the server's cache before the first value
    for step in steps:
        rows = net['rows_at'](0 if step < 2 else 2)
        clock = int(time.time())
        send(rows, clock, log)
        time.sleep(15)      # the server writes the snapshots by its normal path (a queue of the LLD manager)
        r = one_run(settings, step)
        r['clock'] = clock
        report['steps'].append(r)
        log('step %s: %s s (%s), %s' % (step, r['wall_seconds'], (r.get('stats') or {}).get('seconds'), r['summary']))
    report['seconds'] = round(time.time() - t0, 1)
    if remove_after:
        remove(api, log)
    return report


def shares(conn):
    """The shares of the shape on the stand that is in the topo_* tables: read from the snapshots that are there (the NEIGHBORS and PORTS snapshots of the reporters)."""
    cur = conn.cursor(dictionary=True)
    cur.execute('SELECT s.itemid, s.hostid, s.topology_type, s.rows_json FROM topo_lld_snapshot s')
    reporters = {}
    chassis_of = {}
    for row in cur.fetchall():
        rows = json.loads(row['rows_json'])
        r = reporters.setdefault(row['hostid'], {'ports': 0, 'lldp': [], 'cdp': [], 'chassis': None})
        if row['topology_type'] == 1:
            r['ports'] += len(rows)
            for x in rows:
                if x.get('loc_chassis'):
                    r['chassis'] = x['loc_chassis']
        elif row['topology_type'] == 2:
            for x in rows:
                r['cdp' if x.get('source') == 'cdp' else 'lldp'].append(x)
    for h, r in reporters.items():
        if r['chassis']:
            chassis_of[str(r['chassis']).lower()] = h
    reporters = {h: r for h, r in reporters.items() if r['lldp'] or r['cdp']}
    n = len(reporters)
    lldp_rows = sum(len(r['lldp']) for r in reporters.values())
    cdp_rows = sum(len(r['cdp']) for r in reporters.values())
    ports = sum(r['ports'] for r in reporters.values())
    to_reporter = 0
    no_hw = 0
    per_port = collections.defaultdict(set)
    by_chassis = collections.defaultdict(set)
    cdp_keys, lldp_keys = set(), set()
    unresolved = 0
    for h, r in reporters.items():
        port_nums = set()
        for x in r['lldp']:
            ch = str(x.get('rem_chassis') or '').lower()
            if ch and ch in chassis_of:
                to_reporter += 1
            if not ch or x.get('rem_chassis_type') not in (None, '', 'macAddress'):
                no_hw += 1
            key = ch or ('s:' + str(x.get('rem_sysname') or x.get('rem_mgmt_ip') or ''))
            per_port[(h, str(x.get('loc_port_num') or x.get('if_index') or x.get('loc_port_id')))].add(key)
            by_chassis[ch].add((h, str(x.get('loc_port_num') or x.get('if_index'))))
            lldp_keys.add(key)
        for x in r['cdp']:
            cdp_keys.add(str(x.get('rem_sysname') or x.get('rem_mgmt_ip')))
    ends = lldp_rows - to_reporter
    junction = sum(1 for v in per_port.values() if len(v) >= 2)
    shared = sum(1 for k, v in by_chassis.items() if k and len({p for p in v}) >= 2 and k not in chassis_of)
    out = {'reporters': n, 'ports_per_reporter': round(ports / n, 1) if n else None, 'lldp_rows': lldp_rows, 'cdp_rows': cdp_rows,
           'rows_per_reporter': round((lldp_rows + cdp_rows) / n, 1) if n else None,
           'reporters_with_cdp': sum(1 for r in reporters.values() if r['cdp']),
           'share_reporters_with_cdp': round(sum(1 for r in reporters.values() if r['cdp']) / n, 3) if n else None,
           'lldp_rows_to_a_reporter': to_reporter, 'lldp_rows_to_end_devices': ends,
           'share_end_device_rows': round(ends / lldp_rows, 3) if lldp_rows else None,
           'share_rows_without_hardware_id': round(no_hw / lldp_rows, 3) if lldp_rows else None,
           'junction_ports': junction, 'ports_with_neighbors': len(per_port), 'share_junction_ports': round(junction / len(per_port), 3) if per_port else None,
           'shared_hardware_ids': shared, 'cdp_only_devices': len(cdp_keys - lldp_keys) if cdp_keys else 0, 'cdp_devices': len(cdp_keys)}
    return out
