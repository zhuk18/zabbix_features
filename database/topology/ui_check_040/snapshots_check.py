#!/usr/bin/env python3
"""Task 125 part 4 (model 2.104 §5.4, §11, §12.7 part 3; FR-B D-19, D-20, D-51; FR-C V-18 item 3): the Snapshots part of the last run view and `GET /topo/snapshots`.

Through the API of Zabbix (JSON-RPC, ZBX_TOKEN), `zabbix_sender` and the page only: no database read. The check makes a host SNAP-CHECK with four Zabbix trapper discovery rules of the topology type
Neighbors, in its own host group, and removes them in a `finally`. The states: `none` (a rule without a value), `fresh` (a value of now), `old` (a value dated 8 days ago: the allowed age of a trapper
rule is the freshness threshold, 7 days), `not_used` (the rule disabled), `ignored_reason = future` (a value dated 600 s ahead: the snapshot stays) and `older` (a value older than the snapshot: it stays).
The R1 data: the rules of the 14 reporters are `fresh`. The page: the table `#topology-snapshot-list`, its state links and its columns. Needs ZBX_TOKEN, ZBX_UI_USER, ZBX_UI_PASSWORD, google-chrome,
bin/zabbix_sender and the lab Zabbix. Exit code 1 when a check fails."""
import json, os, subprocess, sys, time, urllib.request

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import page_shot as P

if not os.environ.get('ZBX_UI_USER') or not os.environ.get('ZBX_UI_PASSWORD') or not os.environ.get('ZBX_TOKEN'):
    print('ZBX_UI_USER, ZBX_UI_PASSWORD and ZBX_TOKEN are needed')
    sys.exit(2)
URL = os.environ.get('ZBX_URL', 'http://localhost/zabbix/ui/api_jsonrpc.php')
SENDER = os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', '..', '..', 'bin', 'zabbix_sender')
HOST = 'SNAP-CHECK'
cookie = P.login()
failed = 0


def check(condition, text, detail=None):
    global failed
    print(('  ok: ' if condition else '  FAIL: ') + text + ('' if condition else ' ' + json.dumps(detail)))
    failed += 0 if condition else 1


def api(method, params):
    request = urllib.request.Request(URL, json.dumps({'jsonrpc': '2.0', 'method': method, 'params': params, 'id': 1}).encode(),
                                     {'Content-Type': 'application/json-rpc', 'Authorization': 'Bearer ' + os.environ['ZBX_TOKEN']})
    answer = json.load(urllib.request.urlopen(request))
    if 'error' in answer:
        raise RuntimeError('%s: %s' % (method, answer['error']))
    return answer['result']


ROW = '[{"{#IFINDEX}":"1","{#REM_SYSNAME}":"snap-check-neighbor","{#REM_CHASSIS}":"aa:bb:cc:00:00:01","{#REM_CHASSIS_TYPE}":"macAddress"}]'


def send(key, clock, value=ROW, wait=True):
    """One value with its timestamp (-T); a rule that the server does not know yet is tried again (the configuration is read every few seconds)."""
    path = '/tmp/snap_check_%d.txt' % os.getpid()
    with open(path, 'w') as f:
        f.write('%s\t%s\t%d\t%s\n' % (HOST, key, clock, value))
    try:
        for _ in range(40):
            out = subprocess.run([SENDER, '-z', '127.0.0.1', '-p', '10051', '-T', '-i', path], capture_output=True, text=True).stdout
            if 'processed: 1' in out:
                return True
            time.sleep(3)
        return False
    finally:
        os.unlink(path)


def fetch(js, query):
    return json.loads(js("fetch('zabbix.php?action=topology.snapshots.get&%s').then(r => r.text())" % query))


def mine(js, **extra):
    """The rows of the host SNAP-CHECK by rule name."""
    rows = fetch(js, 'page_size=1000&' + '&'.join('%s=%s' % kv for kv in extra.items()))['snapshots']
    return {r['rule']: r for r in rows if r['host'] == HOST}


def wait_for(js, condition, what, timeout=60):
    end = time.time() + timeout
    last = None
    while time.time() < end:
        last = mine(js)
        if condition(last):
            return last
        time.sleep(2)
    check(False, what, last)
    return last


