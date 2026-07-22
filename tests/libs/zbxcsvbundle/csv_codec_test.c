/*
** Copyright (C) 2001-2026 Zabbix SIA
**
** This program is free software: you can redistribute it and/or modify it under the terms of
** the GNU Affero General Public License as published by the Free Software Foundation, version 3.
**
** This program is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY;
** without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
** See the GNU Affero General Public License for more details.
**
** You should have received a copy of the GNU Affero General Public License along with this program.
** If not, see <https://www.gnu.org/licenses/>.
*/

/*
** CSV Codec Round-trip Tests
** Tests RFC 4180 compliant CSV with NULL markers
*/

#define _POSIX_C_SOURCE 200809L

#include <stdio.h>
#include <stdlib.h>
#include <string.h>

#include "zbxcommon.h"
#include "zbxcsvbundle.h"

int test_count = 0;
int test_passed = 0;
int test_failed = 0;

void assert_eq(int cond, const char *msg)
{
	test_count++;
	if (cond)
	{
		test_passed++;
		printf("✓ %s\n", msg);
	}
	else
	{
		test_failed++;
		printf("✗ %s\n", msg);
	}
}

void test_csv_round_trip(void)
{
	zbx_csv_buf_t		csv_buf;
	zbx_csv_reader_t	parser;
	const char		*test_data[5];
	char			**read_fields;
	int			*read_is_null;
	size_t			read_field_count;

	printf("\n=== Test 1: Simple fields ===\n");
	zbx_csv_buf_init(&csv_buf, 256);
	test_data[0] = "Alice";
	test_data[1] = "123";
	test_data[2] = "example@test.com";
	test_data[3] = NULL;
	test_data[4] = "data";

	zbx_csv_write_row(&csv_buf, test_data, 5);
	printf("Written CSV: %s", csv_buf.data);

	zbx_csv_buf_init(&parser.field, 256);
	parser.data = csv_buf.data;
	parser.data_len = csv_buf.len;
	parser.pos = 0;
	parser.eof = 0;

	zbx_csv_read_row(&parser, &read_fields, &read_field_count, &read_is_null);

	assert_eq(read_field_count == 5, "Read 5 fields");
	assert_eq(read_is_null[0] == 0, "Field 0 not null");
	assert_eq(0 == strcmp(read_fields[0], "Alice"), "Field 0 = Alice");
	assert_eq(read_is_null[1] == 0, "Field 1 not null");
	assert_eq(0 == strcmp(read_fields[1], "123"), "Field 1 = 123");
	assert_eq(read_is_null[3] == 1, "Field 3 is null");

	zbx_csv_free_row(read_fields, read_is_null, read_field_count);
	zbx_csv_buf_free(&csv_buf);
	zbx_csv_buf_free(&parser.field);

	printf("\n=== Test 2: Embedded quotes ===\n");
	zbx_csv_buf_init(&csv_buf, 256);
	test_data[0] = "He said \"hello\"";
	test_data[1] = "Quote: \"";
	zbx_csv_write_row(&csv_buf, test_data, 2);
	printf("Written CSV: %s", csv_buf.data);

	zbx_csv_buf_init(&parser.field, 256);
	parser.data = csv_buf.data;
	parser.data_len = csv_buf.len;
	parser.pos = 0;
	parser.eof = 0;

	zbx_csv_read_row(&parser, &read_fields, &read_field_count, &read_is_null);

	assert_eq(read_field_count == 2, "Read 2 fields");
	assert_eq(0 == strcmp(read_fields[0], "He said \"hello\""), "Field 0 has correct quotes");
	assert_eq(0 == strcmp(read_fields[1], "Quote: \""), "Field 1 has correct quote");

	zbx_csv_free_row(read_fields, read_is_null, read_field_count);
	zbx_csv_buf_free(&csv_buf);
	zbx_csv_buf_free(&parser.field);

	printf("\n=== Test 3: Embedded newlines ===\n");
	zbx_csv_buf_init(&csv_buf, 256);
	test_data[0] = "Line 1\nLine 2";
	test_data[1] = "normal";
	zbx_csv_write_row(&csv_buf, test_data, 2);
	printf("Written CSV (escaped): ");
	for (size_t i = 0; i < csv_buf.len; i++)
	{
		if ('\n' == csv_buf.data[i])
			printf("\\n");
		else
			printf("%c", csv_buf.data[i]);
	}
	printf("\n");

	zbx_csv_buf_init(&parser.field, 256);
	parser.data = csv_buf.data;
	parser.data_len = csv_buf.len;
	parser.pos = 0;
	parser.eof = 0;

	zbx_csv_read_row(&parser, &read_fields, &read_field_count, &read_is_null);

	assert_eq(read_field_count == 2, "Read 2 fields");
	assert_eq(0 == strcmp(read_fields[0], "Line 1\nLine 2"), "Field 0 has embedded newline");

	zbx_csv_free_row(read_fields, read_is_null, read_field_count);
	zbx_csv_buf_free(&csv_buf);
	zbx_csv_buf_free(&parser.field);

	printf("\n=== Test 4: Backslash handling (no escaping) ===\n");
	zbx_csv_buf_init(&csv_buf, 256);
	test_data[0] = "C:\\Users\\Admin";
	test_data[1] = "path\\to\\file";
	zbx_csv_write_row(&csv_buf, test_data, 2);
	printf("Written CSV: %s", csv_buf.data);

	zbx_csv_buf_init(&parser.field, 256);
	parser.data = csv_buf.data;
	parser.data_len = csv_buf.len;
	parser.pos = 0;
	parser.eof = 0;

	zbx_csv_read_row(&parser, &read_fields, &read_field_count, &read_is_null);

	assert_eq(read_field_count == 2, "Read 2 fields");
	assert_eq(0 == strcmp(read_fields[0], "C:\\Users\\Admin"), "Field 0 backslash preserved");
	assert_eq(0 == strcmp(read_fields[1], "path\\to\\file"), "Field 1 backslash preserved");

	zbx_csv_free_row(read_fields, read_is_null, read_field_count);
	zbx_csv_buf_free(&csv_buf);
	zbx_csv_buf_free(&parser.field);

	printf("\n=== Test 5: Base64-like string (no escaping needed) ===\n");
	zbx_csv_buf_init(&csv_buf, 256);
	test_data[0] = "aGVsbG8gd29ybGQgYmluYXJ5IGRhdGE=";
	zbx_csv_write_row(&csv_buf, test_data, 1);
	printf("Written CSV: %s", csv_buf.data);

	zbx_csv_buf_init(&parser.field, 256);
	parser.data = csv_buf.data;
	parser.data_len = csv_buf.len;
	parser.pos = 0;
	parser.eof = 0;

	zbx_csv_read_row(&parser, &read_fields, &read_field_count, &read_is_null);

	assert_eq(read_field_count == 1, "Read 1 field");
	assert_eq(0 == strcmp(read_fields[0], "aGVsbG8gd29ybGQgYmluYXJ5IGRhdGE="), "Base64 preserved");

	zbx_csv_free_row(read_fields, read_is_null, read_field_count);
	zbx_csv_buf_free(&csv_buf);
	zbx_csv_buf_free(&parser.field);

	printf("\n=== Test 6: All NULLs ===\n");
	zbx_csv_buf_init(&csv_buf, 256);
	test_data[0] = NULL;
	test_data[1] = NULL;
	test_data[2] = NULL;
	zbx_csv_write_row(&csv_buf, test_data, 3);
	printf("Written CSV: %s", csv_buf.data);

	zbx_csv_buf_init(&parser.field, 256);
	parser.data = csv_buf.data;
	parser.data_len = csv_buf.len;
	parser.pos = 0;
	parser.eof = 0;

	zbx_csv_read_row(&parser, &read_fields, &read_field_count, &read_is_null);

	assert_eq(read_field_count == 3, "Read 3 fields");
	assert_eq(read_is_null[0] == 1, "Field 0 is null");
	assert_eq(read_is_null[1] == 1, "Field 1 is null");
	assert_eq(read_is_null[2] == 1, "Field 2 is null");

	zbx_csv_free_row(read_fields, read_is_null, read_field_count);
	zbx_csv_buf_free(&csv_buf);
	zbx_csv_buf_free(&parser.field);
}

int main(void)
{
	printf("CSV Codec Round-trip Test Suite\n");
	printf("================================\n");

	test_csv_round_trip();

	printf("\n================================\n");
	printf("Results: %d/%d passed\n", test_passed, test_count);

	if (test_failed > 0)
	{
		printf("%d tests FAILED\n", test_failed);
		return 1;
	}

	printf("All tests PASSED\n");
	return 0;
}
