#!/usr/bin/env python3
"""task 101 §3 (model §12.1 "Deterministic layout", 2.85): the first layout of the page is the same at every load. The page with nothing selected is loaded 10 times at 1500 x 900 and 10 times at 1280 x 800
(a new Chrome for every load, so that nothing is kept); the positions of the drawn nodes are compared: the largest difference of one node between two loads is reported and must be at most 1 px. The
overlap check (with the lines through a node) must pass at every load. Needs ZBX_UI_USER, ZBX_UI_PASSWORD, google-chrome and the office stand data. Exit code 1 when a check fails.
    python3 determinism_check.py [--loads 10]
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



import argparse
ap = argparse.ArgumentParser()
ap.add_argument('--loads', type=int, default=10)
ap.add_argument('--no-menu', action='store_true')
ap.add_argument('--sizes', default='1500,1280')
a = ap.parse_args()


def load(width, height, menu):
    chrome, ws, js = P.open_page(cookie, 'det', 9700, width, height)
    try:
        if not a.no_menu:
            js("(() => { const s = document.querySelector('.sidebar'); s.classList.toggle('is-compact', %s); s.classList.remove('is-hidden'); window.dispatchEvent(new Event('resize')); })()" % ('true' if menu == 'collapsed' else 'false'))
        P.next_frames(js)      # the resize is handled in the page's next frames; the layout is one synchronous pass
        P.settled(ws, js)
        positions = js("Object.fromEntries(view.drawn_nodes.map(n => [String(n.id), [n.x, n.y]]))")
        positions['_data'] = js("JSON.stringify([Array.from(view.nodes.keys()), view.links.map(l => [l.source, l.target, l.type, l.edge_id, l.stale, l.superseded_at])])")
        positions['_size'] = js("[Math.round(view.view_size.width), Math.round(view.view_size.height), document.getElementById('topology-canvas').getBoundingClientRect().width]")
        result = js(P.CHECKS['overlap'])
        return positions, result
    finally:
        chrome.close()


for width, height, menu in [x for x in ((1500, 900, 'open'), (1280, 800, 'collapsed')) if str(x[0]) in a.sizes.split(',')]:
    print('=== %dx%d, %d loads ===' % (width, height, a.loads))
    runs = [load(width, height, menu) for _ in range(a.loads)]
    print('  sizes:', [r[0]['_size'] for r in runs])
    import hashlib as _h
    print('  data:', [_h.md5(r[0]['_data'].encode()).hexdigest()[:6] for r in runs])
    for r in runs:
        r[0].pop('_size')
        r[0].pop('_data')
    base = runs[0][0]
    worst = 0.0
    for positions, _ in runs[1:]:
        if set(positions) != set(base):
            worst = float('inf')
            continue
        worst = max(worst, max(max(abs(positions[k][0] - base[k][0]), abs(positions[k][1] - base[k][1])) for k in base))
    import hashlib
    print('  layouts:', [hashlib.md5(json.dumps({k: [round(v[0]), round(v[1])] for k, v in sorted(r[0].items())}).encode()).hexdigest()[:6] for r in runs])
    if worst > 1:
        far = runs[[i for i, r in enumerate(runs) if max(max(abs(r[0][k][0] - base[k][0]), abs(r[0][k][1] - base[k][1])) for k in base) > 1][0]][0]
        print('  moved nodes:', [(k, [round(x) for x in base[k]], [round(x) for x in far[k]]) for k in base if max(abs(far[k][0] - base[k][0]), abs(far[k][1] - base[k][1])) > 1][:8])
    check(worst <= 1.0, 'the node positions of %d loads are equal within 1 px (the largest difference: %s px)' % (a.loads, 'a different set of nodes' if worst == float('inf') else round(worst, 3)), worst)
    fails = [r for _, r in runs if r['lines_through_nodes'] or r['overlaps'] or r['bundle_label_overlaps']]
    check(not fails, 'the overlap check, with the lines through a node, passes at every load (%d of %d passed)' % (a.loads - len(fails), a.loads), [r['lines_through_nodes'] for r in fails][:2])

print('\n%s' % ('%d check(s) FAILED' % failed if failed else 'All layout determinism checks passed.'))
sys.exit(1 if failed else 0)
