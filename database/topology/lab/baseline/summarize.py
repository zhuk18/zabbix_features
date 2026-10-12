#!/usr/bin/env python3
"""Baseline helpers (handoff 022 §3). No corpus data and no secret is written.

  summarize.py lab <outdir>      one line per lab report <outdir>/lab/S*.json: scenario, steps, assertions pass/fail/pending, step errors,
                                 SHA-256 of the report without clocks and ids (labsim.runner.normalize_report) - equal on two VMs when the runs are equal
  summarize.py corpus <repo>     SHA-256 of every output file of the corpus runs (database/topology/corpus/out/**); the result files without their timing fields
  summarize.py verdicts <file>   the 'ID rating verdict' lines of a harness output, sorted
"""
import glob
import hashlib
import json
import os
import re
import sys

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, os.path.dirname(HERE))


def lab(outdir):
    from labsim.runner import normalize_report
    print('scenario\tsteps\tpass\tfail\tpending\tstep_errors\tnormalized_sha256')
    for path in sorted(glob.glob(os.path.join(outdir, 'lab', 'S*.json'))):
        rep = json.load(open(path))
        a = [x['result'] for s in rep['steps'] for x in s['assertions']]
        errs = sum(len(s['errors']) for s in rep['steps'])
        sha = hashlib.sha256(json.dumps(normalize_report(rep), sort_keys=True, default=str).encode()).hexdigest()
        print('%s\t%d\t%d\t%d\t%d\t%d\t%s' % (rep['scenario'], len(rep['steps']), a.count('pass'), a.count('fail'), a.count('pending'), errs, sha))


def strip_timing(o):
    if isinstance(o, dict):
        return {k: strip_timing(v) for k, v in o.items() if k != 'seconds'}
    if isinstance(o, list):
        return [strip_timing(x) for x in o]
    return o


def corpus(repo):
    out = os.path.join(repo, 'database', 'topology', 'corpus', 'out')
    for path in sorted(glob.glob(os.path.join(out, '**', '*'), recursive=True)):
        if not os.path.isfile(path):
            continue
        rel = os.path.relpath(path, out)
        if rel.endswith('.json'):
            data = json.dumps(strip_timing(json.load(open(path))), sort_keys=True, ensure_ascii=False).encode()
            print('%s  %s  (json without timing fields)' % (hashlib.sha256(data).hexdigest(), rel))
        else:
            print('%s  %s' % (hashlib.sha256(open(path, 'rb').read()).hexdigest(), rel))


def verdicts(path):
    rows = sorted(set(re.findall(r'^  ([A-Z]-\d+b?|[A-Z]-\d+)\s+(\S+)\s+(match|differs|out of harness)', open(path, encoding='utf-8').read(), re.M)))
    for r in rows:
        print('\t'.join(r))


if __name__ == '__main__':
    {'lab': lab, 'corpus': corpus, 'verdicts': verdicts}[sys.argv[1]](sys.argv[2])
