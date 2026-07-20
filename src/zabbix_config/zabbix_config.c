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
#include <sys/wait.h>
#include <time.h>
#include <unistd.h>

#ifndef PATH_MAX
#define PATH_MAX 4096
#endif

#ifndef SUCCEED
#define SUCCEED 0
#endif

#ifndef FAIL
#define FAIL -1
#endif

#define ZBX_CONFIG_EXCHANGE_SCHEMA_CURRENT	1

#define ZBX_CONFIG_RC_USAGE		1
#define ZBX_CONFIG_RC_NOT_IMPLEMENTED	2
#define ZBX_CONFIG_RC_IO		3
#define ZBX_CONFIG_RC_VALIDATION	4

typedef enum
{
	ZBX_CONFIG_PROGRESS_HUMAN,
	ZBX_CONFIG_PROGRESS_JSON
}	zbx_config_progress_t;

typedef struct
{
	const char	*output;
	const char	*db_host;
	const char	*db_port;
	const char	*db_name;
	const char	*db_user;
	const char	*db_pass;
	const char	*producer_db;
	const char	*producer_version;
	int		exchange_schema;
	unsigned int	object_mask;
	int		object_selected;
}	zbx_config_export_opts_t;

typedef struct
{
	const char	*bundle;
	const char	*db_host;
	const char	*db_port;
	const char	*db_name;
	const char	*db_user;
	const char	*db_pass;
	const char	*cloud_version;
}	zbx_config_import_opts_t;

typedef struct
{
	char	*data;
	size_t	len;
	size_t	alloc;
}	zbx_config_buf_t;

typedef struct
{
	const char	*name;
	unsigned int	bit;
}	zbx_config_object_t;

#define ZBX_OBJ_TEMPLATE_GROUPS		(1U << 0)
#define ZBX_OBJ_HOST_GROUPS		(1U << 1)
#define ZBX_OBJ_TEMPLATES		(1U << 2)
#define ZBX_OBJ_HOSTS			(1U << 3)
#define ZBX_OBJ_ITEMS			(1U << 4)
#define ZBX_OBJ_TRIGGERS		(1U << 5)
#define ZBX_OBJ_DISCOVERY_RULES		(1U << 6)
#define ZBX_OBJ_ITEM_PROTOTYPES		(1U << 7)
#define ZBX_OBJ_TRIGGER_PROTOTYPES	(1U << 8)
#define ZBX_OBJ_VALUE_MAPS		(1U << 9)

#define ZBX_OBJ_ALL_MASK (ZBX_OBJ_TEMPLATE_GROUPS | ZBX_OBJ_HOST_GROUPS | ZBX_OBJ_TEMPLATES | ZBX_OBJ_HOSTS | \
		ZBX_OBJ_ITEMS | ZBX_OBJ_TRIGGERS | ZBX_OBJ_DISCOVERY_RULES | ZBX_OBJ_ITEM_PROTOTYPES | \
		ZBX_OBJ_TRIGGER_PROTOTYPES | ZBX_OBJ_VALUE_MAPS)

#define ZBX_OBJ_SUPPORTED_MASK (ZBX_OBJ_TEMPLATE_GROUPS | ZBX_OBJ_HOST_GROUPS | ZBX_OBJ_TEMPLATES | \
		ZBX_OBJ_HOSTS | ZBX_OBJ_ITEMS | ZBX_OBJ_TRIGGERS)

static const zbx_config_object_t	zbx_config_objects[] =
{
	{"template_groups", ZBX_OBJ_TEMPLATE_GROUPS},
	{"host_groups", ZBX_OBJ_HOST_GROUPS},
	{"templates", ZBX_OBJ_TEMPLATES},
	{"hosts", ZBX_OBJ_HOSTS},
	{"items", ZBX_OBJ_ITEMS},
	{"triggers", ZBX_OBJ_TRIGGERS},
	{"discovery_rules", ZBX_OBJ_DISCOVERY_RULES},
	{"item_prototypes", ZBX_OBJ_ITEM_PROTOTYPES},
	{"trigger_prototypes", ZBX_OBJ_TRIGGER_PROTOTYPES},
	{"value_maps", ZBX_OBJ_VALUE_MAPS}
};

static int	zbx_config_emit_progress(zbx_config_progress_t progress_format, const char *operation,
		const char *phase, const char *status, int progress);

static int	zbx_config_run_stub(const char *command, zbx_config_progress_t progress_format);

static int	zbx_config_run_validate(int argc, char **argv, int argi, zbx_config_progress_t progress_format);

static int	zbx_config_run_import(int argc, char **argv, int argi, zbx_config_progress_t progress_format);

static int	zbx_config_buf_append_json_escaped(zbx_config_buf_t *buf, const char *value);

static int	zbx_config_buf_append_shell_quoted(zbx_config_buf_t *buf, const char *value);

static int	zbx_config_buf_append_sql_quoted(zbx_config_buf_t *buf, const char *value);

static int	zbx_config_build_group_array(const zbx_config_export_opts_t *opts, int group_type,
		zbx_config_buf_t *array_json, size_t *rows_num);

static int	zbx_config_build_host_object_array(const zbx_config_export_opts_t *opts, int templates,
		zbx_config_buf_t *array_json, size_t *rows_num);

static int	zbx_config_build_item_array(const zbx_config_export_opts_t *opts, zbx_config_buf_t *array_json,
		size_t *rows_num);

static int	zbx_config_build_trigger_array(const zbx_config_export_opts_t *opts, zbx_config_buf_t *array_json,
		size_t *rows_num);

static char	*zbx_config_next_field(char **cursor, char delim);

static int	zbx_config_buf_append_uuid_csv_array(zbx_config_buf_t *buf, const char *csv);

static int	zbx_config_run_count_query(const zbx_config_export_opts_t *opts, const char *pgsql_sql,
		const char *mysql_sql, long *count_out);

static int	zbx_config_validate_export_selection(const zbx_config_export_opts_t *opts);

static int	zbx_config_validate_host_group_refs(const zbx_config_export_opts_t *opts, int templates);

static int	zbx_config_validate_item_refs(const zbx_config_export_opts_t *opts);

static int	zbx_config_validate_trigger_refs(const zbx_config_export_opts_t *opts);

static int	zbx_config_parse_import_opts(int argc, char **argv, int argi, zbx_config_import_opts_t *opts);

static int	zbx_config_read_text_file(const char *path, zbx_config_buf_t *buf);

static int	zbx_config_parse_json_int(const char *json, const char *key, int *value_out);

static int	zbx_config_parse_json_string_token(const char *json, const char *key, char *out, size_t out_size);

static int	zbx_config_parse_object_order(const char *json, const zbx_config_object_t **objects_out,
		size_t max_objects, size_t *object_count_out, unsigned int *object_mask_out);

static int	zbx_config_validate_package_text(const char *json, unsigned int *object_mask_out);

static const char	*zbx_config_find_object_array(const char *json, const char *name);

static int	zbx_config_object_array_is_empty(const char *json, const char *name, int *empty_out);

static int	zbx_config_next_array_object(const char **cursor, zbx_config_buf_t *object_json, int *done);

static int	zbx_config_run_db_command(const zbx_config_import_opts_t *opts, const char *db_engine,
		const char *sql);

static int	zbx_config_detect_target_db(const zbx_config_import_opts_t *opts, char *db_engine_out,
		size_t db_engine_out_size);

static int	zbx_config_apply_group_array(const char *json, const char *name, int group_type,
		const zbx_config_import_opts_t *opts, const char *db_engine);

static int	zbx_config_append_host_group_insert_statements(zbx_config_buf_t *sql,
		const char *object_json, const char *host, int group_type, int templates);

static int	zbx_config_apply_template_array(const char *json, const char *name,
		const zbx_config_import_opts_t *opts, const char *db_engine);

static int	zbx_config_apply_host_array(const char *json, const char *name,
		const zbx_config_import_opts_t *opts, const char *db_engine);

static int	zbx_config_apply_item_array(const char *json, const char *name,
		const zbx_config_import_opts_t *opts, const char *db_engine);

static int	zbx_config_lookup_itemid(const zbx_config_import_opts_t *opts, const char *db_engine,
		const char *item_uuid, long *itemid_out);

static int	zbx_config_apply_trigger_array(const char *json, const char *name,
		const zbx_config_import_opts_t *opts, const char *db_engine);

static int	zbx_config_run_import_apply(const char *json, const zbx_config_import_opts_t *opts,
		zbx_config_progress_t progress_format);

static void	zbx_config_print_usage(const char *prog)
{
	fprintf(stderr,
		"Usage:\n"
		"  %s [--progress-format human|json] <command> [command arguments]\n\n"
		"Commands:\n"
		"  export      Export configuration package\n"
		"  validate    Validate configuration package\n"
		"  upgrade     Upgrade configuration package schema\n"
		"  import      Import configuration package\n\n"
		"Export command:\n"
		"  %s export --output <file> --db-host <host> --db-port <port> --db-name <name>\n"
		"      --db-user <user> --db-pass <pass> --producer-db <mysql|postgresql>\n"
		"      [--producer-version <version>] [--exchange-schema <number>]\n"
		"      [--object <name>]... (supported: template_groups, host_groups, templates,\n"
		"      hosts, items, triggers)\n\n"
		"Validate command:\n"
		"  %s validate --bundle <file>\n\n"
		"Import command:\n"
		"  %s import --bundle <file> [--db-host <host> --db-port <port> --db-name <name>]\n"
		"      [--db-user <user> --db-pass <pass>] [--cloud-version <version>]\n",
		prog,
		prog,
		prog,
		prog);
}

static int	zbx_config_buf_init(zbx_config_buf_t *buf, size_t initial)
{
	buf->data = (char *)malloc(initial);

	if (NULL == buf->data)
		return FAIL;

	buf->len = 0;
	buf->alloc = initial;
	buf->data[0] = '\0';

	return SUCCEED;
}

static int	zbx_config_buf_grow(zbx_config_buf_t *buf, size_t required)
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

static int	zbx_config_buf_appendf(zbx_config_buf_t *buf, const char *fmt, ...)
{
	va_list	args;
	int	needed;

	va_start(args, fmt);
	needed = vsnprintf(NULL, 0, fmt, args);
	va_end(args);

	if (0 > needed)
		return FAIL;

	if (SUCCEED != zbx_config_buf_grow(buf, buf->len + (size_t)needed + 1))
		return FAIL;

	va_start(args, fmt);
	(void)vsnprintf(buf->data + buf->len, buf->alloc - buf->len, fmt, args);
	va_end(args);

	buf->len += (size_t)needed;

	return SUCCEED;
}

static void	zbx_config_buf_free(zbx_config_buf_t *buf)
{
	free(buf->data);
	buf->data = NULL;
	buf->len = 0;
	buf->alloc = 0;
}

static int	zbx_config_buf_append_json_escaped(zbx_config_buf_t *buf, const char *value)
{
	const unsigned char	*p;

	if (SUCCEED != zbx_config_buf_appendf(buf, "\""))
		return FAIL;

	for (p = (const unsigned char *)value; '\0' != *p; p++)
	{
		switch (*p)
		{
			case '"':
				if (SUCCEED != zbx_config_buf_appendf(buf, "\\\""))
					return FAIL;
				break;
			case '\\':
				if (SUCCEED != zbx_config_buf_appendf(buf, "\\\\"))
					return FAIL;
				break;
			case '\b':
				if (SUCCEED != zbx_config_buf_appendf(buf, "\\b"))
					return FAIL;
				break;
			case '\f':
				if (SUCCEED != zbx_config_buf_appendf(buf, "\\f"))
					return FAIL;
				break;
			case '\n':
				if (SUCCEED != zbx_config_buf_appendf(buf, "\\n"))
					return FAIL;
				break;
			case '\r':
				if (SUCCEED != zbx_config_buf_appendf(buf, "\\r"))
					return FAIL;
				break;
			case '\t':
				if (SUCCEED != zbx_config_buf_appendf(buf, "\\t"))
					return FAIL;
				break;
			default:
				if (*p < 0x20)
				{
					if (SUCCEED != zbx_config_buf_appendf(buf, "\\u%04x", *p))
						return FAIL;
				}
				else
				{
					if (SUCCEED != zbx_config_buf_appendf(buf, "%c", *p))
						return FAIL;
				}
		}
	}

	if (SUCCEED != zbx_config_buf_appendf(buf, "\""))
		return FAIL;

	return SUCCEED;
}

static int	zbx_config_buf_append_shell_quoted(zbx_config_buf_t *buf, const char *value)
{
	const unsigned char	*p;

	if (SUCCEED != zbx_config_buf_appendf(buf, "'"))
		return FAIL;

	for (p = (const unsigned char *)value; '\0' != *p; p++)
	{
		if ('\'' == *p)
		{
			if (SUCCEED != zbx_config_buf_appendf(buf, "'\\''"))
				return FAIL;
		}
		else
		{
			if (SUCCEED != zbx_config_buf_appendf(buf, "%c", *p))
				return FAIL;
		}
	}

	if (SUCCEED != zbx_config_buf_appendf(buf, "'"))
		return FAIL;

	return SUCCEED;
}

static int	zbx_config_buf_append_sql_quoted(zbx_config_buf_t *buf, const char *value)
{
	const unsigned char	*p;

	if (SUCCEED != zbx_config_buf_appendf(buf, "'"))
		return FAIL;

	for (p = (const unsigned char *)value; '\0' != *p; p++)
	{
		if ('\'' == *p)
		{
			if (SUCCEED != zbx_config_buf_appendf(buf, "''"))
				return FAIL;
		}
		else
		{
			if (SUCCEED != zbx_config_buf_appendf(buf, "%c", *p))
				return FAIL;
		}
	}

	if (SUCCEED != zbx_config_buf_appendf(buf, "'"))
		return FAIL;

	return SUCCEED;
}

static int	zbx_config_build_group_array(const zbx_config_export_opts_t *opts, int group_type,
		zbx_config_buf_t *array_json, size_t *rows_num)
{
	zbx_config_buf_t	cmd;
	FILE			*fp = NULL;
	char			line[8192];
	int			comma = 0;
	int			status;
	char			*delim;

	memset(&cmd, 0, sizeof(cmd));
	memset(array_json, 0, sizeof(*array_json));
	*rows_num = 0;

	if (SUCCEED != zbx_config_buf_init(array_json, 512) || SUCCEED != zbx_config_buf_init(&cmd, 512))
		goto fail;

	if (0 == strcmp(opts->producer_db, "postgresql"))
	{
		if (SUCCEED != zbx_config_buf_appendf(&cmd, "PGPASSWORD=") ||
				SUCCEED != zbx_config_buf_append_shell_quoted(&cmd, opts->db_pass) ||
				SUCCEED != zbx_config_buf_appendf(&cmd,
				" psql -h ") ||
				SUCCEED != zbx_config_buf_append_shell_quoted(&cmd, opts->db_host) ||
				SUCCEED != zbx_config_buf_appendf(&cmd, " -p ") ||
				SUCCEED != zbx_config_buf_append_shell_quoted(&cmd, opts->db_port) ||
				SUCCEED != zbx_config_buf_appendf(&cmd, " -U ") ||
				SUCCEED != zbx_config_buf_append_shell_quoted(&cmd, opts->db_user) ||
				SUCCEED != zbx_config_buf_appendf(&cmd, " -d ") ||
				SUCCEED != zbx_config_buf_append_shell_quoted(&cmd, opts->db_name) ||
				SUCCEED != zbx_config_buf_appendf(&cmd, " -At -F '|' -c ") ||
				SUCCEED != zbx_config_buf_append_shell_quoted(&cmd,
						(1 == group_type
							? "select uuid,name from hstgrp where type=1 order by name"
							: "select uuid,name from hstgrp where type=0 order by name")))
		{
			goto fail;
		}
	}
	else
	{
		if (SUCCEED != zbx_config_buf_appendf(&cmd, "MYSQL_PWD=") ||
				SUCCEED != zbx_config_buf_append_shell_quoted(&cmd, opts->db_pass) ||
				SUCCEED != zbx_config_buf_appendf(&cmd, " mysql --batch --raw --skip-column-names -h ") ||
				SUCCEED != zbx_config_buf_append_shell_quoted(&cmd, opts->db_host) ||
				SUCCEED != zbx_config_buf_appendf(&cmd, " -P ") ||
				SUCCEED != zbx_config_buf_append_shell_quoted(&cmd, opts->db_port) ||
				SUCCEED != zbx_config_buf_appendf(&cmd, " -u ") ||
				SUCCEED != zbx_config_buf_append_shell_quoted(&cmd, opts->db_user) ||
				SUCCEED != zbx_config_buf_appendf(&cmd, " ") ||
				SUCCEED != zbx_config_buf_append_shell_quoted(&cmd, opts->db_name) ||
				SUCCEED != zbx_config_buf_appendf(&cmd, " -e ") ||
				SUCCEED != zbx_config_buf_append_shell_quoted(&cmd,
						(1 == group_type
							? "select uuid,name from hstgrp where type=1 order by name"
							: "select uuid,name from hstgrp where type=0 order by name")))
		{
			goto fail;
		}
	}

	if (SUCCEED != zbx_config_buf_appendf(array_json, "["))
		goto fail;

	if (NULL == (fp = popen(cmd.data, "r")))
	{
		fprintf(stderr, "zabbix-config: failed to start DB query command\n");
		goto fail;
	}

	while (NULL != fgets(line, sizeof(line), fp))
	{
		size_t	line_len;

		line_len = strlen(line);

		while (0 != line_len && ('\n' == line[line_len - 1] || '\r' == line[line_len - 1]))
			line[--line_len] = '\0';

		if ('\0' == line[0])
			continue;

		delim = strchr(line, '|');

		if (NULL == delim)
		{
			fprintf(stderr, "zabbix-config: unexpected row format from DB query\n");
			goto fail;
		}

		*delim = '\0';
		delim++;

		if (0 != comma)
		{
			if (SUCCEED != zbx_config_buf_appendf(array_json, ", "))
				goto fail;
		}

		if (SUCCEED != zbx_config_buf_appendf(array_json, "{\"uuid\":"))
			goto fail;

		if (SUCCEED != zbx_config_buf_append_json_escaped(array_json, line) ||
				SUCCEED != zbx_config_buf_appendf(array_json, ",\"name\":") ||
				SUCCEED != zbx_config_buf_append_json_escaped(array_json, delim) ||
				SUCCEED != zbx_config_buf_appendf(array_json, "}"))
		{
			goto fail;
		}

		comma = 1;
		(*rows_num)++;
	}

	status = pclose(fp);
	fp = NULL;

	if (!WIFEXITED(status) || 0 != WEXITSTATUS(status))
	{
		fprintf(stderr, "zabbix-config: DB query command failed\n");
		goto fail;
	}

	if (SUCCEED != zbx_config_buf_appendf(array_json, "]"))
		goto fail;

	zbx_config_buf_free(&cmd);
	return SUCCEED;
fail:
	if (NULL != fp)
		(void)pclose(fp);

	zbx_config_buf_free(&cmd);
	zbx_config_buf_free(array_json);
	return FAIL;
}

static char	*zbx_config_next_field(char **cursor, char delim)
{
	char	*start, *sep;

	if (NULL == cursor || NULL == *cursor)
		return NULL;

	start = *cursor;
	sep = strchr(start, delim);

	if (NULL != sep)
	{
		*sep = '\0';
		*cursor = sep + 1;
	}
	else
	{
		*cursor = NULL;
	}

	return start;
}

