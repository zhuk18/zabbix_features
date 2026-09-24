<?php

class CControllerTopotPortsGet extends CController {
	protected function init(): void {
		$this->disableCsrfValidation();
	}
	protected function checkInput(): bool { return $this->validateInput(['id' => 'required|string']); }
	protected function checkPermissions(): bool { return !CWebUser::isGuest(); }
	protected function doAction(): void {
		$id = $this->getInput('id');
		$groups = CTopologyTModel::getPorts($id);

		// §6: "/ports returns, for each port with a neighbor item, that item's itemid, so the
		// UI can link to its history" (E-B8). getPorts() itself never queries items directly
		// (it works from the already-assembled graph) -- filled in here instead.
		if (str_starts_with($id, 'h:')) {
			$hostid = substr($id, 2);
			$items = API::Item()->get([
				'output' => ['itemid'], 'hostids' => [$hostid], 'selectTags' => 'extend',
				'tags' => [['tag' => 'topo.role', 'value' => 'neighbor', 'operator' => 0]]
			]);
			$itemid_by_port = [];
			foreach ($items as $item) {
				foreach ($item['tags'] as $t) {
					if ($t['tag'] === 'interface') {
						$itemid_by_port[$t['value']] = $item['itemid'];
					}
				}
			}
			foreach ($groups as &$group) {
				foreach ($group as &$port) {
					$port['itemid'] = $itemid_by_port[$port['port']] ?? null;
				}
			}
			unset($group, $port);
		}

		$this->setResponse(new CControllerResponseData(['main_block' => json_encode(['groups' => $groups])]));
	}
}
