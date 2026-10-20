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


if __name__ == '__main__':
    unittest.main()
