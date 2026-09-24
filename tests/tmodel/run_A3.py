#!/usr/bin/env python3
"""A3. See expected/A3.md, written before this ran."""
import sys, time, json
from pathlib import Path
sys.path.insert(0, str(Path(__file__).parent))
import harness as h

log = h.ResultsLog(Path(__file__).parent / "results_A3.json")


def variant(name_suffix, with_mgmt_ip):
    r = h.TestResult(id=f"A3-{name_suffix}", uc="A3", expected=Path("expected/A3.md").read_text())
    reporter_host = f"TM-A3-{name_suffix}"
    peer_host = f"TM-A3-{name_suffix}-Peer"
    old_chassis = f"aa:bb:cc:dd:ee:{name_suffix}0"
    new_chassis = f"aa:bb:cc:dd:ee:{name_suffix}9"
    mgmt_ip = "10.9.9.70" if with_mgmt_ip else None

    hr = h.create_reporter_host(reporter_host)
    try:
        blob_old = dict(
            reporter={"sysname": reporter_host, "chassis_id": f"aa:bb:cc:dd:ee:{name_suffix}1",
                      "mgmt_ip": "10.9.9.71", "vendor": "t"},
            ports=[{"if_index": 1, "name": "Eth1", "if_type": "physical", "mac": "aa:00:00:00:09:01", "admin_status": "up", "oper_status": "up"}],
            neighbors=[{"local_if_index": 1, "remote_chassis_id": old_chassis, "remote_sysname": "MovingPeer",
                        "remote_port_id": "Eth9", "remote_port_id_subtype": None, "remote_port_desc": "Eth9"}]
        )
        h.push_blob(reporter_host, blob_old["reporter"], blob_old["ports"], blob_old["neighbors"])
        peer_node = h.wait_for(lambda: next((d for d in h.php_call("getDevices", []) if d["name"] == "MovingPeer"), None),
                                timeout=40, interval=2)
        assert peer_node, "peer node never appeared"
        old_cluster_key = f"c:{old_chassis}"

        # Manually promote the unbound peer onto a placeholder host, and add a manual link to it
        # from the reporter -- this is the "manual decision" that A3 checks survival of.
        placeholder_hostid = h.create_reporter_host(f"TM-A3-{name_suffix}-Placeholder")
        h.php_call("promote", [old_cluster_key, placeholder_hostid])
        h.php_call("linkPorts", [f"h:{hr}/Eth1", f"h:{placeholder_hostid}/Eth9"])

        before = h.php_call("getDevices", [])
        before_bound = next(d for d in before if d["hostid"] == placeholder_hostid)

        # Now the neighbor's chassis_id changes (reporter still sees it, but with a NEW chassis).
        blob_new = dict(
            reporter=blob_old["reporter"],
            ports=blob_old["ports"],
            neighbors=[{"local_if_index": 1, "remote_chassis_id": new_chassis, "remote_sysname": "MovingPeer",
                        "remote_port_id": "Eth9", "remote_port_id_subtype": None, "remote_port_desc": "Eth9"}]
        )
        h.push_blob(reporter_host, blob_new["reporter"], blob_new["ports"], blob_new["neighbors"])
        h.wait_for(lambda: any(d["name"] == "MovingPeer" and d["chassis_id"] == f"c:{new_chassis}"
                                for d in h.php_call("getDevices", [])), timeout=40, interval=2)

        diag = h.php_call("diagnostics", [])
        after = h.php_call("getDevices", [])
        placeholder_still_bound = next((d for d in after if d["hostid"] == placeholder_hostid), None)
        new_peer_node = next((d for d in after if d["name"] == "MovingPeer" and d["chassis_id"] == f"c:{new_chassis}"), None)

        r.metrics_before = {"before_bound": before_bound}
        r.metrics_after = {"after_placeholder": placeholder_still_bound, "new_peer_node": new_peer_node, "diagnostics_dangling": diag.get("dangling")}

        manual_id_orphaned = placeholder_still_bound is not None and placeholder_still_bound.get("identity") is not None \
            and new_peer_node is not None and new_peer_node.get("id") != placeholder_still_bound.get("id")
        detected_by_diagnostics = bool(diag.get("dangling"))

        r.actual = (f"manual topo.id orphaned (placeholder host keeps its OLD identity, new "
                    f"chassis appears as a SEPARATE unbound node)={manual_id_orphaned}; "
                    f"diagnostics.dangling flags it={detected_by_diagnostics}; "
                    f"mgmt_ip present={with_mgmt_ip}")
        if manual_id_orphaned:
            r.verdict = "PASS"  # matches the predicted 🔴 -- manual decision does silently detach
            r.uc_verdict_held = True
            if not detected_by_diagnostics:
                r.notes = "Detachment confirmed, but diagnostics() does NOT surface it as 'dangling' -- the dangling check only looks at topo.id/topo.unbind values with NO current cluster at all; a topo.id that still resolves to SOME (now wrong) cluster isn't flagged. Gap in diagnostics, worth a follow-up BUG entry."
        else:
            r.verdict = "BUG"
            r.uc_verdict_held = False

        h.delete_host(placeholder_hostid)
    except Exception as e:
        r.actual = f"Exception: {e}"
        r.verdict = "BLOCKED"
    finally:
        h.delete_host(hr)
    log.add(r)


variant("mgmtyes", with_mgmt_ip=True)
variant("mgmtno", with_mgmt_ip=False)

log.save()
print("A3 done.")