static int	zbx_config_buf_append_uuid_csv_array(zbx_config_buf_t *buf, const char *csv)
{
	char	*work = NULL, *cursor, *token;
	int	first = 1;
	int	ret = FAIL;

	if (SUCCEED != zbx_config_buf_appendf(buf, "["))
		return FAIL;

	if (NULL == csv || '\0' == *csv)
	{
		if (SUCCEED == zbx_config_buf_appendf(buf, "]"))
			return SUCCEED;

		return FAIL;
	}

	work = strdup(csv);

	if (NULL == work)
		return FAIL;

	cursor = work;

	while (NULL != cursor)
	{
		token = zbx_config_next_field(&cursor, ',');

		if (NULL == token || '\0' == *token)
			continue;

		if (0 == first)
		{
			if (SUCCEED != zbx_config_buf_appendf(buf, ", "))
				goto out;
		}

		if (SUCCEED != zbx_config_buf_append_json_escaped(buf, token))
			goto out;

		first = 0;
	}

	if (SUCCEED != zbx_config_buf_appendf(buf, "]"))
		goto out;

	ret = SUCCEED;
out:
	free(work);
	return ret;
}

static int	zbx_config_run_count_query(const zbx_config_export_opts_t *opts, const char *pgsql_sql,
		const char *mysql_sql, long *count_out)
{
	zbx_config_buf_t	cmd;
	FILE			*fp = NULL;
	char			line[256];
	char			*endptr = NULL;
	int			status;
	long			count;

	memset(&cmd, 0, sizeof(cmd));

	if (NULL == count_out)
		return FAIL;

	*count_out = 0;

	if (SUCCEED != zbx_config_buf_init(&cmd, 512))
		return FAIL;

	if (0 == strcmp(opts->producer_db, "postgresql"))
	{
		if (SUCCEED != zbx_config_buf_appendf(&cmd, "PGPASSWORD=") ||
				SUCCEED != zbx_config_buf_append_shell_quoted(&cmd, opts->db_pass) ||
				SUCCEED != zbx_config_buf_appendf(&cmd, " psql -h ") ||
				SUCCEED != zbx_config_buf_append_shell_quoted(&cmd, opts->db_host) ||
				SUCCEED != zbx_config_buf_appendf(&cmd, " -p ") ||
				SUCCEED != zbx_config_buf_append_shell_quoted(&cmd, opts->db_port) ||
				SUCCEED != zbx_config_buf_appendf(&cmd, " -U ") ||
				SUCCEED != zbx_config_buf_append_shell_quoted(&cmd, opts->db_user) ||
				SUCCEED != zbx_config_buf_appendf(&cmd, " -d ") ||
				SUCCEED != zbx_config_buf_append_shell_quoted(&cmd, opts->db_name) ||
				SUCCEED != zbx_config_buf_appendf(&cmd, " -At -c ") ||
				SUCCEED != zbx_config_buf_append_shell_quoted(&cmd, pgsql_sql))
		{
			goto fail;
		}
	}
	else
	{
		if (SUCCEED != zbx_config_buf_appendf(&cmd, "MYSQL_PWD=") ||
				SUCCEED != zbx_config_buf_append_shell_quoted(&cmd, opts->db_pass) ||
				SUCCEED != zbx_config_buf_appendf(&cmd, " mysql --batch --raw --skip-column-names -h ") ||
				SUCCEED != zbx_config_buf_append_shell_quoted(&cmd, opts->db_host) ||
				SUCCEED != zbx_config_buf_appendf(&cmd, " -P ") ||
				SUCCEED != zbx_config_buf_append_shell_quoted(&cmd, opts->db_port) ||
				SUCCEED != zbx_config_buf_appendf(&cmd, " -u ") ||
				SUCCEED != zbx_config_buf_append_shell_quoted(&cmd, opts->db_user) ||
				SUCCEED != zbx_config_buf_appendf(&cmd, " ") ||
				SUCCEED != zbx_config_buf_append_shell_quoted(&cmd, opts->db_name) ||
				SUCCEED != zbx_config_buf_appendf(&cmd, " -e ") ||
				SUCCEED != zbx_config_buf_append_shell_quoted(&cmd, mysql_sql))
		{
			goto fail;
		}
	}

	if (NULL == (fp = popen(cmd.data, "r")))
	{
		fprintf(stderr, "zabbix-config: failed to start DB validation query\n");
		goto fail;
	}

	if (NULL == fgets(line, sizeof(line), fp))
	{
		fprintf(stderr, "zabbix-config: empty result from DB validation query\n");
		goto fail;
	}

	count = strtol(line, &endptr, 10);

	if (NULL == endptr || endptr == line)
	{
		fprintf(stderr, "zabbix-config: invalid DB validation query result\n");
		goto fail;
	}

	status = pclose(fp);
	fp = NULL;

	if (!WIFEXITED(status) || 0 != WEXITSTATUS(status))
	{
		fprintf(stderr, "zabbix-config: DB validation query failed\n");
		goto fail;
	}

	*count_out = count;
	zbx_config_buf_free(&cmd);
	return SUCCEED;
fail:
	if (NULL != fp)
		(void)pclose(fp);

	zbx_config_buf_free(&cmd);
	return FAIL;
}

static int	zbx_config_validate_export_selection(const zbx_config_export_opts_t *opts)
{
	if (0 != (opts->object_mask & ZBX_OBJ_TEMPLATES) && 0 == (opts->object_mask & ZBX_OBJ_TEMPLATE_GROUPS))
	{
		fprintf(stderr, "zabbix-config: exporting templates requires template_groups to be included\n");
		return FAIL;
	}

	if (0 != (opts->object_mask & ZBX_OBJ_HOSTS) && 0 == (opts->object_mask & ZBX_OBJ_HOST_GROUPS))
	{
		fprintf(stderr, "zabbix-config: exporting hosts requires host_groups to be included\n");
		return FAIL;
	}

	if (0 != (opts->object_mask & ZBX_OBJ_ITEMS) &&
			(0 == (opts->object_mask & ZBX_OBJ_TEMPLATES) || 0 == (opts->object_mask & ZBX_OBJ_HOSTS)))
	{
		fprintf(stderr, "zabbix-config: exporting items requires templates and hosts to be included\n");
		return FAIL;
	}

	if (0 != (opts->object_mask & ZBX_OBJ_TRIGGERS) && 0 == (opts->object_mask & ZBX_OBJ_ITEMS))
	{
		fprintf(stderr, "zabbix-config: exporting triggers requires items to be included\n");
		return FAIL;
	}

	return SUCCEED;
}

static int	zbx_config_validate_host_group_refs(const zbx_config_export_opts_t *opts, int templates)
{
	long	unresolved = 0;
	const char	*pgsql_sql;
	const char	*mysql_sql;

	if (0 != templates)
	{
		pgsql_sql = "select count(*)"
			" from hosts_groups hg"
			" join hosts h on h.hostid=hg.hostid"
			" left join hstgrp g on g.groupid=hg.groupid and g.type=1 and g.uuid is not null and g.uuid<>''"
			" where h.status=3 and h.uuid is not null and h.uuid<>'' and g.groupid is null";

		mysql_sql = "select count(*)"
			" from hosts_groups hg"
			" join hosts h on h.hostid=hg.hostid"
			" left join hstgrp g on g.groupid=hg.groupid and g.type=1 and g.uuid is not null and g.uuid<>''"
			" where h.status=3 and h.uuid is not null and h.uuid<>'' and g.groupid is null";
	}
	else
	{
		pgsql_sql = "select count(*)"
			" from hosts_groups hg"
			" join hosts h on h.hostid=hg.hostid"
			" left join hstgrp g on g.groupid=hg.groupid and g.type=0 and g.uuid is not null and g.uuid<>''"
			" where h.status<>3 and h.uuid is not null and h.uuid<>'' and g.groupid is null";

		mysql_sql = "select count(*)"
			" from hosts_groups hg"
			" join hosts h on h.hostid=hg.hostid"
			" left join hstgrp g on g.groupid=hg.groupid and g.type=0 and g.uuid is not null and g.uuid<>''"
			" where h.status<>3 and h.uuid is not null and h.uuid<>'' and g.groupid is null";
	}

	if (SUCCEED != zbx_config_run_count_query(opts, pgsql_sql, mysql_sql, &unresolved))
		return FAIL;

	if (0 < unresolved)
	{
		fprintf(stderr, "zabbix-config: unresolved %s group references detected: %ld\n",
				(0 != templates ? "template" : "host"), unresolved);
		return FAIL;
	}

	return SUCCEED;
}

static int	zbx_config_validate_item_refs(const zbx_config_export_opts_t *opts)
{
	long	unresolved = 0;
	const char	*pgsql_sql;
	const char	*mysql_sql;

	pgsql_sql = "select count(*)"
		" from items i"
		" left join hosts h on h.hostid=i.hostid and h.uuid is not null and h.uuid<>''"
		" where i.flags=0 and i.uuid is not null and i.uuid<>'' and h.hostid is null";

	mysql_sql = "select count(*)"
		" from items i"
		" left join hosts h on h.hostid=i.hostid and h.uuid is not null and h.uuid<>''"
		" where i.flags=0 and i.uuid is not null and i.uuid<>'' and h.hostid is null";

	if (SUCCEED != zbx_config_run_count_query(opts, pgsql_sql, mysql_sql, &unresolved))
		return FAIL;

	if (0 < unresolved)
	{
		fprintf(stderr, "zabbix-config: unresolved item host references detected: %ld\n", unresolved);
		return FAIL;
	}

	return SUCCEED;
}

static int	zbx_config_validate_trigger_refs(const zbx_config_export_opts_t *opts)
{
	long	unresolved = 0;
	const char	*pgsql_sql;
	const char	*mysql_sql;

	pgsql_sql = "select count(distinct t.triggerid)"
		" from triggers t"
		" join functions f on f.triggerid=t.triggerid"
		" left join items i on i.itemid=f.itemid and i.uuid is not null and i.uuid<>''"
		" where t.flags=0 and t.uuid is not null and t.uuid<>'' and i.itemid is null";

	mysql_sql = "select count(distinct t.triggerid)"
		" from triggers t"
		" join functions f on f.triggerid=t.triggerid"
		" left join items i on i.itemid=f.itemid and i.uuid is not null and i.uuid<>''"
		" where t.flags=0 and t.uuid is not null and t.uuid<>'' and i.itemid is null";

	if (SUCCEED != zbx_config_run_count_query(opts, pgsql_sql, mysql_sql, &unresolved))
		return FAIL;

	if (0 < unresolved)
	{
		fprintf(stderr, "zabbix-config: unresolved trigger item references detected: %ld\n", unresolved);
		return FAIL;
	}

	return SUCCEED;
}

static int	zbx_config_build_host_object_array(const zbx_config_export_opts_t *opts, int templates,
		zbx_config_buf_t *array_json, size_t *rows_num)
{
	zbx_config_buf_t	cmd;
	FILE			*fp = NULL;
	char			line[8192];
	char			*cursor, *f_uuid, *f_host, *f_name, *f_groups;
	int			comma = 0;
	int			status;

	memset(&cmd, 0, sizeof(cmd));
	memset(array_json, 0, sizeof(*array_json));
	*rows_num = 0;

	if (SUCCEED != zbx_config_buf_init(array_json, 1024) || SUCCEED != zbx_config_buf_init(&cmd, 1024))
		goto fail;

	if (0 == strcmp(opts->producer_db, "postgresql"))
	{
		if (SUCCEED != zbx_config_buf_appendf(&cmd, "PGPASSWORD=") ||
				SUCCEED != zbx_config_buf_append_shell_quoted(&cmd, opts->db_pass) ||
				SUCCEED != zbx_config_buf_appendf(&cmd, " psql -h ") ||
				SUCCEED != zbx_config_buf_append_shell_quoted(&cmd, opts->db_host) ||
				SUCCEED != zbx_config_buf_appendf(&cmd, " -p ") ||
				SUCCEED != zbx_config_buf_append_shell_quoted(&cmd, opts->db_port) ||
				SUCCEED != zbx_config_buf_appendf(&cmd, " -U ") ||
				SUCCEED != zbx_config_buf_append_shell_quoted(&cmd, opts->db_user) ||
				SUCCEED != zbx_config_buf_appendf(&cmd, " -d ") ||
				SUCCEED != zbx_config_buf_append_shell_quoted(&cmd, opts->db_name) ||
				SUCCEED != zbx_config_buf_appendf(&cmd, " -At -F '|' -c ") ||
				SUCCEED != zbx_config_buf_append_shell_quoted(&cmd,
						(0 != templates
							? "select h.uuid,h.host,h.name,coalesce(string_agg(g.uuid,',' order by g.uuid),'')"
							  " from hosts h"
							  " left join hosts_groups hg on hg.hostid=h.hostid"
							  " left join hstgrp g on g.groupid=hg.groupid and g.type=1 and g.uuid is not null and g.uuid<>''"
							  " where h.status=3 and h.uuid is not null and h.uuid<>''"
							  " group by h.hostid,h.uuid,h.host,h.name"
							  " order by h.host"
							: "select h.uuid,h.host,h.name,coalesce(string_agg(g.uuid,',' order by g.uuid),'')"
							  " from hosts h"
							  " left join hosts_groups hg on hg.hostid=h.hostid"
							  " left join hstgrp g on g.groupid=hg.groupid and g.type=0 and g.uuid is not null and g.uuid<>''"
							  " where h.status<>3 and h.uuid is not null and h.uuid<>''"
							  " group by h.hostid,h.uuid,h.host,h.name"
							  " order by h.host")))
		{
			goto fail;
		}
	}
	else
	{
		if (SUCCEED != zbx_config_buf_appendf(&cmd, "MYSQL_PWD=") ||
				SUCCEED != zbx_config_buf_append_shell_quoted(&cmd, opts->db_pass) ||
				SUCCEED != zbx_config_buf_appendf(&cmd, " mysql --batch --raw --skip-column-names -h ") ||
				SUCCEED != zbx_config_buf_append_shell_quoted(&cmd, opts->db_host) ||
				SUCCEED != zbx_config_buf_appendf(&cmd, " -P ") ||
				SUCCEED != zbx_config_buf_append_shell_quoted(&cmd, opts->db_port) ||
				SUCCEED != zbx_config_buf_appendf(&cmd, " -u ") ||
				SUCCEED != zbx_config_buf_append_shell_quoted(&cmd, opts->db_user) ||
				SUCCEED != zbx_config_buf_appendf(&cmd, " ") ||
				SUCCEED != zbx_config_buf_append_shell_quoted(&cmd, opts->db_name) ||
				SUCCEED != zbx_config_buf_appendf(&cmd, " -e ") ||
				SUCCEED != zbx_config_buf_append_shell_quoted(&cmd,
						(0 != templates
							? "select h.uuid,h.host,h.name,ifnull(group_concat(distinct g.uuid order by g.uuid separator ','),'')"
							  " from hosts h"
							  " left join hosts_groups hg on hg.hostid=h.hostid"
							  " left join hstgrp g on g.groupid=hg.groupid and g.type=1 and g.uuid is not null and g.uuid<>''"
							  " where h.status=3 and h.uuid is not null and h.uuid<>''"
							  " group by h.hostid,h.uuid,h.host,h.name"
							  " order by h.host"
							: "select h.uuid,h.host,h.name,ifnull(group_concat(distinct g.uuid order by g.uuid separator ','),'')"
							  " from hosts h"
							  " left join hosts_groups hg on hg.hostid=h.hostid"
							  " left join hstgrp g on g.groupid=hg.groupid and g.type=0 and g.uuid is not null and g.uuid<>''"
							  " where h.status<>3 and h.uuid is not null and h.uuid<>''"
							  " group by h.hostid,h.uuid,h.host,h.name"
							  " order by h.host")))
		{
			goto fail;
		}
	}

	if (SUCCEED != zbx_config_buf_appendf(array_json, "["))
		goto fail;

	if (NULL == (fp = popen(cmd.data, "r")))
	{
		fprintf(stderr, "zabbix-config: failed to start DB query command\n");
		goto fail;
	}

	while (NULL != fgets(line, sizeof(line), fp))
	{
		size_t	line_len;

		line_len = strlen(line);

		while (0 != line_len && ('\n' == line[line_len - 1] || '\r' == line[line_len - 1]))
			line[--line_len] = '\0';

		if ('\0' == line[0])
			continue;

		cursor = line;
		f_uuid = zbx_config_next_field(&cursor, '|');
		f_host = zbx_config_next_field(&cursor, '|');
		f_name = zbx_config_next_field(&cursor, '|');
		f_groups = zbx_config_next_field(&cursor, '|');

		if (NULL == f_uuid || NULL == f_host || NULL == f_name || NULL == f_groups)
		{
			fprintf(stderr, "zabbix-config: unexpected row format from DB query\n");
			goto fail;
		}

		if (0 != comma)
		{
			if (SUCCEED != zbx_config_buf_appendf(array_json, ", "))
				goto fail;
		}

		if (SUCCEED != zbx_config_buf_appendf(array_json, "{\"uuid\":"))
			goto fail;

		if (SUCCEED != zbx_config_buf_append_json_escaped(array_json, f_uuid) ||
				SUCCEED != zbx_config_buf_appendf(array_json, ",\"host\":") ||
				SUCCEED != zbx_config_buf_append_json_escaped(array_json, f_host) ||
				SUCCEED != zbx_config_buf_appendf(array_json, ",\"name\":") ||
				SUCCEED != zbx_config_buf_append_json_escaped(array_json, f_name) ||
				SUCCEED != zbx_config_buf_appendf(array_json, ",\"group_uuids\":") ||
				SUCCEED != zbx_config_buf_append_uuid_csv_array(array_json, f_groups) ||
				SUCCEED != zbx_config_buf_appendf(array_json, "}"))
		{
			goto fail;
		}

		comma = 1;
		(*rows_num)++;
	}

	status = pclose(fp);
	fp = NULL;

	if (!WIFEXITED(status) || 0 != WEXITSTATUS(status))
	{
		fprintf(stderr, "zabbix-config: DB query command failed\n");
		goto fail;
	}

	if (SUCCEED != zbx_config_buf_appendf(array_json, "]"))
		goto fail;

	zbx_config_buf_free(&cmd);
	return SUCCEED;
fail:
	if (NULL != fp)
		(void)pclose(fp);

	zbx_config_buf_free(&cmd);
	zbx_config_buf_free(array_json);
	return FAIL;
}

