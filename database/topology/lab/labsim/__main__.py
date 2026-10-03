"""python -m labsim <command>   (run from database/topology/lab/)"""
import argparse
import json
import os
import sys

from . import ctl, db, gen, model, runner, zbx
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
    sub.add_parser('down')
    sub.add_parser('status')
    p = sub.add_parser('step'); p.add_argument('n', type=int)
    p = sub.add_parser('fault'); p.add_argument('device'); p.add_argument('fault')
    p = sub.add_parser('provision'); p.add_argument('scenario'); p.add_argument('--prune', action='store_true')
    p.add_argument('--transport', choices=model.TRANSPORTS, help='every monitored host on this transport (replaces settings.transport)')
    p = sub.add_parser('run'); p.add_argument('scenario'); p.add_argument('--reset', action='store_true')
    p.add_argument('--timeout', type=int, default=120); p.add_argument('--report')
    p.add_argument('--transport', choices=model.TRANSPORTS, help='every monitored host on this transport (replaces settings.transport); provision with the same option first')
    p.add_argument('--roles', help='comma-separated roles to collect on LLD hosts (%s); the other rules are disabled for the run' % ','.join(model.RULE_ROLES))
    sub.add_parser('restore', help='restore what an interrupted run changed in Zabbix (item intervals, freshness macro)')
    p = sub.add_parser('compare-reports', help='compare two run reports, ignoring clocks and ids'); p.add_argument('a'); p.add_argument('b')
    p = sub.add_parser('compare-transports', help='compare the reports of one scenario run on lld and on push (equivalence, identity bytes)')
    p.add_argument('lld'); p.add_argument('push')
    a = ap.parse_args(argv)
    try:
        lab = model.load_lab(a.lab)
        settings = lab.settings
        if a.cmd in ('provision', 'run'):
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
                path = os.path.join(a.out, '%s.%s.step%d.synthetic.walk' % (sc.id, name, a.step))
                with open(path, 'w') as f:
                    f.write(gen.export_walk(sc.states[a.step], name))
                print(path)
        elif a.cmd == 'restore':
            db.connect(settings).close()
            api = zbx.Api(settings['zabbix']['url'])
            schedule = zbx.restore_schedule(api, runner.SCHEDULE_FILE)
            macro = zbx.restore_fresh_macro(api, runner.FRESH_FILE)
            roles = zbx.restore_roles(api, runner.ROLES_FILE)
            if not schedule and macro is None and not roles:
                print('nothing to restore')
        elif a.cmd == 'compare-transports':
            diffs = runner.compare_transports(json.load(open(a.lld)), json.load(open(a.push)))
            print('\n'.join(diffs) if diffs else 'equivalent: devices, ports, links, represented_by, observation outcomes and identity bytes')
            return 1 if diffs else 0
        elif a.cmd == 'compare-reports':
            x, y = (runner.normalize_report(json.load(open(f))) for f in (a.a, a.b))
            print('reports are equal (clocks and ids ignored)' if x == y else 'reports DIFFER')
            return 0 if x == y else 1
        elif a.cmd == 'up':
            ctl.up(model.load_scenario(a.scenario, lab), settings)
        elif a.cmd == 'down':
            ctl.down()
        elif a.cmd == 'status':
            print(json.dumps(ctl.status(), indent=1))
        elif a.cmd == 'step':
            ctl.step(a.n)
        elif a.cmd == 'fault':
            ctl.fault(a.device, a.fault, settings)
        elif a.cmd == 'provision':
            sc = model.load_scenario(a.scenario, lab, transport=a.transport)
            zbx.provision(zbx.Api(settings['zabbix']['url']), sc, prune=a.prune)
        elif a.cmd == 'run':
            sc = model.load_scenario(a.scenario, lab, transport=a.transport, roles=a.roles.split(',') if a.roles else None)
            conn = db.connect(settings)
            rep = runner.run(sc, settings, zbx.Api(settings['zabbix']['url']), conn, reset=a.reset, timeout=a.timeout)
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
