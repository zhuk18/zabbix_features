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
**/

#include "lld.h"

#include "zbxcommon.h"
#include "zbxcrypto.h"
#include "zbxdb.h"
#include "zbxdbhigh.h"
#include "zbxhash.h"
#include "zbxjson.h"
#include "zbxnum.h"
#include "zbxstr.h"

/*
 * Topology role snapshots.
 *
 * A discovery rule with topology_role != NONE writes the rows that survived the LLD filter into
 * topo_lld_snapshot, as objects with normalized field names. The server never reads topology tables; matching
 * and reconciliation stay in ingest.php.
 *
 * The contract table below MUST be kept in sync with ui/include/classes/topology/CTopologyRole.php (macros,
 * required fields and "one of" groups) and with the field names read by database/topology/discovery/ingest.php.
 */

#define ZBX_LLD_TOPO_ROLE_PORTS		1
#define ZBX_LLD_TOPO_ROLE_NEIGHBORS	2
#define ZBX_LLD_TOPO_ROLE_LEARNED_MACS	3
#define ZBX_LLD_TOPO_ROLE_LAG		4

#define ZBX_LLD_TOPO_STR	0	/* emitted as JSON string */
#define ZBX_LLD_TOPO_UINT	1	/* emitted as JSON number; a non-numeric value invalidates the row */

typedef struct
{
	const char	*macro;
	const char	*field;
	unsigned char	type;
	unsigned char	required;
	unsigned char	group;		/* 0 - none; otherwise at least one field of the group must be non-empty */
	const char	*deflt;		/* value used when the macro is absent, NULL - omit */
}
zbx_lld_topo_field_t;

#define ZBX_LLD_TOPO_MAX_FIELDS		16
#define ZBX_LLD_TOPO_MAX_REASONS	(2 * ZBX_LLD_TOPO_MAX_FIELDS + 2)

static const zbx_lld_topo_field_t	topo_ports[] = {
	{"{#IFINDEX}",		"if_index",		ZBX_LLD_TOPO_UINT,	1, 0, NULL},
	{"{#IFNAME}",		"name",			ZBX_LLD_TOPO_STR,	0, 0, NULL},
	{"{#IFTYPE}",		"if_type",		ZBX_LLD_TOPO_STR,	0, 0, NULL},
	{"{#IFMAC}",		"mac",			ZBX_LLD_TOPO_STR,	0, 0, NULL},
	{"{#IFADMINSTATUS}",	"admin_status",		ZBX_LLD_TOPO_STR,	0, 0, NULL},
	{"{#IFOPERSTATUS}",	"oper_status",		ZBX_LLD_TOPO_STR,	0, 0, NULL},
	{"{#IFSPEED}",		"speed",		ZBX_LLD_TOPO_STR,	0, 0, NULL},
	{"{#LOC_CHASSIS}",	"loc_chassis",		ZBX_LLD_TOPO_STR,	0, 0, NULL},
	{NULL, NULL, 0, 0, 0, NULL}
};

static const zbx_lld_topo_field_t	topo_neighbors[] = {
	{"{#IFINDEX}",		"if_index",		ZBX_LLD_TOPO_UINT,	1, 0, NULL},
	{"{#IFNAME}",		"if_name",		ZBX_LLD_TOPO_STR,	0, 0, NULL},
	{"{#REM_CHASSIS}",	"rem_chassis",		ZBX_LLD_TOPO_STR,	0, 1, NULL},
	{"{#REM_MGMT_IP}",	"rem_mgmt_ip",		ZBX_LLD_TOPO_STR,	0, 1, NULL},
	{"{#REM_SYSNAME}",	"rem_sysname",		ZBX_LLD_TOPO_STR,	0, 1, NULL},
	{"{#REM_CHASSIS_TYPE}",	"rem_chassis_type",	ZBX_LLD_TOPO_STR,	0, 0, NULL},
	{"{#REM_PORT}",		"rem_port",		ZBX_LLD_TOPO_STR,	0, 0, NULL},
	{"{#REM_PORT_TYPE}",	"rem_port_type",	ZBX_LLD_TOPO_STR,	0, 0, NULL},
	{"{#REM_PORT_DESC}",	"rem_port_desc",	ZBX_LLD_TOPO_STR,	0, 0, NULL},
	{"{#LOC_CHASSIS}",	"loc_chassis",		ZBX_LLD_TOPO_STR,	0, 0, NULL},
	{"{#SOURCE}",		"source",		ZBX_LLD_TOPO_STR,	0, 0, "lldp"},
	{NULL, NULL, 0, 0, 0, NULL}
};

