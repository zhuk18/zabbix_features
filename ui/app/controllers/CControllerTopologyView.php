<?php

/**
 * The topology page: two views of one page with one header (topology-1-model.md 2.98, §12.1 "Page header"): `view=graph` (default) and `view=lastrun` (§12.7).
 * Release 1: Super admin only (the page itself refuses everyone else).
 *
 * The graph view reads its data by the JSON actions of the page. The last run view reads its data here: the filter of the list (outcomes, one reporter host; kept in the profile),
 * the Device and the Port (from the links "Neighbor reports" of the side panel, not kept), the page; the data: the status file and CTopologyPrototype::getObservations,
 * the same code as the API.
 */
class CControllerTopologyView extends CController {

	public const VIEW_GRAPH = 'graph';
	public const VIEW_LASTRUN = 'lastrun';

	// the legend of the graph can be collapsed; the state is kept per user (topology-1-model.md §12.2)
	public const PROFILE_LEGEND_COLLAPSED = 'web.topology.legend.collapsed';
	public const PROFILE_OUTCOMES = 'web.topology.lastrun.outcomes';
	public const PROFILE_HOSTID = 'web.topology.lastrun.hostid';

	// the rows of the list that one request reads; the pages cut them (the standard pager of the list pages)
	private const ROW_LIMIT = 5000;

	protected function init(): void {
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		$ret = $this->validateInput([
			'view' => 'in '.self::VIEW_GRAPH.','.self::VIEW_LASTRUN,
			// the filter of the graph (the URL keeps it, so that Back returns to the graph with its filter: §12.1 "Page header")
			'groupids' => 'array_id',
			'hostid' => 'id',
			'hops' => 'string',
			'only_duplicates' => 'in 1',
			'show_unlinked' => 'in 1',
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

	// release 1 (model §12.7): the page is for Super admin; anyone else gets the standard "no permissions" page
	protected function checkPermissions(): bool {
		return CWebUser::getType() == USER_TYPE_SUPER_ADMIN;
	}

	protected function doAction() {
		$view = $this->getInput('view', self::VIEW_GRAPH);
		$status = CTopologyRun::status();
		$state = CTopologyRun::state();

		// the header of both views: the run state and the time of the last run; the warning when the executor does not run (§12.1 "Page header"; the same values as the status
		// action reads, which the page polls)
		$data = [
			'view' => $view,
			'status' => $status,
			'reporters_new_data' => CTopologyRun::reportersNewData(),
			'running' => $state['locked'] && ($status['status'] ?? '') === 'running',
			'requested' => (bool) $state['requested'],
			'executor_missing' => (bool) ($state['requested'] && $state['executor_missing']),
			'legend_collapsed' => (int) CProfile::get(self::PROFILE_LEGEND_COLLAPSED, 1)
		];

		if ($view === self::VIEW_LASTRUN) {
			$data += $this->lastRunData($status);
		}
		else {
			$data += $this->graphFilterData();
		}

		$response = new CControllerResponseData($data);
		$response->setTitle(_('Topology'));
		$this->setResponse($response);
	}

	/**
	 * The filter of the graph from the URL (the page writes it there at Apply and Reset); the names of the host groups and of the focus host are read for the multiselects.
	 * Depth: 1 to 6 or empty (no limit); the page starts with 1.
	 */
	private function graphFilterData(): array {
		$groupids = array_values((array) $this->getInput('groupids', []));
		$hostid = $this->hasInput('hostid') ? $this->getInput('hostid') : null;
		$hops = $this->hasInput('hops') ? (string) $this->getInput('hops') : '1';
		if (!in_array($hops, ['', '1', '2', '3', '4', '5', '6'], true)) {
			$hops = '1';
		}
		$groups = $groupids ? API::HostGroup()->get(['output' => ['groupid', 'name'], 'groupids' => $groupids, 'preservekeys' => false]) : [];
		$hosts = $hostid !== null ? API::Host()->get(['output' => ['hostid', 'name'], 'hostids' => [$hostid]]) : [];

		return [
			'filter_groups' => CArrayHelper::renameObjectsKeys($groups, ['groupid' => 'id']),
			'filter_host' => CArrayHelper::renameObjectsKeys($hosts, ['hostid' => 'id']),
			'filter_hops' => $hops,
			'filter_duplicates' => $this->hasInput('only_duplicates'),
			'filter_unlinked' => $this->hasInput('show_unlinked')
		];
	}

	private function lastRunData(array $status): array {
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
		CPagerHelper::savePage('topology.view', $page);
		$url = (new CUrl('zabbix.php'))->setArgument('action', 'topology.view')->setArgument('view', self::VIEW_LASTRUN);
		foreach (['device_id' => $device_id, 'port_id' => $port_id] as $name => $value) {
			if ($value !== null) {
				$url->setArgument($name, $value);
			}
		}
		$paging = CPagerHelper::paginate($page, $rows, ZBX_SORT_UP, $url);

		return [
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
	}
}
