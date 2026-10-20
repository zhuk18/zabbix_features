#!/usr/bin/env python3
"""Synthetic gateways for the office stand (invented data, not a capture).

Facts taken from the real capture and kept:
  - sysName zbx-office-gw1 / zbx-office-gw2
  - chassis 84:39:8f:37:3a:7c / 84:39:8f:1b:a3:28 (SW12 lldpRemChassisId)
  - port ids port1, port2, subtype interfaceName (SW12 lldpRemPortId)
  - cabling: gw1 port1/port2 - SW12 1/1/37, 1/1/38; gw2 port1/port2 - SW12 1/1/39, 1/1/40
  - LACP system id 84:39:8f:37:3a:82 / 84:39:8f:1b:a3:2e (SW12 dot3adAggPortPartnerOperSystemID)
  - no LLDP management address towards SW12 (SW12 has none for them)
Invented: sysDescr, sysObjectID, ifIndex values, port MACs, the HA cable
gw1 ha1 - gw2 ha1, lab addresses 127.0.17.251 / .252, firmware text.
"""
import json, os, sys

def hx(b): return b.hex()
def mac(s): return bytes(int(x, 16) for x in s.split(':'))
def txt(s): return s.encode().hex()

# far ends taken from the replay data of SW12 (rows/*.json of the capture)
SW12 = {'sysname': 'SW12-10-CORE-6000-48G', 'chassis': 'a8:52:d4:53:11:00', 'ip': '127.0.17.2',
        'sysdesc': 'Aruba 6000 48G 4SFP Core Switch'}

GW = {
  'zbx-office-gw1': {'ip': '127.0.17.251', 'chassis': '84:39:8f:37:3a:7c', 'lacp': '84:39:8f:37:3a:82',
                     'ports': {3: ('port1', '84:39:8f:37:3a:7c'), 4: ('port2', '84:39:8f:37:3a:7d'), 7: ('ha1', '84:39:8f:37:3a:80')},
                     'lag': (30, 'core-lag', [3, 4]),
                     'nbr': {3: ('sw', '1/1/37'), 4: ('sw', '1/1/38'), 7: ('gw', 'zbx-office-gw2')}},
  'zbx-office-gw2': {'ip': '127.0.17.252', 'chassis': '84:39:8f:1b:a3:28', 'lacp': '84:39:8f:1b:a3:2e',
                     'ports': {3: ('port1', '84:39:8f:1b:a3:28'), 4: ('port2', '84:39:8f:1b:a3:29'), 7: ('ha1', '84:39:8f:1b:a3:2c')},
                     'lag': (30, 'core-lag', [3, 4]),
                     'nbr': {3: ('sw', '1/1/39'), 4: ('sw', '1/1/40'), 7: ('gw', 'zbx-office-gw1')}},
}

