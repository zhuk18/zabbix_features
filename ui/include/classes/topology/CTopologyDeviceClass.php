<?php

/**
 * The class of a Device for its icon (topology-1-model.md §12.2 "Device class", 2.71; FR-C V-3, FR-B D-52). Display only: no rule of the model reads it. Pure: no database, no framework calls.
 *
 * The union of `rem_sys_cap` of the current observations of the Device (the names of LLD spec 2.14; a space-separated list in an observation) gives the class; the first rule that is true wins.
 * A neighbor can announce any bits (§13): a wrong icon is the only result, and a bit that is no name of the table gives no class.
 */
class CTopologyDeviceClass {

	public const ACCESS_POINT = 'access_point';
	public const PHONE = 'phone';
	public const SWITCH = 'switch';
	public const ROUTER = 'router';
	public const STATION = 'station';

	/** the rules of the table of §12.2, in order: class => bits */
	private const RULES = [
		[self::ACCESS_POINT, ['wlanAccessPoint']],
		[self::PHONE, ['telephone']],
		[self::SWITCH, ['switch', 'bridge', 'transBridge', 'srcRouteBridge']],      // 2.72: `bridge` before `router`: an LLDP layer-3 switch (`bridge router`) is a Switch
		[self::ROUTER, ['router']],
		[self::STATION, ['stationOnly', 'host']]
	];

	/**
	 * @param string[] $bits  the capability names of the Device (the union over its current observations)
	 *
	 * @return string|null  one of the constants, or null: the generic icon (none of the bits, or no `rem_sys_cap`)
	 */
	public static function fromBits(array $bits): ?string {
		foreach (self::RULES as [$class, $names]) {
			if (array_intersect($names, $bits)) {
				return $class;
			}
		}

		return null;
	}

	/** The bits of one `rem_sys_cap` value: split at white space, as received (no change of the names; an unknown name stays and is escaped where it is shown). */
	public static function bitsOf(?string $value): array {
		return $value === null || trim($value) === '' ? [] : preg_split('/\s+/', trim($value));
	}

	/**
	 * Device id => ['class' => ..., 'bits' => sorted union] for the Devices that have a `rem_sys_cap` in a current observation.
	 *
	 * @param array $rows  rows with `device_id` and `remote_attrs` (JSON text) of the observations
	 * @param array $own   device id => the own capabilities of a reporter's Device (`attrs.sys_cap`, 2.96, model §12.2): for such a Device they replace the capabilities of the neighbors
	 */
	public static function ofObservations(array $rows, array $own = []): array {
		$bits = [];
		foreach ($rows as $row) {
			$attrs = json_decode((string) $row['remote_attrs'], true);
			if (!is_array($attrs) || !isset($attrs['rem_sys_cap']) || !is_string($attrs['rem_sys_cap'])) {
				continue;
			}
			if (array_key_exists((string) $row['device_id'], $own)) {
				continue;      // own data wins
			}
			foreach (self::bitsOf($attrs['rem_sys_cap']) as $bit) {
				$bits[(string) $row['device_id']][$bit] = true;
			}
		}
		foreach ($own as $device_id => $value) {
			foreach (self::bitsOf((string) $value) as $bit) {
				$bits[(string) $device_id][$bit] = true;
			}
		}
		$result = [];
		foreach ($bits as $device_id => $set) {
			$list = array_keys($set);
			sort($list, SORT_STRING);
			$result[(string) $device_id] = ['class' => self::fromBits($list), 'bits' => $list];
		}

		return $result;
	}
}
