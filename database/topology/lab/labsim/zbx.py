"""Zabbix API client and host provisioning (spec §7). The token comes only from the ZBX_TOKEN environment variable."""
import json
import os
import time
import urllib.request

from .model import LabError

TAG = 'lab.managed'


class Api:
    def __init__(self, url, token=None):
        self.url = url
        self.token = token or os.environ.get('ZBX_TOKEN')
        if not self.token:
            raise LabError('ZBX_TOKEN is not set (the API token is read only from the environment)')
        self._id = 0

    def call(self, method, params):
        self._id += 1
        req = urllib.request.Request(self.url, json.dumps({'jsonrpc': '2.0', 'method': method, 'params': params,
                                                          'id': self._id}).encode(),
                                     {'Content-Type': 'application/json-rpc', 'Authorization': 'Bearer ' + self.token})
        r = json.load(urllib.request.urlopen(req, timeout=60))
        if 'error' in r:
            raise LabError('API %s: %s %s' % (method, r['error'].get('message'), r['error'].get('data')))
        return r['result']


def snmp_details(host):
    return {'version': '2', 'bulk': '1', 'community': host['community'], 'max_repetitions': '10'}


def _has_tag(h):
    return any(t['tag'] == TAG and t['value'] == '1' for t in h.get('tags', []))


def provision(api, scenario, prune=False, log=print):
    lab = scenario.states[0]
    s = lab.settings
    z = s['zabbix']
    export_dir = os.path.expanduser(s.get('export_dir', '~/work/lab-backup'))
    adopt = set(s.get('adopt') or [])
    wanted = {h['host']: h for h in __import__('labsim.gen', fromlist=['x']).hosts_json(scenario)}

    groups = api.call('hostgroup.get', {'filter': {'name': [z['group']]}})
    if not groups:
        raise LabError('host group %r does not exist' % z['group'])
    groupid = groups[0]['groupid']
    tmpl = {}
    for key in ('template_lld', 'template_push'):
        r = api.call('template.get', {'filter': {'host': [z[key]]}, 'output': ['templateid', 'host']})
        tmpl[key] = r[0]['templateid'] if r else None
    proxies = {p['name']: p['proxyid'] for p in api.call('proxy.get', {'output': ['proxyid', 'name']})}

    existing = {h['host']: h for h in api.call('host.get', {
        'filter': {'host': list(wanted)}, 'output': ['hostid', 'host', 'monitored_by', 'proxyid'], 'selectTags': 'extend',
        'selectInterfaces': 'extend', 'selectParentTemplates': ['templateid']})}

    # Plan first, change after: nothing is touched before the adopted hosts are exported.
    plan = []
    for name, w in sorted(wanted.items()):
        h = existing.get(name)
        tkey = 'template_lld' if w['transport'] == 'lld' else 'template_push'
        if not tmpl[tkey]:
            raise LabError('template %r does not exist' % z[tkey])
        if h is None:
            plan.append(('create', name, w, None))
            continue
        if not _has_tag(h):
            if name not in adopt:
                raise LabError('host %s exists without the %s=1 tag and is not in settings.adopt; refusing to touch it'
                               % (name, TAG))
            plan.append(('adopt', name, w, h))
        else:
            plan.append(('update', name, w, h))

    to_export = [h['hostid'] for kind, n, w, h in plan if kind == 'adopt']
    if to_export:
        os.makedirs(export_dir, exist_ok=True)
        path = os.path.join(export_dir, 'adopted-hosts-%s.json' % time.strftime('%Y%m%d-%H%M%S'))
        data = api.call('configuration.export', {'format': 'json', 'options': {'hosts': to_export}})
        with open(path, 'w') as f:
            f.write(data)
        log('  exported %d host(s) to %s before adopting them' % (len(to_export), path))

    for kind, name, w, h in plan:
        tkey = 'template_lld' if w['transport'] == 'lld' else 'template_push'
        want_if = {'ip': w['ip'], 'port': str(w['port']), 'dns': '', 'useip': '1', 'main': '1', 'type': '2',
                   'details': snmp_details(w)}
        proxy_params = {'monitored_by': '0'} if not w['proxy'] else {'monitored_by': '1', 'proxyid': proxies[w['proxy']]}
        if w['proxy'] and w['proxy'] not in proxies:
            raise LabError('proxy %r does not exist' % w['proxy'])
        if kind == 'create':
            r = api.call('host.create', dict({'host': w['host'], 'groups': [{'groupid': groupid}],
                                              'tags': [{'tag': TAG, 'value': '1'}], 'templates': [{'templateid': tmpl[tkey]}],
                                              'interfaces': [want_if]}, **proxy_params))
            log('  created host %s (%s)' % (name, r['hostids'][0]))
            continue
        changes = {}
        if kind == 'adopt':
            changes['tags'] = h['tags'] + [{'tag': TAG, 'value': '1'}]
        linked = {t['templateid'] for t in h['parentTemplates']}
        if tmpl[tkey] not in linked:
            changes['templates'] = [{'templateid': t} for t in linked | {tmpl[tkey]}]
        if (h['monitored_by'], h.get('proxyid') or '0') != (proxy_params['monitored_by'], proxy_params.get('proxyid', '0')):
            changes.update(proxy_params)
        main = next((i for i in h['interfaces'] if i['type'] == '2' and i['main'] == '1'), None)
        if main is None:
            raise LabError('host %s has no main SNMP interface' % name)
        d = main.get('details') or {}
        if (main['ip'], main['port'], main['useip'], d.get('community'), d.get('version'), d.get('bulk')) != \
                (w['ip'], str(w['port']), '1', w['community'], '2', '1'):
            api.call('hostinterface.update', {'interfaceid': main['interfaceid'], 'ip': w['ip'], 'port': str(w['port']),
                                             'dns': '', 'useip': '1', 'details': snmp_details(w)})
            log('  updated interface of %s' % name)
        if changes:
            api.call('host.update', dict({'hostid': h['hostid']}, **changes))
            log('  %s host %s: %s' % ('adopted' if kind == 'adopt' else 'updated', name, ', '.join(sorted(changes))))
        elif kind == 'update':
            log('  host %s unchanged' % name)

    if prune:
        for h in api.call('host.get', {'tags': [{'tag': TAG, 'value': '1', 'operator': 1}], 'output': ['hostid', 'host'],
                                       'selectTags': 'extend'}):
            if h['host'] not in wanted and _has_tag(h):
                api.call('host.delete', [h['hostid']])
                log('  pruned host %s' % h['host'])


