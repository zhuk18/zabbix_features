"""Show mode and stands (lab spec 2, 6.4a, 7; task 104): the stand name, the lab file of a scenario, the refusal while a stand is up, the `lab.stand` tag and the stand limit of `provision --prune`,
`--step` (steps 0 to n, no assertion), the summary. Run from database/topology/lab/:  python3 -m unittest discover -s tests"""
import os
import sys
import unittest
from unittest import mock

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

from labsim import ctl, model, runner, show, zbx  # noqa: E402
from labsim.model import LabError  # noqa: E402


class StandNames(unittest.TestCase):
    def test_the_stand_is_the_lab_file_name_without_yaml(self):
        self.assertEqual(model.stand_name('stands/office/lab-office.yaml'), 'lab-office')
        self.assertEqual(model.stand_name('lab.yaml'), 'lab')
        self.assertEqual(model.stand_name('/x/stands/office-demo/lab-office-demo.yaml'), 'lab-office-demo')

    def test_load_lab_sets_the_stand(self):
        self.assertEqual(model.load_lab().stand, 'lab')
        lab = model.load_lab(os.path.join(model.HERE, 'stands', 'office-demo', 'lab-office-demo.yaml')) if os.path.exists(os.path.join(model.HERE, 'stands', 'office-demo', 'data')) else None
        if lab:
            self.assertEqual(lab.stand, 'lab-office-demo')

    def test_a_lab_file_is_a_path_or_a_name_in_a_stand(self):
        self.assertTrue(model.find_lab_file('lab-office.yaml').endswith(os.path.join('stands', 'office', 'lab-office.yaml')))
        self.assertTrue(model.find_lab_file('lab-office-demo').endswith(os.path.join('stands', 'office-demo', 'lab-office-demo.yaml')))
        self.assertEqual(model.find_lab_file(os.path.join(model.HERE, 'lab.yaml')), os.path.join(model.HERE, 'lab.yaml'))
        with self.assertRaisesRegex(LabError, 'no lab file'):
            model.find_lab_file('lab-nothing.yaml')

    def test_the_lab_file_of_a_scenario(self):
        self.assertTrue(model.lab_file_of_scenario('S0').endswith('lab.yaml'))
        self.assertTrue(model.lab_file_of_scenario('R1').endswith(os.path.join('stands', 'office', 'lab-office.yaml')))
        self.assertTrue(model.lab_file_of_scenario('D1').endswith(os.path.join('stands', 'office-demo', 'lab-office-demo.yaml')))
        with self.assertRaisesRegex(LabError, 'no scenario'):
            model.lab_file_of_scenario('NOPE')


class Refusal(unittest.TestCase):
    STATE = {'scenario': 'D1', 'step': 3, 'stand': 'lab-office-demo', 'mode': 'show', 'devices': {'A': {'pid': os.getpid()}}}

    def test_show_while_a_stand_is_up_names_the_stand_and_the_command(self):
        with mock.patch.object(ctl, 'load_state', return_value=self.STATE):
            with self.assertRaises(LabError) as raised:
                show.refuse_if_up()
        text = str(raised.exception)
        self.assertIn('lab-office-demo', text)
        self.assertIn('labsim down', text)
        self.assertIn('step 3', text)

    def test_nothing_up_no_refusal(self):
        with mock.patch.object(ctl, 'load_state', return_value=None):
            show.refuse_if_up()
        dead = dict(self.STATE, devices={'A': {'pid': 2 ** 22 + 12345}})
        with mock.patch.object(ctl, 'load_state', return_value=dead):
            show.refuse_if_up()

    def test_status_names_the_stand_and_the_mark(self):
        st = dict(self.STATE, devices={'A': {'pid': os.getpid(), 'ip': '192.0.2.2', 'port': 1611, 'community': 'c', 'fault': None, 'mux': {}}})
        with mock.patch.object(ctl, 'load_state', return_value=st):
            got = ctl.status()
        self.assertEqual((got['stand'], got['mode'], got['scenario'], got['step']), ('lab-office-demo', 'show', 'D1', 3))


