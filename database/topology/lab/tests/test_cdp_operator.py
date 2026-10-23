"""Lab 2.21 (topology-4-lab-simulator.md): the CDP keys of a device (`cdp_device_id`, `cdp_address`), CDP-only devices, the step key `operator`, the assertions
`device_count` and `candidate` (task 067).

Run from database/topology/lab/:  python3 -m unittest discover -s tests
"""
import copy
import os
import subprocess
import sys
import unittest

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

from labsim import gen, model, runner  # noqa: E402

CDP = (1, 3, 6, 1, 4, 1, 9, 9, 23, 1, 2, 1, 1)
REM = gen.LLDP_REM
BASE = {
    'settings': {'community': 'lab', 'snmp_port': 1611, 'database': 'zabbix', 'emit_mgmt_addr': True},
    'devices': {
        'A': {'ip': '192.0.2.201', 'chassis': {'subtype': 'mac', 'value': '00:11:22:33:44:01'}, 'cdp': True,
              'ports': [{'ifindex': 1, 'name': 'Gi0/1'}, {'ifindex': 2, 'name': 'Gi0/2'}], 'zabbix': {'monitored': True}},
        'B': {'ip': '192.0.2.202', 'chassis': {'subtype': 'mac', 'value': '00:11:22:33:44:02'}, 'cdp': True,
              'ports': [{'ifindex': 1, 'name': 'Gi0/1'}], 'zabbix': {'monitored': True}},
        'C': {'chassis': {'subtype': 'mac', 'value': '00:11:22:33:44:03'}, 'ports': [{'ifindex': 1, 'name': 'eth0'}], 'sysname': 'SRV', 'zabbix': {'monitored': False}},
    },
    'cables': [['A:Gi0/1', 'B:Gi0/1'], ['A:Gi0/2', 'C:eth0']],
}


def lab(**devices):
    d = copy.deepcopy(BASE)
    for name, change in devices.items():
        d['devices'][name].update(change)
    return model.Lab(d)


def cdp_of(l, near='A', ifindex=1):
    """cdpCacheDeviceId, cdpCacheAddressType, cdpCacheAddress at the neighbor 1 of ifIndex `ifindex`."""
    r = gen.device_records(l, near)
    return r.get(CDP + (6, ifindex, 1)), r.get(CDP + (3, ifindex, 1)), r.get(CDP + (4, ifindex, 1))


class CdpKeys(unittest.TestCase):
    def test_defaults_are_the_sysname_and_no_address(self):
        device_id, atype, address = cdp_of(lab())
        self.assertEqual(device_id, ('4', 'B'))
        self.assertIsNone(atype)
        self.assertIsNone(address)

    def test_cdp_address_true_is_the_device_ip(self):
        device_id, atype, address = cdp_of(lab(B={'cdp_address': True}))
        self.assertEqual((atype, address), (('2', '1'), ('4x', 'c00002ca')))              # 192.0.2.202

    def test_cdp_address_an_other_address(self):
        _, atype, address = cdp_of(lab(B={'cdp_address': '192.0.2.108'}))
        self.assertEqual((atype, address), (('2', '1'), ('4x', 'c000026c')))

    def test_cdp_address_false_is_none(self):
        self.assertIsNone(cdp_of(lab(B={'cdp_address': False}))[2])

    def test_cdp_device_id_mac_is_six_binary_bytes(self):
        self.assertEqual(cdp_of(lab(B={'cdp_device_id': 'mac'}))[0], ('4x', '001122334402'))

    def test_cdp_device_id_literal_text(self):
        self.assertEqual(cdp_of(lab(B={'cdp_device_id': 'N9K-1(FDO2101X0AB)'}))[0], ('4', 'N9K-1(FDO2101X0AB)'))

    def test_wrong_values_are_errors(self):
        for change, pattern in [({'cdp_device_id': ''}, 'cdp_device_id'), ({'cdp_device_id': 5}, 'cdp_device_id'), ({'cdp_address': 'x'}, 'cdp_address'),
                                ({'cdp_address': '2001:db8::1'}, 'cdp_address'), ({'cdp_address': 7}, 'cdp_address')]:
            with self.assertRaisesRegex(model.LabError, pattern):
                lab(B=change)
        with self.assertRaisesRegex(model.LabError, 'needs an ip'):
            lab(C={'cdp_address': True})

    def test_a_step_changes_both_keys(self):
        l = lab()
        l.apply_step({'devices': {'B': {'cdp_address': '192.0.2.151', 'cdp_device_id': 'mac'}}})
        device_id, _, address = cdp_of(l)
        self.assertEqual((device_id, address), (('4x', '001122334402'), ('4x', 'c0000297')))
        with self.assertRaisesRegex(model.LabError, 'cdp_address'):
            l.apply_step({'devices': {'B': {'cdp_address': 'nope'}}})


