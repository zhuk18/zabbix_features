#!/usr/bin/env python3
"""A2. See expected/A2.md, written before this ran."""
import sys, time, json
from pathlib import Path
sys.path.insert(0, str(Path(__file__).parent))
import harness as h

log = h.ResultsLog(Path(__file__).parent / "results_A2.json")

# (a) two reporters, both report the SAME neighbor by chassis_id.
h1 = h.create_reporter_host("TM-A2-R1")
h2 = h.create_reporter_host("TM-A2-R2")
try:
    r = h.TestResult(id="A2a", uc="A2", expected=Path("expected/A2.md").read_text())
    shared_chassis = "aa:bb:cc:dd:ee:40"
    h.push_blob("TM-A2-R1", {"sysname": "TM-A2-R1", "chassis_id": "aa:bb:cc:dd:ee:41", "mgmt_ip": "10.9.9.41", "vendor": "t"},
                [{"if_index": 1, "name": "Eth1", "if_type": "physical", "mac": "aa:00:00:00:05:01", "admin_status": "up", "oper_status": "up"}],
                [{"local_if_index": 1, "remote_chassis_id": shared_chassis, "remote_sysname": "SharedPeer",
                  "remote_port_id": "Eth9", "remote_port_id_subtype": None, "remote_port_desc": "Eth9"}])
    h.push_blob("TM-A2-R2", {"sysname": "TM-A2-R2", "chassis_id": "aa:bb:cc:dd:ee:42", "mgmt_ip": "10.9.9.42", "vendor": "t"},
                [{"if_index": 1, "name": "Eth1", "if_type": "physical", "mac": "aa:00:00:00:05:02", "admin_status": "up", "oper_status": "up"}],
                [{"local_if_index": 1, "remote_chassis_id": shared_chassis, "remote_sysname": "SharedPeer",
                  "remote_port_id": "Eth9", "remote_port_id_subtype": None, "remote_port_desc": "Eth9"}])

    def shared_nodes():
        devices = h.php_call("getDevices", [])
        return [d for d in devices if d["chassis_id"] == f"c:{shared_chassis}"]

    found = h.wait_for(shared_nodes, timeout=40, interval=2)
    reads = [h.php_call("getDevices", []) for _ in range(3)]
    same_across_reads = all(
        sorted(d["id"] for d in reads[0]) == sorted(d["id"] for d in reads[i]) for i in (1, 2)
    )
    r.evidence = {"shared_nodes": found}
    r.metrics_after = {"reads_consistent": same_across_reads}
    if found and len(found) == 1 and same_across_reads:
        r.actual = f"Exactly one node for the shared chassis_id ({found[0]['id']}), consistent across 3 reads."
        r.verdict = "PASS"
        r.uc_verdict_held = True
    else:
        r.actual = f"found={found}, consistent={same_across_reads}"
        r.verdict = "BUG"
    log.add(r)
finally:
    h.delete_host(h1)
    h.delete_host(h2)

# (b) one reporter sees chassis_id, the other sees sysname-only, for what is (in ground truth)
# the SAME physical neighbor.
h1 = h.create_reporter_host("TM-A2b-R1")
h2 = h.create_reporter_host("TM-A2b-R2")
try:
    r = h.TestResult(id="A2b", uc="A2", expected=Path("expected/A2.md").read_text())
    h.push_blob("TM-A2b-R1", {"sysname": "TM-A2b-R1", "chassis_id": "aa:bb:cc:dd:ee:43", "mgmt_ip": "10.9.9.43", "vendor": "t"},
                [{"if_index": 1, "name": "Eth1", "if_type": "physical", "mac": "aa:00:00:00:06:01", "admin_status": "up", "oper_status": "up"}],
                [{"local_if_index": 1, "remote_chassis_id": "aa:bb:cc:dd:ee:99", "remote_sysname": "AmbiguousPeer",
                  "remote_port_id": "Eth9", "remote_port_id_subtype": None, "remote_port_desc": "Eth9"}])
    h.push_blob("TM-A2b-R2", {"sysname": "TM-A2b-R2", "chassis_id": "aa:bb:cc:dd:ee:44", "mgmt_ip": "10.9.9.44", "vendor": "t"},
                [{"if_index": 1, "name": "Eth1", "if_type": "physical", "mac": "aa:00:00:00:06:02", "admin_status": "up", "oper_status": "up"}],
                # same physical box, but THIS reporter never learned its chassis id -- sysname only.
                [{"local_if_index": 1, "remote_chassis_id": None, "remote_sysname": "AmbiguousPeer",
                  "remote_port_id": "Eth9", "remote_port_id_subtype": None, "remote_port_desc": "Eth9"}])

    def ambiguous_nodes():
        devices = h.php_call("getDevices", [])
        return [d for d in devices if d["name"] == "AmbiguousPeer"]

    found = h.wait_for(ambiguous_nodes, timeout=40, interval=2)
    r.evidence = {"ambiguous_nodes": found}
    if found and len(found) == 2:
        r.actual = (f"TWO separate nodes for the same physical neighbor: {[d['id'] for d in found]} "
                    "-- c: id and s: id never cross-matched, confirming the use-case doc's predicted 🟡.")
        r.verdict = "PASS"
        r.uc_verdict_held = True
        r.proposed_uc_verdict = None
    elif found and len(found) == 1:
        r.actual = "Unexpectedly merged into one node -- contradicts the design's own §5.2 rule 3."
        r.verdict = "BUG"
    else:
        r.actual = f"found={found}"
        r.verdict = "BLOCKED"
    log.add(r)
finally:
    h.delete_host(h1)
    h.delete_host(h2)

log.save()
print("A2 done.")
