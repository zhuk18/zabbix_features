<?php declare(strict_types = 0); ?>
<script>
const view = new class {
	async request(action, options = {}) {
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
		try {
			await action();
		}
		catch (error) {
			alert(error.message);
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
		document.getElementById('topology-details-title').textContent = text;
	}

	async init() {
		this.canvas = d3.select('#topology-canvas');
		this.details = document.getElementById('topology-details');
		this.nodes = new Map();
		this.links = [];
		this.link_pick = null;
		this.unfolded = new Set();
		this.leaf_groups = [];
		this.relayout = true;
		this.show_unlinked = false;
		// The filter "possible duplicate" (topology-1-model.md §12.1, 2.47, 2.49; FR-C V-16, V-17): the server answers with the Devices that have a candidate (linked
		// or not), their partners and one hop of context; render() dims the context and draws the pair lines.
		this.only_duplicates = false;
		// the run summary of the last run view links here with "Possible duplicates only" set (model §12.7)
		if (new URLSearchParams(window.location.search).get('only_duplicates') === '1') {
			this.only_duplicates = true;
			document.getElementById('topology-filter-duplicates').checked = true;
		}

		// Device details panel: collapsible so a wide canvas/graph gets the room back when the
		// panel isn't needed — purely a CSS class toggle (topology-panel-collapsed on the grid
		// container shrinks the panel's column), nothing about the loaded data changes.
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
			panel_toggle.textContent = collapsed ? '»' : '«';
			panel_toggle.title = collapsed ? <?= json_encode(_('Expand')) ?> : <?= json_encode(_('Collapse')) ?>;
			// The CSS transition resizes the canvas column; re-render once it's settled so the
			// graph actually uses the space instead of sitting at its old (now stale) viewBox
			// until some unrelated interaction happens to trigger the next render().
			setTimeout(() => this.render(), 160);
		});
		this.buildLegend(<?= json_encode((bool) ($data['legend_collapsed'] ?? 0)) ?>);
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
		this.run_info = {note: '', before: '', time: '', after: ''};
		document.getElementById('topology-ingest-run').addEventListener('click', () => this.guard(async () => {
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
			await this.loadDevices({reset_layout: true});
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
			await this.loadDevices({reset_layout: true});
		}));
		await this.loadDevices();
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
		const time = status.finished_at ? new Date(status.finished_at * 1000).toLocaleString() : '';
		this.setRunInfo(TopologyGraph.runInfoParts(status.reporters_new_data ?? 0, time, {
			one: <?= json_encode(_('%1$s reporter has new data since the last run')) ?>,
			many: <?= json_encode(_('%1$s reporters have new data since the last run')) ?>,
			last: <?= json_encode(_('Last run: %1$s')) ?>
		}));
	}

	// One line: the state of the run (§12.7: in progress, requested, does not run), then the note about new data and the time of the last run.
	setRunState(text) {
		this.run_state = text;
		this.updateRunLine();
	}

	setRunInfo(text) {
		this.run_info = text;
		this.updateRunLine();
	}

	updateRunLine() {
		if (!this.run_line_element) {
			return;
		}
		// one line (§12.1 "Page header"): the state, the note about new data, "Last run: <time>" where the time is the link to the last run view
		const info = this.run_info ?? {note: '', before: '', time: '', after: ''};
		const line = this.run_line_element;
		line.textContent = [this.run_state, info.note].filter(text => text !== '').join(' — ') + (info.time !== '' && (this.run_state !== '' || info.note !== '') ? ' — ' : '') + info.before;
		if (info.time !== '') {
			const link = document.createElement('a');
			link.href = 'zabbix.php?action=topology.lastrun';
			link.id = 'topology-run-time';
			link.textContent = info.time;
			line.appendChild(link);
			line.appendChild(document.createTextNode(info.after));
		}
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
				this.ingest_polling = true;
				this.requested_here = true;
				this.setRunState(<?= json_encode(_('Reconciliation in progress')) ?>);
				setTimeout(poll, 2000);
				return;
			}

			if (status.requested) {
				this.ingest_polling = true;
				this.setRunState(status.executor_missing
					? <?= json_encode(_('The reconciliation does not run. Check the scheduled CLI job.')) ?>
					: <?= json_encode(_('Run requested')) ?>);
				setTimeout(poll, status.executor_missing ? 15000 : 3000);
				return;
			}

			const was_polling = this.ingest_polling;
			this.ingest_polling = false;
			this.setRunState('');

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
		return query;
	}

	async loadDevices({reselect_edge = null, reselect_device = null, reset_layout = false} = {}) {
		const query = this.filterQuery();
		const {devices = [], relations = [], contradictions = [], leaf_groups = []} = await this.request(`topology.devices.get${query}`);
		this.contradictions = contradictions;
		this.leaf_groups = leaf_groups;
		// the focus host of the radial placement (§12.1): the Host chosen in the filter when this view was loaded
		this.applied_hostid = jQuery('#hostid').multiSelect('getData')[0]?.id ?? null;
		// positions: a dragged node stays until the page is loaded again or the filter is applied (§12.1); other reloads (after an action) keep every position
		const old_nodes = reset_layout ? new Map() : this.nodes;
		if (reset_layout || !this.view_size) {
			this.relayout = true;
			this.unfolded.clear();
			this.group_nodes?.clear();
			this.view_size = null;
		}
		devices.forEach(node => {
			if (node.type === 'host' || node.type === 'proxy') {
				node.linked = true;
			}
		});
		this.nodes = new Map(devices.map(node => {
			const before = old_nodes.get(String(node.id));
			if (before) {
				Object.assign(node, {x: before.x, y: before.y, fx: before.fx, fy: before.fy});
			}
			return [String(node.id), node];
		}));
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
		const first_device = devices.find(node => node.type === 'device');
		if (first_device) {
			await this.selectNode(first_device);
		}
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
				this.selectLink(this.resolvedLink(link));
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
			if (!confirm(<?= json_encode(_('Delete this Device? Its ports and discovered links are deleted too. If a current neighbor row reports it again, ingest makes it again with a new id.')) ?>)) {
				return;
			}
			await post('topology.devices.delete', {id: data.device});
			this.details.innerHTML = '';
			await this.loadDevices();
			return;
		}
		await this.loadDevices({reselect_device: stay});
	}

	async selectNode(node) {
		// two clicks of a double click start two selections; the panel of a later request (a fold shows the group) must not be overwritten by a late answer of an earlier one
		const token = this.select_token = (this.select_token ?? 0) + 1;
		// Host/Proxy: one combined panel, plus a Device section when this node has an active
		// represented_by (node.device, set in render() from the same represented_by edges that used
		// to drive the old split-node merge — see render()'s device_of_target map). A represented
		// Device has no on-screen position of its own (topology-1-model.md "Side panels"), so its /neighbors + /ports are fetched here,
		// keyed off the clicked Host/Proxy, exactly like a standalone Device click below would.
		// Problems section is Host-only (topology-1-model.md "API" /problems, topology-1-model.md "Side panels") — Zabbix has no clean "this problem
		// belongs to this proxy" semantics, so a Proxy panel never fetches or shows one.
		if (node.type === 'host' || node.type === 'proxy') {
			const problems = node.type === 'host'
				? (await this.request(`topology.problems.get&id=${encodeURIComponent(node.id)}`)).problems
				: null;
			let groups = null;
			if (node.device) {
				const [neighbor_groups] = await Promise.all([
					this.request(`topology.ports.get&id=${encodeURIComponent(node.device.id)}`),
					this.mergeNeighbors(node.device)
				]);
				groups = neighbor_groups.groups;
				this.render();
			}
			if (token !== this.select_token) {
				return;
			}
			this.showPanel(node, problems, groups);
			this.addClassLine(node);
			this.addFoldControl(node);
			return;
		}
		if (node.type !== 'device') {
			return;
		}

		const [{groups}, addresses] = await Promise.all([
			this.request(`topology.ports.get&id=${encodeURIComponent(node.id)}`),
			this.request(`topology.addresses.get&id=${encodeURIComponent(node.id)}`),
			this.mergeNeighbors(node)
		]);
		this.render();
		if (token !== this.select_token) {
			return;
		}
		this.showPorts(node, groups, addresses);
		this.addClassLine(node);
		this.addFoldControl(node);
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
			const speed_mismatch = link.source_speed && link.target_speed && link.source_speed !== link.target_speed;
			if (link.source_speed) {
				rows.push([`${this.escape(link.source.name)} ${<?= json_encode(_('speed')) ?>}`,
					this.formatSpeed(link.source_speed) + (speed_mismatch ? ' ⚠' : '')]);
			}
			if (link.target_speed) {
				rows.push([`${this.escape(link.target.name)} ${<?= json_encode(_('speed')) ?>}`,
					this.formatSpeed(link.target_speed) + (speed_mismatch ? ' ⚠' : '')]);
			}
			if (speed_mismatch) {
				rows.push([<?= json_encode(_('Note')) ?>, <?= json_encode(_('Speed mismatch between the two ends.')) ?>]);
			}
			// load of each side: numbers and the graphs of the port items; no colour (§4.8)
			[[link.source, link.source_load], [link.target, link.target_load]].forEach(([node, load]) => {
				const text = this.loadText(load);
				if (text !== '') {
					rows.push([`${this.escape(node.name)} ${<?= json_encode(_('load')) ?>}`, `<span class="topology-wrap">${this.escape(text)} ${this.loadGraphLinks(load)}</span>`, true]);
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
		const table = `<section class="topology-group"><table><tbody>${rows.map(([label, value, raw, label_escaped]) =>
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
				[line('#64748b', 3, '1 4'), <?= json_encode(_('Dotted: device-level link (far port unknown)')) ?>]
			]]
		];
		const legend = document.createElement('div');
		legend.className = 'topology-legend' + (collapsed ? ' topology-legend-collapsed' : '');
		legend.id = 'topology-legend';
		legend.innerHTML = `<div class="topology-legend-head" id="topology-legend-head"><span>${this.escape(<?= json_encode(_('Legend')) ?>)}</span><span id="topology-legend-arrow">${collapsed ? '▸' : '▾'}</span></div>` +
			`<div class="topology-legend-body">${sections.map(([title, rows]) => `<h4>${this.escape(title)}</h4>` + rows.map(([swatch, text]) => row(swatch, this.escape(text))).join('')).join('')}</div>`;
		workspace.appendChild(legend);
		document.getElementById('topology-legend-head').addEventListener('click', () => {
			const now_collapsed = legend.classList.toggle('topology-legend-collapsed');
			document.getElementById('topology-legend-arrow').textContent = now_collapsed ? '▸' : '▾';
			this.guard(() => this.request('topology.legend.state', {method: 'POST', body: JSON.stringify({collapsed: now_collapsed ? 1 : 0})}));
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
		let height = Math.max(520, Math.floor(window.innerHeight - top - 8));
		page.style.height = `${height}px`;
		// what Zabbix puts below the page (the footer) must fit too: Zabbix scrolls .wrapper, not the document
		const wrapper = document.querySelector('.wrapper');
		const excess = wrapper ? wrapper.scrollHeight - wrapper.clientHeight : 0;
		if (excess > 0) {
			height = Math.max(520, height - excess);
			page.style.height = `${height}px`;
		}
	}

	// Problems section fragment only — no title, no this.details assignment. Called from showPanel()
	// only for a Host (never a Proxy, topology-1-model.md "Side panels" — showPanel() guards on `problems !== null`); kept as its
	// own method rather than inlined there so the table markup stays next to formatAge()/cssColor().
	buildProblemsFragment(problems) {
		return `<section class="topology-group"><h3>${this.escape(<?= json_encode(_('Problems')) ?>)}</h3>${problems.length
			? `<table><thead><tr><th>Severity</th><th>Problem</th><th>Age</th></tr></thead><tbody>
				${problems.map(problem => `<tr>
					<td title="${this.escape(problem.severity_name)}"><span class="topology-severity-badge" style="background:${this.cssColor(problem.color)}">${this.escape(problem.severity_name)}</span></td>
					<td title="${this.escape(problem.name)}">${this.escape(problem.name)}</td>
					<td>${this.escape(this.formatAge(problem.age))}</td>
				</tr>`).join('')}
				</tbody></table>`
			: `<div class="topology-empty">${this.escape(<?= json_encode(_('No active problems.')) ?>)}</div>`}</section>`;
	}

	// Host/Proxy side panel (topology-1-model.md "Side panels"): a Problems section for a Host (never for a Proxy — `problems` is
	// null there, topology-1-model.md "API"), plus a Device section when `node.device` is set (this Host/Proxy has an
	// active represented_by — see render()'s device_of_target map, which populates node.device).
	// `groups` is the represented Device's /ports response, or null when there's no represented
	// Device at all — in which case the Device section is simply omitted, not rendered empty. A
	// Proxy with no represented Device gets an entirely empty panel — expected, not a bug (topology-1-model.md "Side panels").
	showPanel(node, problems, groups) {
		this.setDetailsTitle(<?= json_encode(_('Device details')) ?>);
		const problems_section = problems !== null ? this.buildProblemsFragment(problems) : '';
		const device_section = groups !== null
			? `<section class="topology-group"><h3>${this.escape(<?= json_encode(_('Device')) ?>)}</h3>${this.buildPortsFragment(node.device, groups)}</section>`
			: '';
		this.details.innerHTML = `<h2>${this.escape(node.label ?? node.name)}</h2>${problems_section}${device_section}`;
		if (groups !== null) {
			this.wirePortsFragment(node.device, node);
		}
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

	// Device section fragment (grouped /ports table + Promote-or-Depromote button) — no title, no
	// this.details assignment. Shared by showPorts() (standalone Device panel, unchanged behavior) and
	// showPanel() (Device section inside a Host/Proxy's combined panel, topology-1-model.md "Side panels") — `device` is always the
	// actual Device node either way, never the Host/Proxy that might be showing it.
	buildPortsFragment(device, groups) {
		const hidden_by = this.hiddenByFragment(device.id);
		// topology-1-model.md §12.5 "Port table groups": the server gives each port its group (first true rule: connected, lag, mgmt, virtual, mac_only,
		// no_neighbor) and leaves empty groups out. Status (oper / admin) is a column, not a group.
		const labels = {
			connected: <?= json_encode(_('Connected')) ?>, lag: <?= json_encode(_('Port-channel')) ?>, mgmt: <?= json_encode(_('Management')) ?>,
			virtual: <?= json_encode(_('Virtual')) ?>, mac_only: <?= json_encode(_('MAC addresses only')) ?>, no_neighbor: <?= json_encode(_('No neighbor')) ?>
		};
		const help = {
			mac_only: <?= json_encode(_('No LLDP or CDP neighbor on these ports. The switch learned MAC addresses here (FDB), so a device is connected, but it is not known.')) ?>
		};
		const mac_count_text = <?= json_encode(_('%1$s MAC addresses')) ?>;
		// Connected to: the far Device of a link; for a port in "MAC addresses only" the learned MACs, or their number above the MAC limit
		const connected_cell = port => port.group === 'mac_only'
			? (port.learned_mac_count !== null && port.learned_mac_count !== undefined
				? this.escape(mac_count_text.replace('%1$s', String(port.learned_mac_count)))
				: (port.learned_macs ?? []).map(mac => this.escape(mac)).join('<br>'))
			: `${this.escape(port.connected_to ?? '–')}${port.device_link_id ? ` <em>(${this.escape(<?= json_encode(_('port unknown')) ?>)})</em>` : ''}`;
		const status_cell = port => `<span class="topology-status-dot topology-status-${this.escape(port.status)}"></span>${this.escape(port.status)}` +
			(port.admin_status === 'down' ? ` <em>(${this.escape(<?= json_encode(_('admin down')) ?>)})</em>` : '');
		const port_action = port => port.device_link_id
			? `<button type="button" class="btn-alt topology-remove-link-button" data-edge-id="${this.escape(port.device_link_id)}">${this.escape(<?= json_encode(_('Unlink')) ?>)}</button>`
			: port.linked_port_id
			? `<button type="button" class="btn-alt topology-unlink-button" data-port-id="${this.escape(port.id)}" data-linked-port-id="${this.escape(port.linked_port_id)}">${this.escape(<?= json_encode(_('Unlink')) ?>)}</button>`
			: `<button type="button" class="btn-alt topology-link-button" data-port-id="${this.escape(port.id)}" data-port-name="${this.escape(port.port)}">${this.escape(<?= json_encode(_('Link')) ?>)}</button>`;
		const source_classes = {LLDP: 'topology-source-lldp', Manual: 'topology-source-manual'};
		const sections = Object.entries(groups).filter(([, ports]) => ports.length).map(([group, ports]) => `
			<section class="topology-group"><h3>${this.escape(labels[group] ?? group)}</h3>${help[group] ? `<p class="topology-group-help">${this.escape(help[group])}</p>` : ''}<table><colgroup>
				<col class="topology-col-port"><col class="topology-col-status"><col class="topology-col-connected">
				<col class="topology-col-source"><col class="topology-col-action">
			</colgroup><thead><tr>${[<?= json_encode(_('Port')) ?>, <?= json_encode(_('Status')) ?>, <?= json_encode(_('Connected to')) ?>, <?= json_encode(_('Source')) ?>]
				.map(header => `<th title="${this.escape(header)}">${this.escape(header)}</th>`).join('')}<th></th></tr></thead><tbody>
			${ports.map(port => `<tr>
				<td class="topology-port-name" title="${this.escape(port.port)}${port.lag ? ` (${this.escape(port.lag)})` : ''}">${this.escape(port.port)}${port.lag ? ` <em>(${this.escape(port.lag)})</em>` : ''}${port.connected_to || port.device_link_id ? `<a class="topology-port-reports" href="zabbix.php?action=topology.lastrun&port_id=${encodeURIComponent(port.id)}">${this.escape(<?= json_encode(_('Neighbor reports')) ?>)}</a>` : ''}</td>
				<td title="${this.escape(port.status ?? '')}${port.admin_status === 'down' ? ` (${this.escape(<?= json_encode(_('admin down')) ?>)})` : ''}">${status_cell(port)}</td>
				<td${port.group === 'mac_only' ? ' class="topology-mac-list"' : ''} title="${this.escape(port.group === 'mac_only' ? (port.learned_macs ?? []).join(' ') : (port.connected_to ?? ''))}">${connected_cell(port)}</td>
				<td title="${port.source === '-' ? '' : this.escape(port.source)}">${port.source === '-' ? '–' : `<span class="topology-source-badge ${source_classes[port.source] ?? ''}">${this.escape(port.source)}</span>`}</td>
				<td>${port_action(port)}</td>
			</tr>`).join('')}
			</tbody></table></section>`).join('');
		// topology-1-model.md "Lazy Host/Proxy nodes": under lazy-creation, most Zabbix hosts/proxies have no local topo_nodes row (and
		// therefore no `this.nodes` entry) to build a candidate list from — the search-as-you-type
		// picker below (wirePromoteSearch()) queries Zabbix directly via /topo/hosts/search and
		// /topo/proxies/search instead of a pre-fetched candidate list.
		const promotion = device.represented
			? `<div class="topology-promote"><button type="button" class="btn-alt topology-depromote-button">${this.escape(<?= json_encode(_('Depromote')) ?>)}</button></div>`
			: `<div class="topology-promote">
				<input type="text" class="topology-promote-search" placeholder="${this.escape(<?= json_encode(_('Search host or proxy by name/IP…')) ?>)}">
				<div class="topology-promote-results"></div>
				<div class="topology-promote-selected"></div>
				<button type="button" class="btn-alt topology-promote-button" disabled>${this.escape(<?= json_encode(_('Promote to host')) ?>)}</button>
			</div>
			<div class="topology-promote"><button type="button" class="btn-alt topology-delete-device-button" data-topology-dup="delete" data-device="${this.escape(device.id)}">` +
				`${this.escape(<?= json_encode(_('Delete Device')) ?>)}</button></div>`;
		// A pick started on another Device's port can also end at this Device as a whole (a manual device-level link,
		// topology-1-model.md "Manual links" (creation)): the far port is not chosen.
		const link_to_device = this.link_pick && String(this.link_pick.device_id) !== String(device.id)
			? `<div class="topology-promote"><button type="button" class="btn-alt topology-link-device-button">` +
				`${this.escape(<?= json_encode(_('Link to this device (port unknown)')) ?>)}</button></div>`
			: '';
		// §12.7: the last run view filtered to this Device (what the neighbors reported about it)
		const neighbor_reports = `<div class="topology-device-reports"><a href="zabbix.php?action=topology.lastrun&device_id=${encodeURIComponent(device.id)}">` +
			`${this.escape(<?= json_encode(_('Neighbor reports')) ?>)}</a></div>`;
		return `${neighbor_reports}${hidden_by}${this.duplicatesFragment(device)}${sections}${link_to_device}${promotion}`;
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
		const depromote_button = this.details.querySelector('.topology-depromote-button');
		if (depromote_button) {
			depromote_button.addEventListener('click', () => this.guard(async () => {
				await this.request('topology.depromote', {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({id: device.id})});
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
					await this.selectNode(reselect_node);
				}
				else {
					this.link_pick = {id: port_id, device_id: device.id, label: `${device.name} / ${link_button.dataset.portName}`};
					this.renderLinkPick();
				}
			}));
		});
		const link_device_button = this.details.querySelector('.topology-link-device-button');
		if (link_device_button) {
			link_device_button.addEventListener('click', () => this.guard(async () => {
				await this.request('topology.devicelink.create', {
					method: 'POST', headers: {'Content-Type': 'application/json'},
					body: JSON.stringify({port_id: this.link_pick.id, device_id: device.id})
				});
				this.link_pick = null;
				this.renderLinkPick();
				await this.loadDevices();
			}));
		}
		this.details.querySelectorAll('.topology-remove-link-button').forEach(remove_button => {
			remove_button.addEventListener('click', () => this.guard(async () => {
				await this.request('topology.link.remove', {
					method: 'POST', headers: {'Content-Type': 'application/json'},
					body: JSON.stringify({id: remove_button.dataset.edgeId})
				});
				await this.selectNode(reselect_node);
			}));
		});
		this.details.querySelectorAll('.topology-unlink-button').forEach(unlink_button => {
			unlink_button.addEventListener('click', () => this.guard(async () => {
				await this.request('topology.ports.unlink', {
					method: 'POST', headers: {'Content-Type': 'application/json'},
					body: JSON.stringify({id: unlink_button.dataset.portId, dst_id: unlink_button.dataset.linkedPortId})
				});
				await this.selectNode(reselect_node);
			}));
		});
	}

	// Side panel — standalone Device (no represented_by): unchanged by the topology-1-model.md "UI" rewrite, since this only
	// ever applies to a Device with no on-screen Host/Proxy counterpart to begin with.
	showPorts(node, groups, addresses = null) {
		this.setDetailsTitle(<?= json_encode(_('Device details')) ?>);
		this.details.innerHTML = `<h2>${this.escape(node.label ?? node.name)}</h2>${addresses ? this.buildAddressesFragment(addresses) : ''}${this.buildPortsFragment(node, groups)}`;
		this.wirePortsFragment(node, node);
		this.wireCopyButtons();
	}

	// topology-1-model.md §12.5 "Addresses": the addresses of a Device without association as its neighbors report them (topology.addresses.get), above the port table: the
	// sysname and the chassis id (the name for a new host), the addresses (management first, then IPv4, then IPv6) each with a Copy control, and per address who reported it
	// (reporter, local port, LLDP / CDP / push) and how that reporter is monitored (the proxy for the new host). Read only. Every value comes from the network and is
	// escaped (§13): nothing here is markup.
	buildAddressesFragment(data) {
		const escape = value => this.escape(value);
		const copy = (text, label) => `<button type="button" class="btn-alt topology-copy-button" data-copy="${escape(text)}" title="${escape(label)}">${escape(<?= json_encode(_('Copy')) ?>)}</button>`;
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
		const identity = [[<?= json_encode(_('System name')) ?>, data.sysname], [<?= json_encode(_('Hardware ID')) ?>, data.chassis_id]].filter(([, value]) => value)
			.map(([label, value]) => `<tr><th>${escape(label)}</th><td class="topology-address-value" title="${escape(value)}">${escape(value)}</td><td>${copy(value, label)}</td></tr>`).join('');
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
		return `<section class="topology-group topology-addresses"><h3>${escape(<?= json_encode(_('Addresses')) ?>)}</h3>` +
			(identity ? `<table class="topology-address-identity"><tbody>${identity}</tbody></table>` : '') +
			(data.addresses.length ? `<ul class="topology-address-list">${lines}</ul>`
				: `<div class="topology-empty">${escape(<?= json_encode(_('No address reported')) ?>)}</div>`) + `</section>`;
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
			const label = button.textContent;
			button.textContent = <?= json_encode(_('Copied')) ?>;
			setTimeout(() => button.textContent = label, 1200);
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
		this.unfolded.add(String(parent_id));
		this.render();
	}

	foldGroup(parent_id) {
		this.unfolded.delete(String(parent_id));
		this.render();
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
		this.setDetailsTitle(<?= json_encode(_('%1$s devices at %2$s')) ?>.replace('%1$s', String(group.count)).replace('%2$s', parent?.label ?? parent?.name ?? ''));
		this.details.innerHTML = `<h2>${this.escape(group.label)}</h2>` +
			`<div class="topology-group-fold"><button type="button" class="btn-alt" id="topology-fold" data-parent="${this.escape(group.parent)}">${this.escape(
				<?= json_encode(_('Fold %1$s devices')) ?>.replace('%1$s', String(group.count)))}</button></div>` +
			`<p class="topology-group-help">${this.escape(<?= json_encode(_('Devices with one link each, at')) ?>)} ${this.escape(parent?.label ?? parent?.name ?? '')}</p>` +
			`<section class="topology-group"><table><thead><tr><th>${this.escape(<?= json_encode(_('Device')) ?>)}</th><th>${this.escape(<?= json_encode(_('Local port')) ?>)}</th>` +
			`<th>${this.escape(<?= json_encode(_('Remote port')) ?>)}</th></tr></thead><tbody>${rows}</tbody></table></section>`;
		this.wireFoldButtons();
		this.details.querySelectorAll('.topology-group-row').forEach(row => row.addEventListener('click', () => {
			const device = this.nodes.get(row.dataset.deviceId);
			if (device) {
				this.guard(() => this.selectNode(device));
			}
		}));
	}

	// "Fold <N> devices" in a panel (the parent's, and the group's): one delegated lookup after the panel is built.
	wireFoldButtons() {
		this.details.querySelectorAll('[data-parent]').forEach(button => button.addEventListener('click', () => this.foldGroup(button.dataset.parent)));
	}

	// The parent of an unfolded group gets the control "Fold <N> devices" at the top of its panel.
	addFoldControl(node) {
		const group = (this.leaf_groups ?? []).find(candidate => String(candidate.parent) === String(node.id) && this.unfolded.has(String(candidate.parent)));
		if (!group) {
			return;
		}
		const title = this.details.querySelector('h2');
		const holder = document.createElement('div');
		holder.className = 'topology-group-fold';
		const button = document.createElement('button');
		button.type = 'button';
		button.className = 'btn-alt';
		button.dataset.parent = String(group.parent);
		button.textContent = <?= json_encode(_('Fold %1$s devices')) ?>.replace('%1$s', String(group.devices.length));
		holder.appendChild(button);
		(title ?? this.details.firstChild)?.after(holder);
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

	classLabel(name) {
		return {access_point: <?= json_encode(_('Access point')) ?>, phone: <?= json_encode(_('Phone')) ?>, switch: <?= json_encode(_('Switch')) ?>,
			router: <?= json_encode(_('Router')) ?>, station: <?= json_encode(_('Station')) ?>, generic: <?= json_encode(_('Generic (no class)')) ?>}[name] ?? <?= json_encode(_('Generic (no class)')) ?>;
	}

	// The side panel shows the class of a Device and the bits it comes from (§12.2); the bits are data from the network, escaped.
	addClassLine(node) {
		const device = node.type === 'device' ? node : node.device;
		if (!device) {
			return;
		}
		const name = TopologyGraph.iconOf(device);
		const bits = (device.device_class_bits ?? []).join(', ');
		const line = document.createElement('p');
		line.className = 'topology-device-class';
		line.textContent = `${<?= json_encode(_('Device class')) ?>}: ${this.classLabel(name)}${bits !== '' ? ` (${bits})` : ''}`;
		this.details.querySelector('h2')?.after(line);
	}

	render() {
		const G = TopologyGraph;
		const element = this.canvas.node();
		const bounds = element.getBoundingClientRect();
		const view_width = bounds.width || 800;
		const view_height = bounds.height || 500;
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

		// Size (§12.2): large = Host, Proxy, a Device without association with two or more links; small = a leaf, a Device without a link, a group node. The count is by the drawn
		// links before the leaves are folded into groups, so a fold does not change the size of its parent.
		const link_count = new Map();
		simulation_links.filter(link => ['physical_link', 'device_link'].includes(link.type)).forEach(link => {
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
			is_focus: String(node.id) === focus_id}));
		nodes.push(...group_nodes.filter(group_node => nodes.some(node => String(node.id) === group_node.parent)));
		rendered_ids = new Set(nodes.map(node => String(node.id)));
		group_nodes.forEach(group_node => {
			if (rendered_ids.has(group_node.id)) {
				simulation_links.push({type: 'group_link', source: group_node.parent, target: group_node.id});
			}
		});
		simulation_links = simulation_links.filter(link => rendered_ids.has(link.source) && rendered_ids.has(link.target));

		nodes.forEach(node => G.layoutNode(node, G.sizeOf(node, link_count.get(String(node.id)) ?? 0), (text, font) => this.measureText(text, font)));

		// ---- placement (§12.1 "Node placement") ----
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
		if (relayout) {
			nodes.forEach(node => {
				if (node.fx === undefined || node.fx === null) {
					node.x = undefined;
					node.y = undefined;
				}
			});
			if (focus_id !== null) {
				// radial: the focus host in the centre, a node at distance d on ring d, a node with no path on the outer ring
				let radius = G.placeRadial(nodes, simulation_links, focus_id, {x: width / 2, y: height / 2});
				const extent = Math.max(...nodes.map(node => Math.max(node.box.w, node.box.h))) + padding;
				width = Math.max(width, 2 * (radius + extent));
				height = Math.max(height, 2 * (radius + extent));
				radius = G.placeRadial(nodes, simulation_links, focus_id, {x: width / 2, y: height / 2});
			}
			else {
				nodes.forEach((node, index) => {
					if (!Number.isFinite(node.x)) {
						const angle = index * 2.399963;
						const r = 18 * Math.sqrt(index + 1);
						node.x = width / 2 + Math.cos(angle) * r;
						node.y = height / 2 + Math.sin(angle) * r;
					}
				});
				const force_links = simulation_links.map(link => ({source: node_by_id.get(link.source), target: node_by_id.get(link.target)}));
				const collide = () => {
					for (let i = 0; i < nodes.length; i++) {
						for (let j = i + 1; j < nodes.length; j++) {
							const a = nodes[i];
							const b = nodes[j];
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
				const simulation = d3.forceSimulation(nodes)
					.force('link', d3.forceLink(force_links).distance(130).strength(0.6))
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
		}
		else {
			place_new_near_parents();
			// a node that was already placed stays; a new one finds a free place (the placed ones are not moved)
			const stay = new Set(placed_before);
			nodes.forEach(node => {
				if (stay.has(node) && (node.fx === undefined || node.fx === null)) {
					node.fx = node.x;
					node.fy = node.y;
					node.temp_fixed = true;
				}
			});
		}
		// the collision step: no box (shape and label) overlaps another; too small an area → a larger one
		for (let attempt = 0; attempt < 6; attempt++) {
			nodes.forEach(node => {
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
		simulation_links.forEach(link => {
			link.source = node_by_id.get(link.source);
			link.target = node_by_id.get(link.target);
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
				if (link.type === 'group_link') {
					return '#64748b';
				}
				if (link.type === 'device_link') {
					return link.discovered_via === 'manual' ? '#334155' : '#64748b';
				}
				return link.type === 'physical_link' && link.severity !== null && link.severity !== undefined ? this.cssColor(link.color) : '#64748b';
			},
			width: link => {
				const severe = link.type === 'physical_link' && link.severity !== null && link.severity !== undefined;
				return link.type === 'device_link' ? 3 : (severe ? 4 : 2);
			}
		};
		// the highlight under the line of a hovered node (§12.3 "Hover"): its own element, so the channels of the link itself do not change
		const glows = scene.append('g').selectAll('path').data(simulation_links).join('path')
			.attr('class', 'topology-link-glow').style('stroke-width', link => link_style.width(link) + 6);
		const links = scene.append('g').selectAll('path').data(simulation_links).join('path')
			.attr('class', 'topology-link')
			.style('fill', 'none')
			.style('cursor', link => link.type === 'group_link' ? 'default' : 'pointer')
			.style('pointer-events', 'stroke')
			.on('click', (event, link) => {
				if (link.type !== 'group_link') {
					this.selectLink(link);
				}
			})
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
				return (link.type === 'physical_link' && link.discovered_via === 'manual') ? '5 3' : null;
			})
			// staleness (§12.3): a third independent channel; never collapse it into the dash pattern or the colour
			.style('opacity', link => (['physical_link', 'device_link'].includes(link.type) && (link.stale || link.superseded_at)) ? 0.45 : 1);
		links.append('title').text(link => {
			if (link.type === 'physical_link') {
				const provenance = link.discovered_via === 'manual'
					? <?= json_encode(_('manually declared')) ?>
					: (link.discovery_source || 'lldp').toUpperCase() + '-' + <?= json_encode(_('discovered')) ?>;
				const status_labels = {
					up: <?= json_encode(_('Up')) ?>, down: <?= json_encode(_('DOWN')) ?>,
					disabled: <?= json_encode(_('Disabled')) ?>
				};
				const sides = [];
				if (link.source_status) {
					sides.push(`${link.source.name}: ${status_labels[link.source_status] ?? link.source_status}`);
				}
				if (link.target_status) {
					sides.push(`${link.target.name}: ${status_labels[link.target_status] ?? link.target_status}`);
				}
				const ports = link.source_port || link.target_port ? ` ${link.source.name} ${link.source_port ?? '?'} ↔ ${link.target.name} ${link.target_port ?? '?'}.` : '';
				const loads = [[link.source, link.source_load], [link.target, link.target_load]].map(([node, load]) => {
					const text = this.loadText(load);
					return text === '' ? '' : ` ${node.name} ${<?= json_encode(_('load')) ?>}: ${text}.`;
				}).join('');
				const severity_note = link.severity !== null && link.severity !== undefined ? ` ${<?= json_encode(_('Severity')) ?>}: ${link.severity_name}.` : '';
				const lost = this.lostPrecisionNotes(link).map(note => ` ${note}`).join('') + loads + severity_note;
				const stale_note = (link.superseded_at
					? ` ${this.replacedNote(link)}`
					: (link.stale ? ` ${<?= json_encode(_('Not recently reconfirmed.')) ?>}` : '')) + lost;
				return `${provenance}.${ports}${sides.length ? ' ' + sides.join(', ') : ''}${stale_note}`;
			}
			if (link.type === 'device_link') {
				return this.deviceLinkTooltip(link);
			}
			return '';
		});
		// A bundle of device-level links between the same two Devices is one line labelled with the count and the known local ports on each side ("2 links: Gi0/1, Gi0/2 ↔ ?").
		const bundle_data = simulation_links.filter(link => link.type === 'device_link' && (link.count ?? 0) > 1);
		const bundle_labels = scene.append('g').selectAll('text').data(bundle_data).join('text')
			.attr('text-anchor', 'middle').style('font-size', '10px').style('fill', '#475569')
			.style('pointer-events', 'none').text(link => this.deviceBundleLabel(link));
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
			.attr('class', 'topology-ghost-link')
			.style('cursor', 'pointer')
			.on('click', (event, ghost) => this.manualAction('open', ghost.entry.edge_id));
		const ghost_lines = ghosts.append('line').attr('stroke', '#c2410c').attr('stroke-width', 2)
			.attr('stroke-dasharray', '2 5').style('pointer-events', 'stroke');
		ghosts.append('title').text(ghost => this.shadowText(ghost.shadow));
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
					pair_data.push({from, to, a: device, candidate});
				});
			});
		}
		const pairs = scene.append('g').selectAll('g').data(pair_data).join('g')
			.attr('class', 'topology-pair-line').style('cursor', 'pointer')
			.on('click', (event, pair) => this.selectPair(pair.a, pair.candidate));
		const pair_lines = pairs.append('line').attr('stroke', '#b45309').attr('stroke-width', 2)
			.attr('stroke-dasharray', '8 4').style('pointer-events', 'stroke');
		pairs.append('title').text(pair => this.pairReason(pair.candidate));
		const pair_labels = pairs.append('text').attr('text-anchor', 'middle').style('font-size', '10px')
			.style('fill', '#b45309').style('pointer-events', 'none').text(pair => this.pairReason(pair.candidate));
		const contradicted_links = simulation_links.filter(link => ['physical_link', 'device_link'].includes(link.type) && link.edge_id !== null
			&& link.edge_id !== undefined && this.contradictionOf(link.edge_id)?.contradicted);
		const link_badges = scene.append('g').selectAll('g').data(contradicted_links).join('g')
			.attr('class', 'topology-contradiction-badge').style('cursor', 'pointer')
			.on('click', (event, link) => this.selectLink(link));
		link_badges.append('circle').attr('r', 9).style('fill', '#c2410c').style('stroke', '#7c2d12').style('stroke-width', 2);
		link_badges.append('text').attr('y', 4).attr('text-anchor', 'middle').style('font-size', '12px')
			.style('font-weight', 'bold').style('fill', '#ffffff').style('pointer-events', 'none').text('!');
		link_badges.append('title').text(link => this.contradictionSummary(this.contradictionOf(link.edge_id)));

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
		const stroke_for = subject => subject.type === 'device' || subject.type === 'group' ? '#697386' : '#1f2937';
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
					this.foldGroup(group.parent);
					// 091 (§12.8): the side panel then shows the group, not a leaf that is no longer drawn
					const group_node = this.group_nodes.get(String(group.parent));
					if (group_node) {
						this.showGroup(group_node);
					}
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
						draw_positions();
					}
				}));
		node_selection.each((node, i, elements) => {
			node.element = elements[i];
		});
		// the shape: no text inside it (§12.2 "Label placement")
		node_selection.append('rect').attr('class', 'topology-shape')
			.attr('x', node => -node.shape_w / 2).attr('y', node => -node.shape_h / 2).attr('width', node => node.shape_w).attr('height', node => node.shape_h)
			.attr('rx', node => node.size === 'small' ? 3 : 5)
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
				if (node.type === 'group') {
					this.unfoldGroup(node.parent);
					this.showGroup(node);
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
		node_selection.append('text').attr('class', 'topology-label').attr('text-anchor', 'middle')
			.attr('y', node => node.shape_h / 2 + 3 + node.font)
			.style('font-size', node => `${node.font}px`).style('fill', '#1f2937').style('pointer-events', 'none')
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
			group.append('title').text(text_of);
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
		node_selection.append('title').attr('class', 'topology-label-title').text(node => describeSubject(node));

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
			bundle_labels.attr('x', link => G.curve(link.source, link.target, link.parallel.index, link.parallel.count).mid.x)
				.attr('y', link => G.curve(link.source, link.target, link.parallel.index, link.parallel.count).mid.y - 6);
		};
		draw_positions();
		this.drawn_nodes = nodes;
		this.drawn_links = simulation_links;
		this.link_selection = links;
		this.glow_selection = glows;
	}

	// Hover (§12.3): the node and its drawn links get a highlight outline, nothing else changes (the opacity stays the channel of stale links).
	highlight(node, on) {
		d3.select(node.element).classed('topology-hover', on);
		this.glow_selection?.classed('topology-link-hover', link => on && (link.source === node || link.target === node));
	}
};
</script>
