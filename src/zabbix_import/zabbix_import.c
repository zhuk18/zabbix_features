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

#include "config.h"

#include <errno.h>
#include <signal.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <time.h>

#include "zbxcommon.h"
#include "zbxcsvbundle.h"
#include "zbxdbschema.h"
#include "zbxhash.h"

/* Excluded tables (Appendix B) - history/trends are never part of a config bundle */
static const char *excluded_tables[] = {
	"history",
	"history_uint",
	"history_str",
	"history_text",
	"history_log",
	"trends",
	"trends_uint",
	"history_bin",
	NULL
};

/* Sentinel emitted via psql "\echo" to reliably delimit each command's output on the shared pipe. */
#define ZBX_PG_SENTINEL		"__ZBX_EOT__"

typedef struct
{
	char	*db_type;
	char	*db_host;
	char	*db_port;
	char	*db_name;
	char	*db_user;
	char	*db_password;
	char	*input_file;
} zbx_import_options_t;

typedef struct
{
	char	*dbversion_mandatory;
} zbx_manifest_t;

typedef struct
{
	int		status;
	int		exit_code;
	char		*error_msg;
	int		tables_imported;
	int		rows_imported;
	char		*bundle_filename;
	zbx_manifest_t	*manifest;
} zbx_import_result_t;

static int zbx_is_excluded_table(const char *table)
{
	for (int i = 0; NULL != excluded_tables[i]; i++)
		if (0 == strcmp(table, excluded_tables[i]))
			return 1;
	return 0;
}

static int zbx_table_in_schema(const zbx_db_table_t *tables, const char *table)
{
	for (int t = 0; NULL != tables[t].table; t++)
		if (0 == strcmp(tables[t].table, table))
			return 1;
	return 0;
}

/* Convert a 32-byte SHA-256 digest to a lowercase hex string (out must hold 65 bytes). */
static void zbx_sha256_to_hex(const unsigned char *digest, char *out)
{
	for (int i = 0; i < ZBX_SHA256_DIGEST_SIZE; i++)
		zbx_snprintf(out + i * 2, 3, "%02x", digest[i]);
	out[ZBX_SHA256_DIGEST_SIZE * 2] = '\0';
}

/* Read one full logical line from the pipe into *buf (grown on demand). On return *len holds the number */
/* of bytes read, including a trailing newline when present. Returns FAIL only at end of input. */
static int zbx_pg_read_line(FILE *pipe, char **buf, size_t *buf_alloc, size_t *len)
{
	*len = 0;

	if (0 == *buf_alloc)
	{
		*buf_alloc = 8192;
		*buf = zbx_malloc(NULL, *buf_alloc);
	}

	for (;;)
	{
		if (NULL == fgets(*buf + *len, (int)(*buf_alloc - *len), pipe))
			break;

		*len += strlen(*buf + *len);

		if (0 < *len && '\n' == (*buf)[*len - 1])
			break;

		if (*len + 1 >= *buf_alloc)
		{
			*buf_alloc *= 2;
			*buf = zbx_realloc(*buf, *buf_alloc);
		}
	}

	return (0 < *len) ? SUCCEED : FAIL;
}

static int zbx_pg_line_is_sentinel(const char *buf, size_t len)
{
	while (0 < len && ('\n' == buf[len - 1] || '\r' == buf[len - 1]))
		len--;

	return (ZBX_CONST_STRLEN(ZBX_PG_SENTINEL) == len && 0 == strncmp(buf, ZBX_PG_SENTINEL, len)) ? 1 : 0;
}

/* Discard all pipe output up to (and including) the sentinel. Returns FAIL if the pipe closed first */
/* (connection lost or a command failed under ON_ERROR_STOP). */
static int zbx_pg_drain_to_sentinel(FILE *pipe, char **buf, size_t *buf_alloc)
{
	size_t	len;

	while (SUCCEED == zbx_pg_read_line(pipe, buf, buf_alloc, &len))
	{
		if (0 != zbx_pg_line_is_sentinel(*buf, len))
			return SUCCEED;
	}

	return FAIL;
}

