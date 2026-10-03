"""The freshness macro override and its restore (topology-4-lab-simulator.md "Freshness threshold override"), the `wait` forms
(topology-4-lab-simulator.md "Scenario format") and the assertions that read derived state (stale, contradiction, badge).
No Zabbix, no database: a fake API and a fake helper.

Run from database/topology/lab/:  python3 -m unittest discover -s tests
"""
import copy
import os
import sys
import tempfile
import unittest

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

from labsim import model, runner, zbx  # noqa: E402
from labsim.model import LabError  # noqa: E402
from test_gen import BASE  # noqa: E402


class FakeApi:
    """usermacro.* over one dict: {macro: (id, value)}."""

    def __init__(self, value=None):
        self.macros = {} if value is None else {zbx.FRESH_MACRO: ('7', value)}
        self.calls = []

    def call(self, method, params):
        self.calls.append(method)
        if method == 'usermacro.get':
            m = self.macros.get(params['filter']['macro'])
            return [{'globalmacroid': m[0], 'value': m[1]}] if m else []
        if method == 'usermacro.createglobal':
            self.macros[params['macro']] = ('8', params['value'])
            return {'globalmacroids': ['8']}
        if method == 'usermacro.updateglobal':
            for k, (i, _) in list(self.macros.items()):
                if i == params['globalmacroid']:
                    self.macros[k] = (i, params['value'])
            return {'globalmacroids': [params['globalmacroid']]}
        if method == 'usermacro.deleteglobal':
            self.macros = {k: v for k, v in self.macros.items() if v[0] not in params}
            return {'globalmacroids': params}
        raise AssertionError(method)


def quiet(*_):
    pass


class FreshnessMacro(unittest.TestCase):
    def setUp(self):
        self.dir = tempfile.TemporaryDirectory()
        self.file = os.path.join(self.dir.name, 'freshness.json')

    def tearDown(self):
        self.dir.cleanup()

    def test_absent_macro_is_created_and_deleted_again(self):
        api = FakeApi()
        before, during = zbx.set_fresh_macro(api, 60, self.file, quiet)
        self.assertEqual((before, during), ('absent', '60'))
        self.assertTrue(os.path.exists(self.file))
        self.assertEqual(zbx.restore_fresh_macro(api, self.file, quiet), 'absent')
        self.assertEqual(api.macros, {})
        self.assertFalse(os.path.exists(self.file))

    def test_existing_value_is_set_and_restored(self):
        api = FakeApi('3600')
        before, during = zbx.set_fresh_macro(api, 60, self.file, quiet)
        self.assertEqual((before, during), ('3600', '60'))
        self.assertEqual(zbx.restore_fresh_macro(api, self.file, quiet), '3600')
        self.assertEqual(api.macros[zbx.FRESH_MACRO][1], '3600')

    def test_a_killed_run_is_restored_by_the_next_one(self):
        api = FakeApi('3600')
        zbx.set_fresh_macro(api, 60, self.file, quiet)           # ... the run is killed here, nothing restores
        before, _ = zbx.set_fresh_macro(api, 90, self.file, quiet)
        self.assertEqual(before, '3600', 'the second run records the ORIGINAL value, not the leftover 60')
        zbx.restore_fresh_macro(api, self.file, quiet)
        self.assertEqual(api.macros[zbx.FRESH_MACRO][1], '3600')

    def test_nothing_to_restore(self):
        self.assertIsNone(zbx.restore_fresh_macro(FakeApi(), self.file, quiet))


def scenario(**extra):
    data = {'id': 'T', 'steps': [{'id': 0, 'assert': []}]}
    data.update(extra)
    return model.Scenario(data, model.Lab(copy.deepcopy(BASE)))


class ScenarioFormat(unittest.TestCase):
    def test_freshness_seconds(self):
        self.assertIsNone(scenario().freshness_seconds)
        self.assertEqual(scenario(settings={'freshness_seconds': 60}).freshness_seconds, 60)
        for bad in (59, 0, '60', 60.5, True):
            with self.assertRaisesRegex(LabError, 'freshness_seconds'):
                scenario(settings={'freshness_seconds': bad})

    def test_wait_forms(self):
        for ok in ({'seconds': 90}, {'unsupported': ['A'], 'timeout': 120}, {'unsupported': ['A'], 'seconds': 5}):
            scenario(steps=[{'id': 0, 'wait': ok}])
        for bad in ({'seconds': -1}, {'seconds': 'x'}, {'sec': 5}, {'seconds': True}, 90):
            with self.assertRaisesRegex(LabError, 'wait'):
                scenario(steps=[{'id': 0, 'wait': bad}])

    def test_assertion_keys(self):
        import yaml
        ok = [{'badge': {'node': 'UPS1', 'present': False}}, {'link': ['A:p', 'B'], 'state': 'stale'}, {'pending': 'x'},
              {'observation': {'reporter': 'A', 'port': 'p'}, 'outcome': 'applied', 'precision_lower': True}]
        scenario(steps=[{'id': 0, 'assert': ok}])
        # the YAML 1.1 surprise: an unquoted `on` key is the boolean True
        surprise = yaml.safe_load('badge: {on: UPS1, present: false}')
        self.assertIn(True, surprise['badge'])
        for bad, msg in ((surprise, 'not a string'), ({'badge': {'node': 'U', 'on': 'U'}}, "unknown key 'on'"),
                         ({'badge': {'on': 'U'}}, "unknown key 'on'"), ({'link': ['A', 'B'], 'sate': 'active'}, "unknown key 'sate'"),
                         ({'nothing': 1}, 'exactly one'), ({'badge': {}, 'pending': 'x'}, 'exactly one'), ('badge', 'mapping')):
            with self.assertRaisesRegex(LabError, msg):
                scenario(steps=[{'id': 0, 'assert': [bad]}])

    def test_shipped_scenarios_load(self):
        for sid in ('S0', 'S1', 'S2', 'S3', 'S4'):
            model.load_scenario(sid)

    def test_s4_loads(self):
        s = model.load_scenario('S4')
        self.assertEqual(s.freshness_seconds, 60)
        self.assertEqual(s.steps[1]['wait'], {'seconds': 90})


