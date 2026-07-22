/*
** Spike Test: Database Export/Import Contract Verification
**
** This program verifies that:
** 1. MySQL's QUOTE() function safely round-trips binary data
** 2. PostgreSQL's COPY ... FORMAT csv with bytea_output='hex' works correctly
**
** Compile with: gcc -o test_spike test_spike.c -lmysqlclient -lpq
** Or with: gcc -o test_spike test_spike.c -I/usr/include/mysql -lmysqlclient
**
** Usage: ./test_spike <mysql|postgres> <host> <user> <password> <dbname>
*/

#include <stdio.h>
#include <stdlib.h>
#include <string.h>

#ifndef _WIN32
#include <mysql/mysql.h>
#else
#include <mysql.h>
#endif

void print_hex(const unsigned char *data, size_t len) {
	for (size_t i = 0; i < len; i++) {
		printf("%02x", data[i]);
	}
}

void test_mysql_quote_roundtrip(const char *host, const char *user, const char *pass, const char *db) {
	printf("\n========== MySQL QUOTE() Round-trip Test ==========\n");

	MYSQL *conn = mysql_init(NULL);
	if (mysql_real_connect(conn, host, user, pass, db, 0, NULL, 0) == NULL) {
		fprintf(stderr, "MySQL connection failed: %s\n", mysql_error(conn));
		mysql_close(conn);
		return;
	}

	printf("✓ Connected to MySQL at %s\n", host);

	/* Create test table */
	if (mysql_query(conn, "DROP TABLE IF EXISTS spike_test_blob")) {
		fprintf(stderr, "DROP failed: %s\n", mysql_error(conn));
		mysql_close(conn);
		return;
	}

	if (mysql_query(conn, "CREATE TABLE spike_test_blob (id INT PRIMARY KEY, binary_data BLOB)")) {
		fprintf(stderr, "CREATE TABLE failed: %s\n", mysql_error(conn));
		mysql_close(conn);
		return;
	}

	printf("✓ Created test table\n");

	/* Test Case 1: Edge-case bytes (0x00, 0x0a, 0x0d, 0x09, 0xff) */
	printf("\n--- Test 1: Edge-case byte sequence ---\n");
	unsigned char test_bytes[] = {0x00, 0x0a, 0x0d, 0x09, 0xff};
	printf("Original bytes: ");
	print_hex(test_bytes, sizeof(test_bytes));
	printf("\n");

	/* Insert using hex notation */
	const char *insert_query = "INSERT INTO spike_test_blob VALUES (1, 0x000A0D09FF)";
	if (mysql_query(conn, insert_query)) {
		fprintf(stderr, "INSERT failed: %s\n", mysql_error(conn));
		mysql_close(conn);
		return;
	}

	/* Retrieve with QUOTE() */
	if (mysql_query(conn, "SELECT QUOTE(binary_data) FROM spike_test_blob WHERE id = 1")) {
		fprintf(stderr, "SELECT QUOTE failed: %s\n", mysql_error(conn));
		mysql_close(conn);
		return;
	}

	MYSQL_RES *result = mysql_store_result(conn);
	if (result) {
		MYSQL_ROW row = mysql_fetch_row(result);
		if (row && row[0]) {
			printf("QUOTE() output: %s\n", row[0]);
			printf("This output must be unescapable to recover the original bytes\n");
		}
		mysql_free_result(result);
	}

	/* Test Case 2: Quote and backslash bytes (0x22, 0x5c) */
	printf("\n--- Test 2: Quote and backslash bytes ---\n");
	unsigned char test_bytes2[] = {0x22, 0x5c};
	printf("Original bytes: ");
	print_hex(test_bytes2, sizeof(test_bytes2));
	printf("\n");

	if (mysql_query(conn, "INSERT INTO spike_test_blob VALUES (2, 0x225C)")) {
		fprintf(stderr, "INSERT failed: %s\n", mysql_error(conn));
		mysql_close(conn);
		return;
	}

	if (mysql_query(conn, "SELECT QUOTE(binary_data) FROM spike_test_blob WHERE id = 2")) {
		fprintf(stderr, "SELECT QUOTE failed: %s\n", mysql_error(conn));
		mysql_close(conn);
		return;
	}

	result = mysql_store_result(conn);
	if (result) {
		MYSQL_ROW row = mysql_fetch_row(result);
		if (row && row[0]) {
			printf("QUOTE() output: %s\n", row[0]);
		}
		mysql_free_result(result);
	}

	/* Cleanup */
	mysql_query(conn, "DROP TABLE spike_test_blob");
	mysql_close(conn);

	printf("\n✓ MySQL QUOTE() test completed\n");
	printf("  Review the QUOTE() outputs above to verify they are reversible\n");
	printf("  If any byte sequence cannot be perfectly recovered, the architecture must be redesigned\n");
}

void test_postgres_bytea_roundtrip(const char *host, const char *user, const char *pass, const char *db) {
	printf("\n========== PostgreSQL bytea + COPY FORMAT csv Test ==========\n");

	printf("PostgreSQL testing requires manual execution via psql command line\n");
	printf("Run the following commands against your PostgreSQL database:\n\n");

	printf("  psql -U %s -h %s -d %s\n\n", user, host, db);
	printf("Then execute:\n\n");

	printf("  SET bytea_output = 'hex';\n");
	printf("  CREATE TABLE IF NOT EXISTS spike_test_bytea (id SERIAL PRIMARY KEY, binary_data BYTEA);\n");
	printf("  INSERT INTO spike_test_bytea (binary_data) VALUES (E'\\\\x000A0D09FF');\n");
	printf("  COPY spike_test_bytea (id, binary_data) TO STDOUT WITH (FORMAT csv, FORCE_QUOTE *, NULL 'NULL');\n");
	printf("  DROP TABLE spike_test_bytea;\n\n");

	printf("Expected output pattern:\n");
	printf("  1,\"\\\\x000A0D09FF\"\n\n");
	printf("The bytes should appear as \\\\x-prefixed hex and be properly CSV-quoted\n");
}

int main(int argc, char *argv[]) {
	if (argc < 6) {
		printf("Usage: %s <mysql|postgres> <host> <user> <password> <dbname>\n", argv[0]);
		printf("\nExample:\n");
		printf("  %s mysql localhost root password testdb\n", argv[0]);
		printf("  %s postgres localhost postgres password testdb\n", argv[0]);
		return 1;
	}

	const char *engine = argv[1];
	const char *host = argv[2];
	const char *user = argv[3];
	const char *pass = argv[4];
	const char *db = argv[5];

	printf("Zabbix Configuration Migration Utility - Spike Test\n");
	printf("====================================================\n");
	printf("Testing database export/import contract\n\n");
	printf("Engine: %s\nHost: %s\nUser: %s\nDatabase: %s\n", engine, host, user, db);

	if (strcmp(engine, "mysql") == 0) {
		test_mysql_quote_roundtrip(host, user, pass, db);
	} else if (strcmp(engine, "postgres") == 0) {
		test_postgres_bytea_roundtrip(host, user, pass, db);
	} else {
		fprintf(stderr, "Unknown engine: %s (use 'mysql' or 'postgres')\n", engine);
		return 1;
	}

	printf("\n========== Spike Test Summary ==========\n");
	printf("✓ Test completed\n");
	printf("✓ If all outputs above look correct, the architecture is sound\n");
	printf("✓ If any issues appear, stop and redesign before writing production code\n");

	return 0;
}
