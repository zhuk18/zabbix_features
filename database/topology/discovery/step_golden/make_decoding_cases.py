#!/usr/bin/env python3
"""Writes decoding_cases.json: walk text -> the raw row of the native step and push.py (topology-6-normalization.md §3, LLD spec §4.4 as of 2.4, handoff 025).
The step extracts and decodes; it makes no decision (no trim, no cleaning, no key, no local port, no address choice). edge_cases.php runs the cases
through the native step, test_push.py through push.py (ports and lldp; push.py has no CDP). Each case names the row and the macros it expects; `null`
means the macro is absent.

    python3 make_decoding_cases.py
"""
import json
import os

HERE = os.path.dirname(os.path.abspath(__file__))
IFX = "1.3.6.1.2.1.31.1.1.1.1"


def line(oid, typ, value):
    return ".%s = %s: %s\n" % (oid, typ, value)


def hexs(b):
    return " ".join("%02X" % x for x in b) + " "


def ports_walk(name=None, descr=None, mac=None, extra=""):
    w = line("1.3.6.1.2.1.2.2.1.1.1", "INTEGER", 1) + line("1.3.6.1.2.1.2.2.1.3.1", "INTEGER", "ethernetCsmacd(6)")
    if name is not None:
        w += line(IFX + ".1", *name)
    if descr is not None:
        w += line("1.3.6.1.2.1.2.2.1.2.1", *descr)
    if mac is not None:
        w += line("1.3.6.1.2.1.2.2.1.6.1", *mac)
    return w + extra


def lldp_walk(local=7, loc=(), rem=(), man=(), extra=""):
    """loc: [(column, type, value)], rem: [(column, type, value)] of the neighbor 0.<local>.1; man: [(full index tail after .3., value)]"""
    w = line("1.0.8802.1.1.2.1.3.7.1.2.%d" % local, "INTEGER", 5) if False else ""
    for col, typ, value in loc:
        w += line("1.0.8802.1.1.2.1.3.7.1.%d.%d" % (col, local), typ, value)
    for col, typ, value in rem:
        w += line("1.0.8802.1.1.2.1.4.1.1.%d.0.%d.1" % (col, local), typ, value)
    for tail, typ, value in man:
        w += line("1.0.8802.1.1.2.1.4.2.1.3.%s" % tail, typ, value)
    return w + extra


cases = []


def case(name, source, walk, expect):
    cases.append({"name": name, "source": source, "walk": walk, "expect": expect})


