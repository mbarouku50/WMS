<?php
/**
 * WMS - Package list.
 */

$requiredPermission = 'manage_packages';
require_once __DIR__ . '/../../includes/auth-check.php';
require_once INCLUDES_PATH . '/components.php';

$packages = new Package();

if (is_post()) {
    CSRF::verify();
    $id     = (int)post('id');
    $action = post('action');
    $record = $packages->find($id);

    if (!$record) {
        Response::back('error', 'That package no longer exists.');
    }

    if ($action === 'toggle') {
        $new = $record['status'] === 'active' ? 'inactive' : 'active';
        $packages->updateById($id, ['status' => $new]);
        AuditLog::record('package_update', 'package', $id, 'Set ' . $record['name'] . ' to ' . $new);
        Response::back('success', $record['name'] . ' is now ' . $new . '.');
    }

    if ($action === 'delete') {
        $inUse = Database::getInstance()->count('SELECT COUNT(*) FROM vouchers WHERE package_id = ?', [$id])
               + Database::getInstance()->count('SELECT COUNT(*) FROM subscriptions WHERE package_id = ?', [$id]);
        if ($inUse > 0) {
            Response::back('error', 'This package has ' . $inUse . ' voucher(s) or subscription(s) attached. Deactivate it instead of deleting.');
        }
        $packages->deleteById($id);
        AuditLog::record('package_delete', 'package', $id, 'Deleted package ' . $record['name']);
        Response::back('success', 'Package deleted.');
    }

    Response::back('error', 'That action is not supported.');
}

$filters = [
    'q'             => query('q'),
    'status'        => query('status'),
    'duration_unit' => query('unit'),
    'data_type'     => query('data'),
];

$page  = $packages->search($filters, current_page(), (int)setting('records_per_page', 20));
$stats = $packages->stats();

$pageTitle    = 'Packages';
$pageSubtitle = 'The plans you sell - price, time, data, speed and devices';
$activeNav    = 'packages';
$breadcrumbs  = [['label' => 'Packages']];
require INCLUDES_PATH . '/admin-header.php';
?>

<?= page_head($pageTitle, $pageSubtitle,
    '<a class="btn" href="' . e(url('admin/network/bandwidth.php')) . '">' . icon('sliders', 'ico--sm') . ' Bandwidth profiles</a>'
    . '<a class="btn btn--primary" href="' . e(url('admin/packages/add.php')) . '">' . icon('plus', 'ico--sm') . ' New package</a>'
) ?>

<div class="stat-grid mb-3">
    <?= stat_card(['label' => 'Packages', 'value' => number_format($stats['total']), 'icon' => 'package', 'tone' => 'primary']) ?>
    <?= stat_card(['label' => 'On sale', 'value' => number_format($stats['active']), 'icon' => 'check', 'tone' => 'success']) ?>
    <?= stat_card(['label' => 'Inactive', 'value' => number_format($stats['inactive']), 'icon' => 'block', 'tone' => 'neutral']) ?>
</div>

