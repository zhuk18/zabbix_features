#!/usr/bin/env python3
"""Task 117 sections 3 and 6 (topology-1-model.md §12.3, §12.5): the actions are in menus, the device panel is for information only.

A click on the label of a node on the graph opens the menu of the node: for a Host the standard host menu of the frontend and the section "Topology"; for a Device without a host and for a Proxy the
section "Topology" only. A click on a line opens the menu of the line. A click on the icon still selects the node and opens its panel. The panel has no action buttons, and no Problems section: the
problems of a Host are the counters of the summary line. All waits are for a state of the page (task 116).
Needs ZBX_UI_USER, ZBX_UI_PASSWORD, google-chrome and the office stand data. Exit code 1 when a check fails."""
import json, os, sys

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


def close_menu(js):
    js("document.body.click()")
    P.until(js, "!document.querySelector('.menu-popup')", 'the menu is closed')


def click_icon(js, label, kind):
    js("""(() => { const g = Array.from(document.querySelectorAll('#topology-canvas g.topology-node')).find(e => String(e.__data__.label) === %s && e.__data__.type === %s);
        g.querySelector('rect.topology-shape').dispatchEvent(new MouseEvent('click', {bubbles: true})); })()""" % (json.dumps(label), json.dumps(kind)))
    P.idle(js)


chrome, ws, js = P.open_page(cookie, 'menu', 9600)
# the legend of the user may be open over the graph (its state is kept in the profile) and covers nodes: it is hidden for the check in this page only, nothing is written
js("document.getElementById('topology-legend').style.display = 'none'")

print('=== the label of a Host: the host menu and the section Topology ===')
check(js("(() => { const t = document.querySelector('#topology-canvas g.topology-node.host text.topology-label'); const s = getComputedStyle(t); return s.cursor === 'pointer' && s.pointerEvents !== 'none'; })()"),
      'the label of a node is a link: a pointer cursor')
text = P.node_menu(js, 'SW12-10-CORE-6000-48G', node_type='host')
check(all(word in text for word in ('Latest data', 'Problems', 'Inventory')) and 'Topology' in text, 'the host menu (Latest data, Problems, Inventory ...) and the section Topology', text)
check('Focus' in text and 'Neighbor reports' in text, 'the section Topology has "Focus" and "Neighbor reports"', text)
check('Unfold' not in text and 'Fold' not in text, 'no Fold / Unfold: SW12 has no folded neighbors', text)
check(js("document.querySelectorAll('#topology-details .topology-host-name').length") == 0, 'a click on the label opens a menu and does not open the panel', js("document.getElementById('topology-details').textContent.slice(0, 60)"))
close_menu(js)
check(js("document.getElementById('topology-canvas').querySelectorAll('.topology-selected').length") == 0, 'a click on the label selects nothing')

print('=== the icon: select and open the panel ===')
click_icon(js, 'SW12-10-CORE-6000-48G', 'host')
panel = js("""(() => { const d = document.getElementById('topology-details'); return {title: (d.querySelector('.topology-node-title') || {}).textContent, plain_name: !!d.querySelector('h4.topology-node-title') && !d.querySelector('a.topology-node-title, a.topology-host-name'),
    actions: d.querySelectorAll('.topology-node-actions, .topology-focus-button, .topology-menu-button, .topology-node-reports, .topology-menu-list').length, problems_section: !!d.querySelector('details.topology-problems'),
    neighbors: d.querySelectorAll('a.topology-neighbor[data-node]').length, summary: (d.querySelector('.topology-node-summary') || {}).textContent}; })()""")
check(panel['title'] == 'SW12-10-CORE-6000-48G' and panel['plain_name'], 'a click on the icon selects the node and opens its panel; the name is plain text', panel)
check(panel['actions'] == 0, 'the panel has no action buttons and no menu: no Focus, no "Neighbor reports", no "⋯"', panel)
check(not panel['problems_section'], 'the panel has no Problems section', panel)
check(panel['neighbors'] > 0, 'the neighbor names in Links are links that select the neighbor', panel)
name = js("document.querySelector('#topology-details a.topology-neighbor[data-node]').textContent")
js("document.querySelector('#topology-details a.topology-neighbor[data-node]').click()")
P.idle(js)
check(js("document.querySelector('#topology-details .topology-node-title').textContent") != 'SW12-10-CORE-6000-48G' and not js("document.querySelector('.menu-popup')"), 'a click on a neighbor name selects the neighbor (navigation, no menu): %s' % name)