/* Read output up to the sentinel. *value receives the first non-empty line (newline stripped) as an */
/* allocated string, or NULL. Returns FAIL if the pipe closed before the sentinel. */
static int zbx_pg_read_value(FILE *pipe, char **buf, size_t *buf_alloc, char **value)
{
	size_t	len;

	*value = NULL;

	while (SUCCEED == zbx_pg_read_line(pipe, buf, buf_alloc, &len))
	{
		if (0 != zbx_pg_line_is_sentinel(*buf, len))
			return SUCCEED;

		if (NULL == *value)
		{
			while (0 < len && ('\n' == (*buf)[len - 1] || '\r' == (*buf)[len - 1]))
				(*buf)[--len] = '\0';

			if (0 < len)
				*value = zbx_strdup(NULL, *buf);
		}
	}

	return FAIL;
}

/* Send a command followed by the sentinel and flush. */
static void zbx_pg_send(FILE *pipe, const char *sql)
{
	fprintf(pipe, "%s\n\\echo " ZBX_PG_SENTINEL "\n", sql);
	fflush(pipe);
}

/* Set libpq connection environment so the credentials never appear on the psql command line. */
static void zbx_pg_setenv(zbx_import_options_t *opts)
{
	if (opts->db_host && *opts->db_host)
		setenv("PGHOST", opts->db_host, 1);

	if (opts->db_port && *opts->db_port)
		setenv("PGPORT", opts->db_port, 1);

	if (opts->db_user && *opts->db_user)
		setenv("PGUSER", opts->db_user, 1);

	if (opts->db_name && *opts->db_name)
		setenv("PGDATABASE", opts->db_name, 1);

	if (opts->db_password && *opts->db_password)
		setenv("PGPASSWORD", opts->db_password, 1);
}

/* Escape a value for use inside a single-quoted PostgreSQL string literal (doubles embedded quotes). */
static char *zbx_pg_escape_literal(const char *s)
{
	zbx_csv_buf_t	buf;
	char		*result;

	if (SUCCEED != zbx_csv_buf_init(&buf, 64))
		return NULL;

	for (const char *p = s; '\0' != *p; p++)
	{
		if ('\'' == *p)
			zbx_csv_buf_append_len(&buf, "''", 2);
		else
			zbx_csv_buf_append_len(&buf, p, 1);
	}

	result = zbx_strdup(NULL, buf.data);
	zbx_csv_buf_free(&buf);

	return result;
}

/* Turn a plain CSV header ("a,b,c") into a double-quoted identifier list (`"a","b","c"`) so the column */
/* names are matched case-exactly and cannot inject SQL. */
static char *zbx_pg_quote_columns(const char *header)
{
	zbx_csv_buf_t	buf;
	char		*result;
	const char	*p = header;
	int		first = 1;

	if (SUCCEED != zbx_csv_buf_init(&buf, 256))
		return NULL;

	while ('\0' != *p)
	{
		const char	*comma = strchr(p, ',');
		size_t		col_len = (NULL != comma) ? (size_t)(comma - p) : strlen(p);

		while (0 < col_len && ('\r' == p[col_len - 1] || '\n' == p[col_len - 1]))
			col_len--;

		if (0 == first)
			zbx_csv_buf_append_len(&buf, ",", 1);

		zbx_csv_buf_append_len(&buf, "\"", 1);

		for (size_t i = 0; i < col_len; i++)
		{
			if ('"' == p[i])
				zbx_csv_buf_append_len(&buf, "\"\"", 2);
			else
				zbx_csv_buf_append_len(&buf, &p[i], 1);
		}

		zbx_csv_buf_append_len(&buf, "\"", 1);
		first = 0;

		if (NULL == comma)
			break;

		p = comma + 1;
	}

	result = zbx_strdup(NULL, buf.data);
	zbx_csv_buf_free(&buf);

	return result;
}

