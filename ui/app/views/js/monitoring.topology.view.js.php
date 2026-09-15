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
		this.tray_list = document.getElementById('topology-tray-list');
		this.nodes = new Map();
		this.links = [];
		this.unassigned = {host: new Map(), proxy: new Map()};
		this.link_pick = null;
		// Automatic (LLDP) vs manual physical_link display — purely a client-side render filter,
		// not a data-scope query like groupids/hostid above: the discovered_via a link needs is
		// already in what loadDevices() fetched, so toggling it just re-renders, no server round
		// trip. represented_by/monitored_by aren't physical wiring and are never affected by this.
		this.link_filter = 'all';
		document.getElementById('topology-link-filter').addEventListener('change', event => {
			this.link_filter = event.target.value;
			this.render();
		});
		// Device details panel: collapsible so a wide canvas/graph gets the room back when the
		// panel isn't needed — purely a CSS class toggle (topology-panel-collapsed on the grid
		// container shrinks the panel's column), nothing about the loaded data changes.
		const panel_toggle = document.getElementById('topology-panel-toggle');
		const workspace = document.querySelector('.topology-workspace');
		panel_toggle.addEventListener('click', () => {
			const collapsed = workspace.classList.toggle('topology-panel-collapsed');
			panel_toggle.textContent = collapsed ? '»' : '«';
			panel_toggle.title = collapsed ? <?= json_encode(_('Expand')) ?> : <?= json_encode(_('Collapse')) ?>;
			// The CSS transition resizes the canvas column; re-render once it's settled so the
			// graph actually uses the space instead of sitting at its old (now stale) viewBox
			// until some unrelated interaction happens to trigger the next render().
			setTimeout(() => this.render(), 160);
		});
		document.getElementById('topology-host-pull').addEventListener('click', () => this.guard(async () => {
			await this.request('topology.hosts.pull', {
				method: 'POST', headers: {'Content-Type': 'application/json'}, body: '{}'
			});
			await this.loadDevices();
		}));
		this.ingest_status_element = document.getElementById('topology-ingest-status');
		document.getElementById('topology-ingest-run').addEventListener('click', () => this.guard(async () => {
			await this.runIngest();
		}));
		// A run may already be in progress from a previous page load (button click before a reload) or
		// from the CLI — reflect that on load instead of only ever noticing it after this tab's own click.
		this.pollIngestStatus({ignore_idle: true});
		// Hostgroup / host+hops scope: read on "Apply", not on every multiselect change — a
		// group multiselect can see several add/remove events in a row while the user is still
		// picking, and firing a request per keystroke there would be wasteful.
		document.getElementById('topology-filter-apply').addEventListener('click', () => this.guard(async () => {
			await this.loadDevices();
		}));
		document.getElementById('topology-filter-clear').addEventListener('click', () => this.guard(async () => {
			jQuery('#groupids_').multiSelect('clean');
			jQuery('#hostid').multiSelect('clean');
			document.getElementById('topology-filter-hops').value = '1';
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

	// "Run discovery ingest" (spec §7): starts POST topology.ingest.run, then polls
	// topology.ingest.status until it's no longer "running". Deliberately not blocking anything else —
	// no await on the poll loop from the caller, and every other control/handler stays untouched while
	// this runs. this.ingest_polling guards against a second poll loop starting (e.g. a second click,
	// or the on-load check below racing a user click) since the backend's own lock already means a
	// second *run* would just be rejected/reflected as "running" — but two independent poll loops for
	// the one run would still double up the eventual toast, which this avoids.
	async runIngest() {
		if (this.ingest_polling) {
			return;
		}
		await this.request('topology.ingest.run', {
			method: 'POST', headers: {'Content-Type': 'application/json'}, body: '{}'
		});
		// The backend always attempts a spawn and reports {status: 'started'} regardless of whether a
		// run was already in progress (a redundant spawn just loses the lock race and exits immediately,
		// §6) — either way, polling topology.ingest.status is what actually reflects ground truth.
		this.pollIngestStatus();
	}

	async pollIngestStatus({ignore_idle = false} = {}) {
		if (this.ingest_polling) {
			return;
		}

		const poll = async () => {
			let status;
			try {
				status = await this.request('topology.ingest.status');
			}
			catch (error) {
				this.ingest_polling = false;
				this.ingest_status_element.textContent = '';
				alert(error.message);
				return;
			}

			if (status.status === 'running') {
				this.ingest_polling = true;
				this.ingest_status_element.textContent = <?= json_encode(_('Ingest running…')) ?>;
				setTimeout(poll, 2000);
				return;
			}

			const was_polling = this.ingest_polling;
			this.ingest_polling = false;
			this.ingest_status_element.textContent = '';

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

			if (status.status === 'done') {
				const summary = status.summary ?? {};
				alert(<?= json_encode(_('Discovery ingest finished.')) ?> +
					`\n${<?= json_encode(_('Devices created')) ?>}: ${summary.devices_created ?? 0}` +
					`\n${<?= json_encode(_('Devices updated')) ?>}: ${summary.devices_updated ?? 0}` +
					`\n${<?= json_encode(_('Ports created')) ?>}: ${summary.ports_created ?? 0}` +
					`\n${<?= json_encode(_('Links created')) ?>}: ${summary.links_created ?? 0}`);
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
	filterQuery() {
		const hostid = jQuery('#hostid').multiSelect('getData')[0]?.id;
		if (hostid) {
			const hops = document.getElementById('topology-filter-hops').value;
			return `&hostid=${encodeURIComponent(hostid)}&hops=${encodeURIComponent(hops)}`;
		}
		const groupids = jQuery('#groupids_').multiSelect('getData').map(item => item.id);
		return groupids.map(id => `&groupids[]=${encodeURIComponent(id)}`).join('');
	}

	async loadDevices() {
		const {devices = [], relations = [], unassigned_hosts = [], unassigned_proxies = []} =
			await this.request(`topology.devices.get${this.filterQuery()}`);
		devices.forEach(node => {
			if (node.type === 'host' || node.type === 'proxy') {
				node.linked = true;
			}
		});
		this.nodes = new Map(devices.map(node => [String(node.id), node]));
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
			stale: relation.stale
		}));
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
		// Host/Proxy: one combined panel, always Problems, plus a Device section when this node has an
		// active represented_by (node.device, set in render() from the same represented_by edges that
		// used to drive the old split-node merge — see render()'s device_of_target map). A represented
		// Device has no on-screen position of its own (§7), so its /neighbors + /ports are fetched here,
		// keyed off the clicked Host/Proxy, exactly like a standalone Device click below would.
		if (node.type === 'host' || node.type === 'proxy') {
			const {problems} = await this.request(`topology.problems.get&id=${encodeURIComponent(node.id)}`);
			let groups = null;
			if (node.device) {
				const [neighbor_groups] = await Promise.all([
					this.request(`topology.ports.get&id=${encodeURIComponent(node.device.id)}`),
					this.mergeNeighbors(node.device)
				]);
				groups = neighbor_groups.groups;
				this.render();
			}
			this.showPanel(node, problems, groups);
			return;
		}
		if (node.type !== 'device') {
			return;
		}

		const [{groups}] = await Promise.all([
			this.request(`topology.ports.get&id=${encodeURIComponent(node.id)}`),
			this.mergeNeighbors(node)
		]);
		this.render();
		this.showPorts(node, groups);
	}

	// Fetches /neighbors for `device` and folds each one into this.nodes/this.links — shared by a
	// standalone Device click and a Host/Proxy click whose represented Device's neighbors need the
	// same treatment. Caller is responsible for this.render() afterwards (both callers already need to
	// do other awaited work first, so this doesn't render on their behalf).
	async mergeNeighbors(device) {
		const {neighbors} = await this.request(`topology.neighbors.get&id=${encodeURIComponent(device.id)}`);
		neighbors.forEach(neighbor => {
			this.nodes.set(String(neighbor.id), neighbor);
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
				link.discovered_via === 'manual' ? <?= json_encode(_('Manual')) ?> : 'LLDP']);
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
		}
		const table = `<section class="topology-group"><table><tbody>${rows.map(([label, value, raw]) =>
			`<tr><th>${this.escape(label)}</th><td>${raw ? value : this.escape(value)}</td></tr>`).join('')}</tbody></table></section>`;
		// Only physical_link is a real, user/LLDP-declared topo_edges row a person can remove —
		// monitored_by comes from pullHosts() syncing actual Zabbix host config and would just
		// reappear on the next pull, so there's nothing meaningful to "delete" there.
		const delete_button = link.type === 'physical_link' && link.source_port_id && link.target_port_id
			? `<div class="topology-promote"><button type="button" class="btn-alt topology-delete-link-button">` +
				`${this.escape(<?= json_encode(_('Delete link')) ?>)}</button></div>`
			: '';
		this.details.innerHTML = `<h2>${this.escape(link.source.name)} ↔ ${this.escape(link.target.name)}</h2>${table}${delete_button}`;
		const button = this.details.querySelector('.topology-delete-link-button');
		if (button) {
			button.addEventListener('click', () => this.guard(async () => {
				await this.request('topology.ports.unlink', {
					method: 'POST', headers: {'Content-Type': 'application/json'},
					body: JSON.stringify({id: link.source_port_id, dst_id: link.target_port_id})
				});
				// The link itself is gone, not just refreshable in place — both endpoints' data
				// changes, so a full reload (same as "Apply filter"/"Show all") rather than a
				// targeted re-render.
				await this.loadDevices();
			}));
		}
	}

	// Same colored-dot convention showPorts() already uses for a port's own oper_status.
	statusCell(status, status_labels) {
		return `<span class="topology-status-dot topology-status-${this.escape(status)}"></span>` +
			this.escape(status_labels[status] ?? status);
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

	// Node/device names here are typically one hyphenated word (e.g. "core-switch-48") rather than prose, so
	// wrapping on spaces alone (as CSS text-wrap would) wouldn't help — this also breaks on '-'. Greedy
	// last-fit: the break point is the latest hyphen/space at or before maxChars, so the first line never
	// overflows; the second line is left as-is even if it runs a little over rather than ellipsis-truncating
	// it — for names like "access-switch-1" losing the trailing "-1" to an ellipsis would destroy the one
	// thing that makes the label useful, which is worse than a few pixels of visual overflow. SVG text has no
	// native wrapping, so this returns plain line strings; the caller renders each as its own <tspan>.
	wrapLabel(text, maxChars) {
		text = String(text ?? '');
		if (text.length <= maxChars) {
			return [text];
		}
		let break_at = -1;
		for (let i = Math.min(maxChars, text.length - 1); i >= 1; i--) {
			if ('- '.includes(text[i])) {
				break_at = i;
				break;
			}
		}
		if (break_at === -1) {
			break_at = maxChars;
		}
		const cut_after_hyphen = text[break_at] === '-';
		const first = text.slice(0, break_at + (cut_after_hyphen ? 1 : 0)).trim();
		const second = text.slice(break_at + (cut_after_hyphen ? 1 : 0)).trim();
		return [first, second];
	}

	// Renders `textFn(node)` as one or two vertically-centered <tspan> lines (wrapped via wrapLabel()) instead
	// of a single line that can run past the node box's edge — every <tspan> repeats `x` explicitly, since a
	// tspan with no x of its own continues from the end of the previous line's text, not back at the start.
	renderWrappedLabel(selection, textFn, maxChars, x = 0) {
		selection.each((node, index, elements) => {
			const lines = this.wrapLabel(textFn(node), maxChars);
			const text = d3.select(elements[index]);
			lines.forEach((line, line_index) => {
				text.append('tspan').attr('x', x).attr('dy', line_index === 0 ? (lines.length > 1 ? -2 : 4) : 12).text(line);
			});
		});
	}

	// Problems section fragment only — no title, no this.details assignment. Used both standalone
	// (never happens today, since every Host/Proxy click always includes a Problems section — see
	// showPanel()) and, going forward, exclusively via showPanel(); kept as its own method rather than
	// inlined there so the table markup stays next to formatAge()/cssColor(), same as before.
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

	// Host/Proxy side panel (§7): one panel, always a Problems section; a Device section too when
	// `node.device` is set (this Host/Proxy has an active represented_by — see render()'s
	// device_of_target map, which populates node.device). `groups` is the represented Device's /ports
	// response, or null when there's no represented Device at all — in which case the Device section is
	// simply omitted, not rendered empty.
	showPanel(node, problems, groups) {
		this.setDetailsTitle(<?= json_encode(_('Device details')) ?>);
		const device_section = groups !== null
			? `<section class="topology-group"><h3>${this.escape(<?= json_encode(_('Device')) ?>)}</h3>${this.buildPortsFragment(node.device, groups)}</section>`
			: '';
		this.details.innerHTML = `<h2>${this.escape(node.name)}</h2>${this.buildProblemsFragment(problems)}${device_section}`;
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
	// showPanel() (Device section inside a Host/Proxy's combined panel, §7) — `device` is always the
	// actual Device node either way, never the Host/Proxy that might be showing it.
	buildPortsFragment(device, groups) {
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
		const promotion = device.represented
			? `<div class="topology-promote"><button type="button" class="btn-alt topology-depromote-button">Depromote</button></div>`
			: `<div class="topology-promote"><select class="topology-host-select" ${candidates.length ? '' : 'disabled'}>${candidates.map(candidate => `<option value="${this.escape(candidate.id)}">${this.escape(type_labels[candidate.type])}: ${this.escape(candidate.name)}</option>`).join('')}</select><button type="button" class="btn-alt topology-promote-button" ${candidates.length ? '' : 'disabled'}>Promote to host</button><button type="button" class="btn-alt topology-create-host-button">+ Create host</button></div>`;
		return `${sections}${promotion}`;
	}

	// Wires the buttons buildPortsFragment() just rendered into this.details. `reselect_node` is what a
	// Link/Unlink completion re-selects afterwards: `device` itself for the standalone panel (showPorts()),
	// or the owning Host/Proxy node for the combined panel (showPanel()) — re-selecting the wrong one would
	// swap the whole panel to the standalone Device view instead of refreshing the Device section in place.
	wirePortsFragment(device, reselect_node) {
		const button = this.details.querySelector('.topology-promote-button');
		if (button) {
			button.addEventListener('click', () => this.guard(async () => {
				const host_id = this.details.querySelector('.topology-host-select').value;
				await this.request('topology.promote', {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({id: device.id, host_id})});
				await this.loadDevices();
			}));
		}
		const create_host_button = this.details.querySelector('.topology-create-host-button');
		if (create_host_button) {
			create_host_button.addEventListener('click', () => this.guard(async () => {
				await this.createHostForDevice(device);
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
					this.link_pick = {id: port_id, label: `${device.name} / ${link_button.dataset.portName}`};
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
				await this.selectNode(reselect_node);
			}));
		});
	}

	// Side panel — standalone Device (no represented_by): unchanged by §7's rewrite, since this only
	// ever applies to a Device with no on-screen Host/Proxy counterpart to begin with.
	showPorts(node, groups) {
		this.setDetailsTitle(<?= json_encode(_('Device details')) ?>);
		this.details.innerHTML = `<h2>${this.escape(node.name)}</h2>${this.buildPortsFragment(node, groups)}`;
		this.wirePortsFragment(node, node);
	}

	// "+ Create host" on an unrepresented device: opens Zabbix's own host-creation popup prefilled with the
	// device's LLDP name, then tries to promote the result automatically. The create response carries no hostid
	// (see CControllerHostCreate), so the only way back to it is a pull + a name lookup — which only works if
	// the name wasn't changed in the popup. If it doesn't match, we don't guess: the new host still lands in the
	// unassigned tray/select for a manual Promote, same as pulling it in via the existing "Pull hosts" button.
	async createHostForDevice(node) {
		const overlay = ZABBIX.PopupManager.open('host.edit', {host: node.name});
		if (!overlay) {
			return;
		}

		await new Promise(resolve => {
			overlay.$dialogue[0].addEventListener('dialogue.submit', resolve, {once: true});
		});

		await this.request('topology.hosts.pull', {
			method: 'POST', headers: {'Content-Type': 'application/json'}, body: '{}'
		});

		const {unassigned_hosts = []} = await this.request('topology.devices.get');
		const match = unassigned_hosts.find(host => host.name === node.name);

		if (match) {
			await this.request('topology.promote', {
				method: 'POST', headers: {'Content-Type': 'application/json'},
				body: JSON.stringify({id: node.id, host_id: match.id})
			});
		}

		await this.loadDevices();
	}

	render() {
		const element = this.canvas.node();
		const bounds = element.getBoundingClientRect();
		const width = bounds.width || 800;
		const height = bounds.height || 500;
		const padding = 70;

		// §7: a Device with an active represented_by has no on-screen position of its own at all — only its
		// Host/Proxy shows, styled exactly as any other Host/Proxy would be. This still needs to know, for
		// any given Host/Proxy node, whether it has an associated Device (and which one, for showPanel()'s
		// Device section — see selectNode()) and needs to keep the Device itself out of the simulation
		// graph, same as the earlier split-node design did — only the "draw two halves" part is dropped, not
		// the "the Device gets no simulation node of its own" part. Only counts when both sides are actually
		// loaded (a promoted Host/Proxy still sitting in the tray, not yet dragged in, has nothing to attach
		// to yet — same "both endpoints must be on the canvas" rule already used for every other link type
		// here).
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
		// 1:1 on represented_by (backend-enforced) guarantees at most one represented Device per Host/Proxy —
		// no ambiguity about which Device a node's .device points at.
		const nodes = [...this.nodes.values()].filter(node => !(node.type === 'device' && target_id_of_device.has(String(node.id))));
		const rendered_ids = new Set(nodes.map(node => String(node.id)));
		const resolve_display_id = id => target_id_of_device.get(id) ?? id;
		const simulation_links = this.links
			.filter(link => link.type !== 'represented_by')
			// Automatic/manual toggle: only ever hides physical_link edges — monitored_by isn't
			// physical wiring and passes through regardless of the selector's current value.
			.filter(link => this.link_filter === 'all' || link.type !== 'physical_link' ||
				link.discovered_via === this.link_filter)
			.map(link => ({
				...link,
				// A physical_link endpoint that's a represented Device (off-graph, per above) resolves to its
				// Host/Proxy's id instead — same "attach to whatever's actually on screen" principle, just
				// pointing straight at the plain Host/Proxy node now instead of a merged node's bounding box.
				source: resolve_display_id(link.source),
				target: resolve_display_id(link.target)
			}))
			.filter(link => rendered_ids.has(link.source) && rendered_ids.has(link.target));
		const columns = Math.max(1, Math.floor((width - 2 * padding) / 140));
		nodes.forEach((node, index) => {
			if (!Number.isFinite(node.x) || !Number.isFinite(node.y)) {
				node.x = padding + (index % columns) * ((width - 2 * padding) / Math.max(1, columns - 1));
				node.y = padding + Math.floor(index / columns) * 80;
				node.vx = 0;
				node.vy = 0;
			}
		});
		// render() reruns on every click (selectNode() always calls it, even when nothing new was
		// discovered) as well as on real structural changes, and it always rebuilds the
		// simulation from these same node objects — which already carry x/y/vx/vy from wherever
		// they last settled, or from a manual drag. What it does NOT already carry over on its
		// own is alpha: a plain `d3.forceSimulation(nodes)` starts at the default alpha=1 (full
		// force strength) regardless, so every re-render was re-throwing the WHOLE graph through
		// a full-energy settle even when only one node/edge actually changed — every other node
		// visibly wobbling on every single click, not just the ones near it. Reheating gently
		// (low alpha) instead of cold-starting fixes that: existing positions get nudged to
		// accommodate whatever's new, not thrown back into a fresh layout. Only the very first
		// render (nothing to preserve yet) still wants the full alpha=1 settle.
		const is_first_render = !this.simulation;
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
			.alpha(is_first_render ? 1 : 0.3)
			.force('link', d3.forceLink(simulation_links).id(node => String(node.id)).distance(160))
			.force('charge', d3.forceManyBody().strength(-600))
			.force('center', d3.forceCenter(width / 2, height / 2));
		this.simulation = simulation;
		// Two independent visual channels on physical_link, deliberately kept apart so they can't collide:
		// dash pattern = provenance (dashed = "manual", not yet discovery-confirmed — same dashed/solid
		// language already used for represented_by/monitored_by and for an unassociated Device's outline),
		// color + stroke-width = connectivity — an *unexpected* down (admin says up, oper says down) on
		// either endpoint port, which is orthogonal — a manually-declared link can be down just as easily
		// as an LLDP one. 'disabled' (admin turned the port off on purpose, see portStatus() server-side)
		// deliberately does NOT turn the link red — that's an intentional state, not an alarm.
		const links = this.canvas.append('g').selectAll('line').data(simulation_links).join('line')
			.attr('class', 'topology-link')
			.style('cursor', 'pointer')
			.style('pointer-events', 'stroke')
			.on('click', (event, link) => this.selectLink(link))
			.attr('stroke', link => {
				if (link.type === 'represented_by') {
					return '#2b7dbc';
				}
				if (link.type === 'monitored_by') {
					return '#6b46c1';
				}
				const down = link.source_status === 'down' || link.target_status === 'down';
				return (link.type === 'physical_link' && down) ? '#dc2626' : '#64748b';
			})
			.attr('stroke-width', link => {
				const down = link.source_status === 'down' || link.target_status === 'down';
				return (link.type === 'physical_link' && down) ? 4 : 2;
			})
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
			})
			// §7 staleness indicator: a THIRD independent channel, deliberately on neither the dash
			// pattern (provenance) nor the stroke color/width (connectivity) above — a stale manual
			// link is still dashed, just faded; a stale link with a down/red endpoint is still red,
			// just faded. Never collapse this into either of the other two.
			.style('opacity', link => (link.type === 'physical_link' && link.stale) ? 0.45 : 1);
		links.append('title').text(link => {
			if (link.type === 'physical_link') {
				const provenance = link.discovered_via === 'manual'
					? <?= json_encode(_('manually declared')) ?>
					: <?= json_encode(_('LLDP-discovered')) ?>;
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
				const stale_note = link.stale ? ` ${<?= json_encode(_('Not recently reconfirmed.')) ?>}` : '';
				return `${provenance}.${sides.length ? ' ' + sides.join(', ') : ''}${stale_note}`;
			}
			return '';
		});
		// §7: disabled overrides every other host channel — not polled, so severity/blind-spot/maintenance are
		// all meaningless for it right now. Muted gray is a *third* state, distinct from both "severity: ok"
		// (monitored, currently fine) and blind-spot (monitored, but this proxy can't currently confirm it).
		// A node with an associated Device (node.device set) is styled exactly the same as one without —
		// nothing here reads .device at all, per §7's "completely unchanged by whether it happens to have an
		// associated Device."
		const fill_for = subject => {
			if (subject.type === 'host') {
				if (subject.disabled) {
					return '#cbd2d9';
				}
				if (subject.blind_spot) {
					return 'url(#topology-blind-spot-hatch)';
				}
				return subject.severity !== null && subject.severity !== undefined ? this.cssColor(subject.color) : '#2b7dbc';
			}
			if (subject.type === 'proxy') {
				if (subject.unreachable) {
					return '#9ca3af';
				}
				return subject.severity !== null && subject.severity !== undefined ? this.cssColor(subject.color) : '#6b46c1';
			}
			return '#f3f4f6';
		};
		const stroke_for = subject => {
			if (subject.type === 'host') {
				if (subject.disabled) {
					return '#98a2ad';
				}
				return subject.blind_spot ? '#f59e0b' : '#17577f';
			}
			if (subject.type === 'proxy') {
				return subject.unreachable ? '#ef4444' : '#4c1d95';
			}
			return '#697386';
		};
		const label_fill_for = subject => {
			if (subject.type === 'host' && subject.disabled) {
				return '#55606b';
			}
			return (subject.type === 'host' || subject.type === 'proxy') ? '#ffffff' : '#1f2937';
		};

		const node_selection = this.canvas.append('g').selectAll('g').data(nodes).join('g')
			.attr('class', node => `topology-node ${node.type}`)
			// Manual positioning: fx/fy pin a node in place for the force simulation (it stops
			// pushing that node around once set) and, since render() only auto-places a node
			// whose x/y aren't already finite (see the "give a good initial position" loop
			// below), the pinned spot survives every future re-render — filter changes, the
			// automatic/manual link toggle, a fresh loadDevices() poll — same as if the layout
			// had put it there itself. d3.drag() coexists with the click-to-select handlers on
			// the child <rect> elements below without extra guarding: a plain click (no pointer
			// movement) still fires as a normal DOM 'click' event on mouseup.
			.call(d3.drag()
				.on('start', (event, node) => {
					if (!event.active) {
						simulation.alphaTarget(0.3).restart();
					}
					node.fx = node.x;
					node.fy = node.y;
				})
				.on('drag', (event, node) => {
					node.fx = event.x;
					node.fy = event.y;
				})
				.on('end', (event, node) => {
					if (!event.active) {
						simulation.alphaTarget(0);
					}
					// fx/fy stay set — the node keeps the spot it was dropped at rather than
					// springing back into the simulation's own layout.
				}));

		// Every node — Host, Proxy, or a standalone (unrepresented) Device — renders through this one
		// single-box path, styled purely by its own actual type. A Host/Proxy that happens to have an
		// associated Device (node.device set, see above) is not treated any differently here: no on-screen
		// trace of the represented_by relationship beyond what selectNode()'s panel does with it (§7 — the
		// earlier split-square "merged node" design that used to fork rendering here has been dropped).
		node_selection.append('rect').attr('x', -58).attr('y', -22).attr('width', 116).attr('height', 44).attr('rx', 4)
			.style('fill', fill_for)
			.style('stroke', stroke_for)
			.style('stroke-width', node => (node.type === 'proxy' && node.unreachable) ? 3 : 2)
			.style('stroke-dasharray', node => {
				if (node.type === 'device') {
					return '6 4';
				}
				return (node.type === 'proxy' && node.unreachable) ? '4 3' : null;
			})
			.style('cursor', 'pointer')
			.on('click', (event, node) => this.selectNode(node));
		const node_labels = node_selection.append('text').attr('class', 'topology-label').attr('text-anchor', 'middle')
			.style('fill', label_fill_for).style('pointer-events', 'none');
		this.renderWrappedLabel(node_labels, node => node.name, 14);

		const proxy_badges = node_selection.filter(node => node.type === 'proxy');
		proxy_badges.append('circle').attr('cx', 50).attr('cy', -18).attr('r', 10)
			.style('fill', '#ffffff').style('stroke', node => node.unreachable ? '#ef4444' : '#4c1d95').style('stroke-width', 2);
		proxy_badges.append('text').attr('x', 50).attr('y', -14).attr('text-anchor', 'middle')
			.style('font-size', '11px').style('font-weight', 'bold').style('fill', node => node.unreachable ? '#ef4444' : '#4c1d95')
			.text('P');
		// blind-spot ("?", amber, top-right) and maintenance ("M", slate) are deliberately different corners
		// with unrelated colors — a host can be both at once (behind an unreachable proxy AND in a maintenance
		// window) and the two badges must read as different concerns, never the same warning twice.
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

		const describeSubject = subject => {
			if (subject.type === 'proxy' && subject.unreachable) {
				return <?= json_encode(_('Proxy unreachable')) ?>;
			}
			if (subject.type === 'host' && subject.disabled) {
				return <?= json_encode(_('Host is disabled — not polled, no severity to show.')) ?>;
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
			return notes.length ? `${subject.name} — ${notes.join(' ')}` : subject.name;
		};
		node_selection.append('title').text(node => describeSubject(node));
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
