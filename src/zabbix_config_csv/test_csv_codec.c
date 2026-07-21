/*
** CSV Codec Round-trip Tests
** Tests RFC 4180 compliant CSV with NULL markers
*/

#define _POSIX_C_SOURCE 200809L

#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <stdarg.h>

#ifndef SUCCEED
#define SUCCEED 0
#endif

#ifndef FAIL
#define FAIL -1
#endif

typedef struct
{
	char	*data;
	size_t	len;
	size_t	alloc;
}	zbx_config_csv_buf_t;

typedef struct
{
	zbx_config_csv_buf_t	field;
	const char		*data;
	size_t			data_len;
	size_t			pos;
	int			in_quotes;
	int			eof;
}	zbx_csv_parser_state_t;

static int zbx_config_csv_buf_init(zbx_config_csv_buf_t *buf, size_t initial)
{
	buf->data = (char *)malloc(initial);
	if (NULL == buf->data)
		return FAIL;
	buf->len = 0;
	buf->alloc = initial;
	buf->data[0] = '\0';
	return SUCCEED;
}

static int zbx_config_csv_buf_grow(zbx_config_csv_buf_t *buf, size_t required)
{
	size_t	new_alloc;
	char	*tmp;

	if (required <= buf->alloc)
		return SUCCEED;

	new_alloc = buf->alloc;
	while (new_alloc < required)
		new_alloc *= 2;

	tmp = (char *)realloc(buf->data, new_alloc);
	if (NULL == tmp)
		return FAIL;

	buf->data = tmp;
	buf->alloc = new_alloc;

	return SUCCEED;
}

static int zbx_config_csv_buf_appendf(zbx_config_csv_buf_t *buf, const char *fmt, ...)
{
	va_list	args;
	int	needed;

	va_start(args, fmt);
	needed = vsnprintf(NULL, 0, fmt, args);
	va_end(args);

	if (0 > needed)
		return FAIL;

	if (SUCCEED != zbx_config_csv_buf_grow(buf, buf->len + (size_t)needed + 1))
		return FAIL;

	va_start(args, fmt);
	(void)vsnprintf(buf->data + buf->len, buf->alloc - buf->len, fmt, args);
	va_end(args);

	buf->len += (size_t)needed;

	return SUCCEED;
}

static void zbx_config_csv_buf_free(zbx_config_csv_buf_t *buf)
{
	free(buf->data);
	buf->data = NULL;
	buf->len = 0;
	buf->alloc = 0;
}

static int zbx_config_csv_write_field(zbx_config_csv_buf_t *out, const char *value, int is_null)
{
	const unsigned char	*p;

	if (is_null)
		return zbx_config_csv_buf_appendf(out, "NULL");

	if (SUCCEED != zbx_config_csv_buf_appendf(out, "\""))
		return FAIL;

	for (p = (const unsigned char *)value; '\0' != *p; p++)
	{
		if ('"' == *p)
		{
			if (SUCCEED != zbx_config_csv_buf_appendf(out, "\"\""))
				return FAIL;
		}
		else
		{
			if (SUCCEED != zbx_config_csv_buf_appendf(out, "%c", *p))
				return FAIL;
		}
	}

	if (SUCCEED != zbx_config_csv_buf_appendf(out, "\""))
		return FAIL;

	return SUCCEED;
}

static int zbx_config_csv_write_row(zbx_config_csv_buf_t *out, const char **fields, size_t field_count)
{
	size_t	i;

	for (i = 0; i < field_count; i++)
	{
		if (i > 0)
		{
			if (SUCCEED != zbx_config_csv_buf_appendf(out, ","))
				return FAIL;
		}

		if (NULL == fields[i])
		{
			if (SUCCEED != zbx_config_csv_write_field(out, "", 1))
				return FAIL;
		}
		else
		{
			if (SUCCEED != zbx_config_csv_write_field(out, fields[i], 0))
				return FAIL;
		}
	}

	if (SUCCEED != zbx_config_csv_buf_appendf(out, "\n"))
		return FAIL;

	return SUCCEED;
}

