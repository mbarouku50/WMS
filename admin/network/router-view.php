<?php
/**
 * WMS - Router detail.
 *
 * Everything on this page comes from one of two places: a column in the WMS
 * database, or a reading this router returned during a sync. Nothing is
 * estimated. Where a value was never retrieved the page says "Unknown"
 * rather than printing a zero that looks like a measurement.
 *
 * The router is resolved through Router::find(), which is tenant scoped, so
 * editing the id in the URL cannot reach another provider's hardware.
 */

$requiredPermission = 'manage_routers';
require_once __DIR__ . '/../../includes/auth-check.php';
require_once INCLUDES_PATH . '/components.php';

$routers = new Router();
$id      = (int)query('id');

// Retired routers stay readable here, so history keeps its context.
$router = $id ? $routers->findWithRetired($id) : null;
if (!$router) {
    Response::redirect('admin/network/routers.php', 'error', 'That router could not be found.');
}

$network = new NetworkService();

if (is_post()) {
    CSRF::verify();
    $action = post('action');

    if ($action === 'sync') {
        $status = $network->pollRouter($router);
        AuditLog::record('SYNC_ROUTER', 'router', $id, 'Synced router "' . $router['name'] . '"');
        Response::back(($status['status'] ?? '') === 'online' ? 'success' : 'warning',
            $router['name'] . ': ' . ($status['message'] ?? 'sync complete.')
            . ' Status is now ' . label($status['status'] ?? 'unknown') . '.');
    }

    if ($action === 'go_live') {
        if (empty($router['api_password'])) {
            Response::back('error', 'Add an API password before switching this router to Live mode.');
        }
        $routers->updateById($id, ['mode' => 'live', 'failed_checks' => 0]);
        AuditLog::record('UPDATE_ROUTER', 'router', $id, 'Switched ' . $router['name'] . ' to Live mode');
        Response::back('success', $router['name'] . ' is now in Live mode. WMS will contact it and push hotspot users to it.');
    }

    if ($action === 'go_demo') {
        $routers->updateById($id, ['mode' => 'demo', 'status' => 'unknown']);
        AuditLog::record('UPDATE_ROUTER', 'router', $id, 'Switched ' . $router['name'] . ' to Demo mode');
        Response::back('warning', $router['name'] . ' is back in Demo mode. It will not be contacted.');
    }

    Response::back('error', 'That action is not supported.');
}

$db          = Database::getInstance();
$accessPoint = new AccessPoint();

$isLive   = $router['mode'] === 'live';
$isSynced = $isLive && !empty($router['last_sync_at']);
$stale    = Router::isStale($router);
$degraded = Router::degradedReason($router);

/* Everything below is a WMS database count, not a router reading. */
$accessPoints  = $routers->accessPoints($id);
$activeSessions = $db->count(
    "SELECT COUNT(*) FROM sessions WHERE router_id = ? AND status = 'active'", [$id]
);
$todayTraffic = $db->fetchOne(
    "SELECT COALESCE(SUM(download_bytes),0) AS down, COALESCE(SUM(upload_bytes),0) AS up, COUNT(*) AS rows_counted
       FROM sessions WHERE router_id = ? AND source = 'live' AND started_at >= ?",
    [$id, date('Y-m-d 00:00:00')]
) ?: ['down' => 0, 'up' => 0, 'rows_counted' => 0];
$hasTraffic = (int)$todayTraffic['rows_counted'] > 0;

$events = $db->fetchAll(
    "SELECT action, description, created_at, actor_name FROM audit_logs
      WHERE entity_type = 'router' AND entity_id = ? ORDER BY created_at DESC LIMIT 15",
    [$id]
);

$ownerName = ProviderContext::isGlobalScope()
    ? $db->fetchColumn('SELECT business_name FROM providers WHERE id = ?', [(int)$router['provider_id']])
    : null;

$pageTitle    = $router['name'];
$pageSubtitle = $router['location'] ?: 'MikroTik gateway';
$activeNav    = 'routers';
$breadcrumbs  = [
    ['label' => 'Network', 'href' => url('admin/network/index.php')],
    ['label' => 'Routers', 'href' => url('admin/network/routers.php')],
    ['label' => $router['name']],
];
require INCLUDES_PATH . '/admin-header.php';
?>

