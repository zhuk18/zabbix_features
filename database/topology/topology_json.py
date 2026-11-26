"""SQL conditions on the JSON `attrs` of the topology tables that give the same result on MariaDB and on MySQL 8.4 (topology-1-model.md §18, 2.111; task 133): the Python twin of
topology_json.inc.php (the same SQL text; `tests/test_topology_json.py` of the lab compares the two). Rule: a JSON value is never compared with a value of another type; it is read as a number,
an unquoted string or a boolean text. The column and the path are constants of the code (checked)."""
import re


def _expression(column, path):
    if not re.fullmatch(r'[a-z_][a-z0-9_]*(\.[a-z_][a-z0-9_]*)?', column):
        raise ValueError('not a column: %s' % column)
    if not re.fullmatch(r'\$(\.[A-Za-z_][A-Za-z0-9_]*)+', path):
        raise ValueError('not a JSON path of keys: %s' % path)
    return "JSON_EXTRACT(%s, '%s')" % (column, path)


def number(column, path):
    return 'CAST(JSON_UNQUOTE(%s) AS SIGNED)' % _expression(column, path)


def text(column, path):
    return 'JSON_UNQUOTE(%s)' % _expression(column, path)


def is_true(column, path):
    return "(%s = 'true')" % text(column, path)


def is_false(column, path):
    return "(%s = 'false')" % text(column, path)


def exists(column, path):
    return '(%s IS NOT NULL)' % _expression(column, path)


def missing(column, path):
    return '(%s IS NULL)' % _expression(column, path)