print('=== Fold / Unfold in the menu ===')
text = P.node_menu(js, 'SW18-09-ACC-2530-48G', node_type='host')
check('Unfold' in text and 'devices' in text, 'SW18 has folded neighbors: "Unfold <N> devices"', text)
js("Array.from(document.querySelectorAll('.menu-popup-item')).find(e => e.textContent.trim().startsWith('Unfold')).click()")
P.until(js, "view.unfolded.has(String(Array.from(view.nodes.values()).find(n => n.label === 'SW18-09-ACC-2530-48G' && n.type === 'host').id)) && view.idle", 'the group is unfolded by the menu')
text = P.node_menu(js, 'SW18-09-ACC-2530-48G', node_type='host')
check('Fold' in text and 'Unfold' not in text.replace('Unfold ', 'x'), 'unfolded: "Fold <N> devices"', text)
close_menu(js)

print('=== the label of a Device without a host: the section Topology only ===')
text = P.node_menu(js, 'NA12501003431', node_type='device')
check('Topology' in text and 'Neighbor reports' in text and 'Latest data' not in text and 'Inventory' not in text, 'a Device without a host: the section Topology, no host items', text)
check('Promote to host' in text and 'Delete Device' in text, 'it has "Promote to host" and "Delete Device"', text)
close_menu(js)

print('=== the label of a Proxy: the section Topology only (a node made for the check) ===')
proxy = js("""(() => { const items = view.nodeMenuItems({type: 'proxy', id: 'proxy:1', name: 'P', label: 'P', device: {id: '99', represented: true, duplicates: [], dismissed: []}});
    return items.map(i => i.label); })()""")
check(proxy == ['Neighbor reports', 'Depromote'], 'a Proxy: only topology actions that apply (no Focus: it is not a host)', proxy)

print('=== the problem counters of the summary line ===')
counters = js("""(() => { const node = {type: 'host', hostid: '10084'}; const problems = [{severity: 4, severity_name: 'High'}, {severity: 4, severity_name: 'High'}, {severity: 2, severity_name: 'Warning'}];
    const host = view.problemCounters(node, problems); const none = view.problemCounters(node, []); const device = view.problemCounters({type: 'device'}, problems);
    const d = document.createElement('div'); d.innerHTML = host; return {items: Array.from(d.querySelectorAll('.problem-icon-list-item')).map(e => [e.textContent, e.className, e.title]), href: (d.querySelector('a') || {}).getAttribute('href'), none: none, device: device}; })()""")
check([i[0] for i in counters['items']] == ['2', '1'] and [i[2] for i in counters['items']] == ['High', 'Warning'] and 'status-high-bg' in counters['items'][0][1] and 'status-warning-bg' in counters['items'][1][1],
      'the counters are the standard element of the Hosts list: one count for each severity that has problems, the highest first', counters)
check('action=problem.view' in counters['href'] and '10084' in counters['href'], 'a click opens Monitoring → Problems for the host', counters['href'])
check(counters['none'] == '' and counters['device'] == '', 'no problems: nothing; a Device without a host: no counters', counters)

print('=== the menu of a line ===')
close_menu(js)
js("view.unfolded.clear(); view.render()")
P.idle(js)
LINK = "view.line_entries.find(e => e.link && e.link.type === 'physical_link' && e.menu)"
text = P.line_menu(js, LINK)
check('Link details' in text and 'Neighbor reports' in text, 'a click on a link line: "Link details" and "Neighbor reports" of the port', text)
js("Array.from(document.querySelectorAll('.menu-popup-item')).find(e => e.textContent.trim() === 'Link details').click()")
P.idle(js)
check(js("document.getElementById('topology-details-title').textContent") == 'Link details' and js("!!document.querySelector('#topology-details h2')"), '"Link details" opens the link panel, as a click on the line did before')
BUNDLE = "view.line_entries.find(e => e.link && e.link.type === 'lag_bundle')"
text = P.line_menu(js, BUNDLE)
check('Link details' in text and 'Unlink' not in text, 'a click on a bundle line: "Link details" only (Unlink is in the members)', text)
js("Array.from(document.querySelectorAll('.menu-popup-item')).find(e => e.textContent.trim() === 'Link details').click()")
P.idle(js)
check('↔' in js("document.querySelector('#topology-details h2').textContent") and js("document.querySelectorAll('#topology-details [data-member]').length") > 0, '"Link details" of a bundle opens the bundle panel')
ghost = js("""(() => view.lineMenuItems({ghost: {entry: {edge_id: 7}, shadow: {hidden_device_id: 5}}, open() {}}).map(i => i.label))()""")
check(ghost == ['Link details', 'Keep manual', 'Accept discovery'], 'a ghost line (a manual link that discovery contradicts): Link details, Keep manual, Accept discovery', ghost)
manual = js("""(() => view.lineMenuItems({link: {type: 'device_link', edge_id: 9, source: {id: '1', name: 'A'}, target: {id: '2', name: 'B'}, source_port_id: null, target_port_id: null}, open() {}}).map(i => i.label))()""")
check(manual == ['Link details', 'Unlink'], 'a manual device link: Link details and Unlink (with the confirmation)', manual)
print('=== the panel: the Zabbix font, no kind badge, no class icon (task 117 section 8) ===')
click_icon(js, 'SW12-10-CORE-6000-48G', 'host')
js("Array.from(document.querySelectorAll('#topology-details details.topology-identity')).forEach(d => { d.open = true; })")
P.idle(js)
fonts = js("""(() => { const d = document.getElementById('topology-details'); const body = getComputedStyle(document.body).fontFamily;
    const families = new Set(Array.from(d.querySelectorAll('*')).filter(e => e.textContent.trim() !== '' && e.children.length === 0).map(e => getComputedStyle(e).fontFamily));
    const label = d.querySelector('.topology-identity .form-grid label'); const copy = d.querySelector('.topology-identity button.zi-copy');
    return {body, families: Array.from(families), mono: Array.from(families).some(f => /mono|courier/i.test(f)), label_weight: label ? getComputedStyle(label).fontWeight : null, label: label ? label.textContent : null,
        copy_icon: !!copy, copy_text: copy ? copy.textContent.trim() : null, text_copy_buttons: d.querySelectorAll('button.btn-alt.topology-copy-button').length, kind_badge: !!d.querySelector('.topology-node-kind'), class_icon: !!d.querySelector('.topology-node-icon'),
        kind_grey: !!d.querySelector('.topology-node-summary .grey'), form_view: !!d.querySelector('.topology-identity .form-grid')}; })()""")
