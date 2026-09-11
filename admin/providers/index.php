<?php
/**
 * WMS - Provider list (platform only).
 *
 * The Super Admin's view of every Wi-Fi business on the platform.
 */

$requirePlatform    = true;
$requiredPermission = 'manage_providers';
require_once __DIR__ . '/../../includes/auth-check.php';
require_once INCLUDES_PATH . '/components.php';

$providers = new Provider();

if (is_post()) {
    CSRF::verify();
    $action = post('action');
    $id     = (int)post('id');

    /* Leaving an impersonation session - available from the banner. */
    if ($action === 'stop_impersonation') {
        ProviderContext::stopImpersonation();
        Response::redirect('admin/providers/index.php', 'success', 'You are back in the platform view.');
    }

    $record = $id ? $providers->find($id) : null;
    if (!$record) {
        Response::back('error', 'That provider could not be found.');
    }

    switch ($action) {
        case 'suspend':
            $providers->setStatus($id, 'suspended');
            AuditLog::record('provider_suspend', 'provider', $id, 'Suspended provider ' . $record['business_name']);
            Response::back('warning', $record['business_name'] . ' is suspended. Their staff cannot sign in, and all their data is kept.');
            break;

        case 'activate':
            $providers->setStatus($id, 'active');
            AuditLog::record('provider_activate', 'provider', $id, 'Activated provider ' . $record['business_name']);
            Response::back('success', $record['business_name'] . ' is active again.');
            break;

        case 'deactivate':
            $providers->setStatus($id, 'inactive');
            AuditLog::record('provider_deactivate', 'provider', $id, 'Deactivated provider ' . $record['business_name']);
            Response::back('warning', $record['business_name'] . ' has been made inactive.');
            break;

        case 'impersonate':
            if (!ProviderContext::startImpersonation($id)) {
                Response::back('error', 'You do not have permission to view the system as a provider.');
            }
            Response::redirect('admin/index.php', 'info', 'You are now viewing the system as ' . $record['business_name'] . '.');
            break;

        default:
            Response::back('error', 'That action is not supported.');
    }
}

$filters = [
    'q'             => query('q'),
    'status'        => query('status'),
    'business_type' => query('type'),
    'date_from'     => query('from'),
    'date_to'       => query('to'),
    'sort'          => query('sort', 'created_at DESC'),
];

$page   = $providers->search($filters, current_page(), (int)setting('records_per_page', 20));
$counts = $providers->counts();

$pageTitle    = 'Providers';
$pageSubtitle = 'Every Wi-Fi business running on this platform';
$activeNav    = 'providers';
$breadcrumbs  = [['label' => 'Providers']];
require INCLUDES_PATH . '/admin-header.php';
?>

<?= page_head($pageTitle, $pageSubtitle,
    '<a class="btn btn--primary" href="' . e(url('admin/providers/add.php')) . '">' . icon('plus', 'ico--sm') . ' Add provider</a>'
) ?>

<div class="stat-grid mb-3">
    <?= stat_card(['label' => 'Providers', 'value' => number_format($counts['total']), 'icon' => 'building', 'tone' => 'primary']) ?>
    <?= stat_card(['label' => 'Active', 'value' => number_format($counts['active']), 'icon' => 'check', 'tone' => 'success']) ?>
    <?= stat_card(['label' => 'Suspended', 'value' => number_format($counts['suspended']), 'icon' => 'block', 'tone' => 'warning']) ?>
    <?= stat_card(['label' => 'Inactive', 'value' => number_format($counts['inactive']), 'icon' => 'power', 'tone' => 'neutral']) ?>
</div>

