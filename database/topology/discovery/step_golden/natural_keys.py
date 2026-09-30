#!/usr/bin/env python3
"""Dumps topo_nodes / topo_edges / topo_observations by natural keys (chassis id, ifIndex, endpoints) to a JSON file,
so two clean ingest runs (JS template vs native template) can be compared: ids and last_seen differ, natural keys must
not (topology-lld-part3-spec.md §8, Part 2 §10 "compare by natural keys").

    natural_keys.py out.json      # reads the database through `mysql -uzabbix zabbix`; a leading "E2E native " is
                                  # stripped from Zabbix host names so cloned reporters compare equal
"""
import json, subprocess, sys, re

def q(sql):
    out = subprocess.run(['mysql', '-uzabbix', 'zabbix', '-N', '-B', '-e', sql], capture_output=True, text=True, check=True).stdout
    return [line.split('\t') for line in out.splitlines() if line]

def norm(host):
    return re.sub(r'^E2E native ', '', host)

def dump():
    nodes = {int(r[0]): dict(type=r[1], host_ref=r[2], device_id=r[3], rep=r[4], mb=r[5], attrs=json.loads(r[6]))
             for r in q("select id,type,ifnull(host_ref,''),ifnull(device_id,''),ifnull(represented_by_node_id,''),ifnull(represented_by_matched_by,''),attrs from topo_nodes")}
    hosts = {r[0]: norm(r[1]) for r in q("select hostid,host from hosts")}
    def dev(n): return nodes[int(n['device_id'])] if n['device_id'] else n
    def dkey(n):
        a = dev(n)['attrs']; return a.get('chassis_id') or a.get('mgmt_ip') or a.get('sysname')
    def pkey(pid):
        p = nodes[pid]; return f"{dkey(p)}#{p['attrs'].get('if_index')}{'(pseudo)' if p['attrs'].get('pseudo') else ''}"
    out = {'devices': [], 'ports': [], 'edges': [], 'represented_by': [], 'observations': []}
    volatile = {'last_seen', 'last_seen_src', 'last_seen_dst', 'zabbix_itemids'}
    for i, n in nodes.items():
        a = {k: v for k, v in n['attrs'].items() if k not in volatile}
        if n['type'] == 'device':
            out['devices'].append([dkey(n), a])
            if n['rep']:
                r = nodes[int(n['rep'])]
                out['represented_by'].append([dkey(n), r['type'], hosts.get(r['host_ref']), n['mb']])
        elif n['type'] == 'port':
            out['ports'].append([pkey(i), a])
    for r in q("select id,src_id,dst_id,attrs from topo_edges"):
        a = {k: v for k, v in json.loads(r[3]).items() if k not in volatile}
        out['edges'].append([pkey(int(r[1])), pkey(int(r[2])), a])
    cols = [c[0] for c in q("describe topo_observations")]
    for r in q("select * from topo_observations"):
        o = dict(zip(cols, r))
        ra = {k: v for k, v in json.loads(o['remote_attrs']).items() if k not in volatile} if o.get('remote_attrs') else {}
        dev_id = o.get('device_id')
        out['observations'].append([pkey(int(o['local_port_id'])), o['remote_key'], o['outcome'], ra,
            dkey(nodes[int(dev_id)]) if dev_id not in (None, 'NULL', '') else None])
    for k in out: out[k].sort(key=lambda x: json.dumps(x, sort_keys=True))
    return out

if __name__ == '__main__':
    json.dump(dump(), open(sys.argv[1], 'w'), indent=1, sort_keys=True)
    d = json.load(open(sys.argv[1]))
    print({k: len(v) for k, v in d.items()})
