#!/usr/bin/env python3
"""corpus_f1.py — the local port rule (LLD spec §4.4 `lldp`, steps 1-4) and the management address rule (F6) as an independent reference, and the native
step / push.py measured against it on the walks with ifIndex added (handoff 024). Reads out/walks-ifindex/*.walk; writes out/f1.json.
Nothing of the corpus is committed. This file is the oracle: the native step and push.py are the implementations under test.

  corpus_f1.py [--topo-step PATH] [--tag before|after] [file-name-fragment ...]

For every lldpRemTable row: the local port the oracle resolves (the step that did it, 1/2/3, or the drop reason), the ifIndex the native step and
push.py gave, and for step-3 rows a second judgement where the walk allows it (macAddress port id against a unique ifPhysAddress; numeric port id against
an ifIndex whose ifName equals lldpLocPortDesc).
"""
import argparse
import glob
import json
import os
import re
import sys

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, HERE)
sys.path.insert(0, os.path.join(HERE, "..", "discovery"))
import corpus_evidence as ev  # noqa: E402
import corpus_run  # noqa: E402
import corpus_walk  # noqa: E402
import push  # noqa: E402

IFPHYS = ".1.3.6.1.2.1.2.2.1.6."
IFINDEX = ".1.3.6.1.2.1.2.2.1.1."
LOCSUB = LOC_SUBTYPE = ev.LOC + "2."


def norm_mac(text):
    t = text.strip().lower()
    m = re.match(r"^([0-9a-f]{1,2})[:-]([0-9a-f]{1,2})[:-]([0-9a-f]{1,2})[:-]([0-9a-f]{1,2})[:-]([0-9a-f]{1,2})[:-]([0-9a-f]{1,2})$", t)
    if m:
        return ":".join(g.zfill(2) for g in m.groups())
    m = re.match(r"^([0-9a-f]{4})\.([0-9a-f]{4})\.([0-9a-f]{4})$", t)
    if m:
        h = "".join(m.groups())
        return ":".join(h[i:i + 2] for i in range(0, 12, 2))
    return t


def hex_mac(cells, oid):
    typ, val = cells[oid]
    if typ == "Hex-STRING":
        octets = val.split()
        return ":".join(o.lower().zfill(2) for o in octets) if len(octets) == 6 else ""
    return norm_mac(ev.decode(typ, val))


def loc_text(cells, base, local_num, subtype=None):
    """lldpLocPortId / lldpLocPortDesc of a LocalPortNum as the walk gives it: a Hex-STRING of a macAddress id is colon hex, any other value is text."""
    cell = cells.get(base + local_num)
    if cell is None:
        return ""
    typ, val = cell
    if typ == "Hex-STRING" and subtype == 3:
        return ":".join(o.lower().zfill(2) for o in val.split())
    return ev.decode(typ, val)


def port_view(cells):
    ifindexes = {oid[len(IFINDEX):] for oid in cells if oid.startswith(IFINDEX)}
    names, descrs = ev.column(cells, ev.IFNAME), ev.column(cells, ev.IFDESCR)
    macs = {}
    for oid in cells:
        if oid.startswith(IFPHYS):
            m = hex_mac(cells, oid)
            if m:
                macs[oid[len(IFPHYS):]] = m
    names = {k: v for k, v in names.items() if k in ifindexes and v != ""}
    descrs = {k: v for k, v in descrs.items() if k in ifindexes and v != ""}
    return ifindexes, names, descrs, macs


def resolve(cells, local_num, id_major=True):
    """(step, ifindex) or (None, reason) for one LocalPortNum."""
    ifindexes, names, descrs, macs = port_view(cells)
    sub = ev.column(cells, LOC_SUBTYPE).get(local_num)
    try:
        st = int(sub) if sub is not None else 0
    except ValueError:
        st = 0
    pid = loc_text(cells, ev.LOC + "3.", local_num, st)
    pdesc = loc_text(cells, ev.LOC + "4.", local_num)
    if pid:
        if st == 5:
            m = [i for i, n in names.items() if n == pid]
            if len(m) == 1:
                return 1, m[0]
            if len(m) > 1:
                return None, "name on several ports"
        elif st == 3:
            m = [i for i, v in macs.items() if v == norm_mac(pid)]
            if len(m) == 1:
                return 1, m[0]
        elif st == 7:
            if pid in ifindexes and re.match(r"^[0-9]+$", pid):
                return 1, pid
    tries = [(t, f) for t in (pid, pdesc) for f in (names, descrs)] if id_major else [(t, f) for f in (names, descrs) for t in (pid, pdesc)]
    for text, field in tries:
        if not text:
            continue
        m = [i for i, n in field.items() if n == text]
        if len(m) == 1:
            return 2, m[0]
        if len(m) > 1:
            return None, "name on several ports"
    if local_num in ifindexes:
        return 3, local_num
    return None, "ifIndex missing"