<?= page_head($pageTitle, $pageSubtitle,
    '<a class="btn" href="' . e(url('admin/network/routers.php')) . '">' . icon('chevron', 'ico--sm') . ' All routers</a>'
    . '<a class="btn" href="' . e(url('admin/network/routers.php?edit=' . $id)) . '">' . icon('edit', 'ico--sm') . ' Edit</a>'
    . '<button class="btn btn--primary" data-router-test="' . $id . '" data-result="#router-test-result">' . icon('link', 'ico--sm') . ' Test connection</button>'
) ?>

<div class="flex items-center gap-1 flex-wrap mb-2">
    <?= network_status($router['status'], $degraded ?: null) ?>
    <?= mode_badge($router['mode']) ?>
    <?php if ($stale && $isLive): ?>
        <span class="badge badge--warning"><?= icon('clock', 'ico--sm') ?>Stale data</span>
    <?php endif; ?>
    <?php if ($ownerName): ?><span class="pill"><?= icon('building', 'ico--sm') ?> <?= e((string)$ownerName) ?></span><?php endif; ?>
    <span class="pill mono"><?= e($router['ip_address']) ?>:<?= (int)$router['api_port'] ?><?= !empty($router['use_tls']) ? ' TLS' : '' ?></span>
    <?= freshness_note($router) ?>
</div>

<?php if (!$isLive): ?>
    <?= alert_box('demo',
        'No commands are sent to MikroTik and no real router is contacted. Every figure below that would come from the router is shown as Unknown, because nothing has measured it.',
        'Demo mode') ?>
<?php elseif ($stale): ?>
    <?= alert_box('warning',
        'This router has not synced recently, so the readings below may be out of date. Use "Sync now" to refresh them.',
        'Data may be outdated') ?>
<?php endif; ?>

<?php if ($router['last_error'] && $router['status'] !== 'online'): ?>
    <?= alert_box('danger', $router['last_error'], 'Last connection error') ?>
<?php endif; ?>

<div id="router-test-result"></div>

