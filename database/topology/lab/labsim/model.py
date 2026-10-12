"""Lab description, scenarios and per-step state (topology-4-lab-simulator.md "Lab description")."""
import copy
import os

import yaml

HERE = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
DEVICE_TYPES = ('physical', 'lag')
MIBS = ('lldp', 'cdp', 'fdb', 'lag')
DEVIATION_TYPES = ('extra_lldp_neighbor', 'one_sided_cable', 'override_oid', 'omit_mib', 'ifindex_renumber',
                   'zabbix_manual_link')
FAULTS = ('unreachable', 'slow', 'wrong_community', 'lldp_error')


class LabError(Exception):
    """Invalid lab or scenario input (topology-4-lab-simulator.md "Checks"): the generator stops and writes nothing."""


def split_port(ref):
    if ':' not in ref:
        raise LabError('bad port reference %r (want Device:Port)' % ref)
    dev, port = ref.split(':', 1)
    return dev, port


def norm_cable(a, b):
    return tuple(sorted((a, b)))


class Lab:
    def __init__(self, data):
        self.settings = dict(data.get('settings', {}))
        if data.get('fdb_extra'):
            self.settings['fdb_extra'] = data['fdb_extra']     # topology-4-lab-simulator.md "Extra MACs": top-level key in lab.yaml
        self.devices = {}
        for name, d in (data.get('devices') or {}).items():
            self.devices[name] = self._device(name, d)
        self.add_corpus_devices(data.get('corpus_devices'))
        self.cables = [norm_cable(*c) for c in (data.get('cables') or [])]
        self.deviations = {}
        for d in data.get('deviations') or []:
            self.add_deviation(d)
        self.active = set()          # enabled deviation ids
        self.faults = {}             # device -> fault name

    # -- construction ------------------------------------------------------------------------------------------------
    def _device(self, name, d):
        d = copy.deepcopy(d or {})
        d.setdefault('sysname', name)
        d.setdefault('community', self.settings.get('community', 'public'))
        d.setdefault('lldp', True)
        d.setdefault('cdp', False)
        d.setdefault('fdb', 'none')
        d.setdefault('lldp_local_port_subtype', None)
        d.setdefault('lldp_loc_port_num', 'ifindex')
        d.setdefault('ports', [])
        z = d.setdefault('zabbix', {})
        z.setdefault('monitored', False)
        z.setdefault('transport', 'lld')
        z.setdefault('proxy', None)
        if 'chassis' not in d:
            raise LabError('device %s has no chassis' % name)
        if z['monitored'] and not d.get('ip'):
            raise LabError('monitored device %s has no ip' % name)
        return d

    def add_corpus_devices(self, section):
        """`corpus_devices:` (lab.yaml or a scenario patch): name -> {snmprec, ip, transport, proxy, ifindex}. `ifindex: true` adds ifIndex.N = N for every row N of ifTable/ifXTable (the corpus has no such column; IF-MIB defines its value as N). A device whose walk is a file of the vendor corpus,
        served as it is (plus the end record): no ports, no cables, no deviations, no changes in a step."""
        if section is None:
            return
        if not isinstance(section, dict):
            raise LabError('corpus_devices must be a mapping name -> {snmprec, ip, transport, proxy}')
        for name, c in section.items():
            c = c or {}
            bad = set(c) - {'snmprec', 'ip', 'transport', 'proxy', 'ifindex'}
            if bad:
                raise LabError('corpus device %s: unknown field %s (allowed: snmprec, ip, transport, proxy, ifindex)' % (name, sorted(bad)))
            if not isinstance(c.get('ifindex', False), bool):
                raise LabError('corpus device %s: ifindex must be true or false' % name)
            if name in self.devices:
                raise LabError('corpus device %s has the name of another device' % name)
            if not c.get('snmprec') or not c.get('ip'):
                raise LabError('corpus device %s needs snmprec and ip' % name)
            if c.get('transport', 'lld') not in TRANSPORTS:
                raise LabError('corpus device %s: transport must be one of %s' % (name, list(TRANSPORTS)))
            d = self._device(name, {'snmprec': c['snmprec'], 'ip': c['ip'], 'chassis': None, 'corpus_ifindex': bool(c.get('ifindex', False)),
                                    'zabbix': {'monitored': True, 'transport': c.get('transport', 'lld'), 'proxy': c.get('proxy')}})
            d['lldp'] = d['cdp'] = False                 # the file decides what the device answers
            self.devices[name] = d

    def add_deviation(self, d):
        if not d.get('id'):
            raise LabError('deviation without id: %r' % (d,))
        if not d.get('reason'):
            raise LabError('deviation %s has no reason' % d['id'])
        if d.get('type') not in DEVIATION_TYPES:
            raise LabError('deviation %s: unknown type %r' % (d['id'], d.get('type')))
        self.deviations[d['id']] = d

    def apply_patch(self, patch):
        """Scenario-level patch applied before step 0 (devices, ports, cables, deviations)."""
        self.settings.update((patch or {}).get('settings') or {})      # e.g. emit_subtypes, emit_mgmt_addr for this scenario only
        for name, d in ((patch or {}).get('devices') or {}).items():
            if name in self.devices:
                dev = self.devices[name]
                for p in d.get('add_ports') or []:
                    dev['ports'].append(p)
                if 'zabbix' in d:
                    dev['zabbix'].update(d['zabbix'])
                for k, v in d.items():
                    if k not in ('add_ports', 'zabbix'):
                        dev[k] = v
            else:
                self.devices[name] = self._device(name, d)
        self.add_corpus_devices((patch or {}).get('corpus_devices'))
        self.apply_cables(((patch or {}).get('cables') or {}))
        for d in (patch or {}).get('deviations') or []:
            self.add_deviation(d)

    def apply_cables(self, change):
        for c in change.get('remove') or []:
            nc = norm_cable(*c)
            if nc not in self.cables:
                raise LabError('cannot remove cable %s - %s: not present' % nc)
            self.cables.remove(nc)
        for c in change.get('add') or []:
            nc = norm_cable(*c)
            if nc in self.cables:
                raise LabError('cable %s - %s is already present' % nc)
            self.cables.append(nc)

    def apply_step(self, step):
        # `devices:` changes attributes of devices from this step on (lldp_local_port_subtype, lldp_loc_port_num, cdp, lldp, ...);
        # ports, zabbix and the identity of a device are not changed this way
        for name, change in (step.get('devices') or {}).items():
            if name not in self.devices:
                raise LabError('step changes unknown device %r' % name)
            if self.devices[name].get('snmprec'):
                raise LabError('step changes corpus device %s: a corpus device does not change' % name)
            bad = set(change) & {'ports', 'zabbix', 'chassis', 'ip', 'sysname'}
            if bad:
                raise LabError('step changes %s of device %s: only attributes of its LLDP/CDP/FDB behavior can change in a step' % (sorted(bad), name))
            self.devices[name].update(change)
        self.apply_cables(step.get('cables') or {})
        dv = step.get('deviations') or {}
        for i in dv.get('enable') or []:
            if i not in self.deviations:
                raise LabError('step enables unknown deviation %r' % i)
            self.active.add(i)
        for i in dv.get('disable') or []:
            self.active.discard(i)
        for dev, fault in (step.get('faults') or {}).items():
            if dev not in self.devices:
                raise LabError('fault on unknown device %r' % dev)
            if fault in (None, 'none'):
                self.faults.pop(dev, None)
            else:
                if fault not in FAULTS:
                    raise LabError('unknown fault %r' % fault)
                self.faults[dev] = fault

    def copy(self):
        return copy.deepcopy(self)

    # -- queries -----------------------------------------------------------------------------------------------------
    def port(self, ref):
        dev, name = split_port(ref)
        if dev not in self.devices:
            raise LabError('unknown device %r in %r' % (dev, ref))
        for p in self.devices[dev]['ports']:
            if p['name'] == name:
                return self.devices[dev], p
        raise LabError('device %s has no port %s' % (dev, name))

    def active_deviations(self, type_=None):
        return [d for i, d in self.deviations.items() if i in self.active and (type_ is None or d['type'] == type_)]

    def monitored(self):
        return {n: d for n, d in self.devices.items() if d['zabbix']['monitored']}

    def validate(self):
        """topology-4-lab-simulator.md "Checks": reject invalid inputs, writing nothing."""
        for name, d in self.devices.items():
            seen_idx, seen_name = set(), set()
            for p in d['ports']:
                if p['ifindex'] in seen_idx or p['name'] in seen_name:
                    raise LabError('device %s: duplicate port %s/%s' % (name, p['ifindex'], p['name']))
                seen_idx.add(p['ifindex'])
                seen_name.add(p['name'])
                if p.get('type', 'physical') not in DEVICE_TYPES:
                    raise LabError('device %s port %s: bad type' % (name, p['name']))
                for m in p.get('members') or []:
                    if m not in seen_name and m not in [q['name'] for q in d['ports']]:
                        raise LabError('device %s: LAG %s member %s does not exist' % (name, p['name'], m))
            if d['fdb'] not in ('qbridge', 'bridge', 'none'):
                raise LabError('device %s: bad fdb %r' % (name, d['fdb']))
        used = {}
        for a, b in self.cables:
            for end in (a, b):
                self.port(end)
                used.setdefault(end, []).append((a, b))
        allowed = {d['port'] for d in self.active_deviations('extra_lldp_neighbor')}
        for end, cs in used.items():
            if len(cs) > 1 and end not in allowed:
                raise LabError('port %s is used by %d cables and no deviation allows it' % (end, len(cs)))
        for d in self.deviations.values():
            for key in ('port',):
                if key in d:
                    self.port(d[key])
            for key in ('link', 'cable'):
                for ref in d.get(key) or []:
                    self.port(ref)
            for ref in d.get('neighbors') or []:
                self.port(ref)
            for key in ('device',):
                if key in d and d[key] not in self.devices:
                    raise LabError('deviation %s refers to unknown device %r' % (d['id'], d[key]))
            if 'writes' in d:
                self.port(d['writes'])


