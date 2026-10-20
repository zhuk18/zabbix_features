"""--reset keeps the snapshots of the rules that have the step "Discard unchanged with heartbeat", found by the step and not by the key (lab spec 8.2).
Runs on a scratch MySQL database `topo_t_lab_reset` made and dropped by the test (the same kind of database as run_php_tests.sh); skipped without MySQL.
Run from database/topology/lab/:  python3 -m unittest discover -s tests"""
import os
import sys
import unittest

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

from labsim import db  # noqa: E402

try:
    import mysql.connector
except ImportError:                                                  # pragma: no cover
    mysql = None

SCRATCH = 'topo_t_lab_reset'


@unittest.skipIf(mysql is None, 'mysql.connector is not installed')
class ResetBySteps(unittest.TestCase):
    def setUp(self):
        try:
            self.conn = mysql.connector.connect(user='zabbix', password='', host='localhost', autocommit=True)
        except mysql.connector.Error as e:
            self.skipTest('no MySQL: %s' % e)
        self.addCleanup(self.drop)
        cur = self.conn.cursor()
        cur.execute('DROP DATABASE IF EXISTS %s' % SCRATCH)
        cur.execute('CREATE DATABASE %s' % SCRATCH)
        cur.execute('USE %s' % SCRATCH)
        for sql in ('CREATE TABLE hosts (hostid BIGINT PRIMARY KEY, host VARCHAR(64))',
                    'CREATE TABLE items (itemid BIGINT PRIMARY KEY, hostid BIGINT, key_ VARCHAR(255))',
                    'CREATE TABLE item_preproc (item_preprocid BIGINT PRIMARY KEY, itemid BIGINT, type INT)',
                    'CREATE TABLE topo_lld_snapshot (itemid BIGINT PRIMARY KEY, clock INT)',
                    'CREATE TABLE topo_identity_keys (id INT)', 'CREATE TABLE topo_observations (id INT)', 'CREATE TABLE topo_edges (id INT)', 'CREATE TABLE topo_nodes (id INT)'):
            cur.execute(sql)
        cur.execute("INSERT INTO hosts VALUES (1, 'A'), (2, 'B')")
        # 10: A/net.if.discovery WITH the step; 11: A/net.if.discovery WITHOUT it; 12: A/custom.ports WITH the step (another key); 13: A/topology.lldp (no step);
        # 20: B/custom.ports WITH the step, but B is not a host of the scenario
        cur.execute("INSERT INTO items VALUES (10, 1, 'net.if.discovery'), (11, 1, 'net.if.discovery'), (12, 1, 'custom.ports'), (13, 1, 'topology.lldp'), (20, 2, 'custom.ports')")
        cur.execute("INSERT INTO item_preproc VALUES (1, 10, %d), (2, 12, %d), (3, 20, %d), (4, 13, 12)" % ((db.DISCARD_UNCHANGED_HEARTBEAT,) * 3))
        cur.execute("INSERT INTO topo_lld_snapshot VALUES (10, 1), (11, 1), (12, 1), (13, 1), (20, 1)")
        cur.execute("INSERT INTO topo_nodes VALUES (1), (2)")

    def drop(self):
        self.conn.cursor().execute('DROP DATABASE IF EXISTS %s' % SCRATCH)
        self.conn.close()

    def kept(self):
        cur = self.conn.cursor()
        cur.execute('SELECT itemid FROM topo_lld_snapshot ORDER BY itemid')
        return [r[0] for r in cur.fetchall()]

    def test_a_rule_with_the_step_is_kept_whatever_its_key(self):
        db.reset_topo(self.conn, ['A'])
        self.assertIn(12, self.kept())                                # another key, with the step: kept

    def test_net_if_discovery_without_the_step_is_deleted(self):
        db.reset_topo(self.conn, ['A'])
        self.assertNotIn(11, self.kept())

    def test_only_the_rules_with_the_step_of_the_hosts_of_the_scenario(self):
        db.reset_topo(self.conn, ['A'])
        self.assertEqual(self.kept(), [10, 12])                       # 13 has no step, 20 is a host that the scenario does not use
        cur = self.conn.cursor()
        cur.execute('SELECT COUNT(*) FROM topo_nodes')
        self.assertEqual(cur.fetchone()[0], 0)

    def test_without_hosts_nothing_stays(self):
        db.reset_topo(self.conn)
        self.assertEqual(self.kept(), [])


if __name__ == '__main__':
    unittest.main()
