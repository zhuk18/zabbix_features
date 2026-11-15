"""Show mode (topology-4-lab-simulator.md 6.4a, 2.26, task 104): put a stand into Zabbix and leave it there, so that a person can look at the topology in the UI. Not a test: no assertions, no report."""
import json
import os

from . import ctl, db, runner, zbx
from .model import LabError


def refuse_if_up():
    """`show` of a stand while another (or the same) one is up refuses with a message that names the stand and the command that stops it (lab spec 6.4a, 3b)."""
    st = ctl.load_state()
    if st and any(ctl.alive(d['pid']) for d in st['devices'].values()):
        raise LabError(ctl.up_message(st))


def topology_url(settings):
    return settings['zabbix']['url'].split('api_jsonrpc.php')[0] + 'zabbix.php?action=topology.view'


def summary(conn, ingest, settings):
    """The lines `show` prints at the end (6.4a point 5): Devices, Ports, links by type, observations by outcome, the ingest status and the URL of the topology view."""
    state = db.natural_state(conn)
    links = {}
    for l in state['links']:
        key = '%s %s' % (l['type'], l['state'])
        links[key] = links.get(key, 0) + 1
    outcomes = {}
    for o in state['observations']:
        outcomes[o['outcome']] = outcomes.get(o['outcome'], 0) + 1
    ports = sum(len(v) for v in state['ports'].values())
    lines = ['Devices: %d, Ports: %d' % (len(state['devices']), ports),
             'links: ' + (', '.join('%s %d' % (k, v) for k, v in sorted(links.items())) or 'none'),
             'observations: ' + (', '.join('%s %d' % (k, v) for k, v in sorted(outcomes.items())) or 'none'),
             'ingest: %s, %s' % (ingest.get('status'), ', '.join('%s %s' % (k, (ingest.get('summary') or {}).get(k)) for k in ('reporters_processed', 'devices_created', 'links_created', 'links_superseded', 'observations_ambiguous')
                                                              if k in (ingest.get('summary') or {}))),
             'topology view: ' + topology_url(settings)]
    return lines


def show(scenario, step, settings, api, conn, reset=False, timeout=None, log=print):
    """`labsim show <scenario> [--step n] [--reset]`: provision the hosts of the lab file with `lab.stand`, run the steps 0 to n (data changes, deviations, faults, waits, operator actions, polls and
    ingest, no assertions), leave the stand up and print the summary."""
    refuse_if_up()
    if step is not None and not 0 <= step < len(scenario.steps):
        raise LabError('scenario %s has steps 0 to %d, not %d' % (scenario.id, len(scenario.steps) - 1, step))
    n = 0 if step is None else step
    log('provisioning the hosts of stand %s' % scenario.states[0].stand)
    zbx.provision(api, scenario, prune=False, log=log)
    runner.run(scenario, settings, api, conn, reset=reset, timeout=runner.effective_timeout(timeout, settings), log=log, show_step=n)
    state_ingest = runner_ingest_status()
    for line in summary(conn, state_ingest, settings):
        log(line)
    log('the stand %s stays up at step %d of %s; labsim down stops it' % (scenario.states[0].stand, n, scenario.id))
    return 0


def runner_ingest_status():
    try:
        with open(runner.STATUS_FILE) as f:
            return json.load(f)
    except (OSError, ValueError):
        return {}


def ingest_only(settings, conn, log=print):
    """`labsim show --ingest-only`: one full ingest again and the summary (6.4a steps 4 and 5), for example after a test run cleared the state."""
    st = ctl.load_state()
    if not st or not any(ctl.alive(d['pid']) for d in st['devices'].values()) or st.get('mode') != 'show':
        raise LabError('no show is up (labsim status); start one with: labsim show <scenario>')
    ingest = runner.run_ingest(settings)
    for line in summary(conn, ingest, settings):
        log(line)
    return 0


def stop(settings, api_factory, log=print):
    """`labsim down`: stop the processes of the stand; after a show also put back the freshness macro that the scenario set (6.4a). A missing token does not keep the processes up."""
    st = ctl.load_state()
    was_show = bool(st) and st.get('mode') == 'show'
    ctl.down(log)
    if was_show or os.path.exists(runner.FRESH_FILE):
        try:
            api = api_factory()
        except LabError as e:
            log('note: the freshness macro was not restored (%s); run labsim restore with ZBX_TOKEN set' % e)
            return
        after = zbx.restore_fresh_macro(api, runner.FRESH_FILE, log)
        if after is not None:
            log('freshness macro restored: %s' % after)
