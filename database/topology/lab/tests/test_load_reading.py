"""Load reading (lab spec 9 S6): polls 30 s apart, a value only from a pair less than 60 s apart, at most 3 polls, else "no fresh counter pair".
A fake clock and fake history. Run from database/topology/lab/: python3 -m unittest discover -s tests"""
import os
import sys
import unittest
from unittest import mock

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

from labsim import runner  # noqa: E402
from labsim.model import LabError  # noqa: E402


class Clock:
    def __init__(self):
        self.t = 1000.0

    def now(self):
        return self.t

    def sleep(self, s):
        self.t += s


class Fake:
    """Every check-now adds one value per item at the current clock, except for the polls in `drop` (a wrapped counter: the server discards the value)."""
    def __init__(self, clock, drop=(), old=100):
        self.clock, self.drop, self.polls = clock, set(drop), 0
        self.hist = {'1': [(int(clock.t) - old, 5)], '2': [(int(clock.t) - old, 5)]}

    def check_now(self, api, ids):
        self.polls += 1
        if self.polls not in self.drop:
            for i in ids:
                self.hist[i].insert(0, (int(self.clock.t), 7))

    def history(self, conn, itemid, n=2):
        return self.hist[itemid][:n]


class Reading(unittest.TestCase):
    def run_read(self, **kw):
        c = Clock()
        f = Fake(c, **kw)
        with mock.patch.object(runner.zbx, 'check_now', f.check_now), mock.patch.object(runner.db, 'item_history', f.history):
            return runner.read_fresh_counters(None, None, ['1', '2'], sleep=c.sleep, now=c.now), f

    def test_pair_after_two_polls(self):
        # the previous value is 100 s old: poll 1 gives a value 100 s after it (not a pair); poll 2, 30 s later, gives the pair
        polls, f = self.run_read()
        self.assertEqual(polls, 2)

    def test_a_discarded_first_value_needs_a_third_poll(self):
        polls, f = self.run_read(drop=[1])
        self.assertEqual(polls, 3)

    def test_no_pair_after_three_polls(self):
        c = Clock()
        f = Fake(c, drop=[2, 3])
        with mock.patch.object(runner.zbx, 'check_now', f.check_now), mock.patch.object(runner.db, 'item_history', f.history):
            with self.assertRaisesRegex(LabError, 'no fresh counter pair'):
                runner.read_fresh_counters(None, None, ['1', '2'], sleep=c.sleep, now=c.now)
        self.assertEqual(f.polls, 3)


if __name__ == '__main__':
    unittest.main()


class WaitUntil(unittest.TestCase):
    def test_until_needs_seconds_and_a_bool(self):
        from labsim import model
        model.validate_wait('X', 0, {'seconds': 900, 'until': True})
        for bad in ({'until': True}, {'seconds': 900, 'until': 'yes'}):
            with self.assertRaises(LabError):
                model.validate_wait('X', 0, bad)

    def test_s6_waits_are_poll_until(self):
        from labsim import model
        steps = model.load_scenario('S6').steps
        self.assertTrue(all((s.get('wait') or {}).get('until') for s in steps))
