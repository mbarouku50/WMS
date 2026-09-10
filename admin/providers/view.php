<?php
/**
 * WMS - Provider overview.
 *
 * Everything the platform owner needs to know about one tenant: how big it
 * is, what it earns, what hardware it runs and who works there.
 */

$requirePlatform    = true;
$requiredPermission = 'manage_providers';
require_once __DIR__ . '/../../includes/auth-check.php';
require_once INCLUDES_PATH . '/components.php';

$providers = new Provider();
$id        = (int)query('id');
$provider  = $providers->find($id);

if (!$provider) {
    Response::redirect('admin/providers/index.php', 'error', 'That provider could not be found.');
}

$db    = Database::getInstance();
$stats = $providers->statistics($id);
$staff = $providers->users($id);

/* All figures below are explicitly filtered to this provider - the page runs
   in platform scope, so nothing is scoped for us automatically. */
$routers = $db->fetchAll('SELECT * FROM routers WHERE provider_id = ? ORDER BY name', [$id]);
$packages = $db->fetchAll("SELECT * FROM packages WHERE provider_id = ? ORDER BY sort_order, price", [$id]);
$recentPayments = $db->fetchAll(
    "SELECT p.*, c.full_name AS customer_name, pk.name AS package_name
       FROM payments p
       LEFT JOIN customers c ON c.id = p.customer_id
       LEFT JOIN packages pk ON pk.id = p.package_id
      WHERE p.provider_id = ? ORDER BY p.created_at DESC LIMIT 6",
    [$id]
);
$revenueSeries = $db->fetchAll(
    "SELECT DATE(created_at) AS day, COALESCE(SUM(CASE WHEN status='successful' THEN amount ELSE 0 END),0) AS revenue
       FROM payments WHERE provider_id = ? AND created_at >= (CURDATE() - INTERVAL 14 DAY)
      GROUP BY DATE(created_at) ORDER BY day",
    [$id]
);
$activity = (new AuditLog())->forProvider($id, 8);
$billing  = (new BillingService())->terms($id);
$payouts  = $db->fetchAll(
    'SELECT * FROM withdrawals WHERE provider_id = ? ORDER BY created_at DESC LIMIT 5',
    [$id]
);

$pageTitle   = $provider['business_name'];
$activeNav   = 'providers';
$breadcrumbs = [['label' => 'Providers', 'url' => 'admin/providers/index.php'], ['label' => $provider['business_name']]];
require INCLUDES_PATH . '/admin-header.php';
?>

<?= page_head($provider['business_name'],
    $provider['provider_code'] . ' · ' . (Provider::BUSINESS_TYPES[$provider['business_type']] ?? $provider['business_type'])
        . ' · joined ' . format_date($provider['created_at'], 'd M Y'),
    '<a class="btn" href="' . e(url('admin/providers/edit.php?id=' . $id)) . '">' . icon('edit', 'ico--sm') . ' Edit</a>'
    . '<a class="btn" href="' . e(url('admin/providers/users.php?id=' . $id)) . '">' . icon('users', 'ico--sm') . ' Staff</a>'
    . '<a class="btn" href="' . e(url('admin/providers/routers.php?id=' . $id)) . '">' . icon('router', 'ico--sm') . ' Routers</a>'
    . (Permission::has('impersonate_provider') && $provider['status'] === 'active'
        ? '<form method="post" action="' . e(url('admin/providers/index.php')) . '" style="display:inline"'
          . ' data-confirm="View the system as ' . e($provider['business_name']) . '? Everything you do is logged against your platform account."'
          . ' data-confirm-button="View as provider" data-confirm-tone="primary">'
          . CSRF::field()
          . '<input type="hidden" name="id" value="' . $id . '">'
          . '<button class="btn btn--primary" name="action" value="impersonate">' . icon('eye', 'ico--sm') . ' View as provider</button></form>'
        : '')
) ?>

<?php if ($provider['status'] !== 'active'): ?>
    <?= alert_box('warning',
        'This provider is ' . $provider['status'] . '. Their staff cannot sign in and no new access can be sold, but every record is preserved and you can still inspect everything here.',
        'Provider ' . ucfirst($provider['status'])) ?>
<?php endif; ?>