static int	zbx_config_build_item_array(const zbx_config_export_opts_t *opts, zbx_config_buf_t *array_json,
		size_t *rows_num)
{
	zbx_config_buf_t	cmd;
	FILE			*fp = NULL;
	char			line[8192];
	char			*cursor, *f_uuid, *f_host_uuid, *f_key, *f_name, *f_type, *f_value_type, *f_status;
	int			comma = 0;
	int			status;

	memset(&cmd, 0, sizeof(cmd));
	memset(array_json, 0, sizeof(*array_json));
	*rows_num = 0;

	if (SUCCEED != zbx_config_buf_init(array_json, 1024) || SUCCEED != zbx_config_buf_init(&cmd, 1024))
		goto fail;

	if (0 == strcmp(opts->producer_db, "postgresql"))
	{
		if (SUCCEED != zbx_config_buf_appendf(&cmd, "PGPASSWORD=") ||
				SUCCEED != zbx_config_buf_append_shell_quoted(&cmd, opts->db_pass) ||
				SUCCEED != zbx_config_buf_appendf(&cmd, " psql -h ") ||
				SUCCEED != zbx_config_buf_append_shell_quoted(&cmd, opts->db_host) ||
				SUCCEED != zbx_config_buf_appendf(&cmd, " -p ") ||
				SUCCEED != zbx_config_buf_append_shell_quoted(&cmd, opts->db_port) ||
				SUCCEED != zbx_config_buf_appendf(&cmd, " -U ") ||
				SUCCEED != zbx_config_buf_append_shell_quoted(&cmd, opts->db_user) ||
				SUCCEED != zbx_config_buf_appendf(&cmd, " -d ") ||
				SUCCEED != zbx_config_buf_append_shell_quoted(&cmd, opts->db_name) ||
				SUCCEED != zbx_config_buf_appendf(&cmd, " -At -F '|' -c ") ||
				SUCCEED != zbx_config_buf_append_shell_quoted(&cmd,
						"select i.uuid,h.uuid,i.key_,i.name,i.type,i.value_type,i.status"
						" from items i"
						" join hosts h on h.hostid=i.hostid"
						" where i.flags=0"
						" and i.uuid is not null and i.uuid<>''"
						" and h.uuid is not null and h.uuid<>''"
						" order by h.host,i.key_"))
		{
			goto fail;
		}
	}
	else
	{
		if (SUCCEED != zbx_config_buf_appendf(&cmd, "MYSQL_PWD=") ||
				SUCCEED != zbx_config_buf_append_shell_quoted(&cmd, opts->db_pass) ||
				SUCCEED != zbx_config_buf_appendf(&cmd, " mysql --batch --raw --skip-column-names -h ") ||
				SUCCEED != zbx_config_buf_append_shell_quoted(&cmd, opts->db_host) ||
				SUCCEED != zbx_config_buf_appendf(&cmd, " -P ") ||
				SUCCEED != zbx_config_buf_append_shell_quoted(&cmd, opts->db_port) ||
				SUCCEED != zbx_config_buf_appendf(&cmd, " -u ") ||
				SUCCEED != zbx_config_buf_append_shell_quoted(&cmd, opts->db_user) ||
				SUCCEED != zbx_config_buf_appendf(&cmd, " ") ||
				SUCCEED != zbx_config_buf_append_shell_quoted(&cmd, opts->db_name) ||
				SUCCEED != zbx_config_buf_appendf(&cmd, " -e ") ||
				SUCCEED != zbx_config_buf_append_shell_quoted(&cmd,
						"select i.uuid,h.uuid,i.key_,i.name,i.type,i.value_type,i.status"
						" from items i"
						" join hosts h on h.hostid=i.hostid"
						" where i.flags=0"
						" and i.uuid is not null and i.uuid<>''"
						" and h.uuid is not null and h.uuid<>''"
						" order by h.host,i.key_"))
		{
			goto fail;
		}
	}

	if (SUCCEED != zbx_config_buf_appendf(array_json, "["))
		goto fail;

	if (NULL == (fp = popen(cmd.data, "r")))
	{
		fprintf(stderr, "zabbix-config: failed to start DB query command\n");
		goto fail;
	}

	while (NULL != fgets(line, sizeof(line), fp))
	{
		size_t	line_len;

		line_len = strlen(line);

		while (0 != line_len && ('\n' == line[line_len - 1] || '\r' == line[line_len - 1]))
			line[--line_len] = '\0';

		if ('\0' == line[0])
			continue;

		cursor = line;
		f_uuid = zbx_config_next_field(&cursor, '|');
		f_host_uuid = zbx_config_next_field(&cursor, '|');
		f_key = zbx_config_next_field(&cursor, '|');
		f_name = zbx_config_next_field(&cursor, '|');
		f_type = zbx_config_next_field(&cursor, '|');
		f_value_type = zbx_config_next_field(&cursor, '|');
		f_status = zbx_config_next_field(&cursor, '|');

		if (NULL == f_uuid || NULL == f_host_uuid || NULL == f_key || NULL == f_name || NULL == f_type ||
				NULL == f_value_type || NULL == f_status)
		{
			fprintf(stderr, "zabbix-config: unexpected row format from DB query\n");
			goto fail;
		}

		if (0 != comma)
		{
			if (SUCCEED != zbx_config_buf_appendf(array_json, ", "))
				goto fail;
		}

		if (SUCCEED != zbx_config_buf_appendf(array_json, "{\"uuid\":"))
			goto fail;

		if (SUCCEED != zbx_config_buf_append_json_escaped(array_json, f_uuid) ||
				SUCCEED != zbx_config_buf_appendf(array_json, ",\"host_uuid\":") ||
				SUCCEED != zbx_config_buf_append_json_escaped(array_json, f_host_uuid) ||
				SUCCEED != zbx_config_buf_appendf(array_json, ",\"key_\":") ||
				SUCCEED != zbx_config_buf_append_json_escaped(array_json, f_key) ||
				SUCCEED != zbx_config_buf_appendf(array_json, ",\"name\":") ||
				SUCCEED != zbx_config_buf_append_json_escaped(array_json, f_name) ||
				SUCCEED != zbx_config_buf_appendf(array_json, ",\"type\":%s,\"value_type\":%s,\"status\":%s}",
						f_type, f_value_type, f_status))
		{
			goto fail;
		}

		comma = 1;
		(*rows_num)++;
	}

	status = pclose(fp);
	fp = NULL;

	if (!WIFEXITED(status) || 0 != WEXITSTATUS(status))
	{
		fprintf(stderr, "zabbix-config: DB query command failed\n");
		goto fail;
	}

	if (SUCCEED != zbx_config_buf_appendf(array_json, "]"))
		goto fail;

	zbx_config_buf_free(&cmd);
	return SUCCEED;
fail:
	if (NULL != fp)
		(void)pclose(fp);

	zbx_config_buf_free(&cmd);
	zbx_config_buf_free(array_json);
	return FAIL;
}

static int	zbx_config_build_trigger_array(const zbx_config_export_opts_t *opts, zbx_config_buf_t *array_json,
		size_t *rows_num)
{
	zbx_config_buf_t	cmd;
	FILE			*fp = NULL;
	char			line[8192];
	int			comma = 0;
	int			status;

	memset(&cmd, 0, sizeof(cmd));
	memset(array_json, 0, sizeof(*array_json));
	*rows_num = 0;

	if (SUCCEED != zbx_config_buf_init(array_json, 1024) || SUCCEED != zbx_config_buf_init(&cmd, 1024))
		goto fail;

	if (0 == strcmp(opts->producer_db, "postgresql"))
	{
		if (SUCCEED != zbx_config_buf_appendf(&cmd, "PGPASSWORD=") ||
				SUCCEED != zbx_config_buf_append_shell_quoted(&cmd, opts->db_pass) ||
				SUCCEED != zbx_config_buf_appendf(&cmd, " psql -h ") ||
				SUCCEED != zbx_config_buf_append_shell_quoted(&cmd, opts->db_host) ||
				SUCCEED != zbx_config_buf_appendf(&cmd, " -p ") ||
				SUCCEED != zbx_config_buf_append_shell_quoted(&cmd, opts->db_port) ||
				SUCCEED != zbx_config_buf_appendf(&cmd, " -U ") ||
				SUCCEED != zbx_config_buf_append_shell_quoted(&cmd, opts->db_user) ||
				SUCCEED != zbx_config_buf_appendf(&cmd, " -d ") ||
				SUCCEED != zbx_config_buf_append_shell_quoted(&cmd, opts->db_name) ||
				SUCCEED != zbx_config_buf_appendf(&cmd, " -At -c ") ||
				SUCCEED != zbx_config_buf_append_shell_quoted(&cmd,
						"select json_build_object("
						"'uuid',t.uuid,"
						"'description',t.description,"
						"'expression',t.expression,"
						"'status',t.status,"
						"'priority',t.priority,"
						"'type',t.type,"
						"'item_uuids',coalesce((select json_agg(x.uuid)"
						" from (select distinct i2.uuid as uuid"
						"       from functions f2"
						"       join items i2 on i2.itemid=f2.itemid"
						"       where f2.triggerid=t.triggerid and i2.uuid is not null and i2.uuid<>''"
						"       order by i2.uuid) x), '[]'::json),"
						"'functions',coalesce((select json_agg(json_build_object("
						"'source_itemid',f2.itemid,"
						"'item_uuid',i2.uuid,"
						"'name',f2.name,"
						"'parameter',f2.parameter"
						"))"
						" from functions f2"
						" join items i2 on i2.itemid=f2.itemid"
						" where f2.triggerid=t.triggerid and i2.uuid is not null and i2.uuid<>''), '[]'::json)"
						")::text"
						" from triggers t"
						" where t.flags=0 and t.uuid is not null and t.uuid<>''"
						" order by t.description"))
		{
			goto fail;
		}
	}
	else
	{
		if (SUCCEED != zbx_config_buf_appendf(&cmd, "MYSQL_PWD=") ||
				SUCCEED != zbx_config_buf_append_shell_quoted(&cmd, opts->db_pass) ||
				SUCCEED != zbx_config_buf_appendf(&cmd, " mysql --batch --raw --skip-column-names -h ") ||
				SUCCEED != zbx_config_buf_append_shell_quoted(&cmd, opts->db_host) ||
				SUCCEED != zbx_config_buf_appendf(&cmd, " -P ") ||
				SUCCEED != zbx_config_buf_append_shell_quoted(&cmd, opts->db_port) ||
				SUCCEED != zbx_config_buf_appendf(&cmd, " -u ") ||
				SUCCEED != zbx_config_buf_append_shell_quoted(&cmd, opts->db_user) ||
				SUCCEED != zbx_config_buf_appendf(&cmd, " ") ||
				SUCCEED != zbx_config_buf_append_shell_quoted(&cmd, opts->db_name) ||
				SUCCEED != zbx_config_buf_appendf(&cmd, " -e ") ||
				SUCCEED != zbx_config_buf_append_shell_quoted(&cmd,
						"select json_object("
						"'uuid',t.uuid,"
						"'description',t.description,"
						"'expression',t.expression,"
						"'status',t.status,"
						"'priority',t.priority,"
						"'type',t.type,"
						"'item_uuids',ifnull((select json_arrayagg(x.uuid)"
						" from (select distinct i2.uuid as uuid"
						"       from functions f2"
						"       join items i2 on i2.itemid=f2.itemid"
						"       where f2.triggerid=t.triggerid and i2.uuid is not null and i2.uuid<>''"
						"       order by i2.uuid) x), json_array()),"
						"'functions',ifnull((select json_arrayagg(json_object("
						"'source_itemid',f2.itemid,"
						"'item_uuid',i2.uuid,"
						"'name',f2.name,"
						"'parameter',f2.parameter"
						"))"
						" from functions f2"
						" join items i2 on i2.itemid=f2.itemid"
						" where f2.triggerid=t.triggerid and i2.uuid is not null and i2.uuid<>''), json_array())"
						")"
						" from triggers t"
						" where t.flags=0 and t.uuid is not null and t.uuid<>''"
						" order by t.description"))
		{
			goto fail;
		}
	}

	if (SUCCEED != zbx_config_buf_appendf(array_json, "["))
		goto fail;

	if (NULL == (fp = popen(cmd.data, "r")))
	{
		fprintf(stderr, "zabbix-config: failed to start DB query command\n");
		goto fail;
	}

	while (NULL != fgets(line, sizeof(line), fp))
	{
		size_t	line_len;

		line_len = strlen(line);

		while (0 != line_len && ('\n' == line[line_len - 1] || '\r' == line[line_len - 1]))
			line[--line_len] = '\0';

		if ('\0' == line[0])
			continue;

		if (0 != comma)
		{
			if (SUCCEED != zbx_config_buf_appendf(array_json, ", "))
				goto fail;
		}

		if (SUCCEED != zbx_config_buf_appendf(array_json, "%s", line))
			goto fail;

		comma = 1;
		(*rows_num)++;
	}

	status = pclose(fp);
	fp = NULL;

	if (!WIFEXITED(status) || 0 != WEXITSTATUS(status))
	{
		fprintf(stderr, "zabbix-config: DB query command failed\n");
		goto fail;
	}

	if (SUCCEED != zbx_config_buf_appendf(array_json, "]"))
		goto fail;

	zbx_config_buf_free(&cmd);
	return SUCCEED;
fail:
	if (NULL != fp)
		(void)pclose(fp);

	zbx_config_buf_free(&cmd);
	zbx_config_buf_free(array_json);
	return FAIL;
}

static const zbx_config_object_t	*zbx_config_find_object(const char *name)
{
	size_t	i;

	for (i = 0; i < sizeof(zbx_config_objects) / sizeof(zbx_config_objects[0]); i++)
	{
		if (0 == strcmp(name, zbx_config_objects[i].name))
			return &zbx_config_objects[i];
	}

	return NULL;
}

static int	zbx_config_object_is_supported(const zbx_config_object_t *object)
{
	if (NULL == object)
		return 0;

	return 0 != (object->bit & ZBX_OBJ_SUPPORTED_MASK);
}

static int	zbx_config_get_utc_timestamp(char *out, size_t out_size)
{
	time_t		now;
	struct tm	tm_utc;

	now = time(NULL);

	if ((time_t)-1 == now)
		return FAIL;

#if defined(_WINDOWS)
	if (0 != gmtime_s(&tm_utc, &now))
		return FAIL;
#else
	if (NULL == gmtime_r(&now, &tm_utc))
		return FAIL;
#endif

	if (0 == strftime(out, out_size, "%Y-%m-%dT%H:%M:%SZ", &tm_utc))
		return FAIL;

	return SUCCEED;
}

static int	zbx_config_is_token_valid(const char *value)
{
	const unsigned char	*p;

	if (NULL == value || '\0' == *value)
		return 0;

	for (p = (const unsigned char *)value; '\0' != *p; p++)
	{
		if (!(('a' <= *p && 'z' >= *p) || ('A' <= *p && 'Z' >= *p) || ('0' <= *p && '9' >= *p) ||
				'_' == *p || '-' == *p || '.' == *p))
		{
			return 0;
		}
	}

	return 1;
}

static int	zbx_config_write_file_atomic(const char *path, const char *data)
{
	char	tmp_path[PATH_MAX];
	int	fd = -1, ret = FAIL;
	FILE	*fp = NULL;
	size_t	data_len;

	if (NULL == path || '\0' == *path)
	{
		fprintf(stderr, "zabbix-config: output path is empty\n");
		return FAIL;
	}

	if (snprintf(tmp_path, sizeof(tmp_path), "%s.tmp.XXXXXX", path) >= (int)sizeof(tmp_path))
	{
		fprintf(stderr, "zabbix-config: output path is too long\n");
		return FAIL;
	}

	if (-1 == (fd = mkstemp(tmp_path)))
	{
		fprintf(stderr, "zabbix-config: cannot create temp file for '%s': %s\n", path, strerror(errno));
		goto out;
	}

	if (NULL == (fp = fdopen(fd, "w")))
	{
		fprintf(stderr, "zabbix-config: cannot open temp file for '%s': %s\n", path, strerror(errno));
		goto out;
	}

	fd = -1;
	data_len = strlen(data);

	if (data_len != fwrite(data, 1, data_len, fp))
	{
		fprintf(stderr, "zabbix-config: failed writing temp file for '%s'\n", path);
		goto out;
	}

	if (0 != fflush(fp))
	{
		fprintf(stderr, "zabbix-config: failed flushing temp file for '%s': %s\n", path, strerror(errno));
		goto out;
	}

	if (0 != fclose(fp))
	{
		fp = NULL;
		fprintf(stderr, "zabbix-config: failed closing temp file for '%s': %s\n", path, strerror(errno));
		goto out;
	}

	fp = NULL;

	if (0 != rename(tmp_path, path))
	{
		fprintf(stderr, "zabbix-config: failed to move temp file to '%s': %s\n", path, strerror(errno));
		goto out;
	}

	ret = SUCCEED;
out:
	if (NULL != fp)
		(void)fclose(fp);

	if (-1 != fd)
		(void)close(fd);

	if (FAIL == ret)
		(void)unlink(tmp_path);

	return ret;
}

static int	zbx_config_read_text_file(const char *path, zbx_config_buf_t *buf)
{
	FILE	*fp = NULL;
	char	chunk[4096];
	size_t	bytes;
	int	ret = FAIL;

	memset(buf, 0, sizeof(*buf));

	if (NULL == path || '\0' == *path)
	{
		fprintf(stderr, "zabbix-config: bundle path is empty\n");
		return FAIL;
	}

	if (SUCCEED != zbx_config_buf_init(buf, 4096))
		return FAIL;

	if (NULL == (fp = fopen(path, "r")))
	{
		fprintf(stderr, "zabbix-config: cannot open bundle '%s': %s\n", path, strerror(errno));
		goto out;
	}

	while (0 != (bytes = fread(chunk, 1, sizeof(chunk), fp)))
	{
		if (SUCCEED != zbx_config_buf_grow(buf, buf->len + bytes + 1))
		{
			fprintf(stderr, "zabbix-config: out of memory reading '%s'\n", path);
			goto out;
		}

		memcpy(buf->data + buf->len, chunk, bytes);
		buf->len += bytes;
		buf->data[buf->len] = '\0';
	}

	if (ferror(fp))
	{
		fprintf(stderr, "zabbix-config: failed reading bundle '%s'\n", path);
		goto out;
	}

	ret = SUCCEED;
out:
	if (NULL != fp)
		(void)fclose(fp);

	if (FAIL == ret)
		zbx_config_buf_free(buf);

	return ret;
}

static const char	*zbx_config_skip_ws(const char *p)
{
	while (NULL != p && (' ' == *p || '\t' == *p || '\n' == *p || '\r' == *p))
		p++;

	return p;
}

static const char	*zbx_config_find_json_key(const char *json, const char *key)
{
	char	pattern[128];

	if (NULL == json || NULL == key)
		return NULL;

	if (snprintf(pattern, sizeof(pattern), "\"%s\"", key) >= (int)sizeof(pattern))
		return NULL;

	return strstr(json, pattern);
}

static int	zbx_config_parse_json_int(const char *json, const char *key, int *value_out)
{
	const char	*p;
	char		*endptr;
	long		value;

	if (NULL == value_out)
		return FAIL;

	p = zbx_config_find_json_key(json, key);

	if (NULL == p || NULL == (p = strchr(p, ':')))
		return FAIL;

	p = zbx_config_skip_ws(p + 1);
	errno = 0;
	value = strtol(p, &endptr, 10);

	if (0 != errno || p == endptr || INT_MAX < value || INT_MIN > value)
		return FAIL;

	*value_out = (int)value;
	return SUCCEED;
}

static int	zbx_config_parse_json_string_token(const char *json, const char *key, char *out, size_t out_size)
{
	const char	*p;
	size_t		len = 0;

	if (NULL == out || 0 == out_size)
		return FAIL;

	p = zbx_config_find_json_key(json, key);

	if (NULL == p || NULL == (p = strchr(p, ':')))
		return FAIL;

	p = zbx_config_skip_ws(p + 1);

	if ('"' != *p)
		return FAIL;

	p++;

	while ('\0' != *p)
	{
		if ('"' == *p)
		{
			out[len] = '\0';
			return SUCCEED;
		}

		if ('\\' == *p)
		{
			p++;

			if ('\0' == *p)
				return FAIL;

			if (len + 1 >= out_size)
				return FAIL;

			switch (*p)
			{
				case '"':
				case '\\':
				case '/':
					out[len++] = *p;
					break;
				case 'b':
					out[len++] = '\b';
					break;
				case 'f':
					out[len++] = '\f';
					break;
				case 'n':
					out[len++] = '\n';
					break;
				case 'r':
					out[len++] = '\r';
					break;
				case 't':
					out[len++] = '\t';
					break;
				case 'u':
				{
					unsigned int	code = 0;
					int		i;

					for (i = 0; i < 4; i++)
					{
						unsigned char	c;

						p++;

						if ('\0' == *p)
							return FAIL;

						c = (unsigned char)*p;

						if ('0' <= c && '9' >= c)
							code = (code << 4) | (c - '0');
						else if ('a' <= c && 'f' >= c)
							code = (code << 4) | (c - 'a' + 10);
						else if ('A' <= c && 'F' >= c)
							code = (code << 4) | (c - 'A' + 10);
						else
							return FAIL;
					}

					if (0x7f >= code)
						out[len++] = (char)code;
					else
						out[len++] = '?';

					break;
				}
				default:
					return FAIL;
			}

			p++;
			continue;
		}

		if (len + 1 >= out_size)
			return FAIL;

		out[len++] = *p;
		p++;
	}

	return FAIL;
}

