#!/usr/bin/env python3
"""
test_push.py — tests of push.py's contract rows (topology-push-transport-spec.md §3, §7).

  python3 test_push.py

* Equivalence: for every walk of lld_js/fixtures and step_golden/fixtures, the ports and LLDP rows push.py builds
  equal step_golden/expected/*.json (the output of the reference JavaScript and of the native step), as sets. This
  covers "identity bytes": every {#LOC_CHASSIS}, {#REM_CHASSIS}, {#IFMAC} and {#REM_MGMT_IP} is the step's.
* Failed != empty (FR 5.d): an unusable walk, or a walk without IF-MIB, sends nothing for the role; an LLDP table
  without rows is "[]".
* Sending: one zabbix_sender call with -T, a value per collected role, the heartbeat always.

No SNMP device and no Zabbix server are needed (the walk text and the zabbix_sender call are stubbed).
"""

import argparse
import glob
import json
import os
import sys
import tempfile
import types

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, HERE)
import push  # noqa: E402

checks = 0
failures = 0


def check(condition: bool, description: str, detail: str = "") -> None:
    global checks, failures
    checks += 1
    if not condition:
        failures += 1
    print(("  ok: " if condition else "  FAIL: ") + description + ("" if condition or not detail else "\n        " + detail))


def canonical(rows):
    return sorted(json.dumps(r, sort_keys=True, ensure_ascii=False, separators=(",", ":")) for r in rows)


print("\n=== equivalence with the native step / reference JavaScript (step_golden/expected) ===")
walks = sorted(glob.glob(os.path.join(HERE, "lld_js", "fixtures", "*.walk")) +
               glob.glob(os.path.join(HERE, "step_golden", "fixtures", "*.walk")))
compared = 0
for path in walks:
    name = os.path.basename(path)[:-len(".walk")]
    text = open(path, encoding="utf-8").read()
    rows = push.parse_walk(text)
    for role, builder in (("ports", push.rows_ports), ("lldp", push.rows_lldp)):
        expected_path = os.path.join(HERE, "step_golden", "expected", f"{name}.{role}.json")
        if not os.path.exists(expected_path):
            continue
        expected = json.load(open(expected_path, encoding="utf-8"))
        try:
            got = builder(rows)
        except push.WalkUnusable:
            # ports without IF-MIB: the step's `error` anchor gives no snapshot; the expected file is the JS' empty result
            check(role == "ports" and expected == [], f"{name} {role}: no IF-MIB, nothing sent (JS gives {len(expected)} rows)")
            continue
        compared += 1
        check(canonical(got) == canonical(expected), f"{name} {role}: {len(got)} rows equal the reference",
              f"push: {canonical(got)[:3]}\n        ref:  {canonical(expected)[:3]}")
check(compared >= 20, f"{compared} fixture x role comparisons were made")

print("\n=== identity bytes ===")
identity_keys = ("{#LOC_CHASSIS}", "{#REM_CHASSIS}", "{#REM_CHASSIS_TYPE}", "{#REM_MGMT_IP}", "{#IFMAC}")
seen = 0
for path in walks:
    name = os.path.basename(path)[:-len(".walk")]
    rows = push.parse_walk(open(path, encoding="utf-8").read())
    for role, builder in (("ports", push.rows_ports), ("lldp", push.rows_lldp)):
        expected_path = os.path.join(HERE, "step_golden", "expected", f"{name}.{role}.json")
        if not os.path.exists(expected_path):
            continue
        try:
            got = builder(rows)
        except push.WalkUnusable:
            continue
        expected = json.load(open(expected_path, encoding="utf-8"))
        key_of = lambda r: (r.get("{#IFINDEX}", ""), r.get("{#REM_PORT}", ""), r.get("{#REM_CHASSIS}", ""))
        by_key = {key_of(r): r for r in expected}
        for r in got:
            ref = by_key.get(key_of(r), {})
            for key in identity_keys:
                if key in r or key in ref:
                    seen += 1
                    if r.get(key) != ref.get(key):
                        check(False, f"{name} {role} {key}: {r.get(key)!r} != {ref.get(key)!r}")
check(seen > 50, f"{seen} identity values compared byte for byte, none differs")

