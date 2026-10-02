#!/usr/bin/env python3
"""
push.py — topology discovery "push" component (spec §4.1, Trapper delivery mechanism).

Runs near a reporter/segment: SNMP-walks a device's sysName/ifTable/ifXTable/lldpRemTable,
assembles one JSON blob per reporter matching spec §4.1's exact shape, and sends it via
`zabbix_sender` to a Trapper item (`topology.discovery.raw`) on that reporter's Zabbix Host,
plus a heartbeat value (`topology.discovery.heartbeat`) on the same run.

No access to topo_nodes/topo_edges or DB here — only SNMP reach to the segment and network
reach to zabbix_sender's trapper port. This component has **zero Zabbix API dependency** —
no auth token, no API client, nothing. The two Trapper items it sends to
(topology.discovery.raw, topology.discovery.heartbeat) must already exist on the reporter's
Host, put there by attaching the `Topology Discovery Reporter` template (see
database/topology/discovery/template_topology_discovery_reporter.yaml) as part of onboarding
that Host — folded into the same manual "reporter must already exist as a Host" step §1/§4
already require, not a separate step. An earlier iteration had this script bootstrap its own
items via item.create against the Zabbix API; that traded the template-attachment step for
an API credential this component otherwise has no reason to hold, so it was dropped in favor
of the template (spec §4.1).

Does NOT reimplement the trapper wire protocol — shells out to the zabbix_sender binary.
Does NOT reimplement SNMP from scratch — shells out to net-snmp's snmpwalk/snmpget, which
matches this box's available tooling (pysnmp/snmpsim are present too, but the sync API on
the installed pysnmp 4.4.12 is dated/awkward to script against; net-snmp CLI is simpler and
exercises the exact same wire protocol against the SNMP-shaped snmpsim lab used to validate
this script — see the self-check notes in the delivery report for what that lab covers and
doesn't).

Credentials/config: never hardcoded (see topo-change-sender.sh at the repo root for the
anti-pattern this deliberately avoids). SNMP community strings live in the --config reporters
file, which is expected to be gitignored if it holds anything sensitive (see
database/topology/discovery/reporters.example.json for the shape).

Two outputs (topology-push-transport-spec.md):

  default       contract rows per role, sent to Zabbix trapper DISCOVERY rules with a topology_role
                (template "Topology by push": topology.push.ports, topology.push.lldp), each with the
                time the walk finished (zabbix_sender -T), plus the heartbeat. The server stores them as
                topo_lld_snapshot rows, the same table the SNMP-walk LLD template fills, so ingest.php
                reconciles both transports on one path. A role whose collection failed is left out of the
                call (failed != empty, FR 5.d): the last snapshot then stays. The rows are the output of
                lld_js/ (the Part 2 JavaScript) and of the native step "SNMP walk to topology rows"
                (src/libs/zbxpreproc/preproc_topology.c) for the same walk: this file ports lld_js/, and
                step_golden/ is its test (test_push.py).
  --legacy-blob the old single blob to topology.discovery.raw. Kept until the equivalence check of the spec
                passes, then removed.

Usage:
  push.py --config reporters.json [--reporter Switch1] [--dry-run] [--legacy-blob]
  push.py --name Switch1 --zabbix-host Switch1 --mgmt-ip 192.0.2.11 \\
          --snmp-target 127.0.0.1 --snmp-port 1611 --snmp-community zbxlab [--dry-run]

If zabbix_sender reports the target item doesn't exist, that means the reporter's Host is
missing the `Topology Discovery Reporter` template — attach it and re-run; this is a
deployment/onboarding mistake to fix, not something this script retries around.

Env vars:
  ZABBIX_SENDER_SERVER  Zabbix server/proxy trapper host (default: 127.0.0.1)
  ZABBIX_SENDER_PORT    Zabbix server/proxy trapper port (default: 10051)
"""

from __future__ import annotations

import argparse
import json
import os
import re
import subprocess
import sys
import tempfile
import time
from dataclasses import dataclass, field

REPO_ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__)))))
ZABBIX_SENDER_BIN = os.path.join(REPO_ROOT, "bin", "zabbix_sender")

RAW_ITEM_KEY = "topology.discovery.raw"
HEARTBEAT_ITEM_KEY = "topology.discovery.heartbeat"

# LLDP-MIB lldpRemEntry column numbers (1.0.8802.1.1.2.1.4.1.1.<col>.<timeMark>.<localPortNum>.<index>)
LLDP_REM_BASE = "1.0.8802.1.1.2.1.4.1.1"
LLDP_COL_CHASSIS_ID_SUBTYPE = 4
LLDP_COL_CHASSIS_ID = 5
LLDP_COL_PORT_ID_SUBTYPE = 6
LLDP_COL_PORT_ID = 7
LLDP_COL_PORT_DESC = 8
LLDP_COL_SYS_NAME = 9
LLDP_COL_SYS_DESC = 10

LLDP_PORT_ID_SUBTYPES = {
    1: "interfaceAlias",
    2: "portComponent",
    3: "macAddress",
    4: "networkAddress",
    5: "interfaceName",
    6: "agentCircuitId",
    7: "local",
}
LLDP_CHASSIS_ID_SUBTYPES = {
    1: "chassisComponent",
    2: "interfaceAlias",
    3: "portComponent",
    4: "macAddress",
    5: "networkAddress",
    6: "interfaceName",
    7: "local",
}

SYS_NAME_OID = "1.3.6.1.2.1.1.5.0"
SYS_OBJECT_ID_OID = "1.3.6.1.2.1.1.2.0"
LLDP_LOC_CHASSIS_ID_OID = "1.0.8802.1.1.2.1.3.2.0"  # not always populated by every agent (see below)