static int	zbx_config_validate_object_dependency_mask(unsigned int object_mask)
{
	if (0 != (object_mask & ZBX_OBJ_TEMPLATES) && 0 == (object_mask & ZBX_OBJ_TEMPLATE_GROUPS))
	{
		fprintf(stderr, "zabbix-config: package object_order includes templates without template_groups\n");
		return FAIL;
	}

	if (0 != (object_mask & ZBX_OBJ_HOSTS) && 0 == (object_mask & ZBX_OBJ_HOST_GROUPS))
	{
		fprintf(stderr, "zabbix-config: package object_order includes hosts without host_groups\n");
		return FAIL;
	}

	if (0 != (object_mask & ZBX_OBJ_ITEMS) &&
			(0 == (object_mask & ZBX_OBJ_TEMPLATES) || 0 == (object_mask & ZBX_OBJ_HOSTS)))
	{
		fprintf(stderr, "zabbix-config: package object_order includes items without templates and hosts\n");
		return FAIL;
	}

	if (0 != (object_mask & ZBX_OBJ_TRIGGERS) && 0 == (object_mask & ZBX_OBJ_ITEMS))
	{
		fprintf(stderr, "zabbix-config: package object_order includes triggers without items\n");
		return FAIL;
	}

	return SUCCEED;
}

static int	zbx_config_parse_object_order(const char *json, const zbx_config_object_t **objects_out,
		size_t max_objects, size_t *object_count_out, unsigned int *object_mask_out)
{
	const char		*p;
	unsigned int	object_mask = 0;
	unsigned int	seen_mask = 0;
	size_t		i, object_count = 0;

	if (NULL == object_count_out || NULL == object_mask_out)
		return FAIL;

	p = zbx_config_find_json_key(json, "object_order");

	if (NULL == p || NULL == (p = strchr(p, '[')))
		return FAIL;

	p++;

	for (;;)
	{
		char	name[64];
		size_t	name_len = 0;
		int	matched = 0;

		p = zbx_config_skip_ws(p);

		if (']' == *p)
			break;

		if ('"' != *p)
			return FAIL;

		p++;

		while ('\0' != *p && '"' != *p)
		{
			if ('\\' == *p || sizeof(name) - 1 <= name_len)
				return FAIL;

			name[name_len++] = *p++;
		}

		if ('"' != *p)
			return FAIL;

		name[name_len] = '\0';
		p++;

		for (i = 0; i < sizeof(zbx_config_objects) / sizeof(zbx_config_objects[0]); i++)
		{
			if (0 == strcmp(name, zbx_config_objects[i].name))
			{
				if (0 != (object_mask & zbx_config_objects[i].bit))
				{
					fprintf(stderr, "zabbix-config: duplicate object '%s' in object_order\n", name);
					return FAIL;
				}

				object_mask |= zbx_config_objects[i].bit;

				if (0 == strcmp(name, "templates") && 0 == (seen_mask & ZBX_OBJ_TEMPLATE_GROUPS))
				{
					fprintf(stderr,
						"zabbix-config: package object_order places templates before template_groups\n");
					return FAIL;
				}

				if (0 == strcmp(name, "hosts") && 0 == (seen_mask & ZBX_OBJ_HOST_GROUPS))
				{
					fprintf(stderr,
						"zabbix-config: package object_order places hosts before host_groups\n");
					return FAIL;
				}

				if (0 == strcmp(name, "items") &&
						(0 == (seen_mask & ZBX_OBJ_TEMPLATES) || 0 == (seen_mask & ZBX_OBJ_HOSTS)))
				{
					fprintf(stderr,
						"zabbix-config: package object_order places items before templates and hosts\n");
					return FAIL;
				}

				if (0 == strcmp(name, "triggers") && 0 == (seen_mask & ZBX_OBJ_ITEMS))
				{
					fprintf(stderr,
						"zabbix-config: package object_order places triggers before items\n");
					return FAIL;
				}

				if (NULL != objects_out)
				{
					if (object_count >= max_objects)
						return FAIL;

					objects_out[object_count] = &zbx_config_objects[i];
				}

				seen_mask |= zbx_config_objects[i].bit;
				object_count++;
				matched = 1;
				break;
			}
		}

		if (0 == matched)
		{
			fprintf(stderr, "zabbix-config: unsupported object '%s' in object_order\n", name);
			return FAIL;
		}

		p = zbx_config_skip_ws(p);

		if (',' == *p)
		{
			p++;
			continue;
		}

		if (']' == *p)
			break;

		return FAIL;
	}

	*object_count_out = object_count;
	*object_mask_out = object_mask;
	return SUCCEED;
}

static const char	*zbx_config_find_object_array(const char *json, const char *name)
{
	const char	*p;
	char		pattern[128];

	if (snprintf(pattern, sizeof(pattern), "\"%s\"", name) >= (int)sizeof(pattern))
		return NULL;

	p = json;

	while (NULL != (p = strstr(p, pattern)))
	{
		const char	*value;

		if (NULL == (value = strchr(p, ':')))
			return NULL;

		value = zbx_config_skip_ws(value + 1);

		if ('[' == *value)
			return value;

		p += strlen(pattern);
	}

	return NULL;
}

static int	zbx_config_object_array_is_empty(const char *json, const char *name, int *empty_out)
{
	const char	*p;

	if (NULL == empty_out)
		return FAIL;

	p = zbx_config_find_object_array(json, name);

	if (NULL == p)
		return FAIL;

	p = zbx_config_skip_ws(p + 1);
	*empty_out = (']' == *p ? 1 : 0);

	return SUCCEED;
}

static int	zbx_config_next_array_object(const char **cursor, zbx_config_buf_t *object_json, int *done)
{
	const char	*p, *start;
	int		depth = 0, in_string = 0, escaped = 0;

	if (NULL == cursor || NULL == *cursor || NULL == object_json || NULL == done)
		return FAIL;

	memset(object_json, 0, sizeof(*object_json));
	p = zbx_config_skip_ws(*cursor);

	if (']' == *p)
	{
		*cursor = p;
		*done = 1;
		return SUCCEED;
	}

	if ('{' != *p)
		return FAIL;

	start = p;

	for (; '\0' != *p; p++)
	{
		if (0 != in_string)
		{
			if (0 != escaped)
			{
				escaped = 0;
				continue;
			}

			if ('\\' == *p)
			{
				escaped = 1;
				continue;
			}

			if ('"' == *p)
				in_string = 0;

			continue;
		}

		if ('"' == *p)
		{
			in_string = 1;
			continue;
		}

		if ('{' == *p)
		{
			depth++;
			continue;
		}

		if ('}' == *p)
		{
			depth--;

			if (0 == depth)
			{
				size_t	len = (size_t)(p - start + 1);

				if (SUCCEED != zbx_config_buf_init(object_json, len + 1))
					return FAIL;

				memcpy(object_json->data, start, len);
				object_json->data[len] = '\0';
				object_json->len = len;
				*cursor = p + 1;
				*done = 0;
				return SUCCEED;
			}
		}
	}

	return FAIL;
}

static int	zbx_config_run_db_command(const zbx_config_import_opts_t *opts, const char *db_engine,
		const char *sql)
{
	zbx_config_buf_t	cmd;
	FILE			*fp = NULL;
	char			line[256];
	int			status;
	char			tmp_path[] = "/tmp/zabbix-config-sql-XXXXXX";
	int			tmp_fd = -1;
	FILE			*tmp_fp = NULL;

	memset(&cmd, 0, sizeof(cmd));

	tmp_fd = mkstemp(tmp_path);

	if (-1 == tmp_fd)
	{
		fprintf(stderr, "zabbix-config: failed to create temporary SQL file\n");
		goto fail;
	}

	if (SUCCEED != zbx_config_buf_init(&cmd, 1024))
		goto fail;

	if (0 == strcmp(db_engine, "postgresql"))
	{
		if (SUCCEED != zbx_config_buf_appendf(&cmd, "PGPASSWORD=") ||
				SUCCEED != zbx_config_buf_append_shell_quoted(&cmd, opts->db_pass) ||
				SUCCEED != zbx_config_buf_appendf(&cmd, " psql -h ") ||
				SUCCEED != zbx_config_buf_append_shell_quoted(&cmd, opts->db_host) ||
				SUCCEED != zbx_config_buf_appendf(&cmd, " -p ") ||
				SUCCEED != zbx_config_buf_append_shell_quoted(&cmd, opts->db_port) ||
				SUCCEED != zbx_config_buf_appendf(&cmd, " -U ") ||
				SUCCEED != zbx_config_buf_append_shell_quoted(&cmd, opts->db_user) ||
				SUCCEED != zbx_config_buf_appendf(&cmd, " -d ") ||
				SUCCEED != zbx_config_buf_append_shell_quoted(&cmd, opts->db_name) ||
				SUCCEED != zbx_config_buf_appendf(&cmd, " -v ON_ERROR_STOP=1 -q -f ") ||
				SUCCEED != zbx_config_buf_append_shell_quoted(&cmd, tmp_path))
		{
			goto fail;
		}
	}
	else if (0 == strcmp(db_engine, "mysql"))
	{
		if (SUCCEED != zbx_config_buf_appendf(&cmd, "MYSQL_PWD=") ||
				SUCCEED != zbx_config_buf_append_shell_quoted(&cmd, opts->db_pass) ||
				SUCCEED != zbx_config_buf_appendf(&cmd, " mysql --batch --raw --skip-column-names -h ") ||
				SUCCEED != zbx_config_buf_append_shell_quoted(&cmd, opts->db_host) ||
				SUCCEED != zbx_config_buf_appendf(&cmd, " -P ") ||
				SUCCEED != zbx_config_buf_append_shell_quoted(&cmd, opts->db_port) ||
				SUCCEED != zbx_config_buf_appendf(&cmd, " -u ") ||
				SUCCEED != zbx_config_buf_append_shell_quoted(&cmd, opts->db_user) ||
				SUCCEED != zbx_config_buf_appendf(&cmd, " ") ||
				SUCCEED != zbx_config_buf_append_shell_quoted(&cmd, opts->db_name) ||
				SUCCEED != zbx_config_buf_appendf(&cmd, " < ") ||
				SUCCEED != zbx_config_buf_append_shell_quoted(&cmd, tmp_path))
		{
			goto fail;
		}
	}
	else
	{
		goto fail;
	}

	tmp_fp = fdopen(tmp_fd, "w");

	if (NULL == tmp_fp)
	{
		fprintf(stderr, "zabbix-config: failed to open temporary SQL file\n");
		goto fail;
	}

	if (0 == fwrite(sql, 1, strlen(sql), tmp_fp))
	{
		fprintf(stderr, "zabbix-config: failed to write temporary SQL file\n");
		goto fail;
	}

	if (0 != fclose(tmp_fp))
	{
		fprintf(stderr, "zabbix-config: failed to close temporary SQL file\n");
		goto fail;
	}

	tmp_fp = NULL;

	if (NULL == (fp = popen(cmd.data, "r")))
	{
		fprintf(stderr, "zabbix-config: failed to start DB command\n");
		goto fail;
	}

	while (NULL != fgets(line, sizeof(line), fp))
		;

	status = pclose(fp);
	fp = NULL;

	if (!WIFEXITED(status) || 0 != WEXITSTATUS(status))
	{
		fprintf(stderr, "zabbix-config: DB command failed\n");
		goto fail;
	}

	zbx_config_buf_free(&cmd);
	unlink(tmp_path);
	return SUCCEED;
fail:
	if (NULL != fp)
		(void)pclose(fp);

	if (NULL != tmp_fp)
		(void)fclose(tmp_fp);
	else if (-1 != tmp_fd)
		(void)close(tmp_fd);

	unlink(tmp_path);
	zbx_config_buf_free(&cmd);
	return FAIL;
}

static int	zbx_config_detect_target_db(const zbx_config_import_opts_t *opts, char *db_engine_out,
		size_t db_engine_out_size)
{
	if (NULL == opts->db_host || NULL == opts->db_port || NULL == opts->db_name || NULL == opts->db_user ||
			NULL == opts->db_pass)
	{
		fprintf(stderr,
			"zabbix-config: --db-host, --db-port, --db-name, --db-user and --db-pass are required for non-empty import\n");
		return FAIL;
	}

	if (NULL != db_engine_out && 11 <= db_engine_out_size)
	{
		if (SUCCEED == zbx_config_run_db_command(opts, "postgresql", "select 1"))
		{
			strcpy(db_engine_out, "postgresql");
			return SUCCEED;
		}

		if (SUCCEED == zbx_config_run_db_command(opts, "mysql", "select 1"))
		{
			strcpy(db_engine_out, "mysql");
			return SUCCEED;
		}
	}

	fprintf(stderr, "zabbix-config: failed to detect target DB engine from connection options\n");
	return FAIL;
}

static int	zbx_config_apply_group_array(const char *json, const char *name, int group_type,
		const zbx_config_import_opts_t *opts, const char *db_engine)
{
	const char	*p;
	int		done = 0;

	p = zbx_config_find_object_array(json, name);

	if (NULL == p)
		return FAIL;

	p++;
	p = zbx_config_skip_ws(p);

	while ('\0' != *p)
	{
		zbx_config_buf_t	object_json;
		char			uuid[64];
		char			group_name[512];
		zbx_config_buf_t	sql;

		if (SUCCEED != zbx_config_next_array_object(&p, &object_json, &done))
			return FAIL;

		if (0 != done)
			break;

		if (SUCCEED != zbx_config_parse_json_string_token(object_json.data, "uuid", uuid, sizeof(uuid)) ||
				SUCCEED != zbx_config_parse_json_string_token(object_json.data, "name", group_name,
				sizeof(group_name)))
		{
			zbx_config_buf_free(&object_json);
			fprintf(stderr, "zabbix-config: invalid group object in '%s'\n", name);
			return FAIL;
		}

		memset(&sql, 0, sizeof(sql));

		if (SUCCEED != zbx_config_buf_init(&sql, 1024))
		{
			zbx_config_buf_free(&object_json);
			return FAIL;
		}

		if (0 == strcmp(db_engine, "postgresql"))
		{
			if (SUCCEED != zbx_config_buf_appendf(&sql,
					"with updated_name as ("
					" update hstgrp set uuid=") ||
				SUCCEED != zbx_config_buf_append_sql_quoted(&sql, uuid) ||
				SUCCEED != zbx_config_buf_appendf(&sql,
					", flags=0 where type=%d and name=", group_type) ||
				SUCCEED != zbx_config_buf_append_sql_quoted(&sql, group_name) ||
				SUCCEED != zbx_config_buf_appendf(&sql,
					" returning groupid),"
					" updated_uuid as ("
					" update hstgrp set name=") ||
				SUCCEED != zbx_config_buf_append_sql_quoted(&sql, group_name) ||
				SUCCEED != zbx_config_buf_appendf(&sql,
					", flags=0, type=%d where uuid=", group_type) ||
				SUCCEED != zbx_config_buf_append_sql_quoted(&sql, uuid) ||
				SUCCEED != zbx_config_buf_appendf(&sql,
					" and not exists (select 1 from updated_name) returning groupid),"
					" inserted as ("
					" insert into hstgrp (groupid,name,flags,uuid,type)"
					" select nextid.groupid,") ||
				SUCCEED != zbx_config_buf_append_sql_quoted(&sql, group_name) ||
				SUCCEED != zbx_config_buf_appendf(&sql,
					",0,") ||
				SUCCEED != zbx_config_buf_append_sql_quoted(&sql, uuid) ||
				SUCCEED != zbx_config_buf_appendf(&sql,
					",%d from (select coalesce(max(groupid),0)+1 as groupid from hstgrp) nextid"
					" where not exists (select 1 from updated_name)"
					" and not exists (select 1 from updated_uuid))"
					" select 1",
					group_type))
			{
				zbx_config_buf_free(&sql);
				zbx_config_buf_free(&object_json);
				return FAIL;
			}
		}
		else if (0 == strcmp(db_engine, "mysql"))
		{
			if (SUCCEED != zbx_config_buf_appendf(&sql, "update hstgrp set uuid=") ||
				SUCCEED != zbx_config_buf_append_sql_quoted(&sql, uuid) ||
				SUCCEED != zbx_config_buf_appendf(&sql, ", flags=0 where type=%d and name=", group_type) ||
				SUCCEED != zbx_config_buf_append_sql_quoted(&sql, group_name) ||
				SUCCEED != zbx_config_buf_appendf(&sql, "; insert into hstgrp (groupid,name,flags,uuid,type) ") ||
				SUCCEED != zbx_config_buf_appendf(&sql,
					"select nextid.groupid,") ||
				SUCCEED != zbx_config_buf_append_sql_quoted(&sql, group_name) ||
				SUCCEED != zbx_config_buf_appendf(&sql, ",0,") ||
				SUCCEED != zbx_config_buf_append_sql_quoted(&sql, uuid) ||
				SUCCEED != zbx_config_buf_appendf(&sql,
					",%d from (select coalesce(max(groupid),0)+1 as groupid from hstgrp) nextid where not exists (select 1 from hstgrp where uuid=", group_type) ||
				SUCCEED != zbx_config_buf_append_sql_quoted(&sql, uuid) ||
				SUCCEED != zbx_config_buf_appendf(&sql, ") and not exists (select 1 from hstgrp where type=%d and name=", group_type) ||
				SUCCEED != zbx_config_buf_append_sql_quoted(&sql, group_name) ||
				SUCCEED != zbx_config_buf_appendf(&sql,
					"); update hstgrp set name=") ||
				SUCCEED != zbx_config_buf_append_sql_quoted(&sql, group_name) ||
				SUCCEED != zbx_config_buf_appendf(&sql, ", flags=0, type=%d where uuid=", group_type) ||
				SUCCEED != zbx_config_buf_append_sql_quoted(&sql, uuid) ||
				SUCCEED != zbx_config_buf_appendf(&sql, " and not exists (select 1 from hstgrp where type=%d and name=", group_type) ||
				SUCCEED != zbx_config_buf_append_sql_quoted(&sql, group_name) ||
				SUCCEED != zbx_config_buf_appendf(&sql, ")"))
			{
				zbx_config_buf_free(&sql);
				zbx_config_buf_free(&object_json);
				return FAIL;
			}
		}

		if (SUCCEED != zbx_config_run_db_command(opts, db_engine, sql.data))
		{
			zbx_config_buf_free(&sql);
			zbx_config_buf_free(&object_json);
			fprintf(stderr, "zabbix-config: failed applying group '%s' from '%s'\n", group_name, name);
			return FAIL;
		}

		zbx_config_buf_free(&sql);
		zbx_config_buf_free(&object_json);

		p = zbx_config_skip_ws(p);

		if (',' == *p)
			p++;

		p = zbx_config_skip_ws(p);
	}

	return SUCCEED;
}

