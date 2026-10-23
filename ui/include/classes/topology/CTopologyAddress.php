<?php declare(strict_types = 0);
/*
** Copyright (C) 2001-2026 Zabbix SIA
**
** This program is free software: you can redistribute it and/or modify it under the terms of
** the GNU Affero General Public License as published by the Free Software Foundation, version 3.
**
** This program is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY;
** without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
** See the GNU Affero General Public License for more details.
**
** You should have received a copy of the GNU Affero General Public License along with this program.
** If not, see <https://www.gnu.org/licenses/>.
**/



/**
 * The addresses of a neighbor (topology-6-normalization.md §4.3 IP address text, §4.4 Management address): the one decoder of `rem_man_addrs` entries, used by ingest.php
 * (the management address it chooses) and by the side panel (every address the neighbors report, topology-1-model.md §12.5 "Addresses"). Pure functions, no database.
 */
class CTopologyAddress {

	/**
	 * §4.3: IPv4 dotted decimal, IPv6 as RFC 5952 text (inet_ntop would write an IPv4-compatible address with a dotted tail); null when it is not an address.
	 */
	public static function ipText(string $text): ?string {
		if (filter_var($text, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
			return $text;
		}

		$packed = filter_var($text, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false ? @inet_pton($text) : false;
		if ($packed === false) {
			return null;
		}

		$groups = array_values(unpack('n8', $packed));
		$best = -1;
		$best_len = 0;
		$run = -1;
		$run_len = 0;
		foreach ($groups as $i => $group) {
			if ($group === 0) {
				if ($run === -1) {
					$run = $i;
					$run_len = 0;
				}
				if (++$run_len > $best_len) {
					$best = $run;
					$best_len = $run_len;
				}
			}
			else {
				$run = -1;
			}
		}

		$hex = static fn (array $list): string => implode(':', array_map(static fn (int $g): string => dechex($g), $list));
		if ($best_len < 2) {
			return $hex($groups);
		}

		return $hex(array_slice($groups, 0, $best)).'::'.$hex(array_slice($groups, $best + $best_len));
	}

	/**
	 * §4.4: every usable entry of the raw `rem_man_addrs` ("lldp:<family.[len.]addr>" / "cdp:<type>:<hex octets>", space-separated, in index order), as
	 * [['address' => text, 'family' => 4|6], ...] in the order of the entries; unusable entries (other families, wrong lengths, CDP types other than 1) are left out.
	 */
	public static function decodeEntries(string $entries): array {
		$result = [];

		foreach (preg_split('/\s+/', trim($entries), -1, PREG_SPLIT_NO_EMPTY) as $entry) {
			$octets = null;
			$family = null;

			if (str_starts_with($entry, 'lldp:')) {
				$numbers = explode('.', substr($entry, 5));
				if ($numbers !== array_filter($numbers, static fn (string $n): bool => preg_match('/^[0-9]{1,3}$/D', $n) === 1 && (int) $n <= 255)) {
					continue;
				}

				$numbers = array_map('intval', $numbers);
				$family = array_shift($numbers);
				$length = $family === 1 ? 4 : ($family === 2 ? 16 : null);
				if ($length === null) {
					continue;
				}

				if (count($numbers) === $length) {                              // no length octet
					$octets = $numbers;
				}
				elseif (count($numbers) > $length && $numbers[0] === count($numbers) - 1) {   // a length octet; IPv4 with length 5: the first 4 octets (§4.4)
					$octets = array_slice($numbers, 1, $length);
					if (!($family === 1 && $numbers[0] === 5) && $numbers[0] !== $length) {
						continue;
					}
				}
				else {
					continue;
				}
			}
			elseif (str_starts_with($entry, 'cdp:')) {
				$parts = explode(':', substr($entry, 4));
				$type = array_shift($parts);
				if ($type !== '1' || count($parts) !== 4 || preg_grep('/^[0-9a-f]{2}$/Di', $parts) !== $parts) {
					continue;
				}

				$octets = array_map('hexdec', $parts);
				$family = 1;
			}
			else {
				continue;
			}

			if ($family === 1) {
				$result[] = ['address' => implode('.', $octets), 'family' => 4];
			}
			else {
				$text = self::ipText(implode(':', array_map(static fn (array $p): string => dechex($p[0] * 256 + $p[1]), array_chunk($octets, 2))));
				if ($text !== null) {
					$result[] = ['address' => $text, 'family' => 6];
				}
			}
		}

		return $result;
	}

	/**
	 * §4.4 (0.11): the management address from the raw entries. Unspecified, loopback and multicast entries are skipped; then the first IPv4 that is not link-local,
	 * else the first IPv6 that is not link-local, else the first link-local address; null when no entry is usable. (Reason: a loopback, unspecified or multicast
	 * address can't be the interface of a host; link-local is the last resort, `fe80::` is often the only IPv6 a neighbor sends.)
	 */
	public static function management(string $entries): ?string {
		$first = [4 => null, 6 => null, 'link_local' => null];
		foreach (self::decodeEntries($entries) as $entry) {
			if (self::isHidden($entry['address'])) {
				continue;
			}

			if (self::isLinkLocal($entry['address'])) {
				$first['link_local'] ??= $entry['address'];
			}
			else {
				$first[$entry['family']] ??= $entry['address'];
			}
		}

		return $first[4] ?? $first[6] ?? $first['link_local'];
	}

	/**
	 * The packed bytes of an address as an IPv4 address when it is one or an IPv4-mapped IPv6 address (::ffff:a.b.c.d), else of the IPv6 address; null when it is not an
	 * address. The rules below read the IPv4 form, so the mapped form of 127.0.0.1 is loopback too.
	 */
	private static function packed(string $address): ?string {
		$packed = @inet_pton($address);
		if ($packed === false) {
			return null;
		}

		return strlen($packed) === 16 && str_starts_with($packed, str_repeat("\0", 10)."\xff\xff") ? substr($packed, 12) : $packed;
	}

	/**
	 * An address that is not shown and is never a management address (topology-6-normalization.md §4.4, topology-1-model.md §12.5): unspecified (0.0.0.0, ::), loopback
	 * (127.0.0.0/8, ::1), multicast (224.0.0.0/4, ff00::/8; 0.11), or not an address.
	 */
	public static function isHidden(string $address): bool {
		$packed = self::packed($address);
		if ($packed === null) {
			return true;
		}

		return strlen($packed) === 4
			? $packed === "\0\0\0\0" || $packed[0] === "\x7f" || (ord($packed[0]) & 0xf0) === 0xe0
			: $packed === str_repeat("\0", 16) || $packed === str_repeat("\0", 15)."\x01" || $packed[0] === "\xff";
	}

	/**
	 * A link-local address (169.254.0.0/16, fe80::/10): shown after all the others, with the mark "link-local" (§4.4, §12.5).
	 */
	public static function isLinkLocal(string $address): bool {
		$packed = self::packed($address);
		if ($packed === null) {
			return false;
		}

		return strlen($packed) === 4
			? $packed[0] === "\xa9" && $packed[1] === "\xfe"
			: ord($packed[0]) === 0xfe && (ord($packed[1]) & 0xc0) === 0x80;
	}

	/**
	 * An address that can identify or match a neighbor (topology-1-model.md §6.7.1 "Match", §6.7.5 `same_address`): a valid address that is not unspecified, loopback or
	 * multicast (isHidden) and not link-local. (The section Addresses of the side panel shows the link-local ones; the match never uses them.)
	 */
	public static function countsAsIdentity(string $address): bool {
		return !self::isHidden($address) && !self::isLinkLocal($address);
	}

	/**
	 * The addresses of one neighbor claim that count for the match: `rem_mgmt_ip` and every entry of `rem_man_addrs` (normalization §4.4), in normal form, without
	 * duplicates, in the order they come. $attrs = the remote attributes of the claim (or the row).
	 */
	public static function claimAddresses(array $attrs): array {
		$found = [];
		if (is_string($attrs['rem_man_addrs'] ?? null)) {
			foreach (self::decodeEntries($attrs['rem_man_addrs']) as $entry) {
				$found[$entry['address']] = true;
			}
		}
		if (is_string($attrs['rem_mgmt_ip'] ?? null) && ($text = self::ipText($attrs['rem_mgmt_ip'])) !== null) {
			$found[$text] = true;
		}

		return array_values(array_filter(array_keys($found), [self::class, 'countsAsIdentity']));
	}

	/**
	 * The name key of a neighbor (§6.7.1 "Names", 2.49): trimmed, ASCII lower case, a final group in parentheses removed (`N9K-1(FDO2101X0AB)` = `n9k-1`, the Cisco NX-OS
	 * CDP device id `name(serial)`), then the domain cut at the first dot (`SW-X.lab` = `sw-x`); a name that looks like an address keeps its dots. Empty for no name.
	 */
	public static function nameKey(string $name): string {
		$key = strtolower(trim($name));
		$without_group = rtrim((string) preg_replace('/\([^()]*\)$/D', '', $key));
		if ($without_group !== '') {
			$key = $without_group;
		}

		if ($key !== '' && !preg_match('/^[0-9.]+$/D', $key) && ($dot = strpos($key, '.')) !== false && $dot > 0) {
			$key = substr($key, 0, $dot);
		}

		return $key;
	}

	/**
	 * The one place of the MAC text forms (normalization §4.1): the octets of a value that is shaped like a MAC, or null (text). Forms: groups of 1-2 hex digits with `:`
	 * only, pairs with `-` only, Cisco dotted `xxxx.xxxx.xxxx`; one separator in a value, so `:` and `-` mixed, or a group of 1 digit with `-`, is not a MAC (0.13).
	 * The count of octets is not checked here (the caller decides: 6 is a MAC, another count is an error where the field is known to be a MAC). The octets are as written
	 * (a group of 1 digit is not padded yet).
	 *
	 * @return string[]|null
	 */
	public static function macOctets(string $value): ?array {
		if (preg_match('/^(?:[0-9a-f]{1,2}(?::[0-9a-f]{1,2})+|[0-9a-f]{2}(?:-[0-9a-f]{2})+)$/i', $value) === 1) {
			return preg_split('/[:-]/', $value);
		}
		if (preg_match('/^[0-9a-f]{4}(\.[0-9a-f]{4})+$/i', $value) === 1) {
			return str_split(str_replace('.', '', $value), 2);
		}

		return null;
	}

	/**
	 * A CDP device id in MAC form (normalization §4.1: six octets with `:` or `-`, or the Cisco form `xxxx.xxxx.xxxx`) that is not a placeholder: the MAC in normal
	 * form, which is the claim's hardware ID (model §6.7.1, 2.48); else null. The text of any other form is a name.
	 */
	public static function macFormId(string $text): ?string {
		$octets = self::macOctets(trim($text));
		if ($octets === null || count($octets) !== 6) {
			return null;
		}
		$mac = strtolower(implode(':', array_map(static fn (string $o): string => str_pad($o, 2, '0', STR_PAD_LEFT), $octets)));

		return in_array($mac, ['00:00:00:00:00:00', 'ff:ff:ff:ff:ff:ff'], true) ? null : $mac;
	}

	/**
	 * The one match predicate of model §6.7.1 ("Match", "names differ") that the three rows for a claim without a chassis use (the reporter address, the claim with a
	 * chassis of the same port, a Device of the port), each with its own inputs. $a and $b = ['addrs' => the addresses that can match (claimAddresses()),
	 * 'name' => the name text, 'chassis' => the chassis id as text of a claim with a chassis (optional)].
	 * Match: (1) an address of one is an address of the other, or the name keys are equal, and (2) the names do not differ (both have a name and the name keys differ).
	 * A name that equals the chassis id of the other side as text is not a name: it matches that chassis (office lab F-1: HP ProCurve sends the LLDP chassis id as the CDP
	 * device id).
	 */
	public static function claimsMatch(array $a, array $b): bool {
		foreach ([[$a, $b], [$b, $a]] as [$one, $other]) {
			$text = trim((string) ($one['name'] ?? ''));
			if ($text !== '' && trim((string) ($other['chassis'] ?? '')) !== '' && strcasecmp($text, trim($other['chassis'])) === 0) {
				return true;
			}
		}

		$key_a = self::nameKey((string) ($a['name'] ?? ''));
		$key_b = self::nameKey((string) ($b['name'] ?? ''));
		$differ = $key_a !== '' && $key_b !== '' && $key_a !== $key_b;
		$equal = $key_a !== '' && $key_a === $key_b;

		return ($equal || array_intersect($a['addrs'] ?? [], $b['addrs'] ?? []) !== []) && !$differ;
	}

	/**
	 * The sort key of an address, the one order of the section: the addresses that are not link-local before the link-local ones, then IPv4 before IPv6 (family), then the
	 * numeric order (the packed bytes). The Device's `mgmt_ip` goes before all of them; the caller puts it first.
	 */
	public static function sortKey(string $address): string {
		$packed = @inet_pton($address);

		return (self::isLinkLocal($address) ? '1' : '0').($packed !== false && strlen($packed) === 4 ? '4' : '6').($packed === false ? $address : bin2hex($packed));
	}
}
