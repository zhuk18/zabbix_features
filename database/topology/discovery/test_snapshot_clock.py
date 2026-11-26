#!/usr/bin/env python3
"""test_snapshot_clock.py — the snapshot clock (model §5.5 rules 3 to 5 and 9; FR-B D-18 to D-21; cases C-136 to C-140), against a live server. In the harness (area `native`, with device_rows_check.py).

Needs a running zabbix_server built from this tree, ZBX_TOKEN in the environment, bin/zabbix_sender and the MySQL login of the lab (`mysql -uzabbix zabbix`; the snapshot's hash and rows are read from
`topo_lld_snapshot`, they are not in an API). Makes the host SNAPCLOCK-CHECK with one Zabbix trapper discovery rule of the topology type Ports and removes it in a `finally`; nothing else is written.

  C-136  the first value dates the snapshot by the value's timestamp, not by the processing time (a value from 1000 s ago)
  C-137  an older timestamp with other rows changes nothing; the same timestamp changes nothing; a newer timestamp with other rows replaces the rows and the clock
  C-140  identical rows with a newer timestamp change only the time: the same rows_hash, rows_json, rows_total and rows_valid, the clock is the new one (the case that was missing in report 124)
  C-138  a value more than 300 s ahead is refused (301 s, and a day): the snapshot stays, the rule stays supported, the info text says so
  C-139  a value 299 s ahead (and 200 s) is accepted
         a stored clock a day ahead (written by hand) does not block a value with a valid timestamp
Usage: ZBX_TOKEN=... test_snapshot_clock.py        Exit code 1 when a check fails.
"""
import json, os, subprocess, sys, tempfile, time, urllib.request

URL = os.environ.get('ZBX_URL', 'http://localhost/zabbix/ui/api_jsonrpc.php')
REPO = os.path.abspath(os.path.join(os.path.dirname(__file__), '..', '..', '..'))
SENDER = os.path.join(REPO, 'bin', 'zabbix_sender')
HOST, KEY = 'SNAPCLOCK-CHECK', 'snapclock.ports'
if not os.environ.get('ZBX_TOKEN'):
    print('ZBX_TOKEN is needed')
    sys.exit(2)
failures = 0


def check(ok, text):
    global failures
    print(('  ok: ' if ok else '  FAIL: ') + text)
    failures += 0 if ok else 1


def api(method, params):
    request = urllib.request.Request(URL, json.dumps({'jsonrpc': '2.0', 'method': method, 'params': params, 'id': 1}).encode(),
                                     {'Content-Type': 'application/json-rpc', 'Authorization': 'Bearer ' + os.environ['ZBX_TOKEN']})
    answer = json.load(urllib.request.urlopen(request, timeout=60))
    if 'error' in answer:
        raise SystemExit('%s: %s' % (method, answer['error']))
    return answer['result']


def rows(name):
    return json.dumps([{'{#IFINDEX}': '1', '{#IFNAME}': name, '{#IFTYPE}': '6', '{#IFADMINSTATUS}': '1', '{#IFOPERSTATUS}': '1', '{#LOC_CHASSIS}': 'aa:bb:cc:00:00:01'}], separators=(',', ':'))


def sql(query):
    return subprocess.run(['mysql', '-N', '-uzabbix', 'zabbix', '-e', query], capture_output=True, text=True).stdout.rstrip('\n')


def snapshot():
    """(clock, rows_hash, rows_total, rows_valid, rows_json) of the rule, or None."""
    out = sql('select clock, rows_hash, rows_total, rows_valid, rows_json from topo_lld_snapshot where itemid=%s' % itemid)
    return tuple(out.split('\t')) if out else None


def send(ts, value, settle=None):
    """One value with its timestamp (-T); a rule that the server does not know yet is tried again. `settle`: a function of the snapshot that says the server has processed the value (polled up to 40 s);
    without it the answer is waited for 8 s (a value that must change nothing)."""
    for _ in range(40):
        with tempfile.NamedTemporaryFile('w', suffix='.txt') as f:
            f.write('%s\t%s\t%d\t%s\n' % (HOST, KEY, ts, value))
            f.flush()
            out = subprocess.run([SENDER, '-z', '127.0.0.1', '-p', '10051', '-T', '-i', f.name], capture_output=True, text=True).stdout
        if 'failed: 0' in out:
            break
        time.sleep(3)
    else:
        return False
    end = time.time() + 40
    if settle is None:
        time.sleep(8)
        return True
    while time.time() < end:
        if settle(snapshot()):
            return True
        time.sleep(1)
    return False


