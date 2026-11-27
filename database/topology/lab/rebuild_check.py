#!/usr/bin/env python3
"""The rebuild property of the operator's decisions on a lab scenario (topology-1-model.md §6.7.8 "Rebuild property"; task 129; cases C-022 and the merge cases).

Usage: rebuild_check.py            on the state that is in the database now (R1 after `labsim run R1 --reset`, or D1 after `labsim ... run D1 --reset`, with its operator steps: the merge of
                                   the MikroTik, the Device made by hand and its manual link)
**This check reads the database directly** (the state that it compares is in the tables: it checks storage), and it deletes the topology tables (the lab database `zabbix` only).

1. the state is saved: lab/php/rebuild_view.php (the Devices by natural key: their keys, chassis, replaced chassis, representation, duplicate candidates, dismissed pairs; the active links);
2. the topology tables are deleted except `topo_decision`, the manual links and acknowledgments (a manual link and its acknowledgments are operator data that no snapshot gives, and the Devices and
   Ports at its ends, the manual Devices and what they are represented by stay with them);
3. one run of ingest over the snapshots that are there;
4. the state is read again and compared. It must be equal. The number of records of each kind is printed; `nodeid`, `last_seen*`, superseded links and observations may differ.
Exit code 0 when equal, 1 when not (the differences are printed), 2 when the state cannot be checked.
"""
import json
import os
import subprocess
import sys

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, HERE)
sys.path.insert(0, os.path.dirname(HERE))
from labsim import db, runner, model  # noqa: E402
import topology_json as tj  # noqa: E402      (the SQL conditions on JSON attrs of the topology code: model §18)

SETTINGS = {'database': 'zabbix', 'db_socket': '/run/mysqld/mysqld.sock'}


def view():
    out = subprocess.run(['php', os.path.join(HERE, 'php', 'rebuild_view.php')], capture_output=True, text=True)
    if out.returncode != 0:
        print('rebuild_view.php failed: %s' % (out.stderr or out.stdout)[:300])
        sys.exit(2)
    return json.loads(out.stdout)


def main():
    lab = model.load_lab(os.path.join(HERE, 'stands', 'corpus', 'corpus.yaml'))      # only the settings of the lab database are used
    conn = db.connect(lab.settings)
    cur = conn.cursor()
    cur.execute("SELECT kind, COUNT(*) FROM topo_decision GROUP BY kind")
    records = dict(cur.fetchall())
    before = view()
    print('records: %s; %d Devices, %d active links' % (records or 'none', len(before['devices']), len(before['links'])))
    # keep: the manual Devices, the Devices at the end of a manual link, their Ports, and what they are represented by
    cur.execute("SELECT id FROM topo_nodes WHERE type = 'device' AND " + tj.text('attrs', '$.source') + " = 'manual'")
    keep_devices = {r[0] for r in cur.fetchall()}
    cur.execute("SELECT e.src_id, e.dst_id, e.type, s.device_id, d.device_id, d.type FROM topo_edges e JOIN topo_nodes s ON s.id = e.src_id LEFT JOIN topo_nodes d ON d.id = e.dst_id "
                "WHERE " + tj.text('e.attrs', '$.discovered_via') + " = 'manual'")
    manual_edges = []
    for src, dst, etype, src_device, dst_device, dst_type in cur.fetchall():
        manual_edges.append((src, dst))
        keep_devices.add(src_device)
        keep_devices.add(dst if etype == 'device_link' else dst_device)
    keep_devices.discard(None)
    keep_nodes = set(keep_devices)
    if keep_devices:
        marks = ','.join(['%s'] * len(keep_devices))
        cur.execute('SELECT id FROM topo_nodes WHERE device_id IN (%s)' % marks, tuple(keep_devices))
        keep_nodes |= {r[0] for r in cur.fetchall()}
        cur.execute('SELECT represented_by_node_id FROM topo_nodes WHERE id IN (%s) AND represented_by_node_id IS NOT NULL' % marks, tuple(keep_devices))
        keep_nodes |= {r[0] for r in cur.fetchall()}
    print('kept as they are: %d Devices (manual Devices and the ends of %d manual links), %d nodes' % (len(keep_devices), len(manual_edges), len(keep_nodes)))
    cur.execute('SET FOREIGN_KEY_CHECKS = 0')
    cur.execute('DELETE FROM topo_observations')
    if keep_nodes:
        marks = ','.join(['%s'] * len(keep_nodes))
        cur.execute("DELETE FROM topo_edges WHERE (" + tj.text('attrs', '$.discovered_via') + " IS NULL OR " + tj.text('attrs', '$.discovered_via') + " <> 'manual')")
        cur.execute('DELETE FROM topo_identity_keys WHERE device_id NOT IN (%s)' % ','.join(['%s'] * len(keep_devices)), tuple(keep_devices)) if keep_devices else cur.execute('DELETE FROM topo_identity_keys')
        cur.execute('DELETE FROM topo_nodes WHERE id NOT IN (%s)' % marks, tuple(keep_nodes))
    else:
        cur.execute('DELETE FROM topo_edges')
        cur.execute('DELETE FROM topo_identity_keys')
        cur.execute('DELETE FROM topo_nodes')
    cur.execute('SET FOREIGN_KEY_CHECKS = 1')
    conn.commit()
    st = runner.run_ingest(SETTINGS)
    summary = st.get('summary') or {}
    print('run after the delete: %s' % {k: summary.get(k) for k in ('devices_created', 'links_created', 'reporters_processed')})
    for key in ('decisions_conflict', 'decisions_ambiguous', 'decisions_not_applied'):
        if summary.get(key):
            print('  %s: %s' % (key, json.dumps(summary[key])))
    after = view()
    bad = {}
    # A Device that no current snapshot names stays in the stored state (ingest never deletes a Device, §8.7 "not seen") and cannot come back from the snapshots: it is listed, and it must have no
    # active link (an active link is given by the snapshots). Every other Device must be equal.
    not_seen = [nk for nk in sorted(before['devices']) if nk not in after['devices']]
    supported = [nk for nk in not_seen if any(nk in link for link in before['links'])]
    if not_seen:
        print('not seen (in the stored state, no snapshot names them): %d Devices%s' % (len(not_seen), '' if not supported else ', but %d of them have an active link: %s' % (len(supported), supported[:5])))
    for nk in sorted(set(before['devices']) | set(after['devices'])):
        if nk in not_seen and nk not in supported:
            continue
        if before['devices'].get(nk) != after['devices'].get(nk):
            bad[nk] = (before['devices'].get(nk), after['devices'].get(nk))
    only_before = sorted(set(before['links']) - set(after['links']))
    only_after = sorted(set(after['links']) - set(before['links']))
    if bad or only_before or only_after:
        print('NOT EQUAL: %d Devices differ, %d links only before, %d links only after' % (len(bad), len(only_before), len(only_after)))
        for nk, (b, a) in list(bad.items())[:10]:
            print('  device %s\n    before %s\n    after  %s' % (nk, json.dumps(b), json.dumps(a)))
        for l in only_before[:10]:
            print('  link only before: %s' % l)
        for l in only_after[:10]:
            print('  link only after:  %s' % l)
        return 1
    print('EQUAL: %d Devices (by natural key, with their keys, replaced chassis, representation, candidates and dismissed pairs) and %d active links' % (len(after['devices']), len(after['links'])))
    return 0


if __name__ == '__main__':
    sys.exit(main())
