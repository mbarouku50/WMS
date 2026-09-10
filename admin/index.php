<?php
/**
 * WMS - Control centre dashboard.
 *
 * Four bands: commercial overview, revenue, network state, and what has
 * just happened. Network readings are labelled Live or Demo so nobody ever
 * mistakes a simulated row for a router reading.
 */

$requiredPermission = 'view_dashboard';
require_once __DIR__ . '/../includes/auth-check.php';
require_once INCLUDES_PATH . '/components.php';

$pageTitle    = 'Dashboard';
$pageSubtitle = 'Everything happening across your network right now';
$activeNav    = 'dashboard';
$breadcrumbs  = [['label' => 'Dashboard']];

/*
 * Two dashboards, one URL.
 *
 * A platform administrator in global scope asks a different question from a
 * provider - "how are all my tenants doing" rather than "how is my hotspot
 * doing" - so they get the platform overview instead. Everything below this
 * point is the provider dashboard, unchanged.
 */
if (ProviderContext::isGlobalScope()) {
    require __DIR__ . '/_platform_dashboard.php';
    return;
}

$providerName = ProviderContext::scopeLabel();
$pageSubtitle = 'Everything happening across the ' . $providerName . ' network right now';

$loadError = '';
try {
    $reports  = new ReportService();
    $network  = (new NetworkService())->overview();
    $summary  = $reports->dashboardSummary();

    $payments      = new Payment();
    $revenueSeries = $payments->dailySeries(14);
    $recentPayments = $payments->recent(6);

    $packagePerformance = (new Package())->performance(date('Y-m-01 00:00:00'), date('Y-m-t 23:59:59'), 5);
    $openAlerts     = (new Alert())->open(5);
    $alertCounts    = (new Alert())->counts();
    $activations    = (new Voucher())->recentActivations(6);
    $activeSessions = (new SessionModel())->activeSessions(6);
    $expiringSoon   = (new Voucher())->expiringSoon(24, 5);
    $newCustomers   = Database::getInstance()->fetchAll(
        'SELECT id, full_name, customer_code, phone, customer_type, created_at FROM customers ORDER BY created_at DESC LIMIT 5'
    );
    $routers = Database::getInstance()->fetchAll('SELECT * FROM routers ORDER BY name LIMIT 6');
    $accessPoints = Database::getInstance()->fetchAll(
        'SELECT a.*, r.name AS router_name FROM access_points a LEFT JOIN routers r ON r.id = a.router_id ORDER BY a.name LIMIT 6'
    );
} catch (Throwable $e) {
    Logger::error('Dashboard failed to load: ' . $e->getMessage());
    $loadError = 'Some dashboard data could not be loaded. The technical detail has been written to the log.';
    $network = ['routers' => ['total' => 0, 'online' => 0, 'offline' => 0, 'unknown' => 0, 'live' => 0],
                'access_points' => ['total' => 0, 'online' => 0, 'offline' => 0, 'unknown' => 0],
                'active_sessions' => 0, 'live_sessions' => 0, 'devices_online' => 0,
                'download_today' => 0, 'upload_today' => 0, 'health' => null, 'demo' => true];
    $summary = ['customers' => ['total' => 0, 'active' => 0, 'suspended' => 0, 'new_month' => 0],
                'vouchers' => ['available' => 0, 'active' => 0, 'all' => 0, 'expired' => 0, 'exhausted' => 0],
                'revenue_today' => 0, 'revenue_week' => 0, 'revenue_month' => 0,
                'payments_pending' => 0, 'payments_failed' => 0,
                'subscriptions' => ['active' => 0], 'packages' => ['active' => 0]];
    $revenueSeries = $recentPayments = $packagePerformance = $openAlerts = [];
    $activations = $activeSessions = $expiringSoon = $newCustomers = $routers = $accessPoints = [];
    $alertCounts = ['open' => 0, 'critical' => 0, 'warning' => 0, 'resolved' => 0];
}

require INCLUDES_PATH . '/admin-header.php';
?>

<?php if ($loadError !== ''): ?>
    <?= alert_box('danger', $loadError, 'Dashboard incomplete') ?>
<?php endif; ?>

<?= page_head($providerName . ' dashboard', $pageSubtitle, ''
    . '<a class="btn" href="' . e(url('admin/reports/index.php')) . '">' . icon('report', 'ico--sm') . ' Reports</a>'
    . '<a class="btn btn--primary" href="' . e(url('admin/vouchers/generate.php')) . '">' . icon('plus', 'ico--sm') . ' Generate vouchers</a>'
) ?>