class FakeApi:
    """The calls of provision(): enough of the Zabbix API for one lab with two hosts."""
    def __init__(self, hosts):
        self.hosts = hosts           # name -> {'hostid', 'tags', ...}
        self.calls = []

    def call(self, method, params):
        self.calls.append((method, params))
        if method == 'hostgroup.get':
            return [{'groupid': '1'}]
        if method == 'template.get':
            return [{'templateid': {'Topology by SNMP': '10', 'Topology by push': '11', 'Network Generic Device by SNMP': '12'}.get(params['filter']['host'][0], '13'), 'host': params['filter']['host'][0]}]
        if method == 'discoveryrule.get':
            return [{'itemid': '1', 'key_': 'net.if.discovery', 'topology_type': '1'}]
        if method == 'proxy.get':
            return []
        if method == 'host.get':
            if 'tags' in params:
                return [dict(h, host=n) for n, h in self.hosts.items() if any(t['tag'] == 'lab.managed' for t in h['tags'])]
            return [dict(h, host=n) for n, h in self.hosts.items() if n in params['filter']['host']]
        if method == 'host.create':
            self.hosts[params['host']] = {'hostid': str(100 + len(self.hosts)), 'tags': params['tags']}
            return {'hostids': [self.hosts[params['host']]['hostid']]}
        if method == 'host.update':
            for h in self.hosts.values():
                if h['hostid'] == params['hostid'] and 'tags' in params:
                    h['tags'] = params['tags']
            return {}
        if method == 'host.delete':
            for n in [n for n, h in self.hosts.items() if h['hostid'] in params]:
                del self.hosts[n]
            return {}
        return {}


def existing(hostid, tags, ip='192.0.2.2'):
    return {'hostid': hostid, 'monitored_by': '0', 'proxyid': '0', 'tags': tags, 'parentTemplates': [{'templateid': '10'}, {'templateid': '12'}],
            'interfaces': [{'interfaceid': '1', 'type': '2', 'main': '1', 'ip': ip, 'port': '1611', 'useip': '1', 'details': {'community': 'zbxlab', 'version': '2', 'bulk': '1'}}]}


class ProvisionStand(unittest.TestCase):
    def setUp(self):
        zbx.CONFIG_SYNC_WAIT = 0
        self.lab = model.load_lab(os.path.join(model.HERE, 'lab.yaml'))
        self.sc = model.load_scenario('S0', self.lab)
        self.names = sorted(h['host'] for h in __import__('labsim.gen', fromlist=['x']).hosts_json(self.sc))

    def tags(self, stand):
        return [{'tag': 'lab.managed', 'value': '1'}] + ([{'tag': 'lab.stand', 'value': stand}] if stand else [])

    def test_a_created_host_has_both_tags(self):
        api = FakeApi({})
        zbx.provision(api, self.sc, log=lambda *a: None)
        self.assertTrue(api.hosts)
        for h in api.hosts.values():
            self.assertIn({'tag': 'lab.managed', 'value': '1'}, h['tags'])
            self.assertIn({'tag': 'lab.stand', 'value': 'lab'}, h['tags'])

    def test_the_stand_that_starts_takes_the_host_and_keeps_its_other_tags(self):
        name = self.names[0]
        h = existing('7', self.tags('lab-office') + [{'tag': 'owner', 'value': 'dima'}])
        api = FakeApi({name: h})
        zbx.provision(api, self.sc, log=lambda *a: None)
        self.assertIn({'tag': 'lab.stand', 'value': 'lab'}, api.hosts[name]['tags'])
        self.assertNotIn({'tag': 'lab.stand', 'value': 'lab-office'}, api.hosts[name]['tags'])
        self.assertIn({'tag': 'owner', 'value': 'dima'}, api.hosts[name]['tags'])

    def test_a_tagged_host_without_the_stand_tag_belongs_to_lab(self):
        self.assertEqual(zbx.stand_of({'tags': self.tags(None)}), 'lab')
        self.assertEqual(zbx.stand_of({'tags': self.tags('lab-office')}), 'lab-office')

    def test_prune_deletes_only_hosts_of_this_stand(self):
        api = FakeApi({'zz-other-stand': existing('50', self.tags('lab-office')), 'zz-lab-stale': existing('51', self.tags('lab')), 'zz-no-stand-tag': existing('52', self.tags(None)),
                       'zz-untagged': existing('53', [])})
        zbx.provision(api, self.sc, prune=True, log=lambda *a: None)
        self.assertIn('zz-other-stand', api.hosts, 'a host of another stand is never pruned')
        self.assertIn('zz-untagged', api.hosts, 'a host without the tag is never touched')
        self.assertNotIn('zz-lab-stale', api.hosts)
        self.assertNotIn('zz-no-stand-tag', api.hosts, 'no lab.stand = the stand lab')


