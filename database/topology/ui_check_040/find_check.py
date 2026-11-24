#!/usr/bin/env python3
"""task 119 section 2 (model 2.100 §12.1 "Find a device", FR-C V-24): the search field of the graph page, 1500 x 900, office stand, real mouse and keyboard events.
The check makes its own Devices (names FIND-CHECK-DEV, FIND-CHECK-<i>X</i> with markup, FIND-LIMIT-01..22) and removes them in a `finally`.
  - 1 character: no request; two inputs in a row: one request (after 300 ms without typing); by name (a substring, any case), by hardware ID and by the MAC of a port in the forms with colons,
    dashes, dots and none, a placeholder MAC finds nothing, by IP (exact), the markup of a name is text, at most 20 results and "refine the search";
  - the view is scoped to one focus host (depth 1): choosing a Device that is outside of it and has no link draws it, selects it, opens its panel and brings it to the middle of the graph area;
    the filter (focus host, depth, the switches) does not change; choosing a Host (SW21) of the office stand draws it as its host.
Screenshot when SHOTS_DIR is set: 119-search.png. Needs ZBX_UI_USER, ZBX_UI_PASSWORD, google-chrome, the office stand data and the database login of the harness (`mysql -uzabbix zabbix`).
Exit code 1 when a check fails."""
import atexit, json, os, subprocess, sys
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import page_shot as P

if not os.environ.get('ZBX_UI_USER') or not os.environ.get('ZBX_UI_PASSWORD'):
    print('ZBX_UI_USER and ZBX_UI_PASSWORD are needed')
    sys.exit(2)
cookie = P.login()
failed = 0
NAME = 'FIND-CHECK-DEV'
MARKUP = 'FIND-CHECK-<i>X</i>'
CHASSIS = 'aa:bb:cc:00:11:22'
PORT_MAC = 'aa:bb:cc:00:11:23'
IP = '192.0.2.250'
REPORTED_IP = '198.51.100.77'      # a neighbor row reports this address for the Device; the Device has IP as its own (task 120 section 2)


def check(condition, text, detail=None):
    global failed
    print(('  ok: ' if condition else '  FAIL: ') + text + ('' if condition else ' ' + json.dumps(detail)))
    failed += 0 if condition else 1


def db(statement):
    return subprocess.run(['mysql', '-N', '-uzabbix', 'zabbix', '-e', statement], capture_output=True, text=True).stdout.strip()


def attrs_json(**values):
    return json.dumps(values).replace("'", "''").replace('\\', '\\\\')


def make_data():
    out = db("INSERT INTO topo_nodes (type, attrs, created_at, updated_at) VALUES ('device', '%s', 0, 0); SELECT LAST_INSERT_ID();" % attrs_json(sysname=NAME, chassis_id=CHASSIS, mgmt_ip=IP))
    device = out.split('\n')[-1]
    db("INSERT INTO topo_identity_keys (device_id, `key`, kind, first_seen, replaced) VALUES (%s, 'chassis:mac:%s', 'chassis', 0, 0)" % (device, CHASSIS))
    # the observation of a neighbor row that reports another address for the Device (the observation needs an item: any one)
    db("INSERT INTO topo_observations (itemid, local_port_ref, remote_key, remote_attrs, outcome, device_id, first_seen, last_seen) SELECT MIN(itemid), 'FIND-CHECK', 'find-check', '%s', 'linked', %s, 0, 0 FROM items"
       % (attrs_json(rem_mgmt_ip=REPORTED_IP, rem_sysname=NAME), device))
    db("INSERT INTO topo_nodes (type, device_id, attrs, created_at, updated_at) VALUES ('port', %s, '%s', 0, 0)" % (device, attrs_json(if_index=1, name='ge-0/0/9', mac=PORT_MAC, pseudo=False, if_type='physical', admin_status='up', oper_status='up', speed='1000')))
    db("INSERT INTO topo_nodes (type, attrs, created_at, updated_at) VALUES ('device', '%s', 0, 0)" % attrs_json(sysname=MARKUP))
    db("INSERT INTO topo_nodes (type, attrs, created_at, updated_at) VALUES %s" % ', '.join("('device', '%s', 0, 0)" % attrs_json(sysname='FIND-LIMIT-%02d' % i) for i in range(1, 23)))
    return device