class CdpOnly(unittest.TestCase):
    def test_the_neighbors_get_only_a_cdp_entry(self):
        l = lab(C={'lldp': False, 'cdp': True})
        r = gen.device_records(l, 'A')
        self.assertIsNone(r.get(REM + (9, 0, 2, 1)))                                       # no LLDP entry for C on Gi0/2
        self.assertEqual(r[REM + (9, 0, 1, 1)], ('4', 'B'))                                # B is still there by LLDP
        self.assertEqual(r[CDP + (6, 2, 1)], ('4', 'SRV'))                                 # and C by CDP

    def test_a_monitored_cdp_only_device_has_no_lldp_mib(self):
        l = lab(B={'lldp': False, 'cdp': True})
        r = gen.device_records(l, 'B')
        self.assertFalse([o for o in r if o[:6] == (1, 0, 8802, 1, 1, 2)])
        self.assertEqual(r[CDP + (6, 1, 1)], ('4', 'A'))
        self.assertIsNone(gen.device_records(l, 'A').get(REM + (9, 0, 1, 1)))


class OperatorForms(unittest.TestCase):
    def test_valid_forms(self):
        self.assertEqual(model.operator_actions('S', 1, None), [])
        self.assertEqual(len(model.operator_actions('S', 1, {'merge': ['A', 'B'], 'keep': 'A'})), 1)
        self.assertEqual(len(model.operator_actions('S', 1, [{'merge_group': 'SRV2', 'keep': 'Switch2:Gi0/12'}, {'dismiss': ['A', 'B']}, {'dismiss_group': 'SRV2'}, {'delete': 'A'}])), 4)

    def test_wrong_forms(self):
        for bad in [{'merge': ['A'], 'keep': 'A'}, {'merge': ['A', 'B']}, {'merge': ['A', 'B'], 'keep': 'C'}, {'merge_group': 'X'}, {'dismiss': 'A'}, {'delete': 'A', 'keep': 'A'},
                    {'frobnicate': 'A'}, {'merge': ['A', 'B'], 'dismiss': ['A', 'B'], 'keep': 'A'}, 'merge', {'dismiss_group': 'X', 'extra': 1}]:
            with self.assertRaises(model.LabError, msg=repr(bad)):
                model.operator_actions('S', 1, bad)

    def test_a_scenario_rejects_a_wrong_operator(self):
        data = {'id': 'X', 'steps': [{'id': 0, 'operator': {'merge': ['A']}}]}
        with self.assertRaisesRegex(model.LabError, 'takes a list of two'):
            model.Scenario(data, lab())


def state(devices, links=()):
    """devices: [(id, sysname, [(other id, reason)...])]; links: [(near id, near port, far id)]."""
    return {'device_nodes': [{'id': i, 'sysname': n, 'chassis_id': None, 'mac': None, 'candidates': [{'device_id': o, 'reason': r} for o, r in c]} for i, n, c in devices],
            'links': [{'end_devices': [a, f], 'end_ports': [p, None], 'type': 'device_link'} for a, p, f in links]}


