#!/usr/bin/env python3
"""Imports the templates "Topology by SNMP" and "Topology by push" into the lab Zabbix and DELETES the discovery rules that the file no longer has (task 138: release 1 has no Learned MACs and no LAG rule;
a lab that was provisioned with the earlier file keeps those rules and their snapshots until this runs). Needs ZBX_TOKEN. Idempotent.
Usage: ZBX_TOKEN=... import_template.py [snmp|push|both]      (default both)"""
import os
import sys

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, os.path.dirname(HERE))
from labsim import zbx  # noqa: E402

FILES = {'snmp': 'template_topology_by_snmp.yaml', 'push': 'template_topology_by_push.yaml'}
RULES = {
    'template_groups': {'createMissing': True, 'updateExisting': False},
    'templates': {'createMissing': True, 'updateExisting': True},
    'items': {'createMissing': True, 'updateExisting': True, 'deleteMissing': True},
    'discoveryRules': {'createMissing': True, 'updateExisting': True, 'deleteMissing': True},
}


def main():
    token = os.environ.get('ZBX_TOKEN')
    if not token:
        print('ZBX_TOKEN is needed')
        return 2
    api = zbx.Api(os.environ.get('ZBX_URL', 'http://localhost/zabbix/ui/api_jsonrpc.php'), token)
    which = sys.argv[1] if len(sys.argv) > 1 else 'both'
    for key, name in FILES.items():
        if which not in ('both', key):
            continue
        path = os.path.join(os.path.dirname(os.path.dirname(HERE)), 'discovery', name)
        with open(path) as f:
            api.call('configuration.import', {'format': 'yaml', 'source': f.read(), 'rules': RULES})
        print('imported %s' % name)
    return 0


if __name__ == '__main__':
    sys.exit(main())
