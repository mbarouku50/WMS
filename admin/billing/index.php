<?php
/**
 * WMS - Platform billing (Super Admin).
 *
 * Your side of the money: what providers are holding, what they owe you,
 * and the withdrawals waiting for your approval.
 */

$requirePlatform    = true;
$requiredPermission = 'manage_billing';
require_once __DIR__ . '/../../includes/auth-check.php';
require_once INCLUDES_PATH . '/components.php';

$billing     = new BillingService();
$wallet      = new WalletService();
$platform    = new PlatformWalletService();
$myPayouts   = new PlatformPayout();
$withdrawals = new Withdrawal();
$invoices    = new PlatformInvoice();
$providers   = new Provider();
$db          = Database::getInstance();

if (is_post()) {
    CSRF::verify();
    $action = post('action');
    $id     = (int)post('id');

    switch ($action) {
        case 'approve_withdrawal':
            $result = $wallet->processWithdrawal($id);
            Response::back($result['ok'] ? 'success' : 'error', $result['message']);
            break;

        case 'reject_withdrawal':
            $result = $wallet->releaseHold($id, 'cancelled', post('reason', 'Declined by the platform'));
            Response::back($result['ok'] ? 'warning' : 'error',
                $result['ok'] ? 'Withdrawal declined and the money returned to the provider wallet.' : $result['message']);
            break;

        case 'complete_withdrawal':
            $result = $wallet->completeWithdrawal($id);
            Response::back($result['ok'] ? 'success' : 'error', $result['message']);
            break;

        case 'fail_withdrawal':
            $result = $wallet->releaseHold($id, 'failed', post('reason', 'Marked failed by the platform'));
            Response::back($result['ok'] ? 'warning' : 'error', $result['message']);
            break;

        case 'run_billing':
            $result = $billing->runBilling();
            Response::back('info', $result['raised'] . ' invoice(s) raised, '
                . $result['collected'] . ' settled from wallets, ' . $result['unpaid'] . ' left unpaid.');
            break;

        case 'collect_invoice':
            $result = $billing->payFromWallet($id);
            Response::back($result['ok'] ? 'success' : 'warning', $result['message']);
            break;

        case 'mark_paid':
            $result = $billing->markPaidManually($id, post('note', 'Paid outside the platform'));
            Response::back($result['ok'] ? 'success' : 'error', $result['message']);
            break;

        case 'waive':
            $result = $billing->waive($id, post('reason', 'Waived by the platform owner'));
            Response::back($result['ok'] ? 'success' : 'error', $result['message']);
            break;

        case 'platform_withdraw':
            // The owner paying themselves. No approval step: they are the
            // approver, so this validates and sends in one movement.
            $result = $platform->withdraw([
                'amount'         => post('amount'),
                'method'         => post('method'),
                'account_number' => post('account_number'),
                'account_name'   => post('account_name'),
                'note'           => post('note'),
            ]);
            Response::back($result['ok'] ? 'success' : 'error', $result['message']);
            break;

        case 'platform_payout_complete':
            $result = $platform->complete($id);
            Response::back($result['ok'] ? 'success' : 'error', $result['message']);
            break;

        case 'platform_payout_failed':
            $result = $platform->fail($id, post('reason', 'Marked failed by the platform owner'));
            Response::back($result['ok'] ? 'warning' : 'error', $result['message']);
            break;

        case 'grant_grace':
            $providerId = (int)post('provider_id');
            $until      = post('until');
            if ($providerId <= 0 || $until === '') {
                Response::back('error', 'Choose a provider and the date the extension runs to.');
            }
            $result = (new BillingGuard())->grantGrace($providerId, $until,
                Validator::string(post('reason'), 200) ?: 'Extension granted by the platform');
            Response::back($result['ok'] ? 'success' : 'error', $result['message']);
            break;

        case 'revoke_grace':
            $result = (new BillingGuard())->revokeGrace((int)post('provider_id'));
            Response::back($result['ok'] ? 'warning' : 'error', $result['message']);
            break;

        case 'adjust':
            $providerId = (int)post('provider_id');
            $amount     = (float)post('amount');
            $direction  = post('direction', 'credit');
            $note       = Validator::string(post('note'), 255) ?: 'Manual adjustment by the platform';

            if ($providerId <= 0 || $amount <= 0) {
                Response::back('error', 'Choose a provider and an amount.');
            }
            $result = $direction === 'debit'
                ? $wallet->debit($providerId, $amount, 'adjustment', $note)
                : $wallet->credit($providerId, $amount, 'adjustment', $note);

            AuditLog::record('wallet_adjustment', 'provider', $providerId,
                ucfirst($direction) . ' of ' . money($amount) . '. ' . $note);
            Response::back($result['ok'] ? 'success' : 'error', $result['message']);
            break;

        default:
            Response::back('error', 'That action is not supported.');
    }
}

