#!/usr/bin/env python3
"""Task 128 part 1 (model §5.2, FR-B D-9 to D-14; cases C-117, C-120 to C-126): the row contract on the server, table-driven, and the same table on the frontend side.

The table is discovery/row_contract_cases.json (a row of macros for each case, the topology type, whether the server keeps it). For each case the check makes one Zabbix trapper discovery rule of the type of
the case on the host ROWCON-CHECK (its own host group), sends the row with `zabbix_sender -T`, and reads the result only through the interfaces of Zabbix, no database: `rows_valid` of the snapshot
(GET zabbix.php?action=topology.snapshots.get), and the state and the info text of the rule (`discoveryrule.get`: the rule stays supported, the skipped rows are counted: "Topology (PORTS): 1 of 1 rows
skipped: missing {#IFINDEX} (1)"). The same table goes through CTopologyType::checkRows() (the check of the Test dialog, the only place of the frontend that validates rows; lab/php/row_contract_frontend.php): the same
row must be valid or invalid on both sides (FR-B D-14). A difference is a finding: the cases that differ say so in the table (`frontend`, `reason`), the check pins them (a difference that is not in the table fails;
so does a pinned difference that is gone), and it prints them. The two contract tables (src/zabbix_server/lld/lld_topology.c and CTopologyType.php) are compared from the sources; the pinned difference is KNOWN_TABLE_DIFFERENCES.
Needs ZBX_TOKEN, ZBX_UI_USER, ZBX_UI_PASSWORD, bin/zabbix_sender, php and the lab Zabbix. Removes its host and group in a `finally`. Exit code 1 when a check fails."""
import json, os, subprocess, sys, time, urllib.request

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import page_shot as P

if not os.environ.get('ZBX_UI_USER') or not os.environ.get('ZBX_UI_PASSWORD') or not os.environ.get('ZBX_TOKEN'):
    print('ZBX_UI_USER, ZBX_UI_PASSWORD and ZBX_TOKEN are needed')
    sys.exit(2)
HERE = os.path.dirname(os.path.abspath(__file__))
URL = os.environ.get('ZBX_URL', 'http://localhost/zabbix/ui/api_jsonrpc.php')
SENDER = os.path.join(HERE, '..', '..', '..', 'bin', 'zabbix_sender')
HOST = 'ROWCON-CHECK'
KNOWN_TABLE_DIFFERENCES = []      # task 138: the four differences that task 128 found are fixed in CTopologyType (the server was right); the two sides agree
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


def snapshots(cookie, hostid):
    request = urllib.request.Request(P.BASE + 'zabbix.php?action=topology.snapshots.get&page_size=1000&hostid=' + hostid, headers={'Cookie': 'zbx_session=' + cookie.value})
    return {r['rule']: r for r in json.load(urllib.request.urlopen(request))['snapshots']}


def send(lines, wait=120):
    """The values with their timestamps (-T); a rule that the server does not know yet is tried again (the configuration is read every few seconds)."""
    path = '/tmp/rowcon_check_%d.txt' % os.getpid()
    end = time.time() + wait
    try:
        while True:
            with open(path, 'w') as f:
                f.writelines(lines)
            out = subprocess.run([SENDER, '-z', '127.0.0.1', '-p', '10051', '-T', '-i', path], capture_output=True, text=True).stdout
            if 'failed: 0' in out:
                return True
            if time.time() > end:
                return False
            time.sleep(3)
    finally:
        if os.path.exists(path):
            os.unlink(path)


table = json.load(open(os.path.join(HERE, '..', 'discovery', 'row_contract_cases.json')))['cases']
frontend = json.loads(subprocess.run(['php', os.path.join(HERE, '..', 'lab', 'php', 'row_contract_frontend.php')], capture_output=True, text=True, check=True).stdout)
cookie = P.login()
group_id = host_id = None
try:
    group_id = api('hostgroup.create', {'name': 'ROWCON-CHECK-GROUP'})['groupids'][0]
    host_id = api('host.create', {'host': HOST, 'groups': [{'groupid': group_id}]})['hostids'][0]
    rules = {}
    for case in table:
        rules[case['name']] = api('discoveryrule.create', {'hostid': host_id, 'name': 'Row contract ' + case['name'], 'key_': 'rowcon.' + case['name'], 'type': 2, 'topology_type': case['type']})['itemids'][0]
    now = int(time.time())
    print('=== the server: %d cases, one trapper rule each ===' % len(table))
    check(send(['%s\trowcon.%s\t%d\t%s\n' % (HOST, c['name'], now, json.dumps(c['rows'], separators=(',', ':'))) for c in table]), 'the values are sent')
    seen = {}
    end = time.time() + 120
    while time.time() < end:
        seen = snapshots(cookie, host_id)
        if len(seen) == len(table) and all(r['clock'] is not None for r in seen.values()):
            break
        time.sleep(2)
    check(len(seen) == len(table) and all(r['clock'] is not None for r in seen.values()), 'the server wrote a snapshot for every rule (%d of %d)' % (sum(1 for r in seen.values() if r['clock'] is not None), len(table)))
    infos = {r['itemid']: r for r in api('discoveryrule.get', {'hostids': [host_id], 'output': ['itemid', 'state', 'error', 'status']})}
    for case in table:
        snap = seen.get('Row contract ' + case['name'], {})
        info = infos[rules[case['name']]]
        check(snap.get('rows_valid') == case['valid_rows'], '%s: %d of %d rows valid on the server (%s)' % (case['name'], case['valid_rows'], case['rows_total'], case['why']), snap.get('rows_valid'))
        check(info['state'] == '0' and info['status'] == '0', '%s: the rule stays supported' % case['name'], info)
        if 'info' in case:
            check(case['info'] in info['error'], '%s: the info text says "%s"' % (case['name'], case['info']), info['error'])
        else:
            check('rows skipped' not in info['error'] and 'expected' not in info['error'], '%s: no skipped rows in the info text' % case['name'], info['error'])

    print('=== the frontend: the same table through CTopologyType::checkRows() ===')
    differences = []
    for case in table:
        passed = frontend['cases'][case['name']]['passed']
        server = case['valid_rows']
        expected = case.get('frontend', server)      # a case that still differs says so in the table (`frontend`, `reason`); none does since task 138
        check(passed == expected, '%s: the frontend passes %d row(s)%s' % (case['name'], expected, '' if passed == server else ' (the server keeps %d)' % server), passed)
        if passed != server:
            differences.append('%s: server keeps %d, the Test dialog passes %d (%s)' % (case['name'], server, passed, case.get('reason', '?')))
        else:
            check('reason' not in case, '%s: no difference is pinned for a case that agrees' % case['name'], case.get('reason'))
    check(not differences, 'no difference between the server and the Test dialog: the same row is valid or invalid on both sides (FR-B D-14)', differences)
    print('  FINDINGS (the two sides differ; pinned in the table): ' + ('none' if not differences else ''))
    for line in differences:
        print('    ' + line)

    print('=== the two contract tables from the sources ===')
    check(frontend['tables'] == KNOWN_TABLE_DIFFERENCES, 'the server table (lld_topology.c) and CTopologyType name the same macros, required and "one of" groups, (and the macros that must be numbers)', frontend['tables'])
finally:
    if host_id is not None:
        api('host.delete', [host_id])
    if group_id is not None:
        api('hostgroup.delete', [group_id])
print('FAILED: %d' % failed if failed else 'OK: all checks passed')
sys.exit(1 if failed else 0)
