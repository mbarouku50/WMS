<?php
/**
 * WMS - Create a Wi-Fi provider, with its first administrator.
 *
 * Everything happens in one transaction: either the tenant, its admin and
 * its starter data all exist, or none of them do.
 */

$requirePlatform    = true;
$requiredPermission = 'manage_providers';
require_once __DIR__ . '/../../includes/auth-check.php';
require_once INCLUDES_PATH . '/components.php';

$providers = new Provider();
$users     = new User();
$db        = Database::getInstance();

$isEdit = false;
$errors = [];
$values = [
    'business_name' => '', 'provider_code' => '', 'business_type' => 'hotspot', 'status' => 'active',
    'description' => '', 'owner_name' => '', 'phone' => '', 'email' => '', 'address' => '',
    'city' => '', 'region' => '', 'country' => 'Tanzania',
    'timezone' => 'Africa/Dar_es_Salaam', 'currency' => 'TSh', 'currency_code' => 'TZS',
    'admin_name' => '', 'admin_username' => '', 'admin_email' => '', 'admin_phone' => '',
    'mobile_money_enabled'      => 0,
    'platform_fee_amount'       => (string)setting('platform_fee_default', 10000),
    'platform_fee_cycle_months' => (string)setting('platform_fee_cycle_months', 1),
    'grace_months'              => (string)setting('platform_fee_grace_months', 1),
];

if (is_post()) {
    CSRF::verify();
    foreach ($values as $key => $default) {
        $values[$key] = post($key, (string)$default);
    }
    $values['mobile_money_enabled'] = Validator::bool(post('mobile_money_enabled'));
    $password = $_POST['admin_password'] ?? '';
    $confirm  = $_POST['admin_password_confirm'] ?? '';

    $errors = (new Validator(array_merge($values, [
        'admin_password' => $password,
        'admin_password_confirm' => $confirm,
    ])))->labels([
        'business_name' => 'Business name', 'admin_name' => 'Administrator name',
        'admin_username' => 'Username', 'admin_email' => 'Email',
        'admin_password' => 'Password', 'admin_password_confirm' => 'Password confirmation',
    ])->rules([
        'business_name'          => 'required|min:2|max:160',
        'provider_code'          => 'nullable|slug|max:30',
        'business_type'          => 'required|in:' . implode(',', array_keys(Provider::BUSINESS_TYPES)),
        'status'                 => 'required|in:' . implode(',', array_keys(Provider::STATUSES)),
        'phone'                  => 'nullable|phone',
        'email'                  => 'nullable|email|max:160',
        'admin_name'             => 'required|min:3|max:120',
        'admin_username'         => 'required|username',
        'admin_email'            => 'required|email|max:160',
        'admin_password'         => 'required|password',
        'admin_password_confirm' => 'required|matches:admin_password',
        'platform_fee_amount'    => 'required|numeric|min_value:0',
    ])->errors();

    $code = strtoupper(trim($values['provider_code'])) ?: $providers->nextCode();
    if (!isset($errors['provider_code']) && $providers->isCodeTaken($code)) {
        $errors['provider_code'] = 'That provider code is already in use.';
    }
    if (!isset($errors['admin_username']) && $users->isTaken('username', $values['admin_username'])) {
        $errors['admin_username'] = 'That username is already taken on this platform.';
    }
    if (!isset($errors['admin_email']) && $users->isTaken('email', $values['admin_email'])) {
        $errors['admin_email'] = 'That email address is already in use.';
    }

    if (!$errors) {
        $db->beginTransaction();
        try {
            $providerId = $providers->create([
                'provider_code' => $code,
                'business_name' => $values['business_name'],
                'business_type' => $values['business_type'],
                'owner_name'    => $values['owner_name'] ?: null,
                'phone'         => $values['phone'] ?: null,
                'email'         => $values['email'] ?: null,
                'address'       => $values['address'] ?: null,
                'city'          => $values['city'] ?: null,
                'region'        => $values['region'] ?: null,
                'country'       => $values['country'] ?: 'Tanzania',
                'description'   => $values['description'] ?: null,
                'status'        => $values['status'],
                'timezone'      => $values['timezone'],
                'currency'      => $values['currency'] ?: 'TSh',
                'currency_code' => $values['currency_code'] ?: 'TZS',
                // Vouchers always work; mobile money is granted, not assumed.
                'mobile_money_enabled'    => $values['mobile_money_enabled'],
                'mobile_money_changed_at' => $values['mobile_money_enabled'] ? date('Y-m-d H:i:s') : null,
                'created_by'    => Auth::id(),
            ]);

            /* Optional logo. */
            if (!empty($_FILES['logo']['tmp_name'])) {
                $stored = wms_store_provider_logo($_FILES['logo'], $providerId);
                if ($stored !== null) {
                    $providers->updateById($providerId, ['logo' => $stored]);
                }
            }

            /* The provider's first administrator. */
            $roleId = (int)$db->fetchColumn("SELECT id FROM roles WHERE slug = 'provider_admin' LIMIT 1", [], 0);
            if ($roleId === 0) {
                throw new RuntimeException('The Provider Administrator role is missing. Run database/upgrade_multi_provider.sql.');
            }

            $userId = $db->insert('users', [
                'provider_id'   => $providerId,
                'role_id'       => $roleId,
                'full_name'     => $values['admin_name'],
                'username'      => $values['admin_username'],
                'email'         => $values['admin_email'],
                'phone'         => $values['admin_phone'] ?: null,
                'password_hash' => password_hash((string)$password, PASSWORD_DEFAULT),
                'status'        => 'active',
            ]);

            /* Provider-level branding defaults so their portal is not blank. */
            Setting::setForProvider($providerId, 'app_name', $values['business_name']);
            Setting::setForProvider($providerId, 'company_name', $values['business_name']);
            Setting::setForProvider($providerId, 'currency', $values['currency'] ?: 'TSh');
            Setting::setForProvider($providerId, 'currency_code', $values['currency_code'] ?: 'TZS');
            Setting::setForProvider($providerId, 'timezone', $values['timezone']);
            if ($values['phone'] !== '') {
                Setting::setForProvider($providerId, 'support_phone', $values['phone']);
            }
            if ($values['email'] !== '') {
                Setting::setForProvider($providerId, 'support_email', $values['email']);
            }

            /*
             * Billing terms, as agreed. The grace period is expressed in
             * months from today, so "after 2 months" makes the first invoice
             * fall due two months from registration.
             */
            (new BillingService())->setTerms($providerId, [
                'fee'          => $values['platform_fee_amount'],
                'cycle_months' => $values['platform_fee_cycle_months'],
                'grace_months' => $values['grace_months'],
                'from'         => date('Y-m-d'),
            ]);

            /* Something to sell on day one. */
            if (post('seed_defaults') === '1') {
                wms_seed_provider_defaults($db, $providerId, $values['business_name']);
            }

            $db->commit();
        } catch (Throwable $e) {
            $db->rollback();
            Logger::error('Provider creation failed: ' . $e->getMessage());
            $errors['business_name'] = 'The provider could not be created: ' . $e->getMessage();
        }

        if (!$errors) {
            $terms = (new BillingService())->terms($providerId);
            AuditLog::record('provider_create', 'provider', $providerId,
                'Created provider "' . $values['business_name'] . '" with administrator ' . $values['admin_username']
                . '. Fee ' . money((float)$values['platform_fee_amount'])
                . ', first due ' . format_date($terms['starts_on'] ?? null, 'd M Y')
                . ', mobile money ' . ($values['mobile_money_enabled'] ? 'allowed' : 'not allowed'));

            Response::redirect('admin/providers/view.php?id=' . $providerId, 'success',
                $values['business_name'] . ' has been created. ' . $values['admin_username'] . ' can sign in now. '
                . 'Platform fee ' . money((float)$values['platform_fee_amount'])
                . ' starting ' . format_date($terms['starts_on'] ?? null, 'd M Y') . '.');
        }
    }
}

