<?php

/**
 * The view "last run" of the topology page (topology-1-model.md §12.7): the run summary and the list of the observations. Every string that a neighbor announced is data:
 * it goes through the tag classes, which escape it (§13); nothing is written as HTML here. Included by monitoring.topology.view.php, which builds the header (`$html_page`).
 *
 * @var CView $this
 * @var array $data
 * @var CHtmlPage $html_page
 */

$summary = $data['summary'];
$status = $data['status'];
$result = $data['result'];

$lastrun_url = (new CUrl('zabbix.php'))->setArgument('action', 'topology.view')->setArgument('view', CControllerTopologyView::VIEW_LASTRUN);
$time = static fn (?int $clock): string => $clock ? zbx_date2str(DATE_TIME_FORMAT_SECONDS, $clock) : '–';

$result_labels = [
	CTopologyRunSummary::RESULT_IDLE => [_('No run yet'), ZBX_STYLE_GREY],
	CTopologyRunSummary::RESULT_RUNNING => [_('Running'), ZBX_STYLE_ORANGE],
	CTopologyRunSummary::RESULT_DONE => [_('Done'), ZBX_STYLE_GREEN],
	CTopologyRunSummary::RESULT_DONE_WITH_WARNINGS => [_('Done with warnings'), ZBX_STYLE_ORANGE],
	CTopologyRunSummary::RESULT_FAILED => [_('Failed'), ZBX_STYLE_RED]
];

// ---- the run summary ----
$summary_table = (new CTableInfo())->setId('topology-lastrun-summary');
$summary_table->addRow([_('Status'), (new CSpan($result_labels[$result][0]))->addClass($result_labels[$result][1])->setId('topology-lastrun-result')]);
if (($status['error'] ?? null) !== null) {
	$summary_table->addRow([_('Error'), (string) $status['error']]);
}
$summary_table->addRow([_('Started'), $time($status['started_at'] ?? null)]);
$summary_table->addRow([_('Finished'), $time($status['finished_at'] ?? null)]);
$summary_table->addRow([_('Started by'), (string) ($status['started_by'] ?? '–')]);

if ($summary !== null) {
	$summary_table->addRow([_('Changes'), implode(', ', [
		_s('Devices created: %1$s', $summary['devices_created'] ?? 0),
		_s('links created: %1$s', $summary['links_created'] ?? 0),
		_s('links superseded: %1$s', $summary['links_superseded'] ?? 0),
		_s('links revived: %1$s', $summary['links_revived'] ?? 0)
	])]);

	$duplicates = $summary['duplicate_candidates'] ?? [];
	$summary_table->addRow([_('Possible duplicates'), [
		_s('new: %1$s, ', $duplicates['new'] ?? 0),
		(new CLink(_s('%1$s pairs in the graph', $duplicates['pairs'] ?? 0),
			(new CUrl('zabbix.php'))->setArgument('action', 'topology.view')->setArgument('only_duplicates', 1)
		))->setId('topology-lastrun-duplicates')
	]]);

	$outcome_labels = [
		'applied' => _('applied'), 'device_only' => _('device only'), 'shadowed' => _('shadowed'),
		'conflict' => _('conflict'), 'ambiguous' => _('ambiguous'), 'old_snapshot' => _('Old data'), 'port_limit' => _('Too many neighbors')
	];
	$outcomes = [];
	foreach (CTopologyRunSummary::OUTCOMES as $outcome) {
		$link = (new CLink((string) ($summary['observations'][$outcome] ?? 0),
			(clone $lastrun_url)->setArgument('filter_set', 1)->setArgument('filter_outcomes', [$outcome])
		))->setId('topology-lastrun-outcome-'.$outcome);
		$outcomes[] = new CSpan([$outcome_labels[$outcome].': ', $link, ' ']);
	}
	$summary_table->addRow([_('Observations by outcome'), $outcomes]);
}

$html_page->addItem((new CDiv([
	(new CTag('h4', true, _('Run summary'))),
	$summary_table
]))->setId('topology-lastrun-summary-block'));

// ---- needs attention ----
if ($summary !== null) {
	$attention_table = (new CTableInfo())
		->setId('topology-lastrun-attention')
		->setNoDataMessage(_('Nothing needs attention'))
		->setHeader([_('Host'), _('Rule'), _('Problem'), _('Count')]);
	$problem_labels = [
		CTopologyRunSummary::ATTENTION_REPORTER_SKIPPED => _('Reporter skipped'),
		CTopologyRunSummary::ATTENTION_REPORTER_FAILED => _('Reporter failed'),
		CTopologyRunSummary::ATTENTION_LOCAL_PORT_UNRESOLVED => _('Local port not found'),
		CTopologyRunSummary::ATTENTION_ROWS_NO_IDENTITY => _('Rows without identity'),
		CTopologyRunSummary::ATTENTION_SNAPSHOT_OLD => _('Old data'),
		CTopologyRunSummary::ATTENTION_PORT_LIMIT => _('Too many neighbors'),
		CTopologyRunSummary::ATTENTION_PORTS_DISAGREE => _('PORTS rules disagree')
	];
	foreach ($summary['attention'] ?? [] as $line) {
		$host = $line['host'] === null ? '' : (new CLink($line['host'], (new CUrl('zabbix.php'))
			->setArgument('action', 'popup')->setArgument('popup', 'host.edit')->setArgument('hostid', $line['hostid'])));
		$rule = $line['itemid'] === null ? '' : (new CLink($line['rule'] ?? '#'.$line['itemid'], (new CUrl('zabbix.php'))
			->setArgument('action', 'popup')->setArgument('popup', 'lldrule.edit')->setArgument('itemid', $line['itemid'])
			->setArgument('context', 'host')));
		// an old snapshot: the time of the snapshot in the time zone of the user (the text of the run has the time zone of the process)
		$problem = $line['kind'] === CTopologyRunSummary::ATTENTION_SNAPSHOT_OLD && ($line['clock'] ?? null) !== null
			? _s('Old data: snapshot of %1$s', zbx_date2str(DATE_TIME_FORMAT_SECONDS, (int) $line['clock']))
			: ($problem_labels[$line['kind']] ?? $line['kind']).': '.$line['reason'];
		$attention_table->addRow([$host, $rule, $problem, $line['count']]);
	}
	$html_page->addItem((new CDiv([
		new CTag('h4', true, _('Needs attention')),
		$attention_table
	]))->setId('topology-lastrun-attention-block'));
}

