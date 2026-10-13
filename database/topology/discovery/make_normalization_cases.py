#!/usr/bin/env python3
"""Writes normalization_cases.json: input -> output for every function of topology-6-normalization.md §3-§4 (handoff 025), run by the native step
(step_golden/normalization_cases.php through `topo_step --fn`), by push.py (test_push.py) and by PHP ingest (test_normalization.php). One case file, three
implementations; the cases are written here, the forms-table rows are read from the spec file (so a new row of the table is a regenerate).

    python3 make_normalization_cases.py [--spec PATH]

A function is a key; each case is {"in": ..., "out": ...}. `null` is "no value". The groups:
  text_or_hex  §3  input: hex bytes of a Hex-STRING value, output: text or the hex form aa:bb:..
  clean        §4.5 input text (decoded), output the cleaned value
  name_key     §4.6 input a name, output the key
  numeric_id   §4.7 input text, output an integer
  port_mac     §4.8 input {"mac", "ports": [macs of the Device's ports]}, output the 0-based index of the one port that has it
  ip_text      §4.3 input an address in any spelling, output the canonical text
  man_addrs    §4.4 input the space-separated `rem_man_addrs` entries, output the management address text or null
  local_port   model §6.7.6 input {"name", "ports": [{if_index, name?, descr?, mac?}], "row": {if_index? loc_port_num loc_port_id loc_port_id_type loc_port_desc}},
               output {"if_index": n|null, "reason": null|name_on_several_ports|if_index_missing|no_ports}
"""
import argparse
import json
import os
import sys

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, HERE)
import gen_port_name_forms as forms  # noqa: E402

