#!/usr/bin/env python3
"""ui_check_040: a screenshot of the REAL topology page (action=topology.view) of the lab Zabbix, logged in, in headless Chrome (task 040: a UI check is a
check of the real page, not of an offline harness).

The login goes through the frontend's own login form (index.php); the session cookie is handed to Chrome over the DevTools protocol. The user and the
password are read from the environment (ZBX_UI_USER, ZBX_UI_PASSWORD) and never written to a file. Standard library only (a minimal WebSocket client).

    ZBX_UI_USER=... ZBX_UI_PASSWORD=... python3 page_shot.py --host SW13-10-ACC-6000-48G --depth 1 --out shot.png [--group Test] [--width 1400 --height 900]
"""
import argparse
import base64
import http.cookiejar
import json
import os
import shutil
import socket
import struct
import subprocess
import sys
import tempfile
import time
import urllib.parse
import urllib.request

BASE = os.environ.get('ZBX_UI_URL', 'http://localhost/zabbix/ui/')


def login():
    jar = http.cookiejar.CookieJar()
    opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))
    data = urllib.parse.urlencode({'name': os.environ['ZBX_UI_USER'], 'password': os.environ['ZBX_UI_PASSWORD'], 'autologin': '1', 'enter': 'Sign in'}).encode()
    opener.open(BASE + 'index.php', data).read()
    cookie = next((c for c in jar if c.name == 'zbx_session'), None)
    if cookie is None:
        sys.exit('login failed: no zbx_session cookie')
    # a session cookie that does not open the page means the login was refused
    page = opener.open(BASE + 'zabbix.php?action=topology.view').read().decode('utf-8', 'replace')
    if 'topology-canvas' not in page:
        sys.exit('login failed: the topology page did not open')
    return cookie


class WebSocket:
    """Enough of RFC 6455 for the DevTools protocol: text frames out (masked), text frames in (any length, fragments)."""

    def __init__(self, url):
        u = urllib.parse.urlparse(url)
        self.sock = socket.create_connection((u.hostname, u.port))
        key = base64.b64encode(os.urandom(16)).decode()
        self.sock.sendall(('GET %s HTTP/1.1\r\nHost: %s:%d\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Key: %s\r\n'
                           'Sec-WebSocket-Version: 13\r\n\r\n' % (u.path, u.hostname, u.port, key)).encode())
        head = b''
        while b'\r\n\r\n' not in head:
            head += self.sock.recv(1)
        if b' 101 ' not in head.split(b'\r\n')[0]:
            raise RuntimeError('websocket handshake failed: %r' % head[:200])
        self.next_id = 0

    def _recv_exact(self, n):
        buf = b''
        while len(buf) < n:
            chunk = self.sock.recv(n - len(buf))
            if not chunk:
                raise RuntimeError('websocket closed')
            buf += chunk
        return buf

    def send(self, text):
        payload = text.encode()
        mask = os.urandom(4)
        header = bytes([0x81])
        n = len(payload)
        if n < 126:
            header += bytes([0x80 | n])
        elif n < 65536:
            header += bytes([0x80 | 126]) + struct.pack('>H', n)
        else:
            header += bytes([0x80 | 127]) + struct.pack('>Q', n)
        self.sock.sendall(header + mask + bytes(b ^ mask[i % 4] for i, b in enumerate(payload)))

    def recv(self):
        message = b''
        while True:
            b1, b2 = self._recv_exact(2)
            n = b2 & 0x7f
            if n == 126:
                n = struct.unpack('>H', self._recv_exact(2))[0]
            elif n == 127:
                n = struct.unpack('>Q', self._recv_exact(8))[0]
            message += self._recv_exact(n)
            if b1 & 0x80:
                return message.decode('utf-8', 'replace')

    def call(self, method, **params):
        self.next_id += 1
        my_id = self.next_id
        self.send(json.dumps({'id': my_id, 'method': method, 'params': params}))
        while True:
            answer = json.loads(self.recv())
            if answer.get('id') == my_id:
                if 'error' in answer:
                    raise RuntimeError('%s: %s' % (method, answer['error']))
                return answer.get('result', {})


# task 116: the checks wait for a STATE of the page, not for a time. One helper, `wait_state`: poll a condition on the page until it is true (a long timeout, 60 s by default); on a timeout
# raise WaitTimeout with the condition, the time, the console of the page and a screenshot (in TOPO_EVIDENCE_DIR). The polling interval is not a wait for the page: it only sets how
# often the condition is read.
PROBE_JS = """(() => { if (window.__topo_console) { return; } window.__topo_console = [];
    const keep = (kind, args) => { try { window.__topo_console.push(kind + ': ' + Array.from(args).map(a => (a && a.stack) || String(a)).join(' ').slice(0, 400)); } catch (e) {} };
    ['error', 'warn'].forEach(kind => { const old = console[kind]; console[kind] = function () { keep(kind, arguments); return old.apply(this, arguments); }; });
    window.addEventListener('error', e => keep('error', [e.message + ' at ' + e.filename + ':' + e.lineno]));
    window.addEventListener('unhandledrejection', e => keep('unhandledrejection', [e.reason]));
    // wait until condition() is true (polled every 50 ms), at most `limit` ms; the error names what was waited for
    window.__topo_wait = async (condition, what, limit = 60000) => { const t = Date.now(); while (!condition()) { if (Date.now() - t > limit) { throw new Error('timeout waiting for: ' + what); } await new Promise(r => setTimeout(r, 50)); } }; })()"""


class WaitTimeout(RuntimeError):
    pass


def install_probe(ws):
    """Collect the console (errors, warnings, unhandled rejections) of every page the browser opens from now on; call it before the navigation."""
    ws.call('Page.addScriptToEvaluateOnNewDocument', source=PROBE_JS)


def wait_state(ws, js, condition, what, timeout=60.0, interval=0.1, return_value=False):
    """Wait until the JavaScript expression `condition` is true on the page. Returns the seconds it took (or, with return_value, the value of the expression). `what` says in words
    what the check waits for."""
    started = time.time()
    last_error = None
    while time.time() - started < timeout:
        try:
            # a DOM element is not returned by value (it would come back as {}): it counts as true
            value = js('((value) => (value instanceof Element ? true : value))(%s)' % condition)
            if value:
                return value if return_value else time.time() - started
            last_error = None
        except RuntimeError as error:      # the page script failed: the page may be between two documents, try again until the timeout
            last_error = error
        time.sleep(interval)
    try:
        console = js('(window.__topo_console || []).join("\\n") || "(empty)"')
    except Exception as error:
        console = 'not readable: %s' % error
    folder = os.environ.get('TOPO_EVIDENCE_DIR', '/tmp/topo_evidence')
    shot = os.path.join(folder, 'timeout-%s-%d.png' % (time.strftime('%Y%m%d-%H%M%S'), os.getpid()))
    try:
        os.makedirs(folder, exist_ok=True)
        with open(shot, 'wb') as f:
            f.write(base64.b64decode(ws.call('Page.captureScreenshot', format='png')['data']))
    except Exception as error:
        shot = 'no screenshot (%s)' % error
    try:
        state = js('typeof view !== "undefined" ? JSON.stringify({idle: view.idle, busy: view.busy, pending: view.pending_requests, loads: view.loads_done, first_load_done: !!view.first_load_done}) : "no view"')
    except Exception as error:
        state = 'not readable: %s' % error
    raise WaitTimeout('timeout after %.0f s waiting for: %s\n  condition: %s\n  at %s\n  page state: %s\n  last script error: %s\n  screenshot: %s\n  console:\n%s'
                      % (timeout, what, condition, time.strftime('%H:%M:%S'), state, last_error, shot, console))


