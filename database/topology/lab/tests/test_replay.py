"""Replay devices (lab spec §4.1a, §4.4, §5.3, §5.4): hand-written snmprec files, one per check, and one valid file.
Run from database/topology/lab/:  python3 -m unittest discover -s tests"""
import os
import sys
import tempfile
import unittest

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

from labsim import gen, model  # noqa: E402
from labsim.model import LabError  # noqa: E402

TAIL = '1.3.6.1.4.1.99999.1.0|2|1\n'
NAME = '1.3.6.1.2.1.1.5.0|4|R1\n'
# lldpLocPortId.1 = "1/1/1" (4x hex), lldpRem entry on local port 1: chassis id, sysname; second neighbor on port 2 with two entries (index 1 and 2)
LLDP = ('1.0.8802.1.1.2.1.3.7.1.3.1|4|1/1/1\n'
        '1.0.8802.1.1.2.1.3.7.1.3.2|4|1/1/2\n'
        '1.0.8802.1.1.2.1.4.1.1.5.0.1.1|4x|aabbccddeeff\n'
        '1.0.8802.1.1.2.1.4.1.1.5.0.2.1|4x|112233445566\n'
        '1.0.8802.1.1.2.1.4.1.1.5.0.2.2|4x|665544332211\n'
        '1.0.8802.1.1.2.1.4.1.1.9.0.1.1|4|far\n')
CDP = '1.3.6.1.4.1.9.9.23.1.2.1.1.6.1.1|4|cdpfar\n'
FDB = '1.3.6.1.2.1.17.4.3.1.1.0.1.2.3.4.5|4x|000102030405\n'
IFNAME = '1.3.6.1.2.1.31.1.1.1.1.1|4|Gi1\n'
VALID = LLDP + NAME + FDB + IFNAME + CDP + TAIL


class Base(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)

    def write(self, text, name='r1.snmprec'):
        path = os.path.join(self.tmp.name, name)
        with open(path, 'w') as f:
            f.write(text)
        return name

    def lab(self, device=None, deviations=None, cables=None, text=VALID):
        f = self.write(text)
        d = {'ip': '127.0.17.2', 'replay': f, 'zabbix': {'monitored': True}}
        d.update(device or {})
        data = {'settings': {'community': 'zbxlab'}, 'devices': {'R1': d}, 'cables': cables or [], 'deviations': deviations or []}
        lab = model.Lab(data, self.tmp.name)
        lab.validate()
        return lab

    def scenario(self, steps, lab_kw=None):
        lab = self.lab(**(lab_kw or {}))
        return model.Scenario({'id': 'T', 'steps': steps}, lab)


class Checks(Base):
    def test_valid_file(self):
        lab = self.lab()
        self.assertEqual(len(gen.device_records(lab, 'R1')), VALID.count('\n'))

    def test_missing_file(self):
        with self.assertRaisesRegex(LabError, 'replay file'):
            self.lab(device={'replay': 'nope.snmprec'})

    def test_unparsable_file(self):
        with self.assertRaisesRegex(LabError, 'does not parse'):
            self.lab(text=LLDP + 'garbage\n' + TAIL)

    def test_oids_not_sorted(self):
        with self.assertRaisesRegex(LabError, 'not sorted'):
            self.lab(text=NAME + '1.3.6.1.2.1.1.1.0|4|first\n' + TAIL)

    def test_oids_not_unique(self):
        with self.assertRaisesRegex(LabError, 'not sorted or not unique'):
            self.lab(text=NAME + NAME + TAIL)

    def test_no_end_record(self):
        with self.assertRaisesRegex(LabError, 'does not end with the record'):
            self.lab(text=NAME)

    def test_sysname_is_not_the_device_name(self):
        with self.assertRaisesRegex(LabError, 'sysName.0'):
            self.lab(text='1.3.6.1.2.1.1.5.0|4|other\n' + TAIL)

    def test_sysname_missing(self):
        with self.assertRaisesRegex(LabError, 'sysName.0'):
            self.lab(text=TAIL)

    def test_other_device_keys(self):
        for key in ('chassis', 'ports', 'lldp', 'cdp', 'fdb', 'port_mac_prefix', 'lldp_local_port_subtype', 'lldp_loc_port_num'):
            with self.assertRaisesRegex(LabError, key):
                self.lab(device={key: 1})
        with self.assertRaisesRegex(LabError, 'sysname'):
            self.lab(device={'sysname': 'x'})

    def test_cable_uses_a_replay_port(self):
        with self.assertRaisesRegex(LabError, 'replay device'):
            self.lab(cables=[['R1:1/1/1', 'R1:1/1/2']])

    def test_other_deviation_types(self):
        for dev in ({'type': 'ifindex_renumber', 'device': 'R1', 'offset': 1}, {'type': 'extra_lldp_neighbor', 'port': 'R1:1', 'neighbors': []},
                    {'type': 'one_sided_cable', 'device': 'R1', 'cable': []}):
            with self.assertRaisesRegex(LabError, 'replay device'):
                self.lab(deviations=[dict(dev, id='x', reason='r')])

    def test_step_devices_refused(self):
        with self.assertRaisesRegex(LabError, 'replay device'):
            self.scenario([{'devices': {'R1': {'cdp': True}}}])

    def test_override_row_without_entry(self):
        dv = {'id': 'o', 'reason': 'r', 'type': 'override_oid', 'device': 'R1', 'oid': 'lldpRemChassisId', 'row': '9/9/9', 'value_hex': 'aa'}
        with self.assertRaisesRegex(LabError, 'no lldpRem entry'):
            self.lab(deviations=[dv])

    def test_override_row_with_several_entries(self):
        dv = {'id': 'o', 'reason': 'r', 'type': 'override_oid', 'device': 'R1', 'oid': 'lldpRemChassisId', 'row': '1/1/2', 'value_hex': 'aa'}
        with self.assertRaisesRegex(LabError, 'rem_index'):
            self.lab(deviations=[dv])


