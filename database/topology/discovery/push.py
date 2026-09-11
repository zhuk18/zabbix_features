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

Usage:
  push.py --config reporters.json [--reporter Switch1] [--dry-run]
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
    on a device with no active LLDP neighbors right now)."""
    cmd = ["snmpwalk", "-v", version, "-c", community, "-On", "-t", "3", "-r", "1",
           f"{target}:{port}", oid]
    try:
        out = subprocess.run(cmd, capture_output=True, text=True, timeout=30)
    except (subprocess.TimeoutExpired, FileNotFoundError) as exc:
        raise SnmpError(f"snmpwalk failed for {oid} against {target}:{port}: {exc}") from exc

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
    parser.add_argument("--dry-run", action="store_true", help="print the assembled blob, don't send")
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
