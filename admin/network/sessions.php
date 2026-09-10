<?php
/**
 * WMS - Session manager.
 */

$requiredPermission = 'manage_sessions';
require_once __DIR__ . '/../../includes/auth-check.php';
require_once INCLUDES_PATH . '/components.php';

$sessions = new SessionModel();
$service  = new SessionService();

if (is_post()) {
    CSRF::verify();
    $id     = (int)post('id');
    $action = post('action');

    if ($action === 'disconnect') {
        $result = $service->disconnect($id, 'Disconnected by ' . (Auth::user()['full_name'] ?? 'staff'));
        Response::back($result['ok'] ? 'success' : 'error', $result['message']);
    }
    if ($action === 'block') {
        $session = $sessions->find($id);
        if ($session && $session['device_id']) {
            $result = $service->blockDevice((int)$session['device_id']);
            Response::back($result['ok'] ? 'success' : 'error', $result['message']);
        }
        Response::back('error', 'That session has no device attached to block.');
    }
    if ($action === 'close_stale') {
        $closed = $service->closeStaleSessions();
        Response::back('info', $closed . ' expired session(s) closed.');
    }
    Response::back('error', 'That action is not supported.');
}

$filters = [
    'q'           => query('q'),
    'status'      => query('status'),
    'source'      => query('source'),
    'router_id'   => query('router_id'),
    'customer_id' => query('customer_id'),
    'date_from'   => query('from'),
    'date_to'     => query('to'),
];

$page    = $sessions->search($filters, current_page(), (int)setting('records_per_page', 25));
$routers = (new Router())->listAll();
$traffic = $sessions->trafficTotals(date('Y-m-d 00:00:00'), date('Y-m-d 23:59:59'));
$activeCount = $sessions->activeCount();
$hourly  = $sessions->hourlySeries();

$pageTitle    = 'Sessions';
$pageSubtitle = 'Who is connected, on what, and how much they are using';
$activeNav    = 'sessions';
$breadcrumbs  = [['label' => 'Network'], ['label' => 'Sessions']];
require INCLUDES_PATH . '/admin-header.php';
?>

<?= page_head($pageTitle, $pageSubtitle,
    '<form method="post" style="display:inline">' . CSRF::field() . '<input type="hidden" name="action" value="close_stale">'
    . '<button class="btn">' . icon('refresh', 'ico--sm') . ' Close expired</button></form>'
    . '<a class="btn" href="' . e(url('admin/network/devices.php')) . '">' . icon('device', 'ico--sm') . ' Devices</a>'
) ?>

<?= demo_banner('session') ?>

<div class="stat-grid mb-3">
    <?= stat_card(['label' => 'Active now', 'value' => number_format($activeCount), 'icon' => 'activity', 'tone' => 'success', 'live' => 'active_sessions']) ?>
    <?= stat_card(['label' => 'Sessions today', 'value' => number_format($traffic['sessions']), 'icon' => 'clock', 'tone' => 'info']) ?>
    <?= stat_card(['label' => 'Downloaded today', 'value' => format_bytes($traffic['download']), 'icon' => 'download', 'tone' => 'primary']) ?>
    <?= stat_card(['label' => 'Uploaded today', 'value' => format_bytes($traffic['upload']), 'icon' => 'upload', 'tone' => 'neutral']) ?>
</div>

<section class="card mb-3">
    <div class="card__head">
        <h2 class="card__title"><?= icon('chart') ?> Sessions started, last 24 hours</h2>
    </div>
    <div class="card__body">
        <?= chart([
            'type'   => 'area',
            'fill'   => true,
            'labels' => array_column($hourly, 'hour'),
            'series' => [['name' => 'Sessions', 'data' => array_map(static fn($r) => (int)$r['sessions'], $hourly)]],
        ], '180px') ?>
    </div>
</section>

