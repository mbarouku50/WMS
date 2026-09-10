<?php
/**
 * WMS - Platform dashboard (Super Admin, global scope).
 *
 * The provider dashboard in index.php is untouched; this is what a platform
 * administrator sees instead, because their question is different: not "how
 * is my hotspot doing" but "how are all my tenants doing".
 */

$providers = new Provider();
$loadError = '';

try {
    $totals      = $providers->platformTotals();
    $counts      = $providers->counts();
    $performance = $providers->performance(10);
    $openAlerts  = (new Alert())->open(6);
    $alertCounts = (new Alert())->counts();
    $revenueSeries = (new Payment())->dailySeries(14);
    $recentProviders = Database::getInstance()->fetchAll(
        'SELECT id, business_name, provider_code, status, city, created_at FROM providers ORDER BY created_at DESC LIMIT 5'
    );
    $activity = (new AuditLog())->recent(8);
    $offlineRouters = Database::getInstance()->fetchAll(
        "SELECT r.*, p.business_name AS provider_name
           FROM routers r LEFT JOIN providers p ON p.id = r.provider_id
          WHERE r.status <> 'online' AND r.mode = 'live'
          ORDER BY r.name LIMIT 6"
    );
} catch (Throwable $e) {
    Logger::error('Platform dashboard failed to load: ' . $e->getMessage());
    $loadError = 'Some platform figures could not be loaded. The technical detail is in the log.';
    $totals = $counts = [];
    $performance = $openAlerts = $revenueSeries = $recentProviders = $activity = $offlineRouters = [];
    $alertCounts = ['open' => 0, 'critical' => 0];
}

require INCLUDES_PATH . '/admin-header.php';
?>

<?php if ($loadError !== ''): ?><?= alert_box('danger', $loadError) ?><?php endif; ?>

<?= page_head('Platform overview', 'Every Wi-Fi provider running on this installation',
    '<a class="btn" href="' . e(url('admin/reports/index.php')) . '">' . icon('report', 'ico--sm') . ' Reports</a>'
    . '<a class="btn btn--primary" href="' . e(url('admin/providers/add.php')) . '">' . icon('plus', 'ico--sm') . ' Add provider</a>'
) ?>

<!-- ============================================== platform strip -->
<div class="hero-strip">
    <div>
        <div class="hero-strip__title">
            <?= icon('globe', 'ico--sm') ?>
            <?= e(setting('platform_name', 'WMS')) ?> platform
        </div>
        <div class="hero-strip__sub">
            You are in global scope: these figures cover every provider.
            <?= demo_mode() ? ' Demo mode is on, so no router is being contacted.' : '' ?>
        </div>
    </div>
    <div class="hero-strip__stats">
        <div class="hero-strip__stat">
            <b><?= number_format((int)($totals['providers_active'] ?? 0)) ?></b>
            <span>Active providers</span>
        </div>
        <div class="hero-strip__stat">
            <b><?= number_format((int)($totals['sessions_active'] ?? 0)) ?></b>
            <span>Sessions live</span>
        </div>
        <div class="hero-strip__stat">
            <b><?= (int)($totals['routers_online'] ?? 0) ?>/<?= (int)($totals['routers'] ?? 0) ?></b>
            <span>Routers up</span>
        </div>
        <div class="hero-strip__stat">
            <b><?= e(money((float)($totals['revenue_today'] ?? 0))) ?></b>
            <span>Today across all</span>
        </div>
    </div>
</div>

