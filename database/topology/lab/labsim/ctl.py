"""labctl: one snmpsim process per device (spec §6)."""
import ipaddress
import json
import os
import signal
import subprocess
import sys
import time

from . import gen
from .model import HERE, LabError

RUN_DIR = os.path.join(HERE, 'run')
DATA_DIR = os.path.join(HERE, 'data')
STATE = os.path.join(RUN_DIR, 'state.json')
OUR_VARIATION = os.path.join(HERE, 'variation')
DUMMY_IF = 'lab0'


def _repo_root():
    return os.path.abspath(os.path.join(HERE, '..', '..', '..'))


def snmpsim_bin(settings):
    return settings.get('snmpsim_bin') or os.path.join(_repo_root(), '.venv', 'bin', 'snmpsim-command-responder')


def stock_variation_dir(settings):
    py = os.path.join(os.path.dirname(snmpsim_bin(settings)), 'python')
    return subprocess.run([py, '-c', 'import snmpsim,os;print(os.path.join(os.path.dirname(snmpsim.__file__),"variation"))'],
                          capture_output=True, text=True, check=True).stdout.strip()


def load_state():
    try:
        with open(STATE) as f:
            return json.load(f)
    except FileNotFoundError:
        return None


def save_state(st):
    os.makedirs(RUN_DIR, exist_ok=True)
    with open(STATE, 'w') as f:
        json.dump(st, f, indent=1)


def alive(pid):
    try:
        os.kill(pid, 0)
    except OSError:
        return False
    try:
        with open('/proc/%d/stat' % pid) as f:
            return f.read().rsplit(')', 1)[1].split()[0] != 'Z'
    except OSError:
        return False


def snmp(tool, dev, *args):
    cmd = [tool, '-v2c', '-c', dev['community'], '-Oqv', '-t', '3', '-r', '1', '%s:%s' % (dev['ip'], dev['port'])] + list(args)
    r = subprocess.run(cmd, capture_output=True, text=True)
    return r.returncode, (r.stdout + r.stderr).strip()


def _need_alias(ip):
    return ipaddress.ip_address(ip) not in ipaddress.ip_network('127.0.0.0/8')


def _sudo_ip(*args):
    r = subprocess.run(['sudo', '-n', 'ip'] + list(args), capture_output=True, text=True)
    if r.returncode:
        raise LabError('cannot run "sudo -n ip %s": %s' % (' '.join(args), r.stderr.strip()))


def up(scenario, settings, log=print):
    st = load_state()
    if st and any(alive(p['pid']) for p in st['devices'].values()):
        raise LabError('the lab is already up (scenario %s); run "down" first' % st['scenario'])
    out = os.path.join(DATA_DIR, scenario.id)
    manifest = gen.generate(scenario, out)
    port = settings.get('snmp_port', 161)
    aliases = []
    ips = [d['ip'] for d in manifest['devices'].values()]
    if any(_need_alias(ip) for ip in ips):
        if subprocess.run(['ip', 'link', 'show', DUMMY_IF], capture_output=True).returncode:
            _sudo_ip('link', 'add', DUMMY_IF, 'type', 'dummy')
            _sudo_ip('link', 'set', DUMMY_IF, 'up')
            aliases.append('link')
        for ip in ips:
            if _need_alias(ip):
                _sudo_ip('addr', 'add', '%s/32' % ip, 'dev', DUMMY_IF)
                aliases.append(ip)
    os.makedirs(RUN_DIR, exist_ok=True)
    st = {'scenario': scenario.id, 'step': 0, 'aliases': aliases, 'devices': {}}
    stock = stock_variation_dir(settings)
    for name, d in sorted(manifest['devices'].items()):
        logf = open(os.path.join(RUN_DIR, '%s.log' % name), 'w')
        cmd = [snmpsim_bin(settings), '--data-dir=%s' % d['dir'], '--agent-udpv4-endpoint=%s:%s' % (d['ip'], port),
               '--variation-modules-dir=%s' % stock, '--variation-modules-dir=%s' % OUR_VARIATION]
        p = subprocess.Popen(cmd, stdout=logf, stderr=subprocess.STDOUT, start_new_session=True)
        st['devices'][name] = {'pid': p.pid, 'ip': d['ip'], 'port': port, 'community': d['community'], 'fault': None,
                               'mux': d['mux']}
    save_state(st)
    for name, d in st['devices'].items():
        for _ in range(60):
            rc, out_ = snmp('snmpget', d, '1.3.6.1.2.1.1.5.0')
            if rc == 0 and out_:
                break
            if not alive(d['pid']):
                raise LabError('snmpsim for %s died; see %s' % (name, os.path.join(RUN_DIR, name + '.log')))
            time.sleep(0.25)
        else:
            raise LabError('snmpsim for %s did not answer' % name)
        log('  %s up: pid %s %s:%s' % (name, d['pid'], d['ip'], port))
    return st


