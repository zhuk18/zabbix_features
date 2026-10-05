<?php

/**
 * POST /topo/ingest/run (topology-1-model.md "API") — starts database/topology/discovery/ingest.php (the same CLI script,
 * see topology-1-model.md "Runs") as a detached background OS process and returns immediately. This is deliberately *not* a
 * reimplementation of ingest.php's logic in PHP-in-process: spawning the literal same script file is
 * what satisfies the spec's "must call the exact same ingest logic the CLI already uses" requirement
 * without a second, parallel implementation of the reconciliation rules (topology-1-model.md "Link rules").
 *
 * The concurrency lock (topology-1-model.md "Runs": the UI run goes through the same lock as the CLI) lives inside ingest.php
 * itself (flock() on .ingest.lock next to the script), so both a CLI-triggered run and a button-triggered
 * one always go through the same lock regardless of which process gets there first — nothing extra is
 * needed here beyond spawning the script.
 *
 * Identity (topology-5-access-control.md §2.1): the run is a system process. It gets no session id and no API credential; the database
 * settings reach it through its environment (CTopologyIngest::start()), never through the command line, and it finds the reporters in the
 * database itself, so the result does not depend on who pressed the button. Until the role rule "Run topology ingest" exists the button
 * is for Super admin only.
 */
class CControllerTopologyIngestRun extends CController {
	protected function init(): void {
		$this->setPostContentType(self::POST_CONTENT_TYPE_JSON);
	}

	protected function checkInput(): bool { return true; }
	protected function checkPermissions(): bool { return CWebUser::getType() == USER_TYPE_SUPER_ADMIN; }

	protected function doAction() {
		try {
			CTopologyIngest::start();
			$this->setResponse(new CControllerResponseData(['main_block' => json_encode(['status' => 'started'])]));
		}
		catch (Exception $exception) {
			$this->setResponse(new CControllerResponseData(['main_block' => json_encode([
				'error' => ['messages' => [$exception->getMessage()]]
			])]));
		}
	}
}
