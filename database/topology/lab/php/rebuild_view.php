#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * rebuild_view.php — prints the state that the rebuild property compares (topology-1-model.md §6.7.8, task 129) as JSON, read from the tables (it checks storage; it writes nothing).
 *
 * Usage: rebuild_view.php [--pdo-dsn <dsn>] [--pdo-user <user>]      (default: the lab database `zabbix` through the socket, user `zabbix`)
 */

require_once __DIR__.'/../../discovery/decision_state.php';

$options = getopt('', ['pdo-dsn::', 'pdo-user::']);
$pdo = new PDO($options['pdo-dsn'] ?? 'mysql:unix_socket=/run/mysqld/mysqld.sock;dbname=zabbix', $options['pdo-user'] ?? 'zabbix', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
echo json_encode(state_view($pdo), JSON_UNESCAPED_SLASHES), "\n";
