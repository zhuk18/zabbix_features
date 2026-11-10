<?php

/**
 * The run row of the topology reconciliation (topology-1-model.md §6.1, 2.65): the constants of the run lock and the request, and the state that the row tells. No Zabbix dependencies:
 * ingest.php and the tests use the PDO functions, the frontend reads and writes the row through its own database layer (CTopologyRun) with the same constants and the same state function.
 */

/** A lock whose `heartbeat` is older than this many seconds is free (the run or the action that took it died). A run updates the heartbeat at least every TOPOLOGY_RUN_HEARTBEAT_EVERY seconds. */
const TOPOLOGY_RUN_LOCK_TIMEOUT = 120;
const TOPOLOGY_RUN_HEARTBEAT_EVERY = 10;
/** An operator write action waits this many seconds for the lock, then is refused. */
const TOPOLOGY_ACTION_WAIT = 5;
/** A request that no run took within this many seconds: `executor_missing`. */
const TOPOLOGY_REQUEST_MISSING_AFTER = 300;
const TOPOLOGY_BUSY_MESSAGE = 'A reconciliation is in progress. Try again.';

/**
 * What the row says now.
 *
 * @param array $row  the row of topo_run (null: no row yet)
 *
 * @return array  status (idle|running|done|error: a "running" row whose heartbeat is older than the timeout is a run that died: error), locked (a run or an action holds the lock),
 *                requested (a request waits), requested_at, executor_missing
 */
function topology_run_state(?array $row, int $now): array {
	if ($row === null) {
		return ['status' => 'idle', 'locked' => false, 'requested' => false, 'requested_at' => 0, 'executor_missing' => false];
	}
	$alive = (int) $row['heartbeat'] >= $now - TOPOLOGY_RUN_LOCK_TIMEOUT;
	$locked = $row['owner'] !== '' && $alive;
	$status = (string) $row['status'];
	if ($status === 'running' && !$alive) {
		$status = 'error';      // the run died without a final status
	}
	$requested_at = (int) $row['requested_at'];

	return ['status' => $status, 'locked' => $locked, 'requested' => $requested_at > 0, 'requested_at' => $requested_at,
		'executor_missing' => $requested_at > 0 && $requested_at < $now - TOPOLOGY_REQUEST_MISSING_AFTER];
}

/** The run row through PDO (ingest.php, the tests, the lab). */
class TopologyRunRow {

	public static function ensure(PDO $pdo): void {
		$pdo->exec($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql'
			? 'INSERT INTO topo_run (id) VALUES (1) ON CONFLICT DO NOTHING'
			: 'INSERT IGNORE INTO topo_run (id) VALUES (1)');
	}

	public static function fetch(PDO $pdo): ?array {
		$row = $pdo->query('SELECT * FROM topo_run WHERE id = 1')->fetch(PDO::FETCH_ASSOC);

		return $row === false ? null : $row;
	}

	/** A request waits. */
	public static function requested(PDO $pdo): bool {
		return (int) $pdo->query('SELECT requested_at FROM topo_run WHERE id = 1')->fetchColumn() > 0;
	}

	/**
	 * Takes the lock with one conditional UPDATE: the row is free (no owner) or its heartbeat is older than the timeout. A run (`$run` = [started_by, run_id]) also becomes the running
	 * run and takes the request ($clear_request); an action only holds the lock.
	 */
	public static function lock(PDO $pdo, string $owner, int $now, ?array $run = null, bool $clear_request = false): bool {
		self::ensure($pdo);
		$set = 'owner = ?, heartbeat = ?';
		$params = [$owner, $now];
		if ($run !== null) {
			$set .= ", status = 'running', started_at = ?, finished_at = 0, started_by = ?, run_id = ?, error = NULL, summary = NULL";
			array_push($params, $now, $run[0], $run[1]);
			if ($clear_request) {
				$set .= ", requested_at = 0, requested_by = ''";
			}
		}
		$stmt = $pdo->prepare("UPDATE topo_run SET {$set} WHERE id = 1 AND (owner = '' OR heartbeat < ?)");
		$stmt->execute(array_merge($params, [$now - TOPOLOGY_RUN_LOCK_TIMEOUT]));

		return self::owner($pdo) === $owner;
	}

	public static function owner(PDO $pdo): string {
		return (string) $pdo->query('SELECT owner FROM topo_run WHERE id = 1')->fetchColumn();
	}

	/** False when the lock is no longer ours (it was free by the timeout and another run took it). */
	public static function heartbeat(PDO $pdo, string $owner, int $now): bool {
		$stmt = $pdo->prepare('UPDATE topo_run SET heartbeat = ? WHERE id = 1 AND owner = ?');
		$stmt->execute([$now, $owner]);

		return self::owner($pdo) === $owner;
	}

	public static function release(PDO $pdo, string $owner): void {
		$pdo->prepare("UPDATE topo_run SET owner = '' WHERE id = 1 AND owner = ?")->execute([$owner]);
	}

	/** The status of the run that holds the lock (only the owner writes it): status, finished_at, error, summary (JSON). */
	public static function write(PDO $pdo, string $owner, string $status, int $finished_at, ?string $error, ?string $summary): void {
		$pdo->prepare('UPDATE topo_run SET status = ?, finished_at = ?, error = ?, summary = ? WHERE id = 1 AND owner = ?')
			->execute([$status, $finished_at, $error, $summary, $owner]);
	}

	/** The UI side of the request, for the tests: record a request. */
	public static function request(PDO $pdo, int $now, string $by = ''): void {
		self::ensure($pdo);
		$pdo->prepare('UPDATE topo_run SET requested_at = ?, requested_by = ? WHERE id = 1 AND requested_at = 0')->execute([$now, $by]);
	}

	/** Who requested the run that waits ('' when none or unknown). */
	public static function requestedBy(PDO $pdo): string {
		return (string) $pdo->query('SELECT requested_by FROM topo_run WHERE id = 1 AND requested_at > 0')->fetchColumn();
	}
}
