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

#define _POSIX_C_SOURCE 200809L

#include "config.h"

#include <errno.h>
#include <limits.h>
#include <stdio.h>
#include <stdlib.h>
#include <stdarg.h>
#include <string.h>

#include "zbxcommon.h"
#include "zbxcsvbundle.h"

int zbx_csv_buf_init(zbx_csv_buf_t *buf, size_t initial)
{
	buf->data = (char *)malloc(initial);

	if (NULL == buf->data)
		return FAIL;

	buf->len = 0;
	buf->alloc = initial;
	buf->data[0] = '\0';

	return SUCCEED;
}

int zbx_csv_buf_grow(zbx_csv_buf_t *buf, size_t required)
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

int zbx_csv_buf_appendf(zbx_csv_buf_t *buf, const char *fmt, ...)
{
	va_list	args;
	int	needed;

	va_start(args, fmt);
	needed = vsnprintf(NULL, 0, fmt, args);
	va_end(args);

	if (0 > needed)
		return FAIL;

	if (SUCCEED != zbx_csv_buf_grow(buf, buf->len + (size_t)needed + 1))
		return FAIL;

	va_start(args, fmt);
	(void)vsnprintf(buf->data + buf->len, buf->alloc - buf->len, fmt, args);
	va_end(args);

	buf->len += (size_t)needed;

	return SUCCEED;
}

int zbx_csv_buf_append_len(zbx_csv_buf_t *buf, const char *data, size_t len)
{
	if (SUCCEED != zbx_csv_buf_grow(buf, buf->len + len + 1))
		return FAIL;

	memcpy(buf->data + buf->len, data, len);
	buf->len += len;
	buf->data[buf->len] = '\0';

	return SUCCEED;
}

void zbx_csv_buf_free(zbx_csv_buf_t *buf)
{
	free(buf->data);
	buf->data = NULL;
	buf->len = 0;
	buf->alloc = 0;
}

int zbx_csv_write_field(zbx_csv_buf_t *out, const char *value, int is_null)
{
	const unsigned char	*p;

	if (is_null)
		return zbx_csv_buf_appendf(out, "NULL");

	if (SUCCEED != zbx_csv_buf_appendf(out, "\""))
		return FAIL;

	for (p = (const unsigned char *)value; '\0' != *p; p++)
	{
		if ('"' == *p)
		{
			if (SUCCEED != zbx_csv_buf_appendf(out, "\"\""))
				return FAIL;
		}
		else
		{
			if (SUCCEED != zbx_csv_buf_appendf(out, "%c", *p))
				return FAIL;
		}
	}

	if (SUCCEED != zbx_csv_buf_appendf(out, "\""))
		return FAIL;

	return SUCCEED;
}

int zbx_csv_write_row(zbx_csv_buf_t *out, const char **fields, size_t field_count)
{
	size_t	i;

	for (i = 0; i < field_count; i++)
	{
		if (i > 0)
		{
			if (SUCCEED != zbx_csv_buf_appendf(out, ","))
				return FAIL;
		}

		if (NULL == fields[i])
		{
			if (SUCCEED != zbx_csv_write_field(out, "", 1))
				return FAIL;
		}
		else
		{
			if (SUCCEED != zbx_csv_write_field(out, fields[i], 0))
				return FAIL;
		}
	}

	if (SUCCEED != zbx_csv_buf_appendf(out, "\n"))
		return FAIL;

	return SUCCEED;
}

int zbx_csv_read_field(zbx_csv_reader_t *state, char **out_field, int *out_is_null)
{
	int	has_content = 0;

	zbx_csv_buf_t	*field = &state->field;
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
					if (SUCCEED != zbx_csv_buf_appendf(field, "\""))
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
				if (SUCCEED != zbx_csv_buf_appendf(field, "%c", state->data[state->pos]))
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
			if (SUCCEED != zbx_csv_buf_appendf(field, "%c", state->data[state->pos]))
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

int zbx_csv_read_row(zbx_csv_reader_t *state, char ***out_fields, size_t *out_field_count,
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

		if (SUCCEED != zbx_csv_read_field(state, &field, &field_is_null))
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

void zbx_csv_free_row(char **fields, int *is_null, size_t field_count)
{
	for (size_t i = 0; i < field_count; i++)
	{
		if (NULL != fields[i])
			free(fields[i]);
	}
	free(fields);
	free(is_null);
}