class ShowSteps(unittest.TestCase):
    def test_a_show_stops_after_the_step_and_evaluates_nothing(self):
        # run() with show_step: the loop ends after step n; the assertions of the steps are not evaluated (no report entries), the stand is left up
        import inspect
        src = inspect.getsource(runner.run)
        self.assertIn('if show and i > show_step', src)
        self.assertIn("[] if show else step.get('assert')", src)
        self.assertIn('if not show:\n            ctl.down(log)', src)

    def test_the_summary_lines(self):
        class Conn:
            pass
        state = {'links': [{'type': 'physical_link', 'state': 'active'}] * 3 + [{'type': 'physical_link', 'state': 'superseded'}], 'observations': [{'outcome': 'applied'}, {'outcome': 'ambiguous'}, {'outcome': 'ambiguous'}],
                 'devices': ['A', 'B'], 'ports': {'A': ['1', '2'], 'B': ['1']}}
        with mock.patch.object(show.db, 'natural_state', return_value=state):
            lines = show.summary(Conn(), {'status': 'done', 'summary': {'devices_created': 2, 'links_created': 3}}, {'zabbix': {'url': 'http://h/zabbix/ui/api_jsonrpc.php'}})
        self.assertEqual(lines[0], 'Devices: 2, Ports: 3')
        self.assertIn('physical_link active 3', lines[1])
        self.assertIn('ambiguous 2', lines[2])
        self.assertIn('devices_created 2', lines[3])
        self.assertEqual(lines[4], 'topology view: http://h/zabbix/ui/zabbix.php?action=topology.view')

    def test_ingest_only_needs_a_show_that_is_up(self):
        with mock.patch.object(ctl, 'load_state', return_value=None):
            with self.assertRaisesRegex(LabError, 'no show is up'):
                show.ingest_only({}, None)
        with mock.patch.object(ctl, 'load_state', return_value={'mode': 'run', 'devices': {'A': {'pid': os.getpid()}}}):
            with self.assertRaisesRegex(LabError, 'no show is up'):
                show.ingest_only({}, None)


if __name__ == '__main__':
    unittest.main()


class JunctionAssertion(unittest.TestCase):
    DERIVED = {'junctions': [{'device': 'SW17', 'port': '16', 'devices': ['DEMO-PC2', 'DEMO-PC1', 'DEMO-PRN1']}]}

    def check(self, a):
        return runner.evaluate(a, {}, {}, {}, {}, 1, lambda: self.DERIVED)

    def test_the_projection_on_a_port_with_its_devices(self):
        a = {'junction': {'port': 'SW17:16', 'devices': ['DEMO-PC1', 'DEMO-PC2', 'DEMO-PRN1']}}
        self.assertEqual(self.check(a).status, 'pass')
        self.assertEqual(self.check({'junction': {'port': 'SW17:16', 'devices': ['DEMO-PC1', 'DEMO-PC2']}}).status, 'fail')
        self.assertEqual(self.check({'junction': {'port': 'SW17:17', 'devices': ['DEMO-PC1']}}).status, 'fail')

    def test_present_false_means_none_on_that_port(self):
        self.assertEqual(self.check({'junction': {'port': 'SW17:17', 'present': False}}).status, 'pass')
        self.assertEqual(self.check({'junction': {'port': 'SW17:16', 'present': False}}).status, 'fail')

    def test_the_form_is_validated(self):
        model.validate_assertion('S', 0, {'junction': {'port': 'A:1', 'devices': ['B']}})
        with self.assertRaises(LabError):
            model.validate_assertion('S', 0, {'junction': {'port': 'A:1', 'colour': 'red'}})
