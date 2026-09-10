#!/bin/bash
#
# start-lab.sh — manually bring up the SNMP test lab: LibreNMS's docker-compose stack, the
# bridge IP aliases the simulator needs to be reachable from inside those containers, and the
# four snmpsim-command-responder processes themselves.
#
# Nothing here is wired into systemd/boot on purpose — the LibreNMS containers had their
# restart policy set to "no" specifically so they do NOT come back on their own after a host
# reboot. Run this script by hand whenever you actually want the lab up.
#
# The 172.18.0.10x bridge aliases and the snmpsim processes never survive a reboot either
# (ip addr add is not persistent, and snmpsim runs as plain background processes) — this script
# re-does all of that from scratch every time, so it's safe to re-run at any point.

set -euo pipefail

REPO_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
COMPOSE_FILE="/home/zhuk/librenms-docker/docker/examples/compose/compose.yml"
LIBRENMS_NETWORK="librenms_default"
BRIDGE_IPS=(172.18.0.101 172.18.0.102 172.18.0.103 172.18.0.104)

echo "[1/3] Starting LibreNMS containers..."
sudo docker compose -f "$COMPOSE_FILE" up -d

echo
echo "[2/3] Ensuring snmpsim's bridge IP aliases..."
# Resolve the bridge interface by network id rather than hardcoding br-b081b939765c — that name
# is derived from the network's id and WILL change if the librenms_default network ever gets
# recreated (e.g. `docker compose down` followed by `up`, not just container start/stop).
NETWORK_ID="$(sudo docker network inspect "$LIBRENMS_NETWORK" --format '{{.Id}}')"
BRIDGE_IF="br-${NETWORK_ID:0:12}"

if ! ip link show "$BRIDGE_IF" &>/dev/null; then
    echo "ERROR: bridge interface $BRIDGE_IF not found for network $LIBRENMS_NETWORK." >&2
    echo "The network may have been recreated with a different id — check 'docker network ls'." >&2
    exit 1
fi

for ip in "${BRIDGE_IPS[@]}"; do
    if ip -4 addr show "$BRIDGE_IF" | grep -q " ${ip}/32"; then
        echo "  $ip already present on $BRIDGE_IF"
    else
        sudo ip addr add "${ip}/32" dev "$BRIDGE_IF"
        echo "  added $ip to $BRIDGE_IF"
    fi
done

echo
echo "[3/3] Starting snmpsim..."
cd "$REPO_DIR"
source .venv/bin/activate
./run-snmpsim.sh

echo
echo "=== Lab up. LibreNMS: http://localhost:8000 (or whatever port the compose file maps) ==="
