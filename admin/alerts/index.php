<?php
/**
 * WMS - Alerts.
 */

$requiredPermission = 'manage_alerts';
require_once __DIR__ . '/../../includes/auth-check.php';
require_once INCLUDES_PATH . '/components.php';

$alerts = new Alert();

if (is_post()) {
    CSRF::verify();
    $action = post('action');
    $id     = (int)post('id');

    switch ($action) {
        case 'resolve':
            $alerts->resolve($id, Auth::id());
            AuditLog::record('alert_resolve', 'alert', $id, 'Resolved an alert');
            Response::back('success', 'Alert marked as resolved.');
            break;
        case 'reopen':
            $alerts->reopen($id);
            Response::back('info', 'Alert reopened.');
            break;
        case 'read_all':
            $count = $alerts->markAllRead();
            Response::back('info', $count . ' alert(s) marked as read.');
            break;
        case 'delete':
            $alerts->deleteById($id);
            AuditLog::record('alert_delete', 'alert', $id, 'Deleted an alert');
            Response::back('success', 'Alert deleted.');
            break;
        default:
            Response::back('error', 'That action is not supported.');
    }
}

$filters = [
    'q'         => query('q'),
    'severity'  => query('severity'),
    'type'      => query('type'),
    'state'     => query('state', 'open'),
    'date_from' => query('from'),
    'date_to'   => query('to'),
];

$page   = $alerts->search($filters, current_page(), (int)setting('records_per_page', 25));
$counts = $alerts->counts();
$types  = $alerts->types();

/* Mark the alerts on this page as read once they have been seen. */
foreach ($page['rows'] as $row) {
    if (!$row['is_read']) {
        $alerts->markRead((int)$row['id']);
    }
}

$pageTitle    = 'Alerts';
$pageSubtitle = 'Things the system thinks you should look at';
$activeNav    = 'alerts';
$breadcrumbs  = [['label' => 'Alerts']];
require INCLUDES_PATH . '/admin-header.php';
?>

<?= page_head($pageTitle, $pageSubtitle,
    '<form method="post" style="display:inline">' . CSRF::field() . '<input type="hidden" name="action" value="read_all">'
    . '<button class="btn">' . icon('check', 'ico--sm') . ' Mark all read</button></form>'
) ?>

<div class="stat-grid mb-3">
    <?= stat_card(['label' => 'Open', 'value' => number_format($counts['open']), 'icon' => 'bell',
        'tone' => $counts['open'] ? 'warning' : 'success', 'href' => url('admin/alerts/index.php?state=open')]) ?>
    <?= stat_card(['label' => 'Need attention', 'value' => number_format($counts['critical']), 'icon' => 'alert',
        'tone' => $counts['critical'] ? 'danger' : 'neutral', 'href' => url('admin/alerts/index.php?severity=danger')]) ?>
    <?= stat_card(['label' => 'Warnings', 'value' => number_format($counts['warning']), 'icon' => 'info', 'tone' => 'warning']) ?>
    <?= stat_card(['label' => 'Resolved', 'value' => number_format($counts['resolved']), 'icon' => 'check', 'tone' => 'success',
        'href' => url('admin/alerts/index.php?state=resolved')]) ?>
</div>