<!-- ========================================== operating mode strip -->
<div class="hero-strip" data-live-pulse>
    <div>
        <div class="hero-strip__title">
            <?= icon('signal', 'ico--sm') ?>
            <?= $network['demo'] ? 'Running in demo mode' : 'Live network' ?>
        </div>
        <div class="hero-strip__sub" data-live-stamp>
            <?= $network['demo']
                ? 'Business features are fully working. Router readings appear once a router is set to Live mode.'
                : 'Router readings come from RouterOS. Last poll: ' . e(time_ago(date('Y-m-d H:i:s'))) ?>
        </div>
    </div>
    <div class="hero-strip__stats">
        <div class="hero-strip__stat">
            <b data-live="active_sessions"><?= number_format($network['active_sessions']) ?></b>
            <span>Active sessions</span>
        </div>
        <div class="hero-strip__stat">
            <b data-live="devices_online"><?= number_format($network['devices_online']) ?></b>
            <span>Devices online</span>
        </div>
        <div class="hero-strip__stat">
            <b><?= $network['routers']['online'] ?>/<?= $network['routers']['total'] ?></b>
            <span>Routers up</span>
        </div>
        <div class="hero-strip__stat">
            <b><?= $network['health'] === null ? '—' : $network['health'] . '%' ?></b>
            <span>Network health</span>
        </div>
    </div>
</div>

<!-- ================================================ headline figures -->
<div class="stat-grid mb-3">
    <?= stat_card(['label' => 'Customers', 'icon' => 'users', 'tone' => 'primary',
        'value' => number_format($summary['customers']['total']),
        'meta' => number_format($summary['customers']['active']) . ' active · ' . number_format($summary['customers']['new_month']) . ' new this month',
        'href' => url('admin/customers/index.php')]) ?>

    <?= stat_card(['label' => 'Vouchers available', 'icon' => 'ticket', 'tone' => 'info',
        'value' => number_format($summary['vouchers']['available'] ?? 0),
        'meta' => number_format($summary['vouchers']['active'] ?? 0) . ' currently in use',
        'href' => url('admin/vouchers/index.php?status=available')]) ?>

    <?= stat_card(['label' => "Today's revenue", 'icon' => 'money', 'tone' => 'success',
        'value' => money($summary['revenue_today']),
        'meta' => 'Week ' . money($summary['revenue_week']),
        'href' => url('admin/payments/index.php')]) ?>

    <?= stat_card(['label' => 'This month', 'icon' => 'trend-up', 'tone' => 'success',
        'value' => money($summary['revenue_month']),
        'meta' => number_format($summary['payments_pending']) . ' pending · ' . number_format($summary['payments_failed']) . ' failed (7d)',
        'href' => url('admin/reports/index.php?type=revenue')]) ?>

    <?= stat_card(['label' => 'Active sessions', 'icon' => 'activity', 'tone' => 'primary',
        'value' => number_format($network['active_sessions']), 'live' => 'active_sessions',
        'meta' => $network['live_sessions'] . ' live · ' . ($network['active_sessions'] - $network['live_sessions']) . ' demo',
        'href' => url('admin/network/sessions.php?status=active')]) ?>

    <?= stat_card(['label' => 'Devices online', 'icon' => 'device', 'tone' => 'neutral',
        'value' => number_format($network['devices_online']), 'live' => 'devices_online',
        'meta' => 'Across all access points',
        'href' => url('admin/network/devices.php')]) ?>

    <?= stat_card(['label' => 'Traffic today', 'icon' => 'signal', 'tone' => 'info',
        'value' => format_bytes($network['download_today'] + $network['upload_today']),
        'meta' => '↓ ' . format_bytes($network['download_today']) . '  ↑ ' . format_bytes($network['upload_today']),
        'href' => url('admin/usage/index.php')]) ?>

    <?php if (Permission::has('view_wallet')): ?>
        <?php $walletBalance = (new WalletService())->balance((int)ProviderContext::providerId()); ?>
        <?= stat_card([
            'label' => 'Wallet balance',
            'value' => money($walletBalance['available']),
            'icon'  => 'money',
            'tone'  => $walletBalance['available'] > 0 ? 'success' : 'neutral',
            'meta'  => $walletBalance['held'] > 0
                ? money($walletBalance['held']) . ' held · ' . money($walletBalance['earned']) . ' earned'
                : money($walletBalance['earned']) . ' earned all time',
            'href'  => url('admin/wallet/index.php'),
        ]) ?>
    <?php endif; ?>

    <?= stat_card(['label' => 'Open alerts', 'icon' => 'alert',
        'tone' => $alertCounts['critical'] > 0 ? 'danger' : ($alertCounts['open'] > 0 ? 'warning' : 'neutral'),
        'value' => number_format($alertCounts['open']),
        'meta' => $alertCounts['critical'] . ' need attention now',
        'href' => url('admin/alerts/index.php?state=open')]) ?>
