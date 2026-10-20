"""Scenario runner (topology-4-lab-simulator.md "Scenario runner"): per step switch data -> poll -> wait -> ingest -> assert -> report."""
import json
import os
import re
import subprocess
import time

from . import ctl, db, gen, zbx
from .model import HERE, LabError

INGEST = os.path.abspath(os.path.join(HERE, '..', 'discovery', 'ingest.php'))
SCHEDULE_FILE = os.path.join(ctl.RUN_DIR, 'schedule.json')
FRESH_FILE = os.path.join(ctl.RUN_DIR, 'freshness.json')
ROLES_FILE = os.path.join(ctl.RUN_DIR, 'roles.json')
DERIVED = os.path.join(HERE, 'php', 'derived.php')
STATUS_FILE = os.path.join(os.environ.get('TMPDIR', '/tmp'), 'topology-ingest-status.json')


def server_running():
    return subprocess.run(['pgrep', '-x', 'zabbix_server'], capture_output=True).returncode == 0


def run_ingest(settings):
    dsn = 'mysql:host=%s;dbname=%s' % (settings.get('db_host', 'localhost'), settings['database'])
    t0 = int(time.time())
    p = subprocess.run(['php', INGEST, '--pdo-dsn', dsn, '--pdo-user', settings.get('db_user', 'zabbix')],
                       capture_output=True, text=True, timeout=300)
    if p.returncode:
        raise LabError('ingest failed (%d): %s' % (p.returncode, (p.stderr or p.stdout).strip()[:500]))
    with open(STATUS_FILE) as f:
        st = json.load(f)
    if st.get('started_at', 0) < t0 - 1:
        raise LabError('ingest status file is older than this run (started_at %s < %s)' % (st.get('started_at'), t0))
    return st


def run_derived():
    """The derived state the UI reads (php/derived.php: read only), as a dict; LabError if the helper fails."""
    p = subprocess.run(['php', DERIVED], capture_output=True, text=True, timeout=120)
    try:
        answer = json.loads(p.stdout.strip().splitlines()[-1])
    except (ValueError, IndexError):
        answer = {'error': (p.stderr or p.stdout).strip()[:300]}
    if p.returncode or 'error' in answer:
        raise LabError('the read-only helper failed: %s' % answer.get('error'))
    return answer


class Result:
    def __init__(self, kind, spec, status, message=''):
        self.kind, self.spec, self.status, self.message = kind, spec, status, message

    def as_dict(self):
        return {'assert': self.kind, 'spec': self.spec, 'result': self.status, 'message': self.message}


MAC_FORM = re.compile(r'^[0-9a-fA-F]{2}([:.-]?[0-9a-fA-F]{2}){5}$')


def normal_chassis(key):
    """A chassis id key in normal form (normalization §4.1, §4.2): a MAC in any text form becomes lower-case colon-separated hex; anything else stays as it is."""
    key = str(key)
    if MAC_FORM.match(key):
        digits = re.sub(r'[^0-9a-fA-F]', '', key).lower()
        return ':'.join(digits[i:i + 2] for i in range(0, 12, 2))
    return key


def split_end(ref):
    """An end of a link or an observation: `Device`, `Device:port` (split at the first ':') or {device: <key>, port: <name>}. A Device key is a text, or the explicit
    form {sysname: <value>} / {chassis: <value>}. Returns (Device key, port or None)."""
    if isinstance(ref, dict):
        bad = set(ref) - {'device', 'port'}
        if bad or 'device' not in ref:
            raise LabError('bad end %r: the object form is {device: <key>, port: <name>}' % (ref,))
        key = ref['device']
        return (key if isinstance(key, dict) else str(key)), (None if ref.get('port') is None else str(ref['port']))
    ref = str(ref)
    if ':' in ref:
        device, port = ref.split(':', 1)
        return device, port
    return ref, None


def resolve_device(state, key):
    """The Device node ids of a Device key (lab spec 8.3): the sysname; if no Device or several have that sysname, the chassis id in normal form. A key that gives
    several Devices fails with `ambiguous key <key>`. No Device at all is an empty list (a link to it is absent).
    The explicit key {sysname: <value>} or {chassis: <value>} compares only that attribute (the chassis in normal form); no Device is `no device <key>`, several
    are `ambiguous key <key>`."""
    nodes = state.get('device_nodes') or []
    if isinstance(key, dict):
        if len(key) != 1 or next(iter(key)) not in ('sysname', 'chassis'):
            raise LabError('bad Device key %r: the explicit forms are {sysname: <value>} and {chassis: <value>}' % (key,))
        kind, value = next(iter(key.items()))
        if kind == 'sysname':
            found = [n['id'] for n in nodes if n['sysname'] == str(value)]
        else:
            want = normal_chassis(value)
            found = [n['id'] for n in nodes if want in (n['chassis_id'], n['mac'])]
        if not found:
            raise LabError('no device %s' % (key,))
        if len(found) > 1:
            raise LabError('ambiguous key %s' % (key,))
        return found
    named = [n['id'] for n in nodes if n['sysname'] == key]
    if len(named) == 1:
        return named
    want = normal_chassis(key)
    by_chassis = [n['id'] for n in nodes if want in (n['chassis_id'], n['mac'])]
    found = by_chassis or named
    if len(found) > 1:
        raise LabError('ambiguous key %s' % key)
    return found


