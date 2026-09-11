<?php
/**
 * WMS - Edit a provider's business details.
 */

$requirePlatform    = true;
$requiredPermission = 'manage_providers';
require_once __DIR__ . '/../../includes/auth-check.php';
require_once INCLUDES_PATH . '/components.php';

$providers = new Provider();
$id        = (int)query('id');
$record    = $providers->find($id);

if (!$record) {
    Response::redirect('admin/providers/index.php', 'error', 'That provider could not be found.');
}

$isEdit = true;
$errors = [];
$values = [
    'business_name' => $record['business_name'],
    'provider_code' => $record['provider_code'],
    'business_type' => $record['business_type'],
    'status'        => $record['status'],
    'description'   => (string)$record['description'],
    'owner_name'    => (string)$record['owner_name'],
    'phone'         => (string)$record['phone'],
    'email'         => (string)$record['email'],
    'address'       => (string)$record['address'],
    'city'          => (string)$record['city'],
    'region'        => (string)$record['region'],
    'country'       => (string)$record['country'],
    'timezone'      => $record['timezone'],
    'currency'      => $record['currency'],
    'currency_code' => $record['currency_code'],
    'mobile_money_enabled'      => (int)$record['mobile_money_enabled'],
    'platform_fee_amount'       => (string)(float)$record['platform_fee_amount'],
    'platform_fee_cycle_months' => (string)$record['platform_fee_cycle_months'],
    'billing_starts_on'         => (string)$record['billing_starts_on'],
    'billing_status'            => (string)$record['billing_status'],
];

if (is_post()) {
    CSRF::verify();
    $derivedStatus = $values['billing_status'];      // never posted; shown only
    foreach ($values as $key => $default) {
        $values[$key] = post($key, (string)$default);
    }
    $values['mobile_money_enabled'] = Validator::bool(post('mobile_money_enabled'));
    $values['billing_status']       = $derivedStatus;

    $errors = (new Validator($values))->rules([
        'business_name' => 'required|min:2|max:160',
        'provider_code' => 'required|slug|max:30',
        'business_type' => 'required|in:' . implode(',', array_keys(Provider::BUSINESS_TYPES)),
        'status'        => 'required|in:' . implode(',', array_keys(Provider::STATUSES)),
        'phone'         => 'nullable|phone',
        'email'         => 'nullable|email|max:160',
        'platform_fee_amount' => 'required|numeric|min_value:0',
    ])->errors();

    if (!isset($errors['provider_code']) && $providers->isCodeTaken(strtoupper($values['provider_code']), $id)) {
        $errors['provider_code'] = 'That provider code is already in use.';
    }

    if (!$errors) {
        try {
            $data = [
                'business_name' => $values['business_name'],
                'provider_code' => strtoupper($values['provider_code']),
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
            ];

            if (!empty($_FILES['logo']['tmp_name'])) {
                $info = @getimagesize($_FILES['logo']['tmp_name']);
                $allowed = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/gif' => 'gif', 'image/webp' => 'webp'];
                if ($info && isset($allowed[$info['mime']]) && $_FILES['logo']['size'] <= 2 * 1024 * 1024) {
                    $name = 'provider-' . $id . '-' . date('YmdHis') . '.' . $allowed[$info['mime']];
                    if (@move_uploaded_file($_FILES['logo']['tmp_name'], UPLOADS_PATH . '/logos/' . $name)) {
                        $data['logo'] = 'uploads/logos/' . $name;
                    }
                } else {
                    $errors['business_name'] = 'The logo must be a PNG, JPG, GIF or WebP image under 2 MB.';
                }
            }

            if (!$errors) {
                // Mobile money is a permission, so log the change explicitly.
                $wasEnabled = (int)$record['mobile_money_enabled'] === 1;
                $nowEnabled = (bool)$values['mobile_money_enabled'];
                if ($wasEnabled !== $nowEnabled) {
                    $data['mobile_money_enabled']    = $nowEnabled ? 1 : 0;
                    $data['mobile_money_changed_at'] = date('Y-m-d H:i:s');
                    AuditLog::record('provider_mobile_money', 'provider', $id,
                        ($nowEnabled ? 'Allowed ' : 'Withdrew ') . $record['business_name']
                        . ($nowEnabled ? ' to sell by mobile money' : ' from selling by mobile money'));
                }

                // Billing terms, if they changed.
                /*
                 * No status is passed: BillingService works it out from the
                 * start date, the fee and the invoices that actually exist.
                 * See the note in _form.php.
                 */
                (new BillingService())->setTerms($id, [
                    'fee'          => $values['platform_fee_amount'],
                    'cycle_months' => $values['platform_fee_cycle_months'],
                    'starts_on'    => $values['billing_starts_on'] ?: null,
                ]);

                $providers->updateById($id, $data);

                // Keep the provider's own branding settings in step.
                Setting::setForProvider($id, 'app_name', $values['business_name']);
                Setting::setForProvider($id, 'company_name', $values['business_name']);
                Setting::setForProvider($id, 'currency', $values['currency'] ?: 'TSh');
                Setting::setForProvider($id, 'currency_code', $values['currency_code'] ?: 'TZS');
                Setting::flush();

                AuditLog::record('provider_update', 'provider', $id, 'Updated provider "' . $values['business_name'] . '"');
                Response::redirect('admin/providers/view.php?id=' . $id, 'success', 'Provider saved.');
            }
        } catch (Throwable $e) {
            Logger::error('Provider update failed: ' . $e->getMessage());
            $errors['business_name'] = 'The changes could not be saved. Please try again.';
        }
    }
}

$pageTitle   = 'Edit ' . $record['business_name'];
$activeNav   = 'providers';
$breadcrumbs = [
    ['label' => 'Providers', 'url' => 'admin/providers/index.php'],
    ['label' => $record['business_name'], 'url' => 'admin/providers/view.php?id=' . $id],
    ['label' => 'Edit'],
];
require INCLUDES_PATH . '/admin-header.php';
?>

<?= page_head('Edit provider', $record['provider_code'] . ' · joined ' . format_date($record['created_at'], 'd M Y'),
    '<a class="btn" href="' . e(url('admin/providers/view.php?id=' . $id)) . '">' . icon('eye', 'ico--sm') . ' Overview</a>'
) ?>

<?php if ($errors): ?><?= alert_box('danger', $errors['business_name'] ?? 'Please correct the highlighted fields.') ?><?php endif; ?>

<form method="post" action="" enctype="multipart/form-data" novalidate style="max-width:980px">
    <?= CSRF::field() ?>
    <?php require __DIR__ . '/_form.php'; ?>
</form>

<?php require INCLUDES_PATH . '/footer.php'; ?>
