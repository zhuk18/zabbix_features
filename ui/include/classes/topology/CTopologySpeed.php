<?php

/**
 * Speed mismatch of a port-level link (model 2.100 §12.3, FR-C V-25): computed when the graph is read, never stored. A link has the mark when both ends are Ports of reporters (not a pseudo Port,
 * not a manual Port, not a LAG aggregate: a LAG bundle line has no mark of its own, its member links are compared), both are up, both have a speed that is known and greater than 0, and the two
 * speeds are different. The speeds are bits per second (the PORTS speed is Mbit/s, CTopologyPrototype::speedBps() converts it).
 */
class CTopologySpeed {

	/**
	 * @param array $a  a port as getPortDetails() gives it: reporter (bool), if_type, oper_status, speed (bps or null)
	 * @param array $b  the other end
	 *
	 * @return array|null  ['a' => bps, 'b' => bps] or null when the link has no mark
	 */
	public static function mismatch(array $a, array $b): ?array {
		foreach ([$a, $b] as $port) {
			if (empty($port['reporter']) || ($port['if_type'] ?? null) === 'lag' || ($port['oper_status'] ?? null) !== 'up'
					|| !is_int($port['speed'] ?? null) || $port['speed'] <= 0) {
				return null;
			}
		}

		return $a['speed'] !== $b['speed'] ? ['a' => $a['speed'], 'b' => $b['speed']] : null;
	}

	/**
	 * A speed that is known: greater than 0. The value of an item can be -1 (`/sys/class/net/<name>/speed` of a virtual interface: `veth`, `docker0`, `lo`) or have no value: no speed, null.
	 *
	 * @param int|float|null $value  bits per second
	 */
	public static function known($value): ?float {
		return is_numeric($value) && (float) $value > 0 ? (float) $value : null;
	}

	/**
	 * The load of a port as a percent of its speed (model §4.8): the larger of the two directions; null without a known speed or without any value.
	 */
	public static function percent(?float $in, ?float $out, ?float $speed): ?float {
		if (self::known($speed) === null || ($in === null && $out === null)) {
			return null;
		}

		return round(100 * max($in ?? 0, $out ?? 0) / $speed, 1);
	}
}
