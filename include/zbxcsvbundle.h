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

/* Bundle (tar+gzip) API */

typedef struct
{
	void	*gz_file;		/* gzFile handle (opaque) */
	char	path[4096];		/* output file path */
}
zbx_bundle_writer_t;

typedef struct
{
	void	*gz_file;		/* gzFile handle (opaque) */
	unsigned char	header[512];	/* current ustar header buffer */
	size_t	member_pos;		/* bytes read from current member */
	size_t	member_size;		/* total size of current member */
}
zbx_bundle_reader_t;

int	zbx_bundle_writer_open(zbx_bundle_writer_t *bw, const char *path);
int	zbx_bundle_write_member_begin(zbx_bundle_writer_t *bw, const char *name, size_t size);
int	zbx_bundle_write_member_data(zbx_bundle_writer_t *bw, const void *data, size_t len);
int	zbx_bundle_write_member_end(zbx_bundle_writer_t *bw);
int	zbx_bundle_writer_close(zbx_bundle_writer_t *bw);

int	zbx_bundle_reader_open(zbx_bundle_reader_t *br, const char *path);
int	zbx_bundle_read_next_header(zbx_bundle_reader_t *br, char *name_out, size_t name_size, size_t *size_out, int *eof_out);
int	zbx_bundle_read_member_data(zbx_bundle_reader_t *br, void *buf, size_t want, size_t *got);
int	zbx_bundle_reader_close(zbx_bundle_reader_t *br);

#endif
