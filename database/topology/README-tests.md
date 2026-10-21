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
| `discovery/test_device_links.php`, `test_device_link_validation.php`, `test_identity_normalization.php`, `test_link_replacement.php`, `test_manual_contradiction.php`, `test_topology_model.php`, `test_shared_identifier.php`, `test_pseudo_port_index.php`, `test_far_port_identification.php`, `test_join_identity.php`, `test_duplicate_candidates.php`, `test_ingest_identity.php`, `test_identity_keys.php`, `test_normalization.php`, `test_scenario_catalog.php`, `test_stable_ingest.php`, `test_linked_port_claims.php` (047), `test_snapshot_ingest.php`, `test_ingest_lock.php`, `test_manual_link_busy_port.php` | yes | PHP, each in a fresh `topo_t_*` database |
| `test_contradiction_rule.php`, `test_lazy_host_nodes.php` | yes | PHP, fresh `topo_t_*` database |
| `test_freshness.php`, `test_node_label.php`, `test_hop_scope.php` | yes | PHP, no database |
| `discovery/test_push.py` | yes (added 046) | Python; no lab needed |
| `discovery/lld_js/test_lld_js.php` | yes (added 046) | needs `src/zabbix_js/zabbix_js` (built); skipped when it is not |
| `discovery/step_golden/compare.php`, `edge_cases.php` | yes (added 046) | the native step of the walk; `build.sh` links the driver against the built tree (skipped when the build fails) |
| `corpus/test_corpus_walk.py` | yes (added 046) | unittest; run with the lab python (with the system python one test is skipped: no PyYAML) |
| `lab/tests/test_*.py` (13 files) | yes (added 046) | `unittest discover`, lab python; `test_reset.py` needs MySQL (it makes and drops `topo_t_lab_reset`) |
| `discovery/test_port_itemids.py` | yes (added 046) | Lab test, read only; default host = the first host of the lab that has the rule `net.if.discovery` with a snapshot (skipped when there is none: provision and run a scenario first) |
| `lab/php/view_scope_check.php` | yes (added 046) | Read only, the data path of the view page on the office stand (skipped when the office hosts are not provisioned) |
| `ui/tests/api_json/testDiscoveryRuleTopologyType.php`, `testDiscoveryRuleTopologyStep.php`, `testTopologyBySnmpTemplate.php` | yes (added 046) | phpunit 8.5 against the lab Zabbix; they create and remove their own data (skipped without the phar or `ui/tests/bootstrap.php`) |
| `ui_check_040/page_shot.py --check layout` (1280 × 800 collapsed, 1460 × 900 open), `--check escape` | yes when `ZBX_UI_USER` and `ZBX_UI_PASSWORD` are set (added 046) | headless Chrome on the real page, logged in; the login comes from the environment only (skipped otherwise, with the reason) |
| `discovery/test_snapshot_clock.py` | **no** | Needs a host that has the template "Topology by push" linked and its trapper rule, and writes that rule's snapshot. The lab has such a host only after `labsim provision <scenario> --transport push`, and `provision --prune` removes the office stand hosts. Needs a state of its own (a push-provisioned host) |
| `corpus/walk_text_check.py` | **no** | A measurement tool, not a test: it has no assertion and exit status 0 whatever it finds (it writes `corpus/out/walktext`). It creates two hosts and two simulators and removes them. It runs (19 s) |
| `tests/libs/zbxpreproc/zbx_item_preproc.yaml` (C, "SNMP walk to topology rows" cases) | **no** | The C unit framework needs cmocka and libyaml, which are not installed on this VM (`/usr/include/cmocka.h`, `yaml.h` missing). The same walks and parameters are covered by `step_golden/compare.php` and `edge_cases.php` (the README of step_golden says so) |
| `labsim run S0`–`S6`, `R1` (and `--transport push`, `corpus-smoke`) | **no** | These are the lab scenario runs, not unit tests: 1.5 minutes to 18 minutes each (S6), 30 minutes for the batch, and they reset `topo_*` and provision hosts. They are the "full harness" of the task rules; run them by hand |
| `ui_check_037/` (offline render), `ui_check_015/018/019/` (pictures) | **no** | Screenshot tools and pictures, no assertions |
| `seed.php`, `install_mysql.sh` | **no** | Fixture and installer, not tests |
