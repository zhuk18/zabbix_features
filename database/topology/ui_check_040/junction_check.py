"""task 104 §2 (model §12.6 "Junction projection"): on the real page, 1500 x 900. One small node on the anchor Port with a line from the reporter and a line to each Device behind it; no
direct line of the Port to those Devices; a click on the node opens its panel (the anchor Port and the Devices, read only, no action); a click on a Device of the panel opens it with "Back" to the
Junction panel; the node is marked by the selection ring. The stand decides which Junction exists: the office stand R1 has the MikroTik of SW18 port 6, the demo stand also SW17 port 16.
Needs ZBX_UI_USER, ZBX_UI_PASSWORD, google-chrome and an office stand in the database. Exit code 1 when a check fails. `--shot <file>` saves a screenshot of the graph with the panel.
"""
import atexit, shutil, json, math, os, subprocess, sys, tempfile, time, urllib.request
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import page_shot as P


def db_count(statement):
    return int(subprocess.run(['mysql', '-N', '-uzabbix', 'zabbix', '-e', statement], capture_output=True, text=True).stdout.strip() or '-1')

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
    chrome, ws, js = P.open_page(cookie, 'panel', 9700)
    return chrome, ws, js


def mouse(ws, x, y):
    ws.call('Input.dispatchMouseEvent', type='mouseMoved', x=x, y=y, button='none', buttons=0)


def select(js, label, kind=None):
    js("""(async () => { const n = Array.from(view.nodes.values()).find(n => n.label === %s && (%s)); await view.selectNode(n); return 1; })()""" % (json.dumps(label), ("n.type === '%s'" % kind) if kind else 'true'))
    P.after_input(js)




BACK = "(() => { const b = document.querySelector('#topology-details .topology-back-button'); return b ? b.textContent.trim() : null; })()"
TITLE_ID = "document.getElementById('topology-details-title').textContent"
shot = sys.argv[sys.argv.index('--shot') + 1] if '--shot' in sys.argv else None

