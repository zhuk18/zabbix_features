# Topology by SNMP (temporary JS)

Temporary lab template for topology-lld-part2-spec.md §9. Zabbix collects the topology itself: two SNMP `walk[]`
master items feed four dependent discovery rules, one per `topology_role` (PORTS, NEIGHBORS, LEARNED_MACS, LAG). Each
rule's JavaScript turns the walk text into the rows of that role's macro contract (see `CTopologyRole.php`).

This JavaScript is the reference implementation for Part 3's preset preprocessing step (`../step_golden/` holds the
golden comparison). The native template `../template_topology_by_snmp.yaml` (`../build_template.php`) replaces this
one; the JS stays until the end-to-end equivalence check of Part 3 §8 has passed.

| File | What |
|---|---|
| `common.js` | walk parsing shared by all four rules (pasted in front of each rule's script) |
| `ports.js`, `neighbors.js`, `learned_macs.js`, `lag.js` | one script per rule |
| `build_template.php` | builds `../template_topology_by_snmp_js.yaml` from the files above — edit the JS, not the YAML |
| `walk_oids.txt`, `record_fixture.sh` | the OID list of the master items and a recorder for lab fixtures |
| `fixtures/lab-*.walk` | real walks of the snmpsim lab (`snmpdata/`) |
| `test_lld_js.php` | runs the four scripts in the real Zabbix JavaScript engine (`zabbix_js`) on the fixtures and on a synthetic walk |

Rules of the JS: ES5 only (Duktape); a rule returns `[]` when its source table is simply empty and **throws** when the
walk itself is unusable — an empty result is a valid snapshot, a thrown error keeps the last good one. A neighbor
whose local port cannot be resolved is emitted without `{#IFINDEX}` so the server reports it.

    php build_template.php          # regenerate the YAML
    php test_lld_js.php             # test the JS
    ./record_fixture.sh 127.0.0.4:1611 fixtures/lab-switch2.walk   # re-record a fixture from a running lab
