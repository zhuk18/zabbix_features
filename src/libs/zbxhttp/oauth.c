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

#include "zbxdb.h"
#include "zbxlog.h"
#include "zbxhttp.h"
#include "audit/zbxaudit.h"
#include "zbxalgo.h"
#include "zbxstr.h"

/* For token status, bit mask */
#define ZBX_OAUTH_TOKEN_ACCESS_VALID	1
#define ZBX_OAUTH_TOKEN_REFRESH_VALID	2

#define ZBX_OAUTH_TOKEN_VALID		(ZBX_OAUTH_TOKEN_ACCESS_VALID | ZBX_OAUTH_TOKEN_REFRESH_VALID)

typedef struct
{
	zbx_uint64_t	oauthprofileid;
	char		*bearer;
	int		expires;
}
zbx_oauth_profile_cache_t;

static zbx_hashset_t	oauth_profile_cache;
static unsigned char	oauth_profile_cache_initialized = 0;

typedef struct
{
	char	*token_url;
	char	*client_id;
	char	*client_secret;

	char	*old_refresh_token;
	char	*refresh_token;

	unsigned char	old_tokens_status;
	unsigned char	tokens_status;

	char	*old_access_token;
	char	*access_token;

	time_t	old_access_token_updated;
	time_t	access_token_updated;

	int	old_access_expires_in;
	int	access_expires_in;
} zbx_oauth_data_t;

static int	oauth_fetch_from_db(zbx_uint64_t mediatypeid, const char *mediatype_name, zbx_oauth_data_t *data,
		char **error)
{
#define SET_ERROR(message) 										\
	do 												\
	{												\
		*error = zbx_dsprintf(NULL, "Access token fetch failed: mediatype \"%s\": "		\
			message, mediatype_name);							\
	}												\
	while(0)
#define CHECK_FOR_NULL(index, message)									\
	do												\
	{												\
		if (SUCCEED == zbx_db_is_null(row[index]) || 0 == strlen(row[index]))			\
		{											\
			SET_ERROR(message);								\
			goto out;									\
		}											\
	}												\
	while(0)

	int		ret = FAIL;
	zbx_db_result_t	result;
	zbx_db_row_t	row;

	zabbix_log(LOG_LEVEL_DEBUG, "In %s()", __func__);

	result = zbx_db_select("select token_url,client_id,client_secret,refresh_token,access_token,"
			"access_token_updated,access_expires_in,tokens_status"
			" from media_type_oauth"
			" where mediatypeid="ZBX_FS_UI64, mediatypeid);

	if ((zbx_db_result_t)ZBX_DB_DOWN == result)
	{
		*error = zbx_dsprintf(NULL, "cannot fetch access token: database not available");
		goto out1;
	}

	if (NULL == (row = zbx_db_fetch(result)))
	{
		*error = zbx_dsprintf(NULL, "Access token fetch failed: mediatype \"%s\" requires"
				" OAuth2 to be configured in frontend", mediatype_name);
		goto out;
	}

	CHECK_FOR_NULL(0, "token URL is missing");
	CHECK_FOR_NULL(1, "client ID is missing");
	CHECK_FOR_NULL(2, "client secret is missing");
	//CHECK_FOR_NULL(3, "refresh token is missing");
	CHECK_FOR_NULL(4, "access token is missing");

	if (0 == atoi(row[5]))
	{
		SET_ERROR("access token update time is zero");
		goto out;
	}

	if (0 == atoi(row[6]))
	{
		SET_ERROR("access token expire time is zero");
		goto out;
	}

	data->token_url = zbx_strdup(NULL, row[0]);
	data->client_id = zbx_strdup(NULL, row[1]);
	data->client_secret = zbx_strdup(NULL, row[2]);
	data->refresh_token = zbx_strdup(NULL, row[3]);
	data->access_token = zbx_strdup(NULL, row[4]);
	data->access_token_updated = (time_t)atoi(row[5]);
	data->access_expires_in = (time_t)atoi(row[6]);
	data->tokens_status = (unsigned char)atoi(row[7]);

	/* for audit it is necessary to keep the original value from DB to compare with, not the intermediate values
	which may appear later in the sequence of unsuccessful attempts to update tokens */
	data->old_tokens_status = data->tokens_status;

	ret = SUCCEED;
out:
	zbx_db_free_result(result);
out1:
	zabbix_log(LOG_LEVEL_DEBUG, "End of %s(): error:%s", __func__, ZBX_NULL2STR(*error));

	return ret;
#undef CHECK_FOR_NULL
#undef SET_ERROR
}

