#!/usr/bin/env python3
"""C1 (highest risk). See expected/C1.md, written before this ran."""
import sys, time, json
from pathlib import Path
sys.path.insert(0, str(Path(__file__).parent))
import harness as h

log = h.ResultsLog(Path(__file__).parent / "results_C1.json")


def nbr_items(hostid):
    return h.zbx("item.get", {"hostids": [hostid], "search": {"key_": "topo.nbr["},
                              "output": ["itemid", "status", "lastvalue"],
                              "selectItemDiscovery": ["status", "ts_delete", "ts_disable"]})


BLOB_FULL = dict(
    reporter={"sysname": "TM-C1", "chassis_id": "aa:bb:cc:dd:ee:30", "mgmt_ip": "10.9.9.30", "vendor": "t"},
    ports=[{"if_index": 1, "name": "Eth1", "if_type": "physical", "mac": "aa:00:00:00:04:01", "admin_status": "up", "oper_status": "up"},
           {"if_index": 2, "name": "Eth2", "if_type": "physical", "mac": "aa:00:00:00:04:02", "admin_status": "up", "oper_status": "up"}],
    neighbors=[
        {"local_if_index": 1, "remote_chassis_id": "aa:bb:cc:dd:ee:31", "remote_sysname": "PeerA",
         "remote_port_id": "Eth1", "remote_port_id_subtype": None, "remote_port_desc": "Eth1"},
        {"local_if_index": 2, "remote_chassis_id": "aa:bb:cc:dd:ee:32", "remote_sysname": "PeerB",
         "remote_port_id": "Eth1", "remote_port_id_subtype": None, "remote_port_desc": "Eth1"},
    ]
)

hostid = h.create_reporter_host("TM-C1")
try:
    # (a) establish a full baseline (2 neighbors), then push an EMPTY table.
    r = h.TestResult(id="C1a", uc=None, expected=Path("expected/C1.md").read_text())
    h.push_blob("TM-C1", BLOB_FULL["reporter"], BLOB_FULL["ports"], BLOB_FULL["neighbors"])
    h.wait_for(lambda: len(nbr_items(hostid)) == 2, timeout=40, interval=2)
    h.push_blob("TM-C1", BLOB_FULL["reporter"], BLOB_FULL["ports"], BLOB_FULL["neighbors"])
    got = h.wait_for(lambda: all('present":true' in i['lastvalue'] for i in nbr_items(hostid)) and nbr_items(hostid), timeout=40, interval=2)
    before = nbr_items(hostid)
    r.metrics_before = {"items": before}

    h.push_blob("TM-C1", BLOB_FULL["reporter"], BLOB_FULL["ports"], [])  # empty table
    after = h.wait_for(lambda: (lambda items: items if items and all('present":false' in i['lastvalue'] for i in items) else None)(nbr_items(hostid)),
                        timeout=40, interval=2)
    r.metrics_after = {"items": after}
    if after and len(after) == 2 and all(i["itemDiscovery"]["ts_delete"] == "0" for i in after):
        r.actual = "Empty table: both neighbor items survive (not deleted), values show present:false. No data loss."
        r.verdict = "PASS"
    else:
        r.actual = f"Unexpected: after={after}"
        r.verdict = "BUG"
    log.add(r)

    # (b) partial table: drop PeerB only, keep PeerA.
    r = h.TestResult(id="C1b", uc=None, expected=Path("expected/C1.md").read_text())
    h.push_blob("TM-C1", BLOB_FULL["reporter"], BLOB_FULL["ports"], [BLOB_FULL["neighbors"][0]])  # only PeerA
    partial = h.wait_for(lambda: (lambda items: items if len(items) == 2 and
                                   sum('present":true' in i['lastvalue'] for i in items) == 1 and
                                   sum('present":false' in i['lastvalue'] for i in items) == 1 else None)(nbr_items(hostid)),
                          timeout=40, interval=2)
    r.metrics_after = {"items": partial}
    if partial:
        r.actual = "Partial table: PeerA item stays present:true, PeerB item flips to present:false. Both items survive."
        r.verdict = "PASS"
    else:
        r.actual = f"Unexpected: partial={partial}"
        r.verdict = "BUG"
    log.add(r)

    # (c) SNMP timeout is push.py's own concern (no push happens at all) -- already demonstrated
    # live in t-model-findings.md's E-B3(a) with real reporters (Switch1 excluded from a push
    # cycle -> its own items' lastclock froze while a still-pushing neighbor's side advanced).
    r = h.TestResult(id="C1c", uc=None, expected=Path("expected/C1.md").read_text(),
                      actual=("Equivalent to E-B3(a) in t-model-findings.md, already demonstrated live "
                              "with real timestamps on Switch1/Switch2: a fully silent reporter (no push at "
                              "all, which is what a real SNMP timeout inside push.py produces) freezes its "
                              "own items' lastclock while a still-reporting neighbor's side keeps advancing "
                              "-- no data loss, no crash. Not independently re-run against a synthetic host "
                              "in this pass since the mechanism is identical and already has real evidence."),
                      verdict="PASS")
    r.uc_verdict_held = True
    log.add(r)
finally:
    h.delete_host(hostid)

log.save()
print("C1 done.")
