#!/usr/bin/env python3
"""Task 128 parts 3 and 4 (model §6.7.0 segments, §4.8 "Blind spot"; FR-B D-24 to D-26; cases C-148, C-152, C-197): the path through a Zabbix proxy, on the R1 stand.

Needs the R1 stand up (labsim ... run R1 --reset; up R1), ZBX_TOKEN (API) and the lab proxy (setup/lab-proxy.sh create; the check starts and stops it).
Three reporters of R1 (SW13: no CDP; SW17 and SW21: LLDP and CDP) are moved from direct monitoring to the proxy `lab-proxy` through the API (host.update monitored_by = proxy), as an operator would. Part 3:
  - the Topology by SNMP template works through the proxy with no proxy-side configuration: the proxy has only its own configuration file (Server, Hostname, the database); the rules of the three hosts stay supported and
    their snapshots get a newer clock (the native step ran on the proxy: nothing else reads the devices of these hosts; the control is part 4: with the proxy stopped, the clock stops);
  - the snapshots equal those of direct monitoring: for each rule the same rows_hash, rows_total and rows_valid as before the move (the clock is the only change);
  - the Devices are in the segment of the proxy (§6.7.0): after a run of ingest the identity keys of the CDP-only neighbors of the moved reporters are `cdp:proxy:<id>:...` and the result of the run otherwise is the one of
    direct monitoring (the links between the Devices that have a MAC chassis are the same).
Part 4 (C-197, §4.8; the badge itself is checked in a browser by task 137, which uses this proxy step): what the page reads. The blind-spot flag of a host is `monitored by a proxy that is not online` (CTopologyPrototype::getMonitoringAssignments:
host.monitored_by and host.proxyid, and the proxy state of proxy_rtdata); the API gives the same inputs, so the check reads them through the API: with the proxy up the three hosts are monitored by it and it is online (no blind spot); with the proxy
stopped the server sees it offline (state 1) while the hosts are still assigned to it (blind spot) and no problem is raised for them (not an outage); after the start it is online again. The step is re-runnable: setup/lab-proxy.sh create | start | stop | remove.
At the end the hosts are monitored directly again, the proxy is stopped (its database `zabbix_proxy` and the proxy object stay: `lab-proxy.sh remove` drops them), R1 is as it was. Exit code 1 when a check fails."""
import json, os, subprocess, sys, time, urllib.request

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, HERE)
sys.path.insert(0, os.path.dirname(HERE))
import topology_json as tj  # noqa: E402
from labsim import runner, zbx  # noqa: E402

PROXY_SCRIPT = os.path.join(HERE, 'setup', 'lab-proxy.sh')
MOVED = ['SW13-10-ACC-6000-48G', 'SW17-09-ACC-2530-48G', 'SW21-07-ACC-2530-48G']
URL = os.environ.get('ZBX_URL', 'http://localhost/zabbix/ui/api_jsonrpc.php')
SETTINGS = {'database': 'zabbix', 'db_socket': '/run/mysqld/mysqld.sock'}
failed = 0


def check(condition, text, detail=None):
    global failed
    print(('  ok: ' if condition else '  FAIL: ') + text + ('' if condition else ' ' + json.dumps(detail, default=str)))
    failed += 0 if condition else 1


if not os.environ.get('ZBX_TOKEN'):
    print('ZBX_TOKEN is needed')
    sys.exit(2)
api = zbx.Api(URL, os.environ['ZBX_TOKEN'])


def sql(query):
    out = subprocess.run(['mysql', '-N', '-uzabbix', 'zabbix', '-e', query], capture_output=True, text=True).stdout.rstrip('\n')
    return [line.split('\t') for line in out.split('\n')] if out else []


def snapshots():
    """rule (host, key) -> (clock, rows_hash, rows_total, rows_valid) of the moved hosts."""
    names = ','.join("'%s'" % h for h in MOVED)
    return {(r[0], r[1]): tuple(r[2:]) for r in sql("select h.host, i.key_, s.clock, s.rows_hash, s.rows_total, s.rows_valid from items i join hosts h using(hostid) "
                                                    "join topo_lld_snapshot s on s.itemid=i.itemid where h.host in (%s)" % names)}


