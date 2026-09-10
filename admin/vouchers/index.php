<?php
/**
 * WMS - Voucher list.
 *
 * Search, filter, bulk-act on and export vouchers. All state changes go
 * through VoucherService so the network side stays in step.
 */

$requiredPermission = 'manage_vouchers';
require_once __DIR__ . '/../../includes/auth-check.php';
require_once INCLUDES_PATH . '/components.php';

$vouchers = new Voucher();
$service  = new VoucherService();

/* ---- single and bulk actions ---- */
if (is_post()) {
    CSRF::verify();
    $action = post('action');
    $ids    = array_map('intval', (array)($_POST['ids'] ?? []));

    if (post('id')) {
        $ids = [(int)post('id')];
    }
    if (!$ids) {
        Response::back('warning', 'Select at least one voucher first.');
    }

    $result = count($ids) === 1
        ? $service->applyAction($ids[0], $action)
        : $service->applyBulkAction($ids, $action);

    Response::back($result['ok'] ? 'success' : 'error', $result['message']);
}

/* ---- filters ---- */
$filters = [
    'q'          => query('q'),
    'status'     => query('status'),
    'package_id' => query('package_id'),
    'batch_id'   => query('batch_id'),
    'router_id'  => query('router_id'),
    'date_from'  => query('from'),
    'date_to'    => query('to'),
    'sort'       => query('sort', 'created_at DESC'),
];

/* ---- CSV export honours the current filters ---- */
if (query('export') === 'csv') {
    $export = $vouchers->search($filters, 1, 5000);
    $rows = [];
    foreach ($export['rows'] as $row) {
        $rows[] = [
            $row['code'],
            $row['package_name'],
            money((float)$row['price'], false),
            format_package_duration((int)$row['duration_value'], $row['duration_unit']),
            $row['data_limit_mb'] === null ? 'Unlimited' : $row['data_limit_mb'] . ' MB',
            $row['device_limit'],
            $row['status'],
            $row['batch_code'] ?? '',
            $row['customer_name'] ?? '',
            $row['activated_at'] ?? '',
            $row['expires_at'] ?? '',
            $row['created_at'],
        ];
    }
    AuditLog::record('voucher_export', 'voucher', null, 'Exported ' . count($rows) . ' vouchers to CSV');
    Response::csv('wms-vouchers-' . date('Ymd-His') . '.csv',
        ['Code', 'Package', 'Price', 'Duration', 'Data', 'Devices', 'Status', 'Batch', 'Customer', 'Activated', 'Expires', 'Created'],
        $rows);
}

$page       = $vouchers->search($filters, current_page(), (int)setting('records_per_page', 25));
$counts     = $vouchers->statusCounts();
$packages   = (new Package())->active();
$batches    = $vouchers->allBatches();

$pageTitle    = 'Vouchers';
$pageSubtitle = 'Prepaid access codes - generate, track, print and control';
$activeNav    = 'vouchers';
$breadcrumbs  = [['label' => 'Vouchers']];
require INCLUDES_PATH . '/admin-header.php';
?>

<?= page_head($pageTitle, $pageSubtitle,
    '<a class="btn" href="' . e(url('admin/vouchers/batches.php')) . '">' . icon('clipboard', 'ico--sm') . ' Batches</a>'
    . '<a class="btn" href="' . e(query_string(['export' => 'csv'])) . '">' . icon('download', 'ico--sm') . ' Export CSV</a>'
    . '<a class="btn btn--primary" href="' . e(url('admin/vouchers/generate.php')) . '">' . icon('plus', 'ico--sm') . ' Generate vouchers</a>'
) ?>

<div class="stat-grid mb-3">
    <?= stat_card(['label' => 'All vouchers', 'value' => number_format($counts['all']), 'icon' => 'ticket', 'tone' => 'neutral', 'href' => url('admin/vouchers/index.php')]) ?>
    <?= stat_card(['label' => 'Available', 'value' => number_format($counts['available']), 'icon' => 'clipboard', 'tone' => 'info', 'href' => url('admin/vouchers/index.php?status=available')]) ?>
    <?= stat_card(['label' => 'In use', 'value' => number_format($counts['active'] + $counts['activated']), 'icon' => 'wifi', 'tone' => 'success', 'href' => url('admin/vouchers/index.php?status=active')]) ?>
    <?= stat_card(['label' => 'Expired', 'value' => number_format($counts['expired']), 'icon' => 'clock', 'tone' => 'danger', 'href' => url('admin/vouchers/index.php?status=expired')]) ?>
    <?= stat_card(['label' => 'Exhausted', 'value' => number_format($counts['exhausted']), 'icon' => 'database', 'tone' => 'warning', 'href' => url('admin/vouchers/index.php?status=exhausted')]) ?>
    <?= stat_card(['label' => 'Suspended', 'value' => number_format($counts['suspended'] + $counts['cancelled']), 'icon' => 'block', 'tone' => 'neutral', 'href' => url('admin/vouchers/index.php?status=suspended')]) ?>
