<?php
/**
 * WMS - Platform fee: the pay screen.
 *
 * The one page a locked provider can open. It says what is owed, why the
 * account is closed, and takes the money two ways:
 *
 *      from the wallet   - instant, when customer payments have built up
 *      from a phone      - a USSD prompt, for a provider selling by voucher
 *                          whose wallet is always empty
 *
 * It is rendered as a standalone screen rather than inside the admin shell:
 * a sidebar full of links that all bounce straight back here would be a
 * cruel joke. When nothing is locked yet the same screen is a warning with
 * a way through, so the provider can pay early and carry on.
 */

$requiredPermission = 'view_wallet';
require_once __DIR__ . '/../../includes/auth-check.php';
require_once INCLUDES_PATH . '/components.php';

$providerId = ProviderContext::providerId();
if ($providerId === null) {
    // A platform administrator has no fee to pay; they collect them.
    Response::redirect('admin/billing/index.php', 'info', 'Platform fees are collected here.');
}

$billing = new BillingService();
$guard   = new BillingGuard();
$wallet  = new WalletService();

/* --------------------------------------------------------------- actions */

if (is_post()) {
    CSRF::verify();
    $action    = post('action');
    $invoiceId = (int)post('invoice_id');

    // Scoped by hand: this page takes an invoice id from a form, so it must
    // prove the invoice belongs to the provider making the request.
    $invoice = $invoiceId
        ? Database::getInstance()->fetchOne(
            'SELECT * FROM platform_invoices WHERE id = ? AND provider_id = ? LIMIT 1',
            [$invoiceId, $providerId])
        : null;

    if (!$invoice) {
        Response::back('error', 'That invoice could not be found on this account.');
    }

    if ($action === 'pay_wallet') {
        $result = $billing->payFromWallet($invoiceId);
        BillingGuard::forget($providerId);
        Response::back($result['ok'] ? 'success' : 'error', $result['message']);
    }

    if ($action === 'pay_mobile') {
        $result = $billing->payByMobile($invoiceId, post('phone'));
        if (!empty($result['pending'])) {
            // Remember which charge is in flight so the page can poll it.
            Session::set('wms_fee_pending', $invoiceId);
        }
        BillingGuard::forget($providerId);
        Response::back($result['ok'] ? 'info' : 'error', $result['message']);
    }

    Response::back('error', 'That action is not supported.');
}

/* ------------------------------------------- polling a charge in flight */

/*
 * The browser asks "has the money arrived?" every few seconds while a USSD
 * prompt is open. Answered as JSON so the page does not flicker, and only
 * for an invoice belonging to this provider.
 */
if (query('check') !== '') {
    $invoiceId = (int)query('check');
    $owns = Database::getInstance()->count(
        'SELECT COUNT(*) FROM platform_invoices WHERE id = ? AND provider_id = ?',
        [$invoiceId, $providerId]
    ) > 0;

    if (!$owns) {
        Response::json(['success' => false, 'message' => 'Unknown invoice.'], 404);
    }

    $result = $billing->refreshFeePayment($invoiceId);
    BillingGuard::forget($providerId);

    Response::json([
        'success' => $result['ok'],
        'status'  => $result['status'],
        'message' => $result['message'],
        'paid'    => $result['status'] === 'successful',
    ]);
}

/* ----------------------------------------------------------------- view */

$state    = $guard->state($providerId, false);
$balance  = $wallet->balance($providerId);
$terms    = $billing->terms($providerId);
$provider = ProviderContext::provider() ?? [];

// Nothing owed: this screen has no reason to exist. Send them home.
if (!$state['warn']) {
    Response::redirect('admin/index.php', 'success', 'Your platform fee is up to date. ' . $state['detail']);
}

$oldest      = $state['oldest'];
$oldestId    = (int)$oldest['id'];
$oldestDue   = (float)$oldest['amount'];
$canPayWallet = $balance['available'] >= $oldestDue;
$canPayMobile = (string)setting('platform_fee_pay_by_mobile', '1') === '1';

