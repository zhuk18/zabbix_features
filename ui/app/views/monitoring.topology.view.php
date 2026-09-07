<?php

$this->addJsFile('d3.js');
$this->includeJsFile('monitoring.topology.view.js.php');

(new CHtmlPage())
	->setTitle(_('Topology prototype'))
	->addItem(
		(new CDiv([
			(new CDiv([
				(new CTag('h1', true, _('Physical topology'))),
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
					(new CDiv(_('Select a device to inspect its ports.')))->setId('topology-details')
				]))->addClass('topology-panel')
			]))->addClass('topology-workspace')
		]))->addClass('topology-prototype')
		->addStyle('.topology-prototype{display:flex;flex-direction:column;height:calc(100vh - 180px);min-height:520px}.topology-header{display:flex;flex:0 0 auto;justify-content:space-between;align-items:center;margin-bottom:12px}.topology-header h1{margin:0}.topology-workspace{display:grid;grid-template-columns:180px minmax(0,1fr) 340px;grid-template-rows:minmax(0,1fr);flex:1 1 0;min-height:0;overflow:hidden;border:1px solid #d9d9d9;background:#fff}.topology-tray{grid-column:1;grid-row:1;min-width:0;min-height:0;overflow-y:auto;border-right:1px solid #d9d9d9;padding:12px}.topology-tray h2{margin-top:0;font-size:13px}.topology-tray-item{cursor:grab;user-select:none;background:#2b7dbc;color:#fff;border-radius:4px;padding:6px 8px;margin-bottom:6px;font-size:12px}.topology-tray-item-proxy{background:#6b46c1}.topology-tray-item-proxy::before{content:"P ";font-weight:bold}.topology-tray-item.dragging{opacity:.4}.topology-workspace>svg{grid-column:2;grid-row:1;width:100%;height:100%;min-width:0;min-height:0;overflow:hidden}.topology-workspace>svg.drop-target{background:#eef6fc}.topology-panel{grid-column:3;grid-row:1;min-width:0;min-height:0;overflow:auto;border-left:1px solid #d9d9d9;padding:16px}.topology-panel h2{margin-top:0}.topology-node{cursor:pointer}.topology-node.device rect{fill:#f3f4f6;stroke:#697386;stroke-width:2;stroke-dasharray:6 4}.topology-node.host rect{fill:#2b7dbc;stroke:#17577f;stroke-width:2}.topology-label{font-size:12px;pointer-events:none}.topology-link{stroke:#77818d;stroke-width:2}.topology-group{margin:14px 0}.topology-group h3{font-size:14px;margin:0 0 6px}.topology-group table{width:100%;border-collapse:collapse;font-size:12px}.topology-group th,.topology-group td{padding:5px;border-bottom:1px solid #e5e7eb;text-align:left}.topology-source{font-weight:bold}.topology-promote{margin-top:14px}'))
	->show();

(new CScriptTag('view.init();'))->setOnDocumentReady()->show();