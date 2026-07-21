# zabbix_config_csv Implementation Notes

## Overview

`zabbix_config_csv` is a standalone command-line utility for exporting and importing Zabbix database configurations as portable CSV bundles. It implements the "Configuration Migration Utility" feature request, supporting both MySQL and PostgreSQL databases.

## Architecture

### Design Principles

1. **Standalone binary**: No shared code with existing `zabbix_config` tool; patterns are duplicated inline for clarity
2. **Stream-oriented**: All codecs (CSV, tar+gzip) use byte/callback patterns for memory efficiency
3. **Hybrid DB access**: Uses `popen()` + CLI clients (`mysql`/`psql`) for bulk export/import; one native connection only for final DB upgrade
4. **Fixed-schema knowledge**: BLOB columns and table exclusions are hardcoded from Zabbix schema, not introspected

### Subcommands

- `export` — Export Zabbix database to CSV bundle (tar.gz)
- `import` — Import CSV bundle to Zabbix database
- `validate` — Validate bundle integrity (checksums, row counts)
- `upgrade` — Upgrade database schema after import (uses native Zabbix upgrade framework)

## Implementation Status

### Milestone 1: CSV Codec ✓ COMPLETE

**Files:**
- `src/zabbix_config_csv/zabbix_config_csv.c` (CSV writer/reader functions)
- `src/zabbix_config_csv/test_csv_codec.c` (comprehensive test suite)

**Dialect: RFC 4180 with NULL marker**

```
Field Format:
  Non-NULL:  "quoted with "" for embedded quotes"
  NULL:      unquoted literal NULL
  
Row Format:
  comma-delimited, newline-terminated
  
Special behavior:
  - Backslash has NO escape meaning (literal data)
  - Embedded newlines allowed inside quoted fields
  - Embedded commas allowed inside quoted fields
  - Encoding: UTF-8
```

**Functions:**

1. `zbx_config_csv_write_field(buf, value, is_null)`
   - Writes a single CSV field to buffer
   - Handles quote-doubling for embedded quotes
   
2. `zbx_config_csv_write_row(buf, fields[], field_count)`
   - Writes a complete CSV row
   - Comma-delimited, newline-terminated
   
3. `zbx_config_csv_read_field(state, out_field, out_is_null)`
   - Reads a single CSV field from stream
   - State machine handles quoted/unquoted modes
   - Returns NULL marker recognition
   
4. `zbx_config_csv_read_row(state, out_fields[], out_field_count, out_is_null[])`
   - Reads complete CSV row
   - Returns array of field pointers and NULL flags

**Test Coverage:**

The test suite (`test_csv_codec.c`) validates:
- Simple quoted fields
- NULL markers (bare unquoted `NULL`)
- Embedded quotes (`""` → `"`)
- Embedded newlines inside quoted fields
- Backslash preservation (no escaping)
- Base64-like strings (no special handling needed)
- All-NULL rows

### Milestone 2: Bundle Format (ustar + gzip) — TODO

**Scope:**
- Per-table CSV streaming to tar member
- manifest.json generation (metadata + row counts)
- checksums.sha256 generation (SHA-256 per file)
- 5 GiB size cap enforcement
- Round-trip validation against native `tar`/`gzip` CLI

**Key points:**
- CSV files buffered to temp files to compute size + SHA-256 in streaming fashion
- ustar headers created with correct member sizes
- Compressed via zlib's `gzFile` API
- No PAX extended headers (fixed 512-byte ustar format)

**Dependencies:**
- zlib (already a configure.ac dependency)
- libzbxhash (SHA-256)
- CSV codec from Milestone 1

### Milestone 3: `export` subcommand — TODO

**Scope:**
- Table discovery via `information_schema` (MySQL) / `pg_catalog` (PostgreSQL)
- Column discovery and ordering
- Row streaming via `popen()` + `mysql --batch --raw` / `psql -At`
- Base64 encoding of BLOB columns
- Per-table CSV writing + bundle creation
- Progress reporting (human/JSON formats)

**Integration points:**
- Uses Milestone 1 CSV writer
- Uses Milestone 2 bundle codec

### Milestone 4: `validate` subcommand — TODO

**Scope:**
- Bundle integrity verification (shared function used by import)
- Checksum validation against `checksums.sha256`
- Row count validation against `manifest.json`
- Streaming parse of tar members (no full decompression to memory)

**Dependencies:**
- Milestone 2 bundle codec
- libzbxhash (SHA-256 verification)

### Milestone 5: `import` subcommand — TODO

**Scope:**
- Bundle verification (calls Milestone 4 validation)
- Non-empty DB check (abort if target has data)
- Foreign key disabling (MySQL/PostgreSQL specific)
- Row loading via `LOAD DATA INFILE` (MySQL) / `COPY FROM STDIN` (PostgreSQL)
- Base64 decoding of BLOB columns
- Post-load row count verification
- `ids` table / sequence resync
- Foreign key re-enabling
- Native DB upgrade (Milestone 6)

**Load syntax:**

