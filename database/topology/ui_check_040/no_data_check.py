"""task 110 §5 (model 2.93 §3.2, §4.8; FR-C 0.37 V-12 items 2-4): a link from a switch that has interface items to an end device that has none, on the real page, 1500 x 900, office stand, real mouse
events (DevTools Input domain). The switch SW15 is focused with its neighbor NA12502000654 (an end Device that no host reports): the tooltip of the line and the link panel show the load of the SW15 side
and say that the end-device side has no data; the panel says why. Screenshots when SHOTS_DIR is set: 110-load-one-side.png (the tooltip of the line), 110-no-data-panel.png (the link panel).
Needs ZBX_UI_USER, ZBX_UI_PASSWORD, google-chrome and the office stand data. Exit code 1 when a check fails.
"""
import atexit, base64, json, os, shutil, subprocess, sys, tempfile, time, urllib.request
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import page_shot as P

if not os.environ.get('ZBX_UI_USER') or not os.environ.get('ZBX_UI_PASSWORD'):
    print('ZBX_UI_USER and ZBX_UI_PASSWORD are needed')
    sys.exit(2)
cookie = P.login()
failed = 0
SWITCH, SWITCH_HOST, END_DEVICE = 'SW15-10-POE-6000-24G', 'SW15-10-POE-6000-24G', 'NA12502000654'


def check(condition, text, detail=None):
    global failed
    print(('  ok: ' if condition else '  FAIL: ') + text + ('' if condition else ' ' + json.dumps(detail)))
    failed += 0 if condition else 1


def db(statement):
    return subprocess.run(['mysql', '-N', '-uzabbix', 'zabbix', '-e', statement], capture_output=True, text=True).stdout.strip()


profile = tempfile.mkdtemp(prefix='nodata-')
atexit.register(lambda: (time.sleep(2), shutil.rmtree(profile, ignore_errors=True)))      # the Chrome profile is not left in /tmp (task 100)
port = 9900 + os.getpid() % 90
chrome = subprocess.Popen(['google-chrome', '--headless=new', '--no-sandbox', '--disable-gpu', '--remote-debugging-port=%d' % port, '--user-data-dir=' + profile,
                           '--window-size=1500,900', 'about:blank'], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)