static int	zbx_config_append_host_group_insert_statements(zbx_config_buf_t *sql,
		const char *object_json, const char *host, int group_type, int templates)
{
	const char	*p;

	if (NULL == sql)
		return FAIL;

	p = zbx_config_find_object_array(object_json, "group_uuids");

	if (NULL == p)
		return FAIL;

	p = zbx_config_skip_ws(p + 1);

	while (']' != *p)
	{
		char	value[256];
		size_t	len = 0;

		if ('"' != *p)
			return FAIL;

		p++;

		while ('\0' != *p && '"' != *p)
		{
			if ('\\' == *p || sizeof(value) - 1 <= len)
				return FAIL;

			value[len++] = *p++;
		}

		if ('"' != *p)
			return FAIL;

		value[len] = '\0';
		p++;

		if (SUCCEED != zbx_config_buf_appendf(sql,
				"; insert into hosts_groups (hostgroupid,hostid,groupid) "
				"select nextid.hostgroupid, h.hostid, g.groupid "
				"from (select coalesce(max(hostgroupid),0)+1 as hostgroupid from hosts_groups) nextid,"
				" hosts h join hstgrp g on g.type=%d and g.uuid=", group_type) ||
				SUCCEED != zbx_config_buf_append_sql_quoted(sql, value) ||
				SUCCEED != zbx_config_buf_appendf(sql,
				(0 != templates ? " where h.status=3 and h.host=" : " where h.status<>3 and h.host=")) ||
				SUCCEED != zbx_config_buf_append_sql_quoted(sql, host) ||
				SUCCEED != zbx_config_buf_appendf(sql,
				" and not exists (select 1 from hosts_groups hg where hg.hostid=h.hostid and hg.groupid=g.groupid)"))
		{
			return FAIL;
		}

		p = zbx_config_skip_ws(p);

		if (',' == *p)
		{
			p = zbx_config_skip_ws(p + 1);
			continue;
		}

		if (']' != *p)
			return FAIL;
	}

	return SUCCEED;
}

static int	zbx_config_apply_template_array(const char *json, const char *name,
		const zbx_config_import_opts_t *opts, const char *db_engine)
{
	const char	*p;
	int		done = 0;

	p = zbx_config_find_object_array(json, name);

	if (NULL == p)
		return FAIL;

	p = zbx_config_skip_ws(p + 1);

	while ('\0' != *p)
	{
		zbx_config_buf_t	object_json;
		zbx_config_buf_t	sql;
		char			uuid[64];
		char			host[256];
		char			host_name[256];

		if (SUCCEED != zbx_config_next_array_object(&p, &object_json, &done))
			return FAIL;

		if (0 != done)
			break;

		if (SUCCEED != zbx_config_parse_json_string_token(object_json.data, "uuid", uuid, sizeof(uuid)) ||
				SUCCEED != zbx_config_parse_json_string_token(object_json.data, "host", host, sizeof(host)) ||
				SUCCEED != zbx_config_parse_json_string_token(object_json.data, "name", host_name,
				sizeof(host_name)))
		{
			zbx_config_buf_free(&object_json);
			fprintf(stderr, "zabbix-config: invalid template object in '%s'\n", name);
			return FAIL;
		}

		memset(&sql, 0, sizeof(sql));

		if (SUCCEED != zbx_config_buf_init(&sql, 2048))
		{
			zbx_config_buf_free(&sql);
			zbx_config_buf_free(&object_json);
			return FAIL;
		}

		if (SUCCEED != zbx_config_buf_appendf(&sql,
				"update hosts set uuid=") ||
				SUCCEED != zbx_config_buf_append_sql_quoted(&sql, uuid) ||
				SUCCEED != zbx_config_buf_appendf(&sql,
				", name=") ||
				SUCCEED != zbx_config_buf_append_sql_quoted(&sql, host_name) ||
				SUCCEED != zbx_config_buf_appendf(&sql,
				", status=3, flags=0, name_upper=upper(") ||
				SUCCEED != zbx_config_buf_append_sql_quoted(&sql, host) ||
				SUCCEED != zbx_config_buf_appendf(&sql,
				") where status=3 and host=") ||
				SUCCEED != zbx_config_buf_append_sql_quoted(&sql, host) ||
				SUCCEED != zbx_config_buf_appendf(&sql,
				"; update hosts set host=") ||
				SUCCEED != zbx_config_buf_append_sql_quoted(&sql, host) ||
				SUCCEED != zbx_config_buf_appendf(&sql,
				", name=") ||
				SUCCEED != zbx_config_buf_append_sql_quoted(&sql, host_name) ||
				SUCCEED != zbx_config_buf_appendf(&sql,
				", status=3, flags=0, name_upper=upper(") ||
				SUCCEED != zbx_config_buf_append_sql_quoted(&sql, host) ||
				SUCCEED != zbx_config_buf_appendf(&sql,
				") where uuid=") ||
				SUCCEED != zbx_config_buf_append_sql_quoted(&sql, uuid) ||
				SUCCEED != zbx_config_buf_appendf(&sql,
				" and status=3 and not exists (select 1 from hosts where status=3 and host=") ||
				SUCCEED != zbx_config_buf_append_sql_quoted(&sql, host) ||
				SUCCEED != zbx_config_buf_appendf(&sql,
				"); insert into hosts (hostid,host,status,name,flags,uuid,name_upper) "
				"select nextid.hostid,") ||
				SUCCEED != zbx_config_buf_append_sql_quoted(&sql, host) ||
				SUCCEED != zbx_config_buf_appendf(&sql, ",3,") ||
				SUCCEED != zbx_config_buf_append_sql_quoted(&sql, host_name) ||
				SUCCEED != zbx_config_buf_appendf(&sql, ",0,") ||
				SUCCEED != zbx_config_buf_append_sql_quoted(&sql, uuid) ||
				SUCCEED != zbx_config_buf_appendf(&sql, ",upper(") ||
				SUCCEED != zbx_config_buf_append_sql_quoted(&sql, host) ||
				SUCCEED != zbx_config_buf_appendf(&sql,
				") from (select coalesce(max(hostid),0)+1 as hostid from hosts) nextid "
				"where not exists (select 1 from hosts where status=3 and host=") ||
				SUCCEED != zbx_config_buf_append_sql_quoted(&sql, host) ||
				SUCCEED != zbx_config_buf_appendf(&sql,
				") and not exists (select 1 from hosts where status=3 and uuid=") ||
				SUCCEED != zbx_config_buf_append_sql_quoted(&sql, uuid) ||
				SUCCEED != zbx_config_buf_appendf(&sql,
				"); delete from hosts_groups where hostid in (select hostid from hosts where status=3 and host=") ||
				SUCCEED != zbx_config_buf_append_sql_quoted(&sql, host) ||
				SUCCEED != zbx_config_buf_appendf(&sql,
				") and groupid in (select groupid from hstgrp where type=1)") )
		{
			zbx_config_buf_free(&sql);
			zbx_config_buf_free(&object_json);
			return FAIL;
		}

		if (SUCCEED != zbx_config_append_host_group_insert_statements(&sql, object_json.data, host, 1, 1))
		{
			zbx_config_buf_free(&sql);
			zbx_config_buf_free(&object_json);
			return FAIL;
		}

		if (SUCCEED != zbx_config_run_db_command(opts, db_engine, sql.data))
		{
			zbx_config_buf_free(&sql);
			zbx_config_buf_free(&object_json);
			fprintf(stderr, "zabbix-config: failed applying template '%s'\n", host);
			return FAIL;
		}

		zbx_config_buf_free(&sql);
		zbx_config_buf_free(&object_json);
		p = zbx_config_skip_ws(p);

		if (',' == *p)
			p = zbx_config_skip_ws(p + 1);
	}

	return SUCCEED;
}

static int	zbx_config_apply_host_array(const char *json, const char *name,
		const zbx_config_import_opts_t *opts, const char *db_engine)
{
	const char	*p;
	int		done = 0;

	p = zbx_config_find_object_array(json, name);

	if (NULL == p)
		return FAIL;

	p = zbx_config_skip_ws(p + 1);

	while ('\0' != *p)
	{
		zbx_config_buf_t	object_json;
		zbx_config_buf_t	sql;
		char			uuid[64];
		char			host[256];
		char			host_name[256];

		if (SUCCEED != zbx_config_next_array_object(&p, &object_json, &done))
			return FAIL;

		if (0 != done)
			break;

		if (SUCCEED != zbx_config_parse_json_string_token(object_json.data, "uuid", uuid, sizeof(uuid)) ||
				SUCCEED != zbx_config_parse_json_string_token(object_json.data, "host", host, sizeof(host)) ||
				SUCCEED != zbx_config_parse_json_string_token(object_json.data, "name", host_name,
				sizeof(host_name)))
		{
			zbx_config_buf_free(&object_json);
			fprintf(stderr, "zabbix-config: invalid host object in '%s'\n", name);
			return FAIL;
		}

		memset(&sql, 0, sizeof(sql));

		if (SUCCEED != zbx_config_buf_init(&sql, 2048))
		{
			zbx_config_buf_free(&sql);
			zbx_config_buf_free(&object_json);
			return FAIL;
		}

		if (SUCCEED != zbx_config_buf_appendf(&sql,
				"update hosts set uuid=") ||
				SUCCEED != zbx_config_buf_append_sql_quoted(&sql, uuid) ||
				SUCCEED != zbx_config_buf_appendf(&sql,
				", name=") ||
				SUCCEED != zbx_config_buf_append_sql_quoted(&sql, host_name) ||
				SUCCEED != zbx_config_buf_appendf(&sql,
				", name_upper=upper(") ||
				SUCCEED != zbx_config_buf_append_sql_quoted(&sql, host) ||
				SUCCEED != zbx_config_buf_appendf(&sql,
				") where status<>3 and host=") ||
				SUCCEED != zbx_config_buf_append_sql_quoted(&sql, host) ||
				SUCCEED != zbx_config_buf_appendf(&sql,
				"; update hosts set host=") ||
				SUCCEED != zbx_config_buf_append_sql_quoted(&sql, host) ||
				SUCCEED != zbx_config_buf_appendf(&sql,
				", name=") ||
				SUCCEED != zbx_config_buf_append_sql_quoted(&sql, host_name) ||
				SUCCEED != zbx_config_buf_appendf(&sql,
				", name_upper=upper(") ||
				SUCCEED != zbx_config_buf_append_sql_quoted(&sql, host) ||
				SUCCEED != zbx_config_buf_appendf(&sql,
				") where uuid=") ||
				SUCCEED != zbx_config_buf_append_sql_quoted(&sql, uuid) ||
				SUCCEED != zbx_config_buf_appendf(&sql,
				" and status<>3 and not exists (select 1 from hosts where status<>3 and host=") ||
				SUCCEED != zbx_config_buf_append_sql_quoted(&sql, host) ||
				SUCCEED != zbx_config_buf_appendf(&sql,
				"); insert into hosts (hostid,host,status,name,flags,uuid,name_upper) "
				"select nextid.hostid,") ||
				SUCCEED != zbx_config_buf_append_sql_quoted(&sql, host) ||
				SUCCEED != zbx_config_buf_appendf(&sql, ",0,") ||
				SUCCEED != zbx_config_buf_append_sql_quoted(&sql, host_name) ||
				SUCCEED != zbx_config_buf_appendf(&sql, ",0,") ||
				SUCCEED != zbx_config_buf_append_sql_quoted(&sql, uuid) ||
				SUCCEED != zbx_config_buf_appendf(&sql, ",upper(") ||
				SUCCEED != zbx_config_buf_append_sql_quoted(&sql, host) ||
				SUCCEED != zbx_config_buf_appendf(&sql,
				") from (select coalesce(max(hostid),0)+1 as hostid from hosts) nextid "
				"where not exists (select 1 from hosts where status<>3 and host=") ||
				SUCCEED != zbx_config_buf_append_sql_quoted(&sql, host) ||
				SUCCEED != zbx_config_buf_appendf(&sql,
				") and not exists (select 1 from hosts where status<>3 and uuid=") ||
				SUCCEED != zbx_config_buf_append_sql_quoted(&sql, uuid) ||
				SUCCEED != zbx_config_buf_appendf(&sql,
				"); delete from hosts_groups where hostid in (select hostid from hosts where status<>3 and host=") ||
				SUCCEED != zbx_config_buf_append_sql_quoted(&sql, host) ||
				SUCCEED != zbx_config_buf_appendf(&sql,
				") and groupid in (select groupid from hstgrp where type=0)"))
		{
			zbx_config_buf_free(&sql);
			zbx_config_buf_free(&object_json);
			return FAIL;
		}

		if (SUCCEED != zbx_config_append_host_group_insert_statements(&sql, object_json.data, host, 0, 0))
		{
			zbx_config_buf_free(&sql);
			zbx_config_buf_free(&object_json);
			return FAIL;
		}

		if (SUCCEED != zbx_config_run_db_command(opts, db_engine, sql.data))
		{
			zbx_config_buf_free(&sql);
			zbx_config_buf_free(&object_json);
			fprintf(stderr, "zabbix-config: failed applying host '%s'\n", host);
			return FAIL;
		}

		zbx_config_buf_free(&sql);
		zbx_config_buf_free(&object_json);
		p = zbx_config_skip_ws(p);

		if (',' == *p)
			p = zbx_config_skip_ws(p + 1);
	}

	return SUCCEED;
}

static int	zbx_config_apply_item_array(const char *json, const char *name,
		const zbx_config_import_opts_t *opts, const char *db_engine)
{
	const char	*p;
	int		done = 0;
	const size_t	batch_size = 100;
	zbx_config_buf_t	batch_sql;
	size_t		batch_count = 0;

	(void)db_engine;
	memset(&batch_sql, 0, sizeof(batch_sql));
	p = zbx_config_find_object_array(json, name);

	if (NULL == p)
		return FAIL;

	p = zbx_config_skip_ws(p + 1);

	while ('\0' != *p)
	{
		zbx_config_buf_t	object_json;
		zbx_config_buf_t	sql;
		char			uuid[64];
		char			host_uuid[64];
		char			item_key[4096];
		char			item_name[4096];
		int			type;
		int			value_type;
		int			status;

		if (SUCCEED != zbx_config_next_array_object(&p, &object_json, &done))
			return FAIL;

		if (0 != done)
			break;

		if (SUCCEED != zbx_config_parse_json_string_token(object_json.data, "uuid", uuid, sizeof(uuid)))
		{
			zbx_config_buf_free(&object_json);
			fprintf(stderr, "zabbix-config: invalid item object '%s': failed parsing uuid\n", name);
			return FAIL;
		}

		if (SUCCEED != zbx_config_parse_json_string_token(object_json.data, "host_uuid", host_uuid,
				sizeof(host_uuid)))
		{
			zbx_config_buf_free(&object_json);
			fprintf(stderr, "zabbix-config: invalid item object '%s': failed parsing host_uuid\n", name);
			return FAIL;
		}

		if (SUCCEED != zbx_config_parse_json_string_token(object_json.data, "key_", item_key,
				sizeof(item_key)))
		{
			zbx_config_buf_free(&object_json);
			fprintf(stderr, "zabbix-config: invalid item object '%s': failed parsing key_\n", name);
			return FAIL;
		}

		if (SUCCEED != zbx_config_parse_json_string_token(object_json.data, "name", item_name,
				sizeof(item_name)))
		{
			zbx_config_buf_free(&object_json);
			fprintf(stderr, "zabbix-config: invalid item object '%s': failed parsing name\n", name);
			return FAIL;
		}

		if (SUCCEED != zbx_config_parse_json_int(object_json.data, "type", &type))
		{
			zbx_config_buf_free(&object_json);
			fprintf(stderr, "zabbix-config: invalid item object '%s': failed parsing type\n", name);
			return FAIL;
		}

		if (SUCCEED != zbx_config_parse_json_int(object_json.data, "value_type", &value_type))
		{
			zbx_config_buf_free(&object_json);
			fprintf(stderr, "zabbix-config: invalid item object '%s': failed parsing value_type\n", name);
			return FAIL;
		}

		if (SUCCEED != zbx_config_parse_json_int(object_json.data, "status", &status))
		{
			zbx_config_buf_free(&object_json);
			fprintf(stderr, "zabbix-config: invalid item object '%s': failed parsing status\n", name);
			return FAIL;
		}

		memset(&sql, 0, sizeof(sql));

		if (SUCCEED != zbx_config_buf_init(&sql, 3072))
		{
			zbx_config_buf_free(&sql);
			zbx_config_buf_free(&object_json);
			return FAIL;
		}

		if (SUCCEED != zbx_config_buf_appendf(&sql,
				"update items set hostid=(select h.hostid from hosts h where h.uuid=") ||
				SUCCEED != zbx_config_buf_append_sql_quoted(&sql, host_uuid) ||
				SUCCEED != zbx_config_buf_appendf(&sql,
				" limit 1), key_=") ||
				SUCCEED != zbx_config_buf_append_sql_quoted(&sql, item_key) ||
				SUCCEED != zbx_config_buf_appendf(&sql,
				", name=") ||
				SUCCEED != zbx_config_buf_append_sql_quoted(&sql, item_name) ||
				SUCCEED != zbx_config_buf_appendf(&sql,
				", type=%d, value_type=%d, status=%d, flags=0 where uuid=", type, value_type, status) ||
				SUCCEED != zbx_config_buf_append_sql_quoted(&sql, uuid) ||
				SUCCEED != zbx_config_buf_appendf(&sql,
				"; update items set uuid=") ||
				SUCCEED != zbx_config_buf_append_sql_quoted(&sql, uuid) ||
				SUCCEED != zbx_config_buf_appendf(&sql,
				", name=") ||
				SUCCEED != zbx_config_buf_append_sql_quoted(&sql, item_name) ||
				SUCCEED != zbx_config_buf_appendf(&sql,
				", type=%d, value_type=%d, status=%d, flags=0 where key_=", type, value_type, status) ||
				SUCCEED != zbx_config_buf_append_sql_quoted(&sql, item_key) ||
				SUCCEED != zbx_config_buf_appendf(&sql,
				" and hostid=(select h.hostid from hosts h where h.uuid=") ||
				SUCCEED != zbx_config_buf_append_sql_quoted(&sql, host_uuid) ||
				SUCCEED != zbx_config_buf_appendf(&sql,
				" limit 1) and not exists (select 1 from items where uuid=") ||
				SUCCEED != zbx_config_buf_append_sql_quoted(&sql, uuid) ||
				SUCCEED != zbx_config_buf_appendf(&sql,
				"); insert into items (itemid,hostid,key_,name,type,value_type,status,flags,uuid) "
				"select nextid.itemid,h.hostid,") ||
				SUCCEED != zbx_config_buf_append_sql_quoted(&sql, item_key) ||
				SUCCEED != zbx_config_buf_appendf(&sql, ",") ||
				SUCCEED != zbx_config_buf_append_sql_quoted(&sql, item_name) ||
				SUCCEED != zbx_config_buf_appendf(&sql, ",%d,%d,%d,0,", type, value_type, status) ||
				SUCCEED != zbx_config_buf_append_sql_quoted(&sql, uuid) ||
				SUCCEED != zbx_config_buf_appendf(&sql,
				" from (select coalesce(max(itemid),0)+1 as itemid from items) nextid"
				" join hosts h on h.uuid=") ||
				SUCCEED != zbx_config_buf_append_sql_quoted(&sql, host_uuid) ||
				SUCCEED != zbx_config_buf_appendf(&sql,
				" where not exists (select 1 from items where uuid=") ||
				SUCCEED != zbx_config_buf_append_sql_quoted(&sql, uuid) ||
				SUCCEED != zbx_config_buf_appendf(&sql,
				") and not exists (select 1 from items i2 where i2.hostid=h.hostid and i2.key_=") ||
				SUCCEED != zbx_config_buf_append_sql_quoted(&sql, item_key) ||
				SUCCEED != zbx_config_buf_appendf(&sql, ")"))
		{
			zbx_config_buf_free(&sql);
			zbx_config_buf_free(&object_json);
			return FAIL;
		}

		if (0 == batch_count)
		{
			if (SUCCEED != zbx_config_buf_init(&batch_sql, 65536))
			{
				zbx_config_buf_free(&sql);
				zbx_config_buf_free(&object_json);
				return FAIL;
			}

			if (0 == strcmp(db_engine, "postgresql"))
			{
				if (SUCCEED != zbx_config_buf_appendf(&batch_sql, "begin;"))
				{
					zbx_config_buf_free(&batch_sql);
					zbx_config_buf_free(&sql);
					zbx_config_buf_free(&object_json);
					return FAIL;
				}
			}
			else
			{
				if (SUCCEED != zbx_config_buf_appendf(&batch_sql, "start transaction;"))
				{
					zbx_config_buf_free(&batch_sql);
					zbx_config_buf_free(&sql);
					zbx_config_buf_free(&object_json);
					return FAIL;
				}
			}
		}

		if (0 != batch_count && SUCCEED != zbx_config_buf_appendf(&batch_sql, "; "))
		{
			zbx_config_buf_free(&batch_sql);
			zbx_config_buf_free(&sql);
			zbx_config_buf_free(&object_json);
			return FAIL;
		}

		if (SUCCEED != zbx_config_buf_appendf(&batch_sql, "%s", sql.data))
		{
			zbx_config_buf_free(&batch_sql);
			zbx_config_buf_free(&sql);
			zbx_config_buf_free(&object_json);
			return FAIL;
		}

		batch_count++;
		zbx_config_buf_free(&sql);
		zbx_config_buf_free(&object_json);

		if (batch_count >= batch_size)
		{
			if (SUCCEED != zbx_config_buf_appendf(&batch_sql, "; commit;"))
			{
				zbx_config_buf_free(&batch_sql);
				return FAIL;
			}

			if (SUCCEED != zbx_config_run_db_command(opts, db_engine, batch_sql.data))
			{
				zbx_config_buf_free(&batch_sql);
				fprintf(stderr, "zabbix-config: failed applying item batch\n");
				return FAIL;
			}

			zbx_config_buf_free(&batch_sql);
			batch_count = 0;
		}

		p = zbx_config_skip_ws(p);

		if (',' == *p)
			p = zbx_config_skip_ws(p + 1);
	}

	if (0 != batch_count)
	{
		if (SUCCEED != zbx_config_buf_appendf(&batch_sql, "; commit;"))
		{
			zbx_config_buf_free(&batch_sql);
			return FAIL;
		}

		if (SUCCEED != zbx_config_run_db_command(opts, db_engine, batch_sql.data))
		{
			zbx_config_buf_free(&batch_sql);
			fprintf(stderr, "zabbix-config: failed applying item batch\n");
			return FAIL;
		}

		zbx_config_buf_free(&batch_sql);
	}

	return SUCCEED;
}

