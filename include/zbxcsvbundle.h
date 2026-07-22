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

#ifndef ZBXCSVBUNDLE_H
#define ZBXCSVBUNDLE_H

#include "zbxcommon.h"

typedef struct
{
	char	*data;
	size_t	len;
	size_t	alloc;
}
zbx_csv_buf_t;

typedef struct
{
	zbx_csv_buf_t	field;
	const char	*data;
	size_t		data_len;
	size_t		pos;
	int		in_quotes;
	int		eof;
}
zbx_csv_reader_t;

int	zbx_csv_buf_init(zbx_csv_buf_t *buf, size_t initial);
int	zbx_csv_buf_grow(zbx_csv_buf_t *buf, size_t required);
int	zbx_csv_buf_appendf(zbx_csv_buf_t *buf, const char *fmt, ...);
int	zbx_csv_buf_append_len(zbx_csv_buf_t *buf, const char *data, size_t len);
void	zbx_csv_buf_free(zbx_csv_buf_t *buf);

int	zbx_csv_write_field(zbx_csv_buf_t *out, const char *value, int is_null);
int	zbx_csv_write_row(zbx_csv_buf_t *out, const char **fields, size_t field_count);

int	zbx_csv_read_field(zbx_csv_reader_t *state, char **out_field, int *out_is_null);
int	zbx_csv_read_row(zbx_csv_reader_t *state, char ***out_fields, size_t *out_field_count, int **out_is_null);
void	zbx_csv_free_row(char **fields, int *is_null, size_t field_count);

#endif
