<?php

/**
 * The run summary of the topology ingest (topology-1-model.md §6.11, §12.7): what a run says about itself, read from the status file.
 * One class for the three readers: ingest.php (the CLI text and the exit code, the "Needs attention" list written into the summary),
 * GET topology.ingest.status and the last run view. No framework calls here: ingest.php loads this file without the frontend.
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

	public const OUTCOMES = ['applied', 'device_only', 'shadowed', 'conflict', 'ambiguous'];

	public const ATTENTION_REPORTER_SKIPPED = 'reporter_skipped';
	public const ATTENTION_REPORTER_FAILED = 'reporter_failed';
	public const ATTENTION_LOCAL_PORT_UNRESOLVED = 'local_port_unresolved';
	public const ATTENTION_ROWS_NO_IDENTITY = 'rows_no_identity';
	public const ATTENTION_PORTS_WITHOUT_ITEMS = 'hosts_ports_without_items';

	/**
	 * The status file of the last run (the one ingest.php writes in the temporary directory); the "idle" state when no run has been made.
	 */
	public static function read(): array {
		$file = sys_get_temp_dir().'/topology-ingest-status.json';
		$status = is_file($file) ? json_decode((string) @file_get_contents($file), true) : null;

		return is_array($status) ? $status
			: ['status' => 'idle', 'started_at' => null, 'finished_at' => null, 'summary' => null];
	}

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
		$ids = array_merge($ids, array_keys($summary['rows_local_port_unresolved']['by_rule'] ?? []));

		return array_values(array_unique(array_map('intval', $ids)));
	}

	/**
	 * "Needs attention" (§12.7): one entry for each host and rule with a problem. $rules = itemid => ['hostid' => , 'host' => , 'rule' => ] as the database has it now.
	 * Entry: kind, reason, count, hostid, host, itemid, rule (null where the problem is the host's and not a rule's).
	 */
	public static function attention(array $summary, array $rules): array {
		$entry = static function (string $kind, string $reason, int $count, ?int $hostid, ?string $host, ?int $itemid = null, ?string $rule = null): array {
			return ['kind' => $kind, 'reason' => $reason, 'count' => $count, 'hostid' => $hostid, 'host' => $host, 'itemid' => $itemid, 'rule' => $rule];
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
		foreach ($summary['hosts_ports_without_items']['list'] ?? [] as $host) {
			$list[] = $entry(self::ATTENTION_PORTS_WITHOUT_ITEMS, 'hosts_ports_without_items', 1, (int) $host['hostid'], (string) $host['host']);
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
				', new duplicate candidates '.($summary['duplicate_candidates']['new'] ?? 0);
			$outcomes = [];
			foreach (self::OUTCOMES as $outcome) {
				$outcomes[] = $outcome.' '.($summary['observations'][$outcome] ?? 0);
			}
			$lines[] = '  observations: '.implode(', ', $outcomes);
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