static int	zbx_config_replace_expression_item_ids(char *expression, size_t expression_size,
		long source_itemid, long target_itemid)
{
	char	old_token[64], new_token[64];
	zbx_config_buf_t	rewritten;
	const char	*p;
	size_t	out_len = 0;
	int	replaced = 0;

	if (NULL == expression || 0 == expression_size)
		return FAIL;

	snprintf(old_token, sizeof(old_token), "{%ld}", source_itemid);
	snprintf(new_token, sizeof(new_token), "{%ld}", target_itemid);

	memset(&rewritten, 0, sizeof(rewritten));

	if (SUCCEED != zbx_config_buf_init(&rewritten, expression_size + 1))
		return FAIL;

	for (p = expression; '\0' != *p;)
	{
		const char	*match = strstr(p, old_token);
		size_t	segment_len = (NULL != match ? (size_t)(match - p) : strlen(p));

		if (out_len + segment_len + strlen(new_token) + 1 > expression_size)
		{
			zbx_config_buf_free(&rewritten);
			return FAIL;
		}

		if (NULL != match)
		{
			memcpy(rewritten.data + out_len, p, segment_len);
			out_len += segment_len;
			memcpy(rewritten.data + out_len, new_token, strlen(new_token));
			out_len += strlen(new_token);
			p = match + strlen(old_token);
			replaced = 1;
		}
		else
		{
			memcpy(rewritten.data + out_len, p, strlen(p));
			out_len += strlen(p);
			break;
		}
	}

	if (0 != replaced)
	{
		rewritten.data[out_len] = '\0';
		strcpy(expression, rewritten.data);
	}

	zbx_config_buf_free(&rewritten);
	return SUCCEED;
}

static int	zbx_config_lookup_itemid(const zbx_config_import_opts_t *opts, const char *db_engine,
		const char *item_uuid, long *itemid_out)
{
	zbx_config_buf_t	cmd;
	zbx_config_buf_t	query;
	FILE			*fp = NULL;
	char			line[256];
	int			status;
	char			*endptr = NULL;
	long			itemid = -1;

	if (NULL == opts || NULL == db_engine || NULL == item_uuid || NULL == itemid_out)
		return FAIL;

	memset(&cmd, 0, sizeof(cmd));
	memset(&query, 0, sizeof(query));

	if (SUCCEED != zbx_config_buf_init(&cmd, 2048) || SUCCEED != zbx_config_buf_init(&query, 512))
		goto fail;

	if (SUCCEED != zbx_config_buf_appendf(&query, "select itemid from items where uuid=") ||
			SUCCEED != zbx_config_buf_append_sql_quoted(&query, item_uuid) ||
			SUCCEED != zbx_config_buf_appendf(&query, " limit 1"))
		goto fail;

	if (0 == strcmp(db_engine, "postgresql"))
	{
		if (SUCCEED != zbx_config_buf_appendf(&cmd, "PGPASSWORD=") ||
				SUCCEED != zbx_config_buf_append_shell_quoted(&cmd, opts->db_pass) ||
				SUCCEED != zbx_config_buf_appendf(&cmd, " psql -h ") ||
				SUCCEED != zbx_config_buf_append_shell_quoted(&cmd, opts->db_host) ||
				SUCCEED != zbx_config_buf_appendf(&cmd, " -p ") ||
				SUCCEED != zbx_config_buf_append_shell_quoted(&cmd, opts->db_port) ||
				SUCCEED != zbx_config_buf_appendf(&cmd, " -U ") ||
				SUCCEED != zbx_config_buf_append_shell_quoted(&cmd, opts->db_user) ||
				SUCCEED != zbx_config_buf_appendf(&cmd, " -d ") ||
				SUCCEED != zbx_config_buf_append_shell_quoted(&cmd, opts->db_name) ||
				SUCCEED != zbx_config_buf_appendf(&cmd, " -At -c ") ||
				SUCCEED != zbx_config_buf_append_shell_quoted(&cmd, query.data))
			goto fail;
	}
	else if (0 == strcmp(db_engine, "mysql"))
	{
		if (SUCCEED != zbx_config_buf_appendf(&cmd, "MYSQL_PWD=") ||
				SUCCEED != zbx_config_buf_append_shell_quoted(&cmd, opts->db_pass) ||
				SUCCEED != zbx_config_buf_appendf(&cmd, " mysql --batch --raw --skip-column-names -h ") ||
				SUCCEED != zbx_config_buf_append_shell_quoted(&cmd, opts->db_host) ||
				SUCCEED != zbx_config_buf_appendf(&cmd, " -P ") ||
				SUCCEED != zbx_config_buf_append_shell_quoted(&cmd, opts->db_port) ||
				SUCCEED != zbx_config_buf_appendf(&cmd, " -u ") ||
				SUCCEED != zbx_config_buf_append_shell_quoted(&cmd, opts->db_user) ||
				SUCCEED != zbx_config_buf_appendf(&cmd, " ") ||
				SUCCEED != zbx_config_buf_append_shell_quoted(&cmd, opts->db_name) ||
				SUCCEED != zbx_config_buf_appendf(&cmd, " -e ") ||
				SUCCEED != zbx_config_buf_append_shell_quoted(&cmd, query.data))
			goto fail;
	}
	else
		goto fail;

	if (NULL == (fp = popen(cmd.data, "r")))
	{
		fprintf(stderr, "zabbix-config: failed to start DB query command\n");
		goto fail;
	}

	while (NULL != fgets(line, sizeof(line), fp))
	{
		size_t	line_len = strlen(line);

		while (0 != line_len && ('\n' == line[line_len - 1] || '\r' == line[line_len - 1]))
			line[--line_len] = '\0';

		if ('\0' == line[0])
			continue;

		errno = 0;
		itemid = strtol(line, &endptr, 10);

		if (0 != errno || line == endptr || '\0' != *endptr)
		{
			fprintf(stderr, "zabbix-config: invalid item id returned for UUID '%s'\n", item_uuid);
			goto fail;
		}

		break;
	}

	status = pclose(fp);
	fp = NULL;

	if (!WIFEXITED(status) || 0 != WEXITSTATUS(status))
	{
		fprintf(stderr, "zabbix-config: DB query command failed\n");
		goto fail;
	}

	if (-1 == itemid)
	{
		fprintf(stderr, "zabbix-config: failed to resolve item UUID '%s'\n", item_uuid);
		goto fail;
	}

	*itemid_out = itemid;
	zbx_config_buf_free(&cmd);
	zbx_config_buf_free(&query);
	return SUCCEED;
fail:
	if (NULL != fp)
		(void)pclose(fp);

	zbx_config_buf_free(&cmd);
	zbx_config_buf_free(&query);
	return FAIL;
}

static int	zbx_config_apply_trigger_array(const char *json, const char *name,
		const zbx_config_import_opts_t *opts, const char *db_engine)
{
	const char	*p;
	int		done = 0;
	const size_t	batch_size = 100;
	zbx_config_buf_t	batch_sql;
	size_t		batch_count = 0;

	memset(&batch_sql, 0, sizeof(batch_sql));
	p = zbx_config_find_object_array(json, name);

	if (NULL == p)
		return FAIL;

	p = zbx_config_skip_ws(p + 1);

	while ('\0' != *p)
	{
		zbx_config_buf_t	object_json;
		zbx_config_buf_t	sql;
		char		uuid[64];
		char		description[4096];
		char		expression[4096];
		int		status;
		int		priority;
		int		type;
		const char	*functions_array;

		if (SUCCEED != zbx_config_next_array_object(&p, &object_json, &done))
			return FAIL;

		if (0 != done)
			break;

		if (SUCCEED != zbx_config_parse_json_string_token(object_json.data, "uuid", uuid, sizeof(uuid)))
		{
			zbx_config_buf_free(&object_json);
			fprintf(stderr, "zabbix-config: invalid trigger object '%s': failed parsing uuid\n", name);
			return FAIL;
		}

		if (SUCCEED != zbx_config_parse_json_string_token(object_json.data, "description", description,
				sizeof(description)))
		{
			zbx_config_buf_free(&object_json);
			fprintf(stderr, "zabbix-config: invalid trigger object '%s': failed parsing description\n", name);
			return FAIL;
		}

		if (SUCCEED != zbx_config_parse_json_string_token(object_json.data, "expression", expression,
				sizeof(expression)))
		{
			zbx_config_buf_free(&object_json);
			fprintf(stderr, "zabbix-config: invalid trigger object '%s': failed parsing expression\n", name);
			return FAIL;
		}

		if (SUCCEED != zbx_config_parse_json_int(object_json.data, "status", &status))
		{
			zbx_config_buf_free(&object_json);
			fprintf(stderr, "zabbix-config: invalid trigger object '%s': failed parsing status\n", name);
			return FAIL;
		}

		if (SUCCEED != zbx_config_parse_json_int(object_json.data, "priority", &priority))
		{
			zbx_config_buf_free(&object_json);
			fprintf(stderr, "zabbix-config: invalid trigger object '%s': failed parsing priority\n", name);
			return FAIL;
		}

		if (SUCCEED != zbx_config_parse_json_int(object_json.data, "type", &type))
		{
			zbx_config_buf_free(&object_json);
			fprintf(stderr, "zabbix-config: invalid trigger object '%s': failed parsing type\n", name);
			return FAIL;
		}

		functions_array = zbx_config_find_object_array(object_json.data, "functions");

		if (NULL != functions_array)
		{
			const char	*functions_p;

			functions_p = zbx_config_skip_ws(functions_array + 1);

			while ('\0' != *functions_p)
			{
				zbx_config_buf_t	function_json;
				char		item_uuid[64];
				char		func_name[32];
				char		parameter[256];
				int		source_itemid = 0;
				long		target_itemid = 0;
				int		done_functions = 0;

				if (SUCCEED != zbx_config_next_array_object(&functions_p, &function_json, &done_functions))
				{
					zbx_config_buf_free(&object_json);
					return FAIL;
				}

				if (0 != done_functions)
					break;

				if (SUCCEED != zbx_config_parse_json_string_token(function_json.data, "item_uuid", item_uuid,
						sizeof(item_uuid)) ||
					SUCCEED != zbx_config_parse_json_string_token(function_json.data, "name", func_name,
						sizeof(func_name)) ||
					SUCCEED != zbx_config_parse_json_string_token(function_json.data, "parameter", parameter,
						sizeof(parameter)) ||
					SUCCEED != zbx_config_parse_json_int(function_json.data, "source_itemid",
						&source_itemid))
				{
					zbx_config_buf_free(&function_json);
					zbx_config_buf_free(&object_json);
					fprintf(stderr, "zabbix-config: invalid trigger function object in '%s'\n", name);
					return FAIL;
				}

				if (SUCCEED != zbx_config_lookup_itemid(opts, db_engine, item_uuid, &target_itemid))
				{
					zbx_config_buf_free(&function_json);
					zbx_config_buf_free(&object_json);
					fprintf(stderr, "zabbix-config: failed resolving trigger function item UUID '%s'\n", item_uuid);
					return FAIL;
				}

				if (SUCCEED != zbx_config_replace_expression_item_ids(expression, sizeof(expression),
						source_itemid, target_itemid))
				{
					zbx_config_buf_free(&function_json);
					zbx_config_buf_free(&object_json);
					fprintf(stderr, "zabbix-config: failed translating trigger expression item IDs\n");
					return FAIL;
				}

				zbx_config_buf_free(&function_json);

				functions_p = zbx_config_skip_ws(functions_p);

				if (',' == *functions_p)
					functions_p = zbx_config_skip_ws(functions_p + 1);
			}
		}

		memset(&sql, 0, sizeof(sql));

		if (SUCCEED != zbx_config_buf_init(&sql, 8192))
		{
			zbx_config_buf_free(&object_json);
			return FAIL;
		}

		if (0 == strcmp(db_engine, "postgresql"))
		{
			if (SUCCEED != zbx_config_buf_appendf(&sql, "delete from functions where triggerid in (select triggerid from triggers where uuid=") ||
					SUCCEED != zbx_config_buf_append_sql_quoted(&sql, uuid) ||
					SUCCEED != zbx_config_buf_appendf(&sql, "); update triggers set expression=") ||
					SUCCEED != zbx_config_buf_append_sql_quoted(&sql, expression) ||
					SUCCEED != zbx_config_buf_appendf(&sql, ", description=") ||
					SUCCEED != zbx_config_buf_append_sql_quoted(&sql, description) ||
					SUCCEED != zbx_config_buf_appendf(&sql, ", status=%d, priority=%d, type=%d, flags=0 where uuid=", status, priority, type) ||
					SUCCEED != zbx_config_buf_append_sql_quoted(&sql, uuid) ||
					SUCCEED != zbx_config_buf_appendf(&sql, "; insert into triggers (triggerid,expression,description,status,priority,type,flags,uuid) select nextid.triggerid,") ||
					SUCCEED != zbx_config_buf_append_sql_quoted(&sql, expression) ||
					SUCCEED != zbx_config_buf_appendf(&sql, ",") ||
					SUCCEED != zbx_config_buf_append_sql_quoted(&sql, description) ||
					SUCCEED != zbx_config_buf_appendf(&sql, ",%d,%d,%d,0,", status, priority, type) ||
					SUCCEED != zbx_config_buf_append_sql_quoted(&sql, uuid) ||
					SUCCEED != zbx_config_buf_appendf(&sql, " from (select coalesce(max(triggerid),0)+1 as triggerid from triggers) nextid where not exists (select 1 from triggers where uuid=") ||
					SUCCEED != zbx_config_buf_append_sql_quoted(&sql, uuid) ||
					SUCCEED != zbx_config_buf_appendf(&sql, ")"))
			{
				zbx_config_buf_free(&sql);
				zbx_config_buf_free(&object_json);
				return FAIL;
			}
		}
		else
		{
			if (SUCCEED != zbx_config_buf_appendf(&sql, "delete from functions where triggerid in (select triggerid from triggers where uuid=") ||
					SUCCEED != zbx_config_buf_append_sql_quoted(&sql, uuid) ||
					SUCCEED != zbx_config_buf_appendf(&sql, "); update triggers set expression=") ||
					SUCCEED != zbx_config_buf_append_sql_quoted(&sql, expression) ||
					SUCCEED != zbx_config_buf_appendf(&sql, ", description=") ||
					SUCCEED != zbx_config_buf_append_sql_quoted(&sql, description) ||
					SUCCEED != zbx_config_buf_appendf(&sql, ", status=%d, priority=%d, type=%d, flags=0 where uuid=", status, priority, type) ||
					SUCCEED != zbx_config_buf_append_sql_quoted(&sql, uuid) ||
					SUCCEED != zbx_config_buf_appendf(&sql, "; insert into triggers (triggerid,expression,description,status,priority,type,flags,uuid) select nextid.triggerid,") ||
					SUCCEED != zbx_config_buf_append_sql_quoted(&sql, expression) ||
					SUCCEED != zbx_config_buf_appendf(&sql, ",") ||
					SUCCEED != zbx_config_buf_append_sql_quoted(&sql, description) ||
					SUCCEED != zbx_config_buf_appendf(&sql, ",%d,%d,%d,0,", status, priority, type) ||
					SUCCEED != zbx_config_buf_append_sql_quoted(&sql, uuid) ||
					SUCCEED != zbx_config_buf_appendf(&sql, " from (select coalesce(max(triggerid),0)+1 as triggerid from triggers) nextid where not exists (select 1 from triggers where uuid=") ||
					SUCCEED != zbx_config_buf_append_sql_quoted(&sql, uuid) ||
					SUCCEED != zbx_config_buf_appendf(&sql, ")"))
			{
				zbx_config_buf_free(&sql);
				zbx_config_buf_free(&object_json);
				return FAIL;
			}
		}

		if (NULL != functions_array)
		{
			const char	*functions_p;

			functions_p = zbx_config_skip_ws(functions_array + 1);

			while ('\0' != *functions_p)
			{
				zbx_config_buf_t	function_json;
				char		item_uuid[64];
				char		func_name[32];
				char		parameter[256];
				int		source_itemid = 0;
				long		target_itemid = 0;
				int		done_functions = 0;

				if (SUCCEED != zbx_config_next_array_object(&functions_p, &function_json, &done_functions))
				{
					zbx_config_buf_free(&sql);
					zbx_config_buf_free(&object_json);
					return FAIL;
				}

				if (0 != done_functions)
					break;

				if (SUCCEED != zbx_config_parse_json_string_token(function_json.data, "item_uuid", item_uuid,
						sizeof(item_uuid)) ||
					SUCCEED != zbx_config_parse_json_string_token(function_json.data, "name", func_name,
						sizeof(func_name)) ||
					SUCCEED != zbx_config_parse_json_string_token(function_json.data, "parameter", parameter,
						sizeof(parameter)) ||
					SUCCEED != zbx_config_parse_json_int(function_json.data, "source_itemid",
						&source_itemid))
				{
					zbx_config_buf_free(&function_json);
					zbx_config_buf_free(&sql);
					zbx_config_buf_free(&object_json);
					fprintf(stderr, "zabbix-config: invalid trigger function object in '%s'\n", name);
					return FAIL;
				}

				if (SUCCEED != zbx_config_lookup_itemid(opts, db_engine, item_uuid, &target_itemid))
				{
					zbx_config_buf_free(&function_json);
					zbx_config_buf_free(&sql);
					zbx_config_buf_free(&object_json);
					fprintf(stderr, "zabbix-config: failed resolving trigger function item UUID '%s'\n", item_uuid);
					return FAIL;
				}

				if (SUCCEED != zbx_config_replace_expression_item_ids(expression, sizeof(expression),
						source_itemid, target_itemid))
				{
					zbx_config_buf_free(&function_json);
					zbx_config_buf_free(&sql);
					zbx_config_buf_free(&object_json);
					fprintf(stderr, "zabbix-config: failed translating trigger expression item IDs\n");
					return FAIL;
				}

				if (SUCCEED != zbx_config_buf_appendf(&sql, "; insert into functions (functionid,itemid,triggerid,name,parameter) select nextid.functionid, (select itemid from items where uuid=") ||
						SUCCEED != zbx_config_buf_append_sql_quoted(&sql, item_uuid) ||
						SUCCEED != zbx_config_buf_appendf(&sql, " limit 1), (select triggerid from triggers where uuid=") ||
						SUCCEED != zbx_config_buf_append_sql_quoted(&sql, uuid) ||
						SUCCEED != zbx_config_buf_appendf(&sql, " limit 1),") ||
						SUCCEED != zbx_config_buf_append_sql_quoted(&sql, func_name) ||
						SUCCEED != zbx_config_buf_appendf(&sql, ",") ||
						SUCCEED != zbx_config_buf_append_sql_quoted(&sql, parameter) ||
						SUCCEED != zbx_config_buf_appendf(&sql, " from (select coalesce(max(functionid),0)+1 as functionid from functions) nextid"))
				{
					zbx_config_buf_free(&function_json);
					zbx_config_buf_free(&sql);
					zbx_config_buf_free(&object_json);
					return FAIL;
				}

				zbx_config_buf_free(&function_json);

				functions_p = zbx_config_skip_ws(functions_p);

				if (',' == *functions_p)
					functions_p = zbx_config_skip_ws(functions_p + 1);
			}
		}

		if (0 == batch_count)
		{
			if (SUCCEED != zbx_config_buf_init(&batch_sql, 65536))
			{
				zbx_config_buf_free(&sql);
				zbx_config_buf_free(&object_json);
				return FAIL;
			}

			if (0 == strcmp(db_engine, "postgresql"))
			{
				if (SUCCEED != zbx_config_buf_appendf(&batch_sql, "begin;"))
				{
					zbx_config_buf_free(&batch_sql);
					zbx_config_buf_free(&sql);
					zbx_config_buf_free(&object_json);
					return FAIL;
				}
			}
			else
			{
				if (SUCCEED != zbx_config_buf_appendf(&batch_sql, "start transaction;"))
				{
					zbx_config_buf_free(&batch_sql);
					zbx_config_buf_free(&sql);
					zbx_config_buf_free(&object_json);
					return FAIL;
				}
			}
		}

		if (0 != batch_count && SUCCEED != zbx_config_buf_appendf(&batch_sql, "; "))
		{
			zbx_config_buf_free(&batch_sql);
			zbx_config_buf_free(&sql);
			zbx_config_buf_free(&object_json);
			return FAIL;
		}

		if (SUCCEED != zbx_config_buf_appendf(&batch_sql, "%s", sql.data))
		{
			zbx_config_buf_free(&batch_sql);
			zbx_config_buf_free(&sql);
			zbx_config_buf_free(&object_json);
			return FAIL;
		}

		batch_count++;
		zbx_config_buf_free(&sql);
		zbx_config_buf_free(&object_json);

		if (batch_count >= batch_size)
		{
			if (SUCCEED != zbx_config_buf_appendf(&batch_sql, "; commit;"))
			{
				zbx_config_buf_free(&batch_sql);
				return FAIL;
			}

			if (SUCCEED != zbx_config_run_db_command(opts, db_engine, batch_sql.data))
			{
				zbx_config_buf_free(&batch_sql);
				fprintf(stderr, "zabbix-config: failed applying trigger batch\n");
				return FAIL;
			}

			zbx_config_buf_free(&batch_sql);
			batch_count = 0;
		}

		p = zbx_config_skip_ws(p);

		if (',' == *p)
			p = zbx_config_skip_ws(p + 1);
	}

	if (0 != batch_count)
	{
		if (SUCCEED != zbx_config_buf_appendf(&batch_sql, "; commit;"))
		{
			zbx_config_buf_free(&batch_sql);
			return FAIL;
		}

		if (SUCCEED != zbx_config_run_db_command(opts, db_engine, batch_sql.data))
		{
			zbx_config_buf_free(&batch_sql);
			fprintf(stderr, "zabbix-config: failed applying trigger batch\n");
			return FAIL;
		}

		zbx_config_buf_free(&batch_sql);
	}

	return SUCCEED;
}