def judge_step3(cells, local_num, ifindex):
    """A second opinion on a step-3 row: 'agree' / 'disagree' / 'cannot'."""
    ifindexes, names, descrs, macs = port_view(cells)
    sub = ev.column(cells, LOC_SUBTYPE).get(local_num)
    pid = loc_text(cells, ev.LOC + "3.", local_num, 3 if sub in ("3", None) else 0)
    pdesc = loc_text(cells, ev.LOC + "4.", local_num)
    # the corpus mostly lacks lldpLocPortIdSubtype: a MAC-shaped id counts as macAddress for this second opinion (the rule itself does not infer it)
    if (sub == "3" or sub is None) and re.match(r"^([0-9a-f]{2}:){5}[0-9a-f]{2}$", norm_mac(pid or "")) and pid:
        m = [i for i, v in macs.items() if v == norm_mac(pid)]
        if len(m) == 1:
            return "agree" if m[0] == ifindex else "disagree"
    if re.match(r"^[0-9]+$", pid or "") and pid in ifindexes and pdesc and (names.get(pid) == pdesc):
        return "agree" if pid == ifindex else "disagree"
    return "cannot"


# ---- management address (F6) ----
MAN = ".1.0.8802.1.1.2.1.4.2.1.3."


def man_forms(cells):
    """{remote suffix: [(form, ip or None)]} from the lldpRemManAddrTable index TimeMark.LocalPortNum.RemIndex.family.[length.]address."""
    out = {}
    for oid in cells:
        if not oid.startswith(MAN):
            continue
        parts = oid[len(MAN):].split(".")
        if len(parts) < 4:
            continue
        suffix, rest = ".".join(parts[:3]), [int(x) for x in parts[3:]]
        fam, body = rest[0], rest[1:]
        form, ip = "other", None
        if fam == 1:
            if len(body) == 5 and body[0] == 4:
                form, ip = "ipv4 with length", ".".join(map(str, body[1:5]))
            elif len(body) == 4:
                form, ip = "ipv4 without length", ".".join(map(str, body))
            elif len(body) == 6 and body[0] == 5:
                form, ip = "ipv4 length 5 (extra octet)", ".".join(map(str, body[1:5]))
        elif fam == 2:
            if (len(body) == 17 and body[0] == 16) or len(body) == 16:
                raw = body[1:] if len(body) == 17 else body
                import ipaddress
                form, ip = "ipv6 with length" if len(body) == 17 else "ipv6 without length", str(ipaddress.IPv6Address(bytes(raw)))
        elif fam == 6:
            form = "family 6 (802 address)"
        out.setdefault(suffix, []).append((form, ip, tuple(rest)))
    return out


def man_ip(forms):
    """Oracle: the first IPv4 by index order, else the first IPv6."""
    ordered = sorted(forms, key=lambda f: f[2])
    for form, ip, _ in ordered:
        if ip and "." in ip and ":" not in ip:
            return ip
    for form, ip, _ in ordered:
        if ip:
            return ip
    return None


def records(text):
    """The walk text as records: a line that starts a variable plus the continuation lines of a value with a line feed."""
    recs, cur = [], None
    for line in text.split("\n"):
        if re.match(r"^\.[0-9][0-9.]* = ", line):
            cur = [line]
            recs.append(cur)
        elif cur is not None:
            cur.append(line)
    return ["\n".join(r) for r in recs]


def native_row(topo_step, recs, suffix, tmp):
    """The native step on the walk reduced to ONE remote index (its rows come out sorted by ifIndex, not by index, so they cannot be paired by position)."""
    keep = []
    for rec in recs:
        oid = rec.split(" = ", 1)[0]
        if oid.startswith(ev.REM):
            if oid[len(ev.REM):].split(".", 1)[1] == suffix:
                keep.append(rec)
        elif oid.startswith(MAN):
            if oid[len(MAN):].startswith(suffix + "."):
                keep.append(rec)
        else:
            keep.append(rec)
    with open(tmp, "w", encoding="utf-8", errors="surrogateescape") as fh:
        fh.write("\n".join(keep) + "\n")
    rows, err = corpus_run.run_native(topo_step, tmp, "lldp")
    return (rows[0] if rows and len(rows) == 1 else None), err


