<?php
/**
 * WMS - Installer.
 *
 * Four steps: requirements, database, administrator, finish.  It creates the
 * schema, optionally loads demo data, writes config/app.local.php and then
 * locks itself so it cannot be re-run from the browser.
 */

require_once __DIR__ . '/config/config.php';
require_once INCLUDES_PATH . '/components.php';

/* Once installed, the installer refuses to run again - except for the
   confirmation page that the just-finished install redirects to. */
$justInstalled    = is_array(Session::get('install_done'));
$alreadyInstalled = WMS_INSTALLED && !$justInstalled;

$step = max(1, min(4, (int)($_GET['step'] ?? 1)));
$errors = [];
$notice = '';

/* ------------------------------------------------------------ helpers */

/** Splits a .sql file into individual statements (quote and comment aware). */
function wms_split_sql(string $sql): array
{
    $statements = [];
    $current    = '';
    $inString   = false;
    $stringChar = '';
    $length     = strlen($sql);

    for ($i = 0; $i < $length; $i++) {
        $char = $sql[$i];
        $next = $sql[$i + 1] ?? '';

        if (!$inString) {
            // Line comments
            if (($char === '-' && $next === '-') || $char === '#') {
                while ($i < $length && $sql[$i] !== "\n") {
                    $i++;
                }
                continue;
            }
            // Block comments
            if ($char === '/' && $next === '*') {
                $i += 2;
                while ($i < $length && !($sql[$i] === '*' && ($sql[$i + 1] ?? '') === '/')) {
                    $i++;
                }
                $i++;
                continue;
            }
            if ($char === "'" || $char === '"') {
                $inString   = true;
                $stringChar = $char;
            } elseif ($char === ';') {
                $trimmed = trim($current);
                if ($trimmed !== '') {
                    $statements[] = $trimmed;
                }
                $current = '';
                continue;
            }
        } else {
            if ($char === '\\') {
                $current .= $char . $next;
                $i++;
                continue;
            }
            if ($char === $stringChar) {
                // Doubled quote inside a string stays part of it.
                if ($next === $stringChar) {
                    $current .= $char . $next;
                    $i++;
                    continue;
                }
                $inString = false;
            }
        }
        $current .= $char;
    }

    $trimmed = trim($current);
    if ($trimmed !== '') {
        $statements[] = $trimmed;
    }
    return $statements;
}


/**
 * Runs a .sql file that may contain DELIMITER blocks (stored procedures).
 *
 * The plain splitter cannot handle procedure bodies, because they contain
 * semicolons of their own; this walks the file honouring DELIMITER changes.
 */
function wms_run_sql_file(mysqli $connection, string $sql): void
{
    $delimiter = ';';
    $buffer    = '';

    foreach (preg_split('/\r\n|\r|\n/', $sql) as $line) {
        $trimmed = trim($line);

        if ($trimmed === '' || str_starts_with($trimmed, '--')) {
            continue;
        }

        if (preg_match('/^DELIMITER\s+(\S+)/i', $trimmed, $m)) {
            $delimiter = $m[1];
            continue;
        }

        $buffer .= $line . "\n";

        if (str_ends_with(rtrim($buffer), $delimiter)) {
            $statement = trim(substr(rtrim($buffer), 0, -strlen($delimiter)));
            if ($statement !== '') {
                $connection->query($statement);
                // Drain any result set a statement produced.
                while ($connection->more_results() && $connection->next_result()) {
                    $extra = $connection->store_result();
                    if ($extra instanceof mysqli_result) {
                        $extra->free();
                    }
                }
            }
            $buffer = '';
        }
    }

    $tail = trim($buffer);
    if ($tail !== '') {
        $connection->query($tail);
    }
}