/* Look up the expected checksum for a file name in the checksums.sha256 text. Each line is */
/* "<64 hex>  <name>". Returns SUCCEED and fills out_hex[65] when found. */
static int zbx_pg_find_checksum(const char *checksums, const char *name, char *out_hex)
{
	const char	*line = checksums;
	size_t		name_len = strlen(name);

	while (NULL != line && '\0' != *line)
	{
		const char	*nl = strchr(line, '\n');
		size_t		line_len = (NULL != nl) ? (size_t)(nl - line) : strlen(line);

		if (66 + name_len == line_len && ' ' == line[64] && ' ' == line[65] &&
				0 == strncmp(line + 66, name, name_len))
		{
			memcpy(out_hex, line, 64);
			out_hex[64] = '\0';
			return SUCCEED;
		}

		if (NULL == nl)
			break;

		line = nl + 1;
	}

	return FAIL;
}

static int zbx_checksum_matches(const char *checksums, const char *name, const char *data, size_t data_len)
{
	char		expected[65], actual[65];
	unsigned char	digest[ZBX_SHA256_DIGEST_SIZE];
	sha256_ctx	ctx;

	if (SUCCEED != zbx_pg_find_checksum(checksums, name, expected))
		return FAIL;

	zbx_sha256_init(&ctx);
	zbx_sha256_process_bytes(data, data_len, &ctx);
	zbx_sha256_finish(&ctx, digest);
	zbx_sha256_to_hex(digest, actual);

	return (0 == strcmp(expected, actual)) ? SUCCEED : FAIL;
}

/* Verify bundle integrity: every table CSV and manifest.json must match checksums.sha256. */
static int zbx_verify_bundle_integrity(const char *checksums, const char *manifest_data, size_t manifest_size,
	zbx_csv_buf_t **csv_members, char **csv_names, int csv_count, zbx_import_result_t *result)
{
	if (NULL == checksums)
	{
		result->exit_code = 2;
		result->error_msg = zbx_strdup(NULL, "Bundle missing checksums.sha256");
		return FAIL;
	}

	if (SUCCEED != zbx_checksum_matches(checksums, "manifest.json", manifest_data, manifest_size))
	{
		result->exit_code = 2;
		result->error_msg = zbx_strdup(NULL, "Integrity check failed for manifest.json");
		return FAIL;
	}

	for (int i = 0; i < csv_count; i++)
	{
		if (SUCCEED != zbx_checksum_matches(checksums, csv_names[i], csv_members[i]->data, csv_members[i]->len))
		{
			result->exit_code = 2;
			result->error_msg = zbx_dsprintf(NULL, "Integrity check failed for %s", csv_names[i]);
			return FAIL;
		}
	}

	return SUCCEED;
}

/* Parse manifest.json to extract dbversion_mandatory (best-effort, string search). */
static int zbx_parse_manifest(const char *manifest_data, zbx_import_result_t *result)
{
	const char	*dbver_start;

	result->manifest = (zbx_manifest_t *)zbx_malloc(NULL, sizeof(zbx_manifest_t));
	memset(result->manifest, 0, sizeof(zbx_manifest_t));

	if (NULL != (dbver_start = strstr(manifest_data, "\"dbversion_mandatory\": \"")))
	{
		const char	*dbver_end;

		dbver_start += ZBX_CONST_STRLEN("\"dbversion_mandatory\": \"");

		if (NULL != (dbver_end = strchr(dbver_start, '"')))
		{
			size_t	dbver_len = (size_t)(dbver_end - dbver_start);

			result->manifest->dbversion_mandatory = zbx_malloc(NULL, dbver_len + 1);
			memcpy(result->manifest->dbversion_mandatory, dbver_start, dbver_len);
			result->manifest->dbversion_mandatory[dbver_len] = '\0';
		}
	}

	return SUCCEED;
}

