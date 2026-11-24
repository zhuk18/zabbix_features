#!/usr/bin/env python3
"""task 120 section 2: the warning mark on the real data of the demo stand D1, step 7 (SW12 1/1/41 - SW15 1/1/21: SW15 reports 100 Mbit/s, SW12 1000, both up; model 2.101 §12.3).
Run it with the stand up at step 7:   cd database/topology/lab && ZBX_TOKEN=... python -m labsim --lab stands/office-demo/lab-office-demo.yaml show D1 --step 7 --reset
then   ZBX_UI_USER=... ZBX_UI_PASSWORD=... python3 ui_check_040/d1_mark_check.py   (not in the harness: the harness runs on R1, where no link has a mismatch).
Checks, on the page of the focus host SW12 (1 hop), real mouse events: the mark is on the line of that link and on no other, with no hover; the tooltip, the link panel and the Links row of SW12 have the mark
(the row the short one, with both speeds in its hint box). Screenshot 121-d1.png when SHOTS_DIR is set (task 120 made 120-d1.png). Exit code 1 when a check fails."""
import base64, json, os, sys
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import page_shot as P

if not os.environ.get('ZBX_UI_USER') or not os.environ.get('ZBX_UI_PASSWORD'):
    print('ZBX_UI_USER and ZBX_UI_PASSWORD are needed')
    sys.exit(2)
failed = 0


def check(condition, text, detail=None):
    global failed
    print(('  ok: ' if condition else '  FAIL: ') + text + ('' if condition else ' ' + json.dumps(detail)))
    failed += 0 if condition else 1


cookie = P.login()
host = os.popen("mysql -N -uzabbix zabbix -e \"select hostid from hosts where host='SW12-10-CORE-6000-48G'\"").read().strip()
chrome, ws, js = P.open_page(cookie, 'd1mark', 9880, action='topology.view&hostid=%s&hops=1' % host)
A, B = 'SW12-10-CORE-6000-48G', 'SW15-10-POE-6000-24G'


def hover(x, y):
    ws.call('Input.dispatchMouseEvent', type='mouseMoved', x=x, y=y, button='none', buttons=0)


try:
    P.wait_state(ws, js, "Array.from(view.nodes.values()).some(n => n.label.startsWith('%s'))" % B[:4], 'the neighbors are in the view')
    mm = js("view.speedMismatchText(1000000000, 100000000)")
    js("view.panToNode(String(Array.from(view.nodes.values()).find(n => n.label.startsWith('SW12')).id))")
    P.idle(js)
    marks = js("""Array.from(document.querySelectorAll('#topology-canvas g.topology-speed-mark')).map(g => { const b = g.getBoundingClientRect(); const l = g.__data__;
        return {x: b.left + b.width / 2, y: b.top + b.height / 2, source: l.source.label, target: l.target.label, type: l.type}; })""")
    check(len(marks) == 1 and {marks[0]['source'][:4], marks[0]['target'][:4]} == {'SW12', 'SW15'}, 'one mark on the page, on the line SW12 - SW15 (and on no other line)', marks)
    r = js(P.CHECKS['overlap'])
    check(r['speed_marks'] == 1 and not r['mark_overlaps'] and not r['mark_places'] and r['overlaps'] == 0, 'the mark touches no node box, label or mark', {k: r[k] for k in ('speed_marks', 'mark_overlaps', 'mark_places', 'overlaps')})
    m = marks[0]
    fill = js("getComputedStyle(document.querySelector('#topology-canvas g.topology-speed-mark path')).fill")
    check(fill == 'rgb(55, 65, 81)', 'the mark is dark grey (task 121), not a severity colour', fill)
    hover(5, 5)
    hover(m['x'] - 2, m['y'] - 2)
    hover(m['x'], m['y'])
    P.until(js, "document.querySelector('.hintbox')", 'the hint box of the mark')
    hint = js("document.querySelector('.hintbox').textContent")
    check(mm in hint and '1/1/41' in hint and '1/1/21' in hint, 'the tooltip has both ports and the mark "%s"' % mm, hint)
    hover(5, 5)
    ws.call('Input.dispatchMouseEvent', type='mousePressed', x=m['x'], y=m['y'], button='left', buttons=1, clickCount=1)
    ws.call('Input.dispatchMouseEvent', type='mouseReleased', x=m['x'], y=m['y'], button='left', buttons=0, clickCount=1)
    menu = P._menu_choose(js, None)
    check('Link details' in menu, 'a click on the mark opens the line menu (task 121)', menu)
    P._menu_choose(js, 'Link details')
    panel = js("document.getElementById('topology-details').textContent")
    check(mm in panel and '1/1/41' in panel, '"Link details" of the menu opens the link panel with the mark', panel[:300])
    cut = js("Array.from(document.querySelectorAll('#topology-details td, #topology-details th')).filter(e => e.scrollWidth > e.clientWidth + 1).map(e => e.textContent)")
    check(cut == [], 'no row of the link panel is cut', cut)
    js("(async () => { const n = Array.from(view.nodes.values()).find(n => n.label.startsWith('SW12')); await view.selectNode(n); return 1; })()")
    P.idle(js)
    rows = js("""Array.from(document.querySelectorAll('#topology-details .topology-link-row')).map(r => { const m = r.querySelector('.topology-link-mark');
        return {text: r.textContent.replace(/\\s+/g, ' ').trim(), mark: m ? m.textContent : null, hint: m ? (m.getAttribute('title') || m.dataset.hintboxHtml || '') : null}; })""")
    bad = [r for r in rows if r['mark']]
    check(len(bad) == 1 and bad[0]['mark'] == 'speed mismatch' and '1/1/41' in bad[0]['text'] and mm in bad[0]['hint'] and mm not in bad[0]['text'],
          'the Links row of SW12: the short mark on the row of port 1/1/41 only, both speeds in its hint box', bad)
    folder = os.environ.get('SHOTS_DIR')
    if folder:
        # task 121: SW12 in the middle: its link to SW13 (1/1/42) is down since step 1 and has a severity colour, next to the mark (an orange node or line near the mark)
        js("view.panToNode(String(Array.from(view.nodes.values()).find(n => n.label.startsWith('SW12')).id))")
        P.idle(js)
        hover(5, 5)
        P.until(js, "!document.querySelector('.hintbox') && !document.querySelector('.menu-popup')", 'no hint box and no menu over the graph')
        with open(os.path.join(folder, '121-d1.png'), 'wb') as f:
            f.write(base64.b64decode(ws.call('Page.captureScreenshot', format='png')['data']))
finally:
    chrome.close()
print('All D1 mark checks passed.' if not failed else '%d check(s) FAILED.' % failed)
sys.exit(1 if failed else 0)
