#!/usr/bin/env python3
"""task 112 §2 (model 2.95 §12.1 "Selection after Apply"): with a focus host, Apply selects the focus host and opens its panel; without a focus host, Apply selects nothing and closes the panel;
the first load of the page with an empty filter selects nothing. Three runs of page_shot.py on the real page (it sets the filter and presses Apply):
  1. empty filter, first load, no Apply: no panel, no selected node;  2. a host group, no focus host, Apply: no panel;  3. a focus host, Apply: the panel of the focus host.
A fourth run applies a focus host first and then Apply with the filter emptied by the page (Reset is a different button): the panel is closed.
Needs ZBX_UI_USER, ZBX_UI_PASSWORD and the office stand data. Exit code 1 when a check fails, 2 without the environment."""
import json, os, subprocess, sys
HERE = os.path.dirname(os.path.abspath(__file__))
if not os.environ.get('ZBX_UI_USER') or not os.environ.get('ZBX_UI_PASSWORD'):
    print('ZBX_UI_USER and ZBX_UI_PASSWORD are needed')
    sys.exit(2)
STATE = '''(() => ({panel: document.querySelector('#topology-details .topology-node-head') !== null, text: document.getElementById('topology-details').textContent.trim().length,
    title: (document.querySelector('#topology-details .topology-node-name') || {}).textContent || '', selected: !!view.selection, node: view.selected_node ? view.selected_node.label : null}))()'''
failed = 0


def check(condition, text, detail=None):
    global failed
    print(('  ok: ' if condition else '  FAIL: ') + text + ('' if condition else ' ' + json.dumps(detail)))
    failed += 0 if condition else 1


def run(*args, before_filter=None):
    out = subprocess.run([sys.executable, HERE + '/page_shot.py', '--width', '1500', '--height', '900', '--menu', 'open', '--settle', '3', '--eval', before_filter or STATE, '--out', '/tmp/selection.png', *args],
                         capture_output=True, text=True, timeout=180).stdout
    line = next((l for l in out.split('\n') if l.startswith('eval:')), None)
    if not line:
        print('page_shot output:', out[-400:])
    return json.loads(line.split(':', 1)[1]) if line else None


# 1. the first load of the page with an empty filter: nothing is selected
state = run('--no-filter')
check(state is not None and not state['panel'] and state['text'] == 0 and not state['selected'], 'the first load with an empty filter: nothing selected, no panel', state)
# 2. a group, no focus host, Apply: nothing is selected, the panel is closed
state = run('--group', 'Zabbix servers', '--depth', '1')
check(state is not None and not state['panel'] and state['text'] == 0 and not state['selected'], 'Apply without a focus host: nothing selected, no panel', state)
# 3. a focus host, Apply: the panel of the focus host is open
state = run('--host', 'SW13-10-ACC-6000-48G', '--depth', '1')
check(state is not None and state['panel'] and state['title'].startswith('SW13-10-ACC-6000-48G'), 'Apply with a focus host: the panel of the focus host is open', state)
# 4. the panel is open, the focus host is removed from the filter, Apply: the panel is closed
again = '(async () => { jQuery("#hostid").multiSelect("clean"); document.getElementById("topology-filter-apply").click(); await new Promise(r => setTimeout(r, 6000)); return %s; })()' % STATE
state = run('--host', 'SW13-10-ACC-6000-48G', '--depth', '1', before_filter=again)
check(state is not None and not state['panel'] and state['text'] == 0 and not state['selected'], 'the panel was open; Apply without the focus host closes it', state)
print('\n%s' % ('%d check(s) FAILED' % failed if failed else 'All checks passed.'))
sys.exit(1 if failed else 0)