IF_TABLE_BASE = "1.3.6.1.2.1.2.2.1"
IF_INDEX_COL = 1
IF_DESCR_COL = 2
IF_TYPE_COL = 3
IF_PHYS_ADDRESS_COL = 6
IF_ADMIN_STATUS_COL = 7
IF_OPER_STATUS_COL = 8
IFX_NAME_BASE = "1.3.6.1.2.1.31.1.1.1.1"  # ifName — preferred over ifDescr when present

# A handful of well-known sysObjectID enterprise prefixes, just enough to demonstrate the
# vendor lookup — NOT an exhaustive vendor DB (out of scope for this prototype trial).
VENDOR_BY_ENTERPRISE_OID = {
    "1.3.6.1.4.1.9": "Cisco",
    "1.3.6.1.4.1.2011": "Huawei",
    "1.3.6.1.4.1.11.2.3.7": "HP",
    "1.3.6.1.4.1.2636": "Juniper",
}


class SnmpError(Exception):
    pass


def snmp_walk(target: str, port: int, community: str, version: str, oid: str) -> list[tuple[str, str, str]]:
    """Runs snmpwalk and returns a list of (oid, type, value) triples. Never raises for an
    empty/missing subtree (net-snmp prints "No Such Object"/"No more variables" — treated as
    zero rows, not an error, since not every device populates every table, e.g. lldpRemTable
    on a device with no active LLDP neighbors right now) — but DOES raise when snmpwalk itself
    exits non-zero, since that's how net-snmp reports a genuinely unreachable target (dead UDP
    port, wrong community, etc.), not an empty-but-healthy subtree. Confirmed empirically: an
    empty/nonexistent subtree on a live device ("No Such Instance...") exits 0, while a fully
    unreachable target ("Timeout: No Response from ...") exits 1 — the two are reliably
    distinguishable this way. Without this check, an unreachable device silently produced a
    blob with a blank sysname/no chassis_id (still built successfully, since mgmt_ip always
    comes from the reporter config, not SNMP) that ingest then had nothing to match against,
    creating a new blank orphaned Device instead of failing loudly — found live against this
    lab when snmpsim was stopped mid-testing."""
    cmd = ["snmpwalk", "-v", version, "-c", community, "-On", "-t", "3", "-r", "1",
           f"{target}:{port}", oid]
    try:
        out = subprocess.run(cmd, capture_output=True, text=True, timeout=30)
    except (subprocess.TimeoutExpired, FileNotFoundError) as exc:
        raise SnmpError(f"snmpwalk failed for {oid} against {target}:{port}: {exc}") from exc

    if out.returncode != 0:
        detail = out.stderr.strip() or out.stdout.strip() or f"exit code {out.returncode}"
        raise SnmpError(f"snmpwalk failed for {oid} against {target}:{port}: {detail}")

    rows = []
    for line in out.stdout.splitlines():
        m = re.match(r"^(\.\S+)\s*=\s*(?:([A-Za-z][\w-]*):\s*)?(.*)$", line)
        if not m:
            continue
        row_oid, row_type, row_value = m.group(1), m.group(2) or "STRING", m.group(3)
        if "No Such" in row_value or "No more variables" in line:
            continue
        rows.append((row_oid.lstrip("."), row_type, row_value))
    return rows


def snmp_get(target: str, port: int, community: str, version: str, oid: str) -> str | None:
    rows = snmp_walk(target, port, community, version, oid)
    # snmpget semantics via a scoped walk: only accept an exact-OID match (a walk that fell
    # through to the next OID in the tree means this one doesn't exist on the agent).
    for row_oid, _row_type, row_value in rows:
        if row_oid == oid.lstrip("."):
            return decode_snmp_value(_row_type, row_value)
    return None


def decode_snmp_value(row_type: str, raw: str) -> str:
    raw = raw.strip().strip('"')
    if row_type in ("Hex-STRING",):
        octets = raw.split()
        return ":".join(o.lower() for o in octets)
    return raw


def parse_indexed_table(rows: list[tuple[str, str, str]], base_oid: str) -> dict[str, tuple[str, str]]:
    """rows are pre-filtered to one column's worth of walk output; returns {index_suffix: (type, raw_value)}."""
    out = {}
    prefix = base_oid.lstrip(".") + "."
    for row_oid, row_type, row_value in rows:
        if not row_oid.startswith(prefix):
            continue
        index = row_oid[len(prefix):]
        out[index] = (row_type, row_value)
    return out


@dataclass
class ReporterConfig:
    name: str
    zabbix_host: str
    mgmt_ip: str
    snmp_target: str
    snmp_port: int = 161
    snmp_community: str = "public"
    snmp_version: str = "2c"


def load_reporters(config_path: str) -> list[ReporterConfig]:
    with open(config_path) as fh:
        data = json.load(fh)
    return [ReporterConfig(
        name=r["name"],
        zabbix_host=r["zabbix_host"],
        mgmt_ip=r["mgmt_ip"],
        snmp_target=r["snmp_target"],
        snmp_port=int(r.get("snmp_port", 161)),
        snmp_community=r.get("snmp_community", "public"),
        snmp_version=r.get("snmp_version", "2c"),
    ) for r in data]


def resolve_vendor(sys_object_id: str | None) -> str:
    if not sys_object_id:
        return "unknown"
    oid = sys_object_id.lstrip(".")
    # longest-prefix match against the small known-vendor table above
    best = None
    for prefix, vendor in VENDOR_BY_ENTERPRISE_OID.items():
        if oid == prefix or oid.startswith(prefix + "."):
            if best is None or len(prefix) > len(best[0]):
                best = (prefix, vendor)
    return best[1] if best else "unknown"