cases = {
    "text_or_hex": [
        {"in": "74 65 73 74", "out": "test"},
        {"in": "74 65 73 74 00", "out": "test"},
        {"in": "74 65 73 74 00 00 00", "out": "test"},
        {"in": "74 65 00 73 74", "out": "74:65:00:73:74"},
        {"in": "74 65 09 73 74", "out": "te\tst"},
        {"in": "74 65 0a 73 74", "out": "74:65:0a:73:74"},
        {"in": "c3 a4 e2 82 ac", "out": "ä€"},
        {"in": "61 ff 62", "out": "61:ff:62"},
        {"in": "74 65 73 74 20", "out": "test "},
        {"in": "74 65 7f 73", "out": "74:65:7f:73"},
        {"in": "74 65 6e 67 69 67 61 62 69 74 65 74 68 65 72 6e 65 74 31 2f 31 2f 31 00", "out": "tengigabitethernet1/1/1"},
        {"in": "", "out": ""},
        {"in": "00", "out": ""},
    ],
    "clean": [
        {"in": "plain", "out": "plain"},
        {"in": "  a b  ", "out": "a b"},
        {"in": "\t a \r\n", "out": "a"},
        {"in": "a\u0000", "out": "a"},
        {"in": "a\u0000\u0000", "out": "a"},
        {"in": "a  b", "out": "a  b"},
        {"in": "\"x\"", "out": "x"},
        {"in": "'x'", "out": "x"},
        {"in": "“x”", "out": "x"},
        {"in": "  \" x \"  ", "out": "x"},
        {"in": "\"\"", "out": None},
        {"in": "\"  \"", "out": None},
        {"in": "\"x", "out": "\"x"},
        {"in": "x\"", "out": "x\""},
        {"in": "“x\"", "out": "“x\""},
        {"in": "\"'x'\"", "out": "'x'"},
        {"in": "\"\"x\"\"", "out": "\"x\""},
        {"in": "“GigabitEthernet1/0/1”", "out": "GigabitEthernet1/0/1"},
        {"in": "", "out": None},
        {"in": " \t\n ", "out": None},
        {"in": "a\"b", "out": "a\"b"},
        {"in": "ä€ ", "out": "ä€"},
    ],
    "name_key": [
        # the examples of §4.6
        {"in": "GigabitEthernet0/0/1", "out": "gi0/0/1"},
        {"in": "gi0/0/1", "out": "gi0/0/1"},
        {"in": "GE0/0/1", "out": "gi0/0/1"},
        {"in": "XGigabitEthernet0/0/1", "out": "xge0/0/1"},
        {"in": "TenGigabitEthernet1/1/1\u0000", "out": "te1/1/1"},
        {"in": "Ethernet 0/49", "out": "et0/49"},
        {"in": "Eth1/1", "out": "et1/1"},
        {"in": "ge-0/0/1", "out": "ge-0/0/1"},
        {"in": "Eth-Trunk1", "out": "eth-trunk1"},
        {"in": "Port-channel10", "out": "po10"},
        {"in": "“GigabitEthernet1/0/1”", "out": "gi1/0/1"},
        {"in": "1/1/1", "out": "1/1/1"},
        # the other steps
        {"in": "  Gi0/1 ", "out": "gi0/1"},
        {"in": "GigabitEthernet 0/1", "out": "gi0/1"},
        {"in": "Ethernet  0/49", "out": "et0/49"},
        {"in": "Ethernet\t0/49", "out": "et0/49"},
        {"in": "port 1", "out": "port1"},
        {"in": "port-001", "out": "port-001"},
        {"in": "Port 1", "out": "port1"},
        {"in": "Vlan 10", "out": "vlan10"},
        {"in": "Management1", "out": "management1"},
        {"in": "mgmt1/1/1", "out": "mgmt1/1/1"},
        {"in": "ethernet", "out": "ethernet"},
        {"in": "Ethernet", "out": "ethernet"},
        {"in": "ethernetx1", "out": "ethernetx1"},
        {"in": "GigabitEthernet", "out": "gigabitethernet"},
        {"in": "GigabitEthernet-1", "out": "gigabitethernet-1"},
        {"in": "ge 0/0/1", "out": "gi0/0/1"},
        {"in": "G 0/1", "out": "g0/1"},
        {"in": "G0/1 2", "out": "g0/1 2"},
        {"in": "a b 1", "out": "a b 1"},
        {"in": "ÄBC1", "out": "Äbc1"},
        {"in": "äbc 1", "out": "äbc 1"},
        {"in": "1 Gi", "out": "1 gi"},
        {"in": "10GE1/0/1", "out": "10ge1/0/1"},
        {"in": "twentyfivegige1/0/1", "out": "twe1/0/1"},
        {"in": "TwentyFiveGigabitEthernet1/0/1", "out": "twe1/0/1"},
        {"in": "\"Gi0/1\"", "out": "gi0/1"},
        {"in": "", "out": None},
        {"in": "\"\"", "out": None},
    ],
    "numeric_id": [
        {"in": "1", "out": 1},
        {"in": "01", "out": 1},
        {"in": "0001", "out": 1},
        {"in": "101", "out": 101},
        {"in": "0", "out": None},
        {"in": "00", "out": None},
        {"in": "2147483647", "out": 2147483647},
        {"in": "2147483648", "out": None},
        {"in": "0000000001", "out": 1},
        {"in": "00000000001", "out": None},
        {"in": "12345678901", "out": None},
        {"in": "12a", "out": None},
        {"in": " 12 ", "out": 12},
        {"in": "\"12\"", "out": 12},
        {"in": "-1", "out": None},
        {"in": "+1", "out": None},
        {"in": "1.5", "out": None},
        {"in": "", "out": None},
        {"in": "１２", "out": None},
        {"in": "1/1", "out": None},
    ],
    "port_mac": [
        {"in": {"mac": "aa:bb:cc:00:00:01", "ports": ["aa:bb:cc:00:00:01", "aa:bb:cc:00:00:02"]}, "out": 0},
        {"in": {"mac": "aa:bb:cc:00:00:02", "ports": ["aa:bb:cc:00:00:01", "aa:bb:cc:00:00:02"]}, "out": 1},
        {"in": {"mac": "aa:bb:cc:00:00:01", "ports": ["aa:bb:cc:00:00:01", "aa:bb:cc:00:00:01"]}, "out": None},
        {"in": {"mac": "aa:bb:cc:00:00:01", "ports": ["aa:bb:cc:00:00:01", "aa:bb:cc:00:00:01", "aa:bb:cc:00:00:03"]}, "out": None},
        {"in": {"mac": "00:00:00:00:00:00", "ports": ["00:00:00:00:00:00", "aa:bb:cc:00:00:02"]}, "out": None},
        {"in": {"mac": "aa:bb:cc:00:00:09", "ports": ["aa:bb:cc:00:00:01"]}, "out": None},
        {"in": {"mac": "aa:bb:cc:00:00", "ports": ["aa:bb:cc:00:00"]}, "out": None},
        {"in": {"mac": "", "ports": ["", ""]}, "out": None},
        {"in": {"mac": "aa:bb:cc:00:00:01", "ports": ["", "aa:bb:cc:00:00:01"]}, "out": 1},
        {"in": {"mac": "not a mac", "ports": ["not a mac"]}, "out": None},
        {"in": {"mac": "aa:bb:cc:00:00:01", "ports": []}, "out": None},
    ],
    "ip_text": [
        {"in": "10.0.0.1", "out": "10.0.0.1"},
        {"in": "2001:db8::1", "out": "2001:db8::1"},
        {"in": "2001:DB8:0:0:0:0:0:1", "out": "2001:db8::1"},
        {"in": "2001:db8:0:0:1:0:0:1", "out": "2001:db8::1:0:0:1"},
        {"in": "2001:db8:0:1:1:1:1:1", "out": "2001:db8:0:1:1:1:1:1"},
        {"in": "::1", "out": "::1"},
        {"in": "::", "out": "::"},
        {"in": "::169.254.2.122", "out": "::a9fe:27a"},
        {"in": "::ffff:10.0.0.1", "out": "::ffff:a00:1"},
        {"in": "fe80::0:0:1", "out": "fe80::1"},
        {"in": "1:0:0:2:0:0:0:3", "out": "1:0:0:2::3"},
        {"in": "10.0.0", "out": None},
        {"in": "300.1.1.1", "out": None},
        {"in": "hello", "out": None},
        {"in": "", "out": None},
    ],
}


