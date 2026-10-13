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

/*
 * "SNMP walk to topology rows" preprocessing step (topology-2-lld-delivery.md).
 *
 * Turns the text of an SNMP walk[] item into the rows of a topology role's macro contract (see CTopologyRole.php):
 * a JSON array of objects whose keys are LLD macros. The reference implementation is the temporary JavaScript of
 * database/topology/discovery/lld_js; the golden tests hold this file to its output. Where the JavaScript does
 * something the specification does not say, the JavaScript wins and the place is marked "as the JS".
 *
 * The walk text is parsed by the parser of "SNMP walk to JSON" (zbx_snmp_value_cache_init). Tables are not copied:
 * a table is a sorted array of (index suffix, pointer to the parsed pair).
 */

#include "preproc_topology.h"
#include "preproc_snmp.h"
#include "item_preproc.h"

#include "zbxalgo.h"
#include "zbxjson.h"
#include "zbxstr.h"

#include <arpa/inet.h>

#define TOPO_SOURCE_PORTS	1
#define TOPO_SOURCE_LLDP	2
#define TOPO_SOURCE_CDP		3
#define TOPO_SOURCE_FDB		4
#define TOPO_SOURCE_LAG		5

#define TOPO_DEFAULT_MAC_LIMIT	20

#define TOPO_ROW_FIELDS_MAX	20

/* OID bases, without the leading dot */
#define OID_IF_TABLE			"1.3.6.1.2.1.2.2.1"
#define OID_IF_INDEX			OID_IF_TABLE ".1"
#define OID_IF_DESCR			OID_IF_TABLE ".2"
#define OID_IF_TYPE			OID_IF_TABLE ".3"
#define OID_IF_PHYS_ADDRESS		OID_IF_TABLE ".6"
#define OID_IF_ADMIN_STATUS		OID_IF_TABLE ".7"
#define OID_IF_OPER_STATUS		OID_IF_TABLE ".8"
#define OID_IF_NAME			"1.3.6.1.2.1.31.1.1.1.1"
#define OID_IF_HIGH_SPEED		"1.3.6.1.2.1.31.1.1.1.15"

#define OID_LLDP_LOC_PORT_TABLE		"1.0.8802.1.1.2.1.3.7.1"
#define OID_LLDP_LOC_PORT_ID_SUBTYPE	OID_LLDP_LOC_PORT_TABLE ".2"
#define OID_LLDP_LOC_PORT_ID		OID_LLDP_LOC_PORT_TABLE ".3"
#define OID_LLDP_LOC_PORT_DESC		OID_LLDP_LOC_PORT_TABLE ".4"
#define OID_LLDP_REM_TABLE		"1.0.8802.1.1.2.1.4.1.1"
#define OID_LLDP_REM_CHASSIS_SUBTYPE	OID_LLDP_REM_TABLE ".4"
#define OID_LLDP_REM_CHASSIS_ID		OID_LLDP_REM_TABLE ".5"
#define OID_LLDP_REM_PORT_SUBTYPE	OID_LLDP_REM_TABLE ".6"
#define OID_LLDP_REM_PORT_ID		OID_LLDP_REM_TABLE ".7"
#define OID_LLDP_REM_PORT_DESC		OID_LLDP_REM_TABLE ".8"
#define OID_LLDP_REM_SYSNAME		OID_LLDP_REM_TABLE ".9"
#define OID_LLDP_REM_MAN_ADDR		"1.0.8802.1.1.2.1.4.2.1.3"

#define OID_CDP_CACHE_TABLE		"1.3.6.1.4.1.9.9.23.1.2.1.1"
#define OID_CDP_CACHE_ADDRESS_TYPE	OID_CDP_CACHE_TABLE ".3"
#define OID_CDP_CACHE_ADDRESS		OID_CDP_CACHE_TABLE ".4"
#define OID_CDP_CACHE_DEVICE_ID		OID_CDP_CACHE_TABLE ".6"
#define OID_CDP_CACHE_DEVICE_PORT	OID_CDP_CACHE_TABLE ".7"
#define OID_CDP_GLOBAL_RUN		"1.3.6.1.4.1.9.9.23.1.3.1.0"

#define OID_BRIDGE_BASE_PORT_IF_INDEX	"1.3.6.1.2.1.17.1.4.1.2"
#define OID_QBRIDGE_FDB_PORT		"1.3.6.1.2.1.17.7.1.2.2.1.2"
#define OID_QBRIDGE_FDB_STATUS		"1.3.6.1.2.1.17.7.1.2.2.1.3"
#define OID_BRIDGE_FDB_PORT		"1.3.6.1.2.1.17.4.3.1.2"
#define OID_BRIDGE_FDB_STATUS		"1.3.6.1.2.1.17.4.3.1.3"

#define OID_LAG_ATTACHED_AGG_ID		"1.2.840.10006.300.43.1.2.1.1.13"

#define FDB_STATUS_LEARNED		"3"

typedef struct
{
	const char			*suffix;
	const zbx_snmp_value_pair_t	*pair;
}
topo_cell_t;

ZBX_VECTOR_DECL(topo_cell, topo_cell_t)
ZBX_VECTOR_IMPL(topo_cell, topo_cell_t)

/* all variables under one OID base, sorted by the index suffix */
typedef zbx_vector_topo_cell_t	topo_table_t;

typedef struct
{
	const char	*key;
	char		*value;
}
topo_field_t;

typedef struct
{
	topo_field_t	fields[TOPO_ROW_FIELDS_MAX];
	int		fields_num;
	int		has_ifindex;
	zbx_uint64_t	ifindex;
	char		*sort_a;
	char		*sort_b;
}
topo_row_t;

ZBX_PTR_VECTOR_DECL(topo_row_ptr, topo_row_t *)
ZBX_PTR_VECTOR_IMPL(topo_row_ptr, topo_row_t *)

typedef struct
{
	int		source;
	int		missing_empty;
	zbx_uint64_t	mac_limit;
}
topo_params_t;

/******************************************************************************
 *                                                                            *
 * text helpers                                                               *
 *                                                                            *
 ******************************************************************************/

static void	topo_trim(char *str)
{
	char	*start = str;
	size_t	len;

	while ('\0' != *start && (' ' == *start || '\t' == *start || '\r' == *start || '\n' == *start))
		start++;

	len = strlen(start);

	while (0 < len && (' ' == start[len - 1] || '\t' == start[len - 1] || '\r' == start[len - 1] ||
			'\n' == start[len - 1] || '\0' == start[len - 1]))
	{
		len--;
	}

	if (start != str)
		memmove(str, start, len);

	str[len] = '\0';
}

