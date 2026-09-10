<?php
/**
 * WMS - Customer list.
 */

$requiredPermission = 'manage_customers';
require_once __DIR__ . '/../../includes/auth-check.php';
require_once INCLUDES_PATH . '/components.php';

$customers = new Customer();

/* ---- actions ---- */
if (is_post()) {
    CSRF::verify();
    $id     = (int)post('id');
    $action = post('action');
    $record = $customers->find($id);

    if (!$record) {
        Response::back('error', 'That customer no longer exists.');
    }

    switch ($action) {
        case 'suspend':
            $customers->setStatus($id, 'suspended');
            AuditLog::record('customer_suspend', 'customer', $id, 'Suspended ' . $record['full_name']);
            Response::back('success', $record['full_name'] . ' has been suspended.');
            break;
        case 'activate':
            $customers->setStatus($id, 'active');
            AuditLog::record('customer_activate', 'customer', $id, 'Activated ' . $record['full_name']);
            Response::back('success', $record['full_name'] . ' is active again.');
            break;
        case 'delete':
            try {
                $customers->deleteById($id);
                AuditLog::record('customer_delete', 'customer', $id, 'Deleted ' . $record['full_name']);
                Response::back('success', $record['full_name'] . ' has been deleted.');
            } catch (Throwable $e) {
                Logger::error('Customer delete failed: ' . $e->getMessage());
                Response::back('error', 'This customer has records attached and cannot be deleted. Suspend them instead.');
            }
            break;
        default:
            Response::back('error', 'That action is not supported.');
    }
}

/* ---- listing ---- */
$filters = [
    'q'             => query('q'),
    'status'        => query('status'),
    'customer_type' => query('type'),
    'date_from'     => query('from'),
    'date_to'       => query('to'),
    'sort'          => query('sort', 'created_at DESC'),
];

$listError = '';
try {
    $page  = $customers->search($filters, current_page(), (int)setting('records_per_page', 20));
    $stats = $customers->stats();
} catch (Throwable $e) {
    Logger::error('Customer list failed: ' . $e->getMessage());
    $listError = 'The customer list could not be loaded. Please try again.';
    $page  = ['rows' => [], 'total' => 0, 'page' => 1, 'pages' => 1, 'per_page' => 20, 'from' => 0, 'to' => 0];
    $stats = ['total' => 0, 'active' => 0, 'suspended' => 0, 'new_month' => 0];
}

$pageTitle    = 'Customers';
$pageSubtitle = 'Accounts, devices and everything they have bought';
$activeNav    = 'customers';
$breadcrumbs  = [['label' => 'Customers']];
require INCLUDES_PATH . '/admin-header.php';
?>

<?= page_head($pageTitle, $pageSubtitle,
    '<a class="btn btn--primary" href="' . e(url('admin/customers/add.php')) . '">' . icon('plus', 'ico--sm') . ' Add customer</a>'
) ?>

<?php if ($listError !== ''): ?><?= alert_box('danger', $listError) ?><?php endif; ?>

<div class="stat-grid mb-3">
    <?= stat_card(['label' => 'Total customers', 'value' => number_format($stats['total']), 'icon' => 'users', 'tone' => 'primary']) ?>
    <?= stat_card(['label' => 'Active', 'value' => number_format($stats['active']), 'icon' => 'check', 'tone' => 'success']) ?>
    <?= stat_card(['label' => 'Suspended', 'value' => number_format($stats['suspended']), 'icon' => 'block', 'tone' => 'warning']) ?>
    <?= stat_card(['label' => 'New this month', 'value' => number_format($stats['new_month']), 'icon' => 'trend-up', 'tone' => 'info']) ?>
</div>