def info():
    return api('discoveryrule.get', {'itemids': [itemid], 'output': ['error', 'state', 'status']})[0]


for h in api('host.get', {'filter': {'host': HOST}, 'output': ['hostid']}):
    api('host.delete', [h['hostid']])
groupid = api('hostgroup.create', {'name': 'SNAPCLOCK-CHECK-GROUP'})['groupids'][0] if not api('hostgroup.get', {'filter': {'name': ['SNAPCLOCK-CHECK-GROUP']}}) else api('hostgroup.get', {'filter': {'name': ['SNAPCLOCK-CHECK-GROUP']}})[0]['groupid']
hostid = api('host.create', {'host': HOST, 'groups': [{'groupid': groupid}]})['hostids'][0]
itemid = api('discoveryrule.create', {'hostid': hostid, 'name': 'Snapshot clock', 'key_': KEY, 'type': 2, 'topology_type': 1})['itemids'][0]
try:
    now = int(time.time())
    t_old, t_first, t_same, t_new, t_newer = now - 5000, now - 1000, now - 700, now - 500, now - 300

    print('=== C-136: the snapshot is dated by the timestamp of the value ===')
    check(send(t_first, rows('first'), lambda s: s is not None), 'the first value is accepted')
    first = snapshot()
    check(first is not None and first[0] == str(t_first), 'the snapshot clock is the timestamp of the value (1000 s ago), not the processing time')

    print('=== C-137: the clock never goes back ===')
    check(send(t_old, rows('older')) and snapshot() == first, 'an older timestamp with other rows changes nothing (rows, hash and clock)')
    check(send(t_first, rows('first')) and snapshot() == first, 'the same timestamp with the same rows: nothing changes')
    check(send(t_new, rows('newer'), lambda s: s[0] == str(t_new)), 'a newer timestamp with other rows is accepted')
    second = snapshot()
    check(second[0] == str(t_new) and second[1] != first[1] and second[4] != first[4], 'it replaces the rows and the clock')

    print('=== C-140: identical rows with a newer timestamp change only the time ===')
    check(send(t_newer, rows('newer'), lambda s: s[0] == str(t_newer)), 'the same rows with a newer timestamp are processed')
    third = snapshot()
    check(third[0] == str(t_newer), 'the clock is the new one')
    check(third[1:] == second[1:], 'the rows_hash, rows_total, rows_valid and the rows are the same as before (only the time changed)')

    print('=== C-138: more than 300 s ahead is refused ===')
    kept = snapshot()
    check(send(int(time.time()) + 301, rows('ahead301')) and snapshot() == kept, 'a value 301 s ahead: the snapshot does not change')
    time.sleep(4)
    check('in the future, ignored' in info()['error'] and info()['state'] == '0' and info()['status'] == '0', 'the rule stays supported; its info text says so (%s)' % info()['error'].strip())
    check(send(int(time.time()) + 86400, rows('ahead day')) and snapshot() == kept, 'a value one day ahead: the snapshot does not change')

    print('=== C-139: 299 s ahead is accepted ===')
    ahead = int(time.time()) + 299
    check(send(ahead, rows('ahead299'), lambda s: s[0] == str(ahead)), 'a value 299 s ahead: the snapshot is dated by it')
    check(snapshot()[1] != kept[1], 'and has its rows')
    # the stored clock (now + 299) is ahead of the next value: put it back, so that the check is about the 200 s value alone
    sql('update topo_lld_snapshot set clock=%d where itemid=%s' % (int(time.time()) - 400, itemid))
    ahead = int(time.time()) + 200
    check(send(ahead, rows('ahead200'), lambda s: s[0] == str(ahead)), 'a value 200 s ahead (inside the tolerance) is accepted')

    print('=== a stored clock a day ahead does not block a valid value ===')
    sql('update topo_lld_snapshot set clock=%d where itemid=%s' % (int(time.time()) + 86400, itemid))
    valid = int(time.time())
    check(send(valid, rows('recovered'), lambda s: abs(int(s[0]) - valid) < 30), 'a value with a valid timestamp replaces the clock that was written a day ahead')
finally:
    api('host.delete', [hostid])
    api('hostgroup.delete', [groupid])
print('\n%s' % ('All snapshot clock tests passed.' if not failures else '%d FAILED' % failures))
sys.exit(1 if failures else 0)
