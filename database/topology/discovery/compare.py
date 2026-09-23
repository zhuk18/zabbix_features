#!/usr/bin/env python3
"""
compare.py — F11 (topology-t-model-prototype-spec.md §9.1): fetches the G and T graphs for the
lab and diffs them. Nodes are matched by hostid (bound nodes) or by a normalized chassis id
(unbound nodes); links are matched by their two endpoint port names, order-independent.

Goes through the real backend classes rather than re-querying Zabbix independently in Python
(see dump_graph.php's own docstring for why) -- this script shells out to that PHP CLI helper
once per model and diffs the two JSON dumps it prints.

Per F11: the diff should be empty, or every difference classified in t-model-findings.md as
either (a) an expected model difference with a reference to the use case, or (b) a bug in one
of the prototypes. This script only produces the diff -- the classification is a human/findings-
doc step, deliberately not automated here (a script "classifying" its own findings would just be
moving the judgment call somewhere less visible).

Usage:
  compare.py [--php-bin php] [--repo-root <path to zabbix checkout>]
"""

from __future__ import annotations

import argparse
import json
import subprocess
import sys
from pathlib import Path


def dump(php_bin: str, script: Path, model: str) -> dict:
    result = subprocess.run([php_bin, str(script), f"--model={model}"],
                             capture_output=True, text=True, timeout=60)
    if result.returncode != 0:
        raise RuntimeError(f"dump_graph.php --model={model} failed: {result.stderr}")
    return json.loads(result.stdout)


def normalize_chassis(raw: str | None) -> str | None:
    """G-model chassis_id is a bare MAC-or-string; T-model's is 'c:<mac>' or 's:...' (§4.1).
    Strip the T-model prefix so the two are comparable on the same footing."""
    if raw is None:
        return None
    return raw[2:] if raw.startswith('c:') else raw


def node_key(model: str, device: dict) -> str:
    """A stable cross-model key for one node: hostid when bound, else normalized chassis id."""
    bound = device.get('bound') if model == 'T' else (device.get('type') == 'host')
    if bound:
        return f"host:{device['hostid']}"
    chassis = normalize_chassis(device.get('chassis_id'))
    return f"unbound:{chassis}"


def link_key(devices_by_id: dict, model: str, relation: dict) -> tuple[str, str, str, str] | None:
    """Canonical, order-independent key for one link: (sorted node-key pair, sorted port-name pair)."""
    src_dev = devices_by_id.get(relation['source'])
    dst_dev = devices_by_id.get(relation['target'])
    if src_dev is None or dst_dev is None:
        return None
    src_key = node_key(model, src_dev)
    dst_key = node_key(model, dst_dev)
    src_port = relation.get('source_port') or ''
    dst_port = relation.get('target_port') or ''
    pair = sorted([(src_key, src_port), (dst_key, dst_port)])
    return (pair[0][0], pair[0][1], pair[1][0], pair[1][1])


def build_index(model: str, graph: dict) -> tuple[dict, set]:
    devices_by_id = {d['id']: d for d in graph['devices']}
    node_keys = {node_key(model, d) for d in graph['devices']}
    link_keys = set()
    for relation in graph['relations']:
        key = link_key(devices_by_id, model, relation)
        if key is not None:
            link_keys.add(key)
    return node_keys, link_keys


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--php-bin', default='php')
    parser.add_argument('--repo-root', default=str(Path(__file__).resolve().parents[3]))
    args = parser.parse_args()

    script = Path(args.repo_root) / 'database' / 'topology' / 'discovery' / 'dump_graph.php'
    if not script.exists():
        print(f"dump_graph.php not found at {script}", file=sys.stderr)
        return 2

    g = dump(args.php_bin, script, 'G')
    t = dump(args.php_bin, script, 'T')

    g_nodes, g_links = build_index('G', g)
    t_nodes, t_links = build_index('T', t)

    only_g_nodes = sorted(g_nodes - t_nodes)
    only_t_nodes = sorted(t_nodes - g_nodes)
    only_g_links = sorted(g_links - t_links)
    only_t_links = sorted(t_links - g_links)

    print(f"G: {len(g['devices'])} devices, {len(g['relations'])} relations "
          f"({g['elapsed_ms']} ms assembly)")
    print(f"T: {len(t['devices'])} devices, {len(t['relations'])} relations "
          f"({t['elapsed_ms']} ms assembly)")
    print()

    empty = True
    if only_g_nodes:
        empty = False
        print(f"Nodes only in G ({len(only_g_nodes)}):")
        for n in only_g_nodes:
            print(f"  {n}")
    if only_t_nodes:
        empty = False
        print(f"Nodes only in T ({len(only_t_nodes)}):")
        for n in only_t_nodes:
            print(f"  {n}")
    if only_g_links:
        empty = False
        print(f"Links only in G ({len(only_g_links)}):")
        for l in only_g_links:
            print(f"  {l[0]}/{l[1]} -- {l[2]}/{l[3]}")
    if only_t_links:
        empty = False
        print(f"Links only in T ({len(only_t_links)}):")
        for l in only_t_links:
            print(f"  {l[0]}/{l[1]} -- {l[2]}/{l[3]}")

    if empty:
        print("Diff is empty: G and T graphs agree on every node and link.")
        return 0

    print()
    print("Diff is NOT empty -- per F11, classify each difference above in "
          "t-model-findings.md as (a) an expected model difference (cite the use case) or "
          "(b) a bug in one of the prototypes.")
    return 1


if __name__ == '__main__':
    sys.exit(main())
