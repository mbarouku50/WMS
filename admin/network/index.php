<?php
/**
 * WMS - Network overview.
 *
 * One page that answers "is the network healthy?" without opening a single
 * connection to MikroTik: every figure is read from the WMS database, which
 * the scheduled sync keeps up to date. That is what stops each browser
 * request from dialling every router.
 *
 * Counts are real counts. Where a value has never been retrieved it is shown
 * as Unknown, not as zero.
 */

$requiredPermission = 'manage_routers';
require_once __DIR__ . '/../../includes/auth-check.php';
require_once INCLUDES_PATH . '/components.php';

$network = new NetworkService();
$routers = new Router();

if (is_post()) {
    CSRF::verify();

    if (post('action') === 'sync_all') {
        /*
         * A manual sweep. Each router is polled in isolation, so one being
         * unreachable neither stops the others nor fails this request.
         */
        $results  = $network->pollAll();
        $online   = count(array_filter($results, static fn($r) => ($r['status'] ?? '') === 'online'));
        $degraded = count(array_filter($results, static fn($r) => ($r['status'] ?? '') === 'degraded'));

        AuditLog::record('SYNC_NETWORK', 'router', null,
            'Synced ' . count($results) . ' router(s) from the network overview');

        Response::back(count($results) && $online ? 'success' : 'warning', sprintf(
            '%d router(s) synced: %d online, %d degraded, %d not answering.',
            count($results), $online, $degraded, count($results) - $online - $degraded
        ));
    }

    Response::back('error', 'That action is not supported.');
}

$overview = $network->networkOverview();
$r        = $overview['routers'];
$a        = $overview['access_points'];

/* The routers that most need attention, newest problem first. */
[$scopeSql, $scopeParams] = $routers->scope('r');
$attention = Database::getInstance()->fetchAll(
    "SELECT r.* FROM routers r
      WHERE r.status IN ('offline','degraded','unknown') AND r.mode = 'live' AND $scopeSql
      ORDER BY FIELD(r.status,'offline','degraded','unknown'), r.name LIMIT 8",
    $scopeParams
);

$pageTitle    = 'Network overview';
$pageSubtitle = 'Router and access point health across your network';
$activeNav    = 'network-overview';
$breadcrumbs  = [['label' => 'Network'], ['label' => 'Overview']];
require INCLUDES_PATH . '/admin-header.php';
?>

<?= page_head($pageTitle, $pageSubtitle,
    '<a class="btn" href="' . e(url('admin/network/routers.php')) . '">' . icon('router', 'ico--sm') . ' Routers</a>'
    . '<a class="btn" href="' . e(url('admin/network/access-points.php')) . '">' . icon('antenna', 'ico--sm') . ' Access points</a>'
    . '<form method="post" style="display:inline">' . CSRF::field()
    . '<button class="btn btn--primary" name="action" value="sync_all">' . icon('refresh', 'ico--sm') . ' Sync now</button></form>'
) ?>

<?= demo_banner('network') ?>

<div class="grid grid--2 mb-3">
    <section class="card">
        <div class="card__head">
            <h2 class="card__title"><?= icon('router') ?> Routers</h2>
            <a class="btn btn--sm" href="<?= e(url('admin/network/routers.php')) ?>">View all</a>
        </div>
        <div class="card__body">
            <div class="grid grid--2">
                <div class="net-tile">
                    <?= network_status('online') ?>
                    <div><div class="net-tile__count"><?= number_format($r['online']) ?></div>
                         <div class="net-tile__label">answering normally</div></div>
                </div>
                <div class="net-tile">
                    <?= network_status('degraded') ?>
                    <div><div class="net-tile__count"><?= number_format($r['degraded']) ?></div>
                         <div class="net-tile__label">reachable but unwell</div></div>
                </div>
                <div class="net-tile">
                    <?= network_status('offline') ?>
                    <div><div class="net-tile__count"><?= number_format($r['offline']) ?></div>
                         <div class="net-tile__label">not answering</div></div>
                </div>
                <div class="net-tile">
                    <?= network_status('unknown') ?>
                    <div><div class="net-tile__count"><?= number_format($r['unknown']) ?></div>
                         <div class="net-tile__label">never contacted</div></div>
                </div>
            </div>
            <p class="tiny muted mt-1">
                <?= number_format($r['live']) ?> in Live mode, <?= number_format($r['demo']) ?> in Demo mode.
                <?php if ($overview['stale_routers'] > 0): ?>
                    <span style="color:var(--wms-warning)">
                        <?= icon('alert', 'ico--sm') ?> <?= (int)$overview['stale_routers'] ?> live router(s) have stale readings.
                    </span>
                <?php endif; ?>
            </p>
        </div>
    </section>

    <section class="card">
        <div class="card__head">
            <h2 class="card__title"><?= icon('antenna') ?> Access points</h2>
            <a class="btn btn--sm" href="<?= e(url('admin/network/access-points.php')) ?>">View all</a>
        </div>
        <div class="card__body">
            <div class="grid grid--2">
                <div class="net-tile">
                    <?= network_status('online') ?>
                    <div><div class="net-tile__count"><?= number_format($a['online']) ?></div>
                         <div class="net-tile__label">observed up</div></div>
                </div>
                <div class="net-tile">
                    <?= network_status('offline') ?>
                    <div><div class="net-tile__count"><?= number_format($a['offline']) ?></div>
                         <div class="net-tile__label">observed down</div></div>
                </div>
                <div class="net-tile">
                    <?= network_status('unknown') ?>
                    <div><div class="net-tile__count"><?= number_format($a['unknown']) ?></div>
                         <div class="net-tile__label">not measured</div></div>
                </div>
                <div class="net-tile">
                    <span class="badge badge--info"><?= icon('signal', 'ico--sm') ?>Monitored</span>
                    <div><div class="net-tile__count"><?= number_format($a['monitored']) ?></div>
                         <div class="net-tile__label">have a monitoring source</div></div>
                </div>
            </div>
            <p class="tiny muted mt-1">
                An access point is a separate device from its router. WMS reports it as Online or Offline only
                when a monitoring source has actually observed it - a reachable router is not evidence about
                the radios behind it.
            </p>
        </div>
    </section>