def collect_ports(target: str, port: int, community: str, version: str) -> dict[int, dict]:
    if_walk = snmp_walk(target, port, community, version, IF_TABLE_BASE)
    ifx_name_walk = snmp_walk(target, port, community, version, IFX_NAME_BASE)

    descr = parse_indexed_table(if_walk, f"{IF_TABLE_BASE}.{IF_DESCR_COL}")
    iftype = parse_indexed_table(if_walk, f"{IF_TABLE_BASE}.{IF_TYPE_COL}")
    physaddr = parse_indexed_table(if_walk, f"{IF_TABLE_BASE}.{IF_PHYS_ADDRESS_COL}")
    admin = parse_indexed_table(if_walk, f"{IF_TABLE_BASE}.{IF_ADMIN_STATUS_COL}")
    oper = parse_indexed_table(if_walk, f"{IF_TABLE_BASE}.{IF_OPER_STATUS_COL}")
    ifname = parse_indexed_table(ifx_name_walk, IFX_NAME_BASE)

    # ifIndex column is the authoritative index list — every other column is keyed by the
    # same suffix (the ifIndex value itself, since ifTable/ifXTable are single-index tables).
    if_index_walk = snmp_walk(target, port, community, version, f"{IF_TABLE_BASE}.{IF_INDEX_COL}")
    index_col = parse_indexed_table(if_index_walk, f"{IF_TABLE_BASE}.{IF_INDEX_COL}")

    ports = {}
    for suffix, (_type, value) in index_col.items():
        if_index = int(value)
        name = decode_snmp_value(*ifname.get(suffix, ("STRING", ""))) or \
            decode_snmp_value(*descr.get(suffix, ("STRING", f"if{if_index}")))
        type_code = int(decode_snmp_value(*iftype.get(suffix, ("INTEGER", "6")))) if suffix in iftype else 6
        # 161 = ieee8023adLag (LACP port-channel); everything else defaults to "physical" —
        # this prototype's SNMP-only heuristic has no signal for "mgmt" (that's typically a
        # naming/OOB-VLAN convention, not a standard MIB value), so mgmt ports fall back to
        # "physical" here; flagged as a known gap vs. the richer seed.php fixture.
        if_type = "lag" if type_code == 161 else "physical"
        mac = decode_snmp_value(*physaddr[suffix]) if suffix in physaddr else None
        admin_status = "up" if suffix in admin and decode_snmp_value(*admin[suffix]) == "1" else "down"
        oper_status = "up" if suffix in oper and decode_snmp_value(*oper[suffix]) == "1" else "down"

        ports[if_index] = {
            "if_index": if_index,
            "name": name,
            "if_type": if_type,
            "mac": mac,
            "admin_status": admin_status,
            "oper_status": oper_status,
        }
    return ports


def resolve_port_label(remote_port_desc: str | None, remote_port_id: str | None,
                        remote_port_id_subtype: str | None) -> str:
    """Shared label-resolution rule (brief's push-component bullet): prefer lldpRemPortDesc;
    only when it's empty, branch on lldpRemPortIdSubtype to decide how to read remote_port_id.
    The raw fields (remote_port_id / remote_port_id_subtype / remote_port_desc) are what
    actually travels in the blob per spec §4.1's JSON shape — this function is the shared
    resolution rule applied on the reading side (mirrored byte-for-byte in ingest.php's
    neighbor Port-name assignment, since that's where a Port node's `name` attr is actually
    written); it lives here too so the rule has one documented, testable definition and push
    can self-check it (see push.py's --selftest) even though the wire format stays raw."""
    if remote_port_desc:
        return remote_port_desc
    if remote_port_id_subtype == "interfaceName" and remote_port_id:
        return remote_port_id
    if remote_port_id_subtype == "macAddress" and remote_port_id:
        return remote_port_id
    if remote_port_id:
        return remote_port_id
    return "unknown"


def collect_neighbors(target: str, port: int, community: str, version: str) -> tuple[list[dict], dict]:
    rows = snmp_walk(target, port, community, version, LLDP_REM_BASE)

    by_col = {}
    for row_oid, row_type, row_value in rows:
        prefix = LLDP_REM_BASE + "."
        if not row_oid.startswith(prefix):
            continue
        rest = row_oid[len(prefix):]
        col_str, _, index_suffix = rest.partition(".")
        try:
            col = int(col_str)
        except ValueError:
            continue
        by_col.setdefault(col, {})[index_suffix] = (row_type, row_value)

    # every populated column shares the same set of index suffixes (<timeMark>.<localPortNum>.<index>)
    all_suffixes = set()
    for col_rows in by_col.values():
        all_suffixes.update(col_rows.keys())

    neighbors = []
    total = 0
    resolved = 0
    for suffix in sorted(all_suffixes):
        total += 1
        try:
            parts = suffix.split(".")
            if len(parts) < 2:
                raise ValueError(f"unexpected lldpRemTable index shape: {suffix!r}")
            local_if_index = int(parts[1])  # <timeMark>.<localPortNum>.<index> — position 1 is localPortNum

            chassis_id_raw = by_col.get(LLDP_COL_CHASSIS_ID, {}).get(suffix)
            chassis_id = decode_snmp_value(*chassis_id_raw) if chassis_id_raw else None

            port_id_raw = by_col.get(LLDP_COL_PORT_ID, {}).get(suffix)
            port_id = decode_snmp_value(*port_id_raw) if port_id_raw else None

            port_id_subtype_raw = by_col.get(LLDP_COL_PORT_ID_SUBTYPE, {}).get(suffix)
            port_id_subtype = LLDP_PORT_ID_SUBTYPES.get(
                int(decode_snmp_value(*port_id_subtype_raw))) if port_id_subtype_raw else None

            port_desc_raw = by_col.get(LLDP_COL_PORT_DESC, {}).get(suffix)
            port_desc = decode_snmp_value(*port_desc_raw) if port_desc_raw else None

            sys_name_raw = by_col.get(LLDP_COL_SYS_NAME, {}).get(suffix)
            sys_name = decode_snmp_value(*sys_name_raw) if sys_name_raw else None

            entry = {
                "local_if_index": local_if_index,
                "remote_chassis_id": chassis_id,
                "remote_sysname": sys_name,
                "remote_port_id": port_id,
                "remote_port_id_subtype": port_id_subtype,
                "remote_port_desc": port_desc,
            }
            # rule 1 (spec §3): "resolves" means chassis id or sysname is present — an entry
            # with neither is topologically useless (nothing to key a Device on) but is still
            # forwarded as-is; the ingest side is the one that actually declines to create a
            # Device for it, this counter just tracks it up front per §4.1's stats contract.
            if chassis_id or sys_name:
                resolved += 1
            neighbors.append(entry)
        except Exception as exc:  # noqa: BLE001 - one bad neighbor must never sink the blob
            print(f"WARNING: skipping malformed lldpRemTable entry (index {suffix}): {exc}", file=sys.stderr)
            continue

    return neighbors, {"neighbors_total": total, "resolved": resolved}


