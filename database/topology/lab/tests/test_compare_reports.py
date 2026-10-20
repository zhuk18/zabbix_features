"""`labsim compare-reports` (lab spec 8.4): a field that only one report has is a "new field", not a difference; command line and reset counts are ignored.
Run from database/topology/lab/:  python3 -m unittest discover -s tests"""
import os
import sys
import unittest

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

from labsim import runner  # noqa: E402


def diff(a, b):
    return runner.diff_reports(runner.normalize_report(a), runner.normalize_report(b))


class CompareReports(unittest.TestCase):
    def test_equal(self):
        r = {'steps': [{'step': 0, 'summary': {'devices_created': 3}}]}
        self.assertEqual(diff(r, r), ([], []))

    def test_a_field_of_one_report_only_is_a_new_field(self):
        old = {'steps': [{'step': 0, 'summary': {'devices_created': 3}}]}
        new = {'steps': [{'step': 0, 'summary': {'devices_created': 3, 'rows_duplicate_claim': {'count': 0}}}]}
        self.assertEqual(diff(old, new), ([], [('/steps[0]/summary/rows_duplicate_claim', 'b')]))
        self.assertEqual(diff(new, old), ([], [('/steps[0]/summary/rows_duplicate_claim', 'a')]))

    def test_a_changed_value_is_a_difference_even_with_a_new_field(self):
        old = {'steps': [{'step': 0, 'summary': {'devices_created': 3}}]}
        new = {'steps': [{'step': 0, 'summary': {'devices_created': 4, 'rows_duplicate_claim': {'count': 0}}}]}
        diffs, new_fields = diff(old, new)
        self.assertEqual(diffs, ['/steps[0]/summary/devices_created'])
        self.assertEqual(len(new_fields), 1)

    def test_lists_of_other_length_are_a_difference(self):
        self.assertEqual(diff({'steps': [1]}, {'steps': [1, 2]})[0], ['/steps'])

    def test_command_line_and_reset_counts_are_not_compared(self):
        a = {'command_line': ['labsim', 'run', 'S1', '--reset'], 'topo_rows_after_reset': {'topo_nodes': 0}, 'steps': []}
        b = {'command_line': ['labsim', 'run', 'S1'], 'steps': []}
        self.assertEqual(diff(a, b), ([], []))


class NaturalKeys(unittest.TestCase):
    """Lab spec 8.4 (task 041): an item id is compared as <host>:<item key>, also as a key of a map; a node id by the natural key of the node."""

    def report(self, itemid, kept, names=True):
        step = {'step': 0, 'waited_for': ['SW18 core rule %d' % (itemid - 1), 'SW18 core rule %d' % itemid],
                'ingest_status': {'summary': {'rows_duplicate_claim': {'count': 1, 'by_rule': {str(itemid): 1}},
                                              'devices_joined': {'count': 1, 'list': [{'kept': kept, 'removed': kept + 1}]}}}}
        if names:
            step['id_names'] = {'items': {str(itemid - 1): 'SW18:topology.lldp', str(itemid): 'SW18:topology.cdp'},
                                'nodes': {str(kept): 'gw1/84:39:8f:37:3a:7c', str(kept + 1): '/'}}
        return {'scenario': 'R1', 'steps': [step]}

    def compare(self, a, b, current=None):
        return runner.diff_reports(runner.normalize_report(runner.name_ids(a, current)), runner.normalize_report(runner.name_ids(b, current)))

    def test_different_item_and_node_ids_of_the_same_rule_and_device_are_equal(self):
        self.assertEqual(self.compare(self.report(103163, 500), self.report(110069, 900)), ([], []))

    def test_another_rule_is_a_difference(self):
        a = self.report(103163, 500)
        b = self.report(110069, 900)
        b['steps'][0]['id_names']['items']['110069'] = 'SW18:topology.lldp'      # the count belongs to another rule now
        diffs, new = self.compare(a, b)
        self.assertEqual(diffs, [])
        self.assertEqual(sorted(n[0] for n in new), ['/steps[0]/ingest_status/summary/rows_duplicate_claim/by_rule/SW18:topology.cdp',
                                                      '/steps[0]/ingest_status/summary/rows_duplicate_claim/by_rule/SW18:topology.lldp'])

    def test_old_reports_without_names_use_host_kind_and_rank(self):
        # the second item of SW18's core rules in both; the key from the current provisioning
        current = {('SW18', 'core'): ['topology.lldp', 'topology.cdp']}
        a, b = self.report(103163, 500, names=False), self.report(110069, 900, names=False)
        named = runner.name_ids(a, current)
        self.assertEqual(named['steps'][0]['ingest_status']['summary']['rows_duplicate_claim']['by_rule'], {'SW18:topology.cdp': 1})
        diffs, new = self.compare(a, b, current)
        self.assertEqual(new, [])
        self.assertEqual(diffs, ['/steps[0]/ingest_status/summary/devices_joined/list[0]/kept',
                                 '/steps[0]/ingest_status/summary/devices_joined/list[0]/removed'])      # node ids of old reports have no names

    def test_without_current_rules_the_rank_names_the_rule(self):
        named = runner.name_ids(self.report(103163, 500, names=False))
        self.assertEqual(named['steps'][0]['ingest_status']['summary']['rows_duplicate_claim']['by_rule'], {'SW18:core#1': 1})


if __name__ == '__main__':
    unittest.main()
