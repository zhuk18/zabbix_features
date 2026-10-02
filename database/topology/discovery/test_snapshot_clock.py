#!/usr/bin/env python3
"""test_snapshot_clock.py — the snapshot clock never goes back (topology-push-transport-spec.md §6.1), against a live server.

Lab test, not a unit test: needs a running zabbix_server, the "Topology by push" template linked to HOST, and
zabbix_sender. It writes only the snapshot of HOST's topology.push.ports rule. Usage:

  MYSQL_PWD=... test_snapshot_clock.py HOST [--server 127.0.0.1] [--port 10151] [--db zabbix] [--db-user zabbix]
                                            [--sender ../../../src/zabbix_sender/zabbix_sender]

Sends one PORTS value with a timestamp (-T), then: an older timestamp with other rows -> snapshot unchanged; the same
timestamp -> unchanged; a newer timestamp with other rows -> snapshot replaced and dated by it.
"""
import argparse
import json
import subprocess
import sys
import tempfile
import time

KEY = "topology.push.ports"
ap = argparse.ArgumentParser()
ap.add_argument("host")
ap.add_argument("--server", default="127.0.0.1")
ap.add_argument("--port", default="10151")
ap.add_argument("--db", default="zabbix")
ap.add_argument("--db-user", default="zabbix")
ap.add_argument("--sender", default="../../../src/zabbix_sender/zabbix_sender")
args = ap.parse_args()
failures = 0


def check(ok, text):
    global failures
    print(("  ok: " if ok else "FAIL: ") + text)
    failures += 0 if ok else 1


def rows(name):
    return json.dumps([{"{#IFINDEX}": "1", "{#IFNAME}": name, "{#IFTYPE}": "6", "{#IFADMINSTATUS}": "1",
                        "{#IFOPERSTATUS}": "1", "{#LOC_CHASSIS}": "aa:bb:cc:00:00:01"}], separators=(",", ":"))


def send(ts, value):
    with tempfile.NamedTemporaryFile("w", suffix=".txt") as f:
        f.write(f"{args.host}\t{KEY}\t{ts}\t{value}\n")
        f.flush()
        out = subprocess.run([args.sender, "-z", args.server, "-p", args.port, "-T", "-i", f.name],
                             capture_output=True, text=True).stdout
    time.sleep(4)
    return "failed: 0" in out


def snapshot():
    q = ("select clock,rows_hash from topo_lld_snapshot s join items i using(itemid) "
         f"where i.key_='{KEY}' and i.hostid=(select hostid from hosts where host='{args.host}')")
    return subprocess.run(["mysql", f"-u{args.db_user}", args.db, "-N", "-e", q], capture_output=True, text=True).stdout.split()


now = int(time.time())
t_old, t_first, t_new = now - 5000, now - 1000, now - 500
check(send(t_first, rows("first")), "the first value is accepted")
first = snapshot()
check(first[:1] == [str(t_first)], "the snapshot is dated by the value's timestamp")
check(send(t_old, rows("older")) and snapshot() == first, "an older timestamp with other rows changes nothing")
check(send(t_first, rows("first")) and snapshot() == first, "the same timestamp is accepted")
check(send(t_new, rows("newer")) and snapshot()[:1] == [str(t_new)] and snapshot()[1:] != first[1:],
      "a newer timestamp replaces the rows and the clock")
print("\n%s" % ("All snapshot clock tests passed." if not failures else f"{failures} FAILED"))
sys.exit(1 if failures else 0)
