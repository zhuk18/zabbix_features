#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * tmodel_call.php — generic CLI bootstrap for calling any CTopologyTModel public method from the
 * Python test harness (harness.py's php_call()). Same GOTCHAS.md #3 authentication bootstrap as
 * dump_graph.php/test_tags_model.php; kept separate from dump_graph.php because the campaign
 * needs more than devices/relations (diagnostics(), getPorts(), getNeighbors(), the write
 * endpoints for scenarios that call them, etc.) and passing an arbitrary method + args is simpler
 * than adding a new one-off dump script per scenario.
 *
 * Usage: tmodel_call.php <method> '<json array of args>'
 * Prints the method's return value as JSON on stdout.
 */

chdir(__DIR__.'/../../ui');
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
require_once 'include/classes/core/APP.php';
APP::getInstance()->run(APP::EXEC_MODE_API);
API::getWrapper()->auth = ['type' => 0];

$userdata = [
	'userid' => '1', 'username' => 'Admin', 'name' => '', 'surname' => '', 'url' => '',
	'autologin' => 0, 'autologout' => '15m', 'lang' => 'en_US', 'refresh' => '30s',
	'theme' => 'default', 'attempt_failed' => 0, 'attempt_ip' => '', 'attempt_clock' => 0,
	'rows_per_page' => 50, 'roleid' => 3, 'userdirectoryid' => null, 'type' => USER_TYPE_SUPER_ADMIN,
	'userip' => '127.0.0.1', 'sessionid' => 'tmodel-campaign', 'gui_access' => GROUP_GUI_ACCESS_SYSTEM,
	'debug_mode' => false, 'mfaid' => 0, 'timezone' => 'default'
];
CWebUser::$data = $userdata;
CApiService::$userData = $userdata;

if ($argc < 2) {
	fwrite(STDERR, "Usage: tmodel_call.php <method> '<json array of args>'\n");
	exit(1);
}

$method = $argv[1];
$args = $argc > 2 ? json_decode($argv[2], true, 512, JSON_THROW_ON_ERROR) : [];

if (!is_array($args)) {
	fwrite(STDERR, "Args must be a JSON array.\n");
	exit(1);
}

try {
	$result = CTopologyTModel::$method(...$args);
	echo json_encode(['ok' => true, 'result' => $result], JSON_THROW_ON_ERROR), "\n";
}
catch (Throwable $e) {
	echo json_encode(['ok' => false, 'error' => $e->getMessage(), 'class' => get_class($e)],
		JSON_THROW_ON_ERROR), "\n";
}