// ---- the filter ----
$filter = $data['filter'];
$outcome_checkboxes = (new CCheckBoxList('filter_outcomes'))
	->setOptions(array_map(static fn (string $outcome): array => [
		'label' => $outcome === 'old_snapshot' ? _('Old data') : ($outcome === 'port_limit' ? _('Too many neighbors') : $outcome), 'value' => $outcome, 'checked' => in_array($outcome, $filter['outcomes'], true)
	], CTopologyPrototype::OBSERVATION_OUTCOMES))
	->setVertical(false);

$chips = [];
foreach (['device_id' => 'device', 'port_id' => 'port'] as $name => $kind) {
	if ($filter[$name] !== null) {
		$without = clone $lastrun_url;
		foreach (['device_id', 'port_id'] as $keep) {
			if ($keep !== $name && $filter[$keep] !== null) {
				$without->setArgument($keep, $filter[$keep]);
			}
		}
		$chips[] = new CSpan([
			($kind === 'device' ? _('Device') : _('Port')).': ', (string) $filter['names'][$kind], ' ',
			(new CLink(_('clear'), $without))
		]);
		$chips[] = ' ';
	}
}

$filter_form = (new CFilter())
	->setResetUrl($lastrun_url)
	->setProfile('web.topology.lastrun.filter')
	->setActiveTab(1)
	->addFilterTab(_('Filter'), [
		(new CFormList())
			->addRow(_('Outcome'), $outcome_checkboxes)
			->addRow(new CLabel(_('Reporter host'), 'filter_hostids__ms'),
				(new CMultiSelect([
					'name' => 'filter_hostids[]',
					'object_name' => 'hosts',
					'multiple' => false,
					'data' => $filter['hosts'],
					'popup' => ['parameters' => [
						'srctbl' => 'hosts', 'srcfld1' => 'hostid', 'dstfrm' => 'zbx_filter', 'dstfld1' => 'filter_hostids_', 'real_hosts' => 1
					]]
				]))->setWidth(ZBX_TEXTAREA_FILTER_STANDARD_WIDTH)
			)
			->addRow(_('Device and port'), $chips ?: new CSpan('–'))
	]);
foreach (['device_id', 'port_id'] as $name) {
	if ($filter[$name] !== null) {
		$filter_form->addVar($name, $filter[$name]);
	}
}
$filter_form->addVar('action', 'topology.view');
$filter_form->addVar('view', CControllerTopologyView::VIEW_LASTRUN);
$html_page->addItem($filter_form);

// ---- the observation list ----
$list = (new CTableInfo())
	->setId('topology-lastrun-list')
	->setHeader([_('Reporter host'), _('Local port'), _('Source'), _('Neighbor'), _('Outcome'), _('Reason'), _('Last seen')])
	->setPageNavigation($data['paging']);

foreach ($data['rows'] as $row) {
	$attrs = $row['remote_attrs'];
	// the neighbor: its Device name, else what the claim says (chassis id, address, name); all of it came from the network
	$neighbor = $row['remote_device']['name'] ?? null;
	if ($neighbor === null || $neighbor === '') {
		$neighbor = implode(' ', array_filter([
			$attrs['rem_sysname'] ?? null, $attrs['rem_chassis'] ?? null, $attrs['rem_mgmt_ip'] ?? null,
			isset($attrs['rem_port']) ? '('.$attrs['rem_port'].')' : null
		], static fn ($part): bool => $part !== null && $part !== ''));
	}
	$reason = '';
	if (($row['reason']['type'] ?? null) === 'blocking_link') {
		$reason = _s('Blocked by the link %1$s', implode(' – ', $row['reason']['ends']));
	}
	elseif (($row['reason']['type'] ?? null) === 'other_neighbors') {
		$reason = $row['reason']['neighbors']
			? _s('Other neighbors on the port: %1$s', implode(', ', $row['reason']['neighbors']))
			: _('Several claims on the port');
	}
	elseif ($row['outcome'] === 'old_snapshot') {
		$reason = _('The snapshot is older than the freshness threshold: the row is used for no link and no Device');
	}
	elseif ($row['outcome'] === 'port_limit') {
		$reason = _('The reporter port has more neighbors than the limit: the row finds no Device, so none is made and no link');
	}
	elseif ($row['outcome'] === 'device_only') {
		$reason = _('No far port in the claim: no link');
	}

	$list->addRow([
		(new CLink($row['reporter']['name'], (new CUrl('zabbix.php'))
			->setArgument('action', 'popup')->setArgument('popup', 'host.edit')->setArgument('hostid', $row['reporter']['hostid']))),
		(string) ($row['local_port']['name'] ?? '–'),
		(string) ($attrs['source'] ?? '–'),
		(string) $neighbor,
		$row['outcome'] === 'old_snapshot' ? _('Old data') : ($row['outcome'] === 'port_limit' ? _('Too many neighbors') : $row['outcome']),
		$reason,
		$time($row['last_seen'])
	]);
}

$html_page->addItem($list);
