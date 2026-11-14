"""task 099 §3 (model 2.80 §9.7, §12.1, §12.2, §12.5): a manual Device on the real page, 1500 x 900, office stand, with real mouse and keyboard events (DevTools Input domain).
"Create device" -> the dialog (help text) -> "ISP-Router" (Router, no MAC, ports wan0, lan0) -> drawn and selected, panel "Not a Zabbix host · manual"; a free port of SW12 linked to lan0 (the link is
dashed: manual); wan0 renamed wan1; lan0 can not be deleted while linked (the server refuses, the dialog disables it); Edit to class Switch changes the icon; Delete Device; nothing is left in the
database. Screenshots when SHOTS_DIR is set: 099-create-dialog.png, 099-manual-device-linked.png. Needs ZBX_UI_USER, ZBX_UI_PASSWORD, google-chrome, the office stand data and the database login of
the harness (`mysql -uzabbix zabbix`). Exit code 1 when a check fails.
"""
import json, math, os, subprocess, sys, tempfile, time, urllib.request
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
    time.sleep(1.5)



def db(statement):
    return subprocess.run(['mysql', '-N', '-uzabbix', 'zabbix', '-e', statement], capture_output=True, text=True).stdout.strip()


def left_over():
    return db("select count(*) from topo_nodes where (type = 'device' and json_unquote(json_extract(attrs, '$.source')) = 'manual') or (type = 'port' and json_unquote(json_extract(attrs, '$.source')) = 'manual')")


db("delete from topo_nodes where type = 'device' and json_unquote(json_extract(attrs, '$.source')) = 'manual'")
chrome, ws, js = session()


def wait(expr, seconds=15):
    for _ in range(int(seconds * 4)):
        try:
            value = js(expr)
        except RuntimeError:
            value = None
        if value:
            return value
        time.sleep(0.25)
    return js(expr)


def where(expr):
    return js("""(() => { const el = %s; if (!el) return null; el.scrollIntoView({block: 'center'}); const b = el.getBoundingClientRect(); return {x: b.left + b.width / 2, y: b.top + b.height / 2}; })()""" % expr)


def click(expr):
    p = where(expr)
    assert p is not None, 'nothing to click: ' + expr
    for kind in ('mouseMoved', 'mousePressed', 'mouseReleased'):
        ws.call('Input.dispatchMouseEvent', type=kind, x=p['x'], y=p['y'], button='left' if kind != 'mouseMoved' else 'none', buttons=1 if kind == 'mousePressed' else 0, clickCount=1)
    time.sleep(0.4)


def type_into(selector, text):
    click("document.querySelector(%s)" % json.dumps(selector))
    js("document.querySelector(%s).select()" % json.dumps(selector))
    ws.call('Input.insertText', text=text)
    time.sleep(0.2)


def dialog_button(title):
    return "Array.from(document.querySelectorAll('.overlay-dialogue-footer button')).find(b => b.textContent.trim() === %s)" % json.dumps(title)


def shot(name):
    folder = os.environ.get('SHOTS_DIR')
    if folder:
        import base64
        with open(os.path.join(folder, os.environ.get('SHOTS_PREFIX', '099') + name[3:]), 'wb') as f:
            f.write(base64.b64decode(ws.call('Page.captureScreenshot', format='png')['data']))


TITLE = "(() => { const t = document.querySelector('#topology-details .topology-node-title, #topology-details .topology-host-name'); return t ? t.textContent.trim() : null; })()"
KIND = "(() => { const t = document.querySelector('#topology-details .topology-node-kind'); return t ? t.textContent.trim() : null; })()"
RING = "document.querySelectorAll('#topology-canvas rect.topology-selection').length"
NODE = "(label => view.drawn_nodes.find(n => n.label === label))"


BASELINE = {}


def crossing_key(t):
    return t['line'] + ' | ' + t['node']


def layout_clean(text):
    # task 100 §2: the checks of the overlap check of page_shot.py (no node box overlaps, no label touches a node or a label, no line passes through a node that is not its end). A line through a node that
    # was there before the new Device was created (the first layout of the page; the solver of §1 leaves one in a few layouts, see the report) is not the placement of the new Device: only a crossing that
    # is new, or that involves the new Device, fails here.
    r = js(P.CHECKS['overlap'])
    new = [t for t in r['lines_through_nodes'] if crossing_key(t) not in BASELINE or 'ISP-Router' in t['line'] or t['node'] == 'ISP-Router']
    check(r['overlaps'] == 0 and r['bundle_label_overlaps'] == [] and new == [], text, [r['overlapping_pairs'], r['bundle_label_overlaps'], new])