static int zbx_import_postgres(zbx_import_options_t *opts, zbx_import_result_t *result,
	zbx_csv_buf_t **csv_members, char **csv_names, int csv_count)
{
	FILE			*pipe = NULL;
	char			*line = NULL;
	size_t			line_alloc = 0;
	char			*value;
	int			ret = FAIL;
	const zbx_db_table_t	*tables;

	/* Credentials go through the environment; ON_ERROR_STOP makes psql exit on the first SQL error, */
	/* which we detect as a premature pipe close. Ignore SIGPIPE so writing to a dead psql fails cleanly. */
	zbx_pg_setenv(opts);
	signal(SIGPIPE, SIG_IGN);

	if (NULL == (pipe = popen("psql -q -X -w", "r+")))
	{
		result->exit_code = 4;
		result->error_msg = zbx_dsprintf(NULL, "Failed to launch psql: %s", strerror(errno));
		return FAIL;
	}

	fprintf(pipe,
		"\\set ON_ERROR_STOP on\n"
		"\\pset tuples_only on\n"
		"\\pset format unaligned\n"
		"\\pset footer off\n"
		"BEGIN;\n"
		"SET session_replication_role = 'replica';\n"
		"\\echo " ZBX_PG_SENTINEL "\n");
	fflush(pipe);

	if (SUCCEED != zbx_pg_drain_to_sentinel(pipe, &line, &line_alloc))
	{
		result->exit_code = 4;
		result->error_msg = zbx_strdup(NULL, "Failed to connect to PostgreSQL");
		goto out;
	}

	/* Precondition: target schema version must match the bundle. */
	if (NULL != result->manifest && NULL != result->manifest->dbversion_mandatory)
	{
		char	*escaped = zbx_pg_escape_literal(result->manifest->dbversion_mandatory);
		char	*sql;

		if (NULL == escaped)
		{
			result->exit_code = 6;
			result->error_msg = zbx_strdup(NULL, "Out of memory escaping schema version");
			goto out;
		}

		sql = zbx_dsprintf(NULL, "SELECT count(*) FROM dbversion WHERE mandatory = '%s';", escaped);

		zbx_pg_send(pipe, sql);
		zbx_free(sql);
		zbx_free(escaped);

		if (SUCCEED != zbx_pg_read_value(pipe, &line, &line_alloc, &value))
		{
			result->exit_code = 4;
			result->error_msg = zbx_strdup(NULL, "Lost connection while checking schema version");
			goto out;
		}

		if (NULL == value || 0 != strcmp(value, "1"))
		{
			zbx_free(value);
			result->exit_code = 3;
			result->error_msg = zbx_strdup(NULL, "Target database schema version does not match bundle");
			goto out;
		}

		zbx_free(value);
	}

	if (NULL == (tables = zbx_dbschema_get_tables()))
	{
		result->exit_code = 6;
		result->error_msg = zbx_strdup(NULL, "Failed to get database schema");
		goto out;
	}

	/* Precondition: target database must be empty (history/trends are not part of a config bundle). */
	for (int t = 0; NULL != tables[t].table; t++)
	{
		char	*sql;

		if (0 != zbx_is_excluded_table(tables[t].table))
			continue;

		sql = zbx_dsprintf(NULL, "SELECT count(*) FROM (SELECT 1 FROM \"%s\" LIMIT 1) s;", tables[t].table);
		zbx_pg_send(pipe, sql);
		zbx_free(sql);

		if (SUCCEED != zbx_pg_read_value(pipe, &line, &line_alloc, &value))
		{
			result->exit_code = 4;
			result->error_msg = zbx_strdup(NULL, "Lost connection while checking that target is empty");
			goto out;
		}

		if (NULL != value && 0 != strcmp(value, "0"))
		{
			result->error_msg = zbx_dsprintf(NULL, "Target database is not empty: table %s has data",
					tables[t].table);
			zbx_free(value);
			result->exit_code = 3;
			goto out;
		}

		zbx_free(value);
	}

	/* Load each table via COPY FROM STDIN. */
	for (int csv_idx = 0; csv_idx < csv_count; csv_idx++)
	{
		zbx_csv_buf_t	*csv_data = csv_members[csv_idx];
		size_t		name_len = strlen(csv_names[csv_idx]);
		char		*table_name, *columns, *sql;
		const char	*header_end, *data_start;
		size_t		header_len, data_len;

		/* member name is "<table>.csv" (validated as a .csv member before it was stored) */
		table_name = zbx_strdup(NULL, csv_names[csv_idx]);
		table_name[name_len - 4] = '\0';

		if (0 == zbx_table_in_schema(tables, table_name))
		{
			result->error_msg = zbx_dsprintf(NULL, "Bundle contains unknown table: %s", table_name);
			zbx_free(table_name);
			result->exit_code = 2;
			goto out;
		}

		if (NULL == (header_end = strchr(csv_data->data, '\n')))
		{
			result->error_msg = zbx_dsprintf(NULL, "Invalid CSV for table %s: missing header", table_name);
			zbx_free(table_name);
			result->exit_code = 2;
			goto out;
		}

		header_len = (size_t)(header_end - csv_data->data);
		{
			char	*header_line = zbx_malloc(NULL, header_len + 1);

			memcpy(header_line, csv_data->data, header_len);
			header_line[header_len] = '\0';
			columns = zbx_pg_quote_columns(header_line);
			zbx_free(header_line);
		}

		if (NULL == columns)
		{
			result->error_msg = zbx_dsprintf(NULL, "Out of memory building column list for %s",
					table_name);
			zbx_free(table_name);
			result->exit_code = 6;
			goto out;
		}

		sql = zbx_dsprintf(NULL, "COPY \"%s\" (%s) FROM STDIN WITH (FORMAT csv, NULL 'NULL');",
				table_name, columns);
		fprintf(pipe, "%s\n", sql);
		fflush(pipe);
		zbx_free(sql);
		zbx_free(columns);

		/* Stream the data rows (everything after the header line). */
		data_start = header_end + 1;
		data_len = csv_data->len - (size_t)(data_start - csv_data->data);

		if (0 != data_len && data_len != fwrite(data_start, 1, data_len, pipe))
		{
			result->error_msg = zbx_dsprintf(NULL, "Failed to write CSV data for table %s", table_name);
			zbx_free(table_name);
			result->exit_code = 4;
			goto out;
		}

		/* End the COPY stream (marker must start its own line; CSV data already ends with a newline). */
		fprintf(pipe, "\\.\n\\echo " ZBX_PG_SENTINEL "\n");
		fflush(pipe);

		if (SUCCEED != zbx_pg_drain_to_sentinel(pipe, &line, &line_alloc))
		{
			result->error_msg = zbx_dsprintf(NULL, "COPY failed for table %s", table_name);
			zbx_free(table_name);
			result->exit_code = 5;
			goto out;
		}

		/* Exact row count from the loaded table (CSV line counting is unreliable with quoted newlines). */
		sql = zbx_dsprintf(NULL, "SELECT count(*) FROM \"%s\";", table_name);
		zbx_pg_send(pipe, sql);
		zbx_free(sql);

		if (SUCCEED != zbx_pg_read_value(pipe, &line, &line_alloc, &value))
		{
			result->error_msg = zbx_dsprintf(NULL, "Lost connection after loading table %s", table_name);
			zbx_free(table_name);
			result->exit_code = 5;
			goto out;
		}

		if (NULL != value)
		{
			result->rows_imported += atoi(value);
			zbx_free(value);
		}

		result->tables_imported++;
		zbx_free(table_name);
	}

	/* Rebuild the ids table from the loaded data (only tables that own a record id). */
	zbx_pg_send(pipe, "TRUNCATE TABLE ids;");

	if (SUCCEED != zbx_pg_drain_to_sentinel(pipe, &line, &line_alloc))
	{
		result->exit_code = 5;
		result->error_msg = zbx_strdup(NULL, "Failed to reset ids table");
		goto out;
	}

	for (int t = 0; NULL != tables[t].table; t++)
	{
		char	*sql;

		if (NULL == tables[t].recid || 0 != zbx_is_excluded_table(tables[t].table))
			continue;

		sql = zbx_dsprintf(NULL,
				"INSERT INTO ids (table_name, field_name, nextid) "
				"SELECT '%s', '%s', COALESCE(MAX(\"%s\"), 0) FROM \"%s\";",
				tables[t].table, tables[t].recid, tables[t].recid, tables[t].table);
		zbx_pg_send(pipe, sql);
		zbx_free(sql);

		if (SUCCEED != zbx_pg_drain_to_sentinel(pipe, &line, &line_alloc))
		{
			result->error_msg = zbx_dsprintf(NULL, "Failed to rebuild ids for table %s", tables[t].table);
			result->exit_code = 5;
			goto out;
		}
	}

	/* Re-enable triggers/FK enforcement and commit. */
	zbx_pg_send(pipe, "SET session_replication_role = 'origin';\nCOMMIT;");

	if (SUCCEED != zbx_pg_drain_to_sentinel(pipe, &line, &line_alloc))
	{
		result->exit_code = 5;
		result->error_msg = zbx_strdup(NULL, "Failed to commit import transaction");
		goto out;
	}

	result->status = SUCCEED;
	ret = SUCCEED;
out:
	if (NULL != pipe)
		pclose(pipe);
	zbx_free(line);

	return ret;
}

