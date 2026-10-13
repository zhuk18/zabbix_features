"""normalization_ref.py — a reading of topology-6-normalization.md §3-§4.8 written for measurement (handoff 025), independent of the three implementations
(native step, push.py, PHP ingest). It is the oracle of corpus_norm.py. Not production code.

The forms table is read from the spec folder (or TOPOLOGY_NORMALIZATION_FORMS).
"""
import os
import re

SPEC_TABLE = os.environ.get("TOPOLOGY_NORMALIZATION_FORMS", "/media/sf_topology-specs/normalization/port-name-forms.tsv")
WHITE = " \t\n\v\f\r"


def forms(path=SPEC_TABLE):
    rows = []
    with open(path, encoding="utf-8") as fh:
        for line in fh:
            if line.startswith("#") or not line.strip():
                continue
            long_form, short_form = line.rstrip("\n").split("\t")
            rows.append((long_form, short_form))
    return sorted(rows, key=lambda r: -len(r[0]))          # longest long form first


def has_control(text):
    return any(c == "\x7f" or (c < " " and c != "\t") or "\x80" <= c <= "\x9f" for c in text)


def text_or_hex(raw):
    """§3, text fields from a Hex-STRING (bytes): trailing NUL bytes removed; valid UTF-8 without a control character other than tab is text, else `aa:bb:..`."""
    raw = raw.rstrip(b"\0")
    try:
        text = raw.decode("utf-8")
    except UnicodeDecodeError:
        return ":".join("%02x" % b for b in raw)
    return ":".join("%02x" % b for b in raw) if has_control(text) else text


def clean(value):
    """§4.5 steps 2-4 on a decoded value; "" is "no value"."""
    value = value.rstrip("\0").strip(WHITE)
    for left, right in (('"', '"'), ("'", "'"), ("“", "”")):
        if len(value) >= 2 and value[0] == left and value[-1] == right:
            value = value[1:-1].strip(WHITE)
            break
    return value


def name_key(value, table=None):
    """§4.6."""
    table = table if table is not None else forms()
    key = clean(value)
    key = re.sub(r"[A-Z]", lambda m: m.group(0).lower(), key)               # ASCII lower case only
    m = re.match(r"^([a-z]+)[" + re.escape(WHITE) + r"]+([0-9])", key)
    if m:
        key = m.group(1) + key[m.end(1):].lstrip(WHITE)                       # white space between the leading letters and the first digit
    for long_form, short_form in table:
        rest = key[len(long_form):]
        if key.startswith(long_form) and rest[:1] != "" and rest[0] in "0123456789":
            return short_form + rest
    return key


def numeric_id(value):
    """§4.7: the integer of a port id of 1 to 10 ASCII digits (after cleaning); 0 and above 2147483647 are not numeric."""
    value = clean(value)
    if not re.fullmatch(r"[0-9]{1,10}", value):
        return None
    number = int(value)
    return number if 0 < number <= 2147483647 else None


def valid_mac(text):
    """§4.1/§4.8: 6 colon-separated hex pairs, not all zero."""
    text = text.lower()
    return bool(re.fullmatch(r"([0-9a-f]{2}:){5}[0-9a-f]{2}", text)) and text != "00:00:00:00:00:00"