def analyse(name, walk, topo_step, id_major=True):
    cells = ev.read_walk(walk)
    suffixes = sorted({oid[len(ev.REM + c + "."):] for c in "456789" for oid in cells if oid.startswith(ev.REM + c + ".")})
    suffixes = [s for s in suffixes if s.count(".") >= 2]
    text = open(walk, encoding="utf-8", errors="surrogateescape").read()
    recs = records(text)
    out = {"rows": [], "error": None}
    rows, err = corpus_run.run_native(topo_step, walk, "lldp")
    if rows is None:
        out["error"] = err
        return out
    try:
        pushed = push.rows_lldp(push.parse_walk(text))
    except Exception as e:  # noqa: BLE001
        out["error"] = "push: %s" % e
        return out
    if len(rows) != len(suffixes) or len(pushed) != len(suffixes):
        out["error"] = "rows and indexes do not pair (%d native, %d push, %d indexes)" % (len(rows), len(pushed), len(suffixes))
        return out
    forms = man_forms(cells)
    tmp = os.path.join(corpus_walk.OUT, "one-row.walk")
    for suffix, prow in zip(suffixes, pushed):
        local_num = suffix.split(".")[1]
        nrow, nerr = native_row(topo_step, recs, suffix, tmp)
        if nrow is None:
            out["error"] = "the native step on the reduced walk of %s: %s" % (suffix, nerr or "no single row")
            return out
        step, res = resolve(cells, local_num, id_major)
        other = resolve(cells, local_num, not id_major)
        entry = {"suffix": suffix, "local_port_num": local_num, "step": step, "oracle": res if step else None, "drop": None if step else res,
                 "order_matters": other != (step, res),
                 "native": nrow.get("{#IFINDEX}"), "push": prow.get("{#IFINDEX}"),
                 "native_ip": nrow.get("{#REM_MGMT_IP}"), "push_ip": prow.get("{#REM_MGMT_IP}"),
                 "oracle_ip": man_ip(forms.get(suffix, [])), "man_forms": [f for f, _, _ in forms.get(suffix, [])]}
        if step == 3:
            entry["judge"] = judge_step3(cells, local_num, res)
        out["rows"].append(entry)
    return out


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--topo-step", default=os.path.join(HERE, "..", "discovery", "step_golden", "topo_step"))
    ap.add_argument("--tag", default="")
    ap.add_argument("only", nargs="*")
    a = ap.parse_args()
    result = {}
    for walk in sorted(glob.glob(os.path.join(corpus_walk.OUT, "walks-ifindex", "*.walk"))):
        name = os.path.basename(walk)[:-5]
        if a.only and not any(o in name for o in a.only):
            continue
        result[name] = analyse(name, walk, a.topo_step)
    path = os.path.join(corpus_walk.OUT, "f1%s.json" % ("-" + a.tag if a.tag else ""))
    with open(path, "w", encoding="utf-8") as fh:
        json.dump(result, fh, indent=1, ensure_ascii=False)
    rows = [r for f in result.values() for r in f["rows"]]
    steps = {s: sum(1 for r in rows if r["step"] == s) for s in (1, 2, 3)}
    drops = {}
    for r in rows:
        if r["drop"]:
            drops[r["drop"]] = drops.get(r["drop"], 0) + 1
    same = lambda key: sum(1 for r in rows if (r[key] == r["oracle"]) or (r[key] is None and r["oracle"] is None))  # noqa: E731
    print("%d rows; oracle: steps %s, dropped %s; order of step 2 matters in %d rows" % (len(rows), steps, drops, sum(1 for r in rows if r["order_matters"])))
    print("native agrees with the oracle on %d rows, push.py on %d" % (same("native"), same("push")))
    j = {k: sum(1 for r in rows if r.get("judge") == k) for k in ("agree", "disagree", "cannot")}
    print("step-3 rows, second judgement:", j)
    print("errors:", {n: f["error"] for n, f in result.items() if f["error"]})
    ips = [r for r in rows if r["man_forms"]]
    print("manaddr rows: %d; oracle ip %d, native ip %d, push ip %d" % (len(ips), sum(1 for r in ips if r["oracle_ip"]),
                                                                       sum(1 for r in ips if r["native_ip"]), sum(1 for r in ips if r["push_ip"])))


if __name__ == "__main__":
    main()
