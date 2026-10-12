"""Scenario SC, the corpus smoke run (topology-4-lab-simulator.md: corpus devices; handoff task 020 §2).

Every file of the vendor corpus is a lab device (`corpus_devices:`), served as it is. Step 0 only: provision, poll once, ingest, measure.
At most 30 devices run together (a batch), the topo_* state is reset between batches, and two files that share a chassis id never run in
one batch (identity precheck). The result is a measurement; only a rule that fails while its anchor is in the walk, an ingest error and a
crash fail the run. The corpus is read at run time and never copied into the repository (GPLv3).
"""
import glob
import json
import os
import re
import threading
import time

from . import ctl, db, gen, model, runner, zbx
from .model import HERE, LabError

CORPUS_DEFAULT = '/media/sf_topology-specs/corpus'
BATCH = 30
IP_PREFIX = '127.0.10.'          # 127/8 is local: no alias is needed
# the files of the core set of the task (findings F1-F10), as names of corpus files
CORE_SET = ['vrp_ac6605-26', 'dell-os10', 'zynos', 'gam', 'aos7', 'dlink_dgs-1510-28x-me', 'mypoweros', 'raisecom-ros', 'jetstream_vlans',
            'slxos_slx9150', 'edgecos_ecs4610-24f', 'arubaos-cx', 'ciscosb_sg350x-24p']
LOC_CHASSIS = (1, 0, 8802, 1, 1, 2, 1, 3, 2)
REM_CHASSIS = (1, 0, 8802, 1, 1, 2, 1, 4, 1, 1, 5)
ANCHORS = {'ports': [(1, 3, 6, 1, 2, 1, 2, 2, 1, 2), (1, 3, 6, 1, 2, 1, 31, 1, 1, 1, 1)],      # ifDescr, ifName
           'lldp': [(1, 0, 8802, 1, 1, 2, 1, 3, 7)]}                                           # lldpLocPortTable
SERVER_LOG = os.path.expanduser('~/work/logs/zabbix_server.log')


def corpus_files(corpus_dir):
    files = sorted(glob.glob(os.path.join(corpus_dir, 'librenms', '*.snmprec')))
    if not files:
        raise LabError('no corpus files in %s/librenms' % corpus_dir)
    return files


def host_name(path):
    return os.path.basename(path)[:-len('.snmprec')]


def _octets(tag, value):
    if tag == '4x':
        return bytes.fromhex(value)
    if tag == '4e':
        return value.encode('latin-1', errors='replace').decode('unicode_escape').encode('latin-1')
    return value.encode('utf-8', errors='surrogateescape')


def read_records(path):
    return gen.corpus_records(path)


def chassis_ids(path):
    """The chassis ids a file mentions: lldpLocChassisId and every lldpRemChassisId, as lower-case hex of the octets (the identity bytes)."""
    out = set()
    for oid, (tag, value) in read_records(path).items():
        if oid == LOC_CHASSIS or oid[:len(REM_CHASSIS)] == REM_CHASSIS:
            if tag in ('4', '4x', '4e'):
                try:
                    out.add(_octets(tag, value).hex())
                except ValueError:
                    pass
    return out


def precheck(files):
    """{chassis id: [file names]} for every id that occurs in more than one file."""
    seen = {}
    for p in files:
        for c in chassis_ids(p):
            seen.setdefault(c, []).append(host_name(p))
    return {c: sorted(n) for c, n in sorted(seen.items()) if len(n) > 1}


def plan_batches(files, shared, size=BATCH):
    """Greedy: a file goes to the first batch with room that holds no file sharing a chassis id with it."""
    conflict = {}
    for names in shared.values():
        for a in names:
            conflict.setdefault(a, set()).update(n for n in names if n != a)
    batches = []
    for p in files:
        n = host_name(p)
        for b in batches:
            if len(b) < size and not (conflict.get(n, set()) & {host_name(q) for q in b}):
                b.append(p)
                break
        else:
            batches.append([p])
    return batches


def batch_scenario(batch, transport=None, lab=None, sid='SC', ifindex=False):
    """The scenario of one batch: the base lab without its own devices, plus `corpus_devices` in the patch; one step."""
    lab = lab or model.load_lab()
    lab.devices, lab.cables, lab.deviations = {}, [], {}
    cd = {}
    for i, p in enumerate(batch):
        cd[host_name(p)] = {'snmprec': p, 'ip': IP_PREFIX + str(i + 1), 'transport': transport or 'lld', 'proxy': None, 'ifindex': ifindex}
    data = {'id': sid, 'title': 'corpus smoke', 'steps': [{'note': 'step 0: one poll of every corpus device'}], 'patch': {'corpus_devices': cd}}
    return model.Scenario(data, lab)


