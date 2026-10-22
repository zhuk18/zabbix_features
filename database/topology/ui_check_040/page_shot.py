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


# Checks of model §12.1 "Layout" (task 042), run in the real page after the filter (and --select)
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
            header_buttons_out: Array.from(document.querySelectorAll('.topology-header button, .topology-filter-bar button')).filter(e => { const b = e.getBoundingClientRect(); return b.width > 0 && (b.right > W || b.left < 0); }).map(name),
            panel_buttons_out: Array.from(panel.querySelectorAll('table button')).filter(e => { const b = e.getBoundingClientRect(); return b.width > 0 && b.right > panel_right + 0.5; }).length,
            panel_headers_out: Array.from(panel.querySelectorAll('table th')).filter(e => { const b = e.getBoundingClientRect(); return b.width > 0 && b.right > panel_right + 0.5; }).length,
            headers_cut: Array.from(new Set(Array.from(panel.querySelectorAll('table th')).filter(e => e.scrollWidth > e.clientWidth + 1).map(e => e.textContent.trim()))),
            panel_tables: panel.querySelectorAll('table').length,
            workspace_bottom: Math.round(ws.bottom), below: Math.round((document.querySelector('footer') || {getBoundingClientRect: () => ({top: H})}).getBoundingClientRect().top), canvas_h: Math.round(canvas.height), panel_h: panel.clientHeight, panel_content_h: panel.scrollHeight};
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
            r.header_buttons_out = Array.from(document.querySelectorAll('.topology-header button, .topology-filter-bar button')).filter(e => { const b = e.getBoundingClientRect(); return b.width > 0 && b.right > page_right; }).map(name);
        }
        r.ok = (d.scrollWidth <= W || r.zabbix_min_width_scroll) && d.scrollHeight <= H && !r.container_overflow_x.length && !r.container_overflow_y.length && !r.outside_window.length
            && !r.header_buttons_out.length && !r.panel_buttons_out && !r.panel_headers_out && !r.headers_cut.length && r.panel_tables > 0
            && r.workspace_bottom <= H && r.workspace_bottom >= Math.min(H, r.below) - 40 && r.canvas_h === r.panel_h;
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
        const labels = Array.from(section.querySelectorAll('.topology-address-identity th'));
        const r = {window: document.documentElement.clientWidth + 'x' + document.documentElement.clientHeight,
            labels: labels.map(t => t.textContent),
            labels_cut: labels.filter(t => t.scrollWidth > t.clientWidth + 1).map(t => t.textContent),
            out_of_panel: Array.from(section.querySelectorAll('*')).filter(e => { const b = e.getBoundingClientRect(); return b.width > 0 && b.right > panel_right + 0.5; }).map(e => e.className || e.tagName).slice(0, 5),
            addresses: section.querySelectorAll('.topology-address').length, empty: !!section.querySelector('.topology-empty')};
        r.ok = labels.length > 0 && r.labels.every(t => t === 'System name' || t === 'Hardware ID') && !r.labels_cut.length && !r.out_of_panel.length && (r.addresses > 0 || r.empty);
        return r;
    })()''',
    # a cut text shows the full text in a tooltip, and a value with HTML stays text (§13): a node label and the cells of the port table
    'escape': '''(() => {
        const evil = '<img src=x onerror="window.__xss=1">SW-<b>very</b>-long-name-for-a-node';
        const node = Array.from(view.nodes.values()).find(n => n.type !== 'device') || Array.from(view.nodes.values())[0];
        node.label = evil;
        view.render();
        const title = Array.from(document.querySelectorAll('#topology-canvas title.topology-label-title')).find(t => t.textContent === evil);
        const div = document.createElement('div');
        div.innerHTML = view.buildPortsFragment({id: '0', represented: true}, {connected: [{id: '1', port: '<i>p</i>', group: 'connected', status: 'up',
            connected_to: evil, linked_port_id: '2', source: 'LLDP'}]});
        const cell = div.querySelector('td.topology-port-name').nextElementSibling.nextElementSibling;
        const r = {node_title_is_text: !!title && title.childElementCount === 0, img_in_svg: document.querySelectorAll('#topology-canvas img').length,
            script_ran: window.__xss === 1, cell_title: cell.getAttribute('title'), img_in_table: !!div.querySelector('img, b, i'),
            port_title: div.querySelector('td.topology-port-name').getAttribute('title')};
        r.ok = r.node_title_is_text && !r.img_in_svg && !r.script_ran && r.cell_title === evil && !r.img_in_table && r.port_title === '<i>p</i>';
        return r;
    })()''',
}


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--host', help='Focus host (technical name)')
    ap.add_argument('--group', action='append', default=[], help='Host group name (repeatable)')
    ap.add_argument('--depth', default='1', help='Depth: 1-6, or empty for no limit')
    ap.add_argument('--out', required=True)
    ap.add_argument('--width', type=int, default=1400)
    ap.add_argument('--height', type=int, default=900)
    ap.add_argument('--settle', type=float, default=8.0, help='seconds for the force layout to settle')
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
        for _ in range(100):
            try:
                targets = json.load(urllib.request.urlopen('http://127.0.0.1:%d/json' % port))
                page = next(t for t in targets if t.get('type') == 'page')
                break
            except Exception:
                time.sleep(0.1)
        else:
            sys.exit('chrome did not start')
        ws = WebSocket(page['webSocketDebuggerUrl'])
        ws.call('Network.enable')
        ws.call('Network.setCookie', name=cookie.name, value=cookie.value, url=BASE)
        ws.call('Emulation.setDeviceMetricsOverride', width=a.width, height=a.height, deviceScaleFactor=1, mobile=False)
        ws.call('Page.enable')
        ws.call('Page.navigate', url=BASE + 'zabbix.php?action=' + a.action)
        time.sleep(4)

        def js(expr):
            r = ws.call('Runtime.evaluate', expression=expr, awaitPromise=True, returnByValue=True)
            if r.get('exceptionDetails'):
                raise RuntimeError('page script failed: %s' % r['exceptionDetails'])
            return r.get('result', {}).get('value')

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
            document.getElementById('topology-filter-apply').click();
            return [jQuery('#hostid').multiSelect('getData'), jQuery('#groupids_').multiSelect('getData'), document.getElementById('topology-filter-hops').value];
        })()''' % (json.dumps(host), json.dumps(groups), json.dumps(a.depth))
        if a.menu:
            js('''(() => { const s = document.querySelector('.sidebar'); s.classList.toggle('is-compact', %s); s.classList.remove('is-hidden');
                window.dispatchEvent(new Event('resize')); })()''' % ('true' if a.menu == 'collapsed' else 'false'))
            time.sleep(1)
        if a.action == 'topology.view':
            print('filter:', json.dumps(js(filter_js)))
        time.sleep(a.settle)
        if a.select:
            picked = js('''(async () => {
                const node = Array.from(view.nodes.values()).find(n => n.label === %s && n.type !== 'device') || Array.from(view.nodes.values()).find(n => n.label === %s);
                if (!node) throw new Error('no node ' + %s);
                await view.selectNode(node);
                return node.type + ' ' + node.label;
            })()''' % ((json.dumps(a.select),) * 3))
            print('selected:', picked)
            time.sleep(3)
        if a.eval:
            print('eval:', json.dumps(js(a.eval)))
        failed = False
        if a.check:
            result = js(CHECKS[a.check])
            failed = not result.get('ok')
            print('check %s: %s %s' % (a.check, 'ok' if not failed else 'FAILED', json.dumps(result)))
        nodes = js('''Array.from(document.querySelectorAll('#topology-canvas g.node, #topology-canvas g')).length''')
        print('svg groups:', nodes)
        if a.full:
            # let the side panel grow with its content, then capture the whole page
            js('''(() => { for (const el of document.querySelectorAll('.topology-panel, .topology-workspace, .topology-prototype')) { el.style.maxHeight = 'none'; el.style.height = 'auto'; el.style.overflow = 'visible'; } })()''')
            height = js('Math.max(document.body.scrollHeight, document.documentElement.scrollHeight)')
            ws.call('Emulation.setDeviceMetricsOverride', width=a.width, height=int(height), deviceScaleFactor=1, mobile=False)
            time.sleep(1)
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
