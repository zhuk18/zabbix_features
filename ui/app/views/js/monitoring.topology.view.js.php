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

	setDetailsTitle(text) {
		document.getElementById('topology-details-title').textContent = text;
	}

	async init() {
		this.canvas = d3.select('#topology-canvas');
		this.details = document.getElementById('topology-details');
		this.nodes = new Map();
		this.links = [];

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
		await this.loadDevices();
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

	async loadDevices() {
		const {devices = [], relations = []} = await this.request(`topology.devices.get${this.filterQuery()}`);
		this.nodes = new Map(devices.map(node => [String(node.id), node]));
		this.links = relations.map(relation => ({
			source: String(relation.source), target: String(relation.target), type: relation.type,
			source_port: relation.source_port, target_port: relation.target_port
		}));
		this.render();
		const first_host = devices.find(node => node.type === 'host');
		if (first_host) {
			await this.selectNode(first_host);
		}
	}

	async selectNode(node) {
		if (node.type === 'host') {
			const [{problems}, {groups}] = await Promise.all([
				this.request(`topology.problems.get&id=${encodeURIComponent(node.id)}`),
				this.request(`topology.ports.get&id=${encodeURIComponent(node.id)}`)
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
		const problems_section = this.buildProblemsFragment(problems);
		const ports_section = this.buildPortsFragment(groups);
		// §6: a Host only has a Depromote button when IT is the one carrying an explicit
		// topology.identity (i.e. it was manually /promote'd to represent some observed device) —
		// a reporter Host identified purely by its own topology.chassis_id has nothing to depromote.
		const depromote_section = node.identity ? this.depromoteButtonFragment() : '';
		this.details.innerHTML = `<h2>${this.escape(node.name)}</h2>${meta}${problems_section}${ports_section}${depromote_section}`;
		if (node.identity) {
			this.details.querySelector('.topology-depromote-button').addEventListener('click', () => this.guard(async () => {
				await this.request('topology.depromote', {
					method: 'POST', headers: {'Content-Type': 'application/json'},
					body: JSON.stringify({hostid: node.hostid})
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
	// the two optional if_type-driven groups.
	buildPortsFragment(groups) {
		const labels = {
			connected: 'Connected', disconnected: 'Disconnected',
			port_channel: 'Port-channel', management: 'Management'
		};
		const sections = Object.entries(groups).filter(([, ports]) => ports.length).map(([group, ports]) => `
			<section class="topology-group"><h3>${labels[group]}</h3><table><colgroup>
				<col class="topology-col-port"><col class="topology-col-status"><col class="topology-col-connected">
			</colgroup><thead><tr><th>Port</th><th>Status</th><th>Connected to</th></tr></thead><tbody>
			${ports.map(port => `<tr>
				<td class="topology-port-name" title="${this.escape(port.port)}">${this.escape(port.port)}</td>
				<td>${port.status ? `<span class="topology-status-dot topology-status-${this.escape(port.status)}"></span>${this.escape(port.status)}` : ''}</td>
				<td title="${this.escape(port.connected_to ?? '')}">${this.escape(port.connected_to ?? '–')}</td>
			</tr>`).join('')}
			</tbody></table></section>`).join('');
		return sections || `<div class="topology-empty">${this.escape(<?= json_encode(_('No ports reported.')) ?>)}</div>`;
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
				const {hosts = []} = await this.request(`topology.hosts.search&q=${encodeURIComponent(q)}`);
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
			await this.request('topology.promote', {
				method: 'POST', headers: {'Content-Type': 'application/json'},
				body: JSON.stringify({identity: node.chassis_id, hostid: this.promote_selection.hostid})
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