def masters(api, hostnames):
    """host -> {'core': itemid, 'fdb': itemid} (the walk[] master items), and the rule items depending on them."""
    out = {}
    hosts = api.call('host.get', {'filter': {'host': list(hostnames)}, 'output': ['hostid', 'host']})
    for h in hosts:
        items = api.call('item.get', {'hostids': h['hostid'], 'output': ['itemid', 'key_', 'state', 'error', 'status', 'lastclock']})
        rules = api.call('discoveryrule.get', {'hostids': h['hostid'], 'output': ['itemid', 'key_', 'master_itemid']})
        core = next((i for i in items if i['key_'] == 'topology.walk.core'), None)
        fdb = next((i for i in items if i['key_'] == 'topology.walk.fdb'), None)
        out[h['host']] = {'hostid': h['hostid'], 'core': core, 'fdb': fdb,
                          'rules': {'core': [i['itemid'] for i in rules if core and i['master_itemid'] == core['itemid']],
                                    'fdb': [i['itemid'] for i in rules if fdb and i['master_itemid'] == fdb['itemid']]}}
    return out


def check_now(api, itemids):
    if itemids:
        api.call('task.create', [{'type': 6, 'request': {'itemid': i}} for i in itemids])


SCHEDULE_SUSPENDED = '1d'        # the API's maximum update interval is 86400 s


def suspend_schedule(api, masters_by_host, state_file, log=print):
    """Stop Zabbix's own periodic polling of the lab hosts for the duration of a run.

    A scheduled poll that lands between two steps writes a snapshot the runner did not ask for, which changes ingest
    counters from run to run. The master items get a long interval; the original intervals are written to state_file
    first, so restore_schedule() can put them back after a failure or a killed run.
    """
    import json
    if os.path.exists(state_file):
        restore_schedule(api, state_file, log)
    items = {}
    for host, m in masters_by_host.items():
        for kind in ('core', 'fdb'):
            if m.get(kind):
                items[m[kind]['itemid']] = None
    if not items:
        return
    for it in api.call('item.get', {'itemids': list(items), 'output': ['itemid', 'delay']}):
        items[it['itemid']] = it['delay']
    os.makedirs(os.path.dirname(state_file), exist_ok=True)
    with open(state_file, 'w') as f:
        json.dump(items, f)
    for itemid in items:
        api.call('item.update', {'itemid': itemid, 'delay': SCHEDULE_SUSPENDED})
    log('  scheduled polling of %d master item(s) suspended for the run' % len(items))
    time.sleep(12)          # let the server's configuration sync pick the change up


def restore_schedule(api, state_file, log=print):
    import json
    if not os.path.exists(state_file):
        return False
    with open(state_file) as f:
        items = json.load(f)
    for itemid, delay in items.items():
        try:
            api.call('item.update', {'itemid': itemid, 'delay': delay})
        except LabError as e:           # the item may be gone (host pruned): nothing to restore
            log('  could not restore delay of item %s: %s' % (itemid, e))
    os.remove(state_file)
    log('  scheduled polling restored on %d master item(s)' % len(items))
    return True
