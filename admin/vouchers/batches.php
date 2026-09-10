<?php
/**
 * WMS - Voucher batches.
 */

$requiredPermission = 'manage_vouchers';
require_once __DIR__ . '/../../includes/auth-check.php';
require_once INCLUDES_PATH . '/components.php';

$vouchers = new Voucher();
$filters  = ['q' => query('q'), 'package_id' => query('package_id')];
$page     = $vouchers->batches($filters, current_page(), (int)setting('records_per_page', 20));
$packages = (new Package())->active();

$pageTitle   = 'Voucher batches';
$activeNav   = 'vouchers';
$breadcrumbs = [['label' => 'Vouchers', 'url' => 'admin/vouchers/index.php'], ['label' => 'Batches']];
require INCLUDES_PATH . '/admin-header.php';
?>

<?= page_head('Voucher batches', 'Every generation run, with how much of it has been used',
    '<a class="btn btn--primary" href="' . e(url('admin/vouchers/generate.php')) . '">' . icon('plus', 'ico--sm') . ' Generate a batch</a>'
) ?>

<section class="card">
    <form class="filter-bar" method="get" action="">
        <?= search_field($filters['q'], 'Search batch code or name…') ?>
        <?= filter_select('package_id', $filters['package_id'], array_column($packages, 'name', 'id'), 'Any package') ?>
        <div class="filter-bar__actions">
            <button class="btn" type="submit"><?= icon('filter', 'ico--sm') ?> Filter</button>
        </div>
    </form>

    <div class="table-wrap">
        <?php if (!$page['rows']): ?>
            <?= empty_state([
                'icon' => 'clipboard', 'title' => 'No batches yet',
                'text' => 'A batch records who generated which codes, when, and for which package.',
                'action' => '<a class="btn btn--primary" href="' . e(url('admin/vouchers/generate.php')) . '">Generate your first batch</a>',
            ]) ?>
        <?php else: ?>
            <table class="table table--stack">
                <thead>
                    <tr>
                        <th>Batch</th>
                        <th>Package</th>
                        <th class="text-right">Vouchers</th>
                        <th style="min-width:150px">Usage</th>
                        <th class="text-right">Value</th>
                        <th>Created</th>
                        <th class="table__actions">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($page['rows'] as $batch): ?>
                    <?php
                    $total = max(1, (int)$batch['total']);
                    $used  = (int)$batch['used'] + (int)$batch['active'];
                    ?>
                    <tr>
                        <td data-label="Batch">
                            <?= cell_primary($batch['batch_code'], (string)($batch['name'] ?? ''), '', url('admin/vouchers/index.php?batch_id=' . (int)$batch['id'])) ?>
                        </td>
                        <td data-label="Package"><?= e($batch['package_name']) ?></td>
                        <td data-label="Vouchers" class="text-right"><?= number_format((int)$batch['total']) ?></td>
                        <td data-label="Usage">
                            <div class="small"><?= number_format($used) ?> used · <?= number_format((int)$batch['available']) ?> left</div>
                            <div class="mt-1"><?= meter(percent($used, $total, 1), 'success') ?></div>
                        </td>
                        <td data-label="Value" class="text-right nowrap"><?= e(money((float)$batch['price'] * (int)$batch['total'])) ?></td>
                        <td data-label="Created" class="nowrap">
                            <?= e(format_date($batch['created_at'], 'd M Y')) ?>
                            <div class="tiny muted"><?= e($batch['created_by_name'] ?? 'System') ?></div>
                        </td>
                        <td class="table__actions" data-label="Actions">
                            <a class="btn btn--sm" href="<?= e(url('admin/vouchers/print.php?batch=' . (int)$batch['id'])) ?>" target="_blank" rel="noopener"><?= icon('print', 'ico--sm') ?> Print</a>
                            <a class="btn btn--sm" href="<?= e(url('admin/vouchers/index.php?batch_id=' . (int)$batch['id'])) ?>"><?= icon('eye', 'ico--sm') ?></a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <?= pagination($page, 'batches') ?>
</section>

<?php require INCLUDES_PATH . '/footer.php'; ?>
