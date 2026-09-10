<?php
/**
 * WMS - Staff sign in.
 *
 * CSRF protected, rate limited, and quiet about which half of the
 * credentials was wrong.
 */

require_once __DIR__ . '/config/config.php';
require_once INCLUDES_PATH . '/components.php';

if (!WMS_INSTALLED) {
    header('Location: ' . url('install.php'));
    exit;
}

// Already signed in - go straight to the control centre.
if (Auth::check()) {
    header('Location: ' . url('admin/index.php'));
    exit;
}

$errors     = [];
$identifier = '';
$formError  = '';

if (is_post()) {
    CSRF::verify();

    $identifier = post('identifier');
    $password   = $_POST['password'] ?? '';
    $remember   = isset($_POST['remember']);

    $validator = (new Validator($_POST))
        ->labels(['identifier' => 'Username or email', 'password' => 'Password'])
        ->rules([
            'identifier' => 'required|max:160',
            'password'   => 'required|max:255',
        ]);

    if ($validator->fails()) {
        $errors = $validator->errors();
    } else {
        try {
            $result = Auth::login($identifier, (string)$password, $remember);
        } catch (Throwable $e) {
            Logger::error('Login failed with an exception: ' . $e->getMessage());
            $result = ['ok' => false, 'message' => 'We could not sign you in right now. Please try again shortly.'];
        }

        if ($result['ok']) {
            $redirect = Session::get('redirect_after_login');
            Session::forget('redirect_after_login');
            Session::flash('success', $result['message']);

            /*
             * The platform fee, if it is actually pressing.
             *
             * Only two things justify taking somebody to a payment screen
             * instead of their own dashboard: the deadline has passed, or it
             * is within the reminder window (three days by default). An
             * invoice raised three weeks early is mentioned on the dashboard
             * bar and nothing more - interrupting a working business over a
             * bill that is not yet due is how software gets resented.
             */
            if (ProviderContext::isProviderUser() && ProviderContext::providerId() !== null) {
                try {
                    $fee = (new BillingGuard())->state(ProviderContext::providerId(), false);
                    if ($fee['urgent']) {
                        Session::flash($fee['locked'] ? 'error' : 'warning',
                            $fee['headline'] . '. ' . $fee['detail']);
                        header('Location: ' . url(BillingGuard::PAY_PAGE));
                        exit;
                    }
                } catch (Throwable $e) {
                    Logger::error('Could not check the platform fee at sign in: ' . $e->getMessage());
                }
            }

            header('Location: ' . ($redirect && str_contains((string)$redirect, '/admin/') ? $redirect : url('admin/index.php')));
            exit;
        }

        $formError = $result['message'];
        $left = Auth::attemptsLeft($identifier);
        if ($left > 0 && $left <= 2) {
            $formError .= ' ' . $left . ' attempt' . ($left === 1 ? '' : 's') . ' left before this account is locked for ' . Auth::LOCKOUT_MINUTES . ' minutes.';
        }
    }
}

$pageTitle = 'Sign in · ' . platform_setting('app_name', 'WMS');
$bodyClass = 'wms-auth';
require INCLUDES_PATH . '/header.php';
?>
<aside class="auth-aside">
    <!-- The same network atmosphere as the public page and the portal, so the
         staff door does not feel like a different product. -->
    <div class="auth-aside__fx" aria-hidden="true">
        <div class="net-bg net-bg--ink net-bg--inline">
            <div class="net-bg__layer net-bg__bloom"></div>
            <div class="net-bg__layer net-bg__dots"></div>
            <div class="net-bg__scan"></div>
            <div class="net-bg__waves">
                <div class="net-bg__wave net-bg__wave--1"></div>
                <div class="net-bg__wave net-bg__wave--3"></div>
            </div>
        </div>
    </div>

    <a class="site-brand" href="<?= e(url('index.php')) ?>" style="color:#fff;position:relative">
        <span class="brand-mark brand-mark--lg"><?= icon('wifi', 'ico--lg') ?></span>
        <span class="brand-text">
            <b style="color:#fff"><?= e(platform_setting('app_name', 'WMS')) ?></b>
            <span>Control centre</span>
        </span>
    </a>

    <div class="auth-aside__content">
        <h2>Every session, voucher and shilling in one place.</h2>
        <p>
            Sign in to manage customers and packages, generate vouchers, follow payments,
            and watch the routers that carry your traffic.
        </p>
        <div class="hero__stats" style="border-top-color:var(--wms-ink-line)">
            <div class="hero__stat"><b><?= icon('shield', 'ico--lg') ?></b><span>Role based access</span></div>
            <div class="hero__stat"><b><?= icon('router', 'ico--lg') ?></b><span>MikroTik ready</span></div>
            <div class="hero__stat"><b><?= icon('history', 'ico--lg') ?></b><span>Full audit trail</span></div>
        </div>
    </div>
</aside>

<main class="auth-main">
    <div class="auth-card">
        <div class="auth-card__head">
            <h1>Sign in</h1>
            <p>Use the username or email address your administrator gave you.</p>
        </div>

        <?= flash_messages() ?>
        <?php if ($formError !== ''): ?>
            <?= alert_box('danger', $formError, 'Sign in failed') ?>
        <?php endif; ?>

        <form method="post" action="<?= e(url('login.php')) ?>" novalidate>
            <?= CSRF::field() ?>

            <?= field_input([
                'name'        => 'identifier',
                'label'       => 'Username or email',
                'value'       => $identifier,
                'placeholder' => 'admin',
                'required'    => true,
                'error'       => $errors['identifier'] ?? '',
                'attrs'       => 'autocomplete="username" autofocus',
            ]) ?>

            <?= field_input([
                'name'     => 'password',
                'type'     => 'password',
                'label'    => 'Password',
                'required' => true,
                'error'    => $errors['password'] ?? '',
                'attrs'    => 'autocomplete="current-password"',
            ]) ?>

            <div class="flex items-center justify-between mb-2">
                <label class="check">
                    <input type="checkbox" name="remember" value="1">
                    <span>Keep me signed in</span>
                </label>
                <span class="small faint">Sessions end after 1 hour idle</span>
            </div>

            <button type="submit" class="btn btn--primary btn--lg btn--block">
                <?= icon('lock', 'ico--sm') ?> Sign in
            </button>
        </form>

        <div class="divider-label">Customer?</div>
        <a class="btn btn--block" href="<?= e(url('customer/index.php')) ?>">
            <?= icon('wifi', 'ico--sm') ?> Go to the Wi-Fi portal
        </a>

        <p class="small faint text-center mt-3 mb-0">
            <a href="<?= e(url('index.php')) ?>">← Back to <?= e(platform_setting('company_name', 'the site')) ?></a>
        </p>
    </div>
</main>

<script src="<?= e(asset('js/app.js')) ?>"></script>
</body>
</html>
