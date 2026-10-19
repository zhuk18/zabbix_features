"""--reset closes the open problems with manual close on the hosts tagged lab.managed=1 (lab spec 8.2). A fake API; run from database/topology/lab/: python3 -m unittest discover -s tests"""
import os
import sys
import unittest

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

from labsim import zbx  # noqa: E402


class FakeApi:
    def __init__(self, problems, manual_close, stuck=()):
        self.problems = dict(problems)          # eventid -> (triggerid, name)
        self.manual_close = manual_close        # triggerid -> '0'|'1'
        self.stuck = set(stuck)
        self.calls = []

    def call(self, method, params):
        self.calls.append((method, params))
        if method == 'host.get':
            return [{'hostid': '10'}]
        if method == 'problem.get':
            ids = params.get('eventids')
            return [{'eventid': e, 'objectid': t, 'name': n} for e, (t, n) in self.problems.items() if ids is None or e in ids]
        if method == 'trigger.get':
            return [{'triggerid': t, 'manual_close': self.manual_close[t]} for t in params['triggerids']]
        if method == 'event.acknowledge':
            for e in params['eventids']:
                if e not in self.stuck:
                    self.problems.pop(e, None)
            return {'eventids': params['eventids']}
        raise AssertionError(method)


class CloseProblems(unittest.TestCase):
    def test_lower_speed_problem_is_closed_and_a_non_manual_one_stays(self):
        api = FakeApi({'1': ('100', 'Interface Gi0/1(): Ethernet has changed to lower speed than it was before'), '2': ('101', 'High bandwidth usage')},
                      {'100': '1', '101': '0'})
        r = zbx.close_problems(api, log=lambda m: None, wait=3)
        self.assertEqual(r['count'], 1)
        self.assertEqual(r['triggers'], ['Interface Gi0/1(): Ethernet has changed to lower speed than it was before'])
        self.assertEqual(list(api.problems), ['2'])                      # one open before, none that allows manual close after
        ack = [p for m, p in api.calls if m == 'event.acknowledge']
        self.assertEqual(ack, [{'eventids': ['1'], 'action': 1}])

    def test_a_problem_that_cannot_be_closed_is_reported(self):
        api = FakeApi({'1': ('100', 'x')}, {'100': '1'}, stuck=['1'])
        r = zbx.close_problems(api, log=lambda m: None, wait=2)
        self.assertEqual((r['count'], r['left']), (0, ['x']))

    def test_nothing_open(self):
        r = zbx.close_problems(FakeApi({}, {}), log=lambda m: None)
        self.assertEqual(r, {'count': 0, 'triggers': [], 'left': []})


if __name__ == '__main__':
    unittest.main()
