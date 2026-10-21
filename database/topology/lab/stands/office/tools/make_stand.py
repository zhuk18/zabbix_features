#!/usr/bin/env python3
"""Write lab-office.yaml and scenarios/R1.yaml from the converted data and the
expected result (resolve.py). Natural keys only (lab spec §8.3)."""
import json, sys, os

stand, result = sys.argv[1], sys.argv[2]
man = json.load(open(os.path.join(stand, 'data', 'manifest.json')))
res = json.load(open(result))
dev = {d['name']: d for d in res['devices']}
names = [d['name'] for d in res['devices']]

def natural(end):
    """Device[:port] -> natural key. Device names that are not unique -> chassis."""
    d, _, p = end.partition(':') if not end.startswith(tuple('0123456789abcdef')) or end.count(':') < 5 else (end, '', '')
    # names like 18:60:24:b2:bd:06:<port>
    if end.count(':') >= 5 and all(len(x) == 2 for x in end.split(':')[:6]):
        parts = end.split(':'); d = ':'.join(parts[:6]); p = ':'.join(parts[6:])
    p = p.rstrip('*')
    if names.count(d) > 1:
        # pick the device by its pseudo port and the link; resolved by caller
        return None, p
    return d, p

def chassis_of(name, port_pseudo, link_other):
    for o in res['observations']:
        if o.get('far_device_name') == name and o.get('outcome') == 'applied':
            rep = o['reporter']; lp = o['local_name']
            if f'{rep}:{lp}' == link_other:
                return o['row'].get('REM_CHASSIS')
    return None

HP = [m['device'] for m in man if '-2530-' in m['device']]

lines = []
w = lines.append
w('# Lab stand "office": real capture of 12 switches (2026-10-14) + 2 synthetic gateways.')
w('# Needs lab spec 2.6 (replay devices, §4.1a). See README.md.')
w('settings:')
w('  database: zabbix')
w('  network: 127.0.17.0/24        # last octet = last octet of the captured address')
w('  snmp_port: 1611')
w('  community: zbxlab')
w('  zabbix: {url: <as lab.yaml>, group: Test, template_lld: "Topology by SNMP", template_interfaces: "Network Generic Device by SNMP"}')
w('devices:')
for m in man:
    w(f"  {m['device']}:")
    w(f"    ip: {m['lab_ip']}")
    src = 'SYNTHETIC, tools/gen_gateways.py' if m.get('synthetic') else f"from {m['source_file']}, {m['source_ip']}"
    w(f"    replay: data/{m['device']}/zbxlab.snmprec   # {src}")
    w('    zabbix: {monitored: true, transport: lld, proxy: null}')
w('cables: []                     # replay devices: neighbors come from the capture')
w('deviations:')
for h in HP:
    w(f'  - id: no-cdp-{h[:4].lower()}')
    w('    reason: "report F-1: the HP 2530 CDP table is a copy of LLDP; without it each Windows host on an HP port gets one link"')
    w('    type: omit_mib')
    w(f'    device: {h}')
    w('    mib: cdp')
w('  - id: na-reboot-sw15-1-1-1')
w('    reason: "report F-7: NA device restarts with a new random (locally administered) chassis MAC; model §6.7.1 new Device, §8.4 supersede, §6.7.0 address key moves"')
w('    type: override_oid')
w('    device: SW15-10-POE-6000-24G')
w('    oid: lldpRemChassisId')
w('    row: 1/1/1')
w('    value_hex: "3a5c91e2074b"        # invented; old value 12bfb8b96af5')
open(os.path.join(stand, 'lab-office.yaml'), 'w').write('\n'.join(lines) + '\n')

# ---------- scenario (model 2.29, lab 2.10) ----------
alldev = res['devices']
sn_count = {}
for d in alldev:
    k = d['name'] if d['reporter'] else d['attrs'].get('sysname')
    if k: sn_count[k] = sn_count.get(k, 0) + 1

def dkey(e):
    if e['reporter']: return e['device']
    if (e['chassis'] or '').startswith('ZBX-'): return {'chassis': e['chassis']}   # Windows host: name only in the chassis id
    sn = e['sysname']
    if sn and sn_count.get(sn) == 1 and ':' not in sn: return sn
    return {'chassis': e['chassis']}

def yv(v):
    if isinstance(v, dict): return '{' + ', '.join(f'{k}: "{x}"' for k, x in v.items()) + '}'
    return v

def end(e):
    k = dkey(e); p = e['port']
    if isinstance(k, str) and ':' not in k and (p is None or ':' not in p):
        return f'{k}:{p}' if p else k
    return '{device: ' + yv(k) + (f', port: "{p}"' if p else '') + '}'

links = []
for l in res['physical_links']:
    a, b = l['ends']
    if not a['reporter'] or (b['reporter'] and a['device'] > b['device']): a, b = b, a
    links.append((end(a), end(b), '+'.join(sorted(l['sources'])), a, b))
links.sort(key=lambda x: (x[0], x[1]))

