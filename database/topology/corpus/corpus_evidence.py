#!/usr/bin/env python3
"""corpus_evidence.py — evidence for findings F1 and F6 of corpus/vendor-quirks.md on the walks with ifIndex added (handoff 023 section 2), plus the
rows dropped by reason. Reads out/walks-ifindex/*.walk (made by `corpus_run.py --with-ifindex`) and the native step; writes out/evidence.json and prints a summary.
Nothing is changed in any implementation; nothing of the corpus is committed (out/ is ignored).

  corpus_evidence.py [--topo-step PATH] [file-name-fragment ...]

F1: for every LLDP row whose lldpLocPortNum is not the ifIndex of the port its lldpLocPortId / lldpLocPortDesc names (an exact match against ifName,
then ifDescr, of the same walk): the file, the row (LocalPortNum, remote chassis and port), the port the native step gave it, the port it should get,
and the verdict: wrong port, dropped (no {#IFINDEX}), or correct (the step used the right port although the number differs).
F6: the number of lldpRemManAddr rows in the walk and the number of native rows with {#REM_MGMT_IP}.
"""
import argparse
import glob
import json
import os
import re
import sys

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, HERE)
import corpus_run  # noqa: E402
import corpus_walk  # noqa: E402

IFNAME = ".1.3.6.1.2.1.31.1.1.1.1."
IFDESCR = ".1.3.6.1.2.1.2.2.1.2."
LOC = ".1.0.8802.1.1.2.1.3.7.1."
REM = ".1.0.8802.1.1.2.1.4.1.1."
MANADDR = ".1.0.8802.1.1.2.1.4.2.1.3."
LINE = re.compile(r"^(\.[0-9.]+) = (\w[\w-]*)(?:: (.*))?$")


def decode(typ, value):
    if value is None:
        return ""
    if typ == "STRING":
        v = value[1:-1] if value.startswith('"') and value.endswith('"') else value
        return v.replace('\\"', '"').replace("\\\\", "\\")
    if typ == "Hex-STRING":
        try:
            return bytes.fromhex(value.replace(" ", "")).decode("utf-8", errors="replace")
        except ValueError:
            return value
    return value


def read_walk(path):
    cells = {}
    with open(path, encoding="utf-8", errors="surrogateescape") as fh:
        for line in fh:
            m = LINE.match(line.rstrip("\n"))
            if m:
                cells[m.group(1)] = (m.group(2), m.group(3))
    return cells


def column(cells, prefix):
    return {oid[len(prefix):]: decode(*cells[oid]) for oid in cells if oid.startswith(prefix)}


def analyse(name, walk, topo_step):
    cells = read_walk(walk)
    names, descrs = column(cells, IFNAME), column(cells, IFDESCR)
    loc_id, loc_desc = column(cells, LOC + "3."), column(cells, LOC + "4.")
    out = {"f1_rows": [], "f1": {"wrong": 0, "dropped": 0, "correct": 0, "no_reference": 0, "same_number": 0}, "f6": {}}
    rows, err = corpus_run.run_native(topo_step, walk, "lldp")
    suffixes = sorted({oid[len(REM + c + "."):] for c in "456789" for oid in cells if oid.startswith(REM + c + ".")})
    suffixes = [s for s in suffixes if s.count(".") >= 2]
    man = {oid[len(MANADDR):] for oid in cells if oid.startswith(MANADDR)}
    out["f6"] = {"manaddr_rows_in_walk": len(man), "native_rows": None if rows is None else len(rows),
                 "native_rows_with_mgmt_ip": None if rows is None else sum(1 for r in rows if "{#REM_MGMT_IP}" in r)}
    if rows is None:
        out["error"] = err
        return out
    kept, dropped = corpus_run.contract_rows("neighbors", rows)
    reasons = {}
    for _, why in dropped:
        reasons[why] = reasons.get(why, 0) + 1
    out["lldp_rows"] = {"native": len(rows), "kept_by_contract": len(kept), "dropped_by_reason": reasons}
    if len(suffixes) != len(rows):
        out["f1_note"] = "rows (%d) and remote indexes (%d) do not pair; F1 not evaluated" % (len(rows), len(suffixes))
        return out
    for suffix, row in zip(suffixes, rows):
        local_num = suffix.split(".")[1]
        pid, pdesc = loc_id.get(local_num), loc_desc.get(local_num)
        should = None
        for ref in (pid, pdesc):
            if not ref:
                continue
            hit = [i for i, n in names.items() if n == ref] or [i for i, n in descrs.items() if n == ref]
            if len(hit) == 1:
                should = hit[0]
                break
        if should is None:
            out["f1"]["no_reference"] += 1
            continue
        if should == local_num:
            out["f1"]["same_number"] += 1
            continue
        got = row.get("{#IFINDEX}")
        verdict = "dropped" if got is None else ("correct" if got == should else "wrong")
        out["f1"][verdict] += 1
        if len(out["f1_rows"]) < 12:
            out["f1_rows"].append({"local_port_num": local_num, "rem_chassis": row.get("{#REM_CHASSIS}"), "rem_port": row.get("{#REM_PORT}"),
                                   "got": None if got is None else [got, names.get(got, descrs.get(got))], "should": [should, names.get(should, descrs.get(should))],
                                   "verdict": verdict})
    return out


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--topo-step", default=os.path.join(HERE, "..", "discovery", "step_golden", "topo_step"))
    ap.add_argument("only", nargs="*")
    a = ap.parse_args()
    result = {}
    for walk in sorted(glob.glob(os.path.join(corpus_walk.OUT, "walks-ifindex", "*.walk"))):
        name = os.path.basename(walk)[:-5]
        if a.only and not any(o in name for o in a.only):
            continue
        result[name] = analyse(name, walk, a.topo_step)
    with open(os.path.join(corpus_walk.OUT, "evidence.json"), "w", encoding="utf-8") as fh:
        json.dump(result, fh, indent=1, ensure_ascii=False)
    tot = {k: sum(r.get("f1", {}).get(k, 0) for r in result.values()) for k in ("wrong", "dropped", "correct", "no_reference", "same_number")}
    print("F1 rows, all files:", json.dumps(tot))
    for name, r in sorted(result.items()):
        f1 = r.get("f1", {})
        if f1.get("wrong") or f1.get("dropped") or f1.get("correct"):
            print("  %-34s wrong %3d dropped %3d correct %3d" % (name, f1["wrong"], f1["dropped"], f1["correct"]))
    f6 = [(n, r["f6"]) for n, r in sorted(result.items()) if r["f6"].get("manaddr_rows_in_walk")]
    print("F6: files with lldpRemManAddr rows: %d; native rows with {#REM_MGMT_IP} in them: %d of %d rows in the walks" % (
        len(f6), sum(f["native_rows_with_mgmt_ip"] or 0 for _, f in f6), sum(f["manaddr_rows_in_walk"] for _, f in f6)))


if __name__ == "__main__":
    main()
