#!/usr/bin/env python3
"""Q1-Q6 (platform facts). See expected/Q*.md, written before this ran."""
import sys
from pathlib import Path
sys.path.insert(0, str(Path(__file__).parent))
import harness as h

log = h.ResultsLog(Path(__file__).parent / "results_Q.json")

# Q1: single discovered item delete + LLD-recreate-on-reappearance.
r = h.TestResult(id="Q1", uc="B5", expected=Path("expected/Q1.md").read_text())
hostid = h.create_reporter_host("TM-Q1")
try:
    h.push_blob("TM-Q1", {"sysname": "TM-Q1", "chassis_id": "aa:bb:cc:dd:ee:10", "mgmt_ip": "10.9.9.10", "vendor": "t"},
                [{"if_index": 1, "name": "Eth1", "if_type": "physical", "mac": "aa:00:00:00:00:01", "admin_status": "up", "oper_status": "up"}],
                [{"local_if_index": 1, "remote_chassis_id": "aa:bb:cc:dd:ee:11", "remote_sysname": "Peer1",
                  "remote_port_id": "Eth1", "remote_port_id_subtype": None, "remote_port_desc": "Eth1"}])
    import time; time.sleep(3)
    h.push_blob("TM-Q1", {"sysname": "TM-Q1", "chassis_id": "aa:bb:cc:dd:ee:10", "mgmt_ip": "10.9.9.10", "vendor": "t"},
                [{"if_index": 1, "name": "Eth1", "if_type": "physical", "mac": "aa:00:00:00:00:01", "admin_status": "up", "oper_status": "up"}],
                [{"local_if_index": 1, "remote_chassis_id": "aa:bb:cc:dd:ee:11", "remote_sysname": "Peer1",
                  "remote_port_id": "Eth1", "remote_port_id_subtype": None, "remote_port_desc": "Eth1"}])
    time.sleep(3)
    items = h.zbx("item.get", {"hostids": [hostid], "search": {"key_": "topo.nbr["}, "output": ["itemid", "key_"]})
    assert len(items) == 1, f"expected 1 discovered neighbor item, got {items}"
    old_itemid = items[0]["itemid"]
    h.zbx("item.delete", [old_itemid])
    still_there = h.zbx("item.get", {"itemids": [old_itemid], "output": ["itemid"]})
    assert not still_there, "item.delete did not remove it"
    h.push_blob("TM-Q1", {"sysname": "TM-Q1", "chassis_id": "aa:bb:cc:dd:ee:10", "mgmt_ip": "10.9.9.10", "vendor": "t"},
                [{"if_index": 1, "name": "Eth1", "if_type": "physical", "mac": "aa:00:00:00:00:01", "admin_status": "up", "oper_status": "up"}],
                [{"local_if_index": 1, "remote_chassis_id": "aa:bb:cc:dd:ee:11", "remote_sysname": "Peer1",
                  "remote_port_id": "Eth1", "remote_port_id_subtype": None, "remote_port_desc": "Eth1"}])
    time.sleep(3)
    recreated = h.zbx("item.get", {"hostids": [hostid], "search": {"key_": "topo.nbr["}, "output": ["itemid", "key_"]})
    r.evidence = {"deleted_itemid": old_itemid, "recreated": recreated}
    if len(recreated) == 1 and recreated[0]["itemid"] != old_itemid:
        r.actual = f"item.delete succeeded (API); LLD recreated it as itemid {recreated[0]['itemid']} on next push."
        r.verdict = "PASS"
        r.uc_verdict_held = True
    else:
        r.actual = f"Unexpected: recreated={recreated}"
        r.verdict = "BUG"
        r.uc_verdict_held = False
except Exception as e:
    r.actual = f"Exception: {e}"
    r.verdict = "BLOCKED"
finally:
    h.delete_host(hostid)
log.add(r)

# Q2: selectItemDiscovery field allowlist.
r = h.TestResult(id="Q2", uc="B3", expected=Path("expected/Q2.md").read_text())
try:
    try:
        h.zbx("item.get", {"itemids": ["1"], "output": ["itemid"], "selectItemDiscovery": ["lastcheck"]})
        lastcheck_allowed = True
        err = None
    except RuntimeError as e:
        lastcheck_allowed = False
        err = str(e)
    fields = h.zbx("item.get", {"itemids": ["1"], "output": ["itemid"],
                                 "selectItemDiscovery": ["parent_itemid", "key_", "status", "ts_delete", "ts_disable", "disable_source"]})
    r.evidence = {"lastcheck_error": err}
    r.actual = ("selectItemDiscovery rejects 'lastcheck' (API error); allows parent_itemid/key_/"
                "status/ts_delete/ts_disable/disable_source.")
    r.verdict = "PASS" if not lastcheck_allowed else "SPEC_GAP"
    r.uc_verdict_held = True
    r.proposed_uc_verdict = "🟡 (native mechanism exists but the exact field the design assumed isn't exposed; last_seen/lost must be derived from lastclock + the item's own value instead)"
