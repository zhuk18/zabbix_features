"""Generator input validation (spec §5.4), DB-name guard (§2/§10) and determinism.

Run from database/topology/lab/:  python3 -m unittest discover -s tests
"""
import copy
import os
import sys
import tempfile
import unittest

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

from labsim import db, gen, model  # noqa: E402

BASE = {
    'settings': {'community': 'lab', 'snmp_port': 1611, 'database': 'zabbix'},
    'devices': {
        'A': {'ip': '127.0.0.2', 'chassis': {'subtype': 'mac', 'value': '00:11:22:33:44:01'},
              'ports': [{'ifindex': 1, 'name': 'Gi0/1'}, {'ifindex': 2, 'name': 'Gi0/2'}],
              'zabbix': {'monitored': True}},
        'B': {'ip': '127.0.0.3', 'chassis': {'subtype': 'mac', 'value': '00:11:22:33:44:02'},
              'ports': [{'ifindex': 1, 'name': 'Gi0/1'}], 'zabbix': {'monitored': True}},
        'C': {'chassis': {'subtype': 'mac', 'value': '00:11:22:33:44:03'}, 'ports': [{'ifindex': 1, 'name': 'eth0'}],
              'zabbix': {'monitored': False}},
    },
    'cables': [['A:Gi0/1', 'B:Gi0/1']],
}


def lab(**patch):
    d = copy.deepcopy(BASE)
    d.update(patch)
    return model.Lab(d)


class TestValidation(unittest.TestCase):
    def test_valid_lab_passes(self):
        lab().validate()

    def test_cable_to_missing_port(self):
        l = lab(cables=[['A:Gi0/1', 'B:Gi0/9']])
        with self.assertRaisesRegex(model.LabError, 'no port Gi0/9'):
            l.validate()

    def test_cable_to_missing_device(self):
        with self.assertRaisesRegex(model.LabError, 'unknown device'):
            lab(cables=[['A:Gi0/1', 'Z:Gi0/1']]).validate()

    def test_two_cables_on_one_port(self):
        l = lab(cables=[['A:Gi0/1', 'B:Gi0/1'], ['A:Gi0/1', 'C:eth0']])
        with self.assertRaisesRegex(model.LabError, 'used by 2 cables'):
            l.validate()

    def test_shared_port_allowed_by_active_deviation(self):
        l = lab(cables=[['A:Gi0/1', 'B:Gi0/1'], ['A:Gi0/1', 'C:eth0']],
                deviations=[{'id': 'x', 'reason': 'r', 'type': 'extra_lldp_neighbor', 'port': 'A:Gi0/1', 'neighbors': ['C:eth0']}])
        l.active.add('x')
        l.validate()

    def test_deviation_with_missing_device(self):
        l = lab(deviations=[{'id': 'x', 'reason': 'r', 'type': 'override_oid', 'device': 'Z', 'oid': 'sysName', 'value': 'q'}])
        with self.assertRaisesRegex(model.LabError, 'unknown device'):
            l.validate()

    def test_deviation_with_missing_port(self):
        l = lab(deviations=[{'id': 'x', 'reason': 'r', 'type': 'extra_lldp_neighbor', 'port': 'A:Gi0/7', 'neighbors': []}])
        with self.assertRaisesRegex(model.LabError, 'no port Gi0/7'):
            l.validate()

    def test_deviation_needs_reason_and_known_type(self):
        with self.assertRaisesRegex(model.LabError, 'no reason'):
            lab(deviations=[{'id': 'x', 'type': 'omit_mib'}])
        with self.assertRaisesRegex(model.LabError, 'unknown type'):
            lab(deviations=[{'id': 'x', 'reason': 'r', 'type': 'bogus'}])

    def test_duplicate_port(self):
        d = copy.deepcopy(BASE)
        d['devices']['A']['ports'].append({'ifindex': 1, 'name': 'Gi0/3'})
        with self.assertRaisesRegex(model.LabError, 'duplicate port'):
            model.Lab(d).validate()

    def test_generator_writes_nothing_for_invalid_input(self):
        with tempfile.TemporaryDirectory() as t:
            out = os.path.join(t, 'data')
            with self.assertRaises(model.LabError):
                model.Scenario({'id': 'X', 'steps': [{'id': 0, 'cables': {'add': [['A:Gi0/2', 'B:Gi0/9']]}}]}, lab())
            self.assertFalse(os.path.exists(out))


