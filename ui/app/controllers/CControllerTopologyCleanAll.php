<?php

/**
 * POST /topology/clean/all — wipes the `topo_nodes`/`topo_edges` tables entirely.
 *
 * On this branch (topology_cloude), these two tables ARE the whole persisted graph:
 * `CTopologyPrototype` (the only topology model here — there is no tag/LLD-based T-model on
 * this branch) reads and writes them directly via raw SQL. Clicking this button therefore wipes
 * every device/port/host node and every physical_link edge outright; the topology view will
 * render empty until `ingest.php`/"Pull Zabbix hosts" repopulates it from scratch.
 *
 * `topo_edges` is deleted before `topo_nodes` for clarity, even though the FKs
 * (topo_edges_1/topo_edges_2 → topo_nodes.id, both ON DELETE CASCADE) would clean it up on their
 * own from a `topo_nodes` delete alone.
 */
class CControllerTopologyCleanAll extends CController {
	protected function init(): void {
		$this->setPostContentType(self::POST_CONTENT_TYPE_JSON);
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool { return true; }
	protected function checkPermissions(): bool { return !CWebUser::isGuest(); }

	protected function doAction(): void {
		// DBexecute() returns bool, not an affected-row count (ui/include/db.inc.php) -- counted
		// explicitly beforehand so the response can tell the caller something real happened,
		// not just "the query didn't error".
		$edges_before = (int) DBfetch(DBselect('SELECT COUNT(*) AS cnt FROM topo_edges'))['cnt'];
		$nodes_before = (int) DBfetch(DBselect('SELECT COUNT(*) AS cnt FROM topo_nodes'))['cnt'];

		DBexecute('DELETE FROM topo_edges');
		DBexecute('DELETE FROM topo_nodes');

		$this->setResponse(new CControllerResponseData(['main_block' => json_encode([
			'status' => 'ok', 'edges_deleted' => $edges_before, 'nodes_deleted' => $nodes_before
		])]));
	}
}
