"""Read-only access to topo_* state, by natural keys (topology-4-lab-simulator.md "Assertions").

The only write in this module is the one the spec names: `reset_topo()` (topology-4-lab-simulator.md "Reset").
Manual links are created by php/manual_link.php through the UI code path, not here.
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

    def end_parts(nid):
        # (label, Device node id, port name or None): the structured end that an assertion key is matched against (lab spec 8.3)
        n = nodes[nid]
        if n['type'] == 'port':
            return end_label(nid), n['device_id'], n['a'].get('name')
        return end_label(nid), nid, None

    links = []
    for e in rows(conn, 'SELECT * FROM topo_edges ORDER BY id'):
        if e['type'] not in ('physical_link', 'device_link'):
            continue
        a = _j(e['attrs'])
        parts = [end_parts(e['src_id']), end_parts(e['dst_id'])]
        if e['type'] == 'physical_link':
            parts.sort(key=lambda x: x[0])
        ends = [x[0] for x in parts]
        links.append({'id': e['id'], 'type': e['type'], 'ends': ends, 'discovered_via': a.get('discovered_via'),
                      'state': 'superseded' if a.get('superseded_at') else 'active',
                      'far_port_reason': a.get('far_port_reason'), 'attrs': a,
                      'end_devices': [x[1] for x in parts], 'end_ports': [x[2] for x in parts]})
    links.sort(key=lambda l: (l['type'], l['ends']))

    obs = []
    for o in rows(conn, 'SELECT o.*, h.host AS reporter FROM topo_observations o JOIN items i ON i.itemid=o.itemid '
                        'JOIN hosts h ON h.hostid=i.hostid ORDER BY o.id'):
        obs.append({'id': o['id'], 'reporter': o['reporter'], 'port': port_label(o['local_port_id']).split(':', 1)[1],
                    'remote_key': o['remote_key'], 'outcome': o['outcome'], 'edge_id': o['edge_id'],
                    'link_precision': o['link_precision'], 'precision_lower': bool(o['precision_lower']),
                    'device': dev_name(nodes[o['device_id']]) if o['device_id'] in nodes else None, 'device_id': o['device_id']})
    device_nodes = [{'id': n['id'], 'sysname': n['a'].get('sysname'), 'chassis_id': n['a'].get('chassis_id'), 'mac': n['a'].get('mac')}
                    for n in nodes.values() if n['type'] == 'device']
    return {'device_nodes': device_nodes, 'devices': devices, 'ports': ports, 'represented_by': represented, 'links': links, 'observations': obs}


def comparable(state):
    """The part of natural_state() that S0 compares: no ids, no attrs, no observation ids."""
    return {
        'devices': state['devices'],
        'ports': state['ports'],
        'represented_by': state['represented_by'],
        'links': [{'type': l['type'], 'ends': l['ends'], 'discovered_via': l['discovered_via'], 'state': l['state']} for l in state['links']],
    }


def snapshot_clocks(conn):
    return {r['itemid']: r['clock'] for r in rows(conn, 'SELECT itemid, clock FROM topo_lld_snapshot')}


def snapshot_rows(conn):
    """'<host>/<rule>' -> the rows of the topology snapshot (normalized fields, as the server stored them): what ingest reads.
    <rule> is the discovery rule's key without its prefix (topology.lldp and topology.push.lldp are both 'lldp')."""
    out = {}
    for r in rows(conn, 'SELECT s.rows_json, i.key_, h.host FROM topo_lld_snapshot s JOIN items i ON i.itemid=s.itemid '
                        'JOIN hosts h ON h.hostid=i.hostid ORDER BY h.host, i.key_'):
        rule = r['key_'].replace('topology.push.', '').replace('topology.', '')
        out['%s/%s' % (r['host'], rule)] = _j(r['rows_json'])
    return out


def counts(conn):
    out = {}
    for t in ('topo_lld_snapshot', 'topo_nodes', 'topo_edges', 'topo_observations'):
        out[t] = rows(conn, 'SELECT COUNT(*) c FROM %s' % t)[0]['c']
    return out


DISCARD_UNCHANGED_HEARTBEAT = 20       # item_preproc.type "Discard unchanged with heartbeat" (ZBX_PREPROC_THROTTLE_TIMED_VALUE)


def reset_topo(conn, keep_hosts=()):
    """topology-4-lab-simulator.md "Reset": delete every row of every topo_* table, except the topo_lld_snapshot rows of the rules that have the step "Discard unchanged
    with heartbeat" (FR-B D-23): such a rule does not store an unchanged value again, so after a full clean its ports would have no item ids (model §3.2) until the
    heartbeat. The rule is found by its preprocessing step (item_preproc), not by its key. Only the rules of the hosts in `keep_hosts` (the hosts of the scenario)
    keep their row: the snapshot of a host that the scenario does not use would be a reporter in a scenario that does not have it. No `keep_hosts`: nothing stays."""
    cur = conn.cursor()
    cur.execute('SET FOREIGN_KEY_CHECKS = 0')
    for t in ('topo_identity_keys', 'topo_observations', 'topo_edges', 'topo_nodes'):
        cur.execute('DELETE FROM %s' % t)
    keep = list(keep_hosts)
    if keep:
        cur.execute("DELETE FROM topo_lld_snapshot WHERE itemid NOT IN (SELECT i.itemid FROM items i JOIN hosts h ON h.hostid = i.hostid "
                    "JOIN item_preproc p ON p.itemid = i.itemid WHERE p.type = %d AND h.host IN (%s))" % (DISCARD_UNCHANGED_HEARTBEAT, ','.join(['%s'] * len(keep))), keep)
    else:
        cur.execute('DELETE FROM topo_lld_snapshot')
    cur.execute('SET FOREIGN_KEY_CHECKS = 1')


TOPO_TABLES = ('topo_nodes', 'topo_edges', 'topo_identity_keys', 'topo_observations', 'topo_lld_snapshot')


def topo_counts(conn):
    """The row count of each topo_* table (lab spec 8.4: right after --reset)."""
    cur = conn.cursor()
    out = {}
    for t in TOPO_TABLES:
        cur.execute('SELECT COUNT(*) FROM %s' % t)
        out[t] = int(cur.fetchone()[0])
    return out


def port_node_id(conn, device, port):
    r = rows(conn, "SELECT p.id FROM topo_nodes p JOIN topo_nodes d ON d.id=p.device_id WHERE p.type='port' "
                   "AND JSON_UNQUOTE(JSON_EXTRACT(d.attrs,'$.sysname'))=%s AND JSON_UNQUOTE(JSON_EXTRACT(p.attrs,'$.name'))=%s",
             (device, port))
    return r[0]['id'] if r else None


def edge_between(conn, port_a, port_b):
    """The id of the physical_link between two ports (canonical direction), or None."""
    r = rows(conn, "SELECT id FROM topo_edges WHERE type='physical_link' AND src_id=%s AND dst_id=%s ORDER BY id DESC",
             (min(port_a, port_b), max(port_a, port_b)))
    return r[0]['id'] if r else None


def item_history(conn, itemid, n=2):
    """The last n values of an item, newest first: [(clock, value)]. The table follows the value type of the item (float -> history, unsigned -> history_uint)."""
    vt = rows(conn, 'SELECT value_type FROM items WHERE itemid=%s', (itemid,))
    table = 'history' if vt and int(vt[0]['value_type']) == 0 else 'history_uint'
    return [(r['clock'], r['value']) for r in rows(conn, 'SELECT clock, value FROM %s WHERE itemid=%%s ORDER BY clock DESC, ns DESC LIMIT %d' % (table, int(n)), (itemid,))]
