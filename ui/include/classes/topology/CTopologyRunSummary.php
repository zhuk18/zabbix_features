<?php

/**
 * The run summary of the topology ingest (topology-1-model.md §6.11, §12.7): what a run says about itself, read from the status file.
 * One class for the three readers: ingest.php (the CLI text and the exit code, the "Needs attention" list written into the summary),
 * GET topology.ingest.status and the last run view. No framework calls here: ingest.php loads this file without the frontend. The status of the last run is read from the run row (CTopologyRun::status()).
 */
class CTopologyRunSummary {

	public const RESULT_IDLE = 'idle';
	public const RESULT_RUNNING = 'running';
	public const RESULT_DONE = 'done';
	public const RESULT_DONE_WITH_WARNINGS = 'done_with_warnings';
	public const RESULT_FAILED = 'failed';

	// the exit code of the CLI: 0 only for a run that is "done" (§12.7)
	public const EXIT_DONE = 0;
	public const EXIT_FAILED = 1;
	public const EXIT_WARNINGS = 3;

	public const OUTCOMES = ['applied', 'device_only', 'shadowed', 'conflict', 'ambiguous', 'old_snapshot', 'port_limit', 'local_port_unresolved'];

	public const ATTENTION_REPORTER_SKIPPED = 'reporter_skipped';
	public const ATTENTION_REPORTER_FAILED = 'reporter_failed';
	public const ATTENTION_LOCAL_PORT_UNRESOLVED = 'local_port_unresolved';
	public const ATTENTION_ROWS_NO_IDENTITY = 'rows_no_identity';
	public const ATTENTION_SNAPSHOT_OLD = 'snapshot_old';
	public const ATTENTION_PORT_LIMIT = 'port_neighbor_limit';
	public const ATTENTION_PORTS_DISAGREE = 'hosts_ports_disagree';

	/**
	 * done / done with warnings / failed of a finished run (the list "Needs attention" is not empty: warnings); idle and running as the status file says.
	 */
	public static function result(?string $status, ?array $summary): string {
		switch ($status) {
			case 'running':
				return self::RESULT_RUNNING;
			case 'done':
				return $summary !== null && ($summary['attention'] ?? []) !== [] ? self::RESULT_DONE_WITH_WARNINGS : self::RESULT_DONE;
			case 'error':
				return self::RESULT_FAILED;
			default:
				return self::RESULT_IDLE;
		}
	}

	public static function exitCode(string $result): int {
		switch ($result) {
			case self::RESULT_DONE:
				return self::EXIT_DONE;
			case self::RESULT_DONE_WITH_WARNINGS:
				return self::EXIT_WARNINGS;
			default:
				return self::EXIT_FAILED;
		}
	}

	/**
	 * The rule ids the "Needs attention" lines of a summary name (the caller reads their host and name from the database).
	 *
	 * @return int[]
	 */
	public static function itemIds(array $summary): array {
		$ids = array_keys($summary['rows_no_identity']['by_rule'] ?? []);
		$ids = array_merge($ids, array_keys($summary['rows_local_port_unresolved']['by_rule'] ?? []), array_column($summary['snapshots_old'] ?? [], 'itemid'));

		return array_values(array_unique(array_map('intval', $ids)));
	}

