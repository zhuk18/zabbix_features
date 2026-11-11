<?php

require_once __DIR__.'/../../../../database/topology/topology_run.inc.php';

/**
 * The run row of the topology reconciliation (topology-1-model.md §6.1, 2.65; FR-C O-7, O-14, V-21), through the frontend database layer: the UI only requests a run (the scheduled
 * CLI job executes it), reads the state and the last run from the row, and takes the run lock around a write action. The constants are in database/topology/topology_run.inc.php,
 * the same file that ingest.php uses.
 */
class CTopologyRun {

	/** the lock this request holds, for the shutdown release */
	private static ?string $held = null;

	private static function ensure(): void {
		DBexecute(CTopologyRun::isPostgres()
			? 'INSERT INTO topo_run (id) VALUES (1) ON CONFLICT DO NOTHING'
			: 'INSERT IGNORE INTO topo_run (id) VALUES (1)');
	}

	private static function isPostgres(): bool {
		global $DB;

		return $DB['TYPE'] === ZBX_DB_POSTGRESQL;
	}

	/** @return array|null  the row of topo_run */
	public static function row(): ?array {
		$row = DBfetch(DBselect('SELECT * FROM topo_run WHERE id=1'), false);

		return $row ?: null;
	}

	/** The state of the run and the request (topology_run_state). */
	public static function state(): array {
		return topology_run_state(self::row(), time());
	}

	/**
	 * Records "run requested" (POST /topo/ingest/run, "accept discovery") with the user name: the run that takes the request records it as `started_by`. The first request of a series keeps its time and its user, so `executor_missing` counts from it.
	 */
	public static function request(): int {
		self::ensure();
		$now = time();
		DBexecute('UPDATE topo_run SET requested_at='.zbx_dbstr($now).', requested_by='.zbx_dbstr(substr((string) (CWebUser::$data['username'] ?? ''), 0, 100)).' WHERE id=1 AND requested_at=0');

		return (int) (self::row()['requested_at'] ?? $now);
	}

	/**
	 * The status of the last run as the status endpoint and the last run view read it (the shape of the status file): status, started_at, finished_at, started_by, run_id, error, summary.
	 * A run that died (status running, heartbeat older than the timeout) is an error.
	 */
	public static function status(): array {
		$row = self::row();
		if ($row === null || $row['status'] === 'idle') {
			return ['status' => 'idle', 'started_at' => null, 'finished_at' => null, 'summary' => null];
		}
		$state = topology_run_state($row, time());
		$summary = $row['summary'] !== null && $row['summary'] !== '' ? json_decode((string) $row['summary'], true) : null;

		return [
			'status' => $state['status'],
			'started_at' => (int) $row['started_at'] ?: null,
			'finished_at' => (int) $row['finished_at'] ?: null,
			'started_by' => $row['started_by'],
			'run_id' => $row['run_id'],
			'error' => $state['status'] === 'error' && $row['status'] === 'running' ? 'The run stopped without a final status' : $row['error'],
			'summary' => is_array($summary) ? $summary : null
		];
	}

	/**
	 * The number of reporters with new data (model §12.7 "Behind the data", 2.67): the hosts with at least one usable snapshot (§6.2: an enabled rule of the current topology type on a
	 * monitored host) whose `rows_hash` is not the hash that the last runs read (table topo_run_input), or that no run read. The hash, not `clock`: the clock changes at every poll.
	 */
	public static function reportersNewData(): int {
		$row = DBfetch(DBselect(
			'SELECT COUNT(DISTINCT s.hostid) AS cnt FROM topo_lld_snapshot s'.
			' JOIN items i ON i.itemid=s.itemid AND i.status='.ITEM_STATUS_ACTIVE.' AND i.topology_type=s.topology_type'.
			' JOIN hosts h ON h.hostid=s.hostid AND h.status='.HOST_STATUS_MONITORED.
			' LEFT JOIN topo_run_input r ON r.itemid=s.itemid'.
			' WHERE r.itemid IS NULL OR r.rows_hash<>s.rows_hash'
		), false);

		return (int) ($row['cnt'] ?? 0);
	}

	/**
	 * Takes the run lock for a write action: waits up to TOPOLOGY_ACTION_WAIT seconds, then gives up. The lock is released by release() and, whatever happens to the request, at its end.
	 */
	public static function lock(): bool {
		self::ensure();
		$owner = 'action:'.(gethostname() ?: 'host').':'.getmypid().':'.bin2hex(random_bytes(4));
		$deadline = microtime(true) + TOPOLOGY_ACTION_WAIT;
		do {
			$now = time();
			DBexecute('UPDATE topo_run SET owner='.zbx_dbstr($owner).', heartbeat='.zbx_dbstr($now).
				' WHERE id=1 AND (owner='.zbx_dbstr('').' OR heartbeat<'.zbx_dbstr($now - TOPOLOGY_RUN_LOCK_TIMEOUT).')');
			if ((self::row()['owner'] ?? '') === $owner) {
				if (self::$held === null) {
					register_shutdown_function([self::class, 'release']);
				}
				self::$held = $owner;

				return true;
			}
			usleep(100000);
		} while (microtime(true) < $deadline);

		return false;
	}

	public static function release(): void {
		if (self::$held !== null) {
			DBexecute('UPDATE topo_run SET owner='.zbx_dbstr('').' WHERE id=1 AND owner='.zbx_dbstr(self::$held));
			self::$held = null;
		}
	}

	/**
	 * Runs $action while this request holds the run lock (the transaction of the action is inside). When a run holds the lock for more than TOPOLOGY_ACTION_WAIT seconds, the action
	 * is refused with an Exception whose message is the text of the model (§6.1); the controllers show it in the JSON error form of the other topology errors.
	 *
	 * @throws Exception
	 */
	public static function guard(callable $action) {
		if (!self::lock()) {
			throw new Exception(TOPOLOGY_BUSY_MESSAGE);
		}
		try {
			return $action();
		}
		finally {
			self::release();
		}
	}
}
