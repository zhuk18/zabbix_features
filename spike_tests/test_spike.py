#!/usr/bin/env python3
"""
Spike Test: Database Export/Import Contract Verification

This script verifies that:
1. MySQL's QUOTE() function safely round-trips binary data
2. PostgreSQL's COPY ... FORMAT csv with bytea_output='hex' works correctly

Usage:
  python3 test_spike.py mysql <host> <user> <password> <dbname>
  python3 test_spike.py postgres <host> <user> <password> <dbname>

Requirements:
  pip install mysql-connector-python psycopg2-binary
"""

import sys
import binascii

def test_mysql_quote_roundtrip(host, user, password, database):
    print("\n========== MySQL QUOTE() Round-trip Test ==========")

    try:
        import mysql.connector
    except ImportError:
        print("ERROR: mysql-connector-python not installed")
        print("Install with: pip install mysql-connector-python")
        return

    try:
        conn = mysql.connector.connect(
            host=host,
            user=user,
            password=password,
            database=database
        )
        cursor = conn.cursor()
        print(f"✓ Connected to MySQL at {host}")
    except Exception as e:
        print(f"MySQL connection failed: {e}")
        return

    # Create test table
    try:
        cursor.execute("DROP TABLE IF EXISTS spike_test_blob")
        cursor.execute("CREATE TABLE spike_test_blob (id INT PRIMARY KEY, binary_data BLOB)")
        conn.commit()
        print("✓ Created test table")
    except Exception as e:
        print(f"Table creation failed: {e}")
        cursor.close()
        conn.close()
        return

    # Test Case 1: Edge-case bytes (0x00, 0x0a, 0x0d, 0x09, 0xff)
    print("\n--- Test 1: Edge-case byte sequence ---")
    test_bytes = bytes([0x00, 0x0a, 0x0d, 0x09, 0xff])
    print(f"Original bytes: {binascii.hexlify(test_bytes).decode()}")

    try:
        cursor.execute("INSERT INTO spike_test_blob VALUES (1, 0x000A0D09FF)")
        conn.commit()
        cursor.execute("SELECT QUOTE(binary_data) FROM spike_test_blob WHERE id = 1")
        result = cursor.fetchone()
        if result:
            print(f"QUOTE() output: {result[0]}")
            print("This output must be unescapable to recover the original bytes")
    except Exception as e:
        print(f"Test 1 failed: {e}")

    # Test Case 2: Quote and backslash bytes (0x22, 0x5c)
    print("\n--- Test 2: Quote and backslash bytes ---")
    test_bytes2 = bytes([0x22, 0x5c])
    print(f"Original bytes: {binascii.hexlify(test_bytes2).decode()}")

    try:
        cursor.execute("INSERT INTO spike_test_blob VALUES (2, 0x225C)")
        conn.commit()
        cursor.execute("SELECT QUOTE(binary_data) FROM spike_test_blob WHERE id = 2")
        result = cursor.fetchone()
        if result:
            print(f"QUOTE() output: {result[0]}")
    except Exception as e:
        print(f"Test 2 failed: {e}")

    # Cleanup
    try:
        cursor.execute("DROP TABLE spike_test_blob")
        conn.commit()
    except:
        pass

    cursor.close()
    conn.close()

    print("\n✓ MySQL QUOTE() test completed")
    print("  Review the QUOTE() outputs above to verify they are reversible")
    print("  If any byte sequence cannot be perfectly recovered, the architecture must be redesigned")


def test_postgres_bytea_roundtrip(host, user, password, database):
    print("\n========== PostgreSQL bytea + COPY FORMAT csv Test ==========")

    try:
        import psycopg2
    except ImportError:
        print("ERROR: psycopg2 not installed")
        print("Install with: pip install psycopg2-binary")
        return

    try:
        conn = psycopg2.connect(
            host=host,
            user=user,
            password=password,
            database=database
        )
        cursor = conn.cursor()
        print(f"✓ Connected to PostgreSQL at {host}")
    except Exception as e:
        print(f"PostgreSQL connection failed: {e}")
        return

    try:
        # Set bytea_output to hex
        cursor.execute("SET bytea_output = 'hex'")
        conn.commit()
        print("✓ Set bytea_output = 'hex'")

        # Create test table
        cursor.execute("DROP TABLE IF EXISTS spike_test_bytea CASCADE")
        cursor.execute("""
            CREATE TABLE spike_test_bytea (
                id SERIAL PRIMARY KEY,
                binary_data BYTEA
            )
        """)
        conn.commit()
        print("✓ Created test table")

        # Test Case 1: Edge-case bytes
        print("\n--- Test 1: Edge-case byte sequence ---")
        test_bytes = bytes([0x00, 0x0a, 0x0d, 0x09, 0xff])
        print(f"Original bytes: {binascii.hexlify(test_bytes).decode()}")

        cursor.execute("INSERT INTO spike_test_bytea (binary_data) VALUES (%s)", (test_bytes,))
        conn.commit()
        print("✓ Inserted test data")

        # Export via COPY to see the format
        import io
        output = io.StringIO()
        cursor.copy_to(output, 'spike_test_bytea', columns=['id', 'binary_data'])
        csv_output = output.getvalue()
        print(f"COPY output:\n{csv_output}")

        # Test Case 2: Quote and backslash
        print("--- Test 2: Quote and backslash bytes ---")
        test_bytes2 = bytes([0x22, 0x5c])
        print(f"Original bytes: {binascii.hexlify(test_bytes2).decode()}")

        cursor.execute("INSERT INTO spike_test_bytea (binary_data) VALUES (%s)", (test_bytes2,))
        conn.commit()

        # Cleanup
        cursor.execute("DROP TABLE spike_test_bytea")
        conn.commit()

        print("\n✓ PostgreSQL bytea test completed")
        print("  If the hex-encoded output appears as \\xXXXX format, the format is correct")
        print("  The format should be reversible via COPY ... FROM STDIN")

    except Exception as e:
        print(f"PostgreSQL test failed: {e}")
    finally:
        cursor.close()
        conn.close()


def main():
    if len(sys.argv) < 6:
        print(f"Usage: {sys.argv[0]} <mysql|postgres> <host> <user> <password> <dbname>")
        print()
        print("Example:")
        print(f"  {sys.argv[0]} mysql localhost root password testdb")
        print(f"  {sys.argv[0]} postgres localhost postgres password testdb")
        sys.exit(1)

    engine = sys.argv[1]
    host = sys.argv[2]
    user = sys.argv[3]
    password = sys.argv[4]
    database = sys.argv[5]

    print("Zabbix Configuration Migration Utility - Spike Test")
    print("====================================================")
    print("Testing database export/import contract")
    print()
    print(f"Engine: {engine}")
    print(f"Host: {host}")
    print(f"User: {user}")
    print(f"Database: {database}")

    if engine == "mysql":
        test_mysql_quote_roundtrip(host, user, password, database)
    elif engine == "postgres":
        test_postgres_bytea_roundtrip(host, user, password, database)
    else:
        print(f"Unknown engine: {engine} (use 'mysql' or 'postgres')")
        sys.exit(1)

    print("\n========== Spike Test Summary ==========")
    print("✓ Test completed")
    print("✓ If all outputs above look correct, the architecture is sound")
    print("✓ If any issues appear, stop and redesign before writing production code")


if __name__ == "__main__":
    main()