def shot(ws, name):
    folder = os.environ.get('SHOTS_DIR')
    if folder:
        with open(os.path.join(folder, name), 'wb') as f:
            f.write(base64.b64decode(ws.call('Page.captureScreenshot', format='png')['data']))


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

    def mouse(kind, x, y, count=1):
        ws.call('Input.dispatchMouseEvent', type=kind, x=x, y=y, button='left' if kind != 'mouseMoved' else 'none', buttons=1 if kind == 'mousePressed' else 0, clickCount=count)

    host = db("select hostid, name from hosts where host = '%s'" % SWITCH_HOST).split('\t')
    check(len(host) == 2, 'the office host %s is in the lab' % SWITCH_HOST, host)
    js("""(() => { jQuery('#hostid').multiSelect('addData', [{id: %s, name: %s}]); document.getElementById('topology-filter-hops').value = '1'; document.getElementById('topology-filter-apply').click(); return 1; })()"""
       % (json.dumps(host[0]), json.dumps(host[1])))
    time.sleep(2)
    js("(view.leaf_groups ?? []).forEach(g => view.unfoldGroup(g.parent))")      # set-up: the end Devices of SW15 are in a leaf group, folded until the group is unfolded
    for _ in range(60):
        if js("view.drawn_nodes.some(n => n.label === %s)" % json.dumps(END_DEVICE)):
            break
        time.sleep(0.5)
    time.sleep(1.5)
    check(js("view.drawn_nodes.some(n => n.label === %s)" % json.dumps(END_DEVICE)), 'the end Device %s is drawn next to %s' % (END_DEVICE, SWITCH))
    js("""(async () => { await view.selectNode(Array.from(view.nodes.values()).find(n => n.label === %s && n.type === 'device')); return 1; })()""" % json.dumps(SWITCH))
    time.sleep(2.5)

    # the Links row of the end Device: the load of this port (the switch side) is in the row; a click on the row opens the link panel
    rows = js("""Array.from(document.querySelectorAll('#topology-details .topology-links-table tr.topology-link-row')).filter(r => !r.hidden && r.textContent.indexOf(%s) >= 0).map(r => ({edges: r.dataset.edges, text: r.textContent.trim().replace(/\\s+/g, ' ')}))""" % json.dumps(END_DEVICE))
    check(len(rows) == 1, 'the Links section has one row for the link to %s' % END_DEVICE, rows)
    edge = rows[0]['edges'] if rows else None

    # the tooltip of the line: the load of the switch side, "no data" for the side of the end Device
    tip = None
    for u in (0.5, 0.4, 0.6, 0.3, 0.7):
        point = js("""(u => { const e = view.line_entries.find(x => x.link && String(x.link.edge_id) === %s); const p = e.hit.getPointAtLength(e.hit.getTotalLength() * u);
            const t = new DOMPoint(p.x, p.y).matrixTransform(e.hit.getScreenCTM()); return {x: t.x, y: t.y}; })(%s)""" % (json.dumps(edge), u))
        mouse('mouseMoved', point['x'] - 3, point['y'] - 3)
        mouse('mouseMoved', point['x'], point['y'])
        time.sleep(0.5)
        tip = js("(() => { const t = document.getElementById('topology-tooltip'); return t && !t.hidden ? t.textContent : null; })()")
        if tip and END_DEVICE in tip:
            break
    check(tip is not None and ' of ' in tip and 'bps' in tip and (END_DEVICE + ': no data') in tip, 'the tooltip of the line shows the load of the %s side and says that the %s side has no data' % (SWITCH, END_DEVICE), tip)
    shot(ws, '110-load-one-side.png')
    mouse('mouseMoved', 700, 850)

    # the link panel (a real click on the line): both sides, the reason for the side without items
    mouse('mouseMoved', point['x'] - 3, point['y'] - 3)
    mouse('mouseMoved', point['x'], point['y'])
    mouse('mousePressed', point['x'], point['y'])
    mouse('mouseReleased', point['x'], point['y'])
    time.sleep(2.0)
    panel = js("""Array.from(document.querySelectorAll('#topology-details tr')).map(r => r.textContent.trim().replace(/\\s+/g, ' '))""")
    title = js("(document.getElementById('topology-details-title') || {textContent: ''}).textContent.trim()")
    check(title == 'Link details', 'a click on the line opens the link panel', title)
    switch_load = [r for r in panel if r.startswith(SWITCH + ' load')]
    end_load = [r for r in panel if r.startswith(END_DEVICE + ' load')]
    check(len(switch_load) == 1 and 'in ' in switch_load[0] and 'bps' in switch_load[0] and 'no data' not in switch_load[0], 'the panel shows the load of the %s side' % SWITCH, panel)
    check(len(end_load) == 1 and 'no data' in end_load[0] and 'not a monitored host' in end_load[0], 'the panel says why the %s side has no data' % END_DEVICE, end_load)
    shot(ws, '110-no-data-panel.png')
    # FR-C V-12 item 4: the reason is readable, not cut by the width of the panel (the whole text is on screen, in more than one line when needed)
    clipped = js("""(() => { const r = Array.from(document.querySelectorAll('#topology-details tr')).find(x => x.textContent.trim().startsWith(%s + ' load'));
        const c = r.cells[1]; const s = c.querySelector('.topology-wrap') || c; return {text: s.textContent.trim(), cut: c.scrollWidth > c.clientWidth + 1 || getComputedStyle(c).textOverflow === 'ellipsis' && getComputedStyle(c).whiteSpace === 'nowrap' && c.scrollWidth > c.clientWidth, lines: Math.round(s.getBoundingClientRect().height / parseFloat(getComputedStyle(s).lineHeight || 16))}; })()""" % json.dumps(END_DEVICE))
    check(not clipped['cut'] and clipped['text'].endswith('monitored host') and clipped['lines'] >= 2, 'the reason for %s is wrapped, not cut by the panel width' % END_DEVICE, clipped)

    # a reporter's port whose host has no interface items names the missing template (the reason of the server): through the same page function, with the reason that the server gives
    text = js("view.noDataText('no_items')")
    check('template with interface discovery' in text and 'official SNMP interface template' in text and 'Topology by SNMP' in text and 'Network Generic Device by SNMP' not in text,
          'the reason "no interface items" says that the host needs a template with interface discovery (an example, not one template by name)', text)
finally:
    chrome.kill()
print('\n%s' % ('%d check(s) FAILED' % failed if failed else 'All no-data checks passed.'))
sys.exit(1 if failed else 0)
