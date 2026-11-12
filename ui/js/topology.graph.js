/*
 * The pure parts of the topology graph (topology-1-model.md §12.1 "Node placement", §12.2 "Size", "Label placement", §12.3 "Parallel links"): no DOM, no d3, so that node can test them
 * (database/topology/test_topology_graph_js.php). The page (monitoring.topology.view.js.php) calls these functions and draws what they return.
 */
(function (root, factory) {
	if (typeof module === 'object' && module.exports) {
		module.exports = factory();
	}
	else {
		root.TopologyGraph = factory();
	}
})(typeof self !== 'undefined' ? self : this, function () {
	'use strict';

	const MAX_LABEL_PX = 160;
	const LABEL_GAP = 3;
	const SIZES = {
		large: {w: 64, h: 34, font: 12},
		small: {w: 26, h: 18, font: 10}
	};
	// the box of a node (shape and label) never gets closer than this to another box
	const BOX_GAP = 8;

	/** A rough width of a text in px when the page has no canvas (node tests): 0.6 of the font size per character. */
	const approxMeasure = (text, font) => String(text).length * font * 0.6;

	/**
	 * "1 reporter has new data since the last run" / "<N> reporters have new data since the last run" with the time of the last run; only the time when N is 0 (FR-C V-21, model §12.7).
	 * t = {one, many, last}: the three texts with %1$s; time = the already formatted time of the last run or ''.
	 */
	function runInfoText(n, time, t) {
		const parts = [];
		n = Number(n ?? 0);
		if (n > 0) {
			parts.push((n === 1 ? t.one : t.many).replace('%1$s', String(n)));
		}
		if (time) {
			parts.push(t.last.replace('%1$s', time));
		}
		return parts.join(' — ');
	}

	/** A label longer than max_px is cut in the middle: the start and the end stay ("SW19-09…6000-24G"). */
	function cutMiddle(text, max_px, font, measure = approxMeasure) {
		text = String(text);
		if (measure(text, font) <= max_px) {
			return text;
		}
		const chars = Array.from(text);
		for (let keep = chars.length - 1; keep >= 2; keep--) {
			const head = Math.ceil(keep / 2);
			const tail = keep - head;
			const cut = chars.slice(0, head).join('') + '…' + chars.slice(chars.length - tail).join('');
			if (measure(cut, font) <= max_px) {
				return cut;
			}
		}
		return '…';
	}

	/** Large = Host, Proxy, or a Device without association with two or more links; small = a leaf, a Device without a link, a leaf group node (§12.2 "Size"). */
	function sizeOf(node, link_count) {
		if (node.type === 'group') {
			return 'small';
		}
		if (node.type === 'host' || node.type === 'proxy') {
			return 'large';
		}
		return link_count >= 2 ? 'large' : 'small';
	}

	/**
	 * The geometry of a node: the shape and the box that includes the label (the collision box). x, y is the centre of the shape; the label is under it.
	 * Sets node.size, node.shape_w, node.shape_h, node.font, node.label_text, node.box = {w, h, top, bottom} (top/bottom relative to y).
	 */
	function layoutNode(node, size, measure = approxMeasure) {
		const s = SIZES[size];
		const label = node.label ?? node.name ?? '?';
		node.size = size;
		node.shape_w = s.w;
		node.shape_h = s.h;
		node.font = s.font;
		node.label_text = cutMiddle(label, MAX_LABEL_PX, s.font, measure);
		const label_w = Math.min(MAX_LABEL_PX, measure(node.label_text, s.font));
		const label_h = Math.ceil(s.font * 1.25);
		node.box = {
			w: Math.max(s.w, label_w) + 2,
			h: s.h + LABEL_GAP + label_h,
			top: -s.h / 2,
			bottom: s.h / 2 + LABEL_GAP + label_h
		};
		return node;
	}

	const rect = node => ({
		left: node.x - node.box.w / 2, right: node.x + node.box.w / 2,
		top: node.y + node.box.top, bottom: node.y + node.box.bottom
	});

	const overlaps = (a, b, gap = 0) => {
		const ra = rect(a);
		const rb = rect(b);
		return ra.left < rb.right + gap && rb.left < ra.right + gap && ra.top < rb.bottom + gap && rb.top < ra.bottom + gap;
	};

	/** The pairs of nodes whose boxes (shape and label) overlap. */
	function overlappingPairs(nodes, gap = 0) {
		const out = [];
		for (let i = 0; i < nodes.length; i++) {
			for (let j = i + 1; j < nodes.length; j++) {
				if (overlaps(nodes[i], nodes[j], gap)) {
					out.push([nodes[i], nodes[j]]);
				}
			}
		}
		return out;
	}

	/**
	 * Moves nodes apart until no two boxes overlap (with BOX_GAP between them), inside the bounds {width, height, padding}. A node with fx/fy (dragged) does not move. The nodes are placed one
	 * after the other (the fixed ones first); a node whose box meets a placed box goes to the nearest free place around where it was (rings of growing radius). Returns true when every node
	 * has its place without overlap; when the bounds are too small for the boxes it returns false (the caller makes the bounds larger).
	 */
	function resolveOverlaps(nodes, bounds) {
		const pad = bounds.padding ?? 0;
		const inside = node => node.x - node.box.w / 2 >= pad && node.x + node.box.w / 2 <= bounds.width - pad
			&& node.y + node.box.top >= pad && node.y + node.box.bottom <= bounds.height - pad;
		const clamp = node => {
			node.x = Math.max(pad + node.box.w / 2, Math.min(bounds.width - pad - node.box.w / 2, node.x));
			node.y = Math.max(pad - node.box.top, Math.min(bounds.height - pad - node.box.bottom, node.y));
		};
		const fixed = node => node.fx !== undefined && node.fx !== null;
		const order = [...nodes.filter(fixed), ...nodes.filter(node => !fixed(node))];
		const placed = [];
		const free = node => !placed.some(other => overlaps(node, other, BOX_GAP));
		const step = 14;
		const reach = Math.hypot(bounds.width, bounds.height);
		let all_placed = true;
		order.forEach(node => {
			if (!fixed(node)) {
				clamp(node);
				if (!free(node)) {
					const x0 = node.x;
					const y0 = node.y;
					let found = false;
					for (let radius = step; radius <= reach && !found; radius += step) {
						const count = Math.max(8, Math.ceil(2 * Math.PI * radius / step));
						for (let k = 0; k < count && !found; k++) {
							const angle = (k / count) * 2 * Math.PI;
							node.x = x0 + radius * Math.cos(angle);
							node.y = y0 + radius * Math.sin(angle);
							if (inside(node) && free(node)) {
								found = true;
							}
						}
					}
					if (!found) {
						node.x = x0;
						node.y = y0;
						all_placed = false;
					}
				}
			}
			placed.push(node);
		});

		return all_placed && overlappingPairs(nodes, 0).filter(([a, b]) => !(fixed(a) && fixed(b))).length === 0;
	}

	/** Distance in hops from the start node over the drawn links (undirected); a node with no path has no entry. */
	function distances(ids, links, start) {
		const adjacent = new Map(ids.map(id => [id, []]));
		links.forEach(link => {
			if (adjacent.has(link.source) && adjacent.has(link.target)) {
				adjacent.get(link.source).push(link.target);
				adjacent.get(link.target).push(link.source);
			}
		});
		const dist = new Map([[start, 0]]);
		const queue = [start];
		while (queue.length) {
			const id = queue.shift();
			adjacent.get(id)?.forEach(next => {
				if (!dist.has(next)) {
					dist.set(next, dist.get(id) + 1);
					queue.push(next);
				}
			});
		}
		return dist;
	}

	/**
	 * Radial placement (model §12.1 "Node placement"): the focus host in the centre, a node at distance d on ring d, a node with no path to the focus host on the outer ring. A ring is as large
	 * as its boxes need. The order on a ring follows the angles of the neighbors on the ring inside it, so that links cross less. Returns the radius of the outer ring.
	 * nodes need .id and .box (layoutNode); links need .source and .target (ids).
	 */
	function placeRadial(nodes, links, focus_id, center) {
		const ids = nodes.map(node => node.id);
		const dist = distances(ids, links, focus_id);
		const max_known = Math.max(0, ...dist.values());
		const rings = new Map();
		nodes.forEach(node => {
			const d = dist.has(node.id) ? dist.get(node.id) : max_known + 1;
			if (!rings.has(d)) {
				rings.set(d, []);
			}
			rings.get(d).push(node);
		});
		const by_id = new Map(nodes.map(node => [node.id, node]));
		const adjacent = new Map(ids.map(id => [id, []]));
		links.forEach(link => {
			if (adjacent.has(link.source) && adjacent.has(link.target)) {
				adjacent.get(link.source).push(link.target);
				adjacent.get(link.target).push(link.source);
			}
		});
		let radius = 0;
		[...rings.keys()].sort((a, b) => a - b).forEach(d => {
			const ring = rings.get(d);
			if (d === 0) {
				ring.forEach(node => {
					node.x = center.x;
					node.y = center.y;
					node.angle = 0;
				});
				return;
			}
			// the order on the ring: by the mean angle of the neighbors that are already placed (inner rings), then by id
			ring.forEach(node => {
				const placed = adjacent.get(node.id).map(id => by_id.get(id)).filter(other => other.angle !== undefined && other !== node && (dist.get(other.id) ?? -1) < d);
				node.sort_angle = placed.length ? Math.atan2(
					placed.reduce((s, other) => s + Math.sin(other.angle), 0), placed.reduce((s, other) => s + Math.cos(other.angle), 0)) : 0;
			});
			ring.sort((a, b) => (a.sort_angle - b.sort_angle) || String(a.id).localeCompare(String(b.id)));
			// the radius: the boxes along the circle need their widths, the ring must be outside the inner ring by the tallest box
			const need = ring.reduce((sum, node) => sum + Math.max(node.box.w, node.box.h) + BOX_GAP, 0);
			const tallest = Math.max(...ring.map(node => node.box.h));
			radius = Math.max(radius + tallest + 2 * BOX_GAP + 24, need / (2 * Math.PI));
			const offset = ring.length ? ring[0].sort_angle : 0;
			let travelled = 0;
			ring.forEach(node => {
				const share = (Math.max(node.box.w, node.box.h) + BOX_GAP) / need;
				const angle = offset + (travelled + share / 2) * 2 * Math.PI;
				travelled += share;
				node.angle = angle;
				node.x = center.x + radius * Math.cos(angle);
				node.y = center.y + radius * Math.sin(angle);
			});
		});

		return radius;
	}

	/**
	 * A set of parallel links between the same two nodes (§12.3 "Parallel links"): separate curved lines at a fixed distance from each other. For link i of count: the path from s to t
	 * (quadratic curve) and the middle point of the line (for a badge). A single link is a straight line.
	 */
	function curve(s, t, index, count, spacing = 16) {
		const dx = t.x - s.x;
		const dy = t.y - s.y;
		const length = Math.hypot(dx, dy) || 1;
		const offset = (index - (count - 1) / 2) * spacing;
		const nx = -dy / length;
		const ny = dx / length;
		const cx = (s.x + t.x) / 2 + nx * offset * 2;
		const cy = (s.y + t.y) / 2 + ny * offset * 2;

		return {
			d: count === 1 ? `M${s.x},${s.y}L${t.x},${t.y}` : `M${s.x},${s.y}Q${cx},${cy} ${t.x},${t.y}`,
			mid: count === 1 ? {x: (s.x + t.x) / 2, y: (s.y + t.y) / 2} : {x: (s.x + 2 * cx + t.x) / 4, y: (s.y + 2 * cy + t.y) / 4}
		};
	}

	/** Groups the links by node pair (either direction): returns link -> {index, count} (the order is the order of the links). */
	function parallelIndex(links) {
		const seen = new Map();
		const total = new Map();
		const keys = links.map(link => [String(link.source.id ?? link.source), String(link.target.id ?? link.target)].sort().join('|'));
		keys.forEach(key => total.set(key, (total.get(key) ?? 0) + 1));
		return links.map((link, i) => {
			const index = seen.get(keys[i]) ?? 0;
			seen.set(keys[i], index + 1);
			return {index, count: total.get(keys[i])};
		});
	}

	return {MAX_LABEL_PX, SIZES, BOX_GAP, approxMeasure, runInfoText, cutMiddle, sizeOf, layoutNode, overlappingPairs, resolveOverlaps, distances, placeRadial, curve, parallelIndex};
});
