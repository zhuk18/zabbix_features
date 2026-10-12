"""Corpus devices (`corpus_devices:`), the batch plan of scenario SC, and the converter against snmpsim's text. Written with small snmprec
files made here; the real corpus (GPLv3, /media/sf_topology-specs/corpus) is read only when it is present.

Run from database/topology/lab/:  python3 -m unittest discover -s tests
"""
import os
import sys
import tempfile
import unittest

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

from labsim import corpus, gen, model  # noqa: E402

SNMPREC = '''1.3.6.1.2.1.1.5.0|4|sw1
1.3.6.1.2.1.2.2.1.2.10|4|eth10
1.3.6.1.2.1.2.2.1.2.9|4|eth9
1.0.8802.1.1.2.1.3.2.0|4x|001122334455
1.0.8802.1.1.2.1.4.1.1.5.0.1.1|4x|0011223344aa
'''


def write(directory, name, text=SNMPREC):
    path = os.path.join(directory, name + '.snmprec')
    with open(path, 'w') as f:
        f.write(text)
    return path


def lab_with(path, **extra):
    return model.Lab({'settings': {'community': 'lab', 'snmp_port': 1611, 'database': 'zabbix'},
                      'corpus_devices': {'c1': dict({'snmprec': path, 'ip': '127.0.10.1'}, **extra)}})


class TestCorpusDevices(unittest.TestCase):
    def test_device_is_monitored_and_needs_no_chassis(self):
        with tempfile.TemporaryDirectory() as d:
            lab = lab_with(write(d, 'c1'), transport='push')
            lab.validate()
            self.assertEqual(list(lab.monitored()), ['c1'])
            self.assertEqual(lab.devices['c1']['zabbix']['transport'], 'push')

    def test_unknown_field_and_missing_ip_are_errors(self):
        with tempfile.TemporaryDirectory() as d:
            p = write(d, 'c1')
            with self.assertRaises(model.LabError):
                lab_with(p, cables=[])
            with self.assertRaises(model.LabError):
                model.Lab({'corpus_devices': {'c1': {'snmprec': p}}})
            with self.assertRaises(model.LabError):
                lab_with(p, transport='snmp')

    def test_patch_adds_corpus_device(self):
        with tempfile.TemporaryDirectory() as d:
            lab = model.Lab({'settings': {'community': 'lab'}})
            lab.apply_patch({'corpus_devices': {'c2': {'snmprec': write(d, 'c2'), 'ip': '127.0.10.2'}}})
            self.assertIn('c2', lab.monitored())

    def test_records_sorted_numerically_with_end_record(self):
        with tempfile.TemporaryDirectory() as d:
            text = gen.lines(gen.corpus_records(write(d, 'c1')))
        oids = [l.split('|')[0] for l in text.splitlines()]
        self.assertEqual(oids, sorted(oids, key=lambda o: tuple(int(x) for x in o.split('.'))))
        self.assertLess(oids.index('1.3.6.1.2.1.2.2.1.2.9'), oids.index('1.3.6.1.2.1.2.2.1.2.10'))      # numeric, not alphabetical
        self.assertEqual(oids[-1], '1.3.6.1.4.1.99999.1.0')

    def test_line_that_does_not_parse_stops_the_generator(self):
        with tempfile.TemporaryDirectory() as d:
            with self.assertRaises(model.LabError):
                gen.corpus_records(write(d, 'bad', SNMPREC + 'this is not a record\n'))
            with self.assertRaises(model.LabError):
                gen.corpus_records(write(d, 'empty', '\n'))
            with self.assertRaises(model.LabError):
                gen.corpus_records(os.path.join(d, 'missing.snmprec'))

    def test_generate_writes_one_static_file_per_device(self):
        with tempfile.TemporaryDirectory() as d:
            sc = corpus.batch_scenario([write(d, 'c1'), write(d, 'c2')], lab=model.Lab({'settings': {'community': 'lab'}}))
            m = gen.generate(sc, os.path.join(d, 'out'))
            self.assertEqual(sorted(m['devices']), ['c1', 'c2'])
            self.assertEqual(m['devices']['c1']['mux'], {})
            self.assertTrue(os.path.exists(os.path.join(d, 'out', 'c1', 'lab.snmprec')))
            self.assertEqual([x['host'] for x in gen.hosts_json(sc)], ['c1', 'c2'])

    def test_precheck_and_batches(self):
        with tempfile.TemporaryDirectory() as d:
            a = write(d, 'a')                                           # local 001122334455, neighbor 0011223344aa
            b = write(d, 'b', SNMPREC.replace('001122334455', '0011223344aa').replace('0011223344aa|', '00aabbccddee|'))
            c = write(d, 'c', SNMPREC.replace('001122334455', '0000000000c1').replace('0011223344aa', '0000000000c2'))
            shared = corpus.precheck([a, b, c])
            self.assertEqual(sorted(shared.values()), [['a', 'b']])      # b's local id is a's neighbor
            batches = corpus.plan_batches([a, b, c], shared, size=30)
            self.assertEqual([[corpus.host_name(p) for p in x] for x in batches], [['a', 'c'], ['b']])
            small = corpus.plan_batches([a, c], {}, size=1)
            self.assertEqual(len(small), 2)

    def test_step_cannot_change_a_corpus_device(self):
        with tempfile.TemporaryDirectory() as d:
            lab = lab_with(write(d, 'c1'))
            with self.assertRaises(model.LabError):
                lab.apply_step({'devices': {'c1': {'lldp': False}}})

    def test_anchors(self):
        with tempfile.TemporaryDirectory() as d:
            r = gen.corpus_records(write(d, 'c1', '1.3.6.1.2.1.2.2.1.2.1|4|e1\n1.0.8802.1.1.2.1.3.7.1.4.1|4|e1\n'))
        self.assertEqual(corpus.anchors_in(r), {'ports': True, 'lldp': True})


CORPUS = os.environ.get('TOPOLOGY_CORPUS', corpus.CORPUS_DEFAULT)


@unittest.skipUnless(os.path.isdir(os.path.join(CORPUS, 'librenms')), 'the vendor corpus is not present')
class TestRealCorpus(unittest.TestCase):
    def test_every_file_parses_and_generates(self):
        files = corpus.corpus_files(CORPUS)
        self.assertGreaterEqual(len(files), 100)
        for p in files:
            gen.lines(gen.corpus_records(p))

    def test_plan_keeps_shared_ids_apart_and_batches_small(self):
        files = corpus.corpus_files(CORPUS)
        shared = corpus.precheck(files)
        for batch in corpus.plan_batches(files, shared):
            self.assertLessEqual(len(batch), corpus.BATCH)
            names = {corpus.host_name(p) for p in batch}
            for group in shared.values():
                self.assertLessEqual(len(names & set(group)), 1)


if __name__ == '__main__':
    unittest.main()
