<?php
/**
 * WMS - Local configuration.
 *
 * Written by install.php. Keep this file out of version control and out of
 * public reach: it holds the database credentials and the encryption key
 * that protects stored router passwords.
 */

return [
    'db_host'     => 'localhost',
    'db_name'     => 'wms_db',
    'db_user'     => 'phpmyadmin',
    'db_pass'     => 'b',
    'db_port'     => 3306,
    'app_name'    => 'WMS',
    'app_url'     => '',
    'app_key'     => 'c7f1a93e4b52d80f6a1c9e3b7d40582fae6931c4d7b0825e93af16cd4e7b280a',
    'timezone'    => 'Africa/Dar_es_Salaam',
    'environment' => 'production',
];
