#!/usr/bin/env python3
"""corpus_identity.py — task 055 section 2: the chassis of the neighbor rows of the vendor corpus (read only; nothing is written but the JSON in out/, which git ignores).

For every walk of the corpus and each neighbor source (LLDP, CDP) the native step makes the rows (corpus_walk.py, step_golden/topo_step). Each row is classified by its
chassis: a MAC chassis; a chassis of another subtype (per subtype); no usable chassis (empty, placeholder MAC, not a MAC although typed as one; a CDP row has only the
device id). Rows without a MAC chassis are counted by what else they carry: a management address, a sysname, both, none. A row without a chassis of any kind is also
counted separately. Per source and per OS family (the file name up to the first underscore) when the family has 10 or more such rows.

  corpus_identity.py [--corpus DIR] [--topo-step PATH] [--out out/identity.json]
"""
import argparse
import collections
import glob
import json
import os
import re
import sys
import tempfile

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, HERE)
sys.path.insert(0, os.path.join(HERE, "..", "discovery"))
import corpus_run  # noqa: E402
import corpus_walk  # noqa: E402

MAC = re.compile(r"^([0-9a-f]{2}[:-]){5}[0-9a-f]{2}$", re.I)


def decode_addresses(entries):
    """The addresses of a {#REM_MAN_ADDRS} value (normalization §4.4, the same rules as CTopologyAddress::decodeEntries): lldp:<family>.[<length>.]<octets> and
    cdp:<type>:<hex octets>; unusable entries are left out. Returns ipaddress objects."""
    import ipaddress
    found = []
    for entry in (entries or "").split():
        octets = None
        family = None
        if entry.startswith("lldp:"):
            parts = entry[5:].split(".")
            if not all(re.fullmatch(r"[0-9]{1,3}", n) and int(n) <= 255 for n in parts):
                continue
            nums = [int(n) for n in parts]
            family = nums.pop(0)
            length = {1: 4, 2: 16}.get(family)
            if length is None:
                continue
            if len(nums) == length:
                octets = nums
            elif len(nums) > length and nums[0] == len(nums) - 1 and (nums[0] == length or (family == 1 and nums[0] == 5)):
                octets = nums[1:1 + length]
            else:
                continue
        elif entry.startswith("cdp:"):
            parts = entry[4:].split(":")
            if parts[0] != "1" or len(parts) != 5 or not all(re.fullmatch(r"[0-9a-f]{2}", x, re.I) for x in parts[1:]):
                continue
            octets = [int(x, 16) for x in parts[1:]]
            family = 1
        else:
            continue
        found.append(ipaddress.ip_address(bytes(octets)))
    return found


def usable_address(addresses):
    """a decoded address that is not unspecified or loopback (the section Addresses does not show those)"""
    return any(not (a.is_unspecified or a.is_loopback) for a in addresses)


def chassis_class(row):
    """('mac' | 'other:<subtype>' | 'none:<reason>')"""
    value = row.get("{#REM_CHASSIS}") or ""
    ctype = row.get("{#REM_CHASSIS_TYPE}") or ""
    if value == "":
        return "none:empty"
    if ctype in ("macAddress", "") and MAC.match(value):
        if value.lower().replace("-", ":") in ("00:00:00:00:00:00", "ff:ff:ff:ff:ff:ff"):
            return "none:placeholder_mac"
        return "mac"
    if ctype == "macAddress":
        return "none:not_a_mac"
    return "other:" + (ctype or "untyped")


