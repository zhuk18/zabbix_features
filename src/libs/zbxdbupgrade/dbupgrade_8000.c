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

#include "dbupgrade.h"

/*
 * 8.0 maintenance database patches
 */

#ifndef HAVE_SQLITE3

static int	DBpatch_8000000(void)
{
	return SUCCEED;
}

/* topology model 2.104 (FR-B D-19, D-20; FR-C V-18 part 3): the time and the reason of the latest value that a rule's snapshot did not use because of its time */
static int	DBpatch_8000001(void)
{
	const zbx_db_field_t	field = {"ignored_clock", NULL, NULL, NULL, 0, ZBX_TYPE_INT, 0, 0};

	return DBadd_field("topo_lld_snapshot", &field);
}

static int	DBpatch_8000002(void)
{
	const zbx_db_field_t	field = {"ignored_reason", NULL, NULL, NULL, 16, ZBX_TYPE_CHAR, 0, 0};

	return DBadd_field("topo_lld_snapshot", &field);
}

#endif

DBPATCH_START(8000)

/* version, duplicates flag, mandatory flag */

DBPATCH_ADD(8000000, 0, 1)
DBPATCH_ADD(8000001, 0, 1)
DBPATCH_ADD(8000002, 0, 1)

DBPATCH_END()
