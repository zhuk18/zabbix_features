#!/usr/bin/env python3
"""task 119 sections 3 and 4 (model 2.100 §12.3 "Speed mismatch", FR-C V-25; §12.5 "Port alias", FR-C V-14): the mark "speed mismatch: <A> / <B>" in the tooltip, the link panel and the Links row,
and the port alias after the port name (grey, cut, the full text in the hint box; escaped), on the real page, 1500 x 900, real mouse events.
The check makes its own Devices (MM-A .. MM-F, with their ports and links; the names are not used by any scenario) and removes them in a `finally`:
  MM-A ge-1 (1000 Mbit/s, up, alias "uplink to core") - MM-B ge-2 (100 Mbit/s, up, alias with markup): the mark;
  MM-C - MM-D: equal speeds: no mark; MM-E (up) - MM-F (down), speeds differ: no mark; MM-G - MM-H: the port of MM-G has an alias of 90 characters and a port name of 40 characters: the alias is cut,
  the port name is not, the full text is in the hint box.
Screenshots when SHOTS_DIR is set: 119-speed-mismatch.png, 119-alias.png. Needs ZBX_UI_USER, ZBX_UI_PASSWORD, google-chrome, the office stand data and the database login of the harness.
Exit code 1 when a check fails."""
import atexit, json, os, subprocess, sys, time
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import page_shot as P

if not os.environ.get('ZBX_UI_USER') or not os.environ.get('ZBX_UI_PASSWORD'):
    print('ZBX_UI_USER and ZBX_UI_PASSWORD are needed')
    sys.exit(2)
cookie = P.login()
failed = 0
HTML_ALIAS = '<b>x</b> & "q"'
LONG_ALIAS = 'uplink to the core switch of the ninth floor, patch panel 14, cable 207, do not move without a ticket'
LONG_PORT = 'GigabitEthernet1/0/24-uplink-to-core-1'


def check(condition, text, detail=None):
    global failed
    print(('  ok: ' if condition else '  FAIL: ') + text + ('' if condition else ' ' + json.dumps(detail)))
    failed += 0 if condition else 1


def db(statement):
    return subprocess.run(['mysql', '-N', '-uzabbix', 'zabbix', '-e', statement], capture_output=True, text=True).stdout.strip()


def sql_json(**values):
    return json.dumps(values).replace('\\', '\\\\').replace("'", "''")


def make_pair(left, right):
    """Two Devices with one port each and a physical link; `left`/`right` = (device name, port name, speed in Mbit/s, oper_status, alias). Returns the link id."""
    ports = []
    for name, port, speed, status, alias in (left, right):
        device = db("INSERT INTO topo_nodes (type, attrs, created_at, updated_at) VALUES ('device', '%s', 0, 0); SELECT LAST_INSERT_ID();" % sql_json(sysname=name)).split('\n')[-1]
        attrs = dict(if_index=1, name=port, pseudo=False, if_type='physical', admin_status='up', oper_status=status, speed=str(speed))
        if alias:
            attrs['alias'] = alias
        ports.append(db("INSERT INTO topo_nodes (type, device_id, attrs, created_at, updated_at) VALUES ('port', %s, '%s', 0, 0); SELECT LAST_INSERT_ID();" % (device, sql_json(**attrs))).split('\n')[-1])
    return db("INSERT INTO topo_edges (type, src_id, dst_id, attrs, created_at) VALUES ('physical_link', %s, %s, '%s', %d); SELECT LAST_INSERT_ID();" % (ports[0], ports[1], sql_json(discovered_via='lldp', last_seen=int(time.time())), int(time.time()))).split('\n')[-1]


def remove_data():
    where = "attrs LIKE '%\"sysname\": \"MM-%' OR attrs LIKE '%\"sysname\":\"MM-%'"
    db("DELETE FROM topo_edges WHERE src_id IN (SELECT id FROM topo_nodes WHERE type = 'port' AND device_id IN (SELECT id FROM (SELECT id FROM topo_nodes WHERE type = 'device' AND (%s)) x))" % where)
    db("DELETE FROM topo_nodes WHERE type = 'port' AND device_id IN (SELECT id FROM (SELECT id FROM topo_nodes WHERE type = 'device' AND (%s)) x)" % where)
    db("DELETE FROM topo_nodes WHERE type = 'device' AND (%s)" % where)


