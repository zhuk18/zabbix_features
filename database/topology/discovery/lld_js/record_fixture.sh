#!/bin/sh
# Records what the template's master walk[] would return from a lab device, one variable per line, in the same
# "OID = TYPE: value" form the Zabbix walk[] item produces. Usage: record_fixture.sh <host:port> <out-file> [community]
set -u
TARGET=$1
OUT=$2
COMM=${3:-zbxlab}
DIR=$(dirname "$0")
: > "$OUT"
while read -r oid; do
	snmpbulkwalk -v2c -c "$COMM" -On -Cr50 -t 5 -r 1 "$TARGET" "$oid" | grep -v ' = No Such \(Instance\|Object\)' >> "$OUT"
done < "$DIR/walk_oids.txt"
echo "$(wc -l < "$OUT") variables -> $OUT"