static const zbx_lld_topo_field_t	topo_learned_macs[] = {
	{"{#IFINDEX}",		"if_index",		ZBX_LLD_TOPO_UINT,	1, 0, NULL},
	{"{#MAC}",		"mac",			ZBX_LLD_TOPO_STR,	0, 1, NULL},
	{"{#PORT_MAC_COUNT}",	"port_mac_count",	ZBX_LLD_TOPO_UINT,	0, 1, NULL},
	{"{#VLAN}",		"vlan",			ZBX_LLD_TOPO_STR,	0, 0, NULL},
	{NULL, NULL, 0, 0, 0, NULL}
};

static const zbx_lld_topo_field_t	topo_lag[] = {
	{"{#IFINDEX}",		"if_index",		ZBX_LLD_TOPO_UINT,	1, 0, NULL},
	{"{#LAG_IFINDEX}",	"lag_if_index",		ZBX_LLD_TOPO_UINT,	1, 0, NULL},
	{NULL, NULL, 0, 0, 0, NULL}
};

/* {#SNMPINDEX} stands in for a missing {#IFINDEX} for PORTS only: stock IF-MIB discovery emits it as the ifIndex. */
/* For other roles it is a different table index (for NEIGHBORS - TimeMark.LocalPortNum.RemIndex).                */
#define ZBX_LLD_TOPO_SNMPINDEX_MACRO	"{#SNMPINDEX}"

static const char	*lld_topology_role_name(int role)
{
	switch (role)
	{
		case ZBX_LLD_TOPO_ROLE_PORTS:
			return "PORTS";
		case ZBX_LLD_TOPO_ROLE_NEIGHBORS:
			return "NEIGHBORS";
		case ZBX_LLD_TOPO_ROLE_LEARNED_MACS:
			return "LEARNED_MACS";
		case ZBX_LLD_TOPO_ROLE_LAG:
			return "LAG";
		default:
			return NULL;
	}
}

static const zbx_lld_topo_field_t	*lld_topology_contract(int role)
{
	switch (role)
	{
		case ZBX_LLD_TOPO_ROLE_PORTS:
			return topo_ports;
		case ZBX_LLD_TOPO_ROLE_NEIGHBORS:
			return topo_neighbors;
		case ZBX_LLD_TOPO_ROLE_LEARNED_MACS:
			return topo_learned_macs;
		case ZBX_LLD_TOPO_ROLE_LAG:
			return topo_lag;
		default:
			return NULL;
	}
}

typedef struct
{
	zbx_uint64_t	if_index;
	char		*json;
}
zbx_lld_topo_row_t;

static int	lld_topology_row_compare(const void *d1, const void *d2)
{
	const zbx_lld_topo_row_t	*r1 = *(const zbx_lld_topo_row_t * const *)d1;
	const zbx_lld_topo_row_t	*r2 = *(const zbx_lld_topo_row_t * const *)d2;

	ZBX_RETURN_IF_NOT_EQUAL(r1->if_index, r2->if_index);

	return strcmp(r1->json, r2->json);
}

ZBX_PTR_VECTOR_DECL(lld_topo_row_ptr, zbx_lld_topo_row_t *)
ZBX_PTR_VECTOR_IMPL(lld_topo_row_ptr, zbx_lld_topo_row_t *)