def _end_matches(state, link, idx, ref, cache):
    key, port = split_end(ref)
    ck = (id(state), repr(key))
    if ck not in cache:
        cache[ck] = set(resolve_device(state, key))
    if link['end_devices'][idx] not in cache[ck]:
        return False
    return port is None or link['end_ports'][idx] == port


def find_links(state, a, b, type_=None):
    out = []
    cache = {}
    for l in state['links']:
        if type_ and l['type'] != type_:
            continue
        if (_end_matches(state, l, 0, a, cache) and _end_matches(state, l, 1, b, cache)) or \
                (_end_matches(state, l, 1, a, cache) and _end_matches(state, l, 0, b, cache)):
            out.append(l)
    return out


COUNTER_POLL_GAP = 30            # lab spec 9 S6 "Load reading": seconds between two polls of the counter items
COUNTER_PAIR_MAX = 60            # a value counts only when its clock is less than this after the previous value of the same item
COUNTER_POLLS = 3


def read_fresh_counters(api, conn, itemids, log=print, sleep=time.sleep, now=time.time):
    """Lab spec 9 S6 "Load reading": make Zabbix poll the counter items ("check now") 30 s apart and wait until every item has a value whose clock is less than
    60 s after the previous value of the same item (a 32-bit counter wraps in about 360 s at 95 % of 100 Mbit/s: a longer gap gives a wrong rate). At most 3 polls.
    Returns the number of polls; LabError('no fresh counter pair') when 3 polls did not give a pair for every item."""
    itemids = sorted({str(i) for i in itemids})
    start = int(now())
    for poll in range(1, COUNTER_POLLS + 1):
        t0 = now()
        before = {i: (db.item_history(conn, i, 1) or [(0, None)])[0][0] for i in itemids}
        zbx.check_now(api, itemids)
        while now() < t0 + COUNTER_POLL_GAP - 2 and any((db.item_history(conn, i, 1) or [(0, None)])[0][0] <= before[i] for i in itemids):
            sleep(1)
        fresh = True
        for i in itemids:
            h = db.item_history(conn, i, 2)
            if len(h) < 2 or h[0][0] < start or h[0][0] - h[1][0] >= COUNTER_PAIR_MAX:
                fresh = False
        if fresh:
            return poll
        if poll < COUNTER_POLLS:
            sleep(max(0, t0 + COUNTER_POLL_GAP - now()))
    raise LabError('no fresh counter pair')


