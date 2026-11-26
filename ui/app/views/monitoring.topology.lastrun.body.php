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
		'conflict' => _('conflict'), 'ambiguous' => _('ambiguous'), 'old_snapshot' => _('Old data'), 'port_limit' => _('Too many neighbors'), 'local_port_unresolved' => _('Local port unresolved')
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
		CTopologyRunSummary::ATTENTION_ROWS_CHASSIS_INVALID => _('Rows with a chassis ID that is not a MAC'),
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
		'label' => $outcome === 'old_snapshot' ? _('Old data') : ($outcome === 'port_limit' ? _('Too many neighbors') : ($outcome === 'local_port_unresolved' ? _('Local port unresolved') : $outcome)), 'value' => $outcome, 'checked' => in_array($outcome, $filter['outcomes'], true)
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
	elseif ($row['outcome'] === 'local_port_unresolved') {
		$reasons = ['name_on_several_ports' => _('The name of the local port is on several ports of the reporter'), 'if_index_missing' => _('The local port number is no ifIndex of the reporter and no name matches'),
			'no_ports' => _('The reporter has no Ports snapshot'), 'name_not_found' => _('No port of the reporter has this name')];
		$reason = ($reasons[$row['reason']['reason'] ?? ''] ?? (string) ($row['reason']['reason'] ?? '')).'. '._('The row is used for no link and no Device.');
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
		(string) ($row['local_port']['name'] ?? ($row['outcome'] === 'local_port_unresolved' ? $row['local_port']['ref'] : '–')),
		(string) ($attrs['source'] ?? '–'),
		(string) $neighbor,
		$row['outcome'] === 'old_snapshot' ? _('Old data') : ($row['outcome'] === 'port_limit' ? _('Too many neighbors') : ($row['outcome'] === 'local_port_unresolved' ? _('Local port unresolved') : $row['outcome'])),
		$reason,
		$time($row['last_seen'])
	]);
}

$html_page->addItem($list);

// ---- the snapshots (2.104, §12.7 part 3; FR-C V-18 item 3) ----
// One row for each topology rule of a host (disabled ones too): the state of its snapshot is computed when the list is read, with the allowed age of the rule. The host filter of the list above
// applies; the state is chosen with the links.
$snapshot_state_names = ['fresh' => _('Fresh'), 'old' => _('Old'), 'not_used' => _('Not used'), 'none' => _('None')];
$snapshot_url = (new CUrl('zabbix.php'))->setArgument('action', 'topology.view')->setArgument('view', CControllerTopologyView::VIEW_LASTRUN);
$state_links = [(new CLink(_('All'), $data['snapshot_state'] === null ? null : (clone $snapshot_url)))->addClass($data['snapshot_state'] === null ? ZBX_STYLE_GREEN : null)];
foreach ($snapshot_state_names as $state => $name) {
	$state_links[] = ' ';
	$state_links[] = (new CLink($name, (clone $snapshot_url)->setArgument('filter_snapshot_state', $state)))->addClass($data['snapshot_state'] === $state ? ZBX_STYLE_GREEN : null);
}
$html_page->addItem([new CTag('h4', true, _('Snapshots')), (new CDiv($state_links))->addStyle('margin: 4px 0')]);

$snapshots = $data['snapshots'];
$snapshot_list = (new CTableInfo())
	->setId('topology-snapshot-list')
	->setHeader([_('Host'), _('Rule'), _('Topology type'), _('State'), _('Snapshot time'), _('Valid rows'), _('Allowed age'), _('Value not used')]);
foreach ($snapshots['snapshots'] as $snapshot) {
	$ignored = '';
	if ($snapshot['ignored_clock'] !== null) {
		$ignored = ($snapshot['ignored_reason'] === 'future' ? _('later than the server time') : _('older than the snapshot')).', '.$time($snapshot['ignored_clock']);
	}
	$snapshot_list->addRow([
		(new CLink($snapshot['host'], (new CUrl('zabbix.php'))->setArgument('action', 'popup')->setArgument('popup', 'host.edit')->setArgument('hostid', $snapshot['hostid']))),
		(new CLink($snapshot['rule'], (new CUrl('host_discovery.php'))->setArgument('form', 'update')->setArgument('itemid', $snapshot['itemid'])->setArgument('context', 'host'))),
		CTopologyType::getLabel($snapshot['topology_type']),
		$snapshot_state_names[$snapshot['state']],
		$snapshot['clock'] !== null ? $time($snapshot['clock']) : '–',
		$snapshot['rows_valid'] !== null ? (string) $snapshot['rows_valid'] : '–',
		$snapshot['allowed_age'] !== null ? convertUnitsS($snapshot['allowed_age']) : '–',
		$ignored
	]);
}
$html_page->addItem($snapshot_list);

// the pages of the list (the size of a page is the one of the other lists of the user)
$snapshot_pages = (int) ceil($snapshots['total'] / max(1, $snapshots['page_size']));
if ($snapshot_pages > 1) {
	$pager = [];
	for ($n = 1; $n <= $snapshot_pages; $n++) {
		$link = (clone $snapshot_url)->setArgument('snapshot_page', $n);
		if ($data['snapshot_state'] !== null) {
			$link->setArgument('filter_snapshot_state', $data['snapshot_state']);
		}
		$pager[] = (new CLink((string) $n, $link))->addClass($n === $snapshots['page'] ? ZBX_STYLE_GREEN : null);
		$pager[] = ' ';
	}
	$html_page->addItem((new CDiv($pager))->addStyle('margin: 4px 0'));
}
