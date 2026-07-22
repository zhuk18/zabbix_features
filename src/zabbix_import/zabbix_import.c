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
	char		*name;
	int		row_count;
	char		*checksum;
	char		*csv_data;		/* CSV content from bundle */
	size_t		csv_size;
} zbx_table_meta_t;

typedef struct
{
	char		*dbversion_mandatory;
	int		table_count;
	zbx_table_meta_t	*tables;
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

/* Verify bundle integrity: checksums.sha256 covers all table CSVs + manifest.json */
static int zbx_verify_bundle_integrity(zbx_bundle_reader_t *reader, zbx_import_result_t *result)
{
	/* TODO: Read all members, compute SHA-256, verify against checksums.sha256 */
	(void)reader;

	/* For now, assume verification passes - will be implemented in detail */
	return SUCCEED;
}

/* Parse manifest.json to extract table metadata and dbversion_mandatory */
static int zbx_parse_manifest(const char *manifest_data, size_t manifest_size, zbx_import_result_t *result)
{
	/* Allocate manifest structure */
	result->manifest = malloc(sizeof(zbx_manifest_t));
	if (NULL == result->manifest)
	{
		result->error_msg = zbx_strdup(NULL, "Failed to allocate manifest structure");
		return FAIL;
	}
	memset(result->manifest, 0, sizeof(zbx_manifest_t));

	/* Simple parsing: look for dbversion_mandatory field */
	const char *dbver_start = strstr(manifest_data, "\"dbversion_mandatory\": \"");
	if (NULL != dbver_start)
	{
		dbver_start += strlen("\"dbversion_mandatory\": \"");
		const char *dbver_end = strchr(dbver_start, '"');
		if (NULL != dbver_end)
		{
			size_t dbver_len = dbver_end - dbver_start;
			result->manifest->dbversion_mandatory = malloc(dbver_len + 1);
			if (NULL != result->manifest->dbversion_mandatory)
			{
				strncpy(result->manifest->dbversion_mandatory, dbver_start, dbver_len);
				result->manifest->dbversion_mandatory[dbver_len] = '\0';
			}
		}
	}

	/* Count tables in manifest (simple heuristic: count .csv entries) */
	int table_count = 0;
	const char *pos = manifest_data;
	while (NULL != (pos = strstr(pos, ".csv\"")))
	{
		table_count++;
		pos += 5;
	}

	/* Allocate table array if we found any */
	if (table_count > 0)
	{
		result->manifest->tables = malloc(sizeof(zbx_table_meta_t) * table_count);
		if (NULL == result->manifest->tables)
		{
			result->error_msg = zbx_strdup(NULL, "Failed to allocate table metadata array");
			return FAIL;
		}
		memset(result->manifest->tables, 0, sizeof(zbx_table_meta_t) * table_count);
		result->manifest->table_count = table_count;
	}

	return SUCCEED;
}

static int zbx_import_postgres(zbx_import_options_t *opts, zbx_import_result_t *result,
	zbx_manifest_t *manifest, zbx_csv_buf_t **csv_members, char **csv_names, int csv_count)
{
	FILE		*pipe = NULL;
	char		cmd[4096];

	/* Build psql connection command */
	snprintf(cmd, sizeof(cmd), "psql");

	if (opts->db_host && *opts->db_host)
		snprintf(cmd + strlen(cmd), sizeof(cmd) - strlen(cmd), " -h %s", opts->db_host);

	if (opts->db_port && *opts->db_port)
		snprintf(cmd + strlen(cmd), sizeof(cmd) - strlen(cmd), " -p %s", opts->db_port);

	snprintf(cmd + strlen(cmd), sizeof(cmd) - strlen(cmd), " -U %s -d %s", opts->db_user, opts->db_name);

	if (opts->db_password && *opts->db_password)
		snprintf(cmd + strlen(cmd), sizeof(cmd) - strlen(cmd), " -v PGPASSWORD=%s", opts->db_password);

	snprintf(cmd + strlen(cmd), sizeof(cmd) - strlen(cmd), " --batch");

	pipe = popen(cmd, "r+");
	if (NULL == pipe)
	{
		result->status = FAIL;
		result->exit_code = 4;
		result->error_msg = zbx_dsprintf(NULL, "Failed to connect to PostgreSQL: %s", strerror(errno));
		return FAIL;
	}

	/* Check preconditions: verify dbversion matches manifest */
	if (NULL != result->manifest && NULL != result->manifest->dbversion_mandatory)
	{
		fprintf(pipe, "SELECT COUNT(*) FROM dbversion WHERE mandatory = '%s';\n", result->manifest->dbversion_mandatory);
		fflush(pipe);

		char line[1024];
		int dbver_matches = 0;
		while (NULL != fgets(line, sizeof(line), pipe))
		{
			if (line[0] >= '0' && line[0] <= '9')
			{
				/* If we get "1" the version matches */
				if (line[0] == '1')
					dbver_matches = 1;
				break;
			}
		}

		if (!dbver_matches)
		{
			pclose(pipe);
			result->status = FAIL;
			result->exit_code = 3;
			result->error_msg = zbx_strdup(NULL, "Target database schema version does not match bundle");
			return FAIL;
		}
	}

	/* Check precondition: verify target database is empty */
	const zbx_db_table_t *tables = zbx_dbschema_get_tables();
	if (NULL == tables)
	{
		pclose(pipe);
		result->status = FAIL;
		result->exit_code = 6;
		result->error_msg = zbx_strdup(NULL, "Failed to get database schema");
		return FAIL;
	}

	for (int t = 0; NULL != tables[t].table; t++)
	{
		fprintf(pipe, "SELECT 1 FROM %s LIMIT 1;\n", tables[t].table);
		fflush(pipe);

		char line[256];
		int has_rows = 0;
		while (NULL != fgets(line, sizeof(line), pipe))
		{
			if (line[0] == '1')
			{
				has_rows = 1;
				break;
			}
			if (strcmp(line, "(0 rows)\n") == 0)
				break;
		}

		if (has_rows)
		{
			pclose(pipe);
			result->status = FAIL;
			result->exit_code = 3;
			result->error_msg = zbx_dsprintf(NULL, "Target database is not empty: table %s has data", tables[t].table);
			return FAIL;
		}
	}

	/* Start transaction and disable FK checks */
	fprintf(pipe, "BEGIN TRANSACTION;\n");
	fprintf(pipe, "SET session_replication_role = 'replica';\n");
	fflush(pipe);

	/* Load CSV data for each table via COPY FROM STDIN */
	for (int csv_idx = 0; csv_idx < csv_count; csv_idx++)
	{
		/* Extract table name from CSV member name (e.g. "hosts.csv" -> "hosts") */
		char table_name[256];
		size_t name_len = strlen(csv_names[csv_idx]);
		if (name_len > 4 && strcmp(csv_names[csv_idx] + name_len - 4, ".csv") == 0)
		{
			strncpy(table_name, csv_names[csv_idx], name_len - 4);
			table_name[name_len - 4] = '\0';
		}
		else
		{
			continue;	/* Skip non-CSV members */
		}

		zbx_csv_buf_t *csv_data = csv_members[csv_idx];

		/* Extract column names from CSV header (first line) */
		const char *header_end = strchr(csv_data->data, '\n');
		if (NULL == header_end)
		{
			pclose(pipe);
			result->status = FAIL;
			result->exit_code = 2;
			result->error_msg = zbx_dsprintf(NULL, "Invalid CSV for table %s: missing header", table_name);
			return FAIL;
		}

		size_t header_len = header_end - csv_data->data;
		char header_line[4096];
		strncpy(header_line, csv_data->data, header_len);
		header_line[header_len] = '\0';

		/* Issue COPY command with column list from header */
		fprintf(pipe, "COPY %s (%s) FROM STDIN WITH (FORMAT csv, NULL 'NULL');\n", table_name, header_line);
		fflush(pipe);

		/* Write CSV data (skip header, just write data rows) */
		const char *data_start = header_end + 1;
		size_t data_len = csv_data->len - (data_start - csv_data->data);

		if (fwrite(data_start, 1, data_len, pipe) != data_len)
		{
			pclose(pipe);
			result->status = FAIL;
			result->exit_code = 4;
			result->error_msg = zbx_dsprintf(NULL, "Failed to write CSV data for table %s", table_name);
			return FAIL;
		}

		/* Signal end of COPY data */
		fprintf(pipe, "\\.\n");
		fflush(pipe);

		/* Count rows in CSV data (lines after header) */
		int row_count = 0;
		const char *line_start = data_start;
		while (line_start < csv_data->data + csv_data->len)
		{
			const char *line_end = strchr(line_start, '\n');
			if (NULL == line_end)
				break;

			/* Skip empty lines */
			if (line_end > line_start)
				row_count++;

			line_start = line_end + 1;
		}

		result->rows_imported += row_count;

		/* Read COPY response */
		char response[256];
		while (NULL != fgets(response, sizeof(response), pipe))
		{
			if (strstr(response, "COPY"))
				break;	/* Got the COPY response */
		}
	}

	/* Recreate ids table entries */
	fprintf(pipe, "TRUNCATE TABLE ids;\n");
	for (int t = 0; NULL != tables[t].table; t++)
	{
		/* Get recid field name from schema */
		char recid_name[128] = "id";  /* default */
		if (NULL != tables[t].recid)
			snprintf(recid_name, sizeof(recid_name), "%s", tables[t].recid);

		fprintf(pipe, "INSERT INTO ids (table_name, field_name, nextid) "
			"SELECT '%s', '%s', COALESCE(MAX(%s), 0) FROM %s;\n",
			tables[t].table, recid_name, recid_name, tables[t].table);
	}
	fflush(pipe);

	/* Re-enable FK checks and commit */
	fprintf(pipe, "SET session_replication_role = 'origin';\n");
	fprintf(pipe, "COMMIT;\n");
	fflush(pipe);

	pclose(pipe);

	result->status = SUCCEED;
	return SUCCEED;
}

static int zbx_import(zbx_import_options_t *opts, zbx_import_result_t *result)
{
	zbx_bundle_reader_t	reader;
	char		member_name[256];
	size_t		member_size;
	int		eof = 0;
	zbx_csv_buf_t	member_buf;

	if (NULL == opts->input_file || '\0' == *opts->input_file)
	{
		result->status = FAIL;
		result->exit_code = 1;
		result->error_msg = zbx_strdup(NULL, "--input is required");
		return FAIL;
	}

	result->bundle_filename = zbx_strdup(NULL, opts->input_file);

	/* Open bundle for reading */
	if (zbx_bundle_reader_open(&reader, opts->input_file) != SUCCEED)
	{
		result->status = FAIL;
		result->exit_code = 2;
		result->error_msg = zbx_dsprintf(NULL, "Failed to open input bundle: %s", opts->input_file);
		return FAIL;
	}

	/* Initialize buffer for reading members */
	if (zbx_csv_buf_init(&member_buf, 65536) != SUCCEED)
	{
		zbx_bundle_reader_close(&reader);
		result->status = FAIL;
		result->exit_code = 6;
		result->error_msg = zbx_strdup(NULL, "Failed to allocate member buffer");
		return FAIL;
	}

	/* Read bundle members: collect manifest.json, checksums.sha256, and CSV data */
	char *manifest_data = NULL;
	size_t manifest_size = 0;
	char *checksums_data = NULL;
	size_t checksums_size = 0;
	zbx_csv_buf_t **csv_members = NULL;
	char **csv_names = NULL;
	int csv_count = 0;

	while (zbx_bundle_read_next_header(&reader, member_name, sizeof(member_name), &member_size, &eof) == SUCCEED)
	{
		if (eof)
			break;

		zbx_csv_buf_t member_data;
		if (zbx_csv_buf_init(&member_data, member_size + 1) != SUCCEED)
		{
			zbx_csv_buf_free(&member_buf);
			zbx_bundle_reader_close(&reader);
			result->status = FAIL;
			result->exit_code = 6;
			result->error_msg = zbx_strdup(NULL, "Failed to allocate member data buffer");
			return FAIL;
		}

		/* Read member data */
		size_t read_total = 0;
		while (read_total < member_size)
		{
			size_t got;
			unsigned char read_buf[4096];
			if (zbx_bundle_read_member_data(&reader, read_buf,
				(member_size - read_total < sizeof(read_buf)) ? (member_size - read_total) : sizeof(read_buf),
				&got) != SUCCEED)
			{
				zbx_csv_buf_free(&member_data);
				zbx_csv_buf_free(&member_buf);
				zbx_bundle_reader_close(&reader);
				result->status = FAIL;
				result->exit_code = 2;
				result->error_msg = zbx_strdup(NULL, "Failed to read bundle member data");
				return FAIL;
			}
			if (got == 0)
				break;

			if (zbx_csv_buf_append_len(&member_data, (char *)read_buf, got) != SUCCEED)
			{
				zbx_csv_buf_free(&member_data);
				zbx_csv_buf_free(&member_buf);
				zbx_bundle_reader_close(&reader);
				result->status = FAIL;
				result->exit_code = 6;
				result->error_msg = zbx_strdup(NULL, "Failed to buffer member data");
				return FAIL;
			}

			read_total += got;
		}

		/* Store special members */
		if (strcmp(member_name, "manifest.json") == 0)
		{
			manifest_data = zbx_strdup(NULL, member_data.data);
			manifest_size = member_data.len;
		}
		else if (strcmp(member_name, "checksums.sha256") == 0)
		{
			checksums_data = zbx_strdup(NULL, member_data.data);
			checksums_size = member_data.len;
		}
		/* Table CSV members: store data for later loading */
		else if (strlen(member_name) > 4 && strcmp(member_name + strlen(member_name) - 4, ".csv") == 0)
		{
			/* Allocate array if needed */
			if (csv_count == 0)
			{
				csv_members = malloc(sizeof(zbx_csv_buf_t *) * 256);	/* reasonable max */
				csv_names = malloc(sizeof(char *) * 256);
				if (NULL == csv_members || NULL == csv_names)
				{
					zbx_csv_buf_free(&member_data);
					zbx_csv_buf_free(&member_buf);
					zbx_bundle_reader_close(&reader);
					result->status = FAIL;
					result->exit_code = 6;
					result->error_msg = zbx_strdup(NULL, "Failed to allocate CSV member arrays");
					return FAIL;
				}
			}

			/* Store CSV member */
			zbx_csv_buf_t *csv_copy = malloc(sizeof(zbx_csv_buf_t));
			if (NULL == csv_copy)
			{
				zbx_csv_buf_free(&member_data);
				zbx_csv_buf_free(&member_buf);
				zbx_bundle_reader_close(&reader);
				result->status = FAIL;
				result->exit_code = 6;
				result->error_msg = zbx_strdup(NULL, "Failed to allocate CSV member copy");
				return FAIL;
			}

			memcpy(csv_copy, &member_data, sizeof(zbx_csv_buf_t));
			csv_members[csv_count] = csv_copy;
			csv_names[csv_count] = zbx_strdup(NULL, member_name);
			csv_count++;
			result->tables_imported++;

			/* Don't free member_data since we've transferred ownership */
			continue;
		}

		zbx_csv_buf_free(&member_data);
	}

	zbx_csv_buf_free(&member_buf);

	if (zbx_bundle_reader_close(&reader) != SUCCEED)
	{
		result->status = FAIL;
		result->exit_code = 5;
		result->error_msg = zbx_strdup(NULL, "Failed to close bundle reader");
		zbx_free(manifest_data);
		zbx_free(checksums_data);
		return FAIL;
	}

	/* Parse manifest.json */
	if (NULL == manifest_data)
	{
		result->status = FAIL;
		result->exit_code = 2;
		result->error_msg = zbx_strdup(NULL, "Bundle missing manifest.json");
		zbx_free(checksums_data);
		return FAIL;
	}

	if (zbx_parse_manifest(manifest_data, manifest_size, result) != SUCCEED)
	{
		zbx_free(manifest_data);
		zbx_free(checksums_data);
		return FAIL;
	}

	zbx_free(manifest_data);
	zbx_free(checksums_data);

	/* Perform database-specific import */
	if (strcmp(opts->db_type, "postgresql") == 0 || strcmp(opts->db_type, "postgres") == 0)
	{
		if (zbx_import_postgres(opts, result, result->manifest, csv_members, csv_names, csv_count) != SUCCEED)
			return FAIL;
	}

	/* Cleanup CSV members */
	for (int i = 0; i < csv_count; i++)
	{
		zbx_csv_buf_free(csv_members[i]);
		zbx_free(csv_members[i]);
		zbx_free(csv_names[i]);
	}
	zbx_free(csv_members);
	zbx_free(csv_names);
	else if (strcmp(opts->db_type, "mysql") == 0)
	{
		result->status = FAIL;
		result->exit_code = 6;
		result->error_msg = zbx_strdup(NULL, "MySQL import not yet implemented");
		return FAIL;
	}
	else
	{
		result->status = FAIL;
		result->exit_code = 1;
		result->error_msg = zbx_dsprintf(NULL, "Unknown database type: %s", opts->db_type);
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

	/* Free manifest structure */
	if (NULL != result.manifest)
	{
		zbx_free(result.manifest->dbversion_mandatory);
		if (NULL != result.manifest->tables)
		{
			for (int i = 0; i < result.manifest->table_count; i++)
			{
				zbx_free(result.manifest->tables[i].name);
				zbx_free(result.manifest->tables[i].checksum);
				zbx_free(result.manifest->tables[i].csv_data);
			}
			zbx_free(result.manifest->tables);
		}
		zbx_free(result.manifest);
	}

	return 0;
}