print("\n=== failed is not empty ===")
lab = open(os.path.join(HERE, "lld_js", "fixtures", "lab-router1.walk"), encoding="utf-8").read()
values, problems = push.build_role_values(lab)
check(set(values) == {push.ROLE_PORTS_KEY, push.ROLE_LLDP_KEY} and not problems, "a healthy walk gives both roles")
try:
    push.build_role_values("")
    check(False, "an empty walk is unusable")
except push.WalkUnusable:
    check(True, "an empty walk is unusable: nothing for any role")
no_ifmib = "\n".join(l for l in lab.splitlines() if "1.3.6.1.2.1.2.2.1" not in l and "1.3.6.1.2.1.31.1.1.1" not in l)
values, problems = push.build_role_values(no_ifmib)
check(push.ROLE_PORTS_KEY not in values and len(problems) == 1 and push.ROLE_LLDP_KEY in values,
      "no IF-MIB: the ports role is left out, LLDP is still sent")
no_neighbors = "\n".join(l for l in lab.splitlines() if "1.0.8802.1.1.2.1.4." not in l)
values, problems = push.build_role_values(no_neighbors)
check(values.get(push.ROLE_LLDP_KEY) == "[]" and not problems, "LLDP read and without rows: \"[]\" (empty is empty)")


print("\n=== sending ===")
captured = {}


def fake_run(cmd, **kwargs):
    captured["cmd"] = cmd
    captured["input"] = open(cmd[cmd.index("-i") + 1], encoding="utf-8").read()
    return types.SimpleNamespace(returncode=0, stdout="processed: 3; failed: 0; total: 3", stderr="")


real_run = push.subprocess.run
push.subprocess.run = fake_run
try:
    values = {push.ROLE_PORTS_KEY: '[{"{#IFINDEX}":"1"}]', push.ROLE_LLDP_KEY: "[]"}
    push.send_role_values("Router1", values, 1790000000, 1790000100, "127.0.0.1", 10051, "zabbix_sender")
finally:
    push.subprocess.run = real_run
lines = [l.split("\t", 3) for l in captured["input"].splitlines()]
check("-T" in captured["cmd"], "zabbix_sender is called with -T (value timestamps)")
check(lines[0] == ["Router1", push.ROLE_PORTS_KEY, "1790000000", '[{"{#IFINDEX}":"1"}]'], "ports value carries the collection time")
check(lines[1] == ["Router1", push.ROLE_LLDP_KEY, "1790000000", "[]"], "LLDP value carries the collection time")
check(lines[2][:2] == ["Router1", push.HEARTBEAT_KEY] and lines[2][2] == "1790000100", "the heartbeat is in the same call")
captured.clear()
push.subprocess.run = fake_run
try:
    push.send_role_values("Router1", {}, 1790000000, 1790000100, "127.0.0.1", 10051, "zabbix_sender")
finally:
    push.subprocess.run = real_run
check(len(captured["input"].splitlines()) == 1 and push.HEARTBEAT_KEY in captured["input"],
      "every role failed: only the heartbeat is sent")


print("\n=== a failed walk sends nothing but the heartbeat ===")
sent = {}
real_collect, real_send = push.collect_walk, push.send_role_values
push.collect_walk = lambda *a, **k: (_ for _ in ()).throw(push.SnmpError("snmpbulkwalk failed: Timeout"))
push.send_role_values = lambda host, values, *a, **k: sent.update(values=values)
try:
    cfg = push.ReporterConfig(name="R", zabbix_host="R", mgmt_ip="192.0.2.1", snmp_target="127.0.0.1", snmp_port=1611)
    ok = push.run_reporter(cfg, argparse.Namespace(legacy_blob=False, dry_run=False, sender_server="x", sender_port=1,
                                                   sender_bin="x"))
finally:
    push.collect_walk, push.send_role_values = real_collect, real_send
check(ok is False and sent.get("values") == {}, "SNMP failure: no role values, the call still happens (heartbeat), run reports failure")

print("\n=== heartbeat key ===")
check(push.HEARTBEAT_KEY == "topology.push.heartbeat" and push.HEARTBEAT_KEY != push.HEARTBEAT_ITEM_KEY,
      "push sends topology.push.heartbeat; the legacy blob keeps topology.discovery.heartbeat")


