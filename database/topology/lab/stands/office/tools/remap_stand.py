#!/usr/bin/env python3
"""Moves a stand's replay data from the lab addresses 127.0.17.N to 192.0.2.N (task 065, lab spec 6.1); the same last octet.

    remap_stand.py <stand data dir>        # for example stands/office/data

Changes only these things in every <device>/zbxlab.snmprec and in manifest.json: the dotted address 127.0.17.N (in OID indexes and IpAddress values) and the
4-octet cdpCacheAddress value 7f0011NN. Then the OIDs are sorted numerically again (an address in an index sorts differently: snmpsim needs the order).
Idempotent: a stand already on 192.0.2.N is not changed.
"""
import glob
import os
import re
import sys

root = sys.argv[1]
changed = 0
for f in sorted(glob.glob(os.path.join(root, '*', 'zbxlab.snmprec'))) + [os.path.join(root, 'manifest.json')]:
    if not os.path.exists(f):
        continue
    text = open(f, encoding='utf-8').read()
    lines = text.split('\n')
    tail = lines[-1] == ''
    if tail:
        lines = lines[:-1]
    out = []
    for line in lines:
        new = re.sub(r'(?<!\d)127\.0\.17\.(\d+)(?!\d)', r'192.0.2.\1', line)
        m = re.match(r'^(1\.3\.6\.1\.4\.1\.9\.9\.23\.1\.2\.1\.1\.4\.[\d.]+)\|4x\|7f0011([0-9a-f]{2})$', new)
        if m:
            new = '%s|4x|c00002%s' % (m.group(1), m.group(2))
        out.append(new)
    if f.endswith('.snmprec'):
        out.sort(key=lambda l: tuple(int(x) for x in l.split('|', 1)[0].split('.')))
    new_text = '\n'.join(out) + ('\n' if tail else '')
    if new_text != text:
        open(f, 'w', encoding='utf-8').write(new_text)
        changed += 1
print('%d file(s) changed' % changed)
