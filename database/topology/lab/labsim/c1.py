"""C1, corpus replay (topology-4-lab-simulator.md §9 C1; task 127): every walk of the vendor corpus alone through the real path (snmpsim -> Zabbix with the native step -> snapshot -> run). One walk is
one replay device on a lab address, provisioned alone with the templates of the lab, polled once, run once, read, and its host (tag lab.stand=corpus) removed. The results are read from the API (the
state and the rows of every rule) and the run summary; the identity keys of the reporter's Device are read from the tables (the API does not give them). Nothing of the corpus is copied here.

    python -m labsim c1 run [--files a,b] [--out reports/C1.json]     every walk (or the files), one at a time, keeps going after a failed walk
    python -m labsim c1 baseline [--result reports/C1.json]           write stands/corpus/baseline.json from a result
    python -m labsim c1 compare [--result reports/C1.json]            only the differences against the baseline (nothing printed: no difference)
    python -m labsim c1 analyze                                       corpus_tool.py analyze and the per-walk table against the run (reports/C1-analyzer.json)
"""
import json
import os
import subprocess
import sys
import time

from . import corpus, model
from .model import HERE, LabError

STAND_FILE = os.path.join(HERE, 'stands', 'corpus', 'corpus.yaml')
BASELINE = os.path.join(HERE, 'stands', 'corpus', 'baseline.json')
RESULT = os.path.join(HERE, 'reports', 'C1.json')
CORE_LIST = os.path.join(HERE, 'stands', 'corpus', 'core.txt')
CORE_BASELINE = os.path.join(HERE, 'stands', 'corpus', 'baseline-core.json')
CORE_RESULT = os.path.join(HERE, 'reports', 'C1-core.json')
CORE_EXTRA = ('slxos_slx9150', 'aos7', 'ciena-sds', 'vrp_5720', 'ciena-waveserver', 'eltex-mes23xx_mes2324fb')      # lab spec 9 C1 `C1-core`
ANALYZER = os.path.join(corpus.CORPUS_DEFAULT, 'tools', 'corpus_tool.py')


def family(name):
    """The OS family of a walk: the file name up to the first underscore (corpus README)."""
    return name.split('_')[0]


def key_kind(key):
    """`chassis:mac:...` -> chassis:mac; `chassis:local:<segment>:...` -> chassis:local; `cdp:<segment>:...` -> cdp."""
    parts = key.split(':')
    return ':'.join(parts[:2]) if parts[0] == 'chassis' else parts[0]


def core_walks(walks):
    """`C1-core` (lab spec 2.42 §9 C1): for each OS family, the walk with the most neighbor rows (LLDP plus CDP; a family with no neighbor row has none; the first name on a tie), plus
    CORE_EXTRA. `walks` is the `walks` of a baseline (name -> record with `rules`). Sorted. stands/corpus/core.txt is this list; test_c1.py checks that it is."""
    best = {}
    for name, rec in walks.items():
        rows = sum((rec['rules'].get(k) or {}).get('rows_total') or 0 for k in ('topology.lldp', 'topology.cdp'))
        fam = family(name)
        if rows > 0 and (fam not in best or rows > best[fam][0] or (rows == best[fam][0] and name < best[fam][1])):
            best[fam] = (rows, name)
    return sorted({n for _, n in best.values()} | set(CORE_EXTRA))


def core_list(path=CORE_LIST):
    with open(path) as f:
        return [l.strip() for l in f if l.strip() and not l.startswith('#')]