print("\n=== a truncated walk is a failure (spec §2 rule 3) ===")
REAL_COLLECT_WALK = push.collect_walk
lab_lines = lab.splitlines()
walk_by_root = {}
for oid in push.CONTRACT_WALK_OIDS:
    walk_by_root[oid] = [l for l in lab_lines if l.lstrip(".").startswith(oid + ".")]


def run_with(mutations):
    """collect_walk() against a stubbed snmpbulkwalk: the lab walk, with `mutations` {root oid: lines} replacing a subtree."""
    def fake(cmd, **kwargs):
        root = cmd[-1]
        lines = mutations.get(root, walk_by_root[root])
        return types.SimpleNamespace(returncode=0, stdout="\n".join(lines) + ("\n" if lines else ""), stderr="")
    real = push.subprocess.run
    push.subprocess.run = fake
    try:
        return REAL_COLLECT_WALK("127.0.0.1", 161, "public", "2c")
    finally:
        push.subprocess.run = real


rem = push.CONTRACT_WALK_OIDS[6]                                    # lldpRemTable
rem_lines = walk_by_root[rem]
check(len(rem_lines) >= 4, f"the lab LLDP table has {len(rem_lines)} variables to truncate")

text, failed = run_with({})
values, problems = push.build_role_values(text, failed)
check(not failed and set(values) == {push.ROLE_PORTS_KEY, push.ROLE_LLDP_KEY}, "control: an intact walk gives both roles")

swapped = rem_lines[:2] + [rem_lines[3], rem_lines[2]] + rem_lines[4:]
text, failed = run_with({rem: swapped})
values, problems = push.build_role_values(text, failed)
check(rem in failed and "non-increasing" in failed[rem], "a non-increasing OID in lldpRemTable fails that walk")
check(push.ROLE_LLDP_KEY not in values and push.ROLE_PORTS_KEY in values and len(problems) == 1,
      "the LLDP role is not sent, the ports role still is")

early = rem_lines[:3] + [" ." + rem_lines[2].lstrip(" .").split(" =")[0] + " = No more variables left in this MIB View (It is past the end of the MIB tree)"]
text, failed = run_with({rem: early})
values, problems = push.build_role_values(text, failed)
check(rem in failed and "endOfMibView" in failed[rem] and push.ROLE_LLDP_KEY not in values,
      "an early endOfMibView after rows of lldpRemTable fails the role: the table is not shorter, it is cut")

empty_end = [" ." + rem + " = No more variables left in this MIB View (It is past the end of the MIB tree)"]
text, failed = run_with({rem: empty_end})
values, problems = push.build_role_values(text, failed)
check(not failed and values.get(push.ROLE_LLDP_KEY) == "[]",
      "an empty subtree at the end of the MIB view (endOfMibView for the root itself) is an empty table: \"[]\"")

ports_oid = push.CONTRACT_WALK_OIDS[1]                              # ifTable
bad_ports = list(reversed(walk_by_root[ports_oid]))
text, failed = run_with({ports_oid: bad_ports})
values, problems = push.build_role_values(text, failed)
check(push.ROLE_PORTS_KEY not in values and push.ROLE_LLDP_KEY not in values,
      "a broken ifTable fails every role that reads it (ports and LLDP)")

# end to end: the run sends the heartbeat and only the intact role
sent = {}
real_collect, real_send = push.collect_walk, push.send_role_values
push.collect_walk = lambda *a, **k: run_with({rem: swapped})
push.send_role_values = lambda host, values, *a, **k: sent.update(values=dict(values))
try:
    cfg = push.ReporterConfig(name="R", zabbix_host="R", mgmt_ip="192.0.2.1", snmp_target="127.0.0.1", snmp_port=1611)
    ok = push.run_reporter(cfg, argparse.Namespace(legacy_blob=False, dry_run=False, sender_server="x", sender_port=1,
                                                   sender_bin="x"))
finally:
    push.collect_walk, push.send_role_values = real_collect, real_send
check(ok is False and list(sent.get("values", {})) == [push.ROLE_PORTS_KEY],
      "the run sends the ports role (and the heartbeat), not the LLDP role")

print(f"\n{checks - failures} of {checks} checks passed.")
sys.exit(1 if failures else 0)