def _ports(*specs):
    return [dict({"if_index": i}, **{k: v for k, v in zip(("name", "descr", "mac"), rest) if v is not None}) for i, *rest in specs]


P3 = _ports((1, "Gi0/1", "GigabitEthernet0/1", "02:00:00:00:00:01"), (2, "Gi0/2", "GigabitEthernet0/2", "02:00:00:00:00:02"), (3, "Te1/0/1", "TenGigabitEthernet1/0/1", "02:00:00:00:00:03"))
SHARED_MAC = _ports((1, "p1", "d1", "aa:aa:aa:aa:aa:aa"), (2, "p2", "d2", "aa:aa:aa:aa:aa:aa"), (3, "p3", "d3", "02:00:00:00:00:03"))
DUP = _ports((1, "Eth1", None, None), (2, "eth1", None, None), (3, "Eth3", "uplink", None))
ZERO = _ports((1, "p1", None, "00:00:00:00:00:00"), (2, "p2", None, "00:00:00:00:00:00"))


def lp(name, ports, row, ok=None, reason=None):
    return {"in": {"name": name, "ports": ports, "row": row}, "out": {"if_index": ok, "reason": reason}}


cases["man_addrs"] = [
    {"in": "lldp:1.4.10.0.0.9", "out": "10.0.0.9"},
    {"in": "lldp:1.10.0.0.9", "out": "10.0.0.9"},
    {"in": "lldp:1.5.10.0.0.9.0", "out": "10.0.0.9"},
    {"in": "lldp:1.6.10.0.0.9.0.0", "out": None},
    {"in": "lldp:1.3.10.0.0.1", "out": None},
    {"in": "lldp:1.4.10.0.0.256", "out": None},
    {"in": "lldp:2.16.32.1.13.184.0.0.0.0.0.0.0.0.0.0.0.1", "out": "2001:db8::1"},
    {"in": "lldp:2.32.1.13.184.0.0.0.0.0.0.0.0.0.0.0.1", "out": "2001:db8::1"},
    {"in": "lldp:2.16.0.0.0.0.0.0.0.0.0.0.0.0.10.0.0.1", "out": "::a00:1"},
    {"in": "lldp:2.4.10.0.0.1", "out": None},
    {"in": "lldp:6.6.2.0.0.0.0.1", "out": None},
    {"in": "lldp:7.4.10.0.0.1", "out": None},
    {"in": "lldp:1.4.10.0.0.5 lldp:1.4.10.0.0.20", "out": "10.0.0.5"},
    {"in": "lldp:2.16.32.1.13.184.0.0.0.0.0.0.0.0.0.0.0.1 lldp:1.4.10.0.0.5", "out": "10.0.0.5"},
    {"in": "lldp:6.6.2.0.0.0.0.1 lldp:1.4.10.0.0.5", "out": "10.0.0.5"},
    {"in": "lldp:2.16.32.1.13.184.0.0.0.0.0.0.0.0.0.0.0.1 lldp:2.16.32.1.13.184.0.0.0.0.0.0.0.0.0.0.0.2", "out": "2001:db8::1"},
    {"in": "cdp:1:c0:a8:01:0b", "out": "192.168.1.11"},
    {"in": "cdp:1:C0:A8:01:0B", "out": "192.168.1.11"},
    {"in": "cdp:65535:", "out": None},
    {"in": "cdp:1:c0:a8:01", "out": None},
    {"in": "cdp:1:c0:a8:01:0b:00", "out": None},
    {"in": "cdp:20:20:01:0d:b8:00:00:00:00:00:00:00:00:00:00:00:01", "out": None},
    {"in": "cdp:65535: cdp:1:0a:00:00:07", "out": "10.0.0.7"},
    {"in": "lldp:1.4.10.0.0.9 cdp:1:0a:00:00:07", "out": "10.0.0.9"},
    {"in": "", "out": None},
    {"in": "junk lldp:1.x", "out": None},
]
cases["local_port"] = [
    lp("row with if_index is the local port, nothing is looked up", P3, {"if_index": 2, "loc_port_num": 9}, 2),
    lp("no PORTS rows at all", [], {"loc_port_num": 1}, None, "no_ports"),
    lp("step 1 interfaceName: loc_port_id against name", P3, {"loc_port_num": 99, "loc_port_id_type": "interfaceName", "loc_port_id": "Gi0/2"}, 2),
    lp("step 1 interfaceName: against the cleaned name", P3, {"loc_port_num": 99, "loc_port_id_type": "interfaceName", "loc_port_id": "Te1/0/1"}, 3),
    lp("step 1 macAddress: one port has the MAC", P3, {"loc_port_num": 99, "loc_port_id_type": "macAddress", "loc_port_id": "02:00:00:00:00:02"}, 2),
    lp("step 1 macAddress: a MAC on several ports is no match, goes on, then step 3 (loc_port_num is an ifIndex)", SHARED_MAC, {"loc_port_num": 2, "loc_port_id_type": "macAddress", "loc_port_id": "aa:aa:aa:aa:aa:aa"}, 2),
    lp("step 1 macAddress: a MAC on several ports and no other hint: unresolved", SHARED_MAC, {"loc_port_num": 9, "loc_port_id_type": "macAddress", "loc_port_id": "aa:aa:aa:aa:aa:aa"}, None, "if_index_missing"),
    lp("step 1 macAddress: the zero MAC is no match", ZERO, {"loc_port_num": 2, "loc_port_id_type": "macAddress", "loc_port_id": "00:00:00:00:00:00"}, 2),
    lp("step 1 local: a numeric id that is an ifIndex", P3, {"loc_port_num": 99, "loc_port_id_type": "local", "loc_port_id": "3"}, 3),
    lp("step 1 local: leading zeros", P3, {"loc_port_num": 99, "loc_port_id_type": "local", "loc_port_id": "03"}, 3),
    lp("step 1 local: not an ifIndex of the reporter, goes on and ends in step 3", P3, {"loc_port_num": 1, "loc_port_id_type": "local", "loc_port_id": "77"}, 1),
    lp("step 2 id against name when the subtype says nothing", P3, {"loc_port_num": 99, "loc_port_id": "Gi0/1"}, 1),
    lp("step 2 id against descr", P3, {"loc_port_num": 99, "loc_port_id": "GigabitEthernet0/2"}, 2),
    lp("step 2 description against name", P3, {"loc_port_num": 99, "loc_port_desc": "Gi0/1"}, 1),
    lp("step 2 description against descr", P3, {"loc_port_num": 99, "loc_port_desc": "TenGigabitEthernet1/0/1"}, 3),
    lp("step 2 cleaned names before the name key: an exact name wins over a key match", _ports((1, "Gi0/1", None, None), (2, "GigabitEthernet0/1", None, None)), {"loc_port_num": 9, "loc_port_id": "Gi0/1"}, 1),
    lp("step 2 name key: the long form against the short name", P3, {"loc_port_num": 99, "loc_port_id": "GigabitEthernet0/3 "}, None, "if_index_missing"),
    lp("step 2 name key: a long form id against a short name", P3, {"loc_port_num": 99, "loc_port_id": "TenGigabitEthernet1/0/1"}, 3),
    lp("step 2 name key: case and a space before the digit", P3, {"loc_port_num": 99, "loc_port_id": "gi 0/2"}, 2),
    lp("step 2 name key: the id is the long form, the port name the short one", _ports((5, "Gi0/5", None, None)), {"loc_port_num": 99, "loc_port_id": "GigabitEthernet0/5"}, 5),
    lp("step 2 cleaned names are case sensitive: Eth1 is the one port with that exact name", DUP, {"loc_port_num": 3, "loc_port_id": "Eth1"}, 1),
    lp("step 2 a name on several ports (name key only): dropped, step 3 is not tried", DUP, {"loc_port_num": 3, "loc_port_id": "ETH1 "}, None, "name_on_several_ports"),
    lp("step 2 several ports with the name key but the next comparison gives one port: that one wins", DUP, {"loc_port_num": 9, "loc_port_id": "eth1x", "loc_port_desc": "uplink"}, 3),
    lp("step 2 the exact (cleaned) comparison gives one port before the key is tried", _ports((1, "x", None, None), (2, "X", None, None)), {"loc_port_num": 1, "loc_port_id": "x"}, 1),
    lp("step 2 several ports by the name key only: name_on_several_ports", _ports((1, "Gi0/1", None, None), (2, "GigabitEthernet0/1", None, None)), {"loc_port_num": 1, "loc_port_id": "gigabitethernet0/1"}, None, "name_on_several_ports"),
    lp("step 3 loc_port_num as an ifIndex when no name matches", P3, {"loc_port_num": 3, "loc_port_id": "nothing", "loc_port_desc": "else"}, 3),
    lp("step 3 only if no name of step 2 matches any port", _ports((1, "x1", None, None), (2, "X1", None, None), (3, "p3", None, None)), {"loc_port_num": 3, "loc_port_id": "x1"}, 1),
    lp("step 4 the ifIndex does not exist", P3, {"loc_port_num": 12, "loc_port_id": "nothing"}, None, "if_index_missing"),
    lp("step 4 no id, no description, the port number does not exist", P3, {"loc_port_num": 0}, None, "if_index_missing"),
    lp("step 4 a row with neither if_index nor loc_port_num", P3, {"loc_port_id": "nothing"}, None, "if_index_missing"),
]


