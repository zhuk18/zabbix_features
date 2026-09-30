#!/bin/sh
# Builds the standalone driver (driver.c) of the topology preprocessing step against the built tree.
# Usage: build.sh [output]   (default: ./topo_step). Run "make" in src/libs/zbxpreproc and src/zabbix_server first.
set -eu
HERE=$(cd "$(dirname "$0")" && pwd)
TOP=$(cd "$HERE/../../../.." && pwd)
OUT=${1:-$HERE/topo_step}
SERVER=$TOP/src/zabbix_server

# The link line of zabbix_server: every static library it uses, plus the system libraries.
LINE=$(cd "$SERVER" && make -n -W server.c zabbix_server 2>/dev/null | grep -E '^gcc.* -o zabbix_server' | tail -1)
[ -n "$LINE" ] || { echo "cannot find the zabbix_server link line: build the server first" >&2; exit 1; }
LIBS=$(echo "$LINE" | tr ' ' '\n' | grep -E '\.a$' | sed "s#^#$SERVER/#")
SYS=$(echo "$LINE" | tr ' ' '\n' | grep -E '^-l' | sort -u | tr '\n' ' ')

gcc -DHAVE_CONFIG_H -I"$TOP/include/common" -I"$TOP/include" -I"$TOP/src" -o "$OUT" "$HERE/driver.c" \
	-Wl,--start-group $LIBS "$TOP/src/libs/zbxpreproc/libzbxpreproc.a" -Wl,--end-group $SYS
echo "built $OUT"