WAIT_KEYS = {'unsupported', 'timeout', 'seconds'}


def validate_wait(scenario_id, i, wait):
    """`wait` has two forms that can be combined: {unsupported, timeout} and {seconds: N} (topology-4-lab-simulator.md "Scenario format")."""
    if wait is None:
        return
    if not isinstance(wait, dict) or set(wait) - WAIT_KEYS:
        raise LabError('scenario %s step %d: wait takes only %s, got %r' % (scenario_id, i, sorted(WAIT_KEYS), wait))
    s = wait.get('seconds')
    if 'seconds' in wait and (isinstance(s, bool) or not isinstance(s, (int, float)) or s < 0):
        raise LabError('scenario %s step %d: wait.seconds must be a number >= 0, got %r' % (scenario_id, i, s))


# Assertion forms (topology-4-lab-simulator.md "Assertions"): the form key -> (other keys on the assertion itself,
# keys of the form's own mapping, or None when its value is a plain scalar). snapshot_unchanged is an extension.
ASSERTION_FORMS = {
    'link': ({'state', 'stale', 'type', 'discovered_via', 'far_port_reason'}, None),
    'observation': ({'outcome', 'precision_lower'}, {'reporter', 'port', 'remote'}),
    'edge_id_same_as': (set(), {'step', 'link'}),
    'link_attr': (set(), {'link', 'attr', 'vs_step', 'is'}),
    'snapshot_unchanged': (set(), {'host', 'since_step'}),
    'status': (set(), {'field', 'equals'}),
    'observation_count': (set(), {'outcome', 'edge', 'equals'}),
    'contradiction': (set(), {'link', 'hidden_neighbors'}),
    'badge': (set(), {'node', 'present'}),
    'matches_reference': (set(), None),
    'pending': (set(), None),
}


