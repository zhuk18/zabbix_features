"""The Python twin of the JSON condition helper (topology_json.py) gives the same SQL text as the PHP helper (topology_json.inc.php), task 133 (model §18, 2.111).
Needs `php` on the path; no database.

Run from database/topology/lab/:  python3 -m unittest discover -s tests
"""
import os
import subprocess
import sys
import unittest

HERE = os.path.dirname(os.path.abspath(__file__))
TOPOLOGY = os.path.dirname(os.path.dirname(HERE))
sys.path.insert(0, TOPOLOGY)

import topology_json as tj  # noqa: E402

CASES = [('attrs', '$.if_index'), ('attrs', '$.pseudo'), ('attrs', '$.a.b'), ('t.attrs', '$.name')]
PAIRS = [('number', 'number'), ('text', 'text'), ('is_true', 'isTrue'), ('is_false', 'isFalse'), ('exists', 'exists'), ('missing', 'missing')]


def php_sql(method, column, path):
    code = "require '%s/topology_json.inc.php'; echo TopologyJson::%s($argv[1], $argv[2]);" % (TOPOLOGY, method)
    return subprocess.run(['php', '-r', code, column, path], capture_output=True, text=True, check=True).stdout


class TopologyJsonTwin(unittest.TestCase):
    def test_same_sql_as_php(self):
        for py, php in PAIRS:
            for column, path in CASES:
                self.assertEqual(getattr(tj, py)(column, path), php_sql(php, column, path), (py, column, path))

    def test_refuses_bad_column_or_path(self):
        for column, path in [('attrs; DROP', '$.a'), ('attrs', "$.a') OR (1=1"), ('attrs', '$..a'), ('attrs', 'a'), ('At', '$.a')]:
            with self.assertRaises(ValueError):
                tj.text(column, path)


if __name__ == '__main__':
    unittest.main()