<section class="card">
    <form class="filter-bar" method="get" action="">
        <?= search_field($filters['q'], 'Search voucher, MAC, IP or customer…') ?>
        <?= filter_select('status', $filters['status'], WMS_SESSION_STATUSES, 'Any status') ?>
        <?= filter_select('source', $filters['source'], ['live' => 'Live', 'demo' => 'Demo'], 'Any source') ?>
        <?= filter_select('router_id', $filters['router_id'], array_column($routers, 'name', 'id'), 'Any router') ?>
        <?= filter_date('from', $filters['date_from'], 'From') ?>
        <?= filter_date('to', $filters['date_to'], 'To') ?>
        <div class="filter-bar__actions">
            <button class="btn" type="submit"><?= icon('filter', 'ico--sm') ?> Filter</button>
            <a class="btn btn--ghost" href="<?= e(url('admin/network/sessions.php')) ?>">Clear</a>
        </div>
    </form>

    <div class="table-wrap">
        <?php if (!$page['rows']): ?>
            <?= empty_state([
                'icon' => 'activity', 'title' => 'No sessions match',
                'text' => 'Sessions are recorded when a customer activates a voucher or signs in through the portal.',
            ]) ?>
        <?php else: ?>
            <table class="table table--stack">
                <thead>
                    <tr>
                        <th>Customer</th>
                        <th>Voucher / user</th>
                        <th>Device</th>
                        <th>Network</th>
                        <th>Connected</th>
                        <th class="text-right">Usage</th>
                        <th>Status</th>
                        <th class="table__actions">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($page['rows'] as $session): ?>
                    <?php $live = $session['status'] === 'active'; ?>
                    <tr>
                        <td data-label="Customer">
                            <?= cell_primary(
                                $session['customer_name'] ?? 'Hotspot guest',
                                $session['customer_code'] ?? '',
                                avatar($session['customer_name'] ?? 'G'),
                                $session['customer_id'] ? url('admin/customers/view.php?id=' . (int)$session['customer_id']) : ''
                            ) ?>
                        </td>
                        <td data-label="Voucher"><?= $session['voucher_code'] ? code_chip($session['voucher_code']) : e($session['username'] ?: '—') ?></td>
                        <td data-label="Device">
                            <?= e($session['device_name'] ?? '—') ?>
                            <div class="tiny faint mono"><?= e($session['mac_address'] ?? '') ?></div>
                        </td>
                        <td data-label="Network">
                            <span class="small"><?= e($session['router_name'] ?? '—') ?></span>
                            <div class="tiny muted"><?= e($session['ap_name'] ?? '') ?><?= $session['ip_address'] ? ' · ' . e($session['ip_address']) : '' ?></div>
                        </td>
                        <td data-label="Connected" class="nowrap">
                            <?= e(format_date($session['started_at'], 'd M H:i')) ?>
                            <div class="tiny muted">
                                <?php if ($live): ?>
                                    <span data-since="<?= e($session['started_at']) ?>"><?= e(format_duration(time() - strtotime((string)$session['started_at']))) ?></span> online
                                <?php else: ?>
                                    <?= e(format_duration((int)$session['duration_seconds'])) ?>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td data-label="Usage" class="text-right nowrap">
                            <b><?= e(format_bytes((float)$session['download_bytes'] + (float)$session['upload_bytes'])) ?></b>
                            <div class="tiny muted">↓<?= e(format_bytes((float)$session['download_bytes'])) ?> ↑<?= e(format_bytes((float)$session['upload_bytes'])) ?></div>
                        </td>
                        <td data-label="Status"><?= badge($session['status']) ?> <?= source_badge($session['source']) ?></td>
                        <td class="table__actions" data-label="Actions">
                            <?php if ($live): ?>
                                <form method="post" style="display:inline" data-confirm="Disconnect this session? The customer will need to reconnect.">
                                    <?= CSRF::field() ?>
                                    <input type="hidden" name="id" value="<?= (int)$session['id'] ?>">
                                    <button class="btn btn--sm" name="action" value="disconnect"><?= icon('power', 'ico--sm') ?> Disconnect</button>
                                </form>
                                <form method="post" style="display:inline" data-confirm="Block this device? It will be disconnected and stopped from reconnecting.">
                                    <?= CSRF::field() ?>
                                    <input type="hidden" name="id" value="<?= (int)$session['id'] ?>">
                                    <button class="btn btn--sm btn--danger" name="action" value="block"><?= icon('block', 'ico--sm') ?></button>
                                </form>
                            <?php else: ?>
                                <span class="faint tiny"><?= e($session['terminate_cause'] ?: 'Closed') ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <?= pagination($page, 'sessions') ?>
</section>

<?php require INCLUDES_PATH . '/footer.php'; ?>