<section class="card">
    <form class="filter-bar" method="get" action="">
        <?= search_field($filters['q'], 'Search name, phone, email or code…') ?>
        <?= filter_select('status', $filters['status'], WMS_CUSTOMER_STATUSES, 'Any status') ?>
        <?= filter_select('type', $filters['customer_type'], WMS_CUSTOMER_TYPES, 'Any type') ?>
        <?= filter_date('from', $filters['date_from'], 'Joined from') ?>
        <?= filter_date('to', $filters['date_to'], 'Joined to') ?>
        <div class="filter-bar__actions">
            <button class="btn" type="submit"><?= icon('filter', 'ico--sm') ?> Filter</button>
            <?php if (array_filter([$filters['q'], $filters['status'], $filters['customer_type'], $filters['date_from'], $filters['date_to']])): ?>
                <a class="btn btn--ghost" href="<?= e(url('admin/customers/index.php')) ?>">Clear</a>
            <?php endif; ?>
        </div>
    </form>

    <div class="table-wrap">
        <?php if (!$page['rows']): ?>
            <?= empty_state([
                'icon'  => 'users',
                'title' => $filters['q'] || $filters['status'] ? 'No customers match those filters' : 'No customers yet',
                'text'  => $filters['q'] || $filters['status']
                    ? 'Try a different search term, or clear the filters to see everyone.'
                    : 'Add your first customer to get started. The captive portal also creates customers automatically when someone buys a package.',
                'action' => '<a class="btn btn--primary" href="' . e(url('admin/customers/add.php')) . '">' . icon('plus', 'ico--sm') . ' Add your first customer</a>',
            ]) ?>
        <?php else: ?>
            <table class="table table--stack">
                <thead>
                    <tr>
                        <?= th_sort('Customer', 'full_name', $filters['sort']) ?>
                        <th>Contact</th>
                        <th>Type</th>
                        <th class="text-right">Devices</th>
                        <th class="text-right">Spend</th>
                        <?= th_sort('Joined', 'created_at', $filters['sort']) ?>
                        <th>Status</th>
                        <th class="table__actions">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($page['rows'] as $customer): ?>
                    <tr>
                        <td data-label="Customer">
                            <?= cell_primary(
                                $customer['full_name'],
                                $customer['customer_code'],
                                avatar($customer['full_name']),
                                url('admin/customers/view.php?id=' . (int)$customer['id'])
                            ) ?>
                        </td>
                        <td data-label="Contact">
                            <div class="small"><?= e($customer['phone']) ?></div>
                            <div class="tiny muted"><?= e($customer['email'] ?: '—') ?></div>
                        </td>
                        <td data-label="Type"><?= e(label($customer['customer_type'])) ?></td>
                        <td data-label="Devices" class="text-right"><?= (int)$customer['device_count'] ?></td>
                        <td data-label="Spend" class="text-right nowrap"><?= e(money((float)$customer['total_spend'])) ?></td>
                        <td data-label="Joined" class="nowrap"><?= e(format_date($customer['created_at'], 'd M Y')) ?></td>
                        <td data-label="Status">
                            <?= badge($customer['status']) ?>
                            <?php if ((int)$customer['active_sessions'] > 0): ?>
                                <span class="badge badge--live" title="Currently online">Online</span>
                            <?php endif; ?>
                        </td>
                        <td class="table__actions" data-label="Actions">
                            <div class="dropdown" style="display:inline-block">
                                <button type="button" class="btn btn--sm btn--icon" data-dropdown="cust-<?= (int)$customer['id'] ?>" aria-label="Actions">
                                    <?= icon('more', 'ico--sm') ?>
                                </button>
                                <div class="dropdown__menu" id="cust-<?= (int)$customer['id'] ?>">
                                    <a class="dropdown__item" href="<?= e(url('admin/customers/view.php?id=' . (int)$customer['id'])) ?>"><?= icon('eye', 'ico--sm') ?> View profile</a>
                                    <a class="dropdown__item" href="<?= e(url('admin/customers/edit.php?id=' . (int)$customer['id'])) ?>"><?= icon('edit', 'ico--sm') ?> Edit</a>
                                    <a class="dropdown__item" href="<?= e(url('admin/network/sessions.php?customer_id=' . (int)$customer['id'])) ?>"><?= icon('activity', 'ico--sm') ?> Sessions</a>
                                    <a class="dropdown__item" href="<?= e(url('admin/network/devices.php?customer_id=' . (int)$customer['id'])) ?>"><?= icon('device', 'ico--sm') ?> Devices</a>
                                    <a class="dropdown__item" href="<?= e(url('admin/payments/index.php?customer_id=' . (int)$customer['id'])) ?>"><?= icon('card', 'ico--sm') ?> Payments</a>
                                    <div class="dropdown__divider"></div>
                                    <?php if ($customer['status'] === 'active'): ?>
                                        <form method="post" data-confirm="Suspend <?= e($customer['full_name']) ?>? Their access will be blocked." data-confirm-button="Suspend">
                                            <?= CSRF::field() ?>
                                            <input type="hidden" name="id" value="<?= (int)$customer['id'] ?>">
                                            <input type="hidden" name="action" value="suspend">
                                            <button type="submit" class="dropdown__item"><?= icon('block', 'ico--sm') ?> Suspend</button>
                                        </form>
                                    <?php else: ?>
                                        <form method="post">
                                            <?= CSRF::field() ?>
                                            <input type="hidden" name="id" value="<?= (int)$customer['id'] ?>">
                                            <input type="hidden" name="action" value="activate">
                                            <button type="submit" class="dropdown__item"><?= icon('check', 'ico--sm') ?> Activate</button>
                                        </form>
                                    <?php endif; ?>
                                    <form method="post" data-confirm="Delete <?= e($customer['full_name']) ?> permanently? Customers with payments or sessions cannot be deleted." data-confirm-button="Delete">
                                        <?= CSRF::field() ?>
                                        <input type="hidden" name="id" value="<?= (int)$customer['id'] ?>">
                                        <input type="hidden" name="action" value="delete">
                                        <button type="submit" class="dropdown__item dropdown__item--danger"><?= icon('trash', 'ico--sm') ?> Delete</button>
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

    <?= pagination($page, 'customers') ?>
</section>

<?php require INCLUDES_PATH . '/footer.php'; ?>