def one(path, settings, api, conn, timeout, log):
    """The record of one walk. A walk that cannot be replayed or crashes the run is a record with `status` `failed` and its reason, never an exception."""
    name = corpus.host_name(path)
    rec = {'file': name, 'family': family(name)}
    t0 = time.time()
    try:
        lab = model.load_lab(STAND_FILE)
        sc = corpus.batch_scenario([path], lab=lab, sid='C1', ifindex=True)      # the corpus does not record ifIndex (task 127): ifIndex.N = N is added, listed in the stand README
        res = corpus.run_batch(sc, settings, api, conn, timeout, log)
    except Exception as exc:        # the walk could not be replayed (no anchor, a line that does not parse, a failed provision): recorded, not skipped
        rec.update({'status': 'failed', 'error': '%s: %s' % (type(exc).__name__, str(exc)[:300])})
        corpus.delete_hosts(api, [name], log)
        rec['seconds'] = round(time.time() - t0, 1)
        return rec
    dev = res['devices'].get(name) or {}
    rs = res.get('run_summary') or {}
    rules = dev.get('rules') or {}
    lldp = next((r for k, r in rules.items() if k.endswith('lldp')), None)
    cdp = next((r for k, r in rules.items() if k.endswith('cdp')), None)
    unresolved = rs.get('rows_local_port_unresolved') or {}
    rec.update({
        'status': 'ok' if not res['errors'] else 'errors', 'errors': res['errors'],
        'anchors': dev.get('anchors'),
        'rules': {k: {f: r.get(f) for f in ('state', 'error', 'rows_total', 'rows_valid', 'rows_identity_invalid')} for k, r in sorted(rules.items())},
        'lldp_rows': (lldp or {}).get('rows_total'), 'lldp_valid': (lldp or {}).get('rows_valid'), 'cdp_rows': (cdp or {}).get('rows_total'),
        'own_identity': sorted({key_kind(k) for k in res.get('own_keys') or []}),
        'observations': {k: v for k, v in (rs.get('observations') or {}).items() if v},
        'unresolved': unresolved.get('count', 0), 'unresolved_by_reason': {k: v for k, v in (unresolved.get('by_reason') or {}).items() if v},
        'no_identity': (rs.get('rows_no_identity') or {}).get('count', 0), 'identity_invalid': sum((rs.get('rows_identity_invalid') or {}).values()) if isinstance(rs.get('rows_identity_invalid'), dict) else 0,
        'duplicate_claim': (rs.get('rows_duplicate_claim') or {}).get('count', 0),
        'chassis_invalid': sum((rs.get('rows_chassis_invalid') or {}).values()) if isinstance(rs.get('rows_chassis_invalid'), dict) else 0,      # 2.112 (F20): kept rows with a macAddress chassis id that is not a MAC
        'devices_created': rs.get('devices_created'), 'ports_created': rs.get('ports_created'), 'links_created': rs.get('links_created'),
        'template_oid_missing': [x['rule'] for x in res.get('template_oid_missing', [])],
        'server_log': res.get('server_log'), 'seconds': res.get('seconds')})
    rows = (rec['lldp_rows'] or 0) + (rec['cdp_rows'] or 0)
    # the share of the neighbor rows that gave a Device: the Devices made, less the reporter's own, over the neighbor rows
    rec['device_share'] = round(max(0, (rec['devices_created'] or 0) - 1) / rows, 3) if rows else None
    return rec


def run(settings, api, conn, files=None, timeout=150, out=RESULT, log=print):
    paths = corpus.corpus_files(corpus.CORPUS_DEFAULT)
    if files:
        paths = [p for p in paths if corpus.host_name(p) in files]
    t0 = time.time()
    walks = []
    for i, path in enumerate(paths, 1):
        log('[%d/%d] %s' % (i, len(paths), corpus.host_name(path)))
        walks.append(one(path, settings, api, conn, timeout, lambda *a: None))
        log('   %s %s s' % (walks[-1]['status'], walks[-1].get('seconds')))
        os.makedirs(os.path.dirname(out), exist_ok=True)
        with open(out, 'w') as f:
            json.dump({'walks': walks, 'complete': False}, f, indent=1)
    report = {'walks': walks, 'complete': True, 'seconds': round(time.time() - t0, 1), 'started': int(t0)}
    with open(out, 'w') as f:
        json.dump(report, f, indent=1)
    return report


COMPARE_FIELDS = ('status', 'rules', 'own_identity', 'observations', 'unresolved', 'unresolved_by_reason', 'no_identity', 'identity_invalid', 'chassis_invalid', 'duplicate_claim', 'devices_created', 'ports_created', 'links_created')


