#!/usr/bin/env python3
"""task 094 §6 (model §12.5 "Structure of a node panel", 2.75): the side panel of a node on the real page, 1500 x 900, office stand.

SW12 (Host) and zbx-office-gw1 (Host): the sections in the order Problems, Links, Ports, Identity (folded); no "Device details" on any node panel; the header has the class icon, the name, the kind
mark and Focus, Neighbor reports and the menu; no button or link on a row of the Links section but the neighbor, the toggle of a bundle and the row menu; one line per row; the name of a Host opens
the standard Zabbix host menu; Focus sets the filter; a hover on a row highlights its line and a hover on a line marks its row (DevTools Input mouse events); Unlink asks for a confirmation (cancelled).
A FortiAP Device without association: Identity (Addresses open), Links, Ports. A Proxy with a Device: the stand has none, the order is covered by the node tests.
Needs ZBX_UI_USER, ZBX_UI_PASSWORD, google-chrome and the office stand data. Exit code 1 when a check fails.
"""
import atexit, shutil, json, math, os, subprocess, sys, tempfile, time, urllib.request
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


def session():
    chrome, ws, js = P.open_page(cookie, 'panel', 9700)
    return chrome, ws, js


def mouse(ws, x, y):
    ws.call('Input.dispatchMouseEvent', type='mouseMoved', x=x, y=y, button='none', buttons=0)


def select(js, label, kind=None):
    js("""(async () => { const n = Array.from(view.nodes.values()).find(n => n.label === %s && (%s)); await view.selectNode(n); return 1; })()""" % (json.dumps(label), ("n.type === '%s'" % kind) if kind else 'true'))
    P.after_input(js)


PANEL = """(() => {
    const d = document.getElementById('topology-details');
    const title = document.getElementById('topology-details-title');
    const sections = Array.from(d.querySelectorAll(':scope > details.topology-section')).map(e => e.className.replace('topology-section', '').replace('topology-', '').trim() + (e.open ? '+' : '-'));
    const rows = Array.from(d.querySelectorAll('.topology-links-table tr.topology-link-row')).filter(r => !r.hidden);
    const row_controls = rows.map(r => Array.from(r.querySelectorAll('button, a')).filter(e => !e.closest('.topology-menu-list')
        && !e.classList.contains('topology-menu-button') && !e.classList.contains('topology-bundle-toggle') && !e.classList.contains('topology-neighbor')).length);
    const names = rows.map(r => { const n = r.querySelector('.topology-neighbor'); return [n.textContent, n.getAttribute('title')]; });
    const line2 = rows.map(r => r.querySelector('.topology-link-line2').textContent);
    return {sections, title_visible: !title.hidden && title.offsetHeight > 0, title_text: title.textContent, has_device_details: d.textContent.includes('Device details'),
        name: (d.querySelector('.topology-node-name') || {textContent: ''}).textContent.trim(), icon: !!d.querySelector('.topology-node-icon'), kind: (d.querySelector('.topology-node-kind') || {textContent: ''}).textContent,
        actions: Array.from(d.querySelectorAll('.topology-node-actions > *')).map(e => e.classList.contains('topology-menu') ? e.querySelector('.topology-menu-button').textContent : (e.textContent || '').trim()),
        summary: (d.querySelector('.topology-node-summary') || {textContent: ''}).textContent,
        row_controls: row_controls.filter(n => n > 0).length, rows: rows.length, row_heights: rows.map(r => Math.round(r.getBoundingClientRect().height)), names, line2,
        line_counts: rows.map(r => r.querySelectorAll('.topology-link-line1, .topology-link-line2').length),
        row_overflow: rows.filter(r => Array.from(r.querySelectorAll('.topology-link-line1, .topology-link-line2')).some(c => c.scrollWidth > c.clientWidth + 1)).length,
        panel_width: Math.round(d.getBoundingClientRect().width)};
})()"""

