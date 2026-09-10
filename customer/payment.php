<?php
/**
 * WMS - Waiting for payment.
 *
 * The page polls api/payments/status.php while the customer approves the
 * USSD prompt. The status the page shows always comes from the provider -
 * it is never assumed.
 *
 * It also gives the customer a way out. A phone that never showed the prompt,
 * a wrong number, second thoughts at the PIN screen - without a cancel button
 * the only exit was the browser's back arrow, which left the order pending
 * for the reconciler to clean up later.
 */

require_once __DIR__ . '/../config/config.php';
require_once INCLUDES_PATH . '/components.php';

/* Establish the tenant before any query runs (see ProviderContext). */
ProviderContext::resolvePortalProvider();

$payments  = new Payment();
$paymentId = (int)(query('id') ?: post('payment_id'));

/*
 * A visitor may only see an order they started in this browser session (or
 * one attached to the account they are signed in to). Anything else gets the
 * same answer as a payment that does not exist.
 */
$ownPayments   = array_map('intval', (array)Session::get('wms_own_payments', []));
$ownsThisOrder = in_array($paymentId, $ownPayments, true);

$payment = $paymentId ? $payments->withRelationsUnscoped($paymentId) : null;

if ($payment && !$ownsThisOrder) {
    $customerId = Auth::customerId();
    if ($customerId === null || (int)$payment['customer_id'] !== $customerId) {
        Logger::payment('Blocked a portal payment view', ['payment_id' => $paymentId, 'ip' => wms_client_ip()]);
        $payment = null;
    }
}

if (!$payment) {
    Session::flash('error', 'That payment could not be found.');
    header('Location: ' . url('customer/packages.php'));
    exit;
}

/* The order's own provider is the context for the rest of the page. */
if (!empty($payment['provider_id'])) {
    ProviderContext::establishPortal((int)$payment['provider_id']);
}

/*
 * The customer gave up on the prompt.
 *
 * Only a pending order can be abandoned - a settled one is not the
 * customer's to undo. Ownership was already proved above, so reaching this
 * point means this browser really did start this order.
 */
if (is_post() && post('action') === 'cancel') {
    CSRF::verify();

    if ($payment['status'] === 'pending') {
        (new PaymentService())->markFailed(
            $paymentId,
            'cancelled',
            'Cancelled by the customer on the portal before the prompt was approved.'
        );
        Session::flash('warning', 'Payment cancelled. Nothing was charged unless you had already entered your PIN.');
    } else {
        Session::flash('error', 'That payment can no longer be cancelled.');
    }

    header('Location: ' . url('customer/packages.php'));
    exit;
}

/* A payment already settled goes straight to the status page. */
if ($payment['status'] === 'successful') {
    header('Location: ' . url('customer/status.php?payment=' . $paymentId));
    exit;
}

$pageTitle  = 'Payment';
$activeTab  = 'buy';
$portalBack = 'customer/packages.php';
require __DIR__ . '/_header.php';
?>

<div class="portal-card">
    <?php if ($payment['status'] === 'pending'): ?>
        <div class="pay-wait" data-payment-poll="<?= (int)$payment['id'] ?>">
            <div class="pay-wait__ring"></div>
            <div class="pay-wait__title">Check your phone</div>
            <p class="pay-wait__text">
                We sent a payment request for <b><?= e(money((float)$payment['amount'])) ?></b>
                to <b><?= e($payment['payer_phone']) ?></b>. Enter your mobile money PIN to approve it.
            </p>

            <div data-payment-result></div>

            <ol class="pay-steps">
                <li><span class="pay-steps__num">1</span><span>Open the prompt on your phone.</span></li>
                <li><span class="pay-steps__num">2</span><span>Enter your <b>PIN</b> to confirm <?= e(money((float)$payment['amount'])) ?>.</span></li>
                <li><span class="pay-steps__num">3</span><span>Stay on this page - it updates by itself.</span></li>
            </ol>

            <!-- The way out of the wait, kept beside the wait itself: someone
                 staring at a prompt that never arrived should not have to
                 scroll past the receipt to find it. It is a real POST, not a
                 link, because it changes the order - a cancelled payment stops
                 being the reconciler's problem and the customer is free to
                 start again straight away. -->
            <form method="post" action="" class="pay-wait__out"
                  data-confirm="If the prompt is still on your phone, do not enter your PIN after cancelling."
                  data-confirm-title="Cancel this payment?"
                  data-confirm-button="Yes, cancel it">
                <?= CSRF::field() ?>
                <input type="hidden" name="action" value="cancel">
                <input type="hidden" name="payment_id" value="<?= (int)$payment['id'] ?>">

                <p class="tiny muted mb-1">
                    No prompt on your phone, or wrong number? If you have already entered your PIN,
                    let it finish - your code will appear here by itself.
                </p>
                <div class="flex gap-1">
                    <button type="submit" class="btn btn--danger flex-1">
                        <?= icon('x', 'ico--sm') ?> Cancel payment
                    </button>
                    <a class="btn flex-1" href="<?= e(url('customer/index.php')) ?>">
                        <?= icon('wifi', 'ico--sm') ?> Back to start
                    </a>
                </div>
            </form>
        </div>
    <?php else: ?>
        <?= alert_box(
            $payment['status'] === 'cancelled' ? 'warning' : 'danger',
            $payment['failure_reason'] ?: 'The payment was not completed.',
            $payment['status'] === 'cancelled' ? 'Payment cancelled' : 'Payment failed'
        ) ?>
        <a class="btn btn--primary btn--lg btn--block" href="<?= e(url('customer/packages.php')) ?>">Try again</a>
        <a class="btn btn--block mt-1" href="<?= e(url('customer/index.php')) ?>"><?= icon('wifi', 'ico--sm') ?> Back to the start</a>
    <?php endif; ?>

    <div class="divider-label">Reference</div>
    <?= key_value([
        'Package'   => e($payment['package_name'] ?? '—'),
        'Amount'    => '<b>' . e(money((float)$payment['amount'])) . '</b>',
        'Reference' => code_chip($payment['transaction_ref'], true),
        'Status'    => badge($payment['status']),
        'Started'   => e(format_date($payment['created_at'], 'd M Y H:i')),
    ]) ?>

    <p class="tiny muted mt-2 mb-0">
        Keep this reference. If the money left your account but you are not online,
        show it to our team and we will complete the order.
    </p>
</div>

<?php require __DIR__ . '/_footer.php'; ?>