check(fonts['families'] == [fonts['body']] and not fonts['mono'], 'one font in the panel: the font of the frontend, no monospace', fonts)
check(fonts['form_view'] and fonts['label'] is not None and int(fonts['label_weight']) <= 400, 'Identity: label and value as in a Zabbix form view (the label in the left column, normal weight)', fonts)
check(fonts['copy_icon'] and fonts['copy_text'] == '' and fonts['text_copy_buttons'] == 0, 'Copy is the standard copy icon button', fonts)
check(not fonts['kind_badge'] and fonts['kind_grey'] and not fonts['class_icon'], 'no kind badge (the kind is grey text in the summary line) and no class icon before the name', fonts)

print('=== the hint box (task 117 section 7) ===')
js("document.body.click()")
js("view.unfolded.clear(); view.render()")
P.idle(js)


def hover(x, y):
    ws.call('Input.dispatchMouseEvent', type='mouseMoved', x=x, y=y, button='none', buttons=0)


def line_point(entry_js):
    return js("""(() => { const e = %s; const p = e.hit.getPointAtLength(e.hit.getTotalLength() * 0.5); const t = new DOMPoint(p.x, p.y).matrixTransform(e.hit.getScreenCTM()); return {x: t.x, y: t.y}; })()""" % entry_js)


HINT = "(() => { const h = document.querySelector('.hintbox'); return h ? {rows: h.querySelectorAll('tr').length, table: !!h.querySelector('table.list-table'), text: h.textContent, divs: Array.from(h.querySelectorAll('.hintbox-container > div')).map(d => [d.textContent, d.className])} : null; })()"
point = line_point(LINK)
hover(point['x'] - 3, point['y'] - 3)
hover(point['x'], point['y'])
P.until(js, "document.querySelector('.hintbox')", 'the hint box of the link line is shown')
hint = js(HINT)
check(not hint['table'] and len(hint['divs']) >= 2 and '↔' in hint['divs'][0][0] and hint['divs'][1][1] == 'grey' and ' · ' in hint['divs'][1][0],
      'a link line (task 117 section 7): rows, not columns: the ports; in grey the status of both ends · the source · the load', hint)
