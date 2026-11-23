#!/usr/bin/env python3
"""Task 117 section 3 (topology-1-model.md §12.5): the name of a node in the device panel, and the name of a neighbor in the Links list, is a link that opens the menu of the node.
A Device with a host: the standard host menu of the frontend and the section "Topology" (Focus on this device, Unfold, Show details). A Device without a host: the section
"Topology" only. A neighbor name in Links: the same menu; "Show details" selects it and opens its panel. All waits are for a state of the page (task 116).
Needs ZBX_UI_USER, ZBX_UI_PASSWORD, google-chrome and the office stand data. Exit code 1 when a check fails."""
import json, os, sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import page_shot as P

if not os.environ.get('ZBX_UI_USER') or not os.environ.get('ZBX_UI_PASSWORD'):
    print('ZBX_UI_USER and ZBX_UI_PASSWORD are needed')
    sys.exit(2)
cookie = P.login()
failed = 0
MENU = "(() => { const m = document.querySelector('.menu-popup'); return m ? m.textContent.replace(/\\s+/g, ' ').trim() : null; })()"


def check(condition, text, detail=None):
    global failed
    print(('  ok: ' if condition else '  FAIL: ') + text + ('' if condition else ' ' + json.dumps(detail)))
    failed += 0 if condition else 1


def select(js, label, kind):
    js("(async () => { const n = Array.from(view.nodes.values()).find(n => n.label === %s && n.type === %s); await view.selectNode(n); return 1; })()" % (json.dumps(label), json.dumps(kind)))
    P.idle(js)


def open_menu(js, selector):
    js("document.body.click()")
    js("document.querySelector(%s).click()" % json.dumps(selector))
    P.until(js, "document.querySelector('.menu-popup a, .menu-popup .menu-popup-item')", 'the menu of the name is open')
    return js(MENU)


chrome, ws, js = P.open_page(cookie, 'menu', 9600)
print('=== a Device with a host: the standard host menu and the section Topology ===')
select(js, 'SW12-10-CORE-6000-48G', 'host')
text = open_menu(js, '#topology-details .topology-node-menu-link')
check(all(word in text for word in ('Latest data', 'Problems', 'Inventory')) and 'Topology' in text, 'the name of the host opens the host menu (Latest data, Problems, Inventory ...) and the section Topology', text)
check('Focus on this device' in text and 'Show details' in text, 'the section Topology has "Focus on this device" and "Show details"', text)
check('Unfold' not in text, 'no "Unfold": SW12 has no folded neighbors', text)

print('=== a Device with folded neighbors: Unfold ===')
select(js, 'SW18-09-ACC-2530-48G', 'host')
text = open_menu(js, '#topology-details .topology-node-menu-link')
check('Unfold' in text, 'SW18 has folded neighbors: the section has "Unfold"', text)
js("Array.from(document.querySelectorAll('.menu-popup-item')).find(e => e.textContent.trim() === 'Unfold').click()")
P.until(js, "view.unfolded.has(String(Array.from(view.nodes.values()).find(n => n.label === 'SW18-09-ACC-2530-48G' && n.type === 'host').id)) && view.idle", 'the group is unfolded by the menu')
check(True, 'a click on "Unfold" unfolds the group')

print('=== a Device without a host: the section Topology only ===')
js("document.body.click()")
select(js, 'NA12501003431', 'device')
text = open_menu(js, '#topology-details .topology-node-menu-link')
check('Topology' in text and 'Show details' in text and 'Latest data' not in text and 'Inventory' not in text, 'a Device without a host: the menu has the section Topology and no host items', text)

print('=== a neighbor name in Links ===')
js("document.body.click()")
select(js, 'SW12-10-CORE-6000-48G', 'host')
name = js("(document.querySelector('#topology-details .topology-neighbor[data-node]') || {}).textContent")
text = open_menu(js, '#topology-details .topology-neighbor[data-node]')
check(name and 'Show details' in text, 'the name of a neighbor (%s) opens a menu with "Show details"' % name, text)
js("Array.from(document.querySelectorAll('.menu-popup-item')).find(e => e.textContent.trim() === 'Show details').click()")
P.idle(js)
title = js("(document.querySelector('#topology-details .topology-node-menu-link') || {}).textContent")
check(title and title != 'SW12-10-CORE-6000-48G', '"Show details" selects the neighbor and opens its panel', title)
chrome.close()

print('\n%s' % ('%d check(s) FAILED' % failed if failed else 'All menu checks passed.'))
sys.exit(1 if failed else 0)