def evaluate(a, state, ingest, history, clocks_hist, step, derived=None):
    """One assertion. Returns Result. Forms: topology-4-lab-simulator.md "Assertions" plus snapshot_unchanged (an extension).

    derived: a zero-argument callable returning the helper's derived state (run_derived()), for stale, contradiction and badge.
    """
    if 'pending' in a:
        return Result('pending', a, 'pending', a['pending'])
    if 'link' in a:
        x, y = a['link']
        ls = find_links(state, x, y, a.get('type'))
        if a.get('discovered_via'):
            ls = [l for l in ls if l['discovered_via'] == a['discovered_via']]
        if a.get('far_port_reason'):
            ls = [l for l in ls if l['far_port_reason'] == a['far_port_reason']]
        want = a.get('state', 'active')
        if want == 'stale' or 'stale' in a:
            by_edge = {l['edge_id']: l for l in derived()['links']}
            ls = [dict(l, stale=by_edge.get(str(l['id']), {}).get('stale')) for l in ls]
        if want == 'stale':
            hit = [l for l in ls if l['state'] == 'active' and l['stale']]
            return Result('link', a, 'pass' if hit else 'fail',
                          '' if hit else 'no stale active link %s - %s (found: %s)' % (x, y, [(l['ends'], l['state'], l['stale']) for l in ls]))
        if 'stale' in a:
            ls = [l for l in ls if l['stale'] == a['stale']]
        if want == 'absent':
            active = [l for l in ls if l['state'] == 'active']
            return Result('link', a, 'fail' if active else 'pass',
                          'unexpected active link: %s' % [(l['ends'], l['type'], l['discovered_via']) for l in active] if active else '')
        ok = any(l['state'] == want for l in ls)
        return Result('link', a, 'pass' if ok else 'fail',
                      '' if ok else 'no %s link %s - %s (found: %s)' % (want, x, y, [(l['ends'], l['type'], l['discovered_via'], l['state']) for l in ls]))
    if 'observation_count' in a:
        r = a['observation_count']
        ls = find_links(state, r['edge'][0], r['edge'][1])
        ids = {l['id'] for l in ls}
        n = len([o for o in state['observations'] if o['outcome'] == r['outcome'] and o['edge_id'] in ids])
        return Result('observation_count', a, 'pass' if n == r['equals'] else 'fail',
                      '' if n == r['equals'] else '%d %s observation(s) of link %s - %s, expected %s' % (n, r['outcome'], r['edge'][0], r['edge'][1], r['equals']))
    if 'contradiction' in a:
        r = a['contradiction']
        ids = {str(l['id']) for l in find_links(state, r['link'][0], r['link'][1])}
        if not ids:
            return Result('contradiction', a, 'fail', 'no link %s - %s' % tuple(r['link']))
        entries = [c for c in derived()['contradictions'] if c['edge_id'] in ids and c['contradicted']]
        want = sorted(r.get('hidden_neighbors') or [])
        got = sorted({n for c in entries for n in c['hidden_neighbors']})
        ok = got == want and bool(entries) == bool(want)
        return Result('contradiction', a, 'pass' if ok else 'fail',
                      '' if ok else 'hidden neighbors %s, expected %s' % (got, want))
    if 'link_items' in a or 'link_load' in a or 'link_severity' in a:
        # the items, the load and the severity that the UI shows for a link (model §3.2, §4.8; php/derived.php): link_items {link} - both end ports have
        # items (zabbix_itemids not empty); link_load {link, percent, tolerance} - both sides within tolerance (default 5 points) of percent;
        # link_severity {link, present: bool} - the link has (or has not) a severity from an active trigger on the items of its end ports
        kind = 'link_items' if 'link_items' in a else 'link_load' if 'link_load' in a else 'link_severity'
        r = a[kind]
        ids = {str(l['id']) for l in find_links(state, r['link'][0], r['link'][1], 'physical_link')}
        mine = [l for l in derived()['links'] if l['edge_id'] in ids]
        if not mine:
            return Result(kind, a, 'fail', 'no link %s - %s' % tuple(r['link']))
        l = mine[0]
        if kind == 'link_items':
            ok = (l.get('items_local') or 0) > 0 and (l.get('items_remote') or 0) > 0
            return Result(kind, a, 'pass' if ok else 'fail', '' if ok else 'items at the ends: %s / %s' % (l.get('items_local'), l.get('items_remote')))
        if kind == 'link_load':
            got = [l.get('load_local'), l.get('load_remote')]
            tol = float(r.get('tolerance', 5))
            ok = all(g is not None and abs(g - float(r['percent'])) <= tol for g in got)
            return Result(kind, a, 'pass' if ok else 'fail', '' if ok else 'load %s %%, expected %s +- %s' % (got, r['percent'], tol))
        has = l.get('severity') is not None
        ok = has == bool(r.get('present', True))
        return Result(kind, a, 'pass' if ok else 'fail', '' if ok else 'severity %s, expected %s' % (l.get('severity'), 'present' if r.get('present', True) else 'none'))
    if 'badge' in a:
        r = a['badge']
        present = r['node'] in derived()['badges']
        ok = present == r.get('present', True)
        return Result('badge', a, 'pass' if ok else 'fail',
                      '' if ok else 'badge on %s is %s, expected %s' % (r['node'], 'present' if present else 'absent',
                                                                       'present' if r.get('present', True) else 'absent'))
    if 'observation' in a:
        o = a['observation']
        remote = set(resolve_device(state, o['remote'] if isinstance(o['remote'], dict) else str(o['remote']))) if o.get('remote') else None      # the whole value is a Device key (it may contain ':'), also the explicit form
        hits = [x for x in state['observations'] if x['reporter'] == o['reporter'] and x['port'] == o['port']
                and (remote is None or x['device_id'] in remote)]
        ok = any(x['outcome'] == a['outcome'] and ('precision_lower' not in a or x['precision_lower'] == a['precision_lower'])
                 for x in hits)
        return Result('observation', a, 'pass' if ok else 'fail',
                      '' if ok else 'outcome %s (precision_lower %s) not found; observations: %s' % (
                          a['outcome'], a.get('precision_lower', 'any'), [(x['device'], x['outcome'], x['precision_lower']) for x in hits]))
    if 'link_attr' in a:
        # link_attr: {link: [a, b], attr: last_seen_port, vs_step: N, is: gt|eq} - an attribute of the link now, against
        # the same link at step N (e.g. "last_seen_port advanced since step 3", "did not advance")
        r = a['link_attr']
        prev = history.get(r['vs_step'])
        was = find_links(prev, *r['link']) if prev else []
        now = find_links(state, *r['link'])
        if not was or not now:
            return Result('link_attr', a, 'fail', 'link missing at step %s (%d) or now (%d)' % (r['vs_step'], len(was), len(now)))
        old, new = was[0]['attrs'].get(r['attr']), now[0]['attrs'].get(r['attr'])
        ok = (new is not None and old is not None and (new > old if r['is'] == 'gt' else new == old))
        return Result('link_attr', a, 'pass' if ok else 'fail',
                      '' if ok else '%s: step %s = %r, now = %r, expected %s' % (r['attr'], r['vs_step'], old, new, r['is']))
    if 'edge_id_same_as' in a:
        r = a['edge_id_same_as']
        prev = history.get(r['step'])
        if prev is None:
            return Result('edge_id_same_as', a, 'fail', 'step %s has no recorded state' % r['step'])
        was = find_links(prev, *r['link'])
        now = find_links(state, *r['link'])
        if not was or not now:
            return Result('edge_id_same_as', a, 'fail', 'link missing at step %s (%d) or now (%d)' % (r['step'], len(was), len(now)))
        ok = {l['id'] for l in was} & {l['id'] for l in now}
        return Result('edge_id_same_as', a, 'pass' if ok else 'fail',
                      '' if ok else 'edge ids differ: step %s %s, now %s' % (r['step'], sorted(l['id'] for l in was), sorted(l['id'] for l in now)))
    if 'status' in a:
        f = a['status']
        v = ingest['summary']
        for part in f['field'].split('.'):
            v = v.get(part) if isinstance(v, dict) else None
        ok = v == f['equals']
        return Result('status', a, 'pass' if ok else 'fail', '' if ok else '%s = %r, expected %r' % (f['field'], v, f['equals']))
    if 'matches_reference' in a:
        ref = json.load(open(os.path.join(HERE, a['matches_reference'])))['state']
        cur = db.comparable(state)
        diffs = []
        for key in ('devices', 'represented_by'):
            if cur[key] != ref[key]:
                diffs.append('%s: only now %s, only in reference %s' % (key, _sub(cur[key], ref[key]), _sub(ref[key], cur[key])))
        if cur['ports'] != ref['ports']:
            for d in sorted(set(cur['ports']) | set(ref['ports'])):
                if cur['ports'].get(d) != ref['ports'].get(d):
                    diffs.append('ports of %s: now %s, reference %s' % (d, cur['ports'].get(d), ref['ports'].get(d)))
        now_l = [json.dumps(l, sort_keys=True) for l in cur['links']]
        ref_l = [json.dumps(l, sort_keys=True) for l in ref['links']]
        if now_l != ref_l:
            diffs.append('links: only now %s, only in reference %s' % ([x for x in now_l if x not in ref_l], [x for x in ref_l if x not in now_l]))
        return Result('matches_reference', a, 'fail' if diffs else 'pass', '; '.join(diffs))
    if 'snapshot_unchanged' in a:
        r = a['snapshot_unchanged']
        then, now = clocks_hist.get(r['since_step']), clocks_hist.get(step)
        keys = [k for k in now if k[0] == r['host']]
        bad = [k for k in keys if then is None or then.get(k) != now[k]]
        return Result('snapshot_unchanged', a, 'fail' if bad or not keys else 'pass',
                      'changed since step %s: %s' % (r['since_step'], bad) if bad else ('no snapshots of %s' % r['host'] if not keys else ''))
    return Result('unknown', a, 'fail', 'unknown assertion form: %s' % list(a))


