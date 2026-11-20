"""task 098 §1 and §1b (model 2.81 §12.8 "Fold / Unfold", 2.82 §12.5 "Back"): on the real page, 1500 x 900, office stand.
The toggle: the panel of the parent SW18 and the group panel read "Unfold <N> devices" while folded and "Fold <N> devices" while unfolded; a press changes the group and the text, on both panels.
Back: SW18 group panel (unfolded) -> a Device of the list -> Back -> the group panel with the same list; SW12 (a bundle unfolded) -> SW18 (neighbor) -> Link details of a member -> Back -> SW18 -> Back -> SW12 with
the bundle still unfolded; a click on a node (the graph) starts a new stack: no Back. Needs ZBX_UI_USER, ZBX_UI_PASSWORD, google-chrome and the office stand data. Exit code 1 when a check fails.
"""
import atexit, shutil, json, math, os, subprocess, sys, tempfile, time, urllib.request
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import page_shot as P


def db_count(statement):
    return int(subprocess.run(['mysql', '-N', '-uzabbix', 'zabbix', '-e', statement], capture_output=True, text=True).stdout.strip() or '-1')


def manual_edge_ids():
    """The ids of the manual links that exist now (task 109: a check asserts only on the link it made itself; the operator may have made others by hand)."""
    out = subprocess.run(['mysql', '-N', '-uzabbix', 'zabbix', '-e', "select id from topo_edges where json_unquote(json_extract(attrs, '$.discovered_via')) = 'manual'"], capture_output=True, text=True).stdout
    return {int(line) for line in out.split() if line.strip().isdigit()}


MADE_BY_THIS_CHECK = set()

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



BACK = "(() => { const b = document.querySelector('#topology-details .topology-back-button'); return b ? b.textContent.trim() : null; })()"
TOGGLE = "(() => { const b = document.querySelector('#topology-details [data-parent]'); return b ? b.textContent.trim() : null; })()"
TITLE = "(() => { const t = document.querySelector('#topology-details .topology-node-title, #topology-details .topology-host-name'); return t ? t.textContent.trim() : null; })()"
GROUP_ROWS = "Array.from(document.querySelectorAll('#topology-details tr.topology-group-row')).map(r => r.textContent.trim())"

