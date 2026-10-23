<?php

declare(strict_types=1);

/**
 * IngestRun — the state of one run, passed to the step modules (topology-1-model.md §6.0, §6.8).
 *
 * What the closures of ingest.php shared before is a property here: the connection, the clock, the run summary (§6.11), the tables that a step fills for a later
 * step (the reporters' addresses, the claims of a port, the duplicate candidates, ...). A module reads and writes only what its input and output name.
 * The step modules are properties too (`$run->keys`, ...), so a step reaches another step through the run.
 */
class IngestRun {
	public PDO $pdo;
	public int $now;
	/** the quoted name of the column `key` of topo_identity_keys (it is a reserved word) */
	public string $q_key;
	/** the run summary (the status file: model "Run summary") */
	public array $summary = [];
	/** hostid => segment, read once per host per run (IngestKeys::segmentOf) */
	public array $run_segments = [];

	/** the prepared statements of topo_nodes and topo_edges */
	public PDOStatement $insert_node;
	public PDOStatement $insert_port_node;
	public PDOStatement $update_node;
	public PDOStatement $insert_edge;

	public IngestKeys $keys;
	public IngestStore $store;

	public function __construct(PDO $pdo, int $now, string $q_key) {
		$this->pdo = $pdo;
		$this->now = $now;
		$this->q_key = $q_key;
	}
}
