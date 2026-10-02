"""Unit tests for variation/labmultiplex.py (two snmpsim 1.2.2 bugs).

Starts a private snmpsim on loopback and talks to it with net-snmp tools.
Run: python3 -m unittest database/topology/lab/tests/test_labmultiplex.py
"""
import os
import shutil
import subprocess
import sys
import tempfile
import time
import unittest

HERE = os.path.dirname(os.path.abspath(__file__))
VARIATION = os.path.join(os.path.dirname(HERE), 'variation')
RESPONDER = os.path.join(os.path.dirname(sys.executable), 'snmpsim-command-responder')
if not os.path.exists(RESPONDER):
    RESPONDER = shutil.which('snmpsim-command-responder')

ADDR, PORT, COMMUNITY = '127.0.0.78', 11611, 'dev'
CTL = '1.3.6.1.2.1.99999.0'
NAME = '1.3.6.1.2.1.1.5.0'
STEPS = 12      # 00000..00011: listdir order is not numeric


def snmp(tool, *args):
    return subprocess.run([tool, '-v2c', '-c', COMMUNITY, '-Oqv', '-t', '2', '-r', '0',
                           '%s:%d' % (ADDR, PORT), *args], capture_output=True, text=True)


class Base:
    module = 'labmultiplex'
    module_dir = VARIATION

    @classmethod
    def setUpClass(cls):
        if not (RESPONDER and shutil.which('snmpget') and shutil.which('snmpset')):
            raise unittest.SkipTest('snmpsim-command-responder or net-snmp tools missing')
        cls.tmp = tempfile.mkdtemp(prefix='labmux')
        steps = os.path.join(cls.tmp, 'data', 'steps')
        os.makedirs(steps)
        for n in range(STEPS):
            with open(os.path.join(steps, '%05d.snmprec' % n), 'w') as f:
                f.write('%s|4|step%d\n' % (NAME, n))
        with open(os.path.join(cls.tmp, 'data', COMMUNITY + '.snmprec'), 'w') as f:
            f.write('1.3.6.1.2.1|:%s|dir=%s,control=%s\n' % (cls.module, steps, CTL))
        cmd = [RESPONDER, '--data-dir=' + os.path.join(cls.tmp, 'data'),
               '--agent-udpv4-endpoint=%s:%d' % (ADDR, PORT)]
        if cls.module_dir:
            cmd.append('--variation-modules-dir=' + cls.module_dir)
        cls.proc = subprocess.Popen(cmd, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        for _ in range(50):
            if snmp('snmpget', CTL).returncode == 0:
                return
            time.sleep(0.2)
        cls.tearDownClass()
        raise RuntimeError('snmpsim did not start')

    @classmethod
    def tearDownClass(cls):
        cls.proc.terminate()
        cls.proc.wait()
        shutil.rmtree(cls.tmp, ignore_errors=True)


class TestLabMultiplex(Base, unittest.TestCase):
    def test_initial_step_is_numerically_first(self):
        # bug 1: step 0 must serve 00000.snmprec whatever os.listdir() returns
        self.assertEqual(snmp('snmpget', NAME).stdout.strip(), '"step0"')

    def test_set_control_switches_every_step_in_numeric_order(self):
        # bug 2: SET on the control OID must work; bug 1: value N = file N
        for n in (1, 10, 2, 11, 0):
            r = snmp('snmpset', CTL, 'i', str(n))
            self.assertEqual(r.returncode, 0, r.stderr + r.stdout)
            self.assertEqual(snmp('snmpget', CTL).stdout.strip(), str(n))
            self.assertEqual(snmp('snmpget', NAME).stdout.strip(), '"step%d"' % n)

    def test_set_over_limit_is_rejected(self):
        snmp('snmpset', CTL, 'i', str(STEPS))
        self.assertEqual(snmp('snmpget', CTL).stdout.strip(), '0')
        self.assertEqual(snmp('snmpget', NAME).stdout.strip(), '"step0"')

    def test_control_oid_is_not_in_a_walk(self):
        out = snmp('snmpwalk', '1.3.6.1.2.1').stdout
        self.assertNotIn('99999', out)
        rows = [x for x in out.splitlines() if 'No more variables' not in x]
        self.assertEqual(rows, ['"step0"'])


if __name__ == '__main__':
    unittest.main()