static void	lld_topology_row_free(zbx_lld_topo_row_t *row)
{
	zbx_free(row->json);
	zbx_free(row);
}

/* fetches macro value, an empty value counts as missing; returns allocated string or NULL */
static char	*lld_topology_macro_get(const zbx_lld_entry_t *entry, const char *macro)
{
	char	*value = NULL;

	if (SUCCEED != lld_macro_value_by_name(entry, macro, &value) || NULL == value || '\0' == *value)
	{
		zbx_free(value);
		return NULL;
	}

	return value;
}

/******************************************************************************
 *                                                                            *
 * Purpose: build one normalized snapshot row from an LLD entry               *
 *                                                                            *
 * Parameters: role     - [IN]                                                *
 *             contract - [IN] fields of the role                             *
 *             entry    - [IN] LLD entry                                      *
 *             reasons  - [IN/OUT] per-reason counters of dropped rows        *
 *             row      - [OUT] built row on success                          *
 *                                                                            *
 * Return value: SUCCEED - row satisfies the contract                         *
 *               FAIL    - row is invalid (reasons updated)                   *
 *                                                                            *
 ******************************************************************************/
static int	lld_topology_row_build(int role, const zbx_lld_topo_field_t *contract, const zbx_lld_entry_t *entry,
		int *reasons, zbx_lld_topo_row_t **row)
{
	char		*values[ZBX_LLD_TOPO_MAX_FIELDS] = {NULL};
	zbx_uint64_t	numbers[ZBX_LLD_TOPO_MAX_FIELDS] = {0}, if_index = 0;
	int		n, ret = SUCCEED, group_ok[3] = {0}, group_seen[3] = {0};
	struct zbx_json	j;

	for (n = 0; NULL != contract[n].macro; n++)
	{
		values[n] = lld_topology_macro_get(entry, contract[n].macro);

		if (NULL == values[n] && 0 == strcmp(contract[n].field, "if_index") &&
				ZBX_LLD_TOPO_ROLE_PORTS == role)
		{
			values[n] = lld_topology_macro_get(entry, ZBX_LLD_TOPO_SNMPINDEX_MACRO);
		}

		if (NULL == values[n] && NULL != contract[n].deflt)
			values[n] = zbx_strdup(NULL, contract[n].deflt);

		if (0 != contract[n].group)
		{
			group_seen[contract[n].group] = 1;

			if (NULL != values[n])
				group_ok[contract[n].group] = 1;
		}

		if (NULL == values[n])
		{
			if (0 != contract[n].required)
			{
				reasons[2 * n]++;
				ret = FAIL;
			}

			continue;
		}

		if (ZBX_LLD_TOPO_UINT == contract[n].type && SUCCEED != zbx_is_uint64(values[n], &numbers[n]))
		{
			reasons[2 * n + 1]++;
			ret = FAIL;
		}
	}

	for (int g = 1; g < 3; g++)
	{
		if (0 != group_seen[g] && 0 == group_ok[g])
		{
			reasons[2 * ZBX_LLD_TOPO_MAX_FIELDS + g - 1]++;
			ret = FAIL;
		}
	}

	if (SUCCEED == ret)
	{
		zbx_json_init(&j, 256);

		for (n = 0; NULL != contract[n].macro; n++)
		{
			if (NULL == values[n])
				continue;

			if (ZBX_LLD_TOPO_UINT == contract[n].type)
				zbx_json_adduint64(&j, contract[n].field, numbers[n]);
			else
				zbx_json_addstring(&j, contract[n].field, values[n], ZBX_JSON_TYPE_STRING);

			if (0 == strcmp(contract[n].field, "if_index"))
				if_index = numbers[n];
		}

		*row = (zbx_lld_topo_row_t *)zbx_malloc(NULL, sizeof(zbx_lld_topo_row_t));
		(*row)->if_index = if_index;
		(*row)->json = zbx_strdup(NULL, j.buffer);
		zbx_json_free(&j);
	}

	for (n = 0; NULL != contract[n].macro; n++)
		zbx_free(values[n]);

	return ret;
}