def link(id_, ends, state='active', type_='physical_link'):
    return {'id': id_, 'type': type_, 'ends': ends, 'discovered_via': 'lldp', 'state': state, 'far_port_reason': None, 'attrs': {}}


STATE = {'links': [link(1, ['Switch1:Gi0/4', 'Printer1:Gi0/1']), link(2, ['Router1:Gi0/1', 'UPS1:eth0']),
                   link(3, ['Switch1:Gi0/5', 'Printer1:Gi0/1'], 'superseded')], 'observations': []}
DERIVED = {'links': [{'edge_id': '1', 'stale': True, 'superseded_at': None}, {'edge_id': '2', 'stale': False, 'superseded_at': None},
                     {'edge_id': '3', 'stale': True, 'superseded_at': 5}],
           'contradictions': [{'edge_id': '2', 'contradicted': True, 'hidden_neighbors': ['Printer1', 'Switch2'],
                               'acknowledged_neighbors': []}],
           'badges': {'Printer1': ['2'], 'Switch2': ['2']}}


def ev(a, derived=DERIVED):
    return runner.evaluate(a, STATE, {}, {}, {}, 0, lambda: derived)


class DerivedAssertions(unittest.TestCase):
    def test_stale(self):
        self.assertEqual(ev({'link': ['Switch1:Gi0/4', 'Printer1'], 'state': 'stale'}).status, 'pass')
        self.assertEqual(ev({'link': ['Router1:Gi0/1', 'UPS1'], 'state': 'stale'}).status, 'fail')
        self.assertEqual(ev({'link': ['Switch1:Gi0/5', 'Printer1'], 'state': 'stale'}).status, 'fail', 'a superseded link is not "stale"')

    def test_stale_flag_with_a_state(self):
        self.assertEqual(ev({'link': ['Router1:Gi0/1', 'UPS1'], 'state': 'active', 'stale': False}).status, 'pass')
        self.assertEqual(ev({'link': ['Switch1:Gi0/4', 'Printer1'], 'state': 'active', 'stale': False}).status, 'fail')
        self.assertEqual(ev({'link': ['Switch1:Gi0/4', 'Printer1'], 'state': 'active', 'stale': True}).status, 'pass')

    def test_the_helper_is_not_called_without_need(self):
        def boom():
            raise AssertionError('the helper must not be called')
        r = runner.evaluate({'link': ['Router1:Gi0/1', 'UPS1'], 'state': 'active'}, STATE, {}, {}, {}, 0, boom)
        self.assertEqual(r.status, 'pass')

    def test_contradiction(self):
        a = {'contradiction': {'link': ['Router1:Gi0/1', 'UPS1:eth0'], 'hidden_neighbors': ['Switch2', 'Printer1']}}
        self.assertEqual(ev(a).status, 'pass')
        a['contradiction']['hidden_neighbors'] = ['Printer1']
        self.assertEqual(ev(a).status, 'fail')
        self.assertEqual(ev({'contradiction': {'link': ['Switch1:Gi0/4', 'Printer1'], 'hidden_neighbors': []}}).status, 'pass')
        self.assertEqual(ev({'contradiction': {'link': ['Router1:Gi0/1', 'UPS1'], 'hidden_neighbors': []}}).status, 'fail')
        self.assertEqual(ev({'contradiction': {'link': ['A:x', 'B:y'], 'hidden_neighbors': []}}).status, 'fail', 'no such link')

    def test_badge(self):
        self.assertEqual(ev({'badge': {'node': 'Printer1', 'present': True}}).status, 'pass')
        self.assertEqual(ev({'badge': {'node': 'UPS1', 'present': False}}).status, 'pass')
        self.assertEqual(ev({'badge': {'node': 'UPS1', 'present': True}}).status, 'fail')
        self.assertEqual(ev({'badge': {'node': 'Printer1', 'present': False}}).status, 'fail')

    def test_no_assertion_is_pending_any_more(self):
        for a in ({'link': ['Switch1:Gi0/4', 'Printer1'], 'state': 'stale'}, {'badge': {'node': 'UPS1', 'present': False}},
                  {'contradiction': {'link': ['Router1:Gi0/1', 'UPS1'], 'hidden_neighbors': ['Printer1', 'Switch2']}}):
            self.assertNotEqual(ev(a).status, 'pending')


if __name__ == '__main__':
    unittest.main()
