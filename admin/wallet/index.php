<?php
/**
 * WMS - Provider wallet.
 *
 * What this business has earned, what it owes the platform, and how to get
 * the money out. A platform administrator viewing as a provider sees the
 * same screen for that tenant.
 */

$requiredPermission = 'view_wallet';
require_once __DIR__ . '/../../includes/auth-check.php';
require_once INCLUDES_PATH . '/components.php';

$providerId = ProviderContext::providerId();
if ($providerId === null) {
    Response::redirect('admin/billing/index.php', 'info',
        'You are in platform scope. Provider wallets are listed here.');
}

$walletService = new WalletService();
$billing       = new BillingService();
$ledger        = new Wallet();
$withdrawals   = new Withdrawal();
$provider      = ProviderContext::provider() ?? [];

if (is_post()) {
    CSRF::verify();
    $action = post('action');

    if ($action === 'withdraw') {
        Permission::require('request_withdrawal');

        $result = $walletService->requestWithdrawal($providerId, [
            'amount'         => post('amount'),
            'method'         => post('method'),
            'account_number' => post('account_number'),
            'account_name'   => post('account_name'),
        ]);
        Response::back($result['ok'] ? 'success' : 'error', $result['message']);
    }

    if ($action === 'cancel_withdrawal') {
        Permission::require('request_withdrawal');

        // Scoped: another provider's withdrawal is not found here.
        $withdrawal = $withdrawals->find((int)post('id'));
        if (!$withdrawal) {
            Response::back('error', 'That withdrawal could not be found.');
        }
        if ($withdrawal['status'] !== 'pending') {
            Response::back('error', 'Only a withdrawal that has not been sent yet can be cancelled.');
        }
        $result = $walletService->releaseHold((int)$withdrawal['id'], 'cancelled', 'Cancelled by the provider');
        Response::back($result['ok'] ? 'success' : 'error', $result['message']);
    }

    if ($action === 'pay_invoice') {
        /*
         * The invoice id arrives from a form, so it has to be proved to
         * belong to this provider. Without this a provider could post
         * another tenant's invoice id and drain that tenant's wallet.
         */
        $invoiceId = (int)post('invoice_id');
        $owns = Database::getInstance()->count(
            'SELECT COUNT(*) FROM platform_invoices WHERE id = ? AND provider_id = ?',
            [$invoiceId, $providerId]
        ) > 0;

        if (!$owns) {
            Logger::warning('Blocked a cross-provider invoice payment', [
                'invoice_id' => $invoiceId, 'acting_provider' => $providerId,
            ]);
            Response::back('error', 'That invoice could not be found on this account.');
        }

        $result = $billing->payFromWallet($invoiceId);
        BillingGuard::forget($providerId);
        Response::back($result['ok'] ? 'success' : 'error', $result['message']);
    }

    Response::back('error', 'That action is not supported.');
}

$balance  = $walletService->balance($providerId);
$terms    = $billing->terms($providerId);
$feeState = (new BillingGuard())->state($providerId);
$summary  = $ledger->summary(date('Y-m-01 00:00:00'), date('Y-m-t 23:59:59'));
$series   = $ledger->dailyEarnings(14);

$filters  = ['q' => query('q'), 'type' => query('type'), 'date_from' => query('from'), 'date_to' => query('to')];
$entries  = $ledger->search($filters, current_page(), 20);
$myPayouts = $withdrawals->search([], 1, 10);

$minimum  = (float)setting('withdrawal_minimum', 5000);
$needsApproval = (string)setting('withdrawal_requires_approval', '1') === '1';

$pageTitle    = 'Wallet';
$pageSubtitle = 'What you have earned, and getting it out';
$activeNav    = 'wallet';
$breadcrumbs  = [['label' => 'Wallet']];
require INCLUDES_PATH . '/admin-header.php';
?>

<?= page_head('Wallet', ProviderContext::scopeLabel(),
    (Permission::has('request_withdrawal') && $balance['available'] >= $minimum
        ? '<button class="btn btn--primary" data-modal-open="withdraw-form">' . icon('download', 'ico--sm') . ' Withdraw money</button>'
        : '')
) ?>