def collect_reporter_identity(target: str, port: int, community: str, version: str,
                               mgmt_ip: str) -> dict:
    sysname = snmp_get(target, port, community, version, SYS_NAME_OID) or ""
    sys_object_id = snmp_get(target, port, community, version, SYS_OBJECT_ID_OID)
    chassis_id = snmp_get(target, port, community, version, LLDP_LOC_CHASSIS_ID_OID)

    if not chassis_id:
        # Deviation from a fully spec'd device: lldpLocChassisId isn't populated by every
        # agent (and isn't in this lab's fixture at all — see the delivery report). Fall back
        # to the lowest-ifIndex port's MAC as a stand-in chassis identity, a common real-world
        # convention (many vendors' own chassis ID *is* the base MAC of the lowest port) —
        # flagged here rather than silently treated as equivalent to a real LLDP-sourced value.
        ports = collect_ports(target, port, community, version)
        macs = [p["mac"] for p in sorted(ports.values(), key=lambda p: p["if_index"]) if p.get("mac")]
        chassis_id = macs[0] if macs else None

    return {
        "sysname": sysname,
        "chassis_id": chassis_id,
        "mgmt_ip": mgmt_ip,
        "vendor": resolve_vendor(sys_object_id),
    }


def build_blob(cfg: ReporterConfig) -> dict:
    reporter = collect_reporter_identity(cfg.snmp_target, cfg.snmp_port, cfg.snmp_community,
                                          cfg.snmp_version, cfg.mgmt_ip)
    ports = collect_ports(cfg.snmp_target, cfg.snmp_port, cfg.snmp_community, cfg.snmp_version)
    neighbors, stats = collect_neighbors(cfg.snmp_target, cfg.snmp_port, cfg.snmp_community, cfg.snmp_version)

    return {
        "reporter": reporter,
        "ports": [ports[k] for k in sorted(ports)],
        "neighbors": neighbors,
        "stats": stats,
        "collected_at": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime()),
    }


# ---- contract rows (topology-push-transport-spec.md §3) ----
#
# A port of lld_js/common.js, ports.js and neighbors.js, the reference of the native step. It works on the same
# text the walk[] item returns, one variable per line: ".1.3.6... = TYPE: value". Identity strings (chassis ids, MACs,
# management IPs) must come out byte for byte as the step emits them; step_golden/expected/ is the test.

ROLE_PORTS_KEY = "topology.push.ports"
ROLE_LLDP_KEY = "topology.push.lldp"
# The legacy template defines topology.discovery.heartbeat; a host in transition has both templates linked, so the push
# template uses its own key (topology-push-transport-spec.md §4). --legacy-blob keeps sending the old one.
HEARTBEAT_KEY = "topology.push.heartbeat"

# The OIDs ports + LLDP need (a subset of lld_js/walk_oids.txt). CDP, FDB and LAG are not collected by push.py.
CONTRACT_WALK_OIDS = [
    "1.3.6.1.2.1.1.5",                 # sysName
    "1.3.6.1.2.1.2.2.1",               # ifTable
    "1.3.6.1.2.1.31.1.1.1.1",          # ifName
    "1.3.6.1.2.1.31.1.1.1.15",         # ifHighSpeed
    "1.0.8802.1.1.2.1.3.2",            # lldpLocChassisId
    "1.0.8802.1.1.2.1.3.7",            # lldpLocPortTable
    "1.0.8802.1.1.2.1.4.1",            # lldpRemTable
    "1.0.8802.1.1.2.1.4.2",            # lldpRemManAddrTable
]
LLDP_LOC_CHASSIS_ID_FULL = "1.0.8802.1.1.2.1.3.2.0"
IF_TYPE_BASE = "1.3.6.1.2.1.2.2.1.3"


class WalkUnusable(Exception):
    """The walk holds no variables, or no ifType: the roles cannot be told from an empty device."""


_WALK_LINE = re.compile(r"^\s*\.?([0-9][0-9.]*)\s*=\s*(?:([A-Za-z][\w-]*):\s*)?(.*)$")


def parse_walk(text: str) -> dict[str, tuple[str, str]]:
    """{oid (no leading dot): (type, raw)}; lines that are not a variable are ignored."""
    rows: dict[str, tuple[str, str]] = {}
    for line in str(text).splitlines():
        m = _WALK_LINE.match(line)
        if m is None or re.match(r"^No Such (Object|Instance)", m.group(3)) or "No more variables" in line:
            continue
        rows[m.group(1)] = (m.group(2) or "STRING", m.group(3))
    return rows