/* strict decimal: digits only, no sign, no leading zero (as a JS object key would have to match) */
static int	topo_index_parse(const char *str, zbx_uint64_t *index)
{
	const char	*p;
	zbx_uint64_t	value = 0;

	if ('\0' == *str || ('0' == *str && '\0' != str[1]) || 19 < strlen(str))
		return FAIL;

	for (p = str; '\0' != *p; p++)
	{
		if (0 == isdigit((unsigned char)*p))
			return FAIL;

		value = value * 10 + (zbx_uint64_t)(*p - '0');
	}

	*index = value;

	return SUCCEED;
}

/* JS parseInt(): leading decimal integer, optional sign; FAIL when there is none */
static int	topo_parse_int(const char *str, long long *out)
{
	char	*end;

	while (' ' == *str || '\t' == *str)
		str++;

	if ('-' != *str && '+' != *str && 0 == isdigit((unsigned char)*str))
		return FAIL;

	if ('-' == *str || '+' == *str)
	{
		if (0 == isdigit((unsigned char)str[1]))
			return FAIL;
	}

	*out = strtoll(str, &end, 10);

	return SUCCEED;
}

/* dotted components compared as numbers when both are numbers, so 2 < 10 */
static int	topo_suffix_compare(const char *a, const char *b)
{
	while ('\0' != *a && '\0' != *b)
	{
		if (0 != isdigit((unsigned char)*a) && 0 != isdigit((unsigned char)*b))
		{
			char		*end_a, *end_b;
			unsigned long long	na = strtoull(a, &end_a, 10), nb = strtoull(b, &end_b, 10);

			if (na != nb)
				return na < nb ? -1 : 1;

			a = end_a;
			b = end_b;
			continue;
		}

		if (*a != *b)
			return (unsigned char)*a < (unsigned char)*b ? -1 : 1;

		a++;
		b++;
	}

	if (*a == *b)
		return 0;

	return '\0' == *a ? -1 : 1;
}

/* JS decode(): Hex-STRING -> lowercase colon hex; otherwise trimmed text, "name(3)" -> "3" for untyped numbers */
static char	*topo_decode(const zbx_snmp_value_pair_t *pair)
{
	char	*out = zbx_strdup(NULL, pair->value);

	topo_trim(out);

	if (ZBX_SNMP_TYPE_HEX == pair->type)
	{
		char	*src = out, *dst = out;
		int	pending_sep = 0, first = 1;

		for (; '\0' != *src; src++)
		{
			if (' ' == *src || '\t' == *src || '\n' == *src || '\r' == *src)
			{
				pending_sep = 1;
				continue;
			}

			if (0 != pending_sep && 0 == first)
				*dst++ = ':';

			pending_sep = 0;
			first = 0;
			*dst++ = (char)tolower((unsigned char)*src);
		}

		*dst = '\0';

		return out;
	}

	/* "up(1)" -> "1" for untyped numbers, like the JS does for INTEGER / Gauge32 */
	if (ZBX_SNMP_TYPE_UNDEFINED == pair->type)
	{
		size_t	len = strlen(out);
		char	*open;

		if (0 < len && ')' == out[len - 1] && NULL != (open = strrchr(out, '(')))
		{
			char	*digits = open + 1, *p = digits;

			if ('-' == *p)
				p++;

			if (0 != isdigit((unsigned char)*p))
			{
				while (0 != isdigit((unsigned char)*p))
					p++;

				if (p == out + len - 1)
				{
					size_t	num_len = (size_t)(p - digits);

					memmove(out, digits, num_len);
					out[num_len] = '\0';
				}
			}
		}
	}

	zbx_replace_invalid_utf8(out);

	return out;
}

/******************************************************************************
 *                                                                            *
 * tables                                                                     *
 *                                                                            *
 ******************************************************************************/

static int	topo_cell_compare(const void *d1, const void *d2)
{
	return strcmp(((const topo_cell_t *)d1)->suffix, ((const topo_cell_t *)d2)->suffix);
}

/* all variables of the walk below `base`; the suffix points into the OID of the parsed pair */
static void	topo_table_load(const zbx_snmp_value_cache_t *cache, const char *base, topo_table_t *table)
{
	zbx_hashset_const_iter_t	iter;
	const zbx_snmp_value_pair_t	*pair;
	size_t				base_len = strlen(base);

	zbx_vector_topo_cell_create(table);

	zbx_hashset_const_iter_reset(&cache->pairs, &iter);

	while (NULL != (pair = (const zbx_snmp_value_pair_t *)zbx_hashset_const_iter_next(&iter)))
	{
		const char	*oid = pair->oid;
		topo_cell_t	cell;

		if ('.' == *oid)
			oid++;

		if (0 != strncmp(oid, base, base_len) || '.' != oid[base_len] || '\0' == oid[base_len + 1])
			continue;

		cell.suffix = oid + base_len + 1;
		cell.pair = pair;
		zbx_vector_topo_cell_append(table, cell);
	}

	zbx_vector_topo_cell_sort(table, topo_cell_compare);
}

static const zbx_snmp_value_pair_t	*topo_table_get(const topo_table_t *table, const char *suffix)
{
	topo_cell_t	key;
	int		i;

	key.suffix = suffix;

	if (FAIL == (i = zbx_vector_topo_cell_bsearch(table, key, topo_cell_compare)))
		return NULL;

	return table->values[i].pair;
}

/* decoded value of a table cell, "" when there is none (caller frees) */
static char	*topo_table_text(const topo_table_t *table, const char *suffix)
{
	const zbx_snmp_value_pair_t	*pair = topo_table_get(table, suffix);

	return NULL != pair ? topo_decode(pair) : zbx_strdup(NULL, "");
}

/* is any variable of the walk below `oid` (a base) or `oid` itself */
static int	topo_walk_has(const zbx_snmp_value_cache_t *cache, const char *oid)
{
	zbx_hashset_const_iter_t	iter;
	const zbx_snmp_value_pair_t	*pair;
	size_t				len = strlen(oid);

	zbx_hashset_const_iter_reset(&cache->pairs, &iter);

	while (NULL != (pair = (const zbx_snmp_value_pair_t *)zbx_hashset_const_iter_next(&iter)))
	{
		const char	*p = pair->oid;

		if ('.' == *p)
			p++;

		if (0 == strncmp(p, oid, len) && ('\0' == p[len] || '.' == p[len]))
			return SUCCEED;
	}

	return FAIL;
}

static void	topo_table_clear(topo_table_t *table)
{
	zbx_vector_topo_cell_destroy(table);
}

/******************************************************************************
 *                                                                            *
 * decoding (topology-6-normalization.md §3): the only thing the step does to *
 * a value. Same rules in push.py; decoding_cases.json tests both.            *
 *                                                                            *
 ******************************************************************************/

/* a control character is a C0 character other than tab, DEL, or a C1 character (U+0080..U+009F) */
static int	topo_has_control(const unsigned char *s, size_t len)
{
	for (size_t i = 0; i < len; i++)
	{
		if ((s[i] < 0x20 && 0x09 != s[i]) || 0x7f == s[i])
			return SUCCEED;

		if (0xc2 == s[i] && i + 1 < len && 0x80 <= s[i + 1] && 0x9f >= s[i + 1])
			return SUCCEED;
	}

	return FAIL;
}

