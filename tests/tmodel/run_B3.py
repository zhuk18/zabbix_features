#!/usr/bin/env python3
"""B3. See expected/B3.md, written before this ran."""
import sys, time
from pathlib import Path
sys.path.insert(0, str(Path(__file__).parent))
import harness as h

log = h.ResultsLog(Path(__file__).parent / "results_B3.json")

r1, r2 = "TM-B3-A", "TM-B3-B"
h1 = h.create_reporter_host(r1)
h2 = h.create_reporter_host(r2)
try:
    def push_both():
        h.push_blob(r1, {"sysname": r1, "chassis_id": "aa:bb:cc:dd:ee:C0", "mgmt_ip": "10.9.9.90", "vendor": "t"},
                    [{"if_index": 1, "name": "Eth1", "if_type": "physical", "mac": "aa:00:00:00:0c:01", "admin_status": "up", "oper_status": "up"}],
                    [{"local_if_index": 1, "remote_chassis_id": "aa:bb:cc:dd:ee:C1", "remote_sysname": r2,
                      "remote_port_id": "Eth1", "remote_port_id_subtype": None, "remote_port_desc": "Eth1"}])
        h.push_blob(r2, {"sysname": r2, "chassis_id": "aa:bb:cc:dd:ee:C1", "mgmt_ip": "10.9.9.91", "vendor": "t"},
                    [{"if_index": 1, "name": "Eth1", "if_type": "physical", "mac": "aa:00:00:00:0c:02", "admin_status": "up", "oper_status": "up"}],
                    [{"local_if_index": 1, "remote_chassis_id": "aa:bb:cc:dd:ee:C0", "remote_sysname": r1,
                      "remote_port_id": "Eth1", "remote_port_id_subtype": None, "remote_port_desc": "Eth1"}])

    node1, node2 = f"h:{h1}", f"h:{h2}"

    def link():
        rels = h.php_call("getRelations", [])
        return next((x for x in rels if {x["source"], x["target"]} == {node1, node2}), None)

    push_both()
    push_both()  # second round so both sides' items get real present:true values
    l1 = h.wait_for(lambda: (lambda x: x if x and x["last_seen_src"] and x["last_seen_dst"] else None)(link()),
                     timeout=60, interval=3)
    assert l1, "link never became fully established with both sides confirming"

    # (a) r1 goes fully silent; r2 keeps pushing.
    r = h.TestResult(id="B3a", uc="B3", expected=Path("expected/B3.md").read_text())
    r.metrics_before = {"link": l1}
    time.sleep(2)
    h.push_blob(r2, {"sysname": r2, "chassis_id": "aa:bb:cc:dd:ee:C1", "mgmt_ip": "10.9.9.91", "vendor": "t"},
                [{"if_index": 1, "name": "Eth1", "if_type": "physical", "mac": "aa:00:00:00:0c:02", "admin_status": "up", "oper_status": "up"}],
                [{"local_if_index": 1, "remote_chassis_id": "aa:bb:cc:dd:ee:C0", "remote_sysname": r1,
                  "remote_port_id": "Eth1", "remote_port_id_subtype": None, "remote_port_desc": "Eth1"}])
    l2 = h.wait_for(lambda: (lambda x: x if x and x["last_seen_dst"] and x["last_seen_dst"] != l1["last_seen_dst"] else None)(link()),
                     timeout=40, interval=2)
    r.metrics_after = {"link": l2}
    if l2 and l2["last_seen_src"] == l1["last_seen_src"] and l2["last_seen_dst"] > l1["last_seen_dst"]:
        r.actual = (f"last_seen_src frozen at {l2['last_seen_src']} (r1 silent), last_seen_dst advanced "
                    f"{l1['last_seen_dst']} -> {l2['last_seen_dst']} (r2 still pushing). Matches E-B3(a) exactly.")
        r.verdict = "PASS"
        r.uc_verdict_held = True
    else:
        r.actual = f"l1={l1}, l2={l2}"
        r.verdict = "BUG"
    log.add(r)

    # (b) r1 comes back but its blob no longer lists r2 as a neighbor (r1 still pushes; the
    # neighbor itself is gone from ITS view).
    r = h.TestResult(id="B3b", uc="B3", expected=Path("expected/B3.md").read_text())
    h.push_blob(r1, {"sysname": r1, "chassis_id": "aa:bb:cc:dd:ee:C0", "mgmt_ip": "10.9.9.90", "vendor": "t"},
                [{"if_index": 1, "name": "Eth1", "if_type": "physical", "mac": "aa:00:00:00:0c:01", "admin_status": "up", "oper_status": "up"}],
                [])  # r2 no longer seen
    lost = h.wait_for(lambda: (lambda x: x if x and x["lost_src"] else None)(link()), timeout=40, interval=2)
    r.metrics_after = {"link": lost}
    if lost and lost["lost_src"] is True:
        r.actual = f"lost_src=True after r1 stops seeing the neighbor (still pushing otherwise): {lost}"
        r.verdict = "PASS"
        r.uc_verdict_held = True
    else:
        r.actual = f"lost={lost}"
        r.verdict = "BUG"
    log.add(r)
finally:
    h.delete_host(h1)
    h.delete_host(h2)

log.save()
print("B3 done.")