</div>

<section class="card">
    <form class="filter-bar" method="get" action="">
        <?= search_field($filters['q'], 'Search code, customer or phone…') ?>
        <?= filter_select('status', $filters['status'], WMS_VOUCHER_STATUSES, 'Any status') ?>
        <?= filter_select('package_id', $filters['package_id'], array_column($packages, 'name', 'id'), 'Any package') ?>
        <?= filter_select('batch_id', $filters['batch_id'], array_column($batches, 'batch_code', 'id'), 'Any batch') ?>
        <?= filter_date('from', $filters['date_from'], 'Created from') ?>
        <?= filter_date('to', $filters['date_to'], 'Created to') ?>
        <div class="filter-bar__actions">
            <button class="btn" type="submit"><?= icon('filter', 'ico--sm') ?> Filter</button>
            <a class="btn btn--ghost" href="<?= e(url('admin/vouchers/index.php')) ?>">Clear</a>
        </div>
    </form>

    <!-- bulk action bar, revealed when rows are ticked -->
    <form method="post" action="" class="filter-summary hidden" data-bulk-bar data-bulk-form>
        <?= CSRF::field() ?>
        <b><span data-bulk-count>0</span> selected</b>
        <span class="faint">·</span>
        <button class="btn btn--sm" name="action" value="suspend" type="submit"><?= icon('block', 'ico--sm') ?> Suspend</button>
        <button class="btn btn--sm" name="action" value="resume" type="submit"><?= icon('check', 'ico--sm') ?> Resume</button>
        <button class="btn btn--sm btn--danger" name="action" value="cancel" type="submit"><?= icon('x', 'ico--sm') ?> Cancel</button>
        <a class="btn btn--sm" href="#" onclick="var f=this.closest('form');var ids=[].slice.call(document.querySelectorAll('input[name=\'ids[]\']:checked')).map(function(b){return b.value});window.open('<?= e(url('admin/vouchers/print.php')) ?>?ids='+ids.join(','),'_blank');return false;"><?= icon('print', 'ico--sm') ?> Print selected</a>
    </form>

    <div class="table-wrap" id="voucher-table">
        <?php if (!$page['rows']): ?>
            <?= empty_state([
                'icon'  => 'ticket',
                'title' => array_filter([$filters['q'], $filters['status'], $filters['package_id']]) ? 'No vouchers match those filters' : 'No vouchers yet',
                'text'  => array_filter([$filters['q'], $filters['status'], $filters['package_id']])
                    ? 'Try clearing a filter, or search for a different code.'
                    : 'Generate your first batch and print the cards for your counter or your agents.',
                'action' => '<a class="btn btn--primary" href="' . e(url('admin/vouchers/generate.php')) . '">' . icon('plus', 'ico--sm') . ' Generate vouchers</a>',
            ]) ?>
        <?php else: ?>
            <table class="table table--stack">
                <thead>
                    <tr>
                        <th style="width:34px"><input type="checkbox" data-check-all="#voucher-table" aria-label="Select all"></th>
                        <?= th_sort('Code', 'code', $filters['sort']) ?>
                        <th>Package</th>
                        <th>Allowance</th>
                        <th>Customer</th>
                        <?= th_sort('Status', 'status', $filters['sort']) ?>
                        <?= th_sort('Expires', 'expires_at', $filters['sort']) ?>
                        <th class="table__actions">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($page['rows'] as $voucher): ?>
                    <?php $usedPercent = Voucher::dataUsedPercent($voucher); ?>
                    <tr>
                        <td data-label="Select"><input type="checkbox" name="ids[]" value="<?= (int)$voucher['id'] ?>" aria-label="Select voucher"></td>
                        <td data-label="Code">
                            <?= code_chip($voucher['code'], true) ?>
                            <?php if ($voucher['batch_code']): ?>
                                <div class="tiny faint"><?= e($voucher['batch_code']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td data-label="Package">
                            <?= e($voucher['package_name']) ?>
                            <div class="tiny muted"><?= e(money((float)$voucher['price'])) ?> · <?= e(format_package_duration((int)$voucher['duration_value'], $voucher['duration_unit'])) ?></div>
                        </td>
                        <td data-label="Allowance" style="min-width:140px">
                            <?php if ($voucher['data_limit_mb'] === null): ?>
                                <span class="small">Unlimited</span>
                            <?php else: ?>
                                <span class="small"><?= e(format_mb((float)$voucher['data_used_mb'])) ?> / <?= e(format_mb((int)$voucher['data_limit_mb'])) ?></span>
                                <div class="mt-1"><?= meter($usedPercent) ?></div>
                            <?php endif; ?>
                        </td>
                        <td data-label="Customer">
                            <?php if ($voucher['customer_name']): ?>
                                <a href="<?= e(url('admin/customers/view.php?id=' . (int)$voucher['customer_id'])) ?>"><?= e($voucher['customer_name']) ?></a>
                                <div class="tiny muted"><?= e($voucher['customer_phone'] ?? '') ?></div>
                            <?php else: ?>
                                <span class="faint">Unassigned</span>
                            <?php endif; ?>
                        </td>
                        <td data-label="Status"><?= badge($voucher['status']) ?></td>
                        <td data-label="Expires" class="nowrap">
                            <?php if ($voucher['expires_at']): ?>
                                <?= e(format_date($voucher['expires_at'], 'd M H:i')) ?>
                                <?php if (in_array($voucher['status'], ['active', 'activated'], true)): ?>
                                    <div class="tiny muted" data-countdown="<?= (int)seconds_until($voucher['expires_at']) ?>"><?= e(format_duration(seconds_until($voucher['expires_at']))) ?></div>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="faint">Not activated</span>
                            <?php endif; ?>
                        </td>
                        <td class="table__actions" data-label="Actions">
                            <div class="dropdown" style="display:inline-block">
                                <button type="button" class="btn btn--sm btn--icon" data-dropdown="v-<?= (int)$voucher['id'] ?>" aria-label="Actions"><?= icon('more', 'ico--sm') ?></button>
                                <div class="dropdown__menu" id="v-<?= (int)$voucher['id'] ?>">
                                    <a class="dropdown__item" href="<?= e(url('admin/vouchers/print.php?ids=' . (int)$voucher['id'])) ?>" target="_blank" rel="noopener"><?= icon('print', 'ico--sm') ?> Print card</a>
                                    <button type="button" class="dropdown__item" data-copy="<?= e($voucher['code']) ?>"><?= icon('copy', 'ico--sm') ?> Copy code</button>
                                    <?php if ($voucher['customer_id']): ?>
                                        <a class="dropdown__item" href="<?= e(url('admin/customers/view.php?id=' . (int)$voucher['customer_id'])) ?>"><?= icon('user', 'ico--sm') ?> Customer</a>
                                    <?php endif; ?>
                                    <div class="dropdown__divider"></div>
                                    <?php if ($voucher['status'] === 'suspended'): ?>
                                        <form method="post">
                                            <?= CSRF::field() ?>
                                            <input type="hidden" name="id" value="<?= (int)$voucher['id'] ?>">
                                            <button class="dropdown__item" name="action" value="resume"><?= icon('check', 'ico--sm') ?> Resume</button>
                                        </form>
                                    <?php elseif (in_array($voucher['status'], ['available', 'active', 'activated'], true)): ?>
                                        <form method="post" data-confirm="Suspend voucher <?= e($voucher['code']) ?>?">
                                            <?= CSRF::field() ?>
                                            <input type="hidden" name="id" value="<?= (int)$voucher['id'] ?>">
                                            <button class="dropdown__item" name="action" value="suspend"><?= icon('block', 'ico--sm') ?> Suspend</button>
                                        </form>
                                    <?php endif; ?>
                                    <form method="post" data-confirm="Cancel voucher <?= e($voucher['code']) ?>? It can never be used again.">
                                        <?= CSRF::field() ?>
                                        <input type="hidden" name="id" value="<?= (int)$voucher['id'] ?>">
                                        <button class="dropdown__item dropdown__item--danger" name="action" value="cancel"><?= icon('x', 'ico--sm') ?> Cancel</button>
                                    </form>
                                    <?php if (!$voucher['activated_at']): ?>
                                        <form method="post" data-confirm="Delete voucher <?= e($voucher['code']) ?> permanently?">
                                            <?= CSRF::field() ?>
                                            <input type="hidden" name="id" value="<?= (int)$voucher['id'] ?>">
                                            <button class="dropdown__item dropdown__item--danger" name="action" value="delete"><?= icon('trash', 'ico--sm') ?> Delete</button>
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

    <?= pagination($page, 'vouchers') ?>
</section>

<?php require INCLUDES_PATH . '/footer.php'; ?>
