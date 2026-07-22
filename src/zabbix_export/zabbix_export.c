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
		if (zbx_csv_buf_appendf(manifest_buf, "    \"%s\": {\n", result->table_info[i].table_name) != SUCCEED)
			return FAIL;

		if (zbx_csv_buf_appendf(manifest_buf, "      \"rows\": %d,\n", result->table_info[i].row_count) != SUCCEED)
			return FAIL;

		if (zbx_csv_buf_appendf(manifest_buf, "      \"checksum\": \"%s\"\n", result->table_info[i].checksum) != SUCCEED)
			return FAIL;

		if (i < result->tables_exported - 1)
			if (zbx_csv_buf_appendf(manifest_buf, "    },\n") != SUCCEED)
				return FAIL;
		else
			if (zbx_csv_buf_appendf(manifest_buf, "    }\n") != SUCCEED)
				return FAIL;
	}

	if (zbx_csv_buf_appendf(manifest_buf, "  }\n") != SUCCEED)
		return FAIL;

	if (zbx_csv_buf_appendf(manifest_buf, "}\n") != SUCCEED)
		return FAIL;

	return SUCCEED;
}

/* PostgreSQL export path using COPY TO STDOUT */
static int zbx_export_postgres(zbx_export_options_t *opts, zbx_bundle_writer_t *writer, zbx_export_result_t *result)
{
	FILE		*pipe = NULL;
	char		cmd[4096];
	char		line[8192];
	zbx_csv_buf_t	csv_data;
	int		row_count = 0;
	int		table_idx = 0;

	/* Build psql connection command */
	snprintf(cmd, sizeof(cmd), "psql");

	if (opts->db_host && *opts->db_host)
		snprintf(cmd + strlen(cmd), sizeof(cmd) - strlen(cmd), " -h %s", opts->db_host);

	if (opts->db_port && *opts->db_port)
		snprintf(cmd + strlen(cmd), sizeof(cmd) - strlen(cmd), " -p %s", opts->db_port);

	snprintf(cmd + strlen(cmd), sizeof(cmd) - strlen(cmd), " -U %s -d %s", opts->db_user, opts->db_name);

	if (opts->db_password && *opts->db_password)
		snprintf(cmd + strlen(cmd), sizeof(cmd) - strlen(cmd), " -v PGPASSWORD=%s", opts->db_password);

	snprintf(cmd + strlen(cmd), sizeof(cmd) - strlen(cmd), " --batch --no-align");

	pipe = popen(cmd, "r+");
	if (NULL == pipe)
	{
		result->status = FAIL;
		result->exit_code = 4;
		result->error_msg = zbx_dsprintf(NULL, "Failed to connect to PostgreSQL: %s", strerror(errno));
		return FAIL;
	}

	/* Set up transaction and bytea format */
	fprintf(pipe, "BEGIN TRANSACTION ISOLATION LEVEL REPEATABLE READ;\n");
	fprintf(pipe, "SET bytea_output = 'hex';\n");
	fflush(pipe);

	/* Read dbversion.mandatory */
	fprintf(pipe, "\\set ECHO_HIDDEN on\nSELECT mandatory FROM dbversion LIMIT 1;\n");
	fflush(pipe);

	/* Read response until we get the dbversion value */
	while (NULL != fgets(line, sizeof(line), pipe))
	{
		if (line[0] >= '0' && line[0] <= '9')	/* Looks like a version number */
		{
			/* Remove trailing newline */
			size_t len = strlen(line);
			if (len > 0 && line[len-1] == '\n')
				line[len-1] = '\0';
			result->dbversion_mandatory = zbx_strdup(NULL, line);
			break;
		}
	}

	/* Get schema metadata */
	const zbx_db_table_t *tables = zbx_dbschema_get_tables();
	if (NULL == tables)
	{
		pclose(pipe);
		result->status = FAIL;
		result->exit_code = 6;
		result->error_msg = zbx_strdup(NULL, "Failed to get database schema");
		return FAIL;
	}

	/* Count tables to export */
	int export_table_count = 0;
	for (int t = 0; NULL != tables[t].table; t++)
		if (!zbx_is_excluded_table(tables[t].table))
			export_table_count++;

	/* Allocate table info array */
	result->table_info = malloc(sizeof(zbx_table_info_t) * export_table_count);
	if (NULL == result->table_info)
	{
		pclose(pipe);
		result->status = FAIL;
		result->exit_code = 6;
		result->error_msg = zbx_strdup(NULL, "Failed to allocate table info array");
		return FAIL;
	}
	memset(result->table_info, 0, sizeof(zbx_table_info_t) * export_table_count);

	/* Initialize buffers */
	if (zbx_csv_buf_init(&csv_data, 65536) != SUCCEED)
	{
		zbx_csv_buf_free(&csv_data);
		pclose(pipe);
		result->status = FAIL;
		result->exit_code = 6;
		result->error_msg = zbx_strdup(NULL, "Failed to allocate buffer");
		return FAIL;
	}

	/* Export each table */
	for (int t = 0; NULL != tables[t].table; t++)
	{
		if (zbx_is_excluded_table(tables[t].table))
			continue;

		/* Allocate buffer for this table's CSV data */
		zbx_csv_buf_t table_csv;
		if (zbx_csv_buf_init(&table_csv, 65536) != SUCCEED)
		{
			zbx_csv_buf_free(&csv_data);
			pclose(pipe);
			result->status = FAIL;
			result->exit_code = 6;
			result->error_msg = zbx_strdup(NULL, "Failed to allocate table CSV buffer");
			return FAIL;
		}

		/* Initialize SHA-256 for this table */
		zbx_sha256_t table_sha256;
		zbx_sha256_init(&table_sha256);

		/* Build and write CSV header row with column names */
		for (int f = 0; f < tables[t].field_count; f++)
		{
			if (zbx_csv_write_field(&table_csv, tables[t].fields[f].name, 0) != SUCCEED)
			{
				zbx_csv_buf_free(&table_csv);
				zbx_csv_buf_free(&csv_data);
				pclose(pipe);
				result->status = FAIL;
				result->exit_code = 6;
				result->error_msg = zbx_strdup(NULL, "Failed to write CSV header");
				return FAIL;
			}

			if (f < tables[t].field_count - 1)
				if (zbx_csv_buf_append_len(&table_csv, ",", 1) != SUCCEED)
				{
					zbx_csv_buf_free(&table_csv);
					zbx_csv_buf_free(&csv_data);
					pclose(pipe);
					result->status = FAIL;
					result->exit_code = 6;
					result->error_msg = zbx_strdup(NULL, "Failed to write CSV separator");
					return FAIL;
				}
		}

		if (zbx_csv_buf_append_len(&table_csv, "\n", 1) != SUCCEED)
		{
			zbx_csv_buf_free(&table_csv);
			zbx_csv_buf_free(&csv_data);
			pclose(pipe);
			result->status = FAIL;
			result->exit_code = 6;
			result->error_msg = zbx_strdup(NULL, "Failed to finalize CSV header");
			return FAIL;
		}

		/* Update SHA-256 with header */
		zbx_sha256_process_bytes(&table_sha256, (unsigned char *)table_csv.data, table_csv.len);

		/* Issue COPY command for this table */
		fprintf(pipe, "COPY %s TO STDOUT WITH (FORMAT csv, FORCE_QUOTE *, NULL 'NULL');\n", tables[t].table);
		fflush(pipe);

		/* Read COPY output line by line */
		row_count = 0;
		while (NULL != fgets(line, sizeof(line), pipe))
		{
			if (strcmp(line, "\\.\n") == 0)
				break;	/* COPY end marker */

			if (zbx_csv_buf_append_len(&table_csv, line, strlen(line)) != SUCCEED)
			{
				zbx_csv_buf_free(&table_csv);
				zbx_csv_buf_free(&csv_data);
				pclose(pipe);
				result->status = FAIL;
				result->exit_code = 5;
				result->error_msg = zbx_strdup(NULL, "Failed to buffer table CSV data");
				return FAIL;
			}

			/* Update SHA-256 incrementally */
			zbx_sha256_process_bytes(&table_sha256, (unsigned char *)line, strlen(line));

			row_count++;
		}

		/* Finalize SHA-256 and get hex digest */
		unsigned char sha256_digest[32];
		zbx_sha256_finish(&table_sha256, sha256_digest);
		char sha256_hex[65];
		for (int i = 0; i < 32; i++)
			snprintf(sha256_hex + i*2, 3, "%02x", sha256_digest[i]);
		sha256_hex[64] = '\0';

		/* Store table info for manifest */
		result->table_info[table_idx].table_name = zbx_strdup(NULL, tables[t].table);
		result->table_info[table_idx].row_count = row_count;
		result->table_info[table_idx].checksum = zbx_strdup(NULL, sha256_hex);
		table_idx++;

		/* Write table to bundle */
		char member_name[256];
		snprintf(member_name, sizeof(member_name), "%s.csv", tables[t].table);

		if (zbx_bundle_write_member_begin(writer, member_name, table_csv.len) != SUCCEED)
		{
			zbx_csv_buf_free(&table_csv);
			zbx_csv_buf_free(&csv_data);
			pclose(pipe);
			result->status = FAIL;
			result->exit_code = 5;
			result->error_msg = zbx_strdup(NULL, "Failed to start bundle member");
			return FAIL;
		}

		if (zbx_bundle_write_member_data(writer, table_csv.data, table_csv.len) != SUCCEED)
		{
			zbx_csv_buf_free(&table_csv);
			zbx_csv_buf_free(&csv_data);
			pclose(pipe);
			result->status = FAIL;
			result->exit_code = 5;
			result->error_msg = zbx_strdup(NULL, "Failed to write bundle member data");
			return FAIL;
		}

		if (zbx_bundle_write_member_end(writer) != SUCCEED)
		{
			zbx_csv_buf_free(&table_csv);
			zbx_csv_buf_free(&csv_data);
			pclose(pipe);
			result->status = FAIL;
			result->exit_code = 5;
			result->error_msg = zbx_strdup(NULL, "Failed to finalize bundle member");
			return FAIL;
		}

		zbx_csv_buf_free(&table_csv);
		result->tables_exported++;
		result->rows_exported += row_count;
	}

	/* End transaction */
	fprintf(pipe, "COMMIT;\n");
	pclose(pipe);

	zbx_csv_buf_free(&csv_data);

	/* Set table_info_count to match tables_exported */
	result->table_info_count = result->tables_exported;

	result->status = SUCCEED;
	return SUCCEED;
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
	zbx_sha256_t manifest_sha256;
	zbx_sha256_init(&manifest_sha256);
	zbx_sha256_process_bytes(&manifest_sha256, (unsigned char *)manifest_buf.data, manifest_buf.len);
	unsigned char manifest_digest[32];
	zbx_sha256_finish(&manifest_sha256, manifest_digest);
	char manifest_checksum[65];
	for (int i = 0; i < 32; i++)
		snprintf(manifest_checksum + i*2, 3, "%02x", manifest_digest[i]);
	manifest_checksum[64] = '\0';

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