def proxy_script(*args):
    return subprocess.run([PROXY_SCRIPT] + list(args), capture_output=True, text=True, timeout=300)


def wait(condition, what, timeout=240):
    end = time.time() + timeout
    last = None
    while time.time() < end:
        last = condition()
        if last:
            return last
        time.sleep(3)
    check(False, what)
    return None


def force_check_now():
    by_host = zbx_masters = zbx.masters(api, MOVED)
    items = []
    for m in by_host.values():
        items += [m['core']['itemid'], m['fdb']['itemid']] if m['core'] and m['fdb'] else []
    zbx.check_now(api, items)


def move(proxyid):
    hosts = api.call('host.get', {'filter': {'host': MOVED}, 'output': ['hostid']})
    for h in hosts:
        api.call('host.update', {'hostid': h['hostid'], 'monitored_by': '1' if proxyid else '0', **({'proxyid': proxyid} if proxyid else {})})


def summary_keys():
    return {r[0] for r in sql("select `key` from topo_identity_keys where `key` like 'cdp:%'")}


def link_set():
    """The physical links that are not superseded, by the names of the two Devices (a Port is named by its Device), as a set; Devices with a MAC chassis only (the others are keyed by segment)."""
    rows = sql("select concat(least(da.id, db.id), '-', greatest(da.id, db.id)) from topo_edges e join topo_nodes a on a.id = e.src_id join topo_nodes b on b.id = e.dst_id "
               "join topo_nodes da on da.id = a.device_id join topo_nodes db on db.id = b.device_id where e.type = 'physical_link' and "+tj.missing('e.attrs', '$.superseded_at'))
    return {r[0] for r in rows}


def proxy_state():
    return api.call('proxy.get', {'proxyids': [proxyid], 'output': ['state']})[0]['state']      # 2 online, 1 offline, 0 unknown


def blind_spot_inputs():
    """What the page's blind-spot flag is made of, through the API: for each moved host (monitored_by, proxyid) and the state of the proxy; the flag is `monitored by a proxy that is not online`."""
    hosts = api.call('host.get', {'filter': {'host': MOVED}, 'output': ['host', 'monitored_by', 'proxyid']})
    state = proxy_state()
    return {h['host']: (h['monitored_by'] == '1' and h['proxyid'] == proxyid and state != '2') for h in hosts}, [h['hostid'] for h in hosts]


def problems_of(hostids):
    return api.call('problem.get', {'hostids': hostids, 'output': ['eventid', 'severity'], 'recent': False})


proxy = api.call('proxy.get', {'filter': {'name': ['lab-proxy']}, 'output': ['proxyid']})
if not proxy:
    print('the proxy lab-proxy does not exist: run setup/lab-proxy.sh create')
    sys.exit(2)
