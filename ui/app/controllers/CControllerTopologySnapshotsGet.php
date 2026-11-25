<?php

/**
 * GET topology.snapshots.get (topology-1-model.md 2.104 §11, §12.7 part 3; FR-C V-18 item 3): one row for each topology rule of a host with the state of its snapshot.
 *
 * Optional: state (fresh|old|not_used|none), hostid, page, page_size. Only for a Super admin. JSON only. The names in the response come from the network (a host name, a rule name) and are data:
 * whoever renders them must escape.
 */
class CControllerTopologySnapshotsGet extends CController {
	use CTopologyJsonInput;

	protected function init(): void {
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		$ret = $this->validateJsonInput([
			'state' => 'in '.implode(',', CTopologyPrototype::SNAPSHOT_STATES),
			'hostid' => 'id',
			'page' => 'int32|ge 1',
			'page_size' => 'int32|ge 1|le 1000'
		]);

		return $ret;
	}

	protected function checkPermissions(): bool {
		return CWebUser::getType() == USER_TYPE_SUPER_ADMIN;
	}

	protected function doAction() {
		$answer = CTopologyPrototype::getSnapshots(
			$this->hasInput('state') ? $this->getInput('state') : null,
			$this->hasInput('hostid') ? $this->getInput('hostid') : null,
			(int) $this->getInput('page', 1),
			(int) $this->getInput('page_size', (int) (CWebUser::$data['rows_per_page'] ?? 50))
		);

		$this->setResponse(new CControllerResponseData(['main_block' => json_encode($answer, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE)]));
	}
}
