<?php

$this->addJsFile('d3.js');
$this->addJsFile('topology.graph.js');
$this->addJsFile('multiselect.js');
$this->includeJsFile('monitoring.topology.view.js.php', ['legend_collapsed' => (int) ($data['legend_collapsed'] ?? 0)]);

// NOTE: CTag/CDiv::addStyle() writes into the element's style="" HTML attribute, which can only hold
// property:value declarations — it cannot hold selector rules. Every class-based rule below was silently
// discarded by the browser when this page used ->addStyle() for a whole stylesheet; a real <style> tag
// (below, via CTag('style', true, ...), same pattern as monitoring.dashboard.print.php) is required instead.
// CTag::addItem() also HTML-escapes a string body, so '>' combinators must be avoided here — #topology-canvas
// (an id selector) is used instead of '.topology-workspace>svg' for exactly that reason.
// topology-1-model.md §12.1 "Width" (2.38): the page keeps the Zabbix minimum width (main, header and footer have min-width 1200 px in
// blue-theme.css); from there the page has no horizontal scroll: the header row wraps, the port table fits the side panel.
$page_styles = '
.topology-prototype{display:flex;flex-direction:column;height:calc(100vh - 180px);min-height:520px}
.topology-header{display:flex;flex:0 0 auto;flex-wrap:wrap;justify-content:space-between;align-items:center;gap:8px 16px;margin-bottom:12px}
.topology-header h1{margin:0}
.topology-header-field{display:flex;align-items:center;gap:8px;font-size:12px}
.topology-header-field label{margin:0}
.topology-workspace{position:relative;display:grid;grid-template-columns:minmax(0,1fr) 400px;grid-template-rows:auto minmax(0,1fr);flex:1 1 0;min-height:0;overflow:hidden;border:1px solid #d9d9d9;background:#fff}
#topology-canvas{grid-column:1;grid-row:2;width:100%;height:100%;min-width:0;min-height:0;overflow:hidden}
.topology-panel{grid-column:2;grid-row:1 / span 2;min-width:0;min-height:0;overflow:auto;border-left:1px solid #d9d9d9;padding:16px;position:relative;transition:width .15s ease}
.topology-panel h2{margin-top:0;padding-right:28px}
.topology-wrap{display:block;white-space:normal}
.topology-more{position:sticky;bottom:-16px;margin:0 -16px -16px;padding:6px 16px;text-align:center;font-size:12px;color:#1f5a8a;cursor:pointer;background:linear-gradient(rgba(255,255,255,0),#fff 40%)}
.topology-more[hidden]{display:none}
.topology-panel-toggle{position:absolute;top:10px;right:10px;padding:2px 8px;line-height:1.4;font-size:13px;cursor:pointer;color:#1f2c33;background:#fff;border:1px solid #d9d9d9;border-radius:3px}
.topology-workspace.topology-panel-collapsed{grid-template-columns:minmax(0,1fr) 34px}
.topology-workspace.topology-panel-collapsed .topology-panel{padding:10px 4px;overflow:hidden}
.topology-workspace.topology-panel-collapsed .topology-panel h2,
.topology-workspace.topology-panel-collapsed .topology-panel #topology-details{display:none}
.topology-node{cursor:pointer}
.topology-node.device rect{fill:#f3f4f6;stroke:#697386;stroke-width:2;stroke-dasharray:6 4}
.topology-node.host rect{fill:#fff;stroke:#1f2937;stroke-width:2}
.topology-node.proxy rect{fill:#fff;stroke:#1f2937;stroke-width:2}
.topology-legend{position:absolute;left:8px;top:44px;z-index:3;max-width:300px;font-size:12px;background:#fff;border:1px solid #d9d9d9;border-radius:4px;box-shadow:0 1px 3px rgba(0,0,0,.12)}
.topology-legend-head{display:flex;justify-content:space-between;align-items:center;gap:12px;padding:5px 8px;cursor:pointer;font-weight:600;user-select:none}
.topology-legend-body{padding:2px 8px 8px;max-height:46vh;overflow:auto}
.topology-legend[hidden]{display:none}
.topology-legend h4{margin:8px 0 3px;font-size:11px;text-transform:uppercase;color:#55606b}
.topology-legend-row{display:flex;align-items:center;gap:8px;margin:3px 0}
.topology-legend-row svg{flex:0 0 44px}
.topology-label{font-size:12px;pointer-events:none}
.topology-link{stroke:#77818d;stroke-width:2}
.topology-group{margin:14px 0}
.topology-group h3{font-size:14px;margin:0 0 6px}
.topology-address-list{list-style:none;margin:0;padding:0}
.topology-address{padding:6px 0;border-bottom:1px solid #e5e7eb}
.topology-address-head{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.topology-address-value{font-family:monospace;font-size:12px;overflow:hidden;text-overflow:ellipsis;word-break:break-all}
.topology-address-count{font-size:11px;color:#55606b;cursor:pointer}
.topology-address-source{font-size:11px;color:#55606b;margin-top:2px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.topology-address-identity{width:100%;table-layout:auto;border-collapse:collapse;font-size:11.5px;margin-bottom:6px}
.topology-address-identity th{text-align:left;font-weight:600;padding:3px 6px 3px 0;white-space:nowrap;width:1%}
.topology-group .topology-address-identity{table-layout:auto}
.topology-group .topology-address-identity th{overflow:visible;text-overflow:clip}
.topology-address-identity td{padding:3px 4px 3px 0;vertical-align:middle}
.topology-copy-button{font-size:10px;padding:1px 7px;white-space:nowrap;margin-left:auto}
.topology-group-help{font-size:11.5px;color:var(--topology-muted,#666);margin:0 0 6px}
.topology-group table{width:100%;table-layout:fixed;border-collapse:collapse;font-size:11.5px}
.topology-col-port{width:22%}
.topology-col-status{width:18%}
.topology-col-source{width:58px}
.topology-col-action{width:58px}
.topology-group th,.topology-group td{padding:6px 6px;border-bottom:1px solid #e5e7eb;text-align:left;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;vertical-align:middle}
.topology-group td.topology-mac-list{white-space:normal;word-break:break-all;font-family:monospace;font-size:11px}
.topology-port-name{font-family:monospace;font-size:11px}
.topology-status-dot{display:inline-block;width:7px;height:7px;border-radius:50%;margin-right:5px;vertical-align:middle}
.topology-status-up{background:#2ecc71}
.topology-status-down{background:#e74c3c}
.topology-status-disabled{background:#94a3b8}
.topology-source-badge{display:inline-block;padding:1px 6px;border-radius:3px;font-size:10px;font-weight:600;background:#eef0f3;color:#3d4650;white-space:nowrap}
.topology-source-lldp{background:#e3edf7;color:#1f5a8a}
.topology-source-manual{background:#fdf1de;color:#8a5a1f}
.topology-promote{margin-top:14px}
.topology-promote-search{width:100%;box-sizing:border-box;margin-bottom:4px}
.topology-promote-results{max-height:140px;overflow-y:auto;border:1px solid #d9d9d9;border-radius:3px;margin-bottom:6px}
.topology-promote-results:empty{display:none;border:none}
.topology-promote-result{padding:5px 8px;font-size:12px;cursor:pointer}
.topology-promote-result:hover,.topology-promote-result.selected{background:#eef6fc}
.topology-promote-result-proxy::before{content:"P ";font-weight:bold;color:#6b46c1}
.topology-promote-selected{font-size:12px;color:#3d556a;margin-bottom:6px}
.topology-severity-badge{display:inline-block;padding:2px 8px;border-radius:3px;color:#fff;font-size:11px;font-weight:bold}
.topology-empty{color:#768d99;font-style:italic;padding:8px 0}
.topology-port-reports{display:block;font-size:11px;line-height:1.15;white-space:normal;overflow:visible;text-overflow:clip;font-family:inherit}
.topology-device-reports{margin-bottom:6px;font-size:12px}
.topology-link-pick{font-size:12px;color:#3d556a;background:#eef6fc;border:1px solid #bcd9ee;border-radius:4px;padding:6px 10px}
.topology-link-pick:empty{display:none}
.topology-link-pick a{margin-left:8px;color:#2b7dbc;cursor:pointer}
.topology-link-button,.topology-unlink-button{font-size:10px;padding:2px 7px;white-space:nowrap}
.topology-filter-bar{display:flex;align-items:center;gap:8px 12px;flex-wrap:nowrap;flex:0 0 auto;margin-bottom:12px;padding:6px 10px;border:1px solid #d9d9d9;background:#fbfbfb}
.topology-run-line{font-size:12px;color:#3d4650}
.topology-filter-field{display:flex;align-items:center;gap:6px;flex-wrap:nowrap;white-space:nowrap}
.topology-filter-bar label{font-size:12px;color:#1f2933;margin-right:4px}
.topology-node.topology-hover .topology-shape{stroke:#0b6fb8!important;stroke-width:4!important}
.topology-link-glow{fill:none;stroke:transparent;pointer-events:none}
.topology-link-glow.topology-link-hover{stroke:#2b7dbc;stroke-opacity:.45}
.topology-link-glow.topology-link-selected{stroke:#0275b8;stroke-opacity:.8}
.topology-not-in-view{margin:0 0 6px;color:#6b7280;font-style:italic}
.topology-node-head{margin-bottom:10px}
.topology-node-name{display:flex;align-items:center;gap:6px;flex-wrap:wrap;font-size:16px;font-weight:bold;padding-right:30px}
.topology-node-icon{flex:0 0 auto}
.topology-host-name,.topology-node-title{font-size:16px;font-weight:bold;word-break:break-all}
.topology-node-kind{font-size:11px;font-weight:normal;color:#3d556a;background:#eef2f5;border:1px solid #d6dde2;border-radius:3px;padding:1px 6px}
.topology-node-badges{margin-top:4px;display:flex;flex-wrap:wrap;gap:4px}
.topology-text-badge{font-size:11px;color:#7c2d12;background:#fff4e5;border:1px solid #f0c78a;border-radius:3px;padding:1px 6px}
.topology-node-actions{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-top:8px;font-size:12px}
.topology-node-summary{margin-top:6px;font-size:12px;color:#3d4650}
.topology-menu{position:relative;display:inline-block}
.topology-menu-button{padding:0 5px;font-size:16px;line-height:1.3;cursor:pointer;color:#1f2c33;background:#fff;border:1px solid #d9d9d9;border-radius:3px}
.topology-menu-list{position:fixed;z-index:1001;margin:0;padding:4px 0;list-style:none;min-width:140px;background:#fff;border:1px solid #b9c4cc;border-radius:3px;box-shadow:0 3px 10px rgba(0,0,0,.2)}
.topology-menu-list[hidden]{display:none}
.topology-menu-item{display:block;width:100%;box-sizing:border-box;text-align:left;padding:5px 12px;font-size:12px;color:#1f2c33;background:none;border:0;cursor:pointer;text-decoration:none;white-space:nowrap}
.topology-menu-item:hover{background:#eef6fc}
.topology-section{margin:10px 0;border-top:1px solid #e1e6ea;padding-top:6px}
.topology-section>summary{cursor:pointer;font-weight:bold;font-size:13px;padding:2px 0}
.topology-port-group>summary{cursor:pointer;font-weight:bold;font-size:12px;margin:6px 0 2px}
.topology-links-table{width:100%;border-collapse:collapse;table-layout:fixed;font-size:12px}
.topology-links-table td.topology-link-main{padding:4px 3px;border-bottom:1px solid #eef1f3;overflow:visible}
.topology-links-table tr[hidden]{display:none}
.topology-link-line1{display:flex;align-items:center;justify-content:space-between;gap:6px;white-space:nowrap}
.topology-link-name{min-width:0;overflow:hidden;font-size:13px}
.topology-link-line2{margin-top:1px;font-size:11px;color:#6b7782;line-height:1.35}
.topology-link-line2 .topology-sep{color:#9aa5ae}
.topology-link-mark{color:#7c2d12}
.topology-link-row.topology-row-hover td{background:#e3f0fa}
.topology-neighbor{color:#2b7dbc;cursor:pointer}
span.topology-neighbor{color:inherit;cursor:default}
.topology-bundle-toggle{border:0;background:none;cursor:pointer;padding:0 3px 0 0;font-size:11px;color:#3d556a}
.topology-link-member td.topology-link-main{padding-left:18px}
.topology-problem-counts{margin:4px 0}
.topology-problems-table{width:100%;border-collapse:collapse;font-size:12px}
.topology-problems-table td{padding:2px 3px;border-bottom:1px solid #eef1f3;white-space:nowrap}
.topology-problems-all{margin-top:4px;font-size:12px}
.topology-identity>summary~table{margin-top:4px}
.topology-group-fold{margin:8px 0}
.topology-back{margin:0 0 6px}
.topology-back-button{max-width:100%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.topology-toolbar{grid-column:1;grid-row:1;display:flex;align-items:center;gap:8px;padding:6px 8px;border-bottom:1px solid #d9d9d9;background:#f7f8f9}
.topology-toolbar button{padding:2px 12px}
.topology-toolbar button[aria-pressed=true]{background:#e7f0f7}
.topology-dialog-error{display:block;margin:0 0 8px;padding:6px 8px;color:#fff;background:#e45959;border-radius:2px}
.topology-dialog-error[hidden]{display:none}
.topology-port-table th{text-align:left;font-weight:normal;color:#768d99;padding:0 4px 2px 0}
.topology-port-table td{padding:0 4px 4px 0}
.topology-port-table input{width:150px}
.topology-tooltip{position:fixed;z-index:1000;max-width:420px;padding:5px 8px;font-size:12px;line-height:1.35;color:#fff;background:rgba(31,41,55,.95);border-radius:3px;white-space:pre-line;pointer-events:none}
.topology-tooltip[hidden]{display:none}
.topology-group-row{cursor:pointer}
.topology-group-row:hover{background:#eef6fc}
';

(new CHtmlPage())
	->setTitle(_('Topology prototype'))
	->addItem(
		(new CDiv([
			// §12.1 "Page header and filter bar" (2.70): the title, "Run discovery ingest", the state of the run and the time of the last run as one line of text, the link "Last run"
			(new CDiv([
				(new CTag('h1', true, _('Physical topology'))),
				(new CDiv())->setId('topology-link-pick')->addClass('topology-link-pick'),
				(new CButton('topology-ingest-run', _('Run discovery ingest')))->addClass(ZBX_STYLE_BTN_ALT),
				(new CSpan(''))->setId('topology-run-line')->addClass('topology-run-line')
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
						]))->setWidth(120)
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
						]))->setWidth(120)
					]))->addClass('topology-filter-field'),
					(new CDiv([
						new CLabel(_('Depth'), 'topology-filter-hops'),
						(new CSelect('hops'))
							->setId('topology-filter-hops')
							->setValue('1')
							->addOptions(CSelect::createOptionsFromArray([
								'' => _('No limit'), 1 => '1', 2 => '2', 3 => '3', 4 => '4', 5 => '5', 6 => '6'
							])),
						// the help text of the view scope is in a hint, not as fixed text above the graph (§12.1)
						makeHelpIcon(_('The Hosts of the selected host groups and the focus host are the start nodes. The view shows every '.
							'node at a distance of Depth or less from a start node (no Depth: no limit). Distance counts only links: one '.
							'physical link or device-level link is 1; a Device and its Host or Proxy, and a Host and its monitoring Proxy, '.
							'are never a hop. Only links whose two ends are in the view are drawn. Each node is drawn as its Host or Proxy, '.
							'also at the edge of the view. "Reset" clears the selection and displays every node.'))
					]))->addClass('topology-filter-field'),
					// §12.1 (2.47, FR-C V-16): only the Devices that have a duplicate candidate; V-1: the Devices without a link are drawn too (small); both are part of the filter
					(new CDiv(
						(new CCheckBox('only_duplicates'))->setId('topology-filter-duplicates')->setLabel(_('Possible duplicates only'))
					))->addClass('topology-filter-field'),
					(new CDiv(
						(new CCheckBox('show_unlinked'))->setId('topology-filter-unlinked')->setLabel(_('Show devices without links'))
					))->addClass('topology-filter-field'),
					(new CButton('topology-filter-apply', _('Apply')))->addClass(ZBX_STYLE_BTN_ALT),
					(new CButton('topology-filter-clear', _('Reset')))->addClass(ZBX_STYLE_BTN_ALT)
				]),
			(new CDiv([
				// the graph toolbar (§12.1, 2.85): Fit, Create device and Legend in one row above the graph area
				(new CDiv([
					(new CButton('topology-fit', _('Fit')))->addClass(ZBX_STYLE_BTN_ALT),
					(new CButton('topology-create-device', _('Create device')))->addClass(ZBX_STYLE_BTN_ALT),
					(new CButton('topology-legend-button', _('Legend')))->addClass(ZBX_STYLE_BTN_ALT)->setAttribute('aria-pressed', 'false')
				]))->setId('topology-toolbar')->addClass('topology-toolbar'),
				(new CTag('svg', true))->setId('topology-canvas'),
				(new CDiv([
					(new CButton('topology-panel-toggle', '«'))
						->addClass('topology-panel-toggle')
						->setAttribute('title', _('Collapse')),
					(new CTag('h2', true, _('Device details')))->setId('topology-details-title'),
					(new CDiv(_('Select a device to inspect its ports, a host to see its active problems, or a link to see its details.')))->setId('topology-details'),
					(new CDiv('▾ '._('more below')))->setId('topology-more-hint')->addClass('topology-more')->setAttribute('hidden', 'hidden')
				]))
					->setId('topology-panel')
					->addClass('topology-panel')
			]))->addClass('topology-workspace')
		]))->addClass('topology-prototype')
	)
	->show();

(new CTag('style', true, $page_styles))->show();

(new CScriptTag('view.init();'))->setOnDocumentReady()->show();