class OperatorCommands(unittest.TestCase):
    def setUp(self):
        # Switch1 = 1, Switch2 = 2, three SRV2 Devices 11, 12, 13: 11 is linked from Switch1:Gi0/14, 12 from Switch2:Gi0/12, 13 from Switch1:Gi0/12
        self.s = state([(1, 'Switch1', []), (2, 'Switch2', []), (11, 'SRV2', [(12, 'same_address'), (13, 'same_address')]), (12, 'SRV2', [(11, 'same_address')]),
                        (13, 'SRV2', [(11, 'same_address'), (12, 'same_name')]), (21, 'AP-7', [])],
                       [(1, 'Gi0/14', 11), (2, 'Gi0/12', 12), (1, 'Gi0/12', 13)])

    def test_merge_group_keeps_the_device_of_a_link_end(self):
        got = runner.operator_commands(self.s, [{'merge_group': 'SRV2', 'keep': 'Switch2:Gi0/12'}])
        self.assertEqual(got, [('merge_group', {'keep': 12, 'remove': [11, 13]})])

    def test_merge_group_keep_that_names_no_device_of_the_group(self):
        with self.assertRaisesRegex(model.LabError, 'names 0 Devices'):
            runner.operator_commands(self.s, [{'merge_group': 'SRV2', 'keep': 'Switch2:Gi0/9'}])

    def test_merge_a_pair_and_dismiss_a_pair_and_delete(self):
        got = runner.operator_commands(self.s, [{'merge': ['Switch1', 'Switch2'], 'keep': 'Switch2'}, {'dismiss': ['Switch1', 'Switch2']}, {'delete': 'AP-7'}])
        self.assertEqual(got, [('merge', {'keep': 2, 'remove': 1}), ('dismiss', {'a': 1, 'b': 2}), ('delete', {'id': 21})])

    def test_dismiss_group_takes_every_device_of_the_key(self):
        self.assertEqual(runner.operator_commands(self.s, [{'dismiss_group': 'SRV2'}]), [('dismiss_group', {'ids': [11, 12, 13]})])

    def test_a_key_that_gives_several_or_none_is_an_error_where_one_is_needed(self):
        with self.assertRaisesRegex(model.LabError, 'gives 3 Devices'):
            runner.operator_commands(self.s, [{'delete': 'SRV2'}])
        with self.assertRaisesRegex(model.LabError, 'gives 0 Devices'):
            runner.operator_commands(self.s, [{'delete': 'nobody'}])

    def test_operator_needs_a_previous_state(self):
        sc = model.Scenario({'id': 'X', 'steps': [{'id': 0, 'operator': {'delete': 'C'}}]}, lab())
        with self.assertRaisesRegex(model.LabError, 'previous step'):
            runner.run_operator(sc, 0, None, lambda *a: None)


class Assertions(unittest.TestCase):
    def setUp(self):
        self.s = state([(11, 'SRV2', [(12, 'same_address'), (13, 'same_address')]), (12, 'SRV2', [(11, 'same_address')]),
                        (13, 'SRV2', [(11, 'same_address'), (12, 'same_name')]), (21, 'AP-7', [])])

    def ev(self, a):
        return runner.evaluate(a, self.s, {}, {}, {}, 0)

    def test_device_count(self):
        self.assertEqual(self.ev({'device_count': {'key': 'SRV2', 'equals': 3}}).status, 'pass')
        self.assertEqual(self.ev({'device_count': {'key': 'SRV2', 'equals': 2}}).status, 'fail')
        self.assertEqual(self.ev({'device_count': {'key': 'nobody', 'equals': 0}}).status, 'pass')

    def test_candidate_pairs(self):
        self.assertEqual(self.ev({'candidate': {'key': 'SRV2', 'reason': 'same_address', 'pairs': 2}}).status, 'pass')      # 11-12 and 11-13
        self.assertEqual(self.ev({'candidate': {'key': 'SRV2', 'reason': 'same_name', 'pairs': 1}}).status, 'pass')         # 12-13, listed on 13 only
        self.assertEqual(self.ev({'candidate': {'key': 'AP-7', 'reason': 'same_address', 'pairs': 0}}).status, 'pass')
        self.assertEqual(self.ev({'candidate': {'key': 'SRV2', 'reason': 'same_address', 'pairs': 3}}).status, 'fail')

    def test_forms_are_known_and_checked(self):
        model.validate_assertion('S', 0, {'device_count': {'key': 'SRV2', 'equals': 1}})
        model.validate_assertion('S', 0, {'candidate': {'key': 'SRV2', 'reason': 'same_address', 'pairs': 0}})
        with self.assertRaisesRegex(model.LabError, 'unknown key'):
            model.validate_assertion('S', 0, {'device_count': {'key': 'SRV2', 'count': 1}})


class OperatorHelper(unittest.TestCase):
    PHP = os.path.join(os.path.dirname(os.path.dirname(os.path.abspath(__file__))), 'php', 'operator.php')

    def test_the_helper_refuses_a_wrong_call_before_it_connects(self):
        for args in (['bogus', '{}'], ['merge'], ['merge', 'not json']):
            p = subprocess.run(['php', self.PHP] + args, capture_output=True, text=True)
            self.assertEqual(p.returncode, 2, msg=repr(args))


if __name__ == '__main__':
    unittest.main()
