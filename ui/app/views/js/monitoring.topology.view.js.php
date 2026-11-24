<?php declare(strict_types = 0); ?>
<script>
const view = new class {
	// For the checks (task 116), not for the user: what the page is doing. `busy` counts the running actions (a load, a guarded action), `pending_requests` the requests in flight,
	// `loads_done` the loads that have finished, `finished_requests` the answers that have come. `idle` is true when nothing runs; a check waits for it (and for the state it expects)
	// instead of a fixed time. The layout is made in one synchronous pass of render(), so there is no animation to wait for.
	busy = 0;
	pending_requests = 0;
	finished_requests = 0;
	loads_done = 0;

	get idle() {
		return this.busy === 0 && this.pending_requests === 0 && !!this.first_load_done;
	}

	async request(action, options = {}) {
		this.pending_requests++;
		try {
			return await this.requestRun(action, options);
		}
		finally {
			this.pending_requests--;
			this.finished_requests++;
		}
	}

	async requestRun(action, options = {}) {
		// Every POST carries the CSRF token in its JSON body.
		if (options.method === 'POST') {
			options = {...options, body: JSON.stringify({
				...JSON.parse(options.body || '{}'),
				[<?= json_encode(CSRF_TOKEN_NAME) ?>]: <?= json_encode(CCsrfTokenHelper::get('topology')) ?>
			})};
		}
		const response = await fetch(`zabbix.php?action=${action}`, options);
		const payload = await response.json();
		if (!response.ok || payload.error) {
			throw new Error(payload.error?.messages?.join('\n') ?? `Topology request failed: ${response.status}`);
		}
		return payload;
	}

	// Every mutating action (promote, link, ...) can now be legitimately rejected by the backend (e.g. the
	// 1:1 represented_by constraint of topology-1-model.md "`represented_by` (Device → Host or Proxy)") — without this, request()'s thrown Error was an unhandled promise
	// rejection: the click did nothing visible and the failure only showed up in the browser console.
	async guard(action) {
		this.busy++;
		try {
			await action();
		}
		catch (error) {
			alert(error.message);
		}
		finally {
			this.busy--;
		}
	}

	escape(value) {
		return String(value ?? '').replace(/[&<>'"]/g, character => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;'}[character]));
	}

	// The panel's outer section heading ("Device details" / "Link details") — distinct from the
	// per-selection <h2> that showProblems()/showPorts()/selectLink() each render inside
	// #topology-details for the specific node/link's own name. Kept in sync with whatever kind
	// of thing is currently selected, not left stuck on whichever label happened to render last.
	setDetailsTitle(text) {
		const title = document.getElementById('topology-details-title');
		title.hidden = false;
		title.textContent = text;
	}

	// A node panel has no title of its own (§12.5, 2.75): its header is the title.
	hideDetailsTitle() {
		document.getElementById('topology-details-title').hidden = true;
	}

	async init() {
		this.canvas = d3.select('#topology-canvas');
		this.details = document.getElementById('topology-details');
		this.details_hint = this.details.innerHTML;      // the hint of the first load: an empty panel shows it again (model 2.96 §12.1)
		this.nodes = new Map();
		this.links = [];
		this.link_pick = null;
		this.unfolded = new Set();
		this.panel_stack = [];
		this.current_panel = null;
		this.new_manual = new Set();      // manual Devices created in this page and not dragged: when one gets its first link it is placed next to the linked node (§9.7, 2.84)
		this.replace_ids = new Set();      // the Devices that lose their place at the next reload
		this.include_ids = new Set();      // manual Devices created in this view stay in a scoped view until the filter is applied (§12.1, 2.80); 2.100: the nodes chosen in the search too
		this.include_hostids = new Set();  // 2.100: a Host without a Host node that was chosen in the search (a transient node of the answer)
		this.pinned = new Set();           // 2.100: the nodes chosen in the search are drawn also without a link, until the filter is applied
		this.leaf_groups = [];
		this.relayout = true;
		// the filter of the URL (§12.1 "Page header", 2.98): the server has set the controls; the page starts with what they show
		this.show_unlinked = document.getElementById('topology-filter-unlinked').checked;
		// The filter "possible duplicate" (topology-1-model.md §12.1, 2.47, 2.49; FR-C V-16, V-17): the server answers with the Devices that have a candidate (linked
		// or not), their partners and one hop of context; render() dims the context and draws the pair lines.
		this.only_duplicates = document.getElementById('topology-filter-duplicates').checked;
		// the run summary of the last run view links here with "Possible duplicates only" set (model §12.7)
		if (new URLSearchParams(window.location.search).get('only_duplicates') === '1') {
			this.only_duplicates = true;
			document.getElementById('topology-filter-duplicates').checked = true;
		}

		// Device details panel: collapsible so a wide canvas/graph gets the room back when the
		// panel isn't needed — purely a CSS class toggle (topology-panel-collapsed on the grid
		// container shrinks the panel's column), nothing about the loaded data changes.
		// the texts of the panel that were `title` attributes are hints of the standard hint box (section 7): they are converted when the panel is built
		new MutationObserver(() => this.hintify(document.getElementById('topology-panel'))).observe(document.getElementById('topology-panel'), {childList: true, subtree: true});
		const panel_toggle = document.getElementById('topology-panel-toggle');
		const workspace = document.querySelector('.topology-workspace');
		// topology-1-model.md §12.1 "Layout": the graph area reaches the bottom of the window, whatever the side panel holds (the panel scrolls by itself)
		this.fitToWindow();
		window.addEventListener('resize', () => {
			this.fitToWindow();
			this.render();
		});
		panel_toggle.addEventListener('click', () => {
			const collapsed = workspace.classList.toggle('topology-panel-collapsed');
			panel_toggle.textContent = collapsed ? '«' : '»';      // the glyph shows where the panel goes on a click: expanded »  (to the right), collapsed «  (back to the left)
			panel_toggle.dataset.hintboxHtml = this.hintHtml(collapsed ? <?= json_encode(_('Expand')) ?> : <?= json_encode(_('Collapse')) ?>);
			// The CSS transition resizes the canvas column; re-render once it's settled so the
			// graph actually uses the space instead of sitting at its old (now stale) viewBox
			// until some unrelated interaction happens to trigger the next render().
			setTimeout(() => this.render(), 160);
		});
		this.buildLegend(<?= json_encode((bool) ($data['legend_collapsed'] ?? 0)) ?>);
		// the graph toolbar (§12.1, 2.85): "Fit" puts the whole graph in the window (2.72), "Create device" opens the dialog (§9.7), "Legend" opens and closes the legend over the graph
		// task 117 section 2: Fit, zoom in and zoom out are icon buttons in the corner of the graph area; "Create device" is a button of the page header (not drawn in kiosk mode)
		document.getElementById('topology-fit').addEventListener('click', () => this.fit());
		document.getElementById('topology-zoom-in').addEventListener('click', () => this.zoomBy(1.4));
		document.getElementById('topology-zoom-out').addEventListener('click', () => this.zoomBy(1 / 1.4));
		document.getElementById('topology-create-device')?.addEventListener('click', () => this.guard(() => this.deviceDialog(null)));
		this.initFind();
		// Manual links contradicted by discovery (topology-1-model.md "Contradiction"): the buttons live in
		// the details panel, which is rebuilt on every selection, so one delegated handler serves them all.
		this.details.addEventListener('click', event => {
			const button = event.target.closest('[data-topology-manual]');
			if (button) {
				this.guard(() => this.manualAction(button.dataset.topologyManual, button.dataset.edge, button.dataset.device));
			}
		});
		// Duplicate candidates (topology-1-model.md §6.7.5): Merge / Accept new chassis / Not the same device (and revoke), in the Device panel
		this.details.addEventListener('click', event => {
			const button = event.target.closest('[data-topology-dup]');
			if (button) {
				this.guard(() => this.duplicateAction(button.dataset.topologyDup, button.dataset));
			}
		});
		// A hint that more sections follow below in the panel (the panel scrolls, nothing else shows it)
		const panel = document.getElementById('topology-panel');
		const hint = document.getElementById('topology-more-hint');
		const update_hint = () => {
			hint.hidden = !(panel.scrollHeight - panel.scrollTop - panel.clientHeight > 8);
		};
		panel.addEventListener('scroll', update_hint);
		new ResizeObserver(update_hint).observe(this.details);
		hint.addEventListener('click', () => panel.scrollBy({top: panel.clientHeight * 0.8, behavior: 'smooth'}));
		// the state of the run and the time of the last run are one line of text (§12.1 "Page header")
		this.run_line_element = document.getElementById('topology-run-line');
		this.run_state = '';
		this.run_info = {text: ''};
		// in kiosk mode the header (the title and the controls) is not drawn: the button is not there, the state of the run is still read
		document.getElementById('topology-ingest-run')?.addEventListener('click', () => this.guard(async () => {
			await this.runIngest();
		}));
		// A run may already be in progress from a previous page load (button click before a reload) or
		// from the CLI — reflect that on load instead of only ever noticing it after this tab's own click.
		this.pollIngestStatus({ignore_idle: true});
		this.refreshRunInfo();
		// Hostgroup / host+hops scope: read on "Apply", not on every multiselect change — a
		// group multiselect can see several add/remove events in a row while the user is still
		// picking, and firing a request per keystroke there would be wasteful.
		// Apply reads every field of the filter, the two switches too, and starts the layout again (a dragged node stays only until then, §12.1)
		document.getElementById('topology-filter-apply').addEventListener('click', () => this.guard(async () => {
			this.only_duplicates = document.getElementById('topology-filter-duplicates').checked;
			this.show_unlinked = document.getElementById('topology-filter-unlinked').checked;
			this.clearFound();
			this.writeFilterToUrl();
			await this.loadDevices({reset_layout: true, select_focus: true});
		}));
		// Reset (the former "Show all"): clears the selection and the switches and shows every node (Depth 1 as the page starts)
		document.getElementById('topology-filter-clear').addEventListener('click', () => this.guard(async () => {
			jQuery('#groupids_').multiSelect('clean');
			jQuery('#hostid').multiSelect('clean');
			document.getElementById('topology-filter-hops').value = '1';
			document.getElementById('topology-filter-duplicates').checked = false;
			document.getElementById('topology-filter-unlinked').checked = false;
			this.only_duplicates = false;
			this.show_unlinked = false;
			this.clearFound();
			this.writeFilterToUrl();
			await this.loadDevices({reset_layout: true, select_focus: true});
		}));
		// the first layout waits for the fonts (the widths of the labels are measured with the real font, §12.3, 2.79)
		try {
			await document.fonts.ready;
		}
		catch (error) {
		}
		await this.loadDevices({select_focus: true});
		this.first_load_done = true;      // the checks wait for it (the page makes no first panel since 2.95)
	}

	// "Run discovery ingest" (topology-1-model.md §6.1, FR-C O-7, V-21): the page only REQUESTS a run (POST topology.ingest.run); the scheduled CLI job executes it. The status shows
	// the state: "Run requested" until a run takes the request, "Reconciliation in progress" while a run works, and "The reconciliation does not run. Check the scheduled CLI job."
	// when the request waits for more than 5 minutes (`executor_missing`). this.ingest_polling guards against a second poll loop.
	async runIngest() {
		if (this.ingest_polling) {
			return;
		}
		await this.request('topology.ingest.run', {
			method: 'POST', headers: {'Content-Type': 'application/json'}, body: '{}'
		});
		this.requested_here = true;
		this.pollIngestStatus();
	}

	// "Accept discovery" records a request too (no run in the web request): the change applies at the next run, and the page says so.
	async waitSystemIngest(answer) {
		this.requested_here = true;
		alert(answer?.message ?? <?= json_encode(_('The change applies at the next run of the reconciliation.')) ?>);
		this.pollIngestStatus();
		await this.loadDevices();
	}

	// FR-C V-21: the time of the last run, and "<N> reporters have new data since the last run" (only the time when N is 0). Read with the status, so it is polled with the three states of
	// the run (every 2 or 3 seconds while a run works or waits) and every 30 seconds otherwise.
	renderRunInfo(status) {
		const time = status.finished_at_text ?? '';
		this.setRunInfo({text: TopologyGraph.runInfoText(status.reporters_new_data ?? 0, time, {
			one: <?= json_encode(_('%1$s reporter has new data since the last run')) ?>,
			many: <?= json_encode(_('%1$s reporters have new data since the last run')) ?>,
			last: <?= json_encode(_('Last run: %1$s')) ?>
		})});
	}

	// One line: the state of the run (§12.7: in progress, requested, does not run), then the note about new data and the time of the last run.
	setRunState(text) {
		this.run_state = text;
		this.updateRunLine();
	}

	// "The reconciliation does not run. Check the scheduled CLI job." is a warning box under the header (server-rendered, hidden unless it applies), not text of the run state
	setExecutorMissing(missing) {
		const box = document.getElementById('topology-executor-missing');
		if (box) {
			box.hidden = !missing;
		}
	}

	setRunInfo(text) {
		this.run_info = text;
		this.updateRunLine();
	}

	updateRunLine() {
		if (!this.run_line_element) {
			return;
		}
		// one line of grey text in the page header (§12.1 "Page header", 2.98): the state of the run, then the note about new data and "Last run: <time>", separated by " · "; the time is text, not a link
		const info = this.run_info ?? {text: ''};
		this.run_line_element.textContent = [this.run_state, info.text].filter(text => text !== '').join(' · ');
	}

	async refreshRunInfo() {
		try {
			const status = await this.request('topology.ingest.status');
			this.renderRunInfo(status);
			if ((status.running || status.requested || status.status === 'running') && !this.ingest_polling) {
				this.pollIngestStatus();
			}
		}
		catch (error) {
			// the next round tries again; a failed read of the note is not an error of the page
		}
		setTimeout(() => this.refreshRunInfo(), 30000);
	}

	async pollIngestStatus({ignore_idle = false} = {}) {
		if (this.ingest_polling) {
			return;
		}

		const poll = async () => {
			let status;
			try {
				status = await this.request('topology.ingest.status');
				this.renderRunInfo(status);
			}
			catch (error) {
				this.ingest_polling = false;
				this.setRunState('');
				alert(error.message);
				return;
			}

			if (status.running || status.status === 'running') {
				this.setExecutorMissing(false);
				this.ingest_polling = true;
				this.requested_here = true;
				this.setRunState(<?= json_encode(_('Reconciliation in progress')) ?>);
				setTimeout(poll, 2000);
				return;
			}

			if (status.requested) {
				this.ingest_polling = true;
				this.setRunState(<?= json_encode(_('Run requested')) ?>);
				this.setExecutorMissing(!!status.executor_missing);
				setTimeout(poll, status.executor_missing ? 15000 : 3000);
				return;
			}

			const was_polling = this.ingest_polling;
			this.ingest_polling = false;
			this.setRunState('');
			this.setExecutorMissing(false);

			if (status.status === 'idle') {
				// Nothing has ever run — expected on a normal page load (ignore_idle's caller); not
				// reachable from runIngest()'s own poll, since that only starts once a run exists.
				return;
			}

			// Only show a toast for a run this tab actually knows just finished — not for a "done"/
			// "error" left over from long before this page was even loaded (ignore_idle's on-load call).
			if (!was_polling && ignore_idle) {
				return;
			}
			this.requested_here = false;

			if (status.status === 'done') {
				const summary = status.summary ?? {};
				alert(<?= json_encode(_('Discovery ingest finished.')) ?> +
					`\n${<?= json_encode(_('Devices created')) ?>}: ${summary.devices_created ?? 0}` +
					`\n${<?= json_encode(_('Devices updated')) ?>}: ${summary.devices_updated ?? 0}` +
					`\n${<?= json_encode(_('Ports created')) ?>}: ${summary.ports_created ?? 0}` +
					`\n${<?= json_encode(_('Links created')) ?>}: ${summary.links_created ?? 0}` +
					`\n${<?= json_encode(_('Links superseded')) ?>}: ${summary.links_superseded ?? 0}` +
					`\n${<?= json_encode(_('Links revived')) ?>}: ${summary.links_revived ?? 0}` +
					`\n${<?= json_encode(_('Ports with several neighbors')) ?>}: ${summary.ports_ambiguous ?? 0}`);
				await this.loadDevices();
			}
			else if (status.status === 'error') {
				alert(<?= json_encode(_('Discovery ingest failed:')) ?> + ' ' + (status.error ?? ''));
			}
		};

		// If we're only checking on load (ignore_idle) and the very first read is already 'running',
		// this still needs to mark ingest_polling before recursing so a same-tick click doesn't start
		// a second loop; poll() itself sets ingest_polling once it sees 'running'.
		await poll();
	}

	// Current hostgroup/host+hops filter state, read straight from the multiselects rather than
	// tracked separately — they're already the single source of truth for what's selected, and
	// a selected host overrides the group selection (same precedence as the community
	// network_topology module's own filter).
	// topology-1-model.md §12.1 "View scope": the Hosts of the selected groups and the focus host are the start nodes, together; Depth empty = no limit.
	// Nothing selected: no scope parameters, every node.
	// §12.1 "Page header" (2.98): the filter of the graph is in the URL (the same names as the page reads at its load), so that Back from the last run view returns to the graph
	// with its filter. The entry of the history is replaced, not added: Apply is no step of the history.
	writeFilterToUrl() {
		const url = new URLSearchParams({action: 'topology.view'});
		jQuery('#groupids_').multiSelect('getData').forEach(item => url.append('groupids[]', item.id));
		const hostid = jQuery('#hostid').multiSelect('getData')[0]?.id;
		if (hostid) {
			url.set('hostid', hostid);
		}
		url.set('hops', document.getElementById('topology-filter-hops').value);
		if (document.getElementById('topology-filter-duplicates').checked) {
			url.set('only_duplicates', '1');
		}
		if (document.getElementById('topology-filter-unlinked').checked) {
			url.set('show_unlinked', '1');
		}
		history.replaceState(history.state, '', `zabbix.php?${url.toString()}`);
	}

	filterQuery() {
		const hostid = jQuery('#hostid').multiSelect('getData')[0]?.id;
		const groupids = jQuery('#groupids_').multiSelect('getData').map(item => item.id);
		let query = groupids.map(id => `&groupids[]=${encodeURIComponent(id)}`).join('');
		if (hostid) {
			query += `&hostid=${encodeURIComponent(hostid)}`;
		}
		const hops = document.getElementById('topology-filter-hops').value;
		if (query !== '' && hops !== '') {
			query += `&hops=${encodeURIComponent(hops)}`;
		}
		if (this.only_duplicates) {
			query += '&duplicates=1';
		}
		this.include_ids.forEach(id => {
			query += `&include[]=${encodeURIComponent(id)}`;
		});
		this.include_hostids.forEach(id => {
			query += `&include_hostids[]=${encodeURIComponent(id)}`;
		});
		return query;
	}

	async loadDevices(options = {}) {
		this.busy++;
		try {
			return await this.loadDevicesRun(options);
		}
		finally {
			this.busy--;
			this.loads_done++;
		}
	}

	async loadDevicesRun({reselect_edge = null, reselect_device = null, reset_layout = false, select_focus = false} = {}) {
		const query = this.filterQuery();
		const {devices = [], relations = [], contradictions = [], leaf_groups = [], junctions = []} = await this.request(`topology.devices.get${query}`);
		this.contradictions = contradictions;
		this.leaf_groups = leaf_groups;
		this.junctions = junctions;       // the Junction projections (§12.6): a UI projection, nothing is stored
		if (reselect_edge === null && reselect_device === null) {
			this.panel_from = null;
		}
		// the focus host of the radial placement (§12.1): the Host chosen in the filter when this view was loaded
		this.applied_hostid = jQuery('#hostid').multiSelect('getData')[0]?.id ?? null;
		// positions: a dragged node stays until the page is loaded again or the filter is applied (§12.1); other reloads (after an action) keep every position
		const old_nodes = reset_layout ? new Map() : this.nodes;
		if (reset_layout || !this.view_size) {
			this.relayout = true;
			this.unfolded.clear();
			this.group_nodes?.clear();
			this.junction_cache?.clear();
			this.view_size = null;
		}
		devices.forEach(node => {
			if (node.type === 'host' || node.type === 'proxy') {
				node.linked = true;
			}
		});
		this.nodes = new Map(devices.map(node => {
			const before = old_nodes.get(String(node.id));
			if (before && !this.replace_ids.has(String(node.id))) {
				Object.assign(node, {x: before.x, y: before.y, fx: before.fx, fy: before.fy});
			}
			return [String(node.id), node];
		}));
		this.replace_ids.clear();
		// discovered_via/source_port/target_port/source_status/target_status/source_speed/
		// target_speed ride along for physical_link relations (dash-pattern provenance,
		// connectivity color, and the "Link details" panel — see render()/selectLink()) —
		// represented_by/monitored_by don't carry any of it and just get undefined, which every
		// place that reads them already guards against. All of it comes from getRelations()
		// itself (batched server-side, same cost regardless of edge count), present from the
		// very first render — no click-through-both-devices step needed to see it.
		this.links = relations.map(relation => ({
			source: String(relation.source), target: String(relation.target), type: relation.type,
			discovered_via: relation.discovered_via,
			source_port: relation.source_port, target_port: relation.target_port,
			source_port_id: relation.source_port_id, target_port_id: relation.target_port_id,
			source_status: relation.source_status, target_status: relation.target_status,
			source_speed: relation.source_speed, target_speed: relation.target_speed,
			// 2.100: the port alias (ifAlias) of each end and the mark "speed mismatch" (V-25; the speeds of the two ends in bps), both decided by the server
			source_alias: relation.source_alias ?? null, target_alias: relation.target_alias ?? null, speed_mismatch: relation.speed_mismatch ?? null,
			// the LAG of each end (2.72, §12.3 "LAG bundle"): the id and the name, when known
			source_lag_id: relation.source_lag_id ?? null, source_lag: relation.source_lag ?? null, target_lag_id: relation.target_lag_id ?? null, target_lag: relation.target_lag ?? null,
			stale: relation.stale, superseded_at: relation.superseded_at ?? null,
			// colour of the line (§12.3): severity of the problems on the items of the end ports
			severity: relation.severity ?? null, severity_name: relation.severity_name ?? null, color: relation.color ?? null,
			replaced_by_device: relation.replaced_by_device ?? null, edge_id: relation.edge_id ?? null,
			// physical_link only (topology-1-model.md "Lost precision"): sides that see only the far Device.
			lost_precision: relation.lost_precision ?? null, last_seen_port: relation.last_seen_port ?? null,
			// device_link only (topology-1-model.md "Link channels"): the bundled links between the two Devices.
			discovery_source: relation.discovery_source, count: relation.count ?? null, links: relation.links ?? null
		}));
		// topology-1-model.md "Monitoring assignment (`MONITORED_BY`) — live, not stored": monitoring assignment is no longer a stored edge — relations never carries a
		// 'monitored_by' entry. Synthesize the line here from each Host's own live-resolved
		// target_node_id (topology.devices.get, topology-1-model.md "API") instead; target_node_id is null when
		// server-monitored, or when the target proxy has no graph node yet (never pulled) — in the
		// latter case there's simply nothing to draw to yet, same as any other not-yet-loaded node
		// (GOTCHAS.md #5: render() already filters simulation_links to nodes that currently exist).
		devices.forEach(node => {
			if (node.type === 'host' && node.target_node_id !== null && node.target_node_id !== undefined) {
				this.links.push({source: String(node.id), target: String(node.target_node_id), type: 'monitored_by'});
			}
		});
		this.render();
		if (reselect_edge !== null) {
			const link = this.links.find(candidate => candidate.edge_id !== null && String(candidate.edge_id) === String(reselect_edge));
			if (link) {
				this.selectLink(this.resolvedLink(link));
				return;
			}
		}
		// the panel stays on the Device the operator acted on (a merge keeps K, §6.7.5), shown through its host when it has one
		if (reselect_device !== null) {
			const device = this.nodes.get(String(reselect_device));
			if (device && device.type === 'device') {
				const host = device.represented_hostid
					? devices.find(node => node.type === 'host' && String(node.hostid) === String(device.represented_hostid)) : null;
				await this.selectNode(host ?? device);
				return;
			}
		}
		// 2.95 (§12.1 "Selection after Apply"): Apply (and the first load of the page) selects the focus host and opens its panel; without a focus host nothing is selected and the panel is closed.
		// The page does not select the first Device of the list. A reload after an action leaves the panel as it is.
		if (select_focus) {
			const focus = this.applied_hostid === null ? null
				: devices.find(node => node.type === 'host' && String(node.hostid) === String(this.applied_hostid));
			if (focus) {
				await this.selectNode(focus);
			}
			else {
				this.clearSelection();
			}
		}
		else {
			await this.refreshPanel();
		}
	}

	// A reload after an action (model 2.96 §12.1 "Reload after an action"): the open panel reads its object again; an object that is gone (merged, deleted, an unlinked link) or not in the view
	// any more closes the panel and clears the selection.
	async refreshPanel() {
		if (!this.selection || !this.current_panel) {
			return;
		}
		if (this.selectionTarget(this.selection) === null) {
			this.clearSelection();
			return;
		}
		await this.current_panel.reopen();
	}

	// No node, link or panel selected (model 2.95 §12.1): the panel is empty and closed; it shows the hint of the first load (2.96)
	clearSelection() {
		this.select_token = (this.select_token ?? 0) + 1;
		this.details.innerHTML = this.details_hint;
		this.setDetailsTitle(<?= json_encode(_('Device details')) ?>);
		this.selected_node = null;
		this.current_panel = null;
		this.panel_stack = [];
		this.selection = null;
		this.applySelection(false);
	}

	// A link object as render() hands it to selectLink(): with source/target resolved to node objects.
	resolvedLink(link) {
		return {...link, source: this.nodes.get(String(link.source)) ?? {name: String(link.source)},
			target: this.nodes.get(String(link.target)) ?? {name: String(link.target)}};
	}

	// ---- Manual links contradicted by discovery (topology-1-model.md "Contradiction") ----

	contradictionOf(edge_id) {
		return (this.contradictions ?? []).find(entry => String(entry.edge_id) === String(edge_id)) ?? null;
	}

	// Every unacknowledged shadowing observation, with the manual link it hides behind.
	unacknowledgedShadows() {
		const shadows = [];
		(this.contradictions ?? []).forEach(entry => entry.shadows.forEach(shadow => {
			if (!shadow.acknowledged) {
				shadows.push({entry, shadow});
			}
		}));
		return shadows;
	}

	formatDate(unix) {
		return new Date(unix * 1000).toLocaleString();
	}

	// What discovery says, as plain text. Near end: the hidden neighbor is what the link's own port sees. Third party
	// (topology-1-model.md "Contradiction"): the hidden neighbor is the reporter, which sees an end of the link on its own port.
	shadowText(shadow) {
		return shadow.third_party
			? `${shadow.hidden_name ?? '?'} ${<?= json_encode(_('sees')) ?>} ${shadow.remote_name ?? '?'} ${<?= json_encode(_('on')) ?>} ${shadow.local_port ?? '?'}`
			: `${<?= json_encode(_('Discovery sees')) ?>} ${shadow.hidden_name ?? shadow.remote_key} ${<?= json_encode(_('on')) ?>} ${shadow.local_port ?? '?'}`;
	}

	// The Device at the other end of a hidden-neighbor line: the end of the manual link that the shadow is seen from.
	shadowFar(entry, shadow) {
		if (shadow.third_party) {
			return shadow.remote_name;
		}

		return String(shadow.local_device_id) === String(entry.device_a) ? entry.device_b_name : entry.device_a_name;
	}

	// Tooltip of the warning badge on a contradicted manual link: one line per unacknowledged hidden neighbor.
	contradictionSummary(entry) {
		return entry.shadows.filter(shadow => !shadow.acknowledged).map(shadow =>
			`${this.shadowText(shadow)} (${<?= json_encode(_('since')) ?>} ${this.formatDate(shadow.first_seen)})`
		).join('\n');
	}

	// Details of a manual link: who it hides, what the operator can do about each, what they already decided.
	manualLinkFragment(edge_id) {
		const entry = this.contradictionOf(edge_id);
		if (!entry) {
			return '';
		}
		const edge = this.escape(entry.edge_id);
		const parts = [`<h3>${this.escape(<?= json_encode(_('Manual link')) ?>)}</h3>`,
			`<p>${this.escape(<?= json_encode(_('Manual link, created')) ?>)} ${this.escape(this.formatDate(entry.created_at))}.</p>`];
		const unacknowledged = entry.shadows.filter(shadow => !shadow.acknowledged);
		if (unacknowledged.length) {
			parts.push(`<p><strong>${this.escape(<?= json_encode(_('Contradicted by discovery')) ?>)}</strong></p><ul>`);
			unacknowledged.forEach(shadow => {
				const buttons = shadow.hidden_device_id === null
					? `<em>${this.escape(<?= json_encode(_('unknown device: it cannot be acknowledged')) ?>)}</em> `
					: `<button type="button" class="btn-alt" data-topology-manual="keep" data-edge="${edge}" ` +
						`data-device="${this.escape(shadow.hidden_device_id)}">${this.escape(<?= json_encode(_('Keep manual')) ?>)}</button> `;
				parts.push(`<li>${this.escape(this.shadowText(shadow))}` +
					`${shadow.remote_port ? ' (' + this.escape(shadow.remote_port) + ')' : ''}, ` +
					`${this.escape(<?= json_encode(_('reported by')) ?>)} ${this.escape(shadow.reporter.name)}, ` +
					`${this.escape(<?= json_encode(_('since')) ?>)} ${this.escape(this.formatDate(shadow.first_seen))}<br>${buttons}` +
					`<button type="button" class="btn-alt" data-topology-manual="accept" data-edge="${edge}">` +
					`${this.escape(<?= json_encode(_('Accept discovery')) ?>)}</button></li>`);
			});
			parts.push('</ul>');
		}
		if (entry.acks.length) {
			parts.push(`<p>${this.escape(<?= json_encode(_('Acknowledged')) ?>)}:</p><ul>`);
			entry.acks.forEach(ack => parts.push(`<li>${this.escape(ack.device_name ?? ack.remote_key)} — ` +
				`${this.escape(<?= json_encode(_('kept by')) ?>)} ${this.escape(ack.by)} ${this.escape(<?= json_encode(_('on')) ?>)} ` +
				`${this.escape(this.formatDate(ack.at))} <button type="button" class="btn-alt" data-topology-manual="revoke" ` +
				`data-edge="${edge}" data-device="${this.escape(ack.device_id)}">${this.escape(<?= json_encode(_('Revoke')) ?>)}</button></li>`));
			parts.push('</ul>');
		}
		if (entry.confirmed_from.length) {
			parts.push(`<p>${this.escape(<?= json_encode(_('Confirmed from')) ?>)} ${entry.confirmed_from.map(name => this.escape(name)).join(', ')}.</p>`);
		}

		return `<section class="topology-group">${parts.join('')}</section>`;
	}

	// Details of a hidden neighbor: the manual links hiding it, acknowledged ones included.
	hiddenByFragment(device_id) {
		const rows = [];
		(this.contradictions ?? []).forEach(entry => {
			const shadow = entry.shadows.find(candidate => String(candidate.hidden_device_id) === String(device_id));
			const ack = entry.acks.find(candidate => String(candidate.device_id) === String(device_id));
			if (!shadow && !ack) {
				return;
			}
			const far = shadow ? this.shadowFar(entry, shadow) : entry.device_a_name;
			const state = ack
				? `${this.escape(<?= json_encode(_('kept by')) ?>)} ${this.escape(ack.by)} ${this.escape(<?= json_encode(_('on')) ?>)} ${this.escape(this.formatDate(ack.at))}`
				: this.escape(<?= json_encode(_('not acknowledged')) ?>);
			rows.push(`<li>${this.escape(<?= json_encode(_('Link hidden by manual link')) ?>)} ${this.escape(shadow?.local_port ?? entry.port_a_name ?? '?')} ` +
				`↔ ${this.escape(far ?? '?')}${shadow ? ' ' + this.escape(<?= json_encode(_('on')) ?>) + ' ' + this.escape(shadow.reporter.name) : ''} — ${state} ` +
				`<button type="button" class="btn-alt" data-topology-manual="open" data-edge="${this.escape(entry.edge_id)}">` +
				`${this.escape(<?= json_encode(_('Open link')) ?>)}</button></li>`);
		});

		return rows.length ? `<section class="topology-group"><h3>${this.escape(<?= json_encode(_('Hidden by a manual link')) ?>)}</h3><ul>${rows.join('')}</ul></section>` : '';
	}

	async manualAction(action, edge_id, device_id) {
		if (action === 'open') {
			const link = this.links.find(candidate => candidate.edge_id !== null && String(candidate.edge_id) === String(edge_id));
			if (link) {
				this.openFromPanel(() => this.selectLink(this.resolvedLink(link)));
			}
			return;
		}
		const post = (name, body) => this.request(name, {
			method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify(body)
		});
		if (action === 'keep') {
			await post('topology.manual.keep', {edge_id, device_id});
			await this.loadDevices({reselect_edge: edge_id});
		}
		else if (action === 'revoke') {
			await post('topology.manual.revoke', {edge_id, device_id});
			await this.loadDevices({reselect_edge: edge_id});
		}
		else if (action === 'accept') {
			if (!confirm(<?= json_encode(_('Delete this manual link and let discovery take the port?')) ?>)) {
				return;
			}
			const answer = await post('topology.manual.accept', {edge_id});
			// The discovered link is decided by a full ingest from the stored snapshots (a superseded one is revived): the server starts it as the
			// system process after the delete (and after any run already in progress); this page only waits for it and reloads.
			await this.waitSystemIngest(answer);
		}
	}

	// Duplicate candidates of a Device (topology-1-model.md §6.7.5, "Side panels"): every value shown comes from the network or from
	// the operator and is escaped (§13). Merge asks which Device to keep; the proposal is the one with a represented_by, else the older one.
	duplicatesFragment(device, only_device_id = null) {
		const rows = [];
		(device.duplicates ?? []).filter(candidate => only_device_id === null || String(candidate.device_id) === String(only_device_id)).forEach(candidate => {
			const other = candidate.device_id === null ? null : this.nodes.get(String(candidate.device_id));
			const title = this.escape(this.markLabel(candidate));
			if (candidate.device_id === null) {
				rows.push(`<li>${title}<br><button type="button" class="btn-alt" data-topology-dup="accept" data-device="${this.escape(device.id)}">` +
					`${this.escape(<?= json_encode(_('Accept new chassis')) ?>)}</button></li>`);
				return;
			}
			const mine_is_kept = Boolean(device.represented) !== Boolean(other?.represented)
				? Boolean(device.represented)
				: (device.created_at ?? 0) <= (other?.created_at ?? 0);
			const keep_button = (keep_id, remove_id, label, proposed) => `<button type="button" class="btn-alt" data-topology-dup="merge" ` +
				`data-keep="${this.escape(keep_id)}" data-remove="${this.escape(remove_id)}">${this.escape(<?= json_encode(_('Merge, keep')) ?>)} ` +
				`${this.escape(label ?? '?')}${proposed ? ' ' + this.escape(<?= json_encode(_('(proposed)')) ?>) : ''}</button> `;
			rows.push(`<li>${title}: ${this.escape(candidate.name ?? candidate.device_id)}<br>` +
				keep_button(device.id, candidate.device_id, this.deviceLabel(device), mine_is_kept) +
				keep_button(candidate.device_id, device.id, this.deviceLabel(other ?? {id: candidate.device_id, name: candidate.name}), !mine_is_kept) +
				`<button type="button" class="btn-alt" data-topology-dup="dismiss" data-a="${this.escape(device.id)}" data-b="${this.escape(candidate.device_id)}">` +
				`${this.escape(<?= json_encode(_('Not the same device')) ?>)}</button></li>`);
		});
		(only_device_id === null ? device.dismissed ?? [] : []).forEach(entry => rows.push(`<li>${this.escape(<?= json_encode(_('Not the same device')) ?>)}: ` +
			`${this.escape(entry.name ?? entry.device_id)} — ${this.escape(entry.by)} ${this.escape(<?= json_encode(_('on')) ?>)} ${this.escape(this.formatDate(entry.at))} ` +
			`<button type="button" class="btn-alt" data-topology-dup="revoke" data-a="${this.escape(device.id)}" data-b="${this.escape(entry.device_id)}">` +
			`${this.escape(<?= json_encode(_('Revoke')) ?>)}</button></li>`));

		// A group (§6.7.5 "Merge a group"): three or more Devices linked by candidates. One action merges all of them into the chosen Device; the proposal
		// is the Device with a represented_by, else the oldest.
		const group = (only_device_id === null ? device.duplicate_group ?? [] : []).map(id => this.nodes.get(String(id))).filter(Boolean);
		if (group.length >= 3) {
			const proposed = group.slice().sort((a, b) => (Boolean(b.represented) - Boolean(a.represented)) || ((a.created_at ?? 0) - (b.created_at ?? 0)) || (a.id - b.id))[0];
			rows.push(`<li>${this.escape(<?= json_encode(_('A group of Devices linked by candidates')) ?>)} (${group.length}):<br>` +
				`<select class="topology-group-keep">${group.map(member => `<option value="${this.escape(member.id)}"${member.id === proposed.id ? ' selected' : ''}>` +
					`${this.escape(this.deviceLabel(member))}</option>`).join('')}</select> ` +
				`<button type="button" class="btn-alt" data-topology-dup="mergegroup" data-ids="${this.escape(group.map(member => member.id).join(','))}">` +
				`${this.escape(<?= json_encode(_('Merge all into the selected Device')) ?>)}</button> ` +
				// 2.48: "Not the same device" for every pair of the group in one action (the revocation stays per pair)
				`<button type="button" class="btn-alt" data-topology-dup="dismissgroup" data-ids="${this.escape(group.map(member => member.id).join(','))}">` +
				`${this.escape(<?= json_encode(_('Not the same device (all of the group)')) ?>)}</button></li>`);
		}

		return rows.length ? `<section class="topology-group"><h3>${this.escape(<?= json_encode(_('Possible duplicates')) ?>)}</h3><ul>${rows.join('')}</ul></section>` : '';
	}

	// The label of a mark (model §6.7.5, FR-A behavior R-28), one text for the side panel and the pair line of the graph: "same address 10.0.0.5", "same name AP-7",
	// "same HW-ID aa:bb:…" (a chassis key shared by Devices or claimed by two hosts), "same device ID Core-SW1" (a shared `cdp` key), "possible hardware replacement".
	// The value comes from the network: the caller escapes it (HTML) or sets it as text (SVG).
	markLabel(candidate) {
		const value = candidate.value ?? '';
		const labels = {
			same_address: <?= json_encode(_('same address %1$s')) ?>,
			same_name: <?= json_encode(_('same name %1$s')) ?>,
			reporters_share_key: <?= json_encode(_('same HW-ID %1$s')) ?>,
			reporter_key_shared: <?= json_encode(_('same HW-ID %1$s')) ?>,
			reporter_cdp_key: <?= json_encode(_('same device ID %1$s')) ?>,
			reporter_chassis_change: <?= json_encode(_('possible hardware replacement')) ?>
		};
		const label = candidate.reason === 'key_shared'
			? (String(candidate.key ?? '').startsWith('cdp:') ? <?= json_encode(_('same device ID %1$s')) ?> : <?= json_encode(_('same HW-ID %1$s')) ?>)
			: labels[candidate.reason] ?? candidate.reason;
		return label.replace('%1$s', () => value);
	}

	pairReason(candidate) {
		return this.markLabel(candidate);
	}

	// The pair of a pair line (V-17): the Merge and Not the same device actions of that one pair
	selectPair(device, candidate) {
		this.setDetailsTitle(<?= json_encode(_('Possible duplicates')) ?>);
		const other = this.nodes.get(String(candidate.device_id));
		this.details.innerHTML = `<h2>${this.escape(this.deviceLabel(device))} ↔ ${this.escape(other ? this.deviceLabel(other) : candidate.name ?? candidate.device_id)}</h2>` +
			this.duplicatesFragment(device, candidate.device_id);
		this.registerPanel('pair:' + device.id + ':' + candidate.device_id, this.deviceLabel(device), () => this.selectPair(device, candidate), {kind: 'pair', key: 'pair:' + device.id + ':' + candidate.device_id});
	}

	// A Device named so that two Devices with one name can be told apart: name, chassis or address, id.
	deviceLabel(node) {
		const identity = node.chassis_id || node.mgmt_ip || '';
		return `${node.name ?? '?'} (${identity ? identity + ', ' : ''}#${node.id})`;
	}

	async duplicateAction(action, data) {
		const post = (name, body) => this.request(name, {
			method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify(body)
		});
		let stay = null;
		if (action === 'merge') {
			if (!confirm(<?= json_encode(_('Merge the two Devices? The removed one is deleted: its ports, links and observations move to the kept one.')) ?>)) {
				return;
			}
			await post('topology.devices.merge', {keep_id: data.keep, remove_id: data.remove});
			stay = data.keep;
		}
		else if (action === 'mergegroup') {
			const keep = this.details.querySelector('.topology-group-keep').value;
			const ids = data.ids.split(',').filter(id => id !== keep);
			if (!confirm(<?= json_encode(_('Merge all Devices of the group into the selected one? The others are deleted: their ports, links and observations move to the kept one.')) ?>)) {
				return;
			}
			await post('topology.devices.mergegroup', {keep_id: keep, remove_ids: ids});
			stay = keep;
		}
		else if (action === 'accept') {
			await post('topology.devices.acceptchassis', {id: data.device});
			stay = data.device;
		}
		else if (action === 'dismiss') {
			await post('topology.devices.dismissduplicate', {a: data.a, b: data.b});
			stay = data.a;
		}
		else if (action === 'dismissgroup') {
			await post('topology.devices.dismissgroup', {ids: data.ids.split(',')});
			stay = this.details.querySelector('.topology-group-keep')?.value ?? data.ids.split(',')[0];
		}
		else if (action === 'revoke') {
			await post('topology.devices.dismissduplicate', {a: data.a, b: data.b, revoke: 1});
			stay = data.a;
		}
		else if (action === 'delete') {
			// Delete Device (§6.7.5, 2.47): the operator deletes for real; the server refuses a Device with an association or a manual link
			// a manual Device (§9.7, 2.80) takes its manual links with it
			const manual = this.nodes.get(String(data.device))?.manual === true;
			if (!confirm(manual ? <?= json_encode(_('Delete this Device? Its ports and its manual links are deleted too.')) ?>
					: <?= json_encode(_('Delete this Device? Its ports and discovered links are deleted too. If a current neighbor row reports it again, ingest makes it again with a new id.')) ?>)) {
				return;
			}
			await post('topology.devices.delete', {id: data.device});
			this.include_ids.delete(String(data.device));
			this.clearSelection();
			await this.loadDevices();
			return;
		}
		await this.loadDevices({reselect_device: stay});
	}

	// The stack of panels (§12.5 "Back", 2.82). Every panel that is shown calls registerPanel(key, title, reopen). A panel that is opened from a list or a link of another panel is opened
	// with openFromPanel(): the panel before is put on the stack (with its state) and the new one gets "← Back to <title>". A click on the graph or Apply sets panel_from = null: a new stack.
	// A panel shown again with the same key (a refresh) keeps the stack.
	registerPanel(key, title, reopen, selection = null) {
		const previous = this.current_panel;
		if (this.panel_going_back) {
			// the stack was popped by back()
		}
		else if (this.panel_from) {
			this.panel_stack.push(this.panel_from);
		}
		else if (this.panel_from === null || !previous || previous.key !== key) {
			this.panel_stack = [];
		}
		this.panel_from = undefined;
		this.current_panel = {key, title, reopen};
		// the graph selection follows the panel shown (§12.1 "Selection", 2.83); an object that is not drawn has none, and its panel says so
		this.selection = selection;
		const drawn = selection === null || this.selectionDrawn(selection);
		if (!drawn) {
			this.details.insertAdjacentHTML('afterbegin', `<p class="topology-not-in-view">${this.escape(<?= json_encode(_('Not in this view')) ?>)}</p>`);
		}
		this.applySelection(!this.no_pan_for_panel);      // task 109, 110: no pan to the object of the panel that a fold or an unfold opens (set by withoutPan(); a panel that a click opens pans)
		const entry = this.panel_stack[this.panel_stack.length - 1];
		if (entry) {
			this.details.insertAdjacentHTML('afterbegin', `<div class="topology-back"><button type="button" class="btn-link topology-back-button" title="${this.escape(entry.title)}">` +
				`← ${this.escape(<?= json_encode(_('Back to %1$s')) ?>.replace('%1$s', entry.title))}</button></div>`);
			this.details.querySelector('.topology-back-button').addEventListener('click', () => this.guard(() => this.back()));
		}
	}

	// Runs `open` (a function that shows another panel) as a step from the panel that is shown now.
	openFromPanel(open, state = null) {
		this.panel_from = this.current_panel ? {...this.current_panel, state: state ?? this.capturePanelState()} : null;
		return open();
	}

	capturePanelState() {
		return {
			sections: Array.from(this.details.querySelectorAll('details')).map(element => element.open),
			bundles: Array.from(this.details.querySelectorAll('.topology-bundle-toggle')).map(button => button.getAttribute('aria-expanded') === 'true'),
			scroll: this.details.scrollTop
		};
	}

	restorePanelState(state) {
		const sections = this.details.querySelectorAll('details');
		state.sections.forEach((open, i) => {
			if (sections[i]) {
				sections[i].open = open;
			}
		});
		this.details.querySelectorAll('.topology-bundle-toggle').forEach((button, i) => {
			if (state.bundles[i] && button.getAttribute('aria-expanded') !== 'true') {
				button.click();
			}
		});
		this.details.scrollTop = state.scroll;
	}

	async back() {
		const entry = this.panel_stack.pop();
		if (!entry) {
			return;
		}
		this.panel_going_back = true;
		try {
			await entry.reopen();
		}
		finally {
			this.panel_going_back = false;
		}
		this.restorePanelState(entry.state);
	}

	// The panel of a node (§12.5 "Structure of a node panel", 2.75): a header, then the sections of the node kind. A Host or a Proxy shows the data of the Device that it represents
	// (node.device); a Device without association shows its own.
	async selectNode(node) {
		// two clicks of a double click start two selections; the panel of a later request (a fold shows the group) must not be overwritten by a late answer of an earlier one
		const token = this.select_token = (this.select_token ?? 0) + 1;
		if (!['host', 'proxy', 'device'].includes(node.type)) {
			return;
		}
		// §12.5 (2.85): the panel of a Device that a Host or a Proxy represents is the panel of that Host or Proxy, the node that is drawn; whatever opens a panel gets it (the first selection of a
		// page, a link, Back, a refresh). Task 101: the first selection of the page took the first Device of the list, which was the Device of SW12, not drawn: "Not in this view", "Not a Zabbix host".
		if (node.type === 'device' && node.represented) {
			node = this.nodeOfDevice(node.id) ?? node;
		}
		const device = node.type === 'device' ? node : node.device;
		const id = value => encodeURIComponent(value);
		// Problems: a Host only (Zabbix has no clean "this problem belongs to this proxy" semantics, so a Proxy never shows them), read live
		const [problems, groups, addresses] = await Promise.all([
			node.type === 'host' ? this.request(`topology.problems.get&id=${id(node.id)}`).then(answer => answer.problems) : null,
			device ? this.request(`topology.ports.get&id=${id(device.id)}`).then(answer => answer.groups) : null,
			device ? this.request(`topology.addresses.get&id=${id(device.id)}`) : null,
			device ? this.mergeNeighbors(device) : null
		]);
		// the near side of the load of the links comes from ports.get, the far side from neighbors.get (§4.8, 2.88)
		if (device && groups) {
			TopologyGraph.applyNearLoads(this.links, Object.values(groups).flat(), device.id);
		}
		if (device) {
			this.render();
		}
		if (token !== this.select_token) {
			return;
		}
		this.selected_node = node;
		this.showNodePanel(node, {device, problems, groups, addresses});
		this.registerPanel('node:' + node.id, node.label ?? node.name, () => this.selectNode(this.nodes.get(String(node.id)) ?? node), {kind: 'node', id: String(node.id)});
	}

	// Fetches /neighbors for `device` and folds the link facts (severity, ports, load) into the links of this view — shared by a
	// standalone Device click and a Host/Proxy click whose represented Device's neighbors need the
	// same treatment. Caller is responsible for this.render() afterwards (both callers already need to
	// do other awaited work first, so this doesn't render on their behalf).
	// topology-1-model.md §12.1 "View scope": the view draws the nodes of its scope and only the links whose two ends are in it. A neighbor that is not
	// in this.nodes is outside the scope: it is not added (before task 040 it was added as a bare Device, drawn dashed even when it is a lab host), and
	// a node that is in the scope keeps its own object (its Host/Proxy association comes from topology.devices.get).
	async mergeNeighbors(device) {
		const {neighbors} = await this.request(`topology.neighbors.get&id=${encodeURIComponent(device.id)}`);
		neighbors.forEach(neighbor => {
			if (!this.nodes.has(String(neighbor.id))) {
				return;
			}
			// A neighbor reached only through a device-level link: the node is all that is missing, the line comes
			// from the relations (they bundle device-level links) and must not become a physical_link here.
			if (neighbor.link_type === 'device_link') {
				return;
			}
			// severity is a link-level fact (derived from both endpoint ports' triggers), not a node fact —
			// carry it onto the physical_link edge object itself, refreshing it even if the link already
			// exists from a previous selection, since the underlying trigger state can have changed since.
			// Undirected match: the edge may already be in this.links from the initial devices.get load
			// (getRelations() now includes physical_link pairs up front) with either endpoint as "source" —
			// whichever device got clicked first here isn't necessarily the one that ended up as source
			// there, and an exact-order match would wrongly add a second, reversed duplicate of the same edge.
			const node_id = String(device.id), neighbor_id = String(neighbor.id);
			let link = this.links.find(candidate =>
				candidate.type === 'physical_link' &&
				((candidate.source === node_id && candidate.target === neighbor_id) ||
					(candidate.source === neighbor_id && candidate.target === node_id)));
			if (!link) {
				link = {source: node_id, target: neighbor_id, type: 'physical_link'};
				this.links.push(link);
			}
			link.discovered_via = neighbor.discovered_via;
			link.stale = neighbor.stale;
			link.superseded_at = neighbor.superseded_at ?? null;
			link.severity = neighbor.severity ?? null;
			link.severity_name = neighbor.severity_name ?? null;
			link.color = neighbor.color ?? null;
			// neighbor.local_*/remote_* are from the CLICKED device's (node's) point of view —
			// map them onto whichever of link.source/link.target actually IS node_id, same
			// orientation concern as the undirected match above.
			if (link.source === node_id) {
				link.source_port = neighbor.local_port;
				link.target_port = neighbor.remote_port;
				link.source_port_id = neighbor.local_port_id;
				link.target_port_id = neighbor.remote_port_id;
				link.source_status = neighbor.local_status;
				link.target_status = neighbor.remote_status;
				link.source_speed = neighbor.local_speed;
				link.target_speed = neighbor.remote_speed;
				link.source_load = neighbor.load?.local ?? null;
				link.target_load = neighbor.load?.remote ?? null;
				link.source_load_reason = neighbor.load_reason?.local ?? null;
				link.target_load_reason = neighbor.load_reason?.remote ?? null;
			}
			else {
				link.source_port = neighbor.remote_port;
				link.target_port = neighbor.local_port;
				link.source_port_id = neighbor.remote_port_id;
				link.target_port_id = neighbor.local_port_id;
				link.source_status = neighbor.remote_status;
				link.target_status = neighbor.local_status;
				link.source_speed = neighbor.remote_speed;
				link.target_speed = neighbor.local_speed;
				link.source_load = neighbor.load?.remote ?? null;
				link.target_load = neighbor.load?.local ?? null;
				link.source_load_reason = neighbor.load_reason?.remote ?? null;
				link.target_load_reason = neighbor.load_reason?.local ?? null;
			}
		});
	}

	// Link click: by the time a rendered <line>'s click handler fires, d3.forceLink has already
	// resolved link.source/link.target from the plain ids simulation_links started with (see
	// render()) into the actual node data objects — so .name/.type etc. are just there, no
	// lookup needed. represented_by edges never reach this: render() filters them out of
	// simulation_links entirely (a represented_by edge is never drawn as a line at all — the
	// Device side has no on-screen position once represented, so there's nothing to click).
	selectLink(link) {
		this.setDetailsTitle(<?= json_encode(_('Link details')) ?>);
		const type_labels = {
			physical_link: <?= json_encode(_('Physical link (LLDP/manual)')) ?>,
			device_link: <?= json_encode(_('Device-level link (far port unknown)')) ?>,
			monitored_by: <?= json_encode(_('Monitored by')) ?>
		};
		const status_labels = {
			up: <?= json_encode(_('Up')) ?>, down: <?= json_encode(_('Down')) ?>,
			disabled: <?= json_encode(_('Disabled')) ?>
		};
		const rows = [[<?= json_encode(_('Type')) ?>, type_labels[link.type] ?? link.type]];
		if (link.type === 'physical_link') {
			// Port names/status/speed all come from getRelations()' up-front payload already (or
			// selectNode()'s per-click refresh) — a missing value (device removed a port, or the
			// topo_nodes attrs never had one) just skips that row rather than showing a blank.
			if (link.source_port) {
				rows.push([`${this.escape(link.source.name)} ${<?= json_encode(_('port')) ?>}`, link.source_port]);
			}
			if (link.target_port) {
				rows.push([`${this.escape(link.target.name)} ${<?= json_encode(_('port')) ?>}`, link.target_port]);
			}
			rows.push([<?= json_encode(_('Discovered via')) ?>,
				link.discovered_via === 'manual'
					? <?= json_encode(_('Manual')) ?>
					: this.escape((link.discovery_source || 'lldp').toUpperCase())]);
			if (link.source_status) {
				rows.push([`${this.escape(link.source.name)} ${<?= json_encode(_('status')) ?>}`,
					this.statusCell(link.source_status, status_labels), true]);
			}
			if (link.target_status) {
				rows.push([`${this.escape(link.target.name)} ${<?= json_encode(_('status')) ?>}`,
					this.statusCell(link.target_status, status_labels), true]);
			}
			// A speed mismatch is a real, common misconfiguration (autonegotiation gone wrong) —
			// flagged on both speed rows plus its own note, rather than folded into the line
			// color: that channel already carries connectivity, and stacking a second meaning
			// onto the same color would make neither one reliably readable at a glance.
			// 2.100 (V-25): the server decides (both ends are up reporter ports with known, different speeds; not for a LAG aggregate); the mark is the same text as in the tooltip and the Links row
			const speed_mismatch = Boolean(link.speed_mismatch);
			if (link.source_speed) {
				rows.push([`${this.escape(link.source.name)} ${<?= json_encode(_('speed')) ?>}`,
					this.formatSpeed(link.source_speed) + (speed_mismatch ? ' ⚠' : '')]);
			}
			if (link.target_speed) {
				rows.push([`${this.escape(link.target.name)} ${<?= json_encode(_('speed')) ?>}`,
					this.formatSpeed(link.target_speed) + (speed_mismatch ? ' ⚠' : '')]);
			}
			if (speed_mismatch) {
				rows.push([<?= json_encode(_('Note')) ?>, this.speedMismatchText(link.speed_mismatch.source, link.speed_mismatch.target)]);
			}
			// load of each side: numbers and the graphs of the port items; no colour (§4.8)
			[[link.source, link.source_load, link.source_load_reason], [link.target, link.target_load, link.target_load_reason]].forEach(([node, load, reason]) => {
				const text = this.loadText(load);
				if (text !== '') {
					rows.push([`${this.escape(node.name)} ${<?= json_encode(_('load')) ?>}`, `<span class="topology-wrap">${this.escape(text)} ${this.loadGraphLinks(load)}</span>`, true]);
				}
				else if (reason) {
					// FR-C V-12 item 4 (2.93): a side with no items says why it has no data
					rows.push([`${this.escape(node.name)} ${<?= json_encode(_('load')) ?>}`, `<span class="topology-wrap">${this.escape(this.noDataText(reason))}</span>`, true]);
				}
			});
			if (link.severity !== null && link.severity !== undefined) {
				rows.push([<?= json_encode(_('Severity')) ?>, `<span class="topology-severity-badge" style="background:${this.cssColor(link.color)}">${this.escape(link.severity_name)}</span>`, true]);
			}
			if (link.superseded_at) {
				rows.push([<?= json_encode(_('Replaced')) ?>, this.replacedNote(link)]);
			}
			this.lostPrecisionNotes(link).forEach(note => rows.push([<?= json_encode(_('Not confirmed')) ?>, note]));
		}
		let device_link_actions = '';
		if (link.type === 'device_link') {
			// Row labels of a device-level link may carry already-escaped device names; escape() is idempotent on the
			// plain text rows, so the names go through it once more here only if they were not escaped yet.
			this.deviceLinkRows(link).forEach(([label, value]) => rows.push([label, value, false, true]));
			device_link_actions = this.deviceLinkActions(link);
		}
		// task 120 section 2: the rows of the link panel show their full text (a long value wraps; the Note of a speed mismatch was cut)
		const table = `<section class="topology-group"><table class="topology-full-rows"><tbody>${rows.map(([label, value, raw, label_escaped]) =>
			`<tr><th>${label_escaped ? label : this.escape(label)}</th><td>${raw ? value : this.escape(value)}</td></tr>`).join('')}</tbody></table></section>` +
			(['physical_link', 'device_link'].includes(link.type) && link.discovered_via === 'manual' && link.edge_id !== null && link.edge_id !== undefined
				? this.manualLinkFragment(link.edge_id) : '');
		// Only physical_link is a real, user/LLDP-declared topo_edges row a person can remove —
		// monitored_by is a synthesized line, not a stored row at all (topology-1-model.md "Monitoring assignment (`MONITORED_BY`) — live, not stored"): it's resolved live
		// from Zabbix host config on every load, so there's nothing to "delete" there — reassigning
		// the host to a different proxy in Zabbix is what changes it, not this UI.
		const delete_button = link.type === 'physical_link' && link.source_port_id && link.target_port_id
			? `<div class="topology-promote"><button type="button" class="btn-alt topology-delete-link-button">` +
				`${this.escape(<?= json_encode(_('Delete link')) ?>)}</button></div>`
			: '';
		// A manual physical_link can become a device-level one by keeping one of its ports (same edge id).
		const to_device_buttons = link.type === 'physical_link' && link.discovered_via === 'manual' && link.edge_id
			&& link.source_port_id && link.target_port_id
			? `<div class="topology-promote">${[[link.source_port_id, link.source_port], [link.target_port_id, link.target_port]]
				.map(([port_id, name]) => `<button type="button" class="btn-alt" data-topology-keep-port="${this.escape(port_id)}" ` +
					`data-edge="${this.escape(link.edge_id)}">${this.escape(<?= json_encode(_('Make device-level, keep')) ?>)} ${this.escape(name ?? '?')}</button>`).join(' ')}</div>`
			: '';
		this.details.innerHTML = `<h2>${this.escape(link.source.label ?? link.source.name)} ↔ ${this.escape(link.target.label ?? link.target.name)}</h2>${table}${device_link_actions}${delete_button}${to_device_buttons}`;
		this.wireDeviceLinkActions();
		this.registerPanel(link.edge_id ? 'link:' + link.edge_id : 'link:' + link.source.id + ':' + link.target.id,
			`${link.source.label ?? link.source.name} ↔ ${link.target.label ?? link.target.name}`, () => {
				const stored = link.edge_id ? this.links.find(candidate => candidate.edge_id !== null && String(candidate.edge_id) === String(link.edge_id)) : null;
				return this.selectLink(stored ? this.resolvedLink(stored) : link);
			}, link.edge_id ? {kind: 'line', edge_ids: [String(link.edge_id)]} : null);
		this.details.querySelectorAll('[data-topology-keep-port]').forEach(keep => keep.addEventListener('click', () => this.guard(async () => {
			await this.request('topology.link.convert', {
				method: 'POST', headers: {'Content-Type': 'application/json'},
				body: JSON.stringify({id: keep.dataset.edge, keep_port_id: keep.dataset.topologyKeepPort})
			});
			await this.loadDevices();
		})));
		const button = this.details.querySelector('.topology-delete-link-button');
		if (button) {
			button.addEventListener('click', () => this.guard(async () => {
				await this.request('topology.ports.unlink', {
					method: 'POST', headers: {'Content-Type': 'application/json'},
					body: JSON.stringify({id: link.source_port_id, dst_id: link.target_port_id})
				});
				// The link itself is gone, not just refreshable in place — both endpoints' data
				// changes, so a full reload (same as Apply and Reset) rather than a
				// targeted re-render.
				await this.loadDevices();
			}));
		}
	}

	// ---- Device-level links (topology-1-model.md "`device_link` (Port → Device)", "Link channels") ----

	// The far end's advertisement as received (untrusted text: callers escape it).
	hintText(hint) {
		if (!hint) {
			return '';
		}
		return String(hint.rem_port_desc || hint.rem_port || '');
	}

	// Operator-facing explanation of why the far port is unknown. Plain text: callers escape it.
	farPortReasonText(item, near_name, far_name, local_ports = '') {
		const hint = this.hintText(item.far_port_hint);
		switch (item.far_port_reason) {
			case 'port_unmatched':
				return `${far_name} ${<?= json_encode(_('reports its own ports, and none of them matches the port')) ?>} ` +
					`${near_name} ${<?= json_encode(_('sees')) ?>}${hint ? ` (${hint})` : ''}. ` +
					<?= json_encode(_('The link is drawn to the device until the port can be matched.')) ?>;
			case 'port_shared_id':
				return `${far_name} ${<?= json_encode(_('advertises the same identifier')) ?>}${hint ? ` (${hint})` : ''} ` +
					<?= json_encode(_('on several ports, so the exact port can\'t be told apart.')) ?>;
			case 'lag_ambiguous':
				return `${near_name} ${<?= json_encode(_('has several links to')) ?>} ${far_name}` +
					`${local_ports ? ` (${local_ports})` : ''} ` +
					<?= json_encode(_('whose remote ports can\'t be told apart, typically a link aggregation.')) ?>;
			case 'manual':
				return <?= json_encode(_('Created manually without a remote port')) ?> +
					`${item.created_by ? ' ' + <?= json_encode(_('by')) ?> + ' ' + (item.created_by_name ?? <?= json_encode(_('another user')) ?>) : ''}` +
					`${item.created_at ? ' ' + <?= json_encode(_('on')) ?> + ' ' + this.formatDate(item.created_at) : ''}.`;
			case 'fdb_mac_only':
				return <?= json_encode(_('Found by a learned MAC address (FDB) only; FDB doesn\'t tell which port of the device it is.')) ?>;
			default:
				return '';
		}
	}

	nodeName(id) {
		const node = this.nodes.get(String(id));
		return node ? (node.name ?? node.sysname ?? '?') : '?';
	}

	// The known local ports of a bundle, per side: "2 links: Gi0/1, Gi0/2 ↔ ?".
	deviceBundleLabel(link) {
		const ports_of = device_id => (link.links ?? []).filter(item => String(item.near_device) === String(device_id))
			.map(item => item.near_port).filter(Boolean).join(', ');
		const first = link.links[0];
		const left = ports_of(first.near_device) || '?';
		const right = ports_of(first.far_device) || '?';
		return `${link.count} ${<?= json_encode(_('links')) ?>}: ${left} ↔ ${right}`;
	}

	deviceLinkTooltip(link) {
		return (link.links ?? []).map(item => {
			const near = this.nodeName(item.near_device);
			const far = this.nodeName(item.far_device);
			const ports = (link.links ?? []).filter(other => String(other.near_device) === String(item.near_device))
				.map(other => other.near_port).filter(Boolean).join(', ');
			return `${near} ${item.near_port ?? '?'} → ${far}: ${this.farPortReasonText(item, near, far, ports)}`;
		}).join('\n');
	}

	// "Link details" of a device-level link (or bundle): one block per stored link, with its reason, and the actions.
	deviceLinkRows(link) {
		const rows = [];
		(link.links ?? []).forEach((item, index) => {
			const near = this.nodeName(item.near_device);
			const far = this.nodeName(item.far_device);
			const ports = (link.links ?? []).filter(other => String(other.near_device) === String(item.near_device))
				.map(other => other.near_port).filter(Boolean).join(', ');
			if (index > 0) {
				rows.push(['', '']);
			}
			rows.push([`${this.escape(near)} ${this.escape(<?= json_encode(_('port')) ?>)}`, item.near_port ?? '?']);
			rows.push([`${this.escape(far)} ${this.escape(<?= json_encode(_('port')) ?>)}`,
				<?= json_encode(_('unknown')) ?>]);
			rows.push([<?= json_encode(_('Why')) ?>, this.farPortReasonText(item, near, far, ports)]);
			if (item.far_port_hint) {
				rows.push([<?= json_encode(_('Advertised by the far end')) ?>, this.hintText(item.far_port_hint)]);
			}
			rows.push([<?= json_encode(_('Discovered via')) ?>, item.discovered_via === 'manual'
				? <?= json_encode(_('Manual')) ?> : (item.discovery_source || 'lldp').toUpperCase()]);
		});
		return rows;
	}

	deviceLinkActions(link) {
		return (link.links ?? []).map(item => {
			const label = `${this.nodeName(item.near_device)} ${item.near_port ?? '?'}`;
			const convert = item.discovered_via === 'manual'
				? `<button type="button" class="btn-alt" data-topology-device-link="convert" data-edge="${this.escape(item.edge_id)}" ` +
					`data-far="${this.escape(item.far_device)}">${this.escape(<?= json_encode(_('Set the far port')) ?>)}</button> `
				: '';
			return `<div class="topology-promote"><span>${this.escape(label)}</span> ${convert}` +
				`<button type="button" class="btn-alt" data-topology-device-link="remove" data-edge="${this.escape(item.edge_id)}">` +
				`${this.escape(<?= json_encode(_('Delete link')) ?>)}</button></div>`;
		}).join('');
	}

	wireDeviceLinkActions() {
		this.details.querySelectorAll('[data-topology-device-link]').forEach(button => {
			button.addEventListener('click', () => this.guard(async () => {
				const post = (name, body) => this.request(name, {
					method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify(body)
				});
				if (button.dataset.topologyDeviceLink === 'remove') {
					await post('topology.link.remove', {id: button.dataset.edge});
					await this.loadDevices();
					return;
				}
				// convert: pick the far Device's port, then turn the manual device-level link into a port-to-port one
				const {groups = {}} = await this.request(`topology.ports.get&id=${encodeURIComponent(button.dataset.far)}`);
				const ports = Object.values(groups).flat();
				const holder = document.createElement('div');
				holder.className = 'topology-promote';
				const select = document.createElement('select');
				ports.forEach(port => select.add(new Option(port.port, port.id)));
				const confirm = document.createElement('button');
				confirm.type = 'button';
				confirm.className = 'btn-alt';
				confirm.textContent = <?= json_encode(_('Set')) ?>;
				confirm.addEventListener('click', () => this.guard(async () => {
					await post('topology.link.convert', {id: button.dataset.edge, far_port_id: select.value});
					await this.loadDevices();
				}));
				holder.append(select, confirm);
				button.after(holder);
			}));
		});
	}

	// Lost precision (topology-1-model.md "Lost precision"): "The remote port <Q> hasn't been confirmed since <date>; <D> is still
	// seen on this port." One note per side that sees only the far Device. Plain text: the caller escapes it.
	lostPrecisionNotes(link) {
		const since = link.last_seen_port ? this.formatDate(link.last_seen_port) : '?';

		return (link.lost_precision ?? []).map(side => <?= json_encode(_('The remote port')) ?> +
			` ${side.remote_port ?? '?'} ` + <?= json_encode(_('hasn\'t been confirmed since')) ?> + ` ${since}; ` +
			`${this.nodeName(side.remote_device)} ` + <?= json_encode(_('is still seen on this port.')) ?>);
	}

	// "Replaced on <date>", and the neighbor that took the port when the payload says which one it is.
	replacedNote(link) {
		const date = new Date(link.superseded_at * 1000).toLocaleString();
		const node = link.replaced_by_device !== null && link.replaced_by_device !== undefined
			? this.nodes.get(String(link.replaced_by_device))
			: null;

		return <?= json_encode(_('Replaced on')) ?> + ' ' + date + (node
			? ' ' + <?= json_encode(_('by a link to')) ?> + ' ' + (node.name ?? node.sysname ?? '')
			: '') + '.';
	}

	// Same colored-dot convention showPorts() already uses for a port's own oper_status.
	statusCell(status, status_labels) {
		return `<span class="topology-status-dot topology-status-${this.escape(status)}"></span>` +
			this.escape(status_labels[status] ?? status);
	}

	// Load of one side of a link (topology-1-model.md §4.8): in, out, speed and %, as text, with links to the graphs of the items. Display only.
	loadText(load) {
		if (!load || (load.in === null && load.out === null && load.speed === null)) {
			return '';
		}
		const part = (label, value) => value === null || value === undefined ? '' : `${label} ${value === 0 ? '0 bps' : this.formatSpeed(value)}`;
		return [part(<?= json_encode(_('in')) ?>, load.in), part(<?= json_encode(_('out')) ?>, load.out), part(<?= json_encode(_('speed')) ?>, load.speed)]
			.filter(Boolean).join(', ') + (load.percent !== null && load.percent !== undefined ? ` (${load.percent} %)` : '');
	}

	// FR-C V-12 item 4 (model §3.2, 2.93): why a side of a link has no data (the reason comes from the server: `load_reason`)
	noDataText(reason) {
		return {
			not_monitored: <?= json_encode(_('no data: the Device is not a monitored host')) ?>,
			not_reported: <?= json_encode(_('no data: not a port that the host reports')) ?>,
			no_binding: <?= json_encode(_('no data: the port is not bound to an interface')) ?>,
			no_items: <?= json_encode(_('no data: the host has no interface items; it needs a template with interface discovery (for example the official SNMP interface template) next to "Topology by SNMP"')) ?>,
			no_match: <?= json_encode(_('no data: no interface of the host matches this port')) ?>
		}[reason] ?? <?= json_encode(_('no data')) ?>;
	}

	loadGraphLinks(load) {
		return [[<?= json_encode(_('in')) ?>, load?.items?.in], [<?= json_encode(_('out')) ?>, load?.items?.out]].filter(([, id]) => id)
			.map(([label, id]) => `<a href="history.php?action=showgraph&amp;itemids%5B%5D=${encodeURIComponent(id)}" target="_blank" rel="noopener">${this.escape(label)}</a>`).join(' · ');
	}

	formatSpeed(bps) {
		if (!bps) {
			return '';
		}
		if (bps >= 1e9) {
			return `${+(bps / 1e9).toFixed(1)} Gbps`;
		}
		if (bps >= 1e6) {
			return `${+(bps / 1e6).toFixed(1)} Mbps`;
		}
		if (bps >= 1e3) {
			return `${+(bps / 1e3).toFixed(1)} Kbps`;
		}
		return `${bps} bps`;
	}

	formatAge(seconds) {
		const days = Math.floor(seconds / 86400);
		const hours = Math.floor((seconds % 86400) / 3600);
		const minutes = Math.floor((seconds % 3600) / 60);
		if (days > 0) {
			return `${days}d ${hours}h`;
		}
		if (hours > 0) {
			return `${hours}h ${minutes}m`;
		}
		return `${minutes}m`;
	}

	// Zabbix stores severity colors as bare hex, e.g. "E97659" — no leading '#' (see admin ›
	// General › Trigger displaying options). CSS needs the '#'; strip any that's already there
	// first so this stays correct whether or not that storage convention ever changes.
	cssColor(color) {
		return '#' + String(color ?? '97AAB3').replace(/^#/, '');
	}

	// Dark or white text, whichever reads better on a fill (a severity colour can be light yellow or dark red); WCAG relative luminance.
	readableTextOn(hex) {
		const value = String(hex).replace(/^#/, '');
		const [red, green, blue] = [0, 2, 4].map(start => {
			const channel = parseInt(value.slice(start, start + 2), 16) / 255;
			return channel <= 0.03928 ? channel / 12.92 : Math.pow((channel + 0.055) / 1.055, 2.4);
		});
		return 0.2126 * red + 0.7152 * green + 0.0722 * blue > 0.4 ? '#1f2937' : '#ffffff';
	}

	// The legend (topology-1-model.md §12.2 "Legend"): the node channels (outline, icon, fill, badges) and the link channels of §12.3. It can be collapsed; the state
	// is kept per user in the user profile (topology.legend.state), and comes with the page. Only static text and shapes: nothing from the network.
	buildLegend(collapsed) {
		const workspace = document.querySelector('.topology-workspace');
		const node = (outline, fill, icon, dash) => `<svg width="44" height="26" viewBox="0 0 44 26"><rect x="2" y="3" width="40" height="20" rx="3" style="fill:${fill};stroke:${outline};stroke-width:2;${dash ? 'stroke-dasharray:5 3' : ''}"/>${icon}</svg>`;
		const size = kind => kind === 'large'
			? '<svg width="44" height="26" viewBox="0 0 44 26"><rect x="4" y="3" width="36" height="20" rx="4" style="fill:#f3f4f6;stroke:#697386;stroke-width:2"/></svg>'
			: '<svg width="44" height="26" viewBox="0 0 44 26"><rect x="14" y="7" width="16" height="12" rx="3" style="fill:#f3f4f6;stroke:#697386;stroke-width:2"/></svg>';
		const host_icon = '<g transform="translate(6,8)" style="fill:none;stroke:#1f2937;stroke-width:1.4"><rect x="0" y="0" width="10" height="7" rx="1"/><path d="M3 10 H7 M5 7 V10"/></g>';
		const proxy_icon = '<g transform="translate(6,8)" style="fill:none;stroke:#1f2937;stroke-width:1.4"><rect x="2" y="2" width="6" height="6" rx="1"/><path d="M0 5 H2 M8 5 H10 M5 0 V2 M5 8 V10"/></g>';
		const badge = (fill, stroke, letter) => `<svg width="44" height="26" viewBox="0 0 44 26"><circle cx="22" cy="13" r="9" style="fill:${fill};stroke:${stroke};stroke-width:2"/><text x="22" y="17" text-anchor="middle" style="font:bold 11px sans-serif;fill:#fff">${letter}</text></svg>`;
		const line = (stroke, width, dash, opacity = 1) => `<svg width="44" height="26" viewBox="0 0 44 26"><line x1="2" y1="13" x2="42" y2="13" style="stroke:${stroke};stroke-width:${width};stroke-dasharray:${dash};opacity:${opacity}"/></svg>`;
		const row = (swatch, text) => `<div class="topology-legend-row">${swatch}<span>${text}</span></div>`;
		const sections = [
			[<?= json_encode(_('Nodes')) ?>, [
				[node('#1f2937', '#ffffff', '', false), <?= json_encode(_('Host: solid outline; white fill: no problems')) ?>],
				[node('#1f2937', '#f97a5e', '', false), <?= json_encode(_('Host fill: the severity of its problems')) ?>],
				[node('#1f2937', '#cbd2d9', '', false), <?= json_encode(_('Host, disabled: muted fill')) ?>],
				[node('#1f2937', '#ffffff', '', false), <?= json_encode(_('Proxy: solid outline, neutral fill')) ?>],
				[node('#697386', '#f3f4f6', '', true), <?= json_encode(_('Device without association: dashed outline, grey fill')) ?>]
			]],
			[<?= json_encode(_('Device class (icon)')) ?>, TopologyGraph.CLASS_ORDER.map(name => [
				`<svg width="44" height="26" viewBox="0 0 44 26"><g transform="translate(22,13)">${TopologyGraph.iconMarkup(name, '#1f2937', 1.3)}</g></svg>`,
				this.classLabel(name)]).concat([[`<svg width="44" height="26" viewBox="0 0 44 26"><g transform="translate(22,13)">${TopologyGraph.iconMarkup('proxy', '#1f2937', 1.3)}</g></svg>`, <?= json_encode(_('Proxy')) ?>]])],
			[<?= json_encode(_('Size')) ?>, [
				[size('large'), <?= json_encode(_('Large: Host, Proxy, a Device with two or more links')) ?>],
				[size('small'), <?= json_encode(_('Small: a Device with one link or none, a group of devices')) ?>]
			]],
			[<?= json_encode(_('Badges')) ?>, [
				[badge('#475569', '#1e293b', 'M'), <?= json_encode(_('Maintenance')) ?>],
				[badge('#f59e0b', '#7c2d12', '?'), <?= json_encode(_('Blind spot: the Proxy of the Host is not reachable')) ?>],
				[badge('#c2410c', '#7c2d12', '!'), <?= json_encode(_('Link hidden by a manual link (contradiction)')) ?>],
				[badge('#6d28d9', '#4c1d95', '='), <?= json_encode(_('Possible duplicate')) ?>]
			]],
			[<?= json_encode(_('Links')) ?>, [
				[line('#64748b', 2, '0'), <?= json_encode(_('Solid: discovered')) ?>],
				[line('#64748b', 2, '5 3'), <?= json_encode(_('Dashed: manual')) ?>],
				[line('#e97659', 4, '0'), <?= json_encode(_('Colour: the severity of the problems on the port items')) ?>],
				[line('#64748b', 2, '0', 0.45), <?= json_encode(_('Faded: stale or superseded')) ?>],
				[line('#64748b', 3, '1 4'), <?= json_encode(_('Dotted: device-level link (far port unknown)')) ?>],
				// 2.101 (§12.3): the warning mark on the line, the same shape and colours as the mark that is drawn
				[`<svg width="44" height="26" viewBox="0 0 44 26"><g transform="translate(22,13)"><path d="M0,-7 L7,6 L-7,6 Z" style="fill:#f59e0b;stroke:#92400e;stroke-width:1.4;stroke-linejoin:round"/><path d="M0,-2.5 V1.5 M0,3.8 V3.9" style="fill:none;stroke:#1f2937;stroke-width:1.6;stroke-linecap:round"/></g></svg>`,
					<?= json_encode(_('Speed mismatch: the data is wrong, or a device that is not on the graph is between the ports')) ?>]
			]]
		];
		const legend = document.createElement('div');
		legend.className = 'topology-legend';
		legend.id = 'topology-legend';
		// task 117 section 2: the legend is a collapsible box in the bottom corner of the graph area (no button in a toolbar); the head opens and closes it, the state is kept for the user
		legend.classList.toggle('topology-legend-collapsed', collapsed);
		const arrow = open => `<span id="topology-legend-arrow" class="${open ? 'zi-chevron-down' : 'zi-chevron-up'}"></span>`;
		legend.innerHTML = `<div class="topology-legend-head" id="topology-legend-head" role="button" tabindex="0" aria-expanded="${String(!collapsed)}"><span>${this.escape(<?= json_encode(_('Legend')) ?>)}</span>${arrow(!collapsed)}</div>` +
			`<div class="topology-legend-body">${sections.map(([title, rows]) => `<h4>${this.escape(title)}</h4>` + rows.map(([swatch, text]) => row(swatch, this.escape(text))).join('')).join('')}</div>`;
		workspace.appendChild(legend);
		const head = document.getElementById('topology-legend-head');
		const toggle = () => {
			const now_collapsed = !legend.classList.contains('topology-legend-collapsed');
			legend.classList.toggle('topology-legend-collapsed', now_collapsed);
			head.setAttribute('aria-expanded', String(!now_collapsed));
			head.lastElementChild.className = now_collapsed ? 'zi-chevron-up' : 'zi-chevron-down';
			this.guard(() => this.request('topology.legend.state', {method: 'POST', body: JSON.stringify({collapsed: now_collapsed ? 1 : 0})}));
		};
		head.addEventListener('click', toggle);
		head.addEventListener('keydown', event => {
			if (event.key === 'Enter' || event.key === ' ') {
				event.preventDefault();
				toggle();
			}
		});
	}

	// The page (filter bar, graph area and side panel) ends at the bottom of the window: the graph area has the height that is left, and the side panel
	// scrolls inside it (topology-1-model.md §12.1 "Layout"). Not below the CSS minimum.
	fitToWindow() {
		const page = document.querySelector('.topology-prototype');
		if (!page) {
			return;
		}
		const top = page.getBoundingClientRect().top + window.scrollY;
		// task 117 section 5: down to the standard Zabbix footer, which is not changed: the window, minus what is above the page, minus the room that main keeps below it, minus the footer
		const footer = document.querySelector('footer');
		const main = document.querySelector('main');
		const below = (main ? parseFloat(getComputedStyle(main).paddingBottom) || 0 : 0) + (footer ? footer.getBoundingClientRect().height : 0);
		let height = Math.max(520, Math.floor(window.innerHeight - top - below));
		page.style.height = `${height}px`;
		// what Zabbix puts below the page (the footer) must fit too: Zabbix scrolls .wrapper, not the document
		const wrapper = document.querySelector('.wrapper');
		const excess = wrapper ? wrapper.scrollHeight - wrapper.clientHeight : 0;
		if (excess > 0) {
			height = Math.max(520, height - excess);
			page.style.height = `${height}px`;
		}
	}

	// ---- the side panel of a node (§12.5 "Structure of a node panel", 2.75, task 094) ----

	// A text cut in the middle to a width in px (the full text goes in a tooltip: the caller writes it).
	cutText(text, max_px) {
		return TopologyGraph.cutMiddle(String(text ?? ''), max_px, 12, (value, font) => this.measureText(value, font));
	}

	// ---- Menus of the graph (task 117 section 3, model §12.5) ----
	// A click on the label of a node, or on a line, opens a menu, as a click on an element of a Zabbix map does; the device panel is for information only.
	// A Host: the standard host menu of the frontend (the answer of menu.popup and getMenuPopupHost, as the rights of the user allow), then the section "Topology" with the actions that apply.
	// A Device without a host and a Proxy: the section Topology only.
	async openMenu(native_event, anchor, hostid, items) {
		this.hideHint();      // the hint box closes when the menu opens
		this.closeMenus();
		const $anchor = jQuery(anchor);
		let sections = [];
		if (hostid) {
			const answer = await jQuery.ajax({url: 'zabbix.php?action=menu.popup&type=host', method: 'POST', data: {data: {hostid: String(hostid)}}, dataType: 'json'});
			if ('error' in answer) {
				throw new Error((answer.error.messages ?? []).join('\n') || answer.error.title);
			}
			sections = getMenuPopupHost(answer.data, $anchor);
		}
		items = await items;
		if (items.length) {
			sections.push({label: <?= json_encode(_('Topology')) ?>, items});
		}
		if (!sections.length) {
			return;
		}
		const where = {preventDefault() {}, pageX: native_event.pageX, pageY: native_event.pageY, clientX: native_event.clientX, clientY: native_event.clientY};
		$anchor.menuPopup(sections, jQuery.Event(native_event), {position: {of: where, my: 'left top', at: 'left bottom', collision: 'fit flip'}});
	}

	// The actions of a node that apply to it, for the menu of its label. An action that does not apply is not in the list.
	nodeMenuItems(node) {
		const device = node.type === 'device' ? node : (node.device ?? null);
		const items = [];
		const item = (label, callback) => items.push({label, clickCallback: callback});
		// those that are forms of the panel (Promote to host, the duplicates): the panel of the node opens, then the form
		const in_panel = action => () => this.guard(async () => {
			if (this.selected_node !== node) {
				this.panel_from = null;
				await this.selectNode(node);
			}
			action();
		});
		if (node.type === 'host' && node.hostid && String(this.applied_hostid ?? '') !== String(node.hostid)) {
			item(<?= json_encode(_('Focus')) ?>, () => {
				jQuery('#hostid').multiSelect('clean');
				jQuery('#hostid').multiSelect('addData', [{id: String(node.hostid), name: node.name ?? node.label}]);
				document.getElementById('topology-filter-apply').click();
			});
		}
		const group = (this.leaf_groups ?? []).find(candidate => String(candidate.parent) === String(node.id));
		if (group) {
			item(this.groupToggleText(group.parent, group.devices.length), () => this.guard(() => this.toggleGroup(group.parent)));
		}
		if (device) {
			item(<?= json_encode(_('Neighbor reports')) ?>, () => {
				window.location.href = `zabbix.php?action=topology.view&view=lastrun&device_id=${encodeURIComponent(device.id)}`;
			});
		}
		if (device && ((device.duplicates ?? []).length || (device.dismissed ?? []).length)) {
			item(<?= json_encode(_('Possible duplicates')) ?>, in_panel(() => {
				const block = this.details.querySelector('.topology-duplicates-block');
				block.hidden = false;
				block.scrollIntoView({block: 'nearest'});
			}));
		}
		if (device && !device.represented) {
			item(<?= json_encode(_('Promote to host')) ?>, in_panel(() => {
				const form = this.details.querySelector('.topology-promote-form');
				form.hidden = false;
				form.querySelector('.topology-promote-search')?.focus();
			}));
		}
		if (device && device.represented) {
			item(<?= json_encode(_('Depromote')) ?>, () => this.guard(async () => {
				await this.request('topology.depromote', {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({id: device.id})});
				await this.loadDevices();
			}));
		}
		if (device && !device.represented) {
			item(<?= json_encode(_('Delete Device')) ?>, () => this.guard(() => this.duplicateAction('delete', {device: device.id})));
		}
		// a manual Device (§9.7): the same dialog edits it, ports are added with it; a pick of a link waits for the other end
		if (device && device.manual) {
			item(<?= json_encode(_('Edit device')) ?>, () => this.guard(() => this.deviceDialog(device)));
			item(<?= json_encode(_('Add port')) ?>, () => this.guard(() => this.portDialog(device, node, null)));
		}
		if (device && this.link_pick && String(this.link_pick.device_id) !== String(device.id)) {
			item(<?= json_encode(_('Link to this device (port unknown)')) ?>, () => this.guard(async () => {
				await this.request('topology.devicelink.create', {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({port_id: this.link_pick.id, device_id: device.id})});
				this.link_pick = null;
				this.renderLinkPick();
				await this.loadDevices();
			}));
		}
		return items;
	}

	// The menu of a line (§12.3): Link details (the link panel, as a click opened it before), Neighbor reports of the port, Unlink (a manual link, with the confirmation). A LAG bundle: Link details
	// (the bundle panel); Unlink is in its members. A ghost line (a manual link that discovery contradicts): Open link, Keep manual, Accept discovery.
	lineMenuItems(entry) {
		const items = [];
		const item = (label, callback) => items.push({label, clickCallback: callback});
		const link = entry.link ?? null;
		if (entry.ghost) {
			item(<?= json_encode(_('Link details')) ?>, () => entry.open());
			if (entry.ghost.shadow.hidden_device_id !== null) {
				item(<?= json_encode(_('Keep manual')) ?>, () => this.guard(() => this.manualAction('keep', entry.ghost.entry.edge_id, entry.ghost.shadow.hidden_device_id)));
			}
			item(<?= json_encode(_('Accept discovery')) ?>, () => this.guard(() => this.manualAction('accept', entry.ghost.entry.edge_id)));
			return items;
		}
		item(<?= json_encode(_('Link details')) ?>, () => entry.open());
		if (link && link.type !== 'lag_bundle') {
			[[link.source, link.source_port, link.source_port_id], [link.target, link.target_port, link.target_port_id]].forEach(([end, port, port_id]) => {
				if (port_id) {
					item(<?= json_encode(_('Neighbor reports')) ?> + ` (${end?.name ?? '?'} ${port ?? '?'})`, () => {
						window.location.href = `zabbix.php?action=topology.view&view=lastrun&port_id=${encodeURIComponent(port_id)}`;
					});
				}
			});
			if (link.type === 'device_link' && link.edge_id !== null && link.edge_id !== undefined) {
				item(<?= json_encode(_('Unlink')) ?>, () => this.guard(() => this.removeDeviceLink(link.edge_id, String(link.source.id))));
			}
			else if (link.type === 'physical_link' && link.discovered_via === 'manual' && link.source_port_id && link.target_port_id) {
				item(<?= json_encode(_('Unlink')) ?>, () => this.guard(() => this.unlinkPorts(link.source_port_id, link.target_port_id, String(link.source.id))));
			}
		}
		return items;
	}

	// A manual link of a manual Device that a host represents: its port can be bound to an interface of the host. The binding is read with the ports of the Device (the same answer as the panel reads).
	async lineMenuItemsWithBinding(entry) {
		const items = this.lineMenuItems(entry);
		const link = entry.link ?? null;
		if (!link || link.type !== 'physical_link' || link.discovered_via !== 'manual') {
			return items;
		}
		for (const [end, port_id] of [[link.source, link.source_port_id], [link.target, link.target_port_id]]) {
			const device = end?.type === 'device' ? end : end?.device;
			if (!device || !device.manual || !device.represented || !port_id) {
				continue;
			}
			const groups = (await this.request(`topology.ports.get&id=${encodeURIComponent(device.id)}`)).groups ?? {};
			const port = Object.values(groups).flat().find(candidate => String(candidate.id) === String(port_id));
			if (!port || !port.manual) {      // a reported port (a PORTS row took it) has no binding to make
				continue;
			}
			if (!port.binding || port.binding.missing) {
				items.push({label: <?= json_encode(_('Bind to interface')) ?> + ` (${port.port})`, clickCallback: () => this.guard(() => this.bindDialog(device, end, {id: port.id, name: port.port}))});
			}
			if (port.binding) {
				items.push({label: <?= json_encode(_('Unbind')) ?> + ` (${port.port})`, clickCallback: () => this.guard(async () => {
					await this.request('topology.ports.unbind', {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({id: port.id})});
					await this.selectNode(end);
				})});
			}
		}
		return items;
	}

	async removeDeviceLink(edge_id, device_id) {
		if (!confirm(<?= json_encode(_('Unlink these ports? The link is removed.')) ?>)) {
			return;
		}
		await this.request('topology.link.remove', {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({id: edge_id})});
		await this.loadDevices({reselect_device: device_id});
	}

	async unlinkPorts(port_id, linked_port_id, device_id) {
		if (!confirm(<?= json_encode(_('Unlink these ports? The link is removed.')) ?>)) {
			return;
		}
		await this.request('topology.ports.unlink', {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({id: port_id, dst_id: linked_port_id})});
		await this.loadDevices({reselect_device: device_id});
	}

	// The menu "⋯": a button and a list of items, each {label, href, cls, attrs}; no item → no menu. The list opens at the button (fixed position: the panel scrolls).
	menuHtml(items, title) {
		if (!items.length) {
			return '';
		}
		return `<span class="topology-menu"><button type="button" class="topology-menu-button" aria-haspopup="true" aria-expanded="false" title="${this.escape(title)}">⋯</button>` +
			`<ul class="topology-menu-list" hidden>${items.map(item => `<li>${item.href
				? `<a class="topology-menu-item ${item.cls ?? ''}" href="${this.escape(item.href)}" ${item.attrs ?? ''}>${this.escape(item.label)}</a>`
				: `<button type="button" class="topology-menu-item ${item.cls ?? ''}" ${item.attrs ?? ''}>${this.escape(item.label)}</button>`}</li>`).join('')}</ul></span>`;
	}

	wireMenus() {
		this.details.querySelectorAll('.topology-menu-button').forEach(button => button.addEventListener('click', event => {
			event.stopPropagation();
			const list = button.parentNode.querySelector('.topology-menu-list');
			const open = list.hidden;
			this.closeMenus();
			if (open) {
				const rect = button.getBoundingClientRect();
				list.hidden = false;
				list.style.top = `${Math.min(rect.bottom + 2, window.innerHeight - list.offsetHeight - 8)}px`;
				list.style.left = `${Math.max(8, Math.min(rect.right - list.offsetWidth, window.innerWidth - list.offsetWidth - 8))}px`;
				button.setAttribute('aria-expanded', 'true');
			}
		}));
		if (!this.menu_closer) {
			this.menu_closer = () => this.closeMenus();
			document.addEventListener('click', this.menu_closer);
		}
	}

	closeMenus() {
		this.details.querySelectorAll('.topology-menu-list').forEach(list => {
			list.hidden = true;
		});
		this.details.querySelectorAll('.topology-menu-button').forEach(button => button.setAttribute('aria-expanded', 'false'));
	}

	// The names of the sources of a link (§12.5 "Links"): LLDP, CDP, push, manual.
	sourceText(source) {
		const key = String(source ?? 'lldp').toLowerCase();
		return {lldp: 'LLDP', cdp: 'CDP', push: 'push', manual: <?= json_encode(_('manual')) ?>}[key] ?? key;
	}

	// The Links of a Device as entries (the Connected ports of /ports, with the graph's data of the same link when it has it) plus the superseded links that /ports does not give.
	linkEntries(device, groups) {
		const own = String(device.id);
		const by_edge = new Map();
		this.links.forEach(link => {
			if (link.edge_id !== null && link.edge_id !== undefined) {
				by_edge.set(String(link.edge_id), link);
			}
		});
		const entries = (groups?.connected ?? []).map(port => {
			const relation = by_edge.get(String(port.edge_id)) ?? null;
			return {
				port_id: port.id, edge_id: port.edge_id, port: port.port, remote_port: port.remote_port ?? null, remote_device_id: port.remote_device_id ?? null, connected_to: port.connected_to ?? null,
				status: port.status, source: port.source === 'Manual' ? 'Manual' : 'LLDP', source_text: this.sourceText(port.source === 'Manual' ? 'manual' : relation?.discovery_source ?? 'lldp'),
				load: port.load ?? null, stale: Boolean(port.stale), last_seen: port.last_seen ?? null, superseded_at: null, lag_id: port.lag_id ?? null, lag: port.lag ?? null,
				remote_lag_id: port.remote_lag_id ?? null, remote_lag: port.remote_lag ?? null, device_link_id: port.device_link_id ?? null, linked_port_id: port.linked_port_id ?? null,
				severity: relation?.severity ?? null, color: relation?.color ?? null, manual: Boolean(port.manual), binding: port.binding ?? null,
				alias: port.alias ?? null, remote_alias: port.remote_alias ?? null, speed_mismatch: port.speed_mismatch ?? null
			};
		});
		const known = new Set(entries.map(entry => String(entry.edge_id)));
		this.links.filter(link => link.type === 'physical_link' && link.superseded_at && (link.source === own || link.target === own)
				&& link.edge_id !== null && !known.has(String(link.edge_id))).forEach(link => {
			const near = link.source === own;
			const far = this.nodes.get(near ? link.target : link.source);
			entries.push({
				port_id: near ? link.source_port_id : link.target_port_id, edge_id: link.edge_id, port: near ? link.source_port : link.target_port, remote_port: near ? link.target_port : link.source_port,
				remote_device_id: near ? link.target : link.source, connected_to: far?.label ?? far?.name ?? null, status: near ? link.source_status : link.target_status,
				source: link.discovered_via === 'manual' ? 'Manual' : 'LLDP', source_text: this.sourceText(link.discovered_via === 'manual' ? 'manual' : link.discovery_source ?? 'lldp'),
				load: null, stale: true, last_seen: link.last_seen_port ?? null, superseded_at: link.superseded_at, lag_id: near ? link.source_lag_id : link.target_lag_id,
				lag: near ? link.source_lag : link.target_lag, remote_lag_id: near ? link.target_lag_id : link.source_lag_id, remote_lag: near ? link.target_lag : link.source_lag,
				device_link_id: null, linked_port_id: null, severity: link.severity ?? null, color: link.color ?? null
			});
		});

		return entries;
	}

	// The node that shows a Device on the graph: the Device itself, or the Host/Proxy that represents it; null when it is not in the view.
	nodeOfDevice(device_id) {
		const device = this.nodes.get(String(device_id));
		if (!device) {
			return null;
		}
		return [...this.nodes.values()].find(node => node.type !== 'device' && node.device && String(node.device.id) === String(device_id)) ?? device;
	}

	// The width that a neighbor label may have in line 1 of a Links row: the panel without the menu and the toggle.
	linkNameBudget(toggle) {
		return Math.max(90, (this.details.clientWidth || 340) - 40 - (toggle ? 16 : 0));
	}

	// A row of the Links section has two lines (§12.5, 2.77): line 1 the neighbor (the full width, cut in the middle only when wider) and the menu; line 2, smaller and grey, the ports, the status, the
	// source, the load and the marks.
	linkRowHtml({edges, cls = '', hidden = false, toggle = '', neighbor_text, neighbor_node_id, line2}) {
		const esc = value => this.escape(value);
		const cut = esc(this.cutText(neighbor_text ?? '?', this.linkNameBudget(toggle !== '')));
		const neighbor = neighbor_node_id !== null && neighbor_node_id !== undefined
			? `<a class="topology-neighbor" data-node="${esc(neighbor_node_id)}" title="${esc(neighbor_text ?? '?')}">${cut}</a>`
			: `<span class="topology-neighbor" title="${esc(neighbor_text ?? '?')}">${cut}</span>`;
		return `<tr class="topology-link-row ${cls}" data-edges="${esc(edges)}"${hidden ? ' hidden' : ''}><td class="topology-link-main">` +
			`<div class="topology-link-line1"><span class="topology-link-name">${toggle}${neighbor}</span></div>` +
			`<div class="topology-link-line2">${TopologyGraph.joinParts(line2, '<span class="topology-sep"> · </span>')}</div></td></tr>`;
	}

	linkEntryHtml(entry, {member = false} = {}) {
		const esc = value => this.escape(value);
		const status_labels = {up: <?= json_encode(_('Up')) ?>, down: <?= json_encode(_('DOWN')) ?>, disabled: <?= json_encode(_('Disabled')) ?>};
		const neighbor_node = entry.remote_device_id !== null ? this.nodeOfDevice(entry.remote_device_id) : null;
		const status = entry.status ?? '';
		const load = TopologyGraph.loadText(entry.load, {in: <?= json_encode(_('in')) ?>, out: <?= json_encode(_('out')) ?>});
		const marks = [];
		if (entry.superseded_at) {
			marks.push(<?= json_encode(_('superseded')) ?>);
		}
		else if (entry.stale) {
			marks.push(entry.last_seen ? <?= json_encode(_('not confirmed since %1$s')) ?>.replace('%1$s', this.formatDate(entry.last_seen)) : <?= json_encode(_('not confirmed recently')) ?>);
		}
		// 2.101 (§12.3): the row has the short mark; both speeds are in its hint box
		const mismatch_hint = entry.speed_mismatch ? this.speedMismatchText(entry.speed_mismatch.local, entry.speed_mismatch.remote) : null;
		if (mismatch_hint !== null) {
			marks.push(<?= json_encode(_('speed mismatch')) ?>);
		}
		// the port name is never cut; the name of the interface of a bound port and the alias (model 2.100 §12.5, ifAlias) are cut and have the full text in the hint box
		const ports_text = `${this.portSideText(entry.port ?? '?', entry.binding, entry.alias)} ↔ ${this.portSideText(entry.remote_port ?? '?', null, entry.remote_alias)}`;
		const line2 = [`<span class="topology-link-ports" title="${esc(ports_text)}">${this.portSideHtml(entry.port ?? '?', entry.binding, entry.alias)} ↔ ${this.portSideHtml(entry.remote_port ?? '?', null, entry.remote_alias)}</span>`,
			// a part with no value is left out with its separator (§12.5, 2.84)
			status !== '' ? `<span class="topology-link-status" title="${esc(status_labels[status] ?? status)}"><span class="topology-status-dot topology-status-${esc(status)}"></span></span>` : null,
			entry.source_text ? `<span class="topology-link-source">${esc(entry.source_text)}</span>` : null, load !== '—' ? `<span class="topology-link-load">${esc(load)}</span>` : null];
		if (marks.length) {
			line2.push(`<span class="topology-link-mark"${mismatch_hint !== null ? ` title="${esc(mismatch_hint)}"` : ''}>${esc(marks.join(' · '))}</span>`);
		}
		return this.linkRowHtml({edges: String(entry.edge_id ?? ''), cls: member ? 'topology-link-member' : '', hidden: member, neighbor_text: entry.connected_to, neighbor_node_id: neighbor_node?.id ?? null, line2});
	}

	linkBundleHtml(row, index) {
		const esc = value => this.escape(value);
		const first = row.members[0];
		const key = `b${index}`;
		const members = row.members;
		// the load of a bundle: the sum of the bits per second of the members against the sum of their speeds (never a sum of percents, §4.8)
		const sum = name => members.every(member => member.load && member.load[name] !== null && member.load[name] !== undefined) ? members.reduce((total, member) => total + member.load[name], 0) : null;
		const speed = members.every(member => member.load && member.load.speed > 0) ? members.reduce((total, member) => total + member.load.speed, 0) : null;
		const load = TopologyGraph.loadText(speed === null ? null : {in: sum('in'), out: sum('out'), speed}, {in: <?= json_encode(_('in')) ?>, out: <?= json_encode(_('out')) ?>});
		const statuses = [...new Set(members.map(member => member.status))];
		const neighbor_node = first.remote_device_id !== null ? this.nodeOfDevice(first.remote_device_id) : null;
		const edge_ids = members.map(member => String(member.edge_id));
		// line 2 of a bundle row (§12.5, 2.78): "<LAG name> ×<N> · <status> · <source> · <load>"; the ports are in the member rows
		const line2 = [`<span class="topology-link-ports" title="LAG ${esc(row.label)}">${esc(row.label)}</span>`,
			statuses.filter(status => status).length ? `<span class="topology-link-status" title="${esc(statuses.join(' / '))}">${statuses.filter(status => status).map(status => `<span class="topology-status-dot topology-status-${esc(status)}"></span>`).join('')}</span>` : null,
			first.source_text ? `<span class="topology-link-source">${esc(first.source_text)}</span>` : null, load !== '—' ? `<span class="topology-link-load">${esc(load)}</span>` : null];
		// 2.101: a bundle with a member that has a speed mismatch has the short mark; its hint box names the member
		const bad = members.filter(member => member.speed_mismatch);
		if (bad.length) {
			line2.push(`<span class="topology-link-mark" title="${esc(bad.map(member => `${member.port ?? '?'} ↔ ${member.remote_port ?? '?'}: ${this.speedMismatchText(member.speed_mismatch.local, member.speed_mismatch.remote)}`).join('\n'))}">${esc(<?= json_encode(_('speed mismatch')) ?>)}</span>`);
		}
		const toggle = `<button type="button" class="btn-link topology-bundle-toggle" data-bundle="${key}" aria-expanded="false" title="${esc(<?= json_encode(_('Members')) ?>)}">▸</button>`;
		return this.linkRowHtml({edges: edge_ids.join(','), cls: 'topology-link-bundle', toggle, neighbor_text: first.connected_to, neighbor_node_id: neighbor_node?.id ?? null, line2}) +
			members.map(member => this.linkEntryHtml(member, {member: true})).join('');
	}

	buildLinksSection(rows, open) {
		const esc = value => this.escape(value);
		const count = rows.reduce((total, row) => total + (row.type === 'bundle' ? row.count : 1), 0);
		const body = rows.length
			? `<table class="topology-links-table"><tbody>${rows.map((row, i) => (row.type === 'bundle' ? this.linkBundleHtml(row, i) : this.linkEntryHtml(row.entry))).join('')}</tbody></table>`
			: `<div class="topology-empty">${esc(<?= json_encode(_('No links')) ?>)}</div>`;
		return `<details class="topology-section topology-links" ${open ? 'open' : ''}><summary>${esc(<?= json_encode(_('Links')) ?>)} (${count})</summary>${body}</details>`;
	}

	buildIdentitySection(node, device, addresses, open) {
		const esc = value => this.escape(value);
		// task 117 section 8: Copy is the standard copy icon button of the frontend (the icon font), not a text button
		const copy = (text, label) => `<button type="button" class="btn-icon zi-copy topology-copy-button" data-copy="${esc(text)}" title="${esc(label)}" aria-label="${esc(<?= json_encode(_('Copy')) ?>)}"></button>`;
		const name = TopologyGraph.iconOf(device);
		const bits = (device.device_class_bits ?? []).join(', ');
		// a manual Device: the class that the operator chose (§12.2, 2.80)
		const class_text = device.manual ? `${this.classLabel(device.chosen_class)} (${<?= json_encode(_('chosen')) ?>})` : `${this.classLabel(name)}${bits !== '' ? ` (${bits})` : ''}`;
		// Copy for the name and the hardware id; the class has none (§12.5, 2.77)
		// the list of bits wraps only after a comma (§12.5, 2.85): each bit with its comma is one unit that does not break
		const class_bits = device.manual ? [] : (device.device_class_bits ?? []);
		const class_html = device.manual || !class_bits.length ? esc(class_text)
			: `${esc(this.classLabel(name))} (${class_bits.map((bit, i) => `<span class="topology-nowrap">${esc(bit)}${i < class_bits.length - 1 ? ',' : ''}</span>`).join(' ')})`;
		const rows = [[<?= json_encode(_('System name')) ?>, addresses?.sysname, true], [<?= json_encode(_('Hardware ID')) ?>, addresses?.chassis_id, true], [<?= json_encode(_('Device class')) ?>, class_text, false, class_html]]
			.filter(([, value]) => value).map(([label, value, can_copy, html]) => `<label>${esc(label)}</label><div class="form-field topology-address-value${html ? ' topology-class-value' : ''}" title="${esc(value)}">${html ?? esc(value)}${can_copy ? ' ' + copy(value, label) : ''}</div>`).join('');
		// the Addresses are part of it: open for a Device without association, folded for a Host or a Proxy (§12.5)
		const addresses_html = addresses ? this.buildAddressesFragment(addresses, node.type === 'device') : '';
		return `<details class="topology-section topology-identity" ${open ? 'open' : ''}><summary>${esc(<?= json_encode(_('Identity')) ?>)}</summary>` +
			`<div class="form-grid topology-address-identity">${rows}</div>${addresses_html}</details>`;
	}

	// The header of a node panel: class icon, name (a Host: the standard Zabbix host menu), kind mark, badges, actions, summary.
	buildNodeHeader(node, device, groups, entries, problems) {
		const esc = value => this.escape(value);
		const G = TopologyGraph;
		const label = node.label ?? node.name;
		// the name is plain text (task 117 section 3): the actions are in the menu of the label of the node on the graph
		// task 117 section 8: the name has the style of the title of a Zabbix dialog (h4); no class icon before it
		const name = `<h4 class="${node.type === 'host' && node.hostid ? 'topology-host-name ' : ''}topology-node-title">${esc(label)}</h4>`;
		const kind = {host: <?= json_encode(_('Zabbix host')) ?>, proxy: <?= json_encode(_('Proxy')) ?>,
			device: node.manual ? <?= json_encode(_('Not a Zabbix host · manual')) ?> : <?= json_encode(_('Not a Zabbix host')) ?>}[node.type];
		const badges = [];
		if (node.type === 'host' && node.disabled) {
			badges.push(<?= json_encode(_('Host is disabled')) ?>);
		}
		if (node.type === 'host' && node.maintenance) {
			badges.push(<?= json_encode(_('In maintenance')) ?>);
		}
		if (node.type === 'host' && node.blind_spot) {
			badges.push(<?= json_encode(_('Blind spot: the Proxy of the Host is not reachable')) ?>);
		}
		if (node.type === 'proxy' && node.unreachable) {
			badges.push(<?= json_encode(_('Proxy unreachable')) ?>);
		}
		if (device && this.hiddenByFragment(device.id) !== '') {
			badges.push(<?= json_encode(_('Link hidden by a manual link')) ?>);
		}
		if (device && (device.duplicates ?? []).length) {
			badges.push(<?= json_encode(_('Possible duplicate')) ?> + ': ' + device.duplicates.map(candidate => this.markLabel(candidate)).join(', '));
		}
		// the summary line (§12.5): "<N> links · <L> LAG · <P> ports (<U> up)"
		let summary = '';
		if (groups) {
			const all_ports = Object.values(groups).flat();
			const live = entries.filter(entry => !entry.superseded_at);
			const neighbors = new Set(live.map(entry => String(entry.remote_device_id ?? entry.connected_to)));
			summary = G.summaryLine({links: live.length, neighbors: neighbors.size, ports: all_ports.length, up: all_ports.filter(port => port.status === 'up').length},
				{link: <?= json_encode(_('%1$s link')) ?>, links: <?= json_encode(_('%1$s links')) ?>, neighbor: <?= json_encode(_('%1$s neighbor')) ?>, neighbors: <?= json_encode(_('%1$s neighbors')) ?>,
					to: <?= json_encode(_('%1$s to %2$s')) ?>, port: <?= json_encode(_('%1$s port')) ?>, ports: <?= json_encode(_('%1$s ports')) ?>, up: <?= json_encode(_('%1$s up')) ?>});
		}
		// task 117 section 6: the problems of a Host as the counters of its summary line, the standard element of the "Problems" column of Monitoring → Hosts: one count for each severity that has problems
		// (the highest first), nothing when there are none; a click opens Monitoring → Problems for the host. A Device without a host has none.
		const counters = this.problemCounters(node, problems);
		return `<div class="topology-node-head"><div class="topology-node-name">${name}</div>` +
			(badges.length ? `<div class="topology-node-badges">${badges.map(text => `<span class="topology-text-badge">${esc(text)}</span>`).join('')}</div>` : '') +
			// task 117 section 8: the kind of the node is grey text at the start of the summary line, not a badge (a badge looks like a Zabbix tag)
			`<div class="topology-node-summary"><span class="grey">${esc([kind, summary].filter(text => text !== '').join(' · '))}</span>${counters !== '' ? ' ' + counters : ''}</div>` + `</div>`;
	}

	problemCounters(node, problems) {
		if (node.type !== 'host' || !node.hostid || !problems || !problems.length) {
			return '';
		}
		// the status classes of the severities (CSeverityHelper::getStatusStyle: not classified, information, warning, average, high, disaster)
		const styles = ['status-na-bg', 'status-info-bg', 'status-warning-bg', 'status-average-bg', 'status-high-bg', 'status-disaster-bg'];
		const items = TopologyGraph.problemSummary(problems).by_severity.map(entry => `<span class="problem-icon-list-item ${this.escape(styles[entry.severity] ?? '')}" title="${this.escape(entry.severity_name ?? '')}">${entry.count}</span>`).join('');
		return `<a class="problem-icon-link topology-problem-counters" href="zabbix.php?action=problem.view&hostids%5B%5D=${encodeURIComponent(node.hostid)}&filter_set=1">${items}</a>`;
	}

	showNodePanel(node, {device, problems, groups, addresses}) {
		const G = TopologyGraph;
		this.hideDetailsTitle();
		this.panel_device = device;      // the menus of a manual port (Bind to interface) need to know whether the Device is associated with a host
		const entries = device && groups ? this.linkEntries(device, groups) : [];
		const rows = G.linkRows(entries);
		const sections = G.panelSections(node.type, Boolean(device)).map(section => {
			if (section.id === 'links') {
				return this.buildLinksSection(rows, section.open);
			}
			if (section.id === 'ports') {
				return this.buildPortGroups(groups ?? {}, section.open);
			}
			return this.buildIdentitySection(node, device, addresses, section.open);
		}).join('');
		// the blocks that a menu item shows: Promote to host (the picker), the duplicates (§6.7.5); a contradicted link's note stays in the panel
		const promote = device && !device.represented ? `<div class="topology-promote topology-promote-form" hidden><input type="text" class="topology-promote-search" placeholder="${this.escape(<?= json_encode(_('Search host or proxy by name/IP…')) ?>)}">` +
			`<div class="topology-promote-results"></div><div class="topology-promote-selected"></div>` +
			`<button type="button" class="btn-alt topology-promote-button" disabled>${this.escape(<?= json_encode(_('Promote to host')) ?>)}</button></div>` : '';
		const duplicates = device ? `<div class="topology-duplicates-block" hidden>${this.duplicatesFragment(device)}</div>` : '';
		const hidden_by = device ? this.hiddenByFragment(device.id) : '';
		this.details.innerHTML = this.buildNodeHeader(node, device, groups, entries, problems) + promote + duplicates + hidden_by + sections;
		this.wireNodePanel(node, device);
	}

	wireNodePanel(node, device) {
		this.wireMenus();
		this.wireCopyButtons();
		const on = (selector, handler) => this.details.querySelectorAll(selector).forEach(element => element.addEventListener('click', handler));
		// a neighbor of a row: select it on the graph and open its panel
		// a neighbor of a row: select it on the graph and open its panel (navigation, not an action)
		on('.topology-neighbor[data-node]', event => {
			const target = this.nodes.get(event.currentTarget.dataset.node);
			if (target) {
				this.guard(() => this.openFromPanel(() => this.selectNode(target)));
			}
		});
		on('.topology-link-details', event => {
			const link = this.links.find(candidate => candidate.edge_id !== null && String(candidate.edge_id) === String(event.currentTarget.dataset.edge));
			if (link) {
				this.openFromPanel(() => this.selectLink(this.resolvedLink(link)));
			}
		});
		on('.topology-bundle-details', event => {
			const ids = event.currentTarget.dataset.edges.split(',');
			const bundle = (this.drawn_links ?? []).find(link => link.type === 'lag_bundle' && (link.members ?? []).some(member => ids.includes(String(member.edge_id))));
			if (bundle) {
				this.openFromPanel(() => this.selectBundle(bundle));
			}
		});
		on('.topology-bundle-toggle', event => {
			const button = event.currentTarget;
			const open = button.getAttribute('aria-expanded') !== 'true';
			button.setAttribute('aria-expanded', String(open));
			button.textContent = open ? '▾' : '▸';
			let row = button.closest('tr').nextElementSibling;
			while (row && (row.classList.contains('topology-link-member') || row.classList.contains('topology-link-note'))) {
				row.hidden = !open;
				row = row.nextElementSibling;
			}
		});
		// a row and its line are one thing (§12.3): a hover on a row is a hover on its line
		this.details.querySelectorAll('tr[data-edges]').forEach(row => {
			row.addEventListener('mouseenter', () => this.hoverRow(row));
			row.addEventListener('mouseleave', () => this.hoverRow(null));
		});
		if (device) {
			this.wirePortsFragment(device, node);
		}
	}

	// The ids of the stored links that a drawn line stands for (a bundle: its members; a device-level bundle: the links in it).
	lineEdgeIds(link) {
		const ids = [];
		const add = value => {
			if (value !== null && value !== undefined) {
				ids.push(String(value));
			}
		};
		add(link.edge_id);
		(link.members ?? []).forEach(member => add(member.edge_id));
		(link.links ?? []).forEach(item => {
			add(item.edge_id);
			add(item.id);
		});
		return ids;
	}

	// A hover on a row of the Links section: the line gets its hover (highlight and tooltip, at the middle of the line); null clears it.
	hoverRow(row) {
		if (!row) {
			this.hoverLine(null, null);
			return;
		}
		const ids = row.dataset.edges.split(',').filter(Boolean);
		const entry = (this.line_entries ?? []).find(candidate => candidate.link && this.lineEdgeIds(candidate.link).some(id => ids.includes(id)));
		if (!entry) {
			return;
		}
		const middle = entry.hit.getPointAtLength(entry.hit.getTotalLength() / 2);
		const point = new DOMPoint(middle.x, middle.y).matrixTransform(entry.hit.getScreenCTM());
		this.hoverLine({clientX: point.x, clientY: point.y}, entry);
	}

	// A hover on a line marks its row in the open panel (when the panel is one of its ends).
	markRows(entry) {
		const ids = entry && entry.link ? this.lineEdgeIds(entry.link) : [];
		this.details.querySelectorAll('tr[data-edges]').forEach(row => {
			const mine = row.dataset.edges.split(',').filter(Boolean);
			row.classList.toggle('topology-row-hover', ids.length > 0 && mine.some(id => ids.includes(id)));
		});
	}

	renderLinkPick() {
		const container = document.getElementById('topology-link-pick');
		if (!this.link_pick) {
			container.innerHTML = '';
			return;
		}
		container.innerHTML = `${this.escape(<?= json_encode(_('Linking from: ')) ?>)}${this.escape(this.link_pick.label)}` +
			`<a class="topology-link-cancel">${this.escape(<?= json_encode(_('Cancel')) ?>)}</a>`;
		container.querySelector('.topology-link-cancel').addEventListener('click', () => {
			this.link_pick = null;
			this.renderLinkPick();
		});
	}

	// "Bind to interface" (§9.7, 2.91): the text of a manual port that is bound, "lan0 → Gi0/1", or "lan0 (interface not found)" when its items are gone
	// One side of the ports of a link or a row of the Ports section (model 2.100 §12.5): "<port name>" and, after it, "→ <interface>" (a bound manual port) or "· <alias>" in grey. The name of the
	// port is never cut; the interface and the alias are cut and their full text is in the hint box (the `title` of the element). The names come from the network: escaped.
	portSideText(port, binding, alias) {
		return `${this.boundPortText(port, binding)}${alias ? ` · ${alias}` : ''}`;
	}

	portSideHtml(port, binding, alias) {
		const esc = value => this.escape(value);
		let html = esc(port);
		if (binding) {
			const name = String(binding.name ?? binding.if_index);
			html += binding.missing ? ` (${esc(<?= json_encode(_('interface not found')) ?>)})` : ` → <span title="${esc(name)}">${esc(this.cutText(name, 90))}</span>`;
		}
		if (alias) {
			html += ` <span class="grey topology-port-alias" title="${esc(alias)}">· ${esc(this.cutText(alias, 150))}</span>`;
		}

		return html;
	}

	boundPortText(port, binding) {
		if (!binding) {
			return port;
		}

		return binding.missing ? `${port} (${<?= json_encode(_('interface not found')) ?>})` : `${port} → ${binding.name ?? binding.if_index}`;
	}

	// The menu items of a manual port of an associated Device: "Bind to interface" (also for a stale binding, whose items are gone) and "Unbind" when it is bound. A Device without an association has neither.
	bindItems(port_id, port_name, binding) {
		if (!this.panel_device || !this.panel_device.represented || !this.panel_device.manual) {
			return [];
		}
		const attrs = `data-port-id="${this.escape(port_id)}" data-name="${this.escape(port_name)}"`;
		const items = [];
		if (!binding || binding.missing) {
			items.push({label: <?= json_encode(_('Bind to interface')) ?>, cls: 'topology-port-bind', attrs});
		}
		if (binding) {
			items.push({label: <?= json_encode(_('Unbind')) ?>, cls: 'topology-port-unbind', attrs});
		}

		return items;
	}

	// The Ports section (§12.5, 2.75): the port groups of the Device except Connected (that is the Links section), each folded by default with its counts in the header: "No neighbor (5 up,
	// 30 down)", "Port-channel (4)". The rules of the groups do not change (§12.5 "Port table groups").
	buildPortGroups(groups, open = true) {
		const labels = {
			lag: <?= json_encode(_('Port-channel')) ?>, mgmt: <?= json_encode(_('Management')) ?>,
			virtual: <?= json_encode(_('Virtual')) ?>, mac_only: <?= json_encode(_('MAC addresses only')) ?>, no_neighbor: <?= json_encode(_('No neighbor')) ?>
		};
		const help = {
			mac_only: <?= json_encode(_('No LLDP or CDP neighbor on these ports. The switch learned MAC addresses here (FDB), so a device is connected, but it is not known.')) ?>
		};
		const mac_count_text = <?= json_encode(_('%1$s MAC addresses')) ?>;
		const connected_cell = port => port.group === 'mac_only'
			? (port.learned_mac_count !== null && port.learned_mac_count !== undefined
				? this.escape(mac_count_text.replace('%1$s', String(port.learned_mac_count)))
				: (port.learned_macs ?? []).map(mac => this.escape(mac)).join('<br>'))
			: `${this.escape(port.connected_to ?? '–')}`;
		const status_cell = port => `<span class="topology-status-dot topology-status-${this.escape(port.status)}"></span>${this.escape(port.status)}` +
			(port.admin_status === 'down' ? ` <em>(${this.escape(<?= json_encode(_('admin down')) ?>)})</em>` : '');
		const link_button = port => `<button type="button" class="btn-alt topology-link-button" data-port-id="${this.escape(port.id)}" data-port-name="${this.escape(port.port)}">${this.escape(<?= json_encode(_('Link')) ?>)}</button>`;
		const source_classes = {LLDP: 'topology-source-lldp', Manual: 'topology-source-manual'};
		const count_text = (group, ports) => {
			if (group === 'no_neighbor' || group === 'mac_only') {
				const up = ports.filter(port => port.status === 'up').length;
				const down = ports.length - up;
				return `(${[up ? `${up} ${<?= json_encode(_('up')) ?>}` : '', down ? `${down} ${<?= json_encode(_('down')) ?>}` : ''].filter(Boolean).join(', ')})`;
			}
			return `(${ports.length})`;
		};
		const sections = Object.entries(groups).filter(([group, ports]) => group !== 'connected' && ports.length).map(([group, ports]) => `
			<details class="topology-group topology-port-group" data-group="${this.escape(group)}"><summary>${this.escape(labels[group] ?? group)} ${this.escape(count_text(group, ports))}</summary>${help[group] ? `<p class="topology-group-help">${this.escape(help[group])}</p>` : ''}<table><colgroup>
				<col class="topology-col-port"><col class="topology-col-status"><col class="topology-col-connected">
				<col class="topology-col-source"><col class="topology-col-action">
			</colgroup><thead><tr>${[<?= json_encode(_('Port')) ?>, <?= json_encode(_('Status')) ?>, <?= json_encode(_('Connected to')) ?>, <?= json_encode(_('Source')) ?>]
				.map(header => `<th title="${this.escape(header)}">${this.escape(header)}</th>`).join('')}<th></th></tr></thead><tbody>
			${ports.map(port => `<tr>
				<td class="topology-port-name" title="${this.escape(this.portSideText(port.port, port.binding, port.alias))}${port.lag ? ` (${this.escape(port.lag)})` : ''}">${this.portSideHtml(port.port, port.binding, port.alias)}${port.lag ? ` <em>(${this.escape(port.lag)})</em>` : ''}</td>
				<td title="${this.escape(port.status ?? '')}${port.admin_status === 'down' ? ` (${this.escape(<?= json_encode(_('admin down')) ?>)})` : ''}">${status_cell(port)}</td>
				<td${port.group === 'mac_only' ? ' class="topology-mac-list"' : ''} title="${this.escape(port.group === 'mac_only' ? (port.learned_macs ?? []).join(' ') : (port.connected_to ?? ''))}">${connected_cell(port)}</td>
				<td title="${port.source === '-' ? '' : this.escape(port.source)}">${port.source === '-' ? '–' : `<span class="topology-source-badge ${source_classes[port.source] ?? ''}">${this.escape(port.source)}</span>`}</td>
				<td>${link_button(port)}${port.manual ? this.menuHtml([
					{label: <?= json_encode(_('Rename')) ?>, cls: 'topology-port-rename', attrs: `data-port-id="${this.escape(port.id)}" data-name="${this.escape(port.port)}" data-description="${this.escape(port.description ?? '')}"`},
					...this.bindItems(port.id, port.port, port.binding),
					{label: <?= json_encode(_('Delete')) ?>, cls: 'topology-port-delete', attrs: `data-port-id="${this.escape(port.id)}" data-name="${this.escape(port.port)}"`}
				], <?= json_encode(_('Port actions')) ?>) : ''}</td>
			</tr>`).join('')}
			</tbody></table></details>`).join('');
		return sections === '' ? '' : `<details class="topology-section topology-ports" ${open ? 'open' : ''}><summary>${this.escape(<?= json_encode(_('Ports')) ?>)}</summary>${sections}</details>`;
	}

	// Search-as-you-type picker for the "Promote to host" panel (topology-1-model.md "API", "Side panels"): debounced, queries
	// /topo/hosts/search and /topo/proxies/search in parallel (both are thin Zabbix API wrappers,
	// the Zabbix pull — neither reads nor writes topo_nodes), renders the merged results, and tracks the current
	// selection on `this.promote_selection` so wirePortsFragment()'s Promote button can read it
	// without re-querying. Re-created fresh on every buildPortsFragment() render, same lifecycle as
	// the other per-panel wiring in wirePortsFragment().
	wirePromoteSearch(device) {
		this.promote_selection = null;
		const search_input = this.details.querySelector('.topology-promote-search');
		const results_container = this.details.querySelector('.topology-promote-results');
		const selected_container = this.details.querySelector('.topology-promote-selected');
		const promote_button = this.details.querySelector('.topology-promote-button');
		if (!search_input) {
			return;
		}
		const select = candidate => {
			this.promote_selection = candidate;
			results_container.innerHTML = '';
			search_input.value = '';
			selected_container.textContent = `${candidate.type === 'proxy' ? <?= json_encode(_('Proxy')) ?> : <?= json_encode(_('Host')) ?>}: ${candidate.name}`;
			promote_button.disabled = false;
		};
		search_input.addEventListener('input', () => {
			clearTimeout(this.promote_search_timer);
			const q = search_input.value.trim();
			if (!q) {
				results_container.innerHTML = '';
				return;
			}
			// Debounced — a request per keystroke would hit /topo/hosts/search and /topo/proxies/search
			// far more often than the typing itself needs.
			this.promote_search_timer = setTimeout(() => this.guard(async () => {
				const [{hosts = []}, {proxies = []}] = await Promise.all([
					this.request(`topology.hosts.search&q=${encodeURIComponent(q)}`),
					this.request(`topology.proxies.search&q=${encodeURIComponent(q)}`)
				]);
				const candidates = [
					...hosts.map(host => ({type: 'host', id: host.hostid, name: host.name})),
					...proxies.map(proxy => ({type: 'proxy', id: proxy.proxyid, name: proxy.name}))
				];
				results_container.innerHTML = candidates.length
					? candidates.map((candidate, index) => `<div class="topology-promote-result topology-promote-result-${candidate.type}" data-index="${index}">${this.escape(candidate.name)}</div>`).join('')
					: `<div class="topology-promote-result">${this.escape(<?= json_encode(_('No matches.')) ?>)}</div>`;
				results_container.querySelectorAll('.topology-promote-result[data-index]').forEach(item => {
					item.addEventListener('click', () => select(candidates[Number(item.dataset.index)]));
				});
			}), 250);
		});
	}

	// Wires the buttons buildPortsFragment() just rendered into this.details. `reselect_node` is what a
	// Link/Unlink completion re-selects afterwards: `device` itself for the standalone panel (showPorts()),
	// or the owning Host/Proxy node for the combined panel (showPanel()) — re-selecting the wrong one would
	// swap the whole panel to the standalone Device view instead of refreshing the Device section in place.
	wirePortsFragment(device, reselect_node) {
		this.wirePromoteSearch(device);
		const button = this.details.querySelector('.topology-promote-button');
		if (button) {
			button.addEventListener('click', () => this.guard(async () => {
				if (!this.promote_selection) {
					return;
				}
				const {type: target_type, id: target_id} = this.promote_selection;
				await this.request('topology.promote', {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({id: device.id, target_type, target_id})});
				await this.loadDevices();
			}));
		}
		this.details.querySelectorAll('.topology-link-button').forEach(link_button => {
			link_button.addEventListener('click', () => this.guard(async () => {
				const port_id = link_button.dataset.portId;
				// A click either starts a pick (nothing picked yet, or re-picking the same port) or completes
				// one (a different port was already picked, possibly on another device's panel entirely —
				// the pick lives on `this`, not in this render, so it survives navigating to a different node).
				if (this.link_pick && this.link_pick.id !== port_id) {
					await this.request('topology.ports.link', {
						method: 'POST', headers: {'Content-Type': 'application/json'},
						body: JSON.stringify({id: this.link_pick.id, dst_id: port_id})
					});
					this.link_pick = null;
					this.renderLinkPick();
					// the line is drawn at once (2.80): the view is read again, and this Device's panel is shown again
					this.forgetPlaceOfNewManual([device.id, this.link_pick_from_device ?? '']);
					await this.loadDevices({reselect_device: device.id});
				}
				else {
					this.link_pick = {id: port_id, device_id: device.id, label: `${device.name} / ${link_button.dataset.portName}`};
					this.link_pick_from_device = String(device.id);
					this.renderLinkPick();
				}
			}));
		});
		// the manual port of a manual Device (§9.7): Rename and Delete in its row menu
		this.details.querySelectorAll('.topology-port-rename').forEach(item => item.addEventListener('click', () => this.guard(async () => {
			this.closeMenus();
			await this.portDialog(device, reselect_node, {id: item.dataset.portId, name: item.dataset.name, description: item.dataset.description});
		})));
		this.details.querySelectorAll('.topology-port-bind').forEach(item => item.addEventListener('click', () => this.guard(async () => {
			this.closeMenus();
			await this.bindDialog(device, reselect_node, {id: item.dataset.portId, name: item.dataset.name});
		})));
		this.details.querySelectorAll('.topology-port-unbind').forEach(item => item.addEventListener('click', () => this.guard(async () => {
			this.closeMenus();
			await this.request('topology.ports.unbind', {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({id: item.dataset.portId})});
			await this.selectNode(reselect_node);
		})));
		this.details.querySelectorAll('.topology-port-delete').forEach(item => item.addEventListener('click', () => this.guard(async () => {
			this.closeMenus();
			if (!confirm(<?= json_encode(_('Delete the port %1$s?')) ?>.replace('%1$s', item.dataset.name))) {
				return;
			}
			await this.request('topology.ports.delete', {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({id: item.dataset.portId})});
			await this.selectNode(reselect_node);
		})));
	}

	// topology-1-model.md §12.5 "Addresses": the addresses of a Device without association as its neighbors report them (topology.addresses.get), above the port table: the
	// sysname and the chassis id (the name for a new host), the addresses (management first, then IPv4, then IPv6) each with a Copy control, and per address who reported it
	// (reporter, local port, LLDP / CDP / push) and how that reporter is monitored (the proxy for the new host). Read only. Every value comes from the network and is
	// escaped (§13): nothing here is markup.
	buildAddressesFragment(data, open = true) {
		const escape = value => this.escape(value);
		const copy = (text, label) => `<button type="button" class="btn-icon zi-copy topology-copy-button" data-copy="${escape(text)}" title="${escape(label)}" aria-label="${escape(<?= json_encode(_('Copy')) ?>)}"></button>`;
		const monitoring = (m) => {
			const name = item => item ? (item.name ?? item.id) : null;
			if (m.mode === 'proxy_group') {
				return `${<?= json_encode(_('proxy group')) ?>} ${name(m.proxy_group) ?? '?'}` + (m.proxy ? ` (${<?= json_encode(_('proxy')) ?>} ${name(m.proxy)})` : '');
			}
			if (m.mode === 'proxy') {
				return `${<?= json_encode(_('proxy')) ?>} ${name(m.proxy) ?? '?'}`;
			}
			return <?= json_encode(_('server')) ?>;
		};
		// §12.5 (2.70): one line per address with the count "reported by <N> ports of <M> hosts"; the sources (reporter, local port, protocol, how the reporter is monitored) are in a
		// folded list under the line, closed by default
		const plural = (n, one, many) => (n === 1 ? one : many).replace('%1$s', String(n));
		const lines = data.addresses.map(line => {
			const ports = new Set(line.sources.map(source => `${source.reporter.hostid ?? source.reporter.name}|${source.local_port ?? ''}`)).size;
			const hosts = new Set(line.sources.map(source => source.reporter.hostid ?? source.reporter.name)).size;
			const count = line.sources.length
				? <?= json_encode(_('reported by %1$s of %2$s')) ?>
					.replace('%1$s', plural(ports, <?= json_encode(_('%1$s port')) ?>, <?= json_encode(_('%1$s ports')) ?>))
					.replace('%2$s', plural(hosts, <?= json_encode(_('%1$s host')) ?>, <?= json_encode(_('%1$s hosts')) ?>))
				: '';
			return `<li class="topology-address">
			<div class="topology-address-head"><span class="topology-address-value" title="${escape(line.address)}">${escape(line.address)}</span>
				${line.management ? `<span class="topology-source-badge">${escape(<?= json_encode(_('management')) ?>)}</span>` : ''}${line.link_local ? `<span class="topology-source-badge">${escape(<?= json_encode(_('link-local')) ?>)}</span>` : ''}${copy(line.address, line.address)}</div>
			${line.not_reported_now ? `<div class="topology-address-source">${escape(<?= json_encode(_('not reported now')) ?>)}</div>` : ''}
			${count ? `<details class="topology-address-sources"><summary class="topology-address-count">${escape(count)}</summary>` +
				line.sources.map(source => `<div class="topology-address-source" title="${escape(`${source.reporter.name}, ${source.local_port ?? ''}, ${source.source}`)}">` +
					`${escape(source.reporter.name)} · ${escape(source.local_port ?? '?')} · ${escape(String(source.source).toUpperCase())} · ${escape(<?= json_encode(_('monitored by')) ?>)} ${escape(monitoring(source.monitoring))}</div>`).join('') +
				`</details>` : ''}
		</li>`;
		}).join('');
		return `<details class="topology-group topology-addresses" ${open ? 'open' : ''}><summary>${escape(<?= json_encode(_('Addresses')) ?>)}</summary>` +
			(data.addresses.length ? `<ul class="topology-address-list">${lines}</ul>`
				: `<div class="topology-empty">${escape(<?= json_encode(_('No address reported')) ?>)}</div>`) + `</details>`;
	}

	// The Copy controls of the Addresses section: the text goes to the clipboard (the Clipboard API where the page is a secure context, else a hidden textarea).
	wireCopyButtons() {
		this.details.querySelectorAll('.topology-copy-button').forEach(button => button.addEventListener('click', async () => {
			const text = button.dataset.copy ?? '';
			try {
				await navigator.clipboard.writeText(text);
			}
			catch (error) {
				const area = document.createElement('textarea');
				area.value = text;
				area.style.position = 'fixed';
				area.style.opacity = '0';
				document.body.appendChild(area);
				area.select();
				document.execCommand('copy');
				area.remove();
			}
			button.dispatchEvent(new CustomEvent('topology-copied'));
		}));
	}

	// The width of a text in px as the browser draws it (the label of a node: §12.2 "Label placement"); one canvas for the page.
	measureText(text, font) {
		if (!this.measure_context) {
			this.measure_context = document.createElement('canvas').getContext('2d');
			this.measure_family = getComputedStyle(this.canvas.node()).fontFamily || 'sans-serif';
		}
		this.measure_context.font = `${font}px ${this.measure_family}`;
		return this.measure_context.measureText(text).width;
	}

	// The focus host (§12.1 "Node placement"): the node of the Host chosen in the filter, or null.
	focusNodeId(nodes) {
		const hostid = this.applied_hostid ?? null;
		if (hostid === null) {
			return null;
		}
		const node = nodes.find(candidate => candidate.type === 'host' && String(candidate.hostid) === String(hostid));
		return node ? String(node.id) : null;
	}

	// Leaf group (§12.8): the click on its node unfolds it, "Fold <N> devices" folds it. The state is kept until the page is loaded or the filter applied, not stored.
	unfoldGroup(parent_id) {
		this.user_moved = true;      // an unfold or a fold is the user's action: the view does not jump to the centre again
		this.unfolded.add(String(parent_id));
		this.render();
	}

	foldGroup(parent_id) {
		this.user_moved = true;
		this.unfolded.delete(String(parent_id));
		this.render();
	}

	// Runs `open` (a function that folds or unfolds a group and shows the panel that follows: the group, the parent) so that the panel does not pan the view to its object, even when the object is
	// outside the window (§12.8, 2.93: a fold or an unfold never moves the view). The rule depends on the action that opens the panel, not on a time after it (task 110); a panel that a click on a node
	// or a line opens pans as before (applySelection).
	async withoutPan(open) {
		this.no_pan_for_panel = true;
		try {
			return await open();
		}
		finally {
			this.no_pan_for_panel = false;
		}
	}

	// The side panel of a leaf group: its Devices, one line each: label, the local port of the parent, the remote port. A click on a line opens the panel of that Device.
	showGroup(group) {
		this.select_token = (this.select_token ?? 0) + 1;
		this.setDetailsTitle(<?= json_encode(_('Device details')) ?>);
		const rows = group.devices.map(id => {
			const device = this.nodes.get(String(id));
			const link = this.links.find(candidate => ['physical_link', 'device_link'].includes(candidate.type)
				&& (String(candidate.source) === String(id) || String(candidate.target) === String(id)) && !candidate.superseded_at);
			const leaf_is_source = link && String(link.source) === String(id);
			const local = link ? (leaf_is_source ? link.target_port : link.source_port) : null;
			const remote = link ? (leaf_is_source ? link.source_port : link.target_port) : null;
			return `<tr class="topology-group-row" data-device-id="${this.escape(id)}"><td title="${this.escape(device?.label ?? id)}">${this.escape(device?.label ?? device?.name ?? id)}</td>` +
				`<td>${this.escape(local ?? '–')}</td><td title="${this.escape(remote ?? '')}">${this.escape(remote ?? '–')}</td></tr>`;
		}).join('');
		const parent = this.nodes.get(String(group.parent));
		// 2.72: the title is "<N> devices at <parent>", not "Device details"
		const group_title = <?= json_encode(_('%1$s devices at %2$s')) ?>.replace('%1$s', String(group.count)).replace('%2$s', parent?.label ?? parent?.name ?? '');
		this.setDetailsTitle(group_title);
		// 2.73: no second line with "<N> devices" under the title
		this.details.innerHTML = `<div class="topology-group-fold"><button type="button" class="btn-alt" id="topology-fold" data-parent="${this.escape(group.parent)}">${this.escape(
				this.groupToggleText(group.parent, group.count))}</button></div>` +
			`<p class="topology-group-help">${this.escape(<?= json_encode(_('Devices with one link each, at')) ?>)} ${this.escape(parent?.label ?? parent?.name ?? '')}</p>` +
			`<section class="topology-group"><table><thead><tr><th>${this.escape(<?= json_encode(_('Device')) ?>)}</th><th>${this.escape(<?= json_encode(_('Local port')) ?>)}</th>` +
			`<th>${this.escape(<?= json_encode(_('Remote port')) ?>)}</th></tr></thead><tbody>${rows}</tbody></table></section>`;
		this.wireFoldButtons();
		this.details.querySelectorAll('.topology-group-row').forEach(row => row.addEventListener('click', () => {
			const device = this.nodes.get(row.dataset.deviceId);
			if (device) {
				this.guard(() => this.openFromPanel(() => this.selectNode(device)));
			}
		}));
		this.registerPanel('group:' + group.parent, group_title, () => {
			const current = this.group_nodes.get(String(group.parent));
			return current ? this.showGroup(current) : undefined;
		}, {kind: 'group', id: String(group.id ?? 'group:' + group.parent), parent: String(group.parent)});
	}

	// The title of a Junction projection (§12.6): "Junction at <Device>, port <port>"
	junctionTitle(junction_node) {
		const reporter = this.nodes.get(String(junction_node.junction.device_id));
		return <?= json_encode(_('Junction at %1$s, port %2$s')) ?>.replace('%1$s', reporter?.label ?? junction_node.junction.device_name ?? '').replace('%2$s', junction_node.junction.port_name ?? '');
	}

	// The side panel of a Junction projection (§12.6): the anchor Port and the Devices behind it, read only. A click on a line opens the panel of that Device. It has no id, no name of its own and no action.
	showJunction(junction_node) {
		this.select_token = (this.select_token ?? 0) + 1;
		const junction = junction_node.junction;
		const title = this.junctionTitle(junction_node);
		this.setDetailsTitle(title);
		const rows = junction.devices.map(device => {
			const known = this.nodes.get(String(device.id));
			const label = known?.label ?? known?.name ?? device.name ?? device.id;
			return `<tr class="topology-group-row" data-device-id="${this.escape(device.id)}"><td title="${this.escape(label)}">${this.escape(label)}</td></tr>`;
		}).join('');
		this.details.innerHTML = `<p class="topology-group-help">${this.escape(<?= json_encode(_('Several devices are seen on one port: a switch or a hub that is not monitored sits between them and this device. Nothing here is stored.')) ?>)}</p>` +
			`<section class="topology-group"><table><thead><tr><th>${this.escape(<?= json_encode(_('Port')) ?>)}</th></tr></thead><tbody><tr class="topology-junction-port"><td>${this.escape(junction.port_name ?? '–')}</td></tr></tbody></table></section>` +
			`<section class="topology-group"><table><thead><tr><th>${this.escape(<?= json_encode(_('Devices behind the port')) ?>)} (${junction.devices.length})</th></tr></thead><tbody>${rows}</tbody></table></section>`;
		this.details.querySelectorAll('.topology-group-row').forEach(row => row.addEventListener('click', () => {
			const device = this.nodes.get(row.dataset.deviceId);
			if (device) {
				this.guard(() => this.openFromPanel(() => this.selectNode(device)));
			}
		}));
		this.registerPanel('junction:' + junction.port_id, title, () => {
			const current = (this.drawn_nodes ?? []).find(candidate => candidate.id === `junction:${junction.port_id}`);
			return current ? this.showJunction(current) : undefined;
		}, {kind: 'junction', id: `junction:${junction.port_id}`, parent: String(junction_node.parent)});
	}

	// "Fold <N> devices" in a panel (the parent's, and the group's): one delegated lookup after the panel is built.
	wireFoldButtons() {
		this.details.querySelectorAll('[data-parent]').forEach(button => button.addEventListener('click', () => this.guard(() => this.toggleGroup(button.dataset.parent))));
	}

	// The text of the one control (§12.8, 2.81): "Unfold <N> devices" while the group is folded, "Fold <N> devices" while it is unfolded.
	groupToggleText(parent_id, count) {
		return (this.unfolded.has(String(parent_id)) ? <?= json_encode(_('Fold %1$s devices')) ?> : <?= json_encode(_('Unfold %1$s devices')) ?>).replace('%1$s', String(count));
	}

	// The control folds or unfolds the group and shows the panel again (same panel, same state, same stack) with the other text.
	async toggleGroup(parent_id) {
		const panel = this.current_panel;
		const state = this.capturePanelState();
		await this.withoutPan(async () => {
			if (this.unfolded.has(String(parent_id))) {
				this.foldGroup(parent_id);
			}
			else {
				this.unfoldGroup(parent_id);
			}
			if (panel) {
				await panel.reopen();
				this.restorePanelState(state);
			}
		});
	}

	// The parent of an unfolded group gets the control "Fold <N> devices" at the top of its panel.
	addFoldControl(node) {
		const group = (this.leaf_groups ?? []).find(candidate => String(candidate.parent) === String(node.id));
		const actions = this.details.querySelector('.topology-node-actions');
		if (!group || !actions) {
			return;
		}
		const button = document.createElement('button');
		button.type = 'button';
		button.className = 'btn-alt';
		button.dataset.parent = String(group.parent);
		button.textContent = this.groupToggleText(group.parent, group.devices.length);
		actions.prepend(button);
		this.wireFoldButtons();
	}

	// The names of the device classes (§12.2), singular and plural, for the tooltip of a group and the legend.
	classNames() {
		return {
			access_point: [<?= json_encode(_('%1$s access point')) ?>, <?= json_encode(_('%1$s access points')) ?>],
			phone: [<?= json_encode(_('%1$s phone')) ?>, <?= json_encode(_('%1$s phones')) ?>],
			switch: [<?= json_encode(_('%1$s switch')) ?>, <?= json_encode(_('%1$s switches')) ?>],
			router: [<?= json_encode(_('%1$s router')) ?>, <?= json_encode(_('%1$s routers')) ?>],
			station: [<?= json_encode(_('%1$s station')) ?>, <?= json_encode(_('%1$s stations')) ?>],
			generic: [<?= json_encode(_('%1$s other device')) ?>, <?= json_encode(_('%1$s other devices')) ?>]
		};
	}

	// A manual Device that was created in this page and not dragged gets its place again when it is linked (§9.7, 2.84): next to the node it is linked to, as a leaf.
	forgetPlaceOfNewManual(device_ids) {
		device_ids.map(String).forEach(id => {
			const node = this.nodes.get(id);
			if (node?.manual && this.new_manual.has(id) && (node.fx === undefined || node.fx === null)) {
				this.replace_ids.add(id);
			}
		});
	}

	// ---- Manual Device (§9.7, 2.80): the dialogs ----

	// The create / edit dialog: a Zabbix form dialog with the name, the class, the hardware ID, the management address and the ports (rows: name, description). `device` is the node of a manual Device
	// to edit, or null to create one. The refusals of the server are shown in the dialog.
	async deviceDialog(device) {
		const esc = value => this.escape(value);
		const request = (action, body) => this.request(action, {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify(body)});
		const classes = [['router', <?= json_encode(_('Router')) ?>], ['switch', <?= json_encode(_('Switch')) ?>], ['access_point', <?= json_encode(_('Access point')) ?>],
			['phone', <?= json_encode(_('Phone')) ?>], ['station', <?= json_encode(_('Station')) ?>], ['other', <?= json_encode(_('Other')) ?>]];
		// the manual ports of the Device (edit): rows with an id; a port with a link is shown, but not renamed or deleted
		let existing = [];
		if (device) {
			const answer = await this.request(`topology.ports.get&id=${encodeURIComponent(device.id)}`);
			existing = Object.values(answer.groups ?? {}).flat().filter(port => port.manual).map(port => ({id: String(port.id), name: port.port, description: port.description ?? '',
				linked: port.group === 'connected' || port.edge_id !== null && port.edge_id !== undefined}));
			// a manual port with a link is in the group "connected"; the groups of getPorts() list it too (the Links section reads it), so it is found here
		}
		const form = document.createElement('form');
		form.className = 'topology-device-form';
		form.setAttribute('autocomplete', 'off');
		const port_row = (port = {id: '', name: '', description: '', linked: false}) => `<tr class="topology-port-row" data-port-id="${esc(port.id)}">` +
			`<td><input type="text" class="topology-port-name-input" maxlength="255" value="${esc(port.name)}" ${port.linked ? 'disabled title="' + esc(<?= json_encode(_('The port has a link: unlink the port first.')) ?>) + '"' : ''}></td>` +
			`<td><input type="text" class="topology-port-description-input" maxlength="255" value="${esc(port.description)}"></td>` +
			`<td><button type="button" class="btn-link topology-port-remove" ${port.linked ? 'disabled' : ''}>${esc(<?= json_encode(_('Remove')) ?>)}</button></td></tr>`;
		form.innerHTML = `<output class="topology-dialog-error" hidden></output><div class="form-grid">` +
			`<label for="topology-device-name" class="form-label-asterisk">${esc(<?= json_encode(_('Name')) ?>)}</label><div class="form-field"><input type="text" id="topology-device-name" maxlength="255" value="${esc(device?.name ?? '')}" required></div>` +
			`<label for="topology-device-class">${esc(<?= json_encode(_('Device class')) ?>)}</label><div class="form-field"><select id="topology-device-class">${classes.map(([value, label]) => `<option value="${value}" ${(device?.chosen_class ?? 'router') === value ? 'selected' : ''}>${esc(label)}</option>`).join('')}</select></div>` +
			`<label for="topology-device-mac">${esc(<?= json_encode(_('Hardware ID')) ?>)}</label><div class="form-field"><input type="text" id="topology-device-mac" maxlength="64" placeholder="00:1a:2b:3c:4d:5e" value="${esc(device?.chassis_id ?? '')}"></div>` +
			`<label for="topology-device-ip">${esc(<?= json_encode(_('Management address')) ?>)}</label><div class="form-field"><input type="text" id="topology-device-ip" maxlength="64" value="${esc(device?.mgmt_ip ?? '')}"></div>` +
			`<label>${esc(<?= json_encode(_('Ports')) ?>)}</label><div class="form-field"><table class="topology-port-table"><thead><tr><th>${esc(<?= json_encode(_('Name')) ?>)}</th><th>${esc(<?= json_encode(_('Description')) ?>)}</th><th></th></tr></thead>` +
			`<tbody>${existing.map(port_row).join('')}</tbody></table><button type="button" class="btn-link topology-port-add">${esc(<?= json_encode(_('Add')) ?>)}</button></div></div>` +
			`<p class="topology-group-help">${esc(<?= json_encode(_('A host polled by SNMP does not need this: it needs a link only.')) ?>)}</p>`;
		const tbody = form.querySelector('tbody');
		const wire_row = row => row.querySelector('.topology-port-remove').addEventListener('click', () => {
			if (row.dataset.portId === '') {
				row.remove();
			}
			else {
				row.hidden = true;
				row.dataset.removed = '1';
			}
		});
		tbody.querySelectorAll('tr').forEach(wire_row);
		form.querySelector('.topology-port-add').addEventListener('click', () => {
			tbody.insertAdjacentHTML('beforeend', port_row());
			wire_row(tbody.lastElementChild);
			tbody.lastElementChild.querySelector('input').focus();
		});
		const error = text => {
			const output = form.querySelector('.topology-dialog-error');
			output.textContent = text;
			output.hidden = text === '';
		};
		// a refusal stays until the operator changes the form
		form.addEventListener('input', () => error(''));
		const submit = async overlay => {
			error('');
			const value = id => form.querySelector(id).value.trim();
			const body = {name: value('#topology-device-name'), device_class: value('#topology-device-class'), chassis_id: value('#topology-device-mac'), mgmt_ip: value('#topology-device-ip')};
			const rows = Array.from(tbody.querySelectorAll('tr'));
			try {
				let device_id;
				if (device) {
					await request('topology.devices.update', {id: device.id, ...body});
					device_id = String(device.id);
					for (const row of rows) {
						const name = row.querySelector('.topology-port-name-input').value.trim();
						const description = row.querySelector('.topology-port-description-input').value.trim();
						const port = existing.find(candidate => candidate.id === row.dataset.portId);
						if (row.dataset.removed === '1') {
							await request('topology.ports.delete', {id: row.dataset.portId});
						}
						else if (port && !port.linked && (port.name !== name || port.description !== description)) {
							await request('topology.ports.update', {id: port.id, name, description});
						}
						else if (!port && (name !== '' || description !== '')) {
							await request('topology.ports.create', {device_id, name, description});
						}
						else if (port && port.linked && port.description !== description) {
							await request('topology.ports.update', {id: port.id, description});
						}
					}
				}
				else {
					body.ports = rows.map(row => ({name: row.querySelector('.topology-port-name-input').value.trim(), description: row.querySelector('.topology-port-description-input').value.trim()}))
						.filter(port => port.name !== '' || port.description !== '');
					device_id = String((await request('topology.devices.create', body)).id);
				}
				overlayDialogueDestroy(overlay.dialogueid);
				// the Device is drawn and selected in the current view, also in a scoped one, until the filter is applied (§12.1)
				this.include_ids.add(device_id);
				if (!device) {
					this.new_manual.add(device_id);
				}
				await this.loadDevices({reselect_device: device_id});
			}
			catch (exception) {
				error(exception.message);
				overlay.unsetLoading();
			}
			return false;
		};
		overlayDialogue({
			title: device ? <?= json_encode(_('Edit device')) ?> : <?= json_encode(_('Create device')) ?>,
			content: form,
			class: 'modal-popup modal-popup-medium',
			buttons: [
				{title: device ? <?= json_encode(_('Update')) ?> : <?= json_encode(_('Create')) ?>, class: 'dialogue-widget-save', isSubmit: true, focused: true, action: overlay => { submit(overlay); return false; }},
				{title: <?= json_encode(_('Cancel')) ?>, class: 'btn-alt', cancel: true, action: () => {}}
			],
			dialogueid: 'topology-device-dialog'
		}, {dialogueid: 'topology-device-dialog'});
		form.addEventListener('submit', event => event.preventDefault());
	}

	// Add a port to a manual Device, or rename one and change its description (§9.7).
	async portDialog(device, reselect_node, port) {
		const esc = value => this.escape(value);
		const request = (action, body) => this.request(action, {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify(body)});
		const form = document.createElement('form');
		form.setAttribute('autocomplete', 'off');
		form.innerHTML = `<output class="topology-dialog-error" hidden></output><div class="form-grid">` +
			`<label for="topology-port-name" class="form-label-asterisk">${esc(<?= json_encode(_('Name')) ?>)}</label><div class="form-field"><input type="text" id="topology-port-name" maxlength="255" value="${esc(port?.name ?? '')}" required></div>` +
			`<label for="topology-port-description">${esc(<?= json_encode(_('Description')) ?>)}</label><div class="form-field"><input type="text" id="topology-port-description" maxlength="255" value="${esc(port?.description ?? '')}"></div></div>`;
		overlayDialogue({
			title: port ? <?= json_encode(_('Rename port')) ?> : <?= json_encode(_('Add port')) ?>,
			content: form,
			class: 'modal-popup modal-popup-medium',
			buttons: [
				{title: port ? <?= json_encode(_('Update')) ?> : <?= json_encode(_('Add')) ?>, class: 'dialogue-widget-save', isSubmit: true, focused: true, action: overlay => {
					const output = form.querySelector('.topology-dialog-error');
					const body = {name: form.querySelector('#topology-port-name').value.trim(), description: form.querySelector('#topology-port-description').value.trim()};
					(port ? request('topology.ports.update', {id: port.id, ...body}) : request('topology.ports.create', {device_id: device.id, ...body})).then(async () => {
						overlayDialogueDestroy(overlay.dialogueid);
						await this.selectNode(reselect_node);
					}).catch(exception => {
						output.textContent = exception.message;
						output.hidden = false;
						overlay.unsetLoading();
					});
					return false;
				}},
				{title: <?= json_encode(_('Cancel')) ?>, class: 'btn-alt', cancel: true, action: () => {}}
			],
			dialogueid: 'topology-port-dialog'
		}, {dialogueid: 'topology-port-dialog'});
		form.addEventListener('submit', event => event.preventDefault());
	}

	// Bind a manual port to an interface of the host of its Device (§9.7, 2.91): the list of the interfaces with the name and the index; one that another Port has is shown with that Port and not selectable.
	async bindDialog(device, reselect_node, port) {
		const esc = value => this.escape(value);
		const request = (action, body) => this.request(action, {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify(body)});
		const answer = await request('topology.ports.interfaces', {id: port.id});
		const interfaces = answer.interfaces ?? [];
		const form = document.createElement('form');
		form.setAttribute('autocomplete', 'off');
		// task 118 (2.99): an interface that is found by name has no index: its value is the name ("n:<name>"), the index column shows "—"; one with an index has "i:<index>"
		const key_of = item => (item.if_index === null || item.if_index === undefined ? `n:${item.name}` : `i:${item.if_index}`);
		const rows = interfaces.map((item, position) => {
			const taken = item.port && String(item.port.id) !== String(port.id);
			return `<tr class="topology-bind-row"><td><input type="radio" name="topology-bind-interface" value="${esc(key_of(item))}" id="topology-bind-${esc(item.if_index ?? 'n' + position)}"${taken ? ' disabled' : ''}${item.port && !taken ? ' checked' : ''}></td>` +
				`<td><label for="topology-bind-${esc(item.if_index ?? 'n' + position)}">${esc(item.name)}</label></td><td>${item.if_index === null || item.if_index === undefined ? '—' : esc(item.if_index)}</td>` +
				`<td>${taken ? esc(<?= json_encode(_('used by %1$s')) ?>.replace('%1$s', item.port.name)) : ''}</td></tr>`;
		}).join('');
		form.innerHTML = `<output class="topology-dialog-error" hidden></output>` + (interfaces.length
			? `<div class="topology-bind-list"><table class="list-table topology-bind-table"><thead><tr><th></th><th>${esc(<?= json_encode(_('Interface')) ?>)}</th><th>${esc(<?= json_encode(_('Index')) ?>)}</th><th></th></tr></thead><tbody>${rows}</tbody></table></div>`
			: `<div class="topology-empty">${esc(<?= json_encode(_('The host has no interfaces that you can read.')) ?>)}</div>`);
		overlayDialogue({
			title: <?= json_encode(_('Bind to interface')) ?>.concat(' – ', port.name),
			content: form,
			class: 'modal-popup modal-popup-medium',
			buttons: [
				{title: <?= json_encode(_('Bind')) ?>, class: 'dialogue-widget-save', isSubmit: true, focused: true, action: overlay => {
					const output = form.querySelector('.topology-dialog-error');
					const chosen = form.querySelector('input[name="topology-bind-interface"]:checked');
					if (!chosen) {
						output.textContent = <?= json_encode(_('Choose an interface.')) ?>;
						output.hidden = false;
						overlay.unsetLoading();
						return false;
					}
					const body = chosen.value.startsWith('n:') ? {id: port.id, name: chosen.value.slice(2)} : {id: port.id, if_index: Number(chosen.value.slice(2))};
					request('topology.ports.bind', body).then(async () => {
						overlayDialogueDestroy(overlay.dialogueid);
						await this.selectNode(reselect_node);
					}).catch(exception => {
						output.textContent = exception.message;
						output.hidden = false;
						overlay.unsetLoading();
					});
					return false;
				}},
				{title: <?= json_encode(_('Cancel')) ?>, class: 'btn-alt', cancel: true, action: () => {}}
			],
			dialogueid: 'topology-bind-dialog'
		}, {dialogueid: 'topology-bind-dialog'});
		form.addEventListener('submit', event => event.preventDefault());
	}

	classLabel(name) {
		return {access_point: <?= json_encode(_('Access point')) ?>, phone: <?= json_encode(_('Phone')) ?>, switch: <?= json_encode(_('Switch')) ?>,
			router: <?= json_encode(_('Router')) ?>, station: <?= json_encode(_('Station')) ?>, other: <?= json_encode(_('Other')) ?>, generic: <?= json_encode(_('Generic (no class)')) ?>}[name] ?? <?= json_encode(_('Generic (no class)')) ?>;
	}

	render() {
		const G = TopologyGraph;
		const element = this.canvas.node();
		const bounds = element.getBoundingClientRect();
		// §12.1 "Deterministic layout" (2.85): the size of the graph area is taken in whole pixels: it differs at the fourth decimal between two loads of the same window (the layout of the page settles in
		// a different order), and the layout area, the start of the force and so the first layout followed it (task 101: two layouts in 1 of 5 loads at 1500 × 900)
		const view_width = Math.round(bounds.width) || 800;
		const view_height = Math.round(bounds.height) || 500;
		const padding = 24;

		// topology-1-model.md "UI" (graph): a Device with an active represented_by has no on-screen position of its own at all — only its Host/Proxy shows, styled exactly as any
		// other Host/Proxy would be. node.device is the Device of a Host/Proxy (for showPanel()'s Device section — see selectNode()). Only counts when both sides are loaded.
		const device_of_target = new Map(); // Host/Proxy id (string) -> its represented Device node object
		const target_id_of_device = new Map(); // Device id (string) -> its represented_by target's id (string)
		this.links.forEach(link => {
			if (link.type === 'represented_by' && this.nodes.has(link.source) && this.nodes.has(link.target)) {
				device_of_target.set(link.target, this.nodes.get(link.source));
				target_id_of_device.set(link.source, link.target);
			}
		});
		this.nodes.forEach((node, id) => {
			node.device = device_of_target.get(id) ?? null;
		});
		const has_duplicates = node => (node.duplicates ?? []).length > 0;
		let nodes = [...this.nodes.values()].filter(node => !(node.type === 'device' && target_id_of_device.has(String(node.id))));
		// the filter "possible duplicate" (V-17): a node is a duplicate node when it, or the Device it represents, has a candidate; the others are context
		const is_duplicate_node = node => node.type === 'device' ? has_duplicates(node) : Boolean(node.device && has_duplicates(node.device));
		const resolve_display_id = id => target_id_of_device.get(id) ?? id;
		let rendered_ids = new Set(nodes.map(node => String(node.id)));
		let simulation_links = this.links
			.filter(link => link.type !== 'represented_by')
			.map(link => ({...link, source: resolve_display_id(link.source), target: resolve_display_id(link.target)}))
			.filter(link => rendered_ids.has(link.source) && rendered_ids.has(link.target));

		// Junction projections (§12.6): one small node on the anchor Port, a line from the reporter to it and a line from it to each Device; the direct lines of the Ports to those Devices are not
		// drawn, the line of the projection to the Device takes their channels (severity, stale, superseded, manual). Only Devices that are in the view are members; fewer than two: no projection.
		const junction_nodes = [];
		const junction_lines = [];
		(this.junctions ?? []).forEach(junction => {
			const reporter = resolve_display_id(String(junction.device_id));
			const members = [...new Set(junction.devices.map(device => resolve_display_id(String(device.id))).filter(id => rendered_ids.has(id)))];
			if (!rendered_ids.has(reporter) || members.length < 2) {
				return;
			}
			const port_id = String(junction.port_id);
			// the node object is kept between drawings (as a group node is), so that its place is kept: an unfold of another group does not place it again (task 106)
			this.junction_cache ??= new Map();
			const junction_node = this.junction_cache.get(port_id) ?? {id: `junction:${port_id}`, type: 'junction'};
			Object.assign(junction_node, {parent: reporter, name: junction.port_name ?? '', label: junction.port_name ?? '', devices: members, junction});
			this.junction_cache.set(port_id, junction_node);
			junction_nodes.push(junction_node);
			junction_lines.push({type: 'junction_link', source: reporter, target: junction_node.id});
			const carried = new Map();
			simulation_links = simulation_links.filter(link => {
				if (link.type !== 'physical_link' || ![String(link.source_port_id), String(link.target_port_id)].includes(port_id)) {
					return true;
				}
				const far = String(link.source_port_id) === port_id ? link.target : link.source;
				if (!members.includes(far)) {
					return true;
				}
				carried.set(far, [...(carried.get(far) ?? []), link]);
				return false;
			});
			members.forEach(member => {
				const own = (carried.get(member) ?? []).find(link => !link.superseded_at) ?? (carried.get(member) ?? [])[0] ?? {};
				junction_lines.push({...own, type: 'junction_member', source: junction_node.id, target: member, junction_id: junction_node.id});
			});
		});

		// Size (§12.2): large = Host, Proxy, a Device without association with two or more links; small = a leaf, a Device without a link, a group node. The count is by the drawn
		// links before the leaves are folded into groups, so a fold does not change the size of its parent.
		const link_count = new Map();
		[...simulation_links, ...junction_lines.filter(link => link.type === 'junction_member')].filter(link => ['physical_link', 'device_link', 'junction_member'].includes(link.type)).forEach(link => {
			link_count.set(link.source, (link_count.get(link.source) ?? 0) + 1);
			link_count.set(link.target, (link_count.get(link.target) ?? 0) + 1);
		});

		// Leaf groups (§12.8): the leaves of a group are not drawn while it is folded; one small group node with one line to its node is.
		this.group_nodes ??= new Map();
		const hidden_ids = new Set();
		const group_nodes = [];
		(this.leaf_groups ?? []).forEach(group => {
			const parent_id = String(group.parent);
			if (!rendered_ids.has(parent_id) || this.unfolded.has(parent_id)) {
				return;
			}
			const members = group.devices.map(String).filter(id => rendered_ids.has(id));
			if (!members.length) {
				return;
			}
			members.forEach(id => hidden_ids.add(id));
			const group_node = this.group_nodes.get(parent_id) ?? {id: `group:${parent_id}`, type: 'group', parent: parent_id};
			Object.assign(group_node, {devices: members, count: members.length,
				label: <?= json_encode(_('%1$s devices')) ?>.replace('%1$s', String(members.length))});
			group_node.name = group_node.label;
			this.group_nodes.set(parent_id, group_node);
			group_nodes.push(group_node);
		});
		nodes = nodes.filter(node => !hidden_ids.has(String(node.id)));
		simulation_links = simulation_links.filter(link => !hidden_ids.has(link.source) && !hidden_ids.has(link.target));

		// "Show devices without links" (§12.1, V-1): a Device (drawn as itself or as its Host) is drawn only when it has a link that the graph draws; the switch, the filter
		// "Possible duplicates only" and the focus host draw the others. A Host without a Device and without a drawn link follows the same rule (091); a Proxy is in the view by the view scope rules.
		const focus_id = this.focusNodeId(nodes);
		// (the rule is for every node: a Host without a Device too; a Proxy is in the view by the view scope rules)
		nodes = nodes.filter(node => G.isDrawn(node, {has_link: link_count.has(String(node.id)), show_unlinked: this.show_unlinked, only_duplicates: this.only_duplicates,
			is_focus: String(node.id) === focus_id, is_pinned: this.pinned.has(String(node.id))}));
		nodes.push(...group_nodes.filter(group_node => nodes.some(node => String(node.id) === group_node.parent)));
		// a projection is drawn with its reporter and at least two of its Devices (the others of the view may be folded into a group or hidden)
		const drawn_before = new Set(nodes.map(node => String(node.id)));
		junction_nodes.filter(junction_node => drawn_before.has(junction_node.parent) && junction_node.devices.filter(id => drawn_before.has(id)).length >= 2).forEach(junction_node => {
			// 2.89 (§12.2): the label of the node is "<reporter> <port>"
			const reporter_label = nodes.find(node => String(node.id) === junction_node.parent)?.label ?? junction_node.junction.device_name ?? '';
			junction_node.label = `${reporter_label} ${junction_node.junction.port_name ?? ''}`.trim();
			nodes.push(junction_node);
			simulation_links.push(...junction_lines.filter(link => link.junction_id === junction_node.id || (link.type === 'junction_link' && link.target === junction_node.id)));
		});
		rendered_ids = new Set(nodes.map(node => String(node.id)));
		{
			// 2.90 (§12.1): the list is in the start order too (the collision step and the arcs walk it): by the label, the hardware ID and the kind, not by the id
			const by_id = new Map(nodes.map(node => [String(node.id), node]));
			nodes.sort((a, b) => G.compareNodes(a, b, by_id));
		}
		group_nodes.forEach(group_node => {
			if (rendered_ids.has(group_node.id)) {
				simulation_links.push({type: 'group_link', source: group_node.parent, target: group_node.id});
			}
		});
		simulation_links = simulation_links.filter(link => rendered_ids.has(link.source) && rendered_ids.has(link.target));

		nodes.forEach(node => G.layoutNode(node, G.sizeOf(node, link_count.get(String(node.id)) ?? 0), (text, font) => this.measureText(text, font)));

		// ---- placement (§12.1 "Node placement") ----
		// ONE width of a bundle label for the place, the room of a layout and the room of a ring (§12.3, 2.79): the real font of the page
		const label_measure = (text, font) => this.measureText(text, font);
		const relayout = this.relayout || !this.view_size;
		this.relayout = false;
		const area = nodes.reduce((sum, node) => sum + (node.box.w + 24) * (node.box.h + 24), 0) * 2.4;
		const scale = Math.max(1, Math.sqrt(area / (view_width * view_height)));
		let width = Math.max(view_width * scale, !relayout && this.view_size ? this.view_size.width : 0);
		let height = Math.max(view_height * scale, !relayout && this.view_size ? this.view_size.height : 0);
		const node_by_id = new Map(nodes.map(node => [String(node.id), node]));
		const placed_before = nodes.filter(node => Number.isFinite(node.x) && Number.isFinite(node.y));
		const place_new_near_parents = () => {
			// a node without a position (an unfolded leaf, a group node, a node that appeared): near its neighbor, then the collision step moves it to a free place
			nodes.filter(node => !Number.isFinite(node.x) || !Number.isFinite(node.y)).forEach((node, index) => {
				const neighbor_link = simulation_links.find(link => link.source === String(node.id) || link.target === String(node.id));
				const other = neighbor_link ? node_by_id.get(neighbor_link.source === String(node.id) ? neighbor_link.target : neighbor_link.source) : null;
				const base = other && Number.isFinite(other.x) ? other : {x: width / 2, y: height / 2};
				const angle = index * 2.399963;
				node.x = base.x + Math.cos(angle) * 90;
				node.y = base.y + Math.sin(angle) * 90;
			});
		};
		const new_arcs = [];      // the arcs of this drawing: {parent, leaves}
		// 2.89 (§12.6 "Placement"): a Junction is placed like a leaf of its reporter, and its Devices that have no other line (the private members) on an arc around the Junction. The other members stay
		// where the layout puts them
		const junction_groups = nodes.filter(node => node.type === 'junction').map(junction_node => {
			const degree = id => simulation_links.filter(link => link.source === id || link.target === id).length;
			return {junction: junction_node, reporter: node_by_id.get(junction_node.parent),
				members: junction_node.devices.filter(id => node_by_id.has(id) && degree(id) === 1).map(id => node_by_id.get(id))};
		}).filter(group => group.reporter);
		const junction_owned = new Set(junction_groups.flatMap(group => [String(group.junction.id), ...group.members.map(member => String(member.id))]));
		const place_junctions = (all) => {
			junction_groups.forEach(({junction, reporter, members}) => {
				if (!Number.isFinite(reporter.x)) {
					return;
				}
				const owned = new Set([junction, ...members]);
				// the angles that a parent must keep free: its lines to nodes that are not in the group, and the nodes near it
				const angles_of = parent => {
					const result = simulation_links.filter(link => link.source === String(parent.id) || link.target === String(parent.id)).map(link => {
						const other = node_by_id.get(link.source === String(parent.id) ? link.target : link.source);
						return other && Number.isFinite(other.x) && !owned.has(other) ? Math.atan2(other.y - parent.y, other.x - parent.x) : null;
					}).filter(angle => angle !== null);
					nodes.forEach(other => {
						if (other !== parent && !owned.has(other) && Number.isFinite(other.x) && Math.hypot(other.x - parent.x, other.y - parent.y) < 420) {
							const distance = Math.hypot(other.x - parent.x, other.y - parent.y);
							result.push({angle: Math.atan2(other.y - parent.y, other.x - parent.x), half: Math.asin(Math.min(0.99, (Math.hypot(other.box.w, other.box.h) / 2 + 8) / Math.max(distance, 1)))});
						}
					});
					return result;
				};
				const fix = (placed) => placed.forEach(({node, x, y}) => {
					node.x = x;
					node.y = y;
					node.fx = x;
					node.fy = y;
					node.temp_fixed = true;
				});
				const fresh_junction = all || !Number.isFinite(junction.x);
				if (fresh_junction) {
					junction.x = undefined;
					junction.y = undefined;
					if (reporter.fx === undefined || reporter.fx === null) {
						reporter.fx = reporter.x;
						reporter.fy = reporter.y;
						reporter.temp_fixed = true;
					}
					fix(G.arcPlace(reporter, [junction], angles_of(reporter)));
					G.clearSector(reporter, [junction], nodes.filter(other => Number.isFinite(other.x) && !owned.has(other)));
					new_arcs.push({parent: reporter, leaves: [junction]});
				}
				const to_place = (fresh_junction ? members : members.filter(member => !Number.isFinite(member.x))).sort((a, b) => String(a.label).localeCompare(String(b.label), undefined, {numeric: true}));
				if (to_place.length) {
					if (junction.fx === undefined || junction.fx === null) {
						junction.fx = junction.x;
						junction.fy = junction.y;
						junction.temp_fixed = true;
					}
					const arc = G.arcPlace(junction, to_place, angles_of(junction).concat([{angle: Math.atan2(reporter.y - junction.y, reporter.x - junction.x), half: 0.5}]));
					(this.arc_info ??= new Map()).set(String(junction.id), arc.info);
					fix(arc);
					G.clearSector(junction, to_place, nodes.filter(other => Number.isFinite(other.x) && !owned.has(other)));
					new_arcs.push({parent: junction, leaves: to_place});
				}
			});
		};
		if (relayout) {
			nodes.forEach(node => {
				if (node.fx === undefined || node.fx === null) {
					node.x = undefined;
					node.y = undefined;
				}
			});
			if (focus_id !== null) {
				// radial: the focus host in the centre, a node at distance d on ring d, a node with no path on the outer ring
				let radius = G.placeRadial(nodes, simulation_links, focus_id, {x: width / 2, y: height / 2}, label_measure);
				const extent = Math.max(...nodes.map(node => Math.max(node.box.w, node.box.h))) + padding;
				width = Math.max(width, 2 * (radius + extent));
				height = Math.max(height, 2 * (radius + extent));
				radius = G.placeRadial(nodes, simulation_links, focus_id, {x: width / 2, y: height / 2}, label_measure);
			}
			else {
				// §12.1 "Deterministic layout" (2.85): the same data and the same window give the same first layout: the start positions come from the nodes in the order of their id (a group node:
				// after the numbers, by its text), and the simulation has a fixed seed and a fixed number of steps (a node of the list that came in another order does not change it)
				// 2.90: by the label, the hardware ID and the kind (G.compareNodes), not by the id
				const ordered = [...nodes].sort((a, b) => G.compareNodes(a, b, node_by_id));
				ordered.forEach((node, index) => {
					if (!Number.isFinite(node.x)) {
						const angle = index * 2.399963;
						const r = 18 * Math.sqrt(index + 1);
						node.x = width / 2 + Math.cos(angle) * r;
						node.y = height / 2 + Math.sin(angle) * r;
					}
				});
				let seed = 20851;
				const seeded = () => {
					seed = (seed + 0x6D2B79F5) | 0;
					let t = Math.imul(seed ^ (seed >>> 15), 1 | seed);
					t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t;

					return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
				};
				const force_links = simulation_links.map(link => ({source: node_by_id.get(link.source), target: node_by_id.get(link.target), near: ['junction_link', 'junction_member'].includes(link.type)}));
				const collide = () => {
					for (let i = 0; i < ordered.length; i++) {
						for (let j = i + 1; j < ordered.length; j++) {
							const a = ordered[i];
							const b = ordered[j];
							if (G.overlappingPairs([a, b], G.BOX_GAP).length) {
								const dx = (a.x - b.x) || 0.01;
								const dy = (a.y - b.y) || 0.01;
								const norm = Math.hypot(dx, dy);
								a.vx += dx / norm * 3;
								a.vy += dy / norm * 3;
								b.vx -= dx / norm * 3;
								b.vy -= dy / norm * 3;
							}
						}
					}
				};
				const simulation = d3.forceSimulation(ordered).randomSource(seeded)
					.force('link', d3.forceLink(force_links).distance(link => link.near ? 75 : 130).strength(link => link.near ? 1 : 0.6))
					.force('charge', d3.forceManyBody().strength(-380))
					.force('center', d3.forceCenter(width / 2, height / 2))
					.force('x', d3.forceX(width / 2).strength(0.03))
					.force('y', d3.forceY(height / 2).strength(0.03))
					.force('collide', collide)
					.stop();
				for (let i = 0; i < 300; i++) {
					simulation.tick();
				}
			}
			place_junctions(true);
		}
		else {
			// 2.72 (§12.8): the nodes that have no place yet (the leaves of a group that was just unfolded, the group node of a fold, a node that appeared) go on an arc around the node
			// they are linked to, in its largest free angle, in the order of the parent's local port; the radius grows to fit. The nodes that were placed stay unless they are in the
			// way: the collision step moves them away (a dragged node does not move).
			const fresh = nodes.filter(node => (!Number.isFinite(node.x) || !Number.isFinite(node.y)) && !junction_owned.has(String(node.id)));
			const by_parent = new Map();
			fresh.forEach(node => {
				const link = simulation_links.find(candidate => (candidate.source === String(node.id) || candidate.target === String(node.id)));
				const other_id = link ? (link.source === String(node.id) ? link.target : link.source) : null;
				const other = other_id === null ? null : node_by_id.get(other_id);
				const parent = other && Number.isFinite(other.x) ? other : null;
				const key = parent ? String(parent.id) : '';
				if (!by_parent.has(key)) {
					by_parent.set(key, {parent, leaves: []});
				}
				by_parent.get(key).leaves.push({node, link});
			});
			by_parent.forEach(({parent, leaves}) => {
				if (parent === null) {
					// no link: a free place in the visible part of the graph (§9.7, 2.84), never on a line; else the middle
					const transform = this.zoom_transform ?? d3.zoomIdentity;
					const placed = nodes.filter(other => Number.isFinite(other.x) && Number.isFinite(other.y));
					const chords = simulation_links.map(link => ({a: node_by_id.get(link.source), b: node_by_id.get(link.target), index: 0, count: 1}))
						.filter(line => line.a && line.b && Number.isFinite(line.a.x) && Number.isFinite(line.b.x));
					leaves.forEach(({node}, i) => {
						const k = transform.k || 1;
						const left = Math.max(padding, (0 - transform.x) / k), right = Math.min(width - padding, (view_width - transform.x) / k);
						const top = Math.max(padding, (0 - transform.y) / k), bottom = Math.min(height - padding, (view_height - transform.y) / k);
						const spots = [];
						for (let x = left + node.box.w / 2; x <= right - node.box.w / 2; x += 20) {
							for (let y = top - node.box.top; y <= bottom - node.box.bottom; y += 20) {
								spots.push([x, y, Math.hypot(x - (left + right) / 2, y - (top + bottom) / 2)]);
							}
						}
						spots.sort((p, q) => p[2] - q[2]);
						const spot = spots.find(([x, y]) => {
							const probe = {x, y, box: node.box};
							return !placed.some(other => G.overlappingPairs([probe, other], G.BOX_GAP).length) && !chords.some(line => G.lineCrossesNode(line, probe, 10));
						});
						node.x = spot ? spot[0] : width / 2 + 40 * i;
						node.y = spot ? spot[1] : height / 2;
						placed.push(node);
					});
					return;
				}
				const local_port = ({node, link}) => String((link.source === String(node.id) ? link.target_port : link.source_port) ?? '');
				leaves.sort((a, b) => local_port(a).localeCompare(local_port(b), undefined, {numeric: true}));
				const other_angles = simulation_links.filter(link => (link.source === String(parent.id) || link.target === String(parent.id))).map(link => {
					const other = node_by_id.get(link.source === String(parent.id) ? link.target : link.source);
					return other && Number.isFinite(other.x) && !leaves.some(leaf => leaf.node === other) ? Math.atan2(other.y - parent.y, other.x - parent.x) : null;
				}).filter(angle => angle !== null);
				// the nodes near the parent that the group does not touch are in the way too: the arc is where nothing is near
				nodes.forEach(other => {
					if (other !== parent && Number.isFinite(other.x) && !leaves.some(leaf => leaf.node === other) && Math.hypot(other.x - parent.x, other.y - parent.y) < 420) {
						const distance = Math.hypot(other.x - parent.x, other.y - parent.y);
						other_angles.push({angle: Math.atan2(other.y - parent.y, other.x - parent.x), half: Math.asin(Math.min(0.99, (Math.hypot(other.box.w, other.box.h) / 2 + 8) / Math.max(distance, 1)))});
					}
				});
				const arc = G.arcPlace(parent, leaves.map(leaf => leaf.node), other_angles);
				(this.arc_info ??= new Map()).set(String(parent.id), arc.info);
				arc.forEach(({node, x, y}) => {
					node.x = x;
					node.y = y;
					node.fx = x;      // fixed while the collision step makes room: the others move away
					node.fy = y;
					node.temp_fixed = true;
				});
				// the parent stays where it is while the collision step makes room: the arc is at its radius from it (a node that is not fixed could be moved by that step, and the leaves
				// would then be at another distance from it)
				if (parent.fx === undefined || parent.fx === null) {
					parent.fx = parent.x;
					parent.fy = parent.y;
					parent.temp_fixed = true;
				}
				// the nodes that are in the way of the arc turn out of its sector (the lines of the leaves must not cross another node)
				G.clearSector(parent, leaves.map(leaf => leaf.node), nodes.filter(other => Number.isFinite(other.x)));
				new_arcs.push({parent, leaves: leaves.map(leaf => leaf.node)});
			});
			place_junctions(false);
		}
		// the collision step: no box (shape and label) overlaps another; too small an area → a larger one
		// the nodes that are fixed (the leaves of an arc, a dragged node) keep their place: the area grows to hold them
		nodes.filter(node => node.fx !== undefined && node.fx !== null).forEach(node => {
			width = Math.max(width, node.x + node.box.w / 2 + padding);
			height = Math.max(height, node.y + node.box.bottom + padding);
		});
		for (let attempt = 0; attempt < 6; attempt++) {
			nodes.forEach(node => {
				if (node.fx !== undefined && node.fx !== null) {
					return;
				}
				node.x = Math.max(padding + node.box.w / 2, Math.min(width - padding - node.box.w / 2, node.x));
				node.y = Math.max(padding - node.box.top, Math.min(height - padding - node.box.bottom, node.y));
			});
			if (G.resolveOverlaps(nodes, {width, height, padding})) {
				break;
			}
			width *= 1.3;
			height *= 1.3;
		}
		nodes.forEach(node => {
			if (node.temp_fixed) {
				node.fx = undefined;
				node.fy = undefined;
				node.temp_fixed = false;
			}
		});
		// a node turned out of the way of a leaf line can stand on the line of a bundle, and a node moved off a bundle line can stand on a leaf line: the steps are repeated. 2.84 (§12.1 "No line
		// through a node"): a node that a drawn line crosses is moved off the line, then the collision step and the label room run again, until all hold (at most 8 passes)
		{
			const keep = link => !this.contradictionOf(link.edge_id)?.contradicted;
			const lines = G.bundleLinks(simulation_links.map(link => ({...link})), keep);
			const placement = G.parallelIndex(lines);
			const drawn_lines = lines.map((line, i) => ({a: node_by_id.get(line.source), b: node_by_id.get(line.target), ...placement[i]})).filter(line => line.a && line.b);
			// the labels of the LAG bundles and (2.101) the warning marks of the lines with a speed mismatch: both need a free point on their line
			const bundles = lines.map((line, i) => ({line, ...placement[i]})).filter(({line}) => line.type === 'lag_bundle' || G.markOf(line))
				.map(({line, index, count}) => ({a: node_by_id.get(line.source), b: node_by_id.get(line.target), width: line.type === 'lag_bundle' ? G.labelWidth(line.label, label_measure) : 0, index, count,
					mark: G.markOf(line) !== null, mark_only: line.type !== 'lag_bundle'}));
			// the leaves of an arc and their parent keep their places (the arc is as it was made); the others make room
			const held = new_arcs.flatMap(({parent, leaves}) => [parent, ...leaves]).filter(node => node.fx === undefined || node.fx === null);
			const before_solver = nodes.map(node => [node.x, node.y]);
			let best = {crossings: Infinity, positions: before_solver};      // the layout with the fewest crossings among those that have room for every label
			// the passes end when all hold, or when two passes in a row took away no crossing (the area does not grow for this: a crossing that stays is shown by --check overlap)
			{
				let clean = false;
				let crossings = Infinity;
				let stuck = 0;
				for (let pass = 0; pass < 8 && !clean; pass++) {
					held.forEach(node => {
						node.fx = node.x;
						node.fy = node.y;
					});
					// the places of the bundle labels now: the nodes that are moved off a line do not go onto a label (else the room for the label takes the node back across the line)
					const node_boxes = nodes.map(node => ({left: node.x - node.box.w / 2, right: node.x + node.box.w / 2, top: node.y + node.box.top, bottom: node.y + node.box.bottom}));
					const label_boxes = G.placeLabelsAndMarks(bundles, node_boxes);
					G.clearThroughLines(nodes, drawn_lines, {width, height, padding}, 40, label_boxes);
					// §12.3 (2.73, 2.76): the label of a LAG bundle needs a free point on its line; a layout that leaves none is stretched, after every change of the layout
					const room = G.makeLabelRoom(nodes, bundles, {width, height, padding});
					held.forEach(node => {
						node.fx = undefined;
						node.fy = undefined;
					});
					const now = G.linesThrough(nodes, drawn_lines, 2).length;
					clean = room && now === 0;
					if (room && now < best.crossings) {
						best = {crossings: now, positions: nodes.map(node => [node.x, node.y])};
					}
					if (now < crossings) {
						stuck = 0;
					}
					else if (++stuck >= 2 && !clean) {
						break;
					}
					crossings = now;
				}
				// a crossing that the passes could not take away: one more search, with four times the effort and another seed (the passes before are cheap, this one is made only when they failed)
				if (G.linesThrough(nodes, drawn_lines, 2).length > 0) {
					held.forEach(node => {
						node.fx = node.x;
						node.fy = node.y;
					});
					const node_boxes = nodes.map(node => ({left: node.x - node.box.w / 2, right: node.x + node.box.w / 2, top: node.y + node.box.top, bottom: node.y + node.box.bottom}));
					const label_boxes = G.placeLabelsAndMarks(bundles, node_boxes);
					G.clearThroughLines(nodes, drawn_lines, {width, height, padding}, 80, label_boxes, 8, 5);
					held.forEach(node => {
						node.fx = undefined;
						node.fy = undefined;
					});
				}
				// the room for the labels comes last: a label with no free point is a failure of the layout, a line through a node that no pass could move is only shown by the check
				held.forEach(node => {
					node.fx = node.x;
					node.fy = node.y;
				});
				const final_room = G.makeLabelRoom(nodes, bundles, {width, height, padding});
				if (!final_room) {
					// the moves for the lines left a label without a free point: the best layout that had room for every label stays (before the moves, if none had)
					nodes.forEach((node, i) => {
						node.x = best.positions[i][0];
						node.y = best.positions[i][1];
					});
					G.makeLabelRoom(nodes, bundles, {width, height, padding});
				}
				// the room for the labels can bring a crossing back: one search more, that keeps off the labels; it stays only when every label still has its free point
				if (G.linesThrough(nodes, drawn_lines, 2).length > 0) {
					const label_places = () => {
						const node_boxes = nodes.map(node => ({left: node.x - node.box.w / 2, right: node.x + node.box.w / 2, top: node.y + node.box.top, bottom: node.y + node.box.bottom}));
						return G.placeLabelsAndMarks(bundles, node_boxes);
					};
					const saved = nodes.map(node => [node.x, node.y]);
					const crossings_before = G.linesThrough(nodes, drawn_lines, 2).length;
					G.clearThroughLines(nodes, drawn_lines, {width, height, padding}, 80, label_places(), 8, 5);
					// the room for a label that the move took is made again; the result stays when every label has its point and there are fewer crossings than before
					const room_again = G.makeLabelRoom(nodes, bundles, {width, height, padding});
					if (!(room_again && label_places().every(item => item.free) && G.linesThrough(nodes, drawn_lines, 2).length < crossings_before)) {
						nodes.forEach((node, i) => {
							node.x = saved[i][0];
							node.y = saved[i][1];
						});
					}
				}
				held.forEach(node => {
					node.fx = undefined;
					node.fy = undefined;
				});
			}
		}
		// 2.90 (task 106): the nodes that were held for the repair passes are free again, whatever branch the passes took (a Junction that stayed fixed made the leaf of the next unfold overlap it)
		new_arcs.flatMap(({parent, leaves}) => [parent, ...leaves]).filter(node => node.temp_fixed !== true).forEach(node => {
			if (node.drag_moved !== true && node.fixed_by_user !== true && node.fx !== undefined && node.fx !== null) {
				node.fx = undefined;
				node.fy = undefined;
			}
		});
		// the moves for the lines can leave two boxes on each other (a node moved off a line onto a leaf of the arc): a last collision step, the leaves of the arcs and their parents stay
		{
			const keep_placed = new_arcs.flatMap(({parent, leaves}) => [parent, ...leaves]).filter(node => node.fx === undefined || node.fx === null);
			keep_placed.forEach(node => {
				node.fx = node.x;
				node.fy = node.y;
			});
			// 2.90 (task 106): when the area has no free place left for a box, the area grows (as in the first collision step), else the overlap stays
			for (let attempt = 0; attempt < 4 && !G.resolveOverlaps(nodes, {width, height, padding}); attempt++) {
				width *= 1.15;
				height *= 1.15;
			}
			keep_placed.forEach(node => {
				node.fx = undefined;
				node.fy = undefined;
			});
		}
		this.view_size = {width, height, keep: true};
		// 091 (§12.1 "Scale"): the view is the size of the window and the graph is drawn at scale 1 or more; the user pans (drag on the empty area) and zooms out (wheel). The double click
		// does not zoom (it folds a group, §12.8); a double click on the empty area does nothing, as before the zoom was there.
		this.canvas.attr('viewBox', `0 0 ${view_width} ${view_height}`).selectAll('*').remove();
		const defs = this.canvas.append('defs');
		const scene = this.canvas.append('g').attr('id', 'topology-scene');
		this.zoom ??= d3.zoom().scaleExtent([0.15, 4])
			.filter(event => event.type !== 'dblclick' && !event.target.closest?.('.topology-node') && !event.target.closest?.('.topology-link') && !event.button)
			.on('zoom', event => {
				if (event.sourceEvent) {
					this.user_moved = true;      // a pan or a zoom of the user: the view is not centred again by a redraw
				}
				this.zoom_transform = event.transform;
				d3.select('#topology-scene').attr('transform', event.transform);
			});
		this.canvas.call(this.zoom).on('dblclick.zoom', null);
		if (relayout) {
			this.user_moved = false;
		}
		if (relayout || !this.zoom_transform || !this.user_moved) {
			// the first drawing (and every redraw until the user pans or zooms: the window can change its size, for example the menu): scale 1; the graph is centred when it fits the window, else centred on the focus host, or on the node with the most drawn links
			const fits = width <= view_width * 1.02 && height <= view_height * 1.02;
			let centre = {x: width / 2, y: height / 2};
			if (!fits) {
				// the node with the most drawn links (the lines that are on the screen: a folded group is one line)
				const drawn_links = new Map();
				simulation_links.forEach(link => {
					drawn_links.set(String(link.source), (drawn_links.get(String(link.source)) ?? 0) + 1);
					drawn_links.set(String(link.target), (drawn_links.get(String(link.target)) ?? 0) + 1);
				});
				const best = focus_id !== null ? node_by_id.get(focus_id)
					: nodes.reduce((top, node) => ((drawn_links.get(String(node.id)) ?? 0) > (drawn_links.get(String(top.id)) ?? 0) ? node : top), nodes[0]);
				if (best) {
					centre = {x: best.x, y: best.y};
				}
			}
			this.zoom_transform = d3.zoomIdentity.translate(view_width / 2 - centre.x, view_height / 2 - centre.y);
		}
		scene.attr('transform', this.zoom_transform);
		this.canvas.property('__zoom', this.zoom_transform);
		// the halo of a label (§12.2): a stroke in the page background colour under the text, so a line under a label does not cross the letters; it has no meaning
		const halo_colour = getComputedStyle(document.querySelector('.topology-workspace')).backgroundColor || '#ffffff';
		defs.append('pattern')
			.attr('id', 'topology-blind-spot-hatch').attr('width', 8).attr('height', 8)
			.attr('patternUnits', 'userSpaceOnUse').attr('patternTransform', 'rotate(45)')
			.call(pattern => {
				pattern.append('rect').attr('width', 8).attr('height', 8).attr('fill', '#2b7dbc');
				pattern.append('rect').attr('width', 4).attr('height', 8).attr('fill', '#f59e0b');
			});
		// §12.3 "LAG bundle" (2.72): port-level links of one LAG between the same two nodes with the same channels are one line; a contradicted manual link keeps its own line
		simulation_links = G.bundleLinks(simulation_links, link => !this.contradictionOf(link.edge_id)?.contradicted);
		simulation_links.forEach(link => {
			link.source = node_by_id.get(link.source);
			link.target = node_by_id.get(link.target);
			(link.members ?? []).forEach(member => {
				member.source = node_by_id.get(member.source) ?? member.source;
				member.target = node_by_id.get(member.target) ?? member.target;
			});
		});
		const parallel = G.parallelIndex(simulation_links);
		simulation_links.forEach((link, i) => {
			link.parallel = parallel[i];
		});

		// Two independent visual channels on physical_link: dash pattern = provenance (dashed = manual), color + stroke-width = severity of the problems on the port items
		// (§12.3); opacity = stale or superseded. A bundle of device-level links is one line. Parallel links are separate curved lines (§12.3, 2.70).
		const link_style = {
			stroke: link => {
				if (link.type === 'monitored_by') {
					return '#6b46c1';
				}
				if (link.type === 'group_link' || link.type === 'junction_link') {
					return '#64748b';
				}
				if (link.type === 'device_link') {
					return link.discovered_via === 'manual' ? '#334155' : '#64748b';
				}
				return ['physical_link', 'lag_bundle', 'junction_member'].includes(link.type) && link.severity !== null && link.severity !== undefined ? this.cssColor(link.color) : '#64748b';
			},
			width: link => {
				const severe = ['physical_link', 'lag_bundle', 'junction_member'].includes(link.type) && link.severity !== null && link.severity !== undefined;
				return link.type === 'device_link' ? 3 : (severe ? 4 : 2);
			}
		};
		// the highlight under the line of a hovered node (§12.3 "Hover"): its own element, so the channels of the link itself do not change
		const glows = scene.append('g').selectAll('path').data(simulation_links).join('path')
			.attr('class', 'topology-link-glow').style('stroke-width', link => link_style.width(link) + 4);
		const links = scene.append('g').selectAll('path').data(simulation_links).join('path')
			.attr('class', 'topology-link')
			.style('fill', 'none')
			// the hit lines (§12.3 "Hit area of a line") take the pointer; the line itself does not
			.style('pointer-events', 'none')
			// style, not attribute: the stylesheet rule .topology-link{stroke:...} would override a presentation attribute
			.style('stroke', link_style.stroke)
			.style('stroke-width', link_style.width)
			.attr('stroke-dasharray', link => {
				if (link.type === 'monitored_by') {
					return '2 2';
				}
				// A device-level link (far port unknown) is dotted: distinct from dashed manual, faded stale and the orange dotted ghost line.
				if (link.type === 'device_link') {
					return '1 4';
				}
				return (['physical_link', 'lag_bundle', 'junction_member'].includes(link.type) && link.discovered_via === 'manual') ? '5 3' : null;
			})
			// staleness (§12.3): a third independent channel; never collapse it into the dash pattern or the colour
			.style('opacity', link => (['physical_link', 'lag_bundle', 'device_link', 'junction_member'].includes(link.type) && (link.stale || link.superseded_at)) ? 0.45 : 1);
		const link_tooltip = link => {
			if (link.type === 'lag_bundle') {
				// 2.73: "LAG <name> ×<N>: <A> ↔ <B>", then a line per member: its two ports, its status and the load of side A (§4.8); the link panel has both sides
				return {html: this.bundleHintHtml(G.bundleHintParts(link, {in: <?= json_encode(_('in')) ?>, out: <?= json_encode(_('out')) ?>, of: <?= json_encode(_('of')) ?>,
					status: {up: <?= json_encode(_('Up')) ?>, down: <?= json_encode(_('DOWN')) ?>, disabled: <?= json_encode(_('Disabled')) ?>}, speed: bps => this.formatSpeed(bps),
					mismatch: m => this.speedMismatchText(m.source, m.target)}))};
			}
			if (link.type === 'physical_link') {
				const marks = [];
				if (link.superseded_at) {
					marks.push(this.replacedNote(link));
				}
				else if (link.stale) {
					marks.push(<?= json_encode(_('Not recently reconfirmed.')) ?>);
				}
				marks.push(...this.lostPrecisionNotes(link));
				if (link.speed_mismatch) {
					marks.push(this.speedMismatchText(link.speed_mismatch.source, link.speed_mismatch.target));
				}
				if (link.severity !== null && link.severity !== undefined) {
					marks.push(`${<?= json_encode(_('Severity')) ?>}: ${link.severity_name}.`);
				}
				const source = link.discovered_via === 'manual' ? <?= json_encode(_('manual')) ?> : this.sourceText(link.discovery_source || 'lldp');
				const near_load = TopologyGraph.loadText(link.source_load ?? link.target_load, {in: <?= json_encode(_('in')) ?>, out: <?= json_encode(_('out')) ?>});
				return {html: this.linkHintHtml(G.linkHintParts({...link, source_text: source, marks}, {in: <?= json_encode(_('in')) ?>, out: <?= json_encode(_('out')) ?>, of: <?= json_encode(_('of')) ?>, no_data: <?= json_encode(_('no data')) ?>,
					status: {up: <?= json_encode(_('Up')) ?>, down: <?= json_encode(_('DOWN')) ?>, disabled: <?= json_encode(_('Disabled')) ?>}, speed: bps => this.formatSpeed(bps)}), near_load !== '—' ? near_load : '')};
			}
			if (link.type === 'device_link') {
				return this.deviceLinkTooltip(link);
			}
			return '';
		};
		// A bundle of device-level links between the same two Devices is one line labelled with the count and the known local ports on each side ("2 links: Gi0/1, Gi0/2 ↔ ?").
		const bundle_data = simulation_links.filter(link => link.type === 'device_link' && (link.count ?? 0) > 1);
		const bundle_labels = scene.append('g').selectAll('text').data(bundle_data).join('text')
			.attr('text-anchor', 'middle').style('font-size', '10px').style('fill', '#475569')
			.style('pointer-events', 'none').text(link => this.deviceBundleLabel(link));
		// the label of a LAG bundle at the middle of its line: "Trk1 ×2", "lag16 ↔ Trk1 ×2" (the name comes from the network: text())
		const lag_labels = scene.append('g').selectAll('text').data(simulation_links.filter(link => link.type === 'lag_bundle')).join('text')
			.attr('class', 'topology-lag-label').attr('text-anchor', 'middle').style('font-size', '10px').style('fill', '#334155').style('pointer-events', 'none')
			.style('paint-order', 'stroke').style('stroke', halo_colour).style('stroke-width', '3px').style('stroke-linejoin', 'round')
			.text(link => link.label);
		// Manual links contradicted by discovery (topology-1-model.md "Contradicted manual links"). Ghost link: where discovery says the cable actually goes — a dashed orange
		// line from the reporting Device to the hidden neighbor, not a stored link. One per Device pair however many observations say it. Acknowledged → none.
		const ghost_data = [];
		const ghost_seen = new Set();
		this.unacknowledgedShadows().forEach(({entry, shadow}) => {
			if (shadow.hidden_device_id === null || shadow.local_device_id === null) {
				return;
			}
			const from = node_by_id.get(resolve_display_id(String(shadow.local_device_id)));
			const to = node_by_id.get(resolve_display_id(String(shadow.remote_device_id ?? shadow.hidden_device_id)));
			const pair = [from?.id, to?.id].sort().join('-');
			if (!from || !to || from === to || ghost_seen.has(pair)) {
				return;
			}
			ghost_seen.add(pair);
			ghost_data.push({from, to, entry, shadow});
		});
		const ghosts = scene.append('g').selectAll('g').data(ghost_data).join('g')
			.attr('class', 'topology-ghost-link');
		const ghost_lines = ghosts.append('line').attr('stroke', '#c2410c').attr('stroke-width', 2)
			.attr('stroke-dasharray', '2 5').style('pointer-events', 'none');
		const ghost_labels = ghosts.append('text').attr('text-anchor', 'middle').style('font-size', '10px')
			.style('fill', '#c2410c').style('pointer-events', 'none').text(ghost => ghost.shadow.remote_port ?? '');
		// Pair lines of the filter (V-17): one dashed line per pair of candidates, labelled with the reason; not a link. A click opens the pair.
		const pair_data = [];
		if (this.only_duplicates) {
			const pair_seen = new Set();
			this.nodes.forEach(device => {
				(device.type === 'device' ? device.duplicates ?? [] : []).forEach(candidate => {
					const from = node_by_id.get(resolve_display_id(String(device.id)));
					const to = candidate.device_id === null ? null : node_by_id.get(resolve_display_id(String(candidate.device_id)));
					const key = [device.id, candidate.device_id].sort().join('-');
					if (!from || !to || from === to || pair_seen.has(key)) {
						return;
					}
					pair_seen.add(key);
					pair_data.push({from, to, a: device, candidate, key: 'pair:' + device.id + ':' + candidate.device_id});
				});
			});
		}
		const pairs = scene.append('g').selectAll('g').data(pair_data).join('g')
			.attr('class', 'topology-pair-line');
		const pair_lines = pairs.append('line').attr('stroke', '#b45309').attr('stroke-width', 2)
			.attr('stroke-dasharray', '8 4').style('pointer-events', 'none');
		const pair_labels = pairs.append('text').attr('text-anchor', 'middle').style('font-size', '10px')
			.style('fill', '#b45309').style('pointer-events', 'none').text(pair => this.pairReason(pair.candidate));
		const contradicted_links = simulation_links.filter(link => ['physical_link', 'device_link'].includes(link.type) && link.edge_id !== null
			&& link.edge_id !== undefined && this.contradictionOf(link.edge_id)?.contradicted);
		const link_badges = scene.append('g').selectAll('g').data(contradicted_links).join('g')
			.attr('class', 'topology-contradiction-badge').style('cursor', 'pointer')
			.on('click', (event, link) => {
				this.panel_from = null;
				this.selectLink(link);
			});
		link_badges.append('circle').attr('r', 9).style('fill', '#c2410c').style('stroke', '#7c2d12').style('stroke-width', 2);
		link_badges.append('text').attr('y', 4).attr('text-anchor', 'middle').style('font-size', '12px')
			.style('font-weight', 'bold').style('fill', '#ffffff').style('pointer-events', 'none').text('!');
		link_badges.on('mouseenter.hint', (event, link) => this.showHint(event, event.currentTarget, this.contradictionSummary(this.contradictionOf(link.edge_id))))
			.on('mouseleave.hint', () => this.hideHint());

		// ---- the hit lines (§12.3 "Hit area of a line", 2.72) ----
		// Every drawn line (link, LAG bundle, group line, ghost, duplicate pair line) has an invisible hit line along it, 12 px wide, in a layer under the nodes. The pointer on a hit line is
		// a hover on the line that is NEAREST to the pointer (parallel lines meet): its highlight outline and its tooltip; a click opens its panel.
		const extra_glows = scene.insert('g', ':first-child');
		const line_entries = [];
		const straight = (a, b) => `M${a.x},${a.y}L${b.x},${b.y}`;
		simulation_links.forEach((link, i) => {
			if (link.type === 'monitored_by') {
				return;
			}
			const group_node = link.type === 'group_link' ? (link.source.type === 'group' ? link.source : link.target) : null;
			const junction_node = ['junction_link', 'junction_member'].includes(link.type) ? (link.source.type === 'junction' ? link.source : link.target) : null;
			line_entries.push({
				link,
				menu: ['physical_link', 'device_link', 'lag_bundle'].includes(link.type),      // a click opens the menu of the line (task 117 section 3); a group line and a Junction line open their panel
				d: () => G.curve(link.source, link.target, link.parallel.index, link.parallel.count).d,
				glow: () => glows.nodes()[i],
				tooltip: () => group_node ? describeSubject(group_node) : (junction_node ? describeSubject(junction_node) : link_tooltip(link)),
				open: () => {
					this.panel_from = null;
					if (junction_node) {
						this.showJunction(junction_node);
					}
					else if (group_node) {
						this.showGroup(group_node);
					}
					else if (link.type === 'lag_bundle') {
						this.selectBundle(link);
					}
					else {
						this.selectLink(link);
					}
				}
			});
		});
		const glow_of = (width, d_fn) => {
			const glow = extra_glows.append('path').attr('class', 'topology-link-glow').style('stroke-width', `${width}px`).node();
			glow.d_fn = d_fn;
			return glow;
		};
		ghost_data.forEach(ghost => {
			const d = () => straight(ghost.from, ghost.to);
			const glow = glow_of(8, d);
			line_entries.push({d, ghost, menu: true, glow: () => glow, tooltip: () => this.shadowText(ghost.shadow), open: () => this.manualAction('open', ghost.entry.edge_id)});
		});
		pair_data.forEach(pair => {
			const d = () => straight(pair.from, pair.to);
			const glow = glow_of(8, d);
			line_entries.push({d, key: pair.key, glow: () => glow, tooltip: () => this.pairReason(pair.candidate), open: () => {
				this.panel_from = null;
				this.selectPair(pair.a, pair.candidate);
			}});
		});
		const hit_layer = scene.append('g').attr('class', 'topology-hit-layer');
		const hits = hit_layer.selectAll('path').data(line_entries).join('path').attr('class', 'topology-hit')
			.style('fill', 'none').style('stroke', 'transparent').style('stroke-width', '12px').style('pointer-events', 'stroke').style('cursor', 'pointer');
		hits.each((entry, i, elements) => {
			entry.hit = elements[i];
		});
		const nearest_entry = event => {
			const point = d3.pointer(event, scene.node());
			let best = null;
			let best_distance = 6.5 + 6 / (this.zoom_transform?.k ?? 1);
			line_entries.forEach(entry => {
				const length = entry.hit.getTotalLength();
				for (let at = 0; at <= length; at += 4) {
					const p = entry.hit.getPointAtLength(at);
					const distance = Math.hypot(p.x - point[0], p.y - point[1]);
					if (distance < best_distance) {
						best_distance = distance;
						best = entry;
					}
				}
			});
			return best;
		};
		hits.on('mousemove', (event, entry) => this.hoverLine(event, nearest_entry(event) ?? entry))
			.on('mouseleave', () => this.hoverLine(null, null))
			.on('click', (event, entry) => {
				const target = nearest_entry(event) ?? entry;
				if (target.menu) {
					this.guard(() => this.openMenu(event, target.hit, null, this.lineMenuItemsWithBinding(target)));
				}
				else {
					target.open();
				}
			});
		this.line_entries = line_entries;
		// ---- the warning mark of a speed mismatch (§12.3, 2.101; FR-C V-4, V-25) ----
		// A small warning sign at the middle of the line of a link with a speed mismatch, or of a LAG bundle with a member that has one (next to the label, never on it). The line keeps its
		// colour, dash, width and opacity. Visible with no hover; a click opens the panel of the link, as the line does; the hover has the tooltip of the line.
		const mark_data = simulation_links.filter(link => G.markOf(link) !== null);
		const speed_marks = scene.append('g').attr('class', 'topology-speed-mark-layer').selectAll('g').data(mark_data).join('g')
			.attr('class', 'topology-speed-mark').style('cursor', 'pointer')
			.attr('data-link', link => `${link.source.id}>${link.target.id}`);
		speed_marks.append('path').attr('d', `M0,${-G.MARK_SIZE / 2} L${G.MARK_SIZE / 2},${G.MARK_SIZE / 2 - 1} L${-G.MARK_SIZE / 2},${G.MARK_SIZE / 2 - 1} Z`)
			.style('fill', '#f59e0b').style('stroke', '#92400e').style('stroke-width', '1.4px').style('stroke-linejoin', 'round');
		speed_marks.append('path').attr('d', 'M0,-2.5 V1.5 M0,3.8 V3.9').style('fill', 'none').style('stroke', '#1f2937').style('stroke-width', '1.6px').style('stroke-linecap', 'round');
		const entry_of = link => line_entries.find(entry => entry.link === link);
		speed_marks.on('click', (event, link) => {
			event.stopPropagation();
			entry_of(link)?.open();
		}).on('mouseenter.hint', (event, link) => {
			const tooltip = link_tooltip(link);
			if (tooltip !== '') {
				this.showHint(event, event.currentTarget, tooltip);
			}
		}).on('mouseleave.hint', () => this.hideHint());

		// topology-1-model.md §12.2: one channel, one meaning. Outline = node kind (solid: Host or Proxy; dashed: Device without association). Icon = node kind. Fill = the severity
		// of a Host only: white without problems, the severity colour with them, muted when the Host is disabled. A Proxy is neutral, a Device is always grey. Maintenance, blind
		// spot, contradiction and possible duplicate are badges, never a fill.
		const fill_for = subject => {
			if (subject.type === 'host') {
				if (subject.disabled) {
					return '#cbd2d9';
				}
				return subject.severity !== null && subject.severity !== undefined ? this.cssColor(subject.color) : '#ffffff';
			}
			return subject.type === 'proxy' ? '#ffffff' : '#f3f4f6';
		};
		const stroke_for = subject => subject.type === 'device' || subject.type === 'group' || subject.type === 'junction' ? '#697386' : '#1f2937';
		const icon_stroke_for = subject => {
			if (subject.type === 'host' && !subject.disabled && subject.severity !== null && subject.severity !== undefined) {
				return this.readableTextOn(this.cssColor(subject.color));
			}
			return subject.type === 'host' && subject.disabled ? '#55606b' : (subject.type === 'device' ? '#4b5563' : '#1f2937');
		};

		const node_selection = scene.append('g').selectAll('g').data(nodes).join('g')
			.attr('class', node => `topology-node ${node.type} topology-size-${node.size}${this.only_duplicates && !is_duplicate_node(node) ? ' topology-context' : ''}`)
			.style('opacity', node => this.only_duplicates && !is_duplicate_node(node) ? 0.45 : null)
			// hover (§12.3): the node and its drawn links get a highlight outline; no other channel changes
			.on('mouseenter', (event, node) => this.highlight(node, true))
			.on('mouseleave', (event, node) => this.highlight(node, false))
			// a double click on the parent of an unfolded group, or on one of its leaves, folds the group (§12.8; the panel button "Fold <N> devices" does the same)
			.on('dblclick', (event, node) => {
				const group = (this.leaf_groups ?? []).find(candidate => this.unfolded.has(String(candidate.parent))
					&& (String(candidate.parent) === String(node.id) || candidate.devices.map(String).includes(String(node.id))));
				if (group) {
					event.preventDefault();
					this.withoutPan(() => {
						this.foldGroup(group.parent);
						// 091 (§12.8): the side panel then shows the group, not a leaf that is no longer drawn
						const group_node = this.group_nodes.get(String(group.parent));
						this.panel_from = null;
						if (group_node) {
							this.showGroup(group_node);
						}
					});
				}
			})
			// a dragged node stays where it is dropped, until the page is loaded again or the filter is applied (§12.1 "Node placement"); positions are not stored
			.call(d3.drag()
				.on('start', (event, node) => {
					node.drag_moved = false;
				})
				.on('drag', (event, node) => {
					node.fx = event.x;
					node.fy = event.y;
					node.x = event.x;
					node.y = event.y;
					node.drag_moved = true;
					draw_positions();
				})
				.on('end', (event, node) => {
					if (node.drag_moved) {
						// the others make room: the dragged node is fixed for the collision step
						if (!G.resolveOverlaps(nodes, {width, height, padding})) {
							// the area is full; the nodes stay as they are
						}
						// 2.76: the labels of the bundles get their room again (the dragged node and the other fixed nodes stay)
						const bundle_lines = simulation_links.filter(link => link.type === 'lag_bundle' || G.markOf(link))
							.map(link => ({a: link.source, b: link.target, width: link.type === 'lag_bundle' ? G.labelWidth(link.label, label_measure) : 0, index: link.parallel.index, count: link.parallel.count,
								mark: G.markOf(link) !== null, mark_only: link.type !== 'lag_bundle'}));
						const all_lines = simulation_links.map(link => ({a: link.source, b: link.target, index: link.parallel.index, count: link.parallel.count}));
						// 2.84 (§12.1 "No line through a node"): after a drag the nodes that a line crosses are moved off it (the dragged node and the other fixed nodes stay)
						for (let pass = 0; pass < 8; pass++) {
							G.clearThroughLines(nodes, all_lines, {width, height, padding});
							const room = G.makeLabelRoom(nodes, bundle_lines, {width, height, padding});
							if (room && G.linesThrough(nodes, all_lines, 2).length === 0) {
								break;
							}
						}
						draw_positions();
					}
				}));
		node_selection.each((node, i, elements) => {
			node.element = elements[i];
		});
		// the shape: no text inside it (§12.2 "Label placement")
		node_selection.append('rect').attr('class', 'topology-shape')
			.attr('x', node => -node.shape_w / 2).attr('y', node => -node.shape_h / 2).attr('width', node => node.shape_w).attr('height', node => node.shape_h)
			.attr('rx', node => node.type === 'junction' ? node.shape_w / 2 : (node.size === 'small' ? 3 : 5))
			.style('fill', fill_for)
			.style('stroke', stroke_for)
			.style('stroke-width', node => (node.type === 'proxy' && node.unreachable) ? 3 : 2)
			.style('stroke-dasharray', node => node.type === 'device' || node.type === 'group' ? (node.size === 'small' ? '4 3' : '6 4') : null)
			.style('cursor', 'pointer')
			.on('click', (event, node) => {
				if (node.drag_moved) {
					node.drag_moved = false;
					return;
				}
				this.panel_from = null;
				if (node.type === 'junction') {
					this.showJunction(node);
					return;
				}
				if (node.type === 'group') {
					this.withoutPan(() => {
						this.unfoldGroup(node.parent);
						this.showGroup(node);
					});
					return;
				}
				this.selectNode(node);
			});
		// the icon (§12.2 "Icon", 2.71): the class of the Device, for a Host and a Device without association alike (a Host takes the class of its Device); a Proxy has the Proxy icon;
		// no class: the generic icon. Strokes only, scaled to the node (large 1.3, small 0.8); a group node has none. The outline and the fill keep the node kind.
		const icon_groups = node_selection.filter(node => TopologyGraph.iconOf(node) !== null).append('g')
			.attr('class', node => `topology-icon topology-icon-${TopologyGraph.iconOf(node)}`)
			.attr('transform', node => `scale(${node.size === 'small' ? 0.8 : 1.3})`).style('pointer-events', 'none')
			.style('fill', 'none').style('stroke-width', node => 1.5 / (node.size === 'small' ? 0.8 : 1.3))
			.style('stroke', node => node.type === 'proxy' ? '#1f2937' : icon_stroke_for(node)).style('color', node => node.type === 'proxy' ? '#1f2937' : icon_stroke_for(node));
		icon_groups.each((node, i, elements) => {
			const group = d3.select(elements[i]);
			TopologyGraph.ICON_DEFS[TopologyGraph.iconOf(node)].forEach(([tag, attributes]) => {
				const shape = group.append(tag);
				Object.entries(attributes).forEach(([name, value]) => shape.attr(name, value));
			});
		});
		// the label: under the shape, one line, at most 160 px, cut in the middle (the full text is in the tooltip); written with text(), so a value from the network is never markup (§13)
		// task 117 section 3: the label of a Host, a Proxy and a Device is a link: a click opens the menu of the node (the icon selects it and opens the panel); a group and a Junction have no menu
		const has_menu = node => ['host', 'proxy', 'device'].includes(node.type);
		node_selection.append('text').attr('class', node => `topology-label${has_menu(node) ? ' topology-label-link' : ''}`).attr('text-anchor', 'middle')
			.attr('y', node => node.shape_h / 2 + 3 + node.font)
			.style('font-size', node => `${node.font}px`).style('fill', '#1f2937').style('pointer-events', node => has_menu(node) ? 'all' : 'none')
			.on('click', (event, node) => {
				if (!has_menu(node)) {
					return;
				}
				event.stopPropagation();
				this.guard(() => this.openMenu(event, event.currentTarget, node.type === 'host' ? node.hostid : null, this.nodeMenuItems(node)));
			})
			.style('paint-order', 'stroke').style('stroke', halo_colour).style('stroke-width', '3px').style('stroke-linejoin', 'round')
			.text(node => node.label_text);

		// Badges (§12.2): a blind spot (top right), maintenance (top left), a hidden neighbor of a contradicted manual link (bottom right), a possible duplicate (bottom left);
		// each badge is its own group with a tooltip that holds its text.
		const badge = (selection, fx, fy, fill, stroke, letter, text_of) => {
			const group = selection.append('g').attr('class', 'topology-badge')
				.attr('transform', node => `translate(${fx * node.shape_w / 2},${fy * node.shape_h / 2})`);
			group.append('circle').attr('r', node => node.size === 'small' ? 6 : 8).style('fill', fill).style('stroke', stroke).style('stroke-width', 1.6);
			group.append('text').attr('y', node => node.size === 'small' ? 3 : 4).attr('text-anchor', 'middle')
				.style('font-size', node => node.size === 'small' ? '8px' : '11px').style('font-weight', 'bold').style('fill', '#ffffff').style('pointer-events', 'none').text(letter);
			group.on('mouseenter.hint', (event, node) => { event.stopPropagation(); this.showHint(event, event.currentTarget, text_of(node)); })
				.on('mouseleave.hint', () => this.hideHint());
			return group;
		};
		badge(node_selection.filter(node => node.type === 'host' && node.blind_spot), 1, -1, '#f59e0b', '#7c2d12', '?',
			() => <?= json_encode(_('Monitoring blind spot: the proxy for this host is unreachable, this does not mean the host itself is down.')) ?>);
		badge(node_selection.filter(node => node.type === 'host' && node.maintenance && !node.disabled), -1, -1, '#475569', '#1e293b', 'M',
			() => <?= json_encode(_('In maintenance — still monitored, notifications suppressed.')) ?>);
		// Badge on every unacknowledged hidden neighbor, whether or not it has other links
		const hidden_tooltips = new Map();
		this.unacknowledgedShadows().forEach(({entry, shadow}) => {
			if (shadow.hidden_device_id === null) {
				return;
			}
			const display_id = resolve_display_id(String(shadow.hidden_device_id));
			const far = this.shadowFar(entry, shadow);
			const line = `${<?= json_encode(_('Link hidden by manual link')) ?>} ${shadow.local_port ?? '?'} ↔ ${far ?? '?'} ` +
				`${<?= json_encode(_('on')) ?>} ${shadow.reporter.name}`;
			hidden_tooltips.set(display_id, [...(hidden_tooltips.get(display_id) ?? []), line]);
		});
		badge(node_selection.filter(node => hidden_tooltips.has(String(node.id))), 1, 1, '#c2410c', '#7c2d12', '!', node => hidden_tooltips.get(String(node.id)).join('\n'))
			.attr('class', 'topology-badge topology-hidden-badge');
		// Possible duplicates: the reasons are the tooltip
		badge(node_selection.filter(node => node.type === 'device' && (node.duplicates ?? []).length > 0), -1, 1, '#6d28d9', '#4c1d95', '=',
			node => <?= json_encode(_('Possible duplicate')) ?> + ': ' + node.duplicates.map(candidate => `${candidate.reason} (${candidate.name ?? candidate.value})`).join(', '))
			.attr('class', 'topology-badge topology-duplicate-badge');

		const describeSubject = subject => {
			const full = subject.label ?? subject.name;
			if (subject.type === 'junction') {
				return this.junctionTitle(subject);
			}
			if (subject.type === 'group') {
				// the tooltip counts the Devices of the group by class (§12.8): "9 access points, 6 stations"
				const counts = TopologyGraph.classCounts(subject.devices.map(id => this.nodes.get(String(id))).filter(Boolean));
				return `${full}: ${TopologyGraph.classText(counts, this.classNames())}`;
			}
			if (subject.type === 'proxy' && subject.unreachable) {
				return `${full} — ${<?= json_encode(_('Proxy unreachable')) ?>}`;
			}
			if (subject.type === 'host' && subject.disabled) {
				return `${full} — ${<?= json_encode(_('Host is disabled — not polled, no severity to show.')) ?>}`;
			}
			const notes = [];
			if (subject.type === 'host' && subject.blind_spot) {
				notes.push(<?= json_encode(_('Monitoring blind spot: the proxy for this host is unreachable, this does not mean the host itself is down.')) ?>);
			}
			if (subject.type === 'host' && subject.maintenance) {
				notes.push(<?= json_encode(_('In maintenance — still monitored, notifications suppressed.')) ?>);
			}
			if ((subject.type === 'host' || subject.type === 'proxy') && subject.severity_name) {
				notes.push(`${<?= json_encode(_('Severity: ')) ?>}${subject.severity_name}`);
			}
			return notes.length ? `${full} — ${notes.join(' ')}` : full;
		};
		// task 117 section 7 (updated): a node (icon and label) has no hint box, the panel and the menu show the same; a label that is cut shows its full name, and only the name. A group node
		// keeps its hint box (its name and the count by class).
		node_selection.on('mouseenter.hint', (event, node) => {
			if (node.type === 'group') {
				this.showHint(event, event.currentTarget, describeSubject(node).replace(': ', '\n'));
			}
			else if (has_menu(node) && node.label_text !== (node.label ?? node.name)) {
				this.showHint(event, event.currentTarget, String(node.label ?? node.name));
			}
		}).on('mouseleave.hint', () => this.hideHint());

		const draw_positions = () => {
			links.attr('d', link => G.curve(link.source, link.target, link.parallel.index, link.parallel.count).d);
			glows.attr('d', link => G.curve(link.source, link.target, link.parallel.index, link.parallel.count).d);
			node_selection.attr('transform', node => `translate(${node.x},${node.y})`);
			ghost_lines.attr('x1', ghost => ghost.from.x).attr('y1', ghost => ghost.from.y)
				.attr('x2', ghost => ghost.to.x).attr('y2', ghost => ghost.to.y);
			ghost_labels.attr('x', ghost => (ghost.from.x + ghost.to.x) / 2).attr('y', ghost => (ghost.from.y + ghost.to.y) / 2 - 5);
			pair_lines.attr('x1', pair => pair.from.x).attr('y1', pair => pair.from.y)
				.attr('x2', pair => pair.to.x).attr('y2', pair => pair.to.y);
			pair_labels.attr('x', pair => (pair.from.x + pair.to.x) / 2).attr('y', pair => (pair.from.y + pair.to.y) / 2 - 5);
			link_badges.attr('transform', link => {
				const mid = G.curve(link.source, link.target, link.parallel.index, link.parallel.count).mid;
				return `translate(${mid.x},${mid.y})`;
			});
			// §12.3 (2.73): the label of a bundle is at the point between 30 % and 70 % of its line where its box touches no node box and no label placed before it; the middle when it is free
			const node_boxes = nodes.map(node => ({left: node.x - node.box.w / 2, right: node.x + node.box.w / 2, top: node.y + node.box.top, bottom: node.y + node.box.bottom}));
			const placed = [];
			const view_places = [];
			lag_labels.each(function (link) {
				const line = G.curve(link.source, link.target, link.parallel.index, link.parallel.count);
				const place = G.placeLabel(line.at, G.labelWidth(link.label, label_measure), [...node_boxes, ...placed]);
				placed.push(place.box);
				d3.select(this).attr('x', place.x).attr('y', place.y);
				view_places.push({label: link.label, source: link.source.name, target: link.target.name, u: Math.round(place.u * 100), free: place.free});
			});
			this.lag_label_places = view_places;
			// 2.101: the warning marks after the labels: each one at the middle of its line, or at the free point nearest to it (the label of a bundle is an obstacle, a mark is never on it)
			const mark_places = [];
			speed_marks.each(function (link) {
				const place = G.placeMark(G.curve(link.source, link.target, link.parallel.index, link.parallel.count).at, [...node_boxes, ...placed]);
				placed.push(place.box);
				d3.select(this).attr('transform', `translate(${place.x},${place.y})`);
				mark_places.push({source: String(link.source.id), target: String(link.target.id), type: link.type, u: Math.round(place.u * 100), free: place.free});
			});
			this.speed_mark_places = mark_places;
			hits.attr('d', entry => entry.d());
			extra_glows.selectAll('path').attr('d', function () {
				return this.d_fn();
			});
			bundle_labels.attr('x', link => G.curve(link.source, link.target, link.parallel.index, link.parallel.count).mid.x)
				.attr('y', link => G.curve(link.source, link.target, link.parallel.index, link.parallel.count).mid.y - 6);
		};
		draw_positions();
		this.drawn_nodes = nodes;
		this.drawn_links = simulation_links;
		this.link_selection = links;
		this.glow_selection = glows;
		// the selection (§12.1, task 117 section 4): the frame of the icon of the node (its shape) gets the accent colour and a thicker line; no box around the icon and the label. A click on the empty area removes it
		this.canvas.on('click.selection', event => {
			if (this.selection && !event.target.closest?.('.topology-node, .topology-hit, .topology-contradiction-badge')) {
				this.selection = null;
				this.applySelection(false);
			}
		});
		this.applySelection(false);
	}

	// Is the selected object drawn? (a node or a line of the drawn graph)
	selectionDrawn(selection) {
		return this.selectionTarget(selection) !== null;
	}

	selectionTarget(selection) {
		if (!selection) {
			return null;
		}
		if (selection.kind === 'node' || selection.kind === 'group' || selection.kind === 'junction') {
			let node = (this.drawn_nodes ?? []).find(candidate => String(candidate.id) === selection.id);
			if (!node && selection.kind === 'group') {
				node = (this.drawn_nodes ?? []).find(candidate => String(candidate.id) === selection.parent);
			}
			return node ? {node} : null;
		}
		const entries = (this.line_entries ?? []).filter(entry => selection.kind === 'pair' ? entry.key === selection.key
			: entry.link && this.lineEdgeIds(entry.link).some(id => selection.edge_ids.includes(id)));
		return entries.length ? {entries} : null;
	}

	// Marks the object of the open panel (§12.1 "Selection", task 117 section 4): a node by the frame of its icon in the accent colour and a thicker line (the class topology-selected on the node; no
	// box around the icon and the label), a line by a band under it (wider than the hover band). `pan`: the view moves to the object when it is not in the visible part of the graph (the zoom stays).
	applySelection(pan) {
		(this.line_entries ?? []).forEach(entry => {
			const glow = entry.glow();
			if (glow?.classList.contains('topology-link-selected')) {
				glow.classList.remove('topology-link-selected');
				glow.style.strokeWidth = glow.__width + 'px';
			}
		});
		d3.selectAll('#topology-canvas g.topology-node.topology-selected').classed('topology-selected', false);
		this.selection_node = null;
		const target = this.selectionTarget(this.selection);
		if (!target) {
			return;
		}
		let point;
		if (target.node) {
			this.selection_node = target.node;
			if (target.node.element) {
				d3.select(target.node.element).classed('topology-selected', true);
			}
			point = [target.node.x, target.node.y];
		}
		else {
			target.entries.forEach(entry => {
				const glow = entry.glow();
				glow.__width = glow.__width ?? parseFloat(glow.style.strokeWidth);
				glow.classList.add('topology-link-selected');
				glow.style.strokeWidth = (glow.__width + 2) + 'px';
			});
			const hit = target.entries[0].hit;
			const middle = hit.getPointAtLength(hit.getTotalLength() / 2);
			point = [middle.x, middle.y];
		}
		if (pan && this.zoom_transform) {
			const bounds = this.canvas.node().getBoundingClientRect();
			const k = this.zoom_transform.k;
			const x = point[0] * k + this.zoom_transform.x, y = point[1] * k + this.zoom_transform.y;
			if (x < 20 || y < 20 || x > bounds.width - 20 || y > bounds.height - 20) {
				this.user_moved = true;
				this.canvas.call(this.zoom.transform, d3.zoomIdentity.translate(bounds.width / 2 - k * point[0], bounds.height / 2 - k * point[1]).scale(k));
			}
		}
	}

	// A line of a LAG bundle in the tooltip and the panel: its two ports, its status and its load (§4.8). The names come from the network; the callers write them with text().
	memberLine(member) {
		const status = {up: <?= json_encode(_('Up')) ?>, down: <?= json_encode(_('DOWN')) ?>, disabled: <?= json_encode(_('Disabled')) ?>};
		const sides = [member.source_status ? `${status[member.source_status] ?? member.source_status}` : null, member.target_status ? `${status[member.target_status] ?? member.target_status}` : null].filter(Boolean);
		const loads = [[member.source, member.source_load], [member.target, member.target_load]].map(([node, load]) => (this.loadText(load) === '' ? '' : `${node?.name ?? ''} ${this.loadText(load)}`)).filter(Boolean);
		return `${member.source?.name ?? ''} ${member.source_port ?? '?'} ↔ ${member.target?.name ?? ''} ${member.target_port ?? '?'}` + (sides.length ? ` — ${sides.join(' / ')}` : '') + (loads.length ? ` — ${loads.join('; ')}` : '');
	}

	// The panel of a LAG bundle: the members, each with its ports, status and load; a click on a member opens its link panel.
	selectBundle(bundle) {
		this.select_token = (this.select_token ?? 0) + 1;
		this.setDetailsTitle(<?= json_encode(_('Link details')) ?>);
		this.details.innerHTML = `<h2>${this.escape(`${bundle.source.name} ↔ ${bundle.target.name}`)}</h2><p class="topology-device-class">LAG ${this.escape(bundle.label)}</p>` +
			`<section class="topology-group"><table><thead><tr><th>${this.escape(<?= json_encode(_('Members')) ?>)}</th></tr></thead><tbody>` +
			bundle.members.map((member, i) => `<tr class="topology-group-row" data-member="${i}"><td title="${this.escape(this.memberLine(member))}">${this.escape(this.memberLine(member))}</td></tr>`).join('') + '</tbody></table></section>';
		this.details.querySelectorAll('[data-member]').forEach(row => row.addEventListener('click', () => this.openFromPanel(() => this.selectLink(bundle.members[Number(row.dataset.member)]))));
		const edge_ids = bundle.members.map(member => String(member.edge_id));
		this.registerPanel('bundle:' + edge_ids.join(','), `${bundle.source.name} ↔ ${bundle.target.name}`, () => {
			const drawn = (this.drawn_links ?? []).find(link => link.type === 'lag_bundle' && (link.members ?? []).some(member => edge_ids.includes(String(member.edge_id))));
			return this.selectBundle(drawn ?? bundle);
		}, {kind: 'line', edge_ids});
	}

	// The tooltip of a line (§12.3 "Hit area of a line"): the line nearest to the pointer; plain text.
	hoverLine(event, entry) {
		this.markRows(entry);
		if (this.hover_entry && this.hover_entry !== entry) {
			d3.select(this.hover_entry.glow()).classed('topology-link-hover', false);
		}
		const changed = this.hover_entry !== entry;
		this.hover_entry = entry;
		if (!entry) {
			this.hideHint();
			return;
		}
		d3.select(entry.glow()).classed('topology-link-hover', true);
		if (changed) {
			this.showHint(event, entry.hit, entry.tooltip());
		}
	}

	// ---- The hint box (task 117 section 7) ----
	// Every tooltip of the page is the standard hint box of the frontend (hintBox of main.js: the one of the hover hints of Monitoring → Problems and of the maps), through its own code. The text
	// of a tooltip is lines; each line is a row of the table of the hint box, and the parts of a line (" · ") are its columns, as the member lines of a bundle.
	hintHtml(text) {
		const rows = String(text ?? '').split('\n').filter(line => line !== '').map(line => `<tr>${line.split(' · ').map(cell => `<td>${this.escape(cell)}</td>`).join('')}</tr>`);
		return `<table class="list-table"><tbody>${rows.join('')}</tbody></table>`;
	}

	// A link: three kinds of rows, not columns (§12.3, task 117 section 7): the ports; in grey, in the order of the Links row (§12.5), the status of both ends · the source · the load; the load of each
	// end; then the notes (stale, superseded, lost precision), which wrap. The box keeps the standard maximum width of the hint box.
	linkHintHtml(parts, load) {
		const esc = value => this.escape(value);
		const meta = [parts.statuses, parts.source, load].filter(Boolean).join(' · ');
		return `<div>${esc(parts.ports)}</div>${meta !== '' ? `<div class="grey">${esc(meta)}</div>` : ''}` +
			parts.loads.map(line => `<div class="grey">${esc(line)}</div>`).join('') + parts.marks.map(line => `<div>${esc(line)}</div>`).join('');
	}

	// A LAG bundle: the title, a table for the member rows only (ports, status, load); the notes of a bundle, if any, come below the table.
	// the mark of V-25 (model 2.100 §12.3): the speeds of the two ends with the unit of §4.8
	speedMismatchText(a, b) {
		return <?= json_encode(_('speed mismatch: %1$s / %2$s')) ?>.replace('%1$s', this.formatSpeed(a)).replace('%2$s', this.formatSpeed(b));
	}

	bundleHintHtml(parts) {
		const esc = value => this.escape(value);
		return `<div>${esc(parts.title)}</div><table class="list-table"><tbody>${parts.members.map(member => `<tr><td>${esc(member.ports)}</td><td>${esc(member.state ?? '')}</td><td>${esc(member.load ?? '')}</td>${parts.members.some(m => m.mismatch) ? `<td>${esc(member.mismatch ?? '')}</td>` : ''}</tr>`).join('')}</tbody></table>`;
	}

	showHint(event, target, content) {
		this.hideHint();
		this.hint_target = target;
		hintBox.showHint(event, target, typeof content === 'object' && content !== null ? content.html : this.hintHtml(content), '', false);
	}

	hideHint() {
		if (this.hint_target) {
			hintBox.hideHint(this.hint_target, false);
			this.hint_target = null;
		}
	}

	// The tooltips of the panel (cut text, the status dot, ...): the texts that were `title` attributes are hints of the same box, set by the standard attributes of the frontend (data-hintbox).
	hintify(container) {
		container.querySelectorAll('[title]').forEach(element => {
			const text = element.getAttribute('title');
			element.removeAttribute('title');
			if (text !== '') {
				element.dataset.hintbox = '1';
				element.dataset.hintboxHtml = this.hintHtml(text);
			}
		});
	}

	// ---- Find a device (model 2.100 §12.1, FR-C V-24) ----
	// The search field at the top left of the graph area: after 300 ms without typing and with 2 characters or more, one request `topology.devices.find`; the answer is a list of at most 20
	// rows. Choosing a row draws the node (also when "Show devices without links" is off or it is outside the host groups of the filter), selects it, pans to it and opens its panel. The filter does
	// not change; the node stays in the view until the filter is applied. Every text of a row comes from the network or from Zabbix and is escaped.
	initFind() {
		const input = document.getElementById('topology-find-input');
		const list = document.getElementById('topology-find-results');
		if (!input || !list) {
			return;
		}
		this.find_seq = 0;
		this.find_answers = 0;       // the number of lists drawn (for the checks: the state "the answer of the search is on the page")
		this.find_timer = null;
		this.find_rows = [];
		this.find_active = -1;
		input.addEventListener('input', () => {
			clearTimeout(this.find_timer);
			const text = input.value.trim();
			if ([...text].length < 2) {
				this.find_seq++;
				this.closeFind();
				return;
			}
			this.find_timer = setTimeout(() => this.runFind(text), 300);
		});
		input.addEventListener('keydown', event => {
			if (event.key === 'Escape') {
				this.closeFind();
				input.blur();
			}
			else if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
				if (this.find_rows.length) {
					event.preventDefault();
					this.find_active = (this.find_active + (event.key === 'ArrowDown' ? 1 : -1) + this.find_rows.length) % this.find_rows.length;
					this.markFindActive();
				}
			}
			else if (event.key === 'Enter') {
				event.preventDefault();
				const row = this.find_rows[Math.max(0, this.find_active)];
				if (row) {
					this.guard(() => this.chooseFound(row));
				}
			}
		});
		list.addEventListener('mousedown', event => {
			// (mousedown, not click: the input loses its focus before a click and the list would be closed)
			const element = event.target.closest('[data-find-index]');
			if (element) {
				event.preventDefault();
				const row = this.find_rows[Number(element.dataset.findIndex)];
				if (row) {
					this.guard(() => this.chooseFound(row));
				}
			}
		});
		input.addEventListener('blur', () => setTimeout(() => this.closeFind(), 150));
	}

	async runFind(text) {
		const seq = ++this.find_seq;
		this.busy++;
		try {
			let answer = null;
			try {
				answer = await this.request(`topology.devices.find&q=${encodeURIComponent(text)}`);
			}
			catch (exception) {
				answer = null;
			}
			if (seq === this.find_seq) {       // (a newer input is on its way: this answer is dropped)
				this.renderFind(answer?.results ?? [], Boolean(answer?.more), answer === null);
			}
		}
		finally {
			this.busy--;
		}
	}

	renderFind(results, more, failed) {
		const list = document.getElementById('topology-find-results');
		this.find_rows = results;
		this.find_active = results.length ? 0 : -1;
		const kinds = {proxy: 'proxy'};
		const rows = results.map((row, index) => {
			const name = kinds[row.type] ?? (TopologyGraph.CLASS_ORDER.includes(row.device_class) ? row.device_class : 'generic');
			const icon = `<svg width="22" height="22" viewBox="-11 -11 22 22" aria-hidden="true">${TopologyGraph.iconMarkup(name, '#1f2937', 0.7)}</svg>`;
			return `<div class="topology-find-row" data-find-index="${index}" role="option">${icon}<span class="topology-find-name">${this.escape(row.name)}</span>`+
				`<span class="topology-find-matched grey">${this.escape((row.matched ?? []).join(', '))}</span></div>`;
		});
		let note = '';
		if (failed) {
			note = <?= json_encode(_('The search failed.')) ?>;
		}
		else if (!results.length) {
			note = <?= json_encode(_('No devices found.')) ?>;
		}
		else if (more) {
			note = <?= json_encode(_('There are more results: refine the search.')) ?>;
		}
		this.find_answers++;
		list.innerHTML = rows.join('') + (note !== '' ? `<div class="topology-find-note grey">${this.escape(note)}</div>` : '');
		list.hidden = false;
		this.markFindActive();
	}

	markFindActive() {
		document.querySelectorAll('#topology-find-results .topology-find-row').forEach((element, index) => {
			element.classList.toggle('topology-find-active', index === this.find_active);
		});
	}

	closeFind() {
		const list = document.getElementById('topology-find-results');
		if (list) {
			list.hidden = true;
			list.innerHTML = '';
		}
		this.find_rows = [];
		this.find_active = -1;
	}

	// the nodes chosen in the search stay until the filter is applied (like a manual Device that was just created)
	clearFound() {
		this.include_ids.clear();
		this.include_hostids.clear();
		this.pinned.clear();
	}

	async chooseFound(row) {
		clearTimeout(this.find_timer);
		this.find_seq++;
		this.closeFind();
		const node_id = String(row.node);
		(row.include ?? []).forEach(id => this.include_ids.add(String(id)));
		(row.include_hostids ?? []).forEach(id => this.include_hostids.add(String(id)));
		this.pinned.add(node_id);
		// a node inside a folded leaf group is shown by unfolding the group of its parent (a UI state, nothing is stored)
		(this.leaf_groups ?? []).forEach(group => {
			if ((group.devices ?? []).map(String).includes(node_id)) {
				this.unfolded.add(String(group.parent));
			}
		});
		this.user_moved = true;       // the view does not jump to the centre again; the node is brought to the middle below
		await this.loadDevices();
		// the answer of the load may group the node again (a leaf of a group the load has just made known)
		(this.leaf_groups ?? []).forEach(group => {
			if ((group.devices ?? []).map(String).includes(node_id) && !this.unfolded.has(String(group.parent))) {
				this.unfolded.add(String(group.parent));
				this.render();
			}
		});
		const node = this.nodes.get(node_id);
		if (!node) {
			return;
		}
		await this.selectNode(node);
		this.panToNode(String(node.id));
	}

	// The node is brought to the middle of the graph area; the zoom stays.
	panToNode(node_id) {
		const drawn = (this.drawn_nodes ?? []).find(candidate => String(candidate.id) === String(node_id));
		if (!drawn) {
			return;
		}
		const bounds = this.canvas.node().getBoundingClientRect();
		const k = d3.zoomTransform(this.canvas.node()).k;
		this.user_moved = true;
		this.canvas.call(this.zoom.transform, d3.zoomIdentity.translate(bounds.width / 2 - k * drawn.x, bounds.height / 2 - k * drawn.y).scale(k));
	}

	// "Fit" (§12.1 "Fit", 2.72): the zoom is set so that the whole drawn graph is in the window, and the graph is centred.
	// zoom in / zoom out of the buttons: around the middle of the graph area, inside the limits of the zoom
	zoomBy(factor) {
		this.user_moved = true;
		this.canvas.call(this.zoom.scaleBy, factor);
	}

	fit() {
		const nodes = this.drawn_nodes ?? [];
		if (!nodes.length) {
			return;
		}
		const left = Math.min(...nodes.map(node => node.x - node.box.w / 2));
		const right = Math.max(...nodes.map(node => node.x + node.box.w / 2));
		const top = Math.min(...nodes.map(node => node.y + node.box.top));
		const bottom = Math.max(...nodes.map(node => node.y + node.box.bottom));
		const bounds = this.canvas.node().getBoundingClientRect();
		const pad = 24;
		const k = Math.max(0.15, Math.min(2, (bounds.width - 2 * pad) / (right - left), (bounds.height - 2 * pad) / (bottom - top)));
		const transform = d3.zoomIdentity.translate(bounds.width / 2 - k * (left + right) / 2, bounds.height / 2 - k * (top + bottom) / 2).scale(k);
		this.user_moved = true;
		this.canvas.call(this.zoom.transform, transform);
	}

	// Hover (§12.3): the node and its drawn links get a highlight outline, nothing else changes (the opacity stays the channel of stale links).
	highlight(node, on) {
		d3.select(node.element).classed('topology-hover', on);
		this.glow_selection?.classed('topology-link-hover', link => on && (link.source === node || link.target === node));
	}
};
</script>
