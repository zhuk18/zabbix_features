"""task 107 §3 (model 2.91 §9.7 "Bind to interface", §12.5): "Bind to interface" on the real page, 1500 x 900, office stand, real mouse and keyboard events (DevTools Input domain).
Create "BIND-TEST" (Switch, ports p1, p2), link p1 to a free port of SW12, Promote to a lab host that has the interface template and is not a reporter of another Device (the check takes the first host of
the default lab with two discovered interfaces and no node of its own, Router1 when `labsim provision S6` ran; it fails with a message when none fits), bind p1 to an interface (the Links row shows
"p1 -> Gi0/0" and the load of that interface: the check writes a last value for the items of the interface and removes it at the end), bind p2 to the same interface (the dialog shows it taken by p1,
the server refuses), Unbind p1, Depromote, delete the Device: nothing is left (no manual Device, no port, no link, no history row of the check). Screenshots when SHOTS_DIR is set: 107-bind-dialog.png,
107-bound-port.png. Needs ZBX_UI_USER, ZBX_UI_PASSWORD, google-chrome, the office stand data and the database login of the harness (`mysql -uzabbix zabbix`). Exit code 1 when a check fails.
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


def db(statement):
    return subprocess.run(['mysql', '-N', '-uzabbix', 'zabbix', '-e', statement], capture_output=True, text=True).stdout.strip()


def session():
    chrome, ws, js = P.open_page(cookie, 'panel', 9700)
    return chrome, ws, js


def mouse(ws, x, y):
    ws.call('Input.dispatchMouseEvent', type='mouseMoved', x=x, y=y, button='none', buttons=0)


def select(js, label, kind=None):
    js("""(async () => { const n = Array.from(view.nodes.values()).find(n => n.label === %s && (%s)); await view.selectNode(n); return 1; })()""" % (json.dumps(label), ("n.type === '%s'" % kind) if kind else 'true'))
    P.after_input(js)





def left_over():
    # task 109: only the Device of this check (BIND-TEST) and its ports; a manual Device that the operator made by hand is not counted
    return db("select count(*) from topo_nodes where (type = 'device' and json_unquote(json_extract(attrs, '$.source')) = 'manual' and json_unquote(json_extract(attrs, '$.sysname')) = 'BIND-TEST') or (type = 'port' and device_id in (select id from (select id from topo_nodes where type = 'device' and json_unquote(json_extract(attrs, '$.sysname')) = 'BIND-TEST') d))")


chrome, ws, js = session()


def wait(expr, seconds=None):
    """Wait (up to 60 s) until the expression is true on the page; its value is returned. On a timeout the message (condition, time, page state, console, screenshot) is printed and the
    value is '' so that the check that asked fails with it (task 116)."""
    try:
        return P.wait_state(ws, js, expr, 'the page reaches: ' + expr[:120], return_value=True)
    except P.WaitTimeout as error:
        print(error)
        return ''


def where(expr):
    return js("""(() => { const el = %s; if (!el) return null; el.scrollIntoView({block: 'center'}); const b = el.getBoundingClientRect(); return {x: b.left + b.width / 2, y: b.top + b.height / 2}; })()""" % expr)


def click(expr):
    p = where(expr)
    assert p is not None, 'nothing to click: ' + expr
    for kind in ('mouseMoved', 'mousePressed', 'mouseReleased'):
        ws.call('Input.dispatchMouseEvent', type=kind, x=p['x'], y=p['y'], button='left' if kind != 'mouseMoved' else 'none', buttons=1 if kind == 'mousePressed' else 0, clickCount=1)
    P.after_input(js)


def type_into(selector, text):
    click("document.querySelector(%s)" % json.dumps(selector))
    js("document.querySelector(%s).select()" % json.dumps(selector))
    ws.call('Input.insertText', text=text)
    P.after_input(js)


def dialog_button(title):
    return "Array.from(document.querySelectorAll('.overlay-dialogue-footer button')).find(b => b.textContent.trim() === %s)" % json.dumps(title)


def shot(name):
    folder = os.environ.get('SHOTS_DIR')
    if folder:
        import base64
        with open(os.path.join(folder, name), 'wb') as f:
            f.write(base64.b64decode(ws.call('Page.captureScreenshot', format='png')['data']))



TITLE = "(() => { const t = document.querySelector('#topology-details .topology-node-title, #topology-details .topology-host-name'); return t ? t.textContent.trim() : null; })()"


def pick_host():
    # the first host of the default lab with two discovered interfaces, no Device of its own (a Host node is allowed: the view makes it when it reads the scope, task 111) and no snapshot (so it is not a reporter of another Device)
    rows = db("select h.hostid, h.host from hosts h where h.status = 0 and h.host in ('Router1', 'Switch1', 'Switch2', 'AccessSwitch1', 'UPS1') "
              "and (select count(*) from items i join item_discovery d on d.itemid = i.itemid where i.hostid = h.hostid and i.key_ like 'net.if.in[%') >= 2 "
              "and not exists (select 1 from topo_nodes n where n.type = 'device' and n.host_ref = h.hostid) and not exists (select 1 from topo_lld_snapshot s where s.hostid = h.hostid) order by h.hostid")
    return rows.split('\n')[0].split('\t') if rows else None


def interface_names(hostid):
    # the names (tag `interface`) of the interface items of the host, by index: the first two
    rows = db("select cast(substring_index(substring_index(i.key_, '.', -1), ']', 1) as unsigned) as idx, t.value from items i join item_tag t on t.itemid = i.itemid and t.tag = 'interface' "
              "where i.hostid = %s and i.flags = 4 and i.key_ like 'net.if.in[%%' order by idx" % hostid)
    return [line.split('\t')[1] for line in rows.split('\n') if line][:2]


def interface_items(hostid, index):
    out = {}
    for line in db("select i.itemid, i.key_, i.value_type from items i where i.hostid = %s and i.flags = 4 and i.key_ in ('net.if.in[ifInOctets.%d]', 'net.if.out[ifOutOctets.%d]', 'net.if.speed[ifSpeed.%d]')" % (hostid, index, index, index)).split('\n'):
        if line:
            itemid, key, vt = line.split('\t')
            out[key.split('[')[0]] = (itemid, vt)
    return out


HISTORY = []


def history_rows(hostid):
    return [db("select count(*) from %s where itemid in (select itemid from items where hostid = %s and key_ like 'net.if.%%')" % (table, hostid)) for table in ('history', 'history_uint')]


def write_history(hostid, index, rate_in, rate_out, speed):
    now = int(time.time())
    for name, value in (('net.if.in', rate_in), ('net.if.out', rate_out), ('net.if.speed', speed)):
        itemid, vt = interface_items(hostid, index)[name]
        table = 'history_uint' if vt == '3' else 'history'
        db("insert into %s (itemid, clock, value, ns) values (%s, %d, %s, 0)" % (table, itemid, now, value))
        HISTORY.append((table, itemid, now))


def row_menu(expr_row):
    click("(%s).querySelector('.topology-menu-button')" % expr_row)


LINKS_ROW = "(port => Array.from(document.querySelectorAll('#topology-details tr.topology-link-row')).find(r => r.querySelector('.topology-link-ports') && r.querySelector('.topology-link-ports').textContent.trim().startsWith(port)))"
PORTS_ROW = "(port => Array.from(document.querySelectorAll('#topology-details tr')).find(r => r.querySelector('.topology-port-name') && r.querySelector('.topology-port-name').textContent.trim().startsWith(port)))"


host = pick_host()
try:
    P.after_input(js)
    js("window.confirm = () => true; window.alert = text => { window.__alert = text; }")
    # task 112: the names of the first two interfaces come from the host (the hosts of the lab have different names: Router1 Gi0/0 Gi0/1, AccessSwitch1 Gi0/1 Gi0/2), not from the text of the check
    NAMES = interface_names(host[0]) if host else ['', '']
    check(host is not None, 'a lab host with the interface template, two discovered interfaces and no Device of its own exists (run `labsim provision S6` when not)', host)
    if host is None:
        raise SystemExit(1)
    hostid, hostname = host
    HISTORY_BEFORE = history_rows(hostid)
    print('=== host used: %s (hostid %s) ===' % (hostname, hostid))
    check(left_over() == '0', 'no manual Device or port in the database at the start')
    print('=== Create BIND-TEST (Switch, p1, p2) ===')
    click("document.getElementById('topology-create-device')")
    wait("!!document.getElementById('topology-device-name')")
    type_into('#topology-device-name', 'BIND-TEST')
    js("(() => { const s = document.getElementById('topology-device-class'); s.value = 'switch'; s.dispatchEvent(new Event('change', {bubbles: true})); })()")
    for i, name in ((1, 'p1'), (2, 'p2')):
        click("document.querySelector('.topology-port-add')")
        type_into('.topology-port-row:nth-child(%d) .topology-port-name-input' % i, name)
    click(dialog_button('Create'))
    check(wait("!document.getElementById('topology-device-name')") and wait("(%s) === 'BIND-TEST'" % TITLE), 'the Device BIND-TEST is created and selected')
    menu = js("Array.from(document.querySelectorAll('#topology-details .topology-menu-item')).map(e => e.textContent.trim())")
    check('Bind to interface' not in menu, 'a Device without an association has no "Bind to interface" (no port menu item)', menu)

    print('=== link p1 to a free port of SW12 ===')
    js("Array.from(document.querySelectorAll('#topology-details details.topology-port-group')).forEach(d => { d.open = true; })")
    click("(%s)('p1').querySelector('.topology-link-button')" % PORTS_ROW)
    wait("document.getElementById('topology-link-pick').textContent.includes('p1')")
    click("(() => { const g = Array.from(document.querySelectorAll('#topology-canvas g.topology-node')).find(e => String(e.__data__.label).startsWith('SW12')); return g.querySelector('rect.topology-shape'); })()")
    wait("(%s) && (%s).startsWith('SW12')" % (TITLE, TITLE))
    js("Array.from(document.querySelectorAll('#topology-details details.topology-port-group[data-group=\"no_neighbor\"]')).forEach(d => { d.open = true; })")
    click("(() => { const row = Array.from(document.querySelectorAll('#topology-details details[data-group=\"no_neighbor\"] tbody tr'))[0]; return row.querySelector('.topology-link-button'); })()")
    P.after_input(js)
    check(db("select count(*) from topo_edges e join topo_nodes a on a.id = e.src_id join topo_nodes b on b.id = e.dst_id where e.type = 'physical_link' and (json_unquote(json_extract(a.attrs, '$.name')) = 'p1' or json_unquote(json_extract(b.attrs, '$.name')) = 'p1')") == '1', 'p1 has a manual link to a port of SW12')

    print('=== Promote to %s ===' % hostname)
    click("(() => { const g = Array.from(document.querySelectorAll('#topology-canvas g.topology-node')).find(e => String(e.__data__.label) === 'BIND-TEST'); return g.querySelector('rect.topology-shape'); })()")
    wait("(%s) === 'BIND-TEST'" % TITLE)
    click("document.querySelector('#topology-details .topology-node-actions .topology-menu-button')")
    click("document.querySelector('#topology-details .topology-menu-promote')")
    type_into('.topology-promote-search', hostname)
    wait("document.querySelector('.topology-promote-result-host')")
    click("Array.from(document.querySelectorAll('.topology-promote-result-host')).find(e => e.textContent.trim() === %s)" % json.dumps(hostname))
    click("document.querySelector('.topology-promote-button')")
    check(wait("view.nodes.get(String(view.drawn_nodes.find(n => n.label === %s).id)) !== undefined && view.drawn_nodes.every(n => n.label !== 'BIND-TEST')" % json.dumps(hostname), 25), 'Promote: the Device is drawn as the host %s' % hostname)
    js("(async () => { const n = view.drawn_nodes.find(n => n.label === %s); await view.selectNode(n); return 1; })()" % json.dumps(hostname))
    P.after_input(js)
    check(js("document.querySelectorAll('#topology-details tr.topology-link-row').length") >= 1, 'the panel of the host has the Links row of p1')
    menu = js("Array.from((%s)('p1').querySelectorAll('.topology-menu-item')).map(e => e.textContent.trim())" % LINKS_ROW)
    check('Bind to interface' in menu and 'Unbind' not in menu, 'the Links row of p1 has "Bind to interface" (and no Unbind) in its menu', menu)

    print('=== Bind p1 ===')
    write_history(hostid, 1, 8000000, 4000000, 1000000000)
    row_menu("(%s)('p1')" % LINKS_ROW)
    click("(%s)('p1').querySelector('.topology-port-bind')" % LINKS_ROW)
    check(wait("!!document.querySelector('.topology-bind-table')"), 'the dialog "Bind to interface" opens with the list of interfaces')
    names = js("Array.from(document.querySelectorAll('.topology-bind-row')).map(r => Array.from(r.querySelectorAll('td')).map(td => td.textContent.trim()).filter(Boolean).join(' '))")
    check(names == ['%s 1' % NAMES[0], '%s 2' % NAMES[1]], 'the list shows the name and the index of each interface of %s' % hostname, names)
    shot('107-bind-dialog.png')
    click("document.getElementById('topology-bind-1')")
    click(dialog_button('Bind'))
    check(wait("!document.querySelector('.topology-bind-table')"), 'Bind closes the dialog')
    at = db("select json_extract(attrs, '$.if_index'), json_unquote(json_extract(attrs, '$.interface_name')), json_extract(attrs, '$.zabbix_itemids') from topo_nodes where type = 'port' and json_unquote(json_extract(attrs, '$.name')) = 'p1'")
    check(at.split('\t')[0] == '1' and at.split('\t')[1] == NAMES[0] and at.split('\t')[2] == 'NULL', 'p1 has if_index 1 and the interface name; the items are not stored (found when the graph is read)', at)
    wait("(%s)('p1') && (%s)('p1').querySelector('.topology-link-ports').textContent.includes('p1 → %s')" % (LINKS_ROW, LINKS_ROW, NAMES[0]), 20)
    text = js("(%s)('p1').textContent" % LINKS_ROW)
    check('p1 → %s' % NAMES[0] in text, 'the Links row shows "p1 → %s"' % NAMES[0], text)
    check('in 0.8 %' in text and 'out 0.4 %' in text, 'the Links row shows the load of the interface (8 Mbit/s in and 4 Mbit/s out of 1 Gbit/s: in 0.8 % / out 0.4 %)', text)
    shot('107-bound-port.png')
    menu = js("Array.from((%s)('p1').querySelectorAll('.topology-menu-item')).map(e => e.textContent.trim())" % LINKS_ROW)
    check('Unbind' in menu and 'Bind to interface' not in menu, 'a bound port offers Unbind, not Bind to interface', menu)

    print('=== Bind p2 to the same interface ===')
    js("Array.from(document.querySelectorAll('#topology-details details.topology-port-group')).forEach(d => { d.open = true; })")
    row_menu("(%s)('p2')" % PORTS_ROW)
    click("(%s)('p2').querySelector('.topology-port-bind')" % PORTS_ROW)
    wait("!!document.querySelector('.topology-bind-table')")
    taken = js("(() => { const r = document.getElementById('topology-bind-1'); return [r.disabled, r.closest('tr').textContent.trim()]; })()")
    check(taken[0] is True and 'used by p1' in taken[1], 'the interface of p1 is shown with p1 and is not selectable', taken)
    p2 = db("select id from topo_nodes where type = 'port' and json_unquote(json_extract(attrs, '$.name')) = 'p2'")
    refused = js("""(async () => { try { await view.request('topology.ports.bind', {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({id: '%s', if_index: 1})}); return 'bound'; } catch (e) { return e.message; } })()""" % p2)
    check('p1 already has this interface' in refused and db("select json_extract(attrs, '$.if_index') from topo_nodes where id = %s" % p2) in ('', 'NULL'), 'the server refuses p2 on the same interface: "p1 already has this interface"', refused)
    click(dialog_button('Cancel'))
    wait("!document.querySelector('.topology-bind-table')")

    print('=== Unbind p1 ===')
    row_menu("(%s)('p1')" % LINKS_ROW)
    click("(%s)('p1').querySelector('.topology-port-unbind')" % LINKS_ROW)
    wait("!(%s)('p1').querySelector('.topology-link-ports').textContent.includes('→')" % LINKS_ROW, 20)
    check('→' not in js("(%s)('p1').textContent" % LINKS_ROW) and db("select json_extract(attrs, '$.if_index') from topo_nodes where type = 'port' and json_unquote(json_extract(attrs, '$.name')) = 'p1'") in ('', 'NULL'), 'Unbind: the row shows p1 only, the index is cleared')
    menu = js("Array.from((%s)('p1').querySelectorAll('.topology-menu-item')).map(e => e.textContent.trim())" % LINKS_ROW)
    check('Bind to interface' in menu and 'Unbind' not in menu, 'after Unbind the menu offers "Bind to interface" again', menu)

    print('=== a Port that a PORTS row took is a reported port (task 108) ===')
    js("""(async () => { await view.request('topology.ports.bind', {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({id: '%s', if_index: 2})}); return 1; })()""" % db("select id from topo_nodes where type = 'port' and json_unquote(json_extract(attrs, '$.name')) = 'p1'"))
    # the ingest step of the same change is tested on the server (test_bind_interface.php); here the page gets the data of such a Port: source reported, no binding
    db("update topo_nodes set attrs = json_set(json_remove(attrs, '$.interface_name'), '$.source', 'reported', '$.name', 'Gi0/1') where type = 'port' and json_unquote(json_extract(attrs, '$.name')) = 'p1'")
    js("(async () => { const n = view.drawn_nodes.find(n => n.label === %s); await view.selectNode(n); return 1; })()" % json.dumps(hostname))
    P.after_input(js)
    wait("(%s)('Gi0/1')" % LINKS_ROW, 15)
    text = js("(%s)('Gi0/1').textContent" % LINKS_ROW)
    menu = js("Array.from((%s)('Gi0/1').querySelectorAll('.topology-menu-item')).map(e => e.textContent.trim())" % LINKS_ROW)
    check('Gi0/1' in text and '→ Gi' not in text and 'p1' not in text, 'the reported Port shows its reported name only (no "→")', text)
    check(not [m for m in menu if m in ('Rename', 'Delete', 'Bind to interface', 'Unbind')], 'it has no Rename, Delete, Bind or Unbind', menu)

    print('=== Depromote, delete the Device ===')
    click("document.querySelector('#topology-details .topology-node-actions .topology-menu-button')")
    click("document.querySelector('#topology-details .topology-depromote-button')")
    wait("view.drawn_nodes.some(n => n.label === 'BIND-TEST')", 20)
    js("(async () => { const n = view.drawn_nodes.find(n => n.label === 'BIND-TEST'); await view.selectNode(n); return 1; })()")
    P.after_input(js)
    click("document.querySelector('#topology-details .topology-node-actions .topology-menu-button')")
    click("document.querySelector('#topology-details .topology-delete-device-button')")
    check(wait("view.drawn_nodes.every(n => n.label !== 'BIND-TEST')", 20), 'Delete Device: BIND-TEST is not drawn any more')
    P.after_input(js)
finally:
    for table, itemid, clock in HISTORY:
        db("delete from %s where itemid = %s and clock = %d" % (table, itemid, clock))
    db("delete from topo_nodes where type = 'device' and json_unquote(json_extract(attrs, '$.source')) = 'manual' and json_unquote(json_extract(attrs, '$.sysname')) = 'BIND-TEST'")
    chrome.kill()
check(left_over() == '0' and db("select count(*) from topo_edges e left join topo_nodes a on a.id = e.src_id where a.id is null") == '0', 'nothing of this check is left: no BIND-TEST Device, no port of it, no dangling link', left_over())
if host:
    check(db("select count(*) from topo_nodes where type = 'host' and host_ref = %s and represented_by_node_id is null" % host[0]) in ('0', '1') and db("select count(*) from topo_nodes where represented_by_node_id is not null and type = 'device' and json_unquote(json_extract(attrs, '$.source')) = 'manual'") == '0', 'the host is not represented by a manual Device any more')
    check(history_rows(host[0]) == HISTORY_BEFORE, 'no history row of the check is left (%s rows of the host before and after)' % HISTORY_BEFORE, history_rows(host[0]))
print('\n%s' % ('%d check(s) FAILED' % failed if failed else 'All Bind to interface page checks passed.'))
sys.exit(1 if failed else 0)
