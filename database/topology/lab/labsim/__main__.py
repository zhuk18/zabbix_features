"""python -m labsim <command>   (run from database/topology/lab/)"""
import argparse
import json
import os
import sys

from . import bench, c1, corpus, ctl, db, gen, model, runner, show as show_mode, zbx
from .model import LabError


def main(argv=None):
    ap = argparse.ArgumentParser(prog='labsim')
    ap.add_argument('--lab', help='lab description (default: lab.yaml next to this package)')
    sub = ap.add_subparsers(dest='cmd', required=True)
    p = sub.add_parser('gen', help='generate snmpsim data for a scenario')
    p.add_argument('scenario')
    p.add_argument('--out')
    p = sub.add_parser('export-walks', help='write the walk text each device serves at a step (*.synthetic.walk)')
    p.add_argument('scenario')
    p.add_argument('step', type=int)
    p.add_argument('--out', default='walks')
    p = sub.add_parser('up'); p.add_argument('scenario', nargs='?', default='S0')
    sub.add_parser('down', help='stop the stand that is up (after a show: also put the freshness macro back)')
    sub.add_parser('status', help='the stand that is up, its scenario and step, and its processes')
    p = sub.add_parser('show', help='put a stand into Zabbix and leave it there for a person to look at (lab spec 6.4a); no assertions, no report')
    p.add_argument('scenario', nargs='?')
    p.add_argument('--step', type=int, default=None, help='run the steps 0 to n (default: step 0) and stay at step n')
    p.add_argument('--reset', action='store_true', help='clear the topo_* state first (lab spec 8.2)')
    p.add_argument('--ingest-only', action='store_true', help='run the ingest and the summary again for the show that is up')
    p.add_argument('--lab', dest='show_lab', help='the lab file: a path, or a file name of a stand (stands/*/lab-office.yaml); default: the lab file of the scenario')
    p.add_argument('--timeout', type=int, default=None, help='seconds to wait for the snapshots of one master item (default: settings.timeout, else 60)')
    p = sub.add_parser('step'); p.add_argument('n', type=int)
    p = sub.add_parser('fault'); p.add_argument('device'); p.add_argument('fault')
    p = sub.add_parser('provision'); p.add_argument('scenario'); p.add_argument('--prune', action='store_true')
    p.add_argument('--transport', choices=model.TRANSPORTS, help='every monitored host on this transport (replaces settings.transport)')
    p = sub.add_parser('run'); p.add_argument('scenario'); p.add_argument('--reset', action='store_true')
    p.add_argument('--timeout', type=int, default=None, help='seconds to wait for the snapshots of one master item after its check now; wins over settings.timeout of the lab file (default 60)'); p.add_argument('--report')
    p.add_argument('--transport', choices=model.TRANSPORTS, help='every monitored host on this transport (replaces settings.transport); provision with the same option first')
    p.add_argument('--sources', help='comma-separated sources to collect on LLD hosts (%s); the other rules are disabled for the run' % ','.join(model.RULE_SOURCES))
    p = sub.add_parser('corpus-smoke', help='scenario SC: every file of the vendor corpus as a lab device, in batches, step 0 only')
    p.add_argument('--corpus', default=corpus.CORPUS_DEFAULT); p.add_argument('--files', help='comma-separated file names (without .snmprec)')
    p.add_argument('--batch-size', type=int, default=corpus.BATCH); p.add_argument('--no-push', action='store_true', help='skip the push run of the core set')
    p.add_argument('--timeout', type=int, default=150); p.add_argument('--report', help='write the report here (default: reports/SC.json, git-ignored)')
    p.add_argument('--dry-run', action='store_true', help='only the identity precheck and the batch plan')
    p.add_argument('--stall', help='test hook: comma-separated devices that answer nothing during their batch (SIGSTOP); the retry alone runs without it')
    p.add_argument('--ifindex', action='store_true', help='add ifIndex.N = N for every row N of ifTable/ifXTable (the corpus does not record that column); default: as recorded')
    p = sub.add_parser('bench', help='the bench of the release 1 target size (lab spec 5.6, B1): gen | provision | push | run | shares | remove')
    p.add_argument('action', choices=['gen', 'provision', 'push', 'run', 'shares', 'remove'])
    p.add_argument('config', nargs='?', help='bench.yaml (stands/bench/bench.yaml)')
    p.add_argument('step', nargs='?', type=int, help='push: the step 0, 1 or 2')
    p.add_argument('--steps', default='0,1,2'); p.add_argument('--out'); p.add_argument('--name', default='B1', help='run: the report is reports/<name>.json')
    p.add_argument('--reporters', type=int); p.add_argument('--neighbor-rows', type=float, dest='neighbor_rows'); p.add_argument('--keep', action='store_true', help='run: leave the bench hosts')
    p = sub.add_parser('c1', help='C1, corpus replay through the real path, one walk at a time (lab spec 9 C1): run | baseline | compare | analyze')
    p.add_argument('action', choices=['run', 'baseline', 'compare', 'analyze'])
    p.add_argument('--files', help='run: comma-separated walk names (without .snmprec); default: every walk')
    p.add_argument('--core', action='store_true', help='C1-core: the walks of stands/corpus/core.txt (run), its own result reports/C1-core.json and its own baseline baseline-core.json (baseline, compare)')
    p.add_argument('--result', default=None); p.add_argument('--timeout', type=int, default=150)
    sub.add_parser('restore', help='restore what an interrupted run changed in Zabbix (item intervals, freshness macro)')
    p = sub.add_parser('compare-reports', help='compare two run reports, ignoring clocks and ids'); p.add_argument('a'); p.add_argument('b')
    p = sub.add_parser('compare-transports', help='compare the reports of one scenario run on lld and on push (equivalence, identity bytes)')
    p.add_argument('lld'); p.add_argument('push')
    a = ap.parse_args(argv)
    try:
        a.lab = (getattr(a, 'show_lab', None) or a.lab)
        if a.cmd in ('down', 'status', 'restore') and not a.lab:
            st = ctl.load_state()
            if st and st.get('lab') and os.path.exists(st['lab']):
                a.lab = st['lab']              # the settings of the stand that is up (its lab file is in the state)
        if a.cmd == 'show' and not a.ingest_only:
            if not a.scenario:
                raise LabError('show needs a scenario (labsim show D1 --step 3), or --ingest-only')
            # the lab file: --lab (a path, or a file name of a stand), else the lab file of the scenario (a stand's scenario names its base)
            a.lab = model.find_lab_file(a.lab) if a.lab else model.lab_file_of_scenario(a.scenario)
        elif a.lab:
            a.lab = model.find_lab_file(a.lab)
        lab = model.load_lab(a.lab)
        settings = lab.settings
        if a.cmd in ('provision', 'run', 'show') or (a.cmd == 'bench' and a.action != 'gen') or (a.cmd == 'c1' and a.action == 'run'):
            db.connect(settings).close()          # refuses unless the database is `zabbix` (topology-4-lab-simulator.md "Constraints")
        if a.cmd == 'gen':
            sc = model.load_scenario(a.scenario, lab)
            m = gen.generate(sc, a.out or os.path.join(ctl.DATA_DIR, sc.id))
            print('generated %s: %d device(s), %d step(s)' % (sc.id, len(m['devices']), m['steps']))
            with open(os.path.join(a.out or os.path.join(ctl.DATA_DIR, sc.id), 'hosts.json'), 'w') as f:
                json.dump(gen.hosts_json(sc), f, indent=1)
        elif a.cmd == 'export-walks':
            sc = model.load_scenario(a.scenario, lab)
            os.makedirs(a.out, exist_ok=True)
            for name in sc.states[a.step].monitored():
                kind = 'replay' if sc.states[a.step].devices[name].get('replay') else 'synthetic'
                path = os.path.join(a.out, '%s.%s.step%d.%s.walk' % (sc.id, name, a.step, kind))
                with open(path, 'w') as f:
                    f.write(gen.export_walk(sc.states[a.step], name))
                print(path)
        elif a.cmd == 'c1':
            a.result = a.result or (c1.CORE_RESULT if a.core else c1.RESULT)
            if a.action == 'run':
                lab_c1 = model.load_lab(c1.STAND_FILE)
                rep = c1.run(lab_c1.settings, zbx.Api(lab_c1.settings['zabbix']['url']), db.connect(lab_c1.settings), a.files.split(',') if a.files else (c1.core_list() if a.core else None), a.timeout, a.result)
                print('report: %s (%s s, %d walks, %d not ok)' % (a.result, rep['seconds'], len(rep['walks']), sum(1 for w in rep['walks'] if w['status'] != 'ok')))
            elif a.action == 'baseline':
                print('baseline: %d walks' % (c1.baseline(a.result, c1.CORE_BASELINE) if a.core else c1.baseline(a.result)))
            elif a.action == 'compare':
                for line in (c1.compare(a.result, c1.CORE_BASELINE) if a.core else c1.compare(a.result)):
                    print(line)
            else:
                print('analyzer table: %d walks' % len(c1.analyze(a.result)))
        elif a.cmd == 'bench':
            cfg = bench.load_config(a.config, reporters=a.reporters, neighbor_rows_per_reporter=a.neighbor_rows)
            if a.action == 'gen':
                net = bench.generate(cfg)
                print(json.dumps(net['counts'], indent=1))
                if a.out:
                    os.makedirs(a.out, exist_ok=True)
                    for step in (0, 2):
                        with open(os.path.join(a.out, 'rows-step%d.json' % step), 'w') as f:
                            json.dump(net['rows_at'](step), f, separators=(',', ':'))
            elif a.action == 'shares':
                print(json.dumps(bench.shares(db.connect(settings)), indent=1))
            elif a.action == 'remove':
                bench.remove(zbx.Api(settings['zabbix']['url']))
            elif a.action == 'provision':
                net = bench.generate(cfg)
                bench.provision(zbx.Api(settings['zabbix']['url']), cfg, net)
            elif a.action == 'push':
                net = bench.generate(cfg)
                bench.send(net['rows_at'](0 if (a.step or 0) < 2 else 2), int(__import__('time').time()))
            else:
                rep = bench.run(settings, zbx.Api(settings['zabbix']['url']), db.connect(settings), cfg, tuple(int(x) for x in a.steps.split(',')), remove_after=not a.keep)
                path = os.path.join(model.HERE, 'reports', '%s.json' % a.name)
                os.makedirs(os.path.dirname(path), exist_ok=True)
                with open(path, 'w') as f:
                    json.dump(rep, f, indent=1, default=str)
                print('report: %s (%s s)' % (path, rep['seconds']))
        elif a.cmd == 'corpus-smoke':
            conn = db.connect(settings)
            rep = corpus.run(settings, None if a.dry_run else zbx.Api(settings['zabbix']['url']), conn, a.corpus,
                             a.files.split(',') if a.files else None, a.batch_size, not a.no_push, a.timeout, a.dry_run, ifindex=a.ifindex, stall=tuple(a.stall.split(',')) if a.stall else ())
            path = a.report or os.path.join(model.HERE, 'reports', 'SC.json')
            os.makedirs(os.path.dirname(path), exist_ok=True)
            with open(path, 'w') as f:
                json.dump(rep, f, indent=1, default=str)
            if not a.dry_run:
                print(corpus.table(rep))
            print('report: %s (%s s, load max %s, %d error(s))' % (path, rep.get('seconds'), rep.get('load_max'), len(rep['errors'])))
            return 1 if rep['errors'] else 0
        elif a.cmd == 'restore':
            db.connect(settings).close()
            api = zbx.Api(settings['zabbix']['url'])
            schedule = zbx.restore_schedule(api, runner.SCHEDULE_FILE)
            st = ctl.load_state()
            if st and st.get('mode') == 'show' and any(ctl.alive(d['pid']) for d in st['devices'].values()):
                macro = None       # the freshness macro of a show that is up stays until `labsim down` (lab spec 6.4a)
                print('note: the freshness macro of the show of %s stays; labsim down restores it' % st['scenario'])
            else:
                macro = zbx.restore_fresh_macro(api, runner.FRESH_FILE)
            sources = zbx.restore_sources(api, runner.SOURCES_FILE)
            if not schedule and macro is None and not sources:
                print('nothing to restore')
        elif a.cmd == 'compare-transports':
            diffs = runner.compare_transports(json.load(open(a.lld)), json.load(open(a.push)))
            print('\n'.join(diffs) if diffs else 'equivalent: devices, ports, links, represented_by, observation outcomes and identity bytes')
            return 1 if diffs else 0
        elif a.cmd == 'compare-reports':
            reports = [json.load(open(f)) for f in (a.a, a.b)]
            current = None
            if any(not any(st.get('id_names') for st in r.get('steps', [])) for r in reports):
                # a report without id names (before task 041): the keys of its rules come from the current provisioning, read only
                try:
                    current = db.current_rule_keys(db.connect(settings))
                except Exception as e:                    # no database: the names stay <host>:<kind>#<rank>
                    print('note: rule keys not read (%s); old item ids are named by host, kind and rank' % e)
            x, y = (runner.normalize_report(runner.name_ids(r, current)) for r in reports)
            diffs, new = runner.diff_reports(x, y)
            for path, only in new:
                print('new field %s (only in %s)' % (path, 'the first report' if only == 'a' else 'the second report'))
            for path in diffs[:50]:
                print('differs: %s' % path)
            print('reports are equal (clocks and ids ignored%s)' % (', new fields listed' if new else '') if not diffs else 'reports DIFFER')
            return 0 if not diffs else 1
        elif a.cmd == 'up':
            ctl.up(model.load_scenario(a.scenario, lab), settings)
        elif a.cmd == 'down':
            show_mode.stop(settings, lambda: zbx.Api(settings['zabbix']['url']))
        elif a.cmd == 'status':
            st = ctl.status()
            if st is None:
                print('no stand is up')
            else:
                print('stand %s%s, scenario %s, step %s: %d device(s), %d alive' % (st['stand'], ' [show]' if st['mode'] == 'show' else '', st['scenario'], st['step'], len(st['devices']),
                                                                              sum(1 for d in st['devices'] if d['alive'])))
                for d in st['devices']:
                    print('  %-28s %-15s pid %-7s %s%s' % (d['device'], d['ip'], d['pid'], 'alive' if d['alive'] else 'DEAD', ('  fault: ' + d['fault']) if d['fault'] else ''))
        elif a.cmd == 'show':
            if a.ingest_only:
                return show_mode.ingest_only(settings, db.connect(settings))
            sc = model.load_scenario(a.scenario, lab)
            return show_mode.show(sc, a.step, settings, zbx.Api(settings['zabbix']['url']), db.connect(settings), reset=a.reset, timeout=a.timeout)
        elif a.cmd == 'step':
            ctl.step(a.n)
        elif a.cmd == 'fault':
            ctl.fault(a.device, a.fault, settings)
        elif a.cmd == 'provision':
            sc = model.load_scenario(a.scenario, lab, transport=a.transport)
            zbx.provision(zbx.Api(settings['zabbix']['url']), sc, prune=a.prune)
        elif a.cmd == 'run':
            sc = model.load_scenario(a.scenario, lab, transport=a.transport, sources=a.sources.split(',') if a.sources else None)
            if a.reset and getattr(sc, 'clean_provision', False):
                # lab 2.34 section 7 and task 119 section 5: a scenario with `clean_provision` starts from its own hosts: `--reset` removes the hosts of an earlier run (with the interface items that its step 2
                # made) and makes them again, so the scenario passes twice in a row without `labsim provision`
                zbx.provision(zbx.Api(settings['zabbix']['url']), sc, prune=False)
            conn = db.connect(settings)
            rep = runner.run(sc, settings, zbx.Api(settings['zabbix']['url']), conn, reset=a.reset, timeout=runner.effective_timeout(a.timeout, settings), argv=sys.argv)
            path = a.report or os.path.join(model.HERE, 'reports', '%s.json' % sc.id)
            os.makedirs(os.path.dirname(path), exist_ok=True)
            with open(path, 'w') as f:
                json.dump(rep, f, indent=1, default=str)
            print('report: %s' % path)
            return runner.exit_code(rep)
    except LabError as e:
        print('error: %s' % e, file=sys.stderr)
        return 2
    return 0


if __name__ == '__main__':
    sys.exit(main())
