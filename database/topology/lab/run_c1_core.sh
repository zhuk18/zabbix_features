#!/bin/bash
# The C1 part of level 3 (README-tests.md, task 135): the `C1-core` walks (stands/corpus/core.txt, 48 walks, about 30 minutes), compared with stands/corpus/baseline-core.json.
# The corpus stand needs the office stand down, so the script takes it down first and restores R1 at the end (`run R1 --reset`, `up R1`: about 5 minutes more).
# Usage: run_c1_core.sh [full]     `full`: every walk (128, about 75 minutes) against baseline.json instead (the night run; when a task changes the native step, the normalization
#                                  or the local port and identity rules, and before a release of the specs)
# Exit status 0: no difference from the baseline; 1: a difference (printed), a walk that did not run ok, or R1 not restored.
here=$(cd "$(dirname "$0")" && pwd)
cd "$here" || exit 2
py=${LABPY:-$HOME/work/labenv/bin/python}
if [ "${1:-}" = full ]; then opt=""; else opt="--core"; fi
office=stands/office/lab-office.yaml
status=0
echo "C1${opt:+-core} start $(date +%T)"
"$py" -m labsim --lab $office down >/dev/null 2>&1
"$py" -m labsim c1 run $opt || status=1
diff_lines=$("$py" -m labsim c1 compare $opt)
if [ -n "$diff_lines" ]; then echo "$diff_lines"; status=1; fi
echo "C1${opt:+-core} end $(date +%T), difference lines: $(printf '%s' "$diff_lines" | grep -c .)"
"$py" -m labsim --lab $office run R1 --reset --timeout 120 >/dev/null 2>&1 && "$py" -m labsim --lab $office up R1 >/dev/null 2>&1 || { echo "R1 NOT restored"; status=1; }
exit $status
