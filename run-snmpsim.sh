#!/bin/bash

set -e

echo "[1/3] Stopping old SNMPSim processes..."
pkill -f snmpsim-command-responder || true

sleep 2

echo "[2/3] Starting devices..."

# Each device listens on TWO endpoints in a single process (snmpsim supports multiple
# --agent-udpv4-endpoint flags per engine):
#   - its original 127.0.0.x:1611 loopback alias — what Zabbix's hosts are configured with.
#     Loopback addresses never cross a network-namespace boundary, so this one is host-only.
#   - a 172.18.0.10x IP alias on the LibreNMS stack's docker bridge (br-b081b939765c), added
#     with `sudo ip addr add 172.18.0.10x/32 dev br-b081b939765c` — see prior session notes if
#     these aliases are gone (they don't survive a reboot or bridge recreation). Reachable from
#     inside the librenms containers, unlike the loopback aliases.
# Do NOT bind 0.0.0.0 here: that makes whichever process owns a given PORT answer for every IP
# at that port, including the other three devices' loopback aliases — a real regression hit
# once already (Router1 on 0.0.0.0:1611 started answering for 127.0.0.3/4/5:1611 too, silently
# feeding Zabbix's Switch1/Switch2/UPS1 hosts Router1's data instead of their own).

nohup snmpsim-command-responder \
    --data-dir=./snmpdata/router1 \
    --agent-udpv4-endpoint=127.0.0.2:1611 \
    --agent-udpv4-endpoint=172.18.0.101:1611 \
    >/tmp/router1.log 2>&1 &

nohup snmpsim-command-responder \
    --data-dir=./snmpdata/switch1 \
    --agent-udpv4-endpoint=127.0.0.3:1611 \
    --agent-udpv4-endpoint=172.18.0.102:1612 \
    >/tmp/switch1.log 2>&1 &

nohup snmpsim-command-responder \
    --data-dir=./snmpdata/switch2 \
    --agent-udpv4-endpoint=127.0.0.4:1611 \
    --agent-udpv4-endpoint=172.18.0.103:1613 \
    >/tmp/switch2.log 2>&1 &

nohup snmpsim-command-responder \
    --data-dir=./snmpdata/ups1 \
    --agent-udpv4-endpoint=127.0.0.5:1611 \
    --agent-udpv4-endpoint=172.18.0.104:1614 \
    >/tmp/ups1.log 2>&1 &

sleep 3

echo "[3/3] Listening sockets:"
ss -lunp | grep -E ':161[1-4]\b'

echo
echo "=== Test commands (Zabbix-side loopback endpoints) ==="
echo "snmpget -On -v2c -c zbxlab 127.0.0.2:1611 .1.3.6.1.2.1.1.5.0   # router1"
echo "snmpget -On -v2c -c zbxlab 127.0.0.3:1611 .1.3.6.1.2.1.1.5.0   # switch1"
echo "snmpget -On -v2c -c zbxlab 127.0.0.4:1611 .1.3.6.1.2.1.1.5.0   # switch2"
echo "snmpget -On -v2c -c zbxlab 127.0.0.5:1611 .1.3.6.1.2.1.1.5.0   # ups1"

echo
echo "=== Test commands (LibreNMS-side bridge endpoints) ==="
echo "snmpget -On -v2c -c zbxlab 172.18.0.101:1611 .1.3.6.1.2.1.1.5.0   # router1"
echo "snmpget -On -v2c -c zbxlab 172.18.0.102:1612 .1.3.6.1.2.1.1.5.0   # switch1"
echo "snmpget -On -v2c -c zbxlab 172.18.0.103:1613 .1.3.6.1.2.1.1.5.0   # switch2"
echo "snmpget -On -v2c -c zbxlab 172.18.0.104:1614 .1.3.6.1.2.1.1.5.0   # ups1"

echo
echo "=== LLDP test ==="
echo "snmpwalk -On -v2c -c zbxlab 127.0.0.3:1611 .1.0.8802.1.1.2.1.4.1.1.9"

echo
echo "NOTE: if the 172.18.0.10x aliases are missing (host reboot / bridge recreated), re-add:"
echo "  sudo ip addr add 172.18.0.101/32 dev br-b081b939765c"
echo "  sudo ip addr add 172.18.0.102/32 dev br-b081b939765c"
echo "  sudo ip addr add 172.18.0.103/32 dev br-b081b939765c"
echo "  sudo ip addr add 172.18.0.104/32 dev br-b081b939765c"
