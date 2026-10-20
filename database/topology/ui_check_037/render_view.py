#!/usr/bin/env python3
"""ui_check_037: offline harness for the topology view (037 section 4): the real view code (before: git HEAD, after: the working tree) on the real JSON of the database, in headless Chrome.
No login, no Zabbix frontend: fetch() is stubbed with a JSON dump of topology.devices.get."""
import json, os, re, subprocess, sys
HERE = os.path.dirname(os.path.abspath(__file__))
REPO = os.path.abspath(os.path.join(HERE, '..', '..', '..'))
S = os.environ.get('OUT', '/tmp/topology-ui-check')          # where the HTML pages and the scratch files go
os.makedirs(S + '/shots', exist_ok=True)
def build(tag, js_path, view_path, dump_path, collapsed='0'):
    js = subprocess.run(['php', HERE + '/render_js.php', js_path, collapsed], capture_output=True, text=True).stdout
    view = open(view_path).read()
    css = re.search(r"\$page_styles = '(.*?)';\n", view, re.S).group(1).replace("\\'", "'")
    d3 = open(REPO + '/ui/js/vendors/D3/d3.js').read()
    dump = open(dump_path).read().strip()
    html = '''<!doctype html><html><head><meta charset="utf-8"><style>body{margin:0;font-family:Arial,sans-serif}%s .topology-prototype{height:100vh}</style></head><body>
<div class="topology-prototype"><div class="topology-header"><h1>Physical topology</h1><div id="topology-link-pick"></div>
<span id="topology-ingest-status"></span><button id="topology-ingest-run">Run discovery ingest</button><button id="topology-clean-all">Clean</button>
<select id="topology-link-filter"><option value="all">all</option></select><input id="topology-filter-hops" value="3"><button id="topology-filter-apply"></button><button id="topology-filter-clear"></button></div>
<div class="topology-workspace"><svg id="topology-canvas"></svg><div class="topology-panel" id="topology-panel"><button class="topology-panel-toggle" id="topology-panel-toggle">«</button>
<h2 id="topology-details-title">Device details</h2><div id="topology-details">Select a device.</div><div id="topology-more-hint" class="topology-more" hidden></div></div></div></div>
<script>%s</script>
<script>
const DUMP = %s;
window.jQuery = () => ({multiSelect: () => [], on: () => {}});
window.fetch = async url => { const action = String(url).split('action=')[1].split('&')[0];
  const payload = action === 'topology.devices.get' ? DUMP : (action === 'topology.ingest.status' ? {status: 'idle'} : {});
  return {ok: true, json: async () => payload}; };
</script>
<script>%s</script>
<script>view.init();</script></body></html>''' % (css, d3, dump, js)
    out = '%s/shots/harness-%s.html' % (S, tag)
    open(out, 'w').write(html)
    return out
if __name__ == '__main__':
    tag, dump = sys.argv[1], sys.argv[2]
    if tag == 'before':
        for name, path in (('js', 'ui/app/views/js/monitoring.topology.view.js.php'), ('view', 'ui/app/views/monitoring.topology.view.php')):
            open('%s/shots/before.%s.php' % (S, name), 'w').write(subprocess.run(['git', 'show', 'HEAD:' + path], capture_output=True, text=True, cwd=REPO).stdout)
        print(build('before', S + '/shots/before.js.php', S + '/shots/before.view.php', dump))
    else:
        collapsed = sys.argv[3] if len(sys.argv) > 3 else '0'
        print(build(tag, REPO + '/ui/app/views/js/monitoring.topology.view.js.php', REPO + '/ui/app/views/monitoring.topology.view.php', dump, collapsed))