<div class="grid grid--1-2 mb-3">
    <!-- ------------------------------------------------ business card -->
    <section class="card">
        <div class="card__head">
            <h2 class="card__title"><?= icon('building') ?> Business</h2>
            <?= badge($provider['status']) ?>
        </div>
        <div class="card__body">
            <div class="flex items-center gap-2 mb-3">
                <span class="provider-logo" style="width:48px;height:48px">
                    <?php if (!empty($provider['logo']) && is_file(WMS_ROOT . '/' . $provider['logo'])): ?>
                        <img src="<?= e(url($provider['logo'])) ?>" alt="">
                    <?php else: ?>
                        <?= e(initials($provider['business_name'])) ?>
                    <?php endif; ?>
                </span>
                <div>
                    <div class="strong"><?= e($provider['business_name']) ?></div>
                    <div class="small muted"><?= e($provider['owner_name'] ?: 'No contact set') ?></div>
                </div>
            </div>

            <?= key_value([
                'Provider code' => code_chip($provider['provider_code']),
                'Type'          => e(Provider::BUSINESS_TYPES[$provider['business_type']] ?? $provider['business_type']),
                'Phone'         => e((string)$provider['phone']),
                'Email'         => e((string)$provider['email']),
                'Address'       => e(trim(($provider['address'] ?? '') . ' ' . ($provider['city'] ?? '') . ' ' . ($provider['region'] ?? ''))),
                'Country'       => e((string)$provider['country']),
                'Timezone'      => e($provider['timezone']),
                'Currency'      => e($provider['currency'] . ' (' . $provider['currency_code'] . ')'),
                'Created'       => e(format_date($provider['created_at'])),
            ]) ?>

            <?php if (!empty($provider['description'])): ?>
                <div class="divider-label">About</div>
                <p class="small muted mb-0"><?= nl2br(e($provider['description'])) ?></p>
            <?php endif; ?>
        </div>
        <div class="card__foot flex gap-1 flex-wrap">
            <?php if ($provider['status'] === 'active'): ?>
                <form method="post" action="<?= e(url('admin/providers/index.php')) ?>" data-confirm="Suspend this provider? Their staff will be locked out. No data is deleted.">
                    <?= CSRF::field() ?>
                    <input type="hidden" name="id" value="<?= $id ?>">
                    <button class="btn btn--sm btn--danger" name="action" value="suspend"><?= icon('block', 'ico--sm') ?> Suspend</button>
                </form>
            <?php else: ?>
                <form method="post" action="<?= e(url('admin/providers/index.php')) ?>">
                    <?= CSRF::field() ?>
                    <input type="hidden" name="id" value="<?= $id ?>">
                    <button class="btn btn--sm btn--primary" name="action" value="activate"><?= icon('check', 'ico--sm') ?> Activate</button>
                </form>
            <?php endif; ?>
            <a class="btn btn--sm" href="<?= e(url('admin/providers/activity.php?id=' . $id)) ?>"><?= icon('history', 'ico--sm') ?> Activity</a>
        </div>
    </section>

    <!-- --------------------------------------------------- headline -->
    <div>
        <div class="stat-grid mb-2">
            <?= stat_card(['label' => 'Customers', 'value' => number_format((int)($stats['customers'] ?? 0)), 'icon' => 'users', 'tone' => 'primary',
                'meta' => number_format((int)($stats['customers_active'] ?? 0)) . ' active']) ?>
            <?= stat_card(['label' => 'Routers', 'value' => number_format((int)($stats['routers'] ?? 0)), 'icon' => 'router', 'tone' => 'info',
                'meta' => (int)($stats['routers_online'] ?? 0) . ' online · ' . (int)($stats['access_points'] ?? 0) . ' access points']) ?>
            <?= stat_card(['label' => 'Active sessions', 'value' => number_format((int)($stats['sessions_active'] ?? 0)), 'icon' => 'activity', 'tone' => 'success',
                'meta' => number_format((int)($stats['devices'] ?? 0)) . ' devices known']) ?>
            <?= stat_card(['label' => 'Total revenue', 'value' => money((float)($stats['revenue_total'] ?? 0)), 'icon' => 'money', 'tone' => 'success',
                'meta' => 'This month ' . money((float)($stats['revenue_month'] ?? 0))]) ?>
            <?= stat_card(['label' => 'Vouchers', 'value' => number_format((int)($stats['vouchers'] ?? 0)), 'icon' => 'ticket', 'tone' => 'neutral',
                'meta' => number_format((int)($stats['vouchers_available'] ?? 0)) . ' still available']) ?>
            <?= stat_card(['label' => 'Data carried', 'value' => format_bytes((float)($stats['data_total'] ?? 0)), 'icon' => 'signal', 'tone' => 'info']) ?>
            <?= stat_card(['label' => 'Packages', 'value' => number_format((int)($stats['packages'] ?? 0)), 'icon' => 'package', 'tone' => 'neutral']) ?>
            <?= stat_card(['label' => 'Staff', 'value' => number_format((int)($stats['users'] ?? 0)), 'icon' => 'user', 'tone' => 'neutral',
                'href' => url('admin/providers/users.php?id=' . $id)]) ?>
        </div>

        <section class="card">
            <div class="card__head">
                <h2 class="card__title"><?= icon('money') ?> Revenue, last 14 days</h2>
            </div>
            <div class="card__body">
                <?= chart([
                    'type' => 'bar', 'format' => 'money',
                    'labels' => array_map(static fn($r) => date('d M', strtotime((string)$r['day'])), $revenueSeries),
                    'series' => [['name' => 'Revenue', 'data' => array_map(static fn($r) => (float)$r['revenue'], $revenueSeries)]],
                ], '180px') ?>
            </div>
        </section>
    </div>
