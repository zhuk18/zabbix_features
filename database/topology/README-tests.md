# Tests of the topology code (task 046)

One command runs every test that can run on the lab VM:

```
database/topology/run_php_tests.sh zabbix            # MySQL user; MariaDB of the lab, no password
ZBX_UI_USER=... ZBX_UI_PASSWORD=... database/topology/run_php_tests.sh zabbix     # with the checks of the real page
```

It prints `pass` / `FAIL` / `SKIP` per file and a table with the number of checks and the seconds per file. The exit status is 0 when nothing failed. A `SKIP` is a test that needs a state
the lab does not have at that moment; the reason is in the line. Environment: `LABPY` (python with PyYAML and mysql-connector, default `~/work/labenv/bin/python`), `PHPUNIT` (phpunit 8.5 phar,
default `~/work/tools/phpunit-8.5.phar`: 8.5 because `ui/tests/include/CTest.php` calls `TestCase::getAnnotations()`, which PHPUnit 9 removed).

## Every test file of the topology code

| Test | In the common run | Notes |
|---|---|---|
| `discovery/test_device_links.php`, `test_device_link_validation.php`, `test_identity_normalization.php`, `test_link_replacement.php`, `test_manual_contradiction.php`, `test_topology_model.php`, `test_shared_identifier.php`, `test_pseudo_port_index.php`, `test_far_port_identification.php`, `test_far_device_lookup.php` (056, replaces test_join_identity.php), `test_cdp_device_key.php` (074), `test_run_summary.php` (076), `test_old_snapshot.php`, `test_remote_attrs_cap.php` (084), `test_port_limit.php` (085), `test_port_follows_name.php` (086), `test_two_ports_rules.php` (089: two PORTS rules of one host), `test_run_input.php` (088: the hashes of the snapshots a run read, the heartbeat gap), `test_chassis_subtypes.php` (078, 079), `test_claim_key.php` (079), `test_duplicate_candidates.php`, `test_ingest_identity.php`, `test_identity_keys.php`, `test_normalization.php`, `test_scenario_catalog.php`, `test_stable_ingest.php`, `test_linked_port_claims.php` (047), `test_addresses.php` (049), `test_device_last_seen.php` (050), `test_snapshot_ingest.php`, `test_ingest_lock.php` (087: the run row, `--if-requested`, the heartbeat), `test_manual_link_busy_port.php` | yes | PHP, each in a fresh `topo_t_*` database |
| `test_contradiction_rule.php`, `test_lazy_host_nodes.php` | yes | PHP, fresh `topo_t_*` database |
| `test_freshness.php`, `test_node_label.php`, `test_hop_scope.php`, `test_leaf_groups.php` (090), `test_device_class.php` (091), `test_topology_graph.js` (090, node) | yes | PHP, no database |
| `discovery/test_push.py` | yes (added 046) | Python; no lab needed |
| `discovery/lld_js/test_lld_js.php` | yes (added 046) | needs `src/zabbix_js/zabbix_js` (built); skipped when it is not |
| `discovery/step_golden/compare.php`, `edge_cases.php` | yes (added 046) | the native step of the walk; `build.sh` links the driver against the built tree (skipped when the build fails) |
| `corpus/test_corpus_walk.py` | yes (added 046) | unittest; run with the lab python (with the system python one test is skipped: no PyYAML) |
| `lab/tests/test_*.py` (13 files) | yes (added 046) | `unittest discover`, lab python; `test_reset.py` needs MySQL (it makes and drops `topo_t_lab_reset`) |
| `discovery/test_port_itemids.py` | yes (added 046) | Lab test, read only; default host = the first host of the lab that has the rule `net.if.discovery` with a snapshot (skipped when there is none: provision and run a scenario first) |
| `lab/php/view_scope_check.php` | yes (added 046) | Read only, the data path of the view page on the office stand (skipped when the office hosts are not provisioned) |
| `ui/tests/api_json/testDiscoveryRuleTopologyType.php`, `testDiscoveryRuleTopologyStep.php`, `testTopologyBySnmpTemplate.php` | yes (added 046) | phpunit 8.5 against the lab Zabbix; they create and remove their own data (skipped without the phar or `ui/tests/bootstrap.php`) |
| `ui_check_040/page_shot.py --check layout` (1280 × 800 collapsed, 1460 × 900 open), `--check escape`, `--check addresses` (051: the labels of the section Addresses, at both sizes; needs the office host SW15 and its neighbor NA12502000654), `--check duplicates` (057: the reason, the actions and the filter "possible duplicate"; needs the FortiAP Devices of SW19 with a `same_address` candidate, scenario R1), `--check lastrun` (076: the last run view at 1500 × 1100 open and 1280 × 1100 collapsed), `lab/php/lastrun_http_check.php`, `lab/php/run_row_http_check.php` (087: the request, `executor_missing`, the lock of a write action, accept discovery) (076: menu, page for Super admin only, `started_by`, `reporter_hostid`/`port_id`, the filter links; creates and removes one user when `ZBX_TOKEN` is set) | yes when `ZBX_UI_USER` and `ZBX_UI_PASSWORD` are set (added 046) | headless Chrome on the real page, logged in; the login comes from the environment only (skipped otherwise, with the reason) |
| `ui_check_040/stack_check.py` (098) | yes when `ZBX_UI_USER` and `ZBX_UI_PASSWORD` are set | headless Chrome on the real page: the Fold / Unfold toggle in the group and the parent panel, Back in a panel opened from another panel (the stack, its state, a new stack after a click on the graph and after Apply) |
| `ui_check_040/panel_check.py` (094), `hit_check.py` (092), `dblclick_check.py` (091, with the drag of 094) | yes when `ZBX_UI_USER` and `ZBX_UI_PASSWORD` are set | headless Chrome on the real page with DevTools Input mouse events: the node panel (section order, no "Device details", header, one line per Links row, host menu, Focus, row and line hover, Unlink confirmation), the hit area of a line, the double click and the drag |
| `discovery/test_snapshot_clock.py` | **no** | Needs a host that has the template "Topology by push" linked and its trapper rule, and writes that rule's snapshot. The lab has such a host only after `labsim provision <scenario> --transport push`, and `provision --prune` removes the office stand hosts. Needs a state of its own (a push-provisioned host) |
| `corpus/walk_text_check.py` | **no** | A measurement tool, not a test: it has no assertion and exit status 0 whatever it finds (it writes `corpus/out/walktext`). It creates two hosts and two simulators and removes them. It runs (19 s) |
| `tests/libs/zbxpreproc/zbx_item_preproc.yaml` (C, "SNMP walk to topology rows" cases) | **no** | The C unit framework needs cmocka and libyaml, which are not installed on this VM (`/usr/include/cmocka.h`, `yaml.h` missing). The same walks and parameters are covered by `step_golden/compare.php` and `edge_cases.php` (the README of step_golden says so) |
| `labsim run S0`–`S6`, `R1` (and `--transport push`, `corpus-smoke`) | **no** | These are the lab scenario runs, not unit tests: 1.5 minutes to 18 minutes each (S6), 30 minutes for the batch, and they reset `topo_*` and provision hosts. They are the "full harness" of the task rules; run them by hand |
| `ui_check_037/` (offline render), `ui_check_015/018/019/` (pictures) | **no** | Screenshot tools and pictures, no assertions |
| `seed.php`, `install_mysql.sh` | **no** | Fixture and installer, not tests |

Page checks (098): `run_php_tests.sh` runs every check of the real page with a limit of 900 s (`PAGE_TIMEOUT`). The limit, "chrome did not start", a failed login and a run without any result line are infrastructure errors: the check is run once more and the summary counts them apart ("infrastructure: <n>", status INFRA when the second run fails the same way). A check that fails is never retried. The overlap check (also inside `--check arc`) keeps the evidence of a failure in `/tmp/topo_evidence/<time>-<pid>-<check>/` (`TOPO_EVIDENCE_DIR`): `evidence.json` (overlapping pairs with px, node and label boxes, label places, node positions) and `screenshot.png`.
