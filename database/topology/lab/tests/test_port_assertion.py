"""Lab spec 2.25 (task 089): the assertion `port` (same_id_as_step, if_index, itemids_same_as, itemids) and the step key `poll_interface_discovery`."""
import os
import sys
import unittest

sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), '..'))
from labsim import model, runner  # noqa: E402


def port(device, name, id_, if_index, itemids, pseudo=False):
    return {'device': device, 'name': name, 'id': id_, 'if_index': if_index, 'itemids': sorted(itemids), 'pseudo': pseudo}


STEP0 = {'port_nodes': [port('R', 'Gi0/1', 10, 1, [101]), port('R', 'Gi0/2', 11, 2, [102])]}
NOW = {'port_nodes': [port('R', 'Gi0/1', 10, 2, [102]), port('R', 'Gi0/2', 11, 11, []), port('R', 'x', 12, -5, [], True)]}


def check(a, state=NOW, history=None):
    return runner.evaluate(a, state, {}, history if history is not None else {0: STEP0}, {}, 1)


class PortAssertion(unittest.TestCase):
    def test_same_id_and_if_index(self):
        self.assertEqual(check({'port': 'R:Gi0/1', 'same_id_as_step': 0, 'if_index': 2}).status, 'pass')
        self.assertEqual(check({'port': 'R:Gi0/1', 'same_id_as_step': 0, 'if_index': 3}).status, 'fail')
        moved = {'port_nodes': [port('R', 'Gi0/1', 99, 2, [102])]}
        self.assertEqual(check({'port': 'R:Gi0/1', 'same_id_as_step': 0}, moved).status, 'fail')

    def test_itemids(self):
        self.assertEqual(check({'port': 'R:Gi0/2', 'itemids': []}).status, 'pass')
        self.assertEqual(check({'port': 'R:Gi0/1', 'itemids': []}).status, 'fail')
        # R:Gi0/1 now has the items that R:Gi0/2 had in step 0
        self.assertEqual(check({'port': 'R:Gi0/1', 'itemids_same_as': {'step': 0, 'port': 'R:Gi0/2'}}).status, 'pass')
        self.assertEqual(check({'port': 'R:Gi0/1', 'itemids_same_as': {'step': 0, 'port': 'R:Gi0/1'}}).status, 'fail')
        # an empty list is not "the same items" (the assertion is about items that follow)
        self.assertEqual(check({'port': 'R:Gi0/2', 'itemids_same_as': {'step': 0, 'port': 'R:Gi0/2'}}).status, 'fail')

    def test_missing_port_and_pseudo(self):
        self.assertEqual(check({'port': 'R:nope'}).status, 'fail')
        self.assertEqual(check({'port': 'R:x'}).status, 'fail')      # a pseudo port is not a Port of the reporter

    def test_validation(self):
        model.validate_assertion('S', 1, {'port': 'R:Gi0/1', 'same_id_as_step': 0, 'if_index': 11, 'itemids': []})
        with self.assertRaises(model.LabError):
            model.validate_assertion('S', 1, {'port': 'R:Gi0/1', 'bogus': 1})


if __name__ == '__main__':
    unittest.main()
