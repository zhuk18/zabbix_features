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
            // task 090 (model §12.1): the filter bar is one row (every field and button has the same vertical centre) and the page has no "Links" select and no "Clean all" button
            filter_one_row: (() => { const c = Array.from(document.querySelectorAll('.topology-filter-bar > *')).map(e => { const b = e.getBoundingClientRect(); return b.top + b.height / 2; }); return c.length > 5 && Math.max(...c) - Math.min(...c) < 6; })(),
            removed_controls: ['topology-link-filter', 'topology-clean-all'].filter(id => document.getElementById(id)),
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
            && r.filter_one_row && !r.removed_controls.length && !r.header_buttons_out.length && !r.panel_buttons_out && !r.panel_headers_out && !r.headers_cut.length && r.panel_tables > 0
            && r.workspace_bottom <= H && r.workspace_bottom >= Math.min(H, r.below) - 40 && r.canvas_h === r.panel_h;
        return r;
    })()''',
    # model §12.7 (task 076): the last run view (--action topology.lastrun): the run summary, the list "Needs attention", the filter and the observation list are there;
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
        const labels = Array.from(panel.querySelectorAll('.topology-identity .topology-address-identity th'));  // 2.75: the identity is in the section Identity, Addresses is inside it
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
        // the switches are part of the filter (task 090, model §12.1): the checkbox and Apply
        document.getElementById('topology-filter-duplicates').click();
        document.getElementById('topology-filter-apply').click();
        // wait for the server's answer (the number of nodes changes), at most 30 s: a fixed pause of 3 s was too short on a busy VM
        for (let i = 0; i < 60 && nodes() === r.nodes_before; i++) {
            await new Promise(resolve => setTimeout(resolve, 500));
        }
        await new Promise(resolve => setTimeout(resolve, 1000));
        // V-17: the server answers with the Devices that have a candidate, their partners and one hop of context (dimmed), and each pair has a labelled line
        r.nodes_filtered = nodes();
        r.duplicate_nodes = document.querySelectorAll('#topology-canvas .topology-node:not(.topology-context)').length;
        r.context_nodes = document.querySelectorAll('#topology-canvas .topology-context').length;
        r.badges_filtered = document.querySelectorAll('#topology-canvas .topology-duplicate-badge').length;
        r.pair_lines = document.querySelectorAll('#topology-canvas .topology-pair-line').length;
        r.pair_labels = Array.from(document.querySelectorAll('#topology-canvas .topology-pair-line text')).map(t => t.textContent);
        r.ok = !!r.reason_text && r.actions.every(n => n > 0) && r.duplicate_nodes > 0 && r.badges_filtered === r.duplicate_nodes
            && r.pair_lines > 0 && r.pair_labels.every(t => /^(same address|same name|same HW-ID|same device ID|possible hardware replacement)/.test(t));
        return r;
    })()''',
    # model §12.1 / FR-C V-1 (task 090): the switch "Show devices without links" is part of the filter: the MikroTik Devices of SW18 (no link) are drawn only with the switch on
    'unlinked': '''(async () => {
        const drawn = () => Array.from(document.querySelectorAll('#topology-canvas g.topology-node text.topology-label')).filter(t => t.textContent.startsWith('MikroTik')).length;
        const settle = async before => { for (let i = 0; i < 40 && drawn() === before; i++) { await new Promise(resolve => setTimeout(resolve, 500)); } await new Promise(resolve => setTimeout(resolve, 800)); };
        const sw = document.getElementById('topology-filter-unlinked');
        const r = {switch_default: sw.checked, off_at_start: drawn()};
        sw.checked = true;
        document.getElementById('topology-filter-apply').click();
        await settle(r.off_at_start);
        r.on = drawn();
        sw.checked = false;
        document.getElementById('topology-filter-apply').click();
        await settle(r.on);
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
        r.ok = r.nodes > 0 && r.overlaps === 0 && r.bundle_label_overlaps.length === 0 && !r.labels_wider_than_160 && !r.labels_multiline && !r.text_in_shape && r.font_large_min >= 11.99 && r.font_small_min >= 9.99 && r.zoom_k >= 1 && r.visible_nodes > 0;
        return r;
    })()''',
    # model §12.1 "Fit" (2.72, task 092): the control "Fit" puts the whole drawn graph in the window and centres it
    'fit': '''(async () => {
        const before = view.zoom_transform.k;
        document.getElementById('topology-fit').click();
        await new Promise(resolve => setTimeout(resolve, 500));
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
            const info = view.arc_info.get(g.parent) || {};
            rows.push({parent: parent.label.slice(0, 8), leaves: leaves.length, expected: g.devices.length, free_deg: Math.round(info.free_angle * 180 / Math.PI), rows: info.rows,
                final_deg: Math.round((Math.max(...rel) - Math.min(...rel)) * 180 / Math.PI), radius_max: Math.round(Math.max(...leaves.map(radius))), box_overlaps: leaves.some(a => view.drawn_nodes.some(b => a !== b && overlap(a, b))),
                lines_through_other_nodes: through.length, node_overlaps: node_pairs, label_overlaps: label_hits,
                ordered_by_port: by_port.every((a, i) => i === 0 || a > by_port[i - 1] - 1e-9) || by_port.every((a, i) => i === 0 || a < by_port[i - 1] + 1e-9)});
        }
        const r = {groups: rows.length, rows};
        r.ok = rows.length > 0 && rows.every(x => x.leaves === x.expected && x.radius_max <= 250.5 && !x.box_overlaps && x.ordered_by_port && x.lines_through_other_nodes === 0 && x.node_overlaps === 0 && x.label_overlaps === 0);
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
        // 2.75: the cells of a row of the Links section (the neighbor and the ports come from the network) and the Copy of the identity stay text
        div.innerHTML = '<table><tbody>' + view.linkEntryHtml({port_id: '1', edge_id: '9', port: '<i>p</i>', remote_port: '<b>q</b>', remote_device_id: null, connected_to: evil, status: 'up', source: 'LLDP', source_text: 'LLDP',
            load: null, stale: true, last_seen: 5, superseded_at: null, lag_id: null, lag: null, remote_lag_id: null, remote_lag: null, device_link_id: null, linked_port_id: '2'}) + '</tbody></table>';
        const cell = div.querySelector('td.topology-link-neighbor span, td.topology-link-neighbor a');
        const r = {node_title_is_text: !!title && title.childElementCount === 0, img_in_svg: document.querySelectorAll('#topology-canvas img').length,
            script_ran: window.__xss === 1, cell_title: cell.getAttribute('title'), img_in_table: !!div.querySelector('img, b, i'),
            port_title: div.querySelector('td.topology-link-ports').getAttribute('title')};
        r.ok = r.node_title_is_text && !r.img_in_svg && !r.script_ran && r.cell_title === evil && !r.img_in_table && r.port_title === '<i>p</i> ↔ <b>q</b>';
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
    ap.add_argument('--unlinked', action='store_true', help='set the switch "Show devices without links" before the filter is applied (model §12.1, V-1)')
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

        def js(expr):
            r = ws.call('Runtime.evaluate', expression=expr, awaitPromise=True, returnByValue=True)
            if r.get('exceptionDetails'):
                raise RuntimeError('page script failed: %s' % r['exceptionDetails'])
            return r.get('result', {}).get('value')

        # wait for the page (the first request after a change of the code can take longer than a fixed sleep): the document is complete and the menu and, on the graph page, the
        # filter bar are there
        ready = "document.readyState === 'complete' && !!document.querySelector('.sidebar') && (%s)" % (
            "!!document.getElementById('topology-filter-apply')" if a.action == 'topology.view' else 'true')
        for _ in range(120):
            try:
                if js(ready):
                    break
            except RuntimeError:
                pass
            time.sleep(0.5)
        time.sleep(1)

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
