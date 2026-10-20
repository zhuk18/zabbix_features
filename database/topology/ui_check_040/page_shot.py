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
        ws.call('Page.navigate', url=BASE + 'zabbix.php?action=topology.view')
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
    finally:
        chrome.terminate()
        chrome.wait(timeout=10)
        shutil.rmtree(profile, ignore_errors=True)


if __name__ == '__main__':
    main()