def _sub(a, b):
    if isinstance(a, dict):
        return {k: v for k, v in a.items() if b.get(k) != v}
    return [x for x in a if x not in b]


def create_manual_links(conn, scenario, i, log):
    """zabbix_manual_link deviations enabled by step i: a manual physical_link between two existing ports, created through
    CTopologyPrototype::linkPorts() (php/manual_link.php), the code path of the UI endpoint."""
    new = scenario.states[i].active - (scenario.states[i - 1].active if i else set())
    gone = (scenario.states[i - 1].active if i else set()) - scenario.states[i].active
    out = []
    for dev_id in sorted(gone):
        if scenario.states[i].deviations[dev_id]['type'] == 'zabbix_manual_link':
            raise LabError('disabling zabbix_manual_link %s is not supported in v1' % dev_id)
    for dev_id in sorted(new):
        d = scenario.states[i].deviations[dev_id]
        if d['type'] != 'zabbix_manual_link':
            continue
        ids = []
        for ref in d['link']:
            dev, port = ref.split(':', 1)
            nid = db.port_node_id(conn, dev, port) or db.port_node_id(conn, dev, gen.long_name(port))
            if nid is None:
                raise LabError('deviation %s: port %s does not exist in topo_nodes yet (ingest it first)' % (dev_id, ref))
            ids.append(nid)
        p = subprocess.run(['php', os.path.join(HERE, 'php', 'manual_link.php'), str(ids[0]), str(ids[1])],
                           capture_output=True, text=True, timeout=120)
        try:
            answer = json.loads(p.stdout.strip().splitlines()[-1])
        except (ValueError, IndexError):
            answer = {'error': (p.stderr or p.stdout).strip()[:300]}
        if p.returncode or 'error' in answer:
            raise LabError('deviation %s: the UI code path refused the manual link: %s' % (dev_id, answer.get('error')))
        eid = db.edge_between(conn, ids[0], ids[1])
        log('  manual link %s - %s created through CTopologyPrototype::linkPorts (edge %s)' % (d['link'][0], d['link'][1], eid))
        out.append(eid)
    return out


PUSH = os.path.abspath(os.path.join(HERE, '..', 'discovery', 'push.py'))
PUSH_TIMEOUT = 300


def push_command(dev, name, settings):
    """The push.py call for one lab device (topology-3-push-delivery.md): it walks the device itself and sends the role values
    to the trapper rules of host `name`, each with the time the walk finished (-T is push.py's own call to zabbix_sender)."""
    cmd = ['python3', PUSH, '--name', name, '--zabbix-host', name, '--mgmt-ip', dev['ip'], '--snmp-target', dev['ip'],
           '--snmp-port', str(settings.get('snmp_port', 161)), '--snmp-community', dev['community']]
    if settings.get('sender_server'):
        cmd += ['--sender-server', settings['sender_server']]
    if settings.get('sender_port'):
        cmd += ['--sender-port', str(settings['sender_port'])]
    if settings.get('sender_bin'):
        cmd += ['--sender-bin', settings['sender_bin']]
    return cmd


def run_push(name, dev, settings, log=print):
    """Run push.py for a host with transport: push. Returns (ok, detail): ok is push.py's exit status (0 = every role was
    collected and sent); a failed collection sends nothing for the role (the last snapshot stays), exactly what a failed SNMP
    poll does on the LLD side."""
    p = subprocess.run(push_command(dev, name, settings), capture_output=True, text=True, timeout=PUSH_TIMEOUT)
    detail = (p.stderr.strip() or p.stdout.strip()).splitlines()
    return p.returncode == 0, (detail[-1] if detail else 'exit code %d' % p.returncode)


