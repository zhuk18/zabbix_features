#!/usr/bin/env python3
"""task 102 §0 (model §11 `topology.ports.get`, 2.87): the time of `topology.ports.get` for a Device: N calls (default 10) of the real endpoint with the login of the frontend; prints the median and the
largest in ms and the size of the answer. Needs ZBX_UI_USER, ZBX_UI_PASSWORD and the office stand data.
    python3 ports_timing.py [--calls 10] [--device-like 'SW12%'] [--device-like 'FortiAP%']
"""
import argparse, json, os, statistics, subprocess, sys, time, urllib.request
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import page_shot as P

ap = argparse.ArgumentParser()
ap.add_argument('--calls', type=int, default=10)
ap.add_argument('--device-like', action='append', default=None)
a = ap.parse_args()
if not os.environ.get('ZBX_UI_USER') or not os.environ.get('ZBX_UI_PASSWORD'):
    print('ZBX_UI_USER and ZBX_UI_PASSWORD are needed')
    sys.exit(2)
cookie = P.login()
for like in a.device_like or ['SW12%', 'FortiAP%']:
    row = subprocess.run(['mysql', '-N', '-uzabbix', 'zabbix', '-e', "select id, json_unquote(json_extract(attrs, '$.sysname')) from topo_nodes where type = 'device' and json_unquote(json_extract(attrs, '$.sysname')) like '%s' order by id limit 1" % like.replace("'", '')],
                         capture_output=True, text=True).stdout.strip().split('\t')
    if len(row) < 2:
        print('no Device like %s' % like)
        continue
    times = []
    size = 0
    for _ in range(a.calls):
        request = urllib.request.Request(P.BASE + 'zabbix.php?action=topology.ports.get&id=%s' % row[0], headers={'Cookie': '%s=%s' % (cookie.name, cookie.value)})
        start = time.perf_counter()
        body = urllib.request.urlopen(request).read()
        times.append((time.perf_counter() - start) * 1000)
        size = len(body)
    print('%s (device %s): %d calls, median %.0f ms, largest %.0f ms, smallest %.0f ms, answer %d bytes' % (row[1], row[0], len(times), statistics.median(times), max(times), min(times), size))