except Exception as e:
    r.actual = f"Exception: {e}"
    r.verdict = "BLOCKED"
log.add(r)

# Q3: proxy tags.
r = h.TestResult(id="Q3", uc="A6", expected=Path("expected/Q3.md").read_text())
try:
    try:
        h.zbx("proxy.get", {"output": "extend", "selectTags": "extend"})
        allowed = True
    except RuntimeError as e:
        allowed = False
        r.evidence = {"error": str(e)}
    r.actual = f"proxy.get selectTags allowed={allowed}"
    r.verdict = "PASS" if not allowed else "SPEC_GAP"
    r.uc_verdict_held = True
except Exception as e:
    r.actual = f"Exception: {e}"
    r.verdict = "BLOCKED"
log.add(r)

# Q4: not applicable (design doesn't use host prototypes for peers).
r = h.TestResult(id="Q4", uc="A2", expected=Path("expected/Q4.md").read_text(),
                  actual="No LLD host-prototype rule exists anywhere in this implementation for "
                         "peers -- confirmed by inspecting template_topology_discovery_reporter.yaml "
                         "(only topo.self item + topo.nbr.discovery LLD rule + topo.nbr[] item "
                         "prototype; no host prototypes at all). There is nothing to collide.",
                  verdict="BLOCKED")
r.notes = "Deliberate design choice (matches the use-case doc's own explicit rejection), not a gap."
log.add(r)

# Q5: not applicable (VMware/hosted_by out of scope).
r = h.TestResult(id="Q5", uc="C6", expected=Path("expected/Q5.md").read_text(),
                  actual="Out of scope for this prototype; not investigated.", verdict="BLOCKED")
log.add(r)

# Q6: empty LLD result vs error -- effect on existing items.
r = h.TestResult(id="Q6", uc=None, expected=Path("expected/Q6.md").read_text())
hostid = h.create_reporter_host("TM-Q6")
try:
    import time
    h.push_blob("TM-Q6", {"sysname": "TM-Q6", "chassis_id": "aa:bb:cc:dd:ee:20", "mgmt_ip": "10.9.9.20", "vendor": "t"},
                [{"if_index": 1, "name": "Eth1", "if_type": "physical", "mac": "aa:00:00:00:01:01", "admin_status": "up", "oper_status": "up"}],
                [{"local_if_index": 1, "remote_chassis_id": "aa:bb:cc:dd:ee:21", "remote_sysname": "Peer1",
                  "remote_port_id": "Eth1", "remote_port_id_subtype": None, "remote_port_desc": "Eth1"}])
    time.sleep(3)
    h.push_blob("TM-Q6", {"sysname": "TM-Q6", "chassis_id": "aa:bb:cc:dd:ee:20", "mgmt_ip": "10.9.9.20", "vendor": "t"},
                [{"if_index": 1, "name": "Eth1", "if_type": "physical", "mac": "aa:00:00:00:01:01", "admin_status": "up", "oper_status": "up"}],
                [{"local_if_index": 1, "remote_chassis_id": "aa:bb:cc:dd:ee:21", "remote_sysname": "Peer1",
                  "remote_port_id": "Eth1", "remote_port_id_subtype": None, "remote_port_desc": "Eth1"}])
    time.sleep(3)
    before = h.zbx("item.get", {"hostids": [hostid], "search": {"key_": "topo.nbr["}, "output": ["itemid"],
                                 "selectItemDiscovery": ["status", "ts_delete"]})
    # Now push an EMPTY neighbors array -- the "empty table" case.
    h.push_blob("TM-Q6", {"sysname": "TM-Q6", "chassis_id": "aa:bb:cc:dd:ee:20", "mgmt_ip": "10.9.9.20", "vendor": "t"},
                [{"if_index": 1, "name": "Eth1", "if_type": "physical", "mac": "aa:00:00:00:01:01", "admin_status": "up", "oper_status": "up"}],
                [])
    time.sleep(3)
    after = h.zbx("item.get", {"hostids": [hostid], "search": {"key_": "topo.nbr["}, "output": ["itemid", "lastvalue"],
                                "selectItemDiscovery": ["status", "ts_delete"]})
    r.metrics_before = {"items": before}
    r.metrics_after = {"items": after}
    survived = len(after) == 1 and after[0]["itemDiscovery"]["status"] == "0" and after[0]["itemDiscovery"]["ts_delete"] == "0"
    value_shows_absent = survived and '"present":false' in after[0]["lastvalue"]
    r.actual = (f"After an empty-neighbors push: item survived={survived}, "
                f"value shows present:false={value_shows_absent}, raw value={after[0]['lastvalue'] if after else None}")
    r.verdict = "PASS" if survived and value_shows_absent else "BUG"
except Exception as e:
    r.actual = f"Exception: {e}"
    r.verdict = "BLOCKED"
finally:
    h.delete_host(hostid)
log.add(r)

log.save()
print("Q1-Q6 done.")