def _decode(row: tuple[str, str]) -> str:
    """Decoded text of a walk row: Hex-STRING -> colon hex, quotes stripped, "name(3)" enums -> "3"."""
    row_type, raw = row[0], row[1].strip()
    if row_type == "Hex-STRING":
        return ":".join(o.lower() for o in raw.split())
    if len(raw) > 1 and raw[0] == '"' and raw[-1] == '"':
        raw = raw[1:-1]
    elif row_type in ("INTEGER", "Gauge32", "Counter32", "Unsigned32"):
        m = re.search(r"\((-?[0-9]+)\)\s*$", raw)
        if m:
            raw = m.group(1)
    return raw


def _norm_mac(text: str) -> str:
    """A MAC written as text -> "aa:bb:cc:00:00:0a" (six octets of one or two hex digits joined by ':' or '-', or the
    Cisco form "aabb.cc00.000a"); anything else is returned as it came."""
    m = re.match(r"^([0-9a-fA-F]{1,2})([:-])([0-9a-fA-F]{1,2})\2([0-9a-fA-F]{1,2})\2([0-9a-fA-F]{1,2})\2"
                 r"([0-9a-fA-F]{1,2})\2([0-9a-fA-F]{1,2})$", text)
    if m:
        octets = [m.group(i) for i in (1, 3, 4, 5, 6, 7)]
    else:
        d = re.match(r"^([0-9a-fA-F]{4})\.([0-9a-fA-F]{4})\.([0-9a-fA-F]{4})$", text)
        if not d:
            return text
        octets = []
        for i in (1, 2, 3):
            octets += [d.group(i)[:2], d.group(i)[2:]]
    return ":".join(("0" if len(o) < 2 else "") + o.lower() for o in octets)


def _table(rows: dict, base: str) -> dict[str, tuple[str, str]]:
    prefix = base + "."
    return {oid[len(prefix):]: row for oid, row in rows.items() if oid.startswith(prefix)}


def _numeric_keys(obj: dict) -> list[int]:
    return sorted(int(k) for k in obj if re.match(r"^[0-9]+$", k))


def _parse_int(text: str):
    m = re.match(r"^\s*([+-]?[0-9]+)", text)
    return int(m.group(1)) if m else None


def _interfaces(rows: dict) -> dict[str, dict]:
    """{ifIndex: {name, mac}} from ifName (preferred), ifDescr and ifPhysAddress."""
    base = "1.3.6.1.2.1.2.2.1"
    indexes, descr, phys = _table(rows, base + ".1"), _table(rows, base + ".2"), _table(rows, base + ".6")
    names = _table(rows, "1.3.6.1.2.1.31.1.1.1.1")
    result = {}
    # JavaScript visits integer-like keys in ascending order; keep that, byName below depends on it
    for k in sorted(indexes, key=lambda x: (0, int(x)) if re.match(r"^[0-9]+$", x) else (1, 0)):
        name = _decode(names[k]) if k in names else ""
        if name == "" and k in descr:
            name = _decode(descr[k])
        result[k] = {"name": name if name != "" else "if" + k,
                     "mac": _norm_mac(_decode(phys[k])) if k in phys else ""}
    return result


def _local_chassis(rows: dict, ifs: dict) -> str:
    """lldpLocChassisId, else the MAC of the lowest-ifIndex port that has one."""
    row = rows.get(LLDP_LOC_CHASSIS_ID_FULL)
    value = _norm_mac(_decode(row)) if row is not None else ""
    if value != "":
        return value
    for k in _numeric_keys(ifs):
        if ifs[str(k)]["mac"] != "":
            return ifs[str(k)]["mac"]
    return ""


def _with_chassis(row: dict, chassis: str) -> dict:
    if chassis != "":
        row["{#LOC_CHASSIS}"] = chassis
    return row


def rows_ports(rows: dict) -> list[dict]:
    """PORTS: one row per interface. Fails (WalkUnusable) when there is no ifType, like the step's `error` anchor."""
    if not _table(rows, IF_TYPE_BASE):
        raise WalkUnusable("the walk holds no ifType (no IF-MIB)")
    ifs = _interfaces(rows)
    chassis = _local_chassis(rows, ifs)
    types, admin, oper = _table(rows, IF_TYPE_BASE), _table(rows, "1.3.6.1.2.1.2.2.1.7"), _table(rows, "1.3.6.1.2.1.2.2.1.8")
    speeds = _table(rows, "1.3.6.1.2.1.31.1.1.1.15")
    out = []
    for key in _numeric_keys(ifs):
        k = str(key)
        row = {"{#IFINDEX}": k, "{#IFNAME}": ifs[k]["name"]}
        if k in types:
            row["{#IFTYPE}"] = _decode(types[k])
        if ifs[k]["mac"] != "":
            row["{#IFMAC}"] = ifs[k]["mac"]
        if k in admin:
            row["{#IFADMINSTATUS}"] = _decode(admin[k])
        if k in oper:
            row["{#IFOPERSTATUS}"] = _decode(oper[k])
        if k in speeds:
            row["{#IFSPEED}"] = _decode(speeds[k])      # ifHighSpeed, Mbit/s
        out.append(_with_chassis(row, chassis))
    return out


