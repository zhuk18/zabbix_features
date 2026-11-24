#!/usr/bin/env python3
"""task 120 section 1 and 2 (model 2.101 §12.3 "Speed mismatch", FR-C V-4, V-25): the warning mark on the line of a link with a speed mismatch, on the real page, 1500 x 900, real mouse events.
The check makes its own Devices (MK-*; the names are used by no scenario) and removes them in a `finally`:
  MK-HUB: links to MK-B (1000 / 100 Mbit/s, up: the mark), to MK-C (the bundle: MK-C has two links to MK-D, p1 1000 / 1000 and p2 1000 / 100, the ports of MK-D are members of the LAG Trk9:
  the bundle has the mark and names p2), to MK-E (a bundle of two equal links to MK-F: no mark), to MK-G (equal speeds: no mark) and to four leaves MK-L1..4 (a leaf group, to be unfolded);
  MK-B and MK-G also have a link to MK-Z, so that they are no leaves.
Checks: the mark is visible with no hover, on the right lines only, the line keeps its channels; a click on it opens the panel of the link; the tooltip of the bundle names the member; no overlap of a mark with a node
box, a label or a mark after the layout, after an unfold and after a drag; the legend row; the short mark in the Links row with both speeds in its hint box.
Screenshots when SHOTS_DIR is set: 120-mark.png, 120-mark-bundle.png. Needs ZBX_UI_USER, ZBX_UI_PASSWORD, google-chrome, the database login of the harness. Exit code 1 when a check fails."""
import atexit, base64, json, os, subprocess, sys, time
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import page_shot as P

if not os.environ.get('ZBX_UI_USER') or not os.environ.get('ZBX_UI_PASSWORD'):
    print('ZBX_UI_USER and ZBX_UI_PASSWORD are needed')
    sys.exit(2)
cookie = P.login()
failed = 0


def check(condition, text, detail=None):
    global failed
    print(('  ok: ' if condition else '  FAIL: ') + text + ('' if condition else ' ' + json.dumps(detail)))
    failed += 0 if condition else 1


def db(statement):
    return subprocess.run(['mysql', '-N', '-uzabbix', 'zabbix', '-e', statement], capture_output=True, text=True).stdout.strip()


def sql_json(**values):
    return json.dumps(values).replace('\\', '\\\\').replace("'", "''")


def last(text):
    return text.split('\n')[-1]


devices = {}


def device(name):
    if name not in devices:
        devices[name] = last(db("INSERT INTO topo_nodes (type, attrs, created_at, updated_at) VALUES ('device', '%s', 0, 0); SELECT LAST_INSERT_ID();" % sql_json(sysname=name)))
    return devices[name]


def port(name, port_name, speed, status='up', if_index=1, lag=None, if_type='physical'):
    attrs = dict(if_index=if_index, name=port_name, pseudo=False, if_type=if_type, admin_status='up', oper_status=status, speed=str(speed))
    return last(db("INSERT INTO topo_nodes (type, device_id, lag_id, attrs, created_at, updated_at) VALUES ('port', %s, %s, '%s', 0, 0); SELECT LAST_INSERT_ID();"
                   % (device(name), lag if lag else 'NULL', sql_json(**attrs))))


def link(a, b):
    return last(db("INSERT INTO topo_edges (type, src_id, dst_id, attrs, created_at) VALUES ('physical_link', %s, %s, '%s', %d); SELECT LAST_INSERT_ID();"
                   % (a, b, sql_json(discovered_via='lldp', last_seen=int(time.time())), int(time.time()))))


def remove_data():
    where = "attrs LIKE '%\"sysname\": \"MK-%' OR attrs LIKE '%\"sysname\":\"MK-%'"
    ids = "SELECT id FROM (SELECT id FROM topo_nodes WHERE type = 'device' AND (%s)) x" % where
    db("DELETE FROM topo_edges WHERE src_id IN (SELECT id FROM topo_nodes WHERE type = 'port' AND device_id IN (%s)) OR dst_id IN (SELECT id FROM topo_nodes WHERE type = 'port' AND device_id IN (%s))" % (ids, ids))
    db("UPDATE topo_nodes SET lag_id = NULL WHERE type = 'port' AND device_id IN (%s)" % ids)
    db("DELETE FROM topo_nodes WHERE type = 'port' AND device_id IN (%s)" % ids)
    db("DELETE FROM topo_nodes WHERE type = 'device' AND (%s)" % where)


def left_over():
    return db("SELECT COUNT(*) FROM topo_nodes WHERE attrs LIKE '%MK-%'")


