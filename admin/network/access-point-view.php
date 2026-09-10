<?php
/**
 * WMS - Access point detail.
 *
 * Shows what WMS knows about one radio, and is explicit about where each
 * figure came from. Client counts and traffic appear only when a monitoring
 * source genuinely supplied them; otherwise the page says so rather than
 * printing a zero.
 *
 * Resolved through AccessPoint::findDetailed(), which is tenant scoped, so
 * another provider's id cannot be reached by editing the URL.
 */

$requiredPermission = 'manage_routers';
require_once __DIR__ . '/../../includes/auth-check.php';
require_once INCLUDES_PATH . '/components.php';

$accessPoints = new AccessPoint();
$id           = (int)query('id');
$ap           = $id ? $accessPoints->findDetailed($id) : null;

if (!$ap) {
    Response::redirect('admin/network/access-points.php', 'error', 'That access point could not be found.');
}

if (is_post()) {
    CSRF::verify();

    if (post('action') === 'retire') {
        $accessPoints->retire($id);
        AuditLog::record('RETIRE_ACCESS_POINT', 'access_point', $id,
            'Retired access point "' . $ap['name'] . '" - history preserved');
        Response::redirect('admin/network/access-points.php', 'success',
            'Access point retired. Its session and device history is kept.');
    }

    Response::back('error', 'That action is not supported.');
}

$db       = Database::getInstance();
$measured = AccessPoint::isMeasured($ap);

/* Session and device figures are WMS records, not radio telemetry. */
$activeSessions = $db->count(
    "SELECT COUNT(*) FROM sessions WHERE access_point_id = ? AND status = 'active'", [$id]
);
$sessionsToday = $db->count(
    "SELECT COUNT(*) FROM sessions WHERE access_point_id = ? AND started_at >= ?",
    [$id, date('Y-m-d 00:00:00')]
);
$traffic = $db->fetchOne(
    "SELECT COALESCE(SUM(download_bytes),0) AS down, COALESCE(SUM(upload_bytes),0) AS up, COUNT(*) AS rows_counted
       FROM sessions WHERE access_point_id = ? AND source = 'live' AND started_at >= ?",
    [$id, date('Y-m-d 00:00:00')]
) ?: ['down' => 0, 'up' => 0, 'rows_counted' => 0];
$hasTraffic = (int)$traffic['rows_counted'] > 0;

$recentSessions = $db->fetchAll(
    "SELECT s.username, s.mac_address, s.ip_address, s.started_at, s.ended_at, s.status, s.source,
            s.download_bytes, s.upload_bytes, c.full_name
       FROM sessions s
       LEFT JOIN customers c ON c.id = s.customer_id
      WHERE s.access_point_id = ?
      ORDER BY s.started_at DESC LIMIT 15",
    [$id]
);

$events = $db->fetchAll(
    "SELECT action, description, created_at, actor_name FROM audit_logs
      WHERE entity_type = 'access_point' AND entity_id = ? ORDER BY created_at DESC LIMIT 15",
    [$id]
);

$pageTitle    = $ap['name'];
$pageSubtitle = $ap['location'] ?: 'Wi-Fi access point';
$activeNav    = 'access-points';
$breadcrumbs  = [
    ['label' => 'Network', 'href' => url('admin/network/index.php')],
    ['label' => 'Access points', 'href' => url('admin/network/access-points.php')],
    ['label' => $ap['name']],
];
require INCLUDES_PATH . '/admin-header.php';
?>

<?= page_head($pageTitle, $pageSubtitle,
    '<a class="btn" href="' . e(url('admin/network/access-points.php')) . '">' . icon('chevron', 'ico--sm') . ' All access points</a>'
    . '<a class="btn btn--primary" href="' . e(url('admin/network/access-points.php?edit=' . $id)) . '">' . icon('edit', 'ico--sm') . ' Edit</a>'
) ?>

<div class="flex items-center gap-1 flex-wrap mb-2">
    <?= network_status($ap['status']) ?>
    <span class="pill"><?= icon('signal', 'ico--sm') ?> <?= e(AccessPoint::sourceLabel($ap)) ?></span>
    <?php if ($ap['ssid']): ?><span class="pill"><?= icon('wifi', 'ico--sm') ?> <?= e($ap['ssid']) ?></span><?php endif; ?>
    <?php if (!empty($ap['provider_name']) && ProviderContext::isGlobalScope()): ?>
        <span class="pill"><?= icon('building', 'ico--sm') ?> <?= e($ap['provider_name']) ?></span>
    <?php endif; ?>
    <span class="tiny muted">
        <?= $ap['last_seen_at'] ? 'Last seen ' . e(time_ago($ap['last_seen_at'])) : 'Never seen by a monitoring source' ?>
    </span>
</div>

<?php if (!$measured): ?>
    <?= alert_box('info',
        'Nothing is currently monitoring this access point, so its status is reported as Unknown rather than guessed. '
        . 'Link it to a live MikroTik router and give it a MAC address or management IP, and WMS will read its state from there.',
        'Not monitored') ?>
