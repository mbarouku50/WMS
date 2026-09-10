<?php
/**
 * WMS - Voucher activation.
 *
 * Handles the code the customer typed on the portal. All the rules live in
 * VoucherService; this page only collects context and shows the outcome.
 */

require_once __DIR__ . '/../config/config.php';
require_once INCLUDES_PATH . '/components.php';

/* Establish the tenant before any query runs (see ProviderContext). */
ProviderContext::resolvePortalProvider();

$service = new VoucherService();
$result  = null;
$code    = '';

if (is_post()) {
    CSRF::verify();
    $code = strtoupper(post('code'));

    if ($code === '') {
        $result = ['ok' => false, 'message' => 'Enter the voucher code printed on your card.'];
    } else {
        try {
            $result = $service->activate($code, [
                'mac'         => post('mac') ?: ($_GET['mac'] ?? null),
                'ip'          => wms_client_ip(),
                'user_agent'  => $_SERVER['HTTP_USER_AGENT'] ?? null,
                'customer_id' => Auth::customerId(),
                'phone'       => post('phone'),
            ]);
        } catch (Throwable $e) {
            Logger::error('Voucher activation error: ' . $e->getMessage(), ['code' => $code]);
            $result = ['ok' => false, 'message' => 'Something went wrong while checking that code. Please try again.'];
        }
    }

    if ($result['ok']) {
        Session::flash('success', $result['message']);
        header('Location: ' . url('customer/status.php?voucher=' . urlencode($code)));
        exit;
    }
}

/*
 * The bottom tab bar links straight here, so this page is now a starting
 * point as well as a failure page. Only say a code was rejected when one
 * actually was.
 */
$portalStatus = ($result !== null && !$result['ok'])
    ? ['tone' => 'offline', 'icon' => 'alert', 'title' => 'Not connected',
       'text' => 'That code could not be used. Check it and try again.']
    : ['tone' => 'offline', 'icon' => 'wifi', 'title' => 'Not connected yet',
       'text' => 'Type the code from your card to get online.'];
$pageTitle    = 'Voucher';
$activeTab    = 'connect';
$hasActionBar = true;
require __DIR__ . '/_header.php';
?>

<div class="portal-card">
    <h1 class="portal-card__title">Enter your voucher</h1>
    <p class="portal-card__sub">Type the code exactly as it appears on your card.</p>

    <?php if ($result && !$result['ok']): ?>
        <?= alert_box('danger', $result['message'], 'That did not work') ?>
    <?php endif; ?>

    <form method="post" action="<?= e(url('customer/voucher.php')) ?>" novalidate>
        <?= CSRF::field() ?>
        <input type="text" name="code" class="voucher-input<?= $result && !$result['ok'] ? ' is-invalid' : '' ?>"
               data-voucher-input value="<?= e($code) ?>" placeholder="XXXX-XXXX"
               autocomplete="off" autocapitalize="characters" spellcheck="false"
               maxlength="24" required aria-label="Voucher code">

        <?php if ($result && !$result['ok'] && str_contains(strtolower($result['message']), 'device')): ?>
            <p class="small muted mt-2">
                This voucher is already being used by other devices. Disconnect one of them,
                or buy a package that allows more devices.
            </p>
        <?php endif; ?>

        <div class="app-actionbar">
            <button type="submit" class="btn btn--primary btn--lg">
                <?= icon('wifi', 'ico--sm') ?> Connect me
            </button>
        </div>
    </form>

    <div class="divider-label">Other options</div>
    <?php if ($portalCanBuy): ?>
        <a class="btn btn--block mb-1" href="<?= e(url('customer/packages.php')) ?>"><?= icon('package', 'ico--sm') ?> Buy a package</a>
    <?php endif; ?>
    <a class="btn btn--block" href="<?= e(url('customer/login.php')) ?>"><?= icon('user', 'ico--sm') ?> Sign in to my account</a>
</div>

<?php require __DIR__ . '/_footer.php'; ?>