TITLE = "(() => { const t = document.querySelector('#topology-details .topology-node-title, #topology-details .topology-host-name'); return t ? t.textContent.trim() : null; })()"
KIND = "(() => { const t = document.querySelector('#topology-details .topology-node-kind'); return t ? t.textContent.trim() : null; })()"
RING = "document.querySelectorAll('#topology-canvas rect.topology-selection').length"
NODE = "(label => view.drawn_nodes.find(n => n.label === label))"


def layout_clean(text):
    # task 100 §2: the checks of the overlap check of page_shot.py (no node box overlaps, no label touches a node or a label, no line passes through a node that is not its end)
    r = js(P.CHECKS['overlap'])
    check(r['overlaps'] == 0 and r['bundle_label_overlaps'] == [] and r['lines_through_nodes'] == [], text, [r['overlapping_pairs'], r['bundle_label_overlaps'], r['lines_through_nodes']])

try:
    time.sleep(2)
    BASELINE.update({crossing_key(t): True for t in js(P.CHECKS['overlap'])['lines_through_nodes']})
    print('lines through a node before the new Device: %d' % len(BASELINE))
    js("window.confirm = () => true; window.alert = text => { window.__alert = text; }")
    print('=== Create device: the dialog ===')
    check(left_over() == '0', 'no manual Device or port in the database at the start')
    click("document.getElementById('topology-create-device')")
    check(wait("!!document.getElementById('topology-device-name')"), 'a click on "Create device" (next to Fit) opens the dialog')
    fit = js("(() => { const f = document.getElementById('topology-fit').getBoundingClientRect(), c = document.getElementById('topology-create-device').getBoundingClientRect(); return [f.right, c.left, f.top, c.top]; })()")
    check(fit[1] > fit[0] and abs(fit[2] - fit[3]) < 2, 'the button is next to Fit', fit)
    text = js("document.querySelector('.overlay-dialogue-body').textContent")
    check('does not need this' in text and 'needs a link only' in text, 'the help text says a host polled by SNMP needs a link only', text[-160:])
    check(js("Array.from(document.querySelectorAll('#topology-device-class option')).map(o => o.textContent)") == ['Router', 'Switch', 'Access point', 'Phone', 'Station', 'Other'], 'the classes: Router, Switch, Access point, Phone, Station, Other')
    type_into('#topology-device-name', 'ISP-Router')
    click("document.querySelector('.topology-port-add')")
    type_into('.topology-port-row:nth-child(1) .topology-port-name-input', 'wan0')
    click("document.querySelector('.topology-port-add')")
    type_into('.topology-port-row:nth-child(2) .topology-port-name-input', 'lan0')
    # a refusal stays in the dialog: the same port name twice
    type_into('.topology-port-row:nth-child(2) .topology-port-name-input', 'wan0')
    click(dialog_button('Create'))
    check(wait("!document.querySelector('.topology-dialog-error').hidden") and 'twice' in js("document.querySelector('.topology-dialog-error').textContent") and left_over() == '0', 'a port name twice: the refusal is shown in the dialog, nothing is created', js("document.querySelector('.topology-dialog-error').textContent"))
    type_into('.topology-port-row:nth-child(2) .topology-port-name-input', 'lan0')
    shot('099-create-dialog.png')
    click(dialog_button('Create'))
    check(wait("!document.getElementById('topology-device-name')"), 'Create closes the dialog', [js("(document.querySelector('.topology-dialog-error') || {}).textContent"), js("window.__alert"), js("Array.from(document.querySelectorAll('.topology-port-name-input')).map(i => i.value)")])
    wait("(%s) === 'ISP-Router'" % TITLE)
    check(js(TITLE) == 'ISP-Router' and js(KIND) == 'Not a Zabbix host · manual', 'the Device is selected, its panel is open: "ISP-Router", kind mark "Not a Zabbix host · manual"', [js(TITLE), js(KIND)])
    check(js("(n => !!n && !!n.element)(view.drawn_nodes.find(n => n.label === 'ISP-Router'))") and js(RING) == 1, 'the Device is drawn (no link) and the selection ring is on it')
    layout_clean('the new Device without a link is at a free place: no overlap, no label on a node, no line through a node')
    check(js("document.querySelector('#topology-canvas g.topology-icon-router') !== null && view.drawn_nodes.find(n => n.label === 'ISP-Router').device_class === 'router'"), 'its icon is the class Router (§12.2)')
    check(db("select count(*) from topo_nodes where type = 'port' and json_unquote(json_extract(attrs, '$.source')) = 'manual'") == '2', 'two manual ports in the database')
    names = js("Array.from(document.querySelectorAll('#topology-details .topology-port-name')).map(e => e.textContent.trim())")
    check(sorted(names) == ['lan0', 'wan0'], 'the Ports section lists wan0 and lan0 (no neighbor)', names)
    check(js("Array.from(document.querySelectorAll('#topology-details .topology-menu-item')).map(e => e.textContent.trim()).filter(t => ['Rename', 'Delete'].includes(t)).length") == 4, 'each free manual port has Rename and Delete in its menu')
    header = js("Array.from(document.querySelectorAll('#topology-details .topology-node-actions .topology-menu-item')).map(e => e.textContent.trim())")
    check(['Edit device', 'Add port', 'Delete Device'] == [h for h in header if h in ('Edit device', 'Add port', 'Delete Device')], 'the node menu has Edit device, Add port, Delete Device', header)
    check('Device class' in js("document.querySelector('#topology-details .topology-identity').textContent") and 'Router (chosen)' in js("document.querySelector('#topology-details .topology-identity').textContent"), 'Identity: "Device class: Router (chosen)"')

    print('=== Link SW12 <-> lan0 ===')
    js("Array.from(document.querySelectorAll('#topology-details details.topology-port-group')).forEach(d => { d.open = true; })")
    click("(() => { const row = Array.from(document.querySelectorAll('#topology-details .topology-port-row, #topology-details tr')).find(r => r.querySelector('.topology-port-name') && r.querySelector('.topology-port-name').textContent.trim() === 'lan0'); return row.querySelector('.topology-link-button'); })()")
    check(wait("document.getElementById('topology-link-pick').textContent.includes('lan0')"), 'Link on lan0 starts the pick')
    # SW12 is selected by a click on its node in the graph
    click("(() => { const g = Array.from(document.querySelectorAll('#topology-canvas g.topology-node')).find(e => String(e.__data__.label).startsWith('SW12')); return g.querySelector('rect.topology-shape'); })()")
    check(wait("(%s) && (%s).startsWith('SW12')" % (TITLE, TITLE)), 'a click on SW12 opens its panel')
    # open the folded group of free ports and take the first free port
    js("Array.from(document.querySelectorAll('#topology-details details.topology-port-group[data-group=\"no_neighbor\"]')).forEach(d => { d.open = true; })")
    port = js("(() => { const row = Array.from(document.querySelectorAll('#topology-details details[data-group=\"no_neighbor\"] tbody tr'))[0]; return row ? row.querySelector('.topology-port-name').textContent.trim() : null; })()")
    check(port is not None, 'SW12 has a free port', port)
    click("(() => { const row = Array.from(document.querySelectorAll('#topology-details details[data-group=\"no_neighbor\"] tbody tr'))[0]; return row.querySelector('.topology-link-button'); })()")
    time.sleep(2.5)
    edge = db("select e.id from topo_edges e join topo_nodes a on a.id = e.src_id join topo_nodes b on b.id = e.dst_id where e.type = 'physical_link' and json_unquote(json_extract(e.attrs, '$.discovered_via')) = 'manual' and "
              "(json_unquote(json_extract(a.attrs, '$.name')) = 'lan0' or json_unquote(json_extract(b.attrs, '$.name')) = 'lan0') limit 1")
    check(edge != '', 'a manual link between the free port %s of SW12 and lan0 is in the database' % port, edge)
    click("(() => { const g = Array.from(document.querySelectorAll('#topology-canvas g.topology-node')).find(e => String(e.__data__.label) === 'ISP-Router'); return g.querySelector('rect.topology-shape'); })()")
    wait("(%s) === 'ISP-Router'" % TITLE)
    time.sleep(1)
    dash = js("(() => { const i = view.drawn_links.findIndex(l => String(l.edge_id) === '%s'); return i < 0 ? null : getComputedStyle(view.link_selection.nodes()[i]).strokeDasharray; })()" % edge)
    check(dash not in (None, '', 'none'), 'the manual link is drawn dashed (manual channel, §12.3)', [dash, edge, js("view.drawn_links.map(l => [l.edge_id, l.type, l.discovered_via])")])
    check(js("document.querySelectorAll('#topology-details .topology-links-table tr.topology-link-row').length") == 1, 'the Links section of ISP-Router has the link')
    layout_clean('after create and Link the new Device is next to SW12 and the layout is clean: no overlap, no label on a node, no line through a node')
    near = js("(() => { const a = view.drawn_nodes.find(n => n.label === 'ISP-Router'), b = view.drawn_nodes.find(n => n.label.startsWith('SW12')); return Math.round(Math.hypot(a.x - b.x, a.y - b.y)); })()")
    check(near < 330, 'the new Device is placed next to the node it is linked to (%s px)' % near, near)
    shot('099-manual-device-linked.png')

    print('=== rename, refuse, edit, delete ===')
    js("Array.from(document.querySelectorAll('#topology-details details.topology-port-group')).forEach(d => { d.open = true; })")
    click("(() => { const row = Array.from(document.querySelectorAll('#topology-details tr')).find(r => r.querySelector('.topology-port-name') && r.querySelector('.topology-port-name').textContent.trim() === 'wan0'); return row.querySelector('.topology-menu-button'); })()")
    click("(() => { const row = Array.from(document.querySelectorAll('#topology-details tr')).find(r => r.querySelector('.topology-port-name') && r.querySelector('.topology-port-name').textContent.trim() === 'wan0'); return row.querySelector('.topology-port-rename'); })()")
    check(wait("!!document.getElementById('topology-port-name')"), 'Rename opens the port dialog')
    type_into('#topology-port-name', 'wan1')
    click(dialog_button('Update'))
    check(wait("Array.from(document.querySelectorAll('#topology-details .topology-port-name')).map(e => e.textContent.trim()).includes('wan1')") and db("select count(*) from topo_nodes where type = 'port' and json_unquote(json_extract(attrs, '$.name')) = 'wan1'") == '1', 'wan0 is renamed wan1 (the panel and the database)')
    lan0 = db("select id from topo_nodes where type = 'port' and json_unquote(json_extract(attrs, '$.name')) = 'lan0'")
    refused = js("""(async () => { try { await view.request('topology.ports.delete', {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({id: '%s'})}); return 'deleted'; } catch (e) { return e.message; } })()""" % lan0)
    check('link' in refused and db("select count(*) from topo_nodes where id = %s" % lan0) == '1', 'delete of lan0 is refused while it is linked', refused)
    check(js("Array.from(document.querySelectorAll('#topology-details .topology-port-name')).every(e => e.textContent.trim() !== 'lan0')"), 'a linked port is in the Links section, with no Rename or Delete')
    click("document.querySelector('#topology-details .topology-node-actions .topology-menu-button')")
    click("document.querySelector('#topology-details .topology-menu-edit-device')")
    check(wait("!!document.getElementById('topology-device-name')") and js("document.getElementById('topology-device-name').value") == 'ISP-Router', 'Edit device opens the same dialog with the values')
    rows = js("Array.from(document.querySelectorAll('.topology-port-row')).map(r => [r.querySelector('.topology-port-name-input').value, r.querySelector('.topology-port-name-input').disabled])")
    check(sorted(rows) == [['lan0', True], ['wan1', False]], 'the dialog lists the manual ports; the linked one can not be renamed', rows)
    js("(() => { const s = document.getElementById('topology-device-class'); s.value = 'switch'; s.dispatchEvent(new Event('change', {bubbles: true})); })()")
    click(dialog_button('Update'))
    check(wait("!document.getElementById('topology-device-name')") and wait("document.querySelector('#topology-canvas g.topology-icon-switch') !== null && view.drawn_nodes.find(n => n.label === 'ISP-Router').device_class === 'switch'"), 'Edit to class Switch: the icon is the Switch icon')
    check('Switch (chosen)' in wait("(() => { const e = document.querySelector('#topology-details .topology-identity'); return e && e.textContent.includes('Switch (chosen)') ? e.textContent : null; })()"), 'Identity: "Switch (chosen)"')
    click("document.querySelector('#topology-details .topology-node-actions .topology-menu-button')")
    click("document.querySelector('#topology-details .topology-delete-device-button')")
    check(wait("view.drawn_nodes.every(n => n.label !== 'ISP-Router')"), 'Delete Device: the Device is not drawn any more')
    time.sleep(1)
    check(left_over() == '0' and db("select count(*) from topo_edges where id = %s" % edge) == '0' and db("select count(*) from topo_identity_keys k left join topo_nodes n on n.id = k.device_id where n.id is null") == '0',
          'nothing is left in the database: no manual Device, no manual port, no link', [left_over(), db("select count(*) from topo_edges where id = %s" % edge)])
    check(db("select count(*) from topo_nodes where type = 'port' and json_unquote(json_extract(attrs, '$.name')) = '%s' and json_unquote(json_extract(attrs, '$.if_index')) is not null" % port) != '0', 'the port %s of SW12 stays' % port)
finally:
    db("delete from topo_nodes where type = 'device' and json_unquote(json_extract(attrs, '$.source')) = 'manual'")
    chrome.kill()
print('\n%s' % ('%d check(s) FAILED' % failed if failed else 'All manual Device checks passed.'))
sys.exit(1 if failed else 0)
