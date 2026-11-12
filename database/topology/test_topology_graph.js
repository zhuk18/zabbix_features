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
console.log('\nAll ' + n + ' topology graph checks passed.');
