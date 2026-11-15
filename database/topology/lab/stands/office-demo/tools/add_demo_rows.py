#!/usr/bin/env python3
"""Build the data of the stand "office-demo" from the data of the stand "office" (lab spec 9 D1, task 104).

Usage: tools/add_demo_rows.py [<office data dir> [<demo data dir>]]    (defaults: ../office/data, data)

Copies every device file of the office stand and adds ONE synthetic block: the LLDP rows of two PCs and a printer on SW17-09-ACC-2530-48G port 16
(an HP 2530 port that is up and has no neighbor row, LLDP or CDP, in the capture). The rows copy the form of real rows of the same switch:

  PC       chassis id subtype 7 (local, the host name), port id subtype 3 (MAC), no sysName, no port description, no sysDesc, capabilities 00 (SW17 port 3)
  printer  chassis id subtype 4 (MAC), port id subtype 3 (MAC), port description eth0, sysName, sysDesc, capabilities (SW17 port 37)
  no management address, as the real rows of this switch have none that is a lab address

Invented values: locally administered MACs (02:de:00:00:00:xx), the names DEMO-PC1, DEMO-PC2, DEMO-PRN1, the printer sysDesc. The manifest of the demo data
gets `synthetic_rows`; the list is also in README.md. The rows are in the data from step 0 (a replay device takes only omit_mib and override_oid, lab spec 4.4).
"""
import json
import os
import shutil
import sys

PORT = 16                    # lldpRemLocalPortNum = ifIndex 16 of SW17-09-ACC-2530-48G (ifOperStatus up, no lldpRem and no cdpCache row)
DEVICE = 'SW17-09-ACC-2530-48G'
REM = '1.0.8802.1.1.2.1.4.1.1'
ROWS = [
    # remIndex, chassis subtype, chassis id, port subtype, port id, port desc, sysName, sysDesc, capabilities supported, enabled
    (1, 7, b'DEMO-PC1', 3, bytes.fromhex('02de00000011'), b'', b'', b'', '00', '00'),
    (2, 7, b'DEMO-PC2', 3, bytes.fromhex('02de00000012'), b'', b'', b'', '00', '00'),
    (3, 4, bytes.fromhex('02de00000003'), 3, bytes.fromhex('02de00000013'), b'eth0', b'DEMO-PRN1', b'DEMO printer (synthetic)', '39', '01'),
]
NAMES = {1: 'DEMO-PC1', 2: 'DEMO-PC2', 3: 'DEMO-PRN1'}


def rows():
    out = {}
    for ix, cs, cid, ps, pid, pdesc, sysname, sysdesc, caps, enabled in ROWS:
        suffix = '0.%d.%d' % (PORT, ix)
        for col, tag, val in ((4, '2', str(cs)), (5, '4x', cid.hex()), (6, '2', str(ps)), (7, '4x', pid.hex()), (8, '4x' if pdesc else '4', pdesc.hex() if pdesc else ''),
                              (9, '4x' if sysname else '4', sysname.hex() if sysname else ''), (10, '4x' if sysdesc else '4', sysdesc.hex() if sysdesc else ''),
                              (11, '4x', caps), (12, '4x', enabled)):
            out['%s.%d.%s' % (REM, col, suffix)] = '%s.%d.%s|%s|%s' % (REM, col, suffix, tag, val)
    return out


def key(line):
    return tuple(int(x) for x in line.split('|', 1)[0].split('.'))


def main(src, dst):
    if os.path.exists(dst):
        shutil.rmtree(dst)
    shutil.copytree(src, dst)
    path = os.path.join(dst, DEVICE, 'zbxlab.snmprec')
    with open(path) as f:
        lines = f.read().splitlines()
    clash = [l for l in lines if l.split('|', 1)[0] in rows()]
    if clash or any(l.startswith(REM) and ('.0.%d.' % PORT) in l.split('|', 1)[0] for l in lines):
        sys.exit('the port %d of %s already has rows: %s' % (PORT, DEVICE, clash[:2]))
    lines += list(rows().values())
    lines.sort(key=key)
    with open(path, 'w') as f:
        f.write('\n'.join(lines) + '\n')
    mpath = os.path.join(dst, 'manifest.json')
    manifest = json.load(open(mpath))
    for m in manifest:
        if m['device'] == DEVICE:
            m['records'] = len(lines)
            m['synthetic_rows'] = ['lldpRem row of %s on port %d (remIndex %d)' % (NAMES[ix], PORT, ix) for ix in sorted(NAMES)]
    json.dump(manifest, open(mpath, 'w'), indent=1)
    print('%s: %d rows added on %s port %d' % (dst, len(rows()), DEVICE, PORT))


if __name__ == '__main__':
    here = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
    main(sys.argv[1] if len(sys.argv) > 1 else os.path.join(here, '..', 'office', 'data'), sys.argv[2] if len(sys.argv) > 2 else os.path.join(here, 'data'))