static int zbx_import(zbx_import_options_t *opts, zbx_import_result_t *result)
{
	zbx_bundle_reader_t	reader;
	char			member_name[256];
	size_t			member_size;
	int			eof = 0;
	char			*manifest_data = NULL;
	size_t			manifest_size = 0;
	char			*checksums_data = NULL;
	zbx_csv_buf_t		**csv_members = NULL;
	char			**csv_names = NULL;
	int			csv_count = 0, csv_alloc = 0;
	int			ret = FAIL;

	if (NULL == opts->input_file || '\0' == *opts->input_file)
	{
		result->exit_code = 1;
		result->error_msg = zbx_strdup(NULL, "--input is required");
		return FAIL;
	}

	result->bundle_filename = zbx_strdup(NULL, opts->input_file);

	if (zbx_bundle_reader_open(&reader, opts->input_file) != SUCCEED)
	{
		result->exit_code = 2;
		result->error_msg = zbx_dsprintf(NULL, "Failed to open input bundle: %s", opts->input_file);
		return FAIL;
	}

	/* Read every bundle member into memory: manifest.json, checksums.sha256, and each table CSV. */
	while (SUCCEED == zbx_bundle_read_next_header(&reader, member_name, sizeof(member_name), &member_size, &eof))
	{
		zbx_csv_buf_t	member_data;
		size_t		read_total = 0, name_len;

		if (eof)
			break;

		if (zbx_csv_buf_init(&member_data, member_size + 1) != SUCCEED)
		{
			result->exit_code = 6;
			result->error_msg = zbx_strdup(NULL, "Failed to allocate member data buffer");
			goto read_fail;
		}

		while (read_total < member_size)
		{
			size_t		got;
			unsigned char	read_buf[4096];
			size_t		want = (member_size - read_total < sizeof(read_buf)) ?
						(member_size - read_total) : sizeof(read_buf);

			if (zbx_bundle_read_member_data(&reader, read_buf, want, &got) != SUCCEED)
			{
				zbx_csv_buf_free(&member_data);
				result->exit_code = 2;
				result->error_msg = zbx_strdup(NULL, "Failed to read bundle member data");
				goto read_fail;
			}

			if (0 == got)
				break;

			if (zbx_csv_buf_append_len(&member_data, (char *)read_buf, got) != SUCCEED)
			{
				zbx_csv_buf_free(&member_data);
				result->exit_code = 6;
				result->error_msg = zbx_strdup(NULL, "Failed to buffer member data");
				goto read_fail;
			}

			read_total += got;
		}

		name_len = strlen(member_name);

		if (0 == strcmp(member_name, "manifest.json"))
		{
			manifest_data = zbx_malloc(NULL, member_data.len + 1);
			memcpy(manifest_data, member_data.data, member_data.len);
			manifest_data[member_data.len] = '\0';
			manifest_size = member_data.len;
			zbx_csv_buf_free(&member_data);
		}
		else if (0 == strcmp(member_name, "checksums.sha256"))
		{
			checksums_data = zbx_strdup(NULL, member_data.data);
			zbx_csv_buf_free(&member_data);
		}
		else if (name_len > 4 && 0 == strcmp(member_name + name_len - 4, ".csv"))
		{
			zbx_csv_buf_t	*csv_copy;

			if (csv_count == csv_alloc)
			{
				csv_alloc = (0 == csv_alloc) ? 32 : csv_alloc * 2;
				csv_members = zbx_realloc(csv_members, sizeof(zbx_csv_buf_t *) * csv_alloc);
				csv_names = zbx_realloc(csv_names, sizeof(char *) * csv_alloc);
			}

			csv_copy = zbx_malloc(NULL, sizeof(zbx_csv_buf_t));
			memcpy(csv_copy, &member_data, sizeof(zbx_csv_buf_t));	/* transfer ownership */
			csv_members[csv_count] = csv_copy;
			csv_names[csv_count] = zbx_strdup(NULL, member_name);
			csv_count++;
		}
		else
		{
			zbx_csv_buf_free(&member_data);
		}
	}

	if (zbx_bundle_reader_close(&reader) != SUCCEED)
	{
		result->exit_code = 5;
		result->error_msg = zbx_strdup(NULL, "Failed to close bundle reader");
		goto cleanup;
	}

	if (NULL == manifest_data)
	{
		result->exit_code = 2;
		result->error_msg = zbx_strdup(NULL, "Bundle missing manifest.json");
		goto cleanup;
	}

	/* Verify integrity before touching the database. */
	if (SUCCEED != zbx_verify_bundle_integrity(checksums_data, manifest_data, manifest_size,
			csv_members, csv_names, csv_count, result))
	{
		goto cleanup;
	}

	if (SUCCEED != zbx_parse_manifest(manifest_data, result))
		goto cleanup;

	if (0 == strcmp(opts->db_type, "postgresql") || 0 == strcmp(opts->db_type, "postgres"))
	{
		if (SUCCEED != zbx_import_postgres(opts, result, csv_members, csv_names, csv_count))
			goto cleanup;
	}
	else if (0 == strcmp(opts->db_type, "mysql"))
	{
		result->exit_code = 6;
		result->error_msg = zbx_strdup(NULL, "MySQL import not yet implemented");
		goto cleanup;
	}
	else
	{
		result->exit_code = 1;
		result->error_msg = zbx_dsprintf(NULL, "Unknown database type: %s", opts->db_type);
		goto cleanup;
	}

	result->status = SUCCEED;
	ret = SUCCEED;
	goto cleanup;
read_fail:
	zbx_bundle_reader_close(&reader);
cleanup:
	zbx_free(manifest_data);
	zbx_free(checksums_data);

	for (int i = 0; i < csv_count; i++)
	{
		zbx_csv_buf_free(csv_members[i]);
		zbx_free(csv_members[i]);
		zbx_free(csv_names[i]);
	}
	zbx_free(csv_members);
	zbx_free(csv_names);

	return ret;
}