def settled(ws, js, what='the page is idle (no load, no action, no request)', timeout=60.0):
    """The page has finished what it was doing (view.idle, see the page's script)."""
    return wait_state(ws, js, "typeof view !== 'undefined' && view.idle", what, timeout)


def until(js, condition, what, timeout=60.0):
    """wait_state for a check that has only `js` (the function open_page returns)."""
    return wait_state(js.ws, js, condition, what, timeout)


def idle(js, what='the page is idle (no load, no action, no request)', timeout=60.0):
    return settled(js.ws, js, what, timeout)


def node_menu(js, label, choose=None, node_type=None):
    """Task 117 section 3: a click on the label of a node on the graph opens its menu. Returns the text of the menu; with `choose`, the item with that text is clicked and the page is awaited."""
    js("""(() => { const g = Array.from(document.querySelectorAll('#topology-canvas g.topology-node')).find(e => String(e.__data__.label) === %s%s); const t = g.querySelector('text.topology-label');
        const b = t.getBoundingClientRect(); t.dispatchEvent(new MouseEvent('click', {bubbles: true, clientX: b.left + b.width / 2, clientY: b.top + b.height / 2})); })()""" % (json.dumps(label), (" && e.__data__.type === %s" % json.dumps(node_type)) if node_type else ''))
    return _menu_choose(js, choose)


def line_menu(js, entry_js, choose=None):
    """A click on a line of the graph (`entry_js`: an expression for its line entry, view.line_entries[...]) opens the menu of the line."""
    js("""(() => { const e = %s; const p = e.hit.getPointAtLength(e.hit.getTotalLength() * 0.5); const t = new DOMPoint(p.x, p.y).matrixTransform(e.hit.getScreenCTM());
        e.hit.dispatchEvent(new MouseEvent('click', {bubbles: true, clientX: t.x, clientY: t.y})); })()""" % entry_js)
    return _menu_choose(js, choose)


def _menu_choose(js, choose):
    until(js, "document.querySelector('.menu-popup-item')", 'the menu is open')
    text = js("(() => { const m = document.querySelector('.menu-popup'); return m.textContent.replace(/\\s+/g, ' ').trim(); })()")
    if choose is not None:
        found = js("(() => { const e = Array.from(document.querySelectorAll('.menu-popup-item')).find(e => e.textContent.trim().startsWith(%s)); if (!e) return false; e.click(); return true; })()" % json.dumps(choose))
        if not found:
            raise AssertionError('the menu has no item "%s": %s' % (choose, text))
        idle(js)
    return text


def after_input(js, what='the page is idle after the input (no load, no action, no request)'):
    """After a click, a key or a mouse move: the browser has drawn the frames that apply the styles, and the page has finished what the input started (a load, a guarded action).
    The input handlers run before dispatchMouseEvent (or a click()) returns, so a request that an input starts is already counted when this is called."""
    next_frames(js)
    return idle(js, what)


def next_frames(js, count=2):
    """Let the browser draw `count` frames (a drawn change of a style or a zoom transform is in the page after it): a frame is a state, not a time."""
    js('new Promise(resolve => { let n = %d; const step = () => (--n <= 0 ? resolve(1) : requestAnimationFrame(step)); requestAnimationFrame(step); })' % count)


READY_JS = "document.readyState === 'complete' && typeof view !== 'undefined' && view.first_load_done && view.drawn_nodes && view.drawn_nodes.length > 0 && !!document.getElementById('hostid')"


def open_page(cookie, prefix, port_base, width=1500, height=900, action='topology.view', ready=READY_JS):
    """Start headless Chrome with its own profile, open the page logged in and wait for its first load (task 116: one start for all checks). Returns (chrome, ws, js); the browser is
    stopped and the profile removed at exit. `js(expr)` evaluates an expression on the page (promises are awaited) and raises RuntimeError when the page script fails."""
    import atexit
    profile = tempfile.mkdtemp(prefix=prefix + '-')
    port = port_base + os.getpid() % 90
    chrome = subprocess.Popen(['google-chrome', '--headless=new', '--no-sandbox', '--disable-gpu', '--remote-debugging-port=%d' % port, '--user-data-dir=' + profile,
                               '--window-size=%d,%d' % (width, height), 'about:blank'], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)

    def cleanup():
        chrome.kill()
        try:
            chrome.wait(timeout=30)      # the browser has exited when wait() returns: a state, not a pause
        except subprocess.TimeoutExpired:
            pass
        shutil.rmtree(profile, ignore_errors=True)      # the Chrome profile is not left in /tmp (task 100)
    atexit.register(cleanup)
    chrome.close = cleanup      # a check that opens several browsers one after the other stops each with chrome.close()
    for _ in range(600):      # the DevTools port answers when the browser has started (up to 60 s on a loaded machine)
        try:
            page = next(t for t in json.load(urllib.request.urlopen('http://127.0.0.1:%d/json' % port)) if t.get('type') == 'page')
            break
        except Exception:
            time.sleep(0.1)
    else:
        raise RuntimeError('chrome did not start within 60 s (DevTools port %d)' % port)
    ws = WebSocket(page['webSocketDebuggerUrl'])
    ws.call('Network.enable')
    ws.call('Network.setCookie', name=cookie.name, value=cookie.value, url=BASE)
    ws.call('Emulation.setDeviceMetricsOverride', width=width, height=height, deviceScaleFactor=1, mobile=False)
    ws.call('Page.enable')
    install_probe(ws)
    ws.call('Page.navigate', url=BASE + 'zabbix.php?action=' + action)

    def js(expr):
        r = ws.call('Runtime.evaluate', expression=expr, awaitPromise=True, returnByValue=True)
        if r.get('exceptionDetails'):
            raise RuntimeError('page script failed: %s' % r['exceptionDetails'])
        return r.get('result', {}).get('value')
    js.ws = ws      # `until(js, ...)` and `idle(js)` need the connection for the screenshot of a timeout

    wait_state(ws, js, ready, 'the page %s is complete and has made its first load' % action)
    if action.startswith('topology.view') and 'view=lastrun' not in action:      # the graph view has the script that counts loads and requests (view.idle); the last run view has none
        settled(ws, js, 'the first load has finished (no request, no action)')
    return chrome, ws, js