</div>

<!-- ========================================== revenue + package mix -->
<div class="grid grid--3-2 mb-3">
    <section class="card">
        <div class="card__head">
            <div>
                <h2 class="card__title"><?= icon('money') ?> Revenue, last 14 days</h2>
                <p class="card__subtitle">Successful payments only</p>
            </div>
            <a class="btn btn--sm" href="<?= e(url('admin/reports/index.php?type=revenue')) ?>">Full report</a>
        </div>
        <div class="card__body">
            <?= chart([
                'type'   => 'bar',
                'format' => 'money',
                'labels' => array_map(static fn($r) => date('d M', strtotime((string)$r['day'])), $revenueSeries),
                'series' => [['name' => 'Revenue', 'data' => array_map(static fn($r) => (float)$r['revenue'], $revenueSeries)]],
            ], '220px') ?>
        </div>
    </section>

    <section class="card">
        <div class="card__head">
            <h2 class="card__title"><?= icon('package') ?> Package performance</h2>
            <span class="small muted">This month</span>
        </div>
        <div class="card__body">
            <?php if (!$packagePerformance): ?>
                <?= empty_state(['icon' => 'package', 'title' => 'No sales yet this month', 'text' => 'Package performance appears here once payments start coming in.']) ?>
            <?php else: ?>
                <?php $topRevenue = max(array_map(static fn($p) => (float)$p['revenue'], $packagePerformance)) ?: 1; ?>
                <?php foreach ($packagePerformance as $package): ?>
                    <div class="mb-2">
                        <div class="flex justify-between items-center" style="gap:.75rem">
                            <span class="small strong"><?= e($package['name']) ?></span>
                            <span class="small muted nowrap"><?= (int)$package['sales'] ?> × · <b><?= e(money((float)$package['revenue'])) ?></b></span>
                        </div>
                        <div class="mt-1"><?= meter(percent((float)$package['revenue'], $topRevenue, 1), 'success') ?></div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </section>
</div>