/* the hex form: lower case pairs separated by ':' (aa:bb:cc) */
static char	*topo_hex_form(const unsigned char *bytes, size_t len)
{
	char	*out = (char *)zbx_malloc(NULL, 3 * len + 1);
	size_t	offset = 0;

	for (size_t i = 0; i < len; i++)
		offset += zbx_snprintf(out + offset, 4, "%s%02x", 0 == i ? "" : ":", bytes[i]);

	out[offset] = '\0';

	return out;
}

/* the bytes of a Hex-STRING value ("AA bb 0C"); *len is their number */
static unsigned char	*topo_hex_bytes(const char *value, size_t *len)
{
	unsigned char	*bytes = (unsigned char *)zbx_malloc(NULL, strlen(value) / 2 + 1);
	char		*copy = zbx_strdup(NULL, value), *p, *save = NULL;

	*len = 0;

	for (p = strtok_r(copy, " \t\r\n:", &save); NULL != p; p = strtok_r(NULL, " \t\r\n:", &save))
		bytes[(*len)++] = (unsigned char)strtoul(p, NULL, 16);

	zbx_free(copy);

	return bytes;
}

/* a text column from a Hex-STRING: trailing NUL bytes removed; the rest is text when it is valid UTF-8 without a control character other than tab, else
 * the hex form */
static char	*topo_text_or_hex(const unsigned char *bytes, size_t len)
{
	char	*out;

	while (0 < len && '\0' == bytes[len - 1])
		len--;

	out = (char *)zbx_malloc(NULL, len + 1);
	memcpy(out, bytes, len);
	out[len] = '\0';

	if (strlen(out) == len && SUCCEED == zbx_is_utf8(out) && SUCCEED != topo_has_control(bytes, len))
		return out;

	zbx_free(out);

	return topo_hex_form(bytes, len);
}

/* a text column (DisplayString, a text subtype id): a STRING is passed as it is (no trim, no case); a Hex-STRING is text or the hex form */
static char	*topo_decode_text(const zbx_snmp_value_pair_t *pair)
{
	char	*out;

	if (ZBX_SNMP_TYPE_HEX == pair->type)
	{
		size_t		len;
		unsigned char	*bytes = topo_hex_bytes(pair->value, &len);

		out = topo_text_or_hex(bytes, len);
		zbx_free(bytes);

		return out;
	}

	out = zbx_strdup(NULL, pair->value);
	zbx_replace_invalid_utf8(out);

	return out;
}

/* a binary column (macAddress and networkAddress ids, ifPhysAddress, CDP addresses): the hex form; a STRING (a device that sends text) is passed as text */
static char	*topo_decode_binary(const zbx_snmp_value_pair_t *pair)
{
	if (ZBX_SNMP_TYPE_HEX == pair->type)
	{
		size_t		len;
		unsigned char	*bytes = topo_hex_bytes(pair->value, &len);
		char		*out = topo_hex_form(bytes, len);

		zbx_free(bytes);

		return out;
	}

	return topo_decode_text(pair);
}

static char	*topo_table_name(const topo_table_t *table, const char *suffix)
{
	const zbx_snmp_value_pair_t	*pair = topo_table_get(table, suffix);

	return NULL != pair ? topo_decode_text(pair) : zbx_strdup(NULL, "");
}

static char	*topo_table_binary(const topo_table_t *table, const char *suffix)
{
	const zbx_snmp_value_pair_t	*pair = topo_table_get(table, suffix);

	return NULL != pair ? topo_decode_binary(pair) : zbx_strdup(NULL, "");
}

static const char	*lldp_chassis_subtype_name(long long type)
{
	switch (type)
	{
		case 1: return "chassisComponent";
		case 2: return "interfaceAlias";
		case 3: return "portComponent";
		case 4: return "macAddress";
		case 5: return "networkAddress";
		case 6: return "interfaceName";
		case 7: return "local";
		default: return NULL;
	}
}

static const char	*lldp_port_subtype_name(long long type)
{
	switch (type)
	{
		case 1: return "interfaceAlias";
		case 2: return "portComponent";
		case 3: return "macAddress";
		case 4: return "networkAddress";
		case 5: return "interfaceName";
		case 6: return "agentCircuitId";
		case 7: return "local";
		default: return NULL;
	}
}

/* a cell decoded and parsed as an integer, 0 when there is none */
static long long	topo_table_int(const topo_table_t *table, const char *suffix)
{
	char		*text = topo_table_text(table, suffix);
	long long	value = 0;

	if (SUCCEED != topo_parse_int(text, &value))
		value = 0;

	zbx_free(text);

	return value;
}

/* a chassis / port id by its subtype: macAddress and networkAddress are binary (hex form), the other subtypes are text; without a subtype the type of the
 * value decides (a Hex-STRING is binary, a STRING is text). chassis = 1: the chassis id subtypes, 0: the port id subtypes. */
static char	*topo_decode_id(const zbx_snmp_value_pair_t *pair, long long subtype, int chassis)
{
	int	binary;

	if (0 == subtype)
		binary = ZBX_SNMP_TYPE_HEX == pair->type;
	else if (0 != chassis)
		binary = 4 == subtype || 5 == subtype;
	else
		binary = 3 == subtype || 4 == subtype;

	return 0 != binary ? topo_decode_binary(pair) : topo_decode_text(pair);
}

/* the device's own chassis id (lldpLocChassisId.0 with lldpLocChassisIdSubtype.0): never guessed (no fallback to a port MAC); *type is the name of the
 * subtype or NULL */
static char	*topo_local_chassis(const zbx_snmp_value_cache_t *cache, const char **type)
{
	topo_table_t			loc, loc_type;
	const zbx_snmp_value_pair_t	*pair;
	char				*chassis;
	long long			subtype;

	topo_table_load(cache, "1.0.8802.1.1.2.1.3.1", &loc_type);
	topo_table_load(cache, "1.0.8802.1.1.2.1.3.2", &loc);

	subtype = topo_table_int(&loc_type, "0");
	chassis = NULL != (pair = topo_table_get(&loc, "0")) ? topo_decode_id(pair, subtype, 1) : zbx_strdup(NULL, "");
	*type = '\0' != *chassis ? lldp_chassis_subtype_name(subtype) : NULL;

	topo_table_clear(&loc);
	topo_table_clear(&loc_type);

	return chassis;
}

/******************************************************************************
 *                                                                            *
 * rows                                                                       *
 *                                                                            *
 ******************************************************************************/

static topo_row_t	*topo_row_create(void)
{
	return (topo_row_t *)zbx_calloc(NULL, 1, sizeof(topo_row_t));
}

