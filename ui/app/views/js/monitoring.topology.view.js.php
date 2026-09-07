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
		const {devices = []} = await this.request('topology.devices.get');
		this.nodes = new Map(devices.map(node => [String(node.id), node]));
		this.links = [];
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
				this.links.push({source: String(node.id), target: String(neighbor.id)});
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
		const width = element.clientWidth || 800;
		const height = element.clientHeight || 500;
		const nodes = [...this.nodes.values()];
		this.canvas.attr('viewBox', `0 0 ${width} ${height}`).selectAll('*').remove();
		const simulation = d3.forceSimulation(nodes)
			.force('link', d3.forceLink(this.links).id(node => String(node.id)).distance(160))
			.force('charge', d3.forceManyBody().strength(-600))
			.force('center', d3.forceCenter(width / 2, height / 2));
		const links = this.canvas.append('g').selectAll('line').data(this.links).join('line').attr('class', 'topology-link');
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
			links.attr('x1', link => link.source.x).attr('y1', link => link.source.y)
				.attr('x2', link => link.target.x).attr('y2', link => link.target.y);
			node_selection.attr('transform', node => `translate(${node.x},${node.y})`);
		});
	}
};
</script>
