'use strict';
// Tests of ui/js/topology.graph.js (task 090, model 2.70 §12.1 "Node placement", §12.2 "Size", "Label placement", §12.3 "Parallel links", FR-C V-21). Run: node test_topology_graph.js
const assert = require('assert');
const G = require('../../ui/js/topology.graph.js');
let n = 0;
const ok = (condition, text, detail) => {
	assert.ok(condition, text + (detail === undefined ? '' : ' ' + JSON.stringify(detail)));
	n++;
	console.log('  ok: ' + text);
};

// ---- the line "<N> reporters have new data since the last run" (FR-C V-21) ----
const t = {one: '%1$s reporter has new data since the last run', many: '%1$s reporters have new data since the last run', last: 'Last run: %1$s'};
ok(G.runInfoText(0, '10:00', t) === 'Last run: 10:00', 'N = 0: only the time of the last run');
ok(G.runInfoText(1, '10:00', t) === '1 reporter has new data since the last run — Last run: 10:00', 'N = 1: "1 reporter has"');
ok(G.runInfoText(2, '10:00', t) === '2 reporters have new data since the last run — Last run: 10:00', 'N = 2: "2 reporters have"');
ok(G.runInfoText(0, '', t) === '', 'N = 0 and no run yet: nothing');

{
	const p1 = G.runInfoParts(1, '10:00', t);
	ok(p1.note === '1 reporter has new data since the last run' && p1.before === 'Last run: ' && p1.time === '10:00', 'the line in parts: the time is its own part (the link to the last run view)', p1);
	ok(G.runInfoParts(0, '', t).time === '' && G.runInfoParts(0, '10:00', t).note === '', 'N = 0: no note; no run yet: no time');
}
// ---- the label: cut in the middle, at most 160 px ----
const m = G.approxMeasure;
const long_name = 'SW19-09-POE-6000-24G-with-a-very-long-suffix-of-the-name';
const cut = G.cutMiddle(long_name, G.MAX_LABEL_PX, 12);
ok(m(cut, 12) <= G.MAX_LABEL_PX && cut.includes('…') && cut.startsWith('SW19-09') && cut.endsWith('name'), 'a long label is cut in the middle, start and end stay', cut);
ok(G.cutMiddle('SW12', G.MAX_LABEL_PX, 12) === 'SW12', 'a short label is not changed');
ok(G.cutMiddle('SW19-09-POE-6000-24G', 90, 12).includes('…'), 'the cut follows the width');

// ---- sizes (§12.2) ----
ok(G.sizeOf({type: 'host'}, 1) === 'large' && G.sizeOf({type: 'proxy'}, 0) === 'large', 'Host and Proxy: large');
ok(G.sizeOf({type: 'device'}, 2) === 'large' && G.sizeOf({type: 'device'}, 1) === 'small' && G.sizeOf({type: 'device'}, 0) === 'small', 'Device: large with two or more links, else small');
ok(G.sizeOf({type: 'group'}, 1) === 'small', 'a leaf group node: small');
const big = G.layoutNode({label: 'SW12'}, 'large');
const small = G.layoutNode({label: 'SW12'}, 'small');
ok(big.shape_w > small.shape_w && big.font > small.font, 'two sizes: the small node has a smaller shape and font');
ok(big.box.h > big.shape_h && big.box.bottom > big.shape_h / 2, 'the collision box includes the label under the shape');

// ---- no overlap (boxes with labels) ----
const make = (count, seed) => {
	let s = seed;
	const rnd = () => (s = (s * 1664525 + 1013904223) % 4294967296) / 4294967296;
	return Array.from({length: count}, (_, i) => G.layoutNode({id: String(i), label: 'node-' + i + '-with-a-label', x: 400 + rnd() * 60, y: 300 + rnd() * 60}, i % 3 === 0 ? 'large' : 'small'));
};
for (const [w, h] of [[1500, 900], [1280, 800]]) {
	const nodes = make(40, 7);
	ok(G.overlappingPairs(nodes).length > 0, 'the start is crowded (' + w + ' × ' + h + ')');
	const fits = G.resolveOverlaps(nodes, {width: w, height: h, padding: 20});
	ok(fits && G.overlappingPairs(nodes).length === 0, 'after the collision step no box overlaps another (' + w + ' × ' + h + ')');
	ok(nodes.every(node => node.x >= 0 && node.y >= 0 && node.x <= w && node.y <= h), 'all nodes stay inside the area');
}
{
	const nodes = make(6, 3);
	nodes[0].fx = nodes[0].x;
	nodes[0].fy = nodes[0].y;
	const before = [nodes[0].x, nodes[0].y];
	G.resolveOverlaps(nodes, {width: 1200, height: 800, padding: 20});
	ok(nodes[0].x === before[0] && nodes[0].y === before[1] && G.overlappingPairs(nodes).length === 0, 'a dragged (fixed) node stays where it is dropped, the others move away');
}
{
	const nodes = make(400, 5);
	ok(G.resolveOverlaps(nodes, {width: 400, height: 300, padding: 10}) === false, 'too small an area for the boxes: false (the page makes the area larger)');
}

// ---- radial placement with a focus host ----
{
	const ids = ['f', 'a', 'b', 'c', 'a1', 'a2', 'b1', 'x'];
	const nodes = ids.map(id => G.layoutNode({id, label: 'node ' + id}, id.length === 1 ? 'large' : 'small'));
	const links = [['f', 'a'], ['f', 'b'], ['f', 'c'], ['a', 'a1'], ['a', 'a2'], ['b', 'b1']].map(([source, target]) => ({source, target}));
	const radius = G.placeRadial(nodes, links, 'f', {x: 600, y: 400});
	const by = new Map(nodes.map(node => [node.id, node]));
	const r = id => Math.hypot(by.get(id).x - 600, by.get(id).y - 400);
	ok(r('f') === 0, 'the focus host is in the centre');
	ok(Math.abs(r('a') - r('b')) < 1e-6 && Math.abs(r('a') - r('c')) < 1e-6, 'nodes at distance 1 are on one ring');
	ok(r('a1') > r('a') && Math.abs(r('a1') - r('b1')) < 1e-6, 'nodes at distance 2 are on ring 2, outside ring 1');
	ok(r('x') > r('a1'), 'a node with no path to the focus host is on the outer ring', [r('x'), r('a1')]);
	ok(G.overlappingPairs(nodes).length === 0, 'no box overlaps another on the rings');
	ok(radius >= r('x') - 1e-6, 'the radius returned is that of the outer ring');
	const dist = G.distances(ids, links, 'f');
	ok(dist.get('a1') === 2 && !dist.has('x'), 'distance counts links only');
}
{
	const nodes = Array.from({length: 61}, (_, i) => G.layoutNode({id: 'n' + i, label: 'a-long-device-name-' + i}, 'small'));
	const links = nodes.slice(1).map(node => ({source: 'n0', target: node.id}));
	G.placeRadial(nodes, links, 'n0', {x: 0, y: 0});
	ok(G.overlappingPairs(nodes).length === 0, '60 nodes at distance 1: the ring grows until the boxes fit');
}

