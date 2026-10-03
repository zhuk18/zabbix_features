"""Scenario runner (topology-4-lab-simulator.md "Scenario runner"): per step switch data -> poll -> wait -> ingest -> assert -> report."""
import json
import os
import subprocess
import time

from . import ctl, db, gen, zbx
from .model import HERE, LabError

INGEST = os.path.abspath(os.path.join(HERE, '..', 'discovery', 'ingest.php'))
SCHEDULE_FILE = os.path.join(ctl.RUN_DIR, 'schedule.json')
FRESH_FILE = os.path.join(ctl.RUN_DIR, 'freshness.json')
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


def _match_end(label, ref):
    """label: 'Dev:Port' or 'Dev' (device_link end). ref: 'Dev:Port' or 'Dev' (any port of Dev)."""
    if ':' in ref:
        return label == ref
    return label == ref or label.split(':', 1)[0] == ref


def find_links(state, a, b, type_=None):
    out = []
    for l in state['links']:
        if type_ and l['type'] != type_:
            continue
        x, y = l['ends']
        if (_match_end(x, a) and _match_end(y, b)) or (_match_end(y, a) and _match_end(x, b)):
            out.append(l)
    return out


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
    if 'badge' in a:
        r = a['badge']
        present = r['node'] in derived()['badges']
        ok = present == r.get('present', True)
        return Result('badge', a, 'pass' if ok else 'fail',
                      '' if ok else 'badge on %s is %s, expected %s' % (r['node'], 'present' if present else 'absent',
                                                                       'present' if r.get('present', True) else 'absent'))
    if 'observation' in a:
        o = a['observation']
        hits = [x for x in state['observations'] if x['reporter'] == o['reporter'] and x['port'] == o['port']
                and (not o.get('remote') or x['device'] == o['remote'])]
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


def snapshot_ids(conn):
    """(host, role-key itemid) -> (clock, rows_hash), for the snapshot_unchanged assertion."""
    out = {}
    for r in db.rows(conn, 'SELECT s.itemid, s.clock, s.rows_hash, h.host FROM topo_lld_snapshot s '
                           'JOIN items i ON i.itemid=s.itemid JOIN hosts h ON h.hostid=i.hostid'):
        out[(r['host'], r['itemid'])] = (r['clock'], r['rows_hash'])
    return out


def run(scenario, settings, api, conn, reset=False, timeout=120, log=print):
    if not server_running():
        raise LabError('zabbix_server is not running; the runner polls through it')
    report = {'scenario': scenario.id, 'title': scenario.title, 'steps': []}
    if reset:
        db.reset_topo(conn)
        log('reset: topo_* cleared')
    ctl.up(scenario, settings, log)
    manifest = json.load(open(os.path.join(ctl.DATA_DIR, scenario.id, 'manifest.json')))
    mon = sorted(manifest['devices'])
    history, clocks_hist = {}, {}
    try:
        ms = zbx.masters(api, mon)
        for dev in mon:
            if not ms.get(dev) or not ms[dev]['core']:
                raise LabError('host %s has no topology.walk.core item (run "provision" first)' % dev)
        zbx.restore_fresh_macro(api, FRESH_FILE, log)          # what a killed earlier run left
        zbx.suspend_schedule(api, ms, SCHEDULE_FILE, log)
        report['schedule_suspended'] = True
        if scenario.freshness_seconds is not None:
            before, during = zbx.set_fresh_macro(api, scenario.freshness_seconds, FRESH_FILE, log)
            report['freshness_macro'] = {'before': before, 'during': during, 'after': None}
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
            deadline = time.time() + float((step.get('wait') or {}).get('timeout', timeout))
            # One master item at a time, in a fixed order, each started in a later second than the previous snapshot's
            # clock: all snapshot clocks of a step are then distinct and ordered the same way in every run. Ingest counts
            # a device as updated when its attrs change, and last_seen (= snapshot clock) is one of them, so two clocks
            # landing in the same second (or not) would change `devices_updated` from run to run.
            pending, waited_for = {}, []
            last_clock = max(db.snapshot_clocks(conn).values(), default=0)
            for dev in sorted(polled):
                if dev in want_unsupported:
                    continue
                if scenario.states[i].devices[dev]['zabbix']['transport'] != 'lld':
                    sr['errors'].append('push transport is not supported by the runner in v1 (%s)' % dev)
                    continue
                for m in sorted(polled[dev]):
                    if not ms[dev][m]:
                        continue
                    while time.time() < last_clock + 1.05:
                        time.sleep(0.1)
                    before = db.snapshot_clocks(conn)
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
                    last_clock = max([last_clock] + [done.get(int(r), 0) for r in ms[dev]['rules'][m]])
            for dev in sorted(want_unsupported):
                if dev in polled:
                    zbx.check_now(api, [ms[dev]['core']['itemid']])
            waited_for = sorted(waited_for)
            unsupported_seen = set()
            while time.time() < deadline and len(unsupported_seen) < len(want_unsupported):
                for dev in want_unsupported:
                    if dev in unsupported_seen:
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
            if seconds:
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
            derived_cache = {}

            def derived():
                if 'd' not in derived_cache:
                    derived_cache['d'] = run_derived()
                return derived_cache['d']

            for a in step.get('assert') or []:
                try:
                    res = evaluate(a, state, ingest, history, clocks_hist, i, derived)
                except LabError as e:
                    res = Result(next(iter(a)), a, 'fail', str(e))
                sr['assertions'].append(res.as_dict())
                log('  %-7s %s' % (res.status.upper(), (res.message if res.status == 'pending' else
                                                          '%s %s %s' % (res.kind, json.dumps(a, default=str)[:110], res.message))[:300]))
            for e in sr['errors']:
                log('  ERROR   ' + e)
    finally:
        ctl.down(log)
        zbx.restore_schedule(api, SCHEDULE_FILE, log)
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


_VOLATILE = {'failure_seen', 'id', 'edge_id', 'started_at', 'finished_at', 'run_id', 'snapshot_clocks', 'waited_for', 'last_seen', 'itemids',
             'hostid', 'itemid', 'at'}


def normalize_report(obj):
    """A report without clocks and ids, for comparing two runs (topology-4-lab-simulator.md "Acceptance criteria")."""
    if isinstance(obj, dict):
        return {k: normalize_report(v) for k, v in obj.items() if k not in _VOLATILE}
    if isinstance(obj, list):
        return [normalize_report(x) for x in obj]
    return obj
