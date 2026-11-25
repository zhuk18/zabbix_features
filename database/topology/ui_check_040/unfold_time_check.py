#!/usr/bin/env python3
"""Task 123 (model §12.1 "No line through a node", §12.8 unfold): a click on a group node unfolds it in at most 500 ms, no main-thread task is longer than 200 ms, and one click unfolds once.

The states are the ones that Dima saw slow (2026-11-25): b) after Reset, pressed once from a view with a focus host (SW12) and depth 2; c) after Reset pressed three times in a row. For every leaf group of the
view, in each state: the state is made again in the same page (Reset starts the layout again, so the groups are folded and the layout is the first one), the group node is panned into the window, a REAL mouse
click is sent, and the time from the mouse press to the moment the page is idle with the group unfolded is measured in the page, with the longest `longtask` (a PerformanceObserver). A click must make one
render() and one unfoldGroup(); the number of event listeners on a group node must be the same after every Reset (Reset adds none).
The limits are checked when the VM is quiet (the 1-minute load average is below 4, the stand and the server alone give 2 to 3.5); on a loaded VM the numbers are printed as a note and do not fail the check,
as panel_time_check.py does. Needs ZBX_UI_USER, ZBX_UI_PASSWORD, google-chrome and the office stand data (R1). Exit code 1 when a check fails."""
import json, os, subprocess, sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import page_shot as P

if not os.environ.get('ZBX_UI_USER') or not os.environ.get('ZBX_UI_PASSWORD'):
    print('ZBX_UI_USER and ZBX_UI_PASSWORD are needed')
    sys.exit(2)
LIMIT_MS, LIMIT_TASK = 500, 200
load = os.getloadavg()[0]
quiet = load < 4
cookie = P.login()
failed = 0


def check(condition, text, detail=None):
    global failed
    print(('  ok: ' if condition else '  FAIL: ') + text + ('' if condition else ' ' + json.dumps(detail)))
    failed += 0 if condition else 1


def note(text):
    print('  note: ' + text)


def sql(query):
    return subprocess.run(['mysql', '-N', '-uzabbix', 'zabbix', '-e', query], capture_output=True, text=True).stdout.split('\n')[0].split('\t')


host_id, host_name = sql("SELECT hostid,name FROM hosts WHERE host='SW12-10-CORE-6000-48G'")
chrome, ws, js = P.open_page(cookie, 'unfold', 9830)


def evaluate(expression):
    return ws.call('Runtime.evaluate', expression=expression, includeCommandLineAPI=True, returnByValue=True, awaitPromise=True).get('result', {}).get('value')


def apply_focus():
    loads = js('view.loads_done')
    js("""(() => { jQuery('#hostid').multiSelect('addData', [%s]); document.getElementById('topology-filter-hops').value = '2'; document.getElementById('topology-filter-apply').click(); })()""" % json.dumps({'id': host_id, 'name': host_name}))
    P.until(js, 'view.loads_done > %d' % loads, 'the focus host is applied')
    P.idle(js)


def reset():
    loads = js('view.loads_done')
    js("document.getElementById('topology-filter-clear').click()")
    P.until(js, 'view.loads_done > %d' % loads, 'Reset made its load')
    P.idle(js)
    P.next_frames(js)


def listeners():
    return evaluate("(() => { const g = document.querySelector('#topology-canvas g.topology-node.group'); const l = getEventListeners(g); return Object.fromEntries(Object.entries(l).map(([k, v]) => [k, v.length])); })()")