// A prompt already sent to a handset and still unanswered.
$pendingId = (int)Session::get('wms_fee_pending', 0);
$pending   = $pendingId === $oldestId && $oldest['pay_status'] === 'pending';

$payPhone = (string)($oldest['pay_phone'] ?? $provider['phone'] ?? '');

$pageTitle   = ($state['locked'] ? 'Account locked' : 'Platform fee due') . ' · ' . ($provider['business_name'] ?? 'WMS');
$daysLeft    = (int)$state['days_to_lock'];
$bodyClass   = 'wms-auth';
$netTone     = 'ink';
require INCLUDES_PATH . '/header.php';
?>
<main class="auth-main" style="max-width:none">
    <div class="auth-card" style="max-width:640px">

        <div class="auth-card__head">
            <span class="brand-mark brand-mark--lg" style="background:<?= $state['locked'] ? 'var(--wms-danger)' : 'var(--wms-warning)' ?>;color:#fff">
                <?= icon($state['locked'] ? 'lock' : 'money', 'ico--lg') ?>
            </span>
            <h1><?= e(match (true) {
                $state['locked']   => 'Your account is locked',
                $daysLeft <= 0     => 'Your platform fee is due today',
                $state['due_soon'] => 'Your platform fee is due in ' . $daysLeft . ' day' . ($daysLeft === 1 ? '' : 's'),
                default            => 'Your platform fee is due',
            }) ?></h1>
            <p><?= e($state['detail']) ?></p>
        </div>

        <?= flash_messages() ?>

        <!-- ============================================ what is owed -->
        <div class="card mb-3">
            <div class="card__body">
                <div class="flex items-center justify-between mb-2">
                    <span class="muted">Total outstanding</span>
                    <b style="font-size:1.6rem"><?= e(money($state['owed'])) ?></b>
                </div>

                <?= key_value([
                    'Paying now'    => $oldest['invoice_number'] . ' · ' . money($oldestDue),
                    'Period'        => format_date($oldest['period_start'], 'd M Y') . ' – ' . format_date($oldest['period_end'], 'd M Y'),
                    'Was due'       => format_date($oldest['due_on'], 'd M Y'),
                    'Your fee'      => money((float)($terms['fee'] ?? 0)) . ' every '
                                       . ((int)($terms['cycle_months'] ?? 1) === 1 ? 'month' : $terms['cycle_months'] . ' months'),
                ]) ?>

                <?php if (count($state['invoices']) > 1): ?>
                    <p class="small faint mt-2 mb-0">
                        <?= count($state['invoices']) ?> invoices are open. They are settled oldest first —
                        pay this one and the next appears here.
                    </p>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($state['stopped']): ?>
            <?= alert_box('danger',
                'Your customers cannot buy a package or activate a voucher on your network at the moment. '
                . 'Everything starts working again the moment this is paid — nothing has been deleted.',
                'Your service is stopped') ?>
        <?php endif; ?>

        <!-- =========================================== pay from wallet -->
        <section class="card mb-2">
            <div class="card__head">
                <div>
                    <h2 class="card__title"><?= icon('money') ?> Pay from your wallet</h2>
                    <p class="card__subtitle"><?= e(money($balance['available'])) ?> available</p>
                </div>
            </div>
            <div class="card__body">
                <?php if ($canPayWallet): ?>
                    <form method="post" action="">
                        <?= CSRF::field() ?>
                        <input type="hidden" name="action" value="pay_wallet">
                        <input type="hidden" name="invoice_id" value="<?= $oldestId ?>">
                        <button class="btn btn--primary btn--block" type="submit">
                            <?= icon('check', 'ico--sm') ?> Pay <?= e(money($oldestDue)) ?> from the wallet
                        </button>
                    </form>
                <?php else: ?>
                    <p class="muted mb-0">
                        Your wallet holds <?= e(money($balance['available'])) ?>, which does not cover
                        <?= e(money($oldestDue)) ?>. Pay from your phone below instead.
                    </p>
                <?php endif; ?>
            </div>
        </section>

        <!-- =========================================== pay from a phone -->
        <?php if ($canPayMobile): ?>
        <section class="card mb-3" id="mobile-pay" data-invoice="<?= $oldestId ?>" data-pending="<?= $pending ? '1' : '0' ?>">
            <div class="card__head">
                <div>
                    <h2 class="card__title"><?= icon('card') ?> Pay from your phone</h2>
                    <p class="card__subtitle">M-Pesa, Tigo Pesa, Airtel Money, Halopesa</p>
                </div>
            </div>
            <div class="card__body">
                <?php if ($pending): ?>
                    <div class="alert alert--info" id="fee-wait">
                        <?= icon('clock', 'alert__icon') ?>
                        <div class="alert__body">
                            <div class="alert__title">Waiting for your PIN</div>
                            A payment prompt was sent to <b><?= e($payPhone) ?></b>.
                            Approve it on that handset — this page updates itself the moment the money lands.
                        </div>
                    </div>
                <?php endif; ?>

                <form method="post" action="">
                    <?= CSRF::field() ?>
                    <input type="hidden" name="action" value="pay_mobile">
                    <input type="hidden" name="invoice_id" value="<?= $oldestId ?>">

                    <?= field_input([
                        'name'        => 'phone',
                        'label'       => 'Mobile number to charge',
                        'value'       => $payPhone,
                        'placeholder' => '0712 345 678',
                        'required'    => true,
                        'hint'        => 'You will get a prompt on this number for ' . money($oldestDue) . '.',
                    ]) ?>

                    <button class="btn btn--primary btn--block" type="submit">
                        <?= icon('card', 'ico--sm') ?> Send me the payment prompt
                    </button>
                </form>

                <?php if (!empty($oldest['pay_message']) && $oldest['pay_status'] === 'failed'): ?>
                    <p class="small mt-2 mb-0" style="color:var(--wms-danger)">
                        Last attempt: <?= e((string)$oldest['pay_message']) ?>
                    </p>
                <?php endif; ?>
            </div>
        </section>
        <?php endif; ?>

        <!-- ================================================= the way on -->
        <?php if (!$state['locked']): ?>
            <!-- Not locked yet, so there is a way past this screen. It is
                 stated as what it is - a postponement with a date on it -
                 rather than a dismissal that hides the deadline. -->
            <a class="btn btn--block" href="<?= e(url('admin/index.php')) ?>">
                Continue to my dashboard for now
            </a>
            <p class="small faint text-center mt-2">
                You can keep working until <?= e(format_date($state['lock_on'], 'd M Y')) ?>.
                After that this screen is the only one that opens.
            </p>
        <?php else: ?>
            <p class="small faint text-center mt-2 mb-2">
                Paid at the bank or in cash? Ask the platform administrator to mark it received —
                your account reopens immediately when they do.
            </p>
        <?php endif; ?>

        <div class="divider-label">Not you?</div>
        <a class="btn btn--block" href="<?= e(url('logout.php')) ?>">
            <?= icon('logout', 'ico--sm') ?> Sign out
        </a>
    </div>
</main>

<script src="<?= e(asset('js/app.js')) ?>"></script>
<script>
/*
 * While a USSD prompt is open on the provider's handset, ask the server
 * every few seconds whether the money has arrived. The gateway may also
 * confirm by webhook first - either way, the first answer of "paid" reloads
 * the page, which unlocks the account.
 */
(function () {
    var panel = document.getElementById('mobile-pay');
    if (!panel || panel.dataset.pending !== '1') { return; }

    var invoice = panel.dataset.invoice;
    var tries   = 0;

    var timer = setInterval(function () {
        if (++tries > 40) { clearInterval(timer); return; }   // ~3 minutes

        fetch('?check=' + encodeURIComponent(invoice), { headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data && data.paid) {
                    clearInterval(timer);
                    window.location.href = '<?= e(url('admin/index.php')) ?>';
                }
            })
            .catch(function () { /* a dropped poll is not an error worth showing */ });
    }, 5000);
})();
</script>
</body>
</html>
