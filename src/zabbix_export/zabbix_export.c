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
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <time.h>
#include <unistd.h>
#include <sys/stat.h>
#include <sys/wait.h>

#include "zbxcommon.h"
#include "zbxcsvbundle.h"
#include "zbxdbschema.h"
#include "zbxhash.h"

/* Excluded tables (Appendix B) - history/trends are not exported */
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
	char	*output_file;
} zbx_export_options_t;

typedef struct
{
	char		*table_name;
	int		row_count;
	char		*checksum;		/* SHA-256 hex */
} zbx_table_info_t;

typedef struct
{
	int		status;
	int		exit_code;
	char		*error_msg;
	int		tables_exported;
	int		rows_exported;
	long long	size_bytes;
	char		*bundle_filename;
	zbx_table_info_t	*table_info;
	int		table_info_count;
	char		*dbversion_mandatory;
	char		*export_timestamp;
} zbx_export_result_t;

static int zbx_is_excluded_table(const char *table)
{
	for (int i = 0; excluded_tables[i] != NULL; i++)
		if (strcmp(table, excluded_tables[i]) == 0)
			return 1;
	return 0;
}

static char *zbx_generate_default_filename(void)
{
	time_t now = time(NULL);
	struct tm *tm_info = localtime(&now);
	char filename[256];

	strftime(filename, sizeof(filename), "zabbix-config-%Y%m%d-%H%M%S.tar.gz", tm_info);
	return zbx_strdup(NULL, filename);
}

static char *zbx_generate_iso8601_timestamp(void)
{
	time_t now = time(NULL);
	struct tm *tm_info = gmtime(&now);
	char timestamp[32];

	strftime(timestamp, sizeof(timestamp), "%Y-%m-%dT%H:%M:%SZ", tm_info);
	return zbx_strdup(NULL, timestamp);
}

/* Convert a 32-byte SHA-256 digest to a lowercase hex string (out must hold 65 bytes). */
static void zbx_sha256_to_hex(const unsigned char *digest, char *out)
{
	for (int i = 0; i < ZBX_SHA256_DIGEST_SIZE; i++)
		zbx_snprintf(out + i * 2, 3, "%02x", digest[i]);
	out[ZBX_SHA256_DIGEST_SIZE * 2] = '\0';
}

