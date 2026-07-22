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
** Bundle Format (ustar + gzip) Tests
** Tests tar archive creation and reading with gzip compression
*/

#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <unistd.h>
#include <sys/stat.h>

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

void test_bundle_round_trip(void)
{
	printf("\n=== Bundle Round-trip Test ===\n");

	char bundle_path[256] = "test_bundle.tar.gz";
	zbx_bundle_writer_t writer;
	zbx_bundle_reader_t reader;

	/* Test data */
	const char *test_member1_name = "manifest.json";
	const char *test_member1_data = "{\"version\":1,\"tables\":2}";
	size_t test_member1_size = strlen(test_member1_data);

	const char *test_member2_name = "hosts.csv";
	const char *test_member2_data = "\"id\",\"name\"\n\"1\",\"host1\"\n\"2\",\"host2\"\n";
	size_t test_member2_size = strlen(test_member2_data);

	/* Write bundle */
	printf("\n--- Writing bundle ---\n");
	if (zbx_bundle_writer_open(&writer, bundle_path) != SUCCEED)
	{
		printf("✗ Failed to open bundle for writing\n");
		return;
	}
	printf("✓ Opened bundle for writing: %s\n", bundle_path);

	if (zbx_bundle_write_member_begin(&writer, test_member1_name, test_member1_size) != SUCCEED)
	{
		printf("✗ Failed to begin member 1\n");
		zbx_bundle_writer_close(&writer);
		return;
	}

	if (zbx_bundle_write_member_data(&writer, test_member1_data, test_member1_size) != SUCCEED)
	{
		printf("✗ Failed to write member 1 data\n");
		zbx_bundle_writer_close(&writer);
		return;
	}

	if (zbx_bundle_write_member_end(&writer) != SUCCEED)
	{
		printf("✗ Failed to end member 1\n");
		zbx_bundle_writer_close(&writer);
		return;
	}
	printf("✓ Wrote member 1: %s (%zu bytes)\n", test_member1_name, test_member1_size);

	if (zbx_bundle_write_member_begin(&writer, test_member2_name, test_member2_size) != SUCCEED)
	{
		printf("✗ Failed to begin member 2\n");
		zbx_bundle_writer_close(&writer);
		return;
	}

	if (zbx_bundle_write_member_data(&writer, test_member2_data, test_member2_size) != SUCCEED)
	{
		printf("✗ Failed to write member 2 data\n");
		zbx_bundle_writer_close(&writer);
		return;
	}

	if (zbx_bundle_write_member_end(&writer) != SUCCEED)
	{
		printf("✗ Failed to end member 2\n");
		zbx_bundle_writer_close(&writer);
		return;
	}
	printf("✓ Wrote member 2: %s (%zu bytes)\n", test_member2_name, test_member2_size);

	if (zbx_bundle_writer_close(&writer) != SUCCEED)
	{
		printf("✗ Failed to close bundle writer\n");
		return;
	}
	printf("✓ Closed bundle writer\n");

	/* Verify file exists and is compressed */
	struct stat st;
	if (stat(bundle_path, &st) != 0)
	{
		printf("✗ Bundle file not created\n");
		return;
	}
	printf("✓ Bundle file created: %ld bytes\n", st.st_size);

	/* Read bundle */
	printf("\n--- Reading bundle ---\n");
	if (zbx_bundle_reader_open(&reader, bundle_path) != SUCCEED)
	{
		printf("✗ Failed to open bundle for reading\n");
		unlink(bundle_path);
		return;
	}
	printf("✓ Opened bundle for reading\n");

	/* Read member 1 */
	char member_name[256];
	size_t member_size;
	int eof;

	if (zbx_bundle_read_next_header(&reader, member_name, sizeof(member_name), &member_size, &eof) != SUCCEED)
	{
		printf("✗ Failed to read member 1 header\n");
		zbx_bundle_reader_close(&reader);
		unlink(bundle_path);
		return;
	}

	assert_eq(strcmp(member_name, test_member1_name) == 0, "Member 1 name matches");
	assert_eq(member_size == test_member1_size, "Member 1 size matches");

	char *member1_data = malloc(member_size + 1);
	if (NULL == member1_data)
	{
		printf("✗ Failed to allocate memory for member 1 data\n");
		zbx_bundle_reader_close(&reader);
		unlink(bundle_path);
		return;
	}

	size_t read_total = 0;
	while (read_total < member_size)
	{
		size_t got;
		if (zbx_bundle_read_member_data(&reader, member1_data + read_total, member_size - read_total, &got) != SUCCEED)
		{
			printf("✗ Failed to read member 1 data\n");
			free(member1_data);
			zbx_bundle_reader_close(&reader);
			unlink(bundle_path);
			return;
		}
		if (got == 0)
			break;
		read_total += got;
	}

	member1_data[read_total] = '\0';
	assert_eq(strcmp(member1_data, test_member1_data) == 0, "Member 1 data matches");
	free(member1_data);
	printf("✓ Read and verified member 1\n");

	/* Read member 2 */
	if (zbx_bundle_read_next_header(&reader, member_name, sizeof(member_name), &member_size, &eof) != SUCCEED)
	{
		printf("✗ Failed to read member 2 header\n");
		zbx_bundle_reader_close(&reader);
		unlink(bundle_path);
		return;
	}

	assert_eq(strcmp(member_name, test_member2_name) == 0, "Member 2 name matches");
	assert_eq(member_size == test_member2_size, "Member 2 size matches");

	char *member2_data = malloc(member_size + 1);
	if (NULL == member2_data)
	{
		printf("✗ Failed to allocate memory for member 2 data\n");
		zbx_bundle_reader_close(&reader);
		unlink(bundle_path);
		return;
	}

	read_total = 0;
	while (read_total < member_size)
	{
		size_t got;
		if (zbx_bundle_read_member_data(&reader, member2_data + read_total, member_size - read_total, &got) != SUCCEED)
		{
			printf("✗ Failed to read member 2 data\n");
			free(member2_data);
			zbx_bundle_reader_close(&reader);
			unlink(bundle_path);
			return;
		}
		if (got == 0)
			break;
		read_total += got;
	}

	member2_data[read_total] = '\0';
	assert_eq(strcmp(member2_data, test_member2_data) == 0, "Member 2 data matches");
	free(member2_data);
	printf("✓ Read and verified member 2\n");

	/* Check EOF */
	if (zbx_bundle_read_next_header(&reader, member_name, sizeof(member_name), &member_size, &eof) != SUCCEED)
	{
		assert_eq(eof, "EOF detected correctly");
	}

	if (zbx_bundle_reader_close(&reader) != SUCCEED)
	{
		printf("✗ Failed to close bundle reader\n");
		unlink(bundle_path);
		return;
	}
	printf("✓ Closed bundle reader\n");

	/* Cleanup */
	unlink(bundle_path);
	printf("✓ Cleaned up test file\n");
}