def remove_data():
    db("DELETE FROM topo_observations WHERE local_port_ref = 'FIND-CHECK' AND remote_key = 'find-check'")
    db("DELETE FROM topo_identity_keys WHERE device_id IN (SELECT id FROM topo_nodes WHERE type = 'device' AND (attrs LIKE '%FIND-CHECK-%' OR attrs LIKE '%FIND-LIMIT-%'))")
    db("DELETE FROM topo_nodes WHERE type = 'port' AND device_id IN (SELECT id FROM (SELECT id FROM topo_nodes WHERE type = 'device' AND (attrs LIKE '%FIND-CHECK-%' OR attrs LIKE '%FIND-LIMIT-%')) x)")
    db("DELETE FROM topo_nodes WHERE type = 'device' AND (attrs LIKE '%FIND-CHECK-%' OR attrs LIKE '%FIND-LIMIT-%')")


def left_over():
    return db("SELECT (SELECT COUNT(*) FROM topo_nodes WHERE attrs LIKE '%FIND-CHECK-%' OR attrs LIKE '%FIND-LIMIT-%') + (SELECT COUNT(*) FROM topo_identity_keys WHERE `key` LIKE '%aa:bb:cc:00:11:2%') + (SELECT COUNT(*) FROM topo_observations WHERE local_port_ref = 'FIND-CHECK')")


remove_data()
atexit.register(remove_data)
device_id = make_data()
sw12 = db("SELECT hostid FROM hosts WHERE host = 'SW12-10-CORE-6000-48G'")
chrome, ws, js = P.open_page(cookie, 'find', 9800, action='topology.view&hostid=%s&hops=1' % sw12)


def mouse(kind, x, y, button='left', buttons=1):
    ws.call('Input.dispatchMouseEvent', type=kind, x=x, y=y, button=button if kind != 'mouseMoved' else 'none', buttons=buttons if kind == 'mousePressed' else 0, clickCount=1)


def key(name, code, vk):
    for kind in ('keyDown', 'keyUp'):
        ws.call('Input.dispatchKeyEvent', type=kind, key=name, code=code, windowsVirtualKeyCode=vk)


def type_text(text):
    """Click into the field, select what is in it and type the text (real events); then wait for the answer of the search (a state: the rows or the note are on the page)."""
    box = js("(() => { const b = document.getElementById('topology-find-input').getBoundingClientRect(); return {x: b.left + b.width / 2, y: b.top + b.height / 2}; })()")
    for kind in ('mouseMoved', 'mousePressed', 'mouseReleased'):
        mouse(kind, box['x'], box['y'])
    js("document.getElementById('topology-find-input').select()")
    ws.call('Input.insertText', text=text)


def answer(answers_before):
    """The rows and the note of the list once the search of the last input has answered (the list is drawn after the request: `view.find_answers` grew, the page is idle)."""
    P.wait_state(ws, js, "view.find_answers > %d && view.idle && !document.getElementById('topology-find-results').hidden" % answers_before, 'the list of the search is shown')
    return js("""(() => ({rows: Array.from(document.querySelectorAll('#topology-find-results .topology-find-row')).map(r => ({name: r.querySelector('.topology-find-name').textContent,
        matched: r.querySelector('.topology-find-matched').textContent})), note: (document.querySelector('#topology-find-results .topology-find-note') || {}).textContent || null,
        html: document.getElementById('topology-find-results').innerHTML}))()""")


def search(text):
    before = js('view.find_answers')
    type_text(text)
    return answer(before)