def stable(rec):
    out = {f: rec.get(f) for f in COMPARE_FIELDS}
    out['rules'] = {k: {f: v.get(f) for f in ('state', 'rows_total', 'rows_valid', 'rows_identity_invalid')} for k, v in (rec.get('rules') or {}).items()}
    if not out.get('chassis_invalid'):
        out.pop('chassis_invalid', None)      # only when not zero: a baseline written before 2.112 has no such field
    return out


def baseline(result_path=RESULT, path=BASELINE):
    with open(result_path) as f:
        rep = json.load(f)
    if not rep.get('complete'):
        raise LabError('the result %s is not complete' % result_path)
    data = {'walks': {w['file']: stable(w) for w in rep['walks']}, 'seconds': rep.get('seconds'), 'note': 'task 127; the result of every walk without clocks, ids and times; see stands/corpus/README.md'}
    if path == CORE_BASELINE:
        data['note'] = 'task 135; C1-core (stands/corpus/core.txt): the result of its walks without clocks, ids and times; see stands/corpus/README.md'
    with open(path, 'w') as f:
        json.dump(data, f, indent=1, sort_keys=True)
    return len(data['walks'])


def compare(result_path=RESULT, path=BASELINE):
    """The differences against the baseline, as lines: nothing when there is none."""
    with open(path) as f:
        base = json.load(f)['walks']
    with open(result_path) as f:
        rep = json.load(f)
    now = {w['file']: stable(w) for w in rep['walks']}
    lines = []
    for name in sorted(set(base) | set(now)):
        if name not in now:
            lines.append('%s: not in the result' % name)
        elif name not in base:
            lines.append('%s: not in the baseline' % name)
        else:
            for field in COMPARE_FIELDS:
                if base[name].get(field) != now[name].get(field):
                    lines.append('%s: %s: baseline %s, now %s' % (name, field, json.dumps(base[name].get(field), sort_keys=True), json.dumps(now[name].get(field), sort_keys=True)))
    return lines


def analyze(result_path=RESULT, out=os.path.join(HERE, 'reports', 'C1-analyzer.json')):
    """The cross-check: corpus_tool.py analyze (a separate implementation) on the same walks, per walk: the rows of the analyzer by the way it resolves the local port, and the rows of the run."""
    tmp = out + '.raw'
    subprocess.run([sys.executable, ANALYZER, 'analyze', os.path.join(corpus.CORPUS_DEFAULT, 'librenms'), tmp], check=True, capture_output=True)
    with open(tmp) as f:
        an = {r['file'][:-len('.snmprec')]: r for r in json.load(f)}
    os.unlink(tmp)
    with open(result_path) as f:
        rep = json.load(f)
    table = []
    for w in rep['walks']:
        a = an.get(w['file']) or {}
        rows = a.get('rem_rows') or []
        resolved = [r for r in rows if str(r.get('local_resolve', '')).startswith('ok')]
        table.append({'file': w['file'], 'analyzer_rows': len(rows), 'analyzer_resolved': len(resolved),
                      'analyzer_unresolved': {k: sum(1 for r in rows if r.get('local_resolve') == k) for k in sorted({r.get('local_resolve') for r in rows if not str(r.get('local_resolve', '')).startswith('ok')})},
                      'analyzer_cdp_rows': len(a.get('cdp_rows') or []) if isinstance(a.get('cdp_rows'), list) else a.get('cdp_rows'),
                      'c1_status': w['status'], 'c1_lldp_rows': w.get('lldp_rows'), 'c1_lldp_valid': w.get('lldp_valid'), 'c1_cdp_rows': w.get('cdp_rows'),
                      'c1_unresolved': w.get('unresolved'), 'c1_unresolved_by_reason': w.get('unresolved_by_reason'), 'c1_no_identity': w.get('no_identity'),
                      'c1_observations': w.get('observations')})
    with open(out, 'w') as f:
        json.dump(table, f, indent=1)
    return table
