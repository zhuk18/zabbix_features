#!/usr/bin/env python3
"""task 094 §6 (model §12.5 "Structure of a node panel", 2.75): the side panel of a node on the real page, 1500 x 900, office stand.

SW12 (Host) and zbx-office-gw1 (Host): the sections in the order Problems, Links, Ports, Identity (folded); no "Device details" on any node panel; the header has the class icon, the name, the kind
mark and Focus, Neighbor reports and the menu; no button or link on a row of the Links section but the neighbor, the toggle of a bundle and the row menu; one line per row; the name of a Host opens
the standard Zabbix host menu; Focus sets the filter; a hover on a row highlights its line and a hover on a line marks its row (DevTools Input mouse events); Unlink asks for a confirmation (cancelled).
A FortiAP Device without association: Identity (Addresses open), Links, Ports. A Proxy with a Device: the stand has none, the order is covered by the node tests.
Needs ZBX_UI_USER, ZBX_UI_PASSWORD, google-chrome and the office stand data. Exit code 1 when a check fails.
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


PANEL = """(() => {
    const d = document.getElementById('topology-details');
    const title = document.getElementById('topology-details-title');
    const sections = Array.from(d.querySelectorAll(':scope > details.topology-section')).map(e => e.className.replace('topology-section', '').replace('topology-', '').trim() + (e.open ? '+' : '-'));
    const row_controls = Array.from(d.querySelectorAll('.topology-links-table tr.topology-link-row')).filter(r => !r.hidden).map(r => Array.from(r.querySelectorAll('button, a')).filter(e => !e.closest('.topology-menu-list')
        && !e.classList.contains('topology-menu-button') && !e.classList.contains('topology-bundle-toggle') && !e.classList.contains('topology-neighbor')).length);
    const rows = Array.from(d.querySelectorAll('.topology-links-table tr.topology-link-row')).filter(r => !r.hidden);
    return {sections, title_visible: !title.hidden && title.offsetHeight > 0, title_text: title.textContent, has_device_details: d.textContent.includes('Device details'),
        name: (d.querySelector('.topology-node-name') || {textContent: ''}).textContent.trim(), icon: !!d.querySelector('.topology-node-icon'), kind: (d.querySelector('.topology-node-kind') || {textContent: ''}).textContent,
        actions: Array.from(d.querySelectorAll('.topology-node-actions > *')).map(e => e.classList.contains('topology-menu') ? e.querySelector('.topology-menu-button').textContent : (e.textContent || '').trim()),
        summary: (d.querySelector('.topology-node-summary') || {textContent: ''}).textContent,
        row_controls: row_controls.filter(n => n > 0).length, rows: rows.length, row_heights: rows.map(r => Math.round(r.getBoundingClientRect().height)),
        row_overflow: rows.filter(r => Array.from(r.children).some(c => c.scrollWidth > c.clientWidth + 1 && !c.classList.contains('topology-link-menu'))).length,
        panel_width: Math.round(d.getBoundingClientRect().width)};
})()"""

chrome, ws, js = session()
try:
    print('=== SW12 (Host) ===')
    select(js, 'SW12-10-CORE-6000-48G', 'host')
    r = js(PANEL)
    print('  ', json.dumps(r))
    check(r['sections'] == ['problems+', 'links+', 'ports+', 'identity-'], 'sections: Problems, Links, Ports, Identity (folded)', r['sections'])
    check(not r['title_visible'] and not r['has_device_details'], 'no "Device details" on the node panel', [r['title_text'], r['title_visible']])
    check(r['icon'] and r['name'].startswith('SW12-10-CORE-6000-48G') and 'Zabbix host' in r['name'], 'header: class icon, name, kind mark', r['name'])
    check(r['actions'][0] == 'Focus' and 'Neighbor reports' in r['actions'] and r['actions'][-1] == '⋯', 'header actions: Focus, Neighbor reports, the menu', r['actions'])
    check(re_match := bool(__import__('re').match(r'^\d+ links( · \d+ LAG)?( · \d+ ports( \(\d+ up\))?)?$', r['summary'])), 'summary line "<N> links · <L> LAG · <P> ports (<U> up)"', r['summary'])
    check(r['rows'] >= 5 and r['row_controls'] == 0, 'no button or link on a Links row but the neighbor, the toggle and the menu', [r['rows'], r['row_controls']])
    check(all(h <= 36 for h in r['row_heights']) and r['row_overflow'] == 0, 'one line per row at 1500 x 900 (height <= 36 px, no cell wider than its column)', [r['row_heights'], r['row_overflow']])
    # the Links section replaces the group Connected; the Ports section has the others, folded
    groups = js("Array.from(document.querySelectorAll('#topology-details details.topology-port-group')).map(e => [e.dataset.group, e.open, e.querySelector('summary').textContent.trim()])")
    check(groups and all(not g[1] for g in groups) and 'connected' not in [g[0] for g in groups], 'Ports: the other groups, folded, and no group Connected', groups)
    check(any(g[0] == 'no_neighbor' and 'up' in g[2] and 'down' in g[2] for g in groups) and any(g[0] == 'lag' and g[2].endswith(')') for g in groups), 'the group headers have counts', groups)

    print('=== the host menu (the standard Zabbix one) ===')
    js("document.querySelector('#topology-details .topology-host-name').click()")
    time.sleep(2)
    menu = js("(() => { const m = document.querySelector('.menu-popup'); return m ? Array.from(m.querySelectorAll('a')).map(a => a.textContent.trim()).filter(Boolean) : null; })()")
    check(menu is not None and len(menu) >= 3, 'the name of the Host opens the standard host menu', menu)
    js("document.body.click()")
    time.sleep(0.5)

    print('=== a row and its line ===')
    js("document.body.click()")
    row = js("""(() => { const r = Array.from(document.querySelectorAll('#topology-details .topology-links-table tr.topology-link-row')).filter(x => !x.hidden && !x.classList.contains('topology-link-bundle'))[0];
        r.scrollIntoView({block: 'center'}); const b = r.getBoundingClientRect(); return {x: b.left + b.width / 2, y: b.top + b.height / 2, edges: r.dataset.edges}; })()""")
    time.sleep(0.3)
    row = js("""(() => { const r = Array.from(document.querySelectorAll('#topology-details .topology-links-table tr.topology-link-row')).filter(x => !x.hidden && !x.classList.contains('topology-link-bundle'))[0];
        const b = r.getBoundingClientRect(); return {x: b.left + b.width / 2, y: b.top + b.height / 2, edges: r.dataset.edges}; })()""")
    mouse(ws, row['x'], row['y'])
    time.sleep(0.4)
    state = js("(() => ({hover: !!view.hover_entry, edge: view.hover_entry ? String(view.hover_entry.link.edge_id) : null, glow: document.querySelectorAll('.topology-link-hover').length, row: document.querySelectorAll('tr.topology-row-hover').length}))()")
    check(state['hover'] and state['edge'] == row['edges'] and state['glow'] >= 1, 'a hover on a row highlights its line', [state, row['edges']])
    mouse(ws, 700, 700)
    time.sleep(0.3)
    check(js("!view.hover_entry"), 'the pointer leaves the row: no hover on the line')
    # a hover on a line marks its row: the pointer on the middle of the line of a row
    point = js("""(() => { const e = view.line_entries.find(x => x.link && x.link.type === 'physical_link'); const p = e.hit.getPointAtLength(e.hit.getTotalLength() * 0.5);
        const t = new DOMPoint(p.x, p.y).matrixTransform(e.hit.getScreenCTM()); return {x: t.x, y: t.y, edge: String(e.link.edge_id)}; })()""")
    mouse(ws, point['x'], point['y'])
    time.sleep(0.4)
    marked = js("Array.from(document.querySelectorAll('tr.topology-row-hover')).map(r => r.dataset.edges)")
    check(point['edge'] in ','.join(marked), 'a hover on a line marks its row in the panel of one of its ends', [point, marked])
    mouse(ws, 700, 700)

    print('=== Unlink asks for a confirmation ===')
    before = js("document.querySelectorAll('#topology-details .topology-links-table tr.topology-link-row').length")
    js("window.__asked = 0; window.confirm = () => { window.__asked++; return false; }")
    js("""(() => { const r = Array.from(document.querySelectorAll('#topology-details .topology-links-table tr.topology-link-row')).filter(x => !x.hidden && !x.classList.contains('topology-link-bundle'))[0];
        r.querySelector('.topology-menu-button').click(); })()""")
    time.sleep(0.3)
    items = js("Array.from(document.querySelectorAll('#topology-details .topology-menu-list:not([hidden]) .topology-menu-item')).map(e => e.textContent.trim())")
    check(items == ['Link details', 'Neighbor reports', 'Unlink'], 'the row menu: Link details, Neighbor reports, Unlink', items)
    js("document.querySelector('#topology-details .topology-menu-list:not([hidden]) .topology-remove-link-button, #topology-details .topology-menu-list:not([hidden]) .topology-unlink-button').click()")
    time.sleep(1.0)
    after = js("[window.__asked, document.querySelectorAll('#topology-details .topology-links-table tr.topology-link-row').length]")
    check(after[0] == 1 and after[1] == before, 'Unlink asks for a confirmation; cancelled, the link stays', [after, before])
    # Link details opens the link panel
    js("""(() => { const r = Array.from(document.querySelectorAll('#topology-details .topology-links-table tr.topology-link-row')).filter(x => !x.hidden && !x.classList.contains('topology-link-bundle'))[0];
        r.querySelector('.topology-menu-button').click(); })()""")
    time.sleep(0.2)
    js("document.querySelector('#topology-details .topology-menu-list:not([hidden]) .topology-link-details').click()")
    time.sleep(0.5)
    check(js("document.getElementById('topology-details-title').textContent") == 'Link details', 'Link details opens the panel of the link')

    print('=== zbx-office-gw1 (Host) ===')
    select(js, 'zbx-office-gw1', 'host')
    r = js(PANEL)
    check(r['sections'] == ['problems+', 'links+', 'ports+', 'identity-'] and not r['has_device_details'], 'gw1: sections in order, no "Device details"', r['sections'])
    print('=== a FortiAP Device without association ===')
    js("(async () => { const n = Array.from(view.nodes.values()).find(n => n.type === 'device' && String(n.label).startsWith('FortiAP')); await view.selectNode(n); return n.label; })()")
    time.sleep(1.5)
    r = js(PANEL)
    print('  ', json.dumps(r))
    check(r['sections'][:2] == ['identity+', 'links+'] and r['sections'][2:] in ([], ['ports+']) and not r['has_device_details'], 'FortiAP: Identity, Links, Ports (Ports only when it has a group besides Connected)', r['sections'])
    check('Not a Zabbix host' in r['name'], 'kind mark "Not a Zabbix host"', r['name'])
    check(js("document.querySelector('#topology-details details.topology-addresses').open"), 'the Addresses are open inside Identity')
    print('=== Focus ===')
    select(js, 'zbx-office-gw1', 'host')
    hostid = js("(() => { const n = Array.from(view.nodes.values()).find(n => n.label === 'zbx-office-gw1' && n.type === 'host'); return String(n.hostid); })()")
    js("document.querySelector('#topology-details .topology-focus-button').click()")
    time.sleep(4)
    focus = js("[String(jQuery('#hostid').multiSelect('getData')[0].id), String(view.applied_hostid)]")
    check(focus == [hostid, hostid], 'Focus sets the Focus host and applies the filter', [focus, hostid])

finally:
    chrome.kill()
print('\n%s' % ('%d check(s) FAILED' % failed if failed else 'All node panel checks passed.'))
sys.exit(1 if failed else 0)