$tab = query('tab', 'overview');
if (!in_array($tab, ['overview', 'my-money', 'withdrawals', 'invoices', 'wallets'], true)) {
    $tab = 'overview';
}

$summary        = $billing->platformSummary();
$pendingPayouts = $withdrawals->pendingApproval(20);
$providerList   = $providers->listAll();
$lockedProviders = $invoices->lockedProviders();
$myBalance       = $platform->balance();

$pageTitle    = 'Billing';
$pageSubtitle = 'Provider wallets, platform fees and payouts';
$activeNav    = 'billing';
$breadcrumbs  = [['label' => 'Billing']];
require INCLUDES_PATH . '/admin-header.php';
?>

<?= page_head('Billing', 'What providers hold, what they owe you, and what is waiting to be paid out',
    '<form method="post" style="display:inline">' . CSRF::field()
    . '<input type="hidden" name="action" value="run_billing">'
    . '<button class="btn">' . icon('refresh', 'ico--sm') . ' Run billing now</button></form>'
    . '<button class="btn btn--primary" data-modal-open="adjust-form">' . icon('edit', 'ico--sm') . ' Adjust a wallet</button>'
) ?>

<div class="stat-grid mb-3">
    <?= stat_card(['label' => 'Held for providers', 'value' => money((float)($summary['held_for_providers'] ?? 0)),
        'icon' => 'card', 'tone' => 'primary', 'meta' => money((float)($summary['reserved'] ?? 0)) . ' reserved against payouts']) ?>
    <?= stat_card(['label' => 'Fees collected', 'value' => money((float)($summary['collected'] ?? 0)),
        'icon' => 'money', 'tone' => 'success', 'meta' => money((float)($summary['collected_month'] ?? 0)) . ' this month']) ?>
    <?= stat_card(['label' => 'Fees outstanding', 'value' => money((float)($summary['outstanding'] ?? 0)),
        'icon' => 'report', 'tone' => ($summary['outstanding'] ?? 0) > 0 ? 'warning' : 'neutral',
        'meta' => (int)($summary['overdue_count'] ?? 0) . ' overdue · ' . money((float)($summary['overdue'] ?? 0))]) ?>
    <?= stat_card(['label' => 'Withdrawals waiting', 'value' => number_format((int)($summary['pending'] ?? 0)),
        'icon' => 'download', 'tone' => ($summary['pending'] ?? 0) > 0 ? 'warning' : 'neutral',
        'meta' => money((float)($summary['pending_amount'] ?? 0)) . ' requested']) ?>
</div>

<div class="tabs mb-3">
    <?php foreach (['overview' => 'Overview', 'my-money' => 'My money', 'withdrawals' => 'Withdrawals', 'invoices' => 'Invoices', 'wallets' => 'Provider wallets'] as $key => $label): ?>
        <a class="tab<?= $tab === $key ? ' is-active' : '' ?>" href="<?= e(url('admin/billing/index.php?tab=' . $key)) ?>"><?= e($label) ?></a>
    <?php endforeach; ?>
</div>

