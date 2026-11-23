#!/usr/bin/env python3
"""Page header of the topology page (topology-1-model.md 2.98, §12.1 "Page header", §12.7): one header for the graph view and the last run view.

Checks on the real page: the same title and controls in both views, the switch (selected value, a click opens the other view, Back returns to the graph with its filter),
the links "Neighbor reports", the warning box of an executor that does not run, kiosk mode, the width (no horizontal scroll, the controls on one line). All waits are for a
state of the page (task 116). Needs ZBX_UI_USER, ZBX_UI_PASSWORD, google-chrome and the office stand data. Exit code 1 when a check fails.
"""
import json, os, re, subprocess, sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import page_shot as P

if not os.environ.get('ZBX_UI_USER') or not os.environ.get('ZBX_UI_PASSWORD'):
    print('ZBX_UI_USER and ZBX_UI_PASSWORD are needed')
    sys.exit(2)
cookie = P.login()
failed = 0
RUN_TEXT = r'^(\d+ reporters? ha(s|ve) new data since the last run · )?Last run: \d{4}-\d\d-\d\d '

GRAPH_READY = "document.readyState === 'complete' && typeof view !== 'undefined' && view.first_load_done && view.drawn_nodes && view.drawn_nodes.length > 0 && !!document.getElementById('hostid')"
LASTRUN_READY = "document.readyState === 'complete' && !!document.getElementById('topology-lastrun-summary') && !!document.getElementById('topology-run-line')"
HEADER = """(() => { const nav = document.querySelector('nav[aria-label="Content controls"]'); const items = nav ? Array.from(nav.querySelectorAll(':scope > ul > li')) : [];
    const radio = Array.from(document.querySelectorAll('input[type=radio][name=view]')).find(e => e.checked);
    return {title: (document.querySelector('h1') || {}).textContent, items: items.map(li => ({text: li.textContent.trim(), top: Math.round(li.getBoundingClientRect().top), cls: (li.firstElementChild || {}).className || ''})),
        selected: radio ? radio.value : null, values: Array.from(document.querySelectorAll('input[type=radio][name=view]')).map(e => e.value),
        run_line_grey: !!document.querySelector('#topology-run-line.grey'), button: (document.getElementById('topology-ingest-run') || {}).textContent,
        kiosk_icon: !!document.querySelector('nav[aria-label="Content controls"] button[class*=btn-kiosk], nav[aria-label="Content controls"] .btn-kiosk'),
        old_title: /Topology prototype|Topology: last run|Physical topology/.test(document.body.innerText), scroll: document.documentElement.scrollWidth - document.documentElement.clientWidth,
        box_visible: (() => { const b = document.getElementById('topology-executor-missing'); return !!b && b.getBoundingClientRect().height > 0; })()}; })()"""


def check(condition, text, detail=None):
    global failed
    print(('  ok: ' if condition else '  FAIL: ') + text + ('' if condition else ' ' + json.dumps(detail)))
    failed += 0 if condition else 1


def sql(statement):
    return subprocess.run(['mysql', '-N', '-uzabbix', 'zabbix', '-e', statement], capture_output=True, text=True).stdout.strip()


def graph(width=1452, height=900, extra=''):
    return P.open_page(cookie, 'hdr', 9500, width, height, action='topology.view' + extra, ready=GRAPH_READY)


def lastrun(width=1452, height=1000, extra=''):
    return P.open_page(cookie, 'hdr', 9500, width, height, action='topology.view&view=lastrun' + extra, ready=LASTRUN_READY)


def menu(js, mode):
    js("(() => { const s = document.querySelector('.sidebar'); s.classList.toggle('is-compact', %s); s.classList.remove('is-hidden'); window.dispatchEvent(new Event('resize')); })()" % ('true' if mode == 'collapsed' else 'false'))
    P.next_frames(js)


print('=== the same header in both views (1452 px, menu open) ===')
chrome, ws, js = graph()
g = js(HEADER)
chrome.close()
chrome, ws, js = lastrun()
l = js(HEADER)
chrome.close()
check(g['title'] == 'Topology' and l['title'] == 'Topology', 'the title is "Topology" in both views', [g['title'], l['title']])
check(not g['old_title'] and not l['old_title'], 'no "Topology prototype", "Topology: last run" or "Physical topology" in either view', [g['old_title'], l['old_title']])
check(len(g['items']) == 4 and len(l['items']) == 4 and [i['text'] for i in g['items']][1:] == [i['text'] for i in l['items']][1:] == ['GraphLast run', 'Run reconciliation', ''] or
      [i['text'] for i in g['items']][1:3] == [i['text'] for i in l['items']][1:3] == ['GraphLast run', 'Run reconciliation'], 'the same controls in the same order: run state, switch, "Run reconciliation", kiosk icon', [g['items'], l['items']])
