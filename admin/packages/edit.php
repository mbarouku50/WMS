<?php
/**
 * WMS - Edit a package.
 */

$requiredPermission = 'manage_packages';
require_once __DIR__ . '/../../includes/auth-check.php';
require_once INCLUDES_PATH . '/components.php';

$packages = new Package();
$profiles = (new BandwidthProfile())->active();
$id       = (int)query('id');
$record   = $packages->find($id);

if (!$record) {
    Response::redirect('admin/packages/index.php', 'error', 'That package could not be found.');
}

$isEdit = true;
$errors = [];
$values = [
    'name'                 => $record['name'],
    'code'                 => $record['code'],
    'price'                => (string)(float)$record['price'],
    'duration_value'       => (string)$record['duration_value'],
    'duration_unit'        => $record['duration_unit'],
    'unlimited_data'       => $record['data_limit_mb'] === null ? 1 : 0,
    'data_limit_mb'        => (string)($record['data_limit_mb'] ?? ''),
    'download_kbps'        => (string)$record['download_kbps'],
    'upload_kbps'          => (string)$record['upload_kbps'],
    'device_limit'         => (string)$record['device_limit'],
    'bandwidth_profile_id' => (string)($record['bandwidth_profile_id'] ?? ''),
    'description'          => (string)$record['description'],
    'sort_order'           => (string)$record['sort_order'],
    'is_featured'          => (int)$record['is_featured'],
    'status'               => $record['status'],
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

    if (!isset($errors['code']) && $packages->isCodeTaken(strtoupper($values['code']), $id)) {
        $errors['code'] = 'That code is already used by another package.';
    }

    if (!$errors) {
        try {
            $packages->updateById($id, [
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
            AuditLog::record('package_update', 'package', $id, 'Updated package "' . $values['name'] . '"');
            Response::redirect('admin/packages/index.php', 'success', 'Package saved.');
        } catch (Throwable $e) {
            Logger::error('Package update failed: ' . $e->getMessage());
            $errors['name'] = 'The changes could not be saved. Please try again.';
        }
    }
}

$voucherCount = Database::getInstance()->count('SELECT COUNT(*) FROM vouchers WHERE package_id = ?', [$id]);

$pageTitle   = 'Edit ' . $record['name'];
$activeNav   = 'packages';
$breadcrumbs = [['label' => 'Packages', 'url' => 'admin/packages/index.php'], ['label' => $record['name']]];
require INCLUDES_PATH . '/admin-header.php';
?>

<?= page_head('Edit package', $record['code'] . ' · ' . number_format($voucherCount) . ' voucher(s) issued under this package',
    '<a class="btn" href="' . e(url('admin/vouchers/generate.php?package_id=' . $id)) . '">' . icon('ticket', 'ico--sm') . ' Generate vouchers</a>'
) ?>

<?php if ($errors): ?><?= alert_box('danger', 'Please correct the highlighted fields.') ?><?php endif; ?>
<?php if ($voucherCount > 0): ?>
    <?= alert_box('info', 'Existing vouchers keep the rules they were created with. Changes here apply to vouchers and purchases made from now on.') ?>
<?php endif; ?>

<form method="post" action="" novalidate>
    <?= CSRF::field() ?>
    <?php require __DIR__ . '/_form.php'; ?>
</form>

<?php require INCLUDES_PATH . '/footer.php'; ?>