def validate_assertion(scenario_id, i, a):
    """Reject an unknown or non-string key, so a YAML 1.1 surprise (`on:` read as True) is an error here, not a crash later."""
    where = 'scenario %s step %d: assertion %r' % (scenario_id, i, a)
    if not isinstance(a, dict):
        raise LabError('%s must be a mapping' % where)
    bad = [k for k in a if not isinstance(k, str)]
    if bad:
        raise LabError('%s has a key that is not a string: %r (quote it?)' % (where, bad[0]))
    forms = [k for k in a if k in ASSERTION_FORMS]
    if len(forms) != 1:
        raise LabError('%s must have exactly one of %s, has %s' % (where, sorted(ASSERTION_FORMS), forms or 'none'))
    extra, inner = ASSERTION_FORMS[forms[0]]
    unknown = [k for k in a if k != forms[0] and k not in extra]
    if unknown:
        raise LabError('%s: unknown key %r for %s (allowed: %s)' % (where, unknown[0], forms[0], sorted(extra) or 'none'))
    if inner is not None:
        body = a[forms[0]]
        if not isinstance(body, dict):
            raise LabError('%s: %s must be a mapping' % (where, forms[0]))
        bad = [k for k in body if not isinstance(k, str)]
        if bad:
            raise LabError('%s: %s has a key that is not a string: %r (quote it?)' % (where, forms[0], bad[0]))
        unknown = [k for k in body if k not in inner]
        if unknown:
            raise LabError('%s: unknown key %r in %s (allowed: %s)' % (where, unknown[0], forms[0], sorted(inner)))


