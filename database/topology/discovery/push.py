#!/usr/bin/env python3
"""
push.py — topology discovery "push" component (topology-3-push-delivery.md, Trapper delivery mechanism).

Runs near a reporter/segment: walks a device with net-snmp's snmpbulkwalk, decodes the walk the way the native step "SNMP walk to topology rows"
does (extract and decode only, LLD spec §4), and sends the contract rows per rule (ports, LLDP) to Zabbix trapper DISCOVERY rules with a
topology_type (template "Topology by push": topology.push.ports, topology.push.lldp), each with the time the walk finished (zabbix_sender -T),
plus the heartbeat (topology.push.heartbeat). The server stores them as topo_lld_snapshot rows, the same table the SNMP-walk LLD template fills,
so ingest.php reconciles both transports on one path. A rule whose collection failed is left out of the call (failed != empty, FR 5.d): the last
snapshot then stays. The rows are the output of the native step (src/libs/zbxpreproc/preproc_topology.c) for the same walk; step_golden/ is the
test (test_push.py).

No access to topo_nodes/topo_edges or DB here — only SNMP reach to the segment and network reach to zabbix_sender's trapper port. This component
has zero Zabbix API dependency: no auth token, no API client. The trapper items must exist on the reporter's Host (template "Topology by push").
It does NOT reimplement the trapper wire protocol (shells out to the zabbix_sender binary) nor SNMP (net-snmp CLI).

Credentials/config: never hardcoded. SNMP community strings live in the --config reporters file, which is expected to be gitignored if it holds
anything sensitive (see database/topology/discovery/reporters.example.json for the shape).

Usage:
  push.py --config reporters.json [--reporter Switch1] [--dry-run]
  push.py --name Switch1 --zabbix-host Switch1 --mgmt-ip 192.0.2.11 \\
          --snmp-target 127.0.0.1 --snmp-port 1611 --snmp-community zbxlab [--dry-run]

If zabbix_sender reports that a target item doesn't exist, the reporter's Host is missing the template "Topology by push": attach it and re-run
(a deployment mistake to fix, not something this script retries around).

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
from dataclasses import dataclass

REPO_ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__)))))
ZABBIX_SENDER_BIN = os.path.join(REPO_ROOT, "bin", "zabbix_sender")



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





class SnmpError(Exception):
    pass










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














# ---- contract rows (topology-3-push-delivery.md "`push.py`") ----
#
# A port of lld_js/common.js, ports.js and neighbors.js, the reference of the native step. It works on the same
# text the walk[] item returns, one variable per line: ".1.3.6... = TYPE: value". Identity strings (chassis ids, MACs,
# management IPs) must come out byte for byte as the step emits them; step_golden/expected/ is the test.

RULE_PORTS_KEY = "topology.push.ports"
RULE_LLDP_KEY = "topology.push.lldp"
# Heartbeat of the template "Topology by push" (topology-3-push-delivery.md "Template Topology by push").
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
    """The walk holds no variables, or no ifType: the rules cannot be told from an empty device."""


_WALK_LINE = re.compile(r"^\s*\.?([0-9][0-9.]*)\s*=\s*(?:([A-Za-z][\w-]*):\s*)?(.*)$")


def _quote_open(value: str) -> bool:
    """A quoted STRING value whose closing quote is not on this line: the value goes on in the next line (a raw line feed inside the quotes)."""
    v = value.lstrip()
    if not v.startswith('"'):
        return False
    i = 1
    while i < len(v):
        if v[i] == "\\":
            i += 2
            continue
        if v[i] == '"':
            return False
        i += 1
    return True


def parse_walk(text: str) -> dict[str, tuple[str, str]]:
    """{oid (no leading dot): (type, raw)}; lines that are not a variable are ignored. A quoted value with a raw line feed inside is ONE value
    (the `walk[]` text, topology-2-lld-delivery.md §4.1): the lines up to the closing quote belong to it."""
    rows: dict[str, tuple[str, str]] = {}
    key = None
    for line in str(text).split("\n"):
        if key is not None:
            row_type, raw = rows[key]
            if _quote_open(raw):
                rows[key] = (row_type, raw + "\n" + line)
                continue
        m = _WALK_LINE.match(line)
        if m is None or re.match(r"^No Such (Object|Instance)", m.group(3)) or "No more variables" in line:
            key = None
            continue
        key = m.group(1)
        rows[key] = (m.group(2) or "STRING", m.group(3))
    return rows


# --- decoding (topology-6-normalization.md §3): the only thing the step and push.py do to a value; the same rules as preproc_topology.c ---
def _hex_bytes(raw: str) -> bytes:
    return bytes(int(o, 16) for o in re.split(r"[\s:]+", raw.strip()) if o)


def _hex_form(raw: bytes) -> str:
    """The hex form: lower case pairs separated by ':'."""
    return ":".join("%02x" % b for b in raw)


def _has_control(text: str) -> bool:
    return any(c == "\x7f" or (c < " " and c != "\t") or "\x80" <= c <= "\x9f" for c in text)


def text_or_hex(raw: bytes) -> str:
    """A text column from a Hex-STRING: trailing NUL bytes removed; the rest is text when it is valid UTF-8 without a control character other than tab,
    else the hex form."""
    raw = raw.rstrip(b"\0")
    try:
        text = raw.decode("utf-8")
    except UnicodeDecodeError:
        return _hex_form(raw)
    return _hex_form(raw) if _has_control(text) else text


def _unquote(raw: str) -> str:
    """A STRING value as the walk[] text has it: surrounding quotes removed, `\\X` unescaped once (a raw line feed stays); no trim inside."""
    raw = raw.strip()
    if len(raw) > 1 and raw[0] == '"' and raw[-1] == '"':
        return re.sub(r"\\(.)", r"\1", raw[1:-1], flags=re.S)
    return raw


def _text(row: tuple[str, str]) -> str:
    """A text column: a STRING as it is, a Hex-STRING as text or the hex form."""
    return text_or_hex(_hex_bytes(row[1])) if row[0] == "Hex-STRING" else _unquote(row[1])


def _binary(row: tuple[str, str]) -> str:
    """A binary column (macAddress and networkAddress ids, ifPhysAddress): the hex form; a STRING is passed as text."""
    return _hex_form(_hex_bytes(row[1])) if row[0] == "Hex-STRING" else _unquote(row[1])


def _decode(row: tuple[str, str]) -> str:
    """A number or enum column: Hex-STRING as colon hex, quotes stripped, "name(3)" -> "3"."""
    row_type, raw = row[0], row[1].strip()
    if row_type == "Hex-STRING":
        return ":".join(o.lower() for o in raw.split())
    if len(raw) > 1 and raw[0] == '"' and raw[-1] == '"':
        return raw[1:-1].strip()
    if row_type in ("INTEGER", "Gauge32", "Counter32", "Unsigned32"):
        m = re.search(r"\((-?[0-9]+)\)\s*$", raw)
        if m:
            raw = m.group(1)
    return raw


def _id(row: tuple[str, str], subtype, chassis: bool) -> str:
    """A chassis (chassis=True) or port id by its subtype: macAddress and networkAddress are binary, the other subtypes are text; without a subtype the
    type of the value decides."""
    if not subtype:
        binary = row[0] == "Hex-STRING"
    elif chassis:
        binary = subtype in (4, 5)
    else:
        binary = subtype in (3, 4)
    return _binary(row) if binary else _text(row)


def _table(rows: dict, base: str) -> dict[str, tuple[str, str]]:
    prefix = base + "."
    return {oid[len(prefix):]: row for oid, row in rows.items() if oid.startswith(prefix)}


def _numeric_keys(obj: dict) -> list[int]:
    return sorted(int(k) for k in obj if re.match(r"^[0-9]+$", k))


def _parse_int(text: str):
    m = re.match(r"^\s*([+-]?[0-9]+)", text)
    return int(m.group(1)) if m else None


def _local_chassis(rows: dict) -> tuple[str, str]:
    """lldpLocChassisId only, with its subtype name; never guessed from a port MAC (model §6.4 step 7)."""
    row = rows.get(LLDP_LOC_CHASSIS_ID_FULL)
    if row is None:
        return "", ""
    subtype_row = rows.get("1.0.8802.1.1.2.1.3.1.0")
    subtype = (_parse_int(_decode(subtype_row)) if subtype_row is not None else None) or 0
    value = _id(row, subtype, True)
    return value, (LLDP_CHASSIS_ID_SUBTYPES.get(subtype, "") if value != "" else "")


def _put(row: dict, key: str, value) -> None:
    """An absent value is omitted, not written as an empty string (model §5.3)."""
    if value is not None and value != "":
        row[key] = value


def _with_chassis(row: dict, chassis: tuple[str, str]) -> dict:
    if chassis[0] != "":
        row["{#LOC_CHASSIS}"] = chassis[0]
        _put(row, "{#LOC_CHASSIS_TYPE}", chassis[1])
    return row


def rows_ports(rows: dict) -> list[dict]:
    """PORTS: one row per interface. Fails (WalkUnusable) when there is no ifType, like the step's `error` anchor. The names are the walk's (ifName and
    ifDescr apart; ingest chooses)."""
    if not _table(rows, IF_TYPE_BASE):
        raise WalkUnusable("the walk holds no ifType (no IF-MIB)")
    base = "1.3.6.1.2.1.2.2.1"
    indexes, descr, phys = _table(rows, base + ".1"), _table(rows, base + ".2"), _table(rows, base + ".6")
    names = _table(rows, "1.3.6.1.2.1.31.1.1.1.1")
    types, admin, oper = _table(rows, IF_TYPE_BASE), _table(rows, "1.3.6.1.2.1.2.2.1.7"), _table(rows, "1.3.6.1.2.1.2.2.1.8")
    speeds = _table(rows, "1.3.6.1.2.1.31.1.1.1.15")
    chassis = _local_chassis(rows)
    out = []
    for key in _numeric_keys(indexes):
        k = str(key)
        row = {"{#IFINDEX}": k}
        _put(row, "{#IFNAME}", _text(names[k]) if k in names else "")
        _put(row, "{#IFDESCR}", _text(descr[k]) if k in descr else "")
        if k in types:
            _put(row, "{#IFTYPE}", _decode(types[k]))
        if k in phys:
            _put(row, "{#IFMAC}", _binary(phys[k]))
        if k in admin:
            _put(row, "{#IFADMINSTATUS}", _decode(admin[k]))
        if k in oper:
            _put(row, "{#IFOPERSTATUS}", _decode(oper[k]))
        if k in speeds:
            _put(row, "{#IFSPEED}", _decode(speeds[k]))      # ifHighSpeed, Mbit/s
        out.append(_with_chassis(row, chassis))
    return out


def _suffix_key(text: str):
    return tuple(int(x) if x.isdigit() else -1 for x in text.split("."))


def rows_lldp(rows: dict) -> list[dict]:
    """NEIGHBORS from LLDP: one row per lldpRemTable entry; [] when the table is empty or absent. The local port is NOT resolved here (model §6.7.6): the row
    carries the LocalPortNum and the local port id, subtype and description; every lldpRemManAddrTable entry of the neighbor is emitted raw. Nothing is dropped."""
    chassis = _local_chassis(rows)
    rem, loc = "1.0.8802.1.1.2.1.4.1.1", "1.0.8802.1.1.2.1.3.7.1"
    rem_chassis_type, rem_chassis = _table(rows, rem + ".4"), _table(rows, rem + ".5")
    rem_port_type, rem_port = _table(rows, rem + ".6"), _table(rows, rem + ".7")
    rem_port_desc, rem_sysname = _table(rows, rem + ".8"), _table(rows, rem + ".9")
    loc_port_type, loc_port_id, loc_port_desc = _table(rows, loc + ".2"), _table(rows, loc + ".3"), _table(rows, loc + ".4")
    man_addr = _table(rows, "1.0.8802.1.1.2.1.4.2.1.3")

    suffixes = set()
    for col in (rem_chassis, rem_port, rem_port_desc, rem_sysname, rem_chassis_type, rem_port_type):
        suffixes.update(col)

    def man_addrs(suffix: str) -> str:
        """The entries of this neighbor, any TimeMark: `lldp:<family>.[<length>.]<address octets>`, ordered by that part, then by the whole index."""
        pair = suffix.split(".", 1)[1] + "."
        hits = []
        for key in man_addr:
            tail = key.split(".", 1)[1] if "." in key else ""
            if tail.startswith(pair):
                hits.append((_suffix_key(tail[len(pair):]), _suffix_key(key), tail[len(pair):]))
        return " ".join("lldp:" + h[2] for h in sorted(hits))

    out = []
    for sfx in sorted(suffixes, key=lambda x: (_suffix_key(x)[1:2], _suffix_key(x))):
        parts = sfx.split(".")
        if len(parts) < 3:
            continue                                               # not a TimeMark.LocalPortNum.RemIndex index
        chassis_type = _parse_int(_decode(rem_chassis_type[sfx])) if sfx in rem_chassis_type else 0
        port_type = _parse_int(_decode(rem_port_type[sfx])) if sfx in rem_port_type else 0
        num = parts[1]
        row = {}
        if num.isdigit() and not (num.startswith("0") and len(num) > 1):
            row["{#LOC_PORT_NUM}"] = num
        loc_type = (_parse_int(_decode(loc_port_type[num])) if num in loc_port_type else 0) or 0
        if num in loc_port_id:
            _put(row, "{#LOC_PORT_ID}", _id(loc_port_id[num], loc_type, False))
            _put(row, "{#LOC_PORT_ID_TYPE}", LLDP_PORT_ID_SUBTYPES.get(loc_type, ""))
        _put(row, "{#LOC_PORT_DESC}", _text(loc_port_desc[num]) if num in loc_port_desc else "")
        row["{#SOURCE}"] = "lldp"
        if sfx in rem_chassis:
            _put(row, "{#REM_CHASSIS}", _id(rem_chassis[sfx], chassis_type, True))
            _put(row, "{#REM_CHASSIS_TYPE}", LLDP_CHASSIS_ID_SUBTYPES.get(chassis_type, ""))
        _put(row, "{#REM_MAN_ADDRS}", man_addrs(sfx))
        _put(row, "{#REM_SYSNAME}", _text(rem_sysname[sfx]) if sfx in rem_sysname else "")
        if sfx in rem_port:
            _put(row, "{#REM_PORT}", _id(rem_port[sfx], port_type, False))
            _put(row, "{#REM_PORT_TYPE}", LLDP_PORT_ID_SUBTYPES.get(port_type, ""))
        _put(row, "{#REM_PORT_DESC}", _text(rem_port_desc[sfx]) if sfx in rem_port_desc else "")
        out.append(_with_chassis(row, chassis))
    return out


# What each rule needs from the walk. A rule is sent only when every OID it reads was walked completely.
RULE_OIDS = {
    RULE_PORTS_KEY: ["1.3.6.1.2.1.2.2.1", "1.3.6.1.2.1.31.1.1.1.1", "1.3.6.1.2.1.31.1.1.1.15", "1.0.8802.1.1.2.1.3.2"],
    RULE_LLDP_KEY: ["1.3.6.1.2.1.2.2.1", "1.3.6.1.2.1.31.1.1.1.1", "1.0.8802.1.1.2.1.3.2", "1.0.8802.1.1.2.1.3.7",
                    "1.0.8802.1.1.2.1.4.1", "1.0.8802.1.1.2.1.4.2"],
}


def build_rule_values(walk_text: str, failed_oids: dict[str, str] | None = None) -> tuple[dict[str, str], list[str]]:
    """{trapper key: JSON array} for every rule that was collected, and the problems of the rules that were not.
    A rule that failed is absent (failed != empty, FR 5.d); an empty table gives "[]". `failed_oids` are the walks that
    did not complete (timeout, truncated, non-increasing OID, early endOfMibView): a rule that reads one of them is not
    sent, the other rules are."""
    failed_oids = failed_oids or {}
    rows = parse_walk(walk_text)
    if not rows and not failed_oids:
        raise WalkUnusable("the SNMP walk holds no variables (device unreachable or wrong OIDs)")
    values, problems = {}, []
    for key, builder in ((RULE_PORTS_KEY, rows_ports), (RULE_LLDP_KEY, rows_lldp)):
        broken = [f"{oid}: {failed_oids[oid]}" for oid in RULE_OIDS[key] if oid in failed_oids]
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
    """A walk that ended early is a failure, not the end of the table (topology-3-push-delivery.md "Design rules": failed is not empty):
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




