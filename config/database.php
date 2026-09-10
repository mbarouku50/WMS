<?php
/**
 * WMS - Database credentials.
 *
 * Real credentials live in config/app.local.php, which install.php writes and
 * which should never be committed or served.  The values below are only the
 * fallback defaults used before installation.
 */

if (!defined('WMS_ROOT')) {
    require_once __DIR__ . '/constants.php';
}

$wmsLocal = [];
if (is_file(LOCAL_CONFIG_FILE)) {
    /** @var array $wmsLocal */
    $wmsLocal = require LOCAL_CONFIG_FILE;
    if (!is_array($wmsLocal)) {
        $wmsLocal = [];
    }
}

return [
    'host'    => $wmsLocal['db_host']    ?? 'localhost',
    'name'    => $wmsLocal['db_name']    ?? 'wms_db',
    'user'    => $wmsLocal['db_user']    ?? 'phpmyadmin',
    'pass'    => $wmsLocal['db_pass']    ?? 'b',
    'port'    => (int)($wmsLocal['db_port'] ?? 3306),
    'charset' => 'utf8mb4',
    'app'     => $wmsLocal,
];
