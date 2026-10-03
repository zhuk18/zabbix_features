"""Zabbix API client and host provisioning (topology-4-lab-simulator.md "Zabbix provisioning"). The token comes only from the ZBX_TOKEN environment variable."""
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

    changed = False
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
            changed = True
            continue
        changes = {}
        if kind == 'adopt':
            changes['tags'] = h['tags'] + [{'tag': TAG, 'value': '1'}]
        linked = {t['templateid'] for t in h['parentTemplates']}
        # one transport per host: the other lab template is unlinked (and cleared), so a transport override moves the host over
        other = tmpl['template_push' if tkey == 'template_lld' else 'template_lld']
        keep = (linked - {other}) | {tmpl[tkey]}
        if keep != linked:
            changes['templates'] = [{'templateid': t} for t in sorted(keep)]
            if other in linked:
                changes['templates_clear'] = [{'templateid': other}]
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
            changed = True
        if changes:
            api.call('host.update', dict({'hostid': h['hostid']}, **changes))
            changed = True
            log('  %s host %s: %s' % ('adopted' if kind == 'adopt' else 'updated', name, ', '.join(sorted(changes))))
        elif kind == 'update':
            log('  host %s unchanged' % name)

    if changed:
        log('  waiting for the server to pick up the configuration')
        time.sleep(CONFIG_SYNC_WAIT)        # values sent to a trapper item before that are rejected ("item not found")
    if prune:
        for h in api.call('host.get', {'tags': [{'tag': TAG, 'value': '1', 'operator': 1}], 'output': ['hostid', 'host'],
                                       'selectTags': 'extend'}):
            if h['host'] not in wanted and _has_tag(h):
                api.call('host.delete', [h['hostid']])
                log('  pruned host %s' % h['host'])


PUSH_RULE_PREFIX = 'topology.push.'


def masters(api, hostnames):
    """host -> {'core': item, 'fdb': item} (the walk[] master items), the rule items depending on them, and for a push host
    {'push': {rule key: itemid}} (the trapper discovery rules; such a host has no master items)."""
    out = {}
    hosts = api.call('host.get', {'filter': {'host': list(hostnames)}, 'output': ['hostid', 'host']})
    for h in hosts:
        items = api.call('item.get', {'hostids': h['hostid'], 'output': ['itemid', 'key_', 'state', 'error', 'status', 'lastclock']})
        rules = api.call('discoveryrule.get', {'hostids': h['hostid'], 'output': ['itemid', 'key_', 'master_itemid', 'status']})
        core = next((i for i in items if i['key_'] == 'topology.walk.core'), None)
        fdb = next((i for i in items if i['key_'] == 'topology.walk.fdb'), None)
        # "Topology by push": the trapper discovery rules (topology.push.ports, topology.push.lldp, ...), by key
        push = {i['key_']: i['itemid'] for i in rules if i['key_'].startswith(PUSH_RULE_PREFIX)}
        info = {i['itemid']: {'key': i['key_'], 'status': i['status']} for i in rules}
        out[h['host']] = {'hostid': h['hostid'], 'core': core, 'fdb': fdb, 'push': push, 'rule_info': info,
                          'rules': {'core': [i['itemid'] for i in rules if core and i['master_itemid'] == core['itemid'] and i['status'] == '0'],
                                    'fdb': [i['itemid'] for i in rules if fdb and i['master_itemid'] == fdb['itemid'] and i['status'] == '0']}}
    return out