<!-- ============================================ headline figures -->
<div class="stat-grid mb-3">
    <?= stat_card(['label' => 'Providers', 'value' => number_format((int)($totals['providers'] ?? 0)), 'icon' => 'building', 'tone' => 'primary',
        'meta' => (int)($counts['active'] ?? 0) . ' active · ' . (int)($counts['suspended'] ?? 0) . ' suspended',
        'href' => url('admin/providers/index.php')]) ?>

    <?= stat_card(['label' => 'Customers', 'value' => number_format((int)($totals['customers'] ?? 0)), 'icon' => 'users', 'tone' => 'info',
        'meta' => 'Across every provider', 'href' => url('admin/customers/index.php')]) ?>

    <?= stat_card(['label' => 'Routers', 'value' => number_format((int)($totals['routers'] ?? 0)), 'icon' => 'router', 'tone' => 'neutral',
        'meta' => (int)($totals['routers_online'] ?? 0) . ' online', 'href' => url('admin/network/routers.php')]) ?>

    <?= stat_card(['label' => 'Active sessions', 'value' => number_format((int)($totals['sessions_active'] ?? 0)), 'icon' => 'activity', 'tone' => 'success',
        'href' => url('admin/network/sessions.php?status=active')]) ?>

    <?= stat_card(['label' => "Today's revenue", 'value' => money((float)($totals['revenue_today'] ?? 0)), 'icon' => 'money', 'tone' => 'success',
        'meta' => 'This month ' . money((float)($totals['revenue_month'] ?? 0))]) ?>

    <?= stat_card(['label' => 'Lifetime revenue', 'value' => money((float)($totals['revenue_total'] ?? 0)), 'icon' => 'trend-up', 'tone' => 'success',
        'href' => url('admin/reports/index.php?type=revenue')]) ?>

    <?= stat_card(['label' => 'Vouchers issued', 'value' => number_format((int)($totals['vouchers'] ?? 0)), 'icon' => 'ticket', 'tone' => 'neutral',
        'href' => url('admin/vouchers/index.php')]) ?>

    <?= stat_card(['label' => 'Data carried', 'value' => format_bytes((float)($totals['data_total'] ?? 0)), 'icon' => 'signal', 'tone' => 'info',
        'href' => url('admin/usage/index.php')]) ?>

    <?php if (Permission::has('manage_billing')): ?>
        <?php $billingSummary = (new BillingService())->platformSummary(); ?>
        <?= stat_card([
            'label' => 'Held for providers',
            'value' => money((float)($billingSummary['held_for_providers'] ?? 0)),
            'icon'  => 'card', 'tone' => 'neutral',
            'meta'  => money((float)($billingSummary['pending_amount'] ?? 0)) . ' waiting to pay out',
            'href'  => url('admin/billing/index.php?tab=wallets'),
        ]) ?>
        <?= stat_card([
            'label' => 'Platform fees owed',
            'value' => money((float)($billingSummary['outstanding'] ?? 0)),
            'icon'  => 'building',
            'tone'  => ($billingSummary['overdue'] ?? 0) > 0 ? 'danger' : (($billingSummary['outstanding'] ?? 0) > 0 ? 'warning' : 'success'),
            'meta'  => (int)($billingSummary['overdue_count'] ?? 0) . ' overdue · ' . money((float)($billingSummary['collected'] ?? 0)) . ' collected',
            'href'  => url('admin/billing/index.php?tab=invoices'),
        ]) ?>
    <?php endif; ?>
</div>

