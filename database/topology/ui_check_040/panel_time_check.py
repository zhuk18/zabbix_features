#!/usr/bin/env python3
"""task 102 §2 (model §11 `topology.ports.get`, 2.87): the time budget of the node panel: the panel of SW12 (58 ports, 11 links) is complete within 300 ms on the quiet VM (the check fails above it) and the goal is 200 ms (model §11, 2.89; task 105 §4: the median and the goal are printed). Five clicks on the node
in the graph (each after a click on SW13, so that the panel really changes), measured in the page from the click to the moment the panel of that node is registered (its last section is drawn);
the median is checked. On a loaded VM (the 1-minute load average of the VM is 4 or more; the stand and the server alone give 2 to 3.5) the numbers are printed as a note and do not fail the check. Needs ZBX_UI_USER, ZBX_UI_PASSWORD,
google-chrome and the office stand data. Exit code 1 when a check fails.
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


def session():
    chrome, ws, js = P.open_page(cookie, 'panel', 9700)
    return chrome, ws, js


def mouse(ws, x, y):
    ws.call('Input.dispatchMouseEvent', type='mouseMoved', x=x, y=y, button='none', buttons=0)


def select(js, label, kind=None):
    js("""(async () => { const n = Array.from(view.nodes.values()).find(n => n.label === %s && (%s)); await view.selectNode(n); return 1; })()""" % (json.dumps(label), ("n.type === '%s'" % kind) if kind else 'true'))
    P.after_input(js)



FAIL_MS = 300      # the check fails above it (model 2.89 §11; 103 Q1)
GOAL_MS = 200      # the goal: printed, not a failure
chrome, ws, js = session()
try:
    P.after_input(js)
    load1 = float(open('/proc/loadavg').read().split()[0])
    quiet = load1 < 4

    def click_and_time(label):
        return js("""(async label => {
            const node = view.drawn_nodes.find(n => String(n.label).startsWith(label));
            const key = 'node:' + node.id;
            const g = Array.from(document.querySelectorAll('#topology-canvas g.topology-node')).find(e => String(e.__data__.label).startsWith(label));
            const t0 = performance.now();
            g.querySelector('rect.topology-shape').dispatchEvent(new MouseEvent('click', {bubbles: true}));
            await new Promise(resolve => { const tick = () => { if (view.current_panel && view.current_panel.key === key) { resolve(); } else { requestAnimationFrame(tick); } }; tick(); });
            return performance.now() - t0;
        })(%s)""" % json.dumps(label))

    # one click on each, not timed (the first requests of the page and of the PHP workers are slower than the ones after them)
    click_and_time('SW13')
    click_and_time('SW12')
    times = []
    for _ in range(5):
        click_and_time('SW13')
        times.append(click_and_time('SW12'))
    median = sorted(times)[len(times) // 2]
    text = 'the panel of SW12 is complete in %.0f ms (median of 5 clicks; %s; goal %d ms, the check fails above %d ms; load average %.1f)' % (median, ', '.join('%.0f' % t for t in times), GOAL_MS, FAIL_MS, load1)
    print('  median %.0f ms, goal %d ms (%s)' % (median, GOAL_MS, 'goal met' if median <= GOAL_MS else 'goal not met'))
    if quiet:
        check(median <= FAIL_MS, text, times)
    else:
        print('  note: ' + text + ' - the VM is loaded, so this is not a failure')
finally:
    chrome.kill()
print('\n%s' % ('%d check(s) FAILED' % failed if failed else 'The panel time check passed.'))
sys.exit(1 if failed else 0)