def set_roles(api, masters_by_host, roles, state_file, log=print):
    """Collect only `roles` (rule keys topology.<role>) on the LLD hosts for the run: the other discovery rules are disabled. The
    ids go to state_file first, so restore_roles() can enable them again after a failure or a killed run. Push hosts have only
    the rules the template defines (topology.push.*); they are not touched. Updates masters_by_host to the enabled rules."""
    if os.path.exists(state_file):
        restore_roles(api, state_file, log)
    off = []
    for m in masters_by_host.values():
        for itemid, i in m['rule_info'].items():
            role = i['key'][len('topology.'):] if i['key'].startswith('topology.') and not i['key'].startswith(PUSH_RULE_PREFIX) else None
            if role in ('ports', 'lldp', 'cdp', 'fdb', 'lag') and role not in roles and i['status'] == '0':
                off.append(itemid)
        for kind in ('core', 'fdb'):
            m['rules'][kind] = [r for r in m['rules'][kind] if r not in off]
    if not off:
        return
    os.makedirs(os.path.dirname(state_file), exist_ok=True)
    with open(state_file, 'w') as f:
        json.dump(off, f)
    for itemid in off:
        api.call('discoveryrule.update', {'itemid': itemid, 'status': 1})
    log('  %d discovery rule(s) disabled for the run (only %s collected)' % (len(off), ', '.join(roles)))


def restore_roles(api, state_file, log=print):
    if not os.path.exists(state_file):
        return False
    with open(state_file) as f:
        off = json.load(f)
    for itemid in off:
        try:
            api.call('discoveryrule.update', {'itemid': itemid, 'status': 0})
        except LabError as e:
            log('  could not enable discovery rule %s: %s' % (itemid, e))
    os.remove(state_file)
    log('  %d discovery rule(s) enabled again' % len(off))
    return True


def check_now(api, itemids):
    if itemids:
        api.call('task.create', [{'type': 6, 'request': {'itemid': i}} for i in itemids])


CONFIG_SYNC_WAIT = 12            # the server's CacheUpdateFrequency is 10 s
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
    time.sleep(CONFIG_SYNC_WAIT)          # let the server's configuration sync pick the change up


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


FRESH_MACRO = '{$TOPOLOGY.LINK.FRESH.SECONDS}'       # topology-1-model.md "Freshness threshold"; one definition: database/topology/topology_freshness.inc.php


def get_fresh_macro(api):
    """The global macro as it is now: {'globalmacroid', 'value'}, or None when it does not exist."""
    r = api.call('usermacro.get', {'globalmacro': True, 'filter': {'macro': FRESH_MACRO}, 'output': ['globalmacroid', 'value']})
    return r[0] if r else None


def set_fresh_macro(api, seconds, state_file, log=print):
    """Set the freshness macro for a run (topology-4-lab-simulator.md "Freshness threshold override").

    The original state ({'was': value or None}) is written to state_file first, so restore_fresh_macro() can put it back
    after a failure or a killed run. Returns (before, during): the macro text before the change ('absent' if it did not
    exist) and as read back after it.
    """
    if os.path.exists(state_file):
        restore_fresh_macro(api, state_file, log)
    current = get_fresh_macro(api)
    before = current['value'] if current else None
    os.makedirs(os.path.dirname(state_file), exist_ok=True)
    with open(state_file, 'w') as f:
        json.dump({'macro': FRESH_MACRO, 'was': before}, f)
    if current:
        api.call('usermacro.updateglobal', {'globalmacroid': current['globalmacroid'], 'value': str(seconds)})
    else:
        api.call('usermacro.createglobal', {'macro': FRESH_MACRO, 'value': str(seconds)})
    during = get_fresh_macro(api)
    log('  freshness macro set to %s (was %s)' % (seconds, before if before is not None else 'absent'))
    return before if before is not None else 'absent', during['value'] if during else 'absent'


def restore_fresh_macro(api, state_file, log=print):
    """Put the freshness macro back: the old value, or deletion if it did not exist. Returns the state read back
    ('absent' or the text), or None when there was nothing to restore."""
    if not os.path.exists(state_file):
        return None
    with open(state_file) as f:
        saved = json.load(f)
    current = get_fresh_macro(api)
    if saved['was'] is None:
        if current:
            api.call('usermacro.deleteglobal', [current['globalmacroid']])
    elif current:
        api.call('usermacro.updateglobal', {'globalmacroid': current['globalmacroid'], 'value': saved['was']})
    else:
        api.call('usermacro.createglobal', {'macro': FRESH_MACRO, 'value': saved['was']})
    os.remove(state_file)
    after = get_fresh_macro(api)
    log('  freshness macro restored (%s)' % (saved['was'] if saved['was'] is not None else 'absent'))
    return after['value'] if after else 'absent'