def form_cases(table):
    """Every row of the forms table: the long form with a digit after it, in three spellings; and the same without a digit (no replacement)."""
    out = []
    for long_form, short_form in table:
        out.append({"in": "%s1/2" % long_form, "out": "%s1/2" % short_form})
        out.append({"in": "%s1/2" % long_form.upper(), "out": "%s1/2" % short_form})
        if long_form.isalpha():          # white space is removed only between LETTERS and the first digit (§4.6 step 3): "Port-channel 1" keeps its space
            out.append({"in": "%s%s 1/2" % (long_form[0].upper(), long_form[1:]), "out": "%s1/2" % short_form})
        else:
            out.append({"in": "%s 1/2" % long_form, "out": "%s 1/2" % long_form})
        out.append({"in": "%s-1" % long_form, "out": "%s-1" % long_form})
        out.append({"in": long_form, "out": long_form})
    return out


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--spec", default=forms.SPEC)
    a = ap.parse_args()
    table = forms.read_table(a.spec)
    cases["name_key"] += form_cases(table)
    with open(os.path.join(HERE, "normalization_cases.json"), "w", encoding="utf-8") as fh:
        json.dump(cases, fh, indent=1, ensure_ascii=False)
        fh.write("\n")
    print({k: len(v) for k, v in cases.items()})


if __name__ == "__main__":
    main()
