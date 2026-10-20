"""Ends and Device keys of the assertions (lab spec 8.3): `Device`, `Device:port` split at the first ':', {device, port}; a Device key is the sysname, then the chassis id
in normal form; a key that gives several Devices fails with `ambiguous key <key>`; observation.remote uses the same key.
Run from database/topology/lab/:  python3 -m unittest discover -s tests"""
import os
import sys
import unittest

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

from labsim import runner  # noqa: E402
from labsim.model import LabError  # noqa: E402

DEVICES = [
    {'id': 1, 'sysname': 'SW1', 'chassis_id': '00:00:00:00:00:01', 'mac': '00:00:00:00:00:01'},
    {'id': 2, 'sysname': None, 'chassis_id': '18:60:24:b2:bd:06', 'mac': '18:60:24:b2:bd:06'},          # named by its chassis id
    {'id': 3, 'sysname': 'FAP', 'chassis_id': '94:f3:92:df:07:70', 'mac': '94:f3:92:df:07:70'},          # two Devices share the sysname FAP
    {'id': 4, 'sysname': 'FAP', 'chassis_id': '94:f3:92:df:04:50', 'mac': '94:f3:92:df:04:50'},
    {'id': 5, 'sysname': 'NA1', 'chassis_id': '3a:5c:91:e2:07:4b', 'mac': None},
    {'id': 6, 'sysname': 'NA1', 'chassis_id': '12:bf:b8:b9:6a:f5', 'mac': None},                           # the old Device of a restarted one
]


def link(id_, a, b):
    """a, b: (device node id, port)."""
    return {'id': id_, 'type': 'physical_link', 'ends': ['%s:%s' % x for x in (a, b)], 'discovered_via': 'lldp', 'state': 'active', 'far_port_reason': None, 'attrs': {},
            'end_devices': [a[0], b[0]], 'end_ports': [a[1], b[1]]}


STATE = {'device_nodes': DEVICES, 'observations': [
    {'reporter': 'SW1', 'port': '1/1/1', 'device': 'NA1', 'device_id': 5, 'outcome': 'applied', 'precision_lower': False, 'remote_key': 'chassis:mac:3a:5c:91:e2:07:4b'},
    {'reporter': 'SW1', 'port': '1/1/2', 'device': 'FAP', 'device_id': 3, 'outcome': 'applied', 'precision_lower': False, 'remote_key': 'x'}],
    'links': [link(1, (1, '1/1/1'), (5, 'eth0')), link(2, (1, '1/1/2'), (3, 'eth0')), link(3, (1, '1/1/3'), (2, '18:60:24:b2:bd:06')),
              link(4, (1, '1/1/4'), (4, 'eth0'))]}


def link_status(a, b, **kw):
    return runner.evaluate(dict({'link': [a, b], 'state': 'active'}, **kw), STATE, {}, {}, {}, 0, lambda: {}).status


def obs(remote, outcome='applied', port='1/1/1'):
    return runner.evaluate({'observation': {'reporter': 'SW1', 'port': port, 'remote': remote}, 'outcome': outcome}, STATE, {}, {}, {}, 0, lambda: {})


class Ends(unittest.TestCase):
    def test_split_at_the_first_colon(self):
        self.assertEqual(runner.split_end('SW1:1/1/1'), ('SW1', '1/1/1'))
        self.assertEqual(runner.split_end('18:60:24:b2:bd:06'), ('18', '60:24:b2:bd:06'))     # a MAC as a name needs the object form
        self.assertEqual(runner.split_end('SW1'), ('SW1', None))

    def test_object_form(self):
        self.assertEqual(runner.split_end({'device': '18:60:24:b2:bd:06', 'port': '18:60:24:b2:bd:06'}), ('18:60:24:b2:bd:06', '18:60:24:b2:bd:06'))
        self.assertEqual(runner.split_end({'device': 'SW1'}), ('SW1', None))
        with self.assertRaises(LabError):
            runner.split_end({'dev': 'x'})

    def test_link_with_object_ends_and_colons_in_names(self):
        self.assertEqual(link_status('SW1:1/1/3', {'device': '18:60:24:b2:bd:06', 'port': '18:60:24:b2:bd:06'}), 'pass')
        self.assertEqual(link_status({'device': 'SW1', 'port': '1/1/3'}, {'device': '18:60:24:b2:bd:06'}), 'pass')
        self.assertEqual(link_status('SW1:1/1/3', {'device': '18:60:24:b2:bd:06', 'port': 'other'}), 'fail')

    def test_device_key_is_the_sysname(self):
        self.assertEqual(link_status('SW1:1/1/3', {'device': '18:60:24:b2:bd:06'}), 'pass')
        self.assertEqual(link_status('SW1', {'device': '18:60:24:b2:bd:06'}), 'pass')
        self.assertEqual(link_status('SW1:1/1/9', {'device': '18:60:24:b2:bd:06'}), 'fail')           # SW1 has no port 1/1/9

    def test_device_key_is_the_chassis_id_in_normal_form(self):
        self.assertEqual(link_status('SW1:1/1/3', {'device': '18:60:24:B2:BD:06'}), 'pass')       # upper case
        self.assertEqual(link_status('SW1:1/1/3', {'device': '18-60-24-b2-bd-06'}), 'pass')       # dashes
        self.assertEqual(link_status('SW1:1/1/3', {'device': '186024b2bd06'}), 'pass')            # no separators

    def test_several_sysnames_the_chassis_id_picks_one(self):
        self.assertEqual(link_status('SW1:1/1/1', {'device': '3a:5c:91:e2:07:4b', 'port': 'eth0'}), 'pass')
        self.assertEqual(link_status('SW1:1/1/2', {'device': '94:f3:92:df:07:70', 'port': 'eth0'}), 'pass')
        self.assertEqual(link_status('SW1:1/1/4', {'device': '94:f3:92:df:07:70', 'port': 'eth0'}), 'fail')      # the other FAP has the other link

    def test_a_key_with_several_devices_is_ambiguous(self):
        with self.assertRaisesRegex(LabError, 'ambiguous key FAP'):
            link_status('SW1:1/1/2', 'FAP:eth0')
        with self.assertRaisesRegex(LabError, 'ambiguous key NA1'):
            link_status('SW1', {'device': 'NA1'})

    def test_no_device_is_no_link(self):
        self.assertEqual(link_status('SW1', 'NOBODY'), 'fail')
        self.assertEqual(link_status('SW1', 'NOBODY', state='absent'), 'pass')

    def test_observation_remote_cases(self):
        self.assertEqual(obs('3a:5c:91:e2:07:4b').status, 'pass')       # a chassis key with ':' is the whole key, not "Device:port"
        self.assertEqual(obs('3A-5C-91-E2-07-4B').status, 'pass')
        self.assertEqual(obs('12:bf:b8:b9:6a:f5').status, 'fail')       # the old Device has no such observation
        self.assertEqual(obs('3a:5c:91:e2:07:4b', outcome='ambiguous').status, 'fail')

    def test_observation_remote_ambiguous(self):
        with self.assertRaisesRegex(LabError, 'ambiguous key FAP'):
            obs('FAP', port='1/1/2')


if __name__ == '__main__':
    unittest.main()
