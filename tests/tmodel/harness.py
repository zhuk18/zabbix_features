#!/usr/bin/env python3
"""
harness.py — reusable T-model test-campaign harness (topology-test-campaign-tmodel.md §2).

Environment facts discovered before writing this (recorded in report.md's Environment section
too): this implementation's collection path (topology-t-model-prototype-spec.md §3, as actually
built — not the use-case doc's original lldp.rem[]/lldp.loc.chassis sketch) is Trapper end to
end: push.py SNMP-walks a device and sends one JSON blob to `topology.discovery.raw`; a
dependent LLD rule (`topo.nbr.discovery`) and dependent item prototype
(`topo.nbr[{#LOCIFINDEX},{#NBRKEY}]`) derive everything else from that SAME blob. There is no
separate "SNMP-layer LLD" distinct from "trapper-layer LLD" to choose between (§2's two-layer
menu collapses to one here) — the only real choice is WHERE the blob's JSON comes from:

  - a real SNMP walk against snmpsim, via push.py (`push_via_snmp`) — exercises push.py's own
    parsing, slower, harder to control precisely;
  - a hand-built blob sent directly with zabbix_sender (`push_blob`) — fully controllable, used
    for every scenario that needs a specific, exact LLDP/identity fact pattern.

Every scenario notes which of the two it used.
"""

from __future__ import annotations

import json
import subprocess
import tempfile
import time
import urllib.request
from dataclasses import dataclass, field
from pathlib import Path

REPO_ROOT = Path(__file__).resolve().parents[2]
ZBX_URL = "http://192.168.6.232/zabbix/ui/api_jsonrpc.php"
ZBX_TOKEN = "f326c00c8b91deab38d5ca68c4828faffb39a7eec8a930b49b1502fe434e1649"
SENDER_BIN = str(REPO_ROOT / "bin" / "zabbix_sender")
SENDER_SERVER = "127.0.0.1"
SENDER_PORT = 10051
TEMPLATE_HOST = "Topology Discovery Reporter"


def zbx(method: str, params: dict) -> dict:
    req = urllib.request.Request(
        ZBX_URL,
        data=json.dumps({"jsonrpc": "2.0", "method": method, "params": params, "id": 1}).encode(),
        headers={"Content-Type": "application/json-rpc", "Authorization": f"Bearer {ZBX_TOKEN}"},
    )
    with urllib.request.urlopen(req, timeout=30) as resp:
        result = json.loads(resp.read())
    if "error" in result:
        raise RuntimeError(f"{method} failed: {result['error']}")
    return result["result"]


def get_group_id(name: str = "Templates") -> str:
    groups = zbx("hostgroup.get", {"output": ["groupid"], "filter": {"name": ["Linux servers"]}})
    if groups:
        return groups[0]["groupid"]
    # Fall back to any host group at all.
    groups = zbx("hostgroup.get", {"output": ["groupid"], "limit": 1})
    return groups[0]["groupid"]


def get_template_id() -> str:
    templates = zbx("template.get", {"output": ["templateid"], "filter": {"host": [TEMPLATE_HOST]}})
    if not templates:
        raise RuntimeError(f"Template '{TEMPLATE_HOST}' not found.")
    return templates[0]["templateid"]


def create_reporter_host(name: str, ip: str = "127.0.0.1") -> str:
    """Creates a disposable Host with the reporter template attached. Ground rule 3's 'reset' for
    a scenario is: delete this host (delete_host) and call this again for a clean slate."""
    existing = zbx("host.get", {"output": ["hostid"], "filter": {"host": [name]}})
    if existing:
        delete_host(existing[0]["hostid"])
    hostid = zbx("host.create", {
        "host": name,
        "interfaces": [{"type": 1, "main": 1, "useip": 1, "ip": ip, "dns": "", "port": "10050"}],
        "groups": [{"groupid": get_group_id()}],
        "templates": [{"templateid": get_template_id()}]
    })["hostids"][0]
    # Config cache sync lag: a just-created host's items aren't recognized by the trapper
    # process for a moment (observed live: immediate push -> processed:0/failed:2 even though
    # item.get already shows the items existing). 2s is a pragmatic margin, not a documented
    # constant -- if this ever flakes, this is the first thing to bump.
    time.sleep(2)
    return hostid