<section class="card">
    <form class="filter-bar" method="get" action="">
        <?= search_field($filters['q'], 'Search name, code, owner, phone or city…') ?>
        <?= filter_select('status', $filters['status'], Provider::STATUSES, 'Any status') ?>
        <?= filter_select('type', $filters['business_type'], Provider::BUSINESS_TYPES, 'Any type') ?>
        <?= filter_date('from', $filters['date_from'], 'Joined from') ?>
        <?= filter_date('to', $filters['date_to'], 'Joined to') ?>
        <div class="filter-bar__actions">
            <button class="btn" type="submit"><?= icon('filter', 'ico--sm') ?> Filter</button>
            <a class="btn btn--ghost" href="<?= e(url('admin/providers/index.php')) ?>">Clear</a>
        </div>
    </form>

    <div class="table-wrap">
        <?php if (!$page['rows']): ?>
            <?= empty_state([
                'icon'  => 'building',
                'title' => array_filter([$filters['q'], $filters['status']]) ? 'No providers match those filters' : 'No providers yet',
                'text'  => array_filter([$filters['q'], $filters['status']])
                    ? 'Try a different search, or clear the filters.'
                    : 'Add the first Wi-Fi business to this platform. Each one gets its own customers, packages, vouchers, routers and staff, completely separate from the others.',
                'action' => '<a class="btn btn--primary" href="' . e(url('admin/providers/add.php')) . '">' . icon('plus', 'ico--sm') . ' Add your first provider</a>',
            ]) ?>
        <?php else: ?>
            <table class="table table--stack">
                <thead>
                    <tr>
                        <?= th_sort('Provider', 'business_name', $filters['sort']) ?>
                        <th>Contact</th>
                        <th class="text-right">Staff</th>
                        <th class="text-right">Customers</th>
                        <th class="text-right">Routers</th>
                        <th class="text-right">Live now</th>
                        <th class="text-right">Revenue</th>
                        <?= th_sort('Joined', 'created_at', $filters['sort']) ?>
                        <th>Status</th>
                        <th class="table__actions">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($page['rows'] as $provider): ?>
                    <tr>
                        <td data-label="Provider">
                            <div class="cell-primary">
                                <span class="provider-logo">
                                    <?php if (!empty($provider['logo']) && is_file(WMS_ROOT . '/' . $provider['logo'])): ?>
                                        <img src="<?= e(url($provider['logo'])) ?>" alt="">
                                    <?php else: ?>
                                        <?= e(initials($provider['business_name'])) ?>
                                    <?php endif; ?>
                                </span>
                                <div class="cell-primary__text">
                                    <div class="cell-primary__title">
                                        <a class="link" href="<?= e(url('admin/providers/view.php?id=' . (int)$provider['id'])) ?>"><?= e($provider['business_name']) ?></a>
                                    </div>
                                    <div class="cell-primary__sub"><?= e($provider['provider_code']) ?> · <?= e(Provider::BUSINESS_TYPES[$provider['business_type']] ?? $provider['business_type']) ?></div>
                                </div>
                            </div>
                        </td>
                        <td data-label="Contact">
                            <div class="small"><?= e($provider['owner_name'] ?: '—') ?></div>
                            <div class="tiny muted"><?= e($provider['phone'] ?: $provider['email'] ?: '') ?><?= $provider['city'] ? ' · ' . e($provider['city']) : '' ?></div>
                        </td>
                        <td data-label="Staff" class="text-right"><?= number_format((int)$provider['user_count']) ?></td>
                        <td data-label="Customers" class="text-right"><?= number_format((int)$provider['customer_count']) ?></td>
                        <td data-label="Routers" class="text-right">
                            <?= number_format((int)$provider['router_count']) ?>
                            <?php if ((int)$provider['router_count'] > 0): ?>
                                <div class="tiny muted"><?= (int)$provider['routers_online'] ?> online</div>
                            <?php endif; ?>
                        </td>
                        <td data-label="Live now" class="text-right"><?= number_format((int)$provider['active_sessions']) ?></td>
                        <td data-label="Revenue" class="text-right nowrap strong"><?= e(money((float)$provider['revenue'])) ?></td>
                        <td data-label="Joined" class="nowrap"><?= e(format_date($provider['created_at'], 'd M Y')) ?></td>
                        <td data-label="Status"><?= badge($provider['status']) ?></td>
                        <td class="table__actions" data-label="Actions">
                            <div class="dropdown" style="display:inline-block">
                                <button type="button" class="btn btn--sm btn--icon" data-dropdown="prv-<?= (int)$provider['id'] ?>" aria-label="Actions"><?= icon('more', 'ico--sm') ?></button>
                                <div class="dropdown__menu" id="prv-<?= (int)$provider['id'] ?>">
                                    <a class="dropdown__item" href="<?= e(url('admin/providers/view.php?id=' . (int)$provider['id'])) ?>"><?= icon('eye', 'ico--sm') ?> Overview</a>
                                    <a class="dropdown__item" href="<?= e(url('admin/providers/edit.php?id=' . (int)$provider['id'])) ?>"><?= icon('edit', 'ico--sm') ?> Edit details</a>
                                    <a class="dropdown__item" href="<?= e(url('admin/providers/users.php?id=' . (int)$provider['id'])) ?>"><?= icon('users', 'ico--sm') ?> Staff accounts</a>
                                    <a class="dropdown__item" href="<?= e(url('admin/providers/routers.php?id=' . (int)$provider['id'])) ?>"><?= icon('router', 'ico--sm') ?> Routers</a>
                                    <a class="dropdown__item" href="<?= e(url('admin/providers/activity.php?id=' . (int)$provider['id'])) ?>"><?= icon('history', 'ico--sm') ?> Activity</a>
                                    <?php if (Permission::has('impersonate_provider') && $provider['status'] === 'active'): ?>
                                        <div class="dropdown__divider"></div>
                                        <form method="post" data-confirm="View the system as <?= e($provider['business_name']) ?>? Everything you do is logged against your platform account." data-confirm-button="View as provider" data-confirm-tone="primary">
                                            <?= CSRF::field() ?>
                                            <input type="hidden" name="id" value="<?= (int)$provider['id'] ?>">
                                            <button class="dropdown__item" name="action" value="impersonate"><?= icon('eye', 'ico--sm') ?> View as this provider</button>
                                        </form>
                                    <?php endif; ?>
                                    <div class="dropdown__divider"></div>
                                    <?php if ($provider['status'] === 'active'): ?>
                                        <form method="post" data-confirm="Suspend <?= e($provider['business_name']) ?>? Their staff will not be able to sign in. No data is deleted.">
                                            <?= CSRF::field() ?>
                                            <input type="hidden" name="id" value="<?= (int)$provider['id'] ?>">
                                            <button class="dropdown__item dropdown__item--danger" name="action" value="suspend"><?= icon('block', 'ico--sm') ?> Suspend</button>
                                        </form>
                                    <?php else: ?>
                                        <form method="post">
                                            <?= CSRF::field() ?>
                                            <input type="hidden" name="id" value="<?= (int)$provider['id'] ?>">
                                            <button class="dropdown__item" name="action" value="activate"><?= icon('check', 'ico--sm') ?> Activate</button>
                                        </form>
                                    <?php endif; ?>
                                    <?php /* Deleting asks for the provider code, so it happens on the
                                             overview page rather than behind a single click here. */ ?>
                                    <a class="dropdown__item dropdown__item--danger" href="<?= e(url('admin/providers/view.php?id=' . (int)$provider['id'] . '#danger')) ?>"><?= icon('trash', 'ico--sm') ?> Delete&hellip;</a>
                                </div>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <?= pagination($page, 'providers') ?>
</section>

<?php require INCLUDES_PATH . '/footer.php'; ?>