<div class="tab-set" data-tabs>
    <div class="tabs" role="tablist">
        <button type="button" class="tab is-active" data-tab="overview" role="tab" aria-selected="true">Overview</button>
        <button type="button" class="tab" data-tab="health" role="tab" aria-selected="false">Health</button>
        <button type="button" class="tab" data-tab="hotspot" role="tab" aria-selected="false">Hotspot</button>
        <button type="button" class="tab" data-tab="aps" role="tab" aria-selected="false">Access points (<?= count($accessPoints) ?>)</button>
        <button type="button" class="tab" data-tab="events" role="tab" aria-selected="false">Events</button>
        <button type="button" class="tab" data-tab="settings" role="tab" aria-selected="false">Settings</button>
    </div>

    <!-- ------------------------------------------------------- Overview -->
    <div class="tab-panel is-active" data-panel="overview">
        <div class="stat-grid mb-3">
            <?= stat_card(['label' => 'RouterOS version', 'icon' => 'info', 'tone' => 'neutral',
                'value' => $router['routeros_version'] ?: 'Unknown',
                'meta'  => $router['board'] ?: 'Board unknown']) ?>
            <?= stat_card(['label' => 'Uptime', 'icon' => 'clock', 'tone' => 'neutral',
                'value' => $router['uptime'] ?: 'Unknown']) ?>
            <?= stat_card(['label' => 'Active hotspot users', 'icon' => 'users', 'tone' => 'primary',
                'value' => $isSynced ? number_format((int)$router['active_users']) : 'Unknown',
                'meta'  => $isSynced ? 'Reported by the router' : 'Never read from the router']) ?>
            <?= stat_card(['label' => 'Active sessions', 'icon' => 'activity', 'tone' => 'info',
                'value' => number_format($activeSessions), 'meta' => 'Recorded in WMS',
                'href'  => url('admin/network/sessions.php?router_id=' . $id)]) ?>
        </div>

        <div class="grid grid--2">
            <section class="card">
                <div class="card__head"><h2 class="card__title"><?= icon('router') ?> Identity</h2></div>
                <div class="card__body">
                    <?= key_value([
                        'Router name'      => e($router['name']),
                        'RouterOS identity'=> $router['identity'] ? e($router['identity']) : null,
                        'Location'         => $router['location'] ? e($router['location']) : null,
                        'API address'      => '<span class="mono">' . e($router['ip_address']) . ':' . (int)$router['api_port'] . '</span>',
                        'Connection type'  => !empty($router['use_tls']) ? 'RouterOS API TLS (api-ssl)' : 'RouterOS API (plain)',
                        'API username'     => '<span class="mono">' . e($router['api_username']) . '</span>',
                        'API password'     => '<span class="faint">Stored encrypted - never displayed</span>',
                        'Notes'            => $router['notes'] ? nl2br(e($router['notes'])) : null,
                    ]) ?>
                </div>
            </section>

            <section class="card">
                <div class="card__head"><h2 class="card__title"><?= icon('activity') ?> Traffic today</h2></div>
                <div class="card__body">
                    <?= key_value([
                        'Download' => $hasTraffic ? '<b>' . e(format_bytes((int)$todayTraffic['down'])) . '</b>' : '<span class="faint">No live session data yet</span>',
                        'Upload'   => $hasTraffic ? '<b>' . e(format_bytes((int)$todayTraffic['up'])) . '</b>' : '<span class="faint">No live session data yet</span>',
                        'Last seen'        => $router['last_seen_at'] ? e(time_ago($router['last_seen_at'])) . ' <span class="tiny muted">(' . e(format_date($router['last_seen_at'])) . ')</span>' : '<span class="faint">Never</span>',
                        'Last successful sync' => $router['last_sync_at'] ? e(time_ago($router['last_sync_at'])) : '<span class="faint">Never</span>',
                        'Last connection error' => $router['last_error'] ? '<span style="color:var(--wms-danger)">' . e($router['last_error']) . '</span>' : '<span class="faint">None recorded</span>',
                        'Failed attempts since last success' => (int)$router['failed_checks'],
                    ]) ?>
                    <p class="tiny muted mt-1">
                        Traffic totals are summed from live session records in WMS. They are blank until a real
                        session has reported usage - they are never estimated.
                    </p>
                </div>
            </section>
        </div>
    </div>

    <!-- --------------------------------------------------------- Health -->
    <div class="tab-panel" data-panel="health">
        <section class="card">
            <div class="card__head"><h2 class="card__title"><?= icon('signal') ?> Health</h2></div>
            <div class="card__body">
                <?php if (!$isSynced): ?>
                    <?= empty_state(['icon' => 'info', 'title' => 'No health readings yet',
                        'text' => $isLive
                            ? 'WMS has not completed a sync with this router, so it has no CPU, memory or uptime figures. Nothing is shown rather than something invented.'
                            : 'This router is in Demo mode, so it is never contacted and has no health readings.']) ?>
                <?php else: ?>
                    <div class="grid grid--2">
                        <div>
                            <div class="field__label">CPU load</div>
                            <?php if ($router['cpu_load'] === null): ?>
                                <p class="faint">Not reported by this router.</p>
                            <?php else: ?>
                                <?= meter((float)$router['cpu_load']) ?>
                                <p class="small"><b><?= (int)$router['cpu_load'] ?>%</b>
                                    <?= (int)$router['cpu_load'] >= Router::CPU_DEGRADED_PCT
                                        ? '<span style="color:var(--wms-warning)"> — above the ' . Router::CPU_DEGRADED_PCT . '% degraded threshold</span>' : '' ?>
                                </p>
                            <?php endif; ?>
                        </div>
                        <div>
                            <div class="field__label">Memory used</div>
                            <?php if ($router['memory_used_pct'] === null): ?>
                                <p class="faint">Not reported by this router.</p>
                            <?php else: ?>
                                <?= meter((float)$router['memory_used_pct']) ?>
                                <p class="small"><b><?= (int)$router['memory_used_pct'] ?>%</b>
                                    <?= (int)$router['memory_used_pct'] >= Router::MEMORY_DEGRADED_PCT
                                        ? '<span style="color:var(--wms-warning)"> — above the ' . Router::MEMORY_DEGRADED_PCT . '% degraded threshold</span>' : '' ?>
                                </p>
                            <?php endif; ?>
                        </div>
                    </div>
                    <hr class="divider">
                    <?= key_value([
                        'Status'   => network_status($router['status'], $degraded ?: null),
                        'Uptime'   => $router['uptime'] ? e($router['uptime']) : null,
                        'Board'    => $router['board'] ? e($router['board']) : null,
                        'RouterOS' => $router['routeros_version'] ? e($router['routeros_version']) : null,
                    ]) ?>
                <?php endif; ?>
                <p class="tiny muted">
                    A router is marked <b>Degraded</b> when it answers but reports CPU at or above
                    <?= Router::CPU_DEGRADED_PCT ?>%, memory at or above <?= Router::MEMORY_DEGRADED_PCT ?>%,
                    or replies more slowly than <?= Router::SLOW_RESPONSE_MS ?> ms. It is only marked
                    <b>Offline</b> after <?= Router::FAILURE_THRESHOLD ?> consecutive failed contacts,
                    so a single timeout does not take it down.
                </p>
            </div>
            <div class="card__foot">
                <form method="post">
                    <?= CSRF::field() ?>
                    <button class="btn" name="action" value="sync"><?= icon('refresh', 'ico--sm') ?> Sync now</button>
                </form>
            </div>
        </section>
    </div>

    <!-- -------------------------------------------------------- Hotspot -->
    <div class="tab-panel" data-panel="hotspot">
        <section class="card">
            <div class="card__head"><h2 class="card__title"><?= icon('wifi') ?> Hotspot configuration</h2></div>
            <div class="card__body">
                <?= key_value([
                    'Hotspot server'       => $router['hotspot_server'] ? '<b>' . e($router['hotspot_server']) . '</b>' : '<span class="faint">Not set - WMS will use every server on the router</span>',
                    'Hotspot profile'      => $router['hotspot_profile'] ? e($router['hotspot_profile']) : null,
                    'Default user profile' => $router['default_user_profile'] ? e($router['default_user_profile']) : null,
                ]) ?>
                <p class="tiny muted mt-1">
                    WMS never assumes a hotspot server called "hotspot1" exists. Use
                    <b>Test connection</b> or the readiness check to read the real server names from the router.
                </p>
            </div>
            <div class="card__foot flex gap-1 flex-wrap">
                <a class="btn" href="<?= e(url('admin/network/router-setup.php?id=' . $id)) ?>"><?= icon('clipboard', 'ico--sm') ?> Readiness check</a>
                <button class="btn btn--primary" data-router-test="<?= $id ?>" data-result="#router-test-result"><?= icon('link', 'ico--sm') ?> Test connection</button>
            </div>
        </section>
    </div>

    <!-- -------------------------------------------------- Access points -->
    <div class="tab-panel" data-panel="aps">
        <section class="card">
            <div class="card__head">
                <h2 class="card__title"><?= icon('antenna') ?> Access points on this router</h2>
                <a class="btn btn--sm" href="<?= e(url('admin/network/access-points.php?router_id=' . $id)) ?>">Manage</a>
            </div>
            <div class="table-wrap">
                <?php if (!$accessPoints): ?>
                    <?= empty_state(['icon' => 'antenna', 'title' => 'No access points linked',
                        'text' => 'Add the radios served by this router so WMS can associate customers and network activity with physical locations.',
                        'action' => '<a class="btn btn--primary" href="' . e(url('admin/network/access-points.php?router_id=' . $id)) . '">' . icon('plus', 'ico--sm') . ' Add an access point</a>']) ?>
                <?php else: ?>
                    <table class="table table--stack">
                        <thead><tr>
                            <th>Access point</th><th>SSID</th><th>Management IP</th>
                            <th>Status</th><th>Source</th><th>Last seen</th>
                        </tr></thead>
                        <tbody>
                        <?php foreach ($accessPoints as $ap): ?>
                            <tr>
                                <td data-label="Access point">
                                    <a href="<?= e(url('admin/network/access-point-view.php?id=' . (int)$ap['id'])) ?>"><b><?= e($ap['name']) ?></b></a>
                                    <div class="tiny muted mono"><?= e($ap['mac_address'] ?: 'No MAC recorded') ?></div>
                                </td>
                                <td data-label="SSID"><?= e($ap['ssid'] ?: '—') ?></td>
                                <td data-label="Management IP"><?= code_chip($ap['ip_address']) ?></td>
                                <td data-label="Status"><?= network_status($ap['status']) ?></td>
                                <td data-label="Source"><span class="tiny muted"><?= e(AccessPoint::sourceLabel($ap)) ?></span></td>
                                <td data-label="Last seen" class="nowrap"><?= $ap['last_seen_at'] ? e(time_ago($ap['last_seen_at'])) : '<span class="faint">Never</span>' ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </section>
    </div>

    <!-- --------------------------------------------------------- Events -->
    <div class="tab-panel" data-panel="events">
        <section class="card">
            <div class="card__head"><h2 class="card__title"><?= icon('history') ?> Recent activity</h2></div>
            <div class="table-wrap">
                <?php if (!$events): ?>
                    <?= empty_state(['icon' => 'history', 'title' => 'Nothing recorded yet',
                        'text' => 'Tests, syncs and configuration changes for this router appear here.']) ?>
                <?php else: ?>
                    <table class="table table--stack">
                        <thead><tr><th>When</th><th>Action</th><th>Detail</th><th>By</th></tr></thead>
                        <tbody>
                        <?php foreach ($events as $event): ?>
                            <tr>
                                <td data-label="When" class="nowrap"><?= e(time_ago($event['created_at'])) ?></td>
                                <td data-label="Action"><span class="code-chip"><?= e($event['action']) ?></span></td>
                                <td data-label="Detail"><?= e($event['description']) ?></td>
                                <td data-label="By"><?= e($event['actor_name'] ?: 'System') ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </section>
    </div>

    <!-- ------------------------------------------------------- Settings -->
    <div class="tab-panel" data-panel="settings">
        <section class="card">
            <div class="card__head"><h2 class="card__title"><?= icon('settings') ?> Operating mode</h2></div>
            <div class="card__body">
                <?php if ($isLive): ?>
                    <?= alert_box('warning', 'WMS communicates with this MikroTik router. Network changes made here may affect real customers.', 'Live mode') ?>
                <?php else: ?>
                    <?= alert_box('demo', 'No commands are sent to MikroTik. No real router is contacted. Network values are not presented as real measurements.', 'Demo mode') ?>
                <?php endif; ?>
            </div>
            <div class="card__foot flex gap-1 flex-wrap">
                <?php if ($isLive): ?>
                    <form method="post" data-confirm="Switch <?= e($router['name']) ?> back to Demo mode? WMS will stop contacting it and stop pushing hotspot users to it.">
                        <?= CSRF::field() ?>
                        <button class="btn" name="action" value="go_demo"><?= icon('info', 'ico--sm') ?> Switch to Demo mode</button>
                    </form>
                <?php else: ?>
                    <form method="post" data-confirm="Switch <?= e($router['name']) ?> to Live mode? WMS will contact this router and create real hotspot users on it.">
                        <?= CSRF::field() ?>
                        <button class="btn btn--primary" name="action" value="go_live"><?= icon('signal', 'ico--sm') ?> Switch to Live mode</button>
                    </form>
                <?php endif; ?>
                <a class="btn" href="<?= e(url('admin/network/routers.php?edit=' . $id)) ?>"><?= icon('edit', 'ico--sm') ?> Edit configuration</a>
            </div>
        </section>
    </div>
</div>

<?php require INCLUDES_PATH . '/footer.php'; ?>