static int	oauth_access_refresh(zbx_oauth_data_t *data, const char *context_name, long timeout,
		const char *config_source_ip, const char *config_ssl_ca_location, char **error)
{
#ifndef HAVE_LIBCURL
	ZBX_UNUSED(data);
	ZBX_UNUSED(context_name);
	ZBX_UNUSED(timeout);
	ZBX_UNUSED(config_source_ip);
	ZBX_UNUSED(config_ssl_ca_location);
	*error = zbx_dsprintf(*error, "OAuth requires curl library. This Zabbix server binary was compiled without curl"
		" library support.");
	return FAIL;
#else

#define ZBX_HTTP_STATUS_CODE_OK  200

#define SET_ERROR(format, ...)											\
	do													\
	{													\
		*error = zbx_dsprintf(NULL, "Access token retrieval failed: %s: " format,			\
				context_name, __VA_ARGS__);							\
	}													\
	while (0)

	int			ret = FAIL;
	char			*out = NULL, *tmp = NULL;
	size_t			tmp_alloc = 0;
	long			response_code;
	struct zbx_json_parse	jp;
	time_t			sec = time(NULL);
	const char		*header = "Content-Type: application/x-www-form-urlencoded";

	zabbix_log(LOG_LEVEL_DEBUG, "In %s()", __func__);

	char	*posts = zbx_strdcatf(NULL, "grant_type=refresh_token&client_id=%s&client_secret=%s&refresh_token=%s",
			data->client_id, data->client_secret, data->refresh_token);

	zabbix_log(LOG_LEVEL_DEBUG, "%s(): posts:[%s]", __func__, posts);

	if (SUCCEED != zbx_http_req(data->token_url, header, timeout, NULL, NULL, config_source_ip,
			config_ssl_ca_location, NULL, NULL, &out, posts, &response_code, error))
	{
		goto out;
	}

	tmp = zbx_str_printable_dyn(ZBX_NULL2STR(out));
	zabbix_log(LOG_LEVEL_DEBUG, "%s(): out:[%s]", __func__, tmp);
	zbx_free(tmp);

	if (SUCCEED != zbx_json_open(out, &jp))
	{
		SET_ERROR("%s", zbx_json_strerror());
		goto out;
	}

	if (ZBX_HTTP_STATUS_CODE_OK != response_code)
	{
		int	expected_ret = FAIL;

		if (SUCCEED != zbx_json_value_by_name_dyn(&jp, "error", &tmp, &tmp_alloc, NULL))
		{
			SET_ERROR("%s", "error field not found in OAuth server response");
			goto out;
		}

		if (0 != strcmp("invalid_grant", tmp))
			expected_ret = NETWORK_ERROR;	/* you may try again */

		if (SUCCEED != zbx_json_value_by_name_dyn(&jp, "error_description", &tmp, &tmp_alloc, NULL))
		{
			SET_ERROR("%s", "error_description field not found in OAuth server response");
			goto out;
		}

		SET_ERROR("%s", tmp);

		if (NETWORK_ERROR == expected_ret)
		{
			/* remove access token valid bit */
			data->tokens_status = (data->tokens_status & ~ZBX_OAUTH_TOKEN_ACCESS_VALID) &
					ZBX_OAUTH_TOKEN_VALID;
		}
		else /* invalid_grant */
		{
			/* user should renew everything */
			data->tokens_status = 0;
		}

		ret = expected_ret;
	}
	else
	{
		if (SUCCEED != zbx_json_value_by_name_dyn(&jp, "token_type", &tmp, &tmp_alloc, NULL))
		{
			SET_ERROR("%s", "token_type field not found in OAuth server response");
			goto out;
		}

		//if (0 != strcmp(tmp, "Bearer"))
		//{
		//	SET_ERROR("%s", "token_type is not \"Bearer\" in OAuth server response");
		//	goto out;
		//}

		if (SUCCEED != zbx_json_value_by_name_dyn(&jp, "access_token", &tmp, &tmp_alloc, NULL))
		{
			SET_ERROR("%s", "access_token field not found in OAuth server response");
			goto out;
		}

		data->old_access_token = data->access_token;
		data->access_token = zbx_strdup(NULL, tmp);

		if (SUCCEED != zbx_json_value_by_name_dyn(&jp, "expires_in", &tmp, &tmp_alloc, NULL))
		{
			SET_ERROR("%s", "expires_in field not found in OAuth server response");
			goto out;
		}

		data->old_access_expires_in = data->access_expires_in;
		data->access_expires_in = atoi(tmp);

		/* request may return the new refresh token */
		if (SUCCEED == zbx_json_value_by_name_dyn(&jp, "refresh_token", &tmp, &tmp_alloc, NULL))
		{
			data->old_refresh_token = data->refresh_token;
			data->refresh_token = zbx_strdup(NULL, tmp);
		}

		data->old_access_token_updated = data->access_token_updated;
		data->access_token_updated = sec;
		ret = SUCCEED;
	}
out:
	zbx_free(posts);
	zbx_free(tmp);
	zbx_free(out);

	if (NULL != *error)
		zabbix_log(LOG_LEVEL_ERR, "%s", *error);

	zabbix_log(LOG_LEVEL_DEBUG, "End of %s()", __func__);

	return ret;
#undef SET_ERROR
#endif
}