def click_group(parent):
    js("""(() => { const g = view.drawn_nodes.find(d => d.type === 'group' && String(d.parent) === %s); const t = view.zoom_transform;
        view.canvas.call(view.zoom.transform, d3.zoomIdentity.translate(1500 / 2 - g.x * t.k, 900 / 2 - g.y * t.k).scale(t.k)); })()""" % json.dumps(parent))
    P.next_frames(js)
    P.idle(js)
    pos = js("""(() => { const el = Array.from(document.querySelectorAll('#topology-canvas g.topology-node.group')).find(g => String(g.__data__.parent) === %s);
        const b = el.getBoundingClientRect(); return {x: b.left + b.width / 2, y: b.top + Math.min(b.height / 2, 17)}; })()""" % json.dumps(parent))
    # the wrappers and the observer are made once per page; the counters are set to zero before every click
    js("""(() => { if (!window.__instrumented) { window.__instrumented = true; window.__long = []; window.__renders = 0; window.__unfolds = 0;
        new PerformanceObserver(l => l.getEntries().forEach(e => window.__long.push(Math.round(e.duration)))).observe({entryTypes: ['longtask']});
        const r = view.render.bind(view); view.render = function (...a) { window.__renders++; return r(...a); };
        const u = view.unfoldGroup.bind(view); view.unfoldGroup = function (...a) { window.__unfolds++; return u(...a); }; }
        window.__long.length = 0; window.__renders = 0; window.__unfolds = 0;
        document.addEventListener('mousedown', () => { window.__t0 = performance.now(); }, {capture: true, once: true}); })()""")
    before = js('view.drawn_nodes.length')
    for kind, button in (('mouseMoved', 'none'), ('mousePressed', 'left'), ('mouseReleased', 'left')):
        ws.call('Input.dispatchMouseEvent', type=kind, x=pos['x'], y=pos['y'], button=button, buttons=1 if kind == 'mousePressed' else 0, clickCount=1)
    elapsed = js("""new Promise(resolve => { const step = () => { if (view.unfolded.has(%s) && view.idle) resolve(Math.round(performance.now() - window.__t0)); else requestAnimationFrame(step); }; step(); })""" % json.dumps(parent))
    P.next_frames(js)
    info = js('({long: window.__long, renders: window.__renders, unfolds: window.__unfolds, nodes: view.drawn_nodes.length})')
    return elapsed, (max(info['long']) if info['long'] else 0), info, before


try:
    js("document.getElementById('topology-legend').style.display = 'none'")
    print('load average %.2f: %s' % (load, 'the limits are checked' if quiet else 'the VM is loaded (4 or more): the numbers are notes, not failures'))
    for state, presses in (('b: after Reset', 1), ('c: after Reset three times in a row', 3)):
        print('=== %s ===' % state)
        apply_focus()
        for _ in range(presses):
            reset()
        groups = js("(view.leaf_groups || []).map(g => [String(g.parent), g.devices.length, (Array.from(view.nodes.values()).find(n => String(n.id) === String(g.parent)) || {}).label])")
        check(len(groups) >= 1, '%d leaf group(s) in the view' % len(groups), groups)
        first = listeners()
        for parent, leaves, label in groups:
            apply_focus()
            for _ in range(presses):
                reset()
            check(listeners() == first, 'Reset added no listener on a group node: %s' % json.dumps(first), listeners())
            elapsed, longest, info, before = click_group(parent)
            check(info['unfolds'] == 1 and info['renders'] == 1, '%s (%d leaves): one click ran unfoldGroup %d time(s) and render %d time(s)' % (label, leaves, info['unfolds'], info['renders']), info)
            check(info['nodes'] == before + leaves - 1, '%s: the %d leaves are drawn in place of the group node (%d -> %d nodes)' % (label, leaves, before, info['nodes']), info)
            text = '%s (%d leaves): unfold %d ms (limit %d), longest task %d ms (limit %d)' % (label, leaves, elapsed, LIMIT_MS, longest, LIMIT_TASK)
            if quiet:
                check(elapsed <= LIMIT_MS and longest <= LIMIT_TASK, text, {'elapsed': elapsed, 'longest': longest, 'tasks': info['long']})
            else:
                note(text)
finally:
    chrome.close()
print('\nAll unfold time checks passed.' if failed == 0 else '\n%d unfold time check(s) FAILED.' % failed)
sys.exit(1 if failed else 0)