</div>

<!-- ======================================================== money -->
<div class="grid grid--2 mb-3">
    <section class="card">
        <div class="card__head">
            <div>
                <h2 class="card__title"><?= icon('card') ?> Wallet</h2>
                <p class="card__subtitle">Money this provider has earned and can withdraw</p>
            </div>
            <?= (int)$provider['mobile_money_enabled'] === 1
                ? '<span class="badge badge--success">Mobile money allowed</span>'
                : '<span class="badge badge--neutral">Vouchers only</span>' ?>
        </div>
        <div class="card__body">
            <?php if ((int)$provider['mobile_money_enabled'] !== 1): ?>
                <?= alert_box('info', 'This provider sells prepaid vouchers only. Customers cannot pay them by mobile money, so nothing is credited here. Turn it on under Edit if you want them to take card-free payments directly.') ?>
            <?php endif; ?>

            <div class="router-card__metrics" style="border-top:0;border:1px solid var(--wms-border);border-radius:var(--wms-radius-sm)">
                <div class="router-card__metric">
                    <b><?= e(money((float)($stats['wallet_balance'] ?? 0))) ?></b>
                    <span>Balance</span>
                </div>
                <div class="router-card__metric">
                    <b><?= e(money((float)($stats['wallet_held'] ?? 0))) ?></b>
                    <span>Held</span>
                </div>
                <div class="router-card__metric">
                    <b><?= e(money((float)$provider['lifetime_earned'])) ?></b>
                    <span>Earned</span>
                </div>
                <div class="router-card__metric">
                    <b><?= e(money((float)$provider['lifetime_withdrawn'])) ?></b>
                    <span>Withdrawn</span>
                </div>
            </div>

            <?php if ($payouts): ?>
                <div class="divider-label">Recent withdrawals</div>
                <?php foreach ($payouts as $payout): ?>
                    <div class="node mb-1">
                        <span class="node__icon node__icon--<?= $payout['status'] === 'completed' ? 'online' : ($payout['status'] === 'failed' ? 'offline' : 'unknown') ?>">
                            <?= icon('download') ?>
                        </span>
                        <span class="node__text">
                            <b><?= e(money((float)$payout['amount'])) ?> · <?= e($payout['method']) ?></b>
                            <span><?= e($payout['reference']) ?> · <?= e(time_ago($payout['created_at'])) ?></span>
                        </span>
                        <?= badge($payout['status'] === 'completed' ? 'successful' : ($payout['status'] === 'processing' ? 'pending' : $payout['status'])) ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <div class="card__foot">
            <a class="btn btn--sm" href="<?= e(url('admin/billing/index.php?tab=withdrawals&provider_id=' . $id)) ?>"><?= icon('download', 'ico--sm') ?> Withdrawals</a>
        </div>
    </section>

    <section class="card">
        <div class="card__head">
            <div>
                <h2 class="card__title"><?= icon('building') ?> Platform fee</h2>
                <p class="card__subtitle">What this provider pays you</p>
            </div>
            <?= badge($billing['status'] === 'current' ? 'active' : ($billing['status'] === 'overdue' ? 'expired' : $billing['status']), ucfirst((string)$billing['status'])) ?>
        </div>
        <div class="card__body">
            <p class="small muted mb-2"><?= e($billing['summary'] ?? '') ?></p>
            <?= key_value([
                'Fee'          => e(money($billing['fee'] ?? 0)) . ' every ' . (($billing['cycle_months'] ?? 1) === 1 ? 'month' : ($billing['cycle_months'] ?? 1) . ' months'),
                'Billing starts' => e(format_date($billing['starts_on'] ?? null, 'd M Y'))
                    . (!empty($billing['in_grace']) ? ' <span class="badge badge--info">' . (int)$billing['grace_days'] . ' days of grace left</span>' : ''),
                'Next invoice' => e(format_date($billing['next_due_on'] ?? null, 'd M Y')),
                'Outstanding'  => ($billing['outstanding'] ?? 0) > 0
                    ? '<b style="color:var(--wms-warning)">' . e(money($billing['outstanding'])) . '</b>'
                    : '<span class="badge badge--success">Nothing owed</span>',
            ]) ?>

            <?php if (!empty($billing['unpaid'])): ?>
                <div class="divider-label">Unpaid</div>
                <?php foreach ($billing['unpaid'] as $invoice): ?>
                    <div class="node mb-1">
                        <span class="node__icon node__icon--<?= $invoice['due_on'] < date('Y-m-d') ? 'offline' : 'unknown' ?>"><?= icon('report') ?></span>
                        <span class="node__text">
                            <b><?= e($invoice['invoice_number']) ?> · <?= e(money((float)$invoice['amount'])) ?></b>
                            <span>Due <?= e(format_date($invoice['due_on'], 'd M Y')) ?></span>
                        </span>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <div class="card__foot flex gap-1 flex-wrap">
            <a class="btn btn--sm" href="<?= e(url('admin/billing/index.php?tab=invoices&provider_id=' . $id)) ?>"><?= icon('report', 'ico--sm') ?> Invoices</a>
            <a class="btn btn--sm" href="<?= e(url('admin/providers/edit.php?id=' . $id)) ?>"><?= icon('edit', 'ico--sm') ?> Change terms</a>
        </div>
    </section>