def rows_lldp(rows: dict) -> list[dict]:
    """NEIGHBORS from LLDP: one row per lldpRemTable entry. [] when the table is empty or absent."""
    ifs = _interfaces(rows)
    chassis = _local_chassis(rows, ifs)
    rem, loc = "1.0.8802.1.1.2.1.4.1.1", "1.0.8802.1.1.2.1.3.7.1"
    rem_chassis_type, rem_chassis = _table(rows, rem + ".4"), _table(rows, rem + ".5")
    rem_port_type, rem_port = _table(rows, rem + ".6"), _table(rows, rem + ".7")
    rem_port_desc, rem_sysname = _table(rows, rem + ".8"), _table(rows, rem + ".9")
    loc_port_type, loc_port_id = _table(rows, loc + ".2"), _table(rows, loc + ".3")
    man_addr = _table(rows, "1.0.8802.1.1.2.1.4.2.1.3")
    by_name, by_mac = {}, {}
    for k, interface in ifs.items():
        by_name[interface["name"]] = k
        if interface["mac"] != "":
            by_mac[interface["mac"]] = k

    suffixes = set()
    for col in (rem_chassis, rem_port, rem_port_desc, rem_sysname, rem_chassis_type, rem_port_type):
        suffixes.update(col)

    def local_if_index(port_num: str) -> str:
        t = _parse_int(_decode(loc_port_type[port_num])) if port_num in loc_port_type else 0
        ident = _decode(loc_port_id[port_num]) if port_num in loc_port_id else ""
        if ident != "":
            if t == 5 and ident in by_name:
                return by_name[ident]
            if t == 3 and _norm_mac(ident) in by_mac:
                return by_mac[_norm_mac(ident)]
            if t == 7 and ident in ifs:
                return ident
        return port_num if port_num in ifs else ""

    def decode_id(row, subtype) -> str:
        text = _decode(row)
        if subtype == 5 and row[0] == "Hex-STRING":
            octets = row[1].strip().split()
            if len(octets) == 5 and int(octets[0], 16) == 1:       # address family 1 = IPv4
                return ".".join(str(int(o, 16)) for o in octets[1:])
        return text

    def management_ip(suffix: str) -> str:
        for key in man_addr:
            if key.startswith(suffix + "."):
                p = key[len(suffix) + 1:].split(".")
                if p[0] == "1" and len(p) > 1 and p[1] == "4" and len(p) == 6:
                    return ".".join(p[2:])
        return ""

    out = []
    for sfx in sorted(suffixes):
        parts = sfx.split(".")
        if len(parts) < 3:
            continue                                               # not a TimeMark.LocalPortNum.RemIndex index
        chassis_type = _parse_int(_decode(rem_chassis_type[sfx])) if sfx in rem_chassis_type else 0
        port_type = _parse_int(_decode(rem_port_type[sfx])) if sfx in rem_port_type else 0
        row = {"{#IFINDEX}": local_if_index(parts[1]), "{#SOURCE}": "lldp"}
        ip = management_ip(sfx)
        if row["{#IFINDEX}"] == "":
            del row["{#IFINDEX}"]
        else:
            row["{#IFNAME}"] = ifs[row["{#IFINDEX}"]]["name"]
        if sfx in rem_chassis:
            row["{#REM_CHASSIS}"] = decode_id(rem_chassis[sfx], chassis_type)
            if chassis_type in (4, 0):
                row["{#REM_CHASSIS}"] = _norm_mac(row["{#REM_CHASSIS}"])
            if chassis_type in LLDP_CHASSIS_ID_SUBTYPES:
                row["{#REM_CHASSIS_TYPE}"] = LLDP_CHASSIS_ID_SUBTYPES[chassis_type]
        if ip != "":
            row["{#REM_MGMT_IP}"] = ip
        if sfx in rem_sysname:
            row["{#REM_SYSNAME}"] = _decode(rem_sysname[sfx])
        if sfx in rem_port:
            row["{#REM_PORT}"] = decode_id(rem_port[sfx], port_type)
            if port_type in (3, 0):
                row["{#REM_PORT}"] = _norm_mac(row["{#REM_PORT}"])
            if port_type in LLDP_PORT_ID_SUBTYPES:
                row["{#REM_PORT_TYPE}"] = LLDP_PORT_ID_SUBTYPES[port_type]
        if sfx in rem_port_desc:
            row["{#REM_PORT_DESC}"] = _decode(rem_port_desc[sfx])
        out.append(_with_chassis(row, chassis))
    return out


# What each role needs from the walk. A role is sent only when every OID it reads was walked completely.
ROLE_OIDS = {
    ROLE_PORTS_KEY: ["1.3.6.1.2.1.2.2.1", "1.3.6.1.2.1.31.1.1.1.1", "1.3.6.1.2.1.31.1.1.1.15", "1.0.8802.1.1.2.1.3.2"],
    ROLE_LLDP_KEY: ["1.3.6.1.2.1.2.2.1", "1.3.6.1.2.1.31.1.1.1.1", "1.0.8802.1.1.2.1.3.2", "1.0.8802.1.1.2.1.3.7",
                    "1.0.8802.1.1.2.1.4.1", "1.0.8802.1.1.2.1.4.2"],
}


def build_role_values(walk_text: str, failed_oids: dict[str, str] | None = None) -> tuple[dict[str, str], list[str]]:
    """{trapper key: JSON array} for every role that was collected, and the problems of the roles that were not.
    A role that failed is absent (failed != empty, FR 5.d); an empty table gives "[]". `failed_oids` are the walks that
    did not complete (timeout, truncated, non-increasing OID, early endOfMibView): a role that reads one of them is not
    sent, the other roles are."""
    failed_oids = failed_oids or {}
    rows = parse_walk(walk_text)
    if not rows and not failed_oids:
        raise WalkUnusable("the SNMP walk holds no variables (device unreachable or wrong OIDs)")
    values, problems = {}, []
    for key, builder in ((ROLE_PORTS_KEY, rows_ports), (ROLE_LLDP_KEY, rows_lldp)):
        broken = [f"{oid}: {failed_oids[oid]}" for oid in ROLE_OIDS[key] if oid in failed_oids]
        if broken:
            problems.append(f"{key}: " + "; ".join(broken))
            continue
        try:
            values[key] = json.dumps(builder(rows), separators=(",", ":"), ensure_ascii=False)
        except WalkUnusable as exc:
            problems.append(f"{key}: {exc}")
    return values, problems


