#!/usr/bin/env python3
"""The screenshots of the demo (task 104 section 4): 1500 x 900, the graph and the panel of one step, of the REAL topology page (ui_check_040/page_shot.py).

Usage: ZBX_UI_USER=... ZBX_UI_PASSWORD=... demo_shots.py <step|before3|before6> <out dir>

The stand must be up at the step that the shot shows (`labsim show D1 --step <n> --reset`); `before3` is taken at step 2 (three MikroTik Devices), `before6` at step 5 (the free port of SW22).
"""
import os
import subprocess
import sys

SHOT = os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', '..', '..', '..', 'ui_check_040', 'page_shot.py')

# graph area: the focus host and the depth; the panel: a node picked by its label (--select) or a script run before the shot (--eval)
CLICK_JUNCTION = "(() => { const g = Array.from(document.querySelectorAll('g.topology-node.junction'))[0]; g.querySelector('rect.topology-shape').dispatchEvent(new MouseEvent('click', {bubbles: true})); return 1; })()"
CLICK_LINK = ("(() => { const names = ['SW12-10-CORE-6000-48G', 'SW13-10-ACC-6000-48G']; const e = view.line_entries.find(e => e.link && e.link.type === 'physical_link' "
              "&& names.includes(String(e.link.source.label)) && names.includes(String(e.link.target.label))); e.open(); return 1; })()")
SHOTS = {
    '0': ['--host', 'SW12-10-CORE-6000-48G', '--depth', '2', '--select', 'SW12-10-CORE-6000-48G'],
    '1': ['--host', 'SW12-10-CORE-6000-48G', '--depth', '1', '--eval', CLICK_LINK],
    '2': ['--host', 'SW17-09-ACC-2530-48G', '--depth', '1', '--eval', CLICK_JUNCTION],
    'before3': ['--host', 'SW18-09-ACC-2530-48G', '--depth', '1', '--select', 'MikroTik'],
    '3': ['--host', 'SW18-09-ACC-2530-48G', '--depth', '1', '--select', 'MikroTik'],
    '4': ['--host', 'SW15-10-POE-6000-24G', '--depth', '1', '--select', '3a:5c:91:e2:07:4b'],
    '5': ['--host', 'SW21-07-ACC-2530-48G', '--depth', '1', '--select', 'SW21-07-ACC-2530-48G'],
    'before6': ['--host', 'SW22-07-ACC-2530-48G', '--depth', '1', '--select', 'SW22-07-ACC-2530-48G'],
    '6': ['--host', 'SW22-07-ACC-2530-48G', '--depth', '1', '--select', 'ISP-Router'],
}

if __name__ == '__main__':
    if len(sys.argv) != 3 or sys.argv[1] not in SHOTS:
        sys.exit(__doc__)
    step, out_dir = sys.argv[1], sys.argv[2]
    name = '104-d1-step3-before.png' if step == 'before3' else '104-d1-step6-before.png' if step == 'before6' else '104-d1-step%s.png' % step
    sys.exit(subprocess.run([sys.executable, SHOT] + SHOTS[step] + ['--width', '1500', '--height', '900', '--out', os.path.join(out_dir, name)]).returncode)