<?php if (empty($provider['mobile_money_enabled'])): ?>
    <?= alert_box('info',
        'This account sells through vouchers. Mobile money payments from customers are not switched on, so nothing is being credited here automatically. Ask the platform administrator if you would like to accept mobile money.',
        'Voucher sales only') ?>
<?php endif; ?>

<!-- ===================================================== balances -->
<div class="stat-grid mb-3">
    <?= stat_card([
        'label' => 'Available to withdraw',
        'value' => money($balance['available']),
        'icon'  => 'money',
        'tone'  => $balance['available'] > 0 ? 'success' : 'neutral',
        'meta'  => $balance['held'] > 0 ? money($balance['held']) . ' held against a pending withdrawal' : 'Nothing held',
    ]) ?>
    <?= stat_card([
        'label' => 'Wallet balance',
        'value' => money($balance['balance']),
        'icon'  => 'card',
        'tone'  => 'primary',
        'meta'  => 'Including anything held',
    ]) ?>
    <?= stat_card([
        'label' => 'Earned this month',
        'value' => money($summary['by_type']['sale'] ?? 0),
        'icon'  => 'trend-up',
        'tone'  => 'success',
        'meta'  => money($balance['earned']) . ' all time',
    ]) ?>
    <?= stat_card([
        'label' => 'Platform fees owed',
        'value' => money($terms['outstanding'] ?? 0),
        'icon'  => 'building',
        'tone'  => ($terms['outstanding'] ?? 0) > 0 ? 'warning' : 'neutral',
        'meta'  => $terms['summary'] ?? '',
    ]) ?>
</div>