# Checks of model §12.1 "Layout" (task 042), run in the real page after the filter (and --select)
# task 098 §2: the evidence of an overlap failure (the boxes with coordinates, the overlapping pairs and by how many px, the label places, the node positions); the page script of the checks
# `overlap` and `arc` has it as `collect(tag)`, main() writes it into a folder with a screenshot
COLLECT_JS = '''
        // task 100 (model §12.1 "No line through a node", 2.84): the drawn lines (the hit path of each line entry, in the coordinates of the scene) that pass through the box of a node that is not
        // one of their ends; one entry per (line, node)
        const linesThrough = () => {
            const out = [];
            (view.line_entries || []).forEach(entry => {
                if (!entry.link || !entry.hit) { return; }
                const a = entry.link.source, b = entry.link.target;
                const length = entry.hit.getTotalLength();
                const points = [];
                for (let at = 0; at <= length; at += 3) { points.push(entry.hit.getPointAtLength(at)); }
                view.drawn_nodes.forEach(n => {
                    if (n === a || n === b || String(n.id) === String(a.id) || String(n.id) === String(b.id)) { return; }
                    const left = n.x - n.box.w / 2, right = n.x + n.box.w / 2, top = n.y + n.box.top, bottom = n.y + n.box.bottom;
                    const inside = points.filter(p => p.x > left && p.x < right && p.y > top && p.y < bottom).length;
                    if (inside > 0) {
                        out.push({line: (a.label || a.name) + ' - ' + (b.label || b.name) + (entry.link.type === 'lag_bundle' ? ' (' + entry.link.label + ')' : ''), node: n.label || n.name, px: inside * 3});
                    }
                });
            });
            return out;
        };
        const collect = tag => {
            const box = (name, e) => { const b = e.getBoundingClientRect(); return {name, left: b.left, right: b.right, top: b.top, bottom: b.bottom}; };
            const nodes = Array.from(document.querySelectorAll('#topology-canvas g.topology-node')).map(g => box((g.querySelector('text.topology-label') || {textContent: '?'}).textContent, g));
            const lag_boxes = Array.from(document.querySelectorAll('#topology-canvas text.topology-lag-label')).map(t => box(t.textContent, t));
            const px = (a, c) => ({dx: Math.round((Math.min(a.right, c.right) - Math.max(a.left, c.left)) * 10) / 10, dy: Math.round((Math.min(a.bottom, c.bottom) - Math.max(a.top, c.top)) * 10) / 10});
            const hit2 = (a, c) => a.left < c.right - 0.5 && c.left < a.right - 0.5 && a.top < c.bottom - 0.5 && c.top < a.bottom - 0.5;
            const overlaps = [];
            nodes.forEach((a, i) => { nodes.slice(i + 1).forEach(c => { if (hit2(a, c)) overlaps.push({kind: 'node-node', a: a.name, b: c.name, ...px(a, c)}); }); });
            const mark_boxes = Array.from(document.querySelectorAll('#topology-canvas g.topology-speed-mark')).map(g => box('mark ' + g.getAttribute('data-link'), g));
            mark_boxes.forEach((a, i) => { nodes.concat(lag_boxes).forEach(c => { if (hit2(a, c)) overlaps.push({kind: 'mark-box', a: a.name, b: c.name, ...px(a, c)}); });
                mark_boxes.slice(i + 1).forEach(c => { if (hit2(a, c)) overlaps.push({kind: 'mark-mark', a: a.name, b: c.name, ...px(a, c)}); }); });
            lag_boxes.forEach((a, i) => { nodes.forEach(c => { if (hit2(a, c)) overlaps.push({kind: 'label-node', a: a.name, b: c.name, ...px(a, c)}); });
                lag_boxes.slice(i + 1).forEach(c => { if (hit2(a, c)) overlaps.push({kind: 'label-label', a: a.name, b: c.name, ...px(a, c)}); }); });
            return {tag, lines_through_nodes: linesThrough(), window: [innerWidth, innerHeight], zoom: view.zoom_transform ? {k: view.zoom_transform.k, x: view.zoom_transform.x, y: view.zoom_transform.y} : null,
                overlaps, node_boxes: nodes, label_boxes: lag_boxes,
                label_places: (view.lag_label_places || []).map(p => ({source: String(p.source), target: String(p.target), label: p.label, u: p.u, free: p.free})),
                node_positions: view.drawn_nodes.map(n => ({id: String(n.id), label: n.label, x: Math.round(n.x * 10) / 10, y: Math.round(n.y * 10) / 10, fixed: n.fx !== undefined && n.fx !== null}))};
        };
'''

