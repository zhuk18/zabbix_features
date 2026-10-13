/*
 * Standalone driver of the "SNMP walk to topology rows" step, for the golden tests (compare.php). It runs
 * item_preproc_snmp_walk_to_topology() on a walk file and prints the result, or "ERROR: <message>" with exit code 1.
 *
 *     topo_step <walk-file> <source> [missing_mib] [mac_limit]
 *
 * Built by build.sh against the libraries of the built tree.
 */

#include "zbxcommon.h"
#include "zbxvariant.h"
#include "zbxstr.h"
#include "zbxlog.h"
#include "libs/zbxpreproc/preproc_topology.h"

const char	*progname = "topo_step";
const char	title_message[] = "topo_step";
const char	*usage_message[] = {NULL};
const char	*help_message[] = {NULL};
unsigned char	program_type = 0;
int		CONFIG_LOG_LEVEL = 0;

int	main(int argc, char **argv)
{
	FILE		*f;
	char		*buf, *params, *err = NULL;
	long		size;
	zbx_variant_t	v;
	int		ret;

	if (argc < 3 || NULL == (f = fopen(argv[1], "rb")))
	{
		fprintf(stderr, "usage: %s <walk-file> <source> [missing_mib] [mac_limit]\n", argv[0]);
		return 2;
	}

	fseek(f, 0, SEEK_END);
	size = ftell(f);
	fseek(f, 0, SEEK_SET);
	buf = (char *)malloc((size_t)size + 1);

	if ((size_t)size != fread(buf, 1, (size_t)size, f))
		return 2;

	buf[size] = '\0';
	fclose(f);

	params = zbx_dsprintf(NULL, "%s\n%s\n%s", argv[2], 3 < argc ? argv[3] : "error", 4 < argc ? argv[4] : "");
	zbx_variant_set_str(&v, buf);

	ret = item_preproc_snmp_walk_to_topology(&v, params, &err);

	if (SUCCEED == ret)
		printf("%s\n", v.data.str);
	else
		printf("ERROR: %s\n", err);

	zbx_variant_clear(&v);
	zbx_free(params);
	zbx_free(err);

	return SUCCEED == ret ? 0 : 1;
}