class TestGenerator(unittest.TestCase):
    def test_one_cable_two_ends(self):
        l = lab()
        a, b = gen.device_records(l, 'A'), gen.device_records(l, 'B')
        rem = gen.LLDP_REM
        self.assertEqual(a[rem + (9, 0, 1, 1)], ('4', 'B'))
        self.assertEqual(a[rem + (7, 0, 1, 1)], ('4', 'Gi0/1'))
        self.assertEqual(b[rem + (9, 0, 1, 1)], ('4', 'A'))
        self.assertEqual(a[rem + (5, 0, 1, 1)], ('4x', '001122334402'))

    def test_hex_value_for_bytes_outside_ascii(self):
        self.assertEqual(gen.enc_str(b'Prnt\xff\xfe'), ('4x', '50726e74fffe'))
        self.assertEqual(gen.enc_str('plain'), ('4', 'plain'))

    def test_loc_port_num_modes(self):
        l = lab()
        l.devices['A']['lldp_loc_port_num'] = 'offset:10'
        r = gen.device_records(l, 'A')
        self.assertIn(gen.LLDP_REM + (9, 0, 11, 1), r)
        l.devices['A']['lldp_loc_port_num'] = 'sequential'
        l.devices['A']['ports'][0]['ifindex'] = 7          # sequential: numbered by ifIndex order, so Gi0/1 (7) is 2nd
        self.assertIn(gen.LLDP_REM + (9, 0, 2, 1), gen.device_records(l, 'A'))

    def test_deterministic_output(self):
        sc = model.Scenario({'id': 'T', 'steps': [{'id': 0}, {'id': 1, 'cables': {'add': [['A:Gi0/2', 'C:eth0']]}}]}, lab())
        with tempfile.TemporaryDirectory() as t:
            gen.generate(sc, os.path.join(t, 'one'))
            gen.generate(sc, os.path.join(t, 'two'))
            for root, _, files in os.walk(os.path.join(t, 'one')):
                for f in files:
                    p1 = os.path.join(root, f)
                    p2 = p1.replace(os.path.join(t, 'one'), os.path.join(t, 'two'))
                    if f == 'manifest.json':
                        continue     # holds absolute paths
                    a, b = open(p1).read(), open(p2).read()
                    self.assertEqual(a.replace(os.path.join(t, 'one'), ''), b.replace(os.path.join(t, 'two'), ''))

    def test_changing_subtree_goes_through_labmultiplex_with_control_inside(self):
        sc = model.Scenario({'id': 'T', 'steps': [{'id': 0}, {'id': 1, 'cables': {'add': [['A:Gi0/2', 'C:eth0']]}}]}, lab())
        with tempfile.TemporaryDirectory() as t:
            m = gen.generate(sc, t)
            main = open(os.path.join(t, 'A', 'lab.snmprec')).read()
            self.assertIn('1.0.8802.1.1.2|:labmultiplex|dir=', main)
            self.assertIn('control=1.0.8802.1.1.2.99999.0', main)
            self.assertNotIn('multiplex|dir=%s' % os.path.join(t, 'A', 'mib2'), main)    # mib2 does not change
            self.assertEqual(sorted(os.listdir(os.path.join(t, 'A', 'lldp'))), ['00000.snmprec', '00001.snmprec'])
            self.assertEqual(m['devices']['A']['step_masters'], [[], ['core']])


