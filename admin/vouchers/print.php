<?php
/**
 * WMS - Printable voucher cards.
 *
 * Opens as its own page (no sidebar) so the browser print dialogue produces
 * a clean sheet of cards. Accepts ?batch=ID or ?ids=1,2,3.
 */

$requiredPermission = 'manage_vouchers';
require_once __DIR__ . '/../../includes/auth-check.php';
require_once INCLUDES_PATH . '/components.php';

$vouchers = new Voucher();
$batchId  = (int)query('batch');
$idList   = array_filter(array_map('intval', explode(',', query('ids'))));

if ($batchId) {
    $batch = $vouchers->batch($batchId);
    $rows  = $vouchers->byBatch($batchId);
    $title = $batch ? $batch['batch_code'] : 'Batch';
} else {
    $batch = null;
    $rows  = $vouchers->byIds($idList);
    $title = count($rows) . ' voucher' . (count($rows) === 1 ? '' : 's');
}

$perPage = max(4, min(60, (int)query('per', '24')));
$rows    = array_slice($rows, 0, 600);

AuditLog::record('voucher_print', 'voucher_batch', $batchId ?: null, 'Opened the print sheet for ' . $title);
?>
<!DOCTYPE html>
<html lang="en" data-base="<?= e(BASE_PATH) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Print vouchers · <?= e($title) ?></title>
<link rel="stylesheet" href="<?= e(asset('css/style.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('css/admin.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('css/responsive.css')) ?>">
<style>
    body { background: var(--wms-canvas); padding: 1.25rem; }
    .print-toolbar {
        position: sticky; top: 0; z-index: 10;
        display: flex; align-items: center; justify-content: space-between;
        gap: 1rem; flex-wrap: wrap;
        padding: .8rem 1rem; margin-bottom: 1.25rem;
        background: var(--wms-surface);
        border: 1px solid var(--wms-border);
        border-radius: var(--wms-radius);
        box-shadow: var(--wms-shadow-sm);
    }
    @media print {
        body { padding: 0; background: #fff; }
        .print-toolbar { display: none; }
        .voucher-grid { grid-template-columns: repeat(3, 1fr) !important; gap: 6px; }
        .voucher-card { page-break-inside: avoid; }
    }
    @page { margin: 10mm; }
</style>
</head>
<body>

<div class="print-toolbar no-print">
    <div>
        <b><?= e($title) ?></b>
        <div class="small muted">
            <?= count($rows) ?> card<?= count($rows) === 1 ? '' : 's' ?>
            <?= $batch ? ' · ' . e($batch['package_name']) . ' · generated ' . e(format_date($batch['created_at'], 'd M Y')) : '' ?>
        </div>
    </div>
    <div class="flex gap-1 flex-wrap">
        <a class="btn" href="<?= e(url('admin/vouchers/index.php' . ($batchId ? '?batch_id=' . $batchId : ''))) ?>"><?= icon('chevron', 'ico--sm') ?> Back to vouchers</a>
        <button class="btn btn--primary" data-print><?= icon('print', 'ico--sm') ?> Print these cards</button>
    </div>
</div>

<div class="print-header">
    <b style="font-size:1.1rem"><?= e(setting('company_name', 'WMS')) ?></b>
    <div class="small"><?= e($title) ?> · printed <?= e(date('d M Y H:i')) ?></div>
</div>

<?php if (!$rows): ?>
    <div class="card"><div class="card__body">
        <?= empty_state([
            'icon' => 'ticket',
            'title' => 'Nothing to print',
            'text' => 'Select some vouchers from the list, or open a batch, and try again.',
            'action' => '<a class="btn btn--primary" href="' . e(url('admin/vouchers/index.php')) . '">Back to vouchers</a>',
        ]) ?>
    </div></div>
<?php else: ?>
    <div class="voucher-grid">
        <?php foreach ($rows as $voucher): ?>
            <?= voucher_card($voucher) ?>
        <?php endforeach; ?>
    </div>
    <p class="small muted text-center mt-3 no-print">
        Tip: print at 100% scale with background graphics enabled so the dark header band shows.
        The dashed square on each card is reserved for a QR code.
    </p>
<?php endif; ?>

<script src="<?= e(asset('js/app.js')) ?>"></script>
<script src="<?= e(asset('js/admin.js')) ?>"></script>
</body>
</html>
