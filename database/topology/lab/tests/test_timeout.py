"""Step timeout (lab spec 8.1): the command line wins over settings.timeout of the lab file; default 120; a bad value is refused.
Run from database/topology/lab/:  python3 -m unittest discover -s tests"""
import os
import sys
import unittest

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

from labsim import model, runner  # noqa: E402
from labsim.model import LabError  # noqa: E402


class StepTimeout(unittest.TestCase):
    def test_default(self):
        self.assertEqual(runner.effective_timeout(None, {}), 120)
        self.assertEqual(runner.effective_timeout(None, {'database': 'zabbix'}), 120)

    def test_the_lab_file_sets_it(self):
        self.assertEqual(runner.effective_timeout(None, {'timeout': 240}), 240)

    def test_the_command_line_wins(self):
        self.assertEqual(runner.effective_timeout(400, {'timeout': 240}), 400)
        self.assertEqual(runner.effective_timeout(60, {}), 60)

    def test_bad_values(self):
        for bad in (0, -5, 'ten', True):
            with self.assertRaises(LabError):
                runner.effective_timeout(None, {'timeout': bad})

    def test_the_office_stand_sets_240(self):
        lab = model.load_lab(os.path.join(os.path.dirname(os.path.dirname(os.path.abspath(__file__))), 'stands', 'office', 'lab-office.yaml'))
        self.assertEqual(runner.effective_timeout(None, lab.settings), 240)
        self.assertEqual(runner.effective_timeout(None, model.load_lab().settings), 120)


if __name__ == '__main__':
    unittest.main()