static void	topo_row_free(topo_row_t *row)
{
	for (int i = 0; i < row->fields_num; i++)
		zbx_free(row->fields[i].value);

	zbx_free(row->sort_a);
	zbx_free(row->sort_b);
	zbx_free(row);
}

/* takes ownership of value */
static void	topo_row_add_owned(topo_row_t *row, const char *key, char *value)
{
	/* an absent value is omitted, not written as an empty string (model §5.3) */
	if ('\0' == *value)
	{
		zbx_free(value);
		return;
	}

	if (TOPO_ROW_FIELDS_MAX == row->fields_num)
	{
		THIS_SHOULD_NEVER_HAPPEN;
		zbx_free(value);
		return;
	}

	row->fields[row->fields_num].key = key;
	row->fields[row->fields_num].value = value;
	row->fields_num++;
}

static void	topo_row_add(topo_row_t *row, const char *key, const char *value)
{
	topo_row_add_owned(row, key, zbx_strdup(NULL, value));
}

static void	topo_row_add_ifindex(topo_row_t *row, zbx_uint64_t ifindex)
{
	row->has_ifindex = 1;
	row->ifindex = ifindex;
	topo_row_add_owned(row, "{#IFINDEX}", zbx_dsprintf(NULL, ZBX_FS_UI64, ifindex));
}

/* {#LOC_CHASSIS} and its subtype when the device's own chassis id is known */
static void	topo_row_add_chassis(topo_row_t *row, const char *chassis, const char *chassis_type)
{
	if ('\0' != *chassis)
	{
		topo_row_add(row, "{#LOC_CHASSIS}", chassis);

		if (NULL != chassis_type)
			topo_row_add(row, "{#LOC_CHASSIS_TYPE}", chassis_type);
	}
}

static int	topo_row_compare(const void *d1, const void *d2)
{
	const topo_row_t	*a = *(const topo_row_t * const *)d1, *b = *(const topo_row_t * const *)d2;
	int			ret;

	if (a->has_ifindex != b->has_ifindex)
		return a->has_ifindex < b->has_ifindex ? -1 : 1;

	if (a->ifindex != b->ifindex)
		return a->ifindex < b->ifindex ? -1 : 1;

	if (NULL != a->sort_a && NULL != b->sort_a && 0 != (ret = topo_suffix_compare(a->sort_a, b->sort_a)))
		return ret;

	if (NULL != a->sort_b && NULL != b->sort_b)
		return strcmp(a->sort_b, b->sort_b);

	return 0;
}

static char	*topo_rows_serialize(zbx_vector_topo_row_ptr_t *rows)
{
	struct zbx_json	json;
	char		*out;

	zbx_vector_topo_row_ptr_sort(rows, topo_row_compare);

	zbx_json_initarray(&json, ZBX_JSON_STAT_BUF_LEN);

	for (int i = 0; i < rows->values_num; i++)
	{
		const topo_row_t	*row = rows->values[i];

		zbx_json_addobject(&json, NULL);

		for (int j = 0; j < row->fields_num; j++)
			zbx_json_addstring(&json, row->fields[j].key, row->fields[j].value, ZBX_JSON_TYPE_STRING);

		zbx_json_close(&json);
	}

	zbx_json_close(&json);
	out = zbx_strdup(NULL, json.buffer);
	zbx_json_free(&json);

	return out;
}

/******************************************************************************
 *                                                                            *
 * source: ports (IF-MIB)                                                     *
 *                                                                            *
 ******************************************************************************/
static void	topo_source_ports(const zbx_snmp_value_cache_t *cache, const char *chassis, const char *chassis_type,
		zbx_vector_topo_row_ptr_t *rows)
{
	topo_table_t	indexes, names, descr, phys, types, admin, oper, high_speed;

	topo_table_load(cache, OID_IF_INDEX, &indexes);
	topo_table_load(cache, OID_IF_NAME, &names);
	topo_table_load(cache, OID_IF_DESCR, &descr);
	topo_table_load(cache, OID_IF_PHYS_ADDRESS, &phys);
	topo_table_load(cache, OID_IF_TYPE, &types);
	topo_table_load(cache, OID_IF_ADMIN_STATUS, &admin);
	topo_table_load(cache, OID_IF_OPER_STATUS, &oper);
	topo_table_load(cache, OID_IF_HIGH_SPEED, &high_speed);

	for (int i = 0; i < indexes.values_num; i++)
	{
		const char	*key = indexes.values[i].suffix;
		zbx_uint64_t	index;
		topo_row_t	*row;

		if (SUCCEED != topo_index_parse(key, &index))
			continue;

		row = topo_row_create();
		topo_row_add_ifindex(row, index);

		/* the names as the walk has them: ingest cleans, compares and chooses (no fallback of ifName to ifDescr here) */
		topo_row_add_owned(row, "{#IFNAME}", topo_table_name(&names, key));
		topo_row_add_owned(row, "{#IFDESCR}", topo_table_name(&descr, key));

		if (NULL != topo_table_get(&types, key))
			topo_row_add_owned(row, "{#IFTYPE}", topo_table_text(&types, key));

		topo_row_add_owned(row, "{#IFMAC}", topo_table_binary(&phys, key));

		if (NULL != topo_table_get(&admin, key))
			topo_row_add_owned(row, "{#IFADMINSTATUS}", topo_table_text(&admin, key));

		if (NULL != topo_table_get(&oper, key))
			topo_row_add_owned(row, "{#IFOPERSTATUS}", topo_table_text(&oper, key));

		/* ifHighSpeed, Mbit/s. The spec asks for an ifSpeed fallback for devices without ifXTable; the JS has none,
		 * so neither does this step (as the JS, see the README of step_golden) */
		if (NULL != topo_table_get(&high_speed, key))
			topo_row_add_owned(row, "{#IFSPEED}", topo_table_text(&high_speed, key));

		topo_row_add_chassis(row, chassis, chassis_type);
		zbx_vector_topo_row_ptr_append(rows, row);
	}

	topo_table_clear(&indexes);
	topo_table_clear(&names);
	topo_table_clear(&descr);
	topo_table_clear(&phys);
	topo_table_clear(&types);
	topo_table_clear(&admin);
	topo_table_clear(&oper);
	topo_table_clear(&high_speed);
}

/******************************************************************************
 *                                                                            *
 * source: lldp (LLDP-MIB)                                                    *
 *                                                                            *
 ******************************************************************************/

static int	topo_str_ptr_compare(const void *d1, const void *d2)
{
	return strcmp(*(const char * const *)d1, *(const char * const *)d2);
}

/* the lldpRemManAddrTable entries of a neighbor (LLDP spec §4.4): the neighbor is LocalPortNum.RemIndex, the TimeMark of the index is ignored; every entry
 * is emitted as "lldp:<family>.[<length>.]<address octets>" (the index part after RemIndex), space separated, ordered by that part and then by the
 * whole index. The step chooses nothing: ingest reads the entries (normalization §4.4). */
