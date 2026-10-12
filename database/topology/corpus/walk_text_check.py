#!/usr/bin/env python3
"""walk_text_check.py — what the Zabbix `walk[]` item really returns for STRING values, and what the native step and push.py make of it
(handoff 023 section 3). Nothing is fixed here; it only measures.

Two devices are served by snmpsim on 127.0.0.91 and 127.0.0.92 (port 1611):
  walktest  synthetic: seven ports whose ifDescr / ifName / lldpRemPortDesc / lldpRemSysName carry a plain value, a backslash, a double quote,
            a newline, a NUL, a non-printable byte and a UTF-8 multi-byte character;
  aos6walk  the corpus file aos6.snmprec (ifName with a newline: finding D2 of 020), with ifIndex.N = N added.
Two hosts with one SNMP agent item each, `walk[<IF-MIB and LLDP roots>]` (the real item, value type text), read through the API. The item text is saved
under out/walktext/ (git-ignored), then fed to the native step (topo_step) and to push.py, and compared with `snmpbulkwalk -On` of the same device.
The hosts and the simulators are removed at the end. Needs: zabbix_server running, ZBX_TOKEN, the lab venv (labenv) and the snmpsim of the repository's .venv.

  ZBX_TOKEN=... ~/work/labenv/bin/python walk_text_check.py [--corpus DIR]
"""
import argparse
import json
import os
import shutil
import subprocess
import sys
import tempfile
import time

HERE = os.path.dirname(os.path.abspath(__file__))
REPO = os.path.abspath(os.path.join(HERE, "..", "..", ".."))
sys.path.insert(0, os.path.join(HERE, "..", "lab"))
sys.path.insert(0, os.path.join(HERE, "..", "discovery"))
sys.path.insert(0, HERE)
from labsim import gen, zbx  # noqa: E402
import corpus_run  # noqa: E402
import corpus_walk  # noqa: E402
import push  # noqa: E402

API_URL = "http://localhost/zabbix/ui/api_jsonrpc.php"
ROOTS = ["1.3.6.1.2.1.2.2.1.1", "1.3.6.1.2.1.2.2.1.2", "1.3.6.1.2.1.2.2.1.3", "1.3.6.1.2.1.31.1.1.1.1", "1.0.8802.1.1.2.1.4.1.1"]       # ifIndex, ifDescr, ifType, ifName, lldpRemEntry
VALUES = [("plain", b"plain"), ("backslash", b"a\\b"), ("quote", b'a"b'), ("newline", b"a\nb"), ("nul", b"a\x00b"),
          ("nonprintable", b"a\x01b"), ("utf8", "ä€".encode("utf-8")), ("invalid_utf8", b"a\xffb"), ("tab", b"a\tb"), ("cr", b"a\rb"),
          ("del", b"a\x7fb"), ("c1_utf8", "a\u0085b".encode("utf-8")), ("high_latin1", b"a\xe4b"), ("trailing_space", b"ab "), ("leading_space", b" ab"), ("empty", b""), ("six_ascii", b"abcdef"), ("six_utf8", "äää".encode("utf-8")),
          ("vt", b"a\x0bb")]
SNMPSIM = os.path.join(REPO, ".venv", "bin", "snmpsim-command-responder")
COMMUNITY = "wt"


def line(oid, value):
    if isinstance(value, int):
        return "%s|2|%d" % (oid, value)
    printable = all(32 <= c < 127 for c in value) and value == value.strip() and b"|" not in value
    return "%s|4|%s" % (oid, value.decode()) if printable else "%s|4x|%s" % (oid, value.hex())


def synthetic_snmprec():
    rows = [("1.3.6.1.2.1.1.5.0", b"walktest")]
    for i, (_, value) in enumerate(VALUES, 1):
        rows += [("1.3.6.1.2.1.2.2.1.1.%d" % i, i), ("1.3.6.1.2.1.2.2.1.2.%d" % i, value), ("1.3.6.1.2.1.2.2.1.3.%d" % i, 6),
                 ("1.3.6.1.2.1.31.1.1.1.1.%d" % i, value)]
        rem = "0.%d.1" % i                                   # TimeMark 0, LocalPortNum i, index 1
        rows += [("1.0.8802.1.1.2.1.4.1.1.4." + rem, 4), ("1.0.8802.1.1.2.1.4.1.1.5." + rem, bytes([0xAA, 0, 0, 0, 0, i])),
                 ("1.0.8802.1.1.2.1.4.1.1.6." + rem, 5), ("1.0.8802.1.1.2.1.4.1.1.7." + rem, b"p%d" % i),
                 ("1.0.8802.1.1.2.1.4.1.1.8." + rem, value), ("1.0.8802.1.1.2.1.4.1.1.9." + rem, value)]
    rows.append(("1.3.6.1.4.1.99999.1.0", b"end"))
    rows.sort(key=lambda r: tuple(int(x) for x in r[0].split(".")))
    return "\n".join(line(o, v) for o, v in rows) + "\n"


