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

    def test_walk_text_of_octet_strings(self):
        # measured through snmpsim and the real walk[] item in task 023/024 (corpus/walk_text_check.py)
        table = [(b"plain", 'STRING: "plain"'), (b"a\\b", 'STRING: "a\\\\b"'), (b'a"b', 'STRING: "a\\"b"'), (b"a\nb", 'STRING: "a\nb"'),
                 (b"a\x00b", "Hex-STRING: 61 00 62 "), (b"a\x01b", "Hex-STRING: 61 01 62 "), ("ä€".encode(), 'STRING: "ä€"'),
                 (b"a\xffb", "Hex-STRING: 61 FF 62 "), (b"a\tb", 'STRING: "a\tb"'), (b"a\rb", 'STRING: "a\rb"'), (b"a\x7fb", "Hex-STRING: 61 7F 62 "),
                 ("a\u0085b".encode(), 'STRING: "a\x85b"'), (b"a\xe4b", "Hex-STRING: 61 E4 62 "), (b"", '""'), (b"abcdef", 'STRING: "abcdef"'),
                 ("äää".encode(), "Hex-STRING: C3 A4 C3 A4 C3 A4 "), (b"a\x0bb", 'STRING: "a\x0bb"'), (b"ab ", 'STRING: "ab "')]
        for raw, want in table:
            self.assertEqual(cw.walk_string(raw), want, raw)

    def test_lab_generator_writes_the_same_text(self):
        sys_path = os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "lab")
        import sys
        sys.path.insert(0, sys_path)
        try:
            from labsim import gen
        except ModuleNotFoundError as e:       # PyYAML is the lab's, not this tool's
            self.skipTest(str(e))
        for raw in (b"plain", b"a\\b", b'a"b', b"a\nb", b"a\x00b", "ä€".encode(), b"a\xffb", b"", b"abcdef", "äää".encode()):
            self.assertEqual(gen._walk_string(raw), cw.walk_string(raw), raw)


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