def down(log=print):
    st = load_state()
    if not st:
        log('lab is not up (no state file)')
        return
    for name, d in st['devices'].items():
        if alive(d['pid']):
            try:
                os.kill(d['pid'], signal.SIGCONT)
                os.kill(d['pid'], signal.SIGTERM)
            except OSError:
                pass
    for _ in range(40):
        if not any(alive(d['pid']) for d in st['devices'].values()):
            break
        time.sleep(0.1)
    for name, d in st['devices'].items():
        if alive(d['pid']):
            os.kill(d['pid'], signal.SIGKILL)
    aliases = st.get('aliases') or []
    for ip in [a for a in aliases if a not in ('link',)]:
        _sudo_ip('addr', 'del', '%s/32' % ip, 'dev', DUMMY_IF)
    if 'link' in aliases:
        _sudo_ip('link', 'del', DUMMY_IF)
    os.remove(STATE)
    log('lab down')


def status():
    st = load_state()
    if not st:
        return None
    rows = []
    for name, d in sorted(st['devices'].items()):
        rows.append({'device': name, 'ip': d['ip'], 'pid': d['pid'], 'alive': alive(d['pid']), 'step': st['step'],
                     'fault': d['fault']})
    return {'scenario': st['scenario'], 'step': st['step'], 'devices': rows}


def _stopped(pid):
    with open('/proc/%d/stat' % pid) as f:
        return f.read().rsplit(')', 1)[1].split()[0] == 'T'


def step(n, log=print):
    st = load_state()
    if not st:
        raise LabError('the lab is not up')
    for name, d in sorted(st['devices'].items()):
        if not d['mux']:
            continue
        was_stopped = alive(d['pid']) and _stopped(d['pid'])
        if was_stopped:
            os.kill(d['pid'], signal.SIGCONT)
        for sub, m in sorted(d['mux'].items()):
            rc, out = snmp('snmpset', d, m['control'], 'i', str(n))
            if rc:
                raise LabError('%s: snmpset %s failed: %s' % (name, m['control'], out))
            rc, out = snmp('snmpget', d, m['control'])
            if out != str(n):
                raise LabError('%s: control %s reads %r after setting %d' % (name, m['control'], out, n))
        if was_stopped:
            os.kill(d['pid'], signal.SIGSTOP)
    st['step'] = n
    save_state(st)
    log('  step %d set on %d device(s)' % (n, sum(1 for d in st['devices'].values() if d['mux'])))


def fault(device, name, settings=None, log=print):
    st = load_state()
    if not st or device not in st['devices']:
        raise LabError('device %s is not running' % device)
    d = st['devices'][device]
    if name in (None, 'none'):
        if d['fault'] == 'unreachable':
            os.kill(d['pid'], signal.SIGCONT)
        elif d['fault'] == 'wrong_community':
            raise LabError('wrong_community is cleared by restarting the lab')
        d['fault'] = None
    elif name == 'unreachable':
        # SIGSTOP: the process keeps its socket and its step, and answers nothing until SIGCONT. Spec §6.3 says
        # "delay" records; those cannot sit inside a labmultiplex subtree, so the lab uses a stopped process.
        os.kill(d['pid'], signal.SIGSTOP)
        d['fault'] = name
    else:
        raise LabError('fault %r is not implemented in v1 (no v1 scenario uses it)' % name)
    save_state(st)
    log('  %s fault: %s' % (device, d['fault']))
