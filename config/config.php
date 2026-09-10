<?php
/**
 * WMS - Application bootstrap.
 *
 * Every entry point (index.php, login.php, admin/*, customer/*, api/*)
 * includes this single file.  It wires up constants, error handling, the
 * class autoloader, the session and the shared helper functions.
 */

declare(strict_types=1);

if (defined('WMS_BOOTSTRAPPED')) {
    return;
}
define('WMS_BOOTSTRAPPED', true);

require_once __DIR__ . '/constants.php';

/* ------------------------------------------------------------------------ */
/* Local configuration (written by install.php)                             */
/* ------------------------------------------------------------------------ */

/*
 * The local configuration is optional (before installation) but must be
 * *readable* when it exists. A file the web server cannot open is the
 * commonest cause of a blank HTTP 500 on a fresh deployment - usually the
 * file is owned by the account that uploaded it and the web server runs as
 * someone else - so it is reported plainly rather than fatally.
 */
$wmsLocal = [];
if (is_file(LOCAL_CONFIG_FILE)) {
    if (!is_readable(LOCAL_CONFIG_FILE)) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        echo "WMS cannot read config/app.local.php.

"
           . "The file exists but the web server user does not have permission to open it.
"
           . "Fix it with:

"
           . "    chmod 644 " . LOCAL_CONFIG_FILE . "

"
           . "or hand the application to your web server user, for example:

"
           . "    sudo chown -R www-data:www-data " . WMS_ROOT . "
";
        exit;
    }
    $loaded = require LOCAL_CONFIG_FILE;
    if (is_array($loaded)) {
        $wmsLocal = $loaded;
    }
}

define('WMS_INSTALLED', is_file(INSTALL_LOCK_FILE) && is_file(LOCAL_CONFIG_FILE));
define('ENVIRONMENT', $wmsLocal['environment'] ?? 'production');
define('APP_NAME', $wmsLocal['app_name'] ?? 'WMS');
define('APP_KEY', $wmsLocal['app_key'] ?? 'wms-insecure-default-key-change-me');
define('DEFAULT_TIMEZONE', $wmsLocal['timezone'] ?? 'Africa/Dar_es_Salaam');

/* ------------------------------------------------------------------------ */
/* Error handling - technical detail goes to logs, never to the browser      */
/* ------------------------------------------------------------------------ */

error_reporting(E_ALL);
ini_set('display_errors', ENVIRONMENT === 'development' ? '1' : '0');
ini_set('log_errors', '1');
if (is_dir(LOGS_PATH) && is_writable(LOGS_PATH)) {
    // Same .log.php + exit-guard trick as core/Logger.php, so PHP's own error
    // log is never readable over HTTP even where .htaccess is ignored.
    $wmsErrorLog = LOGS_PATH . '/php-error.log.php';
    if (!is_file($wmsErrorLog)) {
        @file_put_contents($wmsErrorLog, "<?php exit; /* WMS log - not web readable */ ?>\n");
        @chmod($wmsErrorLog, 0640);
    }
    ini_set('error_log', $wmsErrorLog);
    unset($wmsErrorLog);
}

date_default_timezone_set(DEFAULT_TIMEZONE);

/* ------------------------------------------------------------------------ */
/* Autoloader - core/, classes/, services/ (and its provider sub-folders)    */
/* ------------------------------------------------------------------------ */

spl_autoload_register(static function (string $class): void {
    $class = str_replace('\\', '/', $class);
    $candidates = [
        CORE_PATH     . '/' . $class . '.php',
        CLASSES_PATH  . '/' . $class . '.php',
        SERVICES_PATH . '/' . $class . '.php',
        SERVICES_PATH . '/payments/' . $class . '.php',
        SERVICES_PATH . '/network/'  . $class . '.php',
    ];
    foreach ($candidates as $file) {
        if (is_file($file)) {
            require_once $file;
            return;
        }
    }
});

/* ------------------------------------------------------------------------ */
/* URLs - detected so the app works at /WMS/ or at a domain root             */
/* ------------------------------------------------------------------------ */

/**
 * Works out the public base URL of the application, e.g. "/WMS/" or "/".
 */
function wms_detect_base_path(): string
{
    $docRoot = isset($_SERVER['DOCUMENT_ROOT']) ? realpath($_SERVER['DOCUMENT_ROOT']) : false;
    $appRoot = realpath(WMS_ROOT);

    if ($docRoot && $appRoot) {
        $docRoot = rtrim(str_replace('\\', '/', $docRoot), '/');
        $appRoot = rtrim(str_replace('\\', '/', $appRoot), '/');
        if ($docRoot !== '' && str_starts_with($appRoot, $docRoot)) {
            $path = trim(substr($appRoot, strlen($docRoot)), '/');
            return $path === '' ? '/' : '/' . $path . '/';
        }
    }

    // Fall back to the directory of the running script, stepping back out of
    // any known sub-folder (admin/, customer/, api/...).
    $script = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
    $script = rtrim($script, '/');
    foreach (['/admin/customers', '/admin/packages', '/admin/vouchers', '/admin/payments',
              '/admin/network', '/admin/usage', '/admin/reports', '/admin/alerts',
              '/admin/administration', '/admin/settings', '/admin', '/customer',
              '/api/customers', '/api/vouchers', '/api/packages', '/api/payments',
              '/api/sessions', '/api/devices', '/api/network', '/api/reports', '/api'] as $suffix) {
        if (str_ends_with($script, $suffix)) {
            $script = substr($script, 0, -strlen($suffix));
            break;
        }
    }

    return ($script === '' ? '/' : $script . '/');
}

$wmsScheme = 'http';
if ((!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
    || (int)($_SERVER['SERVER_PORT'] ?? 0) === 443
    || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https') {
    $wmsScheme = 'https';
}
$wmsHost = $_SERVER['HTTP_HOST'] ?? 'localhost';

define('BASE_PATH', wms_detect_base_path());
define('APP_URL', rtrim($wmsLocal['app_url'] ?? ($wmsScheme . '://' . $wmsHost . BASE_PATH), '/') . '/');
define('ASSET_URL', BASE_PATH . 'assets/');

unset($wmsLocal, $wmsScheme, $wmsHost);

/* ------------------------------------------------------------------------ */
/* Shared helpers + session                                                  */
/* ------------------------------------------------------------------------ */

require_once INCLUDES_PATH . '/functions.php';

Session::start();
