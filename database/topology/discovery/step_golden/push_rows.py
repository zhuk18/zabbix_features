#!/usr/bin/env python3
"""Runs walks through push.py's decoder: reads a JSON list of {"source", "walk"} on stdin, writes a JSON list with the rows of each walk (null when push.py
refuses the walk). Used by test_generated.php to compare push.py with the native step on generated values (handoff 060)."""
import json
import os
import sys

sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), ".."))
import push  # noqa: E402

out = []
for case in json.load(sys.stdin):
    builder = push.rows_ports if case["source"] == "ports" else push.rows_lldp
    try:
        out.append(builder(push.parse_walk(case["walk"])))
    except push.WalkUnusable:
        out.append(None)
json.dump(out, sys.stdout)