static char	*lldp_man_addrs(const topo_table_t *man_addr, const char *suffix)
{
	const char	*pair = strchr(suffix, '.');
	size_t		len, alloc = 0, offset = 0;
	char		*out = NULL;
	zbx_vector_ptr_t	hits;

	if (NULL == pair)
		return zbx_strdup(NULL, "");

	pair++;
	len = strlen(pair);
	zbx_vector_ptr_create(&hits);

	for (int i = 0; i < man_addr->values_num; i++)
	{
		const char	*key = man_addr->values[i].suffix, *rest = strchr(key, '.');

		if (NULL == rest || 0 != strncmp(rest + 1, pair, len) || '.' != rest[1 + len])
			continue;

		zbx_vector_ptr_append(&hits, (void *)(rest + 1 + len + 1));
	}

	/* insertion sort by the numeric order of the index part (the vector is short) */
	for (int i = 1; i < hits.values_num; i++)
	{
		void	*item = hits.values[i];
		int	j = i - 1;

		while (0 <= j && 0 < topo_suffix_compare((const char *)hits.values[j], (const char *)item))
		{
			hits.values[j + 1] = hits.values[j];
			j--;
		}

		hits.values[j + 1] = item;
	}

	for (int i = 0; i < hits.values_num; i++)
		zbx_snprintf_alloc(&out, &alloc, &offset, "%slldp:%s", 0 == i ? "" : " ", (const char *)hits.values[i]);

	zbx_vector_ptr_destroy(&hits);

	return NULL != out ? out : zbx_strdup(NULL, "");
}

/* One row per lldpRemTable entry (index TimeMark.LocalPortNum.RemIndex). The local port is NOT resolved here (model §6.7.6): the row carries the
 * LocalPortNum and the local port id, its subtype and description from lldpLocPortTable. Nothing is dropped. */
static void	topo_source_lldp(const zbx_snmp_value_cache_t *cache, const char *chassis, const char *chassis_type,
		zbx_vector_topo_row_ptr_t *rows)
{
	topo_table_t		rem_chassis_type, rem_chassis, rem_port_type, rem_port, rem_port_desc, rem_sysname,
				loc_type, loc_id, loc_desc, man_addr;
	topo_table_t		*columns[6] = {&rem_chassis, &rem_port, &rem_port_desc, &rem_sysname, &rem_chassis_type,
					&rem_port_type};
	zbx_vector_ptr_t	suffixes;
	const char		*last = NULL;

	topo_table_load(cache, OID_LLDP_REM_CHASSIS_SUBTYPE, &rem_chassis_type);
	topo_table_load(cache, OID_LLDP_REM_CHASSIS_ID, &rem_chassis);
	topo_table_load(cache, OID_LLDP_REM_PORT_SUBTYPE, &rem_port_type);
	topo_table_load(cache, OID_LLDP_REM_PORT_ID, &rem_port);
	topo_table_load(cache, OID_LLDP_REM_PORT_DESC, &rem_port_desc);
	topo_table_load(cache, OID_LLDP_REM_SYSNAME, &rem_sysname);
	topo_table_load(cache, OID_LLDP_LOC_PORT_ID_SUBTYPE, &loc_type);
	topo_table_load(cache, OID_LLDP_LOC_PORT_ID, &loc_id);
	topo_table_load(cache, OID_LLDP_LOC_PORT_DESC, &loc_desc);
	topo_table_load(cache, OID_LLDP_REM_MAN_ADDR, &man_addr);

	zbx_vector_ptr_create(&suffixes);

	/* every column shares the same TimeMark.LocalPortNum.RemIndex suffixes */
	for (int c = 0; c < 6; c++)
	{
		for (int i = 0; i < columns[c]->values_num; i++)
			zbx_vector_ptr_append(&suffixes, (void *)columns[c]->values[i].suffix);
	}

	zbx_vector_ptr_sort(&suffixes, topo_str_ptr_compare);

	for (int i = 0; i < suffixes.values_num; i++)
	{
		const char			*suffix = (const char *)suffixes.values[i];
		char				*port_num, *dot, *addrs;
		const char			*second;
		const zbx_snmp_value_pair_t	*pair;
		long long			chassis_type_n, port_type, loc_port_type;
		zbx_uint64_t			number;
		topo_row_t			*row;
		int				parts = 1;

		if (NULL != last && 0 == strcmp(last, suffix))
			continue;

		last = suffix;

		for (const char *p = suffix; '\0' != *p; p++)
			parts += ('.' == *p);

		if (parts < 3)
			continue;	/* not a TimeMark.LocalPortNum.RemIndex index */

		second = strchr(suffix, '.') + 1;
		port_num = zbx_strdup(NULL, second);

		if (NULL != (dot = strchr(port_num, '.')))
			*dot = '\0';

		row = topo_row_create();
		row->sort_a = zbx_strdup(NULL, suffix);

		/* the rows are ordered by the local port number (LLD spec §4.3); it is not an ifIndex and is not emitted as {#IFINDEX} */
		if (SUCCEED == topo_index_parse(port_num, &number))
		{
			row->has_ifindex = 1;
			row->ifindex = number;
			topo_row_add(row, "{#LOC_PORT_NUM}", port_num);
		}

		loc_port_type = topo_table_int(&loc_type, port_num);

		if (NULL != (pair = topo_table_get(&loc_id, port_num)))
		{
			topo_row_add_owned(row, "{#LOC_PORT_ID}", topo_decode_id(pair, loc_port_type, 0));

			if (NULL != lldp_port_subtype_name(loc_port_type))
				topo_row_add(row, "{#LOC_PORT_ID_TYPE}", lldp_port_subtype_name(loc_port_type));
		}

		topo_row_add_owned(row, "{#LOC_PORT_DESC}", topo_table_name(&loc_desc, port_num));
		zbx_free(port_num);

		topo_row_add(row, "{#SOURCE}", "lldp");

		chassis_type_n = topo_table_int(&rem_chassis_type, suffix);
		port_type = topo_table_int(&rem_port_type, suffix);

		if (NULL != (pair = topo_table_get(&rem_chassis, suffix)))
		{
			topo_row_add_owned(row, "{#REM_CHASSIS}", topo_decode_id(pair, chassis_type_n, 1));

			if (NULL != lldp_chassis_subtype_name(chassis_type_n))
				topo_row_add(row, "{#REM_CHASSIS_TYPE}", lldp_chassis_subtype_name(chassis_type_n));
		}

		addrs = lldp_man_addrs(&man_addr, suffix);
		topo_row_add_owned(row, "{#REM_MAN_ADDRS}", addrs);

		topo_row_add_owned(row, "{#REM_SYSNAME}", topo_table_name(&rem_sysname, suffix));

		if (NULL != (pair = topo_table_get(&rem_port, suffix)))
		{
			topo_row_add_owned(row, "{#REM_PORT}", topo_decode_id(pair, port_type, 0));

			if (NULL != lldp_port_subtype_name(port_type))
				topo_row_add(row, "{#REM_PORT_TYPE}", lldp_port_subtype_name(port_type));
		}

		topo_row_add_owned(row, "{#REM_PORT_DESC}", topo_table_name(&rem_port_desc, suffix));

		topo_row_add_chassis(row, chassis, chassis_type);
		zbx_vector_topo_row_ptr_append(rows, row);
	}

	zbx_vector_ptr_destroy(&suffixes);

	topo_table_clear(&rem_chassis_type);
	topo_table_clear(&rem_chassis);
	topo_table_clear(&rem_port_type);
	topo_table_clear(&rem_port);
	topo_table_clear(&rem_port_desc);
	topo_table_clear(&rem_sysname);
	topo_table_clear(&loc_type);
	topo_table_clear(&loc_id);
	topo_table_clear(&loc_desc);
	topo_table_clear(&man_addr);
}

