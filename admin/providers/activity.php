<?php
/**
 * WMS - One provider's activity log (platform view).
 */

$requirePlatform    = true;
$requiredPermission = 'manage_providers';
require_once __DIR__ . '/../../includes/auth-check.php';
require_once INCLUDES_PATH . '/components.php';

$providers = new Provider();
$id        = (int)query('id');
$provider  = $id ? $providers->find($id) : null;

if (!$provider) {
    Response::redirect('admin/providers/index.php', 'error', 'That provider could not be found.');
}

$db      = Database::getInstance();
$filters = ['q' => query('q'), 'action' => query('action'), 'date_from' => query('from'), 'date_to' => query('to')];

$where  = ['a.provider_id = ?'];
$params = [$id];

if ($filters['q'] !== '') {
    $where[] = '(a.description LIKE ? OR a.actor_name LIKE ? OR a.action LIKE ?)';
    $term    = '%' . $filters['q'] . '%';
    $params  = array_merge($params, [$term, $term, $term]);
}
if ($filters['action'] !== '') {
    $where[]  = 'a.action = ?';
    $params[] = $filters['action'];
}
if ($filters['date_from'] !== '') {
    $where[]  = 'a.created_at >= ?';
    $params[] = $filters['date_from'] . ' 00:00:00';
}
if ($filters['date_to'] !== '') {
    $where[]  = 'a.created_at <= ?';
    $params[] = $filters['date_to'] . ' 23:59:59';
}
$whereSql = implode(' AND ', $where);

$logs = new AuditLog();
$page = $logs->paginate(
    "SELECT a.*, u.username FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id
      WHERE $whereSql ORDER BY a.created_at DESC, a.id DESC",
    "SELECT COUNT(*) FROM audit_logs a WHERE $whereSql",
    $params,
    current_page(),
    40
);

$actions = array_column(
    $db->fetchAll('SELECT DISTINCT action FROM audit_logs WHERE provider_id = ? ORDER BY action', [$id]),
    'action'
);

if (query('export') === 'csv') {
    $all = $logs->paginate(
        "SELECT a.*, u.username FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id
          WHERE $whereSql ORDER BY a.created_at DESC",
        "SELECT COUNT(*) FROM audit_logs a WHERE $whereSql",
        $params, 1, 5000
    );
    $rows = array_map(static fn($r) => [
        $r['created_at'], $r['actor_type'], $r['actor_name'], $r['action'],
        $r['entity_type'], $r['entity_id'], $r['description'], $r['ip_address'],
    ], $all['rows']);
    AuditLog::record('provider_activity_export', 'provider', $id,
        'Exported the activity log for ' . $provider['business_name']);
    Response::csv('wms-' . strtolower($provider['provider_code']) . '-activity-' . date('Ymd-His') . '.csv',
        ['When', 'Actor type', 'Actor', 'Action', 'Entity', 'Entity id', 'Description', 'IP'], $rows);
}

$pageTitle   = 'Activity · ' . $provider['business_name'];
$activeNav   = 'providers';
$breadcrumbs = [
    ['label' => 'Providers', 'url' => 'admin/providers/index.php'],
    ['label' => $provider['business_name'], 'url' => 'admin/providers/view.php?id=' . $id],
    ['label' => 'Activity'],
];
require INCLUDES_PATH . '/admin-header.php';
?>

<?= page_head('Activity · ' . $provider['business_name'],
    'Everything this provider has done, and everything done to it',
    '<a class="btn" href="' . e(query_string(['export' => 'csv'])) . '">' . icon('download', 'ico--sm') . ' Export CSV</a>'
    . '<a class="btn" href="' . e(url('admin/providers/view.php?id=' . $id)) . '">Back to overview</a>'
) ?>

<section class="card">
    <form class="filter-bar" method="get" action="">
        <input type="hidden" name="id" value="<?= $id ?>">
        <?= search_field($filters['q'], 'Search description, actor or action…') ?>
        <?= filter_select('action', $filters['action'], array_combine($actions, array_map('label', $actions)), 'Any action') ?>
        <?= filter_date('from', $filters['date_from'], 'From') ?>
        <?= filter_date('to', $filters['date_to'], 'To') ?>
        <div class="filter-bar__actions">
            <button class="btn" type="submit"><?= icon('filter', 'ico--sm') ?> Filter</button>
        </div>
    </form>

    <div class="table-wrap">
        <?php if (!$page['rows']): ?>
            <?= empty_state(['icon' => 'history', 'title' => 'Nothing logged', 'text' => 'Actions taken by this provider, and platform actions affecting it, appear here.']) ?>
        <?php else: ?>
            <table class="table table--stack table--compact">
                <thead><tr><th>When</th><th>Actor</th><th>Action</th><th>Entity</th><th>Description</th><th>IP</th></tr></thead>
                <tbody>
                <?php foreach ($page['rows'] as $log): ?>
                    <tr>
                        <td data-label="When" class="nowrap">
                            <?= e(format_date($log['created_at'], 'd M Y H:i')) ?>
                            <div class="tiny faint"><?= e(time_ago($log['created_at'])) ?></div>
                        </td>
                        <td data-label="Actor">
                            <?= e($log['actor_name'] ?: 'System') ?>
                            <div class="tiny muted"><?= e(label($log['actor_type'])) ?><?= $log['username'] ? ' · @' . e($log['username']) : '' ?></div>
                        </td>
                        <td data-label="Action"><span class="pill"><?= e(label($log['action'])) ?></span></td>
                        <td data-label="Entity"><?= e(label($log['entity_type'] ?? '')) ?><?php if ($log['entity_id']): ?> <span class="tiny faint">#<?= (int)$log['entity_id'] ?></span><?php endif; ?></td>
                        <td data-label="Description"><?= e((string)$log['description']) ?></td>
                        <td data-label="IP" class="mono tiny"><?= e((string)$log['ip_address']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
    <?= pagination($page, 'entries') ?>
</section>

<?php require INCLUDES_PATH . '/footer.php'; ?>
