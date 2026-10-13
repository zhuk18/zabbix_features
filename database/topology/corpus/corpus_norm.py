#!/usr/bin/env python3
"""corpus_norm.py — measurement for topology-6-normalization.md on the corpus with ifIndex (handoff 025).

  corpus_norm.py characterize     section 0: how many name values the cleaning changes, how many Hex-STRING names become text, how many local port rows
                                   step 2 resolves today and how many more the name key would resolve (an offline reference, normalization_ref.py)
  corpus_norm.py measure          section 4: the local port by steps (1, 2 exact, 2 key, 3), dropped by reason, against the 024 state, native and push.py
                                   against the reference

Reads out/walks-ifindex/*.walk (corpus_run.py --with-ifindex); writes out/norm.json. Nothing of the corpus is committed.
"""
import glob
import json
import os
import re
import sys

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, HERE)
sys.path.insert(0, os.path.join(HERE, "..", "discovery"))
import corpus_evidence as ev  # noqa: E402
import corpus_f1 as f1  # noqa: E402
import corpus_run  # noqa: E402
import corpus_walk  # noqa: E402
import normalization_ref as nr  # noqa: E402
import push  # noqa: E402

TEXT_CHASSIS = {1, 2, 3, 6, 7}           # chassisComponent, interfaceAlias, portComponent, interfaceName, local
TEXT_PORT = {1, 2, 5, 6, 7}              # interfaceAlias, portComponent, interfaceName, agentCircuitId, local


def unescape(text):
    return re.sub(r"\\(.)", r"\1", text, flags=re.S)


def raw_value(cell):
    """(kind, payload): ('text', str) for a STRING, ('hex', bytes) for a Hex-STRING, ('other', str)."""
    typ, val = cell
    val = val or ""
    if typ == "Hex-STRING":
        return "hex", bytes(int(x, 16) for x in val.split())
    if typ == "STRING":
        v = val.strip()
        if len(v) >= 2 and v[0] == '"' and v[-1] == '"':
            v = v[1:-1]
        return "text", unescape(v)
    return "other", val


def today(cell):
    """What the native step gives today for a name value: trimmed text, a Hex-STRING as colon hex."""
    kind, p = raw_value(cell)
    if kind == "hex":
        return ":".join("%02x" % b for b in p)
    return p.strip(" \t\r\n")


def new(cell):
    kind, p = raw_value(cell)
    if kind == "hex":
        return nr.clean(nr.text_or_hex(p))
    return nr.clean(p)


def fields(cells):
    """[(field name, oid, cell)] of the name fields of §4.5 present in the walk."""
    out = []
    ctype = ev.column(cells, ev.REM + "4.")
    ptype = ev.column(cells, ev.REM + "6.")
    ltype = ev.column(cells, ev.LOC + "2.")

    def num(v):
        try:
            return int(v)
        except (TypeError, ValueError):
            return 0

    for oid, cell in cells.items():
        if oid.startswith(ev.IFNAME):
            out.append(("ifName", oid, cell))
        elif oid.startswith(ev.IFDESCR):
            out.append(("ifDescr", oid, cell))
        elif oid.startswith(ev.REM + "7."):
            if num(ptype.get(oid[len(ev.REM + "7."):])) in TEXT_PORT:
                out.append(("rem_port", oid, cell))
        elif oid.startswith(ev.REM + "8."):
            out.append(("rem_port_desc", oid, cell))
        elif oid.startswith(ev.REM + "9."):
            out.append(("rem_sysname", oid, cell))
        elif oid.startswith(ev.REM + "5."):
            if num(ctype.get(oid[len(ev.REM + "5."):])) in TEXT_CHASSIS:
                out.append(("chassis id (text subtype)", oid, cell))
        elif oid.startswith(ev.LOC + "3."):
            sub = ltype.get(oid[len(ev.LOC + "3."):])
            if sub is None:
                out.append(("lldpLocPortId (no subtype)", oid, cell))
            elif num(sub) in TEXT_PORT:
                out.append(("lldpLocPortId (text subtype)", oid, cell))
        elif oid.startswith(ev.LOC + "4."):
            out.append(("lldpLocPortDesc", oid, cell))
    return out