static void usage(const char *program)
{
	fprintf(stderr,
		"Usage: %s [OPTIONS]\n"
		"\n"
		"OPTIONS:\n"
		"  --input <file>          Input bundle file (required)\n"
		"  --db-type <type>        Database type (postgresql, mysql)\n"
		"  --db-host <host>        Database host\n"
		"  --db-port <port>        Database port\n"
		"  --db-name <name>        Database name\n"
		"  --db-user <user>        Database user\n"
		"  --db-password <pass>    Database password\n"
		"  --help                  Show this help message\n",
		program);
}

int main(int argc, char *argv[])
{
	zbx_import_options_t	opts;
	zbx_import_result_t	result;

	memset(&opts, 0, sizeof(opts));
	memset(&result, 0, sizeof(result));

	/* Simple argument parsing */
	for (int i = 1; i < argc; i++)
	{
		if (strcmp(argv[i], "--help") == 0 || strcmp(argv[i], "-?") == 0)
		{
			usage(argv[0]);
			return 0;
		}
		else if (strcmp(argv[i], "--input") == 0 && i + 1 < argc)
		{
			opts.input_file = zbx_strdup(opts.input_file, argv[++i]);
		}
		else if (strcmp(argv[i], "--db-type") == 0 && i + 1 < argc)
		{
			opts.db_type = zbx_strdup(opts.db_type, argv[++i]);
		}
		else if (strcmp(argv[i], "--db-host") == 0 && i + 1 < argc)
		{
			opts.db_host = zbx_strdup(opts.db_host, argv[++i]);
		}
		else if (strcmp(argv[i], "--db-port") == 0 && i + 1 < argc)
		{
			opts.db_port = zbx_strdup(opts.db_port, argv[++i]);
		}
		else if (strcmp(argv[i], "--db-name") == 0 && i + 1 < argc)
		{
			opts.db_name = zbx_strdup(opts.db_name, argv[++i]);
		}
		else if (strcmp(argv[i], "--db-user") == 0 && i + 1 < argc)
		{
			opts.db_user = zbx_strdup(opts.db_user, argv[++i]);
		}
		else if (strcmp(argv[i], "--db-password") == 0 && i + 1 < argc)
		{
			opts.db_password = zbx_strdup(opts.db_password, argv[++i]);
		}
	}

	/* Validate required options */
	if (NULL == opts.input_file || NULL == opts.db_type || NULL == opts.db_name || NULL == opts.db_user)
	{
		fprintf(stderr, "Error: --input, --db-type, --db-name, and --db-user are required\n");
		usage(argv[0]);
		return 1;
	}

	/* Perform import */
	if (zbx_import(&opts, &result) != SUCCEED)
	{
		if (NULL != result.error_msg)
		{
			fprintf(stderr, "Error: %s\n", result.error_msg);
			zbx_free(result.error_msg);
		}
		return (0 != result.exit_code) ? result.exit_code : 6;
	}

	/* Output success summary as JSON */
	printf("{\n");
	printf("  \"status\": \"success\",\n");
	printf("  \"command\": \"import\",\n");
	printf("  \"bundle\": \"%s\",\n", result.bundle_filename);
	printf("  \"tables\": %d,\n", result.tables_imported);
	printf("  \"rows\": %d\n", result.rows_imported);
	printf("}\n");

	/* Cleanup */
	zbx_free(opts.input_file);
	zbx_free(opts.db_type);
	zbx_free(opts.db_host);
	zbx_free(opts.db_port);
	zbx_free(opts.db_name);
	zbx_free(opts.db_user);
	zbx_free(opts.db_password);
	zbx_free(result.bundle_filename);
	zbx_free(result.error_msg);

	if (NULL != result.manifest)
	{
		zbx_free(result.manifest->dbversion_mandatory);
		zbx_free(result.manifest);
	}

	return 0;
}