check(g['run_line_grey'] and l['run_line_grey'] and re.match(RUN_TEXT, g['items'][0]['text']) and re.match(RUN_TEXT, l['items'][0]['text']),
      'the run state is grey text "[<N> reporters have new data since the last run · ]Last run: <time>", not a link', [g['items'][0], l['items'][0]])
check(g['selected'] == 'graph' and l['selected'] == 'lastrun' and g['values'] == l['values'] == ['graph', 'lastrun'], 'the selected value of the switch is the current view', [g['selected'], l['selected']])
check(g['button'] == 'Run reconciliation' and l['button'] == 'Run reconciliation', 'the button is "Run reconciliation"', [g['button'], l['button']])
check([i['top'] for i in g['items']] == [g['items'][0]['top']] * 4 and [i['top'] for i in l['items']] == [l['items'][0]['top']] * 4, 'the controls are on one line at 1452 px', [g['items'], l['items']])
check(g['scroll'] <= 0 and l['scroll'] <= 0, 'no horizontal scroll at 1452 px, menu open', [g['scroll'], l['scroll']])
check(not g['box_visible'] and not l['box_visible'], 'no warning box while the executor works (nothing requested)', [g['box_visible'], l['box_visible']])

print('=== the width with the menu collapsed (1268 px) ===')
chrome, ws, js = graph(1268, 900)
menu(js, 'collapsed')
g = js(HEADER)
chrome.close()
chrome, ws, js = lastrun(1268, 1000)
menu(js, 'collapsed')
l = js(HEADER)
chrome.close()
check(g['scroll'] <= 0 and l['scroll'] <= 0, 'no horizontal scroll at 1268 px, menu collapsed', [g['scroll'], l['scroll']])
check([i['top'] for i in g['items']] == [g['items'][0]['top']] * 4 and [i['top'] for i in l['items']] == [l['items'][0]['top']] * 4, 'the controls are on one line at 1268 px', [g['items'], l['items']])

print('=== the switch, the URL and Back ===')
chrome, ws, js = graph()
js("""(() => { const h = Array.from(view.nodes.values()).find(n => n.type === 'host' && n.label === 'SW13-10-ACC-6000-48G'); jQuery('#hostid').multiSelect('addData', [{id: h.hostid, name: h.name}]);
    document.getElementById('topology-filter-hops').value = '2'; document.getElementById('topology-filter-apply').click(); return 1; })()""")
P.until(js, "view.idle && view.loads_done >= 2", 'the filter is applied')
before = js("({url: location.search, hostid: (jQuery('#hostid').multiSelect('getData')[0] || {}).id, hops: document.getElementById('topology-filter-hops').value, nodes: view.drawn_nodes.length, k: Math.round(view.zoom_transform.k * 1000) / 1000})")
check('hostid=' in before['url'] and 'hops=2' in before['url'], 'Apply writes the filter into the URL', before)
js("document.querySelector('input[type=radio][name=view][value=lastrun]').click()")
P.until(js, "location.search.includes('view=lastrun') && document.readyState === 'complete' && !!document.getElementById('topology-lastrun-summary')", 'the last run view is open')
now = js(HEADER)
check(now['selected'] == 'lastrun' and now['title'] == 'Topology', 'a click on "Last run" opens the last run view; the switch shows "Last run"', now['selected'])
js("history.back()")
P.until(js, GRAPH_READY + " && location.search.includes('hostid=')", 'Back: the graph is open again with its filter in the URL')
P.idle(js)
back = js("({url: location.search, hostid: (jQuery('#hostid').multiSelect('getData')[0] || {}).id, hops: document.getElementById('topology-filter-hops').value, nodes: view.drawn_nodes.length, k: Math.round(view.zoom_transform.k * 1000) / 1000, selected: document.querySelector('input[type=radio][name=view]:checked').value})")
check(back['hostid'] == before['hostid'] and back['hops'] == before['hops'] and back['nodes'] == before['nodes'] and back['k'] == before['k'] and back['selected'] == 'graph',
      'Back returns to the graph with the same filter, the same nodes and the same fit', [before, back])
chrome.close()