remove_data()
atexit.register(remove_data)
links = {}
links['mismatch'] = link(port('MK-HUB', 'ge-1', 1000, if_index=1), port('MK-B', 'ge-1', 100))
links['equal'] = link(port('MK-HUB', 'ge-2', 1000, if_index=2), port('MK-G', 'ge-1', 1000))
link(port('MK-B', 'ge-2', 1000, if_index=2), port('MK-Z', 'ge-1', 1000))
link(port('MK-G', 'ge-2', 1000, if_index=2), port('MK-Z', 'ge-2', 1000, if_index=2))      # MK-G is no leaf (a leaf is drawn in the group)
# the bundles: the aggregate Trk9 of MK-D and Trk8 of MK-F (a port of the type "lag"), the members point at it
trk_d = port('MK-D', 'Trk9', 2000, if_index=90, if_type='lag')
trk_f = port('MK-F', 'Trk8', 2000, if_index=80, if_type='lag')
link(port('MK-HUB', 'ge-3', 1000, if_index=3), port('MK-C', 'ge-1', 1000))
links['b1'] = link(port('MK-C', 'p1', 1000, if_index=11), port('MK-D', 'p1', 1000, if_index=11, lag=trk_d))
links['b2'] = link(port('MK-C', 'p2', 1000, if_index=12), port('MK-D', 'p2', 100, if_index=12, lag=trk_d))
link(port('MK-HUB', 'ge-4', 1000, if_index=4), port('MK-E', 'ge-1', 1000))
links['e1'] = link(port('MK-E', 'p1', 1000, if_index=11), port('MK-F', 'p1', 1000, if_index=11, lag=trk_f))
links['e2'] = link(port('MK-E', 'p2', 1000, if_index=12), port('MK-F', 'p2', 1000, if_index=12, lag=trk_f))
for i in range(1, 5):
    link(port('MK-HUB', 'ge-1%d' % i, 1000, if_index=10 + i), port('MK-L%d' % i, 'ge-1', 1000))
chrome, ws, js = P.open_page(cookie, 'mark', 9860)


def mouse(kind, x, y):
    ws.call('Input.dispatchMouseEvent', type=kind, x=x, y=y, button='left' if kind != 'mouseMoved' else 'none', buttons=1 if kind == 'mousePressed' else 0, clickCount=1)


def hover(x, y):
    ws.call('Input.dispatchMouseEvent', type='mouseMoved', x=x, y=y, button='none', buttons=0)


def shot(name, around=None):
    folder = os.environ.get('SHOTS_DIR')
    if folder:
        params = dict(format='png')
        if around:
            params['clip'] = {'x': max(0, around['x'] - 330), 'y': max(0, around['y'] - 200), 'width': 660, 'height': 400, 'scale': 1.6}
        with open(os.path.join(folder, name), 'wb') as f:
            f.write(base64.b64decode(ws.call('Page.captureScreenshot', **params)['data']))


def marks():
    """The drawn marks: [{link, x, y, w, h}] (the screen box)."""
    return js("""Array.from(document.querySelectorAll('#topology-canvas g.topology-speed-mark')).map(g => { const b = g.getBoundingClientRect();
        return {link: g.getAttribute('data-link'), x: b.left + b.width / 2, y: b.top + b.height / 2, w: b.width, h: b.height}; })""")


def mark_of(*names):
    """The mark of the line between two nodes (by label), or None."""
    ids = js("(() => { const by = n => String(Array.from(view.nodes.values()).find(x => x.label === n).id); return %s.map(by); })()" % json.dumps(list(names)))
    for m in marks():
        if sorted(m['link'].split('>')) == sorted(ids):
            return m
    return None


def line_style(a, b):
    return js("""(() => { const by = n => String(Array.from(view.nodes.values()).find(x => x.label === n).id);
        const link = view.drawn_links.find(l => [String(l.source.id), String(l.target.id)].sort().join() === [by(%s), by(%s)].sort().join());
        const path = view.link_selection.nodes().find(n => n.__data__ === link); const s = getComputedStyle(path);
        return {stroke: s.stroke, width: s.strokeWidth, dash: s.strokeDasharray, opacity: s.opacity, type: link.type}; })()""" % (json.dumps(a), json.dumps(b)))


def overlaps():
    r = js(P.CHECKS['overlap'])
    return {'overlaps': r['overlaps'], 'bundle_label_overlaps': r['bundle_label_overlaps'], 'mark_overlaps': r['mark_overlaps'], 'mark_places': r['mark_places'], 'speed_marks': r['speed_marks'],
            'lines_through_nodes': r['lines_through_nodes']}


def overlap_ok(tag, expected_marks=2):
    r = overlaps()
    check(r['speed_marks'] == expected_marks and not r['overlaps'] and not r['bundle_label_overlaps'] and not r['mark_overlaps'] and not r['mark_places'],
          'overlap check %s: %d marks, none on a node box, a label or another mark, every mark has a free point' % (tag, expected_marks), r)


