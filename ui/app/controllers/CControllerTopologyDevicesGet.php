<?php

class CControllerTopologyDevicesGet extends CController {
    protected function init() {
		$this->disableCsrfValidation();
	}
	protected function checkInput(): bool {
		return $this->validateInput([
			// Hostgroup filter mode, ignored when 'hostid' is also set — a selected host
			// overrides the group selection, same precedence as the community network_topology
			// module's own filter.
			'groupids' => 'array_id',
			'hostid' => 'id',
			'hops' => 'int32'
		]);
	}
	protected function checkPermissions(): bool { return !CWebUser::isGuest(); }
	protected function doAction() {
		$groupids = $this->getInput('groupids', []);
		$hostid = (string) $this->getInput('hostid', '');
		// 1-6, matching the community module's own hop-radius range; a stray/garbage value
		// falls back to 1 rather than silently expanding to an unbounded walk.
		$hops = min(6, max(1, (int) $this->getInput('hops', 1)));

		$node_ids = CTopologyPrototype::resolveScope($groupids, $hostid, $hops);
		// Tray follows the hostgroup filter (not the focus-host+depth one — an unassigned host
		// has no hop distance from anything, it isn't in the graph yet), same precedence as
		// everywhere else: a selected host means group mode isn't active, so don't restrict.
		$tray_groupids = $hostid === '' ? $groupids : [];

		$this->setResponse(new CControllerResponseData(['main_block' => json_encode([
			'devices' => CTopologyPrototype::getDevices($node_ids),
			'relations' => CTopologyPrototype::getRelations($node_ids),
			'unassigned_hosts' => CTopologyPrototype::getUnassignedHosts($tray_groupids),
			'unassigned_proxies' => CTopologyPrototype::getUnassignedProxies()
		])]));
	}
}