	/**
	 * "Needs attention" (§12.7): one entry for each host and rule with a problem. $rules = itemid => ['hostid' => , 'host' => , 'rule' => ] as the database has it now.
	 * Entry: kind, reason, count, hostid, host, itemid, rule (null where the problem is the host's and not a rule's).
	 */
	public static function attention(array $summary, array $rules): array {
		$entry = static function (string $kind, string $reason, int $count, ?int $hostid, ?string $host, ?int $itemid = null, ?string $rule = null, ?int $clock = null): array {
			return ['kind' => $kind, 'reason' => $reason, 'count' => $count, 'hostid' => $hostid, 'host' => $host, 'itemid' => $itemid, 'rule' => $rule, 'clock' => $clock];
		};
		$of_rule = static function (int $itemid) use ($rules): array {
			$rule = $rules[$itemid] ?? null;

			return [$rule !== null ? (int) $rule['hostid'] : null, $rule['host'] ?? null, $itemid, $rule['rule'] ?? null];
		};
		$list = [];
		foreach ($summary['reporters_skipped_list'] ?? [] as $skipped) {
			$list[] = $entry(self::ATTENTION_REPORTER_SKIPPED, (string) $skipped['reason'], 1, (int) $skipped['hostid'], (string) $skipped['host']);
		}
		foreach ($summary['reporters_failed']['list'] ?? [] as $failed) {
			$list[] = $entry(self::ATTENTION_REPORTER_FAILED, $failed['step'].': '.$failed['error'], 1, (int) $failed['hostid'], (string) $failed['host']);
		}
		foreach ($summary['rows_local_port_unresolved']['by_rule'] ?? [] as $itemid => $reasons) {
			foreach ($reasons as $reason => $count) {
				if ($count > 0) {
					$list[] = $entry(self::ATTENTION_LOCAL_PORT_UNRESOLVED, (string) $reason, (int) $count, ...$of_rule((int) $itemid));
				}
			}
		}
		foreach ($summary['rows_no_identity']['by_rule'] ?? [] as $itemid => $count) {
			if ($count > 0) {
				$list[] = $entry(self::ATTENTION_ROWS_NO_IDENTITY, 'rows_no_identity', (int) $count, ...$of_rule((int) $itemid));
			}
		}
		// 2.60 (model §6.2): each old NEIGHBORS snapshot, with the time of the snapshot
		foreach ($summary['snapshots_old'] ?? [] as $old) {
			[$hostid, $host, $itemid, $rule] = $of_rule((int) $old['itemid']);
			// 2.96: the allowed age that the rule gave (its interval, never more than the freshness threshold)
			$allowed = isset($old['allowed_age']) ? ', allowed age '.(int) $old['allowed_age'].' s' : '';
			$list[] = $entry(self::ATTENTION_SNAPSHOT_OLD, 'old snapshot of '.date('Y-m-d H:i:s T', (int) $old['clock']).$allowed, 1, $hostid ?? (int) $old['hostid'], $host, $itemid, $rule, (int) $old['clock']);
		}
		// 2.65 (model §6.7.7): a reporter port with more neighbors than the limit
		foreach ($summary['ports_neighbor_limit'] ?? [] as $limited) {
			$list[] = $entry(self::ATTENTION_PORT_LIMIT, 'port '.$limited['local_port_ref'].': '.$limited['neighbors'].' neighbors', 1, (int) $limited['hostid'], (string) ($limited['host'] ?? ''));
		}
		// 2.69 (model §6.6): the two PORTS snapshots of a host disagree and none is newer
		foreach ($summary['hosts_ports_disagree']['list'] ?? [] as $host) {
			$list[] = $entry(self::ATTENTION_PORTS_DISAGREE, 'hosts_ports_disagree', 1, (int) $host['hostid'], (string) $host['host']);
		}

		return $list;
	}

	/**
	 * The text of the run summary that the CLI prints (§12.7): the same lines as the page, without links.
	 */
	public static function text(array $status): string {
		$summary = is_array($status['summary'] ?? null) ? $status['summary'] : [];
		$result = self::result($status['status'] ?? null, $summary ?: null);
		$time = static fn (?int $clock): string => $clock ? date('Y-m-d H:i:s T', $clock) : '-';
		$lines = [
			'Run summary',
			'  status:      '.str_replace('_', ' ', $result).(($status['error'] ?? null) !== null ? ' ('.$status['error'].')' : ''),
			'  started:     '.$time($status['started_at'] ?? null).' by '.($status['started_by'] ?? '-'),
			'  finished:    '.$time($status['finished_at'] ?? null)
		];
		if ($summary) {
			$lines[] = '  changes:     Devices created '.($summary['devices_created'] ?? 0).', links created '.($summary['links_created'] ?? 0).
				', links superseded '.($summary['links_superseded'] ?? 0).', links revived '.($summary['links_revived'] ?? 0).
				', new duplicate candidates '.($summary['duplicate_candidates']['new'] ?? 0).
				(($summary['links_ports_differ'] ?? 0) > 0 ? ', links with different LLDP and CDP ports '.$summary['links_ports_differ'] : '');
			$outcomes = [];
			foreach (self::OUTCOMES as $outcome) {
				$outcomes[] = $outcome.' '.($summary['observations'][$outcome] ?? 0);
			}
			$lines[] = '  observations: '.implode(', ', $outcomes);
			if (isset($summary['stats']['heartbeat_gap_max_seconds'])) {
				$lines[] = '  heartbeat gap: '.$summary['stats']['heartbeat_gap_max_seconds'].' s'.(($summary['stats']['heartbeat_gap_step'] ?? '') !== '' ? ' (closed in: '.$summary['stats']['heartbeat_gap_step'].')' : '');
			}
			$attention = $summary['attention'] ?? [];
			$lines[] = '  needs attention: '.($attention ? count($attention) : 'nothing');
			foreach ($attention as $line) {
				$lines[] = '    '.($line['host'] ?? '-').($line['rule'] !== null ? ' / '.$line['rule'] : '').': '.$line['kind'].' '.$line['reason'].
					($line['count'] > 1 ? ' ('.$line['count'].')' : '');
			}
		}

		return implode("\n", $lines)."\n";
	}
}
