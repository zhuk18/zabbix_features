"""C1 (task 127): the family of a walk, the kind of an identity key, the baseline and the compare mode. No Zabbix and no database; no corpus file is read.

Run from database/topology/lab/:  python3 -m unittest discover -s tests
"""
import json
import os
import sys
import tempfile
import unittest

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

from labsim import c1  # noqa: E402


def walk(name, **extra):
    base = {'file': name, 'family': c1.family(name), 'status': 'ok', 'rules': {'topology.lldp': {'state': 'snapshot', 'error': '', 'rows_total': 3, 'rows_valid': 3, 'rows_identity_invalid': 0}},
            'own_identity': ['chassis:mac'], 'observations': {'applied': 3}, 'unresolved': 0, 'unresolved_by_reason': {}, 'no_identity': 0, 'identity_invalid': 0, 'duplicate_claim': 0,
            'devices_created': 4, 'ports_created': 10, 'links_created': 3, 'seconds': 40.1, 'server_log': []}
    base.update(extra)
    return base


class C1(unittest.TestCase):
    def test_family_and_key_kind(self):
        self.assertEqual(c1.family('dlink_dgs-1510-28x-me'), 'dlink')
        self.assertEqual(c1.family('aos'), 'aos')
        self.assertEqual(c1.key_kind('chassis:mac:aa:bb:cc:dd:ee:ff'), 'chassis:mac')
        self.assertEqual(c1.key_kind('chassis:local:server:abc'), 'chassis:local')
        self.assertEqual(c1.key_kind('cdp:server:core-sw1'), 'cdp')

    def test_baseline_and_compare(self):
        with tempfile.TemporaryDirectory() as d:
            result, base = os.path.join(d, 'r.json'), os.path.join(d, 'b.json')
            json.dump({'walks': [walk('aos'), walk('zynos')], 'complete': True, 'seconds': 100}, open(result, 'w'))
            self.assertEqual(c1.baseline(result, base), 2)
            self.assertEqual(c1.compare(result, base), [])           # the same code: nothing
            changed = walk('zynos', observations={'applied': 2, 'local_port_unresolved': 1}, seconds=99.0)     # a time is no difference
            json.dump({'walks': [walk('aos', seconds=1), changed], 'complete': True}, open(result, 'w'))
            lines = c1.compare(result, base)
            self.assertEqual(len(lines), 1)
            self.assertIn('zynos: observations', lines[0])
            json.dump({'walks': [walk('aos')], 'complete': True}, open(result, 'w'))
            self.assertEqual(c1.compare(result, base), ['zynos: not in the result'])

    def test_incomplete_result_is_no_baseline(self):
        with tempfile.TemporaryDirectory() as d:
            result = os.path.join(d, 'r.json')
            json.dump({'walks': [walk('aos')], 'complete': False}, open(result, 'w'))
            with self.assertRaises(Exception):
                c1.baseline(result, os.path.join(d, 'b.json'))

    def test_core_list_follows_the_rule(self):
        # task 135 part 3: stands/corpus/core.txt is the walk with the most neighbor rows of each family (a family with none has none) and the six named walks
        walks = {'a_big': walk('a_big', rules={'topology.lldp': {'rows_total': 5}, 'topology.cdp': {'rows_total': 2}}),
                 'a_small': walk('a_small', rules={'topology.lldp': {'rows_total': 6}}),
                 'b_one': walk('b_one', rules={'topology.lldp': {'rows_total': 0}}),
                 'c_x': walk('c_x', rules={'topology.cdp': {'rows_total': 1}}), 'c_y': walk('c_y', rules={'topology.cdp': {'rows_total': 1}})}
        self.assertEqual(sorted(set(c1.core_walks(walks)) - set(c1.CORE_EXTRA)), ['a_big', 'c_x'])
        with open(c1.BASELINE) as f:
            self.assertEqual(c1.core_list(), c1.core_walks(json.load(f)['walks']))

    def test_core_compare_uses_its_own_baseline(self):
        with tempfile.TemporaryDirectory() as d:
            result, base = os.path.join(d, 'r.json'), os.path.join(d, 'b.json')
            json.dump({'walks': [walk('aos')], 'complete': True}, open(result, 'w'))
            c1.baseline(result, base)
            self.assertEqual(c1.compare(result, base), [])


if __name__ == '__main__':
    unittest.main()