static void	oauth_db_update(zbx_uint64_t mediatypeid, zbx_oauth_data_t *data, int fetch_result)
{
	if (SUCCEED != fetch_result)
	{
		data->tokens_status &= ZBX_OAUTH_TOKEN_REFRESH_VALID;

		zbx_db_execute("update media_type_oauth set tokens_status=%hhu"
				" where mediatypeid="ZBX_FS_UI64, data->tokens_status, mediatypeid);
	}
	else
	{
		data->tokens_status |= (ZBX_OAUTH_TOKEN_ACCESS_VALID | ZBX_OAUTH_TOKEN_REFRESH_VALID);

		if (NULL != data->old_refresh_token)	 /* data->refresh_token has changed */
		{
			zbx_db_execute("update media_type_oauth set"
					" access_token='%s',access_token_updated=" ZBX_FS_TIME_T ","
					"access_expires_in=%d,refresh_token='%s',tokens_status=%hhu"
					" where mediatypeid="ZBX_FS_UI64,
					data->access_token, data->access_token_updated, data->access_expires_in,
					data->refresh_token, data->tokens_status,
					mediatypeid);
		}
		else
		{
			zbx_db_execute("update media_type_oauth set"
					" access_token='%s',access_token_updated=" ZBX_FS_TIME_T ","
					"access_expires_in=%d,tokens_status=%hhu"
					" where mediatypeid="ZBX_FS_UI64,
					data->access_token, data->access_token_updated, data->access_expires_in,
					data->tokens_status,
					mediatypeid);
		}
	}
}