/** Requirement checks shown on step 1. */
function wms_requirements(): array
{
    return [
        ['label' => 'PHP 8.0 or newer',        'ok' => PHP_VERSION_ID >= 80000, 'detail' => PHP_VERSION],
        ['label' => 'MySQLi extension',        'ok' => extension_loaded('mysqli'), 'detail' => extension_loaded('mysqli') ? 'Loaded' : 'Missing'],
        ['label' => 'cURL extension',          'ok' => extension_loaded('curl'), 'detail' => extension_loaded('curl') ? 'Loaded' : 'Needed for mobile money'],
        ['label' => 'OpenSSL extension',       'ok' => extension_loaded('openssl'), 'detail' => extension_loaded('openssl') ? 'Loaded' : 'Needed for stored credentials'],
        ['label' => 'mbstring extension',      'ok' => extension_loaded('mbstring'), 'detail' => extension_loaded('mbstring') ? 'Loaded' : 'Missing'],
        ['label' => 'config/ is writable',     'ok' => is_writable(CONFIG_PATH), 'detail' => CONFIG_PATH],
        ['label' => 'logs/ is writable',       'ok' => is_writable(LOGS_PATH), 'detail' => LOGS_PATH],
        ['label' => 'uploads/ is writable',    'ok' => is_writable(UPLOADS_PATH), 'detail' => UPLOADS_PATH],
        ['label' => 'database/schema.sql',     'ok' => is_readable(DATABASE_PATH . '/schema.sql'), 'detail' => 'Schema file'],
    ];
}

/* ------------------------------------------------------- step handling */

