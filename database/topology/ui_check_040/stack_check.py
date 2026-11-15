"""task 098 §1 and §1b (model 2.81 §12.8 "Fold / Unfold", 2.82 §12.5 "Back"): on the real page, 1500 x 900, office stand.
The toggle: the panel of the parent SW18 and the group panel read "Unfold <N> devices" while folded and "Fold <N> devices" while unfolded; a press changes the group and the text, on both panels.
Back: SW18 group panel (unfolded) -> a Device of the list -> Back -> the group panel with the same list; SW12 (a bundle unfolded) -> SW18 (neighbor) -> Link details of a member -> Back -> SW18 -> Back -> SW12 with
the bundle still unfolded; a click on a node (the graph) starts a new stack: no Back. Needs ZBX_UI_USER, ZBX_UI_PASSWORD, google-chrome and the office stand data. Exit code 1 when a check fails.
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
    profile = tempfile.mkdtemp(prefix='panel-')
    atexit.register(shutil.rmtree, profile, ignore_errors=True)      # the Chrome profile is not left in /tmp (task 100)
    port = 9700 + os.getpid() % 200
    chrome = subprocess.Popen(['google-chrome', '--headless=new', '--no-sandbox', '--disable-gpu', '--remote-debugging-port=%d' % port, '--user-data-dir=' + profile,
                               '--window-size=1500,900', 'about:blank'], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    for _ in range(100):
        try:
            page = next(t for t in json.load(urllib.request.urlopen('http://127.0.0.1:%d/json' % port)) if t.get('type') == 'page')
            break
        except Exception:
            time.sleep(0.1)
    ws = P.WebSocket(page['webSocketDebuggerUrl'])
    ws.call('Network.enable')
    ws.call('Network.setCookie', name=cookie.name, value=cookie.value, url=P.BASE)
    ws.call('Emulation.setDeviceMetricsOverride', width=1500, height=900, deviceScaleFactor=1, mobile=False)
    ws.call('Page.enable')
    ws.call('Page.navigate', url=P.BASE + 'zabbix.php?action=topology.view')

    def js(expr):
        r = ws.call('Runtime.evaluate', expression=expr, awaitPromise=True, returnByValue=True)
        if r.get('exceptionDetails'):
            raise RuntimeError('page script failed: %s' % r['exceptionDetails'])
        return r.get('result', {}).get('value')

    for _ in range(120):
        try:
            if js("document.readyState === 'complete' && !!window.view && view.drawn_nodes && view.drawn_nodes.length > 0 && !!document.getElementById('hostid')"):
                break
        except RuntimeError:
            pass
        time.sleep(0.5)
    return chrome, ws, js


def mouse(ws, x, y):
    ws.call('Input.dispatchMouseEvent', type='mouseMoved', x=x, y=y, button='none', buttons=0)


def select(js, label, kind=None):
    js("""(async () => { const n = Array.from(view.nodes.values()).find(n => n.label === %s && (%s)); await view.selectNode(n); return 1; })()""" % (json.dumps(label), ("n.type === '%s'" % kind) if kind else 'true'))
    time.sleep(3.5)



BACK = "(() => { const b = document.querySelector('#topology-details .topology-back-button'); return b ? b.textContent.trim() : null; })()"
TOGGLE = "(() => { const b = document.querySelector('#topology-details [data-parent]'); return b ? b.textContent.trim() : null; })()"
TITLE = "(() => { const t = document.querySelector('#topology-details .topology-node-title, #topology-details .topology-host-name'); return t ? t.textContent.trim() : null; })()"
GROUP_ROWS = "Array.from(document.querySelectorAll('#topology-details tr.topology-group-row')).map(r => r.textContent.trim())"

chrome, ws, js = session()
try:
    time.sleep(2)
    parent = js("(() => { const n = Array.from(view.nodes.values()).find(n => n.type === 'host' && String(n.label).startsWith('SW18')); return {id: String(n.id), label: n.label}; })()")
    count = js("view.leaf_groups.find(g => String(g.parent) === '%s').devices.length" % parent['id'])
    print('=== the toggle: %s, %d devices ===' % (parent['label'], count))
    js("(() => { view.unfolded.delete('%s'); view.render(); })()" % parent['id'])
    select(js, parent['label'], 'host')
    check(js(TOGGLE) == 'Unfold %d devices' % count, 'parent panel, group folded: "Unfold %d devices"' % count, js(TOGGLE))
    js("document.querySelector('#topology-details [data-parent]').click()")
    time.sleep(3.5)
    check(js("view.unfolded.has('%s')" % parent['id']) and js(TOGGLE) == 'Fold %d devices' % count, 'the press unfolds the group; the button reads "Fold %d devices"' % count, [js(TOGGLE)])
    check(js(TITLE) == parent['label'], 'the parent panel stays', js(TITLE))
    js("document.querySelector('#topology-details [data-parent]').click()")
    time.sleep(3.5)
    check(not js("view.unfolded.has('%s')" % parent['id']) and js(TOGGLE) == 'Unfold %d devices' % count, 'a second press folds it; "Unfold %d devices"' % count, [js(TOGGLE)])
    # the group panel
    js("(() => { view.panel_from = null; view.showGroup(view.group_nodes.get('%s')); })()" % parent['id'])
    time.sleep(0.5)
    check(js(TOGGLE) == 'Unfold %d devices' % count, 'group panel, folded: "Unfold %d devices"' % count, js(TOGGLE))
    js("document.querySelector('#topology-details [data-parent]').click()")
    time.sleep(3.5)
    check(js("view.unfolded.has('%s')" % parent['id']) and js(TOGGLE) == 'Fold %d devices' % count and ' devices at ' in js("document.getElementById('topology-details-title').textContent"),
          'group panel: the press unfolds, "Fold %d devices", still the group panel' % count, [js(TOGGLE)])
    js("document.querySelector('#topology-details [data-parent]').click()")
    time.sleep(3.5)
    check(not js("view.unfolded.has('%s')" % parent['id']) and js(TOGGLE) == 'Unfold %d devices' % count, 'group panel: the press folds, "Unfold %d devices"' % count, [js(TOGGLE)])

    print('=== Back: group list ===')
    js("(() => { view.panel_from = null; view.unfoldGroup('%s'); view.showGroup(view.group_nodes.get('%s')); })()" % (parent['id'], parent['id']))
    time.sleep(1.0)
    rows = js(GROUP_ROWS)
    check(js(BACK) is None and len(rows) == count, 'the group panel opened from the graph: no Back, %d rows' % count, [js(BACK), len(rows)])
    js("document.querySelector('#topology-details tr.topology-group-row').click()")
    time.sleep(3.5)
    back = js(BACK)
    check(back is not None and back.startswith('← Back to ') and ' devices at ' in back, 'a Device of the list: "← Back to <N> devices at <parent>"', back)
    js("document.querySelector('#topology-details .topology-back-button').click()")
    time.sleep(3.5)
    check(js(BACK) is None and js(GROUP_ROWS) == rows and ' devices at ' in js("document.getElementById('topology-details-title').textContent"), 'Back: the group panel with the same list, no Back', [js(BACK), js(GROUP_ROWS)])

    print('=== Back: SW12 -> SW18 -> Link details of a member ===')
    js("(() => { view.unfolded.delete('%s'); view.render(); })()" % parent['id'])
    select(js, 'SW12-10-CORE-6000-48G', 'host')
    check(js(BACK) is None, 'SW12 opened as the first panel: no Back')
    js("document.querySelector('#topology-details tr.topology-link-bundle .topology-bundle-toggle').click()")
    open_members = js("Array.from(document.querySelectorAll('#topology-details .topology-bundle-toggle')).map(b => b.getAttribute('aria-expanded'))")
    js("""(() => { const l = Array.from(document.querySelectorAll('#topology-details .topology-neighbor[data-node]')).find(e => String(view.nodes.get(e.dataset.node).label).startsWith('SW18')); l.click(); })()""")
    time.sleep(3.5)
    check(js(TITLE).startswith('SW18') and js(BACK) == '← Back to SW12-10-CORE-6000-48G', 'a neighbor in Links: the panel of SW18 with "← Back to SW12-10-CORE-6000-48G"', [js(TITLE), js(BACK)])
    js("document.querySelector('#topology-details tr.topology-link-member .topology-link-details').click()")
    time.sleep(1.0)
    check('↔' in js("document.querySelector('#topology-details h2').textContent") and js(BACK) is not None and js(BACK).startswith('← Back to SW18'), 'Link details of a member: "← Back to SW18…"', js(BACK))
    js("document.querySelector('#topology-details .topology-back-button').click()")
    time.sleep(3.5)
    check(js(TITLE).startswith('SW18') and js(BACK) == '← Back to SW12-10-CORE-6000-48G', 'Back: SW18, and its own Back to SW12', [js(TITLE), js(BACK)])
    js("document.querySelector('#topology-details .topology-back-button').click()")
    time.sleep(3.5)
    now = js("Array.from(document.querySelectorAll('#topology-details .topology-bundle-toggle')).map(b => b.getAttribute('aria-expanded'))")
    check(js(TITLE).startswith('SW12') and js(BACK) is None and now == open_members and 'true' in now, 'Back: SW12 with the bundle still unfolded, no Back', [js(TITLE), now, open_members])
    print('=== a click on the graph starts a new stack ===')
    js("document.querySelector('#topology-details tr.topology-link-bundle .topology-bundle-details') && 1")
    js("""(() => { const l = Array.from(document.querySelectorAll('#topology-details .topology-neighbor[data-node]')).find(e => String(view.nodes.get(e.dataset.node).label).startsWith('SW18')); l.click(); })()""")
    time.sleep(3.5)
    check(js(BACK) is not None, 'SW18 from SW12: Back is there')
    js("""(() => { const g = Array.from(document.querySelectorAll('#topology-canvas g.topology-node')).find(e => String(e.__data__.label).startsWith('SW13'));
        g.querySelector('rect.topology-shape').dispatchEvent(new MouseEvent('click', {bubbles: true})); })()""")
    time.sleep(3.5)
    check(js(TITLE).startswith('SW13') and js(BACK) is None, 'a click on a node in the graph: its panel has no Back', [js(TITLE), js(BACK)])
    # Apply starts a new stack too
    js("""(() => { const l = Array.from(document.querySelectorAll('#topology-details .topology-neighbor[data-node]'))[0]; l.click(); })()""")
    time.sleep(3.5)
    check(js(BACK) is not None, 'a neighbor of SW13: Back is there')
    js("document.getElementById('topology-filter-apply').click()")
    time.sleep(4)
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
        time.sleep(3.5)

    js("(() => { view.unfolded.clear(); view.render(); })()")
    time.sleep(1)
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
    time.sleep(3.5)
    check(ring_on('SW18'), 'a neighbor in Links: the ring moves to SW18', js(RING))
    js("document.querySelector('#topology-details .topology-back-button').click()")
    time.sleep(3.5)
    check(ring_on('SW12'), 'Back: the ring is on SW12 again', js(RING))
    # a panel opened from a list: SW12 is out of the window, the view pans to it, the zoom stays
    click_node('SW18')
    k0 = js("view.zoom_transform.k")
    js("(() => { const t = view.zoom_transform; view.canvas.call(view.zoom.transform, d3.zoomIdentity.translate(t.x + 4000, t.y).scale(t.k)); })()")
    time.sleep(0.5)
    out = js(BOX % json.dumps('SW12'))
    check(out['left'] > 1500, 'SW12 is out of the window after the pan', out)
    js("""(() => { const l = Array.from(document.querySelectorAll('#topology-details .topology-neighbor[data-node]')).find(e => String(view.nodes.get(e.dataset.node).label).startsWith('SW12')); l.click(); })()""")
    time.sleep(3.5)
    box = js(BOX % json.dumps('SW12'))
    inside = js("(() => { const b = document.getElementById('topology-canvas').getBoundingClientRect(); return [b.left, b.right, b.top, b.bottom]; })()")
    check(js(TITLE).startswith('SW12') and inside[0] <= box['left'] and box['right'] <= inside[1] and inside[2] <= box['top'] and box['bottom'] <= inside[3] and ring_on('SW12') and abs(js("view.zoom_transform.k") - k0) < 1e-9,
          'opened from a list: the view panned to SW12 (the zoom is the same) and the ring is on it', [box, inside])
    # a click on the empty area removes the selection
    js("document.getElementById('topology-canvas').dispatchEvent(new MouseEvent('click', {bubbles: true}))")
    time.sleep(0.5)
    check(js(RING) is None, 'a click on the empty area: no ring')
    # a bundle line: the band under it; a member opened from the bundle panel: the bundle line
    BAND = "Array.from(document.querySelectorAll('#topology-canvas path.topology-link-selected')).map(e => parseFloat(e.style.strokeWidth))"
    js("(() => { const e = view.line_entries.find(x => x.link && x.link.type === 'lag_bundle'); e.open(); })()")
    time.sleep(1)
    band = js(BAND)
    hover = js("(() => { const e = view.line_entries.find(x => x.link && x.link.type === 'lag_bundle'); return parseFloat(e.glow().__width); })()")
    check(len(band) == 1 and band[0] > hover and js(RING) is None, 'a click on a bundle line: a band under it, wider than the hover band', [band, hover])
    js("document.querySelector('#topology-details [data-member]').click()")
    time.sleep(1)
    check(len(js(BAND)) == 1 and js("document.querySelector('#topology-details h2').textContent").count('↔') == 1 and js(BACK) is not None, 'a member opened from the bundle panel: the bundle line stays selected', js(BAND))
    js("document.querySelector('#topology-details .topology-back-button').click()")
    time.sleep(1)
    check(len(js(BAND)) == 1 and 'LAG' in js("document.getElementById('topology-details').textContent"), 'Back: the bundle panel and its band')
    click_node('SW13')
    check(js(BAND) == [] and ring_on('SW13'), 'a node after a line: the band is gone, the ring is on the node')
finally:
    chrome.kill()
print('\n%s' % ('%d check(s) FAILED' % failed if failed else 'All toggle and Back checks passed.'))
sys.exit(1 if failed else 0)
