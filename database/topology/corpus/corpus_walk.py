#!/usr/bin/env python3
"""corpus_walk.py — snmprec files of the vendor corpus -> the walk text of the golden fixtures (topology-2-lld-delivery.md §6.1).

The corpus (/media/sf_topology-specs/corpus/, GPLv3) is read at run time and never copied into this repository; the walk text this tool makes
goes to a folder that git ignores (database/topology/corpus/out/). The text is what `snmpbulkwalk -On` prints for the OID roots of the
template's master walk[] item (what record_fixture.sh records from a lab device): one variable per line, `.OID = TYPE: value`, sorted by OID.

  corpus_walk.py convert <file.snmprec> [more files]       writes out/<name>.walk
  corpus_walk.py verify  <snmpsim-command-responder> <file.snmprec> [more]   serves the files with snmpsim, walks them with snmpbulkwalk,
                                                                          and compares with the converter (the proof of the format)
"""
import os
import re
import shutil
import subprocess
import sys
import tempfile
import time

HERE = os.path.dirname(os.path.abspath(__file__))
OUT = os.path.join(HERE, "out")
CORPUS = os.environ.get("TOPOLOGY_CORPUS", "/media/sf_topology-specs/corpus")

# the OID roots of the "core" master walk[] of the template (template_topology_by_snmp.yaml, topology.walk.core)
CORE_ROOTS = ["1.3.6.1.2.1.1.5", "1.3.6.1.2.1.2.2.1", "1.3.6.1.2.1.31.1.1.1.1", "1.3.6.1.2.1.31.1.1.1.15", "1.0.8802.1.1.2.1.3.2",
              "1.0.8802.1.1.2.1.3.7", "1.0.8802.1.1.2.1.4.1", "1.0.8802.1.1.2.1.4.2", "1.2.840.10006.300.43.1.2.1.1.13",
              "1.3.6.1.4.1.9.9.23.1.2.1.1", "1.3.6.1.4.1.9.9.23.1.3.1"]


class WalkError(Exception):
    pass


def oid_key(oid):
    return tuple(int(p) for p in oid.split("."))


def read_snmprec(path):
    """[(oid, tag, value)]; a line that does not parse is an error (the generator of the lab fails on it too)."""
    rows = []
    with open(path, "rb") as fh:
        for n, raw in enumerate(fh, 1):
            line = raw.decode("utf-8", errors="surrogateescape").rstrip("\r\n")
            if line == "":
                continue
            parts = line.split("|", 2)
            if len(parts) != 3 or not re.fullmatch(r"\d+(\.\d+)*", parts[0]):
                raise WalkError("%s:%d: not an snmprec line: %r" % (os.path.basename(path), n, line[:80]))
            value = parts[2] if parts[1] == "4x" else parts[2].rstrip(" \t")      # snmpsim strips trailing white space of a line (not of a hex value)
            rows.append((parts[0], parts[1], value))
    return rows


def octets(tag, value):
    """The bytes of an OctetString value as snmpsim serves it: 4 = the text (UTF-8), 4x = hex, 4e = escaped (\\xNN)."""
    if tag == "4x":
        try:
            return bytes.fromhex(value)
        except ValueError:
            raise WalkError("bad hex string %r" % value[:40])
    if tag == "4e":
        return value.encode("latin-1", errors="replace").decode("unicode_escape").encode("latin-1")
    return value.encode("utf-8", errors="surrogateescape")


def printable(b):
    # net-snmp (sprint_realloc_octet_string): a string is shown as text when every byte is isprint() or isspace()
    return all(0x20 <= c < 0x7f or c in (9, 10, 11, 12, 13) for c in b)