<section class="card">
    <form class="filter-bar" method="get" action="">
        <?= search_field($filters['q'], 'Search alerts…') ?>
        <?= filter_select('state', $filters['state'], ['open' => 'Open', 'resolved' => 'Resolved', 'unread' => 'Unread'], 'All alerts') ?>
        <?= filter_select('severity', $filters['severity'], WMS_ALERT_SEVERITIES, 'Any severity') ?>
        <?= filter_select('type', $filters['type'], array_combine($types, array_map('label', $types)), 'Any type') ?>
        <?= filter_date('from', $filters['date_from'], 'From') ?>
        <?= filter_date('to', $filters['date_to'], 'To') ?>
        <div class="filter-bar__actions">
            <button class="btn" type="submit"><?= icon('filter', 'ico--sm') ?> Filter</button>
            <a class="btn btn--ghost" href="<?= e(url('admin/alerts/index.php')) ?>">Clear</a>
        </div>
    </form>

    <div class="card__body--flush">
        <?php if (!$page['rows']): ?>
            <?= empty_state([
                'icon' => 'check',
                'title' => $filters['state'] === 'open' ? 'Nothing needs your attention' : 'No alerts match',
                'text' => $filters['state'] === 'open'
                    ? 'Offline routers, failed payments, expiring packages and unusual usage will show up here.'
                    : 'Try a different filter or widen the date range.',
            ]) ?>
        <?php else: ?>
            <?php foreach ($page['rows'] as $alert): ?>
                <div class="alert-row alert-row--<?= e($alert['severity']) ?>" style="<?= $alert['is_resolved'] ? 'opacity:.65' : '' ?>">
                    <div class="alert-row__body">
                        <div class="flex items-center gap-1 flex-wrap">
                            <span class="alert-row__title"><?= e($alert['title']) ?></span>
                            <?= badge($alert['severity'] === 'critical' ? 'blocked' : $alert['severity'], ucfirst($alert['severity'])) ?>
                            <span class="pill"><?= e(label($alert['type'])) ?></span>
                            <?php if (ProviderContext::isGlobalScope()): ?>
                                <!-- Whose alert this is. A platform administrator sees
                                     every tenant's, and several of them are written to
                                     the provider in the second person - without this
                                     there is no telling who "you" is. -->
                                <span class="pill"><?= icon('building', 'ico--sm') ?><?= e($alert['provider_name'] ?? 'Platform') ?></span>
                            <?php endif; ?>
                            <?php if ($alert['is_resolved']): ?>
                                <span class="badge badge--success">Resolved</span>
                            <?php endif; ?>
                        </div>
                        <div class="alert-row__text mt-1"><?= e($alert['message']) ?></div>
                        <div class="tiny faint mt-1">
                            <?= e(format_date($alert['created_at'])) ?> · <?= e(time_ago($alert['created_at'])) ?>
                            <?php if ($alert['is_resolved'] && $alert['resolved_by_name']): ?>
                                · resolved by <?= e($alert['resolved_by_name']) ?> <?= e(time_ago($alert['resolved_at'])) ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="flex gap-1 items-center">
                        <?php if ($alert['source_type'] === 'router' && $alert['source_id']): ?>
                            <a class="btn btn--sm" href="<?= e(url('admin/network/routers.php')) ?>"><?= icon('router', 'ico--sm') ?></a>
                        <?php elseif ($alert['source_type'] === 'payment' && $alert['source_id']): ?>
                            <a class="btn btn--sm" href="<?= e(url('admin/payments/index.php?id=' . (int)$alert['source_id'])) ?>"><?= icon('card', 'ico--sm') ?></a>
                        <?php endif; ?>
                        <?php if ($alert['is_resolved']): ?>
                            <form method="post"><?= CSRF::field() ?>
                                <input type="hidden" name="id" value="<?= (int)$alert['id'] ?>">
                                <button class="btn btn--sm" name="action" value="reopen">Reopen</button>
                            </form>
                        <?php else: ?>
                            <form method="post"><?= CSRF::field() ?>
                                <input type="hidden" name="id" value="<?= (int)$alert['id'] ?>">
                                <button class="btn btn--sm btn--primary" name="action" value="resolve"><?= icon('check', 'ico--sm') ?> Resolve</button>
                            </form>
                        <?php endif; ?>
                        <form method="post" data-confirm="Delete this alert?"><?= CSRF::field() ?>
                            <input type="hidden" name="id" value="<?= (int)$alert['id'] ?>">
                            <button class="btn btn--sm btn--ghost" name="action" value="delete" aria-label="Delete"><?= icon('trash', 'ico--sm') ?></button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <?= pagination($page, 'alerts') ?>
</section>

<?php require INCLUDES_PATH . '/footer.php'; ?>
