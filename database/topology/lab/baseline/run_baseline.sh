#!/usr/bin/env bash
# The baseline run of handoff 022 §3: every suite of the topology work on the checked-out commit, results and times into baseline/<commit>/.
# Run it on the old VM and on the new one with the same commit, then compare the two folders (COMMANDS.md says how).
#
#   ZBX_TOKEN=<token> database/topology/lab/baseline/run_baseline.sh [outdir]
#
# Needs: zabbix_server running with the lab hosts' templates, MariaDB/MySQL user `zabbix` (DB_PASSWORD may be set for a non-empty password),
# ~/work/labenv, database/topology/discovery/step_golden/topo_step built, the corpus in /media/sf_topology-specs/corpus.
# It writes only to <outdir>, to the databases zabbix and topo_t_*, and restores scenario_catalog_results.json after the full harness run.
set -u
REPO=$(cd "$(dirname "$0")/../../../.." && pwd)
COMMIT=$(git -C "$REPO" rev-parse --short=11 HEAD)
OUT=${1:-$REPO/database/topology/lab/baseline/$COMMIT}
PY=${LABENV_PYTHON:-$HOME/work/labenv/bin/python}
DB_USER=${DB_USER:-zabbix}
export MYSQL_PWD=${DB_PASSWORD:-}
DSN='mysql:host=localhost;dbname=topo_t_baseline;charset=utf8mb4'
mkdir -p "$OUT/lab"
: >"$OUT/times.tsv"
printf 'suite\tseconds\texit\n' >"$OUT/times.tsv"
git -C "$REPO" status --short -- database/topology >"$OUT/git-status.txt"
git -C "$REPO" rev-parse HEAD >"$OUT/commit.txt"

timed() {      # timed <name> <command...>   output to <name>.txt
	local name=$1; shift
	local t0 t1 rc
	t0=$(date +%s.%N)
	"$@" >"$OUT/$name.txt" 2>&1
	rc=$?
	t1=$(date +%s.%N)
	printf '%s\t%.1f\t%d\n' "$name" "$(echo "$t1 - $t0" | bc)" "$rc" >>"$OUT/times.tsv"
	echo "$name: exit $rc"
}

cd "$REPO/database/topology"
timed run_php_tests ./run_php_tests.sh "$DB_USER" "${DB_PASSWORD:-}"

cd "$REPO/database/topology/lab"
timed lab_unit_tests "$PY" -m unittest discover -s tests

mysql -u"$DB_USER" -e "DROP DATABASE IF EXISTS topo_t_baseline; CREATE DATABASE topo_t_baseline"
cd "$REPO/database/topology/discovery"
timed harness_first_pass php test_scenario_catalog.php --first-pass "$DSN" "$DB_USER" "${DB_PASSWORD:-}"
mysql -u"$DB_USER" -e "DROP DATABASE IF EXISTS topo_t_baseline; CREATE DATABASE topo_t_baseline"
cp scenario_catalog_results.json "$OUT/.results.keep"
timed harness_full php test_scenario_catalog.php "$DSN" "$DB_USER" "${DB_PASSWORD:-}"
cp "$OUT/.results.keep" scenario_catalog_results.json && rm -f "$OUT/.results.keep"
mysql -u"$DB_USER" -e "DROP DATABASE IF EXISTS topo_t_baseline"
"$PY" "$REPO/database/topology/lab/baseline/summarize.py" verdicts "$OUT/harness_full.txt" >"$OUT/harness_full_verdicts.tsv"
"$PY" "$REPO/database/topology/lab/baseline/summarize.py" verdicts "$OUT/harness_first_pass.txt" >"$OUT/harness_first_pass_verdicts.tsv"

cd "$REPO/database/topology/lab"
for s in S0 S1 S2 S3 S4 S5; do
	"$PY" -m labsim provision $s >"$OUT/lab/$s.provision.txt" 2>&1
	timed "lab_$s" "$PY" -m labsim run $s --reset --report "$OUT/lab/$s.json"
	cp "$OUT/lab_$s.txt" "$OUT/lab/$s.run.txt" && rm -f "$OUT/lab_$s.txt"
done
"$PY" baseline/summarize.py lab "$OUT" >"$OUT/lab_summary.tsv"
rm -f "$OUT"/lab/S*.json          # the reports hold ids and clocks; the summary keeps what a comparison needs

cd "$REPO/database/topology/corpus"
mysql -u"$DB_USER" -e "CREATE DATABASE IF NOT EXISTS topo_t_corpus"
timed corpus_as_recorded python3 corpus_run.py --user "$DB_USER" --password "${DB_PASSWORD:-}"
timed corpus_ifindex_added python3 corpus_run.py --with-ifindex --user "$DB_USER" --password "${DB_PASSWORD:-}"
timed corpus_converter_tests python3 -m unittest test_corpus_walk
python3 "$REPO/database/topology/lab/baseline/summarize.py" corpus "$REPO" >"$OUT/corpus_sha256.txt"
echo "baseline written to $OUT"