</div>

<div class="grid grid--2 mb-3">
    <!-- ---------------------------------------------------- routers -->
    <section class="card">
        <div class="card__head">
            <h2 class="card__title"><?= icon('router') ?> Routers</h2>
            <a class="btn btn--sm" href="<?= e(url('admin/providers/routers.php?id=' . $id)) ?>">Assign</a>
        </div>
        <div class="card__body">
            <?php if (!$routers): ?>
                <?= empty_state([
                    'icon' => 'router', 'title' => 'No routers assigned',
                    'text' => 'This provider cannot manage any network hardware yet. Assign a router to give them live control.',
                    'action' => '<a class="btn btn--primary btn--sm" href="' . e(url('admin/providers/routers.php?id=' . $id)) . '">Assign a router</a>',
                ]) ?>
            <?php else: ?>
                <div class="node-list">
                    <?php foreach ($routers as $router): ?>
                        <div class="node">
                            <span class="node__icon node__icon--<?= e(in_array($router['status'], ['online','offline'], true) ? $router['status'] : 'unknown') ?>">
                                <?= icon('router') ?>
                            </span>
                            <span class="node__text">
                                <b><?= e($router['name']) ?> <?= badge($router['status']) ?></b>
                                <span><?= e($router['ip_address']) ?><?= $router['location'] ? ' · ' . e($router['location']) : '' ?></span>
                            </span>
                            <span class="node__metrics">
                                <span><b><?= $router['mode'] === 'live' ? 'Live' : 'Demo' ?></b>Mode</span>
                                <span><b><?= (int)$router['active_users'] ?></b>Users</span>
                            </span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <!-- ---------------------------------------------------- packages -->
    <section class="card">
        <div class="card__head">
            <h2 class="card__title"><?= icon('package') ?> Packages on sale</h2>
        </div>
        <div class="table-wrap">
            <?php if (!$packages): ?>
                <?= empty_state(['icon' => 'package', 'title' => 'No packages yet', 'text' => 'This provider has nothing on sale. They can create packages from their own dashboard.']) ?>
            <?php else: ?>
                <table class="table table--stack table--compact">
                    <thead><tr><th>Package</th><th class="text-right">Price</th><th>Duration</th><th>Data</th><th>Status</th></tr></thead>
                    <tbody>
                    <?php foreach ($packages as $package): ?>
                        <tr>
                            <td data-label="Package"><b><?= e($package['name']) ?></b><div class="tiny muted"><?= e($package['code']) ?></div></td>
                            <td data-label="Price" class="text-right nowrap"><?= e($provider['currency'] . ' ' . number_format((float)$package['price'])) ?></td>
                            <td data-label="Duration" class="nowrap"><?= e(format_package_duration((int)$package['duration_value'], $package['duration_unit'])) ?></td>
                            <td data-label="Data" class="nowrap"><?= e(format_mb($package['data_limit_mb'] === null ? null : (int)$package['data_limit_mb'])) ?></td>
                            <td data-label="Status"><?= badge($package['status']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </section>

    <!-- ------------------------------------------------------- staff -->
    <section class="card">
        <div class="card__head">
            <h2 class="card__title"><?= icon('users') ?> Staff accounts</h2>
            <a class="btn btn--sm" href="<?= e(url('admin/providers/users.php?id=' . $id)) ?>">Manage</a>
        </div>
        <div class="table-wrap">
            <?php if (!$staff): ?>
                <?= empty_state(['icon' => 'users', 'title' => 'No staff accounts', 'text' => 'Nobody can sign in for this provider yet.']) ?>
            <?php else: ?>
                <table class="table table--stack table--compact">
                    <thead><tr><th>Name</th><th>Role</th><th>Last sign in</th><th>Status</th></tr></thead>
                    <tbody>
                    <?php foreach ($staff as $person): ?>
                        <tr>
                            <td data-label="Name"><?= cell_primary($person['full_name'], '@' . $person['username'], avatar($person['full_name'])) ?></td>
                            <td data-label="Role"><?= e($person['role_name']) ?></td>
                            <td data-label="Last sign in" class="nowrap"><?= $person['last_login_at'] ? e(time_ago($person['last_login_at'])) : '<span class="faint">Never</span>' ?></td>
                            <td data-label="Status"><?= badge($person['status']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </section>

    <!-- ---------------------------------------------------- payments -->
    <section class="card">
        <div class="card__head">
            <h2 class="card__title"><?= icon('card') ?> Recent payments</h2>
        </div>
        <div class="card__body--flush">
            <?php if (!$recentPayments): ?>
                <?= empty_state(['icon' => 'card', 'title' => 'No payments yet', 'text' => 'Sales made by this provider will appear here.']) ?>
            <?php else: ?>
                <ul class="activity-list">
                    <?php foreach ($recentPayments as $payment): ?>
                        <li>
                            <span class="activity-list__icon"><?= icon('money', 'ico--sm') ?></span>
                            <span class="activity-list__body">
                                <b><?= e($provider['currency'] . ' ' . number_format((float)$payment['amount'])) ?></b>
                                <span class="muted">· <?= e($payment['package_name'] ?? 'Package') ?></span>
                                <div class="tiny muted"><?= e($payment['customer_name'] ?? $payment['payer_phone'] ?? 'Walk-in') ?></div>
                            </span>
                            <span class="text-right">
                                <?= badge($payment['status']) ?>
                                <div class="activity-list__time"><?= e(time_ago($payment['created_at'])) ?></div>
                            </span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </section>
</div>

<section class="card">
    <div class="card__head">
        <h2 class="card__title"><?= icon('history') ?> Recent activity</h2>
        <a class="btn btn--sm" href="<?= e(url('admin/providers/activity.php?id=' . $id)) ?>">Full log</a>
    </div>
    <div class="table-wrap">
        <?php if (!$activity): ?>
            <?= empty_state(['icon' => 'history', 'title' => 'Nothing logged yet', 'text' => 'Actions taken by this provider will be recorded here.']) ?>
        <?php else: ?>
            <table class="table table--stack table--compact">
                <thead><tr><th>When</th><th>Who</th><th>Action</th><th>Description</th></tr></thead>
                <tbody>
                <?php foreach ($activity as $log): ?>
                    <tr>
                        <td data-label="When" class="nowrap"><?= e(format_date($log['created_at'], 'd M H:i')) ?></td>
                        <td data-label="Who"><?= e($log['actor_name'] ?: 'System') ?></td>
                        <td data-label="Action"><span class="pill"><?= e(label($log['action'])) ?></span></td>
                        <td data-label="Description"><?= e((string)$log['description']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</section>

<?php require INCLUDES_PATH . '/footer.php'; ?>
