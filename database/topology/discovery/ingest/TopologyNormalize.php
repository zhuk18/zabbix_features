<?php

declare(strict_types=1);

/**
 * TopologyNormalize — the step "Normalize" of the reconciliation (topology-1-model.md §6.0, §6.3; topology-6-normalization.md §3-§5).
 *
 * Input: one value, or one NEIGHBORS/PORTS row of a snapshot (field names of the row as the step and push.py write them).
 * Output: the normal form of the value, or [row, null] / [null, "field: why"] for a row.
 * Reads nothing from the database and no other row; no state. The same input always gives the same output.
 * The same functions as the native step (preproc_topology.c) and push.py for what they decode; normalization_cases.json and test_generated.php test all three.
 */
class TopologyNormalize {
	private const WHITE = " \t\n\x0B\f\r";

	/** normalization §4.1: the normal form `00:1a:2b:3c:4d:5e` is shaped like this (any case) */
	public static function macLike(string $value): bool {
		return (bool) preg_match('/^([0-9a-f]{2}:){5}[0-9a-f]{2}$/i', $value);
	}

	/** normalization §4.1 "Placeholder MAC": `00:00:00:00:00:00` and `ff:ff:ff:ff:ff:ff` (after the MAC normal form, any case) are valid values but never an identity: the one predicate of
	 * every place that makes or compares a MAC identity (chassis key, loc_chassis, port MAC identity, inventory MAC match) */
	public static function isPlaceholderMac(?string $mac): bool {
		return $mac !== null && in_array(strtolower($mac), ['00:00:00:00:00:00', 'ff:ff:ff:ff:ff:ff'], true);
	}

	/** §3, text fields from a Hex-STRING: trailing NUL bytes removed; valid UTF-8 without a control character other than tab is text, else the hex form aa:bb:.. of all the bytes (0.9) */
	public static function textOrHex(string $raw): string {
		$text = rtrim($raw, "\0");
		if ($text === '') {
			return '';
		}
		// the hex form holds ALL the bytes, trailing NUL bytes included (0.9, task 059: a binary id that ends in 00 is not text)
		if (preg_match('//u', $text) !== 1 || preg_match('/[\x00-\x08\x0A-\x1F\x7F]|\xC2[\x80-\x9F]/', $text) === 1) {
			return implode(':', str_split(bin2hex($raw), 2));
		}
		return $text;
	}

	/** §4.5 steps 2-4, repeated until the value does not change (step 5, 0.12): trailing NUL characters, white space, one pair of surrounding quotes (".." '..' U+201C U+201D), white space; null = no value */
	public static function clean(string $value): ?string {
		do {
			$before = $value;
			$value = trim(rtrim($value, "\0"), self::WHITE);
			foreach ([['"', '"'], ["'", "'"], ["\u{201C}", "\u{201D}"]] as [$left, $right]) {
				if (strlen($value) >= strlen($left) + strlen($right) && str_starts_with($value, $left) && str_ends_with($value, $right)) {
					$value = trim(substr($value, strlen($left), strlen($value) - strlen($left) - strlen($right)), self::WHITE);
					break;
				}
			}
		} while ($value !== $before);

		return $value === '' ? null : $value;
	}