def snapshot_ids(conn):
    """(host, role-key itemid) -> (clock, rows_hash), for the snapshot_unchanged assertion."""
    out = {}
    for r in db.rows(conn, 'SELECT s.itemid, s.clock, s.rows_hash, h.host FROM topo_lld_snapshot s '
                           'JOIN items i ON i.itemid=s.itemid JOIN hosts h ON h.hostid=i.hostid'):
        out[(r['host'], r['itemid'])] = (r['clock'], r['rows_hash'])
    return out


PUSH_ROLE_KEYS = ('topology.push.ports', 'topology.push.lldp')      # the roles the template collects today


def left_ids(host, kind):
    """The rule itemids whose snapshot clock a poll of this kind must advance."""
    if kind == 'push':
        return [r for k, r in host['push'].items() if k in PUSH_ROLE_KEYS]
    return host['rules'][kind]


def reset_report(conn, mon, report, reset, log=print):
    """`--reset` (lab spec 8.2) and its line in the report (8.4): the row count of each topo_* table right after it. Without `--reset` the report has no counts."""
    if not reset:
        return
    db.reset_topo(conn, mon)
    report['topo_rows_after_reset'] = db.topo_counts(conn)
    log('reset: topo_* cleared')


def run(scenario, settings, api, conn, reset=False, timeout=120, log=print, argv=None):
    if not server_running():
        raise LabError('zabbix_server is not running; the runner polls through it')
    report = {'scenario': scenario.id, 'title': scenario.title, 'steps': []}
    if argv is not None:
        report['command_line'] = list(argv)          # all arguments of the runner (lab spec 8.4)
    ctl.up(scenario, settings, log)
    manifest = json.load(open(os.path.join(ctl.DATA_DIR, scenario.id, 'manifest.json')))
    mon = sorted(manifest['devices'])
    history, clocks_hist = {}, {}
    try:
        ms = zbx.masters(api, mon)
        transport = {d: scenario.states[0].devices[d]['zabbix']['transport'] for d in mon}
        for dev in mon:
            if transport[dev] == 'push':
                missing = [k for k in PUSH_ROLE_KEYS if k not in (ms.get(dev) or {}).get('push', {})]
                if missing:
                    raise LabError('host %s has no trapper rule %s (run "provision" first, with the push template)' % (dev, ', '.join(missing)))
            elif not ms.get(dev) or not ms[dev]['core']:
                raise LabError('host %s has no topology.walk.core item (run "provision" first)' % dev)
        zbx.restore_fresh_macro(api, FRESH_FILE, log)          # what a killed earlier run left
        if scenario.roles is not None:
            zbx.set_roles(api, ms, scenario.roles, ROLES_FILE, log)
            report['roles'] = scenario.roles
        report['transport'] = scenario.transport or 'per lab.yaml'
        zbx.suspend_schedule(api, ms, SCHEDULE_FILE, log)
        report['schedule_suspended'] = True
        if scenario.freshness_seconds is not None:
            before, during = zbx.set_fresh_macro(api, scenario.freshness_seconds, FRESH_FILE, log)
            report['freshness_macro'] = {'before': before, 'during': during, 'after': None}
        if reset:
            # after the schedule is suspended and the rules are set: a poll Zabbix made on its own before that, with the old
            # rules, would otherwise leave a snapshot behind (an empty one for a rule that is now off)
            reset_report(conn, mon, report, reset, log)
            report['problems_closed'] = zbx.close_problems(api, log)
        for i, step in enumerate(scenario.steps):
            log('step %d: %s' % (i, step.get('note', '')))
            sr = {'step': i, 'note': step.get('note', ''), 'assertions': [], 'errors': []}
            report['steps'].append(sr)
            faults = step.get('faults') or {}
            for dev, f in faults.items():
                if f in (None, 'none'):
                    ctl.fault(dev, 'none', settings, log)
            ctl.step(i, log)
            for dev, f in faults.items():
                if f not in (None, 'none'):
                    ctl.fault(dev, f, settings, log)

            # what to poll: devices whose data changed (all of them at step 0), and faulted devices (to see the failure)
            polled = {}
            for dev in mon:
                masters = ['core', 'fdb'] if i == 0 else manifest['devices'][dev]['step_masters'][i]
                if faults.get(dev) not in (None, 'none'):
                    masters = ['core']
                if masters:
                    polled[dev] = masters
            want_unsupported = (step.get('wait') or {}).get('unsupported') or [d for d, f in faults.items() if f not in (None, 'none')]
            # One master item at a time, in a fixed order, each started in a later second than the previous snapshot's
            # clock: all snapshot clocks of a step are then distinct and ordered the same way in every run. Ingest counts
            # a device as updated when its attrs change, and last_seen (= snapshot clock) is one of them, so two clocks
            # landing in the same second (or not) would change `devices_updated` from run to run.
            # The standard interface discovery rule (role PORTS, model §3.2): the item ids of the ports. Once, at step 0 (a --reset clears the snapshots),
            # all rules at the same time with a wait of their own: a rule that is slow must not eat the time of the step. A rule that does not answer is
            # only reported; the scenarios do not assert item ids.
            if i == 0:
                ifdisc = {ms[dev]['ifdisc']['itemid']: dev for dev in sorted(polled) if ms[dev].get('ifdisc')}
                if ifdisc:
                    # The rule keeps "Discard unchanged with heartbeat" (FR-B D-23): an unchanged value is not stored again, so a rule that has a snapshot (--reset
                    # keeps those of the standard interface discovery) usually gives no new clock; it gets a short wait for a changed value (ports a scenario adds),
                    # a rule without a snapshot gets the long one.
                    before = db.snapshot_clocks(conn)
                    zbx.check_now(api, list(ifdisc))
                    start = time.time()
                    while time.time() < start + 45:
                        now_clocks = db.snapshot_clocks(conn)
                        if all(now_clocks.get(int(r), 0) > before.get(int(r), 0) or (int(r) in before and time.time() > start + 10) for r in ifdisc):
                            break
                        time.sleep(1)
                    missing = sorted(dev for r, dev in ifdisc.items() if int(r) not in db.snapshot_clocks(conn))
                    template = (settings.get('zabbix') or {}).get('template_interfaces')
                    if missing and template and zbx.reseed_interface_rule(api, template, log):
                        # a rule that has no snapshot and whose value is the one it stored last (a deleted row, a fresh database) is made to store the next value
                        zbx.check_now(api, [r for r, dev in ifdisc.items() if dev in missing])
                        end = time.time() + 45
                        while time.time() < end and any(int(r) not in db.snapshot_clocks(conn) for r, dev in ifdisc.items() if dev in missing):
                            time.sleep(1)
                        missing = sorted(dev for r, dev in ifdisc.items() if int(r) not in db.snapshot_clocks(conn))
                    if missing:
                        log('  note: no snapshot of the interface discovery rule yet for %s (the item ids of their ports come later)' % ', '.join(missing))
            # The wait of the step starts here: the interface rule phase above has its own waits (up to 90 s with a reseed) and must not eat it (035 section 1:
            # in a batch S5 step 0 lost Switch2 fdb and UPS1 because that phase had used most of the 119 s).
            deadline = time.time() + float((step.get('wait') or {}).get('timeout', timeout))
            pending, waited_for = {}, []
            last_clock = max(db.snapshot_clocks(conn).values(), default=0)
            for dev in sorted(polled):
                if dev in want_unsupported:
                    continue
                # A push host has one "poll": push.py walks the device and sends every role. A LLD host has a master item per
                # kind (core, fdb) that "check now" runs.
                kinds = ['push'] if transport[dev] == 'push' else sorted(polled[dev])
                for m in kinds:
                    if m != 'push' and not ms[dev].get(m):
                        continue
                    while time.time() < last_clock + 1.05:
                        time.sleep(0.1)
                    before = db.snapshot_clocks(conn)
                    if m == 'push':
                        ok, detail = run_push(dev, scenario.states[i].devices[dev], settings, log)
                        if not ok:
                            sr['errors'].append('push.py failed for %s: %s' % (dev, detail))
                            continue
                        left = {r: '%s push rule %s' % (dev, r) for r in left_ids(ms[dev], m)}
                    else:
                        zbx.check_now(api, [ms[dev][m]['itemid']])
                        left = {r: '%s %s rule %s' % (dev, m, r) for r in ms[dev]['rules'][m]}
                    waited_for += sorted(left.values())
                    while left and time.time() < deadline:
                        now = db.snapshot_clocks(conn)
                        for r in list(left):
                            if now.get(int(r), 0) > before.get(int(r), 0):
                                del left[r]
                        if left:
                            time.sleep(0.3)
                    pending.update(left)
                    done = db.snapshot_clocks(conn)
                    last_clock = max([last_clock] + [done.get(int(r), 0) for r in left_ids(ms[dev], m)])
            unsupported_seen = set()
            for dev in sorted(want_unsupported):
                if dev not in polled:
                    continue
                if transport[dev] == 'push':
                    # push.py fails the collection (nothing is sent for the role, the snapshots stay): that is "the poll failed"
                    ok, detail = run_push(dev, scenario.states[i].devices[dev], settings, log)
                    if not ok:
                        unsupported_seen.add(dev)
                        sr.setdefault('failure_seen', {})[dev] = detail
                else:
                    zbx.check_now(api, [ms[dev]['core']['itemid']])
            waited_for = sorted(waited_for)
            while time.time() < deadline and len(unsupported_seen) < len(want_unsupported):
                for dev in want_unsupported:
                    if dev in unsupported_seen or transport[dev] == 'push':
                        continue
                    hs = api.call('host.get', {'filter': {'host': [dev]}, 'output': ['hostid'],
                                               'selectInterfaces': ['error', 'available', 'errors_from', 'disable_until']})
                    it = api.call('item.get', {'itemids': ms[dev]['core']['itemid'], 'output': ['state', 'error']})[0]
                    iface = hs[0]['interfaces'][0]
                    # A timed-out SNMP poll does not make the item unsupported: Zabbix marks the interface (errors_from,
                    # disable_until) and only later flips `available`. Any of these counts as "the poll failed".
                    if it['state'] == '1' or it['error'] or iface['error'] or iface['available'] == '2' \
                            or iface['errors_from'] != '0' or iface['disable_until'] != '0':
                        unsupported_seen.add(dev)
                        sr.setdefault('failure_seen', {})[dev] = it['error'] or iface['error'] or \
                            ('interface errors_from=%s available=%s' % (iface['errors_from'], iface['available']))
                time.sleep(1)
            if pending:
                sr['errors'].append('timeout (%ss) waiting for snapshots of: %s' % (int(deadline - time.time() + timeout), sorted(pending.values())))
            for dev in want_unsupported:
                if dev not in unsupported_seen:
                    sr['errors'].append('timeout: %s did not fail the poll' % dev)
            sr['waited_for'] = waited_for

            seconds = (step.get('wait') or {}).get('seconds')
            until = bool((step.get('wait') or {}).get('until'))      # poll-until: the link assertions below are re-evaluated until they hold, `seconds` is the longest wait
            if seconds and not until:
                log('  waiting %s s' % seconds)
                time.sleep(seconds)
                sr['waited_seconds'] = seconds

            try:
                create_manual_links(conn, scenario, i, log)
            except LabError as e:
                sr['errors'].append(str(e))
            ingest = run_ingest(settings)
            state = db.natural_state(conn)
            history[i] = state
            clocks_hist[i] = snapshot_ids(conn)
            sr['ingest_status'] = ingest
            sr['snapshot_clocks'] = {'%s/%s' % k: v[0] for k, v in clocks_hist[i].items()}
            sr['links'] = [{k: l[k] for k in ('id', 'type', 'ends', 'discovered_via', 'state', 'far_port_reason')} for l in state['links']]
            sr['observations'] = state['observations']
            sr['natural_state'] = db.comparable(state)
            sr['neighbor_outcomes'] = sorted(([o['reporter'], o['port'], o['device'], o['outcome']] for o in state['observations']),
                                             key=lambda row: [str(v) for v in row])
            sr['snapshot_rows'] = db.snapshot_rows(conn)
            derived_cache = {}

            def derived():
                if 'd' not in derived_cache:
                    derived_cache['d'] = run_derived()
                return derived_cache['d']

            load_asserts = [a['link_load'] for a in step.get('assert') or [] if 'link_load' in a]
            counter_links = [a[k] for a in step.get('assert') or [] for k in ('link_load', 'link_severity') if k in a]
            load_error, load_polls = None, None

            def counter_ids():
                ends = run_derived()['links']
                ids = set()
                for r in counter_links:
                    for l in [x for x in ends if x['edge_id'] in {str(y['id']) for y in find_links(state, r['link'][0], r['link'][1], 'physical_link')}][:1]:
                        for side in (l.get('load_items') or {}).values():
                            ids.update(str(side[k]) for k in ('in', 'out') if side and side.get(k))
                return ids

            if until and counter_links:
                # poll-until (lab spec 9 S6): every round reads a fresh counter pair (which also polls the items the template triggers use) and evaluates the
                # link assertions; the step ends when all hold or after `seconds`. The result below is the evaluation after the last round.
                t_end, rounds = time.time() + seconds, 0
                link_forms = [a for a in step.get('assert') or [] if set(a) & {'link_items', 'link_load', 'link_severity'}]
                while True:
                    rounds += 1
                    round_start = time.time()
                    try:
                        read_fresh_counters(api, conn, counter_ids(), log)
                    except LabError:
                        pass                                                     # reported by the final evaluation below
                    derived_cache.clear()
                    ok = True
                    for a in link_forms:
                        try:
                            ok = ok and evaluate(a, state, ingest, history, clocks_hist, i, derived).status == 'pass'
                        except LabError:
                            ok = False
                    if ok or time.time() >= t_end:
                        break
                    time.sleep(max(0, round_start + COUNTER_POLL_GAP - time.time()))       # a round is at least 30 s: a poll every few seconds would change the 15-minute average of the trigger
                sr['waited_seconds'] = int(time.time() - (t_end - seconds))
                sr['wait_rounds'] = rounds
                log('  link assertions %s after %d round(s), %d s' % ('hold' if ok else 'do not hold', rounds, sr['waited_seconds']))
            if load_asserts and not (until and counter_links):
                # the counters of the ends of the asserted links are read from a fresh pair (lab spec 9 S6 "Load reading"), then the UI reads them
                try:
                    load_polls = read_fresh_counters(api, conn, counter_ids(), log)
                    sr['load_polls'] = load_polls
                    log('  counters read from a fresh pair after %d poll(s)' % load_polls)
                except LabError as e:
                    load_error = str(e)
                derived_cache.clear()
            for a in step.get('assert') or []:
                if load_error and 'link_load' in a:
                    res = Result('link_load', a, 'fail', load_error)
                    sr['assertions'].append(res.as_dict())
                    log('  FAIL    link_load %s %s' % (json.dumps(a, default=str)[:110], load_error))
                    continue
                try:
                    res = evaluate(a, state, ingest, history, clocks_hist, i, derived)
                except LabError as e:
                    res = Result(next(iter(a)), a, 'fail', str(e))
                sr['assertions'].append(res.as_dict())
                log('  %-7s %s' % (res.status.upper(), (res.message if res.status == 'pending' else
                                                          '%s %s %s' % (res.kind, json.dumps(a, default=str)[:110], res.message))[:300]))
            if load_asserts:
                # the values behind link_load (bit/s, speed and %: what the UI reads from the items of the two ends), in the report
                sr['load_measured'] = []
                try:
                    all_links = {l['edge_id']: l for l in derived()['links']}
                    for r in load_asserts:
                        for l in find_links(state, r['link'][0], r['link'][1], 'physical_link')[:1]:
                            d = all_links.get(str(l['id'])) or {}
                            sr['load_measured'].append({'link': r['link'], 'detail': d.get('load_detail')})
                            for side in ('local', 'remote'):
                                v = (d.get('load_detail') or {}).get(side) or {}
                                log('  load %s of %s: in %s bit/s, out %s bit/s, speed %s bit/s, %s %%' % (side, r['link'], v.get('in'), v.get('out'), v.get('speed'), v.get('percent')))
                except LabError as e:
                    sr['load_measured'].append({'error': str(e)})
            for e in sr['errors']:
                log('  ERROR   ' + e)
    finally:
        ctl.down(log)
        zbx.restore_schedule(api, SCHEDULE_FILE, log)
        zbx.restore_roles(api, ROLES_FILE, log)
        after = zbx.restore_fresh_macro(api, FRESH_FILE, log)
        if after is not None and 'freshness_macro' in report:
            report['freshness_macro']['after'] = after
    return report