group_id = host_id = None
chrome = None
try:
    group_id = api('hostgroup.create', {'name': 'SNAP-CHECK-GROUP'})['groupids'][0]
    host_id = api('host.create', {'host': HOST, 'groups': [{'groupid': group_id}]})['hostids'][0]
    keys = {'none': 'snap.check.none', 'fresh': 'snap.check.fresh', 'old': 'snap.check.old', 'future': 'snap.check.future', 'older': 'snap.check.older'}
    rules = {}
    for name, key in keys.items():
        rules[name] = api('discoveryrule.create', {'hostid': host_id, 'name': 'Snapshot check ' + name, 'key_': key, 'type': 2, 'topology_type': 2})['itemids'][0]
    # 2.107 (§12.7 part 3): `old` is shown for every topology type: a Ports rule and a Device rule (the other keys above are Neighbors rules)
    other_types = {'portsold': ('snap.check.portsold', 1, '[{"{#IFINDEX}":"1","{#IFNAME}":"eth0"}]'), 'deviceold': ('snap.check.deviceold', 5, '[{"{#LOC_CHASSIS}":"aa:bb:cc:00:00:09","{#LOC_CHASSIS_TYPE}":"macAddress"}]')}
    for name, (key, topology_type, value) in other_types.items():
        rules[name] = api('discoveryrule.create', {'hostid': host_id, 'name': 'Snapshot check ' + name, 'key_': key, 'type': 2, 'topology_type': topology_type})['itemids'][0]
    now = int(time.time())
    chrome, ws, js = P.open_page(cookie, 'snap', 9840, action='topology.view&view=lastrun', ready="document.readyState === 'complete' && !!document.getElementById('topology-snapshot-list')")

    print('=== R1: the rules of the reporters ===')
    everything = fetch(js, 'page_size=1000')
    r1 = [r for r in everything['snapshots'] if r['host'].startswith('SW') or r['host'].startswith('zbx-office')]
    check(len(r1) >= 14 * 4 and all(r['state'] == 'fresh' for r in r1 if r['clock'] is not None), '%d rules of the R1 hosts; each one that has a snapshot is fresh' % len(r1),
          [r for r in r1 if r['state'] not in ('fresh',)][:3])
    check(everything['page_size'] == 1000 and everything['total'] >= len(r1), 'the answer has the total and the page size', {k: everything[k] for k in ('total', 'page_size', 'page')})

    print('=== none: a rule that has no value ===')
    rows = mine(js)
    check(set(rows) == {'Snapshot check ' + n for n in list(keys) + list(other_types)} and all(r['state'] == 'none' and r['clock'] is None and r['rows_valid'] is None for r in rows.values()), 'seven rules, all "none", no clock', rows)

    print('=== fresh, old ===')
    check(send(keys['fresh'], now), 'a value of now is sent')
    check(send(keys['old'], now - 8 * 86400), 'a value dated 8 days ago is sent')
    for name, (key, topology_type, value) in other_types.items():
        check(send(key, now - 8 * 86400, value), 'a %s value dated 8 days ago is sent' % ('Ports' if topology_type == 1 else 'Device'))
    rows = wait_for(js, lambda r: r['Snapshot check fresh']['state'] == 'fresh' and r['Snapshot check old']['state'] == 'old' and r['Snapshot check portsold']['clock'] is not None and r['Snapshot check deviceold']['clock'] is not None, 'the states become fresh and old')
    check(rows['Snapshot check portsold']['state'] == 'old' and rows['Snapshot check deviceold']['state'] == 'old' and rows['Snapshot check portsold']['allowed_age'] == 604800,
          'a Ports rule and a Device rule older than their allowed age are old too (2.107: every topology type)', [rows['Snapshot check portsold'], rows['Snapshot check deviceold']])
    check(rows['Snapshot check fresh']['rows_valid'] == 1 and abs(rows['Snapshot check fresh']['clock'] - now) < 5, 'fresh: one valid row, the clock is the time of the value', rows['Snapshot check fresh'])
    check(rows['Snapshot check old']['allowed_age'] == 604800 and rows['Snapshot check old']['clock'] == now - 8 * 86400, 'old: the allowed age of a trapper rule is the freshness threshold (7 days), the clock is the time of the value', rows['Snapshot check old'])

    print('=== a value dated 600 s ahead: not used (future) ===')
    check(send(keys['future'], now) and send(keys['future'], now + 600, '[]'), 'a value of now, then one 600 s ahead')
    rows = wait_for(js, lambda r: r['Snapshot check future']['ignored_reason'] == 'future', 'ignored_reason becomes "future"')
    future = rows['Snapshot check future']
    check(future['state'] == 'fresh' and future['rows_valid'] == 1 and abs(future['clock'] - now) < 5 and future['ignored_clock'] == now + 600, 'the snapshot stays (one valid row, the old clock); the ignored time is shown', future)

    print('=== a value older than the snapshot: not used (older) ===')
    check(send(keys['older'], now) and send(keys['older'], now - 100, '[]'), 'a value of now, then one 100 s older')
    rows = wait_for(js, lambda r: r['Snapshot check older']['ignored_reason'] == 'older', 'ignored_reason becomes "older"')
    older = rows['Snapshot check older']
    check(older['rows_valid'] == 1 and abs(older['clock'] - now) < 5 and older['ignored_clock'] == now - 100, 'the snapshot stays; the ignored time is the one of the late value', older)
    check(send(keys['older'], now + 30), 'a new value is sent')
    rows = wait_for(js, lambda r: r['Snapshot check older']['ignored_reason'] is None, 'a new snapshot clears the ignored time and reason')

    print('=== not_used: the rule disabled ===')
    api('discoveryrule.update', {'itemid': rules['fresh'], 'status': 1})
    rows = mine(js)
    check(rows['Snapshot check fresh']['state'] == 'not_used', 'a disabled rule is not_used (its snapshot is kept)', rows['Snapshot check fresh'])
    api('discoveryrule.update', {'itemid': rules['fresh'], 'status': 0})
    check(mine(js)['Snapshot check fresh']['state'] == 'fresh', 'enabled again: fresh')
    api('host.update', {'hostid': host_id, 'status': 1})
    check(all(r['state'] == 'not_used' for r in mine(js).values()), 'a disabled host: every rule not_used')
    api('host.update', {'hostid': host_id, 'status': 0})

    print('=== filters, pages ===')
    only_old = fetch(js, 'state=old&page_size=1000')
    check(all(r['state'] == 'old' for r in only_old['snapshots']) and any(r['host'] == HOST for r in only_old['snapshots']), 'state=old lists only old rules, and the old rule of the check', only_old['total'])
    one_host = fetch(js, 'hostid=%s&page_size=1000' % host_id)
    check(one_host['total'] == 7 and {r['host'] for r in one_host['snapshots']} == {HOST}, 'hostid filters to the seven rules of the host', one_host['total'])
    paged = fetch(js, 'hostid=%s&page_size=2&page=4' % host_id)
    check(paged['total'] == 7 and len(paged['snapshots']) == 1 and paged['page'] == 4, 'the pages: 7 rules, 2 on a page, page 4 has one', paged)

    print('=== the page ===')
    js("location.href = 'zabbix.php?action=topology.view&view=lastrun&filter_snapshot_state=old'")
    P.until(js, "document.readyState === 'complete' && !!document.getElementById('topology-snapshot-list') && document.querySelectorAll('#topology-snapshot-list tbody tr').length > 0", 'the list of the snapshots is shown')
    table = js("""(() => { const t = document.getElementById('topology-snapshot-list'); return {head: Array.from(t.querySelectorAll('thead th')).map(e => e.textContent.trim()),
        rows: Array.from(t.querySelectorAll('tbody tr')).map(r => Array.from(r.children).map(c => c.textContent.trim())), links: Array.from(document.querySelectorAll('h4')).map(h => h.textContent.trim())}; })()""")
    check(table['head'] == ['Host', 'Rule', 'Topology type', 'State', 'Snapshot time', 'Valid rows', 'Allowed age', 'Value not used'] and 'Snapshots' in table['links'], 'the section "Snapshots" has its columns', table['head'])
    check(any(row[0] == HOST and row[1] == 'Snapshot check old' and row[3] == 'Old' for row in table['rows']) and all(row[3] == 'Old' for row in table['rows']), 'the filter "Old" shows only old rules, among them the rule of the check', table['rows'][:3])
    # the host filter of the list above (it is kept in the profile of the user: reset at the end)
    js("location.href = 'zabbix.php?action=topology.view&view=lastrun&filter_set=1&filter_hostids[]=%s'" % host_id)
    P.until(js, "document.readyState === 'complete' && location.search.includes('filter_set') && document.querySelectorAll('#topology-snapshot-list tbody tr').length === 7", 'the seven rules of the host are listed')
    text = js("document.getElementById('topology-snapshot-list').textContent")
    check('Snapshot check future' in text and 'later than the server time' in text and 'older than the snapshot' not in text, 'the value that was not used is shown with its reason (the rule `older` got a new snapshot: nothing shown for it)', text[:300])
    js("location.href = 'zabbix.php?action=topology.view&view=lastrun&filter_rst=1'")
    P.until(js, "document.readyState === 'complete' && location.search.includes('filter_rst') && !!document.getElementById('topology-snapshot-list')", 'the filter is reset')
finally:
    if chrome is not None:
        try:
            js("location.href = 'zabbix.php?action=topology.view&view=lastrun&filter_rst=1'")
            P.until(js, "document.readyState === 'complete' && location.search.includes('filter_rst')", 'the filter is reset')
        except Exception:
            pass
        chrome.close()
    for step in (lambda: api('host.delete', [host_id]) if host_id else None, lambda: api('hostgroup.delete', [group_id]) if group_id else None):
        try:
            step()
        except Exception as error:
            print('  cleanup:', error)
    leftover = [h for h in api('host.get', {'filter': {'host': [HOST]}, 'output': ['hostid']})]
    check(not leftover, 'the host of the check is removed', leftover)
print('\nAll snapshot checks passed.' if failed == 0 else '\n%d snapshot check(s) FAILED.' % failed)
sys.exit(1 if failed else 0)