def start_snmpsim(directory, address):
    return subprocess.Popen([SNMPSIM, "--data-dir=" + directory, "--cache-dir=" + os.path.join(directory, "cache"),
                             "--agent-udpv4-endpoint=%s:1611" % address], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--corpus", default=corpus_walk.CORPUS)
    a = ap.parse_args()
    out = os.path.join(corpus_walk.OUT, "walktext")
    os.makedirs(out, exist_ok=True)
    work = tempfile.mkdtemp(prefix="walktext-")
    procs, hosts = [], []
    api = zbx.Api(API_URL)
    try:
        devices = {"walktest": "127.0.0.91", "aos6walk": "127.0.0.92"}
        for name, address in devices.items():
            d = os.path.join(work, name)
            os.makedirs(d)
            with open(os.path.join(d, COMMUNITY + ".snmprec"), "w", encoding="utf-8") as fh:
                if name == "walktest":
                    fh.write(synthetic_snmprec())
                else:
                    # the corpus file with ifIndex.N = N added (the corpus has no such column), the way the lab serves it with `ifindex: true`
                    fh.write(gen.lines(gen.corpus_records(os.path.join(a.corpus, "librenms", "aos6.snmprec"), ifindex=True)))
            procs.append(start_snmpsim(d, address))
        time.sleep(4)
        group = api.call("hostgroup.get", {"filter": {"name": ["Test"]}})[0]["groupid"]
        oid = "walk[" + ",".join(ROOTS) + "]"
        items = {}
        for name, address in devices.items():
            host = api.call("host.create", {"host": name, "groups": [{"groupid": group}], "tags": [{"tag": zbx.TAG, "value": "1"}],
                                           "interfaces": [{"type": 2, "main": 1, "useip": 1, "ip": address, "dns": "", "port": "1611",
                                                           "details": zbx.snmp_details({"community": COMMUNITY})}]})["hostids"][0]
            hosts.append(host)
            iface = api.call("hostinterface.get", {"hostids": [host], "output": ["interfaceid"]})[0]["interfaceid"]
            item = api.call("item.create", {"hostid": host, "interfaceid": iface, "name": "walk", "key_": "wt.walk", "type": 20, "snmp_oid": oid, "value_type": 4,
                                           "delay": "5s", "history": "1d"})["itemids"][0]
            items[name] = item
        time.sleep(zbx.CONFIG_SYNC_WAIT)
        texts = {}
        deadline = time.time() + 120
        while time.time() < deadline and len(texts) < len(items):
            for name, item in items.items():
                if name in texts:
                    continue
                h = api.call("history.get", {"history": 4, "itemids": [item], "sortfield": "clock", "sortorder": "DESC", "limit": 1})
                if h:
                    texts[name] = h[0]["value"]
            time.sleep(2)
        for name, item in items.items():
            if name not in texts:
                info = api.call("item.get", {"itemids": [item], "output": ["state", "error"]})[0]
                print("%s: no value after 120 s: %s" % (name, info["error"] or info["state"]))
        results = {}
        for name, text in texts.items():
            path = os.path.join(out, name + ".zbx.txt")
            with open(path, "w", encoding="utf-8", errors="surrogateescape", newline="") as fh:
                fh.write(text)
            net = subprocess.run(["snmpbulkwalk", "-v2c", "-c", COMMUNITY, "-On", "-Cr50", "-t", "5", "-r", "1", "%s:1611" % devices[name]] + ROOTS,
                                 capture_output=True, text=True, errors="replace").stdout
            with open(os.path.join(out, name + ".snmpbulkwalk.txt"), "w", encoding="utf-8", errors="surrogateescape") as fh:
                fh.write(net)
            native = {}
            for source in ("ports", "lldp"):
                rows, err = corpus_run.run_native(os.path.join(HERE, "..", "discovery", "step_golden", "topo_step"), path, source)
                native[source] = rows if rows is not None else err
            pushed, perr = corpus_run.run_push(text)
            results[name] = {"item_text_lines": text.count("\n") + 1, "native": native, "push": pushed, "push_problems": perr}
        with open(os.path.join(out, "results.json"), "w", encoding="utf-8") as fh:
            json.dump(results, fh, indent=1, ensure_ascii=False)
        print(json.dumps({k: v["item_text_lines"] for k, v in results.items()}), "-> results in", out)
    finally:
        for h in hosts:
            api.call("host.delete", [h])
        for p in procs:
            p.terminate()
        shutil.rmtree(work, ignore_errors=True)


if __name__ == "__main__":
    main()
