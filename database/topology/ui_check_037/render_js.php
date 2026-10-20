<?php
// renders ui/app/views/js/monitoring.topology.view.js.php (a Zabbix view fragment) to plain JavaScript without the frontend: translations are the identity
const CSRF_TOKEN_NAME = '_csrf_token';
class CCsrfTokenHelper { public static function get($a) { return 'x'; } }
$data = ['legend_collapsed' => (int) ($argv[2] ?? 0)];
ob_start();
include $argv[1];
$out = ob_get_clean();
echo preg_replace('~^\s*<script>|</script>\s*$~', '', $out);
