#!/usr/bin/env python3
"""task 092 §1 (model §12.3 "Hit area of a line"): with the DevTools Input mouse events, the pointer 5 px beside a line (the line is 2 px wide, the hit line 12 px) hovers THAT line; where hit lines of parallel
lines meet, the line nearest to the pointer. Pairs SW12 - SW18 / SW20 / zbx-office-gw1 / zbx-office-gw2 (each is a LAG bundle after §2), then two parallel lines that §2 does not bundle: a manual link is made
between SW12 and SW18 for the test (bundle + manual link), the pointer 5 px beside each of them and at a point between them 2 px nearer to one; the manual link is removed again.
Needs ZBX_UI_USER, ZBX_UI_PASSWORD, google-chrome and the office stand data. Exit code 1 when a check fails."""
import atexit, shutil, json, math, os, re, subprocess, sys, tempfile, time, urllib.request
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import page_shot as P

if not os.environ.get('ZBX_UI_USER') or not os.environ.get('ZBX_UI_PASSWORD'):
    print('ZBX_UI_USER and ZBX_UI_PASSWORD are needed')
    sys.exit(2)
cookie = P.login()


def sql(statement):
    return subprocess.run(['mysql', '-N', '-uzabbix', 'zabbix', '-e', statement], capture_output=True, text=True).stdout.strip()


def post(action, body):
    page = urllib.request.Request(P.BASE + 'zabbix.php?action=topology.view', headers={'Cookie': '%s=%s' % (cookie.name, cookie.value)})
    token = re.search(r'"_csrf_token"\]?:\s*"([0-9a-f]+)"', urllib.request.urlopen(page).read().decode()).group(1)
    request = urllib.request.Request(P.BASE + 'zabbix.php?action=' + action, json.dumps(dict(body, _csrf_token=token)).encode(),
                                     {'Content-Type': 'application/json', 'Cookie': '%s=%s' % (cookie.name, cookie.value)})
    return json.load(urllib.request.urlopen(request))


failed = 0


def check(condition, text, detail=None):
    global failed
    print(('  ok: ' if condition else '  FAIL: ') + text + ('' if condition else ' ' + json.dumps(detail)))
    failed += 0 if condition else 1


def session(host_label='SW12-10-CORE-6000-48G'):
    profile = tempfile.mkdtemp(prefix='hit-')
    atexit.register(shutil.rmtree, profile, ignore_errors=True)      # the Chrome profile is not left in /tmp (task 100)
    port = 9600 + os.getpid() % 300
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
    # the focus host SW12, depth 1: the lines of SW12
    js("""(async () => { const h = Array.from(view.nodes.values()).find(n => n.type === 'host' && n.label === '%s'); jQuery('#hostid').multiSelect('addData', [{id: h.hostid, name: h.name}]);
        document.getElementById('topology-filter-apply').click(); return 1; })()""" % host_label)
    time.sleep(4)
    return chrome, ws, js


def mouse(ws, x, y):
    ws.call('Input.dispatchMouseEvent', type='mouseMoved', x=x, y=y, button='none', buttons=0)


def line_geometry(js, other_label):
    """The hit line of the line(s) between SW12 and `other_label`: [{id, points: [(x, y)] in client coordinates, label}]"""
    return js("""(() => view.line_entries.filter(e => e.link && ['physical_link', 'lag_bundle'].includes(e.link.type)
        && [e.link.source.label, e.link.target.label].includes('%s') && [e.link.source.label, e.link.target.label].includes('SW12-10-CORE-6000-48G')).map(e => {
        const m = e.hit.getScreenCTM(); const len = e.hit.getTotalLength(); const pts = [];
        for (let at = 0; at <= len; at += 2) { const p = e.hit.getPointAtLength(at); pts.push([p.x * m.a + p.y * m.c + m.e, p.x * m.b + p.y * m.d + m.f]); }
        return {label: e.link.label || e.link.type, edge: e.link.edge_id, points: pts}; }))()""" % other_label)


def hovered(js):
    return js("view.hover_entry ? (view.hover_entry.link.label || view.hover_entry.link.type) + '#' + view.hover_entry.link.edge_id : null")


def normal_point(points, fraction, distance):
    i = int(len(points) * fraction)
    (x0, y0), (x1, y1) = points[i - 1], points[min(i + 1, len(points) - 1)]
    length = math.hypot(x1 - x0, y1 - y0) or 1
    nx, ny = -(y1 - y0) / length, (x1 - x0) / length
    return points[i], (points[i][0] + nx * distance, points[i][1] + ny * distance), (nx, ny)


