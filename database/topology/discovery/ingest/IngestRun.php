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
	/** the duplicate candidates and the chassis changes of the run (IngestIdentify; the marks, model §6.7.5, are made from them at the end) */
	public array $duplicate_candidates = [];
	public array $chassis_changes = [];
	/** local port node id => the Devices without a chassis that have it as scope_port (IngestIdentify::loadPortScope); null until the first claim without a chassis needs it */
	public ?array $port_scope = null;
	/** "hostid|local port" => the claims with a chassis of this run (pass B resolves them first, §6.5) */
	public array $port_chassis_claims = [];
	/** segment => address => [hostid => true]: the SNMP interface addresses of the reporters identified in this run; hostid => the Device of that reporter */
	public array $reporter_by_address = [];
	public array $reporter_device_of = [];
	/** hostid => segment, read once per host per run (IngestKeys::segmentOf) */
	public array $run_segments = [];

	/** the prepared statements of topo_nodes and topo_edges */
	public PDOStatement $insert_node;
	public PDOStatement $insert_port_node;
	public PDOStatement $update_node;
	public PDOStatement $insert_edge;

	public IngestKeys $keys;
	public IngestStore $store;
	public IngestIdentify $identify;
	public IngestFarPort $farport;
	public IngestLinks $links;

	public function __construct(PDO $pdo, int $now, string $q_key) {
		$this->pdo = $pdo;
		$this->now = $now;
		$this->q_key = $q_key;
	}
}
