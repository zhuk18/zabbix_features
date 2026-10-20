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

# ---------- scenario ----------
links = []
for l in res['physical_links']:
    a, b = l['a'], l['b']
    rep = {m['device'] for m in man}
    # reporter side first
    if a.split(':')[0] not in rep: a, b = b, a
    dn, dp = natural(b)
    if dn is None:
        nm = b.split(':')[0]
        ch = chassis_of(nm, dp, a)
        dn = ch
    if ':' in dn or ':' in dp:
        far = '{device: "%s"%s}' % (dn, f', port: "{dp}"' if dp else '')   # lab spec §8.3 has no form for ':' in a name (README)
    else:
        far = f'{dn}:{dp}' if dp else dn
    links.append((a, far, sorted(l['sources'])))

ws_ports = []      # F-1
mikrotik = []
for o in res['observations']:
    if o.get('outcome') == 'ambiguous':
        key = (o['reporter'], o['local_name'])
        if 'MikroTik' in json.dumps(o['row']) or '64:d1:54' in json.dumps(o['row']):
            if key not in mikrotik: mikrotik.append(key)
        elif key not in [k for k, _ in ws_ports]:
            ws_ports.append((key, o['row'].get('REM_CHASSIS') or o['row'].get('REM_SYSNAME')))

S = []
s = S.append
s('id: R1')
s('title: Office capture — baseline, HP without CDP, CDP back, NA restart')
s('base: lab-office.yaml')
s('settings: {freshness_seconds: null, transport: null, roles: null}')
s('steps:')
s('  - id: 0')
s('    note: baseline, real capture as received + synthetic gateways')
s('    assert:')
s(f'      - status: {{field: links_created, equals: {len(links)}}}')
s('      - status: {field: rows_no_identity, equals: 10}          # report F-2 (SW12 x5, SW14 x5)')
s('      - pending: "identity_keys_shared: one key ip:server:192.168.88.1 (report F-4); the runner has no assertion for a list field"')
s('      - pending: "SW18 CDP port 6: two rows with the same claim key (itemid, num/ifindex:6, ip:server:192.168.88.1) — model §7.1 unique key; expected behaviour not defined (report F-4)"')
for a, far, src in links:
    s(f'      - {{link: [{a}, {far}], state: active, type: physical_link}}   # {"+".join(src)}')
for (rep, port), remote in ws_ports:
    s(f'      - {{observation: {{reporter: {rep}, port: "{port}", remote: {remote}}}, outcome: ambiguous}}   # F-1')
    s(f'      - {{link: [{rep}:{port}, {remote}], state: absent}}')
for rep, port in mikrotik:
    s(f'      - {{observation: {{reporter: {rep}, port: "{port}", remote: MikroTik}}, outcome: ambiguous}}   # F-4')
s('  - id: 1')
s('    note: CDP removed from the six HP switches (F-1 option b)')
s('    deviations: {enable: [%s], disable: []}' % ', '.join('no-cdp-'+h[:4].lower() for h in HP))
s('    assert:')
s(f'      - status: {{field: links_created, equals: {len(ws_ports)}}}')
for (rep, port), remote in ws_ports:
    s(f'      - {{link: [{rep}:{port}, {remote}], state: active, type: physical_link}}')
for a, far, src in links:
    s(f'      - {{link: [{a}, {far}], state: active}}')
for rep, port in mikrotik:
    s(f'      - {{observation: {{reporter: {rep}, port: "{port}", remote: MikroTik}}, outcome: ambiguous}}   # two LLDP Devices stay')
s('  - id: 2')
s('    note: CDP back — same data as step 0, different history (model §8.5 first bullet)')
s('    deviations: {enable: [], disable: [%s]}' % ', '.join('no-cdp-'+h[:4].lower() for h in HP))
s('    assert:')
s('      - status: {field: links_created, equals: 0}')
for (rep, port), remote in ws_ports:
    s(f'      - {{link: [{rep}:{port}, {remote}], state: active, type: physical_link}}   # the link of step 1 stays')
    s(f'      - {{observation: {{reporter: {rep}, port: "{port}", remote: {remote}}}, outcome: ambiguous}}   # the CDP claim; conflict if §8.5 bullet 3 -> §8.4 is read (README Q-3)')
s('  - id: 3')
s('    note: NA12501003431 restarts with a new chassis MAC (synthetic, F-7)')
s('    deviations: {enable: [na-reboot-sw15-1-1-1], disable: []}')
s('    assert:')
s('      - {link: [SW15-10-POE-6000-24G:1/1/1, {device: "3a:5c:91:e2:07:4b", port: "eth0"}], state: active, type: physical_link}')
s('      - {link: [SW15-10-POE-6000-24G:1/1/1, {device: "12:bf:b8:b9:6a:f5", port: "eth0"}], state: superseded}   # no other rule supports it (§8.4)')
s('      - {observation: {reporter: SW15-10-POE-6000-24G, port: "1/1/1", remote: "3a:5c:91:e2:07:4b"}, outcome: applied}')
s('      - status: {field: links_created, equals: 1}')
s('      - status: {field: links_superseded, equals: 1}')
s('      - status: {field: devices_created, equals: 1}')
s('      - status: {field: address_keys_removed, equals: 1}   # ip:server:192.168.131.84 leaves the old Device (it keeps its chassis key)')
s('      - pending: "the old Device NA12501003431 (chassis 12:bf:…) stays with no active link: a ghost after each restart; the model has no rule to remove it (§6.7.1: Devices are never deleted by ingest)"')
os.makedirs(os.path.join(stand, 'scenarios'), exist_ok=True)
open(os.path.join(stand, 'scenarios', 'R1.yaml'), 'w').write('\n'.join(S) + '\n')
print('links', len(links), 'ws ports', len(ws_ports), 'mikrotik', mikrotik)
print([x for x in links if x[1] is None or x[1].startswith('None')])