<?php if ($tab === 'overview'): ?>

    <?php if ($lockedProviders): ?>
        <!-- Providers the system has shut out over an unpaid fee. This is the
             sharpest thing WMS does to a customer of yours, so it is stated
             plainly and the way to undo it is right here: collect the money,
             mark it received, or give them more time. -->
        <section class="card mb-3">
            <div class="card__head">
                <div>
                    <h2 class="card__title"><?= icon('lock') ?> Locked over an unpaid fee</h2>
                    <p class="card__subtitle">They can sign in only to pay. A stopped network cannot sell at all.</p>
                </div>
            </div>
            <div class="table-wrap">
                <table class="table table--stack">
                    <thead>
                        <tr>
                            <th>Provider</th><th class="text-right">Owed</th><th>Was due</th>
                            <th>State</th><th class="text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($lockedProviders as $locked): ?>
                        <tr>
                            <td data-label="Provider">
                                <a href="<?= e(url('admin/providers/view.php?id=' . (int)$locked['id'])) ?>"><?= e($locked['business_name']) ?></a>
                                <div class="tiny faint"><?= e((string)$locked['provider_code']) ?></div>
                            </td>
                            <td data-label="Owed" class="text-right nowrap strong"><?= e(money((float)$locked['owed'])) ?></td>
                            <td data-label="Was due" class="nowrap">
                                <?= $locked['oldest_due'] ? e(format_date($locked['oldest_due'], 'd M Y')) : '—' ?>
                            </td>
                            <td data-label="State">
                                <?= $locked['service_suspended_at']
                                        ? badge('blocked', 'Service stopped')
                                        : badge('suspended', 'Account locked') ?>
                                <div class="tiny faint mt-1">since <?= e(time_ago($locked['billing_locked_at'])) ?></div>
                            </td>
                            <td data-label="Actions" class="text-right">
                                <div class="dropdown">
                                    <button type="button" class="btn btn--sm" data-dropdown="lock-<?= (int)$locked['id'] ?>">
                                        <?= icon('more', 'ico--sm') ?> Release
                                    </button>
                                    <div class="dropdown__menu" id="lock-<?= (int)$locked['id'] ?>">
                                        <form method="post">
                                            <?= CSRF::field() ?>
                                            <input type="hidden" name="action" value="grant_grace">
                                            <input type="hidden" name="provider_id" value="<?= (int)$locked['id'] ?>">
                                            <div class="dropdown__label">Give them more time</div>
                                            <div style="padding:.4rem .7rem">
                                                <input class="input" type="date" name="until"
                                                       min="<?= e(date('Y-m-d')) ?>"
                                                       value="<?= e(date('Y-m-d', strtotime('+7 days'))) ?>">
                                            </div>
                                            <button class="dropdown__item" type="submit">
                                                <?= icon('clock', 'ico--sm') ?> Unlock until that date
                                            </button>
                                        </form>
                                        <?php if ($locked['billing_grace_until']): ?>
                                            <div class="dropdown__divider"></div>
                                            <form method="post" data-confirm="End this extension now? The usual deadlines apply again.">
                                                <?= CSRF::field() ?>
                                                <input type="hidden" name="provider_id" value="<?= (int)$locked['id'] ?>">
                                                <button class="dropdown__item dropdown__item--danger" name="action" value="revoke_grace">
                                                    <?= icon('x', 'ico--sm') ?> End the extension
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                        <div class="dropdown__divider"></div>
                                        <a class="dropdown__item" href="<?= e(url('admin/billing/index.php?tab=invoices&provider_id=' . (int)$locked['id'])) ?>">
                                            <?= icon('report', 'ico--sm') ?> Their invoices
                                        </a>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="card__body">
                <p class="small faint mb-0">
                    Marking an invoice paid, collecting it from a wallet, or waiving it all release the provider
                    immediately — there is no separate unlock to remember.
                </p>
            </div>
        </section>
    <?php endif; ?>

    <div class="grid grid--2">
        <section class="card">
            <div class="card__head">
                <h2 class="card__title"><?= icon('download') ?> Waiting for your approval</h2>
                <a class="btn btn--sm" href="<?= e(url('admin/billing/index.php?tab=withdrawals')) ?>">All</a>
            </div>
            <div class="card__body--flush">
                <?php if (!$pendingPayouts): ?>
                    <?= empty_state(['icon' => 'check', 'title' => 'Nothing waiting', 'text' => 'Withdrawal requests from providers appear here for you to approve.']) ?>
                <?php else: ?>
                    <?php foreach ($pendingPayouts as $payout): ?>
                        <div class="alert-row alert-row--warning">
                            <div class="alert-row__body">
                                <div class="alert-row__title"><?= e($payout['provider_name']) ?> · <?= e(money((float)$payout['amount'])) ?></div>
                                <div class="alert-row__text"><?= e($payout['method']) ?> · <?= e($payout['account_name']) ?> · <?= e($payout['account_number']) ?></div>
                                <div class="tiny faint mt-1"><?= e($payout['reference']) ?> · <?= e(time_ago($payout['created_at'])) ?></div>
                            </div>
                            <div class="flex gap-1 items-center">
                                <form method="post" data-confirm="Send <?= e(money((float)$payout['amount'])) ?> to <?= e($payout['account_name']) ?> via <?= e($payout['method']) ?>? This moves real money." data-confirm-button="Send payout" data-confirm-tone="primary">
                                    <?= CSRF::field() ?>
                                    <input type="hidden" name="id" value="<?= (int)$payout['id'] ?>">
                                    <button class="btn btn--sm btn--primary" name="action" value="approve_withdrawal"><?= icon('check', 'ico--sm') ?> Approve</button>
                                </form>
                                <form method="post" data-confirm="Decline this withdrawal? The money returns to the provider's wallet.">
                                    <?= CSRF::field() ?>
                                    <input type="hidden" name="id" value="<?= (int)$payout['id'] ?>">
                                    <button class="btn btn--sm btn--danger" name="action" value="reject_withdrawal"><?= icon('x', 'ico--sm') ?></button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </section>

        <section class="card">
            <div class="card__head">
                <h2 class="card__title"><?= icon('building') ?> Fee status by provider</h2>
                <a class="btn btn--sm" href="<?= e(url('admin/billing/index.php?tab=invoices')) ?>">Invoices</a>
            </div>
            <div class="table-wrap">
                <?php
                $feeRows = $db->fetchAll(
                    "SELECT pr.id, pr.business_name, pr.platform_fee_amount, pr.platform_fee_cycle_months,
                            pr.billing_status, pr.billing_starts_on, pr.billing_next_due_on, pr.wallet_balance,
                            pr.mobile_money_enabled,
                            (SELECT COALESCE(SUM(i.amount),0) FROM platform_invoices i
                              WHERE i.provider_id = pr.id AND i.status = 'unpaid') AS owed
                       FROM providers pr ORDER BY pr.business_name"
                );
                ?>
                <?php if (!$feeRows): ?>
                    <?= empty_state(['icon' => 'building', 'title' => 'No providers yet', 'text' => 'Fee terms appear here once you register a provider.']) ?>
                <?php else: ?>
                    <table class="table table--stack table--compact">
                        <thead><tr><th>Provider</th><th class="text-right">Fee</th><th>Starts</th><th class="text-right">Wallet</th><th class="text-right">Owed</th><th>Status</th></tr></thead>
                        <tbody>
                        <?php foreach ($feeRows as $row): ?>
                            <tr>
                                <td data-label="Provider">
                                    <a href="<?= e(url('admin/providers/view.php?id=' . (int)$row['id'])) ?>"><?= e($row['business_name']) ?></a>
                                    <div class="tiny muted">
                                        <?= (int)$row['mobile_money_enabled'] === 1
                                            ? '<span class="badge badge--success badge--plain">Mobile money</span>'
                                            : '<span class="badge badge--neutral badge--plain">Vouchers only</span>' ?>
                                    </div>
                                </td>
                                <td data-label="Fee" class="text-right nowrap">
                                    <?= e(money((float)$row['platform_fee_amount'])) ?>
                                    <div class="tiny muted">/ <?= (int)$row['platform_fee_cycle_months'] === 1 ? 'month' : (int)$row['platform_fee_cycle_months'] . ' months' ?></div>
                                </td>
                                <td data-label="Starts" class="nowrap"><?= e(format_date($row['billing_starts_on'], 'd M Y')) ?></td>
                                <td data-label="Wallet" class="text-right nowrap"><?= e(money((float)$row['wallet_balance'])) ?></td>
                                <td data-label="Owed" class="text-right nowrap">
                                    <?= (float)$row['owed'] > 0 ? '<b style="color:var(--wms-warning)">' . e(money((float)$row['owed'])) . '</b>' : '—' ?>
                                </td>
                                <td data-label="Status"><?= badge($row['billing_status'] === 'current' ? 'active' : ($row['billing_status'] === 'overdue' ? 'expired' : $row['billing_status']), ucfirst($row['billing_status'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </section>
    </div>

<?php elseif ($tab === 'my-money'): ?>
    <?php
    /*
     * The platform owner's own money.
     *
     * The distinction this screen has to make honestly: the merchant
     * account holds two different kinds of money, and only one of them is
     * the owner's to take. Fees collected are theirs. Provider wallet
     * balances sit in the same account and are not.
     */
    $payoutPage = $myPayouts->search(['status' => query('status')], current_page(), 20);
    ?>

    <div class="stat-grid mb-3">
        <?= stat_card([
            'label' => 'Yours to withdraw',
            'value' => money($myBalance['available']),
            'icon'  => 'money',
            'tone'  => $myBalance['available'] > 0 ? 'success' : 'neutral',
            'meta'  => $myBalance['in_flight'] > 0
                ? money($myBalance['in_flight']) . ' already on its way out'
                : 'Fees collected, less what you have taken',
        ]) ?>
        <?= stat_card([
            'label' => 'Fees collected',
            'value' => money($myBalance['earned']),
            'icon'  => 'trend-up',
            'tone'  => 'primary',
            'meta'  => money($myBalance['collected_month']) . ' this month',
        ]) ?>
        <?= stat_card([
            'label' => 'Withdrawn so far',
            'value' => money($myBalance['paid_out']),
            'icon'  => 'download',
            'tone'  => 'neutral',
            'meta'  => $myBalance['payout_fees'] > 0
                ? money($myBalance['payout_fees']) . ' paid in payout charges'
                : 'No payout charges yet',
        ]) ?>
        <?= stat_card([
            'label' => 'Held for providers',
            'value' => money($myBalance['owed_to_providers']),
            'icon'  => 'card',
            'tone'  => 'warning',
            'meta'  => 'In your account, but not yours',
        ]) ?>
    </div>

    <?= alert_box('info',
        'Your merchant account holds ' . money($myBalance['in_merchant_account']) . ' in total. '
        . money($myBalance['available']) . ' of that is fee income you can take. The other '
        . money($myBalance['owed_to_providers']) . ' belongs to providers and is what you pay them with when '
        . 'they withdraw — WMS will not let you draw it down.',
        'What is actually yours') ?>

    <div class="grid grid--1-2">
        <!-- =============================================== take money out -->
        <section class="card">
            <div class="card__head">
                <div>
                    <h2 class="card__title"><?= icon('download') ?> Withdraw your fees</h2>
                    <p class="card__subtitle">Straight to your own bank or mobile money</p>
                </div>
            </div>
            <div class="card__body">
                <?php if ($myBalance['available'] < (float)setting('platform_withdrawal_minimum', 5000)): ?>
                    <?= empty_state([
                        'icon'  => 'money',
                        'title' => 'Nothing to withdraw yet',
                        'text'  => 'You have ' . money($myBalance['available']) . ' in collected fees. The smallest '
                                 . 'withdrawal is ' . money((float)setting('platform_withdrawal_minimum', 5000)) . '.',
                    ]) ?>
                <?php else: ?>
                    <form method="post" action=""
                          data-confirm="Send this money to your own account? This moves real money."
                          data-confirm-tone="primary" data-confirm-button="Send it">
                        <?= CSRF::field() ?>
                        <input type="hidden" name="action" value="platform_withdraw">

                        <?= field_input([
                            'name'     => 'amount',
                            'type'     => 'number',
                            'label'    => 'Amount',
                            'required' => true,
                            'value'    => (string)$myBalance['available'],
                            'hint'     => 'Up to ' . money($myBalance['available']) . '.',
                            'attrs'    => 'min="' . (float)setting('platform_withdrawal_minimum', 5000)
                                        . '" max="' . $myBalance['available'] . '" step="1"',
                        ]) ?>

                        <?= field_select([
                            'name'     => 'method',
                            'label'    => 'Send it to',
                            'required' => true,
                            'options'  => Withdrawal::METHODS,
                        ]) ?>

                        <?= field_input([
                            'name'        => 'account_number',
                            'label'       => 'Account or phone number',
                            'required'    => true,
                            'placeholder' => '0712 345 678',
                        ]) ?>

                        <?= field_input([
                            'name'        => 'account_name',
                            'label'       => 'Name on the account',
                            'required'    => true,
                            'placeholder' => 'As registered with the bank or wallet',
                        ]) ?>

                        <?= field_input([
                            'name'        => 'note',
                            'label'       => 'Note (optional)',
                            'placeholder' => 'What this withdrawal was for',
                        ]) ?>

                        <button class="btn btn--primary btn--block" type="submit">
                            <?= icon('download', 'ico--sm') ?> Withdraw
                        </button>
                    </form>
                <?php endif; ?>
            </div>
        </section>

        <!-- ================================================== the history -->
        <section class="card">
            <div class="card__head">
                <div>
                    <h2 class="card__title"><?= icon('history') ?> Your withdrawals</h2>
                    <p class="card__subtitle">Every payout you have taken from fee income</p>
                </div>
            </div>

            <div class="table-wrap">
                <?php if (!$payoutPage['rows']): ?>
                    <?= empty_state([
                        'icon'  => 'download',
                        'title' => 'You have not withdrawn anything yet',
                        'text'  => 'Fees you collect build up here until you take them out.',
                    ]) ?>
                <?php else: ?>
                    <table class="table table--stack">
                        <thead>
                            <tr>
                                <th>Reference</th><th class="text-right">Amount</th>
                                <th>Sent to</th><th>Status</th><th>When</th>
                                <th class="text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($payoutPage['rows'] as $payout): ?>
                            <tr>
                                <td data-label="Reference">
                                    <?= code_chip($payout['reference']) ?>
                                    <?php if (!empty($payout['note'])): ?>
                                        <div class="tiny faint"><?= e((string)$payout['note']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td data-label="Amount" class="text-right nowrap strong">
                                    <?= e(money((float)$payout['amount'])) ?>
                                    <?php if ((float)$payout['fee'] > 0): ?>
                                        <div class="tiny faint"><?= e(money((float)$payout['net_amount'])) ?> after charges</div>
                                    <?php endif; ?>
                                </td>
                                <td data-label="Sent to">
                                    <?= e((string)$payout['method']) ?>
                                    <div class="tiny faint"><?= e((string)$payout['account_number']) ?></div>
                                </td>
                                <td data-label="Status">
                                    <?= badge($payout['status'] === 'completed' ? 'successful' : $payout['status'],
                                              PlatformPayout::STATUSES[$payout['status']] ?? label($payout['status'])) ?>
                                    <?php if (!empty($payout['failure_reason'])): ?>
                                        <div class="tiny" style="color:var(--wms-danger)"><?= e((string)$payout['failure_reason']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td data-label="When" class="nowrap">
                                    <?= e(format_date($payout['created_at'], 'd M Y')) ?>
                                    <div class="tiny faint"><?= e(time_ago($payout['created_at'])) ?></div>
                                </td>
                                <td data-label="Actions" class="text-right">
                                    <?php if ($payout['status'] === 'processing'): ?>
                                        <!-- SonicPesa is polled by cron, but a payout you
                                             can see landed should not need to wait for it. -->
                                        <form method="post" style="display:inline" data-confirm="Mark this payout as completed?">
                                            <?= CSRF::field() ?><input type="hidden" name="id" value="<?= (int)$payout['id'] ?>">
                                            <button class="btn btn--sm" name="action" value="platform_payout_complete">Completed</button>
                                        </form>
                                        <form method="post" style="display:inline" data-confirm="Mark as failed? The amount becomes available to withdraw again.">
                                            <?= CSRF::field() ?><input type="hidden" name="id" value="<?= (int)$payout['id'] ?>">
                                            <button class="btn btn--sm btn--danger" name="action" value="platform_payout_failed"><?= icon('x', 'ico--sm') ?></button>
                                        </form>
                                    <?php else: ?>
                                        <span class="faint tiny">
                                            <?= $payout['completed_at'] ? e(format_date($payout['completed_at'], 'd M H:i')) : '—' ?>
                                        </span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
            <?= pagination($payoutPage, 'withdrawals') ?>
        </section>
    </div>

<?php elseif ($tab === 'withdrawals'): ?>
    <?php
    $filters = ['q' => query('q'), 'status' => query('status'), 'provider_id' => query('provider_id')];
    $page = $withdrawals->search($filters, current_page(), 25);
    ?>
    <section class="card">
        <form class="filter-bar" method="get" action="">
            <input type="hidden" name="tab" value="withdrawals">
            <?= search_field($filters['q'], 'Search reference or account…') ?>
            <?= filter_select('status', $filters['status'], Withdrawal::STATUSES, 'Any status') ?>
            <?= filter_select('provider_id', $filters['provider_id'], array_column($providerList, 'business_name', 'id'), 'Any provider') ?>
            <div class="filter-bar__actions"><button class="btn" type="submit"><?= icon('filter', 'ico--sm') ?> Filter</button></div>
        </form>
        <div class="table-wrap">
            <?php if (!$page['rows']): ?>
                <?= empty_state(['icon' => 'download', 'title' => 'No withdrawals', 'text' => 'When a provider asks for their money it appears here.']) ?>
            <?php else: ?>
                <table class="table table--stack">
                    <thead><tr><th>Reference</th><th>Provider</th><th class="text-right">Amount</th><th>Destination</th><th>Requested</th><th>Status</th><th class="table__actions">Actions</th></tr></thead>
                    <tbody>
                    <?php foreach ($page['rows'] as $payout): ?>
                        <tr>
                            <td data-label="Reference">
                                <?= code_chip($payout['reference']) ?>
                                <?php if ($payout['provider_ref']): ?><div class="tiny faint mono">SP #<?= e($payout['provider_ref']) ?></div><?php endif; ?>
                            </td>
                            <td data-label="Provider"><a href="<?= e(url('admin/providers/view.php?id=' . (int)$payout['provider_id'])) ?>"><?= e($payout['provider_name']) ?></a></td>
                            <td data-label="Amount" class="text-right nowrap strong">
                                <?= e(money((float)$payout['amount'])) ?>
                                <?php if ((float)$payout['fee'] > 0): ?>
                                    <div class="tiny muted">fee <?= e(money((float)$payout['fee'])) ?> · net <?= e(money((float)$payout['net_amount'])) ?></div>
                                <?php endif; ?>
                            </td>
                            <td data-label="Destination">
                                <?= e($payout['method']) ?>
                                <div class="tiny muted"><?= e($payout['account_name']) ?> · <?= e($payout['account_number']) ?></div>
                            </td>
                            <td data-label="Requested" class="nowrap">
                                <?= e(format_date($payout['created_at'], 'd M Y')) ?>
                                <div class="tiny muted"><?= e($payout['requested_by_name'] ?? '') ?></div>
                            </td>
                            <td data-label="Status">
                                <?= badge($payout['status'] === 'completed' ? 'successful' : ($payout['status'] === 'processing' ? 'pending' : $payout['status'])) ?>
                                <?php if ($payout['failure_reason']): ?><div class="tiny muted"><?= e(str_limit($payout['failure_reason'], 40)) ?></div><?php endif; ?>
                            </td>
                            <td class="table__actions" data-label="Actions">
                                <?php if ($payout['status'] === 'pending'): ?>
                                    <form method="post" style="display:inline" data-confirm="Send <?= e(money((float)$payout['amount'])) ?> to <?= e($payout['account_name']) ?>? This moves real money." data-confirm-button="Send" data-confirm-tone="primary">
                                        <?= CSRF::field() ?><input type="hidden" name="id" value="<?= (int)$payout['id'] ?>">
                                        <button class="btn btn--sm btn--primary" name="action" value="approve_withdrawal">Approve</button>
                                    </form>
                                    <form method="post" style="display:inline" data-confirm="Decline and return the money?">
                                        <?= CSRF::field() ?><input type="hidden" name="id" value="<?= (int)$payout['id'] ?>">
                                        <button class="btn btn--sm btn--danger" name="action" value="reject_withdrawal"><?= icon('x', 'ico--sm') ?></button>
                                    </form>
                                <?php elseif ($payout['status'] === 'processing'): ?>
                                    <form method="post" style="display:inline" data-confirm="Mark this payout as completed?">
                                        <?= CSRF::field() ?><input type="hidden" name="id" value="<?= (int)$payout['id'] ?>">
                                        <button class="btn btn--sm" name="action" value="complete_withdrawal">Completed</button>
                                    </form>
                                    <form method="post" style="display:inline" data-confirm="Mark as failed and return the money to the provider?">
                                        <?= CSRF::field() ?><input type="hidden" name="id" value="<?= (int)$payout['id'] ?>">
                                        <button class="btn btn--sm btn--danger" name="action" value="fail_withdrawal">Failed</button>
                                    </form>
                                <?php else: ?>
                                    <span class="faint tiny"><?= e(format_date($payout['completed_at'], 'd M H:i')) ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?= pagination($page, 'withdrawals') ?>
    </section>

<?php elseif ($tab === 'invoices'): ?>
    <?php
    $filters = ['q' => query('q'), 'status' => query('status'), 'provider_id' => query('provider_id'), 'overdue' => query('overdue')];
    $page = $invoices->search($filters, current_page(), 25);
    ?>
    <section class="card">
        <form class="filter-bar" method="get" action="">
            <input type="hidden" name="tab" value="invoices">
            <?= search_field($filters['q'], 'Search invoice number…') ?>
            <?= filter_select('status', $filters['status'], PlatformInvoice::STATUSES, 'Any status') ?>
            <?= filter_select('provider_id', $filters['provider_id'], array_column($providerList, 'business_name', 'id'), 'Any provider') ?>
            <?= filter_select('overdue', $filters['overdue'], ['1' => 'Overdue only'], 'All dates') ?>
            <div class="filter-bar__actions"><button class="btn" type="submit"><?= icon('filter', 'ico--sm') ?> Filter</button></div>
        </form>
        <div class="table-wrap">
            <?php if (!$page['rows']): ?>
                <?= empty_state([
                    'icon' => 'report', 'title' => 'No invoices yet',
                    'text' => 'Invoices are raised automatically when a provider\'s grace period ends. Use "Run billing now" to raise any that are due.',
                ]) ?>
            <?php else: ?>
                <table class="table table--stack">
                    <thead><tr><th>Invoice</th><th>Provider</th><th>Period</th><th class="text-right">Amount</th><th>Due</th><th class="text-right">Wallet</th><th>Status</th><th class="table__actions">Actions</th></tr></thead>
                    <tbody>
                    <?php foreach ($page['rows'] as $invoice): ?>
                        <?php $overdue = $invoice['status'] === 'unpaid' && $invoice['due_on'] < date('Y-m-d'); ?>
                        <tr>
                            <td data-label="Invoice"><?= code_chip($invoice['invoice_number']) ?></td>
                            <td data-label="Provider"><a href="<?= e(url('admin/providers/view.php?id=' . (int)$invoice['provider_id'])) ?>"><?= e($invoice['provider_name']) ?></a></td>
                            <td data-label="Period" class="nowrap"><?= e(format_date($invoice['period_start'], 'd M')) ?> – <?= e(format_date($invoice['period_end'], 'd M Y')) ?></td>
                            <td data-label="Amount" class="text-right nowrap strong"><?= e(money((float)$invoice['amount'])) ?></td>
                            <td data-label="Due" class="nowrap">
                                <?= e(format_date($invoice['due_on'], 'd M Y')) ?>
                                <?php if ($overdue): ?><div class="tiny" style="color:var(--wms-danger)">overdue</div><?php endif; ?>
                            </td>
                            <td data-label="Wallet" class="text-right nowrap"><?= e(money((float)$invoice['wallet_balance'])) ?></td>
                            <td data-label="Status"><?= badge($invoice['status'] === 'paid' ? 'successful' : ($overdue ? 'expired' : $invoice['status'])) ?></td>
                            <td class="table__actions" data-label="Actions">
                                <?php if ($invoice['status'] === 'unpaid'): ?>
                                    <?php if ((float)$invoice['wallet_balance'] >= (float)$invoice['amount']): ?>
                                        <form method="post" style="display:inline" data-confirm="Take <?= e(money((float)$invoice['amount'])) ?> from <?= e($invoice['provider_name']) ?>'s wallet?" data-confirm-tone="primary" data-confirm-button="Collect">
                                            <?= CSRF::field() ?><input type="hidden" name="id" value="<?= (int)$invoice['id'] ?>">
                                            <button class="btn btn--sm btn--primary" name="action" value="collect_invoice">Collect</button>
                                        </form>
                                    <?php endif; ?>
                                    <div class="dropdown" style="display:inline-block">
                                        <button type="button" class="btn btn--sm btn--icon" data-dropdown="inv-<?= (int)$invoice['id'] ?>"><?= icon('more', 'ico--sm') ?></button>
                                        <div class="dropdown__menu" id="inv-<?= (int)$invoice['id'] ?>">
                                            <form method="post" data-confirm="Mark this invoice as paid outside the platform?">
                                                <?= CSRF::field() ?><input type="hidden" name="id" value="<?= (int)$invoice['id'] ?>">
                                                <button class="dropdown__item" name="action" value="mark_paid"><?= icon('check', 'ico--sm') ?> Paid outside WMS</button>
                                            </form>
                                            <form method="post" data-confirm="Waive this invoice? The provider will not be charged for this period.">
                                                <?= CSRF::field() ?><input type="hidden" name="id" value="<?= (int)$invoice['id'] ?>">
                                                <button class="dropdown__item dropdown__item--danger" name="action" value="waive"><?= icon('x', 'ico--sm') ?> Waive</button>
                                            </form>
                                        </div>
                                    </div>
                                <?php else: ?>
                                    <span class="faint tiny"><?= e(label((string)$invoice['paid_from'])) ?> <?= e(format_date($invoice['paid_at'], 'd M')) ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?= pagination($page, 'invoices') ?>
    </section>

<?php else: ?>
    <?php
    $walletRows = $db->fetchAll(
        "SELECT pr.*, (SELECT COUNT(*) FROM wallet_transactions w WHERE w.provider_id = pr.id) AS entries
           FROM providers pr ORDER BY pr.wallet_balance DESC"
    );
    ?>
    <section class="card">
        <div class="card__head"><h2 class="card__title"><?= icon('card') ?> Provider wallets</h2></div>
        <div class="table-wrap">
            <?php if (!$walletRows): ?>
                <?= empty_state(['icon' => 'card', 'title' => 'No providers yet', 'text' => 'Wallets appear once you register a provider.']) ?>
            <?php else: ?>
                <table class="table table--stack">
                    <thead><tr><th>Provider</th><th>Selling</th><th class="text-right">Balance</th><th class="text-right">Held</th><th class="text-right">Earned</th><th class="text-right">Withdrawn</th><th class="text-right">Entries</th></tr></thead>
                    <tbody>
                    <?php foreach ($walletRows as $row): ?>
                        <tr>
                            <td data-label="Provider">
                                <?= cell_primary($row['business_name'], $row['provider_code'],
                                    '<span class="provider-logo">' . e(initials($row['business_name'])) . '</span>',
                                    url('admin/providers/view.php?id=' . (int)$row['id'])) ?>
                            </td>
                            <td data-label="Selling">
                                <?= (int)$row['mobile_money_enabled'] === 1
                                    ? '<span class="badge badge--success">Mobile money + vouchers</span>'
                                    : '<span class="badge badge--neutral">Vouchers only</span>' ?>
                            </td>
                            <td data-label="Balance" class="text-right nowrap strong"><?= e(money((float)$row['wallet_balance'])) ?></td>
                            <td data-label="Held" class="text-right nowrap"><?= (float)$row['wallet_held'] > 0 ? e(money((float)$row['wallet_held'])) : '—' ?></td>
                            <td data-label="Earned" class="text-right nowrap"><?= e(money((float)$row['lifetime_earned'])) ?></td>
                            <td data-label="Withdrawn" class="text-right nowrap"><?= e(money((float)$row['lifetime_withdrawn'])) ?></td>
                            <td data-label="Entries" class="text-right"><?= number_format((int)$row['entries']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </section>
<?php endif; ?>

<?php
ob_start();
echo CSRF::field();
echo '<input type="hidden" name="action" value="adjust">';
?>
<p class="small muted">Corrects a provider's balance by hand. Every adjustment is written to the wallet ledger and the audit log with your name on it.</p>
<?= field_select(['name' => 'provider_id', 'label' => 'Provider', 'required' => true,
    'placeholder' => 'Choose a provider', 'options' => array_column($providerList, 'business_name', 'id')]) ?>
<?= field_select(['name' => 'direction', 'label' => 'Direction', 'value' => 'credit',
    'options' => ['credit' => 'Credit — add money to their wallet', 'debit' => 'Debit — take money out']]) ?>
<?= field_input(['name' => 'amount', 'type' => 'number', 'label' => 'Amount', 'required' => true,
    'prefix' => setting('currency', 'TSh'), 'attrs' => 'min="1" step="any"']) ?>
<?= field_textarea(['name' => 'note', 'label' => 'Reason', 'rows' => 2,
    'placeholder' => 'Why this adjustment is being made — this is kept on the record.']) ?>
<?php
$body = ob_get_clean();
echo '<form method="post" action="">'
    . modal('adjust-form', 'Adjust a provider wallet', $body,
        '<button type="button" class="btn" data-modal-close>Cancel</button>'
        . '<button type="submit" class="btn btn--primary">Apply adjustment</button>')
    . '</form>';
?>

<?php require INCLUDES_PATH . '/footer.php'; ?>
