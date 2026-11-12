#!/usr/bin/env python3
"""task 091 §4 (model §12.8 "Double-click"): a REAL mouse double click (the browser's own input events through the DevTools Input domain, as Playwright's dblclick sends them) on the
parent SW18 and on one unfolded leaf folds the group; the side panel then shows the group; the graph does not zoom (the scale is the same before and after); a double click on the empty area
changes nothing (it did nothing before the zoom was there, and `dblclick.zoom` is off). Needs ZBX_UI_USER, ZBX_UI_PASSWORD and google-chrome. Exit code 1 when a check fails.
    python3 dblclick_check.py [--width 1500 --height 900]
"""
import argparse, json, os, subprocess, sys, tempfile, time, urllib.request
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import page_shot as P

ap = argparse.ArgumentParser()
ap.add_argument('--width', type=int, default=1500)
ap.add_argument('--height', type=int, default=900)
ap.add_argument('--parent', default='SW18')
a = ap.parse_args()
if not os.environ.get('ZBX_UI_USER') or not os.environ.get('ZBX_UI_PASSWORD'):
    print('ZBX_UI_USER and ZBX_UI_PASSWORD are needed')
    sys.exit(2)
cookie = P.login()
profile = tempfile.mkdtemp(prefix='dbl-')
port = 9400 + os.getpid() % 500
chrome = subprocess.Popen(['google-chrome', '--headless=new', '--no-sandbox', '--disable-gpu', '--remote-debugging-port=%d' % port, '--user-data-dir=' + profile,
                           '--window-size=%d,%d' % (a.width, a.height), 'about:blank'], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
failed = 0


def check(condition, text, detail=None):
    global failed
    print(('  ok: ' if condition else '  FAIL: ') + text + ('' if condition else ' ' + json.dumps(detail)))
    failed += 0 if condition else 1


try:
    for _ in range(100):
        try:
            page = next(t for t in json.load(urllib.request.urlopen('http://127.0.0.1:%d/json' % port)) if t.get('type') == 'page')
            break
        except Exception:
            time.sleep(0.1)
    ws = P.WebSocket(page['webSocketDebuggerUrl'])
    ws.call('Network.enable')
    ws.call('Network.setCookie', name=cookie.name, value=cookie.value, url=P.BASE)
    ws.call('Emulation.setDeviceMetricsOverride', width=a.width, height=a.height, deviceScaleFactor=1, mobile=False)
    ws.call('Page.enable')
    ws.call('Page.navigate', url=P.BASE + 'zabbix.php?action=topology.view')

    def js(expr):
        r = ws.call('Runtime.evaluate', expression=expr, awaitPromise=True, returnByValue=True)
        if r.get('exceptionDetails'):
            raise RuntimeError('page script failed: %s' % r['exceptionDetails'])
        return r.get('result', {}).get('value')

    for _ in range(120):
        try:
            if js("document.readyState === 'complete' && !!document.getElementById('topology-filter-apply') && !!window.view && view.drawn_nodes && view.drawn_nodes.length > 0"):
                break
        except RuntimeError:
            pass
        time.sleep(0.5)
    time.sleep(2)

    def mouse(kind, x, y, count=1):
        ws.call('Input.dispatchMouseEvent', type=kind, x=x, y=y, button='left' if kind != 'mouseMoved' else 'none', buttons=1 if kind == 'mousePressed' else 0, clickCount=count)

    def click(x, y, count=1):
        mouse('mouseMoved', x, y)
        mouse('mousePressed', x, y, 1)
        mouse('mouseReleased', x, y, 1)
        if count == 2:
            mouse('mousePressed', x, y, 2)
            mouse('mouseReleased', x, y, 2)

    state = lambda: js("""({nodes: view.drawn_nodes.length, k: view.zoom_transform ? Math.round(view.zoom_transform.k * 1000) / 1000 : null, tx: Math.round(view.zoom_transform.x), ty: Math.round(view.zoom_transform.y),
        unfolded: Array.from(view.unfolded), panel: (document.getElementById('topology-details-title') || {textContent: ''}).textContent.trim(), group_h2: document.querySelectorAll('#topology-details h2').length,
        fold_button: !!document.querySelector('#topology-details [data-parent]')})""")
    # put the parent in the middle of the window by panning only (the scale stays), then find the screen positions of its nodes
    js("""(() => { const h = Array.from(view.nodes.values()).find(n => n.type === 'host' && n.label.startsWith('%s'));
        const n = view.drawn_nodes.find(d => String(d.id) === String(h.id)); const t = view.zoom_transform;
        view.canvas.call(view.zoom.transform, d3.zoomIdentity.translate(%d / 2 - n.x * t.k - 200, %d / 2 - n.y * t.k).scale(t.k)); })()""" % (a.parent, a.width, a.height))
    time.sleep(0.5)
    where = lambda selector_js: js("""(() => { const el = %s; if (!el) return null; const b = el.getBoundingClientRect(); return {x: b.left + b.width / 2, y: b.top + Math.min(b.height / 2, 17)}; })()""" % selector_js)
    parent_id = js("String(Array.from(view.nodes.values()).find(n => n.type === 'host' && n.label.startsWith('%s')).id)" % a.parent)
    group_pos = where("Array.from(document.querySelectorAll('#topology-canvas g.topology-node.group')).find(g => String(g.__data__.parent) === '%s')" % parent_id)
    check(group_pos is not None, 'the group node of %s is drawn' % a.parent)
    before = state()
    # a real click on the group node unfolds it
    click(group_pos['x'], group_pos['y'])
    time.sleep(1.5)
    unfolded = state()
    check(unfolded['nodes'] > before['nodes'] and parent_id in unfolded['unfolded'] and 'devices' in unfolded['panel'] and unfolded['fold_button'], 'a click on the group node unfolds it and opens the group panel', [before, unfolded])
    # the arc of the leaves can reach out of the window (the graph is bigger than the window): pan (the scale stays) so that the leaves are in the middle, then take the state
    js("""(() => { const g = view.leaf_groups.find(x => String(x.parent) === '%s'); const ls = view.drawn_nodes.filter(n => g.devices.map(String).includes(String(n.id)));
        const t = view.zoom_transform; const cx = ls.reduce((s, n) => s + n.x, 0) / ls.length, cy = ls.reduce((s, n) => s + n.y, 0) / ls.length;
        view.canvas.call(view.zoom.transform, d3.zoomIdentity.translate(%d / 2 - 200 - cx * t.k, %d / 2 - cy * t.k).scale(t.k)); })()""" % (parent_id, a.width, a.height))
    time.sleep(0.5)
    unfolded = state()
    # a real double click on an unfolded leaf folds the group
    leaf_pos = where("(() => { const ids = view.leaf_groups.find(g => String(g.parent) === '%s').devices.map(String); return Array.from(document.querySelectorAll('#topology-canvas g.topology-node')).find(g => ids.includes(String(g.__data__.id))); })()" % parent_id)
    click(leaf_pos['x'], leaf_pos['y'], 2)
    time.sleep(2.5)
    after_leaf = state()
    check(after_leaf['nodes'] == before['nodes'] and parent_id not in after_leaf['unfolded'], 'a double click on an unfolded leaf folds the group (nodes before %d, unfolded %d, after %d)' % (before['nodes'], unfolded['nodes'], after_leaf['nodes']), after_leaf)
    check(after_leaf['k'] == before['k'] and (after_leaf['tx'], after_leaf['ty']) == (unfolded['tx'], unfolded['ty']), 'the graph does not zoom or move: scale %s before, %s after' % (before['k'], after_leaf['k']), [before, after_leaf])
    check(' devices at ' in after_leaf['panel'] and after_leaf['group_h2'] == 0, 'the side panel shows the group after the fold: the title "<N> devices at <parent>", no second line "<N> devices" (task 093)', after_leaf)
    # unfold again, a real double click on the parent
    group_pos = where("Array.from(document.querySelectorAll('#topology-canvas g.topology-node.group')).find(g => String(g.__data__.parent) === '%s')" % parent_id)
    click(group_pos['x'], group_pos['y'])
    time.sleep(1.5)
    again = state()
    js("""(() => { const n = view.drawn_nodes.find(d => String(d.id) === '%s'); const t = view.zoom_transform;
        view.canvas.call(view.zoom.transform, d3.zoomIdentity.translate(%d / 2 - 200 - n.x * t.k, %d / 2 - n.y * t.k).scale(t.k)); })()""" % (parent_id, a.width, a.height))
    time.sleep(0.5)
    again = state()
    parent_pos = where("Array.from(document.querySelectorAll('#topology-canvas g.topology-node')).find(g => String(g.__data__.id) === '%s')" % parent_id)
    click(parent_pos['x'], parent_pos['y'], 2)
    time.sleep(2.5)
    after_parent = state()
    check(again['nodes'] > before['nodes'] and after_parent['nodes'] == before['nodes'] and parent_id not in after_parent['unfolded'], 'a double click on the parent folds the group (nodes %d → %d → %d)' % (before['nodes'], again['nodes'], after_parent['nodes']), after_parent)
    check(after_parent['k'] == before['k'] and ' devices at ' in after_parent['panel'] and after_parent['group_h2'] == 0, 'no zoom; the side panel shows the group', after_parent)
    # a double click on the empty area: nothing (no zoom: dblclick.zoom is off)
    empty = js("(() => { const b = document.getElementById('topology-canvas').getBoundingClientRect(); return {x: b.left + 12, y: b.bottom - 12}; })()")
    pos = where("document.elementFromPoint(%f, %f)" % (empty['x'], empty['y']))
    click(empty['x'], empty['y'], 2)
    time.sleep(1.0)
    after_empty = state()
    check(after_empty['k'] == after_parent['k'] and after_empty['nodes'] == after_parent['nodes'], 'a double click on the empty area changes nothing (the same scale %s, the same nodes)' % after_empty['k'], [after_parent, after_empty])
    # task 094 §5 (model 2.76 §12.3): after a DRAG the labels of the bundles get their room again: SW17 (not on a bundle) is dragged onto the middle of the SW12 - zbx-office-gw1 line;
    # then no label of a bundle touches a node box or another label
    js("view.fit && view.fit()")
    time.sleep(0.8)
    spot = js("""(() => { const e = view.line_entries.find(x => x.link && x.link.type === 'lag_bundle' && x.link.target.name === 'zbx-office-gw1'); const p = e.hit.getPointAtLength(e.hit.getTotalLength() * 0.5);
        const t = new DOMPoint(p.x, p.y).matrixTransform(e.hit.getScreenCTM()); return {x: t.x, y: t.y}; })()""")
    src = where("Array.from(document.querySelectorAll('#topology-canvas g.topology-node')).find(g => g.__data__.label && g.__data__.label.startsWith('SW17'))")
    mouse('mouseMoved', src['x'], src['y'])
    mouse('mousePressed', src['x'], src['y'])
    for step in range(1, 11):
        mouse('mouseMoved', src['x'] + (spot['x'] - src['x']) * step / 10, src['y'] + (spot['y'] - src['y']) * step / 10)
        time.sleep(0.05)
    mouse('mouseReleased', spot['x'], spot['y'])
    time.sleep(1.0)
    drag = js("""(() => { const hit = (a, c) => a.left < c.right - 0.5 && c.left < a.right - 0.5 && a.top < c.bottom - 0.5 && c.top < a.bottom - 0.5;
        const nodes = Array.from(document.querySelectorAll('#topology-canvas g.topology-node')).map(e => e.getBoundingClientRect());
        const labels = Array.from(document.querySelectorAll('#topology-canvas text.topology-lag-label')).map(e => e.getBoundingClientRect());
        let n = 0; labels.forEach((l, i) => { nodes.forEach(b => { if (hit(l, b)) n++; }); labels.slice(i + 1).forEach(c => { if (hit(l, c)) n++; }); });
        let pairs = 0; nodes.forEach((a, i) => nodes.slice(i + 1).forEach(c => { if (hit(a, c)) pairs++; }));
        const sw17 = view.drawn_nodes.find(d => d.label && d.label.startsWith('SW17'));
        return {labels: labels.length, label_overlaps: n, node_overlaps: pairs, dragged: sw17.fx !== undefined && sw17.fx !== null, places: view.lag_label_places.map(p => p.u + (p.free ? '' : '!'))}; })()""")
    check(drag['dragged'] and drag['labels'] == 4 and drag['label_overlaps'] == 0 and drag['node_overlaps'] == 0, 'after a drag onto a bundle line the labels have their room again (no label touches a node or a label)', drag)
    print('before %s | unfolded %s | after leaf %s | after parent %s | after empty %s' % (before, unfolded, after_leaf, after_parent, after_empty))
finally:
    chrome.kill()
print('\n%s' % ('%d check(s) FAILED' % failed if failed else 'All double click checks passed.'))
sys.exit(1 if failed else 0)
