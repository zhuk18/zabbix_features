#!/usr/bin/env python3
"""task 130 part 3 (lab 2.41, model §8.5, FR-C V-13): D1 step 8, on the demo stand: SW16 port 48 - SW18 port 44; the CDP row of SW16 names port 40 of SW18. Run it with the stand up at step 8:
  cd database/topology/lab && ZBX_TOKEN=... python -m labsim --lab stands/office-demo/lab-office-demo.yaml show D1 --step 8 --reset
then  ZBX_UI_USER=... ZBX_UI_PASSWORD=... python3 ui_check_040/d1_ports_differ_check.py   (not in the harness: the harness runs on R1, where LLDP and CDP agree).
Checks, on the page of the focus host SW16 (1 hop), real mouse events: the link SW16 48 - SW18 44 is one line (port-level, nothing superseded) and carries ports_differ; the hint box (tooltip) of the line and the link
panel ("Link details" of the menu) name both ports; the line has no new mark. Screenshot 130-d1-step8.png when SHOTS_DIR is set. Exit code 1 when a check fails."""
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
host = os.popen("mysql -N -uzabbix zabbix -e \"select hostid from hosts where host='SW16-09-ACC-2530-48G'\"").read().strip()
chrome, ws, js = P.open_page(cookie, 'd1ports', 9890, action='topology.view&hostid=%s&hops=1' % host)
WANT = 'LLDP and CDP report different remote ports: 44 and 40.'
LINK = "view.line_entries.find(e => e.link && e.link.type === 'physical_link' && [e.link.source.label, e.link.target.label].every(l => l.startsWith('SW16') || l.startsWith('SW18')) && e.menu)"


def hover(x, y):
    ws.call('Input.dispatchMouseEvent', type='mouseMoved', x=x, y=y, button='none', buttons=0)


try:
    P.wait_state(ws, js, "Array.from(view.nodes.values()).some(n => n.label.startsWith('SW18'))", 'the neighbors are in the view')
    js("view.panToNode(String(Array.from(view.nodes.values()).find(n => n.label.startsWith('SW16')).id))")
    P.idle(js)
    links = js("view.drawn_links.filter(l => l.type === 'physical_link' && [l.source.label, l.target.label].some(x => x.startsWith('SW18')) && [l.source.label, l.target.label].some(x => x.startsWith('SW16'))).map(l => ({ports: [l.source_port, l.target_port], differ: l.ports_differ, superseded: l.superseded_at, marks: 0}))")
    check(len(links) == 1 and sorted(links[0]['ports']) == ['44', '48'] and links[0]['differ'] == {'lldp': '44', 'cdp': '40'} and not links[0]['superseded'],
          'one link SW16 48 - SW18 44, active, with ports_differ {lldp: 44, cdp: 40}', links)
    check(js("document.querySelectorAll('#topology-canvas g.topology-speed-mark').length") == 0, 'no new mark on a line (the mark is in the tooltip and the panel only)')
    # the line of the demo is short (the two nodes are close): the point of the line that the pointer hits is the one where the hit path itself is on top (not a node box or a label)
    point = js("""(() => { const e = %s; const len = e.hit.getTotalLength(); const ctm = e.hit.getScreenCTM();
        for (const u of [0.5, 0.45, 0.55, 0.4, 0.6, 0.35, 0.65, 0.3, 0.7, 0.25, 0.75]) { const p = e.hit.getPointAtLength(len * u); const t = new DOMPoint(p.x, p.y).matrixTransform(ctm);
            if (document.elementFromPoint(t.x, t.y) === e.hit) return {x: t.x, y: t.y, u}; }
        return null; })()""" % LINK)
    check(point is not None, 'a point of the line can be hit', point)
    hover(point['x'] - 3, point['y'] - 3)
    hover(point['x'], point['y'])
    P.until(js, "document.querySelector('.hintbox')", 'the hint box of the line is shown')
    hint = js("document.querySelector('.hintbox').textContent.replace(/\\s+/g, ' ')")
    check(WANT in hint, 'the tooltip names both ports: "%s"' % WANT, hint)
    hover(5, 5)
    P.until(js, "!document.querySelector('.hintbox')", 'the hint box is closed')
    P.line_menu(js, LINK, choose='Link details')  # (a click at the middle of the line; the same hit test as above)
    panel = js("document.getElementById('topology-details').textContent.replace(/\\s+/g, ' ')")
    check(WANT in panel, 'the link panel names both ports', panel[:400])
    cut = js("Array.from(document.querySelectorAll('#topology-details td, #topology-details th')).filter(e => e.scrollWidth > e.clientWidth + 1).map(e => e.textContent)")
    check(cut == [], 'no row of the link panel is cut', cut)
    folder = os.environ.get('SHOTS_DIR')
    if folder:
        hover(5, 5)
        P.until(js, "!document.querySelector('.hintbox') && !document.querySelector('.menu-popup')", 'no hint box and no menu over the graph')
        with open(os.path.join(folder, '130-d1-step8.png'), 'wb') as f:
            f.write(base64.b64decode(ws.call('Page.captureScreenshot', format='png')['data']))
finally:
    chrome.close()
print('All D1 step 8 checks passed.' if not failed else '%d check(s) FAILED.' % failed)
sys.exit(1 if failed else 0)