/******************************************************************************
 *                                                                            *
 * source: cdp (CISCO-CDP-MIB)                                                *
 *                                                                            *
 ******************************************************************************/

/* One row per cdpCacheTable entry (index ifIndex.DeviceIndex). cdpCacheDeviceId is a hostname-like string, not a chassis id: it goes to {#REM_SYSNAME},
 * and identity relies on the management address and the sysname. The address is emitted raw as "cdp:<cdpCacheAddressType>:<hex octets>" (ingest decodes it,
 * normalization §4.4). */
static void	topo_source_cdp(const zbx_snmp_value_cache_t *cache, const char *chassis, const char *chassis_type,
		zbx_vector_topo_row_ptr_t *rows)
{
	topo_table_t		device_id, device_port, address, address_type, names;
	topo_table_t		*columns[4] = {&device_id, &device_port, &address, &address_type};
	zbx_vector_ptr_t	suffixes;
	const char		*last = NULL;

	topo_table_load(cache, OID_CDP_CACHE_DEVICE_ID, &device_id);
	topo_table_load(cache, OID_CDP_CACHE_DEVICE_PORT, &device_port);
	topo_table_load(cache, OID_CDP_CACHE_ADDRESS, &address);
	topo_table_load(cache, OID_CDP_CACHE_ADDRESS_TYPE, &address_type);
	topo_table_load(cache, OID_IF_NAME, &names);

	zbx_vector_ptr_create(&suffixes);

	for (int c = 0; c < 4; c++)
	{
		for (int i = 0; i < columns[c]->values_num; i++)
			zbx_vector_ptr_append(&suffixes, (void *)columns[c]->values[i].suffix);
	}

	zbx_vector_ptr_sort(&suffixes, topo_str_ptr_compare);

	for (int i = 0; i < suffixes.values_num; i++)
	{
		const char			*suffix = (const char *)suffixes.values[i];
		char				*index_text, *dot;
		zbx_uint64_t			ifindex;
		const zbx_snmp_value_pair_t	*pair;
		topo_row_t			*row;

		if (NULL != last && 0 == strcmp(last, suffix))
			continue;

		last = suffix;

		if (NULL == (dot = strchr(suffix, '.')))
			continue;	/* not an ifIndex.DeviceIndex index */

		index_text = zbx_strdup(NULL, suffix);
		index_text[dot - suffix] = '\0';

		if (SUCCEED != topo_index_parse(index_text, &ifindex))
		{
			zbx_free(index_text);
			continue;
		}

		row = topo_row_create();
		row->sort_a = zbx_strdup(NULL, suffix);

		topo_row_add_ifindex(row, ifindex);
		topo_row_add_owned(row, "{#IFNAME}", topo_table_name(&names, index_text));
		zbx_free(index_text);

		topo_row_add_owned(row, "{#REM_SYSNAME}", topo_table_name(&device_id, suffix));

		if (NULL != (pair = topo_table_get(&address, suffix)))
		{
			char	*octets = topo_decode_binary(pair);

			topo_row_add_owned(row, "{#REM_MAN_ADDRS}", zbx_dsprintf(NULL, "cdp:%lld:%s", topo_table_int(&address_type, suffix), octets));
			zbx_free(octets);
		}

		if (NULL != topo_table_get(&device_port, suffix))
		{
			topo_row_add_owned(row, "{#REM_PORT}", topo_table_name(&device_port, suffix));
			topo_row_add(row, "{#REM_PORT_TYPE}", "interfaceName");
		}

		topo_row_add(row, "{#SOURCE}", "cdp");
		topo_row_add_chassis(row, chassis, chassis_type);
		zbx_vector_topo_row_ptr_append(rows, row);
	}

	zbx_vector_ptr_destroy(&suffixes);

	topo_table_clear(&device_id);
	topo_table_clear(&device_port);
	topo_table_clear(&address);
	topo_table_clear(&address_type);
	topo_table_clear(&names);
}

/******************************************************************************
 *                                                                            *
 * source: fdb (BRIDGE-MIB / Q-BRIDGE-MIB)                                    *
 *                                                                            *
 ******************************************************************************/

typedef struct
{
	zbx_uint64_t	ifindex;
	char		mac[18];
	char		*vlan;
}
topo_fdb_entry_t;

ZBX_VECTOR_DECL(topo_fdb_entry, topo_fdb_entry_t)
ZBX_VECTOR_IMPL(topo_fdb_entry, topo_fdb_entry_t)

static int	topo_fdb_entry_compare(const void *d1, const void *d2)
{
	const topo_fdb_entry_t	*a = (const topo_fdb_entry_t *)d1, *b = (const topo_fdb_entry_t *)d2;
	int			ret;

	ZBX_RETURN_IF_NOT_EQUAL(a->ifindex, b->ifindex);

	if (0 != (ret = strcmp(a->mac, b->mac)))
		return ret;

	return strcmp(a->vlan, b->vlan);
}

/* "170.187.204.1.2.3" -> "aa:bb:cc:01:02:03"; FAIL unless exactly six octets 0..255 */
static int	topo_fdb_mac(const char *octets, char *mac)
{
	int	num = 0;

	while ('\0' != *octets)
	{
		char		*end;
		unsigned long	value;

		if (0 == isdigit((unsigned char)*octets) || 6 == num)
			return FAIL;

		value = strtoul(octets, &end, 10);

		if (255 < value || ('.' != *end && '\0' != *end))
			return FAIL;

		zbx_snprintf(mac + num * 3, 4, "%02lx:", value);
		num++;
		octets = end;

		if ('.' == *octets)
			octets++;
	}

	if (6 != num)
		return FAIL;

	mac[17] = '\0';

	return SUCCEED;
}

/* Q-BRIDGE (dot1qTpFdbTable) when the device has it, else BRIDGE (dot1dTpFdbTable). Only "learned" entries on a
 * port that maps to an ifIndex through dot1dBasePortIfIndex. A port with more than mac_limit MACs (a trunk) gets
 * one count-only row. */
