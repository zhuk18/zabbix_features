#!/usr/bin/env python3
"""corpus_run.py — the three implementations on every file of the vendor corpus (handoff 020 section 1).

For each snmprec file and each source (ports, lldp, cdp, fdb, lag): the walk text (corpus_walk.py), the native C step (step_golden/topo_step),
push.py (build_role_values: ports and lldp; it has no CDP), and the PHP ingest normalization (corpus_normalize.php) of the rows of the two. Records
rows out, rows dropped with the reason (the server contract of lld_topology.c, then the PHP normalization), errors, and every difference between
the implementations. Output goes to out/ (git ignores it): results.json and summary.md. Nothing of the corpus is committed.

  corpus_run.py [--corpus DIR] [--topo-step PATH] [--dsn DSN --user U [--password P]] [file-name-fragment ...]
"""
import argparse
import glob
import json
import os
import subprocess
import sys
import time

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, HERE)
sys.path.insert(0, os.path.join(HERE, "..", "discovery"))
import corpus_walk  # noqa: E402
import push  # noqa: E402

SOURCES = ["ports", "lldp", "cdp", "fdb", "lag"]
CONTRACT = {   # lld_topology.c: macro -> (field, uint, required, group)
    "ports": [("{#IFINDEX}", "if_index", True, True, 0), ("{#IFNAME}", "name", False, False, 0), ("{#IFTYPE}", "if_type", False, False, 0),
              ("{#IFMAC}", "mac", False, False, 0), ("{#IFADMINSTATUS}", "admin_status", False, False, 0),
              ("{#IFOPERSTATUS}", "oper_status", False, False, 0), ("{#IFSPEED}", "speed", False, False, 0), ("{#LOC_CHASSIS}", "loc_chassis", False, False, 0)],
    "neighbors": [("{#IFINDEX}", "if_index", True, True, 0), ("{#IFNAME}", "if_name", False, False, 0), ("{#REM_CHASSIS}", "rem_chassis", False, False, 1),
                  ("{#REM_MGMT_IP}", "rem_mgmt_ip", False, False, 1), ("{#REM_SYSNAME}", "rem_sysname", False, False, 1),
                  ("{#REM_CHASSIS_TYPE}", "rem_chassis_type", False, False, 0), ("{#REM_PORT}", "rem_port", False, False, 0),
                  ("{#REM_PORT_TYPE}", "rem_port_type", False, False, 0), ("{#REM_PORT_DESC}", "rem_port_desc", False, False, 0),
                  ("{#LOC_CHASSIS}", "loc_chassis", False, False, 0), ("{#SOURCE}", "source", False, False, 0)],
}


def contract_rows(role, rows):
    """The server contract (lld_topology.c lld_topology_row_build): [fields rows], [(row, reason)] dropped."""
    spec = CONTRACT[role]
    kept, dropped = [], []
    for row in rows:
        out, reason = {}, None
        for macro, field, uint, required, group in spec:
            value = row.get(macro)
            if value is None and field == "if_index" and role == "ports":
                value = row.get("{#SNMPINDEX}")
            if value is None and field == "source":
                value = "lldp"
            if value is None:
                if required:
                    reason = "missing %s" % macro
                continue
            if uint and not str(value).isdigit():
                reason = "non-numeric %s" % macro
                continue
            out[field] = int(value) if uint else value
        for g in (1, 2):
            members = [f for _, f, _, _, grp in spec if grp == g]
            if members and not any(f in out for f in members):
                reason = reason or "missing one of " + "|".join(m for m, f, _, _, grp in spec if grp == g)
        if reason:
            dropped.append((row, reason))
        else:
            kept.append(out)
    return kept, dropped


def run_native(topo_step, walk, source, missing="error"):
    p = subprocess.run([topo_step, walk, source, missing], capture_output=True, text=True, errors="replace", timeout=60)
    if p.returncode != 0 or p.stdout.startswith("ERROR"):
        return None, (p.stdout + p.stderr).strip()[:300] or "exit %d" % p.returncode
    try:
        return json.loads(p.stdout), None
    except ValueError as e:
        return None, "invalid JSON: %s" % e


def run_push(text):
    try:
        values, problems = push.build_role_values(text)
    except Exception as e:  # noqa: BLE001
        return {}, "%s: %s" % (type(e).__name__, str(e)[:200])
    out = {}
    for key, role in ((push.ROLE_PORTS_KEY, "ports"), (push.ROLE_LLDP_KEY, "lldp")):
        if key in values:
            out[role] = json.loads(values[key])
    return out, ("; ".join(problems) if problems else None)


def php_normalize(args, rows):
    if not rows:
        return []
    p = subprocess.run(["php", os.path.join(HERE, "corpus_normalize.php"), args.dsn, args.user, args.password],
                       input=json.dumps(rows), capture_output=True, text=True, timeout=120)
    lines = [l for l in p.stdout.splitlines() if l.startswith("[")]
    if not lines:
        raise RuntimeError("corpus_normalize.php: " + (p.stderr or p.stdout)[:300])
    return json.loads(lines[-1])


def canonical(rows):
    return sorted(json.dumps(r, sort_keys=True, ensure_ascii=False) for r in rows)


