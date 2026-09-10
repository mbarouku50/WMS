<?php
/**
 * WMS - Purchase confirmation.
 *
 * Collects the paying phone number and starts the charge. The heavy lifting
 * is in PaymentService, which also issues the voucher once money arrives.
 */

require_once __DIR__ . '/../config/config.php';
require_once INCLUDES_PATH . '/components.php';

/* Establish the tenant before any query runs (see ProviderContext). */
ProviderContext::resolvePortalProvider();

$packages  = new Package();
$packageId = (int)(query('package') ?: post('package_id'));
$package   = $packageId ? $packages->find($packageId) : null;

if (!$package || $package['status'] !== 'active') {
    Session::flash('error', 'That package is not available. Please choose another one.');
    header('Location: ' . url('customer/packages.php'));
    exit;
}

$customer = Auth::customer();
$errors   = [];
$values   = [
    'phone' => $customer['phone'] ?? '',
    'name'  => $customer['full_name'] ?? '',
    'email' => $customer['email'] ?? '',
];

if (is_post()) {
    CSRF::verify();
    foreach ($values as $key => $default) {
        $values[$key] = post($key, (string)$default);
    }

    $errors = (new Validator($values))
        ->labels(['phone' => 'Mobile number', 'name' => 'Your name'])
        ->rules([
            'phone' => 'required|phone',
            'name'  => 'nullable|max:140',
            'email' => 'nullable|email|max:160',
        ])->errors();

    if (!$errors) {
        try {
            $result = (new PaymentService())->purchasePackage([
                'package_id'  => $packageId,
                'phone'       => $values['phone'],
                'name'        => $values['name'],
                'email'       => $values['email'],
                'customer_id' => Auth::customerId(),
            ]);
        } catch (Throwable $e) {
            Logger::error('Purchase failed: ' . $e->getMessage(), ['package_id' => $packageId]);
            $result = ['ok' => false, 'message' => 'We could not start that payment. Please try again in a moment.'];
        }

        if ($result['ok'] && !empty($result['payment'])) {
            header('Location: ' . url('customer/payment.php?id=' . (int)$result['payment']['id']));
            exit;
        }
        $errors['phone'] = $result['message'];
    }
}

$provider     = (new PaymentService())->provider();
$pageTitle    = 'Confirm purchase';
$activeTab    = 'buy';
$portalBack   = 'customer/packages.php';
$hasActionBar = true;
require __DIR__ . '/_header.php';

/* The gate again, at the last moment before money is asked for. */
if (!$portalCanBuy) {
    echo '<div class="portal-card">' . empty_state([
        'icon'  => 'ticket',
        'title' => 'This network sells vouchers',
        'text'  => 'Mobile money is not switched on for this Wi-Fi service. Buy a voucher from the operator instead.',
        'action' => '<a class="btn btn--primary" href="' . e(url('customer/index.php')) . '">Enter a voucher</a>',
    ]) . '</div>';
    require __DIR__ . '/_footer.php';
    return;
}
?>

<div class="portal-card">
    <h1 class="portal-card__title">Confirm your purchase</h1>
    <p class="portal-card__sub">Check the details, then approve the payment on your phone.</p>

    <div class="package-card is-selected mb-2" style="cursor:default">
        <div class="package-card__head">
            <span class="package-card__name"><?= e($package['name']) ?></span>
            <span class="package-card__price"><?= e(money((float)$package['price'])) ?></span>
        </div>
        <div class="package-card__specs">
            <span><?= icon('database', 'ico--sm') ?><?= e(format_mb($package['data_limit_mb'] === null ? null : (int)$package['data_limit_mb'])) ?></span>
            <span><?= icon('clock', 'ico--sm') ?><?= e(format_package_duration((int)$package['duration_value'], $package['duration_unit'])) ?></span>
            <span><?= icon('signal', 'ico--sm') ?><?= e(format_speed((int)$package['download_kbps'])) ?></span>
            <span><?= icon('device', 'ico--sm') ?><?= (int)$package['device_limit'] ?> device<?= (int)$package['device_limit'] === 1 ? '' : 's' ?></span>
        </div>
    </div>

    <?php if ($errors): ?>
        <?= alert_box('danger', $errors['phone'] ?? reset($errors), 'Please check this') ?>
    <?php endif; ?>

    <?php if ($provider->name() === 'demo'): ?>
        <?= alert_box('demo', 'Demo payments settle automatically after a few seconds. No money will leave your account.', 'Demo mode') ?>
    <?php endif; ?>

    <form method="post" action="" novalidate>
        <?= CSRF::field() ?>
        <input type="hidden" name="package_id" value="<?= (int)$package['id'] ?>">

        <?= field_input([
            'name' => 'phone', 'type' => 'tel', 'label' => 'Mobile money number', 'required' => true,
            'value' => $values['phone'], 'placeholder' => '0754 000 000',
            'error' => $errors['phone'] ?? '',
            'attrs' => 'inputmode="tel" autocomplete="tel"',
            'hint' => 'M-Pesa, Tigo Pesa, Airtel Money or Halopesa. You will get a prompt on this number.',
        ]) ?>

        <?= field_input(['name' => 'name', 'label' => 'Your name', 'value' => $values['name'], 'placeholder' => 'Optional', 'error' => $errors['name'] ?? '']) ?>

        <?= field_input(['name' => 'email', 'type' => 'email', 'label' => 'Email', 'value' => $values['email'],
            'placeholder' => 'Optional', 'error' => $errors['email'] ?? '',
            'attrs' => 'inputmode="email" autocomplete="email"',
            'hint' => 'Used only for your payment receipt.']) ?>

        <a class="btn btn--block mt-2" href="<?= e(url('customer/packages.php')) ?>">Choose a different package</a>

        <ol class="pay-steps">
            <li><span class="pay-steps__num">1</span><span>Press <b>Pay</b> and wait a few seconds.</span></li>
            <li><span class="pay-steps__num">2</span><span>A prompt appears on your phone - enter your <b>PIN</b>.</span></li>
            <li><span class="pay-steps__num">3</span><span>You are connected automatically, and your code is shown.</span></li>
        </ol>

        <div class="app-actionbar">
            <button type="submit" class="btn btn--primary btn--lg">
                <?= icon('card', 'ico--sm') ?> Pay <?= e(money((float)$package['price'])) ?>
            </button>
        </div>
    </form>

</div>

<?php require __DIR__ . '/_footer.php'; ?>
