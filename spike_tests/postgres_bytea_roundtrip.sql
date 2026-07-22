-- Spike Test: PostgreSQL bytea with COPY FORMAT csv and bytea_output='hex'
-- Purpose: Verify that COPY ... FORMAT csv with bytea_output='hex' produces
-- stable, reproducible hex-encoded output that round-trips correctly

-- Create test table with bytea column
CREATE TABLE IF NOT EXISTS spike_test_bytea (
  id SERIAL PRIMARY KEY,
  binary_data BYTEA
);

-- Set bytea_output to hex (critical for our design)
SET bytea_output = 'hex';

-- Test Case 1: Edge-case byte sequence (0x00, 0x0a, 0x0d, 0x09, 0xff)
INSERT INTO spike_test_bytea (binary_data) VALUES (E'\\x000A0D09FF');

-- Test Case 2: Bytes that look like quotes/backslashes
INSERT INTO spike_test_bytea (binary_data) VALUES (E'\\x225C');  -- " and \

-- Test Case 3: Real image data (you would insert actual PNG/JPEG here)
-- INSERT INTO spike_test_bytea (binary_data) VALUES (pg_read_binary_file('/path/to/image.png'));

-- Export via COPY with CSV format
-- This is exactly how zabbix_export will read the data
COPY spike_test_bytea (id, binary_data) TO STDOUT WITH (FORMAT csv, FORCE_QUOTE *, NULL 'NULL');

-- Then import via COPY FROM STDIN to verify round-trip
-- This is exactly how zabbix_import will write the data
-- \copy spike_test_bytea (id, binary_data) FROM STDIN WITH (FORMAT csv, NULL 'NULL');

-- Clean up
DROP TABLE spike_test_bytea;
