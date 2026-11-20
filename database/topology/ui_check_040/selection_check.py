#!/usr/bin/env python3
"""task 112 §2 (model 2.95 §12.1 "Selection after Apply"; 113 §4: model 2.96 §12.1 "Empty panel" and "Reload after an action"): with a focus host, Apply selects the focus host and opens its panel; without a focus host, Apply selects nothing and closes the panel;
the first load of the page with an empty filter selects nothing. Three runs of page_shot.py on the real page (it sets the filter and presses Apply):
  1. empty filter, first load, no Apply: no panel, no selected node;  2. a host group, no focus host, Apply: no panel;  3. a focus host, Apply: the panel of the focus host.
Since 113: an empty panel shows the hint of the first load (not the title alone); a reload after an action (the view answer is changed by a stub of the request) makes the open panel read its object
again, and closes the panel when its object (a Device, a link) is gone: cases 5-8.
A fourth run applies a focus host first and then Apply with the filter emptied by the page (Reset is a different button): the panel is closed.
Needs ZBX_UI_USER, ZBX_UI_PASSWORD and the office stand data. Exit code 1 when a check fails, 2 without the environment."""
import json, os, subprocess, sys
HERE = os.path.dirname(os.path.abspath(__file__))
if not os.environ.get('ZBX_UI_USER') or not os.environ.get('ZBX_UI_PASSWORD'):
    print('ZBX_UI_USER and ZBX_UI_PASSWORD are needed')
    sys.exit(2)
STATE = '''(() => ({panel: document.querySelector('#topology-details .topology-node-head') !== null, text: document.getElementById('topology-details').textContent.trim().length,
    hint: document.getElementById('topology-details').textContent.includes('Select a device'), title_shown: !document.getElementById('topology-details-title').hidden,
    title: (document.querySelector('#topology-details .topology-node-name') || {}).textContent || '', selected: !!view.selection, node: view.selected_node ? view.selected_node.label : null}))()'''
failed = 0


def check(condition, text, detail=None):
    global failed
    print(('  ok: ' if condition else '  FAIL: ') + text + ('' if condition else ' ' + json.dumps(detail)))
    failed += 0 if condition else 1


def run(*args, before_filter=None):
    out = subprocess.run([sys.executable, HERE + '/page_shot.py', '--width', '1500', '--height', '900', '--menu', 'open', '--eval', before_filter or STATE, '--out', '/tmp/selection.png', *args],
                         capture_output=True, text=True, timeout=180).stdout
    line = next((l for l in out.split('\n') if l.startswith('eval:')), None)
    if not line:
        print('page_shot output:', out[-400:])
    return json.loads(line.split(':', 1)[1]) if line else None


# 1. the first load of the page with an empty filter: nothing is selected
state = run('--no-filter')
check(state is not None and not state['panel'] and state['hint'] and not state['selected'], 'the first load with an empty filter: nothing selected, no panel', state)
# 2. a group, no focus host, Apply: nothing is selected, the panel is closed
state = run('--group', 'Zabbix servers', '--depth', '1')
check(state is not None and not state['panel'] and state['hint'] and not state['selected'], 'Apply without a focus host: nothing selected, no panel', state)
# 3. a focus host, Apply: the panel of the focus host is open
state = run('--host', 'SW13-10-ACC-6000-48G', '--depth', '1')
check(state is not None and state['panel'] and state['title'].startswith('SW13-10-ACC-6000-48G'), 'Apply with a focus host: the panel of the focus host is open', state)
# 4. the panel is open, the focus host is removed from the filter, Apply: the panel is closed
again = '(async () => { jQuery("#hostid").multiSelect("clean"); const loads = view.loads_done; document.getElementById("topology-filter-apply").click(); await window.__topo_wait(() => view.loads_done > loads && view.idle, "the load after Apply"); return %s; })()' % STATE
state = run('--host', 'SW13-10-ACC-6000-48G', '--depth', '1', before_filter=again)
check(state is not None and not state['panel'] and state['hint'] and not state['selected'], 'the panel was open; Apply without the focus host closes it', state)
# 5-8. a reload after an action (model 2.96 §12.1): the request of the view is wrapped, the answer is changed, the page reloads
STUB = '''(async () => {
    const orig = view.request.bind(view); let mutate = null;
    view.request = async (name, options) => { const answer = await orig(name, options); if (name.startsWith('topology.devices.get') && mutate) { mutate(answer); } return answer; };
    const quiet = () => window.__topo_wait(() => view.idle, 'the page is idle after the action');      // the action has awaited its own reads; this is the state, not a time
    const state = () => (%s);
    const out = {};
    // 5. a Device panel: the Device is renamed by the answer: the panel reads it again
    const device = Array.from(view.nodes.values()).find(n => n.type === 'device');
    await view.selectNode(device);
    out.before = state();
    const shown = view.selected_node;      // the node of the panel: the Host when the Device has one
    mutate = answer => answer.devices.forEach(n => { if (String(n.id) === String(shown.id)) { n.label = 'RENAMED-BY-RELOAD'; n.name = 'RENAMED-BY-RELOAD'; } });
    await view.loadDevices(); await quiet();
    out.renamed = state();
    // 6. the Device is gone from the answer (merged, deleted): the panel closes, nothing is selected
    mutate = answer => { answer.devices = answer.devices.filter(n => String(n.id) !== String(shown.id)); answer.relations = answer.relations.filter(r => String(r.source) !== String(shown.id) && String(r.target) !== String(shown.id)); };
    await view.loadDevices(); await quiet();
    out.gone = state();
    // 7. a link panel, the link is gone from the answer (unlinked): the panel closes
    mutate = null; await view.loadDevices(); await quiet();
    const link = view.links.find(l => l.edge_id !== null && l.edge_id !== undefined && l.type === 'physical_link');
    await view.selectLink(view.resolvedLink(link)); await quiet();
    out.link_open = state();
    mutate = answer => { answer.relations = answer.relations.filter(r => String(r.edge_id ?? '') !== String(link.edge_id)); };
    await view.loadDevices(); await quiet();
    out.link_gone = state();
    // 8. nothing open: a reload opens nothing
    mutate = null; await view.loadDevices(); await quiet();
    out.nothing = state();
    return out;
})()''' % STATE.replace('\n', ' ')
state = run('--host', 'SW13-10-ACC-6000-48G', '--depth', '2', before_filter=STUB)
if state is None:
    check(False, 'the reload cases ran')
else:
    check(state['before']['panel'] and 'RENAMED' not in state['before']['title'], '5. the Device panel is open', state['before'])
    check(state['renamed']['panel'] and state['renamed']['title'].startswith('RENAMED-BY-RELOAD'), '5. a reload after an action: the open panel reads its object again (the new name)', state['renamed'])
    check(not state['gone']['panel'] and state['gone']['hint'] and not state['gone']['selected'] and state['gone']['title_shown'],
          '6. the Device is gone from the view: the panel is closed, nothing is selected, the hint and the title of the first load are shown', state['gone'])
    check(state['link_open']['selected'] and not state['link_gone']['selected'] and state['link_gone']['hint'] and not state['link_gone']['panel'],
          '7. a link panel is open and the link is unlinked: the panel is closed, nothing is selected, the hint is shown', [state['link_open'], state['link_gone']])
    check(not state['nothing']['panel'] and not state['nothing']['selected'] and state['nothing']['hint'], '8. nothing was open: a reload opens nothing', state['nothing'])
print('\n%s' % ('%d check(s) FAILED' % failed if failed else 'All checks passed.'))
sys.exit(1 if failed else 0)