class Load(threading.Thread):
    """Samples the 1-minute load average and the free memory of the VM while a batch runs."""

    def __init__(self):
        super().__init__(daemon=True)
        self.stop = threading.Event()
        self.load_max, self.mem_min = 0.0, None

    def run(self):
        while not self.stop.is_set():
            try:
                self.load_max = max(self.load_max, float(open('/proc/loadavg').read().split()[0]))
                for line in open('/proc/meminfo'):
                    if line.startswith('MemAvailable:'):
                        kb = int(line.split()[1])
                        self.mem_min = kb if self.mem_min is None else min(self.mem_min, kb)
            except (OSError, ValueError):
                pass
            self.stop.wait(2)


def log_size():
    try:
        return os.path.getsize(SERVER_LOG)
    except OSError:
        return 0


def log_problems(offset):
    """Lines the server wrote since `offset` that are about a crash or about the topology code failing."""
    try:
        with open(SERVER_LOG, 'rb') as f:
            f.seek(offset)
            text = f.read().decode('utf-8', errors='replace')
    except OSError:
        return []
    pat = re.compile(r'(Got signal|crashed|terminated by signal|topolog\w*.*(error|fail|cannot|invalid)|(error|fail|cannot|invalid).*topolog)', re.I)
    return [l.strip()[:300] for l in text.splitlines() if pat.search(l)]


def anchors_in(records):
    return {role: any(o[:len(a)] == a for o in records for a in roots) for role, roots in ANCHORS.items()}


def delete_hosts(api, names, log):
    """The corpus hosts of a batch (those with the lab tag): nothing else is touched."""
    for h in api.call('host.get', {'filter': {'host': list(names)}, 'output': ['hostid', 'host'], 'selectTags': 'extend'}):
        if zbx._has_tag(h):
            api.call('host.delete', [h['hostid']])
    log('  pruned %d corpus host(s)' % len(names))


