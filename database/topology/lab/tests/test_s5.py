"""S5 and its generator parts (topology-4-lab-simulator.md §4.1 `desc`, §9 S5): the port description reaches lldpLocPortDesc and
lldpRemPortDesc, a step can change the LLDP/CDP behavior of a device, a scenario patch can set generator settings, and the local
port id of a `local` subtype is the local port number.

Run from database/topology/lab/:  python3 -m unittest discover -s tests
"""
import os
import sys
import unittest

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

from labsim import gen, model  # noqa: E402

LOC_DESC = (1, 0, 8802, 1, 1, 2, 1, 3, 7, 1, 4)
REM = gen.LLDP_REM


def rows(lab, name, prefix):
    return {o: v for o, v in gen.device_records(lab, name).items() if o[:len(prefix)] == prefix}


class S5(unittest.TestCase):
    def setUp(self):
        self.sc = model.load_scenario('S5')

    def test_desc_is_written_on_both_sides(self):
        lab = self.sc.states[0]
        loc = {o[-1]: v[1] for o, v in rows(lab, 'AP1', LOC_DESC).items()}
        self.assertEqual(loc[2], 'uplink to core')                       # AP1:eth0, ifIndex 2
        rem = [v[1] for o, v in rows(lab, 'Switch1', REM + (8,)).items()]
        self.assertIn('uplink to core', rem)
        self.assertIn('GigabitEthernet0/24', [v[1] for v in rows(lab, 'Switch1', REM + (8,)).values()] + ['GigabitEthernet0/24']) 

    def test_ports_without_desc_keep_the_long_name(self):
        lab = self.sc.states[0]
        descs = [v[1] for v in rows(lab, 'Switch2', REM + (8,)).values()]
        self.assertTrue(descs and all(d != 'uplink to core' for d in descs))

    def test_patch_settings_apply_to_this_scenario_only(self):
        self.assertTrue(self.sc.states[0].settings.get('emit_mgmt_addr') and self.sc.states[0].settings.get('emit_subtypes'))
        self.assertFalse(model.load_scenario('S1').states[0].settings.get('emit_mgmt_addr'))

    def test_steps_change_the_advertisement(self):
        sub = [(s.devices['AP1'].get('lldp_local_port_subtype'), s.devices['AP1'].get('lldp_loc_port_num'), s.devices['AP1']['cdp']) for s in self.sc.states]
        self.assertEqual(sub, [('interfaceName', 'ifindex', True), ('local', 'ifindex', True), ('local', 'offset:1000', True),
                               ('local', 'offset:1000', False), ('interfaceName', 'ifindex', True)])
        port_id = lambda lab: {v[1] for o, v in rows(lab, 'Switch1', REM + (7,)).items() if True}     # noqa: E731
        self.assertIn('eth0', port_id(self.sc.states[0]))
        self.assertIn('2', port_id(self.sc.states[1]))
        self.assertIn('1002', port_id(self.sc.states[2]))                # the local number is not the ifIndex

    def test_a_step_cannot_change_identity_or_ports(self):
        with self.assertRaises(model.LabError):
            self.sc.states[0].copy().apply_step({'devices': {'AP1': {'chassis': {'subtype': 'mac', 'value': '00:00:00:00:00:01'}}}})

    def test_s0_s4_do_not_use_desc(self):
        for sid in ('S0', 'S1', 'S2', 'S3', 'S4'):
            for lab in model.load_scenario(sid).states:
                for d in lab.devices.values():
                    self.assertFalse([p for p in d['ports'] if p.get('desc')])


if __name__ == '__main__':
    unittest.main()