// ---- parallel links ----
{
	const s = {x: 0, y: 0};
	const tt = {x: 200, y: 0};
	const one = G.curve(s, tt, 0, 1);
	ok(one.d === 'M0,0L200,0', 'a single link is a straight line');
	const a = G.curve(s, tt, 0, 2);
	const b = G.curve(s, tt, 1, 2);
	ok(a.d !== b.d && a.d.includes('Q') && b.d.includes('Q'), 'two links between the same nodes are two curved lines');
	ok(Math.abs(Math.abs(a.mid.y - b.mid.y) - 16) < 1e-6, 'a fixed distance between the lines (16 px at the middle)', [a.mid, b.mid]);
	const links = [{source: {id: 1}, target: {id: 2}}, {source: {id: 2}, target: {id: 1}}, {source: {id: 1}, target: {id: 3}}];
	const idx = G.parallelIndex(links);
	ok(idx[0].count === 2 && idx[1].count === 2 && idx[0].index === 0 && idx[1].index === 1 && idx[2].count === 1, 'the direction of a link does not matter for the pair');
}
// ---- the icon by device class (§12.2, 2.71) ----
{
	ok(G.iconOf({type: 'proxy'}) === 'proxy', 'a Proxy keeps its icon');
	ok(G.iconOf({type: 'device', device_class: 'switch'}) === 'switch' && G.iconOf({type: 'device'}) === 'generic', 'a Device: its class; no class: generic');
	ok(G.iconOf({type: 'host', device: {device_class: 'router'}}) === 'router', 'a Host has the class of its Device');
	ok(G.iconOf({type: 'host', device: null}) === 'generic' && G.iconOf({type: 'host'}) === 'generic', 'a Host without a Device: generic');
	ok(G.iconOf({type: 'device', device_class: '<img onerror=x>'}) === 'generic' && G.iconOf({type: 'device', device_class: 'proxy'}) === 'generic', 'a class that is not one of ours (markup, or the Proxy icon) is the generic icon');
	ok(G.iconOf({type: 'group'}) === null, 'a group node has no icon');
	ok(['access_point', 'phone', 'switch', 'router', 'station', 'generic'].every(name => G.ICON_DEFS[name].length >= 2 && G.iconMarkup(name, '#000', 0.8).startsWith('<g ')), 'one icon for each class and a generic icon, also at the scale of a small node');
	const names = {access_point: ['%1$s access point', '%1$s access points'], phone: ['%1$s phone', '%1$s phones'], switch: ['%1$s switch', '%1$s switches'], router: ['%1$s router', '%1$s routers'],
		station: ['%1$s station', '%1$s stations'], generic: ['%1$s other device', '%1$s other devices']};
	const counts = G.classCounts([...Array(9).fill({device_class: 'access_point'}), ...Array(6).fill({device_class: 'station'})]);
	ok(G.classText(counts, names) === '9 access points, 6 stations', 'the tooltip of a group counts by class: "9 access points, 6 stations"', G.classText(counts, names));
	ok(G.classText(G.classCounts([{device_class: 'switch'}, {}]), names) === '1 switch, 1 other device', 'one of a class and a device without a class');
}
// ---- nodes without a drawn link (§12.1, FR-C V-1, 091) ----
{
	const none = {has_link: false, show_unlinked: false, only_duplicates: false, is_focus: false};
	ok(G.isDrawn({type: 'host', device: null}, none) === false, 'a Host without a Device and without a drawn link: not drawn');
	ok(G.isDrawn({type: 'host', device: null}, {...none, show_unlinked: true}) === true, 'a Host without a Device and without a link: drawn with the switch');
	ok(G.isDrawn({type: 'host', device: null}, {...none, is_focus: true}) === true, 'a Host without a Device and without a link: drawn as the focus host');
	ok(G.isDrawn({type: 'host', device: null}, {...none, only_duplicates: true}) === true, 'a node without a link: drawn with "Possible duplicates only"');
	ok(G.isDrawn({type: 'host', device: {}}, none) === false && G.isDrawn({type: 'device'}, none) === false, 'a Host with a Device and a Device without a drawn link: not drawn');
	ok(G.isDrawn({type: 'device', manual: true}, none) === true && G.isDrawn({type: 'device', manual: false}, none) === false, 'a manual Device is drawn without a link (§12.1, 2.80); a discovered one is not');
	ok(G.isDrawn({type: 'host'}, {...none, has_link: true}) === true && G.isDrawn({type: 'device'}, {...none, has_link: true}) === true, 'a node with a drawn link: drawn');
	ok(G.isDrawn({type: 'proxy'}, none) === true && G.isDrawn({type: 'group'}, none) === true, 'a Proxy (by the view scope) and a group node: drawn');
}
// ---- LAG bundle (§12.3, 2.72) ----
{
	const link = (id, extra = {}) => ({type: 'physical_link', edge_id: id, source: 'A', target: 'B', source_lag_id: null, source_lag: null, target_lag_id: '9', target_lag: 'Trk1',
		discovered_via: 'lldp', stale: false, superseded_at: null, severity: null, color: null, ...extra});
	const kinds = lines => lines.map(l => l.type === 'lag_bundle' ? 'bundle×' + l.count : 'link');
	let lines = G.bundleLinks([link(1), link(2)]);
	ok(kinds(lines).join() === 'bundle×2' && lines[0].label === 'Trk1 ×2' && lines[0].members.length === 2, '2 members with the same channels: one line "Trk1 ×2"', lines.map(l => l.label));
	lines = G.bundleLinks([link(1), link(2, {stale: true})]);
	ok(kinds(lines).join() === 'link,link', 'one member stale: two lines');
	lines = G.bundleLinks([link(1), link(2, {discovered_via: 'manual'})]);
	ok(kinds(lines).join() === 'link,link', 'one member manual: two lines');
	lines = G.bundleLinks([link(1), link(2, {severity: 3, color: 'e97659'})]);
	ok(kinds(lines).join() === 'link,link', 'a member with another severity colour: two lines');
	lines = G.bundleLinks([link(1), link(2), link(3, {stale: true})]);
	ok(kinds(lines).join() === 'bundle×2,link', 'two alike and one stale: a bundle of two and an own line');
	lines = G.bundleLinks([link(1), link(2, {target_lag_id: '10', target_lag: 'Trk2'}), link(3, {target_lag_id: '10', target_lag: 'Trk2'}), link(4)]);
	ok(kinds(lines).join() === 'bundle×2,bundle×2' && lines.map(l => l.label).join() === 'Trk1 ×2,Trk2 ×2', 'members of two LAGs between the same nodes: two bundles, not one', lines.map(l => l.label));
	lines = G.bundleLinks([link(1, {target_lag_id: null, target_lag: null}), link(2, {target_lag_id: null, target_lag: null})]);
	ok(kinds(lines).join() === 'link,link', 'no LAG on either side: separate curves as now');
	lines = G.bundleLinks([link(1, {source_lag_id: '5', source_lag: 'lag16'}), link(2, {source_lag_id: '5', source_lag: 'lag16'})]);
	ok(lines.length === 1 && lines[0].label === 'lag16 ↔ Trk1 ×2', 'a LAG on both sides: "lag16 ↔ Trk1 ×2"', lines.map(l => l.label));
	lines = G.bundleLinks([link(1), link(2)], l => l.edge_id !== 2);
	ok(kinds(lines).join() === 'link,link', 'a link that the caller keeps out (a contradicted manual link) is not bundled');
	lines = G.bundleLinks([link(1, {type: 'device_link'}), link(2, {type: 'device_link'})]);
	ok(kinds(lines).join() === 'link,link', 'device-level links are not touched (their own bundling stays)');
	const evil = '<img src=x onerror=alert(1)>';
	lines = G.bundleLinks([link(1, {target_lag: evil}), link(2, {target_lag: evil})]);
	ok(lines[0].label === evil + ' ×2', 'a LAG name with HTML is returned as text (the page writes it with text(), §13)');
	lines = G.bundleLinks([link(1), link(2), link(3, {source: 'C', target: 'B'}), link(4, {source: 'C', target: 'B'})]);
	ok(kinds(lines).join() === 'bundle×2,bundle×2', 'two node pairs: one bundle for each');
}
// ---- ring order by barycentre (2.72) and the arc of an unfolded group ----
{
	const crossings = (nodes, links) => {
		const by = new Map(nodes.map(n => [n.id, n]));
		const o = (p, q, r) => Math.sign((q.x - p.x) * (r.y - p.y) - (q.y - p.y) * (r.x - p.x));
		let count = 0;
		for (let i = 0; i < links.length; i++) {
			for (let j = i + 1; j < links.length; j++) {
				const [a, b, c, d] = [by.get(links[i].source), by.get(links[i].target), by.get(links[j].source), by.get(links[j].target)];
				if ([a, b].some(n => n === c || n === d)) {
					continue;
				}
				if (o(a, b, c) !== o(a, b, d) && o(c, d, a) !== o(c, d, b)) {
					count++;
				}
			}
		}
		return count;
	};
	// a tree: the focus f, 8 nodes of ring 1 with 2 children each; the ids are chosen so that the order by id puts the children of one parent far apart
	const nodes = [{id: 'f'}];
	const links = [];
	for (let i = 0; i < 8; i++) {
		nodes.push({id: 'p' + i});
		links.push({source: 'f', target: 'p' + i});
		for (let k = 0; k < 2; k++) {
			nodes.push({id: 'c' + (k * 8 + (i * 5) % 8)});
			links.push({source: 'p' + i, target: 'c' + (k * 8 + (i * 5) % 8)});
		}
	}
	nodes.forEach(n => G.layoutNode(n, 'small'));
	G.placeRadial(nodes, links, 'f', {x: 0, y: 0});
	ok(crossings(nodes, links) === 0, 'ring order by barycentre: a tree of 8 branches has no crossing of its lines', crossings(nodes, links));
	ok(G.overlappingPairs(nodes).length === 0, 'and no box overlaps another');
	const by = new Map(nodes.map(n => [n.id, n]));
	const angle = n => Math.atan2(n.y, n.x);
	const spread = id => Math.abs(Math.atan2(Math.sin(angle(by.get('c' + id)) - angle(by.get('p' + 0))), Math.cos(angle(by.get('c' + id)) - angle(by.get('p' + 0)))));
	ok(links.filter(l => l.source.startsWith('p')).every(l => {
		const d = Math.abs(Math.atan2(Math.sin(angle(by.get(l.target)) - angle(by.get(l.source))), Math.cos(angle(by.get(l.target)) - angle(by.get(l.source)))));
		return d < 0.6;
	}), 'a node of ring 2 is near the angle of its parent (barycentre)');
	// the arc
	const parent = G.layoutNode({id: 'P', x: 0, y: 0, label: 'SW18-09-ACC-2530-48G'}, 'large');
	const leaves = Array.from({length: 6}, (_, i) => G.layoutNode({id: 'L' + i, label: 'ZBX-5CD' + i + 'AAAA'}, 'small'));
	const placed = G.arcPlace(parent, leaves, [0, Math.PI / 2, Math.PI]);      // other lines to the right, down and left: the largest free angle is the top
	placed.forEach(({node, x, y}) => {
		node.x = x;
		node.y = y;
	});
	ok(placed.every(({y}) => y < 0), 'the leaves are in the largest free angle (here: above the parent, away from the lines to the right, down and left)');
	const angles = placed.map(({node}) => Math.atan2(node.y, node.x));
	ok(angles.every((a, i) => i === 0 || a > angles[i - 1]), 'in the given order (by the local port): the angles grow along the arc', angles);
	ok(G.overlappingPairs(leaves, 0).length === 0 && !leaves.some(n => G.overlappingPairs([parent, n], 0).length), 'no leaf overlaps another or the parent');
	const radius = n => Math.hypot(n.x, n.y);
	ok(Math.max(...leaves.map(radius)) - Math.min(...leaves.map(radius)) < 1e-6, 'all leaves are at one radius (an arc)');
	const many = Array.from({length: 15}, (_, i) => G.layoutNode({id: 'M' + i, label: 'ZBX-5CD' + i + 'AAAA'}, 'small'));
	const placed_many = G.arcPlace(parent, many, [0]);
	placed_many.forEach(({node, x, y}) => {
		node.x = x;
		node.y = y;
	});
	ok(radius(placed_many[0].node) > radius(placed[0].node) && G.overlappingPairs(many, 0).length === 0, 'the radius grows with the number of leaves, and they still fit', [radius(placed_many[0].node), radius(placed[0].node)]);
	// a node in the sector of the arc is turned out of it
	{
		const p = G.layoutNode({id: 'P', x: 0, y: 0, label: 'parent'}, 'large');
		const ls = Array.from({length: 4}, (_, i) => G.layoutNode({id: 'L' + i, label: 'leaf' + i}, 'small'));
		G.arcPlace(p, ls, [Math.PI / 2]).forEach(({node, x, y}) => { node.x = x; node.y = y; });
		const mean = Math.atan2(ls.reduce((s, n) => s + n.y, 0), ls.reduce((s, n) => s + n.x, 0));
		const r = Math.max(...ls.map(n => Math.hypot(n.x, n.y)));
		const blocker = G.layoutNode({id: 'B', x: Math.cos(mean) * r * 0.6, y: Math.sin(mean) * r * 0.6, label: 'blocker'}, 'large');
		const hits = n => ls.some(l => { for (let t = 0.05; t < 0.96; t += 0.05) { const x = l.x * t, y = l.y * t; if (x > n.x - n.box.w / 2 && x < n.x + n.box.w / 2 && y > n.y + n.box.top && y < n.y + n.box.bottom) { return true; } } return false; });
		ok(hits(blocker), 'start: a node in the sector has a leaf line through its box');
		G.clearSector(p, ls, [blocker]);
		ok(!hits(blocker), 'after clearSector the lines of the leaves do not cross it', [blocker.x, blocker.y]);
		const dragged = G.layoutNode({id: 'D', x: Math.cos(mean) * r * 0.6, y: Math.sin(mean) * r * 0.6, fx: 1, fy: 1, label: 'dragged'}, 'large');
		const before = [dragged.x, dragged.y];
		G.clearSector(p, ls, [dragged]);
		ok(dragged.x === before[0] && dragged.y === before[1], 'a dragged (fixed) node stays');
	}
	const lone = G.arcPlace(parent, [G.layoutNode({id: 'x', label: 'one'}, 'small')], []);
	ok(lone.length === 1 && lone[0].y < 0, 'no other line: the arc is above the parent');
}
// ---- the place of the label of a LAG bundle (§12.3, 2.73) ----
{
	const at = u => ({x: 100 * u, y: 0});      // a line of 100 px
	let place = G.placeLabel(at, 40, []);
	ok(place.free && place.u === 0.5 && place.x === 50 && place.y === -4, 'a free line: the label is in the middle', place);
	// an obstacle over the middle (a node box 45..60): the nearest free point on one side, within 30 to 70 %
	const obstacle = {left: 45, right: 60, top: -20, bottom: 20};
	place = G.placeLabel(at, 20, [obstacle]);
	ok(place.free && place.u >= 0.3 - 1e-9 && place.u <= 0.7 + 1e-9 && (place.box.right <= 45 || place.box.left >= 60), 'a node at the middle: the label moves to the nearest free point within 30 to 70 %', place);
	// the free point nearest to the middle: the next point towards the middle touches the node
	const next = place.u + (place.u < 0.5 ? 0.01 : -0.01);
	const next_box = {left: 100 * next - 14, right: 100 * next + 14};
	ok(next_box.left < obstacle.right && obstacle.left < next_box.right, 'the next point towards the middle touches the node', {u: place.u});
	// another label placed before: avoided
	const before = G.placeLabel(at, 6, []);
	place = G.placeLabel(at, 6, [before.box]);
	ok(place.free && (place.box.right <= before.box.left || place.box.left >= before.box.right), 'a label placed before is avoided');
	// no free point: the middle, and free is false (the overlap check lists it)
	place = G.placeLabel(at, 20, [{left: -50, right: 150, top: -50, bottom: 50}]);
	ok(!place.free && place.u === 0.5, 'no free point: the middle and "not free"', place);
	// a curved (parallel) line
	const curved = G.curve({x: 0, y: 0}, {x: 100, y: 0}, 0, 2).at;
	const mid = G.curve({x: 0, y: 0}, {x: 100, y: 0}, 0, 2).mid;
	ok(Math.abs(curved(0.5).x - mid.x) < 1e-9 && Math.abs(curved(0.5).y - mid.y) < 1e-9 && curved(0).x === 0 && curved(1).x === 100, 'the point at 50 % of a curve is its middle; 0 and 1 are the ends');
}
// ---- rings leave a free place for the label of a LAG link (2.73) ----
{
	const focus = G.layoutNode({id: 'F', label: 'SW12-10-CORE-6000-48G'}, 'large');
	const near = G.layoutNode({id: 'N', label: 'SW18-09-ACC-2530-48G'}, 'large');
	const links = [{type: 'physical_link', source: 'F', target: 'N', source_lag_id: null, source_lag: null, target_lag_id: '9', target_lag: 'Trk1'}];
	G.placeRadial([focus, near], links, 'F', {x: 0, y: 0});
	const box = node => ({left: node.x - node.box.w / 2, right: node.x + node.box.w / 2, top: node.y + node.box.top, bottom: node.y + node.box.bottom});
	const label = G.placeLabel(u => ({x: focus.x + (near.x - focus.x) * u, y: focus.y + (near.y - focus.y) * u}), ('Trk1 ×2'.length * 6), [box(focus), box(near)]);
	ok(label.free, 'a LAG link between the focus node and ring 1: the label has a free place on the line', label);
	const plain = [G.layoutNode({id: 'F', label: 'SW12-10-CORE-6000-48G'}, 'large'), G.layoutNode({id: 'N', label: 'SW18-09-ACC-2530-48G'}, 'large')];
	const r_plain = G.placeRadial(plain, [{type: 'physical_link', source: 'F', target: 'N'}], 'F', {x: 0, y: 0});
	ok(Math.hypot(near.x, near.y) >= r_plain, 'the ring with a LAG link is not smaller than without', [Math.hypot(near.x, near.y), r_plain]);
}
// ---- the tooltip of a LAG bundle (§12.3, 2.73) ----
{
	const words = {in: 'in', out: 'out', of: 'of', status: {up: 'Up', down: 'DOWN', disabled: 'Disabled'}, speed: bps => (bps >= 1e9 ? bps / 1e9 + ' Gbps' : bps / 1e6 + ' Mbps')};
	const member = (a, b, extra = {}) => ({source_port: a, target_port: b, source_status: 'up', target_status: 'up', source_load: {in: 2e6, out: 5e5, speed: 1e9}, ...extra});
	const bundle = {lag_name: 'Trk1', source: {name: 'SW12'}, target: {name: 'SW18'}, members: [member('1/1/47', '47'), member('1/1/48', '48', {source_status: 'down', target_status: 'down', source_load: null})]};
	let lines = G.bundleTooltip(bundle, words).split('\n');
	ok(lines[0] === 'LAG Trk1 ×2: SW12 ↔ SW18', 'first line: LAG <name> ×<N>: <node A> ↔ <node B>', lines[0]);
	ok(lines.length === 3, 'one line per member', lines);
	ok(lines[1] === '1/1/47 ↔ 47 · Up · in 2 Mbps / out 0.5 Mbps of 1 Gbps', 'a member: ports · status · in / out of speed', lines[1]);
	ok(lines[2] === '1/1/48 ↔ 48 · DOWN', 'a member without load: no load part', lines[2]);
	lines = G.bundleTooltip({...bundle, lag_name: 'lag16 ↔ Trk1'}, words).split('\n');
	ok(lines[0] === 'LAG lag16 ↔ Trk1 ×2: SW12 ↔ SW18', 'a LAG on both sides: both names', lines[0]);
	lines = G.bundleTooltip({...bundle, lag_name: ''}, words).split('\n');
	ok(lines[0] === 'LAG ×2: SW12 ↔ SW18', 'no LAG name: "LAG ×2"', lines[0]);
	lines = G.bundleTooltip({...bundle, members: [member('a', 'b', {target_status: 'down'})]}, words).split('\n');
	ok(lines[1] === 'a ↔ b · Up / DOWN · in 2 Mbps / out 0.5 Mbps of 1 Gbps', 'the two sides differ: both states', lines[1]);
	lines = G.bundleTooltip({...bundle, source: {name: '<img src=x>'}, members: [member('<b>', 'x')]}, words).split('\n');
	ok(lines[0].includes('<img src=x>') && lines[1].startsWith('<b> ↔ x'), 'HTML in a name stays text (the page writes the tooltip with text(), §13)');
	const named = G.bundleLinks([{type: 'physical_link', edge_id: 1, source: 'A', target: 'B', source_lag_id: '5', source_lag: 'lag16', target_lag_id: '9', target_lag: 'Trk1', stale: false, superseded_at: null},
		{type: 'physical_link', edge_id: 2, source: 'A', target: 'B', source_lag_id: '5', source_lag: 'lag16', target_lag_id: '9', target_lag: 'Trk1', stale: false, superseded_at: null}]);
	ok(named[0].lag_name === 'lag16 ↔ Trk1', 'the bundle carries the LAG name for the tooltip', named[0].lag_name);
}
// ---- a node with one inner neighbor is in the angle of that neighbor (2.74, task 093) ----
{
	// the case of the stand: SW12 in the centre, 7 nodes on ring 1, one of them with 5 neighbors on ring 2, others with 1 to 4 (before 2.74 a node was up to 80° from its neighbor here)
	const counts = {A: 1, B: 1, C: 2, D: 4, E: 5, F: 1, G: 1};
	const nodes = [G.layoutNode({id: 'FOCUS', label: 'SW12-10-CORE-6000-48G'}, 'large')];
	const links = [];
	Object.keys(counts).forEach(id => {
		nodes.push(G.layoutNode({id, label: 'SW1' + id + '-09-ACC-2530-48G'}, 'large'));
		links.push({type: 'physical_link', source: 'FOCUS', target: id});
	});
	Object.entries(counts).forEach(([id, count]) => {
		for (let i = 0; i < count; i++) {
			nodes.push(G.layoutNode({id: id + i, label: 'ZBX-5CD4' + id + i + '3FG'}, 'large'));
			links.push({type: 'physical_link', source: id, target: id + i});
		}
	});
	G.placeRadial(nodes, links, 'FOCUS', {x: 0, y: 0});
	const by = new Map(nodes.map(node => [node.id, node]));
	const angle = node => Math.atan2(node.y, node.x);
	const diff = (a, b) => Math.abs(Math.atan2(Math.sin(a - b), Math.cos(a - b)));
	const worst = Math.max(...links.filter(l => l.source !== 'FOCUS').map(l => diff(angle(by.get(l.source)), angle(by.get(l.target)))));
	ok(worst <= 60 * Math.PI / 180 + 0.02, 'a node of ring 2 with one inner neighbor is at most 60° from it (the siblings of five fill about 120°)', Math.round(worst * 180 / Math.PI));
	const rings = new Map();
	nodes.forEach(node => rings.set(node.ring, [...(rings.get(node.ring) ?? []), node]));
	const ring2 = rings.get(2).map(node => node.angle).sort((a, b) => a - b);
	ok(ring2.every((a, i) => i === 0 || a - ring2[i - 1] > 0), 'the nodes of the ring have different angles');
	const crossing_count = (() => {
		const o = (p, q, r) => Math.sign((q.x - p.x) * (r.y - p.y) - (q.y - p.y) * (r.x - p.x));
		let count = 0;
		for (let i = 0; i < links.length; i++) {
			for (let j = i + 1; j < links.length; j++) {
				const [a, b, c, d] = [by.get(links[i].source), by.get(links[i].target), by.get(links[j].source), by.get(links[j].target)];
				if (![a, b].some(x => x === c || x === d) && o(a, b, c) !== o(a, b, d) && o(c, d, a) !== o(c, d, b)) {
					count++;
				}
			}
		}
		return count;
	})();
	ok(crossing_count === 0, 'no two lines cross', crossing_count);
}
// ---- the size of the arc (2.74, task 093) ----
{
	const make = count => ({
		parent: G.layoutNode({id: 'P', x: 0, y: 0, label: 'SW15-10-POE-6000-24G'}, 'large'),
		leaves: Array.from({length: count}, (_, i) => G.layoutNode({id: 'L' + i, label: 'NA1250200' + String(i).padStart(4, '0')}, 'small'))
	});
	const place = (count, others) => {
		const {parent, leaves} = make(count);
		const placed = G.arcPlace(parent, leaves, others);
		placed.forEach(({node, x, y}) => {
			node.x = x;
			node.y = y;
		});
		const radii = leaves.map(n => Math.hypot(n.x, n.y));
		const free = G.overlappingPairs(leaves, 0).length === 0 && !leaves.some(n => G.overlappingPairs([parent, n], 0).length);
		return {info: placed.info, max: Math.max(...radii), min: Math.min(...radii), free, leaves, parent};
	};
	// a free angle of about 60° (the other lines at 0° and 60°... on the circle: the rest is blocked by lines every 60°)
	const lines = [0, 1, 2, 3, 4].map(k => k * Math.PI / 3);
	let r = place(3, []);
	ok(r.info.rows === 1 && r.max <= 200, 'a few leaves in a free circle: one row, radius at most 200', r.info);
	r = place(12, lines);
	ok(r.max <= 250 + 1e-6 && r.free, '12 leaves in a free angle of 60°: the radius is at most 250 (200 + 50) and no box overlaps', [r.info, r.max]);
	ok(r.info.rows === 2, '12 leaves in 60°: two staggered rows', r.info);
	ok(r.info.widened === true, 'they do not fit in 60° at 200 px: the angle widens', r.info);
	ok(r.max - r.min <= 50 + 1e-6, 'two rows: the two radii differ by 50 px', [r.min, r.max]);
	const second_row = r.leaves.filter((_, i) => i % 2 === 1).every(n => Math.hypot(n.x, n.y) > r.leaves.filter((__, i) => i % 2 === 0).reduce((m, n) => Math.min(m, Math.hypot(n.x, n.y)), Infinity) + 49);
	ok(second_row, 'the leaves alternate between the two rows');
	r = place(6, lines);
	ok(r.max <= 250 + 1e-6 && r.free, '6 leaves in 60°: radius at most 250');
	r = place(15, [0]);
	ok(r.max <= 250 + 1e-6 && r.free, '15 leaves with one other line: radius at most 250 and no overlap', [r.info, r.max]);
	const angles = place(12, lines).leaves.map(n => Math.atan2(n.y, n.x));
	const unwrapped = angles.map(a => (a - angles[0] + 3 * Math.PI) % (2 * Math.PI) - Math.PI);
	ok(unwrapped.every((a, i) => i === 0 || a > unwrapped[i - 1] - 1e-9), 'the order along the arc is the given order', unwrapped);
}
// ---- a node on the line to a leaf is turned out (2.74) ----
{
	const parent = G.layoutNode({id: 'P', x: 0, y: 0, label: 'SW18'}, 'large');
	const leaf = G.layoutNode({id: 'L', x: 300, y: 0, label: 'leaf'}, 'small');
	const crosses = n => { for (let t = 0.03; t < 0.98; t += 0.02) { const x = 300 * t; if (x > n.x - n.box.w / 2 && x < n.x + n.box.w / 2 && 0 > n.y + n.box.top && 0 < n.y + n.box.bottom) { return true; } } return false; };
	const on_line = G.layoutNode({id: 'N', x: 150, y: 0, label: 'in the way'}, 'large');
	const aside = G.layoutNode({id: 'A', x: 150, y: 200, label: 'aside'}, 'large');
	const pinned = G.layoutNode({id: 'D', x: 220, y: 5, fx: 220, fy: 5, label: 'dragged'}, 'large');
	const moved = G.clearLines(parent, [leaf], [parent, leaf, on_line, aside, pinned]);
	ok(moved.length === 1 && moved[0] === on_line && !crosses(on_line), 'a node on the line is turned out of it', [on_line.x, on_line.y]);
	ok(aside.x === 150 && aside.y === 200, 'a node that the line does not cross stays');
	ok(pinned.x === 220 && pinned.y === 5, 'a fixed (dragged) node stays');
	ok(![parent, leaf, aside].some(n => G.overlappingPairs([on_line, n], G.BOX_GAP).length), 'it is clear of the other boxes');
}
// ---- room for the label of a LAG bundle in a finished layout (2.73) ----
{
	const a = G.layoutNode({id: 'A', x: 300, y: 300, label: 'SW12-10-CORE-6000-48G'}, 'large');
	const b = G.layoutNode({id: 'B', x: 300 + a.box.w / 2 + 10 + 74, y: 300, label: 'SW18-09-ACC-2530-48G'}, 'large');
	const filler = G.layoutNode({id: 'C', x: 300, y: 500, label: 'SW20'}, 'large');
	const nodes = [a, b, filler];
	const bundle = {a, b, width: 'Trk1 ×2'.length * 6 + 4, index: 0, count: 1};
	const room = () => {
		const boxes = nodes.map(n => ({left: n.x - n.box.w / 2, right: n.x + n.box.w / 2, top: n.y + n.box.top, bottom: n.y + n.box.bottom}));
		return G.placeLabel(G.curve(a, b, 0, 1).at, bundle.width, boxes).free;
	};
	ok(!room(), 'start: two boxes 10 px apart leave no free point for the label');
	ok(G.makeLabelRoom(nodes, [bundle], {width: 1200, height: 900, padding: 10}) && room(), 'after makeLabelRoom the label has a free point');
	ok(G.overlappingPairs(nodes, 0).length === 0, 'and no box overlaps another');
	const pinned_a = G.layoutNode({id: 'A', x: 300, y: 300, fx: 300, fy: 300, label: 'SW12-10-CORE-6000-48G'}, 'large');
	const pinned_b = G.layoutNode({id: 'B', x: 300 + pinned_a.box.w / 2 + 10 + 74, y: 300, fx: 800, fy: 300, label: 'SW18-09-ACC-2530-48G'}, 'large');
	const before = [pinned_a.x, pinned_a.y, pinned_b.x, pinned_b.y];
	G.makeLabelRoom([pinned_a, pinned_b], [{a: pinned_a, b: pinned_b, width: 40, index: 0, count: 1}], {width: 1200, height: 900, padding: 10}, 3);
	ok(before.join() === [pinned_a.x, pinned_a.y, pinned_b.x, pinned_b.y].join(), 'two fixed (dragged) nodes do not move');
}
// ---- the side panel of a node (2.75, task 094) ----
{
	const ids = (kind, has) => G.panelSections(kind, has).map(x => x.id).join();
	ok(ids('host', true) === 'problems,links,ports,identity' && G.panelSections('host', true)[3].open === false, 'Host: Problems, Links, Ports, Identity (folded)');
	ok(ids('device', false) === 'identity,links,ports' && G.panelSections('device', false)[0].open === true, 'Device without association: Identity (with Addresses, open), Links, Ports');
	ok(ids('proxy', true) === 'links,ports,identity' && G.panelSections('proxy', true)[2].open === false, 'Proxy with a Device: Links, Ports, Identity (folded)');
	ok(ids('proxy', false) === '', 'a Proxy without a Device: the header only (no section)');
	ok(ids('host', false) === 'problems', 'a Host without a Device: Problems only');
	const w = {link: '%1$s link', links: '%1$s links', neighbor: '%1$s neighbor', neighbors: '%1$s neighbors', to: '%1$s to %2$s', port: '%1$s port', ports: '%1$s ports', up: '%1$s up'};
	ok(G.summaryLine({links: 11, neighbors: 7, ports: 58, up: 30}, w) === '11 links to 7 neighbors · 58 ports (30 up)', 'summary line: all parts (SW12)');
	ok(G.summaryLine({links: 0, neighbors: 0, ports: 48, up: 14}, w) === '48 ports (14 up)', 'a part that is 0 is left out (links)');
	ok(G.summaryLine({links: 3, neighbors: 2, ports: 24, up: 0}, w) === '3 links to 2 neighbors · 24 ports', 'no port up: that part is left out');
	ok(G.summaryLine({links: 1, neighbors: 1, ports: 1, up: 1}, w) === '1 link to 1 neighbor · 1 port (1 up)', 'one link, one neighbor, one port');
	ok(G.summaryLine({links: 0, neighbors: 0, ports: 0, up: 0}, w) === '', 'all 0: an empty line');
	ok(G.loadText({in: 250e6, out: 5e6, speed: 1e9}) === 'in 25 % / out 0.5 %', 'load "in x % / out y %"', G.loadText({in: 250e6, out: 5e6, speed: 1e9}));
	ok(G.loadText({in: 0, out: 0, speed: 1e9}) === 'in 0 % / out 0 %', 'load 0 is 0 %, not a dash');
	ok(G.loadText(null) === '—' && G.loadText({in: null, out: null, speed: 1e9}) === '—' && G.loadText({in: 1, out: 1, speed: null}) === '—', 'load "—" without items, without a value, without a speed');
	ok(G.loadText({in: 1e8, out: null, speed: 1e9}) === 'in 10 % / out — %', 'one value missing: "—" in its place');
	const problems = [{severity: 2, severity_name: 'Warning', color: 'ffc859', name: 'a', age: 500}, {severity: 4, severity_name: 'High', color: 'e97659', name: 'b', age: 100},
		{severity: 2, severity_name: 'Warning', color: 'ffc859', name: 'c', age: 50}, {severity: 2, severity_name: 'Warning', color: 'ffc859', name: 'd', age: 900}, {severity: 1, severity_name: 'Information', color: '7499ff', name: 'e', age: 20},
		{severity: 2, severity_name: 'Warning', color: 'ffc859', name: 'f', age: 700}, {severity: 2, severity_name: 'Warning', color: 'ffc859', name: 'g', age: 10}];
	const summary = G.problemSummary(problems);
	ok(summary.total === 7 && summary.by_severity.map(x => x.severity_name + x.count).join() === 'High1,Warning5,Information1', 'problems: the count by severity, the highest first', summary.by_severity);
	ok(summary.newest.length === 5 && summary.newest.map(x => x.name).join('') === 'gecba', 'problems: the 5 newest (the smallest age first)', summary.newest.map(x => x.name));
	ok(G.problemSummary([]).total === 0 && G.problemSummary([]).newest.length === 0, 'no problems: nothing');
	// Links rows
	const entry = (port, extra = {}) => ({port_id: port, edge_id: port, port, remote_port: '9', remote_device_id: '77', connected_to: 'SW18', status: 'up', source: 'LLDP', load: null, stale: false, last_seen: 100,
		superseded_at: null, lag_id: null, lag: null, remote_lag_id: null, remote_lag: null, device_link_id: null, ...extra});
	let rows = G.linkRows([entry('1/1/10'), entry('1/1/2'), entry('1/1/1', {remote_device_id: '88', connected_to: 'SW20'})]);
	ok(rows.map(r => r.entry.port).join() === '1/1/1,1/1/2,1/1/10', 'rows in the natural order of the local port (1/1/2 before 1/1/10)', rows.map(r => r.entry.port));
	rows = G.linkRows([entry('1/1/43', {remote_lag_id: '5', remote_lag: 'Trk1'}), entry('1/1/44', {remote_lag_id: '5', remote_lag: 'Trk1'}), entry('1/1/1')]);
	ok(rows.length === 2 && rows[0].type === 'link' && rows[1].type === 'bundle' && rows[1].count === 2 && rows[1].label === 'Trk1 ×2', 'two members of one LAG to one neighbor: one bundle row', rows.map(r => r.type));
	rows = G.linkRows([entry('1/1/43', {remote_lag_id: '5', remote_lag: 'Trk1'}), entry('1/1/44', {remote_lag_id: '5', remote_lag: 'Trk1', stale: true})]);
	ok(rows.length === 2 && rows.every(r => r.type === 'link'), 'a stale member is its own row (as in the graph)');
	rows = G.linkRows([entry('1/1/1', {device_link_id: '5', remote_port: null, remote_device_id: '77'}), entry('1/1/2', {device_link_id: '6', remote_port: null, remote_device_id: '77'})]);
	ok(rows.length === 2 && rows.every(r => r.type === 'link'), 'device-level links are not bundled');
	rows = G.linkRows([entry('1/1/5', {superseded_at: 123})]);
	ok(rows.length === 1 && rows[0].entry.superseded_at === 123, 'a superseded link has its row');
}
// ---- the tooltip of a link (2.77, task 095) ----
{
	const words = {in: 'in', out: 'out', of: 'of', status: {up: 'Up', down: 'DOWN', disabled: 'Disabled'}, speed: bps => (bps >= 1e9 ? bps / 1e9 + ' Gbps' : bps / 1e6 + ' Mbps')};
	const link = (extra = {}) => ({source: {name: 'SW12'}, target: {name: 'SW15'}, source_port: '1/1/41', target_port: '1/1/21', source_status: 'up', target_status: 'up', source_text: 'LLDP',
		source_load: {in: 2e6, out: 5e5, speed: 1e9, percent: 0.2}, target_load: {in: 0, out: 0, speed: 1e9, percent: 0}, marks: [], ...extra});
	let lines = G.linkTooltip(link(), words).split('\n');
	ok(lines.length === 4 && lines[0] === 'SW12 1/1/41 ↔ SW15 1/1/21' && lines[1] === 'LLDP · Up / Up', 'port-level: the ports, then "source · status A / status B"', lines);
	ok(lines[2] === 'SW12: in 2 Mbps / out 0.5 Mbps of 1 Gbps (0.2 %)' && lines[3] === 'SW15: in 0 bps / out 0 bps of 1 Gbps (0 %)', 'one line per side with the load', lines.slice(2));
	lines = G.linkTooltip(link({source_load: null, target_load: null}), words).split('\n');
	ok(lines.length === 2, 'no load: no load lines', lines);
	lines = G.linkTooltip(link({stale: true, marks: ['Not recently reconfirmed.', 'The remote port 5 hasn\'t been confirmed since x.']}), words).split('\n');
	ok(lines.length === 6 && lines[4] === 'Not recently reconfirmed.' && lines[5].startsWith('The remote port'), 'stale and the other marks: each on its own line', lines);
	lines = G.linkTooltip(link({superseded_at: 5, marks: ['Replaced on 1/1/2026 by a link to SW20.']}), words).split('\n');
	ok(lines[lines.length - 1] === 'Replaced on 1/1/2026 by a link to SW20.', 'superseded: the replaced note is the last line');
	lines = G.linkTooltip(link({source_status: 'down', target_status: null, source_text: 'manual', source_load: null, target_load: null}), words).split('\n');
	ok(lines[1] === 'manual · DOWN', 'one side has no status: only the known one', lines[1]);
	lines = G.linkTooltip(link({source: {name: '<img src=x>'}, source_port: '<b>1</b>'}), words).split('\n');
	ok(lines[0] === '<img src=x> <b>1</b> ↔ SW15 1/1/21', 'HTML in a name stays text (§13)', lines[0]);
	lines = G.linkTooltip(link({source_port: null, target_port: null, source_load: null, target_load: null}), words).split('\n');
	ok(lines[0] === 'SW12 ? ↔ SW15 ?', 'an unknown port is "?" (a device-level end)', lines[0]);
}
// ---- one width of a bundle label for the place and the room (2.79, task 097) ----
{
	const wide = (text, font) => String(text).length * font * 0.9;      // a real font that is wider than the estimate of 0.6
	ok(G.labelWidth('Trk1 ×2') === 'Trk1 ×2'.length * 6 && G.labelWidth('Trk1 ×2', wide) > G.labelWidth('Trk1 ×2'), 'labelWidth: the estimate without a measure, the measured width with one');
	const make = () => {
		const a = G.layoutNode({id: 'A', x: 300, y: 300, label: 'SW12-10-CORE-6000-48G'}, 'large');
		// the line between the boxes has room for the estimated label (42 px) but not for the measured one (63 px)
		const b = G.layoutNode({id: 'B', x: 300 + a.box.w / 2 + 8 + 56 + 75, y: 300, label: 'SW18-09-ACC-2530-48G'}, 'large');
		return [a, b];
	};
	const boxes = nodes => nodes.map(n => ({left: n.x - n.box.w / 2, right: n.x + n.box.w / 2, top: n.y + n.box.top, bottom: n.y + n.box.bottom}));
	let [a, b] = make();
	ok(G.placeLabel(G.curve(a, b, 0, 1).at, G.labelWidth('Trk1 ×2'), boxes([a, b])).free, 'start: the estimated width fits between the boxes');
	ok(!G.placeLabel(G.curve(a, b, 0, 1).at, G.labelWidth('Trk1 ×2', wide), boxes([a, b])).free, 'start: the measured (larger) width does not');
	const width = G.labelWidth('Trk1 ×2', wide);
	ok(G.makeLabelRoom([a, b], [{a, b, width, index: 0, count: 1}], {width: 1200, height: 900, padding: 10}), 'makeLabelRoom with the measured width makes room');
	ok(G.placeLabel(G.curve(a, b, 0, 1).at, width, boxes([a, b])).free, 'and placeLabel with the same width finds a free point: the same answer');
	// the ring room uses the same width
	const mk = measure => {
		const focus = G.layoutNode({id: 'F', label: 'SW12-10-CORE-6000-48G'}, 'large');
		const near = G.layoutNode({id: 'N', label: 'SW18-09-ACC-2530-48G'}, 'large');
		G.placeRadial([focus, near], [{type: 'physical_link', source: 'F', target: 'N', source_lag_id: null, source_lag: null, target_lag_id: '9', target_lag: 'a-long-lag-name-0123456789'}], 'F', {x: 0, y: 0}, measure);
		const label = G.labelWidth('a-long-lag-name-0123456789 ×2', measure);
		return [G.placeLabel(u => ({x: focus.x + (near.x - focus.x) * u, y: focus.y + (near.y - focus.y) * u}), label, boxes([focus, near])).free, Math.hypot(near.x, near.y)];
	};
	const [free_wide, radius_wide] = mk(wide);
	const [free_est, radius_est] = mk(undefined);
	ok(free_wide && free_est && radius_wide >= radius_est, 'placeRadial: the ring leaves room for the measured width of the label, larger than for the estimate', [radius_wide, radius_est]);
}
// ---- task 100: no line through a node (§12.1, 2.84) ----
{
	const nd = (id, x, y) => ({...G.layoutNode({id, label: id}, 'small'), x, y});
	const bounds = {width: 600, height: 400, padding: 20};
	const a = nd('A', 100, 200);
	const b = nd('B', 400, 200);
	const c = nd('C', 250, 200);      // on the line A-B
	const line = {a, b, index: 0, count: 1};
	ok(G.linesThrough([a, b, c], [line]).length === 1 && G.linesThrough([a, b, c], [line])[0].node === c, 'linesThrough: a node on the line is found, the two ends are not');
	ok(G.linesThrough([a, b, nd('D', 250, 120)], [line]).length === 0, 'linesThrough: a node beside the line is not crossed');
	ok(G.linesThrough([a, b, nd('E', 250, 224)], [line], 0).length === 0 && G.linesThrough([a, b, nd('E', 250, 224)], [line], 40).length === 1, 'linesThrough: the margin grows the box');
	const curved = {a, b, index: 0, count: 2};
	const middle = G.curve(a, b, 0, 2).at(0.5);
	const on_curve = nd('F', middle.x, middle.y);
	ok(G.linesThrough([a, b, on_curve], [curved]).length === 1, 'linesThrough: a parallel (curved) line is followed along its curve');
	// the move: the node leaves the line, nothing else is crossed, no overlap
	const nodes = [a, b, c];
	const moved = G.clearThroughLines(nodes, [line], bounds);
	ok(moved === 1 && G.linesThrough(nodes, [line], 2).length === 0 && !G.overlappingPairs(nodes).length && a.x === 100 && b.x === 400, 'clearThroughLines: the crossed node is moved off the line, the ends stay');
	// a fixed node stays: the end of the line is moved instead
	const a2 = nd('A', 100, 200);
	const b2 = nd('B', 400, 200);
	const c2 = {...nd('C', 250, 200), fx: 250, fy: 200};
	const nodes2 = [a2, b2, c2];
	const moved2 = G.clearThroughLines(nodes2, [{a: a2, b: b2, index: 0, count: 1}], bounds);
	ok(c2.x === 250 && c2.y === 200 && moved2 === 1 && G.linesThrough(nodes2, [{a: a2, b: b2, index: 0, count: 1}], 2).length === 0, 'clearThroughLines: a node that was dragged (fixed) stays; an end of the line moves');
	// all fixed: nothing moves, the crossing stays (the drag rule)
	const fa = {...nd('A', 100, 200), fx: 100, fy: 200};
	const fb = {...nd('B', 400, 200), fx: 400, fy: 200};
	const fc = {...nd('C', 250, 200), fx: 250, fy: 200};
	ok(G.clearThroughLines([fa, fb, fc], [{a: fa, b: fb, index: 0, count: 1}], bounds) === 0 && G.linesThrough([fa, fb, fc], [{a: fa, b: fb, index: 0, count: 1}]).length === 1, 'clearThroughLines: when every node is fixed, nothing moves');
	// the moved node's own lines must not cross another node
	const a3 = nd('A', 100, 200);
	const b3 = nd('B', 400, 200);
	const c3 = nd('C', 250, 200);
	const d3 = nd('D', 250, 60);
	const lines3 = [{a: a3, b: b3, index: 0, count: 1}, {a: c3, b: d3, index: 0, count: 1}];
	G.clearThroughLines([a3, b3, c3, d3], lines3, bounds);
	ok(G.linesThrough([a3, b3, c3, d3], lines3, 2).length === 0, 'clearThroughLines: the moved node\'s own line crosses no other node either');
}
console.log('\nAll ' + n + ' topology graph checks passed.');