def exit_code(report):
    bad = False
    for s in report['steps']:
        if s['errors']:
            bad = True
        if any(a['result'] == 'fail' for a in s['assertions']):
            bad = True
    return 1 if bad else 0


def _identity(rows):
    """The identity bytes of a snapshot: {#LOC_CHASSIS} and {#REM_CHASSIS} of every row, in order."""
    return [[r.get('loc_chassis'), r.get('rem_chassis')] for r in rows]


def compare_transports(a, b):
    """Differences between two reports of one scenario, one run per transport (topology-3-push-delivery.md "Acceptance criteria":
    Equivalence and Identity bytes). Compared by natural keys, per step: Devices, Ports, links of both types with their state,
    represented_by targets, the outcome of every observation, and the identity fields of every snapshot both runs have.
    Returns a list of strings, empty when the runs are equivalent."""
    out = []
    if a['scenario'] != b['scenario'] or len(a['steps']) != len(b['steps']):
        return ['reports are not of the same scenario and step count']
    for x, y in zip(a['steps'], b['steps']):
        at = 'step %d' % x['step']
        for key in ('devices', 'ports', 'represented_by', 'links'):
            if x['natural_state'][key] != y['natural_state'][key]:
                out.append('%s: %s differ: %s: %s, %s: %s' % (at, key, a.get('transport'), json.dumps(x['natural_state'][key], sort_keys=True),
                                                           b.get('transport'), json.dumps(y['natural_state'][key], sort_keys=True)))
        if x['neighbor_outcomes'] != y['neighbor_outcomes']:
            out.append('%s: observation outcomes differ: only in %s: %s; only in %s: %s' % (
                at, a.get('transport'), [o for o in x['neighbor_outcomes'] if o not in y['neighbor_outcomes']],
                b.get('transport'), [o for o in y['neighbor_outcomes'] if o not in x['neighbor_outcomes']]))
        for key in sorted(set(x['snapshot_rows']) | set(y['snapshot_rows'])):
            if key not in x['snapshot_rows'] or key not in y['snapshot_rows']:
                out.append('%s: snapshot %s only in %s' % (at, key, a.get('transport') if key in x['snapshot_rows'] else b.get('transport')))
            elif _identity(x['snapshot_rows'][key]) != _identity(y['snapshot_rows'][key]):
                out.append('%s: identity bytes of snapshot %s differ: %s: %s, %s: %s' % (
                    at, key, a.get('transport'), _identity(x['snapshot_rows'][key]), b.get('transport'), _identity(y['snapshot_rows'][key])))
    return out