def send_rule_values(zabbix_host: str, values: dict[str, str], collected_at: int, heartbeat_at: int, server: str,
                     port: int, sender_bin: str) -> None:
    """One zabbix_sender call: every collected rule with the time the walk finished (-T), and the heartbeat."""
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
    """The default transport: contract rows per rule. Nothing is sent for a rule that could not be collected."""
    print(f"--- {cfg.name} ({cfg.snmp_target}:{cfg.snmp_port}) ---")
    values: dict[str, str] = {}
    ok = True
    collected_at = int(time.time())
    try:
        text, failed_oids = collect_walk(cfg.snmp_target, cfg.snmp_port, cfg.snmp_community, cfg.snmp_version)
        collected_at = int(time.time())                       # the time the walk finished
        values, problems = build_rule_values(text, failed_oids)
        for problem in problems:
            print(f"ERROR: nothing sent for {problem}", file=sys.stderr)
            ok = False
    except (SnmpError, WalkUnusable) as exc:
        print(f"ERROR: nothing sent for any rule of reporter '{cfg.name}': {exc}", file=sys.stderr)
        ok = False

    if args.dry_run:
        for key, value in values.items():
            print(f"{key} @{collected_at}: {value}")
        return ok

    try:
        send_rule_values(cfg.zabbix_host, values, collected_at, int(time.time()), args.sender_server,
                         args.sender_port, args.sender_bin)
    except (RuntimeError, OSError) as exc:
        print(f"ERROR: failed to send for reporter '{cfg.name}': {exc}", file=sys.stderr)
        return False

    state = 'OK' if ok else ('PARTIAL' if values else 'FAILED')
    print(f"{state}: sent {len(values)} rule(s) for '{cfg.name}': {', '.join(values) or '-'} (+ heartbeat).")
    return ok


# ---- zabbix_sender ----



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
        ok = run_reporter_rows(cfg, args) and ok

    return 0 if ok else 1


if __name__ == "__main__":
    sys.exit(main())
