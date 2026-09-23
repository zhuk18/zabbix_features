#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * dump_graph.php — prints one backend's current graph (devices + relations) as JSON on stdout.
 *
 * compare.py (F11) shells out to this twice, once per model, rather than re-implementing the
 * graph-assembly queries itself in Python — that would risk exactly the "reinvent G-model logic"
 * problem constraint 5 warns against, just one language removed. Going through the real
 * CTopologyPrototype/CTopologyTModel classes means compare.py is diffing what the UI actually
 * renders, not a parallel reconstruction of it.
 *
 * Bootstrap: GOTCHAS.md #3 -- both classes call through the real Zabbix API layer (API::Host(),
 * API::Item(), API::Problem()), which needs a fully authenticated API context even from the CLI.
 *
 * Usage: dump_graph.php --model=G|T
 */

chdir(__DIR__.'/../../../ui');
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
require_once 'include/classes/core/APP.php';
APP::getInstance()->run(APP::EXEC_MODE_API);
API::getWrapper()->auth = ['type' => 0];

$userdata = [
	'userid' => '1', 'username' => 'Admin', 'name' => '', 'surname' => '', 'url' => '',
	'autologin' => 0, 'autologout' => '15m', 'lang' => 'en_US', 'refresh' => '30s',
	'theme' => 'default', 'attempt_failed' => 0, 'attempt_ip' => '', 'attempt_clock' => 0,
	'rows_per_page' => 50, 'roleid' => 3, 'userdirectoryid' => null, 'type' => USER_TYPE_SUPER_ADMIN,
	'userip' => '127.0.0.1', 'sessionid' => 'compare-cli', 'gui_access' => GROUP_GUI_ACCESS_SYSTEM,
	'debug_mode' => false, 'mfaid' => 0, 'timezone' => 'default'
];
CWebUser::$data = $userdata;
CApiService::$userData = $userdata;

$options = getopt('', ['model:']);
$model = $options['model'] ?? null;
if (!in_array($model, ['G', 'T'], true)) {
	fwrite(STDERR, "Usage: dump_graph.php --model=G|T\n");
	exit(1);
}

$t0 = microtime(true);
if ($model === 'G') {
	$devices = CTopologyPrototype::getDevices();
	$relations = CTopologyPrototype::getRelations();
}
else {
	$devices = CTopologyTModel::getDevices();
	$relations = CTopologyTModel::getRelations();
}
$elapsed_ms = round((microtime(true) - $t0) * 1000, 1);

echo json_encode(['model' => $model, 'devices' => $devices, 'relations' => $relations,
	'elapsed_ms' => $elapsed_ms], JSON_THROW_ON_ERROR), "\n";
