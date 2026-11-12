<?php

/**
 * Leaf groups (topology-1-model.md §12.8, FR-C V-22, task 090): a UI-only projection, not stored. Pure: no database, no framework calls, so that a unit test can run it.
 *
 * A leaf is a Device without association that has exactly one link that is not superseded, counted in the whole topology (not only in the view). A leaf in a group is a leaf whose link is
 * discovered (not manual), not stale and has no severity colour, and that has no badge (§12.2). A node of the view with LEAF_GROUP_MIN or more leaves in a group gets a group: the
 * leaves are not drawn, one group node is. With "Possible duplicates only" there are no groups. The Junction projection (§12.6) is not implemented, so no Device is in one.
 */
class CTopologyLeafGroups {

	/** a constant, not a setting (§12.8) */
	public const LEAF_GROUP_MIN = 4;

	/**
	 * @param array $devices           the Devices and Hosts of the view as getDevices() gives them: id, type, represented (bool), duplicates
	 * @param array $relations         the relations of the view as getRelations() gives them: type, source, target, discovered_via, stale, superseded_at, severity
	 * @param array $topology_links    Device id => the number of links that are not superseded in the whole topology
	 * @param array $badge_device_ids  Device ids that carry a badge other than "possible duplicate" (an unacknowledged hidden neighbor of a contradicted manual link)
	 * @param bool  $only_duplicates   the filter "Possible duplicates only": no groups
	 * @param array $visible           Device ids the user can see; null = every Device (access control of a Device without association is not implemented yet: the caller passes null)
	 *
	 * @return array  list of ['parent' => node id (as drawn: the Host or Proxy of an associated Device), 'devices' => [Device ids]], sorted by parent
	 */
	public static function compute(array $devices, array $relations, array $topology_links, array $badge_device_ids, bool $only_duplicates, ?array $visible = null): array {
		if ($only_duplicates) {
			return [];
		}
		$by_id = [];
		$display_of = [];       // a Device with an association is drawn as its Host or Proxy
		foreach ($devices as $device) {
			$by_id[(string) $device['id']] = $device;
		}
		foreach ($relations as $relation) {
			if ($relation['type'] === 'represented_by') {
				$display_of[(string) $relation['source']] = (string) $relation['target'];
			}
		}
		$badges = array_flip(array_map('strval', $badge_device_ids));
		$links_of = [];     // Device id => the drawn links that are not superseded
		foreach ($relations as $relation) {
			if (!in_array($relation['type'], ['physical_link', 'device_link'], true) || ($relation['superseded_at'] ?? null) !== null) {
				continue;
			}
			$links_of[(string) $relation['source']][] = $relation;
			$links_of[(string) $relation['target']][] = $relation;
		}
		$groups = [];
		foreach ($by_id as $id => $device) {
			$id = (string) $id;      // an array key like '11' is an int in PHP
			if ($device['type'] !== 'device' || isset($display_of[$id]) || !empty($device['represented'])) {
				continue;       // not a Device without association
			}
			if ($visible !== null && !in_array($id, array_map('strval', $visible), true)) {
				continue;
			}
			// a leaf: one link not superseded in the whole topology, and that link is in the view
			if (($topology_links[$id] ?? 0) !== 1 || count($links_of[$id] ?? []) !== 1) {
				continue;
			}
			$link = $links_of[$id][0];
			// a leaf in a group: discovered, not stale, no severity colour, no badge
			if (($link['discovered_via'] ?? null) === 'manual' || !empty($link['stale']) || ($link['severity'] ?? null) !== null
					|| !empty($device['duplicates']) || isset($badges[$id])) {
				continue;
			}
			$other = (string) ($link['source'] === $id ? $link['target'] : $link['source']);
			$parent = $display_of[$other] ?? $other;
			if (!isset($by_id[$other]) && !isset($by_id[$parent])) {
				continue;
			}
			$groups[$parent][] = $id;
		}
		$result = [];
		foreach ($groups as $parent => $ids) {
			if (count($ids) >= self::LEAF_GROUP_MIN) {
				sort($ids, SORT_STRING);
				$result[] = ['parent' => (string) $parent, 'devices' => array_map('strval', $ids)];
			}
		}
		usort($result, static fn (array $a, array $b): int => strcmp($a['parent'], $b['parent']));

		return $result;
	}
}