# ---- text columns: a STRING is passed as it is, a Hex-STRING is text or the hex form ----
S = "STRING"
H = "Hex-STRING"
case("ports: ifName and ifDescr apart, no fallback of ifName to ifDescr", "ports", ports_walk(None, (S, '"eth0"')), {"{#IFNAME}": None, "{#IFDESCR}": "eth0"})
case("ports: both names", "ports", ports_walk((S, '"Gi0/1"'), (S, '"GigabitEthernet0/1"')), {"{#IFNAME}": "Gi0/1", "{#IFDESCR}": "GigabitEthernet0/1"})
case("text: white space inside the quotes is kept (ingest cleans)", "ports", ports_walk((S, '"  a b  "')), {"{#IFNAME}": "  a b  "})
case("text: a quote character inside is kept", "ports", ports_walk((S, '"\\"x\\""')), {"{#IFNAME}": '"x"'})
case("text: backslash, quote and a raw line feed", "ports", ports_walk((S, '"a\\\\b\\"c\nd"')), {"{#IFNAME}": 'a\\b"c\nd'})
case("text: a line feed and a trailing space are kept", "ports", ports_walk((S, '"Uplink x\n2D 31 00 "')), {"{#IFNAME}": "Uplink x\n2D 31 00 "})
case("text: UTF-8 is a STRING", "ports", ports_walk((S, '"ä€"')), {"{#IFNAME}": "ä€"})
case("text: a Hex-STRING with trailing NUL bytes is text (corpus F5)", "ports", ports_walk((H, hexs(b"tengigabitethernet1/1/1\0"))), {"{#IFNAME}": "tengigabitethernet1/1/1"})
case("text: several trailing NUL bytes", "ports", ports_walk((H, hexs(b"Gi0/1\0\0\0"))), {"{#IFNAME}": "Gi0/1"})
case("text: a Hex-STRING with a NUL inside is the hex form", "ports", ports_walk((H, hexs(b"a\0b"))), {"{#IFNAME}": "61:00:62"})
case("text: a Hex-STRING with a control character is the hex form", "ports", ports_walk((H, hexs(b"a\x01b"))), {"{#IFNAME}": "61:01:62"})
case("text: a Hex-STRING with a line feed is the hex form", "ports", ports_walk((H, hexs(b"a\nb"))), {"{#IFNAME}": "61:0a:62"})
case("text: a Hex-STRING with a C1 control character (U+0085) is the hex form", "ports", ports_walk((H, hexs("a\u0085b".encode()))), {"{#IFNAME}": "61:c2:85:62"})
case("text: a Hex-STRING with DEL is the hex form", "ports", ports_walk((H, hexs(b"a\x7fb"))), {"{#IFNAME}": "61:7f:62"})
case("text: a Hex-STRING with a tab is text", "ports", ports_walk((H, hexs(b"a\tb"))), {"{#IFNAME}": "a\tb"})
case("text: invalid UTF-8 is the hex form", "ports", ports_walk((H, hexs(b"a\xffb"))), {"{#IFNAME}": "61:ff:62"})
case("text: a Hex-STRING of UTF-8 is text", "ports", ports_walk((H, hexs("ä€".encode()))), {"{#IFNAME}": "ä€"})
case("text: a Hex-STRING of NUL bytes only is no value", "ports", ports_walk((H, hexs(b"\0\0"))), {"{#IFNAME}": None})
case("text: an empty STRING is no value", "ports", ports_walk(None, None, None, line(IFX + ".1", S, '""')), {"{#IFNAME}": None})
# ---- binary columns ----
case("binary: ifPhysAddress is the hex form", "ports", ports_walk(None, None, (H, "02 00 00 00 00 0A ")), {"{#IFMAC}": "02:00:00:00:00:0a"})
case("binary: a MAC written as text is passed as text", "ports", ports_walk(None, None, (S, '"0:11:22:33:44:5"')), {"{#IFMAC}": "0:11:22:33:44:5"})
case("binary: an all-zero MAC is passed (ingest decides)", "ports", ports_walk(None, None, (H, "00 00 00 00 00 00 ")), {"{#IFMAC}": "00:00:00:00:00:00"})
# ---- the device's own chassis id ----
chassis = line("1.0.8802.1.1.2.1.3.1.0", "INTEGER", "macAddress(4)") + line("1.0.8802.1.1.2.1.3.2.0", H, "AA BB CC 00 00 01 ")
case("loc chassis: macAddress subtype, the hex form and the subtype name", "ports", ports_walk(None, None, None, chassis), {"{#LOC_CHASSIS}": "aa:bb:cc:00:00:01", "{#LOC_CHASSIS_TYPE}": "macAddress"})
case("loc chassis: local subtype is text", "ports", ports_walk(None, None, None, line("1.0.8802.1.1.2.1.3.1.0", "INTEGER", "local(7)") + line("1.0.8802.1.1.2.1.3.2.0", S, '"sw-1"')),
     {"{#LOC_CHASSIS}": "sw-1", "{#LOC_CHASSIS_TYPE}": "local"})
case("loc chassis: no subtype, a Hex-STRING is the hex form and has no type", "ports", ports_walk(None, None, None, line("1.0.8802.1.1.2.1.3.2.0", H, "AA BB CC 00 00 02 ")),
     {"{#LOC_CHASSIS}": "aa:bb:cc:00:00:02", "{#LOC_CHASSIS_TYPE}": None})
