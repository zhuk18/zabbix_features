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
	// the largest angle (radians) that a node of an outer ring may be from the mean angle of its inner neighbors (§12.1, 2.74): 60 degrees, about the half of the arc that five siblings fill
	// the arc of an unfolded group (§12.8, 2.74): the largest radius of the first row, and the distance of the second row
	const RADIUS_MAX = 200;
	const ROW_STEP = 50;
	const DEVIATION = 60 * Math.PI / 180;

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
	function placeRadial(nodes, links, focus_id, center, measure = approxMeasure) {
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
				const width = labelWidth((names.length ? names.join(' ↔ ') + ' ' : '') + '×2', measure);
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
		/**
		 * The angles of the ring `ring` (in its order along the circle) at radius `R`: as near as the boxes allow to the mean angle of the inner neighbors (the final angles of ring d - 1), so that
		 * the sum of the squares of the differences is the least (the nodes keep their order, and two neighbors on the circle are the width of their boxes apart). The circle is cut at each place in
		 * turn; the cut with the least sum is used. Returns the largest difference (radians) of a node from its wish, or null (nothing changed) when the nodes do not fit in the circle at that radius.
		 */
		const pullToInner = (ring, R, d, apply = true) => {
			const TWO = 2 * Math.PI;
			const n = ring.length;
			const wishes = ring.map(node => desired(node, [d - 1]));
			if (n < 2 || wishes.every(wish => wish === undefined)) {
				return null;
			}
			const widths = ring.map(width_of);
			const gap = (i, j) => ((widths[i] + widths[j]) / 2) / R;
			const base = ring.map(node => node.angle);
			let best = null;
			for (let cut = 0; cut < n; cut++) {
				const idx = Array.from({length: n}, (_, j) => (cut + j) % n);
				const B = idx.map(i => base[i] + (i < cut ? TWO : 0));
				const W = idx.map((i, j) => (wishes[i] === undefined ? B[j] : B[j] + Math.atan2(Math.sin(wishes[i] - B[j]), Math.cos(wishes[i] - B[j]))));
				const weight = idx.map(i => (wishes[i] === undefined ? 0.001 : 1));
				const c = [0];
				for (let j = 1; j < n; j++) {
					c.push(c[j - 1] + gap(idx[j - 1], idx[j]));
				}
				// the least squares with y nondecreasing: pool the adjacent blocks that are in the wrong order
				const blocks = [];
				W.forEach((w, j) => {
					blocks.push({sum: (w - c[j]) * weight[j], weight: weight[j], count: 1});
					while (blocks.length > 1) {
						const last = blocks[blocks.length - 1];
						const before = blocks[blocks.length - 2];
						if (before.sum / before.weight <= last.sum / last.weight) {
							break;
						}
						blocks.splice(blocks.length - 2, 2, {sum: before.sum + last.sum, weight: before.weight + last.weight, count: before.count + last.count});
					}
				});
				const x = [];
				blocks.forEach(block => {
					for (let k = 0; k < block.count; k++) {
						x.push(block.sum / block.weight + c[x.length]);
					}
				});
				if (x[n - 1] - x[0] + gap(idx[n - 1], idx[0]) > TWO + 1e-9) {
					continue;
				}
				const cost = x.reduce((sum, value, j) => sum + weight[j] * (value - W[j]) ** 2, 0);
				if (best === null || cost < best.cost) {
					best = {cost, idx, x, deviation: Math.max(...x.map((value, j) => (wishes[idx[j]] === undefined ? 0 : Math.abs(value - W[j]))))};
				}
			}
			if (best === null) {
				return null;
			}
			if (apply) {
				best.idx.forEach((i, j) => {
					ring[i].angle = best.x[j];
				});
			}

			return best.deviation;
		};
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
			// 2.74 §12.1: a node of this ring is placed in the angle of its inner neighbors (one neighbor: its angle; a cluster of siblings is centred on it), the others of the ring move
			// away as little as they must. The ring grows (up to 2 times the smallest) until no node is more than DEVIATION from the angle of its inner neighbors; with no radius that
			// does it, the one with the least deviation is used; and when the nodes do not fit in the circle at any radius, the even spread of the barycentre order stays.
			if (d >= 2) {
				const base_radius = radius;
				let best = null;
				for (let factor = 1; factor <= 2.0001; factor += 0.05) {
					const deviation = pullToInner(ring, base_radius * factor, d, false);
					if (deviation !== null && (best === null || deviation < best.deviation - 1e-9)) {
						best = {factor, deviation};
					}
					if (deviation !== null && deviation <= DEVIATION) {
						break;
					}
				}
				if (best !== null) {
					radius = base_radius * best.factor;
					pullToInner(ring, radius, d, true);
				}
			}
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
		// §12.8 "Angle slots" (2.85): each leaf has its own slot of the arc, in the order of the leaves. For a radius, a number of rows (1; 2: the leaves alternate between the radius and the radius
		// + ROW_STEP) and the start angle: the leaves one after the other along the arc, each at the first angle where its box (the shape and the label) is clear of the parent and of every leaf
		// placed before, the line from the parent to it does not touch the box of a leaf placed before, and the line to a leaf placed before does not touch its box. So the slot of a leaf is as
		// wide as its box needs at its radius, and the line to an outer-row leaf passes between two inner-row leaves; no leaf line crosses another leaf of the arc. Returns the angle that they use.
		const LINE_GAP = 3;
		const grown = (node, gap) => ({left: node.x - node.box.w / 2 - gap, right: node.x + node.box.w / 2 + gap, top: node.y + node.box.top - gap, bottom: node.y + node.box.bottom + gap});
		const touches = (leaf, box) => segmentHitsBox(parent, leaf, box.left, box.right, box.top, box.bottom);
		const layout = (radius, start, rows) => {
			let angle = start;
			leaves.forEach((node, i) => {
				const r = radius + (rows === 2 && i % 2 === 1 ? ROW_STEP : 0);
				for (let guard = 0; guard < 4000; guard++) {
					node.x = parent.x + Math.cos(angle) * r;
					node.y = parent.y + Math.sin(angle) * r;
					const mine = grown(node, LINE_GAP);
					if (!leaves.slice(0, i).some(before => overlaps(node, before, BOX_GAP) || touches(node, grown(before, LINE_GAP)) || touches(before, mine)) && !overlaps(node, parent, BOX_GAP)) {
						break;
					}
					angle += 1.5 / r;
				}
			});
			return angle - start;
		};
		// §12.8, 2.74 "Arc size": 1. one row, the radius up to RADIUS_MAX, in the free angle; 2. two rows (r and r + ROW_STEP), in the free angle; 3. the angle widens on both sides of its centre
		// until they fit at RADIUS_MAX (the nodes in it move out, the lines of the leaves may cross the other lines of the parent); only when even the whole circle is not enough, the radius grows
		let rows = 1;
		let radius = minimum;
		let width = span;
		let used = layout(radius, centre - width / 2, 1);
		while (used > width && radius + 12 <= RADIUS_MAX) {
			radius += 12;
			used = layout(radius, centre - width / 2, 1);
		}
		if (used > width) {
			rows = 2;
			radius = minimum;
			used = layout(radius, centre - width / 2, 2);
			while (used > width && radius + 12 <= RADIUS_MAX) {
				radius += 12;
				used = layout(radius, centre - width / 2, 2);
			}
		}
		const free_angle_used = width;
		while (used > width && width < TWO * 0.95) {
			width = Math.min(TWO * 0.95, width + 0.05);
			used = layout(radius, centre - width / 2, rows);
		}
		while (used > width && radius < 1500) {
			radius += 24;
			used = layout(radius, centre - width / 2, rows);
		}
		// centred in the angle: a second layout from the middle of what they use (the final layout is the one that is returned)
		layout(radius, centre - used / 2, rows);

		const placed = leaves.map(node => ({node, x: node.x, y: node.y}));
		placed.info = {free_angle: gap, rows, span: used, radius: rows === 2 ? radius + ROW_STEP : radius, widened: used > free_angle_used + 1e-9};

		return placed;
	}

	/**
	 * Moves the nodes that are in the way of an arc out of its sector (§12.8: "other nodes in the way move away"): a node that is nearer to the parent than the leaves are, and whose angle (with
	 * the angle that its box covers) is inside the sector of the leaves, is turned about the parent to the nearest edge of the sector, at the same distance. Fixed nodes (fx) stay. Returns the nodes that were moved.
	 */
	function clearSector(parent, leaves, others) {
		const TWO = 2 * Math.PI;
		const angle_of = node => Math.atan2(node.y - parent.y, node.x - parent.x);
		const mean = Math.atan2(leaves.reduce((s, n) => s + Math.sin(angle_of(n)), 0), leaves.reduce((s, n) => s + Math.cos(angle_of(n)), 0));
		const rel = a => Math.atan2(Math.sin(a - mean), Math.cos(a - mean));      // the angle from the middle of the arc, -π..π
		const reach = Math.max(...leaves.map(n => Math.hypot(n.x - parent.x, n.y - parent.y)));
		const low = Math.min(...leaves.map(n => rel(angle_of(n))));
		const high = Math.max(...leaves.map(n => rel(angle_of(n))));
		const moved = [];
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
			moved.push(node);
		});

		return moved;
	}

	/**
	 * Room for the labels of the LAG bundles (§12.3, 2.73) in a layout that the collision step has made: a bundle whose label has no free point on its line (every point between 30 % and 70 %
	 * touches a node box or a label placed before) is stretched: a node that is on the line (not an end, not fixed) moves 18 px across the line, away from it; with none, the node at one end that is not fixed moves 14 px away from the other end along
	 * the line; the collision step makes room for it, and the labels are tried again (up to `rounds` times). `bundles` = [{a, b, width, index, count}] (a and b are node objects, the line is as G.curve draws it). Returns true when every
	 * label has a free point.
	 */
	function makeLabelRoom(nodes, bundles, bounds, rounds = 60) {
		const fixed = node => node.fx !== undefined && node.fx !== null;
		for (let round = 0; round < rounds; round++) {
			const boxes = nodes.map(node => ({left: node.x - node.box.w / 2, right: node.x + node.box.w / 2, top: node.y + node.box.top, bottom: node.y + node.box.bottom}));
			const placed = [];
			let blocked = 0;
			bundles.forEach(({a, b, width, index, count}) => {
				const place = placeLabel(curve(a, b, index, count).at, width, [...boxes, ...placed]);
				placed.push(place.box);
				if (place.free) {
					return;
				}
				blocked++;
				const length = Math.hypot(b.x - a.x, b.y - a.y) || 1;
				const ux = (b.x - a.x) / length;
				const uy = (b.y - a.y) / length;
				// a node (not an end of the bundle) on the part of the line where the label could be: it is moved away from the line, across it (a node that is fixed stays)
				const in_the_way = nodes.find(node => node !== a && node !== b && !fixed(node) && [0.3, 0.4, 0.5, 0.6, 0.7].some(u => {
					const point = curve(a, b, index, count).at(u);
					return point.x > node.x - node.box.w / 2 - width / 2 && point.x < node.x + node.box.w / 2 + width / 2 && point.y > node.y + node.box.top - 8 && point.y < node.y + node.box.bottom + 14;
				}));
				if (in_the_way) {
					const side = Math.sign((in_the_way.x - a.x) * -uy + (in_the_way.y - a.y) * ux) || 1;
					in_the_way.x += -uy * side * 18;
					in_the_way.y += ux * side * 18;
				}
				else if (!fixed(b)) {
					b.x += ux * 14;
					b.y += uy * 14;
				}
				else if (!fixed(a)) {
					a.x -= ux * 14;
					a.y -= uy * 14;
				}
			});
			if (blocked === 0) {
				return true;
			}
			resolveOverlaps(nodes, bounds);
		}

		return false;
	}

	/**
	 * No line through a node (§12.1, 2.84). `lines`: [{a, b, index, count}], the drawn lines (a, b: node objects with x, y, box; index and count give the parallel curve, §12.3).
	 * A line crosses a node that is not one of its ends when the line (as a polyline of 16 pieces) enters the box of the node (the box grown by `margin`).
	 */
	function segmentHitsBox(p, q, left, right, top, bottom) {
		let t0 = 0;
		let t1 = 1;
		const dx = q.x - p.x;
		const dy = q.y - p.y;
		for (const [d, r] of [[-dx, p.x - left], [dx, right - p.x], [-dy, p.y - top], [dy, bottom - p.y]]) {
			if (d === 0) {
				if (r < 0) {
					return false;
				}
			}
			else {
				const t = r / d;
				if (d < 0) {
					t0 = Math.max(t0, t);
				}
				else {
					t1 = Math.min(t1, t);
				}
			}
		}
		return t0 <= t1;
	}

	function lineCrossesNode(line, node, margin = 2, points = null) {
		if (node === line.a || node === line.b) {
			return false;
		}
		const left = node.x - node.box.w / 2 - margin;
		const right = node.x + node.box.w / 2 + margin;
		const top = node.y + node.box.top - margin;
		const bottom = node.y + node.box.bottom + margin;
		// a quick reject: the distance of the middle of the box to the chord is more than half of the box diagonal plus the bend of a parallel line (the curve is `offset` off the chord, 16 px a step)
		const bend = Math.abs(line.index - (line.count - 1) / 2) * 16 + 1;
		const cx = node.x;
		const cy = node.y + (node.box.top + node.box.bottom) / 2;
		const dx = line.b.x - line.a.x;
		const dy = line.b.y - line.a.y;
		const length2 = dx * dx + dy * dy;
		const t = length2 > 0 ? Math.max(0, Math.min(1, ((cx - line.a.x) * dx + (cy - line.a.y) * dy) / length2)) : 0;
		if (Math.hypot(cx - (line.a.x + t * dx), cy - (line.a.y + t * dy)) > Math.hypot(node.box.w, node.box.bottom - node.box.top) / 2 + margin + bend + 2) {
			return false;
		}
		const c = curve(line.a, line.b, line.index, line.count);
		let previous = c.at(0);
		for (let i = 1; i <= 16; i++) {
			const point = c.at(i / 16);
			if (segmentHitsBox(previous, point, left, right, top, bottom)) {
				return true;
			}
			previous = point;
		}
		return false;
	}

	/** The (line, node) pairs where a line passes through a node that is not its end: [{line, node}] */
	function linesThrough(nodes, lines, margin = 0) {
		const out = [];
		lines.forEach(line => nodes.forEach(node => {
			if (lineCrossesNode(line, node, margin)) {
				out.push({line, node});
			}
		}));

		return out;
	}

	/**
	 * A node that a line crosses is moved off the line (§12.1 "No line through a node", 2.84): turned about the nearer end of the line (1 to 2.2 times as far), else moved across the line, to
	 * the nearest place where its box is free of every other box, it is crossed by no line, and its own lines cross no other node. When the node can not be moved (fixed, or no such place),
	 * an end of the line is turned about the other end instead, with the same conditions. A node that is fixed (`fx`) stays. When no place is free of every crossing, the place with fewer
	 * crossings than now is taken (for the crossed node), else nothing moves. The pairs are taken again after each move.
	 *
	 * @return {number}  the number of nodes that were moved
	 */
	function clearThroughLines(nodes, lines, bounds, limit = 40, obstacles = [], effort = 1, seed_start = 20840) {
		const fixed = node => node.fx !== undefined && node.fx !== null;
		const own = new Map();
		lines.forEach(line => {
			[line.a, line.b].forEach(node => own.set(node, [...(own.get(node) ?? []), line]));
		});
		// the group node of a folded leaf group is a satellite of its parent: it moves with the parent (the parent's group line keeps its length)
		const satellites = new Map();
		nodes.forEach(node => {
			if (node.type !== 'group') {
				return;
			}
			const neighbors = new Set((own.get(node) ?? []).map(line => (line.a === node ? line.b : line.a)));
			if (neighbors.size === 1) {
				const [parent] = neighbors;
				satellites.set(parent, [...(satellites.get(parent) ?? []), node]);
			}
		});
		const members = mover => [mover, ...(satellites.get(mover) ?? [])];
		let budget = 3000 * effort;      // the number of places that are tried in one call: the call ends in a few tens of milliseconds also when nothing can be solved
		const scoreOne = node => {
			let count = 0;
			lines.forEach(line => {
				if (lineCrossesNode(line, node)) {
					count++;
				}
			});
			(own.get(node) ?? []).forEach(line => nodes.forEach(other => {
				if (lineCrossesNode(line, other)) {
					count++;
				}
			}));

			return count;
		};
		const score = node => {
			budget--;

			return members(node).reduce((sum, member) => sum + scoreOne(member), 0);
		};
		const insideOne = node => node.x >= bounds.padding + node.box.w / 2 && node.x <= bounds.width - bounds.padding - node.box.w / 2
			&& node.y >= bounds.padding - node.box.top && node.y <= bounds.height - bounds.padding - node.box.bottom;
		const inside = node => members(node).every(insideOne);
		// the places of a mover and its satellites are set together
		const place = (mover, x, y, origin) => {
			const dx = x - origin[0][0];
			const dy = y - origin[0][1];
			members(mover).forEach((member, i) => {
				member.x = origin[i][0] + dx;
				member.y = origin[i][1] + dy;
			});
		};
		// the labels of the LAG bundles (`obstacles`: [{box: {left, right, top, bottom}, owners: [a, b]}]) are in the way too: a node is not moved onto a label (the ends of a bundle carry their label with them)
		const freeOne = node => !nodes.some(other => other !== node && overlaps(node, other, BOX_GAP))
			&& !obstacles.some(({box, owners}) => !owners.includes(node) && node.x - node.box.w / 2 < box.right + 2 && box.left - 2 < node.x + node.box.w / 2
				&& node.y + node.box.top < box.bottom + 2 && box.top - 2 < node.y + node.box.bottom);
		const free = node => members(node).every(freeOne);
		const moved = new Set();
		const gave_up = new Set();      // "line index|node": the pairs that could not be solved in this call
		// Moves `mover` (turned about `pivot`, then across `across`'s line) to a place with no crossing at all; `ok` is the extra condition of the pair. Returns true when it moved.
		const relocate = (mover, pivot, line, ok, allow_better) => {
			const now = score(mover);
			const origin = members(mover).map(member => [member.x, member.y]);
			const x0 = mover.x;
			const y0 = mover.y;
			const distance = Math.hypot(x0 - pivot.x, y0 - pivot.y);
			const angle = Math.atan2(y0 - pivot.y, x0 - pivot.x);
			let best = null;
			const candidate = (x, y) => {
				place(mover, x, y, origin);
				if (!inside(mover) || !free(mover) || !ok()) {
					return false;
				}
				const value = score(mover);
				if (value === 0) {
					return true;
				}
				if (allow_better && value < now && (best === null || value < best.value)) {
					best = {x, y, value};
				}

				return false;
			};
			for (const scale of [1, 1.15, 1.3, 1.5, 1.8, 2.2]) {
				for (let k = 1; k <= 90; k++) {
					for (const sign of [1, -1]) {
						const turned = angle + sign * k * 2 * Math.PI / 180;
						if (candidate(pivot.x + distance * scale * Math.cos(turned), pivot.y + distance * scale * Math.sin(turned))) {
							return true;
						}
					}
				}
			}
			const length = Math.hypot(line.b.x - line.a.x, line.b.y - line.a.y) || 1;
			const nx = -(line.b.y - line.a.y) / length;
			const ny = (line.b.x - line.a.x) / length;
			for (let offset = 16; offset <= 240; offset += 16) {
				for (const sign of [1, -1]) {
					if (candidate(x0 + nx * offset * sign, y0 + ny * offset * sign)) {
						return true;
					}
				}
			}
			// the last resort: any free place of the area, the nearest first
			const spots = [];
			for (let x = bounds.padding + mover.box.w / 2; x <= bounds.width - bounds.padding - mover.box.w / 2; x += 20) {
				for (let y = bounds.padding - mover.box.top; y <= bounds.height - bounds.padding - mover.box.bottom; y += 20) {
					spots.push([x, y, (x - x0) ** 2 + (y - y0) ** 2]);
				}
			}
			spots.sort((p, q) => p[2] - q[2]);
			// the nearest 400 places, then every n-th of the others (the whole area is looked at)
			const rest = spots.slice(400);
			const stride = Math.max(1, Math.ceil(rest.length / 300));
			for (const [x, y] of [...spots.slice(0, 400), ...rest.filter((spot, i) => i % stride === 0)]) {
				if (budget <= 0) {
					break;
				}
				if (candidate(x, y)) {
					return true;
				}
			}
			if (best !== null) {
				place(mover, best.x, best.y, origin);

				return true;
			}
			place(mover, x0, y0, origin);

			return false;
		};
		for (let step = 0; step < limit; step++) {
			const pair = linesThrough(nodes, lines, 2).find(({line, node}) => !gave_up.has(lines.indexOf(line) + '|' + nodes.indexOf(node)));
			if (!pair) {
				break;
			}
			if (budget <= 0) {
				break;
			}
			const {line, node} = pair;
			const key = lines.indexOf(line) + '|' + nodes.indexOf(node);
			const end = Math.hypot(node.x - line.a.x, node.y - line.a.y) <= Math.hypot(node.x - line.b.x, node.y - line.b.y) ? line.a : line.b;
			const clear = () => !lineCrossesNode(line, node, 2);
			if (!fixed(node) && relocate(node, end, line, () => true, true)) {
				moved.add(node);
			}
			else if (!fixed(line.a) && relocate(line.a, line.b, line, clear, true)) {
				moved.add(line.a);
			}
			else if (!fixed(line.b) && relocate(line.b, line.a, line, clear, true)) {
				moved.add(line.b);
			}
			else {
				gave_up.add(key);
			}
		}

		// what the search could not solve: the nodes are pushed off the lines a little at a time (the node away from the line, or, when it is fixed, the two ends of the line away from it), the
		// collision step runs after each push; the result is kept only when it has fewer crossings than before
		if (linesThrough(nodes, lines, 2).length > 0) {
			const saved = nodes.map(node => [node.x, node.y]);
			const before = linesThrough(nodes, lines, 2).length;
			for (let round = 0; round < 30; round++) {
				const pairs = linesThrough(nodes, lines, 2);
				if (!pairs.length) {
					break;
				}
				pairs.forEach(({line, node}) => {
					// the point of the line nearest to the middle of the box, and the push away from it
					const c = curve(line.a, line.b, line.index, line.count);
					const cy = node.y + (node.box.top + node.box.bottom) / 2;
					let nearest = c.at(0);
					let distance = Infinity;
					for (let i = 0; i <= 24; i++) {
						const point = c.at(i / 24);
						const d = Math.hypot(node.x - point.x, cy - point.y);
						if (d < distance) {
							distance = d;
							nearest = point;
						}
					}
					let ux = node.x - nearest.x;
					let uy = cy - nearest.y;
					const norm = Math.hypot(ux, uy);
					if (norm < 1) {
						const length = Math.hypot(line.b.x - line.a.x, line.b.y - line.a.y) || 1;
						ux = -(line.b.y - line.a.y) / length;
						uy = (line.b.x - line.a.x) / length;
					}
					else {
						ux /= norm;
						uy /= norm;
					}
					const reach = Math.abs(ux) * node.box.w / 2 + Math.abs(uy) * (node.box.bottom - node.box.top) / 2 + 6;
					const push = Math.max(6, Math.min(40, reach - distance + 4));
					if (!fixed(node)) {
						members(node).forEach(member => {
							member.x += ux * push;
							member.y += uy * push;
						});
						moved.add(node);
					}
					else {
						[line.a, line.b].forEach(end => {
							if (!fixed(end)) {
								end.x -= ux * push / 2;
								end.y -= uy * push / 2;
								moved.add(end);
							}
						});
					}
				});
				nodes.forEach(node => {
					if (!fixed(node)) {
						node.x = Math.max(bounds.padding + node.box.w / 2, Math.min(bounds.width - bounds.padding - node.box.w / 2, node.x));
						node.y = Math.max(bounds.padding - node.box.top, Math.min(bounds.height - bounds.padding - node.box.bottom, node.y));
					}
				});
				resolveOverlaps(nodes, bounds);
			}
			if (linesThrough(nodes, lines, 2).length >= before) {
				nodes.forEach((node, i) => {
					node.x = saved[i][0];
					node.y = saved[i][1];
				});
			}
		}

		// the last step: a random walk with a fixed seed (the same layout gives the same result): a crossed node, or an end of its line, is moved by a random step; the step is kept when the crossings
		// of the moved node do not grow (a step that changes nothing is kept too, so that the nodes can move round each other)
		if (linesThrough(nodes, lines, 2).length > 0) {
			let seed = seed_start;
			const random = () => {
				seed = (seed + 0x6D2B79F5) | 0;
				let t = Math.imul(seed ^ (seed >>> 15), 1 | seed);
				t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t;

				return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
			};
			const saved = nodes.map(node => [node.x, node.y]);
			const before = linesThrough(nodes, lines, 2).length;
			budget = 4000 * effort;
			for (let step = 0; step < 600 * effort && budget > 0; step++) {
				const pairs = linesThrough(nodes, lines, 2);
				if (!pairs.length) {
					break;
				}
				const {line, node} = pairs[Math.floor(random() * pairs.length)];
				const movers = [node, line.a, line.b].filter(candidate => !fixed(candidate));
				if (!movers.length) {
					continue;
				}
				const mover = movers[Math.floor(random() * movers.length)];
				const now = score(mover);
				const origin = members(mover).map(member => [member.x, member.y]);
				const angle = random() * 2 * Math.PI;
				const radius = 12 + random() * 110;
				place(mover, origin[0][0] + Math.cos(angle) * radius, origin[0][1] + Math.sin(angle) * radius, origin);
				if (!inside(mover) || !free(mover) || score(mover) > now) {
					place(mover, origin[0][0], origin[0][1], origin);
				}
				else {
					moved.add(mover);
				}
			}
			if (linesThrough(nodes, lines, 2).length >= before) {
				nodes.forEach((node, i) => {
					node.x = saved[i][0];
					node.y = saved[i][1];
				});
			}
		}

		return moved.size;
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
	 * The width of the label of a LAG bundle (10 px font): ONE function for the place of the label (`placeLabel`), the room that a layout makes for it (`makeLabelRoom`) and the room of a ring
	 * (`placeRadial`) (§12.3, 2.79). `measure(text, font)` is the real measure of the page (the same canvas font as the node labels); without it the estimate of `approxMeasure`.
	 */
	function labelWidth(text, measure = approxMeasure) {
		return measure(String(text), 10);
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
		// 2.80 (§9.7, §12.1): a manual Device is drawn also without a link
		return ctx.has_link || ctx.show_unlinked || ctx.only_duplicates || ctx.is_focus || node.manual === true;
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
			bundled.set(indexes[0], {...first, type: 'lag_bundle', members, count: members.length, label, lag_name: names.join(' ↔ '), edge_id: null});
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

	/**
	 * The tooltip of a LAG bundle (§12.3, 2.73): the first line "LAG <name> ×<N>: <node A> ↔ <node B>", then one line per member: "<port A> ↔ <port B> · <status> · in <x> / out <y> of <speed>" (the
	 * load of side A; the link panel has both sides). Text only (§13): the caller writes it with text(). `words` = {in, out, of, status: {up, down, disabled}, speed: bps -> text}.
	 */
	function bundleTooltip(bundle, words) {
		const name = bundle.lag_name ? bundle.lag_name + ' ' : '';
		const node = side => side?.name ?? side?.label ?? '';
		const lines = [`LAG ${name}×${bundle.members.length}: ${node(bundle.source)} ↔ ${node(bundle.target)}`];
		bundle.members.forEach(member => {
			const status = side => (side ? (words.status[side] ?? side) : null);
			const a = status(member.source_status);
			const b = status(member.target_status);
			const state = a !== null && b !== null && a !== b ? `${a} / ${b}` : (a ?? b);
			const load = member.source_load;
			const rate = value => (value === null || value === undefined ? '–' : (value === 0 ? '0 bps' : words.speed(value)));
			const has_load = load && (load.in !== null && load.in !== undefined || load.out !== null && load.out !== undefined);
			const parts = [`${member.source_port ?? '?'} ↔ ${member.target_port ?? '?'}`];
			if (state !== null) {
				parts.push(state);
			}
			if (has_load) {
				parts.push(`${words.in} ${rate(load.in)} / ${words.out} ${rate(load.out)}` + (load.speed ? ` ${words.of} ${words.speed(load.speed)}` : ''));
			}
			lines.push(parts.join(' · '));
		});

		return lines.join('\n');
	}

	// ---- the side panel of a node (§12.5 "Structure of a node panel", 2.75, task 094) ----

	/**
	 * The sections of a node panel in their order: Host: Problems, Links, Ports, Identity (folded); Device without association: Identity (with Addresses, open), Links, Ports; Proxy: Links, Ports,
	 * Identity (folded). Links, Ports and Identity are the Device's: a node without a Device has Problems only (a Host) or the header only (a Proxy).
	 *
	 * @return array  [{id, open}]
	 */
	function panelSections(kind, has_device) {
		if (kind === 'host') {
			return has_device ? [{id: 'problems', open: true}, {id: 'links', open: true}, {id: 'ports', open: true}, {id: 'identity', open: false}] : [{id: 'problems', open: true}];
		}
		if (kind === 'proxy') {
			return has_device ? [{id: 'links', open: true}, {id: 'ports', open: true}, {id: 'identity', open: false}] : [];
		}

		return [{id: 'identity', open: true}, {id: 'links', open: true}, {id: 'ports', open: true}];
	}

	/**
	 * The summary line "<N> links to <M> neighbors · <P> ports (<U> up)" (§12.5, 2.77); a part that is 0 is not shown, and "(<U> up)" goes with the ports. `words`: {link, links, neighbor, neighbors, to,
	 * port, ports, up}, each with %1$s ("to" with %1$s and %2$s).
	 */
	function summaryLine(counts, words) {
		const part = (n, one, many) => (n === 1 ? one : many).replace('%1$s', String(n));
		const parts = [];
		if (counts.links > 0) {
			parts.push(words.to.replace('%1$s', part(counts.links, words.link, words.links)).replace('%2$s', part(counts.neighbors, words.neighbor, words.neighbors)));
		}
		if (counts.ports > 0) {
			parts.push(part(counts.ports, words.port, words.ports) + (counts.up > 0 ? ' (' + words.up.replace('%1$s', String(counts.up)) + ')' : ''));
		}

		return parts.join(' · ');
	}

	/**
	 * The tooltip of a port-level link (§12.3, 2.77): "<A> <port> ↔ <B> <port>"; "<source> · <status A> / <status B>"; one line per side "<node>: in <x> / out <y> of <speed> (<p> %)"; then the
	 * marks, each on its own line. Text only (§13). `link` has source/target (nodes with name), source_port/target_port, source_status/target_status, source_load/target_load, source_text, `marks` (the
	 * caller's texts). `words`: {in, out, of, status: {up, down, disabled}, speed: bps -> text}.
	 */
	function linkTooltip(link, words) {
		const lines = [`${link.source?.name ?? '?'} ${link.source_port ?? '?'} ↔ ${link.target?.name ?? '?'} ${link.target_port ?? '?'}`];
		const state = side => (side ? (words.status[side] ?? side) : null);
		const statuses = [state(link.source_status), state(link.target_status)].filter(Boolean).join(' / ');
		lines.push([link.source_text, statuses].filter(Boolean).join(' · '));
		const rate = value => (value === null || value === undefined ? '–' : (value === 0 ? '0 bps' : words.speed(value)));
		[[link.source, link.source_load], [link.target, link.target_load]].forEach(([node, load]) => {
			if (load && ((load.in !== null && load.in !== undefined) || (load.out !== null && load.out !== undefined))) {
				lines.push(`${node?.name ?? '?'}: ${words.in} ${rate(load.in)} / ${words.out} ${rate(load.out)}` + (load.speed ? ` ${words.of} ${words.speed(load.speed)}` : '')
					+ (load.percent !== null && load.percent !== undefined ? ` (${load.percent} %)` : ''));
			}
		});
		(link.marks ?? []).forEach(mark => lines.push(mark));

		return lines.filter(line => line !== '').join('\n');
	}

	/** The load of a port as "in <x> % / out <y> %" (§4.8): % of the speed, one decimal at most; "—" without items, without speed, or when neither value is known. */
	function loadText(load, words = {in: 'in', out: 'out'}) {
		if (!load || !(load.speed > 0) || ((load.in === null || load.in === undefined) && (load.out === null || load.out === undefined))) {
			return '—';
		}
		const percent = value => (value === null || value === undefined ? '—' : String(Math.round(1000 * value / load.speed) / 10));

		return `${words.in} ${percent(load.in)} % / ${words.out} ${percent(load.out)} %`;
	}

	/**
	 * The near side of the load of the links of a Device (§4.8, 2.88): `neighbors.get` gives only the far side; the near side is on the ports of `ports.get` (a port of a link has `edge_id` and `load`).
	 * Puts it into the link (its source side when the Device is the source of the link, else the target side), so that the tooltip and the panel of a link have both sides as before.
	 */
	function applyNearLoads(links, ports, node_id) {
		const by_edge = new Map();
		ports.forEach(port => {
			if (port.edge_id !== null && port.edge_id !== undefined && Object.prototype.hasOwnProperty.call(port, 'load')) {
				by_edge.set(String(port.edge_id), port.load ?? null);
			}
		});
		links.forEach(link => {
			if (link.type !== 'physical_link' || link.edge_id === null || link.edge_id === undefined || !by_edge.has(String(link.edge_id))) {
				return;
			}
			if (String(link.source) === String(node_id)) {
				link.source_load = by_edge.get(String(link.edge_id));
			}
			else if (String(link.target) === String(node_id)) {
				link.target_load = by_edge.get(String(link.edge_id));
			}
		});
	}

	/**
	 * Line 2 of a Links row (§12.5, 2.84): a part with no value (null, undefined or empty text) is left out together with its separator ("lan0 ↔ 1/1/3 · manual").
	 */
	function joinParts(parts, separator = ' · ') {
		return parts.filter(part => part !== null && part !== undefined && part !== '').join(separator);
	}

	/**
	 * The problems of a Host for the panel: the count by severity (the highest first) and the 5 newest (the smallest age first). `problems` = [{severity_name, severity, color, name, age}].
	 *
	 * @return object  {total, by_severity: [{severity_name, severity, color, count}], newest}
	 */
	function problemSummary(problems) {
		const by = new Map();
		problems.forEach(problem => {
			const key = problem.severity_name ?? String(problem.severity);
			const entry = by.get(key) ?? {severity_name: problem.severity_name, severity: Number(problem.severity ?? 0), color: problem.color ?? null, count: 0};
			entry.count++;
			by.set(key, entry);
		});

		return {
			total: problems.length,
			by_severity: [...by.values()].sort((a, b) => b.severity - a.severity),
			newest: [...problems].sort((a, b) => a.age - b.age).slice(0, 5)
		};
	}

	/**
	 * The rows of the Links section: one row for each link of the Device and one row for each LAG bundle (the same rule as the graph, `bundleLinks`: the same neighbor, the same LAG on the side
	 * where it is known, the same channels). `ports` = the entries of the Connected ports of /ports plus the links that /ports does not give (a superseded link), each
	 * {port_id, edge_id, port, remote_port, remote_device_id, connected_to, status, source, load, stale, last_seen, superseded_at, lag_id, lag, remote_lag_id, remote_lag, device_link_id}.
	 * Order: the natural order of the local port (the first member of a bundle).
	 *
	 * @return array  [{type: 'link', entry} | {type: 'bundle', label, lag_name, members: [entry], count}]
	 */
	function linkRows(entries) {
		const links = entries.map((entry, i) => ({
			type: entry.device_link_id ? 'device_link' : 'physical_link', edge_id: i, source: 'near', target: String(entry.remote_device_id ?? entry.connected_to ?? '?'),
			source_lag_id: entry.lag_id ?? null, source_lag: entry.lag ?? null, target_lag_id: entry.remote_lag_id ?? null, target_lag: entry.remote_lag ?? null,
			discovered_via: entry.source === 'Manual' ? 'manual' : 'lldp', stale: Boolean(entry.stale), superseded_at: entry.superseded_at ?? null, severity: entry.severity ?? null, color: entry.color ?? null, entry
		}));
		const rows = bundleLinks(links).map(line => (line.type === 'lag_bundle'
			? {type: 'bundle', label: line.label, lag_name: line.lag_name, members: line.members.map(member => member.entry), count: line.members.length}
			: {type: 'link', entry: line.entry}));
		const natural = (a, b) => String(a ?? '').localeCompare(String(b ?? ''), undefined, {numeric: true, sensitivity: 'base'});
		const first = row => (row.type === 'bundle' ? row.members[0] : row.entry);

		return rows.sort((a, b) => natural(first(a).port, first(b).port));
	}

	return {applyNearLoads, joinParts, linesThrough, clearThroughLines, lineCrossesNode, labelWidth, linkTooltip, panelSections, summaryLine, loadText, problemSummary, linkRows, makeLabelRoom, bundleTooltip, placeLabel, clearSector, arcPlace, bundleLinks, runInfoParts, isDrawn, ICON_DEFS, CLASS_ORDER, iconOf, iconMarkup, classCounts, classText, MAX_LABEL_PX, SIZES, BOX_GAP, approxMeasure, runInfoText, cutMiddle, sizeOf, layoutNode, overlappingPairs, resolveOverlaps, distances, placeRadial, curve, parallelIndex};
});