/* Generate manifest.json content */
static int zbx_generate_manifest(zbx_export_result_t *result, zbx_csv_buf_t *manifest_buf)
{
	if (zbx_csv_buf_appendf(manifest_buf, "{\n") != SUCCEED)
		return FAIL;

	if (zbx_csv_buf_appendf(manifest_buf, "  \"format_version\": 1,\n") != SUCCEED)
		return FAIL;

	if (zbx_csv_buf_appendf(manifest_buf, "  \"zabbix_version\": \"8.0\",\n") != SUCCEED)
		return FAIL;

	if (result->dbversion_mandatory)
		if (zbx_csv_buf_appendf(manifest_buf, "  \"dbversion_mandatory\": \"%s\",\n", result->dbversion_mandatory) != SUCCEED)
			return FAIL;

	if (zbx_csv_buf_appendf(manifest_buf, "  \"created_at\": \"%s\",\n", result->export_timestamp) != SUCCEED)
		return FAIL;

	if (zbx_csv_buf_appendf(manifest_buf, "  \"tables\": {\n") != SUCCEED)
		return FAIL;

	for (int i = 0; i < result->tables_exported; i++)
	{
		const char	*closing;

		if (zbx_csv_buf_appendf(manifest_buf, "    \"%s\": {\n", result->table_info[i].table_name) != SUCCEED)
			return FAIL;

		if (zbx_csv_buf_appendf(manifest_buf, "      \"rows\": %d,\n", result->table_info[i].row_count) != SUCCEED)
			return FAIL;

		if (zbx_csv_buf_appendf(manifest_buf, "      \"checksum\": \"%s\"\n", result->table_info[i].checksum) != SUCCEED)
			return FAIL;

		closing = (i < result->tables_exported - 1) ? "    },\n" : "    }\n";

		if (zbx_csv_buf_appendf(manifest_buf, "%s", closing) != SUCCEED)
			return FAIL;
	}

	if (zbx_csv_buf_appendf(manifest_buf, "  }\n") != SUCCEED)
		return FAIL;

	if (zbx_csv_buf_appendf(manifest_buf, "}\n") != SUCCEED)
		return FAIL;

	return SUCCEED;
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

/* Discard all pipe output up to (and including) the sentinel. Returns FAIL if the pipe closed first. */
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

/* Read output up to the sentinel and return the first non-empty line (newline stripped), or NULL. */
static char *zbx_pg_read_value_to_sentinel(FILE *pipe, char **buf, size_t *buf_alloc)
{
	size_t	len;
	char	*value = NULL;

	while (SUCCEED == zbx_pg_read_line(pipe, buf, buf_alloc, &len))
	{
		if (0 != zbx_pg_line_is_sentinel(*buf, len))
			break;

		if (NULL == value)
		{
			while (0 < len && ('\n' == (*buf)[len - 1] || '\r' == (*buf)[len - 1]))
				(*buf)[--len] = '\0';

			if (0 < len)
				value = zbx_strdup(NULL, *buf);
		}
	}

	return value;
}

/* Set libpq connection environment so the credentials never appear on the psql command line. */
static void zbx_pg_setenv(zbx_export_options_t *opts)
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

/* Spawn a child process wired to two pipes. Returns the child's stdout as a readable FILE*, sets */
/* *to_child to a writable FILE* for the child's stdin and *pid to the child pid. NULL on failure. */
/* (popen() cannot be used here: POSIX only allows "r" or "w", so bidirectional talk needs two pipes.) */
static FILE *zbx_pg_popen2(char *const argv[], FILE **to_child, pid_t *pid)
{
	int	in_pipe[2], out_pipe[2];
	pid_t	child;

	*to_child = NULL;

	if (0 != pipe(in_pipe))
		return NULL;

	if (0 != pipe(out_pipe))
	{
		close(in_pipe[0]);
		close(in_pipe[1]);
		return NULL;
	}

	if (0 > (child = fork()))
	{
		close(in_pipe[0]);
		close(in_pipe[1]);
		close(out_pipe[0]);
		close(out_pipe[1]);
		return NULL;
	}

	if (0 == child)
	{
		dup2(in_pipe[0], STDIN_FILENO);
		dup2(out_pipe[1], STDOUT_FILENO);
		close(in_pipe[0]);
		close(in_pipe[1]);
		close(out_pipe[0]);
		close(out_pipe[1]);
		execvp(argv[0], argv);
		_exit(127);
	}

	close(in_pipe[0]);
	close(out_pipe[1]);

	*to_child = fdopen(in_pipe[1], "w");
	*pid = child;

	return fdopen(out_pipe[0], "r");
}

static void zbx_pg_pclose(FILE *to_child, FILE *from_child, pid_t pid)
{
	if (NULL != to_child)
		fclose(to_child);
	if (NULL != from_child)
		fclose(from_child);
	if (0 < pid)
		waitpid(pid, NULL, 0);
}

/* PostgreSQL export path using COPY TO STDOUT */
static int zbx_export_postgres(zbx_export_options_t *opts, zbx_bundle_writer_t *writer, zbx_export_result_t *result)
{
	FILE		*pin = NULL, *pout = NULL;
	pid_t		pid = -1;
	char		*line = NULL;
	size_t		line_alloc = 0, line_len;
	int		table_idx = 0;
	int		ret = FAIL;
	char		*psql_argv[] = {"psql", "-q", "-X", "-w", NULL};

	/* Connection parameters are passed through the environment (see zbx_pg_setenv), so the command line */
	/* carries no user-controlled data. -q suppresses status noise, -X skips ~/.psqlrc, -w never prompts. */
	zbx_pg_setenv(opts);

	pout = zbx_pg_popen2(psql_argv, &pin, &pid);
	if (NULL == pin || NULL == pout)
	{
		result->status = FAIL;
		result->exit_code = 4;
		result->error_msg = zbx_dsprintf(NULL, "Failed to launch psql: %s", strerror(errno));
		goto out;
	}

	/* Open a consistent snapshot and configure output for the metadata queries below. */
	fprintf(pin,
		"BEGIN TRANSACTION ISOLATION LEVEL REPEATABLE READ;\n"
		"SET bytea_output = 'hex';\n"
		"\\pset tuples_only on\n"
		"\\pset format unaligned\n"
		"\\pset footer off\n"
		"\\echo " ZBX_PG_SENTINEL "\n");
	fflush(pin);

	/* Draining to the sentinel also verifies the connection actually came up. */
	if (SUCCEED != zbx_pg_drain_to_sentinel(pout, &line, &line_alloc))
	{
		result->status = FAIL;
		result->exit_code = 4;
		result->error_msg = zbx_strdup(NULL, "Failed to connect to PostgreSQL");
		goto out;
	}

	/* Read dbversion.mandatory */
	fprintf(pin, "SELECT mandatory FROM dbversion LIMIT 1;\n\\echo " ZBX_PG_SENTINEL "\n");
	fflush(pin);
	result->dbversion_mandatory = zbx_pg_read_value_to_sentinel(pout, &line, &line_alloc);

	/* Get schema metadata */
	const zbx_db_table_t *tables = zbx_dbschema_get_tables();
	if (NULL == tables)
	{
		result->status = FAIL;
		result->exit_code = 6;
		result->error_msg = zbx_strdup(NULL, "Failed to get database schema");
		goto out;
	}

	/* Count tables to export */
	int export_table_count = 0;
	for (int t = 0; NULL != tables[t].table; t++)
		if (!zbx_is_excluded_table(tables[t].table))
			export_table_count++;

	/* Allocate table info array */
	result->table_info = (zbx_table_info_t *)zbx_malloc(NULL, sizeof(zbx_table_info_t) * export_table_count);
	memset(result->table_info, 0, sizeof(zbx_table_info_t) * export_table_count);

	/* Export each table */
	for (int t = 0; NULL != tables[t].table; t++)
	{
		if (zbx_is_excluded_table(tables[t].table))
			continue;

		/* Allocate buffer for this table's CSV data */
		zbx_csv_buf_t table_csv;
		if (zbx_csv_buf_init(&table_csv, 65536) != SUCCEED)
		{
			result->status = FAIL;
			result->exit_code = 6;
			result->error_msg = zbx_strdup(NULL, "Failed to allocate table CSV buffer");
			goto out;
		}

		/* Initialize SHA-256 for this table */
		sha256_ctx table_ctx;
		zbx_sha256_init(&table_ctx);

		/* Build and write CSV header row with column names (COPY output carries no header). */
		int	header_ok = SUCCEED;
		for (int f = 0; SUCCEED == header_ok && NULL != tables[t].fields[f].name; f++)
		{
			if (0 != f && zbx_csv_buf_append_len(&table_csv, ",", 1) != SUCCEED)
				header_ok = FAIL;
			else if (zbx_csv_write_field(&table_csv, tables[t].fields[f].name, 0) != SUCCEED)
				header_ok = FAIL;
		}

		if (SUCCEED != header_ok || zbx_csv_buf_append_len(&table_csv, "\n", 1) != SUCCEED)
		{
			zbx_csv_buf_free(&table_csv);
			result->status = FAIL;
			result->exit_code = 6;
			result->error_msg = zbx_strdup(NULL, "Failed to write CSV header");
			goto out;
		}

		/* Update SHA-256 with header */
		zbx_sha256_process_bytes(table_csv.data, table_csv.len, &table_ctx);

		/* Ask for an exact row count within the snapshot (COPY output cannot be counted reliably */
		/* because a single field may contain embedded newlines). */
		fprintf(pin, "SELECT count(*) FROM \"%s\";\n\\echo " ZBX_PG_SENTINEL "\n", tables[t].table);
		fflush(pin);
		char	*count_str = zbx_pg_read_value_to_sentinel(pout, &line, &line_alloc);
		int	row_count = (NULL != count_str) ? atoi(count_str) : 0;
		zbx_free(count_str);

		/* Issue COPY command for this table and read its output up to the sentinel. */
		fprintf(pin, "COPY \"%s\" TO STDOUT WITH (FORMAT csv, FORCE_QUOTE *, NULL 'NULL');\n\\echo " ZBX_PG_SENTINEL "\n",
			tables[t].table);
		fflush(pin);

		while (SUCCEED == zbx_pg_read_line(pout, &line, &line_alloc, &line_len))
		{
			if (0 != zbx_pg_line_is_sentinel(line, line_len))
				break;

			if (zbx_csv_buf_append_len(&table_csv, line, line_len) != SUCCEED)
			{
				zbx_csv_buf_free(&table_csv);
				result->status = FAIL;
				result->exit_code = 5;
				result->error_msg = zbx_strdup(NULL, "Failed to buffer table CSV data");
				goto out;
			}

			zbx_sha256_process_bytes(line, line_len, &table_ctx);
		}

		/* Finalize SHA-256 and get hex digest */
		unsigned char sha256_digest[ZBX_SHA256_DIGEST_SIZE];
		zbx_sha256_finish(&table_ctx, sha256_digest);
		char sha256_hex[ZBX_SHA256_DIGEST_SIZE * 2 + 1];
		zbx_sha256_to_hex(sha256_digest, sha256_hex);

		/* Store table info for manifest */
		result->table_info[table_idx].table_name = zbx_strdup(NULL, tables[t].table);
		result->table_info[table_idx].row_count = row_count;
		result->table_info[table_idx].checksum = zbx_strdup(NULL, sha256_hex);
		table_idx++;

		/* Write table to bundle */
		char member_name[ZBX_TABLENAME_LEN_MAX + 8];
		zbx_snprintf(member_name, sizeof(member_name), "%s.csv", tables[t].table);

		if (zbx_bundle_write_member_begin(writer, member_name, table_csv.len) != SUCCEED ||
				zbx_bundle_write_member_data(writer, table_csv.data, table_csv.len) != SUCCEED ||
				zbx_bundle_write_member_end(writer) != SUCCEED)
		{
			zbx_csv_buf_free(&table_csv);
			result->status = FAIL;
			result->exit_code = 5;
			result->error_msg = zbx_dsprintf(NULL, "Failed to write bundle member: %s", member_name);
			goto out;
		}

		zbx_csv_buf_free(&table_csv);
		result->tables_exported++;
		result->rows_exported += row_count;
	}

	/* End transaction */
	fprintf(pin, "COMMIT;\n");
	fflush(pin);

	/* Set table_info_count to match tables_exported */
	result->table_info_count = result->tables_exported;

	result->status = SUCCEED;
	ret = SUCCEED;
out:
	zbx_pg_pclose(pin, pout, pid);
	zbx_free(line);

	return ret;
}

static int zbx_export(zbx_export_options_t *opts, zbx_export_result_t *result)
{
	zbx_bundle_writer_t	writer;

	if (NULL == opts->output_file || '\0' == *opts->output_file)
	{
		opts->output_file = zbx_generate_default_filename();
		if (NULL == opts->output_file)
		{
			result->status = FAIL;
			result->exit_code = 5;
			result->error_msg = zbx_strdup(NULL, "Failed to generate default filename");
			return FAIL;
		}
	}

	result->bundle_filename = zbx_strdup(NULL, opts->output_file);

	if (zbx_bundle_writer_open(&writer, opts->output_file) != SUCCEED)
	{
		result->status = FAIL;
		result->exit_code = 5;
		result->error_msg = zbx_dsprintf(NULL, "Failed to open output bundle: %s", opts->output_file);
		return FAIL;
	}

	/* Generate export timestamp */
	result->export_timestamp = zbx_generate_iso8601_timestamp();

	if (strcmp(opts->db_type, "postgresql") == 0 || strcmp(opts->db_type, "postgres") == 0)
	{
		if (zbx_export_postgres(opts, &writer, result) != SUCCEED)
		{
			zbx_bundle_writer_close(&writer);
			return FAIL;
		}
	}
	else if (strcmp(opts->db_type, "mysql") == 0)
	{
		result->status = FAIL;
		result->exit_code = 6;
		result->error_msg = zbx_strdup(NULL, "MySQL export not yet implemented");
		zbx_bundle_writer_close(&writer);
		return FAIL;
	}
	else
	{
		result->status = FAIL;
		result->exit_code = 1;
		result->error_msg = zbx_dsprintf(NULL, "Unknown database type: %s", opts->db_type);
		zbx_bundle_writer_close(&writer);
		return FAIL;
	}

	/* Generate manifest.json */
	zbx_csv_buf_t manifest_buf;
	if (zbx_csv_buf_init(&manifest_buf, 8192) != SUCCEED)
	{
		result->status = FAIL;
		result->exit_code = 5;
		result->error_msg = zbx_strdup(NULL, "Failed to allocate manifest buffer");
		zbx_bundle_writer_close(&writer);
		return FAIL;
	}

	if (zbx_generate_manifest(result, &manifest_buf) != SUCCEED)
	{
		zbx_csv_buf_free(&manifest_buf);
		result->status = FAIL;
		result->exit_code = 5;
		result->error_msg = zbx_strdup(NULL, "Failed to generate manifest.json");
		zbx_bundle_writer_close(&writer);
		return FAIL;
	}

	/* Calculate SHA-256 for manifest.json */
	sha256_ctx manifest_ctx;
	zbx_sha256_init(&manifest_ctx);
	zbx_sha256_process_bytes(manifest_buf.data, manifest_buf.len, &manifest_ctx);
	unsigned char manifest_digest[ZBX_SHA256_DIGEST_SIZE];
	zbx_sha256_finish(&manifest_ctx, manifest_digest);
	char manifest_checksum[ZBX_SHA256_DIGEST_SIZE * 2 + 1];
	zbx_sha256_to_hex(manifest_digest, manifest_checksum);

	/* Write manifest.json to bundle */
	if (zbx_bundle_write_member_begin(&writer, "manifest.json", manifest_buf.len) != SUCCEED)
	{
		zbx_csv_buf_free(&manifest_buf);
		result->status = FAIL;
		result->exit_code = 5;
		result->error_msg = zbx_strdup(NULL, "Failed to write manifest.json header");
		zbx_bundle_writer_close(&writer);
		return FAIL;
	}

	if (zbx_bundle_write_member_data(&writer, manifest_buf.data, manifest_buf.len) != SUCCEED)
	{
		zbx_csv_buf_free(&manifest_buf);
		result->status = FAIL;
		result->exit_code = 5;
		result->error_msg = zbx_strdup(NULL, "Failed to write manifest.json data");
		zbx_bundle_writer_close(&writer);
		return FAIL;
	}

	if (zbx_bundle_write_member_end(&writer) != SUCCEED)
	{
		zbx_csv_buf_free(&manifest_buf);
		result->status = FAIL;
		result->exit_code = 5;
		result->error_msg = zbx_strdup(NULL, "Failed to finalize manifest.json");
		zbx_bundle_writer_close(&writer);
		return FAIL;
	}

	/* Generate checksums.sha256 content */
	zbx_csv_buf_t checksums_buf;
	if (zbx_csv_buf_init(&checksums_buf, 8192) != SUCCEED)
	{
		zbx_csv_buf_free(&manifest_buf);
		result->status = FAIL;
		result->exit_code = 5;
		result->error_msg = zbx_strdup(NULL, "Failed to allocate checksums buffer");
		zbx_bundle_writer_close(&writer);
		return FAIL;
	}

	/* Write table checksums */
	for (int i = 0; i < result->tables_exported; i++)
	{
		if (zbx_csv_buf_appendf(&checksums_buf, "%s  %s.csv\n",
			result->table_info[i].checksum, result->table_info[i].table_name) != SUCCEED)
		{
			zbx_csv_buf_free(&checksums_buf);
			zbx_csv_buf_free(&manifest_buf);
			result->status = FAIL;
			result->exit_code = 5;
			result->error_msg = zbx_strdup(NULL, "Failed to write table checksums");
			zbx_bundle_writer_close(&writer);
			return FAIL;
		}
	}

	/* Write manifest.json checksum */
	if (zbx_csv_buf_appendf(&checksums_buf, "%s  manifest.json\n", manifest_checksum) != SUCCEED)
	{
		zbx_csv_buf_free(&checksums_buf);
		zbx_csv_buf_free(&manifest_buf);
		result->status = FAIL;
		result->exit_code = 5;
		result->error_msg = zbx_strdup(NULL, "Failed to write manifest checksum");
		zbx_bundle_writer_close(&writer);
		return FAIL;
	}

	/* Write checksums.sha256 to bundle */
	if (zbx_bundle_write_member_begin(&writer, "checksums.sha256", checksums_buf.len) != SUCCEED)
	{
		zbx_csv_buf_free(&checksums_buf);
		zbx_csv_buf_free(&manifest_buf);
		result->status = FAIL;
		result->exit_code = 5;
		result->error_msg = zbx_strdup(NULL, "Failed to write checksums.sha256 header");
		zbx_bundle_writer_close(&writer);
		return FAIL;
	}

	if (zbx_bundle_write_member_data(&writer, checksums_buf.data, checksums_buf.len) != SUCCEED)
	{
		zbx_csv_buf_free(&checksums_buf);
		zbx_csv_buf_free(&manifest_buf);
		result->status = FAIL;
		result->exit_code = 5;
		result->error_msg = zbx_strdup(NULL, "Failed to write checksums.sha256 data");
		zbx_bundle_writer_close(&writer);
		return FAIL;
	}

	if (zbx_bundle_write_member_end(&writer) != SUCCEED)
	{
		zbx_csv_buf_free(&checksums_buf);
		zbx_csv_buf_free(&manifest_buf);
		result->status = FAIL;
		result->exit_code = 5;
		result->error_msg = zbx_strdup(NULL, "Failed to finalize checksums.sha256");
		zbx_bundle_writer_close(&writer);
		return FAIL;
	}

	zbx_csv_buf_free(&checksums_buf);
	zbx_csv_buf_free(&manifest_buf);

	if (zbx_bundle_writer_close(&writer) != SUCCEED)
	{
		result->status = FAIL;
		result->exit_code = 5;
		result->error_msg = zbx_strdup(NULL, "Failed to close output bundle");
		return FAIL;
	}

	/* Record the size of the finished bundle for the summary output. */
	struct stat	st;
	if (0 == stat(opts->output_file, &st))
		result->size_bytes = (long long)st.st_size;

	result->status = SUCCEED;
	return SUCCEED;
}

static void usage(const char *program)
{
	fprintf(stderr,
		"Usage: %s [OPTIONS]\n"
		"\n"
		"OPTIONS:\n"
		"  --db-type <type>        Database type (postgresql, mysql)\n"
		"  --db-host <host>        Database host\n"
		"  --db-port <port>        Database port\n"
		"  --db-name <name>        Database name\n"
		"  --db-user <user>        Database user\n"
		"  --db-password <pass>    Database password\n"
		"  --output <file>         Output bundle file\n"
		"  --help                  Show this help message\n",
		program);
}

int main(int argc, char *argv[])
{
	zbx_export_options_t	opts;
	zbx_export_result_t	result;

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
		else if (strcmp(argv[i], "--output") == 0 && i + 1 < argc)
		{
			opts.output_file = zbx_strdup(opts.output_file, argv[++i]);
		}
	}

	/* Validate required options */
	if (NULL == opts.db_type || NULL == opts.db_name || NULL == opts.db_user)
	{
		fprintf(stderr, "Error: --db-type, --db-name, and --db-user are required\n");
		usage(argv[0]);
		return 1;
	}

	/* Perform export */
	if (zbx_export(&opts, &result) != SUCCEED)
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
	printf("  \"command\": \"export\",\n");
	printf("  \"bundle\": \"%s\",\n", result.bundle_filename);
	printf("  \"tables\": %d,\n", result.tables_exported);
	printf("  \"rows\": %d,\n", result.rows_exported);
	printf("  \"size_bytes\": %lld\n", result.size_bytes);
	printf("}\n");

	/* Cleanup */
	zbx_free(opts.db_type);
	zbx_free(opts.db_host);
	zbx_free(opts.db_port);
	zbx_free(opts.db_name);
	zbx_free(opts.db_user);
	zbx_free(opts.db_password);
	zbx_free(opts.output_file);
	zbx_free(result.bundle_filename);
	zbx_free(result.error_msg);
	zbx_free(result.export_timestamp);
	zbx_free(result.dbversion_mandatory);

	/* Free table info array */
	if (NULL != result.table_info)
	{
		for (int i = 0; i < result.table_info_count; i++)
		{
			zbx_free(result.table_info[i].table_name);
			zbx_free(result.table_info[i].checksum);
		}
		zbx_free(result.table_info);
	}

	return 0;
}