def left_over():
    return db("SELECT COUNT(*) FROM topo_nodes WHERE attrs LIKE '%MM-%'")


remove_data()
atexit.register(remove_data)
links = {
    'mismatch': make_pair(('MM-A', 'ge-1', 1000, 'up', 'uplink to core'), ('MM-B', 'ge-2', 100, 'up', HTML_ALIAS)),
    'equal': make_pair(('MM-C', 'ge-1', 1000, 'up', None), ('MM-D', 'ge-1', 1000, 'up', None)),
    'down': make_pair(('MM-E', 'ge-1', 1000, 'up', None), ('MM-F', 'ge-1', 100, 'down', None)),
    'long': make_pair(('MM-G', LONG_PORT, 1000, 'up', LONG_ALIAS), ('MM-H', 'ge-1', 1000, 'up', None)),
}
# a port of MM-A that has no link: the Ports section lists the ports that are not connected, with the alias
db("INSERT INTO topo_nodes (type, device_id, attrs, created_at, updated_at) SELECT 'port', id, '%s', 0, 0 FROM topo_nodes WHERE type = 'device' AND attrs LIKE '%%\"MM-A\"%%'"
   % sql_json(if_index=7, name='ge-7', pseudo=False, if_type='physical', admin_status='up', oper_status='up', speed='1000', alias='spare uplink'))
chrome, ws, js = P.open_page(cookie, 'speed', 9850)


def mouse(kind, x, y):
    ws.call('Input.dispatchMouseEvent', type=kind, x=x, y=y, button='left' if kind != 'mouseMoved' else 'none', buttons=1 if kind == 'mousePressed' else 0, clickCount=1)


def hover(x, y):
    ws.call('Input.dispatchMouseEvent', type='mouseMoved', x=x, y=y, button='none', buttons=0)


def shot(name):
    folder = os.environ.get('SHOTS_DIR')
    if folder:
        import base64
        with open(os.path.join(folder, name), 'wb') as f:
            f.write(base64.b64decode(ws.call('Page.captureScreenshot', format='png')['data']))


def entry(edge_id):
    return "view.line_entries.find(e => e.link && String(e.link.edge_id) === '%s')" % edge_id


def hint_of(edge_id):
    """Hover the middle of the line of a link and read the hint box (real mouse events)."""
    hover(5, 5)
    P.until(js, "!document.querySelector('.hintbox')", 'no hint box before the hover')
    # the line is brought to the middle of the graph area (the pan of the search: the graph is larger than the window)
    js("(() => { const e = %s; view.panToNode(String(e.link.source.id ?? e.link.source)); })()" % entry(edge_id))
    point = js("""(() => { const e = %s; const p = e.hit.getPointAtLength(e.hit.getTotalLength() * 0.5); const t = new DOMPoint(p.x, p.y).matrixTransform(e.hit.getScreenCTM()); return {x: t.x, y: t.y}; })()""" % entry(edge_id))
    hover(point['x'] - 3, point['y'] - 3)
    hover(point['x'], point['y'])
    P.until(js, "document.querySelector('.hintbox')", 'the hint box of the line is shown')
    return js("(() => { const h = document.querySelector('.hintbox'); return {text: h.textContent, divs: Array.from(h.querySelectorAll('.hintbox-container > div')).map(d => d.textContent), html: h.innerHTML}; })()")


def select(label):
    js("(async () => { const n = Array.from(view.nodes.values()).find(n => n.label === %s); await view.selectNode(n); return 1; })()" % json.dumps(label))
    P.idle(js)


def rows_text():
    """The text of the Links rows of the open panel: [[line1, line2 text, line2 html]]."""
    return js("""(() => Array.from(document.querySelectorAll('#topology-details .topology-link-row')).map(r => [r.querySelector('.topology-link-line1').textContent.trim(), r.querySelector('.topology-link-line2').textContent.trim(), r.querySelector('.topology-link-line2').innerHTML]))()""")