chrome, ws, js = session()
try:
    P.after_input(js)
    junctions = js("view.junctions.map(j => ({port_id: String(j.port_id), port: j.port_name, device: j.device_name, devices: j.devices.map(d => d.name)}))")
    check(len(junctions) >= 1, 'the page has the Junction projections of the stand', junctions)
    # the biggest one is the one the check follows
    junction = max(junctions, key=lambda j: len(j['devices']))
    print('=== %s port %s: %d Devices %s ===' % (junction['device'], junction['port'], len(junction['devices']), junction['devices']))
    node_id = 'junction:' + junction['port_id']
    node = js("(() => { const n = view.drawn_nodes.find(n => n.id === '%s'); return n ? {type: n.type, size: n.size, w: n.shape_w, label: n.label_text, members: n.devices.length} : null; })()" % node_id)
    check(node is not None and node['type'] == 'junction' and node['w'] == 14 and node['label'].endswith(' ' + junction['port']), 'one node on the anchor Port, a small circle labelled "<reporter> <port>" (2.89)', node)
    lines = js("""(() => { const own = view.drawn_links.filter(l => (l.source.id === '%s' || l.target.id === '%s')); return {junction_link: own.filter(l => l.type === 'junction_link').length,
        junction_member: own.filter(l => l.type === 'junction_member').length}; })()""" % (node_id, node_id))
    check(lines['junction_link'] == 1 and lines['junction_member'] == len(junction['devices']), 'a line from the reporter and a line to each Device', lines)
    # 2.89 (§12.6 "Placement"): the node next to its reporter like a leaf, its Devices on an arc around it (radius at most 200 + 50 px of the second row, §12.8), no line through a node
    near = js("""(() => { const n = view.drawn_nodes.find(n => n.id === '%s'); const r = view.drawn_nodes.find(m => m.id === n.parent);
        const members = view.drawn_links.filter(l => l.type === 'junction_member' && (l.source.id === n.id || l.target.id === n.id)).map(l => l.source.id === n.id ? l.target : l.source);
        const lines = view.drawn_links.filter(l => ['junction_link', 'junction_member'].includes(l.type) && (l.source.id === n.id || l.target.id === n.id));
        return {to_reporter: Math.round(Math.hypot(n.x - r.x, n.y - r.y)), to_members: members.map(m => Math.round(Math.hypot(n.x - m.x, n.y - m.y))),
            through: lines.flatMap(l => view.drawn_nodes.filter(o => o !== l.source && o !== l.target && TopologyGraph.lineCrossesNode({a: l.source, b: l.target, index: 0, count: 1}, o, 2)).map(o => o.label_text + ' on ' + l.type))}; })()""" % node_id)
    check(near['to_reporter'] <= 200 and all(60 <= d <= 260 for d in near['to_members']) and not near['through'], 'the node is next to its reporter, its Devices on an arc around it, no Junction line through a node', near)
    direct = js("""(() => view.drawn_links.filter(l => l.type === 'physical_link' && [String(l.source_port_id), String(l.target_port_id)].includes('%s')).length)()""" % junction['port_id'])
    check(direct == 0, 'no direct line of the Port to a Device behind it is drawn', direct)
    stored = db_count("SELECT COUNT(*) FROM topo_nodes WHERE type NOT IN ('device','port','host','proxy')") + db_count("SELECT COUNT(*) FROM topo_edges WHERE type NOT IN ('physical_link','device_link')")
    check(stored == 0, 'nothing of it is stored (no node or edge of another kind)', stored)

    print('=== the panel ===')
    js("""(() => { const g = Array.from(document.querySelectorAll('g.topology-node.junction')).find(e => e.__data__.id === '%s'); g.querySelector('rect.topology-shape').dispatchEvent(new MouseEvent('click', {bubbles: true})); })()""" % node_id)
    P.after_input(js)
    title = js(TITLE_ID)
    check(title == 'Junction at %s, port %s' % (junction['device'], junction['port']), 'the click opens the panel "Junction at <Device>, port <port>"', title)
    rows = js("Array.from(document.querySelectorAll('#topology-details tr.topology-group-row')).map(r => r.textContent.trim())")
    check(sorted(rows) == sorted(junction['devices']), 'the panel lists the Devices behind the Port', rows)
    check(js("document.querySelector('#topology-details .topology-junction-port td').textContent") == junction['port'], 'the panel shows the anchor Port')
    check(js("document.querySelectorAll('#topology-details button, #topology-details input, #topology-details select').length") == 0, 'the panel is read only: no button, no input')
    check(js("view.selection && view.selection.kind") == 'junction' and js("document.querySelectorAll('.topology-selection').length") >= 1, 'the node is marked by the selection ring', js("view.selection"))
    check(js(BACK) is None, 'opened from the graph: no Back')
    if shot:
        ws.call('Page.captureScreenshot')  # warm up
        data = ws.call('Page.captureScreenshot', format='png')['data']
        import base64
        open(shot, 'wb').write(base64.b64decode(data))
        print('screenshot: ' + shot)
    print('=== a Device of the list, then Back ===')
    js("document.querySelector('#topology-details tr.topology-group-row').click()")
    P.after_input(js)
    back = js(BACK)
    check(back is not None and back.startswith('← Back to Junction at '), 'a Device of the list: "← Back to Junction at …"', back)
    js("document.querySelector('#topology-details .topology-back-button').click()")
    P.after_input(js)
    check(js(TITLE_ID) == title and js("document.querySelectorAll('#topology-details tr.topology-group-row').length") == len(rows) and js(BACK) is None, 'Back: the Junction panel again, same list', js(TITLE_ID))
    print('=== a click on a line of the projection opens the same panel ===')
    js("view.panel_from = null; document.getElementById('topology-details').innerHTML = ''")
    js("""(() => { const e = view.line_entries.find(e => e.link && e.link.type === 'junction_member'); e.open(); })()""")
    P.after_input(js)
    check(js(TITLE_ID) == title, 'a click on a line to a Device opens the Junction panel', js(TITLE_ID))
finally:
    chrome.kill()
print('\n%s' % ('%d check(s) FAILED' % failed if failed else 'All Junction checks passed.'))
sys.exit(1 if failed else 0)