chrome, ws, js = session()
try:
    print('=== the pairs of SW12 (each a LAG bundle: one line) ===')
    for other in ['SW18-09-ACC-2530-48G', 'SW20-07-ACC-2530-48G', 'zbx-office-gw1', 'zbx-office-gw2']:
        lines = line_geometry(js, other)
        check(len(lines) == 1 and 'LAG' not in lines[0]['label'] and lines[0]['label'].endswith('×2'), '%s: one bundle line' % other, [l['label'] for l in lines])
        for side in (5, -5):
            on, off, _ = normal_point(lines[0]['points'], 0.5, side)
            mouse(ws, off[0], off[1])
            time.sleep(0.2)
            h = hovered(js)
            check(h is not None and h.startswith(lines[0]['label']), '%s: the pointer 5 px %s of the line hovers it (the line is 2 px wide)' % (other, 'right' if side > 0 else 'left'), h)
        mouse(ws, 5, 5)
        time.sleep(0.2)
        check(hovered(js) is None, '%s: the pointer away from the line: no hover' % other)
    print('\n=== two parallel lines that §2 does not bundle: the bundle SW12-SW18 and a manual link ===')
    ports = sql("""SELECT (SELECT p.id FROM topo_nodes p JOIN topo_nodes d ON d.id = p.device_id WHERE p.type = 'port' AND JSON_UNQUOTE(JSON_EXTRACT(d.attrs, '$.sysname')) = 'SW12-10-CORE-6000-48G'
        AND NOT EXISTS (SELECT 1 FROM topo_edges e WHERE e.src_id = p.id OR e.dst_id = p.id) AND JSON_EXTRACT(p.attrs, '$.pseudo') = false ORDER BY p.id LIMIT 1),
        (SELECT p.id FROM topo_nodes p JOIN topo_nodes d ON d.id = p.device_id WHERE p.type = 'port' AND JSON_UNQUOTE(JSON_EXTRACT(d.attrs, '$.sysname')) = 'SW18-09-ACC-2530-48G'
        AND NOT EXISTS (SELECT 1 FROM topo_edges e WHERE e.src_id = p.id OR e.dst_id = p.id) AND JSON_EXTRACT(p.attrs, '$.pseudo') = false ORDER BY p.id LIMIT 1)""").split('\t')
    made = post('topology.ports.link', {'id': ports[0], 'dst_id': ports[1]})
    manual = made.get('edge_id')
    try:
        check(bool(manual), 'a manual link between a free port of SW12 and one of SW18', made)
        chrome.kill()
        chrome, ws, js = session()
        lines = line_geometry(js, 'SW18-09-ACC-2530-48G')
        check(len(lines) == 2 and sorted(l['label'] for l in lines)[0].endswith('×2') or any(l['label'].endswith('×2') for l in lines), 'two lines: the bundle and the manual link (not bundled: other channels)', [l['label'] for l in lines])
        bundle = next(l for l in lines if l['label'].endswith('×2'))
        single = next(l for l in lines if not l['label'].endswith('×2'))
        # the two curves are 16 px apart at the middle: the pointer 5 px beside each, away from the other
        for line, other in ((bundle, single), (single, bundle)):
            i = int(len(line['points']) * 0.5)
            p, q = line['points'][i], other['points'][int(len(other['points']) * 0.5)]
            direction = ((p[0] - q[0]), (p[1] - q[1]))
            norm = math.hypot(*direction) or 1
            target = (p[0] + direction[0] / norm * 5, p[1] + direction[1] / norm * 5)
            mouse(ws, target[0], target[1])
            time.sleep(0.2)
            h = hovered(js)
            check(h is not None and h.endswith('#%s' % (line['edge'] if line['edge'] is not None else 'null')), 'the pointer 5 px beside %s (away from the other line) hovers it' % line['label'], [h, line['edge']])
            # between the two lines, 3 px nearer to this one: the nearest line
            mid = ((p[0] + q[0]) / 2, (p[1] + q[1]) / 2)
            toward = ((p[0] - q[0]) / norm * 3, (p[1] - q[1]) / norm * 3)
            mouse(ws, mid[0] + toward[0], mid[1] + toward[1])
            time.sleep(0.2)
            h = hovered(js)
            check(h is not None and h.endswith('#%s' % (line['edge'] if line['edge'] is not None else 'null')), 'a point between the two lines, 3 px nearer to %s: the nearest one is hovered' % line['label'], [h, line['edge']])
    finally:
        post('topology.link.remove', {'id': str(manual)})
        check(sql("SELECT COUNT(*) FROM topo_edges WHERE id = %s" % int(manual)) == '0', 'the manual link is removed again')
finally:
    chrome.kill()
print('\n%s' % ('%d check(s) FAILED' % failed if failed else 'All hit area checks passed.'))
sys.exit(1 if failed else 0)