<section class="card">
    <form class="filter-bar" method="get" action="">
        <?= search_field($filters['q'], 'Search packages…') ?>
        <?= filter_select('status', $filters['status'], ['active' => 'Active', 'inactive' => 'Inactive'], 'Any status') ?>
        <?= filter_select('unit', $filters['duration_unit'], WMS_DURATION_UNITS, 'Any duration') ?>
        <?= filter_select('data', $filters['data_type'], ['unlimited' => 'Unlimited data', 'capped' => 'Capped data'], 'Any allowance') ?>
        <div class="filter-bar__actions">
            <button class="btn" type="submit"><?= icon('filter', 'ico--sm') ?> Filter</button>
        </div>
    </form>

    <div class="table-wrap">
        <?php if (!$page['rows']): ?>
            <?= empty_state([
                'icon'  => 'package',
                'title' => 'No packages yet',
                'text'  => 'Packages are never hard-coded here. Create the plans your customers actually buy - by the hour, the day, the week or the month.',
                'action' => '<a class="btn btn--primary" href="' . e(url('admin/packages/add.php')) . '">' . icon('plus', 'ico--sm') . ' Create your first package</a>',
            ]) ?>
        <?php else: ?>
            <table class="table table--stack">
                <thead>
                    <tr>
                        <th>Package</th>
                        <th class="text-right">Price</th>
                        <th>Duration</th>
                        <th>Data</th>
                        <th>Speed</th>
                        <th class="text-right">Devices</th>
                        <th class="text-right">Sold</th>
                        <th>Status</th>
                        <th class="table__actions">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($page['rows'] as $package): ?>
                    <tr>
                        <td data-label="Package">
                            <?= cell_primary($package['name'], $package['description'] ? str_limit($package['description'], 48) : $package['code'], '', url('admin/packages/edit.php?id=' . (int)$package['id'])) ?>
                            <?php if (!empty($package['is_featured'])): ?><span class="badge badge--demo badge--plain">Featured</span><?php endif; ?>
                        </td>
                        <td data-label="Price" class="text-right nowrap strong"><?= e(money((float)$package['price'])) ?></td>
                        <td data-label="Duration" class="nowrap"><?= e(format_package_duration((int)$package['duration_value'], $package['duration_unit'])) ?></td>
                        <td data-label="Data" class="nowrap"><?= e(format_mb($package['data_limit_mb'] === null ? null : (int)$package['data_limit_mb'])) ?></td>
                        <td data-label="Speed" class="nowrap">
                            <?= e(format_speed((int)$package['download_kbps'])) ?>
                            <div class="tiny muted">↑ <?= e(format_speed((int)$package['upload_kbps'])) ?><?= $package['profile_name'] ? ' · ' . e($package['profile_name']) : '' ?></div>
                        </td>
                        <td data-label="Devices" class="text-right"><?= (int)$package['device_limit'] ?></td>
                        <td data-label="Sold" class="text-right"><?= number_format((int)$package['voucher_count'] + (int)$package['subscription_count']) ?></td>
                        <td data-label="Status"><?= badge($package['status']) ?></td>
                        <td class="table__actions" data-label="Actions">
                            <a class="btn btn--sm" href="<?= e(url('admin/packages/edit.php?id=' . (int)$package['id'])) ?>"><?= icon('edit', 'ico--sm') ?></a>
                            <div class="dropdown" style="display:inline-block">
                                <button type="button" class="btn btn--sm btn--icon" data-dropdown="pkg-<?= (int)$package['id'] ?>" aria-label="More"><?= icon('more', 'ico--sm') ?></button>
                                <div class="dropdown__menu" id="pkg-<?= (int)$package['id'] ?>">
                                    <a class="dropdown__item" href="<?= e(url('admin/vouchers/generate.php?package_id=' . (int)$package['id'])) ?>"><?= icon('ticket', 'ico--sm') ?> Generate vouchers</a>
                                    <form method="post">
                                        <?= CSRF::field() ?>
                                        <input type="hidden" name="id" value="<?= (int)$package['id'] ?>">
                                        <input type="hidden" name="action" value="toggle">
                                        <button class="dropdown__item"><?= icon('power', 'ico--sm') ?> <?= $package['status'] === 'active' ? 'Deactivate' : 'Activate' ?></button>
                                    </form>
                                    <form method="post" data-confirm="Delete <?= e($package['name']) ?>? This only works if no vouchers use it.">
                                        <?= CSRF::field() ?>
                                        <input type="hidden" name="id" value="<?= (int)$package['id'] ?>">
                                        <input type="hidden" name="action" value="delete">
                                        <button class="dropdown__item dropdown__item--danger"><?= icon('trash', 'ico--sm') ?> Delete</button>
                                    </form>
                                </div>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <?= pagination($page, 'packages') ?>
</section>

<?php require INCLUDES_PATH . '/footer.php'; ?>
