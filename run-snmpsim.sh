#!/bin/bash

set -e

echo "[1/3] Stopping old SNMPSim processes..."
pkill -f snmpsim-command-responder || true

sleep 2

echo "[2/3] Starting devices..."

nohup snmpsim-command-responder \
    --data-dir=./snmpdata/router1 \
    --agent-udpv4-endpoint=127.0.0.2:1611 \
    >/tmp/router1.log 2>&1 &

nohup snmpsim-command-responder \
    --data-dir=./snmpdata/switch1 \
    --agent-udpv4-endpoint=127.0.0.3:1611 \
    >/tmp/switch1.log 2>&1 &

nohup snmpsim-command-responder \
    --data-dir=./snmpdata/switch2 \
    --agent-udpv4-endpoint=127.0.0.4:1611 \
    >/tmp/switch2.log 2>&1 &

nohup snmpsim-command-responder \
    --data-dir=./snmpdata/ups1 \
    --agent-udpv4-endpoint=127.0.0.5:1611 \
    >/tmp/ups1.log 2>&1 &

sleep 3

echo "[3/3] Listening sockets:"
ss -lunp | grep 1611

echo
echo "=== Test commands ==="
echo "snmpget -On -v2c -c zbxlab 127.0.0.2:1611 .1.3.6.1.2.1.1.5.0"
echo "snmpget -On -v2c -c zbxlab 127.0.0.3:1611 .1.3.6.1.2.1.1.5.0"
echo "snmpget -On -v2c -c zbxlab 127.0.0.4:1611 .1.3.6.1.2.1.1.5.0"
echo "snmpget -On -v2c -c zbxlab 127.0.0.5:1611 .1.3.6.1.2.1.1.5.0"

echo
echo "=== LLDP test ==="
echo "snmpwalk -On -v2c -c zbxlab 127.0.0.3:1611 .1.0.8802.1.1.2.1.4.1.1.9"