def load_yaml(path):
    with open(path) as f:
        return yaml.safe_load(f)


def load_lab(path=None):
    return Lab(load_yaml(path or os.path.join(HERE, 'lab.yaml')))


TRANSPORTS = ('lld', 'push')
RULE_ROLES = ('ports', 'lldp', 'cdp', 'fdb', 'lag')       # the discovery rules of "Topology by SNMP": topology.<role>


class Scenario:
    def __init__(self, data, base_lab, transport=None, roles=None):
        self.id = data['id']
        self.title = data.get('title', '')
        self.steps = data['steps']
        self.freshness_seconds = (data.get('settings') or {}).get('freshness_seconds')
        if self.freshness_seconds is not None and (not isinstance(self.freshness_seconds, int) or isinstance(self.freshness_seconds, bool)
                                                   or self.freshness_seconds < 60):
            raise LabError('scenario %s: settings.freshness_seconds must be a whole number of at least 60 (or null), got %r'
                           % (self.id, self.freshness_seconds))
        for i, step in enumerate(self.steps):
            validate_wait(self.id, i, step.get('wait'))
            for a in step.get('assert') or []:
                validate_assertion(self.id, i, a)
        settings = data.get('settings') or {}
        # Scenario-level overrides (the CLI options --transport and --roles replace the settings): every monitored device on one
        # transport, and only some roles collected. The equivalence runs of topology-3-push-delivery.md "Acceptance criteria" use them.
        self.transport = transport or settings.get('transport')
        if self.transport is not None and self.transport not in TRANSPORTS:
            raise LabError('scenario %s: settings.transport must be one of %s or null, got %r' % (self.id, list(TRANSPORTS), self.transport))
        self.roles = roles if roles is not None else settings.get('roles')
        if self.roles is not None and (not isinstance(self.roles, list) or set(self.roles) - set(RULE_ROLES)):
            raise LabError('scenario %s: settings.roles must be a list of %s or null, got %r' % (self.id, list(RULE_ROLES), self.roles))
        self.base = base_lab.copy()
        self.base.apply_patch(data.get('patch'))
        if self.transport:
            for d in self.base.monitored().values():
                d['zabbix']['transport'] = self.transport
        self.states = []             # Lab state per step (index = step number)
        lab = self.base.copy()
        for i, step in enumerate(self.steps):
            if step.get('id', i) != i:
                raise LabError('scenario %s: step ids must be 0..n in order (got %r at %d)' % (self.id, step.get('id'), i))
            lab = lab.copy()
            lab.apply_step(step)
            self.states.append(lab)
        for lab in self.states:
            lab.validate()


def load_scenario(sid, lab=None, directory=None, transport=None, roles=None):
    directory = directory or os.path.join(HERE, 'scenarios')
    path = os.path.join(directory, '%s.yaml' % sid)
    if not os.path.exists(path):
        raise LabError('no scenario %s (%s)' % (sid, path))
    return Scenario(load_yaml(path), lab or load_lab(), transport, roles)
