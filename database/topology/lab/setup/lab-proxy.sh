#!/bin/bash
# lab-proxy.sh — one Zabbix proxy on this VM for the lab (lab spec §4.1 `proxy`; task 128 part 3). Not a service: no cron, no systemd; start it by hand, stop it by hand.
#   lab-proxy.sh create   make the database `zabbix_proxy` (schema of this tree) and the proxy `lab-proxy` in the server (API, needs ZBX_TOKEN)
#   lab-proxy.sh start    start the proxy (active, name lab-proxy, log /tmp/zabbix_proxy.log); waits until the server sees it
#   lab-proxy.sh stop     stop it (SIGTERM, waits)
#   lab-proxy.sh status   running or not
#   lab-proxy.sh remove   stop, delete the proxy from the server and drop the database
# Uses the built binary src/zabbix_proxy/zabbix_proxy of this tree (sbin/ can hold an older install) and the same MySQL login as the lab (`mysql -uzabbix`, no password, the socket).
repo=$(cd "$(dirname "$0")/../../../.." && pwd)
bin=$repo/src/zabbix_proxy/zabbix_proxy
conf=${LAB_PROXY_CONF:-/tmp/lab_zabbix_proxy.conf}
pidfile=/tmp/lab_zabbix_proxy.pid
name=lab-proxy
api() { curl -s -H 'Content-Type: application/json-rpc' -H "Authorization: Bearer $ZBX_TOKEN" -d "{\"jsonrpc\":\"2.0\",\"method\":\"$1\",\"params\":$2,\"id\":1}" "${ZBX_URL:-http://localhost/zabbix/ui/api_jsonrpc.php}"; }
running() { [ -f "$pidfile" ] && kill -0 "$(cat "$pidfile")" 2>/dev/null; }
case "$1" in
create)
	mysql -uzabbix -e "CREATE DATABASE IF NOT EXISTS zabbix_proxy CHARACTER SET utf8mb4 COLLATE utf8mb4_bin"
	[ "$(mysql -uzabbix -N -e "select count(*) from information_schema.tables where table_schema='zabbix_proxy'")" -gt 0 ] || mysql -uzabbix zabbix_proxy < "$repo/database/mysql/schema.sql"
	[ -n "$ZBX_TOKEN" ] || { echo "ZBX_TOKEN is needed"; exit 2; }
	api proxy.get "{\"filter\":{\"name\":[\"$name\"]},\"output\":[\"proxyid\"]}" | grep -q proxyid || api proxy.create "{\"name\":\"$name\",\"operating_mode\":0}" ;;
start)
	running && { echo "running"; exit 0; }
	cat > "$conf" <<EOF
Server=127.0.0.1
Hostname=$name
LogFile=/tmp/zabbix_proxy.log
PidFile=$pidfile
DBName=zabbix_proxy
DBUser=zabbix
ListenPort=10061
Timeout=4
ProxyBufferMode=hybrid
ProxyMemoryBufferSize=16M
ProxyConfigFrequency=10
DataSenderFrequency=1
StatsAllowedIP=127.0.0.1
SocketDir=/tmp/lab_proxy_sock
EOF
	mkdir -p /tmp/lab_proxy_sock
	"$bin" -c "$conf" || { tail -5 /tmp/zabbix_proxy.log; exit 1; }
	for i in $(seq 1 60); do running && break; sleep 1; done
	echo "started pid $(cat "$pidfile")" ;;
stop)
	running || { echo "not running"; exit 0; }
	kill "$(cat "$pidfile")"
	for i in $(seq 1 60); do running || break; sleep 1; done
	running && { echo "still running"; exit 1; }
	echo "stopped" ;;
status)
	running && echo "running pid $(cat "$pidfile")" || echo "not running" ;;
remove)
	"$0" stop
	[ -n "$ZBX_TOKEN" ] || { echo "ZBX_TOKEN is needed"; exit 2; }
	ids=$(api proxy.get "{\"filter\":{\"name\":[\"$name\"]},\"output\":[\"proxyid\"]}" | grep -o '"proxyid":"[0-9]*"' | grep -o '[0-9]*')
	[ -z "$ids" ] || api proxy.delete "[\"$ids\"]"
	mysql -uzabbix -e "DROP DATABASE IF EXISTS zabbix_proxy"
	rm -f "$conf"; rm -rf /tmp/lab_proxy_sock ;;
*) sed -n 2,9p "$0"; exit 2 ;;
esac