chrome, ws, js = session()
try:
    P.after_input(js)
    parent = js("(() => { const n = Array.from(view.nodes.values()).find(n => n.type === 'host' && String(n.label).startsWith('SW18')); return {id: String(n.id), label: n.label}; })()")
    count = js("view.leaf_groups.find(g => String(g.parent) === '%s').devices.length" % parent['id'])
    print('=== the toggle: %s, %d devices ===' % (parent['label'], count))
    js("(() => { view.unfolded.delete('%s'); view.render(); })()" % parent['id'])
    select(js, parent['label'], 'host')
    check(js(TOGGLE) == 'Unfold %d devices' % count, 'parent panel, group folded: "Unfold %d devices"' % count, js(TOGGLE))
    js("document.querySelector('#topology-details [data-parent]').click()")
    P.after_input(js)
    check(js("view.unfolded.has('%s')" % parent['id']) and js(TOGGLE) == 'Fold %d devices' % count, 'the press unfolds the group; the button reads "Fold %d devices"' % count, [js(TOGGLE)])
    check(js(TITLE) == parent['label'], 'the parent panel stays', js(TITLE))
    js("document.querySelector('#topology-details [data-parent]').click()")
    P.after_input(js)
    check(not js("view.unfolded.has('%s')" % parent['id']) and js(TOGGLE) == 'Unfold %d devices' % count, 'a second press folds it; "Unfold %d devices"' % count, [js(TOGGLE)])
    # the group panel
    js("(() => { view.panel_from = null; view.showGroup(view.group_nodes.get('%s')); })()" % parent['id'])
    P.after_input(js)
    check(js(TOGGLE) == 'Unfold %d devices' % count, 'group panel, folded: "Unfold %d devices"' % count, js(TOGGLE))
    js("document.querySelector('#topology-details [data-parent]').click()")
    P.after_input(js)
    check(js("view.unfolded.has('%s')" % parent['id']) and js(TOGGLE) == 'Fold %d devices' % count and ' devices at ' in js("document.getElementById('topology-details-title').textContent"),
          'group panel: the press unfolds, "Fold %d devices", still the group panel' % count, [js(TOGGLE)])
    js("document.querySelector('#topology-details [data-parent]').click()")
    P.after_input(js)
    check(not js("view.unfolded.has('%s')" % parent['id']) and js(TOGGLE) == 'Unfold %d devices' % count, 'group panel: the press folds, "Unfold %d devices"' % count, [js(TOGGLE)])

    print('=== Back: group list ===')
    js("(() => { view.panel_from = null; view.unfoldGroup('%s'); view.showGroup(view.group_nodes.get('%s')); })()" % (parent['id'], parent['id']))
    P.after_input(js)
    rows = js(GROUP_ROWS)
    check(js(BACK) is None and len(rows) == count, 'the group panel opened from the graph: no Back, %d rows' % count, [js(BACK), len(rows)])
    js("document.querySelector('#topology-details tr.topology-group-row').click()")
    P.after_input(js)
    back = js(BACK)
    check(back is not None and back.startswith('← Back to ') and ' devices at ' in back, 'a Device of the list: "← Back to <N> devices at <parent>"', back)
    js("document.querySelector('#topology-details .topology-back-button').click()")
    P.after_input(js)
    check(js(BACK) is None and js(GROUP_ROWS) == rows and ' devices at ' in js("document.getElementById('topology-details-title').textContent"), 'Back: the group panel with the same list, no Back', [js(BACK), js(GROUP_ROWS)])

    print('=== Back: SW12 -> SW18 -> Link details of a member ===')
    js("(() => { view.unfolded.delete('%s'); view.render(); })()" % parent['id'])
    select(js, 'SW12-10-CORE-6000-48G', 'host')
    check(js(BACK) is None, 'SW12 opened as the first panel: no Back')
    js("document.querySelector('#topology-details tr.topology-link-bundle .topology-bundle-toggle').click()")
    open_members = js("Array.from(document.querySelectorAll('#topology-details .topology-bundle-toggle')).map(b => b.getAttribute('aria-expanded'))")
    js("""(() => { const l = Array.from(document.querySelectorAll('#topology-details .topology-neighbor[data-node]')).find(e => String(view.nodes.get(e.dataset.node).label).startsWith('SW18')); l.click(); })()""")
    P.after_input(js)
    check(js(TITLE).startswith('SW18') and js(BACK) == '← Back to SW12-10-CORE-6000-48G', 'a neighbor in Links: the panel of SW18 with "← Back to SW12-10-CORE-6000-48G"', [js(TITLE), js(BACK)])
    js("document.querySelector('#topology-details tr.topology-link-member .topology-link-details').click()")
    P.after_input(js)
    check('↔' in js("document.querySelector('#topology-details h2').textContent") and js(BACK) is not None and js(BACK).startswith('← Back to SW18'), 'Link details of a member: "← Back to SW18…"', js(BACK))
    js("document.querySelector('#topology-details .topology-back-button').click()")
    P.after_input(js)
    check(js(TITLE).startswith('SW18') and js(BACK) == '← Back to SW12-10-CORE-6000-48G', 'Back: SW18, and its own Back to SW12', [js(TITLE), js(BACK)])
    js("document.querySelector('#topology-details .topology-back-button').click()")
    P.after_input(js)
    now = js("Array.from(document.querySelectorAll('#topology-details .topology-bundle-toggle')).map(b => b.getAttribute('aria-expanded'))")
    check(js(TITLE).startswith('SW12') and js(BACK) is None and now == open_members and 'true' in now, 'Back: SW12 with the bundle still unfolded, no Back', [js(TITLE), now, open_members])
    print('=== a click on the graph starts a new stack ===')
    js("document.querySelector('#topology-details tr.topology-link-bundle .topology-bundle-details') && 1")
    js("""(() => { const l = Array.from(document.querySelectorAll('#topology-details .topology-neighbor[data-node]')).find(e => String(view.nodes.get(e.dataset.node).label).startsWith('SW18')); l.click(); })()""")
    P.after_input(js)
    check(js(BACK) is not None, 'SW18 from SW12: Back is there')
    js("""(() => { const g = Array.from(document.querySelectorAll('#topology-canvas g.topology-node')).find(e => String(e.__data__.label).startsWith('SW13'));
        g.querySelector('rect.topology-shape').dispatchEvent(new MouseEvent('click', {bubbles: true})); })()""")
    P.after_input(js)
    check(js(TITLE).startswith('SW13') and js(BACK) is None, 'a click on a node in the graph: its panel has no Back', [js(TITLE), js(BACK)])
    # Apply starts a new stack too
    js("""(() => { const l = Array.from(document.querySelectorAll('#topology-details .topology-neighbor[data-node]'))[0]; l.click(); })()""")
    P.after_input(js)
    check(js(BACK) is not None, 'a neighbor of SW13: Back is there')
    js("document.getElementById('topology-filter-apply').click()")
    P.after_input(js)
    check(js(BACK) is None, 'Apply: a new stack, the first panel has no Back', js(BACK))

    print('=== Selection (model 2.83) ===')
    RING = """(() => { const r = document.querySelector('#topology-canvas rect.topology-selection'); if (!r) return null; const b = r.getBoundingClientRect(); return {left: b.left, right: b.right, top: b.top, bottom: b.bottom, count: document.querySelectorAll('#topology-canvas rect.topology-selection').length}; })()"""
    BOX = "(label => { const g = Array.from(document.querySelectorAll('#topology-canvas g.topology-node')).find(e => String(e.__data__.label).startsWith(label)); const b = g.getBoundingClientRect(); return {left: b.left, right: b.right, top: b.top, bottom: b.bottom}; })(%s)"
    OUTLINE = "(label => { const g = Array.from(document.querySelectorAll('#topology-canvas g.topology-node')).find(e => String(e.__data__.label).startsWith(label)); const s = getComputedStyle(g.querySelector('rect.topology-shape')); return [s.stroke, s.fill, s.strokeDasharray, s.strokeWidth, s.opacity, getComputedStyle(g).opacity]; })(%s)"

    def ring_on(label):
        ring, box = js(RING), js(BOX % json.dumps(label))
        if ring is None:
            return False
        k = js("view.zoom_transform.k")
        m = 4.5 * k
        return all(abs(a - b) < 2.5 for a, b in [(ring['left'], box['left'] - m), (ring['right'], box['right'] + m), (ring['top'], box['top'] - m), (ring['bottom'], box['bottom'] + m)]) and ring['count'] == 1

    def click_node(label):
        js("""(label => { const g = Array.from(document.querySelectorAll('#topology-canvas g.topology-node')).find(e => String(e.__data__.label).startsWith(label));
            g.querySelector('rect.topology-shape').dispatchEvent(new MouseEvent('click', {bubbles: true})); })(%s)""" % json.dumps(label))
        P.after_input(js)

    js("(() => { view.unfolded.clear(); view.render(); })()")
    P.after_input(js)
    before = js(OUTLINE % json.dumps('SW12'))
    click_node('SW12')
    check(ring_on('SW12') and js(TITLE).startswith('SW12'), 'a click on SW12: a ring around its box (gap 3 px + 3 px stroke), one ring', [js(RING), js(BOX % json.dumps('SW12'))])
    check(js(OUTLINE % json.dumps('SW12')) == before, 'the outline, fill, dash and opacity of SW12 are the same as before the selection', [before, js(OUTLINE % json.dumps('SW12'))])
    check(js("getComputedStyle(document.querySelector('#topology-canvas rect.topology-selection')).stroke") == 'rgb(2, 117, 184)' and js("getComputedStyle(document.querySelector('#topology-canvas rect.topology-selection')).strokeWidth") == '3px', 'the ring is 3 px in the accent colour')
    shot = os.environ.get('SELECTION_SHOT')
    if shot:
        import base64
        with open(shot, 'wb') as f:
            f.write(base64.b64decode(ws.call('Page.captureScreenshot', format='png')['data']))
    js("""(() => { const l = Array.from(document.querySelectorAll('#topology-details .topology-neighbor[data-node]')).find(e => String(view.nodes.get(e.dataset.node).label).startsWith('SW18')); l.click(); })()""")
    P.after_input(js)
    check(ring_on('SW18'), 'a neighbor in Links: the ring moves to SW18', js(RING))
    js("document.querySelector('#topology-details .topology-back-button').click()")
    P.after_input(js)
    check(ring_on('SW12'), 'Back: the ring is on SW12 again', js(RING))
    # a panel opened from a list: SW12 is out of the window, the view pans to it, the zoom stays
    click_node('SW18')
    k0 = js("view.zoom_transform.k")
    js("(() => { const t = view.zoom_transform; view.canvas.call(view.zoom.transform, d3.zoomIdentity.translate(t.x + 4000, t.y).scale(t.k)); })()")
    P.after_input(js)
    out = js(BOX % json.dumps('SW12'))
    check(out['left'] > 1500, 'SW12 is out of the window after the pan', out)
    js("""(() => { const l = Array.from(document.querySelectorAll('#topology-details .topology-neighbor[data-node]')).find(e => String(view.nodes.get(e.dataset.node).label).startsWith('SW12')); l.click(); })()""")
    P.after_input(js)
    box = js(BOX % json.dumps('SW12'))
    inside = js("(() => { const b = document.getElementById('topology-canvas').getBoundingClientRect(); return [b.left, b.right, b.top, b.bottom]; })()")
    check(js(TITLE).startswith('SW12') and inside[0] <= box['left'] and box['right'] <= inside[1] and inside[2] <= box['top'] and box['bottom'] <= inside[3] and ring_on('SW12') and abs(js("view.zoom_transform.k") - k0) < 1e-9,
          'opened from a list: the view panned to SW12 (the zoom is the same) and the ring is on it', [box, inside])
    # a click on the empty area removes the selection
    js("document.getElementById('topology-canvas').dispatchEvent(new MouseEvent('click', {bubbles: true}))")
    P.after_input(js)
    check(js(RING) is None, 'a click on the empty area: no ring')
    # a bundle line: the band under it; a member opened from the bundle panel: the bundle line
    BAND = "Array.from(document.querySelectorAll('#topology-canvas path.topology-link-selected')).map(e => parseFloat(e.style.strokeWidth))"
    js("(() => { const e = view.line_entries.find(x => x.link && x.link.type === 'lag_bundle'); e.open(); })()")
    P.after_input(js)
    band = js(BAND)
    hover = js("(() => { const e = view.line_entries.find(x => x.link && x.link.type === 'lag_bundle'); return parseFloat(e.glow().__width); })()")
    check(len(band) == 1 and band[0] > hover and js(RING) is None, 'a click on a bundle line: a band under it, wider than the hover band', [band, hover])
    js("document.querySelector('#topology-details [data-member]').click()")
    P.after_input(js)
    check(len(js(BAND)) == 1 and js("document.querySelector('#topology-details h2').textContent").count('↔') == 1 and js(BACK) is not None, 'a member opened from the bundle panel: the bundle line stays selected', js(BAND))
    js("document.querySelector('#topology-details .topology-back-button').click()")
    P.after_input(js)
    check(len(js(BAND)) == 1 and 'LAG' in js("document.getElementById('topology-details').textContent"), 'Back: the bundle panel and its band')
    click_node('SW13')
    check(js(BAND) == [] and ring_on('SW13'), 'a node after a line: the band is gone, the ring is on the node')

    print('=== The panel of a host is the Host panel, however it is opened (task 101 §1, model §12.5 2.85) ===')
    HOSTPANEL = """(() => { const d = document.getElementById('topology-details'); const k = d.querySelector('.topology-node-kind'); return {kind: k ? k.textContent.trim() : null, title: (d.querySelector('.topology-host-name, .topology-node-title') || {}).textContent,
        problems: !!d.querySelector('details.topology-problems'), focus: !!d.querySelector('.topology-focus-button'), not_in_view: !!d.querySelector('.topology-not-in-view'), ring: document.querySelectorAll('#topology-canvas rect.topology-selection').length}; })()"""

    def host_panel(text, label, focus=True):
        p = js(HOSTPANEL)
        check(p['kind'] == 'Zabbix host' and (p['title'] or '').startswith(label) and p['problems'] and p['focus'] == focus and not p['not_in_view'] and p['ring'] == 1, text, p)

    js("window.confirm = () => true; window.alert = text => { window.__alert = text; }")
    # model 2.95 §12.1: Apply without a focus host selects nothing (until 2.94 it selected the first Device of the list, task 101: its Host panel); the panels below come from clicks
    js("document.getElementById('topology-filter-apply').click()")
    P.after_input(js)
    check(not js("document.querySelector('#topology-details .topology-node-head') !== null"), 'the first load and Apply without a focus host: no panel (nothing is selected)')
    js("(() => { view.unfolded.clear(); view.render(); })()")
    click_node('SW12')
    host_panel('a click on SW12: Host panel, Problems, Focus, selection on the drawn node', 'SW12')
    click_node('SW13')
    js("""(() => { const l = Array.from(document.querySelectorAll('#topology-details .topology-neighbor[data-node]')).find(e => String(view.nodes.get(e.dataset.node).label).startsWith('SW12')); l.click(); })()""")
    P.after_input(js)
    host_panel("SW12 opened from SW13's Links: Host panel", 'SW12')
    js("document.querySelector('#topology-details .topology-back-button').click()")
    P.after_input(js)
    host_panel('Back to SW13: Host panel', 'SW13')
    js("document.querySelector('#topology-details .topology-back-button')") if False else None
    # a refresh after Link and Unlink: a manual link between a free port of SW12 and one of SW13 (the picker of the Ports section), then Unlink; the panel is the Host panel each time
    click_node('SW12')
    js("Array.from(document.querySelectorAll('#topology-details details.topology-port-group')).forEach(d => { d.open = true; })")
    free12 = js("(() => { const r = document.querySelector('#topology-details details[data-group=\"no_neighbor\"] tbody tr'); return r ? r.querySelector('.topology-port-name').textContent.trim() : null; })()")
    manual_before = manual_edge_ids()
    js("document.querySelector('#topology-details details[data-group=\"no_neighbor\"] tbody tr .topology-link-button').click()")
    click_node('SW13')
    js("Array.from(document.querySelectorAll('#topology-details details.topology-port-group')).forEach(d => { d.open = true; })")
    js("document.querySelector('#topology-details details[data-group=\"no_neighbor\"] tbody tr .topology-link-button').click()")
    P.after_input(js)
    host_panel('after a Link (the view is read again): the Host panel of SW13', 'SW13')
    n_links = js("document.querySelectorAll('#topology-details .topology-link-row').length")
    # only the manual link that this check made: its id is the manual link that was not there before the Link; its row is the one whose data-edges has it
    made = manual_edge_ids() - manual_before
    MADE_BY_THIS_CHECK.update(made)
    check(len(made) == 1, 'the Link made one manual link (its id is %s)' % sorted(made), [sorted(made), sorted(manual_before)])
    js("""(() => { const id = '%s'; const row = Array.from(document.querySelectorAll('#topology-details tr.topology-link-row')).find(r => (r.dataset.edges || '').split(',').includes(id)); row.querySelector('.topology-unlink-button').click(); })()""" % (sorted(made)[0] if made else ''))
    P.after_input(js)
    host_panel('after an Unlink (the view is read again): the Host panel of SW13', 'SW13')
    check(not (manual_edge_ids() & MADE_BY_THIS_CHECK) and manual_before <= manual_edge_ids(), 'the link that this check made is gone; the manual links that were there before are untouched', [sorted(manual_edge_ids()), sorted(MADE_BY_THIS_CHECK), sorted(manual_before)])

    print('=== Identity: the list of bits wraps only after a comma (task 101 §5) ===')
    found = None
    # since 113 the own capabilities of a reporter's Device replace the neighbors' (model 2.96 §12.2), so the reporters have one bit each: the panel of a Device with two or more bits (opened through the page)
    ids = js("Array.from(view.nodes.values()).filter(n => n.type === 'device' && (n.device_class_bits || []).length >= 2).slice(0, 5).map(n => String(n.id))") or []
    for node_id in ids:
        js("(async id => { await view.selectNode(view.nodes.get(id)); })(%s)" % json.dumps(node_id))
        P.after_input(js)
        js("Array.from(document.querySelectorAll('#topology-details details.topology-identity')).forEach(d => { d.open = true; })")
        r = js("""(() => { const v = document.querySelector('#topology-details .topology-class-value'); if (!v) return null; const spans = Array.from(v.querySelectorAll('.topology-nowrap'));
            return {text: v.textContent, spans: spans.length, broken: spans.filter(s => s.getClientRects().length > 1).map(s => s.textContent), lines: new Set(spans.map(s => Math.round(s.getBoundingClientRect().top))).size}; })()""")
        if r and r['spans'] >= 2:
            found = r
            break
    check(found is not None, 'a node with a list of two or more bits is on the stand', found)
    if found:
        check(found['broken'] == [], 'no bit is broken across two lines: the list wraps only after a comma (%s; %d line(s))' % (found['text'], found['lines']), found)
    print('=== The graph toolbar: the legend opens over the graph and closes again (task 101 §2) ===')
    LEG = "(() => { const l = document.getElementById('topology-legend'), c = document.getElementById('topology-canvas').getBoundingClientRect(); const b = l.getBoundingClientRect(); return {hidden: l.hidden, over_graph: !l.hidden && b.left >= c.left && b.top >= c.top && b.right <= c.right && b.bottom <= c.bottom + 1, pressed: document.getElementById('topology-legend-button').getAttribute('aria-pressed')}; })()"
    if not js("document.getElementById('topology-legend').hidden"):
        click_legend = js("document.getElementById('topology-legend-button').click()")
        P.after_input(js)
    check(js(LEG)['hidden'], 'the legend starts closed')
    js("document.getElementById('topology-legend-button').click()")
    P.after_input(js)
    st = js(LEG)
    check(not st['hidden'] and st['over_graph'] and st['pressed'] == 'true', 'the Legend button opens the legend as a panel over the graph', st)
    shot_dir = os.environ.get('SHOTS_DIR')
    if shot_dir:
        import base64
        with open(os.path.join(shot_dir, os.environ.get('SHOTS_PREFIX', '101') + '-legend-open.png'), 'wb') as f:
            f.write(base64.b64decode(ws.call('Page.captureScreenshot', format='png')['data']))
    js("document.getElementById('topology-legend-button').click()")
    P.after_input(js)
    check(js(LEG)['hidden'], 'the Legend button closes it again')
finally:
    # the cleanup: the link of this check, by its own id, when the run stopped before the Unlink; nothing else
    for edge_id in MADE_BY_THIS_CHECK:
        subprocess.run(['mysql', '-uzabbix', 'zabbix', '-e', 'delete from topo_edges where id = %d' % edge_id], capture_output=True)
    chrome.kill()
print('\n%s' % ('%d check(s) FAILED' % failed if failed else 'All toggle and Back checks passed.'))
sys.exit(1 if failed else 0)
