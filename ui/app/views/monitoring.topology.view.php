<?php

$this->addJsFile('d3.js');
$this->addJsFile('multiselect.js');
$this->includeJsFile('monitoring.topology.view.js.php');

// NOTE: CTag/CDiv::addStyle() writes into the element's style="" HTML attribute, which can only hold
// property:value declarations — it cannot hold selector rules. A real <style> tag (via
// CTag('style', true, ...)) is required instead. CTag::addItem() also HTML-escapes a string body, so
// '>' combinators must be avoided here — #topology-canvas (an id selector) is used instead of
// '.topology-workspace>svg' for exactly that reason.
$page_styles = '
.topology-prototype{display:flex;flex-direction:column;height:calc(100vh - 180px);min-height:520px}
.topology-header{display:flex;flex:0 0 auto;justify-content:space-between;align-items:center;margin-bottom:12px}
.topology-header h1{margin:0}
.topology-header-field{display:flex;align-items:center;gap:8px;font-size:12px}
.topology-header-field label{margin:0}
.topology-workspace{display:grid;grid-template-columns:minmax(0,1fr) 400px;grid-template-rows:minmax(0,1fr);flex:1 1 0;min-height:0;overflow:hidden;border:1px solid #d9d9d9;background:#fff}
#topology-canvas{grid-column:1;grid-row:1;width:100%;height:100%;min-width:0;min-height:0;overflow:hidden}
.topology-panel{grid-column:2;grid-row:1;min-width:0;min-height:0;overflow:auto;border-left:1px solid #d9d9d9;padding:16px;position:relative;transition:width .15s ease}
.topology-panel h2{margin-top:0;padding-right:28px}
.topology-panel-toggle{position:absolute;top:10px;right:10px;padding:2px 8px;line-height:1.4;font-size:13px;cursor:pointer;background:#fff;border:1px solid #d9d9d9;border-radius:3px}
.topology-workspace.topology-panel-collapsed{grid-template-columns:minmax(0,1fr) 34px}
.topology-workspace.topology-panel-collapsed .topology-panel{padding:10px 4px;overflow:hidden}
.topology-workspace.topology-panel-collapsed .topology-panel h2,
.topology-workspace.topology-panel-collapsed .topology-panel #topology-details{display:none}
.topology-node{cursor:pointer}
.topology-node.device rect{fill:#f3f4f6;stroke:#697386;stroke-width:2;stroke-dasharray:6 4}
.topology-node.host rect{fill:#2b7dbc;stroke:#17577f;stroke-width:2}
.topology-label{font-size:12px;pointer-events:none}
.topology-link{stroke:#77818d;stroke-width:2}
.topology-group{margin:14px 0}
.topology-group h3{font-size:14px;margin:0 0 6px}
.topology-group table{width:100%;table-layout:fixed;border-collapse:collapse;font-size:11.5px}
.topology-col-port{width:26%}
.topology-col-status{width:14%}
.topology-col-connected{width:36%}
.topology-col-action{width:24%}
.topology-group th,.topology-group td{padding:6px 6px;border-bottom:1px solid #e5e7eb;text-align:left;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;vertical-align:middle}
.topology-port-name{font-family:monospace;font-size:11px}
.topology-status-dot{display:inline-block;width:7px;height:7px;border-radius:50%;margin-right:5px;vertical-align:middle}
.topology-status-up{background:#2ecc71}
.topology-status-down{background:#e74c3c}
.topology-port-link-row td{white-space:normal;overflow:visible}
.topology-port-link-picker{display:flex;flex-direction:column;gap:4px;padding:4px 0}
.topology-port-link-picker input,.topology-port-link-picker select{width:100%;box-sizing:border-box;font-size:11.5px}
.topology-port-link-results{max-height:100px;overflow-y:auto;border:1px solid #d9d9d9;border-radius:3px}
.topology-port-link-results:empty{display:none;border:none}
.topology-port-link-result{padding:4px 6px;font-size:11.5px;cursor:pointer}
.topology-port-link-result:hover{background:#eef6fc}
.topology-port-link-actions{display:flex;gap:6px}
.topology-promote{margin-top:14px}
.topology-promote-search{width:100%;box-sizing:border-box;margin-bottom:4px}
.topology-promote-results{max-height:140px;overflow-y:auto;border:1px solid #d9d9d9;border-radius:3px;margin-bottom:6px}
.topology-promote-results:empty{display:none;border:none}
.topology-promote-result{padding:5px 8px;font-size:12px;cursor:pointer}
.topology-promote-result:hover{background:#eef6fc}
.topology-promote-selected{font-size:12px;color:#3d556a;margin-bottom:6px}
.topology-severity-badge{display:inline-block;padding:2px 8px;border-radius:3px;color:#fff;font-size:11px;font-weight:bold}
.topology-empty{color:#768d99;font-style:italic;padding:8px 0}
.topology-filter-bar{display:flex;align-items:flex-start;gap:8px 24px;flex-wrap:wrap;flex:0 0 auto;margin-bottom:12px;padding:8px 12px;border:1px solid #d9d9d9;background:#fbfbfb}
.topology-filter-field{display:flex;align-items:center;gap:8px 14px;flex-wrap:wrap}
.topology-filter-bar label{font-size:12px;color:#1f2933;margin-right:4px}
.topology-filter-hint{flex-basis:100%;color:#7c8594;font-size:12px}
.topology-diagnostics-panel{flex:0 0 auto;margin-top:10px;padding:10px 14px;border:1px solid #d9d9d9;background:#fbfbfb;font-size:12px;max-height:220px;overflow:auto}
.topology-diagnostics-panel:empty{display:none;padding:0;border:none;margin:0}
.topology-diagnostics-panel h3{margin:0 0 6px;font-size:13px}
.topology-diagnostics-panel table{border-collapse:collapse;width:100%}
.topology-diagnostics-panel td,.topology-diagnostics-panel th{padding:2px 8px;text-align:left;border-bottom:1px solid #e5e7eb}
.topology-conflict-badge{display:inline-block;padding:1px 6px;border-radius:3px;background:#e74c3c;color:#fff;font-size:10px;font-weight:bold;margin-left:4px;vertical-align:middle}
.topology-lost-badge{display:inline-block;padding:1px 6px;border-radius:3px;background:#e08a1e;color:#fff;font-size:10px;font-weight:bold;margin-left:4px;vertical-align:middle}
.topology-node.conflict rect{stroke:#e74c3c;stroke-dasharray:2 2}
.topology-link.stale{stroke-dasharray:4 3;opacity:.55}
';

(new CHtmlPage())
	->setTitle(_('Topology prototype (tags model)'))
	->addItem(
		(new CDiv([
			(new CDiv([
				(new CTag('h1', true, _('Physical topology')))
			]))->addClass('topology-header'),
			(new CForm())
				->setId('topology-filter-bar')
				->addClass('topology-filter-bar')
				->addItem([
					(new CDiv([
						new CLabel(_('Host groups'), 'groupids_ms'),
						(new CMultiSelect([
							'name'        => 'groupids[]',
							'object_name' => 'hostGroup',
							'popup' => [
								'parameters' => [
									'srctbl'  => 'host_groups',
									'srcfld1' => 'groupid',
									'dstfrm'  => 'topology-filter-bar',
									'dstfld1' => 'groupids_'
								]
							]
						]))->setWidth(ZBX_TEXTAREA_FILTER_STANDARD_WIDTH)
					]))->addClass('topology-filter-field'),
					(new CDiv([
						new CLabel(_('Focus host'), 'hostid_ms'),
						(new CMultiSelect([
							'name'        => 'hostid',
							'object_name' => 'hosts',
							'multiple'    => false,
							'popup' => [
								'parameters' => [
									'srctbl'  => 'hosts',
									'srcfld1' => 'hostid',
									'dstfrm'  => 'topology-filter-bar',
									'dstfld1' => 'hostid'
								]
							]
						]))->setWidth(ZBX_TEXTAREA_FILTER_STANDARD_WIDTH),
						new CLabel(_('Depth'), 'topology-filter-hops'),
						(new CSelect('hops'))
							->setId('topology-filter-hops')
							->setValue('1')
							->addOptions(CSelect::createOptionsFromArray([
								1 => '1', 2 => '2', 3 => '3', 4 => '4', 5 => '5', 6 => '6'
							]))
					]))->addClass('topology-filter-field'),
					(new CButton('topology-filter-apply', _('Apply filter')))->addClass(ZBX_STYLE_BTN_ALT),
					(new CButton('topology-filter-clear', _('Show all')))->addClass(ZBX_STYLE_BTN_ALT),
					(new CDiv([
						(new CButton('topology-ingest-run', _('Run discovery ingest'))),
						(new CSpan(''))->setId('topology-ingest-status')->addClass('topology-ingest-status')
					]))->addClass('topology-filter-field'),
					// T-model spec §7 point 1: "a data-source switch (G/T) that selects the API
					// prefix. The two graphs must be viewable one after the other on the same lab
					// without restarting anything." G = CTopologyPrototype (topology.* actions,
					// ad-hoc host tags), T = CTopologyTModel (topot.* actions, LLD-collected
					// tags/items, topology-t-model-prototype-spec.md).
					(new CDiv([
						new CLabel(_('Model'), 'topology-model-select'),
						(new CSelect('model'))
							->setId('topology-model-select')
							->setValue('G')
							->addOptions(CSelect::createOptionsFromArray(['G' => 'G (graph tags)', 'T' => 'T (LLD tags)']))
					]))->addClass('topology-filter-field'),
					(new CButton('topology-diagnostics-button', _('Diagnostics')))->addClass(ZBX_STYLE_BTN_ALT),
					(new CDiv(_('Show whole host groups, or center the map on one host and limit how far out it '.
						'expands. A focus host overrides the group selection. "Show all" clears both and displays '.
						'every relation at once. The graph is derived live from Host tags on every load — there is '.
						'no separate discovery/pull step to run first.')))
						->addClass('topology-filter-hint')
				]),
			(new CDiv([
				(new CTag('svg', true))->setId('topology-canvas'),
				(new CDiv([
					(new CButton('topology-panel-toggle', '«'))
						->addClass('topology-panel-toggle')
						->setAttribute('title', _('Collapse')),
					(new CTag('h2', true, _('Device details')))->setId('topology-details-title'),
					(new CDiv(_('Select a host to inspect its ports and active problems, or an unmanaged device to promote it.')))->setId('topology-details')
				]))
					->setId('topology-panel')
					->addClass('topology-panel')
			]))->addClass('topology-workspace'),
			// §5.6/§7 point 6: T-model diagnostics panel (per-step timings/API call counts,
			// conflicts, malformed tags, dangling references, lost/silent side counts). Empty in
			// G-mode -- CTopologyPrototype has no equivalent instrumentation.
			(new CDiv(''))->setId('topology-diagnostics-panel')->addClass('topology-diagnostics-panel')
		]))->addClass('topology-prototype')
	)
	->show();

(new CTag('style', true, $page_styles))->show();

(new CScriptTag('view.init();'))->setOnDocumentReady()->show();
