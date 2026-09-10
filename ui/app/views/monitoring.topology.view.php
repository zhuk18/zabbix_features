<?php

$this->addJsFile('d3.js');
$this->addJsFile('multiselect.js');
$this->includeJsFile('monitoring.topology.view.js.php');

// NOTE: CTag/CDiv::addStyle() writes into the element's style="" HTML attribute, which can only hold
// property:value declarations — it cannot hold selector rules. Every class-based rule below was silently
// discarded by the browser when this page used ->addStyle() for a whole stylesheet; a real <style> tag
// (below, via CTag('style', true, ...), same pattern as monitoring.dashboard.print.php) is required instead.
// CTag::addItem() also HTML-escapes a string body, so '>' combinators must be avoided here — #topology-canvas
// (an id selector) is used instead of '.topology-workspace>svg' for exactly that reason.
$page_styles = '
.topology-prototype{display:flex;flex-direction:column;height:calc(100vh - 180px);min-height:520px}
.topology-header{display:flex;flex:0 0 auto;justify-content:space-between;align-items:center;margin-bottom:12px}
.topology-header h1{margin:0}
.topology-header-field{display:flex;align-items:center;gap:8px;font-size:12px}
.topology-header-field label{margin:0}
.topology-workspace{display:grid;grid-template-columns:180px minmax(0,1fr) 400px;grid-template-rows:minmax(0,1fr);flex:1 1 0;min-height:0;overflow:hidden;border:1px solid #d9d9d9;background:#fff}
.topology-tray{grid-column:1;grid-row:1;min-width:0;min-height:0;overflow-y:auto;border-right:1px solid #d9d9d9;padding:12px}
.topology-tray h2{margin-top:0;font-size:13px}
.topology-tray-item{cursor:grab;user-select:none;background:#2b7dbc;color:#fff;border-radius:4px;padding:6px 8px;margin-bottom:6px;font-size:12px}
.topology-tray-item-proxy{background:#6b46c1}
.topology-tray-item-proxy::before{content:"P ";font-weight:bold}
.topology-tray-item.dragging{opacity:.4}
#topology-canvas{grid-column:2;grid-row:1;width:100%;height:100%;min-width:0;min-height:0;overflow:hidden}
#topology-canvas.drop-target{background:#eef6fc}
.topology-panel{grid-column:3;grid-row:1;min-width:0;min-height:0;overflow:auto;border-left:1px solid #d9d9d9;padding:16px;position:relative;transition:width .15s ease}
.topology-panel h2{margin-top:0;padding-right:28px}
.topology-panel-toggle{position:absolute;top:10px;right:10px;padding:2px 8px;line-height:1.4;font-size:13px;cursor:pointer;background:#fff;border:1px solid #d9d9d9;border-radius:3px}
.topology-workspace.topology-panel-collapsed{grid-template-columns:180px minmax(0,1fr) 34px}
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
.topology-col-port{width:32%}
.topology-col-status{width:16%}
.topology-col-connected{width:26%}
.topology-col-source{width:15%}
.topology-col-action{width:64px}
.topology-group th,.topology-group td{padding:6px 6px;border-bottom:1px solid #e5e7eb;text-align:left;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;vertical-align:middle}
.topology-port-name{font-family:monospace;font-size:11px}
.topology-status-dot{display:inline-block;width:7px;height:7px;border-radius:50%;margin-right:5px;vertical-align:middle}
.topology-status-up{background:#2ecc71}
.topology-status-down{background:#e74c3c}
.topology-source-badge{display:inline-block;padding:1px 6px;border-radius:3px;font-size:10px;font-weight:600;background:#eef0f3;color:#3d4650;white-space:nowrap}
.topology-source-lldp{background:#e3edf7;color:#1f5a8a}
.topology-source-manual{background:#fdf1de;color:#8a5a1f}
.topology-promote{margin-top:14px}
.topology-severity-badge{display:inline-block;padding:2px 8px;border-radius:3px;color:#fff;font-size:11px;font-weight:bold}
.topology-empty{color:#768d99;font-style:italic;padding:8px 0}
.topology-link-pick{font-size:12px;color:#3d556a;background:#eef6fc;border:1px solid #bcd9ee;border-radius:4px;padding:6px 10px}
.topology-link-pick:empty{display:none}
.topology-link-pick a{margin-left:8px;color:#2b7dbc;cursor:pointer}
.topology-link-button,.topology-unlink-button{font-size:10px;padding:2px 7px;white-space:nowrap}
.topology-filter-bar{display:flex;align-items:flex-start;gap:8px 24px;flex-wrap:wrap;flex:0 0 auto;margin-bottom:12px;padding:8px 12px;border:1px solid #d9d9d9;background:#fbfbfb}
.topology-filter-field{display:flex;align-items:center;gap:8px 14px;flex-wrap:wrap}
.topology-filter-bar label{font-size:12px;color:#1f2933;margin-right:4px}
.topology-filter-hint{flex-basis:100%;color:#7c8594;font-size:12px}
';

(new CHtmlPage())
	->setTitle(_('Topology prototype'))
	->addItem(
		(new CDiv([
			(new CDiv([
				(new CTag('h1', true, _('Physical topology'))),
				(new CDiv())->setId('topology-link-pick')->addClass('topology-link-pick'),
				(new CDiv([
					new CLabel(_('Links'), 'topology-link-filter'),
					(new CSelect('link_filter'))
						->setId('topology-link-filter')
						->setValue('all')
						->addOptions(CSelect::createOptionsFromArray([
							'all' => _('Automatic + manual'),
							'lldp' => _('Automatic only'),
							'manual' => _('Manual only')
						]))
				]))->addClass('topology-header-field'),
				(new CButton('topology-host-pull', _('Pull Zabbix hosts & proxies')))->addClass(ZBX_STYLE_BTN_ALT)
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
					(new CDiv(_('Show whole host groups, or center the map on one host and limit how far out it '.
						'expands — depth counts represented_by/monitored_by links and LLDP-derived physical links '.
						'alike. A focus host overrides the group selection. "Show all" clears both and displays '.
						'every relation at once.')))
						->addClass('topology-filter-hint')
				]),
			(new CDiv([
				(new CDiv([
					(new CTag('h2', true, _('Unassigned hosts & proxies'))),
					(new CDiv(_('No unassigned hosts or proxies.')))->setId('topology-tray-list')
				]))->addClass('topology-tray'),
				(new CTag('svg', true))->setId('topology-canvas'),
				(new CDiv([
					(new CButton('topology-panel-toggle', '«'))
						->addClass('topology-panel-toggle')
						->setAttribute('title', _('Collapse')),
					(new CTag('h2', true, _('Device details'))),
					(new CDiv(_('Select a device to inspect its ports, or a host to see its active problems.')))->setId('topology-details')
				]))
					->setId('topology-panel')
					->addClass('topology-panel')
			]))->addClass('topology-workspace')
		]))->addClass('topology-prototype')
	)
	->show();

(new CTag('style', true, $page_styles))->show();

(new CScriptTag('view.init();'))->setOnDocumentReady()->show();
