<?php declare(strict_types = 0); ?>
<script>
const view = new class {
	async request(action, options = {}) {
		const response = await fetch(`zabbix.php?action=${action}`, options);
		const payload = await response.json();
		if (!response.ok || payload.error) {
			throw new Error(payload.error?.messages?.join('\n') ?? `Topology request failed: ${response.status}`);
		}
		return payload;
	}

	// Every mutating action (promote, depromote) can be legitimately rejected by the backend (e.g.
	// §5 rule 2's identity-uniqueness check) — without this, request()'s thrown Error would be an
	// unhandled promise rejection: the click does nothing visible and the failure only shows up in
	// the browser console.
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

	isTModel() {
		return this.prefix === 'topot';
	}

	// §4.2 (T-model) vs. the old model's "host:<hostid>" -- opaque id formats differ per backend;
	// nothing on either side should hardcode one or the other.
	hostNodeId(hostid) {
		return this.isTModel() ? `h:${hostid}` : `host:${hostid}`;
	}

	renderDiagnostics(diagnostics) {
		const steps = Object.entries(diagnostics.steps ?? {})
			.map(([name, {ms}]) => `<tr><td>${this.escape(name)}</td><td>${ms} ms</td></tr>`).join('');
		const conflicts = (diagnostics.conflicts ?? []).map(c => `<li>${this.escape(c.message ?? JSON.stringify(c))}</li>`).join('');
		const dangling = (diagnostics.dangling ?? []).map(d => `<li>${this.escape(`${d.tag} on host ${d.hostid}: ${d.value}`)}</li>`).join('');
		const counts = diagnostics.counts ?? {};
		this.diagnostics_panel.innerHTML = `<h3>${this.escape(<?= json_encode(_('T-model diagnostics')) ?>)}</h3>
			<table><tbody>${steps}<tr><td>${this.escape(<?= json_encode(_('API calls')) ?>)}</td><td>${diagnostics.api_calls ?? '–'}</td></tr>
			<tr><td>${this.escape(<?= json_encode(_('Nodes / links')) ?>)}</td><td>${counts.nodes ?? 0} / ${counts.links ?? 0}</td></tr>
			<tr><td>${this.escape(<?= json_encode(_('Lost sides / silent (stale) sides')) ?>)}</td><td>${counts.lost_sides ?? 0} / ${counts.silent_sides ?? 0}</td></tr>
			</tbody></table>
			${conflicts ? `<p><strong>${this.escape(<?= json_encode(_('Conflicts:')) ?>)}</strong></p><ul>${conflicts}</ul>` : ''}
			${dangling ? `<p><strong>${this.escape(<?= json_encode(_('Dangling tag references:')) ?>)}</strong></p><ul>${dangling}</ul>` : ''}`;
	}

	setDetailsTitle(text) {
		document.getElementById('topology-details-title').textContent = text;
	}

	async init() {
		this.canvas = d3.select('#topology-canvas');
		this.details = document.getElementById('topology-details');
		this.diagnostics_panel = document.getElementById('topology-diagnostics-panel');
		this.nodes = new Map();
		this.links = [];

		// §7 point 1: G/T data-source switch. 'topology' = CTopologyPrototype (ad-hoc host tags),
		// 'topot' = CTopologyTModel (LLD-collected tags/items, topology-t-model-prototype-spec.md).
		// Everything below reads/writes through this.prefix rather than a hardcoded action name,
		// so the two graphs are viewable one after another without a reload.
		this.prefix = 'topology';
		const model_select = document.getElementById('topology-model-select');
		model_select.addEventListener('change', () => this.guard(async () => {
			this.prefix = (model_select.value === 'T') ? 'topot' : 'topology';
			this.diagnostics_panel.innerHTML = '';
			// §6: T-model has no ingest/pull endpoints -- LLD is the ingest. Hide the G-only
			// "Run discovery ingest" control while T is selected rather than leaving a button
			// that would silently no-op (or worse, mutate the OTHER model's tags) on click.
			const ingest_field = this.ingest_button.closest('.topology-filter-field');
			if (ingest_field) {
				ingest_field.hidden = this.isTModel();
			}
			await this.loadDevices();
		}));

		document.getElementById('topology-diagnostics-button').addEventListener('click', () => this.guard(async () => {
			if (!this.isTModel()) {
				this.diagnostics_panel.innerHTML = `<div class="topology-empty">${this.escape(<?= json_encode(_('Diagnostics is a T-model-only instrument (spec §5.6) — switch the Model selector to T.')) ?>)}</div>`;
				return;
			}
			const diagnostics = await this.request('topot.diagnostics');
			this.renderDiagnostics(diagnostics);
		}));

		const panel_toggle = document.getElementById('topology-panel-toggle');
		const workspace = document.querySelector('.topology-workspace');
		panel_toggle.addEventListener('click', () => {
			const collapsed = workspace.classList.toggle('topology-panel-collapsed');
			panel_toggle.textContent = collapsed ? '»' : '«';
			panel_toggle.title = collapsed ? <?= json_encode(_('Expand')) ?> : <?= json_encode(_('Collapse')) ?>;
			setTimeout(() => this.render(), 160);
		});
		document.getElementById('topology-filter-apply').addEventListener('click', () => this.guard(async () => {
			await this.loadDevices();
		}));
		document.getElementById('topology-filter-clear').addEventListener('click', () => this.guard(async () => {
			jQuery('#groupids_').multiSelect('clean');
			jQuery('#hostid').multiSelect('clean');
			document.getElementById('topology-filter-hops').value = '1';
			await this.loadDevices();
		}));

		this.ingest_button = document.getElementById('topology-ingest-run');
		this.ingest_status = document.getElementById('topology-ingest-status');
		this.ingest_button.addEventListener('click', () => this.guard(() => this.runIngest()));
		await this.pollIngestStatus({silent: true});

		await this.loadDevices();
	}

	// §14.2: "Run discovery ingest" button — POSTs to the same code path ingest.php's CLI form
	// uses (CControllerTopologyIngestRun just shells out to that script), then polls
	// topology.ingest.status until the run leaves "running", refreshing the graph on completion
	// so newly-ingested tags show up without a manual reload.
	async runIngest() {
		this.ingest_button.disabled = true;
		this.ingest_status.textContent = <?= json_encode(_('Starting…')) ?>;
		await this.request('topology.ingest.run', {method: 'POST', headers: {'Content-Type': 'application/json'}, body: '{}'});
		await this.pollIngestStatus({refresh_on_done: true});
	}

	async pollIngestStatus({silent = false, refresh_on_done = false} = {}) {
		const status = await this.request('topology.ingest.status');
		if (status.status === 'running') {
			this.ingest_button.disabled = true;
			this.ingest_status.textContent = <?= json_encode(_('Running…')) ?>;
			setTimeout(() => this.guard(() => this.pollIngestStatus({refresh_on_done: true})), 1500);
			return;
		}
		this.ingest_button.disabled = false;
		if (status.status === 'done' && status.summary) {
			this.ingest_status.textContent = sprintf(<?= json_encode(_('Done: %1$s reporter(s), %2$s tag(s) written, %3$s error(s).')) ?>,
				status.summary.reporters_processed, status.summary.tags_written, status.summary.errors);
		}
		else if (status.status === 'error') {
			this.ingest_status.textContent = `${<?= json_encode(_('Ingest failed: ')) ?>}${status.error ?? ''}`;
		}
		else if (!silent) {
			this.ingest_status.textContent = '';
		}
		if (refresh_on_done) {
			await this.loadDevices();
		}
	}

	filterQuery() {
		const groupids = [...document.querySelectorAll('input[name="groupids[]"]')].map(input => input.value);
		const hostid = document.querySelector('input[name="hostid"]')?.value ?? '';
		const hops = document.getElementById('topology-filter-hops').value;
		let query = '';
		if (hostid) {
			query += `&hostid=${encodeURIComponent(hostid)}&hops=${encodeURIComponent(hops)}`;
		}
		else {
			query += this.groupidsQuery(groupids);
		}
		return query;
	}

	groupidsQuery(groupids) {
		return groupids.map(id => `&groupids[]=${encodeURIComponent(id)}`).join('');
	}

	// §14.2-style refresh-in-place: a plain reload always re-selects the first host, which would throw
	// away whatever port/device panel the user was looking at right after a Link/Unlink action mutates
	// the very graph they're inspecting. preferred_id, when it's still present in the reloaded node set,
	// keeps that same node selected instead of jumping back to the top.
	async loadDevices(preferred_id = null) {
		const {devices = [], relations = []} = await this.request(`${this.prefix}.devices.get${this.filterQuery()}`);
		this.nodes = new Map(devices.map(node => [String(node.id), node]));
		this.links = relations.map(relation => ({
			source: String(relation.source), target: String(relation.target), type: relation.type,
			source_port: relation.source_port, target_port: relation.target_port
		}));
		this.render();
		const target = (preferred_id && this.nodes.get(String(preferred_id)))
			|| devices.find(node => node.type === 'host');
		if (target) {
			await this.selectNode(target);
		}
	}

	async selectNode(node) {
		if (node.type === 'host') {
			const [{problems}, {groups}] = await Promise.all([
				this.request(`${this.prefix}.problems.get&id=${encodeURIComponent(node.id)}`),
				this.request(`${this.prefix}.ports.get&id=${encodeURIComponent(node.id)}`)
			]);
			this.showHostPanel(node, problems, groups);
			return;
		}
		if (node.type !== 'device') {
			return;
		}
		this.showUnmanagedPanel(node);
	}

	// §21: an unmanaged node has no ports/problems of its own — it only ever appears as the remote
	// end of some Host's neighbor observation. Its panel is just identity + a promote picker.
	showUnmanagedPanel(node) {
		this.setDetailsTitle(<?= json_encode(_('Device details')) ?>);
		const meta = `<section class="topology-group"><table><tbody>` +
			`<tr><th>${this.escape(<?= json_encode(_('Chassis ID')) ?>)}</th><td>${this.escape(node.chassis_id)}</td></tr>` +
			`</tbody></table></section>`;
		this.details.innerHTML = `<h2>${this.escape(node.name)}</h2>${meta}${this.buildPromoteFragment(node)}`;
		this.wirePromoteFragment(node);
	}

	showHostPanel(node, problems, groups) {
		this.setDetailsTitle(<?= json_encode(_('Device details')) ?>);
		const meta_rows = [];
		if (node.identity) {
			meta_rows.push([<?= json_encode(_('Topology identity')) ?>, node.identity]);
		}
		if (node.chassis_id && node.chassis_id !== node.identity) {
			meta_rows.push([<?= json_encode(_('Reported chassis ID')) ?>, node.chassis_id]);
		}
		if (node.device_type) {
			meta_rows.push([<?= json_encode(_('Type')) ?>, node.device_type]);
		}
		const meta = meta_rows.length
			? `<section class="topology-group"><table><tbody>${meta_rows.map(([label, value]) =>
				`<tr><th>${this.escape(label)}</th><td>${this.escape(value)}</td></tr>`).join('')}</tbody></table></section>`
			: '';
		// §7 point 3: a Host node's side panel may contain several Device sections (A7 stacks/MLAG)
		// -- only meaningful in T-mode, where node.devices[] can have more than one entry.
		const devices_section = (this.isTModel() && Array.isArray(node.devices) && node.devices.length)
			? `<section class="topology-group"><h3>${this.escape(<?= json_encode(_('Device(s)')) ?>)}</h3><table><tbody>${
				node.devices.map(d => `<tr><td>${this.escape(d.sysname ?? d.display_key)}</td>
					<td>${this.escape(d.matched_by ?? '')}</td>
					<td>${d.conflict ? `<span class="topology-conflict-badge">${this.escape(<?= json_encode(_('conflict')) ?>)}</span>` : ''}</td></tr>`).join('')
				}</tbody></table></section>`
			: '';
		const problems_section = this.buildProblemsFragment(problems);
		const ports_section = this.buildPortsFragment(groups, node);
		// §6: a Host only has a Depromote button when IT is the one carrying an explicit manual
		// identity binding -- a reporter identified purely by reporter_self has nothing to
		// depromote (T-model rejects that with 409, G-model never shows the button for it).
		const depromote_section = node.identity ? this.depromoteButtonFragment() : '';
		this.details.innerHTML = `<h2>${this.escape(node.name)}${node.conflict ? `<span class="topology-conflict-badge">${this.escape(<?= json_encode(_('conflict')) ?>)}</span>` : ''}</h2>${meta}${devices_section}${problems_section}${ports_section}${depromote_section}`;
		this.wirePortsFragment(node);
		if (node.identity) {
			this.details.querySelector('.topology-depromote-button').addEventListener('click', () => this.guard(async () => {
				const body = this.isTModel()
					? {device_id: (node.devices?.[0]?.cluster_key) ?? node.identity}
					: {hostid: node.hostid};
				await this.request(`${this.prefix}.depromote`, {
					method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify(body)
				});
				await this.loadDevices();
			}));
		}
	}

	buildProblemsFragment(problems) {
		return `<section class="topology-group"><h3>${this.escape(<?= json_encode(_('Problems')) ?>)}</h3>${problems.length
			? `<table><thead><tr><th>Severity</th><th>Problem</th><th>Age</th></tr></thead><tbody>
				${problems.map(problem => `<tr>
					<td><span class="topology-severity-badge" style="background:${this.cssColor(problem.color)}">${this.escape(problem.severity_name)}</span></td>
					<td>${this.escape(problem.name)}</td>
					<td>${this.escape(this.formatAge(problem.age))}</td>
				</tr>`).join('')}
				</tbody></table>`
			: `<div class="topology-empty">${this.escape(<?= json_encode(_('No active problems.')) ?>)}</div>`}</section>`;
	}

	// §8/§21: ports are pure tag-derived metadata — no separate port entity, no MAC-only/partial
	// group (no CAM-table data exists in this model at all, §24), just connected/disconnected plus
	// the two optional if_type-driven groups. The Link/Unlink button writes/removes
	// topology.neighbor.<if_index>.* directly (CTopologyPrototype::linkPort()/unlinkPort()) — there is
	// no separate manual-link entity, so a manual link looks exactly like a discovered one afterwards.
	buildPortsFragment(groups, node) {
		const labels = {
			connected: 'Connected', disconnected: 'Disconnected',
			port_channel: 'Port-channel', management: 'Management'
		};
		const sections = Object.entries(groups).filter(([, ports]) => ports.length).map(([group, ports]) => `
			<section class="topology-group"><h3>${labels[group]}</h3><table><colgroup>
				<col class="topology-col-port"><col class="topology-col-status"><col class="topology-col-connected"><col class="topology-col-action">
			</colgroup><thead><tr><th>Port</th><th>Status</th><th>Connected to</th><th></th></tr></thead><tbody>
			${ports.map(port => `<tr data-if-index="${port.if_index}" data-port-name="${this.escape(port.port)}" data-port-id="${this.escape(port.port_id ?? '')}" data-connected-to-port-id="${this.escape(port.connected_to_port_id ?? '')}">
				<td class="topology-port-name" title="${this.escape(port.port)}">${this.escape(port.port)}
					${port.conflict ? `<span class="topology-conflict-badge">${this.escape(<?= json_encode(_('conflict')) ?>)}</span>` : ''}
					${port.stale ? `<span class="topology-lost-badge">${this.escape(<?= json_encode(_('stale')) ?>)}</span>` : ''}
					${(this.isTModel() && port.itemid) ? ` <a href="history.php?action=showvalues&itemids[]=${encodeURIComponent(port.itemid)}" target="_blank" class="topology-history-link">${this.escape(<?= json_encode(_('history')) ?>)}</a>` : ''}
				</td>
				<td>${port.status ? `<span class="topology-status-dot topology-status-${this.escape(port.status)}"></span>${this.escape(port.status)}` : ''}</td>
				<td title="${this.escape(port.connected_to ?? '')}">${this.escape(port.connected_to ?? '–')}</td>
				<td class="topology-col-action">${port.connected_to
					? `<button type="button" class="btn-alt topology-port-unlink-button" data-if-index="${port.if_index}">${this.escape(<?= json_encode(_('Unlink')) ?>)}</button>`
					: `<button type="button" class="btn-alt topology-port-link-button" data-if-index="${port.if_index}">${this.escape(<?= json_encode(_('Link')) ?>)}</button>`}</td>
			</tr>`).join('')}
			</tbody></table></section>`).join('');
		return sections || `<div class="topology-empty">${this.escape(<?= json_encode(_('No ports reported.')) ?>)}</div>`;
	}

	wirePortsFragment(node) {
		this.details.querySelectorAll('.topology-port-unlink-button').forEach(button => {
			button.addEventListener('click', () => this.guard(async () => {
				const row = button.closest('tr');
				const body = this.isTModel()
					? {src: row.dataset.portId, dst: row.dataset.connectedToPortId}
					: {hostid: node.hostid, if_index: button.dataset.ifIndex};
				await this.request(`${this.prefix}.port.unlink`, {
					method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify(body)
				});
				await this.loadDevices(node.id);
			}));
		});
		this.details.querySelectorAll('.topology-port-link-button').forEach(button => {
			button.addEventListener('click', () => this.togglePortLinkPicker(node, button));
		});
	}

	// Inline picker row, opened directly under the port that was clicked: search for a target host,
	// then pick one of ITS ports from a <select> (populated via a second /topo/ports/get call), then
	// confirm. Only one picker is ever open at a time — opening a second one, or clicking the same
	// button again, closes whichever is already there first.
	togglePortLinkPicker(node, button) {
		const existing = this.details.querySelector('.topology-port-link-row');
		const reopening_same = existing && existing.dataset.ifIndex === button.dataset.ifIndex;
		if (existing) {
			existing.remove();
		}
		if (reopening_same) {
			return;
		}

		const if_index = button.dataset.ifIndex;
		const source_port_id = button.closest('tr').dataset.portId;
		const row = document.createElement('tr');
		row.className = 'topology-port-link-row';
		row.dataset.ifIndex = if_index;
		row.dataset.sourcePortId = source_port_id;
		row.innerHTML = `<td colspan="4"><div class="topology-port-link-picker">
			<input type="text" class="topology-port-link-search" placeholder="${this.escape(<?= json_encode(_('Search target host by name/IP…')) ?>)}">
			<div class="topology-port-link-results"></div>
			<select class="topology-port-link-target-port" disabled>
				<option value="">${this.escape(<?= json_encode(_('Select a host first')) ?>)}</option>
			</select>
			<div class="topology-port-link-actions">
				<button type="button" class="btn-alt topology-port-link-confirm" disabled>${this.escape(<?= json_encode(_('Link')) ?>)}</button>
				<button type="button" class="btn-alt topology-port-link-cancel">${this.escape(<?= json_encode(_('Cancel')) ?>)}</button>
			</div>
		</div></td>`;
		button.closest('tr').insertAdjacentElement('afterend', row);

		const search_input = row.querySelector('.topology-port-link-search');
		const results_container = row.querySelector('.topology-port-link-results');
		const port_select = row.querySelector('.topology-port-link-target-port');
		const confirm_button = row.querySelector('.topology-port-link-confirm');

		row.querySelector('.topology-port-link-cancel').addEventListener('click', () => row.remove());

		search_input.addEventListener('input', () => {
			clearTimeout(this.port_link_search_timer);
			const q = search_input.value.trim();
			if (!q) {
				results_container.innerHTML = '';
				return;
			}
			this.port_link_search_timer = setTimeout(() => this.guard(async () => {
				const {hosts = []} = await this.request(`${this.prefix}.hosts.search&q=${encodeURIComponent(q)}`);
				const candidates = hosts.filter(host => String(host.hostid) !== String(node.hostid));
				results_container.innerHTML = candidates.length
					? candidates.map((host, index) => `<div class="topology-port-link-result" data-index="${index}">${this.escape(host.name)}</div>`).join('')
					: `<div class="topology-port-link-result">${this.escape(<?= json_encode(_('No matches.')) ?>)}</div>`;
				results_container.querySelectorAll('.topology-port-link-result[data-index]').forEach(item => {
					item.addEventListener('click', () => this.guard(async () => {
						const target_host = candidates[Number(item.dataset.index)];
						results_container.innerHTML = '';
						search_input.value = target_host.name;
						port_select.disabled = true;
						confirm_button.disabled = true;
						port_select.innerHTML = `<option value="">${this.escape(<?= json_encode(_('Loading ports…')) ?>)}</option>`;

						const {groups: target_groups = {}} = await this.request(`${this.prefix}.ports.get&id=${encodeURIComponent(this.hostNodeId(target_host.hostid))}`);
						const target_ports = Object.values(target_groups).flat();
						port_select.innerHTML = target_ports.length
							? target_ports.map(port => `<option value="${port.if_index}" data-port-id="${this.escape(port.port_id ?? '')}">${this.escape(port.port)}</option>`).join('')
							: `<option value="">${this.escape(<?= json_encode(_('This host has no ports reported.')) ?>)}</option>`;
						port_select.disabled = target_ports.length === 0;
						confirm_button.disabled = target_ports.length === 0;
						confirm_button.dataset.targetHostid = target_host.hostid;
					}));
				});
			}), 250);
		});

		confirm_button.addEventListener('click', () => this.guard(async () => {
			if (!confirm_button.dataset.targetHostid || !port_select.value) {
				return;
			}
			const body = this.isTModel()
				? {src: source_port_id, dst: port_select.selectedOptions[0].dataset.portId}
				: {
					hostid: node.hostid, if_index, target_hostid: confirm_button.dataset.targetHostid,
					target_if_index: port_select.value
				};
			await this.request(`${this.prefix}.port.link`, {
				method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify(body)
			});
			await this.loadDevices(node.id);
		}));
	}

	// §5/§6: search-as-you-type picker for /promote, backed by /topo/hosts/search (a thin host.get
	// wrapper — nothing local to search against under the tags-only model). Debounced.
	buildPromoteFragment(node) {
		return `<div class="topology-promote">
			<input type="text" class="topology-promote-search" placeholder="${this.escape(<?= json_encode(_('Search host by name/IP…')) ?>)}">
			<div class="topology-promote-results"></div>
			<div class="topology-promote-selected"></div>
			<button type="button" class="btn-alt topology-promote-button" disabled>${this.escape(<?= json_encode(_('Promote to host')) ?>)}</button>
		</div>`;
	}

	wirePromoteFragment(node) {
		this.promote_selection = null;
		const search_input = this.details.querySelector('.topology-promote-search');
		const results_container = this.details.querySelector('.topology-promote-results');
		const selected_container = this.details.querySelector('.topology-promote-selected');
		const promote_button = this.details.querySelector('.topology-promote-button');
		if (!search_input) {
			return;
		}

		const select = host => {
			this.promote_selection = host;
			results_container.innerHTML = '';
			search_input.value = '';
			selected_container.textContent = `${<?= json_encode(_('Host: ')) ?>}${host.name}`;
			promote_button.disabled = false;
		};

		search_input.addEventListener('input', () => {
			clearTimeout(this.promote_search_timer);
			const q = search_input.value.trim();
			if (!q) {
				results_container.innerHTML = '';
				return;
			}
			this.promote_search_timer = setTimeout(() => this.guard(async () => {
				const {hosts = []} = await this.request(`${this.prefix}.hosts.search&q=${encodeURIComponent(q)}`);
				results_container.innerHTML = hosts.length
					? hosts.map((host, index) => `<div class="topology-promote-result" data-index="${index}">${this.escape(host.name)}</div>`).join('')
					: `<div class="topology-promote-result">${this.escape(<?= json_encode(_('No matches.')) ?>)}</div>`;
				results_container.querySelectorAll('.topology-promote-result[data-index]').forEach(item => {
					item.addEventListener('click', () => select(hosts[Number(item.dataset.index)]));
				});
			}), 250);
		});

		promote_button.addEventListener('click', () => this.guard(async () => {
			if (!this.promote_selection) {
				return;
			}
			const body = this.isTModel()
				? {device_id: node.chassis_id, hostid: this.promote_selection.hostid}
				: {identity: node.chassis_id, hostid: this.promote_selection.hostid};
			await this.request(`${this.prefix}.promote`, {
				method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify(body)
			});
			await this.loadDevices();
		}));
	}

	depromoteButtonFragment() {
		return `<div class="topology-promote"><button type="button" class="btn-alt topology-depromote-button">${this.escape(<?= json_encode(_('Depromote')) ?>)}</button></div>`;
	}

	render() {
		const element = this.canvas.node();
		const bounds = element.getBoundingClientRect();
		const width = bounds.width || 800;
		const height = bounds.height || 500;
		const padding = 70;

		const nodes = [...this.nodes.values()];
		const node_ids = new Set(nodes.map(node => String(node.id)));
		// GOTCHAS.md #5 (previous prototype): filter links to endpoints that actually exist in the
		// currently-loaded node set before handing them to forceLink() — it throws otherwise.
		const simulation_links = this.links
			.filter(link => node_ids.has(link.source) && node_ids.has(link.target))
			.map(link => ({...link}));

		this.canvas.selectAll('*').remove();
		const is_first_render = !this.simulation;

		nodes.forEach(node => {
			if (node.x === undefined) {
				node.x = width / 2 + (Math.random() - 0.5) * 100;
				node.y = height / 2 + (Math.random() - 0.5) * 100;
			}
		});

		const simulation = d3.forceSimulation(nodes)
			.alpha(is_first_render ? 1 : 0.3)
			.force('link', d3.forceLink(simulation_links).id(node => String(node.id)).distance(160))
			.force('charge', d3.forceManyBody().strength(-600))
			.force('center', d3.forceCenter(width / 2, height / 2));
		this.simulation = simulation;

		const links = this.canvas.append('g').selectAll('line').data(simulation_links).join('line')
			.attr('class', 'topology-link')
			.style('cursor', 'pointer')
			.attr('stroke', '#64748b')
			.attr('stroke-width', 2);
		links.append('title').text(link => {
			const source = this.nodes.get(link.source), target = this.nodes.get(link.target);
			const source_label = link.source_port ? `${source?.name} / ${link.source_port}` : source?.name;
			const target_label = link.target_port ? `${target?.name} / ${link.target_port}` : target?.name;
			return `${source_label} ↔ ${target_label}`;
		});

		const fill_for = node => {
			if (node.type === 'device') {
				return '#f3f4f6';
			}
			return node.disabled ? '#94a3b8' : (node.color ?? '#2b7dbc');
		};
		const stroke_for = node => node.type === 'device' ? '#697386' : '#17577f';
		const label_fill_for = node => node.type === 'device' ? '#374151' : '#ffffff';

		const node_selection = this.canvas.append('g').selectAll('g.topology-node').data(nodes, node => String(node.id))
			.join('g')
			.attr('class', node => `topology-node ${node.type}`);
		node_selection.append('rect').attr('x', -58).attr('y', -22).attr('width', 116).attr('height', 44).attr('rx', 4)
			.style('fill', fill_for)
			.style('stroke', stroke_for)
			.style('stroke-width', 2)
			.style('stroke-dasharray', node => node.type === 'device' ? '6 4' : null)
			.style('cursor', 'pointer')
			.on('click', (event, node) => this.guard(() => this.selectNode(node)));
		const node_labels = node_selection.append('text').attr('class', 'topology-label').attr('text-anchor', 'middle')
			.style('fill', label_fill_for).style('pointer-events', 'none');
		this.renderWrappedLabel(node_labels, node => node.name, 14);

		const drag = d3.drag()
			.on('start', (event, node) => {
				if (!event.active) simulation.alphaTarget(0.3).restart();
				node.fx = node.x;
				node.fy = node.y;
			})
			.on('drag', (event, node) => {
				node.fx = event.x;
				node.fy = event.y;
			})
			.on('end', (event, node) => {
				if (!event.active) simulation.alphaTarget(0);
			});
		node_selection.call(drag);

		simulation.on('tick', () => {
			links
				.attr('x1', link => link.source.x).attr('y1', link => link.source.y)
				.attr('x2', link => link.target.x).attr('y2', link => link.target.y);
			node_selection.attr('transform', node => `translate(${node.x},${node.y})`);
		});
	}

	renderWrappedLabel(selection, text_fn, font_size) {
		selection.each(function(node) {
			const text = String(text_fn(node) ?? '');
			const words = text.split(/\s+/);
			const el = d3.select(this);
			el.selectAll('tspan').remove();
			let line = '';
			const lines = [];
			words.forEach(word => {
				const candidate = line ? `${line} ${word}` : word;
				if (candidate.length > 16 && line) {
					lines.push(line);
					line = word;
				}
				else {
					line = candidate;
				}
			});
			if (line) {
				lines.push(line);
			}
			const start_y = -((lines.length - 1) * font_size) / 2;
			lines.forEach((line_text, index) => {
				el.append('tspan').attr('x', 0).attr('y', start_y + index * font_size).text(line_text);
			});
		});
	}

	cssColor(color) {
		return color ? `#${color}` : '#97AAB3';
	}

	formatAge(seconds) {
		const days = Math.floor(seconds / 86400);
		const hours = Math.floor((seconds % 86400) / 3600);
		const minutes = Math.floor((seconds % 3600) / 60);
		if (days > 0) return `${days}d ${hours}h`;
		if (hours > 0) return `${hours}h ${minutes}m`;
		return `${minutes}m`;
	}
};
</script>