/**
 * Saves a provider logo into uploads/logos and returns its relative path.
 */
function wms_store_provider_logo(array $file, int $providerId): ?string
{
    $info = @getimagesize($file['tmp_name']);
    $allowed = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/gif' => 'gif', 'image/webp' => 'webp'];
    if (!$info || !isset($allowed[$info['mime']]) || $file['size'] > 2 * 1024 * 1024) {
        return null;
    }
    $name = 'provider-' . $providerId . '-' . date('YmdHis') . '.' . $allowed[$info['mime']];
    return @move_uploaded_file($file['tmp_name'], UPLOADS_PATH . '/logos/' . $name)
        ? 'uploads/logos/' . $name
        : null;
}

/**
 * Gives a brand new provider one bandwidth profile and three packages, so
 * their dashboard is not an empty room on the first morning.
 */
function wms_seed_provider_defaults(Database $db, int $providerId, string $businessName): void
{
    $profileId = $db->insert('bandwidth_profiles', [
        'provider_id'   => $providerId,
        'name'          => 'Standard 3M/1M',
        'download_kbps' => 3072,
        'upload_kbps'   => 1024,
        'burst_enabled' => 0,
        'priority'      => 6,
        'description'   => 'Starter profile created with the provider account.',
        'status'        => 'active',
    ]);

    $prefix = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $businessName) ?: 'WMS', 0, 2));
    $packages = [
        ['Quick Hour',      $prefix . '-QHR',  500,  1,  'hours', null, 1, 1],
        ['Daily 5GB',       $prefix . '-D5GB', 1000, 24, 'hours', 5120, 1, 2],
        ['Daily Unlimited', $prefix . '-DUNL', 2000, 24, 'hours', null, 2, 3],
    ];
    foreach ($packages as [$name, $code, $price, $durValue, $durUnit, $dataMb, $devices, $sort]) {
        $db->insert('packages', [
            'provider_id'          => $providerId,
            'name'                 => $name,
            'code'                 => $code,
            'price'                => $price,
            'duration_value'       => $durValue,
            'duration_unit'        => $durUnit,
            'data_limit_mb'        => $dataMb,
            'download_kbps'        => 3072,
            'upload_kbps'          => 1024,
            'device_limit'         => $devices,
            'bandwidth_profile_id' => $profileId,
            'description'          => 'Starter package - edit or remove it once you have set your own prices.',
            'is_featured'          => $sort === 2 ? 1 : 0,
            'sort_order'           => $sort,
            'status'               => 'active',
        ]);
    }
}

$pageTitle   = 'Add provider';
$activeNav   = 'providers';
$breadcrumbs = [['label' => 'Providers', 'url' => 'admin/providers/index.php'], ['label' => 'Add']];
require INCLUDES_PATH . '/admin-header.php';
?>

<?= page_head('Add a Wi-Fi provider', 'A new, fully isolated business on this platform') ?>

<?php if ($errors): ?>
    <?= alert_box('danger', $errors['business_name'] ?? 'Please correct the highlighted fields.') ?>
<?php endif; ?>

<?= alert_box('info', 'Everything this provider creates - customers, packages, vouchers, payments, routers, sessions - stays inside their own boundary. No other provider can see or reach it.') ?>

<form method="post" action="" enctype="multipart/form-data" novalidate style="max-width:980px">
    <?= CSRF::field() ?>
    <?php require __DIR__ . '/_form.php'; ?>
</form>

<?php require INCLUDES_PATH . '/footer.php'; ?>
