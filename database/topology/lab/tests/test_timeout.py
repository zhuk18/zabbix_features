"""Snapshot wait (lab spec 8.1, task 040): the timeout counts per master item, from its own "check now"; default 60 s; settings.timeout of the lab file
changes it, the command line wins; a bad value is refused.
Run from database/topology/lab/:  python3 -m unittest discover -s tests"""
import os
import sys
import unittest

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

from labsim import model, runner  # noqa: E402
from labsim.model import LabError  # noqa: E402


class FakeClock:
    """Time that moves only when the code sleeps; a rule's snapshot clock advances at a given time."""

    def __init__(self):
        self.t = 1000.0

    def now(self):
        return self.t

    def sleep(self, s):
        self.t += s


class StepTimeout(unittest.TestCase):
    def test_default(self):
        self.assertEqual(runner.effective_timeout(None, {}), 60)
        self.assertEqual(runner.effective_timeout(None, {'database': 'zabbix'}), 60)

    def test_the_lab_file_sets_it(self):
        self.assertEqual(runner.effective_timeout(None, {'timeout': 90}), 90)

    def test_the_command_line_wins(self):
        self.assertEqual(runner.effective_timeout(400, {'timeout': 90}), 400)
        self.assertEqual(runner.effective_timeout(30, {}), 30)

    def test_bad_values(self):
        for bad in (0, -5, 'ten', True):
            with self.assertRaises(LabError):
                runner.effective_timeout(None, {'timeout': bad})

    def test_the_office_stand_uses_the_default(self):
        here = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
        lab = model.load_lab(os.path.join(here, 'stands', 'office', 'lab-office.yaml'))
        self.assertNotIn('timeout', lab.settings)
        self.assertEqual(runner.effective_timeout(None, lab.settings), 60)


class WaitPerMasterItem(unittest.TestCase):
    def wait(self, clock, arrive_after, timeout=60):
        """One master item: its rule 7 gets a new snapshot `arrive_after` seconds after the check now (None: never)."""
        start = clock.now()
        clocks = lambda: {7: 2 if arrive_after is not None and clock.now() >= start + arrive_after else 1}
        return runner.wait_for_clocks({7: 'dev core rule 7'}, {7: 1}, clocks, timeout, now=clock.now, sleep=clock.sleep)

    def test_a_rule_that_advances_in_time(self):
        self.assertEqual(self.wait(FakeClock(), 5), {})

    def test_a_rule_that_never_advances_times_out_after_the_timeout(self):
        clock = FakeClock()
        self.assertEqual(self.wait(clock, None), {7: 'dev core rule 7'})
        self.assertAlmostEqual(clock.now() - 1000.0, 60, delta=0.5)

    def test_the_deadline_of_item_n_does_not_depend_on_the_items_before_it(self):
        # 28 master items, each answers after 5 s (the office stand): 140 s in all, more than one timeout of 60 s; none times out
        clock = FakeClock()
        for _ in range(28):
            self.assertEqual(self.wait(clock, 5), {})
        self.assertGreater(clock.now() - 1000.0, 60 * 2)
        # after a slow item that used its whole wait, the next one still has its own full 60 s
        self.assertEqual(self.wait(clock, None), {7: 'dev core rule 7'})
        self.assertEqual(self.wait(clock, 59), {})

    def test_the_value_from_the_lab_file_and_the_command_line_is_the_wait(self):
        for cli, settings, ok_after, late in ((None, {'timeout': 90}, 80, 95), (30, {'timeout': 90}, 25, 35)):
            t = runner.effective_timeout(cli, settings)
            self.assertEqual(self.wait(FakeClock(), ok_after, t), {})
            self.assertEqual(self.wait(FakeClock(), late, t), {7: 'dev core rule 7'})


if __name__ == '__main__':
    unittest.main()
