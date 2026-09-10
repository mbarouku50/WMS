<?php
/**
 * WMS - Payments.
 *
 * The money ledger: every attempt, its provider, and what it bought.
 * Actions go through PaymentService so fulfilment stays consistent.
 */

$requiredPermission = 'manage_payments';
require_once __DIR__ . '/../../includes/auth-check.php';
require_once INCLUDES_PATH . '/components.php';

$payments = new Payment();
$service  = new PaymentService();

if (is_post()) {
    CSRF::verify();
    $action = post('action');
    $id     = (int)post('id');

    switch ($action) {
        case 'refresh':
            $result = $service->refreshStatus($id);
            Response::back($result['status'] === 'successful' ? 'success' : 'info', $result['message']);
            break;

        case 'refund':
            $result = $service->refund($id, post('reason', 'Refunded by staff'));
            Response::back($result['ok'] ? 'success' : 'error', $result['message']);
            break;

        case 'complete':
            $result = $service->fulfil($id);
            Response::back($result['ok'] ? 'success' : 'error', $result['message']);
            break;

        case 'manual':
            $result = $service->recordManualPayment($_POST);
            Response::back($result['ok'] ? 'success' : 'error', $result['message']);
            break;

        case 'reconcile':
            $result = $service->reconcilePending(25);
            Response::back('info', $result['checked'] . ' pending payment(s) checked, ' . $result['settled'] . ' settled.');
            break;

        default:
            Response::back('error', 'That action is not supported.');
    }
}

$filters = [
    'q'           => query('q'),
    'status'      => query('status'),
    'provider'    => query('provider'),
    'package_id'  => query('package_id'),
    'customer_id' => query('customer_id'),
    'date_from'   => query('from'),
    'date_to'     => query('to'),
    'sort'        => query('sort', 'created_at DESC'),
];

if (query('export') === 'csv') {
    $export = $payments->search($filters, 1, 5000);
    $rows = array_map(static fn($p) => [
        $p['transaction_ref'], $p['created_at'], $p['customer_name'] ?? $p['payer_name'] ?? '',
        $p['payer_phone'] ?? '', $p['package_name'] ?? '', money((float)$p['amount'], false),
        $p['method'], $p['provider'], $p['channel'] ?? '', $p['status'], $p['provider_txn_id'] ?? '', $p['completed_at'] ?? '',
    ], $export['rows']);
    AuditLog::record('payment_export', 'payment', null, 'Exported ' . count($rows) . ' payments to CSV');
    Response::csv('wms-payments-' . date('Ymd-His') . '.csv',
        ['Reference', 'Created', 'Customer', 'Phone', 'Package', 'Amount', 'Method', 'Provider', 'Channel', 'Status', 'Provider txn', 'Completed'],
        $rows);
}

$page      = $payments->search($filters, current_page(), (int)setting('records_per_page', 25));
$rangeFrom = ($filters['date_from'] ?: date('Y-m-01')) . ' 00:00:00';
$rangeTo   = ($filters['date_to'] ?: date('Y-m-t')) . ' 23:59:59';
$summary   = $payments->statusSummary($rangeFrom, $rangeTo);
$packages  = (new Package())->active();
$providers = $payments->providers();
$detail    = query('id') ? $payments->withRelations((int)query('id')) : null;
$detailTxn = $detail ? $payments->transactions((int)$detail['id']) : [];

$pageTitle    = 'Payments';
$pageSubtitle = 'Every payment attempt, and what it unlocked';
$activeNav    = 'payments';
$breadcrumbs  = [['label' => 'Payments']];
require INCLUDES_PATH . '/admin-header.php';
?>

<?= page_head($pageTitle, $pageSubtitle,
    '<form method="post" style="display:inline"><input type="hidden" name="action" value="reconcile">' . CSRF::field()
    . '<button class="btn">' . icon('refresh', 'ico--sm') . ' Check pending</button></form>'
    . '<a class="btn" href="' . e(query_string(['export' => 'csv'])) . '">' . icon('download', 'ico--sm') . ' Export CSV</a>'
    . '<button class="btn btn--primary" data-modal-open="manual-payment">' . icon('plus', 'ico--sm') . ' Record payment</button>'
) ?>

<?= demo_banner('payment') ?>

<div class="stat-grid mb-3">
    <?= stat_card(['label' => 'Successful', 'value' => money($summary['successful']['amount']), 'icon' => 'check', 'tone' => 'success',
        'meta' => number_format($summary['successful']['total']) . ' payments in range']) ?>
    <?= stat_card(['label' => 'Pending', 'value' => number_format($summary['pending']['total']), 'icon' => 'clock', 'tone' => 'warning',
        'meta' => money($summary['pending']['amount']) . ' awaiting confirmation']) ?>
    <?= stat_card(['label' => 'Failed', 'value' => number_format($summary['failed']['total']), 'icon' => 'alert', 'tone' => 'danger',
        'meta' => 'Includes cancelled: ' . number_format($summary['cancelled']['total'])]) ?>
    <?= stat_card(['label' => 'Refunded', 'value' => money($summary['refunded']['amount']), 'icon' => 'refresh', 'tone' => 'info',
        'meta' => number_format($summary['refunded']['total']) . ' refunds']) ?>