def format_value(tag, value):
    if tag in ("4", "4x", "4e"):
        b = octets(tag, value)
        shown = b
        if printable(shown):
            if shown == b"":
                return '""'                                  # snmpbulkwalk -On prints an empty string without the type
            return 'STRING: "%s"' % shown.decode("ascii").replace("\\", "\\\\").replace('"', '\\"')
        return "Hex-STRING: " + " ".join("%02X" % c for c in b) + " "          # net-snmp ends the hex bytes with a space
    if tag == "2":
        return "INTEGER: %s" % value
    if tag == "6":
        return "OID: ." + value.lstrip(".")
    if tag == "64":
        return "IpAddress: %s" % value
    if tag == "65":
        return "Counter32: %s" % value
    if tag == "66":
        return "Gauge32: %s" % value
    if tag == "67":
        t = int(value)
        days, rest = divmod(t, 8640000)
        hms = "%d:%02d:%02d.%02d" % (rest // 360000, rest // 6000 % 60, rest // 100 % 60, rest % 100)
        return "Timeticks: (%d) %s" % (t, ("%d day%s, " % (days, "" if days == 1 else "s")) + hms if days else hms)
    if tag == "70":
        return "Counter64: %s" % value
    raise WalkError("unknown snmprec tag %r" % tag)


IF_COLUMNS = ["1.3.6.1.2.1.2.2.1.2", "1.3.6.1.2.1.2.2.1.3", "1.3.6.1.2.1.2.2.1.6", "1.3.6.1.2.1.31.1.1.1.1"]


def add_ifindex(rows):
    """The corpus keeps no ifIndex column (it is the table index by definition); every real device has it, and the step needs it to know a port.
    This adds `ifIndex.N = N` for every N that has an ifDescr, ifType, ifPhysAddress or ifName: a measurement variant, never the default."""
    have = {oid for oid, _, _ in rows}
    out = list(rows)
    for oid, _, _ in rows:
        for col in IF_COLUMNS:
            if oid.startswith(col + ".") and oid.count(".") == col.count(".") + 1:
                n = oid.rsplit(".", 1)[1]
                new = "1.3.6.1.2.1.2.2.1.1." + n
                if new not in have:
                    have.add(new)
                    out.append((new, "2", n))
    return out


def convert_rows(rows, roots=CORE_ROOTS, with_ifindex=False):
    """Walk text lines of the variables under `roots`, sorted by OID (what snmpbulkwalk of each root, one after the other, prints)."""
    if with_ifindex:
        rows = add_ifindex(rows)
    keyed = {}
    for oid, tag, value in rows:
        keyed[oid_key(oid)] = (oid, tag, value)
    lines = []
    for root in roots:
        rk = oid_key(root)
        for k in sorted(k for k in keyed if k[:len(rk)] == rk and len(k) > len(rk)):
            oid, tag, value = keyed[k]
            lines.append(".%s = %s" % (oid, format_value(tag, value)))
    return lines


def convert_file(path, roots=CORE_ROOTS, with_ifindex=False):
    return "\n".join(convert_rows(read_snmprec(path), roots, with_ifindex)) + "\n"


def cmd_convert(paths):
    os.makedirs(OUT, exist_ok=True)
    for path in paths:
        name = os.path.basename(path)[:-len(".snmprec")]
        text = convert_file(path)
        with open(os.path.join(OUT, name + ".walk"), "w", encoding="utf-8", errors="surrogateescape") as fh:
            fh.write(text)
        print("%s: %d variables" % (name, text.count("\n")))


def cmd_verify(responder, paths):
    """snmpsim serves the files, snmpbulkwalk records them like record_fixture.sh, and the two texts must be identical."""
    ok = True
    with tempfile.TemporaryDirectory() as data:
        for path in paths:
            shutil.copy(path, os.path.join(data, os.path.basename(path)))       # snmpsim does not follow symbolic links
        port = "11611"
        proc = subprocess.Popen([responder, "--data-dir=" + data, "--cache-dir=" + os.path.join(data, "cache"),
                                 "--agent-udpv4-endpoint=127.0.0.1:" + port], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        try:
            time.sleep(4)
            for path in paths:
                name = os.path.basename(path)[:-len(".snmprec")]
                got = []
                for root in CORE_ROOTS:
                    out = subprocess.run(["snmpbulkwalk", "-v2c", "-c", name, "-On", "-Cr50", "-t", "5", "-r", "1", "127.0.0.1:" + port, root],
                                         capture_output=True, text=True, errors="surrogateescape").stdout
                    got += [l for l in out.splitlines() if l and " = No Such " not in l and "No more variables" not in l]
                want = convert_rows(read_snmprec(path)) if False else convert_file(path).splitlines()
                same = got == want
                ok = ok and same
                print("%s: %d lines, %s" % (name, len(want), "identical to snmpsim + snmpbulkwalk" if same else "DIFFERENT"))
                if not same:
                    for a, b in zip(got, want):
                        if a != b:
                            print("  snmpsim:   %s\n  converter: %s" % (a, b))
                            break
                    if len(got) != len(want):
                        print("  lines: snmpsim %d, converter %d" % (len(got), len(want)))
        finally:
            proc.terminate()
    return ok


if __name__ == "__main__":
    if len(sys.argv) >= 3 and sys.argv[1] == "convert":
        cmd_convert(sys.argv[2:])
    elif len(sys.argv) >= 4 and sys.argv[1] == "verify":
        sys.exit(0 if cmd_verify(sys.argv[2], sys.argv[3:]) else 1)
    else:
        print(__doc__)
        sys.exit(2)