def _oid_tuple(oid: str) -> tuple[int, ...]:
    return tuple(int(x) for x in oid.strip(".").split(".") if x.isdigit())


def check_walk_output(root: str, lines: list[str]) -> None:
    """A walk that ended early is a failure, not the end of the table (topology-push-transport-spec.md §2 rule 3):
    the OIDs must strictly increase, and a subtree that returned variables must not end with endOfMibView. An empty
    subtree at the very end of the MIB view answers endOfMibView for the root itself: that is an empty table."""
    previous: tuple[int, ...] | None = None
    in_subtree = 0
    root_t = _oid_tuple(root)
    for line in lines:
        m = re.match(r"^\s*(\.?[0-9][0-9.]*)\s*=\s*(.*)$", line)
        if not m:
            continue
        current = _oid_tuple(m.group(1))
        if "No more variables left in this MIB View" in m.group(2):
            if in_subtree and current != root_t and current[:len(root_t)] == root_t:
                raise SnmpError(f"early endOfMibView after {in_subtree} variable(s) of {root}")
            continue
        if re.match(r"^No Such (Object|Instance)", m.group(2)):
            continue
        if previous is not None and current <= previous:
            raise SnmpError(f"non-increasing OID {m.group(1)} after {'.'.join(map(str, previous))}")
        previous = current
        if current[:len(root_t)] == root_t:
            in_subtree += 1


def collect_walk(target: str, port: int, community: str, version: str) -> tuple[str, dict[str, str]]:
    """What the template's walk[] item would return (one variable per line) and {oid: why} for every OID whose walk did
    not complete. Raises SnmpError only when the device cannot be reached at all (the first walk times out)."""
    lines: list[str] = []
    failed: dict[str, str] = {}
    tool = "snmpwalk" if version == "1" else "snmpbulkwalk"
    for index, oid in enumerate(CONTRACT_WALK_OIDS):
        cmd = [tool, "-v", version, "-c", community, "-On", "-t", "5", "-r", "1"]
        if tool == "snmpbulkwalk":
            cmd += ["-Cr50"]
        cmd += [f"{target}:{port}", oid]
        try:
            out = subprocess.run(cmd, capture_output=True, text=True, timeout=120)
            if out.returncode != 0:
                detail = out.stderr.strip() or out.stdout.strip() or f"exit code {out.returncode}"
                raise SnmpError(f"{tool} failed for {oid} against {target}:{port}: {detail}")
            walked = out.stdout.splitlines()
            check_walk_output(oid, walked)
        except (subprocess.TimeoutExpired, FileNotFoundError) as exc:
            if index == 0:
                raise SnmpError(f"{tool} failed for {oid} against {target}:{port}: {exc}") from exc
            failed[oid] = str(exc)
            continue
        except SnmpError as exc:
            if index == 0 and "non-increasing" not in str(exc) and "endOfMibView" not in str(exc):
                raise                                  # the device does not answer at all: nothing to send
            failed[oid] = str(exc)
            continue
        lines += [l for l in walked if not re.search(r" = No Such (Instance|Object)", l)]
    return "\n".join(lines), failed


def collect_walk_text(target: str, port: int, community: str, version: str) -> str:
    """Compatibility wrapper: the walk text, raising SnmpError when any OID failed."""
    text, failed = collect_walk(target, port, community, version)
    if failed:
        raise SnmpError("; ".join(f"{oid}: {why}" for oid, why in failed.items()))
    return text


def send_role_values(zabbix_host: str, values: dict[str, str], collected_at: int, heartbeat_at: int, server: str,
                     port: int, sender_bin: str) -> None:
    """One zabbix_sender call: every collected role with the time the walk finished (-T), and the heartbeat."""
    with tempfile.NamedTemporaryFile("w", suffix=".txt", delete=False) as fh:
        for key, value in values.items():
            fh.write(f"{zabbix_host}\t{key}\t{collected_at}\t{value}\n")
        fh.write(f"{zabbix_host}\t{HEARTBEAT_KEY}\t{heartbeat_at}\t{heartbeat_at}\n")
        input_path = fh.name
    try:
        result = subprocess.run([sender_bin, "-z", server, "-p", str(port), "-T", "-i", input_path],
                                capture_output=True, text=True, timeout=60)
        output = result.stdout.strip()
        print(output)
        if result.returncode != 0 or "failed: 0" not in output:
            detail = output or result.stderr.strip() or f"(no output, exit code {result.returncode})"
            raise RuntimeError(
                f"zabbix_sender reported a failure sending to Host '{zabbix_host}' - most likely the "
                f"'Topology by push' template (topology.push.* discovery rules and the heartbeat item) is not "
                f"attached to that Host yet; attach it and re-run. zabbix_sender output: {detail}")
    finally:
        os.unlink(input_path)


def run_reporter_rows(cfg: ReporterConfig, args) -> bool:
    """The default transport: contract rows per role. Nothing is sent for a role that could not be collected."""
    print(f"--- {cfg.name} ({cfg.snmp_target}:{cfg.snmp_port}) ---")
    values: dict[str, str] = {}
    ok = True
    collected_at = int(time.time())
    try:
        text, failed_oids = collect_walk(cfg.snmp_target, cfg.snmp_port, cfg.snmp_community, cfg.snmp_version)
        collected_at = int(time.time())                       # the time the walk finished
        values, problems = build_role_values(text, failed_oids)
        for problem in problems:
            print(f"ERROR: nothing sent for {problem}", file=sys.stderr)
            ok = False
    except (SnmpError, WalkUnusable) as exc:
        print(f"ERROR: nothing sent for any role of reporter '{cfg.name}': {exc}", file=sys.stderr)
        ok = False

    if args.dry_run:
        for key, value in values.items():
            print(f"{key} @{collected_at}: {value}")
        return ok

    try:
        send_role_values(cfg.zabbix_host, values, collected_at, int(time.time()), args.sender_server,
                         args.sender_port, args.sender_bin)
    except (RuntimeError, OSError) as exc:
        print(f"ERROR: failed to send for reporter '{cfg.name}': {exc}", file=sys.stderr)
        return False

    state = 'OK' if ok else ('PARTIAL' if values else 'FAILED')
    print(f"{state}: sent {len(values)} role(s) for '{cfg.name}': {', '.join(values) or '-'} (+ heartbeat).")
    return ok