def run_batch(sc, settings, api, conn, timeout, log):
    t0 = time.time()
    names = sorted(sc.states[0].monitored())
    dev = sc.states[0].devices
    transport = {n: dev[n]['zabbix']['transport'] for n in names}
    records = {n: read_records(dev[n]['snmprec']) for n in names}
    load = Load()
    load.start()
    offset = log_size()
    result = {'devices': {}, 'errors': []}
    try:
        zbx.provision(api, sc, prune=False, log=log)
        ctl.up(sc, settings, log)
        ms = zbx.masters(api, names)
        for n in names:
            if transport[n] == 'push':
                if not (ms.get(n) or {}).get('push'):
                    raise LabError('host %s has no trapper rules (provision with the push template)' % n)
            elif not ms.get(n) or not ms[n]['core']:
                raise LabError('host %s has no topology.walk.core item' % n)
        zbx.restore_fresh_macro(api, runner.FRESH_FILE, log)
        zbx.suspend_schedule(api, ms, runner.SCHEDULE_FILE, log)
        db.reset_topo(conn)
        log('reset: topo_* cleared')
        rules = {}          # rule itemid -> (host, key)
        for n in names:
            for r in api.call('discoveryrule.get', {'hostids': ms[n]['hostid'], 'output': ['itemid', 'key_', 'status']}):
                if r['status'] == '0' and (r['key_'].startswith('topology.') or r['key_'] == 'net.if.discovery'):
                    rules[r['itemid']] = (n, r['key_'])
        before = db.snapshot_clocks(conn)
        started = int(time.time())
        masters = [ms[n][k]['itemid'] for n in names if transport[n] == 'lld' for k in ('core', 'fdb') if ms[n].get(k)]
        ifdisc = [ms[n]['ifdisc']['itemid'] for n in names if transport[n] == 'lld' and ms[n].get('ifdisc')]
        zbx.check_now(api, masters + ifdisc)
        push = {}
        for n in names:
            if transport[n] == 'push':
                ok, detail = runner.run_push(n, dev[n], settings, log)
                push[n] = {'ok': ok, 'detail': detail}
        # a rule is settled when it has a new snapshot, or the server marked it unsupported, or its master item failed
        deadline = time.time() + timeout
        status = {}
        while time.time() < deadline:
            now = db.snapshot_clocks(conn)
            items = {i['itemid']: i for i in api.call('item.get', {'itemids': masters, 'output': ['itemid', 'state', 'error', 'lastclock']})} if masters else {}
            rs = {r['itemid']: r for r in api.call('discoveryrule.get', {'itemids': list(rules), 'output': ['itemid', 'state', 'error']})}
            for rid, (n, key) in rules.items():
                master_of = None
                for k in ('core', 'fdb'):
                    if ms[n].get(k) and rid in ms[n]['rules'][k]:
                        master_of = items.get(ms[n][k]['itemid'])
                st = status.get(rid)
                if st and st['settled']:
                    continue
                if now.get(int(rid), 0) > before.get(int(rid), 0):
                    status[rid] = {'settled': True, 'state': 'snapshot', 'error': ''}
                elif rs[rid]['state'] == '1':
                    status[rid] = {'settled': True, 'state': 'unsupported', 'error': rs[rid]['error']}
                elif master_of is not None and master_of['state'] == '1':
                    status[rid] = {'settled': True, 'state': 'master unsupported', 'error': master_of['error']}
                elif transport[n] == 'push' and not push[n]['ok']:
                    status[rid] = {'settled': True, 'state': 'push failed', 'error': push[n]['detail']}
            todo = [r for r in rules if r not in status]
            # key net.if.discovery is the standard rule and may not answer (as in the other scenarios): it is not waited for
            todo = [r for r in todo if rules[r][1] != 'net.if.discovery']
            if not todo:
                break
            time.sleep(1)
        for rid in rules:
            status.setdefault(rid, {'settled': False, 'state': 'no result', 'error': 'no snapshot and no error after %ss' % timeout})
        ingest = runner.run_ingest(settings)
        state = db.natural_state(conn)
        snaps = {r['itemid']: r for r in db.rows(conn, 'SELECT itemid, rows_total, rows_valid FROM topo_lld_snapshot')}
        bad_ident = (ingest.get('summary') or {}).get('rows_identity_invalid') or {}
        host_of = {}
        for dname, hname in state['represented_by'].items():
            host_of[hname] = dname
        for n in names:
            dn = host_of.get(n)
            ports = state['ports'].get(dn, []) if dn else []
            others = set()
            for l in state['links']:
                if l['state'] != 'active':
                    continue
                devs = [e.split(':', 1)[0] for e in l['ends']]
                if dn in devs:
                    others.update(d for d in devs if d != dn)
            corpus_links = [l['ends'] for l in state['links'] if l['state'] == 'active'
                            and all(e.split(':', 1)[0] in host_of.values() for e in l['ends'])
                            and dn in [e.split(':', 1)[0] for e in l['ends']]]
            anchors = anchors_in(records[n])
            row = {'transport': transport[n], 'anchors': anchors, 'rules': {}, 'device': dn,
                   'ports': sum(1 for p in ports if not p.endswith('*')), 'pseudo_ports': sum(1 for p in ports if p.endswith('*')),
                   'neighbor_devices': len(others), 'links_between_corpus_devices': corpus_links}
            for rid, (hn, key) in rules.items():
                if hn != n:
                    continue
                snap = snaps.get(int(rid))
                row['rules'][key] = dict(status[rid], rows_total=snap['rows_total'] if snap else None, rows_valid=snap['rows_valid'] if snap else None,
                                         rows_identity_invalid=bad_ident.get(int(rid), bad_ident.get(str(rid), 0)))
            result['devices'][n] = row
            for role in ('ports', 'lldp'):
                r = row['rules'].get('topology.%s' % role) or row['rules'].get('topology.push.%s' % role)
                if r and anchors[role] and r['state'] in ('unsupported', 'master unsupported', 'push failed', 'no result') and 'walk holds no ' in r['error']:
                    # the corpus lacks an OID the template needs (for example ifType): not a defect, listed on its own
                    result.setdefault('template_oid_missing', []).append({'device': n, 'rule': role, 'error': r['error'][:200]})
                elif r and anchors[role] and r['state'] in ('unsupported', 'master unsupported', 'push failed', 'no result'):
                    result.setdefault('rule_failed', []).append(n)
                    result['errors'].append('%s: the %s rule is %s (%s) while its anchor is in the walk' % (n, role, r['state'], r['error'][:150]))
        result['ingest'] = {k: ingest.get(k) for k in ('status', 'error')}
        result['ingest']['summary'] = {k: v for k, v in (ingest.get('summary') or {}).items() if not isinstance(v, (dict, list)) or v in ({}, [])}
        if ingest.get('status') != 'done':
            result['errors'].append('ingest: %s %s' % (ingest.get('status'), ingest.get('error')))
        result['devices_in_topology'] = len(state['devices'])
        result['links_total'] = len([l for l in state['links'] if l['state'] == 'active'])
        if not runner.server_running():
            result['errors'].append('zabbix_server is not running after the batch (crash?)')
    finally:
        load.stop.set()
        try:
            ctl.down(log)
        finally:
            zbx.restore_schedule(api, runner.SCHEDULE_FILE, log)
            delete_hosts(api, names, log)
    result['server_log'] = log_problems(offset)
    result['seconds'] = round(time.time() - t0, 1)
    result['load_max'] = load.load_max
    result['mem_available_min_mb'] = round(load.mem_min / 1024) if load.mem_min else None
    return result