<!-- ============================================== network + alerts -->
<div class="grid grid--2-1 mb-3">
    <section class="card">
        <div class="card__head">
            <div>
                <h2 class="card__title"><?= icon('router') ?> Network equipment</h2>
                <p class="card__subtitle">
                    <?= $network['routers']['online'] ?> of <?= $network['routers']['total'] ?> routers online ·
                    <?= $network['access_points']['online'] ?> of <?= $network['access_points']['total'] ?> access points online
                </p>
            </div>
            <a class="btn btn--sm" href="<?= e(url('admin/network/routers.php')) ?>">Manage</a>
        </div>
        <div class="card__body">
            <?php if (!$routers): ?>
                <?= empty_state([
                    'icon' => 'router',
                    'title' => 'No routers added yet',
                    'text' => 'Add your first MikroTik router to start monitoring sessions and pushing hotspot users.',
                    'action' => '<a class="btn btn--primary" href="' . e(url('admin/network/routers.php')) . '">' . icon('plus', 'ico--sm') . ' Add a router</a>',
                ]) ?>
            <?php else: ?>
                <div class="node-list">
                    <?php foreach ($routers as $router): ?>
                        <?php $state = $router['status']; ?>
                        <div class="node">
                            <span class="node__icon node__icon--<?= e(in_array($state, ['online','offline'], true) ? $state : 'unknown') ?>">
                                <?= icon('router') ?>
                            </span>
                            <span class="node__text">
                                <b><?= e($router['name']) ?> <?= badge($state) ?> <?= $router['mode'] === 'live' ? '' : '<span class="badge badge--demo">Demo</span>' ?></b>
                                <span><?= e($router['ip_address']) ?><?= $router['location'] ? ' · ' . e($router['location']) : '' ?></span>
                            </span>
                            <span class="node__metrics">
                                <span><b><?= $router['cpu_load'] === null ? '—' : (int)$router['cpu_load'] . '%' ?></b>CPU</span>
                                <span><b><?= (int)$router['active_users'] ?></b>Users</span>
                                <span><b><?= $router['last_seen_at'] ? e(time_ago($router['last_seen_at'])) : '—' ?></b>Seen</span>
                            </span>
                        </div>
                    <?php endforeach; ?>
                </div>

                <?php if ($accessPoints): ?>
                    <div class="divider-label">Access points</div>
                    <div class="node-list">
                        <?php foreach (array_slice($accessPoints, 0, 4) as $ap): ?>
                            <div class="node">
                                <span class="node__icon node__icon--<?= e(in_array($ap['status'], ['online','offline'], true) ? $ap['status'] : 'unknown') ?>">
                                    <?= icon('antenna') ?>
                                </span>
                                <span class="node__text">
                                    <b><?= e($ap['name']) ?> <?= badge($ap['status']) ?></b>
                                    <span><?= e($ap['location'] ?: '—') ?><?= $ap['router_name'] ? ' · ' . e($ap['router_name']) : '' ?></span>
                                </span>
                                <span class="node__metrics">
                                    <span><b><?= (int)$ap['connected_users'] ?></b>Users</span>
                                    <span><?= signal_bars($ap['status'] === 'online' ? (int)$ap['health'] : null) ?></span>
                                </span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </section>

    <section class="card">
        <div class="card__head">
            <h2 class="card__title"><?= icon('alert') ?> Alerts</h2>
            <a class="btn btn--sm" href="<?= e(url('admin/alerts/index.php')) ?>">All</a>
        </div>
        <div class="card__body--flush">
            <?php if (!$openAlerts): ?>
                <?= empty_state(['icon' => 'check', 'title' => 'Nothing needs attention', 'text' => 'Alerts about offline routers, failed payments and unusual usage appear here.']) ?>
            <?php else: ?>
                <?php foreach ($openAlerts as $alert): ?>
                    <div class="alert-row alert-row--<?= e($alert['severity']) ?>">
                        <div class="alert-row__body">
                            <div class="alert-row__title"><?= e($alert['title']) ?></div>
                            <div class="alert-row__text"><?= e(str_limit($alert['message'], 110)) ?></div>
                            <div class="tiny faint mt-1"><?= e(time_ago($alert['created_at'])) ?></div>
                        </div>
                        <?= badge($alert['severity'] === 'critical' ? 'blocked' : $alert['severity'], ucfirst($alert['severity'])) ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </section>
</div>

<!-- ================================================ recent activity -->
<div class="grid grid--3 mb-3">
    <section class="card">
        <div class="card__head">
            <h2 class="card__title"><?= icon('card') ?> Recent payments</h2>
            <a class="btn btn--sm btn--ghost" href="<?= e(url('admin/payments/index.php')) ?>">All</a>
        </div>
        <div class="card__body--flush">
            <?php if (!$recentPayments): ?>
                <?= empty_state(['icon' => 'card', 'title' => 'No payments yet', 'text' => 'Payments show up here the moment a customer buys a package.']) ?>
            <?php else: ?>
                <ul class="activity-list">
                    <?php foreach ($recentPayments as $payment): ?>
                        <li>
                            <span class="activity-list__icon"><?= icon('money', 'ico--sm') ?></span>
                            <span class="activity-list__body">
                                <b><?= e(money((float)$payment['amount'])) ?></b>
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

    <section class="card">
        <div class="card__head">
            <h2 class="card__title"><?= icon('ticket') ?> Voucher activity</h2>
            <a class="btn btn--sm btn--ghost" href="<?= e(url('admin/vouchers/index.php')) ?>">All</a>
        </div>
        <div class="card__body--flush">
            <?php if (!$activations): ?>
                <?= empty_state(['icon' => 'ticket', 'title' => 'No activations yet', 'text' => 'When a customer enters a voucher on the portal it appears here.']) ?>
            <?php else: ?>
                <ul class="activity-list">
                    <?php foreach ($activations as $activation): ?>
                        <li>
                            <span class="activity-list__icon"><?= icon('ticket', 'ico--sm') ?></span>
                            <span class="activity-list__body">
                                <b class="mono"><?= e($activation['code']) ?></b>
                                <div class="tiny muted"><?= e($activation['package_name']) ?> · <?= e($activation['customer_name'] ?? 'Hotspot guest') ?></div>
                            </span>
                            <span class="text-right">
                                <?= badge($activation['status']) ?>
                                <div class="activity-list__time"><?= e(time_ago($activation['activated_at'])) ?></div>
                            </span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </section>

    <section class="card">
        <div class="card__head">
            <h2 class="card__title"><?= icon('users') ?> New customers</h2>
            <a class="btn btn--sm btn--ghost" href="<?= e(url('admin/customers/index.php')) ?>">All</a>
        </div>
        <div class="card__body--flush">
            <?php if (!$newCustomers): ?>
                <?= empty_state([
                    'icon' => 'users', 'title' => 'No customers yet',
                    'text' => 'Add your first customer, or let the captive portal create them automatically.',
                    'action' => '<a class="btn btn--primary btn--sm" href="' . e(url('admin/customers/add.php')) . '">Add a customer</a>',
                ]) ?>
            <?php else: ?>
                <ul class="activity-list">
                    <?php foreach ($newCustomers as $customer): ?>
                        <li>
                            <?= avatar($customer['full_name']) ?>
                            <span class="activity-list__body">
                                <b><a href="<?= e(url('admin/customers/view.php?id=' . (int)$customer['id'])) ?>"><?= e($customer['full_name']) ?></a></b>
                                <div class="tiny muted"><?= e($customer['phone']) ?> · <?= e(label($customer['customer_type'])) ?></div>
                            </span>
                            <span class="activity-list__time"><?= e(time_ago($customer['created_at'])) ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </section>