def build(name):
    g = GW[name]; R = {}
    def put(oid, tag, val): R[oid] = f'{oid}|{tag}|{val}'
    put('1.3.6.1.2.1.1.1.0', '4x', txt('FortiGate-100F v7.2.8,build1639 (synthetic)'))
    put('1.3.6.1.2.1.1.2.0', '6', '1.3.6.1.4.1.12356.101.1.1041')
    put('1.3.6.1.2.1.1.3.0', '67', '123456789')
    put('1.3.6.1.2.1.1.5.0', '4x', txt(name))
    ifs = dict(g['ports']); lagix, lagname, members = g['lag']
    allif = {**{k: (v[0], v[1], 6) for k, v in ifs.items()}, lagix: (lagname, g['lacp'], 161)}
    put('1.3.6.1.2.1.2.1.0', '2', str(len(allif)))
    for ix, (nm, m, t) in sorted(allif.items()):
        put(f'1.3.6.1.2.1.2.2.1.1.{ix}', '2', ix)
        put(f'1.3.6.1.2.1.2.2.1.2.{ix}', '4x', txt(nm))
        put(f'1.3.6.1.2.1.2.2.1.3.{ix}', '2', t)
        put(f'1.3.6.1.2.1.2.2.1.5.{ix}', '66', 2000000000 if t == 161 else 1000000000)
        put(f'1.3.6.1.2.1.2.2.1.6.{ix}', '4x', hx(mac(m)))
        put(f'1.3.6.1.2.1.2.2.1.7.{ix}', '2', 1)
        put(f'1.3.6.1.2.1.2.2.1.8.{ix}', '2', 1)
        for c in (10, 14, 16, 20): put(f'1.3.6.1.2.1.2.2.1.{c}.{ix}', '65', 0)
        put(f'1.3.6.1.2.1.31.1.1.1.1.{ix}', '4x', txt(nm))
        for c in (6, 10): put(f'1.3.6.1.2.1.31.1.1.1.{c}.{ix}', '70', 0)
        put(f'1.3.6.1.2.1.31.1.1.1.15.{ix}', '66', 2000 if t == 161 else 1000)
        put(f'1.3.6.1.2.1.31.1.1.1.18.{ix}', '4x', txt('to core' if ix in members else ('HA heartbeat' if nm == 'ha1' else '')))
    L = '1.0.8802.1.1.2.1'
    put(f'{L}.3.1.0', '2', 4)
    put(f'{L}.3.2.0', '4x', hx(mac(g['chassis'])))
    put(f'{L}.3.3.0', '4x', txt(name))
    for ix, (nm, m) in sorted(ifs.items()):
        put(f'{L}.3.7.1.2.{ix}', '2', 5)
        put(f'{L}.3.7.1.3.{ix}', '4x', txt(nm))
        put(f'{L}.3.7.1.4.{ix}', '4x', txt(nm))
    # own management address
    a = g['ip'].split('.')
    put(f'{L}.3.8.1.3.1.4.{g["ip"]}', '2', 2)
    put(f'{L}.3.8.1.4.1.4.{g["ip"]}', '2', 0)
    ri = 1
    for ix, (kind, x) in sorted(g['nbr'].items()):
        idx = f'0.{ix}.{ri}'
        if kind == 'sw':
            ch, pid, pdesc, sn, sd, ip = SW12['chassis'], x, x, SW12['sysname'], SW12['sysdesc'], SW12['ip']
        else:
            o = GW[x]; ch, pid, pdesc, sn, sd, ip = o['chassis'], 'ha1', 'ha1', x, 'FortiGate-100F v7.2.8,build1639 (synthetic)', o['ip']
        put(f'{L}.4.1.1.4.{idx}', '2', 4)
        put(f'{L}.4.1.1.5.{idx}', '4x', hx(mac(ch)))
        put(f'{L}.4.1.1.6.{idx}', '2', 5)
        put(f'{L}.4.1.1.7.{idx}', '4x', txt(pid))
        put(f'{L}.4.1.1.8.{idx}', '4x', txt(pdesc))
        put(f'{L}.4.1.1.9.{idx}', '4x', txt(sn))
        put(f'{L}.4.1.1.10.{idx}', '4x', txt(sd))
        put(f'{L}.4.2.1.3.{idx}.1.4.{ip}', '2', 2)
        put(f'{L}.4.2.1.4.{idx}.1.4.{ip}', '2', 0)
        ri += 1
    A = '1.2.840.10006.300.43.1.2.1.1'
    for ix in sorted(ifs):
        put(f'{A}.1.{ix}', '2', ix)
        put(f'{A}.3.{ix}', '4x', hx(mac(g['lacp'])))
        put(f'{A}.12.{ix}', '2', lagix if ix in members else 0)
        put(f'{A}.13.{ix}', '2', lagix if ix in members else 0)
    put('1.3.6.1.4.1.99999.1.0', '2', 1)
    keys = sorted(R, key=lambda s: [int(p) for p in s.split('.')])
    return [R[k] for k in keys]

if __name__ == '__main__':
    dst = sys.argv[1]
    man = json.load(open(os.path.join(dst, 'manifest.json')))
    man = [m for m in man if not m.get('synthetic')]
    for name, g in GW.items():
        lines = build(name)
        os.makedirs(os.path.join(dst, name), exist_ok=True)
        open(os.path.join(dst, name, 'zbxlab.snmprec'), 'w').write('\n'.join(lines) + '\n')
        man.append({'device': name, 'synthetic': True, 'source_file': 'tools/gen_gateways.py', 'source_ip': None,
                    'lab_ip': g['ip'], 'exported': None, 'records': len(lines), 'changes': None})
        print(name, len(lines))
    json.dump(man, open(os.path.join(dst, 'manifest.json'), 'w'), indent=1)
