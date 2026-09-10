<?php
/**
 * WMS - Customer portal sign in.
 */

require_once __DIR__ . '/../config/config.php';
require_once INCLUDES_PATH . '/components.php';

/* Establish the tenant before any query runs (see ProviderContext). */
ProviderContext::resolvePortalProvider();

if (Auth::customer()) {
    header('Location: ' . url('customer/status.php'));
    exit;
}

$error      = '';
$identifier = '';

if (is_post()) {
    CSRF::verify();
    $identifier = post('identifier');
    $password   = $_POST['password'] ?? '';

    if ($identifier === '' || $password === '') {
        $error = 'Enter your phone number and password.';
    } else {
        try {
            $result = Auth::customerLogin($identifier, (string)$password);
        } catch (Throwable $e) {
            Logger::error('Customer login failed: ' . $e->getMessage());
            $result = ['ok' => false, 'message' => 'We could not sign you in right now. Please try again.'];
        }

        if ($result['ok']) {
            Session::flash('success', 'Welcome back.');
            header('Location: ' . url('customer/status.php'));
            exit;
        }
        $error = $result['message'];
    }
}

$pageTitle = 'Sign in';
$activeTab = 'account';
require __DIR__ . '/_header.php';
?>

<?= portal_tabs('account', $portalCanBuy) ?>

<div class="portal-card">
    <h1 class="portal-card__title">Sign in</h1>
    <p class="portal-card__sub">Use the phone number you registered with.</p>

    <?php if ($error !== ''): ?>
        <?= alert_box('danger', $error) ?>
    <?php endif; ?>

    <form method="post" action="" novalidate>
        <?= CSRF::field() ?>
        <?= field_input([
            'name' => 'identifier', 'label' => 'Phone number or username', 'required' => true,
            'value' => $identifier, 'placeholder' => '0754 000 000',
            'attrs' => 'inputmode="tel" autocomplete="username" autofocus',
        ]) ?>
        <?= field_input(['name' => 'password', 'type' => 'password', 'label' => 'Password', 'required' => true, 'attrs' => 'autocomplete="current-password"']) ?>

        <button type="submit" class="btn btn--primary btn--lg btn--block">
            <?= icon('user', 'ico--sm') ?> Sign in
        </button>
    </form>

    <div class="divider-label">No account?</div>
    <p class="small muted text-center">
        You do not need one to get online - a voucher or a package purchase is enough.
    </p>
    <a class="btn btn--block" href="<?= e(url('customer/index.php')) ?>"><?= icon('wifi', 'ico--sm') ?> Use a voucher</a>
</div>

<?php require __DIR__ . '/_footer.php'; ?>