print('=== "Neighbor reports" opens the last run view with the list filtered ===')
chrome, ws, js = graph()
js("(async () => { const n = Array.from(view.nodes.values()).find(n => n.type === 'device' && n.label === 'SW12-10-CORE-6000-48G') || Array.from(view.nodes.values()).find(n => n.label === 'SW12-10-CORE-6000-48G'); await view.selectNode(n); return 1; })()")
P.idle(js)
href = js("(document.querySelector('#topology-details a.topology-node-reports') || {}).getAttribute && document.querySelector('#topology-details a.topology-node-reports').getAttribute('href')")
check(href is not None and 'action=topology.view&view=lastrun&device_id=' in href, 'the link "Neighbor reports" of a Device is the new URL', href)
chrome.close()
if href:
    chrome, ws, js = P.open_page(cookie, 'hdr', 9500, 1452, 1000, action=href.split('action=', 1)[1], ready=LASTRUN_READY)
    now = js(HEADER)
    chips = js("document.body.innerText.includes('Device: ')")
    check(now['selected'] == 'lastrun' and chips, 'the last run view opens with the Device in its filter; the switch shows "Last run"', [now['selected'], chips])
    chrome.close()

print('=== the executor does not run: a warning box in both views ===')
saved = sql('select requested_at, requested_by from topo_run where id = 1').split('\t')
try:
    sql("update topo_run set requested_at = unix_timestamp() - 600, requested_by = 'header_check' where id = 1")
    chrome, ws, js = graph()
    g = js(HEADER)
    text = js("(document.getElementById('topology-executor-missing') || {}).textContent")
    chrome.close()
    chrome, ws, js = lastrun()
    l = js(HEADER)
    chrome.close()
    check(g['box_visible'] and l['box_visible'] and text == 'The reconciliation does not run. Check the scheduled CLI job.', 'a request older than 5 minutes: the warning box shows in both views', [g['box_visible'], l['box_visible'], text])
    check(not any('does not run' in i['text'] for i in g['items'] + l['items']), 'the warning is not part of the header text', [g['items'], l['items']])
finally:
    sql("update topo_run set requested_at = %s, requested_by = '%s' where id = 1" % (saved[0], saved[1] if len(saved) > 1 else ''))

print('=== kiosk mode ===')
KIOSK_ON = "document.readyState === 'complete' && !!document.querySelector('.layout-mode[data-layout-mode=\"0\"]')"      # the button "Normal view" is there
KIOSK_OFF = "document.readyState === 'complete' && !!document.querySelector('.layout-mode[data-layout-mode=\"1\"]') && !!document.querySelector('nav[aria-label=\"Content controls\"] input[type=radio][name=view]')"
try:
    for name, opener in (('graph', graph), ('last run', lastrun)):
        chrome, ws, js = opener()
        js("document.querySelector('.layout-mode[data-layout-mode=\"1\"]').click()")      # the page saves the mode in the user profile and reloads
        P.until(js, KIOSK_ON, 'kiosk mode is on (%s view)' % name)
        if name == 'graph':
            P.until(js, GRAPH_READY, 'the graph is loaded in kiosk mode')
            P.idle(js)
            fit = js("(() => { const p = document.querySelector('.topology-prototype'); const b = p.getBoundingClientRect(); return {kiosk_class: p.classList.contains('topology-kiosk'), bottom: Math.round(b.bottom), inner: innerHeight}; })()")
            check(fit['kiosk_class'] and fit['inner'] - fit['bottom'] < 60, 'kiosk mode, graph view: the view fills the window', fit)
        else:
            P.until(js, "document.readyState === 'complete' && !!document.getElementById('topology-lastrun-summary')", 'the last run view is loaded in kiosk mode (the header is not drawn)')
        js("document.querySelector('.layout-mode[data-layout-mode=\"0\"]').click()")
        P.until(js, KIOSK_OFF, 'leaving kiosk mode (%s view)' % name)
        back = js(HEADER)
        check(back['title'] == 'Topology' and len(back['items']) >= 3, 'leaving kiosk mode (%s view) restores the header' % name, back['title'])
        chrome.close()
finally:
    # the mode is a setting of the user profile: never leave it on
    sql("delete from profiles where idx = 'web.layout.mode' and userid = (select userid from users where username = '%s')" % os.environ['ZBX_UI_USER'].replace("'", ''))

print('\n%s' % ('%d check(s) FAILED' % failed if failed else 'All header checks passed.'))
sys.exit(1 if failed else 0)