</div>

<section class="card">
    <form class="filter-bar" method="get" action="">
        <?= search_field($filters['q'], 'Search reference, phone or provider id…') ?>
        <?= filter_select('status', $filters['status'], WMS_PAYMENT_STATUSES, 'Any status') ?>
        <?= filter_select('provider', $filters['provider'], array_combine($providers, array_map('ucfirst', $providers)), 'Any provider') ?>
        <?= filter_select('package_id', $filters['package_id'], array_column($packages, 'name', 'id'), 'Any package') ?>
        <?= filter_date('from', $filters['date_from'], 'From') ?>
        <?= filter_date('to', $filters['date_to'], 'To') ?>
        <div class="filter-bar__actions">
            <button class="btn" type="submit"><?= icon('filter', 'ico--sm') ?> Filter</button>
            <a class="btn btn--ghost" href="<?= e(url('admin/payments/index.php')) ?>">Clear</a>
        </div>
    </form>

    <div class="table-wrap">
        <?php if (!$page['rows']): ?>
            <?= empty_state([
                'icon' => 'card', 'title' => 'No payments found',
                'text' => 'Payments appear here as soon as a customer buys a package on the portal, or when you record one at the counter.',
                'action' => '<button class="btn btn--primary" data-modal-open="manual-payment">Record a payment</button>',
            ]) ?>
        <?php else: ?>
            <table class="table table--stack">
                <thead>
                    <tr>
                        <th>Reference</th>
                        <th>Customer</th>
                        <th>Package</th>
                        <?= th_sort('Amount', 'amount', $filters['sort']) ?>
                        <th>Method</th>
                        <?= th_sort('Status', 'status', $filters['sort']) ?>
                        <?= th_sort('Date', 'created_at', $filters['sort']) ?>
                        <th class="table__actions">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($page['rows'] as $payment): ?>
                    <tr>
                        <td data-label="Reference">
                            <?= code_chip($payment['transaction_ref']) ?>
                            <?php if ($payment['provider_txn_id']): ?>
                                <div class="tiny faint mono"><?= e($payment['provider_txn_id']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td data-label="Customer">
                            <?php if ($payment['customer_id']): ?>
                                <a href="<?= e(url('admin/customers/view.php?id=' . (int)$payment['customer_id'])) ?>"><?= e($payment['customer_name'] ?? 'Customer') ?></a>
                            <?php else: ?>
                                <?= e($payment['payer_name'] ?: 'Walk-in') ?>
                            <?php endif; ?>
                            <div class="tiny muted"><?= e($payment['payer_phone'] ?? '') ?></div>
                        </td>
                        <td data-label="Package"><?= e($payment['package_name'] ?? '—') ?></td>
                        <td data-label="Amount" class="text-right nowrap strong"><?= e(money((float)$payment['amount'])) ?></td>
                        <td data-label="Method">
                            <?= e(label($payment['method'])) ?>
                            <div class="tiny muted"><?= e(ucfirst((string)$payment['provider'])) ?><?= $payment['channel'] ? ' · ' . e($payment['channel']) : '' ?></div>
                        </td>
                        <td data-label="Status">
                            <?= badge($payment['status']) ?>
                            <?php if ($payment['status'] === 'failed' && $payment['failure_reason']): ?>
                                <div class="tiny muted" title="<?= e($payment['failure_reason']) ?>"><?= e(str_limit($payment['failure_reason'], 32)) ?></div>
                            <?php endif; ?>
                        </td>
                        <td data-label="Date" class="nowrap">
                            <?= e(format_date($payment['created_at'], 'd M Y')) ?>
                            <div class="tiny muted"><?= e(format_date($payment['created_at'], 'H:i')) ?></div>
                        </td>
                        <td class="table__actions" data-label="Actions">
                            <a class="btn btn--sm" href="<?= e(query_string(['id' => (int)$payment['id']])) ?>"><?= icon('eye', 'ico--sm') ?></a>
                            <div class="dropdown" style="display:inline-block">
                                <button type="button" class="btn btn--sm btn--icon" data-dropdown="pay-<?= (int)$payment['id'] ?>" aria-label="Actions"><?= icon('more', 'ico--sm') ?></button>
                                <div class="dropdown__menu" id="pay-<?= (int)$payment['id'] ?>">
                                    <?php if ($payment['status'] === 'pending'): ?>
                                        <form method="post">
                                            <?= CSRF::field() ?>
                                            <input type="hidden" name="id" value="<?= (int)$payment['id'] ?>">
                                            <button class="dropdown__item" name="action" value="refresh"><?= icon('refresh', 'ico--sm') ?> Check with provider</button>
                                        </form>
                                        <form method="post" data-confirm="Mark this payment as received and issue the voucher? Only do this if you have confirmed the money arrived.">
                                            <?= CSRF::field() ?>
                                            <input type="hidden" name="id" value="<?= (int)$payment['id'] ?>">
                                            <button class="dropdown__item" name="action" value="complete"><?= icon('check', 'ico--sm') ?> Mark as received</button>
                                        </form>
                                    <?php endif; ?>
                                    <?php if ($payment['status'] === 'successful'): ?>
                                        <form method="post" data-confirm="Mark this payment refunded? Any voucher it created will be cancelled.">
                                            <?= CSRF::field() ?>
                                            <input type="hidden" name="id" value="<?= (int)$payment['id'] ?>">
                                            <button class="dropdown__item dropdown__item--danger" name="action" value="refund"><?= icon('refresh', 'ico--sm') ?> Mark refunded</button>
                                        </form>
                                    <?php endif; ?>
                                    <?php if ($payment['voucher_id']): ?>
                                        <a class="dropdown__item" href="<?= e(url('admin/vouchers/index.php?q=' . (int)$payment['voucher_id'])) ?>"><?= icon('ticket', 'ico--sm') ?> Related voucher</a>
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

    <?= pagination($page, 'payments') ?>
</section>

<?php
/* -------------------------------------------------- payment detail -------- */
if ($detail):
    $timeline = '';
    foreach ($detailTxn as $txn) {
        $tone = $txn['status'] === 'successful' ? 'is-success' : (in_array($txn['status'], ['failed', 'cancelled'], true) ? 'is-danger' : '');
        $timeline .= '<li class="' . $tone . '"><b>' . e(label($txn['type'])) . '</b> · ' . e($txn['status'])
            . '<div class="muted">' . e(str_limit((string)$txn['message'], 120)) . '</div>'
            . '<div class="timeline__time">' . e(format_date($txn['created_at'])) . '</div></li>';
    }

    $body = key_value([
        'Reference'    => code_chip($detail['transaction_ref']),
        'Status'       => badge($detail['status']),
        'Amount'       => '<b>' . e(money((float)$detail['amount'])) . '</b> ' . e($detail['currency']),
        'Customer'     => e($detail['customer_name'] ?? $detail['payer_name'] ?? 'Walk-in'),
        'Phone'        => e((string)$detail['payer_phone']),
        'Package'      => e($detail['package_name'] ?? '—'),
        'Voucher'      => $detail['voucher_code'] ? code_chip($detail['voucher_code'], true) : '',
        'Method'       => e(label($detail['method'])) . ' · ' . e(ucfirst((string)$detail['provider'])),
        'Channel'      => e((string)$detail['channel']),
        'Provider ref' => $detail['provider_ref'] ? code_chip($detail['provider_ref']) : '',
        'Provider txn' => $detail['provider_txn_id'] ? code_chip($detail['provider_txn_id']) : '',
        'Created'      => e(format_date($detail['created_at'])),
        'Completed'    => e(format_date($detail['completed_at'])),
        'Failure'      => e((string)$detail['failure_reason']),
    ]) . ($timeline ? '<div class="divider-label">Provider timeline</div><ul class="timeline">' . $timeline . '</ul>' : '');

    echo modal('payment-detail', 'Payment ' . $detail['transaction_ref'], $body,
        '<a class="btn" href="' . e(query_string([], ['id'])) . '">Close</a>');
endif;
?>

<?php
/* ------------------------------------------------- manual payment --------- */
ob_start();
?>
<p class="small muted">Use this for cash at the counter or a bank transfer. It creates the payment, issues a voucher and records the sale.</p>
<?= field_select([
    'name' => 'package_id', 'label' => 'Package', 'required' => true, 'placeholder' => 'Choose a package',
    'options' => array_reduce($packages, static function ($carry, $p) {
        $carry[$p['id']] = $p['name'] . ' — ' . money((float)$p['price']);
        return $carry;
    }, []),
]) ?>
<?= field_input(['name' => 'amount', 'type' => 'number', 'label' => 'Amount received', 'prefix' => setting('currency', 'TSh'), 'attrs' => 'min="0" step="any"', 'hint' => 'Leave blank to use the package price.']) ?>
<?= field_select(['name' => 'method', 'label' => 'Method', 'value' => 'cash', 'options' => ['cash' => 'Cash', 'bank_transfer' => 'Bank transfer', 'mobile_money' => 'Mobile money (manual)', 'other' => 'Other']]) ?>
<div class="form-grid">
    <?= field_input(['name' => 'payer_name', 'label' => 'Customer name']) ?>
    <?= field_input(['name' => 'payer_phone', 'label' => 'Phone', 'placeholder' => '0754 000 000']) ?>
</div>
<?php
$manualBody = ob_get_clean();
echo '<form method="post" action="">' . CSRF::field() . '<input type="hidden" name="action" value="manual">'
    . modal('manual-payment', 'Record a payment', $manualBody,
        '<button type="button" class="btn" data-modal-close>Cancel</button><button type="submit" class="btn btn--primary">' . icon('check', 'ico--sm') . ' Record &amp; issue voucher</button>')
    . '</form>';
?>

<?php if ($detail): ?>
<script>document.addEventListener('DOMContentLoaded', function () { WMS.openModal('payment-detail'); });</script>
<?php endif; ?>

<?php require INCLUDES_PATH . '/footer.php'; ?>