def is_ws(b): return (b['chassis'] or '').startswith('ZBX-')
ws = [(a, b) for _, _, _, a, b in links if is_ws(b)]
mik = [('SW18-09-ACC-2530-48G', '6')]
NA_OLD, NA_NEW = '12:bf:b8:b9:6a:f5', '3a:5c:91:e2:07:4b'
ndev = len(alldev)

S = []; s_ = S.append
s_('id: R1')
s_('title: Office capture — baseline, HP without CDP, CDP back, NA restart, ingest again')
s_('base: lab-office.yaml')
s_('# Expected values: model v2.29, lab v2.10 (2026-10-20). They need task 038 in ingest.')
s_('settings: {freshness_seconds: null, transport: null, sources: null}')
s_('steps:')
s_('  - id: 0')
s_('    note: baseline, real capture as received + synthetic gateways')
s_('    assert:')
s_(f'      - status: {{field: devices_created, equals: {ndev}}}   # 14 reporters + {ndev-14}; no twin Devices (model 2.29 §6.7.1 same-port join)')
s_(f'      - status: {{field: links_created, equals: {len(links)}}}')
s_('      - pending: "10 rows of SW12 and SW14 (chassis subtype local, 6 zero bytes) are dropped by the server: no identity macro, contract model §5.2. No observation, no link on those ports."')
s_('      - pending: "SW18 CDP port 6: two rows with one claim key; model 2.29 §7.1: the first row makes the observation, rows_duplicate_claim = 1 for the rule"')
for x, y, src, a, b in links:
    s_(f'      - {{link: [{x}, {y}], state: active, type: physical_link}}   # {src}')
for a, b in ws:
    s_(f'      - {{observation: {{reporter: {a["device"]}, port: "{a["port"]}", remote: {yv(dkey(b))}}}, outcome: applied}}   # one Device per host (on HP: LLDP + CDP rows)')
for rep, port in mik:
    s_(f'      - {{observation: {{reporter: {rep}, port: "{port}", remote: MikroTik}}, outcome: ambiguous}}   # two LLDP chassis, one IP (model §6.7.4)')
HPS = [m['device'] for m in man if '-2530-' in m['device']]
ids = ', '.join('no-cdp-' + h[:4].lower() for h in HPS)
s_('  - id: 1')
s_('    note: CDP removed from the six HP switches')
s_(f'    deviations: {{enable: [{ids}], disable: []}}')
s_('    assert:')
s_('      - status: {field: links_created, equals: 0}')
s_('      - status: {field: devices_created, equals: 0}')
for x, y, src, a, b in links:
    s_(f'      - {{link: [{x}, {y}], state: active}}')
for rep, port in mik:
    s_(f'      - {{observation: {{reporter: {rep}, port: "{port}", remote: MikroTik}}, outcome: ambiguous}}')
s_('  - id: 2')
s_('    note: CDP back — same data as step 0, same result')
s_(f'    deviations: {{enable: [], disable: [{ids}]}}')
s_('    assert:')
s_('      - status: {field: links_created, equals: 0}')
s_('      - status: {field: devices_created, equals: 0}')
for a, b in ws:
    s_(f'      - {{link: [{end(a)}, {end(b)}], state: active, type: physical_link}}')
    s_(f'      - {{observation: {{reporter: {a["device"]}, port: "{a["port"]}", remote: {yv(dkey(b))}}}, outcome: applied}}')
s_('  - id: 3')
s_('    note: NA12501003431 restarts with a new chassis MAC (synthetic, F-7)')
s_('    deviations: {enable: [na-reboot-sw15-1-1-1], disable: []}')
s_('    assert:')
s_(f'      - {{link: [SW15-10-POE-6000-24G:1/1/1, {{device: {{chassis: "{NA_NEW}"}}, port: "eth0"}}], state: active, type: physical_link}}')
s_(f'      - {{link: [SW15-10-POE-6000-24G:1/1/1, {{device: {{chassis: "{NA_OLD}"}}, port: "eth0"}}], state: superseded}}   # no other rule supports it (§8.4)')
s_(f'      - {{observation: {{reporter: SW15-10-POE-6000-24G, port: "1/1/1", remote: {{chassis: "{NA_NEW}"}}}}, outcome: applied}}')
s_('      - status: {field: links_created, equals: 1}')
s_('      - status: {field: links_superseded, equals: 1}')
s_('      - status: {field: devices_created, equals: 1}')
s_('      - status: {field: address_keys_removed, equals: 1}   # ip:server:192.168.131.84 leaves the old Device')
s_('      - pending: "the old Device (chassis 12:bf:…) stays without an active link: a ghost after each restart (model §6.7.1: ingest never deletes Devices)"')
s_('  - id: 4')
s_('    note: no change; poll and ingest again on the same data (task 034 found 26 new Devices per run)')
s_('    assert:')
s_('      - status: {field: devices_created, equals: 0}')
s_('      - status: {field: links_created, equals: 0}')
s_('      - status: {field: links_superseded, equals: 0}')
os.makedirs(os.path.join(stand, 'scenarios'), exist_ok=True)
open(os.path.join(stand, 'scenarios', 'R1.yaml'), 'w').write('\n'.join(S) + '\n')
print('links', len(links), 'ws', len(ws), 'devices', ndev)
