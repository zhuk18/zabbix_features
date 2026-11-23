<?php

/**
 * The topology page (topology-1-model.md 2.98, §12.1 "Page header"): one header for the two views, the graph and the last run. The body of each view is in its own file
 * (monitoring.topology.graph.php, monitoring.topology.lastrun.body.php), included below.
 *
 * @var CView $this
 * @var array $data
 */

$this->addJsFile('layout.mode.js');      // the kiosk mode button of the header
$this->enableLayoutModes();
$web_layout_mode = $this->getLayoutMode();
$data['web_layout_mode'] = $web_layout_mode;

$is_graph = $data['view'] === CControllerTopologyView::VIEW_GRAPH;

$view_url = static fn (string $view): string => (new CUrl('zabbix.php'))
	->setArgument('action', 'topology.view')
	->setArgument('view', $view)
	->getUrl();

// 1. the run state, grey text (§12.1 "Page header", §12.7 "Behind the data"): "<N> reporters have new data since the last run · Last run: <time>", "Reconciliation in progress",
// "Run requested". The page's script keeps the text up to date (the status action is polled); this is the text at the load of the page.
$run_parts = [];
if ($data['running']) {
	$run_parts[] = _('Reconciliation in progress');
}
elseif ($data['requested']) {
	$run_parts[] = _('Run requested');
}
if ($data['reporters_new_data'] > 0) {
	$run_parts[] = _n('%1$s reporter has new data since the last run', '%1$s reporters have new data since the last run',
		$data['reporters_new_data']
	);
	$run_parts[count($run_parts) - 1] = str_replace('%1$s', (string) $data['reporters_new_data'], $run_parts[count($run_parts) - 1]);
}
if (($data['status']['finished_at'] ?? null) !== null) {
	$run_parts[] = _s('Last run: %1$s', zbx_date2str(DATE_TIME_FORMAT_SECONDS, (int) $data['status']['finished_at']));
}

$html_page = (new CHtmlPage())
	->setTitle(_('Topology'))
	->setWebLayoutMode($web_layout_mode)
	->setControls(
		(new CTag('nav', true,
			(new CList())
				->addItem(
					(new CSpan(implode(' · ', $run_parts)))
						->setId('topology-run-line')
						->addClass(ZBX_STYLE_GREY)
				)
				->addItem(
					(new CRadioButtonList('view', $data['view']))
						->setId('topology-view-switch')
						->addValue(_('Graph'), CControllerTopologyView::VIEW_GRAPH)
						->addValue(_('Last run'), CControllerTopologyView::VIEW_LASTRUN)
						->disableAutocomplete()
						->setModern(true)
				)
				->addItem((new CSimpleButton(_('Run reconciliation')))->setId('topology-ingest-run'))
				->addItem(get_icon('kioskmode', ['mode' => $web_layout_mode]))
		))->setAttribute('aria-label', _('Content controls'))
	);

// 3. "The reconciliation does not run": a warning under the header, in both views (§12.7); the script shows and hides it with the state of the run
$executor_box = makeMessageBox(ZBX_STYLE_MSG_WARNING, [], _('The reconciliation does not run. Check the scheduled CLI job.'), false)
	->setId('topology-executor-missing');
if (!$data['executor_missing']) {
	$executor_box->setAttribute('hidden', 'hidden');
}
// a message box is displayed by its class: the attribute `hidden` alone does not hide it
$html_page->addItem(new CTag('style', true, '#topology-executor-missing[hidden]{display:none}'));
$html_page->addItem($executor_box);

// the switch (the same control as View | Edit of Monitoring → Services): a click on the value that is not selected opens the URL of that view
$view_urls = [
	CControllerTopologyView::VIEW_GRAPH => $view_url(CControllerTopologyView::VIEW_GRAPH),
	CControllerTopologyView::VIEW_LASTRUN => $view_url(CControllerTopologyView::VIEW_LASTRUN)
];
$switch_script = '
	document.querySelectorAll("input[type=radio][name=view]").forEach(input => {
		if (!input.checked) {
			input.addEventListener("click", () => { location.href = '.json_encode($view_urls).'[input.value]; });
		}
	});';

$page_after = [];
if ($is_graph) {
	include __DIR__.'/monitoring.topology.graph.php';
}
else {
	$this->addJsFile('topology.graph.js');
	$this->includeJsFile('monitoring.topology.header.js.php');
	include __DIR__.'/monitoring.topology.lastrun.body.php';
}

$html_page->show();

foreach ($page_after as $item) {
	$item->show();
}

(new CScriptTag($switch_script))->setOnDocumentReady()->show();