void test_bundle_empty_member(void)
{
	printf("\n=== Empty Member Test ===\n");

	char bundle_path[256] = "test_empty.tar.gz";
	zbx_bundle_writer_t writer;
	zbx_bundle_reader_t reader;

	/* Write bundle with empty member */
	if (zbx_bundle_writer_open(&writer, bundle_path) != SUCCEED)
	{
		printf("✗ Failed to open bundle\n");
		return;
	}

	if (zbx_bundle_write_member_begin(&writer, "empty.txt", 0) != SUCCEED)
	{
		printf("✗ Failed to begin empty member\n");
		zbx_bundle_writer_close(&writer);
		return;
	}

	if (zbx_bundle_write_member_end(&writer) != SUCCEED)
	{
		printf("✗ Failed to end empty member\n");
		zbx_bundle_writer_close(&writer);
		return;
	}

	if (zbx_bundle_writer_close(&writer) != SUCCEED)
	{
		printf("✗ Failed to close writer\n");
		return;
	}

	/* Read bundle with empty member */
	if (zbx_bundle_reader_open(&reader, bundle_path) != SUCCEED)
	{
		printf("✗ Failed to open bundle for reading\n");
		unlink(bundle_path);
		return;
	}

	char member_name[256];
	size_t member_size;
	int eof;

	if (zbx_bundle_read_next_header(&reader, member_name, sizeof(member_name), &member_size, &eof) != SUCCEED)
	{
		printf("✗ Failed to read header\n");
		zbx_bundle_reader_close(&reader);
		unlink(bundle_path);
		return;
	}

	assert_eq(strcmp(member_name, "empty.txt") == 0, "Empty member name correct");
	assert_eq(member_size == 0, "Empty member size is zero");

	if (zbx_bundle_reader_close(&reader) != SUCCEED)
	{
		printf("✗ Failed to close reader\n");
		unlink(bundle_path);
		return;
	}

	unlink(bundle_path);
	printf("✓ Empty member test passed\n");
}

int main(void)
{
	printf("Bundle Format (ustar + gzip) Test Suite\n");
	printf("=======================================\n");

	test_bundle_round_trip();
	test_bundle_empty_member();

	printf("\n=======================================\n");
	printf("Results: %d/%d passed\n", test_passed, test_count);

	if (test_failed > 0)
	{
		printf("%d tests FAILED\n", test_failed);
		return 1;
	}

	printf("All tests PASSED\n");
	return 0;
}