<div class="grid grid--3-2 mb-3">
    <!-- ============================================ the ledger -->
    <section class="card">
        <div class="card__head">
            <div>
                <h2 class="card__title"><?= icon('history') ?> Wallet activity</h2>
                <p class="card__subtitle">Every movement, with the balance it produced</p>
            </div>
        </div>

        <form class="filter-bar" method="get" action="">
            <?= search_field($filters['q'], 'Search reference or description…') ?>
            <?= filter_select('type', $filters['type'], Wallet::TYPES, 'Any type') ?>
            <?= filter_date('from', $filters['date_from'], 'From') ?>
            <?= filter_date('to', $filters['date_to'], 'To') ?>
            <div class="filter-bar__actions">
                <button class="btn" type="submit"><?= icon('filter', 'ico--sm') ?> Filter</button>
            </div>
        </form>

        <div class="table-wrap">
            <?php if (!$entries['rows']): ?>
                <?= empty_state([
                    'icon'  => 'money',
                    'title' => 'Nothing in the wallet yet',
                    'text'  => empty($provider['mobile_money_enabled'])
                        ? 'Money appears here when customers pay you by mobile money. That is not switched on for this account yet — voucher sales are collected by you directly.'
                        : 'Money appears here the moment a customer pays for a package.',
                ]) ?>
            <?php else: ?>
                <table class="table table--stack">
                    <thead>
                        <tr><th>When</th><th>Type</th><th>Description</th><th class="text-right">Amount</th><th class="text-right">Balance after</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($entries['rows'] as $entry): ?>
                        <tr>
                            <td data-label="When" class="nowrap">
                                <?= e(format_date($entry['created_at'], 'd M Y')) ?>
                                <div class="tiny muted"><?= e(format_date($entry['created_at'], 'H:i')) ?></div>
                            </td>
                            <td data-label="Type">
                                <span class="pill"><?= e(Wallet::TYPES[$entry['type']] ?? label($entry['type'])) ?></span>
                            </td>
                            <td data-label="Description">
                                <?= e((string)$entry['description']) ?>
                                <?php if ($entry['reference']): ?>
                                    <div class="tiny faint mono"><?= e($entry['reference']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td data-label="Amount" class="text-right nowrap strong"
                                style="color:<?= $entry['direction'] === 'credit' ? 'var(--wms-success)' : 'var(--wms-danger)' ?>">
                                <?= $entry['direction'] === 'credit' ? '+' : '−' ?><?= e(money((float)$entry['amount'])) ?>
                            </td>
                            <td data-label="Balance after" class="text-right nowrap"><?= e(money((float)$entry['balance_after'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?= pagination($entries, 'entries') ?>
    </section>

    <div>
        <!-- ======================================= earnings chart -->
        <section class="card mb-2">
            <div class="card__head"><h2 class="card__title"><?= icon('chart') ?> Last 14 days</h2></div>
            <div class="card__body">
                <?= chart([
                    'type'   => 'bar',
                    'format' => 'money',
                    'labels' => array_map(static fn($r) => date('d M', strtotime((string)$r['day'])), $series),
                    'series' => [['name' => 'Credited', 'data' => array_map(static fn($r) => (float)$r['credited'], $series)]],
                ], '170px') ?>
            </div>
        </section>

        <!-- ==================================== platform fees -->
        <section class="card mb-2">
            <div class="card__head">
                <h2 class="card__title"><?= icon('building') ?> Platform fee</h2>
                <?= badge($terms['status'] ?? 'grace', ucfirst((string)($terms['status'] ?? 'grace'))) ?>
            </div>
            <div class="card__body">
                <p class="small muted mb-2"><?= e($terms['summary'] ?? '') ?></p>

                <?php if ($feeState['warn']): ?>
                    <?= alert_box($feeState['locked'] ? 'danger' : 'warning', $feeState['detail'], $feeState['headline']) ?>
                <?php endif; ?>
                <?= key_value([
                    'Fee'         => e(money($terms['fee'] ?? 0)) . ' every ' . (($terms['cycle_months'] ?? 1) === 1 ? 'month' : ($terms['cycle_months'] ?? 1) . ' months'),
                    'Starts on'   => e(format_date($terms['starts_on'] ?? null, 'd M Y')),
                    'Next due'    => e(format_date($terms['next_due_on'] ?? null, 'd M Y')),
                    'Outstanding' => ($terms['outstanding'] ?? 0) > 0
                        ? '<b style="color:var(--wms-warning)">' . e(money($terms['outstanding'])) . '</b>'
                        : '<span class="badge badge--success">Nothing owed</span>',
                ]) ?>

                <?php if (!empty($terms['unpaid'])): ?>
                    <div class="divider-label">Unpaid invoices</div>
                    <?php foreach ($terms['unpaid'] as $invoice): ?>
                        <div class="node mb-1">
                            <span class="node__icon node__icon--<?= $invoice['due_on'] < date('Y-m-d') ? 'offline' : 'unknown' ?>">
                                <?= icon('report') ?>
                            </span>
                            <span class="node__text">
                                <b><?= e($invoice['invoice_number']) ?> · <?= e(money((float)$invoice['amount'])) ?></b>
                                <span>
                                    <?= e(format_date($invoice['period_start'], 'd M')) ?> – <?= e(format_date($invoice['period_end'], 'd M Y')) ?>
                                    · due <?= e(format_date($invoice['due_on'], 'd M Y')) ?>
                                    <?= $invoice['due_on'] < date('Y-m-d') ? ' <b style="color:var(--wms-danger)">overdue</b>' : '' ?>
                                </span>
                            </span>
                            <?php if ($balance['available'] >= (float)$invoice['amount']): ?>
                                <form method="post" data-confirm="Pay <?= e($invoice['invoice_number']) ?> (<?= e(money((float)$invoice['amount'])) ?>) from your wallet?" data-confirm-tone="primary" data-confirm-button="Pay now">
                                    <?= CSRF::field() ?>
                                    <input type="hidden" name="invoice_id" value="<?= (int)$invoice['id'] ?>">
                                    <button class="btn btn--sm btn--primary" name="action" value="pay_invoice">Pay now</button>
                                </form>
                            <?php else: ?>
                                <!-- The wallet cannot cover it, which is the normal case for a
                                     voucher-only operator. The pay screen takes it from a phone. -->
                                <a class="btn btn--sm btn--primary" href="<?= e(url(BillingGuard::PAY_PAGE)) ?>">Pay by phone</a>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </section>

        <!-- ======================================= withdrawals -->
        <section class="card">
            <div class="card__head">
                <h2 class="card__title"><?= icon('download') ?> Withdrawals</h2>
                <?php if (Permission::has('request_withdrawal')): ?>
                    <button class="btn btn--sm btn--primary" data-modal-open="withdraw-form">New</button>
                <?php endif; ?>
            </div>
            <div class="card__body--flush">
                <?php if (!$myPayouts['rows']): ?>
                    <?= empty_state([
                        'icon' => 'download', 'title' => 'No withdrawals yet',
                        'text' => 'Once you have ' . money($minimum) . ' available you can send it to your bank or mobile wallet.',
                    ]) ?>
                <?php else: ?>
                    <ul class="activity-list">
                        <?php foreach ($myPayouts['rows'] as $payout): ?>
                            <li>
                                <span class="activity-list__icon"><?= icon('download', 'ico--sm') ?></span>
                                <span class="activity-list__body">
                                    <b><?= e(money((float)$payout['amount'])) ?></b>
                                    <span class="muted">· <?= e($payout['method']) ?></span>
                                    <div class="tiny muted mono"><?= e($payout['reference']) ?> · <?= e($payout['account_number']) ?></div>
                                    <?php if ($payout['failure_reason']): ?>
                                        <div class="tiny" style="color:var(--wms-danger)"><?= e(str_limit($payout['failure_reason'], 70)) ?></div>
                                    <?php endif; ?>
                                </span>
                                <span class="text-right">
                                    <?= badge($payout['status'] === 'completed' ? 'successful' : ($payout['status'] === 'processing' ? 'pending' : $payout['status'])) ?>
                                    <div class="activity-list__time"><?= e(time_ago($payout['created_at'])) ?></div>
                                    <?php if ($payout['status'] === 'pending' && Permission::has('request_withdrawal')): ?>
                                        <form method="post" data-confirm="Cancel this withdrawal and return the money to your wallet?">
                                            <?= CSRF::field() ?>
                                            <input type="hidden" name="id" value="<?= (int)$payout['id'] ?>">
                                            <button class="btn btn--sm btn--ghost" name="action" value="cancel_withdrawal">Cancel</button>
                                        </form>
                                    <?php endif; ?>
                                </span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </section>
    </div>
</div>

<?php
/* ------------------------------------------------ withdrawal dialogue -- */
if (Permission::has('request_withdrawal')):
    ob_start();
    echo CSRF::field();
    echo '<input type="hidden" name="action" value="withdraw">';
    ?>
    <p class="small muted">
        Available to withdraw: <b><?= e(money($balance['available'])) ?></b>.
        Smallest withdrawal <?= e(money($minimum)) ?>.
        <?= $needsApproval ? 'The platform reviews each request before the money is sent.' : 'Payouts are sent as soon as you confirm.' ?>
    </p>

    <?php if (($terms['outstanding'] ?? 0) > 0): ?>
        <?= alert_box('warning', 'You owe ' . money($terms['outstanding']) . ' in platform fees. Leave at least that much in the wallet, or settle the invoice first.') ?>
    <?php endif; ?>

    <?= field_input([
        'name' => 'amount', 'type' => 'number', 'label' => 'Amount', 'required' => true,
        'prefix' => setting('currency', 'TSh'),
        'attrs' => 'min="' . $minimum . '" max="' . $balance['available'] . '" step="any"',
        'value' => (string)max(0, $balance['available'] - ($terms['outstanding'] ?? 0)),
    ]) ?>
    <?= field_select([
        'name' => 'method', 'label' => 'Send to', 'required' => true,
        'value' => (string)($provider['payout_method'] ?? ''),
        'placeholder' => 'Choose a destination',
        'options' => Withdrawal::METHODS,
    ]) ?>
    <?= field_input([
        'name' => 'account_number', 'label' => 'Account or phone number', 'required' => true,
        'value' => (string)($provider['payout_account_number'] ?? ''),
        'class' => 'input--mono',
        'hint'  => 'For Selcom, use 9 digits without a leading 0 or 255.',
    ]) ?>
    <?= field_input([
        'name' => 'account_name', 'label' => 'Name on the account', 'required' => true,
        'value' => (string)($provider['payout_account_name'] ?? $provider['business_name'] ?? ''),
    ]) ?>
    <?php
    $body = ob_get_clean();
    echo '<form method="post" action="">'
        . modal('withdraw-form', 'Withdraw money', $body,
            '<button type="button" class="btn" data-modal-close>Cancel</button>'
            . '<button type="submit" class="btn btn--primary">' . icon('download', 'ico--sm') . ' Request withdrawal</button>')
        . '</form>';
endif;
?>

<?php require INCLUDES_PATH . '/footer.php'; ?>
