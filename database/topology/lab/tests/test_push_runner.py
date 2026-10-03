"""Push hosts in the runner (topology-4-lab-simulator.md "Step loop", item 2): push.py instead of "check now". No Zabbix, no
database: the subprocess and the API are faked.

Run from database/topology/lab/:  python3 -m unittest discover -s tests
"""
import os
import subprocess
import sys
import unittest
from unittest import mock

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

from labsim import runner, zbx  # noqa: E402

DEV = {'ip': '127.0.0.3', 'community': 'zbxlab'}
SETTINGS = {'snmp_port': 1611}


class PushCommand(unittest.TestCase):
    def test_push_py_gets_the_device_and_its_host(self):
        cmd = runner.push_command(DEV, 'Switch1', SETTINGS)
        self.assertEqual(cmd[1], runner.PUSH)
        self.assertTrue(os.path.exists(runner.PUSH))
        args = dict(zip(cmd[2::2], cmd[3::2]))
        self.assertEqual(args, {'--name': 'Switch1', '--zabbix-host': 'Switch1', '--mgmt-ip': '127.0.0.3', '--snmp-target': '127.0.0.3',
                                '--snmp-port': '1611', '--snmp-community': 'zbxlab'})

    def test_sender_settings_are_passed_on(self):
        cmd = runner.push_command(DEV, 'Switch1', dict(SETTINGS, sender_server='10.0.0.9', sender_port=10052, sender_bin='/x/zabbix_sender'))
        self.assertEqual(cmd[-6:], ['--sender-server', '10.0.0.9', '--sender-port', '10052', '--sender-bin', '/x/zabbix_sender'])

    def test_exit_status_is_the_result(self):
        done = lambda code, out, err: subprocess.CompletedProcess([], code, out, err)           # noqa: E731
        with mock.patch.object(runner.subprocess, 'run', return_value=done(0, 'OK: sent 2 role(s)\n', '')) as run:
            self.assertEqual(runner.run_push('Switch1', DEV, SETTINGS), (True, 'OK: sent 2 role(s)'))
            self.assertEqual(run.call_args[0][0], runner.push_command(DEV, 'Switch1', SETTINGS))
        with mock.patch.object(runner.subprocess, 'run', return_value=done(1, '', "ERROR: nothing sent for any role of reporter 'S'\n")):
            ok, detail = runner.run_push('Switch1', DEV, SETTINGS)
            self.assertFalse(ok)
            self.assertIn('nothing sent', detail)


class FakeApi:
    def call(self, method, params):
        if method == 'host.get':
            return [{'hostid': '1', 'host': 'Switch1'}, {'hostid': '2', 'host': 'Router1'}]
        if method == 'item.get':
            return [{'itemid': '10', 'key_': 'topology.walk.core'}] if params['hostids'] == '2' else \
                [{'itemid': '11', 'key_': 'topology.push.heartbeat'}]
        if method == 'discoveryrule.get':
            if params['hostids'] == '2':
                return [{'itemid': '20', 'key_': 'topology.ports', 'master_itemid': '10'}]
            return [{'itemid': '21', 'key_': 'topology.push.ports', 'master_itemid': '0'},
                    {'itemid': '22', 'key_': 'topology.push.lldp', 'master_itemid': '0'}]
        raise AssertionError(method)


class Masters(unittest.TestCase):
    def test_push_host_has_trapper_rules_and_no_master_item(self):
        ms = zbx.masters(FakeApi(), ['Switch1', 'Router1'])
        self.assertIsNone(ms['Switch1']['core'])
        self.assertEqual(ms['Switch1']['push'], {'topology.push.ports': '21', 'topology.push.lldp': '22'})
        self.assertEqual(sorted(runner.left_ids(ms['Switch1'], 'push')), ['21', '22'])
        self.assertEqual(ms['Router1']['push'], {})
        self.assertEqual(runner.left_ids(ms['Router1'], 'core'), ['20'])


if __name__ == '__main__':
    unittest.main()