<!-- ==================================== provider performance table -->
<div class="grid grid--3-2 mb-3">
    <section class="card">
        <div class="card__head">
            <div>
                <h2 class="card__title"><?= icon('building') ?> Provider performance</h2>
                <p class="card__subtitle">Revenue this month, and the size of each tenant</p>
            </div>
            <a class="btn btn--sm" href="<?= e(url('admin/providers/index.php')) ?>">All providers</a>
        </div>
        <div class="table-wrap">
            <?php if (!$performance): ?>
                <?= empty_state([
                    'icon' => 'building', 'title' => 'No providers yet',
                    'text' => 'Add the first Wi-Fi business to this platform and its figures will appear here.',
                    'action' => '<a class="btn btn--primary" href="' . e(url('admin/providers/add.php')) . '">Add a provider</a>',
                ]) ?>
            <?php else: ?>
                <?php $topRevenue = max(array_map(static fn($p) => (float)$p['revenue_month'], $performance)) ?: 1; ?>
                <table class="table table--stack">
                    <thead>
                        <tr>
                            <th>Provider</th>
                            <th class="text-right">Customers</th>
                            <th class="text-right">Routers</th>
                            <th class="text-right">Live</th>
                            <th class="text-right">Data</th>
                            <th style="min-width:130px">Revenue this month</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($performance as $row): ?>
                        <tr>
                            <td data-label="Provider">
                                <?= cell_primary($row['business_name'], $row['provider_code'],
                                    '<span class="provider-logo">' . e(initials($row['business_name'])) . '</span>',
                                    url('admin/providers/view.php?id=' . (int)$row['id'])) ?>
                            </td>
                            <td data-label="Customers" class="text-right"><?= number_format((int)$row['customers']) ?></td>
                            <td data-label="Routers" class="text-right">
                                <?= (int)$row['routers_online'] ?>/<?= (int)$row['routers'] ?>
                            </td>
                            <td data-label="Live" class="text-right"><?= number_format((int)$row['sessions']) ?></td>
                            <td data-label="Data" class="text-right nowrap"><?= e(format_bytes((float)$row['data_used'])) ?></td>
                            <td data-label="Revenue">
                                <div class="small strong"><?= e(money((float)$row['revenue_month'])) ?></div>
                                <div class="mt-1"><?= meter(percent((float)$row['revenue_month'], $topRevenue, 1), 'success') ?></div>
                            </td>
                            <td data-label="Status"><?= badge($row['status']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </section>

    <section class="card">
        <div class="card__head">
            <h2 class="card__title"><?= icon('money') ?> Platform revenue</h2>
            <span class="small muted">14 days</span>
        </div>
        <div class="card__body">
            <?= chart([
                'type' => 'bar', 'format' => 'money',
                'labels' => array_map(static fn($r) => date('d M', strtotime((string)$r['day'])), $revenueSeries),
                'series' => [['name' => 'Revenue', 'data' => array_map(static fn($r) => (float)$r['revenue'], $revenueSeries)]],
            ], '200px') ?>

            <div class="divider-label">Newest providers</div>
            <?php if (!$recentProviders): ?>
                <p class="small muted mb-0">None yet.</p>
            <?php else: ?>
                <ul class="activity-list">
                    <?php foreach ($recentProviders as $row): ?>
                        <li style="padding-left:0;padding-right:0">
                            <span class="provider-logo" style="width:26px;height:26px;font-size:.6rem"><?= e(initials($row['business_name'])) ?></span>
                            <span class="activity-list__body">
                                <b><a href="<?= e(url('admin/providers/view.php?id=' . (int)$row['id'])) ?>"><?= e($row['business_name']) ?></a></b>
                                <div class="tiny muted"><?= e($row['provider_code']) ?><?= $row['city'] ? ' · ' . e($row['city']) : '' ?></div>
                            </span>
                            <span class="text-right">
                                <?= badge($row['status']) ?>
                                <div class="activity-list__time"><?= e(time_ago($row['created_at'])) ?></div>
                            </span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </section>
</div>

<!-- ================================================ alerts + network -->
<div class="grid grid--2">
    <section class="card">
        <div class="card__head">
            <div>
                <h2 class="card__title"><?= icon('router') ?> Routers needing attention</h2>
                <p class="card__subtitle">Live-mode routers that are not answering</p>
            </div>
            <a class="btn btn--sm" href="<?= e(url('admin/network/routers.php')) ?>">All routers</a>
        </div>
        <div class="card__body">
            <?php if (!$offlineRouters): ?>
                <?= empty_state([
                    'icon' => 'check',
                    'title' => demo_mode() ? 'Nothing to poll' : 'Every live router is answering',
                    'text' => demo_mode()
                        ? 'Demo mode is on, so no router is being contacted. Configure a router in Live mode to see real status here.'
                        : 'All routers set to Live mode responded to their last status poll.',
                ]) ?>
            <?php else: ?>
                <div class="node-list">
                    <?php foreach ($offlineRouters as $router): ?>
                        <div class="node">
                            <span class="node__icon node__icon--<?= e($router['status'] === 'offline' ? 'offline' : 'unknown') ?>">
                                <?= icon('router') ?>
                            </span>
                            <span class="node__text">
                                <b><?= e($router['name']) ?> <?= badge($router['status']) ?></b>
                                <span><?= e($router['provider_name'] ?? 'Unassigned') ?> · <?= e($router['ip_address']) ?></span>
                            </span>
                            <span class="node__metrics">
                                <span><b><?= $router['last_seen_at'] ? e(time_ago($router['last_seen_at'])) : 'Never' ?></b>Seen</span>
                            </span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <section class="card">
        <div class="card__head">
            <h2 class="card__title"><?= icon('alert') ?> Alerts across the platform</h2>
            <a class="btn btn--sm" href="<?= e(url('admin/alerts/index.php')) ?>">All</a>
        </div>
        <div class="card__body--flush">
            <?php if (!$openAlerts): ?>
                <?= empty_state(['icon' => 'check', 'title' => 'Nothing needs attention', 'text' => 'Alerts raised by any provider appear here.']) ?>
            <?php else: ?>
                <?php foreach ($openAlerts as $alert): ?>
                    <div class="alert-row alert-row--<?= e($alert['severity']) ?>">
                        <div class="alert-row__body">
                            <div class="alert-row__title"><?= e($alert['title']) ?></div>
                            <div class="alert-row__text"><?= e(str_limit((string)$alert['message'], 110)) ?></div>
                            <div class="tiny faint mt-1"><?= e(time_ago($alert['created_at'])) ?></div>
                        </div>
                        <?= badge($alert['severity'] === 'critical' ? 'blocked' : $alert['severity'], ucfirst($alert['severity'])) ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </section>
</div>

<section class="card mt-3">
    <div class="card__head">
        <h2 class="card__title"><?= icon('history') ?> Recent platform activity</h2>
        <a class="btn btn--sm" href="<?= e(url('admin/administration/audit-logs.php')) ?>">Audit log</a>
    </div>
    <div class="table-wrap">
        <?php if (!$activity): ?>
            <?= empty_state(['icon' => 'history', 'title' => 'Nothing logged yet', 'text' => 'Actions taken anywhere on the platform appear here.']) ?>
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