static void	topo_source_fdb(const zbx_snmp_value_cache_t *cache, zbx_uint64_t mac_limit,
		zbx_vector_topo_row_ptr_t *rows)
{
	topo_table_t			base_ports, q_ports, ports, status;
	int				use_q;
	zbx_vector_topo_fdb_entry_t	entries;

	topo_table_load(cache, OID_BRIDGE_BASE_PORT_IF_INDEX, &base_ports);
	topo_table_load(cache, OID_QBRIDGE_FDB_PORT, &q_ports);

	use_q = 0 < q_ports.values_num;

	if (0 != use_q)
	{
		ports = q_ports;
		topo_table_load(cache, OID_QBRIDGE_FDB_STATUS, &status);
	}
	else
	{
		topo_table_clear(&q_ports);
		topo_table_load(cache, OID_BRIDGE_FDB_PORT, &ports);
		topo_table_load(cache, OID_BRIDGE_FDB_STATUS, &status);
	}

	zbx_vector_topo_fdb_entry_create(&entries);

	for (int i = 0; i < ports.values_num; i++)
	{
		const char		*suffix = ports.values[i].suffix, *octets = suffix;
		char			*state, *port, *if_text, *vlan = NULL;
		topo_fdb_entry_t	entry;
		zbx_uint64_t		ifindex;
		int			ok;

		state = topo_table_text(&status, suffix);
		ok = 0 == strcmp(state, FDB_STATUS_LEARNED);
		zbx_free(state);

		if (0 == ok)
			continue;

		/* Q-BRIDGE index: <fdbId>.<6 mac octets>; BRIDGE index: <6 mac octets> */
		if (0 != use_q)
		{
			const char	*dot = strchr(suffix, '.');

			if (NULL == dot)
				continue;

			vlan = zbx_strdup(NULL, suffix);
			vlan[dot - suffix] = '\0';
			octets = dot + 1;
		}
		else
			vlan = zbx_strdup(NULL, "");

		if (SUCCEED != topo_fdb_mac(octets, entry.mac))
		{
			zbx_free(vlan);
			continue;
		}

		port = topo_decode(ports.values[i].pair);
		if_text = topo_table_text(&base_ports, port);

		/* not learned on a port we can name */
		if (0 == strcmp(port, "0") || '\0' == *if_text || SUCCEED != topo_index_parse(if_text, &ifindex))
		{
			zbx_free(port);
			zbx_free(if_text);
			zbx_free(vlan);
			continue;
		}

		zbx_free(port);
		zbx_free(if_text);

		entry.ifindex = ifindex;
		entry.vlan = vlan;
		zbx_vector_topo_fdb_entry_append(&entries, entry);
	}

	zbx_vector_topo_fdb_entry_sort(&entries, topo_fdb_entry_compare);

	for (int i = 0; i < entries.values_num;)
	{
		int	j = i;

		while (j < entries.values_num && entries.values[j].ifindex == entries.values[i].ifindex)
			j++;

		if ((zbx_uint64_t)(j - i) > mac_limit)
		{
			topo_row_t	*row = topo_row_create();

			topo_row_add_ifindex(row, entries.values[i].ifindex);
			topo_row_add_owned(row, "{#PORT_MAC_COUNT}", zbx_dsprintf(NULL, "%d", j - i));
			zbx_vector_topo_row_ptr_append(rows, row);
		}
		else
		{
			for (int k = i; k < j; k++)
			{
				topo_row_t	*row = topo_row_create();

				topo_row_add_ifindex(row, entries.values[k].ifindex);
				topo_row_add(row, "{#MAC}", entries.values[k].mac);

				if ('\0' != *entries.values[k].vlan)
					topo_row_add(row, "{#VLAN}", entries.values[k].vlan);

				row->sort_b = zbx_dsprintf(NULL, "%s|%s", entries.values[k].mac, entries.values[k].vlan);
				zbx_vector_topo_row_ptr_append(rows, row);
			}
		}

		i = j;
	}

	for (int i = 0; i < entries.values_num; i++)
		zbx_free(entries.values[i].vlan);

	zbx_vector_topo_fdb_entry_destroy(&entries);

	topo_table_clear(&ports);
	topo_table_clear(&status);
	topo_table_clear(&base_ports);
}

/******************************************************************************
 *                                                                            *
 * source: lag (IEEE8023-LAG-MIB)                                             *
 *                                                                            *
 ******************************************************************************/

/* dot3adAggPortAttachedAggID is indexed by the member's ifIndex; the value is the aggregator's ifIndex. 0 means
 * "not aggregated"; a port attached to itself is skipped. */
static void	topo_source_lag(const zbx_snmp_value_cache_t *cache, zbx_vector_topo_row_ptr_t *rows)
{
	topo_table_t	attached;

	topo_table_load(cache, OID_LAG_ATTACHED_AGG_ID, &attached);

	for (int i = 0; i < attached.values_num; i++)
	{
		zbx_uint64_t	member;
		char		*text;
		long long	agg;
		topo_row_t	*row;

		if (SUCCEED != topo_index_parse(attached.values[i].suffix, &member))
			continue;

		text = topo_decode(attached.values[i].pair);

		if (SUCCEED != topo_parse_int(text, &agg) || 0 == agg || (long long)member == agg)
		{
			zbx_free(text);
			continue;
		}

		zbx_free(text);

		row = topo_row_create();
		topo_row_add_ifindex(row, member);
		topo_row_add_owned(row, "{#LAG_IFINDEX}", zbx_dsprintf(NULL, "%lld", agg));
		zbx_vector_topo_row_ptr_append(rows, row);
	}

	topo_table_clear(&attached);
}

/******************************************************************************
 *                                                                            *
 * step                                                                       *
 *                                                                            *
 ******************************************************************************/

