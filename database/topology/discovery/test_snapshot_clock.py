#!/usr/bin/env python3
"""test_snapshot_clock.py — the snapshot clock never goes back (topology-3-push-delivery.md "Server checks"), against a live server.

Lab test, not a unit test: needs a running zabbix_server, the "Topology by push" template linked to HOST, and
zabbix_sender. It writes only the snapshot of HOST's topology.push.ports rule. Usage:

  MYSQL_PWD=... test_snapshot_clock.py HOST [--server 127.0.0.1] [--port 10151] [--db zabbix] [--db-user zabbix]
                                            [--sender ../../../src/zabbix_sender/zabbix_sender]

Sends one PORTS value with a timestamp (-T), then: an older timestamp with other rows -> snapshot unchanged; the same
timestamp -> unchanged; a newer timestamp with other rows -> snapshot replaced and dated by it. Then the future-timestamp
rule (topology-1-model.md "Snapshot rules" rule 9): a value more than 300 s ahead of the server is refused and the rule's info
text says so; one 200 s ahead is accepted; a stored clock written by hand far in the future does not block a normal value.
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
def rule_error():
    time.sleep(6)          # the item error is written with the history sync
    q = (f"select error from items where key_='{KEY}' and hostid=(select hostid from hosts where host='{args.host}')")
    return subprocess.run(["mysql", f"-u{args.db_user}", args.db, "-N", "-e", q], capture_output=True, text=True).stdout.strip()


kept = snapshot()
check(send(int(time.time()) + 86400, rows("future")) and snapshot() == kept, "a value one day ahead is refused: the snapshot does not change")
check("in the future, ignored" in rule_error(), "the rule's info text says so (%s)" % rule_error())
check(send(int(time.time()) + 200, rows("tolerated")) and snapshot()[:1] != kept[:1], "a value 200 s ahead (inside the tolerance) is accepted")
subprocess.run(["mysql", f"-u{args.db_user}", args.db, "-e",
                "update topo_lld_snapshot set clock=%d where itemid=(select itemid from items where key_='%s' and hostid="
                "(select hostid from hosts where host='%s'))" % (int(time.time()) + 86400, KEY, args.host)])
check(send(int(time.time()), rows("recovered")) and abs(int(snapshot()[0]) - time.time()) < 30,
      "a stored clock a day ahead (hand-written) does not block a value with a valid timestamp")
print("\n%s" % ("All snapshot clock tests passed." if not failures else f"{failures} FAILED"))
sys.exit(1 if failures else 0)
