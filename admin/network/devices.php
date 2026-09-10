<?php
/**
 * WMS - Device management.
 */

$requiredPermission = 'manage_devices';
require_once __DIR__ . '/../../includes/auth-check.php';
require_once INCLUDES_PATH . '/components.php';

$devices = new Device();
$service = new SessionService();

if (is_post()) {
    CSRF::verify();
    $id     = (int)post('id');
    $action = post('action');
    $device = $devices->find($id);

    if (!$device) {
        Response::back('error', 'That device no longer exists.');
    }

    switch ($action) {
        case 'block':
            $result = $service->blockDevice($id);
            Response::back($result['ok'] ? 'success' : 'error', $result['message']);
            break;
        case 'unblock':
            $result = $service->unblockDevice($id);
            Response::back($result['ok'] ? 'success' : 'error', $result['message']);
            break;
        case 'rename':
            $name = Validator::string(post('name'), 120);
            if ($name === '') {
                Response::back('error', 'Give the device a name.');
            }
            $devices->updateById($id, ['name' => $name, 'device_type' => post('device_type', $device['device_type'])]);
            AuditLog::record('device_rename', 'device', $id, 'Renamed device to "' . $name . '"');
            Response::back('success', 'Device renamed.');
            break;
        case 'disconnect':
            $closed = 0;
            foreach (Database::getInstance()->fetchAll("SELECT id FROM sessions WHERE device_id = ? AND status = 'active'", [$id]) as $row) {
                $service->disconnect((int)$row['id'], 'Disconnected from the device list');
                $closed++;
            }
            Response::back($closed ? 'success' : 'info', $closed ? 'Device disconnected.' : 'That device had no open session.');
            break;
        default:
            Response::back('error', 'That action is not supported.');
    }
}

$filters = [
    'q'           => query('q'),
    'status'      => query('status'),
    'device_type' => query('type'),
    'router_id'   => query('router_id'),
    'customer_id' => query('customer_id'),
    'source'      => query('source'),
];

$page    = $devices->search($filters, current_page(), (int)setting('records_per_page', 25));
$stats   = $devices->stats();
$routers = (new Router())->listAll();
$types   = $devices->typeBreakdown();

$pageTitle    = 'Devices';
$pageSubtitle = 'Every device that has ever connected, and what it is allowed to do';
$activeNav    = 'devices';
$breadcrumbs  = [['label' => 'Network'], ['label' => 'Devices']];
require INCLUDES_PATH . '/admin-header.php';
?>

<?= page_head($pageTitle, $pageSubtitle,
    '<a class="btn" href="' . e(url('admin/network/sessions.php')) . '">' . icon('activity', 'ico--sm') . ' Sessions</a>'
) ?>

<div class="grid grid--3-2 mb-3">
    <div class="stat-grid">
        <?= stat_card(['label' => 'Known devices', 'value' => number_format($stats['total']), 'icon' => 'device', 'tone' => 'primary']) ?>
        <?= stat_card(['label' => 'Online now', 'value' => number_format($stats['online']), 'icon' => 'wifi', 'tone' => 'success', 'live' => 'devices_online']) ?>
        <?= stat_card(['label' => 'Blocked', 'value' => number_format($stats['blocked']), 'icon' => 'block', 'tone' => 'danger']) ?>
    </div>
    <section class="card">
        <div class="card__head"><h2 class="card__title"><?= icon('chart') ?> Device mix</h2></div>
        <div class="card__body">
            <?= chart([
                'type' => 'donut',
                'labels' => array_map(static fn($t) => label($t['device_type']), $types),
                'series' => [['name' => 'Devices', 'data' => array_map(static fn($t) => (int)$t['total'], $types)]],
                'centreLabel' => 'Devices',
            ], '160px') ?>
        </div>
    </section>
</div>