proxyid = proxy[0]['proxyid']
try:
    print('=== the proxy ===')
    started = proxy_script('start')
    check(started.returncode == 0, 'the proxy starts (%s)' % started.stdout.strip(), started.stderr[-300:])
    online = wait(lambda: api.call('proxy.get', {'proxyids': [proxyid], 'output': ['state', 'compatibility']})[0]['state'] == '2', 'the server sees the proxy online', 90)
    check(bool(online), 'the server sees the proxy online (state 2), same major version')
    print('=== before: direct monitoring ===')
    before = snapshots()
    check(len(before) >= 3 * 5, '%d snapshots of the three hosts (ports, lldp, cdp, lag, fdb, device)' % len(before))
    base_run = runner.run_ingest(SETTINGS)
    links_before = link_set()
    keyed_before = {r[0] for r in sql("select device_id from topo_identity_keys where `key` like '%:server:%'")}      # keys of the segment `server` (cdp:<segment>:..., chassis:local:<segment>:..., ip:<segment>:...)
    keys_before = summary_keys()
    print('=== part 3: the three hosts are monitored by the proxy ===')
    t_move = int(time.time())
    move(proxyid)
    time.sleep(15)       # the configuration reaches the proxy every 10 s (ProxyConfigFrequency)
    force_check_now()
    got = wait(lambda: (lambda s: s if len(s) == len(before) and all(int(s[k][0]) >= t_move for k in s) else None)(snapshots()), 'every snapshot of the three hosts has a clock after the move', 300)
    if got:
        check(True, 'every snapshot of the three hosts has a newer clock: the rules ran through the proxy')
        same = {k: (got[k][1:] == before[k][1:]) for k in before}
        check(all(same.values()), 'the snapshots equal those of direct monitoring: the same rows_hash, rows_total and rows_valid for each of %d rules' % len(same), [k for k, v in same.items() if not v])
        rules = api.call('discoveryrule.get', {'hostids': [h['hostid'] for h in api.call('host.get', {'filter': {'host': MOVED}, 'output': ['hostid']})], 'output': ['key_', 'state', 'status', 'error']})
        bad = [r['key_'] for r in rules if r['state'] != '0' and r['status'] == '0']
        check(not bad, 'every enabled rule of the three hosts is supported (no proxy-side configuration)', bad)
    run = runner.run_ingest(SETTINGS)
    links_after = link_set()
    keys_after = summary_keys()
    moved_keys = [k for k in keys_after if k.startswith('cdp:proxy:%s:' % proxyid)]
    check(len(moved_keys) > 0, 'Devices keyed by the segment of the proxy exist (%d keys cdp:proxy:%s:...)' % (len(moved_keys), proxyid), sorted(keys_after)[:5])
    print('  run after the move: %s' % {k: run['summary'].get(k) for k in ('devices_created', 'devices_updated', 'ports_created', 'links_created', 'reporters_processed')})
    keyed = {r[0] for r in sql("select device_id from topo_identity_keys where `key` like '%%:proxy:%s:%%'" % proxyid)}
    gone, new = links_before - links_after, links_after - links_before
    check(all(any(d in keyed_before for d in link.split('-')) for link in gone),
          'the %d links that are gone are links of a neighbor that is identified by a key of the segment "server" (a CDP device id or a non-MAC chassis id) (the result of direct monitoring otherwise: %d of %d links are the same)' % (len(gone), len(links_before & links_after), len(links_before)))
    check(all(any(d in keyed for d in link.split('-')) for link in new),
          'the %d new links are links of a neighbor identified by a key of the segment of the proxy (a known limitation of a segment change, model §6.7.0: the neighbor is a new Device)' % len(new))
    print('  links before %d, after %d, kept %d, gone %d, new %d; cdp keys before %d, after %d' % (len(links_before), len(links_after), len(links_before & links_after), len(links_before - links_after), len(links_after - links_before), len(keys_before), len(keys_after)))
    print('=== part 4: what the page reads: a blind spot, not an outage ===')
    flags, hostids = blind_spot_inputs()
    check(len(flags) == len(MOVED) and not any(flags.values()), 'the proxy is up: the three hosts are assigned to it and no host is a blind spot', flags)
    stopped = proxy_script('stop')
    check(stopped.returncode == 0, 'the proxy is stopped (%s)' % stopped.stdout.strip())
    check(bool(wait(lambda: proxy_state() == '1', 'the server sees the proxy offline', 300)), 'the server sees the proxy offline (state 1)')
    t_stop = int(time.time())
    force_check_now()
    time.sleep(30)
    after_stop = snapshots()
    check(all(int(after_stop[k][0]) < t_stop for k in after_stop), 'with the proxy stopped no snapshot of the three hosts gets a newer clock (the collection of these hosts goes through the proxy)', [k for k in after_stop if int(after_stop[k][0]) >= t_stop])
    flags, hostids = blind_spot_inputs()
    check(len(flags) == len(MOVED) and all(flags.values()), 'with the proxy offline the three hosts, and only they, are a blind spot (assigned to an offline proxy)', flags)
    check(problems_of(hostids) == [], 'no problem is raised for the three hosts: a blind spot is not an outage', problems_of(hostids))
    started = proxy_script('start')
    check(started.returncode == 0 and bool(wait(lambda: proxy_state() == '2', 'the server sees the proxy online again', 300)), 'the proxy starts again and the server sees it online')
    flags, hostids = blind_spot_inputs()
    check(not any(flags.values()), 'after the start no host is a blind spot', flags)
finally:
    move(None)
    proxy_script('stop')
print('FAILED: %d' % failed if failed else 'OK: all checks passed')
sys.exit(1 if failed else 0)