static int zbx_config_csv_read_field(zbx_csv_parser_state_t *state, char **out_field, int *out_is_null)
{
	int	has_content = 0;
	zbx_config_csv_buf_t	*field = &state->field;
	field->len = 0;

	*out_field = NULL;
	*out_is_null = 0;

	if (state->pos >= state->data_len)
	{
		state->eof = 1;
		return SUCCEED;
	}

	if ('"' == state->data[state->pos])
	{
		state->in_quotes = 1;
		state->pos++;

		while (state->pos < state->data_len)
		{
			if ('"' == state->data[state->pos])
			{
				if (state->pos + 1 < state->data_len && '"' == state->data[state->pos + 1])
				{
					if (SUCCEED != zbx_config_csv_buf_appendf(field, "\""))
						return FAIL;
					state->pos += 2;
					has_content = 1;
				}
				else
				{
					state->in_quotes = 0;
					state->pos++;
					break;
				}
			}
			else
			{
				if (SUCCEED != zbx_config_csv_buf_appendf(field, "%c", state->data[state->pos]))
					return FAIL;
				state->pos++;
				has_content = 1;
			}
		}

		if (state->pos < state->data_len && ',' == state->data[state->pos])
			state->pos++;
		else if (state->pos < state->data_len && '\n' == state->data[state->pos])
			state->pos++;
	}
	else
	{
		while (state->pos < state->data_len && ',' != state->data[state->pos] && '\n' != state->data[state->pos])
		{
			if (SUCCEED != zbx_config_csv_buf_appendf(field, "%c", state->data[state->pos]))
				return FAIL;
			state->pos++;
			has_content = 1;
		}

		if (state->pos < state->data_len && ',' == state->data[state->pos])
			state->pos++;
		else if (state->pos < state->data_len && '\n' == state->data[state->pos])
			state->pos++;
	}

	if (!has_content)
	{
		*out_is_null = 1;
		return SUCCEED;
	}

	if (field->len == 4 && 0 == strncmp(field->data, "NULL", 4))
	{
		*out_is_null = 1;
	}
	else
	{
		*out_field = field->data;
	}

	return SUCCEED;
}

