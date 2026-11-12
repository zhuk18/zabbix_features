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

	/** The same line in parts, for the page (2.72: the time is the link to the last run view): {note: "N reporters have…" or '', before: "Last run: ", time, after: ''}. */
	function runInfoParts(n, time, t) {
		n = Number(n ?? 0);
		const [before, after] = t.last.split('%1$s');
		return {note: n > 0 ? (n === 1 ? t.one : t.many).replace('%1$s', String(n)) : '', before: time ? before : '', time: time || '', after: time ? (after ?? '') : ''};
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
	 * Radial placement (model §12.1 "Node placement", "Order on a ring", 2.72): the focus host in the centre, a node at distance d on ring d, a node with no path to the focus host on the
	 * outer ring. The order on a ring is the barycentre: a node of ring d+1 is placed at the mean angle of its neighbors on the inner rings, and the order of ring 1 follows its neighbors on the
	 * outer rings (a few passes of the barycentre in both directions). A ring is as large as its boxes need: its radius grows until they fit. Returns the radius of the outer ring.
	 * nodes need .id and .box (layoutNode); links need .source and .target (ids).
	 */
	function placeRadial(nodes, links, focus_id, center) {
		const ids = nodes.map(node => node.id);
		const dist = distances(ids, links, focus_id);
		const max_known = Math.max(0, ...dist.values());
		const rings = new Map();
		nodes.forEach(node => {
			const d = dist.has(node.id) ? dist.get(node.id) : max_known + 1;
			node.ring = d;
			if (!rings.has(d)) {
				rings.set(d, []);
			}
			rings.get(d).push(node);
		});
		const by_id = new Map(nodes.map(node => [node.id, node]));
		const adjacent = new Map(ids.map(id => [id, []]));
		links.forEach(link => {
			if (adjacent.has(link.source) && adjacent.has(link.target) && link.source !== link.target) {
				adjacent.get(link.source).push(link.target);
				adjacent.get(link.target).push(link.source);
			}
		});
		const depths = [...rings.keys()].sort((a, b) => a - b);
		// the LAG links of the node to the nodes of the inner rings: the width of the longest label that a bundle on it can have ("lag16 ↔ Trk1 ×2": 6 px a character at 10 px, with the box gap)
		const lag_widths = new Map(ids.map(id => [id, []]));
		links.forEach(link => {
			if (link.type === 'physical_link' && (link.source_lag_id || link.target_lag_id) && by_id.has(link.source) && by_id.has(link.target)) {
				const names = [link.source_lag, link.target_lag].filter(name => name !== null && name !== undefined && name !== '');
				const width = ((names.length ? names.join(' ↔ ') + ' ' : '') + '×2').length * 6 + 4;
				[[link.source, link.target], [link.target, link.source]].forEach(([from, to]) => {
					if (by_id.get(to).ring < by_id.get(from).ring) {
						lag_widths.get(from).push({other: by_id.get(to), width});
					}
				});
			}
		});
		const box_of = node => ({left: node.x - node.box.w / 2, right: node.x + node.box.w / 2, top: node.y + node.box.top, bottom: node.y + node.box.bottom});
		// false when the label of a LAG link of the node to an inner node has no free place on the line between the two boxes (the inner node is placed already)
		const lag_room = node => lag_widths.get(node.id).every(({other, width}) => placeLabel(u => ({x: node.x + (other.x - node.x) * u, y: node.y + (other.y - node.y) * u}), width, [box_of(node), box_of(other)]).free);
		const width_of = node => Math.max(node.box.w, node.box.h) + BOX_GAP;
		// the angle that a node wants: the circular mean of the angles of its neighbors on the given rings (undefined when none is placed yet)
		const desired = (node, wanted_rings) => {
			const near = adjacent.get(node.id).map(id => by_id.get(id)).filter(other => other.angle !== undefined && wanted_rings.includes(other.ring));
			return near.length ? Math.atan2(near.reduce((sum, other) => sum + Math.sin(other.angle), 0), near.reduce((sum, other) => sum + Math.cos(other.angle), 0)) : undefined;
		};
		// the angles of a ring in a given order: spread by the widths of the boxes, then the whole ring turned to the mean of the wishes
		const assign = (ring, order) => {
			const need = order.reduce((sum, node) => sum + width_of(node), 0);
			let travelled = 0;
			const base = order.map(node => {
				const share = width_of(node) / need;
				const angle = (travelled + share / 2) * 2 * Math.PI;
				travelled += share;
				return angle;
			});
			const wishes = order.map((node, i) => [node.wish, base[i]]).filter(([wish]) => wish !== undefined);
			const turn = wishes.length ? Math.atan2(wishes.reduce((sum, [wish, a]) => sum + Math.sin(wish - a), 0), wishes.reduce((sum, [wish, a]) => sum + Math.cos(wish - a), 0)) : 0;
			order.forEach((node, i) => {
				node.angle = base[i] + turn;
			});
			rings.set(ring, order);
		};
		const sort_ring = (ring, wanted_rings) => {
			const order = rings.get(ring);
			order.forEach(node => {
				node.wish = desired(node, wanted_rings);
			});
			// by the wish; nodes without a wish keep their place at the end, by id
			const key = node => (node.wish === undefined ? Infinity : ((node.wish % (2 * Math.PI)) + 2 * Math.PI) % (2 * Math.PI));
			order.sort((a, b) => (key(a) - key(b)) || String(a.id).localeCompare(String(b.id)));
			assign(ring, order);
		};
		nodes.forEach(node => {
			node.angle = undefined;
		});
		const focus_ring = rings.get(0) ?? [];
		focus_ring.forEach(node => {
			node.angle = 0;
		});
		const outer = depths.filter(d => d > 0);
		// the first pass outwards: ring 1 by id, the others by their inner neighbors
		outer.forEach(d => {
			if (d === 1) {
				const order = rings.get(1).sort((a, b) => String(a.id).localeCompare(String(b.id)));
				order.forEach(node => {
					node.wish = undefined;
				});
				assign(1, order);
			}
			else {
				sort_ring(d, [d - 1]);
			}
		});
		// passes in both directions: a ring follows the mean angle of its neighbors on the rings next to it
		for (let pass = 0; pass < 4; pass++) {
			[...outer].reverse().forEach(d => sort_ring(d, [d - 1, d + 1]));
			outer.forEach(d => sort_ring(d, d === 1 ? [2] : [d - 1, d + 1]));
		}
		// the radius of each ring: the boxes along the circle need their widths; the ring is outside the inner ring by the tallest box
		let radius = 0;
		focus_ring.forEach(node => {
			node.x = center.x;
			node.y = center.y;
		});
		outer.forEach(d => {
			const ring = rings.get(d);
			const need = ring.reduce((sum, node) => sum + width_of(node), 0);
			const tallest = Math.max(...ring.map(node => node.box.h));
			radius = Math.max(radius + tallest + 2 * BOX_GAP + 24, need / (2 * Math.PI));
			const put = () => ring.forEach(node => {
				node.x = center.x + radius * Math.cos(node.angle);
				node.y = center.y + radius * Math.sin(node.angle);
			});
			put();
			// §12.3 (2.73): the line of a LAG link to a node of an inner ring shows the label of its bundle: the ring is as far out as the label needs a free place on the line
			for (let guard = 0; guard < 200 && ring.some(node => lag_room(node) === false); guard++) {
				radius += 6;
				put();
			}
		});

		return radius;
	}

	/**
	 * The leaves of an unfolded group on an arc around the parent (§12.8, 2.72): in the largest free angle between the other lines of the parent (`other_angles`: the angles, in radians, of
	 * its lines to the nodes that are not in the group), in the given order (the caller orders them by the parent's local port); the radius grows until they fit. Returns [{node, x, y}].
	 */
	function arcPlace(parent, leaves, other_angles) {
		const TWO = 2 * Math.PI;
		const norm = a => ((a % TWO) + TWO) % TWO;
		// an obstacle is an angle (a line) or {angle, half}: the angle of a node and the half of the angle that its box covers; they are merged into the free angles between them
		const intervals = other_angles.map(o => (typeof o === 'number' ? {angle: o, half: 0.06} : {angle: o.angle, half: Math.max(o.half, 0.06)}))
			.map(o => [norm(o.angle - o.half), o.half * 2]).sort((x, y) => x[0] - y[0]);
		let centre = -Math.PI / 2;      // no other line: the arc is above the parent
		let gap = TWO;
		if (intervals.length) {
			// the free gap after each obstacle, up to the start of the next obstacle (the ones that overlap leave no gap)
			gap = 0;
			intervals.forEach(([start, length], i) => {
				const end = start + length;
				let free_until = i + 1 < intervals.length ? intervals[i + 1][0] : intervals[0][0] + TWO;
				// a later obstacle that starts before this one ends: the gap is between this end and the next start that is after it
				for (let k = i + 1; k < intervals.length + 1 && free_until < end; k++) {
					free_until = k + 1 < intervals.length ? intervals[k + 1][0] : intervals[0][0] + TWO;
				}
				if (free_until - end > gap) {
					gap = free_until - end;
					centre = end + gap / 2;
				}
			});
		}
		const span = Math.min(gap * 0.9, TWO * 0.9);
		const biggest = Math.max(...leaves.map(node => Math.max(node.box.w, node.box.h)));
		const minimum = Math.max(parent.box.w, parent.box.h) / 2 + biggest / 2 + 36;
		// for a radius and a start angle: the leaves one after the other along the arc, each at the first angle where its box (shape and label) is clear of the one before and of the parent;
		// returns the angle that they use. The test is made at the angles where the leaves really are, so that the result has no overlap.
		const layout = (radius, start) => {
			let angle = start;
			leaves.forEach((node, i) => {
				for (let guard = 0; guard < 4000; guard++) {
					node.x = parent.x + Math.cos(angle) * radius;
					node.y = parent.y + Math.sin(angle) * radius;
					if ((i === 0 || !overlaps(node, leaves[i - 1], BOX_GAP)) && !overlaps(node, parent, BOX_GAP)) {
						break;
					}
					angle += 0.5 / radius;
				}
			});
			return angle - start;
		};
		let radius = minimum;
		let used = layout(radius, centre - span / 2);
		while (used > span && radius < 4000) {
			radius += 12;
			used = layout(radius, centre - span / 2);
		}
		// centred in the free angle: a second layout from the middle of what they use (the final layout is the one that is returned)
		layout(radius, centre - used / 2);

		return leaves.map(node => ({node, x: node.x, y: node.y}));
	}

	/**
	 * Moves the nodes that are in the way of an arc out of its sector (§12.8: "other nodes in the way move away"): a node that is nearer to the parent than the leaves are, and whose angle (with
	 * the angle that its box covers) is inside the sector of the leaves, is turned about the parent to the nearest edge of the sector, at the same distance. Fixed nodes (fx) stay.
	 */
	function clearSector(parent, leaves, others) {
		const TWO = 2 * Math.PI;
		const angle_of = node => Math.atan2(node.y - parent.y, node.x - parent.x);
		const mean = Math.atan2(leaves.reduce((s, n) => s + Math.sin(angle_of(n)), 0), leaves.reduce((s, n) => s + Math.cos(angle_of(n)), 0));
		const rel = a => Math.atan2(Math.sin(a - mean), Math.cos(a - mean));      // the angle from the middle of the arc, -π..π
		const reach = Math.max(...leaves.map(n => Math.hypot(n.x - parent.x, n.y - parent.y)));
		const low = Math.min(...leaves.map(n => rel(angle_of(n))));
		const high = Math.max(...leaves.map(n => rel(angle_of(n))));
		others.forEach(node => {
			if (node === parent || leaves.includes(node) || (node.fx !== undefined && node.fx !== null)) {
				return;
			}
			const distance = Math.hypot(node.x - parent.x, node.y - parent.y);
			if (distance > reach + Math.hypot(node.box.w, node.box.h) / 2) {
				return;
			}
			const half = Math.asin(Math.min(0.99, (Math.hypot(node.box.w, node.box.h) / 2 + BOX_GAP) / Math.max(distance, 1)));      // the angle that the box covers (its corner)
			const angle = rel(angle_of(node));
			if (angle + half < low || angle - half > high) {
				return;
			}
			// to the nearer edge of the sector, outside it by the angle that the box covers
			const target = (angle - low) < (high - angle) ? low - half - 0.02 : high + half + 0.02;
			node.x = parent.x + distance * Math.cos(mean + target);
			node.y = parent.y + distance * Math.sin(mean + target);
		});
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
			mid: count === 1 ? {x: (s.x + t.x) / 2, y: (s.y + t.y) / 2} : {x: (s.x + 2 * cx + t.x) / 4, y: (s.y + 2 * cy + t.y) / 4},
			// the point at the fraction u (0..1) of the line, by its parameter (u = 0.5 is `mid`)
			at: u => (count === 1
				? {x: s.x + (t.x - s.x) * u, y: s.y + (t.y - s.y) * u}
				: {x: (1 - u) * (1 - u) * s.x + 2 * (1 - u) * u * cx + u * u * t.x, y: (1 - u) * (1 - u) * s.y + 2 * (1 - u) * u * cy + u * u * t.y})
		};
	}

	/**
	 * The place of the label of a LAG bundle on its line (§12.3, 2.73): the point between 30 % and 70 % of the line where the box of the label touches no obstacle (the box of a node with its
	 * label, a label placed before), the one nearest to the middle; the middle when it is free, and also when no point is free (`free` is false then, and the overlap check lists it).
	 *
	 * @param function at   u (0..1) -> {x, y}: the point of the line
	 * @param number   width  the width of the text of the label
	 * @param array    obstacles  [{left, right, top, bottom}] in the coordinates of the line
	 *
	 * @return object  {u, x, y, free, box}; (x, y) is the anchor of the text (middle, baseline), box is the box of the label
	 */
	function placeLabel(at, width, obstacles, step = 0.01) {
		const boxAt = u => {
			const p = at(u);
			// the text is anchored in the middle, its baseline 4 px above the line; a 10 px font: 10 px above the baseline, 3 px below it; a gap of 2 px
			return {u, x: p.x, y: p.y - 4, box: {left: p.x - width / 2 - 2, right: p.x + width / 2 + 2, top: p.y - 4 - 10 - 2, bottom: p.y - 4 + 3 + 2}};
		};
		const touches = box => obstacles.some(o => box.left < o.right && o.left < box.right && box.top < o.bottom && o.top < box.bottom);
		const candidates = [];
		for (let k = 0; k <= Math.round(0.2 / step); k++) {
			candidates.push(0.5 + k * step);
			if (k > 0) {
				candidates.push(0.5 - k * step);
			}
		}
		for (const u of candidates) {
			const place = boxAt(u);
			if (!touches(place.box)) {
				return {...place, free: true};
			}
		}

		return {...boxAt(0.5), free: false};
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

	// ---- the icon by device class (§12.2 "Device class", 2.71) ----
	// One style: strokes only, drawn in a box of about 16 × 12 around (0, 0); the page scales it to the size of the node (large 1.3, small 0.8: readable in 26 × 18).
	const ICON_DEFS = {
		access_point: [['path', {d: 'M-6.5 -1 A9 9 0 0 1 6.5 -1'}], ['path', {d: 'M-3.6 2 A5 5 0 0 1 3.6 2'}], ['circle', {cx: 0, cy: 5, r: 1.1, fill: 'currentColor'}]],
		phone: [['rect', {x: -4, y: -6, width: 8, height: 12, rx: 1.5}], ['path', {d: 'M-1.8 -3.5 H1.8 M-1.8 3.5 H1.8'}]],
		switch: [['rect', {x: -8, y: -4.5, width: 16, height: 9, rx: 1.5}], ['path', {d: 'M-5 -1.2 H4 L2.2 -3 M5 1.2 H-4 L-2.2 3'}]],
		router: [['circle', {cx: 0, cy: 0, r: 6.2}], ['path', {d: 'M-3.2 0 H3.2 M0 -3.2 V3.2 M-3.2 0 L-2 -1.2 M3.2 0 L2 1.2'}]],
		station: [['rect', {x: -6.5, y: -5.5, width: 13, height: 8.5, rx: 1}], ['path', {d: 'M-3.5 6.5 H3.5 M0 3 V6.5'}]],
		generic: [['rect', {x: -4.5, y: -4.5, width: 9, height: 9, rx: 2}], ['circle', {cx: 0, cy: 0, r: 1.2, fill: 'currentColor'}]],
		proxy: [['rect', {x: -4, y: -4, width: 8, height: 8, rx: 1.5}], ['path', {d: 'M-7 0 H-4 M4 0 H7 M0 -7 V-4 M0 4 V7'}]]
	};
	const CLASS_ORDER = ['access_point', 'phone', 'switch', 'router', 'station', 'generic'];

	/** The icon name of a node: a Proxy its own; a Host the class of its Device (a Host without a Device: generic); a Device its class (none: generic); a group node none. */
	function iconOf(node) {
		if (node.type === 'proxy') {
			return 'proxy';
		}
		const device = node.type === 'host' ? node.device : (node.type === 'device' ? node : null);
		if (node.type !== 'host' && node.type !== 'device') {
			return null;
		}
		return device && CLASS_ORDER.includes(device.device_class) ? device.device_class : 'generic';
	}

	/** The markup of an icon (a constant of this file, no data from the network): for the legend, which is built as text. */
	function iconMarkup(name, stroke, scale = 1) {
		const attrs = a => Object.entries(a).map(([k, v]) => `${k}="${v}"`).join(' ');
		return `<g transform="scale(${scale})" style="fill:none;stroke:${stroke};stroke-width:${1.5 / scale};color:${stroke}">` +
			(ICON_DEFS[name] ?? ICON_DEFS.generic).map(([tag, a]) => `<${tag} ${attrs(a)}/>`).join('') + '</g>';
	}

	/** Counts of the nodes by class name (the classes of CLASS_ORDER). */
	function classCounts(devices) {
		const counts = {};
		devices.forEach(device => {
			const name = CLASS_ORDER.includes(device.device_class) ? device.device_class : 'generic';
			counts[name] = (counts[name] ?? 0) + 1;
		});
		return counts;
	}

	/** "9 access points, 6 stations": the counts by class, the largest first; names = {class: [one, many]}, with %1$s for the number. */
	function classText(counts, names) {
		return CLASS_ORDER.filter(name => counts[name])
			.sort((a, b) => counts[b] - counts[a] || CLASS_ORDER.indexOf(a) - CLASS_ORDER.indexOf(b))
			.map(name => (counts[name] === 1 ? names[name][0] : names[name][1]).replace('%1$s', String(counts[name]))).join(', ');
	}

	/**
	 * Is a node drawn (§12.1, FR-C V-1; 091): a Host or a Device without a drawn link only with the switch "Show devices without links", with "Possible duplicates only", or as the focus
	 * host. A Proxy, a group node and every node with a drawn link: drawn (a Proxy is in the view by the view scope rules).
	 */
	function isDrawn(node, ctx) {
		if (node.type !== 'host' && node.type !== 'device') {
			return true;
		}
		return ctx.has_link || ctx.show_unlinked || ctx.only_duplicates || ctx.is_focus;
	}

	// ---- LAG bundle (§12.3, 2.72; FR-C V-6) ----
	const channelsOf = link => [link.discovered_via === 'manual' ? 'manual' : 'discovered', link.stale || link.superseded_at ? 'faded' : 'solid',
		link.severity !== null && link.severity !== undefined ? 'severity:' + String(link.color ?? link.severity) : 'none'].join('|');

	/**
	 * Port-level links between the same two nodes whose ports on one side, or on both sides, are members of one LAG (the same `lag_id`) are one line when they have the same channels (dash,
	 * colour, opacity); a member with other channels is its own line; a bundle has two or more members, one left is an ordinary line; the members of two LAGs between the same nodes are two
	 * bundles. Device-level links and links that `keep(link)` refuses (a contradicted manual link) are not touched.
	 *
	 * @return array  the lines in the order of the first member: a link as it is, or a bundle {type: 'lag_bundle', source, target, members, label, ...the channels of its members}
	 */
	function bundleLinks(links, keep = () => true) {
		const groups = new Map();
		links.forEach((link, index) => {
			if (link.type !== 'physical_link' || !keep(link) || (!link.source_lag_id && !link.target_lag_id)) {
				return;
			}
			const pair = [String(link.source), String(link.target)];
			// the key: the pair as the link has it, each side with its LAG, and the channels
			const key = [pair.join('>'), link.source_lag_id ?? '-', link.target_lag_id ?? '-', channelsOf(link)].join('#');
			if (!groups.has(key)) {
				groups.set(key, []);
			}
			groups.get(key).push(index);
		});
		const bundled = new Map();      // index of the first member -> bundle
		const skipped = new Set();
		groups.forEach(indexes => {
			if (indexes.length < 2) {
				return;
			}
			const members = indexes.map(i => links[i]);
			const first = members[0];
			const names = [first.source_lag, first.target_lag].filter(name => name !== null && name !== undefined && name !== '');
			// "Trk1 ×2"; both sides known: "lag16 ↔ Trk1 ×2"
			const label = (names.length ? names.join(' ↔ ') + ' ' : '') + '×' + members.length;
			bundled.set(indexes[0], {...first, type: 'lag_bundle', members, count: members.length, label, edge_id: null});
			indexes.forEach(i => skipped.add(i));
		});
		const out = [];
		links.forEach((link, index) => {
			if (bundled.has(index)) {
				out.push(bundled.get(index));
			}
			else if (!skipped.has(index)) {
				out.push(link);
			}
		});

		return out;
	}

	return {placeLabel, clearSector, arcPlace, bundleLinks, runInfoParts, isDrawn, ICON_DEFS, CLASS_ORDER, iconOf, iconMarkup, classCounts, classText, MAX_LABEL_PX, SIZES, BOX_GAP, approxMeasure, runInfoText, cutMiddle, sizeOf, layoutNode, overlappingPairs, resolveOverlaps, distances, placeRadial, curve, parallelIndex};
});