static void	oauth_audit(int audit_context_mode, zbx_uint64_t mediatypeid, const char *mediatype_name,
		const zbx_oauth_data_t *data, int fetch_result)
{
	RETURN_IF_AUDIT_OFF(audit_context_mode);

	if (SUCCEED == fetch_result || data->old_tokens_status != data->tokens_status)
	{
		zbx_audit_entry_t	*entry = zbx_audit_entry_init(mediatypeid, AUDIT_MEDIATYPE_ID, mediatype_name,
				ZBX_AUDIT_ACTION_UPDATE, ZBX_AUDIT_RESOURCE_MEDIATYPE);

		if (data->old_tokens_status != data->tokens_status)
		{
			zbx_audit_entry_update_int(entry, "tokens_status", data->old_tokens_status,
					data->tokens_status);
		}

		if (SUCCEED == fetch_result)
		{
			zbx_audit_entry_update_string(entry, "access_token", ZBX_SECRET_MASK,
					ZBX_SECRET_MASK);
			zbx_audit_entry_update_int(entry, "access_expires_in", data->old_access_expires_in,
					data->access_expires_in);
			zbx_audit_entry_update_int(entry, "access_token_updated", (int)data->old_access_token_updated,
					(int)data->access_token_updated);

			if (NULL != data->old_refresh_token)
			{
				zbx_audit_entry_update_string(entry, "refresh_token", ZBX_SECRET_MASK,
						ZBX_SECRET_MASK);
			}
		}

		zbx_hashset_insert(zbx_get_audit_hashset(), &entry, sizeof(entry));
	}
}

static void	oauth_clean(zbx_oauth_data_t *data)
{
	zbx_free(data->token_url);
	zbx_free(data->client_id);
	zbx_free(data->client_secret);
	zbx_free(data->old_refresh_token);
	zbx_free(data->refresh_token);
	zbx_free(data->old_access_token);
	zbx_free(data->access_token);
}

/*****************************************************************************************
 *                                                                                       *
 * Purpose: get OAuth authorization OAuthBearer used as password                         *
 *                                                                                       *
 * Parameters: mediatypeid            - [IN]                                             *
 *             mediatype_name         - [IN]                                             *
 *             timeout                - [IN] refresh request timeout                     *
 *             maxattempts            - [IN] max attempts on refresh request             *
 *             expire_offset          - [IN] offset before renew access token for OAuth2 *
 *             config_source_ip       - [IN]                                             *
 *             config_ssl_ca_location - [IN]                                             *
 *             oauthbearer            - [OUT]                                            *
 *             expires                - [OUT]                                            *
 *             error                  - [IN/OUT]                                         *
 *                                                                                       *
 * Return value: SUCCEED - function got valid access token successfully                  *
 *               FAIL    - otherwise                                                     *
 *                                                                                       *
 *****************************************************************************************/
int	zbx_oauth_get(zbx_uint64_t mediatypeid, const char *mediatype_name, int timeout, int maxattempts,
		int expire_offset, const char *config_source_ip, const char *config_ssl_ca_location,
		char **oauthbearer, int *expires, char **error)
{
	int			ret;
	zbx_oauth_data_t	data = {0};

	zabbix_log(LOG_LEVEL_DEBUG, "In %s()", __func__);

	if (SUCCEED != (ret = oauth_fetch_from_db(mediatypeid, mediatype_name, &data, error)))
		goto out;

	if (data.access_token_updated + data.access_expires_in - expire_offset < time(NULL))
	{
		char	*suberror = NULL;

		do
		{
			zbx_free(suberror);	/* clear last error */

			ret = oauth_access_refresh(&data, mediatype_name, timeout, config_source_ip,
					config_ssl_ca_location, &suberror);
		}
		while (0 < --maxattempts && NETWORK_ERROR == ret);

		oauth_db_update(mediatypeid, &data, ret);
		oauth_audit(ZBX_AUDIT_ALL_CONTEXT, mediatypeid, mediatype_name, &data, ret);

		if (SUCCEED != ret)
		{
			*error = suberror;
			goto out;
		}
	}

	*oauthbearer = zbx_strdup(*oauthbearer, data.access_token);
	*expires = (int)data.access_token_updated + data.access_expires_in;
out:
	oauth_clean(&data);

	zabbix_log(LOG_LEVEL_DEBUG, "End of %s() ret:%s expires:%d", __func__, zbx_result_string(ret),
			(NULL != expires ? *expires : 0));

	return ret;
}

