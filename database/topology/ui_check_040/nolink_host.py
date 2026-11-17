#!/usr/bin/env python3
"""task 091 §3 (model §12.1, FR-C V-1): a Host without a Device and without a drawn link, in a selected host group, is not drawn; it is drawn with the switch "Show devices without links",
and as the focus host. Makes a throwaway host in a throwaway host group through the API (ZBX_TOKEN), gives it a Host node, runs three page checks (page_shot.py --eval) and removes both.
Needs ZBX_TOKEN, ZBX_UI_USER, ZBX_UI_PASSWORD and google-chrome. Exit code 1 when a check fails, 2 without the environment."""
import json, os, subprocess, sys, urllib.request
HERE = os.path.dirname(os.path.abspath(__file__))
BASE = os.environ.get('ZBX_UI_URL', 'http://localhost/zabbix/ui/')
TOKEN = os.environ.get('ZBX_TOKEN')
if not TOKEN or not os.environ.get('ZBX_UI_USER') or not os.environ.get('ZBX_UI_PASSWORD'):
    print('ZBX_TOKEN, ZBX_UI_USER and ZBX_UI_PASSWORD are needed')
    sys.exit(2)
GROUP, HOST = 'zz-091-nolink-group', 'zz-091-nolink-host'


def api(method, params):
    request = urllib.request.Request(BASE + 'api_jsonrpc.php', json.dumps({'jsonrpc': '2.0', 'method': method, 'params': params, 'id': 1}).encode(),
                                     {'Content-Type': 'application/json-rpc', 'Authorization': 'Bearer ' + TOKEN})
    answer = json.load(urllib.request.urlopen(request))
    if 'error' in answer:
        raise RuntimeError('%s: %s' % (method, answer['error']))
    return answer['result']


def sql(statement):
    return subprocess.run(['mysql', '-N', '-uzabbix', 'zabbix', '-e', statement], capture_output=True, text=True).stdout.strip()


def drawn(*extra):
    count = '(Array.from(document.querySelectorAll("#topology-canvas g.topology-node text.topology-label")).filter(t => t.textContent === "%s").length)' % HOST
    out = subprocess.run([sys.executable, HERE + '/page_shot.py', '--width', '1500', '--height', '900', '--menu', 'open', '--settle', '2', '--eval', count, '--out', '/tmp/nolink.png', *extra],
                         capture_output=True, text=True, timeout=120).stdout
    line = next((l for l in out.split('\n') if l.startswith('eval:')), None)
    if not line:
        print('page_shot output:', out[-300:])
    return int(line.split(':', 1)[1]) if line else None


failed = 0


def check(condition, text, detail=None):
    global failed
    print(('  ok: ' if condition else '  FAIL: ') + text + ('' if condition else ' ' + json.dumps(detail)))
    failed += 0 if condition else 1


# a group and a host in it. The host has NO Host node and no Device, as a host with only the official interface templates (FR-B D-49): the view makes its Host node when it reads the scope (task 111).
# (Until task 110 this test inserted the Host node itself, so it could not see that such a host was never drawn.)
for old in api('host.get', {'filter': {'host': [HOST]}, 'output': ['hostid']}):
    api('host.delete', [old['hostid']])
for old in api('hostgroup.get', {'filter': {'name': [GROUP]}, 'output': ['groupid']}):
    api('hostgroup.delete', [old['groupid']])
group = api('hostgroup.create', {'name': GROUP})['groupids'][0]
host = api('host.create', {'host': HOST, 'groups': [{'groupid': group}]})['hostids'][0]
try:
    check(sql('select count(*) from topo_nodes where type=\'host\' and host_ref=%d' % int(host)) == '0', 'set-up: the host has no Host node and no Device')
    by_default = drawn('--group', GROUP, '--depth', '1')
    check(by_default == 0, 'a Host without a Device and without a link, in the selected host group: not drawn', by_default)
    with_switch = drawn('--group', GROUP, '--depth', '1', '--unlinked')
    check(with_switch == 1, 'the same with the switch "Show devices without links": drawn', with_switch)
    as_focus = drawn('--host', HOST, '--depth', '1')
    check(as_focus == 1, 'as the focus host: drawn', as_focus)
finally:
    api('host.delete', [host])
    api('hostgroup.delete', [group])
print('\n%s' % ('%d check(s) FAILED' % failed if failed else 'All checks passed.'))
sys.exit(1 if failed else 0)
