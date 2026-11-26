"""The bench generator (topology-4-lab-simulator.md 5.6, task 126): the same seed gives the same rows, the counts and the shape. No Zabbix and no database.

Run from database/topology/lab/:  python3 -m unittest discover -s tests
"""
import json
import os
import sys
import unittest

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

from labsim import bench  # noqa: E402

CFG = bench.load_config(os.path.join(os.path.dirname(os.path.dirname(os.path.abspath(__file__))), 'stands', 'bench', 'bench.yaml'), reporters=60)


class Generator(unittest.TestCase):
    def test_same_seed_same_rows(self):
        a = json.dumps(bench.generate(CFG)['rows_at'](0), sort_keys=True)
        b = json.dumps(bench.generate(CFG)['rows_at'](0), sort_keys=True)
        self.assertEqual(a, b)
        self.assertNotEqual(a, json.dumps(bench.generate(dict(CFG, seed=1))['rows_at'](0), sort_keys=True))

    def test_counts_and_rules(self):
        net = bench.generate(CFG)
        rows = net['rows_at'](0)
        self.assertEqual(len(rows), 60)
        self.assertTrue(all(len(r['device']) == 1 and len(r['ports']) == 50 for r in rows.values()))
        self.assertEqual(net['counts']['rows_device'], 60)
        self.assertEqual(net['counts']['ports'], 3000)
        self.assertEqual(sum(1 for r in net['reporters'] if r['kind'] == 'core'), 2)
        # the cdp rule only on a reporter that reports CDP
        self.assertTrue(all(bool(rows[r['host']]['cdp']) <= r['cdp'] for r in net['reporters']))

    def test_row_contract(self):
        rows = bench.generate(CFG)['rows_at'](0)
        for host, rules in rows.items():
            for row in rules['lldp']:
                self.assertTrue(row.get('{#LOC_PORT_NUM}') and (row.get('{#REM_CHASSIS}') or row.get('{#REM_SYSNAME}')), (host, row))
            for row in rules['cdp']:
                self.assertTrue(row.get('{#IFINDEX}') and row.get('{#REM_SYSNAME}'), (host, row))

    def test_shape_cases_are_there(self):
        net = bench.generate(bench.load_config(None, reporters=300))
        rows = net['rows_at'](0)
        lldp = [r for rules in rows.values() for r in rules['lldp']]
        self.assertTrue(any('{#REM_CHASSIS}' not in r for r in lldp), 'rows without a hardware ID')
        self.assertTrue(any(int(r['{#LOC_PORT_NUM}']) > 1000 for r in lldp), 'rows with an unresolved local port')
        self.assertTrue(any(e['junction'] for e in net['ends']), 'junction ports')

    def test_step_2_moves_end_devices(self):
        net = bench.generate(bench.load_config(None, reporters=300))
        a, b = net['rows_at'](0), net['rows_at'](2)
        self.assertGreater(len(net['moved']), 0)
        changed = [h for h in a if a[h]['lldp'] != b[h]['lldp']]
        self.assertGreater(len(changed), len(net['moved']) // 2)
        self.assertEqual(sum(len(r['lldp']) for r in a.values()), sum(len(r['lldp']) for r in b.values()))


if __name__ == '__main__':
    unittest.main()