try:
    expected = js("view.speedMismatchText(1000000000, 100000000)")
    check(expected.startswith('speed mismatch: ') and ' / ' in expected, 'the mark text: "speed mismatch: <speed A> / <speed B>" with the unit of the load', expected)
    P.wait_state(ws, js, "Array.from(view.nodes.values()).some(n => n.label === 'MM-A')", 'the Devices of the check are in the view (they have a link)')
    js("view.load_all = 1")

    print('-- the tooltip')
    hint = hint_of(links['mismatch'])
    check('MM-A ge-1 · uplink to core ↔ MM-B ge-2 · ' + HTML_ALIAS in hint['divs'][0], 'the tooltip: the alias follows the port name, as text ("MM-A ge-1 · uplink to core ↔ MM-B ge-2 · <b>x</b> ...")', hint['divs'])
    check('<b>' not in hint['html'].replace('&lt;b&gt;', ''), 'the markup of the alias is escaped in the hint box', hint['html'][:300])
    check(any(d == expected for d in hint['divs']), 'the tooltip has the mark "speed mismatch: 1 Gbit/s / 100 Mbit/s"', hint['divs'])
    hover(5, 5)
    for name in ('equal', 'down'):
        hint = hint_of(links[name])
        check(not any('speed mismatch' in d for d in hint['divs']), 'no mark in the tooltip of the link with %s' % ('equal speeds' if name == 'equal' else 'one end down'), hint['divs'])
    hover(5, 5)

    print('-- the Links row and the Ports section')
    select('MM-A')
    rows = rows_text()
    check(len(rows) == 1 and expected in rows[0][1], 'the Links row of MM-A has the mark', rows)
    check('ge-1 · uplink to core' in rows[0][1] and 'ge-2 · ' + HTML_ALIAS in rows[0][1], 'the row: "ge-1 · uplink to core ↔ ge-2 · <b>x</b> ..." (the alias after the port name, as text)', rows[0][1])
    check('<span class="grey topology-port-alias"' in rows[0][2] and '<b>' not in rows[0][2], 'the alias is grey and escaped (no element made of the markup)', rows[0][2][:300])
    ports_cell = js("(Array.from(document.querySelectorAll('#topology-details .topology-port-name')).map(c => c.textContent.trim()))")
    check(any(text.startswith('ge-7 · spare uplink') for text in ports_cell), 'the Ports section shows "ge-7 · spare uplink" (a port with no link)', ports_cell)
    shot('119-alias.png')
    select('MM-C')
    rows = rows_text()
    check(len(rows) == 1 and 'speed mismatch' not in rows[0][1], 'no mark in the Links row with equal speeds', rows)
    select('MM-E')
    rows = rows_text()
    check(len(rows) == 1 and 'speed mismatch' not in rows[0][1], 'no mark in the Links row when one end is down', rows)

    print('-- the link panel')
    js("(async () => { const l = view.links.find(l => String(l.edge_id) === '%s'); await view.selectLink(view.resolvedLink(l)); return 1; })()" % links['mismatch'])
    P.idle(js)
    panel = js("document.getElementById('topology-details').textContent")
    check(expected in panel, 'the link panel has the mark', panel[:400])
    shot('119-speed-mismatch.png')
    js("(async () => { const l = view.links.find(l => String(l.edge_id) === '%s'); await view.selectLink(view.resolvedLink(l)); return 1; })()" % links['equal'])
    P.idle(js)
    check('speed mismatch' not in js("document.getElementById('topology-details').textContent"), 'no mark in the link panel with equal speeds')

    print('-- a long alias is cut, the port name is not')
    select('MM-G')
    rows = rows_text()
    info = js("""(() => { const r = document.querySelector('#topology-details .topology-link-row'); const alias = r.querySelector('.topology-port-alias'); const ports = r.querySelector('.topology-link-ports');
        return {alias_text: alias ? alias.textContent : null, alias_title: alias ? alias.getAttribute('title') || alias.dataset.hintboxHtml || '' : null, ports_text: ports.textContent, ports_title: ports.getAttribute('title') || ports.dataset.hintboxHtml || ''}; })()""")
    check(info['alias_text'] is not None and info['alias_text'] != '· ' + LONG_ALIAS and '…' in info['alias_text'], 'the alias is cut in the middle', info)
    check(LONG_ALIAS in (info['alias_title'] or ''), 'the full alias is in the hint box', info)
    check(LONG_PORT in info['ports_text'], 'the port name is not cut', info)
finally:
    chrome.close()
    remove_data()
left = left_over()
check(left == '0', 'nothing of the check is left in the database', left)
print('All speed mismatch and alias checks passed.' if not failed else '%d check(s) FAILED.' % failed)
sys.exit(1 if failed else 0)
