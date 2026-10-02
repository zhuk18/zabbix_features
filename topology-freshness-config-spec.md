# Topology — configurable link freshness threshold: build spec (v1)

Status: **draft. The mechanism (§3) is a proposal and needs approval before
any code is written.**

Origin: lab simulator S3 step 2 (`topology-lab-simulator-spec.md`) cannot
run, because the freshness threshold is a hard-coded 7 days.

## 0. Objective

One definition of the link freshness threshold, readable by `ingest.php`
(CLI) and by the UI without a web-server restart, with a default of 7 days
and a lab-run override.

References:
- `topology-device-level-edge-spec.md` — §5.2 conversion after the
  freshness threshold; "the same one that makes links stale".
- `topology-prototype-spec.md` — model spec §7 staleness indicator
  ("a starting-point threshold").
- `topology-lab-simulator-spec.md` — S3 step 2.

If a requirement not listed here seems necessary while implementing, stop
and flag it rather than silently expanding scope.

## 1. Scope

**In scope:** one definition and one reader for the threshold (§2–§3);
default, minimum and invalid-value handling (§4); the lab runner override
(§5).

**Out of scope:** changing what is compared with the threshold (§2); a
settings page in the UI; any new database table.

## 2. Precondition check (done 2026-10-02)

The two constants mean the same threshold, applied to two different
timestamps of a link.

| | `TOPO_LINK_FRESH_SECONDS` | `STALE_LINK_SECONDS` |
|---|---|---|
| Where defined | `ingest.php:724`, `7 * 24 * 60 * 60` | `CTopologyPrototype.php:19`, `7 * 24 * 60 * 60` |
| Where used | `ingest.php:1855` only | `isLinkStale()` (`CTopologyPrototype.php:24`) |
| Question it answers | Is the last **port-level** confirmation of a discovered `physical_link` older than the threshold? If yes, the link may be converted to a `device_link` (device-level spec §5.2, condition 2) | Is the link's last sighting of any kind older than the threshold? If yes, the UI draws it as stale |
| Timestamp compared | `last_seen_port` (falls back to `last_seen`) | `last_seen` (the newer of `last_seen_src` / `last_seen_dst` for the drawn link) |
| Clock | ingest `$now` | `time()` at request |
| Boundary | converts when age `>` threshold | stale when age `>` threshold |

`isLinkStale()` has three callers, all in `CTopologyPrototype.php` (the
drawn link, the relation list, the neighbor list). The threshold is never
sent to the browser; the client receives only the derived `stale` boolean.
No other numeric literal for this threshold exists in `ui/` or
`database/topology/`.

The device-level spec §5.2 says the conversion uses "the same threshold that
makes links stale". The two constants therefore have one meaning; they differ
in the timestamp they are applied to, by design. They are not merged into
one *comparison*, only into one *value*.

## 3. Mechanism (proposal)

**Setting:** the Zabbix global macro `{$TOPOLOGY.LINK.FRESH.SECONDS}`,
stored in the existing `globalmacro` table (Administration → Macros).

Why this one:
- no new table, no new file, no restart; both ingest (PDO, already connected
  to the Zabbix database) and the UI read the same row;
- an operator can change it in the UI; the lab runner can change it through
  the API (`usermacro.createglobal` / `updateglobal` / `deleteglobal`), which
  keeps "configuration changes through the API";
- the type is text, so the value is validated by the reader (§4), not by the
  database.

Rejected: a config file (two processes, two users, file permissions, drift
between hosts); the `settings` table (fixed columns, no free key); an
environment variable (the PHP-FPM pool and the CLI do not share it).

**One definition:** one small file with no Zabbix dependencies, for example
`database/topology/topology_freshness.inc.php`, required by `ingest.php`
and by `CTopologyPrototype.php`. It holds the macro name, the default, the
minimum, and one function that turns the raw macro text into seconds (§4).
Each caller fetches the raw text its own way (PDO in ingest, `DBselect` in
the UI) and passes it to that function. The file is the only place where the
number or the macro name appears.

**Reading:** once per ingest run, and once per UI request (not once per
link). A missing macro is the default, with no warning.

## 4. Value rules

- Default: **604800** (7 days).
- Minimum: **60**.
- The macro text must be a plain non-negative integer (digits only, no
  sign, no unit suffix such as `7d`). Anything else, or an integer below 60,
  → the default is used and a warning is logged: `ingest.php` writes it to
  its output and status; the UI writes it with `error_log()` once per
  request.
- The warning names the macro and the rejected text (escaped; model spec §9).

## 5. Lab runner

- For S3 step 2 the runner sets the macro to a short value (60 or more)
  through the API before the step, and restores the original state after the
  run: the old value, or deletion if the macro did not exist.
- Restore also when the run fails or is interrupted (`finally`). The runner
  also writes the original state to a file under `run/` before changing it,
  so that a killed run is restored by the next `labsim` start, or by an
  explicit `labsim restore` command.
- The restore is part of the report (the macro value before, during, after).

## 6. Acceptance criteria

- No numeric literal for this threshold remains in topology code (ingest, UI
  classes, lab tool): one definition file only.
- With the macro absent, behavior is unchanged: all existing suites pass.
- Ingest and the UI use the same value: changing the macro changes both
  without a restart.
- Invalid macro text (`abc`, `-5`, `7d`, `59`) → default used, warning logged,
  in ingest and in the UI.
- S3 step 2 passes with a short threshold; the runner restores the original
  macro state, also when the run fails and after a killed run.

## 7. Open questions

- **S3 step 2 data.** Device-level spec §5.2 converts only when no latest
  NEIGHBORS snapshot contains the link at port level. In S3, AP1 is a
  reporter; its last good snapshot (kept while AP1 is unreachable, step 1)
  still contains Switch1 on eth0. The step therefore needs data that makes
  Switch1's advertisement of AP1 resolve to the device only **and** removes
  AP1's own port-level claim (for example AP1 reachable again with its LLDP
  omitted). To be defined with the lab scenario, not in this spec.
- **A device-level `STALE` setting for the UI only** (a different threshold
  for drawing than for conversion) is not offered: the device-level spec
  requires them to be the same.