static void	oauth_profile_cache_init(void)
{
	if (0 != oauth_profile_cache_initialized)
		return;

	zbx_hashset_create(&oauth_profile_cache, 1, ZBX_DEFAULT_UINT64_HASH_FUNC,
			ZBX_DEFAULT_UINT64_COMPARE_FUNC);
	oauth_profile_cache_initialized = 1;
}

static void	oauth_profile_cache_free_entry(zbx_oauth_profile_cache_t *entry)
{
	zbx_free(entry->bearer);
}

static int	oauth_profile_cache_get(zbx_uint64_t oauthprofileid, int expire_offset, char **oauthbearer, int *expires)
{
	zbx_oauth_profile_cache_t	*entry;

	if (0 == oauthprofileid)
		return FAIL;

	oauth_profile_cache_init();

	if (NULL == (entry = (zbx_oauth_profile_cache_t *)zbx_hashset_search(&oauth_profile_cache, &oauthprofileid)))
		return FAIL;

	if (entry->expires - expire_offset <= (int)time(NULL))
		return FAIL;

	*oauthbearer = zbx_strdup(*oauthbearer, entry->bearer);
	*expires = entry->expires;

	return SUCCEED;
}

static void	oauth_profile_cache_set(zbx_uint64_t oauthprofileid, const char *bearer, int expires)
{
	zbx_oauth_profile_cache_t	*entry, entry_local = {.oauthprofileid = oauthprofileid};

	if (0 == oauthprofileid || NULL == bearer || '\0' == *bearer)
		return;

	oauth_profile_cache_init();

	if (NULL == (entry = (zbx_oauth_profile_cache_t *)zbx_hashset_search(&oauth_profile_cache, &oauthprofileid)))
	{
		entry = (zbx_oauth_profile_cache_t *)zbx_hashset_insert(&oauth_profile_cache, &entry_local,
				sizeof(entry_local));
	}
	else
		zbx_free(entry->bearer);

	entry->bearer = zbx_strdup(NULL, bearer);
	entry->expires = expires;
}

void	zbx_oauth_profile_invalidate(zbx_uint64_t oauthprofileid)
{
	zbx_oauth_profile_cache_t	*entry;

	if (0 == oauthprofileid || 0 == oauth_profile_cache_initialized)
		return;

	if (NULL != (entry = (zbx_oauth_profile_cache_t *)zbx_hashset_search(&oauth_profile_cache, &oauthprofileid)))
	{
		oauth_profile_cache_free_entry(entry);
		zbx_hashset_remove_direct(&oauth_profile_cache, entry);
	}
}

void	zbx_oauth_profile_invalidate_all(void)
{
	zbx_hashset_iter_t	iter;
	zbx_oauth_profile_cache_t	*entry;

	if (0 == oauth_profile_cache_initialized)
		return;

	zbx_hashset_iter_reset(&oauth_profile_cache, &iter);

	while (NULL != (entry = (zbx_oauth_profile_cache_t *)zbx_hashset_iter_next(&iter)))
		oauth_profile_cache_free_entry(entry);

	zbx_hashset_clear(&oauth_profile_cache);
}

static int	oauth_db_ensure_connected(char **error)
{
	if (SUCCEED == zbx_db_is_connected())
		return SUCCEED;

	if (ZBX_DB_OK != zbx_db_connect(ZBX_DB_CONNECT_ONCE))
	{
		*error = zbx_strdup(NULL, "cannot fetch access token: database not available");
		return FAIL;
	}

	return SUCCEED;
}