def select(label):
    js("(async () => { const n = Array.from(view.nodes.values()).find(n => n.label === %s); await view.selectNode(n); return 1; })()" % json.dumps(label))
    P.idle(js)


def panel_text():
    return js("document.getElementById('topology-details').textContent")


def pan_to(label):
    """The graph is larger than the window: the node is brought to the middle of the graph area (the pan of the search)."""
    js("view.panToNode(String(Array.from(view.nodes.values()).find(n => n.label === %s).id))" % json.dumps(label))
    P.idle(js)


def center(label):
    return js("(() => { const n = view.drawn_nodes.find(n => n.label === %s); const b = n.element.getBoundingClientRect(); return {x: b.left + b.width / 2, y: b.top + b.height / 2}; })()" % json.dumps(label))


try:
    P.wait_state(ws, js, "Array.from(view.nodes.values()).some(n => n.label === 'MK-HUB')", 'the Devices of the check are in the view')
    js("view.load_all = 1")
    mm = js("view.speedMismatchText(1000000000, 100000000)")
    pan_to('MK-C')

    print('-- the mark on the line, with no hover')
    hover(5, 5)
    P.until(js, "!document.querySelector('.hintbox')", 'no hint box')
    m1 = mark_of('MK-HUB', 'MK-B')
    m2 = mark_of('MK-C', 'MK-D')
    check(m1 is not None and m2 is not None and len(marks()) == 2, 'two marks are drawn: the link HUB-B and the bundle C-D', marks())
    check(mark_of('MK-HUB', 'MK-G') is None and mark_of('MK-E', 'MK-F') is None and mark_of('MK-HUB', 'MK-L1') is None, 'no mark on the link with equal speeds, the bundle with equal members and a leaf link')
    check(m1 is not None and m1['w'] >= 10 and m1['h'] >= 10 and js("!document.querySelector('.hintbox')"), 'the mark has its size with no hover and no hint box', m1)
    # at the middle of the line (between 30 % and 70 %)
    u = js("(view.speed_mark_places || []).map(p => p.u)")
    check(all(30 <= x <= 70 for x in u) and len(u) == 2, 'each mark is between 30 % and 70 % of its line', u)
    plain = line_style('MK-HUB', 'MK-G')
    marked = line_style('MK-HUB', 'MK-B')
    check(plain['stroke'] == marked['stroke'] and plain['width'] == marked['width'] and plain['dash'] == marked['dash'] and plain['opacity'] == marked['opacity'] and plain['type'] == marked['type'],
          'the line keeps its colour, width, dash and opacity (the same as a line with equal speeds)', [plain, marked])
    bundle_plain = line_style('MK-E', 'MK-F')
    bundle_marked = line_style('MK-C', 'MK-D')
    check(bundle_marked['type'] == 'lag_bundle' and bundle_plain['stroke'] == bundle_marked['stroke'] and bundle_plain['width'] == bundle_marked['width'] and bundle_plain['dash'] == bundle_marked['dash'],
          'the bundle line keeps its channels', [bundle_plain, bundle_marked])
    overlap_ok('after the layout')
    shot('120-mark.png', {'x': m1['x'], 'y': m1['y']})
    # the bundle: the mark is next to the label, never on it
    label = js("(() => { const t = Array.from(document.querySelectorAll('#topology-canvas text.topology-lag-label')).find(t => t.textContent.startsWith('Trk9')); const b = t.getBoundingClientRect(); return {l: b.left, r: b.right, t: b.top, b: b.bottom}; })()")
    check(not (m2['x'] - m2['w'] / 2 < label['r'] and label['l'] < m2['x'] + m2['w'] / 2 and m2['y'] - m2['h'] / 2 < label['b'] and label['t'] < m2['y'] + m2['h'] / 2), 'the mark of the bundle is next to its label, not on it', [m2, label])
    shot('120-mark-bundle.png', {'x': m2['x'], 'y': m2['y']})

    print('-- the tooltip of the bundle names the member')
    hover(5, 5)
    hover(m2['x'] - 2, m2['y'] - 2)
    hover(m2['x'], m2['y'])
    P.until(js, "document.querySelector('.hintbox')", 'the hint box of the mark of the bundle')
    hint = js("document.querySelector('.hintbox').textContent")
    check('p2 ↔ p2' in hint and mm in hint and 'p1 ↔ p1' in hint, 'the tooltip lists the members and names p2 ↔ p2 with the mark', hint)
    hover(5, 5)

    print('-- a click on the mark opens the panel of the link')
    mouse('mousePressed', m1['x'], m1['y'])
    mouse('mouseReleased', m1['x'], m1['y'])
    P.idle(js)
    text = panel_text()
    check('MK-HUB' in text and 'MK-B' in text and mm in text, 'the click on the mark of HUB-B opens its panel with the mark', text[:300])
    hover(5, 5)
    mouse('mousePressed', m2['x'], m2['y'])
    mouse('mouseReleased', m2['x'], m2['y'])
    P.idle(js)
    text = panel_text()
    check('MK-C' in text and 'MK-D' in text and 'p2' in text, 'the click on the mark of the bundle opens the panel of the bundle', text[:300])

    print('-- the Links row: the short mark, both speeds in the hint box')
    ROWS = """Array.from(document.querySelectorAll('#topology-details .topology-link-row')).map(r => { const l2 = r.querySelector('.topology-link-line2'); const mark = l2.querySelector('.topology-link-mark');
        return {line1: r.querySelector('.topology-link-line1').textContent.trim(), text: l2.textContent, mark: mark ? mark.textContent : null, hint: mark ? (mark.getAttribute('title') || mark.dataset.hintboxHtml || '') : null}; })"""
    select('MK-HUB')
    rows = js(ROWS)
    bad = [r for r in rows if r['mark'] is not None]
    check(len(bad) == 1 and bad[0]['line1'] == 'MK-B' and bad[0]['mark'] == 'speed mismatch', 'the Links row of MK-HUB: the short mark "speed mismatch" on the row of MK-B only', rows)
    check(mm not in bad[0]['text'] and mm in (bad[0]['hint'] or ''), 'the row has no speeds; the hint box has "%s"' % mm, bad)
    select('MK-C')
    rows = js(ROWS)
    bad = [r for r in rows if r['mark'] is not None]
    bundle_row = [r for r in bad if r['text'].startswith('Trk9')]
    check(len(bundle_row) == 1 and mm not in bundle_row[0]['text'] and 'p2 ↔ p2' in bundle_row[0]['hint'] and mm in bundle_row[0]['hint'], 'the bundle row of MK-C has the short mark, and its hint box names the member p2', rows)
    check(len(bad) == 2 and all(r['mark'] == 'speed mismatch' for r in bad) and not any(r['mark'] for r in rows if r['text'].startswith('ge-') or r['text'].startswith('p1')), 'only the bundle row and the row of its member p2 have the mark', rows)
    select('MK-E')
    check(all(r['mark'] is None for r in js(ROWS)), 'no mark in the rows of MK-E (a bundle with equal members)')

    print('-- the legend')
    legend = js("document.getElementById('topology-legend').textContent")
    check('Speed mismatch: the data is wrong, or a device that is not on the graph is between the ports' in legend, 'the legend has the row of the mark', legend[-200:])
    check(js("document.querySelectorAll('#topology-legend .topology-legend-row svg path[style*=\"f59e0b\"]').length") == 1, 'the legend swatch is the mark (one row)')

    print('-- unfold and drag')
    group = js("view.leaf_groups.map(g => String(g.parent))")
    check(len(group) >= 1, 'MK-HUB has a leaf group', group)
    js("view.unfoldGroup(%s)" % json.dumps(group[0]))
    P.idle(js)
    overlap_ok('after the unfold')
    # a real drag of MK-B across the graph
    pan_to('MK-B')
    start = center('MK-B')
    mouse('mouseMoved', start['x'], start['y'])
    mouse('mousePressed', start['x'], start['y'])
    for step in range(1, 9):
        mouse('mouseMoved', start['x'] + 14 * step, start['y'] + 9 * step)
    mouse('mouseReleased', start['x'] + 112, start['y'] + 72)
    P.idle(js)
    moved = center('MK-B')
    check(abs(moved['x'] - start['x']) > 20 or abs(moved['y'] - start['y']) > 20, 'MK-B was dragged', [start, moved])
    overlap_ok('after the drag')
    # a drag of MK-D (an end of the bundle)
    pan_to('MK-D')
    start = center('MK-D')
    mouse('mouseMoved', start['x'], start['y'])
    mouse('mousePressed', start['x'], start['y'])
    for step in range(1, 9):
        mouse('mouseMoved', start['x'] - 12 * step, start['y'] + 10 * step)
    mouse('mouseReleased', start['x'] - 96, start['y'] + 80)
    P.idle(js)
    overlap_ok('after the drag of an end of the bundle')
finally:
    chrome.close()
    remove_data()
left = left_over()
check(left == '0', 'nothing of the check is left in the database', left)
print('All speed mark checks passed.' if not failed else '%d check(s) FAILED.' % failed)
sys.exit(1 if failed else 0)