MySQL:
```sql
LOAD DATA LOCAL INFILE '<temp_csv>'
INTO TABLE <table>
FIELDS TERMINATED BY ',' OPTIONALLY ENCLOSED BY '"' ESCAPED BY ''
LINES TERMINATED BY '\n'
IGNORE 1 LINES
(col1, col2, ...);
```

PostgreSQL:
```sql
COPY <table> (col1, col2, ...) FROM STDIN WITH (
  FORMAT csv, DELIMITER ',', QUOTE '"', ESCAPE '"',
  NULL 'NULL', ENCODING 'UTF8'
);
```

### Milestone 6: `upgrade` subcommand + native DB upgrade — TODO

**Scope:**
- Reusable `zbx_config_csv_run_native_upgrade(opts)` helper
- Native DB connection setup (unlike popen-based export/import)
- Zabbix's own upgrade framework integration
- Progress reporting via native API

**Precedent:**
- Models `src/zabbix_proxy/proxy.c` (~lines 1273-1473)
- Uses `zbx_db_check_version_and_upgrade(ZBX_HA_MODE_STANDALONE)`

## Build System

**Files modified:**
- `src/zabbix_config_csv/Makefile.am` (new) — defines binary + link libraries
- `src/Makefile.am` — added `zabbix_config_csv` to `DIST_SUBDIRS` and `SERVER_SUBDIRS`
- `configure.ac` — added `src/zabbix_config/Makefile` and `src/zabbix_config_csv/Makefile` to `AC_CONFIG_FILES`

**Library dependencies:**
- `libzbxdbupgrade`, `libzbxdbhigh`, `libzbxdbwrap`, `libzbxdb`, `libzbxdbschema`
- `libzbxhash`, `libzbxcrypto` (crypto/hash functions)
- `libzbxcommon`, `libzbxstr`, `libzbxalgo`, etc. (basic utilities)
- `libzbxgetopt` (command-line parsing)
- zlib (gzip compression)

## Testing Strategy

**Milestone 1 (CSV):**
- Unit tests with hardcoded data fixtures
- No DB/network dependencies
- Verifiable with `test_csv_codec.c`

**Milestone 2 (Bundle):**
- Round-trip against native `tar`/`gzip`/`sha256sum`
- Cross-check manually generated tarball with tool output

**Milestone 3 (Export):**
- Real DB dump (MySQL or PostgreSQL)
- Verify all tables enumerated, excluded tables absent
- Validate CSV formatting matches Milestone 1 dialect

**Milestone 4 (Validate):**
- Manually corrupt bundles (bad checksum, missing file, row count mismatch)
- Verify detection of each failure type

**Milestone 5 (Import):**
- Same-engine pair (MySQL→MySQL, PostgreSQL→PostgreSQL)
- Cross-engine pair (PostgreSQL→MySQL, MySQL→PostgreSQL)
- Verify non-empty target DB rejection
- Post-import row counts match source

**Milestone 6 (Upgrade):**
- Database snapshot at older schema version
- Verify upgrade path to current version
- Check no-op upgrade (already current) works

**Full acceptance:**
- End-to-end PostgreSQL→MySQL migration
- Verify host/item/trigger counts match
- New object creation gets non-colliding IDs
- Zabbix frontend/API accessible post-import

## CSV Dialect Design Decision

**Question:** Backslash escaping (mysqldump style) vs. RFC 4180 doubled-quote?

**Decision:** RFC 4180 doubled-quote (this is the key CSV dialect choice made in planning).

**Rationale:**
- Both work equally with MySQL `LOAD DATA` and PostgreSQL `COPY`
- RFC 4180 is standard; readable by Python `csv` module, Excel, csvkit without custom config
- Backslash style requires non-default dialect config in most generic CSV tooling
- Backslash escaping was never a DB compatibility requirement, just inherited from mysqldump convention

**Impact:**
- NULL marker: `NULL` (unquoted) instead of `\N`
- Quote escaping: `""` instead of `\"`
- Backslash: literal, never escaped

Both MySQL and PostgreSQL support the NULL word convention (MySQL when `ENCLOSED BY` is non-empty; PostgreSQL via `NULL` parameter), so both engines handle this equally.

## Error Codes

```c
#define ZBX_CONFIG_CSV_RC_USAGE         1  /* Usage/flag error */
#define ZBX_CONFIG_CSV_RC_NOT_IMPLEMENTED 2  /* Feature not yet implemented */
#define ZBX_CONFIG_CSV_RC_IO            3  /* File I/O / network error */
#define ZBX_CONFIG_CSV_RC_VALIDATION    4  /* Data validation error (corrupt bundle, etc.) */
```

## Future Extensions (Out of Scope for v1)

- User-supplied table exclusions (`--exclude-table`)
- Partial exports (by object type/host group)
- Incremental/differential exports
- Symmetric symmetric for other data formats (JSON, XML)
- Parallel table loading on import
- Resume on interrupted export/import

## Code Style

- No comments unless the WHY is non-obvious
- Reuse of simple helpers (buffer, quoting) inline rather than shared libraries
- Stream-oriented design (callbacks, state machines) for memory efficiency
- Consistent with existing `zabbix_config.c` patterns
