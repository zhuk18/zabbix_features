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
		this.nodes = new Map();
		this.links = [];
		document.getElementById('topology-host-pull').addEventListener('click', async () => {
			await this.request('topology.hosts.pull', {
				method: 'POST', headers: {'Content-Type': 'application/json'}, body: '{}'
			});
			await this.loadDevices();
		});
		await this.loadDevices();
	}

	async loadDevices() {
		const {devices = [], relations = []} = await this.request('topology.devices.get');
		this.nodes = new Map(devices.map(node => [String(node.id), node]));
		this.links = relations.map(relation => ({source: String(relation.source), target: String(relation.target), type: relation.type}));
		this.render();
		const first_device = devices.find(node => node.type === 'device');
		if (first_device) {
			await this.selectNode(first_device);
		}
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
		const hosts = [...this.nodes.values()].filter(candidate => candidate.type === 'host');
		const promotion = node.represented ? '' : `<div class="topology-promote"><select class="topology-host-select" ${hosts.length ? '' : 'disabled'}>${hosts.map(host => `<option value="${this.escape(host.id)}">${this.escape(host.name)}</option>`).join('')}</select><button type="button" class="btn-alt topology-promote-button" ${hosts.length ? '' : 'disabled'}>Promote to host</button></div>`;
		this.details.innerHTML = `<h2>${this.escape(node.name)}</h2>${sections}${promotion}`;
		const button = this.details.querySelector('.topology-promote-button');
		if (button) {
			button.addEventListener('click', async () => {
				const host_id = this.details.querySelector('.topology-host-select').value;
				await this.request('topology.promote', {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({id: node.id, host_id})});
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
		const simulation_links = this.links.map(link => ({...link}));
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
		const simulation = d3.forceSimulation(nodes)
			.force('link', d3.forceLink(simulation_links).id(node => String(node.id)).distance(160))
			.force('charge', d3.forceManyBody().strength(-600))
			.force('center', d3.forceCenter(width / 2, height / 2));
		this.simulation = simulation;
		const links = this.canvas.append('g').selectAll('line').data(simulation_links).join('line')
			.attr('class', 'topology-link')
			.attr('stroke', link => link.type === 'represented_by' ? '#2b7dbc' : '#64748b')
			.attr('stroke-width', 2)
			.attr('stroke-dasharray', link => link.type === 'represented_by' ? '5 3' : null);
		const node_selection = this.canvas.append('g').selectAll('g').data(nodes).join('g')
			.attr('class', node => `topology-node ${node.type}`).on('click', (event, node) => this.selectNode(node));
		node_selection.append('rect').attr('x', -58).attr('y', -22).attr('width', 116).attr('height', 44).attr('rx', 4)
			.style('fill', node => node.type === 'host' ? '#2b7dbc' : '#f3f4f6')
			.style('stroke', node => node.type === 'host' ? '#17577f' : '#697386')
			.style('stroke-dasharray', node => node.type === 'device' ? '6 4' : null);
		node_selection.append('text').attr('class', 'topology-label').attr('text-anchor', 'middle').attr('dy', 4)
			.style('fill', node => node.type === 'host' ? '#ffffff' : '#1f2937')
			.text(node => node.name);
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