</div>

<!-- =========================================== live sessions + expiring -->
<div class="grid grid--2-1">
    <section class="card">
        <div class="card__head">
            <div>
                <h2 class="card__title"><?= icon('activity') ?> Live sessions</h2>
                <p class="card__subtitle">Devices currently holding an open session</p>
            </div>
            <a class="btn btn--sm" href="<?= e(url('admin/network/sessions.php?status=active')) ?>">Session manager</a>
        </div>
        <div class="table-wrap">
            <?php if (!$activeSessions): ?>
                <?= empty_state(['icon' => 'activity', 'title' => 'Nobody is online right now', 'text' => 'Active sessions appear here as soon as a customer connects.']) ?>
            <?php else: ?>
                <table class="table table--stack">
                    <thead>
                        <tr>
                            <th>Customer</th>
                            <th>Voucher</th>
                            <th>Device</th>
                            <th>Duration</th>
                            <th class="text-right">Data</th>
                            <th>Source</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($activeSessions as $session): ?>
                        <tr>
                            <td data-label="Customer">
                                <?= cell_primary(
                                    $session['customer_name'] ?? 'Hotspot guest',
                                    $session['ip_address'] ?? '',
                                    avatar($session['customer_name'] ?? 'G'),
                                    $session['customer_id'] ? url('admin/customers/view.php?id=' . (int)$session['customer_id']) : ''
                                ) ?>
                            </td>
                            <td data-label="Voucher"><?= $session['voucher_code'] ? code_chip($session['voucher_code']) : '<span class="faint">—</span>' ?></td>
                            <td data-label="Device"><?= e($session['device_name'] ?? '—') ?><div class="tiny faint mono"><?= e($session['mac_address'] ?? '') ?></div></td>
                            <td data-label="Duration" class="nowrap"><span data-since="<?= e($session['started_at']) ?>"><?= e(format_duration(strtotime('now') - strtotime((string)$session['started_at']))) ?></span></td>
                            <td data-label="Data" class="text-right nowrap"><?= e(format_bytes((float)$session['download_bytes'] + (float)$session['upload_bytes'])) ?></td>
                            <td data-label="Source"><?= source_badge($session['source']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </section>

    <section class="card">
        <div class="card__head">
            <h2 class="card__title"><?= icon('clock') ?> Expiring within 24h</h2>
        </div>
        <div class="card__body--flush">
            <?php if (!$expiringSoon): ?>
                <?= empty_state(['icon' => 'clock', 'title' => 'Nothing expiring soon', 'text' => 'Vouchers ending in the next day will be listed here so you can prompt a renewal.']) ?>
            <?php else: ?>
                <ul class="activity-list">
                    <?php foreach ($expiringSoon as $voucher): ?>
                        <li>
                            <span class="activity-list__icon"><?= icon('clock', 'ico--sm') ?></span>
                            <span class="activity-list__body">
                                <b class="mono"><?= e($voucher['code']) ?></b>
                                <div class="tiny muted"><?= e($voucher['customer_name'] ?? 'Hotspot guest') ?> · <?= e($voucher['package_name']) ?></div>
                            </span>
                            <span class="activity-list__time" data-countdown="<?= (int)seconds_until($voucher['expires_at']) ?>">
                                <?= e(format_duration(seconds_until($voucher['expires_at']))) ?>
                            </span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </section>
</div>

<?php require INCLUDES_PATH . '/footer.php'; ?>