	/** §4.6: clean, ASCII lower case, no white space between the leading letters and hyphens and the first digit, a long form of the table replaced by its short form when a digit follows */
	public static function nameKey(string $value): ?string {
		$key = self::clean($value);
		if ($key === null) {
			return null;
		}
		$key = strtr($key, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz');
		$key = preg_replace('/^([a-z-]+)[ \t\n\x0B\f\r]+(?=[0-9])/', '$1', $key);
		foreach (TOPO_PORT_NAME_FORMS as [$long, $short]) {
			$next = $key[strlen($long)] ?? '';
			if (str_starts_with($key, $long) && $next !== '' && $next >= '0' && $next <= '9') {
				return $short.substr($key, strlen($long));
			}
		}
		return $key;
	}

	/** §4.7: 1 to 10 ASCII digits after cleaning, value 1..2147483647; else null */
	public static function numericId(string $value): ?int {
		$value = self::clean($value);
		if ($value === null || preg_match('/^[0-9]{1,10}$/D', $value) !== 1) {
			return null;
		}
		$number = (int) $value;
		return $number > 0 && $number <= 2147483647 ? $number : null;
	}

	/** §4.8: the 0-based index of the one port that has this MAC; null when the MAC is not usable (invalid, a placeholder MAC) or no or several ports have it */
	public static function portMac(string $mac, array $port_macs): ?int {
		if (preg_match('/^([0-9a-f]{2}:){5}[0-9a-f]{2}$/D', $mac) !== 1 || self::isPlaceholderMac($mac)) {
			return null;
		}
		$hits = array_keys(array_filter($port_macs, static fn ($m): bool => $m === $mac));
		return count($hits) === 1 ? (int) $hits[0] : null;
	}

	/** §4.3: IPv4 dotted decimal, IPv6 as RFC 5952 text; null when it is not an address. The decoder is shared with the side panel (ui/include/classes/topology/CTopologyAddress.php). */
	public static function ipText(string $text): ?string {
		return CTopologyAddress::ipText($text);
	}

	/** §4.4: the management address from the raw `rem_man_addrs` entries: the first IPv4, else the first IPv6; null = none usable (CTopologyAddress, shared with the side panel) */
	public static function manAddrs(string $entries): ?string {
		return CTopologyAddress::management($entries);
	}

	// Identity normalization of a snapshot row (topology-1-model.md "Identity normalization"): the
	// one place that decides how identity strings are spelled, whatever transport produced the snapshot. On the output of
	// the native step it is a no-op (that output is already in the normal form: test_push.php runs every golden output
	// through it). A MAC (chassis id of type macAddress or without a type, port id of type macAddress, ifPhysAddress, FDB
	// MAC) becomes lowercase, colon-separated, six octets; a value that is shaped like a MAC but has the wrong number of
	// octets cannot be normalized and the row is skipped, never guessed. A management IP becomes dotted IPv4 / compressed
	// lowercase IPv6; a malformed one skips the row. A networkAddress chassis id that is an IP is written the same way; any
	// other chassis / port id is text, trimmed of trailing NUL and spaces. Returns [row, null] or [null, "field: why"].
	public static function row(array $row): array {
		$mac = static function (string $value, bool $strict): ?string {
			$octets = null;
			if (preg_match('/^[0-9a-f]{1,2}([:-][0-9a-f]{1,2})+$/i', $value)) {
				$octets = preg_split('/[:-]/', $value);
			}
			elseif (preg_match('/^[0-9a-f]{4}(\.[0-9a-f]{4})+$/i', $value)) {
				$octets = str_split(str_replace('.', '', $value), 2);
			}
			if ($octets === null) {
				return $value;                       // not shaped like a MAC: text, left alone
			}
			if (count($octets) !== 6) {
				// Wrong length: an error where the field is known to be a MAC (ifPhysAddress, a macAddress id). Elsewhere it
				// is another kind of id that happens to look alike (a local networkAddress chassis is colon hex of 5 octets).
				return $strict ? null : $value;
			}

			return implode(':', array_map(static fn (string $o): string => strtolower(str_pad($o, 2, '0', STR_PAD_LEFT)), $octets));
		};
		$ip = static fn (string $text): ?string => self::ipText($text);          // normalization §4.3: the same text as the native step and push.py

		foreach (['loc_chassis', 'mac', 'rem_chassis', 'rem_port', 'rem_mgmt_ip', 'rem_chassis_type', 'rem_port_type'] as $field) {
			if (isset($row[$field]) && is_string($row[$field])) {
				$row[$field] = rtrim($row[$field], "\0 ");
			}
		}
		// normalization §4.5: the cleaned value is the stored one; an empty result is no value
		foreach (['name', 'descr', 'if_name', 'rem_sysname', 'rem_port_desc', 'loc_port_desc'] as $field) {
			if (isset($row[$field]) && is_string($row[$field])) {
				if (($cleaned = self::clean($row[$field])) === null) {
					unset($row[$field]);
				}
				else {
					$row[$field] = $cleaned;
				}
			}
		}

		foreach (['loc_chassis' => false, 'mac' => true] as $field => $strict) {
			if (isset($row[$field]) && $row[$field] !== '') {
				if (($row[$field] = $mac($row[$field], $strict)) === null) {
					return [null, $field.': a MAC of the wrong length'];
				}
			}
		}
		// normalization §4.1/§4.2: a placeholder loc_chassis is not a loc_chassis (the reporter has none: model §6.4 steps 2 and 3); the row is valid
		if (isset($row['loc_chassis']) && self::isPlaceholderMac($row['loc_chassis'])) {
			unset($row['loc_chassis']);
		}
		foreach ([['rem_chassis', 'rem_chassis_type'], ['rem_port', 'rem_port_type']] as [$field, $type_field]) {
			if (!isset($row[$field]) || $row[$field] === '') {
				continue;
			}
			$type = $row[$type_field] ?? '';
			if ($type === 'macAddress') {
				if (($row[$field] = $mac($row[$field], true)) === null) {
					return [null, $field.': a MAC of the wrong length'];
				}
			}
			elseif ($type === 'networkAddress') {
				if (($normal = $ip($row[$field])) !== null) {
					$row[$field] = $normal;
				}
			}
			elseif (($cleaned = self::clean($row[$field])) === null) {
				unset($row[$field]);                 // a name field of another type (and a chassis id with no type): cleaned, never validated (§5)
			}
			else {
				$row[$field] = $cleaned;
			}
		}
		// model §6.7.1 (2.48): a CDP device id in MAC form (normalization §4.1, not a placeholder) is the claim's hardware ID, like an LLDP chassis id: the claim has a chassis
		// (`chassis:mac:<mac>`) and no name. The MAC comes from the device itself (MikroTik sends its LLDP chassis MAC as the CDP device id; SW12 its chassis MAC).
		if (($row['source'] ?? 'lldp') === 'cdp' && ($row['rem_chassis'] ?? '') === '' && isset($row['rem_sysname'])
				&& ($mac_id = CTopologyAddress::macFormId((string) $row['rem_sysname'])) !== null) {
			$row['rem_chassis'] = $mac_id;
			$row['rem_chassis_type'] = 'macAddress';
			unset($row['rem_sysname']);
		}
		if (isset($row['loc_port_id']) && is_string($row['loc_port_id'])) {
			// a macAddress port id is a MAC when it is shaped like one; it is only compared (§4.8), a bad one is no match, never a skipped row
			$cleaned = ($row['loc_port_id_type'] ?? '') === 'macAddress' ? ($mac($row['loc_port_id'], false) ?? $row['loc_port_id']) : self::clean($row['loc_port_id']);
			if ($cleaned === null || $cleaned === '') {
				unset($row['loc_port_id']);
			}
			else {
				$row['loc_port_id'] = $cleaned;
			}
		}
		if (isset($row['rem_mgmt_ip']) && $row['rem_mgmt_ip'] !== '') {
			if (($row['rem_mgmt_ip'] = $ip($row['rem_mgmt_ip'])) === null) {
				return [null, 'rem_mgmt_ip: not an IP address'];
			}
		}
		elseif (isset($row['rem_man_addrs']) && is_string($row['rem_man_addrs'])) {
			if (($address = self::manAddrs($row['rem_man_addrs'])) !== null) {
				$row['rem_mgmt_ip'] = $address;      // no usable entry: no address, not an invalid row (§4.4)
			}
		}
		// the raw entries stay in the row: the observation keeps them as received (model §7.1 remote_attrs), so the side panel can show every address the neighbor reports (§12.5 "Addresses")
		if (isset($row['rem_man_addrs']) && !is_string($row['rem_man_addrs'])) {
			unset($row['rem_man_addrs']);
		}

		return [$row, null];
	}
}

// BEGIN port-name-forms.tsv (generated by database/topology/discovery/gen_port_name_forms.py from normalization/port-name-forms.tsv; do not edit)
const TOPO_PORT_NAME_FORMS = [
	['twentyfivegigabitethernet', 'twe'],
	['hundredgigabitethernet', 'hu'],
	['fortygigabitethernet', 'fo'],
	['tengigabitethernet', 'te'],
	['twogigabitethernet', 'tw'],
	['xgigabitethernet', 'xge'],
	['gigabitethernet', 'gi'],
	['twentyfivegige', 'twe'],
	['fastethernet', 'fa'],
	['port-channel', 'po'],
	['hundredgige', 'hu'],
	['fortygige', 'fo'],
	['ethernet', 'et'],
	['tengige', 'te'],
	['eth', 'et'],
	['ge', 'gi'],
];
// END port-name-forms.tsv
