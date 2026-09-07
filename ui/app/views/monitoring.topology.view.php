<?php

$this->addJsFile('d3.js');
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
.topology-workspace{display:grid;grid-template-columns:180px minmax(0,1fr) 400px;grid-template-rows:minmax(0,1fr);flex:1 1 0;min-height:0;overflow:hidden;border:1px solid #d9d9d9;background:#fff}
.topology-tray{grid-column:1;grid-row:1;min-width:0;min-height:0;overflow-y:auto;border-right:1px solid #d9d9d9;padding:12px}
.topology-tray h2{margin-top:0;font-size:13px}
.topology-tray-item{cursor:grab;user-select:none;background:#2b7dbc;color:#fff;border-radius:4px;padding:6px 8px;margin-bottom:6px;font-size:12px}
.topology-tray-item-proxy{background:#6b46c1}
.topology-tray-item-proxy::before{content:"P ";font-weight:bold}
.topology-tray-item.dragging{opacity:.4}
#topology-canvas{grid-column:2;grid-row:1;width:100%;height:100%;min-width:0;min-height:0;overflow:hidden}
#topology-canvas.drop-target{background:#eef6fc}
.topology-panel{grid-column:3;grid-row:1;min-width:0;min-height:0;overflow:auto;border-left:1px solid #d9d9d9;padding:16px}
.topology-panel h2{margin-top:0}
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
';

(new CHtmlPage())
	->setTitle(_('Topology prototype'))
	->addItem(
		(new CDiv([
			(new CDiv([
				(new CTag('h1', true, _('Physical topology'))),
				(new CDiv())->setId('topology-link-pick')->addClass('topology-link-pick'),
				(new CButton('topology-host-pull', _('Pull Zabbix hosts & proxies')))->addClass(ZBX_STYLE_BTN_ALT)
			]))->addClass('topology-header'),
			(new CDiv([
				(new CDiv([
					(new CTag('h2', true, _('Unassigned hosts & proxies'))),
					(new CDiv(_('No unassigned hosts or proxies.')))->setId('topology-tray-list')
				]))->addClass('topology-tray'),
				(new CTag('svg', true))->setId('topology-canvas'),
				(new CDiv([
					(new CTag('h2', true, _('Device details'))),
					(new CDiv(_('Select a device to inspect its ports, or a host to see its active problems.')))->setId('topology-details')
				]))->addClass('topology-panel')
			]))->addClass('topology-workspace')
		]))->addClass('topology-prototype')
	)
	->show();

(new CTag('style', true, $page_styles))->show();

(new CScriptTag('view.init();'))->setOnDocumentReady()->show();