_VOLATILE = {'failure_seen', 'id', 'edge_id', 'started_at', 'finished_at', 'run_id', 'snapshot_clocks', 'waited_for', 'last_seen', 'itemids',
             'hostid', 'itemid', 'at', 'device_id', 'end_devices', 'device_nodes', 'waited_seconds', 'wait_rounds', 'load_polls', 'node_ids', 'load_measured',
             'command_line', 'topo_rows_after_reset'}


def diff_reports(a, b, path=''):
    """Two normalized reports: (differences, new fields). A key that only one report has is a new field (lab spec 8.4), not a difference; each entry is a
    path and the report that has it ('a' or 'b'). Everything else that is not equal is a difference."""
    diffs, new = [], []
    if isinstance(a, dict) and isinstance(b, dict):
        for k in sorted(set(a) | set(b), key=str):
            if k not in b:
                new.append((path + '/' + str(k), 'a'))
            elif k not in a:
                new.append((path + '/' + str(k), 'b'))
            else:
                d, n = diff_reports(a[k], b[k], path + '/' + str(k))
                diffs += d
                new += n
    elif isinstance(a, list) and isinstance(b, list) and len(a) == len(b):
        for i, (x, y) in enumerate(zip(a, b)):
            d, n = diff_reports(x, y, '%s[%d]' % (path, i))
            diffs += d
            new += n
    elif a != b:
        diffs.append(path or '/')
    return diffs, new


def normalize_report(obj):
    """A report without clocks and ids, for comparing two runs (topology-4-lab-simulator.md "Acceptance criteria")."""
    if isinstance(obj, dict):
        return {k: normalize_report(v) for k, v in obj.items() if k not in _VOLATILE}
    if isinstance(obj, list):
        return [normalize_report(x) for x in obj]
    return obj
