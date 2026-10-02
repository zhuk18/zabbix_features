"""Lab description, scenarios and per-step state (spec §4)."""
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
    """Invalid lab or scenario input (spec §5.4): the generator stops and writes nothing."""


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
            self.settings['fdb_extra'] = data['fdb_extra']     # spec §4.3: top-level key in lab.yaml
        self.devices = {}
        for name, d in (data.get('devices') or {}).items():
            self.devices[name] = self._device(name, d)
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
        """§5.4: reject invalid inputs, writing nothing."""
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


def load_yaml(path):
    with open(path) as f:
        return yaml.safe_load(f)


def load_lab(path=None):
    return Lab(load_yaml(path or os.path.join(HERE, 'lab.yaml')))


class Scenario:
    def __init__(self, data, base_lab):
        self.id = data['id']
        self.title = data.get('title', '')
        self.steps = data['steps']
        self.base = base_lab.copy()
        self.base.apply_patch(data.get('patch'))
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


def load_scenario(sid, lab=None, directory=None):
    directory = directory or os.path.join(HERE, 'scenarios')
    path = os.path.join(directory, '%s.yaml' % sid)
    if not os.path.exists(path):
        raise LabError('no scenario %s (%s)' % (sid, path))
    return Scenario(load_yaml(path), lab or load_lab())
