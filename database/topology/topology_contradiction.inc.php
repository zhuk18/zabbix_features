<?php

declare(strict_types=1);

/**
 * The one definition of "hidden neighbor" for manual links contradicted by discovery
 * (topology-1-model.md "Contradiction", "Acknowledgments").
 *
 * Required by ingest.php (PDO) and by CTopologyPrototype (Zabbix DB layer). It reads nothing: each caller fetches the
 * ids its own way and passes them in, so the two cannot disagree about the rule.
 */

/**
 * The hidden neighbor of one shadowing observation of a manual link M (outcome `shadowed`, edge_id = M).
 *
 * - From an end of M (the observation's local Port is an end Port of M): the hidden neighbor is the Device the
 *   observation resolved to (`$remote_device_id`).
 * - Third party (the reporter's own Port is not an end of M, and the reporter's Device is not an end of M either: it
 *   reports a Port of M as its neighbor): the hidden neighbor is the reporter's Device (`$local_device_id`), not
 *   `$remote_device_id`, which is an end of M.
 * - Otherwise (an end Device of M reporting from another Port): as for an end, using the resolved Device.
 *
 * @param int      $local_port_id    the observation's local Port (a Port of the reporter)
 * @param int|null $local_device_id  the Device that owns that Port: the reporter's Device
 * @param int|null $remote_device_id the observation's `device_id`: the far Device it resolved to
 * @param int[]    $end_port_ids     the Ports M joins (one for a device_link, two for a physical_link)
 * @param int[]    $end_device_ids   the Devices M joins
 *
 * @return array{kind: string, device_id: ?int, third_party: bool}
 *   kind `hidden`: `device_id` is the hidden neighbor.
 *   kind `own`: the neighbor is one of M's own ends seen at another precision (model "Confirmation by discovery"):
 *               not a hidden neighbor, not a contradiction.
 *   kind `unknown`: no Device is known; it cannot be acknowledged by Device, so it counts as contradicting.
 */
function topo_hidden_neighbor(int $local_port_id, ?int $local_device_id, ?int $remote_device_id, array $end_port_ids,
		array $end_device_ids): array {
	$end_port_ids = array_map('intval', $end_port_ids);
	$end_device_ids = array_map('intval', $end_device_ids);

	if (!in_array($local_port_id, $end_port_ids, true) && $local_device_id !== null
			&& !in_array($local_device_id, $end_device_ids, true)) {
		return ['kind' => 'hidden', 'device_id' => $local_device_id, 'third_party' => true];
	}
	if ($remote_device_id === null) {
		return ['kind' => 'unknown', 'device_id' => null, 'third_party' => false];
	}
	if (in_array($remote_device_id, $end_device_ids, true)) {
		return ['kind' => 'own', 'device_id' => $remote_device_id, 'third_party' => false];
	}

	return ['kind' => 'hidden', 'device_id' => $remote_device_id, 'third_party' => false];
}

/**
 * The `remote_key` an acknowledgment records for a third-party shadowing observation: the natural key of the
 * reporter's local Port, in the kind-prefixed form of every `remote_key` (`chassis:<type>:<value>`, `ip:<addr>`,
 * `sysname:<name>`): `port:<reporter identity key>:<if_index>`. The chassis type is not stored on a Device, so it is `-`.
 * Not a node id. Display and audit only (model "Acknowledgments").
 *
 * @param array    $device_attrs  `attrs` of the reporter's Device (`chassis_id`, `mgmt_ip`, `sysname`)
 * @param int|null $if_index      `if_index` of the local Port
 */
function topo_reporter_port_key(array $device_attrs, ?int $if_index): string {
	$chassis = (string) ($device_attrs['chassis_id'] ?? '');
	$ip = (string) ($device_attrs['mgmt_ip'] ?? '');
	if ($chassis !== '') {
		$identity = 'chassis:-:'.$chassis;
	}
	elseif ($ip !== '') {
		$identity = 'ip:'.$ip;
	}
	else {
		$identity = 'sysname:'.(string) ($device_attrs['sysname'] ?? '');
	}

	return substr('port:'.$identity.':'.($if_index === null ? '' : (string) $if_index), 0, 255);
}