</div>

<div class="stat-grid mb-3">
    <?= stat_card(['label' => 'Active sessions', 'icon' => 'activity', 'tone' => 'primary',
        'value' => number_format($overview['active_sessions']),
        'meta'  => number_format($overview['live_sessions']) . ' from live routers']) ?>
    <?= stat_card(['label' => 'Download today', 'icon' => 'download', 'tone' => 'info',
        'value' => $overview['live_download'] === null ? 'Unknown' : format_bytes($overview['live_download']),
        'meta'  => $overview['live_download'] === null ? 'No live session has reported usage' : 'From live sessions']) ?>
    <?= stat_card(['label' => 'Upload today', 'icon' => 'upload', 'tone' => 'info',
        'value' => $overview['live_upload'] === null ? 'Unknown' : format_bytes($overview['live_upload']),
        'meta'  => $overview['live_upload'] === null ? 'No live session has reported usage' : 'From live sessions']) ?>
    <?= stat_card(['label' => 'Network alerts', 'icon' => 'bell', 'tone' => $overview['alerts'] > 0 ? 'warning' : 'neutral',
        'value' => number_format($overview['alerts']), 'meta' => 'Unread',
        'href'  => url('admin/alerts/index.php')]) ?>
</div>

<section class="card">
    <div class="card__head">
        <h2 class="card__title"><?= icon('alert') ?> Routers needing attention</h2>
        <span class="tiny muted">Polled every <?= (int)$overview['poll_seconds'] ?> seconds by the scheduler</span>
    </div>
    <div class="table-wrap">
        <?php if (!$attention): ?>
            <?= empty_state(['icon' => 'check', 'title' => 'Every live router is answering',
                'text' => $r['live'] > 0
                    ? 'Nothing needs attention right now.'
                    : 'No router is in Live mode yet, so there is nothing to contact. Add a router and switch it to Live mode when its credentials are ready.',
                'action' => $r['live'] > 0 ? '' : '<a class="btn btn--primary" href="' . e(url('admin/network/routers.php')) . '">' . icon('router', 'ico--sm') . ' Go to routers</a>']) ?>
        <?php else: ?>
            <table class="table table--stack">
                <thead><tr>
                    <th>Router</th><th>Location</th><th>Status</th>
                    <th>Last seen</th><th>Reason</th><th class="table__actions">Actions</th>
                </tr></thead>
                <tbody>
                <?php foreach ($attention as $router): ?>
                    <tr>
                        <td data-label="Router">
                            <a href="<?= e(url('admin/network/router-view.php?id=' . (int)$router['id'])) ?>"><b><?= e($router['name']) ?></b></a>
                            <div class="tiny muted mono"><?= e($router['ip_address']) ?>:<?= (int)$router['api_port'] ?></div>
                        </td>
                        <td data-label="Location"><?= e($router['location'] ?: '—') ?></td>
                        <td data-label="Status"><?= network_status($router['status'], Router::degradedReason($router) ?: null) ?></td>
                        <td data-label="Last seen" class="nowrap">
                            <?= $router['last_seen_at'] ? e(time_ago($router['last_seen_at'])) : '<span class="faint">Never</span>' ?>
                        </td>
                        <td data-label="Reason">
                            <?php if ($router['status'] === 'degraded' && Router::degradedReason($router)): ?>
                                <?= e(Router::degradedReason($router)) ?>
                            <?php elseif ($router['last_error']): ?>
                                <span class="tiny"><?= e(str_limit($router['last_error'], 70)) ?></span>
                            <?php else: ?>
                                <span class="faint">Not contacted yet</span>
                            <?php endif; ?>
                        </td>
                        <td class="table__actions" data-label="Actions">
                            <button class="btn btn--sm" data-router-test="<?= (int)$router['id'] ?>" data-result="#router-test-result">
                                <?= icon('link', 'ico--sm') ?> Test
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</section>

<div id="router-test-result" class="mt-2"></div>

<?php require INCLUDES_PATH . '/footer.php'; ?>