static int	oauth_profile_fetch_from_db(zbx_uint64_t oauthprofileid, const char *context_name, zbx_oauth_data_t *data,
		char **error)
{
	const char	*profile_name = context_name;
#define SET_ERROR(message) 										\
	do 												\
	{												\
		*error = zbx_dsprintf(NULL, "Access token fetch failed: %s \"%s\": "			\
			message, "OAuth profile", profile_name);					\
	}												\
	while(0)
#define CHECK_FOR_NULL(index, message)									\
	do												\
	{												\
		if (SUCCEED == zbx_db_is_null(row[index]) || 0 == strlen(row[index]))			\
		{											\
			SET_ERROR(message);								\
			goto out;									\
		}											\
	}												\
	while(0)

	int		ret = FAIL;
	zbx_db_result_t	result;
	zbx_db_row_t	row;

	zabbix_log(LOG_LEVEL_DEBUG, "In %s()", __func__);

	if (SUCCEED != oauth_db_ensure_connected(error))
		return FAIL;

	result = zbx_db_select("select profile_name,token_url,client_id,client_secret,refresh_token,access_token,"
			"access_token_updated,access_expires_in,tokens_status"
			" from oauth_profile"
			" where oauthprofileid="ZBX_FS_UI64, oauthprofileid);

	if ((zbx_db_result_t)ZBX_DB_DOWN == result)
	{
		*error = zbx_dsprintf(NULL, "cannot fetch access token: database not available");
		goto out1;
	}

	if (NULL == (row = zbx_db_fetch(result)))
	{
		*error = zbx_dsprintf(NULL, "Access token fetch failed: OAuth profile (ID: " ZBX_FS_UI64 ") not found."
				" Select a valid profile or configure OAuth2 in Administration > OAuth profiles",
				oauthprofileid);
		goto out;
	}

	if ('\0' != *row[0])
		profile_name = row[0];

	CHECK_FOR_NULL(1, "token URL is missing");
	CHECK_FOR_NULL(2, "client ID is missing");
	CHECK_FOR_NULL(3, "client secret is missing");
	//CHECK_FOR_NULL(4, "refresh token is missing");
	CHECK_FOR_NULL(5, "access token is missing");

	if (0 == atoi(row[6]))
	{
		SET_ERROR("access token update time is zero");
		goto out;
	}

	if (0 == atoi(row[7]))
	{
		SET_ERROR("access token expire time is zero");
		goto out;
	}

	data->token_url = zbx_strdup(NULL, row[1]);
	data->client_id = zbx_strdup(NULL, row[2]);
	data->client_secret = zbx_strdup(NULL, row[3]);
	data->refresh_token = zbx_strdup(NULL, row[4]);
	data->access_token = zbx_strdup(NULL, row[5]);
	data->access_token_updated = (time_t)atoi(row[6]);
	data->access_expires_in = (time_t)atoi(row[7]);
	data->tokens_status = (unsigned char)atoi(row[8]);
	data->old_tokens_status = data->tokens_status;

	ret = SUCCEED;
out:
	zbx_db_free_result(result);
out1:
	zabbix_log(LOG_LEVEL_DEBUG, "End of %s(): error:%s", __func__, ZBX_NULL2STR(*error));

	return ret;
#undef CHECK_FOR_NULL
#undef SET_ERROR
}