def delete_host(hostid: str) -> None:
    zbx("host.delete", [hostid])


def push_blob(host: str, reporter: dict, ports: list[dict], neighbors: list[dict]) -> dict:
    """Hand-built blob, sent directly via zabbix_sender — bypasses SNMP/push.py entirely.
    `reporter`/`ports`/`neighbors` follow push.py's exact blob shape (spec §4.1)."""
    blob = {
        "reporter": reporter, "ports": ports, "neighbors": neighbors,
        "stats": {"neighbors_total": len(neighbors), "resolved": len(neighbors)},
        "collected_at": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime())
    }
    return _send(host, blob)


def push_raw(host: str, raw_json_string: str) -> dict:
    """Sends an already-serialized string verbatim as the topology.discovery.raw value — for
    scenarios that need to send something that ISN'T a well-formed blob (C1/C3: empty array,
    truncated JSON, hostile strings inside otherwise-valid JSON)."""
    with tempfile.NamedTemporaryFile("w", suffix=".txt", delete=False) as fh:
        fh.write(f"{host}\ttopology.discovery.raw\t{raw_json_string}\n")
        path = fh.name
    result = subprocess.run([SENDER_BIN, "-z", SENDER_SERVER, "-p", str(SENDER_PORT), "-i", path],
                             capture_output=True, text=True, timeout=30)
    return {"returncode": result.returncode, "stdout": result.stdout, "stderr": result.stderr}


def _send(host: str, blob: dict, retries: int = 6) -> dict:
    raw_json = json.dumps(blob, separators=(",", ":"))
    with tempfile.NamedTemporaryFile("w", suffix=".txt", delete=False) as fh:
        fh.write(f"{host}\ttopology.discovery.raw\t{raw_json}\n")
        fh.write(f"{host}\ttopology.discovery.heartbeat\t{int(time.time())}\n")
        path = fh.name
    last = None
    for attempt in range(retries):
        result = subprocess.run([SENDER_BIN, "-z", SENDER_SERVER, "-p", str(SENDER_PORT), "-i", path],
                                 capture_output=True, text=True, timeout=30)
        last = {"returncode": result.returncode, "stdout": result.stdout, "stderr": result.stderr}
        # zabbix_sender exits 0 even for a partial "processed: 0; failed: N" -- config cache sync
        # lag right after host.create means the items aren't recognized yet (observed live,
        # repeatedly, under this box's current load). Treat any "failed: " count > 0 in the
        # response as retryable, up to `retries` times with a growing backoff, rather than a real
        # send error.
        if "failed: 0;" in result.stdout:
            return last
        time.sleep(1 + attempt)
    return last


def push_via_snmp(reporter_names: list[str] | None = None, config: str | None = None) -> str:
    """Real SNMP walk via push.py against the snmpsim lab -- used only when a scenario is
    specifically about SNMP-parsing behavior, not identity/reconciliation logic."""
    cfg = config or str(REPO_ROOT / "database" / "topology" / "discovery" / "reporters.example.json")
    cmd = ["python3", str(REPO_ROOT / "database" / "topology" / "discovery" / "push.py"),
           "--config", cfg, "--sender-bin", SENDER_BIN]
    if reporter_names:
        for name in reporter_names:
            cmd += ["--reporter", name]
    env = {"ZABBIX_SENDER_SERVER": SENDER_SERVER, "ZABBIX_SENDER_PORT": str(SENDER_PORT)}
    import os
    result = subprocess.run(cmd, capture_output=True, text=True, timeout=60, env={**os.environ, **env})
    return result.stdout + result.stderr


