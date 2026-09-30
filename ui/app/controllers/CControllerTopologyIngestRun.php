<?php

/**
 * POST /topo/ingest/run (spec §6) — starts database/topology/discovery/ingest.php (the same CLI script,
 * §4.1) as a detached background OS process and returns immediately. This is deliberately *not* a
 * reimplementation of ingest.php's logic in PHP-in-process: spawning the literal same script file is
 * what satisfies the spec's "must call the exact same ingest logic the CLI already uses" requirement
 * without a second, parallel implementation of §3's rules.
 *
 * The concurrency lock (§6 — "must go through the same run-lock as the CLI") lives inside ingest.php
 * itself (flock() on .ingest.lock next to the script), so both a CLI-triggered run and a button-triggered
 * one always go through the same lock regardless of which process gets there first — nothing extra is
 * needed here beyond spawning the script.
 *
 * Arg sourcing (flagged rather than hardcoded silently, per spec §1's "flag rather than expand scope"):
 *  - --api-url / --api-token: this controller runs inside an authenticated Zabbix web session, so rather
 *    than minting a new API token it reuses the current session id (CWebUser::$data['sessionid']) as the
 *    bearer credential against this same frontend's api_jsonrpc.php — the JSON-RPC layer
 *    (CLocalApiClient::authenticate()) already accepts either a 64-char API token or a session id under
 *    the same Bearer header, so no new credential-management surface is needed.
 *  - --pdo-dsn/--pdo-user/--pdo-password: read from the frontend's own $DB global (populated from
 *    ui/conf/zabbix.conf.php), which is the same database ingest.php needs to reach directly via PDO.
 *  - No reporters argument is passed: ingest.php discovers its reporters itself, dynamically, via
 *    item.get on the topology.discovery.raw key (spec §4.1, "Reporter discovery must be dynamic") —
 *    every host onboarded with the template is automatically picked up. Reporter selection is not this
 *    controller's concern at all; there is nothing here to source or flag.
 */
class CControllerTopologyIngestRun extends CController {
	protected function init(): void {
		$this->setPostContentType(self::POST_CONTENT_TYPE_JSON);
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool { return true; }
	protected function checkPermissions(): bool { return !CWebUser::isGuest(); }

	protected function doAction() {
		CTopologyIngest::start();

		$this->setResponse(new CControllerResponseData([
			'main_block' => json_encode(['status' => 'started'])
		]));
	}
}