check(js("!document.getElementById('topology-tooltip') && document.querySelectorAll('.topology-tooltip').length === 0"), 'there is no own tooltip element')
hover(5, 5)
P.until(js, "!document.querySelector('.hintbox')", 'the hint box closes when the pointer leaves the line')
point = line_point(BUNDLE)
hover(point['x'] - 3, point['y'] - 3)
hover(point['x'], point['y'])
P.until(js, "document.querySelector('.hintbox')", 'the hint box of the bundle line is shown')
hint = js(HINT)
members = js("%s.link.members.length" % BUNDLE)
check(hint['table'] and hint['rows'] == members and hint['divs'][0][0].startswith('LAG'), 'a bundle line: the title, and a table of the member rows only (%d members: ports, status, load)' % members, hint)
hover(5, 5)
P.until(js, "!document.querySelector('.hintbox')", 'the hint box closes')
box = js("""(() => { const g = Array.from(document.querySelectorAll('#topology-canvas g.topology-node')).find(e => String(e.__data__.label) === 'SW12-10-CORE-6000-48G'); const b = g.querySelector('rect.topology-shape').getBoundingClientRect(); return {x: b.left + b.width / 2, y: b.top + b.height / 2}; })()""")
hover(box['x'] - 2, box['y'] - 2)
hover(box['x'], box['y'])
P.next_frames(js)
P.idle(js)
check(js("!document.querySelector('.hintbox')"), 'a node (icon and label) has no hint box: the panel and the menu show the same')
hover(5, 5)
# a group node that nothing covers (the legend of the user may be open over one of them)
grp = js("""(() => { for (const g of document.querySelectorAll('#topology-canvas g.topology-node.group')) { const r = g.querySelector('rect.topology-shape'); const b = r.getBoundingClientRect(); const x = b.left + b.width / 2, y = b.top + b.height / 2;
    if (document.elementFromPoint(x, y) === r) { return {x, y}; } } return null; })()""")
hover(grp['x'] - 2, grp['y'] - 2)
hover(grp['x'], grp['y'])
P.until(js, "document.querySelector('.hintbox')", 'the hint box of a group node is shown')
check(js("!!document.querySelector('.hintbox table.list-table') && /devices/.test(document.querySelector('.hintbox').textContent)"), 'a group node keeps its hint box (the name and the count by class)')
hover(5, 5)
P.until(js, "!document.querySelector('.hintbox')", 'the hint box closes')
long_name = 'A-label-that-is-much-too-long-to-be-drawn-in-full-on-the-graph-' + 'x' * 40
js("(() => { const n = Array.from(view.nodes.values()).find(n => n.type === 'host' && n.label === 'SW13-10-ACC-6000-48G'); n.label = %s; view.render(); })()" % json.dumps(long_name))
P.idle(js)
cut = js("""(() => { const t = Array.from(document.querySelectorAll('#topology-canvas g.topology-node')).find(e => String(e.__data__.label) === %s).querySelector('text.topology-label'); const b = t.getBoundingClientRect(); return {x: b.left + b.width / 2, y: b.top + b.height / 2, shown: t.textContent}; })()""" % json.dumps(long_name))
hover(cut['x'] - 2, cut['y'])
hover(cut['x'], cut['y'])
P.until(js, "document.querySelector('.hintbox')", 'the hint box of a cut label is shown')
hint = js(HINT)
check(cut['shown'] != long_name and hint['text'] == long_name and hint['rows'] == 1, 'a label that is cut shows its full name, and only the name', [cut['shown'], hint])
hover(5, 5)
P.until(js, "!document.querySelector('.hintbox')", 'the hint box closes')
js("(() => { const n = Array.from(view.nodes.values()).find(n => n.label === %s); n.label = 'SW13-10-ACC-6000-48G'; view.render(); })()" % json.dumps(long_name))
P.idle(js)
label = js("""(() => { const t = Array.from(document.querySelectorAll('#topology-canvas g.topology-node')).find(e => String(e.__data__.label) === 'SW12-10-CORE-6000-48G').querySelector('text.topology-label'); const b = t.getBoundingClientRect(); return {x: b.left + b.width / 2, y: b.top + b.height / 2}; })()""")
hover(label['x'], label['y'])
P.node_menu(js, 'SW12-10-CORE-6000-48G', node_type='host')
check(js("!document.querySelector('.hintbox')"), 'the hint box closes when the menu opens')
close_menu(js)
select_name = js("document.querySelector('#topology-details .topology-node-title') && 1")
click_icon(js, 'SW12-10-CORE-6000-48G', 'host')
name_point = js("""(() => { const a = document.querySelector('#topology-details a.topology-neighbor[data-node]'); const b = a.getBoundingClientRect(); return {x: b.left + b.width / 2, y: b.top + b.height / 2, title: a.getAttribute('title'), html: !!a.dataset.hintboxHtml}; })()""")
check(name_point['title'] is None and name_point['html'], 'a text of the panel that was a `title` is a hint of the same box (data-hintbox)', name_point)
hover(name_point['x'] - 1, name_point['y'] - 1)
hover(name_point['x'], name_point['y'])
P.until(js, "document.querySelector('.hintbox table.list-table')", 'the hint box of a name in the panel is shown')
check(js("!!document.querySelector('.hintbox table.list-table')"), 'the panel hint is the same box')
hover(5, 5)
chrome.close()

chrome.close()

print('\n%s' % ('%d check(s) FAILED' % failed if failed else 'All menu checks passed.'))
sys.exit(1 if failed else 0)
