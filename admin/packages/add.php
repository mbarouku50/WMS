<?php
/**
 * WMS - Create a package.
 */

$requiredPermission = 'manage_packages';
require_once __DIR__ . '/../../includes/auth-check.php';
require_once INCLUDES_PATH . '/components.php';

$packages = new Package();
$profiles = (new BandwidthProfile())->active();
$isEdit   = false;
$errors   = [];
$values   = [
    'name' => '', 'code' => '', 'price' => '1000',
    'duration_value' => '24', 'duration_unit' => 'hours',
    'unlimited_data' => 0, 'data_limit_mb' => '5120',
    'download_kbps' => '5120', 'upload_kbps' => '2048', 'device_limit' => '1',
    'bandwidth_profile_id' => '', 'description' => '', 'sort_order' => '0', 'is_featured' => 0,
    'status' => 'active', 'provider_id' => '',
];

if (is_post()) {
    CSRF::verify();
    foreach ($values as $key => $default) {
        $values[$key] = post($key, (string)$default);
    }
    $values['unlimited_data'] = Validator::bool(post('unlimited_data'));
    $values['is_featured']    = Validator::bool(post('is_featured'));

    $rules = [
        'name'           => 'required|min:2|max:120',
        'code'           => 'required|slug|max:40',
        'price'          => 'required|numeric|min_value:0',
        'duration_value' => 'required|integer|min_value:1',
        'duration_unit'  => 'required|in:' . implode(',', array_keys(WMS_DURATION_UNITS)),
        'download_kbps'  => 'required|integer|min_value:64',
        'upload_kbps'    => 'required|integer|min_value:64',
        'device_limit'   => 'required|integer|min_value:1|max_value:64',
    ];
    if (!$values['unlimited_data']) {
        $rules['data_limit_mb'] = 'required|integer|min_value:1';
    }

    $errors = (new Validator($values))->rules($rules)->errors();

    if (!isset($errors['code']) && $packages->isCodeTaken(strtoupper($values['code']))) {
        $errors['code'] = 'That code is already used by another package.';
    }

    if (!$errors) {
        try {
            // Platform scope has no implicit tenant, so the form supplies one.
            $providerId = ProviderContext::providerId();
            if ($providerId === null) {
                $providerId = (int)post('provider_id');
                if ($providerId <= 0 || (new Provider())->find($providerId) === null) {
                    throw new RuntimeException('Choose which provider this package belongs to.');
                }
            }

            $id = $packages->create([
                'provider_id'          => $providerId,
                'name'                 => $values['name'],
                'code'                 => strtoupper($values['code']),
                'price'                => (float)$values['price'],
                'duration_value'       => (int)$values['duration_value'],
                'duration_unit'        => $values['duration_unit'],
                'data_limit_mb'        => $values['unlimited_data'] ? null : (int)$values['data_limit_mb'],
                'download_kbps'        => (int)$values['download_kbps'],
                'upload_kbps'          => (int)$values['upload_kbps'],
                'device_limit'         => (int)$values['device_limit'],
                'bandwidth_profile_id' => $values['bandwidth_profile_id'] !== '' ? (int)$values['bandwidth_profile_id'] : null,
                'description'          => $values['description'] ?: null,
                'sort_order'           => (int)$values['sort_order'],
                'is_featured'          => $values['is_featured'],
                'status'               => $values['status'],
            ]);
            AuditLog::record('package_create', 'package', $id, 'Created package "' . $values['name'] . '"');
            Response::redirect('admin/packages/index.php', 'success', 'Package "' . $values['name'] . '" created.');
        } catch (Throwable $e) {
            Logger::error('Package create failed: ' . $e->getMessage());
            $errors['name'] = str_starts_with($e->getMessage(), 'Choose which')
                ? $e->getMessage()
                : 'The package could not be saved. Please try again.';
        }
    }
}

$pageTitle   = 'New package';
$activeNav   = 'packages';
$breadcrumbs = [['label' => 'Packages', 'url' => 'admin/packages/index.php'], ['label' => 'New']];
require INCLUDES_PATH . '/admin-header.php';
?>

<?= page_head('New package', 'Everything about a package is configurable - nothing is hard-coded') ?>
<?php if ($errors): ?><?= alert_box('danger', 'Please correct the highlighted fields.') ?><?php endif; ?>

<form method="post" action="" novalidate>
    <?= CSRF::field() ?>
    <?php require __DIR__ . '/_form.php'; ?>
</form>

<?php require INCLUDES_PATH . '/footer.php'; ?>