CHECKS = {
    # the graph area ends at the bottom of the window; the side panel scrolls inside it; the page itself does not scroll
    # model §12.1 "Layout" and "Width" (tasks 042, 043): no page scroll in either direction (the document and Zabbix's own scroll containers), the graph
    # area ends at the bottom of the window (above Zabbix's footer), the side panel scrolls inside it; every element of the page inside the window; every button of the header
    # and the filter bar inside the window; every button and column header of the port table inside the side panel, no column header cut
    'layout': '''(() => {
        const d = document.documentElement, W = d.clientWidth, H = d.clientHeight;
        const ws = document.querySelector('.topology-workspace').getBoundingClientRect();
        const canvas = document.querySelector('#topology-canvas').getBoundingClientRect();
        const panel = document.querySelector('.topology-panel');
        const pr = panel.getBoundingClientRect();
        const panel_right = pr.left + panel.clientLeft + panel.clientWidth;
        const name = e => e.id ? '#' + e.id : (e.textContent || '').trim().slice(0, 24) || e.tagName;
        const containers = ['main', '.wrapper', 'body'].map(sel => document.querySelector(sel)).filter(Boolean);
        const r = {window: W + 'x' + H, doc_scroll: d.scrollWidth + 'x' + d.scrollHeight,
            container_overflow_x: containers.filter(e => e.scrollWidth > e.clientWidth + 1).map(e => e.tagName + '.' + e.className),
            container_overflow_y: containers.filter(e => e.scrollHeight > e.clientHeight + 1 && getComputedStyle(e).overflowY !== 'visible').map(e => e.tagName + '.' + e.className),
            outside_window: Array.from(document.querySelectorAll('.topology-prototype *')).filter(e => { const b = e.getBoundingClientRect(); return b.width > 0 && b.right > W + 1; }).map(name).slice(0, 10),
            header_buttons_out: Array.from(document.querySelectorAll('.topology-filter-bar button, #topology-ingest-run')).filter(e => { const b = e.getBoundingClientRect(); return b.width > 0 && (b.right > W || b.left < 0); }).map(name),
            panel_buttons_out: Array.from(panel.querySelectorAll('table button')).filter(e => { const b = e.getBoundingClientRect(); return b.width > 0 && b.right > panel_right + 0.5; }).length,
            panel_headers_out: Array.from(panel.querySelectorAll('table th')).filter(e => { const b = e.getBoundingClientRect(); return b.width > 0 && b.right > panel_right + 0.5; }).length,
            headers_cut: Array.from(new Set(Array.from(panel.querySelectorAll('table th')).filter(e => e.scrollWidth > e.clientWidth + 1).map(e => e.textContent.trim()))),
            // task 090 (model §12.1): the filter bar is one row (every field and button has the same vertical centre) and the page has no "Links" select and no "Clean all" button
            filter_one_row: (() => { const c = Array.from(document.querySelectorAll('.topology-filter-bar > *')).map(e => { const b = e.getBoundingClientRect(); return b.top + b.height / 2; }); return c.length > 5 && Math.max(...c) - Math.min(...c) < 6; })(),
            removed_controls: ['topology-link-filter', 'topology-clean-all'].filter(id => document.getElementById(id)),
            panel_tables: panel.querySelectorAll('table').length,
            workspace_bottom: Math.round(ws.bottom), below: Math.round((document.querySelector('footer') || {getBoundingClientRect: () => ({top: H})}).getBoundingClientRect().top), canvas_h: Math.round(canvas.height), panel_h: panel.clientHeight,
            // task 117 §2: no toolbar row; the graph area starts at the top of the workspace. Zoom in, zoom out and Fit are icon buttons in the top left corner of the graph area, the legend is a box in its bottom left corner,
            // "Create device" is a button of the page header
            toolbar: (() => { const c = canvas, ids = ['topology-zoom-in', 'topology-zoom-out', 'topology-fit']; const b = ids.map(id => document.getElementById(id).getBoundingClientRect());
                const l = document.getElementById('topology-legend').getBoundingClientRect(), g = document.getElementById('topology-create-device').getBoundingClientRect(), nav = document.querySelector('nav[aria-label="Content controls"]').getBoundingClientRect();
                return {no_toolbar_row: !document.getElementById('topology-toolbar') && Math.abs(canvas.top - ws.top) <= 2, controls_in_corner: b.every(x => x.left >= c.left && x.left - c.left < 40 && x.top >= c.top && x.top - c.top < 130) && b[0].top < b[1].top && b[1].top < b[2].top,
                    legend_bottom_left: l.left >= c.left && l.left - c.left < 20 && c.bottom - l.bottom < 20 && l.bottom <= c.bottom + 1, create_in_header: g.top >= nav.top - 1 && g.bottom <= nav.bottom + 1 && g.right <= nav.right + 1}; })(), panel_content_h: panel.scrollHeight};
        // model §12.1 "Width" (2.38): the page keeps the Zabbix minimum width of main (1200 px). When the content area is narrower (for example a
        // 1280 px window with the menu open), the page scrolls horizontally like every Zabbix page: that is said, not a failure; the other checks are
        // made against the width of the page instead of the window.
        const main = document.querySelector('main');
        const ms = getComputedStyle(main);
        // the width Zabbix needs: min-width of main plus its horizontal padding and border (main is content-box: 1200 + 2 x 10 = 1220 px)
        const main_min = (parseFloat(ms.minWidth) || 0) + (ms.boxSizing === 'border-box' ? 0 : parseFloat(ms.paddingLeft) + parseFloat(ms.paddingRight)
            + parseFloat(ms.borderLeftWidth) + parseFloat(ms.borderRightWidth));
        const content_w = Math.round(document.querySelector('.wrapper').clientWidth);
        r.menu = document.querySelector('.sidebar').classList.contains('is-compact') ? 'collapsed' : 'open';
        r.zabbix_min_width = main_min;
        r.content_w = content_w;
        r.zabbix_min_width_scroll = content_w < main_min;
        if (r.zabbix_min_width_scroll) {
            const page_right = main.getBoundingClientRect().left + main.scrollWidth;
            r.note = 'the content area (' + content_w + ' px) is narrower than the Zabbix minimum width of main with its padding (' + main_min + ' px): the page scrolls horizontally, as every Zabbix page';
            r.container_overflow_x = r.container_overflow_x.filter(name => !name.startsWith('DIV.wrapper'));
            r.outside_window = Array.from(document.querySelectorAll('.topology-prototype *')).filter(e => { const b = e.getBoundingClientRect(); return b.width > 0 && b.right > page_right + 1; }).map(name).slice(0, 10);
            r.header_buttons_out = Array.from(document.querySelectorAll('.topology-filter-bar button, #topology-ingest-run')).filter(e => { const b = e.getBoundingClientRect(); return b.width > 0 && b.right > page_right; }).map(name);
        }
        r.ok = (d.scrollWidth <= W || r.zabbix_min_width_scroll) && d.scrollHeight <= H && !r.container_overflow_x.length && !r.container_overflow_y.length && !r.outside_window.length
            && r.filter_one_row && !r.removed_controls.length && !r.header_buttons_out.length && !r.panel_buttons_out && !r.panel_headers_out && !r.headers_cut.length && r.panel_tables > 0
            && r.workspace_bottom <= H && r.workspace_bottom >= Math.min(H, r.below) - 40 && Math.abs(r.canvas_h - r.panel_h) <= 2
            && Object.values(r.toolbar).every(Boolean);
        return r;
    })()''',
    # model §12.7 (task 076): the last run view (--action topology.view&view=lastrun): the run summary, the list "Needs attention", the filter and the observation list are there;
    # the five outcome numbers are links; no horizontal scroll from the window width where the content area has the Zabbix minimum width (§12.1 "Width"; below it
    # the page scrolls like every Zabbix page); nothing of the page is outside the window; the text of the neighbors is text (no element made from it)
    'lastrun': '''(() => {
        const d = document.documentElement, W = d.clientWidth, H = d.clientHeight;
        const main = document.querySelector('main'), ms = getComputedStyle(main);
        const main_min = (parseFloat(ms.minWidth) || 0) + (ms.boxSizing === 'border-box' ? 0 : parseFloat(ms.paddingLeft) + parseFloat(ms.paddingRight)
            + parseFloat(ms.borderLeftWidth) + parseFloat(ms.borderRightWidth));
        const wrapper = document.querySelector('.wrapper');
        const content_w = Math.round(wrapper.clientWidth);
        const r = {window: W + 'x' + H, doc_scroll: d.scrollWidth + 'x' + d.scrollHeight, content_w, zabbix_min_width: main_min,
            summary_rows: document.querySelectorAll('#topology-lastrun-summary tr').length,
            outcome_links: Array.from(document.querySelectorAll('a[id^="topology-lastrun-outcome-"]')).map(a => a.id.replace('topology-lastrun-outcome-', '')),
            has_attention: !!document.getElementById('topology-lastrun-attention'), has_list: !!document.getElementById('topology-lastrun-list'),
            list_rows: document.querySelectorAll('#topology-lastrun-list tbody tr').length,
            outside_window: Array.from(main.querySelectorAll('*')).filter(e => { const b = e.getBoundingClientRect(); return b.width > 0 && b.right > W + 1; }).map(e => e.id || e.className || e.tagName).slice(0, 8),
            unsafe_nodes: document.querySelectorAll('#topology-lastrun-list img, #topology-lastrun-list script, #topology-lastrun-list b, #topology-lastrun-list i, #topology-lastrun-list iframe, #topology-lastrun-attention img, #topology-lastrun-attention script').length,
            scroll_x: wrapper.scrollWidth > wrapper.clientWidth + 1 || d.scrollWidth > W};
        // main has 10 px of margin on each side: the content area needs 1220 px; a page taller than the window loses 15 px to the vertical scrollbar, so a window that is
        // narrower than that scrolls like every Zabbix page (§12.1 "Width")
        r.min_width_note = content_w < main_min + 20;
        r.ok = r.summary_rows >= 5 && r.outcome_links.length === 7 && r.has_attention && r.has_list && !r.unsafe_nodes && (r.min_width_note || (!r.scroll_x && !r.outside_window.length));
        return r;
    })()''',
    # model §12.5 "Addresses" (task 051): with a Device without association selected, the labels of the section are the full text ("System name", "Hardware ID": not cut
    # to "S." / "H."), no text of the section is wider than the side panel, the section has an address list or "No address reported"
    'addresses': '''(() => {
        const panel = document.querySelector('.topology-panel');
        const section = panel.querySelector('.topology-addresses');
        if (!section) {
            return {ok: false, error: 'no section Addresses: select a Device without association (--select)'};
        }
        const pr = panel.getBoundingClientRect();
        const panel_right = pr.left + panel.clientLeft + panel.clientWidth;
        const labels = Array.from(panel.querySelectorAll('.topology-identity .topology-address-identity label'));  // 2.75: the identity is in the section Identity, Addresses is inside it
        const r = {window: document.documentElement.clientWidth + 'x' + document.documentElement.clientHeight,
            labels: labels.map(t => t.textContent),
            labels_cut: labels.filter(t => t.scrollWidth > t.clientWidth + 1).map(t => t.textContent),
            out_of_panel: Array.from(section.querySelectorAll('*')).filter(e => { const b = e.getBoundingClientRect(); return b.width > 0 && b.right > panel_right + 0.5; }).map(e => e.className || e.tagName).slice(0, 5),
            identity_open: panel.querySelector('details.topology-identity').open, addresses_open: section.open,
            addresses: section.querySelectorAll('.topology-address').length, empty: !!section.querySelector('.topology-empty'),
            // task 090 (model §12.5, 2.70): one line per address with the count "reported by <N> ports of <M> hosts"; the sources are folded, closed by default
            counts: Array.from(section.querySelectorAll('summary.topology-address-count')).map(t => t.textContent),
            sources_open: Array.from(section.querySelectorAll('details.topology-address-sources')).filter(d => d.open).length,
            source_lines_visible: Array.from(section.querySelectorAll('details.topology-address-sources .topology-address-source')).filter(e => e.checkVisibility ? e.checkVisibility() : e.offsetHeight > 0).length,
            duplicate_addresses: (() => { const v = Array.from(section.querySelectorAll('.topology-address-head .topology-address-value')).map(e => e.textContent); return v.length - new Set(v).size; })()};
        r.counts_ok = r.counts.every(t => /^reported by \d+ ports? of \d+ hosts?$/.test(t));
        r.ok = labels.length > 0 && r.labels.every(t => t === 'System name' || t === 'Hardware ID' || t === 'Device class') && r.identity_open && r.addresses_open && !r.labels_cut.length && !r.out_of_panel.length && (r.addresses > 0 || r.empty) && r.counts_ok && r.sources_open === 0 && r.source_lines_visible === 0 && r.duplicate_addresses === 0;
        return r;
    })()''',
    # model §12.2, §12.1 (2.47, task 057): with a Device that has a duplicate candidate selected (--select), the panel names the reason in words and offers Merge,
    # Not the same device and Delete Device; the filter "possible duplicate" draws the Devices that have a candidate (also without a link) with a badge and a labelled pair line, the context dimmed
    'duplicates': '''(async () => {
        const panel = document.querySelector('.topology-panel');
        const r = {reason_text: (panel.textContent.match(/(same address|same name) \S+/) || [null])[0],
            actions: ['merge', 'dismiss', 'delete'].map(a => panel.querySelectorAll('[data-topology-dup="' + a + '"]').length)};
        const nodes = () => document.querySelectorAll('#topology-canvas .topology-node').length;
        r.nodes_before = nodes();
        const loads_before = view.loads_done;
        // the switches are part of the filter (task 090, model §12.1): the checkbox and Apply
        document.getElementById('topology-filter-duplicates').click();
        document.getElementById('topology-filter-apply').click();
        // wait for the load that Apply makes: it has finished and the page is idle
        await window.__topo_wait(() => view.loads_done > loads_before && view.idle, 'the load after Apply (duplicates filter)');
        // V-17: the server answers with the Devices that have a candidate, their partners and one hop of context (dimmed), and each pair has a labelled line
        r.nodes_filtered = nodes();
        r.duplicate_nodes = document.querySelectorAll('#topology-canvas .topology-node:not(.topology-context)').length;
        r.context_nodes = document.querySelectorAll('#topology-canvas .topology-context').length;
        r.badges_filtered = document.querySelectorAll('#topology-canvas .topology-duplicate-badge').length;
        r.pair_lines = document.querySelectorAll('#topology-canvas .topology-pair-line').length;
        r.pair_labels = Array.from(document.querySelectorAll('#topology-canvas .topology-pair-line text')).map(t => t.textContent);
        r.ok = !!r.reason_text && r.actions[0] > 0 && r.actions[1] > 0 && r.actions[2] === 0 &&      // Delete Device is in the menu of the label (task 117 section 3), not in the panel
             r.duplicate_nodes > 0 && r.badges_filtered === r.duplicate_nodes
            && r.pair_lines > 0 && r.pair_labels.every(t => /^(same address|same name|same HW-ID|same device ID|possible hardware replacement)/.test(t));
        return r;
    })()''',
    # model §12.1 / FR-C V-1 (task 090): the switch "Show devices without links" is part of the filter: the MikroTik Devices of SW18 (no link) are drawn only with the switch on
    'unlinked': '''(async () => {
        // the Devices drawn with no line at all (a Device in a Junction projection has a line to it, §12.6: it is not "without a link"); the R1 end state has the ghost of the replaced NA device
        const drawn = () => view.drawn_nodes.filter(n => n.type === 'device' && !view.drawn_links.some(l => l.source === n || l.target === n)).length;
        // Apply makes a load; wait until it has finished and the page is idle
        const settle = async loads => { await window.__topo_wait(() => view.loads_done > loads && view.idle, 'the load after Apply (switch Show devices without links)'); };
        const sw = document.getElementById('topology-filter-unlinked');
        const r = {switch_default: sw.checked, off_at_start: drawn()};
        sw.checked = true;
        let loads = view.loads_done;
        document.getElementById('topology-filter-apply').click();
        await settle(loads);
        r.on = drawn();
        sw.checked = false;
        loads = view.loads_done;
        document.getElementById('topology-filter-apply').click();
        await settle(loads);
        r.off_again = drawn();
        r.ok = r.switch_default === false && r.off_at_start === 0 && r.on > 0 && r.off_again === 0;
        return r;
    })()''',
    # model §12.1 "Node placement" and §12.2 "Label placement" (task 090): no node box (the shape with its label) overlaps another; a label is one line of at most 160 px; the shape has no text
    'overlap': '''(() => {
        const boxes = Array.from(document.querySelectorAll('#topology-canvas g.topology-node')).map(g => {
            const b = g.getBoundingClientRect();
            return {name: (g.querySelector('text.topology-label') || {textContent: '?'}).textContent, left: b.left, right: b.right, top: b.top, bottom: b.bottom};
        });
        const pairs = [];
        for (let i = 0; i < boxes.length; i++) {
            for (let j = i + 1; j < boxes.length; j++) {
                const a = boxes[i], c = boxes[j];
                if (a.left < c.right - 0.5 && c.left < a.right - 0.5 && a.top < c.bottom - 0.5 && c.top < a.bottom - 0.5) {
                    pairs.push([a.name, c.name]);
                }
            }
        }
        const svg = document.querySelector('#topology-canvas');
        const view_box = svg.viewBox.baseVal;
        const scale = svg.getBoundingClientRect().width / (view_box.width || 1);
        const labels = Array.from(document.querySelectorAll('#topology-canvas text.topology-label'));
        const r = {nodes: boxes.length, overlapping_pairs: pairs.slice(0, 6), overlaps: pairs.length,
            labels_wider_than_160: labels.filter(t => t.getBBox().width > 160.5).length, labels_multiline: labels.filter(t => t.childElementCount > 0).length,
            text_in_shape: document.querySelectorAll('#topology-canvas .topology-shape text, #topology-canvas rect + text:not(.topology-label)').length,
            scale: Math.round(scale * 100) / 100,
            // the first drawing is at scale 1 or more (§12.1 "Scale"): the label font on the screen is at least 12 px (large) and 10 px (small); the node that the view is centred on is in the window
            font_large_min: Math.min(...labels.filter(t => t.parentNode.classList.contains('topology-size-large')).map(t => parseFloat(getComputedStyle(t).fontSize) * t.getScreenCTM().a)),
            font_small_min: Math.min(...labels.filter(t => t.parentNode.classList.contains('topology-size-small')).map(t => parseFloat(getComputedStyle(t).fontSize) * t.getScreenCTM().a)),
            zoom_k: view.zoom_transform ? Math.round(view.zoom_transform.k * 100) / 100 : null,
            visible_nodes: boxes.filter(b => b.right > svg.getBoundingClientRect().left && b.left < svg.getBoundingClientRect().right && b.bottom > svg.getBoundingClientRect().top && b.top < svg.getBoundingClientRect().bottom).length};
        // task 093 (model §12.3, 2.73): the label of a LAG bundle touches no node box and no other bundle label; each place is reported as the % of its line (an unplaced one is listed)
        const lag = Array.from(document.querySelectorAll('#topology-canvas text.topology-lag-label')).map(t => { const b = t.getBoundingClientRect(); return {name: t.textContent, left: b.left, right: b.right, top: b.top, bottom: b.bottom}; });
        const hit = (a, c) => a.left < c.right - 0.5 && c.left < a.right - 0.5 && a.top < c.bottom - 0.5 && c.top < a.bottom - 0.5;
        r.bundle_labels = lag.length;
        r.bundle_label_overlaps = [];
        lag.forEach((a, i) => { boxes.forEach(c => { if (hit(a, c)) r.bundle_label_overlaps.push([a.name, c.name]); }); lag.slice(i + 1).forEach(c => { if (hit(a, c)) r.bundle_label_overlaps.push([a.name, c.name]); }); });
        r.bundle_label_places = (view.lag_label_places || []).map(p => p.source.slice(0, 4) + '-' + p.target.slice(0, 4) + ' ' + p.label + ': ' + p.u + ' %' + (p.free ? '' : ' (no free point)'));
        // task 120 (model §12.3, 2.101): the warning mark of a speed mismatch touches no node box, no bundle label and no other mark
        const marks = Array.from(document.querySelectorAll('#topology-canvas g.topology-speed-mark')).map(g => { const b = g.getBoundingClientRect(); return {name: 'mark ' + g.getAttribute('data-link'), left: b.left, right: b.right, top: b.top, bottom: b.bottom}; });
        r.speed_marks = marks.length;
        r.mark_overlaps = [];
        marks.forEach((a, i) => { boxes.concat(lag).forEach(c => { if (hit(a, c)) r.mark_overlaps.push([a.name, c.name]); }); marks.slice(i + 1).forEach(c => { if (hit(a, c)) r.mark_overlaps.push([a.name, c.name]); }); });
        // task 121 (model 2.102 §12.3): a mark touches no line other than its own (the hit path of every other line has no point inside the box of the mark), and two marks are at least 6 px apart
        r.mark_line_hits = [];
        const mark_nodes = Array.from(document.querySelectorAll('#topology-canvas g.topology-speed-mark'));
        mark_nodes.forEach(g => {
            const b = g.getBoundingClientRect();
            (view.line_entries || []).forEach(entry => {
                if (!entry.link || !entry.hit || entry.link === g.__data__) { return; }
                const length = entry.hit.getTotalLength(), matrix = entry.hit.getScreenCTM();
                for (let at = 0; at <= length; at += 2) {
                    const p = entry.hit.getPointAtLength(at), t = new DOMPoint(p.x, p.y).matrixTransform(matrix);
                    if (t.x > b.left && t.x < b.right && t.y > b.top && t.y < b.bottom) { r.mark_line_hits.push([g.getAttribute('data-link'), (entry.link.source.label || entry.link.source.name) + ' - ' + (entry.link.target.label || entry.link.target.name)]); break; }
                }
            });
        });
        r.mark_gaps = [];
        marks.forEach((a, i) => marks.slice(i + 1).forEach(c => { const gx = Math.max(a.left - c.right, c.left - a.right), gy = Math.max(a.top - c.bottom, c.top - a.bottom); if (Math.max(gx, gy) < 6) r.mark_gaps.push([a.name, c.name, Math.round(Math.max(gx, gy) * 10) / 10]); }));
        r.mark_places = (view.speed_mark_places || []).filter(p => !p.free).map(p => p.source + '-' + p.target + ' (no free point)');
        r.lines_through_nodes = linesThrough();
        r.ok = r.nodes > 0 && r.overlaps === 0 && r.bundle_label_overlaps.length === 0 && r.mark_overlaps.length === 0 && r.mark_line_hits.length === 0 && r.mark_gaps.length === 0 && r.mark_places.length === 0 && r.lines_through_nodes.length === 0 && !r.labels_wider_than_160 && !r.labels_multiline && !r.text_in_shape && r.font_large_min >= 11.99 && r.font_small_min >= 9.99 && r.zoom_k >= 1 && r.visible_nodes > 0;
        if (!r.ok) {
            r.evidence = collect('overlap');
        }
        return r;
    })()''',
    # model §12.1 "Fit" (2.72, task 092): the control "Fit" puts the whole drawn graph in the window and centres it
    'fit': '''(async () => {
        const before = view.zoom_transform.k;
        document.getElementById('topology-fit').click();
        await new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve)));      // the zoom transform is in the page after its next frames
        const svg = document.getElementById('topology-canvas').getBoundingClientRect();
        const boxes = Array.from(document.querySelectorAll('#topology-canvas g.topology-node')).map(g => g.getBoundingClientRect());
        const left = Math.min(...boxes.map(b => b.left)), right = Math.max(...boxes.map(b => b.right)), top = Math.min(...boxes.map(b => b.top)), bottom = Math.max(...boxes.map(b => b.bottom));
        const r = {k_before: Math.round(before * 100) / 100, k_after: Math.round(view.zoom_transform.k * 100) / 100, nodes: boxes.length,
            inside: left >= svg.left - 1 && right <= svg.right + 1 && top >= svg.top - 1 && bottom <= svg.bottom + 1,
            centre_offset: [Math.round((left + right) / 2 - (svg.left + svg.right) / 2), Math.round((top + bottom) / 2 - (svg.top + svg.bottom) / 2)]};
        r.ok = r.inside && Math.abs(r.centre_offset[0]) <= 3 && Math.abs(r.centre_offset[1]) <= 3 && r.k_after > 0;
        return r;
    })()''',
    # model §12.8 (2.72, 2.74; tasks 092, 093): for every leaf group of the view, unfolded alone (a new layout each time): the leaves are on one or two staggered arcs around the parent, the radius is
    # at most 250 px (200 + 50), no leaf box overlaps another box, the angles of the leaves follow the order of the parent's local port; a line of a leaf through another node fails the check on the office stand (2.76), and so do an overlap of nodes or of bundle labels after the unfold (task 094 §5)
    'arc': '''(async () => {
        const groups = view.leaf_groups.map(g => ({parent: String(g.parent), devices: g.devices.map(String)}));
        const rect = n => ({left: n.x - n.box.w / 2, right: n.x + n.box.w / 2, top: n.y + n.box.top, bottom: n.y + n.box.bottom});
        const overlap = (a, b) => { const x = rect(a), y = rect(b); return x.left < y.right && y.left < x.right && x.top < y.bottom && y.top < x.bottom; };
        const rows = [];
        let evidence = null;
        for (const g of groups) {
            Array.from(view.unfolded).forEach(id => view.unfolded.delete(id));
            view.relayout = true;
            view.render();
            view.unfoldGroup(g.parent);
            const parent = view.drawn_nodes.find(n => String(n.id) === g.parent);
            const leaves = view.drawn_nodes.filter(n => g.devices.includes(String(n.id)));
            const radius = n => Math.hypot(n.x - parent.x, n.y - parent.y);
            const through = [];
            leaves.forEach(leaf => view.drawn_nodes.forEach(n => { if (n !== parent && n !== leaf && !leaves.includes(n)) { const r = rect(n);
                for (let t = 0.03; t < 0.98; t += 0.02) { const x = parent.x + (leaf.x - parent.x) * t, y = parent.y + (leaf.y - parent.y) * t; if (x > r.left && x < r.right && y > r.top && y < r.bottom) { through.push([leaf.label, n.label]); break; } } } }));
            const port = leaf => { const l = view.links.find(c => ['physical_link'].includes(c.type) && (String(c.source) === String(leaf.id) || String(c.target) === String(leaf.id))); return String(String(l.source) === String(leaf.id) ? l.target_port : l.source_port); };
            const angle = n => Math.atan2(n.y - parent.y, n.x - parent.x);
            const mean = Math.atan2(leaves.reduce((s, n) => s + Math.sin(angle(n)), 0), leaves.reduce((s, n) => s + Math.cos(angle(n)), 0));
            const rel = leaves.map(n => Math.atan2(Math.sin(angle(n) - mean), Math.cos(angle(n) - mean)));
            const by_port = leaves.map((n, i) => [port(n), rel[i]]).sort((a, b) => a[0].localeCompare(b[0], undefined, {numeric: true})).map(x => x[1]);
            // 2.76 (task 094 §5): the overlap check after each unfold: no two node boxes overlap, and no label of a LAG bundle touches a node box or another label
            const dom = Array.from(document.querySelectorAll('#topology-canvas g.topology-node')).map(e => e.getBoundingClientRect());
            const labels = Array.from(document.querySelectorAll('#topology-canvas text.topology-lag-label')).map(e => e.getBoundingClientRect());
            const hit = (a, c) => a.left < c.right - 0.5 && c.left < a.right - 0.5 && a.top < c.bottom - 0.5 && c.top < a.bottom - 0.5;
            let node_pairs = 0, label_hits = 0;
            dom.forEach((a, i) => { dom.slice(i + 1).forEach(c => { if (hit(a, c)) node_pairs++; }); labels.forEach(l => { if (hit(a, l)) label_hits++; }); });
            labels.forEach((a, i) => labels.slice(i + 1).forEach(c => { if (hit(a, c)) label_hits++; }));
            if ((node_pairs || label_hits) && !evidence) {
                evidence = collect('arc ' + parent.label);
            }
            const info = view.arc_info.get(g.parent) || {};
            rows.push({parent: parent.label.slice(0, 8), leaves: leaves.length, expected: g.devices.length, free_deg: Math.round(info.free_angle * 180 / Math.PI), rows: info.rows,
                final_deg: Math.round((Math.max(...rel) - Math.min(...rel)) * 180 / Math.PI), radius_max: Math.round(Math.max(...leaves.map(radius))), box_overlaps: leaves.some(a => view.drawn_nodes.some(b => a !== b && overlap(a, b))),
                lines_through_other_nodes: through.length, lines_through_nodes: linesThrough().length,
                // task 101 §4 (model §12.8 "Angle slots"): the lines of the arc (parent - leaf) through any node: must be 0; the other lines through nodes are counted above and reported (the solver of §12.1)
                arc_lines_through_nodes: linesThrough().filter(t => leaves.some(l => t.line.includes(l.label)) || t.line.includes(parent.label)).filter(t => leaves.some(l => t.line === parent.label + ' - ' + l.label || t.line === l.label + ' - ' + parent.label)).length, node_overlaps: node_pairs, label_overlaps: label_hits,
                ordered_by_port: by_port.every((a, i) => i === 0 || a > by_port[i - 1] - 1e-9) || by_port.every((a, i) => i === 0 || a < by_port[i - 1] + 1e-9)});
            if (evidence) {
                break;      // the page stays in the state of the failing group, so that the screenshot shows it
            }
        }
        const r = {groups: rows.length, rows};
        if (evidence) {
            r.evidence = evidence;
        }
        r.ok = rows.length > 0 && rows.every(x => x.leaves === x.expected && x.radius_max <= 250.5 && !x.box_overlaps && x.ordered_by_port && x.lines_through_other_nodes === 0 && x.arc_lines_through_nodes === 0 && x.node_overlaps === 0 && x.label_overlaps === 0);
        return r;
    })()''',
    # a cut text shows the full text in a tooltip, and a value with HTML stays text (§13): a node label and the cells of the port table
    'escape': '''(() => {
        const evil = '<img src=x onerror="window.__xss=1">SW-<b>very</b>-long-name-for-a-node';
        const node = Array.from(view.nodes.values()).find(n => n.type !== 'device') || Array.from(view.nodes.values())[0];
        node.label = evil;
        view.render();
        // task 117 section 7: the tooltip of a node is the hint box; its content is built by hintHtml(): the text stays text
        const title = document.createElement('div');
        title.innerHTML = view.hintHtml(evil);
        const div = document.createElement('div');
        // 2.75: the cells of a row of the Links section (the neighbor and the ports come from the network) and the Copy of the identity stay text
        div.innerHTML = '<table><tbody>' + view.linkEntryHtml({port_id: '1', edge_id: '9', port: '<i>p</i>', remote_port: '<b>q</b>', remote_device_id: null, connected_to: evil, status: 'up', source: 'LLDP', source_text: 'LLDP',
            load: null, stale: true, last_seen: 5, superseded_at: null, lag_id: null, lag: null, remote_lag_id: null, remote_lag: null, device_link_id: null, linked_port_id: '2'}) + '</tbody></table>';
        const cell = div.querySelector('.topology-link-name span.topology-neighbor, .topology-link-name a.topology-neighbor');
        const r = {node_title_is_text: title.textContent === evil && !title.querySelector('img, b, i'), img_in_svg: document.querySelectorAll('#topology-canvas img').length,
            script_ran: window.__xss === 1, cell_title: cell.getAttribute('title'), img_in_table: !!div.querySelector('img, b, i'),
            port_title: div.querySelector('.topology-link-ports').getAttribute('title')};
        r.ok = r.node_title_is_text && !r.img_in_svg && !r.script_ran && r.cell_title === evil && !r.img_in_table && r.port_title === '<i>p</i> ↔ <b>q</b>';
        return r;
    })()''',
}


