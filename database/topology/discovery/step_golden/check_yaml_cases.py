#!/usr/bin/env python3
"""The YAML cases of "SNMP walk to topology rows" (tests/libs/zbxpreproc/zbx_item_preproc.yaml) run through the standalone driver of the step (build.sh).

The C unit test framework (cmocka, libyaml) is not on every machine; this runs the same walks and parameters through topo_step and compares the result exactly
(SUCCEED and the output text, or FAIL). Usage: check_yaml_cases.py [--topo-step PATH] [--yaml PATH]   (PyYAML: the lab venv)
"""
import argparse
import os
import subprocess
import sys
import tempfile

import yaml

HERE = os.path.dirname(os.path.abspath(__file__))
ap = argparse.ArgumentParser()
ap.add_argument('--topo-step', default=os.path.join(HERE, 'topo_step'))
ap.add_argument('--yaml', default=os.path.join(HERE, '..', '..', '..', '..', 'tests', 'libs', 'zbxpreproc', 'zbx_item_preproc.yaml'))
a = ap.parse_args()

checks = failures = 0
with open(a.yaml, encoding='utf-8') as fh:
    docs = [d for d in yaml.safe_load_all(fh) if d]
for d in docs:
    step = (d.get('in') or {}).get('step') or {}
    if step.get('type') != 'ZBX_PREPROC_SNMP_WALK_TO_TOPOLOGY':
        continue
    params = str(step['params']).split('\n')
    with tempfile.NamedTemporaryFile('w', suffix='.walk', delete=False, encoding='utf-8') as tmp:
        tmp.write(d['in']['value']['data'])
    r = subprocess.run([a.topo_step, tmp.name, params[0], params[1]] + (params[2:3] if params[0] == 'fdb' else []), capture_output=True, text=True)
    os.unlink(tmp.name)
    out = d['out']
    want_ok = out['return'] == 'SUCCEED'
    got_ok = r.returncode == 0 and r.stdout.strip().startswith('[')
    ok = want_ok == got_ok and (not want_ok or r.stdout.strip() == str(out['value']).strip())
    checks += 1
    failures += 0 if ok else 1
    print(('  ok: ' if ok else '  FAIL: ') + d['test case'] + ('' if ok else '\n        got %r expected %r' % (r.stdout.strip()[:300], out.get('value'))))
print('%s: %d checks' % ('OK' if not failures else 'FAILED %d of' % failures, checks))
sys.exit(1 if failures else 0)