/* human readable reasons grouped by requirement: "missing {#IFINDEX} (2), missing one of {#A}|{#B} (1)" */
static void	lld_topology_reasons_format(const zbx_lld_topo_field_t *contract, const int *reasons, char **out)
{
	size_t	alloc = 0, offset = 0;
	char	*buf = NULL;
	int	n;

	for (n = 0; NULL != contract[n].macro; n++)
	{
		if (0 != reasons[2 * n])
		{
			zbx_snprintf_alloc(&buf, &alloc, &offset, "%smissing %s (%d)", 0 == offset ? "" : ", ",
					contract[n].macro, reasons[2 * n]);
		}

		if (0 != reasons[2 * n + 1])
		{
			zbx_snprintf_alloc(&buf, &alloc, &offset, "%snon-numeric %s (%d)", 0 == offset ? "" : ", ",
					contract[n].macro, reasons[2 * n + 1]);
		}
	}

	for (int g = 1; g < 3; g++)
	{
		int	count = reasons[2 * ZBX_LLD_TOPO_MAX_FIELDS + g - 1];

		if (0 == count)
			continue;

		zbx_snprintf_alloc(&buf, &alloc, &offset, "%smissing one of ", 0 == offset ? "" : ", ");

		for (int first = 1, i = 0; NULL != contract[i].macro; i++)
		{
			if (g != contract[i].group)
				continue;

			zbx_snprintf_alloc(&buf, &alloc, &offset, "%s%s", 0 == first ? "|" : "", contract[i].macro);
			first = 0;
		}

		zbx_snprintf_alloc(&buf, &alloc, &offset, " (%d)", count);
	}

	*out = buf;
}

static int	lld_topology_snapshot_write(zbx_uint64_t itemid, zbx_uint64_t hostid, int role, int now,
		const char *rows_json, const char *hash, int rows_total, int rows_valid)
{
	zbx_db_result_t	result;
	zbx_db_row_t	db_row;
	int		exists = 0, same = 0, ret = SUCCEED;

	result = zbx_db_select("select role,rows_hash from topo_lld_snapshot where itemid=" ZBX_FS_UI64, itemid);

	if (NULL != (db_row = zbx_db_fetch(result)))
	{
		exists = 1;
		same = role == atoi(db_row[0]) && 0 == strcmp(hash, db_row[1]);
	}

	zbx_db_free_result(result);

	if (1 == same)
	{
		/* identical data: only the observation time and counters move */
		if (ZBX_DB_OK > zbx_db_execute("update topo_lld_snapshot set clock=%d,rows_total=%d,rows_valid=%d"
				" where itemid=" ZBX_FS_UI64, now, rows_total, rows_valid, itemid))
		{
			ret = FAIL;
		}

		return ret;
	}

	if (1 == exists)
	{
		char	*sql = NULL, *rows_esc;
		size_t	sql_alloc = 0, sql_offset = 0;

		rows_esc = zbx_db_dyn_escape_string(rows_json);

		zbx_snprintf_alloc(&sql, &sql_alloc, &sql_offset,
				"update topo_lld_snapshot set hostid=" ZBX_FS_UI64 ",role=%d,clock=%d,rows_hash='%s',"
				"rows_total=%d,rows_valid=%d,rows_json='%s' where itemid=" ZBX_FS_UI64,
				hostid, role, now, hash, rows_total, rows_valid, rows_esc, itemid);

		if (ZBX_DB_OK > zbx_db_execute("%s", sql))
			ret = FAIL;

		zbx_free(sql);
		zbx_free(rows_esc);

		return ret;
	}

	zbx_db_insert_t	db_insert;

	zbx_db_insert_prepare(&db_insert, "topo_lld_snapshot", "itemid", "hostid", "role", "clock", "rows_hash",
			"rows_json", "rows_total", "rows_valid", (char *)NULL);
	zbx_db_insert_add_values(&db_insert, itemid, hostid, role, now, hash, rows_json, rows_total, rows_valid);

	if (ZBX_DB_OK > zbx_db_insert_execute(&db_insert))
		ret = FAIL;

	zbx_db_insert_clean(&db_insert);

	return ret;
}