def shot(name):
    folder = os.environ.get('SHOTS_DIR')
    if folder:
        import base64
        with open(os.path.join(folder, name), 'wb') as f:
            f.write(base64.b64decode(ws.call('Page.captureScreenshot', format='png')['data']))


FILTER = "(() => ({host: jQuery('#hostid').multiSelect('getData').map(i => i.name), hops: document.getElementById('topology-filter-hops').value, unlinked: document.getElementById('topology-filter-unlinked').checked, duplicates: document.getElementById('topology-filter-duplicates').checked}))()"

try:
    js("window.__finds = 0; (() => { const orig = view.request.bind(view); view.request = (name, options) => { if (String(name).startsWith('topology.devices.find')) { window.__finds++; } return orig(name, options); }; })()")
    filter_before = js(FILTER)
    check(filter_before['host'] and filter_before['hops'] == '1' and filter_before['unlinked'] is False, 'the view is scoped to the focus host SW12, depth 1', filter_before)
    check(js("!Array.from(view.nodes.values()).some(n => n.label === '%s')" % NAME), 'the Device of the check is not in the view', None)

    print('-- the input')
    type_text('F')
    P.after_input(js)
    check(js('window.__finds') == 0 and js("document.getElementById('topology-find-results').hidden"), 'one character: no request, no list', js('window.__finds'))
    before = js('view.find_answers')
    ws.call('Input.insertText', text='IN')
    ws.call('Input.insertText', text='D-')
    rows = answer(before)
    check(js('window.__finds') == 1 and len(rows['rows']) > 0, 'three inputs in a row ("F", "IN", "D-"): one request, 300 ms after the last', [js('window.__finds'), len(rows['rows'])])

    print('-- by name')
    rows = search('find-check-dev')
    check([r['name'] for r in rows['rows']] == [NAME], 'a substring in any case finds the Device by its name', rows['rows'])
    check(rows['rows'] and 'name' in rows['rows'][0]['matched'], 'the row says what matched: name', rows['rows'])
    rows = search('SW21')
    check(any(r['name'].startswith('SW21') for r in rows['rows']), 'a Host of the office stand is found by its name', rows['rows'][:3])

    print('-- by hardware ID and the MAC of a port, all the forms')
    for label, text in (('colons', CHASSIS), ('dashes, upper case', CHASSIS.upper().replace(':', '-')), ('Cisco dots', 'aabb.cc00.1122'), ('no separator', 'AABBCC001122')):
        rows = search(text)
        check([r['name'] for r in rows['rows']] == [NAME] and 'hardware ID' in rows['rows'][0]['matched'], 'the hardware ID with %s finds the Device: "hardware ID"' % label, rows['rows'])
    for label, text in (('colons', PORT_MAC), ('dashes', PORT_MAC.replace(':', '-')), ('dots, upper case', 'AABB.CC00.1123'), ('no separator', 'aabbcc001123')):
        rows = search(text)
        check([r['name'] for r in rows['rows']] == [NAME] and 'port ge-0/0/9 MAC' in rows['rows'][0]['matched'], 'the MAC of a port with %s finds the Device: "port ge-0/0/9 MAC"' % label, rows['rows'])
    for text in ('00:00:00:00:00:00', 'ff-ff-ff-ff-ff-ff'):
        rows = search(text)
        check(rows['rows'] == [] and rows['note'] is not None, 'the placeholder MAC %s finds nothing' % text, rows)

    print('-- by IP (exact)')
    rows = search(IP)
    check([r['name'] for r in rows['rows']] == [NAME] and 'management address' in rows['rows'][0]['matched'], 'the management address finds the Device: "management address"', rows['rows'])
    rows = search('192.0.2.25')
    check(rows['rows'] == [], 'a part of an address finds nothing (exact)', rows['rows'])
    rows = search(REPORTED_IP)
    check([r['name'] for r in rows['rows']] == [NAME] and 'reported address' in rows['rows'][0]['matched'] and 'management address' not in rows['rows'][0]['matched'],
          'an address that a neighbor reports (and the Device does not have as its own) finds the Device: "reported address"', rows['rows'])

    print('-- the markup of a name is text; the limit')
    rows = search('FIND-CHECK-<')
    check([r['name'] for r in rows['rows']] == [MARKUP] and '<i>' not in rows['html'] and js("document.querySelector('#topology-find-results i') === null"), 'a name with markup is shown as text (escaped)', rows['html'][:200])
    rows = search('FIND-LIMIT')
    check(len(rows['rows']) == 20 and rows['note'] and 'refine' in rows['note'], '22 matches: 20 rows and "refine the search"', [len(rows['rows']), rows['note']])

    print('-- choose a Device that the scope hides')
    rows = search('find-check-dev')
    shot('119-search.png')
    box = js("(() => { const b = document.querySelector('#topology-find-results .topology-find-row').getBoundingClientRect(); return {x: b.left + b.width / 2, y: b.top + b.height / 2}; })()")
    for kind in ('mouseMoved', 'mousePressed', 'mouseReleased'):
        mouse(kind, box['x'], box['y'])
    P.wait_state(ws, js, "view.idle && !!view.selected_node && view.selected_node.label === %s" % json.dumps(NAME), 'the chosen Device is selected')
    state = js("""(() => { const n = view.drawn_nodes.find(n => n.label === %s); const c = view.canvas.node().getBoundingClientRect(); const e = n ? n.element.getBoundingClientRect() : null;
        return {drawn: !!n, title: (document.querySelector('#topology-details .topology-node-name') || {}).textContent || '', dx: e ? (e.left + e.width / 2) - (c.left + c.width / 2) : null, dy: e ? (e.top + e.height / 2) - (c.top + c.height / 2) : null,
        list_hidden: document.getElementById('topology-find-results').hidden}; })()""" % json.dumps(NAME))
    check(state['drawn'], 'the Device is drawn although it has no link and is outside the focus host scope', state)
    check(state['title'].startswith(NAME), 'its panel is open', state['title'])
    check(state['dx'] is not None and abs(state['dx']) < 4 and abs(state['dy']) < 20, 'the graph is panned: the node (the icon with its label under it) is in the middle of the graph area', state)
    check(state['list_hidden'], 'the list is closed', state)
    check(js(FILTER) == filter_before, 'the filter did not change (focus host, depth, the two switches)', js(FILTER))

    print('-- choose a Host')
    rows = search('SW21')
    index = next((i for i, r in enumerate(rows['rows']) if r['name'].startswith('SW21')), None)
    box = js("(() => { const b = document.querySelectorAll('#topology-find-results .topology-find-row')[%d].getBoundingClientRect(); return {x: b.left + b.width / 2, y: b.top + b.height / 2}; })()" % (index or 0))
    for kind in ('mouseMoved', 'mousePressed', 'mouseReleased'):
        mouse(kind, box['x'], box['y'])
    P.wait_state(ws, js, "view.idle && !!view.selected_node && view.selected_node.label.startsWith('SW21')", 'the chosen Host is selected')
    state = js("(() => { const n = view.drawn_nodes.find(n => n.label.startsWith('SW21')); return {drawn: !!n, type: n && n.type}; })()")
    check(state['drawn'] and state['type'] == 'host', 'the Host is drawn as its Host and selected', state)
    check(js(FILTER) == filter_before, 'the filter did not change', js(FILTER))

    print('-- keyboard: Arrow, Enter, Escape')
    before = js('view.find_answers')
    type_text('FIND-LIMIT')
    answer(before)
    key('ArrowDown', 'ArrowDown', 40)
    key('Escape', 'Escape', 27)
    check(js("document.getElementById('topology-find-results').hidden"), 'Escape closes the list', None)
finally:
    chrome.close()
    remove_data()
left = left_over()
check(left == '0', 'nothing of the check is left in the database', left)
print('All find checks passed.' if not failed else '%d check(s) FAILED.' % failed)
sys.exit(1 if failed else 0)