# ---- zabbix_sender ----

def send_via_zabbix_sender(zabbix_host: str, raw_blob: dict, server: str, port: int, sender_bin: str) -> None:
    raw_json = json.dumps(raw_blob, separators=(",", ":"))
    heartbeat_ts = str(int(time.time()))

    with tempfile.NamedTemporaryFile("w", suffix=".txt", delete=False) as fh:
        fh.write(f"{zabbix_host}\t{RAW_ITEM_KEY}\t{raw_json}\n")
        fh.write(f"{zabbix_host}\t{HEARTBEAT_ITEM_KEY}\t{heartbeat_ts}\n")
        input_path = fh.name

    try:
        cmd = [sender_bin, "-z", server, "-p", str(port), "-i", input_path]
        result = subprocess.run(cmd, capture_output=True, text=True, timeout=30)
        output = result.stdout.strip()
        print(output)
        # zabbix_sender exits non-zero both when it can't reach the server at all AND when the
        # server accepted the connection but rejected one or more values (e.g. "processed: 0;
        # failed: 2; total: 2" for a Host missing the target item — the shape you get when the
        # 'Topology Discovery Reporter' template hasn't been attached yet, spec §4.1). Its own
        # per-run summary already says this clearly; the bug in the old message was that it only
        # quoted stderr, which zabbix_sender leaves empty for this case — so the summary (on
        # stdout, already printed above) got silently dropped from the exception text. Quote
        # both, and always mention the template explicitly on a reported failure, since
        # zabbix_sender doesn't name *why* a value failed (no such item vs. wrong value type,
        # etc.) — the template is by far the most likely cause and the one deployment step this
        # script depends on. This is a deployment mistake to surface immediately, not to retry.
        if result.returncode != 0 or "failed: 0" not in output:
            detail = output or result.stderr.strip() or f"(no output, exit code {result.returncode})"
            raise RuntimeError(
                f"zabbix_sender reported a failure sending to Host '{zabbix_host}' — most "
                f"likely the 'Topology Discovery Reporter' template (topology.discovery.raw / "
                f"topology.discovery.heartbeat items) is not attached to that Host yet; attach "
                f"it and re-run. zabbix_sender output: {detail}")
    finally:
        os.unlink(input_path)


def run_reporter(cfg: ReporterConfig, args) -> bool:
    if not args.legacy_blob:
        return run_reporter_rows(cfg, args)
    print(f"--- {cfg.name} ({cfg.snmp_target}:{cfg.snmp_port}) ---")
    try:
        blob = build_blob(cfg)
    except SnmpError as exc:
        print(f"ERROR: SNMP walk failed for reporter '{cfg.name}': {exc}", file=sys.stderr)
        return False

    if args.dry_run:
        print(json.dumps(blob, indent=2))
        return True

    try:
        send_via_zabbix_sender(cfg.zabbix_host, blob, args.sender_server, args.sender_port, args.sender_bin)
    except (RuntimeError, OSError) as exc:
        print(f"ERROR: failed to send blob for reporter '{cfg.name}': {exc}", file=sys.stderr)
        return False

    print(f"OK: sent blob for '{cfg.name}' — {len(blob['ports'])} ports, "
          f"{blob['stats']['resolved']}/{blob['stats']['neighbors_total']} neighbors resolved.")
    return True


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--config", help="reporters JSON file (see reporters.example.json)")
    parser.add_argument("--reporter", help="only run the reporter with this 'name' from --config")
    parser.add_argument("--name")
    parser.add_argument("--zabbix-host")
    parser.add_argument("--mgmt-ip")
    parser.add_argument("--snmp-target")
    parser.add_argument("--snmp-port", type=int, default=161)
    parser.add_argument("--snmp-community", default="public")
    parser.add_argument("--snmp-version", default="2c")
    parser.add_argument("--dry-run", action="store_true", help="print what would be sent, don't send")
    parser.add_argument("--legacy-blob", action="store_true",
                        help="send the old single blob to topology.discovery.raw instead of contract rows per role")
    parser.add_argument("--sender-server", default=os.environ.get("ZABBIX_SENDER_SERVER", "127.0.0.1"))
    parser.add_argument("--sender-port", type=int, default=int(os.environ.get("ZABBIX_SENDER_PORT", "10051")))
    parser.add_argument("--sender-bin", default=os.environ.get("ZABBIX_SENDER_BIN", ZABBIX_SENDER_BIN))
    args = parser.parse_args()

    if args.config:
        reporters = load_reporters(args.config)
        if args.reporter:
            reporters = [r for r in reporters if r.name == args.reporter]
            if not reporters:
                print(f"No reporter named '{args.reporter}' in {args.config}", file=sys.stderr)
                return 1
    elif args.name:
        reporters = [ReporterConfig(
            name=args.name, zabbix_host=args.zabbix_host or args.name, mgmt_ip=args.mgmt_ip,
            snmp_target=args.snmp_target, snmp_port=args.snmp_port,
            snmp_community=args.snmp_community, snmp_version=args.snmp_version,
        )]
    else:
        parser.error("either --config or --name/--snmp-target is required")
        return 1

    ok = True
    for cfg in reporters:
        ok = run_reporter(cfg, args) and ok

    return 0 if ok else 1


if __name__ == "__main__":
    sys.exit(main())
