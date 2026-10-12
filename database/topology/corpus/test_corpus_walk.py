"""The converter of corpus_walk.py: the net-snmp text of the value types, and (when the corpus is present) every file converts.

Run:  python3 -m unittest database/topology/corpus/test_corpus_walk.py   (the corpus tests skip when the folder is absent)
"""
import glob
import os
import unittest

import corpus_walk as cw


class TestFormat(unittest.TestCase):
    def test_types(self):
        f = cw.format_value
        self.assertEqual(f('2', '5'), 'INTEGER: 5')
        self.assertEqual(f('4', 'eth0'), 'STRING: "eth0"')
        self.assertEqual(f('4', ''), '""')                                       # an empty string has no type in snmpbulkwalk -On
        self.assertEqual(f('4x', '0011ff'), 'Hex-STRING: 00 11 FF ')             # net-snmp ends the hex bytes with a space
        self.assertEqual(f('4', 'a\\b"c'), 'STRING: "a\\\\b\\"c"')
        self.assertEqual(f('64', '10.0.0.1'), 'IpAddress: 10.0.0.1')
        self.assertEqual(f('67', '8640100'), 'Timeticks: (8640100) 1 day, 0:00:01.00')
        self.assertEqual(f('66', '7'), 'Gauge32: 7')
        with self.assertRaises(cw.WalkError):
            f('99', '1')


CORPUS = os.environ.get('TOPOLOGY_CORPUS', cw.CORPUS)


@unittest.skipUnless(os.path.isdir(os.path.join(CORPUS, 'librenms')), 'the vendor corpus is not present')
class TestRealCorpus(unittest.TestCase):
    def test_every_file_converts(self):
        files = sorted(glob.glob(os.path.join(CORPUS, 'librenms', '*.snmprec')))
        self.assertGreaterEqual(len(files), 100)
        for p in files:
            self.assertTrue(cw.convert_file(p, cw.CORE_ROOTS, False) is not None, p)


if __name__ == '__main__':
    unittest.main()
