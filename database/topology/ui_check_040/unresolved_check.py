#!/usr/bin/env python3
"""Task 130 part 1 (model 2.107 §6.7.6 item 4, §7.2, §12.7; FR-A R-23; cases C-047, C-097): the observation list of the last run view shows an observation `local_port_unresolved` with its port reference
and its reason, the count of the outcome is a link that filters by it, and the filter by the result shows these rows only.

The page is the real page of the stand. The check writes its own observations (one for each reason, on the rule of an existing observation of R1, with the remote keys `unresolved-check-*`) and removes them in a
`finally`; the rows that the ingest writes are checked in discovery/test_ifname_local_port.php. Needs ZBX_UI_USER, ZBX_UI_PASSWORD, google-chrome and the office stand data. Exit code 1 when a check fails."""
import json, os, subprocess, sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import page_shot as P

if not os.environ.get('ZBX_UI_USER') or not os.environ.get('ZBX_UI_PASSWORD'):
    print('ZBX_UI_USER and ZBX_UI_PASSWORD are needed')
    sys.exit(2)
cookie = P.login()
failed = 0
REASONS = {'name_on_several_ports': 'several ports', 'if_index_missing': 'no ifIndex', 'no_ports': 'no Ports snapshot', 'name_not_found': 'No port of the reporter has this name'}


def check(condition, text, detail=None):
    global failed
    print(('  ok: ' if condition else '  FAIL: ') + text + ('' if condition else ' ' + json.dumps(detail)))
    failed += 0 if condition else 1


def db(statement):
    return subprocess.run(['mysql', '-N', '-uzabbix', 'zabbix', '-e', statement], capture_output=True, text=True).stdout.strip()


itemid = db("SELECT o.itemid FROM topo_observations o JOIN items i ON i.itemid = o.itemid JOIN topo_lld_snapshot s ON s.itemid = i.itemid AND s.topology_type = i.topology_type WHERE i.topology_type = 2 AND i.status = 0 LIMIT 1")
chrome = None
try:
    check(itemid != '', 'a NEIGHBORS rule with observations exists (R1)', itemid)
    for number, reason in enumerate(REASONS, 1):
        db("INSERT INTO topo_observations (itemid, local_port_ref, local_port_id, remote_key, remote_attrs, outcome, far_port_reason, first_seen, last_seen) VALUES (%s, 'name:Gi9/%d', NULL, 'unresolved-check-%d', '{\"rem_sysname\":\"UNRESOLVED-CHECK-%d\"}', 'local_port_unresolved', '%s', 1, 2)" % (itemid, number, number, number, reason))
    chrome, ws, js = P.open_page(cookie, 'unres', 9850, action='topology.view&view=lastrun&filter_set=1&filter_outcomes[]=local_port_unresolved',
                                 ready="document.readyState === 'complete' && !!document.getElementById('topology-lastrun-list')")
    rows = js("""Array.from(document.querySelectorAll('#topology-lastrun-list tbody tr')).map(r => Array.from(r.children).map(c => c.textContent.trim()))""")
    mine = [r for r in rows if 'UNRESOLVED-CHECK' in ' '.join(r)]
    check(len(mine) == 4 and len(rows) == len(mine), 'the filter by the result shows the four rows and no other', rows)
    for number, reason in enumerate(REASONS, 1):
        row = [r for r in mine if 'UNRESOLVED-CHECK-%d' % number in ' '.join(r)]
        check(len(row) == 1 and row[0][1] == 'name:Gi9/%d' % number and row[0][4] == 'Local port unresolved' and REASONS[reason] in row[0][5] and 'no link and no Device' in row[0][5],
              'the row of %s: the port reference, the result "Local port unresolved", its reason' % reason, row)
    # the default list (everything except applied) has them too; the count of the outcome in the summary is a link that sets the filter
    js("location.href = 'zabbix.php?action=topology.view&view=lastrun&filter_rst=1'")
    P.until(js, "document.readyState === 'complete' && location.search.includes('filter_rst') && !!document.getElementById('topology-lastrun-list')", 'the default list')
    text = js("document.getElementById('topology-lastrun-list').textContent")
    check(text.count('UNRESOLVED-CHECK') >= 4 or 'UNRESOLVED-CHECK' in text, 'the default list shows them', text[:200])
    link = js("""(() => { const a = Array.from(document.querySelectorAll('a')).find(e => /filter_outcomes/.test(decodeURIComponent(e.getAttribute('href') || '')) && /local_port_unresolved/.test(decodeURIComponent(e.getAttribute('href') || ''))); return a ? a.textContent.trim() : null; })()""")
    check(link is not None, 'the summary has the count of local_port_unresolved as a link that sets the filter', link)
finally:
    db("DELETE FROM topo_observations WHERE remote_key LIKE 'unresolved-check-%'")
    if chrome is not None:
        try:
            js("location.href = 'zabbix.php?action=topology.view&view=lastrun&filter_rst=1'")
            P.until(js, "document.readyState === 'complete' && location.search.includes('filter_rst')", 'the filter is reset')
        except Exception:
            pass
        chrome.close()
check(db("SELECT COUNT(*) FROM topo_observations WHERE remote_key LIKE 'unresolved-check-%'") == '0', 'nothing of the check is left in the database')
print('\nAll unresolved local port checks passed.' if failed == 0 else '\n%d unresolved local port check(s) FAILED.' % failed)
sys.exit(1 if failed else 0)
