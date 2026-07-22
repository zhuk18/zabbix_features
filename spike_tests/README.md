# Spike Tests: Database Export/Import Contract Verification

**CRITICAL: These tests are a hard prerequisite before writing any production code for the zabbix_export/zabbix_import tools.**

The entire architecture of the bundle format depends on verifying that:
1. MySQL's `QUOTE()` function safely round-trips arbitrary binary data
2. PostgreSQL's `COPY ... FORMAT csv` with `bytea_output='hex'` produces stable output

## Why This Matters

The export/import tools must handle arbitrary binary data (specifically the `images.image` BLOB column, which contains uploaded PNG/JPEG files). These files contain bytes like `0x00`, `0x0a`, `0x0d`, `0xff` — exactly the bytes that would break a naive text-based serialization.

If `QUOTE()` or `COPY ... FORMAT csv` don't handle these bytes correctly, the entire MySQL export path fails, and we need to redesign before writing production code.

## Test Procedures

### MySQL QUOTE() Round-trip Test

1. **Connect to a test MySQL instance** (not production):
   ```bash
   mysql -u <user> -p -h <host> < mysql_quote_roundtrip.sql
   ```

2. **Examine the output** for each test case:
   - **Test 1**: `QUOTE()` output for bytes `0x00 0x0a 0x0d 0x09 0xff`
     - Should produce: `0x000A0D09FF` or similar hex escaping
     - Verify our parser can reverse it exactly
   
   - **Test 2**: `QUOTE()` output for bytes `0x22 0x5c` (" and \)
     - Should escape properly so `0x22 0x5c` is recoverable
   
   - **Test 3**: Run with actual image files
     ```sql
     INSERT INTO spike_test_blob VALUES (3, LOAD_FILE('/path/to/test.png'));
     SELECT QUOTE(binary_data) FROM spike_test_blob WHERE id = 3;
     ```
     Verify the output is reversible

3. **Write a small test program** (pseudo-code):
   ```
   For each QUOTE() output:
     - Unescape backslashes and quotes per MySQL string literal rules
     - Verify byte-for-byte equality with original input
     - If any byte doesn't round-trip, STOP and redesign
   ```

### PostgreSQL COPY FORMAT csv Round-trip Test

1. **Connect to a test PostgreSQL instance**:
   ```bash
   psql -U <user> -h <host> -d <dbname> < postgres_bytea_roundtrip.sql
   ```

2. **Examine the COPY output**:
   - Look for the `\x`-prefixed hex encoding (e.g., `\x000A0D09FF`)
   - Verify it exactly matches what `bytea_output='hex'` produces
   - Confirm the CSV quoting is applied correctly on top

3. **Test the round-trip** with `\copy ... FROM STDIN`:
   ```sql
   CREATE TABLE test_round_trip (id INT, data BYTEA);
   \copy test_round_trip FROM STDIN WITH (FORMAT csv, NULL 'NULL');
   <paste the COPY output here>
   .
   
   -- Verify each row has identical binary data
   SELECT id, data FROM test_round_trip;
   ```

4. **Test with real image files**:
   ```sql
   INSERT INTO spike_test_bytea (binary_data) VALUES (pg_read_binary_file('/path/to/test.jpg'));
   COPY spike_test_bytea (id, binary_data) TO '/tmp/export.csv' WITH (FORMAT csv, FORCE_QUOTE *);
   
   -- Manually inspect /tmp/export.csv to see the hex encoding
   -- Then round-trip it back via COPY FROM STDIN and verify equality
   ```

## Success Criteria

✅ **Both spike tests pass** if:
1. Arbitrary binary data (including `0x00`, `0x0a`, `0x0d`, `0xff`) survives QUOTE()→unescape round-trip in MySQL
2. Arbitrary binary data survives COPY→hex→COPY round-trip in PostgreSQL
3. Real image files (PNG, JPEG) round-trip byte-for-byte through both engines

❌ **Spike fails and architecture must be redesigned** if:
1. Any byte sequence cannot be perfectly recovered after QUOTE()→unescape
2. Postgres `bytea_output='hex'` is not stable/available on your target versions
3. Image data corruption occurs during round-trip

## What If the Spike Fails?

If either test fails, **stop immediately** and:
1. Document which byte sequences or conditions cause the failure
2. Consider fallback approaches:
   - For MySQL: try `QUOTE(CAST(col AS BINARY))` or use `SELECT INTO OUTFILE` with `secure_file_priv`
   - For Postgres: use `encode(bytea_col, 'escape')` instead of hex, or use `COPY ... TO FILE`
3. **Do not proceed** with production code until the export/import contract is verified

## Related Documentation

- Plan file: `/plans/i-have-a-zabbix-config-adaptive-allen.md` (DB↔CSV serialization contract section)
- Implementation notes: `IMPLEMENTATION_NOTES.md` in the same directory
- Schema audit: `create/src/schema.tmpl` (confirms only `images.image` is a BLOB in scope)