def diff_rows(a, b):
    """Row-level differences between two lists of rows (as dicts): [(kind, row_a, row_b)]; rows are paired by if_index and remote key."""
    def key(r):
        return (str(r.get("{#IFINDEX}", r.get("if_index"))), str(r.get("{#REM_CHASSIS}", r.get("rem_chassis", ""))),
                str(r.get("{#REM_MGMT_IP}", r.get("rem_mgmt_ip", ""))), str(r.get("{#REM_SYSNAME}", r.get("rem_sysname", ""))))
    ma, mb = {}, {}
    for r in a:
        ma.setdefault(key(r), []).append(r)
    for r in b:
        mb.setdefault(key(r), []).append(r)
    out = []
    for k in sorted(set(ma) | set(mb)):
        # rows with one key are paired in the order of their other fields, not of the walk: the push script and the step may order them differently
        la, lb = sorted(ma.get(k, []), key=repr), sorted(mb.get(k, []), key=repr)
        for ra, rb in zip(la, lb):
            if ra != rb:
                fields = sorted(f for f in set(ra) | set(rb) if ra.get(f) != rb.get(f))
                out.append(("field", k, {f: (ra.get(f), rb.get(f)) for f in fields}))
        for r in la[len(lb):]:
            out.append(("only_a", k, r))
        for r in lb[len(la):]:
            out.append(("only_b", k, r))
    return out


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--corpus", default=corpus_walk.CORPUS)
    ap.add_argument("--topo-step", default=os.path.join(HERE, "..", "discovery", "step_golden", "topo_step"))
    ap.add_argument("--dsn", default="mysql:host=localhost;dbname=topo_t_corpus;charset=utf8mb4")
    ap.add_argument("--user", default="zabbix")
    ap.add_argument("--password", default="")
    ap.add_argument("--with-ifindex", action="store_true", help="add the ifIndex column the corpus does not keep (a measurement variant)")
    ap.add_argument("only", nargs="*")
    args = ap.parse_args()
    files = sorted(glob.glob(os.path.join(args.corpus, "librenms", "*.snmprec")))
    if args.only:
        files = [f for f in files if any(o in os.path.basename(f) for o in args.only)]
    if not files:
        sys.exit("no corpus files under %s/librenms (the corpus is read at run time, never committed)" % args.corpus)
    tag = "-ifindex" if args.with_ifindex else ""
    os.makedirs(os.path.join(corpus_walk.OUT, "walks" + tag), exist_ok=True)
    results = {}
    started = time.time()
    for path in files:
        name = os.path.basename(path)[:-len(".snmprec")]
        entry = results[name] = {"sources": {}}
        try:
            text = corpus_walk.convert_file(path, with_ifindex=args.with_ifindex)
        except corpus_walk.WalkError as e:
            entry["convert_error"] = str(e)
            continue
        walk = os.path.join(corpus_walk.OUT, "walks" + tag, name + ".walk")
        with open(walk, "w", encoding="utf-8", errors="surrogateescape") as fh:
            fh.write(text)
        entry["variables"] = text.count("\n")
        pushed, push_error = run_push(text)
        entry["push_error"] = push_error
        for source in SOURCES:
            t0 = time.time()
            rec = entry["sources"][source] = {}
            try:
                native, error = run_native(args.topo_step, walk, source)
            except subprocess.TimeoutExpired:
                native, error = None, "timeout (60 s)"
            rec["native_error"] = error
            rec["native_rows"] = None if native is None else len(native)
            if native is None:
                continue
            role = "ports" if source == "ports" else "neighbors"
            if source in ("ports", "lldp", "cdp"):
                fields, dropped = contract_rows(role, native)
                rec["contract_dropped"] = [(d[1]) for d in dropped]
                normalized = php_normalize(args, fields)
                rec["php_rows"] = sum(1 for r, why in normalized if r is not None)
                rec["php_dropped"] = [why for r, why in normalized if r is None]
                rec["normalized"] = [r for r, why in normalized if r is not None]
                if source in pushed:
                    prow = pushed[source]
                    rec["push_rows"] = len(prow)
                    rec["push_vs_native"] = [d for d in diff_rows(native, prow)]
                    pf, _ = contract_rows(role, prow)
                    pn = php_normalize(args, pf)
                    rec["push_php_rows"] = sum(1 for r, why in pn if r is not None)
                    rec["push_php_vs_native"] = [d for d in diff_rows([r for r, w in normalized if r is not None],
                                                                      [r for r, w in pn if r is not None])]
                elif source in ("ports", "lldp"):
                    rec["push_rows"] = None
            rec["seconds"] = round(time.time() - t0, 2)
        print("%s: %d variables" % (name, entry["variables"]), flush=True)
    results["_meta"] = {"files": len(files), "seconds": round(time.time() - started, 1), "corpus": args.corpus}
    with open(os.path.join(corpus_walk.OUT, "results%s.json" % tag), "w", encoding="utf-8") as fh:
        json.dump(results, fh, indent=1, ensure_ascii=False, default=str)
    print("done: %d files in %.0f s -> %s" % (len(files), time.time() - started, os.path.join(corpus_walk.OUT, "results%s.json" % tag)))


if __name__ == "__main__":
    main()
