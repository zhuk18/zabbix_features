#!/usr/bin/env python3
"""Writes lldp_cases.json: synthetic walks for the local port rule (topology-2-lld-delivery.md §4.4 `lldp`, steps 1-4) and the management address
(F6), each with the expected {#IFINDEX} and {#REM_MGMT_IP} of its one neighbor row. edge_cases.php runs them through the native step, test_push.py
through push.py: one logic, two implementations (topology-3-push-delivery.md §3). Handoff 024.

    python3 make_lldp_cases.py
"""
import json
import os

HERE = os.path.dirname(os.path.abspath(__file__))
# ifIndex, ifName, ifDescr, ifPhysAddress
PORTS = [(1, "A", "dA", "02 00 00 00 00 01"), (2, "B", "dB", "02 00 00 00 00 02"), (3, "B", "dC", "02 00 00 00 00 03"),
         (4, "C", "dD", "02 00 00 00 00 04"), (5, "D", "dE", "02 00 00 00 00 55"), (6, "E", "dF", "02 00 00 00 00 55"),
         (10, "X10", "dX", "02 00 00 00 00 10")]


def line(oid, typ, value):
    return ".%s = %s: %s\n" % (oid, typ, value)


def base():
    text = ""
    for index, name, descr, mac in PORTS:
        text += line("1.3.6.1.2.1.2.2.1.1.%d" % index, "INTEGER", index)
        text += line("1.3.6.1.2.1.2.2.1.2.%d" % index, "STRING", '"%s"' % descr)
        text += line("1.3.6.1.2.1.2.2.1.3.%d" % index, "INTEGER", "ethernetCsmacd(6)")
        text += line("1.3.6.1.2.1.2.2.1.6.%d" % index, "Hex-STRING", mac + " ")
        text += line("1.3.6.1.2.1.31.1.1.1.1.%d" % index, "STRING", '"%s"' % name)
    return text


def case(name, rule, local, subtype=None, port_id=None, port_desc=None, expect=None, man=(), expect_ip=None, hexid=False):
    """local = LocalPortNum; the neighbor sits at index 0.<local>.1."""
    text = base()
    loc = "1.0.8802.1.1.2.1.3.7.1"
    if subtype is not None:
        text += line("%s.2.%d" % (loc, local), "INTEGER", subtype)
    if port_id is not None:
        text += line("%s.3.%d" % (loc, local), "Hex-STRING" if hexid else "STRING", port_id if hexid else '"%s"' % port_id)
    else:
        text += line("%s.3.%d" % (loc, local), "STRING", '""') if False else ""
    if port_desc is not None:
        text += line("%s.4.%d" % (loc, local), "STRING", '"%s"' % port_desc)
    rem = "0.%d.1" % local
    text += line("1.0.8802.1.1.2.1.4.1.1.4." + rem, "INTEGER", 4)
    text += line("1.0.8802.1.1.2.1.4.1.1.5." + rem, "Hex-STRING", "AA BB CC 00 00 01 ")
    text += line("1.0.8802.1.1.2.1.4.1.1.6." + rem, "INTEGER", 5)
    text += line("1.0.8802.1.1.2.1.4.1.1.7." + rem, "STRING", '"p1"')
    text += line("1.0.8802.1.1.2.1.4.1.1.9." + rem, "STRING", '"peer"')
    for tail, typ, value in man:
        text += line("1.0.8802.1.1.2.1.4.2.1.3.%s.%s" % (rem, tail), typ, value)
    return {"name": name, "rule": rule, "walk": text, "expect_ifindex": expect, "expect_ip": expect_ip}