class TestDeviationsAndMibs(unittest.TestCase):
    REM = gen.LLDP_REM

    def test_override_oid_hex_on_one_row(self):
        l = lab(deviations=[{'id': 'h', 'reason': 'r', 'type': 'override_oid', 'device': 'A', 'oid': 'lldpRemSysName',
                             'row': 'Gi0/1', 'value_hex': '50726e74fffe'}])
        l.active.add('h')
        r = gen.device_records(l, 'A')
        self.assertEqual(r[self.REM + (9, 0, 1, 1)], ('4x', '50726e74fffe'))

    def test_override_oid_without_match_is_an_error(self):
        l = lab(deviations=[{'id': 'h', 'reason': 'r', 'type': 'override_oid', 'device': 'A', 'oid': '1.2.3.4', 'value': 'x'}])
        l.active.add('h')
        with self.assertRaisesRegex(model.LabError, 'no OID matches'):
            gen.device_records(l, 'A')

    def test_one_sided_cable_writes_one_end_only(self):
        l = lab(deviations=[{'id': 'o', 'reason': 'r', 'type': 'one_sided_cable', 'cable': ['A:Gi0/1', 'B:Gi0/1'],
                             'writes': 'A:Gi0/1'}])
        l.active.add('o')
        self.assertIn(self.REM + (9, 0, 1, 1), gen.device_records(l, 'A'))
        self.assertNotIn(self.REM + (9, 0, 1, 1), gen.device_records(l, 'B'))

    def test_extra_lldp_neighbors_on_one_port(self):
        l = lab(deviations=[{'id': 'e', 'reason': 'r', 'type': 'extra_lldp_neighbor', 'port': 'A:Gi0/2',
                             'neighbors': ['C:eth0', 'B:Gi0/1']}])
        l.active.add('e')
        r = gen.device_records(l, 'A')
        self.assertEqual(r[self.REM + (9, 0, 2, 1)], ('4', 'C'))
        self.assertEqual(r[self.REM + (9, 0, 2, 2)], ('4', 'B'))

    def test_omit_mib(self):
        l = lab(deviations=[{'id': 'm', 'reason': 'r', 'type': 'omit_mib', 'device': 'A', 'mib': 'lldp'}])
        l.active.add('m')
        r = gen.device_records(l, 'A')
        self.assertFalse([o for o in r if o[:6] == gen.SUBTREES['lldp']])

    def test_ifindex_renumber_keeps_names(self):
        l = lab(deviations=[{'id': 'n', 'reason': 'r', 'type': 'ifindex_renumber', 'device': 'A', 'offset': 100}])
        l.active.add('n')
        r = gen.device_records(l, 'A')
        self.assertEqual(r[(1, 3, 6, 1, 2, 1, 31, 1, 1, 1, 1, 101)], ('4', 'Gi0/1'))
        self.assertNotIn((1, 3, 6, 1, 2, 1, 31, 1, 1, 1, 1, 1), r)

    def test_fdb_qbridge_and_extra_macs(self):
        l = lab(fdb_extra={'A:Gi0/2': {'count': 25, 'vlan': 10}})
        l.devices['A']['fdb'] = 'qbridge'
        r = gen.device_records(l, 'A')
        macs = [o for o in r if o[:12] == (1, 3, 6, 1, 2, 1, 17, 7, 1, 2, 2, 1) and o[12] == 2]
        self.assertEqual(len(macs), 1 + 25)       # B's port MAC behind Gi0/1 + 25 synthetic ones behind Gi0/2
        self.assertEqual(r[(1, 3, 6, 1, 2, 1, 17, 1, 4, 1, 2, 1)], ('2', '1'))

    def test_lag_membership_and_cdp(self):
        l = lab()
        l.devices['A']['ports'].append({'ifindex': 49, 'name': 'Po1', 'type': 'lag', 'members': ['Gi0/1', 'Gi0/2']})
        l.devices['A']['cdp'] = l.devices['B']['cdp'] = True
        l.validate()
        r = gen.device_records(l, 'A')
        self.assertEqual(r[(1, 2, 840, 10006, 300, 43, 1, 2, 1, 1, 13, 1)], ('2', '49'))
        self.assertEqual(r[(1, 3, 6, 1, 2, 1, 2, 2, 1, 3, 49)], ('2', '161'))
        self.assertEqual(r[(1, 3, 6, 1, 4, 1, 9, 9, 23, 1, 2, 1, 1, 6, 1, 1)], ('4', 'B'))

    def test_lldp_local_port_table_subtypes(self):
        l = lab()
        l.devices['A']['lldp_local_port_subtype'] = 'macAddress'
        r = gen.device_records(l, 'A')
        lb = (1, 0, 8802, 1, 1, 2, 1, 3, 7, 1)
        self.assertEqual(r[lb + (2, 1)], ('2', '3'))
        self.assertEqual(r[lb + (3, 1)][0], '4x')


class TestDatabaseGuard(unittest.TestCase):
    def test_refuses_other_database_before_connecting(self):
        for name in ('zbx', 'zabbix_test', '', None):
            with self.assertRaises(db.DatabaseGuard):
                db.connect({'database': name})


if __name__ == '__main__':
    unittest.main()