case("loc chassis: absent, nothing is guessed from a port MAC", "ports", ports_walk(None, None, (H, "02 00 00 00 00 01 ")), {"{#LOC_CHASSIS}": None, "{#LOC_CHASSIS_TYPE}": None})
# ---- lldp: the local port is not resolved ----
nb = [(4, "INTEGER", "macAddress(4)"), (5, H, "AA BB CC 00 00 01 "), (6, "INTEGER", "interfaceName(5)"), (7, S, '"Te1/0/1"'), (9, S, '"peer"')]
case("lldp: the local port number from the index, no {#IFINDEX}, no {#IFNAME}", "lldp", lldp_walk(7, [(3, S, '"Gi0/7"')], nb), {"{#LOC_PORT_NUM}": "7", "{#IFINDEX}": None, "{#IFNAME}": None})
case("lldp: a row is emitted whatever the local port (no IF-MIB, no loc port table)", "lldp", lldp_walk(99, [], nb + [(8, S, '"d"')], extra=line("1.0.8802.1.1.2.1.3.7.1.3.1", S, '"x"')), {"{#LOC_PORT_NUM}": "99"})
for sub, kind, typ, val, want in [(5, "interfaceName", S, '"Gi0/7"', "Gi0/7"), (3, "macAddress", H, "02 00 00 00 00 07 ", "02:00:00:00:00:07"), (7, "local", S, '"7"', "7"),
                                  (7, "local", H, hexs(b"7\0"), "7"), (1, "interfaceAlias", S, '"alias 7"', "alias 7"), (4, "networkAddress", H, "01 0A 00 00 07 ", "01:0a:00:00:07")]:
    case("lldp: local port id of subtype %s" % kind, "lldp", lldp_walk(7, [(2, "INTEGER", "%s(%d)" % (kind, sub)), (3, typ, val)], nb),
         {"{#LOC_PORT_ID}": want, "{#LOC_PORT_ID_TYPE}": kind})
case("lldp: local port id without a subtype: a Hex-STRING is the hex form", "lldp", lldp_walk(7, [(3, H, "02 00 00 00 00 07 ")], nb), {"{#LOC_PORT_ID}": "02:00:00:00:00:07", "{#LOC_PORT_ID_TYPE}": None})
case("lldp: local port id without a subtype: a STRING is text", "lldp", lldp_walk(7, [(3, S, '"Gi0/7"')], nb), {"{#LOC_PORT_ID}": "Gi0/7", "{#LOC_PORT_ID_TYPE}": None})
case("lldp: local port description", "lldp", lldp_walk(7, [(4, S, '"Uplink"')], nb), {"{#LOC_PORT_DESC}": "Uplink"})
# remote ids by subtype
for sub, kind, typ, val, want in [(4, "macAddress", H, "AA BB CC 00 00 01 ", "aa:bb:cc:00:00:01"), (5, "networkAddress", H, "01 C0 A8 01 0A ", "01:c0:a8:01:0a"),
                                  (7, "local", S, '"sw-local"', "sw-local"), (6, "interfaceName", H, hexs(b"Gi0/1\0"), "Gi0/1"), (4, "macAddress", S, '"AA:BB:CC:00:00:01"', "AA:BB:CC:00:00:01")]:
    case("lldp: remote chassis id of subtype %s (%s)" % (kind, typ), "lldp", lldp_walk(7, [], [(4, "INTEGER", "%s(%d)" % (kind, sub)), (5, typ, val), (9, S, '"peer"')]),
         {"{#REM_CHASSIS}": want, "{#REM_CHASSIS_TYPE}": kind})
case("lldp: remote chassis id without a subtype: a STRING is text", "lldp", lldp_walk(7, [], [(5, S, '"X1"'), (9, S, '"peer"')]), {"{#REM_CHASSIS}": "X1", "{#REM_CHASSIS_TYPE}": None})
for sub, kind, typ, val, want in [(3, "macAddress", H, "AA BB CC 00 00 09 ", "aa:bb:cc:00:00:09"), (5, "interfaceName", S, '"Te1/0/1"', "Te1/0/1"), (7, "local", S, '"01"', "01"),
                                  (7, "local", H, hexs(b"12\0"), "12"), (4, "networkAddress", H, "01 0A 00 00 09 ", "01:0a:00:00:09")]:
    case("lldp: remote port id of subtype %s (%s)" % (kind, typ), "lldp", lldp_walk(7, [], [(6, "INTEGER", "%s(%d)" % (kind, sub)), (7, typ, val), (9, S, '"peer"')]),
         {"{#REM_PORT}": want, "{#REM_PORT_TYPE}": kind})
