"""Live check of the DEVICE rule of the server (LLD spec 2.19 section 3.5): a trapper discovery rule with topology type DEVICE.
One row -> one row stored; two valid rows -> an empty snapshot and the info text; Needs a running zabbix_server built from this tree, ZBX_TOKEN in the environment and the MySQL login of the lab. Makes and deletes one host."""
import json, os, subprocess, sys, time, urllib.request
URL = os.environ.get('ZBX_URL', 'http://localhost/zabbix/ui/api_jsonrpc.php')
REPO = os.path.abspath(os.path.join(os.path.dirname(__file__), '..', '..', '..'))
TOKEN = os.environ['ZBX_TOKEN']
def call(m, p):
    req = urllib.request.Request(URL, json.dumps({'jsonrpc': '2.0', 'method': m, 'params': p, 'id': 1}).encode(),
        {'Content-Type': 'application/json-rpc', 'Authorization': 'Bearer ' + TOKEN})
    r = json.load(urllib.request.urlopen(req, timeout=60))
    if 'error' in r: raise SystemExit('%s: %s' % (m, r['error']))
    return r['result']
def sql(q):
    return subprocess.run(['mysql', '-N', '-uzabbix', 'zabbix', '-e', q], capture_output=True, text=True).stdout.strip()
fails = 0
def check(ok, what):
    global fails; fails += 0 if ok else 1; print(('  ok: ' if ok else '  FAIL: ') + what)
name = 'check113-device-rows'
for h in call('host.get', {'filter': {'host': name}, 'output': ['hostid']}): call('host.delete', [h['hostid']])
grp = call('hostgroup.get', {'output': ['groupid'], 'limit': 1})[0]['groupid']
hid = call('host.create', {'host': name, 'groups': [{'groupid': grp}]})['hostids'][0]
rid = call('discoveryrule.create', {'hostid': hid, 'name': 'dev', 'key_': 'dev.rows', 'type': 2, 'topology_type': 5,
    'lifetime_type': 2, 'enabled_lifetime_type': 2})['itemids'][0]
def send(rows):
    r = subprocess.run([os.path.join(REPO, 'bin/zabbix_sender'), '-z', '127.0.0.1', '-s', name, '-k', 'dev.rows', '-o', json.dumps(rows)], capture_output=True, text=True)
    return r.returncode
time.sleep(20)
try:
    send([{'{#LOC_CHASSIS}': 'AA:BB:CC:00:00:01', '{#LOC_SYSNAME}': 'one'}])
    time.sleep(12)
    snap = sql("select rows_valid, rows_total, rows_json from topo_lld_snapshot where itemid=%s" % rid)
    check(snap.startswith('1\t1\t'), 'one row: stored (%s)' % snap[:60])
    send([{'{#LOC_SYSNAME}': 'a'}, {'{#LOC_SYSNAME}': 'b'}])
    time.sleep(12)
    snap = sql("select rows_valid, rows_total, rows_json from topo_lld_snapshot where itemid=%s" % rid)
    check(snap.startswith('0\t2\t[]'), 'two valid rows: empty snapshot (%s)' % snap[:60])
    err = call('discoveryrule.get', {'itemids': [rid], 'output': ['error', 'state']})[0]
    check('Topology (DEVICE): 2 rows, one expected' in err['error'], 'the info text of the rule: %r' % err['error'])
    send([{'{#LOC_SYSNAME}': 'a'}, {'{#IFINDEX}': '1'}])
    time.sleep(12)
    snap = sql("select rows_valid, rows_total from topo_lld_snapshot where itemid=%s" % rid)
    check(snap == '1\t2', 'one valid and one invalid row: the valid one is stored (%s)' % snap)
finally:
    call('host.delete', [hid])
print('FAILED' if fails else 'OK'); sys.exit(1 if fails else 0)