def resolve_new(cells, local_num, table):
    """The local port by LLD 4.4 with normalization §4.5-§4.8: (step label, ifIndex) or (None, reason). Step labels: 1, '2 exact', '2 key', 3."""
    ifindexes, _, _, macs = f1.port_view(cells)
    names, descrs = {}, {}
    for oid, cell in cells.items():
        if oid.startswith(ev.IFNAME) and oid[len(ev.IFNAME):] in ifindexes:
            v = new(cell)
            if v:
                names[oid[len(ev.IFNAME):]] = v
        elif oid.startswith(ev.IFDESCR) and oid[len(ev.IFDESCR):] in ifindexes:
            v = new(cell)
            if v:
                descrs[oid[len(ev.IFDESCR):]] = v
    sub = ev.column(cells, ev.LOC + "2.").get(local_num)
    try:
        st = int(sub) if sub is not None else 0
    except ValueError:
        st = 0
    id_cell = cells.get(ev.LOC + "3." + local_num)
    desc_cell = cells.get(ev.LOC + "4." + local_num)
    pid = f1.loc_text(cells, ev.LOC + "3.", local_num, st) if st == 3 else (new(id_cell) if id_cell else "")
    pdesc = new(desc_cell) if desc_cell else ""
    if pid:
        if st == 5:
            m = [i for i, n in names.items() if n == pid]
            if len(m) == 1:
                return 1, m[0]
            if len(m) > 1:
                return None, "name on several ports"
        elif st == 3:
            mac = f1.norm_mac(pid)
            m = [i for i, v in macs.items() if v == mac] if nr.valid_mac(mac) else []
            if len(m) == 1:
                return 1, m[0]
        elif st == 7:
            n = nr.numeric_id(pid)
            if n is not None and str(n) in ifindexes:
                return 1, str(n)
    for label, conv in (("2 exact", lambda s: s), ("2 key", lambda s: nr.name_key(s, table))):
        for text in (pid, pdesc):
            if not text:
                continue
            for field in (names, descrs):
                m = [i for i, n in field.items() if conv(n) == conv(text)]
                if len(m) == 1:
                    return label, m[0]
                if len(m) > 1:
                    return None, "name on several ports"
    if local_num in ifindexes:
        return 3, local_num
    return None, "ifIndex missing"


def characterize(paths):
    table = nr.forms()
    changed, total, kinds = {}, {}, {}
    hex_names = {}
    steps = {}
    key_examples = []
    for path in paths:
        name = os.path.basename(path)[:-5]
        cells = ev.read_walk(path)
        for fld, oid, cell in fields(cells):
            total[fld] = total.get(fld, 0) + 1
            t, n = today(cell), new(cell)
            if raw_value(cell)[0] == "hex" and fld not in ("lldpLocPortId (no subtype)",):
                kind, p = raw_value(cell)
                if nr.text_or_hex(p).count(":") == 0 or not re.fullmatch(r"([0-9a-f]{2}:)*[0-9a-f]{2}", nr.text_or_hex(p)):
                    hex_names[fld] = hex_names.get(fld, 0) + 1
            if t != n:
                changed[fld] = changed.get(fld, 0) + 1
                kind = ("hex to text" if raw_value(cell)[0] == "hex" and not re.fullmatch(r"([0-9a-f]{2}:)*[0-9a-f]{2}", n) else
                        "quotes removed" if re.match(r"^[\"'“]", t) else "emptied" if n == "" else "other")
                kinds[kind] = kinds.get(kind, 0) + 1
        # local port rows: today's reference (exact, uncleaned) against the new one
        suffixes = sorted({oid[len(ev.REM + c + "."):] for c in "456789" for oid in cells if oid.startswith(ev.REM + c + ".")})
        for suffix in [s for s in suffixes if s.count(".") >= 2]:
            local_num = suffix.split(".")[1]
            old_step, old_res = f1.resolve(cells, local_num)
            new_step, new_res = resolve_new(cells, local_num, table)
            steps[(old_step, new_step)] = steps.get((old_step, new_step), 0) + 1
            if old_step != new_step or old_res != new_res:
                key_examples.append((name, local_num, old_step, old_res, new_step, new_res))
    return {"total": total, "changed": changed, "kinds": kinds, "hex_names": hex_names,
            "steps": {"%s -> %s" % k: v for k, v in sorted(steps.items(), key=str)}, "different": key_examples}


def main():
    mode = sys.argv[1] if len(sys.argv) > 1 else "characterize"
    paths = sorted(glob.glob(os.path.join(corpus_walk.OUT, "walks-ifindex", "*.walk")))
    if mode == "characterize":
        r = characterize(paths)
        with open(os.path.join(corpus_walk.OUT, "norm.json"), "w", encoding="utf-8") as fh:
            json.dump(r, fh, indent=1, ensure_ascii=False)
        print("name values by field:", json.dumps(r["total"], ensure_ascii=False))
        print("changed by the cleaning (today's native text vs §3+§4.5):", json.dumps(r["changed"], ensure_ascii=False))
        print("by kind:", json.dumps(r["kinds"]))
        print("Hex-STRING name values that become text:", json.dumps(r["hex_names"]))
        print("local port rows, step before -> step after (reference):", json.dumps(r["steps"]))
        print("rows whose result differs:", len(r["different"]))
        for x in r["different"][:30]:
            print("  ", x)


if __name__ == "__main__":
    main()
