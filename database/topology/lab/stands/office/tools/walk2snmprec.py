#!/usr/bin/env python3
"""Real capture (net-snmp numeric walk export) -> snmpsim .snmprec for the lab.

Transformations (all listed in the stand README):
  T1  type mapping to snmprec tags; octet strings as 4x (hex), empty as 4
  T2  untyped integers (net-snmp -Ot) -> TimeTicks (67)
  T3  'No Such Object' / 'No more variables' lines dropped
  T4  address remap 192.168.17.N -> <lab prefix>.N in: LLDP management
      address indexes (lldpConfigManAddr, lldpLocManAddr, lldpRemManAddr),
      ipAddrTable, ipNetToMediaTable (index and IpAddress values),
      cdpCacheAddress (4-octet value)
  T5  end record 1.3.6.1.4.1.99999.1.0 (lab spec §5.2)
  T6  OIDs sorted numerically
Nothing else is changed.
"""
import sys, re, os, json

SRC_NET = (192, 168, 17)
TAGS = {'INTEGER': '2', 'Counter32': '65', 'Gauge32': '66', 'Counter64': '70', 'OID': '6',
        'IpAddress': '64', 'Timeticks': '67'}
ADDR_TABLES = ('1.0.8802.1.1.2.1.1.7.', '1.0.8802.1.1.2.1.3.8.', '1.0.8802.1.1.2.1.4.2.',
               '1.3.6.1.2.1.4.20.', '1.3.6.1.2.1.4.22.')
CDP_ADDR = '1.3.6.1.4.1.9.9.23.1.2.1.1.4.'

def parse(fn):
    recs, last = [], None
    hdr = {}
    for raw in open(fn, encoding='utf-8', errors='replace'):
        line = raw.rstrip('\r\n')
        m = re.match(r'# (Device|IP address|Exported): (.*)', line)
        if m: hdr[m.group(1)] = m.group(2).strip()
        if line.startswith('#') or not line.strip():
            last = None; continue
        m = re.match(r'\.([\d.]+) = (.*)$', line)
        if m:
            recs.append([m.group(1), m.group(2)]); last = recs[-1]
        elif last is not None:
            last[1] += ' ' + line.strip()
    return hdr, recs

def remap_oid(oid, dst, stats):
    if not oid.startswith(ADDR_TABLES): return oid
    a, b, c = SRC_NET
    new = re.sub(rf'(?<=\.){a}\.{b}\.{c}\.(\d+)(?=$|\.)', lambda m: f'{dst}.{m.group(1)}', oid)
    if new != oid: stats['oid'] += 1
    return new

def convert(fn, dst_prefix):
    hdr, recs = parse(fn)
    stats = {'oid': 0, 'ipaddr': 0, 'cdp': 0, 'dropped': 0, 'timeticks': 0}
    out = {}
    d = [int(x) for x in dst_prefix.split('.')]
    for oid, v in recs:
        if v.startswith('No Such') or v.startswith('No more variables'):
            stats['dropped'] += 1; continue
        noid = remap_oid(oid, dst_prefix, stats)
        if v.strip() == '""':
            rec = f'{noid}|4|'
        elif re.fullmatch(r'\d+', v.strip()):
            rec = f'{noid}|67|{v.strip()}'; stats['timeticks'] += 1
        else:
            m = re.match(r'([A-Za-z0-9-]+): ?(.*)$', v)
            if not m: raise ValueError(f'{fn}: cannot parse {oid} = {v}')
            t, x = m.group(1), m.group(2).strip()
            if t == 'Hex-STRING':
                h = x.replace(' ', '').lower()
                if noid.startswith(CDP_ADDR) and len(h) == 8 and tuple(bytes.fromhex(h)[:3]) == SRC_NET:
                    h = bytes(d + [bytes.fromhex(h)[3]]).hex(); stats['cdp'] += 1
                rec = f'{noid}|4x|{h}'
            elif t == 'STRING':
                s = x[1:-1] if x.startswith('"') and x.endswith('"') else x
                rec = f'{noid}|4x|{s.encode().hex()}'
            elif t == 'IpAddress':
                p = [int(y) for y in x.split('.')]
                if tuple(p[:3]) == SRC_NET:
                    x = f'{dst_prefix}.{p[3]}'; stats['ipaddr'] += 1
                rec = f'{noid}|64|{x}'
            elif t == 'OID':
                rec = f'{noid}|6|{x.lstrip(".")}'
            elif t in TAGS:
                n = re.match(r'-?\d+', x).group(0)
                rec = f'{noid}|{TAGS[t]}|{n}'
            else:
                raise ValueError(f'{fn}: unknown type {t} at {oid}')
        if noid in out: raise ValueError(f'{fn}: duplicate OID {noid}')
        out[noid] = rec
    out['1.3.6.1.4.1.99999.1.0'] = '1.3.6.1.4.1.99999.1.0|2|1'
    lines = [out[k] for k in sorted(out, key=lambda s: [int(p) for p in s.split('.')])]
    return hdr, lines, stats

if __name__ == '__main__':
    src_dir, dst_dir, prefix = sys.argv[1], sys.argv[2], sys.argv[3]
    import glob
    manifest = []
    for fn in sorted(glob.glob(os.path.join(src_dir, '*.walk'))):
        hdr, lines, st = convert(fn, prefix)
        name = hdr['Device'].split()[0]
        ip = hdr['IP address']; n = ip.split('.')[-1]
        os.makedirs(os.path.join(dst_dir, name), exist_ok=True)
        open(os.path.join(dst_dir, name, 'zbxlab.snmprec'), 'w').write('\n'.join(lines) + '\n')
        manifest.append({'device': name, 'source_file': os.path.basename(fn), 'source_ip': ip,
                         'lab_ip': f'{prefix}.{n}', 'exported': hdr.get('Exported'), 'records': len(lines), 'changes': st})
        print(name, ip, '->', f'{prefix}.{n}', len(lines), st)
    json.dump(manifest, open(os.path.join(dst_dir, 'manifest.json'), 'w'), indent=1)