def run_lld_now(hostid: str) -> None:
    """Force an immediate LLD run instead of waiting out the interval (task.create, type 6)."""
    zbx("task.create", {"type": 6, "request": {"itemid": get_raw_itemid(hostid)}})


def wait_for(predicate, timeout=15, interval=1.0):
    """Polls predicate() until it returns a truthy value or timeout elapses; returns that value
    or None. Needed because LLD/dependent-item processing lags a push by an unpredictable amount
    under this box's current load -- a fixed sleep() flaked in practice (observed live: 3s
    sometimes wasn't enough for even the FIRST of two closely-spaced pushes to be fully
    processed before the second one landed)."""
    import time as _time
    deadline = _time.time() + timeout
    while _time.time() < deadline:
        result = predicate()
        if result:
            return result
        _time.sleep(interval)
    return None


def get_raw_itemid(hostid: str) -> str:
    items = zbx("item.get", {"hostids": [hostid], "filter": {"key_": "topology.discovery.raw"}, "output": ["itemid"]})
    return items[0]["itemid"]


def php_call(method: str, args: list):
    """Calls one CTopologyTModel method via the tmodel_call.php CLI bootstrap and returns its
    JSON-decoded result. This is Layer 3 (read-time assembly) exercised for real, not
    reimplemented in Python. Raises RuntimeError with the PHP exception message if the call
    itself threw (e.g. a promote() rejection) -- callers that WANT to see that as data rather
    than a Python exception should catch RuntimeError and inspect its message."""
    script = REPO_ROOT / "tests" / "tmodel" / "tmodel_call.php"
    result = subprocess.run(["php", str(script), method, json.dumps(args)],
                             capture_output=True, text=True, timeout=60)
    if result.returncode != 0:
        raise RuntimeError(f"tmodel_call.php {method} crashed: {result.stderr}")
    payload = json.loads(result.stdout)
    if not payload["ok"]:
        raise RuntimeError(f"{payload['class']}: {payload['error']}")
    return payload["result"]


def read_graph() -> dict:
    devices = php_call("getDevices", [])
    relations = php_call("getRelations", [])
    return {"devices": devices, "relations": relations}


def diagnostics() -> dict:
    return php_call("diagnostics", [])


def zbx_state(hostid: str) -> dict:
    """Raw Zabbix-side state for a host: tags, items (with tags/lastvalue/lastclock/discovery)."""
    host = zbx("host.get", {"hostids": [hostid], "output": ["hostid", "host"], "selectTags": "extend"})[0]
    items = zbx("item.get", {"hostids": [hostid], "output": ["itemid", "key_", "lastvalue", "lastclock"],
                              "selectTags": "extend", "selectItemDiscovery": ["status", "ts_delete", "ts_disable"]})
    return {"host": host, "items": items}


@dataclass
class TestResult:
    id: str
    uc: str | None
    expected: str
    actual: str = ""
    verdict: str = ""
    uc_verdict_held: bool | None = None
    proposed_uc_verdict: str | None = None
    evidence: dict = field(default_factory=dict)
    metrics_before: dict = field(default_factory=dict)
    metrics_after: dict = field(default_factory=dict)
    timings: dict = field(default_factory=dict)
    notes: str = ""

    def to_dict(self) -> dict:
        return {
            "id": self.id, "uc": self.uc, "expected": self.expected, "actual": self.actual,
            "verdict": self.verdict, "uc_verdict_held": self.uc_verdict_held,
            "proposed_uc_verdict": self.proposed_uc_verdict, "evidence": self.evidence,
            "metrics_before": self.metrics_before, "metrics_after": self.metrics_after,
            "timings": self.timings, "notes": self.notes
        }


class ResultsLog:
    def __init__(self, path: Path):
        self.path = path
        self.results: list[TestResult] = []

    def add(self, result: TestResult) -> None:
        self.results.append(result)
        print(f"[{result.id}] {result.verdict}: {result.actual[:120]}")

    def save(self) -> None:
        self.path.write_text(json.dumps([r.to_dict() for r in self.results], indent=2))