/* params: source \n missing_mib \n mac_limit, all but the first optional */
static int	topo_params_parse(const char *params, topo_params_t *out, char **errmsg)
{
	char	*copy, *line, *next, *save_source, *save_missing, *save_limit;

	out->source = 0;
	out->missing_empty = 0;
	out->mac_limit = TOPO_DEFAULT_MAC_LIMIT;

	if (NULL == params || '\0' == *params)
	{
		*errmsg = zbx_strdup(*errmsg, "parameter should be set");
		return FAIL;
	}

	copy = zbx_strdup(NULL, params);
	save_source = copy;

	if (NULL != (next = strchr(copy, '\n')))
		*next++ = '\0';

	save_missing = next;

	if (NULL != next && NULL != (next = strchr(next, '\n')))
		*next++ = '\0';

	save_limit = next;
	line = save_source;
	topo_trim(line);

	if (0 == strcmp(line, "ports"))
		out->source = TOPO_SOURCE_PORTS;
	else if (0 == strcmp(line, "lldp"))
		out->source = TOPO_SOURCE_LLDP;
	else if (0 == strcmp(line, "cdp"))
		out->source = TOPO_SOURCE_CDP;
	else if (0 == strcmp(line, "fdb"))
		out->source = TOPO_SOURCE_FDB;
	else if (0 == strcmp(line, "lag"))
		out->source = TOPO_SOURCE_LAG;
	else
	{
		*errmsg = zbx_dsprintf(*errmsg, "invalid topology source \"%s\"", line);
		goto fail;
	}

	if (NULL != save_missing)
	{
		line = save_missing;
		topo_trim(line);

		if ('\0' == *line || 0 == strcmp(line, "error"))
			out->missing_empty = 0;
		else if (0 == strcmp(line, "empty"))
			out->missing_empty = 1;
		else
		{
			*errmsg = zbx_dsprintf(*errmsg, "invalid missing MIB action \"%s\"", line);
			goto fail;
		}
	}

	if (NULL != save_limit)
	{
		zbx_uint64_t	limit;

		line = save_limit;
		topo_trim(line);

		if ('\0' != *line)
		{
			if (SUCCEED != topo_index_parse(line, &limit) || 1 > limit)
			{
				*errmsg = zbx_dsprintf(*errmsg, "invalid MAC limit \"%s\"", line);
				goto fail;
			}

			out->mac_limit = limit;
		}
	}

	zbx_free(copy);

	return SUCCEED;
fail:
	zbx_free(copy);

	return FAIL;
}

/* Leading blank lines and lines that start with '#' are comments (the template's bridge master item stores
 * "# device has no bridge tables" when the device has none); trailing white space is dropped. Only the edges are
 * touched: a value that spans several lines is never altered. Returns the text to parse; when it had to be cut at
 * the end, that is a copy and *copy owns it. */
static const char	*topo_walk_trim(const char *data, char **copy)
{
	const char	*start = data, *end = data + strlen(data);

	*copy = NULL;

	for (;;)
	{
		const char	*p = start;

		while (' ' == *p || '\t' == *p || '\r' == *p)
			p++;

		if ('#' != *p && '\n' != *p)
			break;

		if (NULL == (p = strchr(p, '\n')))
			return "";

		start = p + 1;
	}

	while (end > start && (' ' == end[-1] || '\t' == end[-1] || '\r' == end[-1] || '\n' == end[-1]))
		end--;

	if (end == start)
		return "";

	/* nothing to cut: the text ends right after `end`, or after one line feed */
	if ('\0' == *end || ('\n' == *end && '\0' == end[1]))
		return start;

	*copy = (char *)zbx_malloc(NULL, (size_t)(end - start) + 2);
	memcpy(*copy, start, (size_t)(end - start));
	(*copy)[end - start] = '\n';
	(*copy)[end - start + 1] = '\0';

	return *copy;
}

static const char	*topo_anchor_missing(int source, const zbx_snmp_value_cache_t *cache)
{
	switch (source)
	{
		case TOPO_SOURCE_PORTS:
			return SUCCEED == topo_walk_has(cache, OID_IF_TYPE) ? NULL : "ifType";
		case TOPO_SOURCE_LLDP:
			/* the local port table, or the neighbor table of a device that does not expose it */
			return (SUCCEED == topo_walk_has(cache, OID_LLDP_LOC_PORT_TABLE) ||
					SUCCEED == topo_walk_has(cache, OID_LLDP_REM_TABLE)) ? NULL : "lldpLocPortTable";
		case TOPO_SOURCE_CDP:
			return (SUCCEED == topo_walk_has(cache, OID_CDP_CACHE_TABLE) ||
					SUCCEED == topo_walk_has(cache, OID_CDP_GLOBAL_RUN)) ? NULL : "cdpCacheTable";
		case TOPO_SOURCE_FDB:
			return SUCCEED == topo_walk_has(cache, OID_BRIDGE_BASE_PORT_IF_INDEX) ? NULL :
					"dot1dBasePortIfIndex";
		case TOPO_SOURCE_LAG:
			return SUCCEED == topo_walk_has(cache, OID_LAG_ATTACHED_AGG_ID) ? NULL :
					"dot3adAggPortAttachedAggID";
	}

	return "";
}

int	item_preproc_snmp_walk_to_topology(zbx_variant_t *value, const char *params, char **errmsg)
{
	topo_params_t			p;
	zbx_snmp_value_cache_t		cache;
	zbx_vector_topo_row_ptr_t	rows;
	char				*parse_error = NULL, *copy, *result = NULL, *chassis;
	const char			*chassis_type;
	const char			*missing, *text;

	if (FAIL == item_preproc_convert_value(value, ZBX_VARIANT_STR, errmsg))
		return FAIL;

	if (SUCCEED != topo_params_parse(params, &p, errmsg))
		return FAIL;

	text = topo_walk_trim(value->data.str, &copy);

	if (SUCCEED != zbx_snmp_value_cache_init(&cache, text, &parse_error))
	{
		*errmsg = zbx_dsprintf(*errmsg, "cannot parse the SNMP walk: %s", NULL != parse_error ? parse_error :
				"unknown error");
		zbx_free(parse_error);
		zbx_free(copy);

		return FAIL;
	}

	zbx_free(copy);
	zbx_free(parse_error);

	if (NULL != (missing = topo_anchor_missing(p.source, &cache)))
	{
		zbx_snmp_value_cache_clear(&cache);

		if (0 == p.missing_empty)
		{
			*errmsg = zbx_dsprintf(*errmsg, "the SNMP walk holds no %s (the device does not support the MIB,"
					" or the walk[] item does not cover it)", missing);
			return FAIL;
		}

		zbx_variant_clear(value);
		zbx_variant_set_str(value, zbx_strdup(NULL, "[]"));

		return SUCCEED;
	}

	zbx_vector_topo_row_ptr_create(&rows);
	chassis = topo_local_chassis(&cache, &chassis_type);

	switch (p.source)
	{
		case TOPO_SOURCE_PORTS:
			topo_source_ports(&cache, chassis, chassis_type, &rows);
			break;
		case TOPO_SOURCE_LLDP:
			topo_source_lldp(&cache, chassis, chassis_type, &rows);
			break;
		case TOPO_SOURCE_CDP:
			topo_source_cdp(&cache, chassis, chassis_type, &rows);
			break;
		case TOPO_SOURCE_FDB:
			topo_source_fdb(&cache, p.mac_limit, &rows);
			break;
		case TOPO_SOURCE_LAG:
			topo_source_lag(&cache, &rows);
			break;
	}

	result = topo_rows_serialize(&rows);

	zbx_free(chassis);
	zbx_vector_topo_row_ptr_clear_ext(&rows, topo_row_free);
	zbx_vector_topo_row_ptr_destroy(&rows);
	zbx_snmp_value_cache_clear(&cache);

	zbx_variant_clear(value);
	zbx_variant_set_str(value, result);

	return SUCCEED;
}
