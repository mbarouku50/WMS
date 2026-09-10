<?php
/**
 * WMS - Audit log.
 */

$requiredPermission = 'view_audit_logs';
require_once __DIR__ . '/../../includes/auth-check.php';
require_once INCLUDES_PATH . '/components.php';

$logs    = new AuditLog();
$filters = [
    'q'          => query('q'),
    'action'     => query('action'),
    'actor_type' => query('actor'),
    'date_from'  => query('from'),
    'date_to'    => query('to'),
];

if (query('export') === 'csv') {
    $rows = array_map(static fn($r) => [
        $r['created_at'], $r['actor_type'], $r['actor_name'], $r['action'],
        $r['entity_type'], $r['entity_id'], $r['description'], $r['ip_address'],
    ], $logs->search($filters, 1, 5000)['rows']);
    Response::csv('wms-audit-' . date('Ymd-His') . '.csv',
        ['When', 'Actor type', 'Actor', 'Action', 'Entity', 'Entity id', 'Description', 'IP'], $rows);
}

$page    = $logs->search($filters, current_page(), 40);
$actions = $logs->actions();

$pageTitle    = 'Audit logs';
$pageSubtitle = 'Who did what, when, and from where';
$activeNav    = 'audit';
$breadcrumbs  = [['label' => 'Administration'], ['label' => 'Audit logs']];
require INCLUDES_PATH . '/admin-header.php';
?>

<?= page_head($pageTitle, $pageSubtitle,
    '<a class="btn" href="' . e(query_string(['export' => 'csv'])) . '">' . icon('download', 'ico--sm') . ' Export CSV</a>'
) ?>

<section class="card">
    <form class="filter-bar" method="get" action="">
        <?= search_field($filters['q'], 'Search description, actor or action…') ?>
        <?= filter_select('action', $filters['action'], array_combine($actions, array_map('label', $actions)), 'Any action') ?>
        <?= filter_select('actor', $filters['actor_type'], ['user' => 'Staff', 'customer' => 'Customer', 'system' => 'System', 'api' => 'API'], 'Any actor') ?>
        <?= filter_date('from', $filters['date_from'], 'From') ?>
        <?= filter_date('to', $filters['date_to'], 'To') ?>
        <div class="filter-bar__actions">
            <button class="btn" type="submit"><?= icon('filter', 'ico--sm') ?> Filter</button>
            <a class="btn btn--ghost" href="<?= e(url('admin/administration/audit-logs.php')) ?>">Clear</a>
        </div>
    </form>

    <div class="table-wrap">
        <?php if (!$page['rows']): ?>
            <?= empty_state(['icon' => 'history', 'title' => 'No audit entries match', 'text' => 'Sign-ins, voucher generation, payments, router changes and settings edits are all recorded here.']) ?>
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
                        <td data-label="Entity">
                            <?= e(label($log['entity_type'] ?? '')) ?>
                            <?php if ($log['entity_id']): ?><span class="tiny faint">#<?= (int)$log['entity_id'] ?></span><?php endif; ?>
                        </td>
                        <td data-label="Description"><?= e($log['description'] ?? '') ?></td>
                        <td data-label="IP" class="mono tiny"><?= e($log['ip_address'] ?? '') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
    <?= pagination($page, 'entries') ?>
</section>

<?php require INCLUDES_PATH . '/footer.php'; ?>