cases = [
    # step 1: by the subtype
    case("step 1, interfaceName: the name picks the port, not LocalPortNum", "1", 7, 5, "C", expect="4"),
    case("step 1, macAddress: a MAC that one port has", "1", 9, 3, "02 00 00 00 00 04 ", hexid=True, expect="4"),
    case("step 1, local: the value is an ifIndex that exists", "1", 11, 7, "10", expect="10"),
    case("step 1, interfaceName: a name on two ports -> drop (step 4)", "4", 1, 5, "B", expect=None),
    case("step 1, macAddress: a MAC that two ports have is no match; no name matches; LocalPortNum", "1/3", 5, 3, "02 00 00 00 00 55 ", hexid=True, expect="5"),
    case("step 1, local: an ifIndex that does not exist; no name; LocalPortNum does not exist either -> drop", "4", 12, 7, "99", expect=None),
    # step 2: by the names, whatever the subtype
    case("step 2, no subtype: lldpLocPortId = ifName, LocalPortNum is another port", "2", 8, None, "C", expect="4"),
    case("step 2, interfaceName subtype but the name only equals an ifDescr", "2", 9, 5, "dE", expect="5"),
    case("step 2, lldpLocPortDesc = ifName (the id matches nothing)", "2", 8, 7, "99", "X10", expect="10"),
    case("step 2, lldpLocPortDesc = ifDescr", "2", 8, None, "nothing", "dF", expect="6"),
    case("step 2 wins over LocalPortNum: the id names port 1, LocalPortNum is 2", "2", 2, None, "A", expect="1"),
    case("step 2, a name on two ports (ifName B) -> drop, LocalPortNum is not used", "4", 1, None, "B", expect=None),
    case("step 2, ifName is tried before ifDescr: dA is ifDescr of port 1, no ifName dA", "2", 9, None, "dA", expect="1"),
    # step 3: LocalPortNum
    case("step 3, no name of step 2 matches any port: LocalPortNum is an ifIndex", "3", 2, None, "unknown", expect="2"),
    case("step 3, no id at all: LocalPortNum", "3", 6, None, None, expect="6"),
    case("step 3, a MAC-looking id that matches no ifPhysAddress: LocalPortNum", "3", 4, None, "02 00 00 00 00 99 ", hexid=True, expect="4"),
    # step 4
    case("step 4, nothing matches and LocalPortNum is no ifIndex -> drop", "4", 77, None, "unknown", expect=None),
    # management address (F6)
    case("mgmt: IPv4 with the length octet", "F6", 1, None, "A", man=[("1.4.10.0.0.1", "INTEGER", 2)], expect="1", expect_ip="10.0.0.1"),
    case("mgmt: IPv4 without the length octet", "F6", 1, None, "A", man=[("1.10.0.0.2", "INTEGER", 2)], expect="1", expect_ip="10.0.0.2"),
    case("mgmt: IPv4 with length 5 and an extra 0 octet", "F6", 1, None, "A", man=[("1.5.10.0.0.3.0", "INTEGER", 2)], expect="1", expect_ip="10.0.0.3"),
    case("mgmt: family 6 (a MAC as the address) is ignored", "F6", 1, None, "A", man=[("6.6.2.0.0.0.0.1", "INTEGER", 2)], expect="1", expect_ip=None),
    case("mgmt: IPv4 with a wrong length is ignored", "F6", 1, None, "A", man=[("1.10.0.1", "INTEGER", 2), ("1.7.10.0.0.1.1.1.1", "INTEGER", 2)], expect="1", expect_ip=None),
    case("mgmt: IPv6 only when there is no IPv4", "F6", 1, None, "A", man=[("2.16.32.1.13.184.0.0.0.0.0.0.0.0.0.0.0.1", "INTEGER", 2)], expect="1", expect_ip="2001:db8::1"),
    case("mgmt: IPv6 without the length octet", "F6", 1, None, "A", man=[("2.32.1.13.184.0.0.0.0.0.0.0.0.0.0.0.2", "INTEGER", 2)], expect="1", expect_ip="2001:db8::2"),
    case("mgmt: IPv6 text is RFC 5952 (the first of two equal zero runs is compressed)", "F6", 1, None, "A",
         man=[("2.16.32.1.13.184.0.0.0.0.0.1.0.0.0.0.0.1", "INTEGER", 2)], expect="1", expect_ip="2001:db8::1:0:0:1"),
    case("mgmt: an IPv4-compatible IPv6 address is written in hex, not with a dotted tail", "F6", 1, None, "A",
         man=[("2.16.0.0.0.0.0.0.0.0.0.0.0.0.169.254.2.122", "INTEGER", 2)], expect="1", expect_ip="::a9fe:27a"),
    case("mgmt: IPv4 wins over IPv6, whatever the order of the index", "F6", 1, None, "A",
         man=[("1.4.10.0.0.9", "INTEGER", 2), ("2.16.32.1.13.184.0.0.0.0.0.0.0.0.0.0.0.1", "INTEGER", 2)], expect="1", expect_ip="10.0.0.9"),
    case("mgmt: two IPv4 addresses: the first by index order", "F6", 1, None, "A",
         man=[("1.4.10.0.0.20", "INTEGER", 2), ("1.4.10.0.0.5", "INTEGER", 2)], expect="1", expect_ip="10.0.0.5"),
]
with open(os.path.join(HERE, "lldp_cases.json"), "w", encoding="utf-8") as fh:
    json.dump(cases, fh, indent=1, ensure_ascii=False)
    fh.write("\n")
print("%d cases" % len(cases))