<section class="card">
    <form class="filter-bar" method="get" action="">
        <?= search_field($filters['q'], 'Search name, MAC, IP or customer…') ?>
        <?= filter_select('status', $filters['status'], ['active' => 'Active', 'idle' => 'Idle', 'blocked' => 'Blocked'], 'Any status') ?>
        <?= filter_select('type', $filters['device_type'], WMS_DEVICE_TYPES, 'Any type') ?>
        <?= filter_select('router_id', $filters['router_id'], array_column($routers, 'name', 'id'), 'Any router') ?>
        <div class="filter-bar__actions">
            <button class="btn" type="submit"><?= icon('filter', 'ico--sm') ?> Filter</button>
            <a class="btn btn--ghost" href="<?= e(url('admin/network/devices.php')) ?>">Clear</a>
        </div>
    </form>

    <div class="table-wrap">
        <?php if (!$page['rows']): ?>
            <?= empty_state([
                'icon' => 'device', 'title' => 'No devices yet',
                'text' => 'Devices register themselves the first time a customer connects through the portal.',
            ]) ?>
        <?php else: ?>
            <table class="table table--stack">
                <thead>
                    <tr>
                        <th>Device</th>
                        <th>Owner</th>
                        <th>MAC / IP</th>
                        <th>Location</th>
                        <th>First seen</th>
                        <th>Last seen</th>
                        <th>Status</th>
                        <th class="table__actions">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($page['rows'] as $device): ?>
                    <tr>
                        <td data-label="Device">
                            <b><?= e($device['name'] ?: 'Unnamed device') ?></b>
                            <div class="tiny muted"><?= e(label($device['device_type'])) ?></div>
                        </td>
                        <td data-label="Owner">
                            <?php if ($device['customer_id']): ?>
                                <a href="<?= e(url('admin/customers/view.php?id=' . (int)$device['customer_id'])) ?>"><?= e($device['customer_name']) ?></a>
                                <div class="tiny muted"><?= e($device['customer_code'] ?? '') ?></div>
                            <?php else: ?>
                                <span class="faint">Unassigned</span>
                            <?php endif; ?>
                        </td>
                        <td data-label="MAC / IP">
                            <?= code_chip($device['mac_address']) ?>
                            <div class="tiny faint mono"><?= e($device['ip_address'] ?? '') ?></div>
                        </td>
                        <td data-label="Location">
                            <span class="small"><?= e($device['router_name'] ?? '—') ?></span>
                            <div class="tiny muted"><?= e($device['ap_name'] ?? '') ?></div>
                        </td>
                        <td data-label="First seen" class="nowrap"><?= e(format_date($device['first_seen_at'], 'd M Y')) ?></td>
                        <td data-label="Last seen" class="nowrap"><?= e(time_ago($device['last_seen_at'])) ?></td>
                        <td data-label="Status">
                            <?= badge($device['status']) ?>
                            <?php if ((int)$device['active_sessions'] > 0): ?><span class="badge badge--live">Online</span><?php endif; ?>
                        </td>
                        <td class="table__actions" data-label="Actions">
                            <div class="dropdown" style="display:inline-block">
                                <button type="button" class="btn btn--sm btn--icon" data-dropdown="dev-<?= (int)$device['id'] ?>" aria-label="Actions"><?= icon('more', 'ico--sm') ?></button>
                                <div class="dropdown__menu" id="dev-<?= (int)$device['id'] ?>">
                                    <button type="button" class="dropdown__item" data-modal-open="rename-<?= (int)$device['id'] ?>"><?= icon('edit', 'ico--sm') ?> Rename</button>
                                    <a class="dropdown__item" href="<?= e(url('admin/network/sessions.php?q=' . urlencode((string)$device['mac_address']))) ?>"><?= icon('history', 'ico--sm') ?> Session history</a>
                                    <div class="dropdown__divider"></div>
                                    <?php if ((int)$device['active_sessions'] > 0): ?>
                                        <form method="post" data-confirm="Disconnect this device now?">
                                            <?= CSRF::field() ?>
                                            <input type="hidden" name="id" value="<?= (int)$device['id'] ?>">
                                            <button class="dropdown__item" name="action" value="disconnect"><?= icon('power', 'ico--sm') ?> Disconnect</button>
                                        </form>
                                    <?php endif; ?>
                                    <?php if ($device['status'] === 'blocked'): ?>
                                        <form method="post">
                                            <?= CSRF::field() ?>
                                            <input type="hidden" name="id" value="<?= (int)$device['id'] ?>">
                                            <button class="dropdown__item" name="action" value="unblock"><?= icon('check', 'ico--sm') ?> Unblock</button>
                                        </form>
                                    <?php else: ?>
                                        <form method="post" data-confirm="Block this device? It will be disconnected and cannot reconnect.">
                                            <?= CSRF::field() ?>
                                            <input type="hidden" name="id" value="<?= (int)$device['id'] ?>">
                                            <button class="dropdown__item dropdown__item--danger" name="action" value="block"><?= icon('block', 'ico--sm') ?> Block</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <?= pagination($page, 'devices') ?>
</section>

<?php
/* One rename dialogue per row. */
foreach ($page['rows'] as $device) {
    ob_start();
    echo CSRF::field();
    echo '<input type="hidden" name="id" value="' . (int)$device['id'] . '">';
    echo '<input type="hidden" name="action" value="rename">';
    echo field_input(['name' => 'name', 'label' => 'Device name', 'value' => (string)$device['name'], 'required' => true]);
    echo field_select(['name' => 'device_type', 'label' => 'Device type', 'value' => $device['device_type'], 'options' => WMS_DEVICE_TYPES]);
    echo '<p class="small muted mb-0">MAC ' . code_chip($device['mac_address']) . '</p>';
    $body = ob_get_clean();

    echo '<form method="post" action="">'
        . modal('rename-' . (int)$device['id'], 'Rename device', $body,
            '<button type="button" class="btn" data-modal-close>Cancel</button><button type="submit" class="btn btn--primary">Save</button>')
        . '</form>';
}
?>

<?php require INCLUDES_PATH . '/footer.php'; ?>