/******************************************************************************
 *                                                                            *
 * Purpose: store the LLD rows of a rule with a topology role as a snapshot   *
 *                                                                            *
 * Parameters: itemid   - [IN] discovery rule                                 *
 *             hostid   - [IN] host of the rule                               *
 *             role     - [IN] topology_role of the rule                      *
 *             lld_rows - [IN] rows that passed the LLD filter                *
 *             now      - [IN] processing time                                *
 *             info     - [OUT] problems to append to the rule's info text    *
 *                                                                            *
 * Comments: Called only after successful processing, so an empty row set is  *
 *           a valid snapshot and a failed rule never overwrites the last     *
 *           good one. A failure here must not affect prototype processing.   *
 *                                                                            *
 ******************************************************************************/
void	lld_topology_snapshot_update(zbx_uint64_t itemid, zbx_uint64_t hostid, int role,
		const zbx_vector_lld_row_ptr_t *lld_rows, int now, char **info)
{
	const zbx_lld_topo_field_t	*contract = lld_topology_contract(role);
	zbx_vector_lld_topo_row_ptr_t	rows;
	int				reasons[ZBX_LLD_TOPO_MAX_REASONS] = {0}, rows_total = lld_rows->values_num;
	char				*rows_json = NULL, hash[2 * ZBX_SHA256_DIGEST_SIZE + 1];
	unsigned char			digest[ZBX_SHA256_DIGEST_SIZE];
	size_t				alloc = 0, offset = 0;

	if (NULL == contract)
		return;

	zbx_vector_lld_topo_row_ptr_create(&rows);

	for (int i = 0; i < lld_rows->values_num; i++)
	{
		zbx_lld_topo_row_t	*row;

		if (SUCCEED == lld_topology_row_build(role, contract, lld_rows->values[i]->data, reasons, &row))
			zbx_vector_lld_topo_row_ptr_append(&rows, row);
	}

	/* deterministic order: identical data always produces an identical hash */
	zbx_vector_lld_topo_row_ptr_sort(&rows, lld_topology_row_compare);

	zbx_chrcpy_alloc(&rows_json, &alloc, &offset, '[');

	for (int i = 0; i < rows.values_num; i++)
	{
		if (0 != i)
			zbx_chrcpy_alloc(&rows_json, &alloc, &offset, ',');

		zbx_strcpy_alloc(&rows_json, &alloc, &offset, rows.values[i]->json);
	}

	zbx_chrcpy_alloc(&rows_json, &alloc, &offset, ']');

	zbx_sha256_hash_len(rows_json, offset, (char *)digest);
	zbx_bin2hex(digest, sizeof(digest), hash, sizeof(hash));

	if (SUCCEED != lld_topology_snapshot_write(itemid, hostid, role, now, rows_json, hash, rows_total,
			rows.values_num))
	{
		zabbix_log(LOG_LEVEL_WARNING, "cannot store topology snapshot of discovery rule " ZBX_FS_UI64,
				itemid);
	}

	if (rows.values_num != rows_total)
	{
		char	*text = NULL;

		lld_topology_reasons_format(contract, reasons, &text);

		*info = zbx_dsprintf(*info, "Topology (%s): %d of %d rows skipped: %s\n", lld_topology_role_name(role),
				rows_total - rows.values_num, rows_total, ZBX_NULL2EMPTY_STR(text));
		zbx_free(text);
	}

	zbx_free(rows_json);
	zbx_vector_lld_topo_row_ptr_clear_ext(&rows, lld_topology_row_free);
	zbx_vector_lld_topo_row_ptr_destroy(&rows);
}
