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

#include "checks_script.h"

#include "zbxembed.h"
#include "zbxjson.h"

#ifdef HAVE_LIBCURL
#include "checks_http.h"
#include "zbxhttp.h"
#endif

static void	script_set_oauth_bearer(zbx_es_t *es, const zbx_dc_item_t *item, const char *config_source_ip,
		const char *config_ssl_ca_location)
{
#ifdef HAVE_LIBCURL
	zbx_uint64_t	oauthprofileid;
	char		*oauth_bearer = NULL, *error = NULL;

	oauthprofileid = (0 != item->oauthprofileid) ? item->oauthprofileid : item->host.oauthprofileid;

	if (0 == oauthprofileid)
		return;

	if (SUCCEED == zbx_http_get_oauth_bearer(oauthprofileid, item->key_orig, item->timeout, config_source_ip,
			config_ssl_ca_location, ZBX_OAUTH_REFRESH_NORMAL, &oauth_bearer, &error) && NULL != oauth_bearer)
	{
		zbx_es_set_oauth_bearer(es, oauth_bearer);
	}

	zbx_free(oauth_bearer);
	zbx_free(error);
#else
	ZBX_UNUSED(es);
	ZBX_UNUSED(item);
	ZBX_UNUSED(config_source_ip);
	ZBX_UNUSED(config_ssl_ca_location);
#endif
}

int	get_value_script(zbx_dc_item_t *item, const char *config_source_ip, const char *config_ssl_ca_location,
		AGENT_RESULT *result)
{
	char		*error = NULL, *script_bin = NULL, *output = NULL;
	int		script_bin_sz, ret = NOTSUPPORTED;
	zbx_es_t	es_engine;
	struct zbx_json	json;

	zbx_es_init(&es_engine);

	if (SUCCEED != zbx_es_init_env(&es_engine, config_source_ip, &error))
	{
		SET_MSG_RESULT(result, zbx_dsprintf(NULL, "Cannot initialize scripting environment: %s", error));
		return ret;
	}

	script_set_oauth_bearer(&es_engine, item, config_source_ip, config_ssl_ca_location);

	zbx_json_init(&json, ZBX_JSON_STAT_BUF_LEN);

	if (SUCCEED != zbx_es_compile(&es_engine, item->params, &script_bin, &script_bin_sz, &error))
	{
		SET_MSG_RESULT(result, zbx_dsprintf(NULL, "Cannot compile script: %s", error));
		goto err;
	}

	zbx_es_set_timeout(&es_engine, item->timeout);

	for (int i = 0; i < item->script_params.values_num; i++)
	{
		zbx_json_addstring(&json, (const char *)item->script_params.values[i].first,
				(const char *)item->script_params.values[i].second, ZBX_JSON_TYPE_STRING);
	}

	if (SUCCEED != zbx_es_execute(&es_engine, NULL, script_bin, script_bin_sz, json.buffer, &output,
			&error))
	{
		SET_MSG_RESULT(result, zbx_dsprintf(NULL, "Cannot execute script: %s", error));
		goto err;
	}

	ret = SUCCEED;
	SET_TEXT_RESULT(result, NULL != output ? output : zbx_strdup(NULL, ""));
err:
	zbx_json_free(&json);
	zbx_es_destroy(&es_engine);

	zbx_free(script_bin);
	zbx_free(error);

	/* avoid memory not being released back to the system if there was memory-intensive script item */
	zbx_malloc_trim(time(NULL), 0, ZBX_MEBIBYTE);
	return ret;
}