static void	oauth_profile_db_update(zbx_uint64_t oauthprofileid, zbx_oauth_data_t *data, int fetch_result)
{
	if (SUCCEED != fetch_result)
	{
		data->tokens_status &= ZBX_OAUTH_TOKEN_REFRESH_VALID;

		zbx_db_execute("update oauth_profile set tokens_status=%hhu"
				" where oauthprofileid="ZBX_FS_UI64, data->tokens_status, oauthprofileid);
	}
	else
	{
		data->tokens_status |= (ZBX_OAUTH_TOKEN_ACCESS_VALID | ZBX_OAUTH_TOKEN_REFRESH_VALID);

		if (NULL != data->old_refresh_token)
		{
			zbx_db_execute("update oauth_profile set"
					" access_token='%s',access_token_updated=" ZBX_FS_TIME_T ","
					"access_expires_in=%d,refresh_token='%s',tokens_status=%hhu"
					" where oauthprofileid="ZBX_FS_UI64,
					data->access_token, data->access_token_updated, data->access_expires_in,
					data->refresh_token, data->tokens_status,
					oauthprofileid);
		}
		else
		{
			zbx_db_execute("update oauth_profile set"
					" access_token='%s',access_token_updated=" ZBX_FS_TIME_T ","
					"access_expires_in=%d,tokens_status=%hhu"
					" where oauthprofileid="ZBX_FS_UI64,
					data->access_token, data->access_token_updated, data->access_expires_in,
					data->tokens_status,
					oauthprofileid);
		}
	}
}

/*****************************************************************************************
 *                                                                                       *
 * Purpose: get OAuth authorization bearer token from OAuth profile                      *
 *                                                                                       *
 * Parameters: oauthprofileid         - [IN]                                             *
 *             context_name           - [IN] profile or media type name for errors       *
 *             timeout                - [IN] refresh request timeout                     *
 *             maxattempts            - [IN] max attempts on refresh request             *
 *             expire_offset          - [IN] offset before renew access token for OAuth2 *
 *             config_source_ip       - [IN]                                             *
 *             config_ssl_ca_location - [IN]                                             *
 *             oauthbearer            - [OUT]                                            *
 *             expires                - [OUT]                                            *
 *             error                  - [IN/OUT]                                         *
 *                                                                                       *
 * Return value: SUCCEED - function got valid access token successfully                  *
 *               FAIL    - otherwise                                                     *
 *                                                                                       *
 *****************************************************************************************/
int	zbx_oauth_profile_get(zbx_uint64_t oauthprofileid, const char *context_name, int timeout, int maxattempts,
		int expire_offset, const char *config_source_ip, const char *config_ssl_ca_location,
		unsigned char force_refresh, char **oauthbearer, int *expires, char **error)
{
	int			ret;
	zbx_oauth_data_t	data = {0};

	zabbix_log(LOG_LEVEL_DEBUG, "In %s()", __func__);

	if (0 == oauthprofileid)
	{
		*error = zbx_strdup(NULL, "OAuth profile is not configured.");
		return FAIL;
	}

	if (0 == force_refresh && SUCCEED == oauth_profile_cache_get(oauthprofileid, expire_offset, oauthbearer,
			expires))
	{
		return SUCCEED;
	}

	if (0 != force_refresh)
		zbx_oauth_profile_invalidate(oauthprofileid);

	if (SUCCEED != (ret = oauth_profile_fetch_from_db(oauthprofileid, context_name, &data, error)))
		goto out;

	if (data.access_token_updated + data.access_expires_in - expire_offset < time(NULL))
	{
		char	*suberror = NULL;

		do
		{
			zbx_free(suberror);

			ret = oauth_access_refresh(&data, context_name, timeout, config_source_ip,
					config_ssl_ca_location, &suberror);
		}
		while (0 < --maxattempts && NETWORK_ERROR == ret);

		oauth_profile_db_update(oauthprofileid, &data, ret);

		if (SUCCEED != ret)
		{
			zbx_oauth_profile_invalidate(oauthprofileid);
			*error = suberror;
			goto out;
		}
	}

	*oauthbearer = zbx_strdup(*oauthbearer, data.access_token);
	*expires = (int)data.access_token_updated + data.access_expires_in;
	oauth_profile_cache_set(oauthprofileid, *oauthbearer, *expires);
out:
	oauth_clean(&data);

	zabbix_log(LOG_LEVEL_DEBUG, "End of %s() ret:%s expires:%d", __func__, zbx_result_string(ret),
			(NULL != expires ? *expires : 0));

	return ret;
}
