<?php

/**
 * The view "last run" of the topology (topology-1-model.md §12.7): the run summary of the last ingest and the list of its observations. Release 1: Super admin only.
 *
 * Input: the filter of the list (outcomes, one reporter host; kept in the profile), the Device and the Port (from the links "Neighbor reports" of the side panel,
 * not kept), the page. The data: topology.ingest.status (the status file) and topology.observations.get (CTopologyPrototype::getObservations), the same code as the API.
 */
class CControllerTopologyLastRun extends CController {

	public const PROFILE_OUTCOMES = 'web.topology.lastrun.outcomes';
	public const PROFILE_HOSTID = 'web.topology.lastrun.hostid';

	// the rows of the list that one request reads; the pages cut them (the standard pager of the list pages)
	private const ROW_LIMIT = 5000;

	protected function init(): void {
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		$ret = $this->validateInput([
			'filter_set' => 'in 1',
			'filter_rst' => 'in 1',
			'filter_outcomes' => 'array',
			'filter_hostids' => 'array_id',
			'device_id' => 'id',
			'port_id' => 'id',
			'page' => 'ge 1'
		]);

		if ($ret) {
			foreach ((array) $this->getInput('filter_outcomes', []) as $outcome) {
				if (!is_string($outcome) || !in_array($outcome, CTopologyPrototype::OBSERVATION_OUTCOMES, true)) {
					$ret = false;
					break;
				}
			}
		}
		if (!$ret) {
			$this->setResponse(new CControllerResponseFatal());
		}

		return $ret;
	}

	protected function checkPermissions(): bool {
		return CWebUser::getType() == USER_TYPE_SUPER_ADMIN;
	}

	protected function doAction() {
		if ($this->hasInput('filter_set')) {
			CProfile::updateArray(self::PROFILE_OUTCOMES, array_values((array) $this->getInput('filter_outcomes', [])), PROFILE_TYPE_STR);
			CProfile::updateArray(self::PROFILE_HOSTID, array_slice(array_values((array) $this->getInput('filter_hostids', [])), 0, 1), PROFILE_TYPE_ID);
			CPagerHelper::resetPage();
		}
		elseif ($this->hasInput('filter_rst')) {
			CProfile::deleteIdx(self::PROFILE_OUTCOMES);
			CProfile::deleteIdx(self::PROFILE_HOSTID);
			CPagerHelper::resetPage();
		}

		// default (model §11): everything except `applied`
		$device_id = $this->hasInput('device_id') ? $this->getInput('device_id') : null;
		$port_id = $this->hasInput('port_id') ? $this->getInput('port_id') : null;
		$outcomes = CProfile::getArray(self::PROFILE_OUTCOMES, null) ?? array_values(array_diff(CTopologyPrototype::OBSERVATION_OUTCOMES, ['applied']));
		if (($device_id !== null || $port_id !== null) && !$this->hasInput('filter_set')) {
			// the link "Neighbor reports" of the side panel: every report about the Device or the Port, the applied ones too (what the stored filter hides is no answer to it)
			$outcomes = CTopologyPrototype::OBSERVATION_OUTCOMES;
		}
		$hostid = CProfile::getArray(self::PROFILE_HOSTID, [])[0] ?? null;

		$status = CTopologyRunSummary::read();
		$summary = is_array($status['summary'] ?? null) ? $status['summary'] : null;

		$rows = $outcomes
			? CTopologyPrototype::getObservations($outcomes, $device_id, self::ROW_LIMIT, false, null, $hostid, $port_id)
			: [];

		$names = ['device' => null, 'port' => null, 'host' => null];
		if ($device_id !== null) {
			$names['device'] = CTopologyPrototype::deviceLabel($device_id);
		}
		if ($port_id !== null) {
			$names['port'] = CTopologyPrototype::portLabel($port_id);
		}
		$hosts = $hostid !== null ? API::Host()->get(['output' => ['hostid', 'name'], 'hostids' => [$hostid]]) : [];

		$page = $this->getInput('page', 1);
		CPagerHelper::savePage('topology.lastrun', $page);
		$url = (new CUrl('zabbix.php'))->setArgument('action', 'topology.lastrun');
		foreach (['device_id' => $device_id, 'port_id' => $port_id] as $name => $value) {
			if ($value !== null) {
				$url->setArgument($name, $value);
			}
		}
		$paging = CPagerHelper::paginate($page, $rows, ZBX_SORT_UP, $url);

		$data = [
			'status' => $status,
			'summary' => $summary,
			'result' => CTopologyRunSummary::result($status['status'] ?? null, $summary),
			'filter' => [
				'outcomes' => $outcomes,
				'hosts' => CArrayHelper::renameObjectsKeys($hosts, ['hostid' => 'id']),
				'device_id' => $device_id, 'port_id' => $port_id, 'names' => $names
			],
			'rows' => $rows,
			'paging' => $paging
		];

		$response = new CControllerResponseData($data);
		$response->setTitle(_('Topology: last run'));
		$this->setResponse($response);
	}
}