chrome, ws, js = session()
try:
    print('=== SW12 (Host) ===')
    select(js, 'SW12-10-CORE-6000-48G', 'host')
    r = js(PANEL)
    print('  ', json.dumps(r))
    check(r['sections'] == ['links+', 'ports+', 'identity-'], 'sections: Links, Ports, Identity (folded); no Problems section (task 117 section 6)', r['sections'])
    check(not r['title_visible'] and not r['has_device_details'], 'no "Device details" on the node panel', [r['title_text'], r['title_visible']])
    check(r['icon'] and r['name'].startswith('SW12-10-CORE-6000-48G') and 'Zabbix host' in r['name'], 'header: class icon, name, kind mark', r['name'])
    check(r['actions'] == [], 'the header has no action row: Focus, Neighbor reports and the menu are in the menu of the label (task 117 section 3)', r['actions'])
    check(r['summary'].startswith('11 links to 7 neighbors · 58 ports (30 up)'), 'summary line "<N> links to <M> neighbors · <P> ports (<U> up)" (and the problem counters after it)', r['summary'])
    check(r['rows'] >= 5 and r['row_controls'] == 0, 'no button or link on a Links row but the neighbor and the toggle', [r['rows'], r['row_controls']])
    check(all(n == 2 for n in r['line_counts']) and all(h <= 70 for h in r['row_heights']) and r['row_overflow'] == 0, 'a row has two lines (line 1: neighbor; line 2: ports, status, source, load) and nothing is wider than the panel', [r['line_counts'], r['row_heights'], r['row_overflow']])
    check(all(shown == full for shown, full in r['names']) and len(set(f for _, f in r['names'])) == len(r['names']), 'every neighbor label is shown in full (no cut) and the neighbors can be told apart', r['names'])
    check(any(t.startswith('core-lag ×2') for t in r['line2']) and any(t.startswith('Trk1 ×2') for t in r['line2']), 'a bundle row shows "core-lag ×2" and "Trk1 ×2" in full in line 2', r['line2'])
    bundles = js("Array.from(document.querySelectorAll('#topology-details tr.topology-link-bundle')).map(r => [r.querySelector('.topology-link-line2').textContent, Math.round(r.querySelector('.topology-link-line2').getBoundingClientRect().height), Math.round(r.getBoundingClientRect().height)])")
    check(len(bundles) == 4 and all(b[1] <= 16 and b[2] <= 52 and ' ↔ ' not in b[0] for b in bundles), '2.78: a bundle row has two text lines (line 2 does not wrap) and no ports in line 2', bundles)
    check(all(' in ' in t or t.endswith('—') or '%' in t for t in r['line2']), 'line 2 has the load "in x % / out y %"', r['line2'])
    # the Links section replaces the group Connected; the Ports section has the others, folded
    groups = js("Array.from(document.querySelectorAll('#topology-details details.topology-port-group')).map(e => [e.dataset.group, e.open, e.querySelector('summary').textContent.trim()])")
    check(groups and all(not g[1] for g in groups) and 'connected' not in [g[0] for g in groups], 'Ports: the other groups, folded, and no group Connected', groups)
    check(any(g[0] == 'no_neighbor' and 'up' in g[2] and 'down' in g[2] for g in groups) and any(g[0] == 'lag' and g[2].endswith(')') for g in groups), 'the group headers have counts', groups)

    print('=== the host menu (the standard Zabbix one) ===')
    P.node_menu(js, 'SW12-10-CORE-6000-48G', node_type='host')
    menu = js("(() => { const m = document.querySelector('.menu-popup'); return m ? Array.from(m.querySelectorAll('a')).map(a => a.textContent.trim()).filter(Boolean) : null; })()")
    check(menu is not None and len(menu) >= 3, 'the label of the Host opens the standard host menu', menu)
    js("document.body.click()")
    P.after_input(js)

    print('=== a row and its line ===')
    js("document.body.click()")
    row = js("""(() => { const r = Array.from(document.querySelectorAll('#topology-details .topology-links-table tr.topology-link-row')).filter(x => !x.hidden && !x.classList.contains('topology-link-bundle'))[0];
        r.scrollIntoView({block: 'center'}); const b = r.getBoundingClientRect(); return {x: b.left + b.width / 2, y: b.top + b.height / 2, edges: r.dataset.edges}; })()""")
    P.after_input(js)
    row = js("""(() => { const r = Array.from(document.querySelectorAll('#topology-details .topology-links-table tr.topology-link-row')).filter(x => !x.hidden && !x.classList.contains('topology-link-bundle'))[0];
        const b = r.getBoundingClientRect(); return {x: b.left + b.width / 2, y: b.top + b.height / 2, edges: r.dataset.edges}; })()""")
    mouse(ws, row['x'], row['y'])
    P.after_input(js)
    state = js("(() => ({hover: !!view.hover_entry, edge: view.hover_entry ? String(view.hover_entry.link.edge_id) : null, glow: document.querySelectorAll('.topology-link-hover').length, row: document.querySelectorAll('tr.topology-row-hover').length}))()")
    check(state['hover'] and state['edge'] == row['edges'] and state['glow'] >= 1, 'a hover on a row highlights its line', [state, row['edges']])
    mouse(ws, 700, 700)
    P.after_input(js)
    check(js("!view.hover_entry"), 'the pointer leaves the row: no hover on the line')
    # a hover on a line marks its row: the pointer on the line of a row of the open panel (the middle of the line, then other points of it when a node or another line is there)
    ok_hover = False
    tried = []
    for u in (0.5, 0.4, 0.6, 0.3, 0.7):
        point = js("""(u => { const rows = Array.from(document.querySelectorAll('#topology-details .topology-links-table tr.topology-link-row')).filter(r => !r.hidden && r.dataset.edges.indexOf(',') < 0).map(r => r.dataset.edges);
            const e = view.line_entries.find(x => x.link && x.link.type === 'physical_link' && rows.includes(String(x.link.edge_id))); const p = e.hit.getPointAtLength(e.hit.getTotalLength() * u);
            const t = new DOMPoint(p.x, p.y).matrixTransform(e.hit.getScreenCTM()); return {x: t.x, y: t.y, edge: String(e.link.edge_id), u}; })(%s)""" % u)
        mouse(ws, point['x'], point['y'])
        P.after_input(js)
        marked = js("Array.from(document.querySelectorAll('tr.topology-row-hover')).map(r => r.dataset.edges)")
        tried.append([point, marked])
        if point['edge'] in ','.join(marked):
            ok_hover = True
            break
    if not ok_hover:
        # task 100 §4: the evidence of a failure: the screenshot at the moment of the hover and the hovered element, in a folder of the evidence of the overlap checks
        folder = os.path.join(os.environ.get('TOPO_EVIDENCE_DIR', '/tmp/topo_evidence'), '%s-%d-linehover' % (time.strftime('%Y%m%d-%H%M%S'), os.getpid()))
        os.makedirs(folder, exist_ok=True)
        import base64
        with open(os.path.join(folder, 'screenshot.png'), 'wb') as f:
            f.write(base64.b64decode(ws.call('Page.captureScreenshot', format='png')['data']))
        element = js("""(p => { const e = document.elementFromPoint(p.x, p.y); const h = view.hover_entry; return {element: e ? {tag: e.tagName, class: String(e.getAttribute('class')), id: e.id} : null, hover_entry_edge: h && h.link ? String(h.link.edge_id) : null,
            panel_rows: Array.from(document.querySelectorAll('#topology-details tr.topology-link-row')).map(r => [r.dataset.edges, r.hidden, r.className])}; })(%s)""" % json.dumps(point))
        with open(os.path.join(folder, 'evidence.json'), 'w') as f:
            json.dump({'tried': tried, 'hovered': element}, f, indent=1)
        print('evidence: %s' % folder)
    check(ok_hover, 'a hover on a line marks its row in the panel of one of its ends', tried)
    mouse(ws, 700, 700)

    print('=== Unlink asks for a confirmation (the menu of a manual line) ===')
    # a link line of a discovered link has no Unlink; the confirmation of a manual one is checked with the menu item of a device link made for the check (no change of the data: the confirmation is cancelled)
    js("window.__asked = 0; window.confirm = () => { window.__asked++; return false; }")
    items = js("view.lineMenuItems({link: {type: 'device_link', edge_id: 999999, source: {id: '1', name: 'A'}, target: {id: '2', name: 'B'}}, open() {}}).map(i => i.label)")
    check(items == ['Link details', 'Unlink'], 'the menu of a manual line: Link details, Unlink', items)
    js("(() => { const item = view.lineMenuItems({link: {type: 'device_link', edge_id: 999999, source: {id: '1', name: 'A'}, target: {id: '2', name: 'B'}}, open() {}}).find(i => i.label === 'Unlink'); item.clickCallback(); })()")
    P.idle(js)
    check(js("window.__asked") == 1, 'Unlink asks for a confirmation; cancelled, nothing is sent', js("window.__asked"))
    # Link details opens the link panel
    P.line_menu(js, "view.line_entries.find(e => e.link && e.link.type === 'physical_link' && e.menu)", choose='Link details')
    check(js("document.getElementById('topology-details-title').textContent") == 'Link details', 'Link details opens the panel of the link')

    print('=== a stale link has its mark in line 2; the bundle row menu ===')
    def sql(statement):
        return subprocess.run(['mysql', '-N', '-uzabbix', 'zabbix', '-e', statement], capture_output=True, text=True).stdout.strip()
    edge = sql("SELECT e.id, e.attrs FROM topo_edges e JOIN topo_nodes a ON a.id=e.src_id JOIN topo_nodes d ON d.id=a.device_id WHERE e.type='physical_link' AND JSON_UNQUOTE(JSON_EXTRACT(d.attrs,'$.sysname'))='SW12-10-CORE-6000-48G' "
               "AND JSON_EXTRACT(e.attrs,'$.superseded_at') IS NULL LIMIT 1").split('\t')
    try:
        sql("UPDATE topo_edges SET attrs=JSON_SET(attrs,'$.last_seen',1) WHERE id=%s" % edge[0])
        chrome.kill()
        chrome, ws, js = session()
        select(js, 'SW12-10-CORE-6000-48G', 'host')
        marks = js("Array.from(document.querySelectorAll('#topology-details .topology-link-row')).filter(r => r.querySelector('.topology-link-line2 .topology-link-mark')).map(r => r.querySelector('.topology-link-line2').textContent)")
        check(len(marks) >= 1 and all('not confirmed since' in m for m in marks), 'a stale link has its mark "not confirmed since <time>" in line 2', marks)
    finally:
        sql("UPDATE topo_edges SET attrs='%s' WHERE id=%s" % (edge[1].replace("'", "''"), edge[0]))
    P.line_menu(js, "view.line_entries.find(e => e.link && e.link.type === 'lag_bundle')", choose='Link details')
    check('LAG' in js("document.getElementById('topology-details').textContent") or '↔' in js("document.querySelector('#topology-details h2').textContent"), 'Link details of a bundle line opens the bundle panel')
    select(js, 'SW12-10-CORE-6000-48G', 'host')

    print('=== zbx-office-gw1 (Host) ===')
    select(js, 'zbx-office-gw1', 'host')
    r = js(PANEL)
    check(r['sections'] == ['links+', 'ports+', 'identity-'] and not r['has_device_details'], 'gw1: sections in order, no "Device details"', r['sections'])
    print('=== a FortiAP Device without association ===')
    js("(async () => { const n = Array.from(view.nodes.values()).find(n => n.type === 'device' && String(n.label).startsWith('FortiAP')); await view.selectNode(n); return n.label; })()")
    P.after_input(js)
    r = js(PANEL)
    print('  ', json.dumps(r))
    check(r['sections'][:2] == ['identity+', 'links+'] and r['sections'][2:] in ([], ['ports+']) and not r['has_device_details'], 'FortiAP: Identity, Links, Ports (Ports only when it has a group besides Connected)', r['sections'])
    check('Not a Zabbix host' in r['name'], 'kind mark "Not a Zabbix host"', r['name'])
    check(js("document.querySelector('#topology-details details.topology-addresses').open"), 'the Addresses are open inside Identity')
    print('=== Focus (the menu of the label) ===')
    menu = P.node_menu(js, 'SW13-10-ACC-6000-48G', node_type='host')
    check('Focus' in menu, 'no Focus host yet: Focus is in the menu', menu)
    js("document.body.click()")
    P.node_menu(js, 'SW12-10-CORE-6000-48G', choose='Focus', node_type='host')
    P.until(js, "view.applied_hostid !== null && view.idle", 'the filter is applied with the Focus host')
    menu = P.node_menu(js, 'SW12-10-CORE-6000-48G', node_type='host')
    check('Focus' not in menu.replace('Topology', '').split('Topology')[-1][:0] and not any(w == 'Focus' for w in menu.split(' ')), 'SW12 is the Focus host: no Focus in its menu', menu)
    js("document.body.click()")
    menu = P.node_menu(js, 'SW13-10-ACC-6000-48G', node_type='host')
    check('Focus' in menu, 'SW13 in the same view: Focus is in its menu', menu)
    js("document.body.click()")
    hostid = js("(() => { const n = Array.from(view.nodes.values()).find(n => n.label === 'zbx-office-gw1' && n.type === 'host'); return String(n.hostid); })()")
    P.node_menu(js, 'zbx-office-gw1', choose='Focus', node_type='host')
    P.until(js, "String(view.applied_hostid) === '%s' && view.idle" % hostid, 'the filter is applied with the new Focus host')
    focus = js("[String(jQuery('#hostid').multiSelect('getData')[0].id), String(view.applied_hostid)]")
    check(focus == [hostid, hostid], 'Focus sets the Focus host and applies the filter', [focus, hostid])

finally:
    chrome.kill()
print('\n%s' % ('%d check(s) FAILED' % failed if failed else 'All node panel checks passed.'))
sys.exit(1 if failed else 0)