def table(report):
    lines = ['| device | transport | rule | state | rows_total | rows_valid | identity_invalid | ports | pseudo | neighbor devices | links among corpus |',
             '|---|---|---|---|---|---|---|---|---|---|---|']
    for b in report['batches']:
        for n, d in sorted(b['devices'].items()):
            for key, r in sorted(d['rules'].items()):
                lines.append('| %s | %s | %s | %s%s | %s | %s | %s | %s | %s | %s | %s |' % (
                    n, d['transport'], key, r['state'], (': ' + r['error'][:60].replace('|', '/')) if r['error'] else '',
                    r['rows_total'], r['rows_valid'], r['rows_identity_invalid'], d['ports'], d['pseudo_ports'], d['neighbor_devices'],
                    len(d['links_between_corpus_devices'])))
    return '\n'.join(lines)


def run(settings, api, conn, corpus_dir=CORPUS_DEFAULT, files=None, size=BATCH, push_core=True, timeout=150, dry_run=False, log=print, ifindex=False):
    paths = corpus_files(corpus_dir)
    if files:
        paths = [p for p in paths if host_name(p) in files]
    shared = precheck(paths)
    plan = [('lld', b) for b in plan_batches(paths, shared, size)]
    if push_core:
        core = [p for p in paths if host_name(p) in CORE_SET]
        plan += [('push', b) for b in plan_batches(core, shared, size)]
    report = {'corpus': corpus_dir, 'ifindex_added': ifindex, 'files': len(paths), 'shared_chassis_ids': shared,
              'plan': [{'transport': t, 'devices': [host_name(p) for p in b]} for t, b in plan], 'batches': [], 'errors': []}
    log('%d file(s), %d chassis id(s) shared between files, %d batch(es)' % (len(paths), len(shared), len(plan)))
    if dry_run:
        return report
    started = time.time()
    try:
        for i, (transport, b) in enumerate(plan):
            log('batch %d/%d: %d device(s), transport %s' % (i + 1, len(plan), len(b), transport))
            res = run_batch(batch_scenario(b, transport, ifindex=ifindex), settings, api, conn, timeout, log)
            res['transport'] = transport
            report['batches'].append(res)
            report['errors'] += res['errors']
            for e in res['errors']:
                log('  ERROR   ' + e)
        # A walk that times out while 30 simulators and the server share the VM is a load effect, not a finding: the devices whose PORTS/LLDP rule failed
        # run once more, alone. Both outcomes are in the report; an error stays only if the device fails alone too.
        failed = sorted({n for b in report['batches'] for n in b.get('rule_failed', [])})
        if failed:
            log('retry alone: %s' % ', '.join(failed))
            report['retried'] = {}
            for n in failed:
                tr = next(b['transport'] for b in report['batches'] if n in b.get('rule_failed', []))
                p = next(q for q in paths if host_name(q) == n)
                res = run_batch(batch_scenario([p], tr, ifindex=ifindex), settings, api, conn, timeout, log)
                res['transport'] = tr
                report['batches'].append(res)
                report['retried'][n] = 'passed alone' if not res['errors'] else 'failed alone'
                if not res['errors']:
                    report['errors'] = [e for e in report['errors'] if not e.startswith(n + ':')]
                else:
                    report['errors'] += res['errors']
    finally:
        db.reset_topo(conn)          # back to the --reset state
    report['seconds'] = round(time.time() - started, 1)
    report['load_max'] = max([b['load_max'] for b in report['batches']] or [0])
    return report
