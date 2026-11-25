#!/usr/bin/env python3
"""Task 122 section 2 (topology-1-model.md §12.4, 2.103): a click on the "!" badge of a contradicted link acts as a click on the line: the line menu; "Link details" of the menu opens the link panel.

The page is the real page of the office stand. A contradiction needs a manual link with an unacknowledged shadow observation; the check does not write one to the database: it gives the page a contradiction
entry for one real link (view.contradictions, in this page only, nothing is stored) and draws again, then clicks the badge with a real mouse event. Needs ZBX_UI_USER, ZBX_UI_PASSWORD, google-chrome.
Exit code 1 when a check fails."""
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


chrome, ws, js = P.open_page(cookie, 'badge', 9700)
try:
    js("document.getElementById('topology-legend').style.display = 'none'")
    js("view.unfolded.clear(); view.render()")
    P.idle(js)
    LINK = "view.line_entries.find(e => e.link && e.link.type === 'physical_link' && e.link.edge_id !== null && e.link.edge_id !== undefined && e.menu && !e.link.source_lag_id && !e.link.target_lag_id)"
    edge = js("(() => { const e = %s; return e ? String(e.link.edge_id) : null; })()" % LINK)
    check(edge is not None, 'the page has a link line with an edge id and a menu', edge)
    js("""(() => { view.contradictions = [{edge_id: %s, contradicted: true, shadows: [{acknowledged: false, first_seen: 1700000000, hidden_device_id: 1, reporter: 'X', neighbor: 'Y', rem_port: 'p'}]}]; view.render(); })()""" % json.dumps(edge))
    P.idle(js)
    count = js("document.querySelectorAll('#topology-canvas g.topology-contradiction-badge').length")
    check(count == 1, 'one "!" badge is drawn on the contradicted link', count)
    pos = js("""(() => { const b = document.querySelector('#topology-canvas g.topology-contradiction-badge').getBoundingClientRect(); return {x: b.left + b.width / 2, y: b.top + b.height / 2}; })()""")
    # a real mouse click on the badge
    ws.call('Input.dispatchMouseEvent', type='mouseMoved', x=pos['x'], y=pos['y'], button='none', buttons=0)
    ws.call('Input.dispatchMouseEvent', type='mousePressed', x=pos['x'], y=pos['y'], button='left', buttons=1, clickCount=1)
    ws.call('Input.dispatchMouseEvent', type='mouseReleased', x=pos['x'], y=pos['y'], button='left', buttons=0, clickCount=1)
    P.until(js, "document.querySelector('.menu-popup-item')", 'the line menu is open')
    text = js("document.querySelector('.menu-popup').textContent.replace(/\\s+/g, ' ').trim()")
    check('Link details' in text, 'a click on the "!" badge opens the line menu (it has "Link details")', text)
    check(js("document.getElementById('topology-details-title').textContent") != 'Link details', 'the click did not open the link panel at once')
    js("Array.from(document.querySelectorAll('.menu-popup-item')).find(e => e.textContent.trim() === 'Link details').click()")
    P.idle(js)
    check(js("document.getElementById('topology-details-title').textContent") == 'Link details' and js("!!document.querySelector('#topology-details h2')"), '"Link details" of the menu opens the link panel')
    shots = os.environ.get('SHOTS_DIR')
    if shots:
        js("document.body.click()")
        P.until(js, "!document.querySelector('.menu-popup')", 'the panel stays, the menu is closed')
        ws.call('Input.dispatchMouseEvent', type='mousePressed', x=pos['x'], y=pos['y'], button='left', buttons=1, clickCount=1)
        ws.call('Input.dispatchMouseEvent', type='mouseReleased', x=pos['x'], y=pos['y'], button='left', buttons=0, clickCount=1)
        P.until(js, "document.querySelector('.menu-popup-item')", 'the line menu is open (for the screenshot)')
        open(os.path.join(shots, '122-badge-menu.png'), 'wb').write(__import__('base64').b64decode(ws.call('Page.captureScreenshot', format='png')['data']))
finally:
    chrome.close()
print('All badge checks passed.' if failed == 0 else '%d badge checks FAILED.' % failed)
sys.exit(1 if failed else 0)