<?php elseif ($ap['status'] === 'offline'): ?>
    <?= alert_box('warning',
        'The monitoring source last reported this access point as not responding'
        . ($ap['last_status_change_at'] ? ', ' . time_ago($ap['last_status_change_at']) : '') . '.',
        'Access point offline') ?>
<?php endif; ?>

<div class="tab-set" data-tabs>
    <div class="tabs" role="tablist">
        <button type="button" class="tab is-active" data-tab="overview" role="tab" aria-selected="true">Overview</button>
        <button type="button" class="tab" data-tab="clients" role="tab" aria-selected="false">Clients</button>
        <button type="button" class="tab" data-tab="traffic" role="tab" aria-selected="false">Traffic</button>
        <button type="button" class="tab" data-tab="history" role="tab" aria-selected="false">History</button>
        <button type="button" class="tab" data-tab="events" role="tab" aria-selected="false">Events</button>
        <button type="button" class="tab" data-tab="config" role="tab" aria-selected="false">Configuration</button>
    </div>

    <!-- ------------------------------------------------------- Overview -->
    <div class="tab-panel is-active" data-panel="overview">
        <div class="stat-grid mb-3">
            <?= stat_card(['label' => 'Status', 'icon' => 'antenna', 'value' => label($ap['status']),
                'tone' => status_tone($ap['status']), 'meta' => AccessPoint::sourceLabel($ap)]) ?>
            <?= stat_card(['label' => 'Connected clients', 'icon' => 'users', 'tone' => 'primary',
                'value' => ($measured && $ap['status'] === 'online') ? number_format((int)$ap['connected_users']) : 'Unknown',
                'meta'  => $measured ? 'From the monitoring source' : 'Not measured']) ?>
            <?= stat_card(['label' => 'Active sessions', 'icon' => 'activity', 'tone' => 'info',
                'value' => number_format($activeSessions), 'meta' => 'Recorded in WMS']) ?>
            <?= stat_card(['label' => 'Sessions today', 'icon' => 'chart', 'tone' => 'neutral',
                'value' => number_format($sessionsToday), 'meta' => 'Recorded in WMS']) ?>
        </div>

        <section class="card">
            <div class="card__head"><h2 class="card__title"><?= icon('antenna') ?> Access point</h2></div>
            <div class="card__body">
                <?= key_value([
                    'Name'           => e($ap['name']),
                    'Location'       => $ap['location'] ? e($ap['location']) : null,
                    'Model'          => $ap['model'] ? e($ap['model']) : null,
                    'SSID'           => $ap['ssid'] ? e($ap['ssid']) : null,
                    'MAC address'    => $ap['mac_address'] ? '<span class="mono">' . e($ap['mac_address']) . '</span>' : null,
                    'Management IP'  => $ap['ip_address'] ? '<span class="mono">' . e($ap['ip_address']) . '</span>' : null,
                    'Parent router'  => $ap['router_id']
                        ? '<a href="' . e(url('admin/network/router-view.php?id=' . (int)$ap['router_id'])) . '">' . e((string)$ap['router_name']) . '</a>'
                          . ' <span class="tiny muted">(' . e((string)$ap['router_address']) . ', ' . e((string)$ap['router_mode']) . ' mode)</span>'
                        : null,
                    'Provider'       => !empty($ap['provider_name']) ? e($ap['provider_name']) : null,
                    'Status'         => network_status($ap['status']),
                    'Last seen'      => $ap['last_seen_at']
                        ? e(time_ago($ap['last_seen_at'])) . ' <span class="tiny muted">(' . e(format_date($ap['last_seen_at'])) . ')</span>'
                        : '<span class="faint">Never</span>',
                    'Status changed' => $ap['last_status_change_at'] ? e(time_ago($ap['last_status_change_at'])) : null,
                    'Notes'          => $ap['notes'] ? nl2br(e($ap['notes'])) : null,
                ]) ?>
            </div>
        </section>
    </div>

    <!-- -------------------------------------------------------- Clients -->
    <div class="tab-panel" data-panel="clients">
        <section class="card">
            <div class="card__head"><h2 class="card__title"><?= icon('users') ?> Connected clients</h2></div>
            <div class="card__body">
                <?php if (!$measured): ?>
                    <?= empty_state(['icon' => 'info', 'title' => 'No client information',
                        'text' => 'Nothing is monitoring this access point, so WMS has no client count for it. It is left blank rather than shown as zero.']) ?>
                <?php elseif ($ap['status'] !== 'online'): ?>
                    <?= empty_state(['icon' => 'info', 'title' => 'Access point is not online',
                        'text' => 'Client counts are only meaningful while the radio is up.']) ?>
                <?php else: ?>
                    <p class="small">
                        The monitoring source reports
                        <b><?= number_format((int)$ap['connected_users']) ?></b>
                        client(s) associated with this radio, as of <?= e(time_ago($ap['last_seen_at'] ?: 'now')) ?>.
                    </p>
                    <p class="tiny muted">
                        Per-client detail is only available when the access point is managed by CAPsMAN or a
                        vendor controller. Sessions carried through this access point are listed under History.
                    </p>
                <?php endif; ?>
            </div>
        </section>
    </div>

    <!-- -------------------------------------------------------- Traffic -->
    <div class="tab-panel" data-panel="traffic">
        <section class="card">
            <div class="card__head"><h2 class="card__title"><?= icon('activity') ?> Traffic today</h2></div>
            <div class="card__body">
                <?php if (!$hasTraffic): ?>
                    <?= empty_state(['icon' => 'activity', 'title' => 'No traffic recorded today',
                        'text' => 'These totals are summed from live session records attributed to this access point. Nothing is estimated, so they stay blank until a real session reports usage.']) ?>
                <?php else: ?>
                    <?= key_value([
                        'Download' => '<b>' . e(format_bytes((int)$traffic['down'])) . '</b>',
                        'Upload'   => '<b>' . e(format_bytes((int)$traffic['up'])) . '</b>',
                        'Sessions counted' => number_format((int)$traffic['rows_counted']),
                    ]) ?>
                    <p class="tiny muted mt-1">Summed from live session records in WMS, not read from the radio.</p>
                <?php endif; ?>
            </div>
        </section>
    </div>

    <!-- -------------------------------------------------------- History -->
    <div class="tab-panel" data-panel="history">
        <section class="card">
            <div class="card__head"><h2 class="card__title"><?= icon('history') ?> Recent sessions through this access point</h2></div>
            <div class="table-wrap">
                <?php if (!$recentSessions): ?>
                    <?= empty_state(['icon' => 'activity', 'title' => 'No sessions recorded',
                        'text' => 'Sessions attributed to this access point will be listed here.']) ?>
                <?php else: ?>
                    <table class="table table--stack">
                        <thead><tr>
                            <th>Customer</th><th>Address</th><th>Started</th>
                            <th class="text-right">Usage</th><th>Status</th>
                        </tr></thead>
                        <tbody>
                        <?php foreach ($recentSessions as $session): ?>
                            <tr>
                                <td data-label="Customer">
                                    <b><?= e($session['full_name'] ?: $session['username'] ?: 'Unknown') ?></b>
                                    <div class="tiny faint mono"><?= e($session['mac_address'] ?: '') ?></div>
                                </td>
                                <td data-label="Address"><?= code_chip($session['ip_address']) ?></td>
                                <td data-label="Started" class="nowrap"><?= e(time_ago($session['started_at'])) ?></td>
                                <td data-label="Usage" class="text-right">
                                    <?= e(format_bytes((int)$session['download_bytes'] + (int)$session['upload_bytes'])) ?>
                                </td>
                                <td data-label="Status">
                                    <?= badge($session['status']) ?> <?= source_badge($session['source']) ?>
                                </td>
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
                        'text' => 'Configuration changes for this access point appear here.']) ?>
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

    <!-- -------------------------------------------------- Configuration -->
    <div class="tab-panel" data-panel="config">
        <section class="card">
            <div class="card__head"><h2 class="card__title"><?= icon('settings') ?> Configuration</h2></div>
            <div class="card__body">
                <?= key_value([
                    'Monitoring source' => e(AccessPoint::sourceLabel($ap))
                        . '<div class="tiny muted">' . ($measured
                            ? 'Status is measured, not entered by hand.'
                            : 'Nothing measures this access point yet, so its status stays Unknown.') . '</div>',
                    'Parent router'     => $ap['router_id'] ? e((string)$ap['router_name']) : '<span class="faint">Not linked</span>',
                    'Management IP'     => $ap['ip_address'] ? '<span class="mono">' . e($ap['ip_address']) . '</span>' : null,
                    'MAC address'       => $ap['mac_address'] ? '<span class="mono">' . e($ap['mac_address']) . '</span>' : null,
                ]) ?>
                <p class="tiny muted mt-1">
                    An access point does not authenticate anybody. Hotspot login, bandwidth limits and
                    disconnections are all enforced by the MikroTik router in front of it.
                </p>
            </div>
            <div class="card__foot flex gap-1 flex-wrap">
                <a class="btn" href="<?= e(url('admin/network/access-points.php?edit=' . $id)) ?>"><?= icon('edit', 'ico--sm') ?> Edit</a>
                <form method="post" data-confirm="Retire access point <?= e($ap['name']) ?>? It is removed from your list but its session and device history is kept.">
                    <?= CSRF::field() ?>
                    <button class="btn btn--danger" name="action" value="retire"><?= icon('history', 'ico--sm') ?> Retire access point</button>
                </form>
            </div>
        </section>
    </div>
</div>

<?php require INCLUDES_PATH . '/footer.php'; ?>
