"""Read-only access to topo_* state, by natural keys (spec §8.3).

The only writes in this module are the ones the spec names: `reset_topo()` (§8.2) and
`insert_manual_link()` (the `zabbix_manual_link` deviation, created the way the existing test suites
create it: a raw topo_edges row, because the UI action needs a browser session).
Everything refuses to run unless the connected database is the configured one and that is `zabbix`.
"""
import json

import mysql.connector

from .model import LabError

REQUIRED_DATABASE = 'zabbix'


class DatabaseGuard(LabError):
    pass


def connect(settings):
    name = settings.get('database')
    if name != REQUIRED_DATABASE:
        raise DatabaseGuard('refusing to start: database is %r, only %r is allowed' % (name, REQUIRED_DATABASE))
    conn = mysql.connector.connect(user=settings.get('db_user', 'zabbix'), password=settings.get('db_password', ''),
                                   host=settings.get('db_host', 'localhost'), database=name, autocommit=True)
    cur = conn.cursor()
    cur.execute('SELECT DATABASE()')
    actual = cur.fetchone()[0]
    if actual != REQUIRED_DATABASE:
        conn.close()
        raise DatabaseGuard('refusing to start: connected to %r, only %r is allowed' % (actual, REQUIRED_DATABASE))
    return conn


def rows(conn, sql, args=()):
    cur = conn.cursor(dictionary=True)
    cur.execute(sql, args)
    return cur.fetchall()


def _j(text):
    return json.loads(text) if text else {}


def natural_state(conn):
    """Devices, ports, links, represented_by and observations by natural keys (no ids)."""
    nodes = {r['id']: r for r in rows(conn, 'SELECT * FROM topo_nodes')}
    for n in nodes.values():
        n['a'] = _j(n['attrs'])

    def dev_name(node):
        a = node['a']
        return a.get('sysname') or a.get('chassis_id') or a.get('mac') or ('device#%s' % node['id'])

    def port_label(pid):
        p = nodes[pid]
        return '%s:%s' % (dev_name(nodes[p['device_id']]), p['a'].get('name'))

    def end_label(nid):
        n = nodes[nid]
        return port_label(nid) if n['type'] == 'port' else dev_name(n)

    devices = sorted(dev_name(n) for n in nodes.values() if n['type'] == 'device')
    ports = {}
    for n in nodes.values():
        if n['type'] == 'port':
            ports.setdefault(dev_name(nodes[n['device_id']]), []).append(
                n['a'].get('name') + ('*' if n['a'].get('pseudo') else ''))
    ports = {k: sorted(v) for k, v in sorted(ports.items())}

    hostnames = {r['hostid']: r['host'] for r in rows(conn, 'SELECT hostid, host FROM hosts')}
    represented = {}
    for n in nodes.values():
        if n['type'] == 'device' and n['represented_by_node_id']:
            t = nodes[n['represented_by_node_id']]
            represented[dev_name(n)] = hostnames.get(t['host_ref'], 'host#%s' % t['host_ref']) if t['host_ref'] \
                else 'proxy#%s' % t['proxy_ref']
    represented = dict(sorted(represented.items()))

    links = []
    for e in rows(conn, 'SELECT * FROM topo_edges ORDER BY id'):
        if e['type'] not in ('physical_link', 'device_link'):
            continue
        a = _j(e['attrs'])
        ends = sorted([end_label(e['src_id']), end_label(e['dst_id'])]) if e['type'] == 'physical_link' \
            else [end_label(e['src_id']), end_label(e['dst_id'])]
        links.append({'id': e['id'], 'type': e['type'], 'ends': ends, 'via': a.get('discovered_via'),
                      'state': 'superseded' if a.get('superseded_at') else 'active',
                      'far_port_reason': a.get('far_port_reason'), 'attrs': a})
    links.sort(key=lambda l: (l['type'], l['ends']))

    obs = []
    for o in rows(conn, 'SELECT o.*, h.host AS reporter FROM topo_observations o JOIN items i ON i.itemid=o.itemid '
                        'JOIN hosts h ON h.hostid=i.hostid ORDER BY o.id'):
        obs.append({'id': o['id'], 'reporter': o['reporter'], 'port': port_label(o['local_port_id']).split(':', 1)[1],
                    'remote_key': o['remote_key'], 'outcome': o['outcome'], 'edge_id': o['edge_id'],
                    'link_precision': o['link_precision'], 'precision_lower': bool(o['precision_lower']),
                    'device': dev_name(nodes[o['device_id']]) if o['device_id'] in nodes else None})
    return {'devices': devices, 'ports': ports, 'represented_by': represented, 'links': links, 'observations': obs}


def comparable(state):
    """The part of natural_state() that S0 compares: no ids, no attrs, no observation ids."""
    return {
        'devices': state['devices'],
        'ports': state['ports'],
        'represented_by': state['represented_by'],
        'links': [{'type': l['type'], 'ends': l['ends'], 'via': l['via'], 'state': l['state']} for l in state['links']],
    }


def snapshot_clocks(conn):
    return {r['itemid']: r['clock'] for r in rows(conn, 'SELECT itemid, clock FROM topo_lld_snapshot')}


def counts(conn):
    out = {}
    for t in ('topo_lld_snapshot', 'topo_nodes', 'topo_edges', 'topo_observations'):
        out[t] = rows(conn, 'SELECT COUNT(*) c FROM %s' % t)[0]['c']
    return out


def reset_topo(conn):
    """§8.2: clear topo_* state (same as 'clean topo_* state' of Part 2 §10)."""
    cur = conn.cursor()
    cur.execute('SET FOREIGN_KEY_CHECKS = 0')
    for t in ('topo_observations', 'topo_edges', 'topo_nodes', 'topo_lld_snapshot'):
        cur.execute('DELETE FROM %s' % t)
    cur.execute('SET FOREIGN_KEY_CHECKS = 1')


def port_node_id(conn, device, port):
    r = rows(conn, "SELECT p.id FROM topo_nodes p JOIN topo_nodes d ON d.id=p.device_id WHERE p.type='port' "
                   "AND JSON_UNQUOTE(JSON_EXTRACT(d.attrs,'$.sysname'))=%s AND JSON_UNQUOTE(JSON_EXTRACT(p.attrs,'$.name'))=%s",
             (device, port))
    return r[0]['id'] if r else None


def insert_manual_link(conn, port_a, port_b):
    """A manual physical_link, as the existing test suites create one (raw_link in test_manual_contradiction.php)."""
    a, b = sorted((port_a, port_b))
    cur = conn.cursor()
    cur.execute("INSERT INTO topo_edges (type, src_id, dst_id, attrs, created_at) VALUES ('physical_link', %s, %s, %s, UNIX_TIMESTAMP())",
                (a, b, json.dumps({'discovered_via': 'manual', 'last_seen': __import__('time').time().__int__()})))
    return cur.lastrowid
