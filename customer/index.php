<?php
/**
 * WMS - Captive portal home.
 *
 * The shortest possible path to being online: type the code, press connect.
 * Buying a package and signing in are one tap away.
 */

require_once __DIR__ . '/../config/config.php';
require_once INCLUDES_PATH . '/components.php';

/* The visitor picked their network from the chooser. */
if (is_post() && post('action') === 'select_provider') {
    CSRF::verify();
    if (ProviderContext::selectPortalProvider((int)post('provider_id'))) {
        header('Location: ' . url('customer/index.php'));
        exit;
    }
    Session::flash('error', 'That network is not available. Please choose another.');
}

/*
 * The visitor is on the wrong network, or picked the wrong one.
 *
 * Only an anonymous visitor may do this: a signed-in customer belongs to one
 * provider, and letting them hop tenants would put another operator's
 * packages in front of their account.
 */
if (is_post() && post('action') === 'change_network') {
    CSRF::verify();
    if (Auth::customer() === null) {
        ProviderContext::forgetPortalProvider();
    }
    header('Location: ' . url('customer/index.php'));
    exit;
}

$customer     = Auth::customer();
$activeAccess = null;

if ($customer) {
    try {
        $activeAccess = (new Customer())->currentSubscription((int)$customer['id'])
            ?? (new Customer())->currentVoucher((int)$customer['id']);
    } catch (Throwable $e) {
        Logger::warning('Portal could not read access state: ' . $e->getMessage());
    }
}

/* Is this device currently in an open session? */
$mac = SessionService::syntheticMac(wms_client_ip());
try {
    $openSession = Database::getInstance()->fetchOne(
        "SELECT s.*, v.code AS voucher_code, v.expires_at, p.name AS package_name
           FROM sessions s
           LEFT JOIN vouchers v ON v.id = s.voucher_id
           LEFT JOIN packages p ON p.id = v.package_id
          WHERE s.mac_address = ? AND s.status = 'active'
          ORDER BY s.started_at DESC LIMIT 1",
        [$mac]
    );
} catch (Throwable $e) {
    $openSession = null;
}

$portalStatus = $openSession
    ? ['tone' => 'online', 'icon' => 'wifi', 'title' => 'You are connected',
       'text' => ($openSession['package_name'] ?? 'Your package') . ' · ' . format_duration(seconds_until($openSession['expires_at'])) . ' remaining']
    : ['tone' => 'offline', 'icon' => 'wifi', 'title' => 'Not connected yet',
       'text' => 'Enter a voucher code, or buy a package to get online.'];

try {
    $packages = array_slice((new Package())->active(), 0, 3);
} catch (Throwable $e) {
    $packages = [];
}

$pageTitle    = 'Get online';
$activeTab    = 'home';
$hasActionBar = true;
require __DIR__ . '/_header.php';
?>

<?php if ($openSession): ?>
    <div class="portal-card">
        <h1 class="portal-card__title">You're online</h1>
        <p class="portal-card__sub">Enjoy your connection. You can check what is left at any time.</p>
        <?php if ($portalCanBuy): ?>
            <a class="btn btn--block" href="<?= e(url('customer/packages.php')) ?>">
                <?= icon('package', 'ico--sm') ?> Buy more time
            </a>
        <?php endif; ?>
    </div>

    <div class="app-actionbar">
        <a class="btn btn--primary btn--lg" href="<?= e(url('customer/status.php')) ?>">
            <?= icon('activity', 'ico--sm') ?> View my status
        </a>
    </div>
<?php else: ?>

    <?= portal_tabs('voucher', $portalCanBuy) ?>

    <form class="portal-card" method="post" action="<?= e(url('customer/voucher.php')) ?>" novalidate>
        <?= CSRF::field() ?>
        <h1 class="portal-card__title">Enter your voucher</h1>
        <p class="portal-card__sub">Type the code exactly as it appears on your card.</p>

        <input type="text" name="code" class="voucher-input" data-voucher-input
               placeholder="XXXX-XXXX" autocomplete="off" autocapitalize="characters"
               spellcheck="false" inputmode="text" maxlength="24" required aria-label="Voucher code">

        <p class="tiny muted text-center mt-2 mb-0">
            Codes are not case sensitive. If the code does not work, check for a
            <b>0</b> that should be an <b>O</b> - our codes never use either.
        </p>

        <!-- The one button that matters, held at the bottom of the screen
             where a thumb already is, instead of at the end of the scroll. -->
        <div class="app-actionbar">
            <button type="submit" class="btn btn--primary btn--lg">
                <?= icon('wifi', 'ico--sm') ?> Connect me
            </button>
        </div>
    </form>

    <?php if ($packages && $portalCanBuy): ?>
        <div class="divider-label">Or buy a package now</div>
        <div class="package-list">
            <?php foreach ($packages as $package): ?>
                <a class="package-card" href="<?= e(url('customer/purchase.php?package=' . (int)$package['id'])) ?>">
                    <?php if (!empty($package['is_featured'])): ?><span class="package-card__flag">Popular</span><?php endif; ?>
                    <div class="package-card__head">
                        <span class="package-card__name"><?= e($package['name']) ?></span>
                        <span class="package-card__price"><?= e(money((float)$package['price'])) ?></span>
                    </div>
                    <div class="package-card__specs">
                        <span><?= icon('database', 'ico--sm') ?><?= e(format_mb($package['data_limit_mb'] === null ? null : (int)$package['data_limit_mb'])) ?></span>
                        <span><?= icon('clock', 'ico--sm') ?><?= e(format_package_duration((int)$package['duration_value'], $package['duration_unit'])) ?></span>
                        <span><?= icon('signal', 'ico--sm') ?><?= e(format_speed((int)$package['download_kbps'])) ?></span>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
        <a class="btn btn--block mt-2" href="<?= e(url('customer/packages.php')) ?>">See all packages</a>
    <?php endif; ?>

<?php endif; ?>

<?php require __DIR__ . '/_footer.php'; ?>