case("lldp: remote sysname and port description are text", "lldp", lldp_walk(7, [], [(8, S, '" uplink "'), (9, H, hexs(b"core-1\0"))]), {"{#REM_SYSNAME}": "core-1", "{#REM_PORT_DESC}": " uplink "})
# management address entries: raw
r = [(5, H, "AA BB CC 00 00 01 "), (9, S, '"peer"')]
case("lldp mgmt: an entry with the length octet", "lldp", lldp_walk(7, [], r, [("0.7.1.1.4.10.0.0.9", "INTEGER", 2)]), {"{#REM_MAN_ADDRS}": "lldp:1.4.10.0.0.9"})
case("lldp mgmt: every entry, ordered by the index part", "lldp", lldp_walk(7, [], r, [("0.7.1.2.16.32.1.13.184.0.0.0.0.0.0.0.0.0.0.0.1", "INTEGER", 2), ("0.7.1.1.4.10.0.0.20", "INTEGER", 2), ("0.7.1.1.4.10.0.0.5", "INTEGER", 2), ("0.7.1.6.6.2.0.0.0.0.1", "INTEGER", 2)]),
     {"{#REM_MAN_ADDRS}": "lldp:1.4.10.0.0.5 lldp:1.4.10.0.0.20 lldp:2.16.32.1.13.184.0.0.0.0.0.0.0.0.0.0.0.1 lldp:6.6.2.0.0.0.0.1"})
case("lldp mgmt: without the length octet and with length 5 and an extra octet", "lldp", lldp_walk(7, [], r, [("0.7.1.1.10.0.0.2", "INTEGER", 2), ("0.7.1.1.5.10.0.0.3.0", "INTEGER", 2)]),
     {"{#REM_MAN_ADDRS}": "lldp:1.5.10.0.0.3.0 lldp:1.10.0.0.2"})
case("lldp mgmt: the TimeMark of the address table is ignored", "lldp", lldp_walk(7, [], r, [("527540.7.1.1.4.10.0.0.7", "INTEGER", 2)]), {"{#REM_MAN_ADDRS}": "lldp:1.4.10.0.0.7"})
case("lldp mgmt: one pair under two TimeMark values is one neighbor", "lldp", lldp_walk(7, [], r, [("5.7.1.1.4.10.0.0.8", "INTEGER", 2), ("6.7.1.1.4.10.0.0.7", "INTEGER", 2)]),
     {"{#REM_MAN_ADDRS}": "lldp:1.4.10.0.0.7 lldp:1.4.10.0.0.8"})
case("lldp mgmt: another LocalPortNum.RemIndex is another neighbor", "lldp", lldp_walk(7, [], r, [("0.8.1.1.4.10.0.0.9", "INTEGER", 2), ("0.7.2.1.4.10.0.0.9", "INTEGER", 2)]), {"{#REM_MAN_ADDRS}": None})
case("lldp mgmt: no entries, no macro", "lldp", lldp_walk(7, [], r), {"{#REM_MAN_ADDRS}": None})
# cdp
ci = line("1.3.6.1.2.1.31.1.1.1.1.5", S, '"Gi0/5"')
cdp = "1.3.6.1.4.1.9.9.23.1.2.1.1"
case("cdp: the address raw as cdp:<type>:<hex octets>", "cdp", ci + line(cdp + ".3.5.1", "INTEGER", 1) + line(cdp + ".4.5.1", H, "C0 A8 01 0B ") + line(cdp + ".6.5.1", S, '"sw-b"') + line(cdp + ".7.5.1", S, '"Gi1/0/48"'),
     {"{#IFINDEX}": "5", "{#IFNAME}": "Gi0/5", "{#REM_MAN_ADDRS}": "cdp:1:c0:a8:01:0b", "{#REM_SYSNAME}": "sw-b", "{#REM_PORT}": "Gi1/0/48", "{#REM_PORT_TYPE}": "interfaceName", "{#SOURCE}": "cdp"})
case("cdp: a type 65535 entry with an empty address is raw too", "cdp", ci + line(cdp + ".3.5.1", "INTEGER", 65535) + line(cdp + ".4.5.1", H, "") + line(cdp + ".6.5.1", S, '"sw-c"'),
     {"{#REM_MAN_ADDRS}": "cdp:65535:"})
case("cdp: a device id that is a bare MAC is text", "cdp", ci + line(cdp + ".6.5.1", S, '"204c9e6e08fe"'), {"{#REM_SYSNAME}": "204c9e6e08fe", "{#REM_MAN_ADDRS}": None})

with open(os.path.join(HERE, "decoding_cases.json"), "w", encoding="utf-8") as fh:
    json.dump(cases, fh, indent=1, ensure_ascii=False)
    fh.write("\n")
print("%d decoding cases" % len(cases))