class Deviations(Base):
    def dv(self, **kw):
        return dict({'id': 'd', 'reason': 'r', 'device': 'R1'}, **kw)

    def test_omit_mib_removes_the_subtree(self):
        sc = self.scenario([{}, {'deviations': {'enable': ['d']}}], {'deviations': [self.dv(type='omit_mib', mib='cdp')]})
        before = gen.device_records(sc.states[0], 'R1')
        after = gen.device_records(sc.states[1], 'R1')
        self.assertTrue(any(o[:9] == (1, 3, 6, 1, 4, 1, 9, 9, 23) for o in before))
        self.assertFalse(any(o[:9] == (1, 3, 6, 1, 4, 1, 9, 9, 23) for o in after))
        self.assertEqual({o: v for o, v in before.items() if o[:9] != (1, 3, 6, 1, 4, 1, 9, 9, 23)}, dict(after))

    def test_omit_fdb_removes_both_roots(self):
        text = VALID.replace(IFNAME, '1.3.6.1.2.1.17.7.1.2.2.1.2.1.1|2|1\n' + IFNAME)
        sc = self.scenario([{}, {'deviations': {'enable': ['d']}}], {'text': text, 'deviations': [self.dv(type='omit_mib', mib='fdb')]})
        after = gen.device_records(sc.states[1], 'R1')
        self.assertFalse(any(o[:8] in ((1, 3, 6, 1, 2, 1, 17, 4),) or o[:10] == (1, 3, 6, 1, 2, 1, 17, 7, 1, 2) for o in after))

    def test_override_with_row_and_rem_index(self):
        dv = self.dv(type='override_oid', oid='lldpRemChassisId', row='1/1/2', rem_index=2, value_hex='3a5c91e2074b')
        sc = self.scenario([{}, {'deviations': {'enable': ['d']}}], {'deviations': [dv]})
        before = gen.device_records(sc.states[0], 'R1')
        after = gen.device_records(sc.states[1], 'R1')
        changed = {o for o in after if before[o] != after[o]}
        self.assertEqual(changed, {(1, 0, 8802, 1, 1, 2, 1, 4, 1, 1, 5, 0, 2, 2)})
        self.assertEqual(after[(1, 0, 8802, 1, 1, 2, 1, 4, 1, 1, 5, 0, 2, 2)], ('4x', '3a5c91e2074b'))

    def test_override_row_by_ifname(self):
        # no lldpLocPortId matches: the row is the ifName of the same lldpLocPortNum (here 1 -> "Gi1")
        dv = self.dv(type='override_oid', oid='lldpRemSysName', row='Gi1', value='renamed')
        sc = self.scenario([{}, {'deviations': {'enable': ['d']}}], {'deviations': [dv]})
        self.assertEqual(gen.device_records(sc.states[1], 'R1')[(1, 0, 8802, 1, 1, 2, 1, 4, 1, 1, 9, 0, 1, 1)], ('4', 'renamed'))

    def test_other_deviation_type_name_check_for_omit_mib(self):
        with self.assertRaisesRegex(LabError, 'omit_mib'):
            self.lab(deviations=[self.dv(type='omit_mib', mib='nothing')])


class Files(Base):
    def generate(self, sc):
        out = os.path.join(self.tmp.name, 'out')
        return gen.generate(sc, out), out

    def test_static_replay_is_a_copy(self):
        sc = self.scenario([{}])
        m, out = self.generate(sc)
        self.assertEqual(open(os.path.join(out, 'R1', 'zbxlab.snmprec')).read(), VALID)
        self.assertEqual(m['devices']['R1']['mux'], {})

    def test_changing_replay_has_step_files(self):
        sc = self.scenario([{}, {'deviations': {'enable': ['d']}}], {'deviations': [{'id': 'd', 'reason': 'r', 'device': 'R1', 'type': 'omit_mib', 'mib': 'cdp'}]})
        m, out = self.generate(sc)
        self.assertEqual(sorted(m['devices']['R1']['mux']), ['cdp'])
        step0 = open(os.path.join(out, 'R1', 'cdp', '00000.snmprec')).read()
        step1 = open(os.path.join(out, 'R1', 'cdp', '00001.snmprec')).read()
        self.assertEqual((step0, step1), (CDP, ''))
        main = open(os.path.join(out, 'R1', 'zbxlab.snmprec')).read()
        self.assertIn('1.3.6.1.4.1.9.9.23|:labmultiplex|dir=', main)
        self.assertNotIn('cdpfar', main)                                   # the changing subtree is not in the main file
        self.assertEqual(m['devices']['R1']['step_masters'][1], ['core'])     # the core master item sees the change

    def test_export_walk_of_a_replay_device(self):
        sc = self.scenario([{}])
        text = gen.export_walk(sc.states[0], 'R1')
        self.assertIn('.1.3.6.1.2.1.1.5.0 = STRING: "R1"', text)


if __name__ == '__main__':
    unittest.main()
