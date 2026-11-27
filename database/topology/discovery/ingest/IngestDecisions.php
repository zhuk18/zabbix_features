<?php

declare(strict_types=1);

/**
 * IngestDecisions — the operator's decisions as records (topology-1-model.md §6.7.8, 2.106; task 129).
 *
 * Every run reads the table `topo_decision` (the operator actions write it, ingest never does) and applies the records:
 *   - `merge`: every key of `key_a`, `key_b` and `keys_b` finds the Device of `key_a` as one Device (the merge class of CTopologyDecision::mergeClasses). Chains join, a pair with a `not_same`
 *     record is not joined (also through a chain) and the conflict is listed. The keys of a class find the Device in IngestKeys::classHolders(); a Device found or made gets the other keys of
 *     its class (giveKeys()), so a run over the snapshots alone (the rebuild property) ends with the same Device and the same keys as the operator's merge.
 *   - `not_same`: the pair is no duplicate candidate; `attrs.duplicate_dismissed` is computed from the records (dismissedPairs(), written by the candidates pass).
 *   - `accept_chassis`: the Device that holds the new chassis key takes the old chassis as a replaced chassis (applyOperatorRecords()).
 *   - `associate`: the Device of `key_a` is represented by the host or proxy (`represented_by_matched_by = manual`).
 * A record whose keys find no Device does nothing in that run and stays. A record whose keys find several Devices does nothing and is listed in "Needs attention" (`decisions_ambiguous`);
 * a merge that a `not_same` refuses is listed too (`decisions_conflict`). A merge whose Devices have only `port:` keys is not applied (no join, no guess; `decisions_not_applied`).
 */
class IngestDecisions {
	private IngestRun $run;
	/** the rows of topo_decision in the order of decisionid */
	public array $records = [];
	private array $classes = ['class_of' => [], 'keys' => [], 'conflicts' => []];
	/** key => the other keys of its merge class that name a Device by a key (not `port:` keys) */
	private array $alias = [];
	/** key => the key of the kept side of the first merge of its class (`key_a`): the chassis that a Device without a reporter keeps (§6.7.5: K keeps its chassis) */
	private array $primary = [];
	/** record key (decisionid) => the Device ids that its keys found: only the records that found several are kept here */
	private array $ambiguous = [];
	/** key => [Device ids] of the keyless Devices by natural key `port:...`, built when first needed */
	private ?array $port_keyed = null;

	public function __construct(IngestRun $run) {
		$this->run = $run;
	}

	/**
	 * Reads the records and builds the merge classes (stage Normalize: it runs before pass A). A database without the table (a stand-in of the Zabbix tables in a test) has no records.
	 */
	public function load(): void {
		$summary = &$this->run->summary;
		$summary['decisions'] = ['merge' => 0, 'not_same' => 0, 'accept_chassis' => 0, 'associate' => 0];
		$summary['decisions_conflict'] = [];
		$summary['decisions_ambiguous'] = [];
		$summary['decisions_not_applied'] = [];
		try {
			$this->records = $this->run->pdo->query('SELECT decisionid, kind, key_a, key_b, hostid, proxyid, keys_b, created_at, created_by FROM topo_decision ORDER BY decisionid')
				->fetchAll(PDO::FETCH_ASSOC);
		}
		catch (PDOException $exception) {
			$this->records = [];
		}
		foreach ($this->records as $record) {
			$summary['decisions'][$record['kind']] = ($summary['decisions'][$record['kind']] ?? 0) + 1;
		}
		$this->classes = CTopologyDecision::mergeClasses($this->records);
		foreach ($this->classes['conflicts'] as $conflict) {
			$summary['decisions_conflict'][] = $conflict;
		}
		$first_a = [];
		foreach ($this->records as $record) {
			if ($record['kind'] === CTopologyDecision::KIND_MERGE && isset($this->classes['class_of'][(string) $record['key_a']])) {
				$first_a[$this->classes['class_of'][(string) $record['key_a']]] ??= (string) $record['key_a'];
			}
		}
		foreach ($this->classes['keys'] as $class => $keys) {
			foreach ($keys as $key) {
				if (isset($first_a[$class])) {
					$this->primary[$key] = $first_a[$class];
				}
			}
			$named = array_values(array_filter($keys, static fn (string $key): bool => !CTopologyDecision::isPortKey($key)));
			if (count($named) < 2) {
				continue;
			}
			foreach ($named as $key) {
				$this->alias[$key] = array_values(array_diff($named, [$key]));
			}
		}
		$this->releaseKeys();
		// a merge of Devices that both have only `port:` keys cannot be applied (no join, no guess)
		foreach ($this->records as $record) {
			if ($record['kind'] === CTopologyDecision::KIND_MERGE && CTopologyDecision::isPortKey((string) $record['key_a']) && CTopologyDecision::isPortKey((string) $record['key_b'])) {
				$summary['decisions_not_applied'][] = ['decisionid' => (int) $record['decisionid'], 'kind' => 'merge', 'reason' => 'port_keys'];
			}
		}
	}