if (is_post() && !$alreadyInstalled) {
    CSRF::verify();
    $action = post('action');

    /* ---- Step 2: test and remember the database connection ---- */
    if ($action === 'database') {
        $db = [
            'host' => post('db_host', 'localhost'),
            'name' => post('db_name'),
            'user' => post('db_user'),
            'pass' => $_POST['db_pass'] ?? '',
            'port' => (int)(post('db_port', '3306') ?: 3306),
        ];

        $validator = (new Validator($db))->rules([
            'host' => 'required|max:120',
            'name' => 'required|max:64',
            'user' => 'required|max:64',
        ]);

        if ($validator->fails()) {
            $errors = $validator->errors();
        } else {
            mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
            try {
                $connection = new mysqli($db['host'], $db['user'], (string)$db['pass'], '', $db['port']);
                $connection->set_charset('utf8mb4');

                $safeName = str_replace('`', '', $db['name']);
                $connection->query("CREATE DATABASE IF NOT EXISTS `$safeName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
                $connection->select_db($safeName);
                $connection->close();

                Session::set('install_db', $db);
                header('Location: ' . url('install.php?step=3'));
                exit;
            } catch (Throwable $e) {
                $errors['db_host'] = 'Could not connect: ' . $e->getMessage();
                $step = 2;
            }
        }
        if ($errors) {
            $step = 2;
        }
    }

    /* ---- Step 3: create everything ---- */
    if ($action === 'install') {
        $db = Session::get('install_db');
        if (!is_array($db)) {
            $errors['general'] = 'The database details were lost. Please start again from step 2.';
            $step = 2;
        } else {
            $admin = [
                'full_name' => post('admin_name'),
                'username'  => post('admin_username'),
                'email'     => post('admin_email'),
                'password'  => $_POST['admin_password'] ?? '',
                'confirm'   => $_POST['admin_password_confirm'] ?? '',
            ];

            $validator = (new Validator(array_merge($admin, [
                'app_name' => post('app_name'),
                'app_url'  => post('app_url'),
            ])))->labels([
                'full_name' => 'Full name', 'username' => 'Username', 'email' => 'Email',
                'password'  => 'Password', 'confirm' => 'Password confirmation',
            ])->rules([
                'full_name' => 'required|min:3|max:120',
                'username'  => 'required|username',
                'email'     => 'required|email|max:160',
                'password'  => 'required|password',
                'confirm'   => 'required|matches:password',
                'app_name'  => 'required|max:60',
            ]);

            if ($validator->fails()) {
                $errors = $validator->errors();
                $step = 3;
            } else {
                try {
                    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
                    $connection = new mysqli($db['host'], $db['user'], (string)$db['pass'], $db['name'], $db['port']);
                    $connection->set_charset('utf8mb4');

                    /*
                     * Import order matters:
                     *   1. schema.sql   - the base tables
                     *   2. the upgrade files, in the order they were written,
                     *      each building on the one before
                     *   3. seed.sql     - optional demo data, which already
                     *      carries provider_id
                     *
                     * A fresh install runs exactly the same upgrade files as
                     * an existing database being migrated. That is the whole
                     * point: one source of truth per change, so a new
                     * installation can never end up missing a table that an
                     * upgraded one has. Every file is safe to run twice.
                     */
                    $schema = file_get_contents(DATABASE_PATH . '/schema.sql');
                    foreach (wms_split_sql((string)$schema) as $statement) {
                        $connection->query($statement);
                    }

                    foreach ([
                        'upgrade_multi_provider.sql',        // providers, provider_id, scoped roles
                        'upgrade_provider_billing.sql',      // wallets, withdrawals, platform invoices
                        'upgrade_network_production.sql',    // live router monitoring
                        'upgrade_platform_fee_enforcement.sql', // locking an unpaid provider out
                        'upgrade_platform_payouts.sql',      // the platform's own withdrawals
                    ] as $upgradeFile) {
                        $upgrade = DATABASE_PATH . '/' . $upgradeFile;
                        if (is_readable($upgrade)) {
                            // These files define stored procedures, so they need
                            // the DELIMITER-aware runner, not the plain splitter.
                            wms_run_sql_file($connection, (string)file_get_contents($upgrade));
                        }
                    }

                    /* demo data (optional) */
                    if (post('load_demo') === '1' && is_readable(DATABASE_PATH . '/seed.sql')) {
                        $seed = file_get_contents(DATABASE_PATH . '/seed.sql');
                        foreach (wms_split_sql((string)$seed) as $statement) {
                            $connection->query($statement);
                        }
                    }

                    /* baseline settings when demo data was skipped */
                    $connection->query("INSERT IGNORE INTO roles (id, name, slug, description, is_system) VALUES
                        (1,'Super Admin','super_admin','Full, unrestricted access to every module.',1),
                        (2,'Network Administrator','network_admin','Routers, access points, sessions and devices.',1),
                        (3,'Sales Administrator','sales_admin','Packages, vouchers, customers and payments.',1),
                        (4,'Support','support','Read-mostly access for helping customers get online.',1)");

                    $permissionSlugs = [
                        ['view_dashboard', 'View dashboard', 'Dashboard'],
                        ['manage_customers', 'Manage customers', 'Customers'],
                        ['manage_packages', 'Manage packages', 'Sales'],
                        ['manage_vouchers', 'Manage vouchers', 'Sales'],
                        ['manage_payments', 'Manage payments', 'Sales'],
                        ['manage_routers', 'Manage routers & access points', 'Network'],
                        ['manage_devices', 'Manage devices', 'Network'],
                        ['manage_sessions', 'Manage sessions', 'Network'],
                        ['manage_reports', 'View & export reports', 'Reports'],
                        ['manage_alerts', 'Manage alerts', 'Reports'],
                        ['view_audit_logs', 'View audit logs', 'Administration'],
                        ['manage_staff', 'Manage staff & roles', 'Administration'],
                        ['manage_settings', 'Manage settings', 'Administration'],
                    ];
                    $statement = $connection->prepare('INSERT IGNORE INTO permissions (slug, name, group_name) VALUES (?,?,?)');
                    foreach ($permissionSlugs as $permission) {
                        $statement->bind_param('sss', $permission[0], $permission[1], $permission[2]);
                        $statement->execute();
                    }
                    $statement->close();
                    $connection->query('INSERT IGNORE INTO role_permissions (role_id, permission_id) SELECT 1, id FROM permissions');

                    /* administrator */
                    $hash = password_hash($admin['password'], PASSWORD_DEFAULT);
                    $statement = $connection->prepare(
                        'INSERT INTO users (role_id, full_name, username, email, password_hash, status)
                         VALUES (1, ?, ?, ?, ?, "active")
                         ON DUPLICATE KEY UPDATE full_name = VALUES(full_name), password_hash = VALUES(password_hash), role_id = 1'
                    );
                    $statement->bind_param('ssss', $admin['full_name'], $admin['username'], $admin['email'], $hash);
                    $statement->execute();
                    $statement->close();

                    /* core settings */
                    $appName  = post('app_name', 'WMS');
                    $currency = post('currency', 'TSh');
                    $timezone = post('timezone', 'Africa/Dar_es_Salaam');
                    $demoMode = post('demo_mode') === '1' ? '1' : '0';
                    $settings = [
                        ['app_name', $appName, 'general', 'string'],
                        ['company_name', post('company_name', $appName), 'general', 'string'],
                        ['currency', $currency, 'general', 'string'],
                        ['currency_code', post('currency_code', 'TZS'), 'general', 'string'],
                        ['timezone', $timezone, 'general', 'string'],
                        ['demo_mode', $demoMode, 'general', 'bool'],
                        ['support_email', $admin['email'], 'general', 'string'],
                        ['payment_provider', post('payment_provider', 'demo'), 'payment', 'string'],
                        ['sonicpesa_base_url', 'https://api.sonicpesa.com/api/v1', 'payment', 'string'],
                        ['sonicpesa_api_key', post('sonicpesa_api_key'), 'payment', 'secret'],
                        ['sonicpesa_api_secret', post('sonicpesa_api_secret'), 'payment', 'secret'],
                    ];
                    $statement = $connection->prepare(
                        'INSERT INTO settings (setting_key, setting_value, setting_group, value_type) VALUES (?,?,?,?)
                         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
                    );
                    foreach ($settings as $setting) {
                        $statement->bind_param('ssss', $setting[0], $setting[1], $setting[2], $setting[3]);
                        $statement->execute();
                    }
                    $statement->close();
                    $connection->close();

                    /* local configuration file */
                    $config = [
                        'db_host'     => $db['host'],
                        'db_name'     => $db['name'],
                        'db_user'     => $db['user'],
                        'db_pass'     => (string)$db['pass'],
                        'db_port'     => (int)$db['port'],
                        'app_name'    => $appName,
                        'app_url'     => rtrim(post('app_url', rtrim(APP_URL, '/')), '/'),
                        'app_key'     => bin2hex(random_bytes(32)),
                        'timezone'    => $timezone,
                        'environment' => post('environment', 'production'),
                    ];

                    $export = "<?php\n"
                        . "/**\n * WMS - Local configuration, written by install.php.\n"
                        . " * Keep this file out of version control and out of public reach.\n */\n\n"
                        . 'return ' . var_export($config, true) . ";\n";

                    if (@file_put_contents(LOCAL_CONFIG_FILE, $export) === false) {
                        throw new RuntimeException('Could not write config/app.local.php. Check the folder permissions.');
                    }
                    // 0644, not 0640: the web server is often a different
                    // user from the one that uploaded the files, and it must
                    // be able to read this. The directory is closed to the
                    // web by config/.htaccess, so this is not exposure.
                    @chmod(LOCAL_CONFIG_FILE, 0644);
                    @file_put_contents(INSTALL_LOCK_FILE, 'Installed ' . date('c') . "\n");

                    Session::forget('install_db');
                    Session::set('install_done', ['username' => $admin['username']]);
                    header('Location: ' . url('install.php?step=4'));
                    exit;
                } catch (Throwable $e) {
                    Logger::error('Installation failed: ' . $e->getMessage());
                    $errors['general'] = 'Installation failed: ' . $e->getMessage();
                    $step = 3;
                }
            }
        }
    }
}

$pageTitle = 'Install · WMS';
$bodyClass = 'wms-public';
require INCLUDES_PATH . '/header.php';
?>
<div class="site-wrap" style="max-width:760px;padding-top:2.5rem;padding-bottom:3rem">

    <div class="flex items-center gap-1 mb-3">
        <span class="brand-mark brand-mark--lg"><?= icon('wifi', 'ico--lg') ?></span>
        <div>
            <h1 class="mb-0">Install WMS</h1>
            <p class="muted small mb-0">Wi-Fi Management System · version <?= e(WMS_VERSION) ?></p>
        </div>
    </div>

    <?php if ($alreadyInstalled): ?>
        <div class="card">
            <div class="card__body">
                <?= alert_box('warning', 'WMS is already installed. For safety the installer will not run again. Delete config/installed.lock only if you intend to reinstall - doing so on a live system is dangerous.', 'Installation locked') ?>
                <a class="btn btn--primary" href="<?= e(url('login.php')) ?>">Go to sign in</a>
            </div>
        </div>
    <?php else: ?>

    <!-- progress -->
    <div class="tabs mb-3">
        <?php foreach (['Requirements', 'Database', 'Administrator', 'Finish'] as $i => $label): ?>
            <span class="tab<?= $step === $i + 1 ? ' is-active' : '' ?>"><?= ($i + 1) . '. ' . e($label) ?></span>
        <?php endforeach; ?>
    </div>

    <?= flash_messages() ?>
    <?php if (!empty($errors['general'])): ?>
        <?= alert_box('danger', $errors['general'], 'Something went wrong') ?>
    <?php endif; ?>

    <?php if ($step === 1): ?>
        <?php
        $requirements = wms_requirements();
        $allOk = !in_array(false, array_column($requirements, 'ok'), true);
        ?>
        <div class="card">
            <div class="card__head"><h2 class="card__title"><?= icon('check') ?> Server requirements</h2></div>
            <div class="table-wrap">
                <table class="table">
                    <tbody>
                    <?php foreach ($requirements as $requirement): ?>
                        <tr>
                            <td style="width:34px"><?= $requirement['ok']
                                ? '<span style="color:var(--wms-success)">' . icon('check') . '</span>'
                                : '<span style="color:var(--wms-danger)">' . icon('x') . '</span>' ?></td>
                            <td><b><?= e($requirement['label']) ?></b></td>
                            <td class="muted small"><?= e($requirement['detail']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="card__foot flex justify-between items-center">
                <span class="<?= $allOk ? 'muted' : 'strong' ?>">
                    <?= $allOk ? 'Everything checks out.' : 'Fix the items marked in red before continuing.' ?>
                </span>
                <a class="btn btn--primary<?= $allOk ? '' : ' is-loading' ?>" href="<?= e(url('install.php?step=2')) ?>">
                    Continue <?= icon('chevron', 'ico--sm') ?>
                </a>
            </div>
        </div>

    <?php elseif ($step === 2): ?>
        <form method="post" action="<?= e(url('install.php')) ?>" class="card">
            <?= CSRF::field() ?>
            <input type="hidden" name="action" value="database">
            <div class="card__head"><h2 class="card__title"><?= icon('database') ?> Database connection</h2></div>
            <div class="card__body">
                <p class="muted small">
                    WMS creates the database if it does not exist yet, then imports the schema.
                    These details are stored in <code>config/app.local.php</code>, never in a page.
                </p>
                <div class="form-grid">
                    <?= field_input(['name' => 'db_host', 'label' => 'Database host', 'value' => post('db_host', 'localhost'), 'required' => true, 'error' => $errors['db_host'] ?? '']) ?>
                    <?= field_input(['name' => 'db_port', 'label' => 'Port', 'value' => post('db_port', '3306')]) ?>
                    <?= field_input(['name' => 'db_name', 'label' => 'Database name', 'value' => post('db_name', 'wms_db'), 'required' => true, 'error' => $errors['db_name'] ?? '']) ?>
                    <?= field_input(['name' => 'db_user', 'label' => 'Database user', 'value' => post('db_user', 'root'), 'required' => true, 'error' => $errors['db_user'] ?? '']) ?>
                    <div class="field--full">
                        <?= field_input(['name' => 'db_pass', 'type' => 'password', 'label' => 'Database password', 'hint' => 'Leave blank if the user has no password.']) ?>
                    </div>
                </div>
            </div>
            <div class="card__foot flex justify-between">
                <a class="btn" href="<?= e(url('install.php?step=1')) ?>">Back</a>
                <button type="submit" class="btn btn--primary">Test connection &amp; continue</button>
            </div>
        </form>

    <?php elseif ($step === 3): ?>
        <form method="post" action="<?= e(url('install.php')) ?>" class="card">
            <?= CSRF::field() ?>
            <input type="hidden" name="action" value="install">

            <div class="card__head"><h2 class="card__title"><?= icon('user') ?> Administrator &amp; system</h2></div>
            <div class="card__body">
                <fieldset class="fieldset">
                    <legend>Your account</legend>
                    <div class="form-grid">
                        <?= field_input(['name' => 'admin_name', 'label' => 'Full name', 'value' => post('admin_name'), 'required' => true, 'error' => $errors['full_name'] ?? '']) ?>
                        <?= field_input(['name' => 'admin_username', 'label' => 'Username', 'value' => post('admin_username', 'admin'), 'required' => true, 'error' => $errors['username'] ?? '']) ?>
                        <?= field_input(['name' => 'admin_email', 'type' => 'email', 'label' => 'Email', 'value' => post('admin_email'), 'required' => true, 'error' => $errors['email'] ?? '']) ?>
                        <div></div>
                        <?= field_input(['name' => 'admin_password', 'type' => 'password', 'label' => 'Password', 'required' => true, 'hint' => 'At least 8 characters with a letter and a number.', 'error' => $errors['password'] ?? '']) ?>
                        <?= field_input(['name' => 'admin_password_confirm', 'type' => 'password', 'label' => 'Confirm password', 'required' => true, 'error' => $errors['confirm'] ?? '']) ?>
                    </div>
                </fieldset>

                <fieldset class="fieldset">
                    <legend>System</legend>
                    <div class="form-grid">
                        <?= field_input(['name' => 'app_name', 'label' => 'System name', 'value' => post('app_name', 'WMS'), 'required' => true, 'error' => $errors['app_name'] ?? '']) ?>
                        <?= field_input(['name' => 'company_name', 'label' => 'Company name', 'value' => post('company_name', 'WMS Networks')]) ?>
                        <?= field_input(['name' => 'app_url', 'label' => 'Application URL', 'value' => post('app_url', rtrim(APP_URL, '/')), 'hint' => 'Detected automatically - change only if you use a different domain.']) ?>
                        <?= field_select(['name' => 'timezone', 'label' => 'Timezone', 'value' => post('timezone', 'Africa/Dar_es_Salaam'),
                            'options' => array_combine(
                                ['Africa/Dar_es_Salaam', 'Africa/Nairobi', 'Africa/Kampala', 'Africa/Kigali', 'Africa/Lusaka', 'UTC'],
                                ['Africa/Dar_es_Salaam', 'Africa/Nairobi', 'Africa/Kampala', 'Africa/Kigali', 'Africa/Lusaka', 'UTC']
                            )]) ?>
                        <?= field_input(['name' => 'currency', 'label' => 'Currency symbol', 'value' => post('currency', 'TSh')]) ?>
                        <?= field_input(['name' => 'currency_code', 'label' => 'Currency code', 'value' => post('currency_code', 'TZS')]) ?>
                    </div>
                </fieldset>

                <fieldset class="fieldset">
                    <legend>Payments (optional - you can set this later)</legend>
                    <div class="form-grid">
                        <?= field_select(['name' => 'payment_provider', 'label' => 'Provider', 'value' => post('payment_provider', 'demo'),
                            'options' => ['demo' => 'Demo provider (no real money)', 'sonicpesa' => 'SonicPesa (mobile money)']]) ?>
                        <div></div>
                        <?= field_input(['name' => 'sonicpesa_api_key', 'label' => 'SonicPesa access key', 'value' => '', 'attrs' => 'autocomplete="off"', 'hint' => 'Stored server side and never shown again in full.']) ?>
                        <?= field_input(['name' => 'sonicpesa_api_secret', 'type' => 'password', 'label' => 'SonicPesa secret key', 'attrs' => 'autocomplete="off"', 'hint' => 'Needed for webhook verification and payouts.']) ?>
                    </div>
                </fieldset>

                <?= field_checkbox(['name' => 'demo_mode', 'label' => 'Start in demo mode', 'checked' => true,
                    'hint' => 'Recommended. Everything works without hardware, and simulated network data is clearly labelled. Turn it off in Settings once a router is configured.']) ?>

                <?= field_checkbox(['name' => 'load_demo', 'label' => 'Load demo data', 'checked' => true,
                    'hint' => 'Sample packages, customers, vouchers, payments and sessions so you can explore the system straight away.']) ?>
            </div>
            <div class="card__foot flex justify-between">
                <a class="btn" href="<?= e(url('install.php?step=2')) ?>">Back</a>
                <button type="submit" class="btn btn--primary">Install WMS</button>
            </div>
        </form>

    <?php else: ?>
        <?php
        $done = Session::get('install_done', []);
        Session::forget('install_done'); // shown once; afterwards the lock applies
        ?>
        <div class="card">
            <div class="card__body text-center" style="padding:2.5rem 1.5rem">
                <div class="empty__icon" style="background:var(--wms-success-soft);color:var(--wms-success);margin:0 auto 1rem;width:56px;height:56px">
                    <?= icon('check', 'ico--lg') ?>
                </div>
                <h2>WMS is installed</h2>
                <p class="muted">Sign in as <b><?= e($done['username'] ?? 'your administrator account') ?></b> to open the control centre.</p>
                <div class="flex gap-1 justify-between mt-3" style="justify-content:center">
                    <a class="btn btn--primary btn--lg" href="<?= e(url('login.php')) ?>">Sign in</a>
                    <a class="btn btn--lg" href="<?= e(url('index.php')) ?>">View the site</a>
                </div>
            </div>
            <div class="card__foot">
                <b>Before you go live:</b> delete or rename <code>install.php</code>, confirm that
                <code>config/</code>, <code>core/</code>, <code>classes/</code>, <code>services/</code>,
                <code>database/</code> and <code>logs/</code> are not reachable from the web, and switch
                demo mode off once a router is configured.
            </div>
        </div>
    <?php endif; ?>

    <?php endif; ?>
</div>
<script src="<?= e(asset('js/app.js')) ?>"></script>
</body>
</html>