static int	zbx_config_validate_package_text(const char *json, unsigned int *object_mask_out)
{
	unsigned int	object_mask;
	const zbx_config_object_t	*object_order[sizeof(zbx_config_objects) / sizeof(zbx_config_objects[0])];
	char		producer_db[32];
	char		producer_version[64];
	int		exchange_schema;
	size_t		i, object_count;

	if (NULL == json)
		return FAIL;

	if (SUCCEED != zbx_config_parse_json_int(json, "exchange_schema", &exchange_schema))
	{
		fprintf(stderr, "zabbix-config: bundle is missing a valid exchange_schema\n");
		return FAIL;
	}

	if (ZBX_CONFIG_EXCHANGE_SCHEMA_CURRENT != exchange_schema)
	{
		fprintf(stderr, "zabbix-config: unsupported exchange schema %d (supported: %d)\n",
			exchange_schema, ZBX_CONFIG_EXCHANGE_SCHEMA_CURRENT);
		return FAIL;
	}

	if (SUCCEED != zbx_config_parse_json_string_token(json, "producer_db", producer_db, sizeof(producer_db)) ||
			SUCCEED != zbx_config_parse_json_string_token(json, "producer_version", producer_version,
			sizeof(producer_version)))
	{
		fprintf(stderr, "zabbix-config: bundle is missing required producer metadata\n");
		return FAIL;
	}

	if (0 == zbx_config_is_token_valid(producer_db) || 0 == zbx_config_is_token_valid(producer_version))
	{
		fprintf(stderr, "zabbix-config: bundle producer metadata contains invalid token characters\n");
		return FAIL;
	}

	if (NULL == zbx_config_find_json_key(json, "payload") || NULL == zbx_config_find_json_key(json, "objects") ||
			NULL == zbx_config_find_json_key(json, "object_order"))
	{
		fprintf(stderr, "zabbix-config: bundle is missing payload structure\n");
		return FAIL;
	}

	if (SUCCEED != zbx_config_parse_object_order(json, object_order,
			sizeof(object_order) / sizeof(object_order[0]), &object_count, &object_mask))
		return FAIL;

	if (SUCCEED != zbx_config_validate_object_dependency_mask(object_mask))
		return FAIL;

	for (i = 0; i < object_count; i++)
	{
		int	empty;

		if (NULL == zbx_config_find_object_array(json, object_order[i]->name))
		{
			fprintf(stderr, "zabbix-config: bundle payload is missing object array '%s'\n",
				object_order[i]->name);
			return FAIL;
		}

		if (0 == zbx_config_object_is_supported(object_order[i]))
		{
			if (SUCCEED != zbx_config_object_array_is_empty(json, object_order[i]->name, &empty))
				return FAIL;

			if (0 == empty)
			{
				fprintf(stderr, "zabbix-config: bundle contains unsupported object '%s' with data\n",
					object_order[i]->name);
				return FAIL;
			}
		}
	}

	if (NULL != object_mask_out)
		*object_mask_out = object_mask;

	return SUCCEED;
}

static int	zbx_config_run_import_apply(const char *json, const zbx_config_import_opts_t *opts,
		zbx_config_progress_t progress_format)
{
	const zbx_config_object_t	*object_order[sizeof(zbx_config_objects) / sizeof(zbx_config_objects[0])];
	unsigned int	object_mask;
	size_t		object_count, i;
	char		db_engine[16];
	int		target_db_ready = 0;

	if (SUCCEED != zbx_config_parse_object_order(json, object_order,
			sizeof(object_order) / sizeof(object_order[0]), &object_count, &object_mask))
		return ZBX_CONFIG_RC_VALIDATION;

	(void)object_mask;

	for (i = 0; i < object_count; i++)
	{
		int	empty;
		int	progress;

		if (SUCCEED != zbx_config_object_array_is_empty(json, object_order[i]->name, &empty))
			return ZBX_CONFIG_RC_VALIDATION;

		progress = 80 + (int)(((i + 1) * 15) / (0 == object_count ? 1 : object_count));
		zbx_config_emit_progress(progress_format, "import", object_order[i]->name, "running", progress);

		if (0 != empty)
			continue;

		if (0 == target_db_ready)
		{
			if (SUCCEED != zbx_config_detect_target_db(opts, db_engine, sizeof(db_engine)))
				return ZBX_CONFIG_RC_IO;

			target_db_ready = 1;
		}

		if (0 == strcmp(object_order[i]->name, "template_groups"))
		{
			if (SUCCEED != zbx_config_apply_group_array(json, object_order[i]->name, 1, opts, db_engine))
				return ZBX_CONFIG_RC_IO;

			continue;
		}

		if (0 == strcmp(object_order[i]->name, "host_groups"))
		{
			if (SUCCEED != zbx_config_apply_group_array(json, object_order[i]->name, 0, opts, db_engine))
				return ZBX_CONFIG_RC_IO;

			continue;
		}

		if (0 == strcmp(object_order[i]->name, "templates"))
		{
			if (SUCCEED != zbx_config_apply_template_array(json, object_order[i]->name, opts, db_engine))
				return ZBX_CONFIG_RC_IO;

			continue;
		}

		if (0 == strcmp(object_order[i]->name, "hosts"))
		{
			if (SUCCEED != zbx_config_apply_host_array(json, object_order[i]->name, opts, db_engine))
				return ZBX_CONFIG_RC_IO;

			continue;
		}

		if (0 == strcmp(object_order[i]->name, "items"))
		{
			if (SUCCEED != zbx_config_apply_item_array(json, object_order[i]->name, opts, db_engine))
				return ZBX_CONFIG_RC_IO;

			continue;
		}

		if (0 == strcmp(object_order[i]->name, "triggers"))
		{
			if (SUCCEED != zbx_config_apply_trigger_array(json, object_order[i]->name, opts, db_engine))
				return ZBX_CONFIG_RC_IO;

			continue;
		}

		fprintf(stderr, "zabbix-config: applying unsupported object '%s'\n",
			object_order[i]->name);
		return ZBX_CONFIG_RC_NOT_IMPLEMENTED;
	}

	zbx_config_emit_progress(progress_format, "import", "apply", "completed", 100);
	return 0;
}

static int	zbx_config_parse_import_opts(int argc, char **argv, int argi, zbx_config_import_opts_t *opts)
{
	memset(opts, 0, sizeof(*opts));

	while (argi < argc)
	{
		if (0 == strcmp(argv[argi], "--bundle"))
		{
			if (++argi >= argc)
			{
				fprintf(stderr, "zabbix-config: missing value for --bundle\n");
				return FAIL;
			}

			opts->bundle = argv[argi++];
		}
		else if (0 == strcmp(argv[argi], "--db-host"))
		{
			if (++argi >= argc)
			{
				fprintf(stderr, "zabbix-config: missing value for --db-host\n");
				return FAIL;
			}

			opts->db_host = argv[argi++];
		}
		else if (0 == strcmp(argv[argi], "--db-port"))
		{
			if (++argi >= argc)
			{
				fprintf(stderr, "zabbix-config: missing value for --db-port\n");
				return FAIL;
			}

			opts->db_port = argv[argi++];
		}
		else if (0 == strcmp(argv[argi], "--db-name"))
		{
			if (++argi >= argc)
			{
				fprintf(stderr, "zabbix-config: missing value for --db-name\n");
				return FAIL;
			}

			opts->db_name = argv[argi++];
		}
		else if (0 == strcmp(argv[argi], "--db-user"))
		{
			if (++argi >= argc)
			{
				fprintf(stderr, "zabbix-config: missing value for --db-user\n");
				return FAIL;
			}

			opts->db_user = argv[argi++];
		}
		else if (0 == strcmp(argv[argi], "--db-pass"))
		{
			if (++argi >= argc)
			{
				fprintf(stderr, "zabbix-config: missing value for --db-pass\n");
				return FAIL;
			}

			opts->db_pass = argv[argi++];
		}
		else if (0 == strcmp(argv[argi], "--cloud-version"))
		{
			if (++argi >= argc)
			{
				fprintf(stderr, "zabbix-config: missing value for --cloud-version\n");
				return FAIL;
			}

			opts->cloud_version = argv[argi++];
		}
		else
		{
			fprintf(stderr, "zabbix-config: unknown import option: %s\n", argv[argi]);
			return FAIL;
		}
	}

	if (NULL == opts->bundle)
	{
		fprintf(stderr, "zabbix-config: --bundle is required\n");
		return FAIL;
	}

	if ((NULL != opts->db_host && 0 == zbx_config_is_token_valid(opts->db_host)) ||
			(NULL != opts->db_port && 0 == zbx_config_is_token_valid(opts->db_port)) ||
			(NULL != opts->db_name && 0 == zbx_config_is_token_valid(opts->db_name)) ||
			(NULL != opts->db_user && 0 == zbx_config_is_token_valid(opts->db_user)) ||
			(NULL != opts->cloud_version && 0 == zbx_config_is_token_valid(opts->cloud_version)))
	{
		fprintf(stderr, "zabbix-config: invalid token characters in import options\n");
		return FAIL;
	}

	return SUCCEED;
}

static int	zbx_config_run_validate(int argc, char **argv, int argi, zbx_config_progress_t progress_format)
{
	zbx_config_import_opts_t	opts;
	zbx_config_buf_t		bundle;
	unsigned int			object_mask;
	int				ret = ZBX_CONFIG_RC_IO;

	memset(&bundle, 0, sizeof(bundle));
	zbx_config_emit_progress(progress_format, "validate", "parse", "running", 5);

	if (SUCCEED != zbx_config_parse_import_opts(argc, argv, argi, &opts))
		return ZBX_CONFIG_RC_USAGE;

	zbx_config_emit_progress(progress_format, "validate", "read", "running", 25);

	if (SUCCEED != zbx_config_read_text_file(opts.bundle, &bundle))
		goto out;

	zbx_config_emit_progress(progress_format, "validate", "preflight", "running", 65);

	if (SUCCEED != zbx_config_validate_package_text(bundle.data, &object_mask))
	{
		ret = ZBX_CONFIG_RC_VALIDATION;
		goto out;
	}

	ret = 0;
	zbx_config_emit_progress(progress_format, "validate", "preflight", "completed", 100);
out:
	zbx_config_buf_free(&bundle);

	if (0 != ret)
		zbx_config_emit_progress(progress_format, "validate", "preflight", "failed", 100);

	return ret;
}

static int	zbx_config_run_import(int argc, char **argv, int argi, zbx_config_progress_t progress_format)
{
	zbx_config_import_opts_t	opts;
	zbx_config_buf_t		bundle;
	unsigned int			object_mask;
	int				ret = ZBX_CONFIG_RC_IO;

	memset(&bundle, 0, sizeof(bundle));
	zbx_config_emit_progress(progress_format, "import", "parse", "running", 5);

	if (SUCCEED != zbx_config_parse_import_opts(argc, argv, argi, &opts))
		return ZBX_CONFIG_RC_USAGE;

	zbx_config_emit_progress(progress_format, "import", "read", "running", 20);

	if (SUCCEED != zbx_config_read_text_file(opts.bundle, &bundle))
		goto out;

	zbx_config_emit_progress(progress_format, "import", "preflight", "running", 50);

	if (SUCCEED != zbx_config_validate_package_text(bundle.data, &object_mask))
	{
		ret = ZBX_CONFIG_RC_VALIDATION;
		goto out;
	}

	zbx_config_emit_progress(progress_format, "import", "apply", "running", 80);
	ret = zbx_config_run_import_apply(bundle.data, &opts, progress_format);
out:
	zbx_config_buf_free(&bundle);

	if (0 != ret)
		zbx_config_emit_progress(progress_format, "import", "apply", "failed", 100);

	return ret;
}

