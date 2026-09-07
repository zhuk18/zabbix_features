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
		document.getElementById('topology-host-pull').addEventListener('click', async () => {
			await this.request('topology.hosts.pull', {
				method: 'POST', headers: {'Content-Type': 'application/json'}, body: '{}'
			});
			await this.loadDevices();
		});
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
		if (node.type !== 'device') {
			return;
		}

		const [{neighbors}, {groups}] = await Promise.all([
			this.request(`topology.neighbors.get&id=${encodeURIComponent(node.id)}`),
			this.request(`topology.interfaces.get&id=${encodeURIComponent(node.id)}`)
		]);
		neighbors.forEach(neighbor => {
			this.nodes.set(String(neighbor.id), neighbor);
			if (!this.links.some(link => link.source === String(node.id) && link.target === String(neighbor.id))) {
				this.links.push({source: String(node.id), target: String(neighbor.id), type: 'physical_link'});
			}
		});
		this.render();
		this.showInterfaces(node, groups);
	}

	showInterfaces(node, groups) {
		const labels = {
			connected_lldp: 'Connected via LLDP', connected_mac_only: 'Connected MAC-only',
			disconnected: 'Disconnected', port_channel: 'Port-channel', management: 'Management'
		};
		const sections = Object.entries(groups).filter(([, ports]) => ports.length).map(([group, ports]) => `
			<section class="topology-group"><h3>${labels[group]}</h3><table><thead><tr><th>Port</th><th>Status</th><th>Connected to</th><th>Source</th></tr></thead><tbody>
			${ports.map(port => `<tr><td>${this.escape(port.port)}</td><td>${this.escape(port.status)}</td><td>${this.escape(port.connected_to ?? '-')}</td><td class="topology-source">${this.escape(port.source)}</td></tr>`).join('')}
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
			button.addEventListener('click', async () => {
				const host_id = this.details.querySelector('.topology-host-select').value;
				await this.request('topology.promote', {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({id: node.id, host_id})});
				await this.loadDevices();
			});
		}
		const depromote_button = this.details.querySelector('.topology-depromote-button');
		if (depromote_button) {
			depromote_button.addEventListener('click', async () => {
				await this.request('topology.depromote', {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({id: node.id})});
				await this.loadDevices();
			});
		}
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
		const links = this.canvas.append('g').selectAll('line').data(simulation_links).join('line')
			.attr('class', 'topology-link')
			.attr('stroke', link => link.type === 'represented_by' ? '#2b7dbc' : (link.type === 'monitored_by' ? '#6b46c1' : '#64748b'))
			.attr('stroke-width', 2)
			.attr('stroke-dasharray', link => (link.type === 'represented_by' || link.type === 'monitored_by') ? '5 3' : null);
		const fill_for = node => {
			if (node.type === 'host') {
				return node.blind_spot ? 'url(#topology-blind-spot-hatch)' : '#2b7dbc';
			}
			if (node.type === 'proxy') {
				return node.unreachable ? '#9ca3af' : '#6b46c1';
			}
			return '#f3f4f6';
		};
		const stroke_for = node => {
			if (node.type === 'host') {
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
			.style('fill', node => (node.type === 'host' || node.type === 'proxy') ? '#ffffff' : '#1f2937')
			.text(node => node.name);
		const proxy_badges = node_selection.filter(node => node.type === 'proxy');
		proxy_badges.append('circle').attr('cx', 50).attr('cy', -18).attr('r', 10)
			.style('fill', '#ffffff').style('stroke', node => node.unreachable ? '#ef4444' : '#4c1d95').style('stroke-width', 2);
		proxy_badges.append('text').attr('x', 50).attr('y', -14).attr('text-anchor', 'middle')
			.style('font-size', '11px').style('font-weight', 'bold').style('fill', node => node.unreachable ? '#ef4444' : '#4c1d95')
			.text('P');
		const blind_spot_badges = node_selection.filter(node => node.type === 'host' && node.blind_spot);
		blind_spot_badges.append('circle').attr('cx', 50).attr('cy', -18).attr('r', 10)
			.style('fill', '#f59e0b').style('stroke', '#7c2d12').style('stroke-width', 2);
		blind_spot_badges.append('text').attr('x', 50).attr('y', -14).attr('text-anchor', 'middle')
			.style('font-size', '11px').style('font-weight', 'bold').style('fill', '#ffffff')
			.text('?');
		node_selection.append('title').text(node => {
			if (node.type === 'proxy' && node.unreachable) {
				return <?= json_encode(_('Proxy unreachable')) ?>;
			}
			if (node.type === 'host' && node.blind_spot) {
				return <?= json_encode(_('Monitoring blind spot: the proxy for this host is unreachable, this does not mean the host itself is down.')) ?>;
			}
			return node.name;
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
