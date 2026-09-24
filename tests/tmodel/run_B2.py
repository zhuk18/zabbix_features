#!/usr/bin/env python3
"""B2. See expected/B2.md, written before this ran."""
import sys
from pathlib import Path
sys.path.insert(0, str(Path(__file__).parent))
import harness as h

log = h.ResultsLog(Path(__file__).parent / "results_B2.json")


def variant(name_suffix, subtype, port_id, port_desc):
    r = h.TestResult(id=f"B2-{name_suffix}", uc="B2", expected=Path("expected/B2.md").read_text())
    r1 = f"TM-B2-{name_suffix}-A"
    r2 = f"TM-B2-{name_suffix}-B"
    h1 = h.create_reporter_host(r1)
    h2 = h.create_reporter_host(r2)
    try:
        # r1 sees r2 with the GIVEN subtype/port_id/desc combination; r2 sees r1 with the
        # canonical long ifDescr form (remote_port_desc always present, per real LLDP).
        h.push_blob(r1, {"sysname": r1, "chassis_id": "aa:bb:cc:dd:ee:B0", "mgmt_ip": "10.9.9.80", "vendor": "t"},
                    [{"if_index": 1, "name": "GigabitEthernet0/1", "if_type": "physical", "mac": "aa:00:00:00:0b:01", "admin_status": "up", "oper_status": "up"}],
                    [{"local_if_index": 1, "remote_chassis_id": "aa:bb:cc:dd:ee:B1", "remote_sysname": r2,
                      "remote_port_id": port_id, "remote_port_id_subtype": subtype, "remote_port_desc": port_desc}])
        h.push_blob(r2, {"sysname": r2, "chassis_id": "aa:bb:cc:dd:ee:B1", "mgmt_ip": "10.9.9.81", "vendor": "t"},
                    [{"if_index": 1, "name": "GigabitEthernet0/2", "if_type": "physical", "mac": "aa:00:00:00:0b:02", "admin_status": "up", "oper_status": "up"}],
                    [{"local_if_index": 1, "remote_chassis_id": "aa:bb:cc:dd:ee:B0", "remote_sysname": r1,
                      "remote_port_id": "GigabitEthernet0/1", "remote_port_id_subtype": None, "remote_port_desc": "GigabitEthernet0/1"}])

        node1, node2 = f"h:{h1}", f"h:{h2}"

        def find_link():
            rels = h.php_call("getRelations", [])
            matches = [x for x in rels if {x["source"], x["target"]} == {node1, node2}]
            return matches if matches else None

        found = h.wait_for(find_link, timeout=40, interval=2)
        r.evidence = {"relations_touching_port": found}
        if found and len(found) == 1:
            r.actual = f"ONE link (not two), normalized: {found[0]}"
            r.verdict = "PASS"
            r.uc_verdict_held = True
        else:
            r.actual = f"found={found}"
            r.verdict = "BUG"
            r.uc_verdict_held = False
        log.add(r)
    except Exception as e:
        r.actual = f"Exception: {e}"
        r.verdict = "BLOCKED"
        log.add(r)
    finally:
        h.delete_host(h1)
        h.delete_host(h2)


# ifName-subtype: remote_port_id is the short form, no desc.
variant("ifname", "interfaceName", "Gi0/2", None)
# macAddress-subtype: remote_port_id is a MAC (no normalizable name at all -- resolvePortLabel
# falls through to returning the MAC verbatim since neither desc nor a normalizable name exists).
variant("mac", "macAddress", "aa:00:00:00:0b:02", None)
# local-subtype with a numeric-looking id but WITH a desc present (desc always wins per
# resolvePortLabel -- this variant checks that priority holds).
variant("local_with_desc", "local", "2", "GigabitEthernet0/2")

log.save()
print("B2 done.")