CHECKS['overlap'] = CHECKS['overlap'].replace('(() => {', '(() => {' + COLLECT_JS, 1)
CHECKS['arc'] = CHECKS['arc'].replace('(async () => {', '(async () => {' + COLLECT_JS, 1)


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--host', help='Focus host (technical name)')
    ap.add_argument('--group', action='append', default=[], help='Host group name (repeatable)')
    ap.add_argument('--depth', default='1', help='Depth: 1-6, or empty for no limit')
    ap.add_argument('--out', required=True)
    ap.add_argument('--width', type=int, default=1400)
    ap.add_argument('--height', type=int, default=900)
    ap.add_argument('--unlinked', action='store_true', help='set the switch "Show devices without links" before the filter is applied (model §12.1, V-1)')
    ap.add_argument('--no-filter', action='store_true', help='do not set or apply the filter: the state of the first load of the page (task 112)')
    ap.add_argument('--select', help='after the filter: select the node with this label (opens its side panel)')
    ap.add_argument('--action', default='topology.view', help='the page (zabbix.php?action=...); another page than topology.view gets no filter (to compare with stock Zabbix pages)')
    ap.add_argument('--menu', choices=('open', 'collapsed'), help='the Zabbix main menu: open (full) or collapsed (compact), set in this page only (the class of'
                    ' .sidebar, as Zabbix sets it; the user profile is not written)')
    ap.add_argument('--check', choices=sorted(CHECKS), help='a named check on the real page (model §12.1 "Layout"); exit 1 when it fails')
    ap.add_argument('--eval', help='a JavaScript expression evaluated before the screenshot; its value is printed (layout checks)')
    ap.add_argument('--full', action='store_true', help='capture the whole page height (the side panel with its port table)')
    a = ap.parse_args()

    cookie = login()
    profile = tempfile.mkdtemp(prefix='topo-shot-')
    port = 9300 + os.getpid() % 500
    chrome = subprocess.Popen(['google-chrome', '--headless=new', '--no-sandbox', '--disable-gpu', '--remote-debugging-port=%d' % port,
                               '--user-data-dir=' + profile, '--window-size=%d,%d' % (a.width, a.height), 'about:blank'],
                              stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    try:
        # the browser has started when its DevTools port answers (up to 60 s on a loaded machine)
        for _ in range(600):
            try:
                targets = json.load(urllib.request.urlopen('http://127.0.0.1:%d/json' % port))
                page = next(t for t in targets if t.get('type') == 'page')
                break
            except Exception:
                time.sleep(0.1)
        else:
            sys.exit('chrome did not start within 60 s (DevTools port %d)' % port)
        ws = WebSocket(page['webSocketDebuggerUrl'])
        ws.call('Network.enable')
        ws.call('Network.setCookie', name=cookie.name, value=cookie.value, url=BASE)
        ws.call('Emulation.setDeviceMetricsOverride', width=a.width, height=a.height, deviceScaleFactor=1, mobile=False)
        ws.call('Page.enable')
        install_probe(ws)
        ws.call('Page.navigate', url=BASE + 'zabbix.php?action=' + a.action)

        def js(expr):
            r = ws.call('Runtime.evaluate', expression=expr, awaitPromise=True, returnByValue=True)
            if r.get('exceptionDetails'):
                raise RuntimeError('page script failed: %s' % r['exceptionDetails'])
            return r.get('result', {}).get('value')
        js.ws = ws

        # wait for the page: the document is complete and the menu and, on the graph page, the filter bar are there; on the graph page the first load has been made (the menu and the filter
        # are set after it, as a user does, so that two loads of the page do not run into each other) and the page is idle
        ready = "document.readyState === 'complete' && !!document.querySelector('.sidebar') && (%s)" % (
            "!!document.getElementById('topology-filter-apply')" if a.action == 'topology.view' else 'true')
        wait_state(ws, js, ready, 'the page %s is complete (document, menu, filter bar)' % a.action)
        if a.action == 'topology.view':
            wait_state(ws, js, "typeof view !== 'undefined' && view.first_load_done", 'the first load of the page is done')
            settled(ws, js, 'the first load has finished (no request, no action)')

        # the filter is set through the page's own controls (multiselects, the Depth select, "Apply filter"); the ids come from the page's API
        # the filter is set through the page's own controls (multiselects, the Depth select, "Apply filter"); the ids are read from the database (read only)
        def lookup(sql, name):
            out = subprocess.run(['mysql', '-N', '-uzabbix', 'zabbix', '-e', sql], capture_output=True, text=True).stdout.split('\n')[0].split('\t')
            if len(out) < 2:
                sys.exit('not found: %s' % name)
            return {'id': out[0], 'name': out[1]}
        host = lookup("SELECT hostid,name FROM hosts WHERE host='%s'" % a.host.replace("'", ''), a.host) if a.host else None
        groups = [lookup("SELECT groupid,name FROM hstgrp WHERE name='%s'" % g.replace("'", ''), g) for g in a.group]
        filter_js = '''(() => {
            const host = %s, groups = %s;
            if (host) jQuery('#hostid').multiSelect('addData', [host]);
            if (groups.length) jQuery('#groupids_').multiSelect('addData', groups);
            document.getElementById('topology-filter-hops').value = %s;
            document.getElementById('topology-filter-unlinked').checked = %s;
            document.getElementById('topology-filter-apply').click();
            return [jQuery('#hostid').multiSelect('getData'), jQuery('#groupids_').multiSelect('getData'), document.getElementById('topology-filter-hops').value];
        })()''' % (json.dumps(host), json.dumps(groups), json.dumps(a.depth), 'true' if a.unlinked else 'false')
        if a.menu:
            js('''(() => { const s = document.querySelector('.sidebar'); s.classList.toggle('is-compact', %s); s.classList.remove('is-hidden');
                window.dispatchEvent(new Event('resize')); })()''' % ('true' if a.menu == 'collapsed' else 'false'))
            next_frames(js)      # the resize is handled in the page's next frames
        if a.action == 'topology.view' and not a.no_filter:
            loads = js('view.loads_done')
            print('filter:', json.dumps(js(filter_js)))
            # Apply makes a load; wait until it has finished and the page is idle (a.settle is no longer used: the layout is one synchronous pass of render())
            wait_state(ws, js, 'view.loads_done > %d' % loads, 'Apply filter made its load (loads_done > %d)' % loads)
            settled(ws, js, 'the load after Apply filter has finished')
        if a.select:
            picked = js('''(async () => {
                const node = Array.from(view.nodes.values()).find(n => n.label === %s && n.type !== 'device') || Array.from(view.nodes.values()).find(n => n.label === %s);
                if (!node) throw new Error('no node ' + %s);
                await view.selectNode(node);
                return node.type + ' ' + node.label;
            })()''' % ((json.dumps(a.select),) * 3))
            print('selected:', picked)
            settled(ws, js, 'the panel of the selected node has been read (no request, no action)')
        if a.eval:
            print('eval:', json.dumps(js(a.eval)))
        failed = False
        if a.check:
            result = js(CHECKS[a.check])
            failed = not result.get('ok')
            evidence = result.pop('evidence', None)
            print('check %s: %s %s' % (a.check, 'ok' if not failed else 'FAILED', json.dumps(result)))
            if failed and evidence:
                # task 098 §2: one folder per failure: evidence.json (overlaps with px, boxes, label places, node positions) and the screenshot of the page in that state
                folder = os.path.join(os.environ.get('TOPO_EVIDENCE_DIR', '/tmp/topo_evidence'), '%s-%d-%s' % (time.strftime('%Y%m%d-%H%M%S'), os.getpid(), a.check))
                os.makedirs(folder, exist_ok=True)
                with open(os.path.join(folder, 'evidence.json'), 'w') as f:
                    json.dump(evidence, f, indent=1)
                with open(os.path.join(folder, 'screenshot.png'), 'wb') as f:
                    f.write(base64.b64decode(ws.call('Page.captureScreenshot', format='png')['data']))
                for t in evidence.get('lines_through_nodes', []):
                    print('line through a node: "%s" passes through "%s" (%s px of the line inside the box)' % (t['line'], t['node'], t['px']))
                for o in evidence['overlaps']:
                    print('overlap: %s "%s" and "%s": %s px wide, %s px high' % (o['kind'], o['a'], o['b'], o['dx'], o['dy']))
                for p in evidence['label_places']:
                    if not p['free']:
                        print('label without a free point: %s-%s %s at %s %%' % (p['source'], p['target'], p['label'], p['u']))
                print('evidence: %s' % folder)
        nodes = js('''Array.from(document.querySelectorAll('#topology-canvas g.node, #topology-canvas g')).length''')
        print('svg groups:', nodes)
        if a.full:
            # let the side panel grow with its content, then capture the whole page
            js('''(() => { for (const el of document.querySelectorAll('.topology-panel, .topology-workspace, .topology-prototype')) { el.style.maxHeight = 'none'; el.style.height = 'auto'; el.style.overflow = 'visible'; } })()''')
            height = js('Math.max(document.body.scrollHeight, document.documentElement.scrollHeight)')
            ws.call('Emulation.setDeviceMetricsOverride', width=a.width, height=int(height), deviceScaleFactor=1, mobile=False)
            next_frames(js, 3)      # the new size is in the page after its next frames
        shot = ws.call('Page.captureScreenshot', format='png')
        with open(a.out, 'wb') as f:
            f.write(base64.b64decode(shot['data']))
        print('screenshot:', a.out)
        if failed:
            sys.exit(1)
    finally:
        chrome.terminate()
        chrome.wait(timeout=10)
        shutil.rmtree(profile, ignore_errors=True)


if __name__ == '__main__':
    main()
