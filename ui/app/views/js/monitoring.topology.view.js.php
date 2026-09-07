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

	// Every mutating action (promote, link, ...) can now be legitimately rejected by the backend (e.g. the
	// §2.1 1:1 represented_by constraint) — without this, request()'s thrown Error was an unhandled promise
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

	async init() {
		this.canvas = d3.select('#topology-canvas');
		this.details = document.getElementById('topology-details');
		this.tray_list = document.getElementById('topology-tray-list');
		this.nodes = new Map();
		this.links = [];
		this.unassigned = {host: new Map(), proxy: new Map()};
		this.link_pick = null;
		document.getElementById('topology-host-pull').addEventListener('click', () => this.guard(async () => {
			await this.request('topology.hosts.pull', {
				method: 'POST', headers: {'Content-Type': 'application/json'}, body: '{}'
			});
			await this.loadDevices();
		}));
		const canvas_element = this.canvas.node();
		canvas_element.addEventListener('dragover', event => {
			if (event.dataTransfer.types.includes('text/topology-node-id')) {
				event.preventDefault();
				canvas_element.classList.add('drop-target');
			}
		});
		canvas_element.addEventListener('dragleave', () => canvas_element.classList.remove('drop-target'));
		canvas_element.addEventListener('drop', event => {
			const node_id = event.dataTransfer.getData('text/topology-node-id');
			const node_type = event.dataTransfer.getData('text/topology-node-type');
			canvas_element.classList.remove('drop-target');
			if (node_id && node_type) {
				event.preventDefault();
				this.dropNodeOnCanvas(node_id, node_type, event);
			}
		});
		await this.loadDevices();
	}

	async loadDevices() {
		const {devices = [], relations = [], unassigned_hosts = [], unassigned_proxies = []} =
			await this.request('topology.devices.get');
		devices.forEach(node => {
			if (node.type === 'host' || node.type === 'proxy') {
				node.linked = true;
			}
		});
		this.nodes = new Map(devices.map(node => [String(node.id), node]));
		this.links = relations.map(relation => ({source: String(relation.source), target: String(relation.target), type: relation.type}));
		this.unassigned = {
			host: new Map(unassigned_hosts.map(node => [String(node.id), node])),
			proxy: new Map(unassigned_proxies.map(node => [String(node.id), node]))
		};
		this.renderTray();
		this.render();
		const first_device = devices.find(node => node.type === 'device');
		if (first_device) {
			await this.selectNode(first_device);
		}
	}

	renderTray() {
		this.tray_list.innerHTML = '';
		const items = [...this.unassigned.host.values(), ...this.unassigned.proxy.values()];
		if (items.length === 0) {
			this.tray_list.textContent = <?= json_encode(_('No unassigned hosts or proxies.')) ?>;
			return;
		}
		items.forEach(node => {
			const item = document.createElement('div');
			item.className = `topology-tray-item topology-tray-item-${node.type}`;
			item.textContent = node.name;
			item.draggable = true;
			item.dataset.nodeId = node.id;
			item.dataset.nodeType = node.type;
			item.addEventListener('dragstart', event => {
				event.dataTransfer.setData('text/topology-node-id', node.id);
				event.dataTransfer.setData('text/topology-node-type', node.type);
				event.dataTransfer.effectAllowed = 'move';
				item.classList.add('dragging');
			});
			item.addEventListener('dragend', () => item.classList.remove('dragging'));
			this.tray_list.appendChild(item);
		});
	}

	dropNodeOnCanvas(node_id, node_type, event) {
		const node = this.unassigned[node_type]?.get(node_id);
		if (!node) {
			return;
		}
		const element = this.canvas.node();
		const bounds = element.getBoundingClientRect();
		const view_box = element.viewBox.baseVal;
		const scale_x = (view_box.width || bounds.width) / bounds.width;
		const scale_y = (view_box.height || bounds.height) / bounds.height;
		node.x = view_box.x + (event.clientX - bounds.left) * scale_x;
		node.y = view_box.y + (event.clientY - bounds.top) * scale_y;
		node.linked = false;
		this.unassigned[node_type].delete(node_id);
		this.nodes.set(node_id, node);
		this.renderTray();
		this.render();
	}

	async selectNode(node) {
		// Proxy nodes reuse the Host problems panel only once they're actually represented_by a Device — a
		// proxy just dragged in from the tray (not yet promoted) has nothing to show here, per spec §7.
		if (node.type === 'host' || (node.type === 'proxy' && node.represented)) {
			const {problems} = await this.request(`topology.problems.get&id=${encodeURIComponent(node.id)}`);
			this.showProblems(node, problems);
			return;
		}
		if (node.type !== 'device') {
			return;
		}

		const [{neighbors}, {groups}] = await Promise.all([
			this.request(`topology.neighbors.get&id=${encodeURIComponent(node.id)}`),
			this.request(`topology.ports.get&id=${encodeURIComponent(node.id)}`)
		]);
		neighbors.forEach(neighbor => {
			this.nodes.set(String(neighbor.id), neighbor);
			// severity is a link-level fact (derived from both endpoint ports' triggers), not a node fact —
			// carry it onto the physical_link edge object itself, refreshing it even if the link already
			// exists from a previous selection, since the underlying trigger state can have changed since.
			let link = this.links.find(candidate =>
				candidate.type === 'physical_link' && candidate.source === String(node.id) && candidate.target === String(neighbor.id));
			if (!link) {
				link = {source: String(node.id), target: String(neighbor.id), type: 'physical_link'};
				this.links.push(link);
			}
			link.severity = neighbor.severity;
			link.severity_name = neighbor.severity_name;
			link.color = neighbor.color;
			link.discovered_via = neighbor.discovered_via;
		});
		this.render();
		this.showPorts(node, groups);
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

	showProblems(node, problems) {
		const list = problems.length
			? `<section class="topology-group"><table><thead><tr><th>Severity</th><th>Problem</th><th>Age</th></tr></thead><tbody>
				${problems.map(problem => `<tr>
					<td><span class="topology-severity-badge" style="background:${this.cssColor(problem.color)}">${this.escape(problem.severity_name)}</span></td>
					<td>${this.escape(problem.name)}</td>
					<td>${this.escape(this.formatAge(problem.age))}</td>
				</tr>`).join('')}
				</tbody></table></section>`
			: `<div class="topology-empty">${this.escape(<?= json_encode(_('No active problems.')) ?>)}</div>`;
		this.details.innerHTML = `<h2>${this.escape(node.name)}</h2>${list}`;
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

	showPorts(node, groups) {
		const labels = {
			connected_lldp: 'Connected via LLDP', connected_mac_only: 'Connected MAC-only',
			disconnected: 'Disconnected', port_channel: 'Port-channel', management: 'Management'
		};
		const port_action = port => port.linked_port_id
			? `<button type="button" class="btn-alt topology-unlink-button" data-port-id="${this.escape(port.id)}" data-linked-port-id="${this.escape(port.linked_port_id)}">${this.escape(<?= json_encode(_('Unlink')) ?>)}</button>`
			: `<button type="button" class="btn-alt topology-link-button" data-port-id="${this.escape(port.id)}" data-port-name="${this.escape(port.port)}">${this.escape(<?= json_encode(_('Link')) ?>)}</button>`;
		const source_classes = {LLDP: 'topology-source-lldp', Manual: 'topology-source-manual'};
		const sections = Object.entries(groups).filter(([, ports]) => ports.length).map(([group, ports]) => `
			<section class="topology-group"><h3>${labels[group]}</h3><table><colgroup>
				<col class="topology-col-port"><col class="topology-col-status"><col class="topology-col-connected">
				<col class="topology-col-source"><col class="topology-col-action">
			</colgroup><thead><tr><th>Port</th><th>Status</th><th>Connected to</th><th>Source</th><th></th></tr></thead><tbody>
			${ports.map(port => `<tr>
				<td class="topology-port-name" title="${this.escape(port.port)}">${this.escape(port.port)}</td>
				<td><span class="topology-status-dot topology-status-${this.escape(port.status)}"></span>${this.escape(port.status)}</td>
				<td title="${this.escape(port.connected_to ?? '')}">${this.escape(port.connected_to ?? '–')}</td>
				<td>${port.source === '-' ? '–' : `<span class="topology-source-badge ${source_classes[port.source] ?? ''}">${this.escape(port.source)}</span>`}</td>
				<td>${port_action(port)}</td>
			</tr>`).join('')}
			</tbody></table></section>`).join('');
		const candidates = [
			...[...this.nodes.values()].filter(candidate => (candidate.type === 'host' || candidate.type === 'proxy') && !candidate.linked),
			...this.unassigned.host.values(),
			...this.unassigned.proxy.values()
		];
		const type_labels = {host: 'Host', proxy: 'Proxy'};
		const promotion = node.represented
			? `<div class="topology-promote"><button type="button" class="btn-alt topology-depromote-button">Depromote</button></div>`
			: `<div class="topology-promote"><select class="topology-host-select" ${candidates.length ? '' : 'disabled'}>${candidates.map(candidate => `<option value="${this.escape(candidate.id)}">${this.escape(type_labels[candidate.type])}: ${this.escape(candidate.name)}</option>`).join('')}</select><button type="button" class="btn-alt topology-promote-button" ${candidates.length ? '' : 'disabled'}>Promote to host</button></div>`;
		this.details.innerHTML = `<h2>${this.escape(node.name)}</h2>${sections}${promotion}`;
		const button = this.details.querySelector('.topology-promote-button');
		if (button) {
			button.addEventListener('click', () => this.guard(async () => {
				const host_id = this.details.querySelector('.topology-host-select').value;
				await this.request('topology.promote', {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({id: node.id, host_id})});
				await this.loadDevices();
			}));
		}
		const depromote_button = this.details.querySelector('.topology-depromote-button');
		if (depromote_button) {
			depromote_button.addEventListener('click', () => this.guard(async () => {
				await this.request('topology.depromote', {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({id: node.id})});
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
					await this.selectNode(node);
				}
				else {
					this.link_pick = {id: port_id, label: `${node.name} / ${link_button.dataset.portName}`};
					this.renderLinkPick();
				}
			}));
		});
		this.details.querySelectorAll('.topology-unlink-button').forEach(unlink_button => {
			unlink_button.addEventListener('click', () => this.guard(async () => {
				await this.request('topology.ports.unlink', {
					method: 'POST', headers: {'Content-Type': 'application/json'},
					body: JSON.stringify({id: unlink_button.dataset.portId, dst_id: unlink_button.dataset.linkedPortId})
				});
				await this.selectNode(node);
			}));
		});
	}

	render() {
		const element = this.canvas.node();
		const bounds = element.getBoundingClientRect();
		const width = bounds.width || 800;
		const height = bounds.height || 500;
		const padding = 70;
		const nodes = [...this.nodes.values()];
		const simulation_links = this.links
			.filter(link => this.nodes.has(link.source) && this.nodes.has(link.target))
			.map(link => ({...link}));
		const columns = Math.max(1, Math.floor((width - 2 * padding) / 140));
		nodes.forEach((node, index) => {
			if (!Number.isFinite(node.x) || !Number.isFinite(node.y)) {
				node.x = padding + (index % columns) * ((width - 2 * padding) / Math.max(1, columns - 1));
				node.y = padding + Math.floor(index / columns) * 80;
				node.vx = 0;
				node.vy = 0;
			}
		});
		this.simulation?.stop();
		this.canvas.attr('viewBox', `0 0 ${width} ${height}`).selectAll('*').remove();
		const defs = this.canvas.append('defs');
		defs.append('pattern')
			.attr('id', 'topology-blind-spot-hatch').attr('width', 8).attr('height', 8)
			.attr('patternUnits', 'userSpaceOnUse').attr('patternTransform', 'rotate(45)')
			.call(pattern => {
				pattern.append('rect').attr('width', 8).attr('height', 8).attr('fill', '#2b7dbc');
				pattern.append('rect').attr('width', 4).attr('height', 8).attr('fill', '#f59e0b');
			});
		const simulation = d3.forceSimulation(nodes)
			.force('link', d3.forceLink(simulation_links).id(node => String(node.id)).distance(160))
			.force('charge', d3.forceManyBody().strength(-600))
			.force('center', d3.forceCenter(width / 2, height / 2));
		this.simulation = simulation;
		// Two independent visual channels on physical_link, deliberately kept apart so they can't collide:
		// dash pattern = provenance (dashed = "manual", not yet discovery-confirmed — same dashed/solid
		// language already used for represented_by/monitored_by and for an unassociated Device's outline),
		// color + stroke-width = severity (an active trigger on either endpoint port), which is orthogonal —
		// a manually-declared link can carry an active-trigger color just as easily as an LLDP one.
		const links = this.canvas.append('g').selectAll('line').data(simulation_links).join('line')
			.attr('class', 'topology-link')
			.attr('stroke', link => {
				if (link.type === 'represented_by') {
					return '#2b7dbc';
				}
				if (link.type === 'monitored_by') {
					return '#6b46c1';
				}
				return (link.type === 'physical_link' && link.color) ? this.cssColor(link.color) : '#64748b';
			})
			.attr('stroke-width', link => (link.type === 'physical_link' && link.severity !== null && link.severity !== undefined) ? 4 : 2)
			.attr('stroke-dasharray', link => {
				// represented_by and monitored_by both connect monitoring-related nodes and are easy to
				// mis-read as the same kind of relationship — a distinct dash pattern per type (not just
				// color) keeps them apart even for a colorblind viewer, without touching the dashed/solid
				// channel that already means "physical_link provenance" / "Device association status".
				if (link.type === 'represented_by') {
					return '6 3';
				}
				if (link.type === 'monitored_by') {
					return '2 2';
				}
				return (link.type === 'physical_link' && link.discovered_via === 'manual') ? '5 3' : null;
			});
		links.append('title').text(link => {
			if (link.type === 'physical_link') {
				const provenance = link.discovered_via === 'manual'
					? <?= json_encode(_('manually declared')) ?>
					: <?= json_encode(_('LLDP-discovered')) ?>;
				const severity = link.severity_name
					? <?= json_encode(_('Active problem: ')) ?> + link.severity_name
					: <?= json_encode(_('No active trigger on this link.')) ?>;
				return `${provenance}. ${severity}`;
			}
			return '';
		});
		// §7: disabled overrides every other host channel — not polled, so severity/blind-spot/maintenance are
		// all meaningless for it right now. Muted gray is a *third* state, distinct from both "severity: ok"
		// (monitored, currently fine) and blind-spot (monitored, but this proxy can't currently confirm it).
		const fill_for = node => {
			if (node.type === 'host') {
				if (node.disabled) {
					return '#cbd2d9';
				}
				if (node.blind_spot) {
					return 'url(#topology-blind-spot-hatch)';
				}
				return node.severity !== null && node.severity !== undefined ? this.cssColor(node.color) : '#2b7dbc';
			}
			if (node.type === 'proxy') {
				if (node.unreachable) {
					return '#9ca3af';
				}
				return node.severity !== null && node.severity !== undefined ? this.cssColor(node.color) : '#6b46c1';
			}
			return '#f3f4f6';
		};
		const stroke_for = node => {
			if (node.type === 'host') {
				if (node.disabled) {
					return '#98a2ad';
				}
				return node.blind_spot ? '#f59e0b' : '#17577f';
			}
			if (node.type === 'proxy') {
				return node.unreachable ? '#ef4444' : '#4c1d95';
			}
			return '#697386';
		};
		const node_selection = this.canvas.append('g').selectAll('g').data(nodes).join('g')
			.attr('class', node => `topology-node ${node.type}`).on('click', (event, node) => this.selectNode(node));
		node_selection.append('rect').attr('x', -58).attr('y', -22).attr('width', 116).attr('height', 44).attr('rx', 4)
			.style('fill', fill_for)
			.style('stroke', stroke_for)
			.style('stroke-width', node => (node.type === 'proxy' && node.unreachable) ? 3 : 2)
			.style('stroke-dasharray', node => {
				if (node.type === 'device') {
					return '6 4';
				}
				return (node.type === 'proxy' && node.unreachable) ? '4 3' : null;
			});
		node_selection.append('text').attr('class', 'topology-label').attr('text-anchor', 'middle').attr('dy', 4)
			.style('fill', node => {
				if (node.type === 'host' && node.disabled) {
					return '#55606b';
				}
				return (node.type === 'host' || node.type === 'proxy') ? '#ffffff' : '#1f2937';
			})
			.text(node => node.name);
		const proxy_badges = node_selection.filter(node => node.type === 'proxy');
		proxy_badges.append('circle').attr('cx', 50).attr('cy', -18).attr('r', 10)
			.style('fill', '#ffffff').style('stroke', node => node.unreachable ? '#ef4444' : '#4c1d95').style('stroke-width', 2);
		proxy_badges.append('text').attr('x', 50).attr('y', -14).attr('text-anchor', 'middle')
			.style('font-size', '11px').style('font-weight', 'bold').style('fill', node => node.unreachable ? '#ef4444' : '#4c1d95')
			.text('P');
		// blind-spot ("?", amber, top-right) and maintenance ("M", slate, top-left) are deliberately opposite
		// corners with unrelated colors — a host can be both at once (behind an unreachable proxy AND in a
		// maintenance window) and the two badges must read as different concerns, never the same warning twice.
		const blind_spot_badges = node_selection.filter(node => node.type === 'host' && node.blind_spot);
		blind_spot_badges.append('circle').attr('cx', 50).attr('cy', -18).attr('r', 10)
			.style('fill', '#f59e0b').style('stroke', '#7c2d12').style('stroke-width', 2);
		blind_spot_badges.append('text').attr('x', 50).attr('y', -14).attr('text-anchor', 'middle')
			.style('font-size', '11px').style('font-weight', 'bold').style('fill', '#ffffff')
			.text('?');
		const maintenance_badges = node_selection.filter(node => node.type === 'host' && node.maintenance && !node.disabled);
		maintenance_badges.append('circle').attr('cx', -50).attr('cy', -18).attr('r', 10)
			.style('fill', '#475569').style('stroke', '#1e293b').style('stroke-width', 2);
		maintenance_badges.append('text').attr('x', -50).attr('y', -14).attr('text-anchor', 'middle')
			.style('font-size', '11px').style('font-weight', 'bold').style('fill', '#ffffff')
			.text('M');
		node_selection.append('title').text(node => {
			if (node.type === 'proxy' && node.unreachable) {
				return <?= json_encode(_('Proxy unreachable')) ?>;
			}
			if (node.type === 'host' && node.disabled) {
				return <?= json_encode(_('Host is disabled — not polled, no severity to show.')) ?>;
			}
			const notes = [];
			if (node.type === 'host' && node.blind_spot) {
				notes.push(<?= json_encode(_('Monitoring blind spot: the proxy for this host is unreachable, this does not mean the host itself is down.')) ?>);
			}
			if (node.type === 'host' && node.maintenance) {
				notes.push(<?= json_encode(_('In maintenance — still monitored, notifications suppressed.')) ?>);
			}
			if ((node.type === 'host' || node.type === 'proxy') && node.severity_name) {
				notes.push(`${<?= json_encode(_('Severity: ')) ?>}${node.severity_name}`);
			}
			return notes.length ? `${node.name} — ${notes.join(' ')}` : node.name;
		});
		simulation.on('tick', () => {
			nodes.forEach(node => {
				if (!Number.isFinite(node.x) || !Number.isFinite(node.y)) {
					node.x = width / 2;
					node.y = height / 2;
					node.vx = 0;
					node.vy = 0;
				}
				node.x = Math.max(padding, Math.min(width - padding, node.x));
				node.y = Math.max(padding, Math.min(height - padding, node.y));
			});
			links.attr('x1', link => link.source.x).attr('y1', link => link.source.y)
				.attr('x2', link => link.target.x).attr('y2', link => link.target.y);
			node_selection.attr('transform', node => `translate(${node.x},${node.y})`);
		});
	}
};
</script>