static int zbx_config_csv_read_row(zbx_csv_parser_state_t *state, char ***out_fields, size_t *out_field_count,
		int **out_is_null)
{
	char	**fields = NULL;
	int	*is_null = NULL;
	size_t	field_count = 0;
	size_t	alloc_fields = 0;
	char	*field;
	int	field_is_null;

	*out_fields = NULL;
	*out_field_count = 0;
	*out_is_null = NULL;

	if (state->eof && state->pos >= state->data_len)
		return SUCCEED;

	while (1)
	{
		if (field_count >= alloc_fields)
		{
			alloc_fields = alloc_fields ? alloc_fields * 2 : 10;
			fields = (char **)realloc(fields, alloc_fields * sizeof(char *));
			is_null = (int *)realloc(is_null, alloc_fields * sizeof(int));

			if (NULL == fields || NULL == is_null)
			{
				free(fields);
				free(is_null);
				return FAIL;
			}
		}

		if (SUCCEED != zbx_config_csv_read_field(state, &field, &field_is_null))
		{
			free(fields);
			free(is_null);
			return FAIL;
		}

		if (NULL != field)
		{
			fields[field_count] = (char *)malloc(strlen(field) + 1);
			if (NULL == fields[field_count])
			{
				for (size_t i = 0; i < field_count; i++)
					free(fields[i]);
				free(fields);
				free(is_null);
				return FAIL;
			}
			strcpy(fields[field_count], field);
		}
		else
		{
			fields[field_count] = NULL;
		}

		is_null[field_count] = field_is_null;
		field_count++;

		if (state->eof || state->pos >= state->data_len)
			break;

		if (state->pos > 0 && '\n' == state->data[state->pos - 1])
			break;
	}

	*out_fields = fields;
	*out_field_count = field_count;
	*out_is_null = is_null;

	return SUCCEED;
}

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
	zbx_config_csv_buf_t	csv_buf;
	zbx_csv_parser_state_t	parser;
	const char *test_data[5];
	char **read_fields;
	int *read_is_null;
	size_t read_field_count;

	printf("\n=== Test 1: Simple fields ===\n");
	zbx_config_csv_buf_init(&csv_buf, 256);
	test_data[0] = "Alice";
	test_data[1] = "123";
	test_data[2] = "example@test.com";
	test_data[3] = NULL;
	test_data[4] = "data";

	zbx_config_csv_write_row(&csv_buf, test_data, 5);
	printf("Written CSV: %s", csv_buf.data);

	zbx_config_csv_buf_init(&parser.field, 256);
	parser.data = csv_buf.data;
	parser.data_len = csv_buf.len;
	parser.pos = 0;
	parser.eof = 0;

	zbx_config_csv_read_row(&parser, &read_fields, &read_field_count, &read_is_null);

	assert_eq(read_field_count == 5, "Read 5 fields");
	assert_eq(read_is_null[0] == 0, "Field 0 not null");
	assert_eq(0 == strcmp(read_fields[0], "Alice"), "Field 0 = Alice");
	assert_eq(read_is_null[1] == 0, "Field 1 not null");
	assert_eq(0 == strcmp(read_fields[1], "123"), "Field 1 = 123");
	assert_eq(read_is_null[3] == 1, "Field 3 is null");

	for (size_t i = 0; i < read_field_count; i++)
		if (NULL != read_fields[i])
			free(read_fields[i]);
	free(read_fields);
	free(read_is_null);

	zbx_config_csv_buf_free(&csv_buf);
	zbx_config_csv_buf_free(&parser.field);

	printf("\n=== Test 2: Embedded quotes ===\n");
	zbx_config_csv_buf_init(&csv_buf, 256);
	test_data[0] = "He said \"hello\"";
	test_data[1] = "Quote: \"";
	zbx_config_csv_write_row(&csv_buf, test_data, 2);
	printf("Written CSV: %s", csv_buf.data);

	zbx_config_csv_buf_init(&parser.field, 256);
	parser.data = csv_buf.data;
	parser.data_len = csv_buf.len;
	parser.pos = 0;
	parser.eof = 0;

	zbx_config_csv_read_row(&parser, &read_fields, &read_field_count, &read_is_null);

	assert_eq(read_field_count == 2, "Read 2 fields");
	assert_eq(0 == strcmp(read_fields[0], "He said \"hello\""), "Field 0 has correct quotes");
	assert_eq(0 == strcmp(read_fields[1], "Quote: \""), "Field 1 has correct quote");

	for (size_t i = 0; i < read_field_count; i++)
		if (NULL != read_fields[i])
			free(read_fields[i]);
	free(read_fields);
	free(read_is_null);

	zbx_config_csv_buf_free(&csv_buf);
	zbx_config_csv_buf_free(&parser.field);

	printf("\n=== Test 3: Embedded newlines ===\n");
	zbx_config_csv_buf_init(&csv_buf, 256);
	test_data[0] = "Line 1\nLine 2";
	test_data[1] = "normal";
	zbx_config_csv_write_row(&csv_buf, test_data, 2);
	printf("Written CSV (escaped): ");
	for (size_t i = 0; i < csv_buf.len; i++)
	{
		if ('\n' == csv_buf.data[i])
			printf("\\n");
		else
			printf("%c", csv_buf.data[i]);
	}
	printf("\n");

	zbx_config_csv_buf_init(&parser.field, 256);
	parser.data = csv_buf.data;
	parser.data_len = csv_buf.len;
	parser.pos = 0;
	parser.eof = 0;

	zbx_config_csv_read_row(&parser, &read_fields, &read_field_count, &read_is_null);

	assert_eq(read_field_count == 2, "Read 2 fields");
	assert_eq(0 == strcmp(read_fields[0], "Line 1\nLine 2"), "Field 0 has embedded newline");

	for (size_t i = 0; i < read_field_count; i++)
		if (NULL != read_fields[i])
			free(read_fields[i]);
	free(read_fields);
	free(read_is_null);

	zbx_config_csv_buf_free(&csv_buf);
	zbx_config_csv_buf_free(&parser.field);

	printf("\n=== Test 4: Backslash handling (no escaping) ===\n");
	zbx_config_csv_buf_init(&csv_buf, 256);
	test_data[0] = "C:\\Users\\Admin";
	test_data[1] = "path\\to\\file";
	zbx_config_csv_write_row(&csv_buf, test_data, 2);
	printf("Written CSV: %s", csv_buf.data);

	zbx_config_csv_buf_init(&parser.field, 256);
	parser.data = csv_buf.data;
	parser.data_len = csv_buf.len;
	parser.pos = 0;
	parser.eof = 0;

	zbx_config_csv_read_row(&parser, &read_fields, &read_field_count, &read_is_null);

	assert_eq(read_field_count == 2, "Read 2 fields");
	assert_eq(0 == strcmp(read_fields[0], "C:\\Users\\Admin"), "Field 0 backslash preserved");
	assert_eq(0 == strcmp(read_fields[1], "path\\to\\file"), "Field 1 backslash preserved");

	for (size_t i = 0; i < read_field_count; i++)
		if (NULL != read_fields[i])
			free(read_fields[i]);
	free(read_fields);
	free(read_is_null);

	zbx_config_csv_buf_free(&csv_buf);
	zbx_config_csv_buf_free(&parser.field);

	printf("\n=== Test 5: Base64-like string (no escaping needed) ===\n");
	zbx_config_csv_buf_init(&csv_buf, 256);
	test_data[0] = "aGVsbG8gd29ybGQgYmluYXJ5IGRhdGE=";
	zbx_config_csv_write_row(&csv_buf, test_data, 1);
	printf("Written CSV: %s", csv_buf.data);

	zbx_config_csv_buf_init(&parser.field, 256);
	parser.data = csv_buf.data;
	parser.data_len = csv_buf.len;
	parser.pos = 0;
	parser.eof = 0;

	zbx_config_csv_read_row(&parser, &read_fields, &read_field_count, &read_is_null);

	assert_eq(read_field_count == 1, "Read 1 field");
	assert_eq(0 == strcmp(read_fields[0], "aGVsbG8gd29ybGQgYmluYXJ5IGRhdGE="), "Base64 preserved");

	for (size_t i = 0; i < read_field_count; i++)
		if (NULL != read_fields[i])
			free(read_fields[i]);
	free(read_fields);
	free(read_is_null);

	zbx_config_csv_buf_free(&csv_buf);
	zbx_config_csv_buf_free(&parser.field);

	printf("\n=== Test 6: All NULLs ===\n");
	zbx_config_csv_buf_init(&csv_buf, 256);
	test_data[0] = NULL;
	test_data[1] = NULL;
	test_data[2] = NULL;
	zbx_config_csv_write_row(&csv_buf, test_data, 3);
	printf("Written CSV: %s", csv_buf.data);

	zbx_config_csv_buf_init(&parser.field, 256);
	parser.data = csv_buf.data;
	parser.data_len = csv_buf.len;
	parser.pos = 0;
	parser.eof = 0;

	zbx_config_csv_read_row(&parser, &read_fields, &read_field_count, &read_is_null);

	assert_eq(read_field_count == 3, "Read 3 fields");
	assert_eq(read_is_null[0] == 1, "Field 0 is null");
	assert_eq(read_is_null[1] == 1, "Field 1 is null");
	assert_eq(read_is_null[2] == 1, "Field 2 is null");

	free(read_fields);
	free(read_is_null);

	zbx_config_csv_buf_free(&csv_buf);
	zbx_config_csv_buf_free(&parser.field);
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
