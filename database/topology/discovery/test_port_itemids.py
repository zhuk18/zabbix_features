#!/usr/bin/env python3
"""test_port_itemids.py — the PORTS rows carry the ids of the items made from them (topology-2-lld-delivery.md §3.4), against a live server.

Lab test, not a unit test: needs a running zabbix_server and a host that has the standard interface discovery rule (key net.if.discovery) with
the role PORTS and has been through at least one LLD run (the lab provisioning does that; a lab run leaves the snapshots in the database).
Read only. Usage:  test_port_itemids.py HOST [--db zabbix] [--db-user zabbix]    (the password from MYSQL_PWD if one is needed)

Checks, for the rule net.if.discovery of HOST: every snapshot row has a non-empty `itemids`, sorted, without duplicates; each id is an item of
HOST that was discovered from this rule (item_discovery -> item prototype -> rule) and belongs to the row (its key ends with the row's if_index);
the topology.ports rule of the same host (no item prototypes) has `itemids: []` in every row.
"""
import argparse
import json
import subprocess
import sys

ap = argparse.ArgumentParser()
ap.add_argument("host")
ap.add_argument("--db", default="zabbix")
ap.add_argument("--db-user", default="zabbix")
args = ap.parse_args()
failures = 0


def check(ok, text):
    global failures
    print(("  ok: " if ok else "FAIL: ") + text)
    failures += 0 if ok else 1


def q(sql):
    r = subprocess.run(["mysql", f"-u{args.db_user}", args.db, "-N", "-B", "-e", sql], capture_output=True, text=True)
    if r.returncode:
        sys.exit("mysql: " + r.stderr)
    return [line.split("\t") for line in r.stdout.splitlines()]


def snapshot_rows(key):
    res = q("select s.rows_json, i.itemid from topo_lld_snapshot s join items i on i.itemid=s.itemid join hosts h on h.hostid=i.hostid "
            f"where h.host='{args.host}' and i.key_='{key}' and s.role=1")
    return (json.loads(res[0][0]), res[0][1]) if res else (None, None)


rows, rule = snapshot_rows("net.if.discovery")
if rows is None:
    sys.exit(f"{args.host}: no snapshot of net.if.discovery with the role PORTS (link the interface template, set the role, let the rule run)")

print("\n=== net.if.discovery ===")
check(len(rows) > 0, f"the snapshot has {len(rows)} rows")
for row in rows:
    ids = row.get("itemids")
    check(isinstance(ids, list) and ids != [] and ids == sorted(set(ids)), f"ifIndex {row['if_index']}: itemids is a sorted list without duplicates ({len(ids or [])} items)")
    found = q("select i.itemid, i.key_, i.hostid=(select hostid from hosts where host='%s'), pd.lldruleid "
              "from items i join item_discovery d on d.itemid=i.itemid join item_discovery pd on pd.itemid=d.parent_itemid "
              "where i.itemid in (%s)" % (args.host, ",".join(str(i) for i in ids or [0])))
    check(len(found) == len(ids or []) and all(f[2] == "1" and f[3] == rule for f in found), f"ifIndex {row['if_index']}: every id is an item of the host discovered by this rule")
    check(all(f[1].endswith(".%s]" % row["if_index"]) for f in found), f"ifIndex {row['if_index']}: every key belongs to the row (ends with .{row['if_index']}])")

print("\n=== topology.ports (no item prototypes) ===")
other, _ = snapshot_rows("topology.ports")
if other is not None:
    check(all(r.get("itemids") == [] for r in other), "every row has itemids = []")

print("\nAll checks passed." if not failures else f"\n{failures} check(s) FAILED.")
sys.exit(1 if failures else 0)