def same_port_analysis(rows):
    """Part B (draft reconcile-as-function.md): a claim without a chassis joins the claim with a chassis on the same local port of the same reporter (LLDP and CDP of
    one box). In the corpus a walk is one reporter. A CDP row is matched to an LLDP row of the walk by the port name (ifName / lldpLocPortId / lldpLocPortDesc, lower case)
    or by lldpLocPortNum = ifIndex. Returns counts for the CDP rows and for the LLDP rows without a usable chassis."""
    by_walk = collections.defaultdict(list)
    for r in rows:
        by_walk[r["file"]].append(r)
    out = collections.Counter()
    for walk, rs in by_walk.items():
        lldp = [r for r in rs if r["source"] == "lldp"]
        has_chassis = [r for r in lldp if r["cls"] == "mac" or r["cls"].startswith("other:")]
        names = set()
        nums = set()
        for r in has_chassis:
            names.update(x for x in r["port"][1:3] if x)
            nums.add(r["port"][3])
        # the ports on which the LLDP side has a chassis claim
        for r in rs:
            if r["source"] != "cdp":
                continue
            out["cdp_rows"] += 1
            ifname, ifindex = r["port"][0], r["port"][4]
            if (ifname and ifname in names) or (ifindex and ifindex in nums):
                out["cdp_joins_a_chassis_on_the_same_port"] += 1
            else:
                out["cdp_alone_on_its_port"] += 1
        for r in lldp:
            if r["cls"].startswith("none:"):
                out["lldp_no_chassis_rows"] += 1
    return out


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--corpus", default=corpus_walk.CORPUS)
    ap.add_argument("--topo-step", default=os.path.join(HERE, "..", "discovery", "step_golden", "topo_step"))
    ap.add_argument("--out", default=os.path.join(HERE, "out", "identity.json"))
    a = ap.parse_args()
    files = sorted(glob.glob(os.path.join(a.corpus, "librenms", "*.snmprec")))
    rows = []     # {file, family, source, cls, mgmt, sysname}
    errors = collections.Counter()
    walks = collections.Counter()
    for path in files:
        name = os.path.basename(path)[:-len(".snmprec")]
        family = name.split("_")[0]
        walk = tempfile.mktemp(suffix=".walk")
        with open(walk, "w", encoding="utf-8", errors="surrogateescape") as fh:
            fh.write(corpus_walk.convert_file(path))
        for source in ("lldp", "cdp"):
            got, err = corpus_run.run_native(a.topo_step, walk, source)
            if err:
                errors[(source, err[:60])] += 1
                continue
            walks[source] += 1
            for row in got:
                rows.append({"port": [str(row.get("{#IFNAME}", "")).lower(), str(row.get("{#LOC_PORT_ID}", "")).lower(), str(row.get("{#LOC_PORT_DESC}", "")).lower(),
                                      str(row.get("{#LOC_PORT_NUM}", "")), str(row.get("{#IFINDEX}", ""))], "file": name, "family": family, "source": row.get("{#SOURCE}", source), "cls": chassis_class(row),
                             "mgmt": bool(decode_addresses(row.get("{#REM_MAN_ADDRS}"))), "mgmt_usable": usable_address(decode_addresses(row.get("{#REM_MAN_ADDRS}"))),
                             "sysname": bool(row.get("{#REM_SYSNAME}"))})
        os.unlink(walk)
    os.makedirs(os.path.dirname(a.out), exist_ok=True)
    json.dump({"rows": rows, "walks": walks, "errors": {"%s: %s" % k: v for k, v in errors.items()}}, open(a.out, "w"))

    print("walks with rows: %s; skipped: %s" % (dict(walks), dict(errors)))
    for source in ("lldp", "cdp"):
        mine = [r for r in rows if r["source"] == source]
        by = collections.Counter(("mac" if r["cls"] == "mac" else r["cls"]) for r in mine)
        print("\n== %s: %d rows in %d walks" % (source.upper(), len(mine), len({r['file'] for r in mine})))
        for cls, n in sorted(by.items(), key=lambda kv: (-kv[1], kv[0])):
            print("  %-28s %5d  %5.1f%%" % (cls, n, 100.0 * n / len(mine)))
        nomac = [r for r in mine if r["cls"] != "mac"]
        print("  rows without a MAC chassis: %d: mgmt+sysname %d, mgmt only %d, sysname only %d, none %d (of the mgmt ones, not unspecified/loopback: %d)" % (
            len(nomac), sum(r["mgmt"] and r["sysname"] for r in nomac), sum(r["mgmt"] and not r["sysname"] for r in nomac),
            sum(r["sysname"] and not r["mgmt"] for r in nomac), sum(not r["mgmt"] and not r["sysname"] for r in nomac), sum(r["mgmt_usable"] for r in nomac)))
        nochassis = [r for r in mine if r["cls"].startswith("none:")]
        print("  rows with no usable chassis (empty, placeholder, not a MAC): %d: mgmt+sysname %d, mgmt only %d, sysname only %d, none %d" % (
            len(nochassis), sum(r["mgmt"] and r["sysname"] for r in nochassis), sum(r["mgmt"] and not r["sysname"] for r in nochassis),
            sum(r["sysname"] and not r["mgmt"] for r in nochassis), sum(not r["mgmt"] and not r["sysname"] for r in nochassis)))
        fam = collections.defaultdict(list)
        for r in nomac:
            fam[r["family"]].append(r)
        big = {f: v for f, v in fam.items() if len(v) >= 10}
        if big:
            print("  per OS family (families with 10 or more rows without a MAC chassis):")
            for f, v in sorted(big.items(), key=lambda kv: -len(kv[1])):
                c = collections.Counter(r["cls"] for r in v)
                print("    %-14s %4d rows in %d walks: %s; mgmt %d, sysname %d, both %d, none %d" % (f, len(v), len({r['file'] for r in v}), dict(c), sum(r['mgmt'] for r in v), sum(r['sysname'] for r in v),
                      sum(r['mgmt'] and r['sysname'] for r in v), sum(not r['mgmt'] and not r['sysname'] for r in v)))
        macfam = collections.Counter(r["family"] for r in mine if r["cls"] == "mac")
        print("  MAC-chassis rows by family (top): %s" % macfam.most_common(8))
    report_same_port(rows)


def report_same_port(rows):
    c = same_port_analysis(rows)
    print("\n== Part B, claims without a chassis that join a chassis on the same port (CDP rows; a walk is one reporter)")
    print("  CDP rows %d: join an LLDP chassis claim on the same port %d, alone on their port %d" % (c["cdp_rows"], c["cdp_joins_a_chassis_on_the_same_port"], c["cdp_alone_on_its_port"]))
    print("  LLDP rows without a usable chassis: %d (each would be a Device of its reporter and port)" % c["lldp_no_chassis_rows"])


if __name__ == "__main__":
    main()
