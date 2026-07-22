-- Spike Test: MySQL QUOTE() round-trip for binary data
-- Purpose: Verify that QUOTE() can safely encode/decode arbitrary binary data
-- including NUL bytes, control characters, and non-ASCII bytes

-- Test 1: Synthetic binary data with edge-case bytes
-- Expected: QUOTE() should escape backslashes and quotes appropriately
-- so that unescaping returns the exact original bytes

-- Create a test table with a BLOB column
CREATE TABLE IF NOT EXISTS spike_test_blob (
  id INT PRIMARY KEY,
  binary_data BLOB
);

-- Test Case 1: Edge-case byte sequence (0x00, 0x0a, 0x0d, 0x09, 0xff)
-- In SQL, we'll insert the literal byte sequence and then query with QUOTE()
INSERT INTO spike_test_blob VALUES (1, X'000A0D09FF');

-- Retrieve with QUOTE() - this is what our MySQL export path will see
SELECT id, QUOTE(binary_data) as quoted FROM spike_test_blob WHERE id = 1;
-- Expected output pattern: 0x000A0D09FF (or similar hex escaping)
-- Our parser must reverse this exactly

-- Test Case 2: Binary data containing quotes and backslashes
-- Simulating a case where the binary data naturally contains ASCII quote/backslash bytes
INSERT INTO spike_test_blob VALUES (2, 0x225C);  -- " and \  as raw bytes
SELECT id, QUOTE(binary_data) as quoted FROM spike_test_blob WHERE id = 2;
-- Expected: properly escaped so our parser can reconstruct the original

-- Test Case 3: Real image data simulation
-- (For this you would INSERT actual PNG/JPEG binary data)
-- Example: INSERT INTO spike_test_blob VALUES (3, <actual PNG bytes>);
-- Then: SELECT QUOTE(binary_data) FROM spike_test_blob WHERE id = 3;

-- Clean up
DROP TABLE spike_test_blob;
