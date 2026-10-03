<?php

declare(strict_types=1);

/**
 * Lab PHP helpers: load the Zabbix PHP layer (the topology classes) against the real `zabbix` database.
 *
 * The lab tool never opens a session. The topology classes need only the database layer, plus the API facade for the
 * list of visible hosts; that one call is stubbed to "every host". Nothing else of the API is reachable from here.
 *
 * The database name is read from ui/conf/zabbix.conf.php and must be `zabbix`; anything else stops the helper before
 * it connects (topology-4-lab-simulator.md "Constraints").
 */

const LAB_REQUIRED_DATABASE = 'zabbix';

$ui = realpath(__DIR__.'/../../../../ui');
chdir($ui);
set_include_path($ui);
require_once 'include/defines.inc.php';                   // zabbix.conf.php uses its constants

$conf = (static function (string $file): array {
	require $file;                                        // sets $DB['...'] entries
	return $DB ?? [];
})($ui.'/conf/zabbix.conf.php');

if (($conf['DATABASE'] ?? null) !== LAB_REQUIRED_DATABASE) {
	fwrite(STDERR, 'refusing to start: the database in zabbix.conf.php is '.json_encode($conf['DATABASE'] ?? null).
		', only "'.LAB_REQUIRED_DATABASE."\" is allowed\n");
	exit(2);
}
if (($conf['TYPE'] ?? '') !== 'MYSQL') {
	fwrite(STDERR, "the lab helpers support MySQL only\n");
	exit(2);
}

$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
require_once 'include/classes/core/CAutoloader.php';

/** Stub of the API facade: only the visible hosts matter to the topology classes. */
class API {
	public static function Host() {
		return new class {
			public function get($options) {
				$hosts = [];
				foreach (DBselect('SELECT hostid FROM hosts') as $row) {
					$hosts[] = ['hostid' => (string) $row['hostid']];
				}
				return $hosts;
			}
		};
	}
}

foreach (['debug', 'gettextwrapper', 'defines', 'func', 'html', 'perm', 'users', 'validate', 'locales', 'db'] as $file) {
	require_once "include/{$file}.inc.php";
}
$loader = new CAutoloader();
$loader->addNamespace('', array_map(static fn (string $path): string => "{$ui}/include/classes/{$path}", [
	'api', 'api/services', 'api/helpers', 'api/item_types', 'api/managers', 'api/clients', 'api/wrappers', 'core', 'data',
	'mvc', 'db', 'debug', 'validators', 'validators/schema', 'validators/string', 'validators/object', 'helpers', 'json',
	'routing', 'controllers', 'user', 'topology', 'parsers', 'parsers/results', 'regexp', 'macros', 'items', 'triggers'
]));
$loader->addNamespace('', ["{$ui}/app/controllers"]);
$loader->register();

global $DB;
$DB = ['TYPE' => ZBX_DB_MYSQL, 'SERVER' => $conf['SERVER'] ?? 'localhost', 'PORT' => $conf['PORT'] ?? '0',
	'DATABASE' => $conf['DATABASE'], 'USER' => $conf['USER'] ?? '', 'PASSWORD' => $conf['PASSWORD'] ?? '', 'SCHEMA' => '',
	'ENCRYPTION' => false, 'TRANSACTIONS' => 0, 'TRANSACTION_NO_FAILED_SQLS' => true, 'SELECT_COUNT' => 0, 'EXECUTE_COUNT' => 0];
$backend = new MysqlDbBackend();
$DB['DB'] = $backend->connect($DB['SERVER'], $DB['PORT'], $DB['USER'], $DB['PASSWORD'], $DB['DATABASE'], $DB['SCHEMA']);
if ($backend->getError()) {
	fwrite(STDERR, 'cannot connect: '.$backend->getError()."\n");
	exit(1);
}
$backend->init();