static int	zbx_config_parse_export_opts(int argc, char **argv, int argi, zbx_config_export_opts_t *opts)
{
	memset(opts, 0, sizeof(*opts));
	opts->exchange_schema = ZBX_CONFIG_EXCHANGE_SCHEMA_CURRENT;

	while (argi < argc)
	{
		if (0 == strcmp(argv[argi], "--output"))
		{
			if (++argi >= argc)
			{
				fprintf(stderr, "zabbix-config: missing value for --output\n");
				return FAIL;
			}

			opts->output = argv[argi++];
		}
		else if (0 == strcmp(argv[argi], "--db-host"))
		{
			if (++argi >= argc)
			{
				fprintf(stderr, "zabbix-config: missing value for --db-host\n");
				return FAIL;
			}

			opts->db_host = argv[argi++];
		}
		else if (0 == strcmp(argv[argi], "--db-port"))
		{
			if (++argi >= argc)
			{
				fprintf(stderr, "zabbix-config: missing value for --db-port\n");
				return FAIL;
			}

			opts->db_port = argv[argi++];
		}
		else if (0 == strcmp(argv[argi], "--db-name"))
		{
			if (++argi >= argc)
			{
				fprintf(stderr, "zabbix-config: missing value for --db-name\n");
				return FAIL;
			}

			opts->db_name = argv[argi++];
		}
		else if (0 == strcmp(argv[argi], "--db-user"))
		{
			if (++argi >= argc)
			{
				fprintf(stderr, "zabbix-config: missing value for --db-user\n");
				return FAIL;
			}

			opts->db_user = argv[argi++];
		}
		else if (0 == strcmp(argv[argi], "--db-pass"))
		{
			if (++argi >= argc)
			{
				fprintf(stderr, "zabbix-config: missing value for --db-pass\n");
				return FAIL;
			}

			opts->db_pass = argv[argi++];
		}
		else if (0 == strcmp(argv[argi], "--producer-db"))
		{
			if (++argi >= argc)
			{
				fprintf(stderr, "zabbix-config: missing value for --producer-db\n");
				return FAIL;
			}

			opts->producer_db = argv[argi++];
		}
		else if (0 == strcmp(argv[argi], "--producer-version"))
		{
			if (++argi >= argc)
			{
				fprintf(stderr, "zabbix-config: missing value for --producer-version\n");
				return FAIL;
			}

			opts->producer_version = argv[argi++];
		}
		else if (0 == strcmp(argv[argi], "--exchange-schema"))
		{
			char	*endptr = NULL;
			long	value;

			if (++argi >= argc)
			{
				fprintf(stderr, "zabbix-config: missing value for --exchange-schema\n");
				return FAIL;
			}

			errno = 0;
			value = strtol(argv[argi], &endptr, 10);

			if (0 != errno || NULL == endptr || '\0' != *endptr || 1 > value || INT_MAX < value)
			{
				fprintf(stderr, "zabbix-config: invalid exchange schema: %s\n", argv[argi]);
				return FAIL;
			}

			opts->exchange_schema = (int)value;
			argi++;
		}
		else if (0 == strcmp(argv[argi], "--object"))
		{
			const zbx_config_object_t	*object;

			if (++argi >= argc)
			{
				fprintf(stderr, "zabbix-config: missing value for --object\n");
				return FAIL;
			}

			object = zbx_config_find_object(argv[argi]);

			if (NULL == object)
			{
				fprintf(stderr, "zabbix-config: unsupported object '%s'\n", argv[argi]);
				return FAIL;
			}

			if (0 == zbx_config_object_is_supported(object))
			{
				fprintf(stderr, "zabbix-config: object '%s' is not supported for export yet\n", argv[argi]);
				return FAIL;
			}

			if (0 == opts->object_selected)
			{
				opts->object_selected = 1;
				opts->object_mask = 0;
			}

			opts->object_mask |= object->bit;
			argi++;
		}
		else
		{
			fprintf(stderr, "zabbix-config: unknown export option: %s\n", argv[argi]);
			return FAIL;
		}
	}

	if (NULL == opts->output)
	{
		fprintf(stderr, "zabbix-config: --output is required for export\n");
		return FAIL;
	}

	if (NULL == opts->producer_db)
	{
		fprintf(stderr, "zabbix-config: --producer-db is required for export\n");
		return FAIL;
	}

	if (NULL == opts->db_host || NULL == opts->db_port || NULL == opts->db_name || NULL == opts->db_user ||
			NULL == opts->db_pass)
	{
		fprintf(stderr, "zabbix-config: --db-host, --db-port, --db-name, --db-user and --db-pass are required for export\n");
		return FAIL;
	}

	if (0 != strcmp(opts->producer_db, "mysql") && 0 != strcmp(opts->producer_db, "postgresql"))
	{
		fprintf(stderr, "zabbix-config: unsupported producer DB '%s' (expected mysql or postgresql)\n",
			opts->producer_db);
		return FAIL;
	}

	if (NULL == opts->producer_version)
	{
#ifdef PACKAGE_VERSION
		opts->producer_version = PACKAGE_VERSION;
#else
		opts->producer_version = "unknown";
#endif
	}

	if (0 == zbx_config_is_token_valid(opts->producer_version) || 0 == zbx_config_is_token_valid(opts->producer_db))
	{
		fprintf(stderr, "zabbix-config: invalid token characters in export metadata\n");
		return FAIL;
	}

	if (0 == zbx_config_is_token_valid(opts->db_host) || 0 == zbx_config_is_token_valid(opts->db_name) ||
			0 == zbx_config_is_token_valid(opts->db_user))
	{
		fprintf(stderr, "zabbix-config: invalid token characters in DB connection options\n");
		return FAIL;
	}

	if (0 == zbx_config_is_token_valid(opts->db_port))
	{
		fprintf(stderr, "zabbix-config: invalid DB port value\n");
		return FAIL;
	}

	if (0 == opts->object_selected)
		opts->object_mask = ZBX_OBJ_SUPPORTED_MASK;

	if (0 == (opts->object_mask & ZBX_OBJ_SUPPORTED_MASK))
	{
		fprintf(stderr, "zabbix-config: no supported objects selected\n");
		return FAIL;
	}

	if (ZBX_CONFIG_EXCHANGE_SCHEMA_CURRENT != opts->exchange_schema)
	{
		fprintf(stderr, "zabbix-config: unsupported exchange schema %d (supported: %d)\n",
			opts->exchange_schema, ZBX_CONFIG_EXCHANGE_SCHEMA_CURRENT);
		return FAIL;
	}

	return SUCCEED;
}

static int	zbx_config_run_export(int argc, char **argv, int argi, zbx_config_progress_t progress_format)
{
	zbx_config_export_opts_t	opts;
	zbx_config_buf_t		buf;
	zbx_config_buf_t		template_groups_json;
	zbx_config_buf_t		host_groups_json;
	zbx_config_buf_t		templates_json;
	zbx_config_buf_t		hosts_json;
	zbx_config_buf_t		items_json;
	zbx_config_buf_t		triggers_json;
	char				timestamp[32];
	size_t				i, selected_total = 0, selected_seen = 0;
	size_t				template_groups_num = 0, host_groups_num = 0, templates_num = 0, hosts_num = 0,
					items_num = 0, triggers_num = 0;
	int				template_groups_ready = 0, host_groups_ready = 0, templates_ready = 0, hosts_ready = 0,
					items_ready = 0, triggers_ready = 0;
	int				ret = ZBX_CONFIG_RC_IO;
	const char			*payload_state = "skeleton";

	memset(&template_groups_json, 0, sizeof(template_groups_json));
	memset(&host_groups_json, 0, sizeof(host_groups_json));
	memset(&templates_json, 0, sizeof(templates_json));
	memset(&hosts_json, 0, sizeof(hosts_json));
	memset(&items_json, 0, sizeof(items_json));
	memset(&triggers_json, 0, sizeof(triggers_json));

	zbx_config_emit_progress(progress_format, "export", "parse", "running", 5);

	if (SUCCEED != zbx_config_parse_export_opts(argc, argv, argi, &opts))
		return ZBX_CONFIG_RC_USAGE;

	if (SUCCEED != zbx_config_validate_export_selection(&opts))
		return ZBX_CONFIG_RC_VALIDATION;

	if (0 != (opts.object_mask & ZBX_OBJ_TEMPLATES) &&
			SUCCEED != zbx_config_validate_host_group_refs(&opts, 1))
	{
		return ZBX_CONFIG_RC_VALIDATION;
	}

	if (0 != (opts.object_mask & ZBX_OBJ_HOSTS) &&
			SUCCEED != zbx_config_validate_host_group_refs(&opts, 0))
	{
		return ZBX_CONFIG_RC_VALIDATION;
	}

	if (0 != (opts.object_mask & ZBX_OBJ_ITEMS) && SUCCEED != zbx_config_validate_item_refs(&opts))
		return ZBX_CONFIG_RC_VALIDATION;

	if (0 != (opts.object_mask & ZBX_OBJ_TRIGGERS) && SUCCEED != zbx_config_validate_trigger_refs(&opts))
		return ZBX_CONFIG_RC_VALIDATION;

	if (SUCCEED != zbx_config_get_utc_timestamp(timestamp, sizeof(timestamp)))
		strcpy(timestamp, "1970-01-01T00:00:00Z");

	zbx_config_emit_progress(progress_format, "export", "envelope", "running", 25);
	zbx_config_emit_progress(progress_format, "export", "collect", "running", 45);

	if (0 != (opts.object_mask & ZBX_OBJ_TEMPLATE_GROUPS))
	{
		if (SUCCEED != zbx_config_build_group_array(&opts, 1, &template_groups_json, &template_groups_num))
		{
			fprintf(stderr, "zabbix-config: failed exporting template_groups\n");
			return ZBX_CONFIG_RC_IO;
		}

		template_groups_ready = 1;
		payload_state = "partial";
	}

	if (0 != (opts.object_mask & ZBX_OBJ_HOST_GROUPS))
	{
		if (SUCCEED != zbx_config_build_group_array(&opts, 0, &host_groups_json, &host_groups_num))
		{
			fprintf(stderr, "zabbix-config: failed exporting host_groups\n");
			zbx_config_buf_free(&template_groups_json);
			return ZBX_CONFIG_RC_IO;
		}

		host_groups_ready = 1;
		payload_state = "partial";
	}

	if (0 != (opts.object_mask & ZBX_OBJ_TEMPLATES))
	{
		if (SUCCEED != zbx_config_build_host_object_array(&opts, 1, &templates_json, &templates_num))
		{
			fprintf(stderr, "zabbix-config: failed exporting templates\n");
			zbx_config_buf_free(&template_groups_json);
			zbx_config_buf_free(&host_groups_json);
			return ZBX_CONFIG_RC_IO;
		}

		templates_ready = 1;
		payload_state = "partial";
	}

	if (0 != (opts.object_mask & ZBX_OBJ_HOSTS))
	{
		if (SUCCEED != zbx_config_build_host_object_array(&opts, 0, &hosts_json, &hosts_num))
		{
			fprintf(stderr, "zabbix-config: failed exporting hosts\n");
			zbx_config_buf_free(&template_groups_json);
			zbx_config_buf_free(&host_groups_json);
			zbx_config_buf_free(&templates_json);
			return ZBX_CONFIG_RC_IO;
		}

		hosts_ready = 1;
		payload_state = "partial";
	}

	if (0 != (opts.object_mask & ZBX_OBJ_ITEMS))
	{
		if (SUCCEED != zbx_config_build_item_array(&opts, &items_json, &items_num))
		{
			fprintf(stderr, "zabbix-config: failed exporting items\n");
			zbx_config_buf_free(&template_groups_json);
			zbx_config_buf_free(&host_groups_json);
			zbx_config_buf_free(&templates_json);
			zbx_config_buf_free(&hosts_json);
			return ZBX_CONFIG_RC_IO;
		}

		items_ready = 1;
		payload_state = "partial";
	}

	if (0 != (opts.object_mask & ZBX_OBJ_TRIGGERS))
	{
		if (SUCCEED != zbx_config_build_trigger_array(&opts, &triggers_json, &triggers_num))
		{
			fprintf(stderr, "zabbix-config: failed exporting triggers\n");
			zbx_config_buf_free(&template_groups_json);
			zbx_config_buf_free(&host_groups_json);
			zbx_config_buf_free(&templates_json);
			zbx_config_buf_free(&hosts_json);
			zbx_config_buf_free(&items_json);
			return ZBX_CONFIG_RC_IO;
		}

		triggers_ready = 1;
		payload_state = "partial";
	}

	if (SUCCEED != zbx_config_buf_init(&buf, 2048))
	{
		fprintf(stderr, "zabbix-config: out of memory\n");
		return ZBX_CONFIG_RC_IO;
	}

	if (SUCCEED != zbx_config_buf_appendf(&buf,
			"{\n"
			"  \"exchange_schema\": %d,\n"
			"  \"producer_version\": \"%s\",\n"
			"  \"producer_db\": \"%s\",\n"
			"  \"metadata\": {\n"
			"    \"exported_at\": \"%s\",\n"
			"    \"tool\": \"zabbix-config\",\n"
			"    \"payload_state\": \"%s\",\n"
			"    \"counts\": {\n"
			"      \"template_groups\": %lu,\n"
			"      \"host_groups\": %lu,\n"
			"      \"templates\": %lu,\n"
			"      \"hosts\": %lu,\n"
			"      \"items\": %lu,\n"
			"      \"triggers\": %lu\n"
			"    }\n"
			"  },\n"
			"  \"payload\": {\n"
			"    \"objects\": {\n",
			opts.exchange_schema, opts.producer_version, opts.producer_db, timestamp, payload_state,
			(unsigned long)template_groups_num, (unsigned long)host_groups_num,
			(unsigned long)templates_num, (unsigned long)hosts_num, (unsigned long)items_num,
			(unsigned long)triggers_num))
	{
		fprintf(stderr, "zabbix-config: failed building package envelope\n");
		ret = ZBX_CONFIG_RC_IO;
		goto out;
	}

	for (i = 0; i < sizeof(zbx_config_objects) / sizeof(zbx_config_objects[0]); i++)
	{
		if (0 != (opts.object_mask & zbx_config_objects[i].bit))
			selected_total++;
	}

	for (i = 0; i < sizeof(zbx_config_objects) / sizeof(zbx_config_objects[0]); i++)
	{
		if (0 == (opts.object_mask & zbx_config_objects[i].bit))
			continue;

		selected_seen++;

		if (0 == strcmp(zbx_config_objects[i].name, "template_groups") && 0 != template_groups_ready)
		{
			if (SUCCEED != zbx_config_buf_appendf(&buf, "      \"%s\": %s%s\n", zbx_config_objects[i].name,
					template_groups_json.data, (selected_seen < selected_total ? "," : "")))
			{
				fprintf(stderr, "zabbix-config: failed building template_groups payload\n");
				ret = ZBX_CONFIG_RC_IO;
				goto out;
			}
		}
		else if (0 == strcmp(zbx_config_objects[i].name, "host_groups") && 0 != host_groups_ready)
		{
			if (SUCCEED != zbx_config_buf_appendf(&buf, "      \"%s\": %s%s\n", zbx_config_objects[i].name,
					host_groups_json.data, (selected_seen < selected_total ? "," : "")))
			{
				fprintf(stderr, "zabbix-config: failed building host_groups payload\n");
				ret = ZBX_CONFIG_RC_IO;
				goto out;
			}
		}
		else if (0 == strcmp(zbx_config_objects[i].name, "templates") && 0 != templates_ready)
		{
			if (SUCCEED != zbx_config_buf_appendf(&buf, "      \"%s\": %s%s\n", zbx_config_objects[i].name,
					templates_json.data, (selected_seen < selected_total ? "," : "")))
			{
				fprintf(stderr, "zabbix-config: failed building templates payload\n");
				ret = ZBX_CONFIG_RC_IO;
				goto out;
			}
		}
		else if (0 == strcmp(zbx_config_objects[i].name, "hosts") && 0 != hosts_ready)
		{
			if (SUCCEED != zbx_config_buf_appendf(&buf, "      \"%s\": %s%s\n", zbx_config_objects[i].name,
					hosts_json.data, (selected_seen < selected_total ? "," : "")))
			{
				fprintf(stderr, "zabbix-config: failed building hosts payload\n");
				ret = ZBX_CONFIG_RC_IO;
				goto out;
			}
		}
		else if (0 == strcmp(zbx_config_objects[i].name, "items") && 0 != items_ready)
		{
			if (SUCCEED != zbx_config_buf_appendf(&buf, "      \"%s\": %s%s\n", zbx_config_objects[i].name,
						items_json.data, (selected_seen < selected_total ? "," : "")))
			{
				fprintf(stderr, "zabbix-config: failed building items payload\n");
				ret = ZBX_CONFIG_RC_IO;
				goto out;
			}
		}
		else if (0 == strcmp(zbx_config_objects[i].name, "triggers") && 0 != triggers_ready)
		{
			if (SUCCEED != zbx_config_buf_appendf(&buf, "      \"%s\": %s%s\n", zbx_config_objects[i].name,
						triggers_json.data, (selected_seen < selected_total ? "," : "")))
			{
				fprintf(stderr, "zabbix-config: failed building triggers payload\n");
				ret = ZBX_CONFIG_RC_IO;
				goto out;
			}
		}
		else if (SUCCEED != zbx_config_buf_appendf(&buf, "      \"%s\": []%s\n", zbx_config_objects[i].name,
				(selected_seen < selected_total ? "," : "")))
		{
			fprintf(stderr, "zabbix-config: failed building payload objects\n");
			ret = ZBX_CONFIG_RC_IO;
			goto out;
		}
	}

	if (SUCCEED != zbx_config_buf_appendf(&buf,
			"    },\n"
			"    \"object_order\": ["))
	{
		fprintf(stderr, "zabbix-config: failed building payload object order\n");
		ret = ZBX_CONFIG_RC_IO;
		goto out;
	}

	selected_seen = 0;

	for (i = 0; i < sizeof(zbx_config_objects) / sizeof(zbx_config_objects[0]); i++)
	{
		if (0 == (opts.object_mask & zbx_config_objects[i].bit))
			continue;

		selected_seen++;

		if (SUCCEED != zbx_config_buf_appendf(&buf, "%s\"%s\"", (1 == selected_seen ? "" : ", "),
				zbx_config_objects[i].name))
		{
			fprintf(stderr, "zabbix-config: failed building payload order list\n");
			ret = ZBX_CONFIG_RC_IO;
			goto out;
		}
	}

	if (SUCCEED != zbx_config_buf_appendf(&buf, "]\n  }\n}\n"))
	{
		fprintf(stderr, "zabbix-config: failed finalizing package JSON\n");
		ret = ZBX_CONFIG_RC_IO;
		goto out;
	}

	zbx_config_emit_progress(progress_format, "export", "write", "running", 70);

	if (SUCCEED != zbx_config_write_file_atomic(opts.output, buf.data))
	{
		ret = ZBX_CONFIG_RC_IO;
		goto out;
	}

	zbx_config_emit_progress(progress_format, "export", "write", "completed", 100);
	ret = 0;
out:
	zbx_config_buf_free(&buf);
	zbx_config_buf_free(&template_groups_json);
	zbx_config_buf_free(&host_groups_json);
	zbx_config_buf_free(&templates_json);
	zbx_config_buf_free(&hosts_json);
	zbx_config_buf_free(&items_json);
	zbx_config_buf_free(&triggers_json);

	if (0 != ret)
		zbx_config_emit_progress(progress_format, "export", "write", "failed", 100);

	return ret;
}

static int	zbx_config_emit_progress(zbx_config_progress_t progress_format, const char *operation,
		const char *phase, const char *status, int progress)
{
	if (ZBX_CONFIG_PROGRESS_JSON == progress_format)
	{
		printf("{\"job_id\":\"bootstrap\",\"operation\":\"%s\",\"phase\":\"%s\","
			"\"status\":\"%s\",\"progress\":%d}\n", operation, phase, status, progress);
	}
	else
	{
		printf("%s: %s (%d%%)\n", phase, status, progress);
	}

	fflush(stdout);
	return 0;
}

static int	zbx_config_run_stub(const char *command, zbx_config_progress_t progress_format)
{
	zbx_config_emit_progress(progress_format, command, "bootstrap", "running", 5);
	fprintf(stderr, "zabbix-config: command '%s' is not implemented yet\n", command);
	zbx_config_emit_progress(progress_format, command, "bootstrap", "failed", 100);

	return ZBX_CONFIG_RC_NOT_IMPLEMENTED;
}

int	main(int argc, char **argv)
{
	int				argi = 1;
	zbx_config_progress_t	progress_format = ZBX_CONFIG_PROGRESS_HUMAN;
	const char			*command;

	if (1 == argc)
	{
		zbx_config_print_usage(argv[0]);
		return ZBX_CONFIG_RC_USAGE;
	}

	if (3 <= argc && 0 == strcmp(argv[argi], "--progress-format"))
	{
		if (0 == strcmp(argv[argi + 1], "human"))
			progress_format = ZBX_CONFIG_PROGRESS_HUMAN;
		else if (0 == strcmp(argv[argi + 1], "json"))
			progress_format = ZBX_CONFIG_PROGRESS_JSON;
		else
		{
			fprintf(stderr, "zabbix-config: invalid progress format: %s\n", argv[argi + 1]);
			return ZBX_CONFIG_RC_USAGE;
		}

		argi += 2;
	}

	if (argi >= argc)
	{
		zbx_config_print_usage(argv[0]);
		return ZBX_CONFIG_RC_USAGE;
	}

	command = argv[argi];
	argi++;

	if (0 == strcmp(command, "export"))
	{
		return zbx_config_run_export(argc, argv, argi, progress_format);
	}

	if (0 == strcmp(command, "validate"))
	{
		return zbx_config_run_validate(argc, argv, argi, progress_format);
	}

	if (0 == strcmp(command, "import"))
	{
		return zbx_config_run_import(argc, argv, argi, progress_format);
	}

	if (0 == strcmp(command, "upgrade"))
	{
		return zbx_config_run_stub(command, progress_format);
	}

	fprintf(stderr, "zabbix-config: unknown command: %s\n", command);
	zbx_config_print_usage(argv[0]);

	return ZBX_CONFIG_RC_USAGE;
}
