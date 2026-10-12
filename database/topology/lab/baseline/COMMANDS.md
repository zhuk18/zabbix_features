# Baseline — how to repeat it

The baseline of the old VM (Ubuntu 24.04, MariaDB 10.11, PHP 8.3) is in `<commit>/` next to this file (`<commit>` = the first 11 characters of the commit the run was made on, `<commit>/commit.txt` has all 40).
The same code must be checked out on the new VM. `run_baseline.sh` does everything below in this order and writes the same files; the commands are listed so that one suite can be run alone.

Prerequisites (new VM): `lab/setup/README.md` done; `zabbix_server` running; `ZBX_TOKEN` set; `~/work/labenv` made; `database/topology/discovery/step_golden/topo_step` built; the corpus folder mounted at
`/media/sf_topology-specs/corpus`. Database user `zabbix`; if it has a password, `export DB_PASSWORD=...` before `run_baseline.sh` (the PHP and the corpus runner take it as an argument).

## All at once
```
cd <checkout>
ZBX_TOKEN=... database/topology/lab/baseline/run_baseline.sh /tmp/baseline-new      # about 30 minutes; the output folder is yours to choose
diff <(cut -f1,3 database/topology/lab/baseline/<commit>/times.tsv) <(cut -f1,3 /tmp/baseline-new/times.tsv)      # exit codes
diff database/topology/lab/baseline/<commit>/harness_full_verdicts.tsv /tmp/baseline-new/harness_full_verdicts.tsv
diff database/topology/lab/baseline/<commit>/harness_first_pass_verdicts.tsv /tmp/baseline-new/harness_first_pass_verdicts.tsv
diff database/topology/lab/baseline/<commit>/lab_summary.tsv /tmp/baseline-new/lab_summary.tsv
diff database/topology/lab/baseline/<commit>/corpus_sha256.txt /tmp/baseline-new/corpus_sha256.txt
```
Times differ from VM to VM and are only a size. Everything else should be equal; a difference is a finding.

## One suite at a time (from the checkout root; `$P` = `~/work/labenv/bin/python`)
| Suite | Command | Result file |
|---|---|---|
| PHP tests (21 files, each in a fresh `topo_t_*` database) | `cd database/topology && ./run_php_tests.sh zabbix [password]` | `run_php_tests.txt`: one line `pass`/`FAIL` per file |
| Lab unit tests | `cd database/topology/lab && $P -m unittest discover -s tests` | `lab_unit_tests.txt` |
| Harness, first pass | `mysql -uzabbix -e "DROP DATABASE IF EXISTS topo_t_baseline; CREATE DATABASE topo_t_baseline"`, then `cd database/topology/discovery && php test_scenario_catalog.php --first-pass "mysql:host=localhost;dbname=topo_t_baseline;charset=utf8mb4" zabbix` | `harness_first_pass.txt`, verdicts in `harness_first_pass_verdicts.tsv` |
| Harness, full | the same without `--first-pass` (it rewrites the tracked `scenario_catalog_results.json`; `git checkout -- database/topology/discovery/scenario_catalog_results.json` afterwards) | `harness_full.txt`, `harness_full_verdicts.tsv` |
| Lab S0-S5 | `cd database/topology/lab && for s in S0 S1 S2 S3 S4 S5; do $P -m labsim provision $s; $P -m labsim run $s --reset --report /tmp/$s.json; done` | `lab_Sn` lines in `times.tsv`, `lab/Sn.run.txt`, `lab_summary.tsv` (steps, assertions, SHA-256 of the report without clocks and ids) |
| Corpus fixtures, as recorded | `cd database/topology/corpus && python3 corpus_run.py --user zabbix` | `corpus_as_recorded.txt`, hashes in `corpus_sha256.txt` |
| Corpus fixtures, ifIndex added (a measurement variant) | `python3 corpus_run.py --with-ifindex --user zabbix` | `corpus_ifindex_added.txt`, same hash file |
| Converter tests | `python3 -m unittest test_corpus_walk` | `corpus_converter_tests.txt` |

The corpus run compares the native C step, `push.py` and the PHP normalization for each of the 120 files and the five sources. `corpus_sha256.txt` has the SHA-256 of every file the two runs wrote to
`database/topology/corpus/out/` (the walk texts and the two result files; the result files are hashed without their `seconds` fields). Nothing of the corpus itself is in the baseline (GPLv3, task 020).
Because the hashes are of corpus-derived text, they only match when the corpus has the same files and the converter the same code.

## Reading the old results
- `times.tsv`: suite, seconds, exit code. `git-status.txt`: the state of `database/topology` when the run started (empty = clean). `commit.txt`: the commit.
- `harness_*_verdicts.tsv`: `ID<TAB>rating<TAB>verdict` for every case of the catalog (`match`, `differs`, `out of harness`).
- `lab_summary.tsv`: per scenario the number of steps, passed, failed and pending assertions, step errors, and `normalized_sha256`: equal hashes mean equal natural state, links, observations and ingest counts (clocks and ids ignored).
  The ingest counters that depend on the order of the polls (`devices_updated`) are part of the hash; if a scenario differs only in the hash, compare the two reports with `labsim compare-reports` on both sides (`run` with `--report`).