	/** The other keys of the merge class of a key (the keys that find the same Device), none when the key is in no merge. */
	public function aliasKeys(string $key): array {
		return $this->alias[$key] ?? [];
	}

	public function noteAmbiguous(string $key, array $device_ids): void {
		$summary = &$this->run->summary;
		foreach ($summary['decisions_ambiguous'] as $entry) {
			if ($entry['key'] === $key) {
				return;
			}
		}
		$ids = array_map('intval', $device_ids);
		sort($ids);
		$summary['decisions_ambiguous'][] = ['key' => $key, 'device_ids' => $ids];
	}

	/**
	 * A Device found or made by a key of a merge class holds the other keys of the class: a key that no other Device holds is added (a chassis key that is not the chassis of the Device is
	 * a replaced chassis, the chassis goes to `attrs.replaced_chassis`, as the operator's merge did, §6.7.5). Nothing is written when the Device already has them.
	 */
	public function giveKeys(int $device_id, int $seen_at): void {
		if (!$this->alias) {
			return;
		}
		$keys = $this->run->keys;
		$pdo = $this->run->pdo;
		$q_key = $this->run->q_key;
		$have = [];
		$stmt = $pdo->prepare("SELECT {$q_key} AS k FROM topo_identity_keys WHERE device_id = ?");
		$stmt->execute([$device_id]);
		foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $key) {
			$have[$key] = true;
		}
		$wanted = [];
		foreach (array_keys($have) as $key) {
			foreach ($this->alias[$key] ?? [] as $other) {
				if (!isset($have[$other])) {
					$wanted[$other] = true;
				}
			}
		}
		if (!$wanted) {
			return;
		}
		$store = $this->run->store;
		$before = $store->nodeAttrs($device_id);
		$attrs = $before;
		$chassis = (string) ($attrs['chassis_id'] ?? '');
		$replaced = (array) ($attrs['replaced_chassis'] ?? []);
		$merged = (array) ($attrs['merged_keys'] ?? []);
		foreach (array_keys($wanted) as $other) {
			if (array_diff($keys->keyHolders($other), [$device_id])) {
				continue;      // another Device holds it: the key would be shared; the record finds several Devices (listed by classHolders)
			}
			$merged[] = $other;      // a key that a record gave: released when the record is gone (releaseKeys)
			$value = CTopologyDecision::chassisOfKey($other);
			if ($value === null) {
				$keys->insertKey($device_id, $other, str_starts_with($other, 'cdp:') ? 'cdp' : 'chassis', $seen_at);
				continue;
			}
			$is_replaced = $value !== $chassis;
			$keys->insertKey($device_id, $other, 'chassis', $seen_at, $is_replaced);
			if ($is_replaced && !in_array($value, $replaced, true)) {
				$replaced[] = $value;
			}
		}
		if ($replaced) {
			$attrs['replaced_chassis'] = array_values(array_unique(array_diff($replaced, [$chassis])));
		}
		if (($attrs['replaced_chassis'] ?? []) === []) {
			unset($attrs['replaced_chassis']);
		}
		$attrs['merged_keys'] = array_values(array_unique($merged));
		// the Device of a merge keeps the chassis of the kept side (`key_a` of the first merge of the class), whichever claim made it first (the result must not depend on the order of the claims);
		// a reporter's Device keeps the chassis that its reporter reports (§6.4)
		$primary_key = null;
		foreach (array_merge(array_keys($have), array_keys($wanted)) as $held_key) {
			$primary_key ??= $this->primary[$held_key] ?? null;
		}
		$primary_value = $primary_key !== null ? CTopologyDecision::chassisOfKey($primary_key) : null;
		$reporter = $pdo->prepare("SELECT 1 FROM topo_nodes WHERE id = ? AND represented_by_matched_by = 'reporter_self' AND represented_by_node_id IS NOT NULL");
		$reporter->execute([$device_id]);
		if ($primary_value !== null && $primary_value !== $chassis && $reporter->fetchColumn() === false && $keys->deviceHoldsKey($device_id, $primary_key)) {
			$attrs['chassis_id'] = $primary_value;
			$attrs['mac'] = preg_match('/^([0-9a-f]{2}:){5}[0-9a-f]{2}$/i', $primary_value) === 1 ? strtolower($primary_value) : null;
			$all = $pdo->prepare("SELECT {$q_key} AS k FROM topo_identity_keys WHERE device_id = ? AND kind = 'chassis'");
			$all->execute([$device_id]);
			$replaced = [];
			foreach ($all->fetchAll(PDO::FETCH_COLUMN) as $chassis_key) {
				$value = CTopologyDecision::chassisOfKey($chassis_key);
				if ($value === null) {
					continue;
				}
				$pdo->prepare("UPDATE topo_identity_keys SET replaced = ? WHERE device_id = ? AND {$q_key} = ?")->execute([$value === $primary_value ? 0 : 1, $device_id, $chassis_key]);
				if ($value !== $primary_value) {
					$replaced[] = $value;
				}
			}
			$attrs['replaced_chassis'] = array_values(array_unique($replaced));
			if (!$replaced) {
				unset($attrs['replaced_chassis']);
			}
		}
		$store->writeAttrs($device_id, $attrs, $before);
	}

	/**
	 * Separation (§6.7.8): a key that a merge gave to a Device (`attrs.merged_keys`) and that no merge record names with a key of that Device any more (the record was deleted) is released:
	 * its key row goes, its chassis leaves `replaced_chassis`. The next claims with that key make a Device of their own, with a new node id; its links come from the snapshots.
	 */
	public function releaseKeys(): void {
		$pdo = $this->run->pdo;
		$store = $this->run->store;
		$q_key = $this->run->q_key;
		foreach ($pdo->query("SELECT id, attrs FROM topo_nodes WHERE type = 'device' AND attrs LIKE '%merged_keys%'")->fetchAll(PDO::FETCH_ASSOC) as $row) {
			ingest_tick('decisions: release keys');
			$device_id = (int) $row['id'];
			$attrs = json_decode($row['attrs'], true) ?: [];
			$merged = (array) ($attrs['merged_keys'] ?? []);
			if (!$merged) {
				continue;
			}
			$have = $pdo->prepare("SELECT {$q_key} AS k FROM topo_identity_keys WHERE device_id = ?");
			$have->execute([$device_id]);
			$held = array_flip($have->fetchAll(PDO::FETCH_COLUMN));
			$keep = [];
			$release = [];
			foreach ($merged as $key) {
				$others = array_diff($this->alias[$key] ?? [], $merged);      // a key of the class that is the Device's own (not given by a record)
				if (array_intersect($others, array_keys($held))) {
					$keep[] = $key;
				}
				else {
					$release[] = $key;
				}
			}
			if (!$release) {
				continue;
			}
			$before = $attrs;
			$replaced = (array) ($attrs['replaced_chassis'] ?? []);
			foreach ($release as $key) {
				$pdo->prepare("DELETE FROM topo_identity_keys WHERE device_id = ? AND {$q_key} = ?")->execute([$device_id, $key]);
				if (($value = CTopologyDecision::chassisOfKey($key)) !== null) {
					$replaced = array_values(array_diff($replaced, [$value]));
				}
			}
			$attrs['replaced_chassis'] = $replaced;
			if (!$replaced) {
				unset($attrs['replaced_chassis']);
			}
			if ($keep) {
				$attrs['merged_keys'] = array_values($keep);
			}
			else {
				unset($attrs['merged_keys']);
			}
			$store->writeAttrs($device_id, $attrs, $before);
		}
	}

	/** The Devices that a key (a natural key of a record or any key of its class) finds: [Device id, ...]. */
	public function devicesOf(string $key): array {
		if (CTopologyDecision::isPortKey($key)) {
			$this->loadPortKeyed();
			return $this->port_keyed[$key] ?? [];
		}
		$ids = $this->run->keys->keyHolders($key);
		foreach ($this->alias[$key] ?? [] as $other) {
			$ids = array_merge($ids, $this->run->keys->keyHolders($other));
		}

		return array_values(array_unique(array_map('intval', $ids)));
	}

	/** The keyless Devices by their natural key `port:<reporter hostid>:<port name key>:<name key>` (every scope port of a Device is a name of it). */
	private function loadPortKeyed(): void {
		if ($this->port_keyed !== null) {
			return;
		}
		$this->port_keyed = [];
		$pdo = $this->run->pdo;
		$has_key = array_flip(array_map('intval', $pdo->query('SELECT DISTINCT device_id FROM topo_identity_keys')->fetchAll(PDO::FETCH_COLUMN)));
		$ports = [];
		foreach ($pdo->query("SELECT p.id, p.attrs, h.host_ref FROM topo_nodes p JOIN topo_nodes d ON d.id = p.device_id JOIN topo_nodes h ON h.id = d.represented_by_node_id AND h.type = 'host'".
				" WHERE p.type = 'port'")->fetchAll(PDO::FETCH_ASSOC) as $row) {
			$ports[(int) $row['id']] = ['hostid' => (int) $row['host_ref'], 'name' => (string) (json_decode($row['attrs'], true)['name'] ?? '')];
		}
		foreach ($pdo->query("SELECT id, attrs FROM topo_nodes WHERE type = 'device' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC) as $row) {
			$id = (int) $row['id'];
			if (isset($has_key[$id])) {
				continue;
			}
			$attrs = json_decode($row['attrs'], true) ?: [];
			$name_key = CTopologyAddress::nameKey((string) ($attrs['sysname'] ?? ''));
			if ($name_key === '') {
				continue;
			}
			foreach (CTopologyAddress::scopePorts($attrs) as $port_id) {
				if (isset($ports[$port_id]) && ($port_key = CTopologyAddress::nameKey($ports[$port_id]['name'])) !== '') {
					$this->port_keyed['port:'.$ports[$port_id]['hostid'].':'.$port_key.':'.$name_key][] = $id;
				}
			}
		}
	}

	/**
	 * `associate` and `accept_chassis` (§6.7.8), after pass B: the Devices exist. A record that finds no Device does nothing; one that finds several is listed.
	 */
	public function applyOperatorRecords(): void {
		$pdo = $this->run->pdo;
		$store = $this->run->store;
		foreach ($this->records as $record) {
			ingest_tick('decisions: operator records');
			if ($record['kind'] === CTopologyDecision::KIND_ASSOCIATE) {
				$ids = $this->devicesOf((string) $record['key_a']);
				if (count($ids) > 1) {
					$this->noteAmbiguous((string) $record['key_a'], $ids);
					continue;
				}
				if (!$ids) {
					continue;
				}
				if ($record['hostid'] !== null) {
					$stmt = $pdo->prepare('SELECT 1 FROM hosts WHERE hostid = ?');
					$stmt->execute([$record['hostid']]);
					if ($stmt->fetchColumn() === false) {
						continue;
					}
					$target_node = $store->findOrCreateHostNode((string) $record['hostid']);
				}
				elseif ($record['proxyid'] !== null) {
					$target_node = $this->findOrCreateProxyNode((string) $record['proxyid']);
				}
				else {
					continue;
				}
				$store->promote($ids[0], $target_node, 'manual');      // false (already represented, target taken): nothing to do, the first association wins as it did
			}
			elseif ($record['kind'] === CTopologyDecision::KIND_ACCEPT_CHASSIS) {
				$this->applyAcceptChassis($record);
			}
		}
	}

	private function findOrCreateProxyNode(string $proxyid): int {
		$pdo = $this->run->pdo;
		$stmt = $pdo->prepare("SELECT id FROM topo_nodes WHERE type = 'proxy' AND proxy_ref = ?");
		$stmt->execute([$proxyid]);
		if (($id = $stmt->fetchColumn()) !== false) {
			return (int) $id;
		}
		$this->run->insert_node->execute(['proxy', '{}', $this->run->now, $this->run->now]);
		$id = (int) $pdo->lastInsertId();
		$pdo->prepare('UPDATE topo_nodes SET proxy_ref = ? WHERE id = ?')->execute([$proxyid, $id]);

		return $id;
	}

	/** `accept_chassis`: the Device that holds the new chassis (`key_b`) takes the old one (`key_a`) as a replaced chassis (a key marked replaced, the value in `attrs.replaced_chassis`). */
	private function applyAcceptChassis(array $record): void {
		$old_key = (string) $record['key_a'];
		$new_key = (string) ($record['key_b'] ?? '');
		$old_chassis = CTopologyDecision::chassisOfKey($old_key);
		if ($new_key === '' || $old_chassis === null || CTopologyDecision::chassisOfKey($new_key) === null) {
			return;
		}
		$ids = $this->run->keys->keyHolders($new_key);
		if (count($ids) !== 1) {
			if (count($ids) > 1) {
				$this->noteAmbiguous($new_key, $ids);
			}
			return;
		}
		$device_id = (int) $ids[0];
		if (array_diff($this->run->keys->keyHolders($old_key, true), [$device_id]) || array_diff($this->run->keys->keyHolders($old_key), [$device_id])) {
			return;      // another Device holds the old chassis: the record finds several Devices, nothing is guessed
		}
		$store = $this->run->store;
		$before = $store->nodeAttrs($device_id);
		$attrs = $before;
		$replaced = (array) ($attrs['replaced_chassis'] ?? []);
		if (!in_array($old_chassis, $replaced, true) && $old_chassis !== (string) ($attrs['chassis_id'] ?? '')) {
			$replaced[] = $old_chassis;
			$attrs['replaced_chassis'] = array_values($replaced);
		}
		$store->writeAttrs($device_id, $attrs, $before);
		$pdo = $this->run->pdo;
		$q_key = $this->run->q_key;
		if ($this->run->keys->deviceHoldsKey($device_id, $old_key)) {
			$pdo->prepare("UPDATE topo_identity_keys SET replaced = 1 WHERE device_id = ? AND {$q_key} = ?")->execute([$device_id, $old_key]);
		}
		else {
			$this->run->keys->insertKey($device_id, $old_key, 'chassis', $this->run->now, true);
		}
	}

	/**
	 * `not_same` (§6.7.8): the Device pairs that the records name, for the candidates pass and for `attrs.duplicate_dismissed`. A pair is named by the whole merge class of each side.
	 *
	 * @return array  ['a|b' (a < b) => the record row of the pair]
	 */
	public function dismissedPairs(): array {
		$pairs = [];
		foreach (CTopologyDecision::notSamePairs($this->records, $this->classes) as $n => [$keys_a, $keys_b]) {
			ingest_tick('closing: duplicate candidates');
			$a_ids = [];
			$b_ids = [];
			foreach ($keys_a as $key) {
				$a_ids = array_merge($a_ids, $this->devicesOf($key));
			}
			foreach ($keys_b as $key) {
				$b_ids = array_merge($b_ids, $this->devicesOf($key));
			}
			$record = $this->notSameRecords()[$n] ?? null;
			foreach (array_unique($a_ids) as $a) {
				foreach (array_unique($b_ids) as $b) {
					if ($a !== $b) {
						$pairs[min($a, $b).'|'.max($a, $b)] = $record;
					}
				}
			}
		}

		return $pairs;
	}

	/** The `not_same` rows in the order of CTopologyDecision::notSamePairs() (the ones that have a key_b). */
	private function notSameRecords(): array {
		return array_values(array_filter($this->records, static fn (array $r): bool => $r['kind'] === CTopologyDecision::KIND_NOT_SAME && ($r['key_b'] ?? null) !== null));
	}
}
